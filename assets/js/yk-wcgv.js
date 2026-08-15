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
 *
 * Payload contract (see README "JS data payload"):
 *   data.attributes[] = { name, label, style, options[] }   — every attribute marked
 *                       "Used for variations", in back-office order. `options[].swatch`
 *                       is OPTIONAL: pill attributes do not carry it.
 *   data.images[]     = { url, srcset, sizes }              — per-product image pool
 *   data.variations[] = { variation_id, attributes, img, is_in_stock, max_qty }
 *                       `img` is an index into data.images, or null.
 *
 * The markup renders one group per attribute, marked with data-attribute="<name>", so
 * any number of attributes works — colour and size are not special-cased any more.
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
			cardState.set( card, { selections: {}, variationId: null } );
		}
		return cardState.get( card );
	}

	// ── DOM helpers ──────────────────────────────────────────────────────────

	function getGroups( card ) {
		return Array.from( card.querySelectorAll( '[data-attribute]' ) );
	}

	function getOptions( group ) {
		return Array.from( group.querySelectorAll( '.yk-swatch, .yk-size' ) );
	}

	// ── Variation resolution ─────────────────────────────────────────────────

	/**
	 * True when a variation is compatible with the current selections.
	 * An empty attribute value means "any" in WooCommerce.
	 */
	function variationMatches( variation, selections ) {
		for ( const name in selections ) {
			const wanted = selections[ name ];
			if ( ! wanted ) {
				continue; // nothing picked for this attribute yet
			}
			const actual = variation.attributes[ name ];
			if ( actual === '' || actual === undefined ) {
				continue; // "any"
			}
			if ( actual !== wanted ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Could this attribute value still lead to an in-stock variation, given `constraints`?
	 */
	function isOptionAvailable( data, constraints, name, value ) {
		const test = Object.assign( {}, constraints );
		test[ name ] = value;

		return data.variations.some( v => v.is_in_stock && variationMatches( v, test ) );
	}

	/**
	 * Selections of the groups that come BEFORE `index`.
	 *
	 * Attributes cascade in render order: the first one is always fully clickable, and each
	 * further one is filtered by the choices above it. Constraining a group by the groups
	 * below it would dead-end the card — every other colour would show up disabled just
	 * because the currently selected size happens to be sold out in it.
	 */
	function priorSelections( groups, state, index ) {
		const prior = {};

		groups.slice( 0, index ).forEach( group => {
			const name = group.dataset.attribute;
			if ( state.selections[ name ] ) {
				prior[ name ] = state.selections[ name ];
			}
		} );

		return prior;
	}

	/**
	 * The variation for the current selections — only once every attribute is decided,
	 * because adding a half-specified variation to the cart is never right.
	 */
	function resolveVariation( data, selections ) {
		const attributes = data.attributes || [];

		if ( ! attributes.every( a => !! selections[ a.name ] ) ) {
			return null;
		}

		return data.variations.find( v => variationMatches( v, selections ) ) || null;
	}

	// ── Card initialisation ──────────────────────────────────────────────────

	function initCards( root ) {
		const scope = root || document;
		scope.querySelectorAll( '.yk-card' ).forEach( initCard );
	}

	function initCard( card ) {
		// Idempotent: infinite scroll appends cards and initialises just those, but a
		// second pass over an already wired card would bind every listener twice.
		if ( card.dataset.ykInit === '1' ) {
			return;
		}

		// Bundle cards have no .yk-add-to-cart button — skip entirely.
		const btn = card.querySelector( '.yk-add-to-cart' );
		if ( ! btn ) {
			return;
		}

		card.dataset.ykInit = '1';

		const productId = btn.dataset.productId;
		const data      = products[ productId ];

		if ( ! data || data.type === 'bundle' ) {
			return;
		}

		// ── Attribute groups (swatches and pills alike) ──────────────────────
		if ( data.type === 'variable' ) {
			getGroups( card ).forEach( group => {
				getOptions( group ).forEach( option => {
					option.addEventListener( 'click', () => onOptionClick( option, group, card, data ) );
				} );
			} );
		}

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

		// ── Pre-select the first available option of every attribute ──────────
		if ( data.type === 'variable' ) {
			preselect( card, data );
			refreshCard( card, data );
		}
	}

	// ── Pre-selection on init ────────────────────────────────────────────────

	/**
	 * Walk the groups in render order and pick the first option that is still possible
	 * given the earlier picks. PHP marks the first option of each group active; this
	 * re-decides it against real stock data.
	 */
	function preselect( card, data ) {
		const state  = getState( card );
		const groups = getGroups( card );

		groups.forEach( ( group, index ) => {
			const name    = group.dataset.attribute;
			const options = getOptions( group );

			options.forEach( o => o.classList.remove( 'is-active' ) );
			state.selections[ name ] = null;

			const prior = priorSelections( groups, state, index );
			const pick  = options.find( o => isOptionAvailable( data, prior, name, o.dataset.value ) );

			if ( pick ) {
				pick.classList.add( 'is-active' );
				state.selections[ name ] = pick.dataset.value;
			}
		} );
	}

	// ── Option click ─────────────────────────────────────────────────────────

	function onOptionClick( option, group, card, data ) {
		if ( option.classList.contains( 'is-disabled' ) || option.disabled ) {
			return;
		}

		const state  = getState( card );
		const groups = getGroups( card );
		const name   = group.dataset.attribute;
		const value  = option.dataset.value;

		enableAnnouncements( card );

		if ( state.selections[ name ] === value ) {
			// Toggle off.
			state.selections[ name ] = null;
			option.classList.remove( 'is-active' );
		} else {
			state.selections[ name ] = value;
			getOptions( group ).forEach( o => o.classList.remove( 'is-active' ) );
			option.classList.add( 'is-active' );
		}

		refreshCard( card, data, groups.indexOf( group ) );
	}

	// ── Availability + resolution ────────────────────────────────────────────

	/**
	 * Repaint availability, then resolve the variation and follow up (image, qty cap).
	 *
	 * @param {number|null} changedIndex Index of the group the shopper just changed.
	 *                                   Selections below it are re-checked against the new
	 *                                   combination and only dropped when they became
	 *                                   impossible — a size that is still available stays
	 *                                   selected instead of forcing the shopper to re-pick it.
	 */
	function refreshCard( card, data, changedIndex ) {
		const state  = getState( card );
		const groups = getGroups( card );

		if ( typeof changedIndex === 'number' ) {
			groups.forEach( ( group, index ) => {
				if ( index <= changedIndex ) {
					return;
				}

				const name  = group.dataset.attribute;
				const value = state.selections[ name ];
				if ( ! value ) {
					return;
				}

				// Evaluated against the selections that survive above this group, so the
				// chain stays satisfiable: a kept value always has an in-stock variation.
				if ( isOptionAvailable( data, priorSelections( groups, state, index ), name, value ) ) {
					return;
				}

				state.selections[ name ] = null;
				getOptions( group ).forEach( o => o.classList.remove( 'is-active' ) );
			} );
		}

		groups.forEach( ( group, index ) => {
			const name  = group.dataset.attribute;
			const prior = priorSelections( groups, state, index );

			getOptions( group ).forEach( option => {
				const available = isOptionAvailable( data, prior, name, option.dataset.value );

				option.classList.toggle( 'is-disabled', ! available );

				// Only real buttons (pills) can carry the native disabled attribute.
				if ( option.tagName === 'BUTTON' ) {
					option.disabled = ! available;
				}

				// A selection that just became impossible is dropped.
				if ( ! available && state.selections[ name ] === option.dataset.value ) {
					state.selections[ name ] = null;
					option.classList.remove( 'is-active' );
				}
			} );
		} );

		const variation = resolveVariation( data, state.selections );
		state.variationId = variation ? variation.variation_id : null;

		if ( variation ) {
			updateQtyMax( card, variation.max_qty );
		}
		updateImage( card, data, variation );
		updatePriceAndSku( card, data, variation );
	}

	// ── Price and SKU ─────────────────────────────────────────────────────────

	/**
	 * Show the resolved variation's price and SKU, or fall back to what the server
	 * rendered for the parent.
	 *
	 * While a selection is still incomplete the parent's price range and SKU stay put:
	 * blanking them mid-cascade makes a grid of cards flicker for no information gain.
	 */
	function updatePriceAndSku( card, data, variation ) {
		const priceEl = card.querySelector( '.yk-price__value' );
		const skuEl   = card.querySelector( '.yk-card__sku strong' );

		// Prices are pooled per product (most variations of a product share a price), so
		// the variation carries an index rather than the markup.
		const priceHtml = ( variation && variation.price !== null && variation.price !== undefined )
			? ( data.prices || [] )[ variation.price ]
			: null;

		if ( priceEl ) {
			// Remember the server-rendered parent markup once, and reserve the height it
			// takes. A range ("CHF 19.10 – CHF 20.60") wraps to two lines where a single
			// price needs one, and without the reservation every card in the row would
			// jump the moment the preselection resolves.
			if ( priceEl.dataset.ykDefault === undefined ) {
				priceEl.dataset.ykDefault = priceEl.innerHTML;

				// Reserve on the block container, never on the inline value: pinning a
				// height on the value span pushes the "inkl. MwSt." suffix onto its own
				// line and makes the card taller instead of keeping it steady.
				const box = priceEl.closest( '.yk-price' );
				if ( box ) {
					const height = box.getBoundingClientRect().height;
					if ( height ) {
						box.style.minHeight = height + 'px';
					}
				}
			}

			setHtmlIfChanged( priceEl, priceHtml || priceEl.dataset.ykDefault );
		}

		if ( skuEl ) {
			if ( skuEl.dataset.ykDefault === undefined ) {
				skuEl.dataset.ykDefault = skuEl.textContent;
			}

			const nextSku = ( variation && variation.sku ) ? variation.sku : skuEl.dataset.ykDefault;
			if ( skuEl.textContent !== nextSku ) {
				skuEl.textContent = nextSku;
				flash( skuEl );
			}
		}
	}

	function setHtmlIfChanged( el, html ) {
		if ( el.innerHTML === html ) {
			return;
		}
		el.innerHTML = html;
		flash( el );
	}

	/**
	 * Brief highlight so a changed number is noticed. Deliberately small: sixteen cards
	 * pulsing at once during a cascade would be noise, not feedback.
	 */
	function flash( el ) {
		el.classList.remove( 'yk-value-updated' );
		// Force a reflow so the animation restarts when the value changes twice quickly.
		void el.offsetWidth;
		el.classList.add( 'yk-value-updated' );
	}

	/**
	 * Announce price/SKU changes only for the card the shopper is actually using.
	 *
	 * The regions are not live at page load on purpose: preselection resolves a variation
	 * on every card, and sixteen simultaneous announcements would bury the page. The first
	 * click on a card marks that card's regions polite, so from then on its own changes
	 * are read out.
	 */
	function enableAnnouncements( card ) {
		[ '.yk-price__value', '.yk-card__sku' ].forEach( function ( selector ) {
			const el = card.querySelector( selector );
			if ( el && ! el.hasAttribute( 'aria-live' ) ) {
				el.setAttribute( 'aria-live', 'polite' );
			}
		} );
	}

	// ── Image update ──────────────────────────────────────────────────────────

	/**
	 * Swap the card thumbnail to the resolved variation's image.
	 *
	 * Images live in a per-product pool; the variation only carries an index. When the
	 * variation has no image (img === null) the current image is left untouched.
	 */
	function updateImage( card, data, variation ) {
		if ( ! variation || variation.img === null || variation.img === undefined ) {
			return;
		}

		const image = ( data.images || [] )[ variation.img ];
		if ( ! image || ! image.url ) {
			return;
		}

		const imgEl = card.querySelector( '.yk-card__img' );
		if ( ! imgEl ) {
			return;
		}

		// The payload carries the URL only. The markup WooCommerce printed still has the
		// original srcset/sizes, and a browser picks a candidate from srcset over src — so
		// setting src alone would leave the previous variation's picture on screen. Drop
		// both attributes before swapping.
		imgEl.removeAttribute( 'srcset' );
		imgEl.removeAttribute( 'sizes' );
		imgEl.src = image.url;
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

		// Append to .yk-cart-row, never after the button: the row is a flex container, so a
		// sibling of the button becomes a flex item and steals its width ("In den …arenk").
		// CSS takes the message out of flow (absolute, top:100%), so it spans the full row
		// width below the button and adds no height — card heights stay uniform in the grid.
		const row = card.querySelector( '.yk-cart-row' );
		if ( row ) {
			row.appendChild( el );
		} else {
			const btn = card.querySelector( '.yk-add-to-cart' );
			if ( btn && btn.parentNode ) {
				btn.insertAdjacentElement( 'afterend', el );
			}
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

	// ── Public surface for yk-wcgv-infinite.js ───────────────────────────────

	window.ykWcgvArchive = {
		/**
		 * Wire up cards that were just appended. Pass the container holding only the new
		 * nodes; already-initialised cards are skipped either way.
		 */
		initCards: initCards,

		/**
		 * Merge a page's product payload into the live one. `products` is the same object
		 * reference the card handlers read, so extending it is enough.
		 */
		addProducts: function ( more ) {
			Object.assign( products, more || {} );
		},
	};

	// ── Boot ─────────────────────────────────────────────────────────────────

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () { initCards(); } );
	} else {
		initCards();
	}

} )( jQuery );
