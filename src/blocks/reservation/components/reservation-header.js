import { ReservationHeaderNav } from './reservation-header-nav';

export const ReservationHeader = ( {
	showBrand = false,
	brandLinkHref = '',
	showLogo = false,
	logoUrl = '',
	logoWidth = 0,
	logoAlt = '',
	showName = false,
	brandName = '',
	nav = null,
} ) => {
	// 横幅の指定があるときだけ、既定の高さ上限（max-height）を外して横幅を優先する。縦は比率維持。
	const logoStyle =
		logoWidth > 0
			? {
					width: `${ logoWidth }px`,
					maxWidth: '100%',
					maxHeight: 'none',
					height: 'auto',
			  }
			: undefined;

	const brand = showBrand ? (
		brandLinkHref ? (
			<a
				className="vkbm-reservation-header__brand vkbm-reservation-header__brand-link"
				href={ brandLinkHref }
			>
				{ showLogo && (
					<img
						className="vkbm-reservation-header__brand-logo"
						src={ logoUrl }
						style={ logoStyle }
						alt={ logoAlt }
						loading="lazy"
					/>
				) }
				{ showName && (
					<span className="vkbm-reservation-header__brand-name">
						{ brandName }
					</span>
				) }
			</a>
		) : (
			<div className="vkbm-reservation-header__brand">
				{ showLogo && (
					<img
						className="vkbm-reservation-header__brand-logo"
						src={ logoUrl }
						style={ logoStyle }
						alt={ logoAlt }
						loading="lazy"
					/>
				) }
				{ showName && (
					<span className="vkbm-reservation-header__brand-name">
						{ brandName }
					</span>
				) }
			</div>
		)
	) : null;

	const navNode = nav ? <ReservationHeaderNav { ...nav } /> : null;

	return (
		<div className="vkbm-reservation-header">
			{ brand }
			{ navNode }
		</div>
	);
};
