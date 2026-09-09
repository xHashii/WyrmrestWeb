<?php
// View partial: $config, $slot, and $item are supplied by the paper-doll loop.
// $wowheadStats (entry => cached Wowhead tooltip) is supplied by character.php.
$slotLabel = equipSlotLabel((int) $slot);
$quality = itemQualityInfo($item ? (int) $item['quality'] : -1);
$icon = $item ? itemIconUrl($config, (int) ($item['icon_file_data_id'] ?? 0)) : null;
$hasEntry = $item && (int) $item['entry'] > 0;
$itemLabel = $item ? $item['name'] : 'No saved equipment';
$wowhead = ($hasEntry && isset($wowheadStats) && is_array($wowheadStats))
    ? ($wowheadStats[(int) $item['entry']] ?? null)
    : null;
?>
<details class="gear-slot <?= $item ? 'is-occupied' : 'is-empty' ?>"
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
  </summary>
  <div class="gear-tooltip" role="group" aria-label="<?= htmlspecialchars($slotLabel) ?> details">
    <span class="gear-slot-label"><?= htmlspecialchars($slotLabel) ?></span>
    <?php if ($item): ?>
      <?php if ($wowhead && !empty($wowhead['html'])): ?>
        <?php // Wowhead's own tooltip: exact stats, equip effects and colours. ?>
        <div class="wowhead-stats"><?= $wowhead['html'] ?></div>
      <?php else: ?>
        <strong class="gear-item-name"><?= htmlspecialchars($itemLabel) ?></strong>
        <?php if ($hasEntry): ?>
          <span class="gear-meta"><?= htmlspecialchars($quality[0]) ?><?php if ((int) $item['item_level'] > 0): ?> · Item level <?= (int) $item['item_level'] ?><?php endif; ?></span>
          <?php if ((int) $item['required_level'] > 0): ?><span class="gear-meta">Requires level <?= (int) $item['required_level'] ?></span><?php endif; ?>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($hasEntry): ?>
        <a class="gear-external-link" href="https://www.wowhead.com/wotlk/item=<?= (int) $item['entry'] ?>" target="_blank" rel="noopener noreferrer">View on Wowhead ↗</a>
      <?php else: ?>
        <?php if (!empty($item['identity_ambiguous'])): ?>
          <p class="gear-meta">This saved appearance is shared by <?= (int) ($item['lookalike_count'] ?? 0) ?> items. The character cache does not store item IDs, so exact inventory access is required before a name or stats can be shown safely.</p>
        <?php else: ?>
          <p class="gear-meta">This slot is equipped, but the item's exact ID or details aren't available yet.</p>
        <?php endif; ?>
      <?php endif; ?>
    <?php else: ?>
      <p class="gear-meta">Nothing is equipped in this slot.</p>
    <?php endif; ?>
  </div>
</details>
