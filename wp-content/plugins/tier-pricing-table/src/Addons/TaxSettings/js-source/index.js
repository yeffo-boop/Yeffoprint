import { render } from '@wordpress/element';
import App from './App';

document.addEventListener('DOMContentLoaded', () => {
	const container = document.getElementById('tiered-pricing__feature__tax');

	if (container) {
		render(<App />, container);
	}
});
