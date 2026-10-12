<?php
/**
 * Umstellung auf das eigene Datenmodell (2.0.0): Goodlayers-Spiele (fixture_and_result) werden zu fussball_spiel.
 *
 * Je Beitrag: Felder aus dem Goodlayers-JSON (gdlr-soccer-fixture-result-settings) in die flachen _fussball_*-Felder,
 * Mannschaft aus der Kategorie (Teams der Einstellungen) bzw. aus dem eigenen Teamnamen, Beitragstyp umgestellt.
 * Die Beitrags-IDs bleiben, die alten Felder und Kategorien bleiben als Rückweg stehen
 * (fussball_sync_migration_v2_rueckgaengig() stellt den Beitragstyp zurück).
 * Solange die Umstellung fehlt (Option fussball_sync_schema < 2), setzt der Sync aus.
 */
defined( 'ABSPATH' ) || exit;

function fussball_sync_schema() {
    return (int) get_option( 'fussball_sync_schema', 1 );
}

/** Goodlayers-Platzhalter (|gq2| usw.) zurückwandeln, wie gdlr_lms_decode_preventslashes() im Soccer-Plugin. */
function fussball_sync_gdlr_decode( $value ) {
    $map = array(
        '|gq6|' => '\\\\\\"', '|gq5|' => '\\\\\"', '|gq4|' => '\\\\"', '|gq3|' => '\\\"', '|gq2|' => '\\"', '|gq"|' => '\"',
        '|g2t|' => '\\\t', '|g1t|' => '\t', '|g2n|' => '\\\n', '|g1n|' => '\n',
    );
    foreach ( $map as $von => $nach ) {
        $value = str_replace( $von, $nach, (string) $value );
    }
    return $value;
}

/** result_category eines Beitrags direkt aus der Datenbank (funktioniert auch ohne Soccer-Plugin): [ slug => name ]. */
function fussball_sync_alte_kategorien( $post_id ) {
    global $wpdb;
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT t.slug, t.name FROM {$wpdb->term_relationships} tr
           JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
           JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
          WHERE tr.object_id = %d AND tt.taxonomy = 'result_category'",
        $post_id
    ), ARRAY_A );
    $out = array();
    foreach ( (array) $rows as $r ) {
        $out[ $r['slug'] ] = $r['name'];
    }
    return $out;
}

/** Kategoriename ohne Saison: „Kreisliga B3 26/27“ → „Kreisliga B3“. */
function fussball_sync_ohne_saison( $name ) {
    return trim( preg_replace( '/\s+\d{2}\s*\/\s*\d{2}$/u', '', (string) $name ) );
}

/**
 * Umstellung ausführen. $dry = true: nur berichten.
 * @return array [ 'zeilen' => string[], 'anzahl' => int, 'fehler' => int ]
 */
function fussball_sync_migration_v2( $dry = true ) {
    global $wpdb;
    $zeilen = array();
    $fehler = 0;

    // 1. Teams: Slug und Liganame festschreiben, Kategorie → Mannschaft
    $teams_raw = get_option( 'fussball_sync_teams', null );
    $teams     = fussball_sync_get_teams( false );
    $kat_team  = array();
    foreach ( $teams as $i => $team ) {
        $slug = fussball_sync_team_slug( $team );
        $teams[ $i ]['slug'] = $slug;
        foreach ( array( $team['category'] ?? '', $team['friendly_category'] ?? '' ) as $kat ) {
            if ( '' !== $kat ) {
                $kat_team[ $kat ] = $slug;
            }
        }
        if ( '' === trim( (string) ( $team['liga'] ?? '' ) ) && ! empty( $team['category'] ) ) {
            $term = $wpdb->get_var( $wpdb->prepare( "SELECT t.name FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = 'result_category' AND t.slug = %s", $team['category'] ) );
            $teams[ $i ]['liga'] = $term ? fussball_sync_ohne_saison( $term ) : '';
        }
        $zeilen[] = sprintf( 'Team „%s“ → Mannschaft %s, Liga „%s“, Kategorien %s / %s', $team['label'], $slug, $teams[ $i ]['liga'] ?? '', $team['category'] ?: '–', $team['friendly_category'] ?: '–' );
    }

    // 2. Alle Goodlayers-Spiele (auch Papierkorb)
    $ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'fixture_and_result' ORDER BY ID ASC" );

    // eigener Teamschlüssel je Mannschaft (häufigster Teamname in ihren Kategorien), für Spiele ohne passende Kategorie
    $zaehler = array();
    $daten   = array();
    foreach ( $ids as $id ) {
        $raw = fussball_sync_gdlr_decode( get_post_meta( $id, 'gdlr-soccer-fixture-result-settings', true ) );
        $o   = json_decode( $raw, true );
        $o   = is_array( $o ) ? $o : array();
        $kat = fussball_sync_alte_kategorien( $id );
        $daten[ $id ] = array( 'o' => $o, 'kat' => $kat );
        foreach ( array_keys( $kat ) as $k ) {
            if ( isset( $kat_team[ $k ] ) ) {
                foreach ( array( 'home', 'away' ) as $seite ) {
                    $key = fussball_sync_team_key( $o[ $seite ] ?? '' );
                    if ( '' !== $key ) {
                        $zaehler[ $kat_team[ $k ] ][ $key ] = ( $zaehler[ $kat_team[ $k ] ][ $key ] ?? 0 ) + 1;
                    }
                }
            }
        }
    }
    $eigen = array(); // team slug => eigener Teamschlüssel
    foreach ( $zaehler as $slug => $keys ) {
        arsort( $keys );
        $eigen[ $slug ] = (string) key( $keys );
        $zeilen[] = sprintf( 'Eigener Teamname für %s: „%s“', $slug, $eigen[ $slug ] );
    }

    // Teams mit Slug und Liganame speichern (Kategorien bleiben für den Rückweg stehen), Mannschaften anlegen
    if ( ! $dry ) {
        if ( is_array( $teams_raw ) ) {
            update_option( 'fussball_sync_teams', array_values( array_map( function ( $t ) {
                return array_intersect_key( $t, array_flip( array( 'label', 'url', 'slug', 'liga', 'category', 'friendly_category', 'enabled' ) ) );
            }, $teams ) ) );
        }
        fussball_sync_mannschaften_anlegen();
    }

    // 3. Je Beitrag umstellen
    $migriert = array();
    foreach ( $ids as $id ) {
        $o   = $daten[ $id ]['o'];
        $kat = $daten[ $id ]['kat'];
        $status = get_post_status( $id );

        $team = '';
        foreach ( array_keys( $kat ) as $k ) {
            if ( isset( $kat_team[ $k ] ) ) {
                $team = $kat_team[ $k ];
                break;
            }
        }
        if ( '' === $team ) {
            $treffer = array();
            foreach ( $eigen as $slug => $key ) {
                if ( fussball_sync_team_key( $o['home'] ?? '' ) === $key || fussball_sync_team_key( $o['away'] ?? '' ) === $key ) {
                    $treffer[] = $slug;
                }
            }
            $team = 1 === count( $treffer ) ? $treffer[0] : ( 1 === count( $teams ) ? fussball_sync_team_slug( $teams[0] ) : '' );
        }

        $wettbewerb = (string) get_post_meta( $id, 'fussball_competition', true );
        if ( '' === $wettbewerb && $kat ) {
            $wettbewerb = fussball_sync_ohne_saison( reset( $kat ) );
        }
        $datum = (string) ( $o['date-of-match'] ?? '' );
        $zeit  = (string) ( $o['match-time'] ?? '' );
        $zeit  = ( '' === $zeit || '00:00' === $zeit ) ? '' : $zeit;
        if ( '' === $datum ) {
            $start = (string) get_post_meta( $id, 'gdlr-start-date', true );
            $datum = substr( $start, 0, 10 );
        }
        $neu = array(
            'heim'        => (string) ( $o['home'] ?? '' ),
            'gast'        => (string) ( $o['away'] ?? '' ),
            'tore_heim'   => trim( (string) ( $o['home-goal'] ?? '' ) ),
            'tore_gast'   => trim( (string) ( $o['away-goal'] ?? '' ) ),
            'datum'       => $datum,
            'zeit'        => $zeit,
            'wettbewerb'  => $wettbewerb,
            'saison'      => fussball_saison_aus_datum( $datum ),
            'wappen_heim' => trim( (string) ( $o['home-flag'] ?? '' ) ),
            'wappen_gast' => trim( (string) ( $o['away-flag'] ?? '' ) ),
            'ort'         => trim( (string) ( $o['location'] ?? '' ) ),
            'spiel_id'    => (string) get_post_meta( $id, 'fussball_match_id', true ),
        );
        $bericht = trim( (string) ( $o['match-report'] ?? '' ) );

        $zeilen[] = sprintf(
            '#%d %s %s %s | %s – %s %s | %s | %s | Saison %s | %s%s%s',
            $id, $status, $neu['datum'], $neu['zeit'] ?: '--:--', $neu['heim'], $neu['gast'],
            ( '' !== $neu['tore_heim'] && '' !== $neu['tore_gast'] ) ? $neu['tore_heim'] . ':' . $neu['tore_gast'] : '-:-',
            $team ?: 'OHNE MANNSCHAFT', $neu['wettbewerb'] ?: '?', $neu['saison'],
            '' !== $neu['spiel_id'] ? ( 0 === strpos( $neu['spiel_id'], 'pseudo_' ) ? 'pseudo' : 'fussball.de' ) : 'von Hand',
            '' !== $neu['ort'] ? ' | Ort ' . $neu['ort'] : '',
            '' !== $bericht ? ' | Bericht ' . mb_strlen( wp_strip_all_tags( $bericht ) ) . ' Zeichen' : ''
        );
        if ( '' === $team || '' === $neu['datum'] ) {
            $fehler++;
        }
        if ( $dry ) {
            continue;
        }

        fussball_spiel_speichern( $id, $neu );
        $update = array( 'post_type' => FUSSBALL_SPIEL_PT );
        if ( '' !== $bericht && '' === trim( (string) get_post_field( 'post_content', $id ) ) ) {
            $update['post_content'] = $bericht;
        }
        $wpdb->update( $wpdb->posts, $update, array( 'ID' => $id ) );
        clean_post_cache( $id );
        if ( '' !== $team ) {
            wp_set_object_terms( $id, $team, FUSSBALL_TEAM_TAX, false );
        }
        fussball_spiel_titel_setzen( $id );
        $migriert[] = (int) $id;
    }

    if ( ! $dry ) {
        update_option( 'fussball_sync_migration_v2', array( 'zeit' => current_time( 'mysql' ), 'ids' => $migriert ), false );
        update_option( 'fussball_sync_schema', 2 );
        if ( function_exists( 'wpo_cache_flush' ) ) {
            wpo_cache_flush();
        }
        fussball_sync_log( sprintf( '[2.0] Datenumstellung: %d Spiele auf den Beitragstyp „Spiele“ umgestellt.', count( $migriert ) ) );
    }
    return array( 'zeilen' => $zeilen, 'anzahl' => count( $ids ), 'fehler' => $fehler );
}

/** Rückweg: umgestellte Beiträge wieder zu fixture_and_result machen, Sync wieder auf altes Schema. */
function fussball_sync_migration_v2_rueckgaengig() {
    global $wpdb;
    $m = get_option( 'fussball_sync_migration_v2' );
    $n = 0;
    foreach ( (array) ( $m['ids'] ?? array() ) as $id ) {
        $n += (int) $wpdb->update( $wpdb->posts, array( 'post_type' => 'fixture_and_result' ), array( 'ID' => (int) $id, 'post_type' => FUSSBALL_SPIEL_PT ) );
        clean_post_cache( (int) $id );
    }
    update_option( 'fussball_sync_schema', 1 );
    return $n;
}
