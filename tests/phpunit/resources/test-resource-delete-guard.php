<?php
/**
 * リソース（スタッフ）削除ガード（Resource_Delete_Guard）のテスト（issue #262）。
 *
 * 検証する範囲:
 * - 判定ロジック（count_active_bookings() / has_linked_bookings()）が、予約のステータスだけで
 *   正しく件数・有無を判定すること（日付の未来／過去では判定しない）。
 * - `pre_trash_post` ハンドラが、対応中の予約があっても実行を止めない（常に null を返す）こと、
 *   かつ警告を記録すること。
 * - `pre_delete_post` ハンドラが、紐づく予約が残っていれば削除をブロックすること
 *   （管理画面では wp_die() を避けるため WP_Error、REST API では false を返すこと）。
 * - Pro 版限定機能であり、`register()` が無料版では何も登録しないこと。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Resources;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\Resources\Resource_Delete_Guard;
use WP_Error;
use WP_Query;
use WP_UnitTestCase;

/**
 * Resource_Delete_Guard の判定ロジック・フック挙動を検証するテストクラス。
 *
 * @group resources
 */
class Resource_Delete_Guard_Test extends WP_UnitTestCase {

	/**
	 * $GLOBALS['pagenow'] の元の値（setUp() で退避し、tearDown() で復元する）。
	 *
	 * @var string|null
	 */
	private $original_pagenow;

	/**
	 * $GLOBALS['pagenow'] を書き換えるテストのために、元の値を退避する。
	 *
	 * `pagenow` は WordPress 起動時に一度だけ設定され、テスト間で自動的には復元されない
	 * グローバル変数のため、無条件に `unset()` すると同じプロセスで後から実行される
	 * 他のテストクラスへ影響が漏れてしまう（安藤レビュー指摘・issue #262）。
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->original_pagenow = $GLOBALS['pagenow'] ?? null;
	}

	/**
	 * set_current_screen() / $GLOBALS['pagenow'] を書き換えるテストの後始末をし、
	 * 他のテストへ影響しないようにする。
	 */
	protected function tearDown(): void {
		if ( null === $this->original_pagenow ) {
			unset( $GLOBALS['pagenow'] );
		} else {
			$GLOBALS['pagenow'] = $this->original_pagenow; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- setUp() で退避した元の値へ復元しているだけ。
		}
		// current_screen は unset ではなく「front」へ戻すのが慣例（安藤レビュー指摘・issue #262）。
		set_current_screen( 'front' );
		parent::tearDown();
	}

	/**
	 * テスト用のスタッフ（リソース）投稿を作成するヘルパー。
	 *
	 * @return int 作成したリソース投稿ID。
	 */
	private function create_staff(): int {
		return (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * テスト用の予約投稿を作成し、担当スタッフ・ステータスのメタを設定するヘルパー。
	 *
	 * @param int    $resource_id 担当スタッフ（リソース）投稿ID。0 の場合は担当未設定。
	 * @param string $status      予約ステータス（pending・confirmed・cancelled・no_show 等）。
	 * @return int 作成した予約投稿ID。
	 */
	private function create_booking( int $resource_id, string $status ): int {
		$booking_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		if ( $resource_id > 0 ) {
			update_post_meta( $booking_id, '_vkbm_booking_resource_id', $resource_id );
		}
		update_post_meta( $booking_id, '_vkbm_booking_status', $status );

		return $booking_id;
	}

	/**
	 * count_active_bookings() が、対応中（pending・confirmed）の予約だけを数え、
	 * 完了・キャンセル・無断キャンセルは数えないことを検証する。
	 */
	public function test_count_active_bookings(): void {
		$staff_a = $this->create_staff();
		$staff_b = $this->create_staff();

		// staff_a: pending 1件・confirmed 1件・cancelled 1件・no_show 1件。
		$this->create_booking( $staff_a, 'pending' );
		$this->create_booking( $staff_a, 'confirmed' );
		$this->create_booking( $staff_a, 'cancelled' );
		$this->create_booking( $staff_a, 'no_show' );
		// staff_b: pending 1件（staff_a の集計に混ざらないことを確認するため）。
		$this->create_booking( $staff_b, 'pending' );

		$test_cases = array(
			array(
				'test_condition_name' => 'pending・confirmed が各1件、cancelled・no_show は対象外 => 2',
				'resource_id'         => $staff_a,
				'expected'            => 2,
			),
			array(
				'test_condition_name' => '他のスタッフの予約は数えない（staff_b は pending 1件のみ） => 1',
				'resource_id'         => $staff_b,
				'expected'            => 1,
			),
			array(
				'test_condition_name' => '予約が1件も無いスタッフ => 0',
				'resource_id'         => $this->create_staff(),
				'expected'            => 0,
			),
			array(
				'test_condition_name' => 'resource_id が0以下（境界値） => 0',
				'resource_id'         => 0,
				'expected'            => 0,
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = Resource_Delete_Guard::count_active_bookings( $case['resource_id'] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * count_active_bookings() が、ゴミ箱に入った予約を数えないことを検証する
	 * （安藤レビュー指摘・issue #262 の副作用対応: has_linked_bookings() と同じ一覧
	 * （trash 込み）を流用していたため、予約一覧（既定でゴミ箱を表示しない）と件数が
	 * 食い違っていた。ACTIVE_BOOKING_POST_STATUSES（trash を含まない）に分離して修正）。
	 */
	public function test_count_active_bookings_excludes_trashed(): void {
		$staff_id   = $this->create_staff();
		$booking_id = $this->create_booking( $staff_id, 'pending' );
		wp_trash_post( $booking_id );

		$this->assertSame(
			0,
			Resource_Delete_Guard::count_active_bookings( $staff_id ),
			'ゴミ箱に入った pending の予約は、予約一覧の既定表示に合わせて数えない'
		);
	}

	/**
	 * has_linked_bookings() が、ステータスを問わず紐づく予約が1件でもあれば true を返すことを検証する
	 * （完了・キャンセル・無断キャンセルを含む全件が対象）。
	 */
	public function test_has_linked_bookings(): void {
		$staff_with_cancelled_only = $this->create_staff();
		$this->create_booking( $staff_with_cancelled_only, 'cancelled' );

		$staff_with_trashed_booking = $this->create_staff();
		$trashed_booking_id         = $this->create_booking( $staff_with_trashed_booking, 'pending' );
		wp_trash_post( $trashed_booking_id );

		$staff_without_bookings = $this->create_staff();

		$test_cases = array(
			array(
				'test_condition_name' => 'キャンセル済みの予約のみ紐づく => ステータスを問わないため true',
				'resource_id'         => $staff_with_cancelled_only,
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'ゴミ箱に入った予約のみ紐づく => 除外せず true（安藤レビュー指摘: ' .
					'「予約をゴミ箱へ入れる→スタッフを完全削除→予約を復元」でブロックを回避できないことの確認）',
				'resource_id'         => $staff_with_trashed_booking,
				'expected'            => true,
			),
			array(
				'test_condition_name' => '紐づく予約が1件も無い => false',
				'resource_id'         => $staff_without_bookings,
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'resource_id が0以下（境界値） => false',
				'resource_id'         => 0,
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = Resource_Delete_Guard::has_linked_bookings( $case['resource_id'] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * handle_pre_trash_post() が、対応中の予約があってもゴミ箱移動を止めない（常に null を返す）ことを検証する。
	 *
	 * ミューテーション確認: 「対応中の予約があればブロックする」ように実装を書き換える
	 * （$active_count > 0 のとき non-null を返すよう変更する）と、このテストが失敗することを確認済み。
	 */
	public function test_handle_pre_trash_post(): void {
		$staff_with_active = $this->create_staff();
		$this->create_booking( $staff_with_active, 'pending' );

		$staff_without_active = $this->create_staff();

		$other_post_type = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		$test_cases = array(
			array(
				'test_condition_name' => '対応中の予約があっても null（止めない）',
				'check'               => null,
				'post_id'             => $staff_with_active,
			),
			array(
				'test_condition_name' => '対応中の予約が無くても null',
				'check'               => null,
				'post_id'             => $staff_without_active,
			),
			array(
				'test_condition_name' => 'リソース以外の投稿タイプは何もしない => null',
				'check'               => null,
				'post_id'             => $other_post_type,
			),
		);

		foreach ( $test_cases as $case ) {
			$guard  = new Resource_Delete_Guard();
			$actual = $guard->handle_pre_trash_post( $case['check'], get_post( $case['post_id'] ) );
			$this->assertNull( $actual, $case['test_condition_name'] );
		}

		// 既に他のフィルターが値を返している場合は、それをそのまま優先して返すこと。
		$guard    = new Resource_Delete_Guard();
		$existing = new WP_Error( 'other_plugin_blocked', 'blocked by another plugin' );
		$this->assertSame(
			$existing,
			$guard->handle_pre_trash_post( $existing, get_post( $staff_with_active ) ),
			'他のフィルターが既に値を返している場合はそれを優先する'
		);
	}

	/**
	 * handle_pre_trash_post() が、対応中の予約がある場合にのみ警告を記録し、
	 * flush_notices() で現在のユーザー向け transient へ蓄積することを検証する。
	 *
	 * ミューテーション確認: 警告記録の if 条件（$active_count > 0）を常に false 相当へ変更すると、
	 * このテストが失敗する（transient に warnings が現れなくなる）ことを確認済み。
	 */
	public function test_handle_pre_trash_post_records_warning(): void {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$staff_with_active = $this->create_staff();
		$this->create_booking( $staff_with_active, 'confirmed' );
		$this->create_booking( $staff_with_active, 'pending' );

		$staff_without_active = $this->create_staff();

		$guard = new Resource_Delete_Guard();
		$guard->handle_pre_trash_post( null, get_post( $staff_without_active ) );
		$guard->handle_pre_trash_post( null, get_post( $staff_with_active ) );
		$guard->flush_notices();

		$payload = get_transient( 'vkbm_resource_guard_notice_' . $user_id );
		$this->assertIsArray( $payload, '警告を記録すると transient に配列が保存される' );
		$this->assertCount( 1, $payload['warnings'], '対応中の予約が無いスタッフの警告は記録されない' );
		$this->assertSame( $staff_with_active, $payload['warnings'][0]['id'] );
		$this->assertSame( 2, $payload['warnings'][0]['count'], '対応中（confirmed・pending）の2件が記録される' );

		delete_transient( 'vkbm_resource_guard_notice_' . $user_id );
		wp_set_current_user( 0 );
	}

	/**
	 * handle_pre_delete_post() が、紐づく予約が残っている場合のみ完全削除をブロックすることを検証する。
	 *
	 * WP_Error は真として扱われる（`! $result` が false になる）ため、wp-admin の画面遷移
	 * 経由の削除だけに限定して返す必要がある（安藤レビュー指摘・issue #262）。それ以外
	 * （REST API・WP-CLI・admin-ajax・フロント等）は false を返し、正しく失敗と判定させる。
	 * is_admin_screen_request() / is_rest_request() はテスト用サブクラスで差し替える
	 * （REST_REQUEST 等のグローバル定数は書き換えない）。
	 *
	 * ミューテーション確認: has_linked_bookings() の判定結果を無視して常に null を返すよう変更すると、
	 * このテストが失敗する（ブロックされるべきケースで削除が許可されてしまう）ことを確認済み。
	 */
	public function test_handle_pre_delete_post(): void {
		$staff_with_booking = $this->create_staff();
		$this->create_booking( $staff_with_booking, 'cancelled' ); // ステータスを問わずブロック対象。

		$staff_without_booking = $this->create_staff();

		$other_post_type = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		$admin_guard = new class() extends Resource_Delete_Guard {
			/**
			 * wp-admin の画面遷移経由として扱うテスト用の差し替え（is_admin() 等のグローバル
			 * 状態に依存させないため）。
			 *
			 * @return bool
			 */
			protected function is_admin_screen_request(): bool {
				return true;
			}
		};
		$rest_guard  = new class() extends Resource_Delete_Guard {
			/**
			 * REST_REQUEST 定数を書き換えず、常に REST API 経由として扱うテスト用の差し替え。
			 *
			 * @return bool
			 */
			protected function is_rest_request(): bool {
				return true;
			}
		};
		// デフォルト（is_admin_screen_request() を差し替えない）の場合、PHPUnit は CLI から
		// 実行されるため is_admin() は false になり、wp-admin の画面遷移以外として扱われる。
		$non_admin_guard = new Resource_Delete_Guard();

		// 紐づく予約が無ければ null（許可）。
		$this->assertNull(
			$admin_guard->handle_pre_delete_post( null, get_post( $staff_without_booking ), true ),
			'紐づく予約が無ければブロックしない'
		);

		// リソース以外の投稿タイプは何もしない。
		$this->assertNull(
			$admin_guard->handle_pre_delete_post( null, get_post( $other_post_type ), true ),
			'リソース以外の投稿タイプは対象外'
		);

		// wp-admin の画面遷移: WP_Error を返す（wp_delete_post() の戻り値を truthy に保ち wp_die() を避けるため）。
		$admin_result = $admin_guard->handle_pre_delete_post( null, get_post( $staff_with_booking ), true );
		$this->assertInstanceOf( WP_Error::class, $admin_result, 'wp-admin の画面遷移では WP_Error を返す' );
		$this->assertSame( 'vkbm_resource_has_bookings', $admin_result->get_error_code() );

		// REST API: false を返す（WP_REST_Posts_Controller::delete_item() が失敗として扱えるように）。
		$rest_result = $rest_guard->handle_pre_delete_post( null, get_post( $staff_with_booking ), true );
		$this->assertFalse( $rest_result, 'REST API では false を返す' );

		// wp-admin の画面遷移以外（WP-CLI・admin-ajax・フロント等）: false を返す。WP_Error を
		// 返すと呼び出し元が失敗と判定できず、実際にはブロックされているのに成功したと
		// 誤認してしまうため（安藤レビュー指摘・issue #262）。
		$non_admin_result = $non_admin_guard->handle_pre_delete_post( null, get_post( $staff_with_booking ), true );
		$this->assertFalse( $non_admin_result, 'wp-admin の画面遷移以外では false を返す' );

		// 既に他のフィルターが値を返している場合は、それをそのまま優先して返すこと。
		$existing = new WP_Error( 'other_plugin_blocked', 'blocked by another plugin' );
		$this->assertSame(
			$existing,
			$admin_guard->handle_pre_delete_post( $existing, get_post( $staff_with_booking ), true ),
			'他のフィルターが既に値を返している場合はそれを優先する'
		);
	}

	/**
	 * is_admin_screen_request()（protected）が、`$GLOBALS['pagenow']` を `edit.php`・`post.php`
	 * に限定して判定することを検証する（安藤レビュー指摘・issue #262 の副作用対応: `is_admin()`
	 * だけでは `admin-post.php`・`admin.php?page=...` の独自ハンドラも wp_die() を避ける対象に
	 * 含めてしまい、WP_Error を返す範囲が広すぎた）。
	 *
	 * ミューテーション確認: pagenow の `in_array()` 判定を削除する（is_admin() の結果をそのまま
	 * 返す）と、admin-post.php・admin.php のケースが失敗することを確認済み。
	 */
	public function test_is_admin_screen_request_limits_to_edit_and_post_pagenow(): void {
		// is_admin() を true にするため、管理画面のスクリーンを設定する。
		set_current_screen( 'edit-post' );

		$method = new \ReflectionMethod( Resource_Delete_Guard::class, 'is_admin_screen_request' );
		$method->setAccessible( true );
		$guard = new Resource_Delete_Guard();

		$test_cases = array(
			array(
				'test_condition_name' => 'edit.php（一覧・一括操作・「ゴミ箱を空にする」） => true',
				'pagenow'             => 'edit.php',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'post.php（個別の編集画面からの削除） => true',
				'pagenow'             => 'post.php',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'admin-post.php（独自ハンドラ） => false（wp_die() を避ける対象外）',
				'pagenow'             => 'admin-post.php',
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'admin.php（独自の管理画面ページ） => false',
				'pagenow'             => 'admin.php',
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$GLOBALS['pagenow'] = $case['pagenow']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- is_admin_screen_request() の pagenow 分岐をテストするための意図的な上書き。tearDown() で unset する。
			$actual             = $method->invoke( $guard );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * is_resource_post()（private static）が、リソース投稿かどうかを正しく判定することを検証する
	 * （安藤レビュー指摘・issue #262: ajax_check() が投稿IDを検証せず任意の投稿のタイトルを
	 * 読み出せてしまっていた不具合の修正対象）。
	 */
	public function test_is_resource_post(): void {
		$resource_id      = $this->create_staff();
		$other_post_type  = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$draft_other_type = (int) $this->factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'draft',
				'post_title'  => '非公開のタイトル',
			)
		);

		$method = new \ReflectionMethod( Resource_Delete_Guard::class, 'is_resource_post' );
		$method->setAccessible( true );

		$test_cases = array(
			array(
				'test_condition_name' => 'リソース投稿 => true',
				'post_id'             => $resource_id,
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'リソース以外の投稿タイプ（公開） => false',
				'post_id'             => $other_post_type,
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'リソース以外の投稿タイプ（下書き） => false（任意のIDでタイトルを読み出せないことの確認）',
				'post_id'             => $draft_other_type,
				'expected'            => false,
			),
			array(
				'test_condition_name' => '存在しない投稿ID => false',
				'post_id'             => 999999999,
				'expected'            => false,
			),
			array(
				'test_condition_name' => '投稿IDが0以下（境界値） => false',
				'post_id'             => 0,
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = $method->invoke( null, $case['post_id'] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * filter_booking_query_by_resource()（`pre_get_posts`）が、post_status・perm の設定を
	 * 意図通りに出し分けることを検証する（安藤レビュー指摘・issue #262 再々レビュー: この処理が
	 * 単体テストを1件も持たず、通知・ダイアログのリンク先で使う閲覧範囲の判定が緩んでも
	 * 気づけない状態だった）。
	 *
	 * `is_main_query()` を true にするため、$GLOBALS['wp_the_query'] に検証対象の WP_Query
	 * 自身を差す（安藤提案）。クエリ引数は本番同様 $_GET 経由で渡す。
	 */
	public function test_filter_booking_query_by_resource_sets_post_status_and_perm(): void {
		// is_admin() を true にするため、管理画面のスクリーンを設定する（既存テストと同じ手段）。
		set_current_screen( 'edit-post' );

		$resource_id = $this->create_staff();

		// private const の実値を直接参照し、テスト側で期待値を複製・固定しない
		// （本体側で一覧が変わってもテストが自動追従する）。
		$all_statuses    = ( new \ReflectionClassConstant( Resource_Delete_Guard::class, 'ALL_BOOKING_POST_STATUSES' ) )->getValue();
		$active_statuses = ( new \ReflectionClassConstant( Resource_Delete_Guard::class, 'ACTIVE_BOOKING_POST_STATUSES' ) )->getValue();

		$original_get          = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- テスト末尾で復元するための退避で、読み取り検証は不要。
		$original_wp_the_query = $GLOBALS['wp_the_query'] ?? null;

		$test_cases = array(
			array(
				'test_condition_name'  => 'post_status未指定・active_onlyなし => 全ステータス一覧＋perm=readable',
				'get'                  => array(
					Resource_Delete_Guard::QUERY_VAR_RESOURCE_ID => $resource_id,
				),
				'initial_post_status'  => '',
				'expected_post_status' => $all_statuses,
				'expected_perm'        => 'readable',
			),
			array(
				'test_condition_name'  => 'post_status未指定・active_only=1 => 対応中のみのステータス一覧＋perm=readable',
				'get'                  => array(
					Resource_Delete_Guard::QUERY_VAR_RESOURCE_ID => $resource_id,
					Resource_Delete_Guard::QUERY_VAR_ACTIVE_ONLY => 1,
				),
				'initial_post_status'  => '',
				'expected_post_status' => $active_statuses,
				'expected_perm'        => 'readable',
			),
			array(
				'test_condition_name'  => 'post_statusを手動指定済み(trash・境界値) => 上書きしない・permも設定しない',
				'get'                  => array(
					Resource_Delete_Guard::QUERY_VAR_RESOURCE_ID => $resource_id,
				),
				'initial_post_status'  => 'trash',
				'expected_post_status' => 'trash',
				'expected_perm'        => '',
			),
			array(
				'test_condition_name'  => 'resource_id未指定(0・境界値) => 早期returnでpost_status・permに触れない',
				'get'                  => array(),
				'initial_post_status'  => '',
				'expected_post_status' => '',
				'expected_perm'        => '',
			),
		);

		foreach ( $test_cases as $case ) {
			$_GET = $case['get']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter_booking_query_by_resource() が読む $_GET を再現するテスト用の代入。

			$query = new WP_Query();
			$query->set( 'post_type', Booking_Post_Type::POST_TYPE );
			if ( '' !== $case['initial_post_status'] ) {
				$query->set( 'post_status', $case['initial_post_status'] );
			}
			// is_main_query() は $GLOBALS['wp_the_query'] との同一性で判定するため、
			// 検証対象のインスタンス自身を差す（安藤提案）。
			$GLOBALS['wp_the_query'] = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- is_main_query() を true にするための意図的な差し替え。テスト末尾で元へ復元する。

			$guard = new Resource_Delete_Guard();
			$guard->filter_booking_query_by_resource( $query );

			$this->assertSame(
				$case['expected_post_status'],
				$query->get( 'post_status' ),
				$case['test_condition_name'] . '（post_status）'
			);
			$this->assertSame(
				$case['expected_perm'],
				$query->get( 'perm' ),
				$case['test_condition_name'] . '（perm）'
			);
		}

		$_GET = $original_get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- テスト用に差し替えた $_GET を元へ復元しているだけ。
		if ( null === $original_wp_the_query ) {
			unset( $GLOBALS['wp_the_query'] );
		} else {
			$GLOBALS['wp_the_query'] = $original_wp_the_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- テスト用に差し替えた $GLOBALS['wp_the_query'] を元へ復元しているだけ。
		}
	}

	/**
	 * filter_booking_query_by_resource() が meta_query にリソースID・対応中ステータスの条件を
	 * 正しく積み増すことを検証する（安藤レビュー指摘・issue #262 再々レビュー: post_status・perm
	 * だけでなく、この処理の本来の目的である絞り込み自体もこれまで無検証だった）。
	 */
	public function test_filter_booking_query_by_resource_sets_meta_query(): void {
		set_current_screen( 'edit-post' );

		$resource_id          = $this->create_staff();
		$meta_resource_id_key = ( new \ReflectionClassConstant( Resource_Delete_Guard::class, 'META_RESOURCE_ID' ) )->getValue();
		$meta_status_key      = ( new \ReflectionClassConstant( Resource_Delete_Guard::class, 'META_STATUS' ) )->getValue();
		$warning_statuses     = ( new \ReflectionClassConstant( Resource_Delete_Guard::class, 'WARNING_STATUSES' ) )->getValue();

		$original_get          = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- テスト末尾で復元するための退避で、読み取り検証は不要。
		$original_wp_the_query = $GLOBALS['wp_the_query'] ?? null;

		$test_cases = array(
			array(
				'test_condition_name'      => 'active_onlyなし => リソースIDの条件のみ積み増す',
				'get'                      => array(
					Resource_Delete_Guard::QUERY_VAR_RESOURCE_ID => $resource_id,
				),
				'expected_condition_count' => 1,
				'expect_status_condition'  => false,
			),
			array(
				'test_condition_name'      => 'active_only=1 => リソースIDに加え対応中ステータスの条件も積み増す',
				'get'                      => array(
					Resource_Delete_Guard::QUERY_VAR_RESOURCE_ID => $resource_id,
					Resource_Delete_Guard::QUERY_VAR_ACTIVE_ONLY => 1,
				),
				'expected_condition_count' => 2,
				'expect_status_condition'  => true,
			),
			array(
				'test_condition_name'      => 'resource_id未指定(0・境界値) => meta_queryに触れない',
				'get'                      => array(),
				'expected_condition_count' => 0,
				'expect_status_condition'  => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$_GET = $case['get']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter_booking_query_by_resource() が読む $_GET を再現するテスト用の代入。

			$query = new WP_Query();
			$query->set( 'post_type', Booking_Post_Type::POST_TYPE );
			$GLOBALS['wp_the_query'] = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- is_main_query() を true にするための意図的な差し替え。テスト末尾で元へ復元する。

			$guard = new Resource_Delete_Guard();
			$guard->filter_booking_query_by_resource( $query );

			// filter_booking_query_by_resource() 側が get() の既定値を array() にしたため
			// （司の指摘・issue #262）、meta_query 未設定時に空文字要素が混ざることはない。
			$meta_query = (array) $query->get( 'meta_query', array() );
			$this->assertCount(
				$case['expected_condition_count'],
				$meta_query,
				$case['test_condition_name'] . '（条件数）'
			);

			if ( $case['expected_condition_count'] > 0 ) {
				$this->assertSame(
					$meta_resource_id_key,
					$meta_query[0]['key'],
					$case['test_condition_name'] . '（1件目=リソースIDの条件キー）'
				);
				$this->assertSame(
					$resource_id,
					$meta_query[0]['value'],
					$case['test_condition_name'] . '（1件目=リソースIDの条件値）'
				);
			}

			if ( $case['expect_status_condition'] ) {
				$this->assertSame(
					$meta_status_key,
					$meta_query[1]['key'],
					$case['test_condition_name'] . '（2件目=対応中ステータスの条件キー）'
				);
				$this->assertSame(
					$warning_statuses,
					$meta_query[1]['value'],
					$case['test_condition_name'] . '（2件目=対応中ステータスの条件値）'
				);
			}
		}

		$_GET = $original_get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- テスト用に差し替えた $_GET を元へ復元しているだけ。
		if ( null === $original_wp_the_query ) {
			unset( $GLOBALS['wp_the_query'] );
		} else {
			$GLOBALS['wp_the_query'] = $original_wp_the_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- テスト用に差し替えた $GLOBALS['wp_the_query'] を元へ復元しているだけ。
		}
	}

	/**
	 * Resource_Delete_Guard::$instance（private static）への ReflectionProperty を返す。
	 *
	 * 本番の register() 呼び出し（プラグイン起動時に一度だけ）で設定された実インスタンスを、
	 * テストが使い捨てインスタンスで上書きしたままにしないよう、呼び出し前後で退避・復元する
	 * ために使う（WP_UnitTestCase のフック自動バックアップ／復元は $wp_filter 等のみが対象で、
	 * このクラス自身の static プロパティまでは戻してくれないため）。
	 *
	 * @return \ReflectionProperty
	 */
	private function get_instance_property(): \ReflectionProperty {
		$property = new \ReflectionProperty( Resource_Delete_Guard::class, 'instance' );
		$property->setAccessible( true );
		return $property;
	}

	/**
	 * register() が自身のインスタンスを get_instance() 経由で取得可能にすることを検証する
	 * （安藤レビュー指摘・issue #262: これが無いテストが `remove_all_filters()` という全消しに
	 * 頼らざるを得なかったため）。
	 */
	public function test_get_instance(): void {
		// 無料版では register() が is_guard_enabled() の判定で何もせずに戻り、
		// インスタンスを保持しないため、この検証は成り立たない。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'スタッフの削除ガードは Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$instance_property = $this->get_instance_property();
		$original_instance = $instance_property->getValue();

		$guard = new Resource_Delete_Guard();
		$guard->register();

		$this->assertSame(
			$guard,
			Resource_Delete_Guard::get_instance(),
			'register() を呼んだインスタンス自身が get_instance() で取得できる'
		);

		remove_filter( 'pre_delete_post', array( $guard, 'handle_pre_delete_post' ), 10 );
		remove_filter( 'pre_trash_post', array( $guard, 'handle_pre_trash_post' ), 10 );
		remove_all_actions( 'shutdown' );
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'admin_enqueue_scripts' );
		remove_all_actions( 'pre_get_posts' );

		// 本番の register() が設定した実インスタンスへ戻す（他のテストファイルが
		// get_instance() 経由で本物のフックだけを外せる状態を維持するため）。
		$instance_property->setValue( null, $original_instance );
	}

	/**
	 * is_guard_enabled() が Pro_Upsell::is_free_edition() の否定と一致することを検証する
	 * （実際のプラグインヘッダによる Pro/無料版判定への配線を確認する）。
	 *
	 * このリポジトリのテスト実行環境は常に Pro 版として判定されるため（プラグインヘッダに
	 * "Pro" を含む）、無料版そのものを本テストで再現することはできない。無料版ビルドでの
	 * 実際の無効化は `npm run phpunit:free`（無料版 dist に対する別プロセスのテスト実行）で
	 * 検証される。ここでは「判定ロジックが正しい関数に配線されていること」と、次の
	 * test_register_respects_guard_enabled() で「その判定結果に register() が従うこと」を
	 * 個別に検証することで、実質的に同じ保証を得る。
	 */
	public function test_is_guard_enabled(): void {
		$this->assertSame(
			! Pro_Upsell::is_free_edition(),
			Resource_Delete_Guard::is_guard_enabled(),
			'is_guard_enabled() は Pro_Upsell::is_free_edition() の否定と一致する'
		);
	}

	/**
	 * register() が is_guard_enabled() の判定に従い、無効なら何もフックを登録しないことを検証する。
	 *
	 * static:: 経由の呼び出しにしているため、テスト用サブクラスで is_guard_enabled() だけを
	 * 差し替えて「無料版扱い」を安全に再現できる（REST_REQUEST 定数と同じ理由でグローバル状態は書き換えない）。
	 *
	 * ミューテーション確認: register() 冒頭の早期 return を削除すると、無効時テストで
	 * pre_delete_post フィルターが登録されてしまい、このテストが失敗することを確認済み。
	 */
	public function test_register_respects_guard_enabled(): void {
		$instance_property = $this->get_instance_property();
		$original_instance = $instance_property->getValue();

		$enabled_guard  = new class() extends Resource_Delete_Guard {
			/**
			 * 実際の Pro/無料版判定を経由せず、常に「有効」として扱うテスト用の差し替え。
			 *
			 * @return bool
			 */
			public static function is_guard_enabled(): bool {
				return true;
			}
		};
		$disabled_guard = new class() extends Resource_Delete_Guard {
			/**
			 * 実際の Pro/無料版判定を経由せず、常に「無効（無料版扱い）」として扱うテスト用の差し替え。
			 *
			 * @return bool
			 */
			public static function is_guard_enabled(): bool {
				return false;
			}
		};

		remove_all_filters( 'pre_delete_post' );
		remove_all_filters( 'pre_trash_post' );

		$disabled_guard->register();
		$this->assertFalse(
			has_filter( 'pre_delete_post' ),
			'無効時は pre_delete_post フィルターを登録しない'
		);
		$this->assertFalse(
			has_filter( 'pre_trash_post' ),
			'無効時は pre_trash_post フィルターを登録しない'
		);

		$enabled_guard->register();
		$this->assertNotFalse(
			has_filter( 'pre_delete_post' ),
			'有効時は pre_delete_post フィルターを登録する'
		);
		$this->assertNotFalse(
			has_filter( 'pre_trash_post' ),
			'有効時は pre_trash_post フィルターを登録する'
		);

		remove_all_filters( 'pre_delete_post' );
		remove_all_filters( 'pre_trash_post' );
		remove_all_actions( 'shutdown' );
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'admin_enqueue_scripts' );
		remove_all_actions( 'pre_get_posts' );

		// 本番の register() が設定した実インスタンスへ戻す（他のテストファイルが
		// get_instance() 経由で本物のフックだけを外せる状態を維持するため）。
		$instance_property->setValue( null, $original_instance );
	}
}
