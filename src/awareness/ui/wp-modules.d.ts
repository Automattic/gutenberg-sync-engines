/**
 * Minimal typings for the editor packages the awareness UI renders with.
 *
 * `@wordpress/editor` and `@wordpress/components` ship type declarations,
 * but pointing tsconfig at them pulls the whole vendored Gutenberg tree
 * (including packages without types) into this plugin's type-check. These
 * declarations cover only what the awareness UI uses; the packages are
 * externals at build time (`wp.editor`, `wp.components`).
 */

declare module '@wordpress/editor' {
	import type { ComponentType, ReactNode } from 'react';

	export const PluginSidebar: ComponentType< {
		name: string;
		title: string;
		icon?: ReactNode;
		children?: ReactNode;
	} >;
	export const PluginSidebarMoreMenuItem: ComponentType< {
		target: string;
		icon?: ReactNode;
		children?: ReactNode;
	} >;
}

declare module '@wordpress/components' {
	import type { ComponentType, ReactNode } from 'react';

	export const Button: ComponentType< {
		variant?: 'primary' | 'secondary' | 'tertiary' | 'link';
		size?: 'default' | 'compact' | 'small';
		onClick?: () => void;
		children?: ReactNode;
	} >;
	export const Flex: ComponentType< {
		justify?: string;
		align?: string;
		children?: ReactNode;
	} >;
	export const FlexItem: ComponentType< { children?: ReactNode } >;
	export const PanelBody: ComponentType< {
		title?: string;
		initialOpen?: boolean;
		children?: ReactNode;
	} >;
}
