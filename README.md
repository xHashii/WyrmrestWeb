# TrinityCore 3.4.3 Server Site (Register / Who's Online / Leaderboard)

A small multi-page PHP site: a Home landing page with a nav bar, an account
registration page (via SOAP), a live "Who's Online" list, a Top Characters
leaderboard, and a Server Info page. All pages share one nav bar, status
bar, and visual style.

Registration calls the worldserver's SOAP interface to run
`bnetaccount create <user> <pass>` — the same command you'd type in the
console — instead of writing to the database directly. This matters because
bnet accounts use a different password-hashing scheme than regular WoW
accounts, so letting the server do the hashing itself avoids a broken login.

## Directory structure

Everything lives directly in your web root (`htdocs`, or wherever your
DocumentRoot points) — no subfolder needed. Nothing in the code hardcodes
a folder name; every reference is relative, so this works whether it's
at the root or nested. Just keep everything below in the same place
relative to each other:

```
htdocs/                    (or wherever your web root is)
  config.php           SOAP/DB credentials + site text (server name, rates, links)
  index.php            Home page
  register.php         Create Account
  online.php           Who's Online (paginated)
  leaderboard.php      Top Characters
  info.php             Realmlist, expansion, rates, Discord/support links
  armory.php           Armory: character/guild search
  character.php        Armory: character profile + equipment
  guild.php            Armory: guild roster
  includes/
    bootstrap.php      Session start, config load, SOAP/DB helper functions
    header.php         Shared <head>/styles, nav bar, status bar
    footer.php         Closes the page, site footer, copy-to-clipboard script
  data/
    zones.php          Zone ID -> name lookup for "Who's Online"
  images/
    class/, race/      Class/race icons (you provide these — see below)
```

Every page follows the same three-line pattern:
```php
require __DIR__ . '/includes/bootstrap.php';
// ...page-specific PHP (fetch data, handle POST, etc.)...
$activePage = 'online';           // highlights the matching nav link
$pageTitle = "Who's Online";      // shown in the <title>
require __DIR__ . '/includes/header.php';
?>
<!-- page HTML -->
<?php require __DIR__ . '/includes/footer.php'; ?>
```

## 1. Enable SOAP on the worldserver

In `worldserver.conf`:
```
SOAP.Enabled = 1
SOAP.IP      = 0.0.0.0
SOAP.Port    = 7878
```
Restart worldserver after changing this.

## 2. Create a GM account for SOAP to use

SOAP authenticates against the plain `account` table (a regular WoW game
login) — **not** `battlenet_accounts`. Requires GM level 3
(SEC_ADMINISTRATOR) — lower levels are rejected. In the worldserver
console (or via an existing GM):
```
account create soapadmin SomeStrongPassword
account set gmlevel soapadmin 3 -1
```
Don't create this one with `bnetaccount create` — that writes to a
different table, and TrinityCore will also auto-provision a linked game
account with an auto-generated username like `21#1` instead of
`soapadmin`, which won't match what's in `config.php`.

Put that username/password into `config.php`.

## 3. Enable the SOAP extension in XAMPP's PHP

Edit `php.ini` (XAMPP Control Panel → Apache → Config → php.ini) and
uncomment:
```
extension=soap
```
Restart Apache.

## 4. Configure and deploy

- Edit `config.php`:
  - `soap_host` — `127.0.0.1` if worldserver runs on the same PC as XAMPP.
  - `soap_port` — matches `SOAP.Port` above (default `7878`).
  - `soap_user` / `soap_pass` — the GM account from step 2.
  - `server_name`, `server_tagline`, `realmlist`, `expansion`,
    `rates_summary`, `discord_url` — shown around the site; set
    `discord_url` to `''` to hide that link.
- Copy every file/folder above (`includes/`, `data/`, and once you have
  it, `images/`) directly into your web root — e.g.
  `C:\xampp\htdocs\` — not into a subfolder.
- Visit `http://localhost/` locally, or `http://<your-radmin-ip>/` over
  Radmin.

## 5. (Optional) Show who's online / leaderboard

Both the "Who's Online" list and the leaderboard need read access to the
`characters` database — SOAP alone can't provide per-character data.
Create a read-only MySQL user for it (don't use root):
```sql
CREATE USER 'wow_readonly'@'%' IDENTIFIED BY 'SomeStrongPassword';
GRANT SELECT ON characters.characters TO 'wow_readonly'@'%';
FLUSH PRIVILEGES;
```
Use `'%'` rather than `'localhost'` — PHP connects via `127.0.0.1`, and
MySQL treats that as a different host than `localhost` for user auth, so
a `localhost`-only user won't authenticate.

Then fill in `db_host`, `db_port`, `db_name`, `db_user`, `db_pass` in
`config.php`. Leave `db_host` blank and both pages just show "0 players
online" / "no character data" instead of breaking.

Also make sure PHP's `pdo_mysql` extension is enabled in `php.ini`
(usually on by default in XAMPP).

### Class/race icons

The list expects an `images/` folder sitting next to `index.php`, laid out
exactly like this:
```
images/
  class/1.gif ... class/11.gif          (class id, no gender)
  race/1-0.gif, race/1-1.gif ...        (race id "-" gender: 0=male, 1=female)
```
These are Blizzard's own game icons, so they aren't included here — drop
in your own copy of that folder (from wherever you sourced the icon set)
directly into your web root, as a sibling of `index.php`/`online.php`/etc.
(so it ends up at `htdocs/images/class/...` and `htdocs/images/race/...`,
not nested inside another folder). If an icon fails to load, it shows as
a small dimmed square instead of a broken-image glyph — but that still
means the file isn't where the page expects it. Open an icon URL directly
in the browser (e.g. `http://localhost/images/class/1.gif`) to check for
a 404; if you get one, the `images/` folder either isn't there or isn't
sitting at the same level as `index.php`.

### Zone names

`data/zones.php` maps zone ID → name (generated from an AreaTable export)
so the list can show "Elwynn Forest" instead of a raw number. It's ~2,373
entries, all continents/expansions included. If a character is standing
in a zone ID that isn't in the table, it just falls back to showing
"Zone #<id>" instead of breaking.

## Server status (every page)

The status bar in the nav runs `server info` over the same SOAP connection
used for account creation (no extra database access needed) and shows the
online/offline pill, uptime, player count, and the realmlist address with
a copy button — on every page, since `header.php` is shared.
- Player count and uptime are parsed from the command's text output. The
  exact wording of `server info` can vary a little by build — if the
  player count or uptime doesn't show up, open `includes/bootstrap.php`
  and adjust the two regexes in `getServerStatus()` to match what your
  server actually prints (you can temporarily `var_dump($raw)` there to
  see the raw text).
- If the realm is offline, visitors just see "Realm Offline" — the raw
  connection error is hidden by default. Set `'debug' => true` in
  `config.php` to show a "Connection details" toggle with the actual
  error message while you're setting things up; turn it back off before
  sending people to the page.

## Pages

- **Home** (`index.php`) — hero + four cards linking to the other pages.
- **Register** (`register.php`) — the account creation form.
- **Who's Online** (`online.php`) — live roster, 15 per page, paginated.
- **Leaderboard** (`leaderboard.php`) — top 20 characters by level.
- **Server Info** (`info.php`) — realmlist (with its own copy button),
  expansion, rates, Discord, and support link.

All pages collapse to a single column on narrow/mobile screens.

## Notes

- Battle.net accounts (the `bnet_accounts` table) don't have a separate
  username column — the login is the `email` field, which is why the form
  asks for one and requires an `@`.
- Email/password are restricted to characters with no spaces, since the
  console command is space-delimited — a space would be parsed as a
  second argument.
- This has no rate limiting or CAPTCHA. Since it's only reachable over your
  Radmin network that's usually fine, but add one if you ever expose it
  more broadly.
- The SOAP connection uses plain HTTP — TrinityCore's built-in SOAP server
  doesn't do TLS. Fine for a local/Radmin-only setup; don't expose port
  7878 to the public internet as-is.

## Armory (character/guild lookup)

A native PHP armory — ported from the uploaded Node.js/TypeScript
`wyrmrest-armory` project, covering its core lookup features. It reuses
the same `characters` DB connection as "Who's Online" and the
leaderboard, plus two more tables (see grants below).

**What's included:** character search, a character page (level, race,
class, faction, guild, zone, played time, online status, and equipped
gear by slot), guild search, and a guild roster page (members, ranks,
levels).

**What's not included** (present in the original Node app, cut here to
keep this a reasonable scope): talent trees, glyphs, achievements,
PvP/arena ladder, transmog, and item tooltips/icons — showing an item's
name and quality color is straightforward from `item_template`, but
actual item icon images would need extracting from the game client's
MPQ files, which this project doesn't do. Equipped items show as
quality-colored text only, no icon graphic.

### Files

```
armory.php       Search page (character or guild, by name)
character.php    Character profile + equipment
guild.php        Guild roster
```

Plus the query functions in `includes/bootstrap.php` (search for
"Armory:" in that file).

### Setup

It uses the same `wow_readonly` user as "Who's Online", widened to
cover two more tables — `item_template` (world db, for item names) and
`account_access` (auth db, to hide GM characters from search/rosters,
same as the `hide_game_masters` behavior the Node app had):
```sql
GRANT SELECT ON characters.* TO 'wow_readonly'@'%';
GRANT SELECT ON world.item_template TO 'wow_readonly'@'%';
GRANT SELECT ON auth.account_access TO 'wow_readonly'@'%';
FLUSH PRIVILEGES;
```
(If you already ran the widened `characters.*` grant from the
"Who's Online" section, you only need the two new lines.)

In `config.php`, alongside the existing `db_*` settings:
```php
'world_db_name' => 'world', // database name for item_template lookups
'auth_db_name'  => 'auth',  // database name for account_access
'realm_id'      => 1,       // matches your realm's ID in the auth db
'hide_game_masters' => true,
```
If your world/auth databases are named something other than `world`/
`auth`, change those two values to match. `realm_id` should match the
realm's actual ID (`realmlist` table in `auth`) — `account_access` rows
are scoped per-realm (plus `RealmID = -1` for "all realms" GMs).

That's it — no separate server, no separate port, no Node.js involved.
The "Armory" nav link is always shown; if `db_host` isn't configured
yet, the page just says so instead of erroring.

### Schema notes

I verified every table/column name used here (`characters`,
`character_inventory`, `item_instance`, `item_template`, `guild`,
`guild_member`, `guild_rank`, `account_access`) against TrinityCore's
own schema docs while writing this — not guessed. If something still
doesn't match your exact database version, tell me the error and I'll
adjust the specific query.
