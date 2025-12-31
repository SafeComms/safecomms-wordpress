<?php
/**
 * Logger.
 *
 * @package SafeComms
 */

namespace SafeComms\Logging;

use SafeComms\Database\Logs_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persist and forward logs.
 */
class Logger {

	/**
	 * Logs repository.
	 *
	 * @var Logs_Repository
	 */
	private Logs_Repository $repo;

	/**
	 * Constructor.
	 *
	 * @param Logs_Repository $repo Logs repository.
	 */
	public function __construct( Logs_Repository $repo ) {
		$this->repo = $repo;
	}

	/**
	 * Debug log.
	 *
	 * @param string $type    Log type.
	 * @param string $message Message.
	 * @param array  $context Context data.
	 *
	 * @return void
	 */
	public function debug( string $type, string $message, array $context = array() ): void {
		$this->log( 'debug', $type, $message, $context );
	}

	/**
	 * Info log.
	 *
	 * @param string $type    Log type.
	 * @param string $message Message.
	 * @param array  $context Context data.
	 *
	 * @return void
	 */
	public function info( string $type, string $message, array $context = array() ): void {
		$this->log( 'info', $type, $message, $context );
	}

	/**
	 * Warning log.
	 *
	 * @param string $type    Log type.
	 * @param string $message Message.
	 * @param array  $context Context data.
	 *
	 * @return void
	 */
	public function warn( string $type, string $message, array $context = array() ): void {
		$this->log( 'warning', $type, $message, $context );
	}

	/**
	 * Error log.
	 *
	 * @param string $type    Log type.
	 * @param string $message Message.
	 * @param array  $context Context data.
	 *
	 * @return void
	 */
	public function error( string $type, string $message, array $context = array() ): void {
		$this->log( 'error', $type, $message, $context );
	}

	/**
	 * Write a log entry.
	 *
	 * @param string $level   Severity level.
	 * @param string $type    Log type.
	 * @param string $message Message.
	 * @param array  $context Context data.
	 *
	 * @return void
	 */
	private function log( string $level, string $type, string $message, array $context = array() ): void {
		$safe_context = $this->redact( $context );
		$this->repo->insert( $type, $level, $message, $safe_context, $context['ref_type'] ?? null, $context['ref_id'] ?? null );
		$formatted = sprintf( '[SafeComms] [%s] [%s] %s %s', $level, $type, $message, wp_json_encode( $safe_context ) );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- allow debug logging when WP_DEBUG is enabled.
			error_log( $formatted );
		}
	}

	/**
	 * Redact sensitive context data.
	 *
	 * @param array $context Context data.
	 *
	 * @return array
	 */
	private function redact( array $context ): array {
		// Remove full content/body to avoid logging large amounts of data.
		unset( $context['content'], $context['body'] );

		// Redact PII fields.
		$pii_fields = array( 'author', 'email', 'ip', 'user_login', 'comment_author', 'comment_author_email' );
		foreach ( $pii_fields as $field ) {
			if ( isset( $context[ $field ] ) ) {
				$context[ $field ] = '[REDACTED]';
			}
		}

		return $context;
	}
}
