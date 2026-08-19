/**
 * @typedef {import('react')} React
 */

/**
 * Conditionaly render component A or B based on condition
 *
 * @param {Function|Object}     condition - function or object returning render and props as in {render: true, props: {foo: 'bar'}}
 * @param {React.ComponentType} A         - React component
 * @param {React.ComponentType} B         - React component
 * @return {React.ComponentType} React component
 */
export const Conditional = ( condition, A, B ) => ( componentProps ) => {
	/**
	 * If condition is a function, call it with componentProps
	 * Otherwise, use it as an object
	 * If function, it should return {render: true, props: {foo: 'bar'}},
	 * otherwise it should be {render: true, props: {foo: 'bar'}}
	 *
	 * @param {Object} condition - object or function
	 */
	const { render, props } =
		typeof condition === 'function'
			? condition( componentProps )
			: condition;
	if ( render ) {
		return <A { ...componentProps } { ...( props ?? {} ) } />;
	}
	return <B { ...componentProps } { ...( props ?? {} ) } />;
};
