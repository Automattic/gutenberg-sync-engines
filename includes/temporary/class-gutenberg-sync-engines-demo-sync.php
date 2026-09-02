<?php
/**
 * TEMPORARY: the server half of the demo sync shortcut.
 *
 * Two browsers cannot talk to each other directly, so the shortcut goes
 * through the server: pressing it POSTs a new trigger here, and every open
 * editor polls this route a few times a second to notice it. The client half
 * is src/temporary/demo-sync-shortcut.ts, which also documents the flow.
 *
 * State is one option: `{ id, stage }`. `start` bumps the id and sets stage
 * 1; `advance` with the current id and stage moves it to the next stage. The
 * client decides who syncs at which stage. Delete this file, its require in
 * the plugin class, and the client module together.
 *
 * @package gutenberg-sync-engines
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Gutenberg_Sync_Engines_Demo_Sync' ) ) {
	/**
	 * REST route backing the demo sync shortcut.
	 *
	 * @since n.e.x.t
	 */
	class Gutenberg_Sync_Engines_Demo_Sync {
		const OPTION         = 'gutenberg_sync_engines_demo_sync';
		const REST_NAMESPACE = 'gutenberg-sync-engines/v1';

		/**
		 * Hooks route registration.
		 *
		 * @return void
		 */
		public function register(): void {
			add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		}

		/**
		 * Registers GET and POST /demo-sync.
		 *
		 * @return void
		 */
		public function register_routes(): void {
			$can = static function () {
				return current_user_can( 'edit_posts' );
			};

			register_rest_route(
				self::REST_NAMESPACE,
				'/demo-sync',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this, 'rest_get' ),
						'permission_callback' => $can,
					),
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this, 'rest_post' ),
						'permission_callback' => $can,
						'args'                => array(
							'action' => array(
								'required' => true,
								'type'     => 'string',
								'enum'     => array( 'start', 'advance' ),
							),
							'id'     => array(
								'required' => false,
								'type'     => 'integer',
							),
							'stage'  => array(
								'required' => false,
								'type'     => 'integer',
							),
						),
					),
				)
			);
		}

		/**
		 * Reads the trigger state.
		 *
		 * @return array{id:int,stage:int}
		 */
		private function get_state(): array {
			$state = get_option( self::OPTION, array() );
			if ( ! is_array( $state ) ) {
				$state = array();
			}

			return array(
				'id'    => isset( $state['id'] ) ? (int) $state['id'] : 0,
				'stage' => isset( $state['stage'] ) ? (int) $state['stage'] : 0,
			);
		}

		/**
		 * GET /demo-sync: the current trigger state.
		 *
		 * @return WP_REST_Response
		 */
		public function rest_get(): WP_REST_Response {
			return rest_ensure_response( $this->get_state() );
		}

		/**
		 * POST /demo-sync: `start` opens a new round (stage 1); `advance`
		 * moves the named round on from the named stage.
		 *
		 * @param WP_REST_Request $request The request.
		 * @return WP_REST_Response
		 */
		public function rest_post( WP_REST_Request $request ): WP_REST_Response {
			$state  = $this->get_state();
			$action = $request->get_param( 'action' );

			if ( 'start' === $action ) {
				$state = array(
					'id'    => $state['id'] + 1,
					'stage' => 1,
				);
				update_option( self::OPTION, $state, false );
			} elseif ( 'advance' === $action ) {
				$id    = (int) $request->get_param( 'id' );
				$stage = (int) $request->get_param( 'stage' );
				if ( $id === $state['id'] && $stage === $state['stage'] ) {
					++$state['stage'];
					update_option( self::OPTION, $state, false );
				}
			}

			return rest_ensure_response( $state );
		}
	}
}
