( function ( $ ) {
	'use strict';

	let frame;
	const settings = window.vkbmProviderSettings || {
		logoFrameTitle: '',
		logoFrameButton: '',
	};
	let holidayTemplate = '';
	let $holidayTable;
	let $holidayIndex;
	let basicTemplate = '';
	let $basicSlots;
	let $basicNextIndex;
	let reminderTemplate = '';
	let $reminderList;
	let $reminderIndex;

	/**
	 * Get HTML string from a template or script element (for HTML5 <template> or legacy script type="text/template").
	 * @param {string|Element|jQuery} selectorOrElement - CSS selector, DOM element, or jQuery object.
	 * @return {string} The template's HTML string, or an empty string when not found.
	 */
	function getTemplateHtml( selectorOrElement ) {
		const el =
			typeof selectorOrElement === 'string'
				? document.querySelector( selectorOrElement )
				: selectorOrElement && selectorOrElement[ 0 ] !== undefined
				? selectorOrElement[ 0 ]
				: selectorOrElement;
		if ( ! el ) {
			return '';
		}
		if ( el.tagName === 'TEMPLATE' && el.content ) {
			const div = document.createElement( 'div' );
			div.appendChild( el.content.cloneNode( true ) );
			return div.innerHTML;
		}
		return el.innerHTML || '';
	}

	/**
	 * リソースメニューアイコンのピッカー（プリセット選択・直接入力・プレビュー）を初期化する。
	 */
	function initIconPickers() {
		const iconPattern = /^dashicons-[a-z0-9-]+$/;

		document
			.querySelectorAll( '.vkbm-icon-picker' )
			.forEach( function ( picker ) {
				const input = picker.querySelector(
					'.vkbm-icon-picker__input'
				);
				if ( ! input ) {
					return;
				}

				const preview = picker.querySelector(
					'.vkbm-icon-picker__preview'
				);
				const presets = picker.querySelectorAll(
					'.vkbm-icon-picker__preset'
				);

				// 入力値に合わせてプレビューとプリセットの選択状態を同期する。
				const syncState = function () {
					const value = input.value.trim();
					const isValid = iconPattern.test( value );

					if ( preview ) {
						preview.className =
							'vkbm-icon-picker__preview dashicons' +
							( isValid ? ' ' + value : '' );
					}

					presets.forEach( function ( preset ) {
						const selected =
							preset.getAttribute( 'data-icon' ) === value;
						preset.classList.toggle( 'is-selected', selected );
						preset.setAttribute(
							'aria-pressed',
							selected ? 'true' : 'false'
						);
					} );
				};

				presets.forEach( function ( preset ) {
					preset.addEventListener( 'click', function () {
						input.value = preset.getAttribute( 'data-icon' ) || '';
						syncState();
					} );
				} );

				input.addEventListener( 'input', syncState );
			} );
	}

	/** 利用者名の利用モードに合わせて見出し欄を切り替える。 */
	function initAttendeeNameMode() {
		const mode = document.getElementById(
			'vkbm-booking-attendee-name-mode'
		);
		const label = document.getElementById(
			'vkbm-booking-attendee-name-label'
		);
		if ( ! mode || ! label ) {
			return;
		}
		const update = () => {
			label.disabled = mode.value === 'disabled';
		};
		mode.addEventListener( 'change', update );
		update();
	}

	/**
	 * 業種プリセットの値をフォーム用の文字列へ変換する。
	 *
	 * @param {*} value プリセット値。
	 * @return {string} フォームへ設定する値。
	 */
	function getIndustryFormValue( value ) {
		if ( typeof value === 'boolean' ) {
			return value ? '1' : '0';
		}
		if ( value === null ) {
			return settings.defaultGuestsUnitLabel || '';
		}
		return String( value === undefined ? '' : value );
	}

	/**
	 * 業種プリセットの要約に表示する値を返す。
	 *
	 * @param {*} value プリセット値。
	 * @return {string} 読み取り専用プレビュー文字列。
	 */
	function getIndustryPreviewValue( value ) {
		if ( typeof value === 'boolean' ) {
			return value ? settings.enabledLabel : settings.disabledLabel;
		}
		return getIndustryFormValue( value );
	}

	/**
	 * 入力欄の直後にプリセット反映メッセージを一時表示する。
	 *
	 * @param {HTMLElement} input 値を反映した入力欄。
	 */
	function showIndustryAppliedMessage( input ) {
		const message = document.createElement( 'p' );
		message.className = 'description vkbm-industry-preset-applied-message';
		message.textContent = settings.industryAppliedMessage || '';
		input.insertAdjacentElement( 'afterend', message );
	}

	/**
	 * 業種プリセット選択と対象行の表示状態を初期化する。
	 */
	function initIndustryPreset() {
		const select = document.getElementById( 'vkbm-industry-preset' );
		if ( ! select || ! settings.industryPresets ) {
			return;
		}

		const summary = document.querySelector(
			'.vkbm-industry-preset-summary'
		);
		const toggle = document.querySelector(
			'.vkbm-industry-preset-summary__toggle'
		);
		const content = document.querySelector(
			'.vkbm-industry-preset-summary__content'
		);
		const list = document.querySelector(
			'.vkbm-industry-preset-summary__list'
		);
		const live = document.getElementById( 'vkbm-industry-preset-live' );
		let previousPreset = select.value;

		const announce = function ( message ) {
			if ( live ) {
				live.textContent = '';
				window.setTimeout( function () {
					live.textContent = message;
				}, 20 );
			}
		};

		// PHP 側で正規化済みだが、`vkbm_industry_presets` フィルターが想定外の形を返す
		// 可能性を排除できないため、JS 側でも構造欠落で例外にならないよう防御する（#387 レビュー指摘・安藤案）。
		const emptyPreset = { fields: {} };

		const update = function ( initial ) {
			const presetKey = select.value;
			const preset =
				settings.industryPresets[ presetKey ] ||
				settings.industryPresets.custom ||
				emptyPreset;
			const previous =
				settings.industryPresets[ previousPreset ] ||
				settings.industryPresets.custom ||
				emptyPreset;
			const hiddenFields = [];
			// 値がプリセットの初期値へ差し替わった項目数（aria-live の1本のアナウンスに含める）。
			let appliedCount = 0;

			document
				.querySelectorAll( '.vkbm-industry-preset-applied-message' )
				.forEach( function ( message ) {
					message.remove();
				} );

			document
				.querySelectorAll( '[data-industry-field]' )
				.forEach( function ( row ) {
					const fieldKey = row.getAttribute( 'data-industry-field' );
					const field = preset.fields[ fieldKey ];
					if ( ! field ) {
						return;
					}

					const controls = row.querySelectorAll(
						'input, select, textarea, button'
					);
					const shouldHide =
						presetKey !== 'custom' && ! field.display;
					row.hidden = shouldHide;
					controls.forEach( function ( control ) {
						control.disabled = shouldHide;
					} );

					if ( shouldHide ) {
						hiddenFields.push( {
							label:
								row.getAttribute( 'data-industry-label' ) ||
								fieldKey,
							value: field.value,
						} );
						return;
					}

					if ( initial || presetKey === 'custom' ) {
						return;
					}

					const input = row.querySelector(
						'[name="vkbm_provider_settings[' + fieldKey + ']"]'
					);
					if ( ! input ) {
						return;
					}

					const previousField = previous.fields[ fieldKey ];
					// previousField が無い（フィルターが項目を削っていた等）場合は、
					// 一致判定ができないため書き換えを見送る（安全側に倒す）。
					if ( previousPreset !== 'custom' && ! previousField ) {
						return;
					}
					const previousValue =
						previousPreset === 'custom'
							? settings.industryDefaults[ fieldKey ]
							: previousField.value;
					if (
						input.value === getIndustryFormValue( previousValue )
					) {
						input.value = getIndustryFormValue( field.value );
						input.dispatchEvent(
							new Event( 'input', { bubbles: true } )
						);
						showIndustryAppliedMessage( input );
						appliedCount += 1;
					}
				} );

			if ( summary && toggle && content && list ) {
				summary.hidden = hiddenFields.length === 0;
				toggle.textContent = (
					settings.industrySummaryTemplate || '%d'
				).replace( '%d', String( hiddenFields.length ) );
				list.replaceChildren();
				hiddenFields.forEach( function ( item ) {
					const term = document.createElement( 'dt' );
					const description = document.createElement( 'dd' );
					term.textContent = item.label;
					description.textContent = getIndustryPreviewValue(
						item.value
					);
					list.append( term, description );
				} );
				if ( hiddenFields.length === 0 ) {
					toggle.setAttribute( 'aria-expanded', 'false' );
					content.hidden = true;
				}
			}

			if ( ! initial ) {
				if ( presetKey === 'custom' ) {
					announce( settings.industryCustomMessage || '' );
				} else {
					// 「非表示件数の確定」と「値の差し替え件数」は同じ切り替えイベントで
					// 同時に決まるため、announce() を2回に分けず1本のテキストにまとめる
					// （後勝ちで前の内容が読まれない実装があるため。#387・植草案）。
					const presetLabel = preset.label || presetKey;
					const template =
						appliedCount > 0
							? settings.industryChangeWithAppliedTemplate
							: settings.industryChangeTemplate;
					announce(
						( template || '' )
							.replace( '%1$s', presetLabel )
							.replace( '%2$d', String( hiddenFields.length ) )
							.replace( '%3$d', String( appliedCount ) )
					);
				}
			}

			previousPreset = presetKey;
		};

		if ( toggle && content ) {
			toggle.addEventListener( 'click', function () {
				const expanded =
					toggle.getAttribute( 'aria-expanded' ) === 'true';
				toggle.setAttribute(
					'aria-expanded',
					expanded ? 'false' : 'true'
				);
				content.hidden = expanded;
			} );
		}

		select.addEventListener( 'change', function () {
			update( false );
		} );
		update( true );
	}

	// 詳細ページの表示方法で「予約ブロック内で表示」を選んでいるときだけ、予約ページURLの注意文を見せる。
	function initServiceMenuDetailDisplayModeNotice() {
		const select = document.getElementById(
			'vkbm-service-menu-detail-display-mode'
		);
		const notice = document.getElementById(
			'vkbm-service-menu-detail-display-mode-notice'
		);

		if ( ! select || ! notice ) {
			return;
		}

		select.addEventListener( 'change', function () {
			notice.hidden = 'block' !== select.value;
		} );
	}

	function updateBookingCancelModeState() {
		const $mode = $( '#vkbm-provider-booking-cancel-mode' );
		const $hoursField = $( '#vkbm-provider-booking-cancel-hours-field' );
		const $hoursInput = $( '#vkbm-provider-booking-cancel-hours' );

		if ( ! $mode.length || ! $hoursField.length ) {
			return;
		}

		const isHours = 'hours' === $mode.val();

		$hoursField.prop( 'hidden', ! isHours );
		if ( $hoursInput.length ) {
			$hoursInput.prop( 'disabled', ! isHours );
		}
	}

	// 予約ステータスが「仮予約にする」のときだけ、仮予約完了時の案内文の入力欄を表示する。
	// 非表示にしても入力値は送信・保持する（即時確定へ戻しても値を失わない）。
	function updateBookingStatusModeState() {
		const $mode = $( '#vkbm-provider-booking-status-mode' );
		const $messageField = $(
			'#vkbm-provider-booking-complete-message-pending-field'
		);

		if ( ! $mode.length || ! $messageField.length ) {
			return;
		}

		$messageField.prop( 'hidden', 'pending' !== $mode.val() );
	}

	function updateReservationMenuListModeState() {
		const $checkbox = $( '#vkbm-reservation-show-menu-list' );
		const $row = $( '#vkbm-reservation-menu-list-display-mode-row' );
		const $select = $( '#vkbm-reservation-menu-list-display-mode' );
		if ( ! $checkbox.length || ! $row.length ) {
			return;
		}

		const enabled = $checkbox.is( ':checked' );

		$row.prop( 'hidden', ! enabled );
		if ( $select.length ) {
			$select.prop( 'disabled', ! enabled );
		}
	}

	// #431: 「リソースタグ検索」は「絞り込み検索」の子項目。親（絞り込み検索）のチェックが
	// 外れたら、子（リソースタグ検索）のチェックも自動的に外す（updateReservationMenuListModeState
	// と同じ流儀）。Free版・非Pro版では子チェックボックス自体が描画されないため、
	// $child.length が0のときは何もしない。
	function updateResourceTagSearchState() {
		const $parent = $( '#vkbm-reservation-show-menu-search' );
		const $child = $( '#vkbm-resource-tag-search-enabled' );
		if ( ! $parent.length || ! $child.length ) {
			return;
		}

		const parentChecked = $parent.is( ':checked' );

		if ( ! parentChecked ) {
			$child.prop( 'checked', false );
		}

		// #431: updateReservationMenuListModeState() と同じ流儀で、
		// 親（絞り込み検索）がOFFの間は子（リソースタグ検索）を disabled にする。
		// disabled の input はフォーム送信時に送られないため、サーバー側
		// （Settings_Sanitizer）の「親OFFなら強制OFF」判定と結果が一致することを確認済み。
		$child.prop( 'disabled', ! parentChecked );
	}

	// 「日付で絞り込む」は「絞り込み検索」の子項目。親のチェックが外れたら子も外し、
	// 親がOFFの間は子を disabled にする（サーバー側 Settings_Sanitizer も親OFFなら強制OFF）。
	function updateReservationDateSearchState() {
		const $parent = $( '#vkbm-reservation-show-menu-search' );
		const $child = $( '#vkbm-reservation-date-search-enabled' );
		if ( ! $parent.length || ! $child.length ) {
			return;
		}

		const parentChecked = $parent.is( ':checked' );

		if ( ! parentChecked ) {
			$child.prop( 'checked', false );
		}

		$child.prop( 'disabled', ! parentChecked );
	}

	function updatePrivacyPolicyModeState() {
		const $select = $( '#vkbm-provider-privacy-policy-mode' );
		const $urlField = $( '#vkbm-provider-privacy-policy-url-field' );
		const $contentField = $(
			'#vkbm-provider-privacy-policy-content-field'
		);

		if ( ! $select.length ) {
			return;
		}

		const mode = $select.val();
		const isUrl = 'url' === mode;
		const isContent = 'content' === mode;

		if ( $urlField.length ) {
			$urlField.prop( 'hidden', ! isUrl );
		}

		if ( $contentField.length ) {
			$contentField.prop( 'hidden', ! isContent );
		}
	}

	// #864: フッターの背景色・文字色のどちらかが入っているときだけ、
	// 「見出しをフッターと同じ色にする」チェックボックスの行を表示する（サーバー側の初期表示と同じ条件）。
	// 隠してもチェックの状態は送信・保持する。
	// カラーピッカーの change は入力欄の値を書き換える前に呼ばれるため、
	// 変更中の入力欄は changedInput / changedValue で受け取った新しい値で判定する。
	function updateMenuHeadingUseFooterColorRow( changedInput, changedValue ) {
		const row = document.getElementById(
			'vkbm-design-menu-heading-use-footer-color-row'
		);
		if ( ! row ) {
			return;
		}

		const hasFooterColor = [
			'vkbm-design-footer-background-color',
			'vkbm-design-footer-text-color',
		].some( function ( id ) {
			const input = document.getElementById( id );
			if ( ! input ) {
				return false;
			}
			const value = input === changedInput ? changedValue : input.value;
			// カラーピッカーは「#」だけの入力も空欄として扱う。
			const trimmedValue = String( value ).trim();
			return '' !== trimmedValue && '#' !== trimmedValue;
		} );

		row.hidden = ! hasFooterColor;
	}

	function initColorPicker() {
		if ( typeof $.fn.wpColorPicker !== 'function' ) {
			return;
		}

		$( '.vkbm-color-picker' ).wpColorPicker( {
			defaultColor: false,
			// 色を選んだとき（入力欄への直接入力を含む）。this は変更された入力欄。
			change( event, ui ) {
				updateMenuHeadingUseFooterColorRow( this, ui.color.toString() );
			},
			// 「クリア」ボタン、または入力欄を空にしたとき。入力欄の値は空になっている。
			clear() {
				updateMenuHeadingUseFooterColorRow();
			},
		} );

		// 再読み込み時にブラウザが入力欄の値を戻した場合も、行の表示を入力欄の値に合わせる。
		updateMenuHeadingUseFooterColorRow();
	}

	function setLogo( attachment ) {
		const $input = $( '#vkbm-provider-logo-id' );
		const $preview = $( '#vkbm-provider-logo-preview-container' );
		const $remove = $( '#vkbm-provider-logo-remove' );
		const imageUrl =
			attachment.sizes && attachment.sizes.medium
				? attachment.sizes.medium.url
				: attachment.url;

		$input.val( attachment.id );

		$preview.empty().append(
			$( '<img />', {
				src: imageUrl,
				class: 'vkbm-provider-logo-image',
				alt: '',
			} )
		);

		$remove.prop( 'hidden', false );
	}

	function clearLogo() {
		const $input = $( '#vkbm-provider-logo-id' );
		const $preview = $( '#vkbm-provider-logo-preview-container' );
		const $remove = $( '#vkbm-provider-logo-remove' );

		$input.val( '' );
		$preview.empty();
		$remove.prop( 'hidden', true );
	}

	function openMediaFrame() {
		if ( frame ) {
			frame.open();
			return;
		}

		frame = wp.media( {
			title: settings.logoFrameTitle,
			button: {
				text: settings.logoFrameButton,
			},
			library: {
				type: [ 'image' ],
			},
			multiple: false,
		} );

		frame.on( 'select', function () {
			const attachment = frame
				.state()
				.get( 'selection' )
				.first()
				.toJSON();
			setLogo( attachment );
		} );

		frame.open();
	}

	function getNextHolidayIndex() {
		let current = parseInt( $holidayIndex.val(), 10 );

		if ( isNaN( current ) ) {
			current = $holidayTable.children().length;
		}

		$holidayIndex.val( current + 1 );

		return current;
	}

	function addHolidayRow() {
		if ( ! holidayTemplate ) {
			return;
		}

		const index = getNextHolidayIndex();
		const html = holidayTemplate.replace( /__INDEX__/g, index );
		$holidayTable.append( html );
	}

	/**
	 * 特定日（宿泊プランの料金）の空の行を末尾に足し、日付入力へフォーカスを移す。
	 */
	function addLodgingSpecialDateRow() {
		const $rows = $( '#vkbm-lodging-special-dates-rows' );
		const template = getTemplateHtml(
			'#vkbm-lodging-special-date-row-template'
		);
		if ( 0 === $rows.length || ! template ) {
			return;
		}

		let index = parseInt( $rows.attr( 'data-next-index' ), 10 );
		if ( isNaN( index ) ) {
			index = $rows.children().length;
		}
		$rows.attr( 'data-next-index', index + 1 );

		const $row = $( template.replace( /__INDEX__/g, index ) );
		$rows.append( $row );
		$row.find( 'input' ).first().trigger( 'focus' );
	}

	function getNextIndex( $field, $items ) {
		let current = parseInt( $field.val(), 10 );

		if ( isNaN( current ) ) {
			current = $items.length;
		}

		$field.val( current + 1 );

		return current;
	}

	function addBasicSlot() {
		if ( ! basicTemplate || ! $basicSlots ) {
			return;
		}

		const index = getNextIndex( $basicNextIndex, $basicSlots.children() );
		const html = basicTemplate.replace( /__INDEX__/g, index );

		$basicSlots.append( html );
	}

	function removeBasicSlot( $button ) {
		const $slot = $button.closest( '.vkbm-business-hours-slot' );

		$slot.remove();

		if ( 0 === $basicSlots.children().length ) {
			addBasicSlot();
		}
	}

	function addReminderRow() {
		if ( ! reminderTemplate || ! $reminderList || ! $reminderIndex ) {
			return;
		}

		const index = getNextIndex( $reminderIndex, $reminderList.children() );
		const html = reminderTemplate.replace( /__INDEX__/g, index );

		$reminderList.append( html );
		updateReminderRemoveLabel( $reminderList.children().last() );
	}

	/**
	 * 予約リマインダーの行の「削除」ボタンに、入力中の時間を含む読み上げ用ラベルを設定する。
	 *
	 * @param {jQuery} $row 予約リマインダーの行。
	 */
	function updateReminderRemoveLabel( $row ) {
		const hours = String( $row.find( 'input[type="number"]' ).val() || '' );
		const l10n = window.vkbmProviderSettings || {};
		const label =
			'' === hours
				? l10n.reminderRemoveLabelEmpty
				: ( l10n.reminderRemoveLabel || '' ).replace( '%s', hours );

		if ( label ) {
			$row.find( '.vkbm-reminder-hours-remove' ).attr(
				'aria-label',
				label
			);
		}
	}

	function removeReminderRow( $button ) {
		const $row = $button.closest( '.vkbm-reminder-hours__row' );
		$row.remove();
	}

	function getWeeklySlotTemplate( $row ) {
		let template =
			getTemplateHtml(
				$row.find( '.vkbm-business-hours-slot-template' )
			) || '';

		if ( template ) {
			template = template.trim();
		}

		return template;
	}

	function addWeeklySlot( $row ) {
		const template = getWeeklySlotTemplate( $row );

		if ( ! template ) {
			return;
		}

		const $list = $row.find( '.vkbm-business-hours-slot-list' );
		const $nextField = $row.find( '.vkbm-business-hours-next-slot-index' );
		const index = getNextIndex( $nextField, $list.children() );
		const html = template.replace( /__INDEX__/g, index );
		const $slot = $( html );

		$list.append( $slot );
		toggleWeeklyRowState( $row );
	}

	function addWeeklySlotFromData( $row, slotData ) {
		const $list = $row.find( '.vkbm-business-hours-slot-list' );
		const $nextField = $row.find( '.vkbm-business-hours-next-slot-index' );
		const template = getWeeklySlotTemplate( $row );

		if ( ! template ) {
			return;
		}

		const index = getNextIndex( $nextField, $list.children() );
		const html = template.replace( /__INDEX__/g, index );
		const $slot = $( html );

		if ( slotData ) {
			$slot
				.find( 'select[name$="[start_hour]" ]' )
				.val( slotData.start_hour || '' );
			$slot
				.find( 'select[name$="[start_minute]" ]' )
				.val( slotData.start_minute || '' );
			$slot
				.find( 'select[name$="[end_hour]" ]' )
				.val( slotData.end_hour || '' );
			$slot
				.find( 'select[name$="[end_minute]" ]' )
				.val( slotData.end_minute || '' );
		}

		$list.append( $slot );
	}

	function removeWeeklySlot( $button ) {
		const $slot = $button.closest( '.vkbm-business-hours-slot' );
		const $row = $slot.closest( '.vkbm-business-hours-row' );
		const $list = $row.find( '.vkbm-business-hours-slot-list' );

		$slot.remove();

		if ( 0 === $list.children().length ) {
			if (
				$row
					.find( '.vkbm-business-hours-use-custom' )
					.is( ':checked' ) &&
				! $row.hasClass( 'is-regular-holiday' )
			) {
				addWeeklySlot( $row );
			}
			return;
		}

		toggleWeeklyRowState( $row );
	}

	function toggleWeeklyRowState( $row ) {
		const isRegularHoliday = $row.hasClass( 'is-regular-holiday' );
		const useCustomCheckbox = $row.find(
			'.vkbm-business-hours-use-custom'
		);
		const useCustom = useCustomCheckbox.is( ':checked' );
		const $list = $row.find( '.vkbm-business-hours-slot-list' );
		const basicSlotsData = $list.data( 'basicSlots' );
		let parsedBasicSlots = [];

		if ( basicSlotsData ) {
			if ( typeof basicSlotsData === 'string' ) {
				try {
					parsedBasicSlots = JSON.parse( basicSlotsData ) || [];
				} catch ( error ) {
					parsedBasicSlots = [];
				}
			} else if ( Array.isArray( basicSlotsData ) ) {
				parsedBasicSlots = basicSlotsData;
			}

			$list.data( 'basicSlots', parsedBasicSlots );
		}

		if (
			useCustom &&
			! isRegularHoliday &&
			0 === $list.children().length
		) {
			if ( parsedBasicSlots.length ) {
				parsedBasicSlots.forEach( function ( slot ) {
					addWeeklySlotFromData( $row, slot );
				} );
			} else {
				addWeeklySlot( $row );
			}
		}

		$row.toggleClass( 'is-using-basic', ! useCustom );

		const shouldDisableControls = ! useCustom || isRegularHoliday;

		$row.find(
			'.vkbm-business-hours-hour, .vkbm-business-hours-minute, .vkbm-business-hours-remove-slot'
		).prop( 'disabled', shouldDisableControls );
		$row.find( '.vkbm-business-hours-add-slot' ).prop(
			'disabled',
			shouldDisableControls
		);
	}

	$( document ).on(
		'click',
		'#vkbm-provider-logo-select',
		function ( event ) {
			event.preventDefault();
			openMediaFrame();
		}
	);

	$( document ).on(
		'click',
		'#vkbm-provider-logo-remove',
		function ( event ) {
			event.preventDefault();
			clearLogo();
		}
	);

	$( document ).on( 'click', '#vkbm-regular-holiday-add', function ( event ) {
		event.preventDefault();
		addHolidayRow();
	} );

	$( document ).on(
		'click',
		'#vkbm-lodging-special-date-add',
		function ( event ) {
			event.preventDefault();
			addLodgingSpecialDateRow();
		}
	);

	$( document ).on(
		'click',
		'.vkbm-lodging-special-date-remove',
		function ( event ) {
			event.preventDefault();
			const $rows = $( '#vkbm-lodging-special-dates-rows' );
			$( this ).closest( '.vkbm-lodging-special-date-row' ).remove();

			// 行が無くなると保存時に項目が送られず、保存済みの値が残ってしまうため、空の行を 1 つ残す。
			if ( 0 === $rows.children().length ) {
				addLodgingSpecialDateRow();
			}
		}
	);

	$( document ).on(
		'click',
		'.vkbm-regular-holiday-remove',
		function ( event ) {
			event.preventDefault();
			const $row = $( this ).closest( 'tr' );
			$row.remove();

			if ( 0 === $holidayTable.children().length ) {
				addHolidayRow();
			}
		}
	);

	$( document ).on(
		'click',
		'.vkbm-business-hours-basic-add-slot',
		function ( event ) {
			event.preventDefault();
			addBasicSlot();
		}
	);

	$( document ).on(
		'click',
		'.vkbm-business-hours-basic-remove-slot',
		function ( event ) {
			event.preventDefault();
			removeBasicSlot( $( this ) );
		}
	);

	$( document ).on( 'click', '.vkbm-reminder-hours-add', function ( event ) {
		event.preventDefault();
		addReminderRow();
	} );

	$( document ).on(
		'click',
		'.vkbm-reminder-hours-remove',
		function ( event ) {
			event.preventDefault();
			removeReminderRow( $( this ) );
		}
	);

	$( document ).on(
		'input',
		'.vkbm-reminder-hours__row input[type="number"]',
		function () {
			updateReminderRemoveLabel(
				$( this ).closest( '.vkbm-reminder-hours__row' )
			);
		}
	);

	$( document ).on( 'change', '.vkbm-business-hours-use-custom', function () {
		const $row = $( this ).closest( '.vkbm-business-hours-row' );

		toggleWeeklyRowState( $row );
	} );

	$( document ).on(
		'click',
		'.vkbm-business-hours-add-slot',
		function ( event ) {
			event.preventDefault();
			addWeeklySlot( $( this ).closest( '.vkbm-business-hours-row' ) );
		}
	);

	$( document ).on(
		'click',
		'.vkbm-business-hours-remove-slot',
		function ( event ) {
			event.preventDefault();
			removeWeeklySlot( $( this ) );
		}
	);

	$( document ).on(
		'change',
		'#vkbm-provider-booking-status-mode',
		function () {
			updateBookingStatusModeState();
		}
	);

	$( document ).on(
		'change',
		'#vkbm-provider-booking-cancel-mode',
		function () {
			updateBookingCancelModeState();
		}
	);

	$( document ).on(
		'change',
		'#vkbm-reservation-show-menu-list',
		function () {
			updateReservationMenuListModeState();
		}
	);

	$( document ).on(
		'change',
		'#vkbm-reservation-show-menu-search',
		function () {
			updateResourceTagSearchState();
			updateReservationDateSearchState();
		}
	);

	$( document ).on(
		'change',
		'#vkbm-provider-privacy-policy-mode',
		function () {
			updatePrivacyPolicyModeState();
		}
	);

	/**
	 * 店舗タブの「お支払いの案内」入力欄へフォーカスを移す（#540）。
	 *
	 * 入力欄は全タブ共通のフォームに含まれ、店舗タブ以外では CSS で非表示になっているため、
	 * 表示されていない（offsetParent が null）ときは何もしない。
	 *
	 * @return {boolean} フォーカスを移せた場合は true。
	 */
	function focusPaymentInstructionsField() {
		const field = document.getElementById( 'vkbm-provider-payment-method' );
		if ( ! field || null === field.offsetParent ) {
			return false;
		}
		field.focus();
		return true;
	}

	// 上部の通知の「お支払いの案内を編集する」リンク。
	// 店舗タブを表示中なら画面遷移せずに入力欄へフォーカスを移す。
	// 他のタブでは通常どおり店舗タブへ遷移し、遷移後の読み込み時（下の初期化処理）にフォーカスを移す。
	$( document ).on(
		'click',
		'.vkbm-payment-instructions-notice__link',
		function ( event ) {
			if ( focusPaymentInstructionsField() ) {
				event.preventDefault();
			}
		}
	);

	/**
	 * `#` 以降が指す設定行が別のタブにあるとき（設定項目を移動する前の旧 URL）、正しいタブの
	 * 同じアンカーへ移動する。移動した場合は true を返す。
	 *
	 * @return {boolean} 移動した場合は true。
	 */
	function redirectToTabOfHashTarget() {
		const redirectApi = window.vkbmProviderSettingsTabRedirect;
		const hash = window.location.hash;
		const $page = $( '.vkbm-provider-settings' );

		if ( ! redirectApi || ! hash || 1 === hash.length || ! $page.length ) {
			return false;
		}

		// ハッシュを CSS セレクターに渡すと記号で例外になりうるため、id で直接探す。
		let targetId = hash.slice( 1 );
		try {
			targetId = decodeURIComponent( targetId );
		} catch ( error ) {
			return false;
		}
		const target = document.getElementById( targetId );
		const row = target ? target.closest( 'tr' ) : null;

		if ( ! row ) {
			return false;
		}

		// 画面のタブ一覧に移動先が無いとき（拡張プラグインがタブを外した環境）は、再読み込みの繰り返しを防ぐため移動しない。
		const availableTabs = $( '.nav-tab-wrapper a.nav-tab' )
			.map( function () {
				return new URL(
					this.href,
					window.location.href
				).searchParams.get( 'tab' );
			} )
			.get();
		const redirectTab = redirectApi.resolveRedirectTab(
			String( $page.attr( 'data-active-tab' ) || '' ),
			row.className,
			availableTabs
		);

		if ( '' === redirectTab ) {
			return false;
		}

		window.location.replace(
			redirectApi.buildRedirectUrl( window.location.href, redirectTab )
		);

		return true;
	}

	/**
	 * 利用者が操作できる設定項目の現在値を比較用の文字列へ変換する。
	 *
	 * name を持たない項目と disabled の項目は、送信されないため除外する。
	 *
	 * @param {HTMLFormElement} form 設定フォーム。
	 * @return {string} 比較用に直列化したフォームの内容。
	 */
	function serializeEditableSettings( form ) {
		const values = [];

		form.querySelectorAll( 'input, select, textarea' ).forEach(
			function ( control ) {
				if ( control.disabled || ! control.name ) {
					return;
				}

				const entry = {
					name: control.name,
					type: control.type,
					value: control.value,
				};

				if (
					control instanceof HTMLInputElement &&
					( 'checkbox' === control.type || 'radio' === control.type )
				) {
					entry.checked = control.checked;
				}

				if (
					control instanceof HTMLSelectElement &&
					control.multiple
				) {
					entry.value = Array.from( control.selectedOptions ).map(
						function ( option ) {
							return option.value;
						}
					);
				}

				values.push( entry );
			}
		);

		return JSON.stringify( values );
	}

	/**
	 * 未保存の設定がある状態で画面を離れる操作に確認を追加する。
	 */
	function initUnsavedChangesGuard() {
		const page = document.querySelector( '.vkbm-provider-settings' );
		const form = page
			? page.querySelector( '.vkbm-provider-settings__form' )
			: null;
		if ( ! page || ! form ) {
			return;
		}

		const initialValues = serializeEditableSettings( form );
		const hasRestoredInput = '1' === page.dataset.hasRestoredInput;
		let guardEnabled = true;
		let lastSubmitEvent = null;

		const hasUnsavedChanges = function () {
			return (
				guardEnabled &&
				( hasRestoredInput ||
					initialValues !== serializeEditableSettings( form ) )
			);
		};

		const handleBeforeUnload = function ( event ) {
			if (
				! hasUnsavedChanges() ||
				( lastSubmitEvent && ! lastSubmitEvent.defaultPrevented )
			) {
				return;
			}

			event.preventDefault();
			event.returnValue = '';
		};

		window.addEventListener( 'beforeunload', handleBeforeUnload );

		// submit が別処理で取り消された場合は、未保存変更の監視を継続する。
		form.addEventListener( 'submit', function ( event ) {
			lastSubmitEvent = event;
		} );

		// 本体と拡張プラグインのタブ、および本文内にある別タブへのリンクを一括で扱う。
		page.addEventListener( 'click', function ( event ) {
			const eventTarget = event.target;
			const link =
				eventTarget instanceof Element
					? eventTarget.closest( 'a[href]' )
					: null;
			if (
				! link ||
				event.defaultPrevented ||
				0 !== event.button ||
				event.metaKey ||
				event.ctrlKey ||
				event.shiftKey ||
				'_blank' === link.target
			) {
				return;
			}

			const targetUrl = new URL( link.href, window.location.href );
			const currentUrl = new URL( window.location.href );
			targetUrl.hash = '';
			currentUrl.hash = '';
			if ( targetUrl.href === currentUrl.href ) {
				return;
			}

			const targetTab = targetUrl.searchParams.get( 'tab' ) || 'store';
			const isSettingsTabLink =
				targetUrl.origin === window.location.origin &&
				targetUrl.pathname === window.location.pathname &&
				'vkbm-provider-settings' ===
					targetUrl.searchParams.get( 'page' ) &&
				targetTab !== page.getAttribute( 'data-active-tab' );

			if ( ! isSettingsTabLink || ! hasUnsavedChanges() ) {
				return;
			}

			if ( ! window.confirm( settings.unsavedChangesMessage || '' ) ) {
				event.preventDefault();
				return;
			}

			guardEnabled = false;
			window.removeEventListener( 'beforeunload', handleBeforeUnload );
		} );
	}

	$( function () {
		if ( redirectToTabOfHashTarget() ) {
			return;
		}

		// 「お支払いの案内を編集する」リンクで店舗タブへ遷移してきた場合は、入力欄へフォーカスを移す。
		if ( '#vkbm-provider-payment-method' === window.location.hash ) {
			focusPaymentInstructionsField();

			// URL からハッシュを取り除く。フォームの送信先（action=""）は現在の URL のため、
			// ハッシュが残ると保存後の画面も入力欄までスクロールし、「保存しました」の通知が画面外に出てしまう。
			if ( window.history && window.history.replaceState ) {
				window.history.replaceState(
					null,
					'',
					window.location.pathname + window.location.search
				);
			}
		}

		holidayTemplate =
			getTemplateHtml( '#vkbm-regular-holiday-row-template' ) || '';
		$holidayTable = $( '#vkbm-regular-holiday-rows' );
		$holidayIndex = $( '#vkbm-regular-holiday-next-index' );
		$basicSlots = $( '#vkbm-business-hours-basic-slots' );
		$basicNextIndex = $( '#vkbm-business-hours-basic-next-index' );

		if ( holidayTemplate ) {
			holidayTemplate = holidayTemplate.trim();
		}

		basicTemplate =
			getTemplateHtml( '#vkbm-business-hours-basic-slot-template' ) || '';
		reminderTemplate =
			getTemplateHtml( '#vkbm-booking-reminder-template' ) || '';
		$reminderList = $( '#vkbm-booking-reminder-hours-list' );
		$reminderIndex = $( '#vkbm-booking-reminder-next-index' );

		if ( basicTemplate ) {
			basicTemplate = basicTemplate.trim();
		}

		if ( reminderTemplate ) {
			reminderTemplate = reminderTemplate.trim();
		}

		$( '.vkbm-business-hours-row' ).each( function () {
			toggleWeeklyRowState( $( this ) );
		} );

		updateBookingStatusModeState();
		updateBookingCancelModeState();
		updateReservationMenuListModeState();
		updateResourceTagSearchState();
		updateReservationDateSearchState();
		updatePrivacyPolicyModeState();
		initColorPicker();
		initIconPickers();
		initAttendeeNameMode();
		initIndustryPreset();
		initServiceMenuDetailDisplayModeNotice();
		initUnsavedChangesGuard();
	} );
} )( jQuery );
