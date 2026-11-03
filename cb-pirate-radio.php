<?php
/**
 * Plugin Name: CB Pirate Radio
 * Description: Captain Baltimore pirate radio — artist submissions, genre channels, direct AzuraCast integration, and the tune-in dial. Requires only WordPress + AzuraCast; no other tools needed.
 * Version: 0.13.0
 * Author: Captain Baltimore
 * Text Domain: cb-radio
 */

defined( 'ABSPATH' ) || exit;

define( 'CB_RADIO_VERSION', '0.13.0' );
define( 'CB_RADIO_FILE', __FILE__ );
define( 'CB_RADIO_DIR', plugin_dir_path( __FILE__ ) );
define( 'CB_RADIO_URL', plugin_dir_url( __FILE__ ) );
define( 'CB_RADIO_TABLE', 'cb_radio_submissions' ); // prefixed at runtime

require_once CB_RADIO_DIR . 'includes/class-cb-radio-helpers.php';
require_once CB_RADIO_DIR . 'includes/class-cb-radio-install.php';
require_once CB_RADIO_DIR . 'includes/class-cb-radio-settings.php';
require_once CB_RADIO_DIR . 'includes/class-cb-radio-artists.php';
require_once CB_RADIO_DIR . 'includes/class-cb-radio-form.php';
require_once CB_RADIO_DIR . 'includes/class-cb-radio-my-tracks.php';
require_once CB_RADIO_DIR . 'includes/class-cb-radio-admin.php';
require_once CB_RADIO_DIR . 'includes/class-cb-radio-azuracast.php';
require_once CB_RADIO_DIR . 'includes/class-cb-radio-queue.php';
require_once CB_RADIO_DIR . 'includes/class-cb-radio-reconciler.php';
require_once CB_RADIO_DIR . 'includes/class-cb-radio-rest.php';
require_once CB_RADIO_DIR . 'includes/class-cb-radio-charts.php';
require_once CB_RADIO_DIR . 'includes/class-cb-radio-themes.php';

register_activation_hook( __FILE__, [ 'CB_Radio_Install', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'CB_Radio_Install', 'deactivate' ] );

add_action( 'plugins_loaded', function () {
	// Version-gated upgrades so existing installs get new tables without re-activating.
	if ( get_option( 'cb_radio_db_version' ) !== CB_RADIO_VERSION ) {
		CB_Radio_Install::upgrade();
	}
	CB_Radio_Settings::init();
	CB_Radio_Artists::init();
	CB_Radio_Form::init();
	CB_Radio_MyTracks::init();
	CB_Radio_Admin::init();
	CB_Radio_Queue::init();
	CB_Radio_Reconciler::init();
	CB_Radio_Rest::init();
	CB_Radio_Charts::init();
	CB_Radio_Themes::init();
} );
