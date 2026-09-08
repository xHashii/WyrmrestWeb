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
        'display_id' => 0,
        'icon_file_data_id' => 0,
    ];
}

/**
 * Turn one parsed equipmentCache slot into a displayable item.
 *
 * The cache only stores a look (display id) plus the equipped item's subclass
 * and inventory type — never the item id itself. We resolve that look back to
 * the item it belongs to and pull its real name/quality/item level from the
 * same DB2 export the rest of the Armory uses. A cached secondary appearance
 * (ItemModifiedAppearance id) resolves the item exactly; otherwise the
 * subclass + inventory type disambiguate, the character's class rejects items
 * it cannot wear, and curated realm overrides are preferred over generic
 * same-look items. When nothing matches, the slot still shows its saved icon
 * rather than vanishing.
 */
function cachedAppearanceItem(array $config, int $slot, array $appearance, ?int $characterClass = null): array
{
    $tables = itemVisualTables($config);
    $displayId = (int) ($appearance['display_id'] ?? 0);
    $subclass = (int) ($appearance['subclass'] ?? -1);
    $inventoryType = (int) ($appearance['inventory_type'] ?? -1);
    $secondaryAppearanceId = (int) ($appearance['secondary_appearance_id'] ?? 0);

    $entry = resolveItemFromAppearance($config, $displayId, $subclass, $inventoryType, $characterClass, $secondaryAppearanceId);
    $iconFromDisplay = (int) ($tables['displays'][$displayId] ?? 0);

    $base = array_replace(unknownArmoryItem(0), $appearance, [
        'name' => equipSlotLabel($slot) . ' (saved appearance)',
        'source' => 'appearance-cache',
        'equipment_source' => 'equipment-cache',
        'icon_file_data_id' => $iconFromDisplay,
        'slot' => $slot,
        'bag' => 0,
        'item_guid' => null,
        'count' => 1,
        'durability' => null,
        'lookalike_count' => itemAppearanceCandidateCount($config, $displayId),
    ]);

    if ($entry > 0) {
        $resolved = resolveItems($config, [$entry])[$entry] ?? null;
        if ($resolved !== null) {
            $visual = itemVisuals($config, [$entry])[$entry] ?? [];
            $base = array_replace($base, $resolved, [
                // Keep the character's actual saved appearance/enchant fields.
                'entry' => $entry,
                'display_id' => $displayId ?: (int) ($visual['display_id'] ?? 0),
                'icon_file_data_id' => (int) ($visual['icon_file_data_id'] ?? 0) ?: $iconFromDisplay,
                'source' => 'appearance-resolved',
                'equipment_source' => 'equipment-cache',
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
    }

    return $base;
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
