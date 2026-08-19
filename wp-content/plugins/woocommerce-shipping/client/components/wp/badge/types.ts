export interface BadgeProps {
	/**
	 * Badge variant.
	 *
	 * @default 'default'
	 */
	intent?:
		| 'default'
		| 'info'
		| 'success'
		| 'warning'
		| 'warning-alt'
		| 'error';
	/**
	 * Text to display inside the badge.
	 */
	children: React.ReactNode;
	/**
	 * Whether to hide the icon or not.
	 */
	hideIcon?: boolean;
	/**
	 * Whether to prevent text wrapping and show ellipsis.
	 */
	noEllipsis?: boolean;
}
