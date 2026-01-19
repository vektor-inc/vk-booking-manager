( function ( $ ) {
	'use strict';

	const config = window.vkbmShiftEditor || {};

	const selectors = {
		daysJsonField: config.daysJsonField || '#vkbm-shift-days-json',
		daysContainer: config.daysContainer || '#vkbm-shift-days',
		daysTableBody: config.daysTableBody || '#vkbm-shift-days-body',
		yearSelector: config.yearSelector || '#vkbm-shift-year',
		monthSelector: config.monthSelector || '#vkbm-shift-month',
		resourceSelector: config.resourceSelector || '#vkbm-shift-resource',
		dayRowTemplate: config.dayRowTemplate || '#vkbm-shift-day-row-template',
		slotTemplate: config.slotTemplate || '#vkbm-shift-slot-template',
	};

	const $daysJsonField = $( selectors.daysJsonField );
	const $daysContainer = $( selectors.daysContainer );
	const $daysTableBody = $( selectors.daysTableBody );
	const $yearSelect = $( selectors.yearSelector );
	const $monthSelect = $( selectors.monthSelector );
	const $resourceSelect = $( selectors.resourceSelector );
	const dayRowTemplate = $( selectors.dayRowTemplate ).html() || '';
	const slotTemplate = $( selectors.slotTemplate ).html() || '';

	const STATUS = {
		OPEN: 'open',
		UNAVAILABLE: 'unavailable',
		REGULAR_HOLIDAY: 'regular_holiday',
		TEMPORARY_OPEN: 'temporary_open',
		TEMPORARY_CLOSED: 'temporary_closed',
	};
	const STATUS_KEYS = Object.values( STATUS );
	const CLOSED_STATUS_LOOKUP = {
		[ STATUS.UNAVAILABLE ]: true,
		[ STATUS.REGULAR_HOLIDAY ]: true,
		[ STATUS.TEMPORARY_CLOSED ]: true,
	};
	const DIMMED_STATUS_LOOKUP = {
		[ STATUS.REGULAR_HOLIDAY ]: true,
		[ STATUS.TEMPORARY_CLOSED ]: true,
	};
	const statusOptions = Array.isArray( config.statusOptions )
		? config.statusOptions
				.filter( ( option ) => option && 'object' === typeof option && option.value )
				.map( ( option ) => ( {
					value: String( option.value ),
					label: option.label || option.value,
				} ) )
		: [];
	if ( 0 === statusOptions.length ) {
		statusOptions.push(
			{ value: STATUS.OPEN, label: 'Normal' },
			{ value: STATUS.UNAVAILABLE, label: 'Off' },
			{ value: STATUS.REGULAR_HOLIDAY, label: 'Regular holiday' },
			{ value: STATUS.TEMPORARY_OPEN, label: 'Special opening' },
			{ value: STATUS.TEMPORARY_CLOSED, label: 'Temporary closure' }
		);
	}
	const statusLabelText = config.strings?.statusLabel || 'operational status';
	const closedMessage = config.strings?.closedMessage || 'You cannot set the time zone in this status.';
	const holidayRules = Array.isArray( config.holidayRules ) ? config.holidayRules : [];

	let workingDays = normalizeDays( config.daysData || {} );
	let defaultDays = normalizeDays( config.defaultDays || {} );
	const initialYear = parseInt( config.initialYear, 10 ) || new Date().getFullYear();
	const initialMonth = parseInt( config.initialMonth, 10 ) || 1;
	let weekdayDefaults = ( config.weekdayDefaults && 'object' === typeof config.weekdayDefaults )
		? config.weekdayDefaults
		: buildWeekdayDefaultsFromDays( defaultDays, initialYear, initialMonth );

	const weekdayLabels = {
		sun: config.strings?.weekdayShort?.sun || 'Sun',
		mon: config.strings?.weekdayShort?.mon || 'Mon',
		tue: config.strings?.weekdayShort?.tue || 'Tue',
		wed: config.strings?.weekdayShort?.wed || 'Wed',
		thu: config.strings?.weekdayShort?.thu || 'Thu',
		fri: config.strings?.weekdayShort?.fri || 'Fri',
		sat: config.strings?.weekdayShort?.sat || 'Sat',
	};
	const weekdayKeys = [ 'sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat' ];

	function normalizeDays( days ) {
		const normalized = {};

		if ( ! days || 'object' !== typeof days ) {
			return normalized;
		}

		Object.keys( days ).forEach( ( key ) => {
			const day = parseInt( key, 10 );

			if ( Number.isNaN( day ) ) {
				return;
			}

			const dayKey = ensureDayKey( day );
			const entry = days[ key ];
			let status = STATUS.OPEN;
			let slotsCandidate = [];

			if ( Array.isArray( entry ) ) {
				slotsCandidate = entry;
			} else if ( entry && 'object' === typeof entry ) {
				if ( 'string' === typeof entry.status && STATUS_KEYS.indexOf( entry.status ) !== -1 ) {
					status = entry.status;
				}

				if ( Array.isArray( entry.slots ) ) {
					slotsCandidate = entry.slots;
				} else if ( Array.isArray( entry[ 0 ] ) ) {
					slotsCandidate = entry;
				}
			}

			const slots = normalizeSlotList( slotsCandidate );

			normalized[ dayKey ] = {
				status,
				slots: isClosedStatus( status ) ? [] : slots,
			};
		} );

		return normalized;
	}

	function sanitizeTime( time ) {
		if ( 'string' !== typeof time ) {
			return '';
		}

		const trimmed = time.trim();
		return /^([01][0-9]|2[0-3]):([0-5][0-9])$/.test( trimmed ) ? trimmed : '';
	}

	function normalizeSlotList( slots ) {
		if ( ! Array.isArray( slots ) ) {
			return [];
		}

		return slots
			.map( ( slot ) => {
				if ( ! slot || 'object' !== typeof slot ) {
					return null;
				}

				const start = sanitizeTime( slot.start || '' );
				const end = sanitizeTime( slot.end || '' );

				if ( ! start || ! end || end <= start ) {
					return null;
				}

				return { start, end };
			} )
			.filter( ( slot ) => !! slot );
	}

	function getDaysInMonth( year, month ) {
		return new Date( year, month, 0 ).getDate();
	}

	function getWeekdayLabel( year, month, day ) {
		const date = new Date( year, month - 1, day );
		if ( Number.isNaN( date.getTime() ) ) {
			return '';
		}

		const index = date.getDay();
		return weekdayLabels[ weekdayKeys[ index ] ] || '';
	}

	function ensureDayKey( day ) {
		return String( day );
	}

	function isClosedStatus( status ) {
		return !! CLOSED_STATUS_LOOKUP[ status ];
	}

	function isValidStatus( status ) {
		return STATUS_KEYS.indexOf( status ) !== -1;
	}

	function shouldDimStatus( status ) {
		return !! DIMMED_STATUS_LOOKUP[ status ];
	}

	function cloneSlots( slots ) {
		if ( ! Array.isArray( slots ) ) {
			return [];
		}

	return slots.map( ( slot ) => ( {
		start: sanitizeTime( slot.start || '' ),
		end: sanitizeTime( slot.end || '' ),
	} ) ).filter( ( slot ) => slot.start && slot.end && slot.end > slot.start );
}

function buildWeekdayDefaultsFromDays( days, year, month ) {
	const map = {};

	if ( ! days || 'object' !== typeof days ) {
		return map;
	}

	Object.keys( days ).forEach( ( key ) => {
		const day = parseInt( key, 10 );

		if ( Number.isNaN( day ) ) {
			return;
		}

		const entry = days[ key ] || {};
		const weekdayKey = getWeekdayKey( year, month, day );

		if ( ! weekdayKey || map[ weekdayKey ] ) {
			return;
		}

		const status = STATUS_KEYS.indexOf( entry.status ) !== -1 ? entry.status : STATUS.OPEN;
		const slots = cloneSlots( entry.slots || [] );

		map[ weekdayKey ] = {
			status,
			slots,
		};
	} );

	return map;
}

function getDayData( dayKey ) {
	const existing = workingDays[ dayKey ];

	if ( existing && 'object' === typeof existing ) {
		return {
			status: isValidStatus( existing.status ) ? existing.status : STATUS.OPEN,
			slots: Array.isArray( existing.slots ) ? existing.slots.slice( 0 ) : [],
		};
	}

	return {
		status: STATUS.OPEN,
		slots: [],
	};
}

function getDefaultTemplateForDay( year, month, day, holidayFlags ) {
	const weekdayKey = getWeekdayKey( year, month, day );
	const base = weekdayDefaults[ weekdayKey ]
		? {
			status: STATUS_KEYS.indexOf( weekdayDefaults[ weekdayKey ].status ) !== -1
				? weekdayDefaults[ weekdayKey ].status
				: STATUS.OPEN,
			slots: cloneSlots( weekdayDefaults[ weekdayKey ].slots || [] ),
		}
		: {
			status: STATUS.OPEN,
			slots: [],
		};

	const dayKey = ensureDayKey( day );

	if ( holidayFlags[ dayKey ] ) {
		return {
			status: STATUS.REGULAR_HOLIDAY,
			slots: [],
		};
	}

	if ( base.status === STATUS.UNAVAILABLE ) {
		return {
			status: STATUS.UNAVAILABLE,
			slots: [],
		};
	}

	return base;
}

	function renderStatusOptions( $select, selected ) {
		$select.empty();

		statusOptions.forEach( ( option ) => {
			const value = option.value;
			const label = option.label || value;

			const $option = $( '<option></option>' )
				.attr( 'value', value )
				.text( label );

			if ( value === selected ) {
				$option.prop( 'selected', true );
			}

			$select.append( $option );
		} );

		var matched = false;
		for ( var i = 0; i < statusOptions.length; i++ ) {
			if ( statusOptions[ i ].value === selected ) {
				matched = true;
				break;
			}
		}

		if ( ! matched ) {
			$select.val( STATUS.OPEN );
		}
	}

	function reindexSlots( $container ) {
		$container.find( '.vkbm-shift-slot' ).each( function ( index ) {
			$( this ).attr( 'data-index', index );
		} );
	}

	function getWeekdayKey( year, month, day ) {
		const date = new Date( year, month - 1, day );
		if ( Number.isNaN( date.getTime() ) ) {
			return '';
		}

		const index = date.getDay();
		return weekdayKeys[ index ] || '';
	}

	function getHolidayDaySet( year, month ) {
		if ( ! holidayRules.length ) {
			return {};
		}

		const weeklyRules = {};
		const nthRules = {};

		holidayRules.forEach( ( rule ) => {
			if ( ! rule || 'object' !== typeof rule ) {
				return;
			}

			const freq = String( rule.frequency || '' );
			const weekday = String( rule.weekday || '' );

			if ( ! weekday ) {
				return;
			}

			if ( 'weekly' === freq ) {
				weeklyRules[ weekday ] = true;
				return;
			}

			if ( freq.startsWith( 'nth-' ) ) {
				const nthValue = parseInt( freq.split( '-' )[ 1 ], 10 );

				if ( Number.isNaN( nthValue ) ) {
					return;
				}

				if ( ! Array.isArray( nthRules[ weekday ] ) ) {
					nthRules[ weekday ] = [];
				}

				nthRules[ weekday ].push( nthValue );
			}
		} );

		const occurrences = {};
		const holidays = {};
		const daysInMonth = getDaysInMonth( year, month );

		for ( let day = 1; day <= daysInMonth; day++ ) {
			const weekdayKey = getWeekdayKey( year, month, day );

			if ( ! weekdayKey ) {
				continue;
			}

			occurrences[ weekdayKey ] = ( occurrences[ weekdayKey ] || 0 ) + 1;

			if ( weeklyRules[ weekdayKey ] ) {
				holidays[ ensureDayKey( day ) ] = true;
			}

			const nthList = nthRules[ weekdayKey ];

			if ( Array.isArray( nthList ) && nthList.indexOf( occurrences[ weekdayKey ] ) !== -1 ) {
				holidays[ ensureDayKey( day ) ] = true;
			}
		}

		return holidays;
	}

	function renderDays() {
		const year = parseInt( $yearSelect.val(), 10 );
		const month = parseInt( $monthSelect.val(), 10 );

		if ( Number.isNaN( year ) || Number.isNaN( month ) ) {
			return;
		}

		const daysInMonth = getDaysInMonth( year, month );
		const fragment = document.createDocumentFragment();
		const holidayFlags = getHolidayDaySet( year, month );

		for ( let day = 1; day <= daysInMonth; day++ ) {
			const dayKey = ensureDayKey( day );
			const rowHtml = dayRowTemplate.replace( /__DAY__/g, dayKey );
			const $row = $( rowHtml );
			const weekday = getWeekdayLabel( year, month, day );
			const dayLabel = `${ day }${ config.strings?.daySuffix || '' }${ weekday ? ' (' + weekday + ')' : '' }`;
		const defaultTemplate = getDefaultTemplateForDay( year, month, day, holidayFlags );
		const hasExisting = Object.prototype.hasOwnProperty.call( workingDays, dayKey );
		let dayData = getDayData( dayKey );
		let slots = Array.isArray( dayData.slots ) ? dayData.slots : [];

		if ( holidayFlags[ dayKey ] && dayData.status === STATUS.TEMPORARY_CLOSED ) {
			dayData.status = STATUS.REGULAR_HOLIDAY;
			slots = [];
			workingDays[ dayKey ] = {
				status: dayData.status,
				slots: [],
			};
		}

		if ( ! hasExisting ) {
			dayData = {
				status: defaultTemplate.status,
				slots: cloneSlots( defaultTemplate.slots ),
				};
				workingDays[ dayKey ] = {
					status: dayData.status,
					slots: cloneSlots( dayData.slots ),
				};
				slots = dayData.slots;
			} else if ( dayData.status === STATUS.OPEN && slots.length === 0 && defaultTemplate.slots.length ) {
				dayData.slots = cloneSlots( defaultTemplate.slots );
				slots = dayData.slots;
				workingDays[ dayKey ] = {
					status: dayData.status,
					slots: cloneSlots( dayData.slots ),
				};
			}

			$row.find( '.vkbm-shift-day-label-text' ).text( dayLabel );
			$row.attr( 'data-status', dayData.status );

			$row.removeClass( 'is-holiday is-status-closed' );

			if ( dayData.status === STATUS.REGULAR_HOLIDAY ) {
				$row.addClass( 'is-holiday' );
			}

			const $statusSelect = $row.find( '.vkbm-shift-day-status' );
			const $slotsContainer = $row.find( '.vkbm-shift-day-slots' );
			const $message = $row.find( '.vkbm-shift-day-message' );
			const $addButton = $row.find( '.vkbm-shift-add-slot' );


			renderStatusOptions( $statusSelect, dayData.status );
			$statusSelect.val( dayData.status );

			if ( isClosedStatus( dayData.status ) ) {
				if ( shouldDimStatus( dayData.status ) ) {
					$row.addClass( 'is-status-closed' );
				}

				$slotsContainer.empty();
				$message.text( closedMessage );
				$addButton.prop( 'disabled', true ).addClass( 'is-disabled' );
			} else {
				$row.removeClass( 'is-status-closed' );
				$message.text( '' );
				$addButton.prop( 'disabled', false ).removeClass( 'is-disabled' );
				$slotsContainer.empty();

				let slotsToRender = cloneSlots( slots );

				if ( 0 === slotsToRender.length ) {
					if ( defaultTemplate.slots.length ) {
						slotsToRender = cloneSlots( defaultTemplate.slots );
					} else {
						slotsToRender = [ createEmptySlot() ];
					}

					workingDays[ dayKey ] = {
						status: dayData.status,
						slots: cloneSlots( slotsToRender ),
					};
				}

				slotsToRender.forEach( ( slot, index ) => {
					addSlotElement( $slotsContainer, dayKey, slot, index );
				} );

				reindexSlots( $slotsContainer );
			}

			fragment.appendChild( $row.get( 0 ) );
		}

		$daysTableBody.empty().append( fragment );
	}

	function createEmptySlot() {
		return { start: '09:00', end: '18:00' };
	}

	function addSlotElement( $container, dayKey, slot, index ) {
		const slotIndex = Number.isInteger( index ) ? index : $container.children().length;
		let html = slotTemplate.replace( /__INDEX__/g, String( slotIndex ) );
		html = html.replace( /__DAY__/g, dayKey );

		const slotStart = sanitizeTime( slot.start ) || '09:00';
		const slotEnd = sanitizeTime( slot.end ) || '18:00';

		const $slot = $( html );

		$slot.find( 'select[data-field="start_hour"]' ).val( slotStart.substring( 0, 2 ) );
		$slot.find( 'select[data-field="start_minute"]' ).val( slotStart.substring( 3, 5 ) );
		$slot.find( 'select[data-field="end_hour"]' ).val( slotEnd.substring( 0, 2 ) );
		$slot.find( 'select[data-field="end_minute"]' ).val( slotEnd.substring( 3, 5 ) );

		$container.append( $slot );
	}

	function rebuildWorkingDays() {
		const year = parseInt( $yearSelect.val(), 10 );
		const month = parseInt( $monthSelect.val(), 10 );
		const daysInMonth = getDaysInMonth( year, month );
		const newDays = {};

		Object.keys( workingDays ).forEach( ( key ) => {
			const day = parseInt( key, 10 );
			if ( day >= 1 && day <= daysInMonth ) {
				newDays[ key ] = workingDays[ key ];
			}
		} );

		workingDays = newDays;
	}

	function gatherSlotsFromDom() {
		const updated = {};

		$daysTableBody.find( '.vkbm-shift-day-row' ).each( function () {
			const $row = $( this );
			const dayKey = ensureDayKey( $row.data( 'day' ) );
			const statusValue = String( $row.find( '.vkbm-shift-day-status' ).val() || STATUS.OPEN );
			const status = isValidStatus( statusValue ) ? statusValue : STATUS.OPEN;
			const slots = [];

			if ( ! isClosedStatus( status ) ) {
				$row.find( '.vkbm-shift-slot' ).each( function () {
					const $slot = $( this );
					const startHour = $slot.find( 'select[data-field="start_hour"]' ).val() || '';
					const startMinute = $slot.find( 'select[data-field="start_minute"]' ).val() || '';
					const endHour = $slot.find( 'select[data-field="end_hour"]' ).val() || '';
					const endMinute = $slot.find( 'select[data-field="end_minute"]' ).val() || '';

					const start = sanitizeTime( `${ startHour}:${ startMinute}` );
					const end = sanitizeTime( `${ endHour}:${ endMinute}` );

					if ( start && end && end > start ) {
						slots.push( { start, end } );
					}
				} );
			}

			if ( slots.length || status !== STATUS.OPEN ) {
				updated[ dayKey ] = {
					status,
					slots,
				};
			}
		} );

		workingDays = updated;
	}

	function syncHiddenField() {
		gatherSlotsFromDom();
		$daysJsonField.val( JSON.stringify( workingDays ) );
	}

	function handleAddSlot( dayKey ) {
		const dayData = getDayData( dayKey );

		if ( isClosedStatus( dayData.status ) ) {
			return;
		}

		const $slotsContainer = $daysTableBody.find( `.vkbm-shift-day-row[data-day="${ dayKey }"] .vkbm-shift-day-slots` );
		addSlotElement( $slotsContainer, dayKey, createEmptySlot() );
		reindexSlots( $slotsContainer );
		syncHiddenField();
	}

	function handleRemoveSlot( $button ) {
		const $slot = $button.closest( '.vkbm-shift-slot' );
		const $row = $slot.closest( '.vkbm-shift-day-row' );
		const dayKey = ensureDayKey( $row.data( 'day' ) );
		const statusValue = String( $row.find( '.vkbm-shift-day-status' ).val() || STATUS.OPEN );
		const status = isValidStatus( statusValue ) ? statusValue : STATUS.OPEN;

		$slot.remove();

		const $slotsContainer = $daysTableBody.find( `.vkbm-shift-day-row[data-day="${ dayKey }"] .vkbm-shift-day-slots` );
		if ( ! isClosedStatus( status ) && 0 === $slotsContainer.children().length ) {
			addSlotElement( $slotsContainer, dayKey, createEmptySlot() );
		}

		reindexSlots( $slotsContainer );
	}

	function init() {
		if ( ! dayRowTemplate || ! slotTemplate ) {
			return;
		}

		rebuildWorkingDays();
		renderDays();
		syncHiddenField();

		if ( Object.keys( workingDays ).length === 0 ) {
			const initialResource = parseInt( $resourceSelect.val(), 10 );

			if ( initialResource ) {
				fetchTemplate( initialResource );
			}
		}
	}

	// Event bindings.
	$( document ).on( 'change', `${ selectors.yearSelector }, ${ selectors.monthSelector }`, () => {
		workingDays = {};
		renderDays();
		syncHiddenField();
	} );

	$( document ).on( 'click', '.vkbm-shift-add-slot', function ( event ) {
		event.preventDefault();
		const dayKey = ensureDayKey( $( this ).data( 'day' ) );
		handleAddSlot( dayKey );
		const $slotsContainer = $daysContainer.find( `.vkbm-shift-day-row[data-day="${ dayKey }"] .vkbm-shift-day-slots` );
		reindexSlots( $slotsContainer );
		syncHiddenField();
	} );

	$( document ).on( 'click', '.vkbm-shift-remove-slot', function ( event ) {
		event.preventDefault();
		handleRemoveSlot( $( this ) );
		syncHiddenField();
	} );

	$( document ).on( 'change', '.vkbm-shift-day-status', function () {
		const $row = $( this ).closest( '.vkbm-shift-day-row' );
		const dayKey = ensureDayKey( $row.data( 'day' ) );
		let status = String( $( this ).val() || STATUS.OPEN );

		if ( ! isValidStatus( status ) ) {
			status = STATUS.OPEN;
		}

		gatherSlotsFromDom();

		const dayData = getDayData( dayKey );
		dayData.status = status;

		if ( isClosedStatus( status ) ) {
			dayData.slots = [];
		} else if ( ! Array.isArray( dayData.slots ) || 0 === dayData.slots.length ) {
			const defaultSlots = getDefaultSlots( dayKey );

			if ( defaultSlots.length ) {
				dayData.slots = cloneSlots( defaultSlots );
			}
		}

		workingDays[ dayKey ] = dayData;
		rebuildWorkingDays();
		renderDays();
		syncHiddenField();
	} );

	$( document ).on( 'change', '.vkbm-schedule-hour, .vkbm-schedule-minute', () => {
		syncHiddenField();
	} );

	$( '#post' ).on( 'submit', () => {
		syncHiddenField();
	} );

	$resourceSelect.on( 'change', function () {
		const resourceId = parseInt( $( this ).val(), 10 );

		if ( ! resourceId ) {
			return;
		}

		workingDays = {};
		weekdayDefaults = {};
		fetchTemplate( resourceId );
	} );

	function fetchTemplate( resourceId ) {
		if ( ! config.ajax?.url || ! config.ajax?.nonce ) {
			return;
		}

		const payload = {
			action: 'vkbm_shift_get_template',
			resource_id: resourceId,
			year: parseInt( $yearSelect.val(), 10 ) || 0,
			month: parseInt( $monthSelect.val(), 10 ) || 0,
			_ajax_nonce: config.ajax.nonce,
		};

	$.post( config.ajax.url, payload )
		.done( ( response ) => {
			if ( ! response || ! response.success || ! response.data ) {
				return;
			}

			const normalized = normalizeDays( response.data.days || {} );
			workingDays = normalized;
			defaultDays = normalized;
			weekdayDefaults = buildWeekdayDefaultsFromDays(
				normalized,
				parseInt( $yearSelect.val(), 10 ) || initialYear,
				parseInt( $monthSelect.val(), 10 ) || initialMonth
			);
			rebuildWorkingDays();
			renderDays();
			syncHiddenField();
		} )
		.fail( () => {
			// noop
		} );
	}

	$( init );
}( jQuery ) );
