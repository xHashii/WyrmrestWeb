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

<div class="page-header talent-page-header">
  <h1>Talent Calculator</h1>
  <p>Plan Wrath of the Lich King 3.3.5 talent builds and glyph loadouts in a classic Wowhead-style layout. Nothing is saved — choices stay only for this page session.</p>
</div>

<noscript>
  <div class="msg error">The talent calculator needs JavaScript enabled.</div>
</noscript>

<div class="talent-shell" id="talent-calculator"
     data-initial-class="<?= htmlspecialchars($initialClass) ?>"
     data-data-url="data/talent-calculator.json">

  <section class="panel talent-toolbar" id="talent-toolbar" aria-label="Build summary">
    <div class="talent-toolbar-main">
      <div class="talent-overview-title" id="talent-current-class">Loading…</div>
      <div class="talent-overview-meta">
        <span class="talent-pill"><strong id="talent-total-points">0</strong><span class="talent-pill-dim">/71 spent</span></span>
        <span class="talent-pill">Level <strong id="talent-level">9</strong></span>
        <span class="talent-pill" id="talent-glyph-summary">0/6 glyphs</span>
        <span class="talent-pill is-accent" id="talent-remaining-points">71 left</span>
      </div>
    </div>
    <div class="talent-toolbar-actions">
      <button type="button" class="talent-action-btn" id="talent-refund-mode" aria-pressed="false" title="Toggle between spending and removing talent points">Spend Mode</button>
      <button type="button" class="talent-action-btn" id="talent-reset-build">Reset Build</button>
    </div>
    <div class="talent-progress" aria-hidden="true"><span class="talent-progress-fill" id="talent-progress-fill"></span></div>
  </section>

  <section class="panel talent-classbar">
    <div class="talent-class-picker" id="talent-class-picker" aria-label="Select a class"></div>
    <div class="talent-hint">Click a talent to spend a point · Right-click or Shift-click removes one · Glyph slots unlock at levels 15, 30 and 50</div>
  </section>

  <section class="talent-tree-grid-wrap" id="talent-tree-grid-wrap" aria-live="polite">
    <div class="panel talent-loading">Loading talent data…</div>
  </section>

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
</div>

<div class="talent-toast" id="talent-status" role="status" aria-live="polite"></div>

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
