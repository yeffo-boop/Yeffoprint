export interface SelectItem {
	label: string;
	value: string;
	disabled?: boolean;
}

export interface DesignSystemSelectControlProps {
	label?: string;
	hideLabelFromVision?: boolean;
	description?: string;
	items: SelectItem[];
	value?: string;
	defaultValue?: string;
	onValueChange?: ( value: string ) => void;
	disabled?: boolean;
	required?: boolean;
	className?: string;
}
