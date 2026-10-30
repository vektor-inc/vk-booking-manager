<?php
/**
 * ゲスト予約の編集画面の顧客フラグ欄（メタボックス）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Customers;

use VKBookingManager\Bookings\Booking_Anonymizer;
use VKBookingManager\Bookings\Booking_Source;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Customers\Customer_Flag_Meta_Box;
use VKBookingManager\Customers\Customer_Flag_Service;
use VKBookingManager\PostTypes\Booking_Post_Type;
use WP_UnitTestCase;

/**
 * ゲスト予約の編集画面で、予約の連絡先にフラグを付ける欄の表示・保存を検証する。
 *
 * @group customer-flags
 */
class Customer_Flag_Meta_Box_Guest_Test extends WP_UnitTestCase {
	use Customer_Flag_Options_Cleanup_Trait;

	/**
	 * フラグサービス。
	 *
	 * @var Customer_Flag_Service
	 */
	private $service;

	/**
	 * テスト対象のメタボックス。
	 *
	 * @var Customer_Flag_Meta_Box
	 */
	private $meta_box;

	/**
	 * テスト前の $_POST（復元用）。
	 *
	 * @var array<string, mixed>
	 */
	private $post_backup = array();

	/**
	 * 各テストの前に、種別を登録し $_POST を退避する。
	 */
	public function set_up(): void {
		parent::set_up();

		$this->delete_all_flag_options();
		$this->post_backup = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- テスト後に復元するための退避。

		$this->service  = new Customer_Flag_Service();
		$this->meta_box = new Customer_Flag_Meta_Box( $this->service );
		$this->service->save_types(
			array(
				array(
					'id'             => 'caution',
					'label'          => '要注意',
					'blocks_booking' => false,
					'block_message'  => '',
				),
				array(
					'id'             => 'blacklist',
					'label'          => 'ブラックリスト',
					'blocks_booking' => true,
					'block_message'  => '',
				),
			)
		);
	}

	/**
	 * 各テストの後に、$_POST・ログインユーザー・データを元に戻す。
	 */
	public function tear_down(): void {
		$_POST = $this->post_backup;
		wp_set_current_user( 0 );
		$this->delete_all_flag_options();

		parent::tear_down();
	}

	/**
	 * ゲスト予約の欄が、状態に応じて出し分けられることを検証する。
	 */
	public function test_render(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'ゲスト予約（連絡先あり）はフラグの選択欄と連絡先を表示する（正常系）',
				'conditions'          => array(
					'guest'      => true,
					'email'      => 'Guest@Example.com',
					'phone'      => '090-1234-5678',
					'anonymized' => false,
				),
				'expected'            => array(
					'has_select'   => true,
					'has_contact'  => true,
					'has_message'  => '',
					'hidden_email' => 'guest@example.com',
					'hidden_phone' => '09012345678',
				),
			),
			array(
				'test_condition_name' => 'メールだけのゲスト予約でも選択欄を表示する（正常系）',
				'conditions'          => array(
					'guest'      => true,
					'email'      => 'only@example.com',
					'phone'      => '',
					'anonymized' => false,
				),
				'expected'            => array(
					'has_select'   => true,
					'has_contact'  => true,
					'has_message'  => '',
					'hidden_email' => 'only@example.com',
					'hidden_phone' => '',
				),
			),
			array(
				'test_condition_name' => '連絡先が両方空なら欄を出さず説明だけ出す（異常系）',
				'conditions'          => array(
					'guest'      => true,
					'email'      => '',
					'phone'      => '',
					'anonymized' => false,
				),
				'expected'            => array(
					'has_select'  => false,
					'has_contact' => false,
					'has_message' => 'no_contact',
				),
			),
			array(
				'test_condition_name' => '匿名化済みの予約は欄を出さず案内する（異常系）',
				'conditions'          => array(
					'guest'      => true,
					'email'      => '',
					'phone'      => '',
					'anonymized' => true,
				),
				'expected'            => array(
					'has_select'  => false,
					'has_contact' => false,
					'has_message' => 'anonymized',
				),
			),
			array(
				'test_condition_name' => 'ゲスト予約でなく会員とも紐付かない予約は従来の案内を出す（境界値）',
				'conditions'          => array(
					'guest'      => false,
					'email'      => 'x@example.com',
					'phone'      => '',
					'anonymized' => false,
				),
				'expected'            => array(
					'has_select'  => false,
					'has_contact' => false,
					'has_message' => 'not_linked',
				),
			),
		);

		$messages = array(
			'no_contact' => __( 'Customer flags cannot be set because this reservation has no email address or telephone number.', 'vk-booking-manager' ),
			'anonymized' => __( 'This reservation has been anonymized, so its flag cannot be changed here.', 'vk-booking-manager' ),
			'not_linked' => __( 'Customer flags cannot be set because this reservation is not linked to a member.', 'vk-booking-manager' ),
		);

		wp_set_current_user( $this->create_admin() );

		foreach ( $test_cases as $case ) {
			$c          = $case['conditions'];
			$booking_id = $this->create_guest_booking( $c['email'], $c['phone'], $c['guest'], $c['anonymized'] );

			ob_start();
			$this->meta_box->render( get_post( $booking_id ) );
			$output = (string) ob_get_clean();

			$e = $case['expected'];
			$this->assertSame( $e['has_select'], str_contains( $output, 'name="vkbm_customer_flag_type"' ), $case['test_condition_name'] . ' / 選択欄' );
			$this->assertSame( $e['has_contact'], str_contains( $output, 'name="vkbm_customer_flag_guest"' ), $case['test_condition_name'] . ' / ゲスト欄' );
			if ( '' !== $e['has_message'] ) {
				$this->assertStringContainsString( esc_html( $messages[ $e['has_message'] ] ), $output, $case['test_condition_name'] . ' / 説明' );
			}
			if ( isset( $e['hidden_email'] ) ) {
				$this->assertStringContainsString( 'name="vkbm_customer_flag_guest_email" value="' . $e['hidden_email'] . '"', $output, $case['test_condition_name'] . ' / 保持するメール' );
				$this->assertStringContainsString( 'name="vkbm_customer_flag_guest_phone" value="' . $e['hidden_phone'] . '"', $output, $case['test_condition_name'] . ' / 保持する電話' );
			}
		}
	}

	/**
	 * 付与済みのゲストのフラグが、止める種類は赤・それ以外は青の通知で、一致した連絡先つきで表示されることを検証する。
	 */
	public function test_render_shows_existing_guest_flags(): void {
		$other_booking_id = $this->create_guest_booking( 'old@example.com', '09000001111', true, false );
		$this->service->set_guest_flag( 'both@example.com', '', 'blacklist', '', 1, $other_booking_id );
		$this->service->set_guest_flag( '', '09099998888', 'caution' );
		$booking_id = $this->create_guest_booking( 'both@example.com', '09099998888', true, false );

		wp_set_current_user( $this->create_admin() );
		ob_start();
		$this->meta_box->render( get_post( $booking_id ) );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $output, '止める種類は赤の通知' );
		$this->assertStringContainsString( 'notice-info', $output, '止めない種類は青の通知' );
		$this->assertStringContainsString( 'ブラックリスト', $output, '止める種別名を表示する' );
		$this->assertStringContainsString( '要注意', $output, '止めない種別名を表示する' );
		$this->assertStringContainsString( 'post=' . $other_booking_id, $output, '別の予約で付けたときは元の予約へのリンクを出す' );
		$this->assertStringContainsString( 'name="vkbm_customer_flag_guest_initial_type" value="blacklist"', $output, '別々に一致したら、予約をブロックする種別を初期選択にする' );
		$this->assertMatchesRegularExpression( '/<option value="blacklist"\s+selected=\'selected\'/', $output, '選択欄でもブロックする種別が選ばれている' );
	}

	/**
	 * 保存処理が、条件を満たすときだけ予約の連絡先へフラグを書き込むことを検証する。
	 */
	public function test_save(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '正しい nonce・権限・描画時と同じ連絡先ならフラグが保存される（正常系）',
				'conditions'          => array(
					'valid_nonce'  => true,
					'role'         => 'admin',
					'rendered'     => array( 'guest@example.com', '09012345678' ),
					'type'         => 'blacklist',
					'change_email' => false,
				),
				'expected'            => 'blacklist',
			),
			array(
				'test_condition_name' => '「なし」で保存すると、既に付いたフラグが外れる（正常系）',
				'conditions'          => array(
					'valid_nonce'  => true,
					'role'         => 'admin',
					'rendered'     => array( 'guest@example.com', '09012345678' ),
					'type'         => '',
					'preflag'      => 'blacklist',
					'change_email' => false,
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => 'nonce が不正なら保存しない（異常系）',
				'conditions'          => array(
					'valid_nonce'  => false,
					'role'         => 'admin',
					'rendered'     => array( 'guest@example.com', '09012345678' ),
					'type'         => 'blacklist',
					'change_email' => false,
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '予約管理の権限が無いユーザーでは保存しない（異常系）',
				'conditions'          => array(
					'valid_nonce'  => true,
					'role'         => 'subscriber',
					'rendered'     => array( 'guest@example.com', '09012345678' ),
					'type'         => 'blacklist',
					'change_email' => false,
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '同じ保存で連絡先が書き換わっていたら保存しない（異常系）',
				'conditions'          => array(
					'valid_nonce'  => true,
					'role'         => 'admin',
					'rendered'     => array( 'guest@example.com', '09012345678' ),
					'type'         => 'blacklist',
					'change_email' => true,
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '選択欄が送信されない保存では何もしない（既存のフラグを消さない。境界値）',
				'conditions'          => array(
					'valid_nonce'  => true,
					'role'         => 'admin',
					'rendered'     => array( 'guest@example.com', '09012345678' ),
					'type'         => null,
					'preflag'      => 'caution',
					'change_email' => false,
				),
				'expected'            => 'caution',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->delete_all_flag_options();
			$this->service->save_types(
				array(
					array(
						'id'             => 'caution',
						'label'          => '要注意',
						'blocks_booking' => false,
					),
					array(
						'id'             => 'blacklist',
						'label'          => 'ブラックリスト',
						'blocks_booking' => true,
					),
				)
			);

			$c          = $case['conditions'];
			$booking_id = $this->create_guest_booking( 'Guest@Example.com', '090-1234-5678', true, false );
			if ( ! empty( $c['preflag'] ) ) {
				$this->service->set_guest_flag( 'guest@example.com', '09012345678', $c['preflag'] );
			}
			if ( $c['change_email'] ) {
				update_post_meta( $booking_id, '_vkbm_booking_customer_email', 'changed@example.com' );
			}

			wp_set_current_user( 'admin' === $c['role'] ? $this->create_admin() : self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
			$_POST = array(
				'_vkbm_customer_flag_nonce'      => $c['valid_nonce'] ? wp_create_nonce( 'vkbm_customer_flag_meta' ) : 'invalid',
				'vkbm_customer_flag_guest'       => '1',
				'vkbm_customer_flag_guest_email' => $c['rendered'][0],
				'vkbm_customer_flag_guest_phone' => $c['rendered'][1],
				'vkbm_customer_flag_memo'        => '無断キャンセル',
			);
			if ( null !== $c['type'] ) {
				$_POST['vkbm_customer_flag_type'] = $c['type'];
			}

			$this->meta_box->save( $booking_id );

			$flags = $this->service->find_guest_flags( 'guest@example.com', '09012345678' );
			$this->assertSame( $case['expected'], array() !== $flags ? $flags[0]['type_id'] : '', $case['test_condition_name'] );
			if ( 'blacklist' === $case['expected'] && empty( $c['preflag'] ) ) {
				$this->assertSame( $booking_id, $flags[0]['source_booking_id'], $case['test_condition_name'] . ' / 元の予約' );
			}
		}
	}

	/**
	 * 別々のフラグが付いた連絡先で、種別もメモも変えない保存では 1 件にまとめず、変えた保存ではまとめることを検証する。
	 */
	public function test_save_merges_only_when_changed(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '種別もメモも描画時のままの保存 => 2 件のまま（ブロックが外れない。正常系）',
				'conditions'          => array(
					'type' => 'blacklist',
					'memo' => '',
				),
				'expected'            => 2,
			),
			array(
				'test_condition_name' => 'メモを変えた保存 => 1 件にまとまる（正常系）',
				'conditions'          => array(
					'type' => 'blacklist',
					'memo' => '再発',
				),
				'expected'            => 1,
			),
			array(
				'test_condition_name' => '種別を変えた保存 => 1 件にまとまる（正常系）',
				'conditions'          => array(
					'type' => 'caution',
					'memo' => '',
				),
				'expected'            => 1,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->service->clear_guest_flag( 'both@example.com', '09099998888' );
			$this->service->set_guest_flag( 'both@example.com', '', 'blacklist' );
			$this->service->set_guest_flag( '', '09099998888', 'caution' );
			$booking_id = $this->create_guest_booking( 'both@example.com', '09099998888', true, false );

			wp_set_current_user( $this->create_admin() );
			$_POST = array(
				'_vkbm_customer_flag_nonce'             => wp_create_nonce( 'vkbm_customer_flag_meta' ),
				'vkbm_customer_flag_guest'              => '1',
				'vkbm_customer_flag_guest_email'        => 'both@example.com',
				'vkbm_customer_flag_guest_phone'        => '09099998888',
				'vkbm_customer_flag_guest_initial_type' => 'blacklist',
				'vkbm_customer_flag_guest_initial_memo' => '',
				'vkbm_customer_flag_type'               => $case['conditions']['type'],
				'vkbm_customer_flag_memo'               => $case['conditions']['memo'],
			);
			$this->meta_box->save( $booking_id );

			$this->assertCount( $case['expected'], $this->service->find_guest_flags( 'both@example.com', '09099998888' ), $case['test_condition_name'] );
		}
	}

	/**
	 * 複数行のメモを変えずに保存したとき、改行コードの差（CRLF / LF）で付与日時が書き換わらないことを検証する。
	 */
	public function test_save_multiline_memo_unchanged_keeps_assigned_at(): void {
		$memo = "1行目\n2行目";
		$this->service->set_guest_flag( 'multi@example.com', '09011112222', 'blacklist', $memo, 1 );
		$records                   = $this->service->get_guest_records();
		$records[0]['assigned_at'] = '2026-01-01 00:00:00';
		( new \VKBookingManager\Customers\Customer_Flag_Repository() )->save_guest_records( $records );
		$booking_id = $this->create_guest_booking( 'multi@example.com', '09011112222', true, false );

		wp_set_current_user( $this->create_admin() );
		$_POST = array(
			'_vkbm_customer_flag_nonce'             => wp_create_nonce( 'vkbm_customer_flag_meta' ),
			'vkbm_customer_flag_guest'              => '1',
			'vkbm_customer_flag_guest_email'        => 'multi@example.com',
			'vkbm_customer_flag_guest_phone'        => '09011112222',
			'vkbm_customer_flag_guest_initial_type' => 'blacklist',
			// 描画時の値は LF のまま、textarea は送信時に CRLF になる。
			'vkbm_customer_flag_guest_initial_memo' => $memo,
			'vkbm_customer_flag_type'               => 'blacklist',
			'vkbm_customer_flag_memo'               => "1行目\r\n2行目",
		);
		$this->meta_box->save( $booking_id );

		$flags = $this->service->get_guest_records();
		$this->assertCount( 1, $flags );
		$this->assertSame( '2026-01-01 00:00:00', $flags[0]['assigned_at'], '変えていない保存では付与日時が変わらない' );
	}

	/**
	 * ゲストのフラグを保存できなかったとき、保存後の画面で知らせる通知が積まれ、表示されることを検証する。
	 */
	public function test_save_failure_queues_and_renders_notice(): void {
		$booking_id = $this->create_guest_booking( 'fail@example.com', '09011112222', true, false );
		$admin_id   = $this->create_admin();
		wp_set_current_user( $admin_id );
		$_POST = array(
			'_vkbm_customer_flag_nonce'             => wp_create_nonce( 'vkbm_customer_flag_meta' ),
			'vkbm_customer_flag_guest'              => '1',
			'vkbm_customer_flag_guest_email'        => 'fail@example.com',
			'vkbm_customer_flag_guest_phone'        => '09011112222',
			'vkbm_customer_flag_guest_initial_type' => '',
			'vkbm_customer_flag_guest_initial_memo' => '',
			'vkbm_customer_flag_type'               => 'blacklist',
			'vkbm_customer_flag_memo'               => '',
		);

		$this->hold_guest_lock_for_test();
		$this->meta_box->save( $booking_id );
		$this->release_guest_lock_for_test();

		$this->assertSame( array(), $this->service->get_guest_records(), '排他を取れず保存されていない' );
		$this->assertNotFalse( get_transient( 'vkbm_customer_flag_guest_notice_' . $admin_id . '_' . $booking_id ), '通知が積まれる（異常系）' );

		$_GET['vkbm_customer_flag_guest_notice'] = (string) $booking_id;
		ob_start();
		$this->meta_box->render_guest_save_failed_notice();
		$html = (string) ob_get_clean();
		unset( $_GET['vkbm_customer_flag_guest_notice'] );

		$this->assertStringContainsString( 'notice-error', $html, '保存後の画面にエラー通知が出る' );
		$this->assertFalse( get_transient( 'vkbm_customer_flag_guest_notice_' . $admin_id . '_' . $booking_id ), '表示したら通知は消える' );
	}

	/**
	 * 匿名化済み・会員に紐付け済み・ゲスト予約でない予約には、ゲスト用の保存をしないことを検証する。
	 */
	public function test_save_skips_unsupported_bookings(): void {
		wp_set_current_user( $this->create_admin() );
		$member_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$cases = array(
			'匿名化済み'          => $this->create_guest_booking( 'a@example.com', '09011110000', true, true ),
			'ゲスト予約でない'       => $this->create_guest_booking( 'b@example.com', '09022220000', false, false ),
			'会員に紐付け済みのゲスト予約' => $this->create_guest_booking( 'c@example.com', '09033330000', true, false, $member_id ),
		);

		foreach ( $cases as $name => $booking_id ) {
			$_POST = array(
				'_vkbm_customer_flag_nonce'      => wp_create_nonce( 'vkbm_customer_flag_meta' ),
				'vkbm_customer_flag_guest'       => '1',
				'vkbm_customer_flag_guest_email' => (string) get_post_meta( $booking_id, '_vkbm_booking_customer_email', true ),
				'vkbm_customer_flag_guest_phone' => (string) get_post_meta( $booking_id, '_vkbm_booking_customer_tel', true ),
				'vkbm_customer_flag_type'        => 'blacklist',
				'vkbm_customer_flag_memo'        => '',
			);
			$this->meta_box->save( $booking_id );
		}

		$this->assertSame( array(), $this->service->get_guest_records(), '対象外の予約からはゲストのフラグが付かない' );
	}

	/**
	 * 会員に紐付けたゲスト予約の欄に、連絡先にゲストのフラグが付いている旨が出ることを検証する。
	 */
	public function test_render_for_linked_guest_booking(): void {
		$member_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->service->set_guest_flag( 'linked@example.com', '', 'blacklist' );
		$linked_id = $this->create_guest_booking( 'linked@example.com', '', true, false, $member_id );
		$plain_id  = $this->create_guest_booking( 'plain@example.com', '', true, false, $member_id );

		wp_set_current_user( $this->create_admin() );
		$notice = esc_html( __( 'The contact details of this reservation also have a guest flag.', 'vk-booking-manager' ) );

		ob_start();
		$this->meta_box->render( get_post( $linked_id ) );
		$linked_output = (string) ob_get_clean();
		ob_start();
		$this->meta_box->render( get_post( $plain_id ) );
		$plain_output = (string) ob_get_clean();

		$this->assertStringContainsString( $notice, $linked_output, '連絡先にゲストのフラグが付いていれば知らせる' );
		$this->assertStringNotContainsString( $notice, $plain_output, '付いていなければ知らせない' );
		$this->assertStringContainsString( 'name="vkbm_customer_flag_user_id"', $linked_output, '会員用の欄のまま' );
	}

	/**
	 * ゲスト予約（または普通の予約）を作る。
	 *
	 * @param string $email      お客様のメールアドレス。
	 * @param string $phone      お客様の電話番号。
	 * @param bool   $is_guest   ゲスト予約の印を付けるか。
	 * @param bool   $anonymized 匿名化済みにするか。
	 * @param int    $author_id  予約者のユーザー ID。
	 * @return int 予約 ID。
	 */
	private function create_guest_booking( string $email, string $phone, bool $is_guest, bool $anonymized, int $author_id = 0 ): int {
		$booking_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_author' => $author_id,
			)
		);
		update_post_meta( $booking_id, '_vkbm_booking_customer_email', $email );
		update_post_meta( $booking_id, '_vkbm_booking_customer_tel', $phone );
		if ( $is_guest ) {
			update_post_meta( $booking_id, Booking_Source::META_KEY, Booking_Source::VALUE_GUEST );
		}
		if ( $anonymized ) {
			update_post_meta( $booking_id, Booking_Anonymizer::META_ANONYMIZED_AT, gmdate( 'Y-m-d H:i:s' ) );
		}

		return $booking_id;
	}

	/**
	 * 予約管理の権限を持つ管理者を作る。
	 *
	 * @return int ユーザー ID。
	 */
	private function create_admin(): int {
		$user_id = (int) $this->factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $user_id )->add_cap( Capabilities::MANAGE_RESERVATIONS );

		return $user_id;
	}
}
