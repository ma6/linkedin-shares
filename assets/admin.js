/**
 * Review-table helpers: a working "select all" that ignores disabled
 * (already-imported) rows and stays in sync when rows are toggled by hand.
 */
( function () {
	'use strict';

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	ready( function () {
		var master = document.getElementById( 'lsi-check-all' );
		var form = document.getElementById( 'lsi-review' );
		if ( ! master || ! form ) {
			return;
		}

		function pickable() {
			return Array.prototype.slice.call(
				form.querySelectorAll( '.lsi-pick:not(:disabled)' )
			);
		}

		function syncMaster() {
			var boxes = pickable();
			master.checked = boxes.length > 0 && boxes.every( function ( b ) {
				return b.checked;
			} );
		}

		master.addEventListener( 'change', function () {
			pickable().forEach( function ( b ) {
				b.checked = master.checked;
			} );
		} );

		form.addEventListener( 'change', function ( e ) {
			if ( e.target && e.target.classList.contains( 'lsi-pick' ) ) {
				syncMaster();
			}
		} );

		syncMaster();
	} );
}() );
