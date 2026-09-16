<?php
/**
 * Term_Order_Manager::apply_default_order() / register_rest_fields() のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\TermOrder;

use VKBookingManager\TermOrder\Term_Order_Manager;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use function array_map;
use function do_action;
use function get_terms;
use function register_taxonomy;
use function set_current_screen;
use function unregister_taxonomy;
use function update_term_meta;

/**
 * Term_Order_Manager のテストクラス。
 *
 * @group term-order
 */
class Term_Order_Manager_Test extends WP_UnitTestCase {

	/** テスト専用タクソノミー。 */
	private const TAXONOMY = 'vkbm_term_order_test_tax';

	/**
	 * REST 経路のテスト専用タクソノミー（#463）。
	 *
	 * 既存の TAXONOMY は REST 非公開で登録しているため、REST 関連のテストでは
	 * 別のタクソノミーを使い、既存テストへ影響が出ないようにする。
	 */
	private const REST_TAXONOMY = 'vkbm_term_order_rest_test_tax';

	/**
	 * テスト用タクソノミーを登録する。
	 */
	protected function setUp(): void {
		parent::setUp();

		register_taxonomy(
			self::TAXONOMY,
			'post',
			array(
				'hierarchical' => false,
			)
		);
	}

	/**
	 * issue #452 の再現テスト。
	 *
	 * 管理画面のターム一覧（edit-tags.php 相当）で並び替え → 保存した場合、
	 * 一覧の表示順が保存した vkbm_term_order の値どおりになるかを確認する。
	 * 「並び順を保存しました」と表示されるのに再読み込みすると元の順序（名前順）に
	 * 戻ってしまう不具合が起きていないかを、get_terms() の実際の返り値で検証する。
	 */
	public function test_apply_default_order(): void {
		// 名前順（Alpha, Bravo, Charlie）とは異なる並び順メタを保存して検証する。
		$term_a_id = self::factory()->term->create(
			array(
				'taxonomy' => self::TAXONOMY,
				'name'     => 'Alpha',
			)
		);
		$term_b_id = self::factory()->term->create(
			array(
				'taxonomy' => self::TAXONOMY,
				'name'     => 'Bravo',
			)
		);
		$term_c_id = self::factory()->term->create(
			array(
				'taxonomy' => self::TAXONOMY,
				'name'     => 'Charlie',
			)
		);

		$manager = new Term_Order_Manager( array( self::TAXONOMY ) );
		$manager->register();

		// edit-tags.php を開いた状態（管理画面・ターム一覧）を再現する。
		set_current_screen( 'edit-' . self::TAXONOMY );

		$test_cases = array(
			array(
				'test_condition_name' => '名前順とは逆の並び順メタを保存した場合 => 並び順メタの順で表示される',
				'order_meta'          => array(
					$term_b_id => '1',
					$term_c_id => '2',
					$term_a_id => '3',
				),
				'get_params'          => array(),
				'expected'            => array( $term_b_id, $term_c_id, $term_a_id ),
			),
			array(
				'test_condition_name' => '並び替えを保存し直した場合 => 保存し直した並び順メタの順で表示される',
				'order_meta'          => array(
					$term_c_id => '1',
					$term_a_id => '2',
					$term_b_id => '3',
				),
				'get_params'          => array(),
				'expected'            => array( $term_c_id, $term_a_id, $term_b_id ),
			),
			array(
				'test_condition_name' => 'URL で orderby=name が明示指定された場合（境界値） => 並び順メタを無視して名前順が優先される',
				'order_meta'          => array(
					$term_c_id => '1',
					$term_a_id => '2',
					$term_b_id => '3',
				),
				'get_params'          => array( 'orderby' => 'name' ),
				'expected'            => array( $term_a_id, $term_b_id, $term_c_id ),
			),
		);

		foreach ( $test_cases as $case ) {
			foreach ( $case['order_meta'] as $term_id => $order_value ) {
				update_term_meta( $term_id, Term_Order_Manager::META_KEY, $order_value );
			}

			$original_get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test setup only.
			$_GET         = $case['get_params']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test setup only.

			$terms = get_terms(
				array(
					'taxonomy'   => self::TAXONOMY,
					'hide_empty' => false,
					'fields'     => 'ids',
				)
			);

			$_GET = $original_get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Restore after test.

			$this->assertSame(
				$case['expected'],
				array_map( 'intval', $terms ),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * Term_Order_Manager::get_order_value_for_rest() のテスト（#463）。
	 *
	 * 並び順メタの値をそのまま返すこと、メタが無い・不正な値のタームは
	 * 0 として扱うこと（内部結合ではなく欠損値をデフォルト扱いする方式のため、
	 * 一覧から除外されない前提の値になる）、term_id が不正な場合も 0 を返すことを確認する。
	 */
	public function test_get_order_value_for_rest(): void {
		$term_with_order_id    = self::factory()->term->create(
			array(
				'taxonomy' => self::TAXONOMY,
				'name'     => 'Ordered',
			)
		);
		$term_without_order_id = self::factory()->term->create(
			array(
				'taxonomy' => self::TAXONOMY,
				'name'     => 'NoMeta',
			)
		);
		$term_invalid_meta_id  = self::factory()->term->create(
			array(
				'taxonomy' => self::TAXONOMY,
				'name'     => 'InvalidMeta',
			)
		);

		update_term_meta( $term_with_order_id, Term_Order_Manager::META_KEY, '5' );
		// $term_without_order_id には並び順メタをあえて設定しない。
		update_term_meta( $term_invalid_meta_id, Term_Order_Manager::META_KEY, 'invalid-value' );

		$manager = new Term_Order_Manager( array( self::TAXONOMY ) );

		$test_cases = array(
			array(
				'test_condition_name' => '並び順メタが数値で保存されている場合 => その数値が返る',
				'object'              => array( 'id' => $term_with_order_id ),
				'expected'            => 5,
			),
			array(
				'test_condition_name' => '並び順メタが保存されていない場合（境界値） => 一覧から消えないよう 0 が返る',
				'object'              => array( 'id' => $term_without_order_id ),
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '並び順メタが数値でない不正な値の場合（異常系） => 0 が返る',
				'object'              => array( 'id' => $term_invalid_meta_id ),
				'expected'            => 0,
			),
			array(
				'test_condition_name' => 'REST オブジェクトに id が含まれない場合（異常系） => 0 が返る',
				'object'              => array(),
				'expected'            => 0,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame(
				$case['expected'],
				$manager->get_order_value_for_rest( $case['object'] ),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * Term_Order_Manager::register_rest_fields() のテスト（#463）。
	 *
	 * issue #463 の再現テスト。公開画面の予約ページのリソースタグ検索チェックボックス一覧は
	 * REST（/wp/v2/vkbm_resource_tag 相当）で取得するタームを使うが、
	 * REST レスポンスに並び順が含まれていなかったため、フロント側で並べ替えができず
	 * 名前順のまま表示されてしまっていた。実際に REST リクエストを実行し、
	 * レスポンスに並び順の値が正しく含まれること、並び順メタが無いタームも
	 * 一覧から消えないことを確認する。
	 */
	public function test_register_rest_fields(): void {
		global $wp_rest_server; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WordPress コアのグローバル変数。

		// フルスイート実行時、先に別のテストが REST サーバーを初期化していると
		// 'rest_api_init' が既に発火済みになり、このテストで register_taxonomy() した
		// タクソノミーのルートが反映されず 404 になる。このテスト専用に REST サーバーを
		// 作り直し、テスト終了後は元のサーバーへ戻す（他のテストへ影響させないため）。
		$previous_rest_server = $wp_rest_server;

		try {
			register_taxonomy(
				self::REST_TAXONOMY,
				'post',
				array(
					'hierarchical' => false,
					'show_in_rest' => true,
					'rest_base'    => self::REST_TAXONOMY,
				)
			);

			$term_ordered_id = self::factory()->term->create(
				array(
					'taxonomy' => self::REST_TAXONOMY,
					'name'     => 'Ordered',
				)
			);
			$term_no_meta_id = self::factory()->term->create(
				array(
					'taxonomy' => self::REST_TAXONOMY,
					'name'     => 'NoMeta',
				)
			);

			update_term_meta( $term_ordered_id, Term_Order_Manager::META_KEY, '10' );

			$manager = new Term_Order_Manager( array( self::REST_TAXONOMY ) );

			// 新しい REST サーバーを構築し、'rest_api_init' を発火し直す。
			// register_rest_fields() はこのフックに登録した上で、コアのタクソノミー
			// ルート登録（create_initial_rest_routes）と同じタイミングで反映させる。
			$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WordPress コアのグローバル変数。
			$manager->register_rest_fields();
			do_action( 'rest_api_init', $wp_rest_server ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress コアのフック。

			$request = new WP_REST_Request( 'GET', '/wp/v2/' . self::REST_TAXONOMY );
			$request->set_param( 'hide_empty', false );
			$request->set_param( 'include', array( $term_ordered_id, $term_no_meta_id ) );

			$response = $wp_rest_server->dispatch( $request );

			$this->assertSame( 200, $response->get_status(), 'REST リクエストが成功すること' );

			$data  = $response->get_data();
			$by_id = array();
			foreach ( $data as $item ) {
				$by_id[ $item['id'] ] = $item;
			}

			$test_cases = array(
				array(
					'test_condition_name' => '並び順メタがあるタームの REST レスポンス => order フィールドにその値が含まれる',
					'term_id'             => $term_ordered_id,
					'expected'            => 10,
				),
				array(
					'test_condition_name' => '並び順メタが無いタームの REST レスポンス（境界値） => 一覧から消えず order は 0 になる',
					'term_id'             => $term_no_meta_id,
					'expected'            => 0,
				),
			);

			foreach ( $test_cases as $case ) {
				$this->assertArrayHasKey(
					$case['term_id'],
					$by_id,
					$case['test_condition_name'] . '（一覧に含まれること）'
				);
				$this->assertSame(
					$case['expected'],
					$by_id[ $case['term_id'] ]['order'],
					$case['test_condition_name']
				);
			}
		} finally {
			$wp_rest_server = $previous_rest_server; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WordPress コアのグローバル変数。
			// register_taxonomy() が書き込むグローバルはテスト後に残り続けるため、
			// 後続テストへ影響しないよう明示的に登録解除する
			// （#463 安藤さんレビュー指摘。$backupGlobals = false のため自動では戻らない）。
			unregister_taxonomy( self::REST_TAXONOMY );
		}
	}
}
