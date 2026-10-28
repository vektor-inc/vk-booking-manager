import { __ } from '@wordpress/i18n';

export const ReservationHeaderNav = ( {
	variant = '',
	screen = '',
	ariaLabel = '',
	staffDashboardHref = '',
	staffSettingsHref = '',
	logoutHref = '',
	onReturn = null,
	returnLabel = '',
	onBookings = null,
	isBookingsActive = false,
	onProfile = null,
	isProfileActive = false,
	onLogin = null,
	isLoginActive = false,
	onRegister = null,
	isRegisterActive = false,
	listReturnHref = '',
	listReturnLabel = '',
	isLodging = false,
} ) => {
	let navItems = [];
	if ( variant === 'staff' ) {
		// 店舗スタッフ用ナビゲーション
		navItems = [
			{
				key: 'dashboard',
				type: 'link',
				label: isLodging
					? __(
							'Days on sale/reservation table',
							'vk-booking-manager'
					  )
					: __( 'Shift/reservation table', 'vk-booking-manager' ),
				href: staffDashboardHref,
			},
			{
				key: 'settings',
				type: 'link',
				label: __( 'BM settings', 'vk-booking-manager' ),
				href: staffSettingsHref,
			},
			{
				key: 'logout',
				type: 'link',
				label: __( 'Log out', 'vk-booking-manager' ),
				href: logoutHref,
			},
		];
	} else if ( variant === 'member' ) {
		// 会員用ナビゲーション
		navItems = [
			{
				key: 'bookings',
				type: 'button',
				label: __( 'Confirm reservation', 'vk-booking-manager' ),
				onClick: onBookings,
				ariaPressed: isBookingsActive,
				isActive: isBookingsActive,
			},
			{
				key: 'profile',
				type: 'button',
				label: __( 'Edit user information', 'vk-booking-manager' ),
				onClick: onProfile,
				ariaPressed: isProfileActive,
				isActive: isProfileActive,
			},
			{
				key: 'logout',
				type: 'link',
				label: __( 'Log out', 'vk-booking-manager' ),
				href: logoutHref,
			},
		];
	} else if ( variant === 'return' ) {
		// 戻る用ナビゲーション
		navItems = [
			{
				key: 'return',
				type: 'button',
				label: returnLabel || __( 'Return', 'vk-booking-manager' ),
				onClick: onReturn,
			},
		];
	} else if ( variant === 'guest' ) {
		// ゲスト用ナビゲーション
		navItems = [
			{
				key: 'login',
				type: 'button',
				label: __( 'Log in', 'vk-booking-manager' ),
				onClick: onLogin,
				ariaPressed: isLoginActive,
				isActive: isLoginActive,
			},
			{
				key: 'register',
				type: 'button',
				label: __( 'Sign up', 'vk-booking-manager' ),
				onClick: onRegister,
				ariaPressed: isRegisterActive,
				isActive: isRegisterActive,
			},
		];
	}

	// #635: サービスメニューの詳細表示中は、先頭に「一覧に戻る」を足す（既存のナビはそのまま残す）。
	if ( listReturnHref && variant !== 'return' ) {
		navItems = [
			{
				key: 'list-return',
				type: 'link',
				label:
					listReturnLabel ||
					__( 'Back to the list', 'vk-booking-manager' ),
				href: listReturnHref,
			},
			...navItems,
		];
	}

	const resolvedItems = navItems.filter(
		( item ) =>
			item.type !== 'link' ||
			( typeof item.href === 'string' && item.href.trim() !== '' )
	);

	if ( ! resolvedItems.length ) {
		return null;
	}

	return (
		<div
			className="vkbm-reservation-header__nav"
			role="navigation"
			aria-label={ ariaLabel }
			data-vkbm-nav-screen={ screen }
			data-vkbm-nav-variant={ variant }
			data-vkbm-nav-state={ `${ screen }:${ variant }` }
		>
			{ resolvedItems.map( ( item, index ) => {
				const itemClassName = [
					'vkbm-user-actions__link',
					'vkbm-button',
					'vkbm-button__sm',
					'vkbm-button__link',
					'vkbm-reservation-header__nav-link',
					item.className,
					item.isActive && 'is-active',
				]
					.filter( Boolean )
					.join( ' ' );

				return item.type === 'button' ? (
					<button
						key={ item.key || `${ item.type }-${ index }` }
						type="button"
						className={ itemClassName }
						onClick={ item.onClick }
						aria-pressed={ item.ariaPressed }
						aria-current={ item.isActive ? 'page' : undefined }
					>
						{ item.label }
					</button>
				) : (
					<a
						key={ item.key || `${ item.type }-${ index }` }
						className={ itemClassName }
						href={ item.href }
						aria-current={ item.isActive ? 'page' : undefined }
					>
						{ item.label }
					</a>
				);
			} ) }
		</div>
	);
};
