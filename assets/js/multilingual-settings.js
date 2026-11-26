/**
 * BM 設定「多言語設定」タブ（#1186）の言語の追加・削除。
 *
 * 追加・削除は画面の上だけで行い、「変更を保存」で確定する（保存は PHP の Multilingual_Labels::save_from_request()）。
 * 追加する言語の入力欄は、PHP が言語ごとに出力したひな形（template）から作る。
 */
( function () {
	'use strict';

	const l10n = window.vkbmMultilingualSettings || {};

	/**
	 * 文言の %s を、渡した値で置き換える。
	 *
	 * @param {string} template 文言。
	 * @param {string} value    置き換える値。
	 * @return {string} 置き換えた文言。
	 */
	const format = ( template, value ) =>
		String( template || '%s' ).replace( '%s', String( value ) );

	/**
	 * 読み上げで言語を指す名前（例: 「English」）を返す。
	 *
	 * @param {HTMLElement} section 言語の欄のまとまり。
	 * @return {string} 言語の名前。
	 */
	const getQuotedName = ( section ) =>
		format( l10n.languageName, section.dataset.languageName || '' );

	const init = () => {
		const root = document.getElementById( 'vkbm-multilingual' );
		if ( ! root ) {
			return;
		}

		const select = document.getElementById(
			'vkbm-multilingual-add-locale'
		);
		const addButton = document.getElementById( 'vkbm-multilingual-add' );
		const list = document.getElementById( 'vkbm-multilingual-languages' );
		const status = document.getElementById( 'vkbm-multilingual-status' );
		const empty = root.querySelector( '.vkbm-multilingual__empty' );
		const addHelp = root.querySelector( '.vkbm-multilingual__add-help' );
		const noMore = root.querySelector( '.vkbm-multilingual__no-more' );
		if ( ! select || ! addButton || ! list ) {
			return;
		}

		// 候補の並びは、言語ごとのひな形の並び（候補 → 追加済みの言語）にする。保存済みの言語を削除したときも候補に戻せる。
		const templates = Array.from(
			root.querySelectorAll( '.vkbm-multilingual__template' )
		);
		const initialOptions = templates.map( ( template ) => ( {
			value: template.dataset.locale,
			label: template.dataset.optionLabel || template.dataset.locale,
		} ) );

		/**
		 * 結果を読み上げ用の領域に入れる。
		 *
		 * @param {string} message 読み上げる文言。
		 */
		const announce = ( message ) => {
			if ( status ) {
				status.textContent = message;
			}
		};

		/**
		 * 追加済みの言語の欄のまとまりを返す。
		 *
		 * @return {HTMLElement[]} 言語の欄のまとまり。
		 */
		const getSections = () =>
			Array.from(
				list.querySelectorAll( '.vkbm-multilingual__language' )
			);

		/**
		 * 候補・ボタン・空の表示を、追加済みの言語に合わせて直す。
		 */
		const refresh = () => {
			const added = getSections().map(
				( section ) => section.dataset.locale
			);

			// 追加済みの言語を候補から外し、削除した言語を元の並びで戻す。
			Array.from( select.options ).forEach( ( option ) => {
				if ( '' !== option.value ) {
					option.remove();
				}
			} );
			initialOptions.forEach( ( item ) => {
				if ( ! added.includes( item.value ) ) {
					const option = document.createElement( 'option' );
					option.value = item.value;
					option.textContent = item.label;
					select.appendChild( option );
				}
			} );
			select.value = '';

			const hasCandidates = select.options.length > 1;
			select.disabled = ! hasCandidates;
			syncAddButton();
			if ( addHelp ) {
				addHelp.hidden = ! hasCandidates;
			}
			if ( noMore ) {
				noMore.hidden = hasCandidates;
			}
			if ( empty ) {
				empty.hidden = added.length > 0;
			}
		};

		/**
		 * 「+ 言語を追加」ボタンは、言語を選んでいるときだけ押せるようにする（押しても何も起きないように見せないため）。
		 */
		function syncAddButton() {
			addButton.disabled = select.disabled || '' === select.value;
		}

		/**
		 * 複数形の欄のプレースホルダーを、同じ言語の単数形の入力に合わせる。
		 * 単数形を入力すると、複数形の空欄はその単数形で表示されるため。
		 *
		 * @param {HTMLElement} section 言語の欄のまとまり。
		 */
		const syncPluralPlaceholder = ( section ) => {
			[
				[ 'menu_label', 'menu_label_plural' ],
				[ 'resource_label_singular', 'resource_label_plural' ],
			].forEach( ( [ singularKey, pluralKey ] ) => {
				const singular = section.querySelector(
					`[data-multilingual-key="${ singularKey }"]`
				);
				const plural = section.querySelector(
					`[data-multilingual-key="${ pluralKey }"]`
				);
				if ( singular && plural ) {
					plural.placeholder =
						singular.value.trim() ||
						plural.dataset.basePlaceholder ||
						'';
				}
			} );
		};

		/**
		 * 言語を画面から取り除き、フォーカスを次の言語の見出し（無ければ前の言語、無ければ言語の選択欄）へ移す。
		 *
		 * @param {HTMLElement} section 言語の欄のまとまり。
		 */
		const removeSection = ( section ) => {
			const sections = getSections();
			const index = sections.indexOf( section );
			const next = sections[ index + 1 ] || sections[ index - 1 ] || null;
			const name = getQuotedName( section );

			section.remove();
			refresh();
			announce( format( l10n.removed, name ) );

			const focusTarget = next
				? next.querySelector( '.vkbm-multilingual__language-title' )
				: select;
			if ( focusTarget ) {
				focusTarget.focus();
			}
		};

		// 言語の選択に合わせて「+ 言語を追加」ボタンを切り替える。
		select.addEventListener( 'change', syncAddButton );
		syncAddButton();

		// 言語の追加。
		addButton.addEventListener( 'click', () => {
			const locale = select.value;
			if ( '' === locale ) {
				select.focus();
				return;
			}

			const template = templates.find(
				( item ) => item.dataset.locale === locale
			);
			if ( ! template ) {
				return;
			}

			const fragment = template.content.cloneNode( true );
			const section = fragment.querySelector(
				'.vkbm-multilingual__language'
			);
			list.appendChild( fragment );
			refresh();
			announce( format( l10n.added, getQuotedName( section ) ) );

			// 追加した言語の最初の入力欄（業種プリセットで隠れた欄は除く）へフォーカスを移す。
			const firstInput = Array.from(
				section.querySelectorAll( 'input[type="text"], textarea' )
			).find( ( input ) => ! input.closest( 'tr[hidden]' ) );
			if ( firstInput ) {
				firstInput.focus();
			}
		} );

		// 言語の削除。入力が1つでもあれば確認ダイアログを出す。
		list.addEventListener( 'click', ( event ) => {
			const button = event.target.closest( '.vkbm-multilingual__remove' );
			if ( ! button ) {
				return;
			}
			const section = button.closest( '.vkbm-multilingual__language' );
			if ( ! section ) {
				return;
			}

			const hasInput = Array.from(
				section.querySelectorAll( 'input[type="text"], textarea' )
			).some( ( input ) => '' !== input.value.trim() );
			const dialog = window.vkbmGuardDialog;
			if ( ! hasInput || ! dialog ) {
				removeSection( section );
				return;
			}

			const name = getQuotedName( section );
			dialog.openAlertDialog( {
				title: l10n.dialogTitle || '',
				bodyElements: [
					dialog.paragraph( format( l10n.dialogBody, name ) ),
					dialog.paragraph( l10n.dialogNote || '' ),
				],
				actions: [
					{ label: l10n.cancel || '', isPrimary: false },
					{
						label: l10n.confirm || '',
						isPrimary: true,
						isDestructive: true,
						onClick: () => removeSection( section ),
					},
				],
				triggerEl: button,
			} );
		} );

		// 単数形の入力に合わせて、複数形のプレースホルダーを直す。
		list.addEventListener( 'input', ( event ) => {
			const target = event.target;
			if (
				target instanceof HTMLInputElement &&
				[ 'menu_label', 'resource_label_singular' ].includes(
					target.dataset.multilingualKey
				)
			) {
				syncPluralPlaceholder(
					target.closest( '.vkbm-multilingual__language' )
				);
			}
		} );
	};

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
