<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Integrations;

use VKBookingManager\Admin\Shift_Dashboard_Page;
use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\Bookings\Booking_Admin;
use VKBookingManager\Bookings\Booking_Confirmation_Controller;
use VKBookingManager\Bookings\My_Bookings_Controller;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Integrations\Booking_Event_Dispatcher;
use VKBookingManager\Notifications\Booking_Notification_Service;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\Plugin;
use VKBookingManager\ProviderSettings\Settings_Repository;
use ReflectionMethod;
use ReflectionProperty;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;
use WPDieException;
use function add_action;
use function add_filter;
use function get_post;
use function get_user_by;
use function json_decode;
use function ob_end_clean;
use function ob_get_clean;
use function ob_get_level;
use function ob_start;
use function remove_action;
use function remove_filter;
use function set_transient;
use function strtolower;
use function update_post_meta;
use function vkbm_plugin;
use function wp_create_nonce;
use function wp_delete_post;
use function wp_generate_password;
use function wp_set_current_user;
use function wp_trash_post;
use function wp_untrash_post;

/**
 * Booking_Event_Dispatcher（#474）のテスト。
 *
 * 公開フック `vkbm_booking_state_changed` の発火回数・種別・ペイロードを、スパイ
 * （add_action で発火を記録するコールバック）で検証する。書式は
 * docs/ai-skills/skills/phpunit.md の慣習（条件・期待値の配列をループする形式）に合わせる。
 *
 * @group integrations
 */
class Booking_Event_Dispatcher_Test extends WP_UnitTestCase {
	private const META_DATE_START    = '_vkbm_booking_service_start';
	private const META_DATE_END      = '_vkbm_booking_service_end';
	private const META_TOTAL_END     = '_vkbm_booking_total_end';
	private const META_RESOURCE_ID   = '_vkbm_booking_resource_id';
	private const META_SERVICE_ID    = '_vkbm_booking_service_id';
	private const META_GUESTS        = '_vkbm_booking_guests';
	private const META_CUSTOMER      = '_vkbm_booking_customer_name';
	private const META_CUSTOMER_MAIL = '_vkbm_booking_customer_email';
	private const META_CUSTOMER_TEL  = '_vkbm_booking_customer_tel';
	private const META_STATUS        = '_vkbm_booking_status';

	private const TRANSIENT_PREFIX = 'vkbm_draft_';
	private const OWNER_COOKIE     = 'vkbm_draft_owner';

	/**
	 * 発火を記録したスパイの結果（[event, booking_id, payload] の配列）。
	 *
	 * @var array<int, array{0: string, 1: int, 2: array<string, mixed>}>
	 */
	private array $dispatched = array();

	/**
	 * スパイのコールバック（remove_action で確実に外すため参照を保持する）。
	 *
	 * @var callable
	 */
	private $spy;

	/**
	 * テストで作成した draft トークン（tearDown で transient を掃除するため保持）。
	 *
	 * @var array<int, string>
	 */
	private array $tokens = array();

	protected function setUp(): void {
		parent::setUp();

		$this->dispatched = array();
		$this->spy         = function ( string $event, int $booking_id, array $payload ): void {
			$this->dispatched[] = array( $event, $booking_id, $payload );
		};
		add_action( 'vkbm_booking_state_changed', $this->spy, 10, 3 );
	}

	protected function tearDown(): void {
		remove_action( 'vkbm_booking_state_changed', $this->spy, 10 );
		$_POST    = array();
		$_REQUEST = array();
		$_COOKIE  = array();
		wp_set_current_user( 0 );

		foreach ( $this->tokens as $token ) {
			delete_transient( self::TRANSIENT_PREFIX . $token );
		}
		$this->tokens = array();

		parent::tearDown();
	}

	/**
	 * Booking_Event_Dispatcher::dispatch_change() の種別判定を検証する。
	 *
	 * capture_snapshot() で取得した「変更前」と、実際にメタを書き換えた後の「変更後」を渡し、
	 * 想定どおりの種別で1回だけ発火すること、変更が無ければ発火しないことを確認する。
	 */
	public function test_dispatch_change(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '新規作成（変更前スナップショットが無い） => created で1回発火',
				'initial_status'      => null,
				'mutate'              => static function ( int $booking_id ): void {
					update_post_meta( $booking_id, self::META_STATUS, 'confirmed' );
				},
				'expected_event'      => Booking_Event_Dispatcher::EVENT_CREATED,
				'expected_dispatch'   => true,
			),
			array(
				'test_condition_name' => 'pending から confirmed に変更 => confirmed で1回発火',
				'initial_status'      => 'pending',
				'mutate'              => static function ( int $booking_id ): void {
					update_post_meta( $booking_id, self::META_STATUS, 'confirmed' );
				},
				'expected_event'      => Booking_Event_Dispatcher::EVENT_CONFIRMED,
				'expected_dispatch'   => true,
			),
			array(
				'test_condition_name' => 'confirmed から cancelled に変更 => cancelled で1回発火',
				'initial_status'      => 'confirmed',
				'mutate'              => static function ( int $booking_id ): void {
					update_post_meta( $booking_id, self::META_STATUS, 'cancelled' );
				},
				'expected_event'      => Booking_Event_Dispatcher::EVENT_CANCELLED,
				'expected_dispatch'   => true,
			),
			array(
				'test_condition_name' => 'confirmed から no_show に変更 => updated で1回発火（confirmed/cancelled 以外は updated に倒す）',
				'initial_status'      => 'confirmed',
				'mutate'              => static function ( int $booking_id ): void {
					update_post_meta( $booking_id, self::META_STATUS, 'no_show' );
				},
				'expected_event'      => Booking_Event_Dispatcher::EVENT_UPDATED,
				'expected_dispatch'   => true,
			),
			array(
				'test_condition_name' => 'confirmed から pending への巻き戻し => updated で1回発火',
				'initial_status'      => 'confirmed',
				'mutate'              => static function ( int $booking_id ): void {
					update_post_meta( $booking_id, self::META_STATUS, 'pending' );
				},
				'expected_event'      => Booking_Event_Dispatcher::EVENT_UPDATED,
				'expected_dispatch'   => true,
			),
			array(
				'test_condition_name' => 'ステータスは変えず開始日時だけ変更 => updated で1回発火（日時・メニュー変更の検知）',
				'initial_status'      => 'confirmed',
				'mutate'              => static function ( int $booking_id ): void {
					update_post_meta( $booking_id, self::META_DATE_START, '2024-07-01 11:00:00' );
				},
				'expected_event'      => Booking_Event_Dispatcher::EVENT_UPDATED,
				'expected_dispatch'   => true,
			),
			array(
				'test_condition_name' => '何も変更せず再保存 => 発火しない（異常系・境界値）',
				'initial_status'      => 'confirmed',
				'mutate'              => static function ( int $booking_id ): void {
					// 何もしない（変更なしの再保存を再現）。
				},
				'expected_event'      => null,
				'expected_dispatch'   => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->dispatched = array();
			$dispatcher        = new Booking_Event_Dispatcher();
			$booking_id        = $this->create_booking_post();

			if ( null !== $case['initial_status'] ) {
				update_post_meta( $booking_id, self::META_STATUS, $case['initial_status'] );
			}

			$before = null !== $case['initial_status'] ? $dispatcher->capture_snapshot( $booking_id ) : null;

			( $case['mutate'] )( $booking_id );

			$dispatcher->dispatch_change( $booking_id, $before );

			if ( $case['expected_dispatch'] ) {
				$this->assertCount( 1, $this->dispatched, $case['test_condition_name'] );
				$this->assertSame( $case['expected_event'], $this->dispatched[0][0], $case['test_condition_name'] );
				$this->assertSame( $booking_id, $this->dispatched[0][1], $case['test_condition_name'] );
			} else {
				$this->assertCount( 0, $this->dispatched, $case['test_condition_name'] );
			}
		}
	}

	/**
	 * シグネチャの組み立てに serialize() を使うことで、内容が異なる2件目の発火が重複排除で
	 * 誤ってスキップされないことを検証する（安藤レビュー指摘・再レビュー1対応 #474）。
	 *
	 * 差し戻し1回目では「wp_json_encode() は不正な UTF-8 を含む配列に対して false を返す」と
	 * いう前提でテストを書いたが、実機の WordPress 本体（wp-includes/functions.php）で確認した
	 * ところ、wp_json_encode() は json_encode() が失敗すると _wp_json_sanity_check() が不正バイト
	 * をサニタイズ（例: "\x80"・"\x81" はどちらも "?" になる）してから再エンコードするため、実際には
	 * false を返さない。そのため前回のテストは「is_string( $encoded ) === true」の分岐、つまり
	 * 修正前と同じ分岐を通って通っており、検証したかった性質を検証できていなかった
	 * （安藤レビュー指摘。B で指摘されたのと同じ「検証したい性質を検証できていない」形の再発）。
	 *
	 * 本当の穴は、このサニタイズが**別々の不正バイト列を同じ文字列に潰す**ことにあった。
	 * wp_json_encode() ベースのシグネチャだと、内容が違う（"\x80" と "\x81"）のにサニタイズ後は
	 * 同じ文字列になり、シグネチャが衝突して2件目が誤って捨てられる。serialize() はバイト列を
	 * そのまま扱うためこの2つを区別できる。ここでは、サニタイズを通すと同じ文字列に潰れてしまう
	 * 2つの異なる不正バイト列を使い、両方が別々に発火することを見る。
	 *
	 * capture_snapshot()/dispatch_change() 経由（DB往復）にすると、PHP 側の
	 * wpdb::strip_invalid_text_for_column() で不正なバイト列が保存時に失われるため、private の
	 * maybe_dispatch() を ReflectionMethod で直接呼び、シグネチャ計算そのものをピンポイントに
	 * 検証する。
	 */
	public function test_dispatch_change_does_not_dedupe_distinct_payloads_with_invalid_utf8(): void {
		$dispatcher = new Booking_Event_Dispatcher();
		$booking_id = $this->create_booking_post();

		$method = new ReflectionMethod( Booking_Event_Dispatcher::class, 'maybe_dispatch' );
		$method->setAccessible( true );

		// "\x80" と "\x81" は wp_json_encode() のサニタイズを通すとどちらも "?" になり
		// 区別できなくなる（wp_json_encode() ベースのシグネチャだとここで衝突していた）。
		$after_a = array(
			'status'        => 'confirmed',
			'customer_name' => "Customer \x80 A",
		);
		$after_b = array(
			'status'        => 'confirmed',
			'customer_name' => "Customer \x81 A",
		);

		$method->invoke( $dispatcher, Booking_Event_Dispatcher::EVENT_UPDATED, $booking_id, $after_a, $after_a, array( 'customer_name' ) );
		$method->invoke( $dispatcher, Booking_Event_Dispatcher::EVENT_UPDATED, $booking_id, $after_b, $after_b, array( 'customer_name' ) );

		$updated_events = array_values( array_filter( $this->dispatched, static fn( $d ) => Booking_Event_Dispatcher::EVENT_UPDATED === $d[0] ) );
		$this->assertCount( 2, $updated_events, 'サニタイズで同じ文字列に潰れる別々の不正バイト列でも、serialize() ベースのシグネチャなら両方発火するべき' );
		$this->assertSame( "Customer \x80 A", $updated_events[0][2]['booking']['customer_name'], '1件目のペイロードには値Aが入るべき' );
		$this->assertSame( "Customer \x81 A", $updated_events[1][2]['booking']['customer_name'], '2件目のペイロードには値Bが入るべき' );
	}

	/**
	 * capture_snapshot() が「実質的に存在しない予約」を null として扱うことを検証する。
	 */
	public function test_capture_snapshot(): void {
		$dispatcher = new Booking_Event_Dispatcher();

		$test_cases = array(
			array(
				'test_condition_name' => '投稿が存在しない => null（異常系）',
				'setup'                => static function () {
					return 999999999;
				},
			),
			array(
				'test_condition_name' => 'auto-draft の投稿 => null（管理画面の「新規追加」直後を再現）',
				'setup'                => function () {
					return (int) $this->factory()->post->create(
						array(
							'post_type'   => Booking_Post_Type::POST_TYPE,
							'post_status' => 'auto-draft',
						)
					);
				},
			),
			array(
				'test_condition_name' => 'ステータスメタが一度も書き込まれていない予約 => null（境界値）',
				'setup'                => function () {
					return (int) $this->factory()->post->create(
						array(
							'post_type'   => Booking_Post_Type::POST_TYPE,
							'post_status' => 'publish',
						)
					);
				},
			),
		);

		foreach ( $test_cases as $case ) {
			$booking_id = ( $case['setup'] )();
			$this->assertNull( $dispatcher->capture_snapshot( $booking_id ), $case['test_condition_name'] );
		}

		// 正常系: ステータスメタがある予約は null にならない。
		$normal_id = $this->create_booking_post();
		update_post_meta( $normal_id, self::META_STATUS, 'confirmed' );
		$this->assertIsArray( $dispatcher->capture_snapshot( $normal_id ), 'ステータスメタがある予約は配列を返すべき（正常系）' );
	}

	/**
	 * ゴミ箱移動・復元・完全削除（trashed_post/untrashed_post/before_delete_post）で、
	 * 本番の配線（vk-booking-manager.php で register 済みのディスパッチャー）から
	 * それぞれ1回だけ発火することを検証する。完全削除のペイロードには、メタが消える前に
	 * 確保したスナップショット（日時・担当スタッフを含む）が入ることも確認する。
	 */
	public function test_lifecycle_hooks(): void {
		// --- ゴミ箱移動 ---
		$booking_id = $this->create_booking_post();
		update_post_meta( $booking_id, self::META_STATUS, 'confirmed' );
		$this->dispatched = array();

		wp_trash_post( $booking_id );

		$trashed_events = array_values( array_filter( $this->dispatched, static fn( $d ) => Booking_Event_Dispatcher::EVENT_TRASHED === $d[0] ) );
		$this->assertCount( 1, $trashed_events, 'ゴミ箱移動で trashed が1回だけ発火するべき' );
		$this->assertSame( $booking_id, $trashed_events[0][1] );

		// --- ゴミ箱からの復元 ---
		$this->dispatched = array();
		wp_untrash_post( $booking_id );

		$restored_events = array_values( array_filter( $this->dispatched, static fn( $d ) => Booking_Event_Dispatcher::EVENT_RESTORED === $d[0] ) );
		$this->assertCount( 1, $restored_events, 'ゴミ箱からの復元で restored が1回だけ発火するべき' );
		$this->assertSame( $booking_id, $restored_events[0][1] );

		// --- 完全削除 ---
		update_post_meta( $booking_id, self::META_DATE_START, '2024-08-01 10:00:00' );
		update_post_meta( $booking_id, self::META_TOTAL_END, '2024-08-01 10:30:00' );
		update_post_meta( $booking_id, self::META_RESOURCE_ID, 123 );
		$this->dispatched = array();

		wp_delete_post( $booking_id, true );

		$deleted_events = array_values( array_filter( $this->dispatched, static fn( $d ) => Booking_Event_Dispatcher::EVENT_DELETED === $d[0] ) );
		$this->assertCount( 1, $deleted_events, '完全削除で deleted が1回だけ発火するべき' );
		$payload = $deleted_events[0][2];
		$this->assertSame( '2024-08-01 10:00:00', $payload['booking']['start'], '完全削除のペイロードに開始日時が含まれるべき' );
		$this->assertSame( '2024-08-01 10:30:00', $payload['booking']['total_end'], '完全削除のペイロードに後片付け込み終了日時が含まれるべき' );
		$this->assertSame( 123, $payload['booking']['resource_id'], '完全削除のペイロードに担当スタッフが含まれるべき' );
	}

	/**
	 * 予約（vkbm_booking）以外の投稿タイプでは、完全削除・ゴミ箱移動のいずれでも発火しないことを
	 * 検証する（安藤レビュー指摘D対応 #474）。守りたいガード（capture_snapshot() / dispatch_lifecycle_event()
	 * / handle_before_delete_post() の投稿タイプ判定）そのものを押さえるテストがこれまで無く、
	 * 将来のリグレッション（判定条件の書き換え等）を検知できない状態だった。
	 *
	 * 完全削除の対象は `attachment` ではなく通常の `post` にする（安藤レビュー指摘・再レビュー1
	 * 対応）。WordPress 本体は `wp_delete_post()` の冒頭で添付ファイルを `wp_delete_attachment()`
	 * へ委譲するため、`attachment` を対象にすると `before_delete_post` 自体が発火せず、
	 * `handle_before_delete_post()` の投稿タイプ判定（守りたいガードそのもの）を一度も通らずに
	 * assert が通ってしまっていた。
	 */
	public function test_lifecycle_hooks_ignore_non_booking_post_types(): void {
		$deleted_post_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);
		$page_id         = (int) $this->factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);

		$this->dispatched = array();

		wp_delete_post( $deleted_post_id, true );
		wp_trash_post( $page_id );

		$this->assertSame( array(), $this->dispatched, '予約（vkbm_booking）以外の投稿タイプの完全削除・ゴミ箱移動では、外部連携用のフックが発火しないべき' );
	}

	/**
	 * 入れ子の保存（Booking_Admin::save_post が投稿者変更で自分自身を再発火させる、
	 * #477 で扱う既存の疑いと同じ状況。この issue では直さない）でも、外部連携用の発火が
	 * 1回に重複排除されることを検証する（安藤レビュー指摘B対応 #474）。
	 *
	 * 差し戻し1回目では、ディスパッチャーの公開APIを直接2回呼び、しかも同じ $before 配列を
	 * 2回とも渡す形にしていた。しかしこれでは「入れ子の内側・外側がそれぞれ別のタイミングで
	 * capture_snapshot() を呼び、その2つが一致する保証はコード上どこにもない」という性質が
	 * 検証できていなかった（安藤レビュー指摘）。build_snapshot() は post_status まで含むため、
	 * 外側が wp_update_post() を呼ぶ前に何か書いていれば内側のスナップショットはずれ、シグネチャが
	 * 変わって本当に2回発火しうる。
	 *
	 * ここでは new Booking_Event_Dispatcher() / new Booking_Admin() を作らず、本番配線
	 * （vk-booking-manager.php のブートストラップで登録済みの単一インスタンス）を
	 * get_production_booking_admin() 経由で取得して使う。差し戻し1回目の問題（テストが独自の
	 * Booking_Admin を real フックへ追加登録し、本番側と二重に発火した）はこれで再発しない
	 * （test_lifecycle_hooks() と同じ考え方）。投稿者を変更する保存データを渡すと、
	 * Booking_Admin::save_post() 内部の wp_update_post()（クラス doc コメント参照）が
	 * save_post_vkbm_booking フックを実際に入れ子で再発火させる。内側・外側それぞれが
	 * 独立に capture_snapshot() を呼ぶ「実際の入れ子」を、テストが値を捏造せずそのまま検証する。
	 */
	public function test_dispatch_change_deduplicates_nested_calls(): void {
		$admin = $this->get_production_booking_admin();

		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();
		$this->set_current_user_with_caps();

		$original_author_id = $this->create_user();
		$new_author_id      = $this->create_user();

		$booking_id = $this->create_booking_post( $original_author_id );
		update_post_meta( $booking_id, self::META_STATUS, 'pending' );
		update_post_meta( $booking_id, self::META_SERVICE_ID, $menu_id );
		update_post_meta( $booking_id, self::META_RESOURCE_ID, $staff_id );

		// 投稿者を変更するデータを渡す。status も confirmed へ変更し、実際の入れ子の保存経路で
		// confirmed が1回に重複排除されることまで確認する。
		$this->set_booking_post_data( $menu_id, $staff_id, $new_author_id, '10:00', '10:30' );
		$_POST['vkbm_booking']['status'] = 'confirmed';

		// #474 レビュー対応（安藤レビュー指摘・再レビュー1対応）: assertCount( 1, ... ) だけでは
		// 「入れ子が一度も起きなかった場合」も通ってしまう（#477 で入れ子そのものを止める修正が
		// 入ると、このテストは黙って空回りしたまま緑になる）。save_post_vkbm_booking の発火回数を
		// 別途数え、実際に入れ子（2回以上の発火）が起きたことを明示的に押さえる。
		$save_post_calls = 0;
		$counter         = static function () use ( &$save_post_calls ): void {
			++$save_post_calls;
		};
		add_action( 'save_post_' . Booking_Post_Type::POST_TYPE, $counter, 1 );

		$this->dispatched = array();
		$admin->save_post( $booking_id, get_post( $booking_id ) );

		remove_action( 'save_post_' . Booking_Post_Type::POST_TYPE, $counter, 1 );

		$this->assertGreaterThanOrEqual( 1, $save_post_calls, '投稿者変更で save_post が実際に入れ子で再発火しているべき（この前提が消えたら重複排除のテストは意味を失う）' );

		$confirmed_events = array_values( array_filter( $this->dispatched, static fn( $d ) => Booking_Event_Dispatcher::EVENT_CONFIRMED === $d[0] ) );
		$this->assertCount( 1, $confirmed_events, '投稿者変更で実際に入れ子（save_post の再発火）が起きても、confirmed の発火は1回に重複排除されるべき' );
	}

	/**
	 * 5つの差し込み場所（フロント作成／管理画面保存／クイック編集／お客様キャンセル／
	 * ダッシュボード確定）それぞれで、外部連携用の発火が1回だけ起きることを検証する。
	 */
	public function test_five_injection_points_dispatch_once(): void {
		$this->assert_confirmation_controller_dispatches_created_once();
		$this->assert_admin_save_post_dispatches_once();
		$this->assert_admin_quick_edit_dispatches_once();
		$this->assert_my_bookings_cancel_dispatches_once();
		$this->assert_shift_dashboard_confirm_dispatches_once();
	}

	/**
	 * フロントからの予約作成（Booking_Confirmation_Controller）で created が1回だけ発火し、
	 * 既存の通知メール呼び出し回数が変わっていないことを検証する。
	 */
	private function assert_confirmation_controller_dispatches_created_once(): void {
		$dispatcher = new Booking_Event_Dispatcher();
		$menu_id    = $this->create_menu();
		$staff_id   = $this->create_staff();

		$start = '2026-09-01T10:00:00+09:00';
		$end   = '2026-09-01T10:30:00+09:00';

		$notification = new Counting_Notification_Service_Double( new Settings_Repository() );
		$availability = new Availability_Service_Test_Double(
			array(
				'slot_id'              => 'slot-1',
				'start_at'             => $start,
				'end_at'               => $end,
				'service_end_at'       => $end,
				'staff'                => array( 'id' => $staff_id ),
				'assignable_staff_ids' => array( $staff_id ),
			)
		);

		$controller = new Booking_Confirmation_Controller( $notification, new Settings_Repository(), $availability, $dispatcher );

		$user_id = $this->create_user();
		wp_set_current_user( $user_id );
		$_COOKIE[ self::OWNER_COOKIE ] = 'owner_test';

		$token   = 'token_' . strtolower( wp_generate_password( 8, false, false ) );
		$payload = array(
			'menu_id'              => $menu_id,
			'resource_id'          => $staff_id,
			'guests'               => 1,
			'slot'                 => array(
				'slot_id'  => 'slot-1',
				'start_at' => $start,
				'end_at'   => $end,
			),
			'assignable_staff_ids' => array( $staff_id ),
			'meta'                 => array( 'timezone' => 'Asia/Tokyo' ),
		);
		set_transient( self::TRANSIENT_PREFIX . $token, $payload );
		$this->tokens[] = $token;

		$this->dispatched = array();

		$request = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
		$request->set_param( 'token', $token );
		$request->set_param( 'agree_terms', true );

		$response = $controller->create_booking( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response, 'フロント作成: booking should succeed' );

		$created_events = array_values( array_filter( $this->dispatched, static fn( $d ) => Booking_Event_Dispatcher::EVENT_CREATED === $d[0] ) );
		$this->assertCount( 1, $created_events, 'フロントからの予約作成で created が1回だけ発火するべき' );
		$this->assertSame( 1, $notification->confirmed_creation_calls, '既存の通知メール（確定作成）の呼び出し回数は変わらないべき' );

		wp_set_current_user( 0 );
	}

	/**
	 * 管理画面での保存（Booking_Admin::save_post）で updated が1回だけ発火することを検証する。
	 *
	 * 安藤レビュー指摘B対応（#474）: 従来は投稿者を変更しない（author_id=0＝未送信）データで
	 * new Booking_Admin() を直接呼んでおり、Booking_Admin::save_post() 内部の wp_update_post()
	 * （投稿者変更時のみ）が一度も走らないため、実際には入れ子（save_post の再発火）が起きない
	 * まま「1回だけ発火する」ことを確認していた（たまたま通っている状態だった）。ここでは
	 * new Booking_Admin() を作らず本番配線（get_production_booking_admin()）を使い、投稿者を
	 * 変更するデータを渡すことで、この差し込み場所でも実際の入れ子を経由して1回に重複排除される
	 * ことまで検証する。
	 */
	private function assert_admin_save_post_dispatches_once(): void {
		$admin = $this->get_production_booking_admin();

		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();
		$this->set_current_user_with_caps();

		$original_author_id = $this->create_user();
		$new_author_id      = $this->create_user();

		$booking_id = $this->create_booking_post( $original_author_id );
		update_post_meta( $booking_id, self::META_STATUS, 'confirmed' );
		update_post_meta( $booking_id, self::META_SERVICE_ID, $menu_id );
		update_post_meta( $booking_id, self::META_RESOURCE_ID, $staff_id );

		// 日時変更に加えて投稿者も変更する（内部の wp_update_post() を誘発し、save_post を
		// 実際に入れ子で再発火させるため）。
		$this->set_booking_post_data( $menu_id, $staff_id, $new_author_id, '10:00', '11:00' );

		$this->dispatched = array();
		$admin->save_post( $booking_id, get_post( $booking_id ) );

		$updated_events = array_values( array_filter( $this->dispatched, static fn( $d ) => Booking_Event_Dispatcher::EVENT_UPDATED === $d[0] ) );
		$this->assertCount( 1, $updated_events, '管理画面での保存（日時変更・投稿者変更による実際の入れ子を含む）で updated が1回だけ発火するべき' );
	}

	/**
	 * クイック編集（Booking_Admin::save_quick_edit）で cancelled が1回だけ発火することを検証する
	 * （issue 本文に無い5箇所目。司の decision record 参照）。
	 */
	private function assert_admin_quick_edit_dispatches_once(): void {
		$dispatcher = new Booking_Event_Dispatcher();
		$admin      = new Booking_Admin( null, $dispatcher );

		$this->set_current_user_with_caps();

		$booking_id = $this->create_booking_post();
		update_post_meta( $booking_id, self::META_STATUS, 'confirmed' );

		$_POST = array(
			'_vkbm_booking_quick_nonce' => wp_create_nonce( 'vkbm_booking_quick_edit' ),
			'vkbm_booking'              => array( 'status' => 'cancelled' ),
		);

		$this->dispatched = array();
		$admin->save_quick_edit( $booking_id, get_post( $booking_id ), true );

		$cancelled_events = array_values( array_filter( $this->dispatched, static fn( $d ) => Booking_Event_Dispatcher::EVENT_CANCELLED === $d[0] ) );
		$this->assertCount( 1, $cancelled_events, 'クイック編集でのキャンセルで cancelled が1回だけ発火するべき' );

		$_POST = array();
	}

	/**
	 * お客様自身によるキャンセル（My_Bookings_Controller::cancel_booking）で cancelled が
	 * 1回だけ発火し、既存の通知メール呼び出し回数が変わっていないことを検証する。
	 */
	private function assert_my_bookings_cancel_dispatches_once(): void {
		$dispatcher   = new Booking_Event_Dispatcher();
		$notification = new Counting_Notification_Service_Double( new Settings_Repository() );
		$controller   = new My_Bookings_Controller( new Settings_Repository(), $notification, $dispatcher );

		$user_id    = $this->create_user();
		$booking_id = $this->create_booking_post( $user_id );
		update_post_meta( $booking_id, self::META_STATUS, 'confirmed' );
		update_post_meta( $booking_id, self::META_DATE_START, '2099-01-01 10:00:00' );

		wp_set_current_user( $user_id );

		$this->dispatched = array();

		$request = new WP_REST_Request( 'POST', '/vkbm/v1/my-bookings/' . $booking_id . '/cancel' );
		$request->set_param( 'id', $booking_id );
		$controller->cancel_booking( $request );

		$cancelled_events = array_values( array_filter( $this->dispatched, static fn( $d ) => Booking_Event_Dispatcher::EVENT_CANCELLED === $d[0] ) );
		$this->assertCount( 1, $cancelled_events, 'お客様自身のキャンセルで cancelled が1回だけ発火するべき' );
		$this->assertSame( 1, $notification->customer_cancellation_calls, '既存の通知メール（お客様キャンセル）の呼び出し回数は変わらないべき' );

		wp_set_current_user( 0 );
	}

	/**
	 * シフトダッシュボードからの確定（Shift_Dashboard_Page::ajax_confirm_booking）で
	 * confirmed が1回だけ発火し、既存の通知メール呼び出し回数が変わっていないことを検証する。
	 */
	private function assert_shift_dashboard_confirm_dispatches_once(): void {
		$dispatcher   = new Booking_Event_Dispatcher();
		$notification = new Counting_Notification_Service_Double( new Settings_Repository() );
		$page         = new Shift_Dashboard_Page( Capabilities::MANAGE_PROVIDER_SETTINGS, $notification, $dispatcher );

		$this->set_current_user_with_caps( Capabilities::MANAGE_PROVIDER_SETTINGS );

		$booking_id = $this->create_booking_post();
		update_post_meta( $booking_id, self::META_STATUS, 'pending' );

		$_REQUEST['nonce']   = wp_create_nonce( 'vkbm_confirm_booking' );
		$_POST['booking_id'] = $booking_id;

		$this->dispatched = array();

		// #474 レビュー対応（実機の PHPUnit 失敗で発覚）: wp_send_json_success() が呼ぶ
		// wp_send_json() は、wp_doing_ajax() が false のときは wp_die() すら経由せず、
		// 素の die; で PHP プロセスごと終了させる（wp_die_handler フィルタでも捕まえられない）。
		// これを直接メソッド呼び出しするこのテストでは DOING_AJAX 定数が立たないため
		// wp_doing_ajax() は既定で false になり、そのまま die; に落ちて PHPUnit ごと
		// 巻き込まれて落ちていた（新設テストだけでなく、後続の全テストが実行されないまま
		// 終了コード0で終わっていた）。WP_Ajax_UnitTestCase（wp-phpunit の
		// includes/testcase-ajax.php）と同じ手当てを、このメソッド呼び出しの前後だけに
		// 限定して行う: 'wp_doing_ajax' を true に固定し、'wp_die_ajax_handler' で
		// 例外を投げるハンドラに差し替えることで、wp_send_json() を wp_die() 経由の
		// 捕捉可能な経路へ通す。
		$doing_ajax_filter = '__return_true';
		$die_handler       = static function () {
			return static function ( $message ) {
				throw new WPDieException( is_scalar( $message ) ? (string) $message : '0' );
			};
		};

		add_filter( 'wp_doing_ajax', $doing_ajax_filter );
		add_filter( 'wp_die_ajax_handler', $die_handler );

		// #474 レビュー対応（安藤レビュー指摘E対応、再レビュー1で戻す順序を修正）: 呼び出し前の
		// バッファ段数を控えておく。元の ob_end_clean() は1段しか戻さない。今の
		// ajax_confirm_booking() は内部で ob_start() を使っていないため実害は無いが、将来内部で
		// バッファを開いたまま例外で抜けると、閉じ残しが後続テストへ漏れる。呼び出し前の段数まで
		// 確実に戻すようにする。
		//
		// 戻す順序に注意: 先に ob_get_clean() を呼ぶと、内部で追加のバッファが開かれていた場合に
		// 拾うのは一番内側の内容になり、このテストが本来取りたい JSON（一番外側＝このテストが
		// 自分で ob_start() した段）は後続の while で中身を見ずに捨てられてしまう。まず内側の
		// 余分なバッファだけを空で閉じ、最後に自分の段を ob_get_clean() で取得する。
		$ob_level      = ob_get_level();
		$response_body = '';

		ob_start();
		try {
			$page->ajax_confirm_booking();
		} catch ( WPDieException $e ) {
			// wp_send_json_success() が最後に wp_die() を呼ぶため、想定どおりの終了として握りつぶす。
		} finally {
			// 自分が開いた段（$ob_level + 1）より内側に残っているバッファだけを先に空で閉じる。
			while ( ob_get_level() > $ob_level + 1 ) {
				ob_end_clean();
			}
			// 最後に自分の段を取得しつつ閉じる。これでレスポンス本文を捨てずに取得できる。
			$response_body = (string) ob_get_clean();
			remove_filter( 'wp_doing_ajax', $doing_ajax_filter );
			remove_filter( 'wp_die_ajax_handler', $die_handler );
		}

		$confirmed_events = array_values( array_filter( $this->dispatched, static fn( $d ) => Booking_Event_Dispatcher::EVENT_CONFIRMED === $d[0] ) );
		$this->assertCount( 1, $confirmed_events, 'ダッシュボードからの確定で confirmed が1回だけ発火するべき' );
		$this->assertSame( 1, $notification->status_transition_calls, '既存の通知メール（ステータス変更）の呼び出し回数は変わらないべき' );

		// 取得したレスポンス本文が success:true の JSON であることも確認する（安藤レビュー指摘E対応）。
		$response = json_decode( $response_body, true );
		$this->assertIsArray( $response, 'ダッシュボードからの確定APIのレスポンスは JSON として読めるべき' );
		$this->assertTrue( $response['success'] ?? false, 'ダッシュボードからの確定APIは success:true を返すべき' );

		unset( $_REQUEST['nonce'], $_POST['booking_id'] );
	}

	/**
	 * 予約投稿を作成する。
	 *
	 * @param int $author_id 予約者のユーザーID（省略時は自動作成）。
	 * @return int 予約投稿ID。
	 */
	private function create_booking_post( int $author_id = 0 ): int {
		if ( 0 === $author_id ) {
			$author_id = $this->create_user();
		}

		$booking_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_author' => $author_id,
			)
		);

		update_post_meta( $booking_id, self::META_DATE_START, '2024-07-01 10:00:00' );
		update_post_meta( $booking_id, self::META_DATE_END, '2024-07-01 10:30:00' );
		update_post_meta( $booking_id, self::META_TOTAL_END, '2024-07-01 10:30:00' );
		update_post_meta( $booking_id, self::META_CUSTOMER, 'Test Customer' );
		update_post_meta( $booking_id, self::META_CUSTOMER_MAIL, 'test@example.com' );
		update_post_meta( $booking_id, self::META_CUSTOMER_TEL, '000-0000-0000' );

		return $booking_id;
	}

	/**
	 * サービスメニューを作成する。
	 *
	 * @return int メニュー投稿ID。
	 */
	private function create_menu(): int {
		return (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * スタッフ（リソース）を作成する。
	 *
	 * @return int スタッフ投稿ID。
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
	 * 一般ユーザーを作成する。
	 *
	 * @return int ユーザーID。
	 */
	private function create_user(): int {
		return (int) $this->factory()->user->create();
	}

	/**
	 * 予約管理権限を持つ管理者ユーザーを作成しログイン状態にする。
	 *
	 * @param string $extra_cap 追加で付与する権限（省略可）。
	 */
	private function set_current_user_with_caps( string $extra_cap = '' ): void {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$user = get_user_by( 'id', $user_id );
		$user->add_cap( Capabilities::MANAGE_RESERVATIONS );
		if ( '' !== $extra_cap ) {
			$user->add_cap( $extra_cap );
		}
	}

	/**
	 * 本番配線で登録済みの Booking_Admin インスタンスを取得する（安藤レビュー指摘B対応 #474）。
	 *
	 * テスト側で new Booking_Admin() を作ると、投稿者変更等でこの予約投稿に対する
	 * wp_update_post() が走った際、本番の Booking_Admin（vk-booking-manager.php の
	 * ブートストラップが save_post_vkbm_booking へ既に登録済み）も WordPress の実フック経由で
	 * 連動して発火してしまい、テスト用インスタンスと本番インスタンスそれぞれが持つ別の
	 * Booking_Event_Dispatcher が二重に発火する（差し戻し1回目で発覚した問題と同じ原因）。
	 * 本番の単一インスタンスをリフレクションで取得して使うことで、登録されている
	 * Booking_Admin は常に1つだけになり、実際の入れ子（save_post の再発火）を安全に検証できる
	 * （test_lifecycle_hooks() が WordPress 本体のライフサイクルフックに対して行っているのと
	 * 同じ考え方）。
	 *
	 * @return Booking_Admin
	 */
	private function get_production_booking_admin(): Booking_Admin {
		$plugin = vkbm_plugin();
		$this->assertInstanceOf( Plugin::class, $plugin, 'テスト実行時には本番の Plugin インスタンスが構築済みであるべき' );

		$property = new ReflectionProperty( $plugin, 'booking_admin' );
		$property->setAccessible( true );

		$admin = $property->getValue( $plugin );
		$this->assertInstanceOf( Booking_Admin::class, $admin );

		return $admin;
	}

	/**
	 * 予約編集画面の保存（$_POST）をシミュレートする。
	 *
	 * @param int    $menu_id   サービスメニューID。
	 * @param int    $staff_id  担当スタッフID。
	 * @param int    $author_id 予約者として設定するユーザーID（0 なら送信しない）。
	 * @param string $start_time 開始時刻（H:i）。
	 * @param string $end_time   終了時刻（H:i）。
	 */
	private function set_booking_post_data( int $menu_id, int $staff_id, int $author_id, string $start_time = '10:00', string $end_time = '10:30' ): void {
		$booking = array(
			'date'       => '2024-07-01',
			'start_time' => $start_time,
			'end_time'   => $end_time,
			'service_id' => $menu_id,
			'resource_id' => $staff_id,
			'status'     => 'confirmed',
			'customer'   => 'Test Customer',
		);

		if ( $author_id > 0 ) {
			$booking['author_id'] = $author_id;
		}

		$_POST = array(
			'_vkbm_booking_meta_nonce' => wp_create_nonce( 'vkbm_booking_meta' ),
			'vkbm_booking'             => $booking,
		);
	}
}

/**
 * 通知メールの呼び出し回数を数えるダブル。実際のメール送信は行わない。
 *
 * 既存の通知メールの動作（呼び出し回数）が、外部連携ディスパッチャーの追加で
 * 変わっていないことを検証するために使う。
 */
class Counting_Notification_Service_Double extends Booking_Notification_Service {
	/** @var int */
	public int $confirmed_creation_calls = 0;

	/** @var int */
	public int $pending_creation_calls = 0;

	/** @var int */
	public int $customer_cancellation_calls = 0;

	/** @var int */
	public int $status_transition_calls = 0;

	public function handle_confirmed_creation( int $booking_id ): void {
		++$this->confirmed_creation_calls;
	}

	public function handle_pending_creation( int $booking_id ): void {
		++$this->pending_creation_calls;
	}

	public function handle_customer_cancellation( int $booking_id ): void {
		++$this->customer_cancellation_calls;
	}

	public function handle_status_transition( int $booking_id, string $previous_status, string $status ): void {
		++$this->status_transition_calls;
	}
}

/**
 * Availability_Service のテストダブル。固定の1枠だけを返す。
 */
class Availability_Service_Test_Double extends Availability_Service {
	/** @var array<string, mixed> */
	private array $slot;

	/**
	 * @param array<string, mixed> $slot
	 */
	public function __construct( array $slot ) {
		$this->slot = $slot;
	}

	public function get_daily_slots( array $args ) {
		return array(
			'slots' => array( $this->slot ),
		);
	}
}
