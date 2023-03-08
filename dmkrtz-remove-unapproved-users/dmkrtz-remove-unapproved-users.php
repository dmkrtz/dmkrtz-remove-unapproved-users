<?php
/*
Plugin Name: dmkrtz Remove Unapproved Users
Plugin URI: http://dmkrtz.de
Description: An easy to use and very basic Discord Webhook post plugin. Hooks into WP's "publish_post" function.</br>Comes with several toggles and options.
Author: dmkrtz
Author URI: http://dmkrtz.de
Version: 0.1

/* Verbiete den direkten Zugriff auf die Plugin-Datei */
if ( ! defined( 'ABSPATH' ) ) exit;
/* Nach dieser Zeile den Code einfügen*/

$dmkrtz_ruu_foundplugins = [];
$dmkrtz_ruu_logfile = WP_PLUGIN_DIR . '/dmkrtz-remove-unapproved-users/purge.log';
$ruu_topurge = 0;

$plugin = plugin_basename(__FILE__); 
add_filter("plugin_action_links_$plugin", 'dmkrtz_ruu_settings_link' );
function dmkrtz_ruu_settings_link($links) { 
	global $plugindir, $pluginname;
	
	$settings_link = "<a href=\"admin.php?page=remove_unapproved_users\">Settings</a>"; 
	array_unshift($links, $settings_link); 
	return $links; 

}

add_action( 'admin_enqueue_scripts', 'load_custom_wp_admin_scripts_ruu' );
function load_custom_wp_admin_scripts_ruu($hook) {

	if($hook != "dmkrtz-tools_page_remove_unapproved_users") {
        return;
    }
	wp_register_script( 'scripts', plugins_url('js/scripts.js', __FILE__));
	wp_enqueue_script( 'scripts' );
	wp_register_script( 'swal2', "https://cdn.jsdelivr.net/npm/sweetalert2@10" );
	wp_enqueue_script( 'swal2' );

}

add_action( 'admin_enqueue_scripts', 'load_custom_wp_admin_style_ruu' );
function load_custom_wp_admin_style_ruu($hook) {

    if($hook != "dmkrtz-tools_page_remove_unapproved_users") {
        return;
    }
    wp_enqueue_style( 'custom_wp_admin_css', plugins_url('admin-style.css', __FILE__) );
    wp_enqueue_style( 'bootstrapbtns', plugins_url('bootstrap-buttons.css', __FILE__) );
    wp_enqueue_style( 'loaders', plugins_url('loaders.css', __FILE__) );

}

add_action( 'admin_menu', 'dmkrtz_ruu_add_admin_menu' );
add_action( 'admin_init', 'dmkrtz_ruu_settings_init' );

function dmkrtz_ruu_add_admin_menu(  ) { 

	add_menu_page('dmkrtz Tools', 'dmkrtz Tools', 'manage_options', 'dmkrtz_tools', false, plugins_url("dmkrtz-remove-unapproved-users/assets/insane_logo_16.png"), 99 );
	add_submenu_page( 'dmkrtz_tools', 'Remove Unapproved Users', 'Remove Unapproved Users',
    'manage_options', 'remove_unapproved_users', 'dmkrtz_ruu_options_page');

	remove_submenu_page('dmkrtz_tools', 'dmkrtz_tools');

}

function dmkrtz_ruu_settings_init(  ) { 

	register_setting( 'dmkrtz_ruu_settings', 'dmkrtz_ruu_settings' );

	add_settings_section(
		'dmkrtz_ruu_dmkrtz_ruu_settings_section', 
		__( '', '' ), 
		'dmkrtz_ruu_settings_section_callback', 
		'dmkrtz_ruu_settings'
	);
	
	add_settings_field( 
		'dmkrtz_ruu_2', 
		__( 'Plugin', '' ), 
		'dmkrtz_ruu_2_render', 
		'dmkrtz_ruu_settings', 
		'dmkrtz_ruu_dmkrtz_ruu_settings_section' 
	);
	
	add_settings_field( 
		'dmkrtz_ruu_0', 
		__( 'Settings', '' ), 
		'dmkrtz_ruu_0_render', 
		'dmkrtz_ruu_settings', 
		'dmkrtz_ruu_dmkrtz_ruu_settings_section' 
	);

	add_settings_field( 
		'dmkrtz_ruu_1', 
		__( 'List of users', '' ), 
		'dmkrtz_ruu_1_render', 
		'dmkrtz_ruu_settings', 
		'dmkrtz_ruu_dmkrtz_ruu_settings_section' 
	);

	add_settings_field( 
		'dmkrtz_ruu_3', 
		__( 'Log', '' ), 
		'dmkrtz_ruu_3_render', 
		'dmkrtz_ruu_settings', 
		'dmkrtz_ruu_dmkrtz_ruu_settings_section' 
	);

}

function dmkrtz_ruu_2_render(  ) { 
	global $dmkrtz_ruu_foundplugins;

	$options = get_option( 'dmkrtz_ruu_settings' );
	
	if ( function_exists(um_user) ) {
		echo '<p style="color: green;"><b>Ultimate Member</b> found.</p>';
		$dmkrtz_ruu_foundplugins[] = "um";
	}
	
	if ( function_exists(bp_actions) ) {
		echo '<p style="color: green;"><b>BuddyPress</b> found.</p>';
		$dmkrtz_ruu_foundplugins[] = "bp";
	}
	
	if (count($dmkrtz_ruu_foundplugins) > 1) {
	
		echo '<p>Select the plugin that handles user registrations:</p>';

		if (count($dmkrtz_ruu_foundplugins) > 0) {
			echo '<select name="dmkrtz_ruu_settings[dmkrtz_ruu_memberplugin]" id="post-state" style="display: block;margin-bottom: 10px;">';
			if (in_array("bp", $dmkrtz_ruu_foundplugins)) {
				($options['dmkrtz_ruu_memberplugin'] == "bp") ? $selected = "selected" : $selected = ""; 
				echo '<option value="bp" '. $selected .'>BuddyPress</option>';
			}
			
			if (in_array("um", $dmkrtz_ruu_foundplugins)) {
				($options['dmkrtz_ruu_memberplugin'] == "um") ? $selected = "selected" : $selected = ""; 
				echo '<option value="um" '. $selected .'>Ultimate Member</option>';
			}
			echo '</select>';
		}
	
	} else {
		echo '<input type="hidden" name="dmkrtz_ruu_settings[dmkrtz_ruu_memberplugin]" value="'. $dmkrtz_ruu_foundplugins[0] .'" />';
	}
	
	if (empty($dmkrtz_ruu_foundplugins)) {
		echo '<p style="color: red;"><b>No user management plugin found!</b></p>';
	}
}

function dmkrtz_ruu_0_render( ) { 
	global $dmkrtz_ruu_foundplugins;

	$options = get_option( 'dmkrtz_ruu_settings' );
	
	// Default Value
	if ($options['dmkrtz_ruu_purgeafter'] ? $purgeafter = $options['dmkrtz_ruu_purgeafter'] : $purgeafter = 7 );
	
	if (!empty($dmkrtz_ruu_foundplugins)) {
	
		?>
		
		<div>
			<p>Days after unconfirmed registration until a user is considered to be purged:</p>
			<input id="purgeafterInput" type="range" value="<?= $purgeafter ?>" min="1" max="30" oninput="purgeafter.value=purgeafterInput.value" name='dmkrtz_ruu_settings[dmkrtz_ruu_purgeafter]' style="width: 100%; max-width: 250px;"/>
			<input id="purgeafter" type="number" value="<?= $purgeafter ?>" min="1" max="30" oninput="purgeafterInput.value=purgeafter.value" name='dmkrtz_ruu_settings[dmkrtz_ruu_purgeafter]'/>
		</div>
		
		<div>
			<p>Toggle daily automatic purge:</p>
			<label class="switch">
				<input type='checkbox' name='dmkrtz_ruu_settings[dmkrtz_ruu_schedule]' <?php checked( $options['dmkrtz_ruu_schedule'], 1 ); ?> value='1'>
				<span class="slider round"></span>
			</label>
		</div>
		
		<div>
			<p>Toggle E-Mail notification:</p>
			<label class="switch">
				<input type='checkbox' name='dmkrtz_ruu_settings[dmkrtz_ruu_sendmails]' <?php checked( $options['dmkrtz_ruu_sendmails'], 1 ); ?> value='1'>
				<span class="slider round"></span>
			</label>
		</div>
		
		<div>
			<p>E-Mail Receiver (blank = Admin Email):</p>
			<input type='email' name="dmkrtz_ruu_settings[dmkrtz_ruu_emailreceiver]" placeholder="<?= get_bloginfo( 'admin_email' ) ?>" value="<?= $options['dmkrtz_ruu_emailreceiver'] ?>"></input>
		</div>
		
		<div>
			<p>E-Mail content:</p>
			<p>You can use following variables for information: <code>{count}</code>.</p>
			<?php
			$settings  = array('media_buttons' => false,'textarea_rows' => 20,'textarea_name' => 'dmkrtz_ruu_settings[dmkrtz_ruu_content]');
			wp_editor( $options['dmkrtz_ruu_content'], 'dmkrtz_ruu_content', $settings  );
			?>
		</div>
		
		<?php
	}
	
}

function dmkrtz_ruu_1_render(  ) { 
	global $dmkrtz_ruu_foundplugins;
	global $ruu_topurge;
	
	$options = get_option( 'dmkrtz_ruu_settings' );
	
	if (!$options['dmkrtz_ruu_memberplugin']) {
		echo '<p style="color: red;"><b>Please set up and save before progressing.</b></p>';
	}

	if (!empty($dmkrtz_ruu_foundplugins)) {
		
		// Ultimate Member
		
		if ($options['dmkrtz_ruu_memberplugin'] == "um") {
			
			echo '<p><b>Ultimate Member</b></p>';
		
			$args = array(
				'meta_key'      =>  'account_status',
				'meta_value'    =>  'awaiting_email_confirmation',
				'orderby'		=>	'user_registered',
				'order'			=>	'DESC'
			);

			$users = get_users($args);
			$number_of_users = count($users);
			
			echo '<p>';
			echo $number_of_users, ' Users are awaiting E-Mail confirmation.';
			echo '</p>';
				
			if ( $number_of_users > 0 ) {
				$now = time(); // Current time 
				
				echo '<div class="checklist" style="height: fit-content; max-height: 500px;">';
					foreach ($users as $user) {
						$your_date = strtotime($user->user_registered); // This will parses an English textual datetime into a Unix timestamp
						$datediff = abs($now - $your_date);// Gives absolute Value 
						$diff = floor($datediff/(60*60*24)); //Returns the lowest value by rounding down value 
						$remove = $options['dmkrtz_ruu_purgeafter'];
						
						echo '<b>', $user->id, ' - ', $user->user_nicename, '</b></br>';
						echo '<b>', count_user_posts( $user->id ), ' post(s)</b></br>';
						echo 'Registered ', date('d.m.y H:i', $your_date);
						if ($diff >= $remove) {
							echo ' <b style="color: red;">(EXCEEDED!)</b>';
							$ruu_topurge++;
						} else {
							echo ' (', $diff, ')';
						}
						echo '<hr>';
					}
					
				echo '</div>';
			}
		
		}
		
		// BuddyPress
		
		if ($options['dmkrtz_ruu_memberplugin'] == "bp") {
			
			echo '<p><b>BuddyPress</b></p>';
			
			$inactive_users_array = BP_Signup::get();

			$number_of_users = $inactive_users_array['total'];
			
			if ( $number_of_users > 0 ) {
				$now = time();
				
				echo '<div class="checklist" style="height: fit-content; max-height: 500px;">';
					foreach ( $inactive_users_array['signups'] as $user ) {
						$your_date = strtotime($user->registered); // This will parses an English textual datetime into a Unix timestamp
						$datediff = abs($now - $your_date);// Gives absolute Value 
						$diff = floor($datediff/(60*60*24)); //Returns the lowest value by rounding down value 
						$remove = $options['dmkrtz_ruu_purgeafter'];
						
						echo '<b>', $user->id, ' - ', $user->user_login, '</b></br>';
						echo '<b>', count_user_posts( $user->id ), ' post(s)</b></br>';
						echo 'Registered ', date('y/m/d h:ia', $your_date);
						if ($diff >= $remove) {
							echo ' <b style="color: red;">(EXCEEDED!)</b>';
							$ruu_topurge++;
						} else {
							echo ' (', $diff, ')';
						}
						echo '<hr>';
					}
					
				echo '</div>';
			}
			
		}
	
	}

}

function dmkrtz_ruu_3_render(  ) { 
	global $dmkrtz_ruu_logfile;
	
	if (file_exists($dmkrtz_ruu_logfile)) {
		$logfile = json_decode(file_get_contents($dmkrtz_ruu_logfile), true);
		
		$logfile = array_reverse($logfile);
	}
	
	echo "<div class='checklist' style='height: fit-content; max-height: 500px;'>";
		if ($logfile) {
			foreach($logfile as $log) {
				echo "<b>", $log['date'], "</b></br>";
				echo $log['count'] . "</br>";
				echo $log['users'];
				echo "<hr>";
			}
		} else {
			echo "No log file found.";
		}
	echo "</div>";

}

function dmkrtz_ruu_settings_section_callback(  ) { 

	?>
	<p>Menu for the <b>Remove Unapproved Users</b> plugin made by dmkrtz.</p>
	
	<p>Tired of fake users taking up unnecessary space on your website? They never verify and so you always see the count next to the "Users" menu entry?</br>
	I was, and so I made this plugin.</br></p>
	<p>Set the days you want to wait until the pesky users will be removed from the database.</br>
	Fires once a day at midnight (GMT) when the automatic purge is enabled, or you can do it manually by pressing <span class="btn btn-danger" style="cursor: default">Purge</span>.</br></p>
	<?php
}

function dmkrtz_ruu_options_page(  ) { 
	global $dmkrtz_ruu_foundplugins;
	global $ruu_topurge;

	?>
	<div class="loading" id="loading-div">
		<div class="lds-roller"><div></div><div></div><div></div><div></div><div></div><div></div><div></div><div></div></div>
	
	</div>

	<form id="save" action='options.php' method='post'>

		<h2>Remove Unapproved Users</h2>

		<?php
		settings_fields( 'dmkrtz_ruu_settings' );
		do_settings_sections( 'dmkrtz_ruu_settings' );
		// submit_button();
		
		if (!empty($dmkrtz_ruu_foundplugins)) {

			$args = array(
			'meta_key'      =>  'account_status',
			'meta_value'    =>  'awaiting_email_confirmation'
			);

			$users = get_users($args);
			$number_of_users = count($users);
		
		}
		?>
	
	<div class="form-buttons">
		<input class="btn btn-primary" type="submit" value="Save settings">
		
		<?php
		if ( $ruu_topurge > 0 ) {
			echo "<button class='btn btn-danger' id='btnpurge'>Purge {$ruu_topurge} users</button>";
		} 
		?>
	</div>
	
	</form>
	<?php

	if ( isset ( $_POST['purge'] ) ) {			
		if ( $_POST['purge']==true ) {
			do_action('dmkrtz_ruu_action');
		}
	}
}

add_action( 'dmkrtz_ruu_action', 'dmkrtz_ruu_action', 10 );
function dmkrtz_ruu_action() {
	require_once( ABSPATH.'wp-admin/includes/user.php' );
	
	$options = get_option( 'dmkrtz_ruu_settings' );
	
	$args = array(
	'meta_key'      =>  'account_status',
	'meta_value'    =>  'awaiting_email_confirmation'
	);

	$users = get_users($args);
	$number_of_users = count($users);
	$remove = $options['dmkrtz_ruu_purgeafter'];
	$count = 0;

	$now = time(); // Current time 
	foreach ( $users as $user ) {
		$user_id = $user->id;
		$user_name = $user->user_login;
		$your_date = strtotime($user->user_registered); // This will parses an English textual datetime into a Unix timestamp
		$datediff = abs($now - $your_date);// Gives absolute Value 
		$diff = floor($datediff/(60*60*24)); //Returns the lowest value by rounding down value 

		if ($diff >= $remove) {
			$users_purged[$count] = "{$user_name} ({$user_id})";
			wp_delete_user( $user_id );
			$count++;
		}
	}
	
	if($count>0) {
		do_action('dmkrtz_ruu_sendmail', $count);
		do_action('dmkrtz_ruu_logpurge', $count, $users_purged);
	}

}

add_action( 'dmkrtz_ruu_sendmail', 'dmkrtz_ruu_sendmail', 10, 1);
function dmkrtz_ruu_sendmail( $count ) {
	$options = get_option( 'dmkrtz_ruu_settings' );
	
	// get email sender data
	$email = $options['dmkrtz_ruu_emailreceiver'];
	( $email ) ?: ( $email = get_bloginfo( 'admin_email' ) );
	
	$message = $options['dmkrtz_ruu_content'];
	// Arrays
	$placeholders = [
		'{count}'
	];
	$fillplaceholders = [
		$count
	];
	
	$message = str_replace( $placeholders, $fillplaceholders, $message );
	
	if ( $options['dmkrtz_ruu_sendmails'] ) {
		if ( $count > 0 ) {
			$headers[] = 'Content-Type: text/html; charset=UTF-8';
			$headers[] = 'From: ' . get_bloginfo( 'name' ) . ' < ' . get_bloginfo( 'admin_email' ) . '>';
		
			wp_mail( $email, "Remove Unapproved Users Info", $message, $headers );
		}
	}
}

add_action( 'dmkrtz_ruu_logpurge', 'dmkrtz_ruu_logpurge', 10, 2);
function dmkrtz_ruu_logpurge( $count, $users_purged ) {
	global $dmkrtz_ruu_logfile;
	$datenow = date("d.m.y H:i");
	
	if(file_exists($dmkrtz_ruu_logfile)) {
		$ruulog = json_decode(file_get_contents($dmkrtz_ruu_logfile), true);
		$addlog = count($ruulog) + 1;
	} else {
		$ruulog = [];
		$addlog = 0;
	}
	
	$ruulog[$addlog]['date'] = $datenow;
	$ruulog[$addlog]['count'] = $count;
	$ruulog[$addlog]['users'] = implode(", ", $users_purged);
	
	file_put_contents($dmkrtz_ruu_logfile, json_encode($ruulog));
}

register_activation_hook( __FILE__, 'dmkrtz_ruu_schedule_activation' );
  
function dmkrtz_ruu_schedule_activation() {
    if (! wp_next_scheduled ( 'dmkrtz_ruu_daily_event' )) {
        wp_schedule_event( strtotime('tomorrow 00:00'), 'daily', 'dmkrtz_ruu_daily_event' );
    }
}

add_action( 'dmkrtz_ruu_daily_event', 'dmkrtz_ruu_purge' );
function dmkrtz_ruu_purge() {
	$options = get_option( 'dmkrtz_ruu_settings' );

	if ( $options['dmkrtz_ruu_schedule'] ) {
		do_action('dmkrtz_ruu_action');
	}
}

register_deactivation_hook( __FILE__, 'dmkrtz_ruu_schedule_deactivation' );
  
function dmkrtz_ruu_schedule_deactivation() {
    wp_clear_scheduled_hook( 'dmkrtz_ruu_daily_event' );
}

/* Nach dieser Zeile KEINEN Code mehr einfügen*/