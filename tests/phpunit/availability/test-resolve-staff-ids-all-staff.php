<?php
/**
 * Availability_Service::resolve_staff_ids() の「すべてのリソースが担当できる」（#485）対応のテスト。
 *
 * サービスメニューで「すべて」（_vkbm_staff_all = true）が選ばれているとき、
 * - 指名なし（resource_id = 0）の自動割当候補が公開中の全リソースになること
 * - 公開中のリソースなら個別チェックが無くても指名できること
 * - 下書き（非公開）のリソースは指名できないこと（staff_not_assigned）
 * - 「選ぶ」かつ個別選択が空のメニューは従来どおり staff_not_configured のままであること
 * を、private メソッドのため ReflectionMethod 経由で検証する。
 *
 * 無料版では resolve_staff_ids() がメニュー側の担当設定を参照せず基本スタッフ固定になる
 * （test-resolve-staff-ids-free-default-staff-fallback.php で検証済み）ため、このテストは Pro 版限定。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Availability;

use ReflectionMethod;
use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\Staff\Staff_Editor;
use WP_Error;
use WP_UnitTestCase;
use function get_post;
use function update_post_meta;

/**
 * resolve_staff_ids() の「すべて」展開を検証するテストクラス。
 *
 * @group availability
 * @group staff
 */
class Resolve_Staff_Ids_All_Staff_Test extends WP_UnitTestCase {

	/**
	 * 各テスト前にキャッシュを初期化する。
	 */
	protected function setUp(): void {
		parent::setUp();
		Service_Menu_Post_Type::clear_published_resource_ids_cache();
	}

	/**
	 * テスト用のリソース（スタッフ）を作成する。
	 *
	 * @param string $title      タイトル。
	 * @param int    $menu_order 表示順。
	 * @param string $status     投稿ステータス。
	 * @return int 投稿ID。
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
	 * resolve_staff_ids() が「すべて」フラグを公開中の全リソースへ展開することを検証する。
	 */
	public function test_resolve_staff_ids(): void {
		if ( ! Staff_Editor::is_enabled() ) {
			$this->markTestSkipped( '無料版ではメニュー側の担当設定を参照しない（基本スタッフ固定）ため、Pro版限定のテスト。' );
		}

		$staff_a = $this->create_resource( 'スタッフA', 1 );
		$staff_b = $this->create_resource( 'スタッフB', 2 );
		$draft_c = $this->create_resource( 'スタッフC（下書き）', 3, 'draft' );

		// このテストで作ったリソースだけを候補にするため、他の公開中リソースはフィルターで除外する。
		$own_ids = array( $staff_a, $staff_b );
		$filter  = static function ( array $staff_ids ) use ( $own_ids ): array {
			return array_values( array_intersect( $staff_ids, $own_ids ) );
		};
		add_filter( 'vkbm_menu_assignable_staff_ids', $filter );

		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'テストメニュー',
			)
		);

		$service    = new Availability_Service();
		$reflection = new ReflectionMethod( Availability_Service::class, 'resolve_staff_ids' );
		$reflection->setAccessible( true );

		$test_cases = array(
			array(
				'test_condition_name' => '「すべて」かつ指名なし => 公開中の全リソースが自動割当の候補になる（正常系）',
				'staff_all'           => true,
				'staff_ids'           => array(),
				'preferred_staff'     => 0,
				'expected'            => array( $staff_a, $staff_b ),
			),
			array(
				'test_condition_name' => '「すべて」かつ個別チェックの無い公開中リソースを指名 => その指名スタッフで受け付ける（正常系）',
				'staff_all'           => true,
				'staff_ids'           => array( $staff_a ),
				'preferred_staff'     => $staff_b,
				'expected'            => array( $staff_b ),
			),
			array(
				'test_condition_name' => '「すべて」かつ下書きのリソースを指名 => staff_not_assigned エラー（異常系）',
				'staff_all'           => true,
				'staff_ids'           => array(),
				'preferred_staff'     => $draft_c,
				'expected'            => 'staff_not_assigned',
			),
			array(
				'test_condition_name' => '「選ぶ」かつ個別選択が空・指名なし => 従来どおり staff_not_configured（空配列の意味は変えない・境界値）',
				'staff_all'           => false,
				'staff_ids'           => array(),
				'preferred_staff'     => 0,
				'expected'            => 'staff_not_configured',
			),
			array(
				'test_condition_name' => '「選ぶ」かつ個別選択あり・指名なし => 従来どおり個別選択が候補になる（正常系・回帰）',
				'staff_all'           => false,
				'staff_ids'           => array( $staff_b ),
				'preferred_staff'     => 0,
				'expected'            => array( $staff_b ),
			),
		);

		foreach ( $test_cases as $case ) {
			update_post_meta( $menu_id, Service_Menu_Post_Type::META_STAFF_ALL, $case['staff_all'] );
			update_post_meta( $menu_id, '_vkbm_staff_ids', $case['staff_ids'] );

			$result = $reflection->invoke( $service, get_post( $menu_id ), $case['preferred_staff'] );

			if ( is_string( $case['expected'] ) ) {
				$this->assertInstanceOf( WP_Error::class, $result, $case['test_condition_name'] );
				$this->assertSame( $case['expected'], $result->get_error_code(), $case['test_condition_name'] );
			} else {
				$this->assertSame( $case['expected'], $result, $case['test_condition_name'] );
			}
		}

		remove_filter( 'vkbm_menu_assignable_staff_ids', $filter );
	}
}
