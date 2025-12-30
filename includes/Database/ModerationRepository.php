<?php
namespace SafeComms\Database;

if (!defined('ABSPATH')) {
    exit;
}

class ModerationRepository
{
    public function upsert(string $ref_type, int $ref_id, array $decision, string $content_hash = '', int $attempts = 0): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'safecomms_moderation';

        $data = [
            'ref_type' => $ref_type,
            'ref_id' => $ref_id,
            'status' => $decision['status'] ?? 'unknown',
            'score' => $decision['score'] ?? null,
            'reason' => $decision['reason'] ?? '',
            'details' => isset($decision['details']) ? wp_json_encode($decision['details']) : null,
            'content_hash' => $content_hash,
            'attempts' => $attempts,
            'updated_at' => current_time('mysql'),
        ];

        $existing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE ref_type = %s AND ref_id = %d LIMIT 1",
            $ref_type,
            $ref_id
        ));

        if ($existing_id) {
            $wpdb->update(
                $table,
                $data,
                ['id' => $existing_id],
                null,
                ['%d']
            );
        } else {
            $data['created_at'] = current_time('mysql');
            $wpdb->insert($table, $data);
        }

        // Invalidate blocked count cache
        wp_cache_delete('safecomms_blocked_count', 'safecomms');
    }

    public function blocked_count(): int
    {
        $count = wp_cache_get('safecomms_blocked_count', 'safecomms');
        if (false !== $count) {
            return (int) $count;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'safecomms_moderation';
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'block'");
        
        wp_cache_set('safecomms_blocked_count', $count, 'safecomms', 300);
        return $count;
    }

    public function fetch(int $page = 1, int $per_page = 20, array $filters = []): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'safecomms_moderation';
        $offset = ($page - 1) * $per_page;

        $where_clauses = [];
        $where_params = [];

        if (!empty($filters['status'])) {
            $where_clauses[] = 'status = %s';
            $where_params[] = sanitize_text_field($filters['status']);
        }

        if (!empty($filters['ref_type'])) {
            $where_clauses[] = 'ref_type = %s';
            $where_params[] = sanitize_text_field($filters['ref_type']);
        }

        // Build WHERE clause safely by preparing each condition separately
        $where_sql = '1=1';
        if (!empty($where_clauses)) {
            $prepared_conditions = [];
            foreach ($where_clauses as $i => $clause) {
                $prepared_conditions[] = $wpdb->prepare($clause, $where_params[$i]);
            }
            $where_sql = implode(' AND ', $prepared_conditions);
        }
        
        // Now prepare only LIMIT and OFFSET
        $sql = $wpdb->prepare(
            "SELECT SQL_CALC_FOUND_ROWS * FROM {$table} WHERE {$where_sql} ORDER BY updated_at DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        $total = (int) $wpdb->get_var('SELECT FOUND_ROWS()');

        return ['rows' => $rows, 'total' => $total];
    }

    public function find(int $id): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'safecomms_moderation';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);
        return $row ?: null;
    }
}
