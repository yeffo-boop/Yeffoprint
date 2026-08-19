export interface SimpleAction< AT = string > {
	readonly type: AT;
}

export interface Action< AT = string, P extends string | number | object = any >
	extends SimpleAction< AT > {
	readonly payload: P;
}
