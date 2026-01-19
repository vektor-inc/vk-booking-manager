( function ( $ ) {
	'use strict';

	const settings = window.vkbmResourceSchedule || {};
	const useProviderSelector = settings.useProviderHoursSelector || '#vkbm-resource-schedule-use-provider-hours';
	const daysContainerSelector = settings.daysContainerSelector || '#vkbm-resource-schedule-days';
	const defaultSlotsByDay = settings.defaultSlotsByDay || {};

	const $useProvider = $( useProviderSelector );
	const $daysContainer = $( daysContainerSelector );

	const weekdayLabels = [
		window?.vkbmResourceSchedule?.strings?.weekdayShort?.sun || 'Sun',
		window?.vkbmResourceSchedule?.strings?.weekdayShort?.mon || 'Mon',
		window?.vkbmResourceSchedule?.strings?.weekdayShort?.tue || 'Tue',
		window?.vkbmResourceSchedule?.strings?.weekdayShort?.wed || 'Wed',
		window?.vkbmResourceSchedule?.strings?.weekdayShort?.thu || 'Thu',
		window?.vkbmResourceSchedule?.strings?.weekdayShort?.fri || 'Fri',
		window?.vkbmResourceSchedule?.strings?.weekdayShort?.sat || 'Sat',
	];

	const toggleDaysVisibility = () => {
		if ( $useProvider.is( ':checked' ) ) {
			$daysContainer.hide();
		} else {
			$daysContainer.show();
			applyDefaultSlotsIfEmpty();
		}
	};

	const isEmptyTimeValue = ( value ) => {
		return ! value || '00' === String( value );
	};

	const applyDefaultSlotsIfEmpty = () => {
		Object.keys( defaultSlotsByDay ).forEach( ( dayKey ) => {
			const slot = defaultSlotsByDay[ dayKey ];
			if ( ! slot || ! slot.start || ! slot.end ) {
				return;
			}

			const $dayContainer = $daysContainer.find( `.vkbm-resource-schedule-day[data-day="${ dayKey }"]` );
			const $slot = $dayContainer.find( '.vkbm-resource-schedule-slot' ).first();

			if ( ! $slot.length ) {
				return;
			}

			const $startHour = $slot.find( 'select[data-field="start_hour"]' );
			const $startMinute = $slot.find( 'select[data-field="start_minute"]' );
			const $endHour = $slot.find( 'select[data-field="end_hour"]' );
			const $endMinute = $slot.find( 'select[data-field="end_minute"]' );

			if (
				! isEmptyTimeValue( $startHour.val() ) ||
				! isEmptyTimeValue( $startMinute.val() ) ||
				! isEmptyTimeValue( $endHour.val() ) ||
				! isEmptyTimeValue( $endMinute.val() )
			) {
				return;
			}

			const startParts = String( slot.start ).split( ':' );
			const endParts = String( slot.end ).split( ':' );
			if ( 2 !== startParts.length || 2 !== endParts.length ) {
				return;
			}

			$startHour.val( startParts[ 0 ] );
			$startMinute.val( startParts[ 1 ] );
			$endHour.val( endParts[ 0 ] );
			$endMinute.val( endParts[ 1 ] );
		} );
	};

	const slotTemplate = () => {
		const template = $( '#vkbm-resource-schedule-slot-template' ).html();
		return template || '';
	};

	const reindexSlots = ( $container, dayKey ) => {
		$container.find( '.vkbm-resource-schedule-slot' ).each( function ( index ) {
			const $slot = $( this );
			$slot.attr( 'data-index', index );

			$slot.find( 'select[data-field]' ).each( function () {
				const $field = $( this );
				const fieldKey = String( $field.data( 'field' ) || '' );

				if ( ! fieldKey ) {
					return;
				}

				const name = `vkbm_resource_schedule[days][${ dayKey }][${ index }][${ fieldKey }]`;
				const id = `vkbm-resource-schedule-${ dayKey }-${ index }-${ fieldKey }`;

				$field.attr( {
					name,
					id,
				} );

				$slot.find( `label[data-field="${ fieldKey }"]` ).attr( 'for', id );
			} );
		} );
	};

	const addSlot = ( dayKey ) => {
		const template = slotTemplate();
		if ( ! template ) {
			return;
		}

		const $dayContainer = $daysContainer.find( `.vkbm-resource-schedule-day[data-day="${ dayKey }"]` );
		const $slotsContainer = $dayContainer.find( '.vkbm-resource-schedule-slots' );
		const index = $slotsContainer.find( '.vkbm-resource-schedule-slot' ).length;
		const html = template.replace( /__DAY__/g, dayKey ).replace( /__INDEX__/g, String( index ) );

		$slotsContainer.append( html );
	};

	const ensureAtLeastOneSlot = ( dayKey ) => {
		const $dayContainer = $daysContainer.find( `.vkbm-resource-schedule-day[data-day="${ dayKey }"]` );
		const $slotsContainer = $dayContainer.find( '.vkbm-resource-schedule-slots' );

		if ( 0 === $slotsContainer.find( '.vkbm-resource-schedule-slot' ).length ) {
			addSlot( dayKey );
		}

		reindexSlots( $slotsContainer, dayKey );
	};

	$( document ).on( 'click', '.vkbm-resource-schedule-add-slot', function () {
		const dayKey = $( this ).data( 'day' );
		if ( ! dayKey ) {
			return;
		}

		addSlot( dayKey );
		const $slotsContainer = $daysContainer.find( `.vkbm-resource-schedule-day[data-day="${ dayKey }"] .vkbm-resource-schedule-slots` );
		reindexSlots( $slotsContainer, dayKey );
	} );

	$( document ).on( 'click', '.vkbm-resource-schedule-remove-slot', function () {
		const $slot = $( this ).closest( '.vkbm-resource-schedule-slot' );
		const $dayContainer = $slot.closest( '.vkbm-resource-schedule-day' );
		const dayKey = $dayContainer.data( 'day' );

		$slot.remove();
		ensureAtLeastOneSlot( dayKey );
	} );

	if ( $useProvider.length ) {
		$useProvider.on( 'change', toggleDaysVisibility );
		toggleDaysVisibility();
	}

	$daysContainer.find( '.vkbm-resource-schedule-day' ).each( function () {
		const dayKey = $( this ).data( 'day' );
		const $slotsContainer = $( this ).find( '.vkbm-resource-schedule-slots' );
		reindexSlots( $slotsContainer, dayKey );
	} );
}( jQuery ) );
