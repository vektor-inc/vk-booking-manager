<?php
/**
 * 予約一覧の一括操作「個人情報を匿名化」（Booking_Anonymize_Bulk_Action）のテスト（issue #870）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use DateTimeImmutable;
use VKBookingManager\Bookings\Booking_Anonymize_Bulk_Action;
use VKBookingManager\Bookings\Booking_Anonymizer;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\PostTypes\Booking_Post_Type;
use WP_UnitTestCase;

/**
 * Booking_Anonymize_Bulk_Action を検証する。
 *
 * @group bookings
 */
class Booking_Anonymize_Bulk_Action_Test extends WP_UnitTestCase {

	/**
	 * テスト後に $_GET と画面の状態を戻す。
	 */
	protected function tearDown(): void {
		$_GET = array();
		unset( $GLOBALS['current_screen'] );
		parent::tearDown();
	}

	/**
	 * 予約を管理する権限を持つユーザーに切り替える。
	 *
	 * @return int ユーザーID。
	 */
	private function switch_to_manager(): int {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $user_id )->add_cap( Capabilities::MANAGE_RESERVATIONS );
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * 現在時刻からの相対指定を、保存形式の日時にする。
	 *
	 * @param string $modifier 相対指定。
	 * @return string
	 */
	private function relative_datetime( string $modifier ): string {
		return ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( $modifier )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * 予約を作る。
	 *
	 * @param string $end_modifier 終了日時の相対指定。空文字なら日時を設定しない。
	 * @param string $title        タイトル。
	 * @return int 予約の投稿ID。
	 */
	private function create_booking( string $end_modifier, string $title = '山田太郎 / 2020.1.1 10:00' ): int {
		$booking_id = $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
		update_post_meta( $booking_id, '_vkbm_booking_status', 'confirmed' );
		update_post_meta( $booking_id, '_vkbm_booking_customer_name', '山田太郎' );
		update_post_meta( $booking_id, '_vkbm_booking_customer_email', 'taro@example.com' );
		if ( '' !== $end_modifier ) {
			update_post_meta( $booking_id, '_vkbm_booking_service_end', $this->relative_datetime( $end_modifier ) );
		}

		return $booking_id;
	}

	/**
	 * 一括操作の選択肢が、権限・一覧の種類に応じて出る。
	 */
	public function test_add_bulk_action(): void {
		$bulk_action = new Booking_Anonymize_Bulk_Action( new Booking_Anonymizer() );

		$test_cases = array(
			array(
				'test_condition_name' => '予約を管理する権限を持つユーザーの通常の一覧 => 選択肢が出る',
				'manager'             => true,
				'get'                 => array(),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '予約を管理する権限を持つユーザーの「公開済み」の一覧 => 選択肢が出る',
				'manager'             => true,
				'get'                 => array( 'post_status' => 'publish' ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '予約を管理する権限が無いユーザー => 選択肢が出ない',
				'manager'             => false,
				'get'                 => array(),
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'ゴミ箱の一覧 => 選択肢が出ない',
				'manager'             => true,
				'get'                 => array( 'post_status' => 'trash' ),
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			if ( $case['manager'] ) {
				$this->switch_to_manager();
			} else {
				wp_set_current_user( $this->factory()->user->create( array( 'role' => 'subscriber' ) ) );
			}
			$_GET = $case['get'];

			$actions = $bulk_action->add_bulk_action( array( 'trash' => 'ゴミ箱へ移動' ) );

			$this->assertSame(
				$case['expected'],
				array_key_exists( Booking_Anonymize_Bulk_Action::ACTION, $actions ),
				$case['test_condition_name']
			);
			$this->assertArrayHasKey( 'trash', $actions, $case['test_condition_name'] . '（既存の選択肢は残る）' );
		}
	}

	/**
	 * 一括操作の実行で、結果の種類ごとの件数が正しく集計される。
	 */
	public function test_handle_bulk_action(): void {
		$manager_id  = $this->switch_to_manager();
		$ended_1     = $this->create_booking( '-2 hours' );
		$ended_2     = $this->create_booking( '-1 day' );
		$not_ended_1 = $this->create_booking( '+2 hours' );
		$not_ended_2 = $this->create_booking( '+3 days' );
		$no_datetime = $this->create_booking( '' );
		$already     = $this->create_booking( '-2 hours' );
		( new Booking_Anonymizer() )->anonymize( $already );

		$bulk_action = new Booking_Anonymize_Bulk_Action( new Booking_Anonymizer() );
		$redirect    = $bulk_action->handle_bulk_action(
			'http://example.org/wp-admin/edit.php?post_type=vkbm_booking',
			Booking_Anonymize_Bulk_Action::ACTION,
			array( $ended_1, $ended_2, $not_ended_1, $not_ended_2, $no_datetime, $already )
		);

		$this->assertStringContainsString( Booking_Anonymize_Bulk_Action::QUERY_VAR_DONE . '=1', $redirect );

		$result = get_transient( 'vkbm_anonymize_result_' . $manager_id );
		$this->assertSame( 2, $result[ Booking_Anonymizer::RESULT_ANONYMIZED ], '匿名化した件数' );
		$this->assertSame( array( $not_ended_1, $not_ended_2 ), $result[ Booking_Anonymizer::RESULT_NOT_ENDED ], '終了前の予約' );
		$this->assertSame( 1, $result[ Booking_Anonymizer::RESULT_NO_DATETIME ], '日時が未設定の件数' );
		$this->assertSame( 1, $result[ Booking_Anonymizer::RESULT_ALREADY_ANONYMIZED ], '匿名化済みの件数' );

		// 実際の中身: 終了済みだけが匿名化され、他は変わらない。
		$this->assertTrue( Booking_Anonymizer::is_anonymized( $ended_1 ) );
		$this->assertTrue( Booking_Anonymizer::is_anonymized( $ended_2 ) );
		foreach ( array( $not_ended_1, $not_ended_2, $no_datetime ) as $booking_id ) {
			$this->assertFalse( Booking_Anonymizer::is_anonymized( $booking_id ) );
			$this->assertSame( '山田太郎', get_post_meta( $booking_id, '_vkbm_booking_customer_name', true ) );
		}
	}

	/**
	 * 保存に失敗した予約は、失敗の件数として集計され、印が付かない。
	 */
	public function test_handle_bulk_action_failed(): void {
		$manager_id = $this->switch_to_manager();
		$booking_id = $this->create_booking( '-2 hours' );

		add_filter( 'wp_insert_post_empty_content', '__return_true' );
		( new Booking_Anonymize_Bulk_Action( new Booking_Anonymizer() ) )->handle_bulk_action( 'http://example.org/wp-admin/edit.php', Booking_Anonymize_Bulk_Action::ACTION, array( $booking_id ) );
		remove_filter( 'wp_insert_post_empty_content', '__return_true' );

		$result = get_transient( 'vkbm_anonymize_result_' . $manager_id );
		$this->assertSame( 1, $result[ Booking_Anonymizer::RESULT_FAILED ] );
		$this->assertSame( 0, $result[ Booking_Anonymizer::RESULT_ANONYMIZED ] );
		$this->assertFalse( Booking_Anonymizer::is_anonymized( $booking_id ) );
	}

	/**
	 * 権限が無いユーザー、別の一括操作では、実行されない。
	 */
	public function test_handle_bulk_action_denied(): void {
		$bulk_action = new Booking_Anonymize_Bulk_Action( new Booking_Anonymizer() );

		$test_cases = array(
			array(
				'test_condition_name' => '予約を管理する権限が無いユーザー => 実行されない',
				'manager'             => false,
				'action'              => Booking_Anonymize_Bulk_Action::ACTION,
			),
			array(
				'test_condition_name' => '別の一括操作（trash） => 実行されない',
				'manager'             => true,
				'action'              => 'trash',
			),
		);

		foreach ( $test_cases as $case ) {
			if ( $case['manager'] ) {
				$this->switch_to_manager();
			} else {
				wp_set_current_user( $this->factory()->user->create( array( 'role' => 'subscriber' ) ) );
			}
			$booking_id = $this->create_booking( '-2 hours' );

			$redirect = $bulk_action->handle_bulk_action( 'http://example.org/wp-admin/edit.php', $case['action'], array( $booking_id ) );

			$this->assertSame( 'http://example.org/wp-admin/edit.php', $redirect, $case['test_condition_name'] );
			$this->assertFalse( Booking_Anonymizer::is_anonymized( $booking_id ), $case['test_condition_name'] );
			$this->assertSame( '山田太郎', get_post_meta( $booking_id, '_vkbm_booking_customer_name', true ), $case['test_condition_name'] );
		}
	}

	/**
	 * 結果の通知が、結果の種類ごとに別々の通知として出る。
	 */
	public function test_render_result_notices(): void {
		$manager_id = $this->switch_to_manager();

		$test_cases = array(
			array(
				'test_condition_name' => '全部の種類がある場合 => 4つの通知（成功・終了前・日時未設定・匿名化済み）',
				'result'              => array(
					Booking_Anonymizer::RESULT_ANONYMIZED  => 3,
					Booking_Anonymizer::RESULT_NOT_ENDED   => array( 'ids' => 2 ),
					Booking_Anonymizer::RESULT_NO_DATETIME => 1,
					Booking_Anonymizer::RESULT_ALREADY_ANONYMIZED => 4,
				),
				'expected_classes'    => array( 'notice-success', 'notice-warning', 'notice-warning', 'notice-info' ),
			),
			array(
				'test_condition_name' => '1件も匿名化しなかった場合 => 成功の通知を出さない（「0件を匿名化しました」を出さない）',
				'result'              => array(
					Booking_Anonymizer::RESULT_ANONYMIZED  => 0,
					Booking_Anonymizer::RESULT_NOT_ENDED   => array( 'ids' => 1 ),
					Booking_Anonymizer::RESULT_NO_DATETIME => 0,
					Booking_Anonymizer::RESULT_ALREADY_ANONYMIZED => 0,
				),
				'expected_classes'    => array( 'notice-warning' ),
			),
			array(
				'test_condition_name' => '保存に失敗した予約がある場合 => エラーの通知を別に出す',
				'result'              => array(
					Booking_Anonymizer::RESULT_ANONYMIZED => 1,
					Booking_Anonymizer::RESULT_FAILED     => 2,
				),
				'expected_classes'    => array( 'notice-success', 'notice-error' ),
			),
			array(
				'test_condition_name' => '成功だけの場合 => 成功の通知だけ',
				'result'              => array(
					Booking_Anonymizer::RESULT_ANONYMIZED  => 1,
					Booking_Anonymizer::RESULT_NOT_ENDED   => array(),
					Booking_Anonymizer::RESULT_NO_DATETIME => 0,
					Booking_Anonymizer::RESULT_ALREADY_ANONYMIZED => 0,
				),
				'expected_classes'    => array( 'notice-success' ),
			),
		);

		$bulk_action = new Booking_Anonymize_Bulk_Action( new Booking_Anonymizer() );
		set_current_screen( 'edit-' . Booking_Post_Type::POST_TYPE );

		foreach ( $test_cases as $case ) {
			$result = $case['result'];
			// 終了前の予約は、件数に合わせた実在する予約の投稿IDを入れる。
			if ( isset( $result[ Booking_Anonymizer::RESULT_NOT_ENDED ]['ids'] ) ) {
				$ids = array();
				for ( $i = 0; $i < $result[ Booking_Anonymizer::RESULT_NOT_ENDED ]['ids']; $i++ ) {
					$ids[] = $this->create_booking( '+1 day', '山田太郎 / ' . $i );
				}
				$result[ Booking_Anonymizer::RESULT_NOT_ENDED ] = $ids;
			}
			set_transient( 'vkbm_anonymize_result_' . $manager_id, $result, 300 );
			$_GET[ Booking_Anonymize_Bulk_Action::QUERY_VAR_DONE ] = '1';

			ob_start();
			$bulk_action->render_result_notices();
			$html = (string) ob_get_clean();

			preg_match_all( '/class="notice (notice-[a-z]+)/', $html, $matches );
			$this->assertSame( $case['expected_classes'], $matches[1], $case['test_condition_name'] );
			$this->assertFalse( get_transient( 'vkbm_anonymize_result_' . $manager_id ), $case['test_condition_name'] . '（表示したら消す）' );
		}
	}

	/**
	 * 終了前の予約の通知は、10件までタイトルを編集画面へのリンクで並べ、超える分を「ほか N 件」にまとめる。
	 */
	public function test_render_result_notices_not_ended_list_limit(): void {
		$manager_id = $this->switch_to_manager();

		$ids = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$ids[] = $this->create_booking( '+1 day', 'お客様' . $i . ' / 2030.1.1 10:00' );
		}
		set_transient(
			'vkbm_anonymize_result_' . $manager_id,
			array(
				Booking_Anonymizer::RESULT_ANONYMIZED => 0,
				Booking_Anonymizer::RESULT_NOT_ENDED  => $ids,
			),
			300
		);
		$_GET[ Booking_Anonymize_Bulk_Action::QUERY_VAR_DONE ] = '1';
		set_current_screen( 'edit-' . Booking_Post_Type::POST_TYPE );

		ob_start();
		( new Booking_Anonymize_Bulk_Action( new Booking_Anonymizer() ) )->render_result_notices();
		$html = (string) ob_get_clean();

		$this->assertSame( 10, substr_count( $html, 'post.php?post=' ), '編集画面へのリンクは10件まで' );
		$this->assertSame( 11, substr_count( $html, '<li>' ), '10件のリンクと「ほか N 件」の1行' );
		$this->assertStringContainsString( 'お客様0 / 2030.1.1 10:00', $html );
		$this->assertStringNotContainsString( 'お客様11 / 2030.1.1 10:00', $html );
	}

	/**
	 * 予約一覧以外の画面、または実行直後ではないときは、通知を出さない。
	 */
	public function test_render_result_notices_not_shown(): void {
		$manager_id  = $this->switch_to_manager();
		$bulk_action = new Booking_Anonymize_Bulk_Action( new Booking_Anonymizer() );
		set_transient(
			'vkbm_anonymize_result_' . $manager_id,
			array( Booking_Anonymizer::RESULT_ANONYMIZED => 2 ),
			300
		);

		// 実行直後を示すクエリ引数が無い。
		set_current_screen( 'edit-' . Booking_Post_Type::POST_TYPE );
		ob_start();
		$bulk_action->render_result_notices();
		$this->assertSame( '', (string) ob_get_clean(), 'クエリ引数が無い場合 => 出さない' );

		// 別の画面。
		$_GET[ Booking_Anonymize_Bulk_Action::QUERY_VAR_DONE ] = '1';
		set_current_screen( 'edit-post' );
		ob_start();
		$bulk_action->render_result_notices();
		$this->assertSame( '', (string) ob_get_clean(), '予約一覧以外の画面 => 出さない' );
	}

	/**
	 * 一覧のタイトル横に「匿名化済み」が出る。
	 */
	public function test_add_anonymized_post_state(): void {
		$bulk_action = new Booking_Anonymize_Bulk_Action( new Booking_Anonymizer() );

		$anonymized_id     = $this->create_booking( '-2 hours' );
		$not_anonymized_id = $this->create_booking( '-2 hours' );
		( new Booking_Anonymizer() )->anonymize( $anonymized_id );
		$page_id = $this->factory()->post->create( array( 'post_type' => 'page' ) );

		$test_cases = array(
			array(
				'test_condition_name' => '匿名化済みの予約 => 「匿名化済み」を追加する',
				'post_id'             => $anonymized_id,
				'expected'            => array( 'vkbm_anonymized' => __( 'Anonymized', 'vk-booking-manager' ) ),
			),
			array(
				'test_condition_name' => '匿名化していない予約 => 追加しない',
				'post_id'             => $not_anonymized_id,
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '予約以外の投稿 => 追加しない',
				'post_id'             => $page_id,
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame(
				$case['expected'],
				$bulk_action->add_anonymized_post_state( array(), get_post( $case['post_id'] ) ),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * 確認ダイアログの本文に、匿名化の対象の欄名が入る（フィルターで足した項目も含む）。
	 */
	public function test_get_dialog_strings(): void {
		$bulk_action = new Booking_Anonymize_Bulk_Action( new Booking_Anonymizer() );

		$strings = $bulk_action->get_dialog_strings();
		foreach ( Booking_Anonymizer::get_field_labels() as $label ) {
			$this->assertStringContainsString( $label, $strings['bodyFields'], $label . ' が本文に入る' );
		}
		$this->assertStringNotContainsString( '訪問先の住所', $strings['bodyFields'] );

		$add_address = static function ( array $fields ): array {
			$fields['_vkbm_booking_address'] = array(
				'label' => '訪問先の住所',
				'value' => '',
			);
			return $fields;
		};
		add_filter( 'vkbm_booking_anonymize_meta_fields', $add_address );
		$strings = $bulk_action->get_dialog_strings();
		remove_filter( 'vkbm_booking_anonymize_meta_fields', $add_address );

		$this->assertStringContainsString( '訪問先の住所', $strings['bodyFields'], 'フィルターで足した項目も本文に入る' );
		foreach ( array( 'title', 'bodyFields', 'bodyAttachment', 'bodyMyPage', 'bodyKept', 'bodyFlags', 'bodyNotEnded', 'bodyIrrevers', 'cancel', 'confirmButton' ) as $key ) {
			$this->assertNotSame( '', $strings[ $key ], $key . ' の文言がある' );
		}
	}
}
