# Fussball.de + Real Soccer WordPress Sync

A WordPress plugin that automatically syncs match fixtures and results from [fussball.de](https://www.fussball.de) into the **Goodlayers Real Soccer** theme's "Fixtures & Results" custom post type.

## Overview

German amateur football clubs using the Real Soccer WordPress theme can use this plugin to keep their website's match schedule and results up-to-date automatically, with no manual data entry.

The plugin fetches data from fussball.de's AJAX endpoints, decodes the obfuscated scores (fussball.de uses custom fonts to hide scores from scrapers), and writes everything into the `fixture_and_result` post type that the Real Soccer theme uses to display matches. Several teams of one club (for example the first team and a reserve "SGM") can be synced into separate result categories.

## Project Structure

```
fussball-sync.php           Main plugin file (cron, activation, AJAX, team list, team name keys)
admin/
  settings-page.php         WP admin settings UI (team table, interval, season, log)
includes/
  class-fussball-parser.php Fetches & parses fussball.de HTML
  class-font-decoder.php    Decodes obfuscated scores (TTF font parsing)
  class-sync-manager.php    Creates/updates/cleans up WordPress posts
```

## Features

- **Automatic sync** via WP-Cron (hourly / 6 h / 12 h / twice daily / daily / disabled)
- **Multiple teams** (since 1.1.0): every team has its own fussball.de URL, result category and friendly-games category
- **Manual sync** button in the admin panel
- **Score decoding**: parses fussball.de's custom obfuscation fonts (PUA characters in TTF) to extract real scores
- **Fallback**: if font decoding fails, fetches individual match detail pages
- **Real match IDs** (since 1.2.0): posts are matched by the fussball.de match ID taken from the fixture link; a pseudo ID built from date and normalised team names is only a fallback
- **Tolerant team matching**: `fussball_sync_team_key()` ignores case, bracketed suffixes such as "(Flex)", roman numerals in Spielgemeinschaft names ("Wittendorf II/Lombach I/Loßburg I"), "e.V." and soft hyphens, so renamed teams no longer create duplicate fixtures
- **Smart updates**: only updates posts when data actually changed (score, date/time, teams)
- **Deduplication**: fixtures with the same date, home and away team are merged; the post with a score wins, then the one with a real match ID, then the one with team flags, then the newest. The rest goes to the trash
- **Orphan cleanup**: fixtures in the team's categories that fussball.de no longer lists and that have no score are trashed (guarded: at least 5 matches fetched and the matching past/upcoming list was not empty). Cancelled matches without a score are trashed as well
- **Team isolation**: lookups, the key cache and deduplication never touch posts in the categories of other configured teams, so a friendly between two teams of the same club stays in both schedules
- **Team flag resolution**: automatically finds team logos/flags from existing posts or the Media Library
- **Friendly game support**: matches containing "Freundschaftsspiel" can be assigned to a separate category
- **Dry run**: `fussball_sync_run_team( $team + array( 'dry_run' => true ), $season_start, $season_end )` logs every action with "(dry)" and writes nothing
- **Detailed logging**: the last 400 entries are kept, the admin panel shows the most recent ones

## Installation

1. Clone or copy this repository into `wp-content/plugins/fussball-sync/`
2. Activate the plugin in WordPress Admin > Plugins
3. Go to **Settings > Fussball.de Sync**
4. Add one row per team: label, the team's fussball.de URL (the Mannschaftsspielplan page), result category and friendly-games category, e.g.:
   ```
   https://www.fussball.de/mannschaft/sv-example/-/saison/2627/team-id/011MIB1NCS000000VTVG0001VTR8C1K7#!/
   ```
5. Choose a sync interval and, optionally, the season range
6. Click **Run Sync Now** to test and check the log

## Configuration

| Setting | Description |
|---------|-------------|
| **Teams** | One row per team: active, label, fussball.de team URL (team-id and season are extracted automatically), result category, friendly-games category. Leave label and URL empty to remove a row |
| **Sync Interval** | How often WP-Cron runs the sync (hourly / 6 h / 12 h / twice daily / daily / disabled), shared by all teams |
| **Season** | First and last day of the season; matches outside this range are ignored, shared by all teams |

The single-team options of version 1.0 (`fussball_sync_url`, `fussball_sync_category`, `fussball_sync_friendly_category`) are still read as long as the team list option `fussball_sync_teams` does not exist.

## Requirements

- WordPress 5.0+
- PHP 7.4+ with mbstring
- Goodlayers Real Soccer theme (or any theme using the `fixture_and_result` post type with `gdlr-soccer-fixture-result-settings` meta)

## How It Works

1. **Fetch**: The parser calls fussball.de's paginated AJAX endpoints (`ajax.team.prev.games` and `ajax.team.matchplan`) to get all matches for the configured team and season.

2. **Parse**: The HTML response is parsed to extract match date, time, teams, competition name and the match ID from the fixture link (`/-/spiel/<ID>`). Scores are encoded using a per-page custom TrueType font that maps Private Use Area (PUA) Unicode characters to digit glyphs.

3. **Decode**: The font decoder downloads the TTF file, parses the `cmap` and `post` tables, and maps PUA codepoints back to digits 0 to 9.

4. **Match**: For every fixture the sync manager looks for an existing post by match ID, then by the pseudo IDs older versions stored, then by date plus normalised team keys. Posts found via the fallbacks receive the real match ID once (log tag `[ID]`).

5. **Sync**: New `fixture_and_result` posts are created, existing ones are updated only when something changed. Manually set team flags are preserved. Afterwards duplicates (`[DEDUP]`) and orphans (`[VERWAIST]`) are moved to the trash; cancelled matches without a score are logged as `[ABGESAGT]`.

## Changelog

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
