export interface Package {
	// @Todo: Add types for all properties

	id: string;
	type: string;
	isLetter: boolean;
	length: string;
	width: string;
	height: string;
	outerDimensions: string;
	innerDimensions: string;
	dimensions: string;
	name: string;
	boxWeight: number;
	isUserDefined: false | undefined;
	carrierId?: string;
}
