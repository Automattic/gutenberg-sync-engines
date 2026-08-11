<?php
/**
 * Observable event emitter.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Lib0;

/**
 * Minimal Observable port.
 */
class Observable {
	/**
	 * Event observers.
	 *
	 * @var array<string,array<int,callable>>
	 */
	protected array $observers = array();

	/**
	 * @param string   $name Event name.
	 * @param callable $fn   Listener.
	 * @return void
	 */
	public function on( string $name, callable $fn ): void {
		$this->observers[ $name ] ??= array();
		$this->observers[ $name ][] = $fn;
	}

	/**
	 * @param string   $name Event name.
	 * @param callable $fn   Listener.
	 * @return void
	 */
	public function once( string $name, callable $fn ): void {
		$self = $this;
		$once = static function ( ...$args ) use ( $self, $name, $fn, &$once ): void {
			$self->off( $name, $once );
			$fn( ...$args );
		};
		$this->on( $name, $once );
	}

	/**
	 * @param string   $name Event name.
	 * @param callable $fn   Listener.
	 * @return void
	 */
	public function off( string $name, callable $fn ): void {
		if ( ! isset( $this->observers[ $name ] ) ) {
			return;
		}
		foreach ( $this->observers[ $name ] as $index => $observer ) {
			if ( $observer === $fn ) {
				unset( $this->observers[ $name ][ $index ] );
			}
		}
		if ( array() === $this->observers[ $name ] ) {
			unset( $this->observers[ $name ] );
		}
	}

	/**
	 * @param string $name Event name.
	 * @return bool
	 */
	public function hasObservers( string $name ): bool {
		return ! empty( $this->observers[ $name ] );
	}

	/**
	 * @param string           $name Event name.
	 * @param array<int,mixed> $args Arguments.
	 * @return void
	 */
	public function emit( string $name, array $args ): void {
		$observers = $this->observers[ $name ] ?? array();
		foreach ( array_values( $observers ) as $observer ) {
			$observer( ...$args );
		}
	}

	/**
	 * @return void
	 */
	public function destroy(): void {
		$this->observers = array();
	}
}
