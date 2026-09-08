<?php
/**
 * Item icons, model display IDs, and appearance -> item resolution.
 *
 * Sources, strongest first, all merged into one lookup graph:
 *   1. data/item-overrides.json         curated realm corrections (explicit)
 *   2. `hotfixes` DB2 tables             the data this server actually ships
 *                                        to clients (custom/phase items)
 *   3. the bundled 3.4.3 client exports  the generic fallback
 * ...and, at resolution time, what can be seen equipped on the realm
 * (character_inventory + item_instance) is used as a preference signal.
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
 * share one look, so the resolver is deliberately conservative and layers the
 * signals it trusts:
 *
 *   - the cache's subclass + inventory type filter the candidates,
 *   - the character's class rejects items it cannot equip,
 *   - a saved secondary appearance (ItemModifiedAppearance id, used by the
 *     transmog system) resolves the item exactly,
 *   - curated overrides win, then items someone actually has equipped on the
 *     realm, then ordinary items; NPC-visual placeholders ("Monster - ...",
 *     "Test ...") are ranked last,
 *   - the default/lowest-ordered appearance only breaks remaining ties.
 *
 * Items the exports omit entirely are still recovered from the appearance
 * graph: their display/icon and any realm data apply, and they participate in
 * resolution as "untyped" candidates (used when no fully-known item matches
 * the slot), so a missing item is never invisible again.
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
 * $optional default to 0 (or '' when listed in $strings) when the export
 * doesn't carry them, so a slightly older DB2 export still works. Columns
 * listed in $strings are kept as text, everything else is cast to int.
 */
function itemVisualCsvRows(?string $path, array $columns, array $optional = [], array $strings = []): Generator
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
            foreach (array_merge($columns, $optional) as $column) {
                if (in_array($column, $strings, true)) {
                    $picked[$column] = isset($indexes[$column]) ? trim((string) ($row[$indexes[$column]] ?? '')) : '';
                } else {
                    $picked[$column] = isset($indexes[$column]) ? (int) ($row[$indexes[$column]] ?? 0) : 0;
                }
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
 * Is this a placeholder a player can never equip (NPC visuals, test items)?
 * These share looks with real gear and must rank below it.
 */
function itemAppearanceJunkName(string $name): bool
{
    return (bool) preg_match('/^(Monster|Creature|Test)\s*[-:]/i', trim($name));
}

/**
 * Compact, generated lookup tables from the bundled exports. Rebuilt when any
 * source CSV or the curated override file changes.
 *   items:     item ID => [display ID, icon FileDataID, inventory type, subclass]
 *              (0s where the export lacks the row; see 'untyped')
 *   displays:  display ID => icon FileDataID
 *   ima:       ItemModifiedAppearance ID => [item ID, subclass, inventory type]
 *              (exact link; used when a cache records a secondary appearance)
 *   resolve:   display ID => [item ID, subclass, inventory type, preferred,
 *                             rank, order, class mask, item level, junk], ...
 *              (overrides first, then non-placeholder, then rank/order)
 *   untyped:   item IDs referenced by the appearance graph but missing from
 *              the Item export (their subclass/inventory type is unknown)
 *   unnamed:   item IDs with no ItemSparse row (cannot be named locally)
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
    // ItemSparse supplies AllowableClass/ItemLevel/name/quality for ranking.
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
    $cache = $cacheDir . '/item-visuals-v4-' . $stamp . '.json';
    if (is_file($cache)) {
        $decoded = json_decode((string) @file_get_contents($cache), true);
        if (is_array($decoded) && is_array($decoded['items'] ?? null)
            && is_array($decoded['displays'] ?? null) && is_array($decoded['resolve'] ?? null)
            && is_array($decoded['ima'] ?? null)) {
            return $memo[$stamp] = $decoded;
        }
    }

    $data = ['items' => [], 'displays' => [], 'resolve' => [], 'ima' => [], 'untyped' => [], 'unnamed' => []];
    // appearance ID => [display ID, icon FileDataID]
    $appearances = [];
    foreach (itemVisualCsvRows($sources['ItemAppearance'] ?? null, ['ID', 'ItemDisplayInfoID', 'DefaultIconFileDataID']) as $row) {
        $appearances[$row['ID']] = [$row['ItemDisplayInfoID'], $row['DefaultIconFileDataID']];
        if ($row['ItemDisplayInfoID'] > 0 && empty($data['displays'][$row['ItemDisplayInfoID']])) {
            $data['displays'][$row['ItemDisplayInfoID']] = $row['DefaultIconFileDataID'];
        }
    }
    // ItemSparse: item ID => [AllowableClass mask, ItemLevel, name, quality]
    $sparseMeta = [];
    foreach (itemVisualCsvRows($sources['ItemSparse'] ?? null, ['ID', 'AllowableClass', 'ItemLevel'], ['Display_lang', 'OverallQualityID'], ['Display_lang']) as $row) {
        $allowable = $row['AllowableClass'];
        if ($allowable < 0 || $allowable >= 0x7F000000) {
            $allowable = 0; // -1 / 0xFFFFFFFF = usable by everyone
        }
        $sparseMeta[$row['ID']] = [$allowable, $row['ItemLevel'], (string) ($row['Display_lang'] ?? ''), $row['OverallQualityID']];
    }
    $needsAppearanceIcon = [];
    foreach (itemVisualCsvRows($sources['Item'] ?? null, ['ID', 'IconFileDataID', 'InventoryType'], ['SubclassID']) as $row) {
        $data['items'][$row['ID']] = [0, $row['IconFileDataID'], $row['InventoryType'], $row['SubclassID']];
        if ($row['IconFileDataID'] <= 0) {
            $needsAppearanceIcon[$row['ID']] = true;
        }
    }
    $chosen = [];
    $untyped = [];
    // display ID => [item ID, subclass, inventory type, preferred,
    //                appearance rank, order index, class mask, item level, junk]
    $candidates = [];
    foreach (itemVisualCsvRows($sources['ItemModifiedAppearance'] ?? null, ['ItemID', 'ItemAppearanceModifierID', 'ItemAppearanceID', 'OrderIndex'], ['ID']) as $row) {
        $imaId = $row['ID'];
        $id = $row['ItemID'];
        $appearance = $appearances[$row['ItemAppearanceID']] ?? null;
        if (!$appearance) {
            continue;
        }
        // Items the export omits still belong to the graph: keep them with
        // unknown subclass/inventory type so they can be recovered.
        if (!isset($data['items'][$id])) {
            $data['items'][$id] = [0, 0, 0, 0];
            $untyped[$id] = true;
        }
        // Prefer the default appearance, then the lowest order index.
        $rank = [$row['ItemAppearanceModifierID'] === 0 ? 0 : 1, $row['OrderIndex']];
        if (!isset($chosen[$id]) || $chosen[$id] > $rank) {
            $chosen[$id] = $rank;
            $data['items'][$id][0] = $appearance[0];
            if (isset($needsAppearanceIcon[$id]) || isset($untyped[$id])) {
                $data['items'][$id][1] = $appearance[1];
            }
        }
        $display = $appearance[0];
        if ($display > 0) {
            [$classMask, $itemLevel, $name] = $sparseMeta[$id] ?? [0, 0, ''];
            $junk = $name !== '' && itemAppearanceJunkName($name);
            $candidates[$display][] = [
                $id,
                isset($untyped[$id]) ? -1 : $data['items'][$id][3],
                isset($untyped[$id]) ? -1 : $data['items'][$id][2],
                0,          // not a curated override
                $rank[0],
                $rank[1],
                $classMask,
                $itemLevel,
                $junk,
            ];
        }
        // ItemModifiedAppearance ID -> item: the exact identity used by the
        // transmog/secondary-appearance system (cache field 5).
        if ($imaId > 0 && !isset($data['ima'][$imaId])) {
            $data['ima'][$imaId] = [
                $id,
                isset($untyped[$id]) ? -1 : $data['items'][$id][3],
                isset($untyped[$id]) ? -1 : $data['items'][$id][2],
            ];
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
        unset($untyped[$id]);
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
            0,          // not junk
        ];
    }
    foreach ($candidates as $display => $rows) {
        // Curated overrides first, then real items, then placeholders, then
        // default appearance and order index.
        usort($rows, static fn ($a, $b) => [$b[3], $a[8], $a[4], $a[5], $a[0]] <=> [$a[3], $b[8], $b[4], $b[5], $b[0]]);
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
    if ($untyped) {
        $data['untyped'] = array_map('strval', array_keys($untyped));
    }
    foreach ($data['items'] as $id => $row) {
        if (!isset($sparseMeta[$id])) {
            $data['unnamed'][] = (string) $id;
        }
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

/**
 * Merge the bundled-export candidates with the realm's own appearance rows.
 * Same entry: the realm row wins (it describes what this server ships).
 * Result is re-ranked and capped, so callers always see one canonical list.
 */
function itemAppearanceMergeCandidates(array $base, array $realm): array
{
    if (!$realm) {
        return $base;
    }
    $byEntry = [];
    foreach (array_merge($base, $realm) as $row) {
        $byEntry[(int) $row[0]] = $row;
    }
    $rows = array_values($byEntry);
    usort($rows, static fn ($a, $b) => [$b[3], $a[8], $a[4], $a[5], $a[0]] <=> [$a[3], $b[8], $b[4], $b[5], $b[0]]);
    return array_slice($rows, 0, ITEM_APPEARANCE_MAX_CANDIDATES);
}

/**
 * The `hotfixes` database rows this server actually sends to clients: its own
 * copy of the DB2 appearance graphs, plus values for items the bundled export
 * omits entirely (custom/phase items like the realm's 51625). Latest
 * VerifiedBuild per row wins. Returns [] when unconfigured/unreadable:
 *   items:       ID => [display, icon, inventory type, subclass]
 *   displays:    display => icon
 *   ima:         IMA ID => [item ID, subclass, inventory type]
 *   resolve:     display => candidate rows (same shape as itemVisualTables)
 *   sparse:      ID => [class mask, item level]
 *   names:       ID => name (from item_sparse)
 *   available:   whether the tables were readable at all
 * Disk-cached for a few minutes so the armory doesn't re-read thousands of
 * rows on every request.
 */
function realmAppearanceLayer(array $config): array
{
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }

    $empty = ['items' => [], 'displays' => [], 'ima' => [], 'resolve' => [], 'sparse' => [], 'names' => [], 'available' => false];
    $pdo = connectCharactersDb($config);
    if (!$pdo || empty($config['hotfixes_db_name'])) {
        return $memo = $empty;
    }
    $hotfixes = $config['hotfixes_db_name'];

    $dir = itemCacheDir($config);
    $cache = $dir . '/realm-appearance-v1.json';
    if (is_file($cache) && (@filemtime($cache) + 300) > time()) {
        $decoded = json_decode((string) @file_get_contents($cache), true);
        if (is_array($decoded) && isset($decoded['items'], $decoded['ima'], $decoded['resolve'], $decoded['sparse'], $decoded['names'], $decoded['available'])) {
            return $memo = $decoded;
        }
    }

    // Null means the table could not be read (missing table/GRANT/connection):
    // the whole layer is then unusable and the bundled export stays in charge.
    $read = static function (string $table, array $columns) use ($pdo, $hotfixes): ?array {
        try {
            $stmt = $pdo->query('SELECT ' . implode(',', $columns) . " FROM `{$hotfixes}`.`{$table}` ORDER BY VerifiedBuild ASC");
            return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (\Throwable $e) {
            dbNoteError("read `{$hotfixes}`.`{$table}`", $e);
            return null;
        }
    };

    // latest VerifiedBuild wins: rows arrive ascending, later rows overwrite
    $imaRows = $read('item_modified_appearance', ['ID', 'ItemID', 'ItemAppearanceModifierID', 'ItemAppearanceID', 'OrderIndex']);
    $appearanceRows = $read('item_appearance', ['ID', 'ItemDisplayInfoID', 'DefaultIconFileDataID']);
    if ($imaRows === null || $appearanceRows === null) {
        return $memo = $empty;
    }
    $ima = [];
    foreach ($imaRows as $row) {
        $ima[(int) $row['ID']] = [
            (int) $row['ItemID'], (int) $row['ItemAppearanceModifierID'],
            (int) $row['ItemAppearanceID'], (int) $row['OrderIndex'],
        ];
    }
    $appearances = [];
    foreach ($appearanceRows as $row) {
        $appearances[(int) $row['ID']] = [(int) $row['ItemDisplayInfoID'], (int) $row['DefaultIconFileDataID']];
    }
    // Optional companion tables: extra types missing items, item gives the
    // subclass/inventory/icon, item_sparse gives name/class/level.
    $extra = [];
    foreach (($read('item_modified_appearance_extra', ['ID', 'DisplayWeaponSubclassID', 'DisplayInventoryType']) ?? []) as $row) {
        $extra[(int) $row['ID']] = [(int) $row['DisplayWeaponSubclassID'], (int) $row['DisplayInventoryType']];
    }
    $realmItems = [];
    foreach (($read('item', ['ID', 'SubclassID', 'InventoryType', 'IconFileDataID']) ?? []) as $row) {
        $realmItems[(int) $row['ID']] = [(int) $row['IconFileDataID'], (int) $row['InventoryType'], (int) $row['SubclassID']];
    }
    $sparse = [];
    foreach (($read('item_sparse', ['ID', 'AllowableClass', 'ItemLevel', 'Display', 'OverallQualityID']) ?? []) as $row) {
        $allowable = (int) $row['AllowableClass'];
        if ($allowable < 0 || $allowable >= 0x7F000000) {
            $allowable = 0;
        }
        $sparse[(int) $row['ID']] = [$allowable, (int) $row['ItemLevel'], trim((string) ($row['Display'] ?? '')), (int) $row['OverallQualityID']];
    }

    $layer = ['items' => [], 'displays' => [], 'ima' => [], 'resolve' => [], 'sparse' => [], 'names' => [], 'available' => true];
    foreach ($appearances as $appearanceId => $appearance) {
        if ($appearance[0] > 0 && !isset($layer['displays'][$appearance[0]])) {
            $layer['displays'][$appearance[0]] = $appearance[1];
        }
    }
    $chosen = [];
    foreach ($ima as $imaId => $row) {
        [$id, $mod, $appearanceId, $order] = $row;
        $appearance = $appearances[$appearanceId] ?? null;
        if (!$appearance) {
            continue;
        }
        [$display, $icon] = $appearance;
        // Subclass/inventory type: the item row wins, extra fills the gap.
        $item = $realmItems[$id] ?? null;
        $sub = $item !== null ? $item[2] : ($extra[$imaId][0] ?? -1);
        $inv = $item !== null ? $item[1] : ($extra[$imaId][1] ?? -1);
        $rank = [$mod === 0 ? 0 : 1, $order];
        if ($display > 0 && (!isset($chosen[$id]) || $chosen[$id] > $rank)) {
            $chosen[$id] = $rank;
            $layer['items'][$id] = [$display, $item !== null ? $item[0] : $icon, $inv >= 0 ? $inv : 0, $sub >= 0 ? $sub : 0];
        }
        if ($display > 0) {
            [$classMask, $itemLevel, $name] = $sparse[$id] ?? [0, 0, ''];
            $layer['resolve'][$display][] = [
                $id, $sub, $inv,
                0, $rank[0], $rank[1],
                $classMask, $itemLevel,
                $name !== '' && itemAppearanceJunkName($name),
            ];
        }
        if ($imaId > 0) {
            $layer['ima'][$imaId] = [$id, $sub, $inv];
        }
    }
    foreach ($sparse as $id => $row) {
        $layer['sparse'][$id] = [$row[0], $row[1]];
        $layer['names'][$id] = $row[2];
    }

    if ((is_dir($dir) || @mkdir($dir, 0775, true)) && is_writable($dir)) {
        $tmp = @tempnam($dir, 'realm-appearance-');
        if ($tmp) {
            $json = json_encode($layer);
            if ($json !== false && @file_put_contents($tmp, $json) !== false && @rename($tmp, $cache)) {
                @chmod($cache, 0664);
            } else {
                @unlink($tmp);
            }
        }
    }
    return $memo = $layer;
}

/**
 * Which item entries are actually equipped by someone on this realm
 * (entry => number of instances), learned from readable inventory records.
 * This is the strongest general signal for shared looks: an item this realm
 * really wears beats generic export candidates no one equips (NPC visuals,
 * unused/renumbered items). Disk-cached for a few minutes; [] when the DB is
 * unreadable (resolution then falls back to the static data only).
 */
function realmEquippedItems(array $config): array
{
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }

    $pdo = connectCharactersDb($config);
    if (!$pdo) {
        return $memo = [];
    }
    $dir = itemCacheDir($config);
    $cache = $dir . '/realm-equipped-v1.json';
    if (is_file($cache) && (@filemtime($cache) + 300) > time()) {
        $decoded = json_decode((string) @file_get_contents($cache), true);
        if (is_array($decoded) && is_array($decoded['entries'] ?? null)) {
            $memo = [];
            foreach ($decoded['entries'] as $entry => $count) {
                $memo[(int) $entry] = (int) $count;
            }
            return $memo;
        }
    }

    try {
        $stmt = $pdo->query('
            SELECT ii.itemEntry AS entry, COUNT(*) AS n
            FROM item_instance ii
            JOIN character_inventory ci ON ci.item = ii.guid AND ci.guid = ii.owner_guid
            WHERE ci.bag = 0 AND ci.slot BETWEEN 0 AND 18 AND ii.itemEntry > 0
            GROUP BY ii.itemEntry
            ORDER BY n DESC
            LIMIT 5000
        ');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (\Throwable $e) {
        dbNoteError('read realm-equipped item observations', $e);
        return $memo = [];
    }

    $found = [];
    foreach ($rows as $row) {
        $found[(int) $row['entry']] = (int) $row['n'];
    }
    if ((is_dir($dir) || @mkdir($dir, 0775, true)) && is_writable($dir)) {
        $tmp = @tempnam($dir, 'realm-equipped-');
        if ($tmp) {
            $json = json_encode(['built_at' => time(), 'entries' => $found]);
            if ($json !== false && @file_put_contents($tmp, $json) !== false && @rename($tmp, $cache)) {
                @chmod($cache, 0664);
            } else {
                @unlink($tmp);
            }
        }
    }
    return $memo = $found;
}

function itemVisuals(array $config, array $entries): array
{
    if (!$entries) {
        return [];
    }
    $tables = itemVisualTables($config);
    $realm = realmAppearanceLayer($config);
    $found = [];
    foreach ($entries as $entry) {
        $entry = (int) $entry;
        // The realm's own DB2 rows beat the generic export for visible data.
        if (isset($realm['items'][$entry])) {
            [$display, $icon, $type] = $realm['items'][$entry];
            $found[$entry] = ['display_id' => $display, 'icon_file_data_id' => $icon, 'inventory_type' => $type];
            continue;
        }
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
 * backwards to the item(s) that share that look, combined with the realm's
 * own hotfixes and overrides, and keep the one that matches the saved
 * subclass + inventory type and the character's class; among the survivors,
 * curated overrides win, then items someone actually wears on this realm,
 * then ordinary items, and only then the default/canonical appearance.
 *
 * Returns the item template id (int), or 0 when no item shares that display.
 */
function resolveItemFromAppearance(array $config, int $displayId, int $subclass = -1, int $inventoryType = -1, ?int $characterClass = null, int $secondaryAppearanceId = 0): int
{
    if ($displayId <= 0) {
        return 0;
    }
    $tables = itemVisualTables($config);
    $realm = realmAppearanceLayer($config);
    $observed = realmEquippedItems($config);

    // Exact identity: a recorded ItemModifiedAppearance id maps straight to
    // the item that produced the visible look (transmogged/custom items).
    if ($secondaryAppearanceId > 0) {
        if (isset($realm['ima'][$secondaryAppearanceId])) {
            return (int) $realm['ima'][$secondaryAppearanceId][0];
        }
        if (isset($tables['ima'][$secondaryAppearanceId])) {
            return (int) $tables['ima'][$secondaryAppearanceId][0];
        }
    }

    // The realm's own graph describes those entries: drop their generic
    // export rows everywhere (they may link the entry to a different look).
    $realmEntries = $realm['items'] ?? [];
    $baseRows = array_values(array_filter(
        $tables['resolve'][$displayId] ?? [],
        static fn (array $candidate): bool => !isset($realmEntries[(int) $candidate[0]])
    ));
    $candidates = itemAppearanceMergeCandidates($baseRows, $realm['resolve'][$displayId] ?? []);
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

    $pick = static function (array $set) use ($observed): int {
        // 1. curated realm overrides (newest id first — latest version wins).
        $preferred = array_values(array_filter($set, static fn (array $c): bool => !empty($c[3])));
        if ($preferred) {
            usort($preferred, static fn ($a, $b) => $b[0] <=> $a[0]);
            return (int) $preferred[0][0];
        }
        // 2. items this realm actually has equipped somewhere (most worn first).
        $seen = array_values(array_filter($set, static fn (array $c): bool => isset($observed[(int) $c[0]])));
        if ($seen) {
            usort($seen, static fn ($a, $b) => [$observed[(int) $b[0]], $a[4], $a[5], $a[0]] <=> [$observed[(int) $a[0]], $b[4], $b[5], $b[0]]);
            return (int) $seen[0][0];
        }
        // 3. real items; 4. NPC-visual placeholders.  Rows are pre-ranked.
        $clean = array_values(array_filter($set, static fn (array $c): bool => empty($c[8])));
        return (int) ($clean ? $clean[0][0] : $set[0][0]);
    };

    // Items the exports can't type (missing rows) are still part of the graph.
    $known = array_values(array_filter($eligible, static fn (array $c): bool => $c[1] >= 0 && $c[2] >= 0));
    $unknown = array_values(array_filter($eligible, static fn (array $c): bool => $c[1] < 0 || $c[2] < 0));

    // First choice: an exact subclass + inventory type match, best-ranked first.
    if ($subclass >= 0 && $inventoryType >= 0) {
        $exact = array_values(array_filter(
            $known,
            static fn (array $candidate): bool => (int) $candidate[1] === $subclass && (int) $candidate[2] === $inventoryType
        ));
        if ($exact) {
            return $pick($exact);
        }
    }
    // Next: same inventory type only (weapons/armour of the right kind).
    if ($inventoryType >= 0) {
        $sameType = array_values(array_filter(
            $known,
            static fn (array $candidate): bool => (int) $candidate[2] === $inventoryType
        ));
        if ($sameType) {
            return $pick($sameType);
        }
    }
    // An untyped candidate (e.g. an item absent from the export with no realm
    // data) is a better guess than a known item of the wrong kind.
    if ($unknown) {
        return $pick($unknown);
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
    $realm = realmAppearanceLayer($config);
    return count(itemAppearanceMergeCandidates($tables['resolve'][$displayId] ?? [], $realm['resolve'][$displayId] ?? []));
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
