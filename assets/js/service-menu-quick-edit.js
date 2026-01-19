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
		var $dataEl = $row.find( '.vkbm-service-menu-qe' ).first();

		if ( ! $dataEl.length || ! $editRow.length ) {
			return;
		}

			var data = $dataEl.data();

			$editRow.find( 'input.vkbm-qe-base-price' ).val( data.basePrice !== undefined ? data.basePrice : '' );
			$editRow.find( 'input.vkbm-qe-duration-minutes' ).val( data.durationMinutes !== undefined ? data.durationMinutes : '' );
			$editRow.find( 'input.vkbm-qe-buffer-after-minutes' ).val( data.bufferAfterMinutes !== undefined ? data.bufferAfterMinutes : '' );
			$editRow.find( 'input.vkbm-qe-reservation-deadline-hours' ).val( data.reservationDeadlineHours !== undefined ? data.reservationDeadlineHours : '' );
			$editRow.find( 'select.vkbm-qe-reservation-day-type' ).val( data.reservationDayType !== undefined ? data.reservationDayType : '' );
			$editRow.find( 'input.vkbm-qe-disable-nomination-fee' ).prop( 'checked', data.disableNominationFee === '1' || data.disableNominationFee === 1 || data.disableNominationFee === true );

			var staffIds = data.staffIds !== undefined ? data.staffIds : [];
			if ( typeof staffIds === 'string' ) {
				try {
					staffIds = JSON.parse( staffIds );
				} catch ( e ) {
					staffIds = [];
				}
			}
			if ( ! Array.isArray( staffIds ) ) {
				staffIds = [];
			}
			staffIds = staffIds.map( function( id ) {
				return String( id );
			} );

			var otherConditions = data.otherConditions !== undefined ? data.otherConditions : '';
			if ( typeof otherConditions === 'string' ) {
				try {
					otherConditions = JSON.parse( otherConditions );
				} catch ( e ) {
					// Keep as-is if it is not JSON.
				}
			}
			if ( otherConditions === undefined || otherConditions === null ) {
				otherConditions = '';
			}

			$editRow.find( 'input.vkbm-qe-staff-id' ).prop( 'checked', false );
			staffIds.forEach( function( id ) {
				$editRow
					.find( 'input.vkbm-qe-staff-id[value="' + id.replace( /"/g, '\\"' ) + '"]' )
					.prop( 'checked', true );
			} );
			$editRow.find( 'textarea.vkbm-qe-other-conditions' ).val( otherConditions );
		};
	} )( jQuery );
