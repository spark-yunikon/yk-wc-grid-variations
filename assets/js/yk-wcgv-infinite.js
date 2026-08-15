/**
 * YK WC Grid Variations — Infinite scroll for product archives.
 *
 * Loaded (deferred) only when the effective paging mode is 'infinite'. Everything here is
 * additive: the WooCommerce pagination stays in the DOM and is only hidden visually, so a
 * crawler, a keyboard user without JS, or a failed request all still have working links.
 *
 * Depends on:
 *   window.ykWcgvInfinite — config injected via wp_add_inline_script
 *   window.ykWcgvArchive  — { initCards, addProducts } exposed by yk-wcgv.js
 */
( function () {
	'use strict';

	const cfg = window.ykWcgvInfinite;
	if ( ! cfg || ! cfg.ajaxUrl || ! cfg.nonce ) {
		return;
	}

	const i18n = cfg.i18n || {};

	let currentPage = parseInt( cfg.currentPage, 10 ) || 1;
	let maxPages    = parseInt( cfg.maxPages, 10 ) || 1;
	let loading     = false;
	let failed      = false;
	let controller  = null;
	let observer    = null;

	let grid, sentinel, status;

	// ── Boot ─────────────────────────────────────────────────────────────────

	function init() {
		grid = document.querySelector( 'ul.products' );
		if ( ! grid ) {
			return;
		}

		// An archive that lists only subcategory tiles has no product cards. Rendering a
		// sentinel there would fetch empty page after empty page.
		if ( ! grid.querySelector( '.yk-card' ) ) {
			return;
		}

		// Everything already fits on one page: nothing to observe, pagination stays visible
		// (there is none) and we leave the DOM alone.
		if ( currentPage >= maxPages ) {
			return;
		}

		hidePagination();
		buildSentinel();
		observe();
	}

	/**
	 * Hide, never remove. The links keep working for crawlers and for anyone without JS,
	 * and rel="next" stays discoverable.
	 */
	function hidePagination() {
		document.querySelectorAll( '.woocommerce-pagination' ).forEach( function ( nav ) {
			nav.classList.add( 'yk-pagination--hidden' );
		} );
	}

	function showPagination() {
		document.querySelectorAll( '.woocommerce-pagination' ).forEach( function ( nav ) {
			nav.classList.remove( 'yk-pagination--hidden' );
		} );
	}

	function buildSentinel() {
		status = document.createElement( 'p' );
		status.className = 'yk-infinite-status';
		status.setAttribute( 'role', 'status' );
		status.setAttribute( 'aria-live', 'polite' );

		sentinel = document.createElement( 'div' );
		sentinel.className = 'yk-infinite-sentinel';
		sentinel.setAttribute( 'aria-hidden', 'true' );

		grid.insertAdjacentElement( 'afterend', status );
		status.insertAdjacentElement( 'beforebegin', sentinel );
	}

	function observe() {
		if ( ! ( 'IntersectionObserver' in window ) ) {
			showPagination();   // Very old browser: leave it on the normal pagination.
			return;
		}

		const distance = Math.max( 0, parseInt( cfg.prefetch, 10 ) || 0 );

		observer = new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( entry.isIntersecting ) {
					loadNext();
				}
			} );
		}, {
			// Start fetching before the sentinel is on screen, so the next cards are
			// usually already in place by the time the shopper gets there.
			rootMargin: distance + 'px 0px',
			threshold: 0,
		} );

		observer.observe( sentinel );
	}

	// ── Loading ──────────────────────────────────────────────────────────────

	function loadNext() {
		if ( loading || failed || currentPage >= maxPages ) {
			return;
		}

		loading = true;
		setStatus( i18n.loading || '' );

		// A fast scroll can fire the observer again before the previous response lands;
		// the lock above prevents overlap and this lets us drop an in-flight request if the
		// page is left.
		controller = new AbortController();

		const body = new URLSearchParams( cfg.query || {} );
		body.append( 'action', cfg.action );
		body.append( 'nonce', cfg.nonce );
		body.append( 'paged', String( currentPage + 1 ) );

		fetch( cfg.ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
			credentials: 'same-origin',
			signal: controller.signal,
		} )
			.then( function ( r ) {
				if ( ! r.ok ) {
					throw new Error( 'http ' + r.status );
				}
				return r.json();
			} )
			.then( function ( response ) {
				if ( ! response || ! response.success || ! response.data ) {
					throw new Error( 'payload' );
				}
				appendPage( response.data );
			} )
			.catch( function ( error ) {
				if ( error && error.name === 'AbortError' ) {
					return;
				}
				onFailure();
			} )
			.finally( function () {
				loading    = false;
				controller = null;
			} );
	}

	function appendPage( data ) {
		const html = ( data.html || '' ).trim();

		if ( ! html ) {
			// Nothing came back: treat this as the end rather than asking again.
			maxPages = currentPage;
			finish();
			return;
		}

		// Hand the new cards their payload BEFORE they are wired up.
		if ( window.ykWcgvArchive && data.products ) {
			window.ykWcgvArchive.addProducts( data.products );
		}

		// Parse in a detached list so the cards are complete before they enter the page,
		// and so we can initialise exactly these nodes.
		const holder = document.createElement( 'ul' );
		holder.innerHTML = html;

		const added = [];
		while ( holder.firstElementChild ) {
			const node = holder.firstElementChild;
			grid.appendChild( node );
			added.push( node );
		}

		added.forEach( function ( node ) {
			node.querySelectorAll( 'img' ).forEach( function ( img ) {
				if ( ! img.getAttribute( 'loading' ) ) {
					img.setAttribute( 'loading', 'lazy' );
				}
			} );
		} );

		if ( window.ykWcgvArchive ) {
			added.forEach( function ( node ) {
				if ( node.classList && node.classList.contains( 'yk-card' ) ) {
					// initCards takes a scope; a card is its own scope here.
					window.ykWcgvArchive.initCards( {
						querySelectorAll: function ( sel ) {
							return node.matches( sel ) ? [ node ] : node.querySelectorAll( sel );
						},
					} );
				} else {
					window.ykWcgvArchive.initCards( node );
				}
			} );
		}

		currentPage = parseInt( data.current_page, 10 ) || currentPage + 1;
		maxPages    = parseInt( data.max_pages, 10 ) || maxPages;

		updateUrl();

		if ( ! data.has_more || currentPage >= maxPages ) {
			finish();
			return;
		}

		setStatus( '' );
	}

	/**
	 * Keep the address bar in step so a reload — or the back button after visiting a
	 * product — lands on the page the shopper was actually looking at.
	 */
	function updateUrl() {
		if ( ! window.history || ! window.history.replaceState ) {
			return;
		}

		try {
			const url = new URL( window.location.href );
			if ( currentPage > 1 ) {
				url.searchParams.set( 'paged', String( currentPage ) );
			} else {
				url.searchParams.delete( 'paged' );
			}
			window.history.replaceState( { ykPage: currentPage }, '', url.toString() );
		} catch ( e ) {
			// Malformed URL — not worth breaking the scroll over.
		}
	}

	function finish() {
		if ( observer ) {
			observer.disconnect();
			observer = null;
		}
		if ( sentinel ) {
			sentinel.remove();
			sentinel = null;
		}
		setStatus( i18n.all_loaded || '' );
	}

	function onFailure() {
		failed = true;

		if ( observer ) {
			observer.disconnect();
			observer = null;
		}

		setStatus( i18n.load_error || '' );

		const retry = document.createElement( 'button' );
		retry.type        = 'button';
		retry.className   = 'yk-infinite-retry yk-btn yk-btn--outline';
		retry.textContent = i18n.retry || '';
		retry.addEventListener( 'click', function () {
			retry.remove();
			failed = false;
			setStatus( '' );
			observe();
			loadNext();
		} );

		status.appendChild( document.createTextNode( ' ' ) );
		status.appendChild( retry );

		// Give the shopper a way forward even if the retry never works.
		showPagination();
	}

	function setStatus( message ) {
		if ( ! status ) {
			return;
		}
		status.textContent = message;
	}

	// ── Start after the first paint ──────────────────────────────────────────

	// The script is deferred, so DOM parsing is done; the extra frame keeps observer setup
	// out of the critical rendering path — page 1 must load exactly as fast as before.
	if ( 'requestIdleCallback' in window ) {
		window.requestIdleCallback( init, { timeout: 1000 } );
	} else {
		window.setTimeout( init, 0 );
	}
} )();
