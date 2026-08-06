<?php
/**
 * Plugin Name:       Alt Text Manager
 * Plugin URI:        https://github.com/pjd199/alt-text-manager
 * Description:       Find, audit, and AI-generate alt text for images across your Media Library.
 * Version:           1.0.5
 * Update URI:        https://github.com/pjd199/alt-text-manager
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Author:            Pete Dibdin
 * Text Domain:       alt-text-manager
 * License:           MIT
 *
 * @package AltTextManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

// Ensure get_plugin_data() is available
if ( ! function_exists( 'get_plugin_data' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

$atm_plugin_data = get_plugin_data( __FILE__, false, false );

define( 'ATM_VERSION',     $atm_plugin_data['Version'] );
define( 'ATM_TEXT_DOMAIN', $atm_plugin_data['TextDomain'] );
define( 'ATM_PLUGIN_FILE', __FILE__ );
define( 'ATM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ATM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ATM_UPDATE_URI', $atm_plugin_data['UpdateURI']);
define( 'ATM_OPTION_KEY', 'atm_settings' );
define( 'ATM_SCAN_TRANSIENT', 'atm_used_images_scan' );

// Load Composer Autoloader
if (file_exists(ATM_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once ATM_PLUGIN_DIR . 'vendor/autoload.php';
}

/**
 * Boot the plugin. Everything is instantiated on `plugins_loaded` so that
 * other plugins (SEO plugins in particular, whose meta keys we read) have
 * already registered themselves.
 */
function atm_boot() {
	ATM_Settings::instance();
	ATM_Scanner::instance();
	ATM_AI_Generator::instance();
	ATM_Ajax::instance();
	ATM_Override_Alt_Text::instance();

	if ( is_admin() ) {
		ATM_Admin::instance();
	}
}
add_action( 'plugins_loaded', 'atm_boot' );

/**
 * Activation: seed default settings so the plugin behaves sensibly even
 * before anyone visits the settings screen.
 */
function atm_activate() {
	if ( false === get_option( ATM_OPTION_KEY ) ) {
		add_option( ATM_OPTION_KEY, ATM_Settings::get_defaults() );
	}
}
register_activation_hook( __FILE__, 'atm_activate' );

/**
 * Deactivation: clear any scheduled/cached scan data. We deliberately do
 * NOT delete settings here — that only happens on uninstall.
 */
function atm_deactivate() {
	delete_transient( ATM_SCAN_TRANSIENT );
}
register_deactivation_hook( __FILE__, 'atm_deactivate' );

/**
 * Check for latest updates from GitHub
 */ 
$updateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	ATM_UPDATE_URI,
	ATM_PLUGIN_FILE,
	ATM_TEXT_DOMAIN
);
$updateChecker->setBranch('main');
$updateChecker->getVcsApi()->enableReleaseAssets('/' . ATM_TEXT_DOMAIN . '-\d+\.\d+\.\d+\.zip($|[?&#])/i');
