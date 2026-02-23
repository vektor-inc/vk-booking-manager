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
	 * @return {string}
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

	function updateBookingCancelModeState() {
		const $mode = $( '#vkbm-provider-booking-cancel-mode' );
		const $hoursField = $( '#vkbm-provider-booking-cancel-hours-field' );
		const $hoursInput = $( '#vkbm-provider-booking-cancel-hours' );

		if ( ! $mode.length || ! $hoursField.length ) {
			return;
		}

		const isHours = 'hours' === $mode.val();

		$hoursField.toggle( isHours );
		if ( $hoursInput.length ) {
			$hoursInput.prop( 'disabled', ! isHours );
		}
	}

	function updateReservationMenuListModeState() {
		const $checkbox = $( '#vkbm-reservation-show-menu-list' );
		const $row = $( '#vkbm-reservation-menu-list-display-mode-row' );
		const $select = $( '#vkbm-reservation-menu-list-display-mode' );
		if ( ! $checkbox.length || ! $row.length ) {
			return;
		}

		const enabled = $checkbox.is( ':checked' );

		$row.toggle( enabled );
		if ( $select.length ) {
			$select.prop( 'disabled', ! enabled );
		}
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
			$urlField.toggle( isUrl );
		}

		if ( $contentField.length ) {
			$contentField.toggle( isContent );
		}
	}

	function initColorPicker() {
		if ( typeof $.fn.wpColorPicker !== 'function' ) {
			return;
		}

		$( '.vkbm-color-picker' ).wpColorPicker( {
			defaultColor: false,
		} );
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

		$remove.show();
	}

	function clearLogo() {
		const $input = $( '#vkbm-provider-logo-id' );
		const $preview = $( '#vkbm-provider-logo-preview-container' );
		const $remove = $( '#vkbm-provider-logo-remove' );

		$input.val( '' );
		$preview.empty();
		$remove.hide();
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
		'#vkbm-provider-privacy-policy-mode',
		function () {
			updatePrivacyPolicyModeState();
		}
	);

	$( function () {
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

		updateBookingCancelModeState();
		updateReservationMenuListModeState();
		updatePrivacyPolicyModeState();
		initColorPicker();
	} );
} )( jQuery );
