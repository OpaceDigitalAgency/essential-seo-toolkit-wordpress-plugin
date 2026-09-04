/**
 * Opace Essential SEO Toolkit: URL template resolver (browser port of Opace_ESEOT_Link_Resolver::resolve).
 * Placeholders: [%url%] [%url_encoded%] [%host%] [%host_encoded%] [%scheme%] [%path%].
 * Exposes window.opaceEseotResolver = { resolve(template, url), placeholders, isWellFormedTemplate(template) }.
 */
( function () {
	'use strict';

	var PLACEHOLDERS = [ 'url', 'url_encoded', 'host', 'host_encoded', 'scheme', 'path' ];
	var TOKEN = /\[%(url|url_encoded|host|host_encoded|scheme|path)%\]/g;

	function parse( value ) {
		var parsed;
		try {
			parsed = new URL( String( value || '' ).trim() );
		} catch ( error ) {
			return null;
		}
		if ( ! /^https?:$/.test( parsed.protocol ) || ! parsed.hostname ) {
			return null;
		}
		return parsed;
	}

	/* Placeholder values for a page URL, or null when it is not a usable http(s) URL. */
	function parts( pageUrl ) {
		var parsed = parse( pageUrl );
		if ( ! parsed ) {
			return null;
		}
		// Chrome strips a leading www. from the host, so the WordPress side does too.
		var host = parsed.hostname.toLowerCase().replace( /^www\./, '' );
		return {
			url: parsed.href,
			url_encoded: encodeURIComponent( parsed.href ),
			host: host,
			host_encoded: encodeURIComponent( host ),
			scheme: parsed.protocol + '//',
			path: ( parsed.pathname || '/' ) + ( parsed.search || '' )
		};
	}

	/* http(s) scheme and only known [% %] tokens. No network access. */
	function isWellFormedTemplate( template ) {
		template = String( template || '' ).trim();
		if ( ! /^https?:\/\//i.test( template ) ) {
			return false;
		}
		return ! /\[%[^%]*%\]/.test( template.replace( TOKEN, '' ) );
	}

	/* Single-pass substitution so substituted values are never re-scanned. */
	function substitute( template, values ) {
		return template.replace( TOKEN, function ( match, name ) {
			return values[ name ];
		} );
	}

	function resolve( template, pageUrl ) {
		if ( typeof template !== 'string' ) {
			return null;
		}
		template = template.trim();
		if ( ! isWellFormedTemplate( template ) ) {
			return null;
		}
		var values = parts( pageUrl );
		if ( ! values ) {
			return null;
		}
		var link = substitute( template, values );
		return parse( link ) ? link : null;
	}

	window.opaceEseotResolver = {
		resolve: resolve,
		placeholders: PLACEHOLDERS.slice(),
		isWellFormedTemplate: isWellFormedTemplate
	};
}() );
