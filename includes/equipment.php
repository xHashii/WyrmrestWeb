<?php
/**
 * Player::SaveToDB in xHashii/WyrmrestCore writes 34 equipment/bag slots,
 * FIVE integers per slot: inventory type, DISPLAY id, enchant visual,
 * subclass, secondary modified appearance. These are NOT item template IDs —
 * but the display id, subclass and inventory type together are enough to walk
 * the client's appearance graph back to the item (see resolveItemFromAppearance
 * in item-visuals.php), which is what lets a character whose inventory tables
 * are unreadable still show real, named equipment.
 * Parse only the 19 equipped slots.
 */
function parseEquipmentCache(string $cache): array
{
    $cache = trim($cache);
    if ($cache === '') {
        return [];
    }
    if (strlen($cache) > 8192) {
        throw new UnexpectedValueException('Equipment cache is too large for the 3.4.3 format.');
    }
    $values = preg_split('/\s+/', $cache);
    if (count($values) !== 34 * 5) {
        throw new UnexpectedValueException('Unsupported equipment cache format: expected 170 values (34 slots × 5 fields), got ' . count($values) . '.');
    }
    foreach ($values as $value) {
        if (!ctype_digit($value) || strlen($value) > 10 || (float) $value > 4294967295) {
            throw new UnexpectedValueException('Equipment cache contains an invalid unsigned integer.');
        }
    }
    $slots = [];
    for ($slot = 0; $slot <= 18; $slot++) {
        $offset = $slot * 5;
        $type = (int) $values[$offset];
        $display = (int) $values[$offset + 1];
        if ($type > 30) {
            throw new UnexpectedValueException('Equipment cache contains an invalid inventory type.');
        }
        if ($type === 0 && $display === 0) {
            continue;
        }
        $slots[$slot] = [
            'inventory_type' => $type,
            'display_id' => $display,
            'enchant_visual' => (int) $values[$offset + 2],
            'subclass' => (int) $values[$offset + 3],
            'secondary_appearance_id' => (int) $values[$offset + 4],
        ];
    }
    return $slots;
}

/** The optional cache has its own query so a missing column cannot hide gear. */
function readCharacterEquipmentCache(array $config, int $guid): array
{
    $result = ['status' => 'unavailable', 'slots' => []];
    $pdo = connectCharactersDb($config);
    if (!$pdo || $guid <= 0) {
        return $result;
    }
    try {
        $stmt = $pdo->prepare('SELECT equipmentCache FROM characters WHERE guid = :guid');
        $stmt->execute(['guid' => $guid]);
        $raw = $stmt->fetchColumn();
        if ($raw === false) {
            $result['status'] = 'not-found';
            return $result;
        }
        $result['slots'] = parseEquipmentCache((string) $raw);
        $result['status'] = $result['slots'] ? 'available' : 'empty';
    } catch (UnexpectedValueException $e) {
        $result['status'] = 'invalid';
        dbNoteError('parse character equipment cache', $e);
    } catch (Throwable $e) {
        dbNoteError('read character equipment cache', $e);
    }
    return $result;
}

function unknownArmoryItem(int $entry): array
{
    return [
        'entry' => $entry,
        'name' => $entry > 0 ? "Unknown item #{$entry}" : 'Item details unavailable',
        'quality' => -1,
        'inventory_type' => 0,
        'item_level' => 0,
        'required_level' => 0,
        'source' => 'unresolved',
        'identity_confidence' => 'unavailable',
        'identity_ambiguous' => false,
        'lookalike_count' => 0,
        'display_id' => 0,
        'icon_file_data_id' => 0,
    ];
}

/**
 * Turn one parsed equipmentCache slot into a displayable item.
 *
 * The cache stores a visible display plus type/subclass, never itemEntry. Its
 * fifth value is a secondary transmog appearance and does not identify the
 * primary item. We therefore attach item metadata only when the filtered
 * appearance graph has one valid template (or one explicit realm override).
 * Shared appearances remain visually correct but intentionally unnamed rather
 * than borrowing another item's identity, stats, item level or Wowhead link.
 */
function cachedAppearanceItem(array $config, int $slot, array $appearance, ?int $characterClass = null): array
{
    $tables = itemVisualTables($config);
    $displayId = (int) ($appearance['display_id'] ?? 0);
    $subclass = (int) ($appearance['subclass'] ?? -1);
    $inventoryType = (int) ($appearance['inventory_type'] ?? -1);
    $secondaryAppearanceId = (int) ($appearance['secondary_appearance_id'] ?? 0);

    $entry = resolveItemFromAppearance($config, $displayId, $subclass, $inventoryType, $characterClass, $secondaryAppearanceId);
    $realm = realmAppearanceLayer($config);
    $iconFromDisplay = (int) ($realm['displays'][$displayId] ?? $tables['displays'][$displayId] ?? 0);
    $lookalikeCount = itemAppearanceCandidateCount($config, $displayId, $subclass, $inventoryType, $characterClass);

    $base = array_replace(unknownArmoryItem(0), $appearance, [
        'name' => equipSlotLabel($slot) . ' (saved appearance)',
        'source' => $lookalikeCount > 1 ? 'appearance-ambiguous' : 'appearance-cache',
        'equipment_source' => 'equipment-cache',
        'identity_confidence' => 'appearance-only',
        'identity_ambiguous' => $lookalikeCount > 1,
        'icon_file_data_id' => $iconFromDisplay,
        'slot' => $slot,
        'bag' => 0,
        'item_guid' => null,
        'count' => 1,
        'durability' => null,
        'lookalike_count' => $lookalikeCount,
    ]);

    if ($entry > 0) {
        $resolved = resolveItems($config, [$entry])[$entry] ?? null;
        $hasMetadata = $resolved !== null;
        $resolved ??= unknownArmoryItem($entry);
        $visual = itemVisuals($config, [$entry])[$entry] ?? [];
        $base = array_replace($base, $resolved, [
            // Keep the character's actual saved appearance/enchant fields.
            'entry' => $entry,
            'display_id' => $displayId ?: (int) ($visual['display_id'] ?? 0),
            'icon_file_data_id' => (int) ($visual['icon_file_data_id'] ?? 0) ?: $iconFromDisplay,
            'source' => $hasMetadata ? 'appearance-resolved' : 'appearance-identity',
            'equipment_source' => 'equipment-cache',
            'identity_confidence' => 'appearance-unique',
            'identity_ambiguous' => false,
            'enchant_visual' => $appearance['enchant_visual'] ?? 0,
            'secondary_appearance_id' => $appearance['secondary_appearance_id'] ?? 0,
            'slot' => $slot,
            'bag' => 0,
            'item_guid' => null,
            'count' => 1,
            'durability' => null,
        ]);
        if (empty($base['inventory_type'])) {
            $base['inventory_type'] = $inventoryType > 0 ? $inventoryType : (int) ($visual['inventory_type'] ?? 0);
        }
    }

    return $base;
}

/**
 * Decorate one exact character_inventory/item_instance row. itemEntry owns
 * identity; primary/secondary modified appearances can change visuals only.
 * Arrays are pre-resolved by the caller to keep bulk inventory loads cheap.
 */
function decorateArmoryInventoryRow(array $config, array $row, array $items, array $visuals): array
{
    $entry = (int) ($row['itemEntry'] ?? 0);
    $item = $items[$entry] ?? unknownArmoryItem($entry);
    $visual = $visuals[$entry] ?? [];
    $item['display_id'] = (int) ($visual['display_id'] ?? 0);
    $item['icon_file_data_id'] = (int) ($visual['icon_file_data_id'] ?? 0);
    if (empty($item['inventory_type'])) {
        $item['inventory_type'] = (int) ($visual['inventory_type'] ?? 0);
    }

    $primaryAppearance = (int) ($row['primary_appearance_spec'] ?? 0);
    if ($primaryAppearance <= 0) {
        $primaryAppearance = (int) ($row['primary_appearance_all'] ?? 0);
    }
    if ($primaryAppearance > 0) {
        $item['native_display_id'] = $item['display_id'];
        $item['native_icon_file_data_id'] = $item['icon_file_data_id'];
        $item['transmog_item_modified_appearance_id'] = $primaryAppearance;
        $transmogVisual = itemModifiedAppearanceVisual($config, $primaryAppearance);
        if ($transmogVisual !== null) {
            $item['display_id'] = $transmogVisual['display_id'];
            if ($transmogVisual['icon_file_data_id'] > 0) {
                $item['icon_file_data_id'] = $transmogVisual['icon_file_data_id'];
            }
            $item['transmogrified'] = true;
            $item['visual_source'] = 'primary-transmog';
        } else {
            $item['transmog_visual_unavailable'] = true;
        }
    }

    $secondaryAppearance = (int) ($row['secondary_appearance_spec'] ?? 0);
    if ($secondaryAppearance <= 0) {
        $secondaryAppearance = (int) ($row['secondary_appearance_all'] ?? 0);
    }
    if ($secondaryAppearance > 0) {
        $item['secondary_item_modified_appearance_id'] = $secondaryAppearance;
        $secondaryVisual = itemModifiedAppearanceVisual($config, $secondaryAppearance);
        if ($secondaryVisual !== null) {
            $item['secondary_display_id'] = $secondaryVisual['display_id'];
            $item['secondary_icon_file_data_id'] = $secondaryVisual['icon_file_data_id'];
        }
    }

    $item['slot'] = (int) ($row['slot'] ?? 0);
    $item['bag'] = (int) ($row['bag'] ?? 0);
    $item['item_guid'] = (int) ($row['item_guid'] ?? 0);
    $item['count'] = max(1, (int) ($row['count'] ?? 1));
    $item['durability'] = ($row['durability'] ?? null) !== null ? (int) $row['durability'] : null;
    $item['equipment_source'] = 'inventory';
    // item_instance.itemEntry is authoritative even when descriptive metadata
    // for a custom entry is unavailable.
    $item['identity_confidence'] = $entry > 0 ? 'inventory-exact' : 'unavailable';
    $item['identity_ambiguous'] = false;
    $item['lookalike_count'] = 0;
    return $item;
}

/** Paper-doll positions, matching the in-game character screen. */
function paperdollSlots(): array
{
    return [
        'left' => [0, 1, 2, 14, 4, 3, 18, 8],
        'right' => [9, 5, 6, 7, 10, 11, 12, 13],
        'weapons' => [15, 16, 17],
    ];
}

function equipmentSlotIcon(int $slot): string
{
    $icons = ['head', 'neck', 'shoulders', 'shirt', 'chest', 'waist', 'legs', 'feet', 'wrists',
        'hands', 'finger', 'finger', 'trinket', 'trinket', 'back', 'mainhand', 'offhand', 'ranged', 'tabard'];
    return $icons[$slot] ?? 'trinket';
}

/** Model-viewer slots are inventory TYPES, not character_inventory.slot. */
function equipmentModelItems(array $equipment): array
{
    $slots = [0 => 1, 2 => 3, 3 => 4, 4 => 5, 5 => 6, 6 => 7, 7 => 8, 8 => 9,
        9 => 10, 14 => 16, 15 => 21, 16 => 22, 17 => 26, 18 => 19];
    $items = [];
    foreach ($equipment as $slot => $item) {
        $display = (int) ($item['display_id'] ?? 0);
        if ($display <= 0 || !isset($slots[$slot])) {
            continue;
        }
        $modelSlot = $slots[$slot];
        if ((int) $slot === 4 && (int) $item['inventory_type'] === 20) {
            $modelSlot = 20; // robes must cover the legs
        } elseif ((int) $slot === 17 && in_array((int) $item['inventory_type'], [15, 25, 26, 28], true)) {
            $modelSlot = (int) $item['inventory_type'];
        }
        $items[] = [$modelSlot, $display];
    }
    return $items;
}
