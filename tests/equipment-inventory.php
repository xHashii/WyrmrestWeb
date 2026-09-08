<?php
/**
 * MySQL/MariaDB integration tests; same ARMORY_TEST_DB_* settings as the search
 * suite. Reuses its connection-local fixtures and runs its normal checks first.
 * Run only against a dedicated, otherwise empty test database.
 */
require __DIR__ . '/armory-search.php';
$config['hotfixes_db_name'] = '';
$config['world_db_name'] = '';
$checks = 0;
$pdo->exec('ALTER TABLE characters ADD COLUMN equipmentCache LONGTEXT NULL');
$pdo->exec('CREATE TEMPORARY TABLE character_inventory (
    guid BIGINT NOT NULL, bag BIGINT NOT NULL DEFAULT 0, slot INT NOT NULL,
    item BIGINT PRIMARY KEY, UNIQUE (guid, bag, slot)
)');
$pdo->exec('CREATE TEMPORARY TABLE item_instance (
    guid BIGINT PRIMARY KEY, itemEntry INT NOT NULL, owner_guid BIGINT NOT NULL,
    count INT NOT NULL DEFAULT 1, durability INT NOT NULL DEFAULT 0
)');
function savedAppearanceCache(array $slots): string
{
    $values = array_fill(0, 170, 0);
    foreach ($slots as $slot => $fields) {
        array_splice($values, $slot * 5, 5, $fields);
    }
    return implode(' ', $values) . ' ';
}
$visuals = itemVisuals($config, [25, 35, 7937]);
$head = $visuals[7937]['display_id'];
$sword = $visuals[25]['display_id'];
$staff = $visuals[35]['display_id'];
$caches = [
    1 => savedAppearanceCache([15 => [21, $sword, 0, 7, 0]]),
    2 => savedAppearanceCache([0 => [1, $head, 0, 4, 0], 15 => [21, $sword, 0, 7, 0], 30 => [18, 999, 0, 0, 0]]),
    3 => savedAppearanceCache([0 => [1, $head, 0, 4, 0], 15 => [17, $staff, 0, 10, 0]]),
    4 => savedAppearanceCache([0 => [1, $head, 0, 4, 0], 2 => [3, 777, 0, 4, 0]]),
    6 => 'malformed equipment data',
];
$updateCache = $pdo->prepare('UPDATE characters SET equipmentCache = ? WHERE guid = ?');
foreach ($caches as $guid => $cache) {
    $updateCache->execute([$cache, $guid]);
}
$pdo->exec('INSERT INTO item_instance (guid, itemEntry, owner_guid, count, durability) VALUES
    (5000, 25, 1, 1, 20), (5001, 38, 1, 1, 0), (5100, 4496, 1, 1, 0),
    (5200, 117, 1, 4, 0), (5201, 118, 1, 2, 0), (5202, 36, 1, 1, 20),
    (5203, 37, 1, 1, 20), (5300, 35, 1, 1, 25), (5400, 4496, 1, 1, 0),
    (5401, 36, 1, 1, 20), (6000, 25, 3, 1, 20), (8000, 999999999, 99, 1, 0)');
$pdo->exec('INSERT INTO character_inventory (guid, bag, slot, item) VALUES
    (1, 0, 15, 5000), (1, 0, 3, 5001), (1, 0, 30, 5100),
    (1, 0, 35, 5200), (1, 0, 58, 5201), (1, 0, 59, 5202), (1, 0, 93, 5203),
    (1, 5100, 0, 5300), (1, 0, 87, 5400), (1, 5400, 0, 5401),
    (3, 0, 15, 6000), (4, 0, 0, 7400), (4, 0, 7, 7401), (5, 0, 15, 8000)');

$before = $pdo->query('SELECT * FROM character_inventory ORDER BY item')->fetchAll();
$gear = getCharacterInventory($config, 1);
checkSame([3, 15], array_keys($gear['equipped']), 'Equipped items are selected by position, not by template/instance ID confusion');
checkSame(25, $gear['equipped'][15]['entry'], 'The equipped template id is item_instance.itemEntry');
checkSame(5000, $gear['equipped'][15]['item_guid'], 'The instance id stays separate');
checkSame('Worn Shortsword', $gear['equipped'][15]['name'], 'Real item names resolve without hotfixes or world tables');
checkSame(135274, $gear['equipped'][15]['icon_file_data_id'], 'Real inventory items get an icon');
checkSame('inventory', $gear['equipped'][15]['equipment_source'], 'The cache never overrides a valid inventory record');
checkSame([35, 58], array_keys($gear['backpack']), 'Bank begins at 59 on this core; its contents must not leak into the backpack');
checkSame(1, count($gear['bags']), 'Bank bags are not equipped carried bags');
checkSame(35, $gear['bags'][0]['contents'][0]['entry'], 'Carried bag contents are linked by the bag INSTANCE guid');
checkSame(0, $gear['integrity']['cache_fallback'], 'Complete inventory does not need cached visuals');
checkSame($before, $pdo->query('SELECT * FROM character_inventory ORDER BY item')->fetchAll(), 'Armory reads never modify saved inventory');

$cacheOnly = getCharacterInventory($config, 2);
checkSame('empty', $cacheOnly['status']['inventory'], 'Empty inventory is distinguished from a failed query');
checkSame([0, 15], array_keys($cacheOnly['equipped']), 'A cache-only character still shows equipped appearances, not bags');
checkSame(0, $cacheOnly['equipped'][15]['entry'], 'Cached display IDs are never relabelled as item IDs');
checkSame('appearance-cache', $cacheOnly['equipped'][15]['source'], 'Cache source is explicit');
checkSame(2, $cacheOnly['integrity']['cache_fallback'], 'Diagnostics count fallback slots');
checkSame(-1, $cacheOnly['equipped'][0]['quality'], 'Cached rarity is not guessed from a similar item');
checkSame(null, averageItemLevel($cacheOnly['equipped']), 'Cache-only appearances have no fake average item level');

$mixed = getCharacterInventory($config, 3);
checkSame([15], array_keys($mixed['equipped']), 'Stale cache does not resurrect an unequipped head slot');
checkSame($sword, $mixed['equipped'][15]['display_id'], 'Actual inventory wins over a different cached appearance');
$broken = getCharacterInventory($config, 4);
checkSame([0, 7], array_keys($broken['equipped']), 'Broken instance links still occupy their real slots');
checkSame(2, $broken['integrity']['missing_instance'], 'Missing item records are diagnosed');
checkSame('equipment-cache', $broken['equipped'][0]['equipment_source'], 'Cache can recover an occupied slot whose instance is missing');
checkSame(7400, $broken['equipped'][0]['item_guid'], 'A fallback preserves a known broken instance link for diagnostics');
checkSame('unresolved', $broken['equipped'][7]['source'], 'A missing instance without a cache is shown as unknown, not empty');
$custom = getCharacterInventory($config, 5);
checkSame(999999999, $custom['equipped'][15]['entry'], 'Unknown custom templates remain visible');
checkSame(1, $custom['integrity']['owner_mismatch'], 'Owner mismatch is reported, not used to guess an equipment slot');
checkSame([], getCharacterInventory($config, 999999)['equipped'], 'Nonexistent characters cannot pick up other characters’ gear');
$invalid = getCharacterInventory($config, 6);
checkSame('invalid', $invalid['status']['cache'], 'Unsupported cache formats are diagnosed');
checkSame([], $invalid['equipped'], 'Malformed cache does not invent equipment');
checkSame(null, findCharacter($config, 11), 'GM profiles remain hidden before any equipment is rendered');
$trace = armoryTraceName($config, 'Freshmax');
checkSame(2, $trace['inventory']['integrity']['cache_fallback'], 'The name tracer explains cache-only gear');
checkSame(0, $trace['equipment'][0]['entry'], 'Diagnostics distinguish unknown item identity from display ID');
checkSame($head, $trace['equipment'][0]['display_id'], 'Diagnostics expose the real cached display ID');

$pdo->exec('ALTER TABLE characters DROP COLUMN equipmentCache');
$withoutCache = getCharacterInventory($config, 1);
checkSame(25, $withoutCache['equipped'][15]['entry'], 'Missing optional cache column cannot break normal gear');
checkSame('unavailable', $withoutCache['status']['cache'], 'Missing cache column is reported separately');
$pdo->exec('ALTER TABLE characters ADD COLUMN equipmentCache LONGTEXT NULL');
$updateCache = $pdo->prepare('UPDATE characters SET equipmentCache = ? WHERE guid = ?');
foreach ($caches as $guid => $cache) $updateCache->execute([$cache, $guid]);

$pdo->exec('DROP TABLE item_instance');
$withoutInstances = getCharacterInventory($config, 1);
checkSame('partial', $withoutInstances['status']['inventory'], 'Missing item_instance table retains readable inventory locations');
checkSame([3, 15], array_keys($withoutInstances['equipped']), 'Occupied slots are preserved during instance-query failure');
checkSame('equipment-cache', $withoutInstances['equipped'][15]['equipment_source'], 'Cache recovers a visual during instance-query failure');
$pdo->exec('DROP TABLE character_inventory');
$withoutInventory = getCharacterInventory($config, 2);
checkSame('unavailable', $withoutInventory['status']['inventory'], 'Missing inventory table is not misreported as empty');
checkSame([0, 15], array_keys($withoutInventory['equipped']), 'Cache still works when inventory cannot be queried at all');

$pdo->exec('ALTER TABLE characters DROP COLUMN equipmentCache');
$unavailable = getCharacterInventory($config, 1);
checkSame([], $unavailable['equipped'], 'No saved sources means unavailable equipment, never invented items');
checkSame(['inventory' => 'unavailable', 'cache' => 'unavailable'], $unavailable['status'], 'Both failed sources are visible to diagnostics');
echo "PASS: equipped inventory, cache fallbacks, and diagnostics ({$checks} checks)\n";
