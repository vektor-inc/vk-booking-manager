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
			.addClass( 'small-text vkbm-price-input vkbm-price-tier-price' )
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

	// 「このメニューで指名を使う」チェックボックスが指名を使う状態かどうかを返す。
	// チェックボックス自体が画面に無い（サイト全体の指名機能OFF・無料版など）場合は
	// 判定材料が無いため false（指名を使わない扱い）を返し、複数人一括予約系の表示制御に
	// 影響させない（#412 B-4）。
	function isMenuNominationInUse() {
		const $useNomination = $( '#vkbm_service_menu_use_nomination' );
		return $useNomination.length > 0 && $useNomination.prop( 'checked' );
	}

	// 「このメニューで指名を使う」チェックの切り替えに連動して、「Nomination fee」欄の表示を
	// 即座に切り替える（#412 B-4）。
	// #392: 予約枠の定員（vkbm-max-capacity-field）・複数人一括予約（vkbm-allow-multiple-guests-field）は
	// 指名を使うメニューでも利用できるようになったため、この関数では表示を切り替えなくなった
	// （常に表示。PHP側も同様に hidden 属性を付けない）。
	// #393: 最少催行人数（指名を使うメニューでは「最低申し込み人数」という意味に変わる）・
	// 料金区分・貸し切り予約・予約者による貸切指定はいずれも指名を使うメニューで引き続き
	// 意味を持つため、表示の切り替えは行わない。ただし最少催行人数欄のラベル・説明文
	// （syncMinCapacityFieldCopy）は指名の有無で文言そのものが変わるため、引き続き
	// syncMultipleGuestsDependentFields() 経由で再評価する。
	function syncNominationDependentFields() {
		const $useNomination = $( '#vkbm_service_menu_use_nomination' );
		if ( $useNomination.length === 0 ) {
			return;
		}
		const inUse = $useNomination.prop( 'checked' );

		// 「Nomination fee」欄：指名を使う間だけ表示する。
		$( '#vkbm-disable-nomination-fee-field' ).prop( 'hidden', ! inUse );

		// #393: 表示・非表示の切り替えは不要だが、最少催行人数欄のラベル・説明文
		// （syncMinCapacityFieldCopy）は指名の有無で文言が変わるため、それを含む
		// syncMultipleGuestsDependentFields() を呼んで即時反映する。
		syncMultipleGuestsDependentFields();
	}

	// 最少催行人数／最低申し込み人数欄のラベル・説明文を「このメニューで指名を使う」チェックの
	// 状態に合わせて即時切り替える（#393）。PHP 初期描画（class-service-menu-editor.php）と
	// 同じ文言を wp_localize_script 経由の i18n オブジェクトから取得して差し替える。
	function syncMinCapacityFieldCopy() {
		const $label = $( '#vkbm-min-capacity-label' );
		const $description = $( '#vkbm-min-capacity-description' );
		if ( $label.length === 0 || $description.length === 0 ) {
			return;
		}
		const isNomination = isMenuNominationInUse();

		const labelText = isNomination
			? i18n.minCapacityNominationLabel
			: i18n.minCapacityDefaultLabel;
		if ( labelText ) {
			$label.text( labelText );
		}

		const lines = isNomination
			? [
					i18n.minCapacityNominationDescription1,
					i18n.minCapacityNominationDescription2,
					i18n.minCapacityNominationDescription3,
					i18n.minCapacityNominationDescription4,
			  ]
			: [
					i18n.minCapacityDefaultDescription1,
					i18n.minCapacityDefaultDescription2,
					i18n.minCapacityDefaultDescription3,
			  ];

		$description.empty();
		let isFirstLine = true;
		lines.forEach( function ( line ) {
			if ( ! line ) {
				return;
			}
			if ( ! isFirstLine ) {
				$description.append( '<br>' );
			}
			$description.append( document.createTextNode( line ) );
			isFirstLine = false;
		} );
	}

	// 「複数人予約を許可」チェックと「最大予約受付数」入力に連動して、複数人一括予約に関連する欄
	// （最少催行人数・料金区分・貸し切り予約・予約者による貸切指定）の表示を切り替える
	// （#320・#412 B-4）。#393・#440: これら4欄はすべて「複数人予約 ON かつ 最大受付数が2以上」の
	// 同じ2条件だけで出し分ける。指名の有無は表示条件に含めない（指名を使うメニューでも
	// 貸切設定を利用できるようにする仕様変更のため）。これ以外は欄ごと隠す。条件未達のまま
	// 入力 → 保存で値が削除され入力が消える事故を防ぐため。
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
		const allowMultipleGuests = $checkbox.prop( 'checked' );
		const meetsCapacity = maxCapacity >= 2;

		// #393・#440: 最少催行人数／最低申し込み人数欄・料金区分欄・貸し切り予約・予約者による
		// 貸切指定は、指名を使うメニューでも意味を持つ（予約画面・予約確定処理は #392／#440の
		// 時点で「指名を使わない」条件を外して適用済みのため、編集画面もそれに合わせる）ため、
		// 「指名を使わない」を表示条件に含めない。表示条件は「複数人予約 ON かつ 最大受付数が
		// 2以上」の2つだけ。
		const showMultiGuestDependentFields =
			allowMultipleGuests && meetsCapacity;
		const hiddenMultiGuestDependentFields = ! showMultiGuestDependentFields;
		$( '#vkbm-min-capacity-field' ).prop(
			'hidden',
			hiddenMultiGuestDependentFields
		);
		syncMinCapacityFieldCopy();
		$( '#vkbm-price-tiers-field' ).prop(
			'hidden',
			hiddenMultiGuestDependentFields
		);

		// 貸し切り予約・予約者による貸切指定も、最少催行人数・料金区分と同じ条件で出し分ける
		// （以前は「指名を使わない」も条件に加えていたが、指名を使うメニューでも貸切設定を
		// 利用できるようにする仕様変更で撤廃した）。
		[
			'#vkbm-exclusive-when-booked-field',
			'#vkbm-exclusive-user-selectable-field',
		].forEach( function ( selector ) {
			const $field = $( selector );
			if ( $field.length > 0 ) {
				$field.prop( 'hidden', hiddenMultiGuestDependentFields );
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

	// 「貸し切り予約」ONのときは「予約者による貸切指定」と、その配下の貸し切り料金・適用外人数の
	// 入力欄を操作不可（disabled）にする（#388）。両方ONで保存されると、予約者の指定有無に関わらず
	// 枠全体が貸切扱いになるのに、指定した予約者だけ貸し切り料金を負担する不整合が起きるため、
	// 管理画面の時点で片方しか選べないようにする。保存側（class-service-menu-editor.php）にも
	// 同じ排他のガードがあり、こちらはその事故を未然に防ぐための表示側の対策。
	// disabled にした input はフォーム送信されないため、保存側の削除処理と矛盾なく揃う。
	//
	// input の disabled だけではラベルの文言や貸切料金欄の見出し・単位（通貨記号／人）は通常色の
	// ままになり、どこまで無効化されているか一見して分からない。そのため、チェックボックスを囲む
	// ラベルと貸切料金欄のコンテナに .is-disabled を付け外しし、SCSS（admin-core.scss）側で
	// opacity を下げて見た目も揃える（.vkbm-button.is-disabled 等の既存パターンに合わせる）。
	function syncExclusiveWhenBookedExclusivity() {
		const $exclusiveWhenBooked = $(
			'#vkbm_service_menu_exclusive_when_booked'
		);
		const $userSelectable = $(
			'#vkbm_service_menu_exclusive_user_selectable'
		);
		const $userSelectableLabel = $(
			'.vkbm-exclusive-user-selectable-label'
		);
		const $feeFields = $( '#vkbm-exclusive-fee-fields' );
		if (
			$exclusiveWhenBooked.length === 0 ||
			$userSelectable.length === 0
		) {
			return;
		}
		const disable = $exclusiveWhenBooked.prop( 'checked' );
		$userSelectable.prop( 'disabled', disable );
		$( '#vkbm_service_menu_exclusive_fee_per_person' ).prop(
			'disabled',
			disable
		);
		$( '#vkbm_service_menu_exclusive_fee_exempt_guests' ).prop(
			'disabled',
			disable
		);
		$userSelectableLabel.toggleClass( 'is-disabled', disable );
		$feeFields.toggleClass( 'is-disabled', disable );
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

	$( document ).on(
		'change',
		'#vkbm_service_menu_exclusive_when_booked',
		syncExclusiveWhenBookedExclusivity
	);

	// 「このメニューで指名を使う」チェックの切り替えに連動させる（#412 B-4）。
	$( document ).on(
		'change',
		'#vkbm_service_menu_use_nomination',
		syncNominationDependentFields
	);

	// 初期表示時にも現在のチェック状態へ揃える（PHP 側の初期 hidden と二重化しても無害）。
	// syncNominationDependentFields が内部で syncMultipleGuestsDependentFields も呼ぶため、
	// 単独の syncMultipleGuestsDependentFields 呼び出しは不要（チェックボックス自体が無い
	// 画面では syncNominationDependentFields は何もしないため、念のため両方呼んでおく）。
	$( syncNominationDependentFields );
	$( syncMultipleGuestsDependentFields );
	$( syncExclusiveFeeFields );
	$( syncExclusiveWhenBookedExclusivity );

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
