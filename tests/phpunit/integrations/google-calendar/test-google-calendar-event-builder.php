<?php
/**
 * Google カレンダーのイベント表現を組み立てるクラスのテスト。
 *
 * issue #476。通信を持たない純粋な変換処理のみを検証する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Integrations\GoogleCalendar;

use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Event_Builder;
use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Event_Sync_Settings;
use WP_UnitTestCase;

/**
 * Google_Calendar_Event_Builder のテスト。
 */
class Test_Google_Calendar_Event_Builder extends WP_UnitTestCase {

	/**
	 * 基本の入力値。テストケースごとに上書きして使う。
	 *
	 * @return array<string, mixed>
	 */
	private function base_booking(): array {
		return array(
			'booking_id'     => 42,
			'status'         => 'confirmed',
			'service_name'   => 'カット',
			'start'          => '2026-10-01 10:00:00',
			'end'            => '2026-10-01 10:30:00',
			'guests'         => 2,
			'resource_name'  => '山田',
			'resource_label' => 'スタッフ',
			'customer_name'  => 'お客様 太郎',
			'customer_tel'   => '090-1234-5678',
			'customer_email' => 'customer@example.com',
			'customer_note'  => 'アレルギーがあります',
			'admin_edit_url' => 'https://example.com/wp-admin/post.php?post=42&action=edit',
			'timezone'       => 'Asia/Tokyo',
		);
	}

	/**
	 * タイトル・開始終了日時・extendedProperties が想定どおり組み立てられること。
	 */
	public function test_build(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '確定済み（pending 以外） => タイトルはメニュー名そのまま',
				'overrides'           => array( 'status' => 'confirmed' ),
				'expected_summary'    => 'カット',
			),
			array(
				'test_condition_name' => '仮予約（pending） => タイトルに【仮】相当の接頭辞が付く',
				'overrides'           => array( 'status' => 'pending' ),
				'expected_summary'    => '[Tentative] カット',
			),
			array(
				'test_condition_name' => 'no_show（pending 以外） => タイトルはメニュー名そのまま',
				'overrides'           => array( 'status' => 'no_show' ),
				'expected_summary'    => 'カット',
			),
			array(
				'test_condition_name' => 'メニュー名が空 => 既定のタイトルにフォールバック（異常系）',
				'overrides'           => array(
					'status'       => 'confirmed',
					'service_name' => '',
				),
				'expected_summary'    => 'Reservation',
			),
		);

		foreach ( $test_cases as $case ) {
			$booking = array_merge( $this->base_booking(), $case['overrides'] );
			$event   = Google_Calendar_Event_Builder::build( $booking, array() );

			$this->assertIsArray( $event, $case['test_condition_name'] );
			$this->assertSame( 'confirmed', $event['status'], $case['test_condition_name'] . '（status は常に confirmed を明示すること。安藤レビュー指摘: cancelled 済みの予定へ update するとき復活しないおそれがあるため）' );
			$this->assertSame( $case['expected_summary'], $event['summary'], $case['test_condition_name'] );
			$this->assertSame( '2026-10-01T10:00:00', $event['start']['dateTime'], $case['test_condition_name'] );
			$this->assertSame( 'Asia/Tokyo', $event['start']['timeZone'], $case['test_condition_name'] );
			$this->assertSame( '2026-10-01T10:30:00', $event['end']['dateTime'], $case['test_condition_name'] );
			$this->assertSame( '42', $event['extendedProperties']['private']['vkbm_booking_id'], $case['test_condition_name'] );
		}
	}

	/**
	 * 開始・終了日時が無い場合は null を返すこと（異常系・境界値）。
	 */
	public function test_build_returns_null_without_datetime(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '開始日時が空 => null',
				'overrides'           => array( 'start' => '' ),
			),
			array(
				'test_condition_name' => '終了日時が空 => null',
				'overrides'           => array( 'end' => '' ),
			),
		);

		foreach ( $test_cases as $case ) {
			$booking = array_merge( $this->base_booking(), $case['overrides'] );
			$this->assertNull( Google_Calendar_Event_Builder::build( $booking, array() ), $case['test_condition_name'] );
		}
	}

	/**
	 * サイトのタイムゾーン設定が「都市を選ぶ」（IANA名）か「UTC±オフセットを直接入力する」
	 * （`+09:00` 形式）かで、`start`/`end` の組み立て方を切り替えることを検証する
	 * （安藤レビュー指摘: オフセット指定のとき `timeZone` を送ると Google に拒否され、
	 * 同期が全部失敗する）。
	 */
	public function test_build_datetime_handles_utc_offset_timezone(): void {
		$test_cases = array(
			array(
				'test_condition_name'       => 'IANA タイムゾーン名（例: Asia/Tokyo） => timeZone を付け、dateTime にオフセットを付けない（正常系）',
				'timezone'                  => 'Asia/Tokyo',
				'expect_offset_in_datetime' => false,
			),
			array(
				'test_condition_name'       => 'UTC+9 のようなオフセット直接指定（+09:00） => timeZone を送らず、dateTime にオフセットを直接埋め込む（異常系の回避策）',
				'timezone'                  => '+09:00',
				'expect_offset_in_datetime' => true,
			),
			array(
				'test_condition_name'       => 'マイナスのオフセット直接指定（-05:00） => 同様にオフセットを dateTime へ埋め込む（境界値）',
				'timezone'                  => '-05:00',
				'expect_offset_in_datetime' => true,
			),
		);

		foreach ( $test_cases as $case ) {
			$booking = array_merge( $this->base_booking(), array( 'timezone' => $case['timezone'] ) );
			$event   = Google_Calendar_Event_Builder::build( $booking, array() );

			$this->assertIsArray( $event, $case['test_condition_name'] );

			if ( $case['expect_offset_in_datetime'] ) {
				$this->assertArrayNotHasKey( 'timeZone', $event['start'], $case['test_condition_name'] );
				$this->assertSame( '2026-10-01T10:00:00' . $case['timezone'], $event['start']['dateTime'], $case['test_condition_name'] );
				$this->assertArrayNotHasKey( 'timeZone', $event['end'], $case['test_condition_name'] );
				$this->assertSame( '2026-10-01T10:30:00' . $case['timezone'], $event['end']['dateTime'], $case['test_condition_name'] );
			} else {
				$this->assertSame( $case['timezone'], $event['start']['timeZone'], $case['test_condition_name'] );
				$this->assertSame( '2026-10-01T10:00:00', $event['start']['dateTime'], $case['test_condition_name'] );
			}
		}
	}

	/**
	 * オンになっている項目だけが説明欄へ出ること。管理用メモに相当する項目は、
	 * そもそも選択肢に無いため引数として渡す手段自体が無い（Google_Calendar_Event_Sync_Settings 側で担保）。
	 */
	public function test_build_description_only_includes_enabled_fields(): void {
		$booking = $this->base_booking();

		$test_cases = array(
			array(
				'test_condition_name' => '項目を何もオンにしない => 説明欄は空文字（正常系）',
				'enabled_fields'      => array(),
				'expected_contains'   => array(),
				'expected_absent'     => array( '2', '山田', 'お客様 太郎', '090-1234-5678', 'customer@example.com', 'アレルギーがあります' ),
			),
			array(
				'test_condition_name' => '予約人数・担当・管理画面リンクのみオン（初期値相当） => その3項目だけ出る（正常系）',
				'enabled_fields'      => array(
					Google_Calendar_Event_Sync_Settings::FIELD_GUESTS,
					Google_Calendar_Event_Sync_Settings::FIELD_STAFF,
					Google_Calendar_Event_Sync_Settings::FIELD_ADMIN_LINK,
				),
				'expected_contains'   => array( '2', '山田', 'https://example.com/wp-admin/post.php?post=42&action=edit' ),
				'expected_absent'     => array( 'お客様 太郎', '090-1234-5678', 'customer@example.com', 'アレルギーがあります' ),
			),
			array(
				'test_condition_name' => 'お客様情報をすべてオン => 氏名・電話・メール・メモが出る（正常系）',
				'enabled_fields'      => array(
					Google_Calendar_Event_Sync_Settings::FIELD_CUSTOMER_NAME,
					Google_Calendar_Event_Sync_Settings::FIELD_CUSTOMER_TEL,
					Google_Calendar_Event_Sync_Settings::FIELD_CUSTOMER_EMAIL,
					Google_Calendar_Event_Sync_Settings::FIELD_CUSTOMER_NOTE,
				),
				'expected_contains'   => array( 'お客様 太郎', '090-1234-5678', 'customer@example.com', 'アレルギーがあります' ),
				'expected_absent'     => array( '山田' ),
			),
		);

		foreach ( $test_cases as $case ) {
			$event       = Google_Calendar_Event_Builder::build( $booking, $case['enabled_fields'] );
			$description = $event['description'];

			if ( array() === $case['enabled_fields'] ) {
				$this->assertSame( '', $description, $case['test_condition_name'] );
			}

			foreach ( $case['expected_contains'] as $needle ) {
				$this->assertStringContainsString( $needle, $description, $case['test_condition_name'] );
			}

			foreach ( $case['expected_absent'] as $needle ) {
				$this->assertStringNotContainsString( $needle, $description, $case['test_condition_name'] );
			}
		}
	}
}
