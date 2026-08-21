import type { ComponentType, ReactElement, ReactNode } from 'react';

export type RecordValues< T extends Record< string, unknown > > = T[ keyof T ];

export type SnakeToCamelCase< S extends string | number | symbol > =
	S extends `${ infer T }_${ infer U }`
		? `${ T }${ Capitalize< SnakeToCamelCase< U > > }`
		: S;

export type CamelCaseType<
	InputType extends Record< string, unknown > | object,
> = {
	[ K in keyof InputType as SnakeToCamelCase< K > ]: InputType[ K ];
};

export type ShipmentRecord< T > = Record< `shipment_${ number | string }`, T >;

export type DeepPartial< T > = T extends object
	? {
			[ P in keyof T ]?: DeepPartial< T[ P ] >;
	  }
	: T;

export type SnakeCaseType< T > = {
	[ K in keyof T as K extends string ? SnakeToCamelCase< K > : K ]: T[ K ];
};

export const isCallableElement = (
	type: ReactElement | ComponentType | ReactNode
): type is ( props: unknown ) => ReactElement | null =>
	typeof type === 'function';
