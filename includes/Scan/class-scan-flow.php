<?php
/**
 * Scan flow coordinator.
 *
 * @package SafeComms
 */

namespace SafeComms\Scan;

use SafeComms\Admin\Settings;
use SafeComms\API\Client;
use SafeComms\Database\Moderation_Repository;
use SafeComms\Logging\Logger;
use SafeComms\Scan\Retry_Queue;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage content scanning across posts, comments, and custom hooks.
 */
class Scan_Flow {

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * API client.
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
	 * Retry queue handler.
	 *
	 * @var Retry_Queue
	 */
	private Retry_Queue $retry_queue;

	/**
	 * Moderation repository.
	 *
	 * @var Moderation_Repository
	 */
	private Moderation_Repository $moderation_repository;

	/**
	 * Updating flag to avoid recursion.
	 *
	 * @var bool
	 */
	private bool $is_updating = false;

	/**
	 * Pending decisions keyed by cache hash.
	 *
	 * @var array
	 */
	private array $pending_decisions = array();

	/**
	 * Constructor.
	 *
	 * @param Settings              $settings              Settings service.
	 * @param Client                $client                API client.
	 * @param Logger                $logger                Logger.
	 * @param Retry_Queue           $retry_queue           Retry queue.
	 * @param Moderation_Repository $moderation_repository Moderation repository.
	 */
	public function __construct( Settings $settings, Client $client, Logger $logger, Retry_Queue $retry_queue, Moderation_Repository $moderation_repository ) {
		$this->settings              = $settings;
		$this->client                = $client;
		$this->logger                = $logger;
		$this->retry_queue           = $retry_queue;
		$this->moderation_repository = $moderation_repository;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_insert_post_data', array( $this, 'filter_post_data' ), 10, 2 );
		add_action( 'save_post', array( $this, 'on_save_post' ), 10, 3 );
		add_filter( 'preprocess_comment', array( $this, 'maybe_scan_comment' ) );
		add_action( 'wp_insert_comment', array( $this, 'on_comment_insert' ), 10, 2 );
		add_filter( 'registration_errors', array( $this, 'scan_registration' ), 10, 3 );

		$this->register_custom_hooks();
	}

	/**
	 * Register custom hooks configured in settings.
	 *
	 * @return void
	 */
	private function register_custom_hooks(): void {
		$hooks = $this->settings->get( 'custom_hooks', array() );
		if ( ! is_array( $hooks ) ) {
			return;
		}

		foreach ( $hooks as $hook ) {
			if ( empty( $hook['hook_name'] ) ) {
				continue;
			}

			$priority      = isset( $hook['priority'] ) ? (int) $hook['priority'] : 10;
			$arg_pos       = isset( $hook['arg_position'] ) ? (int) $hook['arg_position'] : 1;
			$accepted_args = max( $arg_pos, 1 );
			$type          = $hook['type'] ?? 'filter';

			if ( 'action' === $type ) {
				add_action(
					$hook['hook_name'],
					function ( ...$args ) use ( $hook ) {
						$this->handle_custom_hook( $hook, ...$args );
					},
					$priority,
					$accepted_args
				);
			} else {
				add_filter(
					$hook['hook_name'],
					function ( ...$args ) use ( $hook ) {
						return $this->handle_custom_hook( $hook, ...$args );
					},
					$priority,
					$accepted_args
				);
			}
		}
	}

	/**
	 * Handle configured custom hook scanning.
	 *
	 * @param array $config Hook configuration.
	 * @param mixed ...$args Hook arguments.
	 *
	 * @return mixed
	 */
	private function handle_custom_hook( array $config, ...$args ) {
		$arg_pos   = isset( $config['arg_position'] ) ? (int) $config['arg_position'] : 1;
		$arg_index = $arg_pos - 1;

		if ( ! isset( $args[ $arg_index ] ) ) {
			return $args[0] ?? null;
		}

		$data            = $args[ $arg_index ];
		$content_to_scan = '';
		$array_key       = $config['array_key'] ?? '';

		if ( ! empty( $array_key ) && is_array( $data ) ) {
			$content_to_scan = $this->get_array_value_by_path( $data, $array_key );
		} elseif ( is_string( $data ) ) {
			$content_to_scan = $data;
		}

		if ( ! is_string( $content_to_scan ) || '' === trim( $content_to_scan ) ) {
			return $args[0] ?? null;
		}

		$profile_id       = $config['profile_id'] ?? $this->settings->get( 'profile_post_body', '' );
		$profile_for_scan = $profile_id ? $profile_id : null;

		$decision = $this->client->scan_content(
			$content_to_scan,
			array(
				'type' => 'custom_hook',
				'hook' => $config['hook_name'],
			),
			$profile_for_scan
		);

		$behavior = $config['behavior'] ?? 'sanitize';
		$type     = $config['type'] ?? 'filter';

		if ( 'block' === ( $decision['status'] ?? '' ) ) {
			if ( 'block' === $behavior && 'action' === $type ) {
				wp_die(
					esc_html__( 'Content blocked by SafeComms.', 'safecomms' ),
					esc_html__( 'Content Blocked', 'safecomms' ),
					array( 'response' => 403 )
				);
			}

			if ( 'block' === $behavior && 1 === $arg_pos ) {
				if ( ! empty( $array_key ) && is_array( $data ) ) {
					$this->set_array_value_by_path( $data, $array_key, '' );
					return $data;
				}

				return '';
			}

			if ( 'sanitize' === $behavior ) {
				$safe_content = $decision['details']['safeContent'] ?? '';
				if ( '' !== $safe_content ) {
					if ( ! empty( $array_key ) && is_array( $data ) ) {
						$this->set_array_value_by_path( $data, $array_key, $safe_content );
						return $data;
					}

					return $safe_content;
				}
			}
		}

		return $args[0] ?? null;
	}

	/**
	 * Retrieve nested value by dot path.
	 *
	 * @param array  $target_array Source array.
	 * @param string $path         Dot-delimited path.
	 *
	 * @return mixed
	 */
	private function get_array_value_by_path( array $target_array, string $path ) {
		$keys    = explode( '.', $path );
		$current = $target_array;

		foreach ( $keys as $key ) {
			if ( ! is_array( $current ) || ! isset( $current[ $key ] ) ) {
				return '';
			}
			$current = $current[ $key ];
		}

		return $current;
	}

	/**
	 * Set nested value by dot path when it exists.
	 *
	 * @param array  $target_array Target array (by reference).
	 * @param string $path         Dot-delimited path.
	 * @param mixed  $value        Value to set.
	 *
	 * @return void
	 */
	private function set_array_value_by_path( array &$target_array, string $path, $value ): void {
		$keys    = explode( '.', $path );
		$current = &$target_array;

		foreach ( $keys as $key ) {
			if ( ! is_array( $current ) ) {
				return;
			}
			if ( ! array_key_exists( $key, $current ) ) {
				return;
			}
			$current = &$current[ $key ];
		}

		$current = $value;
	}

	/**
	 * Filter post data before insert.
	 *
	 * @param array $data    Post data.
	 * @param array $postarr Original post array.
	 *
	 * @return array
	 */
	public function filter_post_data( array $data, array $postarr ): array {
		if ( ! $this->settings->get( 'enable_posts', true ) || ! $this->settings->get( 'auto_scan', true ) ) {
			return $data;
		}

		if ( 'publish' !== $data['post_status'] ) {
			return $data;
		}

		$post_id       = $postarr['ID'] ?? 0;
		$title_profile = $this->settings->get( 'profile_post_title', '' );
		$body_profile  = $this->settings->get( 'profile_post_body', '' );

		$intended_status = $data['post_status'];

		if ( ! empty( $data['post_title'] ) && $this->settings->get( 'enable_post_title_scan', true ) ) {
			$title_decision = $this->scan_field( 'post_title', $data['post_title'], $post_id, $title_profile, $data );

			if ( ! empty( $title_decision['details']['safeContent'] ) ) {
				$data['post_title'] = $title_decision['details']['safeContent'];
			} elseif ( 'block' === $title_decision['status'] ) {
				$this->handle_block( $data, $post_id, $title_decision, $intended_status );
				return $data;
			}

			if ( in_array( $title_decision['status'], array( 'error', 'rate_limited' ), true ) ) {
				$this->handle_fail_closed_filter( $data, $post_id );
				return $data;
			}
		}

		if ( ! empty( $data['post_content'] ) && $this->settings->get( 'enable_post_body_scan', true ) ) {
			$body_decision = $this->scan_field( 'post_content', $data['post_content'], $post_id, $body_profile, $data );

			if ( ! empty( $body_decision['details']['safeContent'] ) ) {
				$data['post_content'] = $body_decision['details']['safeContent'];
			} elseif ( 'block' === $body_decision['status'] ) {
				$this->handle_block( $data, $post_id, $body_decision, $intended_status );
				return $data;
			}

			if ( in_array( $body_decision['status'], array( 'error', 'rate_limited' ), true ) ) {
				$this->handle_fail_closed_filter( $data, $post_id );
				return $data;
			}
		}

		return $data;
	}

	/**
	 * Scan a specific post field.
	 *
	 * @param string $field_type Field being scanned.
	 * @param string $content    Content to scan.
	 * @param int    $post_id    Post ID.
	 * @param string $profile_id Profile ID.
	 * @param array  $data       Post data.
	 *
	 * @return array
	 */
	private function scan_field( string $field_type, string $content, int $post_id, string $profile_id, array $data ): array {
		$hash   = md5( $content . $profile_id );
		$cached = $this->maybe_get_cache( 'post_' . $field_type, $post_id, $hash );

		if ( $cached ) {
			$decision = $cached;
		} else {
			$decision = $this->client->scan_content(
				$content,
				array(
					'type'    => 'post',
					'field'   => $field_type,
					'post_id' => $post_id,
					'title'   => $data['post_title'],
					'author'  => $data['post_author'] ?? 0,
				),
				$profile_id ? $profile_id : null
			);

			if ( ! in_array( $decision['status'], array( 'error', 'rate_limited' ), true ) ) {
				$this->set_cache( 'post_' . $field_type, $post_id, $hash, $decision );
			}
		}

		$this->pending_decisions[ $hash ] = $decision;
		return $decision;
	}

	/**
	 * Handle a blocked post decision.
	 *
	 * @param array  $data            Post data (by reference).
	 * @param int    $post_id         Post ID.
	 * @param array  $decision        Decision payload.
	 * @param string $intended_status Original status.
	 *
	 * @return void
	 */
	private function handle_block( array &$data, int $post_id, array $decision, string $intended_status = '' ): void {
		$data['post_status'] = 'draft';

		if ( $intended_status && $post_id ) {
			update_post_meta( $post_id, '_safecomms_intended_status', $intended_status );
		}

		$this->logger->warn(
			'scan',
			'Post blocked by SafeComms',
			array(
				'post_id' => $post_id,
				'reason'  => $decision['reason'],
			)
		);
		if ( $this->settings->get( 'show_rejection_reason', false ) ) {
			$user_id = get_current_user_id();
			if ( $user_id ) {
				set_transient( 'safecomms_block_notice_' . $user_id, sanitize_text_field( $decision['reason'] ), 60 );
			} elseif ( ! headers_sent() ) {
				setcookie( 'safecomms_block_notice', sanitize_text_field( $decision['reason'] ), time() + 60, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
			}
		}
	}

	/**
	 * Persist outcomes after save_post.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @param bool     $update  Whether updating.
	 *
	 * @return void
	 */
	public function on_save_post( int $post_id, \WP_Post $post, bool $update ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		unset( $update );

		$title_profile = $this->settings->get( 'profile_post_title', '' );
		$body_profile  = $this->settings->get( 'profile_post_body', '' );

		$title_hash = md5( $post->post_title . $title_profile );
		$body_hash  = md5( $post->post_content . $body_profile );

		$title_decision = $this->pending_decisions[ $title_hash ] ?? null;
		$body_decision  = $this->pending_decisions[ $body_hash ] ?? null;

		if ( ! $title_decision && ! $body_decision ) {
			return;
		}

		$final_decision = $this->aggregate_decisions( array( $title_decision, $body_decision ) );
		$main_hash      = $body_decision ? $body_hash : $title_hash;

		if ( 'block' === $final_decision['status'] || in_array( $final_decision['status'], array( 'error', 'rate_limited' ), true ) ) {
			$this->persist_post_decision( $post_id, $final_decision, $main_hash );
		}

		if ( $title_decision && in_array( $title_decision['status'], array( 'error', 'rate_limited' ), true ) ) {
			$this->retry_queue->enqueue_item(
				$post_id,
				$post->post_title,
				array(
					'type'       => 'post',
					'field'      => 'post_title',
					'post_id'    => $post_id,
					'title'      => $post->post_title,
					'author'     => $post->post_author,
					'profile_id' => $title_profile,
				),
				0
			);
		}

		if ( $body_decision && in_array( $body_decision['status'], array( 'error', 'rate_limited' ), true ) ) {
			$this->retry_queue->enqueue_item(
				$post_id,
				$post->post_content,
				array(
					'type'       => 'post',
					'field'      => 'post_content',
					'post_id'    => $post_id,
					'title'      => $post->post_title,
					'author'     => $post->post_author,
					'profile_id' => $body_profile,
				),
				0
			);
		}

		unset( $this->pending_decisions[ $title_hash ] );
		unset( $this->pending_decisions[ $body_hash ] );
	}

	/**
	 * Combine decisions prioritizing blocks then errors.
	 *
	 * @param array $decisions Decisions to merge.
	 *
	 * @return array
	 */
	private function aggregate_decisions( array $decisions ): array {
		$final = array(
			'status' => 'allow',
			'reason' => '',
			'score'  => 0,
		);
		foreach ( $decisions as $decision ) {
			if ( ! $decision ) {
				continue;
			}
			if ( 'block' === $decision['status'] ) {
				return $decision;
			}
			if ( in_array( $decision['status'], array( 'error', 'rate_limited' ), true ) ) {
				$final = $decision;
			}
		}
		return $final;
	}

	/**
	 * Scan comments before insert.
	 *
	 * @param array $commentdata Comment data.
	 *
	 * @return array|WP_Error
	 */
	public function maybe_scan_comment( array $commentdata ) {
		if ( ! $this->settings->get( 'enable_comments', true ) || ! $this->settings->get( 'auto_scan', true ) ) {
			return $commentdata;
		}

		$content      = (string) ( $commentdata['comment_content'] ?? '' );
		$profile_id   = $this->settings->get( 'profile_comment_body', '' );
		$hash         = md5( $content . $profile_id );
		$comment_id   = $commentdata['comment_ID'] ?? 0;
		$post_id      = $commentdata['comment_post_ID'] ?? 0;
		$cache_key_id = $comment_id ? (int) $comment_id : (int) $post_id;

		$cached = $this->maybe_get_cache( 'comment', $cache_key_id, $hash );
		if ( $cached ) {
			$decision = $cached;
		} else {
			$decision = $this->client->scan_content(
				$content,
				array(
					'type'       => 'comment',
					'post_id'    => $post_id,
					'comment_id' => $comment_id,
					'author'     => $commentdata['comment_author'] ?? '',
				),
				$profile_id ? $profile_id : null
			);

			if ( ! in_array( $decision['status'], array( 'error', 'rate_limited' ), true ) ) {
				$this->set_cache( 'comment', $cache_key_id, $hash, $decision );
			}
		}

		if ( ! empty( $decision['details']['safeContent'] ) ) {
			$commentdata['comment_content'] = $decision['details']['safeContent'];
		} elseif ( 'block' === $decision['status'] ) {
			$commentdata['comment_approved'] = '0';
			if ( $this->settings->get( 'show_rejection_reason', false ) ) {
				return new WP_Error(
					'safecomms_comment_blocked',
					esc_html__( 'Your comment was blocked: ', 'safecomms' ) . esc_html( $decision['reason'] ),
					array( 'status' => 403 )
				);
			}
		}

		if ( in_array( $decision['status'], array( 'error', 'rate_limited' ), true ) ) {
			if ( ! $this->settings->get( 'fail_open_comments', false ) ) {
				$commentdata['comment_approved'] = '0';
			}
		}

		return $commentdata;
	}

	/**
	 * Persist moderation after comment insert.
	 *
	 * @param int         $comment_id Comment ID.
	 * @param \WP_Comment $comment    Comment object.
	 *
	 * @return void
	 */
	public function on_comment_insert( int $comment_id, \WP_Comment $comment ): void {
		$content    = (string) $comment->comment_content;
		$profile_id = $this->settings->get( 'profile_comment_body', '' );
		$hash       = md5( $content . $profile_id );
		$cached     = $this->maybe_get_cache( 'comment', (int) $comment->comment_post_ID, $hash );

		if ( $cached ) {
			$decision = $cached;
		} else {
			$decision = $this->client->scan_content(
				$content,
				array(
					'type'       => 'comment',
					'post_id'    => (int) $comment->comment_post_ID,
					'comment_id' => $comment_id,
					'author'     => $comment->comment_author,
				),
				$profile_id ? $profile_id : null
			);
		}

		$this->moderation_repository->upsert( 'comment', $comment_id, $decision, $hash );
		update_comment_meta( $comment_id, 'safecomms_status', $decision['status'] ?? 'unknown' );
		update_comment_meta( $comment_id, 'safecomms_reason', $decision['reason'] ?? '' );
		update_comment_meta( $comment_id, 'safecomms_score', $decision['score'] ?? null );
		update_comment_meta( $comment_id, 'safecomms_checked_at', current_time( 'mysql' ) );

		if ( in_array( $decision['status'], array( 'error', 'rate_limited' ), true ) ) {
			$this->retry_queue->enqueue_item(
				$comment_id,
				$content,
				array(
					'type'       => 'comment',
					'post_id'    => (int) $comment->comment_post_ID,
					'comment_id' => $comment_id,
					'author'     => $comment->comment_author,
					'profile_id' => $profile_id,
				),
				0
			);
		}
	}

	/**
	 * Scan usernames during registration.
	 *
	 * @param \WP_Error $errors               Error object.
	 * @param string    $sanitized_user_login Username.
	 * @param string    $user_email           Email.
	 *
	 * @return \WP_Error
	 */
	public function scan_registration( \WP_Error $errors, string $sanitized_user_login, string $user_email ): \WP_Error {
		if ( ! $this->settings->get( 'auto_scan', true ) || ! $this->settings->get( 'enable_username_scan', true ) ) {
			return $errors;
		}

		$profile_id = $this->settings->get( 'profile_username', '' );

		$decision = $this->client->scan_content(
			$sanitized_user_login,
			array(
				'type'       => 'user_signup',
				'user_login' => $sanitized_user_login,
				'email'      => $user_email,
			),
			$profile_id ? $profile_id : null
		);

		if ( 'block' === $decision['status'] ) {
			$errors->add( 'safecomms_blocked', __( 'Username is not allowed.', 'safecomms' ) );
			$this->logger->info( 'scan', 'Username blocked', array( 'user_login' => $sanitized_user_login ) );
		}

		return $errors;
	}

	/**
	 * Persist post decision to the database.
	 *
	 * @param int    $post_id  Post ID.
	 * @param array  $decision Decision payload.
	 * @param string $hash     Cache hash.
	 *
	 * @return void
	 */
	private function persist_post_decision( int $post_id, array $decision, string $hash ): void {
		$this->moderation_repository->upsert( 'post', $post_id, $decision, $hash );
		update_post_meta( $post_id, 'safecomms_status', $decision['status'] ?? 'unknown' );
		update_post_meta( $post_id, 'safecomms_reason', $decision['reason'] ?? '' );
		update_post_meta( $post_id, 'safecomms_score', $decision['score'] ?? null );
		update_post_meta( $post_id, 'safecomms_checked_at', current_time( 'mysql' ) );
	}

	/**
	 * Fail closed by forcing draft status.
	 *
	 * @param array $data    Post data (by reference).
	 * @param int   $post_id Post ID.
	 *
	 * @return void
	 */
	private function handle_fail_closed_filter( array &$data, int $post_id ): void {
		$this->logger->error( 'scan', 'SafeComms unavailable; enforcing fail-closed for post', array( 'post_id' => $post_id ) );
		$data['post_status'] = 'draft';
	}

	/**
	 * Maybe fetch cached decision.
	 *
	 * @param string $type Decision type.
	 * @param int    $id   Entity ID.
	 * @param string $hash Cache hash.
	 *
	 * @return array|null
	 */
	private function maybe_get_cache( string $type, int $id, string $hash ): ?array {
		$ttl = (int) $this->settings->get( 'cache_ttl', 600 );
		if ( $ttl <= 0 ) {
			return null;
		}

		$key    = 'safecomms_' . sanitize_key( $type ) . '_' . $id . '_' . $hash;
		$cached = get_transient( $key );

		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * Write decision cache.
	 *
	 * @param string $type     Decision type.
	 * @param int    $id       Entity ID.
	 * @param string $hash     Cache hash.
	 * @param array  $decision Decision data.
	 *
	 * @return void
	 */
	private function set_cache( string $type, int $id, string $hash, array $decision ): void {
		$ttl = (int) $this->settings->get( 'cache_ttl', 600 );
		if ( $ttl <= 0 ) {
			return;
		}

		$key = 'safecomms_' . sanitize_key( $type ) . '_' . $id . '_' . $hash;
		set_transient( $key, $decision, $ttl );
	}
}
