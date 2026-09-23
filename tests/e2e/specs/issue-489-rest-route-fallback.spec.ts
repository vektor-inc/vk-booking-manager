import { test, expect } from '@playwright/test';

/**
 * Issue #489: パーマリンク設定はあるがサーバー側（.htaccess 等）に WordPress の
 * 書き換えルールが反映されていない環境では、/wp-json/ へのリクエストがサーバーの
 * 404 HTML を返し、apiFetch が invalid_json エラーになって予約ページが詰む
 * （ログインフォーム自体も REST /vkbm/v1/auth-form で描画されるため、ログインもできない）。
 *
 * この spec は `page.route( '**\/wp-json/**', ... )` で /wp-json/ 配下の全リクエストを
 * 「サーバーの 404 HTML」で置き換え、上記の壊れ方を再現した状態で、
 * - src/blocks/shared/rest-fallback.js の apiFetch ミドルウェアが
 *   ?rest_route= 形式のフォールバック用ルート（restFallbackRoot）へ自動で再試行し、
 * - メニュー一覧・ログインフォームのどちらも表示され、
 * - 同一ページ内で一度フォールバックに成功した後は、以降のリクエストが
 *   最初から ?rest_route= 形式で送られる
 * ことを確認する。
 *
 * 前提: playwright.config の baseURL（テスト用 wp-env）は
 * global-setup.ts で `wp rewrite structure /%postname%/ --hard` 済みのため、
 * window.vkbmReservationConfig.restRoot（/wp-json/ 形式）と restFallbackRoot
 * （?rest_route= 形式）が異なり、フォールバックミドルウェアが有効化される前提で書いている。
 *
 * 実行環境: playwright.config の baseURL（テスト用 wp-env）に対して実行する。
 * 絶対URLはハードコードせず page.goto には相対パスを渡す。
 */
test.describe( 'Issue #489: REST ?rest_route= fallback when /wp-json/ is unreachable', () => {
	test( '/wp-json/ が 404 HTML を返す環境でも、メニュー一覧とログインフォームが表示され、rest_route 形式で通信する', async ( {
		page,
	} ) => {
		// /wp-json/ 配下の全リクエストを、パーマリンク未反映サーバーと同じ
		// 「サーバー側の 404 HTML」で置き換える（apiFetch が invalid_json になる状態の再現）。
		await page.route( '**/wp-json/**', async ( route ) => {
			await route.fulfill( {
				status: 404,
				contentType: 'text/html',
				body: '<html><body>Not Found</body></html>',
			} );
		} );

		// 実際に発生したリクエストを記録し、rest_route 形式へのフォールバックが
		// 発生したことを検証する材料にする。
		const requestedUrls: string[] = [];
		page.on( 'request', ( request ) => {
			requestedUrls.push( request.url() );
		} );

		await page.goto( '/booking/' );
		await page.waitForLoadState( 'networkidle' );

		// メニュー一覧のカードが1件以上表示されていること
		// （/wp-json/ が全滅していても、フォールバックで /vkbm/v1/menu-loop 相当の
		// データが取得できていることの確認。global-setup が最低1件の
		// vkbm_service_menu を作成済み）。
		await expect( page.locator( '.vkbm-menu-loop__item' ) ).not.toHaveCount(
			0,
			{ timeout: 20000 }
		);

		// fetchCalendar（src/blocks/reservation/app.js）は menuId が 0 のときは
		// 通信しない（カレンダー自体が描画されない）ため、次月ボタンの確認に入る前に
		// メニューカードの予約ボタンを押してメニューを選ぶ必要がある
		// （global-setup.ts が作る /booking/ には data-default-menu-id が無く、
		// URL にも menu_id を付けていないため）。
		// issue-392-nomination-slot-capacity.spec.ts のメニュー選択手順に合わせる。
		const menuReserveButton = page
			.locator( '.vkbm-menu-loop__item .vkbm-menu-loop__button--reserve' )
			.first();
		await expect( menuReserveButton ).toBeVisible( { timeout: 20000 } );

		// メニュー選択直前までのURL件数を控えておき、メニュー選択で発生する
		// calendar-meta の取得も /wp-json/ を経由せずフォールバック済みで
		// 送られていることを確認する材料にする。
		const requestCountBeforeMenuSelect = requestedUrls.length;

		await Promise.all( [
			page.waitForResponse(
				( response ) => response.url().includes( 'calendar-meta' ),
				{ timeout: 15000 }
			),
			menuReserveButton.click(),
		] );

		const requestsAfterMenuSelect = requestedUrls.slice(
			requestCountBeforeMenuSelect
		);
		const menuSelectWpJsonAttempts = requestsAfterMenuSelect.filter(
			( url ) => url.includes( '/wp-json/' )
		);
		expect(
			menuSelectWpJsonAttempts.length,
			`メニュー選択後のリクエストで /wp-json/ への試行が発生している: ${ menuSelectWpJsonAttempts.join(
				', '
			) }`
		).toBe( 0 );

		// カレンダーの「次の月」ボタンによる確認は、ここ（ログイン操作より前）で
		// 必ず行う。queryDefaults.auth が未指定の初期表示では shouldShowReservation
		// が true になりカレンダー（CalendarGrid）が描画されるが、後段で「Log in」を
		// クリックして authMode が 'login' になると shouldShowReservation が false に
		// なりカレンダーごと消えるため、次月ボタンはこの時点でしか存在しない。
		const nextMonthButton = page
			.getByRole( 'button', { name: 'next month' } )
			.or( page.getByRole( 'button', { name: '次の月' } ) );
		await expect( nextMonthButton ).toBeVisible( { timeout: 20000 } );

		// 初回読み込みの一連のリクエストが収まった時点までのURL件数を控えておき、
		// 「同一ページ内で一度フォールバックに成功したら、以降は最初から rest_route
		// 形式で送る」ことを、追加のカレンダー再取得（次月ボタン）で確認する。
		const requestCountBeforeNextMonth = requestedUrls.length;

		// 次月ボタンをクリックして、/vkbm/v1/calendar-meta への追加リクエストを
		// 発生させる。クリック後の networkidle 待ちは、404 を返す /wp-json/ 側の
		// リクエストも「完了したレスポンス」として扱われてしまい実際の再取得完了を
		// 保証しないため使わず、calendar-meta を含むレスポンスを直接待つ。
		await Promise.all( [
			page.waitForResponse(
				( response ) => response.url().includes( 'calendar-meta' ),
				{ timeout: 15000 }
			),
			nextMonthButton.click(),
		] );

		const requestsAfterNextMonth = requestedUrls.slice(
			requestCountBeforeNextMonth
		);
		const nextMonthWpJsonAttempts = requestsAfterNextMonth.filter(
			( url ) => url.includes( '/wp-json/' )
		);
		const nextMonthFallbackRequests = requestsAfterNextMonth.filter(
			( url ) => url.includes( 'rest_route=' )
		);

		// 一度フォールバックに成功した後の2回目以降のリクエストでは、
		// /wp-json/ への（失敗する）試行を経由せず、最初から rest_route 形式で
		// 送られていることを確認する。
		expect(
			nextMonthWpJsonAttempts.length,
			`次月ボタン操作後のリクエストで /wp-json/ への試行が発生している: ${ nextMonthWpJsonAttempts.join(
				', '
			) }`
		).toBe( 0 );
		expect( nextMonthFallbackRequests.length ).toBeGreaterThan( 0 );

		// ?rest_route= 形式（コアの非パーマリンク分岐と同じ形。index.php?rest_route=/... ）
		// のリクエストが、ここまでの操作（初回読み込み・次月クリック）で
		// 実際に発生していること。
		const fallbackRequests = requestedUrls.filter( ( url ) =>
			url.includes( 'rest_route=' )
		);
		expect(
			fallbackRequests.length,
			`rest_route= 形式のリクエストが1件も発生していない。記録したURL: ${ requestedUrls
				.filter(
					( url ) =>
						url.includes( '/vkbm/v1/' ) ||
						url.includes( 'wp-json' ) ||
						url.includes( 'rest_route' )
				)
				.join( ', ' ) }`
		).toBeGreaterThan( 0 );

		// queryDefaults.auth（src/blocks/reservation/app.js）は URL の ?vkbm_auth=
		// からしか初期化されないため、ページを開いただけではログインフォームは
		// 表示されない。ナビの「Log in」ボタンをクリックして authMode を 'login' に
		// 切り替える（booking-flow-register.spec.ts のログインボタン操作に合わせる）。
		const loginNavButton = page
			.getByRole( 'button', { name: 'ログイン', exact: false } )
			.or( page.getByRole( 'button', { name: 'Log in', exact: false } ) );
		await expect( loginNavButton ).toBeVisible( { timeout: 20000 } );

		// 「Log in」クリック以降に発生するリクエスト（auth-form の取得を含む）が、
		// 既にフォールバックへ切り替え済みの状態で送られていることを検証するため、
		// クリック前の件数を控えておく。
		const requestCountBeforeLogin = requestedUrls.length;

		// クリック後の networkidle 待ちは何も保証しないため、
		// auth-form のレスポンスを直接待つ形にする。
		await Promise.all( [
			page.waitForResponse(
				( response ) => response.url().includes( 'auth-form' ),
				{ timeout: 15000 }
			),
			loginNavButton.click(),
		] );

		// ログインフォームが表示されていること（/vkbm/v1/auth-form のフォールバック確認）。
		// 「Log in」ボタンのクリックで authMode が 'login' になり、
		// フォームが取得できれば .vkbm-reservation-content__auth-form が描画される。
		await expect(
			page.locator( '.vkbm-reservation-content__auth-form' )
		).toBeVisible( { timeout: 20000 } );

		// 「Log in」クリック後のリクエスト（auth-form の取得を含む）で /wp-json/ への
		// 試行が発生していないこと。次月ボタンの確認と合わせて、フォールバックへ
		// 切り替え済みの状態でのリクエストであることの保証を強める。
		const requestsAfterLogin = requestedUrls.slice(
			requestCountBeforeLogin
		);
		const loginWpJsonAttempts = requestsAfterLogin.filter( ( url ) =>
			url.includes( '/wp-json/' )
		);
		expect(
			loginWpJsonAttempts.length,
			`「Log in」クリック後のリクエストで /wp-json/ への試行が発生している: ${ loginWpJsonAttempts.join(
				', '
			) }`
		).toBe( 0 );
	} );
} );
