<?php
/**
 * Complete item-template, appearance, and live-armory coverage audit.
 *
 * Usage:
 *   php tools/audit-item-appearances.php [--limit=N] [--json] [--junk] [--suggest]
 *
 * --json emits every compared ID, not samples. The database is optional; set
 * ARMORY_AUDIT_NO_DB=1 for a deterministic bundled-export audit. With database
 * access, every exact item_instance.itemEntry referenced by character_inventory
 * is compared with bundled, hotfix, world, and explicit-override metadata.
 *
 * ItemModifiedAppearance is deliberately audited as an appearance graph, not
 * an item catalog. Its dangling ItemIDs are reported and excluded; they must
 * never be copied into item-overrides.json unless a real inventory instance or
 * matching Item + ItemSparse hotfix rows independently prove the template.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
// The complete --json report retains several 45k-ID sets at once. Keep this
// CLI-only audit independent from the web worker's intentionally smaller cap.
@ini_set('memory_limit', '512M');

require __DIR__ . '/../includes/bootstrap.php';

if (getenv('ARMORY_AUDIT_NO_DB')) {
    $config['db_host'] = '';
}

$limit = 30;
$jsonOutput = false;
$showJunk = false;
$suggest = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--json') {
        $jsonOutput = true;
    } elseif ($arg === '--junk') {
        $showJunk = true;
    } elseif ($arg === '--suggest') {
        $suggest = true;
    } elseif (preg_match('/^--limit=(\d+)$/', $arg, $match)) {
        $limit = max(0, (int) $match[1]);
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(1);
    }
}

/** @return list<int> */
function auditSortedIds(array $set): array
{
    $ids = array_map('intval', array_keys($set));
    sort($ids, SORT_NUMERIC);
    return $ids;
}

/** @return array<int, true> */
function auditSetDifference(array $left, array $right): array
{
    return array_diff_key($left, $right);
}

function auditTextIds(array $ids, int $limit): string
{
    if (!$ids || $limit <= 0) {
        return '(none shown)';
    }
    $sample = array_slice($ids, 0, $limit);
    return implode(', ', $sample) . (count($ids) > $limit ? ', …' : '');
}

$itemRows = [];
foreach (itemVisualCsvRows(itemVisualCsvPath($config, 'Item'), ['ID', 'InventoryType', 'IconFileDataID'], ['SubclassID']) as $row) {
    $itemRows[(int) $row['ID']] = [
        'inventory_type' => (int) $row['InventoryType'],
        'subclass' => (int) $row['SubclassID'],
        'icon_file_data_id' => (int) $row['IconFileDataID'],
    ];
}

$sparseRows = [];
$oppositeFactionIds = [];
$craftingReagentIds = [];
foreach (itemVisualCsvRows(
    itemSparseCsvPath($config),
    ['ID', 'InventoryType'],
    ['Display_lang', 'OppositeFactionItemID', 'ModifiedCraftingReagentItemID'],
    ['Display_lang']
) as $row) {
    $sparseRows[(int) $row['ID']] = [
        'inventory_type' => (int) $row['InventoryType'],
        'name' => (string) $row['Display_lang'],
    ];
    if ((int) $row['OppositeFactionItemID'] > 0) {
        $oppositeFactionIds[(int) $row['OppositeFactionItemID']] = true;
    }
    if ((int) $row['ModifiedCraftingReagentItemID'] > 0) {
        $craftingReagentIds[(int) $row['ModifiedCraftingReagentItemID']] = true;
    }
}

$appearanceRows = [];
foreach (itemVisualCsvRows(itemVisualCsvPath($config, 'ItemAppearance'), ['ID', 'ItemDisplayInfoID', 'DefaultIconFileDataID']) as $row) {
    $appearanceRows[(int) $row['ID']] = [
        'display_id' => (int) $row['ItemDisplayInfoID'],
        'icon_file_data_id' => (int) $row['DefaultIconFileDataID'],
    ];
}

$imaSourceIds = [];
$imaRows = 0;
$imaMissingAppearances = [];
foreach (itemVisualCsvRows(itemVisualCsvPath($config, 'ItemModifiedAppearance'), ['ID', 'ItemID', 'ItemAppearanceID']) as $row) {
    $imaRows++;
    $itemId = (int) $row['ItemID'];
    $imaSourceIds[$itemId] = true;
    if (!isset($appearanceRows[(int) $row['ItemAppearanceID']])) {
        $imaMissingAppearances[(int) $row['ID']] = true;
    }
}

$itemSet = array_fill_keys(array_keys($itemRows), true);
$sparseSet = array_fill_keys(array_keys($sparseRows), true);
$stockSet = array_intersect_key($itemSet, $sparseSet);
$itemOnly = auditSetDifference($itemSet, $sparseSet);
$sparseOnly = auditSetDifference($sparseSet, $itemSet);
$appearanceOrphans = auditSetDifference($imaSourceIds, $stockSet);
$orphanItemOnly = array_intersect_key($appearanceOrphans, $itemOnly);
$orphanSparseOnly = array_intersect_key($appearanceOrphans, $sparseOnly);
$orphanNeither = auditSetDifference($appearanceOrphans, $itemSet + $sparseSet);
$missingOppositeFaction = auditSetDifference($oppositeFactionIds, $stockSet);
$missingCraftingReagents = auditSetDifference($craftingReagentIds, $stockSet);

$itemEffectRows = 0;
$itemEffectParentIds = [];
foreach (itemVisualCsvRows(itemVisualCsvPath($config, 'ItemEffect'), ['ID', 'ParentItemID']) as $row) {
    $itemEffectRows++;
    if ((int) $row['ParentItemID'] > 0) {
        $itemEffectParentIds[(int) $row['ParentItemID']] = true;
    }
}
$effectParentsOutsideTemplates = auditSetDifference($itemEffectParentIds, $stockSet);
$effectParentsOutsideItem = auditSetDifference($itemEffectParentIds, $itemSet);

$tables = itemVisualTables($config);
$overrides = itemOverrides($config);
$realm = realmAppearanceLayer($config);

$equippable = [];
$withoutDisplay = [];
$withoutIcon = [];
$missingIconName = [];
$inventoryMismatches = [];
$iconNames = json_decode((string) @file_get_contents(__DIR__ . '/../data/item-icon-names.json'), true) ?: [];
foreach ($stockSet as $id => $_) {
    if (($itemRows[$id]['inventory_type'] ?? 0) <= 0) {
        continue;
    }
    $equippable[$id] = true;
    $visual = $tables['items'][$id] ?? [0, 0, 0, 0];
    if ((int) ($visual[0] ?? 0) <= 0) {
        $withoutDisplay[$id] = true;
    }
    $icon = (int) ($visual[1] ?? 0);
    if ($icon <= 0) {
        $withoutIcon[$id] = true;
    } elseif (!isset($iconNames[(string) $icon]) && !isset($iconNames[$icon])) {
        $missingIconName[$id] = true;
    }
    if ((int) $itemRows[$id]['inventory_type'] !== (int) $sparseRows[$id]['inventory_type']) {
        $inventoryMismatches[$id] = true;
    }
}

$candidatePairs = 0;
$sharedDisplays = 0;
$maxCandidates = 0;
$overFormerCap = 0;
$placeholderCandidates = 0;
$ambiguousDisplays = [];
$allDisplayIds = array_fill_keys(array_keys($tables['resolve']), true)
    + array_fill_keys(array_keys($realm['resolve'] ?? []), true);
foreach ($allDisplayIds as $display => $_) {
    $baseRows = $tables['resolve'][$display] ?? [];
    $realmRows = $realm['resolve'][$display] ?? [];
    $rows = itemAppearanceMergeCandidates($baseRows, $realmRows);
    $candidatePairs += count($rows);
    $maxCandidates = max($maxCandidates, count($rows));
    if (count($rows) > 1) {
        $sharedDisplays++;
    }
    if (count($rows) > 12) {
        $overFormerCap++;
    }
    foreach ($rows as $row) {
        if (!empty($row[8])) {
            $placeholderCandidates++;
        }
    }

    // Find at least one type/subclass group whose ordinary candidates can be
    // worn by a common class. Such a cache-only display must remain anonymous.
    $groups = [];
    foreach ($rows as $row) {
        $groups[$row[1] . ':' . $row[2]][] = $row;
    }
    foreach ($groups as $group) {
        $ordinary = array_values(array_filter($group, static fn (array $row): bool => empty($row[8])));
        $eligible = $ordinary ?: $group;
        if (count($eligible) < 2) {
            continue;
        }
        $overlap = false;
        for ($i = 0; $i < count($eligible) && !$overlap; $i++) {
            for ($j = $i + 1; $j < count($eligible); $j++) {
                $a = (int) $eligible[$i][6];
                $b = (int) $eligible[$j][6];
                if ($a === 0 || $b === 0 || ($a & $b) !== 0) {
                    $overlap = true;
                    break;
                }
            }
        }
        if ($overlap) {
            $ambiguousDisplays[(int) $display] = true;
            break;
        }
    }
}

$dbAvailable = !empty($config['db_host']) && connectCharactersDb($config) !== null;
$inventoryEntries = $dbAvailable ? realmInventoryItems($config) : [];
$resolvedInventory = $inventoryEntries ? resolveItems($config, array_keys($inventoryEntries)) : [];
$observedBundled = [];
$observedOverride = [];
$observedRealmTemplate = [];
$observedDynamic = [];
$observedUnresolved = [];
$observedWithoutVisual = [];
$inventoryVisuals = $inventoryEntries ? itemVisuals($config, array_keys($inventoryEntries)) : [];
foreach ($inventoryEntries as $id => $instances) {
    if (isset($stockSet[$id])) {
        $observedBundled[$id] = $instances;
    } elseif (isset($overrides[$id])) {
        $observedOverride[$id] = $instances;
    } elseif (isset($realm['items'][$id])) {
        $observedRealmTemplate[$id] = $instances;
    } elseif (isset($resolvedInventory[$id])) {
        $observedDynamic[$id] = $instances;
    } else {
        $observedUnresolved[$id] = $instances;
    }
    if ((int) ($inventoryVisuals[$id]['display_id'] ?? 0) <= 0) {
        $observedWithoutVisual[$id] = $instances;
    }
}

$report = [
    'generated_at_utc' => gmdate('c'),
    'bundled_catalog' => [
        'item_count' => count($itemSet),
        'item_sparse_count' => count($sparseSet),
        'stock_template_count' => count($stockSet),
        'item_only_count' => count($itemOnly),
        'sparse_only_count' => count($sparseOnly),
        'item_ids' => auditSortedIds($itemSet),
        'item_sparse_ids' => auditSortedIds($sparseSet),
        'stock_template_ids' => auditSortedIds($stockSet),
        'item_only_ids' => auditSortedIds($itemOnly),
        'sparse_only_ids' => auditSortedIds($sparseOnly),
    ],
    'table_reference_integrity' => [
        'item_effect_row_count' => $itemEffectRows,
        'item_effect_parent_id_count' => count($itemEffectParentIds),
        'item_effect_parent_ids' => auditSortedIds($itemEffectParentIds),
        'effect_parent_outside_template_count' => count($effectParentsOutsideTemplates),
        'effect_parent_outside_template_ids' => auditSortedIds($effectParentsOutsideTemplates),
        'effect_parent_outside_item_count' => count($effectParentsOutsideItem),
        'effect_parent_outside_item_ids' => auditSortedIds($effectParentsOutsideItem),
        'opposite_faction_reference_count' => count($oppositeFactionIds),
        'missing_opposite_faction_template_count' => count($missingOppositeFaction),
        'missing_opposite_faction_template_ids' => auditSortedIds($missingOppositeFaction),
        'crafting_reagent_reference_count' => count($craftingReagentIds),
        'missing_crafting_reagent_template_count' => count($missingCraftingReagents),
        'missing_crafting_reagent_template_ids' => auditSortedIds($missingCraftingReagents),
    ],
    'appearance_graph' => [
        'modified_appearance_rows' => $imaRows,
        'referenced_item_id_count' => count($imaSourceIds),
        'referenced_item_ids' => auditSortedIds($imaSourceIds),
        'dangling_item_id_count' => count($appearanceOrphans),
        'dangling_item_ids' => auditSortedIds($appearanceOrphans),
        'dangling_item_only_ids' => auditSortedIds($orphanItemOnly),
        'dangling_sparse_only_ids' => auditSortedIds($orphanSparseOnly),
        'dangling_missing_both_ids' => auditSortedIds($orphanNeither),
        'missing_item_appearance_row_count' => count($imaMissingAppearances),
        'missing_item_appearance_ids' => auditSortedIds($imaMissingAppearances),
        'candidate_pair_count' => $candidatePairs,
        'display_count' => count($allDisplayIds),
        'shared_display_count' => $sharedDisplays,
        'ambiguous_display_count' => count($ambiguousDisplays),
        'ambiguous_display_ids' => auditSortedIds($ambiguousDisplays),
        'maximum_candidates_per_display' => $maxCandidates,
        'displays_over_former_12_candidate_cap' => $overFormerCap,
        'placeholder_candidate_count' => $placeholderCandidates,
    ],
    'equippable_visuals' => [
        'stock_equippable_count' => count($equippable),
        'without_display_count' => count($withoutDisplay),
        'without_display_ids' => auditSortedIds($withoutDisplay),
        'without_icon_count' => count($withoutIcon),
        'without_icon_ids' => auditSortedIds($withoutIcon),
        'missing_icon_name_count' => count($missingIconName),
        'missing_icon_name_ids' => auditSortedIds($missingIconName),
        'inventory_type_mismatch_count' => count($inventoryMismatches),
        'inventory_type_mismatch_ids' => auditSortedIds($inventoryMismatches),
    ],
    'realm_armory' => [
        'database_available' => $dbAvailable,
        'observed_unique_item_count' => count($inventoryEntries),
        'observed_instance_count' => array_sum($inventoryEntries),
        'bundled_ids' => auditSortedIds($observedBundled),
        'explicit_override_ids' => auditSortedIds($observedOverride),
        'realm_hotfix_template_ids' => auditSortedIds($observedRealmTemplate),
        'other_dynamic_metadata_ids' => auditSortedIds($observedDynamic),
        'unresolved_item_ids' => auditSortedIds($observedUnresolved),
        'without_native_visual_ids' => auditSortedIds($observedWithoutVisual),
    ],
    'explicit_override_ids' => auditSortedIds($overrides),
];

if ($jsonOutput) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

$line = str_repeat('=', 78);
echo "Complete item and appearance coverage audit\n{$line}\n";
echo sprintf(
    "Bundled Item: %s | ItemSparse: %s | authoritative intersection: %s\n",
    number_format(count($itemSet)), number_format(count($sparseSet)), number_format(count($stockSet))
);
echo "Item-only IDs (" . count($itemOnly) . '): ' . auditTextIds(auditSortedIds($itemOnly), $limit) . "\n";
echo "Sparse-only IDs (" . count($sparseOnly) . '): ' . auditTextIds(auditSortedIds($sparseOnly), $limit) . "\n";
echo number_format($itemEffectRows) . ' ItemEffect rows reference ' . number_format(count($itemEffectParentIds))
    . ' parent IDs; ' . number_format(count($effectParentsOutsideTemplates)) . ' lack a complete template ('
    . number_format(count($effectParentsOutsideItem)) . " also lack Item); effect references do not create templates.\n";
echo number_format(count($oppositeFactionIds)) . ' opposite-faction references and '
    . number_format(count($craftingReagentIds)) . ' crafting-reagent references; '
    . number_format(count($missingOppositeFaction) + count($missingCraftingReagents)) . " targets lack a complete template.\n";

echo "\nAppearance graph\n" . str_repeat('-', 78) . "\n";
echo number_format($imaRows) . ' modified-appearance rows reference ' . number_format(count($imaSourceIds)) . " item IDs.\n";
echo number_format(count($appearanceOrphans)) . " source IDs have no Item+ItemSparse template and are visual-only, not items to add.\n";
echo 'Dangling sample: ' . auditTextIds(auditSortedIds($appearanceOrphans), $limit) . "\n";
echo number_format(count($imaMissingAppearances)) . " rows reference a missing ItemAppearance row.\n";
echo number_format($candidatePairs) . ' valid item/display pairs across ' . number_format(count($allDisplayIds)) . " displays; "
    . number_format($sharedDisplays) . ' displays are shared and ' . number_format(count($ambiguousDisplays)) . " remain class/slot ambiguous.\n";
echo "Maximum candidates on one display: {$maxCandidates}; {$overFormerCap} displays exceed the former unsafe cap of 12.\n";
if ($showJunk) {
    echo number_format($placeholderCandidates) . " NPC/test placeholder candidates are excluded when an ordinary candidate exists.\n";
}

echo "\nEquippable stock visual coverage\n" . str_repeat('-', 78) . "\n";
echo number_format(count($equippable)) . ' templates; ' . number_format(count($withoutDisplay))
    . " have no appearance display (many are rings, bags, deprecated, or test entries).\n";
echo 'Without display sample: ' . auditTextIds(auditSortedIds($withoutDisplay), $limit) . "\n";
echo number_format(count($withoutIcon)) . ' have no icon; ' . number_format(count($missingIconName))
    . " nonzero icons lack a bundled CDN filename; " . number_format(count($inventoryMismatches)) . " Item/Sparse inventory types disagree.\n";

echo "\nLive realm armory coverage\n" . str_repeat('-', 78) . "\n";
if (!$dbAvailable) {
    echo "Database unavailable/skipped: bundled coverage is complete, but live inventory IDs were not audited.\n";
} else {
    echo number_format(count($inventoryEntries)) . ' unique IDs across ' . number_format(array_sum($inventoryEntries)) . " inventory-linked instances.\n";
    echo count($observedBundled) . ' bundled, ' . count($observedOverride) . ' explicit override, '
        . count($observedRealmTemplate) . ' realm hotfix template, ' . count($observedDynamic) . " other dynamically resolved.\n";
    echo 'Unresolved exact inventory IDs (' . count($observedUnresolved) . '): '
        . auditTextIds(auditSortedIds($observedUnresolved), $limit) . "\n";
    echo 'Inventory IDs without a native visual (' . count($observedWithoutVisual) . '): '
        . auditTextIds(auditSortedIds($observedWithoutVisual), $limit) . "\n";
}

echo "\nExplicit overrides: " . count($overrides) . "\n";
if ($suggest) {
    echo "\nSafe override guidance\n" . str_repeat('-', 78) . "\n";
    if (!$dbAvailable) {
        echo "No suggestions: connect the characters database first so item_instance.itemEntry can prove each custom ID.\n";
    } elseif (!$observedUnresolved) {
        echo "No unresolved inventory IDs require an override. Shared appearances are intentionally not auto-pinned.\n";
    } else {
        echo "These exact inventory IDs need authoritative realm/client metadata before an override can be written:\n  "
            . implode(', ', auditSortedIds($observedUnresolved)) . "\n";
        echo "Do not infer their names or stats from a same-look ItemModifiedAppearance row.\n";
    }
}
echo "\nUse --json for the complete compared ID lists. Done.\n";
