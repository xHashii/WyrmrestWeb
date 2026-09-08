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

      <?php if ($trace['inventory'] !== null): ?>
        <?php $saved = $trace['inventory']; ?>
        <h2 style="margin-top: 24px;">Saved equipment sources</h2>
        <dl class="stats diag-legend">
          <div><dt>character_inventory</dt><dd><?= htmlspecialchars($saved['status']['inventory']) ?> · <?= $saved['integrity']['rows'] ?> inventory rows</dd></div>
          <div><dt>item_instance links</dt><dd><?= $saved['integrity']['missing_instance'] ?> missing/unreadable · <?= $saved['integrity']['owner_mismatch'] ?> owner mismatches</dd></div>
          <div><dt>equipmentCache</dt><dd><?= htmlspecialchars($saved['status']['cache']) ?> · <?= $saved['integrity']['cache_slots'] ?> saved appearances · <?= $saved['integrity']['cache_fallback'] ?> used as fallback</dd></div>
        </dl>
        <p class="roster-empty" style="margin-top: 12px;">The cache contains appearance/display IDs, not item IDs. It can show how gear looked at the last character save, but cannot identify the exact item or its stats. The website never writes inventory data; log out in-game and refresh to check a new save.</p>
      <?php endif; ?>
      <h2 style="margin-top: 24px;">Equipped items and appearances</h2>
      <?php if (!$trace['equipment']): ?>
        <p class="roster-empty">
          No equipped items or cached appearances could be displayed. This is not
          proof that the character has no gear in-game; check the saved sources above.
        </p>
      <?php else: ?>
        <table class="guild-roster diag-table">
          <thead>
            <tr><th>slot</th><th>itemEntry</th><th>display ID</th><th>name / appearance</th><th>read from</th></tr>
          </thead>
          <tbody>
            <?php foreach ($trace['equipment'] as $item): ?>
              <tr>
                <td><?= htmlspecialchars($item['slot']) ?></td>
                <td><?= $item['entry'] > 0 ? $item['entry'] : 'Unknown' ?></td>
                <td><?= $item['display_id'] > 0 ? $item['display_id'] : '—' ?></td>
                <td><?= htmlspecialchars($item['name']) ?></td>
                <td>
                  <span class="diag-pill <?= $item['equipment_source'] === 'equipment-cache' ? 'warn' : ($item['source'] === 'unresolved' ? 'fail' : 'ok') ?>">
                    <?= htmlspecialchars($item['equipment_source']) ?>
                  </span>
                  <div class="diag-hint"><?= htmlspecialchars($item['source']) ?></div>
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
      <dd><code><?= htmlspecialchars($config['db_name']) ?></code> —
          <code>characters.guid</code> = <code>character_inventory.guid</code>,
          <code>character_inventory.item</code> = <code>item_instance.guid</code></dd>
    </div>
    <div>
      <dt>Slot map (3.4.3)</dt>
      <dd>bag 0: 0-18 equipped · 19-29 profession · 30-33 bags · 34 reagent bag ·
          35-58 backpack · 59+ bank/buyback (not shown). Any other
          <code>bag</code> value is the item guid of the container the item is in.</dd>
    </div>
    <div>
      <dt>Saved character appearance</dt>
      <dd><code>characters.equipmentCache</code> — fallback only. On this core: 34 slots, 5 values each; display IDs are resolved through <code>ItemAppearance</code>, never treated as item IDs.</dd>
    </div>
    <div>
      <dt>Item icons</dt>
      <dd><code>Item</code> + <code>ItemModifiedAppearance</code> + <code>ItemAppearance</code> CSVs supply icon FileDataIDs and model display IDs. Local <code>images/items/&lt;FileDataID&gt;.png</code> files take priority over remote icons.</dd>
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
