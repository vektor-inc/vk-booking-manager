import { __ } from '@wordpress/i18n';

export const ReservationHeaderNav = ( {
	variant = '',
	screen = '',
	ariaLabel = '',
	staffDashboardHref = '',
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
} ) => {
	let navItems = [];
	if ( variant === 'staff' ) {
		// 店舗スタッフ用ナビゲーション
		navItems = [
			{
				key: 'dashboard',
				type: 'link',
				label: __( 'Shift/reservation table', 'vk-booking-manager' ),
				href: staffDashboardHref,
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
					>
						{ item.label }
					</button>
				) : (
					<a
						key={ item.key || `${ item.type }-${ index }` }
						className={ itemClassName }
						href={ item.href }
					>
						{ item.label }
					</a>
				);
			} ) }
		</div>
	);
};
