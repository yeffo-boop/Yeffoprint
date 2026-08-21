/**
 * WordPress dependencies
 */
import { useDispatch, useSelect } from '@wordpress/data';
import { useState } from '@wordpress/element';
import { store as coreStore, useEntityRecord } from '@wordpress/core-data';

/**
 * External dependencies
 */
import { isEqual } from 'lodash';

/**
 * Internal dependencies
 */
import type { Entity } from './types';

interface UseEditEntityRecordReturn< T > {
	record: T | null;
	editedRecord: T | Partial< T >;
	isResolving: boolean;
	hasEdits: boolean;
	edit: ( data: T | Partial< T > ) => void;
	save: () => Promise< void >;
	canUserSave: boolean | undefined;
}

export function useAddEntityRecord< T >( entity: Entity, initialValue: T ) {
	const { saveEntityRecord } = useDispatch( coreStore );
	const [ record, setRecord ] = useState< T >( initialValue );
	const hasEdits = ! isEqual( record, initialValue );
	const edit = ( data: T ) => setRecord( { ...record, ...data } );
	const save = () => saveEntityRecord( entity.kind, entity.name, record );

	return {
		record,
		editedRecord: record,
		isResolving: false,
		hasEdits,
		edit,
		save,
	};
}

export function useAddEditEntityRecord< T = unknown >(
	entity: Entity,
	initialValue: T,
	id?: number | string
): UseEditEntityRecordReturn< T > {
	const isNew = id === undefined;
	const entityRecord = useEntityRecord< T >( entity.kind, entity.name, id!, {
		enabled: ! isNew,
	} );

	const newEntity = useAddEntityRecord< T >( entity, initialValue );
	const canUserSave = useSelect(
		( _select ) =>
			_select( coreStore ).canUser( isNew ? 'create' : 'update', {
				kind: entity.kind,
				name: entity.name,
				id: isNew ? undefined : id,
			} ),
		[ entity, id, isNew ]
	);

	return {
		canUserSave,
		...( isNew ? newEntity : entityRecord ),
	} as UseEditEntityRecordReturn< T >;
}
