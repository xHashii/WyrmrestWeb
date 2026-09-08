<?php
require __DIR__ . '/includes/bootstrap.php';

$onlinePlayers = getOnlinePlayers($config);
$classBreakdown = getOnlineClassBreakdown($config);

$rosterPerPage = 15;
$rosterTotal = count($onlinePlayers);
$rosterPages = max(1, (int) ceil($rosterTotal / $rosterPerPage));
$rosterPage = max(1, min($rosterPages, (int) ($_GET['page'] ?? 1)));
$rosterPageItems = array_slice($onlinePlayers, ($rosterPage - 1) * $rosterPerPage, $rosterPerPage);

$activePage = 'online';
$pageTitle = "Who's Online";
require __DIR__ . '/includes/header.php';
?>

<div class="panel roster-card" id="roster">
  <h2>Who's Online <span><?= count($onlinePlayers) ?> players online</span></h2>

  <?php if (empty($onlinePlayers)): ?>
    <p class="roster-empty">0 players online right now.</p>
  <?php else: ?>
    <?php if (!empty($classBreakdown)): ?>
      <div class="pop-bar">
        <?php foreach ($classBreakdown as $classId => $count): ?>
          <span class="pop-bar-seg" style="width: <?= ($count / array_sum($classBreakdown)) * 100 ?>%; background: <?= classColor((int) $classId) ?>;"></span>
        <?php endforeach; ?>
      </div>
      <div class="pop-legend">
        <?php foreach ($classBreakdown as $classId => $count): ?>
          <span class="pop-legend-item">
            <span class="pop-legend-swatch" style="background: <?= classColor((int) $classId) ?>;"></span>
            <?= htmlspecialchars(className((int) $classId)) ?> (<?= $count ?>)
          </span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="roster-list">
      <?php foreach ($rosterPageItems as $p): ?>
        <div class="roster-row">
          <div class="roster-icons">
            <img class="icon" src="<?= htmlspecialchars($p['class_icon']) ?>" alt="<?= htmlspecialchars($p['class_name']) ?>" title="<?= htmlspecialchars($p['class_name']) ?>" onerror="this.classList.add('icon-missing')">
            <img class="icon" src="<?= htmlspecialchars($p['race_icon']) ?>" alt="<?= htmlspecialchars($p['race_name']) ?>" title="<?= htmlspecialchars($p['race_name']) ?>" onerror="this.classList.add('icon-missing')">
          </div>
          <div class="roster-row-body">
            <div class="roster-row-top">
              <span class="name">
                <?php if (!empty($p['guid'])): ?>
                  <a href="character.php?guid=<?= (int) $p['guid'] ?>"><?= htmlspecialchars($p['name']) ?></a>
                <?php else: ?>
                  <?= htmlspecialchars($p['name']) ?>
                <?php endif; ?>
              </span>
              <span class="level-pill">Lvl <?= $p['level'] ?></span>
            </div>
            <div class="roster-row-sub"><?= htmlspecialchars($p['race_name']) ?> <?= htmlspecialchars($p['class_name']) ?> · <?= htmlspecialchars($p['zone_name']) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($rosterPages > 1): ?>
      <div class="pager">
        <a class="pager-link <?= $rosterPage <= 1 ? 'disabled' : '' ?>" href="?page=<?= max(1, $rosterPage - 1) ?>#roster">‹</a>
        <?php
          $window = 2;
          $lastShown = 0;
          for ($i = 1; $i <= $rosterPages; $i++):
            $show = ($i === 1 || $i === $rosterPages || abs($i - $rosterPage) <= $window);
            if (!$show) continue;
            if ($lastShown && $i - $lastShown > 1):
        ?>
          <span class="pager-link disabled">…</span>
        <?php
            endif;
            $lastShown = $i;
        ?>
          <a class="pager-link <?= $i === $rosterPage ? 'active' : '' ?>" href="?page=<?= $i ?>#roster"><?= $i ?></a>
        <?php endfor; ?>
        <a class="pager-link <?= $rosterPage >= $rosterPages ? 'disabled' : '' ?>" href="?page=<?= min($rosterPages, $rosterPage + 1) ?>#roster">›</a>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
