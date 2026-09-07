<?php
/**
 * Type-ahead endpoint for the Armory search box.
 *
 *   armory-suggest.php?q=syl&type=character
 *   armory-suggest.php?q=wyr&type=guild
 *
 * Returns the same prefix matches the search page would show, as JSON.
 * Fewer than ARMORY_MIN_SEARCH_LENGTH characters returns an empty list
 * instead of scanning the whole realm.
 */
require __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$query = trim($_GET['q'] ?? '');
$type = ($_GET['type'] ?? 'character') === 'guild' ? 'guild' : 'character';

$payload = [
    'query'      => $query,
    'type'       => $type,
    'min_length' => ARMORY_MIN_SEARCH_LENGTH,
    'results'    => [],
];

if (mb_strlen($query) >= ARMORY_MIN_SEARCH_LENGTH) {
    if ($type === 'guild') {
        foreach (searchGuilds($config, $query, 10) as $g) {
            $payload['results'][] = [
                'url'     => 'guild.php?id=' . (int) $g['guildid'],
                'name'    => $g['name'],
                'meta'    => (int) $g['member_count'] . ' member' . ((int) $g['member_count'] === 1 ? '' : 's'),
            ];
        }
    } else {
        foreach (searchCharacters($config, $query, 10) as $c) {
            $payload['results'][] = [
                'url'    => 'character.php?guid=' . (int) $c['guid'],
                'name'   => $c['name'],
                'meta'   => 'Level ' . (int) $c['level'] . ' ' . raceName((int) $c['race']) . ' ' . className((int) $c['class']),
                'online' => (int) $c['online'] === 1,
                'class'  => (int) $c['class'],
                'race'   => (int) $c['race'],
                'gender' => (int) $c['gender'] === 1 ? 1 : 0,
            ];
        }
    }
}

echo json_encode($payload, JSON_UNESCAPED_UNICODE);
