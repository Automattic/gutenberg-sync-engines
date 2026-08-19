/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/*
 * Save-through-the-room (TODO-12): while a de-rtc session is live for a
 * post, the editor's ordinary REST save carries `base_version` — the
 * version the session last applied — so the server's base-version
 * preflight merges the save through the room instead of letting it
 * bypass sync entirely (the TODO-14 "stamped but neither merges nor
 * heals" gap, closed for editor saves).
 *
 * Mechanics: an apiFetch middleware injects `base_version` into
 * POST/PUT bodies for /wp/v2/posts|pages/<id> while a session is
 * registered for that post. The common case merges cleanly (the editor
 * tree ≈ canonical + pending, proposed against a fresh base); an
 * unsalvageable structural conflict rejects the save with the
 * preflight's 409 and the editor surfaces a failed save — retrying
 * after the next sync round almost always succeeds. Autosaves are
 * deliberately excluded (their REST route updates a revision object;
 * the preflight does not cover that lane yet).
 */

/** postType -> REST base for the routes the server preflight covers. */
const REST_BASES: Record< string, string > = {
	post: 'posts',
	page: 'pages',
};

/** Live sessions' version getters, keyed `postType:id`. */
const liveSessions = new Map< string, () => string | null >();
let middlewareRegistered = false;

function ensureMiddleware(): void {
	if ( middlewareRegistered ) {
		return;
	}
	middlewareRegistered = true;
	apiFetch.use( ( options, next ) => {
		const path = String( options.path ?? '' );
		const method = String( options.method ?? 'GET' ).toUpperCase();
		const body = options.data as Record< string, unknown > | undefined;
		if (
			( 'POST' === method || 'PUT' === method ) &&
			body &&
			'object' === typeof body &&
			-1 === path.indexOf( '/autosaves' )
		) {
			for ( const [ type, base ] of Object.entries( REST_BASES ) ) {
				const match = new RegExp(
					'/wp/v2/' + base + '/(\\d+)(?:\\?|$)'
				).exec( path );
				if ( ! match ) {
					continue;
				}
				const version = liveSessions.get( type + ':' + match[ 1 ] )?.();
				if ( version && undefined === body.base_version ) {
					options = {
						...options,
						data: { ...body, base_version: version },
					};
				}
				break;
			}
		}
		return next( options );
	} );
}

/**
 * Registers a live de-rtc session so its post's saves carry
 * `base_version`.
 *
 * @param objectType  Sync object type (e.g. `postType/post`).
 * @param objectId    Object id.
 * @param lastVersion The session bridge's version getter.
 * @return Unregister function (call on entity destroy).
 */
export function registerSaveBaseVersion(
	objectType: string,
	objectId: unknown,
	lastVersion: () => string | null
): () => void {
	const match = /^postType\/(.+)$/.exec( objectType );
	if ( ! match || ! REST_BASES[ match[ 1 ] ] ) {
		return () => {};
	}
	const key = match[ 1 ] + ':' + String( objectId );
	liveSessions.set( key, lastVersion );
	ensureMiddleware();
	return () => {
		liveSessions.delete( key );
	};
}
