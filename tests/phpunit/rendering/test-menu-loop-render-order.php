<?php
/**
 * Menu_Loop_Block::render_block() の描画後の並び順テスト（issue #654）。
 *
 * 並び順を「公開日」にしても、render_grouped_items() が menu_order で並べ直してしまい
 * フロント表示が手動の並び順のままになる不具合の回帰テスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Frontend;

use VKBookingManager\Blocks\Menu_Loop_Block;
use VKBookingManager\PostOrder\Post_Order_Manager;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use WP_UnitTestCase;

/**
 * 描画後の HTML 上でサービスメニューの並び順を検証するテストクラス。
 *
 * @group blocks
 */
class Menu_Loop_Render_Order_Test extends WP_UnitTestCase {

	/**
	 * テスト用に登録した Post_Order_Manager。
	 *
	 * @var Post_Order_Manager
	 */
	private $post_order_manager;

	/**
	 * 各テスト前に Post_Order_Manager の pre_get_posts フックを登録する。
	 */
	public function set_up(): void {
		parent::set_up();
		$this->post_order_manager = new Post_Order_Manager( array( Service_Menu_Post_Type::POST_TYPE ) );
		add_action( 'pre_get_posts', array( $this->post_order_manager, 'apply_default_order' ) );
	}

	/**
	 * 登録したフックを外す。
	 */
	public function tear_down(): void {
		remove_action( 'pre_get_posts', array( $this->post_order_manager, 'apply_default_order' ) );
		parent::tear_down();
	}

	/**
	 * テスト用のサービスメニューを作成するヘルパー。
	 *
	 * @param string $title      メニュータイトル。
	 * @param string $post_date  公開日時（Y-m-d H:i:s）。
	 * @param int    $menu_order 手動並び順の値。
	 * @param int[]  $term_ids   所属させるグループタームID。
	 */
	private function create_menu( string $title, string $post_date, int $menu_order, array $term_ids = array() ): void {
		$post_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
				'post_date'   => $post_date,
				'menu_order'  => $menu_order,
			)
		);

		if ( ! empty( $term_ids ) ) {
			wp_set_object_terms( $post_id, $term_ids, Service_Menu_Post_Type::TAXONOMY_GROUP );
		}
	}

	/**
	 * 描画結果の HTML 内で、指定タイトルが出現する順にタイトルを並べて返す。
	 *
	 * @param array<string,mixed> $attributes ブロック属性。
	 * @param string[]            $titles     検索するタイトル（互いに部分一致しない値）。
	 * @return string[]
	 */
	private function get_rendered_title_order( array $attributes, array $titles ): array {
		$html      = ( new Menu_Loop_Block() )->render_block( array_merge( array( 'loopId' => 'loop-654' ), $attributes ) );
		$positions = array();

		foreach ( $titles as $title ) {
			$position = strpos( $html, $title );
			$this->assertNotFalse( $position, $title . ' が描画結果に含まれていません。' );
			$positions[ $title ] = $position;
		}

		asort( $positions );

		return array_keys( $positions );
	}

	/**
	 * グループなしのメニューが、ブロックの並び順どおりに描画されることを検証する。
	 */
	public function test_render_block_ungrouped(): void {
		// 公開日の古い順 = Alpha, Bravo, Charlie。menu_order の昇順は Charlie, Alpha, Bravo。
		$this->create_menu( 'Alpha menu', '2026-01-01 10:00:00', 20 );
		$this->create_menu( 'Bravo menu', '2026-02-01 10:00:00', 30 );
		$this->create_menu( 'Charlie menu', '2026-03-01 10:00:00', 10 );

		$titles     = array( 'Alpha menu', 'Bravo menu', 'Charlie menu' );
		$test_cases = array(
			array(
				'test_condition_name' => '公開日・降順の場合 => 公開日の新しい順',
				'attributes'          => array(
					'orderBy' => 'date',
					'order'   => 'desc',
				),
				'expected'            => array( 'Charlie menu', 'Bravo menu', 'Alpha menu' ),
			),
			array(
				'test_condition_name' => '公開日・昇順の場合 => 公開日の古い順',
				'attributes'          => array(
					'orderBy' => 'date',
					'order'   => 'asc',
				),
				'expected'            => array( 'Alpha menu', 'Bravo menu', 'Charlie menu' ),
			),
			array(
				'test_condition_name' => '手動の並び順の場合 => menu_order の昇順',
				'attributes'          => array(
					'orderBy' => 'menu_order',
					'order'   => 'asc',
				),
				'expected'            => array( 'Charlie menu', 'Alpha menu', 'Bravo menu' ),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame(
				$case['expected'],
				$this->get_rendered_title_order( $case['attributes'], $titles ),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * グループありのメニューが、各グループ内でブロックの並び順どおりに描画されることを検証する。
	 */
	public function test_render_block_grouped(): void {
		$term = wp_insert_term( 'Group One', Service_Menu_Post_Type::TAXONOMY_GROUP );
		$this->assertIsArray( $term );
		$group = array( (int) $term['term_id'] );

		// グループ内: 公開日の古い順 = Alpha, Bravo, Charlie / menu_order の昇順 = Charlie, Alpha, Bravo。
		$this->create_menu( 'Alpha menu', '2026-01-01 10:00:00', 20, $group );
		$this->create_menu( 'Bravo menu', '2026-02-01 10:00:00', 30, $group );
		$this->create_menu( 'Charlie menu', '2026-03-01 10:00:00', 10, $group );
		// グループなし（「その他」セクション）にも同様に 2 件。
		$this->create_menu( 'Delta menu', '2026-04-01 10:00:00', 50 );
		$this->create_menu( 'Echo menu', '2026-05-01 10:00:00', 40 );

		$titles     = array( 'Alpha menu', 'Bravo menu', 'Charlie menu', 'Delta menu', 'Echo menu' );
		$test_cases = array(
			array(
				'test_condition_name' => '公開日・降順の場合 => グループ内もグループなしも公開日の新しい順',
				'attributes'          => array(
					'orderBy' => 'date',
					'order'   => 'desc',
				),
				'expected'            => array( 'Charlie menu', 'Bravo menu', 'Alpha menu', 'Echo menu', 'Delta menu' ),
			),
			array(
				'test_condition_name' => '公開日・昇順の場合 => グループ内もグループなしも公開日の古い順',
				'attributes'          => array(
					'orderBy' => 'date',
					'order'   => 'asc',
				),
				'expected'            => array( 'Alpha menu', 'Bravo menu', 'Charlie menu', 'Delta menu', 'Echo menu' ),
			),
			array(
				'test_condition_name' => '手動の並び順の場合 => グループ内もグループなしも menu_order の昇順',
				'attributes'          => array(
					'orderBy' => 'menu_order',
					'order'   => 'asc',
				),
				'expected'            => array( 'Charlie menu', 'Alpha menu', 'Bravo menu', 'Echo menu', 'Delta menu' ),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame(
				$case['expected'],
				$this->get_rendered_title_order( $case['attributes'], $titles ),
				$case['test_condition_name']
			);
		}
	}
}
