<?php
/**
 * Plugin Name:  Fussball.de Sync
 * Plugin URI:   https://www.codeunity.de
 * Description:  Syncs match results from fussball.de into the Goodlayers Soccer plugin
 *               (Fixtures & Results custom post type). Supports several teams, each with
 *               its own result categories.
 * Version:      1.1.0
 * Author:       Michael Silzle
 * Author URI:   https://www.codeunity.de
 * Text Domain:  fussball-sync
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'FUSSBALL_SYNC_VERSION', '1.1.0' );
define( 'FUSSBALL_SYNC_FILE',    __FILE__ );
define( 'FUSSBALL_SYNC_DIR',     plugin_dir_path( __FILE__ ) );

// ──────────────────────────────────────────────────────────────────────────────
// Autoload includes
// ──────────────────────────────────────────────────────────────────────────────
require_once FUSSBALL_SYNC_DIR . 'includes/class-font-decoder.php';
require_once FUSSBALL_SYNC_DIR . 'includes/class-fussball-parser.php';
require_once FUSSBALL_SYNC_DIR . 'includes/class-sync-manager.php';
require_once FUSSBALL_SYNC_DIR . 'admin/settings-page.php';

// ──────────────────────────────────────────────────────────────────────────────
// Activation / Deactivation
// ──────────────────────────────────────────────────────────────────────────────
register_activation_hook( FUSSBALL_SYNC_FILE, 'fussball_sync_activate' );
register_deactivation_hook( FUSSBALL_SYNC_FILE, 'fussball_sync_deactivate' );

function fussball_sync_activate() {
    // Schedule cron job on activation
    fussball_sync_reschedule_cron();
}

function fussball_sync_deactivate() {
    // Clear scheduled cron job
    $timestamp = wp_next_scheduled( 'fussball_sync_cron_event' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'fussball_sync_cron_event' );
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// Custom cron intervals
// ──────────────────────────────────────────────────────────────────────────────
add_filter( 'cron_schedules', 'fussball_sync_add_cron_intervals' );
function fussball_sync_add_cron_intervals( $schedules ) {
    $schedules['every_6_hours'] = array(
        'interval' => 6 * HOUR_IN_SECONDS,
        'display'  => __( 'Every 6 Hours', 'fussball-sync' ),
    );
    $schedules['every_12_hours'] = array(
        'interval' => 12 * HOUR_IN_SECONDS,
        'display'  => __( 'Every 12 Hours', 'fussball-sync' ),
    );
    return $schedules;
}

// ──────────────────────────────────────────────────────────────────────────────
// Schedule helper  – call whenever the interval setting changes
// ──────────────────────────────────────────────────────────────────────────────
function fussball_sync_reschedule_cron() {
    // Remove any existing event first
    $timestamp = wp_next_scheduled( 'fussball_sync_cron_event' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'fussball_sync_cron_event' );
    }

    $interval = get_option( 'fussball_sync_interval', 'daily' );
    if ( $interval !== 'disabled' ) {
        wp_schedule_event( time(), $interval, 'fussball_sync_cron_event' );
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// Cron callback
// ──────────────────────────────────────────────────────────────────────────────
add_action( 'fussball_sync_cron_event', 'fussball_sync_run' );

/**
 * Configured teams.
 *
 * Stored in the option `fussball_sync_teams` as a list of
 *   [ 'label' => string, 'url' => string, 'category' => slug, 'friendly_category' => slug, 'enabled' => '1'|'0' ].
 * As long as that option does not exist, the single-team settings of version 1.0
 * (fussball_sync_url / _category / _friendly_category) are used as team 1.
 * Those legacy options are never modified, so downgrading to 1.0 keeps working.
 *
 * @param bool $active_only  Only enabled teams with a URL (for syncing). False = all rows (for the settings page).
 * @return array
 */
function fussball_sync_get_teams( $active_only = true ) {
    $teams = get_option( 'fussball_sync_teams', null );

    if ( ! is_array( $teams ) ) {
        $teams      = array();
        $legacy_url = get_option( 'fussball_sync_url', '' );
        if ( ! empty( $legacy_url ) ) {
            $teams[] = array(
                'label'             => '1. Mannschaft',
                'url'               => $legacy_url,
                'category'          => get_option( 'fussball_sync_category', '' ),
                'friendly_category' => get_option( 'fussball_sync_friendly_category', '' ),
                'enabled'           => '1',
            );
        }
    }

    $defaults = array(
        'label'             => '',
        'url'               => '',
        'category'          => '',
        'friendly_category' => '',
        'enabled'           => '1',
    );

    $out = array();
    foreach ( $teams as $team ) {
        if ( ! is_array( $team ) ) {
            continue;
        }
        $team = wp_parse_args( $team, $defaults );
        if ( $active_only && ( '1' !== (string) $team['enabled'] || '' === trim( (string) $team['url'] ) ) ) {
            continue;
        }
        $out[] = $team;
    }
    return $out;
}

/**
 * Extract team-id and season from a fussball.de team URL.
 *
 * @param string $url
 * @return array|null  [ 'team_id' => string, 'season' => string ] or null.
 */
function fussball_sync_parse_team_url( $url ) {
    if ( ! preg_match( '/team-id\/([^\/\#\?]+)/i', (string) $url, $tid ) ||
         ! preg_match( '/saison\/(\d+)/i', (string) $url, $sai ) ) {
        return null;
    }
    return array( 'team_id' => $tid[1], 'season' => $sai[1] );
}

/**
 * Cron / manual entry point: sync every active team one after another.
 */
function fussball_sync_run() {
    if ( function_exists( 'set_time_limit' ) ) {
        @set_time_limit( 300 );
    }

    $teams = fussball_sync_get_teams();
    if ( empty( $teams ) ) {
        fussball_sync_log( 'No active team configured. Sync skipped.' );
        return;
    }

    $season_start = get_option( 'fussball_sync_season_start', '' );
    $season_end   = get_option( 'fussball_sync_season_end', '' );

    foreach ( $teams as $team ) {
        fussball_sync_run_team( $team, $season_start, $season_end );
    }
}

/**
 * Sync a single team.
 *
 * @param array  $team          One entry of fussball_sync_get_teams().
 * @param string $season_start  Y-m-d or empty.
 * @param string $season_end    Y-m-d or empty.
 */
function fussball_sync_run_team( array $team, $season_start = '', $season_end = '' ) {
    $name   = '' !== trim( (string) $team['label'] ) ? $team['label'] : $team['url'];
    $params = fussball_sync_parse_team_url( $team['url'] );

    if ( ! $params ) {
        fussball_sync_log( sprintf( '[%s] Could not extract team-id / season from URL: %s', $name, $team['url'] ) );
        return;
    }

    fussball_sync_log( sprintf(
        '══ [%s] Starting sync: team-id=%s, season=%s, category=%s, friendly category=%s',
        $name,
        $params['team_id'],
        $params['season'],
        $team['category'] ?: '(none)',
        $team['friendly_category'] ?: '(same)'
    ) );

    try {
        $parser  = new Fussball_Parser( $params['team_id'], $params['season'] );
        $matches = $parser->get_all_matches();

        fussball_sync_log( sprintf( '[%s] Fetched %d matches from fussball.de.', $name, count( $matches ) ) );

        $manager = new Fussball_Sync_Manager( $team['category'], $team['friendly_category'], $season_start, $season_end );
        $stats   = $manager->sync_matches( $matches );

        fussball_sync_log( sprintf(
            '[%s] Sync complete. Created: %d | Score updated: %d | Details updated: %d | Cancelled (skipped): %d | Out of season (skipped): %d | No change: %d | Duplicates removed: %d.',
            $name,
            $stats['created'],
            $stats['updated_score'],
            $stats['updated_details'],
            $stats['cancelled'],
            $stats['out_of_season'],
            $stats['skipped'],
            $stats['duplicates_removed']
        ) );
    } catch ( Exception $e ) {
        fussball_sync_log( sprintf( '[%s] Sync error: %s', $name, $e->getMessage() ) );
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// Logging (kept lightweight – last 400 entries in a WP option)
// ──────────────────────────────────────────────────────────────────────────────
function fussball_sync_log( $message ) {
    $log = get_option( 'fussball_sync_log', array() );

    $log[] = array(
        'time' => current_time( 'Y-m-d H:i:s' ),
        'msg'  => $message,
    );

    // Keep only the last 400 entries (several teams, about two lines per match each)
    if ( count( $log ) > 400 ) {
        $log = array_slice( $log, -400 );
    }

    update_option( 'fussball_sync_log', $log, false );
}

// ──────────────────────────────────────────────────────────────────────────────
// Manual sync AJAX endpoint (admin only)
// ──────────────────────────────────────────────────────────────────────────────
add_action( 'wp_ajax_fussball_sync_manual', 'fussball_sync_manual_ajax' );
function fussball_sync_manual_ajax() {
    check_ajax_referer( 'fussball_sync_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorized' );
    }

    fussball_sync_run();

    $log = get_option( 'fussball_sync_log', array() );
    wp_send_json_success( array( 'log' => array_slice( $log, -40 ) ) );
}
