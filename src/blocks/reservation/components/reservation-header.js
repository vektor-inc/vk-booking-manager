import { ReservationHeaderNav } from './reservation-header-nav';

export const ReservationHeader = ({
	showBrand = false,
	brandLinkHref = '',
	showLogo = false,
	logoUrl = '',
	logoAlt = '',
	showName = false,
	brandName = '',
	nav = null,
}) => {
	const brand = showBrand ? (
		brandLinkHref ? (
			<a
				className="vkbm-reservation-header__brand vkbm-reservation-header__brand-link"
				href={brandLinkHref}
			>
				{showLogo && (
					<img
						className="vkbm-reservation-header__brand-logo"
						src={logoUrl}
						alt={logoAlt}
						loading="lazy"
					/>
				)}
				{showName && (
					<span className="vkbm-reservation-header__brand-name">
						{brandName}
					</span>
				)}
			</a>
		) : (
			<div className="vkbm-reservation-header__brand">
				{showLogo && (
					<img
						className="vkbm-reservation-header__brand-logo"
						src={logoUrl}
						alt={logoAlt}
						loading="lazy"
					/>
				)}
				{showName && (
					<span className="vkbm-reservation-header__brand-name">
						{brandName}
					</span>
				)}
			</div>
		)
	) : null;

	const navNode = nav ? <ReservationHeaderNav {...nav} /> : null;

	return (
		<div className="vkbm-reservation-header">
			{brand}
			{navNode}
		</div>
	);
};
