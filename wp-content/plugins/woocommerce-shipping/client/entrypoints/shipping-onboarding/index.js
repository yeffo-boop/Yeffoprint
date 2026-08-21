import { createRoot } from 'react-dom/client';
import { registerWPCOMConnectionStore } from 'wcshipping/data/wpcom-connection';
import { getConfig, initSentry } from 'utils';
import Onboarding from 'components/onboarding';

const domNode = document.getElementById( 'woocommerce-shipping-onboarding' );
const root = createRoot( domNode );

const {
	authReturnUrl,
	isCurrencySupported,
	isCountrySupported,
	storeCountryName,
	storeCurrency,
	onboardingState,
} = getConfig();

registerWPCOMConnectionStore();
initSentry();

root.render(
	<Onboarding
		authReturnUrl={ authReturnUrl }
		isCountrySupported={ isCountrySupported }
		isCurrencySupported={ isCurrencySupported }
		storeCountryName={ storeCountryName }
		storeCurrency={ storeCurrency }
		onboardingState={ onboardingState }
	/>
);
