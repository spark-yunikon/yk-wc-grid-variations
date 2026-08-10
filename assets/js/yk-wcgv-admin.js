/**
 * Attribute term swatch fields (admin only).
 *
 * Loaded on edit-tags.php / term.php for pa_* taxonomies. Keeps the colour text
 * input (the only submitted colour field), the two native colour pickers and the
 * live preview in sync, and drives the media frame for the swatch image.
 */
( function() {
	'use strict';

	var HEX = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i;

	function parseColors( raw ) {
		var out = [];
		String( raw || '' ).split( ',' ).forEach( function( part ) {
			var hex = part.trim();
			if ( hex && hex.charAt( 0 ) !== '#' ) {
				hex = '#' + hex;
			}
			if ( HEX.test( hex ) && out.length < 2 ) {
				out.push( hex.toLowerCase() );
			}
		} );
		return out;
	}

	function background( colors ) {
		if ( ! colors.length ) {
			return 'transparent';
		}
		if ( colors.length > 1 ) {
			return 'linear-gradient(135deg,' + colors[0] + ' 0 50%,' + colors[1] + ' 50% 100%)';
		}
		return colors[0];
	}

	function initColorField( field ) {
		var text    = field.querySelector( '[data-yk-swatch-text]' );
		var preview = field.querySelector( '[data-yk-swatch-preview]' );
		var pickers = field.querySelectorAll( '[data-yk-swatch-color]' );

		if ( ! text ) {
			return;
		}

		function syncFromText() {
			var colors = parseColors( text.value );

			if ( preview ) {
				preview.style.background = background( colors );
			}

			Array.prototype.forEach.call( pickers, function( picker ) {
				var index = parseInt( picker.getAttribute( 'data-yk-swatch-color' ), 10 );
				if ( colors[ index ] ) {
					picker.value = colors[ index ];
				}
			} );
		}

		function syncFromPickers() {
			var colors = parseColors( text.value );
			var next   = [];

			Array.prototype.forEach.call( pickers, function( picker ) {
				var index = parseInt( picker.getAttribute( 'data-yk-swatch-color' ), 10 );
				// The second picker only joins in once it has been used or a second
				// colour already exists — otherwise every term would become two-tone.
				if ( 0 === index || colors.length > 1 || picker.dataset.ykTouched === '1' ) {
					next[ index ] = picker.value;
				}
			} );

			text.value = next.filter( Boolean ).join( ',' );
			if ( preview ) {
				preview.style.background = background( parseColors( text.value ) );
			}
		}

		text.addEventListener( 'input', syncFromText );

		Array.prototype.forEach.call( pickers, function( picker ) {
			picker.addEventListener( 'input', function() {
				picker.dataset.ykTouched = '1';
				syncFromPickers();
			} );
		} );

		var clearSecond = field.querySelector( '[data-yk-swatch-clear-second]' );
		if ( clearSecond ) {
			clearSecond.addEventListener( 'click', function() {
				var colors = parseColors( text.value );
				text.value = colors.length ? colors[0] : '';
				Array.prototype.forEach.call( pickers, function( picker ) {
					if ( '1' === picker.getAttribute( 'data-yk-swatch-color' ) ) {
						picker.dataset.ykTouched = '0';
					}
				} );
				syncFromText();
			} );
		}
	}

	function initImageField( field ) {
		var input   = field.querySelector( '[data-yk-swatch-image-input]' );
		var preview = field.querySelector( '[data-yk-swatch-image-preview]' );
		var select  = field.querySelector( '[data-yk-swatch-image-select]' );
		var remove  = field.querySelector( '[data-yk-swatch-image-remove]' );
		var frame;

		if ( ! input || ! select ) {
			return;
		}

		select.addEventListener( 'click', function( event ) {
			event.preventDefault();

			if ( ! window.wp || ! window.wp.media ) {
				return;
			}

			if ( ! frame ) {
				frame = window.wp.media( {
					title: ( window.ykWcgvAdmin && window.ykWcgvAdmin.frameTitle ) || '',
					button: { text: ( window.ykWcgvAdmin && window.ykWcgvAdmin.frameButton ) || '' },
					library: { type: 'image' },
					multiple: false
				} );

				frame.on( 'select', function() {
					var attachment = frame.state().get( 'selection' ).first().toJSON();
					var url        = attachment.url;

					if ( attachment.sizes && attachment.sizes.thumbnail ) {
						url = attachment.sizes.thumbnail.url;
					}

					input.value = attachment.id;

					if ( preview ) {
						preview.src           = url;
						preview.style.display = '';
					}
					if ( remove ) {
						remove.style.display = '';
					}
				} );
			}

			frame.open();
		} );

		if ( remove ) {
			remove.addEventListener( 'click', function( event ) {
				event.preventDefault();
				input.value = '0';
				if ( preview ) {
					preview.src           = '';
					preview.style.display = 'none';
				}
				remove.style.display = 'none';
			} );
		}
	}

	function init( root ) {
		var scope = root || document;
		Array.prototype.forEach.call( scope.querySelectorAll( '.yk-wcgv-swatch-field' ), initColorField );
		Array.prototype.forEach.call( scope.querySelectorAll( '.yk-wcgv-swatch-image-field' ), initImageField );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function() {
			init( document );
		} );
	} else {
		init( document );
	}
} )();
