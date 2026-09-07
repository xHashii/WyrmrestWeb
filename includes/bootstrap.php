<?php
session_start();
$config = require __DIR__ . '/../config.php';

/**
 * Send a console command to the worldserver over its SOAP interface
 * and return the raw response text.
 */
function sendSoapCommand(string $command, array $config): string
{
    $options = [
        'location'   => "http://{$config['soap_host']}:{$config['soap_port']}/",
        'uri'        => 'urn:TC',
        'style'      => SOAP_RPC,
        'login'      => $config['soap_user'],
        'password'   => $config['soap_pass'],
        'trace'      => 1,
        'exceptions' => true,
        'connection_timeout' => 2,
    ];

    $client = new SoapClient(null, $options);
    $result = $client->executeCommand(new SoapParam($command, 'command'));

    return trim((string) $result);
}

/**
 * Ask the worldserver for a status reading via the "server info" console
 * command, over the same SOAP connection used for account creation.
 * Returns online=false if the worldserver can't be reached at all.
 */
function getServerStatus(array $config): array
{
    try {
        $raw = sendSoapCommand('server info', $config);

        $players = null;
        if (preg_match('/(?:connected players|players online)\s*:?\s*(\d+)/i', $raw, $m)) {
            $players = (int) $m[1];
        }

        $uptime = null;
        if (preg_match('/uptime\s*:?\s*([^\r\n.]+)/i', $raw, $m)) {
            $uptime = trim($m[1]);
        }

        return ['online' => true, 'players' => $players, 'uptime' => $uptime, 'error' => null];
    } catch (\Throwable $e) {
        return ['online' => false, 'players' => null, 'uptime' => null, 'error' => $e->getMessage()];
    }
}

/**
 * Open a connection to the `characters` database. Returns null (never
 * throws) if it's not configured or not reachable.
 */
function connectCharactersDb(array $config): ?PDO
{
    if (empty($config['db_host'])) {
        return null;
    }

    try {
        $dsn = "mysql:host={$config['db_host']};port={$config['db_port']};dbname={$config['db_name']};charset=utf8mb4";
        return new PDO($dsn, $config['db_user'], $config['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 2,
        ]);
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Static race id => name map, shared by mapCharacterRow() and anywhere
 * else that needs a race name (e.g. legends).
 */
function raceName(int $raceId): string
{
    static $races = [
        1 => 'Human', 2 => 'Orc', 3 => 'Dwarf', 4 => 'Night Elf', 5 => 'Undead',
        6 => 'Tauren', 7 => 'Gnome', 8 => 'Troll', 10 => 'Blood Elf', 11 => 'Draenei',
    ];
    return $races[$raceId] ?? 'Unknown';
}

/**
 * Static class id => name map, shared by mapCharacterRow() and the
 * population breakdown bar's legend.
 */
function className(int $classId): string
{
    static $classes = [
        1 => 'Warrior', 2 => 'Paladin', 3 => 'Hunter', 4 => 'Rogue', 5 => 'Priest',
        6 => 'Death Knight', 7 => 'Shaman', 8 => 'Mage', 9 => 'Warlock', 11 => 'Druid',
    ];
    return $classes[$classId] ?? 'Unknown';
}

/**
 * Community-standard WoW class colors (widely published hex values used
 * across addons/sites — not Blizzard artwork), used for the population
 * breakdown bar on Who's Online.
 */
function classColor(int $classId): string
{
    static $colors = [
        1 => '#C79C6E', 2 => '#F58CBA', 3 => '#ABD473', 4 => '#FFF569', 5 => '#FFFFFF',
        6 => '#C41F3B', 7 => '#0070DE', 8 => '#69CCF0', 9 => '#9482C9', 11 => '#FF7D0A',
    ];
    return $colors[$classId] ?? '#888888';
}

/**
 * Turn a raw `characters` row into the display-ready shape used by both
 * the online list and the leaderboard (name/level/race/class/icons,
 * optionally zone if that column was selected).
 */
function mapCharacterRow(array $row): array
{
    static $zones = null;
    if ($zones === null) {
        $zones = file_exists(__DIR__ . '/../data/zones.php') ? require __DIR__ . '/../data/zones.php' : [];
    }

    $raceId  = (int) $row['race'];
    $classId = (int) $row['class'];
    $gender  = (int) $row['gender'] === 1 ? 1 : 0;

    $mapped = [
        'name'       => $row['name'],
        'level'      => (int) $row['level'],
        'race_name'  => raceName($raceId),
        'race_icon'  => "images/race/{$raceId}-{$gender}.gif",
        'class_name' => className($classId),
        'class_icon' => "images/class/{$classId}.gif",
    ];

    if (array_key_exists('zone', $row)) {
        $zoneId = (int) $row['zone'];
        $mapped['zone_name'] = $zones[$zoneId] ?? "Zone #{$zoneId}";
    }

    return $mapped;
}

/**
 * Fetch currently-online characters from the `characters` database for
 * the "Who's Online" list. Returns an empty array (never throws) if the
 * DB isn't configured or isn't reachable — this section just disappears
 * rather than breaking the page.
 */
function getOnlinePlayers(array $config): array
{
    $pdo = connectCharactersDb($config);
    if (!$pdo) {
        return [];
    }

    try {
        $stmt = $pdo->query('SELECT name, level, race, class, gender, zone FROM characters WHERE online = 1 ORDER BY level DESC LIMIT 500');
        return array_map('mapCharacterRow', $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Fetch the top characters by level (online or not) for the leaderboard.
 * Same DB, no "online" filter, small fixed limit.
 */
function getTopCharacters(array $config, int $limit = 10): array
{
    $pdo = connectCharactersDb($config);
    if (!$pdo) {
        return [];
    }

    try {
        $stmt = $pdo->prepare('SELECT name, level, race, class, gender FROM characters ORDER BY level DESC, totaltime DESC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map('mapCharacterRow', $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Total characters ever created on the realm (online or not). Used to
 * give Home/Leaderboard a bit more substance. Returns null if the DB
 * isn't configured or reachable.
 */
function getTotalCharacterCount(array $config): ?int
{
    $pdo = connectCharactersDb($config);
    if (!$pdo) {
        return null;
    }

    try {
        return (int) $pdo->query('SELECT COUNT(*) FROM characters')->fetchColumn();
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Class breakdown (class id => count) among currently-online characters,
 * for the population bar on the Who's Online page. Empty array if the
 * DB isn't configured/reachable or nobody's online.
 */
function getOnlineClassBreakdown(array $config): array
{
    $pdo = connectCharactersDb($config);
    if (!$pdo) {
        return [];
    }

    try {
        $stmt = $pdo->query('SELECT class, COUNT(*) AS c FROM characters WHERE online = 1 GROUP BY class');
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        return array_map('intval', $rows);
    } catch (\Throwable $e) {
        return [];
    }
}

/* ============================================================
 * Armory: character/guild lookup, ported from the Node.js/
 * AzerothCore Armory app to native PHP against this same DB
 * connection. Covers character summary + equipped gear, and
 * guild roster. Does NOT cover: talents, glyphs, achievements,
 * PvP/arena, transmog, or item tooltips/icons (no item icon
 * images are available without extracting them from the game
 * client, which this project doesn't do) — those were part of
 * the original Node app and are out of scope here.
 * ============================================================ */

/**
 * Equipment slot id (character_inventory.slot, 0-18, bag=0) => label.
 */
function equipSlotLabel(int $slot): string
{
    static $labels = [
        0 => 'Head', 1 => 'Neck', 2 => 'Shoulders', 3 => 'Shirt', 4 => 'Chest',
        5 => 'Waist', 6 => 'Legs', 7 => 'Feet', 8 => 'Wrists', 9 => 'Hands',
        10 => 'Finger 1', 11 => 'Finger 2', 12 => 'Trinket 1', 13 => 'Trinket 2',
        14 => 'Back', 15 => 'Main Hand', 16 => 'Off Hand', 17 => 'Ranged', 18 => 'Tabard',
    ];
    return $labels[$slot] ?? "Slot {$slot}";
}

/**
 * Item quality id => [label, hex color]. Standard WoW UI quality colors
 * (public/well-known values, not Blizzard artwork).
 */
function itemQualityInfo(int $quality): array
{
    static $qualities = [
        0 => ['Poor', '#9d9d9d'],
        1 => ['Common', '#ffffff'],
        2 => ['Uncommon', '#1eff00'],
        3 => ['Rare', '#0070dd'],
        4 => ['Epic', '#a335ee'],
        5 => ['Legendary', '#ff8000'],
        6 => ['Artifact', '#e6cc80'],
        7 => ['Heirloom', '#00ccff'],
    ];
    return $qualities[$quality] ?? ['Unknown', '#888888'];
}

/**
 * 1 = Alliance, 2 = Horde, based on race id. Used for a faction label
 * on the character page.
 */
function raceFaction(int $raceId): string
{
    static $alliance = [1, 3, 4, 7, 11]; // Human, Dwarf, Night Elf, Gnome, Draenei
    return in_array($raceId, $alliance, true) ? 'Alliance' : 'Horde';
}

/**
 * Format a totaltime value (seconds) as "Xd Yh" played time.
 */
function formatPlayedTime(int $seconds): string
{
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    if ($days > 0) {
        return "{$days}d {$hours}h";
    }
    $minutes = intdiv($seconds % 3600, 60);
    if ($hours > 0) {
        return "{$hours}h {$minutes}m";
    }
    return "{$minutes}m";
}

/**
 * Look up one character by exact name for the Armory character page.
 * Returns null if not found, or if it belongs to a hidden GM account.
 */
function findCharacterByName(array $config, string $name): ?array
{
    $pdo = connectCharactersDb($config);
    if (!$pdo) {
        return null;
    }

    $auth = $config['auth_db_name'] ?? 'auth';
    $realmId = (int) ($config['realm_id'] ?? 1);

    try {
        $stmt = $pdo->prepare("
            SELECT c.guid, c.account, c.name, c.race, c.class, c.gender, c.level, c.online,
                   c.totaltime, c.zone, c.money,
                   g.guildid, g.name AS guild_name, gr.rname AS guild_rank,
                   aa.SecurityLevel AS gmlevel
            FROM characters c
            LEFT JOIN guild_member gm ON gm.guid = c.guid
            LEFT JOIN guild g ON g.guildid = gm.guildid
            LEFT JOIN guild_rank gr ON gr.guildid = gm.guildid AND gr.rid = gm.`rank`
            LEFT JOIN `{$auth}`.`account_access` aa ON aa.AccountID = c.account AND aa.RealmID IN (-1, {$realmId})
            WHERE c.name = ?
            LIMIT 1
        ");
        $stmt->execute([$name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }
        if (!empty($config['hide_game_masters']) && (int) ($row['gmlevel'] ?? 0) > 0) {
            return null;
        }

        return $row;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Equipped items (bag = 0, slots 0-18) for a character guid, joined
 * against item_template (world db) for name/quality. Indexed by slot.
 */
function getCharacterEquipment(array $config, int $guid): array
{
    $pdo = connectCharactersDb($config);
    if (!$pdo) {
        return [];
    }

    $world = $config['world_db_name'] ?? 'world';

    try {
        $stmt = $pdo->prepare("
            SELECT ci.slot, it.entry, it.name, it.Quality, it.InventoryType
            FROM character_inventory ci
            JOIN item_instance ii ON ii.guid = ci.item
            JOIN `{$world}`.`item_template` it ON it.entry = ii.itemEntry
            WHERE ci.guid = ? AND ci.bag = 0 AND ci.slot BETWEEN 0 AND 18
            ORDER BY ci.slot ASC
        ");
        $stmt->execute([$guid]);

        $bySlot = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $bySlot[(int) $row['slot']] = $row;
        }
        return $bySlot;
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Character name search (prefix match) for the Armory search box.
 */
function searchCharacters(array $config, string $query, int $limit = 15): array
{
    $pdo = connectCharactersDb($config);
    if (!$pdo || $query === '') {
        return [];
    }

    try {
        $stmt = $pdo->prepare('SELECT guid, name, level, race, class, online FROM characters WHERE name LIKE ? ORDER BY level DESC LIMIT ' . (int) $limit);
        $stmt->execute([$query . '%']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Guild name search (prefix match) for the Armory search box.
 */
function searchGuilds(array $config, string $query, int $limit = 15): array
{
    $pdo = connectCharactersDb($config);
    if (!$pdo || $query === '') {
        return [];
    }

    try {
        $stmt = $pdo->prepare('SELECT guildid, name FROM guild WHERE name LIKE ? ORDER BY name ASC LIMIT ' . (int) $limit);
        $stmt->execute([$query . '%']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Look up one guild by exact name, with leader name resolved.
 */
function findGuildByName(array $config, string $name): ?array
{
    $pdo = connectCharactersDb($config);
    if (!$pdo) {
        return null;
    }

    try {
        $stmt = $pdo->prepare('
            SELECT g.guildid, g.name, g.leaderguid, g.info, g.motd, g.createdate,
                   lc.name AS leader_name
            FROM guild g
            LEFT JOIN characters lc ON lc.guid = g.leaderguid
            WHERE g.name = ?
            LIMIT 1
        ');
        $stmt->execute([$name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Guild roster: members ordered by rank then level, with GM accounts
 * optionally hidden. Rank id (rid) 0 = highest rank (guild master).
 */
function getGuildMembers(array $config, int $guildId): array
{
    $pdo = connectCharactersDb($config);
    if (!$pdo) {
        return [];
    }

    $auth = $config['auth_db_name'] ?? 'auth';
    $realmId = (int) ($config['realm_id'] ?? 1);
    $hideGms = !empty($config['hide_game_masters']);

    try {
        $sql = "
            SELECT c.name, c.level, c.class, c.race, c.gender, c.zone, c.online,
                   gr.rname AS rank_name, gr.rid
            FROM guild_member gm
            JOIN characters c ON c.guid = gm.guid
            JOIN guild_rank gr ON gr.guildid = gm.guildid AND gr.rid = gm.`rank`
            LEFT JOIN `{$auth}`.`account_access` aa ON aa.AccountID = c.account AND aa.RealmID IN (-1, {$realmId})
            WHERE gm.guildid = ?
        ";
        if ($hideGms) {
            $sql .= ' AND (aa.AccountID IS NULL OR aa.SecurityLevel = 0)';
        }
        $sql .= ' ORDER BY gr.rid ASC, c.level DESC, c.name ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$guildId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return [];
    }
}
