/**
 * Bulk discount tier repeater for the Pricing Rule admin screen —
 * same pattern as assets/admin/field-schema.js (plain in-memory array,
 * re-render + re-sync a hidden JSON input on every mutation, classic
 * post-form save, no AJAX). Simpler than the field-schema repeater
 * since tiers don't need manual reordering — YeffoPrint_Pricing_Rule
 * sorts them by threshold when calculating, so display order doesn't
 * matter.
 */
( function () {
	'use strict';

	if ( typeof yeffoprintPricingTiers === 'undefined' ) {
		return;
	}

	var config = yeffoprintPricingTiers;
	var state = Array.isArray( config.tiers ) ? JSON.parse( JSON.stringify( config.tiers ) ) : [];

	document.addEventListener( 'DOMContentLoaded', function () {
		var app = document.getElementById( 'yp-pricing-tiers-app' );
		if ( ! app ) {
			return;
		}

		var list = app.querySelector( '.yp-pricing-tiers-list' );
		var input = document.getElementById( 'yp-pricing-tiers-input' );
		var addButton = document.getElementById( 'yp-pricing-tiers-add' );

		function escapeHtml( value ) {
			var div = document.createElement( 'div' );
			div.textContent = value == null ? '' : String( value );
			return div.innerHTML;
		}

		function optionsHtml( options, selected ) {
			return Object.keys( options ).map( function ( value ) {
				return '<option value="' + escapeHtml( value ) + '"' + ( value === selected ? ' selected' : '' ) + '>' + escapeHtml( options[ value ] ) + '</option>';
			} ).join( '' );
		}

		function rowHtml( tier, index ) {
			return (
				'<div class="yp-pricing-tier-row" data-index="' + index + '">' +
					'<label>' + 'At quantity' +
						'<input type="number" min="1" data-index="' + index + '" data-key="threshold" value="' + escapeHtml( tier.threshold ) + '" />' +
					'</label>' +
					'<label>' + 'apply' +
						'<select data-index="' + index + '" data-key="type">' + optionsHtml( config.types, tier.type ) + '</select>' +
					'</label>' +
					'<label>' +
						'<input type="number" step="0.01" min="0" data-index="' + index + '" data-key="value" value="' + escapeHtml( tier.value ) + '" />' +
					'</label>' +
					'<button type="button" class="button-link-delete" data-action="remove" data-index="' + index + '">' + escapeHtml( config.i18n.removeTier ) + '</button>' +
				'</div>'
			);
		}

		function render() {
			list.innerHTML = state.length
				? state.map( rowHtml ).join( '' )
				: '<p class="yp-field-schema-empty">' + escapeHtml( config.i18n.empty ) + '</p>';

			sync();
		}

		function sync() {
			input.value = JSON.stringify( state );
		}

		list.addEventListener( 'input', function ( event ) {
			var target = event.target;
			var index = target.getAttribute( 'data-index' );
			var key = target.getAttribute( 'data-key' );
			if ( index === null || ! key ) {
				return;
			}

			var value = 'number' === target.type ? parseFloat( target.value ) || 0 : target.value;
			state[ parseInt( index, 10 ) ][ key ] = value;
			sync();
		} );

		list.addEventListener( 'change', function ( event ) {
			var target = event.target;
			if ( 'SELECT' !== target.tagName ) {
				return;
			}
			var index = target.getAttribute( 'data-index' );
			state[ parseInt( index, 10 ) ].type = target.value;
			sync();
		} );

		list.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( 'button[data-action="remove"]' );
			if ( ! button ) {
				return;
			}
			state.splice( parseInt( button.getAttribute( 'data-index' ), 10 ), 1 );
			render();
		} );

		addButton.addEventListener( 'click', function () {
			state.push( { threshold: 1001, type: 'percent', value: 5 } );
			render();
		} );

		render();
	} );
} )();
