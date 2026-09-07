<?php
require __DIR__ . '/includes/bootstrap.php';

$guildId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$name = trim($_GET['name'] ?? '');

$guild = ($guildId > 0 || $name !== '') ? findGuild($config, $guildId ?: null, $name) : null;
$members = $guild ? getGuildMembers($config, (int) $guild['guildid']) : [];

$activePage = 'armory';
$pageTitle = $guild ? $guild['name'] : 'Guild not found';
require __DIR__ . '/includes/header.php';
?>

<?php if (!$guild): ?>
  <div class="page-header">
    <h1>Guild not found</h1>
    <p>
      <?php if ($name !== ''): ?>
        No guild named "<?= htmlspecialchars($name) ?>" could be found.
      <?php else: ?>
        No guild with id <?= $guildId ?> could be found.
      <?php endif; ?>
    </p>
  </div>
  <div class="panel">
    <form method="GET" action="armory.php" class="armory-search">
      <input type="hidden" name="type" value="guild">
      <input type="text" name="q" value="<?= htmlspecialchars($name) ?>"
             placeholder="Search for a guild…" minlength="<?= ARMORY_MIN_SEARCH_LENGTH ?>">
      <button type="submit">Search</button>
    </form>
    <?php require __DIR__ . '/includes/db-errors.php'; ?>
  </div>
<?php else: ?>
  <div class="panel">
    <div class="guild-header">
      <h1>&lt;<?= htmlspecialchars($guild['name']) ?>&gt;</h1>
      <div class="char-sub">
        <?php if ($guild['leader_name']): ?>
          Led by <a href="character.php?guid=<?= (int) $guild['leader_guid'] ?>" style="color: var(--gold-bright);"><?= htmlspecialchars($guild['leader_name']) ?></a>
          ·
        <?php endif; ?>
        <?= count($members) ?> members
        <?php if (!empty($guild['createdate'])): ?>
          · Founded <?= htmlspecialchars(date('M j, Y', (int) $guild['createdate'])) ?>
        <?php endif; ?>
      </div>
      <?php if (!empty($guild['motd'])): ?>
        <div class="char-sub" style="margin-top: 8px; font-style: italic;">"<?= htmlspecialchars($guild['motd']) ?>"</div>
      <?php endif; ?>
    </div>

    <?php if (empty($members)): ?>
      <p class="roster-empty">No members to show.</p>
    <?php else: ?>
      <table class="guild-roster">
        <thead>
          <tr>
            <th>Name</th>
            <th>Rank</th>
            <th>Level</th>
            <th>Class</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($members as $m): ?>
            <tr>
              <td>
                <span class="status-dot <?= (int) $m['online'] === 1 ? 'online' : '' ?>"></span>
                <a href="character.php?guid=<?= (int) $m['guid'] ?>"><?= htmlspecialchars($m['name']) ?></a>
              </td>
              <td><?= htmlspecialchars($m['rank_name'] ?? ('Rank ' . (int) $m['rank_id'])) ?></td>
              <td><?= (int) $m['level'] ?></td>
              <td><?= htmlspecialchars(className((int) $m['class'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    <?php require __DIR__ . '/includes/db-errors.php'; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
