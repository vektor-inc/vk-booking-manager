// @category 予約の確定・確認・キャンセル
import { test, expect, Page } from '@playwright/test';
import {
	wpCliArgs,
	wpEvalPhp,
	loginAsAdmin,
	getServiceMenuId,
} from '../utils/helpers';
import { configureProviderSettings } from '../utils/setup';
import { checkConfirmConsents } from '../utils/consent-helpers';

const WP_BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8889';

// 上限に達したときのサーバーの文言（日本語ロケール）。件数は設定値が入る。
const LIMIT_MESSAGE = '同時にお取りいただける予約は3件までです。';

/**
 * Issue #971: お客様1人あたりの予約数の上限。
 *
 * 検証内容:
 * - 設定欄: 「システム設定」タブの「予約可能な最大日数」の直下に欄が出て、保存した値が残る。空欄は 0（上限なし）になる。
 * - 会員の予約: 予約数が上限ちょうどの会員は、「予約に進む」の直後に文言とリンクが出る。
 *   上限の手前で確認画面まで進み、その間に上限へ達した場合は、確定ボタンの付近に文言とリンクが出る。
 */
// @title Issue #971: お客様1人あたりの予約数の上限
test.describe( 'Issue #971: customer booking limit', () => {
	// 設定を共有して書き換えるため、1本ずつ順に動かす。
	test.describe.configure( { mode: 'serial' } );

	const password = 'TestPassword123!';
	// グローバルセットアップの user_* クリーンアップ対象に合わせた接頭辞を使う。
	const username = `user_booking_limit_${ Date.now() }`;
	let userId = 0;

	/**
	 * 会員の「これから先の確定の予約」を指定件数つくる。
	 *
	 * @param count 件数。
	 */
	const seedUpcomingBookings = ( count: number ) => {
		wpEvalPhp(
			`for ( $i = 0; $i < ${ Number( count ) }; $i++ ) {` +
				'$id = wp_insert_post( array( "post_type" => "vkbm_booking", "post_status" => "publish", "post_author" => ' +
				`${ Number( userId ) }, "post_title" => "e2e limit" ) );` +
				'$s = strtotime( "+" . ( 40 + $i ) . " days 03:00:00 Asia/Tokyo" );' +
				'update_post_meta( $id, "_vkbm_booking_service_start", wp_date( "Y-m-d H:i:s", $s ) );' +
				'update_post_meta( $id, "_vkbm_booking_service_end", wp_date( "Y-m-d H:i:s", $s + 3600 ) );' +
				'update_post_meta( $id, "_vkbm_booking_total_end", wp_date( "Y-m-d H:i:s", $s + 3600 ) );' +
				'update_post_meta( $id, "_vkbm_booking_status", "confirmed" );' +
				'}'
		);
	};

	/**
	 * 会員の予約をすべて削除する。
	 */
	const deleteBookings = () => {
		wpEvalPhp(
			`$ids = get_posts( array( "post_type" => "vkbm_booking", "author" => ${ Number(
				userId
			) }, "post_status" => "any", "numberposts" => -1, "fields" => "ids" ) );` +
				'foreach ( $ids as $id ) { wp_delete_post( $id, true ); }'
		);
	};

	/**
	 * 会員の予約数（キャンセルを除く）を返す。
	 */
	const countBookings = (): number =>
		Number(
			wpEvalPhp(
				`$ids = get_posts( array( "post_type" => "vkbm_booking", "author" => ${ Number(
					userId
				) }, "post_status" => "any", "numberposts" => -1, "fields" => "ids" ) );` +
					'echo count( $ids );'
			)
		);

	test.beforeAll( async () => {
		// @step 管理画面で一般会員（購読者）のユーザーを作っておく
		userId = Number(
			wpCliArgs( [
				'user',
				'create',
				username,
				`${ username }@example.com`,
				'--role=subscriber',
				`--user_pass=${ password }`,
				'--porcelain',
			] )
		);
	} );

	test.afterAll( async () => {
		deleteBookings();
		wpEvalPhp(
			`$u = get_user_by( "login", "${ username }" );` +
				'if ( $u ) { require_once ABSPATH . "wp-admin/includes/user.php"; wp_delete_user( $u->ID ); }'
		);
		// 他の spec が上限で止まらないよう、グローバルセットアップと同じ「上限なし」へ戻す。
		await configureProviderSettings( {
			provider_customer_booking_limit: 0,
		} );
	} );

	// 各テストは独自にログインするため、保存済みのログイン状態は使わない。
	test.use( { storageState: { cookies: [], origins: [] } } );

	/**
	 * wp-login.php 経由で一般会員としてログインする。
	 *
	 * @param page Playwright ページ。
	 */
	const loginAsMember = async ( page: Page ) => {
		await page.goto( `${ WP_BASE_URL }/wp-login.php` );
		await page.locator( '#user_login' ).fill( username );
		await page.locator( '#user_pass' ).fill( password );
		await page.locator( '#wp-submit' ).click();
		await page.waitForLoadState( 'networkidle' );
	};

	/**
	 * 予約フォームでメニュー→日付→枠を選ぶ（「予約に進む」は押さない）。
	 *
	 * @param page Playwright ページ。
	 */
	const selectFirstSlot = async ( page: Page ) => {
		await page.goto( `${ WP_BASE_URL }/booking/` );
		await page.waitForLoadState( 'networkidle' );

		const menuId = getServiceMenuId();
		const reserveButton = page.locator(
			`.vkbm-menu-loop__item[data-menu-id="${ menuId }"] .vkbm-menu-loop__button--reserve`
		);
		if ( await reserveButton.count() ) {
			await reserveButton.first().click();
		} else {
			await page.locator( '.vkbm-service-menu-card' ).first().click();
		}

		await page.waitForSelector( '.vkbm-calendar', {
			state: 'visible',
			timeout: 10000,
		} );
		const availableDays = page.locator(
			'.vkbm-calendar__day--available:not(:disabled)'
		);
		// 月末に実行すると今月に残りの日が無いため、空きの日が見つからなければ翌月へ進む。
		const hasAvailableDay = async ( timeout: number ) => {
			try {
				await expect
					.poll( async () => availableDays.count(), { timeout } )
					.toBeGreaterThan( 0 );
				return true;
			} catch ( e ) {
				return false;
			}
		};
		if ( ! ( await hasAvailableDay( 8000 ) ) ) {
			await page.locator( '.vkbm-calendar__nav' ).nth( 1 ).click();
		}
		await expect
			.poll( async () => availableDays.count(), { timeout: 15000 } )
			.toBeGreaterThan( 0 );
		await availableDays.first().click();

		const slots = page.locator( '.vkbm-slot-list__item' );
		await expect
			.poll( async () => slots.count(), { timeout: 10000 } )
			.toBeGreaterThan( 0 );
		await slots.first().click();
	};

	// @title 設定欄: 「予約可能な最大日数」の直下に出て、保存した値が残る
	test( 'settings field is shown under the max advance period and saved', async ( {
		page,
	} ) => {
		// @step この項目がまだ保存されていない状態にする
		wpEvalPhp(
			'$s = get_option( "vkbm_provider_settings", array() );' +
				'unset( $s["provider_customer_booking_limit"] );' +
				'update_option( "vkbm_provider_settings", $s );'
		);

		// @step 管理者として「BM 設定」の「システム設定」タブを開く
		await loginAsAdmin( page );
		await page.goto(
			'/wp-admin/admin.php?page=vkbm-provider-settings&tab=system'
		);

		// @check 「お客様1人あたりの予約数の上限」の欄が表示され、初期値は 3
		const input = page.locator( '#vkbm-provider-customer-booking-limit' );
		await expect( input ).toBeVisible( { timeout: 10000 } );
		await expect( input ).toHaveValue( '3' );
		await expect(
			page.locator( 'label[for="vkbm-provider-customer-booking-limit"]' )
		).toHaveText( 'お客様1人あたりの予約数の上限' );

		// @check 「予約可能な最大日数」の行のすぐ下に並んでいる
		const nextRowInput = page
			.locator( 'tr' )
			.filter( {
				has: page.locator( '#vkbm-provider-max-advance-booking-days' ),
			} )
			.locator( '+ tr' )
			.locator( '#vkbm-provider-customer-booking-limit' );
		await expect( nextRowInput ).toHaveCount( 1 );

		// @step 5 を入れて保存し、「システム設定」タブを開き直す
		await input.fill( '5' );
		await page.locator( 'input[type="submit"]' ).last().click();
		await page.waitForLoadState( 'networkidle' );
		await page.goto(
			'/wp-admin/admin.php?page=vkbm-provider-settings&tab=system'
		);

		// @check 5 のまま残っている
		await expect( input ).toHaveValue( '5' );

		// @step 空欄にして保存し、開き直す
		await input.fill( '' );
		await page.locator( 'input[type="submit"]' ).last().click();
		await page.waitForLoadState( 'networkidle' );
		await page.goto(
			'/wp-admin/admin.php?page=vkbm-provider-settings&tab=system'
		);

		// @check 空欄は 0（上限なし）として保存されている
		await expect( input ).toHaveValue( '0' );
	} );

	// @title 会員の予約: 上限ちょうどなら「予約に進む」の直後に文言とリンクが出る
	test( 'a member at the limit sees the message with a link right after pressing proceed', async ( {
		page,
	} ) => {
		// @step 上限を 3 にし、会員が先の予約を 3 件持っている状態にする
		await configureProviderSettings( {
			provider_customer_booking_limit: 3,
		} );
		deleteBookings();
		seedUpcomingBookings( 3 );

		// @step 会員としてログインし、予約フォームで枠を選んで「予約に進む」を押す
		await loginAsMember( page );
		await selectFirstSlot( page );
		const planAction = page.locator( '.vkbm-plan-summary__action' ).first();
		await expect( planAction ).toBeEnabled( { timeout: 10000 } );
		await planAction.click();

		// @check 確認画面へ進まず、ボタンの下に上限の文言と「予約内容確認」へのリンクが出る
		const error = page.locator( '.vkbm-plan-summary__error' );
		await expect( error ).toBeVisible( { timeout: 10000 } );
		await expect( error ).toContainText( LIMIT_MESSAGE );
		await expect( error.locator( 'a' ) ).toHaveAttribute(
			'href',
			/vkbm_auth=bookings/
		);
		await expect( page.locator( '.vkbm-confirm__summary' ) ).toHaveCount(
			0
		);
		expect( countBookings() ).toBe( 3 );
	} );

	// @title 会員の予約: 確認画面を開いている間に上限へ達したら、確定ボタンの付近に文言とリンクが出る
	test( 'a member who reaches the limit while on the confirmation screen sees the message near the confirm button', async ( {
		page,
	} ) => {
		// @step 上限を 3 にし、会員が先の予約を 2 件持っている状態にする
		await configureProviderSettings( {
			provider_customer_booking_limit: 3,
		} );
		deleteBookings();
		seedUpcomingBookings( 2 );

		// @step 会員としてログインし、予約フォームで枠を選んで確認画面まで進む
		await loginAsMember( page );
		await selectFirstSlot( page );
		const planAction = page.locator( '.vkbm-plan-summary__action' ).first();
		await expect( planAction ).toBeEnabled( { timeout: 10000 } );
		await planAction.click();
		await expect(
			page.locator( '.vkbm-confirm__summary' ).first()
		).toBeVisible( { timeout: 15000 } );
		await checkConfirmConsents( page );

		// @step 確認画面を開いたまま、別の画面で 3 件目の予約が入った状態にする
		seedUpcomingBookings( 1 );

		// @step 「この内容で予約する」を押す
		const confirmButton = page.locator( '.vkbm-confirm__button' );
		await expect( confirmButton ).toBeEnabled( { timeout: 10000 } );
		await confirmButton.click();

		// @check 確定ボタンの付近に上限の文言と「予約内容確認」へのリンクが出る
		const error = page.locator(
			'.vkbm-confirm__actions .vkbm-alert__danger'
		);
		await expect( error ).toBeVisible( { timeout: 10000 } );
		await expect( error ).toContainText( LIMIT_MESSAGE );
		await expect( error.locator( 'a' ) ).toHaveAttribute(
			'href',
			/vkbm_auth=bookings/
		);
		expect( countBookings() ).toBe( 3 );
	} );
} );
