<?php
/**
 * API Client class.
 *
 * @package SafeComms
 */

namespace SafeComms\API;

use SafeComms\Admin\Settings;
use SafeComms\Logging\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Client
 */
class Client {

	/**
	 * API URL.
	 *
	 * @var string
	 */
	private const API_URL = 'https://api.safecomms.dev/api/v1/public/';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 * @param Logger   $logger   Logger.
	 */
	public function __construct( Settings $settings, Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Get API URL.
	 *
	 * @return string
	 */
	private function get_api_url(): string {
		return apply_filters( 'safecomms_api_url', self::API_URL );
	}

	/**
	 * Scan content.
	 *
	 * @param string      $body       Content body.
	 * @param array       $context    Context data.
	 * @param string|null $profile_id Profile ID.
	 * @return array
	 */
	public function scan_content( string $body, array $context = array(), ?string $profile_id = null ): array {
		$api_key = $this->settings->get( 'api_key', '' );
		if ( '' === $api_key ) {
			return array(
				'status'  => 'error',
				'reason'  => 'missing_api_key',
				'message' => __( 'SafeComms API key is not configured.', 'safecomms' ),
			);
		}

		$language = 'English';
		if ( $this->settings->get( 'enable_non_english', false ) ) {
			$locale   = get_locale();
			$language = substr( $locale, 0, 2 );
		}

		$payload = array(
			'content'               => $body,
			'language'              => $language,
			'replace'               => $this->settings->get( 'enable_text_replacement', false ),
			'pii'                   => $this->settings->get( 'enable_pii_redaction', false ),
			'replace_severity'      => null,
			'moderation_profile_id' => $profile_id,
		);

		$args = array(
			'body'      => wp_json_encode( $payload ),
			'headers'   => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
			'timeout'   => apply_filters( 'safecomms_api_timeout', 10 ),
			'blocking'  => true,
			'sslverify' => true,
		);

		$response = wp_remote_post( $this->get_api_url() . 'moderation/text', $args );

		if ( is_wp_error( $response ) ) {
			$this->logger->error( 'api', $response->get_error_message(), $context );
			return array(
				'status'  => 'error',
				'reason'  => 'network_error',
				'message' => $response->get_error_message(),
			);
		}

		$code          = wp_remote_retrieve_response_code( $response );
		$body_response = wp_remote_retrieve_body( $response );
		$result        = json_decode( $body_response, true );

		if ( 402 === $code ) {
			$this->logger->error( 'quota', 'SafeComms plan quota exceeded.', $context );
			update_option( 'safecomms_quota_exceeded', true );
			return array(
				'status'  => 'error',
				'reason'  => 'quota_exceeded',
				'message' => __( 'SafeComms plan quota exceeded.', 'safecomms' ),
			);
		}

		if ( 429 === $code ) {
			$this->logger->warn( 'rate_limit', __( 'Rate limited by SafeComms.', 'safecomms' ), $context );
			return array(
				'status'  => 'rate_limited',
				'reason'  => 'rate_limited',
				'message' => __( 'Rate limited by SafeComms.', 'safecomms' ),
			);
		}

		if ( in_array( $code, array( 401, 403 ), true ) ) {
			$this->logger->error( 'auth', 'Unauthorized SafeComms request', $context );
			return array(
				'status'  => 'error',
				'reason'  => 'unauthorized',
				'message' => __( 'Unauthorized SafeComms request.', 'safecomms' ),
			);
		}

		if ( $code >= 400 ) {
			$this->logger->error(
				'api',
				'API Error ' . $code,
				array(
					'code'     => $code,
					'response' => $body_response,
				) + $context
			);
			return array(
				'status'  => 'error',
				'reason'  => 'unexpected_response',
				'message' => __( 'Unexpected response from SafeComms.', 'safecomms' ),
			);
		}

		if ( ! is_array( $result ) ) {
			$this->logger->error( 'api', 'Invalid JSON response', array( 'response' => $body_response ) + $context );
			return array(
				'status'  => 'error',
				'reason'  => 'invalid_json',
				'message' => __( 'Invalid response from SafeComms.', 'safecomms' ),
			);
		}

		$status = $result['status'] ?? null;
		if ( null === $status && isset( $result['isClean'] ) ) {
			$status = $result['isClean'] ? 'allow' : 'block';
		}

		return array(
			'status'  => $status ?? 'allow',
			'reason'  => $result['reason'] ?? ( $result['severity'] ?? '' ),
			'score'   => $result['score'] ?? ( $result['severity'] ?? null ),
			'details' => $result,
		);
	}

	/**
	 * Get usage.
	 *
	 * @return array
	 */
	public function get_usage(): array {
		$api_key = $this->settings->get( 'api_key', '' );
		if ( '' === $api_key ) {
			return array( 'error' => 'no_key' );
		}

		$args = array(
			'headers'   => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Accept'        => 'application/json',
			),
			'timeout'   => apply_filters( 'safecomms_api_timeout', 10 ),
			'sslverify' => true,
		);

		$response = wp_remote_get( $this->get_api_url() . 'usage', $args );

		if ( is_wp_error( $response ) ) {
			return array( 'error' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return array(
				'error' => 'api_error',
				'code'  => $code,
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		return is_array( $data ) ? $data : array( 'error' => 'invalid_json' );
	}
}
