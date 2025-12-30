<?php
namespace SafeComms\Admin;

use SafeComms\Database\LogsRepository;

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class LogsListTable extends \WP_List_Table
{
    private LogsRepository $repo;
    private array $filters;

    public function __construct(LogsRepository $repo, array $filters = [])
    {
        parent::__construct([
            'singular' => 'safecomms_log',
            'plural' => 'safecomms_logs',
            'ajax' => false,
        ]);

        $this->repo = $repo;
        $this->filters = $filters;
    }

    public function get_columns(): array
    {
        return [
            'type' => __('Type', 'safecomms'),
            'severity' => __('Severity', 'safecomms'),
            'message' => __('Message', 'safecomms'),
            'created_at' => __('Created', 'safecomms'),
        ];
    }

    protected function get_sortable_columns(): array
    {
        return [];
    }

    public function prepare_items(): void
    {
        $per_page = $this->get_items_per_page('safecomms_logs_per_page', 20);
        $current_page = $this->get_pagenum();

        $result = $this->repo->fetch($current_page, $per_page, $this->filters);
        $this->items = $result['rows'];

        $this->set_pagination_args([
            'total_items' => $result['total'],
            'per_page' => $per_page,
        ]);
    }

    protected function column_default($item, $column_name): string
    {
        switch ($column_name) {
            case 'type':
            case 'severity':
            case 'created_at':
                return esc_html((string) $item[$column_name]);
            case 'message':
                return esc_html((string) $item['message']);
            default:
                return '';
        }
    }
}
