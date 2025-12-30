<?php
namespace SafeComms\API;

use SafeComms\Admin\Settings;
use SafeComms\Logging\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class Client
{
    private const API_URL = 'https://api.safecomms.dev/api/v1/public/';

    private Settings $settings;
    private Logger $logger;

    public function __construct(Settings $settings, Logger $logger)
    {
        $this->settings = $settings;
        $this->logger = $logger;
    }

    private function get_api_url(): string
    {
        return apply_filters('safecomms_api_url', self::API_URL);
    }

    public function scan_content(string $body, array $context = [], ?string $profile_id = null): array
    {
        $api_key = $this->settings->get('api_key', '');
        if ($api_key === '') {
            return [
                'status' => 'error',
                'reason' => 'missing_api_key',
                'message' => __('SafeComms API key is not configured.', 'safecomms'),
            ];
        }

        $language = 'English';
        if ($this->settings->get('enable_non_english', false)) {
            $locale = get_locale();
            $language = substr($locale, 0, 2);
        }

        $payload = [
            'content' => $body,
            'language' => $language,
            'replace' => $this->settings->get('enable_text_replacement', false),
            'pii' => $this->settings->get('enable_pii_redaction', false),
            'replace_severity' => null,
            'moderation_profile_id' => $profile_id,
        ];

        $args = [
            'body' => wp_json_encode($payload),
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'timeout' => apply_filters('safecomms_api_timeout', 10),
            'blocking' => true,
            'sslverify' => true,
        ];

        $response = wp_remote_post($this->get_api_url() . 'moderation/text', $args);

        if (is_wp_error($response)) {
            $this->logger->error('api', $response->get_error_message(), $context);
            return [
                'status' => 'error',
                'reason' => 'network_error',
                'message' => $response->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body_response = wp_remote_retrieve_body($response);
        $result = json_decode($body_response, true);

        if ($code === 402) {
            $this->logger->error('quota', 'SafeComms plan quota exceeded.', $context);
            update_option('safecomms_quota_exceeded', true);
            return [
                'status' => 'error',
                'reason' => 'quota_exceeded',
                'message' => __('SafeComms plan quota exceeded.', 'safecomms'),
            ];
        }
        
        if ($code === 429) {
            $this->logger->warn('rate_limit', __('Rate limited by SafeComms.', 'safecomms'), $context);
            return [
                'status' => 'rate_limited',
                'reason' => 'rate_limited',
                'message' => __('Rate limited by SafeComms.', 'safecomms'),
            ];
        }

        if (in_array($code, [401, 403], true)) {
            $this->logger->error('auth', 'Unauthorized SafeComms request', $context);
            return [
                'status' => 'error',
                'reason' => 'unauthorized',
                'message' => __('Unauthorized SafeComms request.', 'safecomms'),
            ];
        }

        if ($code >= 400) {
            $this->logger->error('api', 'API Error ' . $code, ['code' => $code, 'response' => $body_response] + $context);
            return [
                'status' => 'error',
                'reason' => 'unexpected_response',
                'message' => __('Unexpected response from SafeComms.', 'safecomms'),
            ];
        }

        if (!is_array($result)) {
             $this->logger->error('api', 'Invalid JSON response', ['response' => $body_response] + $context);
             return [
                'status' => 'error',
                'reason' => 'invalid_json',
                'message' => __('Invalid response from SafeComms.', 'safecomms'),
            ];
        }

        $status = $result['status'] ?? null;
        if ($status === null && isset($result['isClean'])) {
            $status = $result['isClean'] ? 'allow' : 'block';
        }

        return [
            'status' => $status ?? 'allow',
            'reason' => $result['reason'] ?? ($result['severity'] ?? ''),
            'score' => $result['score'] ?? ($result['severity'] ?? null),
            'details' => $result,
        ];
    }

    public function get_usage(): array
    {
        $api_key = $this->settings->get('api_key', '');
        if ($api_key === '') {
            return ['error' => 'no_key'];
        }

        $args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Accept' => 'application/json',
            ],
            'timeout' => apply_filters('safecomms_api_timeout', 10),
            'sslverify' => true,
        ];

        $response = wp_remote_get($this->get_api_url() . 'usage', $args);

        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return ['error' => 'api_error', 'code' => $code];
        }

        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true) ?: ['error' => 'invalid_json'];
    }
}
