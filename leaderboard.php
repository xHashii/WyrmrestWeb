<?php
require __DIR__ . '/includes/bootstrap.php';

$topCharacters = getTopCharacters($config, 20);
$totalCharacters = getTotalCharacterCount($config);

$activePage = 'leaderboard';
$pageTitle = 'Leaderboard';
require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <h1>Top Characters</h1>
  <p>
    Ranked by level, played time as tiebreaker.
    <?php if ($totalCharacters !== null): ?>
      <?= number_format($totalCharacters) ?> characters created on this realm.
    <?php endif; ?>
  </p>
</div>

<div class="panel leaderboard-card">
  <?php if (empty($topCharacters)): ?>
    <p class="roster-empty">No character data available.</p>
  <?php else: ?>
    <div class="lb-grid">
      <?php foreach ($topCharacters as $i => $p): ?>
        <?php $rank = $i + 1; ?>
        <div class="lb-row">
          <span class="rank-badge <?= $rank === 1 ? 'r1' : ($rank === 2 ? 'r2' : ($rank === 3 ? 'r3' : '')) ?>">#<?= $rank ?></span>
          <span class="lb-icons">
            <img class="icon" src="<?= htmlspecialchars($p['class_icon']) ?>" alt="<?= htmlspecialchars($p['class_name']) ?>" title="<?= htmlspecialchars($p['class_name']) ?>" onerror="this.classList.add('icon-missing')">
            <img class="icon" src="<?= htmlspecialchars($p['race_icon']) ?>" alt="<?= htmlspecialchars($p['race_name']) ?>" title="<?= htmlspecialchars($p['race_name']) ?>" onerror="this.classList.add('icon-missing')">
          </span>
          <span class="lb-name">
            <?php if (!empty($p['guid'])): ?>
              <a href="character.php?guid=<?= (int) $p['guid'] ?>"><?= htmlspecialchars($p['name']) ?></a>
            <?php else: ?>
              <?= htmlspecialchars($p['name']) ?>
            <?php endif; ?>
          </span>
          <span class="lb-level">Lvl <?= $p['level'] ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
