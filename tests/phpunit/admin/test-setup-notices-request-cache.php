<?php
/**
 * Setup_Notices の判定結果の再利用（1 リクエスト内の保存・transient）のテスト（issue #712）.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Admin\Setup_Notices;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Shift_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_UnitTestCase;

/**
 * 同じ判定を 1 リクエスト内で重ねて行わないこと、シフトの変更で通知が正しく更新されることを検証する。
 *
 * @group admin
 */
class Setup_Notices_Request_Cache_Test extends WP_UnitTestCase {
	private const TRANSIENT = 'vkbm_setup_notice_shift_exists';

	/**
	 * 設定の読み込み回数（未設定項目の判定が走った回数の目安）.
	 *
	 * @var int
	 */
	private int $option_reads = 0;

	/**
	 * シフトの有無を調べる SQL の発行回数.
	 *
	 * @var int
	 */
	private int $shift_queries = 0;

	/**
	 * 管理者でログインし、公開済みのリソースを 1 件用意する（シフト未登録の通知が判定対象になる条件）.
	 */
	protected function setUp(): void {
		parent::setUp();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard' );
		delete_option( Settings_Repository::OPTION_KEY );
		delete_transient( self::TRANSIENT );
		self::factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		$this->option_reads  = 0;
		$this->shift_queries = 0;
		add_filter( 'option_' . Settings_Repository::OPTION_KEY, array( $this, 'count_option_read' ) );
		add_filter( 'default_option_' . Settings_Repository::OPTION_KEY, array( $this, 'count_option_read' ) );
		add_filter( 'query', array( $this, 'count_shift_query' ) );
	}

	/**
	 * フィルター・transient・画面・ユーザーを元に戻す.
	 */
	protected function tearDown(): void {
		remove_filter( 'option_' . Settings_Repository::OPTION_KEY, array( $this, 'count_option_read' ) );
		remove_filter( 'default_option_' . Settings_Repository::OPTION_KEY, array( $this, 'count_option_read' ) );
		remove_filter( 'query', array( $this, 'count_shift_query' ) );
		delete_transient( self::TRANSIENT );
		delete_option( Settings_Repository::OPTION_KEY );
		unset( $GLOBALS['current_screen'] );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * 設定の読み込みを数える.
	 *
	 * @param mixed $value 値.
	 * @return mixed
	 */
	public function count_option_read( $value ) {
		++$this->option_reads;
		return $value;
	}

	/**
	 * シフトの年月メタを調べる SQL を数える.
	 *
	 * @param string $sql SQL.
	 * @return string
	 */
	public function count_shift_query( $sql ) {
		if ( is_string( $sql ) && false !== strpos( $sql, '_vkbm_shift_year' ) && 0 === strpos( ltrim( $sql ), 'SELECT' ) ) {
			++$this->shift_queries;
		}
		return $sql;
	}

	/**
	 * 通知の HTML を取得する.
	 *
	 * @param Setup_Notices $notices 対象.
	 * @return string
	 */
	private function render( Setup_Notices $notices ): string {
		ob_start();
		$notices->render_notices();
		return (string) ob_get_clean();
	}

	/**
	 * 今月のシフトを公開で作成する.
	 *
	 * @return int 投稿 ID.
	 */
	private function create_current_month_shift(): int {
		$now = new \DateTimeImmutable( 'now', wp_timezone() );
		return self::factory()->post->create(
			array(
				'post_type'   => Shift_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'meta_input'  => array(
					'_vkbm_shift_year'  => (int) $now->format( 'Y' ),
					'_vkbm_shift_month' => (int) $now->format( 'n' ),
				),
			)
		);
	}

	/**
	 * 通知の HTML に、今月分の「シフト未登録」の行が含まれるか.
	 *
	 * @param string $html HTML.
	 * @return bool
	 */
	private function has_current_month_missing_line( string $html ): bool {
		$now = new \DateTimeImmutable( 'now', wp_timezone() );
		// translators: %d: month number.
		$line = sprintf( esc_html__( '%d Monthly shift is not registered.', 'vk-booking-manager' ), (int) $now->format( 'n' ) );

		// 「1」と「11」のように桁違いの月に一致しないよう、直前が数字でないことを条件にする.
		return 1 === preg_match( '/(?<!\d)' . preg_quote( $line, '/' ) . '/', $html );
	}

	/**
	 * 同じインスタンスで 2 回描画しても、2 回目は未設定項目・シフト未登録月の判定をやり直さない.
	 */
	public function test_second_render_does_not_repeat_checks(): void {
		$notices = new Setup_Notices();

		$first = $this->render( $notices );
		$this->assertNotSame( '', $first, '前提: 通知が出ている' );
		$reads_after_first   = $this->option_reads;
		$queries_after_first = $this->shift_queries;
		$this->assertGreaterThan( 0, $queries_after_first, '前提: 1 回目はシフトを検索する' );

		$second = $this->render( $notices );

		$this->assertSame( $first, $second );
		$this->assertSame( $reads_after_first, $this->option_reads, '2 回目は設定を読み直さない' );
		$this->assertSame( $queries_after_first, $this->shift_queries, '2 回目はシフトを検索しない' );
	}

	/**
	 * 別リクエスト（新しいインスタンス）では transient を使い、シフトを検索しない.
	 */
	public function test_new_request_uses_transient(): void {
		$this->render( new Setup_Notices() );
		$this->assertGreaterThan( 0, $this->shift_queries );
		$this->assertNotFalse( get_transient( self::TRANSIENT ) );

		$this->shift_queries = 0;
		$this->render( new Setup_Notices() );

		$this->assertSame( 0, $this->shift_queries );
	}

	/**
	 * 「シフト無し」の結果も保存される（未保存の false と区別でき、毎回検索し直さない）.
	 */
	public function test_no_shift_result_is_stored(): void {
		$this->render( new Setup_Notices() );

		$stored = get_transient( self::TRANSIENT );
		$this->assertIsArray( $stored );
		$this->assertNotEmpty( $stored );
		$this->assertSame( array( false ), array_values( array_unique( $stored ) ) );
	}

	/**
	 * シフトの保存・年月メタ変更・公開状態の変更・ゴミ箱・削除で、保存した結果が消えて通知が更新される.
	 */
	public function test_shift_changes_invalidate_stored_result(): void {
		$now = new \DateTimeImmutable( 'now', wp_timezone() );

		$test_cases = array(
			array(
				'test_condition_name'          => 'シフトを公開で保存 => 今月の未登録通知が消える',
				'mutation'                     => function (): void {
					$this->create_current_month_shift();
				},
				'expect_current_month_missing' => false,
			),
			array(
				'test_condition_name'          => '公開済みのシフトを下書きへ変更 => 今月の未登録通知が出る',
				'mutation'                     => function (): void {
					$id = $this->create_current_month_shift();
					wp_update_post(
						array(
							'ID'          => $id,
							'post_status' => 'draft',
						)
					);
				},
				'expect_current_month_missing' => true,
			),
			array(
				'test_condition_name'          => '公開済みのシフトをゴミ箱へ移動 => 今月の未登録通知が出る',
				'mutation'                     => function (): void {
					wp_trash_post( $this->create_current_month_shift() );
				},
				'expect_current_month_missing' => true,
			),
			array(
				'test_condition_name'          => 'ゴミ箱のシフトを復元して公開 => 今月の未登録通知が消える',
				'mutation'                     => function (): void {
					$id = $this->create_current_month_shift();
					wp_trash_post( $id );
					wp_untrash_post( $id );
					wp_publish_post( $id );
				},
				'expect_current_month_missing' => false,
			),
			array(
				'test_condition_name'          => 'シフトを完全に削除 => 今月の未登録通知が出る',
				'mutation'                     => function (): void {
					wp_delete_post( $this->create_current_month_shift(), true );
				},
				'expect_current_month_missing' => true,
			),
			array(
				'test_condition_name'          => 'シフトの年月メタを 3 か月後へ更新 => 今月の未登録通知が出る',
				'mutation'                     => function () use ( $now ): void {
					$id     = $this->create_current_month_shift();
					$target = $now->modify( '+3 months' );
					update_post_meta( $id, '_vkbm_shift_month', (int) $target->format( 'n' ) );
					update_post_meta( $id, '_vkbm_shift_year', (int) $target->format( 'Y' ) );
				},
				'expect_current_month_missing' => true,
			),
			array(
				'test_condition_name'          => 'シフトの月メタを削除 => 今月の未登録通知が出る',
				'mutation'                     => function (): void {
					delete_post_meta( $this->create_current_month_shift(), '_vkbm_shift_month' );
				},
				'expect_current_month_missing' => true,
			),
		);

		foreach ( $test_cases as $case ) {
			// 先に「シフト無し」の結果を保存した状態にする.
			delete_transient( self::TRANSIENT );
			$before = $this->render( new Setup_Notices() );
			$this->assertTrue( $this->has_current_month_missing_line( $before ), $case['test_condition_name'] . '（前提: 未登録通知が出ている）' );
			$this->assertNotFalse( get_transient( self::TRANSIENT ), $case['test_condition_name'] . '（前提: 保存されている）' );

			$case['mutation']();

			$after = $this->render( new Setup_Notices() );
			$this->assertSame( $case['expect_current_month_missing'], $this->has_current_month_missing_line( $after ), $case['test_condition_name'] );

			// 次のケースへ影響しないよう、作成したシフトを片付ける.
			$leftovers = get_posts(
				array(
					'post_type'      => Shift_Post_Type::POST_TYPE,
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			);
			foreach ( $leftovers as $leftover ) {
				wp_delete_post( (int) $leftover, true );
			}
		}
	}

	/**
	 * 複数の月を判定しても、transient への書き込みは 1 回の描画につき 1 回だけ.
	 */
	public function test_transient_is_written_once_per_render(): void {
		$writes  = 0;
		$counter = static function () use ( &$writes ): void {
			++$writes;
		};
		add_action( 'set_transient_' . self::TRANSIENT, $counter );

		try {
			$this->render( new Setup_Notices() );
		} finally {
			remove_action( 'set_transient_' . self::TRANSIENT, $counter );
		}

		$this->assertSame( 1, $writes );
	}

	/**
	 * 書き込みの間に何度消す契機が来ても、delete_transient() は 1 回だけ。書き込み後は再び消す.
	 */
	public function test_repeated_invalidation_deletes_transient_once_until_next_write(): void {
		$deletes = 0;
		$counter = static function () use ( &$deletes ): void {
			++$deletes;
		};
		add_action( 'delete_transient_' . self::TRANSIENT, $counter );

		try {
			$notices = new Setup_Notices();
			$notices->invalidate_shift_exists_cache();
			$notices->invalidate_shift_exists_cache();
			$notices->invalidate_shift_exists_cache();
			$this->assertSame( 1, $deletes, '書き込みの前は 2 回目以降を省く' );

			$this->render( $notices );
			$notices->invalidate_shift_exists_cache();
			$this->assertSame( 2, $deletes, '書き込み後は再び消す' );

			// 消した後は、インスタンスのキャッシュも破棄されて再判定される（古い結果を使わない）.
			// WP_Query の結果キャッシュがあると SQL が出ないため、キャッシュを空にしてから確かめる.
			wp_cache_flush();
			$this->shift_queries = 0;
			$this->render( $notices );
			$this->assertGreaterThan( 0, $this->shift_queries );
		} finally {
			remove_action( 'delete_transient_' . self::TRANSIENT, $counter );
		}
	}

	/**
	 * 消去を省いた場合だけ shutdown に再度の消去が予約され、実行すると transient が消える.
	 * 省いた後に別のリクエストが書き込んだ結果が、最大 5 分残らないようにするため.
	 */
	public function test_skipped_invalidation_schedules_one_more_delete_at_shutdown(): void {
		$notices  = new Setup_Notices();
		$callback = array( $notices, 'flush_shift_exists_transient' );

		$notices->invalidate_shift_exists_cache();
		$this->assertFalse( has_action( 'shutdown', $callback ), '1 件だけの保存では予約しない' );

		// 別のリクエストが、消去の後に判定結果を書き込んだ状態.
		set_transient( self::TRANSIENT, array( '2026-10' => false ), 300 );
		$notices->mark_shift_exists_transient_written();
		$notices->invalidate_shift_exists_cache();
		$this->assertFalse( has_action( 'shutdown', $callback ), '書き込みの後は省かず消すので予約しない' );
		$this->assertFalse( get_transient( self::TRANSIENT ) );

		// 書き込みを知らないまま 2 件目以降を保存する（フラグは下りない）.
		set_transient( self::TRANSIENT, array( '2026-10' => false ), 300 );
		$notices->invalidate_shift_exists_cache();
		$notices->invalidate_shift_exists_cache();
		$this->assertNotFalse( has_action( 'shutdown', $callback ), '省いた場合は shutdown に予約する' );
		$this->assertNotFalse( get_transient( self::TRANSIENT ), '予約しただけでは消えない' );

		$this->assertSame( 0, has_action( 'shutdown', $callback ) );
		$notices->flush_shift_exists_transient();
		$this->assertFalse( get_transient( self::TRANSIENT ), 'shutdown で消える' );

		remove_action( 'shutdown', $callback, 0 );
	}
}
