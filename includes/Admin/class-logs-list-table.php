<?php
/**
 * Logs List Table class.
 *
 * @package SafeComms
 */

namespace SafeComms\Admin;

use SafeComms\Database\Logs_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class Logs_List_Table
 */
class Logs_List_Table extends \WP_List_Table {

	/**
	 * Logs repository.
	 *
	 * @var Logs_Repository
	 */
	private Logs_Repository $repo;

	/**
	 * Filters.
	 *
	 * @var array
	 */
	private array $filters;

	/**
	 * Constructor.
	 *
	 * @param Logs_Repository $repo    Logs repository.
	 * @param array           $filters Filters.
	 */
	public function __construct( Logs_Repository $repo, array $filters = array() ) {
		parent::__construct(
			array(
				'singular' => 'safecomms_log',
				'plural'   => 'safecomms_logs',
				'ajax'     => false,
			)
		);

		$this->repo    = $repo;
		$this->filters = $filters;
	}

	/**
	 * Get columns.
	 *
	 * @return array
	 */
	public function get_columns(): array {
		return array(
			'type'       => __( 'Type', 'safecomms' ),
			'severity'   => __( 'Severity', 'safecomms' ),
			'message'    => __( 'Message', 'safecomms' ),
			'created_at' => __( 'Created', 'safecomms' ),
		);
	}

	/**
	 * Get sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns(): array {
		return array();
	}

	/**
	 * Prepare items.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$columns               = $this->get_columns();
		$hidden                = array();
		$sortable              = $this->get_sortable_columns();
		$this->_column_headers = array( $columns, $hidden, $sortable );

		$per_page     = $this->get_items_per_page( 'safecomms_logs_per_page', 20 );
		$current_page = $this->get_pagenum();

		$result      = $this->repo->fetch( $current_page, $per_page, $this->filters );
		$this->items = $result['rows'];

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Message column handler with Request ID support.
	 *
	 * @param array $item Item data.
	 * @return string
	 */
	protected function column_message( array $item ): string {
		$message = esc_html( (string) $item['message'] );
		$context = json_decode( (string) $item['context'], true );

		if ( ! empty( $context['request_id'] ) ) {
			/* translators: %s: The request ID from SafeComms API */
			$message .= ' <span class="description" style="display:block; color: #888;">' . sprintf( esc_html__( 'Request ID: %s', 'safecomms' ), '<code>' . esc_html( $context['request_id'] ) . '</code>' ) . '</span>';
		}

		return $message;
	}

	/**
	 * Default column handler.
	 *
	 * @param array  $item        Item data.
	 * @param string $column_name Column name.
	 * @return string
	 */
	protected function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'type':
			case 'severity':
			case 'created_at':
				return esc_html( (string) $item[ $column_name ] );
			default:
				return '';
		}
	}
}
