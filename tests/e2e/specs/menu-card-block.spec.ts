/**
 * PR #285 (issue #284): サービスカードブロック（vk-booking-manager/menu-card）の検証。
 *
 * 新ブロック「サービスカード」は、サービス詳細ページで「表示中のサービス」1件分の
 * カードを表示する動的ブロック（save=null + render_callback）。既存メニューループの
 * 単一カード描画（render_menu_card）を再利用する。
 *
 * 本テストは次のシナリオを検証する（PR 本文の確認手順に対応）:
 *   1. 自動表示: サービス詳細ページにブロックを置くと、表示中サービスのカードが1枚表示され、
 *      「詳細を見る」ボタンが出ず・「予約に進む」ボタンが出る。
 *   2. 手動固定: selectedMenuId 指定時、表示中ページに関係なく指定サービスのカードが出る。
 *   3. 表示項目トグル: showImage/showExcerpt/showMeta/showCategories の各 false で要素が消える。
 *   4. フォールバック: サービスでない固定ページに自動モード配置時、
 *        - ログアウト閲覧者には何も表示されない
 *        - サービス閲覧権限ユーザーには注意文が表示される
 *   6. 回帰: 既存メニューループブロックの表示（カードの並び・詳細/予約ボタン）が従来どおり。
 *
 * 注: ブロックは動的（save=null）なので、投稿本文にブロックコメントを直接埋め込めば
 * フロント表示時に render_callback が走る。ブロック挿入の Gutenberg UI 操作は不要。
 */
import { test, expect } from '@playwright/test';
import { wpEvalPhp, loginAsAdmin } from '../utils/helpers';

/**
 * トグル検証用のリッチなサービスメニュー（アイキャッチ・抜粋・タグ・料金・所要時間あり）を
 * 1件作成するヘルパー。既存があれば作り直す。作成した投稿IDを返す。
 *
 * Create (or recreate) one rich service menu post with featured image, excerpt,
 * tag, price and duration, so display-item toggles can be asserted.
 *
 * @return 作成したサービスメニューの投稿ID（数値文字列）
 */
function createRichServiceMenu(): string {
	const phpCode = `
		// 既存の検証用サービスを掃除（タイトル一致）。
		$existing = get_posts( array(
			'post_type'   => 'vkbm_service_menu',
			'post_status' => 'any',
			'title'       => 'Card Test Service',
			'fields'      => 'ids',
			'numberposts' => -1,
		) );
		foreach ( $existing as $eid ) {
			wp_delete_post( (int) $eid, true );
		}

		$menu_id = wp_insert_post( array(
			'post_type'    => 'vkbm_service_menu',
			'post_status'  => 'publish',
			'post_title'   => 'Card Test Service',
			'post_excerpt' => 'カードテスト用の抜粋テキストです。',
			'post_content' => '本文ダミー。',
		) );

		// 料金・所要時間メタ（render_meta_information が読む）。
		update_post_meta( $menu_id, '_vkbm_base_price', 5000 );
		update_post_meta( $menu_id, '_vkbm_duration_minutes', 60 );

		// サービスタグ（タクソノミー vkbm_service_menu_tag）。
		wp_set_object_terms( $menu_id, array( 'カードテストタグ' ), 'vkbm_service_menu_tag', false );

		// アイキャッチ画像（ダミー添付を生成して割り当て）。1x1 PNG を base64 から書き出す。
		$png_b64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
		$upload = wp_upload_bits( 'card-test-thumb.png', null, base64_decode( $png_b64 ) );
		if ( empty( $upload['error'] ) ) {
			$filetype = wp_check_filetype( $upload['file'], null );
			$attach_id = wp_insert_attachment( array(
				'post_mime_type' => $filetype['type'],
				'post_title'     => 'card-test-thumb',
				'post_status'    => 'inherit',
			), $upload['file'], $menu_id );
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$meta = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
			wp_update_attachment_metadata( $attach_id, $meta );
			set_post_thumbnail( $menu_id, $attach_id );
		}

		echo (int) $menu_id;
	`;
	const id = wpEvalPhp( phpCode ).trim();
	if ( ! /^\d+$/.test( id ) || Number( id ) <= 0 ) {
		throw new Error( `Failed to create rich service menu: "${ id }"` );
	}
	return id;
}

/**
 * 指定の投稿本文（ブロックコメント込み）で固定ページまたはサービスメニューを作成/更新するヘルパー。
 * post_name（スラッグ）で一意に管理し、再実行時は更新する。作成/更新した投稿IDを返す。
 *
 * @param postType   'page' または 'vkbm_service_menu'
 * @param slug       スラッグ（許可リストで検証）
 * @param title      タイトル
 * @param contentB64 本文（base64。PHP 側で復号して埋め込み脱出を防ぐ）
 * @return 投稿ID（数値文字列）
 */
function upsertPost(
	postType: 'page' | 'vkbm_service_menu',
	slug: string,
	title: string,
	contentB64: string
): string {
	// postType / slug を許可リスト・正規表現で検証（PHP リテラル脱出防止）。
	if ( postType !== 'page' && postType !== 'vkbm_service_menu' ) {
		throw new Error( `Disallowed post type: "${ postType }"` );
	}
	if ( ! /^[a-z0-9-]+$/.test( slug ) ) {
		throw new Error( `Disallowed slug: "${ slug }"` );
	}
	const titleB64 = Buffer.from( title ).toString( 'base64' );
	const phpCode = `
		$slug = '${ slug }';
		$existing = get_posts( array(
			'post_type'   => '${ postType }',
			'name'        => $slug,
			'post_status' => 'any',
			'fields'      => 'ids',
			'numberposts' => 1,
		) );
		$data = array(
			'post_type'    => '${ postType }',
			'post_status'  => 'publish',
			'post_name'    => $slug,
			'post_title'   => base64_decode( '${ titleB64 }' ),
			'post_content' => base64_decode( '${ contentB64 }' ),
		);
		if ( ! empty( $existing ) ) {
			$data['ID'] = (int) $existing[0];
			$id = wp_update_post( $data );
		} else {
			$id = wp_insert_post( $data );
		}
		echo (int) $id;
	`;
	const id = wpEvalPhp( phpCode ).trim();
	if ( ! /^\d+$/.test( id ) || Number( id ) <= 0 ) {
		throw new Error(
			`Failed to upsert ${ postType } "${ slug }": "${ id }"`
		);
	}
	return id;
}

/**
 * 投稿IDからフロントのパーマリンクを「相対URL」で取得するヘルパー。
 * baseURL ルール: 絶対URLをハードコードしない（page.goto には相対パスを渡す）。
 *
 * サービスメニュー CPT は rewrite=false のため、パーマリンクは
 * `/?vkbm_service_menu=<slug>` のようなクエリ文字列形式になる。
 * そのため path だけでなくクエリ文字列も保持して返す。
 *
 * @param postId 投稿ID（数値文字列）
 * @return サイトルートからの相対URL（path + query。例: /?vkbm_service_menu=card-test-service）
 */
function getRelativePermalink( postId: string ): string {
	if ( ! /^\d+$/.test( postId ) ) {
		throw new Error( `Invalid postId: "${ postId }"` );
	}
	const phpCode = `
		$url   = get_permalink( ${ Number.parseInt( postId, 10 ) } );
		$parts = wp_parse_url( $url );
		$path  = isset( $parts['path'] ) ? $parts['path'] : '/';
		$query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		echo $path . $query;
	`;
	const rel = wpEvalPhp( phpCode ).trim();
	if ( ! rel.startsWith( '/' ) ) {
		throw new Error( `Unexpected permalink: "${ rel }"` );
	}
	return rel;
}

/**
 * menu-card ブロックのブロックコメント文字列を生成するヘルパー。
 *
 * @param attrs ブロック属性（JSON シリアライズして埋め込む）
 * @return ブロックコメント文字列
 */
function menuCardBlock( attrs: Record< string, unknown > = {} ): string {
	const json = JSON.stringify( attrs );
	return `<!-- wp:vk-booking-manager/menu-card ${ json } /-->`;
}

test.describe( 'PR #285: サービスカードブロック', () => {
	let richMenuId: string;
	let richMenuPath: string;

	// 検証用のリッチなサービスメニューと、各シナリオ用の投稿を用意する。
	test.beforeAll( () => {
		richMenuId = createRichServiceMenu();

		// 1. 自動表示: サービス詳細ページ本文に menu-card（自動モード）を埋め込む。
		const autoContent = Buffer.from(
			menuCardBlock( { selectedMenuId: 0 } )
		).toString( 'base64' );
		// サービスメニュー詳細ページ自体に置く想定なので、richMenu の本文を更新する。
		const autoPhp = `
			wp_update_post( array(
				'ID'           => ${ Number.parseInt( richMenuId, 10 ) },
				'post_content' => base64_decode( '${ autoContent }' ),
			) );
			echo 'OK';
		`;
		expect( wpEvalPhp( autoPhp ) ).toContain( 'OK' );

		richMenuPath = getRelativePermalink( richMenuId );
	} );

	/**
	 * シナリオ1: サービス詳細ページに自動モードで配置 → 表示中サービスのカードが1枚。
	 * 詳細ボタンが出ず・予約ボタンが出る。
	 */
	test( '1. 自動表示: 詳細ページで表示中サービスのカード1枚・詳細ボタンなし・予約ボタンあり', async ( {
		page,
	} ) => {
		await page.goto( richMenuPath );
		await page.waitForLoadState( 'domcontentloaded' );

		// カードが1枚表示されている。
		const card = page.locator( '.vkbm-menu-card .vkbm-menu-loop__item' );
		await expect( card ).toHaveCount( 1 );

		// タイトルが表示中サービスのもの。
		await expect(
			card.locator( '.vkbm-menu-loop__card-title' )
		).toContainText( 'Card Test Service' );

		// 予約ボタンが出る。
		await expect(
			card.locator( '.vkbm-menu-loop__button--reserve' )
		).toHaveCount( 1 );

		// 詳細ボタン（primary, reserve でない方）が出ない。
		await expect(
			card.locator( '.vkbm-menu-loop__button.vkbm-button__primary' )
		).toHaveCount( 0 );
	} );

	/**
	 * シナリオ2: 別の固定ページに selectedMenuId 指定で配置 → 表示中ページに関係なく
	 * 指定サービスのカードが出る。
	 */
	test( '2. 手動固定: selectedMenuId 指定でそのサービスのカードが表示される', async ( {
		page,
	} ) => {
		const content = Buffer.from(
			menuCardBlock( { selectedMenuId: Number( richMenuId ) } )
		).toString( 'base64' );
		const pageId = upsertPost(
			'page',
			'card-manual-page',
			'Card Manual Page',
			content
		);
		const path = getRelativePermalink( pageId );

		await page.goto( path );
		await page.waitForLoadState( 'domcontentloaded' );

		const card = page.locator( '.vkbm-menu-card .vkbm-menu-loop__item' );
		await expect( card ).toHaveCount( 1 );
		await expect(
			card.locator( '.vkbm-menu-loop__card-title' )
		).toContainText( 'Card Test Service' );
	} );

	/**
	 * シナリオ3: 表示項目トグル。全 ON の固定ページと、画像/抜粋/メタ/タグを全 OFF にした
	 * 固定ページを比較し、要素の表示/非表示が連動することを確認する。
	 */
	test( '3. 表示項目トグル: 画像/抜粋/メタ/タグの ON/OFF が連動する', async ( {
		page,
	} ) => {
		// 全 ON（既定）。
		const onContent = Buffer.from(
			menuCardBlock( {
				selectedMenuId: Number( richMenuId ),
				showImage: true,
				showExcerpt: true,
				showMeta: true,
				showCategories: true,
			} )
		).toString( 'base64' );
		const onPageId = upsertPost(
			'page',
			'card-toggle-on',
			'Card Toggle On',
			onContent
		);
		const onPath = getRelativePermalink( onPageId );

		await page.goto( onPath );
		await page.waitForLoadState( 'domcontentloaded' );
		const onCard = page.locator( '.vkbm-menu-card' );
		// ON 時は各要素が存在する。
		await expect(
			onCard.locator( '.vkbm-menu-loop__card-media' )
		).toHaveCount( 1 );
		await expect(
			onCard.locator( '.vkbm-menu-loop__card-excerpt' )
		).toHaveCount( 1 );
		await expect(
			onCard.locator( '.vkbm-menu-loop__card-meta' )
		).not.toHaveCount( 0 );
		await expect(
			onCard.locator( '.vkbm-menu-loop__card-categories' )
		).toHaveCount( 1 );

		// 全 OFF。
		const offContent = Buffer.from(
			menuCardBlock( {
				selectedMenuId: Number( richMenuId ),
				showImage: false,
				showExcerpt: false,
				showMeta: false,
				showCategories: false,
			} )
		).toString( 'base64' );
		const offPageId = upsertPost(
			'page',
			'card-toggle-off',
			'Card Toggle Off',
			offContent
		);
		const offPath = getRelativePermalink( offPageId );

		await page.goto( offPath );
		await page.waitForLoadState( 'domcontentloaded' );
		const offCard = page.locator( '.vkbm-menu-card' );
		// OFF 時は各要素が消える。
		await expect(
			offCard.locator( '.vkbm-menu-loop__card-media' )
		).toHaveCount( 0 );
		await expect(
			offCard.locator( '.vkbm-menu-loop__card-excerpt' )
		).toHaveCount( 0 );
		await expect(
			offCard.locator( '.vkbm-menu-loop__card-meta' )
		).toHaveCount( 0 );
		await expect(
			offCard.locator( '.vkbm-menu-loop__card-categories' )
		).toHaveCount( 0 );
	} );

	/**
	 * シナリオ3b（不具合検出）: 「メタ情報を表示」を OFF にしても「予約に進む」ボタンは
	 * 独立して出続けるべき。サービスカードブロックは予約ボタンを既定表示・独立トグルとして
	 * 提供しているため、showMeta=false が showReserveButton を巻き込んで消してはならない。
	 *
	 * 既知の不具合: カード表示の予約/詳細ボタンは render_meta_information() の中で生成され、
	 * その呼び出しが showMeta ガードの内側にあるため、showMeta=false にすると予約ボタンも
	 * 道連れで消える（PR #285 で検出）。
	 */
	test( '3b. メタ非表示でも予約ボタンは独立して表示される（予約ボタンとメタの結合バグ検出）', async ( {
		page,
	} ) => {
		// メタだけ OFF、予約ボタンは既定 ON のまま。
		const content = Buffer.from(
			menuCardBlock( {
				selectedMenuId: Number( richMenuId ),
				showMeta: false,
				showReserveButton: true,
			} )
		).toString( 'base64' );
		const pageId = upsertPost(
			'page',
			'card-meta-off-reserve-on',
			'Card Meta Off Reserve On',
			content
		);
		const path = getRelativePermalink( pageId );

		await page.goto( path );
		await page.waitForLoadState( 'domcontentloaded' );

		const card = page.locator( '.vkbm-menu-card' );
		// メタ情報は消えている。
		await expect(
			card.locator( '.vkbm-menu-loop__card-meta' )
		).toHaveCount( 0 );
		// 予約ボタンは独立して出続けるべき（ここが現状 FAIL する）。
		await expect(
			card.locator( '.vkbm-menu-loop__button--reserve' )
		).toHaveCount( 1 );
	} );

	/**
	 * シナリオ4a: サービスでない固定ページに自動モード配置 → ログアウト閲覧者には何も出ない。
	 */
	test( '4a. フォールバック: 非サービスページ+自動モードはログアウト閲覧者に何も表示しない', async ( {
		browser,
	}, testInfo ) => {
		const content = Buffer.from(
			menuCardBlock( { selectedMenuId: 0 } )
		).toString( 'base64' );
		const pageId = upsertPost(
			'page',
			'card-fallback-page',
			'Card Fallback Page',
			content
		);
		const path = getRelativePermalink( pageId );

		// クリーンな（未ログイン）コンテキストで開く。
		// 手動コンテキストは playwright.config の use.baseURL を引き継がないため、
		// 相対パスの goto が解決できるよう baseURL を明示する。
		// A manually created context does not inherit use.baseURL from
		// playwright.config, so pass baseURL explicitly to resolve relative goto.
		const context = await browser.newContext( {
			baseURL: testInfo.project.use.baseURL,
		} );
		const page = await context.newPage();
		await page.goto( path );
		await page.waitForLoadState( 'domcontentloaded' );

		// カードも注意文も一切表示されない。
		await expect( page.locator( '.vkbm-menu-card' ) ).toHaveCount( 0 );
		await expect( page.locator( '.vkbm-menu-card--notice' ) ).toHaveCount(
			0
		);
		await context.close();
	} );

	/**
	 * シナリオ4b: 同じページを管理者（サービス閲覧権限あり）で開くと注意文が出る。
	 */
	test( '4b. フォールバック: サービス閲覧権限ユーザーには注意文が表示される', async ( {
		page,
	} ) => {
		// 4a で作成済みのページを使う（未作成なら作る）。
		const content = Buffer.from(
			menuCardBlock( { selectedMenuId: 0 } )
		).toString( 'base64' );
		const pageId = upsertPost(
			'page',
			'card-fallback-page',
			'Card Fallback Page',
			content
		);
		const path = getRelativePermalink( pageId );

		await loginAsAdmin( page );
		await page.goto( path );
		await page.waitForLoadState( 'domcontentloaded' );

		// 注意文（vkbm-menu-card--notice）が表示される。
		await expect( page.locator( '.vkbm-menu-card--notice' ) ).toHaveCount(
			1
		);
		await expect( page.locator( '.vkbm-menu-card--notice' ) ).toContainText(
			/サービス|service/i
		);
	} );

	/**
	 * シナリオ6: 回帰。既存メニューループブロックが従来どおり表示される
	 *（カードが並び、予約ボタンが出る）。
	 */
	test( '6. 回帰: メニューループブロックが従来どおり表示される', async ( {
		page,
	} ) => {
		// menu-loop は loopId 必須（未設定だと注意文を出すのが従来仕様）。
		// loopId を指定すればサービスメニュー全件がカードとして並ぶ。
		const content = Buffer.from(
			'<!-- wp:vk-booking-manager/menu-loop {"loopId":"regression"} /-->'
		).toString( 'base64' );
		const pageId = upsertPost(
			'page',
			'menu-loop-regression',
			'Menu Loop Regression',
			content
		);
		const path = getRelativePermalink( pageId );

		await page.goto( path );
		await page.waitForLoadState( 'domcontentloaded' );

		// メニューループのリスト（注意文ではない通常表示）が存在する。
		const loop = page.locator(
			'.vkbm-menu-loop:not(.vkbm-menu-loop--notice)'
		);
		await expect( loop.first() ).toBeVisible();
		// 注意文（ID未設定）が出ていないこと。
		await expect( page.locator( '.vkbm-menu-loop__empty' ) ).toHaveCount(
			0
		);
		// カードが1件以上並ぶ（実 DOM の item 要素）。
		const items = loop.locator( '.vkbm-menu-loop__item' );
		expect( await items.count() ).toBeGreaterThan( 0 );
		// 予約ボタンが少なくとも1つ存在する（従来挙動）。
		expect(
			await loop.locator( '.vkbm-menu-loop__button--reserve' ).count()
		).toBeGreaterThan( 0 );
	} );

	/**
	 * シナリオ6b（回帰・スコープ確認）: menu-loop は「メタOFFで予約ボタンも消える」従来挙動の
	 * ままであること。BUG2 の修正は menu-card 限定で menu-loop に波及していないことを確認する。
	 */
	test( '6b. 回帰: menu-loop はメタOFF時に予約ボタンも消える（従来挙動が維持されている）', async ( {
		page,
	} ) => {
		const content = Buffer.from(
			'<!-- wp:vk-booking-manager/menu-loop {"loopId":"regression","showMeta":false} /-->'
		).toString( 'base64' );
		const pageId = upsertPost(
			'page',
			'menu-loop-meta-off',
			'Menu Loop Meta Off',
			content
		);
		const path = getRelativePermalink( pageId );

		await page.goto( path );
		await page.waitForLoadState( 'domcontentloaded' );

		const loop = page.locator(
			'.vkbm-menu-loop:not(.vkbm-menu-loop--notice)'
		);
		await expect( loop.first() ).toBeVisible();
		expect(
			await loop.locator( '.vkbm-menu-loop__item' ).count()
		).toBeGreaterThan( 0 );
		// メタOFF時は予約ボタンも出ない（menu-loop の従来挙動。menu-card との差分）。
		await expect(
			loop.locator( '.vkbm-menu-loop__button--reserve' )
		).toHaveCount( 0 );
	} );
} );
