<?php
session_start();
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/itemdb.php';

/**
 * Every database call in here is written to fail softly — a missing table or
 * a missing GRANT must never blank out a page. The trade-off is that the real
 * reason then disappears, which is exactly how "Character not found" used to
 * hide a permission error on `auth`.`account_access`. So instead of throwing
 * the message away, record it: armory-diagnostics.php prints the whole list,
 * and pages show it inline when 'debug' is enabled in config.php.
 */
function dbNoteError(string $context, \Throwable $e): void
{
    $GLOBALS['__db_errors'][] = [
        'context' => $context,
        'message' => $e->getMessage(),
    ];
}

/**
 * All database problems hit while rendering the current page.
 */
function dbErrors(): array
{
    return $GLOBALS['__db_errors'] ?? [];
}

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
 * throws) if it's not configured or not reachable. The handle is reused for
 * the rest of the request — a single page can ask for it a dozen times.
 */
function connectCharactersDb(array $config): ?PDO
{
    static $pdo = null;
    static $attempted = false;

    if ($attempted) {
        return $pdo;
    }
    $attempted = true;

    if (empty($config['db_host'])) {
        return null;
    }

    try {
        $dsn = "mysql:host={$config['db_host']};port={$config['db_port']};dbname={$config['db_name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 3,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (\Throwable $e) {
        $pdo = null;
        dbNoteError("connect to `{$config['db_name']}` at {$config['db_host']}:{$config['db_port']}", $e);
    }

    return $pdo;
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
        'guid'       => isset($row['guid']) ? (int) $row['guid'] : null,
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
        $stmt = $pdo->query('SELECT guid, name, level, race, class, gender, zone FROM characters WHERE online = 1 ORDER BY level DESC LIMIT 500');
        return array_map('mapCharacterRow', $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (\Throwable $e) {
        dbNoteError('read online characters', $e);
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
        $stmt = $pdo->prepare('SELECT guid, name, level, race, class, gender FROM characters ORDER BY level DESC, totaltime DESC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map('mapCharacterRow', $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (\Throwable $e) {
        dbNoteError('read top characters', $e);
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
        dbNoteError('count characters', $e);
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
        dbNoteError('read online class breakdown', $e);
        return [];
    }
}

/* ============================================================
 * Armory: character/guild lookup.
 *
 * Everything below reads the `characters` database only, plus two
 * *optional* extras that are queried separately and are allowed to fail:
 *
 *   `auth`.`account_access`      -> which accounts are Game Masters
 *   `hotfixes`.`item_sparse`     -> hotfixed/custom item templates
 *
 * They used to be LEFT JOINed straight into the character query. That is
 * what broke the character page: if the read-only MySQL user has no SELECT
 * grant on the auth database (or the database is named something else), the
 * *whole* query errors out, the catch block returns null, and the page says
 * "No character named X could be found" even though the character is right
 * there. Cross-database joins are now gone; each extra is its own query and
 * its failure only costs you that one detail.
 * ============================================================ */

/** Minimum number of characters required before we run a name search. */
const ARMORY_MIN_SEARCH_LENGTH = 3;

/** Escape character used with LIKE (avoids backslash/sql_mode surprises). */
const ARMORY_LIKE_ESCAPE = '!';

/**
 * How `character_inventory` addresses a character's items on 3.4.3.
 *
 * A row is (guid, bag, slot, item):
 *   guid  -> characters.guid, the owner
 *   item  -> item_instance.guid, the item object (PRIMARY KEY, so one item
 *            instance is in exactly one place)
 *   bag   -> 0 when the item sits directly on the character (equipped,
 *            backpack, bank, ...), otherwise the item_instance.guid of the
 *            container it's inside
 *   slot  -> position within that bag, or, when bag = 0, a position in the
 *            character's own slot map:
 *
 *      0 - 18    equipped gear      (EQUIPMENT_SLOT_START .. EQUIPMENT_SLOT_END)
 *     19 - 29    profession tools   (PROFESSION_SLOT_*)
 *     30 - 33    equipped bags      (INVENTORY_SLOT_BAG_START .. _END)
 *     34         reagent bag        (REAGENT_BAG_SLOT_START)
 *     35 - 62    backpack           (INVENTORY_SLOT_ITEM_START .. _END)
 *     63 - 90    bank               (BANK_SLOT_ITEM_START .. _END)
 *     91 - 97    bank bags          (BANK_SLOT_BAG_START .. _END)
 *     98 - 109   buyback            (BUYBACK_SLOT_START .. _END)
 *    110 - 207   reagent bank       (REAGENT_SLOT_START .. _END)
 *    208 - 210   child equipment    (CHILD_EQUIPMENT_SLOT_START .. _END)
 *
 * Values taken from TrinityCore's Player.h on the 3.4.3 branch — note they
 * are NOT the 3.3.5 numbers (where bags started at 19), so anything that
 * hardcodes "slot > 18 means backpack" is wrong on this core.
 */
const INV_EQUIPMENT_FIRST  = 0;
const INV_EQUIPMENT_LAST   = 18;
const INV_PROFESSION_FIRST = 19;
const INV_PROFESSION_LAST  = 29;
const INV_BAG_FIRST        = 30;
const INV_BAG_LAST         = 33;
const INV_REAGENT_BAG      = 34;
const INV_BACKPACK_FIRST   = 35;
const INV_BACKPACK_LAST    = 62;
const INV_BANK_FIRST       = 63;
const INV_BANK_LAST        = 97;

/**
 * Equipment slot id (character_inventory.slot with bag = 0) => label.
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
 * Profession/tool slot label (slots 19-29, bag = 0).
 */
function professionSlotLabel(int $slot): string
{
    static $labels = [
        19 => 'Profession 1 Tool', 20 => 'Profession 1 Gear', 21 => 'Profession 1 Gear',
        22 => 'Profession 2 Tool', 23 => 'Profession 2 Gear', 24 => 'Profession 2 Gear',
        25 => 'Cooking Tool', 26 => 'Cooking Gear',
        27 => 'Fishing Tool', 28 => 'Fishing Gear', 29 => 'Fishing Gear',
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
 * Turn user input into a safe "starts with" LIKE pattern: the wildcards
 * %, _ and the escape character itself are escaped so a search for "a_b"
 * looks for a literal underscore instead of any character.
 */
function armoryLikePrefix(string $value): string
{
    $escaped = str_replace(
        [ARMORY_LIKE_ESCAPE, '%', '_'],
        [ARMORY_LIKE_ESCAPE . ARMORY_LIKE_ESCAPE, ARMORY_LIKE_ESCAPE . '%', ARMORY_LIKE_ESCAPE . '_'],
        $value
    );
    return $escaped . '%';
}

/**
 * Lowercase helper that also works for the accented characters allowed in
 * WoW names on some locales (mb_strtolower when mbstring is available).
 */
function armoryLower(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

/**
 * Length in characters, not bytes — so "Lún" counts as three. Falls back to
 * strlen() on the rare install without mbstring.
 */
function armoryLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

/**
 * TrinityCore stores `characters`.`name` with the utf8mb4_bin collation,
 * which compares byte-for-byte: "sylea" does not match "Sylea", not with =
 * and not with LIKE. Every name comparison therefore goes through this
 * helper, which matches either the exact spelling or the lowercased one.
 * (LOWER() works fine on a utf8mb4 column with a _bin collation — the
 * "LOWER() does nothing" caveat only applies to real binary strings such as
 * BINARY/VARBINARY/BLOB columns.)
 *
 * Returns [sql fragment, params to bind].
 */
function armoryNameMatch(string $column, string $value, bool $prefix, string $suffix = ''): array
{
    $raw = $prefix ? armoryLikePrefix($value) : $value;
    $low = $prefix ? armoryLikePrefix(armoryLower($value)) : armoryLower($value);

    if ($prefix) {
        $escape = "ESCAPE '" . ARMORY_LIKE_ESCAPE . "'";
        $sql = "({$column} LIKE :raw{$suffix} {$escape} OR LOWER({$column}) LIKE :low{$suffix} {$escape})";
    } else {
        $sql = "({$column} = :raw{$suffix} OR LOWER({$column}) = :low{$suffix})";
    }

    return [$sql, ["raw{$suffix}" => $raw, "low{$suffix}" => $low]];
}

/**
 * True if the `characters` table has the soft-delete columns (TrinityCore
 * keeps deleted characters around with a blanked name and a deleteDate).
 * Probed once, because not every fork has them.
 */
function charactersHaveDeleteDate(array $config): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }

    $pdo = connectCharactersDb($config);
    if (!$pdo) {
        return $has = false;
    }

    try {
        $pdo->query('SELECT deleteDate FROM characters LIMIT 1');
        return $has = true;
    } catch (\Throwable $e) {
        return $has = false;
    }
}

/**
 * SQL fragment that skips soft-deleted characters (and the blank names they
 * leave behind). Empty string when the column doesn't exist.
 */
function armoryAliveFilter(array $config, string $alias = 'c'): string
{
    $sql = " AND {$alias}.name IS NOT NULL AND {$alias}.name <> ''";
    if (charactersHaveDeleteDate($config)) {
        $sql .= " AND ({$alias}.deleteDate IS NULL OR {$alias}.deleteDate = 0)";
    }
    return $sql;
}

/**
 * Account ids that belong to Game Masters, from `auth`.`account_access`.
 *
 * Returns null when the auth database can't be read (wrong name, no GRANT,
 * separate MySQL server...). Callers treat null as "can't tell" and show the
 * characters anyway — hiding the entire realm because of a missing GRANT is
 * far worse, and the diagnostics page reports it loudly.
 */
function gmAccountIds(array $config): ?array
{
    static $ids = null;
    static $attempted = false;

    if ($attempted) {
        return $ids;
    }
    $attempted = true;

    if (empty($config['hide_game_masters'])) {
        return $ids = [];
    }

    $pdo = connectCharactersDb($config);
    if (!$pdo) {
        return null;
    }

    $auth = $config['auth_db_name'] ?? 'auth';
    $realmId = (int) ($config['realm_id'] ?? 1);

    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT AccountID
            FROM `{$auth}`.`account_access`
            WHERE SecurityLevel > 0 AND RealmID IN (-1, :realm)
        ");
        $stmt->execute(['realm' => $realmId]);
        $ids = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
        return $ids;
    } catch (\Throwable $e) {
        dbNoteError("read `{$auth}`.`account_access` (Game Master filter)", $e);
        return $ids = null;
    }
}

/**
 * Should this character be hidden from the Armory because it belongs to a GM?
 */
function isHiddenGmCharacter(array $config, int $accountId): bool
{
    if (empty($config['hide_game_masters'])) {
        return false;
    }
    $ids = gmAccountIds($config);
    return $ids !== null && isset($ids[$accountId]);
}

/**
 * Character name search for the Armory search box: type at least three
 * characters and get every character whose name starts with them,
 * regardless of capitalisation.
 */
function searchCharacters(array $config, string $query, int $limit = 30): array
{
    $query = trim($query);
    $pdo = connectCharactersDb($config);
    if (!$pdo || armoryLength($query) < ARMORY_MIN_SEARCH_LENGTH) {
        return [];
    }

    [$match, $params] = armoryNameMatch('c.name', $query, true);
    $params['exact'] = armoryLower($query);
    $limit = max(1, min(200, $limit));

    try {
        $stmt = $pdo->prepare("
            SELECT c.guid, c.account, c.name, c.race, c.class, c.gender, c.level, c.online, c.zone
            FROM characters c
            WHERE {$match}" . armoryAliveFilter($config) . "
            ORDER BY CASE WHEN LOWER(c.name) = :exact THEN 0 ELSE 1 END,
                     c.level DESC, c.name ASC
            LIMIT {$limit}
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        dbNoteError('character name search', $e);
        return [];
    }

    return array_values(array_filter($rows, static function (array $row) use ($config) {
        return !isHiddenGmCharacter($config, (int) $row['account']);
    }));
}

/**
 * Guild name search — same rules as the character search.
 */
function searchGuilds(array $config, string $query, int $limit = 30): array
{
    $query = trim($query);
    $pdo = connectCharactersDb($config);
    if (!$pdo || armoryLength($query) < ARMORY_MIN_SEARCH_LENGTH) {
        return [];
    }

    [$match, $params] = armoryNameMatch('g.name', $query, true);
    $params['exact'] = armoryLower($query);
    $limit = max(1, min(200, $limit));

    try {
        $stmt = $pdo->prepare("
            SELECT g.guildid, g.name, COUNT(gm.guid) AS member_count
            FROM guild g
            LEFT JOIN guild_member gm ON gm.guildid = g.guildid
            WHERE {$match}
            GROUP BY g.guildid, g.name
            ORDER BY CASE WHEN LOWER(g.name) = :exact THEN 0 ELSE 1 END,
                     g.name ASC
            LIMIT {$limit}
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        dbNoteError('guild name search', $e);
        return [];
    }
}

/**
 * Look up a single character for the Armory profile page, by guid (what the
 * search results link to) or by name (typed URLs, guild rosters, old links).
 * The name match is case-insensitive, with the exact spelling preferred.
 *
 * Returns null only when there really is no such character (or it belongs to
 * a hidden GM account).
 */
function findCharacter(array $config, ?int $guid = null, string $name = ''): ?array
{
    $pdo = connectCharactersDb($config);
    if (!$pdo) {
        armoryLookupReason('no-db');
        return null;
    }

    $columns = 'c.guid, c.account, c.name, c.race, c.class, c.gender, c.level, c.online,
                c.totaltime, c.leveltime, c.zone, c.map, c.money, c.totalKills, c.logout_time';

    try {
        if ($guid !== null && $guid > 0) {
            $stmt = $pdo->prepare("
                SELECT {$columns}
                FROM characters c
                WHERE c.guid = :guid" . armoryAliveFilter($config) . "
                LIMIT 1
            ");
            $stmt->execute(['guid' => $guid]);
        } else {
            $name = trim($name);
            if ($name === '') {
                return null;
            }
            [$match, $params] = armoryNameMatch('c.name', $name, false);
            $params['exact'] = $name;
            $stmt = $pdo->prepare("
                SELECT {$columns}
                FROM characters c
                WHERE {$match}" . armoryAliveFilter($config) . "
                ORDER BY CASE WHEN c.name = :exact THEN 0 ELSE 1 END, c.guid ASC
                LIMIT 1
            ");
            $stmt->execute($params);
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        dbNoteError('character lookup', $e);
        return null;
    }

    if (!$row) {
        armoryLookupReason('not-found');
        return null;
    }
    if (isHiddenGmCharacter($config, (int) $row['account'])) {
        armoryLookupReason('hidden-gm');
        return null;
    }

    armoryLookupReason('');
    $row['guild'] = getCharacterGuild($config, (int) $row['guid']);

    return $row;
}

/**
 * Backwards-compatible wrapper (character.php used to call this directly).
 */
function findCharacterByName(array $config, string $name): ?array
{
    return findCharacter($config, null, $name);
}

/**
 * Guild membership for one character: ['guildid', 'name', 'rank'] or null.
 * Its own query, so a guild table problem can't sink the whole profile.
 */
function getCharacterGuild(array $config, int $guid): ?array
{
    $pdo = connectCharactersDb($config);
    if (!$pdo) {
        return null;
    }

    try {
        $stmt = $pdo->prepare('
            SELECT g.guildid, g.name, gr.rname AS rank_name
            FROM guild_member gm
            JOIN guild g ON g.guildid = gm.guildid
            LEFT JOIN guild_rank gr ON gr.guildid = gm.guildid AND gr.rid = gm.`rank`
            WHERE gm.guid = :guid
            LIMIT 1
        ');
        $stmt->execute(['guid' => $guid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (\Throwable $e) {
        dbNoteError('character guild lookup', $e);
        return null;
    }
}

/**
 * Everything `character_inventory` holds for one character, tied to the
 * character row itself:
 *
 *     characters.guid  =  character_inventory.guid      (whose items these are)
 *     character_inventory.item  =  item_instance.guid   (which item object)
 *     item_instance.itemEntry   ->  item template id    (what that item is)
 *
 * One query, then the rows are sorted into buckets by the slot map above.
 * Items inside a bag have `bag` set to the container's item_instance.guid,
 * so they are attached to the bag they live in.
 *
 * Returns:
 *   equipped   slot => item (0-18)
 *   profession slot => item (19-29)
 *   bags       list of ['slot', 'item', 'contents' => slot => item]
 *   backpack   slot => item (35-62)
 *   integrity  counters for armory-diagnostics.php
 */
function getCharacterInventory(array $config, int $guid): array
{
    $inventory = [
        'equipped'   => [],
        'profession' => [],
        'bags'       => [],
        'backpack'   => [],
        'integrity'  => ['rows' => 0, 'missing_instance' => 0, 'owner_mismatch' => 0, 'orphan_bag' => 0],
    ];

    $pdo = connectCharactersDb($config);
    if (!$pdo || $guid <= 0) {
        return $inventory;
    }

    try {
        // Joined through `characters` on purpose: the guid the page was asked
        // for has to exist as a character before any of its inventory rows
        // count, and item_instance is reached through character_inventory.item
        // (its PRIMARY KEY), so an item can only ever appear under one owner.
        $stmt = $pdo->prepare('
            SELECT ci.bag, ci.slot, ci.item AS item_guid,
                   ii.itemEntry, ii.count, ii.durability, ii.owner_guid
            FROM characters c
            JOIN character_inventory ci ON ci.guid = c.guid
            LEFT JOIN item_instance ii ON ii.guid = ci.item
            WHERE c.guid = :guid
            ORDER BY ci.bag ASC, ci.slot ASC
        ');
        $stmt->execute(['guid' => $guid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        dbNoteError('read character inventory', $e);
        return $inventory;
    }

    if (!$rows) {
        return $inventory;
    }

    $inventory['integrity']['rows'] = count($rows);

    // Resolve every item template id in one go (one hotfixes query, one DB2
    // index pass) instead of once per slot.
    $entries = [];
    foreach ($rows as $row) {
        if ($row['itemEntry'] !== null) {
            $entries[] = (int) $row['itemEntry'];
        }
    }
    $items = resolveItems($config, $entries);

    $decorate = static function (array $row) use ($items, $guid, &$inventory): array {
        $entry = (int) $row['itemEntry'];
        $item = $items[$entry] ?? [
            'entry'          => $entry,
            'name'           => "Unknown item #{$entry}",
            'quality'        => 1,
            'inventory_type' => 0,
            'item_level'     => 0,
            'required_level' => 0,
            'source'         => 'unresolved',
        ];

        $item['slot']       = (int) $row['slot'];
        $item['bag']        = (int) $row['bag'];
        $item['item_guid']  = (int) $row['item_guid'];
        $item['count']      = max(1, (int) $row['count']);
        $item['durability'] = (int) $row['durability'];

        // item_instance.owner_guid should agree with character_inventory.guid.
        // It isn't used to find the item (character_inventory already scopes
        // it to this character) but a mismatch means the database is out of
        // sync, so it gets counted for the diagnostics page.
        if ((int) $row['owner_guid'] !== $guid) {
            $inventory['integrity']['owner_mismatch']++;
        }

        return $item;
    };

    // Pass 1: everything sitting directly on the character (bag = 0).
    $containers = [];
    foreach ($rows as $row) {
        if ((int) $row['bag'] !== 0) {
            continue;
        }
        if ($row['itemEntry'] === null) {
            // character_inventory points at an item_instance row that is gone
            $inventory['integrity']['missing_instance']++;
            continue;
        }

        $slot = (int) $row['slot'];
        $item = $decorate($row);

        if ($slot >= INV_EQUIPMENT_FIRST && $slot <= INV_EQUIPMENT_LAST) {
            $inventory['equipped'][$slot] = $item;
        } elseif ($slot >= INV_PROFESSION_FIRST && $slot <= INV_PROFESSION_LAST) {
            $inventory['profession'][$slot] = $item;
        } elseif (($slot >= INV_BAG_FIRST && $slot <= INV_BAG_LAST) || $slot === INV_REAGENT_BAG) {
            $containers[$item['item_guid']] = ['slot' => $slot, 'item' => $item, 'contents' => []];
        } elseif ($slot >= INV_BACKPACK_FIRST && $slot <= INV_BACKPACK_LAST) {
            $inventory['backpack'][$slot] = $item;
        }
        // bank / buyback / reagent bank slots are deliberately not shown
    }

    // Pass 2: items inside the equipped bags.
    foreach ($rows as $row) {
        $bag = (int) $row['bag'];
        if ($bag === 0) {
            continue;
        }
        if ($row['itemEntry'] === null) {
            $inventory['integrity']['missing_instance']++;
            continue;
        }
        if (!isset($containers[$bag])) {
            // inside a bank bag (or a container we didn't list) — skip it
            $inventory['integrity']['orphan_bag']++;
            continue;
        }
        $containers[$bag]['contents'][(int) $row['slot']] = $decorate($row);
    }

    foreach ($containers as $container) {
        ksort($container['contents']);
        $inventory['bags'][] = $container;
    }
    usort($inventory['bags'], static fn ($a, $b) => $a['slot'] <=> $b['slot']);

    ksort($inventory['equipped']);
    ksort($inventory['profession']);
    ksort($inventory['backpack']);

    return $inventory;
}

/**
 * The equipped gear only (slots 0-18), as slot => item row.
 */
function getCharacterEquipment(array $config, int $guid): array
{
    return getCharacterInventory($config, $guid)['equipped'];
}

/**
 * Resolve item template ids to name/quality/item level.
 *
 * On 3.4.3 there is no `world`.`item_template` any more — item templates
 * live in the client's DB2 files, and the `hotfixes` database only holds the
 * rows the server hotfixes on top of them (usually none). So:
 *
 *   1. `hotfixes`.`item_sparse` — wins when present, that's where custom or
 *      edited items live. Highest VerifiedBuild per id.
 *   2. `world`.`item_template`  — only exists on 3.3.5-era cores; skipped
 *      automatically if the table isn't there.
 *   3. db2/ItemSparse.*.csv     — the bundled client export (see itemdb.php).
 */
function resolveItems(array $config, array $entries): array
{
    $entries = array_values(array_unique(array_filter(array_map('intval', $entries))));
    if (!$entries) {
        return [];
    }

    $resolved = resolveItemsFromHotfixes($config, $entries);

    $missing = array_values(array_diff($entries, array_keys($resolved)));
    if ($missing) {
        $resolved += resolveItemsFromWorldDb($config, $missing);
    }

    $missing = array_values(array_diff($entries, array_keys($resolved)));
    if ($missing) {
        $resolved += itemDb2Lookup($config, $missing);
    }

    return $resolved;
}

/**
 * Step 1: `hotfixes`.`item_sparse`.
 */
function resolveItemsFromHotfixes(array $config, array $entries): array
{
    $pdo = connectCharactersDb($config);
    if (!$pdo || empty($config['hotfixes_db_name'])) {
        return [];
    }

    $hotfixes = $config['hotfixes_db_name'];
    $placeholders = implode(',', array_fill(0, count($entries), '?'));

    try {
        $stmt = $pdo->prepare("
            SELECT ID, Display, OverallQualityID, InventoryType, ItemLevel, RequiredLevel, VerifiedBuild
            FROM `{$hotfixes}`.`item_sparse`
            WHERE ID IN ({$placeholders})
            ORDER BY VerifiedBuild ASC
        ");
        $stmt->execute($entries);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        dbNoteError("read `{$hotfixes}`.`item_sparse`", $e);
        return [];
    }

    $found = [];
    foreach ($rows as $row) {
        $name = trim((string) ($row['Display'] ?? ''));
        if ($name === '') {
            continue;
        }
        // ordered by VerifiedBuild ascending, so later rows overwrite older ones
        $found[(int) $row['ID']] = [
            'entry'          => (int) $row['ID'],
            'name'           => $name,
            'quality'        => (int) $row['OverallQualityID'],
            'inventory_type' => (int) $row['InventoryType'],
            'item_level'     => (int) $row['ItemLevel'],
            'required_level' => (int) $row['RequiredLevel'],
            'source'         => 'hotfixes',
        ];
    }

    return $found;
}

/**
 * Step 2: `world`.`item_template`, for 3.3.5-era cores that still have it.
 * Probed once; on 3.4.3 the table doesn't exist and this quietly does nothing.
 */
function resolveItemsFromWorldDb(array $config, array $entries): array
{
    static $available = null;

    $pdo = connectCharactersDb($config);
    if (!$pdo || empty($config['world_db_name']) || $available === false) {
        return [];
    }

    $world = $config['world_db_name'];
    $placeholders = implode(',', array_fill(0, count($entries), '?'));

    try {
        $stmt = $pdo->prepare("
            SELECT entry AS ID, name AS Display, Quality, InventoryType, ItemLevel, RequiredLevel
            FROM `{$world}`.`item_template`
            WHERE entry IN ({$placeholders})
        ");
        $stmt->execute($entries);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $available = true;
    } catch (\Throwable $e) {
        // Expected on 3.4.3: the table simply doesn't exist any more. Not worth
        // reporting as an error — armory-diagnostics.php shows its state instead.
        $available = false;
        return [];
    }

    $found = [];
    foreach ($rows as $row) {
        $name = trim((string) ($row['Display'] ?? ''));
        if ($name === '') {
            continue;
        }
        $found[(int) $row['ID']] = [
            'entry'          => (int) $row['ID'],
            'name'           => $name,
            'quality'        => (int) $row['Quality'],
            'inventory_type' => (int) $row['InventoryType'],
            'item_level'     => (int) $row['ItemLevel'],
            'required_level' => (int) $row['RequiredLevel'],
            'source'         => 'world',
        ];
    }

    return $found;
}

/**
 * Average item level of the equipped gear, the way the game counts it:
 * shirt and tabard don't contribute.
 */
function averageItemLevel(array $equipment): ?int
{
    $sum = 0;
    $count = 0;
    foreach ($equipment as $slot => $item) {
        if (in_array((int) $slot, [3, 18], true)) { // shirt, tabard
            continue;
        }
        if ((int) $item['item_level'] > 0) {
            $sum += (int) $item['item_level'];
            $count++;
        }
    }
    return $count > 0 ? (int) round($sum / $count) : null;
}

/**
 * Look up a guild by id or (case-insensitive) name.
 */
function findGuild(array $config, ?int $guildId = null, string $name = ''): ?array
{
    $pdo = connectCharactersDb($config);
    if (!$pdo) {
        return null;
    }

    try {
        if ($guildId !== null && $guildId > 0) {
            $stmt = $pdo->prepare('
                SELECT g.guildid, g.name, g.leaderguid, g.info, g.motd, g.createdate,
                       lc.name AS leader_name, lc.guid AS leader_guid
                FROM guild g
                LEFT JOIN characters lc ON lc.guid = g.leaderguid
                WHERE g.guildid = :id
                LIMIT 1
            ');
            $stmt->execute(['id' => $guildId]);
        } else {
            $name = trim($name);
            if ($name === '') {
                return null;
            }
            [$match, $params] = armoryNameMatch('g.name', $name, false);
            $params['exact'] = $name;
            $stmt = $pdo->prepare("
                SELECT g.guildid, g.name, g.leaderguid, g.info, g.motd, g.createdate,
                       lc.name AS leader_name, lc.guid AS leader_guid
                FROM guild g
                LEFT JOIN characters lc ON lc.guid = g.leaderguid
                WHERE {$match}
                ORDER BY CASE WHEN g.name = :exact THEN 0 ELSE 1 END, g.guildid ASC
                LIMIT 1
            ");
            $stmt->execute($params);
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (\Throwable $e) {
        dbNoteError('guild lookup', $e);
        return null;
    }
}

/**
 * Backwards-compatible wrapper.
 */
function findGuildByName(array $config, string $name): ?array
{
    return findGuild($config, null, $name);
}

/**
 * Guild roster: members ordered by rank then level. Rank id (rid) 0 is the
 * guild master. GM characters are filtered out in PHP, so a missing auth
 * grant can't empty the roster.
 */
function getGuildMembers(array $config, int $guildId): array
{
    $pdo = connectCharactersDb($config);
    if (!$pdo) {
        return [];
    }

    try {
        $stmt = $pdo->prepare('
            SELECT c.guid, c.account, c.name, c.level, c.class, c.race, c.gender, c.zone, c.online,
                   gr.rname AS rank_name, gm.`rank` AS rank_id
            FROM guild_member gm
            JOIN characters c ON c.guid = gm.guid
            LEFT JOIN guild_rank gr ON gr.guildid = gm.guildid AND gr.rid = gm.`rank`
            WHERE gm.guildid = :id
            ORDER BY gm.`rank` ASC, c.level DESC, c.name ASC
        ');
        $stmt->execute(['id' => $guildId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        dbNoteError('read guild roster', $e);
        return [];
    }

    return array_values(array_filter($rows, static function (array $row) use ($config) {
        return !isHiddenGmCharacter($config, (int) $row['account']);
    }));
}

/**
 * Everything armory-diagnostics.php needs: one entry per dependency, each
 * with a status of ok / warn / fail and a human explanation.
 */
function armoryDiagnostics(array $config): array
{
    $checks = [];

    $add = static function (array &$checks, string $name, string $status, string $detail, string $hint = '') {
        $checks[] = ['name' => $name, 'status' => $status, 'detail' => $detail, 'hint' => $hint];
    };

    // 1. characters database
    $pdo = connectCharactersDb($config);
    if (!$pdo) {
        $errors = dbErrors();
        $add($checks, 'characters database', 'fail',
            $errors ? end($errors)['message'] : 'db_host is empty in config.php',
            'Check db_host / db_port / db_user / db_pass in config.php.');
        return $checks;
    }
    $add($checks, 'characters database', 'ok',
        "connected to `{$config['db_name']}` at {$config['db_host']}:{$config['db_port']}");

    // 2. required tables
    foreach (['characters', 'character_inventory', 'item_instance', 'guild', 'guild_member', 'guild_rank'] as $table) {
        try {
            $count = $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
            $add($checks, "table `{$table}`", 'ok', number_format((int) $count) . ' rows');
        } catch (\Throwable $e) {
            $add($checks, "table `{$table}`", 'fail', $e->getMessage(),
                "Grant SELECT on `{$config['db_name']}`.`{$table}` to {$config['db_user']}.");
        }
    }

    // 3. name collation — the reason plain "=" needed the exact spelling
    try {
        $row = $pdo->query("
            SELECT COLLATION_NAME AS c
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'characters' AND COLUMN_NAME = 'name'
        ")->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['c'])) {
            $binary = substr($row['c'], -4) === '_bin';
            $add($checks, 'characters.name collation', 'ok', $row['c'] .
                ($binary ? ' (case-sensitive — searches use LOWER() to compensate)' : ' (case-insensitive)'));
        } else {
            $add($checks, 'characters.name collation', 'warn',
                'could not be read from information_schema; name matching uses LOWER() either way');
        }
    } catch (\Throwable $e) {
        $add($checks, 'characters.name collation', 'warn', 'could not read information_schema: ' . $e->getMessage());
    }

    // 4. auth database (GM filter)
    if (empty($config['hide_game_masters'])) {
        $add($checks, 'auth.account_access', 'ok', 'not needed (hide_game_masters is off)');
    } else {
        $ids = gmAccountIds($config);
        if ($ids === null) {
            $errors = array_values(array_filter(dbErrors(), static fn ($e) => str_contains($e['context'], 'account_access')));
            $add($checks, 'auth.account_access', 'warn',
                $errors ? end($errors)['message'] : 'unreadable',
                "GM characters can't be filtered, so they are shown. Fix with: " .
                "GRANT SELECT ON `" . ($config['auth_db_name'] ?? 'auth') . "`.`account_access` TO '{$config['db_user']}'@'%';");
        } else {
            $add($checks, 'auth.account_access', 'ok', count($ids) . ' Game Master account(s) hidden');
        }
    }

    // 5. hotfixes database (optional item source)
    $hotfixes = $config['hotfixes_db_name'] ?? '';
    if ($hotfixes === '') {
        $add($checks, 'hotfixes.item_sparse', 'ok', 'not configured — using the bundled DB2 export');
    } else {
        try {
            $count = (int) $pdo->query("SELECT COUNT(*) FROM `{$hotfixes}`.`item_sparse`")->fetchColumn();
            if ($count === 0) {
                $add($checks, 'hotfixes.item_sparse', 'ok',
                    'reachable but empty — normal on a stock server; item names come from the bundled DB2 export');
            } else {
                $add($checks, 'hotfixes.item_sparse', 'ok', number_format($count) . ' rows (used first, for custom items)');
            }
        } catch (\Throwable $e) {
            $add($checks, 'hotfixes.item_sparse', 'warn', $e->getMessage(),
                'Optional. Custom/hotfixed items will fall back to the bundled DB2 export.');
        }
    }

    // 6. world.item_template — only 3.3.5-era cores still have it
    $world = $config['world_db_name'] ?? '';
    if ($world === '') {
        $add($checks, 'world.item_template', 'ok', 'not configured (expected on 3.4.3)');
    } else {
        try {
            $count = (int) $pdo->query("SELECT COUNT(*) FROM `{$world}`.`item_template`")->fetchColumn();
            $add($checks, 'world.item_template', 'ok', number_format($count) . ' rows (used as a second item source)');
        } catch (\Throwable $e) {
            $add($checks, 'world.item_template', 'ok',
                'absent — normal on 3.4.3, where item templates live in the client DB2 files');
        }
    }

    // 7. bundled DB2 item export
    $db2 = itemDb2Status($config);
    if ($db2['csv'] === null) {
        $add($checks, 'db2/ItemSparse CSV', 'fail', (string) $db2['error'],
            'Equipment can only be named from hotfixes.item_sparse without it.');
    } elseif ($db2['index_built']) {
        $add($checks, 'db2/ItemSparse CSV', 'ok',
            basename($db2['csv']) . ' — ' . number_format($db2['index_count']) . ' items indexed in ' . $db2['cache_dir']);
    } else {
        $add($checks, 'db2/ItemSparse CSV', 'warn',
            basename($db2['csv']) . ' found, but the index could not be built: ' . (string) $db2['error'],
            'Falls back to scanning the CSV on every lookup (slower). Make the cache directory writable to fix.');
    }

    // 8. end-to-end: a character with gear
    try {
        $sample = $pdo->query('
            SELECT c.guid, c.name, COUNT(ci.item) AS items
            FROM characters c
            JOIN character_inventory ci ON ci.guid = c.guid AND ci.bag = 0 AND ci.slot BETWEEN 0 AND 18
            GROUP BY c.guid, c.name
            ORDER BY items DESC
            LIMIT 1
        ')->fetch(PDO::FETCH_ASSOC);

        if (!$sample) {
            $add($checks, 'equipment lookup', 'warn', 'no character on this realm has anything equipped yet');
        } else {
            $inventory = getCharacterInventory($config, (int) $sample['guid']);
            $equipment = $inventory['equipped'];
            $integrity = $inventory['integrity'];

            $carried = count($inventory['backpack']);
            foreach ($inventory['bags'] as $bag) {
                $carried += count($bag['contents']);
            }
            $add($checks, 'characters -> character_inventory', 'ok', sprintf(
                '%s: %d inventory rows — %d equipped, %d profession, %d bag(s), %d carried item(s)',
                $sample['name'],
                $integrity['rows'],
                count($equipment),
                count($inventory['profession']),
                count($inventory['bags']),
                $carried
            ));

            if ($integrity['missing_instance'] > 0 || $integrity['owner_mismatch'] > 0) {
                $add($checks, 'inventory integrity', 'warn', sprintf(
                    '%d row(s) point at an item_instance that no longer exists, %d item(s) whose owner_guid disagrees with character_inventory.guid',
                    $integrity['missing_instance'],
                    $integrity['owner_mismatch']
                ), 'Left over from a crash or a manual DB edit. The Armory shows the rows character_inventory lists for this character, which is the authoritative link.');
            } else {
                $add($checks, 'inventory integrity', 'ok', 'every inventory row resolves to an item_instance owned by that character');
            }

            $named = array_filter($equipment, static fn ($i) => $i['source'] !== 'unresolved');
            $sources = array_count_values(array_map(static fn ($i) => $i['source'], $equipment));
            $detail = $sample['name'] . ': ' . count($named) . '/' . count($equipment) . ' equipped items resolved';
            if ($sources) {
                $detail .= ' (' . implode(', ', array_map(
                    static fn ($k, $v) => "{$v} via {$k}",
                    array_keys($sources),
                    $sources
                )) . ')';
            }
            $add($checks, 'equipment lookup', count($named) === count($equipment) ? 'ok' : 'warn', $detail);
        }
    } catch (\Throwable $e) {
        $add($checks, 'equipment lookup', 'fail', $e->getMessage());
    }

    return $checks;
}

/**
 * Why did the last findCharacter() call come back empty? Set so the profile
 * page can explain itself in debug mode instead of just saying "not found".
 * One of: '', 'no-db', 'not-found', 'hidden-gm'.
 */
function armoryLookupReason(?string $set = null): string
{
    static $reason = '';
    if ($set !== null) {
        $reason = $set;
    }
    return $reason;
}

/**
 * Trace what the Armory sees for one name — used by armory-diagnostics.php.
 *
 * Deliberately looks past every filter the normal lookup applies (deleted
 * characters, GM accounts) so it can tell you *why* a character you know
 * exists isn't showing up.
 */
function armoryTraceName(array $config, string $name): array
{
    $trace = [
        'name'      => $name,
        'error'     => null,
        'matches'   => [],
        'equipment' => [],
    ];

    $pdo = connectCharactersDb($config);
    if (!$pdo) {
        $trace['error'] = 'No connection to the characters database.';
        return $trace;
    }

    [$match, $params] = armoryNameMatch('c.name', trim($name), false);
    $deleteCol = charactersHaveDeleteDate($config) ? 'c.deleteDate' : 'NULL AS deleteDate';

    try {
        $stmt = $pdo->prepare("
            SELECT c.guid, c.account, c.name, c.level, c.race, c.class, {$deleteCol}
            FROM characters c
            WHERE {$match}
            ORDER BY c.guid ASC
            LIMIT 20
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        $trace['error'] = $e->getMessage();
        return $trace;
    }

    $gmIds = gmAccountIds($config);

    foreach ($rows as $row) {
        $deleted = !empty($row['deleteDate']);
        $isGm = $gmIds === null ? null : isset($gmIds[(int) $row['account']]);
        $hidden = $deleted || ($isGm === true && !empty($config['hide_game_masters']));

        $why = 'visible on the Armory';
        if ($deleted) {
            $why = 'deleted character (deleteDate is set) — hidden on purpose';
        } elseif ($isGm === true && !empty($config['hide_game_masters'])) {
            $why = 'account is a Game Master and hide_game_masters is on — set it to false in config.php to show it';
        } elseif ($isGm === null) {
            $why = 'visible (GM status unknown — auth database unreadable)';
        }

        $trace['matches'][] = [
            'guid'    => (int) $row['guid'],
            'name'    => (string) $row['name'],
            'account' => (int) $row['account'],
            'level'   => (int) $row['level'],
            'hidden'  => $hidden,
            'why'     => $why,
        ];

        if (!$hidden && !$trace['equipment']) {
            foreach (getCharacterEquipment($config, (int) $row['guid']) as $slot => $item) {
                $trace['equipment'][] = [
                    'slot'   => equipSlotLabel((int) $slot),
                    'entry'  => (int) $item['entry'],
                    'name'   => (string) $item['name'],
                    'source' => (string) $item['source'],
                ];
            }
        }
    }

    return $trace;
}
