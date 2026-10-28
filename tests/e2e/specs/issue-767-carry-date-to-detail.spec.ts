/**
 * issue #767: 絞り込み検索で選んだ日付を、詳細ページ経由でも予約画面へ引き継ぐ。
 *
 * 検証するシナリオ（時間枠のサイト）:
 *   1. 絞り込み検索の「日付」欄で日付を選ぶと、表示中の URL に date が付く（履歴は増やさない）。
 *   2. 一覧の「詳細を見る」のリンクと詳細表示の URL に date が付く。
 *   3. 詳細表示の「予約に進む」で、予約カレンダーに日付が選択済みで開く（時間枠は未選択）。
 *   4. ブラウザの戻る／詳細表示の「一覧に戻る」で、日付が残った一覧へ戻る。
 *   5. 日付を選ばないときは従来どおり日付未選択（date が付かない）。
 *   6. 引き継いだ日付が予約できない・過去日のときは、未選択に戻し、カレンダーの直上に
 *      role="status" の案内を1行出す。日付を選び直すと案内は消える。
 *   7. 「WordPressの個別投稿として表示」の個別ページでも、URL の日付が「予約に進む」へ引き継がれる。
 *
 * 宿泊のサイト（業種プリセット「宿泊」, Pro 版のみ）:
 *   8. チェックイン日・チェックアウト日を選んで詳細 → 「予約に進む」で、日程選択に入力済みで開く。
 *   9. 予約できない日程・過去の日程は未入力に戻り、日程選択の直上に案内が出る。
 *  10. チェックアウト日が無く date だけの URL は、日程を引き継がない（リンクにも付けない）。
 *
 * 注: 語句は言語設定で変わるため、クラス名・属性を中心にセレクトする（案内の文言だけは日本語で確かめる）。
 * テストデータは接頭辞（E2E767）を付けて作り、afterAll で消す。BM 設定は beforeAll で控えて必ず戻す。
 */
import { test, expect, Page } from '@playwright/test';
import {
	wpCliArgs,
	wpEvalPhp,
	createShiftForMonth,
	getCurrentAndNextTokyoMonths,
	getTokyoDateParts,
} from '../utils/helpers';
import { configureProviderSettings } from '../utils/setup';

const PREFIX = 'E2E767';
const OPTION_NAME = 'vkbm_provider_settings';
const SLOT_MENU_TITLE = `${ PREFIX } Slot Menu`;
const NIGHT_MENU_TITLE = `${ PREFIX } Night Plan`;

let originalSettingsJson: string | null = null;
let bookingUrl = '';
let slotMenuId = '';
let slotPermalink = '';
let roomId = '';
let nightMenuId = '';

/**
 * 今日（Asia/Tokyo）から offset 日後の日付を YYYY-MM-DD で返す。
 *
 * @param offset 日数（負の値は過去）
 * @return YYYY-MM-DD
 */
function tokyoIsoAfter( offset: number ): string {
	const date = new Date( Date.now() + offset * 24 * 60 * 60 * 1000 );
	const { year, month, day } = getTokyoDateParts( date );
	return `${ year }-${ month }-${ day }`;
}

/**
 * offset 日後以降で、チェックイン日とチェックアウト日が同じ月に収まる最初の日程を返す。
 *
 * 宿泊の日程選択カレンダーは1か月分だけを表示し、表示月の外の日は押せない空のマスになる。
 * 月をまたぐ日程（例: 10/31〜11/2）だとチェックアウト日が画面に出ず、
 * 選択済みの日の数を数えるテストが実行日によって失敗するため、同じ月に収まる日程を使う。
 *
 * @param offset 最初に試すチェックイン日（今日から何日後か）
 * @param nights 泊数
 * @return チェックイン日・チェックアウト日（YYYY-MM-DD）
 */
function tokyoStayInOneMonth(
	offset: number,
	nights: number
): { checkIn: string; checkOut: string } {
	for ( let start = offset; start < offset + 31; start++ ) {
		const checkIn = tokyoIsoAfter( start );
		const checkOut = tokyoIsoAfter( start + nights );
		// YYYY-MM が同じなら同じ月に収まる。
		if ( checkIn.slice( 0, 7 ) === checkOut.slice( 0, 7 ) ) {
			return { checkIn, checkOut };
		}
	}
	throw new Error( '同じ月に収まる日程が見つかりませんでした' );
}

/**
 * PHP を実行し、出力が正の整数（post ID）であることを確かめて返す。
 *
 * @param php   実行する PHP（最後に ID を echo する）
 * @param label エラーメッセージ用の名前
 * @return post ID（数値文字列）
 */
function insertAndGetId( php: string, label: string ): string {
	const result = wpEvalPhp( php ).trim();
	if ( ! /^\d+$/.test( result ) || Number( result ) <= 0 ) {
		throw new Error( `${ label } seeding failed: "${ result }"` );
	}
	return result;
}

/**
 * このスペックが作ったデータを接頭辞で探して消す（予約 → シフト → プラン → 客室の順）。
 */
function cleanupSeededData(): void {
	wpEvalPhp( `
		$prefix = '${ PREFIX }';
		$find = static function ( $post_type ) use ( $prefix ) {
			$ids = array();
			foreach ( get_posts( array(
				'post_type'   => $post_type,
				'post_status' => 'any',
				'numberposts' => -1,
				's'           => $prefix,
			) ) as $p ) {
				if ( 0 === strpos( $p->post_title, $prefix ) ) { $ids[] = $p->ID; }
			}
			return $ids;
		};
		$rooms = $find( 'vkbm_resource' );
		$menus = $find( 'vkbm_service_menu' );
		if ( $rooms ) {
			foreach ( get_posts( array(
				'post_type'   => 'vkbm_shift',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_query'  => array( array( 'key' => '_vkbm_shift_resource_id', 'value' => $rooms, 'compare' => 'IN' ) ),
			) ) as $sid ) { wp_delete_post( $sid, true ); }
		}
		foreach ( $menus as $mid ) { wp_delete_post( $mid, true ); }
		foreach ( $rooms as $rid ) { wp_delete_post( $rid, true ); }
		echo 'ok';
	` );
}

/**
 * BM 設定を beforeAll で控えた値へ戻す。控えが無ければ option ごと消す。
 */
function restoreProviderSettings(): void {
	if ( originalSettingsJson ) {
		wpCliArgs(
			[
				'option',
				'update',
				OPTION_NAME,
				originalSettingsJson,
				'--format=json',
			],
			{ stdio: 'pipe' }
		);
		return;
	}
	try {
		wpCliArgs( [ 'option', 'delete', OPTION_NAME ], { stdio: 'pipe' } );
	} catch {
		// 元から無いなら何もしない。
	}
}

/**
 * 個別ページのパーマリンクに、クエリを1つ足した相対パス（パス＋クエリ）を返す。
 * パーマリンクが ?vkbm_service_menu=… 形式でも、パス形式でも使える。
 *
 * @param key   足すクエリのキー
 * @param value 足すクエリの値
 * @return パスとクエリ
 */
function permalinkWith( key: string, value: string ): string {
	const url = new URL( slotPermalink );
	url.searchParams.set( key, value );
	return `${ url.pathname }${ url.search }`;
}

/**
 * 絞り込み検索で日付を選び、表示中の URL に date が付くまで待つ。
 *
 * @param page Playwright の Page
 * @param iso  選ぶ日付（YYYY-MM-DD）
 */
async function pickFilterDate( page: Page, iso: string ) {
	await page.locator( 'input.vkbm-plan-summary__date-input' ).fill( iso );
	await expect( page ).toHaveURL( new RegExp( `[?&]date=${ iso }` ) );
}

/**
 * 詳細表示のカードの「予約に進む」を押す。
 *
 * @param page Playwright の Page
 */
async function clickReserveInDetail( page: Page ) {
	await page
		.locator(
			'.vkbm-reservation-detail__card a.vkbm-menu-loop__button--reserve'
		)
		.click();
}

test.describe
	.serial( 'issue #767: 絞り込みで選んだ日付を詳細経由で引き継ぐ', () => {
	test.beforeAll( () => {
		try {
			originalSettingsJson = wpCliArgs(
				[ 'option', 'get', OPTION_NAME, '--format=json' ],
				{ stdio: 'pipe' }
			);
		} catch {
			originalSettingsJson = null;
		}

		bookingUrl = wpEvalPhp( `echo home_url( '/booking/' );` ).trim();

		// 時間枠のメニュー（詳細ページ ON）。スタッフは global-setup が作った既存メニューのものを使う。
		slotMenuId = insertAndGetId(
			`
			$base = get_posts( array(
				'post_type'   => 'vkbm_service_menu',
				'post_status' => 'publish',
				'numberposts' => 1,
				'orderby'     => 'ID',
				'order'       => 'ASC',
			) );
			$staff_ids = $base ? get_post_meta( $base[0]->ID, '_vkbm_staff_ids', true ) : array();
			$id = wp_insert_post( array(
				'post_type'    => 'vkbm_service_menu',
				'post_status'  => 'publish',
				'post_title'   => '${ SLOT_MENU_TITLE }',
				'post_content' => '<!-- wp:paragraph --><p>767 detail body</p><!-- /wp:paragraph --><!-- wp:vk-booking-manager/reservation-button /-->',
			) );
			if ( is_wp_error( $id ) || ! $id ) { echo 'Error'; return; }
			update_post_meta( $id, '_vkbm_base_price', 5000 );
			update_post_meta( $id, '_vkbm_duration_minutes', 60 );
			update_post_meta( $id, '_vkbm_use_detail_page', '1' );
			if ( is_array( $staff_ids ) ) {
				update_post_meta( $id, '_vkbm_staff_ids', $staff_ids );
			}
			echo $id;
		`,
			'slot menu'
		);
		slotPermalink = wpEvalPhp(
			`echo get_permalink( ${ Number( slotMenuId ) } );`
		).trim();

		// 宿泊用の客室・泊のプラン（詳細ページ ON）。当月＋翌月のシフトを入れる。
		roomId = insertAndGetId(
			`
			$id = wp_insert_post( array(
				'post_type'   => 'vkbm_resource',
				'post_status' => 'publish',
				'post_title'  => '${ PREFIX } Room',
			) );
			if ( is_wp_error( $id ) || ! $id ) { echo 'Error'; return; }
			echo $id;
		`,
			'room'
		);
		for ( const { year, month } of getCurrentAndNextTokyoMonths() ) {
			createShiftForMonth( roomId, year, month );
		}
		nightMenuId = insertAndGetId(
			`
			$id = wp_insert_post( array(
				'post_type'    => 'vkbm_service_menu',
				'post_status'  => 'publish',
				'post_title'   => '${ NIGHT_MENU_TITLE }',
				'post_content' => '<!-- wp:paragraph --><p>767 night body</p><!-- /wp:paragraph -->',
			) );
			if ( is_wp_error( $id ) || ! $id ) { echo 'Error'; return; }
			update_post_meta( $id, '_vkbm_staff_ids', array( ${ Number( roomId ) } ) );
			update_post_meta( $id, '_vkbm_base_price', 10000 );
			update_post_meta( $id, '_vkbm_booking_unit', 'night' );
			update_post_meta( $id, '_vkbm_use_detail_page', '1' );
			echo $id;
		`,
			'night menu'
		);
	} );

	test.afterAll( () => {
		try {
			cleanupSeededData();
		} finally {
			restoreProviderSettings();
		}
	} );

	test.describe( '時間枠のサイト', () => {
		test.beforeAll( async () => {
			await configureProviderSettings( {
				industry_preset: 'custom',
				reservation_show_menu_search: 1,
				reservation_date_search_enabled: 1,
				reservation_show_menu_list: 1,
				service_menu_detail_display_mode: 'block',
				reservation_page_url: bookingUrl,
			} );
		} );

		test( '日付を選ぶと URL に date が付き、詳細 → 予約に進むで日付が選択済みになる。戻る・一覧に戻るでも日付が残る', async ( {
			page,
		} ) => {
			const iso = tokyoIsoAfter( 3 );
			await page.goto( '/booking/' );
			await pickFilterDate( page, iso );

			// 一覧の詳細リンクに日付が付く。
			const detailLink = page.locator(
				`.vkbm-menu-loop__item[data-menu-id="${ slotMenuId }"] a.vkbm-button__primary`
			);
			// 一覧はメニュー取得後に描画される。冷えた環境・重い環境では 5 秒の既定では足りない。
			await expect( detailLink ).toBeVisible( { timeout: 20000 } );
			// 絞り込み後の一覧の再描画を待つ（href に date が付くまで）。再描画前の href を読まない。
			await expect( detailLink ).toHaveAttribute(
				'href',
				new RegExp( `[?&]date=${ iso }` )
			);
			const href = new URL(
				( await detailLink.getAttribute( 'href' ) ) || ''
			);
			expect( href.searchParams.get( 'date' ) ).toBe( iso );
			expect( href.searchParams.get( 'vkbm_view' ) ).toBe( 'detail' );

			// 詳細表示へ。URL に date が付いている。
			await detailLink.click();
			await expect( page ).toHaveURL( /vkbm_view=detail/ );
			await expect( page ).toHaveURL( new RegExp( `date=${ iso }` ) );
			await expect(
				page.locator( 'h1.vkbm-reservation-detail__title' )
			).toBeVisible();

			// 「一覧に戻る」は日付が残った一覧へ戻る。
			const backLink = page
				.locator( '.vkbm-reservation-header__nav a' )
				.first();
			expect(
				new URL(
					( await backLink.getAttribute( 'href' ) ) || ''
				).searchParams.get( 'date' )
			).toBe( iso );

			// 「予約に進む」: 日付が選択済みのカレンダーが開き、時間枠は未選択。案内は出ない。
			await clickReserveInDetail( page );
			await expect( page.locator( '.vkbm-calendar' ) ).toBeVisible( {
				timeout: 15000,
			} );
			const selectedDay = page.locator( '.vkbm-calendar__day--selected' );
			await expect( selectedDay ).toHaveCount( 1 );
			await expect(
				selectedDay.locator( '.vkbm-calendar__day-label' )
			).toHaveText( String( Number( iso.slice( 8 ) ) ) );
			await expect(
				page.locator( '.vkbm-slot-list__item' ).first()
			).toBeVisible( { timeout: 15000 } );
			await expect(
				page.locator( '.vkbm-slot-list__item[aria-pressed="true"]' )
			).toHaveCount( 0 );
			await expect(
				page.locator( '.vkbm-reservation-content__carry-notice p' )
			).toHaveCount( 0 );

			// ブラウザの戻るを2回で、日付が残った一覧へ戻る（絞り込み欄にも日付が入る）。
			await page.goBack();
			await expect( page ).toHaveURL( /vkbm_view=detail/ );
			await page.goBack();
			await expect( page ).toHaveURL( new RegExp( `date=${ iso }` ) );
			await expect(
				page.locator( 'input.vkbm-plan-summary__date-input' )
			).toHaveValue( iso );

			// 「詳細を見る」→「一覧に戻る」でも日付の残った一覧が開く。
			await detailLink.click();
			await expect( page ).toHaveURL( /vkbm_view=detail/ );
			await page
				.locator( '.vkbm-reservation-header__nav a' )
				.first()
				.click();
			await expect( page ).not.toHaveURL( /vkbm_view=detail/ );
			await expect( page ).toHaveURL( new RegExp( `date=${ iso }` ) );
			await expect(
				page.locator( 'input.vkbm-plan-summary__date-input' )
			).toHaveValue( iso );
		} );

		test( '日付を選ばないときは、リンクにも URL にも date が付かず、日付未選択で開く', async ( {
			page,
		} ) => {
			await page.goto( '/booking/' );
			const detailLink = page.locator(
				`.vkbm-menu-loop__item[data-menu-id="${ slotMenuId }"] a.vkbm-button__primary`
			);
			await expect( detailLink ).toBeVisible();
			expect(
				new URL(
					( await detailLink.getAttribute( 'href' ) ) || ''
				).searchParams.has( 'date' )
			).toBe( false );

			await detailLink.click();
			await clickReserveInDetail( page );
			await expect( page.locator( '.vkbm-calendar' ) ).toBeVisible( {
				timeout: 15000,
			} );
			await expect(
				page.locator( '.vkbm-calendar__day--selected' )
			).toHaveCount( 0 );
			await expect( page ).not.toHaveURL( /[?&]date=/ );
		} );

		test( '予約できない日付は未選択に戻り、カレンダーの直上に案内が出て、日付を選び直すと消える', async ( {
			page,
		} ) => {
			// シフトを入れていない遠い日付（予約できない）。
			const farIso = tokyoIsoAfter( 90 );
			await page.goto(
				`/booking/?menu_id=${ slotMenuId }&vkbm_view=detail&date=${ farIso }`
			);
			await clickReserveInDetail( page );
			await expect( page.locator( '.vkbm-calendar' ) ).toBeVisible( {
				timeout: 15000,
			} );

			// 案内は role="status" の領域に1行。日付は未選択のまま。
			const notice = page.locator(
				'.vkbm-reservation-content__carry-notice[role="status"]'
			);
			await expect( notice.locator( 'p' ) ).toHaveCount( 1, {
				timeout: 15000,
			} );
			await expect( notice ).toContainText( '予約できません' );
			await expect( notice ).toContainText(
				new RegExp( `${ farIso.slice( 0, 4 ) }年` )
			);
			await expect(
				page.locator( '.vkbm-calendar__day--selected' )
			).toHaveCount( 0 );
			// 案内は、カレンダーの直上（カレンダーより上）にある。
			const noticeBox = await notice.boundingBox();
			const calendarBox = await page
				.locator( '.vkbm-calendar' )
				.boundingBox();
			expect( noticeBox!.y + noticeBox!.height ).toBeLessThanOrEqual(
				calendarBox!.y + 1
			);

			// 日付を選び直すと案内は消える。表示月は引き継いだ日付の月（空きなし）のため、
			// 「空きのある月」まで前の月へ戻して日付を選ぶ。
			// シフトは当月と翌月に入っているため、実行日によらず必ず空きがあるのは翌月。
			// 戻す回数は、引き継いだ日付の月と翌月の差から決める（描画途中の件数を見て戻す回数を決めると、
			// 読み込み前に空と判定して翌月を通り過ぎ、空きの無い過去月まで戻ってしまう）。
			const nextMonth = getCurrentAndNextTokyoMonths()[ 1 ];
			const monthsBack =
				Number( farIso.slice( 0, 4 ) ) * 12 +
				Number( farIso.slice( 5, 7 ) ) -
				( nextMonth.year * 12 + nextMonth.month );
			expect( monthsBack ).toBeGreaterThan( 0 );
			const available = page.locator(
				'.vkbm-calendar__day--available:not(:disabled):not(.vkbm-calendar__day--muted)'
			);
			for ( let i = 0; i < monthsBack; i++ ) {
				// 表示月の見出しの変化と、その月の calendar-meta の応答完了を待つ（固定待ちはしない）。
				const heading = page.locator( '.vkbm-calendar__current' );
				const before = await heading.textContent();
				await Promise.all( [
					page.waitForResponse(
						( res ) =>
							res.url().includes( 'calendar-meta' ) &&
							res.status() === 200
					),
					page.locator( '.vkbm-calendar__nav' ).first().click(),
				] );
				await expect( heading ).not.toHaveText( before ?? '' );
				// 応答ヘッダー到着時点ではカレンダーへの反映前のため、読み込み表示（取得完了の finally で消える）が消えるまで待つ。
				await expect(
					page.locator( '.vkbm-calendar__spinner' )
				).toHaveCount( 0 );
			}
			// 翌月の空き日が描画されるまで待ってから選ぶ。
			await expect( available.first() ).toBeVisible( { timeout: 15000 } );
			await available.first().click();
			await expect( notice.locator( 'p' ) ).toHaveCount( 0 );
		} );

		test( '過去日を引き継いだときは未選択に戻り、「過ぎています」の案内が出る', async ( {
			page,
		} ) => {
			await page.goto(
				`/booking/?menu_id=${ slotMenuId }&vkbm_view=detail&date=${ tokyoIsoAfter(
					-3
				) }`
			);
			await clickReserveInDetail( page );
			await expect( page.locator( '.vkbm-calendar' ) ).toBeVisible( {
				timeout: 15000,
			} );
			const notice = page.locator(
				'.vkbm-reservation-content__carry-notice[role="status"]'
			);
			await expect( notice.locator( 'p' ) ).toHaveCount( 1, {
				timeout: 15000,
			} );
			await expect( notice ).toContainText( '過ぎています' );
			await expect(
				page.locator( '.vkbm-calendar__day--selected' )
			).toHaveCount( 0 );
		} );

		test( '個別投稿として表示する場合: 一覧の詳細リンクはパーマリンクのまま（仕様書どおり date を付けない）。URL に date が付いた個別ページの「予約に進む」は日付を引き継ぐ', async ( {
			page,
		} ) => {
			await configureProviderSettings( {
				service_menu_detail_display_mode: 'template',
			} );
			const iso = tokyoIsoAfter( 3 );

			// 一覧の詳細リンクは個別ページ（パーマリンク）を指し、date は付かない（docs/specification-service-menu.md）。
			await page.goto( '/booking/' );
			await pickFilterDate( page, iso );
			const detailLink = page.locator(
				`.vkbm-menu-loop__item[data-menu-id="${ slotMenuId }"] a.vkbm-button__primary`
			);
			await expect( detailLink ).toBeVisible();
			expect( await detailLink.getAttribute( 'href' ) ).toBe(
				slotPermalink
			);

			// URL に date が付いた個別ページ（共有された URL など）の「予約に進む」には menu_id と date が付く。
			await page.goto( permalinkWith( 'date', iso ) );
			const reserve = page
				.locator( 'a[href*="menu_id="][href*="date="]' )
				.first();
			await expect( reserve ).toBeVisible();
			const reserveUrl = new URL(
				( await reserve.getAttribute( 'href' ) ) || ''
			);
			expect( reserveUrl.searchParams.get( 'menu_id' ) ).toBe(
				slotMenuId
			);
			expect( reserveUrl.searchParams.get( 'date' ) ).toBe( iso );

			// 不正な値は引き継がない。
			await page.goto( permalinkWith( 'date', '2026-02-31' ) );
			const plain = page.locator( 'a[href*="menu_id="]' ).first();
			await expect( plain ).toBeVisible();
			expect(
				new URL(
					( await plain.getAttribute( 'href' ) ) || ''
				).searchParams.has( 'date' )
			).toBe( false );

			// 押すと、日付が選択済みの予約カレンダーが開く。
			await page.goto( permalinkWith( 'date', iso ) );
			await page
				.locator( 'a[href*="menu_id="][href*="date="]' )
				.first()
				.click();
			await expect( page.locator( '.vkbm-calendar' ) ).toBeVisible( {
				timeout: 15000,
			} );
			await expect(
				page.locator( '.vkbm-calendar__day--selected' )
			).toHaveCount( 1 );
		} );
	} );

	test.describe( '宿泊のサイト', () => {
		test.beforeAll( async () => {
			await configureProviderSettings( {
				industry_preset: 'lodging',
				reservation_show_menu_search: 1,
				reservation_date_search_enabled: 1,
				reservation_show_menu_list: 1,
				service_menu_detail_display_mode: 'block',
				reservation_page_url: bookingUrl,
			} );
		} );

		test( 'チェックイン日・チェックアウト日が詳細経由で日程選択に入力済みで開き、日程を指定しないときは未入力で開く', async ( {
			page,
		} ) => {
			const { checkIn, checkOut } = tokyoStayInOneMonth( 3, 2 );
			await page.goto( '/booking/' );
			const inputs = page.locator(
				'input.vkbm-plan-summary__date-input'
			);
			await inputs.nth( 0 ).fill( checkIn );
			await inputs.nth( 1 ).fill( checkOut );
			await expect( page ).toHaveURL(
				new RegExp(
					`date=${ checkIn }.*check_out_date=${ checkOut }|check_out_date=${ checkOut }.*date=${ checkIn }`
				)
			);

			const detailLink = page.locator(
				`.vkbm-menu-loop__item[data-menu-id="${ nightMenuId }"] a.vkbm-button__primary`
			);
			await expect( detailLink ).toBeVisible( { timeout: 15000 } );
			// 絞り込み後の一覧の再描画を待つ（href に日程が付くまで）。再描画前の href を読まない。
			await expect( detailLink ).toHaveAttribute(
				'href',
				new RegExp( `[?&]check_out_date=${ checkOut }` )
			);
			const href = new URL(
				( await detailLink.getAttribute( 'href' ) ) || ''
			);
			expect( href.searchParams.get( 'date' ) ).toBe( checkIn );
			expect( href.searchParams.get( 'check_out_date' ) ).toBe(
				checkOut
			);

			await detailLink.click();
			await expect( page ).toHaveURL( /vkbm_view=detail/ );
			await clickReserveInDetail( page );

			// 日程選択（範囲カレンダー）が開き、チェックイン日・チェックアウト日が入力済み。
			await expect( page.locator( '.vkbm-lodging' ) ).toBeVisible( {
				timeout: 15000,
			} );
			await expect(
				page.locator( '.vkbm-stay-calendar [aria-pressed="true"]' )
			).toHaveCount( 2, { timeout: 15000 } );
			await expect(
				page.locator( '.vkbm-lodging__carry-notice p' )
			).toHaveCount( 0 );
			// 空室一覧が出ていて、客室は未選択。
			await expect( page.locator( '.vkbm-lodging__room' ) ).toHaveCount(
				1,
				{ timeout: 15000 }
			);
			await expect(
				page.locator( '.vkbm-lodging__room[aria-pressed="true"]' )
			).toHaveCount( 0 );

			// 対照: 日程を指定せずに同じ操作をすると、日程選択は未入力で開く。
			await page.goto( '/booking/' );
			const plainLink = page.locator(
				`.vkbm-menu-loop__item[data-menu-id="${ nightMenuId }"] a.vkbm-button__primary`
			);
			await expect( plainLink ).toBeVisible( { timeout: 15000 } );
			await plainLink.click();
			await clickReserveInDetail( page );
			await expect( page.locator( '.vkbm-lodging' ) ).toBeVisible( {
				timeout: 15000,
			} );
			await expect(
				page.locator( '.vkbm-stay-calendar [aria-pressed="true"]' )
			).toHaveCount( 0 );
		} );

		test( 'メニュー一覧の取得完了を待たずに「予約に進む」を押しても、日程選択に入力済みで開く', async ( {
			page,
		} ) => {
			// 予約画面がメニュー種別（泊のプランか）を判定する元の REST（/wp/v2/vkbm_service_menu）の応答を、
			// 「予約に進む」を押すまで保留し、種別が未確定の状態で押せるようにする。
			// 種別が未確定のあいだは、引き継いだ日程を捨ててはいけない。
			let releaseMenus: () => void = () => {};
			const menusGate = new Promise< void >( ( resolve ) => {
				releaseMenus = resolve;
			} );
			await page.route(
				'**/wp/v2/vkbm_service_menu**',
				async ( route ) => {
					await menusGate;
					await route.continue();
				}
			);
			const { checkIn, checkOut } = tokyoStayInOneMonth( 3, 2 );
			await page.goto(
				`/booking/?menu_id=${ nightMenuId }&vkbm_view=detail&date=${ checkIn }&check_out_date=${ checkOut }`
			);
			// 詳細表示が出た時点で（メニュー種別の応答が未着のまま）押し、押した直後に応答を返す。
			const reserve = page.locator(
				'.vkbm-reservation-detail__card a.vkbm-menu-loop__button--reserve'
			);
			await reserve.click( { timeout: 15000 } );
			releaseMenus();
			await expect( page.locator( '.vkbm-lodging' ) ).toBeVisible( {
				timeout: 20000,
			} );
			await expect(
				page.locator( '.vkbm-stay-calendar [aria-pressed="true"]' )
			).toHaveCount( 2, { timeout: 15000 } );
		} );

		test( '予約できない日程・過去の日程は未入力に戻り、日程選択の直上に案内が出る', async ( {
			page,
		} ) => {
			// シフトの無い遠い日程。
			const farIn = tokyoIsoAfter( 90 );
			const farOut = tokyoIsoAfter( 92 );
			await page.goto(
				`/booking/?menu_id=${ nightMenuId }&vkbm_view=detail&date=${ farIn }&check_out_date=${ farOut }`
			);
			await clickReserveInDetail( page );
			const notice = page.locator(
				'.vkbm-lodging__carry-notice[role="status"]'
			);
			await expect( notice.locator( 'p' ) ).toHaveCount( 1, {
				timeout: 15000,
			} );
			await expect( notice ).toContainText( '予約できません' );
			await expect(
				page.locator( '.vkbm-stay-calendar [aria-pressed="true"]' )
			).toHaveCount( 0 );

			// 過去の日程。
			await page.goto(
				`/booking/?menu_id=${ nightMenuId }&vkbm_view=detail&date=${ tokyoIsoAfter(
					-5
				) }&check_out_date=${ tokyoIsoAfter( -3 ) }`
			);
			await clickReserveInDetail( page );
			await expect( notice.locator( 'p' ) ).toHaveCount( 1, {
				timeout: 15000,
			} );
			await expect( notice ).toContainText( '過ぎています' );
		} );

		test( 'チェックアウト日が無く date だけの URL は、リンクに日付を付けず、日程にも引き継がない', async ( {
			page,
		} ) => {
			const iso = tokyoIsoAfter( 3 );
			await page.goto( `/booking/?date=${ iso }` );
			const detailLink = page.locator(
				`.vkbm-menu-loop__item[data-menu-id="${ nightMenuId }"] a.vkbm-button__primary`
			);
			await expect( detailLink ).toBeVisible( { timeout: 15000 } );
			const href = new URL(
				( await detailLink.getAttribute( 'href' ) ) || ''
			);
			expect( href.searchParams.has( 'date' ) ).toBe( false );

			// 予約ブロックに直接 menu_id と date だけを渡しても、宿泊の日程は入らない。
			await page.goto(
				`/booking/?menu_id=${ nightMenuId }&date=${ iso }`
			);
			await expect( page.locator( '.vkbm-lodging' ) ).toBeVisible( {
				timeout: 15000,
			} );
			await expect(
				page.locator( '.vkbm-stay-calendar [aria-pressed="true"]' )
			).toHaveCount( 0 );
		} );
	} );
} );
