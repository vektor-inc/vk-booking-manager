import { __ } from '@wordpress/i18n';
import { useMemo } from '@wordpress/element';

const WEEKDAYS = [
	__('Sun', 'vk-booking-manager'),
	__('Mon', 'vk-booking-manager'),
	__('Tue', 'vk-booking-manager'),
	__('Wed', 'vk-booking-manager'),
	__('Thu', 'vk-booking-manager'),
	__('Fri', 'vk-booking-manager'),
	__('Sat', 'vk-booking-manager'),
];

const padZero = (value) => value.toString().padStart(2, '0');

const toISODate = (date) =>
	[
		date.getFullYear(),
		padZero(date.getMonth() + 1),
		padZero(date.getDate()),
	].join('-');

/**
 * Format month label based on locale.
 * Japanese: "2026年1月", English: "January 2026"
 *
 * @param {number} year  Year (e.g., 2026)
 * @param {number} month Month (1-12)
 * @param {string|undefined} locale WordPress locale (e.g., 'ja', 'en_US')
 * @return {string} Formatted month label
 */
const formatMonthLabel = (year, month, locale) => {
	// Create a date object for the first day of the month
	const date = new Date(year, month - 1, 1);

	// Default to 'en' if locale is not provided
	const safeLocale = locale || 'en';

	// Check if locale is Japanese (starts with 'ja')
	const isJapanese = typeof safeLocale === 'string' && safeLocale.startsWith('ja');

	if (isJapanese) {
		// Japanese format: "2026年1月"
		return new Intl.DateTimeFormat('ja-JP', {
			year: 'numeric',
			month: 'numeric',
		}).format(date);
	}

	// For other locales, use the locale string directly or default to 'en-US'
	// Convert WordPress locale format (e.g., 'en_US') to BCP 47 format (e.g., 'en-US')
	const bcp47Locale = typeof safeLocale === 'string' ? safeLocale.replace('_', '-') : 'en-US';
	
	// English format: "January 2026" or locale-appropriate format
	return new Intl.DateTimeFormat(bcp47Locale, {
		year: 'numeric',
		month: 'long',
	}).format(date);
};

const buildCalendarMatrix = (year, month) => {
	const firstDay = new Date(year, month - 1, 1);
	const weeks = [];
	const startOffset = firstDay.getDay();
	let cursor = new Date(year, month - 1, 1 - startOffset);

	for (let weekIndex = 0; weekIndex < 6; weekIndex += 1) {
		const week = [];
		for (let dayIndex = 0; dayIndex < 7; dayIndex += 1) {
			const cellDate = new Date(cursor);
			week.push({
				label: cellDate.getDate(),
				date: cellDate,
				iso: toISODate(cellDate),
				inMonth: cellDate.getMonth() === month - 1,
				weekday: dayIndex,
			});
			cursor.setDate(cursor.getDate() + 1);
		}
		weeks.push(week);
	}

	return weeks;
};

const todayIso = toISODate(new Date());

export const CalendarGrid = ({
	year,
	month,
	dayMetaMap = {},
	selectedDate,
	onSelectDate,
	onMonthChange,
	isLoading,
	locale,
}) => {
	const renderStatusLabel = (status) => {
		if (!status || status === 'normal') {
			return '';
		}

		switch (status) {
			case 'holiday':
				return __('Closed days', 'vk-booking-manager');
			case 'special_open':
				return __('Special Opening', 'vk-booking-manager');
			case 'special_close':
				return __('Temporary closure', 'vk-booking-manager');
			case 'off':
				return __('private', 'vk-booking-manager');
			default:
				return status;
		}
	};

	const weeks = useMemo(() => buildCalendarMatrix(year, month), [year, month]);

	return (
		<div className="vkbm-calendar">
			<header className="vkbm-calendar__header">
				<button
					type="button"
					className="vkbm-calendar__nav"
					onClick={() => onMonthChange(-1)}
					aria-label={__('previous month', 'vk-booking-manager')}
				>
					‹
				</button>
				<div className="vkbm-calendar__current">
					{formatMonthLabel(year, month, locale)}
					{isLoading && (
						<span className="vkbm-calendar__spinner" aria-live="polite">
							{__('Loading...', 'vk-booking-manager')}
						</span>
					)}
				</div>
				<button
					type="button"
					className="vkbm-calendar__nav"
					onClick={() => onMonthChange(1)}
					aria-label={__('next month', 'vk-booking-manager')}
				>
					›
				</button>
			</header>

			<div className="vkbm-calendar__weekdays">
				{WEEKDAYS.map((weekday) => (
					<span key={weekday}>{weekday}</span>
				))}
			</div>

			<div className="vkbm-calendar__grid">
				{weeks.map((week, weekIndex) => (
					<div className="vkbm-calendar__week" key={`week-${weekIndex}`}>
						{week.map((day) => {
							const meta = dayMetaMap[day.iso] || {};
							const isSelected = selectedDate === day.iso;
							const disabled =
								!day.inMonth ||
								Boolean(meta.is_disabled) ||
								day.iso < todayIso;

							return (
								<button
									type="button"
									key={day.iso}
									className={[
										'vkbm-calendar__day',
										!day.inMonth && 'vkbm-calendar__day--muted',
										isSelected && 'vkbm-calendar__day--selected',
										meta.available_slots > 0 &&
											'vkbm-calendar__day--available',
									]
										.filter(Boolean)
										.join(' ')}
									onClick={() => onSelectDate(day.iso)}
									disabled={disabled}
								>
									<span className="vkbm-calendar__day-label">
										{day.label}
									</span>
									{renderStatusLabel(meta.shift_status) && (
										<span className="vkbm-calendar__status">
											{renderStatusLabel(meta.shift_status)}
										</span>
									)}
								</button>
							);
						})}
					</div>
				))}
			</div>
		</div>
	);
};
