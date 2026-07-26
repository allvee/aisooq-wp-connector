/**
 * Live inline Layer-1 validation for the classic WooCommerce checkout. As the
 * shopper fills the name / phone / address fields, the entered values are sent
 * (debounced, on blur/change) to the plugin's AJAX forwarder, which asks the
 * platform's NON-recording Layer-1 preview whether each field looks real. A
 * junk name / gibberish address / malformed number is flagged inline under the
 * field, before the shopper hits Place order.
 *
 * Purely advisory + fail-open: the authoritative block still happens
 * server-side at order placement (woocommerce_after_checkout_validation), so a
 * network hiccup here never affects whether the order can be placed.
 *
 * @package ShopifyPulse
 */
( function ( $ ) {
	'use strict';
	if ( ! window.SPValidate || ! window.SPValidate.ajaxUrl ) {
		return;
	}
	var V = window.SPValidate;
	var timer = null;

	// Each platform field → the WooCommerce form-row wrapper to render its error
	// under. Name errors sit under the last-name row (end of the name pair).
	var TARGET = {
		name: '#billing_last_name_field',
		phone: '#billing_phone_field',
		address: '#billing_address_1_field'
	};

	function currentValues() {
		var first = ( $( '#billing_first_name' ).val() || '' ).trim();
		var last = ( $( '#billing_last_name' ).val() || '' ).trim();
		var addr = ( $( '#shipping_address_1' ).val() || '' ).trim() || ( $( '#billing_address_1' ).val() || '' ).trim();
		return {
			name: ( first + ' ' + last ).trim(),
			phone: ( $( '#billing_phone' ).val() || '' ).trim(),
			address: addr
		};
	}

	function clearError( field ) {
		$( TARGET[ field ] ).find( '.sp-inline-err' ).remove();
	}

	function showError( field, message ) {
		var $wrap = $( TARGET[ field ] );
		if ( ! $wrap.length ) {
			return;
		}
		var $err = $wrap.find( '.sp-inline-err' );
		if ( ! $err.length ) {
			$err = $( '<span class="sp-inline-err" role="alert" style="display:block;color:#b32d2e;font-size:13px;line-height:1.4;margin-top:4px;"></span>' );
			$wrap.append( $err );
		}
		$err.text( message );
	}

	function run() {
		var vals = currentValues();
		if ( ! vals.name && ! vals.phone && ! vals.address ) {
			return;
		}
		$.post( V.ajaxUrl, {
			action: 'shopify_pulse_validate',
			nonce: V.nonce,
			name: vals.name,
			phone: vals.phone,
			address: vals.address
		} ).done( function ( res ) {
			if ( ! res || ! res.success || ! res.data || ! res.data.enabled ) {
				return;
			}
			var byField = {};
			( res.data.fields || [] ).forEach( function ( f ) { byField[ f.field ] = f; } );
			[ 'name', 'phone', 'address' ].forEach( function ( field ) {
				var f = byField[ field ];
				if ( f && f.valid === false && f.message ) {
					showError( field, f.message );
				} else {
					clearError( field );
				}
			} );
		} );
	}

	function schedule() {
		if ( timer ) {
			clearTimeout( timer );
		}
		timer = setTimeout( run, 450 );
	}

	$( document.body ).on(
		'blur change',
		'#billing_first_name, #billing_last_name, #billing_phone, #billing_address_1, #shipping_address_1',
		schedule
	);
} )( jQuery );
