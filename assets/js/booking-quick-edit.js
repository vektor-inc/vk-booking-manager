( function ( $ ) {
	'use strict';

	if ( typeof inlineEditPost === 'undefined' ) {
		return;
	}

	const wpInlineEdit = inlineEditPost.edit;

	inlineEditPost.edit = function ( id ) {
		wpInlineEdit.apply( this, arguments );

		let postId = 0;
		if ( typeof id === 'object' ) {
			postId = parseInt( this.getId( id ), 10 );
		} else {
			postId = parseInt( id, 10 );
		}

		if ( ! postId ) {
			return;
		}

		const $row = $( '#post-' + postId );
		const $editRow = $( '#edit-' + postId );
		const $dataEl = $row.find( '.vkbm-booking-qe' ).first();

		if ( ! $dataEl.length || ! $editRow.length ) {
			return;
		}

		const data = $dataEl.data();
		const status = data.status !== undefined ? data.status : '';
		$editRow.find( 'select.vkbm-qe-booking-status' ).val( status );
	};
} )( jQuery );
