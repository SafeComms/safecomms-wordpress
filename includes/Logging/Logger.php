<?php
namespace SafeComms\Logging;

use SafeComms\Database\LogsRepository;

if (!defined('ABSPATH')) {
    exit;
}

class Logger
{
    private LogsRepository $repo;

    public function __construct(LogsRepository $repo)
    {
        $this->repo = $repo;
    }

    public function debug(string $type, string $message, array $context = []): void
    {
        $this->log('debug', $type, $message, $context);
    }

    public function info(string $type, string $message, array $context = []): void
    {
        $this->log('info', $type, $message, $context);
    }

    public function warn(string $type, string $message, array $context = []): void
    {
        $this->log('warning', $type, $message, $context);
    }

    public function error(string $type, string $message, array $context = []): void
    {
        $this->log('error', $type, $message, $context);
    }

    private function log(string $level, string $type, string $message, array $context = []): void
    {
        $safe_context = $this->redact($context);
        $this->repo->insert($type, $level, $message, $safe_context, $context['ref_type'] ?? null, $context['ref_id'] ?? null);
        $formatted = sprintf('[SafeComms] [%s] [%s] %s %s', $level, $type, $message, wp_json_encode($safe_context));
        error_log($formatted);
    }

    private function redact(array $context): array
    {
        // Remove full content/body to avoid logging large amounts of data
        unset($context['content'], $context['body']);
        
        // Redact PII fields
        $pii_fields = ['author', 'email', 'ip', 'user_login', 'comment_author', 'comment_author_email'];
        foreach ($pii_fields as $field) {
            if (isset($context[$field])) {
                $context[$field] = '[REDACTED]';
            }
        }
        
        return $context;
    }
}
