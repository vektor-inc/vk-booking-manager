( function ( $ ) {
	'use strict';

	if ( typeof inlineEditPost === 'undefined' ) {
		return;
	}

	const wpInlineEdit = inlineEditPost.edit;

	// #477 安藤さん・植草さんレビュー対応: クイック編集の保存は画面遷移せず
	// display_rows() を返して閉じるだけのため、redirect_post_location / admin_notices を
	// 前提にした既存の通知の仕組み（PHP 側の push_admin_notice 等）はここでは一度も
	// 利用者に表示されない。担当スタッフ未割当のまま枠を消費するステータス（vkbmBookingQuickEdit.
	// targetStatuses）へ変更しようとする操作を、保存が黙って中断される前（プルダウンの
	// 選択肢そのものを選べなくする）ことで防ぐ。サーバー側の保存中断ガード（save_quick_edit()）は
	// 多層防御としてそのまま残っており、この JS が読み込まれない・無効化された環境でも
	// 保存自体は中断される。
	const settings = window.vkbmBookingQuickEdit || {};
	const targetStatuses = Array.isArray( settings.targetStatuses )
		? settings.targetStatuses
		: [];
	const NOTICE_CLASS = 'vkbm-qe-resource-required-notice';
	// #477 植草さんレビュー対応（FAIL）: どの選択肢を disabled にすべきかの判定は、
	// booking-quick-edit-status-guard.js（Jest でテストされる純粋関数）に切り出してある。
	const statusGuard = window.vkbmBookingQuickEditStatusGuard || null;

	inlineEditPost.edit = function ( id ) {
		wpInlineEdit.apply( this, arguments );

		let postId = 0;
		if ( typeof id === 'object' ) {
			postId = parseInt( this.getId( id ), 10 );
		} else {
			postId = parseInt( id, 10 );
		}

		if ( ! postId ) {
			return;
		}

		const $row = $( '#post-' + postId );
		const $editRow = $( '#edit-' + postId );
		const $dataEl = $row.find( '.vkbm-booking-qe' ).first();

		if ( ! $dataEl.length || ! $editRow.length ) {
			return;
		}

		const data = $dataEl.data();
		const status = data.status !== undefined ? data.status : '';
		const resourceId =
			data.resourceId !== undefined ? parseInt( data.resourceId, 10 ) : 0;
		const editUrl =
			data.editUrl !== undefined ? String( data.editUrl ) : '';

		const $select = $editRow.find( 'select.vkbm-qe-booking-status' );
		$select.val( status );

		// #477 安藤さんレビュー対応（LOW・6件目）: このコメントはかつて「WordPress コアが
		// 1つの隠しテンプレート行を使い回す」という誤った前提を書いていたが、実際には
		// wp-admin/js/inline-edit-post.js の inlineEditPost.edit() が #inline-edit を
		// clone( true ) して #edit-{id} を作り、revert() で毎回破棄する（コア自身のコメントにも
		// "While Quick Edit clones the form each time, Bulk Edit always re-uses…" とある）。
		// クローン元（#inline-edit）には触れていないため、本来は前回の disabled・通知が
		// 残らないはずだが、将来コアの実装が変わっても壊れないよう、クローン元に触れていない
		// ことに依存せず、開くたびに明示的にリセットする多層防御として残す。
		$select.find( 'option' ).prop( 'disabled', false );
		$editRow.find( '.' + NOTICE_CLASS ).remove();
		$select.removeAttr( 'aria-describedby' );

		if ( resourceId > 0 || ! targetStatuses.length || ! statusGuard ) {
			return;
		}

		// 担当スタッフ未割当のため、枠を消費するステータス（現在選択中の値を除く）は
		// 選択肢の時点で選べないようにする。除外の理由・経緯は
		// booking-quick-edit-status-guard.js のコメントを参照。
		$select.find( 'option' ).each( function () {
			if (
				statusGuard.shouldDisableStatusOption(
					this.value,
					status,
					targetStatuses
				)
			) {
				$( this ).prop( 'disabled', true );
			}
		} );

		if ( ! settings.noticeMessage ) {
			return;
		}

		const noticeId = 'vkbm-qe-resource-required-notice-' + postId;
		const $notice = $( '<p></p>' )
			.attr( 'id', noticeId )
			.attr( 'role', 'status' )
			.addClass(
				NOTICE_CLASS +
					' vkbm-alert vkbm-alert__warning vkbm-alert--compact'
			)
			.text( settings.noticeMessage );

		if ( editUrl && settings.noticeActionLabel ) {
			$notice.append( document.createTextNode( ' ' ) );
			$( '<a></a>' )
				.attr( 'href', editUrl )
				.text( settings.noticeActionLabel )
				.appendTo( $notice );
		}

		// #477 安藤さんレビュー対応（LOW・4件目/5件目）: 以前は <span class="input-text-wrap">
		// （フレージング要素）の中へ <p>（フロー要素）を差し込んでおり、<label> の内側に <a> が
		// 置かれる不正なマークアップになっていた。挿入先を <div class="inline-edit-group"> に
		// 変えることで、<div> 直下の兄弟要素としてネストが正しくなり、<a> も <label> の外に出る。
		// また .closest() が空集合（マークアップの前提が崩れた場合）でも .append() は無言で
		// 何もしないため、その場合に aria-describedby だけが存在しない ID を指してしまわないよう
		// 挿入先を変数に取って length を確認してから append と aria-describedby の両方を行う。
		const $noticeTarget = $select.closest( '.inline-edit-group' );
		if ( $noticeTarget.length ) {
			$noticeTarget.append( $notice );
			$select.attr( 'aria-describedby', noticeId );
		}
	};
} )( jQuery );
