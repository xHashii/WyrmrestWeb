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
$pdo->exec('ALTER TABLE characters ADD COLUMN equipmentCache LONGTEXT NULL, ADD COLUMN activeTalentGroup TINYINT UNSIGNED NOT NULL DEFAULT 0');
$pdo->exec('CREATE TEMPORARY TABLE character_inventory (
    guid BIGINT NOT NULL, bag BIGINT NOT NULL DEFAULT 0, slot INT NOT NULL,
    item BIGINT PRIMARY KEY, UNIQUE (guid, bag, slot)
)');
$pdo->exec('CREATE TEMPORARY TABLE item_instance (
    guid BIGINT PRIMARY KEY, itemEntry INT NOT NULL, owner_guid BIGINT NOT NULL,
    count INT NOT NULL DEFAULT 1, durability INT NOT NULL DEFAULT 0
)');
$pdo->exec('CREATE TEMPORARY TABLE item_instance_transmog (
    itemGuid BIGINT PRIMARY KEY,
    itemModifiedAppearanceAllSpecs INT NOT NULL DEFAULT 0,
    itemModifiedAppearanceSpec1 INT NOT NULL DEFAULT 0,
    itemModifiedAppearanceSpec2 INT NOT NULL DEFAULT 0,
    itemModifiedAppearanceSpec3 INT NOT NULL DEFAULT 0,
    itemModifiedAppearanceSpec4 INT NOT NULL DEFAULT 0,
    itemModifiedAppearanceSpec5 INT NOT NULL DEFAULT 0,
    secondaryItemModifiedAppearanceAllSpecs INT NOT NULL DEFAULT 0,
    secondaryItemModifiedAppearanceSpec1 INT NOT NULL DEFAULT 0,
    secondaryItemModifiedAppearanceSpec2 INT NOT NULL DEFAULT 0,
    secondaryItemModifiedAppearanceSpec3 INT NOT NULL DEFAULT 0,
    secondaryItemModifiedAppearanceSpec4 INT NOT NULL DEFAULT 0,
    secondaryItemModifiedAppearanceSpec5 INT NOT NULL DEFAULT 0
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
    // Shared-look endgame gear: the realm's curated item must win over the
    // generic same-model item, filtered by the character's class.
    21 => savedAppearanceCache([4 => [5, 63921, 0, 4, 0]]),
    22 => savedAppearanceCache([7 => [8, 64822, 0, 3, 0]]),
    // Same shared feet look, later paired with an instance the character owns.
    23 => savedAppearanceCache([7 => [8, 64822, 0, 3, 0]]),
    // A shared cape look whose realm evidence lives on another character.
    24 => savedAppearanceCache([14 => [16, 191780, 0, 1, 0]]),
];
$pdo->exec("INSERT INTO characters (guid, account, name, class, level, online, deleteDate) VALUES
    (21, 100, 'Cachepal', 2, 80, 0, NULL), (22, 100, 'Cachesham', 7, 80, 0, NULL),
    (23, 100, 'Cachefoot', 7, 80, 0, NULL), (24, 100, 'Cachecape', 5, 80, 0, NULL)");
$updateCache = $pdo->prepare('UPDATE characters SET equipmentCache = ? WHERE guid = ?');
foreach ($caches as $guid => $cache) {
    $updateCache->execute([$cache, $guid]);
}
$pdo->exec('INSERT INTO item_instance (guid, itemEntry, owner_guid, count, durability) VALUES
    (5000, 25, 1, 1, 20), (5001, 38, 1, 1, 0), (5100, 4496, 1, 1, 0),
    (5200, 117, 1, 4, 0), (5201, 118, 1, 2, 0), (5202, 36, 1, 1, 20),
    (5203, 37, 1, 1, 20), (5300, 35, 1, 1, 25), (5400, 4496, 1, 1, 0),
    (5401, 36, 1, 1, 20), (6000, 25, 3, 1, 20), (8000, 999999999, 99, 1, 0),
    (9003, 186047, 999, 1, 0)');
// Character 1 uses spec 2. Its spec-specific primary/secondary appearances
// must beat the deliberately different all-spec values.
$pdo->exec('UPDATE characters SET activeTalentGroup = 1 WHERE guid = 1');
$pdo->exec('INSERT INTO item_instance_transmog (
    itemGuid, itemModifiedAppearanceAllSpecs, itemModifiedAppearanceSpec2,
    secondaryItemModifiedAppearanceAllSpecs, secondaryItemModifiedAppearanceSpec2
) VALUES (5000, 122206, 116883, 179507, 179925)');
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
checkSame('inventory-exact', $gear['equipped'][15]['identity_confidence'], 'item_instance supplies exact identity');
checkSame(25, $gear['equipped'][15]['entry'], 'Transmog never replaces the equipped item identity');
checkSame($staff, $gear['equipped'][15]['display_id'], 'The active-spec primary transmog controls the visible display');
checkSame($sword, $gear['equipped'][15]['native_display_id'], 'The native item visual remains available for diagnostics');
checkSame(116883, $gear['equipped'][15]['transmog_item_modified_appearance_id'], 'The selected primary appearance ID is exposed');
checkSame(64822, $gear['equipped'][15]['secondary_display_id'], 'The active-spec secondary appearance remains separate visual data');
checkSame([35, 58], array_keys($gear['backpack']), 'Bank begins at 59 on this core; its contents must not leak into the backpack');
checkSame(1, count($gear['bags']), 'Bank bags are not equipped carried bags');
checkSame(35, $gear['bags'][0]['contents'][0]['entry'], 'Carried bag contents are linked by the bag INSTANCE guid');
checkSame(0, $gear['integrity']['cache_fallback'], 'Complete inventory does not need cached visuals');
checkSame($before, $pdo->query('SELECT * FROM character_inventory ORDER BY item')->fetchAll(), 'Armory reads never modify saved inventory');

$cacheOnly = getCharacterInventory($config, 2);
checkSame('empty', $cacheOnly['status']['inventory'], 'Empty inventory is distinguished from a failed query');
checkSame([0, 15], array_keys($cacheOnly['equipped']), 'A cache-only character still shows equipped gear, not bags');
checkSame(25, $cacheOnly['equipped'][15]['entry'], 'A cache-only slot resolves its saved appearance back to the item');
checkSame('Worn Shortsword', $cacheOnly['equipped'][15]['name'], 'A cache-only slot shows the real item name');
checkSame('appearance-resolved', $cacheOnly['equipped'][15]['source'], 'Cache-resolved items are labelled as such');
checkSame('equipment-cache', $cacheOnly['equipped'][15]['equipment_source'], 'Cache-resolved items still record they came from the cache');
checkSame(2, $cacheOnly['integrity']['cache_fallback'], 'Diagnostics count fallback slots');
checkSame(2, $cacheOnly['equipped'][0]['quality'], 'A cache-only slot recovers the real item quality');
checkSame(26, averageItemLevel($cacheOnly['equipped']), 'Cache-resolved items contribute their real item level to the average');

$paladinCache = getCharacterInventory($config, 21, 2);
checkSame(0, $paladinCache['equipped'][4]['entry'], 'A shared cache-only paladin chest is not assigned a fabricated item');
checkSame(true, $paladinCache['equipped'][4]['identity_ambiguous'], 'Shared chest identity is explicitly ambiguous');
checkSame(4, $paladinCache['equipped'][4]['lookalike_count'], 'All class-compatible chest identities are reported');
checkSame(63921, $paladinCache['equipped'][4]['display_id'], 'The anonymous chest still renders its saved appearance');
$shamanCache = getCharacterInventory($config, 22, 7);
checkSame(0, $shamanCache['equipped'][7]['entry'], 'A shared cache-only feet look is not guessed as Returning Footfalls');
checkSame(true, $shamanCache['equipped'][7]['identity_ambiguous'], 'Shared feet identity is explicit');
checkSame(5, $shamanCache['equipped'][7]['lookalike_count'], 'All matching feet templates remain candidates');

// Real realm evidence recovers a shared look instead of leaving it anonymous:
// an item_instance this character still owns names its own lookalike...
$pdo->exec('INSERT INTO item_instance (guid, itemEntry, owner_guid, count, durability) VALUES
    (9002, 53127, 23, 1, 0)');
$feetOwned = getCharacterInventory($config, 23, 7);
checkSame(53127, $feetOwned['equipped'][7]['entry'], 'A shared look is named from an item_instance the character owns');
checkSame('Returning Footfalls', $feetOwned['equipped'][7]['name'], 'The recovered slot shows the real item name');
checkSame('appearance-character', $feetOwned['equipped'][7]['identity_confidence'], 'Character-owned recovery is labelled');
checkSame(false, $feetOwned['equipped'][7]['identity_ambiguous'], 'A recovered shared look is no longer ambiguous');
checkSame(5, $feetOwned['equipped'][7]['lookalike_count'], 'The full candidate set is still reported');
checkSame(1, $feetOwned['integrity']['cache_named'], 'Recovery is counted for diagnostics');
// ...and the only lookalike recorded anywhere on the realm wins its shared look.
$capeRealm = getCharacterInventory($config, 24, 5);
checkSame(186047, $capeRealm['equipped'][14]['entry'], 'The only lookalike recorded on the realm is named');
checkSame('Communal Cape', $capeRealm['equipped'][14]['name'], 'Realm-unique recovery shows the real name');
checkSame('appearance-realm-unique', $capeRealm['equipped'][14]['identity_confidence'], 'Realm-unique recovery is labelled');
checkSame(false, $capeRealm['equipped'][14]['identity_ambiguous'], 'A realm-identified look is not flagged ambiguous');
checkSame(1, $capeRealm['integrity']['cache_named'], 'Realm recovery is counted for diagnostics');

$mixed = getCharacterInventory($config, 3);
checkSame([15], array_keys($mixed['equipped']), 'Stale cache does not resurrect an unequipped head slot');
checkSame($sword, $mixed['equipped'][15]['display_id'], 'Actual inventory wins over a different cached appearance');
checkSame('inventory', $mixed['equipped'][15]['equipment_source'], 'A readable inventory record is never overridden by the cache');
$broken = getCharacterInventory($config, 4);
checkSame([0, 7], array_keys($broken['equipped']), 'Broken instance links still occupy their real slots');
checkSame(2, $broken['integrity']['missing_instance'], 'Missing item records are diagnosed');
checkSame('equipment-cache', $broken['equipped'][0]['equipment_source'], 'Cache can recover an occupied slot whose instance is missing');
checkSame(7937, $broken['equipped'][0]['entry'], 'A recovered broken slot resolves its saved appearance to the item');
checkSame(7400, $broken['equipped'][0]['item_guid'], 'A fallback preserves the known broken instance link');
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
checkSame(7937, $trace['equipment'][0]['entry'], 'Diagnostics show the item a saved appearance resolved to');
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
