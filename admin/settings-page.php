<?php
/**
 * Admin Settings Page for Fussball.de Sync
 *
 * Settings stored:
 *   fussball_sync_url               – Full fussball.de team page URL (team-id + season extracted automatically)
 *   fussball_sync_interval          – WP-Cron schedule key
 *   fussball_sync_category          – result_category taxonomy slug for regular synced posts
 *   fussball_sync_friendly_category – result_category taxonomy slug for friendly games
 *                                     (competition name contains "freundschaftsspiel")
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
        'fussball_sync_url',
        array(
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default'           => '',
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
        'fussball_sync_category',
        array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        )
    );
    register_setting(
        'fussball_sync_options',
        'fussball_sync_friendly_category',
        array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
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
        'fussball_sync_url',
        __( 'fussball.de Team URL', 'fussball-sync' ),
        'fussball_sync_field_url',
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
        'fussball_sync_category',
        __( 'Result Category', 'fussball-sync' ),
        'fussball_sync_field_category',
        'fussball-sync',
        'fussball_sync_main'
    );
    add_settings_field(
        'fussball_sync_friendly_category',
        __( 'Friendly Games Category', 'fussball-sync' ),
        'fussball_sync_field_friendly_category',
        'fussball-sync',
        'fussball_sync_main'
    );
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

function fussball_sync_field_url() {
    $value = get_option( 'fussball_sync_url', '' );
    echo '<input type="url" id="fussball_sync_url" name="fussball_sync_url"'
       . ' value="' . esc_attr( $value ) . '"'
       . ' class="large-text" placeholder="https://www.fussball.de/mannschaft/…" />';
    echo '<p class="description">'
       . esc_html__( 'Paste the full fussball.de team page URL. The team-id and season will be extracted automatically. Example:', 'fussball-sync' )
       . '<br><code>https://www.fussball.de/mannschaft/sv-wittendorf-…/-/saison/2526/team-id/011MIB1NCS000000VTVG0001VTR8C1K7#!/</code>'
       . '</p>';
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

function fussball_sync_field_category() {
    $value = get_option( 'fussball_sync_category', '' );

    // Get available result_category terms
    $terms = get_terms( array(
        'taxonomy'   => 'result_category',
        'hide_empty' => false,
    ) );

    if ( is_wp_error( $terms ) || empty( $terms ) ) {
        // Fall through to text input if taxonomy not registered yet
        echo '<input type="text" id="fussball_sync_category" name="fussball_sync_category"'
           . ' value="' . esc_attr( $value ) . '" class="regular-text" />';
        echo '<p class="description">'
           . esc_html__( 'Enter the slug of the result_category to assign to synced matches. Leave empty to assign no category.', 'fussball-sync' )
           . '</p>';
        return;
    }

    echo '<select id="fussball_sync_category" name="fussball_sync_category">';
    echo '<option value="">' . esc_html__( '— None —', 'fussball-sync' ) . '</option>';
    foreach ( $terms as $term ) {
        echo '<option value="' . esc_attr( $term->slug ) . '"'
           . selected( $value, $term->slug, false ) . '>'
           . esc_html( $term->name ) . ' (' . esc_html( $term->slug ) . ')</option>';
    }
    echo '</select>';
    echo '<p class="description">'
       . esc_html__( 'Assign synced matches to this category so they appear in the correct widget / page builder output.', 'fussball-sync' )
       . '</p>';
}

function fussball_sync_field_friendly_category() {
    $value = get_option( 'fussball_sync_friendly_category', '' );

    $terms = get_terms( array(
        'taxonomy'   => 'result_category',
        'hide_empty' => false,
    ) );

    if ( is_wp_error( $terms ) || empty( $terms ) ) {
        echo '<input type="text" id="fussball_sync_friendly_category" name="fussball_sync_friendly_category"'
           . ' value="' . esc_attr( $value ) . '" class="regular-text" />';
        echo '<p class="description">'
           . esc_html__( 'Enter the slug of the result_category to assign to friendly games. Leave empty to use the default Result Category.', 'fussball-sync' )
           . '</p>';
        return;
    }

    echo '<select id="fussball_sync_friendly_category" name="fussball_sync_friendly_category">';
    echo '<option value="">' . esc_html__( '— Same as Result Category —', 'fussball-sync' ) . '</option>';
    foreach ( $terms as $term ) {
        echo '<option value="' . esc_attr( $term->slug ) . '"'
           . selected( $value, $term->slug, false ) . '>'
           . esc_html( $term->name ) . ' (' . esc_html( $term->slug ) . ')</option>';
    }
    echo '</select>';
    echo '<p class="description">'
       . esc_html__( 'Matches whose competition name contains "Freundschaftsspiel" are assigned to this category instead of the default one. Leave empty to use the default Result Category.', 'fussball-sync' )
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
    $url = get_option( 'fussball_sync_url', '' );
    if ( empty( $url ) ) {
        return;
    }

    $team_id = '';
    $season  = '';
    if ( preg_match( '/team-id\/([^\/\#\?]+)/i', $url, $tid ) ) {
        $team_id = $tid[1];
    }
    if ( preg_match( '/saison\/(\d+)/i', $url, $sai ) ) {
        $season = $sai[1];
    }

    if ( empty( $team_id ) || empty( $season ) ) {
        echo '<div class="notice notice-warning"><p>'
           . esc_html__( 'Could not extract team-id or season from the URL. Please check the configured URL.', 'fussball-sync' )
           . '</p></div>';
        return;
    }
    ?>
    <hr>
    <h2><?php esc_html_e( 'Detected Parameters', 'fussball-sync' ); ?></h2>
    <table class="widefat" style="max-width:600px;">
        <tr>
            <th><?php esc_html_e( 'Team ID', 'fussball-sync' ); ?></th>
            <td><code><?php echo esc_html( $team_id ); ?></code></td>
        </tr>
        <tr>
            <th><?php esc_html_e( 'Season', 'fussball-sync' ); ?></th>
            <td><code><?php echo esc_html( $season ); ?></code></td>
        </tr>
        <tr>
            <th><?php esc_html_e( 'Next scheduled sync', 'fussball-sync' ); ?></th>
            <td>
                <?php
                $next = wp_next_scheduled( 'fussball_sync_cron_event' );
                echo $next
                    ? esc_html( wp_date( 'Y-m-d H:i:s', $next ) )
                    : esc_html__( 'Not scheduled', 'fussball-sync' );
                ?>
            </td>
        </tr>
    </table>
    <?php
}
