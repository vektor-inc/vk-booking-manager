import { test, expect } from '@playwright/test';
import { wpEvalPhp, getServiceMenuId, getStaffId } from '../utils/helpers';

// 設定オプション名（Settings_Repository::OPTION_KEY）。
const OPTION_NAME = 'vkbm_provider_settings';

// メール送信を横取りして記録する mu-plugin の置き場所とオプション名。
const MAIL_CAPTURE_FILE = 'vkbm-e2e-mail-capture-962.php';
const MAIL_CAPTURE_OPTION = 'vkbm_e2e_captured_mails_962';

// このテストで作る予約のお客様のメールアドレス（メールの横取りで宛先を見分ける）。
const GUEST_EMAIL = 'guest-cancel-962@example.com';

/**
 * Issue #962: 会員登録なしで予約したお客様が、メールのリンクから自分で予約をキャンセルできる。
 *
 * 検証内容:
 * - お知らせメールのリンクを開くと、予約内容と「この予約をキャンセルする」ボタンが出る。開いただけではキャンセルされない。
 * - ボタンを押して初めてキャンセルされ、リンクはその後使えなくなる。
 * - 正しくないリンク・キャンセル不可の設定・締め切りを過ぎた予約では、ボタンを出さずに理由を伝える。
 * - 狭い画面幅（320px）でもボタンが入れ物からはみ出さない。
 */
// @title ゲスト予約のキャンセル用リンク（メールのリンクを開く→確認画面→ボタンでキャンセル）
test.describe( 'Issue #962: guest booking cancel link', () => {
	// 元の設定値（テスト終了時に復元する）。JSON 文字列で保持する。
	let originalSettingsJson = '';
	// テスト中に作った予約の ID（後片付け用）。
	const craftedBookingIds: number[] = [];

	/**
	 * 設定オプションの一部のキーだけを上書きする（他のキーは維持する）。
	 *
	 * @param overrides 上書きするキーと値。
	 */
	const updateSettings = (
		overrides: Record< string, string | number | boolean >
	) => {
		// 値は JSON → base64 で PHP へ渡し、PHP リテラル外への脱出を防ぐ。
		const encoded = Buffer.from( JSON.stringify( overrides ) ).toString(
			'base64'
		);
		wpEvalPhp(
			`$o = json_decode( base64_decode( "${ encoded }" ), true );` +
				`$s = get_option( "${ OPTION_NAME }", array() );` +
				'if ( ! is_array( $s ) ) { $s = array(); }' +
				`update_option( "${ OPTION_NAME }", array_merge( $s, $o ) );`
		);
	};

	/**
	 * 送信されたメールを横取りして記録する mu-plugin を入れる（テスト環境にはメールサーバーが無いため）。
	 */
	const installMailCapture = () => {
		const plugin =
			'<?php ' +
			'add_filter( "pre_wp_mail", function ( $pre, $atts ) {' +
			`$mails = get_option( "${ MAIL_CAPTURE_OPTION }", array() );` +
			'if ( ! is_array( $mails ) ) { $mails = array(); }' +
			'$mails[] = array( "to" => $atts["to"], "subject" => $atts["subject"], "message" => $atts["message"] );' +
			`update_option( "${ MAIL_CAPTURE_OPTION }", $mails, false );` +
			'return true;' +
			'}, 10, 2 );';
		const encoded = Buffer.from( plugin ).toString( 'base64' );
		wpEvalPhp(
			'wp_mkdir_p( WPMU_PLUGIN_DIR );' +
				`file_put_contents( WPMU_PLUGIN_DIR . "/${ MAIL_CAPTURE_FILE }", base64_decode( "${ encoded }" ) );` +
				`delete_option( "${ MAIL_CAPTURE_OPTION }" );`
		);
	};

	/**
	 * ゲスト予約を 1 件作る。
	 *
	 * @param startOffsetSeconds 現在から開始までの秒数。
	 * @param status             予約のステータス。
	 * @return 予約の投稿 ID。
	 */
	const craftGuestBooking = (
		startOffsetSeconds: number,
		status = 'confirmed'
	): number => {
		const menuId = Number( getServiceMenuId() );
		const staffId = Number( getStaffId() );
		const encodedStatus = Buffer.from( status ).toString( 'base64' );
		const id = Number(
			wpEvalPhp(
				`$status = base64_decode( "${ encodedStatus }" );` +
					`$start = time() + ( ${ startOffsetSeconds } );` +
					'echo wp_insert_post( array(' +
					'"post_type" => "vkbm_booking", "post_status" => "publish", "post_title" => "e2e guest cancel booking",' +
					'"meta_input" => array(' +
					`"_vkbm_booking_service_id" => ${ menuId },` +
					`"_vkbm_booking_resource_id" => ${ staffId },` +
					'"_vkbm_booking_is_staff_preferred" => "1",' +
					'"_vkbm_booking_guests" => 1,' +
					'"_vkbm_booking_service_start" => wp_date( "Y-m-d H:i:s", $start ),' +
					'"_vkbm_booking_service_end" => wp_date( "Y-m-d H:i:s", $start + 1800 ),' +
					'"_vkbm_booking_status" => $status,' +
					'"_vkbm_booking_customer_name" => "キャンセル 太郎",' +
					`"_vkbm_booking_customer_email" => "${ GUEST_EMAIL }",` +
					'"_vkbm_booking_source" => "guest"' +
					') ) );'
			)
		);
		craftedBookingIds.push( id );
		return id;
	};

	/**
	 * 予約にキャンセル用トークンを発行し、生のトークンを返す（メールを経由せずにリンクを作るため）。
	 *
	 * @param bookingId 予約の投稿 ID。
	 * @return 生のトークン。
	 */
	const issueToken = ( bookingId: number ): string => {
		const out = wpEvalPhp(
			`echo \\VKBookingManager\\Bookings\\Guest_Cancel_Controller::issue_token( ${ bookingId } );`
		);
		const matched = /[A-Za-z0-9]{32}/.exec( out );
		expect( matched, 'トークンが発行されること' ).toBeTruthy();
		return matched![ 0 ];
	};

	/**
	 * 予約のステータスを読む。
	 *
	 * @param bookingId 予約の投稿 ID。
	 * @return ステータス。
	 */
	const readStatus = ( bookingId: number ): string =>
		wpEvalPhp(
			`echo get_post_meta( ${ bookingId }, "_vkbm_booking_status", true );`
		).trim();

	/**
	 * 予約に残っているキャンセル用トークンのハッシュの件数を読む。
	 *
	 * @param bookingId 予約の投稿 ID。
	 * @return 件数。
	 */
	const countTokenHashes = ( bookingId: number ): number =>
		Number(
			wpEvalPhp(
				`echo count( get_post_meta( ${ bookingId }, "_vkbm_booking_guest_cancel_token_hash", false ) );`
			).trim()
		);

	/**
	 * 予約ページに付けて開く、キャンセル用リンクのパスとクエリを作る。
	 *
	 * @param token     トークン。
	 * @param bookingId 予約の投稿 ID。
	 * @return パスとクエリ。
	 */
	const cancelPath = ( token: string, bookingId: number ): string =>
		`/booking/?vkbm_guest_cancel=${ token }&booking=${ bookingId }`;

	test.beforeAll( async () => {
		// @step メール送信を記録する仕組みを入れ、キャンセルの設定を「開始の 24 時間前まで」、店舗の電話番号を決めた値にする
		originalSettingsJson = wpEvalPhp(
			`echo wp_json_encode( get_option( "${ OPTION_NAME }", array() ) );`
		);
		installMailCapture();
		updateSettings( {
			provider_booking_cancel_mode: 'hours',
			provider_booking_cancel_deadline_hours: 24,
			provider_phone: '03-1234-5678',
		} );
	} );

	test.afterAll( async () => {
		// 作った予約・メール記録を削除し、設定を元に戻す。
		const bookings = JSON.stringify( craftedBookingIds );
		wpEvalPhp(
			`$bookings = json_decode( base64_decode( "${ Buffer.from(
				bookings
			).toString( 'base64' ) }" ), true );` +
				'foreach ( $bookings as $b ) { wp_delete_post( (int) $b, true ); }' +
				`delete_option( "${ MAIL_CAPTURE_OPTION }" );` +
				`@unlink( WPMU_PLUGIN_DIR . "/${ MAIL_CAPTURE_FILE }" );`
		);
		const encoded = Buffer.from( originalSettingsJson || '[]' ).toString(
			'base64'
		);
		wpEvalPhp(
			`update_option( "${ OPTION_NAME }", json_decode( base64_decode( "${ encoded }" ), true ) );`
		);
	} );

	// お客様は未ログイン。
	test.use( { storageState: { cookies: [], origins: [] } } );

	// @title メールのリンクを開いても予約はキャンセルされず、ボタンを押して初めてキャンセルされる
	test( 'mail link shows a confirmation screen and cancels only after pressing the button', async ( {
		page,
	} ) => {
		// @step ゲスト予約を作り、確定のお知らせメールを送る（メールは記録するだけ）
		const bookingId = craftGuestBooking( 10 * 24 * 3600 );
		wpEvalPhp(
			'( new \\VKBookingManager\\Notifications\\Booking_Notification_Service( new \\VKBookingManager\\ProviderSettings\\Settings_Repository() ) )' +
				`->handle_confirmed_creation( ${ bookingId } );`
		);

		// @check お知らせメールに「キャンセルについて」の節とキャンセル用リンクがある
		const json = wpEvalPhp(
			`echo wp_json_encode( get_option( "${ MAIL_CAPTURE_OPTION }", array() ) );`
		);
		const mails = JSON.parse(
			json.slice( json.indexOf( '[' ) )
		) as Array< {
			to: string | string[];
			message: string;
		} >;
		const mail = mails
			.filter( ( m ) =>
				[]
					.concat( m.to as never )
					.map( String )
					.includes( GUEST_EMAIL )
			)
			.pop();
		expect( mail, 'お客様宛てのメールが記録されていること' ).toBeTruthy();
		expect( mail!.message ).toContain( '--- キャンセルについて ---' );
		const matched = new RegExp(
			`(\\S*)\\?vkbm_guest_cancel=([A-Za-z0-9]{32})&booking=${ bookingId }`
		).exec( mail!.message );
		expect(
			matched,
			`メールにキャンセル用リンクがあること: ${ mail!.message }`
		).toBeTruthy();
		const token = matched![ 2 ];

		// @step メールのリンクを開く
		const response = await page.goto( cancelPath( token, bookingId ) );

		// @check 結果は人ごとに違うので、キャッシュさせず、リンクのトークンを外部へ送らない設定で返ってくる
		expect( response?.headers()[ 'referrer-policy' ] ).toBe(
			'no-referrer'
		);
		expect( response?.headers()[ 'cache-control' ] ).toContain(
			'no-cache'
		);

		// @check キャンセルの確認画面が出て、予約内容とボタンがある
		const result = page.locator( '[data-vkbm-guest-cancel="can_cancel"]' );
		await expect( result ).toBeVisible( { timeout: 15000 } );
		await expect( result.locator( 'h3' ) ).toHaveText(
			'この予約をキャンセルしますか？'
		);
		await expect( result ).toContainText(
			'ボタンを押すと取り消せません。'
		);
		await expect( result ).toContainText(
			'キャンセルしない場合は、何も押さずにこのページを閉じてください。'
		);
		await expect(
			page.locator( '.vkbm-confirm__summary' ).first()
		).toBeVisible();
		const cancelButton = page.getByRole( 'button', {
			name: 'この予約をキャンセルする',
		} );
		await expect( cancelButton ).toBeVisible();

		// @check 開いただけでは予約はキャンセルされず、アドレスバーからトークンが外れている
		await page.waitForLoadState( 'networkidle' );
		expect( readStatus( bookingId ) ).toBe( 'confirmed' );
		expect( page.url() ).not.toContain( 'vkbm_guest_cancel' );
		// トークンはページの先頭のスクリプトが URL から外して退避し、画面はそこから読む（アクセス解析タグに URL ごと渡さないため）。
		expect(
			await page.evaluate( () =>
				Object.keys( ( window as any ).vkbmGuestLinkParams || {} )
			)
		).toContain( 'vkbm_guest_cancel' );
		expect(
			await page
				.locator( 'head > script' )
				.evaluateAll( ( els ) =>
					els.findIndex(
						( el ) => el.id === 'vkbm-guest-link-scrubber'
					)
				)
		).toBe( 0 );
		expect( countTokenHashes( bookingId ) ).toBeGreaterThan( 0 );

		// @step 「この予約をキャンセルする」ボタンを押す
		await cancelButton.click();

		// @check キャンセル完了の表示になり、予約がキャンセルされ、リンク用の記録も消える
		const done = page.locator( '[data-vkbm-guest-cancel="done"]' );
		await expect( done ).toBeVisible( { timeout: 15000 } );
		await expect( done.locator( 'h3' ) ).toHaveText(
			'ご予約をキャンセルしました'
		);
		await expect( cancelButton ).toHaveCount( 0 );
		expect( readStatus( bookingId ) ).toBe( 'cancelled' );
		expect( countTokenHashes( bookingId ) ).toBe( 0 );

		// @step 同じリンクをもう一度開く
		await page.goto( cancelPath( token, bookingId ) );

		// @check 使えないリンクとして案内され、ボタンは出ない
		const invalid = page.locator( '[data-vkbm-guest-cancel="invalid"]' );
		await expect( invalid ).toBeVisible( { timeout: 15000 } );
		await expect( invalid.locator( 'h3' ) ).toHaveText(
			'このキャンセル用リンクは使えません'
		);
		await expect(
			page.getByRole( 'button', { name: 'この予約をキャンセルする' } )
		).toHaveCount( 0 );
	} );

	// @title 正しくないリンクでは、ボタンを出さず予約ページへ案内する
	test( 'invalid token shows the invalid link screen', async ( { page } ) => {
		// @step 実在する予約の ID に、間違ったトークンを付けて開く
		const bookingId = craftGuestBooking( 10 * 24 * 3600 );
		issueToken( bookingId );
		await page.goto( cancelPath( 'a'.repeat( 32 ), bookingId ) );

		// @check 「正しくありません」の案内になり、予約内容もキャンセルボタンも出ない。予約はそのまま
		const invalid = page.locator( '[data-vkbm-guest-cancel="invalid"]' );
		await expect( invalid ).toBeVisible( { timeout: 15000 } );
		await expect( invalid.locator( 'h3' ) ).toHaveText(
			'このキャンセル用リンクは使えません'
		);
		await expect( page.locator( '.vkbm-confirm__summary' ) ).toHaveCount(
			0
		);
		await expect(
			page.getByRole( 'button', { name: 'この予約をキャンセルする' } )
		).toHaveCount( 0 );
		await expect(
			page.getByRole( 'link', { name: '予約ページへ' } )
		).toBeVisible();
		expect( readStatus( bookingId ) ).toBe( 'confirmed' );
	} );

	// @title キャンセル不可の設定・締め切りを過ぎた予約では、ボタンを出さず電話での連絡を案内する
	test( 'cancellation not allowed shows a phone guidance without the button', async ( {
		page,
	} ) => {
		const cases = [
			{
				name: '設定が「キャンセル不可」',
				settings: { provider_booking_cancel_mode: 'none' },
				offset: 10 * 24 * 3600,
				message: 'この予約はウェブからキャンセルできません。',
			},
			{
				name: '締め切り（24 時間前）を過ぎた予約',
				settings: { provider_booking_cancel_mode: 'hours' },
				offset: 2 * 3600,
				message: 'キャンセル可能期限を過ぎています。',
			},
		];

		for ( const c of cases ) {
			// @step 設定と予約を用意して、キャンセル用リンクを開く
			updateSettings( c.settings );
			const bookingId = craftGuestBooking( c.offset );
			const token = issueToken( bookingId );
			await page.goto( cancelPath( token, bookingId ) );

			// @check キャンセルできない理由（マイページと同じ文言）と店舗の電話番号を案内し、ボタンは出ない。予約はそのまま
			const result = page.locator( '.vkbm-confirm__guest-result' );
			await expect( result ).toBeVisible( { timeout: 15000 } );
			await expect( result.locator( 'h3' ) ).toHaveText(
				'このご予約は、このページからはキャンセルできません',
				{ useInnerText: true }
			);
			await expect( result ).toContainText( c.message );
			await expect( result ).toContainText( 'お電話でご連絡ください。' );
			// 電話番号は文と別の段落に、タップできるリンクで出す。
			await expect(
				result.locator( 'a[href="tel:0312345678"]' )
			).toHaveText( 'TEL: 03-1234-5678' );
			await expect(
				page.getByRole( 'button', {
					name: 'この予約をキャンセルする',
				} ),
				c.name
			).toHaveCount( 0 );
			expect( readStatus( bookingId ), c.name ).toBe( 'confirmed' );
		}

		// 後続のテストのため、設定を「開始の 24 時間前まで」へ戻す。
		updateSettings( { provider_booking_cancel_mode: 'hours' } );
	} );

	// @title ボタンを押した後に状態が変わったら、フォーカスが結果の枠へ移る
	test( 'focus moves to the result box when the state changes after pressing the button', async ( {
		page,
	} ) => {
		// @step キャンセルの確認画面を開き、押す前に設定を「キャンセル不可」へ変える
		updateSettings( { provider_booking_cancel_mode: 'hours' } );
		const bookingId = craftGuestBooking( 10 * 24 * 3600 );
		const token = issueToken( bookingId );
		await page.goto( cancelPath( token, bookingId ) );
		const cancelButton = page.getByRole( 'button', {
			name: 'この予約をキャンセルする',
		} );
		await expect( cancelButton ).toBeVisible( { timeout: 15000 } );
		updateSettings( { provider_booking_cancel_mode: 'none' } );

		// @step ボタンを押す
		await cancelButton.click();

		// @check 「キャンセルできません」の画面に切り替わり、フォーカスが結果の枠にある。予約はそのまま
		const result = page.locator(
			'[data-vkbm-guest-cancel="cancel_disabled"]'
		);
		await expect( result ).toBeVisible( { timeout: 15000 } );
		await expect( result ).toBeFocused();
		expect( readStatus( bookingId ) ).toBe( 'confirmed' );

		// 後続のテストのため、設定を戻す。
		updateSettings( { provider_booking_cancel_mode: 'hours' } );
	} );

	// @title 狭い画面幅（320px）でも、キャンセルボタンが入れ物からはみ出さず、押しやすい高さがある
	test( 'cancel button fits the narrowest width', async ( { page } ) => {
		// @step 画面幅を 320px にして、キャンセル用リンクを開く
		await page.setViewportSize( { width: 320, height: 800 } );
		const bookingId = craftGuestBooking( 10 * 24 * 3600 );
		const token = issueToken( bookingId );
		await page.goto( cancelPath( token, bookingId ) );
		const button = page.getByRole( 'button', {
			name: 'この予約をキャンセルする',
		} );
		await expect( button ).toBeVisible( { timeout: 15000 } );

		// @check キャンセル画面の外枠・ボタンの入れ物・ボタンが 320px の画面幅に収まり、ボタンの高さが 44px 以上ある
		// ページ全体の横幅（document.scrollWidth）はテーマのヘッダーなど外側の要素にも左右されるため、
		// このプラグインが出す画面の入れ物だけで判定する。
		const metrics = await page.evaluate( () => {
			const viewport = document.documentElement.clientWidth;
			const btn = Array.from(
				document.querySelectorAll( 'button' )
			).find(
				( el ) => el.textContent?.includes( 'この予約をキャンセルする' )
			);
			const frame = document.querySelector( '[data-vkbm-guest-cancel]' );
			const actions = document.querySelector(
				'.vkbm-confirm__guest-actions'
			);
			const box = ( el: Element | null | undefined ) => {
				const r = el ? el.getBoundingClientRect() : null;
				return {
					found: !! el,
					left: r ? r.left : -1,
					right: r ? r.right : -1,
					height: r ? r.height : 0,
					overflowX: el ? el.scrollWidth - el.clientWidth : 0,
				};
			};
			return {
				viewport,
				button: box( btn ),
				frame: box( frame ),
				actions: box( actions ),
			};
		} );
		for ( const name of [ 'button', 'frame', 'actions' ] as const ) {
			expect( metrics[ name ].found ).toBe( true );
			expect( metrics[ name ].left ).toBeGreaterThanOrEqual( 0 );
			expect( metrics[ name ].right ).toBeLessThanOrEqual(
				metrics.viewport
			);
			// 入れ物の中身が入れ物からはみ出して横に伸びていない。
			expect( metrics[ name ].overflowX ).toBeLessThanOrEqual( 0 );
		}
		expect( metrics.button.height ).toBeGreaterThanOrEqual( 44 );
	} );
} );
