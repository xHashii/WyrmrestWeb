<?php
/**
 * Item icons, model display IDs, and appearance -> item resolution.
 *
 * An item ID, an ItemAppearance ID, a display ID and an icon FileDataID are
 * separate namespaces. ItemModifiedAppearance lets us find which *valid item
 * templates* share a display, but it is not itself an item catalogue: Blizzard
 * ships stale/dangling ItemID references in that table. An ID is a stock item
 * template only when both Item and ItemSparse describe it. A custom realm item
 * must likewise have authoritative item + item_sparse rows, or an explicit
 * data/item-overrides.json record based on a real item_instance.itemEntry.
 *
 * WyrmrestCore's characters.equipmentCache contains a visible display ID,
 * inventory type, enchant visual, visible subclass and a SECONDARY transmog
 * appearance. It does not contain the equipped itemEntry or the primary
 * modified appearance, so a display shared by several valid templates cannot
 * be reversed from the cache string alone. The resolver therefore weighs real
 * realm evidence before giving up: the saved type/subclass/class filters, an
 * item_instance owned by this very character, the realm's item_instance index,
 * and explicit operator overrides (see itemAppearanceIdentityVerdict). A slot
 * is left anonymous only when no evidence can defensibly name it; it never
 * receives a purely invented identity. Exact identity always comes from
 * character_inventory -> item_instance.itemEntry whenever those rows are
 * readable.
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

/**
 * Is this a placeholder a player can never equip (NPC visuals, test items)?
 * These share looks with real gear and must rank below it.
 */
function itemAppearanceJunkName(string $name): bool
{
    return (bool) preg_match('/^(Monster|Creature|Test)\s*[-:]/i', trim($name));
}

/**
 * Compact lookup tables generated from the bundled exports.
 *
 *   items:       Item ID => [display, icon FileDataID, inventory type, subclass]
 *   templates:   IDs backed by both Item and ItemSparse (plus explicit overrides)
 *   displays:    display ID => icon FileDataID
 *   ima:         valid ItemModifiedAppearance ID => [item ID, subclass, type]
 *   ima_visuals: every IMA ID => [display, icon], including dangling source rows
 *   resolve:     display ID => valid candidate rows
 *   orphans:     ItemIDs referenced by IMA but missing Item or ItemSparse
 *   unnamed:     Item rows with no ItemSparse metadata
 *
 * Dangling IMA rows remain useful for rendering a requested appearance, but
 * are never promoted into item identities. A read-only cache directory falls
 * back to building these tables in memory for the request.
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
    $cache = $cacheDir . '/item-visuals-v6-' . $stamp . '.json';
    if (is_file($cache)) {
        $decoded = json_decode((string) @file_get_contents($cache), true);
        if (is_array($decoded)
            && is_array($decoded['items'] ?? null)
            && is_array($decoded['templates'] ?? null)
            && is_array($decoded['displays'] ?? null)
            && is_array($decoded['resolve'] ?? null)
            && is_array($decoded['ima'] ?? null)
            && is_array($decoded['ima_visuals'] ?? null)
            && is_array($decoded['orphans'] ?? null)) {
            return $memo[$stamp] = $decoded;
        }
    }

    $data = [
        'items' => [], 'templates' => [], 'displays' => [], 'resolve' => [],
        'ima' => [], 'ima_visuals' => [], 'orphans' => [], 'unnamed' => [],
    ];

    // appearance ID => [display ID, icon FileDataID]
    $appearances = [];
    foreach (itemVisualCsvRows($sources['ItemAppearance'] ?? null, ['ID', 'ItemDisplayInfoID', 'DefaultIconFileDataID']) as $row) {
        $appearances[$row['ID']] = [$row['ItemDisplayInfoID'], $row['DefaultIconFileDataID']];
        if ($row['ItemDisplayInfoID'] > 0 && empty($data['displays'][$row['ItemDisplayInfoID']])) {
            $data['displays'][$row['ItemDisplayInfoID']] = $row['DefaultIconFileDataID'];
        }
    }

    // ItemSparse: item ID => [class mask, item level, name, quality]
    $sparseMeta = [];
    foreach (itemVisualCsvRows($sources['ItemSparse'] ?? null, ['ID', 'AllowableClass', 'ItemLevel'], ['Display_lang', 'OverallQualityID'], ['Display_lang']) as $row) {
        $allowable = $row['AllowableClass'];
        if ($allowable < 0 || $allowable >= 0x7F000000) {
            $allowable = 0; // -1 / 0xFFFFFFFF means every class
        }
        $sparseMeta[$row['ID']] = [$allowable, $row['ItemLevel'], (string) $row['Display_lang'], $row['OverallQualityID']];
    }

    $needsAppearanceIcon = [];
    foreach (itemVisualCsvRows($sources['Item'] ?? null, ['ID', 'IconFileDataID', 'InventoryType'], ['SubclassID']) as $row) {
        $id = $row['ID'];
        $data['items'][$id] = [0, $row['IconFileDataID'], $row['InventoryType'], $row['SubclassID']];
        if (isset($sparseMeta[$id])) {
            $data['templates'][$id] = 1;
        }
        if ($row['IconFileDataID'] <= 0) {
            $needsAppearanceIcon[$id] = true;
        }
    }

    $chosen = [];
    $orphans = [];
    // display ID => [item ID, subclass, inventory type, preferred,
    //                appearance rank, order index, class mask, item level, junk]
    $candidates = [];
    foreach (itemVisualCsvRows($sources['ItemModifiedAppearance'] ?? null, ['ItemID', 'ItemAppearanceModifierID', 'ItemAppearanceID', 'OrderIndex'], ['ID']) as $row) {
        $appearance = $appearances[$row['ItemAppearanceID']] ?? null;
        if (!$appearance) {
            continue;
        }
        $imaId = $row['ID'];
        $id = $row['ItemID'];
        if ($imaId > 0) {
            // A dangling source ItemID can still represent a renderable transmog.
            $data['ima_visuals'][$imaId] = [$appearance[0], $appearance[1]];
        }

        $rank = [$row['ItemAppearanceModifierID'] === 0 ? 0 : 1, $row['OrderIndex']];
        // An Item row can still provide useful visual data when exact inventory
        // already supplied its identity, even if Sparse metadata is absent.
        if (isset($data['items'][$id]) && (!isset($chosen[$id]) || $chosen[$id] > $rank)) {
            $chosen[$id] = $rank;
            $data['items'][$id][0] = $appearance[0];
            if (isset($needsAppearanceIcon[$id])) {
                $data['items'][$id][1] = $appearance[1];
            }
        }

        // ItemModifiedAppearance is not an item-template table. Only the
        // Item+ItemSparse intersection can supply a stock cache identity.
        if (!isset($data['templates'][$id])) {
            $orphans[$id] = true;
            continue;
        }

        $display = $appearance[0];
        [$classMask, $itemLevel, $name] = $sparseMeta[$id];
        if ($display > 0) {
            $candidates[$display][] = [
                $id, $data['items'][$id][3], $data['items'][$id][2],
                0, $rank[0], $rank[1], $classMask, $itemLevel,
                $name !== '' && itemAppearanceJunkName($name),
            ];
        }
        if ($imaId > 0 && !isset($data['ima'][$imaId])) {
            $data['ima'][$imaId] = [$id, $data['items'][$id][3], $data['items'][$id][2]];
        }
    }

    // An override is an explicit assertion backed by this realm's real data.
    // It may correct a stock row or define a custom template absent from DB2.
    foreach (itemOverrides($config) as $id => $override) {
        $display = (int) ($override['display_id'] ?? 0);
        $subclass = (int) ($override['subclass'] ?? 0);
        $inventoryType = (int) ($override['inventory_type'] ?? 0);
        $icon = (int) ($override['icon_file_data_id'] ?? 0);
        $data['items'][$id] = [$display, $icon, $inventoryType, $subclass];
        $data['templates'][$id] = 1;
        if ($display <= 0) {
            continue;
        }
        if (!empty($data['displays'][$display])) {
            $icon = $data['displays'][$display];
        } else {
            $data['displays'][$display] = $icon;
        }
        $classMask = (int) ($override['allowable_class'] ?? 0);
        if ($classMask < 0 || $classMask >= 0x7F000000) {
            $classMask = 0;
        }
        $candidates[$display][] = [
            $id, $subclass, $inventoryType,
            1, 0, 0, $classMask, (int) ($override['item_level'] ?? 0), 0,
        ];
    }

    foreach ($candidates as $display => $rows) {
        // Explicit overrides first, then non-placeholder rows, then the
        // default appearance/order. Keep every distinct item: truncating this
        // list can make an ambiguous look appear falsely unique.
        usort($rows, static fn ($a, $b) => [$b[3], $a[8], $a[4], $a[5], $a[0]] <=> [$a[3], $b[8], $b[4], $b[5], $b[0]]);
        $seen = [];
        foreach ($rows as $row) {
            $id = (int) $row[0];
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $data['resolve'][$display][] = $row;
            }
        }
    }

    ksort($data['templates'], SORT_NUMERIC);
    if ($orphans) {
        $ids = array_keys($orphans);
        sort($ids, SORT_NUMERIC);
        $data['orphans'] = array_map('strval', $ids);
    }
    $overrides = itemOverrides($config);
    foreach ($data['items'] as $id => $row) {
        if (!isset($sparseMeta[$id]) && !isset($overrides[$id])) {
            $data['unnamed'][] = (string) $id;
        }
    }
    sort($data['unnamed'], SORT_NUMERIC);

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
 * Result is re-ranked but never truncated: every candidate must remain visible
 * so an ambiguous display cannot accidentally look unique.
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
    return $rows;
}

/**
 * Realm DB2 hotfix rows, layered over the bundled client export. A realm-only
 * identity is accepted only when an Item row and an ItemSparse row exist (the
 * same pair WyrmrestCore requires to build an item template). Appearance rows
 * with no template remain available in ima_visuals but never become items.
 */
function realmAppearanceLayer(array $config): array
{
    static $memo = [];
    $memoKey = sha1(json_encode([
        $config['db_host'] ?? '', $config['db_port'] ?? 0, $config['db_name'] ?? '',
        $config['hotfixes_db_name'] ?? '', itemDb2Dir($config), itemOverridesPath($config),
    ]));
    if (isset($memo[$memoKey])) {
        return $memo[$memoKey];
    }

    $empty = [
        'items' => [], 'displays' => [], 'ima' => [], 'ima_visuals' => [],
        'resolve' => [], 'sparse' => [], 'names' => [], 'orphans' => [],
        'available' => false,
    ];
    $pdo = connectCharactersDb($config);
    if (!$pdo || empty($config['hotfixes_db_name'])) {
        return $memo[$memoKey] = $empty;
    }
    $hotfixes = $config['hotfixes_db_name'];

    $dir = itemCacheDir($config);
    $cacheKey = substr(sha1(implode('|', [
        $config['db_host'] ?? '', $config['db_port'] ?? 0,
        $config['db_name'] ?? '', $hotfixes,
    ])), 0, 12);
    $cache = $dir . '/realm-appearance-v3-' . $cacheKey . '.json';
    if (is_file($cache) && (@filemtime($cache) + 300) > time()) {
        $decoded = json_decode((string) @file_get_contents($cache), true);
        if (is_array($decoded)
            && isset($decoded['items'], $decoded['ima'], $decoded['ima_visuals'],
                $decoded['resolve'], $decoded['sparse'], $decoded['names'],
                $decoded['orphans'], $decoded['available'])) {
            return $memo[$memoKey] = $decoded;
        }
    }

    $read = static function (string $table, array $columns) use ($pdo, $hotfixes): ?array {
        try {
            $stmt = $pdo->query('SELECT ' . implode(',', $columns) . " FROM `{$hotfixes}`.`{$table}` ORDER BY VerifiedBuild ASC");
            return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (\Throwable $e) {
            dbNoteError("read `{$hotfixes}`.`{$table}`", $e);
            return null;
        }
    };

    $imaRows = $read('item_modified_appearance', ['ID', 'ItemID', 'ItemAppearanceModifierID', 'ItemAppearanceID', 'OrderIndex']);
    $appearanceRows = $read('item_appearance', ['ID', 'ItemDisplayInfoID', 'DefaultIconFileDataID']);
    if ($imaRows === null || $appearanceRows === null) {
        return $memo[$memoKey] = $empty;
    }

    // Latest VerifiedBuild wins because rows arrive in ascending build order.
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
    // Hotfix tables can be incremental: a custom IMA may legitimately refer
    // to a stock ItemAppearance that is present only in the bundled export.
    $neededAppearances = [];
    foreach ($ima as $row) {
        if (!isset($appearances[$row[2]])) {
            $neededAppearances[$row[2]] = true;
        }
    }
    if ($neededAppearances) {
        foreach (itemVisualCsvRows(itemVisualCsvPath($config, 'ItemAppearance'), ['ID', 'ItemDisplayInfoID', 'DefaultIconFileDataID']) as $row) {
            if (isset($neededAppearances[$row['ID']])) {
                $appearances[$row['ID']] = [$row['ItemDisplayInfoID'], $row['DefaultIconFileDataID']];
                unset($neededAppearances[$row['ID']]);
                if (!$neededAppearances) {
                    break;
                }
            }
        }
    }
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

    $base = itemVisualTables($config);

    $layer = $empty;
    $layer['available'] = true;
    foreach ($appearances as $appearance) {
        if ($appearance[0] > 0 && !isset($layer['displays'][$appearance[0]])) {
            $layer['displays'][$appearance[0]] = $appearance[1];
        }
    }

    $chosen = [];
    $orphans = [];
    foreach ($ima as $imaId => $row) {
        [$id, $mod, $appearanceId, $order] = $row;
        $appearance = $appearances[$appearanceId] ?? null;
        if (!$appearance) {
            continue;
        }
        [$display, $appearanceIcon] = $appearance;
        $layer['ima_visuals'][$imaId] = [$display, $appearanceIcon];

        $hasBaseTemplate = isset($base['templates'][$id]);
        $hasItemRow = isset($realmItems[$id]) || isset($base['items'][$id]);
        $hasSparseRow = isset($sparse[$id]) || $hasBaseTemplate;
        if (!$hasItemRow || !$hasSparseRow) {
            $orphans[$id] = true;
            continue;
        }

        $baseItem = $base['items'][$id] ?? null;
        $item = $realmItems[$id] ?? null;
        $sub = $item !== null ? $item[2] : ($baseItem[3] ?? ($extra[$imaId][0] ?? -1));
        $inv = $item !== null ? $item[1] : ($baseItem[2] ?? ($extra[$imaId][1] ?? -1));
        $icon = $item !== null && $item[0] > 0 ? $item[0] : (($baseItem[1] ?? 0) ?: $appearanceIcon);
        $rank = [$mod === 0 ? 0 : 1, $order];
        if ($display > 0 && (!isset($chosen[$id]) || $chosen[$id] > $rank)) {
            $chosen[$id] = $rank;
            $layer['items'][$id] = [$display, $icon, max(0, $inv), max(0, $sub)];
        }

        $baseCandidate = null;
        foreach ($base['resolve'][$display] ?? [] as $candidate) {
            if ((int) $candidate[0] === $id) {
                $baseCandidate = $candidate;
                break;
            }
        }
        [$classMask, $itemLevel, $name] = $sparse[$id] ?? [
            (int) ($baseCandidate[6] ?? 0),
            (int) ($baseCandidate[7] ?? 0),
            '',
        ];
        $junk = $name !== '' ? itemAppearanceJunkName($name) : !empty($baseCandidate[8]);
        if ($display > 0) {
            $layer['resolve'][$display][] = [
                $id, $sub, $inv, 0, $rank[0], $rank[1],
                $classMask, $itemLevel, $junk,
            ];
        }
        $layer['ima'][$imaId] = [$id, $sub, $inv];
    }

    foreach ($layer['resolve'] as $display => $rows) {
        $layer['resolve'][$display] = itemAppearanceMergeCandidates([], $rows);
    }
    if ($orphans) {
        $ids = array_keys($orphans);
        sort($ids, SORT_NUMERIC);
        $layer['orphans'] = array_map('strval', $ids);
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
                foreach (glob($dir . '/realm-appearance-v*.json') ?: [] as $old) {
                    if ($old !== $cache) {
                        @unlink($old);
                    }
                }
            } else {
                @unlink($tmp);
            }
        }
    }
    return $memo[$memoKey] = $layer;
}

/**
 * Every exact item entry referenced by character_inventory, including equipped,
 * profession, bag, backpack, and bank rows. This superset lets the coverage
 * audit find metadata gaps for every item an Armory deployment may expose.
 */
function realmInventoryItems(array $config): array
{
    static $memo = [];
    $memoKey = sha1(json_encode([
        $config['db_host'] ?? '', $config['db_port'] ?? 0, $config['db_name'] ?? '',
    ]));
    if (isset($memo[$memoKey])) {
        return $memo[$memoKey];
    }
    $pdo = connectCharactersDb($config);
    if (!$pdo) {
        return $memo[$memoKey] = [];
    }
    try {
        $stmt = $pdo->query('
            SELECT ii.itemEntry AS entry, COUNT(*) AS n
            FROM item_instance ii
            JOIN character_inventory ci ON ci.item = ii.guid AND ci.guid = ii.owner_guid
            WHERE ii.itemEntry > 0
            GROUP BY ii.itemEntry
            ORDER BY ii.itemEntry ASC
        ');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (\Throwable $e) {
        dbNoteError('audit exact realm inventory item entries', $e);
        return $memo[$memoKey] = [];
    }
    $found = [];
    foreach ($rows as $row) {
        $found[(int) $row['entry']] = (int) $row['n'];
    }
    return $memo[$memoKey] = $found;
}

/**
 * Realm-wide item_instance index: itemEntry => instance count.
 *
 * A candidate item that exists as a real instance on this realm — equipped,
 * bagged, banked, mailed, auctioned, or in a guild bank — is hard evidence
 * the template is live here. That is what lets a shared saved appearance be
 * identified even though characters.equipmentCache stores no item ID: after
 * the saved subclass/inventory-type/class filters, exactly one candidate
 * present in this index is a defensible identity, and among several present
 * candidates the commonest is the best available match.
 *
 * The index is cached on disk for a few minutes (identical pattern to
 * realmAppearanceLayer); a read-only cache dir simply re-queries per request.
 */
function realmItemInstanceIndex(array $config): array
{
    static $memo = [];
    $memoKey = sha1(json_encode([
        $config['db_host'] ?? '', $config['db_port'] ?? 0, $config['db_name'] ?? '',
    ]));
    if (isset($memo[$memoKey])) {
        return $memo[$memoKey];
    }

    $pdo = connectCharactersDb($config);
    if (!$pdo) {
        return $memo[$memoKey] = [];
    }

    $dir = itemCacheDir($config);
    $cacheKey = substr(sha1(implode('|', [
        $config['db_host'] ?? '', $config['db_port'] ?? 0, $config['db_name'] ?? '',
    ])), 0, 12);
    $cache = $dir . '/inventory-index-v1-' . $cacheKey . '.json';
    if (is_file($cache) && (@filemtime($cache) + 300) > time()) {
        $decoded = json_decode((string) @file_get_contents($cache), true);
        if (is_array($decoded) && isset($decoded['items'], $decoded['total'])) {
            $items = [];
            foreach ($decoded['items'] as $entry => $count) {
                $items[(int) $entry] = (int) $count;
            }
            return $memo[$memoKey] = $items;
        }
    }

    try {
        $stmt = $pdo->query('
            SELECT itemEntry, COUNT(*) AS n
            FROM item_instance
            WHERE itemEntry > 0
            GROUP BY itemEntry
            ORDER BY itemEntry ASC
        ');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (\Throwable $e) {
        dbNoteError('index realm item instances', $e);
        return $memo[$memoKey] = [];
    }

    $found = [];
    $total = 0;
    foreach ($rows as $row) {
        $found[(int) $row['itemEntry']] = (int) $row['n'];
        $total += (int) $row['n'];
    }

    if ((is_dir($dir) || @mkdir($dir, 0775, true)) && is_writable($dir)) {
        $tmp = @tempnam($dir, 'inventory-index-');
        if ($tmp) {
            $json = json_encode(['items' => $found, 'total' => $total]);
            if ($json !== false && @file_put_contents($tmp, $json) !== false && @rename($tmp, $cache)) {
                @chmod($cache, 0664);
                foreach (glob($dir . '/inventory-index-v*.json') ?: [] as $old) {
                    if ($old !== $cache) {
                        @unlink($old);
                    }
                }
            } else {
                @unlink($tmp);
            }
        }
    }
    return $memo[$memoKey] = $found;
}

/**
 * itemEntry => instance count for every item instance this character owns,
 * regardless of where it currently sits (equipped, bags, bank, mail...).
 * When a cache-only slot must be identified, an instance of a candidate item
 * owned by this very character is the strongest evidence there is.
 */
function characterOwnedItemInstances(array $config, int $guid): array
{
    if ($guid <= 0) {
        return [];
    }
    $pdo = connectCharactersDb($config);
    if (!$pdo) {
        return [];
    }
    try {
        $stmt = $pdo->prepare('
            SELECT itemEntry, COUNT(*) AS n
            FROM item_instance
            WHERE owner_guid = :guid AND itemEntry > 0
            GROUP BY itemEntry
        ');
        $stmt->execute(['guid' => $guid]);
        $found = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $found[(int) $row['itemEntry']] = (int) $row['n'];
        }
        return $found;
    } catch (\Throwable $e) {
        dbNoteError('read character-owned item instances', $e);
        return [];
    }
}

/** Resolve an ItemModifiedAppearance ID to visual data, never to identity. */
function itemModifiedAppearanceVisual(array $config, int $appearanceId): ?array
{
    if ($appearanceId <= 0) {
        return null;
    }
    $tables = itemVisualTables($config);
    $realm = realmAppearanceLayer($config);
    $visual = $realm['ima_visuals'][$appearanceId] ?? $tables['ima_visuals'][$appearanceId] ?? null;
    if (!is_array($visual) || (int) ($visual[0] ?? 0) <= 0) {
        return null;
    }
    return [
        'display_id' => (int) $visual[0],
        'icon_file_data_id' => (int) ($visual[1] ?? 0),
    ];
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
 * Valid candidates for a saved display after applying every identity-bearing
 * field actually present in equipmentCache. No fallback broadens the set when
 * a saved type/subclass disagrees: doing so would manufacture an identity.
 */
function itemAppearanceCandidates(
    array $config,
    int $displayId,
    int $subclass = -1,
    int $inventoryType = -1,
    ?int $characterClass = null
): array {
    if ($displayId <= 0) {
        return [];
    }
    $tables = itemVisualTables($config);
    $realm = realmAppearanceLayer($config);

    // A realm row for the same item replaces the bundled relationship.
    $realmEntries = $realm['items'] ?? [];
    $baseRows = array_values(array_filter(
        $tables['resolve'][$displayId] ?? [],
        static fn (array $candidate): bool => !isset($realmEntries[(int) $candidate[0]])
    ));
    $rows = itemAppearanceMergeCandidates($baseRows, $realm['resolve'][$displayId] ?? []);
    $rows = array_values(array_filter(
        $rows,
        static fn (array $candidate): bool => itemClassAllows((int) $candidate[6], $characterClass)
    ));

    if ($subclass >= 0) {
        $rows = array_values(array_filter(
            $rows,
            static fn (array $candidate): bool => (int) $candidate[1] === $subclass
        ));
    }
    if ($inventoryType >= 0) {
        $rows = array_values(array_filter(
            $rows,
            static fn (array $candidate): bool => (int) $candidate[2] === $inventoryType
        ));
    }

    // NPC/test visual templates do not make a real player item ambiguous when
    // at least one ordinary template carries the same look.
    $ordinary = array_values(array_filter($rows, static fn (array $candidate): bool => empty($candidate[8])));
    return $ordinary ?: $rows;
}

/**
 * Resolve a cache-only appearance to an identity using real realm evidence.
 *
 * WyrmrestCore does not save itemEntry in equipmentCache, so a saved display
 * can be shared by several valid templates. The saved subclass, inventory
 * type and character class filter that set first (see itemAppearanceCandidates);
 * what remains is decided by this ladder, strongest evidence first:
 *
 *   unique         exactly one candidate survives the filters (as before)
 *   character      exactly one candidate has a real item_instance owned by
 *                  this character — the same player's own records
 *   realm-unique   exactly one candidate exists as any instance on the realm
 *   realm-best     several exist: the best-supported one wins (instances the
 *                  character owns, then realm instance count, then the
 *                  override/junk/default ranking), with the runners-up kept
 *                  as disclosed alternatives
 *   '' (none)      no defensible identity; the slot stays visibly anonymous
 *
 * $candidates are rows from itemAppearanceCandidates; $ownedCounts and
 * $realmCounts map itemEntry => instance count. Both evidence maps may be
 * empty (no database configured), which reduces the verdict to the old
 * unique-only decision.
 *
 * The cache's fifth field is a secondary shoulder/transmog appearance and
 * still cannot identify the primary item; it is ignored, as before.
 */
function itemAppearanceIdentityVerdict(array $candidates, array $ownedCounts, array $realmCounts): array
{
    $verdict = [
        'entry' => 0,
        'confidence' => '',
        'lookalike_count' => count($candidates),
        'realm_supported' => 0,
        'match_count' => 0,
        'alternatives' => [],
    ];
    if (!$candidates) {
        return $verdict;
    }

    // An explicit operator override pins the identity when exactly one is
    // among the candidates; two conflicting overrides stay unresolved.
    $preferred = array_values(array_filter($candidates, static fn (array $c): bool => !empty($c[3])));
    if (count($preferred) === 1) {
        $verdict['entry'] = (int) $preferred[0][0];
        $verdict['confidence'] = 'unique';
        $verdict['match_count'] = 1;
        return $verdict;
    }
    if (count($preferred) > 1) {
        return $verdict;
    }
    if (count($candidates) === 1) {
        $verdict['entry'] = (int) $candidates[0][0];
        $verdict['confidence'] = 'unique';
        $verdict['match_count'] = 1;
        return $verdict;
    }

    // Evidence the character itself provides: real instances it owns.
    $owned = [];
    $realm = [];
    foreach ($candidates as $candidate) {
        $entry = (int) $candidate[0];
        if (($ownedCounts[$entry] ?? 0) > 0) {
            $owned[$entry] = (int) $ownedCounts[$entry];
        }
        // An owned instance is itself present on the realm; the index only
        // widens the view, so never let it undercount presence.
        $presence = max((int) ($realmCounts[$entry] ?? 0), (int) ($ownedCounts[$entry] ?? 0));
        if ($presence > 0) {
            $realm[$entry] = $presence;
        }
    }
    if (count($owned) === 1) {
        $verdict['entry'] = (int) key($owned);
        $verdict['confidence'] = 'character';
        $verdict['match_count'] = 1;
        return $verdict;
    }

    // Evidence the realm provides: real instances anywhere (any owner).
    $verdict['realm_supported'] = count($realm);
    if (count($realm) === 1) {
        $verdict['entry'] = (int) key($realm);
        $verdict['confidence'] = 'realm-unique';
        $verdict['match_count'] = 1;
        return $verdict;
    }
    if (count($realm) > 1) {
        // Rank the supported candidates: the character's own instances first,
        // then how common the item is on the realm, then the existing
        // override/junk/appearance order as a deterministic tie-breaker.
        $supported = array_values(array_filter($candidates, static fn (array $c): bool => isset($realm[(int) $c[0]])));
        usort($supported, static function (array $a, array $b) use ($owned, $realm): int {
            return [
                $owned[(int) $b[0]] ?? 0, $realm[(int) $b[0]] ?? 0, (int) $b[3],
                (int) $a[8], (int) $a[4], (int) $a[5], (int) $a[0],
            ] <=> [
                $owned[(int) $a[0]] ?? 0, $realm[(int) $a[0]] ?? 0, (int) $a[3],
                (int) $b[8], (int) $b[4], (int) $b[5], (int) $b[0],
            ];
        });
        $verdict['entry'] = (int) $supported[0][0];
        $verdict['confidence'] = 'realm-best';
        $verdict['match_count'] = $realm[$verdict['entry']];
        $runners = array_slice($supported, 1, 3);
        foreach ($runners as $runner) {
            $entry = (int) $runner[0];
            $verdict['alternatives'][] = ['entry' => $entry, 'count' => $realm[$entry]];
        }
    }
    return $verdict;
}

/**
 * Resolve a cache-only appearance to an item entry, or 0 when it stays
 * anonymous. Realm-wide instance evidence participates (see
 * itemAppearanceIdentityVerdict); the character-owned tier is used by
 * cachedAppearanceItem, which knows the guid. Tests may inject counts via
 * $context to run without a database.
 */
function resolveItemFromAppearance(
    array $config,
    int $displayId,
    int $subclass = -1,
    int $inventoryType = -1,
    ?int $characterClass = null,
    int $secondaryAppearanceId = 0,
    array $context = []
): int {
    $candidates = itemAppearanceCandidates($config, $displayId, $subclass, $inventoryType, $characterClass);
    if (!$candidates) {
        return 0;
    }
    $realmCounts = $context['realm_counts'] ?? null;
    if (!is_array($realmCounts)) {
        $realmCounts = count($candidates) > 1 ? realmItemInstanceIndex($config) : [];
    }
    return itemAppearanceIdentityVerdict($candidates, [], $realmCounts)['entry'];
}

/** Number of plausible real identities left for a saved appearance. */
function itemAppearanceCandidateCount(
    array $config,
    int $displayId,
    int $subclass = -1,
    int $inventoryType = -1,
    ?int $characterClass = null
): int {
    return count(itemAppearanceCandidates($config, $displayId, $subclass, $inventoryType, $characterClass));
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
