<?php
/**
 * Ligatabelle je Mannschaft von fussball.de (seit 2.1.0).
 *
 * Beim Sync: Teamseite laden, die Staffel aus der Tabellen-Adresse lesen (ajax.team.table/-/saison/…/staffel/…),
 * Tabelle laden und auswerten, als Option fussball_sync_tabelle_{mannschaft} speichern (nicht autoload).
 * Wappen kommen aus der eigenen Mediathek bzw. aus vorhandenen Spielen, nie als Bild von fussball.de
 * (sonst würden Besucherdaten an fussball.de übertragen).
 *
 * Lesen: fussball_tabelle( $mannschaft ) liefert
 *   [ 'stand' => 'Y-m-d H:i:s', 'saison' => '2627', 'staffel' => '…', 'zeilen' => [ [ 'platz', 'team', 'team_id', 'eigen' (bool),
 *     'spiele', 'g', 'u', 'v', 'tore', 'gegentore', 'diff', 'punkte', 'zone' (aufstieg|aufstieg-relegation|abstieg|abstieg-relegation|''),
 *     'trend' (hoch|runter|gleich|''), 'wappen' (Anhang-ID oder URL, leer wenn keins) ], … ] ] oder null.
 */
defined( 'ABSPATH' ) || exit;

function fussball_tabelle( $team_slug ) {
	$t = get_option( 'fussball_sync_tabelle_' . sanitize_key( $team_slug ) );
	return ( is_array( $t ) && ! empty( $t['zeilen'] ) ) ? $t : null;
}

/** Seite von fussball.de laden (gleiche Kennung wie der Spielplan-Abruf). */
function fussball_sync_http( $url ) {
	$r = wp_remote_get( $url, array(
		'timeout'    => 20,
		'user-agent' => 'Mozilla/5.0 (compatible; WordPress/' . get_bloginfo( 'version' ) . ')',
		'headers'    => array( 'Accept-Language' => 'de-DE,de;q=0.9', 'Referer' => 'https://www.fussball.de/' ),
	) );
	if ( is_wp_error( $r ) ) {
		return $r;
	}
	if ( 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
		return new WP_Error( 'http', 'HTTP ' . wp_remote_retrieve_response_code( $r ) . ' für ' . $url );
	}
	return (string) wp_remote_retrieve_body( $r );
}

/** Tabellen-HTML von fussball.de auswerten. */
function fussball_sync_tabelle_parsen( $html ) {
	$s     = preg_replace( '/\s+/u', ' ', (string) $html );
	$tbody = strpos( $s, '<tbody' );
	if ( false === $tbody ) {
		return array();
	}
	$s      = substr( $s, $tbody );
	$zeilen = array();
	preg_match_all( '#<tr(?: class="([^"]*)")?>(.*?)</tr>#u', $s, $trs, PREG_SET_ORDER );
	foreach ( $trs as $tr ) {
		$klassen = ' ' . $tr[1] . ' ';
		$inhalt  = $tr[2];
		if ( ! preg_match( '#column-rank">\s*(\d+)\.\s*</td>#u', $inhalt, $platz ) ) {
			continue;
		}
		preg_match( '#club-name">\s*(.*?)\s*</div>#u', $inhalt, $name );
		preg_match( '#team-id/([A-Z0-9]+)#', $inhalt, $tid );
		preg_match_all( '#<td(?: class="([^"]*)")?>\s*(-?\d+)\s*</td>#u', $inhalt, $zahlen, PREG_SET_ORDER );
		preg_match( '#no-wrap">\s*(\d+)\s*:\s*(\d+)\s*</td>#u', $inhalt, $tore );
		$werte = array();
		foreach ( $zahlen as $z ) {
			$werte[] = array( $z[1], (int) $z[2] );
		}
		// Reihenfolge laut Kopf: Spiele, G, U, V, (Tore als „x : y“), Tordifferenz, Punkte
		$punkte = 0; $diff = 0;
		foreach ( $werte as $w ) {
			if ( false !== strpos( (string) $w[0], 'column-points' ) ) {
				$punkte = $w[1];
			}
		}
		$einfach = array_values( array_filter( $werte, function ( $w ) { return false === strpos( (string) $w[0], 'column-points' ); } ) );
		$zone = '';
		if ( false !== strpos( $klassen, ' row-promotion-playoff ' ) ) {
			$zone = 'aufstieg-relegation';
		} elseif ( false !== strpos( $klassen, ' row-promotion ' ) ) {
			$zone = 'aufstieg';
		} elseif ( false !== strpos( $klassen, ' row-relegation-playoff ' ) ) {
			$zone = 'abstieg-relegation';
		} elseif ( false !== strpos( $klassen, ' row-relegation ' ) ) {
			$zone = 'abstieg';
		}
		$trend = '';
		if ( false !== strpos( $inhalt, 'icon-arrow-up-right' ) ) {
			$trend = 'hoch';
		} elseif ( false !== strpos( $inhalt, 'icon-arrow-down-right' ) ) {
			$trend = 'runter';
		} elseif ( false !== strpos( $inhalt, 'icon-arrow-right' ) ) {
			$trend = 'gleich';
		}
		$team = trim( str_replace( array( "\xE2\x80\x8B", "\xC2\xA0" ), array( '', ' ' ), html_entity_decode( wp_strip_all_tags( $name[1] ?? '' ), ENT_QUOTES, 'UTF-8' ) ) );
		$zeilen[] = array(
			'platz'     => (int) $platz[1],
			'team'      => $team,
			'team_id'   => $tid[1] ?? '',
			'eigen'     => false !== strpos( $klassen, ' own ' ),
			'spiele'    => $einfach[0][1] ?? 0,
			'g'         => $einfach[1][1] ?? 0,
			'u'         => $einfach[2][1] ?? 0,
			'v'         => $einfach[3][1] ?? 0,
			'tore'      => isset( $tore[1] ) ? (int) $tore[1] : 0,
			'gegentore' => isset( $tore[2] ) ? (int) $tore[2] : 0,
			'diff'      => $einfach[4][1] ?? ( isset( $tore[1] ) ? (int) $tore[1] - (int) $tore[2] : 0 ),
			'punkte'    => $punkte,
			'zone'      => $zone,
			'trend'     => $trend,
			'wappen'    => '',
		);
	}
	return $zeilen;
}

/**
 * Tabelle einer Mannschaft holen und speichern. $dry = true: nur holen und berichten.
 * @return array|WP_Error  die gespeicherte (bzw. geholte) Tabelle
 */
function fussball_sync_tabelle_holen( array $team, $dry = false ) {
	$slug = fussball_sync_team_slug( $team );
	$url  = preg_replace( '/#.*$/', '', (string) $team['url'] );
	if ( '' === $slug || '' === $url ) {
		return new WP_Error( 'team', 'Mannschaft ohne Kürzel oder Adresse' );
	}
	$seite = fussball_sync_http( $url );
	if ( is_wp_error( $seite ) ) {
		return $seite;
	}
	if ( ! preg_match( '#ajax\.team\.table/-/saison/(\d+)/staffel/([A-Za-z0-9-]+)/team-id/([A-Z0-9]+)#', $seite, $m ) ) {
		return new WP_Error( 'staffel', 'Keine Tabelle auf der Teamseite gefunden (z. B. nur Freundschaftsspiele)' );
	}
	$html = fussball_sync_http( 'https://www.fussball.de/ajax.team.table/-/saison/' . $m[1] . '/staffel/' . $m[2] . '/team-id/' . $m[3] );
	if ( is_wp_error( $html ) ) {
		return $html;
	}
	$zeilen = fussball_sync_tabelle_parsen( $html );
	if ( count( $zeilen ) < 4 ) {
		return new WP_Error( 'leer', 'Tabelle konnte nicht gelesen werden (' . count( $zeilen ) . ' Zeilen)' );
	}
	foreach ( $zeilen as $i => $z ) {
		$zeilen[ $i ]['wappen'] = fussball_wappen_suchen( $z['team'] );
	}
	$tabelle = array( 'stand' => current_time( 'mysql' ), 'saison' => $m[1], 'staffel' => $m[2], 'zeilen' => $zeilen );
	if ( ! $dry ) {
		update_option( 'fussball_sync_tabelle_' . sanitize_key( $slug ), $tabelle, false );
	}
	return $tabelle;
}

/**
 * Wappen zu einem Teamnamen: aus vorhandenen Spielen (gleicher Teamschlüssel, dann Teilübereinstimmung),
 * sonst Bild in der Mediathek, dessen Titel den Namen enthält. Leer, wenn nichts gefunden.
 */
function fussball_wappen_suchen( $team_name ) {
	static $cache = null;
	if ( null === $cache ) {
		$cache = array();
		$ids   = get_posts( array( 'post_type' => FUSSBALL_SPIEL_PT, 'post_status' => array( 'publish' ), 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'DESC', 'suppress_filters' => true ) );
		foreach ( $ids as $id ) {
			foreach ( array( 'heim', 'gast' ) as $seite ) {
				$name   = (string) get_post_meta( $id, '_fussball_' . $seite, true );
				$wappen = (string) get_post_meta( $id, '_fussball_wappen_' . $seite, true );
				$key    = fussball_sync_team_key( $name );
				if ( '' !== $key && '' !== $wappen && ! isset( $cache[ $key ] ) ) {
					$cache[ $key ] = $wappen;
				}
			}
		}
	}
	$key = fussball_sync_team_key( $team_name );
	if ( '' === $key ) {
		return '';
	}
	if ( isset( $cache[ $key ] ) ) {
		return $cache[ $key ];
	}
	foreach ( $cache as $k => $w ) {
		if ( false !== strpos( $k, $key ) || false !== strpos( $key, $k ) ) {
			return $w;
		}
	}
	global $wpdb;
	$id = $wpdb->get_var( $wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'inherit' AND post_mime_type LIKE 'image/%%' AND post_title LIKE %s ORDER BY ID ASC LIMIT 1",
		'%' . $wpdb->esc_like( $team_name ) . '%'
	) );
	return $id ? (string) $id : '';
}
