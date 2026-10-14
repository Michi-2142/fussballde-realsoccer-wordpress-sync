# Fussball.de + Real Soccer WordPress Sync

A WordPress plugin that automatically syncs match fixtures and results of one or more teams from [fussball.de](https://www.fussball.de) into its own "Spiele" (matches) post type. Since version 2.0 it is standalone: it no longer needs the Goodlayers Real Soccer theme or the Goodlayers Soccer plugin.

## Overview

German amateur football clubs using the Real Soccer WordPress theme can use this plugin to keep their website's match schedule and results up-to-date automatically, with no manual data entry.

The plugin fetches data from fussball.de's AJAX endpoints, decodes the obfuscated scores (fussball.de uses custom fonts to hide scores from scrapers), and writes every match into the post type `fussball_spiel` with flat meta fields. Each match belongs to one team (taxonomy `fussball_mannschaft`), so several teams of one club (for example the first team and a reserve "SGM") live side by side. Themes read the data through a small PHP API.

## Project Structure

```
fussball-sync.php           Main plugin file (cron, activation, AJAX, team list, team name keys)
admin/
  settings-page.php         WP admin settings UI (team table, interval, season, log, 1.x migration button)
includes/
  spiele.php                Data model: post type, taxonomy, meta fields, read/write API, edit screen, list columns
  migration.php             One-time migration from Goodlayers Soccer (fixture_and_result) with rollback
  tabelle.php               League table: fetch, parse and store per team, crest lookup
  class-fussball-parser.php Fetches & parses fussball.de HTML
  class-font-decoder.php    Decodes obfuscated scores (TTF font parsing)
  class-sync-manager.php    Creates/updates/cleans up match posts
```

## Features

- **Automatic sync** via WP-Cron (hourly / 6 h / 12 h / twice daily / daily / disabled)
- **Standalone data model** (since 2.0.0): post type `fussball_spiel` (admin only, menu "Spiele"), taxonomy `fussball_mannschaft`, flat meta fields, German edit screen with media picker for crests, list columns (kick-off, result, team, competition, source)
- **League table** (since 2.1.0): after each team sync the current table of the team's league is fetched from fussball.de (the division is read from the team page) and stored per team; crests come from existing matches or the Media Library, never as images from fussball.de. Teams without a league (friendlies only) are skipped with a log entry
- **Multiple teams** (since 1.1.0): every team row has a label (= team), its fussball.de URL and an optional league display name
- **Manual sync** button in the admin panel
- **Score decoding**: parses fussball.de's custom obfuscation fonts (PUA characters in TTF) to extract real scores
- **Fallback**: if font decoding fails, fetches individual match detail pages
- **Real match IDs** (since 1.2.0): posts are matched by the fussball.de match ID taken from the fixture link; a pseudo ID built from date and normalised team names is only a fallback
- **Tolerant team matching**: `fussball_sync_team_key()` ignores case, bracketed suffixes such as "(Flex)", roman numerals in Spielgemeinschaft names ("Wittendorf II/Lombach I/Loßburg I"), "e.V." and soft hyphens, so renamed teams no longer create duplicate fixtures
- **Smart updates**: only updates posts when data actually changed (score, date/time, teams)
- **Deduplication**: fixtures with the same date, home and away team are merged; the post with a score wins, then the one with a real match ID, then the one with team flags, then the newest. The rest goes to the trash
- **Orphan cleanup**: fixtures in the team's categories that fussball.de no longer lists and that have no score are trashed (guarded: at least 5 matches fetched and the matching past/upcoming list was not empty). Cancelled matches without a score are trashed as well
- **Team isolation**: lookups, the key cache and deduplication only see the team's own matches (and matches without a team), so a friendly between two teams of the same club stays in both schedules
- **Old URLs**: requests to `/fixture_and_result/<slug>/` are redirected (301) to the team's page
- **Team flag resolution**: automatically finds team logos/flags from existing posts or the Media Library
- **Competition types**: `fussball_spiel_art()` tells league, cup and friendly apart; `fussball_spiel_wettbewerb_anzeige()` builds labels such as "Kreisliga B3 26/27" or "Freundschaftsspiel"
- **Dry run**: `fussball_sync_run_team( $team + array( 'dry_run' => true ), $season_start, $season_end )` logs every action with "(dry)" and writes nothing
- **Detailed logging**: the last 400 entries are kept, the admin panel shows the most recent ones

## Installation

1. Clone or copy this repository into `wp-content/plugins/fussball-sync/`
2. Activate the plugin in WordPress Admin > Plugins
3. Go to **Settings > Fussball.de Sync**
4. Add one row per team: label (e.g. "2. Mannschaft"), the team's fussball.de URL (the Mannschaftsspielplan page) and optionally the league name to display, e.g.:
   ```
   https://www.fussball.de/mannschaft/sv-example/-/saison/2627/team-id/011MIB1NCS000000VTVG0001VTR8C1K7#!/
   ```
5. Choose a sync interval and, optionally, the season range
6. Click **Run Sync Now** to test and check the log

## Configuration

| Setting | Description |
|---------|-------------|
| **Teams** | One row per team: active, label, fussball.de team URL (team-id and season are extracted automatically), league display name. The team slug is fixed on first save. Leave label and URL empty to remove a row. New season: change the URL |
| **Sync Interval** | How often WP-Cron runs the sync (hourly / 6 h / 12 h / twice daily / daily / disabled), shared by all teams |
| **Season** | First and last day of the season; matches outside this range are ignored, shared by all teams |

The single-team options of version 1.0 (`fussball_sync_url`, `fussball_sync_category`, `fussball_sync_friendly_category`) are still read as long as the team list option `fussball_sync_teams` does not exist.

## Data model and API

| Meta key | Content |
|---|---|
| `_fussball_heim`, `_fussball_gast` | team names as on fussball.de |
| `_fussball_tore_heim`, `_fussball_tore_gast` | goals; missing = no result yet |
| `_fussball_datum`, `_fussball_zeit`, `_fussball_anstoss` | date (Y-m-d), time (H:i), sortable "Y-m-d H:i" |
| `_fussball_wettbewerb`, `_fussball_saison` | competition, season ("2627") |
| `_fussball_wappen_heim`, `_fussball_wappen_gast` | crest: attachment ID or image URL |
| `_fussball_ort` | venue (manual) |
| `_fussball_spiel_id` | fussball.de match ID (or `pseudo_…`) |

```php
fussball_spiele_get( array( 'mannschaft' => '1-mannschaft', 'zeige' => 'anstehend', 'anzahl' => 3, 'saison' => 'aktuell' ) );
fussball_spiel( $post_id );            // normalised array incl. gespielt, art, ts, mannschaft
fussball_wappen_url( $wert );          // crest URL from ID or URL
fussball_spiel_wettbewerb_anzeige( $spiel );
fussball_sync_get_teams( false );      // configured teams
fussball_tabelle( '1-mannschaft' );    // league table or null (since 2.1.0)
add_action( 'fussball_sync_nach_lauf', 'my_flush_cache' ); // after every run (since 2.1.1)
```

`fussball_tabelle()` returns `stand` (time of the fetch), `saison`, `staffel` and `zeilen`, one row per team with `platz`, `team`, `team_id`, `eigen` (own team), `spiele`, `g`, `u`, `v`, `tore`, `gegentore`, `diff`, `punkte`, `zone` (`aufstieg`, `aufstieg-relegation`, `abstieg-relegation`, `abstieg` or empty), `trend` (`hoch`, `runter`, `gleich`) and `wappen` (attachment ID, URL or empty). It is stored in the option `fussball_sync_tabelle_{team slug}` (not autoloaded).

## Upgrading from 1.x

Version 2.0 stops syncing until the existing Goodlayers matches are migrated (Settings → Fussball.de Sync → "Jetzt umstellen"). The migration keeps post IDs, maps every match to a team (category of the team row, otherwise the club's own team name), splits the Goodlayers JSON into the flat fields and keeps the old meta and categories, so `fussball_sync_migration_v2_rueckgaengig()` can switch the post type back. Page builder elements that showed Goodlayers fixtures have to be replaced by the theme's own elements.

## Requirements

- WordPress 5.0+
- PHP 7.4+ with mbstring
- A theme that displays the matches through the API above (no specific theme required)

## How It Works

1. **Fetch**: The parser calls fussball.de's paginated AJAX endpoints (`ajax.team.prev.games` and `ajax.team.matchplan`) to get all matches for the configured team and season.

2. **Parse**: The HTML response is parsed to extract match date, time, teams, competition name and the match ID from the fixture link (`/-/spiel/<ID>`). Scores are encoded using a per-page custom TrueType font that maps Private Use Area (PUA) Unicode characters to digit glyphs.

3. **Decode**: The font decoder downloads the TTF file, parses the `cmap` and `post` tables, and maps PUA codepoints back to digits 0 to 9.

4. **Match**: For every fixture the sync manager looks for an existing match of the team by match ID, then by the pseudo IDs older versions stored, then by date plus normalised team keys. Matches found via the fallbacks receive the real match ID once (log tag `[ID]`).

5. **Sync**: New `fussball_spiel` posts are created, existing ones are updated only when something changed. Manually set crests and the venue are preserved. Afterwards duplicates (`[DEDUP]`) and orphans (`[VERWAIST]`) are moved to the trash; cancelled matches without a score are logged as `[ABGESAGT]`.

6. **Table**: The team page links the table as `ajax.team.table/-/saison/<season>/staffel/<division>/team-id/<id>`. The plugin loads that table, reads rank, games, wins, draws, losses, goals, points, promotion and relegation zones and the trend arrows, and logs the result as `[TABELLE]`. A table with fewer than 4 rows is treated as an error and the stored table stays unchanged.

## Changelog

### 2.1.1 (2026-10-14)

- New action `fussball_sync_nach_lauf` after every complete run (cron and manual sync), for example to flush a page cache

### 2.1.0 (2026-10-12)

- League table per team from fussball.de, fetched after every team sync (also in dry runs, then without saving)
- Read API `fussball_tabelle()`; zones, trend and own team are part of each row
- Crests for table rows from existing matches or the Media Library

### 2.0.0 (2026-10-12)

- Standalone: own post type `fussball_spiel`, taxonomy `fussball_mannschaft` and flat meta fields instead of the Goodlayers Soccer `fixture_and_result` post type and its JSON settings
- Read/write API for themes, current-season filter from the team URL, league display name per team
- German admin: settings page, edit screen with media picker for crests, list columns and default sort by kick-off
- One-time migration with dry run and rollback; sync is paused until the migration ran
- 301 redirect from old `/fixture_and_result/` URLs to the team page
- Kick-off "00:00" is stored as unknown time

### 1.2.0 (2026-10-12)

- Fixed the match ID regex in the parser: the delimiter `|` was also used as alternation, so the expression was invalid and every match got a pseudo ID. Renamed teams (for example with a "(Flex)" suffix) and rescheduled matches therefore created duplicate posts
- Tolerant team keys (`fussball_sync_team_key()`), used for matching, deduplication and the flag cache
- Matching by real ID, legacy pseudo IDs and date plus team keys with one-time migration to the real ID
- Deduplication prefers score, real ID, flags, then the newest post; orphan cleanup for fixtures fussball.de no longer lists; cancelled matches without a score are trashed
- Lookups are limited to posts outside the categories of other configured teams
- Dry run flag and new statistics (migrated IDs, removed orphans)

### 1.1.0 (2026-09-29)

- Team list with per-team URL and categories, settings table, legacy options kept as fallback
- Log extended to 400 entries

### 1.0.0

- Initial release: single team, score decoding, deduplication, flag resolution

## License

Private project. The Real Soccer theme is a commercial Goodlayers theme.
