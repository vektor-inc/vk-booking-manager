import { __ } from '@wordpress/i18n';
import {
	ReservationHeaderNav,
	buildReservationHeaderNavItems,
} from './reservation-header-nav';

/**
 * ロゴ画像の代替テキストを、ブランド名とリンク先から決める。
 *
 * @param {string} providerName  事業者名。
 * @param {string} brandLinkHref 予約ページへのリンク先。
 * @return {string} ロゴ画像の代替テキスト。
 */
export const getBrandLogoAlt = ( providerName, brandLinkHref ) => {
	// 表示設定で事業者名を隠していても、ロゴリンクの名前には利用する。
	if ( typeof providerName === 'string' && providerName.trim() !== '' ) {
		return providerName;
	}

	// リンク先がない場合は画像そのものを表し、リンクなら行き先を表す。
	return brandLinkHref
		? __( 'Go to reservation page', 'vk-booking-manager' )
		: __( 'Logo image', 'vk-booking-manager' );
};

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

	// ナビの項目もロゴ・事業者名も無いときは、空のヘッダーで余計な隙間を作らないよう何も出さない（#1121）。
	const hasNav =
		Boolean( nav ) && buildReservationHeaderNavItems( nav ).length > 0;
	if ( ! brand && ! hasNav ) {
		return null;
	}

	const navNode = hasNav ? <ReservationHeaderNav { ...nav } /> : null;

	return (
		<div className="vkbm-reservation-header">
			{ brand }
			{ navNode }
		</div>
	);
};
