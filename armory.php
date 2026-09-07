<?php
require __DIR__ . '/includes/bootstrap.php';

$query = trim($_GET['q'] ?? '');
$type = ($_GET['type'] ?? 'character') === 'guild' ? 'guild' : 'character';

$characterResults = [];
$guildResults = [];

if ($query !== '') {
    if ($type === 'guild') {
        $guildResults = searchGuilds($config, $query);
    } else {
        $characterResults = searchCharacters($config, $query);
    }
}

$activePage = 'armory';
$pageTitle = 'Armory';
require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <h1>Armory</h1>
  <p>Look up characters and guilds.</p>
</div>

<div class="panel">
  <form method="GET" class="armory-search">
    <select name="type">
      <option value="character" <?= $type === 'character' ? 'selected' : '' ?>>Character</option>
      <option value="guild" <?= $type === 'guild' ? 'selected' : '' ?>>Guild</option>
    </select>
    <input type="text" name="q" value="<?= htmlspecialchars($query) ?>" placeholder="Name…" autofocus>
    <button type="submit">Search</button>
  </form>

  <?php if (empty($config['db_host'])): ?>
    <p class="roster-empty" style="margin-top: 18px;">
      Armory isn't configured yet — set <code>db_host</code> (and the rest
      of the database settings) in <code>config.php</code>.
    </p>
  <?php elseif ($query === ''): ?>
    <p class="roster-empty" style="margin-top: 18px;">Search for a character or guild by name.</p>
  <?php elseif ($type === 'character'): ?>
    <?php if (empty($characterResults)): ?>
      <p class="roster-empty" style="margin-top: 18px;">No characters found matching "<?= htmlspecialchars($query) ?>".</p>
    <?php else: ?>
      <div style="margin-top: 18px;">
        <?php foreach ($characterResults as $c): ?>
          <a class="search-result-row" href="character.php?name=<?= urlencode($c['name']) ?>">
            <img class="icon" src="images/class/<?= (int) $c['class'] ?>.gif" alt="" onerror="this.classList.add('icon-missing')">
            <span class="status-dot <?= (int) $c['online'] === 1 ? 'online' : '' ?>"></span>
            <span class="name"><?= htmlspecialchars($c['name']) ?></span>
            <span class="meta">Level <?= (int) $c['level'] ?> <?= htmlspecialchars(raceName((int) $c['race'])) ?> <?= htmlspecialchars(className((int) $c['class'])) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php else: ?>
    <?php if (empty($guildResults)): ?>
      <p class="roster-empty" style="margin-top: 18px;">No guilds found matching "<?= htmlspecialchars($query) ?>".</p>
    <?php else: ?>
      <div style="margin-top: 18px;">
        <?php foreach ($guildResults as $g): ?>
          <a class="search-result-row" href="guild.php?name=<?= urlencode($g['name']) ?>">
            <span class="name"><?= htmlspecialchars($g['name']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
