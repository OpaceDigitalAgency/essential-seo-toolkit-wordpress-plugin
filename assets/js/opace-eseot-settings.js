/**
 * Opace Essential SEO Toolkit: settings screen.
 * Removes rows with a "saved on submit" notice and previews a new source URL
 * with sample values. Validation itself happens on the server.
 */
( function () {
	'use strict';

	var SAMPLE_URL = 'https://www.example.com/blog/post/';
	var SAMPLE = {
		url: SAMPLE_URL,
		url_encoded: encodeURIComponent( SAMPLE_URL ),
		host: 'example.com',
		host_encoded: encodeURIComponent( 'example.com' ),
		scheme: 'https://',
		path: '/blog/post/'
	};
	var TOKEN = /\[%([^%\]]*)%\]/g;

	function text( element, attribute, fallback ) {
		return ( element && element.getAttribute( attribute ) ) || fallback;
	}

	/* Dismissible info notice at the top of a wrapper. */
	function notice( wrapper, message ) {
		var box = document.createElement( 'div' );
		var p = document.createElement( 'p' );
		var dismiss = document.createElement( 'button' );
		var label = document.createElement( 'span' );

		box.className = 'notice notice-info is-dismissible opace-eseot-notice';
		p.textContent = message;
		dismiss.type = 'button';
		dismiss.className = 'notice-dismiss';
		label.className = 'screen-reader-text';
		label.textContent = text( wrapper, 'data-dismiss-label', 'Dismiss this notice.' );
		dismiss.appendChild( label );
		dismiss.addEventListener( 'click', function () {
			box.remove();
		} );
		box.appendChild( p );
		box.appendChild( dismiss );
		wrapper.insertBefore( box, wrapper.firstChild );
	}

	function removeRow( button ) {
		var row = button.closest( 'tr' ) || button.closest( 'li' );
		var wrapper = button.closest( '.opace-eseot-sources-wrapper, .opace-eseot-categories-wrapper' );
		if ( ! row ) {
			return;
		}
		var cell = row.querySelector( 'td' );
		var name = text( button, 'data-name', cell ? cell.textContent.trim() : '' );
		var message = text( button, 'data-notice', null ) ||
			( name ? name + ' will be removed when you save your changes.' : 'This item will be removed when you save your changes.' );

		row.remove();
		notice( wrapper || document.querySelector( '.opace-eseot-settings' ) || document.body, message );
	}

	/* Live preview: substitute sample values and flag unknown placeholders. */
	function renderPreview( input, preview ) {
		var template = input.value.trim();
		var last = 0;
		var invalid = false;
		var match;
		var frag = document.createDocumentFragment();
		var label = document.createElement( 'span' );

		preview.textContent = '';
		preview.classList.remove( 'opace-eseot-preview--invalid' );
		preview.hidden = ! template;
		if ( ! template ) {
			return;
		}

		label.className = 'opace-eseot-preview__label';
		label.textContent = text( input, 'data-preview-label', 'Preview:' ) + ' ';
		frag.appendChild( label );

		TOKEN.lastIndex = 0;
		while ( ( match = TOKEN.exec( template ) ) !== null ) {
			frag.appendChild( document.createTextNode( template.slice( last, match.index ) ) );
			if ( Object.prototype.hasOwnProperty.call( SAMPLE, match[ 1 ] ) ) {
				frag.appendChild( document.createTextNode( SAMPLE[ match[ 1 ] ] ) );
			} else {
				var token = document.createElement( 'span' );
				token.className = 'opace-eseot-preview__token';
				token.textContent = match[ 0 ];
				frag.appendChild( token );
				invalid = true;
			}
			last = TOKEN.lastIndex;
		}
		frag.appendChild( document.createTextNode( template.slice( last ) ) );

		if ( invalid ) {
			var warning = document.createElement( 'span' );
			warning.className = 'opace-eseot-preview__warning';
			warning.textContent = text( input, 'data-invalid-text', 'Unknown placeholder. Only [%url%], [%url_encoded%], [%host%], [%host_encoded%], [%scheme%] and [%path%] are supported.' );
			frag.appendChild( warning );
			preview.classList.add( 'opace-eseot-preview--invalid' );
		}
		preview.appendChild( frag );
	}

	function initPreview() {
		var input = document.getElementById( 'opace-eseot-source-url' );
		if ( ! input ) {
			return;
		}
		var preview = document.getElementById( 'opace-eseot-source-preview' );
		if ( ! preview ) {
			preview = document.createElement( 'p' );
			preview.id = 'opace-eseot-source-preview';
			preview.className = 'opace-eseot-preview';
			preview.setAttribute( 'aria-live', 'polite' );
			preview.hidden = true;
			input.insertAdjacentElement( 'afterend', preview );
		}
		var described = input.getAttribute( 'aria-describedby' );
		input.setAttribute( 'aria-describedby', described ? described + ' ' + preview.id : preview.id );

		var run = function () {
			renderPreview( input, preview );
		};
		input.addEventListener( 'input', run );
		input.addEventListener( 'change', run );
		run();
	}

	function boot() {
		document.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '[data-action="remove-source"], [data-action="remove-category"]' );
			if ( button ) {
				event.preventDefault();
				removeRow( button );
			}
		} );
		initPreview();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
