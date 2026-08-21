import { register } from '@wordpress/data';
import { createStore } from './store';

let AnalyticsStore: ReturnType< typeof createStore >;
export const registerAnalyticsStore = () => {
	AnalyticsStore = AnalyticsStore || createStore();
	register( AnalyticsStore );
};

export { AnalyticsStore };
