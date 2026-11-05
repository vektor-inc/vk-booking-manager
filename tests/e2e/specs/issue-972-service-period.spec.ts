/**
 * サービスメニューの提供期間（開始日〜終了日）（#972）
 *
 * 検証内容:
 * 1. 編集画面の「提供期間」欄: 「予約可能日」の直上にあり、開始日が終了日より後だとエラー文が出る。
 *    正しい期間を入れて保存すると保存される。
 * 2. 予約ページ: 開始日が先のメニューを選ぶと、カレンダーが開始日の月から開き、期間の外の日は選べない。
 * 3. メニュー一覧: 期間中のメニューには「提供期間」が出て、終了済みのメニューは一覧に出ない。
 *
 * テストデータと後片付け:
 * - プランは固有の接頭辞（E2E972）を付けて作り、afterAll で接頭辞を手がかりに削除する。
 * - 期間は「来月の 10 日〜20 日」にする（global-setup が今月と来月のシフトを登録済みのため）。
 */
// @category 予約画面・カレンダー
import { test, expect } from '@playwright/test';
import {
	wpCliArgs,
	wpEvalPhp,
	loginAsAdmin,
	getStaffId,
	setAdminBlockEditorPreferences,
} from '../utils/helpers';

// テストデータの識別用接頭辞（他 spec のデータと区別する）。
const PREFIX = 'E2E972';

let summerMenuId = '';
let endedMenuId = '';
// 来月の 10 日・20 日（サイトのタイムゾーン。Y-m-d）と、来月の年月の表示用。
let periodStartIso = '';
let periodEndIso = '';
let endedIso = '';

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
 * このspecで作ったプランを接頭辞を手がかりに削除する。
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
 * プランを作る。
 *
 * @param title   プラン名
 * @param staffId 担当スタッフの post ID
 * @param meta    追加で保存する提供期間のメタ（PHP の配列リテラルの中身）
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

test.describe.serial( 'サービスメニューの提供期間（#972）', () => {
	test.beforeAll( () => {
		// 来月の 10 日・20 日と、昨日の日付（サイトのタイムゾーン）を求める。
		const dates = wpEvalPhp( `
			$tz   = wp_timezone();
			$next = new DateTimeImmutable( 'first day of next month', $tz );
			$yest = ( new DateTimeImmutable( 'today', $tz ) )->modify( '-1 day' );
			echo $next->format( 'Y-m-' ) . '10,' . $next->format( 'Y-m-' ) . '20,' . $yest->format( 'Y-m-d' );
		` )
			.trim()
			.split( ',' );
		[ periodStartIso, periodEndIso, endedIso ] = dates;

		// @step 担当スタッフのシフト（今月と来月）が登録されている状態で、期間を設定していないプラン「E2E972 Summer Plan」と、昨日で提供期間が終わったプラン「E2E972 Ended Plan」を作成しておく
		const staffId = getStaffId();
		summerMenuId = createMenu( `${ PREFIX } Summer Plan`, staffId, '' );
		endedMenuId = createMenu(
			`${ PREFIX } Ended Plan`,
			staffId,
			`'_vkbm_service_period_end' => '${ endedIso }'`
		);
	} );

	test.afterAll( () => {
		cleanupSeededData();
		// 幅 390px で編集画面を開くと、ブロックエディターが右の設定サイドバーを閉じ、その状態を admin の
		// persisted_preferences に保存する。残したままだと、後に走る spec で右サイドバーのメタボックス
		// （「スタッフ連携」など）が非表示になって落ちるため、エディターの設定を既定の状態へ戻す。
		// Opening the editor at 390px closes the settings sidebar and persists that state;
		// reset the preferences so later specs see the sidebar meta boxes.
		setAdminBlockEditorPreferences();
	} );

	// @title 編集画面の提供期間欄（逆転エラーと保存）
	test( '「予約可能日」の直上に提供期間欄があり、開始日が終了日より後だとエラーが出て、正しい期間は保存できる', async ( {
		page,
	} ) => {
		setAdminBlockEditorPreferences();
		// @step 管理者でログインし、「E2E972 Summer Plan」の編集画面を開く
		await loginAsAdmin( page );
		await page.goto(
			`/wp-admin/post.php?post=${ summerMenuId }&action=edit`
		);

		const field = page.locator( '#vkbm-service-period-field' );
		const startInput = page.locator(
			'#vkbm_service_menu_service_period_start'
		);
		const endInput = page.locator(
			'#vkbm_service_menu_service_period_end'
		);
		const error = page.locator( '#vkbm-service-period-error' );
		await field.waitFor( { state: 'attached', timeout: 15000 } );

		// @check 「提供期間」の欄が「予約可能日」の欄のすぐ上にある
		await expect( field ).toBeVisible();
		await expect(
			field.locator( 'xpath=following-sibling::tr[1]' )
		).toContainText( '予約可能日' );

		// @step 開始日に来月 20 日、終了日に来月 10 日（開始日より前）を入力する
		await startInput.fill( periodEndIso );
		await endInput.fill( periodStartIso );
		// @check 「終了日は開始日と同じか、それより後の日を選んでください。」が表示され、終了日が入力エラーの状態になる
		await expect( error ).toBeVisible();
		await expect( error ).toHaveText(
			'終了日は開始日と同じか、それより後の日を選んでください。'
		);
		await expect( endInput ).toHaveAttribute( 'aria-invalid', 'true' );
		await expect( endInput ).toHaveAttribute( 'min', periodEndIso );

		// @step 開始日を来月 10 日、終了日を来月 20 日に直す
		await startInput.fill( periodStartIso );
		await endInput.fill( periodEndIso );
		// @check エラーの文が消える
		await expect( error ).toBeHidden();
		await expect( endInput ).not.toHaveAttribute( 'aria-invalid', 'true' );

		// @step 終了日を昨日の日付にする
		await endInput.fill( endedIso );
		// @check 「この期間は終了しています。」の案内が出る
		await expect(
			page.locator( '#vkbm-service-period-ended' )
		).toBeVisible();
		await expect(
			page.locator( '#vkbm-service-period-ended' )
		).toContainText( 'この期間は終了しています。' );

		// @step 終了日を来月 20 日に戻し、更新ボタンを押して保存する
		await endInput.fill( periodEndIso );
		await page.locator( '.editor-post-publish-button' ).click();
		// @check 開始日・終了日が保存される
		await expect
			.poll(
				() =>
					wpCliArgs(
						[
							'post',
							'meta',
							'get',
							summerMenuId,
							'_vkbm_service_period_start',
						],
						{ stdio: 'pipe' }
					),
				{ timeout: 20000 }
			)
			.toBe( periodStartIso );
		expect(
			wpCliArgs(
				[
					'post',
					'meta',
					'get',
					summerMenuId,
					'_vkbm_service_period_end',
				],
				{ stdio: 'pipe' }
			)
		).toBe( periodEndIso );
	} );

	// @title 編集画面の提供期間欄（狭い幅で縦積み）
	test( '編集画面の「提供期間」欄は、幅 390px で開始日と終了日の左端がそろって縦に並ぶ', async ( {
		page,
	} ) => {
		// @step 幅 390px のスマートフォン幅で、管理者として「E2E972 Summer Plan」の編集画面を開く
		await page.setViewportSize( { width: 390, height: 844 } );
		await loginAsAdmin( page );
		await page.goto(
			`/wp-admin/post.php?post=${ summerMenuId }&action=edit`
		);
		await page
			.locator( '#vkbm-service-period-field' )
			.waitFor( { state: 'attached', timeout: 15000 } );
		const startBox = await page
			.locator( '#vkbm_service_menu_service_period_start' )
			.boundingBox();
		const endBox = await page
			.locator( '#vkbm_service_menu_service_period_end' )
			.boundingBox();
		// @check 開始日と終了日が2段に分かれ、左端の位置が同じ
		expect( startBox ).not.toBeNull();
		expect( endBox ).not.toBeNull();
		expect( endBox!.y ).toBeGreaterThan(
			startBox!.y + startBox!.height - 1
		);
		expect( Math.abs( endBox!.x - startBox!.x ) ).toBeLessThanOrEqual( 1 );
		// @check 横にはみ出して横スクロールが出ていない
		const overflow = await page.evaluate(
			() =>
				document.documentElement.scrollWidth -
				document.documentElement.clientWidth
		);
		expect( overflow ).toBeLessThanOrEqual( 0 );
	} );

	// @title 予約ページのカレンダー（期間外の日は選べない）
	test( '開始日が先のメニューを選ぶとカレンダーが開始日の月で開き、期間の外の日は選べない', async ( {
		page,
	} ) => {
		// @step 予約ページを「E2E972 Summer Plan」を選んだ状態で開く
		await page.goto( `/booking/?menu_id=${ summerMenuId }` );
		await page.waitForSelector( '.vkbm-calendar', {
			state: 'visible',
			timeout: 15000,
		} );
		await page
			.locator( '.vkbm-calendar__spinner' )
			.waitFor( { state: 'detached', timeout: 15000 } )
			.catch( () => undefined );

		// 来月の n 日のボタン（前後の月の日は除く）。
		const dayButton = ( day: number ) =>
			page
				.locator(
					'.vkbm-calendar__day:not(.vkbm-calendar__day--muted)'
				)
				.filter( {
					has: page.locator( '.vkbm-calendar__day-label', {
						hasText: new RegExp( `^${ day }$` ),
					} ),
				} );

		// @check カレンダーが来月（提供期間の開始日の月）で開いている
		const [ yearText, monthText ] = periodStartIso.split( '-' );
		await expect( page.locator( '.vkbm-calendar__current' ) ).toContainText(
			new RegExp(
				`${ yearText }.*${ Number( monthText ) }|${ Number(
					monthText
				) }.*${ yearText }`
			)
		);

		// @check 開始日の前日（9 日）は選べず、開始日（10 日）と終了日（20 日）は選べ、終了日の翌日（21 日）は選べない
		await expect( dayButton( 10 ) ).toBeEnabled( { timeout: 15000 } );
		await expect( dayButton( 20 ) ).toBeEnabled();
		await expect( dayButton( 9 ) ).toBeDisabled();
		await expect( dayButton( 21 ) ).toBeDisabled();

		// @step 開始日（10 日）を押す
		await dayButton( 10 ).click();
		// @check 時間枠の一覧が表示される
		await expect(
			page.locator( '.vkbm-slot-list button' ).first()
		).toBeVisible( { timeout: 15000 } );
	} );

	// @title メニュー一覧の提供期間表示と終了済みメニューの除外
	test( '期間を設定したメニューには「提供期間」が出て、終了済みのメニューは一覧に出ない', async ( {
		page,
	} ) => {
		// @step 予約ページを開く
		await page.goto( '/booking/' );
		// @check 「E2E972 Summer Plan」が一覧に出て、カードに「提供期間」の項目がある
		const summerCard = page
			.locator( '.vkbm-menu-loop__item' )
			.filter( { hasText: `${ PREFIX } Summer Plan` } );
		await expect( summerCard.first() ).toBeVisible( { timeout: 15000 } );
		await expect( summerCard.first() ).toContainText( '提供期間' );
		// @step 幅 390px のスマートフォン幅にする
		await page.setViewportSize( { width: 390, height: 844 } );
		// @check カードの「提供期間」の日付は、日付の途中で改行されない（各日付が1行のまま収まる）
		const dateParts = summerCard
			.first()
			.locator( '.vkbm-service-period__date' );
		await expect( dateParts ).toHaveCount( 2 );
		for ( let i = 0; i < 2; i++ ) {
			const lineCount = await dateParts.nth( i ).evaluate( ( el ) => {
				const lineHeight = parseFloat(
					getComputedStyle( el ).lineHeight
				);
				const rects = Array.from( el.getClientRects() );
				return Math.max(
					rects.length,
					Math.round( el.getBoundingClientRect().height / lineHeight )
				);
			} );
			expect( lineCount ).toBe( 1 );
		}
		await page.setViewportSize( { width: 1280, height: 720 } );
		// @check 昨日で提供期間が終わった「E2E972 Ended Plan」は一覧に出ない
		await expect(
			page
				.locator( '.vkbm-menu-loop__item' )
				.filter( { hasText: `${ PREFIX } Ended Plan` } )
		).toHaveCount( 0 );

		// @step 終了済みの「E2E972 Ended Plan」を指定したリンクで予約ページを開く
		await page.goto( `/booking/?menu_id=${ endedMenuId }` );
		// @check 「このメニューの提供期間は終了しています。別のメニューを選んでください。」の案内が出て、選べる日がない
		await expect(
			page.getByText(
				'このメニューの提供期間は終了しています。別のメニューを選んでください。'
			)
		).toBeVisible( { timeout: 15000 } );
		await expect(
			page.locator( '.vkbm-calendar__day--available' )
		).toHaveCount( 0 );
	} );

	// @title メニューを選んだあとのメニューのプルダウンから終了済みメニューを外す
	test( '通常のメニューを選んだあとも、「メニュー」のプルダウンに終了済みのメニューは出ない', async ( {
		page,
	} ) => {
		// @step 期間を設定していない「E2E972 Summer Plan」を指定したリンクで予約ページを開く
		await page.goto( `/booking/?menu_id=${ summerMenuId }` );
		const menuSelect = page.locator( 'select' ).filter( {
			has: page.locator( 'option', {
				hasText: `${ PREFIX } Summer Plan`,
			} ),
		} );
		await expect( menuSelect.first() ).toBeVisible( { timeout: 15000 } );
		// @check 選択中のメニューは選択肢に残り、昨日で提供期間が終わった「E2E972 Ended Plan」は選択肢に出ない
		await expect(
			menuSelect.first().locator( 'option', {
				hasText: `${ PREFIX } Summer Plan`,
			} )
		).toHaveCount( 1 );
		await expect(
			menuSelect.first().locator( 'option', {
				hasText: `${ PREFIX } Ended Plan`,
			} )
		).toHaveCount( 0 );
	} );

	// @title 管理画面のメニュー一覧の「提供期間／予約可能日」列（期間と予約可能日を1つの列に出し、指定がなければ出さず、日付が途中で割れない）
	test( '管理画面のサービスメニュー一覧の「提供期間／予約可能日」列は、期間と予約可能日をまとめて出し、指定がなければ出さず、幅 960px・1280px で日付が途中で割れず列からはみ出さない', async ( {
		page,
	} ) => {
		// @step 管理者としてログインする
		await loginAsAdmin( page );

		// @step 期間と予約可能日の両方があるプラン・予約可能日だけのプラン・どちらも無いプランを作る（「Ended Plan」は終了日だけ）
		const staffId = getStaffId();
		createMenu(
			`${ PREFIX } Both Plan`,
			staffId,
			`'_vkbm_service_period_start' => '${ periodStartIso }', '_vkbm_service_period_end' => '${ periodEndIso }', '_vkbm_reservation_day_type' => 'weekday'`
		);
		createMenu(
			`${ PREFIX } DayOnly Plan`,
			staffId,
			`'_vkbm_reservation_day_type' => 'weekend'`
		);
		createMenu( `${ PREFIX } Empty Plan`, staffId, '' );

		// 期待する行: タイトル、日付の数、予約可能日の文言（無ければ null）、ダッシュだけか
		const targets = [
			{
				title: `${ PREFIX } Both Plan`,
				dates: 2,
				dayLabel: '平日限定',
				dash: false,
			},
			{
				title: `${ PREFIX } DayOnly Plan`,
				dates: 0,
				dayLabel: '土日限定',
				dash: false,
			},
			{
				title: `${ PREFIX } Ended Plan`,
				dates: 1,
				dayLabel: null,
				dash: false,
			},
			{
				title: `${ PREFIX } Empty Plan`,
				dates: 0,
				dayLabel: null,
				dash: true,
			},
		];
		for ( const width of [ 960, 1280 ] ) {
			// @step 幅 ${ width }px でサービスメニューの一覧を開く
			await page.setViewportSize( { width, height: 720 } );
			await page.goto( '/wp-admin/edit.php?post_type=vkbm_service_menu' );
			await expect(
				page.locator( 'th#vkbm_reservation_day_type' )
			).toBeVisible( { timeout: 15000 } );
			// @check 「提供期間」の単独の列は無くなり、見出しは「提供期間」「予約可能日」の2行になる
			await expect(
				page.locator( 'th#vkbm_service_period' )
			).toHaveCount( 0 );
			const headerText = await page
				.locator( 'th#vkbm_reservation_day_type' )
				.evaluate( ( th ) =>
					th.innerHTML.replace( /<[^>]*sorted[^>]*>/g, '' )
				);
			expect( headerText ).toContain( '提供期間 <br>予約可能日' );

			for ( const target of targets ) {
				const row = page.locator( 'tbody#the-list tr' ).filter( {
					has: page.locator(
						`a.row-title:text-is("${ target.title }")`
					),
				} );
				const cell = row.locator(
					'td.column-vkbm_reservation_day_type'
				);
				const dateParts = cell.locator( '.vkbm-service-period__date' );
				await expect( dateParts ).toHaveCount( target.dates, {
					timeout: 15000,
				} );
				// @check 統合列に、期間があれば期間・予約可能日があれば予約可能日が出て、指定が無い側は出ない。両方無ければ「—」だけ（${ target.title }）
				if ( target.dayLabel ) {
					await expect( cell ).toContainText( target.dayLabel );
				} else if ( target.dates > 0 ) {
					await expect( cell ).not.toContainText( '平日限定' );
					await expect( cell ).not.toContainText( '土日限定' );
				}
				if ( target.dash ) {
					await expect( cell ).toHaveText( '—' );
				} else {
					await expect( cell ).not.toHaveText( '—' );
				}
				if ( target.title.endsWith( 'Both Plan' ) ) {
					// @check 期間と予約可能日の両方があるときは「開始日〜」「終了日」「予約可能日」の3行で、期間が上・予約可能日が下になる
					const lines = await cell.evaluate( ( td ) =>
						td.innerHTML
							.split( /<br\s*\/?>/ )
							.map( ( l ) => l.replace( /<[^>]*>/g, '' ).trim() )
					);
					expect( lines.length ).toBe( 3 );
					expect( lines[ 0 ] ).toMatch( /〜$/ );
					expect( lines[ 2 ] ).toBe( '平日限定' );
				}
				// @check 統合列の各日付は1行に収まり（日付の途中で改行されない）、列の右端を超えて隣の列に重ならない（${ target.title }）
				for ( let i = 0; i < target.dates; i++ ) {
					const measured = await dateParts
						.nth( i )
						.evaluate( ( el ) => {
							const lineHeight = parseFloat(
								getComputedStyle( el ).lineHeight
							);
							const rect = el.getBoundingClientRect();
							const cellRect = el
								.closest( 'td' )
								.getBoundingClientRect();
							return {
								lines: Math.max(
									el.getClientRects().length,
									Math.round( rect.height / lineHeight )
								),
								spill: rect.right - cellRect.right,
							};
						} );
					expect( measured.lines, `${ width }px` ).toBe( 1 );
					expect(
						measured.spill,
						`${ width }px`
					).toBeLessThanOrEqual( 0 );
				}
			}
		}
	} );
	// @title 管理画面のメニュー一覧の日付（英語表示でも列からはみ出さない）
	test( '管理者の言語が英語のとき、サービスメニュー一覧の統合列の日付は空白の位置で折り返し、幅 960px・1280px で列からはみ出さず隣の列に重ならない', async ( {
		page,
	} ) => {
		// @step 管理者としてログインする
		await loginAsAdmin( page );

		// @step 一番長い英語の日付（September 30, 2026）になる期間のプランを作り、日付の書式を「F j, Y」にして、管理者の表示言語だけを英語にする（サイトの言語は日本語のまま）
		const originalDateFormat = wpEvalPhp(
			`echo get_option( 'date_format' );`
		);
		createMenu(
			`${ PREFIX } English Plan`,
			getStaffId(),
			`'_vkbm_service_period_start' => '2026-09-30', '_vkbm_service_period_end' => '2027-09-30'`
		);
		try {
			wpEvalPhp( `update_option( 'date_format', 'F j, Y' ); echo 'ok';` );
			wpCliArgs( [
				'user',
				'meta',
				'update',
				'admin',
				'locale',
				'en_US',
			] );
			for ( const width of [ 960, 1280 ] ) {
				// @step 幅 ${ width }px でサービスメニューの一覧を開く
				await page.setViewportSize( { width, height: 720 } );
				await page.goto(
					'/wp-admin/edit.php?post_type=vkbm_service_menu'
				);
				const row = page.locator( 'tbody#the-list tr' ).filter( {
					has: page.locator(
						`a.row-title:text-is("${ PREFIX } English Plan")`
					),
				} );
				const cell = row.locator(
					'td.column-vkbm_reservation_day_type'
				);
				await expect(
					cell.locator( '.vkbm-service-period__date' )
				).toHaveCount( 2, { timeout: 15000 } );
				await expect( cell ).toContainText( 'September 30, 2026' );
				// @check 英語の日付は、列の右端を超えず（隣の列に重ならず）、空白の位置で折り返される（${ width }px）
				const spill = await cell
					.locator( '.vkbm-service-period__date' )
					.evaluateAll( ( els ) => {
						const cellRight = els[ 0 ]
							.closest( 'td' )
							.getBoundingClientRect().right;
						return els.map( ( el ) =>
							Math.max(
								...Array.from( el.getClientRects() ).map(
									( r ) => r.right - cellRight
								)
							)
						);
					} );
				for ( const value of spill ) {
					expect( value, `${ width }px` ).toBeLessThanOrEqual( 0 );
				}
			}
		} finally {
			wpCliArgs( [ 'user', 'meta', 'delete', 'admin', 'locale' ] );
			wpEvalPhp(
				`update_option( 'date_format', ${ JSON.stringify(
					originalDateFormat.trim()
				) } ); echo 'ok';`
			);
		}
	} );
	// @title 管理画面のメニュー一覧の片側だけの期間（「から」「まで」が途中で割れない）
	test( '開始日だけ・終了日だけの提供期間は、日付の書式が「Y年n月j日」でも「F j, Y」でも、「から」「まで」の語の途中で改行されない（幅 783px・960px・1280px）', async ( {
		page,
	} ) => {
		// @step 管理者としてログインする
		await loginAsAdmin( page );

		// @step 開始日だけのプランと終了日だけのプランを作る
		const staffId = getStaffId();
		createMenu(
			`${ PREFIX } From Plan`,
			staffId,
			`'_vkbm_service_period_start' => '2036-12-10'`
		);
		createMenu(
			`${ PREFIX } Until Plan`,
			staffId,
			`'_vkbm_service_period_end' => '2036-12-20'`
		);
		const originalDateFormat = wpEvalPhp(
			`echo get_option( 'date_format' );`
		);
		try {
			for ( const dateFormat of [ 'Y年n月j日', 'F j, Y' ] ) {
				// @step 日付の書式を「${ dateFormat }」にする
				wpEvalPhp(
					`update_option( 'date_format', ${ JSON.stringify(
						dateFormat
					) } ); echo 'ok';`
				);
				for ( const width of [ 783, 960, 1280 ] ) {
					// @step 幅 ${ width }px でサービスメニューの一覧を開く
					await page.setViewportSize( { width, height: 720 } );
					await page.goto(
						'/wp-admin/edit.php?post_type=vkbm_service_menu'
					);
					for ( const [ name, word ] of [
						[ 'From Plan', 'から' ],
						[ 'Until Plan', 'まで' ],
					] ) {
						const cell = page
							.locator( 'tbody#the-list tr' )
							.filter( {
								has: page.locator(
									`a.row-title:text-is("${ PREFIX } ${ name }")`
								),
							} )
							.locator( 'td.column-vkbm_reservation_day_type' );
						await expect(
							cell.locator( '.vkbm-service-period__date' )
						).toHaveCount( 1, { timeout: 15000 } );
						// @check 「${ word }」は1行に収まり、語の途中で改行されない（${ dateFormat }・${ width }px）
						const result = await cell.evaluate(
							( td, target ) => {
								const walker = document.createTreeWalker(
									td,
									NodeFilter.SHOW_TEXT
								);
								const tops: number[] = [];
								let found = false;
								let node = walker.nextNode();
								while ( node ) {
									const text = node.textContent || '';
									const at = text.indexOf( target );
									if ( at >= 0 ) {
										found = true;
										for (
											let i = 0;
											i < target.length;
											i++
										) {
											const range =
												document.createRange();
											range.setStart( node, at + i );
											range.setEnd( node, at + i + 1 );
											tops.push(
												Math.round(
													range.getBoundingClientRect()
														.top
												)
											);
										}
									}
									node = walker.nextNode();
								}
								return { found, tops };
							},
							word
						);
						expect( result.found ).toBe( true );
						expect(
							new Set( result.tops ).size,
							`${ dateFormat } ${ width }px ${ word }`
						).toBe( 1 );
						// @check 片側だけの期間には強制改行（br）が入らない（${ dateFormat }・${ width }px）。折り返しは日付と「${ word }」の境目だけに任せる
						await expect(
							cell.locator( '.vkbm-service-period-list br' )
						).toHaveCount( 0 );
					}
				}
			}
		} finally {
			wpEvalPhp(
				`update_option( 'date_format', ${ JSON.stringify(
					originalDateFormat.trim()
				) } ); echo 'ok';`
			);
		}
	} );
} );
