<?php
/**
 * 管理画面でのゲスト予約の見分け（予約一覧の3行目・絞り込み・予約編集画面の予約者欄）のテスト（#963）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use VKBookingManager\Bookings\Booking_Admin;
use VKBookingManager\Bookings\Booking_Source;
use VKBookingManager\Bookings\Guest_Booking_Admin;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_Query;
use WP_UnitTestCase;

/**
 * Guest_Booking_Admin と、Booking_Admin のゲスト予約の表示を検証する。
 *
 * @group bookings
 */
class Guest_Booking_Admin_Test extends WP_UnitTestCase {

	/**
	 * 退避した基本設定。
	 *
	 * @var mixed
	 */
	private $original_settings;

	/**
	 * 各テストの前に、設定を退避し、管理者でログインする。
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->original_settings = get_option( Settings_Repository::OPTION_KEY, false );
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * 各テストの後に、設定と絞り込みの指定を元に戻す。
	 */
	protected function tearDown(): void {
		unset( $_GET[ Guest_Booking_Admin::FILTER_QUERY_VAR ], $GLOBALS['current_screen'] );
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		parent::tearDown();
	}

	/**
	 * 予約の受付方法を設定する。
	 *
	 * @param string $mode member / guest / both。
	 */
	private function set_customer_mode( string $mode ): void {
		update_option(
			Settings_Repository::OPTION_KEY,
			array_merge( ( new Settings_Repository() )->get_default_settings(), array( Settings_Repository::KEY_BOOKING_CUSTOMER_MODE => $mode ) )
		);
	}

	/**
	 * 予約を作る。
	 *
	 * @param bool                 $guest     ゲスト予約の印を付けるか。
	 * @param int                  $author_id 投稿者（0 は予約したユーザー無し）。
	 * @param array<string,string> $meta      追加の予約メタ。
	 * @return int 予約の投稿 ID。
	 */
	private function create_booking( bool $guest, int $author_id = 0, array $meta = array() ): int {
		$booking_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_author' => $author_id,
			)
		);
		if ( $guest ) {
			update_post_meta( $booking_id, Booking_Source::META_KEY, Booking_Source::VALUE_GUEST );
		}
		foreach ( $meta as $key => $value ) {
			update_post_meta( $booking_id, $key, $value );
		}
		return $booking_id;
	}

	/**
	 * 判定がゲスト予約の印だけで決まること。
	 */
	public function test_is_guest_booking(): void {
		$cases = array(
			array(
				'test_condition_name' => 'ゲスト予約の印 => ゲスト予約',
				'booking_id'          => $this->create_booking( true ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '印が無い => ゲスト予約ではない',
				'booking_id'          => $this->create_booking( false ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '取り込み予約の印 => ゲスト予約ではない',
				'booking_id'          => $this->create_booking( false, 0, array( Booking_Source::META_KEY => 'external_ical' ) ),
				'expected'            => false,
			),
		);

		foreach ( $cases as $case ) {
			$this->assertSame( $case['expected'], Guest_Booking_Admin::is_guest_booking( $case['booking_id'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * 予約一覧の予約者名の列に、ゲスト予約だけ「ゲスト予約」の行が出ること。
	 */
	public function test_booking_list_reserver_column(): void {
		$member_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );

		$cases = array(
			array(
				'test_condition_name' => 'ゲスト予約（会員に紐付いていない） => 名前・電話・ゲスト予約',
				'booking_id'          => $this->create_booking(
					true,
					0,
					array(
						'_vkbm_booking_customer_name' => '山田 太郎',
						'_vkbm_booking_customer_tel'  => '09012345678',
					)
				),
				'contains'            => array( '山田 太郎', '090-1234-5678', '<div class="vkbm-booking-customer-column__guest">' . __( 'Guest booking', 'vk-booking-manager' ) . '</div>' ),
				'not_contains'        => array(),
			),
			array(
				'test_condition_name' => '会員の予約 => ゲスト予約の行を出さない',
				'booking_id'          => $this->create_booking( false, $member_id, array( '_vkbm_booking_customer_name' => '佐藤 花子' ) ),
				'contains'            => array( '佐藤 花子' ),
				'not_contains'        => array( __( 'Guest booking', 'vk-booking-manager' ) ),
			),
			array(
				'test_condition_name' => '印の無い予約（投稿者なし） => ゲスト予約の行を出さない',
				'booking_id'          => $this->create_booking( false, 0, array( '_vkbm_booking_customer_name' => '鈴木 一郎' ) ),
				'contains'            => array( '鈴木 一郎' ),
				'not_contains'        => array( __( 'Guest booking', 'vk-booking-manager' ) ),
			),
			array(
				'test_condition_name' => '会員に紐付け済みのゲスト予約 => 印が残るのでゲスト予約の行も出す',
				'booking_id'          => $this->create_booking( true, $member_id, array( '_vkbm_booking_customer_name' => '高橋 三郎' ) ),
				'contains'            => array( '高橋 三郎', 'vkbm-booking-customer-column__guest' ),
				'not_contains'        => array(),
			),
		);

		$admin = new Booking_Admin();
		foreach ( $cases as $case ) {
			ob_start();
			$admin->render_column( 'vkbm_booking_reserver', $case['booking_id'] );
			$html = (string) ob_get_clean();
			foreach ( $case['contains'] as $needle ) {
				$this->assertStringContainsString( $needle, $html, $case['test_condition_name'] . ' / 含む: ' . $needle );
			}
			foreach ( $case['not_contains'] as $needle ) {
				$this->assertStringNotContainsString( $needle, $html, $case['test_condition_name'] . ' / 含まない: ' . $needle );
			}
		}
	}

	/**
	 * 絞り込み「ゲスト予約のみ」が、クエリ条件でゲスト予約だけに絞ること。
	 */
	public function test_guest_filter_narrows_booking_list(): void {
		$guest_id   = $this->create_booking( true );
		$linked_id  = $this->create_booking( true, $this->factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$normal_id  = $this->create_booking( false );
		$other_id   = $this->create_booking( false, 0, array( Booking_Source::META_KEY => 'external_ical' ) );
		$all        = array( $guest_id, $linked_id, $normal_id, $other_id );
		$guest_only = array( $guest_id, $linked_id );
		sort( $all );
		sort( $guest_only );

		$cases = array(
			array(
				'test_condition_name' => '指定なし => すべて',
				'filter'              => '',
				'expected'            => $all,
			),
			array(
				'test_condition_name' => 'ゲスト予約のみ => 印が guest の予約（紐付け後も含む）だけ',
				'filter'              => Guest_Booking_Admin::FILTER_GUEST,
				'expected'            => $guest_only,
			),
			array(
				'test_condition_name' => '知らない値 => 絞り込まない',
				'filter'              => 'unexpected',
				'expected'            => $all,
			),
		);

		set_current_screen( 'edit-' . Booking_Post_Type::POST_TYPE );
		foreach ( $cases as $case ) {
			$_GET[ Guest_Booking_Admin::FILTER_QUERY_VAR ] = $case['filter'];

			// 管理画面の一覧と同じく、メインクエリとして実行する。
			$query                   = new WP_Query();
			$GLOBALS['wp_the_query'] = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- メインクエリとして実行するため差し替える。
			$ids                     = $query->query(
				array(
					'post_type'   => Booking_Post_Type::POST_TYPE,
					'post_status' => 'publish',
					'fields'      => 'ids',
					'orderby'     => 'ID',
					'order'       => 'ASC',
				)
			);
			$GLOBALS['wp_the_query'] = $GLOBALS['wp_query']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- 元のメインクエリへ戻す。

			$ids = array_map( 'intval', $ids );
			sort( $ids );
			$this->assertSame( $case['expected'], $ids, $case['test_condition_name'] );
		}
	}

	/**
	 * 「ゲスト予約のみ」の選択肢を出す条件（受付方法・ゲスト予約の有無）と、予約以外の一覧には出さないこと。
	 */
	public function test_render_source_filter_visibility(): void {
		$render = static function ( string $post_type ): string {
			ob_start();
			( new Guest_Booking_Admin() )->render_source_filter( $post_type );
			return (string) ob_get_clean();
		};

		// 受付方法が「会員のみ」で、ゲスト予約も無いサイトでは何も出さない（既存の見た目を変えない）。
		$this->set_customer_mode( Settings_Repository::BOOKING_CUSTOMER_MODE_MEMBER );
		$this->assertSame( '', trim( $render( Booking_Post_Type::POST_TYPE ) ), '会員のみでゲスト予約が無ければ出さない' );

		// 受付方法がゲストを含むなら出す。
		$this->set_customer_mode( Settings_Repository::BOOKING_CUSTOMER_MODE_BOTH );
		$html = $render( Booking_Post_Type::POST_TYPE );
		$this->assertStringContainsString( 'name="' . Guest_Booking_Admin::FILTER_QUERY_VAR . '"', $html, 'ゲストを受け付けるなら絞り込み欄を出す' );
		$this->assertStringContainsString( 'value="guest"', $html, '「ゲスト予約のみ」の選択肢を出す' );
		$this->assertStringContainsString( __( 'Guest bookings only', 'vk-booking-manager' ), $html, '選択肢の表示名' );
		$this->assertSame( '', trim( $render( 'post' ) ), '予約以外の一覧には出さない' );

		// 絞り込み中（URL に guest が付いている）なら、ゲスト予約が 0 件でも出す。
		$this->set_customer_mode( Settings_Repository::BOOKING_CUSTOMER_MODE_MEMBER );
		$_GET[ Guest_Booking_Admin::FILTER_QUERY_VAR ] = Guest_Booking_Admin::FILTER_GUEST;
		$this->assertStringContainsString( 'value="guest"', $render( Booking_Post_Type::POST_TYPE ), '絞り込み中は必ず出す' );
		$this->assertStringContainsString( "selected='selected'", $render( Booking_Post_Type::POST_TYPE ), '絞り込み中の値が選ばれている' );
		unset( $_GET[ Guest_Booking_Admin::FILTER_QUERY_VAR ] );

		// フィルターが配列以外を返しても、既定の選択肢に戻して描画が壊れない。
		$this->set_customer_mode( Settings_Repository::BOOKING_CUSTOMER_MODE_BOTH );
		$broken = static function () {
			return 'broken';
		};
		add_filter( 'vkbm_booking_source_filter_options', $broken );
		$html = $render( Booking_Post_Type::POST_TYPE );
		remove_filter( 'vkbm_booking_source_filter_options', $broken );
		$this->assertStringContainsString( __( 'All bookings', 'vk-booking-manager' ), $html, '配列以外なら既定の選択肢に戻す' );

		// 「会員のみ」に戻しても、ゲスト予約がすでにあれば出す。
		$this->set_customer_mode( Settings_Repository::BOOKING_CUSTOMER_MODE_MEMBER );
		$this->create_booking( true );
		$this->assertStringContainsString( 'value="guest"', $render( Booking_Post_Type::POST_TYPE ), '会員のみに戻してもゲスト予約があれば出す' );
	}

	/**
	 * 予約編集画面の予約者欄が、ゲスト予約の状態に応じた表示になること。
	 */
	public function test_render_meta_box_reserver_field(): void {
		$member_id = $this->factory()->user->create(
			array(
				'user_login' => 'guestadmin_member',
				'user_email' => 'guestadmin-member@example.com',
				'role'       => 'subscriber',
			)
		);

		$cases = array(
			array(
				'test_condition_name' => 'ゲスト予約（紐付け無し） => 「ゲスト予約（会員とは紐付いていません）」と選択ボタン',
				'booking_id'          => $this->create_booking( true ),
				'contains'            => array( __( 'Guest booking (not linked to a member)', 'vk-booking-manager' ), '>' . __( 'Select reserver', 'vk-booking-manager' ) . '</button>', 'name="vkbm_booking[author_id]" value="0"' ),
				'not_contains'        => array( __( 'This booking was made as a guest booking.', 'vk-booking-manager' ) ),
			),
			array(
				'test_condition_name' => 'ゲスト予約を会員に紐付け済み => 会員のメールと注記、変更ボタン',
				'booking_id'          => $this->create_booking( true, $member_id ),
				'contains'            => array( 'guestadmin-member@example.com', __( 'This booking was made as a guest booking.', 'vk-booking-manager' ), '>' . __( 'Change reserver', 'vk-booking-manager' ) . '</button>' ),
				'not_contains'        => array( __( 'Guest booking (not linked to a member)', 'vk-booking-manager' ) ),
			),
			array(
				'test_condition_name' => 'ゲスト予約でない未設定の予約 => 従来どおり「Not set」',
				'booking_id'          => $this->create_booking( false ),
				'contains'            => array( __( 'Not set', 'vk-booking-manager' ) ),
				'not_contains'        => array( __( 'Guest booking (not linked to a member)', 'vk-booking-manager' ), __( 'This booking was made as a guest booking.', 'vk-booking-manager' ) ),
			),
		);

		foreach ( $cases as $case ) {
			ob_start();
			( new Booking_Admin() )->render_meta_box( get_post( $case['booking_id'] ) );
			$html = (string) ob_get_clean();
			foreach ( $case['contains'] as $needle ) {
				$this->assertStringContainsString( $needle, $html, $case['test_condition_name'] . ' / 含む: ' . $needle );
			}
			foreach ( $case['not_contains'] as $needle ) {
				$this->assertStringNotContainsString( $needle, $html, $case['test_condition_name'] . ' / 含まない: ' . $needle );
			}
		}
	}

	/**
	 * 会員に紐付け直しても、ゲスト予約の印が残ること。
	 */
	public function test_guest_mark_remains_after_linking_to_member(): void {
		$member_id  = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		$booking_id = $this->create_booking( true );

		Booking_Source::allow_author_change(
			$booking_id,
			static function () use ( $booking_id, $member_id ) {
				return wp_update_post(
					array(
						'ID'          => $booking_id,
						'post_author' => $member_id,
					)
				);
			}
		);

		$this->assertSame( $member_id, (int) get_post_field( 'post_author', $booking_id ), '投稿者が会員に変わる' );
		$this->assertTrue( Guest_Booking_Admin::is_guest_booking( $booking_id ), '印は残る' );
	}
}
