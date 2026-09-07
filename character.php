<?php
require __DIR__ . '/includes/bootstrap.php';

$name = trim($_GET['name'] ?? '');
$character = $name !== '' ? findCharacterByName($config, $name) : null;
$equipment = $character ? getCharacterEquipment($config, (int) $character['guid']) : [];

$activePage = 'armory';
$pageTitle = $character ? $character['name'] : 'Character not found';
require __DIR__ . '/includes/header.php';
?>

<?php if (!$character): ?>
  <div class="page-header">
    <h1>Character not found</h1>
    <p>No character named "<?= htmlspecialchars($name) ?>" could be found.</p>
  </div>
  <div class="panel">
    <p class="roster-empty"><a href="armory.php" class="discord-link" style="margin:0;">Back to search</a></p>
  </div>
<?php else: ?>
  <?php
    $raceId = (int) $character['race'];
    $classId = (int) $character['class'];
    $gender = (int) $character['gender'] === 1 ? 1 : 0;
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
          <?php if ($character['guild_name']): ?>
            · <a href="guild.php?name=<?= urlencode($character['guild_name']) ?>">&lt;<?= htmlspecialchars($character['guild_name']) ?>&gt;</a>
            <?php if ($character['guild_rank']): ?> (<?= htmlspecialchars($character['guild_rank']) ?>)<?php endif; ?>
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
        <dd><?php
          $zoneId = (int) $character['zone'];
          $zonesLookup = file_exists(__DIR__ . '/data/zones.php') ? require __DIR__ . '/data/zones.php' : [];
          echo htmlspecialchars($zonesLookup[$zoneId] ?? "Zone #{$zoneId}");
        ?></dd>
      </div>
      <div>
        <dt>Played time</dt>
        <dd><?= htmlspecialchars(formatPlayedTime((int) $character['totaltime'])) ?></dd>
      </div>
    </dl>
  </div>

  <div class="panel" style="margin-top: 22px;">
    <h2>Equipment</h2>
    <?php if (empty($equipment)): ?>
      <p class="roster-empty">No equipped items found.</p>
    <?php else: ?>
      <div class="equip-grid">
        <?php for ($slot = 0; $slot <= 18; $slot++): ?>
          <?php $item = $equipment[$slot] ?? null; ?>
          <div class="equip-row">
            <span class="slot-label"><?= htmlspecialchars(equipSlotLabel($slot)) ?></span>
            <?php if ($item): ?>
              <?php $q = itemQualityInfo((int) $item['Quality']); ?>
              <span class="item-name" style="color: <?= $q[1] ?>;"><?= htmlspecialchars($item['name']) ?></span>
            <?php else: ?>
              <span class="item-name empty">Empty</span>
            <?php endif; ?>
          </div>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
