<?php
/**
 * Plugin Name:       Alt Text Manager
 * Plugin URI:        https://github.com/pjd199/alt-text-manager
 * Description:       Find, audit, and AI-generate alt text for images across your Media Library.
 * Version:           1.0.0
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

define( 'ATM_VERSION', '1.0.0' );
define( 'ATM_PLUGIN_FILE', __FILE__ );
define( 'ATM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ATM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ATM_OPTION_KEY', 'atm_settings' );
define( 'ATM_SCAN_TRANSIENT', 'atm_used_images_scan' );

/**
 * Autoload-free require of plugin classes. Kept simple and explicit on purpose,
 * so the load order is obvious and there is no Composer dependency required
 * just to run this plugin.
 */
require_once ATM_PLUGIN_DIR . 'includes/class-atm-settings.php';
require_once ATM_PLUGIN_DIR . 'includes/class-atm-scanner.php';
require_once ATM_PLUGIN_DIR . 'includes/class-atm-ai-generator.php';
require_once ATM_PLUGIN_DIR . 'includes/class-atm-list-table.php';
require_once ATM_PLUGIN_DIR . 'includes/class-atm-ajax.php';
require_once ATM_PLUGIN_DIR . 'includes/class-atm-admin.php';

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
