<?php
/**
 * Inline database-problem notice. Included at the bottom of the Armory
 * pages; prints nothing unless something went wrong AND 'debug' => true is
 * set in config.php, so visitors never see internal errors.
 */
$__dbErrors = dbErrors();
if (!$__dbErrors || empty($config['debug'])) {
    return;
}
?>
<div class="db-error-box">
  <strong>Database notices</strong> (shown because <code>debug</code> is enabled)
  <ul>
    <?php foreach ($__dbErrors as $__err): ?>
      <li><span><?= htmlspecialchars($__err['context']) ?></span> — <?= htmlspecialchars($__err['message']) ?></li>
    <?php endforeach; ?>
  </ul>
  <a href="armory-diagnostics.php">Run the Armory diagnostics →</a>
</div>
