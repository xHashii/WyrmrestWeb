<?php
/** Fixed-origin, bounded public assets for the optional model viewer. */
const ARMORY_MODEL_ASSET_MAX_BYTES = 16 * 1024 * 1024;
const ARMORY_MODEL_CACHE_MAX_BYTES = 128 * 1024 * 1024;
const ARMORY_MODEL_ASSET_TTL = 86400;

function validModelAssetPath(string $path): bool
{
    return strlen($path) <= 240 && strpos($path, '..') === false
        && preg_match('~\A(?:meta|models|mo3|m2|textures|anims|skel|bone)/[a-zA-Z0-9_./-]+\.(?:json|mo3|m2|skin|skel|bone|anim|bin|png|webp|jpg)\z~D', $path) === 1;
}

function modelAssetMime(string $path): string
{
    return ['json' => 'application/json', 'png' => 'image/png', 'webp' => 'image/webp',
        'jpg' => 'image/jpeg'][pathinfo($path, PATHINFO_EXTENSION)] ?? 'application/octet-stream';
}

/** No redirects, arbitrary hosts, cookies, credentials, or unbounded downloads. */
function fetchModelAsset(string $path): string
{
    if (!validModelAssetPath($path)) {
        throw new InvalidArgumentException('Invalid model asset path.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The optional 3D viewer requires PHP cURL.');
    }
    $body = '';
    $curl = curl_init('https://wow.zamimg.com/modelviewer/classic/' . $path);
    curl_setopt_array($curl, [
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'WyrmrestWeb-Armory/1.0',
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > ARMORY_MODEL_ASSET_MAX_BYTES) {
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if (!$ok || $status !== 200 || $body === '') {
        throw new RuntimeException('The model asset is unavailable from the provider.');
    }
    if (pathinfo($path, PATHINFO_EXTENSION) === 'json' && !is_array(json_decode($body, true))) {
        throw new RuntimeException('The model provider returned invalid metadata.');
    }
    return $body;
}

/** Best-effort bounded cache; failure to write must not prevent a preview. */
function cacheModelAsset(string $directory, string $file, string $body): void
{
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        return;
    }
    $lock = @fopen($directory . '/.lock', 'c');
    if (!$lock) {
        return;
    }
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            return;
        }
        $files = glob($directory . '/*.asset') ?: [];
        $total = 0;
        $ages = [];
        foreach ($files as $cached) {
            $total += (int) @filesize($cached);
            $ages[$cached] = (int) @filemtime($cached);
        }
        asort($ages);
        foreach ($ages as $cached => $age) {
            if ($total + strlen($body) <= ARMORY_MODEL_CACHE_MAX_BYTES) {
                break;
            }
            $size = (int) @filesize($cached);
            if (@unlink($cached)) {
                $total -= $size;
            }
        }
        if ($total + strlen($body) > ARMORY_MODEL_CACHE_MAX_BYTES) {
            return;
        }
        $tmp = @tempnam($directory, 'asset-');
        if ($tmp && (@file_put_contents($tmp, $body) === false || !@rename($tmp, $file))) {
            @unlink($tmp);
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
