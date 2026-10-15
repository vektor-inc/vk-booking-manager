<?php
/**
 * Menu_Loop_Block::build_query_args() の並び順と Post_Order_Manager の関係テスト（issue #654）。
 *
 * メニューループブロックで並び順を「公開日」にしても、Post_Order_Manager が
 * フロントの orderby=date を手動並び順（menu_order）へ強制して書き換えていた不具合の回帰テスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Frontend;

use ReflectionMethod;
use VKBookingManager\Blocks\Menu_Loop_Block;
use VKBookingManager\PostOrder\Post_Order_Manager;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use WP_Query;
use WP_UnitTestCase;

/**
 * build_query_args() の並び順を、Post_Order_Manager 有効下で検証するテストクラス。
 *
 * @group blocks
 */
class Menu_Loop_Build_Query_Args_Order_Test extends WP_UnitTestCase {

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
		// 本体の登録状況に依存しないよう明示的に登録する（本体の登録と二重になるが処理は冪等）。
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
	 * @return int 作成したサービスメニューの投稿ID。
	 */
	private function create_menu( string $title, string $post_date, int $menu_order ): int {
		return (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
				'post_date'   => $post_date,
				'menu_order'  => $menu_order,
			)
		);
	}

	/**
	 * build_query_args() をリフレクション経由で呼び出し、WP_Query の結果の投稿ID配列を返す。
	 *
	 * @param array<string,mixed> $attributes ブロック属性。
	 * @return int[]
	 */
	private function get_loop_ids( array $attributes ): array {
		$method = new ReflectionMethod( Menu_Loop_Block::class, 'build_query_args' );
		$method->setAccessible( true );
		$args           = $method->invoke( new Menu_Loop_Block(), $attributes, 'loop-654' );
		$args['fields'] = 'ids';
		$query          = new WP_Query( $args );

		return array_map( 'intval', $query->posts );
	}

	/**
	 * メニューループの並び順が、公開日・手動並び順の指定どおりになることを検証する。
	 */
	public function test_build_query_args(): void {
		// 公開日の古い順 = A, B, C。手動並び順（menu_order）の昇順は C, A, B と、別の並びにする。
		$menu_a = $this->create_menu( 'A menu', '2026-01-01 10:00:00', 20 );
		$menu_b = $this->create_menu( 'B menu', '2026-02-01 10:00:00', 30 );
		$menu_c = $this->create_menu( 'C menu', '2026-03-01 10:00:00', 10 );

		$test_cases = array(
			array(
				'test_condition_name' => '公開日・降順の場合 => 公開日の新しい順（C, B, A）',
				'attributes'          => array(
					'orderBy' => 'date',
					'order'   => 'desc',
				),
				'expected'            => array( $menu_c, $menu_b, $menu_a ),
			),
			array(
				'test_condition_name' => '公開日・昇順の場合 => 公開日の古い順（A, B, C）',
				'attributes'          => array(
					'orderBy' => 'date',
					'order'   => 'asc',
				),
				'expected'            => array( $menu_a, $menu_b, $menu_c ),
			),
			array(
				'test_condition_name' => '手動並び順・昇順の場合 => menu_order の昇順（C, A, B）',
				'attributes'          => array(
					'orderBy' => 'menu_order',
					'order'   => 'asc',
				),
				'expected'            => array( $menu_c, $menu_a, $menu_b ),
			),
			array(
				'test_condition_name' => 'タイトル・降順の場合 => タイトルの降順（C, B, A）',
				'attributes'          => array(
					'orderBy' => 'title',
					'order'   => 'desc',
				),
				'expected'            => array( $menu_c, $menu_b, $menu_a ),
			),
			array(
				'test_condition_name' => '不正な orderBy の場合 => 手動並び順にフォールバック（C, A, B）',
				'attributes'          => array(
					'orderBy' => 'invalid',
					'order'   => 'asc',
				),
				'expected'            => array( $menu_c, $menu_a, $menu_b ),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame(
				$case['expected'],
				$this->get_loop_ids( $case['attributes'] ),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * フラグ無しのクエリでは、従来どおり orderby=date が手動並び順へ強制されることを検証する。
	 */
	public function test_apply_default_order(): void {
		$menu_a = $this->create_menu( 'A menu', '2026-01-01 10:00:00', 20 );
		$menu_b = $this->create_menu( 'B menu', '2026-02-01 10:00:00', 30 );
		$menu_c = $this->create_menu( 'C menu', '2026-03-01 10:00:00', 10 );

		$test_cases = array(
			array(
				'test_condition_name' => 'フラグ無しで orderby=date の場合 => 手動並び順に強制（C, A, B）',
				'query_args'          => array(
					'orderby' => 'date',
					'order'   => 'DESC',
				),
				'expected'            => array( $menu_c, $menu_a, $menu_b ),
			),
			array(
				'test_condition_name' => 'vkbm_force_menu_order が true で orderby=date の場合 => 手動並び順に強制（C, A, B）',
				'query_args'          => array(
					'orderby'               => 'date',
					'order'                 => 'DESC',
					'vkbm_force_menu_order' => true,
				),
				'expected'            => array( $menu_c, $menu_a, $menu_b ),
			),
			array(
				'test_condition_name' => 'vkbm_force_menu_order が false で orderby=date の場合 => 強制せず公開日の降順（C, B, A）',
				'query_args'          => array(
					'orderby'               => 'date',
					'order'                 => 'DESC',
					'vkbm_force_menu_order' => false,
				),
				'expected'            => array( $menu_c, $menu_b, $menu_a ),
			),
		);

		foreach ( $test_cases as $case ) {
			$query = new WP_Query(
				array_merge(
					array(
						'post_type'      => Service_Menu_Post_Type::POST_TYPE,
						'post_status'    => 'publish',
						'posts_per_page' => -1,
						'fields'         => 'ids',
					),
					$case['query_args']
				)
			);

			$this->assertSame(
				$case['expected'],
				array_map( 'intval', $query->posts ),
				$case['test_condition_name']
			);
		}
	}
}
