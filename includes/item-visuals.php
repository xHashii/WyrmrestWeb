<?php
/**
 * Item icons, model display IDs, and appearance -> item resolution from the
 * bundled 3.4.3 client exports.
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
 * share one look, so the cache's subclass + inventory type disambiguate, and
 * the default/lowest-ordered appearance wins when they still tie.
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
 * Compact, generated lookup tables. Rebuilt when any source CSV changes.
 *   items:     item ID => [display ID, icon FileDataID, inventory type, subclass]
 *   displays:  display ID => icon FileDataID
 *   resolve:   display ID => [[item ID, subclass, inventory type], ...]
 *              (best candidate first, so a saved appearance can name its item)
 * A read-only cache directory falls back to building in memory for this request.
 */
function itemVisualTables(array $config): array
{
    static $memo = [];
    $sources = [];
    foreach (['Item', 'ItemAppearance', 'ItemModifiedAppearance'] as $table) {
        $sources[$table] = itemVisualCsvPath($config, $table);
    }
    $stampParts = [];
    foreach ($sources as $path) {
        $stampParts[] = $path ? $path . ':' . @filesize($path) . ':' . @filemtime($path) : '-';
    }
    $stamp = substr(sha1(implode('|', $stampParts)), 0, 16);
    if (isset($memo[$stamp])) {
        return $memo[$stamp];
    }
    $cacheDir = itemCacheDir($config);
    $cache = $cacheDir . '/item-visuals-v2-' . $stamp . '.json';
    if (is_file($cache)) {
        $decoded = json_decode((string) @file_get_contents($cache), true);
        if (is_array($decoded) && is_array($decoded['items'] ?? null)
            && is_array($decoded['displays'] ?? null) && is_array($decoded['resolve'] ?? null)) {
            return $memo[$stamp] = $decoded;
        }
    }

    $data = ['items' => [], 'displays' => [], 'resolve' => []];
    // appearance ID => [display ID, icon FileDataID]
    $appearances = [];
    foreach (itemVisualCsvRows($sources['ItemAppearance'], ['ID', 'ItemDisplayInfoID', 'DefaultIconFileDataID']) as $row) {
        $appearances[$row['ID']] = [$row['ItemDisplayInfoID'], $row['DefaultIconFileDataID']];
        if ($row['ItemDisplayInfoID'] > 0 && empty($data['displays'][$row['ItemDisplayInfoID']])) {
            $data['displays'][$row['ItemDisplayInfoID']] = $row['DefaultIconFileDataID'];
        }
    }
    $needsAppearanceIcon = [];
    foreach (itemVisualCsvRows($sources['Item'], ['ID', 'IconFileDataID', 'InventoryType'], ['SubclassID']) as $row) {
        $data['items'][$row['ID']] = [0, $row['IconFileDataID'], $row['InventoryType'], $row['SubclassID']];
        if ($row['IconFileDataID'] <= 0) {
            $needsAppearanceIcon[$row['ID']] = true;
        }
    }
    $chosen = [];
    // display ID => [rank, item ID, subclass, inventory type][] awaiting sort/trim
    $candidates = [];
    foreach (itemVisualCsvRows($sources['ItemModifiedAppearance'], ['ItemID', 'ItemAppearanceModifierID', 'ItemAppearanceID', 'OrderIndex']) as $row) {
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
            $candidates[$display][] = [$rank[0], $rank[1], $id, $data['items'][$id][3], $data['items'][$id][2]];
        }
    }
    foreach ($candidates as $display => $rows) {
        usort($rows, static fn ($a, $b) => $a <=> $b);
        $picked = [];
        $seen = [];
        foreach ($rows as $row) {
            $id = $row[2];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $picked[] = [$id, $row[3], $row[4]]; // [item ID, subclass, inventory type]
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
 * Resolve a saved character appearance back to the item template that most
 * likely produced it.
 *
 * The equipmentCache gives us the item's DISPLAY id, plus the subclass and
 * inventory type of whatever was equipped. We walk the appearance graph
 * backwards to the item(s) that share that look and keep the one that also
 * matches the saved subclass + inventory type; the appearance graph is already
 * ordered so the default/canonical item comes first when several still tie.
 *
 * Returns the item template id (int), or 0 when no item shares that display.
 */
function resolveItemFromAppearance(array $config, int $displayId, int $subclass = -1, int $inventoryType = -1): int
{
    if ($displayId <= 0) {
        return 0;
    }
    $tables = itemVisualTables($config);
    $candidates = $tables['resolve'][$displayId] ?? null;
    if (!$candidates) {
        return 0;
    }
    // First choice: an exact subclass + inventory type match, best-ranked first.
    if ($subclass >= 0 && $inventoryType >= 0) {
        foreach ($candidates as [$id, $sc, $iv]) {
            if ($sc === $subclass && $iv === $inventoryType) {
                return $id;
            }
        }
    }
    // Next: same inventory type only (weapons/armour of the right kind).
    if ($inventoryType >= 0) {
        foreach ($candidates as [$id, $sc, $iv]) {
            if ($iv === $inventoryType) {
                return $id;
            }
        }
    }
    // Otherwise take the best-ranked item that carries this look at all.
    return $candidates[0][0];
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
