<?php
/**
 * Runtime loader for the vendored automerge-php library.
 *
 * The automerge-php library ships Composer metadata (PSR-4 for the
 * `WordPress\DistributedEditing\Automerge\` namespace), but the plugin
 * cannot assume a Composer autoloader at runtime, so this shim registers
 * the equivalent autoloader.
 *
 * Loading is deliberately lazy: nothing is registered until the first
 * caller (the de-rtc engine) asks for it. The library requires PHP 8.2+
 * and the mbstring extension; callers gate on
 * gutenberg_sync_engines_automerge_php_is_supported() before loading.
 *
 * @package GutenbergSyncEngines
 */

if ( ! function_exists( 'gutenberg_sync_engines_automerge_php_is_supported' ) ) {

	/**
	 * Whether the current runtime can run the vendored automerge-php library.
	 *
	 * Mirrors the library's composer.json platform requirements. ext-json is
	 * unconditionally available on PHP 8.x; ext-intl and ext-libxml are listed
	 * by the library but only exercised on paths the engine does not use, so
	 * the hard gate is PHP 8.2 + mbstring.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when automerge-php can be loaded.
	 */
	function gutenberg_sync_engines_automerge_php_is_supported(): bool {
		return PHP_VERSION_ID >= 80200 && extension_loaded( 'mbstring' );
	}
}

if ( ! function_exists( 'gutenberg_sync_engines_load_automerge_php' ) ) {

	/**
	 * Loads the vendored automerge-php library. Idempotent.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	function gutenberg_sync_engines_load_automerge_php(): void {
		static $loaded = false;
		if ( $loaded ) {
			return;
		}
		$loaded = true;

		$base   = __DIR__ . '/automerge-php/src/';
		$prefix = 'WordPress\\DistributedEditing\\Automerge\\';

		spl_autoload_register(
			static function ( string $class_name ) use ( $base, $prefix ): void {
				if ( 0 !== strpos( $class_name, $prefix ) ) {
					return;
				}
				$path = $base . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
				if ( is_file( $path ) ) {
					require $path;
				}
			}
		);
	}
}
