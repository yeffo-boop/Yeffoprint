import {
	__experimentalText as Text,
	Button,
	Flex,
} from '@wordpress/components';
import { chevronDown, chevronRight } from '@wordpress/icons';
import { useState } from '@wordpress/element';
import { sprintf } from '@wordpress/i18n';
import clsx from 'clsx';
import { getCurrencySymbol, getDimensionsUnit, getWeightUnit } from 'utils';
import { withBoundary } from 'components/HOC';

const Item = withBoundary(
	( {
		item,
		isExpandable,
		currencySymbol,
		weightUnit,
		dimensionUnit,
		renderPrefix,
		hasVariation,
		className = '',
	} ) => {
		const [ isExpanded, expand ] = useState( false );
		return (
			<>
				<Flex
					className={ clsx( className, {
						'is-expanded': isExpanded,
					} ) }
				>
					{ renderPrefix( item ) }
					{ isExpandable && (
						<Button
							icon={ isExpanded ? chevronDown : chevronRight }
							className={ clsx( 'expand-row', {
								'is-visible': item.quantity > 1,
								'has-expanded': isExpanded,
							} ) }
							onClick={ () => expand( ! isExpanded ) }
						/>
					) }
					<img src={ item.image } alt={ item.name } width="32" />

					<dl>
						<dt className="item-name">
							<Text truncate>{ item.name }</Text>
							<small>{ item.sku }</small>
						</dt>
						<dt className="item-quantity">
							<small>x</small>
							{ item.quantity }
						</dt>
						{ hasVariation && (
							<dt className="item-variation">
								{ item.variation
									.map( ( { value } ) => value )
									.join( ', ' ) ?? '-' }
							</dt>
						) }
						<dt className="item-dimensions">
							{ item.dimensions && (
								<>
									{ sprintf(
										'%1$s x %2$s x %3$s %4$s',
										item.dimensions.length,
										item.dimensions.width,
										item.dimensions.height,
										`(${ dimensionUnit })`
									) }
								</>
							) }
							{ ! item.dimensions && <>-</> }
						</dt>
						<dt className="item-weight">
							{ item.weight && (
								<>
									{ item.weight } { weightUnit }
								</>
							) }
							{ ! item.weight && <>-</> }
						</dt>
						<dt className="item-price">
							{ currencySymbol }
							{ item.price }
						</dt>
					</dl>
				</Flex>
				{ isExpanded && (
					<>
						{ item.subItems.map( ( subItem ) => {
							return (
								<Item
									key={ subItem.id }
									hasVariation={ hasVariation }
									className="sub-item"
									item={ subItem }
									renderPrefix={ renderPrefix }
									weightUnit={ weightUnit }
									currencySymbol={ currencySymbol }
									dimensionUnit={ dimensionUnit }
								/>
							);
						} ) }
					</>
				) }
			</>
		);
	}
)( 'Item' );

export const Items = withBoundary(
	( {
		orderItems,
		isExpandable = false,
		renderPrefix = () => null,
		header = null,
	} ) => {
		const weightUnit = getWeightUnit();
		const dimensionUnit = getDimensionsUnit();
		const currencySymbol = getCurrencySymbol();
		const hasVariation = orderItems.some(
			( item ) => item.variation?.length > 0
		);

		return (
			<>
				{ header }
				{ orderItems.map( ( item, index ) => (
					<Item
						hasVariation={ hasVariation }
						key={ `${ item.id }-${ index }` }
						item={ item }
						isExpandable={ isExpandable }
						renderPrefix={ renderPrefix }
						weightUnit={ weightUnit }
						currencySymbol={ currencySymbol }
						dimensionUnit={ dimensionUnit }
					/>
				) ) }
			</>
		);
	}
)( 'Items' );
