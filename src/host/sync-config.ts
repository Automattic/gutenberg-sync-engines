/**
 * The per-entity sync configuration: what a synced entity looks like to the
 * engines. This used to be built inside core-data's `entities.js`; the
 * plugin builds it here from the same rules, keyed by entity kind and name.
 */

/**
 * WordPress dependencies
 */
import { getBlockContent, getBlockType } from '@wordpress/blocks';
import { resolveSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';

/**
 * Internal dependencies
 */
import { getAnnouncedSync } from '../sync';
import type { CRDTDoc, ObjectData, ObjectID, SyncConfig } from '../sync';
import {
	applyPostChangesToCRDTDoc,
	defaultCollectionSyncConfig,
	defaultSyncConfig,
	getPostChangesFromCRDTDoc,
	type PostChanges,
} from '../engines/yjs/crdt/crdt';
import { PostEditorAwareness } from '../awareness/typed/post-editor-awareness';

/**
 * The post fields that sync. Taxonomy fields (by REST base) are added per
 * post type once the taxonomies are known.
 */
const BASE_POST_PROPERTIES = [
	'author',
	'blocks',
	'content',
	'comment_status',
	'date',
	'excerpt',
	'featured_media',
	'format',
	'meta',
	'ping_status',
	'slug',
	'status',
	'sticky',
	'template',
	'title',
];

const configs = new Map< string, SyncConfig | undefined >();

interface PostTypeRecord {
	taxonomies?: string[];
}

interface TaxonomyRecord {
	slug: string;
	rest_base?: string;
}

/**
 * Adds a post type's taxonomy REST bases to its synced properties once the
 * REST API has described them. Until then, edits to those fields are not
 * synced; the base fields are.
 *
 * @param name             Post type name.
 * @param syncedProperties The set to extend.
 */
async function addTaxonomyProperties(
	name: string,
	syncedProperties: Set< string >
): Promise< void > {
	try {
		const [ postType, taxonomies ] = await Promise.all( [
			resolveSelect( coreStore ).getEntityRecord(
				'root',
				'postType',
				name
			) as Promise< PostTypeRecord | undefined >,
			resolveSelect( coreStore ).getEntityRecords( 'root', 'taxonomy', {
				per_page: -1,
			} ) as Promise< TaxonomyRecord[] | null >,
		] );
		const byName = new Map(
			( taxonomies ?? [] ).map( ( taxonomy ) => [
				taxonomy.slug,
				taxonomy,
			] )
		);
		for ( const taxonomy of postType?.taxonomies ?? [] ) {
			const restBase = byName.get( taxonomy )?.rest_base;
			if ( restBase ) {
				syncedProperties.add( restBase );
			}
		}
	} catch {
		// The base fields still sync.
	}
}

function createPostTypeSyncConfig( name: string ): SyncConfig {
	const syncedProperties = new Set( BASE_POST_PROPERTIES );
	void addTaxonomyProperties( name, syncedProperties );

	return {
		applyChangesToCRDTDoc: ( crdtDoc, changes ) =>
			applyPostChangesToCRDTDoc(
				crdtDoc,
				changes as PostChanges,
				syncedProperties
			),

		createAwareness: ( ydoc: CRDTDoc, objectId?: ObjectID ) => {
			const id = parseInt( String( objectId ), 10 );
			return new PostEditorAwareness( ydoc, 'postType', name, id );
		},

		// The CRDT utilities are typed against the post entity; the sync core
		// hands records over as plain objects.
		getChangesFromCRDTDoc: ( crdtDoc, editedRecord: ObjectData ) =>
			getPostChangesFromCRDTDoc(
				crdtDoc,
				editedRecord as Parameters<
					typeof getPostChangesFromCRDTDoc
				>[ 1 ],
				syncedProperties
			),

		shouldSync: () =>
			! ( getAnnouncedSync()?.disabledPostTypes ?? [] ).includes( name ),

		/**
		 * Names a block type's rich-text attributes so engines with
		 * rich-text-coordinate capture know which attributes become text
		 * fields.
		 *
		 * @param blockName Block type name.
		 * @return Rich-text attribute names.
		 */
		richTextFields: ( blockName: string ) => {
			const blockType = getBlockType( blockName );
			if ( ! blockType ) {
				return [ 'content' ];
			}
			return Object.entries( blockType.attributes ?? {} )
				.filter(
					( [ , schema ] ) =>
						'html' === schema?.source ||
						'rich-text' === schema?.source
				)
				.map( ( [ key ] ) => key );
		},

		/**
		 * Blocks whose markup lives in innerContent fragments rather than
		 * any attribute. The block serializer special-cases core/html the
		 * same way.
		 *
		 * @param blockName Block type name.
		 * @return Whether the block is a raw-content block.
		 */
		isRawContentBlock: ( blockName: string ) =>
			'core/html' === blockName || 'core/freeform' === blockName,

		/**
		 * The full inner HTML of a raw-content block: static fragments plus
		 * serialized inner blocks. Falls back to the deprecated `content`
		 * attribute for blocks created via `createBlock( 'core/html',
		 * { content } )` that have not been migrated yet.
		 *
		 * @param block The block.
		 * @return Inner HTML.
		 */
		serializeRawContent: ( block ) => {
			if ( block.innerContent ) {
				return getBlockContent(
					block as Parameters< typeof getBlockContent >[ 0 ]
				);
			}
			return 'string' === typeof block.attributes?.content
				? block.attributes.content
				: '';
		},

		/**
		 * Where a raw-content block's HTML re-enters the editor block:
		 * core/html models content as innerContent fragments; classic
		 * content (core/freeform) as a raw-sourced content attribute.
		 *
		 * @param blockName Block type name.
		 * @param html      The block's inner HTML.
		 * @return Partial block (attributes or innerContent).
		 */
		hydrateRawContent: ( blockName: string, html: string ) =>
			'core/freeform' === blockName
				? { attributes: { content: html } }
				: { innerContent: '' === html ? [] : [ html ] },
	};
}

/**
 * The sync configuration for an entity, or undefined for entities that do
 * not sync. Post types sync per record, taxonomy terms sync per record
 * with the default config, and comments sync as a collection.
 *
 * @param kind Entity kind.
 * @param name Entity name.
 * @return The config, or undefined.
 */
export function getEntitySyncConfig(
	kind: string,
	name: string
): SyncConfig | undefined {
	const key = `${ kind }/${ name }`;
	if ( configs.has( key ) ) {
		return configs.get( key );
	}

	let config: SyncConfig | undefined;
	if ( 'postType' === kind ) {
		config = createPostTypeSyncConfig( name );
	} else if ( 'taxonomy' === kind ) {
		config = defaultSyncConfig;
	} else if ( 'root' === kind && 'comment' === name ) {
		config = defaultCollectionSyncConfig;
	}

	configs.set( key, config );
	return config;
}

/**
 * Forgets the cached configs. Test use only.
 */
export function resetEntitySyncConfigsForTesting(): void {
	configs.clear();
}
