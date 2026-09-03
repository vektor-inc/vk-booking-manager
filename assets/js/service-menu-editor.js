( function ( $ ) {
	'use strict';

	// i18n strings localized via wp_localize_script. / wp_localize_script で渡された翻訳文字列.
	const i18n =
		window.vkbmServiceMenuEditor && window.vkbmServiceMenuEditor.i18n
			? window.vkbmServiceMenuEditor.i18n
			: {};
	const labelDelete = i18n.delete || 'Delete';

	const hourOptions = ( function () {
		let options = '';
		for ( let h = 0; h <= 23; h++ ) {
			const val = h < 10 ? '0' + h : '' + h;
			options += '<option value="' + val + '">' + val + '</option>';
		}
		return options;
	} )();

	const minuteOptions = ( function () {
		let options = '';
		[ '00', '10', '20', '30', '40', '50' ].forEach( function ( m ) {
			options += '<option value="' + m + '">' + m + '</option>';
		} );
		return options;
	} )();

	function buildRow() {
		return $(
			'<div class="vkbm-fixed-start-time-row">' +
				'<select name="vkbm_service_menu[fixed_start_times][]" class="vkbm-fixed-start-hour">' +
				hourOptions +
				'</select>' +
				'<span>:</span>' +
				'<select name="vkbm_service_menu[fixed_start_minutes][]" class="vkbm-fixed-start-minute">' +
				minuteOptions +
				'</select>' +
				'<button type="button" class="vkbm-button vkbm-button__sm vkbm-button-outline vkbm-button-outline__danger vkbm-fixed-start-time-remove">' +
				$( '<span>' ).text( labelDelete ).html() +
				'</button>' +
				'</div>'
		);
	}

	$( document ).on( 'click', '#vkbm-fixed-start-time-add', function () {
		$( '#vkbm-fixed-start-times-list' ).append( buildRow() );
	} );

	$( document ).on( 'click', '.vkbm-fixed-start-time-remove', function () {
		$( this ).closest( '.vkbm-fixed-start-time-row' ).remove();
	} );

	// 料金区分（大人料金・子供料金など）のリピータUI.
	// 料金欄の単位（通貨記号＋税込ラベル）。税込ラベルが空でも通貨記号は常時表示する。
	const priceUnit =
		window.vkbmServiceMenuEditor && window.vkbmServiceMenuEditor.priceUnit
			? String( window.vkbmServiceMenuEditor.priceUnit )
			: '';
	const labelPlaceholder = i18n.priceTierLabelPlaceholder || '';
	const labelAria = i18n.priceTierLabelAria || '';
	const priceAria = i18n.priceTierPriceAria || '';

	function buildPriceTierRow() {
		const row = $( '<div class="vkbm-price-tier-row"></div>' );

		$( '<input type="text" />' )
			.attr( 'name', 'vkbm_service_menu[price_tiers][label][]' )
			.addClass( 'regular-text vkbm-price-tier-label' )
			.attr( 'placeholder', labelPlaceholder )
			.attr( 'aria-label', labelAria )
			.appendTo( row );

		$( '<input type="number" min="0" step="1" />' )
			.attr( 'name', 'vkbm_service_menu[price_tiers][price][]' )
			.addClass( 'small-text vkbm-price-tier-price' )
			.attr( 'aria-label', priceAria )
			.appendTo( row );

		if ( priceUnit !== '' ) {
			$( '<span class="vkbm-price-tier-unit"></span>' )
				.text( priceUnit )
				.appendTo( row );
		}

		$(
			'<button type="button" class="vkbm-button vkbm-button__sm vkbm-button-outline vkbm-button-outline__danger vkbm-price-tier-remove"></button>'
		)
			.text( labelDelete )
			.appendTo( row );

		return row;
	}

	$( document ).on( 'click', '#vkbm-price-tier-add', function () {
		$( '#vkbm-price-tiers-list' ).append( buildPriceTierRow() );
	} );

	$( document ).on( 'click', '.vkbm-price-tier-remove', function () {
		$( this ).closest( '.vkbm-price-tier-row' ).remove();
	} );

	// 「複数人予約を許可」チェックと「最大予約受付数」入力に連動して、
	// 複数人予約相乗りが前提の欄（最少催行人数・貸し切り予約・料金区分）の表示を切り替える（#320）。
	// 表示条件は「複数人予約 ON かつ 最大受付数が2以上」。これ以外（OFF または 最大受付数1以下）は
	// 欄ごと隠す。条件未達のまま入力 → 保存で値が削除され入力が消える事故を防ぐため。
	function syncMultipleGuestsDependentFields() {
		const $checkbox = $( '#vkbm_service_menu_allow_multiple_guests' );
		if ( $checkbox.length === 0 ) {
			return;
		}
		// 最大予約受付数の実効値。未入力・不正値は既定1とみなす。
		const $maxCapacity = $( '#vkbm_service_menu_max_capacity' );
		let maxCapacity = 1;
		if ( $maxCapacity.length > 0 ) {
			const parsed = parseInt( $maxCapacity.val(), 10 );
			maxCapacity = isNaN( parsed ) ? 1 : parsed;
		}
		// 複数人予約 ON かつ 最大受付数2以上のときだけ表示する。
		const show = $checkbox.prop( 'checked' ) && maxCapacity >= 2;
		const hidden = ! show;
		[
			'#vkbm-min-capacity-field',
			'#vkbm-price-tiers-field',
			'#vkbm-exclusive-when-booked-field',
			'#vkbm-exclusive-user-selectable-field',
		].forEach( function ( selector ) {
			const $field = $( selector );
			if ( $field.length > 0 ) {
				$field.prop( 'hidden', hidden );
			}
		} );
	}

	// 「ユーザーによる貸し切り指定を受け付ける」チェックと、第二段（貸し切り料金・適用外人数）の表示を連動させる。
	// チェック OFF のときは料金欄を隠し、OFF のまま入力 → 保存で削除され入力が消える事故を防ぐ。
	function syncExclusiveFeeFields() {
		const $checkbox = $( '#vkbm_service_menu_exclusive_user_selectable' );
		const $fields = $( '#vkbm-exclusive-fee-fields' );
		if ( $checkbox.length === 0 || $fields.length === 0 ) {
			return;
		}
		$fields.prop( 'hidden', ! $checkbox.prop( 'checked' ) );
	}

	$( document ).on(
		'change',
		'#vkbm_service_menu_allow_multiple_guests',
		syncMultipleGuestsDependentFields
	);

	// 最大予約受付数の数値変更にも連動させる（保存・再読込なしで切り替わる）。
	// input は手入力・スピナー双方を拾い、change は確定時の保険として両方を購読する。
	$( document ).on(
		'input change',
		'#vkbm_service_menu_max_capacity',
		syncMultipleGuestsDependentFields
	);

	$( document ).on(
		'change',
		'#vkbm_service_menu_exclusive_user_selectable',
		syncExclusiveFeeFields
	);

	// 初期表示時にも現在のチェック状態へ揃える（PHP 側の初期 hidden と二重化しても無害）。
	$( syncMultipleGuestsDependentFields );
	$( syncExclusiveFeeFields );

	// ── 予約可能日：曜日指定／日付指定 ──────────────────────────────
	// プルダウンの選択に応じて、曜日指定・日付指定の詳細パネルを排他で出し分ける。
	// 非表示側の入力値は DOM に残して保持する（保存側で選択中の種別のみ有効化）。

	// テンプレート要素（<template>）の中身 HTML を取得する。
	function getTemplateHtml( id ) {
		const tpl = document.getElementById( id );
		if ( ! tpl ) {
			return '';
		}
		return ( tpl.innerHTML || '' ).trim();
	}

	// 曜日指定行の連番インデックス（同一送信内で name のキー衝突を避けるためだけに使う。
	// 保存側で array_values により振り直されるため、連続でなくてよい）。
	let weekdayRowIndex = $( '#vkbm-reservation-custom-weekday-rows' ).children().length;
	let dateRowIndex = $( '#vkbm-reservation-custom-date-rows' ).children().length;

	// 今日の日付を YYYY-MM-DD（ローカル）で返す。日付入力の min（過去日抑止）に使う。
	function todayStr() {
		const d = new Date();
		const mm = String( d.getMonth() + 1 ).padStart( 2, '0' );
		const dd = String( d.getDate() ).padStart( 2, '0' );
		return d.getFullYear() + '-' + mm + '-' + dd;
	}

	// 行内の最初の操作可能要素へフォーカスを移す（キーボード/SR 利用者の迷子防止）。
	function focusFirstField( $row ) {
		const $field = $row.find( 'select, input' ).filter( ':visible' ).first();
		if ( $field.length ) {
			$field.trigger( 'focus' );
		}
	}

	// 行削除後のフォーカス移動先を決めて移す。前行→次行→「追加」ボタンの順で探す。
	function focusAfterRemove( $row, $addButton ) {
		const $prev = $row.prev();
		const $next = $row.next();
		if ( $prev.length ) {
			focusFirstField( $prev );
		} else if ( $next.length ) {
			focusFirstField( $next );
		} else if ( $addButton && $addButton.length ) {
			$addButton.trigger( 'focus' );
		}
	}

	// 日付指定の1行に、過去日・start>end を UI 段階で防ぐ制約（min）を適用する。
	// 既存の入力値は縛らない（保存済みの値を無効表示にしないため、空欄のときだけ min=今日）。
	// 終了日は開始日以降（開始日未入力時は今日以降）に制限する。
	function syncDateRowConstraints( $row ) {
		const today = todayStr();
		const $single = $row.find( '.vkbm-reservation-custom-date-single-input' );
		const $start = $row.find( '.vkbm-reservation-custom-date-start' );
		const $end = $row.find( '.vkbm-reservation-custom-date-end' );

		if ( $single.length && ! $single.val() ) {
			$single.attr( 'min', today );
		}
		if ( $start.length && ! $start.val() ) {
			$start.attr( 'min', today );
		}
		if ( $end.length ) {
			$end.attr( 'min', ( $start.val() || today ) );
		}
	}

	// 曜日指定の行を1行追加する。$focus=true のとき新規行へフォーカスを移す。
	function addWeekdayRow( focus ) {
		const html = getTemplateHtml(
			'vkbm-reservation-custom-weekday-row-template'
		);
		if ( ! html ) {
			return;
		}
		const rowHtml = html.replace( /__INDEX__/g, String( weekdayRowIndex ) );
		weekdayRowIndex += 1;
		const $row = $( rowHtml );
		$( '#vkbm-reservation-custom-weekday-rows' ).append( $row );
		if ( focus ) {
			focusFirstField( $row );
		}
	}

	// 日付指定の行を1行追加する。$focus=true のとき新規行へフォーカスを移す。
	function addDateRow( focus ) {
		const html = getTemplateHtml(
			'vkbm-reservation-custom-date-row-template'
		);
		if ( ! html ) {
			return;
		}
		const rowHtml = html.replace( /__INDEX__/g, String( dateRowIndex ) );
		dateRowIndex += 1;
		const $row = $( rowHtml );
		$( '#vkbm-reservation-custom-date-rows' ).append( $row );
		syncDateRowConstraints( $row );
		if ( focus ) {
			focusFirstField( $row );
		}
	}

	// 選択中の種別に応じてパネルを出し分ける。表示するパネルが空なら最低1行用意する
	// （0件のまま保存すると「常に予約不可」になるのを避け、入力を促す）。
	// $focusOnAdd=true（ユーザー操作起点）のとき、自動追加した行へフォーカスを移す。
	function syncReservationDayPanels( focusOnAdd ) {
		const $select = $( '#vkbm_service_menu_reservation_day_type' );
		if ( $select.length === 0 ) {
			return;
		}
		const value = $select.val();
		const showWeekday = value === 'custom_weekday';
		const showDate = value === 'custom_date';

		$( '#vkbm-reservation-custom-weekday-panel' ).prop(
			'hidden',
			! showWeekday
		);
		$( '#vkbm-reservation-custom-date-panel' ).prop( 'hidden', ! showDate );

		if (
			showWeekday &&
			$( '#vkbm-reservation-custom-weekday-rows' ).children().length === 0
		) {
			addWeekdayRow( !! focusOnAdd );
		}
		if (
			showDate &&
			$( '#vkbm-reservation-custom-date-rows' ).children().length === 0
		) {
			addDateRow( !! focusOnAdd );
		}
	}

	// 種別変更はユーザー操作起点なので、自動追加した行へフォーカスを移す。
	$( document ).on(
		'change',
		'#vkbm_service_menu_reservation_day_type',
		function () {
			syncReservationDayPanels( true );
		}
	);

	$( document ).on(
		'click',
		'#vkbm-reservation-custom-weekday-add',
		function () {
			addWeekdayRow( true );
		}
	);

	$( document ).on(
		'click',
		'.vkbm-reservation-custom-weekday-remove',
		function () {
			const $row = $( this ).closest( 'tr' );
			const $addButton = $( '#vkbm-reservation-custom-weekday-add' );
			// 削除前に移動先を決めてから行を除去し、フォーカスを移す。
			focusAfterRemove( $row, $addButton );
			$row.remove();
		}
	);

	$( document ).on( 'click', '#vkbm-reservation-custom-date-add', function () {
		addDateRow( true );
	} );

	$( document ).on(
		'click',
		'.vkbm-reservation-custom-date-remove',
		function () {
			const $row = $( this ).closest(
				'.vkbm-reservation-custom-date-row'
			);
			const $addButton = $( '#vkbm-reservation-custom-date-add' );
			focusAfterRemove( $row, $addButton );
			$row.remove();
		}
	);

	// 日付指定の行の「単日／期間」切替に応じて、対応する入力欄を出し分ける。
	$( document ).on(
		'change',
		'.vkbm-reservation-custom-date-type',
		function () {
			const $row = $( this ).closest(
				'.vkbm-reservation-custom-date-row'
			);
			const isRange = this.value === 'range';
			$row.find( '.vkbm-reservation-custom-date-single' ).prop(
				'hidden',
				isRange
			);
			$row.find( '.vkbm-reservation-custom-date-range' ).prop(
				'hidden',
				! isRange
			);
		}
	);

	// 開始日の変更に応じて、終了日の min を開始日以降へ動的連動させる（start>end を UI で防ぐ）。
	$( document ).on(
		'change input',
		'.vkbm-reservation-custom-date-start',
		function () {
			syncDateRowConstraints(
				$( this ).closest( '.vkbm-reservation-custom-date-row' )
			);
		}
	);

	// 初期表示時に現在の選択へ揃える（初期化なのでフォーカスは移さない）。
	$( function () {
		syncReservationDayPanels( false );
		// 既存の日付行にも min 制約を適用する。
		$( '.vkbm-reservation-custom-date-row' ).each( function () {
			syncDateRowConstraints( $( this ) );
		} );
	} );
} )( jQuery );
