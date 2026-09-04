/**
 * Opace Essential SEO Toolkit: page audit panel (editor meta box, Tools page, bulk audits).
 * Port of the Chrome v5 popup rendering. PHP renders only the .opace-eseot-audit container;
 * this script builds the UI, drives the front-end runner in a hidden same-origin iframe,
 * calls the crawl and summary REST endpoints and renders every tab.
 * Vanilla ES2017, no build step. Payload strings are untrusted and only ever set via textContent.
 */
( function () {
	'use strict';

	var W = window;
	var D = document;
	var L = W.opaceEseotAuditL10n || {};
	var C = W.opaceEseotAuditConfig || {};
	var P = 'opace-eseot-audit__';
	var SCRIPT_SRC = ( D.currentScript && D.currentScript.src ) || '';
	var TIMEOUT_MS = 20000;
	var TAB_TIMEOUT_MS = 90000;
	var VITALS = [ 'LCP', 'INP', 'CLS', 'FCP', 'TTFB' ];
	var IMPACTS = [ 'critical', 'serious', 'moderate', 'minor', 'unknown' ];
	var GROUPS = [
		[ 'search', 'Search appearance', 'Title, description and main heading as search engines read them.' ],
		[ 'indexing', 'Indexing & delivery', 'Canonical address, indexing directives and transport.' ],
		[ 'content', 'Content & accessibility', 'Language, viewport, images, heading order and visible text.' ],
		[ 'markup', 'Markup & navigation', 'Structured data, social metadata and links.' ]
	];
	var ENGINE_DESC = { Accessibility: 'axe-core rules run inside the audited page.', Performance: 'Web Vitals captured during this audit visit.' };
	var BUILT_IN = {
		'Google PageSpeed Insights': 'Performance and Core Web Vitals',
		'Google Rich Results Test': 'Google-supported structured data',
		'Schema Markup Validator': 'Schema.org vocabulary validation',
		'WAVE Accessibility Evaluation': 'Automated accessibility checks',
		'Security Headers': 'HTTP response security headers',
		'Google Admin Toolbox Dig': 'Live DNS A-record lookup'
	};
	var SHORT = { 'Google PageSpeed Insights': 'PageSpeed', 'Google Rich Results Test': 'Rich Results', 'Schema Markup Validator': 'Schema Validator', 'WAVE Accessibility Evaluation': 'WAVE' };
	var A11Y = {
		'region': [ 'Landmarks help screen-reader users understand and move around a page.', 'Place the page’s primary content inside a <main> element or another clearly labelled landmark.' ],
		'landmark-one-main': [ 'A single main landmark tells assistive technology where the page’s central content begins.', 'Add one <main> element around the primary page content.' ],
		'image-alt': [ 'Useful alternative text lets people understand meaningful images when they cannot see them.', 'Add concise alt text to meaningful images; use an empty alt attribute for purely decorative images.' ],
		'button-name': [ 'Buttons need a clear accessible name so their purpose is announced.', 'Add visible text or an aria-label that describes what each button does.' ],
		'link-name': [ 'Links need meaningful names so users know where they go.', 'Add descriptive link text or an accessible label; avoid empty and vague links.' ],
		'color-contrast': [ 'Low contrast can make text unreadable for people with low vision.', 'Increase the contrast between the affected text and its background, then verify it again.' ],
		'label': [ 'Form controls need labels so their purpose is announced.', 'Connect a visible label to each input, or add an equivalent accessible name.' ]
	};
	var VITAL_LABEL = { LCP: 'Main content paint (LCP)', INP: 'Interaction response (INP)', CLS: 'Visual stability (CLS)', FCP: 'First visible content (FCP)', TTFB: 'Server response (TTFB)' };
	var uidCounter = 0;

	/* ---------- helpers ---------- */

	function tx( key, fallback ) {
		var nested = L.statuses && L.statuses[ key ];
		return typeof L[ key ] === 'string' ? L[ key ] : ( typeof nested === 'string' ? nested : fallback );
	}

	function tabTx( id, fallback ) {
		return L.tabs && typeof L.tabs[ id ] === 'string' ? L.tabs[ id ] : fallback;
	}

	function el( tag, cls, text ) {
		var node = D.createElement( tag );
		if ( cls ) {
			node.className = cls.split( ' ' ).map( function ( c ) { return P + c; } ).join( ' ' );
		}
		if ( text !== undefined && text !== null ) {
			node.textContent = String( text );
		}
		return node;
	}

	function add( parent ) {
		for ( var i = 1; i < arguments.length; i += 1 ) {
			if ( arguments[ i ] ) {
				parent.appendChild( typeof arguments[ i ] === 'string' ? D.createTextNode( arguments[ i ] ) : arguments[ i ] );
			}
		}
		return parent;
	}

	function button( cls, text, onClick ) {
		var b = el( 'button', cls, text );
		b.type = 'button';
		if ( onClick ) {
			b.addEventListener( 'click', onClick );
		}
		return b;
	}

	function link( cls, href, text ) {
		var a = el( 'a', cls, text );
		a.target = '_blank';
		a.rel = 'noopener noreferrer';
		if ( href ) {
			a.href = href;
		} else {
			a.href = '#';
			a.setAttribute( 'aria-disabled', 'true' );
			a.addEventListener( 'click', function ( e ) { e.preventDefault(); } );
		}
		return a;
	}

	function str( value, limit ) {
		if ( value === null || value === undefined ) {
			return '';
		}
		return String( value ).trim().replace( /\s+/g, ' ' ).slice( 0, limit || 2000 );
	}

	function num( value ) {
		var n = Number( value );
		return Number.isFinite( n ) ? n : 0;
	}

	function safeUrl( value ) {
		try { return new URL( value ); } catch ( e ) { return null; }
	}

	/* The runner reports location.href, which carries the activation args; strip them so links and previews show the public URL. */
	function cleanUrl( value ) {
		var parsed = safeUrl( value );
		if ( ! parsed ) { return value; }
		[ 'opace_eseot_audit', '_wpnonce', 'opace_eseot_post' ].forEach( function ( key ) { parsed.searchParams.delete( key ); } );
		return parsed.href;
	}

	function plural( n, one, many ) {
		return n + ' ' + ( n === 1 ? one : many );
	}

	function fmt( template, value ) {
		return template.indexOf( '%s' ) === -1 ? template + ' ' + value : template.replace( '%s', value );
	}

	function timeAgo( iso ) {
		var then = Date.parse( iso );
		if ( ! Number.isFinite( then ) ) {
			return str( iso, 40 );
		}
		var minutes = Math.round( ( Date.now() - then ) / 60000 );
		if ( minutes < 1 ) { return 'just now'; }
		if ( minutes < 60 ) { return plural( minutes, 'minute ago', 'minutes ago' ); }
		var hours = Math.round( minutes / 60 );
		if ( hours < 24 ) { return plural( hours, 'hour ago', 'hours ago' ); }
		return plural( Math.round( hours / 24 ), 'day ago', 'days ago' );
	}

	function pluginUrl() {
		if ( C.pluginUrl ) {
			return String( C.pluginUrl ).replace( /\/?$/, '/' );
		}
		var match = SCRIPT_SRC.match( /^(.*\/)assets\/js\/[^/]*$/ );
		return match ? match[ 1 ] : '';
	}

	function sourcesMap() {
		var map = {};
		var list = Array.isArray( C.sources ) ? C.sources : Object.keys( C.sources || {} ).map( function ( name ) {
			return Object.assign( { name: name }, C.sources[ name ] );
		} );
		list.forEach( function ( item ) {
			var name = str( item && item.name, 80 );
			if ( name && typeof item.url === 'string' ) {
				map[ name ] = { url: item.url, cat: String( item.cat === undefined ? '' : item.cat ), note: str( item.note, 120 ) };
			}
		} );
		return map;
	}

	function categoryList() {
		var raw = C.categories || {};
		if ( Array.isArray( raw ) ) {
			return raw.map( function ( c ) { return { id: String( c.id ), name: str( c.name, 60 ) }; } );
		}
		return Object.keys( raw ).map( function ( id ) {
			var value = raw[ id ];
			return value && typeof value === 'object' ? { id: String( value.id || id ), name: str( value.name, 60 ) } : { id: id, name: str( value, 60 ) };
		} );
	}

	function resolveTool( template, url ) {
		var resolver = W.opaceEseotResolver;
		return resolver && typeof resolver.resolve === 'function' ? resolver.resolve( template, url ) : null;
	}

	function copyText( text ) {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			return navigator.clipboard.writeText( text ).catch( function () { return fallbackCopy( text ); } );
		}
		return fallbackCopy( text );
	}

	function fallbackCopy( text ) {
		var area = D.createElement( 'textarea' );
		area.value = text;
		area.setAttribute( 'readonly', '' );
		area.style.position = 'fixed';
		area.style.opacity = '0';
		D.body.appendChild( area );
		area.select();
		try { D.execCommand( 'copy' ); } catch ( e ) { /* nothing to fall back to */ }
		area.remove();
		return Promise.resolve();
	}

	function flash( node, text ) {
		var original = node.textContent;
		node.textContent = text;
		setTimeout( function () { node.textContent = original; }, 1400 );
	}

	function formatMs( value ) {
		return value === null || value <= 0 ? 'Unavailable' : value.toLocaleString() + ' ms';
	}

	function formatBytes( value ) {
		if ( value === null || value <= 0 ) { return 'Unavailable'; }
		if ( value >= 1048576 ) { return ( value / 1048576 ).toFixed( 1 ) + ' MB'; }
		return Math.round( value / 1024 ).toLocaleString() + ' KB';
	}

	function formatVital( name, value ) {
		if ( typeof value !== 'number' || ! Number.isFinite( value ) ) { return 'Unavailable'; }
		return name === 'CLS' ? value.toFixed( 3 ) : Math.round( value ).toLocaleString() + ' ms';
	}

	function ratingCopy( rating ) {
		return { good: 'Good in this visit', 'needs-improvement': 'Worth checking', poor: 'Slow in this visit' }[ rating ] || 'Not rated';
	}

	function intersection( a, b ) {
		return ( a || [] ).filter( function ( v ) { return ( b || [] ).indexOf( v ) !== -1; } );
	}

	/* ---------- payload normalisation (everything from the runner is untrusted) ---------- */

	function strList( list, count, length ) {
		return Array.isArray( list ) ? list.slice( 0, count ).map( function ( v ) { return str( v, length ); } ) : [];
	}

	function normalisePage( raw, fallbackUrl ) {
		raw = raw && typeof raw === 'object' ? raw : {};
		var perf = raw.performance && typeof raw.performance === 'object' ? raw.performance : {};
		var sets = raw.termSets && typeof raw.termSets === 'object' ? raw.termSets : {};
		var headings = ( Array.isArray( raw.headings ) ? raw.headings.slice( 0, 300 ) : [] ).map( function ( h ) {
			return { level: Math.min( 6, Math.max( 1, num( h && h.level ) || 1 ) ), text: str( h && h.text, 180 ) };
		} );
		var links = function ( list ) {
			return ( Array.isArray( list ) ? list.slice( 0, 5 ) : [] ).map( function ( l ) { return { text: str( l && l.text, 80 ), url: str( l && l.url, 500 ) }; } );
		};
		var terms = function ( list ) {
			return ( Array.isArray( list ) ? list.slice( 0, 12 ) : [] ).map( function ( t ) { return { term: str( t && t.term, 80 ), count: num( t && t.count ) }; } );
		};
		var opt = function ( v ) { return v === null || v === undefined ? null : num( v ); };
		var url = cleanUrl( str( raw.url, 2000 ) || fallbackUrl );
		var parsed = safeUrl( url );
		return {
			url: url,
			protocol: str( raw.protocol, 10 ) || ( parsed ? parsed.protocol : '' ),
			title: str( raw.title, 1000 ),
			description: str( raw.description, 2000 ),
			canonical: str( raw.canonical, 2000 ),
			robots: str( raw.robots, 200 ),
			language: str( raw.language, 40 ),
			viewport: str( raw.viewport, 200 ),
			headings: headings,
			h1: headings.filter( function ( h ) { return h.level === 1; } ),
			headingJumps: num( raw.headingJumps ),
			imageCount: num( raw.imageCount ),
			missingAltCount: num( raw.missingAltCount ),
			missingAltSamples: strList( raw.missingAltSamples, 5, 180 ),
			internalLinks: num( raw.internalLinks ),
			externalLinks: num( raw.externalLinks ),
			nofollowLinks: num( raw.nofollowLinks ),
			internalLinkSamples: links( raw.internalLinkSamples ),
			externalLinkSamples: links( raw.externalLinkSamples ),
			wordCount: num( raw.wordCount ),
			topTerms: terms( raw.topTerms ),
			topPhrases: terms( raw.topPhrases ),
			termSets: { title: strList( sets.title, 300, 80 ), h1: strList( sets.h1, 300, 80 ), description: strList( sets.description, 300, 80 ) },
			schemaCount: num( raw.schemaCount ),
			schemaTypes: strList( raw.schemaTypes, 30, 80 ),
			openGraphTitle: str( raw.openGraphTitle, 500 ),
			openGraphDescription: str( raw.openGraphDescription, 1000 ),
			openGraphImage: str( raw.openGraphImage, 1000 ),
			performance: {
				available: Boolean( perf.available ),
				responseStartMs: opt( perf.responseStartMs ),
				domContentLoadedMs: opt( perf.domContentLoadedMs ),
				loadCompleteMs: opt( perf.loadCompleteMs ),
				transferBytes: opt( perf.transferBytes ),
				decodedBytes: opt( perf.decodedBytes ),
				resourceCount: opt( perf.resourceCount ),
				thirdPartyHosts: strList( perf.thirdPartyHosts, 12, 120 )
			}
		};
	}

	function normaliseAxe( raw ) {
		raw = raw && typeof raw === 'object' ? raw : {};
		var status = str( raw.status, 20 ) || ( raw.available === false ? 'error' : 'ok' );
		return {
			status: status,
			message: str( raw.message || raw.error, 300 ),
			version: str( raw.version, 20 ),
			passes: num( raw.passes ),
			incomplete: num( raw.incomplete ),
			violations: ( Array.isArray( raw.violations ) ? raw.violations.slice( 0, 100 ) : [] ).map( function ( v ) {
				v = v && typeof v === 'object' ? v : {};
				var impact = str( v.impact, 20 );
				return {
					id: str( v.id, 80 ),
					impact: IMPACTS.indexOf( impact ) === -1 ? 'unknown' : impact,
					help: str( v.help, 300 ),
					description: str( v.description, 500 ),
					nodes: num( v.nodes !== undefined ? v.nodes : v.nodeCount ),
					sample: strList( v.sample || v.targets, 3, 300 )
				};
			} )
		};
	}

	function normaliseVitals( raw ) {
		raw = raw && typeof raw === 'object' ? raw : {};
		var metrics = {};
		var source = raw.metrics && typeof raw.metrics === 'object' ? raw.metrics : {};
		VITALS.forEach( function ( name ) {
			var m = source[ name ];
			if ( m && typeof m === 'object' && Number.isFinite( Number( m.value ) ) ) {
				var rating = str( m.rating, 20 );
				metrics[ name ] = { value: Number( m.value ), rating: [ 'good', 'needs-improvement', 'poor' ].indexOf( rating ) === -1 ? 'unrated' : rating };
			}
		} );
		return { status: str( raw.status, 20 ) || ( raw.available === false ? 'error' : 'ok' ), message: str( raw.message || raw.error || raw.note, 300 ), metrics: metrics, framed: Boolean( raw.framed ) };
	}

	/* ---------- checks (spec §3) ---------- */

	function check( label, status, value, category, summary, why, fix, tools ) {
		return { label: label, status: status, value: value, category: category, summary: summary, why: why, fix: fix, tools: tools || [] };
	}

	function lengthCheck( label, value, minimum, maximum, category, why, fix ) {
		if ( ! value ) {
			return check( label, 'review', 'Missing', category, label + ' is missing.', why, fix );
		}
		var length = value.length;
		var summary = length < minimum ? 'The wording may be too brief to communicate the page clearly.' : length > maximum ? 'The wording may be truncated in some displays.' : 'The length sits within a practical review range.';
		return check( label, length >= minimum && length <= maximum ? 'pass' : 'review', length + ' characters', category, summary, why, fix );
	}

	function buildChecks( page ) {
		var robots = page.robots.toLowerCase();
		var noindex = robots.indexOf( 'noindex' ) !== -1;
		var canonicalValid = Boolean( safeUrl( page.canonical ) );
		var https = page.protocol === 'https:';
		var socialCount = [ page.openGraphTitle, page.openGraphDescription, page.openGraphImage ].filter( Boolean ).length;
		var h1 = page.h1.length;
		var alt = page.missingAltCount;
		var jumps = page.headingJumps;
		var schema = page.schemaCount;
		return [
			lengthCheck( 'Page title', page.title, 15, 60, 'search', 'The title is a strong cue for search results and browser tabs.', 'Write a unique title that identifies the topic and purpose. Keep important wording near the start.' ),
			lengthCheck( 'Meta description', page.description, 70, 160, 'search', 'Search engines may use this text as the result snippet when it fits the query.', 'Summarise the page accurately and give the searcher a concrete reason to click.' ),
			check( 'H1 heading', h1 === 1 ? 'pass' : 'review', h1 + ' found', 'search', h1 === 1 ? 'One clear H1 is present.' : 'Review pages with no H1 or multiple H1 headings.', 'A clear main heading identifies the primary page topic.', 'Use one descriptive main heading, then organise supporting sections beneath it.' ),
			check( 'Canonical URL', canonicalValid ? 'pass' : 'review', canonicalValid ? page.canonical : 'Missing', 'indexing', canonicalValid ? 'A canonical link is declared.' : 'No canonical link was detected.', 'A canonical helps consolidate duplicate URLs around the preferred version.', 'Declare the absolute preferred URL and check that it returns 200 and is indexable.' ),
			check( 'Indexing directive', noindex ? 'review' : 'pass', page.robots || 'No noindex directive', 'indexing', noindex ? 'This page asks search engines not to index it.' : 'No page-level noindex directive was found.', 'A noindex directive normally prevents the page appearing in search results.', noindex ? 'Remove noindex only if this page is intended for public search. Also check X-Robots-Tag.' : 'No change is needed unless this page should be excluded.' ),
			check( 'HTTPS', https ? 'pass' : 'review', page.protocol.replace( ':', '' ).toUpperCase(), 'indexing', https ? 'The page uses HTTPS.' : 'The page is not using HTTPS.', 'HTTPS protects visitors and is expected for public pages.', 'Serve the canonical page over HTTPS, redirect HTTP and review response security headers.', [ 'Security Headers' ] ),
			check( 'Document language', page.language ? 'pass' : 'review', page.language || 'Missing', 'content', page.language ? 'A document language is declared.' : 'No html lang value was detected.', 'The language attribute helps assistive technology interpret the page.', 'Add the appropriate BCP 47 code to html, such as lang="en-GB".', [ 'WAVE Accessibility Evaluation' ] ),
			check( 'Mobile viewport', page.viewport ? 'pass' : 'review', page.viewport ? 'Present' : 'Missing', 'content', page.viewport ? 'A viewport directive is present.' : 'No responsive viewport directive was detected.', 'The viewport directive helps pages render correctly on mobile screens.', 'Add width=device-width and an initial scale, then test real mobile widths.', [ 'Google PageSpeed Insights' ] ),
			check( 'Image alt attributes', alt === 0 ? 'pass' : 'review', alt + ' missing of ' + page.imageCount, 'content', alt ? 'Some images have no alt attribute.' : 'Every image has an alt attribute; empty alt may be correct for decoration.', 'Alt text makes meaningful images understandable when they cannot be seen.', 'Describe meaningful images. Give decorative images an empty alt rather than omitting it.', [ 'WAVE Accessibility Evaluation' ] ),
			check( 'Heading order', jumps === 0 ? 'pass' : 'review', jumps + ' skipped level' + ( jumps === 1 ? '' : 's' ), 'content', jumps ? 'The outline skips one or more heading levels.' : 'No skipped heading levels were detected.', 'A logical outline lets readers and assistive technology scan the structure.', 'Nest headings by importance. Avoid jumps such as H2 directly to H4.', [ 'WAVE Accessibility Evaluation' ] ),
			check( 'Page content', 'info', page.wordCount.toLocaleString() + ' visible words', 'content', 'This is a descriptive count, not a quality target.', 'Visible text reveals the topics and supporting detail available to readers.', 'Use Insights to review repeated terms and title–heading alignment. Edit only to improve usefulness.' ),
			check( 'Structured data', 'info', schema ? page.schemaTypes.join( ', ' ) || schema + ' block(s)' : 'None detected', 'markup', schema ? schema + ' JSON-LD block(s) detected.' : 'No JSON-LD structured data was detected.', 'Accurate markup can help eligible content qualify for enhanced presentation.', schema ? 'Validate every detected type and confirm it describes visible content.' : 'Add schema only when a relevant type describes visible content.', [ 'Google Rich Results Test', 'Schema Markup Validator' ] ),
			check( 'Social metadata', socialCount === 3 ? 'pass' : 'info', socialCount + ' of 3 Open Graph fields', 'markup', socialCount === 3 ? 'Title, description and image are present.' : 'One or more Open Graph fields are absent.', 'Platforms may use Open Graph fields to build a shared-link preview.', 'Set a concise title and description plus an absolute, crawlable image URL.' ),
			check( 'Links', 'info', page.internalLinks + ' internal · ' + page.externalLinks + ' external', 'markup', page.nofollowLinks + ' link(s) use nofollow.', 'Links connect the page to related journeys and help crawlers discover resources.', 'Use descriptive anchor text and review nofollow values for the intended relationship.' )
		];
	}

	function summaryText( page, checks ) {
		var lines = [ 'Essential SEO Toolkit local audit', page.url, '' ];
		checks.forEach( function ( item ) {
			lines.push( item.status.toUpperCase() + ' · ' + item.label + ': ' + item.value );
			if ( item.status === 'review' ) {
				lines.push( '  Action: ' + item.fix );
			}
		} );
		lines.push( '', 'Automated checks are prompts for review, not ranking guarantees.' );
		return lines.join( '\n' );
	}

	/* ---------- shared building blocks ---------- */

	/* Only "Start here" carries the orange accent; every other eyebrow is muted. */
	function eyebrow( text, accent ) {
		return el( 'p', 'eyebrow' + ( accent ? ' eyebrow--accent' : '' ), text );
	}

	function sectionTitle( eyebrowText, title, sideNode, accent ) {
		var row = el( 'div', 'section-row' );
		var copy = add( el( 'div' ), eyebrow( eyebrowText, accent ), el( 'h3', 'h2', title ) );
		return add( row, copy, sideNode );
	}

	function markImage( cls ) {
		var mark = el( 'img', cls );
		mark.src = pluginUrl() + 'assets/images/opace-eseot-mark.svg';
		mark.alt = '';
		mark.width = 32;
		mark.height = 32;
		return mark;
	}

	/* Empty state: one dashicon and one sentence. Callers append the action button after it. */
	function emptyState( icon, text ) {
		var box = el( 'div', 'empty' );
		var glyph = D.createElement( 'span' );
		glyph.className = P + 'empty-icon dashicons dashicons-' + icon;
		glyph.setAttribute( 'aria-hidden', 'true' );
		return add( box, glyph, el( 'p', null, text ) );
	}

	function pageHost( url ) {
		var parsed = safeUrl( url );
		return parsed ? parsed.hostname.replace( /^www\./, '' ) : str( url, 80 );
	}

	/* Shared-link media area: the declared Open Graph image, or a 1200x630 placeholder. */
	function socialMedia( imageUrl ) {
		var media = el( 'div', 'social-media' );
		var missing = function ( text ) {
			media.textContent = '';
			media.classList.add( P + 'social-media--missing' );
			media.appendChild( el( 'span', 'social-media-label', text ) );
		};
		var parsed = imageUrl ? safeUrl( imageUrl ) : null;
		if ( ! parsed || ( parsed.protocol !== 'https:' && parsed.protocol !== 'http:' ) ) {
			missing( 'No Open Graph image · 1200×630 recommended' );
			return media;
		}
		var img = D.createElement( 'img' );
		img.className = P + 'social-img';
		img.alt = '';
		img.loading = 'lazy';
		img.referrerPolicy = 'no-referrer';
		img.addEventListener( 'error', function () { missing( 'Image could not be loaded: ' + str( imageUrl, 120 ) ); } );
		img.src = parsed.href;
		media.appendChild( img );
		return media;
	}

	function setChip( chip, state, text ) {
		chip.className = P + 'status-chip ' + P + 'status-chip--' + state;
		chip.textContent = text;
	}

	/* Report section: a card with a header (title, one-line description, status chip) and a divided body. */
	function sectionCard( title, description, chip, extraClass ) {
		var card = el( 'section', 'section' + ( extraClass ? ' ' + extraClass : '' ) );
		var copy = add( el( 'div', 'section-copy' ), el( 'h4', 'section-title', title ), description ? el( 'p', 'section-desc', description ) : null );
		var head = add( el( 'div', 'section-head' ), copy, chip );
		var body = el( 'div', 'section-body' );
		add( card, head, body );
		return { card: card, head: head, body: body };
	}

	function guidance( label, value ) {
		return add( el( 'div', 'guidance' ), el( 'strong', null, label ), el( 'p', null, value ) );
	}

	function metricGrid( items ) {
		var grid = el( 'div', 'metric-grid' );
		items.forEach( function ( item ) {
			grid.appendChild( add( el( 'div', 'metric' ), el( 'strong', null, String( item[ 0 ] ) ), el( 'span', null, item[ 1 ] ) ) );
		} );
		return grid;
	}

	function outline( headings, limit, capLevel ) {
		var list = el( 'ol', 'outline' );
		headings.slice( 0, limit ).forEach( function ( h ) {
			var item = el( 'li' );
			item.style.setProperty( '--heading-level', String( capLevel ? Math.min( h.level, 3 ) : h.level ) );
			list.appendChild( add( item, el( 'strong', null, 'H' + h.level + ' ' ), h.text || '(empty heading)' ) );
		} );
		if ( ! list.childNodes.length ) {
			list.appendChild( el( 'li', null, 'No headings detected.' ) );
		}
		return list;
	}

	function card( title, cls ) {
		return add( el( 'article', 'card' + ( cls ? ' ' + cls : '' ) ), el( 'h4', null, title ) );
	}

	function chips( values ) {
		var list = el( 'div', 'chips' );
		values.forEach( function ( v ) { list.appendChild( typeof v === 'string' ? el( 'span', 'chip', v ) : v ); } );
		return list;
	}

	function engineSummary( title, detail ) {
		return add( el( 'div', 'engine-summary' ), el( 'strong', null, title ), el( 'span', null, detail ) );
	}

	function engineError( message ) {
		return el( 'p', 'engine-error', 'Unavailable: ' + message );
	}

	function crawlRow( label, value, detail, tone ) {
		var row = add( el( 'div', 'crawl-row crawl-' + ( tone || 'info' ) ), el( 'strong', null, label ), el( 'span', 'crawl-value', value || 'Unavailable' ) );
		return detail ? add( row, el( 'p', null, detail ) ) : row;
	}

	/* Visible viewer for the audited page. Chrome only reports paint and layout-shift entries for frames it paints,
	   so the iframe is rendered inside the panel while the audit runs and collapsed to a toggle afterwards. */
	function makeViewer() {
		var wrap = el( 'div', 'viewer' );
		var status = el( 'span', 'viewer-status', 'Loading the page…' );
		var box = el( 'div', 'viewer-frame' );
		var toggle = button( 'text-link', 'Hide audited page', function () {
			var show = box.hidden;
			box.hidden = ! show;
			toggle.textContent = show ? 'Hide audited page' : 'Show audited page';
			toggle.setAttribute( 'aria-expanded', show ? 'true' : 'false' );
		} );
		var dots = el( 'span', 'viewer-dots' );
		dots.setAttribute( 'aria-hidden', 'true' );
		dots.appendChild( el( 'span' ) );
		wrap.hidden = true;
		toggle.hidden = true;
		add( wrap, add( el( 'div', 'viewer-bar' ), dots, status, toggle ), box );
		return {
			wrap: wrap,
			frame: null,
			status: function ( text ) { status.textContent = text; },
			start: function ( src ) {
				this.clear();
				wrap.hidden = false;
				box.hidden = false;
				toggle.hidden = true;
				status.textContent = 'Loading the page…';
				var frame = D.createElement( 'iframe' );
				frame.className = P + 'frame';
				frame.title = 'Page being audited';
				frame.loading = 'eager';
				box.appendChild( frame );
				this.frame = frame;
				var rect = wrap.getBoundingClientRect();
				if ( rect.bottom < 0 || rect.top > W.innerHeight ) {
					wrap.scrollIntoView( { block: 'center' } );
				}
				frame.src = src;
				return frame;
			},
			done: function () {
				status.textContent = 'Audited page';
				box.hidden = true;
				toggle.hidden = false;
				toggle.textContent = 'Show audited page';
				toggle.setAttribute( 'aria-expanded', 'false' );
			},
			clear: function () {
				if ( this.frame && this.frame.parentNode ) { this.frame.parentNode.removeChild( this.frame ); }
				this.frame = null;
			},
			fail: function () {
				this.clear();
				wrap.hidden = true;
			}
		};
	}

	/* ---------- Panel ---------- */

	function Panel( root, options ) {
		var ds = root.dataset;
		this.root = root;
		this.uid = P + ( ++uidCounter );
		this.o = Object.assign( {
			targetUrl: ds.targetUrl || '',
			nonceUrl: ds.nonceUrl || '',
			postId: num( ds.postId ),
			title: ds.targetTitle || '',
			autorun: ds.autorun === '1' || Boolean( C.autorun ),
			view: ds.view || '',
			embedded: false,
			compact: ds.layout === 'compact',
			lastSummary: parseJson( ds.lastSummary )
		}, options || {} );
		this.nonce = ds.restNonce || C.restNonce || '';
		this.crawlUrl = ds.crawlUrl || C.crawlUrl || endpoint( ds.restUrl || C.restUrl, 'crawl' );
		this.summaryUrl = ds.summaryUrl || C.summaryUrl || endpoint( ds.restUrl || C.restUrl, 'summary' );
		this.sources = sourcesMap();
		this.categories = categoryList();
		this.runId = 0;
		this.busy = false;
		this.result = null;
		this.build();
	}

	function parseJson( text ) {
		if ( ! text ) { return null; }
		try { var value = JSON.parse( text ); return value && typeof value === 'object' ? value : null; } catch ( e ) { return null; }
	}

	function endpoint( restUrl, name ) {
		if ( ! restUrl ) { return ''; }
		if ( new RegExp( '/' + name + '/?$' ).test( restUrl ) ) { return restUrl; }
		if ( /opace-eseot\/v1\/?$/.test( restUrl ) ) { return restUrl.replace( /\/?$/, '/' + name ); }
		return restUrl.replace( /\/?$/, '/' ) + 'opace-eseot/v1/' + name;
	}

	Panel.prototype.build = function () {
		var self = this;
		var root = this.root;
		var target = safeUrl( this.o.targetUrl );
		root.textContent = '';
		root.classList.add( P + 'root' );
		if ( this.o.embedded ) { root.classList.add( P + 'root--embedded' ); }
		this.n = {};

		/* Editor meta box: a slim card by default, the full panel only after "Run audit" (or autorun). */
		this.o.compact = Boolean( this.o.compact ) && ! this.o.embedded;
		if ( this.o.compact ) {
			this.n.compact = this.compactCard( target );
			root.appendChild( this.n.compact );
		}
		var full = el( 'div', 'full' );
		this.n.full = full;
		root.appendChild( full );

		if ( ! this.o.embedded ) {
			var header = add( el( 'header', 'header' ), markImage( 'mark' ), add( el( 'div', 'header-copy' ), el( 'p', 'brand', 'Essential SEO Toolkit' ), el( 'p', 'tagline', 'Local page audit + free deeper checks' ) ) );
			this.n.collapse = button( 'header-action', tx( 'collapse', 'Collapse' ), function () { self.collapse(); } );
			this.n.collapse.hidden = ! this.o.compact;
			full.appendChild( add( header, this.n.collapse ) );
		}
		/* Everything below the header band sits in one white report container. */
		var report = el( 'div', 'report' );
		this.n.report = report;
		full.appendChild( report );

		var page = el( 'section', 'page' );
		page.setAttribute( 'aria-label', 'Current page' );
		var copy = el( 'div', 'page-copy' );
		this.n.host = el( 'strong', 'host', target ? target.hostname.replace( /^www\./, '' ) : 'No page selected' );
		this.n.title = el( 'p', 'title', this.o.title || this.o.targetUrl );
		this.n.url = el( 'small', 'url', this.o.targetUrl );
		add( copy, add( el( 'span', 'label' ), 'Current page · ', this.n.host ), this.n.title, this.n.url );
		var actions = el( 'div', 'actions' );
		this.n.copyUrl = button( 'button', 'Copy URL', function () {
			copyText( self.o.targetUrl ).then( function () { flash( self.n.copyUrl, tx( 'copied', 'Copied' ) ); } );
		} );
		this.n.copyUrl.disabled = ! target;
		this.n.run = button( 'button button--primary', tx( 'runAudit', 'Run audit' ), function () { self.run( 'iframe' ); } );
		this.n.copy = button( 'button', tx( 'copySummary', 'Copy summary' ), function () { self.copySummary(); } );
		this.n.copy.disabled = true;
		add( actions, this.n.copyUrl, this.n.run, this.n.copy );
		this.n.status = el( 'p', 'status' );
		this.n.status.setAttribute( 'role', 'status' );
		this.n.auditedUrl = el( 'small', 'audited-url' );
		this.n.auditedUrl.hidden = true;
		this.n.progress = add( el( 'p', 'progress' ), el( 'span', 'spinner' ), el( 'span', 'progress-text' ) );
		this.n.progress.hidden = true;
		this.n.error = el( 'div', 'error' );
		this.n.error.hidden = true;
		add( page, add( el( 'div', 'page-top' ), copy, actions ), this.n.status, this.n.auditedUrl, this.n.progress, this.n.error );
		this.viewer = this.o.viewerHost || makeViewer();
		if ( ! this.o.viewerHost ) { page.appendChild( this.viewer.wrap ); }
		report.appendChild( page );

		this.buildTabs();
		this.renderIdle();
		this.renderTools();
		this.activate( this.o.view === 'tools' ? 'tools' : 'overview' );

		if ( ! target ) {
			this.setStatus( tx( 'noPermalink', 'This post has no public permalink yet. Save or publish it, then run the audit.' ), 'error' );
			this.n.run.disabled = true;
		} else if ( this.o.lastSummary ) {
			this.renderStoredSummary( this.o.lastSummary );
		} else {
			this.setStatus( 'Ready to audit this page locally.', '' );
		}

		if ( this.o.compact ) {
			this.lastSummary = this.o.lastSummary;
			this.updateCompact();
			full.hidden = true;
		}
	};

	/* Slim card: mark, title, last result line and the three ways in. */
	Panel.prototype.compactCard = function ( target ) {
		var self = this;
		var card = el( 'section', 'compact' );
		card.setAttribute( 'aria-label', tx( 'pageAudit', 'Page audit' ) );
		var copy = add( el( 'div', 'compact-copy' ), el( 'strong', 'compact-title', tx( 'pageAudit', 'Page audit' ) ) );
		this.n.compactLine = el( 'p', 'compact-line' );
		copy.appendChild( this.n.compactLine );
		var actions = el( 'div', 'compact-actions' );
		this.n.compactRun = button( 'button button--primary', tx( 'runAudit', 'Run audit' ), function () { self.run( 'iframe' ); } );
		this.n.compactRun.disabled = ! target;
		actions.appendChild( this.n.compactRun );
		if ( C.toolsPageUrl && target ) {
			var report = el( 'a', 'button', tx( 'openReport', 'Open full report' ) );
			report.href = C.toolsPageUrl + ( C.toolsPageUrl.indexOf( '?' ) === -1 ? '?' : '&' ) + 'url=' + encodeURIComponent( this.o.targetUrl );
			actions.appendChild( report );
		}
		if ( C.settingsUrl ) {
			var settings = el( 'a', 'text-link', tx( 'settings', 'Settings' ) );
			settings.href = C.settingsUrl;
			actions.appendChild( settings );
		}
		return add( card, markImage( 'compact-mark' ), copy, actions );
	};

	Panel.prototype.updateCompact = function () {
		var line = this.n.compactLine;
		if ( ! line ) { return; }
		var summary = this.lastSummary;
		var counts = summary && summary.counts && typeof summary.counts === 'object' ? summary.counts : null;
		line.textContent = '';
		if ( ! safeUrl( this.o.targetUrl ) ) {
			line.textContent = tx( 'noPermalink', 'Save the post to get an address to audit.' );
			line.dataset.state = 'error';
			return;
		}
		if ( ! counts ) {
			line.textContent = tx( 'notAudited', 'Not audited yet' );
			line.dataset.state = '';
			return;
		}
		var when = str( summary.audited_at, 40 );
		var audited = fmt( tx( 'auditedAt', 'Audited %s' ), when ? timeAgo( when ) : 'previously' );
		var parts = [ [ 'pass', 'Pass' ], [ 'review', 'Review' ], [ 'info', 'Info' ] ].map( function ( def ) {
			return num( counts[ def[ 0 ] ] ) + ' ' + tx( def[ 0 ], def[ 1 ] ).toLowerCase();
		} );
		add( line, parts.join( ' · ' ) + ' · ', el( 'em', null, audited.charAt( 0 ).toLowerCase() + audited.slice( 1 ) ) );
		line.dataset.state = 'done';
	};

	Panel.prototype.expand = function () {
		if ( ! this.o.compact ) { return; }
		this.n.full.hidden = false;
		this.n.compact.hidden = true;
	};

	Panel.prototype.collapse = function () {
		if ( ! this.o.compact || this.busy ) { return; }
		this.updateCompact();
		this.n.compactRun.textContent = this.checks ? tx( 'rerun', 'Run again' ) : tx( 'runAudit', 'Run audit' );
		this.n.full.hidden = true;
		this.n.compact.hidden = false;
		this.n.compactRun.focus();
	};

	Panel.prototype.buildTabs = function () {
		var self = this;
		var defs = [ [ 'overview', tabTx( 'overview', 'Overview' ) ], [ 'details', tabTx( 'details', 'All details' ) ], [ 'engines', tabTx( 'engines', 'Local engines' ) ], [ 'crawl', tabTx( 'crawl', 'Crawl' ) ], [ 'tools', tabTx( 'tools', 'Saved tools' ) ] ];
		var list = el( 'div', 'tabs' );
		list.setAttribute( 'role', 'tablist' );
		list.setAttribute( 'aria-label', 'Audit sections' );
		this.tabs = {};
		this.panels = {};
		defs.forEach( function ( def ) {
			var tab = button( 'tab', def[ 1 ], function () { self.activate( def[ 0 ] ); } );
			tab.setAttribute( 'role', 'tab' );
			tab.id = self.uid + '-tab-' + def[ 0 ];
			tab.dataset.tab = def[ 0 ];
			var panel = el( 'section', 'panel panel--' + def[ 0 ] );
			panel.setAttribute( 'role', 'tabpanel' );
			panel.id = self.uid + '-panel-' + def[ 0 ];
			panel.setAttribute( 'aria-labelledby', tab.id );
			panel.tabIndex = 0;
			tab.setAttribute( 'aria-controls', panel.id );
			self.tabs[ def[ 0 ] ] = tab;
			self.panels[ def[ 0 ] ] = panel;
			list.appendChild( tab );
		} );
		list.addEventListener( 'keydown', function ( event ) {
			var keys = Object.keys( self.tabs );
			var index = keys.indexOf( D.activeElement && D.activeElement.dataset.tab );
			if ( index === -1 ) { return; }
			var next = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: keys.length - 1 }[ event.key ];
			if ( next === undefined ) { return; }
			event.preventDefault();
			next = ( next + keys.length ) % keys.length;
			self.activate( keys[ next ] );
			self.tabs[ keys[ next ] ].focus();
		} );
		this.n.report.appendChild( list );
		var wrap = el( 'div', 'panels' );
		Object.keys( this.panels ).forEach( function ( id ) { wrap.appendChild( self.panels[ id ] ); } );
		this.n.report.appendChild( wrap );
	};

	Panel.prototype.activate = function ( id ) {
		var self = this;
		Object.keys( this.tabs ).forEach( function ( key ) {
			var on = key === id;
			self.tabs[ key ].classList.toggle( P + 'tab--active', on );
			self.tabs[ key ].setAttribute( 'aria-selected', on ? 'true' : 'false' );
			self.tabs[ key ].tabIndex = on ? 0 : -1;
			self.panels[ key ].hidden = ! on;
		} );
	};

	Panel.prototype.setStatus = function ( message, state ) {
		this.n.status.textContent = message;
		this.n.status.dataset.state = state || '';
	};

	Panel.prototype.setProgress = function ( message ) {
		this.n.progress.hidden = ! message;
		this.n.progress.lastChild.textContent = message || '';
	};

	Panel.prototype.counts = function () {
		var counts = { pass: 0, review: 0, info: 0 };
		( this.checks || [] ).forEach( function ( c ) { counts[ c.status ] += 1; } );
		return counts;
	};

	Panel.prototype.renderIdle = function () {
		var self = this;
		this.panels.overview.textContent = '';
		this.panels.overview.appendChild( emptyState( 'search', 'Run the audit to see what needs attention, a search preview and the page structure.' ) );
		this.panels.details.textContent = '';
		this.panels.details.appendChild( emptyState( 'list-view', 'Every check, insight card and the plain-text summary appear here after the audit runs.' ) );
		this.panels.engines.textContent = '';
		this.panels.engines.appendChild( emptyState( 'performance', 'axe-core accessibility results and current-visit Web Vitals appear here after the audit runs.' ) );
		this.panels.crawl.textContent = '';
		this.panels.crawl.appendChild( emptyState( 'admin-site-alt3', 'The crawl check runs alongside the audit and reports the response, robots.txt and sitemap findings here.' ) );
		[ 'overview', 'details', 'engines', 'crawl' ].forEach( function ( id ) {
			if ( self.o.targetUrl ) {
				self.panels[ id ].appendChild( button( 'button button--primary', tx( 'runAudit', 'Run audit' ), function () { self.run( 'iframe' ); } ) );
			}
		} );
	};

	Panel.prototype.renderStoredSummary = function ( summary ) {
		var counts = summary.counts && typeof summary.counts === 'object' ? summary.counts : {};
		var when = str( summary.audited_at, 40 );
		this.setStatus( fmt( tx( 'auditedAt', 'Audited %s' ), when ? timeAgo( when ) : 'previously' ), 'stored' );
		var panel = this.panels.overview;
		panel.insertBefore( this.summaryTiles( { pass: num( counts.pass ), review: num( counts.review ), info: num( counts.info ) } ), panel.firstChild );
		var top = strList( summary.top_review, 5, 80 );
		if ( top.length ) {
			panel.insertBefore( el( 'p', 'stored-note', 'Last time the review items were: ' + top.join( ', ' ) + '.' ), panel.childNodes[ 1 ] );
		}
	};

	Panel.prototype.summaryTiles = function ( counts ) {
		var grid = el( 'div', 'summary' );
		[ [ 'pass', tx( 'pass', 'Pass' ) ], [ 'review', tx( 'review', 'Review' ) ], [ 'info', tx( 'info', 'Info' ) ] ].forEach( function ( def ) {
			grid.appendChild( add( el( 'div', 'tile tile--' + def[ 0 ] ), el( 'strong', null, String( counts[ def[ 0 ] ] ) ), el( 'span', null, def[ 1 ] ) ) );
		} );
		return grid;
	};

	/* ---------- running ---------- */

	Panel.prototype.run = function ( mode ) {
		var self = this;
		if ( this.busy || ! safeUrl( this.o.targetUrl ) ) {
			return Promise.resolve();
		}
		this.busy = true;
		this.expand();
		this.runId += 1;
		var id = this.runId;
		this.n.run.disabled = true;
		if ( this.n.collapse ) { this.n.collapse.disabled = true; }
		if ( this.n.compactRun ) { this.n.compactRun.disabled = true; }
		this.n.error.hidden = true;
		this.n.error.textContent = '';
		this.setStatus( tx( 'running', 'Auditing this page locally…' ), 'loading' );
		this.setProgress( mode === 'tab' ? 'Waiting for the new tab to report back…' : 'Loading the page…' );
		var crawl = this.fetchCrawl( id );
		var finish = function () {
			if ( id === self.runId ) {
				self.busy = false;
				self.n.run.disabled = false;
				self.n.run.textContent = tx( 'rerun', 'Run again' );
				if ( self.n.collapse ) { self.n.collapse.disabled = false; }
				if ( self.n.compactRun ) {
					self.n.compactRun.disabled = false;
					self.n.compactRun.textContent = tx( 'rerun', 'Run again' );
				}
				self.setProgress( '' );
			}
		};
		return this.collect( mode ).then( function ( raw ) {
			if ( id !== self.runId ) { return; }
			if ( raw.page && raw.page.status === 'error' ) {
				throw new Error( str( raw.page.message, 300 ) || 'No audit data was returned by this page.' );
			}
			self.showResult( raw );
			self.setStatus( 'Audit complete', 'success' );
			return self.storeSummary();
		} ).catch( function ( error ) {
			if ( id !== self.runId ) { return; }
			self.showRunError( error, mode );
		} ).then( finish, finish ).then( function () { return crawl; } );
	};

	Panel.prototype.collect = function ( mode ) {
		var self = this;
		var nonceUrl = this.o.nonceUrl || this.o.targetUrl;
		var origins = [ location.origin ];
		var parsed = safeUrl( nonceUrl );
		if ( parsed && origins.indexOf( parsed.origin ) === -1 ) { origins.push( parsed.origin ); }
		return new Promise( function ( resolve, reject ) {
			var frame = null;
			var opened = null;
			var timer = null;
			var done = false;
			var expectedSource = function () { return mode === 'tab' ? opened : ( frame && frame.contentWindow ); };
			var cleanup = function ( ok ) {
				done = true;
				clearTimeout( timer );
				W.removeEventListener( 'message', onMessage );
				if ( frame ) {
					if ( ok ) { self.viewer.done(); } else { self.viewer.fail(); }
				}
			};
			var onMessage = function ( event ) {
				var data = event.data;
				if ( done || origins.indexOf( event.origin ) === -1 || ! event.source || event.source !== expectedSource() || ! data || typeof data !== 'object' ) {
					return;
				}
				if ( data.type === 'opace-eseot-audit-progress' ) {
					var text = str( data.message, 160 ) || { signals: 'Reading page signals…', accessibility: 'Running axe-core locally…', vitals: 'Capturing this visit for up to 3 seconds…' }[ data.stage ] || 'Working…';
					self.setProgress( text );
					if ( frame ) { self.viewer.status( text ); }
				} else if ( data.type === 'opace-eseot-audit-result' ) {
					cleanup( true );
					if ( opened && ! opened.closed ) { try { opened.close(); } catch ( e ) { /* the tab closes itself */ } }
					resolve( data );
				}
			};
			W.addEventListener( 'message', onMessage );
			timer = setTimeout( function () {
				cleanup( false );
				var error = new Error( tx( 'timeout', 'The page did not report back within 20 seconds. It may block embedding, redirect elsewhere or have a script error.' ) );
				error.code = 'timeout';
				reject( error );
			}, mode === 'tab' ? TAB_TIMEOUT_MS : TIMEOUT_MS );
			if ( mode === 'tab' ) {
				opened = W.open( nonceUrl, '_blank' );
				if ( ! opened ) {
					cleanup( false );
					reject( new Error( 'The browser blocked the new tab. Allow pop-ups for this site and try again.' ) );
				}
				return;
			}
			frame = self.viewer.start( nonceUrl );
		} );
	};

	Panel.prototype.showRunError = function ( error, mode ) {
		var self = this;
		var message = str( error && error.message, 300 ) || 'The page blocked the local audit.';
		this.setStatus( message, 'error' );
		this.n.error.textContent = '';
		this.n.error.hidden = false;
		add( this.n.error, el( 'p', null, message ) );
		if ( mode !== 'tab' ) {
			add( this.n.error, el( 'p', null, 'You can open the page in a new tab instead; the audit runs there and reports back to this screen.' ), button( 'button', tx( 'openInTab', 'Open the page in a new tab' ), function () { self.run( 'tab' ); } ) );
		}
	};

	Panel.prototype.showResult = function ( raw ) {
		raw = raw && typeof raw === 'object' ? raw : {};
		this.page = normalisePage( raw.page, this.o.targetUrl );
		this.checks = buildChecks( this.page );
		var context = raw.page && raw.page.auditContext && typeof raw.page.auditContext === 'object' ? raw.page.auditContext : {};
		this.result = { axe: normaliseAxe( raw.accessibility ), vitals: normaliseVitals( raw.vitals ), timing: raw.timing && typeof raw.timing === 'object' ? raw.timing : {}, framed: Boolean( raw.framed || context.framed || ( raw.vitals && raw.vitals.framed ) ) };
		if ( this.page.title ) {
			this.n.title.textContent = this.page.title;
			this.n.title.title = this.page.title;
		}
		this.n.auditedUrl.textContent = this.page.url;
		this.n.auditedUrl.hidden = this.page.url === this.o.targetUrl;
		this.n.copy.disabled = false;
		this.renderOverview();
		this.renderDetails();
		this.renderEngines();
		this.renderTools();
	};

	Panel.prototype.copySummary = function () {
		var self = this;
		if ( ! this.page ) { return; }
		copyText( summaryText( this.page, this.checks ) ).then( function () { flash( self.n.copy, tx( 'copied', 'Summary copied' ) ); } );
	};

	Panel.prototype.summaryBody = function () {
		var vitals = {};
		var metrics = this.result.vitals.metrics;
		VITALS.forEach( function ( name ) { if ( metrics[ name ] ) { vitals[ name ] = metrics[ name ].value; } } );
		var finished = num( this.result.timing.finishedAt ) || Date.now();
		return {
			post_id: this.o.postId,
			url: this.page.url,
			counts: this.counts(),
			top_review: this.checks.filter( function ( c ) { return c.status === 'review'; } ).slice( 0, 5 ).map( function ( c ) { return c.label; } ),
			audited_at: new Date( finished ).toISOString(),
			vitals: vitals
		};
	};

	Panel.prototype.storeSummary = function () {
		var self = this;
		var body = this.summaryBody();
		this.lastSummary = body;
		this.updateCompact();
		if ( ! this.summaryUrl ) {
			return Promise.resolve();
		}
		return fetch( this.summaryUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': this.nonce },
			body: JSON.stringify( body )
		} ).then( function ( response ) {
			if ( ! response.ok ) { throw new Error( 'HTTP ' + response.status ); }
		} ).catch( function ( error ) {
			W.console.warn( 'Essential SEO Toolkit: audit summary was not stored.', error );
		} );
	};

	/* ---------- crawl (spec §9, REST field names from PLAN-PHASE2-AUDIT) ---------- */

	Panel.prototype.fetchCrawl = function ( id ) {
		var self = this;
		var panel = this.panels.crawl;
		panel.textContent = '';
		panel.appendChild( add( el( 'div', 'intro-block' ), eyebrow( 'Indexing and delivery' ), el( 'h3', 'h2', 'Can search engines reach the essentials?' ), el( 'p', 'intro', 'Requested by this site’s own server. Results are cached for a minute.' ) ) );
		var loading = sectionCard( 'Crawl signals', 'Response, robots.txt, sitemap and response headers.', el( 'span', 'pill pill--quiet', 'Checking…' ) );
		loading.body.appendChild( el( 'p', 'section-note', 'Checking this site’s public files and response headers…' ) );
		panel.appendChild( loading.card );
		var fail = function ( message ) {
			if ( id === self.runId ) { self.renderCrawl( { error: message } ); }
		};
		if ( ! this.crawlUrl ) {
			fail( tx( 'crawlError', 'The crawl check could not reach this site’s REST endpoint.' ) );
			return Promise.resolve();
		}
		var url = this.crawlUrl + ( this.crawlUrl.indexOf( '?' ) === -1 ? '?' : '&' ) + 'url=' + encodeURIComponent( this.o.targetUrl );
		return fetch( url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': this.nonce, Accept: 'application/json' } } ).then( function ( response ) {
			return response.json().catch( function () { return {}; } ).then( function ( json ) {
				if ( ! response.ok ) {
					var reason = json && json.message ? str( json.message, 200 ) : 'HTTP ' + response.status;
					throw new Error( tx( 'crawlError', 'The crawl check could not reach this site’s REST endpoint.' ) + ' (' + reason + ')' );
				}
				return json;
			} );
		} ).then( function ( data ) {
			if ( id === self.runId ) { self.renderCrawl( data ); }
		} ).catch( function ( error ) {
			fail( str( error && error.message, 300 ) || tx( 'crawlError', 'The crawl check could not reach this site’s REST endpoint.' ) );
		} );
	};

	Panel.prototype.renderCrawl = function ( data ) {
		var panel = this.panels.crawl;
		var pill = el( 'span', 'pill' );
		var list = el( 'div', 'crawl-list' );
		panel.textContent = '';
		panel.appendChild( add( el( 'div', 'intro-block' ), eyebrow( 'Indexing and delivery' ), el( 'h3', 'h2', 'Can search engines reach the essentials?' ), el( 'p', 'intro', 'Requested by this site’s own server' + ( data && str( data.fetched_at, 40 ) ? ' at ' + str( data.fetched_at, 40 ) : '' ) + '. Results are cached for a minute.' ) ) );
		var section = sectionCard( 'Crawl signals', 'Response, robots.txt, sitemap and response headers.', pill );
		panel.appendChild( section.card );
		if ( ! data || data.error ) {
			pill.textContent = 'Unavailable';
			pill.classList.add( P + 'pill--quiet' );
			section.body.appendChild( el( 'p', 'engine-error', 'Unavailable: ' + str( data && data.error, 300 ) ) );
			return;
		}
		var status = num( data.status );
		var headers = data.headers && typeof data.headers === 'object' ? data.headers : {};
		var header = function ( name ) { return str( headers[ name ], 300 ); };
		var robots = data.robots_txt && typeof data.robots_txt === 'object' ? data.robots_txt : {};
		var robotsStatus = num( robots.status );
		var declared = strList( robots.sitemaps, 5, 500 );
		var sitemaps = ( Array.isArray( data.sitemaps ) ? data.sitemaps.slice( 0, 10 ) : [] ).map( function ( s ) {
			s = s && typeof s === 'object' ? s : {};
			return { url: str( s.url, 500 ), status: num( s.status ), found: Boolean( s.found ), source: str( s.source, 20 ) };
		} );
		var found = sitemaps.filter( function ( s ) { return s.found; } )[ 0 ];
		var pageOk = status >= 200 && status < 400;
		var robotsOk = Boolean( robots.found ) && robotsStatus > 0 && robotsStatus < 400;
		var needsReview = ! pageOk || ! robotsOk || ! found || Boolean( robots.disallowed_for_path );
		pill.textContent = needsReview ? 'Review needed' : 'Essentials reachable';
		pill.classList.toggle( P + 'pill--review', needsReview );
		pill.classList.toggle( P + 'pill--good', ! needsReview );
		var finalUrl = str( data.final_url, 2000 );
		add( list,
			crawlRow( 'Page response', status ? 'HTTP ' + status : 'Unavailable', data.redirected && finalUrl ? 'Redirected to ' + finalUrl : ( status ? 'The current URL responded to a request from this site.' : 'The response could not be checked.' ), pageOk ? 'good' : 'review' ),
			crawlRow( 'robots.txt', robots.found ? 'HTTP ' + robotsStatus : ( robotsStatus ? 'HTTP ' + robotsStatus : 'Unavailable' ), declared.length ? 'A sitemap is declared: ' + declared[ 0 ] : 'No sitemap declaration was found in robots.txt.', robotsOk ? 'good' : 'review' ),
			crawlRow( 'robots.txt rule for this path', robots.disallowed_for_path ? 'Disallowed' : 'Allowed', robots.disallowed_for_path ? 'Matched rule: ' + ( str( robots.matched_rule, 200 ) || 'not reported' ) + '. Crawlers that honour robots.txt will not fetch this page.' : 'No Disallow rule in robots.txt matches this path.', robots.disallowed_for_path ? 'review' : 'good' ),
			crawlRow( 'Sitemap', found ? 'HTTP ' + found.status : 'Not found', found ? found.url + ( { robots: ' (declared in robots.txt)', 'wp-sitemap': ' (WordPress default)', guess: ' (conventional location)' }[ found.source ] || '' ) : 'Checked robots.txt declarations, /wp-sitemap.xml and /sitemap.xml.', found ? 'good' : 'review' ),
			crawlRow( 'Response indexing rule', header( 'x-robots-tag' ) || 'No extra rule', 'No X-Robots-Tag normally means the page relies on its HTML robots setting.', header( 'x-robots-tag' ).toLowerCase().indexOf( 'noindex' ) !== -1 ? 'review' : 'info' ),
			crawlRow( 'Browser caching', header( 'cache-control' ) ? 'Policy present' : 'No policy exposed', header( 'cache-control' ) || 'Not exposed', header( 'cache-control' ) ? 'good' : 'review' ),
			crawlRow( 'Security headers', status ? 'CSP ' + ( header( 'content-security-policy' ) ? 'present' : 'not exposed' ) + ' · HSTS ' + ( header( 'strict-transport-security' ) ? 'present' : 'not exposed' ) : 'Unavailable', 'CSP and HSTS visible to this request.', 'info' ),
			crawlRow( 'Other response headers', [ 'x-content-type-options', 'x-frame-options', 'referrer-policy', 'permissions-policy' ].filter( header ).length + ' of 4 present', 'X-Content-Type-Options, X-Frame-Options, Referrer-Policy and Permissions-Policy.' + ( header( 'content-type' ) ? ' Content type: ' + header( 'content-type' ) : '' ), 'info' )
		);
		section.body.appendChild( list );
	};

	/* ---------- overview (spec §4, §10.3) ---------- */

	Panel.prototype.auditRow = function ( item ) {
		var self = this;
		var row = el( 'details', 'row row--' + item.status );
		var summary = el( 'summary', 'row-summary' );
		var marker = el( 'span', 'marker' );
		marker.setAttribute( 'aria-hidden', 'true' );
		var heading = add( el( 'div', 'row-heading' ), el( 'strong', null, item.label ), el( 'span', 'badge badge--' + item.status, tx( item.status, item.status.charAt( 0 ).toUpperCase() + item.status.slice( 1 ) ) ), el( 'span', 'value', item.value ) );
		var chevron = el( 'span', 'chevron', '⌄' );
		chevron.setAttribute( 'aria-hidden', 'true' );
		add( summary, marker, add( el( 'div', 'row-copy' ), heading, el( 'p', null, item.summary ) ), chevron );
		var detail = add( el( 'div', 'row-detail' ), guidance( 'Why it matters', item.why ), guidance( 'What to do', item.fix ) );
		var actions = el( 'div', 'verify' );
		item.tools.forEach( function ( name ) {
			var source = self.sources[ name ];
			var href = source ? resolveTool( source.url, self.page.url ) : null;
			if ( href ) {
				actions.appendChild( link( 'verify-link', href, fmt( tx( 'verifyWith', 'Verify with %s' ), SHORT[ name ] || name ) + ' ↗' ) );
			}
		} );
		if ( actions.childNodes.length ) {
			detail.appendChild( actions );
		} else if ( item.tools.length ) {
			detail.appendChild( el( 'p', 'note', tx( 'noTools', 'No saved tools match this finding.' ) ) );
		}
		return add( row, summary, detail );
	};

	Panel.prototype.renderOverview = function () {
		var self = this;
		var page = this.page;
		var panel = this.panels.overview;
		var reviews = this.checks.filter( function ( c ) { return c.status === 'review'; } );
		panel.textContent = '';
		panel.appendChild( this.summaryTiles( this.counts() ) );

		var priority = el( 'div', 'priority' );
		if ( ! reviews.length ) {
			priority.appendChild( add( el( 'div', 'clear' ), el( 'span', 'clear-icon', '✓' ), el( 'strong', null, 'No common on-page issue was flagged' ), el( 'p', null, 'The automatic supporting checks may still reveal accessibility, performance or delivery work.' ) ) );
		} else {
			reviews.slice( 0, 4 ).forEach( function ( item, index ) {
				var row = self.auditRow( item );
				row.classList.add( P + 'row--priority' );
				row.open = index === 0;
				priority.appendChild( row );
			} );
			if ( reviews.length > 4 ) {
				priority.appendChild( el( 'p', 'note', ( reviews.length - 4 ) + ' more action(s) are listed under All details.' ) );
			}
		}
		panel.appendChild( add( el( 'section', 'block' ), sectionTitle( 'Start here', 'What needs attention', el( 'span', 'pill', reviews.length ? plural( reviews.length, 'action', 'actions' ) : 'No common issues' ), true ), priority ) );

		var structure = add( el( 'article', 'card' ), el( 'h4', null, 'Page structure' ), metricGrid( [ [ page.h1.length, 'H1 headings' ], [ page.headings.length, 'All headings' ], [ page.internalLinks, 'Internal links' ], [ page.missingAltCount, 'Images missing alt' ] ] ), outline( page.headings, 5, true ) );
		panel.appendChild( add( el( 'section', 'block' ), sectionTitle( 'At a glance', 'Search and page structure' ), add( el( 'div', 'grid-2' ), this.serpCard(), structure ), this.vitalSnapshot() ) );

		panel.appendChild( this.recommendations() );
	};

	Panel.prototype.vitalSnapshot = function () {
		var metrics = this.result.vitals.metrics;
		var box = add( el( 'div', 'snapshot' ), el( 'strong', 'snapshot-title', 'Current-visit performance' ) );
		var grid = el( 'div', 'snapshot-grid' );
		VITALS.forEach( function ( name ) {
			var m = metrics[ name ];
			grid.appendChild( add( el( 'div', 'snapshot-cell' + ( m ? ' rating-border-' + m.rating : '' ) ), el( 'span', null, name ), el( 'strong', null, m ? formatVital( name, m.value ) : '—' ), el( 'small', null, m ? ratingCopy( m.rating ) : 'Not observed' ) ) );
		} );
		return add( box, grid, el( 'p', null, 'From this browser visit, not a PageSpeed or ranking score.' ) );
	};

	Panel.prototype.toolContext = function ( name ) {
		var page = this.page;
		var reviewLabels = this.checks.filter( function ( c ) { return c.status === 'review'; } ).map( function ( c ) { return c.label; } );
		var perf = page.performance;
		var heavier = ( perf.loadCompleteMs || 0 ) > 3000 || ( perf.transferBytes || 0 ) > 2500000 || ( perf.resourceCount || 0 ) > 100;
		var a11y = intersection( [ 'Document language', 'Image alt attributes', 'Heading order' ], reviewLabels );
		var schema = page.schemaCount > 0;
		var contexts = {
			'Google PageSpeed Insights': { priority: heavier || reviewLabels.indexOf( 'Mobile viewport' ) !== -1, tag: heavier ? 'Performance follow-up' : 'Field & lab data', reason: heavier ? 'The local snapshot looks relatively heavy or slow. Measure Core Web Vitals and diagnostics for this URL.' : 'Measure performance and Core Web Vitals that a local DOM audit cannot confirm.' },
			'Google Rich Results Test': { priority: schema, tag: schema ? 'Validate detected markup' : 'Eligibility check', reason: schema ? 'Check whether the detected ' + ( page.schemaTypes.join( ', ' ) || 'structured data' ) + ' is eligible for rich results.' : 'Use after adding an eligible structured-data type; no JSON-LD block was detected.' },
			'Schema Markup Validator': { priority: schema, tag: schema ? page.schemaCount + ' block(s) detected' : 'Vocabulary check', reason: schema ? 'Validate this page’s Schema.org syntax and vocabulary.' : 'Use after adding structured data to validate its syntax and vocabulary.' },
			'WAVE Accessibility Evaluation': { priority: a11y.length > 0, tag: a11y.length ? a11y.length + ' related review(s)' : 'Broader accessibility check', reason: a11y.length ? 'Investigate the local ' + a11y.join( ', ' ).toLocaleLowerCase() + ' findings in a broader evaluation.' : 'Check contrast, labels, landmarks and other issues beyond the local signal set.' },
			'Security Headers': { priority: page.protocol !== 'https:', tag: 'Response headers', reason: 'Inspect live HTTP security headers that are not exposed by the page DOM.' },
			'Google Admin Toolbox Dig': { priority: false, tag: 'DNS evidence', reason: 'Confirm the current host’s live A records when diagnosing domain or delivery changes.' }
		};
		return Object.assign( { name: name, priority: false, tag: 'Deeper check', reason: 'Continue reviewing this page.' }, contexts[ name ] || {} );
	};

	Panel.prototype.toolLink = function ( name, source, context ) {
		var a = link( 'tool' + ( context.priority ? ' tool--priority' : '' ), resolveTool( source.url, this.page.url ) );
		add( a, el( 'span', 'tag' + ( context.custom ? ' tag--custom' : '' ), context.tag ), el( 'strong', null, name ), el( 'span', 'reason', context.reason ), el( 'span', null, source.note || BUILT_IN[ name ] || 'Page-aware deeper check' ) );
		var arrow = el( 'span', 'arrow', '↗' );
		arrow.setAttribute( 'aria-hidden', 'true' );
		return add( a, arrow );
	};

	Panel.prototype.recommendations = function () {
		var self = this;
		var reviewCount = this.counts().review;
		var section = el( 'section', 'block' );
		var manage = C.settingsUrl ? el( 'a', 'text-link', 'Manage links' ) : null;
		if ( manage ) { manage.href = C.settingsUrl; }
		section.appendChild( sectionTitle( 'Optional external checks', 'Verify the findings that matter', manage ) );
		section.appendChild( el( 'p', 'intro', reviewCount ? plural( reviewCount, 'local item', 'local items' ) + ' need review. These deeper checks are ordered by relevance.' : 'No common local issue was flagged. These checks cover evidence the page DOM cannot provide.' ) );
		var contexts = Object.keys( BUILT_IN ).map( function ( name ) { return self.toolContext( name ); } );
		contexts.sort( function ( a, b ) { return Number( b.priority ) - Number( a.priority ) || a.name.localeCompare( b.name ); } );
		var group = sectionCard( 'Recommended for this page', 'Ordered by how relevant each check is to this audit.', null, 'tool-group' );
		var links = el( 'div', 'tool-list' );
		contexts.slice( 0, 3 ).forEach( function ( context ) {
			if ( self.sources[ context.name ] ) { links.appendChild( self.toolLink( context.name, self.sources[ context.name ], context ) ); }
		} );
		group.body.appendChild( links );
		var remaining = contexts.slice( 3 ).filter( function ( context ) { return self.sources[ context.name ]; } );
		if ( remaining.length ) {
			var more = add( el( 'details', 'more' ), el( 'summary', null, remaining.length + ' more optional checks' ) );
			var moreList = el( 'div', 'tool-list' );
			remaining.forEach( function ( context ) { moreList.appendChild( self.toolLink( context.name, self.sources[ context.name ], context ) ); } );
			group.body.appendChild( add( more, moreList ) );
		}
		section.appendChild( group.card );
		this.categories.forEach( function ( category ) {
			var names = Object.keys( self.sources ).filter( function ( name ) { return ! BUILT_IN[ name ] && self.sources[ name ].cat === category.id; } );
			if ( ! names.length ) { return; }
			var custom = sectionCard( category.name, 'Your own saved tools in this category.', null, 'tool-group' );
			var list = el( 'div', 'tool-list' );
			names.forEach( function ( name ) {
				list.appendChild( self.toolLink( name, self.sources[ name ], { priority: false, custom: true, tag: 'Custom', reason: self.sources[ name ].note || 'Your saved page-aware deeper check.' } ) );
			} );
			custom.body.appendChild( list );
			section.appendChild( custom.card );
		} );
		return add( section, el( 'p', 'note', 'Nothing is sent automatically. Opening a deeper check sends this URL or domain to that service under its own terms and privacy policy.' ) );
	};

	/* ---------- all details (spec §5, §6) ---------- */

	Panel.prototype.serpCard = function () {
		var page = this.page;
		var host = pageHost( page.url );
		var head = add( el( 'div', 'serp-head' ), el( 'span', 'serp-favicon', host.charAt( 0 ).toUpperCase() ), add( el( 'div', 'serp-copy' ), el( 'span', 'serp-site', host ), el( 'span', 'serp-url', page.url ) ) );
		var preview = add( el( 'div', 'serp' ), head, el( 'span', 'serp-title', page.title || 'Missing page title' ), el( 'span', 'serp-desc', page.description || 'No meta description was detected for this page.' ) );
		return add( card( 'Search result preview' ), preview, el( 'p', null, 'A local approximation. Search engines can choose different titles and snippets for each query.' ) );
	};

	Panel.prototype.insightCards = function () {
		var page = this.page;
		var perf = page.performance;
		var sets = page.termSets;
		var social = add( el( 'div', 'social' ), socialMedia( page.openGraphImage ), add( el( 'div', 'social-copy' ), el( 'span', 'social-host', pageHost( page.url ) ), el( 'strong', null, page.openGraphTitle || page.title || 'No preview title' ), el( 'span', null, page.openGraphDescription || page.description || 'No preview description detected' ) ) );

		var termChips = page.topTerms.concat( page.topPhrases ).slice( 0, 12 ).map( function ( t ) {
			return add( el( 'span', 'chip' ), t.term + ' ', el( 'strong', null, '×' + t.count ) );
		} );
		var terms = add( card( 'Repeated terms and phrases' ), chips( termChips.length ? termChips : [ 'Not enough visible text' ] ), el( 'p', null, 'Frequency from visible page text only. These are not target keywords, search volumes or ranking recommendations.' ) );

		var pairs = [ [ 'Title ↔ H1', intersection( sets.title, sets.h1 ) ], [ 'Title ↔ description', intersection( sets.title, sets.description ) ], [ 'Across all 3', intersection( intersection( sets.title, sets.h1 ), sets.description ) ] ];
		var overlapGrid = el( 'div', 'overlap' );
		pairs.forEach( function ( pair ) { overlapGrid.appendChild( add( el( 'div', 'overlap-cell' ), el( 'strong', null, String( pair[ 1 ].length ) ), el( 'span', null, pair[ 0 ] ) ) ); } );
		var shared = pairs[ 2 ][ 1 ].slice( 0, 8 );
		var overlap = add( card( 'Title, H1 and description overlap' ), overlapGrid, el( 'p', null, shared.length ? 'Shared terms: ' + shared.join( ', ' ) : 'No meaningful term appears in all three fields. Exact repetition is not required.' ) );

		var headingCard = add( card( 'Heading outline' ), outline( page.headings, 12, false ) );
		if ( page.headings.length > 12 ) { headingCard.appendChild( el( 'p', null, ( page.headings.length - 12 ) + ' more heading(s) not shown.' ) ); }

		var linkCard = add( card( 'Link inventory' ), metricGrid( [ [ page.internalLinks, 'Internal' ], [ page.externalLinks, 'External' ], [ page.nofollowLinks, 'Nofollow' ] ] ) );
		var samples = el( 'ul', 'samples' );
		page.internalLinkSamples.slice( 0, 2 ).concat( page.externalLinkSamples.slice( 0, 2 ) ).forEach( function ( l ) {
			var item = el( 'li', null, ( l.text || '(no link text)' ) + ' — ' + l.url );
			item.title = l.url;
			samples.appendChild( item );
		} );
		if ( samples.childNodes.length ) { add( linkCard, el( 'p', null, 'Sample links' ), samples ); }

		var imageCard = add( card( 'Images and alt coverage' ), metricGrid( [ [ page.imageCount, 'Images' ], [ page.imageCount - page.missingAltCount, 'With alt attribute' ], [ page.missingAltCount, 'Missing alt' ] ] ) );
		if ( page.missingAltSamples.length ) {
			var sources = el( 'ul', 'samples' );
			page.missingAltSamples.forEach( function ( s ) { sources.appendChild( el( 'li', null, s ) ); } );
			add( imageCard, el( 'p', null, 'Missing-alt image sources' ), sources );
		} else {
			imageCard.appendChild( el( 'p', null, 'No image without an alt attribute was found. Empty alt may be correct for decorative images.' ) );
		}

		var schemaCard = add( card( 'Structured data summary' ), metricGrid( [ [ page.schemaCount, 'JSON-LD blocks' ], [ page.schemaTypes.length, 'Detected types' ] ] ), el( 'p', null, page.schemaTypes.length ? page.schemaTypes.join( ', ' ) : 'No JSON-LD types detected. Add markup only when it describes visible content accurately.' ) );

		var timing = add( card( 'Navigation timing' ), metricGrid( [ [ formatMs( perf.responseStartMs ), 'Response started' ], [ formatMs( perf.domContentLoadedMs ), 'DOM content loaded' ], [ formatMs( perf.loadCompleteMs ), 'Load event' ] ] ), el( 'p', null, 'A snapshot from this visit, affected by device, cache and connection. It is not a Core Web Vitals field report.' ) );
		var resources = add( card( 'Loaded resources' ), metricGrid( [ [ perf.resourceCount === null ? 'Unavailable' : perf.resourceCount, 'Resource requests' ], [ formatBytes( perf.transferBytes ), 'Transferred' ], [ formatBytes( perf.decodedBytes ), 'Decoded size' ] ] ), el( 'p', null, 'Cached and cross-origin resources can report zero sizes, so treat these figures as a diagnostic snapshot.' ) );
		var hosts = perf.thirdPartyHosts;
		var thirdParty = add( card( 'Third-party resource hosts' ), el( 'p', null, hosts.length ? hosts.length + ' external host(s) supplied resources during this load.' : 'No third-party host was exposed by browser timing data.' ), hosts.length ? chips( hosts ) : null );

		return [
			[ 'Search wording', [ this.serpCard(), add( card( 'Shared-link preview' ), social ), terms, overlap ] ],
			[ 'Page structure', [ headingCard, linkCard, imageCard, schemaCard ] ],
			[ 'Browser performance snapshot', [ timing, resources, thirdParty ] ]
		];
	};

	Panel.prototype.renderDetails = function () {
		var self = this;
		var panel = this.panels.details;
		panel.textContent = '';
		panel.appendChild( add( el( 'div', 'intro-block' ), eyebrow( 'Full local report' ), el( 'h3', 'h2', 'Every check and page signal' ), el( 'p', 'intro', 'Expand a check to see why it matters, what to change and the relevant verification link.' ) ) );
		var groups = el( 'div', 'groups' );
		GROUPS.forEach( function ( group ) {
			var items = self.checks.filter( function ( c ) { return c.category === group[ 0 ]; } );
			if ( ! items.length ) { return; }
			var toReview = items.filter( function ( c ) { return c.status === 'review'; } ).length;
			var chip = el( 'span', 'pill' + ( toReview ? ' pill--review' : ' pill--good' ), toReview ? toReview + ' to review' : 'All clear' );
			var section = sectionCard( group[ 1 ], group[ 2 ], chip, 'group' );
			items.forEach( function ( item ) { section.body.appendChild( self.auditRow( item ) ); } );
			groups.appendChild( section.card );
		} );
		panel.appendChild( groups );
		this.insightCards().forEach( function ( def ) {
			var section = add( el( 'section', 'insights' ), el( 'h4', 'insights-title', def[ 0 ] ) );
			var grid = el( 'div', 'grid-2' );
			def[ 1 ].forEach( function ( c ) { grid.appendChild( c ); } );
			panel.appendChild( add( section, grid ) );
		} );
		var copyButton = button( 'button button--primary button--wide', tx( 'copySummary', 'Copy audit summary' ), function () {
			copyText( summaryText( self.page, self.checks ) ).then( function () { flash( copyButton, tx( 'copied', 'Summary copied' ) ); } );
		} );
		panel.appendChild( copyButton );
		panel.appendChild( add( el( 'p', 'privacy' ), el( 'span', 'privacy-dot', '●' ), ' Page content and results stay in this browser. These checks are prompts for review, not a ranking score.' ) );
	};

	/* ---------- local engines (spec §7, §8) ---------- */

	Panel.prototype.engineCard = function ( eyebrowText, title, pillText, open, body ) {
		var details = el( 'details', 'engine' );
		details.open = open;
		var summary = add( el( 'summary', 'engine-heading' ), add( el( 'div', 'section-copy' ), el( 'p', 'engine-label', eyebrowText ), el( 'h4', 'section-title', title ), ENGINE_DESC[ eyebrowText ] ? el( 'p', 'section-desc', ENGINE_DESC[ eyebrowText ] ) : null ), el( 'span', 'pill', pillText ) );
		var results = el( 'div', 'engine-results' );
		results.setAttribute( 'aria-live', 'polite' );
		body.forEach( function ( node ) { if ( node ) { results.appendChild( node ); } } );
		return add( details, summary, results );
	};

	Panel.prototype.renderEngines = function () {
		var panel = this.panels.engines;
		var engines = C.engines || {};
		panel.textContent = '';
		panel.appendChild( add( el( 'div', 'intro-block' ), eyebrow( 'Automatic local evidence' ), el( 'h3', 'h2', 'Supporting checks' ), el( 'p', 'intro', 'These ran inside the audited page in this browser. Page data is processed locally; nothing is sent to Opace.' ) ) );
		var grid = el( 'div', 'engine-grid' );
		grid.appendChild( engines.axe === false ? this.engineCard( 'Accessibility', 'Barriers people may encounter', 'Turned off', true, [ el( 'p', 'note', 'The accessibility engine is turned off in the toolkit settings.' ) ] ) : this.accessibilityCard() );
		grid.appendChild( engines.vitals === false ? this.engineCard( 'Performance', 'How this visit felt', 'Turned off', true, [ el( 'p', 'note', 'The Web Vitals engine is turned off in the toolkit settings.' ) ] ) : this.vitalsCard() );
		panel.appendChild( grid );
	};

	Panel.prototype.accessibilityCard = function () {
		var data = this.result.axe;
		if ( data.status !== 'ok' ) {
			var reason = data.message || ( data.status === 'skipped' ? 'The accessibility engine was skipped for this run.' : 'Accessibility results were unavailable.' );
			return this.engineCard( 'Accessibility', 'Barriers people may encounter', data.status === 'skipped' ? 'Skipped' : 'Unavailable', true, [ engineError( reason ) ] );
		}
		var violations = data.violations;
		var nodeTotal = violations.reduce( function ( total, v ) { return total + v.nodes; }, 0 );
		var body = [ engineSummary( violations.length ? plural( violations.length, 'rule violation', 'rule violations' ) : 'No rule violations found', violations.length ? plural( nodeTotal, 'affected element', 'affected elements' ) : 'Automated checks do not cover every accessibility requirement' ) ];
		IMPACTS.forEach( function ( impact ) {
			var group = violations.filter( function ( v ) { return v.impact === impact; } );
			if ( ! group.length ) { return; }
			body.push( add( el( 'p', 'impact-heading' ), el( 'span', 'impact impact--' + impact, impact ), ' ' + plural( group.length, 'rule', 'rules' ) ) );
			group.forEach( function ( v ) {
				var copy = A11Y[ v.id ];
				var why = copy ? copy[ 0 ] : ( v.description || 'This automated rule found a pattern that may make the page harder to use.' );
				var fix = copy ? copy[ 1 ] : 'Review the affected element, correct the underlying HTML and test the result with keyboard and screen-reader checks.';
				var row = el( 'details', 'finding' );
				var summary = add( el( 'summary', 'finding-summary' ), el( 'strong', null, v.help || v.id ), el( 'span', 'impact impact--' + impact, impact ), el( 'span', 'value', plural( v.nodes, 'affected element', 'affected elements' ) ) );
				var detail = add( el( 'div', 'row-detail' ), guidance( 'Why it matters', why ), guidance( 'What to do', fix ) );
				if ( v.sample.length ) {
					detail.appendChild( add( el( 'div', 'guidance' ), el( 'strong', null, 'Technical detail' ), el( 'code', 'target', v.sample.join( ' · ' ) ) ) );
					if ( v.sample.some( function ( s ) { return /^#chrome_/i.test( s ); } ) ) {
						detail.appendChild( el( 'p', 'note', 'This element may have been added by another browser extension. Confirm it in a clean profile before changing the page.' ) );
					}
				}
				body.push( add( row, summary, detail ) );
			} );
		} );
		if ( data.passes || data.incomplete ) {
			body.push( el( 'p', 'note', data.passes + ' rule(s) passed · ' + data.incomplete + ' need manual review.' ) );
		}
		body.push( el( 'p', 'note', 'axe-core ' + ( data.version || '4.13.0' ) + ' ran locally. Embedded frames were excluded and automated findings still need human review.' ) );
		return this.engineCard( 'Accessibility', 'Barriers people may encounter', violations.length ? plural( violations.length, 'issue', 'issues' ) : 'No issues found', violations.length > 0, body );
	};

	Panel.prototype.vitalsCard = function () {
		var data = this.result.vitals;
		if ( data.status === 'error' || ( data.status !== 'ok' && data.status !== 'partial' ) ) {
			return this.engineCard( 'Performance', 'How this visit felt', 'Unavailable', true, [ engineError( data.message || 'Current-visit metrics were unavailable.' ) ] );
		}
		var metrics = data.metrics;
		var observed = VITALS.filter( function ( n ) { return metrics[ n ]; } );
		var poor = observed.filter( function ( n ) { return metrics[ n ].rating === 'poor'; } ).length;
		var needsWork = observed.filter( function ( n ) { return metrics[ n ].rating === 'needs-improvement'; } ).length;
		var title = poor ? plural( poor, 'slow metric', 'slow metrics' ) + ' in this visit' : needsWork ? plural( needsWork, 'metric', 'metrics' ) + ' worth checking' : 'This visit looks responsive';
		var grid = el( 'div', 'vitals' );
		VITALS.forEach( function ( name ) {
			var m = metrics[ name ];
			grid.appendChild( add( el( 'div', 'vital' ), el( 'span', 'vital-name', VITAL_LABEL[ name ] ), el( 'strong', null, m ? formatVital( name, m.value ) : 'Unavailable' ), el( 'span', 'rating' + ( m ? ' rating--' + m.rating : '' ), m ? ratingCopy( m.rating ) : ( name === 'INP' ? 'Use the page first' : 'Not observed' ) ) ) );
		} );
		var body = [ engineSummary( title, observed.length + ' of 5 browser metrics captured' ), grid ];
		if ( data.message && data.status === 'partial' ) { body.push( el( 'p', 'note', data.message ) ); }
		if ( data.framed || this.result.framed ) {
			body.push( el( 'p', 'note', 'Measured inside the audit frame at desktop width. Layout shift and paint timings differ from a real visit; treat them as indicative.' ) );
		}
		body.push( el( 'p', 'note', 'This is a current-visit diagnostic, not PageSpeed Insights field data or a ranking score. Confirm slow metrics with the recommended PageSpeed check.' ) );
		return this.engineCard( 'Performance', 'How this visit felt', poor ? poor + ' slow' : needsWork ? needsWork + ' to check' : 'Looks good', poor > 0 || needsWork > 0, body );
	};

	/* ---------- saved tools (spec §10.4) ---------- */

	Panel.prototype.renderTools = function () {
		var self = this;
		var panel = this.panels.tools;
		var pageUrl = ( this.page && this.page.url ) || this.o.targetUrl || C.homeUrl || '';
		var manage = C.settingsUrl ? el( 'a', 'text-link', 'Manage' ) : null;
		if ( manage ) { manage.href = C.settingsUrl; }
		panel.textContent = '';
		panel.appendChild( sectionTitle( 'Reusable on every page', 'Your saved SEO tools', manage ) );
		panel.appendChild( el( 'p', 'intro', 'Each link inserts this page or domain before opening. Built-ins and your own links stay available across websites.' ) );
		var groups = el( 'div', 'tool-groups' );
		var used = {};
		var render = function ( name, list ) {
			var source = self.sources[ name ];
			var a = link( 'saved', resolveTool( source.url, pageUrl ) );
			add( a, BUILT_IN[ name ] ? null : el( 'span', 'tag tag--custom', 'Custom' ), el( 'strong', null, name ), el( 'span', null, BUILT_IN[ name ] ? 'Built-in · ready for this page' : 'Your saved tool · ready for this page' ) );
			var arrow = el( 'span', 'arrow', '↗' );
			arrow.setAttribute( 'aria-hidden', 'true' );
			list.appendChild( add( a, arrow ) );
			used[ name ] = true;
		};
		var group = function ( title, names ) {
			if ( ! names.length ) { return; }
			var list = el( 'div', 'saved-list' );
			names.sort( function ( a, b ) { return a.localeCompare( b ); } ).forEach( function ( name ) { render( name, list ); } );
			var section = sectionCard( title, plural( names.length, 'tool ready for this page', 'tools ready for this page' ), null, 'tool-group' );
			section.body.appendChild( list );
			groups.appendChild( section.card );
		};
		this.categories.forEach( function ( category ) {
			group( category.name, Object.keys( self.sources ).filter( function ( name ) { return self.sources[ name ].cat === category.id; } ) );
		} );
		group( 'Uncategorised', Object.keys( this.sources ).filter( function ( name ) { return ! used[ name ]; } ) );
		if ( ! groups.childNodes.length ) {
			groups.appendChild( el( 'p', 'placeholder', tx( 'noTools', 'No saved tools yet. Open Settings to add a page-aware link.' ) ) );
		}
		panel.appendChild( groups );
		panel.appendChild( el( 'p', 'note', 'Opening a tool sends the current URL or domain to that independent service. Nothing is sent until you choose a link.' ) );
	};

	/* ---------- bulk mode ---------- */

	function withAuditArgs( url, nonceUrl ) {
		var target = safeUrl( url );
		var nonce = safeUrl( nonceUrl );
		if ( ! target || ! nonce ) { return url; }
		[ 'opace_eseot_audit', '_wpnonce' ].forEach( function ( key ) {
			if ( nonce.searchParams.has( key ) ) { target.searchParams.set( key, nonce.searchParams.get( key ) ); }
		} );
		return target.href;
	}

	function Bulk( root ) {
		var self = this;
		var ds = root.dataset;
		this.root = root;
		this.targets = ( parseJson( ds.targetUrls ) || [] ).filter( function ( t ) { return t && safeUrl( t.url ); } ).slice( 0, 200 ).map( function ( t ) {
			var postId = num( t.postId || t.post_id );
			var editUrl = str( t.editUrl || t.edit_url, 2000 ) || ( postId > 0 ? location.pathname.replace( /[^/]*$/, 'post.php' ) + '?post=' + postId + '&action=edit' : '' );
			return { url: String( t.url ), title: str( t.title, 200 ), postId: postId, editUrl: editUrl, nonceUrl: str( t.nonceUrl || t.nonce_url, 2000 ) || withAuditArgs( String( t.url ), ds.nonceUrl ) };
		} );
		root.textContent = '';
		root.classList.add( P + 'root', P + 'root--bulk' );
		this.progressText = el( 'p', 'status' );
		this.progressText.setAttribute( 'role', 'status' );
		this.bar = el( 'div', 'bar' );
		this.bar.setAttribute( 'role', 'progressbar' );
		this.bar.setAttribute( 'aria-valuemin', '0' );
		this.bar.setAttribute( 'aria-valuemax', String( this.targets.length ) );
		this.fill = el( 'span', 'bar-fill' );
		this.bar.appendChild( this.fill );
		var table = el( 'table', 'table' );
		table.className += ' widefat striped';
		var head = el( 'tr' );
		[ 'Page', tx( 'pass', 'Pass' ), tx( 'review', 'Review' ), tx( 'info', 'Info' ), 'Top review findings', '' ].forEach( function ( label ) { head.appendChild( el( 'th', null, label ) ); } );
		table.appendChild( add( el( 'thead' ), head ) );
		this.body = el( 'tbody' );
		table.appendChild( this.body );
		this.viewer = makeViewer();
		add( root, add( el( 'div', 'report' ), add( el( 'section', 'page bulk-head' ), eyebrow( 'Bulk page audit' ), this.progressText, this.bar, this.viewer.wrap ), add( el( 'div', 'table-wrap' ), table ) ) );
		this.rows = this.targets.map( function ( target ) { return self.row( target ); } );
	}

	Bulk.prototype.row = function ( target ) {
		var self = this;
		var tr = el( 'tr' );
		var status = el( 'span', 'status-chip', 'Queued' );
		var pageCell = add( el( 'td' ), add( el( 'div', 'page-name' ), el( 'strong', null, target.title || target.url ), status ), el( 'small', 'url', target.url ) );
		var cells = { pass: el( 'td', 'num', '—' ), review: el( 'td', 'num', '—' ), info: el( 'td', 'num', '—' ) };
		var top = el( 'td', 'top', '' );
		var actions = el( 'td', 'row-actions' );
		if ( target.editUrl ) {
			var open = el( 'a', 'text-link', 'Open' );
			open.href = target.editUrl;
			actions.appendChild( open );
		}
		var detailRow = el( 'tr', 'detail-row' );
		detailRow.hidden = true;
		var container = el( 'div' );
		var cell = add( el( 'td' ), container );
		cell.colSpan = 6;
		detailRow.appendChild( cell );
		var panel = new Panel( container, { targetUrl: target.url, nonceUrl: target.nonceUrl, postId: target.postId, title: target.title, embedded: true, lastSummary: null, view: '', autorun: false, viewerHost: self.viewer } );
		var toggle = button( 'text-link', 'Details', function () {
			detailRow.hidden = ! detailRow.hidden;
			toggle.setAttribute( 'aria-expanded', detailRow.hidden ? 'false' : 'true' );
		} );
		toggle.setAttribute( 'aria-expanded', 'false' );
		actions.appendChild( toggle );
		add( tr, pageCell, cells.pass, cells.review, cells.info, top, actions );
		add( self.body, tr, detailRow );
		return { target: target, panel: panel, cells: cells, top: top, status: status };
	};

	Bulk.prototype.start = function () {
		var self = this;
		var index = 0;
		var total = this.rows.length;
		var step = function () {
			if ( index >= total ) {
				self.progressText.textContent = total ? 'Audited ' + plural( total, 'page', 'pages' ) + '.' : 'No pages were selected for auditing.';
				self.progressText.dataset.state = total ? 'success' : '';
				return Promise.resolve();
			}
			var row = self.rows[ index ];
			index += 1;
			self.progressText.textContent = 'Auditing ' + index + ' of ' + total + '…';
			self.progressText.dataset.state = 'loading';
			self.bar.setAttribute( 'aria-valuenow', String( index ) );
			self.fill.style.width = Math.round( ( index / total ) * 100 ) + '%';
			setChip( row.status, 'running', 'Running…' );
			return row.panel.run( 'iframe' ).then( function () {
				if ( row.panel.checks ) {
					var counts = row.panel.counts();
					Object.keys( counts ).forEach( function ( key ) {
						row.cells[ key ].textContent = '';
						row.cells[ key ].appendChild( el( 'span', 'count count--' + key, String( counts[ key ] ) ) );
					} );
					var top = row.panel.lastSummary ? row.panel.lastSummary.top_review : [];
					row.top.textContent = top.length ? top.join( ', ' ) : 'Nothing to review';
					setChip( row.status, 'done', 'Done' );
				} else {
					row.top.textContent = row.panel.n.status.textContent || 'Audit failed';
					setChip( row.status, 'failed', 'Failed' );
				}
				return step();
			} );
		};
		return step();
	};

	/* ---------- bootstrap ---------- */

	var instances = [];

	function mount( root ) {
		if ( root.dataset.opaceEseotAuditReady ) { return; }
		root.dataset.opaceEseotAuditReady = '1';
		if ( root.dataset.mode === 'bulk' ) {
			var bulk = new Bulk( root );
			instances.push( bulk );
			bulk.start();
			return;
		}
		var panel = new Panel( root );
		instances.push( panel );
		var params = safeUrl( location.href );
		var wantsRun = panel.o.autorun || location.hash === '#opace-eseot-run' || ( params && params.searchParams.get( 'autorun' ) === '1' );
		/* Autorun stays inside whatever box state the user left; only the sidebar launcher (run()) opens the drawer. */
		if ( wantsRun && panel.o.targetUrl ) {
			panel.run( 'iframe' );
		}
	}

	function init( scope ) {
		Array.from( ( scope || D ).querySelectorAll( '.opace-eseot-audit' ) ).forEach( mount );
	}

	function run( root ) {
		var panel = root ? instances.filter( function ( i ) { return i.root === root; } )[ 0 ] : instances.filter( function ( i ) { return i instanceof Panel; } )[ 0 ];
		if ( ! panel || ! ( panel instanceof Panel ) ) { return Promise.resolve(); }
		revealMetaBoxes( panel.root );
		panel.root.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		return panel.run( 'iframe' );
	}

	/**
	 * The block editor keeps normal-context meta boxes in a collapsed drawer and the
	 * audit postbox is closed by default. Open both so the panel is visible.
	 * Called only from the sidebar launcher, never on page load.
	 */
	function revealMetaBoxes( root ) {
		var drawerToggle = D.querySelector( '.edit-post-meta-boxes-main button[aria-expanded="false"]' );
		if ( drawerToggle ) { drawerToggle.click(); }
		var postbox = root.closest( '.postbox' );
		if ( postbox && postbox.classList.contains( 'closed' ) ) {
			var handle = postbox.querySelector( '.handlediv, .postbox-header button' );
			if ( handle ) { handle.click(); }
		}
	}

	function boot() {
		init( D );
		D.addEventListener( 'click', function ( event ) {
			var launcher = event.target.closest && event.target.closest( 'a[href="#opace-eseot-audit"], .opace-eseot-run-audit-link' );
			if ( launcher ) {
				event.preventDefault();
				run();
			}
		} );
		if ( 'MutationObserver' in W && D.body ) {
			new MutationObserver( function ( mutations ) {
				mutations.forEach( function ( mutation ) {
					Array.from( mutation.addedNodes ).forEach( function ( node ) {
						if ( node.nodeType !== 1 ) { return; }
						if ( node.matches( '.opace-eseot-audit' ) ) { mount( node ); } else if ( node.querySelector( '.opace-eseot-audit' ) ) { init( node ); }
					} );
				} );
			} ).observe( D.body, { childList: true, subtree: true } );
		}
	}

	W.opaceEseotAuditPanel = { init: init, run: run };

	if ( D.readyState === 'loading' ) {
		D.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
