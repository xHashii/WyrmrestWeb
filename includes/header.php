<?php
/**
 * Shared page header. The including page must set:
 *   $config     - already loaded by bootstrap.php
 *   $activePage - one of: home, register, online, leaderboard, info
 *   $pageTitle  - text shown in <title> (optional)
 */
$status = getServerStatus($config);

$navItems = [
    'home'        => ['label' => 'Home',        'href' => 'index.php'],
    'register'    => ['label' => 'Register',    'href' => 'register.php'],
    'online'      => ['label' => "Who's Online", 'href' => 'online.php'],
    'leaderboard' => ['label' => 'Leaderboard',  'href' => 'leaderboard.php'],
    'armory'      => ['label' => 'Armory',       'href' => 'armory.php'],
    'info'        => ['label' => 'Server Info',  'href' => 'info.php'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($config['server_name']) ?><?= isset($pageTitle) ? ' — ' . htmlspecialchars($pageTitle) : '' ?></title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700&family=Inter:wght@400;500;600;700&display=swap');

  :root {
    --bg: #0f0d09;
    --panel: #1b160f;
    --border: #4a3d24;
    --border-soft: #362c1c;
    --gold: #c9a24a;
    --gold-bright: #e8c876;
    --text: #ece3d0;
    --text-dim: #a89a7c;
    --text-faint: #766a52;
    --error: #a5493a;
    --error-bg: #2b1a15;
    --success: #7fa563;
    --success-bg: #1a2417;
    --online: #7fae5f;
    --offline: #b0503f;
    --shadow: 0 14px 36px -16px rgba(0, 0, 0, 0.6);
  }

  * { box-sizing: border-box; }

  body {
    margin: 0;
    min-height: 100vh;
    background:
      radial-gradient(ellipse 1000px 560px at 50% -12%, #241f16 0%, var(--bg) 55%);
    font-family: 'Inter', sans-serif;
    color: var(--text);
  }

  a { color: inherit; }

  /* ---- Nav ---- */
  .navbar {
    background: rgba(27, 22, 15, 0.92);
    border-bottom: 1px solid var(--border-soft);
    position: sticky;
    top: 0;
    z-index: 10;
    backdrop-filter: blur(6px);
  }

  .navbar-inner {
    max-width: 980px;
    margin: 0 auto;
    padding: 14px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
  }

  .brand {
    font-family: 'Cinzel', serif;
    font-weight: 700;
    font-size: 16px;
    color: var(--gold-bright);
    text-decoration: none;
    letter-spacing: 0.02em;
  }

  .nav-links {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
  }

  .nav-links a {
    text-decoration: none;
    font-size: 13px;
    color: var(--text-dim);
    padding: 7px 12px;
    border-radius: 4px;
    transition: color 0.15s, background 0.15s;
  }

  .nav-links a:hover { color: var(--text); background: rgba(255,255,255,0.03); }

  .nav-links a.active {
    color: var(--gold-bright);
    background: rgba(201, 162, 74, 0.1);
  }

  /* ---- Status bar ---- */
  .statusbar {
    background: #171310;
    border-bottom: 1px solid var(--border-soft);
  }

  .statusbar-inner {
    max-width: 980px;
    margin: 0 auto;
    padding: 10px 24px;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px 16px;
    font-size: 12px;
  }

  .pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
  }
  .pill.online { background: rgba(127, 174, 95, 0.14); color: var(--online); }
  .pill.offline { background: rgba(176, 80, 63, 0.14); color: var(--offline); }

  .pill .dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: currentColor;
  }
  .pill.online .dot { box-shadow: 0 0 0 3px rgba(127, 174, 95, 0.2); }

  .statusbar .divider {
    width: 1px;
    height: 14px;
    background: var(--border-soft);
  }

  .statusbar .chip {
    color: var(--text-dim);
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .statusbar .chip strong { color: var(--text); font-weight: 600; }

  .statusbar code {
    font-family: 'Inter', sans-serif;
    background: #12100b;
    border: 1px solid var(--border-soft);
    padding: 2px 7px;
    border-radius: 3px;
    font-size: 11px;
    color: var(--gold-bright);
  }

  .copy-btn {
    padding: 2px 8px;
    font-size: 10px;
    font-family: 'Inter', sans-serif;
    background: transparent;
    border: 1px solid var(--border);
    border-radius: 3px;
    color: var(--text-dim);
    cursor: pointer;
    transition: border-color 0.15s, color 0.15s;
  }
  .copy-btn:hover { border-color: var(--gold); color: var(--text); }

  .statusbar details {
    flex-basis: 100%;
    font-size: 11px;
  }
  .statusbar summary {
    cursor: pointer;
    color: var(--offline);
    list-style: none;
  }
  .statusbar summary::-webkit-details-marker { display: none; }
  .statusbar details p {
    margin: 8px 0 0;
    color: var(--text-dim);
    background: var(--error-bg);
    border: 1px solid var(--error);
    border-radius: 3px;
    padding: 8px 10px;
  }

  /* ---- Page wrapper ---- */
  .wrap {
    max-width: 980px;
    margin: 0 auto;
    padding: 44px 24px 72px;
  }

  .page-header {
    margin-bottom: 28px;
  }

  .page-header h1 {
    font-family: 'Cinzel', serif;
    font-size: 26px;
    font-weight: 700;
    color: var(--gold-bright);
    margin: 0 0 6px;
  }

  .page-header p {
    color: var(--text-dim);
    font-size: 14px;
    margin: 0;
  }

  /* ---- Generic panel ---- */
  .panel {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 28px 26px;
    box-shadow: var(--shadow);
  }

  .panel h2 {
    font-family: 'Cinzel', serif;
    font-size: 18px;
    font-weight: 700;
    letter-spacing: 0.02em;
    color: var(--gold-bright);
    margin: 0 0 18px;
  }

  .grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 22px;
  }

  @media (max-width: 760px) {
    .grid-2 { grid-template-columns: 1fr; }
  }

  /* ---- Registration form ---- */
  .form-card {
    position: relative;
    max-width: 460px;
    margin: 0 auto;
  }

  .form-card::before,
  .form-card::after {
    content: "";
    position: absolute;
    width: 14px;
    height: 14px;
    border: 2px solid var(--gold);
    top: 10px;
  }
  .form-card::before { left: 10px; border-right: none; border-bottom: none; }
  .form-card::after { right: 10px; border-left: none; border-bottom: none; }

  label {
    display: block;
    font-size: 13px;
    color: var(--text-dim);
    margin: 0 0 6px;
  }

  input[type="text"],
  input[type="password"] {
    width: 100%;
    padding: 11px 13px;
    margin-bottom: 18px;
    background: #12100b;
    border: 1px solid var(--border);
    border-radius: 3px;
    color: var(--text);
    font-size: 14px;
    font-family: inherit;
    transition: border-color 0.15s, box-shadow 0.15s;
  }

  input:focus {
    outline: none;
    border-color: var(--gold);
    box-shadow: 0 0 0 3px rgba(201, 162, 74, 0.14);
  }

  button[type="submit"] {
    width: 100%;
    padding: 13px;
    background: linear-gradient(180deg, var(--gold-bright), var(--gold));
    border: none;
    border-radius: 3px;
    color: #201b14;
    font-family: 'Cinzel', serif;
    font-weight: 700;
    font-size: 14px;
    letter-spacing: 0.04em;
    cursor: pointer;
    transition: filter 0.15s, transform 0.15s;
  }

  button[type="submit"]:hover { filter: brightness(1.08); transform: translateY(-1px); }

  .msg {
    border-radius: 3px;
    padding: 10px 14px;
    font-size: 13px;
    margin-bottom: 18px;
    line-height: 1.5;
  }

  .msg.error {
    background: var(--error-bg);
    border: 1px solid var(--error);
    color: #e0a89b;
  }

  .msg.success {
    background: var(--success-bg);
    border: 1px solid var(--success);
    color: #bcd6a5;
  }

  .msg ul { margin: 0; padding-left: 18px; }

  .hint {
    font-size: 12px;
    color: var(--text-faint);
    text-align: center;
    margin-top: 16px;
    margin-bottom: 0;
  }

  /* ---- Who's online ---- */
  .roster-card h2 {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
  }

  .roster-card h2 span {
    font-family: 'Inter', sans-serif;
    font-size: 12px;
    font-weight: 400;
    color: var(--text-faint);
  }

  .roster-empty {
    color: var(--text-faint);
    font-size: 13px;
    margin: 0;
  }

  .icon {
    width: 24px;
    height: 24px;
    border-radius: 3px;
    border: 1px solid var(--border-soft);
    image-rendering: pixelated;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.4);
  }

  .icon.icon-missing {
    color: transparent;
    background: var(--border-soft);
  }

  .roster-row {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    padding: 10px 0;
    border-bottom: 1px solid var(--border-soft);
  }
  .roster-row:last-child { border-bottom: none; }

  .roster-icons {
    display: flex;
    gap: 4px;
    flex: 0 0 auto;
    padding-top: 1px;
  }

  .roster-row-body {
    flex: 1 1 auto;
    min-width: 0;
  }

  .roster-row-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
  }

  .roster-row-top .name {
    font-weight: 600;
    color: var(--text);
    font-size: 13px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .level-pill {
    flex: 0 0 auto;
    font-size: 11px;
    color: var(--gold-bright);
    background: rgba(201, 162, 74, 0.12);
    border: 1px solid var(--border);
    padding: 1px 8px;
    border-radius: 10px;
    font-variant-numeric: tabular-nums;
  }

  .roster-row-sub {
    font-size: 11px;
    color: var(--text-faint);
    margin-top: 3px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .pager {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 16px;
    padding-top: 14px;
    border-top: 1px solid var(--border-soft);
  }

  .pager-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 26px;
    height: 26px;
    padding: 0 6px;
    border-radius: 3px;
    border: 1px solid var(--border-soft);
    color: var(--text-dim);
    font-size: 12px;
    text-decoration: none;
    transition: border-color 0.15s, color 0.15s;
  }

  .pager-link:hover { border-color: var(--gold); color: var(--text); }

  .pager-link.active {
    background: var(--gold);
    border-color: var(--gold);
    color: #201b14;
    font-weight: 600;
  }

  .pager-link.disabled {
    opacity: 0.35;
    pointer-events: none;
  }

  /* ---- Info panel ---- */
  dl.stats {
    margin: 0;
    padding: 0;
  }

  dl.stats div {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid var(--border-soft);
    font-size: 13px;
  }
  dl.stats div:last-child { border-bottom: none; }

  dl.stats dt {
    color: var(--text-faint);
    margin: 0;
  }

  dl.stats dd {
    margin: 0;
    color: var(--text);
    text-align: right;
    font-weight: 500;
  }

  .discord-link {
    display: inline-block;
    font-size: 13px;
    color: var(--gold-bright);
    text-decoration: none;
    border-bottom: 1px solid var(--border);
    padding-bottom: 1px;
    margin-top: 18px;
  }
  .discord-link:hover { border-color: var(--gold-bright); }

  /* ---- Leaderboard ---- */
  .lb-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0 20px;
  }

  @media (max-width: 480px) {
    .lb-grid { grid-template-columns: 1fr; }
  }

  .lb-row {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 8px 0;
    border-bottom: 1px solid var(--border-soft);
    font-size: 12px;
  }

  .rank-badge {
    flex: 0 0 22px;
    height: 22px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: var(--border-soft);
    color: var(--text-dim);
    font-size: 11px;
    font-weight: 700;
  }
  .rank-badge.r1 { background: linear-gradient(180deg, #f3d98a, #c9a24a); color: #201b14; }
  .rank-badge.r2 { background: linear-gradient(180deg, #d8d8d8, #a8a8a8); color: #201b14; }
  .rank-badge.r3 { background: linear-gradient(180deg, #d99a5b, #a9672e); color: #201b14; }

  .lb-icons {
    display: flex;
    gap: 3px;
    flex: 0 0 auto;
  }

  .lb-icons .icon { width: 20px; height: 20px; }

  .lb-name {
    flex: 1 1 auto;
    color: var(--text);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .lb-level {
    flex: 0 0 auto;
    color: var(--text-faint);
    font-variant-numeric: tabular-nums;
  }

  /* ---- Home hero + CTA cards ---- */
  .hero {
    text-align: center;
    margin-bottom: 40px;
  }

  .hero .eyebrow {
    font-size: 12px;
    color: var(--text-faint);
    letter-spacing: 0.1em;
    text-transform: uppercase;
  }

  .hero h1 {
    font-family: 'Cinzel', serif;
    font-size: 42px;
    font-weight: 700;
    letter-spacing: 0.01em;
    color: var(--gold-bright);
    margin: 10px 0 12px;
    text-shadow: 0 2px 20px rgba(201, 162, 74, 0.18);
  }

  .hero p {
    color: var(--text-dim);
    font-size: 15px;
    margin: 0 auto;
    max-width: 480px;
  }

  .cta-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
  }

  @media (max-width: 760px) {
    .cta-grid { grid-template-columns: 1fr 1fr; }
  }
  @media (max-width: 480px) {
    .cta-grid { grid-template-columns: 1fr; }
  }

  .cta-card {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 22px 18px;
    text-align: center;
    text-decoration: none;
    box-shadow: var(--shadow);
    transition: transform 0.15s, border-color 0.15s;
    display: block;
  }

  .cta-card:hover {
    transform: translateY(-2px);
    border-color: var(--gold);
  }

  .cta-card .cta-icon {
    font-size: 22px;
    margin-bottom: 10px;
  }

  .cta-card .cta-title {
    font-family: 'Cinzel', serif;
    font-size: 14px;
    font-weight: 700;
    color: var(--gold-bright);
    margin-bottom: 6px;
  }

  .cta-card .cta-desc {
    font-size: 12px;
    color: var(--text-faint);
    line-height: 1.4;
  }

  .cta-card.primary {
    background: linear-gradient(180deg, rgba(201,162,74,0.14), rgba(201,162,74,0.04));
    border-color: var(--gold);
  }

  /* ---- Home stats strip ---- */
  .stats-strip {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-bottom: 28px;
  }

  @media (max-width: 600px) {
    .stats-strip { grid-template-columns: 1fr; }
  }

  .stat-card {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 18px 20px;
    text-align: center;
    box-shadow: var(--shadow);
  }

  .stat-card .stat-value {
    font-family: 'Cinzel', serif;
    font-size: 26px;
    font-weight: 700;
    color: var(--gold-bright);
  }

  .stat-card .stat-label {
    font-size: 12px;
    color: var(--text-faint);
    margin-top: 4px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
  }

  /* ---- Population breakdown bar ---- */
  .pop-bar {
    display: flex;
    height: 10px;
    border-radius: 5px;
    overflow: hidden;
    margin-bottom: 14px;
    background: var(--border-soft);
  }

  .pop-bar-seg {
    height: 100%;
  }

  .pop-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 6px 16px;
    margin-bottom: 22px;
    padding-bottom: 20px;
    border-bottom: 1px solid var(--border-soft);
  }

  .pop-legend-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    color: var(--text-faint);
  }

  .pop-legend-swatch {
    width: 9px;
    height: 9px;
    border-radius: 2px;
    flex-shrink: 0;
  }

  /* ---- Connect steps ---- */
  .connect-steps {
    list-style: none;
    counter-reset: step;
    margin: 0 0 4px;
    padding: 0;
  }

  .connect-steps li {
    counter-increment: step;
    display: flex;
    gap: 14px;
    padding: 14px 0;
    border-bottom: 1px solid var(--border-soft);
  }
  .connect-steps li:last-child { border-bottom: none; }

  .connect-steps li::before {
    content: counter(step);
    flex: 0 0 26px;
    height: 26px;
    border-radius: 50%;
    background: rgba(201, 162, 74, 0.12);
    border: 1px solid var(--border);
    color: var(--gold-bright);
    font-size: 12px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .connect-steps .step-title {
    font-weight: 600;
    color: var(--text);
    font-size: 13px;
    margin-bottom: 3px;
  }

  .connect-steps .step-desc {
    font-size: 12px;
    color: var(--text-dim);
    line-height: 1.5;
  }

  footer.site-footer {
    text-align: center;
    padding: 24px;
    font-size: 12px;
    color: var(--text-faint);
    border-top: 1px solid var(--border-soft);
  }

  footer.site-footer a {
    color: var(--text-dim);
    text-decoration: none;
    border-bottom: 1px solid var(--border-soft);
  }
  footer.site-footer a:hover { color: var(--gold-bright); border-color: var(--gold-bright); }

  /* ---- Armory embed ---- */
  .armory-frame-wrap {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 6px;
    box-shadow: var(--shadow);
    overflow: hidden;
  }

  .armory-frame-wrap iframe {
    display: block;
    width: 100%;
    height: 78vh;
    min-height: 520px;
    border: 0;
    background: #fff;
  }

  /* ---- Armory: search ---- */
  .armory-search {
    display: flex;
    gap: 10px;
    margin-bottom: 4px;
    align-items: stretch;
  }

  .armory-search input[type="text"] {
    margin-bottom: 0;
    flex: 1 1 auto;
    width: auto;
    min-width: 0;
  }

  .armory-search select {
    flex: 0 0 auto;
    background: #12100b;
    border: 1px solid var(--border);
    border-radius: 3px;
    color: var(--text);
    font-size: 14px;
    font-family: inherit;
    padding: 0 10px;
    height: auto;
  }

  .armory-search button {
    flex: 0 0 auto;
    width: auto;
    padding: 0 20px;
    background: linear-gradient(180deg, var(--gold-bright), var(--gold));
    border: none;
    border-radius: 3px;
    color: #201b14;
    font-family: 'Cinzel', serif;
    font-weight: 700;
    font-size: 13px;
    white-space: nowrap;
    cursor: pointer;
    transition: filter 0.15s, transform 0.15s;
  }

  .armory-search button:hover {
    filter: brightness(1.08);
    transform: translateY(-1px);
  }

  .search-result-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 0;
    border-bottom: 1px solid var(--border-soft);
    text-decoration: none;
    color: var(--text);
  }
  .search-result-row:last-child { border-bottom: none; }
  .search-result-row:hover .name { color: var(--gold-bright); }

  .search-result-row .name { font-weight: 600; font-size: 13px; }
  .search-result-row .meta { font-size: 12px; color: var(--text-faint); }

  /* ---- Armory: character profile ---- */
  .char-header {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-bottom: 22px;
    padding-bottom: 20px;
    border-bottom: 1px solid var(--border-soft);
  }

  .char-header .icon { width: 40px; height: 40px; border-radius: 4px; }

  .char-header h1 {
    font-family: 'Cinzel', serif;
    font-size: 22px;
    font-weight: 700;
    color: var(--gold-bright);
    margin: 0 0 4px;
  }

  .char-header .char-sub {
    font-size: 13px;
    color: var(--text-dim);
  }

  .char-header .char-sub a { color: var(--gold-bright); text-decoration: none; border-bottom: 1px solid var(--border); }

  .equip-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0 24px;
  }

  @media (max-width: 600px) {
    .equip-grid { grid-template-columns: 1fr; }
  }

  .equip-row {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding: 8px 0;
    border-bottom: 1px solid var(--border-soft);
    font-size: 13px;
  }

  .equip-row .slot-label { color: var(--text-faint); flex: 0 0 auto; }
  .equip-row .item-name { text-align: right; font-weight: 500; }
  .equip-row .item-name.empty { color: var(--text-faint); font-weight: 400; font-style: italic; }

  /* ---- Armory: guild ---- */
  .guild-header {
    margin-bottom: 22px;
    padding-bottom: 20px;
    border-bottom: 1px solid var(--border-soft);
  }

  .guild-header h1 {
    font-family: 'Cinzel', serif;
    font-size: 22px;
    font-weight: 700;
    color: var(--gold-bright);
    margin: 0 0 6px;
  }

  .guild-header .char-sub { font-size: 13px; color: var(--text-dim); }

  table.guild-roster {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
  }

  table.guild-roster th {
    text-align: left;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text-faint);
    padding: 0 10px 8px;
    border-bottom: 1px solid var(--border-soft);
  }

  table.guild-roster td {
    padding: 9px 10px;
    border-bottom: 1px solid var(--border-soft);
    vertical-align: middle;
  }

  table.guild-roster tr:last-child td { border-bottom: none; }

  table.guild-roster a { color: var(--text); text-decoration: none; font-weight: 500; }
  table.guild-roster a:hover { color: var(--gold-bright); }

  .status-dot {
    display: inline-block;
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--offline);
  }
  .status-dot.online { background: var(--online); }

  /* ---- Armory: search summary, item levels, diagnostics ---- */
  .armory-search-field {
    position: relative;
    flex: 1 1 auto;
    min-width: 0;
  }

  .armory-search-field input[type="text"] { width: 100%; }

  .suggest-box {
    position: absolute;
    z-index: 30;
    left: 0;
    right: 0;
    top: calc(100% + 4px);
    max-height: 320px;
    overflow-y: auto;
    background: #12100b;
    border: 1px solid var(--border);
    border-radius: 4px;
    box-shadow: var(--shadow);
  }

  .suggest-row {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 8px 12px;
    text-decoration: none;
    color: var(--text);
    border-bottom: 1px solid var(--border-soft);
  }
  .suggest-row:last-child { border-bottom: none; }
  .suggest-row:hover, .suggest-row.active { background: #1d1810; }
  .suggest-row:hover .name, .suggest-row.active .name { color: var(--gold-bright); }
  .suggest-row .icon { width: 18px; height: 18px; border-radius: 3px; }
  .suggest-row .name { font-size: 13px; font-weight: 600; }
  .suggest-row .meta { margin-left: auto; font-size: 11px; color: var(--text-faint); }

  .search-summary {
    margin: 16px 0 2px;
    font-size: 12px;
    color: var(--text-faint);
  }

  .equip-row .item-ilvl {
    display: inline-block;
    margin-left: 6px;
    padding: 1px 5px;
    border: 1px solid var(--border-soft);
    border-radius: 3px;
    font-size: 11px;
    color: var(--text-faint);
    vertical-align: middle;
  }

  .db-error-box {
    margin-top: 18px;
    padding: 12px 14px;
    background: var(--error-bg);
    border: 1px solid var(--error);
    border-radius: 4px;
    font-size: 12px;
    color: var(--text-dim);
  }
  .db-error-box strong { color: var(--text); }
  .db-error-box ul { margin: 8px 0 8px 18px; padding: 0; }
  .db-error-box li { margin-bottom: 4px; }
  .db-error-box li span { color: var(--gold-bright); }
  .db-error-box a { color: var(--gold-bright); text-decoration: none; }

  .diag-table td { font-size: 12px; vertical-align: top; }
  .diag-table code { font-size: 12px; }

  .diag-pill {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.04em;
  }
  .diag-pill.ok   { background: rgba(127, 165, 99, 0.15); color: var(--success); border: 1px solid var(--success); }
  .diag-pill.warn { background: rgba(201, 162, 74, 0.12); color: var(--gold-bright); border: 1px solid var(--gold); }
  .diag-pill.fail { background: var(--error-bg); color: #e08a7a; border: 1px solid var(--error); }

  .diag-hint { margin-top: 4px; color: var(--text-faint); font-size: 11px; }
  .diag-legend dd code { font-size: 11px; }

  .roster-row .name a, .lb-name a { color: inherit; text-decoration: none; }
  .roster-row .name a:hover, .lb-name a:hover { color: var(--gold-bright); }
</style>
</head>
<body>
  <nav class="navbar">
    <div class="navbar-inner">
      <a class="brand" href="index.php"><?= htmlspecialchars($config['server_name']) ?></a>
      <div class="nav-links">
        <?php foreach ($navItems as $key => $item): ?>
          <a href="<?= $item['href'] ?>" class="<?= $activePage === $key ? 'active' : '' ?>"><?= htmlspecialchars($item['label']) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  </nav>

  <div class="statusbar">
    <div class="statusbar-inner">
      <span class="pill <?= $status['online'] ? 'online' : 'offline' ?>">
        <span class="dot"></span>
        <?= $status['online'] ? 'Realm Online' : 'Realm Offline' ?>
      </span>

      <?php if ($status['online'] && $status['uptime']): ?>
        <span class="chip">uptime <strong><?= htmlspecialchars($status['uptime']) ?></strong></span>
      <?php endif; ?>

      <?php if ($status['online'] && $status['players'] !== null): ?>
        <span class="divider"></span>
        <span class="chip"><strong><?= (int) $status['players'] ?></strong> players online</span>
      <?php endif; ?>

      <span class="divider"></span>
      <span class="chip">
        realmlist <code id="realmlist-value"><?= htmlspecialchars($config['realmlist']) ?></code>
        <button type="button" class="copy-btn" onclick="copyRealmlist(this)">Copy</button>
      </span>

      <?php if (!$status['online'] && $status['error'] && !empty($config['debug'])): ?>
        <details>
          <summary>Connection details</summary>
          <p><?= htmlspecialchars($status['error']) ?></p>
        </details>
      <?php endif; ?>
    </div>
  </div>

  <div class="wrap">
