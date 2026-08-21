import { useCallback, useEffect, useRef } from '@wordpress/element';
import { isEqual, debounce, type DebounceSettings } from 'lodash';
import { usePrevious } from '@wordpress/compose';

interface ThrottledStateChangeOptions< T > extends DebounceSettings {
	/**
	 * Time in milliseconds to throttle the callback
	 */
	delay?: number;
	/**
	 * Function to determine if the state has changed, defaults to isEqual
	 * If returning true, the callback will be executed
	 */
	hasChanged?: ( prev: T, current: T ) => boolean;
}

/**
 * A hook that executes a callback when a state changes, with throttling.
 * This hook monitors changes to a state value and executes a throttled callback when changes occur.
 * The throttling helps prevent excessive callback executions when the state changes rapidly.
 * It supports comparing nested state values through a statePath parameter and allows customization
 * of the throttle behavior through options like throttle time and trailing execution.
 */
export const useThrottledStateChange = < T >(
	state: T,
	callback: () => void,
	options: ThrottledStateChangeOptions< T > = {}
) => {
	const prevState = usePrevious( state );
	const {
		delay = 500,
		trailing = true,
		leading = false,
		hasChanged = ( prev: T, current: T ) => ! isEqual( prev, current ),
		...optionsRest
	} = options;

	// Create a ref to store the throttled callback
	const throttledCallbackRef = useRef<
		ReturnType< typeof debounce > | undefined
	>();

	// Store the callback in a ref to maintain reference stability
	const callbackRef = useRef( callback );

	// Update the callback ref when callback changes
	useEffect( () => {
		callbackRef.current = callback;
	}, [ callback ] );

	// Create a memoized throttled callback
	const getThrottledCallback = useCallback( () => {
		// If the throttled callback doesn't exist or needs to be recreated
		throttledCallbackRef.current ??= debounce(
			() => callbackRef.current(),
			delay,
			{
				trailing,
				leading,
				...optionsRest,
			}
		);
		return throttledCallbackRef.current;
	}, [ delay, trailing, leading, optionsRest ] );

	// Cleanup the throttled callback on unmount, should only be registered once
	useEffect( () => {
		return () => {
			throttledCallbackRef.current?.cancel();
		};
	}, [] );

	// Check if state has changed and invoke the throttled callback
	useEffect( () => {
		// Only check for changes if we have a previous state
		if ( prevState !== undefined && hasChanged( prevState, state ) ) {
			getThrottledCallback()();
		}
	}, [ state, prevState, hasChanged, getThrottledCallback ] );
};
