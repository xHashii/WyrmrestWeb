<?php
/**
 * Item icons and model display IDs from the bundled 3.4.3 client exports.
 * An item ID, an ItemAppearance ID, a display ID and an icon FileDataID are
 * different namespaces. Never use one as another, or reverse a shared display
 * ID into a guessed item. Cached character appearances can only reveal visuals.
 */
function itemVisualCsvPath(array $config, string $table): ?string
{
    $paths = glob(itemDb2Dir($config) . '/' . $table . '.*.csv') ?: [];
    natsort($paths);
    return $paths ? end($paths) : null;
}

/** Read only the named columns, without retaining whole CSV rows in memory. */
function itemVisualCsvRows(?string $path, array $columns): Generator
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
            yield $picked;
        }
    } finally {
        fclose($handle);
    }
}

/**
 * Compact, generated lookup tables. Rebuilt when any source CSV changes.
 * items: item ID => [display ID, icon FileDataID, inventory type]
 * displays: display ID => icon FileDataID (no inferred item identity).
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
    $cache = $cacheDir . '/item-visuals-v1-' . $stamp . '.json';
    if (is_file($cache)) {
        $decoded = json_decode((string) @file_get_contents($cache), true);
        if (is_array($decoded) && is_array($decoded['items'] ?? null) && is_array($decoded['displays'] ?? null)) {
            return $memo[$stamp] = $decoded;
        }
    }

    $data = ['items' => [], 'displays' => []];
    $appearances = [];
    foreach (itemVisualCsvRows($sources['ItemAppearance'], ['ID', 'ItemDisplayInfoID', 'DefaultIconFileDataID']) as $row) {
        $appearances[$row['ID']] = [$row['ItemDisplayInfoID'], $row['DefaultIconFileDataID']];
        if ($row['ItemDisplayInfoID'] > 0 && empty($data['displays'][$row['ItemDisplayInfoID']])) {
            $data['displays'][$row['ItemDisplayInfoID']] = $row['DefaultIconFileDataID'];
        }
    }
    $needsAppearanceIcon = [];
    foreach (itemVisualCsvRows($sources['Item'], ['ID', 'IconFileDataID', 'InventoryType']) as $row) {
        $data['items'][$row['ID']] = [0, $row['IconFileDataID'], $row['InventoryType']];
        if ($row['IconFileDataID'] <= 0) {
            $needsAppearanceIcon[$row['ID']] = true;
        }
    }
    $chosen = [];
    foreach (itemVisualCsvRows($sources['ItemModifiedAppearance'], ['ItemID', 'ItemAppearanceModifierID', 'ItemAppearanceID', 'OrderIndex']) as $row) {
        $id = $row['ItemID'];
        $appearance = $appearances[$row['ItemAppearanceID']] ?? null;
        if (!$appearance || !isset($data['items'][$id])) {
            continue;
        }
        // Prefer the default appearance, then the lowest order index.
        $rank = [$row['ItemAppearanceModifierID'] === 0 ? 0 : 1, $row['OrderIndex']];
        if (isset($chosen[$id]) && $chosen[$id] <= $rank) {
            continue;
        }
        $chosen[$id] = $rank;
        $data['items'][$id][0] = $appearance[0];
        if (isset($needsAppearanceIcon[$id])) {
            $data['items'][$id][1] = $appearance[1];
        }
    }

    if ($data['items'] || $data['displays']) {
        if (is_dir($cacheDir) || @mkdir($cacheDir, 0775, true)) {
            $tmp = is_writable($cacheDir) ? @tempnam($cacheDir, 'item-visuals-') : false;
            if ($tmp) {
                $json = json_encode($data);
                if ($json !== false && @file_put_contents($tmp, $json) !== false && @rename($tmp, $cache)) {
                    foreach (glob($cacheDir . '/item-visuals-v1-*.json') ?: [] as $old) {
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
