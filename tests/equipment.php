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
equipmentCheck(2, averageItemLevel([15 => $appearance]), 'Resolved cache items contribute their real item level to the average');
equipmentCheck([[21, 1542], [22, 1542]], equipmentModelItems([15 => $appearance, 16 => $appearance, 1 => $appearance]), 'Viewer gets display IDs and proper weapon slots, not character slot indexes or jewelry');

// A look nothing in the export shares stays a visible slot rather than vanishing.
$unmatched = cachedAppearanceItem($config, 0, ['inventory_type' => 1, 'display_id' => 99999999, 'enchant_visual' => 0, 'subclass' => 4, 'secondary_appearance_id' => 0]);
equipmentCheck(0, $unmatched['entry'], 'An unknown appearance is not forced onto an unrelated item');
equipmentCheck('appearance-cache', $unmatched['source'], 'An unresolved appearance is still shown as a saved appearance');
equipmentCheck(0, resolveItemFromAppearance($config, 0), 'A zero display ID resolves to no item');

// Curated overrides name items the bundled client export omits (e.g. the
// heroic-25 ICC block this realm carries) and feed the appearance tables.
$overrideChest = resolveItems($config, [51625])[51625] ?? null;
equipmentCheck('Sanctified Lightsworn Chestguard', $overrideChest['name'] ?? null, 'Overrides provide names for custom realm items missing from the DB2 export');
equipmentCheck(4, $overrideChest['quality'] ?? null, 'Overrides carry the real item quality');
equipmentCheck(277, $overrideChest['item_level'] ?? null, 'Overrides carry the real item level');
equipmentCheck(63921, itemVisuals($config, [51625])[51625]['display_id'], 'Overridden items join the appearance tables');
equipmentCheck(340853, itemVisuals($config, [51625])[51625]['icon_file_data_id'], 'Overridden items get the realm icon');

// Shared looks resolve to this realm's item, not the generic same-model one.
equipmentCheck(51625, resolveItemFromAppearance($config, 63921, 4, 5, 2), 'A paladin chest look resolves to the curated heroic tier item');
equipmentCheck(50680, resolveItemFromAppearance($config, 63921, 4, 5, 1), 'A class that cannot wear the tier piece is not handed it');
equipmentCheck(54577, resolveItemFromAppearance($config, 64822, 3, 8), 'A feet look resolves to the curated phase-5 item, not the canonical one');
equipmentCheck(54577, resolveItemFromAppearance($config, 64822, 3, 8, 7, 179925), 'A saved secondary appearance (ItemModifiedAppearance id) resolves exactly');
$heroicChest = cachedAppearanceItem($config, 4, ['inventory_type' => 5, 'display_id' => 63921, 'enchant_visual' => 0, 'subclass' => 4, 'secondary_appearance_id' => 0], 2);
equipmentCheck(51625, $heroicChest['entry'], 'A cache-only chest slot shows the item actually equipped');
equipmentCheck('Sanctified Lightsworn Chestguard', $heroicChest['name'], 'A cache-only chest slot shows the real item name');
equipmentCheck(340853, $heroicChest['icon_file_data_id'], 'A cache-only chest slot keeps the right icon');
equipmentCheck(5, $heroicChest['lookalike_count'], 'Shared-look slots record how many items share the model');
$footfallsCache = cachedAppearanceItem($config, 7, ['inventory_type' => 8, 'display_id' => 64822, 'enchant_visual' => 0, 'subclass' => 3, 'secondary_appearance_id' => 0], 7);
equipmentCheck(54577, $footfallsCache['entry'], 'A cache-only feet slot shows the item actually equipped');
equipmentCheck('Returning Footfalls', $footfallsCache['name'], 'A cache-only feet slot shows the real item name');
equipmentCheck(284, $footfallsCache['item_level'], 'A cache-only feet slot shows the real item level');

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
