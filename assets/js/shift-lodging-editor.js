( function ( $ ) {
	'use strict';

	// 宿泊のときのシフト編集画面（販売日の登録）。
	// 日ごとの「販売する」のチェックだけを持ち、保存時の状態への変換は PHP 側で行う。
	const config = window.vkbmShiftLodgingEditor || {};

	const $daysJsonField = $( config.daysJsonField );
	const $daysTableBody = $( config.daysTableBody );
	const $yearSelect = $( config.yearSelector );
	const $monthSelect = $( config.monthSelector );
	const $resourceSelect = $( config.resourceSelector );
	const $bulkStatus = $( config.bulkStatus );
	const $bulkButtons = $( config.bulkOnSelector ).add(
		$( config.bulkOffSelector )
	);
	const rowTemplate = $( config.rowTemplate ).html() || '';
	const strings = config.strings || {};
	const weekdayKeys = [ 'sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat' ];

	// 画面を開いた時点の客室・年月と、その月の販売日。同じ月へ戻したときに保存済みの状態を復元する。
	const initialState = {
		resource: parseInt( config.initialResource, 10 ) || 0,
		year: parseInt( config.initialYear, 10 ) || 0,
		month: parseInt( config.initialMonth, 10 ) || 0,
		flags: normalizeFlags( config.flags || {} ),
	};

	let flags = Object.assign( {}, initialState.flags );
	let requestCounter = 0;
	// 初期値の取得中は true。表の内容が前の月のままのため、保存用の値は送らない。
	let isLoading = false;

	function normalizeFlags( source ) {
		const normalized = {};

		Object.keys( source ).forEach( ( key ) => {
			const day = parseInt( key, 10 );
			if ( ! Number.isNaN( day ) ) {
				normalized[ day ] = !! source[ key ];
			}
		} );

		return normalized;
	}

	// 販売する日の状態（open / temporary_open）を、日 => 販売するか に変換する。
	// 販売日の規則は PHP の Shift_Sale_Days::SALE_STATUSES と合わせること（片方だけ変えない）。
	function flagsFromDays( days ) {
		const result = {};

		Object.keys( days || {} ).forEach( ( key ) => {
			const day = parseInt( key, 10 );
			const status = days[ key ] && days[ key ].status;
			if ( ! Number.isNaN( day ) ) {
				result[ day ] =
					'open' === status || 'temporary_open' === status;
			}
		} );

		return result;
	}

	function getSelectedPeriod() {
		return {
			resource: parseInt( $resourceSelect.val(), 10 ) || 0,
			year: parseInt( $yearSelect.val(), 10 ) || 0,
			month: parseInt( $monthSelect.val(), 10 ) || 0,
		};
	}

	function formatDateLabel( year, month, day ) {
		const weekday = new Date( year, month - 1, day ).getDay();
		const weekdayLabel =
			strings.weekdayShort?.[ weekdayKeys[ weekday ] ] || '';

		return ( strings.dateFormat || '%1$d/%2$d (%3$s)' )
			.replace( '%1$d', month )
			.replace( '%2$d', day )
			.replace( '%3$s', weekdayLabel );
	}

	// 行の見た目（背景色と「販売しない」の文字）を、チェックの状態に合わせる。
	function applyRowState( $row, onSale ) {
		$row.toggleClass( 'is-off-sale', ! onSale );
		$row.find( '.vkbm-shift-lodging-off-label' ).prop( 'hidden', onSale );
	}

	function renderDays() {
		const { year, month } = getSelectedPeriod();
		const daysInMonth =
			year && month ? new Date( year, month, 0 ).getDate() : 0;

		$daysTableBody.empty();

		for ( let day = 1; day <= daysInMonth; day++ ) {
			const $row = $( rowTemplate.replace( /__DAY__/g, String( day ) ) );
			const onSale = true === flags[ day ];

			$row.find( '.vkbm-shift-lodging-date' ).text(
				formatDateLabel( year, month, day )
			);
			$row.find( '.vkbm-shift-lodging-sale' ).prop( 'checked', onSale );
			applyRowState( $row, onSale );
			$daysTableBody.append( $row );
		}
	}

	// 画面のチェックの状態を保存用の隠し項目へ書き込む。
	function syncHiddenField() {
		// 取得中は空にし、サーバー側が初期値から組み立てるようにする（前の月の値を新しい月に保存しない）。
		if ( isLoading ) {
			$daysJsonField.val( '' );
			return;
		}

		const result = {};

		$daysTableBody.find( '.vkbm-shift-lodging-row' ).each( function () {
			const $row = $( this );
			const day = parseInt( $row.data( 'day' ), 10 );
			result[ day ] = $row
				.find( '.vkbm-shift-lodging-sale' )
				.prop( 'checked' )
				? 1
				: 0;
		} );

		flags = normalizeFlags( result );
		$daysJsonField.val( JSON.stringify( result ) );
	}

	// 読み上げの対象（aria-live の領域）へ、文を半角スペースで区切って表示する。
	function showMessages( messages ) {
		$bulkStatus.empty();
		messages
			.filter( ( message ) => !! message )
			.forEach( ( message, index ) => {
				if ( index > 0 ) {
					$bulkStatus.append( document.createTextNode( ' ' ) );
				}
				$( '<span>' ).text( message ).appendTo( $bulkStatus );
			} );
	}

	// 取得中は一括ボタンを押せなくする（表が空で、押しても何も変わらないため）。
	function setLoadingState( loading ) {
		isLoading = loading;
		$bulkButtons.prop( 'disabled', loading );
		$daysTableBody
			.closest( 'table' )
			.attr( 'aria-busy', loading ? 'true' : null );
	}

	function setAll( onSale ) {
		if ( isLoading ) {
			return;
		}

		$daysTableBody.find( '.vkbm-shift-lodging-row' ).each( function () {
			const $row = $( this );
			$row.find( '.vkbm-shift-lodging-sale' ).prop( 'checked', onSale );
			applyRowState( $row, onSale );
		} );

		syncHiddenField();
		showMessages( [
			onSale ? strings.bulkOnDone : strings.bulkOffDone,
			strings.notApplied,
		] );
	}

	function applyFlags( newFlags, messages ) {
		setLoadingState( false );
		flags = newFlags;
		renderDays();
		syncHiddenField();
		showMessages( messages || [] );
	}

	// 客室・年・月が変わったとき、その月の販売日を初期値へ戻す（画面を開いた月へ戻したときは保存済みの状態）。
	function loadPeriod() {
		const period = getSelectedPeriod();
		const requestId = ++requestCounter;

		if (
			period.resource === initialState.resource &&
			period.year === initialState.year &&
			period.month === initialState.month
		) {
			applyFlags( Object.assign( {}, initialState.flags ), [
				strings.savedShown,
				strings.notApplied,
			] );
			return;
		}

		// 客室が未選択のときは初期値を取得できないため、全日「販売しない」から始め、理由を知らせる。
		if ( ! period.resource || ! config.ajax?.url || ! config.ajax?.nonce ) {
			applyFlags( {}, [ strings.noResource, strings.allOffSale ] );
			return;
		}

		// 取得中は表を空にして、前の月の内容が残らないようにする。読み上げは aria-live の領域が担うため、表の中の表示は読み上げ対象から外す。
		setLoadingState( true );
		$daysTableBody
			.empty()
			.append(
				$( '<tr>', { 'aria-hidden': 'true' } ).append(
					$( '<td>', { colspan: 2 } ).text( strings.loadingRow )
				)
			);
		syncHiddenField();
		showMessages( [ strings.loading ] );

		$.post( config.ajax.url, {
			action: 'vkbm_shift_get_template',
			resource_id: period.resource,
			year: period.year,
			month: period.month,
			_ajax_nonce: config.ajax.nonce,
		} )
			.done( ( response ) => {
				// 後から変更された選択が優先されるよう、古い応答は捨てる。
				if ( requestId !== requestCounter ) {
					return;
				}

				if ( response && response.success && response.data ) {
					applyFlags( flagsFromDays( response.data.days || {} ), [
						strings.resetDone,
						strings.notApplied,
					] );
					return;
				}

				applyFlags( {}, [ strings.loadFailed, strings.allOffSale ] );
			} )
			.fail( () => {
				if ( requestId === requestCounter ) {
					applyFlags( {}, [
						strings.loadFailed,
						strings.allOffSale,
					] );
				}
			} );
	}

	function init() {
		if ( ! rowTemplate ) {
			return;
		}

		renderDays();
		syncHiddenField();
	}

	$( document ).on( 'change', '.vkbm-shift-lodging-sale', function () {
		applyRowState(
			$( this ).closest( '.vkbm-shift-lodging-row' ),
			$( this ).prop( 'checked' )
		);
		syncHiddenField();
	} );

	$( document ).on( 'click', config.bulkOnSelector || '', () => {
		setAll( true );
	} );

	$( document ).on( 'click', config.bulkOffSelector || '', () => {
		setAll( false );
	} );

	$yearSelect.on( 'change', loadPeriod );
	$monthSelect.on( 'change', loadPeriod );
	$resourceSelect.on( 'change', loadPeriod );

	$( '#post' ).on( 'submit', syncHiddenField );

	$( init );
} )( jQuery );
