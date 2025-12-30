<?php
namespace SafeComms\Scan;

use SafeComms\Admin\Settings;
use SafeComms\API\Client;
use SafeComms\Database\ModerationRepository;
use SafeComms\Logging\Logger;
use SafeComms\Scan\RetryQueue;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

class ScanFlow
{
    private Settings $settings;
    private Client $client;
    private Logger $logger;
    private RetryQueue $retryQueue;
    private ModerationRepository $moderationRepository;
    private bool $is_updating = false;
    private array $pending_decisions = [];

    public function __construct(Settings $settings, Client $client, Logger $logger, RetryQueue $retryQueue, ModerationRepository $moderationRepository)
    {
        $this->settings = $settings;
        $this->client = $client;
        $this->logger = $logger;
        $this->retryQueue = $retryQueue;
        $this->moderationRepository = $moderationRepository;
    }

    public function register(): void
    {
        add_filter('wp_insert_post_data', [$this, 'filter_post_data'], 10, 2);
        add_action('save_post', [$this, 'on_save_post'], 10, 3);
        add_filter('preprocess_comment', [$this, 'maybe_scan_comment']);
        add_action('wp_insert_comment', [$this, 'on_comment_insert'], 10, 2);
        add_filter('registration_errors', [$this, 'scan_registration'], 10, 3);

        $this->register_custom_hooks();
    }

    private function register_custom_hooks(): void
    {
        $hooks = $this->settings->get('custom_hooks', []);
        if (!is_array($hooks)) {
            return;
        }

        foreach ($hooks as $hook) {
            if (empty($hook['hook_name'])) {
                continue;
            }

            $priority = isset($hook['priority']) ? (int)$hook['priority'] : 10;
            $arg_pos = isset($hook['arg_position']) ? (int)$hook['arg_position'] : 1;
            $accepted_args = max($arg_pos, 1);
            $type = $hook['type'] ?? 'filter';

            if ($type === 'action') {
                add_action($hook['hook_name'], function (...$args) use ($hook) {
                    $this->handle_custom_hook($hook, ...$args);
                }, $priority, $accepted_args);
            } else {
                add_filter($hook['hook_name'], function (...$args) use ($hook) {
                    return $this->handle_custom_hook($hook, ...$args);
                }, $priority, $accepted_args);
            }
        }
    }

    private function handle_custom_hook(array $config, ...$args)
    {
        $arg_pos = isset($config['arg_position']) ? (int)$config['arg_position'] : 1;
        $arg_index = $arg_pos - 1;
        
        // If the argument doesn't exist, we can't scan it.
        // For filters, we must return the first argument (or the filtered value).
        if (!isset($args[$arg_index])) {
            return $args[0] ?? null;
        }

        $data = $args[$arg_index];
        $content_to_scan = '';
        $array_key = $config['array_key'] ?? '';

        // Extract content
        if (!empty($array_key) && is_array($data)) {
            $content_to_scan = $this->get_array_value_by_path($data, $array_key);
        } elseif (is_string($data)) {
            $content_to_scan = $data;
        }

        if (!is_string($content_to_scan) || trim($content_to_scan) === '') {
            return $args[0] ?? null;
        }

        // Scan
        $profile_id = $config['profile_id'] ?? $this->settings->get('profile_post_body', '');
        
        $decision = $this->client->scan_content($content_to_scan, [
            'type' => 'custom_hook',
            'hook' => $config['hook_name'],
        ], $profile_id ?: null);

        $behavior = $config['behavior'] ?? 'sanitize';

        if ($decision['status'] === 'block') {
            if ($behavior === 'block') {
                if (($config['type'] ?? 'filter') === 'action') {
                    wp_die(
                        esc_html__('Content blocked by SafeComms.', 'safecomms'),
                        esc_html__('Content Blocked', 'safecomms'),
                        ['response' => 403]
                    );
                } else {
                    // For filters, if we are filtering the target argument (arg 1), we can return empty or error.
                    // If we are scanning arg 2 but filtering arg 1, we can't easily block unless we return something that causes a block.
                    // Assuming arg 1 is the target.
                    if ($arg_pos === 1) {
                        // If it's an array, maybe empty the specific key?
                        if (!empty($array_key) && is_array($data)) {
                             $this->set_array_value_by_path($data, $array_key, '');
                             return $data;
                        }
                        // If string
                        return ''; // Return empty string to block?
                    }
                }
            } elseif ($behavior === 'sanitize') {
                $safe_content = $decision['details']['safeContent'] ?? '';
                if (!empty($safe_content)) {
                    if (!empty($array_key) && is_array($data)) {
                        $this->set_array_value_by_path($data, $array_key, $safe_content);
                        return $data;
                    } else {
                        return $safe_content;
                    }
                }
            }
        }

        // If we are here, either clean or failed to block/sanitize properly.
        // Return the original first argument for filters.
        return $args[0] ?? null;
    }

    private function get_array_value_by_path(array $array, string $path)
    {
        $keys = explode('.', $path);
        $current = $array;

        foreach ($keys as $key) {
            if (!is_array($current) || !isset($current[$key])) {
                return '';
            }
            $current = $current[$key];
        }

        return $current;
    }

    private function set_array_value_by_path(array &$array, string $path, $value): void
    {
        $keys = explode('.', $path);
        $current = &$array;

        foreach ($keys as $key) {
            if (!is_array($current)) {
                return; // Cannot set value in non-array
            }
            // Create key if it doesn't exist? 
            // For our use case, we only want to modify existing keys, but let's be safe.
            // If we are traversing, we expect the structure to exist because we just read from it.
            if (!array_key_exists($key, $current)) {
                return;
            }
            $current = &$current[$key];
        }

        $current = $value;
    }

    public function filter_post_data(array $data, array $postarr): array
    {
        if (!$this->settings->get('enable_posts', true) || !$this->settings->get('auto_scan', true)) {
            return $data;
        }

        if ($data['post_status'] !== 'publish') {
            return $data;
        }

        $post_id = $postarr['ID'] ?? 0;
        $title_profile = $this->settings->get('profile_post_title', '');
        $body_profile = $this->settings->get('profile_post_body', '');

        // Capture intended status before we potentially change it
        $intended_status = $data['post_status'];

        // Scan Title
        if (!empty($data['post_title']) && $this->settings->get('enable_post_title_scan', true)) {
            $title_decision = $this->scan_field('post_title', $data['post_title'], $post_id, $title_profile, $data);
            
            if (!empty($title_decision['details']['safeContent'])) {
                $data['post_title'] = $title_decision['details']['safeContent'];
            } elseif ($title_decision['status'] === 'block') {
                $this->handle_block($data, $post_id, $title_decision, $intended_status);
                return $data;
            }
            
            if (in_array($title_decision['status'], ['error', 'rate_limited'], true)) {
                $this->handle_fail_closed_filter($data, $post_id);
                return $data;
            }
        }

        // Scan Body
        if (!empty($data['post_content']) && $this->settings->get('enable_post_body_scan', true)) {
            $body_decision = $this->scan_field('post_content', $data['post_content'], $post_id, $body_profile, $data);
            
            if (!empty($body_decision['details']['safeContent'])) {
                $data['post_content'] = $body_decision['details']['safeContent'];
            } elseif ($body_decision['status'] === 'block') {
                $this->handle_block($data, $post_id, $body_decision, $intended_status);
                return $data;
            }
            
            if (in_array($body_decision['status'], ['error', 'rate_limited'], true)) {
                $this->handle_fail_closed_filter($data, $post_id);
                return $data;
            }
        }

        return $data;
    }

    private function scan_field(string $field_type, string $content, int $post_id, string $profile_id, array $data): array
    {
        $hash = md5($content . $profile_id);
        $cached = $this->maybe_get_cache('post_' . $field_type, $post_id, $hash);
        
        if ($cached) {
            $decision = $cached;
        } else {
            $decision = $this->client->scan_content($content, [
                'type' => 'post',
                'field' => $field_type,
                'post_id' => $post_id,
                'title' => $data['post_title'],
                'author' => $data['post_author'] ?? 0,
            ], $profile_id ?: null);

            if (!in_array($decision['status'], ['error', 'rate_limited'], true)) {
                $this->set_cache('post_' . $field_type, $post_id, $hash, $decision);
            }
        }

        $this->pending_decisions[$hash] = $decision;
        return $decision;
    }

    private function handle_block(array &$data, int $post_id, array $decision, string $intended_status = ''): void
    {
        $data['post_status'] = 'draft';
        
        if ($intended_status && $post_id) {
             update_post_meta($post_id, '_safecomms_intended_status', $intended_status);
        }

        $this->logger->warn('scan', 'Post blocked by SafeComms', ['post_id' => $post_id, 'reason' => $decision['reason']]);
        if ($this->settings->get('show_rejection_reason', false)) {
            $user_id = get_current_user_id();
            if ($user_id) {
                // For logged-in users, use their user ID
                set_transient('safecomms_block_notice_' . $user_id, sanitize_text_field($decision['reason']), 60);
            } else {
                // For anonymous users, generate a unique session-based identifier
                if (!session_id()) {
                    @session_start();
                }
                $session_id = session_id();
                if ($session_id) {
                    set_transient('safecomms_block_notice_session_' . $session_id, sanitize_text_field($decision['reason']), 60);
                }
            }
        }
    }

    public function on_save_post(int $post_id, \WP_Post $post, bool $update): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_revision($post_id)) {
            return;
        }

        // Check pending decisions for both title and body
        $title_profile = $this->settings->get('profile_post_title', '');
        $body_profile = $this->settings->get('profile_post_body', '');
        
        $title_hash = md5($post->post_title . $title_profile);
        $body_hash = md5($post->post_content . $body_profile);

        $title_decision = $this->pending_decisions[$title_hash] ?? null;
        $body_decision = $this->pending_decisions[$body_hash] ?? null;

        // If we have no decisions, nothing to do (maybe cached or not scanned)
        if (!$title_decision && !$body_decision) {
            return;
        }

        // Determine aggregate status
        $final_decision = $this->aggregate_decisions([$title_decision, $body_decision]);
        
        // We use the body hash for the main record if available, else title hash
        $main_hash = $body_decision ? $body_hash : $title_hash;

        if ($final_decision['status'] === 'block' || in_array($final_decision['status'], ['error', 'rate_limited'], true)) {
             $this->persist_post_decision($post_id, $final_decision, $main_hash);
        }

        // Enqueue retries if needed
        // If title failed, enqueue title. If body failed, enqueue body.
        if ($title_decision && in_array($title_decision['status'], ['error', 'rate_limited'], true)) {
             $this->retryQueue->enqueue_item($post_id, $post->post_title, [
                'type' => 'post',
                'field' => 'post_title',
                'post_id' => $post_id,
                'title' => $post->post_title,
                'author' => $post->post_author,
                'profile_id' => $title_profile,
            ], 0);
        }

        if ($body_decision && in_array($body_decision['status'], ['error', 'rate_limited'], true)) {
             $this->retryQueue->enqueue_item($post_id, $post->post_content, [
                'type' => 'post',
                'field' => 'post_content',
                'post_id' => $post_id,
                'title' => $post->post_title,
                'author' => $post->post_author,
                'profile_id' => $body_profile,
            ], 0);
        }
        
        // Cleanup
        unset($this->pending_decisions[$title_hash]);
        unset($this->pending_decisions[$body_hash]);
    }

    private function aggregate_decisions(array $decisions): array {
        $final = ['status' => 'allow', 'reason' => '', 'score' => 0];
        foreach ($decisions as $d) {
            if (!$d) continue;
            if ($d['status'] === 'block') {
                return $d; // Fail fast on block
            }
            if (in_array($d['status'], ['error', 'rate_limited'], true)) {
                $final = $d; // Store error but keep checking for blocks
            }
        }
        return $final;
    }

    public function maybe_scan_comment(array $commentdata)
    {
        if (!$this->settings->get('enable_comments', true) || !$this->settings->get('auto_scan', true)) {
            return $commentdata;
        }

        $content = (string) ($commentdata['comment_content'] ?? '');
        $profile_id = $this->settings->get('profile_comment_body', '');
        $hash = md5($content . $profile_id);
        $comment_id = $commentdata['comment_ID'] ?? 0;
        $post_id = $commentdata['comment_post_ID'] ?? 0;

        $cached = $this->maybe_get_cache('comment', (int) $comment_id ?: (int) $post_id, $hash);
        if ($cached) {
            $decision = $cached;
        } else {
            $decision = $this->client->scan_content($content, [
                'type' => 'comment',
                'post_id' => $post_id,
                'comment_id' => $comment_id,
                'author' => $commentdata['comment_author'] ?? '',
            ], $profile_id ?: null);

            if (!in_array($decision['status'], ['error', 'rate_limited'], true)) {
                $this->set_cache('comment', (int) $comment_id ?: (int) $post_id, $hash, $decision);
            }
        }

        if (!empty($decision['details']['safeContent'])) {
            $commentdata['comment_content'] = $decision['details']['safeContent'];
        } elseif ($decision['status'] === 'block') {
            $commentdata['comment_approved'] = '0';
            if ($this->settings->get('show_rejection_reason', false)) {
                return new WP_Error(
                    'safecomms_comment_blocked',
                    esc_html__('Your comment was blocked: ', 'safecomms') . esc_html($decision['reason']),
                    ['status' => 403]
                );
            }
        }

        if (in_array($decision['status'], ['error', 'rate_limited'], true)) {
            if (!$this->settings->get('fail_open_comments', false)) {
                $commentdata['comment_approved'] = '0';
            }
        }

        return $commentdata;
    }

    public function on_comment_insert(int $comment_ID, \WP_Comment $comment): void
    {
        $content = (string) $comment->comment_content;
        $profile_id = $this->settings->get('profile_comment_body', '');
        $hash = md5($content . $profile_id);
        $cached = $this->maybe_get_cache('comment', (int) $comment->comment_post_ID, $hash);

        if ($cached) {
            $decision = $cached;
        } else {
            // If not cached, it means preprocess didn't run or cache expired. Scan now.
            $decision = $this->client->scan_content($content, [
                'type' => 'comment',
                'post_id' => (int) $comment->comment_post_ID,
                'comment_id' => $comment_ID,
                'author' => $comment->comment_author,
            ], $profile_id ?: null);
        }

        $this->moderationRepository->upsert('comment', $comment_ID, $decision, $hash);
        update_comment_meta($comment_ID, 'safecomms_status', $decision['status'] ?? 'unknown');
        update_comment_meta($comment_ID, 'safecomms_reason', $decision['reason'] ?? '');
        update_comment_meta($comment_ID, 'safecomms_score', $decision['score'] ?? null);
        update_comment_meta($comment_ID, 'safecomms_checked_at', current_time('mysql'));

        if (in_array($decision['status'], ['error', 'rate_limited'], true)) {
             $this->retryQueue->enqueue_item($comment_ID, $content, [
                'type' => 'comment',
                'post_id' => (int) $comment->comment_post_ID,
                'comment_id' => $comment_ID,
                'author' => $comment->comment_author,
                'profile_id' => $profile_id,
            ], 0);
        }
    }

    public function scan_registration(\WP_Error $errors, string $sanitized_user_login, string $user_email): \WP_Error
    {
        if (!$this->settings->get('auto_scan', true) || !$this->settings->get('enable_username_scan', true)) {
            return $errors;
        }

        $profile_id = $this->settings->get('profile_username', '');
        
        // Scan Username
        $decision = $this->client->scan_content($sanitized_user_login, [
            'type' => 'user_signup',
            'user_login' => $sanitized_user_login,
            'email' => $user_email,
        ], $profile_id ?: null);

        if ($decision['status'] === 'block') {
            $errors->add('safecomms_blocked', __('Username is not allowed.', 'safecomms'));
            $this->logger->info('scan', 'Username blocked', ['user_login' => $sanitized_user_login]);
        }

        return $errors;
    }

    private function persist_post_decision(int $post_id, array $decision, string $hash): void
    {
        $this->moderationRepository->upsert('post', $post_id, $decision, $hash);
        update_post_meta($post_id, 'safecomms_status', $decision['status'] ?? 'unknown');
        update_post_meta($post_id, 'safecomms_reason', $decision['reason'] ?? '');
        update_post_meta($post_id, 'safecomms_score', $decision['score'] ?? null);
        update_post_meta($post_id, 'safecomms_checked_at', current_time('mysql')); 
    }

    private function handle_fail_closed_filter(array &$data, int $post_id): void
    {
        $this->logger->error('scan', 'SafeComms unavailable; enforcing fail-closed for post', ['post_id' => $post_id]);
        $data['post_status'] = 'draft';
    }

    private function maybe_get_cache(string $type, int $id, string $hash): ?array
    {
        $ttl = (int) $this->settings->get('cache_ttl', 600);
        if ($ttl <= 0) {
            return null;
        }

        $key = 'safecomms_' . sanitize_key($type) . '_' . $id . '_' . $hash;
        $cached = get_transient($key);

        return is_array($cached) ? $cached : null;
    }

    private function set_cache(string $type, int $id, string $hash, array $decision): void
    {
        $ttl = (int) $this->settings->get('cache_ttl', 600);
        if ($ttl <= 0) {
            return;
        }

        $key = 'safecomms_' . sanitize_key($type) . '_' . $id . '_' . $hash;
        set_transient($key, $decision, $ttl);
    }
}
