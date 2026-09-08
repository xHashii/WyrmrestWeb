<?php
/**
 * TrinityCore SOAP connection settings.
 *
 * The worldserver must have the SOAP interface enabled (worldserver.conf):
 *   SOAP.Enabled = 1
 *   SOAP.IP      = 0.0.0.0      ; or 127.0.0.1 if only this PC will call it
 *   SOAP.Port    = 7878
 *
 * The account below must exist in the `auth` database with GM level 3
 * (SEC_ADMINISTRATOR) — SOAP refuses lower levels. Create a dedicated
 * account for this instead of reusing your own GM login, e.g.:
 *   account create soapadmin SomeStrongPassword
 *   account set gmlevel soapadmin 3 -1
 *
 * This same connection is also used to pull a live "server info" reading
 * for the status panel on the page, so no extra database access is needed.
 */

return [
    'soap_host' => '127.0.0.1', // worldserver's IP as seen from this PC
    'soap_port' => 7878,
    'soap_user' => 'soapadmin',
    'soap_pass' => 'soapadmin',

    // Shown on the page — edit these to match your server.
    'server_name'    => 'Azeroth Reborn',
    'server_tagline' => 'A community-run TrinityCore 3.4.3 server',
    'realmlist'      => 'logon.yourdomain.com',
    'expansion'      => 'Wrath of the Lich King (3.4.3)',
    'rates_summary'  => 'Blizzlike rates · custom questlines',
    'discord_url'    => '', // leave blank to hide the Discord link
    'report_issue_url' => 'https://github.com/xHashii/3.4.3_Source/issues',
    'client_download_url' => '', // link to your client download, if you have one; leave blank to hide that step

    /**
     * Read-only connection to the `characters` database, used to show
     * who's currently online, the leaderboard, and the Armory (character/
     * guild lookup). Leave 'db_host' blank to hide all of those sections.
     *
     * This one connection is all the Armory strictly needs — characters,
     * their equipped items and guilds all live in the `characters` database:
     *
     *   characters           name, race, class, level, zone, ...
     *   character_inventory  which item instance sits in which slot
     *                        (bag = 0, slot 0-18 = the equipped gear)
     *   item_instance        instance guid -> itemEntry (item template id)
     *   guild / guild_member / guild_rank
     *
     * Two extras are optional. Each is queried on its own and is allowed to
     * fail — if a GRANT is missing you lose that one detail, never the page:
     *
     *   auth.account_access      hides Game Master characters
     *   hotfixes.item_sparse     names for custom / hotfixed items
     *
     *   CREATE USER 'wow_readonly'@'%' IDENTIFIED BY 'SomeStrongPassword';
     *   GRANT SELECT ON characters.* TO 'wow_readonly'@'%';
     *   GRANT SELECT ON hotfixes.item_sparse TO 'wow_readonly'@'%';
     *   GRANT SELECT ON auth.account_access TO 'wow_readonly'@'%';
     *   FLUSH PRIVILEGES;
     * ('%' rather than 'localhost' because PHP connects via 127.0.0.1,
     * and MySQL treats that as a different host than 'localhost'.)
     *
     * Not sure whether all of that is wired up? Open armory-diagnostics.php
     * — it checks every one of these and tells you what to fix.
     */
    'db_host' => '127.0.0.1',
    'db_port' => 3306,
    'db_name' => 'characters',
    'db_user' => 'wow_readonly',
    'db_pass' => 'qazxswer12',

    /**
     * Item names, quality and item level.
     *
     * On 3.4.3 there is no `world`.`item_template` any more — TrinityCore
     * reads item templates straight from the client's DB2 files, and the
     * `hotfixes` database only mirrors the rows the server hotfixes on top
     * of them (usually none at all). So the Armory resolves an itemEntry in
     * this order:
     *
     *   1. hotfixes.item_sparse   custom/edited items win
     *   2. world.item_template    only exists on 3.3.5-era cores; skipped otherwise
     *   3. db2/ItemSparse.*.csv   the bundled client export — always works
     *
     * Leave a name blank to skip that source entirely.
     */
    'world_db_name'    => 'world',    // only used if this core still has item_template
    'auth_db_name'     => 'auth',     // account_access, for hiding GM characters
    'hotfixes_db_name' => 'hotfixes', // item_sparse, for custom/hotfixed items

    // Where the bundled DB2 export and its generated index live. The defaults
    // are the db2/ and cache/ folders next to this file; cache/ must be
    // writable by the web server, otherwise the CSV is scanned on every
    // lookup (slower, but still correct).
    // 'db2_dir'   => __DIR__ . '/db2',
    // 'cache_dir' => __DIR__ . '/cache',

    'realm_id'      => 1,       // matches your realm's ID in the auth db
    'hide_game_masters' => true, // hide GM characters/guild members from the Armory

    /**
     * Show what a character is carrying (equipped bags + backpack) on their
     * Armory page. `character_inventory` holds those rows right next to the
     * equipped ones, so it costs nothing extra to read; set it to false if
     * you'd rather keep player inventories private. Bank, buyback and
     * reagent-bank slots are never shown either way.
     */
    'show_bag_contents' => true,

    // Local images/items/<IconFileDataID>.png/.jpg/.webp always win. Otherwise
    // load real item icons from Wowhead using data/item-icon-names.json.
    // Set false for a fully local deployment; slot outlines/details still work.
    'remote_item_icons' => true,

    // Optional checkbox on character profiles. Model libraries load only when
    // enabled by a visitor. PHP cURL + outbound HTTPS are required for the
    // fixed-origin model-asset endpoint; its cache is capped at 128 MiB.
    // Equipment lookup never depends on 3D being available.
    'enable_3d_viewer' => true,

    /**
     * Show the full item stats inside each gear slot's tooltip, pulled from
     * Wowhead (the exact stat block, equip effects and quality colours players
     * see on the site). PHP cURL + outbound HTTPS to wowhead.com are required
     * — the same reach the 3D viewer already relies on. Results are cached on
     * disk (cache/wowhead/) so each item is fetched at most once. If the fetch
     * fails for any reason the tooltip simply falls back to the name / quality
     * / item level the Armory already resolves, so the page never breaks.
     */
    'wowhead_tooltips' => true,   // set false to keep the old link-only tooltip
    'wowhead_locale'  => 'en',    // tooltip language (en, de, fr, es, ru, …)
    // 'wowhead_cache_ttl' => 86400 * 30,   // how long a good tooltip stays cached
    // 'wowhead_fail_ttl'  => 3600,         // back off before retrying a failed item
    // 'wowhead_timeout'       => 10,       // per-request timeout (seconds)
    // 'wowhead_connect_timeout' => 4,      // connection timeout (seconds)

    /**
     * When true, a "Connection details" toggle appears in the status bar
     * showing the raw SOAP error if the realm is offline, and the Armory
     * pages print the reason behind any database problem they hit (instead
     * of silently showing an empty result). Leave false once everything
     * works, so real visitors don't see internal connection errors.
     */
    'debug' => false,
];
