<?php
require __DIR__ . '/includes/bootstrap.php';

$initialClass = strtolower(trim((string) ($_GET['class'] ?? 'warrior')));
if (!preg_match('/^[a-z]+$/', $initialClass)) {
    $initialClass = 'warrior';
}

$activePage = 'talents';
$pageTitle = 'Talent Calculator';
$pageStylesheets = ['assets/talent.css'];
$bodyClass = 'talent-page';
require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <h1>Talent Calculator</h1>
  <p>Plan Wrath of the Lich King 3.3.5 talent builds and glyph loadouts in a classic Wowhead-style layout. Nothing is saved — choices stay only for this page session.</p>
</div>

<noscript>
  <div class="msg error">The talent calculator needs JavaScript enabled.</div>
</noscript>

<div class="talent-shell" id="talent-calculator"
     data-initial-class="<?= htmlspecialchars($initialClass) ?>"
     data-data-url="data/talent-calculator.json">
  <section class="panel talent-controls-panel">
    <div class="talent-toolbar">
      <div>
        <div class="talent-kicker">WotLK 3.3.5a</div>
        <div class="talent-overview-title" id="talent-current-class">Loading…</div>
        <div class="talent-overview-meta">
          <span><strong id="talent-total-points">0</strong>/71 points spent</span>
          <span>•</span>
          <span>Level <strong id="talent-level">9</strong></span>
          <span>•</span>
          <span id="talent-glyph-summary">0/6 glyph slots unlocked</span>
          <span>•</span>
          <span id="talent-remaining-points">71 points remaining</span>
        </div>
      </div>
      <div class="talent-toolbar-actions">
        <button type="button" class="talent-action-btn" id="talent-refund-mode" aria-pressed="false">Spend Mode</button>
        <button type="button" class="talent-action-btn" id="talent-reset-build">Reset Build</button>
      </div>
    </div>

    <div class="talent-class-picker" id="talent-class-picker" aria-label="Select a class"></div>

    <div class="talent-status" id="talent-status" role="status" aria-live="polite">
      Click a talent to spend a point. Right-click, Shift-click, or use Refund Mode to remove one.
    </div>
  </section>

  <section class="talent-summary-grid" id="talent-summary-grid" aria-label="Talent tree summary"></section>

  <section class="panel glyphs-panel">
    <div class="glyphs-header">
      <div>
        <h2>Glyphs</h2>
        <p>Major and minor glyph slots unlock in pairs at levels 15, 30, and 50. Glyph choices are local to this page only.</p>
      </div>
      <div class="talent-toolbar-actions">
        <button type="button" class="talent-action-btn" id="talent-clear-glyphs">Clear Glyphs</button>
      </div>
    </div>

    <div class="glyph-columns">
      <section class="glyph-column" aria-labelledby="glyph-major-heading">
        <div class="glyph-column-title" id="glyph-major-heading">Major Glyphs</div>
        <div class="glyph-slot-list" id="talent-major-glyphs"></div>
      </section>

      <section class="glyph-column" aria-labelledby="glyph-minor-heading">
        <div class="glyph-column-title" id="glyph-minor-heading">Minor Glyphs</div>
        <div class="glyph-slot-list" id="talent-minor-glyphs"></div>
      </section>
    </div>
  </section>

  <section class="talent-tree-grid-wrap" id="talent-tree-grid-wrap" aria-live="polite">
    <div class="panel talent-loading">Loading talent data…</div>
  </section>
</div>

<div class="talent-tooltip" id="talent-tooltip" hidden></div>

<div class="glyph-picker-overlay" id="glyph-picker-overlay" hidden>
  <div class="glyph-picker" role="dialog" aria-modal="true" aria-labelledby="glyph-picker-title">
    <div class="glyph-picker-head">
      <div>
        <div class="glyph-picker-title" id="glyph-picker-title">Select a Glyph</div>
        <div class="glyph-picker-subtitle" id="glyph-picker-subtitle"></div>
      </div>
      <button type="button" class="talent-action-btn" id="glyph-picker-close">Close</button>
    </div>

    <div class="glyph-picker-actions">
      <button type="button" class="talent-action-btn" id="glyph-picker-clear">Clear Slot</button>
    </div>

    <div class="glyph-picker-list" id="glyph-picker-list"></div>
  </div>
</div>

<script src="assets/talent.js"></script>

<?php require __DIR__ . '/includes/footer.php'; ?>
