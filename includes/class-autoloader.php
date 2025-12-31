<?php
/**
 * Autoloader class.
 *
 * @package SafeComms
 */

namespace SafeComms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Autoloader
 */
final class Autoloader {

	/**
	 * Register autoloader.
	 *
	 * @return void
	 */
	public static function register(): void {
		spl_autoload_register( array( self::class, 'autoload' ) );
	}

	/**
	 * Autoload class.
	 *
	 * @param string $class_name_full Class name.
	 * @return void
	 */
	private static function autoload( string $class_name_full ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( strpos( $class_name_full, $prefix ) !== 0 ) {
			return;
		}

		$relative_class = substr( $class_name_full, strlen( $prefix ) );
		$parts          = explode( '\\', $relative_class );
		$class_name     = array_pop( $parts );

		// Convert ClassName to class-name.
		$class_filename = 'class-' . strtolower( str_replace( '_', '-', preg_replace( '/(?<!^)[A-Z]/', '-$0', $class_name ) ) );

		$directory = implode( '/', $parts );
		if ( ! empty( $directory ) ) {
			$directory .= '/';
		}

		$path = SAFECOMMS_PLUGIN_DIR . 'includes/' . $directory . $class_filename . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
