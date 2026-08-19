import { register } from '@wordpress/data';
import { createStore } from './store';

let WPCOMConnectionStore: ReturnType< typeof createStore >;
export const registerWPCOMConnectionStore = () => {
	WPCOMConnectionStore = WPCOMConnectionStore || createStore();
	register( WPCOMConnectionStore );
};

export { WPCOMConnectionStore };
