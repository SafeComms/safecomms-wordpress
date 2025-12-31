<?php
/**
 * Moderation repository.
 *
 * @package SafeComms
 */

namespace SafeComms\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handle moderation records.
 */
class Moderation_Repository {

	/**
	 * Insert or update moderation decision.
	 *
	 * @param string $ref_type     Reference type.
	 * @param int    $ref_id       Reference ID.
	 * @param array  $decision     Decision payload.
	 * @param string $content_hash Content hash.
	 * @param int    $attempts     Retry attempts.
	 *
	 * @return void
	 */
	public function upsert( string $ref_type, int $ref_id, array $decision, string $content_hash = '', int $attempts = 0 ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'safecomms_moderation';

		$data = array(
			'ref_type'     => $ref_type,
			'ref_id'       => $ref_id,
			'status'       => $decision['status'] ?? 'unknown',
			'score'        => $decision['score'] ?? null,
			'reason'       => $decision['reason'] ?? '',
			'details'      => isset( $decision['details'] ) ? wp_json_encode( $decision['details'] ) : null,
			'content_hash' => $content_hash,
			'attempts'     => $attempts,
			'updated_at'   => current_time( 'mysql' ),
		);

		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from trusted prefix.
				"SELECT id FROM {$table} WHERE ref_type = %s AND ref_id = %d LIMIT 1",
				$ref_type,
				$ref_id
			)
		);

		if ( $existing_id ) {
			$wpdb->update(
				$table,
				$data,
				array( 'id' => $existing_id ),
				null,
				array( '%d' )
			);
		} else {
			$data['created_at'] = current_time( 'mysql' );
			$wpdb->insert( $table, $data );
		}

		// Invalidate blocked count cache.
		wp_cache_delete( 'safecomms_blocked_count', 'safecomms' );
	}

	/**
	 * Get cached blocked count.
	 *
	 * @return int
	 */
	public function blocked_count(): int {
		$count = wp_cache_get( 'safecomms_blocked_count', 'safecomms' );
		if ( false !== $count ) {
			return (int) $count;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'safecomms_moderation';
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from trusted prefix.
				"SELECT COUNT(*) FROM {$table} WHERE status = %s",
				'block'
			)
		);

		wp_cache_set( 'safecomms_blocked_count', $count, 'safecomms', 300 );
		return $count;
	}

	/**
	 * Fetch paginated moderation records.
	 *
	 * @param int   $page     Page number.
	 * @param int   $per_page Items per page.
	 * @param array $filters  Filters.
	 *
	 * @return array{rows: array<int, array>, total: int}
	 */
	public function fetch( int $page = 1, int $per_page = 20, array $filters = array() ): array {
		global $wpdb;
		$table  = $wpdb->prefix . 'safecomms_moderation';
		$offset = ( $page - 1 ) * $per_page;

		$where_sql = '1=1';
		if ( ! empty( $filters['status'] ) ) {
			$where_sql .= $wpdb->prepare( ' AND status = %s', sanitize_text_field( $filters['status'] ) );
		}

		if ( ! empty( $filters['ref_type'] ) ) {
			$where_sql .= $wpdb->prepare( ' AND ref_type = %s', sanitize_text_field( $filters['ref_type'] ) );
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name and prepared where clause are trusted.
				"SELECT SQL_CALC_FOUND_ROWS * FROM {$table} WHERE {$where_sql} ORDER BY updated_at DESC LIMIT %d OFFSET %d",
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

	/**
	 * Find a moderation record.
	 *
	 * @param int $id Record ID.
	 *
	 * @return array|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'safecomms_moderation';
		$row   = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from trusted prefix.
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
			ARRAY_A
		);
		return $row ? $row : null;
	}
}
