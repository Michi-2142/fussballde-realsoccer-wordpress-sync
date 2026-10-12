<?php
/**
 * Datenmodell „Spiele“ (seit 2.0.0): eigener Beitragstyp und eigene Felder, unabhängig von Theme und Goodlayers Soccer.
 *
 * Beitragstyp fussball_spiel: nur im Backend (Menü „Spiele“), keine eigenen Seiten auf der Website.
 * Taxonomie fussball_mannschaft: eine Mannschaft je Spiel, Begriffe legt der Sync aus den Teams in den Einstellungen an.
 * Felder (Beitrags-Meta, flach, mit Unterstrich, damit sie nicht unter „Individuelle Felder“ auftauchen):
 *   _fussball_heim, _fussball_gast                Teamnamen wie bei fussball.de
 *   _fussball_tore_heim, _fussball_tore_gast      Tore; fehlt das Feld, gibt es noch kein Ergebnis
 *   _fussball_datum (Y-m-d), _fussball_zeit (H:i) Anstoß; _fussball_anstoss = „Y-m-d H:i“ zum Sortieren (wird mitgeschrieben)
 *   _fussball_wettbewerb                          z. B. „Bezirksliga“, „Bezirkspokal“, „Bezirksfreundschaftsspiele“
 *   _fussball_saison                              z. B. „2627“ (Saison Juli bis Juni)
 *   _fussball_wappen_heim, _fussball_wappen_gast  Anhang-ID aus der Mediathek oder Bild-URL
 *   _fussball_ort                                 optional, nur von Hand
 *   _fussball_spiel_id                            Kennung bei fussball.de (oder pseudo_…), leer bei Spielen von Hand
 *
 * Lesen:     fussball_spiel( $id ), fussball_spiele_get( $args ), fussball_wappen_url( $wert ),
 *            fussball_spiel_wettbewerb_anzeige( $spiel )
 * Schreiben: fussball_spiel_speichern( $id, $daten ) (nur übergebene Felder), fussball_spiel_titel_setzen( $id )
 */
defined( 'ABSPATH' ) || exit;

const FUSSBALL_SPIEL_PT = 'fussball_spiel';
const FUSSBALL_TEAM_TAX = 'fussball_mannschaft';

/** Gespeicherte Felder (ohne Präfix _fussball_). */
function fussball_spiel_felder() {
    return array( 'heim', 'gast', 'tore_heim', 'tore_gast', 'datum', 'zeit', 'wettbewerb', 'saison', 'wappen_heim', 'wappen_gast', 'ort', 'spiel_id' );
}

// ──────────────────────────────────────────────────────────────────────────────
// Registrierung
// ──────────────────────────────────────────────────────────────────────────────
add_action( 'init', 'fussball_spiele_registrieren', 5 );
function fussball_spiele_registrieren() {
    register_post_type( FUSSBALL_SPIEL_PT, array(
        'labels' => array(
            'name'               => 'Spiele',
            'singular_name'      => 'Spiel',
            'menu_name'          => 'Spiele',
            'all_items'          => 'Alle Spiele',
            'add_new'            => 'Neues Spiel',
            'add_new_item'       => 'Neues Spiel anlegen',
            'edit_item'          => 'Spiel bearbeiten',
            'new_item'           => 'Neues Spiel',
            'search_items'       => 'Spiele durchsuchen',
            'not_found'          => 'Keine Spiele gefunden.',
            'not_found_in_trash' => 'Keine Spiele im Papierkorb.',
        ),
        'description'         => 'Spiele und Ergebnisse, vom Fussball.de Sync gepflegt',
        'public'              => false,
        'show_ui'             => true,
        'show_in_menu'        => true,
        'show_in_nav_menus'   => false,
        'show_in_admin_bar'   => false,
        'menu_position'       => 26,
        'menu_icon'           => 'dashicons-awards',
        'supports'            => false,
        'has_archive'         => false,
        'rewrite'             => false,
        'query_var'           => false,
        'exclude_from_search' => true,
        'publicly_queryable'  => false,
        'show_in_rest'        => false,
        'capability_type'     => 'post',
    ) );
    register_taxonomy( FUSSBALL_TEAM_TAX, FUSSBALL_SPIEL_PT, array(
        'labels' => array(
            'name'          => 'Mannschaften',
            'singular_name' => 'Mannschaft',
            'menu_name'     => 'Mannschaften',
            'all_items'     => 'Alle Mannschaften',
            'edit_item'     => 'Mannschaft bearbeiten',
            'add_new_item'  => 'Neue Mannschaft',
            'search_items'  => 'Mannschaften durchsuchen',
            'not_found'     => 'Keine Mannschaften gefunden.',
        ),
        'public'            => false,
        'show_ui'           => true,
        'show_admin_column' => true,
        'show_in_nav_menus' => false,
        'show_tagcloud'     => false,
        'hierarchical'      => true, // Auswahl als Häkchen-Liste im Spiel
        'rewrite'           => false,
        'query_var'         => false,
        'show_in_rest'      => false,
    ) );
}

// ──────────────────────────────────────────────────────────────────────────────
// Lesen
// ──────────────────────────────────────────────────────────────────────────────

/** Ein Spiel als Array: alle Felder plus mannschaft (Slug), mannschaft_name, gespielt, art, ts (Ortszeit). */
function fussball_spiel( $post_id ) {
    $post_id = (int) $post_id;
    $d       = array( 'id' => $post_id );
    foreach ( fussball_spiel_felder() as $f ) {
        $d[ $f ] = (string) get_post_meta( $post_id, '_fussball_' . $f, true );
    }
    $d['anstoss']         = (string) get_post_meta( $post_id, '_fussball_anstoss', true );
    $d['gespielt']        = ( '' !== $d['tore_heim'] && '' !== $d['tore_gast'] );
    $terms                = get_the_terms( $post_id, FUSSBALL_TEAM_TAX );
    $d['mannschaft']      = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0]->slug : '';
    $d['mannschaft_name'] = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0]->name : '';
    $d['art']             = fussball_spiel_art( $d['wettbewerb'] );
    $d['ts']              = $d['datum'] ? (int) strtotime( $d['datum'] . ' ' . ( $d['zeit'] ? $d['zeit'] : '00:00' ) ) : 0;
    return $d;
}

/** Art des Wettbewerbs: freundschaft, pokal oder liga. */
function fussball_spiel_art( $wettbewerb ) {
    $w = mb_strtolower( (string) $wettbewerb, 'UTF-8' );
    if ( false !== strpos( $w, 'freundschaft' ) ) {
        return 'freundschaft';
    }
    if ( false !== strpos( $w, 'pokal' ) ) {
        return 'pokal';
    }
    return 'liga';
}

/** Saison aus dem Datum (Juli bis Juni): 2026-10-11 → „2627“, 2027-03-14 → „2627“. */
function fussball_saison_aus_datum( $datum ) {
    if ( ! preg_match( '/^(\d{4})-(\d{2})-\d{2}$/', (string) $datum, $m ) ) {
        return '';
    }
    $start = (int) $m[2] >= 7 ? (int) $m[1] : (int) $m[1] - 1;
    return sprintf( '%02d%02d', $start % 100, ( $start + 1 ) % 100 );
}

/**
 * Spiele abfragen.
 * $args: mannschaft (Slug, Liste oder kommagetrennt; leer = alle), zeige (anstehend | vergangene | alle),
 *        anzahl (0 oder -1 = alle), saison („2627“, leer = alle; „aktuell“ = Saison aus der fussball.de-Adresse der Mannschaft).
 * Sortierung nach Anstoß: anstehend und alle aufsteigend, vergangene absteigend.
 */
function fussball_spiele_get( $args = array() ) {
    $a     = wp_parse_args( $args, array( 'mannschaft' => '', 'zeige' => 'alle', 'anzahl' => -1, 'saison' => '' ) );
    $jetzt = current_time( 'Y-m-d H:i' );
    $q     = array(
        'post_type'        => FUSSBALL_SPIEL_PT,
        'post_status'      => 'publish',
        'posts_per_page'   => (int) $a['anzahl'] > 0 ? (int) $a['anzahl'] : -1,
        'meta_key'         => '_fussball_anstoss',
        'orderby'          => 'meta_value',
        'order'            => 'vergangene' === $a['zeige'] ? 'DESC' : 'ASC',
        'fields'           => 'ids',
        'no_found_rows'    => true,
        'suppress_filters' => true,
    );
    if ( 'anstehend' === $a['zeige'] ) {
        $q['meta_query'] = array( array( 'key' => '_fussball_anstoss', 'value' => $jetzt, 'compare' => '>' ) );
    } elseif ( 'vergangene' === $a['zeige'] ) {
        $q['meta_query'] = array( array( 'key' => '_fussball_anstoss', 'value' => $jetzt, 'compare' => '<=' ) );
    }
    $teams = is_array( $a['mannschaft'] ) ? $a['mannschaft'] : explode( ',', (string) $a['mannschaft'] );
    $teams = array_values( array_filter( array_map( 'trim', $teams ) ) );
    if ( $teams ) {
        $q['tax_query'] = array( array( 'taxonomy' => FUSSBALL_TEAM_TAX, 'field' => 'slug', 'terms' => $teams ) );
    }
    $saison = (string) $a['saison'];
    if ( 'aktuell' === $saison ) {
        $saison = 1 === count( $teams ) ? fussball_sync_aktuelle_saison( $teams[0] ) : '';
    }
    if ( '' !== $saison ) {
        $q['meta_query']   = isset( $q['meta_query'] ) ? $q['meta_query'] : array();
        $q['meta_query'][] = array( 'key' => '_fussball_saison', 'value' => $saison );
    }
    return array_map( 'fussball_spiel', get_posts( $q ) );
}

/** Laufende Saison einer Mannschaft: aus ihrer fussball.de-Adresse in den Einstellungen („…/saison/2627/…“ → „2627“). */
function fussball_sync_aktuelle_saison( $team_slug ) {
    $team   = fussball_sync_team_by_slug( $team_slug );
    $params = $team ? fussball_sync_parse_team_url( $team['url'] ) : null;
    return $params ? (string) $params['season'] : '';
}

/** Bild-URL eines Wappens (Anhang-ID oder URL), leer wenn keins. */
function fussball_wappen_url( $wert, $groesse = 'thumbnail' ) {
    $wert = trim( (string) $wert );
    if ( '' === $wert ) {
        return '';
    }
    return ctype_digit( $wert ) ? (string) wp_get_attachment_image_url( (int) $wert, $groesse ) : $wert;
}

/**
 * Wettbewerb für die Anzeige: Ligaspiele mit dem Liganamen der Mannschaft aus den Einstellungen (z. B. „Kreisliga B3“),
 * Liga und Pokal mit Saison („Bezirksliga 26/27“), Freundschaftsspiele als „Freundschaftsspiel“.
 */
function fussball_spiel_wettbewerb_anzeige( array $spiel ) {
    if ( 'freundschaft' === $spiel['art'] ) {
        return 'Freundschaftsspiel';
    }
    $name = $spiel['wettbewerb'];
    if ( 'liga' === $spiel['art'] ) {
        $team = fussball_sync_team_by_slug( $spiel['mannschaft'] );
        if ( $team && '' !== trim( (string) $team['liga'] ) ) {
            $name = $team['liga'];
        }
    }
    if ( preg_match( '/^(\d{2})(\d{2})$/', $spiel['saison'], $m ) ) {
        $name .= ' ' . $m[1] . '/' . $m[2];
    }
    return trim( $name );
}

// ──────────────────────────────────────────────────────────────────────────────
// Schreiben
// ──────────────────────────────────────────────────────────────────────────────

/** Übergebene Felder speichern (leerer Wert = Feld löschen), Anstoß zum Sortieren nachführen. */
function fussball_spiel_speichern( $post_id, array $daten ) {
    foreach ( fussball_spiel_felder() as $f ) {
        if ( ! array_key_exists( $f, $daten ) ) {
            continue;
        }
        $v = trim( (string) $daten[ $f ] );
        if ( '' === $v ) {
            delete_post_meta( $post_id, '_fussball_' . $f );
        } else {
            update_post_meta( $post_id, '_fussball_' . $f, wp_slash( $v ) );
        }
    }
    $datum = (string) get_post_meta( $post_id, '_fussball_datum', true );
    $zeit  = (string) get_post_meta( $post_id, '_fussball_zeit', true );
    if ( '' !== $datum ) {
        update_post_meta( $post_id, '_fussball_anstoss', $datum . ' ' . ( '' !== $zeit ? $zeit : '00:00' ) );
    } else {
        delete_post_meta( $post_id, '_fussball_anstoss' );
    }
}

/** Titel „Heim vs Gast (Datum)“ für die Liste im Backend; direkt in der Datenbank, ohne Speicher-Hooks erneut auszulösen. */
function fussball_spiel_titel_setzen( $post_id ) {
    global $wpdb;
    $s     = fussball_spiel( $post_id );
    $titel = sprintf( '%s vs %s (%s)', $s['heim'] ?: '?', $s['gast'] ?: '?', $s['datum'] ?: '?' );
    if ( get_post_field( 'post_title', $post_id ) !== $titel ) {
        $wpdb->update( $wpdb->posts, array( 'post_title' => $titel ), array( 'ID' => $post_id ) );
        clean_post_cache( $post_id );
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// Mannschaften (Begriffe aus den Teams der Einstellungen)
// ──────────────────────────────────────────────────────────────────────────────

/** Slug der Mannschaft eines Teams aus den Einstellungen (bleibt fest, auch wenn die Bezeichnung geändert wird). */
function fussball_sync_team_slug( array $team ) {
    return '' !== trim( (string) ( $team['slug'] ?? '' ) ) ? $team['slug'] : sanitize_title( $team['label'] ?? '' );
}

/** Team aus den Einstellungen zu einem Mannschafts-Slug (oder null). */
function fussball_sync_team_by_slug( $slug ) {
    if ( '' === (string) $slug ) {
        return null;
    }
    foreach ( fussball_sync_get_teams( false ) as $team ) {
        if ( fussball_sync_team_slug( $team ) === $slug ) {
            return $team;
        }
    }
    return null;
}

/** Für jedes Team den Mannschafts-Begriff anlegen bzw. den Namen nachziehen. */
function fussball_sync_mannschaften_anlegen() {
    if ( ! taxonomy_exists( FUSSBALL_TEAM_TAX ) ) {
        return;
    }
    foreach ( fussball_sync_get_teams( false ) as $team ) {
        $slug = fussball_sync_team_slug( $team );
        $name = '' !== trim( (string) $team['label'] ) ? $team['label'] : $slug;
        if ( '' === $slug ) {
            continue;
        }
        $term = get_term_by( 'slug', $slug, FUSSBALL_TEAM_TAX );
        if ( ! $term ) {
            wp_insert_term( $name, FUSSBALL_TEAM_TAX, array( 'slug' => $slug ) );
        } elseif ( $term->name !== $name ) {
            wp_update_term( $term->term_id, FUSSBALL_TEAM_TAX, array( 'name' => $name ) );
        }
    }
}
add_action( 'update_option_fussball_sync_teams', 'fussball_sync_mannschaften_anlegen' );
add_action( 'add_option_fussball_sync_teams', 'fussball_sync_mannschaften_anlegen' );

// ──────────────────────────────────────────────────────────────────────────────
// Backend: Eingabemaske und Spalten
// ──────────────────────────────────────────────────────────────────────────────
add_action( 'add_meta_boxes_' . FUSSBALL_SPIEL_PT, function () {
    add_meta_box( 'fussball-spiel', 'Spiel', 'fussball_spiel_metabox', FUSSBALL_SPIEL_PT, 'normal', 'high' );
} );

function fussball_spiel_metabox( $post ) {
    $s = fussball_spiel( $post->ID );
    wp_nonce_field( 'fussball_spiel_speichern', 'fussball_spiel_nonce' );
    $feld = function ( $name, $label, $wert, $typ = 'text', $extra = '' ) {
        echo '<p class="fs-feld"><label for="fs-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label>'
            . '<input type="' . esc_attr( $typ ) . '" id="fs-' . esc_attr( $name ) . '" name="fussball_spiel[' . esc_attr( $name ) . ']" value="' . esc_attr( $wert ) . '" ' . $extra . '></p>';
    };
    ?>
    <style>
        .fs-raster { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 4px 20px; max-width: 900px; }
        .fs-feld { margin: 0 0 10px; }
        .fs-feld label { display: block; font-weight: 600; margin-bottom: 4px; }
        .fs-feld input { width: 100%; }
        .fs-wappen { display: flex; align-items: center; gap: 8px; }
        .fs-wappen img { width: 40px; height: 40px; object-fit: contain; background: #f4f3f0; }
        .fs-hinweis { color: #646970; max-width: 900px; }
    </style>
    <div class="fs-raster">
        <?php
        $feld( 'heim', 'Heim', $s['heim'] );
        $feld( 'gast', 'Gast', $s['gast'] );
        $feld( 'tore_heim', 'Tore Heim', $s['tore_heim'], 'number', 'min="0" step="1"' );
        $feld( 'tore_gast', 'Tore Gast', $s['tore_gast'], 'number', 'min="0" step="1"' );
        $feld( 'datum', 'Datum', $s['datum'], 'date' );
        $feld( 'zeit', 'Anstoß', $s['zeit'], 'time' );
        $feld( 'wettbewerb', 'Wettbewerb', $s['wettbewerb'], 'text', 'placeholder="z. B. Bezirksliga"' );
        $feld( 'saison', 'Saison', $s['saison'], 'text', 'placeholder="z. B. 2627" pattern="\d{4}"' );
        $feld( 'ort', 'Ort', $s['ort'], 'text', 'placeholder="optional"' );
        foreach ( array( 'wappen_heim' => 'Wappen Heim', 'wappen_gast' => 'Wappen Gast' ) as $k => $l ) {
            $url = fussball_wappen_url( $s[ $k ] );
            echo '<div class="fs-feld"><label for="fs-' . esc_attr( $k ) . '">' . esc_html( $l ) . '</label><div class="fs-wappen">'
                . ( $url ? '<img src="' . esc_url( $url ) . '" alt="">' : '' )
                . '<input type="text" id="fs-' . esc_attr( $k ) . '" name="fussball_spiel[' . esc_attr( $k ) . ']" value="' . esc_attr( $s[ $k ] ) . '" placeholder="Anhang-ID oder Bild-URL">'
                . '<button type="button" class="button fs-wappen-waehlen" data-ziel="fs-' . esc_attr( $k ) . '">Mediathek</button></div></div>';
        }
        ?>
    </div>
    <p class="fs-hinweis">
        <?php if ( '' !== $s['spiel_id'] ) : ?>
            Kennung bei fussball.de: <code><?php echo esc_html( $s['spiel_id'] ); ?></code>. Der Sync gleicht dieses Spiel bei jedem Lauf ab und überschreibt Teams, Datum, Anstoß, Tore und Wettbewerb. Wappen und Ort bleiben.
        <?php else : ?>
            Von Hand angelegtes Spiel. Findet der Sync dasselbe Spiel bei fussball.de (gleiches Datum, gleiche Teams), übernimmt er es und gleicht es künftig ab.
        <?php endif; ?>
        Die Mannschaft wählst du rechts im Kasten „Mannschaften“.
    </p>
    <script>
    jQuery( function ( $ ) {
        $( '.fs-wappen-waehlen' ).on( 'click', function ( e ) {
            e.preventDefault();
            var ziel = $( '#' + $( this ).data( 'ziel' ) );
            var frame = wp.media( { title: 'Wappen wählen', library: { type: 'image' }, multiple: false, button: { text: 'Übernehmen' } } );
            frame.on( 'select', function () { ziel.val( frame.state().get( 'selection' ).first().id ); } );
            frame.open();
        } );
    } );
    </script>
    <?php
}

add_action( 'admin_enqueue_scripts', function ( $hook ) {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( $screen && FUSSBALL_SPIEL_PT === $screen->post_type && in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
        wp_enqueue_media();
    }
} );

add_action( 'save_post_' . FUSSBALL_SPIEL_PT, 'fussball_spiel_metabox_speichern', 10, 2 );
function fussball_spiel_metabox_speichern( $post_id, $post ) {
    if ( ! isset( $_POST['fussball_spiel_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['fussball_spiel_nonce'] ) ), 'fussball_spiel_speichern' ) ) {
        return;
    }
    if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }
    $in = isset( $_POST['fussball_spiel'] ) && is_array( $_POST['fussball_spiel'] ) ? wp_unslash( $_POST['fussball_spiel'] ) : array();
    $t  = function ( $k ) use ( $in ) { return isset( $in[ $k ] ) ? trim( sanitize_text_field( (string) $in[ $k ] ) ) : ''; };
    $tore  = function ( $v ) { return preg_match( '/^\d{1,2}$/', $v ) ? $v : ''; };
    $datum = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $t( 'datum' ) ) ? $t( 'datum' ) : '';
    $zeit  = preg_match( '/^\d{2}:\d{2}$/', $t( 'zeit' ) ) ? $t( 'zeit' ) : '';
    $wappen = function ( $v ) { return ( '' === $v || ctype_digit( $v ) ) ? $v : esc_url_raw( $v ); };
    $saison = preg_match( '/^\d{4}$/', $t( 'saison' ) ) ? $t( 'saison' ) : fussball_saison_aus_datum( $datum );
    fussball_spiel_speichern( $post_id, array(
        'heim'        => $t( 'heim' ),
        'gast'        => $t( 'gast' ),
        'tore_heim'   => $tore( $t( 'tore_heim' ) ),
        'tore_gast'   => $tore( $t( 'tore_gast' ) ),
        'datum'       => $datum,
        'zeit'        => $zeit,
        'wettbewerb'  => $t( 'wettbewerb' ),
        'saison'      => $saison,
        'ort'         => $t( 'ort' ),
        'wappen_heim' => $wappen( $t( 'wappen_heim' ) ),
        'wappen_gast' => $wappen( $t( 'wappen_gast' ) ),
    ) );
    fussball_spiel_titel_setzen( $post_id );
    if ( function_exists( 'wpo_cache_flush' ) ) {
        wpo_cache_flush();
    }
}

// Spalten der Liste: Anstoß, Paarung, Ergebnis, Wettbewerb, Quelle; Standard-Sortierung nach Anstoß, neueste zuerst
add_filter( 'manage_' . FUSSBALL_SPIEL_PT . '_posts_columns', function ( $cols ) {
    $neu = array( 'cb' => $cols['cb'] ?? '', 'title' => 'Paarung', 'fs_anstoss' => 'Anstoß', 'fs_ergebnis' => 'Ergebnis' );
    foreach ( $cols as $k => $v ) {
        if ( 0 === strpos( $k, 'taxonomy-' ) ) {
            $neu[ $k ] = $v;
        }
    }
    $neu['fs_wettbewerb'] = 'Wettbewerb';
    $neu['fs_quelle']     = 'Quelle';
    return $neu;
} );
add_action( 'manage_' . FUSSBALL_SPIEL_PT . '_posts_custom_column', function ( $col, $post_id ) {
    $s = fussball_spiel( $post_id );
    if ( 'fs_anstoss' === $col ) {
        echo esc_html( $s['ts'] ? date_i18n( 'D d.m.Y', $s['ts'] ) . ( $s['zeit'] ? ', ' . $s['zeit'] : '' ) : '' );
    } elseif ( 'fs_ergebnis' === $col ) {
        echo esc_html( $s['gespielt'] ? $s['tore_heim'] . ':' . $s['tore_gast'] : '' );
    } elseif ( 'fs_wettbewerb' === $col ) {
        echo esc_html( $s['wettbewerb'] );
    } elseif ( 'fs_quelle' === $col ) {
        echo esc_html( '' === $s['spiel_id'] ? 'von Hand' : 'fussball.de' );
    }
}, 10, 2 );
add_filter( 'manage_edit-' . FUSSBALL_SPIEL_PT . '_sortable_columns', function ( $cols ) {
    $cols['fs_anstoss'] = 'fs_anstoss';
    return $cols;
} );
add_action( 'pre_get_posts', function ( $q ) {
    if ( ! is_admin() || ! $q->is_main_query() || FUSSBALL_SPIEL_PT !== $q->get( 'post_type' ) ) {
        return;
    }
    $orderby = $q->get( 'orderby' );
    if ( ! $orderby || 'fs_anstoss' === $orderby ) {
        $q->set( 'meta_key', '_fussball_anstoss' );
        $q->set( 'orderby', 'meta_value' );
        if ( ! $q->get( 'order' ) || ! $orderby ) {
            $q->set( 'order', 'DESC' );
        }
    }
} );

// ──────────────────────────────────────────────────────────────────────────────
// Alte Adressen /fixture_and_result/<slug>/ (Goodlayers Soccer) auf die Mannschaftsseite umleiten
// ──────────────────────────────────────────────────────────────────────────────
add_action( 'template_redirect', 'fussball_spiele_alte_adressen', 1 );
function fussball_spiele_alte_adressen() {
    if ( ! is_404() ) {
        return;
    }
    $pfad  = trim( (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ), '/' );
    $basis = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
    if ( '' !== $basis && 0 === strpos( $pfad, $basis . '/' ) ) {
        $pfad = substr( $pfad, strlen( $basis ) + 1 );
    }
    if ( ! preg_match( '#^fixture_and_result/([^/]+)#', $pfad, $m ) ) {
        return;
    }
    $ziel = home_url( '/' );
    $ids  = get_posts( array( 'post_type' => FUSSBALL_SPIEL_PT, 'name' => sanitize_title( $m[1] ), 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'suppress_filters' => true ) );
    if ( $ids ) {
        $s     = fussball_spiel( $ids[0] );
        $seite = $s['mannschaft'] ? get_page_by_path( $s['mannschaft'] ) : null;
        if ( $seite ) {
            $ziel = get_permalink( $seite );
        }
    }
    wp_safe_redirect( $ziel, 301 );
    exit;
}
