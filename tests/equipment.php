<?php
/** Pure PHP/CSV regression tests. No game database or network access required. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/model-assets.php';

$work = sys_get_temp_dir() . '/wyrmrest-equipment-' . bin2hex(random_bytes(6));
mkdir($work, 0700, true);
register_shutdown_function(static function () use ($work) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($work);
});
$config['db_host'] = '';
$config['cache_dir'] = $work . '/cache';
$checks = 0;
function equipmentCheck($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
    $GLOBALS['checks']++;
}
function fixtureEquipmentCache(array $slots): string
{
    $values = array_fill(0, 170, 0);
    foreach ($slots as $slot => $fields) {
        array_splice($values, $slot * 5, 5, $fields);
    }
    return implode(' ', $values) . ' ';
}

equipmentCheck([], parseEquipmentCache(''), 'Missing cache is empty, not an invented item');
equipmentCheck([], parseEquipmentCache(fixtureEquipmentCache([])), 'All-empty cache is supported');
$cache = fixtureEquipmentCache([0 => [1, 12345, 7, 4, 10], 15 => [21, 1542, 8, 7, 11], 30 => [18, 9999, 0, 0, 0]]);
$parsed = parseEquipmentCache("\n" . $cache . "\t");
equipmentCheck([0, 15], array_keys($parsed), 'Only equipped slots are parsed; bags are not gear');
equipmentCheck(12345, $parsed[0]['display_id'], 'Display ID is the second field of the five-field record');
equipmentCheck(7, $parsed[0]['enchant_visual'], 'Enchant visual is not an item ID');
equipmentCheck(4, $parsed[0]['subclass'], 'Subclass field stays distinct');
equipmentCheck(10, $parsed[0]['secondary_appearance_id'], 'Secondary appearance stays distinct');
equipmentCheck(1542, $parsed[15]['display_id'], 'Slot offsets use stride five');
foreach (['0', implode(' ', array_fill(0, 46, 0)), substr($cache, 0, -4), str_replace('12345', '-1', $cache),
    str_replace('12345', '4294967296', $cache), str_replace('12345', '25x', $cache), str_repeat('0 ', 5000)] as $invalid) {
    $rejected = false;
    try { parseEquipmentCache($invalid); } catch (UnexpectedValueException $e) { $rejected = true; }
    equipmentCheck(true, $rejected, 'Malformed and unsupported cache formats must be rejected, not guessed');
}

$layout = paperdollSlots();
equipmentCheck(8, count($layout['left']), 'Eight left slots');
equipmentCheck(8, count($layout['right']), 'Eight right slots');
equipmentCheck([15, 16, 17], $layout['weapons'], 'Three weapon slots are centered below');
$allSlots = array_merge(...array_values($layout));
sort($allSlots);
equipmentCheck(range(0, 18), $allSlots, 'Every equipment slot appears exactly once');

$visuals = itemVisuals($config, [25, 38, 39, 999999999]);
equipmentCheck(1542, $visuals[25]['display_id'], 'Item -> modified appearance -> appearance -> display ID');
equipmentCheck(135274, $visuals[25]['icon_file_data_id'], 'Item icons use FileDataIDs from the actual client export');
equipmentCheck(false, isset($visuals[999999999]), 'Unknown entries are not mapped to random visuals');
$item = itemDb2Lookup($config, [25])[25];
equipmentCheck('Worn Shortsword', $item['name'], 'The bundled item-name index still resolves real items');
equipmentCheck(1, $item['quality'], 'Item quality survives the index round trip');
equipmentCheck('https://wow.zamimg.com/images/wow/icons/large/inv_chest_samurai.jpg', itemIconUrl($config, 132759), 'CDN icons use real filenames, never numeric item/display IDs');
equipmentCheck(null, itemIconUrl(array_replace($config, ['remote_item_icons' => false]), 132759), 'Remote icons can be disabled');
equipmentCheck('images/items/135274.png', itemIconUrl(array_replace($config, ['remote_item_icons' => false]), 135274), 'Bundled local icons work even when remote icons are disabled');
equipmentCheck(null, itemIconUrl($config, 0), 'Unknown icons use the local slot outline');
// A saved appearance (display 1542, subclass 7, inventory type 21) is walked
// back through the client appearance graph to the item that produced it.
$appearance = cachedAppearanceItem($config, 15, $parsed[15]);
equipmentCheck(25, $appearance['entry'], 'A saved appearance resolves back to its item via the DB2 appearance graph');
equipmentCheck('Worn Shortsword', $appearance['name'], 'A resolved appearance shows the real item name, not a placeholder');
equipmentCheck(1, $appearance['quality'], 'A resolved appearance carries the real item quality');
equipmentCheck(2, $appearance['item_level'], 'A resolved appearance carries the real item level');
equipmentCheck('appearance-resolved', $appearance['source'], 'Items recovered from the appearance cache are labelled as such');
equipmentCheck('equipment-cache', $appearance['equipment_source'], 'The recovered item still records that it came from the equipment cache');
equipmentCheck(135274, $appearance['icon_file_data_id'], 'A resolved appearance keeps the correct item icon');
equipmentCheck(1542, $appearance['display_id'], 'The character\'s saved display ID is preserved');

// Exact inventory identity and primary/secondary transmog visuals stay in
// separate namespaces. The selected spec aliases beat all-spec fallbacks.
$exact25 = resolveItems($config, [25]);
$exactVisual25 = itemVisuals($config, [25]);
$transmogged = decorateArmoryInventoryRow($config, [
    'itemEntry' => 25, 'slot' => 15, 'bag' => 0, 'item_guid' => 5000,
    'count' => 1, 'durability' => 20,
    'primary_appearance_spec' => 116883, 'primary_appearance_all' => 122206,
    'secondary_appearance_spec' => 179925, 'secondary_appearance_all' => 179507,
], $exact25, $exactVisual25);
equipmentCheck(25, $transmogged['entry'], 'Primary transmog cannot replace exact itemEntry identity');
equipmentCheck('Worn Shortsword', $transmogged['name'], 'Transmog cannot replace exact item metadata');
equipmentCheck(472, $transmogged['display_id'], 'Selected active-spec primary appearance controls the visible display');
equipmentCheck(1542, $transmogged['native_display_id'], 'Native display remains available separately');
equipmentCheck(116883, $transmogged['transmog_item_modified_appearance_id'], 'Selected primary appearance ID is retained');
equipmentCheck(64822, $transmogged['secondary_display_id'], 'Selected secondary appearance remains separate visual data');
equipmentCheck('inventory-exact', $transmogged['identity_confidence'], 'Inventory rows explicitly mark exact identity');
$customTransmog = decorateArmoryInventoryRow($config, [
    'itemEntry' => 999999999, 'slot' => 4, 'bag' => 0, 'item_guid' => 6000,
    'count' => 1, 'durability' => 0, 'primary_appearance_spec' => 179507,
], [], []);
equipmentCheck(999999999, $customTransmog['entry'], 'Unknown custom inventory IDs retain their exact identity');
equipmentCheck(63914, $customTransmog['display_id'], 'A dangling appearance can render an exact custom inventory item');
equipmentCheck('Unknown item #999999999', $customTransmog['name'], 'Visual-only data never lends the dangling source item name');
equipmentCheck(2, averageItemLevel([15 => $appearance]), 'Resolved cache items contribute their real item level to the average');
equipmentCheck([[21, 1542], [22, 1542]], equipmentModelItems([15 => $appearance, 16 => $appearance, 1 => $appearance]), 'Viewer gets display IDs and proper weapon slots, not character slot indexes or jewelry');

// A look nothing in the export shares stays a visible slot rather than vanishing.
$unmatched = cachedAppearanceItem($config, 0, ['inventory_type' => 1, 'display_id' => 99999999, 'enchant_visual' => 0, 'subclass' => 4, 'secondary_appearance_id' => 0]);
equipmentCheck(0, $unmatched['entry'], 'An unknown appearance is not forced onto an unrelated item');
equipmentCheck('appearance-cache', $unmatched['source'], 'An unresolved appearance is still shown as a saved appearance');
equipmentCheck(0, resolveItemFromAppearance($config, 0), 'A zero display ID resolves to no item');

// Dangling ItemModifiedAppearance references are visual data, not item records.
$tables = itemVisualTables($config);
equipmentCheck(45070, count($tables['templates']), 'Only the complete Item/ItemSparse intersection becomes stock templates');
equipmentCheck(1050, count($tables['orphans']), 'Every dangling appearance-linked item ID is accounted for');
equipmentCheck(26961, count($tables['ima_visuals']), 'Every modified appearance remains available as visual data');
equipmentCheck(25911, array_sum(array_map('count', $tables['resolve'])), 'The full valid item/display graph is retained');
equipmentCheck(113, max(array_map('count', $tables['resolve'])), 'The largest shared display keeps all 113 candidates');
equipmentCheck(false, isset(itemOverrides($config)[51625]), 'The default catalog has no fabricated 51625 override');
equipmentCheck(false, isset($tables['templates'][51625]), 'An ID missing Item and ItemSparse is not a stock template');
equipmentCheck(true, in_array('51625', $tables['orphans'], true), 'Dangling appearance-linked IDs are reported as orphans');
equipmentCheck(false, isset(itemVisuals($config, [51625])[51625]), 'A dangling graph row is not promoted into an item visual');
equipmentCheck(null, resolveItems($config, [51625])[51625] ?? null, 'A nonexistent item is not given another item\'s metadata');
$orphanVisual = itemModifiedAppearanceVisual($config, 179507);
equipmentCheck(63914, $orphanVisual['display_id'] ?? 0, 'A dangling modified appearance remains independently renderable');
equipmentCheck(340855, $orphanVisual['icon_file_data_id'] ?? 0, 'Visual-only appearances retain their own icon');

// A display shared by several valid templates is never guessed. Saved type,
// subclass and class may prove uniqueness, but ranking or popularity may not.
equipmentCheck(4, itemAppearanceCandidateCount($config, 63921, 4, 5, 2), 'All valid paladin chest candidates remain visible to ambiguity checks');
equipmentCheck(0, resolveItemFromAppearance($config, 63921, 4, 5, 2), 'A shared paladin chest appearance remains anonymous');
equipmentCheck(50680, resolveItemFromAppearance($config, 63921, 4, 5, 1), 'Identity is allowed when class filtering leaves exactly one template');
equipmentCheck(5, itemAppearanceCandidateCount($config, 64822, 3, 8), 'Every valid item sharing the feet display is counted');
equipmentCheck(0, resolveItemFromAppearance($config, 64822, 3, 8), 'Shared feet are not assigned the first ranked item');
equipmentCheck(0, resolveItemFromAppearance($config, 64822, 3, 8, 7, 179925), 'The cache secondary appearance cannot identify the primary item');
$heroicChest = cachedAppearanceItem($config, 4, ['inventory_type' => 5, 'display_id' => 63921, 'enchant_visual' => 0, 'subclass' => 4, 'secondary_appearance_id' => 0], 2);
equipmentCheck(0, $heroicChest['entry'], 'An ambiguous cache-only chest has no invented item ID');
equipmentCheck(true, $heroicChest['identity_ambiguous'], 'Ambiguous identity is explicit in armory data');
equipmentCheck('appearance-ambiguous', $heroicChest['source'], 'Ambiguous appearances have a diagnostic source');
equipmentCheck(4, $heroicChest['lookalike_count'], 'The filtered ambiguity count is exposed');
equipmentCheck(63921, $heroicChest['display_id'], 'An anonymous cache slot still keeps its exact saved visual');
$item = $heroicChest;
$slot = 4;
ob_start();
include __DIR__ . '/../includes/equipment-slot.php';
$anonymousSlotHtml = (string) ob_get_clean();
equipmentCheck(true, str_contains($anonymousSlotHtml, 'shared by 4 items'), 'The paper doll explains ambiguous identity to visitors');
equipmentCheck(false, str_contains($anonymousSlotHtml, 'wowhead.com/wotlk/item='), 'An anonymous look never links to another item');
equipmentCheck(null, averageItemLevel([4 => $heroicChest]), 'Anonymous looks cannot inflate average item level');
$footfallsCache = cachedAppearanceItem($config, 7, ['inventory_type' => 8, 'display_id' => 64822, 'enchant_visual' => 0, 'subclass' => 3, 'secondary_appearance_id' => 179925], 7);
equipmentCheck(0, $footfallsCache['entry'], 'A secondary appearance does not force Returning Footfalls identity');
equipmentCheck(5, $footfallsCache['lookalike_count'], 'Shared feet expose every plausible identity');
$realFootfalls = resolveItems($config, [54577])[54577] ?? null;
equipmentCheck('Returning Footfalls', $realFootfalls['name'] ?? null, 'Returning Footfalls already resolves from authoritative bundled data');
equipmentCheck(284, $realFootfalls['item_level'] ?? null, 'Bundled metadata, not an override, supplies its item level');

// A tiny alternate export tests default-appearance selection and read-only cache fallback.
$fixtureDir = $work . '/db2';
mkdir($fixtureDir);
file_put_contents($fixtureDir . '/Item.test.csv', "ID,IconFileDataID,InventoryType\n7,0,5\n");
file_put_contents($fixtureDir . '/ItemAppearance.test.csv', "ID,ItemDisplayInfoID,DefaultIconFileDataID\n10,800,135274\n11,801,132759\n12,802,134582\n");
file_put_contents($fixtureDir . '/ItemModifiedAppearance.test.csv', "ItemID,ItemAppearanceModifierID,ItemAppearanceID,OrderIndex\n7,1,10,0\n7,0,11,1\n7,0,12,2\n");
file_put_contents($work . '/not-a-directory', 'x');
$alternate = array_replace($config, ['db2_dir' => $fixtureDir, 'cache_dir' => $work . '/not-a-directory']);
$fixtureVisual = itemVisuals($alternate, [7])[7];
equipmentCheck(801, $fixtureVisual['display_id'], 'Default appearance beats a variant, then lowest order wins');
equipmentCheck(132759, $fixtureVisual['icon_file_data_id'], 'Appearance fallback icon follows the chosen appearance');

// Dangling graph references remain visual-only until a real template or an
// explicit operator assertion exists.
$recoveryDir = $work . '/recovery-db2';
mkdir($recoveryDir);
file_put_contents($recoveryDir . '/Item.test.csv', "ID,ClassID,SubclassID,Material,InventoryType,RequiredLevel,IconFileDataID\n70,2,4,6,5,80,100\n71,2,4,6,5,80,101\n73,2,4,6,5,80,103\n");
file_put_contents($recoveryDir . '/ItemAppearance.test.csv', "ID,DisplayType,ItemDisplayInfoID,DefaultIconFileDataID,UiOrder\n10,3,900,111,0\n");
file_put_contents($recoveryDir . '/ItemModifiedAppearance.test.csv', "ID,ItemID,ItemAppearanceModifierID,ItemAppearanceID,OrderIndex,TransmogSourceTypeEnum\n500,70,0,10,0,0\n501,71,0,10,0,0\n502,72,0,10,0,0\n503,73,0,10,0,0\n");
file_put_contents($recoveryDir . '/ItemSparse.test.csv', "ID,AllowableClass,ItemLevel,Display_lang,OverallQualityID\n70,2,277,Monster - Chest,0\n71,2,277,Realm Chestplate,4\n");
$recovery = array_replace($config, ['db2_dir' => $recoveryDir, 'cache_dir' => $work . '/recovery-cache']);
$recoveryTables = itemVisualTables($recovery);
equipmentCheck(false, isset(itemVisuals($recovery, [72])[72]), 'A graph-only ID is not synthesized into an item');
equipmentCheck(true, in_array('72', $recoveryTables['orphans'], true), 'An ID missing both template tables is reported');
equipmentCheck(true, in_array('73', $recoveryTables['orphans'], true), 'An Item row without ItemSparse is also non-authoritative');
equipmentCheck(false, isset($recoveryTables['templates'][73]), 'Both Item and ItemSparse are required for a template');
equipmentCheck(900, itemVisuals($recovery, [73])[73]['display_id'], 'An Item-only ID can retain visual data for exact inventory display');
equipmentCheck(900, itemModifiedAppearanceVisual($recovery, 502)['display_id'], 'A graph-only modified appearance can still render');
// The placeholder is ignored only because one ordinary valid template remains.
equipmentCheck(71, resolveItemFromAppearance($recovery, 900, 4, 5, 2), 'One non-placeholder template can be resolved exactly');
equipmentCheck(71, resolveItemFromAppearance($recovery, 900, 4, 5, 2, 500), 'A secondary appearance is ignored even when it points at another source');
equipmentCheck(0, resolveItemFromAppearance($recovery, 900, 0, 7, 2), 'Wrong saved type/subclass is not broadened into a guess');

// Operators can assert a custom realm item only through the explicit catalog.
$assertionPath = $work . '/asserted-overrides.json';
file_put_contents($assertionPath, json_encode(['schema' => 1, 'items' => ['72' => [
    'name' => 'Verified Realm Chest', 'quality' => 4, 'item_level' => 300,
    'inventory_type' => 5, 'subclass' => 4, 'display_id' => 900,
    'icon_file_data_id' => 111, 'allowable_class' => 2,
]]], JSON_PRETTY_PRINT));
$asserted = array_replace($recovery, ['item_overrides_path' => $assertionPath, 'cache_dir' => $work . '/asserted-cache']);
equipmentCheck('Verified Realm Chest', resolveItems($asserted, [72])[72]['name'] ?? null, 'An explicit verified override creates custom item metadata');
equipmentCheck(900, itemVisuals($asserted, [72])[72]['display_id'], 'An explicit override creates its visual mapping');
equipmentCheck(72, resolveItemFromAppearance($asserted, 900, 4, 5, 2), 'One explicit assertion can pin a shared appearance identity');

// Candidate lists must never be truncated before deciding uniqueness.
$manyDir = $work . '/many-db2';
mkdir($manyDir);
$itemCsv = "ID,SubclassID,InventoryType,IconFileDataID\n";
$sparseCsv = "ID,AllowableClass,ItemLevel,Display_lang,OverallQualityID\n";
$imaCsv = "ID,ItemID,ItemAppearanceModifierID,ItemAppearanceID,OrderIndex\n";
for ($id = 100; $id < 115; $id++) {
    $itemCsv .= "{$id},4,5,111\n";
    $sparseCsv .= "{$id},0,200,Shared Chest {$id},4\n";
    $imaCsv .= (1000 + $id) . ",{$id},0,20,0\n";
}
file_put_contents($manyDir . '/Item.test.csv', $itemCsv);
file_put_contents($manyDir . '/ItemSparse.test.csv', $sparseCsv);
file_put_contents($manyDir . '/ItemAppearance.test.csv', "ID,ItemDisplayInfoID,DefaultIconFileDataID\n20,901,111\n");
file_put_contents($manyDir . '/ItemModifiedAppearance.test.csv', $imaCsv);
$many = array_replace($config, ['db2_dir' => $manyDir, 'cache_dir' => $work . '/many-cache']);
equipmentCheck(15, itemAppearanceCandidateCount($many, 901, 4, 5), 'Every candidate survives beyond the former twelve-item cap');
equipmentCheck(0, resolveItemFromAppearance($many, 901, 4, 5), 'Fifteen shared templates remain ambiguous');

foreach (['meta/character/7.json', 'meta/armor/1/12345.json', 'models/character/7.mo3', 'mo3/character/human/male/humanmale.mo3', 'textures/135274.webp'] as $path) {
    equipmentCheck(true, validModelAssetPath($path), 'Expected model asset paths are allowed');
}
foreach (['https://example.com/x.json', '//example.com/a.json', 'meta/../config.php', 'meta/%2e%2e/a.json',
    'meta/character/7.json?x=1', 'meta/character/7.js', 'file:///etc/passwd', "meta/a\0.json", str_repeat('a', 250) . '.json'] as $path) {
    equipmentCheck(false, validModelAssetPath($path), 'Model endpoint rejects traversal, scripts, URLs and oversized paths');
}
equipmentCheck('application/json', modelAssetMime('meta/character/7.json'), 'Metadata MIME is JSON');
equipmentCheck('application/octet-stream', modelAssetMime('models/character/7.mo3'), 'Model MIME is non-executable binary');
$assetDir = $work . '/models';
$assetFile = $assetDir . '/test.asset';
cacheModelAsset($assetDir, $assetFile, '{"test":true}');
equipmentCheck('{"test":true}', file_get_contents($assetFile), 'Model assets are cached atomically');

echo "PASS: equipment metadata, cache parser, layout, and asset safety ({$checks} checks)\n";
