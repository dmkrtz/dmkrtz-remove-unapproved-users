<?php
/*
Plugin Name: dmkrtz Remove Unapproved Users (GitHub)
Plugin URI: https://dmkrtz.de
GitHub Plugin URI: dmkrtz/dmkrtz-remove-unapproved-users
Description: Removes users that haven't activated their account after set time.
Author: dmkrtz
Author URI: https://dmkrtz.de
Version: 0.6.0
*/

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'DMKRTZ_RUU_VERSION', '0.6.0' );
define( 'DMKRTZ_RUU_PAGE', 'remove_unapproved_users' );

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . DMKRTZ_RUU_PAGE ) ) . '">Settings</a>' );
	return $links;
} );

/** Our page, whichever menu it hangs in (dmkrtz Settings or dmkrtz Tools). */
function dmkrtz_ruu_is_page( $hook ) {
	return str_ends_with( (string) $hook, '_page_' . DMKRTZ_RUU_PAGE );
}

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( dmkrtz_ruu_is_page( $hook ) )
		wp_enqueue_style( 'dmkrtz-ruu-admin', plugins_url( 'admin.css', __FILE__ ), [], DMKRTZ_RUU_VERSION );
} );

add_action( 'admin_menu', function () {
	// shares the site's "dmkrtz Settings" menu when it exists (mu-plugin mods-settings), else its own "dmkrtz Tools"
	$parent = defined( 'MODS_SETTINGS_MENU' ) ? MODS_SETTINGS_MENU : 'dmkrtz_tools';
	if ( $parent === 'dmkrtz_tools' )
		add_menu_page( 'dmkrtz Tools', 'dmkrtz Tools', 'manage_options', 'dmkrtz_tools', false, plugins_url( 'assets/insane_logo_16.png', __FILE__ ), 99 );
	add_submenu_page( $parent, 'Remove Unapproved Users', 'Remove Unapproved Users', 'manage_options', DMKRTZ_RUU_PAGE, 'dmkrtz_ruu_options_page' );
	remove_submenu_page( 'dmkrtz_tools', 'dmkrtz_tools' );
} );

add_action( 'admin_init', function () {
	register_setting( 'dmkrtz_ruu_settings', 'dmkrtz_ruu_settings', [ 'sanitize_callback' => 'dmkrtz_ruu_sanitize' ] );
} );

/** Registration plugins found on this site: key => name. */
function dmkrtz_ruu_found_plugins() {
	$out = [];
	if ( function_exists( 'um_user' ) )
		$out['um'] = 'Ultimate Member';
	if ( function_exists( 'bp_actions' ) )
		$out['bp'] = 'BuddyPress';
	return $out;
}

function dmkrtz_ruu_sanitize( $in ) {
	$in    = is_array( $in ) ? $in : [];
	$found = dmkrtz_ruu_found_plugins();
	$roles = array_keys( wp_roles()->get_names() );
	$plug  = (string) ( $in['dmkrtz_ruu_memberplugin'] ?? '' );
	return [
		'dmkrtz_ruu_memberplugin'  => isset( $found[ $plug ] ) ? $plug : (string) array_key_first( $found ),
		'dmkrtz_ruu_roles'         => array_values( array_intersect( (array) ( $in['dmkrtz_ruu_roles'] ?? [] ), $roles ) ),
		'dmkrtz_ruu_purgeafter'    => max( 1, min( 365, (int) ( $in['dmkrtz_ruu_purgeafter'] ?? 7 ) ) ),
		'dmkrtz_ruu_schedule'      => empty( $in['dmkrtz_ruu_schedule'] ) ? 0 : 1,
		'dmkrtz_ruu_notifyusers'   => empty( $in['dmkrtz_ruu_notifyusers'] ) ? 0 : 1,
		'dmkrtz_ruu_sendmails'     => empty( $in['dmkrtz_ruu_sendmails'] ) ? 0 : 1,
		'dmkrtz_ruu_emailreceiver' => sanitize_email( (string) ( $in['dmkrtz_ruu_emailreceiver'] ?? '' ) ),
		'dmkrtz_ruu_content'       => wp_kses_post( (string) ( $in['dmkrtz_ruu_content'] ?? '' ) ),
	];
}

/* Manual purge from the "Pending users" tab */
add_action( 'admin_post_dmkrtz_ruu_purge', function () {
	if ( ! current_user_can( 'manage_options' ) )
		wp_die( 'Not allowed.', 403 );
	check_admin_referer( 'dmkrtz_ruu_purge' );
	$r   = dmkrtz_ruu_action();
	$msg = sprintf( '%s users removed.', number_format_i18n( $r['purged'] ) )
		. ( $r['reminded'] ? sprintf( ' %s reminders sent.', number_format_i18n( $r['reminded'] ) ) : '' )
		. ( $r['remaining'] ? sprintf( ' %s more follow automatically in a few minutes.', number_format_i18n( $r['remaining'] ) ) : '' );
	wp_safe_redirect( add_query_arg( [ 'page' => DMKRTZ_RUU_PAGE, 'notice' => rawurlencode( $msg ) ], admin_url( 'admin.php' ) ) . '#users' );
	exit;
} );

/** A switch row (same markup as the Download Link Tracker settings). */
function dmkrtz_ruu_switch( $key, $label, $checked, $help = '' ) {
	echo '<label class="dmru-switch-row"><input type="hidden" name="dmkrtz_ruu_settings[' . esc_attr( $key ) . ']" value="0">'
		. '<span class="dmru-switch"><input type="checkbox" name="dmkrtz_ruu_settings[' . esc_attr( $key ) . ']" value="1"' . checked( (bool) $checked, true, false ) . '><span></span></span>'
		. '<span><strong>' . esc_html( $label ) . '</strong>' . ( $help ? '<span class="description">' . wp_kses_post( $help ) . '</span>' : '' ) . '</span></label>';
}

/** Pending sign-ups as rows: [ id, login, registered (unix), posts or null ]. */
function dmkrtz_ruu_pending( $plugin, $limit = 200 ) {
	$rows = [];
	$total = 0;
	if ( $plugin === 'um' ) {
		$q     = dmkrtz_ruu_query( $limit, false );
		$total = (int) $q->get_total();
		foreach ( $q->get_results() as $u )
			$rows[] = [ (int) $u->ID, $u->user_login, strtotime( $u->user_registered . ' UTC' ), null ];
	} elseif ( $plugin === 'bp' && class_exists( 'BP_Signup' ) ) {
		$s     = BP_Signup::get( [ 'number' => $limit ] );
		$total = (int) $s['total'];
		foreach ( $s['signups'] as $u )
			$rows[] = [ (int) $u->ID, $u->user_login, strtotime( $u->registered . ' UTC' ), count_user_posts( $u->ID ) ];
	}
	return [ $total, $rows ];
}

function dmkrtz_ruu_options_page() {
	if ( ! current_user_can( 'manage_options' ) )
		return;
	$o      = dmkrtz_ruu_options();
	$found  = dmkrtz_ruu_found_plugins();
	$plugin = isset( $found[ $o['dmkrtz_ruu_memberplugin'] ?? '' ] ) ? $o['dmkrtz_ruu_memberplugin'] : (string) array_key_first( $found );
	$days   = $o['dmkrtz_ruu_purgeafter'];
	[ $total, $rows ] = dmkrtz_ruu_pending( $plugin );
	$expired = 0;
	foreach ( $rows as $r )
		if ( time() - $r[2] >= $days * DAY_IN_SECONDS ) $expired++;
	if ( $plugin === 'um' )
		$expired = (int) dmkrtz_ruu_query( 1, true, 'ID' )->get_total();
	$log  = array_reverse( (array) get_option( 'dmkrtz_ruu_log', [] ) );
	$next = wp_next_scheduled( 'dmkrtz_ruu_daily_event' );

	echo '<div class="wrap dmru"><h1>Remove Unapproved Users</h1>';
	echo '<p class="dmru-muted">Removes accounts that never confirmed their e-mail address. Accounts with posts are always kept.</p>';
	if ( isset( $_GET['settings-updated'] ) )
		echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
	if ( ! empty( $_GET['notice'] ) )
		echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( wp_unslash( $_GET['notice'] ) ) . '</p></div>';
	if ( ! $found )
		echo '<div class="dmru-alert"><p><strong>No registration plugin found.</strong> Ultimate Member or BuddyPress is needed.</p></div>';

	echo '<nav class="dmru-tabs" role="tablist">'
		. '<a href="#settings" data-tab="settings" data-form="1">Settings</a>'
		. '<a href="#users" data-tab="users" data-form="0">Pending users <span class="count">' . number_format_i18n( $total ) . '</span></a>'
		. '<a href="#log" data-tab="log" data-form="0">Log</a></nav>';

	/* ---- settings ---- */
	echo '<form method="post" action="options.php" id="dmru-settings-form"><section class="dmru-panel" data-panel="settings">';
	settings_fields( 'dmkrtz_ruu_settings' );

	echo '<div class="dmru-card"><h2>Removal</h2>';
	if ( count( $found ) > 1 ) {
		echo '<div class="dmru-field"><span class="dmru-label">Registration plugin</span><div class="dmru-radios">';
		foreach ( $found as $k => $name )
			echo '<label class="dmru-radio"><input type="radio" name="dmkrtz_ruu_settings[dmkrtz_ruu_memberplugin]" value="' . esc_attr( $k ) . '"' . checked( $plugin, $k, false ) . '><span><strong>' . esc_html( $name ) . '</strong></span></label>';
		echo '</div></div>';
	} else {
		echo '<input type="hidden" name="dmkrtz_ruu_settings[dmkrtz_ruu_memberplugin]" value="' . esc_attr( $plugin ) . '">';
		if ( $found )
			echo '<p class="dmru-muted">Registrations are handled by ' . esc_html( $found[ $plugin ] ) . '.</p>';
	}
	dmkrtz_ruu_switch( 'dmkrtz_ruu_schedule', 'Remove automatically every day', ! empty( $o['dmkrtz_ruu_schedule'] ),
		'Runs at midnight (UTC)' . ( $next ? ', next run ' . esc_html( wp_date( 'd.m.Y H:i', $next ) ) . ' (server time)' : '' ) . '. Off: only the button under "Pending users" removes accounts.' );
	echo '<div class="dmru-grid">';
	echo '<div class="dmru-field"><label class="dmru-label" for="dmru-days">Remove after</label><input id="dmru-days" type="number" class="small-text" min="1" max="365" name="dmkrtz_ruu_settings[dmkrtz_ruu_purgeafter]" value="' . (int) $days . '"> days'
		. '<p class="description">Counted from the registration. Default: 7.</p></div>';
	echo '<div class="dmru-field"><span class="dmru-label">Roles</span><fieldset class="dmru-checks dmru-checks-scroll">';
	foreach ( wp_roles()->get_names() as $k => $name )
		echo '<label><input type="checkbox" name="dmkrtz_ruu_settings[dmkrtz_ruu_roles][]" value="' . esc_attr( $k ) . '"' . checked( in_array( $k, $o['dmkrtz_ruu_roles'], true ), true, false ) . '> ' . esc_html( translate_user_role( $name ) ) . '</label>';
	echo '</fieldset><p class="description">None selected = the default role for new users.</p></div>';
	echo '</div></div>';

	echo '<div class="dmru-card"><h2>E-mails</h2>';
	dmkrtz_ruu_switch( 'dmkrtz_ruu_notifyusers', 'E-mail the unconfirmed users', ! empty( $o['dmkrtz_ruu_notifyusers'] ),
		'An activation reminder at half the removal age, and the reason once the account is removed. Max. ' . DMKRTZ_RUU_MAILS . ' mails per run, ' . DMKRTZ_RUU_MAILGAP . ' s apart.' );
	dmkrtz_ruu_switch( 'dmkrtz_ruu_sendmails', 'Send a summary to the admin', ! empty( $o['dmkrtz_ruu_sendmails'] ), 'One mail after a complete run that removed someone.' );
	echo '<div class="dmru-grid">';
	echo '<div class="dmru-field"><label class="dmru-label" for="dmru-to">Summary goes to</label><input id="dmru-to" type="email" class="regular-text" name="dmkrtz_ruu_settings[dmkrtz_ruu_emailreceiver]" value="' . esc_attr( $o['dmkrtz_ruu_emailreceiver'] ?? '' ) . '" placeholder="' . esc_attr( get_bloginfo( 'admin_email' ) ) . '">'
		. '<p class="description">Empty: the site\'s admin e-mail.</p></div>';
	echo '<div class="dmru-field is-wide"><label class="dmru-label" for="dmru-content">Summary text</label><textarea id="dmru-content" rows="5" class="large-text" name="dmkrtz_ruu_settings[dmkrtz_ruu_content]">' . esc_textarea( $o['dmkrtz_ruu_content'] ?? '' ) . '</textarea>'
		. '<p class="description">HTML allowed. <code>{count}</code> = removed users. Empty: "{count} users removed."</p></div>';
	echo '</div></div>';
	echo '</section><p class="dmru-save"><button class="button button-primary button-large">Save settings</button></p></form>';

	/* ---- pending users ---- */
	echo '<section class="dmru-panel" data-panel="users" hidden>';
	echo '<div class="dmru-kpis"><div class="dmru-kpi"><span class="dmru-kpi-label">Unconfirmed</span><strong>' . number_format_i18n( $total ) . '</strong><span class="dmru-kpi-sub">waiting for the e-mail confirmation</span></div>'
		. '<div class="dmru-kpi"><span class="dmru-kpi-label">Due for removal</span><strong>' . number_format_i18n( $expired ) . '</strong><span class="dmru-kpi-sub">older than ' . (int) $days . ' days</span></div></div>';
	echo '<div class="dmru-card"><header><h2>Pending users</h2><span class="dmru-muted">' . ( $total > count( $rows ) ? 'The ' . count( $rows ) . ' oldest' : 'Oldest first' ) . '</span>';
	if ( $expired > 0 )
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'Remove ' . (int) $expired . ' unconfirmed users now? This can\\\'t be undone.\')">'
			. wp_nonce_field( 'dmkrtz_ruu_purge', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="dmkrtz_ruu_purge">'
			. '<button class="button button-link-delete">Remove ' . number_format_i18n( $expired ) . ' users now</button></form>';
	echo '</header>';
	if ( $rows ) {
		echo '<div class="dmru-scroll"><table class="dmru-table"><thead><tr><th>User</th><th>Registered</th><th>Age</th>' . ( $plugin === 'bp' ? '<th>Posts</th>' : '' ) . '<th>Status</th></tr></thead><tbody>';
		foreach ( $rows as [ $id, $login, $reg, $posts ] ) {
			$age = (int) floor( ( time() - $reg ) / DAY_IN_SECONDS );
			echo '<tr><td><a href="' . esc_url( get_edit_user_link( $id ) ) . '">' . esc_html( $login ) . '</a> <span class="dmru-muted">#' . $id . '</span></td>'
				. '<td class="dmru-date" data-label="Registered">' . esc_html( wp_date( 'd.m.Y H:i', $reg ) ) . '</td>'
				. '<td class="dmru-num" data-label="Age">' . $age . ' d</td>'
				. ( $plugin === 'bp' ? '<td class="dmru-num" data-label="Posts">' . (int) $posts . '</td>' : '' )
				. '<td data-label="Status">' . ( $age >= $days ? '<span class="dmru-pill is-warn">Due</span>' : '<span class="dmru-pill is-ok">Waiting</span>' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	} else {
		echo '<p class="dmru-muted">Nobody is waiting for a confirmation.</p>';
	}
	echo '</div></section>';

	/* ---- log ---- */
	echo '<section class="dmru-panel" data-panel="log" hidden><div class="dmru-card"><header><h2>Log</h2><span class="dmru-muted">Last ' . DMKRTZ_RUU_LOGSIZE . ' runs, newest first</span></header>';
	if ( $log ) {
		echo '<div class="dmru-scroll"><table class="dmru-table"><thead><tr><th>Date</th><th>Removed</th><th>Users</th></tr></thead><tbody>';
		foreach ( $log as $l )
			echo '<tr><td class="dmru-date">' . esc_html( $l['date'] ?? '' ) . '</td><td class="dmru-num" data-label="Removed">' . (int) ( $l['count'] ?? 0 ) . '</td><td data-label="Users" class="dmru-muted is-wide">' . esc_html( $l['users'] ?? '' ) . '</td></tr>';
		echo '</tbody></table></div>';
	} else {
		echo '<p class="dmru-muted">Nothing removed yet.</p>';
	}
	echo '</div></section></div>';
	?>
	<script>
	(function () {
		var tabs = document.querySelectorAll('.dmru-tabs a'), save = document.querySelector('.dmru-save');
		function show(k) {
			tabs.forEach(function (a) { a.classList.toggle('is-active', a.dataset.tab === k); if (a.dataset.tab === k) save.hidden = a.dataset.form !== '1'; });
			document.querySelectorAll('.dmru-panel').forEach(function (p) { p.hidden = p.dataset.panel !== k; });
		}
		tabs.forEach(function (a) { a.addEventListener('click', function (e) { e.preventDefault(); show(a.dataset.tab); history.replaceState(null, '', '#' + a.dataset.tab); }); });
		var h = location.hash.slice(1);
		show(document.querySelector('[data-panel="' + h + '"]') ? h : 'settings');
	})();
	</script>
	<?php
}

require_once __DIR__ . '/functions.php';

register_activation_hook( __FILE__, 'dmkrtz_ruu_schedule_activation' );
function dmkrtz_ruu_schedule_activation() {
	if ( ! wp_next_scheduled( 'dmkrtz_ruu_daily_event' ) )
		wp_schedule_event( strtotime( 'tomorrow 00:00' ), 'daily', 'dmkrtz_ruu_daily_event' );
}

add_action( 'dmkrtz_ruu_daily_event', 'dmkrtz_ruu_purge' );
function dmkrtz_ruu_purge() {
	if ( ! empty( dmkrtz_ruu_options()['dmkrtz_ruu_schedule'] ) )
		do_action( 'dmkrtz_ruu_action' );
}

register_deactivation_hook( __FILE__, 'dmkrtz_ruu_schedule_deactivation' );
function dmkrtz_ruu_schedule_deactivation() {
	wp_clear_scheduled_hook( 'dmkrtz_ruu_daily_event' );
	wp_clear_scheduled_hook( 'dmkrtz_ruu_batch_event' );
}
