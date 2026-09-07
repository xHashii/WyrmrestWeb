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
  armory-suggest.php   Armory: JSON type-ahead for the search box
  armory-diagnostics.php  Armory: database/data-source self-check
  character.php        Armory: character profile + equipment
  guild.php            Armory: guild roster
  includes/
    bootstrap.php      Session start, config load, SOAP/DB helper functions
    itemdb.php         Item names/quality from the bundled DB2 export
    db-errors.php      Inline database-problem notice (debug mode only)
    header.php         Shared <head>/styles, nav bar, status bar
    footer.php         Closes the page, site footer, copy-to-clipboard script
  data/
    zones.php          Zone ID -> name lookup for "Who's Online"
  db2/
    ItemSparse.*.csv   3.4.3 client item export (item names/quality/ilvl)
  cache/
    items-*.idx/.dat   Generated item index (auto-rebuilt, safe to delete)
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
leaderboard.

**What's included:** character search (type the first three letters and
get every name that starts with them, with live suggestions), a
character page (level, race, class, faction, guild, zone, played time,
online status, average item level, and equipped gear by slot with
quality colors and item levels), guild search, a guild roster page, and
a diagnostics page that checks every database and file the Armory needs.

**What's not included** (present in the original Node app, cut here to
keep this a reasonable scope): talent trees, glyphs, achievements,
PvP/arena ladder, transmog, and item icons — the icon images would have
to be extracted from the game client, which this project doesn't do.
Equipped items show as quality-colored text with their item level.

### Files

```
armory.php              Search page (character or guild, by name prefix)
armory-suggest.php      JSON type-ahead endpoint used by the search box
armory-diagnostics.php  "why doesn't my character show up" checker
character.php           Character profile + equipment
guild.php               Guild roster
includes/itemdb.php     Item names from the bundled DB2 export
cache/                  Generated item index (safe to delete, rebuilt on demand)
db2/                    ItemSparse/Item CSV exports from the 3.4.3 client
```

Plus the query functions in `includes/bootstrap.php` (search for
"Armory:" in that file).

### Where the data actually lives

This is the part that trips everyone up, so, concretely, for
TrinityCore 3.4.3 ([characters DB reference][tc-chars]):

| What | Where |
| --- | --- |
| Characters | `characters`.`characters` |
| Which item is in which slot | `characters`.`character_inventory` — `bag = 0`, `slot` 0-18 are the equipped slots |
| The item object itself | `characters`.`item_instance` (`itemEntry` = item template id) |
| Guilds | `characters`.`guild`, `guild_member`, `guild_rank` |
| Item name / quality / item level | **not** in the world DB — see below |
| Who is a Game Master | `auth`.`account_access` (optional) |

[tc-chars]: https://trinitycore.info/en/database/master/characters/home

On 3.4.3 (and master) there is **no `world`.`item_template`** any more.
TrinityCore reads item templates straight out of the client's DB2 files,
and the `hotfixes` database only mirrors the rows the server has to
hotfix down to the client — on a stock server `hotfixes.item_sparse` is
usually completely empty. Joining a character's gear against it
therefore returns nothing, which is why equipment used to come up blank.

So item entries are resolved in this order:

1. `hotfixes`.`item_sparse` — custom or edited items win (highest
   `VerifiedBuild` per id).
2. `world`.`item_template` — only exists on 3.3.5-era cores; skipped
   automatically when it isn't there.
3. `db2/ItemSparse.*.csv` — the bundled 3.4.3 client export, 45k items.
   On first use it is compiled into a small sorted binary index in
   `cache/` (about 1.7 MB) so lookups are a binary search; if `cache/`
   isn't writable the CSV is scanned instead — slower, but still correct.

To swap in a newer client export, drop the new
`ItemSparse.<build>.csv` into `db2/`; the index rebuilds itself.

### Name matching

`characters`.`name` uses the **`utf8mb4_bin`** collation, which compares
byte for byte — `WHERE name = 'sylea'` does not match `Sylea`, and
neither does `LIKE 'syl%'`. Every name comparison in the Armory
therefore also compares `LOWER(name)`, so searching and opening a
profile work in any capitalisation. Search results link by `guid`
(`character.php?guid=123`), so clicking a result can't fail on spelling;
`character.php?name=Sylea` still works for typed URLs.

Searches are prefix searches with a three-character minimum
(`ARMORY_MIN_SEARCH_LENGTH` in `includes/bootstrap.php`), and `%`/`_`
typed by a visitor are escaped rather than treated as wildcards.

### Setup

It uses the same `wow_readonly` user as "Who's Online". Only the
`characters` grant is required; the other two are optional extras:

```sql
GRANT SELECT ON characters.* TO 'wow_readonly'@'%';
GRANT SELECT ON auth.account_access TO 'wow_readonly'@'%';   -- hides GM characters
GRANT SELECT ON hotfixes.item_sparse TO 'wow_readonly'@'%';  -- custom item names
FLUSH PRIVILEGES;
```

In `config.php`, alongside the existing `db_*` settings:
```php
'world_db_name'    => 'world',    // only used if your core still has item_template
'auth_db_name'     => 'auth',     // database holding account_access
'hotfixes_db_name' => 'hotfixes', // database holding item_sparse
'realm_id'         => 1,          // matches your realm's ID in the auth db
'hide_game_masters' => true,
```
`realm_id` should match the realm's actual ID (`realmlist` table in
`auth`) — `account_access` rows are scoped per-realm (plus `RealmID = -1`
for "all realms" GMs).

Missing grants no longer break pages. If `auth`.`account_access` can't be
read, GM characters simply aren't hidden; if `hotfixes`.`item_sparse`
can't be read, item names come from the bundled DB2 export.

### When something doesn't show up: armory-diagnostics.php

Open `armory-diagnostics.php` on your server. It checks, one line each:

- the `characters` connection and every table the Armory reads,
- the collation of `characters`.`name`,
- whether `auth`.`account_access` is readable (and the exact `GRANT`
  statement to run if it isn't),
- whether `hotfixes`.`item_sparse` exists, is empty, or is unreadable,
- whether the bundled DB2 export and its index are usable,
- and an end-to-end test: it picks a character that has gear and reports
  how many of their items it could name, and from which source.

It also has a **name tracer**: type a character name and it shows every
matching row regardless of capitalisation, whether that character is
deleted or on a GM account, and each equipped item with the source its
name came from. Set `'debug' => true` in `config.php` to see full error
messages (they're hidden from visitors otherwise); with debug on, the
Armory pages also print any database problem they hit inline.
