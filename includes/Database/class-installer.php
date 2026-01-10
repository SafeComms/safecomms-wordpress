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
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			ref_type varchar(20) NOT NULL,
			ref_id bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL,
			score float NULL,
			reason text NULL,
			details longtext NULL,
			content_hash char(32) NULL,
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY ref_idx (ref_type, ref_id),
			KEY status_idx (status),
			KEY updated_idx (updated_at)
		) {$charset_collate};";

		$logs_sql = "CREATE TABLE {$logs_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(30) NOT NULL,
			ref_type varchar(20) NULL,
			ref_id bigint(20) unsigned NULL,
			severity varchar(20) NOT NULL,
			message text NOT NULL,
			context longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY type_idx (type),
			KEY ref_idx (ref_type, ref_id),
			KEY created_idx (created_at)
		) {$charset_collate};";

		dbDelta( $moderation_sql );
		dbDelta( $logs_sql );
	}
}
