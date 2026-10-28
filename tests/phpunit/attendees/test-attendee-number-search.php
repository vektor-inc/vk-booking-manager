<?php
/**
 * 予約一覧の番号検索と、番号の重複警告のテスト（#931）。
 *
 * @package VKBookingManager
 */

use VKBookingManager\Attendees\Attendee_Number_Search;
use VKBookingManager\Attendees\Attendee_Roster;
use VKBookingManager\Attendees\Attendee_Roster_User_Profile;
use VKBookingManager\Bookings\Booking_List_Search;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;

/**
 * 番号の完全一致・正規化・予約に残る写しでの検索と、重複の検出を確認する。
 *
 * @group attendees
 */
class Test_Attendee_Number_Search extends WP_UnitTestCase {
	/**
	 * 家族の UUID。
	 */
	private const FAMILY_ID = '11111111-1111-4111-8111-111111111111';

	/**
	 * 名簿から削除済みの人の UUID。
	 */
	private const REMOVED_ID = '22222222-2222-4222-8222-222222222222';

	/**
	 * 検索対象。
	 *
	 * @var Booking_List_Search
	 */
	private $search;

	/**
	 * 元のメインクエリ。
	 *
	 * @var mixed
	 */
	private $main_query;

	/**
	 * 元のユーザー。
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * 管理画面・検索フック・番号機能を準備する。
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->main_query = $GLOBALS['wp_the_query'];
		$this->user_id    = get_current_user_id();
		$staff_id         = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $staff_id );
		get_userdata( $staff_id )->add_cap( Capabilities::MANAGE_RESERVATIONS );
		if ( is_multisite() ) {
			grant_super_admin( $staff_id );
		}
		set_current_screen( 'edit-vkbm_booking' );
		$this->set_settings( array() );
		Attendee_Number_Search::reset_cache();
		$this->search = new Booking_List_Search();
		$this->search->register();
	}

	/**
	 * フック・画面・ユーザーを元に戻す。
	 */
	protected function tearDown(): void {
		remove_action( 'pre_get_posts', array( $this->search, 'limit_search' ) );
		remove_filter( 'posts_search', array( $this->search, 'filter_search' ) );
		remove_filter( 'posts_where', array( $this->search, 'filter_attendee_number' ) );
		remove_action( 'admin_notices', array( $this->search, 'render_overflow_notice' ) );
		remove_action( 'admin_notices', array( $this->search, 'render_attendee_number_result' ) );
		remove_filter( 'wp_list_table_class_name', array( $this->search, 'filter_list_table_class' ) );
		unset( $_GET[ Attendee_Number_Search::QUERY_VAR ], $_GET['updated'], $_GET['user_id'] );
		$GLOBALS['wp_the_query'] = $this->main_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- テスト前のメインクエリに戻す。
		wp_set_current_user( $this->user_id );
		set_current_screen( 'front' );
		parent::tearDown();
	}

	/**
	 * 名簿・番号機能の設定を保存する。
	 *
	 * @param array<string,mixed> $overrides 上書きする設定。
	 */
	private function set_settings( array $overrides ): void {
		update_option(
			Settings_Repository::OPTION_KEY,
			array_merge(
				array(
					'attendee_roster_enabled' => true,
					'attendee_number_enabled' => true,
				),
				$overrides
			)
		);
	}

	/**
	 * 本人の番号だけを持つ名簿を保存した会員を作る。
	 *
	 * @param string                 $self_name   本人の名前。
	 * @param string                 $self_number 本人の番号。
	 * @param array<int,array>|array $others      本人以外の名簿の行。
	 * @return int ユーザー ID。
	 */
	private function create_member( string $self_name, string $self_number, array $others = array() ): int {
		$user_id = self::factory()->user->create(
			array(
				'role'         => 'subscriber',
				'display_name' => $self_name,
			)
		);
		$rows    = array_merge(
			array(
				array(
					'id'     => 'self',
					'name'   => $self_name,
					'kana'   => '',
					'number' => $self_number,
				),
			),
			$others
		);
		$this->assertTrue( ( new Attendee_Roster() )->save( $user_id, $rows ) );
		return $user_id;
	}

	/**
	 * 表示名を、WordPress の保存時のフィルターを通さずに書き換える（HTML を含む表示名のテスト用）。
	 *
	 * @param int    $user_id      ユーザー ID。
	 * @param string $display_name 表示名。
	 */
	private function set_raw_display_name( int $user_id, string $display_name ): void {
		global $wpdb;
		$wpdb->update( $wpdb->users, array( 'display_name' => $display_name ), array( 'ID' => $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_user_cache( $user_id );
	}

	/**
	 * 予約を作る。
	 *
	 * @param int                  $author 予約者。
	 * @param array<string,string> $meta   保存する予約メタ。
	 * @return int 予約 ID。
	 */
	private function create_booking( int $author, array $meta = array() ): int {
		$booking_id = self::factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_author' => $author,
			)
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $booking_id, $key, $value );
		}
		return $booking_id;
	}

	/**
	 * 予約一覧のメインクエリを再現して予約 ID を返す。
	 *
	 * @param array<string,mixed> $args 追加のクエリ条件。
	 * @return int[] 昇順の予約 ID。
	 */
	private function search_ids( array $args ): array {
		// 番号は検索欄の入力（リクエスト）として渡す。データを書き換えたテストのため、覚えた照合結果も捨てる。
		Attendee_Number_Search::reset_cache();
		unset( $_GET[ Attendee_Number_Search::QUERY_VAR ] );
		if ( isset( $args[ Attendee_Number_Search::QUERY_VAR ] ) ) {
			$_GET[ Attendee_Number_Search::QUERY_VAR ] = $args[ Attendee_Number_Search::QUERY_VAR ];
			unset( $args[ Attendee_Number_Search::QUERY_VAR ] );
		}
		$query                   = new WP_Query();
		$GLOBALS['wp_the_query'] = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- 一覧のメインクエリを再現する。
		$ids                     = $query->query(
			array_merge(
				array(
					'post_type'      => Booking_Post_Type::POST_TYPE,
					'posts_per_page' => 100,
					'fields'         => 'ids',
				),
				$args
			)
		);
		$ids                     = array_map( 'intval', $ids );
		sort( $ids );
		return $ids;
	}

	/**
	 * 番号の完全一致・正規化・予約の写し・名簿の今の番号で、期待どおりの予約だけが見つかる。
	 */
	public function test_search_by_number(): void {
		$owner1 = $this->create_member(
			'山田',
			'00123',
			array(
				array(
					'id'     => self::FAMILY_ID,
					'name'   => '花子',
					'kana'   => '',
					'number' => 'ABC-9',
				),
			)
		);
		$owner2 = $this->create_member( '佐藤', '00123' );

		$bookings = array(
			'self_copy'      => $this->create_booking(
				$owner1,
				array(
					'_vkbm_booking_attendee_id'     => 'self',
					'_vkbm_booking_attendee_name'   => '山田',
					'_vkbm_booking_attendee_number' => '00123',
				)
			),
			'self_legacy'    => $this->create_booking( $owner1 ),
			'family_copy'    => $this->create_booking(
				$owner1,
				array(
					'_vkbm_booking_attendee_id'     => self::FAMILY_ID,
					'_vkbm_booking_attendee_name'   => '花子',
					'_vkbm_booking_attendee_number' => 'ABC-9',
				)
			),
			'family_no_copy' => $this->create_booking(
				$owner1,
				array(
					'_vkbm_booking_attendee_id'   => self::FAMILY_ID,
					'_vkbm_booking_attendee_name' => '花子',
				)
			),
			'family_changed' => $this->create_booking(
				$owner1,
				array(
					'_vkbm_booking_attendee_id'     => self::FAMILY_ID,
					'_vkbm_booking_attendee_name'   => '花子',
					'_vkbm_booking_attendee_number' => 'OLD-9',
				)
			),
			'removed'        => $this->create_booking(
				$owner1,
				array(
					'_vkbm_booking_attendee_id'     => self::REMOVED_ID,
					'_vkbm_booking_attendee_name'   => '太郎',
					'_vkbm_booking_attendee_number' => 'DEL-5',
				)
			),
			'manual_no_copy' => $this->create_booking(
				$owner1,
				array(
					'_vkbm_booking_attendee_id'   => 'manual',
					'_vkbm_booking_attendee_name' => '手入力',
				)
			),
			'anonymized'     => $this->create_booking(
				0,
				array(
					'_vkbm_booking_attendee_id'     => '',
					'_vkbm_booking_attendee_name'   => '',
					'_vkbm_booking_attendee_number' => '',
				)
			),
			'other_owner'    => $this->create_booking(
				$owner2,
				array(
					'_vkbm_booking_attendee_id'     => 'self',
					'_vkbm_booking_attendee_name'   => '佐藤',
					'_vkbm_booking_attendee_number' => '００１２３',
				)
			),
		);

		$test_cases = array(
			array(
				'test_condition_name' => '同じ番号 => 番号が一致した全員（同じ番号が複数人でも全員）の予約が見つかる',
				'conditions'          => array( 'number' => '00123' ),
				'expected'            => array( 'self_copy', 'self_legacy', 'other_owner' ),
			),
			array(
				'test_condition_name' => '全角数字・前後の空白 => 正規化して一致する（予約の写しが全角でも一致する）',
				'conditions'          => array( 'number' => '  ００１２３ ' ),
				'expected'            => array( 'self_copy', 'self_legacy', 'other_owner' ),
			),
			array(
				'test_condition_name' => '番号の一部だけ => 完全一致のため見つからない',
				'conditions'          => array( 'number' => '0012' ),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '全角英字・小文字 => 英字の大小と全半角をそろえて一致する',
				'conditions'          => array( 'number' => 'ａｂｃ-9' ),
				'expected'            => array( 'family_copy', 'family_no_copy' ),
			),
			array(
				'test_condition_name' => '名簿から消された人の番号 => 予約に残る写しで見つかる',
				'conditions'          => array( 'number' => 'DEL-5' ),
				'expected'            => array( 'removed' ),
			),
			array(
				'test_condition_name' => '名簿の番号を後から変えた人の古い番号 => 写しのある予約だけが見つかる',
				'conditions'          => array( 'number' => 'OLD-9' ),
				'expected'            => array( 'family_changed' ),
			),
			array(
				'test_condition_name' => 'どこにも無い番号 => 1件も見つからない',
				'conditions'          => array( 'number' => 'ZZZ' ),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '番号と検索語の両方 => 両方に一致する予約だけが見つかる',
				'conditions'          => array(
					'number' => '00123',
					's'      => '佐藤',
				),
				'expected'            => array( 'other_owner' ),
			),
			array(
				'test_condition_name' => '番号と一致しない検索語 => 見つからない',
				'conditions'          => array(
					'number' => '00123',
					's'      => '花子',
				),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '空白だけの番号 => 絞り込まない',
				'conditions'          => array( 'number' => '   ' ),
				'expected'            => array_keys( $bookings ),
			),
		);

		foreach ( $test_cases as $case ) {
			$args = array( Attendee_Number_Search::QUERY_VAR => $case['conditions']['number'] );
			if ( isset( $case['conditions']['s'] ) ) {
				$args['s'] = wp_slash( $case['conditions']['s'] );
			}
			$expected = array_map(
				static function ( string $key ) use ( $bookings ): int {
					return $bookings[ $key ];
				},
				$case['expected']
			);
			sort( $expected );

			$this->assertSame( $expected, $this->search_ids( $args ), $case['test_condition_name'] );
		}
	}

	/**
	 * 番号機能がオフの施設では、番号の指定があっても絞り込まない。
	 */
	public function test_number_filter_is_ignored_when_feature_is_off(): void {
		$owner   = $this->create_member( '山田', '00123' );
		$booking = $this->create_booking( $owner, array( '_vkbm_booking_attendee_number' => '00123' ) );
		$other   = $this->create_booking( $owner, array( '_vkbm_booking_attendee_number' => '99999' ) );

		$this->assertSame( array( $booking ), $this->search_ids( array( Attendee_Number_Search::QUERY_VAR => '00123' ) ), '機能がオンなら絞り込む' );

		$this->set_settings( array( 'attendee_number_enabled' => false ) );
		$this->assertSame( array( $booking, $other ), $this->search_ids( array( Attendee_Number_Search::QUERY_VAR => '00123' ) ), '機能がオフなら絞り込まない' );
	}

	/**
	 * 一致した人の一覧は、番号が同じ人を全員出し、名簿に無い人は予約の写しから出す。
	 */
	public function test_find_people(): void {
		$owner1 = $this->create_member( '山田', '00123' );
		$this->create_member( '佐藤', '００１２３' );
		$this->create_booking(
			$owner1,
			array(
				'_vkbm_booking_attendee_id'     => self::REMOVED_ID,
				'_vkbm_booking_attendee_name'   => '太郎',
				'_vkbm_booking_attendee_number' => 'DEL-5',
			)
		);
		$search = new Attendee_Number_Search();

		$names = wp_list_pluck( $search->find_people( '00123' ), 'name' );
		sort( $names );
		$expected = array( '佐藤', '山田' );
		sort( $expected );
		$this->assertSame( $expected, $names, '同じ番号の人は全員出す' );

		$removed = $search->find_people( 'del-5' );
		$this->assertCount( 1, $removed );
		$this->assertSame( '太郎', $removed[0]['name'] );
		$this->assertFalse( $removed[0]['in_roster'], '名簿に無い人は写しから出す' );

		$this->assertSame( array(), $search->find_people( 'zzz' ) );
	}

	/**
	 * 一致した人を一覧の上に表示し、番号機能がオフなら何も出さない。
	 */
	public function test_render_attendee_number_result(): void {
		$owner = $this->create_member( '山田', '00123' );
		$this->set_settings( array( 'attendee_number_label' => '診察券番号' ) );
		$_GET[ Attendee_Number_Search::QUERY_VAR ] = '００１２３';

		ob_start();
		$this->search->render_attendee_number_result();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '診察券番号', $html, '見出しは番号のラベルを使う' );
		$this->assertStringContainsString( '山田（00123）', $html, '一致した人の名前と番号を出す' );
		$this->assertStringContainsString( 'user_id=' . $owner, $html, '名簿のある会員の編集画面へのリンクを出す' );

		$_GET[ Attendee_Number_Search::QUERY_VAR ] = 'zzz';
		ob_start();
		$this->search->render_attendee_number_result();
		$this->assertStringContainsString( __( 'No matching reservations were found.', 'vk-booking-manager' ), (string) ob_get_clean(), '本当に一致が無ければその旨を出す' );

		$this->set_settings( array( 'attendee_number_enabled' => false ) );
		ob_start();
		$this->search->render_attendee_number_result();
		$this->assertSame( '', (string) ob_get_clean(), '番号機能がオフなら何も出さない' );
	}

	/**
	 * 番号の欄は、番号機能がオンの施設だけ、見出しと入力値つきで検索欄の左に出る。
	 */
	public function test_search_box_number_field(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-posts-list-table.php';
		$table                           = _get_list_table( 'WP_Posts_List_Table', array( 'screen' => get_current_screen() ) );
		$original_query                  = $GLOBALS['wp_query'];
		$GLOBALS['wp_query']             = new WP_Query(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- 一覧の表示件数を再現する。
		$GLOBALS['wp_query']->post_count = 1;
		try {
			$render = function () use ( $table ): string {
				ob_start();
				$table->search_box( 'Search', 'post' );
				return (string) ob_get_clean();
			};

			$html = $render();
			$this->assertStringContainsString( 'name="' . Attendee_Number_Search::QUERY_VAR . '"', $html, '番号機能がオンなら番号の欄がある' );
			$this->assertStringContainsString( __( 'Customer number', 'vk-booking-manager' ), $html, '見出しが空なら既定の語を使う' );
			$this->assertStringContainsString( 'aria-describedby="vkbm-attendee-number-search-help"', $html, '番号の欄を説明文と関連付ける' );
			$this->assertStringContainsString( 'id="vkbm-attendee-number-search-help"', $html, '番号の欄の説明を出す' );
			$this->assertLessThan( strpos( $html, 'id="post-search-input"' ), strpos( $html, 'name="' . Attendee_Number_Search::QUERY_VAR . '"' ), '番号の欄は既存の検索欄より前（左）に置く' );

			$this->set_settings( array( 'attendee_number_label' => '診察券番号' ) );
			$_GET[ Attendee_Number_Search::QUERY_VAR ] = '00123';
			$html                                      = $render();
			$this->assertStringContainsString( '診察券番号', $html );
			$this->assertStringContainsString( 'value="00123"', $html, '入力済みの番号を保つ' );

			$this->set_settings( array( 'attendee_number_enabled' => false ) );
			$html = $render();
			$this->assertStringNotContainsString( Attendee_Number_Search::QUERY_VAR, $html, '番号機能がオフなら番号の欄は出ない' );
			$this->assertStringContainsString( 'id="post-search-input"', $html, '既存の検索欄は残る' );

			// 0件で検索語が無いときは標準の検索欄が出ないため、番号の欄に検索ボタンを付ける。
			$this->set_settings( array() );
			$GLOBALS['wp_query']->post_count = 0;
			$html                            = $render();
			$this->assertStringContainsString( 'vkbm-attendee-number-search-submit', $html );
			$this->assertStringContainsString( 'id="vkbm-attendee-number-search-help"', $html, '標準の検索欄が無くても番号の欄の説明は出す' );
			$this->assertStringNotContainsString( 'id="post-search-input"', $html );
		} finally {
			$GLOBALS['wp_query'] = $original_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- テスト前のクエリに戻す。
		}
	}

	/**
	 * 入力は正規化され、長すぎる入力は切り詰められる。
	 */
	public function test_normalize_input(): void {
		$this->assertSame( 'abc-123', Attendee_Number_Search::normalize_input( ' ＡＢＣ-１２３ ' ) );
		$this->assertSame( '', Attendee_Number_Search::normalize_input( "  \t" ) );
		$this->assertSame( 100, mb_strlen( Attendee_Number_Search::normalize_input( str_repeat( '9', 500 ) ) ) );
	}

	/**
	 * 別の会員の名簿に同じ番号があれば検出し、自分自身や番号の無い行は数えない。
	 */
	public function test_find_duplicate_number_users(): void {
		$roster = new Attendee_Roster();
		$other  = $this->create_member( '佐藤', '００１２３' );
		$owner  = $this->create_member(
			'山田',
			' 00123 ',
			array(
				array(
					'id'     => self::FAMILY_ID,
					'name'   => '花子',
					'kana'   => '',
					'number' => '',
				),
			)
		);

		$duplicates = $roster->find_duplicate_number_users( $owner, $roster->get( $owner ) );
		$this->assertSame( array( '00123' ), array_keys( $duplicates ), '番号の無い行は数えない' );
		$this->assertSame( array( $other ), $duplicates['00123']['user_ids'], '自分自身は含めない' );
		$this->assertSame( '00123', $duplicates['00123']['number'], '入力された番号を返す' );

		$this->assertSame( array(), $roster->find_duplicate_number_users( $owner, array( array( 'number' => '77777' ) ) ), '重複が無ければ空' );
	}

	/**
	 * ユーザー編集画面は、重複する番号欄の下と保存後の通知に、相手会員へのリンク付きで警告する。
	 */
	public function test_user_profile_duplicate_warning(): void {
		$other   = $this->create_member( '佐藤', '00123' );
		$owner   = $this->create_member( '山田', '００１２３' );
		$user    = get_userdata( $owner );
		$profile = new Attendee_Roster_User_Profile();

		ob_start();
		$profile->render( $user );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'vkbm-attendee-number-duplicate', $html, '重複する番号欄の下に警告を出す' );
		$this->assertStringContainsString( 'user_id=' . $other, $html, '相手会員の編集画面へのリンクを出す' );

		// 保存後の通知は、更新後の画面（updated 付き）でだけ出す。
		set_current_screen( 'user-edit' );
		$_GET['user_id'] = (string) $owner;
		ob_start();
		$profile->render_duplicate_notice();
		$this->assertSame( '', (string) ob_get_clean(), '保存後でなければ通知を出さない' );

		$_GET['updated'] = '1';
		ob_start();
		$profile->render_duplicate_notice();
		$notice = (string) ob_get_clean();
		$this->assertStringContainsString( 'notice-warning', $notice );
		$this->assertStringContainsString( 'role="alert"', $notice, '読み上げに通知する' );
		$this->assertStringContainsString( 'user_id=' . $other, $notice );

		// 重複が解消したら、欄の下も通知も出さない。
		$changed_rows = array(
			array(
				'id'     => 'self',
				'name'   => '佐藤',
				'kana'   => '',
				'number' => '55555',
			),
		);
		$this->assertTrue( ( new Attendee_Roster() )->save( $other, $changed_rows ) );
		ob_start();
		$profile->render_duplicate_notice();
		$this->assertSame( '', (string) ob_get_clean(), '解消後は通知を出さない' );
		ob_start();
		$profile->render( $user );
		$this->assertStringNotContainsString( 'vkbm-attendee-number-duplicate', (string) ob_get_clean(), '解消後は欄の下の警告も出さない' );
	}

	/**
	 * 配列などの不正な入力は空として扱い、前後の空白と長さを整える。
	 */
	public function test_get_requested_input(): void {
		$key = Attendee_Number_Search::QUERY_VAR;

		$_GET[ $key ] = array( '00123' );
		$this->assertSame( '', Attendee_Number_Search::get_requested_input(), '配列は空文字' );

		$_GET[ $key ] = wp_slash( '  00123  ' );
		$this->assertSame( '00123', Attendee_Number_Search::get_requested_input() );

		$_GET[ $key ] = str_repeat( '9', 500 );
		$this->assertSame( 100, mb_strlen( Attendee_Number_Search::get_requested_input() ), '長さを制限する' );

		unset( $_GET[ $key ] );
		$this->assertSame( '', Attendee_Number_Search::get_requested_input() );
	}

	/**
	 * 一致した人の表示は、名簿の名前が会員名と違うときだけ会員名を添え、リンクは会員名に付ける。
	 */
	public function test_render_matched_person_member_suffix(): void {
		$family_owner                              = $this->create_member(
			'山田一郎',
			'S-1',
			array(
				array(
					'id'     => self::FAMILY_ID,
					'name'   => '山田太郎',
					'kana'   => '',
					'number' => 'F-1',
				),
			)
		);
		$_GET[ Attendee_Number_Search::QUERY_VAR ] = 'F-1';
		ob_start();
		$this->search->render_attendee_number_result();
		$html = (string) ob_get_clean();
		$this->assertMatchesRegularExpression( '/山田太郎（F-1）\s*（会員: <a href="[^"]*user_id=' . $family_owner . '[^"]*">山田一郎<\/a>）|\(member: <a href="[^"]*user_id=' . $family_owner . '[^"]*">山田一郎<\/a>\)/u', $html, '名前が違えば会員名を添え、リンクは会員名に付ける' );

		Attendee_Number_Search::reset_cache();
		$_GET[ Attendee_Number_Search::QUERY_VAR ] = 'S-1';
		ob_start();
		$this->search->render_attendee_number_result();
		$html = (string) ob_get_clean();
		// phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- 原文の書式文字列をそのまま照合する。
		$this->assertStringNotContainsString( __( '(member: %s)', 'vk-booking-manager' ), $html );
		$this->assertMatchesRegularExpression( '/<a href="[^"]*user_id=' . $family_owner . '[^"]*">山田一郎（S-1）<\/a>/u', $html, '本人の行は会員名を添えず、全体がリンク' );
	}

	/**
	 * 権限が無い会員の名簿の人は、予約の写しからも出さず、人が0人でも「見つからない」と出さない。
	 */
	public function test_people_of_unauthorized_member_are_hidden(): void {
		$owner = $this->create_member(
			'山田',
			'00123',
			array(
				array(
					'id'     => self::FAMILY_ID,
					'name'   => '花子',
					'kana'   => '',
					'number' => 'ABC-9',
				),
			)
		);
		$this->create_booking(
			$owner,
			array(
				'_vkbm_booking_attendee_id'     => self::FAMILY_ID,
				'_vkbm_booking_attendee_name'   => '花子',
				'_vkbm_booking_attendee_number' => 'ABC-9',
			)
		);
		// ユーザー編集権限の無い予約担当者として見る。
		$viewer_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_userdata( $viewer_id )->add_cap( Capabilities::MANAGE_RESERVATIONS );
		wp_set_current_user( $viewer_id );
		$this->assertFalse( current_user_can( 'edit_user', $owner ) );

		$search = new Attendee_Number_Search();
		$this->assertSame( array(), $search->find_people( 'abc-9' ), '権限の無い会員の人は、写しからも出さない' );
		$this->assertTrue( $search->has_hidden_people( 'abc-9' ) );

		$_GET[ Attendee_Number_Search::QUERY_VAR ] = 'ABC-9';
		ob_start();
		$this->search->render_attendee_number_result();
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( '<li>', $html, '人の行を出さない' );
		$this->assertStringNotContainsString( __( 'No matching reservations were found.', 'vk-booking-manager' ), $html, '権限による0人では「該当なし」と出さない' );
	}

	/**
	 * 手入力の人は名前が違えば別の人として数える。
	 */
	public function test_manual_people_with_different_names_are_listed_separately(): void {
		$owner = $this->create_member( '山田', 'S-1' );
		foreach ( array( '手入力A', '手入力B' ) as $name ) {
			$this->create_booking(
				$owner,
				array(
					'_vkbm_booking_attendee_id'     => 'manual',
					'_vkbm_booking_attendee_name'   => $name,
					'_vkbm_booking_attendee_number' => 'M-1',
				)
			);
		}
		$names = wp_list_pluck( ( new Attendee_Number_Search() )->find_people( 'm-1' ), 'name' );
		sort( $names );
		$this->assertSame( array( '手入力A', '手入力B' ), $names );
	}

	/**
	 * 番号と名前に HTML が含まれていても、一覧の表示ではエスケープされる。
	 */
	public function test_result_output_is_escaped(): void {
		// 名簿の名前はタグを除いて保存されるため、HTML を含みうる会員の表示名と予約の写しで確かめる。
		$owner = $this->create_member( '山田', '00123' );
		$this->set_raw_display_name( $owner, '<script>alert(1)</script>' );
		$this->create_booking(
			$owner,
			array(
				'_vkbm_booking_attendee_id'     => self::REMOVED_ID,
				'_vkbm_booking_attendee_name'   => '<script>alert(2)</script>',
				'_vkbm_booking_attendee_number' => '00123',
			)
		);
		$_GET[ Attendee_Number_Search::QUERY_VAR ] = '00123<script>alert(3)</script>';
		ob_start();
		$this->search->render_attendee_number_result();
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	/**
	 * 予約管理権限の無い会員の画面には重複の警告を出さず、編集できない相手会員はリンクにしない。
	 */
	public function test_duplicate_warning_respects_permissions(): void {
		$other_id = $this->create_member( '佐藤', '00123' );
		$this->set_raw_display_name( $other_id, '<script>x</script>' );
		$viewer_id = $this->create_member( '山田', '00123' );
		$viewer    = get_userdata( $viewer_id );
		$profile   = new Attendee_Roster_User_Profile();

		// 予約管理権限が無い会員（マイページ側の会員など）には出さない。
		wp_set_current_user( $viewer_id );
		ob_start();
		$profile->render( $viewer );
		$this->assertSame( '', (string) ob_get_clean(), '予約管理権限が無ければ名簿も警告も出さない' );
		set_current_screen( 'profile' );
		$_GET['updated'] = '1';
		ob_start();
		$profile->render_duplicate_notice();
		$this->assertSame( '', (string) ob_get_clean(), '予約管理権限が無ければ通知を出さない' );

		// 予約管理権限はあるが、相手会員を編集できない場合は名前だけを出し、エスケープする。
		$viewer->add_cap( Capabilities::MANAGE_RESERVATIONS );
		// 同じ ID では権限が読み直されないため、いったん外してから切り替える。
		wp_set_current_user( 0 );
		wp_set_current_user( $viewer_id );
		ob_start();
		$profile->render( $viewer );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'vkbm-attendee-number-duplicate', $html );
		$this->assertStringNotContainsString( 'user_id=', $html, '編集できない相手会員はリンクにしない' );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '&lt;script&gt;', $html, '編集できない相手会員の名前は出さない' );
		$this->assertStringContainsString( __( 'another member', 'vk-booking-manager' ), $html, '名前の代わりに「別の会員」と出す' );

		// 編集できる相手会員は、名前をエスケープしてリンクで出す。
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		get_userdata( $admin_id )->add_cap( Capabilities::MANAGE_RESERVATIONS );
		ob_start();
		$profile->render( $viewer );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'user_id=' . $other_id, $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringNotContainsString( '<script>', $html );
	}

	/**
	 * 番号を変えた人を旧番号で探すと名簿に居る人として出し、名簿から消した人は従来どおり登録されていない扱いにする。
	 */
	public function test_changed_number_person_is_treated_as_in_roster(): void {
		$owner = $this->create_member(
			'山田',
			'S-1',
			array(
				array(
					'id'     => self::FAMILY_ID,
					'name'   => '花子',
					'kana'   => '',
					'number' => 'NEW-9',
				),
			)
		);
		// 予約には旧番号が写しで残っている。名簿には新番号で居る。
		$this->create_booking(
			$owner,
			array(
				'_vkbm_booking_attendee_id'     => self::FAMILY_ID,
				'_vkbm_booking_attendee_name'   => '花子',
				'_vkbm_booking_attendee_number' => 'OLD-9',
			)
		);
		// 名簿から本当に消した人。
		$this->create_booking(
			$owner,
			array(
				'_vkbm_booking_attendee_id'     => self::REMOVED_ID,
				'_vkbm_booking_attendee_name'   => '太郎',
				'_vkbm_booking_attendee_number' => 'GONE-1',
			)
		);
		// ID の無い予約は名前で引く。
		$this->create_booking(
			$owner,
			array(
				'_vkbm_booking_attendee_name'   => '花子',
				'_vkbm_booking_attendee_number' => 'OLD-8',
			)
		);
		$search = new Attendee_Number_Search();

		$changed = $search->find_people( 'old-9' );
		$this->assertCount( 1, $changed );
		$this->assertTrue( $changed[0]['in_roster'], '名簿に居る人は名簿の人として扱う' );
		$this->assertSame( $owner, $changed[0]['user_id'] );
		$this->assertSame( 'NEW-9', $changed[0]['number'], '名簿の今の番号を出す' );

		$by_name = $search->find_people( 'old-8' );
		$this->assertCount( 1, $by_name );
		$this->assertTrue( $by_name[0]['in_roster'], 'ID が無ければ名前で名簿から引く' );

		$removed = $search->find_people( 'gone-1' );
		$this->assertCount( 1, $removed );
		$this->assertFalse( $removed[0]['in_roster'], '名簿から消した人は登録されていない扱い' );

		$_GET[ Attendee_Number_Search::QUERY_VAR ] = 'OLD-9';
		ob_start();
		$this->search->render_attendee_number_result();
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( __( '(not registered in the current saved list)', 'vk-booking-manager' ), $html );
		$this->assertStringContainsString( 'user_id=' . $owner, $html, '会員リンクを出す' );

		// 編集権限の無い会員の名簿の人は、写しだけで一致した場合も出さない。
		$viewer_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_userdata( $viewer_id )->add_cap( Capabilities::MANAGE_RESERVATIONS );
		wp_set_current_user( $viewer_id );
		$this->assertFalse( current_user_can( 'edit_user', $owner ) );
		$this->assertSame( array(), ( new Attendee_Number_Search() )->find_people( 'old-9' ), '権限の無い会員の人は出さない' );
		$this->assertTrue( ( new Attendee_Number_Search() )->has_hidden_people( 'old-9' ), '写しだけで一致した人を外したら、外した人がいると判定する' );
		ob_start();
		$this->search->render_attendee_number_result();
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( '<li>', $html );
		$this->assertStringNotContainsString( __( 'No matching reservations were found.', 'vk-booking-manager' ), $html, '権限による0人では「該当する予約はありません」と出さない' );
	}

	/**
	 * 一致した人が上限で切れたときだけ、その旨の注記を出す。
	 */
	public function test_truncation_notice_only_when_limit_reached(): void {
		$owner = $this->create_member( '山田', 'S-1' );
		$this->create_booking(
			$owner,
			array(
				'_vkbm_booking_attendee_id'     => 'manual',
				'_vkbm_booking_attendee_name'   => '手入力',
				'_vkbm_booking_attendee_number' => 'FEW-1',
			)
		);
		$notice = __( 'Only some of the matching people are shown because there are many. All matching reservations are shown in the reservation list below.', 'vk-booking-manager' );

		$search = new Attendee_Number_Search();
		$search->find_people( 'few-1' );
		$this->assertFalse( $search->is_people_truncated() );
		$_GET[ Attendee_Number_Search::QUERY_VAR ] = 'FEW-1';
		ob_start();
		$this->search->render_attendee_number_result();
		$this->assertStringNotContainsString( $notice, (string) ob_get_clean(), '上限に達していなければ注記を出さない' );

		// 人数の上限（50人）を超える。
		for ( $i = 0; $i < 51; $i++ ) {
			$this->create_booking(
				$owner,
				array(
					'_vkbm_booking_attendee_id'     => 'manual',
					'_vkbm_booking_attendee_name'   => '多数' . $i,
					'_vkbm_booking_attendee_number' => 'MANY-1',
				)
			);
		}
		$search = new Attendee_Number_Search();
		$this->assertCount( 50, $search->find_people( 'many-1' ) );
		$this->assertTrue( $search->is_people_truncated() );
		$_GET[ Attendee_Number_Search::QUERY_VAR ] = 'MANY-1';
		ob_start();
		$this->search->render_attendee_number_result();
		$this->assertStringContainsString( $notice, (string) ob_get_clean(), '上限に達したら注記を出す' );
	}
}
