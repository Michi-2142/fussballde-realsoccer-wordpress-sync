<?php
/**
 * Fussball_Parser
 *
 * Fetches match data from the two fussball.de AJAX endpoints used by the
 * Mannschaftsspielplan (team match schedule) page:
 *
 *   Previous games (results):
 *     https://www.fussball.de/ajax.team.prev.games/-/team-id/{id}/saison/{s}/
 *
 *   Upcoming fixtures (matchplan):
 *     https://www.fussball.de/ajax.team.matchplan/-/team-id/{id}/saison/{s}/
 *
 * Both endpoints are PAGINATED via path segments:
 *   /max/{N}/offset/{O}/
 *
 * The Mannschaftsspielplan page shows games 10 at a time with a
 * "Weitere Spiele laden" (load more) button. This class replicates that
 * behaviour by fetching pages in a loop until a page returns fewer matches
 * than the requested page size, signalling the last page.
 *
 * Both endpoints return an HTML fragment (or JSON wrapping the HTML).
 * The HTML uses two `<tr>` rows per match:
 *   1. <tr class="row-headline visible-small"> – date / time / competition
 *   2. <tr …>                                  – home team, away team, score
 *
 * Scores are obfuscated via a custom font (see Fussball_Font_Decoder).
 * For matches where the font decode fails, the class falls back to fetching
 * the individual match detail page and counting goal events.
 *
 * Returned match data structure (per match):
 *
 *   [
 *     'match_id'    => string,   // fussball.de spiel ID
 *     'home_team'   => string,
 *     'away_team'   => string,
 *     'date'        => string,   // Y-m-d
 *     'time'        => string,   // H:i
 *     'competition' => string,
 *     'home_goals'  => int|null, // null = not played / unknown
 *     'away_goals'  => int|null,
 *     'status'      => string,   // 'played' | 'upcoming' | 'cancelled'
 *   ]
 */
defined( 'ABSPATH' ) || exit;

class Fussball_Parser {

    /** @var string */
    private $team_id;
    /** @var string */
    private $season;

    /**
     * @param string $team_id  fussball.de team ID (e.g. 011MIB1NCS000000VTVG0001VTR8C1K7)
     * @param string $season   Season code (e.g. "2526" for 2025/26)
     */
    public function __construct( $team_id, $season ) {
        $this->team_id = $team_id;
        $this->season  = $season;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Public
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Return a deduplicated array of all matches (past + upcoming) for the season.
     *
     * @return array  Array of match data arrays (see class docblock).
     */
    /** @var array  Anzahl geholter Spiele je Endpunkt des letzten Laufs (für die Waisen-Bereinigung als Schutz vor leeren Antworten). */
    public $counts = array( 'prev' => 0, 'upcoming' => 0 );

    public function get_all_matches() {
        $prev_matches     = $this->fetch_endpoint( 'prev.games' );
        $upcoming_matches = $this->fetch_endpoint( 'matchplan' );
        $this->counts     = array( 'prev' => count( $prev_matches ), 'upcoming' => count( $upcoming_matches ) );

        // Merge with match_id-based deduplication (upcoming takes precedence)
        $by_id = array();

        foreach ( $prev_matches as $m ) {
            if ( ! empty( $m['match_id'] ) ) {
                $by_id[ $m['match_id'] ] = $m;
            }
        }

        foreach ( $upcoming_matches as $m ) {
            if ( ! empty( $m['match_id'] ) ) {
                // Only add/replace if the upcoming entry has useful data
                if ( ! isset( $by_id[ $m['match_id'] ] ) ) {
                    $by_id[ $m['match_id'] ] = $m;
                } else {
                    // Keep the played result if already known
                    $existing = $by_id[ $m['match_id'] ];
                    if ( $existing['status'] !== 'played' ) {
                        $by_id[ $m['match_id'] ] = $m;
                    }
                }
            }
        }

        return array_values( $by_id );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Fetching
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Fetch one of the two AJAX endpoints and return parsed match array.
     *
     * Pages through the endpoint using /max/{N}/offset/{M}/ path segments
     * until a page returns fewer matches than requested, signalling the last page.
     *
     * @param string $endpoint  'prev.games' or 'matchplan'
     * @return array
     */
    private function fetch_endpoint( $endpoint ) {
        $all_matches = array();
        $max         = 100; // fetch up to 100 matches per request
        $offset      = 0;
        $page        = 1;

        $base_url = sprintf(
            'https://www.fussball.de/ajax.team.%s/-/team-id/%s/saison/%s/',
            $endpoint,
            rawurlencode( $this->team_id ),
            rawurlencode( $this->season )
        );

        $request_args = array(
            'timeout'    => 20,
            'user-agent' => 'Mozilla/5.0 (compatible; WordPress/' . get_bloginfo( 'version' ) . ')',
            'headers'    => array(
                'Accept'          => 'text/html,application/json,*/*',
                'Accept-Language' => 'de-DE,de;q=0.9',
                'Referer'         => 'https://www.fussball.de/',
            ),
        );

        do {
            $url = $base_url . 'max/' . $max . '/offset/' . $offset . '/';

            $response = wp_remote_get( $url, $request_args );

            if ( is_wp_error( $response ) ) {
                fussball_sync_log( sprintf(
                    '[%s] HTTP error on page %d (offset=%d): %s',
                    $endpoint, $page, $offset, $response->get_error_message()
                ) );
                break;
            }

            $code = wp_remote_retrieve_response_code( $response );
            if ( $code !== 200 ) {
                fussball_sync_log( sprintf(
                    '[%s] HTTP %d on page %d (offset=%d)',
                    $endpoint, $code, $page, $offset
                ) );
                break;
            }

            $body = wp_remote_retrieve_body( $response );

            // The endpoint may return JSON { "success": true, "html": "..." }
            $decoded = json_decode( $body, true );
            if ( is_array( $decoded ) && isset( $decoded['html'] ) ) {
                $html = $decoded['html'];
            } else {
                $html = $body;
            }

            $page_matches = $this->parse_match_table( $html );
            $count        = count( $page_matches );

            fussball_sync_log( sprintf(
                '[%s] page %d (offset=%d): %d matches',
                $endpoint, $page, $offset, $count
            ) );

            $all_matches = array_merge( $all_matches, $page_matches );
            $offset     += $count;
            $page++;

            // Stop when the page returned fewer matches than requested – that's the last page.
            // Also stop if we got an empty page (no matches found at all).
        } while ( $count >= $max );

        return $all_matches;
    }

    // ──────────────────────────────────────────────────────────────────────
    // HTML Parsing
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Parse the HTML table fragment and extract match data.
     *
     * Structure (repeated for every match):
     *
     *   <tr class="row-headline visible-small">
     *     <td>Sonntag, 08.03.2026 - 14:00 Uhr | Bezirksl...</td>
     *   </tr>
     *   [<tr class="row-competition hidden-small">…</tr>]   ← optional, skip
     *   <tr …>
     *     <td class="column-club">…<div class="club-name">HOME</div>…</td>
     *     <td class="column-colon">:</td>
     *     <td class="column-club no-border">…<div class="club-name">AWAY</div>…</td>
     *     <td class="column-score">
     *       <a href="…/spiel/MATCH_ID">
     *         <span data-obfuscation="KEY" class="score-left">CHAR</span>
     *         <span class="colon">:</span>
     *         <span data-obfuscation="KEY" class="score-right">CHAR</span>
     *       </a>
     *       OR
     *       <a …><span class="info-text">Absetzung</span></a>
     *     </td>
     *   </tr>
     *
     * @param string $html
     * @return array
     */
    private function parse_match_table( $html ) {
        $matches = array();

        // Split into <tr> blocks
        if ( ! preg_match_all( '/<tr([^>]*)>(.*?)<\/tr>/is', $html, $rows ) ) {
            return $matches;
        }

        $row_attrs  = $rows[1];
        $row_bodies = $rows[2];
        $total      = count( $row_bodies );

        $current_date        = null;
        $current_time        = null;
        $current_competition = null;

        for ( $i = 0; $i < $total; $i++ ) {
            $attrs = $row_attrs[ $i ];
            $body  = $row_bodies[ $i ];

            // ── Headline row: extract date / time / competition ────────────
            if ( strpos( $attrs, 'row-headline' ) !== false ) {
                $this->parse_headline_row( $body, $current_date, $current_time, $current_competition );
                continue;
            }

            // ── Competition row: skip ──────────────────────────────────────
            if ( strpos( $attrs, 'row-competition' ) !== false ) {
                continue;
            }

            // ── Match data row ─────────────────────────────────────────────
            // Must contain column-club (team names)
            if ( strpos( $body, 'column-club' ) === false ) {
                continue;
            }

            $match = $this->parse_match_row( $body, $current_date, $current_time, $current_competition );
            if ( ! empty( $match ) ) {
                $matches[] = $match;
            }
        }

        return $matches;
    }

    /**
     * Extract date, time and competition from a row-headline cell.
     *
     * Format: "Sonntag, 08.03.2026 - 14:00 Uhr | Bezirksliga"
     *
     * @param string      $body     The TR inner HTML.
     * @param string|null &$date
     * @param string|null &$time
     * @param string|null &$competition
     */
    private function parse_headline_row( $body, &$date, &$time, &$competition ) {
        // Strip tags
        $text = strip_tags( $body );
        $text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
        $text = trim( preg_replace( '/\s+/', ' ', $text ) );

        // Date: DD.MM.YYYY
        if ( preg_match( '/(\d{2})\.(\d{2})\.(\d{4})/', $text, $dm ) ) {
            $date = $dm[3] . '-' . $dm[2] . '-' . $dm[1]; // Y-m-d
        }

        // Time: HH:MM Uhr
        if ( preg_match( '/(\d{1,2}):(\d{2})\s*Uhr/i', $text, $tm ) ) {
            $time = sprintf( '%02d:%02d', $tm[1], $tm[2] );
        }

        // Competition: text after " | "
        if ( preg_match( '/\|\s*(.+)$/', $text, $comp ) ) {
            $competition = trim( $comp[1] );
        }
    }

    /**
     * Parse a match data row (the row with column-club, column-score etc.).
     *
     * @param string $body         TR inner HTML.
     * @param string $date         Current match date (Y-m-d).
     * @param string $time         Current match time (H:i).
     * @param string $competition  Competition name.
     * @return array|null
     */
    private function parse_match_row( $body, $date, $time, $competition ) {
        if ( empty( $date ) ) {
            return null;
        }

        // ── Team names ────────────────────────────────────────────────────
        if ( ! preg_match_all( '/<div[^>]+class="[^"]*club-name[^"]*"[^>]*>(.*?)<\/div>/is', $body, $teams ) ) {
            return null;
        }

        $home_team = $this->clean_text( $teams[1][0] ?? '' );
        $away_team = $this->clean_text( $teams[1][1] ?? '' );

        if ( empty( $home_team ) || empty( $away_team ) ) {
            return null;
        }

        // ── Match ID (from any "/spiel/ID" link) ─────────────────────────
        $match_id = null;
        // Die Kennung ist der letzte Pfadteil des Spiel-Links (…/-/spiel/031EOMHGI0000000VS5489BTVUS470OH).
        // Früher stand hier ein Muster mit „|“ als Begrenzer UND als Oder-Zeichen, das war ungültig, deshalb bekam jedes Spiel eine Pseudo-Kennung.
        if ( preg_match( '#/-/spiel/([A-Z0-9]{16,})(?![A-Za-z0-9])#', $body, $sid ) ) {
            $match_id = $sid[1];
        }
        if ( empty( $match_id ) ) {
            // Fallback: Pseudo-Kennung aus Datum und normalisierten Teamnamen (Klammerzusätze wie „(Flex)“ zählen nicht)
            $match_id = 'pseudo_' . md5( $date . fussball_sync_team_key( $home_team ) . fussball_sync_team_key( $away_team ) );
        }

        // ── Score ─────────────────────────────────────────────────────────
        $home_goals  = null;
        $away_goals  = null;
        $status      = 'upcoming';

        // Determine if match date is in the past
        $match_ts = strtotime( $date . ' ' . ( $time ?? '00:00' ) );
        if ( $match_ts && $match_ts < current_time( 'timestamp' ) ) {
            $status = 'played'; // We expect a score
        }

        // Check for cancellation
        if ( preg_match( '/<span[^>]+class="[^"]*info-text[^"]*"[^>]*>(.*?)<\/span>/is', $body, $info ) ) {
            $info_text = $this->clean_text( $info[1] );
            if ( stripos( $info_text, 'absetzung' ) !== false ||
                 stripos( $info_text, 'abgesagt' ) !== false ) {
                $status = 'cancelled';
            }
        }

        // Decode score if match was played
        if ( $status === 'played' ) {
            $decoded = $this->decode_score_from_html( $body );
            if ( $decoded !== null ) {
                $home_goals = $decoded['home'];
                $away_goals = $decoded['away'];
            } else {
                // Fallback: fetch individual match detail page
                $home_goals = null;
                $away_goals = null;
                if ( $match_id && strpos( $match_id, 'pseudo_' ) !== 0 ) {
                    $score = $this->fetch_score_from_detail_page( $match_id );
                    if ( $score !== null ) {
                        $home_goals = $score['home'];
                        $away_goals = $score['away'];
                    }
                }
            }
        }

        return array(
            'match_id'    => $match_id,
            'home_team'   => $home_team,
            'away_team'   => $away_team,
            'date'        => $date,
            'time'        => $time ?? '00:00',
            'competition' => $competition ?? '',
            'home_goals'  => $home_goals,
            'away_goals'  => $away_goals,
            'status'      => $status,
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Score Decoding
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Attempt to decode score using the font decoder.
     *
     * Returns ['home'=>int,'away'=>int] or null on failure.
     *
     * @param string $body  TR inner HTML.
     * @return array|null
     */
    private function decode_score_from_html( $body ) {
        // Extract column-score td
        if ( ! preg_match( '/<td[^>]+class="[^"]*column-score[^"]*"[^>]*>(.*?)<\/td>/is', $body, $score_td ) ) {
            return null;
        }
        $score_html = $score_td[1];

        // Get obfuscation key (both spans should share the same key)
        if ( ! preg_match( '/data-obfuscation="([^"]+)"/i', $score_html, $key_m ) ) {
            return null;
        }
        $obf_key = $key_m[1];

        // Extract score-left char
        if ( ! preg_match( '/<span[^>]+class="[^"]*score-left[^"]*"[^>]*>(.*?)<\/span>/is', $score_html, $sl ) ) {
            return null;
        }
        // Extract score-right char
        if ( ! preg_match( '/<span[^>]+class="[^"]*score-right[^"]*"[^>]*>(.*?)<\/span>/is', $score_html, $sr ) ) {
            return null;
        }

        // The spans may contain HTML entities – decode them
        $left_char  = html_entity_decode( strip_tags( $sl[1] ), ENT_HTML5, 'UTF-8' );
        $right_char = html_entity_decode( strip_tags( $sr[1] ), ENT_HTML5, 'UTF-8' );

        // Extract the first real character (skip any nested tag remnants)
        // We want only the first non-ASCII character in the PUA range
        $left_char  = $this->first_pua_char( $left_char );
        $right_char = $this->first_pua_char( $right_char );

        if ( $left_char === null || $right_char === null ) {
            return null;
        }

        $home = Fussball_Font_Decoder::decode_char( $left_char, $obf_key );
        $away = Fussball_Font_Decoder::decode_char( $right_char, $obf_key );

        if ( $home === -1 || $away === -1 ) {
            return null;
        }

        return array( 'home' => $home, 'away' => $away );
    }

    /**
     * Return the first Private-Use-Area (0xE000–0xF8FF) character in a string,
     * or null if none found.
     *
     * @param string $text
     * @return string|null
     */
    private function first_pua_char( $text ) {
        // Walk UTF-8 characters
        $len = strlen( $text );
        $i   = 0;
        while ( $i < $len ) {
            $b   = ord( $text[ $i ] );
            $cp  = 0;
            $seq = 1;
            if ( $b < 0x80 ) {
                $cp  = $b;
                $seq = 1;
            } elseif ( ( $b & 0xE0 ) === 0xC0 ) {
                $cp  = $b & 0x1F;
                $seq = 2;
            } elseif ( ( $b & 0xF0 ) === 0xE0 ) {
                $cp  = $b & 0x0F;
                $seq = 3;
            } elseif ( ( $b & 0xF8 ) === 0xF0 ) {
                $cp  = $b & 0x07;
                $seq = 4;
            }
            for ( $j = 1; $j < $seq; $j++ ) {
                if ( $i + $j >= $len ) {
                    break;
                }
                $cp = ( $cp << 6 ) | ( ord( $text[ $i + $j ] ) & 0x3F );
            }
            if ( $cp >= 0xE000 && $cp <= 0xF8FF ) {
                return substr( $text, $i, $seq );
            }
            $i += $seq;
        }
        return null;
    }

    /**
     * Fallback: fetch the individual match detail page and count goal events
     * from the embedded data-match-events JSON.
     *
     * @param string $match_id  fussball.de spiel ID.
     * @return array|null  ['home'=>int,'away'=>int] or null.
     */
    private function fetch_score_from_detail_page( $match_id ) {
        // Transient cache to avoid re-fetching on every sync
        $transient = 'fussball_match_' . sanitize_key( $match_id );
        $cached    = get_transient( $transient );
        if ( $cached !== false ) {
            return is_array( $cached ) ? $cached : null;
        }

        $url = 'https://www.fussball.de/spiel/-/-/spiel/' . rawurlencode( $match_id );

        $response = wp_remote_get( $url, array(
            'timeout'    => 20,
            'user-agent' => 'Mozilla/5.0 (compatible; WordPress/' . get_bloginfo( 'version' ) . ')',
            'headers'    => array(
                'Accept-Language' => 'de-DE,de;q=0.9',
                'Referer'         => 'https://www.fussball.de/',
            ),
        ) );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            set_transient( $transient, 'error', HOUR_IN_SECONDS );
            return null;
        }

        $body = wp_remote_retrieve_body( $response );

        $score = $this->parse_match_events( $body );

        // Cache for 6 h (result should not change)
        set_transient( $transient, $score ?? 'none', 6 * HOUR_IN_SECONDS );

        return $score;
    }

    /**
     * Parse data-match-events JSON from a match detail page.
     *
     * @param string $html
     * @return array|null
     */
    private function parse_match_events( $html ) {
        if ( ! preg_match( '/data-match-events=\'({[^\']+})\'/i', $html, $ev )
          && ! preg_match( '/data-match-events="(\{[^"]+\})"/i', $html, $ev ) ) {
            return null;
        }

        $json = html_entity_decode( $ev[1], ENT_QUOTES, 'UTF-8' );
        // fussball.de uses single-quoted keys, normalise
        $json = preg_replace( "/'([a-zA-Z0-9_-]+)'\s*:/", '"$1":', $json );
        $json = str_replace( "'", '"', $json );

        $data = json_decode( $json, true );
        if ( ! is_array( $data ) ) {
            return null;
        }

        $home = 0;
        $away = 0;

        foreach ( array( 'first-half', 'second-half', 'extra-time', 'extra-time-2' ) as $period ) {
            if ( empty( $data[ $period ]['events'] ) ) {
                continue;
            }
            foreach ( $data[ $period ]['events'] as $event ) {
                if ( isset( $event['type'] ) && $event['type'] === 'goal' ) {
                    if ( isset( $event['team'] ) ) {
                        if ( $event['team'] === 'home' ) {
                            $home++;
                        } elseif ( $event['team'] === 'away' ) {
                            $away++;
                        }
                    }
                }
            }
        }

        return array( 'home' => $home, 'away' => $away );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Text Helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Strip HTML tags, normalise whitespace and decode HTML entities.
     *
     * @param string $html
     * @return string
     */
    private function clean_text( $html ) {
        $text = strip_tags( $html );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        // Remove soft hyphens (&#8203; zero-width space)
        $text = str_replace( "\xE2\x80\x8B", '', $text );
        return trim( preg_replace( '/\s+/', ' ', $text ) );
    }
}
