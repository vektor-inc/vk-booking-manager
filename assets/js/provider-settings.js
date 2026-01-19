(function( $ ) {
	'use strict';

	var frame;
	var settings = window.vkbmProviderSettings || {
		logoFrameTitle: '',
		logoFrameButton: ''
	};
	var holidayTemplate = '';
		var $holidayTable;
		var $holidayIndex;
		var basicTemplate = '';
		var $basicSlots;
		var $basicNextIndex;

		function updateBookingCancelModeState() {
			var $mode = $( '#vkbm-provider-booking-cancel-mode' );
			var $hoursField = $( '#vkbm-provider-booking-cancel-hours-field' );
		var $hoursInput = $( '#vkbm-provider-booking-cancel-hours' );

		if ( ! $mode.length || ! $hoursField.length ) {
			return;
		}

		var isHours = 'hours' === $mode.val();

		$hoursField.toggle( isHours );
		if ( $hoursInput.length ) {
			$hoursInput.prop( 'disabled', ! isHours );
		}
	}

	function updateReservationMenuListModeState() {
		var $checkbox = $( '#vkbm-reservation-show-menu-list' );
		var $row = $( '#vkbm-reservation-menu-list-display-mode-row' );
		var $select = $( '#vkbm-reservation-menu-list-display-mode' );
		if ( ! $checkbox.length || ! $row.length ) {
			return;
		}

		var enabled = $checkbox.is( ':checked' );

		$row.toggle( enabled );
		if ( $select.length ) {
			$select.prop( 'disabled', ! enabled );
		}

	}

	function updatePrivacyPolicyModeState() {
		var $select = $( '#vkbm-provider-privacy-policy-mode' );
		var $urlField = $( '#vkbm-provider-privacy-policy-url-field' );
		var $contentField = $( '#vkbm-provider-privacy-policy-content-field' );

		if ( ! $select.length ) {
			return;
		}

		var mode = $select.val();
		var isUrl = 'url' === mode;
		var isContent = 'content' === mode;

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
			defaultColor: false
		} );
	}

	function setLogo( attachment ) {
		var $input   = $( '#vkbm-provider-logo-id' );
		var $preview = $( '#vkbm-provider-logo-preview-container' );
		var $remove  = $( '#vkbm-provider-logo-remove' );
		var imageUrl = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;

		$input.val( attachment.id );

		$preview
			.empty()
			.append(
				$( '<img />', {
					src: imageUrl,
					class: 'vkbm-provider-logo-image',
					alt: ''
				} )
			);

		$remove.show();
	}

	function clearLogo() {
		var $input   = $( '#vkbm-provider-logo-id' );
		var $preview = $( '#vkbm-provider-logo-preview-container' );
		var $remove  = $( '#vkbm-provider-logo-remove' );

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
				text: settings.logoFrameButton
			},
			library: {
				type: [ 'image' ]
			},
			multiple: false
		} );

		frame.on( 'select', function() {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			setLogo( attachment );
		} );

		frame.open();
	}

	function getNextHolidayIndex() {
		var current = parseInt( $holidayIndex.val(), 10 );

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

		var index = getNextHolidayIndex();
		var html  = holidayTemplate.replace( /__INDEX__/g, index );
		$holidayTable.append( html );
	}

	function getNextIndex( $field, $items ) {
		var current = parseInt( $field.val(), 10 );

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

		var index = getNextIndex( $basicNextIndex, $basicSlots.children() );
		var html  = basicTemplate.replace( /__INDEX__/g, index );

		$basicSlots.append( html );
	}

	function removeBasicSlot( $button ) {
		var $slot = $button.closest( '.vkbm-business-hours-slot' );

		$slot.remove();

		if ( 0 === $basicSlots.children().length ) {
			addBasicSlot();
		}
	}

	function getWeeklySlotTemplate( $row ) {
		var template = $row.find( '.vkbm-business-hours-slot-template' ).html() || '';

		if ( template ) {
			template = template.trim();
		}

		return template;
	}

	function addWeeklySlot( $row ) {
		var template = getWeeklySlotTemplate( $row );

		if ( ! template ) {
			return;
		}

		var $list      = $row.find( '.vkbm-business-hours-slot-list' );
		var $nextField = $row.find( '.vkbm-business-hours-next-slot-index' );
		var index      = getNextIndex( $nextField, $list.children() );
		var html       = template.replace( /__INDEX__/g, index );
		var $slot      = $( html );

		$list.append( $slot );
		toggleWeeklyRowState( $row );
	}

	function addWeeklySlotFromData( $row, slotData ) {
		var $list = $row.find( '.vkbm-business-hours-slot-list' );
		var $nextField = $row.find( '.vkbm-business-hours-next-slot-index' );
		var template = getWeeklySlotTemplate( $row );

		if ( ! template ) {
			return;
		}

		var index = getNextIndex( $nextField, $list.children() );
		var html  = template.replace( /__INDEX__/g, index );
		var $slot = $( html );

		if ( slotData ) {
			$slot.find( 'select[name$="[start_hour]" ]' ).val( slotData.start_hour || '' );
			$slot.find( 'select[name$="[start_minute]" ]' ).val( slotData.start_minute || '' );
			$slot.find( 'select[name$="[end_hour]" ]' ).val( slotData.end_hour || '' );
			$slot.find( 'select[name$="[end_minute]" ]' ).val( slotData.end_minute || '' );
		}

		$list.append( $slot );
	}

	function removeWeeklySlot( $button ) {
		var $slot = $button.closest( '.vkbm-business-hours-slot' );
		var $row  = $slot.closest( '.vkbm-business-hours-row' );
		var $list = $row.find( '.vkbm-business-hours-slot-list' );

		$slot.remove();

	if ( 0 === $list.children().length ) {
		if ( $row.find( '.vkbm-business-hours-use-custom' ).is( ':checked' ) && ! $row.hasClass( 'is-regular-holiday' ) ) {
			addWeeklySlot( $row );
		}
		return;
	}

		toggleWeeklyRowState( $row );
	}

	function toggleWeeklyRowState( $row ) {
		var isRegularHoliday = $row.hasClass( 'is-regular-holiday' );
	var useCustomCheckbox = $row.find( '.vkbm-business-hours-use-custom' );
	var useCustom         = useCustomCheckbox.is( ':checked' );
	var $list            = $row.find( '.vkbm-business-hours-slot-list' );
	var basicSlotsData   = $list.data( 'basicSlots' );
	var parsedBasicSlots = [];

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

	if ( useCustom && ! isRegularHoliday && 0 === $list.children().length ) {
		if ( parsedBasicSlots.length ) {
			parsedBasicSlots.forEach( function( slot ) {
				addWeeklySlotFromData( $row, slot );
			} );
		} else {
			addWeeklySlot( $row );
		}
	}

	$row.toggleClass( 'is-using-basic', ! useCustom );

	var shouldDisableControls = ! useCustom || isRegularHoliday;

	$row
		.find( '.vkbm-business-hours-hour, .vkbm-business-hours-minute, .vkbm-business-hours-remove-slot' )
		.prop( 'disabled', shouldDisableControls );
	$row.find( '.vkbm-business-hours-add-slot' ).prop( 'disabled', shouldDisableControls );
	}

	$( document ).on( 'click', '#vkbm-provider-logo-select', function( event ) {
		event.preventDefault();
		openMediaFrame();
	} );

	$( document ).on( 'click', '#vkbm-provider-logo-remove', function( event ) {
		event.preventDefault();
		clearLogo();
	} );

	$( document ).on( 'click', '#vkbm-regular-holiday-add', function( event ) {
		event.preventDefault();
		addHolidayRow();
	} );

	$( document ).on( 'click', '.vkbm-regular-holiday-remove', function( event ) {
		event.preventDefault();
		var $row = $( this ).closest( 'tr' );
		$row.remove();

		if ( 0 === $holidayTable.children().length ) {
			addHolidayRow();
		}
	} );

	$( document ).on( 'click', '.vkbm-business-hours-basic-add-slot', function( event ) {
		event.preventDefault();
		addBasicSlot();
	} );

	$( document ).on( 'click', '.vkbm-business-hours-basic-remove-slot', function( event ) {
		event.preventDefault();
		removeBasicSlot( $( this ) );
	} );

	$( document ).on( 'change', '.vkbm-business-hours-use-custom', function() {
		var $row = $( this ).closest( '.vkbm-business-hours-row' );

		toggleWeeklyRowState( $row );
	} );

	$( document ).on( 'click', '.vkbm-business-hours-add-slot', function( event ) {
		event.preventDefault();
		addWeeklySlot( $( this ).closest( '.vkbm-business-hours-row' ) );
	} );

		$( document ).on( 'click', '.vkbm-business-hours-remove-slot', function( event ) {
			event.preventDefault();
			removeWeeklySlot( $( this ) );
		} );

		$( document ).on( 'change', '#vkbm-provider-booking-cancel-mode', function() {
			updateBookingCancelModeState();
		} );

		$( document ).on( 'change', '#vkbm-reservation-show-menu-list', function() {
			updateReservationMenuListModeState();
		} );

		$( document ).on( 'change', '#vkbm-provider-privacy-policy-mode', function() {
			updatePrivacyPolicyModeState();
		} );

	$( function() {
		holidayTemplate = $( '#vkbm-regular-holiday-row-template' ).html() || '';
			$holidayTable   = $( '#vkbm-regular-holiday-rows' );
			$holidayIndex   = $( '#vkbm-regular-holiday-next-index' );
			$basicSlots     = $( '#vkbm-business-hours-basic-slots' );
			$basicNextIndex = $( '#vkbm-business-hours-basic-next-index' );

			if ( holidayTemplate ) {
				holidayTemplate = holidayTemplate.trim();
			}

		basicTemplate = $( '#vkbm-business-hours-basic-slot-template' ).html() || '';

		if ( basicTemplate ) {
			basicTemplate = basicTemplate.trim();
		}

			$( '.vkbm-business-hours-row' ).each( function() {
				toggleWeeklyRowState( $( this ) );
			} );

			updateBookingCancelModeState();
			updateReservationMenuListModeState();
			updatePrivacyPolicyModeState();
			initColorPicker();
		} );
	})( jQuery );
