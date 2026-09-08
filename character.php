<?php
require __DIR__ . '/includes/bootstrap.php';

$guid = isset($_GET['guid']) ? (int) $_GET['guid'] : 0;
$name = trim($_GET['name'] ?? '');

$character = ($guid > 0 || $name !== '') ? findCharacter($config, $guid ?: null, $name) : null;

// Read saved inventory plus the optional character-select appearance cache.
// Only a visible profile reaches this lookup; hidden GM characters stay hidden.
$inventory = $character ? getCharacterInventory($config, (int) $character['guid']) : null;
$equipment = $inventory['equipped'] ?? [];
$professionItems = $inventory['profession'] ?? [];
$bags = $inventory['bags'] ?? [];
$backpack = $inventory['backpack'] ?? [];
$averageIlvl = $equipment ? averageItemLevel($equipment) : null;
$showBags = !empty($config['show_bag_contents']);

// Pull Wowhead's full stat tooltips for the equipped gear (cached, parallel).
$wowheadStats = getWowheadItemTooltips($config, array_values(array_filter(array_map(
    static fn (array $it): int => (int) ($it['entry'] ?? 0),
    $equipment
))));
$carriedCount = count($backpack);
foreach ($bags as $bag) {
    $carriedCount += count($bag['contents']);
}

// Anything typed in the URL is also worth offering as a search.
$searchTerm = $name !== '' ? $name : '';

$activePage = 'armory';
$pageTitle = $character ? $character['name'] : 'Character not found';
$bodyClass = 'character-page';
$pageStylesheets = ['assets/character.css'];
require __DIR__ . '/includes/header.php';
?>

<?php if (!$character): ?>
  <div class="page-header">
    <h1>Character not found</h1>
    <p>
      <?php if ($name !== ''): ?>
        No character named "<?= htmlspecialchars($name) ?>" could be found.
      <?php elseif ($guid > 0): ?>
        No character with id <?= $guid ?> could be found.
      <?php else: ?>
        No character was requested.
      <?php endif; ?>
    </p>
  </div>
  <div class="panel">
    <p class="roster-empty" style="margin-bottom: 14px;">
      Names are matched without caring about capitalisation. The character may
      be unavailable, deleted, or hidden from the Armory.
    </p>
    <form method="GET" action="armory.php" class="armory-search">
      <input type="hidden" name="type" value="character">
      <input type="text" name="q" value="<?= htmlspecialchars($searchTerm) ?>"
             placeholder="Search for a character…" minlength="<?= ARMORY_MIN_SEARCH_LENGTH ?>">
      <button type="submit">Search</button>
    </form>
    <?php require __DIR__ . '/includes/db-errors.php'; ?>
  </div>
<?php else: ?>
  <?php
    $raceId = (int) $character['race'];
    $classId = (int) $character['class'];
    $gender = (int) $character['gender'] === 1 ? 1 : 0;
    $guild = $character['guild'] ?? null;
    $zonesLookup = file_exists(__DIR__ . '/data/zones.php') ? require __DIR__ . '/data/zones.php' : [];
    $zoneId = (int) $character['zone'];
    $goldTotal = (int) ($character['money'] ?? 0);
    $enable3d = !empty($config['enable_3d_viewer']) && in_array($raceId, [1, 2, 3, 4, 5, 6, 7, 8, 10, 11], true);
  ?>
  <header class="character-overview">
    <div class="character-identity">
      <h1><?= htmlspecialchars($character['name']) ?></h1>
      <p>Level <?= (int) $character['level'] ?> <?= htmlspecialchars(raceName($raceId)) ?> <?= htmlspecialchars(className($classId)) ?></p>
      <?php if ($guild): ?>
        <p class="character-guild"><a href="guild.php?id=<?= (int) $guild['guildid'] ?>">&lt;<?= htmlspecialchars($guild['name']) ?>&gt;</a></p>
      <?php endif; ?>
      <p class="character-realm"><?= htmlspecialchars($config['server_name']) ?></p>
      <p class="character-online"><?= (int) $character['online'] === 1 ? 'Online' : 'Offline' ?> <span class="status-dot <?= (int) $character['online'] === 1 ? 'online' : '' ?>" aria-hidden="true"></span></p>
    </div>
    <a class="character-back" href="armory.php">← Armory search</a>
  </header>

  <section class="paperdoll" aria-label="Equipped items" id="character-equipment">
    <div class="paperdoll-stage">
      <?php foreach (paperdollSlots() as $position => $slots): ?>
        <div class="<?= $position === 'weapons' ? 'gear-weapons' : 'gear-column gear-' . $position ?>">
          <?php foreach ($slots as $slot): ?>
            <?php $item = $equipment[$slot] ?? null; require __DIR__ . '/includes/equipment-slot.php'; ?>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
      <div class="model-stage">
        <div class="model-canvas" id="character-model" aria-label="3D equipment preview" hidden></div>
        <p class="model-status" id="model-status" role="status" aria-live="polite"></p>
      </div>
    </div>
    <div class="paperdoll-controls">
      <?php if ($enable3d): ?>
        <label class="model-toggle"><input type="checkbox" id="enable-character-model" aria-controls="character-model" disabled> Enable 3D viewer</label>
        <noscript><span class="equipment-count">JavaScript is required for the optional 3D preview.</span></noscript>
      <?php else: ?>
        <span class="equipment-count">Equipment overview</span>
      <?php endif; ?>
      <span class="equipment-count"><?= count($equipment) ?> / 19 equipped</span>
    </div>
    <p class="equipment-hint">Hover, tap or focus a slot to inspect it. This is saved realm data; after changing gear in-game, log out and refresh.</p>
    <p class="equipment-hint" id="model-disclaimer" hidden>3D preview uses a base model for this race and body type, plus the available equipment appearances. Face, hair and other customizations are not applied. Model assets are provided by Wowhead.</p>

    <?php if (!$equipment && $inventory['status']['inventory'] === 'unavailable'): ?>
      <div class="equipment-notice" role="status">Equipment data isn't available for this character right now. This does not mean they are unequipped — check back after they next log out in-game.</div>
    <?php elseif (!$equipment): ?>
      <div class="equipment-notice" role="status">No saved equipment was found. If this character has gear in-game, log out and refresh.</div>
    <?php endif; ?>
    <?php require __DIR__ . '/includes/db-errors.php'; ?>
  </section>

  <details class="panel character-details">
    <summary>Character details<?php if ($averageIlvl !== null): ?> · Average item level <?= $averageIlvl ?><?php endif; ?></summary>
    <dl class="stats">
      <div>
        <dt>Status</dt>
        <dd>
          <span class="status-dot <?= (int) $character['online'] === 1 ? 'online' : '' ?>"></span>
          <?= (int) $character['online'] === 1 ? 'Online' : 'Offline' ?>
        </dd>
      </div>
      <div>
        <dt>Zone</dt>
        <dd><?= htmlspecialchars($zonesLookup[$zoneId] ?? "Zone #{$zoneId}") ?></dd>
      </div>
      <div>
        <dt>Played time</dt>
        <dd><?= htmlspecialchars(formatPlayedTime((int) $character['totaltime'])) ?></dd>
      </div>
      <?php if ($averageIlvl !== null): ?>
        <div>
          <dt>Avg item level</dt>
          <dd><?= $averageIlvl ?></dd>
        </div>
      <?php endif; ?>
      <?php if (isset($character['totalKills'])): ?>
        <div>
          <dt>Honorable kills</dt>
          <dd><?= number_format((int) $character['totalKills']) ?></dd>
        </div>
      <?php endif; ?>
      <div>
        <dt>Gold</dt>
        <dd><?= number_format(intdiv($goldTotal, 10000)) ?>g</dd>
      </div>
    </dl>
  </details>

  <?php if ($professionItems): ?>
    <details class="panel character-details">
      <summary>Profession gear</summary>
      <div class="equip-grid">
        <?php foreach ($professionItems as $slot => $item): ?>
          <?php $q = itemQualityInfo((int) $item['quality']); ?>
          <div class="equip-row">
            <span class="slot-label"><?= htmlspecialchars(professionSlotLabel((int) $slot)) ?></span>
            <span class="item-name" style="color: <?= $q[1] ?>;"><?= htmlspecialchars($item['name']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </details>
  <?php endif; ?>

  <?php if ($showBags): ?>
    <details class="panel character-details">
      <summary>Bags <span class="bag-total"><?= $carriedCount ?> item<?= $carriedCount === 1 ? '' : 's' ?> carried</span></summary>

      <?php if (!$bags && !$backpack): ?>
        <p class="roster-empty">No carried items were found in the saved inventory.</p>
      <?php else: ?>
        <div class="bag-group">
          <div class="bag-head">
            <span class="bag-name">Backpack</span>
            <span class="bag-meta"><?= count($backpack) ?> item<?= count($backpack) === 1 ? '' : 's' ?></span>
          </div>
          <?php if (!$backpack): ?>
            <p class="bag-empty">Empty</p>
          <?php else: ?>
            <ul class="bag-list">
              <?php foreach ($backpack as $item): ?>
                <?php $q = itemQualityInfo((int) $item['quality']); ?>
                <li>
                  <span class="item-name" style="color: <?= $q[1] ?>;"><?= htmlspecialchars($item['name']) ?></span>
                  <?php if ((int) $item['count'] > 1): ?><span class="item-count">×<?= (int) $item['count'] ?></span><?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>

        <?php foreach ($bags as $bag): ?>
          <?php $bq = itemQualityInfo((int) $bag['item']['quality']); ?>
          <div class="bag-group">
            <div class="bag-head">
              <span class="bag-name" style="color: <?= $bq[1] ?>;"><?= htmlspecialchars($bag['item']['name']) ?></span>
              <span class="bag-meta"><?= count($bag['contents']) ?> item<?= count($bag['contents']) === 1 ? '' : 's' ?></span>
            </div>
            <?php if (!$bag['contents']): ?>
              <p class="bag-empty">Empty</p>
            <?php else: ?>
              <ul class="bag-list">
                <?php foreach ($bag['contents'] as $item): ?>
                  <?php $q = itemQualityInfo((int) $item['quality']); ?>
                  <li>
                    <span class="item-name" style="color: <?= $q[1] ?>;"><?= htmlspecialchars($item['name']) ?></span>
                    <?php if ((int) $item['count'] > 1): ?><span class="item-count">×<?= (int) $item['count'] ?></span><?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </details>
  <?php endif; ?>
  <?php if ($enable3d): ?>
    <script type="application/json" id="character-model-data"><?= json_encode(['race' => $raceId, 'gender' => $gender, 'items' => equipmentModelItems($equipment)], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
  <?php endif; ?>
  <script src="assets/character.js" defer></script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
