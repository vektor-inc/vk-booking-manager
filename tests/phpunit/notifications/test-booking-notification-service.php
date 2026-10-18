<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Notifications;

use ReflectionMethod;
use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Bookings\Lodging_Booking;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\Notifications\Booking_Notification_Service;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_UnitTestCase;

/**
 * @group notifications
 */
class Booking_Notification_Service_Test extends WP_UnitTestCase {
	/** @var string */
	private $original_date_format = '';

	/** @var string */
	private $original_time_format = '';

	/** @var string */
	private $original_timezone_string = '';

	/** @var string */
	private $original_admin_email = '';

	protected function setUp(): void {
		parent::setUp();

		// テスト終了時に戻せるよう、現在の日時関連設定を保持する。
		$this->original_date_format     = (string) get_option( 'date_format' );
		$this->original_time_format     = (string) get_option( 'time_format' );
		$this->original_timezone_string = (string) get_option( 'timezone_string' );
		$this->original_admin_email     = (string) get_option( 'admin_email' );

		update_option( 'timezone_string', 'Asia/Tokyo' );
	}

	protected function tearDown(): void {
		update_option( 'date_format', $this->original_date_format );
		update_option( 'time_format', $this->original_time_format );
		update_option( 'timezone_string', $this->original_timezone_string );
		update_option( 'admin_email', $this->original_admin_email );

		parent::tearDown();
	}

	public function test_format_datetime_with_weekday(): void {
		$service = new Booking_Notification_Service( new Settings_Repository() );
		$method  = new ReflectionMethod( Booking_Notification_Service::class, 'format_datetime_with_weekday' );
		$method->setAccessible( true );

		$test_cases = array(
			array(
				'test_condition_name' => '未指定の時はデフォルトフォーマットで整形される',
				'conditions'          => array(
					'locale'      => 'ja_JP',
					'date_format' => '',
					'time_format' => '',
					'input'       => '2026-02-26 12:30:00',
				),
				'expected'            => '2026-02-26 (木) 12:30',
			),
			array(
				'test_condition_name' => '年月日 時分 表記の時は設定フォーマットで整形される',
				'conditions'          => array(
					'locale'      => 'ja_JP',
					'date_format' => 'Y年n月j日',
					'time_format' => 'H:i',
					'input'       => '2026-02-26 12:30:00',
				),
				'expected'            => '2026年2月26日 (木) 12:30',
			),
			array(
				'test_condition_name' => 'en_US の時は英語の曜日短縮表記で整形される',
				'conditions'          => array(
					'locale'      => 'en_US',
					'date_format' => 'Y-m-d',
					'time_format' => 'H:i',
					'input'       => '2026-02-26 12:30:00',
				),
				'expected'            => '2026-02-26 (Thu) 12:30',
			),
		);

		foreach ( $test_cases as $case ) {
			$locale   = (string) ( $case['conditions']['locale'] ?? '' );
			$switched = false;
			if ( '' !== $locale ) {
				$current_locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
				if ( $current_locale !== $locale ) {
					$switched = switch_to_locale( $locale );
					if ( ! $switched ) {
						$this->markTestSkipped( 'Required locale not available: ' . $locale . ' / ' . $case['test_condition_name'] );
					}
				}
			}

			try {
				update_option( 'date_format', $case['conditions']['date_format'] );
				update_option( 'time_format', $case['conditions']['time_format'] );

				$actual = (string) $method->invoke( $service, $case['conditions']['input'] );
				$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
			} finally {
				if ( $switched ) {
					restore_previous_locale();
				}
			}
		}
	}

	public function test_format_reservation_datetime_range(): void {
		$service = new Booking_Notification_Service( new Settings_Repository() );
		$method  = new ReflectionMethod( Booking_Notification_Service::class, 'format_reservation_datetime_range' );
		$method->setAccessible( true );

		$test_cases = array(
			array(
				'test_condition_name' => '同日の場合は終了側を時刻のみで表示する',
				'conditions'          => array(
					'locale'      => 'ja_JP',
					'date_format' => 'Y年n月j日',
					'time_format' => 'H:i',
					'start'       => '2026-02-26 12:30:00',
					'end'         => '2026-02-26 14:40:00',
				),
				'expected'            => '2026年2月26日 (木) 12:30 - 14:40',
			),
			array(
				'test_condition_name' => '日付をまたぐ場合は終了側も年月日と曜日を表示する',
				'conditions'          => array(
					'locale'      => 'ja_JP',
					'date_format' => 'Y年n月j日',
					'time_format' => 'H:i',
					'start'       => '2026-02-26 23:30:00',
					'end'         => '2026-02-27 00:40:00',
				),
				'expected'            => '2026年2月26日 (木) 23:30 - 2026年2月27日 (金) 00:40',
			),
			array(
				'test_condition_name' => '開始または終了が未指定の場合は Not set を返す',
				'conditions'          => array(
					'locale'      => 'ja_JP',
					'date_format' => 'Y年n月j日',
					'time_format' => 'H:i',
					'start'       => '',
					'end'         => '2026-02-27 00:40:00',
				),
				'expected'            => __( 'Not set', 'vk-booking-manager' ),
			),
		);

		foreach ( $test_cases as $case ) {
			$locale   = (string) ( $case['conditions']['locale'] ?? '' );
			$switched = false;
			if ( '' !== $locale ) {
				$current_locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
				if ( $current_locale !== $locale ) {
					$switched = switch_to_locale( $locale );
					if ( ! $switched ) {
						$this->markTestSkipped( 'Required locale not available: ' . $locale . ' / ' . $case['test_condition_name'] );
					}
				}
			}

			try {
				update_option( 'date_format', $case['conditions']['date_format'] );
				update_option( 'time_format', $case['conditions']['time_format'] );

				$actual = (string) $method->invoke( $service, $case['conditions']['start'], $case['conditions']['end'] );
				$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
			} finally {
				if ( $switched ) {
					restore_previous_locale();
				}
			}
		}
	}

	/**
	 * 通知タイプごとの送信者ヘッダー情報が期待どおりに返ることを検証します。
	 */
	public function test_get_mail_header(): void {
		$service = new Booking_Notification_Service( new Settings_Repository() );
		$method  = new ReflectionMethod( Booking_Notification_Service::class, 'get_mail_header' );
		$method->setAccessible( true );

		$test_cases = array(
			array(
				'test_condition_name' => 'ユーザー向けメールは provider_name を優先し Reply-To に provider_email を設定する',
				'admin_email'         => 'admin@example.com',
				'type'                => 'pending_customer',
				'payload'             => array(
					'provider_name'       => 'Sample Provider',
					'provider_email'      => 'provider@example.com',
					'site_name'           => 'Sample Site',
					'booking_author_name' => '予約 太郎',
					'customer_email'      => 'customer@example.com',
				),
				'expected'            => array(
					'name'     => 'Sample Provider',
					'mail'     => 'sender@example.com',
					'reply_to' => 'provider@example.com',
				),
			),
			array(
				'test_condition_name' => 'ユーザー向けメールで provider_name が空の場合はサイト名にフォールバックし provider_email が空なら Reply-To は空',
				'admin_email'         => 'admin@example.com',
				'type'                => 'pending_customer',
				'payload'             => array(
					'provider_name'       => '',
					'provider_email'      => '',
					'site_name'           => 'Sample Site',
					'booking_author_name' => '予約 太郎',
					'customer_email'      => 'customer@example.com',
				),
				'expected'            => array(
					'name'     => 'Sample Site',
					'mail'     => 'sender@example.com',
					'reply_to' => '',
				),
			),
			array(
				'test_condition_name' => '施設向けメールは予約者名を From 名にし Reply-To に顧客メールを設定',
				'admin_email'         => 'admin@example.com',
				'type'                => 'pending_provider',
				'payload'             => array(
					'provider_name'       => 'Sample Provider',
					'provider_email'      => 'provider@example.com',
					'site_name'           => 'Sample Site',
					'booking_author_name' => '予約 太郎',
					'customer_email'      => 'customer@example.com',
				),
				'expected'            => array(
					'name'     => '予約 太郎',
					'mail'     => 'sender@example.com',
					'reply_to' => 'customer@example.com',
				),
			),
			array(
				'test_condition_name' => '施設向けメールで予約者名が空の場合は provider_name を From 名にフォールバック',
				'admin_email'         => 'admin@example.com',
				'type'                => 'confirmed_provider',
				'payload'             => array(
					'provider_name'       => 'Sample Provider',
					'provider_email'      => 'provider@example.com',
					'site_name'           => 'Sample Site',
					'booking_author_name' => '',
					'customer_email'      => 'customer@example.com',
				),
				'expected'            => array(
					'name'     => 'Sample Provider',
					'mail'     => 'sender@example.com',
					'reply_to' => 'customer@example.com',
				),
			),
			array(
				'test_condition_name' => '施設向けメールで顧客メールが不正な場合は Reply-To を空にする',
				'admin_email'         => 'admin@example.com',
				'type'                => 'cancelled_provider',
				'payload'             => array(
					'provider_name'       => 'Sample Provider',
					'provider_email'      => 'provider@example.com',
					'site_name'           => 'Sample Site',
					'booking_author_name' => '予約 太郎',
					'customer_email'      => 'not-an-email',
				),
				'expected'            => array(
					'name'     => '予約 太郎',
					'mail'     => 'sender@example.com',
					'reply_to' => '',
				),
			),
			array(
				'test_condition_name' => 'reminder_customer もユーザー向け経路として扱われ Reply-To に provider_email を設定する',
				'admin_email'         => 'admin@example.com',
				'type'                => 'reminder_customer',
				'payload'             => array(
					'provider_name'       => 'Sample Provider',
					'provider_email'      => 'provider@example.com',
					'site_name'           => 'Sample Site',
					'booking_author_name' => '予約 太郎',
					'customer_email'      => 'customer@example.com',
				),
				'expected'            => array(
					'name'     => 'Sample Provider',
					'mail'     => 'sender@example.com',
					'reply_to' => 'provider@example.com',
				),
			),
		);

		foreach ( $test_cases as $case ) {
			update_option( 'admin_email', $case['admin_email'] );
			update_option( Settings_Repository::OPTION_KEY, array( 'mail_from_email' => 'sender@example.com' ) );
			$actual = $method->invoke( $service, $case['type'], $case['payload'] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * メールヘッダー表示名の CR/LF が除去されることを検証します。
	 */
	public function test_sanitize_mail_header_name(): void {
		$service = new Booking_Notification_Service( new Settings_Repository() );
		$method  = new ReflectionMethod( Booking_Notification_Service::class, 'sanitize_mail_header_name' );
		$method->setAccessible( true );

		$actual = (string) $method->invoke( $service, "Sample Name\r\nBcc: attacker@example.com" );
		$this->assertSame( 'Sample Name Bcc: attacker@example.com', $actual );

		$actual_with_tags = (string) $method->invoke( $service, "<b>店舗名</b>\t" );
		$this->assertSame( '店舗名', $actual_with_tags );
	}

	/**
	 * get_reservation_information_lines: 条件ごとに出力される予約情報行を検証する。
	 *
	 * 出力行は payload とステータスラベルの組み合わせで決まる。各条件で「出力されるべき文字列(contains)」と
	 * 「出力されてはいけない文字列(not_contains)」をケース配列で定義し、foreach でまとめて検証する。
	 *
	 * 検証している分岐:
	 * - ステータス行 : $status_label が空でないときのみ出力。
	 * - スタッフ行   : 指名機能ON（staff_enabled=true）のときのみ出力。
	 * - 人数行       : 複数人予約（guests>1）のときのみ出力。
	 * - 所要時間/料金行: それぞれの値が空でないときのみ出力。
	 * 1予約は単一スタッフに割り当てられるため、複数スタッフ配分の内訳行は存在しない。
	 */
	public function test_get_reservation_information_lines(): void {
		$service = new Booking_Notification_Service( new Settings_Repository() );
		$method  = new ReflectionMethod( Booking_Notification_Service::class, 'get_reservation_information_lines' );
		$method->setAccessible( true );

		// テストは ja ロケールで実行されるためラベルは翻訳される（例: 'Number of guests: %d' → '人数: %d名'）。
		// そこで照合キーには「翻訳されるラベル」ではなく「payload に渡した値（センチネル）」を使い、ロケール非依存にする。
		$status_sentinel = 'STATUS-SENTINEL-XYZ'; // ステータス行が出たときだけ本文に現れる一意な値。

		// 全ケース共通のベース payload（各ケースで必要な項目だけ上書きする）。
		// booking_id・日時はガード値（3 など）と重複しない値にして、人数の数値マーカーが他行と衝突しないようにする。
		$base = array(
			'booking_id'              => 1,
			'menu_title'              => 'メニュー値',
			'staff_title'             => 'スタッフ名値',
			'reservation_datetime'    => '2026-07-01',
			'duration_label'          => '',
			'duration_label_heading'  => '所要時間',
			'price_label'             => '',
			'guests'                  => 1,
			'staff_enabled'           => false,
			'resource_label_singular' => 'スタッフ',
		);

		$cases = array(
			array(
				'name'         => '指名ON・人数3・ステータスなし => スタッフ行と人数行あり / ステータス行なし',
				'overrides'    => array(
					'staff_enabled' => true,
					'guests'        => 3,
				),
				'status_label' => '',
				// スタッフ名値=スタッフ行 / '3'=人数行（人数: 3名）。他の行に '3' は現れない。
				'contains'     => array( 'メニュー値', 'スタッフ名値', '3' ),
				// status_label 未指定なのでセンチネルは出力されない＝ステータス行なし。
				'not_contains' => array( $status_sentinel ),
			),
			array(
				'name'         => '指名OFF・人数1 => スタッフ行も人数行も出力しない',
				'overrides'    => array(
					'staff_enabled' => false,
					'guests'        => 1,
				),
				'status_label' => '',
				'contains'     => array( 'メニュー値' ),
				// スタッフ名値が無い＝スタッフ行なし。'3' が無い＝人数行なし（guests=1 は出力されない）。
				'not_contains' => array( 'スタッフ名値', '3', $status_sentinel ),
			),
			array(
				'name'         => 'ステータスラベルあり => ステータス行を出力',
				'overrides'    => array(),
				'status_label' => $status_sentinel,
				// 渡したセンチネルがそのまま本文に出る＝ステータス行あり（ラベル翻訳に依存しない）。
				'contains'     => array( $status_sentinel ),
				'not_contains' => array(),
			),
			array(
				'name'         => '所要時間・料金あり => 所要時間行と料金行を出力',
				'overrides'    => array(
					'duration_label' => '所要時間値60',
					'price_label'    => '料金値5000',
				),
				'status_label' => '',
				// duration_label / price_label に渡した値がそのまま出力される。
				'contains'     => array( '所要時間値60', '料金値5000' ),
				'not_contains' => array(),
			),
		);

		foreach ( $cases as $case ) {
			$payload = array_merge( $base, $case['overrides'] );
			$text    = implode( "\n", $method->invoke( $service, $payload, $case['status_label'] ) );

			foreach ( $case['contains'] as $needle ) {
				$this->assertStringContainsString( $needle, $text, $case['name'] . " => '{$needle}' を含むべき" );
			}
			foreach ( $case['not_contains'] as $needle ) {
				$this->assertStringNotContainsString( $needle, $text, $case['name'] . " => '{$needle}' を含まないべき" );
			}
		}
	}

	/**
	 * #827: build_booking_payload() の客室（担当者）欄。
	 *
	 * 泊の予約（予約に泊数を保存したもの）は指名フラグを保存しないため、指名の有無やメニュー単位の指名設定に
	 * 関係なく保存済みの客室名を出し、客室の行も出す。客室名が取れない場合は「おまかせ」ではなく「未定」にする。
	 * 時間枠の予約（宿泊のサイトの貸切風呂などを含む）と宿泊以外のサイトは従来どおり。
	 */
	public function test_build_booking_payload(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '宿泊と指名機能は Pro 版限定のため、無料版ではスキップする。' );
		}

		// 文言の検証は英語（原文）で行う（「未定」は 'TBD'）。
		switch_to_locale( 'en_US' );
		unload_textdomain( 'vk-booking-manager', true );
		$original_settings = get_option( Settings_Repository::OPTION_KEY, false );

		$service = new Booking_Notification_Service( new Settings_Repository() );
		$payload = new ReflectionMethod( Booking_Notification_Service::class, 'build_booking_payload' );
		$payload->setAccessible( true );
		$lines = new ReflectionMethod( Booking_Notification_Service::class, 'get_reservation_information_lines' );
		$lines->setAccessible( true );

		// 客室（リソース）を用意する。削除済みの客室は、作ってから完全に削除した ID を使う。
		$room_id         = (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => '桜の間',
			)
		);
		$deleted_room_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => '削除した客室',
			)
		);
		wp_delete_post( $deleted_room_id, true );

		$lodging_site = array(
			'industry_preset'         => 'lodging',
			'staff_enabled'           => true,
			'resource_label_singular' => '客室',
			'no_nomination_label'     => 'おまかせ（空いている客室）',
		);
		$salon_site   = array(
			'industry_preset'         => 'custom',
			'staff_enabled'           => true,
			'resource_label_singular' => 'スタッフ',
			'no_nomination_label'     => '指名なし',
		);

		$test_cases = array(
			array(
				'test_condition_name' => '泊の予約・指名フラグなし・客室あり => 客室名を出し、客室の行も出す',
				'conditions'          => array(
					'site'               => $lodging_site,
					'nights'             => 1,
					'resource_id'        => $room_id,
					'is_staff_preferred' => '',
					'disable_nomination' => false,
				),
				'expected'            => array(
					'staff_title'   => '桜の間',
					'staff_enabled' => true,
					'line'          => '客室: 桜の間',
				),
			),
			array(
				'test_condition_name' => '泊の予約・メニュー単位で指名を使わない設定 => 客室名を出し、客室の行も出す',
				'conditions'          => array(
					'site'               => $lodging_site,
					'nights'             => 2,
					'resource_id'        => $room_id,
					'is_staff_preferred' => '',
					'disable_nomination' => true,
				),
				'expected'            => array(
					'staff_title'   => '桜の間',
					'staff_enabled' => true,
					'line'          => '客室: 桜の間',
				),
			),
			array(
				'test_condition_name' => '泊の予約・客室が削除済み => 「おまかせ」ではなく「未定」を出す',
				'conditions'          => array(
					'site'               => $lodging_site,
					'nights'             => 1,
					'resource_id'        => $deleted_room_id,
					'is_staff_preferred' => '',
					'disable_nomination' => false,
				),
				'expected'            => array(
					'staff_title'   => 'TBD',
					'staff_enabled' => true,
					'line'          => '客室: TBD',
				),
			),
			array(
				'test_condition_name' => '宿泊のサイトの時間枠の予約・指名あり => 指名した名前を出す（従来どおり）',
				'conditions'          => array(
					'site'               => $lodging_site,
					'nights'             => 0,
					'resource_id'        => $room_id,
					'is_staff_preferred' => '1',
					'disable_nomination' => false,
				),
				'expected'            => array(
					'staff_title'   => '桜の間',
					'staff_enabled' => true,
					'line'          => '客室: 桜の間',
				),
			),
			array(
				'test_condition_name' => '宿泊のサイトの時間枠の予約・おまかせ => 指名なしラベルを出す（従来どおり）',
				'conditions'          => array(
					'site'               => $lodging_site,
					'nights'             => 0,
					'resource_id'        => $room_id,
					'is_staff_preferred' => '',
					'disable_nomination' => false,
				),
				'expected'            => array(
					'staff_title'   => 'おまかせ（空いている客室）',
					'staff_enabled' => true,
					'line'          => '客室: おまかせ（空いている客室）',
				),
			),
			array(
				'test_condition_name' => '時間枠の予約・おまかせ・メニュー単位で指名を使わない設定 => 行を出さない（従来どおり）',
				'conditions'          => array(
					'site'               => $lodging_site,
					'nights'             => 0,
					'resource_id'        => $room_id,
					'is_staff_preferred' => '',
					'disable_nomination' => true,
				),
				'expected'            => array(
					'staff_title'   => 'TBD',
					'staff_enabled' => false,
					'line'          => null,
				),
			),
			array(
				'test_condition_name' => '宿泊以外のサイトの指名あり => スタッフ名を出す（従来どおり）',
				'conditions'          => array(
					'site'               => $salon_site,
					'nights'             => 0,
					'resource_id'        => $room_id,
					'is_staff_preferred' => '1',
					'disable_nomination' => false,
				),
				'expected'            => array(
					'staff_title'   => '桜の間',
					'staff_enabled' => true,
					'line'          => 'スタッフ: 桜の間',
				),
			),
			array(
				'test_condition_name' => '宿泊以外のサイトのおまかせ => 指名なしラベルを出す（従来どおり）',
				'conditions'          => array(
					'site'               => $salon_site,
					'nights'             => 0,
					'resource_id'        => $room_id,
					'is_staff_preferred' => '',
					'disable_nomination' => false,
				),
				'expected'            => array(
					'staff_title'   => '指名なし',
					'staff_enabled' => true,
					'line'          => 'スタッフ: 指名なし',
				),
			),
		);

		try {
			foreach ( $test_cases as $case ) {
				$conditions = $case['conditions'];

				// サイトの設定を切り替え、設定のキャッシュを消す。
				update_option(
					Settings_Repository::OPTION_KEY,
					array_merge( ( new Settings_Repository() )->get_default_settings(), $conditions['site'] )
				);
				Industry_Presets::clear_cache();
				Staff_Editor::clear_nomination_enabled_cache();

				// メニューと予約を作る。泊の予約は泊数だけを保存し、指名フラグは確定処理と同じく空のままにする。
				$menu_meta  = $conditions['disable_nomination'] ? array( '_vkbm_disable_nomination' => '1' ) : array();
				$menu_id    = (int) $this->factory()->post->create(
					array(
						'post_type'   => Service_Menu_Post_Type::POST_TYPE,
						'post_status' => 'publish',
						'post_title'  => 'テストプラン',
						'meta_input'  => $menu_meta,
					)
				);
				$booking_id = (int) $this->factory()->post->create(
					array(
						'post_type'   => Booking_Post_Type::POST_TYPE,
						'post_status' => 'publish',
					)
				);
				update_post_meta( $booking_id, '_vkbm_booking_service_id', $menu_id );
				update_post_meta( $booking_id, '_vkbm_booking_resource_id', $conditions['resource_id'] );
				update_post_meta( $booking_id, '_vkbm_booking_is_staff_preferred', $conditions['is_staff_preferred'] );
				update_post_meta( $booking_id, '_vkbm_booking_service_start', '2026-10-10 15:00:00' );
				update_post_meta( $booking_id, '_vkbm_booking_service_end', '2026-10-11 10:00:00' );
				update_post_meta( $booking_id, '_vkbm_booking_status', 'confirmed' );
				if ( $conditions['nights'] > 0 ) {
					update_post_meta( $booking_id, Lodging_Booking::META_NIGHTS, $conditions['nights'] );
				}

				// 本文の材料と「予約情報」の行を組み立てる。
				$actual_payload = $payload->invoke( $service, $booking_id );
				$actual_lines   = $lines->invoke( $service, $actual_payload );

				$this->assertSame( $case['expected']['staff_title'], $actual_payload['staff_title'], $case['test_condition_name'] . '（客室・担当者名）' );
				$this->assertSame( $case['expected']['staff_enabled'], $actual_payload['staff_enabled'], $case['test_condition_name'] . '（行を出すか）' );
				if ( null === $case['expected']['line'] ) {
					$resource_prefix = $conditions['site']['resource_label_singular'] . ': ';
					$resource_lines  = array_filter(
						$actual_lines,
						static function ( string $line ) use ( $resource_prefix ): bool {
							return 0 === strpos( $line, $resource_prefix );
						}
					);
					$this->assertSame( array(), array_values( $resource_lines ), $case['test_condition_name'] . '（本文の行）' );
				} else {
					$this->assertContains( $case['expected']['line'], $actual_lines, $case['test_condition_name'] . '（本文の行）' );
				}
			}
		} finally {
			// 設定とロケールを元に戻す。
			if ( false === $original_settings ) {
				delete_option( Settings_Repository::OPTION_KEY );
			} else {
				update_option( Settings_Repository::OPTION_KEY, $original_settings );
			}
			Industry_Presets::clear_cache();
			Staff_Editor::clear_nomination_enabled_cache();
			restore_previous_locale();
		}
	}

	/**
	 * #651: handle_reminders() は「今」を実際の UTC の時刻として扱い、サイトのタイムゾーン
	 * （Asia/Tokyo）の分だけ早く送らないこと。
	 *
	 * @dataProvider provider_handle_reminders_timezone
	 *
	 * @param string $test_condition_name テスト条件名。
	 * @param int    $seconds_until_start 現在から予約開始までの秒数。
	 * @param bool   $expected_sent       24 時間前リマインダーが送られる期待値。
	 */
	public function test_handle_reminders_timezone( string $test_condition_name, int $seconds_until_start, bool $expected_sent ): void {
		update_option( Settings_Repository::OPTION_KEY, array( 'booking_reminder_hours' => array( 24 ) ) );

		$booking_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		// 予約開始はサイトのタイムゾーン（Asia/Tokyo）の壁時計時刻で保存される。
		update_post_meta( $booking_id, '_vkbm_booking_service_start', wp_date( 'Y-m-d H:i:s', time() + $seconds_until_start ) );
		update_post_meta( $booking_id, '_vkbm_booking_service_end', wp_date( 'Y-m-d H:i:s', time() + $seconds_until_start + HOUR_IN_SECONDS ) );
		update_post_meta( $booking_id, '_vkbm_booking_status', 'confirmed' );
		update_post_meta( $booking_id, '_vkbm_booking_customer_email', 'customer@example.com' );
		update_post_meta( $booking_id, '_vkbm_booking_customer_name', 'テスト太郎' );

		$mail_count = 0;
		$filter     = static function () use ( &$mail_count ) {
			++$mail_count;
			return true;
		};
		add_filter( 'pre_wp_mail', $filter );

		try {
			$service = new Booking_Notification_Service( new Settings_Repository() );
			$service->handle_reminders();
		} finally {
			remove_filter( 'pre_wp_mail', $filter );
		}

		$sent = get_post_meta( $booking_id, '_vkbm_booking_reminder_sent', true );
		$this->assertSame( $expected_sent, is_array( $sent ) && 1 === count( $sent ), $test_condition_name . '（送信記録）' );
		$this->assertSame( $expected_sent ? 1 : 0, $mail_count, $test_condition_name . '（メール送信数）' );
	}

	/**
	 * @return array<int, array<int, int|string|bool>>
	 */
	public function provider_handle_reminders_timezone(): array {
		return array(
			array( '開始の24時間前ちょうどなら24時間前リマインダーを送る', 24 * HOUR_IN_SECONDS, true ),
			array( '開始の23時間59分前なら送信窓内なので送る', 24 * HOUR_IN_SECONDS - 60, true ),
			array( '開始の33時間前は送信窓の外なので送らない', 33 * HOUR_IN_SECONDS, false ),
		);
	}
}
