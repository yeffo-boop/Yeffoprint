import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

interface UsePaymentMethodPollingParams {
	isResolved: boolean;
	onPoll: () => void;
	intervalMs?: number;
}

export const usePaymentMethodPolling = ( {
	isResolved,
	onPoll,
	intervalMs = 3000,
}: UsePaymentMethodPollingParams ) => {
	const intervalRef = useRef< ReturnType< typeof setInterval > | null >(
		null
	);
	const [ isPolling, setIsPolling ] = useState( false );

	const clearPollingInterval = useCallback( () => {
		if ( intervalRef.current ) {
			clearInterval( intervalRef.current );
			intervalRef.current = null;
		}
	}, [] );

	const stopPolling = useCallback( () => {
		clearPollingInterval();
		setIsPolling( false );
	}, [ clearPollingInterval ] );

	const startPolling = useCallback( () => {
		if ( isResolved || intervalRef.current ) {
			return;
		}

		setIsPolling( true );
		onPoll();
		intervalRef.current = setInterval( onPoll, intervalMs );
	}, [ intervalMs, isResolved, onPoll ] );

	useEffect( () => {
		if ( isResolved ) {
			stopPolling();
		}
	}, [ isResolved, stopPolling ] );

	useEffect( () => {
		return () => {
			clearPollingInterval();
		};
	}, [ clearPollingInterval ] );

	return {
		isPolling,
		startPolling,
		stopPolling,
	};
};
