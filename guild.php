<?php
require __DIR__ . '/includes/bootstrap.php';

$name = trim($_GET['name'] ?? '');
$guild = $name !== '' ? findGuildByName($config, $name) : null;
$members = $guild ? getGuildMembers($config, (int) $guild['guildid']) : [];

$activePage = 'armory';
$pageTitle = $guild ? $guild['name'] : 'Guild not found';
require __DIR__ . '/includes/header.php';
?>

<?php if (!$guild): ?>
  <div class="page-header">
    <h1>Guild not found</h1>
    <p>No guild named "<?= htmlspecialchars($name) ?>" could be found.</p>
  </div>
  <div class="panel">
    <p class="roster-empty"><a href="armory.php" class="discord-link" style="margin:0;">Back to search</a></p>
  </div>
<?php else: ?>
  <div class="panel">
    <div class="guild-header">
      <h1>&lt;<?= htmlspecialchars($guild['name']) ?>&gt;</h1>
      <div class="char-sub">
        <?php if ($guild['leader_name']): ?>
          Led by <a href="character.php?name=<?= urlencode($guild['leader_name']) ?>" style="color: var(--gold-bright);"><?= htmlspecialchars($guild['leader_name']) ?></a>
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
                <a href="character.php?name=<?= urlencode($m['name']) ?>"><?= htmlspecialchars($m['name']) ?></a>
              </td>
              <td><?= htmlspecialchars($m['rank_name']) ?></td>
              <td><?= (int) $m['level'] ?></td>
              <td><?= htmlspecialchars(className((int) $m['class'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
