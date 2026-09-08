<?php
/**
 * Item stats pulled straight from Wowhead.
 *
 * The character page's gear tooltip used to show only a "View on Wowhead"
 * link. This module fetches Wowhead's *own* item tooltip — the exact stat
 * block, equip effects and colour coding players see on the site — and
 * renders it inside our tooltip. We use the JSON tooltip API that serves the
 * Wowhead sites themselves (nether.wowhead.com/wotlk/tooltip/item/<id>):
 * it returns just the tooltip markup for the item, never the surrounding
 * item page, so a gear tooltip can never end up showing the page's
 * comments, screenshots or other chrome. The tooltip markup is sanitised
 * down to a safe subset and cached on disk so a character page never has to
 * hit Wowhead more than once per item.
 *
 * Everything fails soft: no cURL, no network, a rate limit or a changed
 * endpoint simply means the tooltip falls back to the name / quality / item
 * level we already resolve from the DB2 export — the page never breaks, it
 * just omits the extra stats.
 */

/** Two-letter locale used for Wowhead tooltips (default English). */
function wowheadItemLocale(array $config): string
{
    $locale = (string) ($config['wowhead_locale'] ?? 'en');
    return preg_match('/^[a-z]{2}(?:_[a-z]{2})?$/', $locale) ? $locale : 'en';
}

/**
 * Numeric locale id for the nether tooltip API. Wowhead serves the WotLK
 * tooltips in a handful of languages; anything outside that set falls back
 * to English (id 0).
 */
function wowheadTooltipNetherLocale(string $locale): int
{
    $ids = ['en' => 0, 'fr' => 2, 'de' => 3, 'es' => 6, 'ru' => 7, 'ko' => 1, 'zh' => 4];
    return $ids[strtolower(substr($locale, 0, 2))] ?? 0;
}

/**
 * Cache format version. Bumped whenever the stored shape or the remote
 * markup changes: entries written by an older format are ignored and
 * fetched again instead of lingering for the whole TTL. This is what retires
 * bad cached tooltips — for example a whole Wowhead page saved by an earlier
 * bug — without waiting for the cache to expire.
 *
 * v3: the sanitizer now keeps Wowhead's money- and socket- class tokens (so
 * the sell-price coins and socket gems render) and drops the "Phase N"
 * title-row marker; older entries were sanitised without either and are
 * refetched.
 */
const WOWHEAD_CACHE_FMT = 3;

/** Cache lives under cache/wowhead/ so it piggy-backs on the writable cache dir. */
function wowheadItemCacheDir(array $config): string
{
    return itemCacheDir($config) . '/wowhead';
}

function wowheadItemCachePath(array $config, int $entry, string $locale): string
{
    $safe = preg_replace('/[^a-z_]/', '', $locale) ?: 'en';
    return wowheadItemCacheDir($config) . '/item-' . $entry . '-' . $safe . '.json';
}

/**
 * Read a cached tooltip. Returns:
 *   - an array with 'ok' => true  when a fresh, usable tooltip is cached
 *   - ['__skip' => true]           when a recent fetch already failed (back off)
 *   - null                         when there is nothing cached (go fetch)
 *
 * Entries written by an older cache format — or whose stored fragment is not
 * a tooltip-shaped table — are treated as missing, so the next page load
 * simply refetches them.
 */
function wowheadItemCacheRead(array $config, int $entry, string $locale): ?array
{
    $file = wowheadItemCachePath($config, $entry, $locale);
    if (!is_file($file)) {
        return null;
    }
    $data = json_decode((string) @file_get_contents($file), true);
    if (!is_array($data) || !isset($data['cached_at'])) {
        return null;
    }
    $age = time() - (int) $data['cached_at'];
    $ttl = (int) ($config['wowhead_cache_ttl'] ?? 86400 * 30);
    $failTtl = (int) ($config['wowhead_fail_ttl'] ?? 3600);

    if (!empty($data['ok'])) {
        $fresh = $age < $ttl && (int) ($data['fmt'] ?? 0) === WOWHEAD_CACHE_FMT;
        $shaped = is_string($data['html'] ?? null) && looksLikeWowheadTooltipHtml($data['html']);
        return $fresh && $shaped ? $data : null;
    }
    return $age < $failTtl ? ['__skip' => true] : null;
}

/** Persist a tooltip (or a failure marker) to disk. */
function wowheadItemCacheWrite(array $config, int $entry, string $locale, ?array $data): void
{
    $dir = wowheadItemCacheDir($config);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return;
    }
    if (!is_writable($dir)) {
        return;
    }
    $file = wowheadItemCachePath($config, $entry, $locale);
    $out = ['entry' => $entry, 'locale' => $locale, 'cached_at' => time(), 'fmt' => WOWHEAD_CACHE_FMT, 'ok' => $data !== null];
    if ($data) {
        $out['name'] = $data['name'] ?? null;
        $out['icon'] = $data['icon'] ?? null;
        $out['quality'] = $data['quality'] ?? null;
        $out['html'] = $data['html'];
    }
    @file_put_contents($file, json_encode($out));
}

/**
 * Single-item lookup. Cached on disk; one network round-trip the first time,
 * instant afterwards. Returns the cached array (with 'html') or null.
 */
function getWowheadItemTooltip(array $config, int $entry): ?array
{
    if ($entry <= 0 || empty($config['wowhead_tooltips'])) {
        return null;
    }
    $locale = wowheadItemLocale($config);
    $cached = wowheadItemCacheRead($config, $entry, $locale);
    if (isset($cached['__skip'])) {
        return null;
    }
    if ($cached) {
        return $cached;
    }
    $parsed = fetchWowheadTooltipRemote($config, $entry, $locale);
    wowheadItemCacheWrite($config, $entry, $locale, $parsed);
    return $parsed;
}

/**
 * Batch lookup used by the character page. Resolves every cached item first,
 * then fetches the rest in parallel with curl_multi so a fresh character page
 * warms all of its gear in a single wall-clock pass instead of 19 serial
 * requests. Returns entry => cached tooltip array.
 */
function getWowheadItemTooltips(array $config, array $entries): array
{
    if (empty($config['wowhead_tooltips'])) {
        return [];
    }
    $entries = array_values(array_unique(array_filter(array_map('intval', $entries))));
    if (!$entries) {
        return [];
    }
    $locale = wowheadItemLocale($config);
    $found = [];
    $miss = [];
    foreach ($entries as $entry) {
        $cached = wowheadItemCacheRead($config, $entry, $locale);
        if (isset($cached['__skip'])) {
            continue;
        }
        if ($cached) {
            $found[$entry] = $cached;
        } else {
            $miss[] = $entry;
        }
    }
    if ($miss) {
        $fetched = fetchWowheadTooltipsRemoteMulti($config, $miss, $locale);
        foreach ($miss as $entry) {
            $data = $fetched[$entry] ?? null;
            wowheadItemCacheWrite($config, $entry, $locale, $data);
            if ($data) {
                $found[$entry] = $data;
            }
        }
    }
    return $found;
}

/** Convenience wrapper: one item via the parallel fetcher. */
function fetchWowheadTooltipRemote(array $config, int $entry, string $locale): ?array
{
    $multi = fetchWowheadTooltipsRemoteMulti($config, [$entry], $locale);
    return $multi[$entry] ?? null;
}

/**
 * Fetch several Wowhead tooltips at once. Returns entry => parsed tooltip
 * array (only the entries that succeeded). Returns [] when cURL is missing.
 *
 * Requests go to the JSON tooltip API the Wowhead sites themselves use
 * (nether.wowhead.com/wotlk/tooltip/item/<id>?locale=<n>). It needs no AJAX
 * headers and — unlike an item *page* — it never contains anything beyond
 * the tooltip itself, which is what makes the response safe to render.
 */
function fetchWowheadTooltipsRemoteMulti(array $config, array $entries, string $locale): array
{
    if (!function_exists('curl_init') || !function_exists('curl_multi_init') || empty($entries)) {
        return [];
    }

    $ua = 'Mozilla/5.0 (compatible; WyrmrestWeb/' . (int) ($config['realm_id'] ?? 1)
        . '; +https://github.com/xHashii/WyrmrestWeb)';

    $mh = curl_multi_init();
    $handles = [];
    foreach ($entries as $entry) {
        $url = 'https://nether.wowhead.com/wotlk/tooltip/item/' . $entry
            . '?locale=' . wowheadTooltipNetherLocale($locale);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) ($config['wowhead_timeout'] ?? 10),
            CURLOPT_CONNECTTIMEOUT => (int) ($config['wowhead_connect_timeout'] ?? 4),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json, text/plain, */*',
                'User-Agent: ' . $ua,
            ],
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$entry] = $ch;
    }

    $running = null;
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running > 0) {
            curl_multi_select($mh, 1.0);
        }
    } while ($running > 0 && $status === CURLM_OK);

    $out = [];
    foreach ($handles as $entry => $ch) {
        $body = (string) curl_multi_getcontent($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        if ($http === 200) {
            $parsed = parseWowheadTooltipBody($body);
            if ($parsed) {
                $out[$entry] = $parsed;
            }
        }
    }
    curl_multi_close($mh);
    return $out;
}

/**
 * Turn a Wowhead tooltip API response body into a tidy tooltip array.
 *
 * The nether API answers with JSON — { name, quality, icon, tooltip: "<html>" }
 * or { error: ... } for unknown entries — and we only ever accept that exact
 * JSON shape. Anything else (an HTML page, an error page, an empty reply)
 * returns null, which the caller treats as "no tooltip": the gear slot falls
 * back to the name / quality / item level it already knows. This strictness
 * matters: a whole Wowhead item page with comments, screenshots and related
 * links must never be mistaken for a tooltip and rendered (or cached)
 * inside a gear slot.
 */
function parseWowheadTooltipBody(string $body): ?array
{
    $body = trim($body);
    if ($body === '') {
        return null;
    }

    $json = json_decode($body, true);
    if (!is_array($json) || isset($json['error'])) {
        return null;
    }
    $tooltip = $json['tooltip'] ?? null;
    if (!is_string($tooltip) || trim($tooltip) === '') {
        return null;
    }

    $html = sanitizeWowheadTooltipHtml($tooltip);
    if (!looksLikeWowheadTooltipHtml($html)) {
        return null;
    }

    return [
        'name' => isset($json['name']) ? (string) $json['name'] : null,
        'icon' => isset($json['icon']) ? (string) $json['icon'] : null,
        'quality' => isset($json['quality']) ? (int) $json['quality'] : null,
        'html' => $html,
    ];
}

/**
 * Cheap shape guard for a sanitised tooltip fragment. Real Wowhead item
 * tooltips are one or more <table> blocks; a fragment that starts with
 * anything else, carries page-level markup, or runs away in size could only
 * have come from an unexpected payload — refuse to store or render it.
 */
function looksLikeWowheadTooltipHtml(string $html): bool
{
    $html = trim($html);
    if ($html === '' || strlen($html) > 65536) {
        return false;
    }
    if (stripos($html, '<table') !== 0) {
        return false;
    }
    return preg_match('#<(?:html|head|body|script|iframe|form)\b#i', $html) !== 1;
}

/**
 * Strip a Wowhead tooltip down to a safe, self-contained HTML fragment.
 *
 * Wowhead renders its tooltip as <table> blocks whose colour comes from
 * `class="q"` / `class="q0"` … `class="q7"` (the standard WoW quality
 * colours we also use for the slot borders). We keep that structure and
 * those classes — plus the money and socket tokens Wowhead hangs its coin
 * and gem icons on, and the muted "extra" rows — drop every event handler /
 * inline style / script / iframe, and only keep <a> links that point back
 * to wowhead.com — absolutising the tooltip's relative /wotlk/… paths. The
 * internal <!--stat7-->-style markers Wowhead embeds in the markup are plain
 * HTML comments and are removed with everything else, and the title row's
 * "Phase N" marker is stripped outright, so the rendered block matches
 * Wowhead visually without letting any of its markup execute in our page.
 */
function sanitizeWowheadTooltipHtml(string $html): string
{
    $html = (string) preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);
    $html = (string) preg_replace('#<style\b[^>]*>.*?</style>#is', '', $html);
    $html = (string) preg_replace('#<!--.*?-->#s', '', $html);
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    try {
        return sanitizeWowheadTooltipDom($html);
    } catch (Throwable $e) {
        // Last-resort: strip everything we don't explicitly allow.
        return sanitizeWowheadTooltipRegex($html);
    }
}

const WOWHEAD_ALLOWED_TAGS = [
    'table', 'tbody', 'thead', 'tr', 'td', 'th', 'b', 'strong', 'span', 'br',
    'div', 'small', 'i', 'em', 'a', 'font', 'p',
];

/**
 * Class tokens that carry Wowhead's own meaning and quality colours: the
 * q-classes, the money classes behind the sell-price coin icons, the socket
 * classes behind the gem icons, and the "extra" info rows (drop source,
 * drop chance) which Wowhead mutes. Everything else is dropped with the
 * attribute.
 */
const WOWHEAD_KEEP_CLASS = '#^(?:q[0-7]?|wowhead-tooltip|indent|money(?:gold|silver|copper)|socket-[a-z]+|whtt-[a-z-]+)$#';

/** Filter a class attribute down to the tokens worth keeping, in order. */
function wowheadKeptClassTokens(string $value): array
{
    return array_values(array_filter(
        array_map('trim', preg_split('/\s+/', $value)),
        static function (string $token): bool {
            return $token !== '' && preg_match(WOWHEAD_KEEP_CLASS, $token) === 1;
        }
    ));
}

/**
 * Wowhead opens the WotLK tooltip's title row with the content phase the
 * item belongs to ("Phase 4"), in a cell of its own next to the item name.
 * A private server's armory has no use for it — the whole realm runs one
 * phase — so the marker is stripped while sanitising. The pattern covers
 * every locale Wowhead serves tooltips in.
 */
const WOWHEAD_PHASE_LABEL = '/^(?:(?:Phase|Fase|Фаза|阶段)\s*\d+|\d+\s*단계)$/iu';

function wowheadIsPhaseLabel(string $text): bool
{
    return preg_match(WOWHEAD_PHASE_LABEL, trim($text)) === 1;
}

/**
 * Regex-path twin of the DOM phase removal: an element whose whole content is
 * the phase label, e.g. `<b class="q0 whtt-extra">Phase 4</b>`. The caller
 * also drops the `<th>`/`<td>` cell the removal leaves empty.
 */
const WOWHEAD_PHASE_STRIP_REGEX = '#<(b|strong|i|em|span|div|td|th)\b[^>]*>\s*(?:(?:Phase|Fase|Фаза|阶段)\s*\d+|\d+\s*단계)\s*</\1>#iu';

/**
 * Normalise a tooltip link to an absolute https://www.wowhead.com URL.
 * The tooltip markup links to other Wowhead pages with relative paths
 * (/wotlk/...), which only make sense absolutised. Anything foreign returns ''
 * so the sanitizer drops the href entirely — tooltip links can never point
 * off wowhead.com or into an unknown protocol.
 */
function wowheadAbsoluteHref(string $href): string
{
    $href = trim($href);
    if (preg_match('#^(https?:)?//([a-z0-9.-]+\.)?wowhead\.com/#i', $href)) {
        return preg_match('#^https?:#i', $href) ? $href : 'https:' . $href;
    }
    if (preg_match('#^/(?:wotlk|classic|tbc|cata)/#i', $href)) {
        return 'https://www.wowhead.com' . $href;
    }
    return '';
}

/** DOM-based sanitiser — the accurate path when libxml is available. */
function sanitizeWowheadTooltipDom(string $html): string
{
    if (!class_exists('DOMDocument')) {
        return sanitizeWowheadTooltipRegex($html);
    }

    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    // The xml prolog pins UTF-8; LIBXML_NONET stops any external fetch while
    // libxml parses the (already network-sourced) markup.
    $doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $body = $doc->getElementsByTagName('body')->item(0) ?: $doc->documentElement;
    if (!$body) {
        return '';
    }

    $container = $doc->createElement('div');
    foreach (iterator_to_array($body->childNodes) as $child) {
        $clean = sanitizeWowheadNode($child);
        if ($clean) {
            $container->appendChild($clean);
        }
    }

    return domInnerHtml($container);
}

/** Recursively copy only the whitelisted nodes/attributes into $doc. */
function sanitizeWowheadNode(DOMNode $node): ?DOMNode
{
    if ($node->nodeType === XML_TEXT_NODE) {
        return wowheadIsPhaseLabel($node->nodeValue ?? '') ? null : $node->cloneNode(true);
    }
    if ($node->nodeType !== XML_ELEMENT_NODE) {
        return null;
    }
    // Wowhead's "Phase N" title-row marker — and the cell whose only content
    // is that marker — is dropped instead of copied: a private-server armory
    // has no phase list to compare the item against, so the label is noise.
    if (wowheadIsPhaseLabel($node->textContent)) {
        return null;
    }

    $tag = strtolower($node->nodeName);
    if (!in_array($tag, WOWHEAD_ALLOWED_TAGS, true)) {
        // Unwrap: keep the sanitised children, drop the element itself.
        $frag = $node->ownerDocument->createDocumentFragment();
        foreach (iterator_to_array($node->childNodes) as $child) {
            $clean = sanitizeWowheadNode($child);
            if ($clean) {
                $frag->appendChild($clean);
            }
        }
        return $frag->hasChildNodes() ? $frag : null;
    }

    $clone = $node->ownerDocument->createElement($tag);

    if ($node->hasAttributes()) {
        foreach (iterator_to_array($node->attributes) as $attr) {
            $name = strtolower($attr->nodeName);
            $value = $attr->nodeValue;
            if ($name === 'class') {
                $tokens = wowheadKeptClassTokens($value);
                if ($tokens) {
                    $clone->setAttribute('class', implode(' ', $tokens));
                }
            } elseif ($name === 'href' && $tag === 'a') {
                $href = wowheadAbsoluteHref($value);
                if ($href !== '') {
                    $clone->setAttribute('href', $href);
                    $clone->setAttribute('target', '_blank');
                    $clone->setAttribute('rel', 'noopener noreferrer');
                }
            }
            // every other attribute (style, on*, data-*, src, …) is dropped
        }
    }

    foreach (iterator_to_array($node->childNodes) as $child) {
        $clean = sanitizeWowheadNode($child);
        if ($clean) {
            $clone->appendChild($clean);
        }
    }

    return $clone;
}

/** Serialise a node's children back to an HTML string. */
function domInnerHtml(DOMNode $node): string
{
    $html = '';
    foreach ($node->childNodes as $child) {
        $html .= $node->ownerDocument->saveHTML($child);
    }
    return trim($html);
}

/**
 * Regex fallback used only when DOMDocument is unavailable or threw. It keeps
 * the allowed tags, preserves the whitelisted class tokens (quality colours,
 * money/socket icons, muted extra rows) and wowhead.com <a> hrefs, strips
 * every other attribute and removes the "Phase N" marker — safe, if a little
 * less faithful to the structure.
 */
function sanitizeWowheadTooltipRegex(string $html): string
{
    $allowed = WOWHEAD_ALLOWED_TAGS;
    $html = strip_tags($html, '<' . implode('><', $allowed) . '>');
    $html = (string) preg_replace_callback(
        '#<([a-z0-9]+)([^>]*)>#i',
        static function (array $m) use ($allowed): string {
            $tag = strtolower($m[1]);
            if (!in_array($tag, $allowed, true)) {
                return '';
            }
            $attr = '';
            if (preg_match('/\bclass=["\']([^"\']*)["\']/i', $m[2], $cm)) {
                $tokens = wowheadKeptClassTokens($cm[1]);
                if ($tokens) {
                    $attr .= ' class="' . implode(' ', $tokens) . '"';
                }
            }
            if ($tag === 'a' && preg_match('/\bhref=["\']([^"\']+)["\']/i', $m[2], $hu)) {
                $href = wowheadAbsoluteHref($hu[1]);
                if ($href !== '') {
                    $attr .= ' href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8')
                        . '" target="_blank" rel="noopener noreferrer"';
                }
            }
            return '<' . $tag . $attr . '>';
        },
        $html
    );
    // Same "Phase N" removal the DOM path performs, expressed on the flat
    // markup: drop the labelled element, then the cell it leaves empty.
    $html = (string) preg_replace(WOWHEAD_PHASE_STRIP_REGEX, '', $html);
    $html = (string) preg_replace('#<(th|td)\b[^>]*>\s*</\1>#i', '', $html);
    return trim($html);
}
