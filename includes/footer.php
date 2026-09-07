  </div>

  <footer class="site-footer">
    <?= htmlspecialchars($config['server_name']) ?>
    <?php if (!empty($config['discord_url'])): ?>
      · <a href="<?= htmlspecialchars($config['discord_url']) ?>" target="_blank" rel="noopener">Discord</a>
    <?php endif; ?>
    <?php if (!empty($config['report_issue_url'])): ?>
      · <a href="<?= htmlspecialchars($config['report_issue_url']) ?>" target="_blank" rel="noopener">Report an issue</a>
    <?php endif; ?>
  </footer>

  <script>
    function copyRealmlist(btn) {
      const text = document.getElementById('realmlist-value').textContent;
      navigator.clipboard.writeText(text).then(() => {
        const original = btn.textContent;
        btn.textContent = 'Copied!';
        setTimeout(() => { btn.textContent = original; }, 1500);
      });
    }
  </script>
</body>
</html>
