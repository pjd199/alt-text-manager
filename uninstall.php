<?php
/**
 * Fires only when the plugin is deleted via the Plugins screen (not on
 * deactivation), so settings persist across normal deactivate/reactivate.
 *
 * @package AltTextManager
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'atm_settings' );
delete_transient( 'atm_used_images_scan' );
