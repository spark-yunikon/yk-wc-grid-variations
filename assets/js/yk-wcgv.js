/**
 * YK WC Grid Variations — Interactive product cards.
 *
 * Depends on:
 *   window.ykWcgv  — injected via wp_add_inline_script (before this file)
 *   jQuery         — for WooCommerce fragment / added_to_cart events
 *
 * Class hooks targeted (all set in content-product.php):
 *   .yk-card, .yk-swatch, .yk-size, .yk-qty-wrap,
 *   .yk-qty-minus, .yk-qty-plus, .yk-qty-input,
 *   .yk-add-to-cart, .yk-price, .yk-badge-sale
 */
( function ( $ ) {
	'use strict';

	if ( typeof ykWcgv === 'undefined' ) {
		return;
	}

	const { ajax_url, lang, products, i18n } = ykWcgv;

	// ── Per-card state ───────────────────────────────────────────────────────

	const cardState    = new WeakMap();
	const originalHTML = new WeakMap();
	const errorTimers  = new WeakMap();

	function getState( card ) {
		if ( ! cardState.has( card ) ) {
			cardState.set( card, { colorValue: null, sizeValue: null, variationId: null } );
		}
		return cardState.get( card );
	}

	// ── Variation resolution ─────────────────────────────────────────────────

	/**
	 * Return the first variation that matches colorValue + sizeValue.
	 * An empty-string attribute value means "any" in WooCommerce.
	 */
	function resolveVariation( variations, colorAttr, sizeAttr, colorValue, sizeValue ) {
		for ( const v of variations ) {
			const vc = colorAttr ? v.attributes[ colorAttr ] : '';
			const vs = sizeAttr  ? v.attributes[ sizeAttr  ] : '';

			const colorMatch = ! colorAttr  || vc === '' || vc === colorValue || ! colorValue;
			const sizeMatch  = ! sizeAttr   || vs === '' || vs === sizeValue  || ! sizeValue;

			if ( colorMatch && sizeMatch ) {
				return v;
			}
		}
		return null;
	}

	/**
	 * Return true if at least one in-stock variation matches colorValue + sizeValue.
	 */
	function isCombinationAvailable( variations, colorAttr, sizeAttr, colorValue, sizeValue ) {
		return variations.some( v => {
			const vc = colorAttr ? v.attributes[ colorAttr ] : '';
			const vs = sizeAttr  ? v.attributes[ sizeAttr  ] : '';

			const colorMatch = ! colorAttr  || vc === '' || vc === colorValue || ! colorValue;
			const sizeMatch  = ! sizeAttr   || vs === '' || vs === sizeValue  || ! sizeValue;

			return colorMatch && sizeMatch && v.is_in_stock;
		} );
	}

	// ── Card initialisation ──────────────────────────────────────────────────

	function initCards() {
		document.querySelectorAll( '.yk-card' ).forEach( initCard );
	}

	function initCard( card ) {
		// Bundle cards have no .yk-add-to-cart button — skip entirely.
		const btn = card.querySelector( '.yk-add-to-cart' );
		if ( ! btn ) {
			return;
		}

		const productId = btn.dataset.productId;
		const data      = products[ productId ];

		if ( ! data || data.type === 'bundle' ) {
			return;
		}

		// ── Swatches ─────────────────────────────────────────────────────────
		card.querySelectorAll( '.yk-swatch' ).forEach( swatch => {
			swatch.addEventListener( 'click', () => onSwatchClick( swatch, card, data ) );
		} );

		// ── Size pills ────────────────────────────────────────────────────────
		card.querySelectorAll( '.yk-size' ).forEach( size => {
			size.addEventListener( 'click', () => onSizeClick( size, card, data ) );
		} );

		// ── Quantity stepper ──────────────────────────────────────────────────
		const minus = card.querySelector( '.yk-qty-minus' );
		const plus  = card.querySelector( '.yk-qty-plus' );
		const input = card.querySelector( '.yk-qty-input' );

		if ( minus && plus && input ) {
			input.removeAttribute( 'readonly' );

			if ( data.type !== 'variable' && data.max_qty ) {
				input.max = data.max_qty;
			}

			minus.addEventListener( 'click', () => stepQty( input, -1 ) );
			plus.addEventListener(  'click', () => stepQty( input, +1 ) );
			input.addEventListener( 'change', () => clampQty( input ) );
			input.addEventListener( 'input',  () => clampQty( input ) );
		}

		// ── Add to cart ───────────────────────────────────────────────────────
		btn.addEventListener( 'click', () => onAddToCart( btn, card, productId, data ) );

		// ── Pre-select first colour + size ────────────────────────────────────
		initPreselect( card, data );
	}

	// ── Pre-selection on init ────────────────────────────────────────────────

	function initPreselect( card, data ) {
		const state = getState( card );

		// Read whichever swatch PHP marked active (the first one).
		const firstSwatch = card.querySelector( '.yk-swatch.is-active' );
		if ( firstSwatch ) {
			state.colorValue = firstSwatch.dataset.value;
			if ( data.type === 'variable' ) {
				filterSizes( card, data, state.colorValue );
			}
		}

		// After size filtering, pick the first available size.
		const allSizes = card.querySelectorAll( '.yk-size' );
		if ( allSizes.length ) {
			allSizes.forEach( s => s.classList.remove( 'is-active' ) );
			const firstAvailable = card.querySelector( '.yk-size:not(.is-disabled):not([disabled])' );
			if ( firstAvailable ) {
				firstAvailable.classList.add( 'is-active' );
				state.sizeValue = firstAvailable.dataset.value;
			}
		}

		// Resolve variation, update image and qty cap.
		if ( data.type === 'variable' ) {
			const v = resolveVariation(
				data.variations,
				data.color_attr,
				data.size_attr,
				state.colorValue,
				state.sizeValue
			);
			state.variationId = v ? v.variation_id : null;
			if ( v ) {
				updateQtyMax( card, v.max_qty );
			}
			updateImage( card, data, state, v );
		}
	}

	// ── Swatch click ─────────────────────────────────────────────────────────

	function onSwatchClick( swatch, card, data ) {
		const state = getState( card );
		const value = swatch.dataset.value;

		// Toggle off if already active.
		if ( state.colorValue === value ) {
			state.colorValue  = null;
			state.variationId = null;
			swatch.classList.remove( 'is-active' );
		} else {
			state.colorValue  = value;
			state.variationId = null;
			card.querySelectorAll( '.yk-swatch' ).forEach( s => s.classList.remove( 'is-active' ) );
			swatch.classList.add( 'is-active' );
		}

		// Deselect active size when color changes — the combination may no longer be valid.
		card.querySelectorAll( '.yk-size' ).forEach( s => s.classList.remove( 'is-active' ) );
		state.sizeValue = null;

		if ( data.type === 'variable' ) {
			filterSizes( card, data, state.colorValue );
			updateImage( card, data, state );
		}
	}

	// ── Size click ───────────────────────────────────────────────────────────

	function onSizeClick( size, card, data ) {
		if ( size.classList.contains( 'is-disabled' ) || size.disabled ) {
			return;
		}

		const state = getState( card );
		const value = size.dataset.value;

		// Toggle off if already active.
		if ( state.sizeValue === value ) {
			state.sizeValue   = null;
			state.variationId = null;
			size.classList.remove( 'is-active' );
		} else {
			state.sizeValue = value;
			card.querySelectorAll( '.yk-size' ).forEach( s => s.classList.remove( 'is-active' ) );
			size.classList.add( 'is-active' );

			// Resolve variation and update qty max.
			if ( data.type === 'variable' ) {
				const v = resolveVariation(
					data.variations,
					data.color_attr,
					data.size_attr,
					state.colorValue,
					state.sizeValue
				);
				state.variationId = v ? v.variation_id : null;
				if ( v ) {
					updateQtyMax( card, v.max_qty );
				}
				updateImage( card, data, state, v );
			}
		}
	}

	// ── Size filtering ────────────────────────────────────────────────────────

	function filterSizes( card, data, colorValue ) {
		card.querySelectorAll( '.yk-size' ).forEach( sizeEl => {
			const sizeValue = sizeEl.dataset.value;
			const available = isCombinationAvailable(
				data.variations,
				data.color_attr,
				data.size_attr,
				colorValue,
				sizeValue
			);

			if ( available ) {
				sizeEl.classList.remove( 'is-disabled' );
				sizeEl.removeAttribute( 'disabled' );
			} else {
				sizeEl.classList.add( 'is-disabled' );
				sizeEl.setAttribute( 'disabled', '' );
				if ( sizeEl.classList.contains( 'is-active' ) ) {
					sizeEl.classList.remove( 'is-active' );
					const state = getState( card );
					if ( state.sizeValue === sizeValue ) {
						state.sizeValue   = null;
						state.variationId = null;
					}
				}
			}
		} );
	}

	// ── Image update ──────────────────────────────────────────────────────────

	// Accepts a pre-resolved variation to avoid calling resolveVariation twice when
	// the caller already has it (initPreselect, onSizeClick). Pass nothing to resolve here.
	function updateImage( card, data, state, v ) {
		if ( data.type !== 'variable' ) {
			return;
		}

		const imgEl = card.querySelector( '.yk-card__img' );
		if ( ! imgEl ) {
			return;
		}

		const variation = ( v !== undefined ) ? v : resolveVariation(
			data.variations,
			data.color_attr,
			data.size_attr,
			state.colorValue,
			state.sizeValue
		);

		if ( variation && variation.image_url ) {
			imgEl.src = variation.image_url;
			if ( variation.image_srcset ) {
				imgEl.srcset = variation.image_srcset;
			}
			if ( variation.image_sizes ) {
				imgEl.sizes = variation.image_sizes;
			}
		}
	}

	// ── Quantity stepper ──────────────────────────────────────────────────────

	function stepQty( input, delta ) {
		const current = parseInt( input.value, 10 ) || 1;
		const min     = parseInt( input.min, 10 )   || 1;
		const max     = parseInt( input.max, 10 )   || 9999;
		input.value   = Math.min( max, Math.max( min, current + delta ) );
	}

	function clampQty( input ) {
		const val = parseInt( input.value, 10 );
		const min = parseInt( input.min, 10 )   || 1;
		const max = parseInt( input.max, 10 )   || 9999;
		if ( isNaN( val ) || val < min ) {
			input.value = min;
		} else if ( val > max ) {
			input.value = max;
		}
	}

	function updateQtyMax( card, maxQty ) {
		const input = card.querySelector( '.yk-qty-input' );
		if ( ! input ) {
			return;
		}
		const max = ( maxQty && maxQty > 0 ) ? maxQty : 9999;
		input.max = max;
		if ( parseInt( input.value, 10 ) > max ) {
			input.value = max;
		}
	}

	// ── Add to cart ───────────────────────────────────────────────────────────

	function onAddToCart( btn, card, productId, data ) {
		const state  = getState( card );
		const qtyEl  = card.querySelector( '.yk-qty-input' );
		const qty    = qtyEl ? ( parseInt( qtyEl.value, 10 ) || 1 ) : 1;

		if ( data.type === 'variable' && ! state.variationId ) {
			showError( card, i18n.select_variation );
			return;
		}

		const postProductId = ( data.type === 'variable' && state.variationId )
			? state.variationId
			: productId;

		const body = new URLSearchParams( {
			product_id: postProductId,
			quantity:   qty,
		} );

		if ( lang ) {
			body.append( 'lang', lang );
		}

		setButtonState( btn, 'loading' );
		clearError( card );

		fetch(
			ajax_url.replace( '%%endpoint%%', 'add_to_cart' ),
			{
				method:  'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body:    body.toString(),
				credentials: 'same-origin',
			}
		)
		.then( r => {
			if ( ! r.ok ) throw new Error( 'network' );
			return r.json();
		} )
		.then( response => {
			if ( ! response || response.error ) {
				setButtonState( btn, 'default' );
				showError( card, i18n.error );
				return;
			}

			$( document.body ).trigger(
				'added_to_cart',
				[ response.fragments, response.cart_hash ]
			);

			setButtonState( btn, 'added' );
			setTimeout( () => setButtonState( btn, 'default' ), 2200 );
		} )
		.catch( () => {
			setButtonState( btn, 'default' );
			showError( card, i18n.error );
		} );
	}

	// ── Button state ─────────────────────────────────────────────────────────

	function setButtonState( btn, state ) {
		if ( ! originalHTML.has( btn ) ) {
			originalHTML.set( btn, btn.innerHTML );
		}

		btn.classList.remove( 'is-loading', 'is-added' );

		switch ( state ) {
			case 'loading':
				btn.classList.add( 'is-loading' );
				btn.disabled = true;
				break;

			case 'added':
				btn.classList.add( 'is-added' );
				btn.disabled = false;
				btn.innerHTML = '<svg class="yk-btn__check-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><polyline points="2 13 9 20 22 4" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg> '
					+ escHtml( i18n.added );
				break;

			case 'default':
			default:
				btn.disabled  = false;
				btn.innerHTML = originalHTML.get( btn );
				break;
		}
	}

	// ── Inline error ──────────────────────────────────────────────────────────

	function showError( card, message ) {
		clearError( card );

		const el = document.createElement( 'p' );
		el.className   = 'yk-card__error';
		el.textContent = message;
		el.setAttribute( 'role', 'alert' );

		const btn = card.querySelector( '.yk-add-to-cart' );
		if ( btn && btn.parentNode ) {
			btn.insertAdjacentElement( 'afterend', el );
		}

		errorTimers.set( el, setTimeout( () => {
			if ( el.parentNode ) el.remove();
		}, 4000 ) );
	}

	function clearError( card ) {
		const existing = card.querySelector( '.yk-card__error' );
		if ( existing ) {
			clearTimeout( errorTimers.get( existing ) );
			errorTimers.delete( existing );
			existing.remove();
		}
	}

	// ── Utility ───────────────────────────────────────────────────────────────

	function escHtml( str ) {
		const d = document.createElement( 'div' );
		d.textContent = str;
		return d.innerHTML;
	}

	// ── Boot ─────────────────────────────────────────────────────────────────

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initCards );
	} else {
		initCards();
	}

} )( jQuery );
