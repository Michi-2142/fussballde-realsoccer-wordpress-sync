<?php
/**
 * Admin Settings Page for Fussball.de Sync
 *
 * Settings stored:
 *   fussball_sync_teams             – List of teams, one row each:
 *                                     label (= Mannschaft), url (fussball.de team page, team-id + season extracted automatically),
 *                                     slug (Mannschafts-Slug, fest), liga (Liganame für die Anzeige, optional), enabled ('1' / '0');
 *                                     category / friendly_category aus 1.x bleiben unsichtbar gespeichert (Rückweg)
 *   fussball_sync_interval          – WP-Cron schedule key (all teams)
 *   fussball_sync_season_start      – First day of the current season (Y-m-d, empty = no lower bound, all teams)
 *   fussball_sync_season_end        – Last day of the current season (Y-m-d, empty = no upper bound, all teams)
 *
 * Legacy (version 1.0, single team): fussball_sync_url, fussball_sync_category, fussball_sync_friendly_category.
 * They are read as team 1 until fussball_sync_teams exists and are never overwritten by this page.
 */
defined( 'ABSPATH' ) || exit;

// ──────────────────────────────────────────────────────────────────────────────
// Register settings + menu
// ──────────────────────────────────────────────────────────────────────────────

add_action( 'admin_menu', 'fussball_sync_admin_menu' );
function fussball_sync_admin_menu() {
    add_options_page(
        __( 'Fussball.de Sync', 'fussball-sync' ),
        __( 'Fussball.de Sync', 'fussball-sync' ),
        'manage_options',
        'fussball-sync',
        'fussball_sync_settings_page'
    );
}

add_action( 'admin_init', 'fussball_sync_register_settings' );
function fussball_sync_register_settings() {
    register_setting(
        'fussball_sync_options',
        'fussball_sync_teams',
        array(
            'type'              => 'array',
            'sanitize_callback' => 'fussball_sync_sanitize_teams',
            'default'           => array(),
        )
    );
    register_setting(
        'fussball_sync_options',
        'fussball_sync_interval',
        array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'daily',
        )
    );
    register_setting(
        'fussball_sync_options',
        'fussball_sync_season_start',
        array(
            'type'              => 'string',
            'sanitize_callback' => 'fussball_sync_sanitize_date',
            'default'           => '',
        )
    );
    register_setting(
        'fussball_sync_options',
        'fussball_sync_season_end',
        array(
            'type'              => 'string',
            'sanitize_callback' => 'fussball_sync_sanitize_date',
            'default'           => '',
        )
    );

    add_settings_section(
        'fussball_sync_main',
        'Einstellungen',
        '__return_false',
        'fussball-sync'
    );

    add_settings_field(
        'fussball_sync_teams',
        'Mannschaften',
        'fussball_sync_field_teams',
        'fussball-sync',
        'fussball_sync_main'
    );
    add_settings_field(
        'fussball_sync_interval',
        'Intervall',
        'fussball_sync_field_interval',
        'fussball-sync',
        'fussball_sync_main'
    );
    add_settings_field(
        'fussball_sync_season_start',
        'Saisonbeginn',
        'fussball_sync_field_season_start',
        'fussball-sync',
        'fussball_sync_main'
    );
    add_settings_field(
        'fussball_sync_season_end',
        'Saisonende',
        'fussball_sync_field_season_end',
        'fussball-sync',
        'fussball_sync_main'
    );
}

/**
 * Sanitize a date setting: accepts an empty string or a valid Y-m-d date.
 * Anything else is discarded (returns empty string).
 *
 * @param string $value
 * @return string
 */
function fussball_sync_sanitize_date( $value ) {
    $value = trim( sanitize_text_field( $value ) );
    if ( $value === '' ) {
        return '';
    }
    $dt = DateTime::createFromFormat( 'Y-m-d', $value );
    if ( $dt && $dt->format( 'Y-m-d' ) === $value ) {
        return $value;
    }
    return '';
}

/**
 * Sanitize the team list. Rows without label and URL are dropped (= the empty "add" row or a removed team).
 * Der Slug einer Mannschaft bleibt fest; neue Zeilen bekommen ihn aus der Bezeichnung (eindeutig gemacht).
 *
 * @param mixed $value
 * @return array
 */
function fussball_sync_sanitize_teams( $value ) {
    $out = array();
    if ( ! is_array( $value ) ) {
        return $out;
    }
    $slugs = array();
    foreach ( $value as $row ) {
        if ( ! is_array( $row ) ) {
            continue;
        }
        $label = sanitize_text_field( $row['label'] ?? '' );
        $url   = esc_url_raw( trim( (string) ( $row['url'] ?? '' ) ) );
        if ( '' === $label && '' === $url ) {
            continue;
        }
        $slug = sanitize_title( $row['slug'] ?? '' );
        if ( '' === $slug ) {
            $slug = sanitize_title( $label ) ?: 'mannschaft';
            $base = $slug;
            for ( $n = 2; in_array( $slug, $slugs, true ); $n++ ) {
                $slug = $base . '-' . $n;
            }
        }
        $slugs[] = $slug;
        $out[]   = array(
            'label'             => $label,
            'url'               => $url,
            'slug'              => $slug,
            'liga'              => sanitize_text_field( $row['liga'] ?? '' ),
            'category'          => sanitize_title( $row['category'] ?? '' ),
            'friendly_category' => sanitize_title( $row['friendly_category'] ?? '' ),
            'enabled'           => empty( $row['enabled'] ) ? '0' : '1',
        );
    }
    return $out;
}

// ──────────────────────────────────────────────────────────────────────────────
// Reschedule cron when interval setting changes
// ──────────────────────────────────────────────────────────────────────────────

add_action( 'update_option_fussball_sync_interval', 'fussball_sync_on_interval_change', 10, 2 );
function fussball_sync_on_interval_change( $old_value, $new_value ) {
    if ( $old_value !== $new_value ) {
        fussball_sync_reschedule_cron();
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// Field renderers
// ──────────────────────────────────────────────────────────────────────────────

function fussball_sync_field_teams() {
    $teams   = fussball_sync_get_teams( false );
    $teams[] = array( 'label' => '', 'url' => '', 'slug' => '', 'liga' => '', 'category' => '', 'friendly_category' => '', 'enabled' => '1' ); // leere Zeile zum Hinzufügen
    ?>
    <table class="widefat striped" style="max-width:1300px;">
        <thead>
            <tr>
                <th style="width:50px;">Aktiv</th>
                <th style="width:180px;">Mannschaft</th>
                <th>Teamseite bei fussball.de</th>
                <th style="width:200px;">Liga (Anzeige)</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $teams as $i => $team ) :
            $n      = 'fussball_sync_teams[' . (int) $i . ']';
            $params = fussball_sync_parse_team_url( $team['url'] );
            $slug   = '' !== $team['label'] || '' !== $team['url'] ? fussball_sync_team_slug( $team ) : '';
            ?>
            <tr>
                <td><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[enabled]" value="1" <?php checked( '1', (string) $team['enabled'] ); ?> /></td>
                <td>
                    <input type="text" name="<?php echo esc_attr( $n ); ?>[label]" value="<?php echo esc_attr( $team['label'] ); ?>" style="width:100%;" placeholder="z. B. 2. Mannschaft" />
                    <input type="hidden" name="<?php echo esc_attr( $n ); ?>[slug]" value="<?php echo esc_attr( $team['slug'] ?? '' ); ?>" />
                    <input type="hidden" name="<?php echo esc_attr( $n ); ?>[category]" value="<?php echo esc_attr( $team['category'] ?? '' ); ?>" />
                    <input type="hidden" name="<?php echo esc_attr( $n ); ?>[friendly_category]" value="<?php echo esc_attr( $team['friendly_category'] ?? '' ); ?>" />
                    <?php if ( $slug ) : ?><p class="description">Kürzel <code><?php echo esc_html( $slug ); ?></code></p><?php endif; ?>
                </td>
                <td>
                    <input type="url" name="<?php echo esc_attr( $n ); ?>[url]" value="<?php echo esc_attr( $team['url'] ); ?>" class="large-text" placeholder="https://www.fussball.de/mannschaft/…/-/saison/2627/team-id/…" />
                    <?php if ( $params ) : ?>
                        <p class="description">team-id <code><?php echo esc_html( $params['team_id'] ); ?></code> | Saison <code><?php echo esc_html( $params['season'] ); ?></code></p>
                    <?php elseif ( '' !== $team['url'] ) : ?>
                        <p class="description" style="color:#b32d2e;">Aus dieser Adresse lassen sich team-id und Saison nicht lesen.</p>
                    <?php endif; ?>
                </td>
                <td><input type="text" name="<?php echo esc_attr( $n ); ?>[liga]" value="<?php echo esc_attr( $team['liga'] ?? '' ); ?>" style="width:100%;" placeholder="z. B. Kreisliga B3" /></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="description">
        Eine Zeile je Mannschaft, jede aktive Zeile wird bei jedem Lauf abgeglichen. Mannschaft hinzufügen: letzte leere Zeile ausfüllen und speichern; entfernen: Bezeichnung und Adresse leeren.
        Die Spiele landen unter „Spiele“ mit der Mannschaft als Zuordnung. „Liga (Anzeige)“ ersetzt bei Ligaspielen den Wettbewerbsnamen von fussball.de, z. B. „Kreisliga B3“ statt „Kreisliga B“. Neue Saison: nur die Adresse auf die neue Saison umstellen.
    </p>
    <?php
}

function fussball_sync_field_interval() {
    $value = get_option( 'fussball_sync_interval', 'daily' );

    $options = array(
        'disabled'      => 'Aus (nur von Hand)',
        'hourly'        => 'Stündlich',
        'every_6_hours' => 'Alle 6 Stunden',
        'every_12_hours'=> 'Alle 12 Stunden',
        'twicedaily'    => 'Zweimal täglich',
        'daily'         => 'Einmal täglich',
    );

    echo '<select id="fussball_sync_interval" name="fussball_sync_interval">';
    foreach ( $options as $key => $label ) {
        echo '<option value="' . esc_attr( $key ) . '"'
           . selected( $value, $key, false ) . '>'
           . esc_html( $label ) . '</option>';
    }
    echo '</select>';

    $next = wp_next_scheduled( 'fussball_sync_cron_event' );
    if ( $next ) {
        echo '<p class="description">'
           . sprintf(
               /* translators: %s = human-readable time */
               'Nächster Lauf: %s',
               esc_html( human_time_diff( $next, current_time( 'timestamp' ) ) . ' (' . wp_date( 'Y-m-d H:i:s', $next ) . ')' )
             )
           . '</p>';
    }
}

function fussball_sync_field_season_start() {
    $value = get_option( 'fussball_sync_season_start', '' );
    echo '<input type="date" id="fussball_sync_season_start" name="fussball_sync_season_start"'
       . ' value="' . esc_attr( $value ) . '" />';
    echo '<p class="description">'
       . 'Erster Tag der laufenden Saison. Spiele davor ignoriert der Sync. Leer = keine Grenze.'
       . '</p>';
}

function fussball_sync_field_season_end() {
    $value = get_option( 'fussball_sync_season_end', '' );
    echo '<input type="date" id="fussball_sync_season_end" name="fussball_sync_season_end"'
       . ' value="' . esc_attr( $value ) . '" />';
    echo '<p class="description">'
       . 'Letzter Tag der laufenden Saison. Spiele danach ignoriert der Sync. Leer = keine Grenze.'
       . '</p>';
}

// ──────────────────────────────────────────────────────────────────────────────
// Settings page
// ──────────────────────────────────────────────────────────────────────────────

function fussball_sync_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    ?>
    <div class="wrap">
        <h1>Fussball.de Sync</h1>
        <p>Holt Spiele und Ergebnisse von fussball.de in den Beitragstyp <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . FUSSBALL_SPIEL_PT ) ); ?>">„Spiele“</a>. Version <?php echo esc_html( FUSSBALL_SYNC_VERSION ); ?>.</p>
        <?php if ( fussball_sync_schema() < 2 ) : ?>
            <div class="notice notice-warning inline"><p>Die Spiele liegen noch im alten Format von Goodlayers Soccer. Bis zur Umstellung setzt der Sync aus.</p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'fussball_sync_migrieren' ); ?><input type="hidden" name="action" value="fussball_sync_migrieren"><p><button class="button button-primary">Jetzt umstellen</button></p></form>
            </div>
        <?php endif; ?>
        <?php if ( isset( $_GET['fussball_migriert'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
            <div class="notice notice-success inline"><p><?php echo esc_html( sprintf( 'Umstellung erledigt: %d Spiele.', (int) $_GET['fussball_migriert'] ) ); // phpcs:ignore WordPress.Security.NonceVerification ?></p></div>
        <?php endif; ?>

        <!-- ── Settings form ───────────────────────────────────────────── -->
        <form method="post" action="options.php">
            <?php
            settings_fields( 'fussball_sync_options' );
            do_settings_sections( 'fussball-sync' );
            submit_button( 'Einstellungen speichern' );
            ?>
        </form>

        <!-- ── Manual sync ─────────────────────────────────────────────── -->
        <hr>
        <h2>Von Hand abgleichen</h2>
        <p>Startet sofort einen Abgleich aller aktiven Mannschaften, unabhängig vom Intervall.</p>

        <button id="fussball-sync-run" class="button button-primary">Jetzt abgleichen</button>
        <span id="fussball-sync-spinner" class="spinner" style="float:none; visibility:hidden; vertical-align:middle; margin-left:4px;"></span>

        <div id="fussball-sync-result" style="margin-top:16px; display:none;">
            <h3>Ergebnis</h3>
            <div id="fussball-sync-log" style="font-family:monospace; background:#f5f5f5; border:1px solid #ddd; padding:10px; max-height:300px; overflow-y:auto;"></div>
        </div>

        <!-- ── Sync log ────────────────────────────────────────────────── -->
        <hr>
        <h2>Protokoll</h2>
        <?php fussball_sync_render_log(); ?>

        <!-- ── Debug info ──────────────────────────────────────────────── -->
        <?php fussball_sync_render_debug_info(); ?>
    </div>

    <script>
    (function($){
        $('#fussball-sync-run').on('click', function(e){
            e.preventDefault();

            var $btn     = $(this);
            var $spinner = $('#fussball-sync-spinner');
            var $result  = $('#fussball-sync-result');
            var $log     = $('#fussball-sync-log');

            $btn.prop('disabled', true);
            $spinner.css('visibility', 'visible');
            $result.hide();

            $.post(ajaxurl, {
                action: 'fussball_sync_manual',
                nonce:  '<?php echo esc_js( wp_create_nonce( 'fussball_sync_nonce' ) ); ?>'
            }, function(response){
                $btn.prop('disabled', false);
                $spinner.css('visibility', 'hidden');

                if ( response.success && response.data.log ) {
                    var html = '';
                    $.each(response.data.log, function(i, entry){
                        html += '<div><strong>' + entry.time + '</strong>  ' + entry.msg + '</div>';
                    });
                    $log.html(html);
                    $result.show();
                    $log.scrollTop($log[0].scrollHeight);
                } else {
                    alert('Abgleich fehlgeschlagen. Details im Protokoll.');
                }
                // Reload page to refresh the persistent log table
                setTimeout(function(){ location.reload(); }, 2500);
            });
        });
    })(jQuery);
    </script>
    <?php
}

// ──────────────────────────────────────────────────────────────────────────────
// Render helpers
// ──────────────────────────────────────────────────────────────────────────────

function fussball_sync_render_log() {
    $log = get_option( 'fussball_sync_log', array() );

    if ( empty( $log ) ) {
        echo '<p>Noch keine Läufe protokolliert.</p>';
        return;
    }

    $rows = array_reverse( $log ); // newest first
    ?>
    <table class="widefat striped" style="max-width:900px;">
        <thead>
            <tr>
                <th style="width:160px;">Zeit</th>
                <th>Meldung</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( array_slice( $rows, 0, 50 ) as $entry ) : ?>
            <tr>
                <td><?php echo esc_html( $entry['time'] ); ?></td>
                <td><?php echo esc_html( $entry['msg'] ); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <p>
        <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'fussball_clear_log', '1' ), 'fussball_clear_log' ) ); ?>" class="button button-secondary">
            Protokoll leeren
        </a>
    </p>
    <?php

    // Handle log-clear action
    if ( isset( $_GET['fussball_clear_log'] )
         && check_admin_referer( 'fussball_clear_log' )
         && current_user_can( 'manage_options' ) ) {
        delete_option( 'fussball_sync_log' );
        echo '<p class="notice notice-success">Protokoll geleert.</p>';
    }
}

function fussball_sync_render_debug_info() {
    $teams = fussball_sync_get_teams( false );
    if ( empty( $teams ) ) {
        return;
    }
    $range_start = get_option( 'fussball_sync_season_start', '' );
    $range_end   = get_option( 'fussball_sync_season_end', '' );
    $next        = wp_next_scheduled( 'fussball_sync_cron_event' );
    ?>
    <hr>
    <h2>Erkannte Werte</h2>
    <table class="widefat striped" style="max-width:900px;">
        <thead>
            <tr><th>Mannschaft</th><th>Team-ID</th><th>Saison</th><th>Kürzel</th><th>Spiele</th><th>Status</th></tr>
        </thead>
        <tbody>
        <?php foreach ( $teams as $team ) :
            $params = fussball_sync_parse_team_url( $team['url'] );
            $slug   = fussball_sync_team_slug( $team );
            $term   = $slug && taxonomy_exists( FUSSBALL_TEAM_TAX ) ? get_term_by( 'slug', $slug, FUSSBALL_TEAM_TAX ) : null; ?>
            <tr>
                <td><?php echo esc_html( $team['label'] ?: '—' ); ?></td>
                <td><code><?php echo esc_html( $params ? $params['team_id'] : '?' ); ?></code></td>
                <td><code><?php echo esc_html( $params ? $params['season'] : '?' ); ?></code></td>
                <td><code><?php echo esc_html( $slug ?: '—' ); ?></code></td>
                <td><?php echo $term ? '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . FUSSBALL_SPIEL_PT . '&' . FUSSBALL_TEAM_TAX . '=' . $slug ) ) . '">' . (int) $term->count . '</a>' : '0'; ?></td>
                <td><?php echo ( '1' === (string) $team['enabled'] && $params ) ? 'aktiv' : 'inaktiv'; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p>
        Saisonzeitraum:
        <?php if ( empty( $range_start ) && empty( $range_end ) ) : ?>
            nicht gesetzt (alle Spiele)
        <?php else : ?>
            <code><?php echo esc_html( $range_start ?: '…' ); ?></code> – <code><?php echo esc_html( $range_end ?: '…' ); ?></code>
        <?php endif; ?>
        &nbsp;|&nbsp;
        Nächster geplanter Lauf:
        <?php echo $next ? esc_html( wp_date( 'Y-m-d H:i:s', $next ) ) : 'nicht geplant'; ?>
    </p>
    <?php
}

// ──────────────────────────────────────────────────────────────────────────────
// Umstellung auf 2.0 per Knopf (Einstellungsseite)
// ──────────────────────────────────────────────────────────────────────────────
add_action( 'admin_post_fussball_sync_migrieren', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Keine Berechtigung' );
    }
    check_admin_referer( 'fussball_sync_migrieren' );
    $r = fussball_sync_migration_v2( false );
    wp_safe_redirect( add_query_arg( 'fussball_migriert', (int) $r['anzahl'], admin_url( 'options-general.php?page=fussball-sync' ) ) );
    exit;
} );
