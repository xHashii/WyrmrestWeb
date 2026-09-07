<?php
require __DIR__ . '/includes/bootstrap.php';

$query = trim($_GET['q'] ?? '');
$type = ($_GET['type'] ?? 'character') === 'guild' ? 'guild' : 'character';

$queryLength = mb_strlen($query);
$tooShort = $query !== '' && $queryLength < ARMORY_MIN_SEARCH_LENGTH;

$characterResults = [];
$guildResults = [];

if ($query !== '' && !$tooShort) {
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
  <p>Look up characters and guilds — type the first <?= ARMORY_MIN_SEARCH_LENGTH ?> letters of a name.</p>
</div>

<div class="panel">
  <form method="GET" class="armory-search" id="armory-search-form" autocomplete="off">
    <select name="type" id="armory-search-type">
      <option value="character" <?= $type === 'character' ? 'selected' : '' ?>>Character</option>
      <option value="guild" <?= $type === 'guild' ? 'selected' : '' ?>>Guild</option>
    </select>
    <div class="armory-search-field">
      <input type="text" name="q" id="armory-search-input" value="<?= htmlspecialchars($query) ?>"
             placeholder="Start of a name, e.g. Syl…" minlength="<?= ARMORY_MIN_SEARCH_LENGTH ?>"
             role="combobox" aria-expanded="false" aria-controls="armory-suggest" autofocus>
      <div class="suggest-box" id="armory-suggest" hidden></div>
    </div>
    <button type="submit">Search</button>
  </form>

  <?php if (empty($config['db_host'])): ?>
    <p class="roster-empty" style="margin-top: 18px;">
      Armory isn't configured yet — set <code>db_host</code> (and the rest
      of the database settings) in <code>config.php</code>.
    </p>
  <?php elseif ($query === ''): ?>
    <p class="roster-empty" style="margin-top: 18px;">
      Search for a character or guild by name. Partial names work: <code>Syl</code>
      finds <em>Sylea</em>, <em>Sylvanas</em> and <em>Sylthar</em>, and capitalisation doesn't matter.
    </p>
  <?php elseif ($tooShort): ?>
    <p class="roster-empty" style="margin-top: 18px;">
      Type at least <?= ARMORY_MIN_SEARCH_LENGTH ?> letters — "<?= htmlspecialchars($query) ?>"
      is only <?= $queryLength ?>.
    </p>
  <?php elseif ($type === 'character'): ?>
    <?php if (empty($characterResults)): ?>
      <p class="roster-empty" style="margin-top: 18px;">No characters start with "<?= htmlspecialchars($query) ?>".</p>
    <?php else: ?>
      <p class="search-summary"><?= count($characterResults) ?> character<?= count($characterResults) === 1 ? '' : 's' ?> starting with "<?= htmlspecialchars($query) ?>"</p>
      <div>
        <?php foreach ($characterResults as $c): ?>
          <?php
            $raceId = (int) $c['race'];
            $classId = (int) $c['class'];
            $genderId = (int) $c['gender'] === 1 ? 1 : 0;
          ?>
          <a class="search-result-row" href="character.php?guid=<?= (int) $c['guid'] ?>">
            <img class="icon" src="images/class/<?= $classId ?>.gif" alt="" onerror="this.classList.add('icon-missing')">
            <img class="icon" src="images/race/<?= $raceId ?>-<?= $genderId ?>.gif" alt="" onerror="this.classList.add('icon-missing')">
            <span class="status-dot <?= (int) $c['online'] === 1 ? 'online' : '' ?>"></span>
            <span class="name"><?= htmlspecialchars($c['name']) ?></span>
            <span class="meta">Level <?= (int) $c['level'] ?> <?= htmlspecialchars(raceName($raceId)) ?> <?= htmlspecialchars(className($classId)) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php else: ?>
    <?php if (empty($guildResults)): ?>
      <p class="roster-empty" style="margin-top: 18px;">No guilds start with "<?= htmlspecialchars($query) ?>".</p>
    <?php else: ?>
      <p class="search-summary"><?= count($guildResults) ?> guild<?= count($guildResults) === 1 ? '' : 's' ?> starting with "<?= htmlspecialchars($query) ?>"</p>
      <div>
        <?php foreach ($guildResults as $g): ?>
          <a class="search-result-row" href="guild.php?id=<?= (int) $g['guildid'] ?>">
            <span class="name">&lt;<?= htmlspecialchars($g['name']) ?>&gt;</span>
            <span class="meta"><?= (int) $g['member_count'] ?> member<?= (int) $g['member_count'] === 1 ? '' : 's' ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php require __DIR__ . '/includes/db-errors.php'; ?>
</div>

<script>
/**
 * Type-ahead for the search box: after <?= ARMORY_MIN_SEARCH_LENGTH ?> characters, ask
 * armory-suggest.php for every name starting with what's been typed.
 * Purely additive — submitting the form still does a full server-side search.
 */
(function () {
  var input = document.getElementById('armory-search-input');
  var typeSelect = document.getElementById('armory-search-type');
  var box = document.getElementById('armory-suggest');
  if (!input || !box) return;

  var minLength = <?= ARMORY_MIN_SEARCH_LENGTH ?>;
  var timer = null;
  var lastQuery = null;
  var activeIndex = -1;

  function hide() {
    box.hidden = true;
    box.innerHTML = '';
    activeIndex = -1;
    input.setAttribute('aria-expanded', 'false');
  }

  function rowsFrom(data) {
    return Array.prototype.map.call(data.results, function (item) {
      var row = document.createElement('a');
      row.className = 'suggest-row';
      row.href = item.url;

      if (typeof item.class === 'number') {
        var classIcon = document.createElement('img');
        classIcon.className = 'icon';
        classIcon.src = 'images/class/' + item.class + '.gif';
        classIcon.alt = '';
        classIcon.onerror = function () { this.classList.add('icon-missing'); };
        row.appendChild(classIcon);

        var dot = document.createElement('span');
        dot.className = 'status-dot' + (item.online ? ' online' : '');
        row.appendChild(dot);
      }

      var name = document.createElement('span');
      name.className = 'name';
      name.textContent = item.name;
      row.appendChild(name);

      var meta = document.createElement('span');
      meta.className = 'meta';
      meta.textContent = item.meta;
      row.appendChild(meta);

      return row;
    });
  }

  function show(data) {
    box.innerHTML = '';
    if (!data.results.length) {
      hide();
      return;
    }
    rowsFrom(data).forEach(function (row) { box.appendChild(row); });
    box.hidden = false;
    activeIndex = -1;
    input.setAttribute('aria-expanded', 'true');
  }

  function highlight(delta) {
    var rows = box.querySelectorAll('.suggest-row');
    if (!rows.length) return;
    if (activeIndex >= 0) rows[activeIndex].classList.remove('active');
    activeIndex = (activeIndex + delta + rows.length) % rows.length;
    rows[activeIndex].classList.add('active');
    rows[activeIndex].scrollIntoView({ block: 'nearest' });
  }

  function fetchSuggestions() {
    var query = input.value.trim();
    if (query.length < minLength) { hide(); return; }
    if (query === lastQuery) return;
    lastQuery = query;

    fetch('armory-suggest.php?type=' + encodeURIComponent(typeSelect.value) +
          '&q=' + encodeURIComponent(query))
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (input.value.trim() === data.query) show(data);
      })
      .catch(hide);
  }

  input.addEventListener('input', function () {
    clearTimeout(timer);
    timer = setTimeout(fetchSuggestions, 150);
  });

  input.addEventListener('keydown', function (event) {
    if (box.hidden) return;
    if (event.key === 'ArrowDown') { event.preventDefault(); highlight(1); }
    else if (event.key === 'ArrowUp') { event.preventDefault(); highlight(-1); }
    else if (event.key === 'Escape') { hide(); }
    else if (event.key === 'Enter' && activeIndex >= 0) {
      event.preventDefault();
      box.querySelectorAll('.suggest-row')[activeIndex].click();
    }
  });

  typeSelect.addEventListener('change', function () { lastQuery = null; fetchSuggestions(); });
  document.addEventListener('click', function (event) {
    if (!box.contains(event.target) && event.target !== input) hide();
  });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
