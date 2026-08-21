/**
 * External dependencies
 */
import { SelectControl as WPSelectControl } from '@wordpress/components';
import type { ComponentType } from 'react';

/**
 * Internal dependencies
 */
import type { DesignSystemSelectControlProps, SelectItem } from './types';

/**
 * Get the design-system SelectControl if available (CIAB context),
 * otherwise return null to use fallback.
 *
 * Uses window.wp.hooks directly to ensure we use the same hooks instance as CIAB.
 * The bundled @wordpress/hooks in woocommerce-shipping is a separate instance.
 */
const getDesignSystemSelectControl =
	(): ComponentType< DesignSystemSelectControlProps > | null => {
		// Use global wp.hooks to share the same instance with CIAB
		const result = window.wp?.hooks?.applyFilters(
			'woocommerce_shipping.components.SelectControl',
			null
		);
		return result as ComponentType< DesignSystemSelectControlProps > | null;
	};

interface SelectControlProps {
	label?: string;
	hideLabelFromVision?: boolean;
	help?: string;
	value?: string;
	options?: SelectItem[];
	items?: SelectItem[];
	onChange?: ( value: string ) => void;
	onValueChange?: ( value: string ) => void;
	disabled?: boolean;
	required?: boolean;
	className?: string;
	// WP-specific props (ignored in design-system version)
	__next40pxDefaultSize?: boolean;
	__nextHasNoMarginBottom?: boolean;
}

/**
 * SelectControl that uses design-system version in CIAB context,
 * falls back to WordPress SelectControl otherwise.
 */
export const SelectControl = ( {
	label,
	hideLabelFromVision,
	help,
	value,
	options,
	items,
	onChange,
	onValueChange,
	disabled,
	required,
	className,
	__next40pxDefaultSize,
	__nextHasNoMarginBottom,
}: SelectControlProps ) => {
	const DesignSystemSelect = getDesignSystemSelectControl();

	// Use design-system SelectControl if available
	if ( DesignSystemSelect ) {
		const dsItems = items ?? options ?? [];
		return (
			<DesignSystemSelect
				label={ label }
				hideLabelFromVision={ hideLabelFromVision }
				description={ help }
				items={ dsItems }
				value={ value }
				onValueChange={ onValueChange ?? onChange }
				disabled={ disabled }
				required={ required }
				className={ className }
			/>
		);
	}

	// Fallback to WordPress SelectControl
	return (
		<WPSelectControl
			label={ label }
			hideLabelFromVision={ hideLabelFromVision }
			help={ help }
			value={ value }
			options={ options ?? items }
			onChange={ onChange ?? onValueChange }
			disabled={ disabled }
			// @ts-ignore - WP-specific props
			__next40pxDefaultSize={ __next40pxDefaultSize }
			__nextHasNoMarginBottom={ __nextHasNoMarginBottom }
		/>
	);
};

export type { SelectItem, DesignSystemSelectControlProps };
