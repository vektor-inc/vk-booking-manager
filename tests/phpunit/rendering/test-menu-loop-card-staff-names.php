<?php
/**
 * メニューループ（カード）の担当リソース名表示と、問い合わせ回数のテスト（#706）。
 *
 * 個別選択の担当リソース名は、カードごとに get_posts() で引かず、公開中リソースIDの一覧
 * （Service_Menu_Post_Type::get_published_resource_ids()）を描画前にまとめてキャッシュへ載せて
 * 引く。表示結果（公開中のみ・menu_order → タイトルの並び・空タイトル除外）は従来と同じで、
 * メニューの件数に比例して問い合わせが増えないことを確認する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Frontend;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Blocks\Menu_Loop_Block;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_UnitTestCase;

/**
 * 個別選択の担当リソース名の表示と、問い合わせ回数を検証するテストクラス。
 *
 * @group blocks
 * @group staff
 */
class Menu_Loop_Card_Staff_Names_Test extends WP_UnitTestCase {

	/**
	 * テスト対象のブロックインスタンス。
	 *
	 * @var Menu_Loop_Block
	 */
	private $block;

	/**
	 * テスト前の全体設定（option）を退避する。
	 *
	 * @var mixed
	 */
	private $original_settings = false;

	/**
	 * 計測中に発行された投稿テーブルへの SELECT の数。
	 *
	 * @var int
	 */
	private $posts_select_count = 0;

	/**
	 * 各テスト前にブロックを用意し、指名機能をサイト全体で有効にする。
	 */
	public function set_up(): void {
		parent::set_up();

		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '指名（担当リソース名の表示）は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$this->block             = new Menu_Loop_Block();
		$this->original_settings = get_option( Settings_Repository::OPTION_KEY, false );

		$repository                = new Settings_Repository();
		$settings                  = $repository->get_settings();
		$settings['staff_enabled'] = true;
		update_option( Settings_Repository::OPTION_KEY, $settings );
		Staff_Editor::clear_nomination_enabled_cache();
		Service_Menu_Post_Type::clear_published_resource_ids_cache();
	}

	/**
	 * 全体設定・フィルター・静的キャッシュをテスト前の状態へ戻す。
	 */
	public function tear_down(): void {
		remove_filter( 'query', array( $this, 'count_posts_select' ) );
		remove_all_filters( 'vkbm_menu_assignable_staff_ids' );
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		Staff_Editor::clear_nomination_enabled_cache();
		Service_Menu_Post_Type::clear_published_resource_ids_cache();
		parent::tear_down();
	}

	/**
	 * リソースを作成する。
	 *
	 * @param string $title      リソース名。
	 * @param int    $menu_order 並び順。
	 * @param string $status     投稿ステータス。
	 * @return int 作成したリソースの投稿ID。
	 */
	private function create_resource( string $title, int $menu_order, string $status = 'publish' ): int {
		return (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => $status,
				'post_title'  => $title,
				'menu_order'  => $menu_order,
			)
		);
	}

	/**
	 * 個別選択の担当リソースを持つサービスメニューを作成する。
	 *
	 * @param string     $title     メニュー名。
	 * @param array<int> $staff_ids 担当リソースID（保存順）。
	 * @return int 作成したサービスメニューの投稿ID。
	 */
	private function create_menu( string $title, array $staff_ids ): int {
		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
		update_post_meta( $menu_id, '_vkbm_base_price', 5000 );
		update_post_meta( $menu_id, '_vkbm_staff_ids', $staff_ids );

		return $menu_id;
	}

	/**
	 * カードに表示された担当リソース名の文字列を取り出す。
	 *
	 * @param string $html  カードのHTML。
	 * @param string $label 項目名（リソースラベル）。
	 * @return string 担当リソース名（項目が無ければ空文字）。
	 */
	private function extract_staff_names( string $html, string $label ): string {
		$pattern = '#<dt>' . preg_quote( $label, '#' ) . '</dt><dd>(.*?)</dd>#u';
		if ( 1 === preg_match( $pattern, $html, $matches ) ) {
			return html_entity_decode( $matches[1] );
		}

		return '';
	}

	/**
	 * 担当リソース名の項目名（設定のリソースラベル）を返す。
	 *
	 * @return string 項目名。
	 */
	private function get_label(): string {
		$method = new \ReflectionMethod( Menu_Loop_Block::class, 'get_resource_label_menu' );
		$method->setAccessible( true );

		return (string) $method->invoke( $this->block );
	}

	/**
	 * 並び順（menu_order → タイトル）・公開中のみ・空タイトル除外・存在しないIDの除外が従来どおりであることを確認する。
	 */
	public function test_card_lists_published_assigned_names_in_admin_order(): void {
		$bob      = $this->create_resource( 'Bob', 2 );
		$alice    = $this->create_resource( 'Alice', 2 );
		$carol    = $this->create_resource( 'Carol', 1 );
		$draft    = $this->create_resource( 'Draft', 0, 'draft' );
		$trashed  = $this->create_resource( 'Trashed', 0, 'trash' );
		$untitled = $this->create_resource( '', 3 );
		// 担当に含まれない公開中リソース（表示されないことの確認用）。
		$this->create_resource( 'NotAssigned', 0 );

		// 保存順は並び順と一致させない。存在しない ID（999999）も混ぜる。
		$menu_id = $this->create_menu( '指名メニュー', array( $bob, $untitled, $draft, $alice, 999999, $trashed, $carol ) );

		$html = $this->block->render_menu_card( $menu_id );

		// menu_order が小さい順、同じなら title 昇順。公開中でタイトルのあるものだけ。
		$this->assertSame( 'Carol, Alice, Bob', $this->extract_staff_names( $html, $this->get_label() ) );
		$this->assertStringNotContainsString( 'NotAssigned', $html );
	}

	/**
	 * フィルターで追加された ID は、公開中のリソースなら表示し、非公開・存在しないものは従来どおり表示しない。
	 */
	public function test_card_handles_ids_added_by_assignable_staff_filter(): void {
		$alice = $this->create_resource( 'Alice', 1 );
		$bob   = $this->create_resource( 'Bob', 2 );
		$draft = $this->create_resource( 'Draft', 0, 'draft' );

		$menu_id = $this->create_menu( '指名メニュー', array( $alice ) );

		add_filter(
			'vkbm_menu_assignable_staff_ids',
			static function ( $ids ) use ( $bob, $draft ) {
				$ids[] = $bob;
				$ids[] = $draft;
				$ids[] = 999999;
				return $ids;
			}
		);

		$html = $this->block->render_menu_card( $menu_id );

		$this->assertSame( 'Alice, Bob', $this->extract_staff_names( $html, $this->get_label() ) );
	}

	/**
	 * 「query」フィルター。投稿テーブルへの SELECT だけを数える。
	 *
	 * @param string $query 発行されるクエリ。
	 * @return string そのままのクエリ。
	 */
	public function count_posts_select( $query ) {
		global $wpdb;
		if ( 0 === stripos( ltrim( $query ), 'SELECT' ) && false !== strpos( $query, $wpdb->posts ) ) {
			++$this->posts_select_count;
		}

		return $query;
	}

	/**
	 * 作ったリソース・メニューをすべて削除する（件数だけを変えて計測を繰り返すため）。
	 */
	private function delete_all_menus_and_resources(): void {
		$post_ids = get_posts(
			array(
				'post_type'      => array( Service_Menu_Post_Type::POST_TYPE, Resource_Post_Type::POST_TYPE ),
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $post_ids as $post_id ) {
			wp_delete_post( (int) $post_id, true );
		}
	}

	/**
	 * リソースとメニューを作り、キャッシュを空にして、描画中に投稿テーブルへ発行された SELECT の数を返す。
	 *
	 * @param callable $render      描画を行うコールバック。作成したメニューID配列を受け取り、HTML を返す。
	 * @param int      $menu_count  メニューの件数。
	 * @param int      $per_menu    メニューごとの担当リソース数。
	 * @param int      $staff_total 作成するリソースの総数。
	 * @return int 発行された SELECT の数。
	 */
	private function count_posts_queries( callable $render, int $menu_count, int $per_menu, int $staff_total ): int {
		$staff_ids = array();
		for ( $i = 1; $i <= $staff_total; $i++ ) {
			$staff_ids[] = $this->create_resource( 'Staff' . $i, $i );
		}

		// メニューごとに担当の組み合わせを変える（WP_Query は同じ条件の結果をリクエスト内でキャッシュするため）。
		// リソースが多いので、メニューが増えるほど読み込むリソースの種類も増える。
		$menu_ids = array();
		for ( $i = 0; $i < $menu_count; $i++ ) {
			$assigned = array();
			for ( $j = 0; $j < $per_menu; $j++ ) {
				$assigned[] = $staff_ids[ ( $i * $per_menu + $j ) % $staff_total ];
			}
			$menu_ids[] = $this->create_menu( 'メニュー' . $i, $assigned );
		}

		// 作成時に載ったキャッシュを捨て、表示1回分の読み込みを再現する。
		wp_cache_flush();
		Service_Menu_Post_Type::clear_published_resource_ids_cache();

		$this->posts_select_count = 0;
		add_filter( 'query', array( $this, 'count_posts_select' ) );
		$html = $render( $menu_ids );
		remove_filter( 'query', array( $this, 'count_posts_select' ) );

		// 全メニューのカードに担当リソース名が出ていること（空振りで件数が揃うのを防ぐ）。
		$this->assertSame( $menu_count, substr_count( $html, '<dt>' . $this->get_label() . '</dt>' ) );

		return $this->posts_select_count;
	}

	/**
	 * メニューの件数を増やしても、予約画面のメニュー選択一覧の問い合わせ回数が増えないことを確認する。
	 */
	public function test_selection_list_queries_do_not_grow_with_menu_count(): void {
		$render = function () {
			return $this->block->render_menu_selection_list();
		};

		$few = $this->count_posts_queries( $render, 2, 3, 12 );
		$this->delete_all_menus_and_resources();
		$many = $this->count_posts_queries( $render, 10, 3, 12 );

		$this->assertSame( $few, $many, '件数に比例して投稿テーブルへの問い合わせが増えている。' );
	}

	/**
	 * メニューの件数を増やしても、メニューループブロックの問い合わせ回数が増えないことを確認する。
	 */
	public function test_render_block_queries_do_not_grow_with_menu_count(): void {
		$render = function () {
			return $this->block->render_block(
				array(
					'loopId'      => 'test-loop',
					'displayMode' => 'card',
					'showMeta'    => true,
				)
			);
		};

		$few = $this->count_posts_queries( $render, 2, 3, 12 );
		$this->delete_all_menus_and_resources();
		$many = $this->count_posts_queries( $render, 10, 3, 12 );

		$this->assertSame( $few, $many, '件数に比例して投稿テーブルへの問い合わせが増えている。' );
	}

	/**
	 * カード単独の描画（先読みを通らない経路）でも、担当リソース数に比例して問い合わせが増えないことを確認する。
	 */
	public function test_single_card_queries_do_not_grow_with_staff_count(): void {
		$render = function ( array $menu_ids ) {
			return $this->block->render_menu_card( $menu_ids[0] );
		};

		$few = $this->count_posts_queries( $render, 1, 2, 8 );
		$this->delete_all_menus_and_resources();
		$many = $this->count_posts_queries( $render, 1, 8, 8 );

		$this->assertSame( $few, $many, '担当リソース数に比例して投稿テーブルへの問い合わせが増えている。' );
	}
}
