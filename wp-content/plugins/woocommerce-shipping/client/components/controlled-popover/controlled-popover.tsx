import { JSX, ReactNode } from 'react';
import { useCallback, useRef, useState } from '@wordpress/element';
import { Button, Icon, type IconType, Popover } from '@wordpress/components';
import clsx from 'clsx';
import type { PopoverProps } from '@wordpress/components/build-types/popover/types';

interface ControlledPopoverProps {
	children: ReactNode;
	icon?: IconType;
	buttonText?: string;
	trigger?: 'click' | 'hover' | 'focus';
	withArrow?: boolean;
	popoverOptions?: Partial< PopoverProps & { className?: string } >;
}

export const ControlledPopover = ( {
	children,
	icon,
	buttonText,
	trigger = 'click',
	withArrow = true,
	popoverOptions,
}: ControlledPopoverProps ): JSX.Element => {
	const { className: popoverClassName = '', ...restPopoverOptions } =
		popoverOptions ?? {};
	const [ show, setShow ] = useState( false );
	const toggle = useCallback(
		() => setShow( ( prev ) => ! prev ),
		[ setShow ]
	);
	const btnRef = useRef( null );
	let triggerProps: Partial<
		Record<
			'onClick' | 'onMouseOver' | 'onMouseOut' | 'onFocus' | 'onBlur',
			typeof toggle
		>
	> = {
		onClick: toggle,
	};

	if ( trigger === 'hover' ) {
		triggerProps = {
			onMouseOver: toggle,
			onMouseOut: toggle,
		};
	}

	if ( trigger === 'focus' ) {
		triggerProps = {
			onFocus: toggle,
			onBlur: toggle,
		};
	}

	return (
		<>
			{ icon && (
				<Icon
					icon={ icon }
					ref={ ! buttonText ? btnRef?.current : undefined }
					aria-haspopup={ true }
					aria-expanded={ show }
					style={
						trigger === 'click' ? { cursor: 'pointer' } : undefined
					}
					{ ...triggerProps }
				/>
			) }
			{ buttonText && (
				<Button
					{ ...triggerProps }
					ref={ ! icon ? btnRef?.current : undefined }
					aria-haspopup={ true }
					aria-expanded={ show }
					icon={ icon }
				>
					{ buttonText }
				</Button>
			) }
			{ show && (
				<Popover
					{ ...restPopoverOptions }
					className={ clsx(
						'label-purchase-form-tooltip',
						popoverClassName
					) }
					onFocusOutside={ toggle }
					noArrow={ withArrow ? false : true }
					inline={ true }
					shift={ true }
					resize={ true }
					children={ children }
					anchor={ btnRef?.current }
				></Popover>
			) }
		</>
	);
};
