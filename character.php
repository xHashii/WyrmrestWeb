<?php
require __DIR__ . '/includes/bootstrap.php';

$guid = isset($_GET['guid']) ? (int) $_GET['guid'] : 0;
$name = trim($_GET['name'] ?? '');

$character = ($guid > 0 || $name !== '') ? findCharacter($config, $guid ?: null, $name) : null;
$equipment = $character ? getCharacterEquipment($config, (int) $character['guid']) : [];
$averageIlvl = $equipment ? averageItemLevel($equipment) : null;

// Anything typed in the URL is also worth offering as a search.
$searchTerm = $name !== '' ? $name : '';

$activePage = 'armory';
$pageTitle = $character ? $character['name'] : 'Character not found';
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
      Names are matched without caring about capitalisation, so this really is
      a character that doesn't exist (or one that has been deleted).
    </p>
    <?php if (!empty($config['debug'])): ?>
      <?php $reason = armoryLookupReason(); ?>
      <?php if ($reason === 'hidden-gm'): ?>
        <p class="roster-empty" style="margin-bottom: 14px; color: var(--gold-bright);">
          Debug: that character exists, but its account is a Game Master and
          <code>hide_game_masters</code> is enabled in <code>config.php</code>.
        </p>
      <?php elseif ($reason === 'no-db'): ?>
        <p class="roster-empty" style="margin-bottom: 14px; color: var(--gold-bright);">
          Debug: the characters database could not be reached at all.
          <a href="armory-diagnostics.php">Run the diagnostics</a>.
        </p>
      <?php elseif ($name !== ''): ?>
        <p class="roster-empty" style="margin-bottom: 14px;">
          Debug: <a href="armory-diagnostics.php?name=<?= urlencode($name) ?>">trace this lookup</a>.
        </p>
      <?php endif; ?>
    <?php endif; ?>
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
  ?>
  <div class="panel">
    <div class="char-header">
      <img class="icon" src="images/class/<?= $classId ?>.gif" alt="" onerror="this.classList.add('icon-missing')">
      <img class="icon" src="images/race/<?= $raceId ?>-<?= $gender ?>.gif" alt="" onerror="this.classList.add('icon-missing')">
      <div>
        <h1><?= htmlspecialchars($character['name']) ?></h1>
        <div class="char-sub">
          Level <?= (int) $character['level'] ?> <?= htmlspecialchars(raceName($raceId)) ?> <?= htmlspecialchars(className($classId)) ?>
          · <?= raceFaction($raceId) ?>
          <?php if ($guild): ?>
            · <a href="guild.php?id=<?= (int) $guild['guildid'] ?>">&lt;<?= htmlspecialchars($guild['name']) ?>&gt;</a>
            <?php if (!empty($guild['rank_name'])): ?> (<?= htmlspecialchars($guild['rank_name']) ?>)<?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

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
  </div>

  <div class="panel" style="margin-top: 22px;">
    <h2>Equipment</h2>
    <?php if (empty($equipment)): ?>
      <p class="roster-empty">
        Nothing equipped — this character has no items in equipment slots
        (<code>character_inventory</code> rows with <code>bag = 0</code> and <code>slot</code> 0-18).
      </p>
    <?php else: ?>
      <div class="equip-grid">
        <?php for ($slot = 0; $slot <= 18; $slot++): ?>
          <?php $item = $equipment[$slot] ?? null; ?>
          <div class="equip-row">
            <span class="slot-label"><?= htmlspecialchars(equipSlotLabel($slot)) ?></span>
            <?php if ($item): ?>
              <?php $q = itemQualityInfo((int) $item['quality']); ?>
              <span class="item-name" style="color: <?= $q[1] ?>;">
                <?= htmlspecialchars($item['name']) ?>
                <?php if ((int) $item['item_level'] > 0): ?>
                  <span class="item-ilvl"><?= (int) $item['item_level'] ?></span>
                <?php endif; ?>
              </span>
            <?php else: ?>
              <span class="item-name empty">Empty</span>
            <?php endif; ?>
          </div>
        <?php endfor; ?>
      </div>
      <?php
        $unresolved = array_filter($equipment, static fn ($i) => $i['source'] === 'unresolved');
      ?>
      <?php if ($unresolved): ?>
        <p class="roster-empty" style="margin-top: 14px;">
          <?= count($unresolved) ?> item(s) couldn't be named — they exist on the character
          but not in <code>hotfixes.item_sparse</code> or the bundled DB2 export.
          <a href="armory-diagnostics.php">Diagnostics</a>
        </p>
      <?php endif; ?>
    <?php endif; ?>
    <?php require __DIR__ . '/includes/db-errors.php'; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
