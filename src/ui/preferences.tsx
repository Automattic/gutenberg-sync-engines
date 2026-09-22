/**
 * The collaboration preferences, which used to be four toggles in
 * Gutenberg's preferences modal under the `core` scope. The plugin keeps
 * them under its own scope and offers them from the editor's more menu.
 */

/**
 * WordPress dependencies
 */
import { Modal, ToggleControl } from '@wordpress/components';
import { dispatch, useDispatch, useSelect } from '@wordpress/data';
import { PluginMoreMenuItem } from '@wordpress/editor';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { people } from '@wordpress/icons';
import { store as preferencesStore } from '@wordpress/preferences';

export const PREFERENCES_SCOPE = 'gutenberg-sync-engines';

export const PREFERENCE_DEFAULTS = {
	showCollaborationCursor: false,
	showCollaborationJoinNotifications: true,
	showCollaborationLeaveNotifications: true,
	showCollaborationPostSaveNotifications: true,
};

/**
 * Registers the defaults so an unset preference reads as its default.
 */
export function registerPreferenceDefaults(): void {
	dispatch( preferencesStore ).setDefaults(
		PREFERENCES_SCOPE,
		PREFERENCE_DEFAULTS
	);
}

interface PreferenceToggleProps {
	featureName: keyof typeof PREFERENCE_DEFAULTS;
	label: string;
	help: string;
}

/**
 * One toggle bound to a preference in the plugin's scope.
 *
 * @param props             Props.
 * @param props.featureName The preference key.
 * @param props.label       The toggle label.
 * @param props.help        The help text.
 */
function PreferenceToggle( {
	featureName,
	label,
	help,
}: PreferenceToggleProps ) {
	const isChecked = useSelect(
		( select ) =>
			!! select( preferencesStore ).get( PREFERENCES_SCOPE, featureName ),
		[ featureName ]
	);
	const { toggle } = useDispatch( preferencesStore );

	return (
		<ToggleControl
			__nextHasNoMarginBottom
			checked={ isChecked }
			label={ label }
			help={ help }
			onChange={ () => toggle( PREFERENCES_SCOPE, featureName ) }
		/>
	);
}

/**
 * The "Collaboration preferences" entry in the editor's more menu, and the
 * small modal it opens.
 */
export function CollaborationPreferencesMenuItem() {
	const [ isOpen, setIsOpen ] = useState( false );

	return (
		<>
			<PluginMoreMenuItem
				icon={ people }
				onClick={ () => setIsOpen( true ) }
			>
				{ __( 'Collaboration preferences' ) }
			</PluginMoreMenuItem>
			{ isOpen && (
				<Modal
					title={ __( 'Collaboration preferences' ) }
					onRequestClose={ () => setIsOpen( false ) }
					className="gutenberg-sync-engines-preferences-modal"
					size="medium"
				>
					<PreferenceToggle
						featureName="showCollaborationCursor"
						help={ __(
							'Show your own avatar inside blocks during collaborative editing sessions.'
						) }
						label={ __( 'Show avatar in blocks' ) }
					/>
					<PreferenceToggle
						featureName="showCollaborationJoinNotifications"
						help={ __(
							'Show notifications when collaborators join the post.'
						) }
						label={ __( 'Collaborator joined' ) }
					/>
					<PreferenceToggle
						featureName="showCollaborationLeaveNotifications"
						help={ __(
							'Show notifications when collaborators leave the post.'
						) }
						label={ __( 'Collaborator left' ) }
					/>
					<PreferenceToggle
						featureName="showCollaborationPostSaveNotifications"
						help={ __(
							'Show notifications when collaborators save, update, or publish the post.'
						) }
						label={ __( 'Post updated' ) }
					/>
				</Modal>
			) }
		</>
	);
}
