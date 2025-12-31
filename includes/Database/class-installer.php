<?php
/**
 * Installer class.
 *
 * @package SafeComms
 */

namespace SafeComms\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Installer
 */
class Installer {

	/**
	 * Install database tables.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		$charset_collate  = $wpdb->get_charset_collate();
		$moderation_table = $wpdb->prefix . 'safecomms_moderation';
		$logs_table       = $wpdb->prefix . 'safecomms_logs';

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$moderation_sql = "CREATE TABLE {$moderation_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ref_type VARCHAR(20) NOT NULL,
            ref_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL,
            score FLOAT NULL,
            reason TEXT NULL,
            details LONGTEXT NULL,
            content_hash CHAR(32) NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY ref_idx (ref_type, ref_id),
            KEY status_idx (status),
            KEY updated_idx (updated_at)
        ) {$charset_collate};";

		$logs_sql = "CREATE TABLE {$logs_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            type VARCHAR(30) NOT NULL,
            ref_type VARCHAR(20) NULL,
            ref_id BIGINT UNSIGNED NULL,
            severity VARCHAR(20) NOT NULL,
            message TEXT NOT NULL,
            context LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY type_idx (type),
            KEY ref_idx (ref_type, ref_id),
            KEY created_idx (created_at)
        ) {$charset_collate};";

		dbDelta( $moderation_sql );
		dbDelta( $logs_sql );
	}
}
