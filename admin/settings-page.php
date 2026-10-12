<?php
/**
 * Admin Settings Page for Fussball.de Sync
 *
 * Settings stored:
 *   fussball_sync_teams             – List of teams, one row each:
 *                                     label, url (fussball.de team page, team-id + season extracted automatically),
 *                                     category (result_category slug for regular matches),
 *                                     friendly_category (slug for games whose competition contains "Freundschaftsspiel"),
 *                                     enabled ('1' / '0')
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
        __( 'Sync Configuration', 'fussball-sync' ),
        '__return_false',
        'fussball-sync'
    );

    add_settings_field(
        'fussball_sync_teams',
        __( 'Teams', 'fussball-sync' ),
        'fussball_sync_field_teams',
        'fussball-sync',
        'fussball_sync_main'
    );
    add_settings_field(
        'fussball_sync_interval',
        __( 'Sync Interval', 'fussball-sync' ),
        'fussball_sync_field_interval',
        'fussball-sync',
        'fussball_sync_main'
    );
    add_settings_field(
        'fussball_sync_season_start',
        __( 'Season Start Date', 'fussball-sync' ),
        'fussball_sync_field_season_start',
        'fussball-sync',
        'fussball_sync_main'
    );
    add_settings_field(
        'fussball_sync_season_end',
        __( 'Season End Date', 'fussball-sync' ),
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
 *
 * @param mixed $value
 * @return array
 */
function fussball_sync_sanitize_teams( $value ) {
    $out = array();
    if ( ! is_array( $value ) ) {
        return $out;
    }
    foreach ( $value as $row ) {
        if ( ! is_array( $row ) ) {
            continue;
        }
        $label = sanitize_text_field( $row['label'] ?? '' );
        $url   = esc_url_raw( trim( (string) ( $row['url'] ?? '' ) ) );
        if ( '' === $label && '' === $url ) {
            continue;
        }
        $out[] = array(
            'label'             => $label,
            'url'               => $url,
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

/**
 * <select> with all result_category terms (text input as fallback if the taxonomy is missing).
 */
function fussball_sync_category_select( $name, $value, $empty_label ) {
    $terms = get_terms( array(
        'taxonomy'   => 'result_category',
        'hide_empty' => false,
    ) );

    if ( is_wp_error( $terms ) || empty( $terms ) ) {
        return '<input type="text" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" class="regular-text" placeholder="slug" />';
    }

    $html = '<select name="' . esc_attr( $name ) . '" style="max-width:100%;">';
    $html .= '<option value="">' . esc_html( $empty_label ) . '</option>';
    foreach ( $terms as $term ) {
        $html .= '<option value="' . esc_attr( $term->slug ) . '"' . selected( $value, $term->slug, false ) . '>'
               . esc_html( $term->name ) . ' (' . esc_html( $term->slug ) . ')</option>';
    }
    return $html . '</select>';
}

function fussball_sync_field_teams() {
    $teams   = fussball_sync_get_teams( false );
    $teams[] = array( 'label' => '', 'url' => '', 'category' => '', 'friendly_category' => '', 'enabled' => '1' ); // empty row to add a team
    ?>
    <table class="widefat striped" style="max-width:1300px;">
        <thead>
            <tr>
                <th style="width:50px;"><?php esc_html_e( 'Active', 'fussball-sync' ); ?></th>
                <th style="width:160px;"><?php esc_html_e( 'Label', 'fussball-sync' ); ?></th>
                <th><?php esc_html_e( 'fussball.de Team URL', 'fussball-sync' ); ?></th>
                <th style="width:240px;"><?php esc_html_e( 'Result Category', 'fussball-sync' ); ?></th>
                <th style="width:240px;"><?php esc_html_e( 'Friendly Games Category', 'fussball-sync' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $teams as $i => $team ) :
            $n      = 'fussball_sync_teams[' . (int) $i . ']';
            $params = fussball_sync_parse_team_url( $team['url'] );
            ?>
            <tr>
                <td><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[enabled]" value="1" <?php checked( '1', (string) $team['enabled'] ); ?> /></td>
                <td><input type="text" name="<?php echo esc_attr( $n ); ?>[label]" value="<?php echo esc_attr( $team['label'] ); ?>" style="width:100%;" placeholder="<?php esc_attr_e( 'e.g. 2. Mannschaft', 'fussball-sync' ); ?>" /></td>
                <td>
                    <input type="url" name="<?php echo esc_attr( $n ); ?>[url]" value="<?php echo esc_attr( $team['url'] ); ?>" class="large-text" placeholder="https://www.fussball.de/mannschaft/…/-/saison/2627/team-id/…" />
                    <?php if ( $params ) : ?>
                        <p class="description">team-id <code><?php echo esc_html( $params['team_id'] ); ?></code> | season <code><?php echo esc_html( $params['season'] ); ?></code></p>
                    <?php elseif ( '' !== $team['url'] ) : ?>
                        <p class="description" style="color:#b32d2e;"><?php esc_html_e( 'Could not extract team-id / season from this URL.', 'fussball-sync' ); ?></p>
                    <?php endif; ?>
                </td>
                <td><?php echo fussball_sync_category_select( $n . '[category]', $team['category'], __( '— None —', 'fussball-sync' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                <td><?php echo fussball_sync_category_select( $n . '[friendly_category]', $team['friendly_category'], __( '— Same as Result Category —', 'fussball-sync' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="description">
        <?php esc_html_e( 'One row per team, every active row is synced on each run. Add a team by filling in the empty last row and saving; remove a team by clearing its label and URL. Each team writes its matches into its own categories, so a page builder element shows one team by filtering on that team\'s categories. Matches whose competition contains "Freundschaftsspiel" go into the friendly games category.', 'fussball-sync' ); ?>
    </p>
    <?php
}

function fussball_sync_field_interval() {
    $value = get_option( 'fussball_sync_interval', 'daily' );

    $options = array(
        'disabled'      => __( 'Disabled (manual only)', 'fussball-sync' ),
        'hourly'        => __( 'Every hour', 'fussball-sync' ),
        'every_6_hours' => __( 'Every 6 hours', 'fussball-sync' ),
        'every_12_hours'=> __( 'Every 12 hours', 'fussball-sync' ),
        'twicedaily'    => __( 'Twice a day', 'fussball-sync' ),
        'daily'         => __( 'Once a day (recommended)', 'fussball-sync' ),
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
               esc_html__( 'Next sync: %s', 'fussball-sync' ),
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
       . esc_html__( 'First day of the current season. Matches from fussball.de dated before this day are ignored during sync. Leave empty for no lower bound.', 'fussball-sync' )
       . '</p>';
}

function fussball_sync_field_season_end() {
    $value = get_option( 'fussball_sync_season_end', '' );
    echo '<input type="date" id="fussball_sync_season_end" name="fussball_sync_season_end"'
       . ' value="' . esc_attr( $value ) . '" />';
    echo '<p class="description">'
       . esc_html__( 'Last day of the current season. Matches from fussball.de dated after this day are ignored during sync. Leave empty for no upper bound.', 'fussball-sync' )
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
        <h1><?php esc_html_e( 'Fussball.de Sync', 'fussball-sync' ); ?></h1>
        <p><?php esc_html_e( 'Automatically sync match results from fussball.de into the Goodlayers Soccer plugin (Fixtures & Results post type).', 'fussball-sync' ); ?></p>

        <!-- ── Settings form ───────────────────────────────────────────── -->
        <form method="post" action="options.php">
            <?php
            settings_fields( 'fussball_sync_options' );
            do_settings_sections( 'fussball-sync' );
            submit_button( __( 'Save Settings', 'fussball-sync' ) );
            ?>
        </form>

        <!-- ── Manual sync ─────────────────────────────────────────────── -->
        <hr>
        <h2><?php esc_html_e( 'Manual Sync', 'fussball-sync' ); ?></h2>
        <p><?php esc_html_e( 'Click the button below to trigger an immediate sync regardless of the schedule.', 'fussball-sync' ); ?></p>

        <button id="fussball-sync-run" class="button button-primary">
            <?php esc_html_e( 'Run Sync Now', 'fussball-sync' ); ?>
        </button>
        <span id="fussball-sync-spinner" class="spinner" style="float:none; visibility:hidden; vertical-align:middle; margin-left:4px;"></span>

        <div id="fussball-sync-result" style="margin-top:16px; display:none;">
            <h3><?php esc_html_e( 'Sync Result', 'fussball-sync' ); ?></h3>
            <div id="fussball-sync-log" style="font-family:monospace; background:#f5f5f5; border:1px solid #ddd; padding:10px; max-height:300px; overflow-y:auto;"></div>
        </div>

        <!-- ── Sync log ────────────────────────────────────────────────── -->
        <hr>
        <h2><?php esc_html_e( 'Sync Log', 'fussball-sync' ); ?></h2>
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
                    alert('<?php esc_html_e( 'Sync failed. Check the sync log for details.', 'fussball-sync' ); ?>');
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
        echo '<p>' . esc_html__( 'No sync runs recorded yet.', 'fussball-sync' ) . '</p>';
        return;
    }

    $rows = array_reverse( $log ); // newest first
    ?>
    <table class="widefat striped" style="max-width:900px;">
        <thead>
            <tr>
                <th style="width:160px;"><?php esc_html_e( 'Time', 'fussball-sync' ); ?></th>
                <th><?php esc_html_e( 'Message', 'fussball-sync' ); ?></th>
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
            <?php esc_html_e( 'Clear Log', 'fussball-sync' ); ?>
        </a>
    </p>
    <?php

    // Handle log-clear action
    if ( isset( $_GET['fussball_clear_log'] )
         && check_admin_referer( 'fussball_clear_log' )
         && current_user_can( 'manage_options' ) ) {
        delete_option( 'fussball_sync_log' );
        echo '<p class="notice notice-success">' . esc_html__( 'Log cleared.', 'fussball-sync' ) . '</p>';
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
    <h2><?php esc_html_e( 'Detected Parameters', 'fussball-sync' ); ?></h2>
    <table class="widefat striped" style="max-width:900px;">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Team', 'fussball-sync' ); ?></th>
                <th><?php esc_html_e( 'Team ID', 'fussball-sync' ); ?></th>
                <th><?php esc_html_e( 'Season', 'fussball-sync' ); ?></th>
                <th><?php esc_html_e( 'Categories', 'fussball-sync' ); ?></th>
                <th><?php esc_html_e( 'Status', 'fussball-sync' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $teams as $team ) :
            $params = fussball_sync_parse_team_url( $team['url'] ); ?>
            <tr>
                <td><?php echo esc_html( $team['label'] ?: '—' ); ?></td>
                <td><code><?php echo esc_html( $params ? $params['team_id'] : '?' ); ?></code></td>
                <td><code><?php echo esc_html( $params ? $params['season'] : '?' ); ?></code></td>
                <td><code><?php echo esc_html( $team['category'] ?: '—' ); ?></code> / <code><?php echo esc_html( $team['friendly_category'] ?: '—' ); ?></code></td>
                <td><?php echo ( '1' === (string) $team['enabled'] && $params ) ? esc_html__( 'active', 'fussball-sync' ) : esc_html__( 'inactive', 'fussball-sync' ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p>
        <?php esc_html_e( 'Season date range:', 'fussball-sync' ); ?>
        <?php if ( empty( $range_start ) && empty( $range_end ) ) : ?>
            <?php esc_html_e( 'Not set (all matches are synced)', 'fussball-sync' ); ?>
        <?php else : ?>
            <code><?php echo esc_html( $range_start ?: '…' ); ?></code> – <code><?php echo esc_html( $range_end ?: '…' ); ?></code>
        <?php endif; ?>
        &nbsp;|&nbsp;
        <?php esc_html_e( 'Next scheduled sync:', 'fussball-sync' ); ?>
        <?php echo $next ? esc_html( wp_date( 'Y-m-d H:i:s', $next ) ) : esc_html__( 'Not scheduled', 'fussball-sync' ); ?>
    </p>
    <?php
}
