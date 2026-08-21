import React from 'react';
import {
	__experimentalText as Text,
	Button,
	ExternalLink,
	Icon,
	Notice,
} from '@wordpress/components';
import {
	createInterpolateElement,
	useCallback,
	useEffect,
	useState,
} from '@wordpress/element';
import { dispatch, select } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { WPCOMConnectionStore } from 'data/wpcom-connection';
import { recordEvent } from 'utils/tracks';

import './style.scss';
import { StoreSettingsRequirements } from '../store-settings-requirements';

interface ContainerProps {
	authReturnUrl: string;
	countryName: string;
	currency: string;
	isCountrySupported: boolean;
	isCurrencySupported: boolean;
	isTosOnly?: boolean;
}

const Connect: React.FC< ContainerProps > = ( {
	authReturnUrl,
	countryName,
	currency,
	isCountrySupported,
	isCurrencySupported,
	isTosOnly = false,
} ) => {
	const [ isConnecting, setIsConnecting ] = useState( false );
	const canConnect = ! ( isCountrySupported && isCurrencySupported );
	const { redirectUrl, error } = select( WPCOMConnectionStore ).getState();

	useEffect( () => {
		if ( redirectUrl ) {
			window.location.href = redirectUrl;
		}
	}, [ redirectUrl ] );

	useEffect( () => {
		recordEvent( 'onboarding_connect_component_viewed', {
			can_connect: canConnect,
			is_country_supported: isCountrySupported,
			is_currency_supported: isCurrencySupported,
			is_tos_only: isTosOnly,
		} );
	}, [ canConnect, isCountrySupported, isCurrencySupported, isTosOnly ] );

	useEffect( () => {
		if ( error ) {
			recordEvent( 'onboarding_connect_component_connect_error_viewed', {
				error,
			} );
		}
	}, [ error ] );

	const handleOnClick = useCallback( async () => {
		setIsConnecting( true );

		recordEvent( 'onboarding_connect_component_connect_button_clicked', {
			is_tos_only: isTosOnly,
		} );

		await dispatch( WPCOMConnectionStore ).createConnection( {
			payload: {
				returnUrl: authReturnUrl,
				source: isTosOnly
					? 'onboarding-tos-only-button'
					: 'onboarding-connect-button',
			},
		} );

		setIsConnecting( false );
	}, [ authReturnUrl, isTosOnly ] );

	const buttonLabel = isTosOnly
		? __( 'Enable WooCommerce Shipping', 'woocommerce-shipping' )
		: __( 'Connect your store', 'woocommerce-shipping' );

	return (
		<div
			className={ `wcshipping-onboarding-connect${
				isTosOnly ? ' wcshipping-onboarding-connect--tos-only' : ''
			}` }
		>
			{ ( ! isCountrySupported || ! isCurrencySupported ) && (
				<StoreSettingsRequirements
					isCountrySupported={ isCountrySupported }
					isCurrencySupported={ isCurrencySupported }
					countryName={ countryName }
					currency={ currency }
				/>
			) }

			<Button
				className="wcshipping-onboarding-connect__button"
				variant="primary"
				disabled={ ( ! isTosOnly && canConnect ) || isConnecting }
				onClick={ handleOnClick }
				isBusy={ isConnecting || !! redirectUrl }
			>
				{ buttonLabel }
				{ ! isTosOnly && <Icon icon="external" /> }
			</Button>

			{ error && (
				<Notice
					status="error"
					isDismissible={ false }
					className="wcshipping-onboarding-connect__error"
				>
					{ error }
				</Notice>
			) }

			<Text
				className="wcshipping-onboarding-connect__footnote"
				size="footnote"
			>
				{ isTosOnly
					? createInterpolateElement(
							__(
								'By clicking Enable WooCommerce Shipping, you agree to the <tos>Terms of Service<icon /></tos> and have read our <privacy_policy>Privacy Policy<icon /></privacy_policy>.',
								'woocommerce-shipping'
							),
							{
								tos: (
									<ExternalLink href="https://wordpress.com/tos/">
										{ ' ' }
									</ExternalLink>
								),
								privacy_policy: (
									<ExternalLink href="https://automattic.com/privacy/">
										{ ' ' }
									</ExternalLink>
								),
								icon: <Icon icon="external" />,
							}
					  )
					: createInterpolateElement(
							__(
								'By clicking Connect your store, you agree to the <tos>Terms of Service<icon /></tos> and have read our <privacy_policy>Privacy Policy<icon /></privacy_policy>.',
								'woocommerce-shipping'
							),
							{
								tos: (
									<ExternalLink href="https://wordpress.com/tos/">
										{ ' ' }
									</ExternalLink>
								),
								privacy_policy: (
									<ExternalLink href="https://automattic.com/privacy/">
										{ ' ' }
									</ExternalLink>
								),
								icon: <Icon icon="external" />,
							}
					  ) }
			</Text>
		</div>
	);
};

export default Connect;
