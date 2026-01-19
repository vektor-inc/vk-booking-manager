( function() {
	'use strict';

	function movePanel() {
		var panel = document.querySelector( '.vkbm-shift-bulk-create' );
		if ( ! panel ) {
			return;
		}

		var wrap = document.querySelector( '.wrap' );
		if ( ! wrap ) {
			return;
		}

		var headerEnd = wrap.querySelector( '.wp-header-end' );
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
}() );

