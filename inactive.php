<?php
if ( ! defined( 'ABSPATH' ) ) exit; // loaded by the plugin only

/*
 * Inactive accounts: members without any published post whose last login (or registration, if they never
 * logged in since logins are tracked) is older than X months get one warning mail; logging in keeps the
 * account. Without a login within Y days after the warning the account is removed (no further mail).
 * Warnings are throttled (per run like the reminders, plus a daily limit) because IONOS locks the mailbox on bursts.
 *
 * user meta dmkrtz_ruu_inactive_warned = unix time of the warning mail (removed again by a login)
 */

define( 'DMKRTZ_RUU_INACTIVE_DELETE', 100 ); // removals per run

function dmkrtz_ruu_inactive_options() {
	$o = dmkrtz_ruu_options();
	return [
		'on'     => ! empty( $o['dmkrtz_ruu_inactive'] ),
		'months' => max( 6, (int) ( $o['dmkrtz_ruu_inactive_months'] ?? 12 ) ),
		'days'   => max( 7, (int) ( $o['dmkrtz_ruu_inactive_days'] ?? 30 ) ),
		'perday' => max( 10, (int) ( $o['dmkrtz_ruu_inactive_perday'] ?? 300 ) ),
		'roles'  => $o['dmkrtz_ruu_roles'],
	];
}

/**
 * Candidate IDs (SQL, the user tables are big). $stage: 'warn' = inactive, not warned yet;
 * 'due' = warned more than Y days ago and no login since. Members with a published post, team roles and kept accounts never show up.
 */
function dmkrtz_ruu_inactive_ids( $stage, $limit = 0, $count = false ) {
	global $wpdb;
	$io     = dmkrtz_ruu_inactive_options();
	$cut    = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $io['months'] . ' months' ) );
	$roles  = array_map( fn( $r ) => '%"' . $wpdb->esc_like( $r ) . '"%', $io['roles'] );
	$rolesql = implode( ' OR ', array_fill( 0, count( $roles ), 'cap.meta_value LIKE %s' ) );
	$sql = "FROM $wpdb->users u
		JOIN $wpdb->usermeta cap ON cap.user_id = u.ID AND cap.meta_key = '{$wpdb->prefix}capabilities' AND ( $rolesql )
		LEFT JOIN ( SELECT user_id, MAX( meta_value ) v FROM $wpdb->usermeta WHERE meta_key = '_um_last_login' GROUP BY user_id ) ll ON ll.user_id = u.ID
		LEFT JOIN $wpdb->usermeta w ON w.user_id = u.ID AND w.meta_key = 'dmkrtz_ruu_inactive_warned'
		WHERE GREATEST( u.user_registered, IFNULL( ll.v, '0' ) ) < %s
		AND NOT EXISTS ( SELECT 1 FROM $wpdb->posts p WHERE p.post_author = u.ID AND p.post_status = 'publish' AND p.post_type NOT IN ('attachment','revision','nav_menu_item') )
		AND NOT EXISTS ( SELECT 1 FROM $wpdb->usermeta k WHERE k.user_id = u.ID AND k.meta_key = 'dmkrtz_ruu_keep' )";
	$args = array_merge( $roles, [ $cut ] );
	if ( $stage === 'warn' ) {
		$sql .= ' AND w.umeta_id IS NULL';
	} else { // warned long enough ago, and the last login is older than the warning
		$sql   .= ' AND w.meta_value < %d AND IFNULL( UNIX_TIMESTAMP( ll.v ), 0 ) < w.meta_value';
		$args[] = time() - $io['days'] * DAY_IN_SECONDS;
	}
	if ( $count )
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) $sql", $args ) );
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT u.ID $sql ORDER BY GREATEST( u.user_registered, IFNULL( ll.v, '0' ) ) ASC LIMIT " . (int) $limit, $args ) ) );
}

/** Warnings sent today (UTC), for the daily limit. */
function dmkrtz_ruu_inactive_sent_today( $add = 0 ) {
	$s = get_option( 'dmkrtz_ruu_inactive_sent', [] );
	$s = is_array( $s ) && ( $s['day'] ?? '' ) === gmdate( 'Y-m-d' ) ? $s : [ 'day' => gmdate( 'Y-m-d' ), 'n' => 0 ];
	if ( $add ) { $s['n'] += $add; update_option( 'dmkrtz_ruu_inactive_sent', $s, false ); }
	return (int) $s['n'];
}

/** One run: remove due accounts, then send warnings within the mail budget. Returns what is left to do today. */
function dmkrtz_ruu_inactive_run( $start, $mailed ) {
	$io = dmkrtz_ruu_inactive_options();
	if ( ! $io['on'] ) return 0;
	require_once ABSPATH . 'wp-admin/includes/user.php';

	// removals: no mails at all (UM would send "account deleted")
	$removed = [];
	$no_mail = fn() => false;
	add_filter( 'pre_wp_mail', $no_mail, 999 );
	foreach ( dmkrtz_ruu_inactive_ids( 'due', DMKRTZ_RUU_INACTIVE_DELETE ) as $id ) {
		if ( time() - $start > DMKRTZ_RUU_TIME ) break;
		$u = get_userdata( $id );
		if ( ! $u ) continue;
		if ( count_user_posts( $id, 'any', true ) > 0 ) { update_user_meta( $id, 'dmkrtz_ruu_keep', 1 ); continue; }
		if ( wp_delete_user( $id ) ) $removed[] = "{$u->user_login} ({$id})";
	}
	remove_filter( 'pre_wp_mail', $no_mail, 999 );
	if ( $removed ) dmkrtz_ruu_logpurge( count( $removed ), array_merge( [ 'inactive:' ], $removed ) );

	// warnings
	$budget = min( DMKRTZ_RUU_MAILS - $mailed, $io['perday'] - dmkrtz_ruu_inactive_sent_today() );
	$sent   = 0;
	if ( $budget > 0 )
		foreach ( dmkrtz_ruu_inactive_ids( 'warn', $budget ) as $id ) {
			if ( time() - $start > DMKRTZ_RUU_TIME ) break;
			if ( $mailed++ > 0 ) sleep( DMKRTZ_RUU_MAILGAP );
			dmkrtz_ruu_inactive_warn( get_userdata( $id ) );
			$sent++;
		}
	if ( $sent ) dmkrtz_ruu_inactive_sent_today( $sent );

	$left = dmkrtz_ruu_inactive_ids( 'due', 1, true );
	if ( dmkrtz_ruu_inactive_sent_today() < $io['perday'] ) $left += dmkrtz_ruu_inactive_ids( 'warn', 1, true );
	return $left;
}

/** The warning mail; the account is marked even if the mail fails (one warning per account). */
function dmkrtz_ruu_inactive_warn( $user ) {
	if ( ! $user ) return false;
	update_user_meta( $user->ID, 'dmkrtz_ruu_inactive_warned', time() );
	$io      = dmkrtz_ruu_inactive_options();
	$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$deleted = time() + $io['days'] * DAY_IN_SECONDS;
	$login   = function_exists( 'um_get_core_page' ) ? um_get_core_page( 'login' ) : wp_login_url();
	$name    = $user->display_name ?: $user->user_login;

	$subject = sprintf( 'Your %s account will be removed in %d days', $site, $io['days'] );
	$message = sprintf(
		'<p>Hi %1$s,</p>'
		. '<p>you haven\'t logged in to %2$s for more than %3$d months, and your account has no published posts. Inactive accounts are removed automatically, so your account will be deleted on %4$s.</p>'
		. '<p>To keep it, just log in once before then: <a href="%5$s">%5$s</a></p>'
		. '<p>%2$s</p>',
		esc_html( $name ), esc_html( $site ), $io['months'], esc_html( wp_date( 'j F Y', $deleted ) ), esc_url( $login )
	);
	$message = apply_filters( 'dmkrtz_ruu_inactive_mail', $message, $user, $deleted, $login );
	return wp_mail( $user->user_email, $subject, $message, [ 'Content-Type: text/html; charset=UTF-8' ] );
}

/* A login keeps the account: the warning is withdrawn */
add_action( 'wp_login', function ( $login, $user ) {
	if ( $user instanceof WP_User ) delete_user_meta( $user->ID, 'dmkrtz_ruu_inactive_warned' );
}, 10, 2 );
