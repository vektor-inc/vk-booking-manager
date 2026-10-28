<?php
/**
 * WordPress 本体の個人データ消去依頼による予約匿名化のテスト（issue #872）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use DateTimeImmutable;
use VKBookingManager\Bookings\Booking_Anonymizer;
use VKBookingManager\Bookings\Booking_Privacy_Eraser;
use VKBookingManager\PostTypes\Booking_Post_Type;
use WP_UnitTestCase;

/**
 * Booking_Privacy_Eraser を検証する。
 *
 * @group bookings
 */
class Booking_Privacy_Eraser_Test extends WP_UnitTestCase {

	/**
	 * テスト対象。
	 *
	 * @var Booking_Privacy_Eraser
	 */
	private $eraser;

	/**
	 * 各テストの準備をする。
	 */
	public function set_up(): void {
		parent::set_up();
		$this->eraser = new Booking_Privacy_Eraser( new Booking_Anonymizer() );
	}

	/**
	 * 現在時刻からの相対日時を保存形式にする。
	 *
	 * @param string $modifier 相対指定。
	 * @return string サイトのタイムゾーンの日時。
	 */
	private function relative_datetime( string $modifier ): string {
		return ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( $modifier )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * テスト用の予約を作る。
	 *
	 * @param string                $email          お客様のメールアドレス。
	 * @param array<string, string> $meta_overrides 上書きする予約メタ。null 相当として空文字を渡したキーは保存しない。
	 * @param int                   $author_id      投稿者ID。
	 * @return int 予約ID。
	 */
	private function create_booking( string $email, array $meta_overrides = array(), int $author_id = 0 ): int {
		$booking_id = $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_author' => $author_id,
				'post_title'  => '予約者 / 予約日時',
			)
		);
		$service_id = $this->factory()->post->create( array( 'post_title' => 'カット' ) );
		$meta       = array_merge(
			array(
				'_vkbm_booking_customer_email' => $email,
				'_vkbm_booking_customer_name'  => '予約者',
				'_vkbm_booking_service_start'  => $this->relative_datetime( '-2 hours' ),
				'_vkbm_booking_service_end'    => $this->relative_datetime( '-1 hour' ),
				'_vkbm_booking_total_end'      => $this->relative_datetime( '-1 hour' ),
				'_vkbm_booking_service_id'     => (string) $service_id,
			),
			$meta_overrides
		);
		foreach ( $meta as $key => $value ) {
			if ( '' !== $value ) {
				update_post_meta( $booking_id, $key, $value );
			}
		}

		return $booking_id;
	}

	/**
	 * メールアドレスを正規化した完全一致だけが対象になる。
	 */
	public function test_erase_personal_data_email_matching(): void {
		$user_id    = $this->factory()->user->create( array( 'user_email' => 'target@example.com' ) );
		$test_cases = array(
			array(
				'test_condition_name' => '大小文字と前後空白が異なるメール => 匿名化する',
				'email'               => '  TARGET@Example.COM  ',
				'author_id'           => 0,
				'expected'            => true,
			),
			array(
				'test_condition_name' => '別メール => 匿名化しない',
				'email'               => 'other@example.com',
				'author_id'           => 0,
				'expected'            => false,
			),
			array(
				'test_condition_name' => '投稿者だけが対象会員 => 匿名化しない',
				'email'               => 'proxy@example.com',
				'author_id'           => $user_id,
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$booking_id = $this->create_booking( $case['email'], array(), $case['author_id'] );
			$this->eraser->erase_personal_data( 'target@example.com' );
			$this->assertSame( $case['expected'], Booking_Anonymizer::is_anonymized( $booking_id ), $case['test_condition_name'] );
		}

		foreach ( array( '', 'invalid-address' ) as $invalid_email ) {
			$result = $this->eraser->erase_personal_data( $invalid_email );
			$this->assertTrue( $result['done'], '空・不正メールは完了として返す' );
			$this->assertSame( array(), $result['messages'], '空・不正メールではメッセージを返さない' );
		}
	}

	/**
	 * 終了済みだけを匿名化し、保持した予約は完了回のメッセージへ出す。
	 */
	public function test_erase_personal_data_mixed_results(): void {
		$email       = 'mixed@example.com';
		$ended       = $this->create_booking( $email );
		$future      = $this->create_booking(
			$email,
			array(
				'_vkbm_booking_service_start' => $this->relative_datetime( '+1 hour' ),
				'_vkbm_booking_service_end'   => $this->relative_datetime( '+2 hours' ),
				'_vkbm_booking_total_end'     => $this->relative_datetime( '+2 hours' ),
			)
		);
		$no_datetime = $this->create_booking(
			$email,
			array(
				'_vkbm_booking_service_start' => '',
				'_vkbm_booking_service_end'   => '',
				'_vkbm_booking_total_end'     => '',
			)
		);
		$already     = $this->create_booking( $email );
		update_post_meta( $already, Booking_Anonymizer::META_ANONYMIZED_AT, gmdate( 'Y-m-d H:i:s' ) );

		$result = $this->eraser->erase_personal_data( $email );

		$this->assertTrue( Booking_Anonymizer::is_anonymized( $ended ), '終了済みは匿名化する' );
		$this->assertFalse( Booking_Anonymizer::is_anonymized( $future ), '未来は匿名化しない' );
		$this->assertFalse( Booking_Anonymizer::is_anonymized( $no_datetime ), '日時未設定は匿名化しない' );
		$this->assertTrue( $result['done'], '追加で匿名化できる予約が無ければ完了する' );
		$this->assertTrue( $result['items_removed'], '匿名化した予約があれば items_removed を立てる' );
		$this->assertTrue( $result['items_retained'], '保持した予約があれば items_retained を立てる' );
		$this->assertCount( 5, $result['messages'], '成功、未終了2件、日時未設定2件のメッセージを返す' );
	}

	/**
	 * 21件以上でもオフセットを使わず有限回で全件匿名化する。
	 */
	public function test_erase_personal_data_pagination(): void {
		$email       = 'many@example.com';
		$booking_ids = array();
		for ( $index = 0; $index < 21; ++$index ) {
			$booking_ids[] = $this->create_booking( $email );
		}

		$first  = $this->eraser->erase_personal_data( $email, 1 );
		$second = $this->eraser->erase_personal_data( $email, 2 );

		$this->assertFalse( $first['done'], '1回目は20件処理して未完了になる' );
		$this->assertTrue( $second['done'], '2回目で残りを処理して完了する' );
		foreach ( $booking_ids as $booking_id ) {
			$this->assertTrue( Booking_Anonymizer::is_anonymized( $booking_id ), '全予約が匿名化済みになる' );
		}
	}

	/**
	 * 未完了回の保存失敗も、その回の結果として返す。
	 */
	public function test_erase_personal_data_reports_failed_save_before_done(): void {
		$email       = 'paged-failure@example.com';
		$booking_ids = array();
		for ( $index = 0; $index < 21; ++$index ) {
			$booking_ids[] = $this->create_booking( $email );
		}
		$failed_booking_id         = $booking_ids[0];
		$stop_customer_name_update = static function ( $check, $object_id, $meta_key ) use ( $failed_booking_id ) {
			return $failed_booking_id === (int) $object_id && '_vkbm_booking_customer_name' === $meta_key ? false : $check;
		};
		add_filter( 'update_post_metadata', $stop_customer_name_update, 10, 3 );

		$first = $this->eraser->erase_personal_data( $email );

		remove_filter( 'update_post_metadata', $stop_customer_name_update, 10 );
		$second       = $this->eraser->erase_personal_data( $email );
		$all_messages = array_merge( $first['messages'], $second['messages'] );

		$this->assertFalse( $first['done'], '1回目は未処理の予約が残る' );
		$this->assertTrue( $first['items_retained'], '未完了回でも保存失敗は保持扱いにする' );
		$this->assertCount( 2, $first['messages'], '未完了回に成功と保存失敗のメッセージを返す' );
		$this->assertTrue( $second['done'], '2回目で残りを処理して完了する' );
		$this->assertNotEmpty(
			array_filter(
				$all_messages,
				static function ( string $message ): bool {
					return false !== strpos( $message, 'saving failed' ) || false !== strpos( $message, '保存に失敗' );
				}
			),
			'全体のメッセージに1回目の保存失敗を残す'
		);
	}

	/**
	 * 保存失敗だけなら完了して呼び出しの繰り返しを止める。
	 */
	public function test_erase_personal_data_failed_save(): void {
		$email = 'failure@example.com';
		$this->create_booking( $email );
		$stop_customer_name_update = static function ( $check, $object_id, $meta_key ) {
			return '_vkbm_booking_customer_name' === $meta_key ? false : $check;
		};
		add_filter( 'update_post_metadata', $stop_customer_name_update, 10, 3 );

		$result = $this->eraser->erase_personal_data( $email );

		remove_filter( 'update_post_metadata', $stop_customer_name_update, 10 );
		$this->assertTrue( $result['done'], '保存失敗だけなら完了する' );
		$this->assertFalse( $result['items_removed'], '保存失敗は削除扱いにしない' );
		$this->assertTrue( $result['items_retained'], '保存失敗は保持扱いにする' );
		$this->assertCount( 1, $result['messages'], '保存失敗メッセージだけを返す' );
	}

	/**
	 * 匿名化の途中で失敗してもメールを残し、同じ消去依頼から再実行できる。
	 */
	public function test_erase_personal_data_failure_restores_email_for_retry(): void {
		$email                     = 'retry@example.com';
		$booking_id                = $this->create_booking( $email );
		$stop_customer_name_update = static function ( $check, $object_id, $meta_key ) use ( $booking_id ) {
			return $booking_id === (int) $object_id && '_vkbm_booking_customer_name' === $meta_key ? false : $check;
		};
		add_filter( 'update_post_metadata', $stop_customer_name_update, 10, 3 );

		$first = $this->eraser->erase_personal_data( $email );

		remove_filter( 'update_post_metadata', $stop_customer_name_update, 10 );

		$this->assertFalse( Booking_Anonymizer::is_anonymized( $booking_id ), '失敗した予約には匿名化済みの印を付けない' );
		$this->assertSame( $email, get_post_meta( $booking_id, '_vkbm_booking_customer_email', true ), '再検索できるよう元のメールアドレスへ戻す' );
		$this->assertTrue( $first['items_retained'], '失敗した予約は保持扱いにする' );

		$second = $this->eraser->erase_personal_data( $email );

		$this->assertTrue( $second['items_removed'], '再実行で同じ予約を匿名化する' );
		$this->assertTrue( Booking_Anonymizer::is_anonymized( $booking_id ), '再実行後は匿名化済みになる' );
	}

	/**
	 * 未終了・日時未設定の保持メッセージは完了回だけ返し、一覧は10件で省略する。
	 */
	public function test_erase_personal_data_messages_only_when_done(): void {
		$email = 'messages@example.com';
		for ( $index = 0; $index < 21; ++$index ) {
			$this->create_booking( $email );
		}
		for ( $index = 0; $index < 11; ++$index ) {
			$this->create_booking(
				$email,
				array(
					'_vkbm_booking_service_start' => $this->relative_datetime( '+1 hour' ),
					'_vkbm_booking_service_end'   => $this->relative_datetime( '+2 hours' ),
					'_vkbm_booking_total_end'     => $this->relative_datetime( '+2 hours' ),
				)
			);
		}

		$first  = $this->eraser->erase_personal_data( $email );
		$second = $this->eraser->erase_personal_data( $email );

		$this->assertCount( 1, $first['messages'], '未完了回には成功メッセージだけを出す' );
		$this->assertCount( 3, $second['messages'], '完了回には成功と未終了の2メッセージを出す' );
		$this->assertSame( 10, substr_count( $second['messages'][1], 'カット' ), '予約一覧は10件まで表示する' );
	}

	/**
	 * 一覧の保存値をエスケープし、メニュー未設定は代替文言で表示する。
	 */
	public function test_erase_personal_data_escapes_booking_list(): void {
		$email            = 'escaped-list@example.com';
		$missing_menu     = $this->create_booking( $email, array( '_vkbm_booking_service_id' => '0' ) );
		$unsafe_menu      = $this->factory()->post->create( array( 'post_title' => 'Fish & Chips "Deluxe"' ) );
		$unsafe_menu_item = $this->create_booking( $email, array( '_vkbm_booking_service_id' => (string) $unsafe_menu ) );
		foreach ( array( $missing_menu, $unsafe_menu_item ) as $booking_id ) {
			update_post_meta( $booking_id, '_vkbm_booking_service_start', $this->relative_datetime( '+1 hour' ) );
			update_post_meta( $booking_id, '_vkbm_booking_service_end', $this->relative_datetime( '+2 hours' ) );
			update_post_meta( $booking_id, '_vkbm_booking_total_end', $this->relative_datetime( '+2 hours' ) );
		}
		$GLOBALS['post'] = get_post( $unsafe_menu ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- メニューID 0で get_the_title() がグローバル投稿を拾わないことを再現する。

		$result = $this->eraser->erase_personal_data( $email );

		$this->assertStringContainsString( esc_html__( 'Menu not set', 'vk-booking-manager' ), $result['messages'][0], 'メニューID 0ではグローバル投稿を拾わない' );
		$this->assertStringNotContainsString( 'Fish & Chips "Deluxe"', $result['messages'][0], '保存されたメニュー名を未エスケープで出力しない' );
		$this->assertStringContainsString( esc_html( get_the_title( $unsafe_menu ) ), $result['messages'][0], 'メニュー名はメッセージ組み立て後にエスケープする' );
	}

	/**
	 * 未ログインでも匿名化でき、会員情報は変更せず案内だけを返す。
	 */
	public function test_erase_personal_data_member_and_logged_out(): void {
		$email   = 'member@example.com';
		$user_id = $this->factory()->user->create( array( 'user_email' => $email ) );
		update_user_meta( $user_id, 'vkbm_full_name', '会員氏名' );
		$this->create_booking( $email, array(), $user_id );
		wp_set_current_user( 0 );

		$result = $this->eraser->erase_personal_data( $email );

		$this->assertTrue( $result['items_removed'], '未ログインでも匿名化できる' );
		$this->assertSame( '会員氏名', get_user_meta( $user_id, 'vkbm_full_name', true ), '会員メタは変更しない' );
		$this->assertCount( 2, $result['messages'], '成功と会員情報の案内を返す' );
		$this->assertFalse( $result['items_retained'], '会員情報の案内だけでは保持扱いにしない' );
	}

	/**
	 * 消去依頼の実行元を保存し、不正値と既存呼び出しは一括操作に倒す。
	 */
	public function test_anonymized_by(): void {
		$privacy_booking = $this->create_booking( 'origin@example.com' );
		$this->eraser->erase_personal_data( 'origin@example.com' );
		$this->assertSame( Booking_Anonymizer::ANONYMIZED_BY_PRIVACY_REQUEST, Booking_Anonymizer::get_anonymized_by( $privacy_booking ) );

		foreach ( array( '', 'invalid' ) as $origin ) {
			$booking_id = $this->create_booking( 'bulk-' . md5( $origin ) . '@example.com' );
			( new Booking_Anonymizer() )->anonymize( $booking_id, $origin );
			$this->assertSame( Booking_Anonymizer::ANONYMIZED_BY_BULK, Booking_Anonymizer::get_anonymized_by( $booking_id ), '既定値と不正値は bulk になる' );
		}
	}

	/**
	 * WordPress 本体の個人データ消去処理へ識別子と表示名を登録する。
	 */
	public function test_register_eraser(): void {
		$this->eraser->register();
		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress 本体の個人データ消去フィルターを検証する。

		$this->assertArrayHasKey( 'vk-booking-manager', $erasers );
		$this->assertSame( __( 'VK Booking Manager reservations', 'vk-booking-manager' ), $erasers['vk-booking-manager']['eraser_friendly_name'] );
		$this->assertIsCallable( $erasers['vk-booking-manager']['callback'] );
	}
}
