<?php
namespace SafeComms\Database;

if (!defined('ABSPATH')) {
    exit;
}

class LogsRepository
{
    public function insert(string $type, string $severity, string $message, array $context = [], ?string $ref_type = null, ?int $ref_id = null): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'safecomms_logs';
        $wpdb->insert(
            $table,
            [
                'type' => $type,
                'ref_type' => $ref_type,
                'ref_id' => $ref_id,
                'severity' => $severity,
                'message' => $message,
                'context' => wp_json_encode($context),
                'created_at' => current_time('mysql'),
            ]
        );
    }

    public function fetch(int $page = 1, int $per_page = 20, array $filters = []): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'safecomms_logs';
        $offset = ($page - 1) * $per_page;

        $where = '1=1';
        $params = [];

        if (!empty($filters['severity'])) {
            $where .= ' AND severity = %s';
            $params[] = $filters['severity'];
        }

        if (!empty($filters['type'])) {
            $where .= ' AND type = %s';
            $params[] = $filters['type'];
        }

        $sql = $wpdb->prepare(
            "SELECT SQL_CALC_FOUND_ROWS * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
            array_merge($params, [$per_page, $offset])
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        $total = (int) $wpdb->get_var('SELECT FOUND_ROWS()');

        return ['rows' => $rows, 'total' => $total];
    }
}
