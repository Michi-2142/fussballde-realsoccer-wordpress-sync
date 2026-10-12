<?php
/**
 * Fussball_Sync_Manager
 *
 * Writes parsed fussball.de match data into the Goodlayers Soccer plugin's
 * `fixture_and_result` custom post type.
 *
 * Each post is uniquely identified by the fussball.de match ID stored in the
 * `fussball_match_id` post-meta key. Existing posts are updated if the score
 * or date changes; posts that are already up-to-date are left untouched.
 *
 * Data mapping:
 *
 *   fussball.de          →   Goodlayers Soccer (`gdlr-soccer-fixture-result-settings` JSON)
 *   ─────────────────────────────────────────────────────────────────────────────────────
 *   home_team            →   home
 *   away_team            →   away
 *   home_goals           →   home-goal   (empty string if not yet played)
 *   away_goals           →   away-goal   (empty string if not yet played)
 *   date (Y-m-d)         →   date-of-match
 *   time (H:i)           →   match-time
 *
 *   In addition `gdlr-start-date` meta is written as "Y-m-d H:i" (used for
 *   WP_Query ordering).
 */
defined( 'ABSPATH' ) || exit;

class Fussball_Sync_Manager {

    /** @var string  result_category taxonomy slug to assign to synced posts. */
    private $category;

    /** @var string  result_category taxonomy slug for friendly games (competition contains "Freundschaftsspiel"). */
    private $friendly_category;

    /** @var string  First day of the current season (Y-m-d). Empty = no lower bound. */
    private $season_start;

    /** @var string  Last day of the current season (Y-m-d). Empty = no upper bound. */
    private $season_end;

    /**
     * Lazy-built cache: normalised_team_name => ['value'=>string, 'source'=>string].
     * Built once per sync run from all existing fixture_and_result posts.
     *
     * @var array|null
     */
    private $team_flag_cache = null;

    /** @var bool  Trockenlauf: alles loggen, nichts schreiben. */
    private $dry = false;

    /** @var array  Beitrags-IDs, die in diesem Lauf einem fussball.de-Spiel zugeordnet wurden (für die Waisen-Bereinigung). */
    private $matched = array();

    /** @var array|null  Cache Datum|Heim-Schlüssel|Gast-Schlüssel => Beitrags-ID (Fallback-Suche ohne Kennung). */
    private $key_cache = null;

    /** @var array  Kategorien (Slugs) der anderen konfigurierten Mannschaften: deren Beiträge fasst dieser Lauf nie an. */
    private $other_cats = array();

    public function set_dry_run( $on ) { $this->dry = (bool) $on; }

    /**
     * @param string $category           Taxonomy slug for regular matches (empty = no assignment).
     * @param string $friendly_category  Taxonomy slug for friendly games (empty = use $category).
     * @param string $season_start       First day of the season, Y-m-d (empty = no lower bound).
     * @param string $season_end         Last day of the season, Y-m-d (empty = no upper bound).
     */
    public function __construct( $category = '', $friendly_category = '', $season_start = '', $season_end = '' ) {
        $this->category          = sanitize_text_field( $category );
        $this->friendly_category = sanitize_text_field( $friendly_category );
        $this->season_start      = sanitize_text_field( $season_start );
        $this->season_end        = sanitize_text_field( $season_end );

        // Kategorien der anderen Mannschaften (ein Freundschaftsspiel 1. Mannschaft gegen SGM hat bei beiden dieselbe
        // fussball.de-Kennung und denselben Datum|Heim|Gast-Schlüssel; es darf weder zugeordnet noch als Dublette gelten).
        if ( function_exists( 'fussball_sync_get_teams' ) ) {
            $own = array_filter( array( $this->category, $this->friendly_category ) );
            foreach ( fussball_sync_get_teams( false ) as $t ) {
                foreach ( array( $t['category'] ?? '', $t['friendly_category'] ?? '' ) as $c ) {
                    $c = sanitize_text_field( (string) $c );
                    if ( '' !== $c && ! in_array( $c, $own, true ) ) { $this->other_cats[ $c ] = $c; }
                }
            }
        }
    }

    /** Abfrage auf Beiträge beschränken, die nicht zu einer anderen Mannschaft gehören. */
    private function scope_query( array $args ) {
        if ( $this->other_cats ) {
            $args['tax_query'] = array( array( 'taxonomy' => 'result_category', 'field' => 'slug', 'terms' => array_values( $this->other_cats ), 'operator' => 'NOT IN' ) );
        }
        return $args;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Public
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Process all matches, generate verbose logs, and return stats.
     *
     * @param array $matches  Array of match data from Fussball_Parser.
     * @return array  ['created'=>int, 'updated_score'=>int, 'updated_details'=>int, 'skipped'=>int, 'cancelled'=>int, 'out_of_season'=>int, 'duplicates_removed'=>int]
     */
    public function sync_matches( array $matches, array $counts = array() ) {
        $stats = array(
            'created'            => 0,
            'updated_score'      => 0,
            'updated_details'    => 0,
            'skipped'            => 0,
            'cancelled'          => 0,
            'out_of_season'      => 0,
            'ids_migrated'       => 0,
            'duplicates_removed' => 0,
            'orphans_removed'    => 0,
        );
        $this->matched      = array();
        $this->key_cache    = null;
        $this->ids_migrated = 0;
        if ( $this->dry ) {
            fussball_sync_log( '── DRY RUN: es wird nichts geschrieben ──' );
        }

        if ( ! empty( $this->season_start ) || ! empty( $this->season_end ) ) {
            fussball_sync_log( sprintf(
                'Season filter active: %s – %s (matches outside this range are ignored).',
                $this->season_start ?: '…',
                $this->season_end   ?: '…'
            ) );
        }

        // ── 1. Log everything that was parsed  ────────────────────────────
        fussball_sync_log( sprintf( '── Parsed %d matches from fussball.de ──', count( $matches ) ) );

        foreach ( $matches as $match ) {
            fussball_sync_log( $this->format_parsed_match( $match ) );
        }

        fussball_sync_log( '── Starting sync ──' );

        // ── 2. Sync each match and log the planned / executed action ───────
        foreach ( $matches as $match ) {
            $result = $this->sync_single_match( $match );

            if ( isset( $stats[ $result ] ) ) {
                $stats[ $result ]++;
            } else {
                $stats['skipped']++;
            }
        }

        $stats['ids_migrated'] = $this->ids_migrated;

        // ── 3. Deduplicate: remove posts with same date + home + away ─────
        $stats['duplicates_removed'] = $this->remove_duplicates();

        // ── 4. Waisen: Spiele dieser Kategorien, die fussball.de nicht mehr liefert und die kein Ergebnis haben ──
        $stats['orphans_removed'] = $this->remove_orphans( $matches, $counts );

        return $stats;
    }

    /** @var int  Anzahl Pseudo-Kennungen, die in diesem Lauf durch echte ersetzt wurden. */
    private $ids_migrated = 0;

    // ──────────────────────────────────────────────────────────────────────
    // Parsed-match log line
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Format a single parsed match into a human-readable log line.
     *
     * Example output:
     *   [PARSE] 2026-03-08 14:00 | SV Wittendorf vs TSV Simmersfeld
     *           upcoming | competition: Bezirksliga | id: 02VN4C…
     *
     * @param array $match
     * @return string
     */
    private function format_parsed_match( array $match ) {
        $score_str = '—';
        if ( $match['status'] === 'played' ) {
            if ( $match['home_goals'] !== null && $match['away_goals'] !== null ) {
                $score_str = $match['home_goals'] . ':' . $match['away_goals'];
            } else {
                $score_str = 'played (score unknown)';
            }
        } elseif ( $match['status'] === 'cancelled' ) {
            $score_str = 'CANCELLED (Absetzung)';
        } else {
            $score_str = 'upcoming';
        }

        return sprintf(
            '[PARSE] %s %s  |  %s vs %s  |  %s  |  %s  |  id: %s',
            $match['date']        ?? '?',
            $match['time']        ?? '?',
            $match['home_team']   ?? '?',
            $match['away_team']   ?? '?',
            $score_str,
            $match['competition'] ?? '?',
            isset( $match['match_id'] ) ? substr( $match['match_id'], 0, 12 ) . '…' : '?'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Single-match logic
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Create or update a single match post with verbose action logging.
     *
     * @param array $match  Match data from Fussball_Parser.
     * @return string  'created' | 'updated_score' | 'updated_details' | 'skipped' | 'cancelled' | 'out_of_season'
     */
    private function sync_single_match( array $match ) {
        $label = sprintf(
            '%s vs %s (%s %s)',
            $match['home_team'] ?? '?',
            $match['away_team'] ?? '?',
            $match['date']      ?? '?',
            $match['time']      ?? '?'
        );

        // ── Guard: missing core fields ─────────────────────────────────────
        if ( empty( $match['date'] ) || empty( $match['match_id'] ) ) {
            fussball_sync_log( '[SKIP invalid]  ' . $label . '  |  missing date or match_id' );
            return 'skipped';
        }

        // ── Guard: match outside the configured season range ───────────────
        if ( ! $this->is_in_season( $match['date'] ) ) {
            fussball_sync_log( sprintf(
                '[SKIP out of season]  %s  |  outside %s – %s',
                $label,
                $this->season_start ?: '…',
                $this->season_end   ?: '…'
            ) );
            return 'out_of_season';
        }

        $existing_id = $this->find_post_for_match( $match );
        if ( $existing_id ) {
            $this->matched[ $existing_id ] = true;
        }

        // ── Guard: cancelled match ─────────────────────────────────────────
        // Gibt es dazu schon einen Beitrag ohne Ergebnis, kommt er in den Papierkorb (sonst stünde er ewig als „Ergebnis offen“).
        if ( $match['status'] === 'cancelled' ) {
            if ( $existing_id && ! $this->post_has_score( $existing_id ) ) {
                fussball_sync_log( '[ABGESAGT]  ' . $label . '  |  post #' . $existing_id . ' → Papierkorb' . ( $this->dry ? ' (dry)' : '' ) );
                if ( ! $this->dry ) { wp_trash_post( $existing_id ); }
            } else {
                fussball_sync_log( '[SKIP cancelled]  ' . $label . '  |  Absetzung on fussball.de' );
            }
            return 'cancelled';
        }

        // ── Resolve team flags ─────────────────────────────────────────────
        // Read flags already stored for this post; preserve non-empty ones.
        $stored_flags = $this->get_stored_flags( $existing_id );
        list( $match, $flag_log_lines, $flags_newly_resolved ) =
            $this->resolve_flags( $match, $stored_flags );

        // ── Friendly game annotation ───────────────────────────────────────
        $friendly_note = '';
        if ( $this->is_friendly( $match['competition'] ?? '' ) ) {
            $cat           = $this->resolve_category( $match );
            $friendly_note = '  |  FRIENDLY: "' . ( $match['competition'] ) . '"  →  cat: ' . ( $cat ?: '(none)' );
        }

        // ── New match ──────────────────────────────────────────────────────
        if ( ! $existing_id ) {
            $score_note = ( $match['home_goals'] !== null && $match['away_goals'] !== null )
                ? 'score: ' . $match['home_goals'] . ':' . $match['away_goals']
                : 'no score yet';

            fussball_sync_log( '[CREATE]  ' . $label . '  |  ' . $score_note . $friendly_note . ( $this->dry ? '  (dry)' : '' ) );
            foreach ( $flag_log_lines as $line ) {
                fussball_sync_log( $line );
            }
            if ( ! $this->dry ) { $this->create_post( $match ); }
            return 'created';
        }

        // ── Existing match: detect what changed ────────────────────────────
        $diff = $this->detect_changes( $existing_id, $match );

        if ( ! $diff['needs_update'] ) {
            $category_fixed = $this->dry ? false : $this->maybe_fix_category( $existing_id, $match );

            if ( $flags_newly_resolved && ! $this->dry ) {
                $this->fill_flags_meta( $existing_id, $match );
                foreach ( $flag_log_lines as $line ) {
                    fussball_sync_log( $line );
                }
            }

            if ( $category_fixed ) {
                $fixed_cat = $this->resolve_category( $match );
                fussball_sync_log( '[CATEGORY fix]  ' . $label . '  |  post #' . $existing_id . '  |  → ' . $fixed_cat );
            }

            if ( ! $flags_newly_resolved && ! $category_fixed ) {
                fussball_sync_log( '[SKIP no change]  ' . $label . '  |  post #' . $existing_id );
            } else {
                fussball_sync_log( '[SKIP fixed]  ' . $label . '  |  post #' . $existing_id );
            }
            return 'skipped';
        }

        // Build a human-readable diff description from the per-field old/new values
        $change_lines = array();
        foreach ( $diff['new'] as $key => $new_val ) {
            $old_val        = isset( $diff['old'][ $key ] ) ? $diff['old'][ $key ] : '';
            $change_lines[] = $key . ': "' . $old_val . '" → "' . $new_val . '"';
        }
        $change_desc = implode( '  |  ', $change_lines );

        // Decide tag: score-only update vs. broader details update
        $only_score = $diff['changes']['score'] && ! $diff['changes']['teams'] && ! $diff['changes']['datetime'];

        if ( $only_score ) {
            fussball_sync_log( '[UPDATE score]  ' . $label . '  |  post #' . $existing_id . '  |  ' . $change_desc . $friendly_note . ( $this->dry ? '  (dry)' : '' ) );
            foreach ( $flag_log_lines as $line ) {
                fussball_sync_log( $line );
            }
            if ( ! $this->dry ) { $this->update_post( $existing_id, $match ); }
            return 'updated_score';
        }

        fussball_sync_log( '[UPDATE details]  ' . $label . '  |  post #' . $existing_id . '  |  ' . $change_desc . $friendly_note . ( $this->dry ? '  (dry)' : '' ) );
        foreach ( $flag_log_lines as $line ) {
            fussball_sync_log( $line );
        }
        if ( ! $this->dry ) { $this->update_post( $existing_id, $match ); }
        return 'updated_details';
    }

    // ──────────────────────────────────────────────────────────────────────
    // Deduplication
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Find and remove duplicate fixture_and_result posts.
     *
     * A duplicate is defined as two or more posts sharing the same
     * date-of-match + normalised home team + normalised away team.
     * When duplicates are found the post with team flag images set is
     * preferred; among equal candidates the newest (highest ID) is kept.
     * All other duplicates are trashed.
     *
     * @return int  Number of duplicate posts removed.
     */
    private function remove_duplicates() {
        $posts = get_posts( $this->scope_query( array(
            'post_type'      => 'fixture_and_result',
            'post_status'    => array( 'publish', 'draft', 'private' ),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ) ) );

        // Build a map: "date|home|away" => [ ['id'=>int, 'has_flags'=>bool], … ]
        $seen = array();
        foreach ( $posts as $post_id ) {
            $raw      = get_post_meta( $post_id, 'gdlr-soccer-fixture-result-settings', true );
            $raw      = $this->safe_decode( $raw );
            $settings = ! empty( $raw ) ? json_decode( $raw, true ) : array();
            if ( ! is_array( $settings ) ) {
                continue;
            }

            $date = trim( $settings['date-of-match'] ?? '' );
            $home = $this->normalise_team_name( $settings['home'] ?? '' );
            $away = $this->normalise_team_name( $settings['away'] ?? '' );

            if ( empty( $date ) || empty( $home ) || empty( $away ) ) {
                continue;
            }

            $has_flags = ! empty( trim( $settings['home-flag'] ?? '' ) )
                      || ! empty( trim( $settings['away-flag'] ?? '' ) );
            $has_score = '' !== trim( (string) ( $settings['home-goal'] ?? '' ) ) && '' !== trim( (string) ( $settings['away-goal'] ?? '' ) );
            $real_id   = 0 !== strpos( (string) get_post_meta( $post_id, 'fussball_match_id', true ), 'pseudo_' );

            $key = $date . '|' . $home . '|' . $away;
            $seen[ $key ][] = array(
                'id'        => (int) $post_id,
                'has_flags' => $has_flags,
                'has_score' => $has_score,
                'real_id'   => $real_id,
            );
        }

        $removed = 0;
        foreach ( $seen as $key => $entries ) {
            if ( count( $entries ) <= 1 ) {
                continue;
            }

            // Behalten wird: mit Ergebnis vor ohne, echte fussball.de-Kennung vor Pseudo, mit Wappen vor ohne, sonst der neueste.
            usort( $entries, function ( $a, $b ) {
                return array( (int) $a['has_score'], (int) $a['real_id'], (int) $a['has_flags'], $a['id'] )
                   <=> array( (int) $b['has_score'], (int) $b['real_id'], (int) $b['has_flags'], $b['id'] );
            } );

            $keep = array_pop( $entries );
            foreach ( $entries as $dup ) {
                if ( ! $this->dry ) { wp_trash_post( $dup['id'] ); }
                $removed++;
                fussball_sync_log( sprintf(
                    '[DEDUP]  post #%d → Papierkorb (Dublette von #%d; Ergebnis: %s, Kennung: %s)  |  %s%s',
                    $dup['id'],
                    $keep['id'],
                    $dup['has_score'] ? 'ja' : 'nein',
                    $dup['real_id'] ? 'echt' : 'pseudo',
                    $key,
                    $this->dry ? '  (dry)' : ''
                ) );
            }
        }

        if ( $removed > 0 ) {
            fussball_sync_log( sprintf( '[DEDUP]  Removed %d duplicate(s).', $removed ) );
        } else {
            fussball_sync_log( '[DEDUP]  No duplicates found.' );
        }

        return $removed;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Existence check
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Find a fixture_and_result post whose `fussball_match_id` equals $match_id.
     *
     * @param string $match_id
     * @return int|null  Post ID or null.
     */
    private function find_post_by_match_id( $match_id ) {
        $posts = get_posts( $this->scope_query( array(
            'post_type'      => 'fixture_and_result',
            'post_status'    => array( 'publish', 'draft', 'private' ),
            'posts_per_page' => 1,
            'meta_query'     => array(
                array(
                    'key'   => 'fussball_match_id',
                    'value' => $match_id,
                ),
            ),
            'fields'         => 'ids',
        ) ) );

        return ! empty( $posts ) ? (int) $posts[0] : null;
    }

    /**
     * Beitrag zu einem fussball.de-Spiel finden:
     *  1. über die Kennung (echte fussball.de-ID oder aktuelle Pseudo-Kennung),
     *  2. über die alten Pseudo-Kennungen (md5 aus Datum + rohen Namen, wie bis Version 1.1 gespeichert),
     *  3. über Datum + tolerante Teamschlüssel (erkennt „(Flex)“ und „II/I/I“-Varianten).
     * Treffer über 2. oder 3. bekommen sofort die echte Kennung (einmalige Umstellung).
     */
    private function find_post_for_match( array $match ) {
        $id = $this->find_post_by_match_id( $match['match_id'] );
        if ( $id ) {
            return $id;
        }
        $home = $match['home_team'] ?? ''; $away = $match['away_team'] ?? ''; $date = $match['date'] ?? '';
        $legacy = array(
            'pseudo_' . md5( $date . $home . $away ),
            'pseudo_' . md5( $date . fussball_sync_team_key( $home ) . fussball_sync_team_key( $away ) ),
        );
        foreach ( array_unique( $legacy ) as $pid ) {
            if ( $pid === $match['match_id'] ) { continue; }
            $id = $this->find_post_by_match_id( $pid );
            if ( $id ) { break; }
        }
        if ( ! $id ) {
            if ( null === $this->key_cache ) { $this->build_key_cache(); }
            $key = $date . '|' . fussball_sync_team_key( $home ) . '|' . fussball_sync_team_key( $away );
            if ( isset( $this->key_cache[ $key ] ) ) {
                // mehrere Kandidaten (Dubletten): den mit Ergebnis bevorzugen, sonst den neuesten
                $cands = $this->key_cache[ $key ];
                usort( $cands, function ( $a, $b ) { return array( $this->post_has_score( $b ), $b ) <=> array( $this->post_has_score( $a ), $a ); } );
                $id = (int) $cands[0];
            }
        }
        if ( $id && 0 !== strpos( $match['match_id'], 'pseudo_' ) ) {
            fussball_sync_log( sprintf( '[ID]  post #%d  %s  |  Kennung → %s%s', $id, get_post_meta( $id, 'fussball_match_id', true ), $match['match_id'], $this->dry ? '  (dry)' : '' ) );
            if ( ! $this->dry ) { update_post_meta( $id, 'fussball_match_id', $match['match_id'] ); }
            $this->ids_migrated++;
        }
        return $id ?: null;
    }

    private function build_key_cache() {
        $this->key_cache = array();
        foreach ( $this->all_fixture_posts() as $post_id => $settings ) {
            $date = trim( $settings['date-of-match'] ?? '' );
            if ( '' === $date ) { continue; }
            $key = $date . '|' . fussball_sync_team_key( $settings['home'] ?? '' ) . '|' . fussball_sync_team_key( $settings['away'] ?? '' );
            $this->key_cache[ $key ][] = $post_id;
        }
    }

    /** Alle Spiel-Beiträge mit dekodierten Einstellungen: [ post_id => settings ]. */
    private function all_fixture_posts() {
        $out = array();
        $ids = get_posts( $this->scope_query( array( 'post_type' => 'fixture_and_result', 'post_status' => array( 'publish', 'draft', 'private' ), 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ) ) );
        foreach ( $ids as $post_id ) {
            $raw = $this->safe_decode( get_post_meta( $post_id, 'gdlr-soccer-fixture-result-settings', true ) );
            $settings = ! empty( $raw ) ? json_decode( $raw, true ) : array();
            if ( is_array( $settings ) ) { $out[ (int) $post_id ] = $settings; }
        }
        return $out;
    }

    private function post_has_score( $post_id ) {
        $raw = $this->safe_decode( get_post_meta( $post_id, 'gdlr-soccer-fixture-result-settings', true ) );
        $s = ! empty( $raw ) ? json_decode( $raw, true ) : array();
        return is_array( $s ) && '' !== trim( (string) ( $s['home-goal'] ?? '' ) ) && '' !== trim( (string) ( $s['away-goal'] ?? '' ) );
    }

    /**
     * Waisen bereinigen: Beiträge in den Kategorien dieses Teams, die vom Sync stammen (fussball_match_id gesetzt),
     * in der Saison liegen, in diesem Lauf keinem fussball.de-Spiel zugeordnet wurden und kein Ergebnis haben
     * (verschobene oder entfernte Spiele). Schutz: nur wenn fussball.de für den Zeitraum (Vergangenheit bzw. Zukunft)
     * überhaupt Spiele geliefert hat, damit eine leere Antwort nicht den Spielplan leert.
     */
    private function remove_orphans( array $matches, array $counts ) {
        $cats = array_filter( array( $this->category, $this->friendly_category ) );
        if ( ! $cats || count( $matches ) < 5 ) {
            return 0;
        }
        $today = current_time( 'Y-m-d' );
        $ids = get_posts( array(
            'post_type' => 'fixture_and_result', 'post_status' => array( 'publish', 'draft', 'private' ), 'posts_per_page' => -1, 'fields' => 'ids',
            'tax_query' => array( array( 'taxonomy' => 'result_category', 'field' => 'slug', 'terms' => array_values( $cats ) ) ),
            'meta_query' => array( array( 'key' => 'fussball_match_id', 'compare' => 'EXISTS' ) ),
        ) );
        $removed = 0;
        foreach ( $ids as $post_id ) {
            $post_id = (int) $post_id;
            if ( isset( $this->matched[ $post_id ] ) ) { continue; }
            $raw = $this->safe_decode( get_post_meta( $post_id, 'gdlr-soccer-fixture-result-settings', true ) );
            $s = ! empty( $raw ) ? json_decode( $raw, true ) : array();
            if ( ! is_array( $s ) ) { continue; }
            $date = trim( $s['date-of-match'] ?? '' );
            if ( '' === $date || ! $this->is_in_season( $date ) ) { continue; }
            if ( '' !== trim( (string) ( $s['home-goal'] ?? '' ) ) || '' !== trim( (string) ( $s['away-goal'] ?? '' ) ) ) { continue; }
            $past = $date < $today;
            if ( ( $past && empty( $counts['prev'] ) ) || ( ! $past && empty( $counts['upcoming'] ) ) ) { continue; }
            fussball_sync_log( sprintf( '[VERWAIST]  %s vs %s (%s)  |  post #%d nicht mehr bei fussball.de, kein Ergebnis → Papierkorb%s', $s['home'] ?? '?', $s['away'] ?? '?', $date, $post_id, $this->dry ? '  (dry)' : '' ) );
            if ( ! $this->dry ) { wp_trash_post( $post_id ); }
            $removed++;
        }
        if ( ! $removed ) { fussball_sync_log( '[VERWAIST]  keine.' ); }
        return $removed;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Change detection
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Compare incoming match data against what is stored in the post.
     *
     * Returns a structured diff:
     *
     *   [
     *     'needs_update' => bool,
     *     'changes'    => [
     *       'score'    => bool,   // home-goal or away-goal changed
     *       'datetime' => bool,   // date-of-match or match-time changed
     *       'teams'    => bool,   // home or away team name changed
     *     ],
     *     'old' => [ field => value, … ],  // only fields that changed
     *     'new' => [ field => value, … ],
     *   ]
     *
     * @param int   $post_id
     * @param array $match
     * @return array
     */
    private function detect_changes( $post_id, array $match ) {
        $raw      = get_post_meta( $post_id, 'gdlr-soccer-fixture-result-settings', true );
        $raw      = $this->safe_decode( $raw );
        $existing = ( ! empty( $raw ) ) ? json_decode( $raw, true ) : array();
        if ( ! is_array( $existing ) ) {
            $existing = array();
        }

        $new_settings = $this->build_settings( $match );

        // Groups: which logical concept each field belongs to
        $groups = array(
            'home-goal'      => 'score',
            'away-goal'      => 'score',
            'date-of-match'  => 'datetime',
            'match-time'     => 'datetime',
            'home'           => 'teams',
            'away'           => 'teams',
        );

        $changed_groups = array( 'score' => false, 'datetime' => false, 'teams' => false );
        $old_vals       = array();
        $new_vals       = array();

        foreach ( $groups as $key => $group ) {
            $old_val = isset( $existing[ $key ] ) ? (string) $existing[ $key ] : '';
            $new_val = isset( $new_settings[ $key ] ) ? (string) $new_settings[ $key ] : '';

            if ( $old_val !== $new_val ) {
                $changed_groups[ $group ] = true;
                $old_vals[ $key ]         = $old_val;
                $new_vals[ $key ]         = $new_val;
            }
        }

        $needs_update = $changed_groups['score'] || $changed_groups['datetime'] || $changed_groups['teams'];

        return array(
            'needs_update' => $needs_update,
            'changes'      => $changed_groups,
            'old'          => $old_vals,
            'new'          => $new_vals,
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Create / Update
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Create a new fixture_and_result post.
     *
     * @param array $match
     * @return int  New post ID.
     */
    private function create_post( array $match ) {
        $title = sprintf(
            '%s vs %s (%s)',
            $this->sanitize_team( $match['home_team'] ),
            $this->sanitize_team( $match['away_team'] ),
            $match['date']
        );

        $post_data = array(
            'post_title'  => $title,
            'post_type'   => 'fixture_and_result',
            'post_status' => 'publish',
            'post_author' => get_current_user_id() ?: 1,
        );

        $post_id = wp_insert_post( $post_data, true );
        if ( is_wp_error( $post_id ) ) {
            fussball_sync_log( '[ERROR] Failed to create post: ' . $post_id->get_error_message() );
            return 0;
        }

        $this->save_post_meta( $post_id, $match );

        // Assign taxonomy category (friendly games go to the friendly category)
        $cat = $this->resolve_category( $match );
        if ( ! empty( $cat ) ) {
            wp_set_object_terms( $post_id, $cat, 'result_category', false );
        }

        return $post_id;
    }

    /**
     * Update an existing fixture_and_result post.
     *
     * @param int   $post_id
     * @param array $match
     */
    private function update_post( $post_id, array $match ) {
        $title = sprintf(
            '%s vs %s (%s)',
            $this->sanitize_team( $match['home_team'] ),
            $this->sanitize_team( $match['away_team'] ),
            $match['date']
        );

        wp_update_post( array(
            'ID'         => $post_id,
            'post_title' => $title,
        ) );

        $this->save_post_meta( $post_id, $match );

        // Re-apply the correct category in case the configuration changed
        $cat = $this->resolve_category( $match );
        if ( ! empty( $cat ) ) {
            wp_set_object_terms( $post_id, $cat, 'result_category', false );
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // Meta persistence (mirrors Goodlayers Soccer save logic)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Write all relevant post metas.
     *
     * @param int   $post_id
     * @param array $match
     */
    private function save_post_meta( $post_id, array $match ) {
        $settings = $this->build_settings( $match );

        // Store clean JSON.  Goodlayers Soccer reads back the value with
        // gdlr_lms_decode_preventslashes() which is a no-op when the string
        // contains no |gq*| placeholders, so plain JSON is fully compatible.
        $json = wp_json_encode( $settings, JSON_UNESCAPED_UNICODE );

        update_post_meta( $post_id, 'gdlr-soccer-fixture-result-settings', $json );

        // The gdlr-start-date meta is used for chronological ordering
        update_post_meta(
            $post_id,
            'gdlr-start-date',
            $settings['date-of-match'] . ' ' . $settings['match-time']
        );

        // Our own tracking keys
        update_post_meta( $post_id, 'fussball_match_id',    $match['match_id'] );
        update_post_meta( $post_id, 'fussball_competition', $match['competition'] ?? '' );
    }

    /**
     * Build the settings array for `gdlr-soccer-fixture-result-settings`.
     *
     * @param array $match
     * @return array
     */
    private function build_settings( array $match ) {
        $home_goal = ( $match['home_goals'] !== null ) ? (string) $match['home_goals'] : '';
        $away_goal = ( $match['away_goals'] !== null ) ? (string) $match['away_goals'] : '';

        return array(
            'home-flag'         => $match['home_flag'] ?? '',
            'away-flag'         => $match['away_flag'] ?? '',
            'home'              => $this->sanitize_team( $match['home_team'] ?? '' ),
            'home-goal'         => $home_goal,
            'away'              => $this->sanitize_team( $match['away_team'] ?? '' ),
            'away-goal'         => $away_goal,
            'date-of-match'     => $match['date'] ?? '',
            'match-time'        => $match['time'] ?? '00:00',
            'location'          => '',
            'show-match-detail' => 'disable',
            'match-report'      => '',
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Flag resolution
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Read the currently stored home-flag and away-flag values for a post.
     *
     * @param int|null $post_id  null for brand-new (not-yet-created) posts.
     * @return array  ['home' => string, 'away' => string]
     */
    private function get_stored_flags( $post_id ) {
        if ( ! $post_id ) {
            return array( 'home' => '', 'away' => '' );
        }
        $raw      = get_post_meta( $post_id, 'gdlr-soccer-fixture-result-settings', true );
        $raw      = $this->safe_decode( $raw );
        $settings = ! empty( $raw ) ? json_decode( $raw, true ) : array();
        if ( ! is_array( $settings ) ) {
            $settings = array();
        }
        return array(
            'home' => (string) ( $settings['home-flag'] ?? '' ),
            'away' => (string) ( $settings['away-flag'] ?? '' ),
        );
    }

    /**
     * Resolve home and away flags for a match.
     *
     * Already-stored (non-empty) flags are preserved untouched.
     * Empty slots are looked up in the flag cache and, as a fallback,
     * from the WordPress Media Library.
     *
     * @param array $match         Match data (will receive 'home_flag' and 'away_flag').
     * @param array $stored_flags  ['home' => string, 'away' => string].
     * @return array  [ updated_match, string[] log_lines, bool newly_resolved ]
     */
    private function resolve_flags( array $match, array $stored_flags ) {
        $log_lines      = array();
        $newly_resolved = false;

        // ── Home ──────────────────────────────────────────────────────────
        if ( ! empty( $stored_flags['home'] ) ) {
            $match['home_flag'] = $stored_flags['home'];
        } else {
            $result             = $this->find_team_flag( $match['home_team'] ?? '' );
            $match['home_flag'] = $result['value'];
            if ( ! empty( $result['value'] ) ) {
                $log_lines[]    = sprintf(
                    '[FLAG]  home "%s" → %s  |  id/url: %s',
                    $match['home_team'],
                    $result['source'],
                    $result['value']
                );
                $newly_resolved = true;
            } else {
                $log_lines[] = sprintf( '[FLAG]  home "%s" → not found', $match['home_team'] );
            }
        }

        // ── Away ──────────────────────────────────────────────────────────
        if ( ! empty( $stored_flags['away'] ) ) {
            $match['away_flag'] = $stored_flags['away'];
        } else {
            $result             = $this->find_team_flag( $match['away_team'] ?? '' );
            $match['away_flag'] = $result['value'];
            if ( ! empty( $result['value'] ) ) {
                $log_lines[]    = sprintf(
                    '[FLAG]  away "%s" → %s  |  id/url: %s',
                    $match['away_team'],
                    $result['source'],
                    $result['value']
                );
                $newly_resolved = true;
            } else {
                $log_lines[] = sprintf( '[FLAG]  away "%s" → not found', $match['away_team'] );
            }
        }

        return array( $match, $log_lines, $newly_resolved );
    }

    /**
     * Write only the home-flag and away-flag fields into an existing post's meta,
     * leaving all other settings intact.  Called when data hasn't changed but
     * flags were newly resolved.
     *
     * @param int   $post_id
     * @param array $match   Must contain 'home_flag' and 'away_flag'.
     */
    private function fill_flags_meta( $post_id, array $match ) {
        $raw      = get_post_meta( $post_id, 'gdlr-soccer-fixture-result-settings', true );
        $raw      = $this->safe_decode( $raw );
        $settings = ! empty( $raw ) ? json_decode( $raw, true ) : array();
        if ( ! is_array( $settings ) ) {
            $settings = array();
        }
        $settings['home-flag'] = $match['home_flag'] ?? '';
        $settings['away-flag'] = $match['away_flag'] ?? '';
        $json = wp_json_encode( $settings, JSON_UNESCAPED_UNICODE );
        update_post_meta( $post_id, 'gdlr-soccer-fixture-result-settings', $json );
    }

    /**
     * Build the team-flag cache from all existing fixture_and_result posts.
     * Scans every post's gdlr-soccer-fixture-result-settings JSON and extracts
     * team name → flag value pairs where both are non-empty.
     * Logs a one-line summary when done.
     */
    private function build_team_flag_cache() {
        $this->team_flag_cache = array();

        $posts = get_posts( array(
            'post_type'      => 'fixture_and_result',
            'post_status'    => array( 'publish', 'draft', 'private' ),
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ) );

        foreach ( $posts as $post_id ) {
            $raw      = get_post_meta( $post_id, 'gdlr-soccer-fixture-result-settings', true );
            $raw      = $this->safe_decode( $raw );
            $settings = ! empty( $raw ) ? json_decode( $raw, true ) : array();
            if ( ! is_array( $settings ) ) {
                continue;
            }
            foreach ( array( 'home' => 'home-flag', 'away' => 'away-flag' ) as $team_key => $flag_key ) {
                $name = trim( $settings[ $team_key ] ?? '' );
                $flag = trim( $settings[ $flag_key ] ?? '' );
                if ( empty( $name ) || empty( $flag ) ) {
                    continue;
                }
                $normalised = $this->normalise_team_name( $name );
                // First occurrence wins (most recently edited post should come last; get_posts
                // returns newest first by default, so first occurrence = newest).
                if ( ! isset( $this->team_flag_cache[ $normalised ] ) ) {
                    $this->team_flag_cache[ $normalised ] = array(
                        'value'  => $flag,
                        'source' => 'existing post #' . $post_id,
                    );
                }
            }
        }

        fussball_sync_log( sprintf(
            '[FLAG cache] Built from %d posts → %d unique team flags found',
            count( $posts ),
            count( $this->team_flag_cache )
        ) );
    }

    /**
     * Find a team flag by name.
     *
     * Search order:
     *   1. Exact normalised match in the existing-post cache.
     *   2. Partial normalised match in the cache (one name is a substring of the other).
     *   3. WordPress Media Library: attachment whose title contains the team name.
     *
     * @param string $team_name
     * @return array  ['value' => string, 'source' => string]
     *                'value' is '' when nothing was found.
     */
    private function find_team_flag( $team_name ) {
        $empty = array( 'value' => '', 'source' => 'none' );

        if ( empty( trim( $team_name ) ) ) {
            return $empty;
        }

        // Build cache lazily (once per sync run)
        if ( $this->team_flag_cache === null ) {
            $this->build_team_flag_cache();
        }

        $needle = $this->normalise_team_name( $team_name );

        // 1. Exact match
        if ( isset( $this->team_flag_cache[ $needle ] ) ) {
            return $this->team_flag_cache[ $needle ];
        }

        // 2. Partial match (handles suffixes like " (A)", "1.", abbreviations)
        foreach ( $this->team_flag_cache as $cached_name => $entry ) {
            if ( strpos( $cached_name, $needle ) !== false ||
                 strpos( $needle, $cached_name ) !== false ) {
                return array(
                    'value'  => $entry['value'],
                    'source' => $entry['source'] . ' (partial match "' . $cached_name . '")',
                );
            }
        }

        // 3. Media Library fallback: attachment with matching title
        global $wpdb;
        $like     = '%' . $wpdb->esc_like( $team_name ) . '%';
        $image_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
              WHERE post_type     = 'attachment'
                AND post_status   = 'inherit'
                AND post_mime_type LIKE 'image/%%'
                AND post_title    LIKE %s
              ORDER BY ID ASC
              LIMIT 1",
            $like
        ) );

        if ( $image_id ) {
            return array(
                'value'  => (string) $image_id,
                'source' => 'media library attachment #' . $image_id,
            );
        }

        return $empty;
    }

    /**
     * Normalise a team name for cache lookups.
     * Lowercases, squashes whitespace, strips trailing "e.V." / "e. V." legal forms.
     *
     * @param string $name
     * @return string
     */
    private function normalise_team_name( $name ) {
        return fussball_sync_team_key( $name ); // gemeinsamer Schlüssel (fussball-sync.php): Klammerzusätze, SGM-Ziffern, e. V.
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Decode a stored meta value into a clean JSON string.
     *
     * Handles both:
     *  - Values we stored (plain JSON, no special encoding)
     *  - Values stored by the Goodlayers Soccer UI (may contain |gq*| escape
     *    placeholder strings inserted by gdlr_lms_preventslashes())
     *
     * @param string $val
     * @return string
     */
    private function safe_decode( $val ) {
        $val = (string) $val;
        if ( function_exists( 'gdlr_lms_decode_preventslashes' ) ) {
            $val = gdlr_lms_decode_preventslashes( $val );
        }
        return $val;
    }

    /**
     * Check whether a post's result_category already contains the expected slug.
     * If not, correct it with wp_set_object_terms(). Returns true when a fix was applied.
     *
     * @param int   $post_id
     * @param array $match
     * @return bool
     */
    private function maybe_fix_category( $post_id, array $match ) {
        $expected = $this->resolve_category( $match );
        if ( empty( $expected ) ) {
            return false;
        }

        $current = wp_get_object_terms( $post_id, 'result_category', array( 'fields' => 'slugs' ) );
        if ( is_wp_error( $current ) ) {
            return false;
        }

        if ( in_array( $expected, $current, true ) ) {
            return false; // already correct
        }

        wp_set_object_terms( $post_id, $expected, 'result_category', false );
        return true;
    }

    /**
     * Check whether a match date falls inside the configured season range.
     * Dates use the Y-m-d format, so plain string comparison is sufficient.
     * An empty bound means "no limit" on that side.
     *
     * @param string $date  Match date (Y-m-d).
     * @return bool
     */
    private function is_in_season( $date ) {
        if ( ! empty( $this->season_start ) && $date < $this->season_start ) {
            return false;
        }
        if ( ! empty( $this->season_end ) && $date > $this->season_end ) {
            return false;
        }
        return true;
    }

    /**
     * Return true if the competition name identifies a friendly game.
     * Matches case-insensitively on "freundschaftsspiel".
     *
     * @param string $competition
     * @return bool
     */
    private function is_friendly( $competition ) {
        return stripos( $competition, 'freundschaftsspiel' ) !== false;
    }

    /**
     * Return the taxonomy slug to use for a given match.
     * Friendly games use $this->friendly_category (if configured),
     * all other matches use $this->category.
     *
     * @param array $match
     * @return string  Empty string = no category assignment.
     */
    private function resolve_category( array $match ) {
        if ( ! empty( $this->friendly_category ) && $this->is_friendly( $match['competition'] ?? '' ) ) {
            return $this->friendly_category;
        }
        return $this->category;
    }

    /**
     * Sanitise a team name for storage.
     *
     * @param string $name
     * @return string
     */
    private function sanitize_team( $name ) {
        return sanitize_text_field( $name );
    }
}
