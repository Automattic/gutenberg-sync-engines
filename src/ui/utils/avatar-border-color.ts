/**
 * Avatar border colors chosen to be visually distinct from each other and
 * from the editor's semantic UI colors. Copied from the editor package's
 * collab-sidebar helpers so the plugin does not reach into them.
 */
const AVATAR_BORDER_COLORS = [
	'#6F42C1', // Purple
	'#D94145', // Red
	'#FBBF24', // Orange
	'#FF35EE', // Magenta
	'#879F11', // Olive
	'#0F766E', // Teal
	'#00CFFF', // Cyan
];

/**
 * Gets the border color for an avatar based on the user ID.
 *
 * Always returns a 6-digit `#RRGGBB` hex string; callers (e.g. the highlight
 * styles) rely on this format to append alpha suffixes.
 *
 * @param userId The user ID (or a client id for fallback collaborators).
 * @return The border color as a `#RRGGBB` hex string.
 */
export function getAvatarBorderColor( userId: number ): string {
	return AVATAR_BORDER_COLORS[ userId % AVATAR_BORDER_COLORS.length ];
}
