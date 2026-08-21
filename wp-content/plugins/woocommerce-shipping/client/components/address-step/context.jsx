import { createContext, useContext } from '@wordpress/element';

export const AddressContext = createContext();

export const useAddressContext = () => {
	return useContext( AddressContext );
};

export const AddressContextProvider = ( { children, initialValue } ) => (
	<AddressContext.Provider value={ initialValue }>
		{ children }
	</AddressContext.Provider>
);
