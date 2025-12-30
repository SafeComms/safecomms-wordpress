<?php
namespace SafeComms\Scan;

use SafeComms\API\Client;
use SafeComms\Admin\Settings;
use SafeComms\Database\ModerationRepository;
use SafeComms\Logging\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class RetryQueue
{
    private const HOOK = 'safecomms_retry_item';

    private Settings $settings;
    private Client $client;
    private Logger $logger;
    private ModerationRepository $moderationRepository;

    public function __construct(Settings $settings, Client $client, Logger $logger, ModerationRepository $moderationRepository)
    {
        $this->settings = $settings;
        $this->client = $client;
        $this->logger = $logger;
        $this->moderationRepository = $moderationRepository;
    }

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'process_item'], 10, 4);
    }

    public function enqueue_item(int $ref_id, string $content, array $context, int $attempts = 0): void
    {
        if (get_option('safecomms_quota_exceeded')) {
            $this->logger->warn('retry', 'Quota exceeded; dropping retry job', $context);
            return;
        }

        $max_attempts = (int) $this->settings->get('max_retry_attempts', 3);
        $schedule = $this->schedule();

        if ($attempts >= $max_attempts || !isset($schedule[$attempts])) {
            $this->logger->warn('retry', 'Retry attempts exceeded; dropping job', ['ref_id' => $ref_id, 'type' => $context['type'] ?? 'unknown']);
            return;
        }

        $delay = (int) $schedule[$attempts];
        $content_hash = md5($content);
        $args = [$ref_id, $content_hash, $context, $attempts + 1];

        if (!wp_next_scheduled(self::HOOK, $args)) {
            wp_schedule_single_event(time() + $delay, self::HOOK, $args);
            $this->logger->info('retry', 'Queued retry', ['ref_id' => $ref_id, 'type' => $context['type'] ?? 'unknown', 'attempt' => $attempts + 1]);
        }
    }

    public function process_item(int $ref_id, string $content_hash, array $context, int $attempt): void
    {
        // Prevent concurrent processing of same item
        $lock_key = 'safecomms_retry_lock_' . $ref_id;
        if (get_transient($lock_key)) {
            $this->logger->debug('retry', 'Item already being processed', ['ref_id' => $ref_id]);
            return;
        }
        set_transient($lock_key, true, 60);

        try {
            $type = $context['type'] ?? 'post';
            $field = $context['field'] ?? 'post_content';
            $content = '';

            if ($type === 'post') {
                $post = get_post($ref_id);
                if (!$post) {
                    return;
                }

                if ($field === 'post_title') {
                    if (!$this->settings->get('enable_post_title_scan', true)) {
                        return;
                    }
                    $content = (string) $post->post_title;
                } else {
                    if (!$this->settings->get('enable_post_body_scan', true)) {
                        return;
                    }
                    $content = (string) $post->post_content;
                }
            } elseif ($type === 'comment') {
                if (!$this->settings->get('enable_comments', true)) {
                    return;
                }
                $comment = get_comment($ref_id);
                if (!$comment) {
                    return;
                }
                $content = (string) $comment->comment_content;
            } else {
                return;
            }

            $current_hash = md5($content);
            if ($current_hash !== $content_hash) {
                $this->logger->info('retry', 'Skipping retry; content changed', ['ref_id' => $ref_id, 'type' => $type]);
                return;
            }

            $profile_id = $context['profile_id'] ?? null;
            $decision = $this->client->scan_content($content, $context, $profile_id);
            $status = $decision['status'] ?? 'error';

            if ($decision['reason'] === 'quota_exceeded') {
                return; // Stop retrying
            }

            if ($status === 'rate_limited' || $status === 'error') {
                $this->enqueue_item($ref_id, $content, $context, $attempt);
                return;
            }

            // Persist decision
            $this->moderationRepository->upsert($type, $ref_id, $decision, $content_hash, $attempt);
            
            if ($type === 'post') {
                update_post_meta($ref_id, 'safecomms_status', $status);
                update_post_meta($ref_id, 'safecomms_reason', $decision['reason'] ?? '');
                update_post_meta($ref_id, 'safecomms_score', $decision['score'] ?? null);
                update_post_meta($ref_id, 'safecomms_checked_at', current_time('mysql'));

                if ($status === 'block') {
                    wp_update_post(['ID' => $ref_id, 'post_status' => 'draft']);
                }
            } elseif ($type === 'comment') {
                update_comment_meta($ref_id, 'safecomms_status', $status);
                update_comment_meta($ref_id, 'safecomms_reason', $decision['reason'] ?? '');
                update_comment_meta($ref_id, 'safecomms_score', $decision['score'] ?? null);
                update_comment_meta($ref_id, 'safecomms_checked_at', current_time('mysql'));

                if ($status === 'block') {
                    wp_set_comment_status($ref_id, '0');
                }
            }
        } finally {
            // Release lock even on early returns/errors
            delete_transient($lock_key);
        }
    }

    private function schedule(): array
    {
        $schedule = $this->settings->get('retry_schedule', [300, 900, 2700]);
        return is_array($schedule) ? array_values($schedule) : [300, 900, 2700];
    }
}
