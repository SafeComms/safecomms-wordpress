<?php
/**
 * Plugin class.
 *
 * @package SafeComms
 */

namespace SafeComms;

use SafeComms\Admin\Settings;
use SafeComms\API\Client;
use SafeComms\Admin\Admin_Pages;
use SafeComms\Database\Installer;
use SafeComms\Database\Logs_Repository;
use SafeComms\Database\Moderation_Repository;
use SafeComms\Logging\Logger;
use SafeComms\Scan\Retry_Queue;
use SafeComms\Scan\Scan_Flow;
use SafeComms\Shortcode\Status_Shortcode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Plugin
 */
class Plugin {

	/**
	 * Instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Scan flow.
	 *
	 * @var Scan_Flow
	 */
	private Scan_Flow $scan_flow;

	/**
	 * Client.
	 *
	 * @var Client
	 */
	private Client $client;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Retry queue.
	 *
	 * @var Retry_Queue
	 */
	private Retry_Queue $retry_queue;

	/**
	 * Logs repository.
	 *
	 * @var Logs_Repository
	 */
	private Logs_Repository $logs_repository;

	/**
	 * Moderation repository.
	 *
	 * @var Moderation_Repository
	 */
	private Moderation_Repository $moderation_repository;

	/**
	 * Admin pages.
	 *
	 * @var Admin_Pages
	 */
	private Admin_Pages $admin_pages;

	/**
	 * Status shortcode.
	 *
	 * @var Status_Shortcode
	 */
	private Status_Shortcode $status_shortcode;

	/**
	 * Get instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize.
	 *
	 * @return void
	 */
	public function init(): void {
		$this->logs_repository       = new Logs_Repository();
		$this->moderation_repository = new Moderation_Repository();
		$this->logger                = new Logger( $this->logs_repository );
		$this->settings              = new Settings();
		$this->client                = new Client( $this->settings, $this->logger );
		$this->settings->set_client( $this->client );
		$this->retry_queue      = new Retry_Queue( $this->settings, $this->client, $this->logger, $this->moderation_repository );
		$this->scan_flow        = new Scan_Flow( $this->settings, $this->client, $this->logger, $this->retry_queue, $this->moderation_repository );
		$this->admin_pages      = new Admin_Pages( $this->settings, $this->moderation_repository, $this->logs_repository, $this->client, $this->logger );
		$this->status_shortcode = new Status_Shortcode();

		add_action( 'plugins_loaded', array( $this, 'on_plugins_loaded' ) );
	}

	/**
	 * Activate.
	 *
	 * @return void
	 */
	public static function activate(): void {
		if ( ! self::meets_requirements() ) {
			deactivate_plugins( plugin_basename( SAFECOMMS_PLUGIN_FILE ) );
			wp_die( esc_html__( 'SafeComms requires PHP 8.0+ and WordPress 5.8+.', 'safecomms' ) );
		}

		Installer::install();
	}

	/**
	 * Deactivate.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		// Stop background retries and clean transient flags.
		wp_clear_scheduled_hook( 'safecomms_retry_item' );
		delete_option( 'safecomms_quota_exceeded' );
	}

	/**
	 * Uninstall.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		global $wpdb;

		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		// Remove custom tables with proper table name validation.
		$moderation_table = $wpdb->prefix . 'safecomms_moderation';
		$logs_table       = $wpdb->prefix . 'safecomms_logs';

		// Verify tables exist before dropping.
		$table_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
				$moderation_table
			)
		);
		if ( $table_exists ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS {$moderation_table}" );
		}

		$table_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
				$logs_table
			)
		);
		if ( $table_exists ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS {$logs_table}" );
		}

		// Remove all post meta.
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE 'safecomms_%' OR meta_key = '_safecomms_intended_status'" );

		// Remove all comment meta.
		$wpdb->query( "DELETE FROM {$wpdb->commentmeta} WHERE meta_key LIKE 'safecomms_%'" );

		// Remove options.
		delete_option( Settings::OPTION_KEY );
		delete_option( 'safecomms_notice_baseline' );
		delete_option( 'safecomms_quota_exceeded' );

		// Remove network options if multisite.
		if ( is_multisite() ) {
			delete_site_option( 'safecomms_network_options' );
		}

		// Clear all related transients (pattern-based).
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_safecomms_%' OR option_name LIKE '_transient_timeout_safecomms_%'" );

		// Clear scheduled events.
		wp_clear_scheduled_hook( 'safecomms_retry_item' );

		// Clear cache.
		wp_cache_flush();
	}

	/**
	 * Check if requirements are met.
	 *
	 * @return bool
	 */
	private static function meets_requirements(): bool {
		global $wp_version;

		$php_ok = version_compare( PHP_VERSION, '8.0', '>=' );
		$wp_ok  = isset( $wp_version ) && version_compare( $wp_version, '5.8', '>=' );

		// Tested and compatible up to WordPress 6.9.
		return $php_ok && $wp_ok;
	}

	/**
	 * On plugins loaded.
	 *
	 * @return void
	 */
	public function on_plugins_loaded(): void {
		load_plugin_textdomain( 'safecomms', false, dirname( plugin_basename( SAFECOMMS_PLUGIN_FILE ) ) . '/languages' );

		if ( ! self::meets_requirements() ) {
			add_action( 'admin_notices', array( $this, 'render_requirement_notice' ) );
			return;
		}

		$this->settings->register();
		$this->retry_queue->register();
		$this->scan_flow->register();
		$this->admin_pages->register();
		$this->status_shortcode->register();
	}

	/**
	 * Render requirement notice.
	 *
	 * @return void
	 */
	public function render_requirement_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>' . esc_html__( 'SafeComms requires PHP 8.0+ and WordPress 5.8+. Please upgrade your environment.', 'safecomms' ) . '</p></div>';
	}
}
