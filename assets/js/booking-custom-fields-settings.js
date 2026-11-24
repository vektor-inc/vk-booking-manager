/**
 * BM 設定「予約時の入力項目」（#1147）の行の追加・並べ替え・削除。
 *
 * 行の並びはそのまま保存時の並び（確認画面に出る順）になる。PHP は送られた name の順に配列を作るため、
 * 行を DOM の中で入れ替えるだけで並べ替えが保存される（name の番号は振り直さない）。
 */
( function () {
	'use strict';

	const ROW_SELECTOR = '.vkbm-booking-custom-field-row';
	const LABEL_INPUT_SELECTOR = '.vkbm-booking-custom-field-row__label input';
	const l10n = window.vkbmBookingCustomFieldsSettings || {};

	/**
	 * 文言の %s・%d・%1$s などを、渡した値で順に置き換える。
	 *
	 * @param {string}               template 文言。
	 * @param {Array<string|number>} values   置き換える値。
	 * @return {string} 置き換えた文言。
	 */
	const format = ( template, values ) => {
		let position = 0;
		return String( template || '' ).replace(
			/%(?:(\d+)\$)?[sd]/g,
			( match, index ) => {
				const value = index
					? values[ parseInt( index, 10 ) - 1 ]
					: values[ position++ ];
				return value === undefined ? '' : String( value );
			}
		);
	};

	/**
	 * 読み上げで行を指す名前を返す。項目名が空なら行の番号で指す。
	 *
	 * @param {HTMLElement} row   行。
	 * @param {number}      index 行の番号（0 始まり）。
	 * @return {string} 行の名前。
	 */
	const getRowName = ( row, index ) => {
		const input = row.querySelector( LABEL_INPUT_SELECTOR );
		const label = input ? input.value.trim() : '';
		return label
			? format( l10n.itemName || '%s', [ label ] )
			: format( l10n.itemRow || '%d', [ index + 1 ] );
	};

	/**
	 * 並べ替え・削除の結果を読み上げ用の領域に入れる。
	 *
	 * @param {string} message 読み上げる文言。
	 */
	const announce = ( message ) => {
		const status = document.getElementById(
			'vkbm-booking-custom-fields-status'
		);
		if ( status ) {
			status.textContent = message;
		}
	};

	/**
	 * ボタンに読み上げ用の名前を付ける。文言が渡されていなければ付けない（ボタンの表示名を使う）。
	 *
	 * @param {HTMLElement|null} button   ボタン。
	 * @param {string}           template 文言。
	 * @param {string}           name     行の名前。
	 */
	const setButtonLabel = ( button, template, name ) => {
		if ( button && template ) {
			button.setAttribute( 'aria-label', format( template, [ name ] ) );
		}
	};

	/**
	 * 一覧の要素を返す。
	 *
	 * @return {HTMLElement|null} 一覧の要素。
	 */
	const getList = () =>
		document.getElementById( 'vkbm-booking-custom-fields-rows' );

	/**
	 * 一覧の行を返す。
	 *
	 * @param {HTMLElement} list 一覧の要素。
	 * @return {HTMLElement[]} 行の一覧。
	 */
	const getRows = ( list ) =>
		Array.from( list.querySelectorAll( ROW_SELECTOR ) );

	/**
	 * 行の数に合わせて、上へ・下へボタン・追加ボタン・空のときの文言の状態を揃える。
	 *
	 * @param {HTMLElement} list 一覧の要素。
	 */
	const refresh = ( list ) => {
		const rows = getRows( list );
		rows.forEach( ( row, index ) => {
			const type = row.querySelector( 'select[name$="[type]"]' )?.value;
			const choices = row.querySelector(
				'.vkbm-booking-custom-field-row__choices'
			);
			const defaultValue = row.querySelector(
				'.vkbm-booking-custom-field-row__default'
			);
			if ( choices ) {
				choices.hidden = type !== 'select' && type !== 'radio';
			}
			if ( defaultValue ) {
				defaultValue.hidden = type !== 'number';
			}
			const typeOptions = row.querySelector(
				'.vkbm-booking-custom-field-row__type-options'
			);
			if ( typeOptions ) {
				typeOptions.hidden =
					type !== 'select' && type !== 'radio' && type !== 'number';
			}
			const up = row.querySelector(
				'.vkbm-booking-custom-field-row__up'
			);
			const down = row.querySelector(
				'.vkbm-booking-custom-field-row__down'
			);
			if ( up ) {
				up.disabled = index === 0;
			}
			if ( down ) {
				down.disabled = index === rows.length - 1;
			}

			// 同じ文言のボタンが行の数だけ並ぶため、どの項目のボタンかを読み上げ用の名前で示す。
			const name = getRowName( row, index );
			setButtonLabel( up, l10n.moveUp, name );
			setButtonLabel( down, l10n.moveDown, name );
			setButtonLabel(
				row.querySelector( '.vkbm-booking-custom-field-row__remove' ),
				l10n.remove,
				name
			);
		} );

		const max = parseInt( list.getAttribute( 'data-max-fields' ), 10 ) || 0;
		const addButton = document.getElementById(
			'vkbm-booking-custom-field-add'
		);
		if ( addButton ) {
			addButton.disabled = max > 0 && rows.length >= max;
		}

		// 行が 0 件のときは一覧を隠し、「まだ項目がありません。」を出す。
		list.hidden = rows.length === 0;
		const empty = document.querySelector(
			'.vkbm-booking-custom-fields__empty'
		);
		if ( empty ) {
			empty.hidden = rows.length > 0;
		}
	};

	/**
	 * 空の行を末尾に足し、項目名の欄へフォーカスを移す。
	 *
	 * @param {HTMLElement} list 一覧の要素。
	 */
	const addRow = ( list ) => {
		const template = document.getElementById(
			'vkbm-booking-custom-field-row-template'
		);
		if ( ! template || ! template.content ) {
			return;
		}

		let index = parseInt( list.getAttribute( 'data-next-index' ), 10 );
		if ( isNaN( index ) ) {
			index = getRows( list ).length;
		}
		list.setAttribute( 'data-next-index', String( index + 1 ) );

		const wrapper = document.createElement( 'div' );
		wrapper.appendChild( template.content.cloneNode( true ) );
		wrapper.innerHTML = wrapper.innerHTML.replace(
			/__INDEX__/g,
			String( index )
		);
		const row = wrapper.querySelector( ROW_SELECTOR );
		if ( ! row ) {
			return;
		}
		list.appendChild( row );
		refresh( list );

		const input = row.querySelector( 'input[type="text"]' );
		if ( input ) {
			input.focus();
		}
	};

	/**
	 * 行を 1 つ上または下へ動かす。押したボタンへフォーカスを戻し、続けて動かせるようにする。
	 *
	 * @param {HTMLElement} list      一覧の要素。
	 * @param {HTMLElement} row       動かす行。
	 * @param {number}      direction -1 なら上へ、1 なら下へ。
	 * @param {HTMLElement} button    押したボタン。
	 */
	const moveRow = ( list, row, direction, button ) => {
		if ( direction < 0 && row.previousElementSibling ) {
			list.insertBefore( row, row.previousElementSibling );
		} else if ( direction > 0 && row.nextElementSibling ) {
			list.insertBefore( row.nextElementSibling, row );
		}
		refresh( list );

		// 画面を見ずに操作している人へ、動かした後の位置を伝える。
		const rows = getRows( list );
		const position = rows.indexOf( row );
		announce(
			format( l10n.moved, [
				getRowName( row, position ),
				position + 1,
				rows.length,
			] )
		);

		// 端まで動かしてボタンが押せなくなったときは、反対向きのボタンへフォーカスを移す。
		const target = button.disabled
			? row.querySelector(
					direction < 0
						? '.vkbm-booking-custom-field-row__down'
						: '.vkbm-booking-custom-field-row__up'
			  )
			: button;
		if ( target && ! target.disabled ) {
			target.focus();
		}
	};

	/**
	 * 行を削除し、隣の行（無ければ追加ボタン）へフォーカスを移す。
	 *
	 * @param {HTMLElement} list 一覧の要素。
	 * @param {HTMLElement} row  削除する行。
	 */
	const removeRow = ( list, row ) => {
		const next = row.nextElementSibling || row.previousElementSibling;
		const name = getRowName( row, getRows( list ).indexOf( row ) );
		row.remove();
		refresh( list );
		announce( format( l10n.removed, [ name ] ) );

		const focusTarget = next
			? next.querySelector( '.vkbm-booking-custom-field-row__remove' )
			: document.getElementById( 'vkbm-booking-custom-field-add' );
		if ( focusTarget ) {
			focusTarget.focus();
		}
	};

	document.addEventListener( 'click', ( event ) => {
		const list = getList();
		if ( ! list || ! ( event.target instanceof Element ) ) {
			return;
		}

		const addButton = event.target.closest(
			'#vkbm-booking-custom-field-add'
		);
		if ( addButton ) {
			event.preventDefault();
			addRow( list );
			return;
		}

		const button = event.target.closest(
			'.vkbm-booking-custom-field-row__up, .vkbm-booking-custom-field-row__down, .vkbm-booking-custom-field-row__remove'
		);
		const row = button ? button.closest( ROW_SELECTOR ) : null;
		if ( ! button || ! row || ! list.contains( row ) ) {
			return;
		}
		event.preventDefault();

		if (
			button.classList.contains( 'vkbm-booking-custom-field-row__remove' )
		) {
			removeRow( list, row );
			return;
		}
		moveRow(
			list,
			row,
			button.classList.contains( 'vkbm-booking-custom-field-row__up' )
				? -1
				: 1,
			button
		);
	} );

	// 項目名を書き換えたら、その行のボタンの読み上げ用の名前も合わせる。
	document.addEventListener( 'input', ( event ) => {
		const list = getList();
		if (
			list &&
			event.target instanceof Element &&
			event.target.matches( LABEL_INPUT_SELECTOR ) &&
			list.contains( event.target )
		) {
			refresh( list );
		}
	} );
	document.addEventListener( 'change', ( event ) => {
		const list = getList();
		if (
			list &&
			event.target instanceof Element &&
			event.target.matches( 'select[name$="[type]"]' ) &&
			list.contains( event.target )
		) {
			refresh( list );
		}
	} );

	const initialList = getList();
	if ( initialList ) {
		refresh( initialList );
	}
} )();
