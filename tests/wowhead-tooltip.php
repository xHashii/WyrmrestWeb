<?php
/**
 * Pure regression tests for the Wowhead tooltip fetcher/parser/cache.
 * No network, database, or real Wowhead access required: the responses are
 * fixture strings in the shapes the nether tooltip API returns (and the one
 * shape it must never return — a whole HTML item page).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../includes/bootstrap.php';

$work = sys_get_temp_dir() . '/wyrmrest-wowhead-' . bin2hex(random_bytes(6));
mkdir($work, 0700, true);
register_shutdown_function(static function () use ($work) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($work);
});
$config['cache_dir'] = $work . '/cache';
$checks = 0;
function wowheadCheck($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
    $GLOBALS['checks']++;
}

// Locale mapping: two-letter config locales -> numeric nether API ids.
wowheadCheck(0, wowheadTooltipNetherLocale('en'), 'English maps to locale id 0');
wowheadCheck(2, wowheadTooltipNetherLocale('fr'), 'French maps to locale id 2');
wowheadCheck(3, wowheadTooltipNetherLocale('de'), 'German maps to locale id 3');
wowheadCheck(6, wowheadTooltipNetherLocale('es'), 'Spanish maps to locale id 6');
wowheadCheck(7, wowheadTooltipNetherLocale('ru'), 'Russian maps to locale id 7');
wowheadCheck(1, wowheadTooltipNetherLocale('ko'), 'Korean maps to locale id 1');
wowheadCheck(4, wowheadTooltipNetherLocale('zh'), 'Chinese maps to locale id 4');
wowheadCheck(0, wowheadTooltipNetherLocale('en_US'), 'Region-tagged English still maps to id 0');
wowheadCheck(0, wowheadTooltipNetherLocale('pt'), 'Unsupported locales fall back to English');
wowheadCheck(0, wowheadTooltipNetherLocale(''), 'Empty locale falls back to English');

// A realistic nether-API tooltip payload: nested name/phase table, comment
// markers, q-class colours, a relative socket link, plain extra rows.
$tooltipHtml = <<<'HTML'
<table><tr><td><table><tr><td><!--nstart--><b class="q4">Rot-Resistant Breastplate</b><!--nend--></td><th><b class="q0 whtt-extra">Phase 4</b></th></tr></table><br><span class="q2">Heroic</span><span class="q"><br>Item Level <!--ilvl-->277</span><br>Binds when picked up<table><tr><td>Chest</td><th><span class="q1">Plate</span></th></tr></table><span><!--amr-->2756 Armor</span><br><span><!--stat7-->+139 Stamina</span><br><span><!--stat5-->+139 Intellect</span><br><a href="/wotlk/items/gems?filter=81;4;0" class="socket-blue q0">Blue Socket</a><br><span class="q0">Socket Bonus: +9 Spell Power</span><br>Durability 165 / 165</td></tr></table><table><tr><td>Requires Level <!--rlvl-->80<br><span class="q2">Equip: Improves critical strike rating by <!--rtg32-->106.</span><br><span class="q2">Equip: Increases spell power by <!--rtg45-->185.</span><div class="whtt-sellprice">Sell Price: <span class="moneygold">18</span> <span class="moneysilver">89</span> <span class="moneycopper">5</span></div><div class="whtt-extra whtt-droppedby">Dropped by: Rotface</div><div class="whtt-extra whtt-dropchance">Drop Chance: 2.95%</div></td></tr></table><!--i?50680:1:80:80-->
HTML;
$apiBody = json_encode([
    'name' => 'Rot-Resistant Breastplate',
    'quality' => 4,
    'icon' => 'inv_chest_plate22',
    'tooltip' => $tooltipHtml,
    'spells' => [],
]);
$parsed = parseWowheadTooltipBody($apiBody);
wowheadCheck(false, $parsed === null, 'A real nether tooltip JSON body parses');
wowheadCheck('Rot-Resistant Breastplate', $parsed['name'], 'Parsed tooltip keeps the name');
wowheadCheck('inv_chest_plate22', $parsed['icon'], 'Parsed tooltip keeps the icon');
wowheadCheck(4, $parsed['quality'], 'Parsed tooltip keeps the quality');
$html = $parsed['html'];
wowheadCheck(true, strpos($html, '<table') === 0, 'Sanitised fragment starts with the tooltip table');
wowheadCheck(true, strpos($html, 'Rot-Resistant Breastplate') !== false, 'Sanitised fragment keeps the item name');
wowheadCheck(true, strpos($html, 'Socket Bonus: +9 Spell Power') !== false, 'Sanitised fragment keeps socket bonus');
wowheadCheck(true, strpos($html, 'Drop Chance: 2.95%') !== false, 'Sanitised fragment keeps drop chance');
wowheadCheck(true, strpos($html, '<!--') === false, 'Comment markers are stripped from the fragment');
wowheadCheck(true, strpos($html, 'style=') === false && stripos($html, '<script') === false, 'Inline styles and scripts are stripped');
wowheadCheck(true, strpos($html, 'class="q4"') !== false, 'Quality colour class is preserved');
wowheadCheck(
    true,
    strpos($html, 'href="https://www.wowhead.com/wotlk/items/gems?filter=81;4;0"') !== false,
    'Relative /wotlk/ tooltip links are absolutised to wowhead.com'
);
wowheadCheck(true, looksLikeWowheadTooltipHtml($html), 'Sanitised fragment passes the shape guard');
wowheadCheck(true, looksLikeWowheadTooltipHtml('<table><tr><td>x</td></tr></table>'), 'Bare tooltip table passes the shape guard');

// Everything that is not a tooltip JSON body must be rejected outright —
// including whole HTML pages, which used to be "salvaged" and cached, so a
// gear tooltip ended up showing the full Wowhead item page.
foreach (['', 'not json at all', '{"error":"Entity type is invalid"}', '{"name":"No Tooltip Here"}',
    '{"tooltip":12345}', '{"tooltip":""}',
    '<!DOCTYPE html><html lang="en"><head><title>Rot-Resistant Breastplate - Item - WotLK</title><script>var x=1;</script></head><body><h1>Skip to Main Content</h1><p>This site makes extensive use of JavaScript.</p><table><tr><td>Quick Facts</td></tr></table><div class="comment">Comment by Theolol</div></body></html>',
] as $body) {
    wowheadCheck(null, parseWowheadTooltipBody($body), 'Non-tooltip bodies (pages, errors, garbage) parse to null');
}
wowheadCheck(false, looksLikeWowheadTooltipHtml('Skip to Main Content'), 'Page chrome text is not tooltip-shaped');
wowheadCheck(false, looksLikeWowheadTooltipHtml('<div><table>x</table></div>'), 'A wrapper div around a table is not tooltip-shaped');
wowheadCheck(false, looksLikeWowheadTooltipHtml('<table>' . str_repeat('<tr><td>x</td></tr>', 4000) . '</table>'), 'Runaway fragments are refused');

// Cache round-trip: a parsed tooltip is stored and read back...
$config2 = $config;
wowheadItemCacheWrite($config2, 50680, 'en', $parsed);
$cached = wowheadItemCacheRead($config2, 50680, 'en');
wowheadCheck(false, $cached === null || isset($cached['__skip']), 'Fresh cache entry reads back');
wowheadCheck($html, $cached['html'], 'Cached HTML survives the round-trip');
wowheadCheck(true, is_file(wowheadItemCachePath($config2, 50680, 'en')), 'Cache file is written under cache/wowhead/');

// ...a failed fetch is remembered briefly (back-off marker)...
wowheadItemCacheWrite($config2, 50681, 'en', null);
$skipped = wowheadItemCacheRead($config2, 50681, 'en');
wowheadCheck(['__skip' => true], $skipped, 'Failure marker is cached, not the tooltip');

// ...and entries from an older cache format — the ones that can hold the
// whole-page garbage from the original bug — are ignored and refetched.
$file = wowheadItemCachePath($config2, 50680, 'en');
$legacy = ['entry' => 50680, 'locale' => 'en', 'cached_at' => time(), 'ok' => true,
    'html' => 'Skip to Main Content<p>This site makes extensive use of JavaScript.</p><table><tr><td>Quick Facts</td></tr></table>'];
file_put_contents($file, json_encode($legacy));
wowheadCheck(null, wowheadItemCacheRead($config2, 50680, 'en'), 'Legacy-format cache entries are treated as missing');
$legacyPage = ['entry' => 50680, 'locale' => 'en', 'cached_at' => time(), 'fmt' => 2, 'ok' => true,
    'html' => 'Skip to Main Content<p>This site makes extensive use of JavaScript.</p><table><tr><td>Quick Facts</td></tr></table>'];
file_put_contents($file, json_encode($legacyPage));
wowheadCheck(null, wowheadItemCacheRead($config2, 50680, 'en'), 'Non-table cache fragments are treated as missing');

echo "PASS: wowhead tooltip fetch/parse/cache guards ({$checks} checks)\n";
