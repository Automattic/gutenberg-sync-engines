<?php
/**
 * Runtime loader for the vendored y-php library.
 *
 * The y-php library ships Composer metadata, but the plugin cannot assume a Composer
 * autoloader at runtime, so this shim provides the equivalent wiring:
 * a PSR-4 autoloader for the `Yjs\` namespace plus the two eager files
 * Composer's `files` directive would load (namespace functions and
 * class aliases).
 *
 * Loading is deliberately lazy: parsing the function files costs real
 * time on requests that never touch Yjs, so nothing is registered until
 * the first caller (the yjs-server engine) asks for it.
 *
 * @package GutenbergSyncEngines
 */

if ( ! function_exists( 'gutenberg_sync_engines_load_y_php' ) ) {

	/**
	 * Loads the vendored y-php library. Idempotent.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	function gutenberg_sync_engines_load_y_php(): void {
		static $loaded = false;
		if ( $loaded ) {
			return;
		}
		$loaded = true;

		$base = __DIR__ . '/y-php/src/';

		spl_autoload_register(
			static function ( string $class_name ) use ( $base ): void {
				if ( 0 !== strpos( $class_name, 'Yjs\\' ) ) {
					return;
				}
				$path = $base . str_replace( '\\', '/', substr( $class_name, 4 ) ) . '.php';
				if ( is_file( $path ) ) {
					require $path;
				}
			}
		);

		// Composer `files` equivalents: namespace functions and public aliases.
		require_once $base . 'functions.php';
		require_once $base . 'aliases.php';
	}
}
