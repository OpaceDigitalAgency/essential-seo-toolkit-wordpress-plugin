/**
 * Opace Essential SEO Toolkit: editor meta box.
 * Accordion toggles plus live search over categories and saved tools.
 * Works when the meta box is injected late (block editor) via a MutationObserver.
 */
( function () {
	'use strict';

	var ROOT = '.opace-eseot-metabox';
	var READY = 'opaceEseotReady';

	function normalise( text ) {
		return ( text || '' ).trim().toLowerCase();
	}

	function isOpen( item ) {
		var button = item.querySelector( '.opace-eseot-toggle' );
		return !! button && button.getAttribute( 'aria-expanded' ) === 'true';
	}

	function setOpen( item, open ) {
		var button = item.querySelector( '.opace-eseot-toggle' );
		var panel = item.querySelector( '.opace-eseot-sources' );
		if ( ! button || ! panel ) {
			return;
		}
		button.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		panel.hidden = ! open;
		item.classList.toggle( 'is-open', open );
	}

	/* Filter categories and sources by the current search term. */
	function filter( box, state ) {
		var term = normalise( box.search.value );
		var anyMatch = ! term;

		if ( term && ! state.saved ) {
			// Remember which categories were open before searching started.
			state.saved = box.items.map( isOpen );
		}

		box.items.forEach( function ( item, index ) {
			var toggle = item.querySelector( '.opace-eseot-toggle' );
			var sources = Array.from( item.querySelectorAll( '.opace-eseot-source' ) );

			if ( ! term ) {
				sources.forEach( function ( source ) {
					source.hidden = false;
				} );
				item.hidden = false;
				if ( state.saved ) {
					setOpen( item, state.saved[ index ] );
				}
				return;
			}

			var categoryMatch = toggle && normalise( toggle.textContent ).indexOf( term ) !== -1;
			var sourceMatch = false;

			sources.forEach( function ( source ) {
				var hit = categoryMatch || normalise( source.textContent ).indexOf( term ) !== -1;
				source.hidden = ! hit;
				sourceMatch = sourceMatch || hit;
			} );

			item.hidden = ! ( categoryMatch || sourceMatch );
			if ( ! item.hidden ) {
				setOpen( item, true );
				anyMatch = true;
			}
		} );

		if ( ! term ) {
			state.saved = null;
		}
		if ( box.empty ) {
			box.empty.hidden = anyMatch;
		}
	}

	function init( root ) {
		if ( root.dataset[ READY ] ) {
			return;
		}
		root.dataset[ READY ] = '1';

		var box = {
			search: root.querySelector( '.opace-eseot-search' ),
			items: Array.from( root.querySelectorAll( '.opace-eseot-category' ) ),
			empty: root.querySelector( '.opace-eseot-empty' )
		};
		var state = { saved: null };

		// Sync classes and panels with whatever aria-expanded the server rendered.
		box.items.forEach( function ( item ) {
			setOpen( item, isOpen( item ) );
		} );

		root.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '.opace-eseot-toggle' );
			var item = button && button.closest( '.opace-eseot-category' );
			if ( ! item || ! root.contains( item ) ) {
				return;
			}
			event.preventDefault();
			setOpen( item, ! isOpen( item ) );
		} );

		if ( ! box.search ) {
			return;
		}
		var run = function () {
			filter( box, state );
		};
		box.search.addEventListener( 'input', run );
		box.search.addEventListener( 'search', run );
		box.search.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' && box.search.value ) {
				event.preventDefault();
				box.search.value = '';
				run();
			}
		} );
		if ( box.search.value ) {
			run();
		}
	}

	function scan( scope ) {
		Array.from( scope.querySelectorAll( ROOT ) ).forEach( init );
	}

	function boot() {
		scan( document );

		if ( ! ( 'MutationObserver' in window ) || ! document.body ) {
			return;
		}
		// The block editor mounts meta boxes after the page has loaded.
		new MutationObserver( function ( mutations ) {
			mutations.forEach( function ( mutation ) {
				Array.from( mutation.addedNodes ).forEach( function ( node ) {
					if ( node.nodeType !== 1 ) {
						return;
					}
					if ( node.matches( ROOT ) ) {
						init( node );
					} else if ( node.id === 'opace-eseot-toolkit' || node.querySelector( ROOT ) ) {
						scan( node );
					}
				} );
			} );
		} ).observe( document.body, { childList: true, subtree: true } );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
