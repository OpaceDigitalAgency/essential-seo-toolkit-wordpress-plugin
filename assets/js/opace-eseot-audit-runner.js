/**
 * Opace Essential SEO Toolkit: front-end audit runner.
 *
 * Runs inside the audited WordPress page (loaded with the audit query args by a
 * capable, logged-in user) and posts the results to the admin panel that opened
 * it, either as a same-origin iframe (window.parent) or as a new tab
 * (window.opener). It is a port of the page-side functions of the Chrome
 * extension: collectPageSignals, collectAxeViolations and
 * collectCurrentVisitVitals. Nothing leaves the site; the only network
 * activity is the page's own.
 *
 * Expects window.opaceEseotAudit = { origin, targetUrl, timeoutMs, expectedPostId, engines }
 * and the vendor globals window.webVitals (head) and window.axe (footer).
 */
( function ( window, document ) {
	'use strict';

	var TAG = '[Opace ESEOT audit]';
	var config = window.opaceEseotAudit;

	// Run once, only when configured and only when there is somewhere to post to.
	if ( ! config || typeof config !== 'object' ) {
		return;
	}
	if ( window.__opaceEseotAuditRan ) {
		return;
	}
	window.__opaceEseotAuditRan = true;

	var framed = window.parent !== window;
	var target = framed ? window.parent : window.opener;
	if ( ! target ) {
		if ( window.console && console.info ) {
			console.info( TAG + ' No admin window to report to; audit not run.' );
		}
		return;
	}

	var origin = typeof config.origin === 'string' && config.origin ? config.origin : window.location.origin;
	var engines = config.engines && typeof config.engines === 'object' ? config.engines : {};
	var axeEnabled = engines.axe !== false;
	var vitalsEnabled = engines.vitals !== false;
	var timeoutMs = Number( config.timeoutMs ) > 0 ? Number( config.timeoutMs ) : 15000;
	var startedAt = Date.now();
	var posted = false;

	// Sections fill in as each stage finishes; the watchdog posts whatever exists.
	var sections = {
		page: { status: 'error', message: 'Page signals were not collected before the audit timed out.' },
		accessibility: { status: 'error', violations: [], passes: 0, incomplete: 0, message: 'The accessibility engine did not finish before the audit timed out.' },
		vitals: { status: 'error', metrics: {}, message: 'Web Vitals did not finish before the audit timed out.' }
	};

	function send( message ) {
		try {
			// JSON round trip guarantees a structured-clone-safe, DOM-free payload.
			target.postMessage( JSON.parse( JSON.stringify( message ) ), origin );
		} catch ( error ) {
			if ( window.console && console.warn ) {
				console.warn( TAG + ' postMessage failed: ' + ( error && error.message ? error.message : error ) );
			}
		}
	}

	function progress( stage, message ) {
		send( { type: 'opace-eseot-audit-progress', stage: stage, message: message } );
	}

	function postResult() {
		if ( posted ) {
			return;
		}
		posted = true;
		window.clearTimeout( watchdog );
		send( {
			type: 'opace-eseot-audit-result',
			version: 1,
			url: window.location.href,
			targetUrl: typeof config.targetUrl === 'string' ? config.targetUrl : '',
			expectedPostId: Number( config.expectedPostId ) || 0,
			page: sections.page,
			accessibility: sections.accessibility,
			vitals: sections.vitals,
			timing: { startedAt: startedAt, finishedAt: Date.now() }
		} );
		if ( ! framed ) {
			closeTabOrExplain();
		}
	}

	var watchdog = window.setTimeout( postResult, timeoutMs );

	/* ------------------------------------------------------------------ */
	/* WordPress adjustments                                              */
	/* ------------------------------------------------------------------ */

	// Logged-in chrome must not count towards the page's own signals.
	function isIgnored( element ) {
		if ( ! element || typeof element.closest !== 'function' ) {
			return false;
		}
		return !! ( element.closest( '#wpadminbar' ) || element.closest( '[data-opace-eseot-ignore]' ) );
	}

	function ignoredRoots() {
		var roots = [];
		var bar = document.getElementById( 'wpadminbar' );
		if ( bar ) {
			roots.push( bar );
		}
		Array.prototype.forEach.call( document.querySelectorAll( '[data-opace-eseot-ignore]' ), function ( element ) {
			if ( roots.indexOf( element ) === -1 && ! ( bar && bar.contains( element ) ) ) {
				roots.push( element );
			}
		} );
		return roots;
	}

	// innerText of the body minus the innerText of each ignored block. A block's
	// rendered text appears contiguously inside its ancestor's, so a single
	// substring removal is exact in practice; if it is not found, it is left in.
	function visibleBodyText() {
		var text = document.body ? document.body.innerText : '';
		ignoredRoots().forEach( function ( root ) {
			var own = ( root.innerText || '' ).trim();
			if ( own && text.indexOf( own ) !== -1 ) {
				text = text.replace( own, ' ' );
			}
		} );
		return text.trim().replace( /\s+/g, ' ' );
	}

	/* ------------------------------------------------------------------ */
	/* Stage 1: page signals (port of collectPageSignals)                 */
	/* ------------------------------------------------------------------ */

	function collectPageSignals() {
		function content( selector ) {
			var element = document.querySelector( selector );
			return element ? ( element.getAttribute( 'content' ) || '' ).trim() : '';
		}
		function href( selector ) {
			var element = document.querySelector( selector );
			return element ? ( element.href || element.getAttribute( 'href' ) || '' ).trim() : '';
		}
		function clean( value, limit ) {
			return String( value || '' ).trim().replace( /\s+/g, ' ' ).substring( 0, limit || 240 );
		}
		function unique( values ) {
			return Array.from( new Set( values ) );
		}
		function wordTokens( value ) {
			var stopWords = new Set( [
				'about', 'after', 'again', 'against', 'also', 'among', 'and', 'any', 'are', 'because', 'been', 'before', 'being', 'between',
				'both', 'but', 'can', 'could', 'did', 'does', 'doing', 'each', 'few', 'for', 'from', 'further', 'had', 'has', 'have', 'having',
				'here', 'how', 'into', 'its', 'itself', 'just', 'more', 'most', 'not', 'now', 'only', 'other', 'our', 'ours', 'out', 'over',
				'own', 'same', 'should', 'some', 'such', 'than', 'that', 'the', 'their', 'them', 'then', 'there', 'these', 'they', 'this',
				'those', 'through', 'too', 'under', 'until', 'very', 'was', 'were', 'what', 'when', 'where', 'which', 'while', 'who', 'why',
				'will', 'with', 'would', 'you', 'your', 'yours', 'www', 'com', 'cookie', 'cookies', 'privacy'
			] );
			var matches = clean( value, 250000 ).toLocaleLowerCase().match( /[\p{L}\p{N}][\p{L}\p{N}'’-]{2,}/gu ) || [];
			return matches.filter( function ( word ) { return ! stopWords.has( word ) && ! /^\d+$/.test( word ); } );
		}
		function rankTerms( tokens, size, limit ) {
			var counts = {};
			for ( var index = 0; index <= tokens.length - size; index += 1 ) {
				var term = tokens.slice( index, index + size ).join( ' ' );
				counts[ term ] = ( counts[ term ] || 0 ) + 1;
			}
			return Object.keys( counts )
				.filter( function ( term ) { return counts[ term ] > ( size === 1 ? 1 : 0 ); } )
				.sort( function ( a, b ) { return counts[ b ] - counts[ a ] || a.localeCompare( b ); } )
				.slice( 0, limit )
				.map( function ( term ) { return { term: term, count: counts[ term ], size: size }; } );
		}
		function collectOptionalAccessibilitySignals() {
			// Stable boundary kept from Chrome; the axe section carries the real results.
			return { available: false, engine: null, issueCount: null };
		}
		function notIgnored( element ) {
			return ! isIgnored( element );
		}

		var headings = Array.from( document.querySelectorAll( 'h1, h2, h3, h4, h5, h6' ) ).filter( notIgnored ).map( function ( heading ) {
			return { level: Number( heading.tagName.substring( 1 ) ), text: ( heading.textContent || '' ).trim().replace( /\s+/g, ' ' ).substring( 0, 180 ) };
		} );
		var headingJumps = 0;
		headings.forEach( function ( heading, index ) {
			if ( index > 0 && heading.level > headings[ index - 1 ].level + 1 ) headingJumps += 1;
		} );
		var images = Array.from( document.images ).filter( notIgnored );
		var missingAltImages = images.filter( function ( image ) { return ! image.hasAttribute( 'alt' ); } );
		var links = Array.from( document.querySelectorAll( 'a[href]' ) ).filter( notIgnored );
		var internalLinks = [];
		var externalLinks = [];
		var nofollowLinks = 0;
		links.forEach( function ( link ) {
			try {
				var linkUrl = new URL( link.href, location.href );
				if ( ! /^https?:$/.test( linkUrl.protocol ) ) return;
				var item = { text: clean( link.textContent, 80 ) || '(no link text)', url: linkUrl.href.substring( 0, 500 ) };
				if ( linkUrl.hostname === location.hostname ) internalLinks.push( item );
				else externalLinks.push( item );
				if ( ( link.rel || '' ).split( /\s+/ ).includes( 'nofollow' ) ) nofollowLinks += 1;
			} catch ( _error ) { return; }
		} );
		var schemaTypes = [];
		function collectSchemaTypes( item ) {
			if ( Array.isArray( item ) ) {
				item.forEach( collectSchemaTypes );
				return;
			}
			if ( ! item || typeof item !== 'object' ) return;
			if ( item['@type'] ) {
				var types = Array.isArray( item['@type'] ) ? item['@type'] : [ item['@type'] ];
				schemaTypes = schemaTypes.concat( types.map( String ) );
			}
			if ( item['@graph'] ) collectSchemaTypes( item['@graph'] );
		}
		var schemaScripts = Array.from( document.querySelectorAll( 'script[type="application/ld+json"]' ) ).filter( notIgnored );
		schemaScripts.forEach( function ( script ) {
			try {
				collectSchemaTypes( JSON.parse( script.textContent ) );
			} catch ( _error ) { return; }
		} );
		var visibleText = visibleBodyText();
		var tokens = wordTokens( visibleText );
		var title = ( document.title || '' ).trim();
		var description = content( 'meta[name="description" i]' );
		var h1Text = headings.filter( function ( heading ) { return heading.level === 1; } ).map( function ( heading ) { return heading.text; } ).join( ' ' );
		var navigationEntry = performance.getEntriesByType( 'navigation' )[0] || null;
		var resourceEntries = performance.getEntriesByType( 'resource' ) || [];
		var thirdPartyHosts = unique( resourceEntries.map( function ( entry ) {
			try {
				var resourceUrl = new URL( entry.name, location.href );
				return resourceUrl.hostname !== location.hostname ? resourceUrl.hostname : '';
			} catch ( _error ) { return ''; }
		} ).filter( Boolean ) );
		return {
			status: 'ok',
			url: location.href,
			protocol: location.protocol,
			title: title,
			description: description,
			canonical: href( 'link[rel="canonical" i]' ),
			robots: content( 'meta[name="robots" i]' ),
			language: ( document.documentElement.lang || '' ).trim(),
			viewport: content( 'meta[name="viewport" i]' ),
			h1: headings.filter( function ( heading ) { return heading.level === 1; } ),
			headings: headings.slice( 0, 60 ),
			headingCount: headings.length,
			headingJumps: headingJumps,
			imageCount: images.length,
			missingAltCount: missingAltImages.length,
			missingAltSamples: missingAltImages.slice( 0, 5 ).map( function ( image ) {
				return clean( image.currentSrc || image.src || image.getAttribute( 'src' ), 180 ) || '(image source unavailable)';
			} ),
			internalLinks: internalLinks.length,
			externalLinks: externalLinks.length,
			nofollowLinks: nofollowLinks,
			internalLinkSamples: internalLinks.slice( 0, 5 ),
			externalLinkSamples: externalLinks.slice( 0, 5 ),
			wordCount: visibleText ? visibleText.split( /\s+/ ).length : 0,
			topTerms: rankTerms( tokens, 1, 8 ),
			topPhrases: rankTerms( tokens, 2, 6 ),
			termSets: { title: unique( wordTokens( title ) ), h1: unique( wordTokens( h1Text ) ), description: unique( wordTokens( description ) ) },
			schemaCount: schemaScripts.length,
			schemaTypes: Array.from( new Set( schemaTypes ) ).slice( 0, 20 ),
			openGraphTitle: content( 'meta[property="og:title" i]' ),
			openGraphDescription: content( 'meta[property="og:description" i]' ),
			openGraphImage: content( 'meta[property="og:image" i]' ),
			performance: {
				available: Boolean( navigationEntry ),
				responseStartMs: navigationEntry ? Math.round( navigationEntry.responseStart ) : null,
				domContentLoadedMs: navigationEntry ? Math.round( navigationEntry.domContentLoadedEventEnd ) : null,
				loadCompleteMs: navigationEntry ? Math.round( navigationEntry.loadEventEnd ) : null,
				transferBytes: resourceEntries.reduce( function ( total, entry ) { return total + ( entry.transferSize || 0 ); }, navigationEntry ? navigationEntry.transferSize || 0 : 0 ),
				decodedBytes: resourceEntries.reduce( function ( total, entry ) { return total + ( entry.decodedBodySize || 0 ); }, navigationEntry ? navigationEntry.decodedBodySize || 0 : 0 ),
				resourceCount: resourceEntries.length,
				thirdPartyHosts: thirdPartyHosts.slice( 0, 12 )
			},
			accessibility: collectOptionalAccessibilitySignals(),
			auditContext: {
				loggedIn: true,
				adminBarPresent: !! document.getElementById( 'wpadminbar' ),
				framed: framed
			}
		};
	}

	/* ------------------------------------------------------------------ */
	/* Stage 2: axe-core (port of collectAxeViolations)                   */
	/* ------------------------------------------------------------------ */

	function runAccessibility() {
		if ( ! axeEnabled ) {
			return Promise.resolve( { status: 'skipped', violations: [], passes: 0, incomplete: 0, message: 'The accessibility engine is switched off in the toolkit settings.' } );
		}
		var axe = window.axe;
		if ( ! axe || typeof axe.run !== 'function' ) {
			return Promise.resolve( { status: 'error', violations: [], passes: 0, incomplete: 0, message: 'The bundled axe-core engine did not initialise.' } );
		}
		var timeout = new Promise( function ( _resolve, reject ) {
			window.setTimeout( function () { reject( new Error( 'The page audit exceeded 8 seconds.' ) ); }, 8000 );
		} );
		var context = { include: [ [ 'html' ] ], exclude: [ [ '#wpadminbar' ], [ '[data-opace-eseot-ignore]' ] ] };
		var options = { iframes: false, resultTypes: [ 'violations' ] };
		return Promise.race( [ axe.run( context, options ), timeout ] ).then( function ( audit ) {
			return {
				status: 'ok',
				version: axe.version || '',
				violations: ( audit.violations || [] ).slice( 0, 25 ).map( function ( violation ) {
					return {
						id: violation.id,
						impact: violation.impact || 'unknown',
						help: violation.help,
						description: violation.description || '',
						helpUrl: violation.helpUrl || '',
						nodes: ( violation.nodes || [] ).length,
						sample: ( violation.nodes || [] ).slice( 0, 3 ).map( function ( node ) {
							return ( node.target || [] ).map( String ).join( ' ' ).substring( 0, 200 );
						} )
					};
				} ),
				violationCount: ( audit.violations || [] ).length,
				passes: ( audit.passes || [] ).length,
				incomplete: ( audit.incomplete || [] ).length
			};
		} ).catch( function ( error ) {
			return { status: 'error', violations: [], passes: 0, incomplete: 0, message: error && error.message ? error.message : 'axe-core could not audit this page.' };
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Stage 3: Web Vitals (port of collectCurrentVisitVitals)            */
	/* ------------------------------------------------------------------ */

	function runVitals() {
		if ( ! vitalsEnabled ) {
			return Promise.resolve( { status: 'skipped', metrics: {}, message: 'Web Vitals capture is switched off in the toolkit settings.' } );
		}
		var library = window.webVitals;
		if ( ! library || typeof library.onLCP !== 'function' ) {
			return Promise.resolve( { status: 'error', metrics: {}, message: 'The bundled Web Vitals engine did not initialise.' } );
		}
		return new Promise( function ( resolve ) {
			var metrics = {};
			function record( metric ) {
				// Last report wins, as in Chrome.
				metrics[ metric.name ] = { value: metric.value, rating: metric.rating || 'unrated' };
			}
			try {
				library.onCLS( record, { reportAllChanges: true } );
				library.onFCP( record, { reportAllChanges: true } );
				library.onINP( record, { reportAllChanges: true } );
				library.onLCP( record, { reportAllChanges: true } );
				library.onTTFB( record, { reportAllChanges: true } );
			} catch ( error ) {
				resolve( { status: 'error', metrics: {}, message: error && error.message ? error.message : 'Web Vitals could not inspect this page load.' } );
				return;
			}
			window.setTimeout( function () {
				var names = [ 'LCP', 'INP', 'CLS', 'FCP', 'TTFB' ];
				var captured = names.filter( function ( name ) { return !! metrics[ name ]; } );
				var result = {
					status: captured.length === names.length ? 'ok' : 'partial',
					version: '6.2.1',
					metrics: metrics
				};
				if ( captured.length === 0 ) {
					result.status = 'error';
					result.message = 'No browser metrics were reported for this visit.';
				} else if ( framed ) {
					result.note = 'Measured from the audit frame inside the editor, not from a real visit. Metrics can differ from what visitors experience.';
				}
				resolve( result );
			}, 2500 );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* New-tab path                                                       */
	/* ------------------------------------------------------------------ */

	function closeTabOrExplain() {
		window.setTimeout( function () {
			try {
				window.close();
			} catch ( _error ) {
				// Fall through to the banner below.
			}
			window.setTimeout( function () {
				if ( window.closed || document.getElementById( 'opace-eseot-runner-banner' ) ) {
					return;
				}
				var banner = document.createElement( 'div' );
				banner.id = 'opace-eseot-runner-banner';
				banner.setAttribute( 'role', 'status' );
				banner.setAttribute( 'data-opace-eseot-ignore', '' );
				banner.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:2147483647;' +
					'padding:12px 18px;border-radius:8px;background:#0b2545;color:#fff;font:14px/1.4 -apple-system,BlinkMacSystemFont,' +
					'"Segoe UI",Roboto,sans-serif;box-shadow:0 6px 24px rgba(0,0,0,.25);max-width:calc(100% - 32px);';
				banner.textContent = 'Audit sent. You can close this tab.';
				( document.body || document.documentElement ).appendChild( banner );
			}, 300 );
		}, 500 );
	}

	/* ------------------------------------------------------------------ */
	/* Orchestration                                                      */
	/* ------------------------------------------------------------------ */

	function whenLoaded() {
		return new Promise( function ( resolve ) {
			if ( document.readyState === 'complete' ) {
				resolve();
				return;
			}
			window.addEventListener( 'load', function () { resolve(); }, { once: true } );
		} );
	}

	function delay( ms ) {
		return new Promise( function ( resolve ) { window.setTimeout( resolve, ms ); } );
	}

	function run() {
		// A macrotask after load so loadEventEnd is populated, as when the Chrome popup opens.
		return whenLoaded().then( function () { return delay( 0 ); } ).then( function () {
			progress( 'signals', 'Auditing this page locally…' );
			try {
				sections.page = collectPageSignals();
			} catch ( error ) {
				sections.page = { status: 'error', message: error && error.message ? error.message : 'The page blocked the local audit.' };
			}

			// Same stagger as Chrome: vitals at +100 ms, axe at +650 ms, run independently.
			var vitals = delay( 100 ).then( function () {
				progress( 'vitals', 'Capturing this visit for up to 3 seconds…' );
				return runVitals();
			} ).then( function ( result ) { sections.vitals = result; } );

			var accessibility = delay( 650 ).then( function () {
				progress( 'accessibility', 'Running axe-core locally…' );
				return runAccessibility();
			} ).then( function ( result ) { sections.accessibility = result; } );

			return Promise.all( [ vitals, accessibility ] );
		} ).then( postResult, function ( error ) {
			// Should not happen (each stage catches its own errors), but never leave the panel waiting.
			if ( sections.page.status !== 'ok' ) {
				sections.page = { status: 'error', message: error && error.message ? error.message : 'The current page could not be audited.' };
			}
			postResult();
		} );
	}

	try {
		run();
	} catch ( error ) {
		sections.page = { status: 'error', message: error && error.message ? error.message : 'The current page could not be audited.' };
		postResult();
	}
} )( window, document );
