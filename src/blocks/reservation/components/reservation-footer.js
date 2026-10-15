import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { PolicyModal } from './policy-modal';
import { formatFooterPhone } from './footer-model';

/**
 * 予約ブロック末尾のフッター（事業者情報と、規約類へのリンク）。
 *
 * @param {Object}  props       プロパティ。
 * @param {?Object} props.model buildFooterModel() の戻り値。null なら何も出さない。
 * @return {?Object} フッターの React 要素。
 */
export const ReservationFooter = ( { model } ) => {
	const [ openKey, setOpenKey ] = useState( '' );

	if ( ! model ) {
		return null;
	}

	const openLink = model.links.find( ( link ) => link.key === openKey );
	const hasInfo = Boolean(
		model.providerName ||
			model.address ||
			model.phone ||
			model.businessHours
	);

	const hasContact = Boolean( model.address || model.phone );
	const hasDetails = hasContact || Boolean( model.businessHours );

	return (
		<footer className="vkbm-reservation-footer">
			{ hasInfo && (
				<div className="vkbm-reservation-footer__info">
					{ model.providerName && (
						<p className="vkbm-reservation-footer__name">
							{ model.providerName }
						</p>
					) }
					{ hasDetails && (
						<div
							className={ `vkbm-reservation-footer__details${
								hasContact && model.businessHours
									? ' vkbm-reservation-footer__details--two-columns'
									: ''
							}` }
						>
							{ hasContact && (
								<div className="vkbm-reservation-footer__contact">
									{ model.address && (
										<p className="vkbm-reservation-footer__address">
											{ model.address }
										</p>
									) }
									{ model.phone && (
										<p className="vkbm-reservation-footer__phone">
											{ formatFooterPhone( model.phone ) }
										</p>
									) }
								</div>
							) }
							{ model.businessHours && (
								<div className="vkbm-reservation-footer__hours-column">
									<p className="vkbm-reservation-footer__hours">
										{ model.businessHours }
									</p>
								</div>
							) }
						</div>
					) }
				</div>
			) }
			{ model.links.length > 0 && (
				<ul className="vkbm-reservation-footer__links">
					{ model.links.map( ( link ) => (
						<li
							className="vkbm-reservation-footer__links-item"
							key={ link.key }
						>
							{ link.url ? (
								<a
									className="vkbm-button vkbm-button__sm vkbm-button__link vkbm-reservation-footer__link"
									href={ link.url }
									target="_blank"
									rel="noopener noreferrer"
									aria-label={ sprintf(
										/* translators: %s: link text (e.g. Privacy policy) */
										__(
											'%s (opens in a new tab)',
											'vk-booking-manager'
										),
										link.label
									) }
								>
									<span className="vkbm-reservation-footer__link-text">
										{ link.label }
									</span>
								</a>
							) : (
								<button
									type="button"
									className="vkbm-button vkbm-button__sm vkbm-button__link vkbm-reservation-footer__link"
									aria-haspopup="dialog"
									onClick={ () => setOpenKey( link.key ) }
								>
									<span className="vkbm-reservation-footer__link-text">
										{ link.label }
									</span>
								</button>
							) }
						</li>
					) ) }
				</ul>
			) }
			{ openLink && (
				<PolicyModal
					id={ openLink.key }
					title={ openLink.label }
					body={ openLink.body }
					onClose={ () => setOpenKey( '' ) }
				/>
			) }
		</footer>
	);
};
