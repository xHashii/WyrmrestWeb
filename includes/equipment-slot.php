<?php
// View partial: $config, $slot, and $item are supplied by the paper-doll loop.
$slotLabel = equipSlotLabel((int) $slot);
$quality = itemQualityInfo($item ? (int) $item['quality'] : -1);
$icon = $item ? itemIconUrl($config, (int) ($item['icon_file_data_id'] ?? 0)) : null;
$isCached = $item && ($item['equipment_source'] ?? '') === 'equipment-cache';
$itemLabel = $item ? $item['name'] : 'No saved equipment';
?>
<details class="gear-slot <?= $item ? 'is-occupied' : 'is-empty' ?> <?= $isCached ? 'is-cached' : '' ?>"
         data-gear-slot="<?= (int) $slot ?>" style="--item-quality: <?= $item ? $quality[1] : '#484b50' ?>;">
  <summary class="gear-icon" aria-label="<?= htmlspecialchars($slotLabel . ': ' . $itemLabel) ?>"
           title="<?= htmlspecialchars($slotLabel . ': ' . $itemLabel) ?>">
    <svg class="slot-placeholder" viewBox="0 0 48 48" aria-hidden="true"><use href="images/equipment-slots.svg#<?= equipmentSlotIcon((int) $slot) ?>"></use></svg>
    <?php if ($icon): ?>
      <img class="item-art" src="<?= htmlspecialchars($icon) ?>" alt="" width="52" height="52"
           referrerpolicy="no-referrer" onerror="this.hidden = true; this.parentElement.classList.add('icon-unavailable');">
    <?php elseif ($item): ?>
      <span class="unknown-item-mark" aria-hidden="true">?</span>
    <?php endif; ?>
    <?php if ($isCached): ?><span class="cached-item-mark" aria-hidden="true">C</span><?php endif; ?>
  </summary>
  <div class="gear-tooltip" role="group" aria-label="<?= htmlspecialchars($slotLabel) ?> details">
    <span class="gear-slot-label"><?= htmlspecialchars($slotLabel) ?></span>
    <strong class="gear-item-name"><?= htmlspecialchars($itemLabel) ?></strong>
    <?php if ($item): ?>
      <?php if ($isCached): ?>
        <p class="gear-cache-note">Saved appearance only. The cache does not contain this item's name, rarity or stats.</p>
        <span class="gear-meta">Display ID <?= (int) $item['display_id'] ?></span>
      <?php else: ?>
        <span class="gear-meta"><?= htmlspecialchars($quality[0]) ?><?php if ((int) $item['item_level'] > 0): ?> · Item level <?= (int) $item['item_level'] ?><?php endif; ?></span>
        <?php if ((int) $item['required_level'] > 0): ?><span class="gear-meta">Requires level <?= (int) $item['required_level'] ?></span><?php endif; ?>
        <?php if ((int) $item['entry'] > 0): ?>
          <span class="gear-meta">Item <?= (int) $item['entry'] ?></span>
          <a class="gear-external-link" href="https://www.wowhead.com/wotlk/item=<?= (int) $item['entry'] ?>" target="_blank" rel="noopener noreferrer">View item on Wowhead ↗</a>
        <?php else: ?>
          <p class="gear-cache-note">An inventory slot is occupied, but its item instance could not be read.</p>
        <?php endif; ?>
      <?php endif; ?>
      <span class="gear-provenance"><?= $isCached ? 'CHARACTER EQUIPMENT CACHE' : 'SAVED INVENTORY RECORD' ?></span>
    <?php else: ?>
      <p class="gear-meta">No equipped item was found for this slot in the saved character data.</p>
    <?php endif; ?>
  </div>
</details>
