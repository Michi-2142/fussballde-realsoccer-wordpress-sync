<?php
/**
 * Fussball_Sync_Manager
 *
 * Schreibt die von fussball.de gelesenen Spiele einer Mannschaft in den eigenen Beitragstyp „Spiele“
 * (fussball_spiel, includes/spiele.php). Seit 2.0.0 ohne Abhängigkeit von Theme oder Goodlayers Soccer.
 *
 * Zuordnung eines Spiels zu einem vorhandenen Beitrag: fussball.de-Kennung (_fussball_spiel_id), alte Pseudo-Kennungen,
 * dann Datum plus tolerante Teamschlüssel (fussball_sync_team_key: „(Flex)“, „II/I/I“ usw.).
 * Jede Mannschaft sieht nur ihre eigenen Spiele (Begriff in fussball_mannschaft) und Spiele ohne Mannschaft;
 * ein Freundschaftsspiel 1. gegen 2. Mannschaft steht deshalb bei beiden.
 *
 * Abbildung:
 *   fussball.de          →   Feld
 *   home_team / away_team →  heim / gast
 *   home_goals / away_goals → tore_heim / tore_gast (leer = noch kein Ergebnis)
 *   date / time          →   datum / zeit (anstoss wird mitgeschrieben)
 *   competition          →   wettbewerb
 *   match_id             →   spiel_id
 */
defined( 'ABSPATH' ) || exit;

class Fussball_Sync_Manager {

    /** @var string  Slug der Mannschaft (Taxonomie fussball_mannschaft). */
    private $team_slug;

    /** @var string  Bezeichnung der Mannschaft (für Logs). */
    private $team_label;

    /** @var string  First day of the current season (Y-m-d). Empty = no lower bound. */
    private $season_start;

    /** @var string  Last day of the current season (Y-m-d). Empty = no upper bound. */
    private $season_end;

    /** @var array|null  Lazy cache: normalised team name => ['value'=>string, 'source'=>string]. */
    private $team_flag_cache = null;

    /** @var bool  Trockenlauf: alles loggen, nichts schreiben. */
    private $dry = false;

    /** @var array  Beitrags-IDs, die in diesem Lauf einem fussball.de-Spiel zugeordnet wurden (für die Waisen-Bereinigung). */
    private $matched = array();

    /** @var array|null  Cache Datum|Heim-Schlüssel|Gast-Schlüssel => Beitrags-IDs (Fallback-Suche ohne Kennung). */
    private $key_cache = null;

    /** @var int  Anzahl Pseudo-Kennungen, die in diesem Lauf durch echte ersetzt wurden. */
    private $ids_migrated = 0;

    public function set_dry_run( $on ) { $this->dry = (bool) $on; }

    /**
     * @param array  $team          Team aus fussball_sync_get_teams() (label, slug).
     * @param string $season_start  First day of the season, Y-m-d (empty = no lower bound).
     * @param string $season_end    Last day of the season, Y-m-d (empty = no upper bound).
     */
    public function __construct( array $team, $season_start = '', $season_end = '' ) {
        $this->team_slug    = fussball_sync_team_slug( $team );
        $this->team_label   = (string) ( $team['label'] ?? $this->team_slug );
        $this->season_start = sanitize_text_field( $season_start );
        $this->season_end   = sanitize_text_field( $season_end );
    }

    /** Abfrage auf Spiele dieser Mannschaft und Spiele ohne Mannschaft beschränken. */
    private function scope_query( array $args ) {
        $args['post_type']        = FUSSBALL_SPIEL_PT;
        $args['suppress_filters'] = true;
        if ( ! isset( $args['post_status'] ) ) {
            $args['post_status'] = array( 'publish', 'draft', 'private', 'future', 'pending' );
        }
        if ( '' !== $this->team_slug ) {
            $args['tax_query'] = array(
                'relation' => 'OR',
                array( 'taxonomy' => FUSSBALL_TEAM_TAX, 'field' => 'slug', 'terms' => array( $this->team_slug ) ),
                array( 'taxonomy' => FUSSBALL_TEAM_TAX, 'operator' => 'NOT EXISTS' ),
            );
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
     * @param array $counts   ['prev' => int, 'upcoming' => int] from the parser (guards the orphan cleanup).
     * @return array
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

        fussball_sync_log( sprintf( '── Parsed %d matches from fussball.de ──', count( $matches ) ) );
        foreach ( $matches as $match ) {
            fussball_sync_log( $this->format_parsed_match( $match ) );
        }

        fussball_sync_log( '── Starting sync ──' );
        foreach ( $matches as $match ) {
            $result = $this->sync_single_match( $match );
            if ( isset( $stats[ $result ] ) ) {
                $stats[ $result ]++;
            } else {
                $stats['skipped']++;
            }
        }

        $stats['ids_migrated']       = $this->ids_migrated;
        $stats['duplicates_removed'] = $this->remove_duplicates();
        $stats['orphans_removed']    = $this->remove_orphans( $matches, $counts );

        if ( ! $this->dry && function_exists( 'wpo_cache_flush' ) && ( $stats['created'] || $stats['updated_score'] || $stats['updated_details'] || $stats['duplicates_removed'] || $stats['orphans_removed'] ) ) {
            wpo_cache_flush();
        }
        return $stats;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Parsed-match log line
    // ──────────────────────────────────────────────────────────────────────

    private function format_parsed_match( array $match ) {
        if ( $match['status'] === 'played' ) {
            $score_str = ( $match['home_goals'] !== null && $match['away_goals'] !== null )
                ? $match['home_goals'] . ':' . $match['away_goals']
                : 'played (score unknown)';
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

        if ( empty( $match['date'] ) || empty( $match['match_id'] ) ) {
            fussball_sync_log( '[SKIP invalid]  ' . $label . '  |  missing date or match_id' );
            return 'skipped';
        }

        if ( ! $this->is_in_season( $match['date'] ) ) {
            fussball_sync_log( sprintf( '[SKIP out of season]  %s  |  outside %s – %s', $label, $this->season_start ?: '…', $this->season_end ?: '…' ) );
            return 'out_of_season';
        }

        $existing_id = $this->find_post_for_match( $match );
        if ( $existing_id ) {
            $this->matched[ $existing_id ] = true;
        }

        // Abgesagt: vorhandenes Spiel ohne Ergebnis kommt in den Papierkorb (sonst stünde es ewig als „Ergebnis offen“)
        if ( $match['status'] === 'cancelled' ) {
            if ( $existing_id && ! $this->post_has_score( $existing_id ) ) {
                fussball_sync_log( '[ABGESAGT]  ' . $label . '  |  post #' . $existing_id . ' → Papierkorb' . ( $this->dry ? ' (dry)' : '' ) );
                if ( ! $this->dry ) { wp_trash_post( $existing_id ); }
            } else {
                fussball_sync_log( '[SKIP cancelled]  ' . $label . '  |  Absetzung on fussball.de' );
            }
            return 'cancelled';
        }

        // Wappen: gespeicherte behalten, fehlende suchen
        $stored_flags = $this->get_stored_flags( $existing_id );
        list( $match, $flag_log_lines, $flags_newly_resolved ) = $this->resolve_flags( $match, $stored_flags );

        $art_note = 'liga' !== fussball_spiel_art( $match['competition'] ?? '' ) ? '  |  ' . ( $match['competition'] ?? '' ) : '';

        if ( ! $existing_id ) {
            $score_note = ( $match['home_goals'] !== null && $match['away_goals'] !== null )
                ? 'score: ' . $match['home_goals'] . ':' . $match['away_goals']
                : 'no score yet';
            fussball_sync_log( '[CREATE]  ' . $label . '  |  ' . $score_note . $art_note . ( $this->dry ? '  (dry)' : '' ) );
            foreach ( $flag_log_lines as $line ) {
                fussball_sync_log( $line );
            }
            if ( ! $this->dry ) { $this->create_post( $match ); }
            return 'created';
        }

        $diff = $this->detect_changes( $existing_id, $match );

        if ( ! $diff['needs_update'] ) {
            $team_fixed = $this->dry ? false : $this->maybe_fix_team( $existing_id );
            if ( $flags_newly_resolved && ! $this->dry ) {
                $this->fill_flags_meta( $existing_id, $match );
                foreach ( $flag_log_lines as $line ) {
                    fussball_sync_log( $line );
                }
            }
            if ( $team_fixed ) {
                fussball_sync_log( '[TEAM fix]  ' . $label . '  |  post #' . $existing_id . '  |  → ' . $this->team_slug );
            }
            fussball_sync_log( ( $flags_newly_resolved || $team_fixed ? '[SKIP fixed]  ' : '[SKIP no change]  ' ) . $label . '  |  post #' . $existing_id );
            return 'skipped';
        }

        $change_lines = array();
        foreach ( $diff['new'] as $key => $new_val ) {
            $change_lines[] = $key . ': "' . ( $diff['old'][ $key ] ?? '' ) . '" → "' . $new_val . '"';
        }
        $change_desc = implode( '  |  ', $change_lines );
        $only_score  = $diff['changes']['score'] && ! $diff['changes']['teams'] && ! $diff['changes']['datetime'] && ! $diff['changes']['details'];
        $tag         = $only_score ? '[UPDATE score]  ' : '[UPDATE details]  ';

        fussball_sync_log( $tag . $label . '  |  post #' . $existing_id . '  |  ' . $change_desc . $art_note . ( $this->dry ? '  (dry)' : '' ) );
        foreach ( $flag_log_lines as $line ) {
            fussball_sync_log( $line );
        }
        if ( ! $this->dry ) { $this->update_post( $existing_id, $match ); }
        return $only_score ? 'updated_score' : 'updated_details';
    }

    // ──────────────────────────────────────────────────────────────────────
    // Deduplication
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Dubletten (gleiches Datum, gleiche Teamschlüssel) dieser Mannschaft in den Papierkorb.
     * Behalten wird: mit Ergebnis vor ohne, echte fussball.de-Kennung vor Pseudo, mit Wappen vor ohne, sonst der neueste.
     *
     * @return int  Number of duplicate posts removed.
     */
    private function remove_duplicates() {
        $seen = array();
        foreach ( $this->all_match_posts() as $post_id => $s ) {
            if ( '' === $s['datum'] || '' === $s['heim'] || '' === $s['gast'] ) {
                continue;
            }
            $key = $s['datum'] . '|' . fussball_sync_team_key( $s['heim'] ) . '|' . fussball_sync_team_key( $s['gast'] );
            $seen[ $key ][] = array(
                'id'        => (int) $post_id,
                'has_flags' => '' !== $s['wappen_heim'] || '' !== $s['wappen_gast'],
                'has_score' => $s['gespielt'],
                'real_id'   => '' !== $s['spiel_id'] && 0 !== strpos( $s['spiel_id'], 'pseudo_' ),
            );
        }

        $removed = 0;
        foreach ( $seen as $key => $entries ) {
            if ( count( $entries ) <= 1 ) {
                continue;
            }
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
                    $dup['id'], $keep['id'], $dup['has_score'] ? 'ja' : 'nein', $dup['real_id'] ? 'echt' : 'pseudo', $key, $this->dry ? '  (dry)' : ''
                ) );
            }
        }
        fussball_sync_log( $removed > 0 ? sprintf( '[DEDUP]  Removed %d duplicate(s).', $removed ) : '[DEDUP]  No duplicates found.' );
        return $removed;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Existence check
    // ──────────────────────────────────────────────────────────────────────

    /** Spiel dieser Mannschaft mit der Kennung $match_id (oder null). */
    private function find_post_by_match_id( $match_id ) {
        $posts = get_posts( $this->scope_query( array(
            'posts_per_page' => 1,
            'meta_query'     => array( array( 'key' => '_fussball_spiel_id', 'value' => $match_id ) ),
            'fields'         => 'ids',
        ) ) );
        return ! empty( $posts ) ? (int) $posts[0] : null;
    }

    /**
     * Beitrag zu einem fussball.de-Spiel finden:
     *  1. über die Kennung (echte fussball.de-ID oder aktuelle Pseudo-Kennung),
     *  2. über die alten Pseudo-Kennungen (md5 aus Datum + rohen bzw. normalisierten Namen),
     *  3. über Datum + tolerante Teamschlüssel.
     * Treffer über 2. oder 3. bekommen sofort die echte Kennung.
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
                $cands = $this->key_cache[ $key ];
                usort( $cands, function ( $a, $b ) { return array( $this->post_has_score( $b ), $b ) <=> array( $this->post_has_score( $a ), $a ); } );
                $id = (int) $cands[0];
            }
        }
        if ( $id && 0 !== strpos( $match['match_id'], 'pseudo_' ) ) {
            fussball_sync_log( sprintf( '[ID]  post #%d  %s  |  Kennung → %s%s', $id, get_post_meta( $id, '_fussball_spiel_id', true ) ?: '(von Hand)', $match['match_id'], $this->dry ? '  (dry)' : '' ) );
            if ( ! $this->dry ) { fussball_spiel_speichern( $id, array( 'spiel_id' => $match['match_id'] ) ); }
            $this->ids_migrated++;
        }
        return $id ?: null;
    }

    private function build_key_cache() {
        $this->key_cache = array();
        foreach ( $this->all_match_posts() as $post_id => $s ) {
            if ( '' === $s['datum'] ) { continue; }
            $key = $s['datum'] . '|' . fussball_sync_team_key( $s['heim'] ) . '|' . fussball_sync_team_key( $s['gast'] );
            $this->key_cache[ $key ][] = $post_id;
        }
    }

    /** Alle Spiele dieser Mannschaft (und ohne Mannschaft): [ post_id => fussball_spiel() ]. */
    private function all_match_posts() {
        $out = array();
        $ids = get_posts( $this->scope_query( array( 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ) ) );
        foreach ( $ids as $post_id ) {
            $out[ (int) $post_id ] = fussball_spiel( $post_id );
        }
        return $out;
    }

    private function post_has_score( $post_id ) {
        return '' !== (string) get_post_meta( $post_id, '_fussball_tore_heim', true ) && '' !== (string) get_post_meta( $post_id, '_fussball_tore_gast', true );
    }

    /**
     * Waisen bereinigen: Spiele dieser Mannschaft, die vom Sync stammen (Kennung gesetzt), in der Saison liegen,
     * kein Ergebnis haben und in diesem Lauf keinem fussball.de-Spiel zugeordnet wurden (verlegt oder gestrichen).
     * Schutz: nur wenn fussball.de genug Spiele geliefert hat und die passende Liste (vergangen / kommend) nicht leer war.
     */
    private function remove_orphans( array $matches, array $counts ) {
        if ( '' === $this->team_slug || count( $matches ) < 5 ) {
            return 0;
        }
        $today = current_time( 'Y-m-d' );
        $ids   = get_posts( array(
            'post_type' => FUSSBALL_SPIEL_PT, 'post_status' => array( 'publish', 'draft', 'private', 'future', 'pending' ), 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true,
            'tax_query'  => array( array( 'taxonomy' => FUSSBALL_TEAM_TAX, 'field' => 'slug', 'terms' => array( $this->team_slug ) ) ),
            'meta_query' => array( array( 'key' => '_fussball_spiel_id', 'compare' => 'EXISTS' ) ),
        ) );
        $removed = 0;
        foreach ( $ids as $post_id ) {
            $post_id = (int) $post_id;
            if ( isset( $this->matched[ $post_id ] ) ) { continue; }
            $s = fussball_spiel( $post_id );
            if ( '' === $s['datum'] || ! $this->is_in_season( $s['datum'] ) || $s['gespielt'] ) { continue; }
            $past = $s['datum'] < $today;
            if ( ( $past && empty( $counts['prev'] ) ) || ( ! $past && empty( $counts['upcoming'] ) ) ) { continue; }
            fussball_sync_log( sprintf( '[VERWAIST]  %s vs %s (%s)  |  post #%d nicht mehr bei fussball.de, kein Ergebnis → Papierkorb%s', $s['heim'] ?: '?', $s['gast'] ?: '?', $s['datum'], $post_id, $this->dry ? '  (dry)' : '' ) );
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
     * Gespeicherte Werte mit fussball.de vergleichen.
     * @return array [ 'needs_update' => bool, 'changes' => [score, datetime, teams, details], 'old' => [], 'new' => [] ]
     */
    private function detect_changes( $post_id, array $match ) {
        $alt    = fussball_spiel( $post_id );
        $neu    = $this->build_daten( $match );
        $groups = array(
            'tore_heim'  => 'score',
            'tore_gast'  => 'score',
            'datum'      => 'datetime',
            'zeit'       => 'datetime',
            'heim'       => 'teams',
            'gast'       => 'teams',
            'wettbewerb' => 'details',
        );
        $changed = array( 'score' => false, 'datetime' => false, 'teams' => false, 'details' => false );
        $old_vals = array();
        $new_vals = array();
        foreach ( $groups as $key => $group ) {
            $o = (string) ( $alt[ $key ] ?? '' );
            $n = (string) ( $neu[ $key ] ?? '' );
            if ( 'wettbewerb' === $key && '' === $n ) {
                continue; // fussball.de ohne Wettbewerb: vorhandenen Wert nicht löschen
            }
            if ( $o !== $n ) {
                $changed[ $group ] = true;
                $old_vals[ $key ]  = $o;
                $new_vals[ $key ]  = $n;
            }
        }
        return array(
            'needs_update' => in_array( true, $changed, true ),
            'changes'      => $changed,
            'old'          => $old_vals,
            'new'          => $new_vals,
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Create / Update
    // ──────────────────────────────────────────────────────────────────────

    private function create_post( array $match ) {
        $post_id = wp_insert_post( array(
            'post_title'  => sprintf( '%s vs %s (%s)', $this->sanitize_team( $match['home_team'] ), $this->sanitize_team( $match['away_team'] ), $match['date'] ),
            'post_type'   => FUSSBALL_SPIEL_PT,
            'post_status' => 'publish',
            'post_author' => get_current_user_id() ?: 1,
        ), true );
        if ( is_wp_error( $post_id ) ) {
            fussball_sync_log( '[ERROR] Failed to create post: ' . $post_id->get_error_message() );
            return 0;
        }
        fussball_spiel_speichern( $post_id, $this->build_daten( $match ) );
        if ( '' !== $this->team_slug ) {
            wp_set_object_terms( $post_id, $this->team_slug, FUSSBALL_TEAM_TAX, false );
        }
        fussball_spiel_titel_setzen( $post_id );
        return $post_id;
    }

    private function update_post( $post_id, array $match ) {
        $daten = $this->build_daten( $match );
        if ( '' === $daten['wettbewerb'] ) {
            unset( $daten['wettbewerb'] );
        }
        fussball_spiel_speichern( $post_id, $daten );
        $this->maybe_fix_team( $post_id );
        fussball_spiel_titel_setzen( $post_id );
    }

    /** Felder eines fussball.de-Spiels für fussball_spiel_speichern(). Ort bleibt unberührt. */
    private function build_daten( array $match ) {
        $time = (string) ( $match['time'] ?? '' );
        return array(
            'heim'        => $this->sanitize_team( $match['home_team'] ?? '' ),
            'gast'        => $this->sanitize_team( $match['away_team'] ?? '' ),
            'tore_heim'   => $match['home_goals'] !== null ? (string) $match['home_goals'] : '',
            'tore_gast'   => $match['away_goals'] !== null ? (string) $match['away_goals'] : '',
            'datum'       => (string) ( $match['date'] ?? '' ),
            'zeit'        => '00:00' === $time ? '' : $time,
            'wettbewerb'  => sanitize_text_field( (string) ( $match['competition'] ?? '' ) ),
            'saison'      => fussball_saison_aus_datum( (string) ( $match['date'] ?? '' ) ),
            'wappen_heim' => (string) ( $match['home_flag'] ?? '' ),
            'wappen_gast' => (string) ( $match['away_flag'] ?? '' ),
            'spiel_id'    => (string) $match['match_id'],
        );
    }

    /** Spiel ohne Mannschaft bekommt diese Mannschaft. Gibt true zurück, wenn etwas gesetzt wurde. */
    private function maybe_fix_team( $post_id ) {
        if ( '' === $this->team_slug ) {
            return false;
        }
        $terms = wp_get_object_terms( $post_id, FUSSBALL_TEAM_TAX, array( 'fields' => 'slugs' ) );
        if ( is_wp_error( $terms ) || in_array( $this->team_slug, $terms, true ) ) {
            return false;
        }
        if ( $terms ) {
            return false; // gehört einer anderen Mannschaft, nicht umhängen
        }
        wp_set_object_terms( $post_id, $this->team_slug, FUSSBALL_TEAM_TAX, false );
        return true;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Flag resolution
    // ──────────────────────────────────────────────────────────────────────

    private function get_stored_flags( $post_id ) {
        if ( ! $post_id ) {
            return array( 'home' => '', 'away' => '' );
        }
        return array(
            'home' => (string) get_post_meta( $post_id, '_fussball_wappen_heim', true ),
            'away' => (string) get_post_meta( $post_id, '_fussball_wappen_gast', true ),
        );
    }

    /**
     * Wappen für Heim und Gast: gespeicherte bleiben, fehlende kommen aus anderen Spielen oder der Mediathek.
     * @return array [ updated_match, string[] log_lines, bool newly_resolved ]
     */
    private function resolve_flags( array $match, array $stored_flags ) {
        $log_lines      = array();
        $newly_resolved = false;
        foreach ( array( 'home', 'away' ) as $side ) {
            if ( ! empty( $stored_flags[ $side ] ) ) {
                $match[ $side . '_flag' ] = $stored_flags[ $side ];
                continue;
            }
            $team   = $match[ $side . '_team' ] ?? '';
            $result = $this->find_team_flag( $team );
            $match[ $side . '_flag' ] = $result['value'];
            if ( ! empty( $result['value'] ) ) {
                $log_lines[]    = sprintf( '[FLAG]  %s "%s" → %s  |  id/url: %s', $side, $team, $result['source'], $result['value'] );
                $newly_resolved = true;
            } else {
                $log_lines[] = sprintf( '[FLAG]  %s "%s" → not found', $side, $team );
            }
        }
        return array( $match, $log_lines, $newly_resolved );
    }

    private function fill_flags_meta( $post_id, array $match ) {
        fussball_spiel_speichern( $post_id, array(
            'wappen_heim' => $match['home_flag'] ?? '',
            'wappen_gast' => $match['away_flag'] ?? '',
        ) );
    }

    /** Wappen-Cache aus allen Spielen (aller Mannschaften): Teamschlüssel → Wappen. */
    private function build_team_flag_cache() {
        $this->team_flag_cache = array();
        $posts = get_posts( array(
            'post_type'        => FUSSBALL_SPIEL_PT,
            'post_status'      => array( 'publish', 'draft', 'private' ),
            'posts_per_page'   => -1,
            'fields'           => 'ids',
            'suppress_filters' => true,
        ) );
        foreach ( $posts as $post_id ) {
            foreach ( array( 'heim', 'gast' ) as $seite ) {
                $name = trim( (string) get_post_meta( $post_id, '_fussball_' . $seite, true ) );
                $flag = trim( (string) get_post_meta( $post_id, '_fussball_wappen_' . $seite, true ) );
                if ( '' === $name || '' === $flag ) {
                    continue;
                }
                $normalised = fussball_sync_team_key( $name );
                if ( ! isset( $this->team_flag_cache[ $normalised ] ) ) {
                    $this->team_flag_cache[ $normalised ] = array( 'value' => $flag, 'source' => 'existing post #' . $post_id );
                }
            }
        }
        fussball_sync_log( sprintf( '[FLAG cache] Built from %d posts → %d unique team flags found', count( $posts ), count( $this->team_flag_cache ) ) );
    }

    /**
     * Wappen zu einem Teamnamen: 1. gleicher Teamschlüssel in vorhandenen Spielen, 2. Teilübereinstimmung,
     * 3. Bild in der Mediathek, dessen Titel den Teamnamen enthält.
     */
    private function find_team_flag( $team_name ) {
        $empty = array( 'value' => '', 'source' => 'none' );
        if ( '' === trim( (string) $team_name ) ) {
            return $empty;
        }
        if ( null === $this->team_flag_cache ) {
            $this->build_team_flag_cache();
        }
        $needle = fussball_sync_team_key( $team_name );
        if ( isset( $this->team_flag_cache[ $needle ] ) ) {
            return $this->team_flag_cache[ $needle ];
        }
        foreach ( $this->team_flag_cache as $cached_name => $entry ) {
            if ( strpos( $cached_name, $needle ) !== false || strpos( $needle, $cached_name ) !== false ) {
                return array( 'value' => $entry['value'], 'source' => $entry['source'] . ' (partial match "' . $cached_name . '")' );
            }
        }
        global $wpdb;
        $image_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
              WHERE post_type = 'attachment' AND post_status = 'inherit' AND post_mime_type LIKE 'image/%%' AND post_title LIKE %s
              ORDER BY ID ASC LIMIT 1",
            '%' . $wpdb->esc_like( $team_name ) . '%'
        ) );
        if ( $image_id ) {
            return array( 'value' => (string) $image_id, 'source' => 'media library attachment #' . $image_id );
        }
        return $empty;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    private function is_in_season( $date ) {
        if ( ! empty( $this->season_start ) && $date < $this->season_start ) {
            return false;
        }
        if ( ! empty( $this->season_end ) && $date > $this->season_end ) {
            return false;
        }
        return true;
    }

    private function sanitize_team( $name ) {
        return sanitize_text_field( $name );
    }
}
