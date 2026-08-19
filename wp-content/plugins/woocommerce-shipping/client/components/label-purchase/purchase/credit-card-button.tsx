import React from 'react';
import {
	Button,
	Flex,
	Notice,
	__experimentalText as Text,
	__experimentalSpacer as Spacer,
} from '@wordpress/components';
import { useLabelPurchaseContext } from 'context/label-purchase';
import {
	createPortal,
	useCallback,
	useRef,
	useState,
} from '@wordpress/element';

interface CreditCardButtonProps {
	url: string;
	disabled?: boolean;
	buttonLabel: string;
	buttonDescription: ( onChooseCard: () => void ) => React.JSX.Element;
	nextDesign?: boolean;
}

export const CreditCardButton = ( {
	url,
	disabled,
	buttonLabel,
	buttonDescription,
	nextDesign = false,
}: CreditCardButtonProps ) => {
	const {
		account: { refreshSettings },
	} = useLabelPurchaseContext();
	const creditCardWindow = useRef< Window | null >( null );
	const [ isRefreshing, updateIsRefreshing ] = useState( false );

	const onVisibilityChange = useCallback( async () => {
		if ( ! document.hidden ) {
			/**
			 * One a refresh request is sent, the button should stay in loading
			 * state and isRefreshing should never be set to false as the UI logic
			 * will remove the button completely when the refresh is done
			 */
			updateIsRefreshing( true );
			await refreshSettings();
		}

		if ( creditCardWindow?.current?.closed ) {
			document.removeEventListener(
				'visibilitychange',
				onVisibilityChange
			);
		}
	}, [ refreshSettings ] );

	const onChooseCard = useCallback( () => {
		creditCardWindow.current = window.open( url );
		document.addEventListener( 'visibilitychange', onVisibilityChange );
	}, [ url, onVisibilityChange ] );
	const portal = document.getElementById( 'label-purchase-status-notices' );
	return nextDesign && portal ? (
		createPortal(
			<Spacer>
				<Notice status="error">
					<Flex
						direction={ 'column' }
						align={ 'flex-start' }
						gap={ 4 }
					>
						<Text>{ buttonDescription( onChooseCard ) }</Text>
						<Button
							onClick={ onChooseCard }
							disabled={ isRefreshing || disabled }
							aria-disabled={ isRefreshing || disabled }
							variant="primary"
							isBusy={ isRefreshing }
							__next40pxDefaultSize
						>
							{ buttonLabel }
						</Button>
					</Flex>
				</Notice>
			</Spacer>,
			portal
		)
	) : (
		<>
			<Button
				onClick={ onChooseCard }
				disabled={ isRefreshing || disabled }
				aria-disabled={ isRefreshing || disabled }
				variant="primary"
				icon="external"
				isBusy={ isRefreshing }
				__next40pxDefaultSize={ nextDesign }
			>
				{ buttonLabel }
			</Button>
			<div className="purchase-section__explanation">
				{ buttonDescription( onChooseCard ) }
			</div>
		</>
	);
};
