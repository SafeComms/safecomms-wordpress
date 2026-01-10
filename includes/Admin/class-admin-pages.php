<?php
/**
 * Admin Pages Handler
 *
 * @package SafeComms
 */

namespace SafeComms\Admin;

use SafeComms\Admin\Logs_List_Table;
use SafeComms\Admin\Moderation_List_Table;
use SafeComms\API\Client;
use SafeComms\Admin\Settings;
use SafeComms\Database\Moderation_Repository;
use SafeComms\Database\Logs_Repository;
use SafeComms\Logging\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin_Pages
 *
 * Handles the administration pages for the plugin.
 *
 * @package SafeComms\Admin
 */
class Admin_Pages {

	/**
	 * Settings instance.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Moderation repository instance.
	 *
	 * @var Moderation_Repository
	 */
	private Moderation_Repository $moderation_repository;

	/**
	 * Logs repository instance.
	 *
	 * @var Logs_Repository
	 */
	private Logs_Repository $logs_repository;

	/**
	 * API Client instance.
	 *
	 * @var Client
	 */
	private Client $client;

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Admin_Pages constructor.
	 *
	 * @param Settings              $settings              Settings instance.
	 * @param Moderation_Repository $moderation_repository Moderation repository instance.
	 * @param Logs_Repository       $logs_repository       Logs repository instance.
	 * @param Client                $client                API Client instance.
	 * @param Logger                $logger                Logger instance.
	 */
	public function __construct( Settings $settings, Moderation_Repository $moderation_repository, Logs_Repository $logs_repository, Client $client, Logger $logger ) {
		$this->settings              = $settings;
		$this->moderation_repository = $moderation_repository;
		$this->logs_repository       = $logs_repository;
		$this->client                = $client;
		$this->logger                = $logger;
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
		add_action( 'admin_notices', array( $this, 'render_block_notice' ) );
		add_action( 'admin_notices', array( $this, 'render_checklist_notice' ) );
	}

	/**
	 * Register admin menu pages.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'SafeComms Moderation', 'safecomms' ),
			__( 'SafeComms', 'safecomms' ),
			'manage_options',
			'safecomms_moderation',
			array( $this, 'render_moderation_page' ),
			'dashicons-shield-alt'
		);

		add_submenu_page(
			'safecomms_moderation',
			__( 'Moderation Queue', 'safecomms' ),
			__( 'Moderation', 'safecomms' ),
			'manage_options',
			'safecomms_moderation',
			array( $this, 'render_moderation_page' )
		);

		add_submenu_page(
			'safecomms_moderation',
			__( 'Logs', 'safecomms' ),
			__( 'Logs', 'safecomms' ),
			'manage_options',
			'safecomms_logs',
			array( $this, 'render_logs_page' )
		);

		add_submenu_page(
			'safecomms_moderation',
			__( 'Settings', 'safecomms' ),
			__( 'Settings', 'safecomms' ),
			'manage_options',
			'safecomms_settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		$this->settings->render_page();
	}

	/**
	 * Render the moderation queue page.
	 *
	 * @return void
	 */
	public function render_moderation_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$filters = array();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['status'] ) ) {
			$filters['status'] = sanitize_text_field( wp_unslash( $_GET['status'] ) );
		}
		if ( ! empty( $_GET['ref_type'] ) ) {
			$filters['ref_type'] = sanitize_text_field( wp_unslash( $_GET['ref_type'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$table = new Moderation_List_Table( $this->moderation_repository, $filters );
		$table->prepare_items();

		echo '<div class="wrap"><h1>' . esc_html__( 'SafeComms Moderation', 'safecomms' ) . '</h1>';
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="safecomms_moderation" />';
		echo '<select name="status">';
		echo '<option value="">' . esc_html__( 'All statuses', 'safecomms' ) . '</option>';
		echo '<option value="block"' . selected( ( $filters['status'] ?? '' ) === 'block', true, false ) . '>' . esc_html__( 'Blocked', 'safecomms' ) . '</option>';
		echo '<option value="allow"' . selected( ( $filters['status'] ?? '' ) === 'allow', true, false ) . '>' . esc_html__( 'Allowed', 'safecomms' ) . '</option>';
		echo '</select> ';
		echo '<select name="ref_type">';
		echo '<option value="">' . esc_html__( 'All types', 'safecomms' ) . '</option>';
		echo '<option value="post"' . selected( ( $filters['ref_type'] ?? '' ) === 'post', true, false ) . '>' . esc_html__( 'Posts', 'safecomms' ) . '</option>';
		echo '<option value="comment"' . selected( ( $filters['ref_type'] ?? '' ) === 'comment', true, false ) . '>' . esc_html__( 'Comments', 'safecomms' ) . '</option>';
		echo '</select> ';
		submit_button( __( 'Filter', 'safecomms' ), 'secondary', '', false );
		echo '</form>';

		echo '<form method="post">';
		wp_nonce_field( 'bulk-safecomms_entries' );
		$table->display();
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Render the logs page.
	 *
	 * @return void
	 */
	public function render_logs_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$filters = array();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['severity'] ) ) {
			$filters['severity'] = sanitize_text_field( wp_unslash( $_GET['severity'] ) );
		}
		if ( ! empty( $_GET['type'] ) ) {
			$filters['type'] = sanitize_text_field( wp_unslash( $_GET['type'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$table = new Logs_List_Table( $this->logs_repository, $filters );
		$table->prepare_items();

		echo '<div class="wrap"><h1>' . esc_html__( 'SafeComms Logs', 'safecomms' ) . '</h1>';
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="safecomms_logs" />';
		echo '<select name="severity">';
		echo '<option value="">' . esc_html__( 'All severities', 'safecomms' ) . '</option>';
		foreach ( array( 'error', 'warning', 'info', 'debug' ) as $level ) {
			$selected = selected( ( $filters['severity'] ?? '' ) === $level, true, false );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<option value="' . esc_attr( $level ) . '" ' . $selected . '>' . esc_html( ucfirst( $level ) ) . '</option>';
		}
		echo '</select> ';
		submit_button( __( 'Filter', 'safecomms' ), 'secondary', '', false );
		echo '</form>';

		$table->display();
		echo '</div>';
	}

	/**
	 * Handle admin actions.
	 *
	 * @return void
	 */
	public function handle_actions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! empty( $_GET['sc_action'] ) && ! empty( $_GET['entry_id'] ) ) {
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'safecomms_action' ) ) {
				wp_die(
					esc_html__( 'Security check failed. Please try again.', 'safecomms' ),
					esc_html__( 'Security Error', 'safecomms' ),
					array( 'response' => 403 )
				);
			}

			$action   = sanitize_text_field( wp_unslash( $_GET['sc_action'] ) );
			$entry_id = (int) $_GET['entry_id'];
			$this->handle_entry_action( $action, $entry_id );
		}

		$bulk_action = isset( $_POST['action'] ) ? wp_unslash( $_POST['action'] ) : ( isset( $_POST['action2'] ) ? wp_unslash( $_POST['action2'] ) : '' );
		if ( ! empty( $bulk_action ) && ! empty( $_POST['entry'] ) ) {
			check_admin_referer( 'bulk-safecomms_entries' );
			$action  = sanitize_text_field( $bulk_action );
			$entries = array_map( 'intval', wp_unslash( (array) $_POST['entry'] ) );
			foreach ( $entries as $entry_id ) {
				if ( $entry_id > 0 ) {
					$this->handle_entry_action( str_replace( 'safecomms_', '', $action ), $entry_id );
				}
			}
		}

		if ( ! empty( $_GET['safecomms_reset_notice'] ) ) {
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'safecomms_action' ) ) {
				return;
			}
			update_option( 'safecomms_notice_baseline', $this->moderation_repository->blocked_count() );
			wp_safe_redirect( remove_query_arg( array( 'safecomms_reset_notice', '_wpnonce' ) ) );
			exit;
		}

		if ( ! empty( $_GET['safecomms_dismiss_checklist'] ) ) {
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'safecomms_action' ) ) {
				return;
			}
			update_user_meta( get_current_user_id(), 'safecomms_checklist_dismissed', 1 );
			wp_safe_redirect( remove_query_arg( array( 'safecomms_dismiss_checklist', '_wpnonce' ) ) );
			exit;
		}
	}

	/**
	 * Handle entry action.
	 *
	 * @param string $action   Action to perform.
	 * @param int    $entry_id Entry ID.
	 * @return void
	 */
	private function handle_entry_action( string $action, int $entry_id ): void {
		$entry = $this->moderation_repository->find( $entry_id );
		if ( ! $entry ) {
			return;
		}

		if ( 'allow' === $action ) {
			$this->mark_allowed( $entry );
			return;
		}

		if ( 'rescan' === $action ) {
			$this->manual_rescan( $entry );
		}
	}

	/**
	 * Manually rescan an entry.
	 *
	 * @param array $entry Entry data.
	 * @return void
	 */
	private function manual_rescan( array $entry ): void {
		$ref_type = $entry['ref_type'];
		$ref_id   = (int) $entry['ref_id'];

		if ( 'post' === $ref_type ) {
			$post = get_post( $ref_id );
			if ( ! $post ) {
				return;
			}

			$body_profile  = $this->settings->get( 'profile_post_body', '' );
			$title_profile = $this->settings->get( 'profile_post_title', '' );

			$decisions = array();

			if ( $this->settings->get( 'enable_post_body_scan', false ) && '' !== $post->post_content ) {
				$decisions['body'] = $this->client->scan_content(
					(string) $post->post_content,
					array(
						'type'    => 'post',
						'field'   => 'post_content',
						'post_id' => $ref_id,
						'title'   => $post->post_title,
						'author'  => $post->post_author,
					),
					$body_profile ? $body_profile : null
				);

				if ( ! empty( $decisions['body']['details']['safeContent'] ) ) {
					$post->post_content = (string) $decisions['body']['details']['safeContent'];
					wp_update_post(
						array(
							'ID'           => $post->ID,
							'post_content' => $post->post_content,
						)
					);
				}
			}

			if ( $this->settings->get( 'enable_post_title_scan', false ) && '' !== $post->post_title ) {
				$decisions['title'] = $this->client->scan_content(
					(string) $post->post_title,
					array(
						'type'    => 'post',
						'field'   => 'post_title',
						'post_id' => $ref_id,
						'title'   => $post->post_title,
						'author'  => $post->post_author,
					),
					$title_profile ? $title_profile : null
				);

				if ( ! empty( $decisions['title']['details']['safeContent'] ) ) {
					$post->post_title = (string) $decisions['title']['details']['safeContent'];
					wp_update_post(
						array(
							'ID'         => $post->ID,
							'post_title' => $post->post_title,
						)
					);
				}
			}

			$final_decision = $this->aggregate_decisions( array_values( $decisions ) );
			$this->apply_post_decision( $post, $final_decision );
			return;
		}

		if ( 'comment' === $ref_type ) {
			$comment = get_comment( $ref_id );
			if ( ! $comment ) {
				return;
			}
			$profile  = $this->settings->get( 'profile_comment_body', '' );
			$decision = $this->client->scan_content(
				(string) $comment->comment_content,
				array(
					'type'       => 'comment',
					'post_id'    => (int) $comment->comment_post_ID,
					'comment_id' => $ref_id,
					'author'     => $comment->comment_author,
				),
				$profile ? $profile : null
			);
			$this->apply_comment_decision( $comment, $decision );
		}
	}

	/**
	 * Aggregate decisions from multiple scans.
	 *
	 * @param array $decisions Array of decisions.
	 * @return array
	 */
	private function aggregate_decisions( array $decisions ): array {
		$final = array(
			'status' => 'allow',
			'reason' => '',
			'score'  => null,
		);

		foreach ( $decisions as $decision ) {
			if ( ! $decision ) {
				continue;
			}

			if ( 'block' === ( $decision['status'] ?? '' ) ) {
				return $decision;
			}

			if ( in_array( $decision['status'] ?? '', array( 'error', 'rate_limited' ), true ) ) {
				$final = $decision;
			} elseif ( 'allow' === $final['status'] ) {
				$final = $decision;
			}
		}

		return $final;
	}

	/**
	 * Mark an entry as allowed.
	 *
	 * @param array $entry Entry data.
	 * @return void
	 */
	private function mark_allowed( array $entry ): void {
		$ref_type = $entry['ref_type'];
		$ref_id   = (int) $entry['ref_id'];

		$decision = array(
			'status' => 'allow',
			'reason' => 'override',
		);

		if ( 'post' === $ref_type ) {
			$post = get_post( $ref_id );
			if ( $post ) {
				$intended_status = get_post_meta( $ref_id, '_safecomms_intended_status', true );
				$new_status      = $intended_status ? $intended_status : 'publish';

				wp_update_post(
					array(
						'ID'          => $ref_id,
						'post_status' => $new_status,
					)
				);
				update_post_meta( $ref_id, 'safecomms_status', 'allow' );
				update_post_meta( $ref_id, 'safecomms_reason', 'override' );
				delete_post_meta( $ref_id, '_safecomms_intended_status' );
			}
		}

		if ( 'comment' === $ref_type ) {
			$comment = get_comment( $ref_id );
			if ( $comment ) {
				wp_set_comment_status( $ref_id, 'approve' );
				update_comment_meta( $ref_id, 'safecomms_status', 'allow' );
				update_comment_meta( $ref_id, 'safecomms_reason', 'override' );
			}
		}

		$this->moderation_repository->upsert( $ref_type, $ref_id, $decision, $entry['content_hash'] ?? '', (int) ( $entry['attempts'] ?? 0 ) );
		$this->logger->info(
			'override',
			'Manual override to allow',
			array(
				'ref_type' => $ref_type,
				'ref_id'   => $ref_id,
			)
		);
	}

	/**
	 * Apply decision to a post.
	 *
	 * @param \WP_Post $post     Post object.
	 * @param array    $decision Decision data.
	 * @return void
	 */
	private function apply_post_decision( \WP_Post $post, array $decision ): void {
		$hash = md5( (string) $post->post_content );
		$this->moderation_repository->upsert( 'post', $post->ID, $decision, $hash );
		update_post_meta( $post->ID, 'safecomms_status', $decision['status'] ?? 'unknown' );
		update_post_meta( $post->ID, 'safecomms_reason', $decision['reason'] ?? '' );
		update_post_meta( $post->ID, 'safecomms_score', $decision['score'] ?? null );
		update_post_meta( $post->ID, 'safecomms_checked_at', current_time( 'mysql' ) );

		if ( 'block' === ( $decision['status'] ?? '' ) ) {
			wp_update_post(
				array(
					'ID'          => $post->ID,
					'post_status' => 'draft',
				)
			);
		}
	}

	/**
	 * Apply decision to a comment.
	 *
	 * @param \WP_Comment $comment  Comment object.
	 * @param array       $decision Decision data.
	 * @return void
	 */
	private function apply_comment_decision( \WP_Comment $comment, array $decision ): void {
		$hash       = md5( (string) $comment->comment_content );
		$comment_id = (int) $comment->comment_ID;

		$this->moderation_repository->upsert( 'comment', $comment_id, $decision, $hash );
		update_comment_meta( $comment_id, 'safecomms_status', $decision['status'] ?? 'unknown' );
		update_comment_meta( $comment_id, 'safecomms_reason', $decision['reason'] ?? '' );
		update_comment_meta( $comment_id, 'safecomms_score', $decision['score'] ?? null );
		update_comment_meta( $comment_id, 'safecomms_checked_at', current_time( 'mysql' ) );

		if ( ( $decision['status'] ?? '' ) === 'block' ) {
			wp_set_comment_status( $comment_id, 'hold' );
		}
	}

	/**
	 * Render block notice.
	 *
	 * @return void
	 */
	public function render_block_notice(): void {
		$user_id = get_current_user_id();
		$reason  = false;

		if ( $user_id ) {
			$reason = get_transient( 'safecomms_block_notice_' . $user_id );
			if ( $reason ) {
				delete_transient( 'safecomms_block_notice_' . $user_id );
			}
		} elseif ( isset( $_COOKIE['safecomms_block_notice'] ) ) {
			$reason = sanitize_text_field( wp_unslash( $_COOKIE['safecomms_block_notice'] ) );
			// Clear cookie.
			$cookie_path   = defined( 'COOKIEPATH' ) ? COOKIEPATH : '/';
			$cookie_domain = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';
			setcookie( 'safecomms_block_notice', '', time() - 3600, $cookie_path, $cookie_domain, is_ssl(), true );
		}

		if ( $reason ) {
			echo '<div class="notice notice-error is-dismissible"><p>' .
				// translators: %s: Block reason.
				sprintf( esc_html__( 'Post saved as draft because it was blocked by SafeComms: %s', 'safecomms' ), esc_html( $reason ) ) .
				'</p></div>';
		}
	}

	/**
	 * Render admin notices.
	 *
	 * @return void
	 */
	public function render_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( get_option( 'safecomms_quota_exceeded' ) ) {
			echo '<div class="notice notice-error">';
			echo '<p>' . esc_html__( 'SafeComms API quota exceeded. Scanning is disabled or failing. Please upgrade your plan.', 'safecomms' ) . '</p>';
			echo '</div>';
		}

		if ( ! $this->settings->get( 'notices_enabled', true ) ) {
			return;
		}

		$baseline = (int) get_option( 'safecomms_notice_baseline', 0 );
		$blocked  = $this->moderation_repository->blocked_count();
		$delta    = max( 0, $blocked - $baseline );

		if ( $delta <= 0 ) {
			return;
		}

		$reset_url = add_query_arg( array( 'safecomms_reset_notice' => 1 ) );
		$reset_url = wp_nonce_url( $reset_url, 'safecomms_action' );

		echo '<div class="notice notice-warning">';
		// translators: %d: Number of blocked items.
		echo '<p>' . esc_html( sprintf( __( 'SafeComms blocked %d items since last reset.', 'safecomms' ), $delta ) ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( $reset_url ) . '">' . esc_html__( 'Reset counter', 'safecomms' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * Render checklist notice for onboarding.
	 *
	 * @return void
	 */
	public function render_checklist_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, 'safecomms_settings' ) ) {
			return;
		}

		if ( get_user_meta( get_current_user_id(), 'safecomms_checklist_dismissed', true ) ) {
			return;
		}

		$dismiss_url = add_query_arg( array( 'safecomms_dismiss_checklist' => 1 ) );
		$dismiss_url = wp_nonce_url( $dismiss_url, 'safecomms_action' );

		$api_key      = $this->settings->get( 'api_key' );
		$auto_approve = $this->settings->get( 'auto_approve_comments' );

		?>
		<div class="notice notice-info safecomms-checklist-notice" style="padding-bottom: 10px;">
			<h3><?php esc_html_e( 'Welcome to SafeComms! Let\'s get you set up.', 'safecomms' ); ?></h3>
			<p><?php esc_html_e( 'Here are a few recommended steps to ensure optimal protection:', 'safecomms' ); ?></p>
			<ul style="list-style: none; margin-left: 0; padding-left: 0;">
				<li style="margin-bottom: 5px;">
					<?php if ( $api_key ) : ?>
						<span class="dashicons dashicons-yes" style="color: #46b450;"></span>
					<?php else : ?>
						<span class="dashicons dashicons-no" style="color: #dc3232;"></span>
					<?php endif; ?>
					<strong><?php esc_html_e( 'Connect your API Key:', 'safecomms' ); ?></strong>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=safecomms_settings' ) ); ?>"><?php esc_html_e( 'Configure', 'safecomms' ); ?></a>
				</li>
				<li style="margin-bottom: 5px;">
					<?php if ( $auto_approve ) : ?>
						<span class="dashicons dashicons-yes" style="color: #46b450;"></span>
					<?php else : ?>
						<span class="dashicons dashicons-marker" style="color: #ffb900;"></span>
					<?php endif; ?>
					<strong><?php esc_html_e( 'Enable Auto-Approve for safe comments:', 'safecomms' ); ?></strong>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=safecomms_settings&tab=scanning#safecomms_auto_approve_comments' ) ); ?>"><?php esc_html_e( 'Enable', 'safecomms' ); ?></a>
				</li>
				<li style="margin-bottom: 5px;">
					<span class="dashicons dashicons-chart-bar"></span>
					<strong><?php esc_html_e( 'Review your Moderation Logs:', 'safecomms' ); ?></strong>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=safecomms_logs' ) ); ?>"><?php esc_html_e( 'View Logs', 'safecomms' ); ?></a>
				</li>
			</ul>
			<p>
				<a class="button" href="<?php echo esc_url( $dismiss_url ); ?>"><?php esc_html_e( 'Dismiss this checklist', 'safecomms' ); ?></a>
			</p>
		</div>
		<?php
	}
}
