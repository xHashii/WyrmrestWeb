<?php
/**
 * Item icons, model display IDs, and appearance -> item resolution from the
 * bundled 3.4.3 client exports, plus the curated realm overrides.
 *
 * An item ID, an ItemAppearance ID, a display ID and an icon FileDataID are
 * different namespaces — never silently treat one as another. But the client
 * DB2 files DO let us walk the appearance graph backwards:
 *
 *     display ID  --ItemAppearance-->  appearance ID
 *     appearance ID  --ItemModifiedAppearance-->  item ID(s)
 *
 * That is exactly what Wowhead / the WotLK item database do to turn a saved
 * character appearance (characters.equipmentCache stores a DISPLAY id, plus
 * the item's subclass and inventory type) into a real item. Several items can
 * share one look, so:
 *   - the cache's subclass + inventory type disambiguate,
 *   - the character's class is used to reject items it cannot equip,
 *   - a saved secondary appearance (ItemModifiedAppearance id, used by the
 *     transmog system) resolves the item exactly,
 *   - items declared in data/item-overrides.json win over generic CSV rows,
 *     because this realm's custom/phase items are the ones actually worn,
 *   - the default/lowest-ordered appearance wins only when still tied.
 */
function itemVisualCsvPath(array $config, string $table): ?string
{
    $paths = glob(itemDb2Dir($config) . '/' . $table . '.*.csv') ?: [];
    natsort($paths);
    return $paths ? end($paths) : null;
}

/**
 * Read only the named columns, without retaining whole CSV rows in memory.
 * Every column in $columns must be present or nothing is yielded; columns in
 * $optional default to 0 when the export doesn't carry them, so a slightly
 * older DB2 export still works.
 */
function itemVisualCsvRows(?string $path, array $columns, array $optional = []): Generator
{
    $handle = $path ? @fopen($path, 'r') : false;
    if (!$handle) {
        return;
    }
    try {
        $header = fgetcsv($handle, 0, ',', '"', '');
        $indexes = is_array($header) ? array_flip($header) : [];
        if (array_diff($columns, array_keys($indexes))) {
            return;
        }
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $picked = [];
            foreach ($columns as $column) {
                $picked[$column] = (int) ($row[$indexes[$column]] ?? 0);
            }
            foreach ($optional as $column) {
                $picked[$column] = isset($indexes[$column]) ? (int) ($row[$indexes[$column]] ?? 0) : 0;
            }
            yield $picked;
        }
    } finally {
        fclose($handle);
    }
}

/** At most this many candidate items are kept per shared display ID. */
const ITEM_APPEARANCE_MAX_CANDIDATES = 12;

/**
 * Compact, generated lookup tables. Rebuilt when any source CSV or the
 * curated override file changes.
 *   items:     item ID => [display ID, icon FileDataID, inventory type, subclass]
 *   displays:  display ID => icon FileDataID
 *   ima:       ItemModifiedAppearance ID => [item ID, subclass, inventory type]
 *              (exact link; used when a cache records a secondary appearance)
 *   resolve:   display ID => [item ID, subclass, inventory type, preferred,
 *                             rank, order, allowable class, item level], ...
 *              (best candidate first: curated overrides first, then the
 *               default/lowest-ordered appearance; saved looks use this)
 * A read-only cache directory falls back to building in memory for this request.
 */
function itemVisualTables(array $config): array
{
    static $memo = [];
    $sources = [];
    foreach (['Item', 'ItemAppearance', 'ItemModifiedAppearance'] as $table) {
        $path = itemVisualCsvPath($config, $table);
        if ($path !== null) {
            $sources[$table] = $path;
        }
    }
    // ItemSparse supplies AllowableClass/ItemLevel for disambiguation; the
    // overrides file pinpoints realm-specific custom items.
    $sparse = itemSparseCsvPath($config);
    if ($sparse !== null) {
        $sources['ItemSparse'] = $sparse;
    }
    $overridesPath = itemOverridesPath($config);

    $stampParts = [];
    foreach ($sources as $path) {
        $stampParts[] = $path . ':' . @filesize($path) . ':' . @filemtime($path);
    }
    $stampParts[] = $overridesPath . ':' . @filesize($overridesPath) . ':' . @filemtime($overridesPath);
    $stamp = substr(sha1(implode('|', $stampParts)), 0, 16);
    if (isset($memo[$stamp])) {
        return $memo[$stamp];
    }
    $cacheDir = itemCacheDir($config);
    $cache = $cacheDir . '/item-visuals-v3-' . $stamp . '.json';
    if (is_file($cache)) {
        $decoded = json_decode((string) @file_get_contents($cache), true);
        if (is_array($decoded) && is_array($decoded['items'] ?? null)
            && is_array($decoded['displays'] ?? null) && is_array($decoded['resolve'] ?? null)
            && is_array($decoded['ima'] ?? null)) {
            return $memo[$stamp] = $decoded;
        }
    }

    $data = ['items' => [], 'displays' => [], 'resolve' => [], 'ima' => []];
    // appearance ID => [display ID, icon FileDataID]
    $appearances = [];
    foreach (itemVisualCsvRows($sources['ItemAppearance'] ?? null, ['ID', 'ItemDisplayInfoID', 'DefaultIconFileDataID']) as $row) {
        $appearances[$row['ID']] = [$row['ItemDisplayInfoID'], $row['DefaultIconFileDataID']];
        if ($row['ItemDisplayInfoID'] > 0 && empty($data['displays'][$row['ItemDisplayInfoID']])) {
            $data['displays'][$row['ItemDisplayInfoID']] = $row['DefaultIconFileDataID'];
        }
    }
    // ItemSparse: item ID => [AllowableClass mask, ItemLevel]
    $sparseMeta = [];
    foreach (itemVisualCsvRows($sources['ItemSparse'] ?? null, ['ID', 'AllowableClass', 'ItemLevel']) as $row) {
        $allowable = $row['AllowableClass'];
        if ($allowable < 0 || $allowable >= 0x7F000000) {
            $allowable = 0; // -1 / 0xFFFFFFFF = usable by everyone
        }
        $sparseMeta[$row['ID']] = [$allowable, $row['ItemLevel']];
    }
    $needsAppearanceIcon = [];
    foreach (itemVisualCsvRows($sources['Item'] ?? null, ['ID', 'IconFileDataID', 'InventoryType'], ['SubclassID']) as $row) {
        $data['items'][$row['ID']] = [0, $row['IconFileDataID'], $row['InventoryType'], $row['SubclassID']];
        if ($row['IconFileDataID'] <= 0) {
            $needsAppearanceIcon[$row['ID']] = true;
        }
    }
    $chosen = [];
    // display ID => [item ID, subclass, inventory type, preferred,
    //                appearance rank, order index, allowable class, item level]
    $candidates = [];
    foreach (itemVisualCsvRows($sources['ItemModifiedAppearance'] ?? null, ['ItemID', 'ItemAppearanceModifierID', 'ItemAppearanceID', 'OrderIndex'], ['ID']) as $row) {
        $imaId = $row['ID'];
        $id = $row['ItemID'];
        $appearance = $appearances[$row['ItemAppearanceID']] ?? null;
        if (!$appearance || !isset($data['items'][$id])) {
            continue;
        }
        // Prefer the default appearance, then the lowest order index.
        $rank = [$row['ItemAppearanceModifierID'] === 0 ? 0 : 1, $row['OrderIndex']];
        if (!isset($chosen[$id]) || $chosen[$id] > $rank) {
            $chosen[$id] = $rank;
            $data['items'][$id][0] = $appearance[0];
            if (isset($needsAppearanceIcon[$id])) {
                $data['items'][$id][1] = $appearance[1];
            }
        }
        $display = $appearance[0];
        if ($display > 0) {
            // Same ranking so the item a display most naturally belongs to wins.
            [$classMask, $itemLevel] = $sparseMeta[$id] ?? [0, 0];
            $candidates[$display][] = [
                $id,
                $data['items'][$id][3],
                $data['items'][$id][2],
                0,          // not a curated override
                $rank[0],
                $rank[1],
                $classMask,
                $itemLevel,
            ];
        }
        // ItemModifiedAppearance ID -> item: the exact identity used by the
        // transmog/secondary-appearance system (cache field 5).
        if ($imaId > 0 && !isset($data['ima'][$imaId])) {
            $data['ima'][$imaId] = [$id, $data['items'][$id][3], $data['items'][$id][2]];
        }
    }
    // Curated overrides: realm-specific items placed into the same lookup
    // tables, explicitly preferred over generic rows that share their look.
    foreach (itemOverrides($config) as $id => $override) {
        $display = (int) ($override['display_id'] ?? 0);
        if ($display <= 0) {
            continue;
        }
        $subclass = (int) ($override['subclass'] ?? 0);
        $inventoryType = (int) ($override['inventory_type'] ?? 0);
        $icon = (int) ($override['icon_file_data_id'] ?? 0);
        $data['items'][$id] = [$display, $icon, $inventoryType, $subclass];
        if (!empty($data['displays'][$display])) {
            // Keep the icon already known for the shared look if we have one.
            $icon = $data['displays'][$display];
        } else {
            $data['displays'][$display] = $icon;
        }
        $classMask = (int) ($override['allowable_class'] ?? 0);
        if ($classMask < 0 || $classMask >= 0x7F000000) {
            $classMask = 0;
        }
        $candidates[$display][] = [
            $id,
            $subclass,
            $inventoryType,
            1,          // preferred: this realm's item for this look
            0,
            0,
            $classMask,
            (int) ($override['item_level'] ?? 0),
        ];
    }
    foreach ($candidates as $display => $rows) {
        // Curated overrides first, then default appearance and order index.
        usort($rows, static fn ($a, $b) => [$b[3], $a[4], $a[5]] <=> [$a[3], $b[4], $b[5]]);
        $picked = [];
        $seen = [];
        foreach ($rows as $row) {
            $id = $row[0];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $picked[] = $row;
            if (count($picked) >= ITEM_APPEARANCE_MAX_CANDIDATES) {
                break;
            }
        }
        $data['resolve'][$display] = $picked;
    }

    if ($data['items'] || $data['displays']) {
        if (is_dir($cacheDir) || @mkdir($cacheDir, 0775, true)) {
            $tmp = is_writable($cacheDir) ? @tempnam($cacheDir, 'item-visuals-') : false;
            if ($tmp) {
                $json = json_encode($data);
                if ($json !== false && @file_put_contents($tmp, $json) !== false && @rename($tmp, $cache)) {
                    foreach (glob($cacheDir . '/item-visuals-v*.json') ?: [] as $old) {
                        if ($old !== $cache) {
                            @unlink($old);
                        }
                    }
                } else {
                    @unlink($tmp);
                }
            }
        }
    }
    return $memo[$stamp] = $data;
}

function itemVisuals(array $config, array $entries): array
{
    if (!$entries) {
        return [];
    }
    $tables = itemVisualTables($config);
    $found = [];
    foreach ($entries as $entry) {
        $entry = (int) $entry;
        if (isset($tables['items'][$entry])) {
            [$display, $icon, $type] = $tables['items'][$entry];
            $found[$entry] = ['display_id' => $display, 'icon_file_data_id' => $icon, 'inventory_type' => $type];
        }
    }
    return $found;
}

/**
 * Can a character of $class equip an item with this AllowableClass mask?
 * Masks 0/-1/0xFFFFFFFF mean "any class"; otherwise bit (class - 1) must be
 * set. A null class means we know nothing and nothing is rejected.
 */
function itemClassAllows(int $classMask, ?int $class): bool
{
    if ($class === null || $class <= 0) {
        return true;
    }
    if ($classMask === 0) {
        return true;
    }
    return ($classMask & (1 << ($class - 1))) !== 0;
}

/**
 * Resolve a saved character appearance back to the item template that most
 * likely produced it.
 *
 * The equipmentCache gives us the item's DISPLAY id, plus the subclass and
 * inventory type of whatever was equipped. When it also records a secondary
 * appearance (an ItemModifiedAppearance id — the transmog system's exact
 * identity), that is used directly. Otherwise we walk the appearance graph
 * backwards to the item(s) that share that look and keep the one that also
 * matches the saved subclass + inventory type; curated realm overrides go
 * first, then the default/canonical item. The character's class is used to
 * reject items it could never have worn.
 *
 * Returns the item template id (int), or 0 when no item shares that display.
 */
function resolveItemFromAppearance(array $config, int $displayId, int $subclass = -1, int $inventoryType = -1, ?int $characterClass = null, int $secondaryAppearanceId = 0): int
{
    if ($displayId <= 0) {
        return 0;
    }
    $tables = itemVisualTables($config);

    // Exact identity: a recorded ItemModifiedAppearance id maps straight to
    // the item that produced the visible look (transmogged/custom items).
    if ($secondaryAppearanceId > 0 && isset($tables['ima'][$secondaryAppearanceId])) {
        return (int) $tables['ima'][$secondaryAppearanceId][0];
    }

    $candidates = $tables['resolve'][$displayId] ?? null;
    if (!$candidates) {
        return 0;
    }

    // The character could never have worn an item their class can't use.
    $eligible = array_values(array_filter(
        $candidates,
        static fn (array $candidate): bool => itemClassAllows((int) $candidate[6], $characterClass)
    ));
    if (!$eligible) {
        $eligible = $candidates; // unusual data; keep a visible guess over nothing
    }

    // Among the winning set: curated overrides first (newest id first — the
    // realm's latest version of a look), otherwise the already-ranked order.
    $pick = static function (array $set): int {
        $preferred = array_values(array_filter($set, static fn (array $c): bool => !empty($c[3])));
        if ($preferred) {
            usort($preferred, static fn ($a, $b) => $b[0] <=> $a[0]);
            return (int) $preferred[0][0];
        }
        return (int) $set[0][0];
    };

    // First choice: an exact subclass + inventory type match, best-ranked first.
    if ($subclass >= 0 && $inventoryType >= 0) {
        $exact = array_values(array_filter(
            $eligible,
            static fn (array $candidate): bool => (int) $candidate[1] === $subclass && (int) $candidate[2] === $inventoryType
        ));
        if ($exact) {
            return $pick($exact);
        }
    }
    // Next: same inventory type only (weapons/armour of the right kind).
    if ($inventoryType >= 0) {
        $sameType = array_values(array_filter(
            $eligible,
            static fn (array $candidate): bool => (int) $candidate[2] === $inventoryType
        ));
        if ($sameType) {
            return $pick($sameType);
        }
    }
    // Otherwise take the best-ranked item that carries this look at all.
    return $pick($eligible);
}

/**
 * How many distinct items share a display id (used to flag lookalike slots).
 */
function itemAppearanceCandidateCount(array $config, int $displayId): int
{
    if ($displayId <= 0) {
        return 0;
    }
    $tables = itemVisualTables($config);
    return count($tables['resolve'][$displayId] ?? []);
}

/** Prefer locally extracted icons; remote requests can be disabled entirely. */
function itemIconUrl(array $config, int $fileDataId): ?string
{
    if ($fileDataId <= 0) {
        return null;
    }
    foreach (['png', 'jpg', 'webp'] as $extension) {
        $relative = 'images/items/' . $fileDataId . '.' . $extension;
        if (is_file(__DIR__ . '/../' . $relative)) {
            return $relative;
        }
    }
    if (isset($config['remote_item_icons']) && !$config['remote_item_icons']) {
        return null;
    }
    static $names = null;
    if ($names === null) {
        $names = json_decode((string) @file_get_contents(__DIR__ . '/../data/item-icon-names.json'), true) ?: [];
    }
    $name = $names[$fileDataId] ?? '';
    if (!is_string($name) || !preg_match('/\A[a-z0-9_&. -]+\z/', $name)) {
        return null;
    }
    return 'https://wow.zamimg.com/images/wow/icons/large/' . rawurlencode($name) . '.jpg';
}
