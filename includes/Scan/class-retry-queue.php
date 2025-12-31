<?php
/**
 * Retry queue.
 *
 * @package SafeComms
 */

namespace SafeComms\Scan;

use SafeComms\API\Client;
use SafeComms\Admin\Settings;
use SafeComms\Database\Moderation_Repository;
use SafeComms\Logging\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handle retrying moderation scans.
 */
class Retry_Queue {

	/**
	 * Cron hook name.
	 */
	private const HOOK = 'safecomms_retry_item';

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
	 * Moderation repository.
	 *
	 * @var Moderation_Repository
	 */
	private Moderation_Repository $moderation_repository;

	/**
	 * Constructor.
	 *
	 * @param Settings              $settings              Settings service.
	 * @param Client                $client                API client.
	 * @param Logger                $logger                Logger.
	 * @param Moderation_Repository $moderation_repository Moderation repository.
	 */
	public function __construct( Settings $settings, Client $client, Logger $logger, Moderation_Repository $moderation_repository ) {
		$this->settings              = $settings;
		$this->client                = $client;
		$this->logger                = $logger;
		$this->moderation_repository = $moderation_repository;
	}

	/**
	 * Register cron hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'process_item' ), 10, 4 );
	}

	/**
	 * Enqueue a retry job.
	 *
	 * @param int    $ref_id   Reference ID.
	 * @param string $content  Content to scan.
	 * @param array  $context  Context metadata.
	 * @param int    $attempts Current attempt count.
	 *
	 * @return void
	 */
	public function enqueue_item( int $ref_id, string $content, array $context, int $attempts = 0 ): void {
		if ( get_option( 'safecomms_quota_exceeded' ) ) {
			$this->logger->warn( 'retry', 'Quota exceeded; dropping retry job', $context );
			return;
		}

		$max_attempts = (int) $this->settings->get( 'max_retry_attempts', 3 );
		$schedule     = $this->schedule();

		if ( $attempts >= $max_attempts || ! isset( $schedule[ $attempts ] ) ) {
			$this->logger->warn(
				'retry',
				'Retry attempts exceeded; dropping job',
				array(
					'ref_id' => $ref_id,
					'type'   => $context['type'] ?? 'unknown',
				)
			);
			return;
		}

		$delay        = (int) $schedule[ $attempts ];
		$content_hash = md5( $content );
		$args         = array( $ref_id, $content_hash, $context, $attempts + 1 );

		if ( ! wp_next_scheduled( self::HOOK, $args ) ) {
			wp_schedule_single_event( time() + $delay, self::HOOK, $args );
			$this->logger->info(
				'retry',
				'Queued retry',
				array(
					'ref_id'  => $ref_id,
					'type'    => $context['type'] ?? 'unknown',
					'attempt' => $attempts + 1,
				)
			);
		}
	}

	/**
	 * Process a queued retry.
	 *
	 * @param int    $ref_id        Reference ID.
	 * @param string $content_hash  Original content hash.
	 * @param array  $context       Context metadata.
	 * @param int    $attempt       Attempt number.
	 *
	 * @return void
	 */
	public function process_item( int $ref_id, string $content_hash, array $context, int $attempt ): void {
		// Prevent concurrent processing of same item.
		$lock_key = 'safecomms_retry_lock_' . $ref_id;
		if ( get_transient( $lock_key ) ) {
			$this->logger->debug( 'retry', 'Item already being processed', array( 'ref_id' => $ref_id ) );
			return;
		}
		set_transient( $lock_key, true, 60 );

		try {
			$type    = $context['type'] ?? 'post';
			$field   = $context['field'] ?? 'post_content';
			$content = '';

			if ( 'post' === $type ) {
				$post = get_post( $ref_id );
				if ( ! $post ) {
					return;
				}

				if ( 'post_title' === $field ) {
					if ( ! $this->settings->get( 'enable_post_title_scan', true ) ) {
						return;
					}
					$content = (string) $post->post_title;
				} else {
					if ( ! $this->settings->get( 'enable_post_body_scan', true ) ) {
						return;
					}
					$content = (string) $post->post_content;
				}
			} elseif ( 'comment' === $type ) {
				if ( ! $this->settings->get( 'enable_comments', true ) ) {
					return;
				}
				$comment = get_comment( $ref_id );
				if ( ! $comment ) {
					return;
				}
				$content = (string) $comment->comment_content;
			} else {
				return;
			}

			$current_hash = md5( $content );
			if ( $content_hash !== $current_hash ) {
				$this->logger->info(
					'retry',
					'Skipping retry; content changed',
					array(
						'ref_id' => $ref_id,
						'type'   => $type,
					)
				);
				return;
			}

			$profile_id = $context['profile_id'] ?? null;
			$decision   = $this->client->scan_content( $content, $context, $profile_id );
			$status     = $decision['status'] ?? 'error';

			if ( 'quota_exceeded' === ( $decision['reason'] ?? '' ) ) {
				return;
			}

			if ( 'rate_limited' === $status || 'error' === $status ) {
				$this->enqueue_item( $ref_id, $content, $context, $attempt );
				return;
			}

			// Persist decision.
			$this->moderation_repository->upsert( $type, $ref_id, $decision, $content_hash, $attempt );

			if ( 'post' === $type ) {
				update_post_meta( $ref_id, 'safecomms_status', $status );
				update_post_meta( $ref_id, 'safecomms_reason', $decision['reason'] ?? '' );
				update_post_meta( $ref_id, 'safecomms_score', $decision['score'] ?? null );
				update_post_meta( $ref_id, 'safecomms_checked_at', current_time( 'mysql' ) );

				if ( 'block' === $status ) {
					wp_update_post(
						array(
							'ID'          => $ref_id,
							'post_status' => 'draft',
						)
					);
				}
			} elseif ( 'comment' === $type ) {
				update_comment_meta( $ref_id, 'safecomms_status', $status );
				update_comment_meta( $ref_id, 'safecomms_reason', $decision['reason'] ?? '' );
				update_comment_meta( $ref_id, 'safecomms_score', $decision['score'] ?? null );
				update_comment_meta( $ref_id, 'safecomms_checked_at', current_time( 'mysql' ) );

				if ( 'block' === $status ) {
					wp_set_comment_status( $ref_id, '0' );
				}
			}
		} finally {
			// Release lock even on early returns/errors.
			delete_transient( $lock_key );
		}
	}

	/**
	 * Get retry schedule.
	 *
	 * @return array
	 */
	private function schedule(): array {
		$schedule = $this->settings->get( 'retry_schedule', array( 300, 900, 2700 ) );
		return is_array( $schedule ) ? array_values( $schedule ) : array( 300, 900, 2700 );
	}
}
