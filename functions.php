<?php
if ( ! defined( 'ABSPATH' ) ) exit; // loaded by the plugin only

define( 'DMKRTZ_RUU_BATCH', 200 );     // users per run
define( 'DMKRTZ_RUU_TIME', 20 );       // seconds per run
define( 'DMKRTZ_RUU_LOGSIZE', 100 );   // kept log entries
define( 'DMKRTZ_RUU_MAILS', 10 );      // users notified per run (IONOS locks the mailbox on bursts)
define( 'DMKRTZ_RUU_MAILGAP', 3 );     // seconds between two notification mails

/* Settings with defaults */
function dmkrtz_ruu_options() {
	$o = get_option( 'dmkrtz_ruu_settings' );
	$o = is_array( $o ) ? $o : [];
	$o['dmkrtz_ruu_roles']     = ! empty( $o['dmkrtz_ruu_roles'] ) ? (array) $o['dmkrtz_ruu_roles'] : [ get_option( 'default_role' ) ];
	$o['dmkrtz_ruu_purgeafter'] = max( 1, (int) ( $o['dmkrtz_ruu_purgeafter'] ?? 7 ) );
	return $o;
}

/* Users awaiting e-mail confirmation in the selected roles, older than the purge age */
function dmkrtz_ruu_query( $number = -1, $only_expired = true, $fields = 'all' ) {
	$o    = dmkrtz_ruu_options();
	$args = [
		'role__in'    => $o['dmkrtz_ruu_roles'],
		'meta_query'  => [
			[ 'key' => 'account_status', 'value' => 'awaiting_email_confirmation' ],
			[ 'key' => 'dmkrtz_ruu_keep', 'compare' => 'NOT EXISTS' ], // has content, never purged
		],
		'orderby'     => 'user_registered',
		'order'       => 'ASC',
		'number'      => $number,
		'fields'      => $fields,
		'count_total' => true,
	];
	if ( $only_expired )
		$args['date_query'] = [ [ 'column' => 'user_registered', 'before' => gmdate( 'Y-m-d H:i:s', time() - $o['dmkrtz_ruu_purgeafter'] * DAY_IN_SECONDS ) ] ];
	return new WP_User_Query( $args );
}

/* Unconfirmed users past half the purge age, not yet expired and not yet reminded */
function dmkrtz_ruu_reminder_query( $number ) {
	$o    = dmkrtz_ruu_options();
	$days = $o['dmkrtz_ruu_purgeafter'];
	return new WP_User_Query( [
		'role__in'    => $o['dmkrtz_ruu_roles'],
		'meta_query'  => [
			[ 'key' => 'account_status', 'value' => 'awaiting_email_confirmation' ],
			[ 'key' => 'dmkrtz_ruu_keep', 'compare' => 'NOT EXISTS' ],
			[ 'key' => 'dmkrtz_ruu_reminded', 'compare' => 'NOT EXISTS' ],
		],
		'date_query'  => [ [
			'column' => 'user_registered',
			'before' => gmdate( 'Y-m-d H:i:s', time() - (int) max( 1, floor( $days / 2 ) ) * DAY_IN_SECONDS ),
			'after'  => gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ),
		] ],
		'orderby'     => 'user_registered',
		'order'       => 'ASC',
		'number'      => $number,
		'count_total' => true,
	] );
}

/* Delete one batch; schedules a follow-up run while users are left */
add_action( 'dmkrtz_ruu_action', 'dmkrtz_ruu_action', 10 );
function dmkrtz_ruu_action() {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	@set_time_limit( 120 );

	$start   = time();
	$query   = dmkrtz_ruu_query( DMKRTZ_RUU_BATCH );
	$total   = (int) $query->get_total();
	$purged  = [];
	$skipped = 0;

	// no mails per deleted account (UM "account deleted" mails would hit the IONOS send limit)
	$no_mail = function () { return false; };
	add_filter( 'pre_wp_mail', $no_mail, 999 );

	$notify = ! empty( dmkrtz_ruu_options()['dmkrtz_ruu_notifyusers'] );
	$mailed = 0;

	foreach ( $query->get_results() as $user ) {
		if ( time() - $start > DMKRTZ_RUU_TIME || ( $notify && $mailed >= DMKRTZ_RUU_MAILS ) )
			break;
		// accounts that already have content are never removed
		if ( count_user_posts( $user->ID, 'any' ) > 0 ) {
			update_user_meta( $user->ID, 'dmkrtz_ruu_keep', 1 );
			$skipped++;
			continue;
		}
		$roles = implode( ',', (array) $user->roles );
		if ( wp_delete_user( $user->ID ) ) {
			$purged[] = "{$user->user_login} ({$user->ID} | {$roles})";
			if ( $notify ) {
				if ( $mailed++ > 0 )
					sleep( DMKRTZ_RUU_MAILGAP );
				remove_filter( 'pre_wp_mail', $no_mail, 999 );
				dmkrtz_ruu_notify_user( $user );
				add_filter( 'pre_wp_mail', $no_mail, 999 );
			}
		}
	}

	remove_filter( 'pre_wp_mail', $no_mail, 999 );

	// halfway reminders with the activation link, from the same mail budget
	$reminded = 0;
	if ( $notify && $mailed < DMKRTZ_RUU_MAILS && time() - $start <= DMKRTZ_RUU_TIME ) {
		foreach ( dmkrtz_ruu_reminder_query( DMKRTZ_RUU_MAILS - $mailed )->get_results() as $user ) {
			if ( $mailed++ > 0 )
				sleep( DMKRTZ_RUU_MAILGAP );
			dmkrtz_ruu_remind_user( $user );
			$reminded++;
		}
	}

	if ( $purged || $skipped )
		dmkrtz_ruu_logpurge( count( $purged ), $purged );

	$done      = (int) get_option( 'dmkrtz_ruu_run_count', 0 ) + count( $purged );
	$remaining = max( 0, $total - count( $purged ) - $skipped );
	if ( $notify )
		$remaining += (int) dmkrtz_ruu_reminder_query( 1 )->get_total();

	// more left → continue in two minutes instead of waiting a whole day; mail once at the end
	if ( $remaining > 0 ) {
		update_option( 'dmkrtz_ruu_run_count', $done, false );
		if ( ! wp_next_scheduled( 'dmkrtz_ruu_batch_event' ) )
			wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, 'dmkrtz_ruu_batch_event' );
	} else {
		delete_option( 'dmkrtz_ruu_run_count' );
		dmkrtz_ruu_sendmail( $done );
	}

	return [ 'purged' => count( $purged ), 'reminded' => $reminded, 'remaining' => $remaining ];
}
add_action( 'dmkrtz_ruu_batch_event', 'dmkrtz_ruu_action' );

/* Tells a deleted user why the account is gone (sent right after wp_delete_user) */
function dmkrtz_ruu_notify_user( $user ) {
	$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$days = dmkrtz_ruu_options()['dmkrtz_ruu_purgeafter'];
	$name = $user->display_name ?: $user->user_login;

	$subject = sprintf( 'Your %s account has been removed', $site );
	$message = sprintf(
		'<p>Hi %1$s,</p>'
		. '<p>you registered an account on %2$s, but the e-mail address was not confirmed within %3$d days. Unconfirmed accounts are removed automatically, so your account and its data have been deleted.</p>'
		. '<p>You are welcome to <a href="%4$s">register again</a> at any time. Please click the confirmation link in the e-mail you receive after registering.</p>'
		. '<p>%2$s</p>',
		esc_html( $name ), esc_html( $site ), $days, esc_url( function_exists( 'um_get_core_page' ) ? um_get_core_page( 'register' ) : wp_registration_url() )
	);
	$message = apply_filters( 'dmkrtz_ruu_user_mail', $message, $user );

	return wp_mail( $user->user_email, $subject, $message, [ 'Content-Type: text/html; charset=UTF-8' ] );
}

/* Halfway reminder: account will be removed in X days unless activated via the link */
function dmkrtz_ruu_remind_user( $user ) {
	update_user_meta( $user->ID, 'dmkrtz_ruu_reminded', time() ); // once per user, even if the mail fails

	// UM link; a fresh hash when the old one is missing or expired
	$expiry = (int) get_user_meta( $user->ID, 'account_secret_hash_expiry', true );
	if ( ! get_user_meta( $user->ID, 'account_secret_hash', true ) || ( $expiry && time() > $expiry ) )
		UM()->common()->users()->assign_secretkey( $user->ID );
	$link = UM()->permalinks()->activate_url( $user->ID );
	if ( ! $link )
		return false;

	$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$days    = dmkrtz_ruu_options()['dmkrtz_ruu_purgeafter'];
	$deleted = strtotime( $user->user_registered . ' UTC' ) + $days * DAY_IN_SECONDS;
	$left    = max( 1, (int) ceil( ( $deleted - time() ) / DAY_IN_SECONDS ) );
	$name    = $user->display_name ?: $user->user_login;

	$subject = sprintf( 'Please activate your %s account', $site );
	$message = sprintf(
		'<p>Hi %1$s,</p>'
		. '<p>you registered an account on %2$s, but your e-mail address has not been confirmed yet. Unconfirmed accounts are removed automatically, so your account will be deleted in %3$s (after %4$s).</p>'
		. '<p>To keep your account, please activate it with this link:<br><a href="%5$s">%5$s</a></p>'
		. '<p>%2$s</p>',
		esc_html( $name ), esc_html( $site ), $left === 1 ? '1 day' : $left . ' days',
		esc_html( wp_date( 'j F Y', $deleted ) ), esc_url( $link )
	);
	$message = apply_filters( 'dmkrtz_ruu_reminder_mail', $message, $user, $link );

	return wp_mail( $user->user_email, $subject, $message, [ 'Content-Type: text/html; charset=UTF-8' ] );
}

/* Summary mail once a purge run is complete */
function dmkrtz_ruu_sendmail( $count ) {
	$o = dmkrtz_ruu_options();
	if ( empty( $o['dmkrtz_ruu_sendmails'] ) || $count < 1 )
		return;

	$email   = ! empty( $o['dmkrtz_ruu_emailreceiver'] ) ? $o['dmkrtz_ruu_emailreceiver'] : get_bloginfo( 'admin_email' );
	$message = str_replace( '{count}', $count, (string) ( ( $o['dmkrtz_ruu_content'] ?? '' ) ?: '{count} users removed.' ) );
	$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
	wp_mail( $email, 'Remove Unapproved Users Info', $message, $headers );
}

/* Log lives in the database (the old purge.log was readable from the web) */
function dmkrtz_ruu_logpurge( $count, $users_purged ) {
	$log   = get_option( 'dmkrtz_ruu_log', [] );
	$log   = is_array( $log ) ? $log : [];
	$log[] = [ 'date' => wp_date( 'd.m.y H:i' ), 'count' => $count, 'users' => implode( ', ', $users_purged ) ];
	update_option( 'dmkrtz_ruu_log', array_slice( $log, -DMKRTZ_RUU_LOGSIZE ), false );
}

/* Daily event must always exist (it went missing after migrations before) */
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'dmkrtz_ruu_daily_event' ) )
		wp_schedule_event( strtotime( 'tomorrow 00:00' ), 'daily', 'dmkrtz_ruu_daily_event' );

	// one-time move of the old public log file into the database
	$file = WP_PLUGIN_DIR . '/dmkrtz-remove-unapproved-users/purge.log';
	if ( is_file( $file ) && is_writable( dirname( $file ) ) ) {
		$old = json_decode( (string) file_get_contents( $file ), true );
		if ( is_array( $old ) && ! get_option( 'dmkrtz_ruu_log' ) )
			update_option( 'dmkrtz_ruu_log', array_slice( array_values( $old ), -DMKRTZ_RUU_LOGSIZE ), false );
		@unlink( $file );
	}
} );
