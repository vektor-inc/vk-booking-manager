/** 予約の個人情報の自動匿名化設定を保存する前の確認。 */
( function () {
	'use strict';
	const config = window.vkbmBookingAutoAnonymize || {};
	const kit = window.vkbmGuardDialog;
	const select = document.getElementById(
		'vkbm-booking-auto-anonymize-months'
	);
	if ( ! select || ! select.form || ! kit ) {
		return;
	}
	let bypass = false;
	select.form.addEventListener( 'submit', function ( event ) {
		if ( bypass ) {
			return;
		}
		const before = Number( select.dataset.initialValue || 0 );
		const after = Number( select.value || 0 );
		if ( after <= 0 || ( before > 0 && after >= before ) ) {
			return;
		}
		event.preventDefault();
		const period = select.selectedOptions[ 0 ]?.dataset.period || '';
		const submitter = event.submitter;
		kit.openAlertDialog( {
			title: before === 0 ? config.titleEnable : config.titleShorten,
			bodyElements: [
				kit.paragraph(
					( config.bodyPeriod || '' ).replace( '%s', period )
				),
				kit.iconParagraph(
					config.bodyFields || '',
					'dashicons-warning'
				),
			],
			triggerEl: event.submitter || select,
			actions: [
				{ label: config.cancel || '', isPrimary: false, onClick: null },
				{
					label: config.confirm || '',
					isPrimary: true,
					isDestructive: true,
					onClick() {
						bypass = true;
						if ( select.form.requestSubmit ) {
							select.form.requestSubmit( submitter || undefined );
						} else {
							select.form.submit();
						}
					},
				},
			],
		} );
	} );
} )();
