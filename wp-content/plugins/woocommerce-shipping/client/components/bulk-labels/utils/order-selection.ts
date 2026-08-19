const ORDER_CHECKBOX_SELECTOR =
	'#the-list input[type="checkbox"][name="id[]"], #the-list input[type="checkbox"][name="post[]"]';

export const getOrderCheckboxes = (): HTMLInputElement[] =>
	Array.from(
		document.querySelectorAll< HTMLInputElement >( ORDER_CHECKBOX_SELECTOR )
	);

export const getSelectedOrderIds = (): string[] => {
	const checkboxes = getOrderCheckboxes();
	return Array.from( checkboxes )
		.filter( ( cb ) => cb.checked )
		.map( ( cb ) => cb.value );
};
