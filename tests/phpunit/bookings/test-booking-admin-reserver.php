<?php
/**
 * 予約編集画面の「予約者」欄（検索 Ajax・保存時の検証・初期表示）のテスト（#711）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use VKBookingManager\Bookings\Booking_Admin;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\PostTypes\Booking_Post_Type;
use WP_UnitTestCase;
use WPDieException;

/**
 * Booking_Admin の予約者まわりを検証する。
 *
 * @group bookings
 */
class Booking_Admin_Reserver_Test extends WP_UnitTestCase {

	/**
	 * テスト後に $_POST / $_REQUEST を空に戻す。
	 */
	protected function tearDown(): void {
		$_POST    = array();
		$_REQUEST = array();
		parent::tearDown();
	}

	/**
	 * 現在のユーザーを、指定の権限を持つ管理者系ユーザーに切り替える。
	 *
	 * @param string $role ロール。
	 * @return int ユーザー ID。
	 */
	private function switch_to_role( string $role ): int {
		$user_id = $this->factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * ajax_search_reservers() を呼び、JSON レスポンスを取得する。
	 *
	 * @param array<string, string> $post POST 値（nonce は呼び出し側で渡す）。
	 * @return array<string, mixed> デコード済みの JSON レスポンス。
	 */
	private function call_search( array $post ): array {
		$_POST = $post;

		// wp_send_json() の die を捕捉可能な例外へ置き換える。
		$die_handler = static function () {
			return static function ( $message ) {
				throw new WPDieException( is_scalar( $message ) ? (string) $message : '0' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- テスト用の終了ハンドラ。
			};
		};
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $die_handler );

		$level = ob_get_level();
		ob_start();
		try {
			( new Booking_Admin() )->ajax_search_reservers();
		} catch ( WPDieException $e ) {
			// wp_send_json() の終了を想定どおり握りつぶす。
			unset( $e );
		} finally {
			while ( ob_get_level() > $level + 1 ) {
				ob_end_clean();
			}
			$body = (string) ob_get_clean();
			remove_filter( 'wp_doing_ajax', '__return_true' );
			remove_filter( 'wp_die_ajax_handler', $die_handler );
		}

		$decoded = json_decode( $body, true );
		$this->assertIsArray( $decoded );

		return $decoded;
	}

	/**
	 * 検索 Ajax は、権限（list_users）と nonce が揃ったときだけ結果を返す。
	 */
	public function test_ajax_search_reservers(): void {
		$target_id = $this->factory()->user->create(
			array(
				'user_login' => 'rsajax_target',
				'user_email' => 'rsajax@example.com',
				'role'       => 'subscriber',
			)
		);

		$admin_id = $this->switch_to_role( 'administrator' );
		$nonce    = wp_create_nonce( 'vkbm_search_reservers' );

		$test_cases = array(
			array(
				'test_condition_name' => '権限あり・nonce 正常 => 検索結果を返す',
				'user_role'           => 'administrator',
				'nonce'               => $nonce,
				'expected'            => array(
					'success' => true,
					'ids'     => array( $target_id ),
				),
			),
			array(
				'test_condition_name' => '権限あり・nonce 不正 => 拒否',
				'user_role'           => 'administrator',
				'nonce'               => 'invalid',
				'expected'            => array(
					'success' => false,
					'ids'     => array(),
				),
			),
			array(
				'test_condition_name' => '権限あり・nonce なし => 拒否',
				'user_role'           => 'administrator',
				'nonce'               => '',
				'expected'            => array(
					'success' => false,
					'ids'     => array(),
				),
			),
			array(
				'test_condition_name' => '権限なし（購読者）・nonce 正常 => 拒否',
				'user_role'           => 'subscriber',
				'nonce'               => $nonce,
				'expected'            => array(
					'success' => false,
					'ids'     => array(),
				),
			),
			array(
				'test_condition_name' => '権限なし（予約管理権限だけを持つ購読者）・nonce 正常 => 拒否',
				'user_role'           => 'subscriber_with_manage_reservations',
				'nonce'               => $nonce,
				'expected'            => array(
					'success' => false,
					'ids'     => array(),
				),
			),
		);

		foreach ( $test_cases as $case ) {
			if ( 'administrator' === $case['user_role'] ) {
				wp_set_current_user( $admin_id );
			} else {
				$user_id = $this->switch_to_role( 'subscriber' );
				if ( 'subscriber_with_manage_reservations' === $case['user_role'] ) {
					get_user_by( 'id', $user_id )->add_cap( Capabilities::MANAGE_RESERVATIONS );
				}
			}

			$response = $this->call_search(
				array(
					'nonce'   => $case['nonce'],
					'keyword' => 'rsajax_target',
				)
			);

			$ids = array();
			if ( ! empty( $response['success'] ) ) {
				foreach ( $response['data']['users'] as $row ) {
					$ids[] = $row['id'];
				}
			}

			$this->assertSame(
				$case['expected'],
				array(
					'success' => (bool) $response['success'],
					'ids'     => $ids,
				),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * 検索 Ajax が返す項目は ID・ラベル・メールアドレスに絞られ、続きの有無を持つ。
	 */
	public function test_ajax_search_reservers_response_shape(): void {
		$this->factory()->user->create(
			array(
				'user_login' => 'rsshape_user',
				'user_email' => 'rsshape@example.com',
				'role'       => 'subscriber',
			)
		);
		$this->switch_to_role( 'administrator' );

		$response = $this->call_search(
			array(
				'nonce'   => wp_create_nonce( 'vkbm_search_reservers' ),
				'keyword' => 'rsshape_user',
			)
		);

		$this->assertTrue( $response['success'] );
		$this->assertSame( array( 'users', 'has_more' ), array_keys( $response['data'] ) );
		$this->assertSame( array( 'id', 'label', 'email' ), array_keys( $response['data']['users'][0] ) );
	}

	/**
	 * 管理画面の編集画面から予約者を送って保存する。
	 *
	 * @param int    $booking_id 予約の投稿 ID。
	 * @param int    $author_id  送信する予約者 ID。
	 * @param string $role       保存するユーザーのロール（いずれも予約管理権限を持つ）。
	 */
	private function save_with_author( int $booking_id, int $author_id, string $role = 'administrator' ): void {
		$user_id = $this->factory()->user->create( array( 'role' => $role ) );
		get_user_by( 'id', $user_id )->add_cap( Capabilities::MANAGE_RESERVATIONS );
		wp_set_current_user( $user_id );

		$_POST = array(
			'_vkbm_booking_meta_nonce' => wp_create_nonce( 'vkbm_booking_meta' ),
			'vkbm_booking'             => array(
				'date'       => '2026-10-10',
				'start_time' => '15:00',
				'end_time'   => '16:00',
				// キャンセルは担当スタッフの割り当て・競合判定の対象外なので、予約者の更新だけを検証できる。
				'status'     => 'cancelled',
				'author_id'  => $author_id,
			),
		);
		( new Booking_Admin() )->save_post( $booking_id, get_post( $booking_id ) );
		$_POST = array();
	}

	/**
	 * 保存時、実在するユーザー ID だけを予約者として受け付ける。
	 */
	public function test_save_post_author_validation(): void {
		$original_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		$other_id    = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		$deleted_id  = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_delete_user( $deleted_id );

		$test_cases = array(
			array(
				'test_condition_name' => '実在する別のユーザーを送る => 予約者が変わる',
				'sent_author_id'      => $other_id,
				'role'                => 'administrator',
				'expected'            => $other_id,
			),
			array(
				'test_condition_name' => 'list_users の無いユーザー（予約管理権限のみ）が実在するユーザーを送る => 変わらない',
				'sent_author_id'      => $other_id,
				'role'                => 'subscriber',
				'expected'            => $original_id,
			),
			array(
				'test_condition_name' => '現在の予約者と同じ ID を送る => 変わらない',
				'sent_author_id'      => $original_id,
				'role'                => 'administrator',
				'expected'            => $original_id,
			),
			array(
				'test_condition_name' => '投稿者 0・印 guest の予約に会員を送る => 会員に付け替わる（Booking_Admin の許可の包み込みが無いと 0 のまま）',
				'sent_author_id'      => $other_id,
				'role'                => 'administrator',
				'guest'               => true,
				'expected'            => $other_id,
			),
			array(
				'test_condition_name' => '存在しない ID を送る => 変わらない',
				'sent_author_id'      => 9999999,
				'role'                => 'administrator',
				'expected'            => $original_id,
			),
			array(
				'test_condition_name' => '削除済みユーザーの ID を送る => 変わらない',
				'sent_author_id'      => $deleted_id,
				'role'                => 'administrator',
				'expected'            => $original_id,
			),
			array(
				'test_condition_name' => '0（未設定）を送る => 変わらない',
				'sent_author_id'      => 0,
				'role'                => 'administrator',
				'expected'            => $original_id,
			),
		);

		foreach ( $test_cases as $case ) {
			$booking_id = $this->factory()->post->create(
				array(
					'post_type'   => Booking_Post_Type::POST_TYPE,
					'post_status' => 'publish',
					'post_author' => ! empty( $case['guest'] ) ? 0 : $original_id,
				)
			);
			if ( ! empty( $case['guest'] ) ) {
				update_post_meta( $booking_id, \VKBookingManager\Bookings\Booking_Source::META_KEY, \VKBookingManager\Bookings\Booking_Source::VALUE_GUEST );
			}

			$this->save_with_author( $booking_id, $case['sent_author_id'], $case['role'] );

			$this->assertSame(
				$case['expected'],
				(int) get_post( $booking_id )->post_author,
				$case['test_condition_name']
			);
		}
	}

	/**
	 * 予約者を付け替えたときだけ、ご利用になる方の ID を manual へ置き換え、名前・番号の写しは残す。
	 */
	public function test_save_post_attendee_id_on_author_change(): void {
		$original_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		$other_id    = $this->factory()->user->create( array( 'role' => 'subscriber' ) );

		$test_cases = array(
			array(
				'test_condition_name' => '名簿の人の予約を別の会員へ付け替える => manual に置き換わり、写しは残る',
				'attendee_id'         => '11111111-1111-4111-8111-111111111111',
				'sent_author_id'      => $other_id,
				'expected_id'         => 'manual',
			),
			array(
				'test_condition_name' => '本人の予約を別の会員へ付け替える => manual に置き換わり、写しは残る',
				'attendee_id'         => 'self',
				'sent_author_id'      => $other_id,
				'expected_id'         => 'manual',
			),
			array(
				'test_condition_name' => 'ID の値が無い過去予約を別の会員へ付け替える => manual になる',
				'attendee_id'         => null,
				'sent_author_id'      => $other_id,
				'expected_id'         => 'manual',
			),
			array(
				'test_condition_name' => '予約者を変えずに保存する => ID はそのまま',
				'attendee_id'         => '11111111-1111-4111-8111-111111111111',
				'sent_author_id'      => $original_id,
				'expected_id'         => '11111111-1111-4111-8111-111111111111',
			),
		);

		foreach ( $test_cases as $case ) {
			$booking_id = $this->factory()->post->create(
				array(
					'post_type'   => Booking_Post_Type::POST_TYPE,
					'post_status' => 'publish',
					'post_author' => $original_id,
				)
			);
			update_post_meta( $booking_id, '_vkbm_booking_attendee_name', 'ポチ' );
			update_post_meta( $booking_id, '_vkbm_booking_attendee_number', 'A-100' );
			if ( null !== $case['attendee_id'] ) {
				update_post_meta( $booking_id, '_vkbm_booking_attendee_id', $case['attendee_id'] );
			}

			$this->save_with_author( $booking_id, $case['sent_author_id'] );

			$this->assertSame( $case['expected_id'], (string) get_post_meta( $booking_id, '_vkbm_booking_attendee_id', true ), $case['test_condition_name'] . '（ID）' );
			$this->assertSame( 'A-100', (string) get_post_meta( $booking_id, '_vkbm_booking_attendee_number', true ), $case['test_condition_name'] . '（番号の写し）' );
		}
	}

	/**
	 * 初期表示は現在の予約者だけを出し、全ユーザーを並べるプルダウンを出さない。
	 */
	public function test_render_meta_box_reserver_field(): void {
		$reserver_id = $this->factory()->user->create(
			array(
				'user_login' => 'rsrender_user',
				'user_email' => 'rsrender@example.com',
				'role'       => 'subscriber',
			)
		);
		update_user_meta( $reserver_id, 'last_name', '高橋' );
		update_user_meta( $reserver_id, 'first_name', '三郎' );
		$other_id = $this->factory()->user->create(
			array(
				'user_login' => 'rsrender_other',
				'user_email' => 'rsrender-other@example.com',
				'role'       => 'subscriber',
			)
		);
		update_user_meta( $other_id, 'last_name', '渡辺' );

		$deleted_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_delete_user( $deleted_id );

		$this->switch_to_role( 'administrator' );

		$test_cases = array(
			array(
				'test_condition_name' => '予約者あり => 氏名・メール・変更ボタンを出し、ほかのユーザーは出さない',
				'author_id'           => $reserver_id,
				'contains'            => array(
					'高橋 三郎',
					'rsrender@example.com',
					__( 'Change reserver', 'vk-booking-manager' ),
					'name="vkbm_booking[author_id]" value="' . $reserver_id . '"',
				),
				'not_contains'        => array( '渡辺', 'rsrender-other@example.com', '<select id="vkbm-booking-author"', '>' . __( 'Select reserver', 'vk-booking-manager' ) . '</button>' ),
			),
			array(
				'test_condition_name' => '予約者未設定 => 「Not set」と選択ボタンを出し、一覧・情報ボタンは出さない',
				'author_id'           => 0,
				'contains'            => array( __( 'Not set', 'vk-booking-manager' ), '>' . __( 'Select reserver', 'vk-booking-manager' ) . '</button>', 'name="vkbm_booking[author_id]" value="0"' ),
				'not_contains'        => array( 'data-vkbm-reserver-saved-action' ),
			),
			array(
				'test_condition_name' => '削除済みユーザー => 「不明なユーザー」と ID を出し、hidden は据え置く',
				'author_id'           => $deleted_id,
				'contains'            => array( sprintf( /* translators: %d: user ID */ __( 'Unknown user (ID: %d)', 'vk-booking-manager' ), $deleted_id ), 'name="vkbm_booking[author_id]" value="' . $deleted_id . '"' ),
				'not_contains'        => array( 'user-edit.php' ),
			),
		);

		foreach ( $test_cases as $case ) {
			$booking_id = $this->factory()->post->create(
				array(
					'post_type'   => Booking_Post_Type::POST_TYPE,
					'post_status' => 'publish',
					'post_author' => $case['author_id'],
				)
			);

			ob_start();
			( new Booking_Admin() )->render_meta_box( get_post( $booking_id ) );
			$html = (string) ob_get_clean();

			foreach ( $case['contains'] as $needle ) {
				$this->assertStringContainsString( $needle, $html, $case['test_condition_name'] . ' / 含む: ' . $needle );
			}
			foreach ( $case['not_contains'] as $needle ) {
				$this->assertStringNotContainsString( $needle, $html, $case['test_condition_name'] . ' / 含まない: ' . $needle );
			}
		}
	}
}
