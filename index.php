<?php
require __DIR__ . '/includes/bootstrap.php';

$totalCharacters = getTotalCharacterCount($config);

$activePage = 'home';
$pageTitle = null;
require __DIR__ . '/includes/header.php';
?>

<div class="hero">
  <div class="eyebrow"><?= htmlspecialchars($config['expansion']) ?></div>
  <h1><?= htmlspecialchars($config['server_name']) ?></h1>
  <p><?= htmlspecialchars($config['server_tagline']) ?></p>
</div>

<div class="stats-strip">
  <div class="stat-card">
    <div class="stat-value"><?= $status['online'] && $status['players'] !== null ? (int) $status['players'] : '—' ?></div>
    <div class="stat-label">Players Online</div>
  </div>
  <div class="stat-card">
    <div class="stat-value"><?= $totalCharacters !== null ? number_format($totalCharacters) : '—' ?></div>
    <div class="stat-label">Characters Created</div>
  </div>
  <div class="stat-card">
    <div class="stat-value" style="font-size: 15px;"><?= htmlspecialchars($config['rates_summary']) ?></div>
    <div class="stat-label">Rates</div>
  </div>
</div>

<div class="cta-grid">
  <a class="cta-card primary" href="register.php">
    <div class="cta-icon">⚔</div>
    <div class="cta-title">Create Account</div>
    <div class="cta-desc">Register a Battle.net login and start playing.</div>
  </a>
  <a class="cta-card" href="online.php">
    <div class="cta-icon">👥</div>
    <div class="cta-title">Who's Online</div>
    <div class="cta-desc">See who's currently playing and where.</div>
  </a>
  <a class="cta-card" href="leaderboard.php">
    <div class="cta-icon">🏆</div>
    <div class="cta-title">Leaderboard</div>
    <div class="cta-desc">Top characters ranked by level.</div>
  </a>
  <a class="cta-card" href="info.php">
    <div class="cta-icon">ℹ</div>
    <div class="cta-title">Server Info</div>
    <div class="cta-desc">Realmlist, rates, and how to get help.</div>
  </a>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
