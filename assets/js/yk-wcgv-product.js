/**
 * YK WC Grid Variations — Single product page.
 *
 * Replaces WooCommerce variation <select> dropdowns with
 * visual colour swatches and size pills, and upgrades the
 * native quantity input with +/- stepper buttons.
 *
 * Depends on:
 *   window.ykWcgvProduct — injected via wp_add_inline_script
 *   jQuery               — for WooCommerce variation form events
 */
( function ( $ ) {
	'use strict';

	const colors     = ( typeof ykWcgvProduct !== 'undefined' ) ? ( ykWcgvProduct.colors || {} ) : {};
	const COLOR_KEYS = [ 'color', 'colour', 'farbe' ];
	const SIZE_KEYS  = [ 'size', 'größe', 'grösse', 'grosse', 'groesse', 'taille' ];

	// ── Variation swatches + pills ────────────────────────────────────────────

	function initVariations() {
		const form = document.querySelector( 'form.variations_form' );
		if ( ! form ) return;

		form.querySelectorAll( 'table.variations tr' ).forEach( row => {
			const select = row.querySelector( 'select' );
			if ( ! select ) return;

			const attrName = select.getAttribute( 'data-attribute_name' ) || select.name || '';
			const rawSlug  = attrName.replace( /^attribute_pa_/, '' ).replace( /^attribute_/, '' ).toLowerCase();
			const labelEl  = row.querySelector( 'th.label label' );
			const label    = ( labelEl ? labelEl.textContent : '' ).trim().toLowerCase();

			const isColor = COLOR_KEYS.some( k => rawSlug.includes( k ) || label.includes( k ) );
			const isSize  = SIZE_KEYS.some(  k => rawSlug.includes( k ) || label.includes( k ) );

			if ( ! isColor && ! isSize ) return;

			const td = row.querySelector( 'td.value' );
			if ( ! td ) return;

			isColor ? buildSwatches( select, td, labelEl ) : buildPills( select, td, labelEl );
		} );

		// Reset visual state when WooCommerce clears the form.
		$( form ).on( 'reset_data', () => {
			form.querySelectorAll( '.yk-sp-swatch.is-active, .yk-sp-size.is-active' )
				.forEach( el => el.classList.remove( 'is-active' ) );
		} );
	}

	function buildSwatches( select, td, labelEl ) {
		const wrap = document.createElement( 'div' );
		wrap.className = 'yk-sp-swatches';
		wrap.setAttribute( 'role', 'group' );
		if ( labelEl ) wrap.setAttribute( 'aria-label', labelEl.textContent.trim() );

		Array.from( select.options ).forEach( opt => {
			if ( ! opt.value ) return;

			const colorKey = opt.value.toLowerCase();
			const color    = colors[ colorKey ] ?? colors[ opt.text.toLowerCase().trim() ] ?? '#cccccc';

			const swatch = document.createElement( 'span' );
			swatch.className             = 'yk-sp-swatch';
			swatch.dataset.value         = opt.value;
			swatch.title                 = opt.text;
			swatch.style.backgroundColor = color;
			swatch.setAttribute( 'role', 'button' );
			swatch.setAttribute( 'tabindex', '0' );
			swatch.setAttribute( 'aria-label', opt.text );
			if ( opt.disabled ) swatch.classList.add( 'is-disabled' );

			swatch.addEventListener( 'click', () => onSwatchClick( swatch, select, wrap ) );
			swatch.addEventListener( 'keydown', e => {
				if ( e.key === 'Enter' || e.key === ' ' ) {
					e.preventDefault();
					onSwatchClick( swatch, select, wrap );
				}
			} );

			wrap.appendChild( swatch );
		} );

		// Pre-select if URL param already has a value.
		if ( select.value ) {
			const pre = wrap.querySelector( `[data-value="${ CSS.escape( select.value ) }"]` );
			if ( pre ) pre.classList.add( 'is-active' );
		}

		select.style.display = 'none';
		td.insertBefore( wrap, select );
		observeSelect( select, wrap );
	}

	function buildPills( select, td, labelEl ) {
		const wrap = document.createElement( 'div' );
		wrap.className = 'yk-sp-sizes';
		wrap.setAttribute( 'role', 'group' );
		if ( labelEl ) wrap.setAttribute( 'aria-label', labelEl.textContent.trim() );

		Array.from( select.options ).forEach( opt => {
			if ( ! opt.value ) return;

			const pill = document.createElement( 'button' );
			pill.type          = 'button';
			pill.className     = 'yk-sp-size';
			pill.dataset.value = opt.value;
			pill.textContent   = opt.text;
			if ( opt.disabled ) { pill.classList.add( 'is-disabled' ); pill.disabled = true; }

			pill.addEventListener( 'click', () => onPillClick( pill, select, wrap ) );
			wrap.appendChild( pill );
		} );

		if ( select.value ) {
			const pre = wrap.querySelector( `[data-value="${ CSS.escape( select.value ) }"]` );
			if ( pre ) pre.classList.add( 'is-active' );
		}

		select.style.display = 'none';
		td.insertBefore( wrap, select );
		observeSelect( select, wrap );
	}

	function onSwatchClick( swatch, select, wrap ) {
		if ( swatch.classList.contains( 'is-disabled' ) ) return;

		if ( swatch.classList.contains( 'is-active' ) ) {
			swatch.classList.remove( 'is-active' );
			select.value = '';
		} else {
			wrap.querySelectorAll( '.yk-sp-swatch' ).forEach( s => s.classList.remove( 'is-active' ) );
			swatch.classList.add( 'is-active' );
			select.value = swatch.dataset.value;
		}

		select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}

	function onPillClick( pill, select, wrap ) {
		if ( pill.classList.contains( 'is-disabled' ) || pill.disabled ) return;

		if ( pill.classList.contains( 'is-active' ) ) {
			pill.classList.remove( 'is-active' );
			select.value = '';
		} else {
			wrap.querySelectorAll( '.yk-sp-size' ).forEach( p => p.classList.remove( 'is-active' ) );
			pill.classList.add( 'is-active' );
			select.value = pill.dataset.value;
		}

		select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}

	// MutationObserver keeps visual disabled states in sync with WooCommerce's option filtering.
	function observeSelect( select, wrap ) {
		const sync = () => {
			const optMap = {};
			Array.from( select.options ).forEach( o => { optMap[ o.value ] = o; } );

			wrap.querySelectorAll( '[data-value]' ).forEach( el => {
				const opt          = optMap[ el.dataset.value ];
				if ( ! opt ) return;
				const shouldDisable = opt.disabled;

				if ( shouldDisable ) {
					el.classList.add( 'is-disabled' );
					if ( el.tagName === 'BUTTON' ) el.disabled = true;
					if ( el.classList.contains( 'is-active' ) ) {
						el.classList.remove( 'is-active' );
						select.value = '';
						select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
					}
				} else {
					el.classList.remove( 'is-disabled' );
					if ( el.tagName === 'BUTTON' ) el.disabled = false;
				}
			} );
		};

		new MutationObserver( sync ).observe( select, {
			childList: true, subtree: true,
			attributes: true, attributeFilter: [ 'disabled', 'class' ],
		} );
	}

	// ── Quantity stepper ──────────────────────────────────────────────────────

	function initQtyStepper() {
		document.querySelectorAll( 'div.quantity' ).forEach( wrap => {
			const input = wrap.querySelector( 'input.qty' );
			if ( ! input || wrap.querySelector( '.yk-sp-qty-minus' ) ) return;

			input.classList.add( 'yk-sp-qty-input' );
			wrap.classList.add( 'yk-sp-qty-wrap' );

			const minus = document.createElement( 'button' );
			minus.type      = 'button';
			minus.className = 'yk-sp-qty-minus';
			minus.setAttribute( 'aria-label', 'Decrease quantity' );
			minus.innerHTML = '<svg class="yk-qty-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path d="M12 20 L22 4 L2 4 Z" fill="currentColor"/></svg>';

			const plus = document.createElement( 'button' );
			plus.type      = 'button';
			plus.className = 'yk-sp-qty-plus';
			plus.setAttribute( 'aria-label', 'Increase quantity' );
			plus.innerHTML = '<svg class="yk-qty-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path d="M12 4 L22 20 L2 20 Z" fill="currentColor"/></svg>';

			wrap.insertBefore( minus, input );
			wrap.appendChild( plus );

			minus.addEventListener( 'click', () => stepQty( input, -1 ) );
			plus.addEventListener(  'click', () => stepQty( input, +1 ) );
			input.addEventListener( 'change', () => clampQty( input ) );
			input.addEventListener( 'input',  () => clampQty( input ) );
		} );
	}

	function stepQty( input, delta ) {
		const val = parseInt( input.value, 10 ) || 1;
		const min = parseInt( input.min,   10 ) || 1;
		const max = parseInt( input.max,   10 ) || 9999;
		input.value = Math.min( max, Math.max( min, val + delta ) );
		input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}

	function clampQty( input ) {
		const val = parseInt( input.value, 10 );
		const min = parseInt( input.min,   10 ) || 1;
		const max = parseInt( input.max,   10 ) || 9999;
		if ( isNaN( val ) || val < min ) input.value = min;
		else if ( val > max )            input.value = max;
	}

	// ── Boot ─────────────────────────────────────────────────────────────────

	function init() {
		initVariations();
		initQtyStepper();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

} )( jQuery );
