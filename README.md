# Fussball.de + Real Soccer WordPress Sync

A WordPress plugin that automatically syncs match fixtures and results from [fussball.de](https://www.fussball.de) into the **Goodlayers Real Soccer** theme's "Fixtures & Results" custom post type.

## Overview

German amateur football clubs using the Real Soccer WordPress theme can use this plugin to keep their website's match schedule and results up-to-date automatically — no manual data entry needed.

The plugin fetches data from fussball.de's AJAX endpoints, decodes the obfuscated scores (fussball.de uses custom fonts to hide scores from scrapers), and writes everything into the `fixture_and_result` post type that the Real Soccer theme uses to display matches.

## Project Structure

```
fussball-sync.php           Main plugin file (cron, activation, AJAX)
admin/
  settings-page.php         WP admin settings UI
includes/
  class-fussball-parser.php Fetches & parses fussball.de HTML
  class-font-decoder.php    Decodes obfuscated scores (TTF font parsing)
  class-sync-manager.php    Creates/updates WordPress posts

```

## Features

- **Automatic sync** via WP-Cron (configurable: hourly to daily)
- **Manual sync** button in the admin panel
- **Score decoding** — parses fussball.de's custom obfuscation fonts (PUA characters in TTF) to extract real scores
- **Fallback** — if font decoding fails, fetches individual match detail pages
- **Smart updates** — only updates posts when data actually changed (score, date/time, teams)
- **Deduplication** — detects and removes duplicate fixture posts
- **Team flag resolution** — automatically finds team logos/flags from existing posts or the Media Library
- **Friendly game support** — matches containing "Freundschaftsspiel" can be assigned to a separate category
- **Detailed logging** — last 100 log entries viewable in the admin panel

## Installation

1. Clone or copy this repository into `wp-content/plugins/fussball-sync/`
2. Activate the plugin in WordPress Admin > Plugins
3. Go to **Settings > Fussball.de Sync**
4. Paste your team's fussball.de URL (the Mannschaftsspielplan page), e.g.:
   ```
   https://www.fussball.de/mannschaft/sv-example/-/saison/2526/team-id/011MIB1NCS000000VTVG0001VTR8C1K7#!/
   ```
5. Choose a sync interval and result category
6. Click **Run Sync Now** to test

## Configuration

| Setting | Description |
|---------|-------------|
| **Team URL** | Full fussball.de team page URL (team-id and season are extracted automatically) |
| **Sync Interval** | How often WP-Cron runs the sync (hourly / 6h / 12h / twice daily / daily / disabled) |
| **Result Category** | The `result_category` taxonomy term to assign to synced matches |
| **Friendly Games Category** | Separate category for friendly matches (optional) |

## Requirements

- WordPress 5.0+
- PHP 7.4+
- Goodlayers Real Soccer theme (or any theme using the `fixture_and_result` post type with `gdlr-soccer-fixture-result-settings` meta)

## How It Works

1. **Fetch** — The parser calls fussball.de's paginated AJAX endpoints (`ajax.team.prev.games` and `ajax.team.matchplan`) to get all matches for the configured team and season.

2. **Parse** — The HTML response is parsed to extract match date, time, teams, competition name, and match ID. Scores are encoded using a per-page custom TrueType font that maps Private Use Area (PUA) Unicode characters to digit glyphs.

3. **Decode** — The font decoder downloads the TTF file, parses the `cmap` and `post` tables, and maps PUA codepoints back to digits 0–9.

4. **Sync** — The sync manager creates new `fixture_and_result` posts or updates existing ones (matched by fussball.de match ID). It preserves manually set team flags and only updates fields that actually changed.

## License

Private project. The Real Soccer theme is a commercial Goodlayers theme.
