/*
 * ブロックエディタで、保存時のお知らせ（人数の丸め・料金区分なしの警告・予約可能日のお知らせ）を
 * 標準の通知（core/notices）で表示する。
 *
 * お知らせは PHP が一時保存し、REST で取得すると同時に消費される。
 * 画面読み込み時と、メタボックスの保存が終わったときに取得する。
 */
( function ( wp, config ) {
	'use strict';

	if ( ! wp || ! wp.data || ! wp.apiFetch || ! config || ! config.postId ) {
		return;
	}

	const data = wp.data;

	function fetchNotice() {
		wp.apiFetch( {
			path: '/vkbm/v1/service-menu-notice?post_id=' + config.postId,
		} )
			.then( function ( response ) {
				if ( response && response.message ) {
					data.dispatch( 'core/notices' ).createNotice(
						'warning',
						response.message,
						{ id: 'vkbm-service-menu-notice', isDismissible: true }
					);
				}
			} )
			.catch( function () {} );
	}

	fetchNotice();

	let wasSaving = false;
	data.subscribe( function () {
		const editPost = data.select( 'core/edit-post' );
		if ( ! editPost || ! editPost.isSavingMetaBoxes ) {
			return;
		}
		const isSaving = editPost.isSavingMetaBoxes();
		if ( wasSaving && ! isSaving ) {
			fetchNotice();
		}
		wasSaving = isSaving;
	} );
} )( window.wp, window.vkbmServiceMenuNotices );
