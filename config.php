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
     * The Armory also reads `item_template` from the world database and
     * `account_access` from the auth database (to hide GM characters) —
     * same user, just needs SELECT granted on those two tables too:
     *   CREATE USER 'wow_readonly'@'%' IDENTIFIED BY 'SomeStrongPassword';
     *   GRANT SELECT ON characters.* TO 'wow_readonly'@'%';
     *   GRANT SELECT ON world.item_template TO 'wow_readonly'@'%';
     *   GRANT SELECT ON auth.account_access TO 'wow_readonly'@'%';
     *   FLUSH PRIVILEGES;
     * ('%' rather than 'localhost' because PHP connects via 127.0.0.1,
     * and MySQL treats that as a different host than 'localhost'.)
     */
    'db_host' => '127.0.0.1',
    'db_port' => 3306,
    'db_name' => 'characters',
    'db_user' => 'wow_readonly',
    'db_pass' => 'qazxswer12',
    'world_db_name' => 'world', // database name for item_template lookups (Armory)
    'auth_db_name'  => 'auth',  // database name for account_access (Armory GM hiding)
    'realm_id'      => 1,       // matches your realm's ID in the auth db
    'hide_game_masters' => true, // hide GM characters/guild members from the Armory

    /**
     * When true, a "Connection details" toggle appears in the status bar
     * showing the raw SOAP error if the realm is offline — handy while
     * you're setting things up. Leave false once it's working, so real
     * visitors don't see internal connection errors.
     */
    'debug' => false,
];
