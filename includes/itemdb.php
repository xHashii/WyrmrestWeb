<?php
/**
 * Item data lookup for the Armory.
 *
 * Where item data lives on a TrinityCore 3.4.3 server
 * ---------------------------------------------------
 * A character's *items* live in the `characters` database:
 *
 *   character_inventory (guid, bag, slot, item)   -- what is in which slot
 *   item_instance       (guid, itemEntry, ...)    -- the actual item object
 *
 * `character_inventory.item` is an item *instance* guid, and
 * `item_instance.itemEntry` is the item *template* id (e.g. 39723).
 *
 * What that entry means — name, quality, item level — is NOT in the world
 * database on 3.4.3/master: TrinityCore dropped `world.item_template` years
 * ago and reads item templates straight out of the client's DB2 files
 * (ItemSparse.db2, Item.db2). The `hotfixes` database only mirrors DB2 rows
 * that the server has to hotfix down to the client, so on a stock server
 * `hotfixes.item_sparse` is usually EMPTY — which is why joining against it
 * silently produced "No equipped items found".
 *
 * So item names are resolved in this order:
 *   1. hotfixes.item_sparse   (custom/hotfixed items, highest VerifiedBuild wins)
 *   2. world.item_template    (only exists on 3.3.5-era cores; skipped if absent)
 *   3. db2/ItemSparse.*.csv   (bundled client data — the reliable fallback)
 *
 * Step 3 is handled here. The CSV is ~14 MB / 45k rows, so on first use it is
 * boiled down to a compact sorted binary index (16 bytes per item + a names
 * blob) in the cache directory; lookups are then a binary search with a
 * couple of fseek()s. If the cache directory isn't writable we fall back to a
 * single streaming pass over the CSV, which is slower but always works.
 */

const ITEMDB_RECORD_SIZE = 16;

/**
 * Directory holding the exported DB2 CSVs (db2/ by default).
 */
function itemDb2Dir(array $config): string
{
    $dir = $config['db2_dir'] ?? (__DIR__ . '/../db2');
    return rtrim($dir, '/\\');
}

/**
 * Directory used for the generated item index (cache/ by default).
 */
function itemCacheDir(array $config): string
{
    $dir = $config['cache_dir'] ?? (__DIR__ . '/../cache');
    return rtrim($dir, '/\\');
}

/**
 * Newest ItemSparse CSV in the db2 directory, or null if none is present.
 */
function itemSparseCsvPath(array $config): ?string
{
    $matches = glob(itemDb2Dir($config) . '/ItemSparse*.csv') ?: [];
    if (!$matches) {
        return null;
    }
    sort($matches);
    return end($matches);
}

/**
 * Column indexes we need out of the ItemSparse CSV header, or null if the
 * header doesn't look like an ItemSparse export.
 */
function itemSparseColumns(array $header): ?array
{
    $index = array_flip($header);

    $pick = static function (array $candidates) use ($index) {
        foreach ($candidates as $name) {
            if (isset($index[$name])) {
                return $index[$name];
            }
        }
        return null;
    };

    $cols = [
        'id'       => $pick(['ID', 'Id', 'id']),
        'name'     => $pick(['Display_lang', 'Display', 'Name_lang', 'Name']),
        'quality'  => $pick(['OverallQualityID', 'OverallQuality', 'Quality']),
        'ilvl'     => $pick(['ItemLevel']),
        'invtype'  => $pick(['InventoryType']),
        'reqlevel' => $pick(['RequiredLevel']),
    ];

    if ($cols['id'] === null || $cols['name'] === null) {
        return null;
    }
    return $cols;
}

/**
 * Cache file paths (index + names blob) for the current CSV. The file name
 * embeds the CSV's size/mtime so a swapped-in newer export rebuilds itself.
 */
function itemCachePaths(array $config): ?array
{
    $csv = itemSparseCsvPath($config);
    if ($csv === null) {
        return null;
    }

    $stamp = substr(sha1($csv . '|' . (string) @filesize($csv) . '|' . (string) @filemtime($csv)), 0, 12);
    $dir = itemCacheDir($config);

    return [
        'csv' => $csv,
        'idx' => "{$dir}/items-{$stamp}.idx",
        'dat' => "{$dir}/items-{$stamp}.dat",
    ];
}

/**
 * Build (or rebuild) the binary item index from the CSV.
 * Returns ['ok' => bool, 'count' => int, 'error' => ?string].
 */
function itemDb2BuildIndex(array $config, bool $force = false): array
{
    $paths = itemCachePaths($config);
    if ($paths === null) {
        return ['ok' => false, 'count' => 0, 'error' => 'No ItemSparse*.csv found in ' . itemDb2Dir($config)];
    }

    if (!$force && is_file($paths['idx']) && is_file($paths['dat'])) {
        return ['ok' => true, 'count' => (int) (filesize($paths['idx']) / ITEMDB_RECORD_SIZE), 'error' => null];
    }

    $dir = itemCacheDir($config);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return ['ok' => false, 'count' => 0, 'error' => "Cache directory {$dir} does not exist and could not be created"];
    }
    if (!is_writable($dir)) {
        return ['ok' => false, 'count' => 0, 'error' => "Cache directory {$dir} is not writable by the web server"];
    }

    $handle = @fopen($paths['csv'], 'r');
    if (!$handle) {
        return ['ok' => false, 'count' => 0, 'error' => "Could not read {$paths['csv']}"];
    }

    $header = fgetcsv($handle);
    $cols = is_array($header) ? itemSparseColumns($header) : null;
    if ($cols === null) {
        fclose($handle);
        return ['ok' => false, 'count' => 0, 'error' => "Unexpected CSV header in {$paths['csv']}"];
    }

    // Write to temp files first so a half-built cache is never visible.
    $tmpIdx = $paths['idx'] . '.tmp' . getmypid();
    $tmpDat = $paths['dat'] . '.tmp' . getmypid();
    $datHandle = @fopen($tmpDat, 'w');
    if (!$datHandle) {
        fclose($handle);
        return ['ok' => false, 'count' => 0, 'error' => "Could not write {$tmpDat}"];
    }

    $records = [];
    $offset = 0;
    while (($row = fgetcsv($handle)) !== false) {
        if (!isset($row[$cols['id']])) {
            continue;
        }
        $id = (int) $row[$cols['id']];
        if ($id <= 0) {
            continue;
        }

        $name = (string) ($row[$cols['name']] ?? '');
        if ($name === '') {
            continue; // unnamed placeholder rows are useless to us
        }

        $len = strlen($name);
        fwrite($datHandle, $name);

        $records[$id] = pack(
            'VVvCcvv',
            $id,
            $offset,
            $len,
            max(0, min(255, (int) ($row[$cols['quality']] ?? 0))),
            max(-128, min(127, (int) ($row[$cols['invtype']] ?? 0))),
            max(0, min(65535, (int) ($row[$cols['ilvl']] ?? 0))),
            max(0, min(65535, (int) ($row[$cols['reqlevel']] ?? 0)))
        );
        $offset += $len;
    }

    fclose($handle);
    fclose($datHandle);

    ksort($records, SORT_NUMERIC);
    if (@file_put_contents($tmpIdx, implode('', $records)) === false) {
        @unlink($tmpDat);
        return ['ok' => false, 'count' => 0, 'error' => "Could not write {$tmpIdx}"];
    }

    if (!@rename($tmpDat, $paths['dat']) || !@rename($tmpIdx, $paths['idx'])) {
        @unlink($tmpDat);
        @unlink($tmpIdx);
        return ['ok' => false, 'count' => 0, 'error' => 'Could not move the freshly built index into place'];
    }

    // Drop indexes built from older CSV exports. (Two globs rather than
    // GLOB_BRACE — that flag doesn't exist on Windows or musl PHP builds.)
    $stale = array_merge(glob($dir . '/items-*.idx') ?: [], glob($dir . '/items-*.dat') ?: []);
    foreach ($stale as $old) {
        if ($old !== $paths['idx'] && $old !== $paths['dat']) {
            @unlink($old);
        }
    }

    return ['ok' => true, 'count' => count($records), 'error' => null];
}

/**
 * Unpack one index record into the shape the Armory uses.
 */
function itemDb2Record(string $bytes, $datHandle): array
{
    $r = unpack('Vid/Voffset/vlen/Cquality/cinvtype/vilvl/vreqlevel', $bytes);
    fseek($datHandle, $r['offset']);
    $name = $r['len'] > 0 ? (string) fread($datHandle, $r['len']) : '';

    return [
        'entry'          => (int) $r['id'],
        'name'           => $name,
        'quality'        => (int) $r['quality'],
        'inventory_type' => (int) $r['invtype'],
        'item_level'     => (int) $r['ilvl'],
        'required_level' => (int) $r['reqlevel'],
        'source'         => 'db2',
    ];
}

/**
 * Look up item entries in the bundled DB2 export. Returns entry => item row
 * for every entry that could be resolved.
 */
function itemDb2Lookup(array $config, array $entries): array
{
    $entries = array_values(array_unique(array_map('intval', $entries)));
    if (!$entries) {
        return [];
    }

    $build = itemDb2BuildIndex($config);
    if ($build['ok']) {
        $paths = itemCachePaths($config);
        $idx = @fopen($paths['idx'], 'rb');
        $dat = @fopen($paths['dat'], 'rb');
        if ($idx && $dat) {
            $count = (int) (filesize($paths['idx']) / ITEMDB_RECORD_SIZE);
            $found = [];
            foreach ($entries as $entry) {
                $record = itemDb2BinarySearch($idx, $count, $entry);
                if ($record !== null) {
                    $found[$entry] = itemDb2Record($record, $dat);
                }
            }
            fclose($idx);
            fclose($dat);
            return $found;
        }
        if ($idx) {
            fclose($idx);
        }
        if ($dat) {
            fclose($dat);
        }
    }

    // No usable cache (e.g. read-only deployment) — scan the CSV once.
    return itemDb2ScanCsv($config, $entries);
}

/**
 * Binary search the sorted index file for an item id.
 */
function itemDb2BinarySearch($handle, int $count, int $entry): ?string
{
    $low = 0;
    $high = $count - 1;

    while ($low <= $high) {
        $mid = intdiv($low + $high, 2);
        fseek($handle, $mid * ITEMDB_RECORD_SIZE);
        $bytes = fread($handle, ITEMDB_RECORD_SIZE);
        if ($bytes === false || strlen($bytes) < ITEMDB_RECORD_SIZE) {
            return null;
        }
        $id = unpack('V', substr($bytes, 0, 4))[1];

        if ($id === $entry) {
            return $bytes;
        }
        if ($id < $entry) {
            $low = $mid + 1;
        } else {
            $high = $mid - 1;
        }
    }

    return null;
}

/**
 * Fallback: one streaming pass over the CSV picking out the wanted entries.
 */
function itemDb2ScanCsv(array $config, array $entries): array
{
    $csv = itemSparseCsvPath($config);
    if ($csv === null) {
        return [];
    }

    $handle = @fopen($csv, 'r');
    if (!$handle) {
        return [];
    }

    $header = fgetcsv($handle);
    $cols = is_array($header) ? itemSparseColumns($header) : null;
    if ($cols === null) {
        fclose($handle);
        return [];
    }

    $wanted = array_flip($entries);
    $found = [];
    while (($row = fgetcsv($handle)) !== false) {
        $id = (int) ($row[$cols['id']] ?? 0);
        if (!isset($wanted[$id])) {
            continue;
        }
        $found[$id] = [
            'entry'          => $id,
            'name'           => (string) ($row[$cols['name']] ?? ''),
            'quality'        => (int) ($row[$cols['quality']] ?? 0),
            'inventory_type' => (int) ($row[$cols['invtype']] ?? 0),
            'item_level'     => (int) ($row[$cols['ilvl']] ?? 0),
            'required_level' => (int) ($row[$cols['reqlevel']] ?? 0),
            'source'         => 'db2-scan',
        ];
        unset($wanted[$id]);
        if (!$wanted) {
            break;
        }
    }
    fclose($handle);

    return $found;
}

/**
 * Human-readable state of the DB2 fallback, for the diagnostics page.
 */
function itemDb2Status(array $config): array
{
    $csv = itemSparseCsvPath($config);
    $paths = itemCachePaths($config);
    $cacheDir = itemCacheDir($config);

    $status = [
        'csv'            => $csv,
        'csv_size'       => $csv ? (int) @filesize($csv) : 0,
        'cache_dir'      => $cacheDir,
        'cache_writable' => is_dir($cacheDir) ? is_writable($cacheDir) : is_writable(dirname($cacheDir)),
        'index_built'    => $paths !== null && is_file($paths['idx']) && is_file($paths['dat']),
        'index_count'    => 0,
        'error'          => null,
    ];

    if ($csv === null) {
        $status['error'] = 'No ItemSparse*.csv found in ' . itemDb2Dir($config);
        return $status;
    }

    $build = itemDb2BuildIndex($config);
    $status['index_built'] = $build['ok'];
    $status['index_count'] = $build['count'];
    $status['error'] = $build['error'];

    return $status;
}
