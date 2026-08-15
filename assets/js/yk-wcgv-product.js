/**
 * YK WC Grid Variations — Single product page.
 *
 * Replaces WooCommerce variation <select> dropdowns with visual swatches and pills, and
 * upgrades the native quantity input with +/- stepper buttons.
 *
 * EVERY variation select is converted — there is no colour/size keyword matching any more.
 * Whether a group renders as swatches or pills comes from the payload, decided by the same
 * PHP function the archive cards use, so both screens always agree.
 *
 * WooCommerce stays the single source of truth for availability: we set the select's value
 * and dispatch `change`, then mirror whatever WC marks disabled. No cascade logic here —
 * that lives in the archive JS only, where there is no variations_form to defer to.
 *
 * Depends on:
 *   window.ykWcgvProduct — injected via wp_add_inline_script
 *     .attributes[ 'attribute_pa_farbe' ] = { label, style, options: { value: { label, swatch? } } }
 *     .colors                             = legacy slug => hex map (fallback only)
 *     .i18n                               = translated UI strings
 *   jQuery               — for WooCommerce variation form events
 */
( function ( $ ) {
	'use strict';

	const payload    = ( typeof ykWcgvProduct !== 'undefined' ) ? ykWcgvProduct : {};
	const attributes = payload.attributes || {};
	const colors     = payload.colors || {};
	const i18n       = payload.i18n || {};

	// ── Variation swatches + pills ────────────────────────────────────────────

	function initVariations() {
		const form = document.querySelector( 'form.variations_form' );
		if ( ! form ) return;

		form.querySelectorAll( 'table.variations tr' ).forEach( row => {
			const select = row.querySelector( 'select' );
			if ( ! select ) return;

			const td = row.querySelector( 'td.value' );
			if ( ! td ) return;

			const name    = select.getAttribute( 'data-attribute_name' ) || select.name || '';
			const meta    = attributes[ name ] || null;
			const labelEl = row.querySelector( 'th.label label' );

			buildGroup( select, td, {
				name:    name,
				// Unknown attribute (payload missing, or a translated taxonomy we did not
				// resolve): render pills rather than skipping, so no native select is left behind.
				style:   meta ? meta.style : 'pill',
				label:   meta ? meta.label : ( labelEl ? labelEl.textContent.trim() : '' ),
				options: meta ? ( meta.options || {} ) : {},
			} );
		} );

		const resetLink = buildResetLink( form );

		// WooCommerce fires reset_data on "Clear" and whenever the current combination
		// matches nothing. Re-read the selects instead of blindly clearing the visual
		// state: WC may well have kept a value, and the two must not drift apart.
		$( form ).on( 'reset_data', () => {
			form.querySelectorAll( '[data-attribute]' ).forEach( wrap => {
				const select = wrap.parentElement ? wrap.parentElement.querySelector( 'select' ) : null;
				if ( select ) syncFromSelect( select, wrap );
			} );
			updateResetLink( form, resetLink );
		} );

		// Bound through jQuery, not addEventListener: WooCommerce changes selects with
		// jQuery's .trigger( 'change' ), which runs jQuery handlers without dispatching a
		// native event. A native listener would miss exactly the programmatic changes that
		// matter here (deep links with ?attribute_… in the URL, WC's own resets).
		$( form ).on( 'change', 'select', () => updateResetLink( form, resetLink ) );

		updateResetLink( form, resetLink );
	}

	/**
	 * "Auswahl zurücksetzen" — the way out of a dead end.
	 *
	 * On a three-attribute product an invalid combination leaves WooCommerce disabling every
	 * remaining option, and WC hides its own .reset_variations link unless a variation has
	 * resolved. Re-clicking the active option still escapes, but nothing on screen says so.
	 *
	 * This button does not reset anything itself — it clicks WooCommerce's own link. Writing
	 * our own reset would make this file a second writer on the selects, which is what
	 * silently wiped shoppers' selections before. If WC ships no reset link, we show nothing
	 * rather than inventing that second writer.
	 *
	 * @param  {HTMLFormElement} form The variations form.
	 * @return {HTMLElement|null} The button, or null when there is nothing to delegate to.
	 */
	function buildResetLink( form ) {
		const native = form.querySelector( '.reset_variations' );
		const table  = form.querySelector( 'table.variations' );
		if ( ! native || ! table || form.querySelector( '.yk-sp-reset' ) ) return null;

		// A button, not an <a>: it performs an action rather than navigating, and that also
		// makes it keyboard-operable without extra wiring. It is styled as a text link.
		const button = document.createElement( 'button' );
		button.type        = 'button';
		button.className   = 'yk-sp-reset';
		button.hidden      = true;
		button.textContent = i18n.reset_selection || 'Auswahl zurücksetzen';

		button.addEventListener( 'click', () => native.click() );

		table.insertAdjacentElement( 'afterend', button );
		return button;
	}

	/**
	 * Show the reset button only once something is selected — an untouched form has nothing
	 * to reset, and a control that is always there is one more thing to read past.
	 */
	function updateResetLink( form, button ) {
		if ( ! button ) return;

		const chosen = Array.from( form.querySelectorAll( 'table.variations select' ) )
			.some( select => select.value !== '' );

		button.hidden = ! chosen;
	}

	/**
	 * Replace one variation select with a swatch or pill group.
	 *
	 * @param {HTMLSelectElement} select The native select (hidden, kept as the state holder).
	 * @param {HTMLElement}       td     Cell to render into.
	 * @param {Object}            meta   { name, style, label, options }.
	 */
	function buildGroup( select, td, meta ) {
		const isSwatch = meta.style === 'swatch';

		const wrap = document.createElement( 'div' );
		wrap.className = isSwatch ? 'yk-sp-swatches' : 'yk-sp-sizes';
		wrap.setAttribute( 'role', 'group' );
		wrap.dataset.attribute = meta.name;
		if ( meta.label ) {
			wrap.setAttribute( 'aria-label', meta.label );
		}

		Array.from( select.options ).forEach( opt => {
			if ( ! opt.value ) return;

			const optionMeta = meta.options[ opt.value ] || null;
			const el = isSwatch ? buildSwatch( opt, optionMeta ) : buildPill( opt, optionMeta );

			el.addEventListener( 'click', () => onOptionClick( el, select, wrap ) );
			if ( el.tagName !== 'BUTTON' ) {
				el.addEventListener( 'keydown', e => {
					if ( e.key === 'Enter' || e.key === ' ' ) {
						e.preventDefault();
						onOptionClick( el, select, wrap );
					}
				} );
			}

			wrap.appendChild( el );
		} );

		select.style.display = 'none';
		td.insertBefore( wrap, select );

		observeSelect( select, wrap );
		// Paints the initial state too (WooCommerce may already have a value from the
		// URL, a default, or a single remaining option).
		syncFromSelect( select, wrap );
	}

	function buildSwatch( opt, optionMeta ) {
		const label  = optionMeta && optionMeta.label ? optionMeta.label : opt.text;
		const swatch = document.createElement( 'span' );

		swatch.className     = 'yk-sp-swatch';
		swatch.dataset.value = opt.value;
		swatch.title         = label;
		swatch.setAttribute( 'role', 'button' );
		swatch.setAttribute( 'tabindex', '0' );
		swatch.setAttribute( 'aria-label', label );
		if ( opt.disabled ) swatch.classList.add( 'is-disabled' );

		applySwatchStyle( swatch, optionMeta ? optionMeta.swatch : null, opt );

		return swatch;
	}

	function buildPill( opt, optionMeta ) {
		const pill = document.createElement( 'button' );

		pill.type          = 'button';
		pill.className     = 'yk-sp-size';
		pill.dataset.value = opt.value;
		pill.textContent   = optionMeta && optionMeta.label ? optionMeta.label : opt.text;
		if ( opt.disabled ) { pill.classList.add( 'is-disabled' ); pill.disabled = true; }

		return pill;
	}

	/**
	 * Paint a swatch exactly like the archive card does: image, two-tone gradient, or
	 * flat colour, falling back to the legacy colour map and finally to neutral grey.
	 */
	/**
	 * Paint one swatch chip.
	 *
	 * Uses the background longhands only — never the `background:` shorthand. The archive
	 * template (content-product.php) and the admin previews follow the same rule, so a
	 * background-color painted underneath an image or gradient behaves identically on every
	 * screen instead of being silently reset on one of them.
	 */
	function applySwatchStyle( el, swatch, opt ) {
		// Light fills need a visible edge — same rule as the archive card, driven by the
		// payload's WCAG luminance flag rather than by matching colour strings.
		if ( swatch && swatch.is_light ) {
			el.classList.add( 'is-light' );
		}

		if ( swatch && swatch.type === 'image' && swatch.image ) {
			el.classList.remove( 'is-light' );   // the picture decides, not the hint colour
			el.classList.add( 'has-image' );
			// JSON.stringify quotes and escapes — a URL cannot break out of url().
			el.style.backgroundImage = 'url(' + JSON.stringify( swatch.image ) + ')';
			return;
		}

		if ( swatch && swatch.type === 'gradient' && swatch.color2 ) {
			el.style.backgroundImage = 'linear-gradient(135deg,' + swatch.color + ' 0 50%,' + swatch.color2 + ' 50% 100%)';
			return;
		}

		if ( swatch && swatch.color ) {
			el.style.backgroundColor = swatch.color;
			return;
		}

		el.style.backgroundColor = colors[ opt.value.toLowerCase() ]
			|| colors[ opt.text.toLowerCase().trim() ]
			|| '#cccccc';
	}

	/**
	 * Selecting is the only thing we do: write the value into the native select and let
	 * WooCommerce recompute what is available.
	 */
	function onOptionClick( el, select, wrap ) {
		if ( el.classList.contains( 'is-disabled' ) || el.disabled ) return;

		if ( el.classList.contains( 'is-active' ) ) {
			el.classList.remove( 'is-active' );
			select.value = '';
		} else {
			wrap.querySelectorAll( '[data-value]' ).forEach( o => o.classList.remove( 'is-active' ) );
			el.classList.add( 'is-active' );
			select.value = el.dataset.value;
		}

		select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}

	/**
	 * Mirror the native select into the swatch/pill group. READ-ONLY.
	 *
	 * WooCommerce owns availability and the selected value. This function must never
	 * write to the select or dispatch events: an earlier version cleared the select when
	 * the active option went disabled, which fought WC's own matching and silently wiped
	 * the shopper's choice on products with three attributes.
	 */
	function syncFromSelect( select, wrap ) {
		const optMap = {};
		Array.from( select.options ).forEach( o => { optMap[ o.value ] = o; } );

		const current = select.value;

		wrap.querySelectorAll( '[data-value]' ).forEach( el => {
			const opt       = optMap[ el.dataset.value ];
			// Option removed from the select entirely → not selectable right now either.
			const available = opt ? ! opt.disabled : false;

			el.classList.toggle( 'is-disabled', ! available );
			if ( el.tagName === 'BUTTON' ) el.disabled = ! available;

			el.classList.toggle( 'is-active', current !== '' && el.dataset.value === current );
		} );
	}

	/**
	 * Watch the select for anything WooCommerce does to it.
	 *
	 * MutationObserver catches option add/remove and disabled toggling; the change
	 * listener catches value changes, which are property writes and mutate no attribute.
	 */
	function observeSelect( select, wrap ) {
		const sync = () => syncFromSelect( select, wrap );

		new MutationObserver( sync ).observe( select, {
			childList: true, subtree: true,
			attributes: true, attributeFilter: [ 'disabled', 'class' ],
		} );

		select.addEventListener( 'change', sync );
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
