<?php
/**
 * サービスメニューのサンプルパーマリンクに関するテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\PostTypes;

use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use WP_UnitTestCase;
use function do_action;
use function get_permalink;
use function get_post_type_object;
use function get_sample_permalink;
use function home_url;

/**
 * サービスメニューのサンプルパーマリンクを検証するテスト。
 *
 * @group post-types
 */
class Service_Menu_Sample_Permalink_Test extends WP_UnitTestCase {
	/**
	 * 各テスト前に投稿タイプを登録する。
	 */
	protected function setUp(): void {
		parent::setUp();
		// 単体実行時にも get_sample_permalink() を利用できるよう管理画面用関数を読み込む。
		require_once ABSPATH . 'wp-admin/includes/post.php';
		// プラグインが登録したフックを実行し、投稿タイプを利用可能にする。
		do_action( 'init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress コアのフック。
	}

	/**
	 * get_sample_permalink() のテスト。
	 *
	 * サービスメニューではクエリ形式を維持しながらスラッグ用プレースホルダーを返し、
	 * 対象外の投稿タイプには影響しないことを確認する。
	 */
	public function test_get_sample_permalink(): void {
		$service_menu_post_type = get_post_type_object( Service_Menu_Post_Type::POST_TYPE );
		$this->assertNotNull( $service_menu_post_type, 'サービスメニュー投稿タイプが登録されている' );

		$test_cases = array(
			array(
				'test_condition_name' => '公開済みサービスメニューの場合 => クエリ形式のテンプレートに %postname% が含まれる',
				'conditions'          => array(
					'post_type'   => Service_Menu_Post_Type::POST_TYPE,
					'post_status' => 'publish',
					'post_title'  => 'Cut',
					'post_name'   => 'cut',
				),
				'expected_template'   => home_url( '?' . $service_menu_post_type->query_var . '=%postname%' ),
				'expected_slug'       => 'cut',
			),
			array(
				'test_condition_name' => '下書きサービスメニューの場合 => タイトル由来のスラッグを維持して編集可能なテンプレートを返す',
				'conditions'          => array(
					'post_type'   => Service_Menu_Post_Type::POST_TYPE,
					'post_status' => 'draft',
					'post_title'  => 'Hair Color',
					'post_name'   => '',
				),
				'expected_template'   => home_url( '?' . $service_menu_post_type->query_var . '=%postname%' ),
				'expected_slug'       => 'hair-color',
			),
			array(
				'test_condition_name' => 'タイトルが空の自動下書きサービスメニューの場合 => 投稿 ID をスラッグに使う',
				'conditions'          => array(
					'post_type'   => Service_Menu_Post_Type::POST_TYPE,
					'post_status' => 'auto-draft',
					'post_title'  => '',
					'post_name'   => '',
				),
				'expected_template'   => home_url( '?' . $service_menu_post_type->query_var . '=%postname%' ),
				'expected_slug'       => null,
			),
			array(
				'test_condition_name' => '対象外の通常投稿の場合 => WordPress 本体のサンプルパーマリンクを変更しない',
				'conditions'          => array(
					'post_type'   => 'post',
					'post_status' => 'publish',
					'post_title'  => 'News',
					'post_name'   => 'news',
				),
				'expected_template'   => null,
				'expected_slug'       => 'news',
			),
		);

		foreach ( $test_cases as $case ) {
			// 条件に応じた投稿を作り、通常投稿ではフィルター前のコア相当値を記録する。
			$post_id = self::factory()->post->create( $case['conditions'] );
			if ( 'post' === $case['conditions']['post_type'] ) {
				$case['expected_template'] = get_permalink( $post_id, true );
			}
			if ( 'auto-draft' === $case['conditions']['post_status'] ) {
				$case['expected_slug'] = (string) $post_id;
			}

			$actual = get_sample_permalink( $post_id );

			$this->assertSame( $case['expected_template'], $actual[0], $case['test_condition_name'] );
			$this->assertSame( $case['expected_slug'], $actual[1], $case['test_condition_name'] );
		}
	}

	/**
	 * filter_sample_permalink() に配列以外が渡された場合のテスト。
	 */
	public function test_filter_sample_permalink(): void {
		$service_menu_post_type = new Service_Menu_Post_Type();
		$invalid_permalink      = 'invalid-permalink';

		$actual = $service_menu_post_type->filter_sample_permalink( $invalid_permalink, 1, null, null );

		$this->assertSame( $invalid_permalink, $actual, '配列以外の場合 => 例外にせず入力値をそのまま返す' );
	}
}
