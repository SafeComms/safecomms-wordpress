<?php
namespace SafeComms;

use SafeComms\Admin\Settings;
use SafeComms\API\Client;
use SafeComms\Admin\AdminPages;
use SafeComms\Database\Installer;
use SafeComms\Database\LogsRepository;
use SafeComms\Database\ModerationRepository;
use SafeComms\Logging\Logger;
use SafeComms\Scan\RetryQueue;
use SafeComms\Scan\ScanFlow;
use SafeComms\Shortcode\StatusShortcode;

if (!defined('ABSPATH')) {
    exit;
}

class Plugin
{
    private static ?Plugin $instance = null;

    private Settings $settings;
    private ScanFlow $scanFlow;
    private Client $client;
    private Logger $logger;
    private RetryQueue $retryQueue;
    private LogsRepository $logsRepository;
    private ModerationRepository $moderationRepository;
    private AdminPages $adminPages;
    private StatusShortcode $statusShortcode;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function init(): void
    {
        $this->logsRepository = new LogsRepository();
        $this->moderationRepository = new ModerationRepository();
        $this->logger = new Logger($this->logsRepository);
        $this->settings = new Settings($this->logger);
        $this->client = new Client($this->settings, $this->logger);
        $this->settings->set_client($this->client);
        $this->retryQueue = new RetryQueue($this->settings, $this->client, $this->logger, $this->moderationRepository);
        $this->scanFlow = new ScanFlow($this->settings, $this->client, $this->logger, $this->retryQueue, $this->moderationRepository);
        $this->adminPages = new AdminPages($this->settings, $this->moderationRepository, $this->logsRepository, $this->client, $this->logger);
        $this->statusShortcode = new StatusShortcode();

        add_action('plugins_loaded', [$this, 'on_plugins_loaded']);
    }

    public static function activate(): void
    {
        if (!self::meets_requirements()) {
            deactivate_plugins(plugin_basename(SAFECOMMS_PLUGIN_FILE));
            wp_die(esc_html__('SafeComms requires PHP 8.0+ and WordPress 5.8+.', 'safecomms'));
        }

        Installer::install();
    }

    public static function deactivate(): void
    {
        // Stop background retries and clean transient flags
        wp_clear_scheduled_hook('safecomms_retry_item');
        delete_option('safecomms_quota_exceeded');
    }

    public static function uninstall(): void
    {
        global $wpdb;

        if (!current_user_can('activate_plugins')) {
            return;
        }

        // Remove custom tables with proper table name validation
        $moderation_table = $wpdb->prefix . 'safecomms_moderation';
        $logs_table = $wpdb->prefix . 'safecomms_logs';
        
        // Verify tables exist before dropping
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
            $moderation_table
        ));
        if ($table_exists) {
            $wpdb->query("DROP TABLE IF EXISTS {$moderation_table}");
        }
        
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
            $logs_table
        ));
        if ($table_exists) {
            $wpdb->query("DROP TABLE IF EXISTS {$logs_table}");
        }

        // Remove all post meta
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE 'safecomms_%' OR meta_key = '_safecomms_intended_status'");
        
        // Remove all comment meta
        $wpdb->query("DELETE FROM {$wpdb->commentmeta} WHERE meta_key LIKE 'safecomms_%'");

        // Remove options
        delete_option(Settings::OPTION_KEY);
        delete_option('safecomms_notice_baseline');
        delete_option('safecomms_quota_exceeded');
        
        // Remove network options if multisite
        if (is_multisite()) {
            delete_site_option('safecomms_network_options');
        }

        // Clear all related transients (pattern-based)
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_safecomms_%' OR option_name LIKE '_transient_timeout_safecomms_%'");
        
        // Clear scheduled events
        wp_clear_scheduled_hook('safecomms_retry_item');
        
        // Clear cache
        wp_cache_flush();
    }

    private static function meets_requirements(): bool
    {
        global $wp_version;

        $php_ok = version_compare(PHP_VERSION, '8.0', '>=');
        $wp_ok = isset($wp_version) && version_compare($wp_version, '5.8', '>=');
        
        // Tested and compatible up to WordPress 6.9
        return $php_ok && $wp_ok;
    }

    public function on_plugins_loaded(): void
    {
        load_plugin_textdomain('safecomms', false, dirname(plugin_basename(SAFECOMMS_PLUGIN_FILE)) . '/languages');

        if (!self::meets_requirements()) {
            add_action('admin_notices', [$this, 'render_requirement_notice']);
            return;
        }

        $this->settings->register();
        $this->retryQueue->register();
        $this->scanFlow->register();
        $this->adminPages->register();
        $this->statusShortcode->register();
    }

    public function render_requirement_notice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        echo '<div class="notice notice-error"><p>' . esc_html__('SafeComms requires PHP 8.0+ and WordPress 5.8+. Please upgrade your environment.', 'safecomms') . '</p></div>';
    }
}
