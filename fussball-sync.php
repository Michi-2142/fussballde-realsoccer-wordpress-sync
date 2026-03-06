<?php
/**
 * Plugin Name:  Fussball.de Sync
 * Plugin URI:   https://www.codeunity.de
 * Description:  Syncs match results from fussball.de into the Goodlayers Soccer plugin
 *               (Fixtures & Results custom post type).
 * Version:      1.0.0
 * Author:       Michael Silzle
 * Author URI:   https://www.codeunity.de
 * Text Domain:  fussball-sync
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'FUSSBALL_SYNC_VERSION', '1.0.0' );
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

function fussball_sync_run() {
    $url = get_option( 'fussball_sync_url', '' );
    if ( empty( $url ) ) {
        fussball_sync_log( 'No fussball.de URL configured. Sync skipped.' );
        return;
    }

    // Extract team-id and season from the configured URL
    if ( ! preg_match( '/team-id\/([^\/\#\?]+)/i', $url, $tid ) ||
         ! preg_match( '/saison\/(\d+)/i', $url, $sai ) ) {
        fussball_sync_log( 'Could not extract team-id / season from URL: ' . $url );
        return;
    }

    $team_id           = $tid[1];
    $season            = $sai[1];
    $category          = get_option( 'fussball_sync_category', '' );
    $friendly_category = get_option( 'fussball_sync_friendly_category', '' );

    fussball_sync_log( sprintf( 'Starting sync: team-id=%s, season=%s', $team_id, $season ) );

    try {
        $parser  = new Fussball_Parser( $team_id, $season );
        $matches = $parser->get_all_matches();

        fussball_sync_log( sprintf( 'Fetched %d matches from fussball.de.', count( $matches ) ) );

        $manager = new Fussball_Sync_Manager( $category, $friendly_category );
        $stats   = $manager->sync_matches( $matches );

        fussball_sync_log( sprintf(
            'Sync complete. Created: %d | Score updated: %d | Details updated: %d | Cancelled (skipped): %d | No change: %d | Duplicates removed: %d.',
            $stats['created'],
            $stats['updated_score'],
            $stats['updated_details'],
            $stats['cancelled'],
            $stats['skipped'],
            $stats['duplicates_removed']
        ) );
    } catch ( Exception $e ) {
        fussball_sync_log( 'Sync error: ' . $e->getMessage() );
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// Logging (kept lightweight – last 50 entries in a WP option)
// ──────────────────────────────────────────────────────────────────────────────
function fussball_sync_log( $message ) {
    $log = get_option( 'fussball_sync_log', array() );

    $log[] = array(
        'time' => current_time( 'Y-m-d H:i:s' ),
        'msg'  => $message,
    );

    // Keep only last 100 entries
    if ( count( $log ) > 100 ) {
        $log = array_slice( $log, -100 );
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
    wp_send_json_success( array( 'log' => array_slice( $log, -20 ) ) );
}
