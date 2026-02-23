( function () {
	'use strict';

	function movePanel() {
		const panel = document.querySelector( '.vkbm-shift-bulk-create' );
		if ( ! panel ) {
			return;
		}

		const wrap = document.querySelector( '.wrap' );
		if ( ! wrap ) {
			return;
		}

		const headerEnd = wrap.querySelector( '.wp-header-end' );
		if ( headerEnd ) {
			wrap.insertBefore( panel, headerEnd );
			return;
		}

		wrap.insertBefore( panel, wrap.firstChild );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', movePanel );
	} else {
		movePanel();
	}
} )();
