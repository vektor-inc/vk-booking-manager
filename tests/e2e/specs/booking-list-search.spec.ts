import { test, expect } from '@playwright/test';
import { wpEvalPhp, loginAsAdmin } from '../utils/helpers';

/**
 * 列幅を超える空白なしの名前は、全ての文字をセル内に折り返す。
 */
test( '長い日本語名と英数字アカウント名がPCとモバイルで欠けない', async ( {
	page,
}, testInfo ) => {
	const name = '山田太郎'.repeat( 8 );
	const account = 'LongAccount0123456789'.repeat( 2 );
	// 名前を持たない会員のログイン名を、予約アカウントの補足として表示する。
	// @step 名前の無い会員（ログイン名は長い英数字）と、その会員の予約（予約者名は「山田太郎」を8回繰り返した長い名前）を登録しておく
	const fixture = JSON.parse(
		wpEvalPhp( `
		$user = wp_insert_user(array('user_login' => '${ account }', 'user_pass' => wp_generate_password(), 'role' => 'subscriber'));
		$id = wp_insert_post(array('post_type' => 'vkbm_booking', 'post_status' => 'publish', 'post_author' => $user, 'post_title' => 'longname891'));
		update_post_meta($id, '_vkbm_booking_customer_name', '${ name }');
		echo wp_json_encode(array('user' => $user, 'id' => $id));
	` )
	);
	try {
		// @step 管理者でログインし、予約一覧で「longname891」を検索する
		await loginAsAdmin( page );
		await page.goto(
			'/wp-admin/edit.php?post_type=vkbm_booking&s=longname891'
		);
		const row = page.locator( `#post-${ fixture.id }` );
		// @step 画面幅を PC（1440px）とスマホ（375px）に変えて表示する（スマホでは行の詳細を開く）
		for ( const width of [ 1440, 375 ] ) {
			await page.setViewportSize( { width, height: 1000 } );
			if ( width === 375 ) {
				await row.locator( '.toggle-row' ).click();
			}
			for ( const [ kind, expected ] of [
				[ 'name', name ],
				[ 'account', `予約アカウント: ${ account }` ],
			] ) {
				// @check 予約者名と「予約アカウント: （ログイン名）」が全文表示され、セルからはみ出さずに複数行に折り返されている
				const element = row.locator(
					`.vkbm-booking-customer-column__${ kind }`
				);
				await expect( element ).toBeVisible();
				await expect( element ).toHaveText( expected );
				// 全文字の描画矩形を測り、セルやoverflowで切られる祖先からの逸脱を検出する。
				const layout = await element.evaluate( ( el ) => {
					const cell = el.closest( 'td' )!.getBoundingClientRect();
					const bounds = el.getBoundingClientRect();
					const text = el.firstChild!;
					const rects = Array.from( text.textContent!, ( _, i ) => {
						const range = document.createRange();
						range.setStart( text, i );
						range.setEnd( text, i + 1 );
						return range.getBoundingClientRect();
					} );
					const clips = [ cell, bounds ];
					for (
						let parent = el.parentElement;
						parent;
						parent = parent.parentElement
					) {
						if (
							/(hidden|clip|auto|scroll)/.test(
								getComputedStyle( parent ).overflow
							)
						) {
							clips.push( parent.getBoundingClientRect() );
						}
					}
					return {
						contained: rects.every( ( rect ) =>
							clips.every(
								( clip ) =>
									rect.left >= clip.left - 1 &&
									rect.right <= clip.right + 1 &&
									rect.top >= clip.top - 1 &&
									rect.bottom <= clip.bottom + 1
							)
						),
						lines: new Set( rects.map( ( rect ) => rect.top ) )
							.size,
					};
				} );
				expect( layout.contained, `${ width }px ${ kind }` ).toBe(
					true
				);
				expect( layout.lines ).toBeGreaterThan( 1 );
			}
			// @check ページが横にはみ出していない
			expect(
				await page.evaluate(
					() => document.documentElement.scrollWidth
				)
			).toBeLessThanOrEqual( width );
			await page.screenshot( {
				path: testInfo.outputPath( `long-names-${ width }.png` ),
				fullPage: true,
			} );
		}
	} finally {
		// 作成した予約と会員だけを片付ける。
		wpEvalPhp( `
			wp_delete_post(${ Number( fixture.id ) }, true);
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user(${ Number( fixture.user ) });
		` );
	}
} );

/**
 * 予約一覧で連絡先・予約者を検索し、きょうだいを重複なく表示する。
 */
test( '電話番号とユーザー名で予約を探し、予約者名列と件数を確認する', async ( {
	page,
}, testInfo ) => {
	test.setTimeout( 90000 );
	await page.setViewportSize( { width: 1440, height: 1000 } );
	// 保存氏名とアカウント名が異なるきょうだい予約を用意する。
	const fixture = JSON.parse(
		// @step 名前「会員 次郎」の会員と、その会員の予約2件（予約者名「歯科太郎」「歯科花子」・電話 09011112222）、会員でない予約1件（予約者名「山田090」・電話 08033334444）を登録しておく
		wpEvalPhp( `
		$login = 'search891_' . wp_generate_password(8, false);
		$user = wp_insert_user(array('user_login' => $login, 'user_pass' => wp_generate_password(), 'role' => 'subscriber', 'last_name' => '会員', 'first_name' => '次郎'));
		$ids = array();
		foreach (array('歯科太郎', '歯科花子', '山田090') as $index => $name) {
			$id = wp_insert_post(array('post_type' => 'vkbm_booking', 'post_status' => 'publish', 'post_author' => $index < 2 ? $user : 0, 'post_title' => $name));
			update_post_meta($id, '_vkbm_booking_customer_name', $name);
			update_post_meta($id, '_vkbm_booking_customer_tel', $index < 2 ? '09011112222' : '08033334444');
			$ids[] = $id;
		}
		echo wp_json_encode(array('user' => $user, 'login' => $login, 'ids' => $ids));
	` )
	);
	try {
		// @step 管理者でログインし、予約一覧を開く
		await loginAsAdmin( page );
		await page.goto( '/wp-admin/edit.php?post_type=vkbm_booking' );
		// @check 検索欄の下に「ユーザー名」を含む検索の案内が表示され、検索欄と結び付いている
		await expect(
			page.locator( '.vkbm-booking-search-help' )
		).toContainText( 'ユーザー名' );
		await expect( page.locator( '#post-search-input' ) ).toHaveAttribute(
			'aria-describedby',
			/(?:^|\s)vkbm-booking-search-help(?:\s|$)/
		);
		await expect(
			page.locator( '.search-box + #vkbm-booking-search-help' )
		).toBeVisible();
		// 標準の検索フォームからハイフン付きの電話番号を入力する。
		for ( const keyword of [
			'09011112222',
			'090-1111-2222',
			'090 1111 2222',
			'(090) 1111 2222',
			'090　1111　2222',
			'０９０　１１１１　２２２２',
			'０９０－１１１１－２２２２',
			'歯科 09011112222',
			fixture.login,
		] ) {
			// @step 検索欄に電話番号をいろいろな書き方（ハイフン・空白・かっこ・全角）や「歯科 09011112222」、会員のログイン名で入れて検索する
			await page.locator( '#post-search-input' ).fill( keyword );
			await page.locator( '#search-submit' ).click();
			// @check どの書き方でも「歯科太郎」「歯科花子」の2件だけが、「予約アカウント: 会員 次郎」と電話番号「090-1111-2222」付きで表示され、「予約者名」列の見出しと件数 2 が出る
			await expect( page.locator( '#post-search-input' ) ).toHaveValue(
				keyword
			);
			for ( const [ index, id ] of fixture.ids.slice( 0, 2 ).entries() ) {
				const row = page.locator( `#post-${ id }` );
				await expect( row ).toBeVisible();
				await expect(
					row.locator( '.vkbm-booking-customer-column__name' )
				).toHaveText( [ '歯科太郎', '歯科花子' ][ index ] );
				await expect(
					row.locator( '.vkbm-booking-customer-column__account' )
				).toHaveText( '予約アカウント: 会員 次郎' );
				await expect(
					row.locator( '.vkbm-booking-customer-column__tel' )
				).toContainText( '090-1111-2222' );
			}
			await expect(
				page.locator( `#post-${ fixture.ids[ 2 ] }` )
			).toHaveCount( 0 );
			await expect( page.locator( '#the-list tr' ) ).toHaveCount( 2 );
			await expect(
				page.locator( '.displaying-num' ).first()
			).toContainText( '2' );
			await expect( page.locator( '#vkbm_booking_reserver' ) ).toHaveText(
				'予約者名'
			);
		}
		// 日本語と数字が混ざる場合は、電話番号に変換せず各語を AND 照合する。
		for ( const [ keyword, count ] of [
			[ '山田 090', 1 ],
			[ '山田 080', 0 ],
		] as const ) {
			// @step 「山田 090」「山田 080」で検索する
			await page.locator( '#post-search-input' ).fill( keyword );
			await page.locator( '#search-submit' ).click();
			// @check 「山田 090」は1件、「山田 080」は0件になる
			await expect(
				page.locator( '#the-list tr[id^="post-"]' )
			).toHaveCount( count );
		}
		// @step 「09011112222」で検索する
		await page.goto(
			'/wp-admin/edit.php?post_type=vkbm_booking&s=09011112222'
		);
		// 補足行は標準サイズを保ち、主要な名前だけを強調する。
		const first = page.locator( `#post-${ fixture.ids[ 0 ] }` );
		// @check 予約者名だけが太字で、電話番号と予約アカウントの行は標準サイズ（13px）の灰色になっている
		await expect(
			first.locator( '.vkbm-booking-customer-column__name' )
		).toHaveCSS( 'font-weight', '600' );
		for ( const kind of [ 'tel', 'account' ] ) {
			await expect(
				first.locator( `.vkbm-booking-customer-column__${ kind }` )
			).toHaveCSS( 'color', 'rgb(80, 87, 94)' );
			await expect(
				first.locator( `.vkbm-booking-customer-column__${ kind }` )
			).toHaveCSS( 'font-size', '13px' );
		}
		// 見える字面の間隔を測り、セル内余白が行間以上か確認する。
		// @check 予約者名の欄の上下の余白が、行と行の間隔以上ある
		const spacing = await first
			.locator( '.column-vkbm_booking_reserver' )
			.evaluate( ( cell ) => {
				const rows = Array.from( cell.children ).map( ( row ) => {
					const style = getComputedStyle( row );
					const rect = row.getBoundingClientRect();
					const leading =
						( parseFloat( style.lineHeight ) -
							parseFloat( style.fontSize ) ) /
						2;
					return {
						top: rect.top + leading,
						bottom: rect.bottom - leading,
					};
				} );
				const rect = cell.getBoundingClientRect();
				return {
					gaps: rows
						.slice( 1 )
						.map( ( row, i ) => row.top - rows[ i ].bottom ),
					top: rows[ 0 ].top - rect.top,
					bottom: rect.bottom - rows[ rows.length - 1 ].bottom,
				};
			} );
		for ( const gap of spacing.gaps ) {
			expect( spacing.top ).toBeGreaterThanOrEqual( gap );
			expect( spacing.bottom ).toBeGreaterThanOrEqual( gap );
		}
		console.log( '予約者名列の字面間隔(px):', spacing );
		// 各語の文字が同じ行にあり、電話番号も途中で分断されないことを実測する。
		const checkWordWrapping = async () => {
			for ( const kind of [ 'name', 'tel', 'account' ] ) {
				const layout = await first
					.locator( `.vkbm-booking-customer-column__${ kind }` )
					.evaluate( ( element ) => {
						const text = element.firstChild!;
						const words = ( text.textContent || '' ).matchAll(
							/\S+/g
						);
						const lines = Array.from( words, ( word ) => {
							const range = document.createRange();
							range.setStart( text, word.index! );
							range.setEnd(
								text,
								word.index! + word[ 0 ].length
							);
							return range.getClientRects().length;
						} );
						return {
							lines,
							width: element.scrollWidth,
							available: element.clientWidth,
						};
					} );
				expect( layout.lines.every( ( lines ) => lines === 1 ) ).toBe(
					true
				);
				expect( layout.width ).toBeLessThanOrEqual( layout.available );
			}
		};
		// @check 名前・電話番号・予約アカウントの各語が途中で折り返されず、欄からはみ出さない
		await checkWordWrapping();
		await page.screenshot( {
			path: testInfo.outputPath( 'desktop.png' ),
			fullPage: true,
		} );
		// 狭い画面でも案内が検索欄に隣接し、画面からはみ出さない。
		// @step 画面幅をスマホ（375px）にして、1件目の行の詳細を開く
		await page.setViewportSize( { width: 375, height: 812 } );
		// @check 検索の案内が検索欄のすぐ後ろに画面からはみ出さずに表示され、予約アカウントの行が表示され、各語が途中で折り返されない
		await expect(
			page.locator( '.search-box + #vkbm-booking-search-help' )
		).toBeVisible();
		const help = await page
			.locator( '#vkbm-booking-search-help' )
			.boundingBox();
		expect( help!.x + help!.width ).toBeLessThanOrEqual( 375 );
		await first.locator( '.toggle-row' ).click();
		await expect(
			first.locator( '.vkbm-booking-customer-column__account' )
		).toBeVisible();
		await checkWordWrapping();
		await page.screenshot( {
			path: testInfo.outputPath( 'mobile.png' ),
			fullPage: true,
		} );
		// ロールを外すと、保存電話で探せても会員名は補足しない。
		// @step 管理画面で会員「会員 次郎」の権限グループを外しておき、もう一度「09011112222」で検索する
		wpEvalPhp(
			`(new WP_User(${ Number( fixture.user ) }))->set_role('');`
		);
		await page.goto(
			'/wp-admin/edit.php?post_type=vkbm_booking&s=09011112222'
		);
		// @check 予約アカウントの行が表示されない
		await expect(
			page.locator(
				`#post-${ fixture.ids[ 0 ] } .vkbm-booking-customer-column__account`
			)
		).toHaveCount( 0 );
	} finally {
		// このspecで作成したデータだけを片付ける。
		wpEvalPhp( `
			foreach (array(${ fixture.ids
				.map( Number )
				.join( ', ' ) }) as $id) { wp_delete_post((int) $id, true); }
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user(${ Number( fixture.user ) });
		` );
	}
} );

/**
 * アカウント照合の上限を超えたら、保存情報への切り替えを見出しの下に通知する。
 */
test( 'アカウント一致の上限超過を警告し、保存情報の検索結果を残す', async ( {
	page,
} ) => {
	test.setTimeout( 90000 );
	// 実際の上限を超える会員と、保存情報だけで一致する予約を用意する。
	// @step ログイン名が同じ文字列で始まる会員を 101 人と、予約者名がその文字列の予約、その会員の1人が予約した予約者名なしの予約を登録しておく
	const fixture = JSON.parse(
		wpEvalPhp( `
		$prefix = 'overflow891_' . wp_generate_password(8, false);
		$users = array();
		for ($i = 0; $i < 101; $i++) {
			$users[] = wp_insert_user(array('user_login' => $prefix . '_' . $i, 'role' => 'subscriber', 'user_pass' => wp_generate_password()));
		}
		$saved = wp_insert_post(array('post_type' => 'vkbm_booking', 'post_status' => 'publish', 'post_title' => $prefix));
		$account = wp_insert_post(array('post_type' => 'vkbm_booking', 'post_status' => 'publish', 'post_title' => 'Account only', 'post_author' => $users[0]));
		echo wp_json_encode(array('prefix' => $prefix, 'users' => $users, 'saved' => $saved, 'account' => $account));
	` )
	);
	try {
		// @step 管理者でログインし、予約一覧でその文字列を検索する
		await loginAsAdmin( page );
		await page.goto(
			`/wp-admin/edit.php?post_type=vkbm_booking&s=${ fixture.prefix }`
		);
		// @check 「アカウント情報での検索を省略しました」の警告に「予約に保存された情報での検索結果を表示しています。」「名前やメールアドレスなどを詳しく入力して、再検索してください。」が表示される
		const notice = page
			.locator( '.notice.notice-warning' )
			.filter( { hasText: 'アカウント情報での検索を省略しました' } );
		await expect( notice ).toContainText(
			'予約に保存された情報での検索結果を表示しています。'
		);
		await expect( notice ).toContainText(
			'名前やメールアドレスなどを詳しく入力して、再検索してください。'
		);
		// @check 予約者名で一致した予約は表示され、会員のアカウントでしか一致しない予約は表示されない
		await expect(
			page.locator( `#post-${ fixture.saved }` )
		).toBeVisible();
		await expect(
			page.locator( `#post-${ fixture.account }` )
		).toHaveCount( 0 );
		// @check 警告が一覧の見出しより下に表示される
		const heading = await page
			.locator( '.wp-heading-inline' )
			.boundingBox();
		const warning = await notice.boundingBox();
		expect( warning!.y ).toBeGreaterThan( heading!.y + heading!.height );
	} finally {
		// 自分が追加した予約と会員だけを削除する。
		wpEvalPhp( `
			wp_delete_post(${ Number( fixture.saved ) }, true);
			wp_delete_post(${ Number( fixture.account ) }, true);
			require_once ABSPATH . 'wp-admin/includes/user.php';
			foreach (array(${ fixture.users
				.map( Number )
				.join( ',' ) }) as $id) { wp_delete_user($id); }
		` );
	}
} );

/**
 * 初期 HTML だけでも案内を読め、読み込み後に位置が動かないことを確かめる。
 */
for ( const scenario of [
	{ name: '通常', javaScriptEnabled: true, width: 1280, blockScripts: false },
	{ name: '375px', javaScriptEnabled: true, width: 375, blockScripts: false },
	{
		name: '375px・JS無効',
		javaScriptEnabled: false,
		width: 375,
		blockScripts: false,
	},
	{
		name: '375px・JS読込失敗',
		javaScriptEnabled: true,
		width: 375,
		blockScripts: true,
	},
] ) {
	test( `検索案内が初期HTMLで関連付く（${ scenario.name }）`, async ( {
		browser,
	}, testInfo ) => {
		// ログイン後のセッションだけを引き継いで、JS無効時も同じ画面を開く。
		// @step 管理者でログインし、ブラウザの条件（通常／375px 幅／375px 幅で JavaScript 無効／375px 幅でスクリプトの読み込み失敗）を変えて、予約一覧で一致しない語「missing891help」を検索する
		const auth = await browser.newContext();
		const login = await auth.newPage();
		await loginAsAdmin( login );
		const context = await browser.newContext( {
			storageState: await auth.storageState(),
			javaScriptEnabled: scenario.javaScriptEnabled,
			viewport: { width: scenario.width, height: 900 },
		} );
		await auth.close();
		try {
			const page = await context.newPage();
			if ( scenario.blockScripts ) {
				await page.route( '**/*', ( route ) =>
					route.request().resourceType() === 'script'
						? route.abort()
						: route.continue()
				);
			}
			// 検索結果0件でも入力欄と案内が出る条件を使う。
			const response = await page.goto(
				'/wp-admin/edit.php?post_type=vkbm_booking&s=missing891help'
			);
			// @check 最初に読み込まれる画面の時点で、検索欄の直後に検索の案内があり、検索欄と結び付いている
			const html = await response!.text();
			expect( html ).toMatch(
				/<input(?=[^>]+id="post-search-input")(?=[^>]+aria-describedby="vkbm-booking-search-help")[^>]*>/
			);
			expect( html ).toMatch(
				/<\/p>\s*<p id="vkbm-booking-search-help"/
			);
			// @check 検索の案内が検索欄のすぐ後ろに表示され、ページの読み込みが終わっても位置が動かず、画面からはみ出さない
			const help = page.locator(
				'.search-box + #vkbm-booking-search-help'
			);
			await expect( help ).toBeVisible();
			await expect(
				page.locator( '#post-search-input' )
			).toHaveAttribute( 'aria-describedby', 'vkbm-booking-search-help' );
			const before = await help.boundingBox();
			await page.waitForLoadState( 'load' );
			expect( await help.boundingBox() ).toEqual( before );
			expect( before!.x ).toBeGreaterThanOrEqual( 0 );
			expect( before!.x + before!.width ).toBeLessThanOrEqual(
				scenario.width
			);
			await page.screenshot( {
				path: testInfo.outputPath( 'search-help.png' ),
				fullPage: true,
			} );
		} finally {
			await context.close();
		}
	} );
}
