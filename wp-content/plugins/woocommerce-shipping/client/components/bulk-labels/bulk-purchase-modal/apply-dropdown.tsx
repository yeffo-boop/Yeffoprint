import { Button, Dropdown, MenuGroup, MenuItem } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { Icon, chevronDown } from '@wordpress/icons';
import type { ReactNode } from 'react';

export interface ApplyOption {
	label: string;
	value: string;
	/** Optional leading visual (e.g. a carrier logo) shown left of the label. */
	icon?: ReactNode;
}

interface ApplyDropdownProps {
	options: ApplyOption[];
	/** Toolbar-style toggle: "📦 Package: <value>". Omit for a compact cell. */
	emoji?: string;
	label?: string;
	/** Controlled value — when set with `onSelect`, the parent owns state. */
	value?: string;
	/** Uncontrolled initial value (visual-only dropdowns). */
	defaultValue?: string;
	onSelect?: ( value: string ) => void;
	/** Accessible name for the toggle when there's no visible `label`. */
	ariaLabel?: string;
	/** Shown in the compact toggle when nothing is selected yet. */
	placeholder?: string;
}

/**
 * Shared "Apply to all" / per-row dropdown. Built on `Dropdown` +
 * `MenuGroup` + `MenuItem` so options can carry a leading icon (carrier
 * logo, etc.) — something `SelectControl` can't render. Toolbar controls
 * pass `emoji`/`label`; table cells use the compact toggle that just
 * shows the selected option (icon + label).
 */
export const ApplyDropdown = ( {
	options,
	emoji,
	label,
	value,
	defaultValue,
	onSelect,
	ariaLabel,
	placeholder,
}: ApplyDropdownProps ): ReactNode => {
	const isControlled = value !== undefined && onSelect !== undefined;
	const [ internalValue, setInternalValue ] = useState(
		defaultValue ?? options[ 0 ]?.value ?? ''
	);
	const currentValue = isControlled ? value : internalValue;
	const selectedOption = options.find(
		( option ) => option.value === currentValue
	);
	const displayLabel = selectedOption?.label ?? placeholder ?? currentValue;
	const isToolbarStyle = emoji !== undefined || label !== undefined;

	return (
		<Dropdown
			renderToggle={ ( { isOpen, onToggle } ) => (
				<Button
					className={
						isToolbarStyle
							? 'bulk-purchase-modal__apply-button'
							: 'bulk-purchase-modal__cell-dropdown-toggle'
					}
					onClick={ onToggle }
					aria-expanded={ isOpen }
					label={ ariaLabel }
				>
					{ isToolbarStyle ? (
						<>
							{ emoji !== undefined && (
								<span className="bulk-purchase-modal__apply-emoji">
									{ emoji }
								</span>
							) }
							{ label !== undefined && (
								<span className="bulk-purchase-modal__apply-label">
									{ label }:
								</span>
							) }
							<span className="bulk-purchase-modal__apply-value">
								{ displayLabel }
							</span>
						</>
					) : (
						<span className="bulk-purchase-modal__cell-dropdown-current">
							{ selectedOption?.icon && (
								<span className="bulk-purchase-modal__cell-dropdown-icon">
									{ selectedOption.icon }
								</span>
							) }
							<span className="bulk-purchase-modal__cell-dropdown-text">
								{ displayLabel }
							</span>
						</span>
					) }
					<Icon icon={ chevronDown } size={ 16 } />
				</Button>
			) }
			renderContent={ ( { onClose } ) => (
				<MenuGroup>
					{ options.map( ( option ) => (
						<MenuItem
							key={ option.value }
							isSelected={ option.value === currentValue }
							onClick={ () => {
								if ( isControlled ) {
									onSelect( option.value );
								} else {
									setInternalValue( option.value );
								}
								onClose();
							} }
						>
							<span className="bulk-purchase-modal__cell-dropdown-option">
								{ option.icon && (
									<span className="bulk-purchase-modal__cell-dropdown-icon">
										{ option.icon }
									</span>
								) }
								<span className="bulk-purchase-modal__cell-dropdown-text">
									{ option.label }
								</span>
							</span>
						</MenuItem>
					) ) }
				</MenuGroup>
			) }
		/>
	);
};

/** Wrap legacy string option lists as `{ label, value }`. */
export const toOptions = ( labels: string[] ): ApplyOption[] =>
	labels.map( ( labelText ) => ( {
		label: labelText,
		value: labelText,
	} ) );
