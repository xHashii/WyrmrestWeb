<?php
/**
 * Armory diagnostics — walks every database and data file the Armory needs
 * and says, in plain English, which one is broken.
 *
 * Written for exactly the kind of bug that produced "Character not found"
 * for a character that clearly exists: a query that touched a database the
 * read-only user had no GRANT on, failing silently inside a catch block.
 *
 * Detailed error messages are only printed when 'debug' => true is set in
 * config.php, so this page can't leak connection details to visitors.
 */
require __DIR__ . '/includes/bootstrap.php';

$debug = !empty($config['debug']);
$checks = armoryDiagnostics($config);
$traceName = trim($_GET['name'] ?? '');
$trace = $traceName !== '' ? armoryTraceName($config, $traceName) : null;

$counts = ['ok' => 0, 'warn' => 0, 'fail' => 0];
foreach ($checks as $check) {
    $counts[$check['status']]++;
}

$activePage = 'armory';
$pageTitle = 'Armory diagnostics';
require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <h1>Armory diagnostics</h1>
  <p>
    <?= $counts['fail'] ?> failing · <?= $counts['warn'] ?> warning<?= $counts['warn'] === 1 ? '' : 's' ?>
    · <?= $counts['ok'] ?> ok
  </p>
</div>

<div class="panel">
  <?php if (!$debug): ?>
    <p class="roster-empty" style="margin-bottom: 16px;">
      Set <code>'debug' =&gt; true</code> in <code>config.php</code> to see the
      full error messages and connection details below.
    </p>
  <?php endif; ?>

  <table class="guild-roster diag-table">
    <thead>
      <tr><th>Check</th><th>Status</th><th>Detail</th></tr>
    </thead>
    <tbody>
      <?php foreach ($checks as $check): ?>
        <tr>
          <td><code><?= htmlspecialchars($check['name']) ?></code></td>
          <td><span class="diag-pill <?= $check['status'] ?>"><?= strtoupper($check['status']) ?></span></td>
          <td>
            <?php if ($debug || $check['status'] === 'ok'): ?>
              <?= htmlspecialchars($check['detail']) ?>
            <?php else: ?>
              <em>hidden — enable debug in config.php</em>
            <?php endif; ?>
            <?php if ($check['hint'] !== ''): ?>
              <div class="diag-hint"><?= htmlspecialchars($check['hint']) ?></div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="panel" style="margin-top: 22px;">
  <h2>Trace a character lookup</h2>
  <p class="roster-empty" style="margin-bottom: 14px;">
    Type the name of a character that misbehaves. This looks past every filter —
    deleted characters and Game Master accounts included — and says exactly what
    the Armory sees.
  </p>
  <form method="GET" class="armory-search">
    <input type="text" name="name" value="<?= htmlspecialchars($traceName) ?>" placeholder="Character name…">
    <button type="submit">Trace</button>
  </form>

  <?php if ($trace !== null): ?>
    <?php if ($trace['error'] !== null): ?>
      <p class="roster-empty" style="margin-top: 16px;">Query failed: <?= htmlspecialchars($trace['error']) ?></p>
    <?php elseif (!$trace['matches']): ?>
      <p class="roster-empty" style="margin-top: 16px;">
        No row in <code>characters</code> has that name, in any capitalisation.
        The character genuinely doesn't exist on this realm.
      </p>
    <?php else: ?>
      <table class="guild-roster diag-table" style="margin-top: 16px;">
        <thead>
          <tr><th>guid</th><th>name</th><th>account</th><th>level</th><th>state</th></tr>
        </thead>
        <tbody>
          <?php foreach ($trace['matches'] as $m): ?>
            <tr>
              <td><a href="character.php?guid=<?= $m['guid'] ?>"><?= $m['guid'] ?></a></td>
              <td><?= htmlspecialchars($m['name']) ?></td>
              <td><?= $m['account'] ?></td>
              <td><?= $m['level'] ?></td>
              <td>
                <span class="diag-pill <?= $m['hidden'] ? 'warn' : 'ok' ?>"><?= $m['hidden'] ? 'HIDDEN' : 'VISIBLE' ?></span>
                <div class="diag-hint"><?= htmlspecialchars($m['why']) ?></div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <h2 style="margin-top: 24px;">Equipped items</h2>
      <?php if (!$trace['equipment']): ?>
        <p class="roster-empty">
          Nothing in <code>character_inventory</code> with <code>bag = 0</code> and
          <code>slot</code> between 0 and 18 for this character.
        </p>
      <?php else: ?>
        <table class="guild-roster diag-table">
          <thead>
            <tr><th>slot</th><th>itemEntry</th><th>name</th><th>resolved from</th></tr>
          </thead>
          <tbody>
            <?php foreach ($trace['equipment'] as $item): ?>
              <tr>
                <td><?= htmlspecialchars($item['slot']) ?></td>
                <td><?= $item['entry'] ?></td>
                <td><?= htmlspecialchars($item['name']) ?></td>
                <td>
                  <span class="diag-pill <?= $item['source'] === 'unresolved' ? 'fail' : 'ok' ?>">
                    <?= htmlspecialchars($item['source']) ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
</div>

<div class="panel" style="margin-top: 22px;">
  <h2>Where the Armory reads from</h2>
  <dl class="stats diag-legend">
    <div>
      <dt>Characters &amp; gear</dt>
      <dd><code><?= htmlspecialchars($config['db_name']) ?></code> — <code>characters</code>,
          <code>character_inventory</code> (bag 0, slots 0-18), <code>item_instance</code></dd>
    </div>
    <div>
      <dt>Game Master filter</dt>
      <dd><code><?= htmlspecialchars($config['auth_db_name'] ?? 'auth') ?>.account_access</code> — optional;
          if it can't be read, GM characters are simply not hidden</dd>
    </div>
    <div>
      <dt>Item names</dt>
      <dd><code><?= htmlspecialchars($config['hotfixes_db_name'] ?? 'hotfixes') ?>.item_sparse</code> first
          (usually empty on a stock 3.4.3 server), then the bundled
          <code>db2/ItemSparse*.csv</code> client export</dd>
    </div>
  </dl>
  <p class="roster-empty" style="margin-top: 12px;">
    On 3.4.3 there is no <code>world.item_template</code> — TrinityCore reads item
    templates from the client's DB2 files, which is why the bundled CSV export is
    the source of truth for item names here.
  </p>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
