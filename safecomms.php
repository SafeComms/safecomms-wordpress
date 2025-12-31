<?php
/**
 * Plugin Name: SafeComms Moderation
 * Description: Server-side content moderation for WordPress posts and comments using SafeComms.
 * Version: 0.2.0
 * Requires at least: 5.8
 * Tested up to: 6.9
 * Requires PHP: 8.0
 * Author: SafeComms
 * Author URI: https://safecomms.dev
 * Plugin URI: https://safecomms.dev/docs/integrations/wordpress
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: safecomms
 * Domain Path: /languages
 * Update URI: false
 *
 * @package SafeComms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SAFECOMMS_PLUGIN_VERSION', '0.2.0' );
define( 'SAFECOMMS_PLUGIN_FILE', __FILE__ );
define( 'SAFECOMMS_PLUGIN_DIR', __DIR__ . '/' );
define( 'SAFECOMMS_PLUGIN_URL', plugins_url( '/', __FILE__ ) );

require_once SAFECOMMS_PLUGIN_DIR . 'includes/class-autoloader.php';
SafeComms\Autoloader::register();

register_activation_hook( SAFECOMMS_PLUGIN_FILE, array( SafeComms\Plugin::class, 'activate' ) );
register_deactivation_hook( SAFECOMMS_PLUGIN_FILE, array( SafeComms\Plugin::class, 'deactivate' ) );
register_uninstall_hook( SAFECOMMS_PLUGIN_FILE, array( SafeComms\Plugin::class, 'uninstall' ) );

/**
 * Bootstrap the SafeComms plugin.
 *
 * @return void
 */
function safecomms_bootstrap(): void {
	$plugin = SafeComms\Plugin::instance();
	$plugin->init();
}

safecomms_bootstrap();
