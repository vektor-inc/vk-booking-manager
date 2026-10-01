<?php
/**
 * #548: 仮予約の完了画面の案内文（provider_booking_complete_message_pending）の
 * サニタイズ・既定値解決・REST レスポンスのテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\ProviderSettings;

use ReflectionMethod;
use VKBookingManager\Admin\Provider_Settings_Page;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use VKBookingManager\REST\Provider_Settings_Controller;
use WP_UnitTestCase;

/**
 * 仮予約の完了画面の案内文に関するテストクラス。
 *
 * @group provider-settings
 * @group rest
 */
class Settings_Sanitizer_Booking_Complete_Message_Test extends WP_UnitTestCase {
	/**
	 * 各テスト後に保存済みの設定を消し、他のテストへ影響しないようにする。
	 */
	protected function tearDown(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		parent::tearDown();
	}

	/**
	 * Settings_Sanitizer::sanitize() が案内文を正規化することを検証する。
	 * 改行は保持し、前後の空白・HTML タグを除き、1000文字までに制限する。
	 */
	public function test_sanitize(): void {
		$defaults = ( new Settings_Repository() )->get_default_settings();
		$max      = Settings_Sanitizer::BOOKING_COMPLETE_MESSAGE_MAX_LENGTH;

		$test_cases = array(
			array(
				'test_condition_name' => '通常の文章（改行あり）の場合 => 改行を保持してそのまま保存',
				'conditions'          => array( 'input' => array( 'provider_booking_complete_message_pending' => "24時間以内にご連絡します。\nお待ちください。" ) ),
				'expected'            => "24時間以内にご連絡します。\nお待ちください。",
			),
			array(
				'test_condition_name' => '前後に空白・改行がある場合 => 前後を除いて保存',
				'conditions'          => array( 'input' => array( 'provider_booking_complete_message_pending' => "  \n案内文\n  " ) ),
				'expected'            => '案内文',
			),
			array(
				'test_condition_name' => 'HTML タグを含む場合 => タグを除いて保存',
				'conditions'          => array( 'input' => array( 'provider_booking_complete_message_pending' => '案内<script>alert(1)</script>文' ) ),
				'expected'            => '案内文',
			),
			array(
				'test_condition_name' => '改行が CRLF の場合 => LF にそろえて保存',
				'conditions'          => array( 'input' => array( 'provider_booking_complete_message_pending' => "1行目\r\n2行目\r3行目" ) ),
				'expected'            => "1行目\n2行目\n3行目",
			),
			array(
				'test_condition_name' => 'CRLF で上限ちょうどの長さの場合 => 切り詰められない',
				'conditions'          => array( 'input' => array( 'provider_booking_complete_message_pending' => str_repeat( "あ\r\n", intdiv( $max, 2 ) ) ) ),
				'expected'            => rtrim( str_repeat( "あ\n", intdiv( $max, 2 ) ) ),
			),
			array(
				'test_condition_name' => '空欄が送信された場合 => 空文字（表示時に標準文が使われる）',
				'conditions'          => array( 'input' => array( 'provider_booking_complete_message_pending' => '' ) ),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '空白のみが送信された場合 => 空文字',
				'conditions'          => array( 'input' => array( 'provider_booking_complete_message_pending' => "  \n  " ) ),
				'expected'            => '',
			),
			array(
				'test_condition_name' => 'キーが未送信の場合 => 既定値の空文字',
				'conditions'          => array( 'input' => array() ),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '上限を超える長さの場合 => 1000文字に切り詰め',
				'conditions'          => array( 'input' => array( 'provider_booking_complete_message_pending' => str_repeat( 'あ', $max + 50 ) ) ),
				'expected'            => str_repeat( 'あ', $max ),
			),
		);

		foreach ( $test_cases as $case ) {
			$result = ( new Settings_Sanitizer() )->sanitize( $case['conditions']['input'], $defaults );

			$this->assertSame(
				$case['expected'],
				$result['provider_booking_complete_message_pending'],
				$case['test_condition_name']
			);
		}
	}

	/**
	 * Provider_Settings_Page::sanitize_old_input()（検証エラー後のフォーム再表示用）が
	 * 案内文を保持し、同じ規則でサニタイズすることを検証する。
	 */
	public function test_sanitize_old_input(): void {
		$service = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$page    = new Provider_Settings_Page( $service );
		$method  = new ReflectionMethod( $page, 'sanitize_old_input' );
		$method->setAccessible( true );

		$test_cases = array(
			array(
				'test_condition_name' => '通常の文章（改行あり）の場合 => 改行を保持',
				'conditions'          => array( 'input' => array( 'provider_booking_complete_message_pending' => "1行目\n2行目" ) ),
				'expected'            => "1行目\n2行目",
			),
			array(
				'test_condition_name' => 'タグと前後の空白を含む場合 => タグと前後の空白を除く',
				'conditions'          => array( 'input' => array( 'provider_booking_complete_message_pending' => ' <b>太字</b>案内 ' ) ),
				'expected'            => '太字案内',
			),
			array(
				'test_condition_name' => 'キーが未送信の場合 => 空文字',
				'conditions'          => array( 'input' => array() ),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '改行が CRLF の場合 => LF にそろえる',
				'conditions'          => array( 'input' => array( 'provider_booking_complete_message_pending' => "1行目\r\n2行目" ) ),
				'expected'            => "1行目\n2行目",
			),
		);

		foreach ( $test_cases as $case ) {
			$result = $method->invoke( $page, $case['conditions']['input'] );

			$this->assertSame(
				$case['expected'],
				$result['provider_booking_complete_message_pending'],
				$case['test_condition_name']
			);
		}
	}

	/**
	 * REST /provider-settings の booking_complete_message_pending が実効値
	 * （空欄・空白のみ・未保存なら標準文、設定済みならその文言）を返すことを検証する。
	 */
	public function test_get_settings(): void {
		$repository = new Settings_Repository();
		$defaults   = $repository->get_default_settings();
		$controller = new Provider_Settings_Controller( $repository );
		$standard   = $repository->get_default_booking_complete_message_pending();

		$test_cases = array(
			array(
				'test_condition_name' => '案内文が空欄の場合 => 標準文',
				'conditions'          => array( 'provider_booking_complete_message_pending' => '' ),
				'expected'            => $standard,
			),
			array(
				'test_condition_name' => '案内文が空白のみの場合 => 標準文',
				'conditions'          => array( 'provider_booking_complete_message_pending' => "  \n " ),
				'expected'            => $standard,
			),
			array(
				'test_condition_name' => '案内文が設定済みの場合 => その文言',
				'conditions'          => array( 'provider_booking_complete_message_pending' => "24時間以内にメールでご連絡します。\nしばらくお待ちください。" ),
				'expected'            => "24時間以内にメールでご連絡します。\nしばらくお待ちください。",
			),
		);

		foreach ( $test_cases as $case ) {
			$repository->update_settings( array_merge( $defaults, $case['conditions'] ) );

			$data = $controller->get_settings()->get_data();

			$this->assertSame( $case['expected'], $data['booking_complete_message_pending'], $case['test_condition_name'] );
		}

		// 標準文が空でないこと（空だと画面に何も出なくなる）を確認する。
		$this->assertNotSame( '', $standard );
	}

	/**
	 * 設定自体が未保存（フレッシュインストール・既存サイトの更新直後）でも
	 * REST が標準文を返すことを検証する（境界値）。
	 */
	public function test_get_settings_returns_standard_text_when_option_not_saved(): void {
		$repository = new Settings_Repository();
		delete_option( Settings_Repository::OPTION_KEY );

		$data = ( new Provider_Settings_Controller( $repository ) )->get_settings()->get_data();

		$this->assertSame( $repository->get_default_booking_complete_message_pending(), $data['booking_complete_message_pending'] );
	}
}
