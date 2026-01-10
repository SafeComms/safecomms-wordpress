<?php
/**
 * Moderation List Table class.
 *
 * @package SafeComms
 */

namespace SafeComms\Admin;

use SafeComms\Database\Moderation_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class Moderation_List_Table
 */
class Moderation_List_Table extends \WP_List_Table {

	/**
	 * Moderation repository.
	 *
	 * @var Moderation_Repository
	 */
	private Moderation_Repository $repo;

	/**
	 * Filters.
	 *
	 * @var array
	 */
	private array $filters;

	/**
	 * Constructor.
	 *
	 * @param Moderation_Repository $repo    Moderation repository.
	 * @param array                 $filters Filters.
	 */
	public function __construct( Moderation_Repository $repo, array $filters = array() ) {
		parent::__construct(
			array(
				'singular' => 'safecomms_entry',
				'plural'   => 'safecomms_entries',
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
			'cb'            => '<input type="checkbox" />',
			'ref_type'      => __( 'Type', 'safecomms' ),
			'ref_id'        => __( 'Reference', 'safecomms' ),
			'status'        => __( 'Status', 'safecomms' ),
			'modifications' => __( 'Modifications', 'safecomms' ),
			'score'         => __( 'Score', 'safecomms' ),
			'reason'        => __( 'Reason', 'safecomms' ),
			'updated_at'    => __( 'Updated', 'safecomms' ),
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
	 * Get bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions(): array {
		return array(
			'safecomms_rescan' => __( 'Re-scan', 'safecomms' ),
			'safecomms_allow'  => __( 'Mark as allowed', 'safecomms' ),
		);
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $item Item data.
	 * @return string
	 */
	protected function column_cb( $item ): string {
		return '<input type="checkbox" name="entry[]" value="' . esc_attr( $item['id'] ) . '" />';
	}

	/**
	 * Reference ID column.
	 *
	 * @param array $item Item data.
	 * @return string
	 */
	protected function column_ref_id( $item ): string {
		$title = $this->resolve_title( $item );
		return esc_html( $title ) . ' (#' . esc_html( $item['ref_id'] ) . ')';
	}

	/**
	 * Status column.
	 *
	 * @param array $item Item data.
	 * @return string
	 */
	protected function column_status( $item ): string {
		$status = esc_html( $item['status'] );
		if ( 'block' === $status ) {
			$status = '<span style="color:#d63638;">' . $status . '</span>';
		}

		$actions = array(
			'rescan' => '<a href="' . esc_url( $this->action_url( 'rescan', $item ) ) . '">' . esc_html__( 'Re-scan', 'safecomms' ) . '</a>',
			'allow'  => '<a href="' . esc_url( $this->action_url( 'allow', $item ) ) . '">' . esc_html__( 'Allow', 'safecomms' ) . '</a>',
		);

		return $status . ' ' . $this->row_actions( $actions );
	}

	/**
	 * Modifications column.
	 *
	 * @param array $item Item data.
	 * @return string
	 */
	protected function column_modifications( $item ): string {
		if ( empty( $item['details'] ) ) {
			return '-';
		}

		$details = json_decode( $item['details'], true );
		if ( ! is_array( $details ) ) {
			return '-';
		}

		$mods = array();
		if ( ! empty( $details['addons']['replacedUnsafe'] ) ) {
			$mods[] = __( 'Text Replacement', 'safecomms' );
		}
		if ( ! empty( $details['addons']['replacedPii'] ) ) {
			$mods[] = __( 'PII Redaction', 'safecomms' );
		}

		if ( empty( $mods ) ) {
			return '-';
		}

		return implode( ', ', $mods );
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
			case 'ref_type':
			case 'reason':
			case 'updated_at':
				return esc_html( (string) $item[ $column_name ] );
			case 'score':
				return esc_html( (string) ( $item['score'] ?? '' ) );
			default:
				return '';
		}
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

		$per_page     = $this->get_items_per_page( 'safecomms_moderation_per_page', 20 );
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
	 * Get action URL.
	 *
	 * @param string $action Action name.
	 * @param array  $item   Item data.
	 * @return string
	 */
	private function action_url( string $action, array $item ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'page'      => 'safecomms_moderation',
					'sc_action' => $action,
					'entry_id'  => $item['id'],
				),
				admin_url( 'admin.php' )
			),
			'safecomms_action'
		);
	}

	/**
	 * Resolve title.
	 *
	 * @param array $item Item data.
	 * @return string
	 */
	private function resolve_title( array $item ): string {
		if ( 'post' === $item['ref_type'] ) {
			$post = get_post( (int) $item['ref_id'] );
			return $post ? $post->post_title : __( '(Post)', 'safecomms' );
		}

		if ( 'comment' === $item['ref_type'] ) {
			$comment = get_comment( (int) $item['ref_id'] );
			return $comment ? wp_trim_words( $comment->comment_content, 8 ) : __( '(Comment)', 'safecomms' );
		}

		return __( '(Unknown)', 'safecomms' );
	}
}
