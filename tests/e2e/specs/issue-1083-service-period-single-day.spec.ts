/**
 * サービスメニューの提供期間「1日のみ」（#1083）
 *
 * 検証内容:
 * 1. 編集画面: 「提供期間」欄に「期間（開始日〜終了日）」「1日のみ」のラジオがあり、
 *    1日のみにすると終了日・期間の説明文・「予約可能日」の行が画面から消え、開催時刻の案内が出る。
 *    保存すると開始日・終了日に同じ日付が入る。期間に戻すと終了日と「予約可能日」の行が戻る。
 * 2. 編集画面: 1日のみで開催日を空にして保存しても、前回の提供期間が残る。
 * 3. 予約ページのカードと管理画面のメニュー一覧: 1日のみのメニューは「開催日」と曜日付きの日付を出し、
 *    「予約可能日」は出さない。
 * 4. 予約ページ: 1日のみのメニューを選ぶと開催日が選ばれ、時間枠が1つなら選ばれる（「予約可能日」が平日のみでも土曜日に予約できる）。
 *    時間枠が2つなら選ばれない。期間のメニューは「予約可能日」が効いて土曜日は選べない。
 *
 * テストデータと後片付け:
 * - プランは固有の接頭辞（E2E1083）を付けて作り、afterAll で接頭辞を手がかりに削除する。
 * - 開催日は「来月の最初の土曜日」にする（global-setup が今月と来月のシフト 9:00〜18:00 を登録済みのため）。
 */
// @category 予約画面・カレンダー
import { test, expect, type Page } from '@playwright/test';
import {
	wpEvalPhp,
	loginAsAdmin,
	getStaffId,
	setAdminBlockEditorPreferences,
} from '../utils/helpers';

// テストデータの識別用接頭辞（他 spec のデータと区別する）。
const PREFIX = 'E2E1083';

let adminMenuId = '';
let singleMenuId = '';
let twoSlotMenuId = '';
let rangeMenuId = '';
// 来月の最初の土曜日（Y-m-d）と、表示用の文字列（年あり・年なし）。
let eventIso = '';
let eventLong = '';
let eventShort = '';

/**
 * PHP を実行し、出力が正の整数（post ID）であることを確かめて返す。
 *
 * @param php   実行する PHP コード（最後に ID を echo すること）
 * @param label エラーメッセージ用の名前
 * @return 作成した post ID（数値文字列）
 */
function insertAndGetId( php: string, label: string ): string {
	const result = wpEvalPhp( php ).trim();
	if ( ! /^\d+$/.test( result ) || Number( result ) <= 0 ) {
		throw new Error( `${ label } seeding failed: "${ result }"` );
	}
	return result;
}

/**
 * この spec で作ったプランを接頭辞を手がかりに削除する。
 */
function cleanupSeededData(): void {
	wpEvalPhp( `
		$prefix = '${ PREFIX }';
		foreach ( get_posts( array(
			'post_type'   => 'vkbm_service_menu',
			'post_status' => 'any',
			'numberposts' => -1,
			's'           => $prefix,
		) ) as $p ) {
			if ( 0 === strpos( $p->post_title, $prefix ) ) { wp_delete_post( $p->ID, true ); }
		}
		echo 'ok';
	` );
}

/**
 * 時間枠で予約するプランを作る。
 *
 * @param title   プラン名
 * @param staffId 担当スタッフの post ID
 * @param meta    追加で保存するメタ（PHP の配列リテラルの中身）
 * @return 作成した post ID
 */
function createMenu( title: string, staffId: string, meta: string ): string {
	return insertAndGetId(
		`
		$id = wp_insert_post( array(
			'post_type'   => 'vkbm_service_menu',
			'post_status' => 'publish',
			'post_title'  => '${ title }',
		) );
		if ( is_wp_error( $id ) || ! $id ) { echo 'Error'; return; }
		update_post_meta( $id, '_vkbm_staff_ids', array( ${ Number( staffId ) } ) );
		update_post_meta( $id, '_vkbm_base_price', 5000 );
		update_post_meta( $id, '_vkbm_duration_minutes', 60 );
		foreach ( array( ${ meta } ) as $key => $value ) { update_post_meta( $id, $key, $value ); }
		echo $id;
	`,
		title
	);
}

/**
 * メタの値を読む。
 *
 * @param postId post ID
 * @param key    メタキー
 * @return 保存値（無ければ空文字）
 */
function getMeta( postId: string, key: string ): string {
	return wpEvalPhp(
		`echo (string) get_post_meta( ${ Number(
			postId
		) }, '${ key }', true );`
	).trim();
}

/**
 * 予約ページを、指定したメニューを選んだ状態で開き、カレンダーの読み込みを待つ。
 *
 * @param page   Playwright のページ
 * @param menuId 選ぶメニューの post ID
 */
async function openBookingWithMenu( page: Page, menuId: string ) {
	await page.goto( `/booking/?menu_id=${ menuId }` );
	await page.waitForSelector( '.vkbm-calendar', {
		state: 'visible',
		timeout: 15000,
	} );
	await page
		.locator( '.vkbm-calendar__spinner' )
		.waitFor( { state: 'detached', timeout: 15000 } )
		.catch( () => undefined );
}

/**
 * 表示中の月の n 日のボタン（前後の月の日は除く）。
 *
 * @param page Playwright のページ
 * @param day  日
 */
const dayButton = ( page: Page, day: number ) =>
	page
		.locator( '.vkbm-calendar__day:not(.vkbm-calendar__day--muted)' )
		.filter( {
			has: page.locator( '.vkbm-calendar__day-label', {
				hasText: new RegExp( `^${ day }$` ),
			} ),
		} );

test.describe.serial( 'サービスメニューの提供期間「1日のみ」（#1083）', () => {
	test.beforeAll( () => {
		// 来月の最初の土曜日と、その表示用の文字列（サイトのタイムゾーン・日本語）を求める。
		const values = wpEvalPhp( `
			$tz  = wp_timezone();
			$sat = ( new DateTimeImmutable( 'first day of next month', $tz ) )->modify( '-1 day' )->modify( 'next saturday' );
			echo $sat->format( 'Y-m-d' ) . '|' . wp_date( 'Y年n月j日（D）', $sat->getTimestamp() ) . '|' . wp_date( 'n月j日（D）', $sat->getTimestamp() );
		` )
			.trim()
			.split( '|' );
		[ eventIso, eventLong, eventShort ] = values;

		cleanupSeededData();
		const staffId = getStaffId();
		// @step 期間のままで「予約可能日」が平日のみのプラン「E2E1083 Admin Plan」（編集画面の確認用）を作る
		adminMenuId = createMenu(
			`${ PREFIX } Admin Plan`,
			staffId,
			`'_vkbm_reservation_day_type' => 'weekday'`
		);
		// @step 1日のみ（開催日は来月の最初の土曜日）・固定開始時刻 13:00 の1件・「予約可能日」は平日のみのプラン「E2E1083 Single Plan」を作る
		singleMenuId = createMenu(
			`${ PREFIX } Single Plan`,
			staffId,
			`'_vkbm_service_period_mode' => 'single', '_vkbm_service_period_start' => '${ eventIso }', '_vkbm_service_period_end' => '${ eventIso }', '_vkbm_reservation_day_type' => 'weekday', '_vkbm_fixed_start_times' => array( '13:00' )`
		);
		// @step 同じ条件で固定開始時刻が 13:00 と 15:00 の2件のプラン「E2E1083 Two Slot Plan」を作る
		twoSlotMenuId = createMenu(
			`${ PREFIX } Two Slot Plan`,
			staffId,
			`'_vkbm_service_period_mode' => 'single', '_vkbm_service_period_start' => '${ eventIso }', '_vkbm_service_period_end' => '${ eventIso }', '_vkbm_reservation_day_type' => 'weekday', '_vkbm_fixed_start_times' => array( '13:00', '15:00' )`
		);
		// @step 対照として、期間（開始日＝終了日＝開催日）・「予約可能日」が平日のみのプラン「E2E1083 Range Plan」を作る
		rangeMenuId = createMenu(
			`${ PREFIX } Range Plan`,
			staffId,
			`'_vkbm_service_period_mode' => 'range', '_vkbm_service_period_start' => '${ eventIso }', '_vkbm_service_period_end' => '${ eventIso }', '_vkbm_reservation_day_type' => 'weekday', '_vkbm_fixed_start_times' => array( '13:00' )`
		);
	} );

	test.afterAll( () => {
		cleanupSeededData();
		setAdminBlockEditorPreferences();
	} );

	// @title 編集画面の「1日のみ」の切り替えと保存
	test( '「1日のみ」にすると終了日・期間の説明文・「予約可能日」の行が消えて開催時刻の案内が出て、保存すると開始日と終了日に同じ日付が入り、期間に戻すと元の欄が戻る', async ( {
		page,
	} ) => {
		setAdminBlockEditorPreferences();
		// @step 管理者でログインし、「E2E1083 Admin Plan」の編集画面を開く
		await loginAsAdmin( page );
		await page.goto(
			`/wp-admin/post.php?post=${ adminMenuId }&action=edit`
		);
		await page
			.locator( '#vkbm-service-period-field' )
			.waitFor( { state: 'attached', timeout: 15000 } );

		const rangeRadio = page.getByRole( 'radio', {
			name: '期間（開始日〜終了日）',
		} );
		const singleRadio = page.getByRole( 'radio', { name: '1日のみ' } );
		const endInput = page.locator(
			'#vkbm_service_menu_service_period_end'
		);
		const rangeHelp = page.locator( '#vkbm-service-period-range-help' );
		const singleHelp = page.locator( '#vkbm-service-period-single-help' );
		const dayRow = page.locator( '#vkbm-reservation-day-field' );

		// @check 「期間（開始日〜終了日）」が選ばれ、終了日の入力欄・期間の説明文・「予約可能日」の行が見えている
		await expect( rangeRadio ).toBeChecked();
		await expect( endInput ).toBeVisible();
		await expect( rangeHelp ).toBeVisible();
		await expect( dayRow ).toBeVisible();
		await expect( singleHelp ).toBeHidden();

		// @step 「1日のみ」を選ぶ
		await singleRadio.check();
		// @check 終了日の入力欄・期間の説明文・「予約可能日」の行が画面から消え（CSS で打ち消されず実際に非表示）、開催時刻の案内が出る
		await expect( endInput ).toBeHidden();
		await expect(
			page.locator( '.vkbm-service-period-end-group' )
		).toHaveCSS( 'display', 'none' );
		await expect( rangeHelp ).toBeHidden();
		await expect( dayRow ).toBeHidden();
		await expect( singleHelp ).toBeVisible();
		await expect( singleHelp ).toHaveText(
			'開催時刻は「固定開始時刻」で指定してください。'
		);
		// @check 開催日の入力欄の読み上げ名が「開催日」になる
		await expect(
			page.locator( '#vkbm_service_menu_service_period_start' )
		).toHaveAttribute( 'aria-label', '開催日' );

		// @step 開催日に来月の最初の土曜日を入れて、更新ボタンで保存する
		await page
			.locator( '#vkbm_service_menu_service_period_start' )
			.fill( eventIso );
		await page.locator( '.editor-post-publish-button' ).click();
		// @check モードが「1日のみ」、開始日と終了日の両方に開催日が保存される
		await expect
			.poll( () => getMeta( adminMenuId, '_vkbm_service_period_mode' ), {
				timeout: 20000,
			} )
			.toBe( 'single' );
		expect( getMeta( adminMenuId, '_vkbm_service_period_start' ) ).toBe(
			eventIso
		);
		expect( getMeta( adminMenuId, '_vkbm_service_period_end' ) ).toBe(
			eventIso
		);

		// @step 編集画面を開き直す
		await page.reload();
		await page
			.locator( '#vkbm-service-period-field' )
			.waitFor( { state: 'attached', timeout: 15000 } );
		// @check 「1日のみ」が選ばれ、開催日が残り、「予約可能日」の行は出ていない
		await expect( singleRadio ).toBeChecked();
		await expect(
			page.locator( '#vkbm_service_menu_service_period_start' )
		).toHaveValue( eventIso );
		await expect( dayRow ).toBeHidden();
		await expect( endInput ).toBeHidden();

		// @step 「期間（開始日〜終了日）」に切り替える（保存はしない）
		await rangeRadio.check();
		// @check 終了日の入力欄（開催日と同じ日付）と「予約可能日」の行が、また表示される
		await expect( endInput ).toBeVisible();
		await expect( endInput ).toHaveValue( eventIso );
		await expect( dayRow ).toBeVisible();
		await expect( singleHelp ).toBeHidden();
	} );

	// @title 開催日を空にして保存しても前回の値を保つ
	test( '「1日のみ」で開催日を空にして保存すると、前回の提供期間が残り、案内が出る', async ( {
		page,
	} ) => {
		setAdminBlockEditorPreferences();
		// @step 管理者でログインし、前のテストで1日のみにした「E2E1083 Admin Plan」の編集画面を開く
		await loginAsAdmin( page );
		await page.goto(
			`/wp-admin/post.php?post=${ adminMenuId }&action=edit`
		);
		const startInput = page.locator(
			'#vkbm_service_menu_service_period_start'
		);
		await startInput.waitFor( { state: 'attached', timeout: 15000 } );
		// @step 開催日を空にして、更新ボタンで保存する
		await startInput.fill( '' );
		await page.locator( '.editor-post-publish-button' ).click();
		// @check 保存後、エディターの上部に「有効な開催日を入力して、もう一度保存してください。提供期間は前回の設定のままです。」と出る
		await expect(
			page
				.getByText(
					'有効な開催日を入力して、もう一度保存してください。提供期間は前回の設定のままです。'
				)
				.first()
		).toBeVisible( { timeout: 20000 } );
		// @check 開始日・終了日・モードは前回の値のまま
		expect( getMeta( adminMenuId, '_vkbm_service_period_start' ) ).toBe(
			eventIso
		);
		expect( getMeta( adminMenuId, '_vkbm_service_period_end' ) ).toBe(
			eventIso
		);
		expect( getMeta( adminMenuId, '_vkbm_service_period_mode' ) ).toBe(
			'single'
		);
		// @step 編集画面を開き直す
		await page.reload();
		// @check 開催日の入力欄に前回の開催日が残っている
		await expect(
			page.locator( '#vkbm_service_menu_service_period_start' )
		).toHaveValue( eventIso, { timeout: 15000 } );
	} );

	// @title カードと管理画面の一覧の「開催日」表示
	test( '1日のみのメニューのカードと管理画面の一覧に「開催日」と曜日付きの日付が出て、「予約可能日」は出ない', async ( {
		page,
	} ) => {
		// @step 予約ページを開く
		await page.goto( '/booking/' );
		const singleCard = page
			.locator( '.vkbm-menu-loop__item' )
			.filter( { hasText: `${ PREFIX } Single Plan` } )
			.first();
		await expect( singleCard ).toBeVisible( { timeout: 15000 } );
		// @check 「E2E1083 Single Plan」のカードに「開催日」と年・曜日付きの日付が出て、「提供期間」「予約可能日」は出ない
		await expect(
			singleCard.locator( '.vkbm-menu-loop__card-meta-item', {
				hasText: '開催日',
			} )
		).toContainText( eventLong );
		await expect( singleCard ).not.toContainText( '提供期間' );
		await expect( singleCard ).not.toContainText( '予約可能日' );
		// @check 対照の「E2E1083 Range Plan」のカードには、従来どおり「提供期間」と「予約可能日」が出る
		const rangeCard = page
			.locator( '.vkbm-menu-loop__item' )
			.filter( { hasText: `${ PREFIX } Range Plan` } )
			.first();
		await expect( rangeCard ).toContainText( '提供期間' );
		await expect( rangeCard ).toContainText( '予約可能日' );
		await expect( rangeCard ).not.toContainText( '開催日' );

		// @step 管理者でログインし、管理画面の「サービスメニュー」一覧を開く
		await loginAsAdmin( page );
		await page.goto( '/wp-admin/edit.php?post_type=vkbm_service_menu' );
		const cell = ( id: string ) =>
			page.locator(
				`tr#post-${ id } td.column-vkbm_reservation_day_type`
			);
		// @check 「提供期間／予約可能日」の列に「開催日 n月j日（曜）」が出て、予約可能日は出ない
		await expect( cell( singleMenuId ) ).toHaveText(
			new RegExp( `^\\s*開催日\\s*${ eventShort }\\s*$` )
		);
		// @check 対照の期間のメニューは、開始日〜終了日と「予約可能日」の種類が出る
		await expect( cell( rangeMenuId ) ).not.toContainText( '開催日' );
		await expect( cell( rangeMenuId ) ).toContainText( '平日' );
	} );

	// @title 予約画面での開催日と時間枠の自動選択
	test( '1日のみのメニューを選ぶと開催日が選ばれ、時間枠が1つならその枠も選ばれ、平日のみの「予約可能日」は効かない', async ( {
		page,
	} ) => {
		const eventDay = Number( eventIso.split( '-' )[ 2 ] );
		const [ yearText, monthText ] = eventIso.split( '-' );

		// @step 予約ページを「E2E1083 Single Plan」を選んだ状態で開く
		await openBookingWithMenu( page, singleMenuId );
		// @check カレンダーが開催日の月で開き、開催日（土曜日）が選ばれている
		await expect( page.locator( '.vkbm-calendar__current' ) ).toContainText(
			new RegExp(
				`${ yearText }.*${ Number( monthText ) }|${ Number(
					monthText
				) }.*${ yearText }`
			)
		);
		await expect( dayButton( page, eventDay ) ).toHaveClass(
			/vkbm-calendar__day--selected/,
			{ timeout: 15000 }
		);
		await expect( dayButton( page, eventDay ) ).toBeEnabled();
		// @check 13:00 の時間枠だけが出て、選ばれた状態（aria-pressed="true"）になっている
		const slots = page.locator( '.vkbm-slot-list__item' );
		await expect( slots ).toHaveCount( 1, { timeout: 15000 } );
		await expect( slots.first() ).toContainText( '13:00' );
		await expect( slots.first() ).toHaveAttribute( 'aria-pressed', 'true' );
		await expect( slots.first() ).toHaveClass( /is-selected/ );

		// @step 固定開始時刻が 13:00 と 15:00 の2件の「E2E1083 Two Slot Plan」で予約ページを開く
		await openBookingWithMenu( page, twoSlotMenuId );
		// @check 開催日は選ばれているが、時間枠は2つとも選ばれていない
		await expect( dayButton( page, eventDay ) ).toHaveClass(
			/vkbm-calendar__day--selected/,
			{ timeout: 15000 }
		);
		await expect( slots ).toHaveCount( 2, { timeout: 15000 } );
		await expect(
			page.locator( '.vkbm-slot-list__item[aria-pressed="true"]' )
		).toHaveCount( 0 );

		// @step 対照として、期間（開始日＝終了日＝開催日）の「E2E1083 Range Plan」で予約ページを開く
		await openBookingWithMenu( page, rangeMenuId );
		// @check 「予約可能日」が平日のみのため、土曜日の開催日は選べない（従来どおり）
		await expect( page.locator( '.vkbm-calendar__current' ) ).toContainText(
			new RegExp(
				`${ yearText }.*${ Number( monthText ) }|${ Number(
					monthText
				) }.*${ yearText }`
			),
			{ timeout: 15000 }
		);
		await expect( dayButton( page, eventDay ) ).toBeDisabled( {
			timeout: 15000,
		} );
	} );

	// @title 予約画面でメニューのカードから選んだときの自動選択
	test( 'メニュー一覧のカードから1日のみのメニューを選んでも、開催日と1つだけの時間枠が選ばれる', async ( {
		page,
	} ) => {
		const eventDay = Number( eventIso.split( '-' )[ 2 ] );
		// @step 予約ページを開き、「E2E1083 Single Plan」のカードの予約ボタンを押す
		await page.goto( '/booking/' );
		const singleCard = page
			.locator( '.vkbm-menu-loop__item' )
			.filter( { hasText: `${ PREFIX } Single Plan` } )
			.first();
		await expect( singleCard ).toBeVisible( { timeout: 15000 } );
		await singleCard
			.locator( 'a, button' )
			.filter( { hasText: /予約/ } )
			.first()
			.click();
		await page.waitForSelector( '.vkbm-calendar', {
			state: 'visible',
			timeout: 15000,
		} );
		// @check 開催日が選ばれ、13:00 の時間枠が選ばれている
		await expect( dayButton( page, eventDay ) ).toHaveClass(
			/vkbm-calendar__day--selected/,
			{ timeout: 15000 }
		);
		await expect(
			page.locator( '.vkbm-slot-list__item[aria-pressed="true"]' )
		).toContainText( '13:00', { timeout: 15000 } );
	} );
} );
