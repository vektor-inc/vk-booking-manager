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
		const $dataEl = $row.find( '.vkbm-service-menu-qe' ).first();

		if ( ! $dataEl.length || ! $editRow.length ) {
			return;
		}

		const data = $dataEl.data();

		$editRow
			.find( 'input.vkbm-qe-base-price' )
			.val( data.basePrice !== undefined ? data.basePrice : '' );
		$editRow
			.find( 'input.vkbm-qe-duration-minutes' )
			.val(
				data.durationMinutes !== undefined ? data.durationMinutes : ''
			);
		$editRow
			.find( 'input.vkbm-qe-buffer-after-minutes' )
			.val(
				data.bufferAfterMinutes !== undefined
					? data.bufferAfterMinutes
					: ''
			);
		$editRow
			.find( 'input.vkbm-qe-reservation-deadline-hours' )
			.val(
				data.reservationDeadlineHours !== undefined
					? data.reservationDeadlineHours
					: ''
			);
		const $reservationDayType = $editRow.find(
			'select.vkbm-qe-reservation-day-type'
		);
		const reservationDayType =
			data.reservationDayType !== undefined ? data.reservationDayType : '';
		// 曜日指定/日付指定は既定で無効化してある（詳細設定が必要でクイック編集では設定できないため）。
		// WordPress のクイック編集は行 DOM を投稿間で使い回すため、前回 custom 投稿で有効化した
		// option が残らないよう、毎回まず両 option を無効化へリセットする。そのうえで現在値が
		// custom のメニューでのみ該当の選択肢を有効化して既存値を選択・保持できるようにする。
		// 詳細未設定のメニューでは無効のままにし、新規選択→保存時の無通知リセットを防ぐ。
		$reservationDayType
			.find(
				'option[value="custom_weekday"], option[value="custom_date"]'
			)
			.prop( 'disabled', true );
		if (
			reservationDayType === 'custom_weekday' ||
			reservationDayType === 'custom_date'
		) {
			$reservationDayType
				.find( 'option[value="' + reservationDayType + '"]' )
				.prop( 'disabled', false );
		}
		$reservationDayType.val( reservationDayType );
		$editRow
			.find( 'input.vkbm-qe-disable-nomination-fee' )
			.prop(
				'checked',
				data.disableNominationFee === '1' ||
					data.disableNominationFee === 1 ||
					data.disableNominationFee === true
			);

		let staffIds = data.staffIds !== undefined ? data.staffIds : [];
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
		staffIds = staffIds.map( function ( id ) {
			return String( id );
		} );

		let otherConditions =
			data.otherConditions !== undefined ? data.otherConditions : '';
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
		staffIds.forEach( function ( id ) {
			$editRow
				.find(
					'input.vkbm-qe-staff-id[value="' +
						id.replace( /"/g, '\\"' ) +
						'"]'
				)
				.prop( 'checked', true );
		} );
		$editRow
			.find( 'textarea.vkbm-qe-other-conditions' )
			.val( otherConditions );
	};
} )( jQuery );
