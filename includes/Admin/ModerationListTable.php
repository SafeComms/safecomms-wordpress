<?php
namespace SafeComms\Admin;

use SafeComms\Database\ModerationRepository;

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class ModerationListTable extends \WP_List_Table
{
    private ModerationRepository $repo;
    private array $filters;

    public function __construct(ModerationRepository $repo, array $filters = [])
    {
        parent::__construct([
            'singular' => 'safecomms_entry',
            'plural' => 'safecomms_entries',
            'ajax' => false,
        ]);

        $this->repo = $repo;
        $this->filters = $filters;
    }

    public function get_columns(): array
    {
        return [
            'cb' => '<input type="checkbox" />',
            'ref_type' => __('Type', 'safecomms'),
            'ref_id' => __('Reference', 'safecomms'),
            'status' => __('Status', 'safecomms'),
            'modifications' => __('Modifications', 'safecomms'),
            'score' => __('Score', 'safecomms'),
            'reason' => __('Reason', 'safecomms'),
            'updated_at' => __('Updated', 'safecomms'),
        ];
    }

    protected function get_sortable_columns(): array
    {
        return [];
    }

    protected function get_bulk_actions(): array
    {
        return [
            'safecomms_rescan' => __('Re-scan', 'safecomms'),
            'safecomms_allow' => __('Mark as allowed', 'safecomms'),
        ];
    }

    protected function column_cb($item): string
    {
        return '<input type="checkbox" name="entry[]" value="' . esc_attr($item['id']) . '" />';
    }

    protected function column_ref_id($item): string
    {
        $title = $this->resolve_title($item);
        return esc_html($title) . ' (#' . esc_html($item['ref_id']) . ')';
    }

    protected function column_status($item): string
    {
        $status = esc_html($item['status']);
        if ($status === 'block') {
            $status = '<span style="color:#d63638;">' . $status . '</span>';
        }

        $actions = [
            'rescan' => '<a href="' . esc_url($this->action_url('rescan', $item)) . '">' . esc_html__('Re-scan', 'safecomms') . '</a>',
            'allow' => '<a href="' . esc_url($this->action_url('allow', $item)) . '">' . esc_html__('Allow', 'safecomms') . '</a>',
        ];

        return $status . ' ' . $this->row_actions($actions);
    }

    protected function column_modifications($item): string
    {
        if (empty($item['details'])) {
            return '-';
        }

        $details = json_decode($item['details'], true);
        if (!is_array($details)) {
            return '-';
        }

        $mods = [];
        if (!empty($details['addons']['replacedUnsafe'])) {
            $mods[] = __('Text Replacement', 'safecomms');
        }
        if (!empty($details['addons']['replacedPii'])) {
            $mods[] = __('PII Redaction', 'safecomms');
        }
        
        if (empty($mods)) {
            return '-';
        }

        return implode(', ', $mods);
    }

    protected function column_default($item, $column_name): string
    {
        switch ($column_name) {
            case 'ref_type':
            case 'reason':
            case 'updated_at':
                return esc_html((string) $item[$column_name]);
            case 'score':
                return esc_html((string) ($item['score'] ?? ''));
            default:
                return '';
        }
    }

    public function prepare_items(): void
    {
        $per_page = $this->get_items_per_page('safecomms_moderation_per_page', 20);
        $current_page = $this->get_pagenum();

        $result = $this->repo->fetch($current_page, $per_page, $this->filters);
        $this->items = $result['rows'];

        $this->set_pagination_args([
            'total_items' => $result['total'],
            'per_page' => $per_page,
        ]);
    }

    private function action_url(string $action, array $item): string
    {
        return wp_nonce_url(
            add_query_arg([
                'page' => 'safecomms_moderation',
                'sc_action' => $action,
                'entry_id' => $item['id'],
            ], admin_url('admin.php')),
            'safecomms_action'
        );
    }

    private function resolve_title(array $item): string
    {
        if ($item['ref_type'] === 'post') {
            $post = get_post((int) $item['ref_id']);
            return $post ? $post->post_title : __('(Post)', 'safecomms');
        }

        if ($item['ref_type'] === 'comment') {
            $comment = get_comment((int) $item['ref_id']);
            return $comment ? wp_trim_words($comment->comment_content, 8) : __('(Comment)', 'safecomms');
        }

        return __('(Unknown)', 'safecomms');
    }
}
