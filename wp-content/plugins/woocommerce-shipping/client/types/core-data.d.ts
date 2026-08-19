export interface CoreDataInvalidateDispatch {
	invalidateResolution: (
		selectorName: string,
		args: unknown[]
	) => Promise< unknown > | void;
}
