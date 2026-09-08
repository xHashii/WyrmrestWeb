<?php
/**
 * Run against a dedicated MySQL/MariaDB test database, never the live realm:
 *   ARMORY_TEST_DB_NAME=armory_test ARMORY_TEST_DB_USER=test php tests/armory-search.php
 * Optional scenarios (separate processes reset the request-local DB caches):
 *   php tests/armory-search.php no-delete-date
 *   php tests/armory-search.php no-auth
 *   php tests/armory-search.php show-gms
 *
 * Fixtures use connection-local temporary tables; no existing rows are changed.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (!getenv('ARMORY_TEST_DB_NAME') || !getenv('ARMORY_TEST_DB_USER')) {
    fwrite(STDERR, "Set ARMORY_TEST_DB_NAME and ARMORY_TEST_DB_USER to a dedicated test database.\n");
    exit(1);
}

require __DIR__ . '/../includes/bootstrap.php';

$scenario = $argv[1] ?? 'normal';
if (!in_array($scenario, ['normal', 'no-delete-date', 'no-auth', 'show-gms'], true)) {
    throw new InvalidArgumentException('Unknown test scenario: ' . $scenario);
}

$config = array_replace($config, [
    'db_host' => getenv('ARMORY_TEST_DB_HOST') ?: '127.0.0.1',
    'db_port' => (int) (getenv('ARMORY_TEST_DB_PORT') ?: 3306),
    'db_name' => getenv('ARMORY_TEST_DB_NAME'),
    'db_user' => getenv('ARMORY_TEST_DB_USER'),
    'db_pass' => getenv('ARMORY_TEST_DB_PASS') ?: '',
    // The test DB also holds the temporary account_access table.
    'auth_db_name' => getenv('ARMORY_TEST_DB_NAME'),
    'realm_id' => 7,
    'hide_game_masters' => $scenario !== 'show-gms',
]);

$pdo = connectCharactersDb($config);
if (!$pdo) {
    throw new RuntimeException('Test database connection failed: ' . json_encode(dbErrors()));
}

$pdo->exec('CREATE TEMPORARY TABLE characters (
    guid BIGINT UNSIGNED PRIMARY KEY,
    account INT UNSIGNED NOT NULL,
    name VARCHAR(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
    race TINYINT NOT NULL DEFAULT 1,
    class TINYINT NOT NULL DEFAULT 1,
    gender TINYINT NOT NULL DEFAULT 0,
    level TINYINT NOT NULL DEFAULT 1,
    online TINYINT NOT NULL DEFAULT 0,
    zone INT NOT NULL DEFAULT 0,
    map INT NOT NULL DEFAULT 0,
    money BIGINT NOT NULL DEFAULT 0,
    totaltime INT NOT NULL DEFAULT 0,
    leveltime INT NOT NULL DEFAULT 0,
    totalKills INT NOT NULL DEFAULT 0,
    logout_time BIGINT NOT NULL DEFAULT 0,
    deleteDate BIGINT DEFAULT NULL
)');
$auth = str_replace('`', '``', $config['auth_db_name']);
$pdo->exec("CREATE TEMPORARY TABLE `{$auth}`.account_access (
    AccountID INT UNSIGNED NOT NULL,
    SecurityLevel TINYINT UNSIGNED NOT NULL,
    RealmID INT NOT NULL,
    PRIMARY KEY (AccountID, RealmID)
)");
$pdo->exec("INSERT INTO `{$auth}`.account_access VALUES
    (900, 3, 7), (900, 3, -1), (901, 2, -1), (902, 3, 99), (903, 0, 7)");
// Empty guild tables ensure a newly created, guildless character can be opened.
$pdo->exec('CREATE TEMPORARY TABLE guild (guildid INT, name VARCHAR(24))');
$pdo->exec('CREATE TEMPORARY TABLE guild_member (guildid INT, guid BIGINT, `rank` INT)');
$pdo->exec('CREATE TEMPORARY TABLE guild_rank (guildid INT, rid INT, rname VARCHAR(24))');

$insert = $pdo->prepare('INSERT INTO characters (guid, account, name, level, online, deleteDate)
    VALUES (?, ?, ?, ?, ?, ?)');
$fixtures = [
    [1, 100, 'Fresh', 1, 0, null],
    [2, 100, 'Freshmax', 80, 1, null],
    [3, 101, 'Freshstart', 1, 0, 0],
    [4, 100, 'Li', 1, 0, null],
    [5, 100, 'Éa', 1, 0, null],
    [6, 100, 'Orderalpha', 1, 0, null],
    [7, 100, 'Orderzulu', 80, 1, null],
    [8, 902, 'Playerother', 1, 0, null],
    [9, 903, 'Playerzero', 1, 0, null],
    [10, 100, 'Playernone', 1, 0, null],
    [11, 900, 'Freshgm', 80, 1, null],
    [12, 901, 'Freshglobal', 1, 0, null],
    [13, 100, 'Freshgone', 80, 0, 1700000000],
    [14, 100, '', 1, 0, null],
    [15, 100, null, 1, 0, null],
    [16, 100, 'Lit%eral', 1, 0, null],
    [17, 100, 'Lit_eral', 1, 0, null],
    [18, 100, 'Lit!eral', 1, 0, null],
    [19, 100, 'Litxeral', 1, 0, null],
    [20, 100, 'Gmczed', 1, 0, null],
];
// More hidden GMs than either result limit, all ahead of the regular level 1.
for ($i = 0; $i < 40; $i++) {
    $fixtures[] = [100 + $i, 900, sprintf('Gmcgm%02d', $i), 80, 1, null];
}
// More ordinary characters than the per-call cap, with a level 1 at the end.
$pageNames = [];
for ($i = 0; $i < 234; $i++) {
    $name = 'Pager' . chr(97 + intdiv($i, 26)) . chr(97 + $i % 26);
    $fixtures[] = [1000 + $i, 100, $name, 80, 1, null];
    $pageNames[] = $name;
}
$fixtures[] = [2000, 100, 'Pagerzz', 1, 0, null];
$pageNames[] = 'Pagerzz';
foreach ($fixtures as $fixture) {
    $insert->execute($fixture);
}

$checks = 0;
function checkSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true));
    }
    $GLOBALS['checks']++;
}
function names(array $rows): array
{
    return array_column($rows, 'name');
}

if ($scenario === 'no-delete-date') {
    $pdo->exec('ALTER TABLE characters DROP COLUMN deleteDate');
    checkSame(false, charactersHaveDeleteDate($config), 'Forks without deleteDate are supported');
    checkSame(['Fresh'], names(searchCharacters($config, 'fresh', 1)), 'Level 1 still appears without deleteDate');
    checkSame(['Gmczed'], names(searchCharacters($config, 'gmc', 10)), 'GM filtering still precedes the limit');
} elseif ($scenario === 'no-auth') {
    $config['auth_db_name'] = 'armory_test_missing_auth';
    checkSame(['Fresh'], names(searchCharacters($config, 'fresh', 1)), 'Missing auth must not break ordinary searches');
    checkSame(null, gmAccountIds($config), 'Unreadable auth retains the documented unknown status');
    checkSame(true, count(dbErrors()) > 0, 'An unreadable GM filter is recorded for diagnostics');
    checkSame('Fresh', findCharacter($config, 1)['name'], 'A level 1 profile survives missing auth access');
} elseif ($scenario === 'show-gms') {
    checkSame(['Freshglobal', 'Freshgm'], names(searchCharacters($config, 'freshg')), 'The hide_game_masters opt-out still works');
    checkSame('Freshgm', findCharacter($config, 11)['name'], 'Opt-out also applies to direct profiles');
} else {
    checkSame(['Fresh', 'Freshmax', 'Freshstart'], names(searchCharacters($config, '  fReSh  ')),
        'Online/offline characters at levels 1 and 80 match case-insensitively; only GMs and deleted rows are hidden');
    checkSame(['Fresh'], names(searchCharacters($config, 'fresh', 1)), 'An exact level 1 match takes priority');
    checkSame(['Orderalpha'], names(searchCharacters($config, 'ord', 1)), 'Level does not determine prefix ranking');
    checkSame(['Li'], names(searchCharacters($config, 'li', 1)), 'Two-letter character names are searchable');
    if (function_exists('mb_strtolower')) {
        checkSame(['Éa'], names(searchCharacters($config, 'ÉA')), 'Two-letter Unicode names match without case sensitivity');
        checkSame([], searchCharacters($config, 'É'), 'Minimum length counts Unicode characters, not bytes');
    }
    foreach (['', '  ', 'f'] as $query) {
        checkSame([], searchCharacters($config, $query), 'Empty and too-short searches stay bounded');
    }
    checkSame(['Gmczed'], names(searchCharacters($config, 'gmc')), 'Hidden GMs cannot exhaust the full-search limit');
    checkSame(['Gmczed'], names(searchCharacters($config, 'gmc', 10)), 'Hidden GMs cannot exhaust the suggestion limit');
    checkSame(['Playernone', 'Playerother', 'Playerzero'], names(searchCharacters($config, 'player')),
        'No access row, SecurityLevel 0, and GM status on another realm are all ordinary players here');
    foreach (['%', '_', '!'] as $literal) {
        checkSame(['Lit' . $literal . 'eral'], names(searchCharacters($config, 'lit' . $literal)),
            'LIKE metacharacters are treated literally');
    }
    checkSame([], searchCharacters($config, "fr' OR 1=1 --"), 'Input stays parameterized');

    $paged = [];
    for ($offset = 0; $offset < count($pageNames); $offset += 30) {
        $paged = array_merge($paged, names(searchCharacters($config, 'pager', 30, $offset)));
    }
    checkSame($pageNames, $paged, 'Pagination exposes all 235 matches without duplicates or gaps, including the last level 1');
    checkSame([], searchCharacters($config, 'pager', 30, count($pageNames)), 'Pagination stops after the final match');
    checkSame(200, count(searchCharacters($config, 'pager', 1000)), 'The per-call safety cap is preserved');
    checkSame(['Fresh'], names(searchCharacters($config, 'fresh', 0, -10)), 'Limit/offset lower bounds are safe');

    $fresh = findCharacter($config, 1);
    checkSame('Fresh', $fresh['name'], 'A level 1 profile opens by guid');
    checkSame(1, (int) $fresh['level'], 'The profile retains level 1');
    checkSame(0, (int) $fresh['totaltime'], 'Never-played characters do not need played time');
    checkSame(null, $fresh['guild'], 'Guild membership is not required');
    checkSame('Fresh', findCharacter($config, null, 'fReSh')['name'], 'A level 1 profile opens by name');
    checkSame('Li', findCharacter($config, null, 'li')['name'], 'A two-letter profile opens by name');
    foreach ([11, 12, 13, 14, 15] as $guid) {
        checkSame(null, findCharacter($config, $guid), 'GM, deleted, and blank-name profiles stay hidden');
    }
    checkSame(null, findCharacter($config, null, 'freshgm'), 'GM profiles also stay hidden by name');
    checkSame('hidden-gm', armoryLookupReason(), 'Diagnostics still explain a hidden GM');
    $showGms = array_replace($config, ['hide_game_masters' => false]);
    checkSame(['Freshglobal', 'Freshgm'], names(searchCharacters($showGms, 'freshg')),
        'Opting out works even after the GM account cache was populated');
    checkSame([], dbErrors(), 'Normal searches and profiles produce no database errors');
}

echo "PASS: {$scenario} ({$checks} checks)\n";
