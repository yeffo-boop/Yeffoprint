import { Tooltip as WpTooltip } from '@wordpress/components';

export const Tooltip = ( { children, text } ) => {
	return (
		<WpTooltip
			placement="top-start"
			delay={ 0 }
			text={ text }
			style={ {
				maxWidth: '224px',
				fontSize: '13px',
				lineHeight: '20px',
				padding: '4px 8px',
				textAlign: 'left',
				borderRadius: '4px',
				backgroundColor: '#1e1e1e',
				boxShadow:
					'0 1px 2px 0 rgba(0, 0, 0, 0.05), 0 1px 2px 0 rgba(0, 0, 0, 0.05), 0 1px 2px 0 rgba(0, 0, 0, 0.05), 0 8px 8px 0 rgba(0, 0, 0, 0.02)',
			} }
		>
			{ children }
		</WpTooltip>
	);
};
