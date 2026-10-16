/**
 * YK WC Grid Variations — "Request a quote" links (price on request).
 *
 * Depends on:
 *   window.ykWcgvQuote — { email, subject, body }, injected before this file by
 *                        YK_WCGV_Quote::enqueue(). Same templates the PHP link uses.
 *   jQuery             — only to listen to WooCommerce's variation form events.
 *
 * Two jobs:
 *   1. On click, rebuild the link's mailto with the live quantity and, for a variable
 *      product, the chosen attributes ("Farbe: schwarz") and the variation's item number.
 *      The server-rendered href stays as the no-JS fallback (quantity 1, parent item
 *      number, no attributes).
 *   2. Single product page, variable product: show the quote link instead of WooCommerce's
 *      button when the chosen variation has no price (`yk_needs_quote` on the variation).
 *      Read-only towards the form — WooCommerce stays the single source of state.
 */
( function ( $ ) {
	'use strict';

	if ( typeof ykWcgvQuote === 'undefined' || ! ykWcgvQuote.email ) {
		return;
	}

	// ── mailto builder ───────────────────────────────────────────────────────

	function buildHref( link ) {
		const sku = link.dataset.variationSku || link.dataset.sku || '';

		const tokens = {
			'{name}':       link.dataset.name || '',
			'{attributes}': chosenAttributes( link ).join( '\n' ),
			'{sku}':        sku,
			'{qty}':        String( currentQty( link ) ),
			'{url}':        link.dataset.url || '',
		};

		// Same rule as YK_WCGV_Quote::mailto(): a line whose placeholder is empty goes.
		const body = ( ykWcgvQuote.body || '' ).split( '\n' ).filter( line =>
			! [ '{attributes}', '{sku}' ].some( key => ! tokens[ key ] && line.indexOf( key ) !== -1 )
		).join( '\n' );

		const subject = fill( ykWcgvQuote.subject || '', tokens );

		return 'mailto:' + ykWcgvQuote.email
			+ '?subject=' + encodeURIComponent( subject )
			+ '&body=' + encodeURIComponent( fill( body, tokens ).replace( /\n/g, '\r\n' ) );
	}

	/**
	 * "Label: value" for every attribute the shopper has chosen, in display order.
	 *
	 * Card: the groups carry their label in aria-label and the chosen option is .is-active
	 * (swatches hold their label in data-label, pills in their text). Product page:
	 * WooCommerce's own <label for> and the selected <option> — present whether or not the
	 * enhanced swatch UI replaced the visible selects. Unchosen attributes are skipped.
	 */
	function chosenAttributes( link ) {
		const lines = [];
		const card  = link.closest( '.yk-card' );

		if ( card ) {
			card.querySelectorAll( '[data-attribute]' ).forEach( group => {
				const active = group.querySelector( '.is-active' );
				const value  = active ? ( active.dataset.label || active.textContent ).trim() : '';
				if ( value ) {
					lines.push( ( group.getAttribute( 'aria-label' ) || '' ).trim() + ': ' + value );
				}
			} );
			return lines;
		}

		const form = link.closest( 'form.variations_form' );
		if ( form ) {
			// Matched by htmlFor rather than a [for="…"] selector: attribute slugs can hold
			// characters that would need CSS.escape(), and a throw here would leave the
			// server-rendered href (no attributes, parent item number) in place.
			const labels = Array.from( form.querySelectorAll( 'table.variations label' ) );

			form.querySelectorAll( 'table.variations select' ).forEach( select => {
				const option = select.value ? select.options[ select.selectedIndex ] : null;
				const label  = labels.find( l => select.id && l.htmlFor === select.id );
				if ( option ) {
					lines.push( ( label ? label.textContent : select.name ).trim() + ': ' + option.textContent.trim() );
				}
			} );
		}

		return lines;
	}

	function fill( template, tokens ) {
		return Object.keys( tokens ).reduce(
			( text, key ) => text.split( key ).join( tokens[ key ] ),
			template
		);
	}

	/**
	 * Quantity from the card stepper, or from the product page's quantity field when the
	 * link sits inside the add-to-cart form. Anything unreadable counts as 1.
	 */
	function currentQty( link ) {
		const scope = link.closest( '.yk-card' ) || link.closest( 'form.cart' );
		const input = scope ? scope.querySelector( '.yk-qty-input, input.qty' ) : null;
		const qty   = input ? parseInt( input.value, 10 ) : 1;

		return qty > 0 ? qty : 1;
	}

	document.addEventListener( 'click', function ( event ) {
		const link = event.target.closest( 'a.yk-quote' );
		if ( link ) {
			// No preventDefault: the browser follows the freshly set href.
			link.href = buildHref( link );
		}
	} );

	// ── Single product page, variable product ───────────────────────────────

	$( function () {
		$( 'form.variations_form' ).each( function () {
			const form = this;
			const box  = form.querySelector( '.yk-sp-quote' );
			if ( ! box ) {
				return;
			}
			const link = box.querySelector( '.yk-quote' );

			function setQuoteMode( on, sku ) {
				box.hidden = ! on;
				form.classList.toggle( 'yk-sp-quote-mode', on );
				if ( link ) {
					link.dataset.variationSku = sku || '';
				}
			}

			$( form )
				.on( 'show_variation', function ( event, variation ) {
					setQuoteMode( !! ( variation && variation.yk_needs_quote ), variation && variation.sku );
				} )
				.on( 'hide_variation reset_data', function () {
					setQuoteMode( false, '' );
				} );
		} );
	} );
}( jQuery ) );
