/**
 * 予約一覧の一括操作「個人情報を匿名化」の確認ダイアログ（issue #870）。
 *
 * 一括操作で「個人情報を匿名化」を選んで「適用」を押したとき、実行前に確認ダイアログを出す。
 * ダイアログの部品は、スタッフ削除の確認と同じ共通部品 admin-guard-dialog.js（window.vkbmGuardDialog）を使う。
 * このダイアログは確認のためだけのもので、JavaScript が動かない環境では確認なしで実行される。
 * 対象の判定・権限の確認はサーバー側（Booking_Anonymize_Bulk_Action）が行う。
 */
( function () {
	'use strict';

	const config = window.vkbmBookingAnonymize || {};
	const i18n = config.i18n || {};
	const dialogKit = window.vkbmGuardDialog;

	/**
	 * 上下どちらの一括操作プルダウンで選ばれているかを返す。
	 *
	 * WordPress 本体の一覧と同じく、上のプルダウンが選ばれていればそれ、
	 * 未選択なら下のプルダウンの値を実行する操作とみなす。
	 *
	 * @return {string} 実行される一括操作の値。未選択なら '-1'。
	 */
	function getSelectedBulkAction() {
		const top = document.getElementById( 'bulk-action-selector-top' );
		const bottom = document.getElementById( 'bulk-action-selector-bottom' );

		if ( top && top.value !== '-1' ) {
			return top.value;
		}

		return bottom ? bottom.value : '-1';
	}

	/**
	 * 確認ダイアログを開く。
	 *
	 * @param {Element}  triggerEl 起点要素（押された「適用」ボタン）。
	 * @param {number}   count     選択された予約の件数。
	 * @param {Function} onProceed 「匿名化する」が押されたときに呼ぶ。
	 */
	function showConfirmDialog( triggerEl, count, onProceed ) {
		dialogKit.openAlertDialog( {
			title: ( i18n.title || '' ).replace( '%d', String( count ) ),
			bodyElements: [
				dialogKit.paragraph( i18n.bodyFields || '' ),
				dialogKit.paragraph( i18n.bodyAttachment || '' ),
				dialogKit.paragraph( i18n.bodyMyPage || '' ),
				dialogKit.paragraph( i18n.bodyKept || '' ),
				dialogKit.paragraph( i18n.bodyFlags || '' ),
				dialogKit.paragraph( i18n.bodyNotEnded || '' ),
				dialogKit.iconParagraph(
					i18n.bodyIrrevers || '',
					'dashicons-warning'
				),
			],
			triggerEl,
			actions: [
				{
					label: i18n.cancel || '',
					isPrimary: false,
					onClick: null,
				},
				{
					label: i18n.confirmButton || '',
					isPrimary: true,
					isDestructive: true,
					onClick: onProceed,
				},
			],
		} );
	}

	/**
	 * 一覧のフォーム送信に、確認ダイアログを差し込む。
	 */
	function init() {
		const form = document.getElementById( 'posts-filter' );
		if ( ! form || ! dialogKit ) {
			return;
		}

		let bypassNextSubmit = false;

		form.addEventListener( 'submit', function ( event ) {
			// 確認ダイアログで「匿名化する」が押されたあとの再送信は、そのまま通す。
			if ( bypassNextSubmit ) {
				bypassNextSubmit = false;
				return;
			}

			if ( getSelectedBulkAction() !== config.action ) {
				return;
			}

			const count = form.querySelectorAll(
				'input[name="post[]"]:checked'
			).length;
			// 何も選ばれていないときは、WordPress 本体の挙動（何もしない）に任せる。
			if ( ! count ) {
				return;
			}

			event.preventDefault();

			// 上下どちらの「適用」ボタンが押されたかを保ったまま再送信する。
			const submitter = event.submitter;
			showConfirmDialog( submitter || form, count, function () {
				bypassNextSubmit = true;
				if ( form.requestSubmit ) {
					form.requestSubmit( submitter || undefined );
				} else {
					form.submit();
				}
			} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
