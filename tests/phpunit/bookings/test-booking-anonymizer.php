<?php
/**
 * 予約の個人情報の匿名化（Booking_Anonymizer）のテスト（issue #870）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use DateTimeImmutable;
use VKBookingManager\Bookings\Booking_Anonymizer;
use VKBookingManager\Integrations\Booking_Event_Dispatcher;
use VKBookingManager\PostTypes\Booking_Post_Type;
use WP_UnitTestCase;

/**
 * Booking_Anonymizer を検証する。
 *
 * @group bookings
 */
class Booking_Anonymizer_Test extends WP_UnitTestCase {
	/** 利用者名は設定にかかわらず匿名化対象に含める。 */
	public function test_attendee_name_is_always_anonymized(): void {
		$fields = Booking_Anonymizer::get_meta_fields();
		$this->assertArrayHasKey( '_vkbm_booking_attendee_name', $fields );
		$this->assertSame( '', $fields['_vkbm_booking_attendee_name']['value'] );
	}

	/** ご利用になる方の ID と番号の写しも匿名化対象に含める。 */
	public function test_attendee_id_and_number_are_anonymized(): void {
		$fields = Booking_Anonymizer::get_meta_fields();
		foreach ( array( '_vkbm_booking_attendee_id', '_vkbm_booking_attendee_number' ) as $key ) {
			$this->assertArrayHasKey( $key, $fields, $key . ' は匿名化の対象' );
			$this->assertSame( '', $fields[ $key ]['value'], $key . ' は空にする' );
		}
	}


	/**
	 * 現在時刻からの相対指定（例: '-2 hours'）を、保存形式（サイトのタイムゾーンの Y-m-d H:i:s）にする。
	 *
	 * @param string $modifier 相対指定。
	 * @return string 保存形式の日時。
	 */
	private function relative_datetime( string $modifier ): string {
		return ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( $modifier )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * 個人情報入りの予約を作る。
	 *
	 * @param array<string, string> $meta_overrides 上書きするメタ（値が空文字のものはメタを作らない）。
	 * @param int                   $author_id      投稿者（予約したお客様）のユーザーID。
	 * @return int 予約の投稿ID。
	 */
	private function create_booking( array $meta_overrides = array(), int $author_id = 0 ): int {
		$booking_id = $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_author' => $author_id,
				'post_title'  => '山田太郎 / 2020.1.1 10:00',
				'post_name'   => 'yamada-taro-2020-1-1-1000',
			)
		);

		$meta = array_merge(
			array(
				'_vkbm_booking_service_start'      => $this->relative_datetime( '-3 hours' ),
				'_vkbm_booking_service_end'        => $this->relative_datetime( '-2 hours' ),
				'_vkbm_booking_total_end'          => $this->relative_datetime( '-2 hours' ),
				'_vkbm_booking_status'             => 'confirmed',
				'_vkbm_booking_customer_name'      => '山田太郎',
				'_vkbm_booking_customer_tel'       => '09012345678',
				'_vkbm_booking_customer_email'     => 'taro@example.com',
				'_vkbm_booking_note'               => 'アレルギーがあります',
				'_vkbm_booking_internal_note'      => '常連のお客様',
				'_vkbm_booking_resource_id'        => '123',
				'_vkbm_booking_service_id'         => '456',
				'_vkbm_booking_guests'             => '2',
				'_vkbm_booking_base_total_price'   => '6000',
				'_vkbm_booking_billed_total_price' => '5500',
				'_vkbm_booking_service_base_price' => '3000',
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
	 * anonymize() が、終了日時を過ぎた予約だけを匿名化し、結果の種類を返す。
	 */
	public function test_anonymize(): void {
		// 匿名化済みの予約の保存ガード（本番では起動時に登録される）。
		Booking_Anonymizer::register();

		$test_cases = array(
			array(
				'test_condition_name' => '終了日時（後片付け込み）を過ぎた予約 => 匿名化する',
				'meta'                => array(),
				'expected'            => Booking_Anonymizer::RESULT_ANONYMIZED,
				'expected_name'       => 'DELETED',
			),
			array(
				'test_condition_name' => '終了していないキャンセル済みの予約 => ステータスでは判定せず、匿名化しない',
				'meta'                => array(
					'_vkbm_booking_status'    => 'cancelled',
					'_vkbm_booking_total_end' => '+1 day',
				),
				'expected'            => Booking_Anonymizer::RESULT_NOT_ENDED,
				'expected_name'       => '山田太郎',
			),
			array(
				'test_condition_name' => '終了済みのキャンセル済みの予約 => ステータスに関係なく匿名化する',
				'meta'                => array(
					'_vkbm_booking_status' => 'cancelled',
				),
				'expected'            => Booking_Anonymizer::RESULT_ANONYMIZED,
				'expected_name'       => 'DELETED',
			),
			array(
				'test_condition_name' => '未来の予約 => 匿名化しない',
				'meta'                => array(
					'_vkbm_booking_service_start' => '+1 day',
					'_vkbm_booking_service_end'   => '+1 day +1 hour',
					'_vkbm_booking_total_end'     => '+1 day +1 hour',
				),
				'expected'            => Booking_Anonymizer::RESULT_NOT_ENDED,
				'expected_name'       => '山田太郎',
			),
			array(
				'test_condition_name' => '進行中の予約（開始済み・終了前） => 匿名化しない',
				'meta'                => array(
					'_vkbm_booking_service_end' => '+1 hour',
					'_vkbm_booking_total_end'   => '+1 hour',
				),
				'expected'            => Booking_Anonymizer::RESULT_NOT_ENDED,
				'expected_name'       => '山田太郎',
			),
			array(
				'test_condition_name' => '滞在中の泊の予約（チェックアウトが未来） => 匿名化しない',
				'meta'                => array(
					'_vkbm_booking_service_start' => '-1 day',
					'_vkbm_booking_service_end'   => '+1 day',
					'_vkbm_booking_total_end'     => '+1 day',
				),
				'expected'            => Booking_Anonymizer::RESULT_NOT_ENDED,
				'expected_name'       => '山田太郎',
			),
			array(
				'test_condition_name' => '後片付けの時間を含めると終了前の予約 => 匿名化しない（total_end を優先する）',
				'meta'                => array(
					'_vkbm_booking_total_end' => '+30 minutes',
				),
				'expected'            => Booking_Anonymizer::RESULT_NOT_ENDED,
				'expected_name'       => '山田太郎',
			),
			array(
				'test_condition_name' => 'total_end が無く service_end を過ぎた予約 => 匿名化する',
				'meta'                => array(
					'_vkbm_booking_total_end' => '',
				),
				'expected'            => Booking_Anonymizer::RESULT_ANONYMIZED,
				'expected_name'       => 'DELETED',
			),
			array(
				'test_condition_name' => '開始日時しか無く、それを過ぎた予約 => 匿名化する',
				'meta'                => array(
					'_vkbm_booking_total_end'   => '',
					'_vkbm_booking_service_end' => '',
				),
				'expected'            => Booking_Anonymizer::RESULT_ANONYMIZED,
				'expected_name'       => 'DELETED',
			),
			array(
				'test_condition_name' => '秒なしで3つの日時候補が保存された終了済みの予約 => 匿名化する',
				'meta'                => array(
					'_vkbm_booking_service_start' => '2020-01-01 10:00',
					'_vkbm_booking_service_end'   => '2020-01-01 11:00',
					'_vkbm_booking_total_end'     => '2020-01-01 12:00',
				),
				'expected'            => Booking_Anonymizer::RESULT_ANONYMIZED,
				'expected_name'       => 'DELETED',
			),
			array(
				'test_condition_name' => 'T区切りで3つの日時候補が保存された終了済みの予約 => 匿名化する',
				'meta'                => array(
					'_vkbm_booking_service_start' => '2020-01-01T10:00:00',
					'_vkbm_booking_service_end'   => '2020-01-01T11:00:00',
					'_vkbm_booking_total_end'     => '2020-01-01T12:00:00',
				),
				'expected'            => Booking_Anonymizer::RESULT_ANONYMIZED,
				'expected_name'       => 'DELETED',
			),
			array(
				'test_condition_name' => 'total_end がゼロ日時で service_end が未来の予約 => 判定不能として匿名化しない',
				'meta'                => array(
					'_vkbm_booking_total_end'   => '0000-00-00 00:00:00',
					'_vkbm_booking_service_end' => '+1 day',
				),
				'expected'            => Booking_Anonymizer::RESULT_NO_DATETIME,
				'expected_name'       => '山田太郎',
			),
			array(
				'test_condition_name' => 'Z付きの終了日時と過去の開始日時がある予約 => 判定不能として匿名化しない',
				'meta'                => array(
					'_vkbm_booking_total_end'     => '',
					'_vkbm_booking_service_end'   => '2026-10-21T10:00:00Z',
					'_vkbm_booking_service_start' => '2020-01-01 10:00:00',
				),
				'expected'            => Booking_Anonymizer::RESULT_NO_DATETIME,
				'expected_name'       => '山田太郎',
			),
			array(
				'test_condition_name' => 'オフセット付きの終了日時と過去の開始日時がある予約 => 判定不能として匿名化しない',
				'meta'                => array(
					'_vkbm_booking_total_end'     => '',
					'_vkbm_booking_service_end'   => '2026-10-21T10:00:00+09:00',
					'_vkbm_booking_service_start' => '2020-01-01 10:00:00',
				),
				'expected'            => Booking_Anonymizer::RESULT_NO_DATETIME,
				'expected_name'       => '山田太郎',
			),
			array(
				'test_condition_name' => '存在しない月日だけの予約（2026-02-30） => 日時が未設定として匿名化しない',
				'meta'                => array(
					'_vkbm_booking_service_start' => '2026-02-30 10:00:00',
					'_vkbm_booking_service_end'   => '',
					'_vkbm_booking_total_end'     => '',
				),
				'expected'            => Booking_Anonymizer::RESULT_NO_DATETIME,
				'expected_name'       => '山田太郎',
			),
			array(
				'test_condition_name' => '日時がどれも未設定の予約 => 匿名化しない',
				'meta'                => array(
					'_vkbm_booking_service_start' => '',
					'_vkbm_booking_service_end'   => '',
					'_vkbm_booking_total_end'     => '',
				),
				'expected'            => Booking_Anonymizer::RESULT_NO_DATETIME,
				'expected_name'       => '山田太郎',
			),
			array(
				'test_condition_name' => '匿名化済みの予約 => 処理を飛ばす',
				'meta'                => array(
					Booking_Anonymizer::META_ANONYMIZED_AT => '2026-01-01 00:00:00',
				),
				'expected'            => Booking_Anonymizer::RESULT_ALREADY_ANONYMIZED,
				'expected_name'       => '山田太郎',
			),
		);

		foreach ( $test_cases as $case ) {
			$meta = array();
			foreach ( $case['meta'] as $key => $value ) {
				// '+1 day' のような相対指定は日時へ変換する。
				$meta[ $key ] = ( 0 === strpos( $value, '+' ) || 0 === strpos( $value, '-' ) ) ? $this->relative_datetime( $value ) : $value;
			}
			$booking_id = $this->create_booking( $meta );

			$actual = ( new Booking_Anonymizer() )->anonymize( $booking_id );

			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
			$this->assertSame(
				'DELETED' === $case['expected_name'] ? Booking_Anonymizer::get_deleted_label() : $case['expected_name'],
				get_post_meta( $booking_id, '_vkbm_booking_customer_name', true ),
				$case['test_condition_name'] . '（氏名）'
			);
		}

		// 予約ではない投稿は処理しない。
		$page_id = $this->factory()->post->create( array( 'post_type' => 'page' ) );
		$this->assertSame( Booking_Anonymizer::RESULT_INVALID, ( new Booking_Anonymizer() )->anonymize( $page_id ), '予約ではない投稿 => 処理しない' );
		$this->assertSame( Booking_Anonymizer::RESULT_INVALID, ( new Booking_Anonymizer() )->anonymize( 999999 ), '存在しない投稿ID => 処理しない' );
	}

	/**
	 * 匿名化で個人情報が消え、日時・料金などは残り、タイトル・スラッグに名前が残らない。
	 */
	public function test_anonymize_replaces_personal_data_and_keeps_the_rest(): void {
		$customer_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		$booking_id  = $this->create_booking( array(), $customer_id );
		$start       = (string) get_post_meta( $booking_id, '_vkbm_booking_service_start', true );

		$before_kept = array();
		$kept_keys   = array(
			'_vkbm_booking_service_start',
			'_vkbm_booking_service_end',
			'_vkbm_booking_total_end',
			'_vkbm_booking_status',
			'_vkbm_booking_resource_id',
			'_vkbm_booking_service_id',
			'_vkbm_booking_guests',
			'_vkbm_booking_base_total_price',
			'_vkbm_booking_billed_total_price',
			'_vkbm_booking_service_base_price',
		);
		foreach ( $kept_keys as $key ) {
			$before_kept[ $key ] = get_post_meta( $booking_id, $key, true );
		}

		$this->assertSame( Booking_Anonymizer::RESULT_ANONYMIZED, ( new Booking_Anonymizer() )->anonymize( $booking_id ) );

		// 個人情報のメタ: 氏名は「（削除済み）」、他は空。
		$replaced = array(
			'_vkbm_booking_customer_name'  => Booking_Anonymizer::get_deleted_label(),
			'_vkbm_booking_customer_tel'   => '',
			'_vkbm_booking_customer_email' => '',
			'_vkbm_booking_note'           => '',
			'_vkbm_booking_internal_note'  => '',
		);
		foreach ( $replaced as $key => $expected ) {
			$this->assertSame( $expected, get_post_meta( $booking_id, $key, true ), $key . ' が置き換わる' );
		}

		// 変えないメタ。
		foreach ( $before_kept as $key => $expected ) {
			$this->assertSame( $expected, get_post_meta( $booking_id, $key, true ), $key . ' は変わらない' );
		}

		$post = get_post( $booking_id );

		// タイトル: 名前部分だけ「（削除済み）」にして、開始日時から作り直す。
		$start_label = ( new DateTimeImmutable( $start, wp_timezone() ) )->format( 'Y.n.j H:i' );
		$this->assertSame( Booking_Anonymizer::get_deleted_label() . ' / ' . $start_label, $post->post_title );

		// 名前（元のタイトル・スラッグ）が残らない。
		$this->assertStringNotContainsString( '山田', $post->post_title );
		$this->assertStringNotContainsString( 'yamada', $post->post_name );
		$this->assertNotSame( '', $post->post_name, 'スラッグは WordPress が作り直す' );

		// 投稿者は 0。
		$this->assertSame( 0, (int) $post->post_author );

		// 匿名化済みの印: UTC の日時。
		$marker = (string) get_post_meta( $booking_id, Booking_Anonymizer::META_ANONYMIZED_AT, true );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $marker );
		$this->assertLessThan( 5, abs( time() - strtotime( $marker . ' UTC' ) ), '印は実行時刻（UTC）' );
		$this->assertTrue( Booking_Anonymizer::is_anonymized( $booking_id ) );
	}

	/**
	 * 開始日時が無い、または読み取れない予約は、タイトルが「（削除済み）」だけになる。
	 */
	public function test_anonymize_title_without_readable_start(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '開始日時が無い予約 => 日時をタイトルへ付けない',
				'start'               => '',
			),
			array(
				'test_condition_name' => '開始日時にタイムゾーンが続く予約 => 日時をタイトルへ付けない',
				'start'               => '2020-01-01T10:00:00Z',
			),
		);

		foreach ( $test_cases as $case ) {
			$booking_id = $this->create_booking( array( '_vkbm_booking_service_start' => $case['start'] ) );

			$this->assertSame( Booking_Anonymizer::RESULT_ANONYMIZED, ( new Booking_Anonymizer() )->anonymize( $booking_id ), $case['test_condition_name'] );
			$this->assertSame( Booking_Anonymizer::get_deleted_label(), get_post( $booking_id )->post_title, $case['test_condition_name'] );
		}
	}

	/**
	 * 投稿者が 0 の予約でも匿名化できる。
	 */
	public function test_anonymize_with_author_zero(): void {
		$booking_id = $this->create_booking( array(), 0 );

		$this->assertSame( Booking_Anonymizer::RESULT_ANONYMIZED, ( new Booking_Anonymizer() )->anonymize( $booking_id ) );
		$this->assertSame( 0, (int) get_post( $booking_id )->post_author );
	}

	/**
	 * フィルターで足した項目も置き換わり、欄名の一覧にも出る。
	 */
	public function test_get_meta_fields_with_filter(): void {
		$add_address = static function ( array $fields ): array {
			$fields['_vkbm_booking_address'] = array(
				'label' => '訪問先の住所',
				'value' => '',
			);
			// 形が正しくない項目は取り除かれる。
			$fields['_vkbm_invalid_field'] = 'not-an-array';
			return $fields;
		};
		add_filter( 'vkbm_booking_anonymize_meta_fields', $add_address );

		$booking_id = $this->create_booking( array( '_vkbm_booking_address' => '東京都千代田区1-1' ) );

		$fields = Booking_Anonymizer::get_meta_fields();
		$this->assertArrayHasKey( '_vkbm_booking_address', $fields );
		$this->assertArrayNotHasKey( '_vkbm_invalid_field', $fields );
		$this->assertContains( '訪問先の住所', Booking_Anonymizer::get_field_labels() );
		$this->assertStringContainsString( '訪問先の住所', Booking_Anonymizer::get_field_labels_text() );

		$this->assertSame( Booking_Anonymizer::RESULT_ANONYMIZED, ( new Booking_Anonymizer() )->anonymize( $booking_id ) );
		$this->assertSame( '', get_post_meta( $booking_id, '_vkbm_booking_address', true ), 'フィルターで足した項目も置き換わる' );

		remove_filter( 'vkbm_booking_anonymize_meta_fields', $add_address );
		$this->assertArrayNotHasKey( '_vkbm_booking_address', Booking_Anonymizer::get_meta_fields() );
	}

	/**
	 * get_end_datetime() が total_end → service_end → service_start の順に使う。
	 */
	public function test_get_end_datetime(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '3つとも有る場合 => total_end',
				'meta'                => array(
					'_vkbm_booking_total_end'     => '2026-10-01 12:00:00',
					'_vkbm_booking_service_end'   => '2026-10-01 11:00:00',
					'_vkbm_booking_service_start' => '2026-10-01 10:00:00',
				),
				'expected'            => '2026-10-01 12:00:00',
			),
			array(
				'test_condition_name' => 'total_end が無い場合 => service_end',
				'meta'                => array(
					'_vkbm_booking_service_end'   => '2026-10-01 11:00:00',
					'_vkbm_booking_service_start' => '2026-10-01 10:00:00',
				),
				'expected'            => '2026-10-01 11:00:00',
			),
			array(
				'test_condition_name' => '開始日時だけ有る場合 => service_start',
				'meta'                => array(
					'_vkbm_booking_service_start' => '2026-10-01 10:00:00',
				),
				'expected'            => '2026-10-01 10:00:00',
			),
			array(
				'test_condition_name' => 'total_end がゼロ日時の場合 => 判定不能として null',
				'meta'                => array(
					'_vkbm_booking_total_end'   => '0000-00-00 00:00:00',
					'_vkbm_booking_service_end' => '2026-10-01 11:00:00',
				),
				'expected'            => null,
			),
			array(
				'test_condition_name' => '日時として読めない値しか無い場合 => null',
				'meta'                => array(
					'_vkbm_booking_total_end' => 'invalid',
				),
				'expected'            => null,
			),
		);

		foreach ( $test_cases as $case ) {
			$booking_id = $this->factory()->post->create( array( 'post_type' => Booking_Post_Type::POST_TYPE ) );
			foreach ( $case['meta'] as $key => $value ) {
				update_post_meta( $booking_id, $key, $value );
			}

			$actual = Booking_Anonymizer::get_end_datetime( $booking_id );

			$this->assertSame(
				$case['expected'],
				$actual ? $actual->format( 'Y-m-d H:i:s' ) : null,
				$case['test_condition_name']
			);
		}
	}

	/**
	 * 匿名化のあとに vkbm_booking_anonymized が1回だけ発火し、未処理の予約では発火しない。
	 */
	public function test_anonymize_fires_action(): void {
		$fired = array();
		$hook  = static function ( int $booking_id ) use ( &$fired ): void {
			$fired[] = $booking_id;
		};
		add_action( 'vkbm_booking_anonymized', $hook );

		$past_id   = $this->create_booking();
		$future_id = $this->create_booking(
			array(
				'_vkbm_booking_service_end' => $this->relative_datetime( '+2 days' ),
				'_vkbm_booking_total_end'   => $this->relative_datetime( '+2 days' ),
			)
		);

		$anonymizer = new Booking_Anonymizer();
		$anonymizer->anonymize( $past_id );
		$anonymizer->anonymize( $past_id );
		$anonymizer->anonymize( $future_id );

		remove_action( 'vkbm_booking_anonymized', $hook );

		$this->assertSame( array( $past_id ), $fired );
	}

	/**
	 * 匿名化は外部連携のフック（Google カレンダーの上書き用）を「変更」として1回発火し、通知メールは送らない。
	 */
	public function test_anonymize_dispatches_update_without_mail(): void {
		$events = array();
		$hook   = static function ( string $event, int $booking_id, array $payload ) use ( &$events ): void {
			$events[] = array(
				'event'   => $event,
				'id'      => $booking_id,
				'status'  => $payload['status'],
				'name'    => $payload['booking']['customer_name'],
				'changed' => $payload['changed'],
			);
		};
		add_action( 'vkbm_booking_state_changed', $hook, 10, 3 );

		$mail_count = 0;
		$mail_hook  = static function ( $short_circuit ) use ( &$mail_count ) {
			++$mail_count;
			return true;
		};
		add_filter( 'pre_wp_mail', $mail_hook );

		$booking_id = $this->create_booking();
		( new Booking_Anonymizer( new Booking_Event_Dispatcher() ) )->anonymize( $booking_id );

		remove_action( 'vkbm_booking_state_changed', $hook, 10 );
		remove_filter( 'pre_wp_mail', $mail_hook );

		$this->assertCount( 1, $events );
		$this->assertSame( 'updated', $events[0]['event'] );
		$this->assertSame( $booking_id, $events[0]['id'] );
		$this->assertSame( 'confirmed', $events[0]['status'], 'ステータスは変わらない' );
		$this->assertSame( Booking_Anonymizer::get_deleted_label(), $events[0]['name'], '匿名化した値が渡る' );
		$this->assertContains( 'customer_name', $events[0]['changed'] );
		$this->assertSame( 0, $mail_count, '通知メールは送らない' );
	}

	/**
	 * 匿名化の前後で、売上・稼働の集計に使う値（料金・件数・日時・担当）が変わらない。
	 */
	public function test_anonymize_keeps_aggregation_values(): void {
		$booking_ids = array( $this->create_booking(), $this->create_booking() );

		$summarize = function () use ( $booking_ids ): array {
			$summary = array(
				'count' => count(
					get_posts(
						array(
							'post_type'   => Booking_Post_Type::POST_TYPE,
							'post_status' => 'any',
							'numberposts' => -1,
							'fields'      => 'ids',
						)
					)
				),
			);
			foreach ( $booking_ids as $booking_id ) {
				foreach ( array( '_vkbm_booking_billed_total_price', '_vkbm_booking_base_total_price', '_vkbm_booking_guests', '_vkbm_booking_resource_id', '_vkbm_booking_service_id', '_vkbm_booking_service_start', '_vkbm_booking_total_end', '_vkbm_booking_status' ) as $key ) {
					$summary[ $booking_id ][ $key ] = get_post_meta( $booking_id, $key, true );
				}
			}
			return $summary;
		};

		$before = $summarize();
		foreach ( $booking_ids as $booking_id ) {
			( new Booking_Anonymizer() )->anonymize( $booking_id );
		}

		$this->assertSame( $before, $summarize() );
	}

	/**
	 * 匿名化後に元のお客様を wp_delete_user（再割り当てなし）で消しても、予約は残る。
	 */
	public function test_anonymized_booking_survives_wp_delete_user(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';

		$customer_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		$booking_id  = $this->create_booking( array(), $customer_id );

		( new Booking_Anonymizer() )->anonymize( $booking_id );
		wp_delete_user( $customer_id );

		$post = get_post( $booking_id );
		$this->assertInstanceOf( \WP_Post::class, $post, '匿名化した予約は、お客様のユーザーを消しても残る' );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( Booking_Anonymizer::get_deleted_label(), get_post_meta( $booking_id, '_vkbm_booking_customer_name', true ) );
	}

	/**
	 * 参考: 匿名化していない予約は、お客様のユーザーを wp_delete_user（再割り当てなし）で消すと一緒に消える。
	 *
	 * 予約の投稿タイプは author をサポートし、delete_with_user を指定していない（null）ため、
	 * WordPress 本体がユーザーと一緒に投稿を削除する（親 issue #869 の懸念の確認）。
	 */
	public function test_not_anonymized_booking_is_deleted_with_wp_delete_user(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';

		$customer_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		$booking_id  = $this->create_booking( array(), $customer_id );

		wp_delete_user( $customer_id );

		$this->assertNull( get_post( $booking_id ), '匿名化していない予約は、再割り当てなしでユーザーを消すと一緒に消える' );
	}

	/**
	 * 匿名化で、名前入りの古いスラッグ（_wp_old_slug・_wp_desired_post_slug）が残らない。
	 */
	public function test_anonymize_removes_old_slug_meta(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '公開済みの予約（スラッグが変わり _wp_old_slug が付く）',
				'trash'               => false,
			),
			array(
				'test_condition_name' => 'ゴミ箱の予約（_wp_desired_post_slug が付く）',
				'trash'               => true,
			),
		);

		foreach ( $test_cases as $case ) {
			$booking_id = $this->create_booking();
			// _wp_old_slug は複数の値を持てる。
			add_post_meta( $booking_id, '_wp_old_slug', 'yamada-taro-old-1' );
			add_post_meta( $booking_id, '_wp_old_slug', 'yamada-taro-old-2' );
			if ( $case['trash'] ) {
				wp_trash_post( $booking_id );
				$this->assertNotSame( array(), get_post_meta( $booking_id, '_wp_desired_post_slug', false ), '前提: ゴミ箱に入れると元のスラッグが残る' );
			}

			$this->assertSame( Booking_Anonymizer::RESULT_ANONYMIZED, ( new Booking_Anonymizer() )->anonymize( $booking_id ), $case['test_condition_name'] );

			$this->assertSame( array(), get_post_meta( $booking_id, '_wp_old_slug', false ), $case['test_condition_name'] . '（_wp_old_slug）' );
			$this->assertSame( array(), get_post_meta( $booking_id, '_wp_desired_post_slug', false ), $case['test_condition_name'] . '（_wp_desired_post_slug）' );
			$this->assertStringNotContainsString( 'yamada', get_post( $booking_id )->post_name, $case['test_condition_name'] . '（スラッグ）' );
		}
	}

	/**
	 * 匿名化済みの予約は、WordPress 本体の保存（wp_update_post・edit_post）でも投稿者・タイトル・スラッグを書き換えられない。
	 */
	public function test_keep_anonymized_post_data(): void {
		require_once ABSPATH . 'wp-admin/includes/post.php';

		$editor_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $editor_id )->add_cap( 'vkbm_manage_reservations' );
		wp_set_current_user( $editor_id );

		$test_cases = array(
			array(
				'test_condition_name' => 'wp_update_post で投稿者・タイトル・スラッグを渡す',
				'anonymize'           => true,
				'route'               => 'wp_update_post',
				'expected_author'     => 0,
				'expected_name_kept'  => true,
			),
			array(
				'test_condition_name' => 'edit_post（編集画面・一括編集と同じ経路）で投稿者・タイトル・スラッグを渡す',
				'anonymize'           => true,
				'route'               => 'edit_post',
				'expected_author'     => 0,
				'expected_name_kept'  => true,
			),
			array(
				'test_condition_name' => '匿名化していない予約 => 渡した内容で書き換わる',
				'anonymize'           => false,
				'route'               => 'wp_update_post',
				'expected_author'     => $editor_id,
				'expected_name_kept'  => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$booking_id = $this->create_booking();
			if ( $case['anonymize'] ) {
				( new Booking_Anonymizer() )->anonymize( $booking_id );
			}
			$saved = get_post( $booking_id );

			if ( 'edit_post' === $case['route'] ) {
				edit_post(
					array(
						'post_ID'              => $booking_id,
						'post_type'            => Booking_Post_Type::POST_TYPE,
						'post_status'          => 'publish',
						'post_title'           => '佐藤花子 / 2030.1.1 10:00',
						'post_name'            => 'sato-hanako',
						'post_author_override' => $editor_id,
					)
				);
			} else {
				wp_update_post(
					array(
						'ID'          => $booking_id,
						'post_title'  => '佐藤花子 / 2030.1.1 10:00',
						'post_name'   => 'sato-hanako',
						'post_author' => $editor_id,
					)
				);
			}

			clean_post_cache( $booking_id );
			$actual = get_post( $booking_id );

			$this->assertSame( $case['expected_author'], (int) $actual->post_author, $case['test_condition_name'] . '（投稿者）' );
			if ( $case['expected_name_kept'] ) {
				$this->assertSame( $saved->post_title, $actual->post_title, $case['test_condition_name'] . '（タイトル）' );
				$this->assertSame( $saved->post_name, $actual->post_name, $case['test_condition_name'] . '（スラッグ）' );
			} else {
				$this->assertSame( '佐藤花子 / 2030.1.1 10:00', $actual->post_title, $case['test_condition_name'] . '（タイトル）' );
			}
		}
	}

	/**
	 * 書き込みに失敗したときは、印もアクションも出さずに失敗として返し、直したあとに再実行できる。
	 */
	public function test_anonymize_failure_and_retry(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'メタの保存が反映されない場合',
				'filter_hook'         => 'update_post_metadata',
				'filter'              => static function ( $check, $object_id, $meta_key ) {
					return '_vkbm_booking_note' === $meta_key ? false : $check;
				},
				'accepted_args'       => 3,
			),
			array(
				'test_condition_name' => '匿名化済みの印の保存が反映されない場合',
				'filter_hook'         => 'update_post_metadata',
				'filter'              => static function ( $check, $object_id, $meta_key ) {
					return Booking_Anonymizer::META_ANONYMIZED_AT === $meta_key ? false : $check;
				},
				'accepted_args'       => 3,
			),
			array(
				'test_condition_name' => '投稿の更新がエラーになる場合',
				'filter_hook'         => 'wp_insert_post_empty_content',
				'filter'              => '__return_true',
				'accepted_args'       => 1,
			),
		);

		foreach ( $test_cases as $case ) {
			$fired = 0;
			$hook  = static function () use ( &$fired ): void {
				++$fired;
			};
			add_action( 'vkbm_booking_anonymized', $hook );
			$booking_id = $this->create_booking();
			add_filter( $case['filter_hook'], $case['filter'], 10, $case['accepted_args'] );

			$result = ( new Booking_Anonymizer() )->anonymize( $booking_id );

			remove_filter( $case['filter_hook'], $case['filter'], 10 );

			$this->assertSame( Booking_Anonymizer::RESULT_FAILED, $result, $case['test_condition_name'] );
			$this->assertFalse( Booking_Anonymizer::is_anonymized( $booking_id ), $case['test_condition_name'] . '（印を付けない）' );
			$this->assertSame( 0, $fired, $case['test_condition_name'] . '（アクションを出さない）' );

			// 原因が無くなれば、同じ予約を再実行できる。
			$this->assertSame( Booking_Anonymizer::RESULT_ANONYMIZED, ( new Booking_Anonymizer() )->anonymize( $booking_id ), $case['test_condition_name'] . '（再実行）' );
			$this->assertSame( 1, $fired, $case['test_condition_name'] . '（再実行でアクションを出す）' );
			remove_action( 'vkbm_booking_anonymized', $hook );
		}
	}

	/**
	 * 失敗した予約を別のインスタンスで再実行しても、外部連携へ「変更」が1回発火する。
	 *
	 * 初回で顧客メタの置き換えだけ成功すると、再実行時のスナップショットは匿名化済みの値になり差分が出ない。
	 * それでも Google カレンダーへ上書きを知らせる必要がある。
	 */
	public function test_anonymize_retry_dispatches_update_even_without_diff(): void {
		$events = array();
		$hook   = static function ( string $event, int $booking_id ) use ( &$events ): void {
			$events[] = array( $event, $booking_id );
		};
		add_action( 'vkbm_booking_state_changed', $hook, 10, 2 );

		$booking_id = $this->create_booking();

		// 初回: 投稿の更新だけが失敗する。メタは置き換わり、通知は出ない。
		add_filter( 'wp_insert_post_empty_content', '__return_true' );
		$first = ( new Booking_Anonymizer( new Booking_Event_Dispatcher() ) )->anonymize( $booking_id );
		remove_filter( 'wp_insert_post_empty_content', '__return_true' );

		$this->assertSame( Booking_Anonymizer::RESULT_FAILED, $first );
		$this->assertSame( array(), $events, '失敗した実行では通知しない' );

		// 再実行: 別のインスタンス。スナップショットは匿名化済みの値だが、通知は1回出る。
		$second = ( new Booking_Anonymizer( new Booking_Event_Dispatcher() ) )->anonymize( $booking_id );

		remove_action( 'vkbm_booking_state_changed', $hook, 10 );

		$this->assertSame( Booking_Anonymizer::RESULT_ANONYMIZED, $second );
		$this->assertSame( array( array( 'updated', $booking_id ) ), $events );
	}

	/**
	 * 開始日時が不正な値（ゼロ日時）でも、タイトルに不正な日時を入れない。
	 */
	public function test_anonymize_title_with_invalid_start(): void {
		$booking_id = $this->create_booking( array( '_vkbm_booking_service_start' => '0000-00-00 00:00:00' ) );

		$this->assertSame( Booking_Anonymizer::RESULT_ANONYMIZED, ( new Booking_Anonymizer() )->anonymize( $booking_id ) );
		$this->assertSame( Booking_Anonymizer::get_deleted_label(), get_post( $booking_id )->post_title );
	}

	/**
	 * 添付ファイルを作る。
	 *
	 * @param bool $uploaded 予約の編集画面からアップロードしたファイルの目印を付けるか。
	 * @return int 添付ファイルの投稿ID。
	 */
	private function create_attachment( bool $uploaded ): int {
		$attachment_id = $this->factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.jpg' );
		if ( $uploaded ) {
			update_post_meta( $attachment_id, '_vkbm_booking_uploaded_attachment', '1' );
		}

		return $attachment_id;
	}

	/**
	 * 添付ファイルを片付け、予約との紐付けを外す。
	 */
	public function test_anonymize_removes_attachments(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '編集画面からアップロードしたファイルだけ => ファイルを削除し、紐付けを外す',
				'attachments'         => array( true ),
				'expected_exists'     => array( false ),
			),
			array(
				'test_condition_name' => 'メディアライブラリから選んだファイルだけ => ファイルは残し、紐付けだけ外す',
				'attachments'         => array( false ),
				'expected_exists'     => array( true ),
			),
			array(
				'test_condition_name' => 'アップロードしたファイルと選んだファイルが混在 => アップロードしたものだけ削除する',
				'attachments'         => array( true, false, true ),
				'expected_exists'     => array( false, true, false ),
			),
			array(
				'test_condition_name' => '添付が無い予約 => そのまま匿名化できる',
				'attachments'         => array(),
				'expected_exists'     => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$attachment_ids = array();
			$files          = array();
			foreach ( $case['attachments'] as $uploaded ) {
				$attachment_id    = $this->create_attachment( $uploaded );
				$attachment_ids[] = $attachment_id;
				$files[]          = (string) get_attached_file( $attachment_id );
			}
			$booking_id = $this->create_booking();
			if ( array() !== $attachment_ids ) {
				update_post_meta( $booking_id, '_vkbm_booking_attachment_ids', $attachment_ids );
			}

			$result = ( new Booking_Anonymizer() )->anonymize( $booking_id );

			$this->assertSame( Booking_Anonymizer::RESULT_ANONYMIZED, $result, $case['test_condition_name'] );
			$this->assertTrue( Booking_Anonymizer::is_anonymized( $booking_id ), $case['test_condition_name'] . '（印）' );
			$this->assertSame( array(), get_post_meta( $booking_id, '_vkbm_booking_attachment_ids', false ), $case['test_condition_name'] . '（紐付け）' );
			foreach ( $attachment_ids as $i => $attachment_id ) {
				$this->assertSame( $case['expected_exists'][ $i ], get_post( $attachment_id ) instanceof \WP_Post, $case['test_condition_name'] . '（メディアライブラリ）' );
				$this->assertSame( $case['expected_exists'][ $i ], file_exists( $files[ $i ] ), $case['test_condition_name'] . '（サーバー上のファイル）' );
			}
		}
	}

	/**
	 * 他の予約も添付しているファイルは、削除せず紐付けだけ外す。すでに無いファイルは片付け済みとして扱う。
	 */
	public function test_anonymize_keeps_attachment_shared_with_other_booking(): void {
		$shared_id  = $this->create_attachment( true );
		$missing_id = $this->create_attachment( true );
		wp_delete_attachment( $missing_id, true );

		$booking_id = $this->create_booking();
		$other_id   = $this->create_booking();
		update_post_meta( $booking_id, '_vkbm_booking_attachment_ids', array( $missing_id, $shared_id ) );
		update_post_meta( $other_id, '_vkbm_booking_attachment_ids', array( $shared_id ) );

		$result = ( new Booking_Anonymizer() )->anonymize( $booking_id );

		$this->assertSame( Booking_Anonymizer::RESULT_ANONYMIZED, $result, '他の予約も添付している場合も匿名化できる' );
		$this->assertInstanceOf( \WP_Post::class, get_post( $shared_id ), '他の予約が添付しているファイルは残す' );
		$this->assertSame( array(), get_post_meta( $booking_id, '_vkbm_booking_attachment_ids', false ), '匿名化した予約からは外す' );
		$this->assertSame( array( $shared_id ), get_post_meta( $other_id, '_vkbm_booking_attachment_ids', true ), '他の予約の紐付けは変えない' );

		// 他の予約を匿名化すると、最後の1件になるためファイルを削除する。
		$this->assertSame( Booking_Anonymizer::RESULT_ANONYMIZED, ( new Booking_Anonymizer() )->anonymize( $other_id ) );
		$this->assertNull( get_post( $shared_id ), '最後の予約を匿名化したらファイルを削除する' );
	}

	/**
	 * 添付ファイルを削除できなかったときは、印もアクションも出さずに失敗として返し、直したあとに再実行できる。
	 */
	public function test_anonymize_attachment_failure_and_retry(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'ファイルの削除が止められた場合',
				'filter_hook'         => 'pre_delete_attachment',
				'filter'              => '__return_false',
				'accepted_args'       => 1,
			),
			array(
				'test_condition_name' => '紐付けの削除が反映されない場合',
				'filter_hook'         => 'delete_post_metadata',
				'filter'              => static function ( $check, $object_id, $meta_key ) {
					return '_vkbm_booking_attachment_ids' === $meta_key ? false : $check;
				},
				'accepted_args'       => 3,
			),
		);

		foreach ( $test_cases as $case ) {
			$fired = 0;
			$hook  = static function () use ( &$fired ): void {
				++$fired;
			};
			add_action( 'vkbm_booking_anonymized', $hook );
			$attachment_id = $this->create_attachment( true );
			$booking_id    = $this->create_booking();
			update_post_meta( $booking_id, '_vkbm_booking_attachment_ids', array( $attachment_id ) );
			add_filter( $case['filter_hook'], $case['filter'], 10, $case['accepted_args'] );

			$result = ( new Booking_Anonymizer() )->anonymize( $booking_id );

			remove_filter( $case['filter_hook'], $case['filter'], 10 );

			$this->assertSame( Booking_Anonymizer::RESULT_FAILED, $result, $case['test_condition_name'] );
			$this->assertFalse( Booking_Anonymizer::is_anonymized( $booking_id ), $case['test_condition_name'] . '（印を付けない）' );
			$this->assertSame( 0, $fired, $case['test_condition_name'] . '（アクションを出さない）' );
			$this->assertSame( array( $attachment_id ), get_post_meta( $booking_id, '_vkbm_booking_attachment_ids', true ), $case['test_condition_name'] . '（紐付けが残る）' );

			// 原因が無くなれば、同じ予約を再実行できる。
			$this->assertSame( Booking_Anonymizer::RESULT_ANONYMIZED, ( new Booking_Anonymizer() )->anonymize( $booking_id ), $case['test_condition_name'] . '（再実行）' );
			$this->assertNull( get_post( $attachment_id ), $case['test_condition_name'] . '（再実行でファイルを削除）' );
			$this->assertSame( array(), get_post_meta( $booking_id, '_vkbm_booking_attachment_ids', false ), $case['test_condition_name'] . '（再実行で紐付けを外す）' );
			$this->assertSame( 1, $fired, $case['test_condition_name'] . '（再実行でアクションを出す）' );
			remove_action( 'vkbm_booking_anonymized', $hook );
		}
	}

	/**
	 * 他の予約の添付配列の添字が対象のファイルの ID と同じ値でも、使用中とは判定しない。
	 */
	public function test_anonymize_ignores_array_index_matching_attachment_id(): void {
		$attachment_id = $this->create_attachment( true );
		$other_file_id = $this->create_attachment( false );
		$booking_id    = $this->create_booking();
		$other_id      = $this->create_booking();
		update_post_meta( $booking_id, '_vkbm_booking_attachment_ids', array( $attachment_id ) );

		// 添字が対象のファイルの ID と同じ値になる配列（保存形式に「;i:ID;」が現れるが、ID としては入っていない）。
		update_post_meta(
			$other_id,
			'_vkbm_booking_attachment_ids',
			array(
				0              => $other_file_id,
				$attachment_id => $other_file_id,
			)
		);

		$result = ( new Booking_Anonymizer() )->anonymize( $booking_id );

		$this->assertSame( Booking_Anonymizer::RESULT_ANONYMIZED, $result, '添字が一致するだけの予約があっても匿名化できる' );
		$this->assertNull( get_post( $attachment_id ), '添字が一致するだけなら、ファイルを削除する' );
		$this->assertInstanceOf( \WP_Post::class, get_post( $other_file_id ), '他の予約のファイルは残す' );
	}
}
