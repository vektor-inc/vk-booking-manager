<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use VKBookingManager\Bookings\Booking_Admin;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Notifications\Booking_Notification_Service;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_Post;
use WP_UnitTestCase;
use function get_current_user_id;
use function get_post;
use function get_post_meta;
use function get_transient;
use function get_user_by;
use function update_post_meta;
use function wp_create_nonce;
use function wp_set_current_user;

/**
 * #477: 管理画面での予約ステータス変更にまつわる通知メール送信入口
 * （Booking_Notification_Service::handle_status_transition）の呼び出し回数を検証する。
 *
 * 1件目: 予約編集画面で担当者（投稿者）とステータスを同時に変更すると、
 *        save_post() の中の wp_update_post() が save_post_{post_type} フックを
 *        再発火させ、save_post() 自身が入れ子（再入）で呼ばれる。再入ガードが
 *        無いと通知送信の入口が2回呼ばれてしまうため、1回に収まることを確認する。
 *
 * 2件目: 予約一覧のクイック編集（save_quick_edit）は、これまで通知送信の入口を
 *        一度も呼んでいなかった。編集画面から同じ変更をしたときと同じく、
 *        1回だけ呼ばれるようになったことを確認する。
 *
 * @group bookings
 * @group notifications
 */
class Booking_Admin_Status_Notification_Test extends WP_UnitTestCase {
	private const META_STATUS      = '_vkbm_booking_status';
	private const META_RESOURCE_ID = '_vkbm_booking_resource_id';
	// 通知 transient のキーは「プレフィックス + ユーザーID + '_' + 投稿ID」（class-booking-admin.php の
	// push_admin_notice() と同じ規則。既存の tests/phpunit/bookings/test-booking-admin-guests.php と同じ書き方）。
	private const NOTICE_TRANSIENT_PREFIX = 'vkbm_booking_staff_conflict_';

	protected function setUp(): void {
		parent::setUp();
		$this->set_current_user_with_caps();
	}

	protected function tearDown(): void {
		$_POST = array();
		parent::tearDown();
	}

	/**
	 * save_post() のテスト。
	 *
	 * 担当者（投稿者）変更・タイトル自動補完のどちらか一方、または両方が
	 * wp_update_post() を発火させて save_post() を入れ子で呼び出しても、
	 * 通知送信の入口（handle_status_transition）が1回だけ呼ばれることを確認する。
	 */
	public function test_save_post(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '担当者変更あり・タイトルは既存のまま（author_id 変更による wp_update_post() のみ再入） => 通知入口は1回（正常系）',
				'previous_status'     => 'pending',
				'new_status'          => 'confirmed',
				'change_author'       => true,
				'empty_title'         => false,
			),
			array(
				'test_condition_name' => '担当者変更なし・タイトル未設定でステータス変更（タイトル自動補完による wp_update_post() のみ再入） => 通知入口は1回（正常系）',
				'previous_status'     => 'pending',
				'new_status'          => 'confirmed',
				'change_author'       => false,
				'empty_title'         => true,
			),
			array(
				'test_condition_name' => '担当者変更あり・タイトル未設定（2つの wp_update_post() が両方とも再入） => 通知入口は1回（境界値）',
				'previous_status'     => 'pending',
				'new_status'          => 'confirmed',
				'change_author'       => true,
				'empty_title'         => true,
			),
			array(
				'test_condition_name' => '担当者変更あり・ステータスは confirmed のまま変化なし => 入口は1回呼ばれるが確定メールは対象外（境界値）',
				'previous_status'     => 'confirmed',
				'new_status'          => 'confirmed',
				'change_author'       => true,
				'empty_title'         => false,
			),
			array(
				// #477 レビュー対応（安藤さん指摘・3件目）: 本番では save_post と save_quick_edit が
				// 同じ save_post_{post_type} フックに同居している。この状態を再現し、再入で
				// save_quick_edit 側も一緒に発火しても、クイック編集用nonceが $_POST に無いため
				// 反応せず、通知入口の呼び出し回数が増えないことを確認する。
				'test_condition_name'      => '同じフックに save_quick_edit も同居（本番と同じ状態）・クイック編集用nonceは無い => save_quick_edit は反応せず通知入口は1回のまま（正常系）',
				'previous_status'          => 'pending',
				'new_status'               => 'confirmed',
				'change_author'            => true,
				'empty_title'              => false,
				'also_register_quick_edit' => true,
			),
		);

		foreach ( $test_cases as $case ) {
			$author_a = (int) $this->factory()->user->create( array( 'role' => 'administrator' ) );
			$author_b = (int) $this->factory()->user->create( array( 'role' => 'administrator' ) );
			$staff_id = $this->create_staff( 'Staff ' . $case['test_condition_name'] );

			$post_args = array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_author' => $author_a,
			);
			// タイトル未設定ケースは maybe_update_post_title() 側の wp_update_post() を発火させるため、
			// 明示的に空タイトルで作成する（factory 標準のタイトルだと非空になり発火しない）。
			if ( $case['empty_title'] ) {
				$post_args['post_title'] = '';
			}
			$booking_id = (int) $this->factory()->post->create( $post_args );
			update_post_meta( $booking_id, self::META_STATUS, $case['previous_status'] );

			$_POST = array(
				'_vkbm_booking_meta_nonce' => wp_create_nonce( 'vkbm_booking_meta' ),
				'vkbm_booking'             => array(
					'date'        => '2026-08-01',
					'start_time'  => '10:00',
					'end_time'    => '11:00',
					'resource_id' => $staff_id,
					'status'      => $case['new_status'],
					'customer'    => 'テスト太郎',
				),
			);
			if ( $case['change_author'] ) {
				$_POST['vkbm_booking']['author_id'] = $author_b;
			}

			$recorder = new Booking_Notification_Service_Call_Recorder();
			$admin    = new Booking_Admin( $recorder );

			// 実運用と同じ再入経路を再現するため、save_post を save_post_{post_type} フックへ登録する
			// （wp_update_post() がこのフックを再発火させ、save_post 自身を入れ子で呼び出す）。
			$hook            = 'save_post_' . Booking_Post_Type::POST_TYPE;
			$also_quick_edit = ! empty( $case['also_register_quick_edit'] );
			add_action( $hook, array( $admin, 'save_post' ), 10, 2 );
			if ( $also_quick_edit ) {
				// 本番の register() と同じく、同じフックへ save_quick_edit も同時に登録する。
				add_action( $hook, array( $admin, 'save_quick_edit' ), 10, 3 );
			}

			$post = get_post( $booking_id );
			$this->assertInstanceOf( WP_Post::class, $post, $case['test_condition_name'] );
			$admin->save_post( $booking_id, $post );

			remove_action( $hook, array( $admin, 'save_post' ), 10 );
			if ( $also_quick_edit ) {
				remove_action( $hook, array( $admin, 'save_quick_edit' ), 10 );
			}

			$this->assertSame(
				1,
				$recorder->status_transition_calls,
				$case['test_condition_name']
			);

			$expected_author = $case['change_author'] ? $author_b : $author_a;
			$this->assertSame(
				$expected_author,
				(int) get_post( $booking_id )->post_author,
				$case['test_condition_name'] . '（担当者は入れ子経路でも正しく1回だけ更新される）'
			);
		}
	}

	/**
	 * save_quick_edit() のテスト。
	 *
	 * クイック編集でステータスを変更した場合に、編集画面と同じ通知送信の入口
	 * （handle_status_transition）が1回呼ばれることを確認する。
	 * 新規投稿（$update = false）のときは呼ばれないことも境界値として確認する。
	 *
	 * #477 レビュー対応（安藤さん指摘・1件目）: 編集画面（save_post_inner）は、枠を消費する
	 * ステータスへ変更するとき担当スタッフが未割当なら保存ごと中断している。クイック編集も
	 * 同じガードを持つようにしたため、担当スタッフ未割当のまま「確定」にしようとした場合は
	 * 保存も通知も行われないことをあわせて確認する（メールは取り消せないため、ここは厳しめに見る）。
	 */
	public function test_save_quick_edit(): void {
		$test_cases = array(
			array(
				'test_condition_name'   => '担当スタッフ割当あり・pending から confirmed へクイック編集 => 通知入口が1回呼ばれる（正常系）',
				'previous_status'       => 'pending',
				'new_status'            => 'confirmed',
				'is_update'             => true,
				'assign_staff'          => true,
				'expected_calls'        => 1,
				'expect_status_updated' => true,
				'expect_staff_notice'   => false,
			),
			array(
				'test_condition_name'   => '担当スタッフ割当なし・confirmed から cancelled へクイック編集 => キャンセル方向は枠を消費しないため通知入口が1回呼ばれる（正常系）',
				'previous_status'       => 'confirmed',
				'new_status'            => 'cancelled',
				'is_update'             => true,
				'assign_staff'          => false,
				'expected_calls'        => 1,
				'expect_status_updated' => true,
				'expect_staff_notice'   => false,
			),
			array(
				'test_condition_name'   => '新規投稿扱い（$update = false）のときは呼ばれない（境界値）',
				'previous_status'       => 'pending',
				'new_status'            => 'confirmed',
				'is_update'             => false,
				'assign_staff'          => true,
				'expected_calls'        => 0,
				'expect_status_updated' => false,
				'expect_staff_notice'   => false,
			),
			array(
				'test_condition_name'   => '担当スタッフ未割当のまま confirmed へクイック編集 => 保存も通知も行われず、担当スタッフ必須の管理通知が登録される（異常系）',
				'previous_status'       => 'pending',
				'new_status'            => 'confirmed',
				'is_update'             => true,
				'assign_staff'          => false,
				'expected_calls'        => 0,
				'expect_status_updated' => false,
				'expect_staff_notice'   => true,
			),
			array(
				// #477 レビュー対応（安藤さん・植草さん指摘・4件目）: ガードが「確定へ変わるとき」
				// ではなく is_staff_check_target_status() が真のステータスへ送信されるたび毎回
				// 発火していたため、担当スタッフ未割当の予約で confirmed のままステータス以外を
				// 保存しただけ（クイック編集は現在の値をそのまま再送する）でも保存が中断されていた。
				// この修正より前は成功していた操作のため、変化がない場合はガードを発火させない。
				'test_condition_name'   => '担当スタッフ未割当・confirmed のまま変更なしで再送 => ガードは発火せず保存・通知入口が1回呼ばれる（境界値・レビュー対応）',
				'previous_status'       => 'confirmed',
				'new_status'            => 'confirmed',
				'is_update'             => true,
				'assign_staff'          => false,
				'expected_calls'        => 1,
				'expect_status_updated' => true,
				'expect_staff_notice'   => false,
			),
			array(
				'test_condition_name'   => '担当スタッフ未割当・pending のまま変更なしで再送 => ガードは発火せず保存・通知入口が1回呼ばれる（境界値・レビュー対応）',
				'previous_status'       => 'pending',
				'new_status'            => 'pending',
				'is_update'             => true,
				'assign_staff'          => false,
				'expected_calls'        => 1,
				'expect_status_updated' => true,
				'expect_staff_notice'   => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$booking_id = (int) $this->factory()->post->create(
				array(
					'post_type'   => Booking_Post_Type::POST_TYPE,
					'post_status' => 'publish',
				)
			);
			update_post_meta( $booking_id, self::META_STATUS, $case['previous_status'] );
			if ( $case['assign_staff'] ) {
				$staff_id = $this->create_staff( 'Staff ' . $case['test_condition_name'] );
				update_post_meta( $booking_id, self::META_RESOURCE_ID, $staff_id );
			}

			$_POST = array(
				'_vkbm_booking_quick_nonce' => wp_create_nonce( 'vkbm_booking_quick_edit' ),
				'vkbm_booking'              => array(
					'status' => $case['new_status'],
				),
			);

			$recorder = new Booking_Notification_Service_Call_Recorder();
			$admin    = new Booking_Admin( $recorder );

			$post = get_post( $booking_id );
			$this->assertInstanceOf( WP_Post::class, $post, $case['test_condition_name'] );
			$admin->save_quick_edit( $booking_id, $post, $case['is_update'] );

			$this->assertSame(
				$case['expected_calls'],
				$recorder->status_transition_calls,
				$case['test_condition_name']
			);

			$expected_status = $case['expect_status_updated'] ? $case['new_status'] : $case['previous_status'];
			$this->assertSame(
				$expected_status,
				get_post_meta( $booking_id, self::META_STATUS, true ),
				$case['test_condition_name'] . '（ステータスの保存有無が期待どおりであること）'
			);

			if ( $case['expected_calls'] > 0 ) {
				$last = end( $recorder->status_transition_args );
				$this->assertSame( $case['previous_status'], $last['old_status'], $case['test_condition_name'] . '（変更前ステータスを保存前に取得できている）' );
				$this->assertSame( $case['new_status'], $last['new_status'], $case['test_condition_name'] );
			}

			// 担当スタッフ必須の管理通知（transient）が期待どおり登録されているかを確認する。
			$notice_key = self::NOTICE_TRANSIENT_PREFIX . get_current_user_id() . '_' . $booking_id;
			$notice     = get_transient( $notice_key );
			if ( $case['expect_staff_notice'] ) {
				$this->assertIsArray( $notice, $case['test_condition_name'] . '（通知ペイロードが配列であること）' );
				$this->assertArrayHasKey( 'notices', $notice, $case['test_condition_name'] . '（notices キーがあること）' );
				$this->assertNotEmpty( $notice['notices'], $case['test_condition_name'] . '（通知が1件以上あること）' );
			} else {
				$this->assertFalse( $notice, $case['test_condition_name'] . '（担当スタッフ必須の通知は登録されていないこと）' );
			}
		}
	}

	/**
	 * render_column()（vkbm_booking_status 列）のテスト（#477 司からの差し戻し対応）。
	 *
	 * クイック編集の保存は画面遷移せず display_rows() を返して閉じるだけのため、
	 * 既存の admin_notices 通知の仕組み（push_admin_notice → redirect_post_location）は
	 * クイック編集の利用者に一度も表示されない。get_transient() で通知が積まれたことだけを
	 * 確認していた従来の test_save_quick_edit() は、この「実際に届くか」を保証できておらず、
	 * 安藤さん・植草さんが独立に指摘するまでテストが緑のまま欠落を通してしまった。
	 *
	 * 採用した方式は、担当スタッフ未割当なら booking-quick-edit.js が保存前（プルダウン選択の
	 * 時点）で枠を消費するステータスの選択肢自体を選べなくするというもの。この JS が読み取る
	 * data-resource-id（0 なら未割当）・data-edit-url（担当を設定する編集画面への導線）を
	 * render_column() が正しく出力しているかどうかが、利用者へ実際に届く経路にあたる。
	 */
	public function test_render_column(): void {
		$admin = new Booking_Admin();

		$test_cases = array(
			array(
				'test_condition_name' => '担当スタッフ割当あり・confirmed => data-resource-id はそのスタッフの投稿ID（正常系）',
				'status'              => 'confirmed',
				'assign_staff'        => true,
			),
			array(
				'test_condition_name' => '担当スタッフ未割当・confirmed => data-resource-id は 0（JS が未割当と判定できる値）（正常系）',
				'status'              => 'confirmed',
				'assign_staff'        => false,
			),
			array(
				// キャンセルは is_staff_check_target_status() の対象外（枠を消費しない）ため、
				// 担当スタッフ未割当でも JS 側で選択肢を無効化する対象にならない。それでも
				// data-resource-id 自体は現在の割当状況どおり 0 のまま出力されることを確認する
				// （このステータスでは JS が無効化に使わないだけで、値の出力ロジックはステータスに
				// 依存しないことの境界値）。
				'test_condition_name' => '担当スタッフ未割当・cancelled => 枠を消費しないステータスでも data-resource-id は割当状況どおり 0（境界値）',
				'status'              => 'cancelled',
				'assign_staff'        => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$booking_id = (int) $this->factory()->post->create(
				array(
					'post_type'   => Booking_Post_Type::POST_TYPE,
					'post_status' => 'publish',
				)
			);
			update_post_meta( $booking_id, self::META_STATUS, $case['status'] );

			$expected_resource_id = 0;
			if ( $case['assign_staff'] ) {
				$expected_resource_id = $this->create_staff( 'Staff ' . $case['test_condition_name'] );
				update_post_meta( $booking_id, self::META_RESOURCE_ID, $expected_resource_id );
			}

			ob_start();
			$admin->render_column( 'vkbm_booking_status', $booking_id );
			$output = (string) ob_get_clean();

			$this->assertStringContainsString(
				sprintf( 'data-status="%s"', $case['status'] ),
				$output,
				$case['test_condition_name'] . '（ステータス値が出力されること）'
			);
			$this->assertStringContainsString(
				sprintf( 'data-resource-id="%d"', $expected_resource_id ),
				$output,
				$case['test_condition_name'] . '（担当スタッフの割当状況を JS が読み取れる値で出力されること）'
			);

			// data-edit-url 属性値を取り出し、対象の投稿を編集する URL であることを確認する
			// （文字列全体に投稿IDが含まれるかだけを見ると、担当スタッフの投稿IDと混同しうるため、
			// 属性値を切り出してから中身を検証する）。
			$matched = (bool) preg_match( '/data-edit-url="([^"]*)"/', $output, $matches );
			$this->assertTrue( $matched, $case['test_condition_name'] . '（data-edit-url 属性が出力されること）' );
			$edit_url = html_entity_decode( $matches[1] ?? '', ENT_QUOTES );
			$this->assertStringContainsString( 'action=edit', $edit_url, $case['test_condition_name'] . '（編集画面へのURLであること）' );
			$this->assertStringContainsString( 'post=' . $booking_id, $edit_url, $case['test_condition_name'] . '（対象の予約投稿を指していること）' );
		}
	}

	/**
	 * 検証用の担当スタッフ（リソース）投稿を作成する。
	 *
	 * @param string $name スタッフ名。
	 * @return int スタッフ投稿ID。
	 */
	private function create_staff( string $name ): int {
		return (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $name,
			)
		);
	}

	/**
	 * 予約管理権限を持つ管理者ユーザーを現在のユーザーとして設定する。
	 */
	private function set_current_user_with_caps(): void {
		$user_id = $this->factory()->user->create(
			array(
				'role' => 'administrator',
			)
		);
		wp_set_current_user( $user_id );

		$user = get_user_by( 'id', $user_id );
		$user->add_cap( Capabilities::MANAGE_RESERVATIONS );
	}
}

/**
 * #477 のテスト用: 実際のメール送信は行わず、handle_status_transition() の
 * 呼び出し回数と引数だけを記録するテストダブル。
 *
 * dispatch_notification() の内部で使われる設定・テンプレート・メール送信の
 * 実処理まで検証すると通知メール自体の詳細仕様（内容・タイミング条件）に
 * 依存してしまうため、ここでは「入口が何回・どの引数で呼ばれたか」だけを見る。
 */
class Booking_Notification_Service_Call_Recorder extends Booking_Notification_Service {
	/**
	 * handle_status_transition() が呼ばれた回数。
	 *
	 * @var int
	 */
	public $status_transition_calls = 0;

	/**
	 * handle_status_transition() の呼び出し引数の履歴。
	 *
	 * @var array<int, array{booking_id: int, old_status: string, new_status: string}>
	 */
	public $status_transition_args = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( new Settings_Repository() );
	}

	/**
	 * {@inheritDoc}
	 */
	public function handle_status_transition( int $booking_id, string $old_status, string $new_status ): void {
		++$this->status_transition_calls;
		$this->status_transition_args[] = array(
			'booking_id' => $booking_id,
			'old_status' => $old_status,
			'new_status' => $new_status,
		);
	}
}
