<?php
/**
 * 予約一覧の検索条件と表示のテスト（#891）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use VKBookingManager\Bookings\Booking_Admin;
use VKBookingManager\Bookings\Booking_List_Search;
use VKBookingManager\Bookings\Booking_Anonymizer;
use VKBookingManager\Bookings\Reserver_Matcher;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\Resources\Resource_Delete_Guard;
use WP_Query;
use WP_UnitTestCase;

/**
 * 保存値・アカウントの検索と既存条件の組み合わせを検証する。
 *
 * @group bookings
 */
class Booking_List_Search_Test extends WP_UnitTestCase {
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
	 * 管理画面と検索フックを準備する。
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->main_query = $GLOBALS['wp_the_query'];
		$this->user_id    = get_current_user_id();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		get_userdata( get_current_user_id() )->add_cap( 'vkbm_manage_reservations' );
		set_current_screen( 'edit-vkbm_booking' );
		$this->search = new Booking_List_Search();
		$this->search->register();
	}

	/**
	 * フック・画面・ユーザーを元に戻す。
	 */
	protected function tearDown(): void {
		remove_action( 'pre_get_posts', array( $this->search, 'limit_search' ) );
		remove_filter( 'posts_search', array( $this->search, 'filter_search' ) );
		remove_action( 'admin_notices', array( $this->search, 'render_overflow_notice' ) );
		remove_filter( 'wp_list_table_class_name', array( $this->search, 'filter_list_table_class' ) );
		$GLOBALS['wp_the_query'] = $this->main_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- テスト前のメインクエリに戻す。
		wp_set_current_user( $this->user_id );
		set_current_screen( 'front' );
		parent::tearDown();
	}

	/**
	 * 予約の検索を実行する。
	 *
	 * @param string $term 検索語。
	 * @param array  $args 追加条件。
	 * @return WP_Query
	 */
	private function query( string $term, array $args = array() ): WP_Query {
		$query                   = new WP_Query();
		$GLOBALS['wp_the_query'] = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- 一覧のメインクエリを再現する。
		$query->query(
			array_merge(
				array(
					'post_type'      => Booking_Post_Type::POST_TYPE,
					's'              => wp_slash( $term ),
					'posts_per_page' => 100,
					'fields'         => 'ids',
				),
				$args
			)
		);
		return $query;
	}

	/**
	 * 保存値のある予約を作る。
	 *
	 * @param int    $author 予約者。
	 * @param string $name 保存氏名。
	 * @param string $phone 保存電話。
	 * @param string $status 投稿状態。
	 * @return int
	 */
	private function booking( int $author, string $name, string $phone = '', string $status = 'publish' ): int {
		$id = self::factory()->post->create(
			array(
				'post_type'    => Booking_Post_Type::POST_TYPE,
				'post_status'  => $status,
				'post_author'  => $author,
				'post_title'   => '旧名三郎',
				'post_content' => '',
				'post_excerpt' => '',
			)
		);
		update_post_meta( $id, '_vkbm_booking_customer_name', $name );
		update_post_meta( $id, '_vkbm_booking_customer_tel', $phone );
		update_post_meta( $id, '_vkbm_booking_customer_email', 'oya@example.com' );
		update_post_meta( $id, '_vkbm_booking_status', 'confirmed' );
		update_post_meta( $id, '_vkbm_booking_service_start', '2030-01-01 10:00:00' );
		return $id;
	}

	/**
	 * issueの検索表・AND・除外語・公開状態・重複を確認する。
	 */
	public function test_filter_search(): void {
		$parent = self::factory()->user->create(
			array(
				'user_login' => 'oyakata01',
				'last_name'  => '保護',
				'first_name' => '者',
				'user_email' => 'oya@example.com',
			)
		);
		update_user_meta( $parent, 'vkbm_kana_name', 'ほご しゃ' );
		$a1 = $this->booking( $parent, '歯科太郎', '09011112222' );
		$a2 = $this->booking( $parent, '歯科花子', '09011112222' );
		$k  = $this->booking( $parent, '保護 者', '09011112222' );
		$g  = $this->booking( $parent, 'キャンセル', '09011112222' );
		update_post_meta( $g, '_vkbm_booking_status', 'cancelled' );
		add_post_meta( $a1, '_vkbm_booking_customer_name', '歯科太郎' );
		$h = $this->booking( $parent, 'ゴミ箱', '09011112222', 'trash' );
		$f = $this->booking( 0, '', '' );
		delete_post_meta( $f, '_vkbm_booking_customer_email' );
		$staff = self::factory()->user->create(
			array(
				'user_login' => 'staffsato',
				'last_name'  => '佐藤',
				'first_name' => '受付',
			)
		);
		get_userdata( $staff )->add_cap( 'vkbm_manage_reservations' );
		$b = $this->booking( $staff, '来院一郎', '08033334444' );
		$d = $this->booking( 0, '新名三郎', '09055556666' );
		$j = $this->booking( 0, '歯科医院スタッフ', '09011119999' );
		update_post_meta( $j, '_vkbm_booking_customer_email', 'other@example.org' );
		update_post_meta( $j, '_vkbm_booking_attendee_name', '受診者すみれ' );
		$q_user = self::factory()->user->create(
			array(
				'user_login' => 'taro',
				'user_email' => 'taro@example.org',
				'last_name'  => '山田',
				'first_name' => '一',
			)
		);
		$q      = $this->booking( $q_user, '山田 一' );
		$r      = $this->booking( 0, 'taroko' );
		$normal = array( $a1, $a2, $k, $g );
		$cases  = array(
			array( '歯科太郎', array( $a1 ) ),
			array( '新名三郎', array( $d ) ),
			array( '09011112222', $normal ),
			array( '090-1111-2222', $normal ),
			array( '090 1111 2222', $normal ),
			array( '(090) 1111 2222', $normal ),
			array( '090　1111　2222', $normal ),
			array( '０９０　１１１１　２２２２', $normal ),
			array( '０９０－１１１１－２２２２', $normal ),
			array( '０９０ー１１１１‐２２２２', $normal ),
			array( '2222', $normal ),
			array( '222', array() ),
			array( '０９０１１１１２２２２', $normal ),
			array( '"(090) 1111-2222"', $normal ),
			array( 'oyakata', $normal ),
			array( '保護者', $normal ),
			array( '保護 者', $normal ),
			array( '者 保護', $normal ),
			array( 'ほごしゃ', $normal ),
			array( 'oya@example.com', array( $a1, $a2, $k, $g, $b, $d, $q, $r ) ),
			array( 'staffsato', array() ),
			array( '佐藤 受付', array() ),
			array( '来院一郎', array( $b ) ),
			array( '受診者すみれ', array( $j ) ),
			array( 'taro', array( $q, $r ) ),
			array( '山田 090', array() ),
			array( '歯科 09011112222', array( $a1, $a2 ) ),
			array( 'oyakata -歯科', array( $k, $g ) ),
			array( '%', array() ),
			array( '_', array() ),
			array( '\\', array() ),
			array( '999', array() ),
			array( '存在しない値', array() ),
		);
		foreach ( $cases as list( $term, $expected ) ) {
			$query = $this->query( $term );
			$this->assertEqualsCanonicalizing( $expected, $query->posts, $term );
			$this->assertSame( count( $expected ), (int) $query->found_posts, $term );
		}
		$this->assertSame( array( $h ), $this->query( 'oyakata', array( 'post_status' => 'trash' ) )->posts );
		$this->assertSame(
			4,
			(int) $this->query(
				'oyakata',
				array(
					'posts_per_page' => 2,
					'paged'          => 2,
				)
			)->found_posts
		);
		$this->assertCount( 2, $this->query( 'oyakata', array( 'posts_per_page' => 2 ) )->posts );
		$this->assertCount( 10, $this->query( '旧名三郎' )->posts );
	}

	/**
	 * 電話番号の結合で除外語が失われず、接頭辞の変更・無効化にも従う。
	 */
	public function test_limit_search_exclusion_prefix(): void {
		// 除外される電話番号と、通常の語分割で残る予約を用意する。
		$phone    = $this->booking( 0, '電話番号一致', '09011112222' );
		$retained = $this->booking( 0, '090', '08055556666' );
		$first    = $this->booking( 0, '1111 2222', '08077778888' );
		$cases    = array(
			array( '-', '090 -1111', array( $retained ), false ),
			array( '-', '-090 1111 2222', array( $phone, $first ), false ),
			array( '-', '-0901111 2222', array( $first ), false ),
			array( '-', '090 1111 2222', array( $phone ), true ),
			array( '-', '090-1111-2222', array( $phone ), true ),
			array( '--', '090 --1111', array( $retained ), false ),
			array( '11', '090 1111', array( $retained ), false ),
			array( '', '090 -1111 2222', array( $phone ), true ),
		);
		// 接頭辞ごとに検索結果と一語化の有無を確認し、フィルターを戻す。
		foreach ( $cases as list( $prefix, $term, $expected, $sentence ) ) {
			$filter = static function () use ( $prefix ) {
				return $prefix;
			};
			add_filter( 'wp_query_search_exclusion_prefix', $filter );
			try {
				$query = $this->query( $term );
				$this->assertEqualsCanonicalizing( $expected, $query->posts, $term );
				$this->assertSame( $sentence, (bool) $query->get( 'sentence' ), $term );
			} finally {
				remove_filter( 'wp_query_search_exclusion_prefix', $filter );
			}
		}
	}

	/**
	 * 特殊文字の実際の一致・匿名化済み・自動下書き・同名の重複を確認する。
	 */
	public function test_filter_search_saved_values(): void {
		$user = self::factory()->user->create(
			array(
				'user_login' => 'nonameuser',
				'user_email' => 'noname@example.com',
			)
		);
		$id   = $this->booking( $user, 'nonameuser', '09011112222' );
		$this->assertSame( array( $id ), $this->query( 'nonameuser' )->posts );
		$this->assertSame( 1, (int) $this->query( 'nonameuser' )->found_posts );
		foreach ( array( '%', '_', '\\', '0' ) as $term ) {
			update_post_meta( $id, '_vkbm_booking_customer_name', wp_slash( 'literal' . $term . 'value' ) );
			$this->assertSame( array( $id ), $this->query( $term )->posts, $term );
		}
		$this->booking( $user, '自動下書き専用', '', 'auto-draft' );
		$this->assertSame( array(), $this->query( '自動下書き専用' )->posts );
		update_post_meta( $id, '_vkbm_booking_service_start', '2020-01-01 10:00:00' );
		update_post_meta( $id, '_vkbm_booking_service_end', '2020-01-01 11:00:00' );
		$this->assertSame( Booking_Anonymizer::RESULT_ANONYMIZED, ( new Booking_Anonymizer() )->anonymize( $id ) );
		foreach ( array( 'nonameuser', '09011112222', 'oya@example.com' ) as $term ) {
			$this->assertNotContains( $id, $this->query( $term )->posts, $term );
		}
	}

	/**
	 * 会員が11人以上でも欠落せず、上限・権限・LIKEのエスケープが働く。
	 */
	public function test_filter_search_accounts(): void {
		$ids = array();
		for ( $i = 0; $i < 11; ++$i ) {
			$user  = self::factory()->user->create( array( 'user_login' => 'matchuser' . $i ) );
			$ids[] = $this->booking( $user, '別名' );
		}
		$this->assertEqualsCanonicalizing( $ids, $this->query( 'matchuser' )->posts );
		// 上限超過でも保存値の一致は残す。
		$saved = $this->booking( 0, 'matchuser' );
		$limit = static function () {
			return 2;
		};
		add_filter( 'vkbm_booking_search_user_limit', $limit );
		$this->assertSame( array( $saved ), $this->query( 'matchuser' )->posts );
		ob_start();
		$this->search->render_overflow_notice();
		$notice = ob_get_clean();
		$this->assertStringContainsString( 'class="notice notice-warning"', $notice );
		$this->assertStringContainsString( esc_html__( 'Account information was excluded from this search because too many reserver accounts match.', 'vk-booking-manager' ), $notice );
		set_current_screen( 'edit-post' );
		ob_start();
		$this->search->render_overflow_notice();
		$this->assertSame( '', ob_get_clean() );
		set_current_screen( 'edit-vkbm_booking' );
		remove_filter( 'vkbm_booking_search_user_limit', $limit );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( array( $saved ), $this->query( 'matchuser' )->posts );
		wp_set_current_user( $this->user_id );
		$literal = self::factory()->user->create( array( 'user_login' => 'a_b' ) );
		$other   = self::factory()->user->create( array( 'user_login' => 'axb' ) );
		$v       = $this->booking( $literal, 'noname' );
		$this->booking( $other, 'noname' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( array( $v ), $this->query( 'a_b' )->posts );
	}

	/**
	 * 並べ替え・リソース絞り込みをANDで適用し、適用範囲を限定する。
	 */
	public function test_filter_search_scope_and_filters(): void {
		// リソース絞り込みは Resource_Delete_Guard（Pro 版限定）が担う。無料版の dist にはクラスのファイルが無い。
		if ( ! class_exists( Resource_Delete_Guard::class ) ) {
			$this->markTestSkipped( 'Resource_Delete_Guard は Pro 版にのみ含まれます。' );
		}

		$one = $this->booking( 0, '検索専用', '09012345678' );
		$two = $this->booking( 0, '検索専用', '09012345678' );
		update_post_meta( $one, '_vkbm_booking_resource_id', 123 );
		update_post_meta( $two, '_vkbm_booking_resource_id', 456 );
		$admin = new Booking_Admin();
		$guard = new Resource_Delete_Guard();
		add_action( 'pre_get_posts', array( $admin, 'handle_sortable_query' ) );
		add_action( 'pre_get_posts', array( $guard, 'filter_booking_query_by_resource' ) );
		$_GET['vkbm_resource_id']         = '123';
		$_GET['vkbm_booking_active_only'] = '1';
		foreach ( array( 'vkbm_booking_datetime', 'vkbm_booking_status' ) as $orderby ) {
			$query = $this->query( '検索専用', array( 'orderby' => $orderby ) );
			$this->assertSame( array( $one ), $query->posts, $orderby );
			$this->assertSame( 1, (int) $query->found_posts );
		}
		unset( $_GET['vkbm_resource_id'], $_GET['vkbm_booking_active_only'] );
		remove_action( 'pre_get_posts', array( $admin, 'handle_sortable_query' ) );
		remove_action( 'pre_get_posts', array( $guard, 'filter_booking_query_by_resource' ) );
		$query = new WP_Query(
			array(
				'post_type' => 'vkbm_booking',
				's'         => '検索専用',
			)
		);
		$this->assertSame( 'original', $this->search->filter_search( 'original', $query ) );
		set_current_screen( 'front' );
		$this->assertSame( array(), $this->query( '検索専用' )->posts );
		set_current_screen( 'edit-vkbm_booking' );
		$this->assertSame( array(), $this->query( '検索専用', array( 'post_type' => 'post' ) )->posts );
		$query = $this->query( str_repeat( 'あ', 101 ) );
		$this->assertSame( 100, mb_strlen( $query->get( 's' ) ) );
	}

	/**
	 * 電話番号は入力を保って一語にし、名前と数字は語ごとのANDを保つ。
	 */
	public function test_limit_search(): void {
		$id    = $this->booking( 0, '山田090', '09011112222' );
		$date  = $this->booking( 0, '日付検索' );
		wp_update_post( array( 'ID' => $date, 'post_title' => '2026.10.26 15:00' ) );
		// 年だけ・月だけの予約は、日付のAND検索に混ざらない。
		$year = $this->booking( 0, '年のみ' );
		wp_update_post( array( 'ID' => $year, 'post_title' => '2026.9.26 15:00' ) );
		$month = $this->booking( 0, '月のみ' );
		wp_update_post( array( 'ID' => $month, 'post_title' => '2025.10.26 15:00' ) );
		$cases = array(
			array( '090 1111 2222', array( $id ), true ),
			array( '(090) 1111 2222', array( $id ), true ),
			array( '090　1111　2222', array( $id ), true ),
			array( '０９０　１１１１　２２２２', array( $id ), true ),
			array( '０９０－１１１１－２２２２', array( $id ), true ),
			array( '0901', array( $id ), true ),
			array( '2026 10', array( $date ), false ),
			array( '1111 2222', array( $id ), false ),
			array( '山田 090', array( $id ), false ),
			array( '山田 080', array(), false ),
			array( '090', array( $id ), false ),
			array( '()　-', array(), false ),
		);
		foreach ( $cases as list( $keyword, $expected, $sentence ) ) {
			$query = $this->query( $keyword );
			$this->assertSame( $keyword, $query->get( 's' ), $keyword );
			$this->assertSame( $expected, $query->posts, $keyword );
			$this->assertSame( $sentence, (bool) $query->get( 'sentence' ), $keyword );
		}
	}

	/**
	 * issueの一覧表示表と、権限・所属・空白・長い名前の境界を確認する。
	 */
	public function test_get_customer_column_rows(): void {
		$parent  = self::factory()->user->create_and_get(
			array(
				'last_name'  => '保護',
				'first_name' => '者',
			)
		);
		$other   = self::factory()->user->create_and_get(
			array(
				'last_name'  => '会員',
				'first_name' => '次郎',
			)
		);
		$taro    = self::factory()->user->create_and_get(
			array(
				'user_login' => 'taro',
				'last_name'  => '山田',
				'first_name' => '一',
			)
		);
		$no_name = self::factory()->user->create_and_get( array( 'user_login' => 'nonameuser' ) );
		$staff   = self::factory()->user->create_and_get();
		$staff->add_cap( 'vkbm_manage_reservations' );
		/* translators: %s: 会員の現在の名前。 */
		$account = sprintf( __( 'Booking account: %s', 'vk-booking-manager' ), '保護 者' );
		$empty   = __( 'Name not entered', 'vk-booking-manager' );
		// 各行は入力7項目と期待する行配列。省略行も配列全体で検証する。
		$cases = array(
			'A1'        => array(
				'歯科太郎',
				'09011112222',
				$parent,
				false,
				false,
				true,
				true,
				array(
					'name'    => '歯科太郎',
					'tel'     => '090-1111-2222',
					'account' => $account,
				),
			),
			'A2'        => array(
				'歯科花子',
				'09011112222',
				$parent,
				false,
				false,
				true,
				true,
				array(
					'name'    => '歯科花子',
					'tel'     => '090-1111-2222',
					'account' => $account,
				),
			),
			'K'         => array(
				'保護 者',
				'09011112222',
				$parent,
				false,
				false,
				true,
				true,
				array(
					'name' => '保護 者',
					'tel'  => '090-1111-2222',
				),
			),
			'L'         => array( 'nonameuser', '', $no_name, false, false, true, true, array( 'name' => 'nonameuser' ) ),
			'B'         => array(
				'来院一郎',
				'08033334444',
				$staff,
				true,
				false,
				true,
				true,
				array(
					'name' => '来院一郎',
					'tel'  => '080-3333-4444',
				),
			),
			'D'         => array(
				'新名三郎',
				'09055556666',
				$other,
				false,
				false,
				true,
				true,
				array(
					'name'    => '新名三郎',
					'tel'     => '090-5555-6666',
					/* translators: %s: 会員の現在の名前。 */
					'account' => sprintf( __( 'Booking account: %s', 'vk-booking-manager' ), '会員 次郎' ),
				),
			),
			'F'         => array( '（削除済み）', '09011112222', $parent, false, true, true, true, array( 'name' => __( 'Anonymized', 'vk-booking-manager' ) ) ),
			'Q'         => array( '山田 一', '', $taro, false, false, true, true, array( 'name' => '山田 一' ) ),
			'R'         => array( 'taroko', '', $staff, true, false, true, true, array( 'name' => 'taroko' ) ),
			'空白違い'      => array( ' 保　護 者　', '', $parent, false, false, true, true, array( 'name' => ' 保　護 者　' ) ),
			'会員の空名'     => array( '', '', $parent, false, false, true, true, array( 'name' => '保護 者' ) ),
			'スタッフの空名'   => array( '', '', $staff, true, false, true, true, array( 'name' => $empty ) ),
			'閲覧権限なし'    => array(
				'歯科太郎',
				'09011112222',
				$parent,
				false,
				false,
				false,
				true,
				array(
					'name' => '歯科太郎',
					'tel'  => '090-1111-2222',
				),
			),
			'別サイト会員'    => array( '顧客', '', $parent, false, false, true, false, array( 'name' => '顧客' ) ),
			'別サイト会員の空名' => array( '', '', $parent, false, false, true, false, array( 'name' => $empty ) ),
			'削除された会員'   => array( '', '', null, false, false, true, false, array( 'name' => $empty ) ),
			'存在しないユーザー' => array( '', '', new \WP_User( 0 ), false, false, true, true, array( 'name' => $empty ) ),
		);
		foreach ( $cases as $label => $case ) {
			$expected = array_pop( $case );
			$this->assertSame( $expected, Booking_Admin::get_customer_column_rows( ...$case ), $label );
		}

		// 100文字目より後の差を見落とさない。
		$long_name = str_repeat( 'あ', 101 );
		update_user_meta( $parent->ID, 'last_name', $long_name );
		update_user_meta( $parent->ID, 'first_name', '末尾' );
		$rows = Booking_Admin::get_customer_column_rows( $long_name . '別名', '', get_userdata( $parent->ID ), false, false, true, true );
		/* translators: %s: 会員の現在の名前。 */
		$this->assertSame( sprintf( __( 'Booking account: %s', 'vk-booking-manager' ), $long_name . ' 末尾' ), $rows['account'] );
	}

	/**
	 * 予約者名列の出力・匿名化と権限別案内を確認する。
	 */
	public function test_render_column_and_help(): void {
		$user  = self::factory()->user->create(
			array(
				'last_name'  => '保護',
				'first_name' => '者',
			)
		);
		$id    = $this->booking( $user, '子供', '09011112222' );
		update_post_meta( $id, '_vkbm_booking_attendee_name', '受診者すみれ' );
		$admin = new Booking_Admin();
		$this->assertSame( array( 'cb', 'title', 'vkbm_booking_reserver' ), array_slice( array_keys( $admin->register_columns( array() ) ), 0, 3 ) );
		foreach ( array( false, true ) as $staff ) {
			if ( $staff ) {
				get_userdata( $user )->add_cap( 'vkbm_manage_reservations' );
			}
			ob_start();
			$admin->render_column( 'vkbm_booking_reserver', $id );
			$output = ob_get_clean();
			$this->assertStringContainsString( '090-1111-2222', $output );
			$this->assertStringContainsString( 'vkbm-booking-customer-column__tel text-nowrap">090-1111-2222</div>', $output );
			$this->assertStringContainsString( 'vkbm-booking-customer-column__name">子供</div>', $output );
			$this->assertStringContainsString( '受診者すみれ', $output );
			$this->assertSame( $staff, Reserver_Matcher::is_staff( get_userdata( $user ) ) );
			if ( $staff ) {
				$this->assertStringNotContainsString( '保護 者', $output );
			} else {
				$this->assertStringContainsString( '保護 者', $output );
			}
		}
		get_userdata( $user )->remove_cap( 'vkbm_manage_reservations' );
		update_post_meta( $id, '_vkbm_booking_customer_name', '<b>子供</b>' );
		update_post_meta( $id, '_vkbm_booking_customer_tel', '<script>tel</script>' );
		update_user_meta( $user, 'last_name', '<b>保護</b>' );
		ob_start();
		$admin->render_column( 'vkbm_booking_reserver', $id );
		$output = ob_get_clean();
		$this->assertStringContainsString( '&lt;b&gt;子供&lt;/b&gt;', $output );
		$this->assertStringContainsString( '&lt;script&gt;tel&lt;/script&gt;', $output );
		/* translators: %s: 会員の現在の名前。 */
		$this->assertStringContainsString( esc_html( sprintf( __( 'Booking account: %s', 'vk-booking-manager' ), '<b>保護</b> 者' ) ), $output );
		$this->assertStringNotContainsString( '<b>', $output );

		update_post_meta( $id, Booking_Anonymizer::META_ANONYMIZED_AT, '2026-01-01 00:00:00' );
		ob_start();
		$admin->render_column( 'vkbm_booking_reserver', $id );
		$this->assertSame( '<div class="vkbm-booking-customer-column__name">' . esc_html__( 'Anonymized', 'vk-booking-manager' ) . '</div>', ob_get_clean() );
		ob_start();
		$this->search->render_search_help();
		$this->assertStringContainsString( 'vkbm-booking-search-help', ob_get_clean() );
	}
	/**
	 * ロールを外した予約者は検索・列の両方で会員扱いしない。
	 */
	public function test_is_site_member(): void {
		$user = self::factory()->user->create_and_get( array( 'role' => 'subscriber', 'user_login' => 'removed891', 'last_name' => '保護者' ) );
		$id   = $this->booking( $user->ID, '子供' );
		foreach ( array( 'subscriber' => true, '' => false ) as $role => $expected ) {
			$user->set_role( $role );
			$this->assertSame( $expected, Reserver_Matcher::is_site_member( $user ) );
			$this->assertSame( $expected ? array( $id ) : array(), $this->query( 'removed891' )->posts );
			ob_start();
			( new Booking_Admin() )->render_column( 'vkbm_booking_reserver', $id );
			$this->assertSame( $expected, false !== strpos( ob_get_clean(), 'vkbm-booking-customer-column__account' ) );
		}
		// ロールなしで個別権限だけが残っていても、検索と列をそろえる。
		$user->add_cap( 'read' );
		$this->assertFalse( Reserver_Matcher::is_site_member( $user ) );
		$this->assertSame( array(), Reserver_Matcher::find_user_ids( 'removed891', 10, true ) );
		$this->assertFalse( Reserver_Matcher::is_site_member( new \WP_User( 0 ) ) );
	}

	/**
	 * 案内の権限分岐と画面限定を確認する。
	 */
	public function test_render_search_help(): void {
		foreach ( array( 'administrator' => true, 'subscriber' => false ) as $role => $can_list ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
			foreach ( array( 'edit-vkbm_booking' => true, 'edit-post' => false ) as $screen => $expected_output ) {
				set_current_screen( $screen );
				ob_start();
				$this->search->render_search_help();
				$output = ob_get_clean();
				if ( $expected_output ) {
					$this->assertStringContainsString( 'id="vkbm-booking-search-help"', $output );
					$expected = $can_list ? 'Search by reservation title, name, phone number (no hyphens needed), email address, or current booking account details (username, phonetic name, etc.).' : 'Search by reservation title, name, phone number (no hyphens needed), or email address.';
					$this->assertStringContainsString( esc_html( __( $expected, 'vk-booking-manager' ) ), $output ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- 権限別の期待文言。
				} else {
					$this->assertSame( '', $output );
				}
			}
		}
	}

	/**
	 * 標準一覧だけを画面に応じて拡張し、他の一覧を置き換えない。
	 */
	public function test_filter_list_table_class(): void {
		foreach ( array( 'edit-vkbm_booking' => true, 'edit-post' => false, 'vkbm_booking' => false ) as $screen => $expected ) {
			set_current_screen( $screen );
			$args = array( 'screen' => get_current_screen() );
			$this->assertSame( $expected ? \VKBookingManager\Bookings\Booking_Posts_List_Table::class : 'WP_Posts_List_Table', $this->search->filter_list_table_class( 'WP_Posts_List_Table', $args ) );
			$this->assertSame( 'Custom_Table', $this->search->filter_list_table_class( 'Custom_Table', $args ) );
		}
		$this->assertSame( 'WP_Posts_List_Table', $this->search->filter_list_table_class( 'WP_Posts_List_Table', array() ) );
	}

	/**
	 * 検索欄の初期 HTML と0件時の絞り込みボタンを確認する。
	 */
	public function test_search_box(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-posts-list-table.php';
		$table               = _get_list_table( 'WP_Posts_List_Table', array( 'screen' => get_current_screen() ) );
		$request             = $_REQUEST;
		$original_query      = $GLOBALS['wp_query'];
		$GLOBALS['wp_query'] = new WP_Query(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- 一覧の表示件数を再現する。
		try {
			foreach ( array( array( true, '', true ), array( false, 'not-found', true ), array( false, '', false ) ) as $case ) {
				$GLOBALS['wp_query']->post_count = $case[0] ? 1 : 0;
				$_REQUEST['s']                  = $case[1];
				$_REQUEST['orderby']            = 'title';
				ob_start();
				$table->search_box( 'Search', 'post' );
				$html = ob_get_clean();
				if ( $case[2] ) {
					$this->assertMatchesRegularExpression( '/<input(?=[^>]+id="post-search-input")(?=[^>]+aria-describedby="vkbm-booking-search-help")[^>]*>/', $html );
					$this->assertMatchesRegularExpression( '/<\/p>\s*<p id="vkbm-booking-search-help"/', $html );
					$this->assertStringContainsString( 'name="orderby" value="title"', $html );
				} else {
					$this->assertSame( '', $html );
				}
			}
			// 月の候補がない0件状態で、案内が絞り込み判定に混ざらないことを確かめる。
			ob_start();
			$method = new \ReflectionMethod( $table, 'extra_tablenav' );
			$method->setAccessible( true );
			$method->invoke( $table, 'top' );
			$html = ob_get_clean();
			$this->assertStringNotContainsString( 'id="post-query-submit"', $html );
			$this->assertStringNotContainsString( 'vkbm-booking-search-help', $html );
		} finally {
			$GLOBALS['wp_query'] = $original_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- テスト前のクエリに戻す。
			$_REQUEST = $request; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- テスト前の入力へ戻す。
		}
	}

	/**
	 * 予約者変更は main の capabilities メタによる候補範囲と10件上限を保つ。
	 */
	public function test_reserver_search_candidates(): void {
		$expected = array();
		foreach ( array( 'subscriber', 'administrator', 'unregistered891', 'individual', 'empty', 'absent' ) as $kind ) {
			$user = self::factory()->user->create_and_get( array( 'role' => 'subscriber', 'user_login' => 'candidate891_' . $kind, 'display_name' => $kind ) );
			if ( in_array( $kind, array( 'unregistered891', 'individual', 'empty', 'absent' ), true ) ) {
				$user->set_role( '' );
				if ( 'unregistered891' === $kind || 'individual' === $kind ) {
					$user->add_cap( 'individual' === $kind ? 'read' : $kind );
				} elseif ( 'absent' === $kind ) {
					delete_user_meta( $user->ID, $GLOBALS['wpdb']->get_blog_prefix() . 'capabilities' );
				}
			} else {
				$user->set_role( $kind );
			}
			if ( ! in_array( $kind, array( 'empty', 'absent' ), true ) ) {
				$expected[] = $user->ID;
			}
		}
		$result = \VKBookingManager\Bookings\Reserver_Search::search( 'candidate891_' );
		$this->assertEqualsCanonicalizing( $expected, array_column( $result['users'], 'id' ) );
		$this->assertFalse( $result['has_more'] );
		// ロール未登録の候補だけでも10件と超過判定を保つ。
		for ( $index = 0; $index < 12; ++$index ) {
			$user = self::factory()->user->create_and_get( array( 'role' => '', 'user_login' => 'unknown891_' . $index ) );
			$user->add_cap( 'unregistered891' );
		}
		$result = \VKBookingManager\Bookings\Reserver_Search::search( 'unknown891_' );
		$this->assertCount( 10, $result['users'] );
		$this->assertTrue( $result['has_more'] );
		$this->assertSame( array(), Reserver_Matcher::find_user_ids( 'unknown891_', 100, true ) );
	}

}
