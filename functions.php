<?php
if ( ! defined( 'ABSPATH' ) ) exit; // loaded by the plugin only

define( 'DMKRTZ_RUU_BATCH', 200 );     // users per run
define( 'DMKRTZ_RUU_TIME', 20 );       // seconds per run
define( 'DMKRTZ_RUU_LOGSIZE', 100 );   // kept log entries

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

	foreach ( $query->get_results() as $user ) {
		if ( time() - $start > DMKRTZ_RUU_TIME )
			break;
		// accounts that already have content are never removed
		if ( count_user_posts( $user->ID, 'any' ) > 0 ) {
			update_user_meta( $user->ID, 'dmkrtz_ruu_keep', 1 );
			$skipped++;
			continue;
		}
		$roles = implode( ',', (array) $user->roles );
		if ( wp_delete_user( $user->ID ) )
			$purged[] = "{$user->user_login} ({$user->ID} | {$roles})";
	}

	remove_filter( 'pre_wp_mail', $no_mail, 999 );

	if ( $purged || $skipped )
		dmkrtz_ruu_logpurge( count( $purged ), $purged );

	$done      = (int) get_option( 'dmkrtz_ruu_run_count', 0 ) + count( $purged );
	$remaining = max( 0, $total - count( $purged ) - $skipped );

	// more left → continue in two minutes instead of waiting a whole day; mail once at the end
	if ( $remaining > 0 ) {
		update_option( 'dmkrtz_ruu_run_count', $done, false );
		if ( ! wp_next_scheduled( 'dmkrtz_ruu_batch_event' ) )
			wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, 'dmkrtz_ruu_batch_event' );
	} else {
		delete_option( 'dmkrtz_ruu_run_count' );
		dmkrtz_ruu_sendmail( $done );
	}

	return [ 'purged' => count( $purged ), 'remaining' => $remaining ];
}
add_action( 'dmkrtz_ruu_batch_event', 'dmkrtz_ruu_action' );

/* Summary mail once a purge run is complete */
function dmkrtz_ruu_sendmail( $count ) {
	$o = dmkrtz_ruu_options();
	if ( empty( $o['dmkrtz_ruu_sendmails'] ) || $count < 1 )
		return;

	$email   = ! empty( $o['dmkrtz_ruu_emailreceiver'] ) ? $o['dmkrtz_ruu_emailreceiver'] : get_bloginfo( 'admin_email' );
	$message = str_replace( '{count}', $count, (string) ( $o['dmkrtz_ruu_content'] ?? '{count} users removed.' ) );
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

/* Manual purge from the settings page (admins only, nonce checked) */
add_action( 'wp_ajax_dmkrtz_ruu_purge', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'dmkrtz_ruu_purge', 'nonce', false ) )
		wp_send_json_error( 'Not allowed', 403 );
	wp_send_json_success( dmkrtz_ruu_action() );
} );

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
