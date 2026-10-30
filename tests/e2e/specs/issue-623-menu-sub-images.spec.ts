/**
 * issue #623: カード一覧にサブ画像を掲載できるようにする。
 *
 * 検証するシナリオ:
 *   1. 編集画面: サブ画像欄の並び替え（前へ／後ろへ移動ボタン）・削除・「すべて削除」と元に戻す・保存。
 *   2. カード: サブ画像がカードにすべて出て、クリックで拡大表示（dialog）が開き、
 *      前後送り・矢印キー・Esc・背景クリックで操作でき、閉じるとサムネイルへフォーカスが戻る。
 *
 * 注: メディアライブラリのモーダル操作は不安定なため、添付は WP-CLI（PHP）で作って
 * メタに直接登録して準備する。語句は言語設定で変わるため、クラス名・属性でセレクトする。
 */
import { test, expect } from '@playwright/test';
import { wpEvalPhp, loginAsAdmin } from '../utils/helpers';

/**
 * 検証用のサービスメニュー（サブ画像3枚つき）を作り直す。
 * 画像は色の違う 1x1 PNG で、ファイル名から並び順を判別できるようにする。
 *
 * @return メニューの投稿IDと、サブ画像の添付ID（登録順）
 */
function createMenuWithSubImages(): { menuId: number; imageIds: number[] } {
	const phpCode = `
		$existing = get_posts( array(
			'post_type'   => 'vkbm_service_menu',
			'post_status' => 'any',
			'title'       => 'Sub Image E2E Menu',
			'fields'      => 'ids',
			'numberposts' => -1,
		) );
		foreach ( $existing as $eid ) {
			wp_delete_post( (int) $eid, true );
		}

		$menu_id = wp_insert_post( array(
			'post_type'    => 'vkbm_service_menu',
			'post_status'  => 'publish',
			'post_title'   => 'Sub Image E2E Menu',
			'post_content' => '<!-- wp:vk-booking-manager/menu-card {"selectedMenuId":0} /-->',
		) );
		update_post_meta( $menu_id, '_vkbm_base_price', 5000 );
		update_post_meta( $menu_id, '_vkbm_duration_minutes', 60 );

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$pngs = array(
			'sub-e2e-1' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
			'sub-e2e-2' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
			'sub-e2e-3' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
		);
		$ids = array();
		foreach ( $pngs as $name => $b64 ) {
			$upload = wp_upload_bits( $name . '.png', null, base64_decode( $b64 ) );
			$attach_id = wp_insert_attachment( array(
				'post_mime_type' => 'image/png',
				'post_title'     => $name,
				'post_excerpt'   => $name === 'sub-e2e-2' ? 'キャプション2' : '',
				'post_status'    => 'inherit',
			), $upload['file'], $menu_id );
			wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, $upload['file'] ) );
			$ids[] = (int) $attach_id;
		}
		update_post_meta( $menu_id, '_vkbm_gallery_ids', $ids );

		echo wp_json_encode( array( 'menuId' => (int) $menu_id, 'imageIds' => $ids ) );
	`;
	const result = JSON.parse( wpEvalPhp( phpCode ).trim() );
	if ( ! result.menuId || result.imageIds.length !== 3 ) {
		throw new Error( 'Failed to create the sub image test menu.' );
	}
	return result;
}

/**
 * メニューに保存されているサブ画像の添付IDを取得する。
 *
 * @param menuId メニューの投稿ID
 * @return 保存されている添付ID（並び順どおり）
 */
function getStoredGalleryIds( menuId: number ): number[] {
	const phpCode = `
		$ids = get_post_meta( ${ Number( menuId ) }, '_vkbm_gallery_ids', true );
		echo wp_json_encode( is_array( $ids ) ? array_values( $ids ) : array() );
	`;
	return JSON.parse( wpEvalPhp( phpCode ).trim() );
}

/**
 * ブロックエディターの「ようこそ」案内を出さず、メタボックス欄を開いた状態にする。
 * 案内やメタボックス欄の折りたたみが、サブ画像欄の操作を妨げるため。
 */
function prepareBlockEditorPreferences(): void {
	const phpCode = `
		global $wpdb;
		$user = get_user_by( 'login', 'admin' );
		update_user_meta(
			$user->ID,
			$wpdb->get_blog_prefix() . 'persisted_preferences',
			array(
				'core/edit-post' => array(
					'welcomeGuide'        => false,
					'metaBoxesMainIsOpen' => true,
				),
				'core'           => array(
					'enableChoosePatternModal' => false,
				),
			)
		);
		echo 'OK';
	`;
	expect( wpEvalPhp( phpCode ) ).toContain( 'OK' );
}

test.describe( 'issue #623: サブ画像', () => {
	let menuId: number;
	let imageIds: number[];

	test.beforeEach( () => {
		// @step 管理画面でサブ画像を3枚（2枚目はキャプション付き）登録したサービスメニューを作っておく
		( { menuId, imageIds } = createMenuWithSubImages() );
	} );

	test( '編集画面: 並び替え・削除・すべて削除と元に戻す・保存ができる', async ( {
		page,
	} ) => {
		// @step 管理者でログインし、そのメニューの編集画面を開く
		prepareBlockEditorPreferences();
		await loginAsAdmin( page );
		await page.goto( `/wp-admin/post.php?post=${ menuId }&action=edit` );

		const gallery = page.locator( '[data-vkbm-gallery]' );
		const items = gallery.locator( '.vkbm-gallery__item' );
		const hidden = gallery.locator( '.vkbm-gallery__input' );
		// @check サブ画像欄に画像が3枚、登録順に並んでいる
		await expect( items ).toHaveCount( 3 );
		await expect( hidden ).toHaveValue( imageIds.join( ',' ) );

		// @check 先頭の画像の「前へ移動」と、末尾の画像の「後ろへ移動」は押せない
		// 先頭の画像は「前へ移動」が押せず、末尾の画像は「後ろへ移動」が押せない。
		await expect(
			items.nth( 0 ).locator( '.vkbm-gallery__move-earlier' )
		).toBeDisabled();
		await expect(
			items.nth( 2 ).locator( '.vkbm-gallery__move-later' )
		).toBeDisabled();

		// @step 先頭の画像の「後ろへ移動」を押す
		// 先頭の画像を「後ろへ移動」すると2番目になり、フォーカスは移動先の同じボタンに残る。
		await items.nth( 0 ).locator( '.vkbm-gallery__move-later' ).click();
		// @check 画像が2番目に移り、フォーカスは移動先の「後ろへ移動」ボタンに残る
		await expect( hidden ).toHaveValue(
			[ imageIds[ 1 ], imageIds[ 0 ], imageIds[ 2 ] ].join( ',' )
		);
		await expect(
			items.nth( 1 ).locator( '.vkbm-gallery__move-later' )
		).toBeFocused();

		// @check 件数の表示に 3 が出ている
		// 件数表示が出ている。
		await expect( gallery.locator( '.vkbm-gallery__count' ) ).toContainText(
			'3'
		);

		// @step 先頭の画像の×を押す
		// 先頭の画像（元の2枚目）を×で外す。
		await items.nth( 0 ).locator( '.vkbm-gallery__remove' ).click();
		// @check 画像が2枚になる
		await expect( items ).toHaveCount( 2 );
		await expect( hidden ).toHaveValue(
			[ imageIds[ 0 ], imageIds[ 2 ] ].join( ',' )
		);

		// @step 「すべて削除」を押す
		// 「すべて削除」は確認ダイアログなしで外れ、「元に戻す」で戻せる。
		page.on( 'dialog', () => {
			throw new Error( 'confirm ダイアログが表示された' );
		} );
		await gallery.locator( '.vkbm-gallery__clear' ).click();
		// @check 確認ダイアログは出ずに、画像がすべて外れる
		await expect( items ).toHaveCount( 0 );
		await expect( hidden ).toHaveValue( '' );
		// @step 「元に戻す」を押す
		await gallery.locator( '.vkbm-gallery__undo' ).click();
		// @check 外した2枚の画像が戻る
		await expect( items ).toHaveCount( 2 );
		await expect( hidden ).toHaveValue(
			[ imageIds[ 0 ], imageIds[ 2 ] ].join( ',' )
		);

		// @step 「更新」ボタンを押して保存する
		// 保存すると、並び順どおりに保存される。
		// （ブロックエディターの保存ボタン。メタボックスの内容もあわせて保存される）
		await page.locator( '.editor-post-publish-button' ).click();
		// @check 画面の並び順どおりに2枚の画像が保存される
		await expect
			.poll( () => getStoredGalleryIds( menuId ), { timeout: 20000 } )
			.toEqual( [ imageIds[ 0 ], imageIds[ 2 ] ] );
	} );

	test( '編集画面: 追加→×で外す→もう一度追加しても、外した画像が戻らず重複の通知も出ない', async ( {
		page,
	} ) => {
		// @step メディアライブラリに、まだメニューに登録していない画像を2枚追加しておく
		// メディア選択画面から選べる、まだ登録していない画像を2枚用意する。
		const extra = JSON.parse(
			wpEvalPhp( `
				require_once ABSPATH . 'wp-admin/includes/image.php';
				$b64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
				$ids = array();
				foreach ( array( 'sub-e2e-extra-1', 'sub-e2e-extra-2' ) as $name ) {
					$upload = wp_upload_bits( $name . '.png', null, base64_decode( $b64 ) );
					$attach_id = wp_insert_attachment( array(
						'post_mime_type' => 'image/png',
						'post_title'     => $name,
						'post_status'    => 'inherit',
					), $upload['file'] );
					wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, $upload['file'] ) );
					$ids[] = (int) $attach_id;
				}
				echo wp_json_encode( $ids );
			` ).trim()
		) as number[];
		expect( extra ).toHaveLength( 2 );

		// @step 管理者でログインし、メニューの編集画面を開く
		prepareBlockEditorPreferences();
		await loginAsAdmin( page );
		await page.goto( `/wp-admin/post.php?post=${ menuId }&action=edit` );

		const gallery = page.locator( '[data-vkbm-gallery]' );
		const items = gallery.locator( '.vkbm-gallery__item' );
		const hidden = gallery.locator( '.vkbm-gallery__input' );
		const status = gallery.locator( '.vkbm-gallery__status' );
		// @check サブ画像欄に画像が3枚ある
		await expect( items ).toHaveCount( 3 );

		const modal = page.locator( '.media-modal' );
		const openLibrary = async () => {
			await gallery.locator( '.vkbm-gallery__add' ).click();
			await expect( modal ).toBeVisible();
			// 開いた直後はアップロードタブのことがあるため、ライブラリへ切り替える。
			await modal.locator( '#menu-item-browse' ).click();
			await expect(
				modal.locator( '.attachments-browser li.attachment' ).first()
			).toBeVisible();
		};
		const pick = async ( id: number ) => {
			await modal
				.locator(
					`.attachments-browser li.attachment[data-id="${ id }"]`
				)
				.click();
		};
		// メディア選択画面で画像を1枚選んで追加する。
		const addFromLibrary = async ( id: number ) => {
			await openLibrary();
			// 開いた時点で、前回の選択が残っていない。
			await expect( modal.locator( '.attachment.selected' ) ).toHaveCount(
				0
			);
			await pick( id );
			await modal.locator( '.media-button-select' ).click();
			await expect( modal ).toBeHidden();
		};

		// @step 画像の追加ボタンでメディア選択画面を開き、ライブラリで追加用の1枚目の画像を選んだだけで Esc キーで閉じる
		// 画像を選んだだけで追加せずに閉じる（Esc）。選択は次に開いたとき残ってはならない。
		await openLibrary();
		await pick( extra[ 0 ] );
		await expect( modal.locator( '.attachment.selected' ) ).toHaveCount(
			1
		);
		await page.keyboard.press( 'Escape' );
		await expect( modal ).toBeHidden();
		// @check 画像は追加されず3枚のまま
		await expect( items ).toHaveCount( 3 );

		// @step もう一度メディア選択画面を開き、前回の選択が残っていないことを見てから、追加用の2枚目の画像を選んで追加する
		// 別の1枚だけを追加する。直前に選んで閉じた画像は一緒に追加されない。
		await addFromLibrary( extra[ 1 ] );
		// @check 選んだ1枚だけが追加されて4枚になる
		await expect( items ).toHaveCount( 4 );
		await expect( hidden ).toHaveValue(
			[ ...imageIds, extra[ 1 ] ].join( ',' )
		);

		// @step 追加した画像の×を押して外す
		// 一覧の×で外す。
		await items.nth( 3 ).locator( '.vkbm-gallery__remove' ).click();
		// @check 3枚に戻る
		await expect( items ).toHaveCount( 3 );

		// @step もう一度メディア選択画面を開き、追加用の1枚目の画像を選んで追加する
		// もう一度、別の1枚を追加する。外した画像は戻らず、重複の通知も出ない。
		await addFromLibrary( extra[ 0 ] );
		// @check 選んだ画像だけが追加されて4枚になり、外した画像は戻らず、重複の通知も出ない
		await expect( items ).toHaveCount( 4 );
		await expect( hidden ).toHaveValue(
			[ ...imageIds, extra[ 0 ] ].join( ',' )
		);
		await expect( status ).toHaveText( '' );
	} );

	test( 'カード: サブ画像が全件出て、拡大表示を開閉・前後送りできる', async ( {
		page,
	} ) => {
		// @step メニューの個別ページを開く
		await page.goto( `/?p=${ menuId }` );

		// @check カードにサブ画像のサムネイルが3枚、正方形で表示される
		const thumbs = page.locator( '.vkbm-menu-loop__gallery-button' );
		await expect( thumbs ).toHaveCount( 3 );

		// サムネイルは正方形で表示される。
		const box = await thumbs.first().boundingBox();
		expect( box ).not.toBeNull();
		expect(
			Math.abs( ( box?.width ?? 0 ) - ( box?.height ?? 1 ) )
		).toBeLessThan( 1 );

		const dialog = page.locator( 'dialog.vkbm-gallery-dialog' );
		await expect( dialog ).toHaveCount( 0 );

		// @step 1枚目のサムネイルを押す
		// 1枚目を開く: 位置表示は 1 / 3、前へは押せない、フォーカスは閉じるボタン。
		await thumbs.nth( 0 ).click();
		// @check 拡大表示が開き、位置表示が「1 / 3」で、前へのボタンは押せず、フォーカスは閉じるボタンにある
		await expect( dialog ).toBeVisible();
		await expect(
			dialog.locator( '.vkbm-gallery-dialog__position' )
		).toHaveText( '1 / 3' );
		await expect(
			dialog.locator( '.vkbm-gallery-dialog__previous' )
		).toBeDisabled();
		await expect(
			dialog.locator( '.vkbm-gallery-dialog__close' )
		).toBeFocused();

		// @step 右矢印キーを押す
		// 矢印キーとボタンで前後に送れる。2枚目にはキャプションが出る。
		await page.keyboard.press( 'ArrowRight' );
		// @check 位置表示が「2 / 3」になり、キャプション「キャプション2」が出る
		await expect(
			dialog.locator( '.vkbm-gallery-dialog__position' )
		).toHaveText( '2 / 3' );
		await expect(
			dialog.locator( '.vkbm-gallery-dialog__caption' )
		).toHaveText( 'キャプション2' );
		// @step 次へのボタンを押す
		await dialog.locator( '.vkbm-gallery-dialog__next' ).click();
		// @check 位置表示が「3 / 3」になり、次へのボタンは押せない
		await expect(
			dialog.locator( '.vkbm-gallery-dialog__position' )
		).toHaveText( '3 / 3' );
		await expect(
			dialog.locator( '.vkbm-gallery-dialog__next' )
		).toBeDisabled();
		// @step 左矢印キーを押す
		await page.keyboard.press( 'ArrowLeft' );
		// @check 位置表示が「2 / 3」に戻る
		await expect(
			dialog.locator( '.vkbm-gallery-dialog__position' )
		).toHaveText( '2 / 3' );

		// @step Esc キーを押す
		// Esc で閉じると、押したサムネイル（1枚目）へフォーカスが戻る。
		await page.keyboard.press( 'Escape' );
		// @check 拡大表示が閉じ、1枚目のサムネイルにフォーカスが戻る
		await expect( dialog ).toBeHidden();
		await expect( thumbs.nth( 0 ) ).toBeFocused();

		// @step 3枚目のサムネイルを押して拡大表示を開き、背景（画面の左上の隅）を押す
		// 3枚目を開いて、背景クリックで閉じる。
		await thumbs.nth( 2 ).click();
		await expect( dialog ).toBeVisible();
		await page.mouse.click( 5, 5 );
		// @check 拡大表示が閉じ、3枚目のサムネイルにフォーカスが戻る
		await expect( dialog ).toBeHidden();
		await expect( thumbs.nth( 2 ) ).toBeFocused();

		// @step 2枚目のサムネイルを押して拡大表示を開き、×ボタンを押す
		// ×ボタンで閉じる。
		await thumbs.nth( 1 ).click();
		await dialog.locator( '.vkbm-gallery-dialog__close' ).click();
		// @check 拡大表示が閉じる
		await expect( dialog ).toBeHidden();
	} );

	test( 'カード: 低い画面でも拡大表示の前後ボタンと位置表示が画面内に収まる', async ( {
		page,
	} ) => {
		// @step 画面を縦持ちのスマホ（375×667px）と横持ち（667×375px）の大きさにして、それぞれメニューの個別ページで、大きな写真を想定して1枚目（キャプション無し）と2枚目（キャプション付き）のサムネイルを押す
		// 縦持ちのスマホ（高さ 667px）と横持ち（高さ 375px）を確認する。
		const viewports = [
			{ width: 375, height: 667 },
			{ width: 667, height: 375 },
		];

		// 0: キャプション無しの画像、1: キャプション付きの画像（2枚目）。
		for ( const viewport of viewports ) {
			for ( const thumbIndex of [ 0, 1 ] ) {
				await page.setViewportSize( viewport );
				await page.goto( `/?p=${ menuId }` );
				// テスト画像は 1px 四方で高さの検証にならないため、実写真のように
				// 大きな画像として扱わせる（画像側が残りの高さに収まることを確認する）。
				await page.addStyleTag( {
					content:
						'.vkbm-gallery-dialog__image{width:3000px;height:2000px}',
				} );
				await page
					.locator( '.vkbm-menu-loop__gallery-button' )
					.nth( thumbIndex )
					.click();

				const dialog = page.locator( 'dialog.vkbm-gallery-dialog' );
				await expect( dialog ).toBeVisible();

				// @check 拡大表示の次へのボタン・位置表示・閉じるボタンが、画面の縦の範囲内に完全に収まっている
				// 前後ボタン・位置表示・閉じるボタンが、画面の範囲内に完全に入っている。
				for ( const selector of [
					'.vkbm-gallery-dialog__next',
					'.vkbm-gallery-dialog__position',
					'.vkbm-gallery-dialog__close',
				] ) {
					const box = await dialog.locator( selector ).boundingBox();
					expect( box, selector ).not.toBeNull();
					expect( box!.y, selector ).toBeGreaterThanOrEqual( 0 );
					expect(
						box!.y + box!.height,
						selector
					).toBeLessThanOrEqual( viewport.height );
				}

				await page.keyboard.press( 'Escape' );
			}
		}
	} );
} );
