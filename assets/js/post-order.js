( function ( window, document, $ ) {
	'use strict';

	const config = window.vkbmPostOrder || {};

	if ( ! config.postType || ! config.ajaxUrl || ! config.nonce ) {
		return;
	}

	const $table = $( '#the-list' );

	if ( ! $table.length ) {
		return;
	}

	const $rows = $table.children( 'tr' ).not( '.inline-edit-row, .no-items' );

	if ( $rows.length < 1 ) {
		return;
	}

	const state = {
		saving: false,
		pendingSave: false,
	};

	const $status = $( '<span class="vkbm-post-order-status" aria-live="polite"></span>' );
	const $tablenav = $( '.tablenav.top .actions.bulkactions' ).first();

	if ( $tablenav.length ) {
		$tablenav.append( $status );
	}

	function setStatus( message, type = '' ) {
		$status.text( message || '' );
		$status.removeClass( 'is-error is-success' );

		if ( 'error' === type ) {
			$status.addClass( 'is-error' );
		} else if ( 'success' === type ) {
			$status.addClass( 'is-success' );
		}
	}

	function captureInitialState() {
		$table
			.children( 'tr' )
			.not( '.inline-edit-row, .no-items' )
			.each( function ( index, row ) {
				const $row = $( row );
				const idAttr = $row.attr( 'id' );
				if ( idAttr && ! $row.attr( 'data-post-id' ) ) {
					$row.attr( 'data-post-id', idAttr.replace( 'post-', '' ) );
				}
				$row.attr( 'data-original-index', index );
			} );
	}

	function markDirtyRows() {
		let dirtyRows = 0;
		$table
			.children( 'tr' )
			.not( '.inline-edit-row, .no-items' )
			.each( function ( index, row ) {
				const $row = $( row );
				const originalIndex = parseInt( $row.attr( 'data-original-index' ), 10 );
				if ( originalIndex !== index ) {
					$row.addClass( 'vkbm-post-order-row--dirty' );
					dirtyRows += 1;
				} else if ( ! state.saving && ! state.pendingSave ) {
					$row.removeClass( 'vkbm-post-order-row--dirty' );
				}
			} );

		return dirtyRows > 0;
	}

	function resetOriginalIndexes() {
		$table
			.children( 'tr' )
			.not( '.inline-edit-row, .no-items' )
			.each( function ( index, row ) {
				const $row = $( row );
				$row.attr( 'data-original-index', index );
				$row.removeClass( 'vkbm-post-order-row--dirty' );
			} );
	}

	function fixHelper( event, ui ) {
		ui.children().each( function () {
			const $cell = $( this );
			$cell.width( $cell.width() );
		} );
		return ui;
	}

	function collectOrderedIds() {
		const ids = [];
		$table
			.children( 'tr' )
			.not( '.inline-edit-row, .no-items' )
			.each( function ( index, row ) {
				const id = parseInt( $( row ).attr( 'data-post-id' ), 10 );
				if ( id ) {
					ids.push( id );
				}
			} );

		return ids;
	}

	function saveOrder() {
		state.saving = true;
		setStatus( config.i18n.saving );

		$.ajax( {
			method: 'POST',
			url: config.ajaxUrl,
			data: {
				action: config.action,
				nonce: config.nonce,
				postType: config.postType,
				orderedIds: collectOrderedIds(),
			},
		} )
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					setStatus( config.i18n.error, 'error' );
					return;
				}

				if ( ! state.pendingSave ) {
					resetOriginalIndexes();
					setStatus( config.i18n.saved, 'success' );
				}
			} )
			.fail( function () {
				setStatus( config.i18n.error, 'error' );
			} )
			.always( function () {
				state.saving = false;
				if ( state.pendingSave ) {
					state.pendingSave = false;
					saveOrder();
				}
			} );
	}

	function queueSave() {
		if ( state.saving ) {
			state.pendingSave = true;
			return;
		}

		saveOrder();
	}

	function initSortable() {
		$table.sortable( {
			items: '> tr:not(.inline-edit-row, .no-items)',
			handle: '.row-title',
			axis: 'y',
			helper: fixHelper,
			distance: 5,
			update: function () {
				const hasChanges = markDirtyRows();
				if ( hasChanges ) {
					queueSave();
				}
			},
		} );
	}

	captureInitialState();
	initSortable();
} )( window, document, jQuery );
