<?php
/**
 * Availability_Service のリソースタグ絞り込み（issue #431）のテスト。
 *
 * 候補リソースの絞り込みは Availability_Service::resolve_staff_ids() に集約されており、
 * 空き枠取得 REST（calendar-meta / availabilities）・予約下書き・予約確定のすべてが
 * このメソッドの結果を経由する。ここでは resolve_staff_ids() 自体（private のため
 * ReflectionMethod 経由）と、それを利用する get_calendar_meta() を検証する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Availability;

use ReflectionMethod;
use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Resources\Resource_Tag_Taxonomy;
use VKBookingManager\Staff\Staff_Editor;
use WP_Error;
use WP_UnitTestCase;

/**
 * Availability_Service のリソースタグ絞り込みを検証するテストクラス。
 *
 * @group availability
 * @group resource-tag
 */
class Resource_Tag_Filter_Test extends WP_UnitTestCase {

	/**
	 * テスト前の全体設定（option）を退避する。
	 *
	 * @var mixed
	 */
	private $original_settings = false;

	/**
	 * テスト前の全体設定（option）を退避する。
	 */
	public function set_up(): void {
		parent::set_up();
		$this->original_settings = get_option( Settings_Repository::OPTION_KEY, false );
	}

	/**
	 * 全体設定・静的キャッシュをテスト前の状態へ戻す。
	 */
	public function tear_down(): void {
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		Staff_Editor::clear_nomination_enabled_cache();
		parent::tear_down();
	}

	/**
	 * サイト全体の指名機能スイッチを更新する。
	 *
	 * @param bool $enabled 指名機能を有効にする場合は true。
	 */
	private function set_site_wide_nomination( bool $enabled ): void {
		$repository                = new Settings_Repository();
		$settings                  = $repository->get_settings();
		$settings['staff_enabled'] = $enabled;
		update_option( Settings_Repository::OPTION_KEY, $settings );
		Staff_Editor::clear_nomination_enabled_cache();
	}

	/**
	 * テスト用のリソース投稿を作成し、指定のリソースタグを割り当てるヘルパー。
	 *
	 * @param string     $title   投稿タイトル。
	 * @param array<int> $tag_ids 割り当てるリソースタグのターム ID 配列。
	 * @return int 作成したリソース投稿ID。
	 */
	private function create_resource( string $title, array $tag_ids = array() ): int {
		$resource_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);

		if ( ! empty( $tag_ids ) ) {
			wp_set_object_terms( $resource_id, $tag_ids, Resource_Tag_Taxonomy::TAXONOMY );
		}

		return $resource_id;
	}

	/**
	 * テスト用のリソースタグタームを作成するヘルパー。
	 *
	 * @param string $name タグ名。
	 * @return int タームID。
	 */
	private function create_tag( string $name ): int {
		$result = wp_insert_term( $name, Resource_Tag_Taxonomy::TAXONOMY );
		$this->assertIsArray( $result, 'wp_insert_term() はタームを作成できるべき: ' . $name );
		return (int) $result['term_id'];
	}

	/**
	 * resolve_staff_ids() のタグ絞り込み（AND条件・該当0件時のエラー）を検証する。
	 */
	public function test_resolve_staff_ids_filters_by_resource_tags(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'リソースタグ機能は Pro 版限定のため、無料版ではスキップする。' );
		}

		$this->set_site_wide_nomination( true );

		$tag_female  = $this->create_tag( '女性_' . wp_generate_password( 6, false ) );
		$tag_veteran = $this->create_tag( 'ベテラン_' . wp_generate_password( 6, false ) );

		$staff_both    = $this->create_resource( 'スタッフA', array( $tag_female, $tag_veteran ) );
		$staff_female  = $this->create_resource( 'スタッフB', array( $tag_female ) );
		$staff_no_tags = $this->create_resource( 'スタッフC' );

		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_both, $staff_female, $staff_no_tags ) );
		$menu_post = get_post( $menu_id );

		$service    = new Availability_Service();
		$reflection = new ReflectionMethod( Availability_Service::class, 'resolve_staff_ids' );
		$reflection->setAccessible( true );

		$test_cases = array(
			array(
				'test_condition_name' => 'タグ未指定 => メニューの担当リソース全員（正常系・従来どおり）',
				'tag_ids'             => array(),
				'expected_is_error'   => false,
				'expected_ids'        => array( $staff_both, $staff_female, $staff_no_tags ),
			),
			array(
				'test_condition_name' => '「女性」タグ指定 => 「女性」を持つ2名に絞り込む（正常系）',
				'tag_ids'             => array( $tag_female ),
				'expected_is_error'   => false,
				'expected_ids'        => array( $staff_both, $staff_female ),
			),
			array(
				'test_condition_name' => '「女性」「ベテラン」両方指定（AND） => 両方持つ1名のみ（正常系）',
				'tag_ids'             => array( $tag_female, $tag_veteran ),
				'expected_is_error'   => false,
				'expected_ids'        => array( $staff_both ),
			),
			array(
				'test_condition_name' => '誰も持たない組み合わせを指定 => resource_tag_no_match エラー（異常系・境界値）',
				'tag_ids'             => array( $tag_veteran, 999999 ),
				'expected_is_error'   => true,
				'expected_error_code' => 'resource_tag_no_match',
			),
		);

		foreach ( $test_cases as $case ) {
			$result = $reflection->invoke( $service, $menu_post, 0, $case['tag_ids'] );

			if ( $case['expected_is_error'] ) {
				$this->assertInstanceOf( WP_Error::class, $result, $case['test_condition_name'] );
				$this->assertSame( $case['expected_error_code'], $result->get_error_code(), $case['test_condition_name'] );
				continue;
			}

			$this->assertIsArray( $result, $case['test_condition_name'] );
			$actual   = $result;
			$expected = $case['expected_ids'];
			sort( $actual );
			sort( $expected );
			$this->assertSame( $expected, $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * get_calendar_meta() が resource_tag_ids 引数を受け取り、キャッシュキーにも反映することを検証する
	 * （タグの組み合わせが違えば別キャッシュになる。#431完了条件「空き枠のキャッシュキーにリソースタグIDを含める」）。
	 *
	 * 直接キャッシュキー文字列を比較するのではなく、「タグ指定 → 該当0件エラー」と
	 * 「タグ未指定 → 正常応答」が transient 経由で混線しない（同じキャッシュを共有しない）ことで検証する。
	 */
	public function test_get_calendar_meta_cache_key_reflects_resource_tag_ids(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'リソースタグ機能は Pro 版限定のため、無料版ではスキップする。' );
		}

		$this->set_site_wide_nomination( true );

		$tag_female = $this->create_tag( '女性_' . wp_generate_password( 6, false ) );
		$staff_id   = $this->create_resource( 'スタッフD', array() );

		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );

		$service = new Availability_Service();

		// タグ未指定 => 正常応答（このスタッフはタグを持たないが、絞り込み自体を行わないため通る）。
		$without_tag = $service->get_calendar_meta(
			array(
				'menu_id'  => $menu_id,
				'year'     => 2026,
				'month'    => 8,
				'timezone' => 'Asia/Tokyo',
			)
		);
		$this->assertIsArray( $without_tag, 'タグ未指定では正常な応答（配列）を返すべき' );

		// タグ指定（このスタッフは持っていない） => 該当0件エラー。
		// タグ未指定のキャッシュと混線して正常応答が誤って返らないことを検証する。
		$with_tag = $service->get_calendar_meta(
			array(
				'menu_id'          => $menu_id,
				'year'             => 2026,
				'month'            => 8,
				'timezone'         => 'Asia/Tokyo',
				'resource_tag_ids' => array( $tag_female ),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $with_tag, 'タグを持たないスタッフしかいない場合はエラーを返すべき（キャッシュキーが分離されていることの確認）' );
		$this->assertSame( 'resource_tag_no_match', $with_tag->get_error_code() );
	}
}
