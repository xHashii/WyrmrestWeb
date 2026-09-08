<?php
/**
 * Item stats pulled straight from Wowhead.
 *
 * The character page's gear tooltip used to show only a "View on Wowhead"
 * link. This module fetches Wowhead's *own* item tooltip — the exact stat
 * block, equip effects and colour coding players see on the site — and
 * renders it inside our tooltip. We ask Wowhead for the JSON tooltip variant
 * (the `&json` endpoint returns the tooltip HTML when the request looks like
 * one of Wowhead's own AJAX calls via `X-Requested-With: XMLHttpRequest`),
 * sanitise it down to a safe subset, and cache it on disk so a character page
 * never has to hit Wowhead more than once per item.
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
 *   - an array with 'ok' => true  when a fresh, successful tooltip is cached
 *   - ['__skip' => true]           when a recent fetch already failed (back off)
 *   - null                         when there is nothing cached (go fetch)
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
        return $age < $ttl ? $data : null;
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
    $out = ['entry' => $entry, 'locale' => $locale, 'cached_at' => time(), 'ok' => $data !== null];
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
 */
function fetchWowheadTooltipsRemoteMulti(array $config, array $entries, string $locale): array
{
    if (!function_exists('curl_init') || !function_exists('curl_multi_init') || empty($entries)) {
        return [];
    }

    $ua = 'Mozilla/5.0 (compatible; WyrmrestWeb/' . (int) ($config['realm_id'] ?? 1)
        . '; +https://github.com/xHashii/WyrmrestWeb)';
    $referer = 'https://www.wowhead.com/';

    $mh = curl_multi_init();
    $handles = [];
    foreach ($entries as $entry) {
        $ch = curl_init('https://www.wowhead.com/wotlk/item=' . $entry . '&json&locale=' . $locale);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) ($config['wowhead_timeout'] ?? 10),
            CURLOPT_CONNECTTIMEOUT => (int) ($config['wowhead_connect_timeout'] ?? 4),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_HTTPHEADER => [
                'X-Requested-With: XMLHttpRequest',
                'Accept: application/json, text/plain, */*',
                'Referer: ' . $referer,
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
 * Turn a Wowhead response body into a tidy tooltip array. Prefers the JSON
 * `tooltip` field; if that's absent (e.g. an HTML page slipped through) we try
 * to salvage any tooltip-shaped markup. Returns null when there's nothing usable.
 */
function parseWowheadTooltipBody(string $body): ?array
{
    $body = trim($body);
    if ($body === '') {
        return null;
    }

    $json = json_decode($body, true);
    if (is_array($json)) {
        $tooltip = $json['tooltip'] ?? $json['html'] ?? null;
        if (is_string($tooltip) && $tooltip !== '') {
            $html = sanitizeWowheadTooltipHtml($tooltip);
            if ($html !== '') {
                return [
                    'name' => isset($json['name']) ? (string) $json['name'] : null,
                    'icon' => isset($json['icon']) ? (string) $json['icon'] : null,
                    'quality' => isset($json['quality']) ? (int) $json['quality'] : null,
                    'html' => $html,
                ];
            }
        }
    }

    // Non-JSON fallback: salvage embedded tooltip markup.
    $html = sanitizeWowheadTooltipHtml($body);
    return $html !== '' ? ['name' => null, 'icon' => null, 'quality' => null, 'html' => $html] : null;
}

/**
 * Strip a Wowhead tooltip down to a safe, self-contained HTML fragment.
 *
 * Wowhead renders its tooltip as a single-column <table> whose colour comes
 * from `class="q0"` … `class="q7"` (the standard WoW quality colours we also
 * use for the slot borders). We keep exactly that structure and those classes,
 * drop every event handler / inline style / script / iframe, and only keep
 * <a> links that point back to wowhead.com — so the rendered block matches
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

const WOWHEAD_SAFE_HREF = '#^https?://([a-z0-9.-]+\.)?wowhead\.com/#i';

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
        return $node->cloneNode(true);
    }
    if ($node->nodeType !== XML_ELEMENT_NODE) {
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
                $tokens = array_filter(
                    array_map('trim', preg_split('/\s+/', $value)),
                    static function (string $t): bool {
                        return preg_match('/^(?:q[0-7]|wowhead-tooltip|indent)$/', $t) === 1;
                    }
                );
                if ($tokens) {
                    $clone->setAttribute('class', implode(' ', $tokens));
                }
            } elseif ($name === 'href' && $tag === 'a' && preg_match(WOWHEAD_SAFE_HREF, $value)) {
                $clone->setAttribute('href', $value);
                $clone->setAttribute('target', '_blank');
                $clone->setAttribute('rel', 'noopener noreferrer');
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
 * the allowed tags, preserves only `class="qN"` and safe <a> hrefs, and strips
 * every other attribute — safe, if a little less faithful to the structure.
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
            if (preg_match('/\bclass=["\'](q[0-7]|wowhead-tooltip|indent)["\']/i', $m[2], $cm)) {
                $attr = ' class="' . strtolower($cm[1]) . '"';
            } elseif ($tag === 'a' && preg_match('/href=["\']([^"\']+)["\']/i', $m[2], $hu)
                && preg_match(WOWHEAD_SAFE_HREF, $hu[1])) {
                $attr = ' href="' . $hu[1] . '" target="_blank" rel="noopener noreferrer"';
            }
            return '<' . $tag . $attr . '>';
        },
        $html
    );
    return trim($html);
}
