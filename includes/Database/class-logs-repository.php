<?php
/**
 * Logs repository.
 *
 * @package SafeComms
 */

namespace SafeComms\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handle log persistence.
 */
class Logs_Repository {

	/**
	 * Insert a log record.
	 *
	 * @param string      $type     Log type.
	 * @param string      $severity Severity level.
	 * @param string      $message  Message text.
	 * @param array       $context  Context payload.
	 * @param string|null $ref_type Reference type.
	 * @param int|null    $ref_id   Reference ID.
	 *
	 * @return void
	 */
	public function insert( string $type, string $severity, string $message, array $context = array(), ?string $ref_type = null, ?int $ref_id = null ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'safecomms_logs';
		$wpdb->insert(
			$table,
			array(
				'type'       => $type,
				'ref_type'   => $ref_type,
				'ref_id'     => $ref_id,
				'severity'   => $severity,
				'message'    => $message,
				'context'    => wp_json_encode( $context ),
				'created_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Fetch paginated logs.
	 *
	 * @param int   $page     Page number.
	 * @param int   $per_page Items per page.
	 * @param array $filters  Filters.
	 *
	 * @return array{rows: array<int, array>, total: int}
	 */
	public function fetch( int $page = 1, int $per_page = 20, array $filters = array() ): array {
		global $wpdb;
		$table  = $wpdb->prefix . 'safecomms_logs';
		$offset = ( $page - 1 ) * $per_page;

		$where_sql = '1=1';
		if ( ! empty( $filters['severity'] ) ) {
			$where_sql .= $wpdb->prepare( ' AND severity = %s', sanitize_text_field( $filters['severity'] ) );
		}

		if ( ! empty( $filters['type'] ) ) {
			$where_sql .= $wpdb->prepare( ' AND type = %s', sanitize_text_field( $filters['type'] ) );
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name and prepared where clause are trusted.
				"SELECT SQL_CALC_FOUND_ROWS * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);
		$total = (int) $wpdb->get_var( 'SELECT FOUND_ROWS()' );

		return array(
			'rows'  => $rows,
			'total' => $total,
		);
	}
}
