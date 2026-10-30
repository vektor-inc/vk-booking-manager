/**
 * issue #635: サービスメニューの詳細ページを予約ブロック内で表示できるようにする。
 *
 * 検証するシナリオ:
 *   1. 設定「予約ブロック内で表示」にすると、「詳細を見る」のリンク先が予約ページの詳細表示用URLになる。
 *   2. サービスメニューの個別ページへ直接アクセスすると、詳細表示用URLへ 302 で転送される。
 *   3. 詳細表示は「メニュー名（h1）→ 本文 → カード」の順で、ヘッダーのナビに「一覧に戻る」がある。
 *      パンくず・独立した予約ボタンは無く、カード内に「詳細を見る」は無い。
 *   4. カードの「予約に進む」で、再読み込みなしに予約画面へ切り替わり、ブラウザの戻るで詳細へ戻れる。
 *   5. 詳細ページを使用しないメニューの詳細表示用URLは、通常の予約画面＋案内を一度だけ出す。
 *   6. 設定を「WordPressの個別投稿として表示」へ戻すと、リンク先が個別ページに戻り、転送もされない。
 *
 * 注: 語句は言語設定で変わるため、クラス名・属性を中心にセレクトする。
 */
import { test, expect } from '@playwright/test';
import { wpEvalPhp } from '../utils/helpers';
import { configureProviderSettings } from '../utils/setup';

const MENU_TITLE = 'Detail In Block E2E Menu';
const MENU_BODY_TEXT = 'ブロック内詳細の本文テキスト';

/**
 * 保存済みの基本設定から、このテストが書き換える2項目の現在値を取得する。
 *
 * @return 表示方法（未保存なら空文字）と予約ページURL
 */
function readOriginalSettings(): { mode: string; reservationUrl: string } {
	const raw = wpEvalPhp( `
		$settings = get_option( 'vkbm_provider_settings', array() );
		echo wp_json_encode( array(
			'mode'           => isset( $settings['service_menu_detail_display_mode'] ) ? $settings['service_menu_detail_display_mode'] : '',
			'reservationUrl' => isset( $settings['reservation_page_url'] ) ? $settings['reservation_page_url'] : '',
		) );
	` );
	return JSON.parse( raw.trim() );
}

/**
 * 検証用のサービスメニュー（詳細ページON・本文あり）を作り直す。
 *
 * 予約画面（カレンダー）まで進めるよう、既存のサービスメニューと同じスタッフを割り当てる。
 *
 * @param useDetailPage 「詳細ページを使用する」をONにするか
 * @return メニューの投稿ID・個別ページのURL・予約ページのURL
 */
function createDetailMenu( useDetailPage: boolean ): {
	menuId: number;
	permalink: string;
	bookingUrl: string;
} {
	const phpCode = `
		$existing = get_posts( array(
			'post_type'   => 'vkbm_service_menu',
			'post_status' => 'any',
			'title'       => '${ MENU_TITLE }',
			'fields'      => 'ids',
			'numberposts' => -1,
		) );
		foreach ( $existing as $eid ) {
			wp_delete_post( (int) $eid, true );
		}

		// 既存メニュー（global-setup が作成）の対応スタッフを引き継ぐ。
		$base = get_posts( array(
			'post_type'   => 'vkbm_service_menu',
			'post_status' => 'publish',
			'numberposts' => 1,
			'orderby'     => 'ID',
			'order'       => 'ASC',
		) );
		$staff_ids = $base ? get_post_meta( $base[0]->ID, '_vkbm_staff_ids', true ) : array();

		$menu_id = wp_insert_post( array(
			'post_type'    => 'vkbm_service_menu',
			'post_status'  => 'publish',
			'post_title'   => '${ MENU_TITLE }',
			'post_content' => '<!-- wp:paragraph --><p>${ MENU_BODY_TEXT }</p><!-- /wp:paragraph -->',
		) );
		update_post_meta( $menu_id, '_vkbm_base_price', 5000 );
		update_post_meta( $menu_id, '_vkbm_duration_minutes', 60 );
		update_post_meta( $menu_id, '_vkbm_use_detail_page', ${
			useDetailPage ? "'1'" : "''"
		} );
		if ( is_array( $staff_ids ) ) {
			update_post_meta( $menu_id, '_vkbm_staff_ids', $staff_ids );
		}

		echo wp_json_encode( array(
			'menuId'     => (int) $menu_id,
			'permalink'  => get_permalink( $menu_id ),
			'bookingUrl' => home_url( '/booking/' ),
		) );
	`;
	const result = JSON.parse( wpEvalPhp( phpCode ).trim() );
	if ( ! result.menuId ) {
		throw new Error( 'Failed to create the detail test menu.' );
	}
	return result;
}

test.describe( 'issue #635: サービスメニュー詳細を予約ブロック内で表示', () => {
	let original: { mode: string; reservationUrl: string };
	let menuId: number;
	let permalink: string;
	let bookingUrl: string;

	test.beforeAll( () => {
		original = readOriginalSettings();
	} );

	test.afterAll( async () => {
		// 書き換えた2項目を元へ戻す（未保存だった表示方法は従来の template にそろえる）。
		await configureProviderSettings( {
			service_menu_detail_display_mode: original.mode || 'template',
			reservation_page_url: original.reservationUrl,
		} );
		wpEvalPhp( `
			$existing = get_posts( array(
				'post_type'   => 'vkbm_service_menu',
				'post_status' => 'any',
				'title'       => '${ MENU_TITLE }',
				'fields'      => 'ids',
				'numberposts' => -1,
			) );
			foreach ( $existing as $eid ) {
				wp_delete_post( (int) $eid, true );
			}
		` );
	} );

	test.beforeEach( async () => {
		// @step 管理画面で詳細ページを使うメニュー「Detail In Block E2E Menu」（本文あり）を作り、共通設定でサービスメニュー詳細の表示方法を「予約ブロック内で表示」、予約ページURLを /booking/ にしておく
		( { menuId, permalink, bookingUrl } = createDetailMenu( true ) );
		// 設定を「予約ブロック内で表示」＋自サイトの予約ページURLにする。
		await configureProviderSettings( {
			service_menu_detail_display_mode: 'block',
			reservation_page_url: bookingUrl,
		} );
	} );

	test( '「詳細を見る」のリンク先が詳細表示用URLになり、個別ページは 302 で転送される', async ( {
		page,
		request,
	} ) => {
		// @step 予約ページを開く
		await page.goto( '/booking/' );

		// @check 「Detail In Block E2E Menu」のカードの「詳細を見る」のリンク先が、予約ページの詳細表示用URL（/booking/?menu_id=…&vkbm_view=detail）になっている
		// 一覧のカードの「詳細を見る」（primary ボタン）が、予約ページ + menu_id + vkbm_view=detail を指す。
		const detailLink = page.locator(
			`.vkbm-menu-loop__item[data-menu-id="${ menuId }"] a.vkbm-button__primary`
		);
		await expect( detailLink ).toBeVisible();
		const href = new URL(
			( await detailLink.getAttribute( 'href' ) ) || ''
		);
		expect( href.pathname ).toBe( '/booking/' );
		expect( href.searchParams.get( 'menu_id' ) ).toBe( String( menuId ) );
		expect( href.searchParams.get( 'vkbm_view' ) ).toBe( 'detail' );

		// @step メニューの個別ページのURLを直接開く
		// 個別ページへ直接アクセスすると、転送先を変えずに 302 で詳細表示用URLへ移る。
		const response = await request.get( permalink, { maxRedirects: 0 } );
		// @check 予約ページの詳細表示用URLへ転送（302）される
		expect( response.status() ).toBe( 302 );
		const location = new URL( response.headers().location );
		expect( location.pathname ).toBe( '/booking/' );
		expect( location.searchParams.get( 'menu_id' ) ).toBe(
			String( menuId )
		);
		expect( location.searchParams.get( 'vkbm_view' ) ).toBe( 'detail' );
	} );

	test( '詳細表示: メニュー名・本文・カードが並び、ヘッダーに「一覧に戻る」がある', async ( {
		page,
	} ) => {
		// @step 予約ページの詳細表示用URLを開く
		await page.goto( `/booking/?menu_id=${ menuId }&vkbm_view=detail` );

		// @check 見出しにメニュー名が出てフォーカスがあたり、タブのタイトルが「メニュー名 | 元のタイトル」になる
		const detail = page.locator( '.vkbm-reservation-detail' );
		const heading = detail.locator( 'h1.vkbm-reservation-detail__title' );
		await expect( heading ).toHaveText( MENU_TITLE );
		// 表示時のフォーカスはメニュー名の見出しへ移る。
		await expect( heading ).toBeFocused();
		// タブのタイトルは「メニュー名 | 元のタイトル」。
		await expect( page ).toHaveTitle(
			new RegExp( `^${ MENU_TITLE } \\| ` )
		);

		// @check 本文、メニューのカードの順に並んでいる
		// 本文（サーバー側で the_content を通したもの）とカードが、この順で表示される。
		await expect(
			detail.locator( '.vkbm-reservation-detail__content' )
		).toContainText( MENU_BODY_TEXT );
		const card = detail.locator(
			'.vkbm-reservation-detail__card .vkbm-menu-loop__card-item'
		);
		await expect( card ).toBeVisible();
		const contentBox = await detail
			.locator( '.vkbm-reservation-detail__content' )
			.boundingBox();
		const cardBox = await card.boundingBox();
		expect( contentBox!.y ).toBeLessThan( cardBox!.y );

		// @check カードに「詳細を見る」やタイトルのリンクは無く、「予約に進む」ボタンはある
		// カード内には自分自身へのリンク（詳細を見る・タイトルのリンク）が無い。予約に進むボタンはある。
		await expect( card.locator( 'a.vkbm-button__primary' ) ).toHaveCount(
			0
		);
		await expect(
			card.locator( '.vkbm-menu-loop__card-title a' )
		).toHaveCount( 0 );
		await expect(
			card.locator( 'a.vkbm-menu-loop__button--reserve' )
		).toHaveCount( 1 );

		// @check パンくずは無く、ヘッダーのナビの先頭に「一覧に戻る」（予約ページへのリンク）がある
		// パンくずは無く、ヘッダーのナビ先頭に「一覧に戻る」（予約ページURL・パラメーターなし）がある。
		await expect(
			page.locator( 'nav[aria-label*="readcrumb" i], .vkbm-breadcrumb' )
		).toHaveCount( 0 );
		const backLink = page
			.locator(
				'.vkbm-reservation-header__nav a.vkbm-reservation-header__nav-link'
			)
			.first();
		await expect( backLink ).toBeVisible();
		const backUrl = new URL(
			( await backLink.getAttribute( 'href' ) ) || ''
		);
		expect( backUrl.pathname ).toBe( '/booking/' );
		expect( backUrl.search ).toBe( '' );

		// 一覧に戻ると、サービスメニュー一覧が表示される。
		// @step 「一覧に戻る」を押す
		await backLink.click();
		// @check 予約ページのサービスメニュー一覧が表示される
		await expect( page ).toHaveURL( /\/booking\/$/ );
		await expect(
			page.locator( '.vkbm-menu-loop__item' ).first()
		).toBeVisible();
	} );

	test( 'カードの「予約に進む」で再読み込みなしに予約画面へ切り替わり、戻るで詳細へ戻れる', async ( {
		page,
	} ) => {
		// @step 予約ページの詳細表示用URLを開く
		await page.goto( `/booking/?menu_id=${ menuId }&vkbm_view=detail` );
		const heading = page.locator( 'h1.vkbm-reservation-detail__title' );
		await expect( heading ).toBeVisible();

		// ページが再読み込みされていないことを、window に付けた印で確かめる。
		await page.evaluate( () => {
			( window as any ).__vkbmDetailMarker = 'kept';
		} );

		// @step カードの「予約に進む」を押す
		await page
			.locator(
				'.vkbm-reservation-detail__card a.vkbm-menu-loop__button--reserve'
			)
			.click();

		// @check ページの再読み込み無しに、そのメニューを選んだ予約画面（カレンダー）へ切り替わり、詳細表示は消える
		// そのメニューを選択済みの予約画面（カレンダー）へ切り替わる。
		await expect( page.locator( '.vkbm-calendar' ) ).toBeVisible( {
			timeout: 15000,
		} );
		await expect( page.locator( '.vkbm-reservation-detail' ) ).toHaveCount(
			0
		);
		await expect( page ).toHaveURL( new RegExp( `menu_id=${ menuId }` ) );
		await expect( page ).not.toHaveURL( /vkbm_view=detail/ );
		expect(
			await page.evaluate( () => ( window as any ).__vkbmDetailMarker )
		).toBe( 'kept' );
		// @check フォーカスが予約画面の内容へ移り、タブのタイトルが元に戻る
		// フォーカスは body に落ちず、予約画面の内容領域へ移る。
		await expect(
			page.locator( '.vkbm-reservation-content' )
		).toBeFocused();
		// 詳細表示をやめたら、タブのタイトルは元に戻る。
		await expect( page ).not.toHaveTitle(
			new RegExp( `^${ MENU_TITLE } \\| ` )
		);

		// @step ブラウザの「戻る」を押す
		// ブラウザの戻るで詳細表示へ戻る。
		await page.goBack();
		// @check 詳細表示へ戻り、メニュー名の見出しが表示される
		await expect( heading ).toBeVisible();
		await expect( heading ).toHaveText( MENU_TITLE );
		await expect( page ).toHaveURL( /vkbm_view=detail/ );
	} );

	test( '詳細ページを使用しないメニューの詳細表示用URLは、通常の予約画面と案内を一度だけ出す', async ( {
		page,
	} ) => {
		// @step 管理画面で詳細ページを使わないメニューを作り、その詳細表示用URLを開く
		const off = createDetailMenu( false );
		await page.goto( `/booking/?menu_id=${ off.menuId }&vkbm_view=detail` );

		// @check 詳細表示は出ず、通常のサービスメニュー一覧と案内が1つだけ表示される
		await expect( page.locator( '.vkbm-reservation-detail' ) ).toHaveCount(
			0
		);
		// 通常の予約画面（サービスメニュー一覧）が表示される。
		await expect(
			page.locator( '.vkbm-menu-loop__item' ).first()
		).toBeVisible();
		// 案内は role="status" で1つだけ。
		const notice = page.locator(
			'.vkbm-reservation-content__detail-notice[role="status"]'
		);
		await expect( notice ).toHaveCount( 1 );
		// 空の領域が先に描画され、案内文は後から中身として入る（読み上げられるように）。
		await expect( notice.locator( 'p.vkbm-alert__warning' ) ).toBeVisible();

		// @step 存在しないメニューの詳細表示用URLを開く
		// 存在しないIDでも同様に案内する。
		await page.goto( '/booking/?menu_id=99999999&vkbm_view=detail' );
		// @check 同じく案内が1つだけ表示される
		await expect(
			page.locator( '.vkbm-reservation-content__detail-notice' )
		).toHaveCount( 1 );
	} );

	test( '設定を「WordPressの個別投稿として表示」へ戻すと、リンク先が個別ページに戻り転送もされない', async ( {
		page,
		request,
	} ) => {
		// @step 共通設定でサービスメニュー詳細の表示方法を「WordPressの個別投稿として表示」に戻して、予約ページを開く
		await configureProviderSettings( {
			service_menu_detail_display_mode: 'template',
			reservation_page_url: bookingUrl,
		} );

		await page.goto( '/booking/' );
		const detailLink = page.locator(
			`.vkbm-menu-loop__item[data-menu-id="${ menuId }"] a.vkbm-button__primary`
		);
		// @check 「詳細を見る」のリンク先がメニューの個別ページになっている
		await expect( detailLink ).toBeVisible();
		expect( await detailLink.getAttribute( 'href' ) ).toBe( permalink );

		// @check メニューの個別ページを開くと、転送されずにそのまま表示される
		// 個別ページは転送されずにそのまま表示される。
		const response = await request.get( permalink, { maxRedirects: 0 } );
		expect( response.status() ).toBe( 200 );
	} );
} );
