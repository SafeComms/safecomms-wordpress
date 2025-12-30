<?php
namespace SafeComms\Admin;

use SafeComms\Admin\LogsListTable;
use SafeComms\Admin\ModerationListTable;
use SafeComms\API\Client;
use SafeComms\Admin\Settings;
use SafeComms\Database\ModerationRepository;
use SafeComms\Database\LogsRepository;
use SafeComms\Logging\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class AdminPages
{
    private Settings $settings;
    private ModerationRepository $moderationRepository;
    private LogsRepository $logsRepository;
    private Client $client;
    private Logger $logger;

    public function __construct(Settings $settings, ModerationRepository $moderationRepository, LogsRepository $logsRepository, Client $client, Logger $logger)
    {
        $this->settings = $settings;
        $this->moderationRepository = $moderationRepository;
        $this->logsRepository = $logsRepository;
        $this->client = $client;
        $this->logger = $logger;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'handle_actions']);
        add_action('admin_notices', [$this, 'render_notices']);
        add_action('admin_notices', [$this, 'render_block_notice']);
    }

    public function register_menu(): void
    {
        add_menu_page(
            __('SafeComms Moderation', 'safecomms'),
            __('SafeComms', 'safecomms'),
            'manage_options',
            'safecomms_moderation',
            [$this, 'render_moderation_page'],
            'dashicons-shield-alt'
        );

        add_submenu_page(
            'safecomms_moderation',
            __('Moderation Queue', 'safecomms'),
            __('Moderation', 'safecomms'),
            'manage_options',
            'safecomms_moderation',
            [$this, 'render_moderation_page']
        );

        add_submenu_page(
            'safecomms_moderation',
            __('Logs', 'safecomms'),
            __('Logs', 'safecomms'),
            'manage_options',
            'safecomms_logs',
            [$this, 'render_logs_page']
        );

        add_submenu_page(
            'safecomms_moderation',
            __('Settings', 'safecomms'),
            __('Settings', 'safecomms'),
            'manage_options',
            'safecomms_settings',
            [$this, 'render_settings_page']
        );
    }

    public function render_settings_page(): void
    {
        $this->settings->render_page();
    }

    public function render_moderation_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $filters = [];
        if (!empty($_GET['status'])) {
            $filters['status'] = sanitize_text_field(wp_unslash($_GET['status']));
        }
        if (!empty($_GET['ref_type'])) {
            $filters['ref_type'] = sanitize_text_field(wp_unslash($_GET['ref_type']));
        }

        $table = new ModerationListTable($this->moderationRepository, $filters);
        $table->prepare_items();

        echo '<div class="wrap"><h1>' . esc_html__('SafeComms Moderation', 'safecomms') . '</h1>';
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="safecomms_moderation" />';
        echo '<select name="status">';
        echo '<option value="">' . esc_html__('All statuses', 'safecomms') . '</option>';
        echo '<option value="block"' . selected(($filters['status'] ?? '') === 'block', true, false) . '>' . esc_html__('Blocked', 'safecomms') . '</option>';
        echo '<option value="allow"' . selected(($filters['status'] ?? '') === 'allow', true, false) . '>' . esc_html__('Allowed', 'safecomms') . '</option>';
        echo '</select> ';
        echo '<select name="ref_type">';
        echo '<option value="">' . esc_html__('All types', 'safecomms') . '</option>';
        echo '<option value="post"' . selected(($filters['ref_type'] ?? '') === 'post', true, false) . '>' . esc_html__('Posts', 'safecomms') . '</option>';
        echo '<option value="comment"' . selected(($filters['ref_type'] ?? '') === 'comment', true, false) . '>' . esc_html__('Comments', 'safecomms') . '</option>';
        echo '</select> ';
        submit_button(__('Filter', 'safecomms'), 'secondary', '', false);
        echo '</form>';

        echo '<form method="post">';
        wp_nonce_field('bulk-safecomms_entries');
        $table->display();
        echo '</form>';
        echo '</div>';
    }

    public function render_logs_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $filters = [];
        if (!empty($_GET['severity'])) {
            $filters['severity'] = sanitize_text_field(wp_unslash($_GET['severity']));
        }
        if (!empty($_GET['type'])) {
            $filters['type'] = sanitize_text_field(wp_unslash($_GET['type']));
        }

        $table = new LogsListTable($this->logsRepository, $filters);
        $table->prepare_items();

        echo '<div class="wrap"><h1>' . esc_html__('SafeComms Logs', 'safecomms') . '</h1>';
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="safecomms_logs" />';
        echo '<select name="severity">';
        echo '<option value="">' . esc_html__('All severities', 'safecomms') . '</option>';
        foreach (['error', 'warning', 'info', 'debug'] as $level) {
            $selected = selected(($filters['severity'] ?? '') === $level, true, false);
            echo '<option value="' . esc_attr($level) . '" ' . $selected . '>' . esc_html(ucfirst($level)) . '</option>';
        }
        echo '</select> ';
        submit_button(__('Filter', 'safecomms'), 'secondary', '', false);
        echo '</form>';

        $table->display();
        echo '</div>';
    }

    public function handle_actions(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (!empty($_GET['sc_action']) && !empty($_GET['entry_id'])) {
            if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), 'safecomms_action')) {
                wp_die(
                    esc_html__('Security check failed. Please try again.', 'safecomms'),
                    esc_html__('Security Error', 'safecomms'),
                    ['response' => 403]
                );
            }

            $action = sanitize_text_field(wp_unslash($_GET['sc_action']));
            $entry_id = (int) $_GET['entry_id'];
            $this->handle_entry_action($action, $entry_id);
        }

        $bulk_action = isset($_POST['action']) ? wp_unslash($_POST['action']) : (isset($_POST['action2']) ? wp_unslash($_POST['action2']) : '');
        if (!empty($bulk_action) && !empty($_POST['entry'])) {
            check_admin_referer('bulk-safecomms_entries');
            $action = sanitize_text_field($bulk_action);
            $entries = array_map('intval', wp_unslash((array) $_POST['entry']));
            foreach ($entries as $entry_id) {
                if ($entry_id > 0) {
                    $this->handle_entry_action(str_replace('safecomms_', '', $action), $entry_id);
                }
            }
        }

        if (!empty($_GET['safecomms_reset_notice'])) {
            if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), 'safecomms_action')) {
                return;
            }
            update_option('safecomms_notice_baseline', $this->moderationRepository->blocked_count());
            wp_safe_redirect(remove_query_arg(['safecomms_reset_notice', '_wpnonce']));
            exit;
        }
    }

    private function handle_entry_action(string $action, int $entry_id): void
    {
        $entry = $this->moderationRepository->find($entry_id);
        if (!$entry) {
            return;
        }

        if ($action === 'allow') {
            $this->mark_allowed($entry);
            return;
        }

        if ($action === 'rescan') {
            $this->manual_rescan($entry);
        }
    }

    private function manual_rescan(array $entry): void
    {
        $ref_type = $entry['ref_type'];
        $ref_id = (int) $entry['ref_id'];

        if ($ref_type === 'post') {
            $post = get_post($ref_id);
            if (!$post) {
                return;
            }

            $body_profile = $this->settings->get('profile_post_body', '');
            $title_profile = $this->settings->get('profile_post_title', '');

            $decisions = [];

            if ($this->settings->get('enable_post_body_scan', false) && $post->post_content !== '') {
                $decisions['body'] = $this->client->scan_content((string) $post->post_content, [
                    'type' => 'post',
                    'field' => 'post_content',
                    'post_id' => $ref_id,
                    'title' => $post->post_title,
                    'author' => $post->post_author,
                ], $body_profile ?: null);

                if (!empty($decisions['body']['details']['safeContent'])) {
                    $post->post_content = (string) $decisions['body']['details']['safeContent'];
                    wp_update_post([
                        'ID' => $post->ID,
                        'post_content' => $post->post_content,
                    ]);
                }
            }

            if ($this->settings->get('enable_post_title_scan', false) && $post->post_title !== '') {
                $decisions['title'] = $this->client->scan_content((string) $post->post_title, [
                    'type' => 'post',
                    'field' => 'post_title',
                    'post_id' => $ref_id,
                    'title' => $post->post_title,
                    'author' => $post->post_author,
                ], $title_profile ?: null);

                if (!empty($decisions['title']['details']['safeContent'])) {
                    $post->post_title = (string) $decisions['title']['details']['safeContent'];
                    wp_update_post([
                        'ID' => $post->ID,
                        'post_title' => $post->post_title,
                    ]);
                }
            }

            $final_decision = $this->aggregate_decisions(array_values($decisions));
            $this->apply_post_decision($post, $final_decision);
            return;
        }

        if ($ref_type === 'comment') {
            $comment = get_comment($ref_id);
            if (!$comment) {
                return;
            }
            $profile = $this->settings->get('profile_comment_body', '');
            $decision = $this->client->scan_content((string) $comment->comment_content, [
                'type' => 'comment',
                'post_id' => (int) $comment->comment_post_ID,
                'comment_id' => $ref_id,
                'author' => $comment->comment_author,
            ], $profile ?: null);
            $this->apply_comment_decision($comment, $decision);
        }
    }

    private function aggregate_decisions(array $decisions): array
    {
        $final = ['status' => 'allow', 'reason' => '', 'score' => null];

        foreach ($decisions as $decision) {
            if (!$decision) {
                continue;
            }

            if (($decision['status'] ?? '') === 'block') {
                return $decision;
            }

            if (in_array($decision['status'] ?? '', ['error', 'rate_limited'], true)) {
                $final = $decision;
            } elseif ($final['status'] === 'allow') {
                $final = $decision;
            }
        }

        return $final;
    }

    private function mark_allowed(array $entry): void
    {
        $ref_type = $entry['ref_type'];
        $ref_id = (int) $entry['ref_id'];

        $decision = [
            'status' => 'allow',
            'reason' => 'override',
        ];

        if ($ref_type === 'post') {
            $post = get_post($ref_id);
            if ($post) {
                $intended_status = get_post_meta($ref_id, '_safecomms_intended_status', true);
                $new_status = $intended_status ?: 'publish';
                
                wp_update_post([
                    'ID' => $ref_id,
                    'post_status' => $new_status,
                ]);
                update_post_meta($ref_id, 'safecomms_status', 'allow');
                update_post_meta($ref_id, 'safecomms_reason', 'override');
                delete_post_meta($ref_id, '_safecomms_intended_status');
            }
        }

        if ($ref_type === 'comment') {
            $comment = get_comment($ref_id);
            if ($comment) {
                wp_set_comment_status($ref_id, 'approve');
                update_comment_meta($ref_id, 'safecomms_status', 'allow');
                update_comment_meta($ref_id, 'safecomms_reason', 'override');
            }
        }

        $this->moderationRepository->upsert($ref_type, $ref_id, $decision, $entry['content_hash'] ?? '', (int) ($entry['attempts'] ?? 0));
        $this->logger->info('override', 'Manual override to allow', ['ref_type' => $ref_type, 'ref_id' => $ref_id]);
    }

    private function apply_post_decision(\WP_Post $post, array $decision): void
    {
        $hash = md5((string) $post->post_content);
        $this->moderationRepository->upsert('post', $post->ID, $decision, $hash);
        update_post_meta($post->ID, 'safecomms_status', $decision['status'] ?? 'unknown');
        update_post_meta($post->ID, 'safecomms_reason', $decision['reason'] ?? '');
        update_post_meta($post->ID, 'safecomms_score', $decision['score'] ?? null);
        update_post_meta($post->ID, 'safecomms_checked_at', current_time('mysql'));

        if (($decision['status'] ?? '') === 'block') {
            wp_update_post([
                'ID' => $post->ID,
                'post_status' => 'draft',
            ]);
        }
    }

    private function apply_comment_decision(\WP_Comment $comment, array $decision): void
    {
        $hash = md5((string) $comment->comment_content);
        $this->moderationRepository->upsert('comment', $comment->comment_ID, $decision, $hash);
        update_comment_meta($comment->comment_ID, 'safecomms_status', $decision['status'] ?? 'unknown');
        update_comment_meta($comment->comment_ID, 'safecomms_reason', $decision['reason'] ?? '');
        update_comment_meta($comment->comment_ID, 'safecomms_score', $decision['score'] ?? null);
        update_comment_meta($comment->comment_ID, 'safecomms_checked_at', current_time('mysql'));

        if (($decision['status'] ?? '') === 'block') {
            wp_set_comment_status($comment->comment_ID, '0');
        }
    }

    public function render_block_notice(): void
    {
        $user_id = get_current_user_id();
        $reason = false;
        
        if ($user_id) {
            $reason = get_transient('safecomms_block_notice_' . $user_id);
            if ($reason) {
                delete_transient('safecomms_block_notice_' . $user_id);
            }
        } else {
            if (!session_id()) {
                @session_start();
            }
            $session_id = session_id();
            if ($session_id) {
                $reason = get_transient('safecomms_block_notice_session_' . $session_id);
                if ($reason) {
                    delete_transient('safecomms_block_notice_session_' . $session_id);
                }
            }
        }
        
        if ($reason) {
            echo '<div class="notice notice-error is-dismissible"><p>' . 
                 sprintf(esc_html__('Post saved as draft because it was blocked by SafeComms: %s', 'safecomms'), esc_html($reason)) . 
                 '</p></div>';
        }
    }

    public function render_notices(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (get_option('safecomms_quota_exceeded')) {
            echo '<div class="notice notice-error">';
            echo '<p>' . esc_html__('SafeComms API quota exceeded. Scanning is disabled or failing. Please upgrade your plan.', 'safecomms') . '</p>';
            echo '</div>';
        }

        if (!$this->settings->get('notices_enabled', true)) {
            return;
        }

        $baseline = (int) get_option('safecomms_notice_baseline', 0);
        $blocked = $this->moderationRepository->blocked_count();
        $delta = max(0, $blocked - $baseline);

        if ($delta <= 0) {
            return;
        }

        $reset_url = add_query_arg(['safecomms_reset_notice' => 1]);
        $reset_url = wp_nonce_url($reset_url, 'safecomms_action');

        echo '<div class="notice notice-warning">';
        echo '<p>' . esc_html(sprintf(__('SafeComms blocked %d items since last reset.', 'safecomms'), $delta)) . '</p>';
        echo '<p><a class="button" href="' . esc_url($reset_url) . '">' . esc_html__('Reset counter', 'safecomms') . '</a></p>';
        echo '</div>';
    }
}
