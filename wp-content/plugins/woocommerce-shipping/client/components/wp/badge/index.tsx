/**
 * Forked from `@wordpress/components` private component.
 * To be removed when the component becomes public.
 */

import { Icon } from '@wordpress/components';
import { info, caution, error, published } from '@wordpress/icons';
import clsx from 'clsx';
import type { BadgeProps } from './types';
import './styles.scss';

/**
 * Returns an icon based on the badge context.
 *
 * @return The corresponding icon for the provided context.
 */
function contextBasedIcon( intent: BadgeProps[ 'intent' ] = 'default' ) {
	switch ( intent ) {
		case 'info':
			return info;
		case 'success':
			return published;
		case 'warning':
			return caution;
		case 'warning-alt':
			return caution;
		case 'error':
			return error;
		default:
			return null;
	}
}

export function Badge( {
	className,
	intent = 'default',
	children,
	hideIcon = false,
	noEllipsis = false,
	...props
}: BadgeProps & React.ComponentPropsWithoutRef< 'span' > ) {
	const icon = contextBasedIcon( intent );
	const hasIcon = hideIcon ? false : !! icon;

	return (
		<span
			className={ clsx( 'components-badge', className, {
				[ `is-${ intent }` ]: intent,
				'has-icon': hasIcon,
				'woocommerce-status-badge': intent === 'warning-alt',
				'is-actionable': intent === 'warning-alt',
			} ) }
			{ ...props }
			style={ {
				userSelect: 'none',
				flexShrink: noEllipsis ? 0 : 1,
				lineHeight: 'inherit',
			} }
		>
			{ hasIcon && (
				<Icon
					icon={ icon }
					size={ 16 }
					fill="currentColor"
					className="components-badge__icon"
				/>
			) }
			<span className="components-badge__content">{ children }</span>
		</span>
	);
}
