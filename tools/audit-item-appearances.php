<?php
/**
 * Audit of appearance -> item resolution.
 *
 * The equipmentCache only stores a look (display id + subclass + inventory
 * type), and several items can share one look, so some slots can never be
 * resolved with certainty from static data. This tool lists every case the
 * resolver has to guess, so a realm operator can pin the right item in
 * data/item-overrides.json instead of discovering mismatches one by one.
 *
 * Usage:
 *   php tools/audit-item-appearances.php [--limit N] [--suggest] [--junk]
 *
 * Sections:
 *   1. items the bundled export cannot describe at all (referenced by the
 *      appearance graph, but no Item/ItemSparse row) — these are invisible
 *      to the old resolver;
 *   2. looks that are shared by more than one item;
 *   3. shared looks that stay ambiguous after subclass + inventory type +
 *      class filtering (the resolver must guess; consider an override);
 *   4. looks that only exist in the realm's own hotfixes data (custom items);
 *   5. --suggest: draft overrides for ambiguous looks that involve an item
 *      the export cannot describe (the likely realm item).
 *
 * The database is optional: without it the realm hotfixes layer is skipped.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../includes/bootstrap.php';

// CLI runs (e.g. in CI or a sandbox) may not have a reachable database; the
// export-based sections still work without it. Set ARMORY_AUDIT_NO_DB=1 to
// skip the realm hotfixes layer and equipped observations.
if (getenv('ARMORY_AUDIT_NO_DB')) {
    $config['db_host'] = '';
}

$limit = 30;
$suggest = false;
$showJunk = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--suggest') {
        $suggest = true;
    } elseif ($arg === '--junk') {
        $showJunk = true;
    } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(0, (int) $m[1]);
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(1);
    }
}

$tables = itemVisualTables($config);
$realm = realmAppearanceLayer($config);
$names = $realm['names'] ?? [];
$db2names = static function (int $entry) use ($names): string {
    if (isset($names[$entry])) {
        return $names[$entry];
    }
    $row = itemDb2Lookup($GLOBALS['config'], [$entry])[$entry] ?? null;
    return $row ? $row['name'] : '⟨missing from export⟩';
};

$unnamedIds = array_map('intval', $tables['unnamed'] ?? []);
$untypedIds = array_map('intval', $tables['untyped'] ?? []);
$allLooks = count($tables['resolve']);
$shared = 0;
$ambiguous = 0;
$ambiguousDetails = [];
$realmOnlyLooks = [];

foreach ($tables['resolve'] as $display => $rows) {
    $merged = itemAppearanceMergeCandidates($rows, $realm['resolve'][$display] ?? []);
    if (count($merged) > 1) {
        $shared++;
    }
    // Any candidate that only the realm layer knows = custom realm item.
    $realmEntries = [];
    foreach ($realm['resolve'][$display] ?? [] as $row) {
        $realmEntries[(int) $row[0]] = true;
    }
    foreach ($merged as $row) {
        if (isset($realmEntries[(int) $row[0]])) {
            $realmOnlyLooks[$display] = true;
        }
    }
    // Ambiguity: after subclass + inventory type + class, >1 candidate may
    // still be selectable (class masks overlap or are unknown).
    $groups = [];
    foreach ($merged as $row) {
        $key = $row[1] . ':' . $row[2];
        $groups[$key][] = $row;
    }
    foreach ($groups as $group) {
        if (count($group) < 2) {
            continue;
        }
        $classCompatible = false;
        for ($i = 0; $i < count($group) && !$classCompatible; $i++) {
            for ($j = $i + 1; $j < count($group); $j++) {
                $a = (int) $group[$i][6];
                $b = (int) $group[$j][6];
                if ($a === 0 || $b === 0 || ($a & $b) !== 0) {
                    $classCompatible = true;
                    break;
                }
            }
        }
        if ($classCompatible) {
            $ambiguous++;
            $ambiguousDetails[] = ['display' => (int) $display, 'rows' => $group];
        }
    }
}

echo "Appearance resolution audit\n";
echo str_repeat('=', 72) . "\n";
echo "looks: " . number_format($allLooks) . ", shared by >1 item: " . number_format($shared)
   . ", ambiguous after slot+class filtering: " . number_format($ambiguous) . "\n";

echo "\n1. Items the export cannot describe (no Item/ItemSparse row)\n";
echo str_repeat('-', 72) . "\n";
echo count($unnamedIds) . " referenced by the appearance graph but with no name in the bundled export;\n"
   . count($untypedIds) . " of them also have no subclass/inventory type (recovered as untyped).\n";
if ($limit > 0 && $unnamedIds) {
    echo "sample:\n";
    foreach (array_slice($unnamedIds, 0, $limit) as $id) {
        $visual = $realm['items'][$id] ?? $tables['items'][$id] ?? [0, 0, 0, 0];
        printf("  %-8d display=%-8d icon=%-8d %s\n", $id, $visual[0] ?? 0, $visual[1] ?? 0, $db2names($id));
    }
}

echo "\n2. Shared looks (more than one item, resolver must rank them)\n";
echo str_repeat('-', 72) . "\n";
echo number_format($shared) . " shared looks; " . number_format(count($realmOnlyLooks))
   . " contain items only the realm's hotfixes data knows (custom items).\n";

echo "\n3. Ambiguous shared looks (same slot, class-compatible candidates)\n";
echo str_repeat('-', 72) . "\n";
echo number_format($ambiguous) . " looks where the resolver has to guess. ";
if ($limit > 0 && $ambiguousDetails) {
    echo "sample:\n";
    usort($ambiguousDetails, static fn ($a, $b) => count($b['rows']) <=> count($a['rows']));
    foreach (array_slice($ambiguousDetails, 0, $limit) as $detail) {
        $list = [];
        foreach ($detail['rows'] as $row) {
            $mark = !empty($row[8]) ? ' [placeholder]' : '';
            $list[] = $row[0] . ' ' . $db2names((int) $row[0]) . $mark;
        }
        echo '  display ' . $detail['display'] . ' (' . count($detail['rows']) . " candidates):\n";
        foreach ($list as $line) {
            echo '    ' . $line . "\n";
        }
    }
}

if ($suggest) {
    echo "\n5. Suggested overrides (review before using)\n";
    echo str_repeat('-', 72) . "\n";
    echo "Draft entries for ambiguous looks that include an item the export cannot\n";
    echo "describe — that item is the likely realm item and should be pinned.\n\n";
    $draft = [];
    foreach ($ambiguousDetails as $detail) {
        $missing = null;
        foreach ($detail['rows'] as $row) {
            if (!isset($names[(int) $row[0]]) && $row[1] < 0) {
                $missing = $row;
                break;
            }
        }
        if ($missing) {
            $draft[(string) $missing[0]] = [
                'display_id' => $detail['display'],
                'inventory_type' => $missing[2] >= 0 ? $missing[2] : 0,
                'subclass' => $missing[1] >= 0 ? $missing[1] : 0,
                'allowable_class' => $missing[6] ?: 0,
                'comment' => 'Audit suggestion: verify name/quality/item level against the realm client, then add them.',
            ];
        }
    }
    if (!$draft) {
        echo "(none found)\n";
    } else {
        echo json_encode(['schema' => 1, 'items' => $draft], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }
}

if ($showJunk) {
    echo "\n4. Placeholder candidates (\"Monster -\", \"Test...\") ranked last\n";
    echo str_repeat('-', 72) . "\n";
    $junkCount = 0;
    foreach ($tables['resolve'] as $display => $rows) {
        foreach (itemAppearanceMergeCandidates($rows, $realm['resolve'][$display] ?? []) as $row) {
            if (!empty($row[8])) {
                $junkCount++;
            }
        }
    }
    echo number_format($junkCount) . " candidates across " . number_format($allLooks) . " looks.\n";
}

echo "\nPinned by data/item-overrides.json: " . count(itemOverrides($config)) . " item(s).\n";
echo "Done.\n";
