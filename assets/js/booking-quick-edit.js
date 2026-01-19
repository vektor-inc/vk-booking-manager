( function ( $ ) {
	'use strict';

	if ( typeof inlineEditPost === 'undefined' ) {
		return;
	}

	var wpInlineEdit = inlineEditPost.edit;

	inlineEditPost.edit = function ( id ) {
		wpInlineEdit.apply( this, arguments );

		var postId = 0;
		if ( typeof id === 'object' ) {
			postId = parseInt( this.getId( id ), 10 );
		} else {
			postId = parseInt( id, 10 );
		}

		if ( ! postId ) {
			return;
		}

		var $row = $( '#post-' + postId );
		var $editRow = $( '#edit-' + postId );
		var $dataEl = $row.find( '.vkbm-booking-qe' ).first();

		if ( ! $dataEl.length || ! $editRow.length ) {
			return;
		}

		var data = $dataEl.data();
		var status = data.status !== undefined ? data.status : '';
		$editRow.find( 'select.vkbm-qe-booking-status' ).val( status );
	};
} )( jQuery );
