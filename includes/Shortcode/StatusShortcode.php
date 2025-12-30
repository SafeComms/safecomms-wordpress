<?php
namespace SafeComms\Shortcode;

if (!defined('ABSPATH')) {
    exit;
}

class StatusShortcode
{
    public function register(): void
    {
        add_shortcode('safecomms_status', [$this, 'render']);
    }

    public function render(array $atts = []): string
    {
        $atts = shortcode_atts([
            'post_id' => '',
            'comment_id' => '',
        ], $atts, 'safecomms_status');

        // 1. Resolve Context (Comment vs Post)
        if (!empty($atts['comment_id'])) {
            return $this->render_comment_status((int) $atts['comment_id']);
        }

        $post_id = !empty($atts['post_id']) ? (int) $atts['post_id'] : get_the_ID();
        if (!$post_id) {
            return '';
        }

        return $this->render_post_status($post_id);
    }

    private function can_view_status(string $capability, int $object_id): bool
    {
        $options = get_option('safecomms_options', []);
        $admins_only = !empty($options['shortcode_admins_only']);

        if ($admins_only) {
            return current_user_can('manage_options');
        }

        return current_user_can($capability, $object_id);
    }

    private function render_post_status(int $post_id): string
    {
        // Security: Check visibility settings
        if (!$this->can_view_status('edit_post', $post_id)) {
            return '';
        }

        $status = get_post_meta($post_id, 'safecomms_status', true);
        $reason = get_post_meta($post_id, 'safecomms_reason', true);
        $score = get_post_meta($post_id, 'safecomms_score', true);

        return $this->format_output('Post', $status, $score, $reason);
    }

    private function render_comment_status(int $comment_id): string
    {
        $comment = get_comment($comment_id);
        if (!$comment) {
            return '';
        }

        // Security: Check visibility settings
        $options = get_option('safecomms_options', []);
        $admins_only = !empty($options['shortcode_admins_only']);

        if ($admins_only && !current_user_can('manage_options')) {
            return '';
        }

        // Allow if user is moderator or comment author
        $can_view = current_user_can('moderate_comments');
        if (!$can_view && is_user_logged_in()) {
            $can_view = (get_current_user_id() === (int) $comment->user_id);
        }

        if (!$can_view) {
            return '';
        }

        $status = get_comment_meta($comment_id, 'safecomms_status', true);
        $reason = get_comment_meta($comment_id, 'safecomms_reason', true);
        $score = get_comment_meta($comment_id, 'safecomms_score', true);

        return $this->format_output('Comment', $status, $score, $reason);
    }

    private function format_output(string $type, $status, $score, $reason): string
    {
        $status_text = esc_html($status ?: __('unknown', 'safecomms'));
        $score_text = $score !== '' ? ' | ' . esc_html(sprintf(__('Score: %s', 'safecomms'), $score)) : '';
        $reason_text = $reason ? ' | ' . esc_html($reason) : '';
        
        $color = '#2271b1'; // Default Blue
        if ($status === 'block') $color = '#d63638'; // Red
        if ($status === 'allow') $color = '#00a32a'; // Green

        $style = "background: #f0f0f1; border-left: 4px solid {$color}; padding: 8px 12px; font-size: 13px; margin: 10px 0; color: #3c434a;";

        return sprintf(
            '<div class="safecomms-status" style="%s"><strong>SafeComms (%s):</strong> %s%s%s</div>',
            esc_attr($style),
            esc_html($type),
            $status_text,
            $score_text,
            $reason_text
        );
    }
}
