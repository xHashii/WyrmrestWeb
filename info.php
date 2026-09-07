<?php
require __DIR__ . '/includes/bootstrap.php';

$activePage = 'info';
$pageTitle = 'Server Info';
require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <h1>Server Info</h1>
  <p>Everything you need to connect and get help.</p>
</div>

<div class="panel" style="margin-bottom: 22px;">
  <h2>How to Connect</h2>
  <ol class="connect-steps">
    <?php if (!empty($config['client_download_url'])): ?>
      <li>
        <div>
          <div class="step-title">Get the client</div>
          <div class="step-desc"><a class="discord-link" style="margin:0;" href="<?= htmlspecialchars($config['client_download_url']) ?>" target="_blank" rel="noopener">Download it here</a></div>
        </div>
      </li>
    <?php else: ?>
      <li>
        <div>
          <div class="step-title">Get the client</div>
          <div class="step-desc">Use the client for <?= htmlspecialchars($config['expansion']) ?> — ask in the Discord below if you're not sure which one.</div>
        </div>
      </li>
    <?php endif; ?>
    <li>
      <div>
        <div class="step-title">Set your realmlist</div>
        <div class="step-desc">Edit <code>realmlist.wtf</code> in your client's Data folder so it points to <code><?= htmlspecialchars($config['realmlist']) ?></code>.</div>
      </div>
    </li>
    <li>
      <div>
        <div class="step-title">Create an account</div>
        <div class="step-desc">If you haven't already, <a class="discord-link" style="margin:0;" href="register.php">register here</a>.</div>
      </div>
    </li>
    <li>
      <div>
        <div class="step-title">Launch and log in</div>
        <div class="step-desc">Start the client and log in with the email and password you registered.</div>
      </div>
    </li>
  </ol>
</div>

<div class="grid-2">
  <div class="panel">
    <h2>Connect</h2>
    <dl class="stats">
      <div>
        <dt>Realmlist</dt>
        <dd>
          <code id="realmlist-value-info"><?= htmlspecialchars($config['realmlist']) ?></code>
          <button type="button" class="copy-btn" onclick="copyRealmlistInfo(this)">Copy</button>
        </dd>
      </div>
      <div>
        <dt>Expansion</dt>
        <dd><?= htmlspecialchars($config['expansion']) ?></dd>
      </div>
      <div>
        <dt>Rates</dt>
        <dd><?= htmlspecialchars($config['rates_summary']) ?></dd>
      </div>
    </dl>
  </div>

  <div class="panel">
    <h2>Community</h2>
    <dl class="stats">
      <?php if (!empty($config['discord_url'])): ?>
        <div>
          <dt>Discord</dt>
          <dd><a class="discord-link" style="margin:0;" href="<?= htmlspecialchars($config['discord_url']) ?>" target="_blank" rel="noopener">Join the Discord →</a></dd>
        </div>
      <?php endif; ?>
      <?php if (!empty($config['report_issue_url'])): ?>
        <div>
          <dt>Support</dt>
          <dd><a class="discord-link" style="margin:0;" href="<?= htmlspecialchars($config['report_issue_url']) ?>" target="_blank" rel="noopener">Report issue or bugs</a></dd>
        </div>
      <?php endif; ?>
    </dl>
  </div>
</div>

<script>
  function copyRealmlistInfo(btn) {
    const text = document.getElementById('realmlist-value-info').textContent;
    navigator.clipboard.writeText(text).then(() => {
      const original = btn.textContent;
      btn.textContent = 'Copied!';
      setTimeout(() => { btn.textContent = original; }, 1500);
    });
  }
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
