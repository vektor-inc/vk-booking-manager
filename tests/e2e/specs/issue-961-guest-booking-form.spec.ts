import { test, expect, Page } from '@playwright/test';
import { randomBytes } from 'crypto';
import {
	wpCliArgs,
	wpEvalPhp,
	getServiceMenuId,
	getStaffId,
} from '../utils/helpers';
import { checkConfirmConsents } from '../utils/consent-helpers';

// 設定オプション名（Settings_Repository::OPTION_KEY）。
const OPTION_NAME = 'vkbm_provider_settings';

// メール送信を横取りして記録する mu-plugin の置き場所とオプション名。
const MAIL_CAPTURE_FILE = 'vkbm-e2e-mail-capture.php';
const MAIL_CAPTURE_OPTION = 'vkbm_e2e_captured_mails';

/**
 * Issue #961: 予約画面（お客様側）の「会員登録せずに予約する」入力欄と、確認リンクを開いた後の結果画面。
 *
 * 検証内容:
 * - 受付方法ごとの表示（会員のみ・ゲストのみ・どちらでも）。「会員のみ」では今までの画面のまま。
 * - 必須入力のエラー表示と、ログイン・新規登録へ切り替えても入力が残ること。
 * - 送信後の画面（案内色・宛先・有効期限）と、メールアドレスを直して送り直す流れ。
 * - 確認リンクを開いた後の結果画面（確定・開き直し・仮予約・各種の注意・予約できなかった）。
 */
// @title 予約画面の「会員登録せずに予約する」入力欄と確認リンクの結果画面
test.describe( 'Issue #961: guest booking form on the reservation screen', () => {
	const password = 'TestPassword123!';
	// グローバルセットアップの user_* クリーンアップ対象に合わせた接頭辞を使う。
	const memberUsername = `user_guest_form_${ Date.now() }`;

	// 元の設定値（テスト終了時に復元する）。JSON 文字列で保持する。
	let originalSettingsJson = '';
	// テスト中に作った、確認リンクのトークン（後片付け用）。
	const craftedTokens: string[] = [];
	// テスト中に作った、結果画面表示用の予約の ID（後片付け用）。
	const craftedBookingIds: number[] = [];
	// メールアドレスを重複させないための通し番号。
	let emailSerial = 0;

	/**
	 * 設定オプションの一部のキーだけを上書きする（他のキーは維持する）。
	 *
	 * @param overrides 上書きするキーと値。
	 */
	const updateSettings = (
		overrides: Record< string, string | boolean >
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
	 * 予約の受付方法を切り替える。
	 *
	 * @param mode member / guest / both。
	 */
	const setCustomerMode = ( mode: 'member' | 'guest' | 'both' ) => {
		updateSettings( { booking_customer_mode: mode } );
	};

	/**
	 * 他のテストと重ならないメールアドレスを作る（同じアドレスへは 60 秒に 1 回の制限があるため）。
	 *
	 * @return メールアドレス。
	 */
	const newGuestEmail = (): string => {
		emailSerial += 1;
		return `guest-${ Date.now() }-${ emailSerial }@example.com`;
	};

	/**
	 * 確認リンク用の、32 文字の英数字のトークンを作って後片付けの対象に加える。
	 *
	 * @return トークン。
	 */
	const newToken = (): string => {
		const token = randomBytes( 16 ).toString( 'hex' );
		craftedTokens.push( token );
		return token;
	};

	/**
	 * 確認リンクの処理結果の記録（使用済みのリンク）を作る。実際に予約を作らずに、結果画面だけを確かめるため。
	 *
	 * @param token  確認リンクのトークン。
	 * @param record 結果の記録（outcome など）。
	 */
	const craftUsedRecord = (
		token: string,
		record: Record< string, string | number >
	) => {
		const encoded = Buffer.from( JSON.stringify( record ) ).toString(
			'base64'
		);
		wpEvalPhp(
			`$r = json_decode( base64_decode( "${ encoded }" ), true );` +
				`set_transient( "vkbm_guest_verified_" . hash( "sha256", "${ token }" ), $r, 3600 );`
		);
	};

	/**
	 * 結果画面の表示確認用に、予約データを 1 件作る。
	 *
	 * @param status 予約のステータス（confirmed / pending / cancelled）。
	 * @return 予約の投稿 ID。
	 */
	const craftBooking = ( status: string ): number => {
		const menuId = Number( getServiceMenuId() );
		const staffId = Number( getStaffId() );
		const encodedStatus = Buffer.from( status ).toString( 'base64' );
		const id = Number(
			wpEvalPhp(
				`$status = base64_decode( "${ encodedStatus }" );` +
					'echo wp_insert_post( array(' +
					'"post_type" => "vkbm_booking", "post_status" => "publish", "post_title" => "e2e guest result booking",' +
					'"meta_input" => array(' +
					`"_vkbm_booking_service_id" => ${ menuId },` +
					`"_vkbm_booking_resource_id" => ${ staffId },` +
					'"_vkbm_booking_is_staff_preferred" => "1",' +
					'"_vkbm_booking_guests" => 2,' +
					'"_vkbm_booking_service_start" => "2030-01-15 10:00:00",' +
					'"_vkbm_booking_service_end" => "2030-01-15 10:30:00",' +
					'"_vkbm_booking_status" => $status,' +
					'"_vkbm_booking_source" => "guest"' +
					') ) );'
			)
		);
		craftedBookingIds.push( id );
		return id;
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
	 * 横取りしたメールのうち、指定の宛先に送られた最新の確認メールから、確認リンクを取り出す。
	 *
	 * @param email 宛先のメールアドレス。
	 * @return 確認リンクのパスとクエリ（ホスト名は含めない）。
	 */
	const readVerifyLink = ( email: string ): string => {
		const json = wpEvalPhp(
			`echo wp_json_encode( get_option( "${ MAIL_CAPTURE_OPTION }", array() ) );`
		);
		const mails = JSON.parse(
			json.slice( json.indexOf( '[' ) )
		) as Array< { to: string | string[]; message: string } >;
		const mail = mails
			.filter( ( m ) =>
				[]
					.concat( m.to as never )
					.map( String )
					.includes( email )
			)
			.pop();
		expect(
			mail,
			`${ email } 宛ての確認メールが記録されていること`
		).toBeTruthy();
		const matched = /vkbm_guest_verify=([A-Za-z0-9]{32})/.exec(
			mail!.message
		);
		expect( matched, '確認メールにリンクが含まれること' ).toBeTruthy();
		craftedTokens.push( matched![ 1 ] );
		return `/booking/?vkbm_guest_verify=${ matched![ 1 ] }`;
	};

	test.beforeAll( async () => {
		// @step メール送信を記録する仕組みと、会員のテスト用ユーザーを用意し、予約ステータスを即時確定にする
		// 元の設定値を控える（未保存の場合は空配列）。
		originalSettingsJson = wpEvalPhp(
			`echo wp_json_encode( get_option( "${ OPTION_NAME }", array() ) );`
		);
		installMailCapture();

		// 会員として予約するお客様を作成する。
		wpCliArgs( [
			'user',
			'create',
			memberUsername,
			`${ memberUsername }@example.com`,
			'--role=subscriber',
			`--user_pass=${ password }`,
			'--porcelain',
		] );
		// 即時確定にして、確定の結果画面を確かめられるようにする。
		// 申し込みの回数制限（IP ごと）で、繰り返し実行したテストが止まらないよう無効にする。
		updateSettings( {
			provider_booking_status_mode: 'confirmed',
			auth_rate_limit_enabled: false,
		} );
	} );

	test.afterAll( async () => {
		// 作った予約・確認リンクの記録・メール記録・テスト用ユーザーを削除する。
		const tokens = JSON.stringify( craftedTokens );
		const bookings = JSON.stringify( craftedBookingIds );
		wpEvalPhp(
			`$tokens = json_decode( base64_decode( "${ Buffer.from(
				tokens
			).toString( 'base64' ) }" ), true );` +
				`$bookings = json_decode( base64_decode( "${ Buffer.from(
					bookings
				).toString( 'base64' ) }" ), true );` +
				'foreach ( $tokens as $t ) {' +
				'$h = hash( "sha256", $t );' +
				'delete_transient( "vkbm_guest_verify_" . $h );' +
				'delete_transient( "vkbm_guest_verified_" . $h );' +
				'delete_option( "vkbm_guest_verify_lock_" . $h );' +
				'}' +
				'foreach ( $bookings as $b ) { wp_delete_post( (int) $b, true ); }' +
				// このテストの確認メールから作られた予約も片付ける。
				'$ids = get_posts( array( "post_type" => "vkbm_booking", "post_status" => "any", "numberposts" => -1, "fields" => "ids", "meta_key" => "_vkbm_booking_source", "meta_value" => "guest" ) );' +
				'foreach ( $ids as $id ) { wp_delete_post( $id, true ); }' +
				`delete_option( "${ MAIL_CAPTURE_OPTION }" );` +
				`@unlink( WPMU_PLUGIN_DIR . "/${ MAIL_CAPTURE_FILE }" );` +
				`$u = get_user_by( "login", "${ memberUsername }" );` +
				'if ( $u ) {' +
				'require_once ABSPATH . "wp-admin/includes/user.php";' +
				'wp_delete_user( $u->ID );' +
				'}'
		);

		// 元の設定値へ戻す。
		const encoded = Buffer.from( originalSettingsJson || '[]' ).toString(
			'base64'
		);
		wpEvalPhp(
			`update_option( "${ OPTION_NAME }", json_decode( base64_decode( "${ encoded }" ), true ) );`
		);
	} );

	// 各テストは未ログインから始める（会員のテストだけ、中でログインする）。
	test.use( { storageState: { cookies: [], origins: [] } } );

	/**
	 * 予約ページでメニュー・日付・枠を選び、予約確認画面（?draft=…）まで進む。ログインはしない。
	 *
	 * @param page Playwright ページ。
	 */
	const gotoConfirmScreen = async ( page: Page ) => {
		await page.goto( '/booking/' );
		await page.waitForLoadState( 'networkidle' );

		// メニュー選択（data-menu-id で「Service Menu 1」を指定する）。
		const menuId = getServiceMenuId();
		await page
			.locator(
				`.vkbm-menu-loop__item[data-menu-id="${ menuId }"] .vkbm-menu-loop__button--reserve`
			)
			.first()
			.click();

		// 空き枠ロード完了後にのみ付与される「空きあり」日を選ぶ。
		await page.waitForSelector( '.vkbm-calendar', {
			state: 'visible',
			timeout: 10000,
		} );
		const availableDays = page.locator(
			'.vkbm-calendar__day--available:not(:disabled)'
		);
		await expect
			.poll( async () => availableDays.count(), { timeout: 15000 } )
			.toBeGreaterThan( 0 );
		await availableDays.first().click();

		// 時間枠を選んで予約確認画面へ進む。
		const slots = page.locator( '.vkbm-slot-list__item' );
		await expect
			.poll( async () => slots.count(), { timeout: 10000 } )
			.toBeGreaterThan( 0 );
		await slots.first().click();
		const planAction = page.locator( '.vkbm-plan-summary__action' ).first();
		await expect( planAction ).toBeEnabled( { timeout: 10000 } );
		await planAction.click();
		await expect(
			page.locator( '.vkbm-confirm__summary' ).first()
		).toBeVisible( { timeout: 15000 } );
	};

	/**
	 * お客様情報欄に入力する。
	 *
	 * @param page  Playwright ページ。
	 * @param name  予約者のお名前。
	 * @param email メールアドレス。
	 * @param phone 電話番号。
	 */
	const fillGuestContact = async (
		page: Page,
		name: string,
		email: string,
		phone: string
	) => {
		await page.locator( '#vkbm-confirm-guest-name' ).fill( name );
		await page.locator( '#vkbm-confirm-guest-email' ).fill( email );
		await page.locator( '#vkbm-confirm-guest-phone' ).fill( phone );
	};

	// @title 受付方法が「会員のみ」なら、今までどおりログインを促す画面になる
	test( 'member only keeps the existing login prompt', async ( { page } ) => {
		// @step 受付方法を「会員のみ」にして、ログインせずに予約確認画面まで進む
		setCustomerMode( 'member' );
		await gotoConfirmScreen( page );

		// @check 「お客様情報」欄は出ず、ログイン・新規登録のボタンが出て、予約ボタンは押せない
		const auth = page.locator( '.vkbm-confirm__auth' );
		await expect( auth ).toBeVisible();
		await expect( auth.locator( '.vkbm-button' ) ).toHaveCount( 2 );
		await expect(
			page.locator( '.vkbm-confirm__guest-fields' )
		).toHaveCount( 0 );
		await expect(
			page.locator( '.vkbm-confirm__guest-submit-note' )
		).toHaveCount( 0 );
		await expect( page.locator( '.vkbm-confirm__button' ) ).toBeDisabled();
	} );

	// @title 受付方法が「ゲストのみ」なら、お客様情報欄だけが出る
	test( 'guest only shows the customer information fields without login', async ( {
		page,
	} ) => {
		// @step 受付方法を「ゲストのみ」にして、ログインせずに予約確認画面まで進み、同意欄にチェックを入れる
		setCustomerMode( 'guest' );
		await gotoConfirmScreen( page );
		await checkConfirmConsents( page );

		// @check ログイン・新規登録の欄は出ず、お名前・メールアドレス・電話番号の入力欄と、送信後の流れの案内が出る
		const fields = page.locator( '.vkbm-confirm__guest-fields' );
		await expect( fields ).toBeVisible();
		await expect( fields.locator( 'h3' ) ).toHaveText( 'お客様情報' );
		await expect( page.locator( '.vkbm-confirm__auth' ) ).toHaveCount( 0 );
		await expect(
			page.locator( '.vkbm-confirm__guest-login-prompt' )
		).toHaveCount( 0 );

		// 3 欄とも必須で、ブラウザの自動入力の指定が付いている。
		const name = page.locator( '#vkbm-confirm-guest-name' );
		const email = page.locator( '#vkbm-confirm-guest-email' );
		const phone = page.locator( '#vkbm-confirm-guest-phone' );
		await expect( name ).toHaveAttribute( 'autocomplete', 'name' );
		await expect( email ).toHaveAttribute( 'autocomplete', 'email' );
		await expect( phone ).toHaveAttribute( 'autocomplete', 'tel' );
		for ( const input of [ name, email, phone ] ) {
			await expect( input ).toHaveAttribute( 'aria-required', 'true' );
		}
		await expect(
			fields.locator( 'label[for="vkbm-confirm-guest-name"]' )
		).toHaveText( '予約者のお名前（必須）' );
		await expect(
			fields.locator( '.vkbm-confirm__guest-field-help' )
		).toHaveText(
			'確認メールとキャンセル用のリンクをこのアドレスにお送りします。'
		);

		// 送信ボタンの直上に案内があり、ボタンの文言は変わらない。
		const note = page.locator( '.vkbm-confirm__guest-submit-note' );
		await expect( note ).toContainText(
			'送信後に確認メールをお送りします。'
		);
		await expect( note ).toContainText(
			'メールのリンクを開くと予約が完了します。'
		);
		// 2 文は 1 つの文言で、文の間に余計な空白が入らない。
		await expect( note ).toContainText(
			'送信後に確認メールをお送りします。メールのリンクを開くと予約が完了します。'
		);
		await expect( page.locator( '.vkbm-confirm__button' ) ).toHaveText(
			'確認メールを送信する'
		);
		await expect( page.locator( '.vkbm-confirm__button' ) ).toBeEnabled();

		// @check 画面に「ゲスト」という言葉は出ない
		// お客様の目に触れる場所では「ゲスト」と言わない。
		const visibleText = await page.locator( '.vkbm-confirm' ).innerText();
		expect( visibleText ).not.toContain( 'ゲスト' );
	} );

	// @title 想定する最小の画面幅でも「予約者のお名前（必須）」が途中で折れない
	test( 'name label does not wrap at the narrowest width', async ( {
		page,
	} ) => {
		setCustomerMode( 'guest' );
		// @step 画面幅を 320px にして、「ゲストのみ」の予約確認画面を開く
		await page.setViewportSize( { width: 320, height: 800 } );
		await gotoConfirmScreen( page );

		// @check 「予約者のお名前（必須）」のラベルが 1 行に収まる
		const label = page.locator( 'label[for="vkbm-confirm-guest-name"]' );
		await expect( label ).toBeVisible();
		const metrics = await label.evaluate( ( el ) => {
			const style = window.getComputedStyle( el );
			const lineHeight =
				parseFloat( style.lineHeight ) ||
				parseFloat( style.fontSize ) * 1.4;
			return {
				height: el.getBoundingClientRect().height,
				lineHeight,
			};
		} );
		expect( metrics.height ).toBeLessThan( metrics.lineHeight * 1.5 );
	} );

	// @title 必須の入力が空のまま送ろうとすると、欄の下にエラーが出る
	test( 'required fields show errors under each field', async ( {
		page,
	} ) => {
		setCustomerMode( 'guest' );
		await gotoConfirmScreen( page );
		// @step 「ゲストのみ」の予約確認画面で、何も入力せずに予約ボタンを押す
		await checkConfirmConsents( page );

		await page.locator( '.vkbm-confirm__button' ).click();
		// @check お名前・メールアドレス・電話番号の下にそれぞれエラーが出て、お名前の欄にカーソルが移る
		for ( const field of [ 'name', 'email', 'phone' ] ) {
			await expect(
				page.locator( `#vkbm-confirm-guest-${ field }-error` )
			).toBeVisible();
		}
		await expect(
			page.locator( '#vkbm-confirm-guest-name' )
		).toBeFocused();
		await expect(
			page.locator( '#vkbm-confirm-guest-name' )
		).toHaveAttribute( 'aria-invalid', 'true' );

		// @step お名前と電話番号を入れ、メールアドレスに形式の違う文字を入れてもう一度押す
		// お名前と電話番号を入れ、メールアドレスだけ形式を誤る。
		await fillGuestContact(
			page,
			'山田 花子',
			'not-an-email',
			'090-1234-5678'
		);
		await page.locator( '.vkbm-confirm__button' ).click();
		// @check メールアドレスの下に形式のエラーだけが出る
		await expect(
			page.locator( '#vkbm-confirm-guest-email-error' )
		).toBeVisible();
		await expect(
			page.locator( '#vkbm-confirm-guest-name-error' )
		).toHaveCount( 0 );
		await expect(
			page.locator( '#vkbm-confirm-guest-phone-error' )
		).toHaveCount( 0 );
		await expect(
			page.locator( '#vkbm-confirm-guest-email' )
		).toBeFocused();
	} );

	// @title 「どちらでも」では入力欄が開いていて、ログイン・新規登録へ切り替えても入力が残る
	test( 'both mode keeps the typed customer information when switching to login', async ( {
		page,
	} ) => {
		// @step 受付方法を「どちらでも」にして予約確認画面を開く
		setCustomerMode( 'both' );
		await gotoConfirmScreen( page );

		// @check 入力欄の上に「会員登録済みの方はログインすると入力を省けます。新規登録」の案内が出ている
		const prompt = page.locator( '.vkbm-confirm__guest-login-prompt' );
		await expect( prompt ).toBeVisible();
		await expect( prompt ).toContainText(
			'会員登録済みの方はログインすると入力を省けます。'
		);
		await expect(
			prompt.getByRole( 'button', { name: 'ログイン' } )
		).toBeVisible();
		await expect(
			prompt.getByRole( 'button', { name: '新規登録' } )
		).toBeVisible();

		// @step お名前と電話番号を入力し、案内の「ログイン」を押す
		await page.locator( '#vkbm-confirm-guest-name' ).fill( '山田 花子' );
		await page
			.locator( '#vkbm-confirm-guest-phone' )
			.fill( '090-1234-5678' );

		// ログインの欄へ切り替える。
		await prompt.getByRole( 'button', { name: 'ログイン' } ).click();
		// @check ログインの欄が開き、お客様情報欄は隠れる
		await expect( page.locator( '.vkbm-confirm__auth' ) ).toBeVisible();
		await expect( page.locator( '.vkbm-confirm__auth-label' ) ).toHaveText(
			'会員の方はログインしてご予約いただけます。'
		);
		// 切り替え先の最初の入力欄にフォーカスが移る。
		await expect(
			page
				.locator(
					'.vkbm-confirm__auth-form input:not([type="hidden"])'
				)
				.first()
		).toBeFocused( { timeout: 15000 } );
		await expect(
			page.locator( '.vkbm-confirm__guest-fields' )
		).toHaveCount( 0 );
		await expect( page.locator( '.vkbm-confirm__auth-form' ) ).toBeVisible(
			{ timeout: 15000 }
		);
		// @check 「会員登録せずに予約する」へ戻る導線が 1 つだけ出る
		const back = page.getByRole( 'button', {
			name: '会員登録せずに予約する',
		} );
		await expect( back ).toHaveCount( 1 );

		// @step 「新規登録」に切り替える
		// 新規登録の欄へ切り替えても、戻る導線は 1 つのまま。
		await page
			.locator( '.vkbm-confirm__auth .vkbm-buttons .vkbm-button' )
			.nth( 1 )
			.click();
		await expect(
			page.getByRole( 'button', { name: '会員登録せずに予約する' } )
		).toHaveCount( 1 );

		// @step 「会員登録せずに予約する」を押す
		// 戻ると、入力途中のお客様情報が残っている。
		const backBox = await back.boundingBox();
		expect( backBox!.height ).toBeGreaterThanOrEqual( 44 );
		await back.click();
		// @check お客様情報欄に戻り、入力したお名前と電話番号が残っている
		await expect(
			page.locator( '.vkbm-confirm__guest-fields' )
		).toBeVisible();
		await expect( page.locator( '#vkbm-confirm-guest-name' ) ).toHaveValue(
			'山田 花子'
		);
		await expect(
			page.locator( '#vkbm-confirm-guest-name' )
		).toBeFocused();
		await expect( page.locator( '#vkbm-confirm-guest-phone' ) ).toHaveValue(
			'090-1234-5678'
		);
	} );

	// @title ログイン中の会員は、受付方法に関わらず会員として予約する
	test( 'logged-in member books as a member in both mode', async ( {
		page,
	} ) => {
		// @step 受付方法を「どちらでも」にして、会員でログインして予約確認画面を開く
		setCustomerMode( 'both' );
		await page.goto( '/wp-login.php' );
		await page.locator( '#user_login' ).fill( memberUsername );
		await page.locator( '#user_pass' ).fill( password );
		await page.locator( '#wp-submit' ).click();
		await page.waitForLoadState( 'networkidle' );

		await gotoConfirmScreen( page );
		await checkConfirmConsents( page );

		// @check お客様情報欄・ログイン案内・送信後の流れの案内は出ず、予約ボタンは押せる
		await expect(
			page.locator( '.vkbm-confirm__guest-fields' )
		).toHaveCount( 0 );
		await expect(
			page.locator( '.vkbm-confirm__guest-login-prompt' )
		).toHaveCount( 0 );
		await expect(
			page.locator( '.vkbm-confirm__guest-submit-note' )
		).toHaveCount( 0 );
		await expect( page.locator( '.vkbm-confirm__auth' ) ).toHaveCount( 0 );
		await expect( page.locator( '.vkbm-confirm__button' ) ).toBeEnabled();
	} );

	// @title 送信後の画面を案内色で出し、メールアドレスを直して送り直せる
	test( 'sent screen, resend with a corrected address, and the confirmed result', async ( {
		page,
	} ) => {
		setCustomerMode( 'guest' );
		await gotoConfirmScreen( page );
		await checkConfirmConsents( page );

		// @step 「ゲストのみ」の予約確認画面でお客様情報を入力し、予約ボタンを押す
		const wrongEmail = newGuestEmail();
		await fillGuestContact(
			page,
			'山田 花子',
			wrongEmail,
			'090-1234-5678'
		);
		await page.locator( '.vkbm-confirm__button' ).click();

		// @check 「確認メールをお送りしました」の画面が緑ではなく案内色で出て、宛先と有効期限と「リンクを開くまで予約は確定していません」が表示される
		// 送信後の画面: 案内色（info）で出し、緑（success）は使わない。
		const sent = page.locator( '.vkbm-confirm__guest-sent' );
		await expect( sent ).toBeVisible( { timeout: 15000 } );
		await expect( sent ).toHaveClass( /vkbm-alert__info/ );
		await expect( sent ).not.toHaveClass( /vkbm-alert__success/ );
		await expect( sent ).toBeFocused();
		await expect(
			sent.locator( '.vkbm-confirm__complete-title' )
		).toHaveText( '確認メールをお送りしました' );
		await expect( sent ).toContainText( wrongEmail );
		await expect( sent ).toContainText( '有効期限' );
		await expect( sent ).toContainText(
			'リンクを開くまで予約は確定していません。'
		);
		await expect( sent ).toContainText( '迷惑メールフォルダ' );
		// @check 予約ボタンと入力欄は消え、予約はまだ作られていない
		await expect( page.locator( '.vkbm-confirm__button' ) ).toHaveCount(
			0
		);
		await expect(
			page.locator( '.vkbm-confirm__guest-fields' )
		).toHaveCount( 0 );
		expect(
			wpEvalPhp(
				'echo count( get_posts( array( "post_type" => "vkbm_booking", "post_status" => "any", "numberposts" => -1, "fields" => "ids", "meta_key" => "_vkbm_booking_customer_email", "meta_value" => "' +
					wrongEmail +
					'" ) ) );'
			)
		).toBe( '0' );

		// @step 「メールアドレスを修正して送り直す」を押す
		// 送り直す: 画面が戻り、お名前・電話番号が残り、メールアドレス欄にカーソルが移る。
		await sent
			.getByRole( 'button', { name: 'メールアドレスを修正して送り直す' } )
			.click();
		// @check 送信後の画面が閉じ、お名前と電話番号を残したままお客様情報欄に戻り、メールアドレス欄にカーソルが移る
		await expect( sent ).toHaveCount( 0 );
		await expect(
			page.locator( '.vkbm-confirm__guest-fields' )
		).toBeVisible();
		await expect( page.locator( '#vkbm-confirm-guest-name' ) ).toHaveValue(
			'山田 花子'
		);
		await expect( page.locator( '#vkbm-confirm-guest-phone' ) ).toHaveValue(
			'090-1234-5678'
		);
		await expect(
			page.locator( '#vkbm-confirm-guest-email' )
		).toBeFocused();

		// @step メールアドレスを直して、もう一度予約ボタンを押す
		// メールアドレスを直して送る。
		const correctedEmail = newGuestEmail();
		await page
			.locator( '#vkbm-confirm-guest-email' )
			.fill( correctedEmail );
		await page.locator( '.vkbm-confirm__button' ).click();
		await expect( sent ).toBeVisible( { timeout: 15000 } );
		// @check 新しい宛先で送信後の画面が出る
		await expect( sent ).toContainText( correctedEmail );

		// @step 確認メールのリンクを開く
		// 確認メールのリンクを開くと、予約が確定する。
		const link = readVerifyLink( correctedEmail );
		await page.goto( link );
		// @check 「ご予約が確定しました」の画面が緑で出て、予約内容が表示される
		const result = page.locator( '.vkbm-confirm__guest-result' );
		await expect( result ).toBeVisible( { timeout: 15000 } );
		await expect( result ).toHaveAttribute(
			'data-vkbm-guest-result',
			'confirmed'
		);
		await expect( result ).toHaveClass( /vkbm-alert__success/ );
		await expect(
			result.locator( '.vkbm-confirm__complete-title' )
		).toHaveText( 'ご予約が確定しました' );
		await expect( result ).not.toContainText(
			'このリンクは、すでに確認済みです。'
		);
		await expect( page.locator( '.vkbm-confirm__summary' ) ).toContainText(
			'Service Menu 1'
		);

		// @step 同じ確認メールのリンクをもう一度開く
		// 同じリンクを開き直すと、エラーにせず「確認済み」の 1 行を足して今の状態を出す。
		await page.goto( link );
		await expect( result ).toBeVisible( { timeout: 15000 } );
		await expect( result ).toHaveAttribute(
			'data-vkbm-guest-result',
			'confirmed'
		);
		// @check 「このリンクは、すでに確認済みです。」が加わり、予約の今の状態が表示される
		await expect( result ).toContainText(
			'このリンクは、すでに確認済みです。'
		);
	} );

	// @title 確認リンクを開いた後の結果画面が、結果ごとの見出し・色・ボタンで出る
	test( 'result screens show the heading, tone and button for each result', async ( {
		page,
	} ) => {
		// @step 結果の違う確認リンク（仮予約・期限切れ・形が不正・枠が埋まっていた・処理中・キャンセル済み・予約できなかった）を用意して、順に開く
		setCustomerMode( 'guest' );

		const pendingBookingId = craftBooking( 'pending' );
		const cancelledBookingId = craftBooking( 'cancelled' );

		// 処理中の再現: 確認待ちデータがあり、ロックが取られている。
		const processingToken = newToken();
		wpEvalPhp(
			`$h = hash( "sha256", "${ processingToken }" );` +
				'set_transient( "vkbm_guest_verify_" . $h, array( "draft" => array(), "guest" => array() ), 3600 );' +
				'update_option( "vkbm_guest_verify_lock_" . $h, "e2e|" . time(), false );'
		);

		// 受付方法が「会員のみ」に戻っていた場合の再現は、別のトークンで後から開く。
		const unavailableToken = newToken();
		wpEvalPhp(
			`set_transient( "vkbm_guest_verify_" . hash( "sha256", "${ unavailableToken }" ), array( "draft" => array(), "guest" => array() ), 3600 );`
		);

		const pendingToken = newToken();
		craftUsedRecord( pendingToken, {
			outcome: 'created',
			booking_id: pendingBookingId,
		} );
		const slotToken = newToken();
		craftUsedRecord( slotToken, {
			outcome: 'slot_unavailable',
			code: 'slot_unavailable',
			message: '内部の理由（枠）',
		} );
		const closedToken = newToken();
		craftUsedRecord( closedToken, {
			outcome: 'created',
			booking_id: cancelledBookingId,
		} );
		const rejectedToken = newToken();
		craftUsedRecord( rejectedToken, {
			outcome: 'rejected',
			code: 'customer_flag_blocked',
			message: 'この顧客は受け付けできません（内部の理由）',
		} );

		const cases = [
			{
				name: '仮予約',
				token: pendingToken,
				result: 'pending',
				tone: 'success',
				title: '仮予約を受け付けました',
				button: '',
				extra: [ 'このリンクは、すでに確認済みです。' ],
			},
			{
				name: '期限切れ',
				token: newToken(),
				result: 'expired',
				tone: 'warning',
				title: '確認リンクの有効期限が切れています',
				button: '予約をやり直す',
				extra: [ 'お手数ですが、もう一度最初からご予約ください。' ],
			},
			{
				name: '形が不正',
				token: 'abc',
				result: 'invalid',
				tone: 'warning',
				title: 'この確認リンクは正しくありません',
				button: '予約ページへ',
				extra: [],
			},
			{
				name: '枠が埋まっていた',
				token: slotToken,
				result: 'slot_unavailable',
				tone: 'warning',
				title: 'この日時は先に別のご予約が入りました',
				button: '別の日時で予約する',
				extra: [ 'お手数ですが、別の日時を選んでご予約ください。' ],
			},
			{
				name: '処理中',
				token: processingToken,
				result: 'processing',
				tone: 'warning',
				title: 'ご予約を処理しています',
				button: '予約ページへ',
				extra: [
					'しばらくしてから、メールのリンクをもう一度開いてください。',
				],
			},
			{
				name: 'キャンセル済みなど',
				token: closedToken,
				result: 'booking_closed',
				tone: 'warning',
				title: 'このご予約は有効ではありません',
				button: '予約ページへ',
				extra: [],
			},
			{
				name: '予約できなかった',
				token: rejectedToken,
				result: 'rejected',
				tone: 'danger',
				title: '予約を受け付けられませんでした',
				button: '予約ページへ',
				extra: [
					'このご予約はオンラインでは受け付けられません。',
					'店舗へ直接お問い合わせください。',
				],
			},
		];

		// @check それぞれ決まった見出しが出て、確定・仮予約は緑、予約できなかったは赤系、それ以外は注意色になる
		for ( const item of cases ) {
			await page.goto( `/booking/?vkbm_guest_verify=${ item.token }` );
			const result = page.locator( '.vkbm-confirm__guest-result' );
			await expect( result, item.name ).toBeVisible( { timeout: 15000 } );
			// 確認リンクのトークンは、結果を出した後にアドレスバーから外れる。
			await expect( page, item.name ).not.toHaveURL(
				/vkbm_guest_verify/
			);
			await expect( result, item.name ).toHaveAttribute(
				'data-vkbm-guest-result',
				item.result
			);
			await expect( result, item.name ).toHaveClass(
				new RegExp( `vkbm-alert__${ item.tone }` )
			);
			await expect(
				result.locator( '.vkbm-confirm__complete-title' ),
				item.name
			).toHaveText( item.title );
			for ( const text of item.extra ) {
				await expect( result, item.name ).toContainText( text );
			}

			const button = page.locator(
				'.vkbm-reservation-content--guest-result .vkbm-buttons a'
			);
			if ( item.button ) {
				await expect( button, item.name ).toHaveText( item.button );
				// 戻り先は確認リンクのトークンを外した予約ページ。
				await expect( button, item.name ).not.toHaveAttribute(
					'href',
					/vkbm_guest_verify/
				);
			} else {
				await expect( button, item.name ).toHaveCount( 0 );
			}

			// 予約できなかった理由（顧客フラグなど）は画面に出さない。
			await expect( page.locator( 'body' ), item.name ).not.toContainText(
				'内部の理由'
			);
		}

		// @check 予約ページへ戻るボタンが決まった文言で出て、予約できなかった理由は画面に出ない
		// 仮予約の結果には予約内容も出る。
		await page.goto( `/booking/?vkbm_guest_verify=${ pendingToken }` );
		await expect( page.locator( '.vkbm-confirm__summary' ) ).toContainText(
			'Service Menu 1'
		);

		// @step 受付方法を「会員のみ」に戻してから、確認待ちのまま残っていたリンクを開く
		// 受付方法が「会員のみ」に戻っていたとき（確認待ちデータが残っている）。
		setCustomerMode( 'member' );
		await page.goto( `/booking/?vkbm_guest_verify=${ unavailableToken }` );
		// @check 「現在、会員登録なしのご予約は受け付けていません」が注意色で出る
		const unavailable = page.locator( '.vkbm-confirm__guest-result' );
		await expect( unavailable ).toHaveAttribute(
			'data-vkbm-guest-result',
			'unavailable'
		);
		await expect( unavailable ).toHaveClass( /vkbm-alert__warning/ );
		await expect(
			unavailable.locator( '.vkbm-confirm__complete-title' )
		).toHaveText( '現在、会員登録なしのご予約は受け付けていません' );
	} );

	// @title 確認リンクを開いた結果は、受付方法が「会員のみ」でも今までの予約画面を壊さない
	test( 'reservation page without a verification link is unchanged', async ( {
		page,
	} ) => {
		// @step 受付方法を「会員のみ」にして、確認リンクではない通常の予約ページを開く
		setCustomerMode( 'member' );
		await page.goto( '/booking/' );
		// @check メニュー一覧が今までどおり表示され、結果画面は出ない
		await expect(
			page.locator( '.vkbm-menu-loop__button--reserve' ).first()
		).toBeVisible( { timeout: 15000 } );
		await expect(
			page.locator( '.vkbm-confirm__guest-result' )
		).toHaveCount( 0 );
	} );
} );
