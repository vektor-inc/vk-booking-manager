<?php
/**
 * 予約専用の日付・月ごとの空き状況キャッシュ世代番号（Availability_Booking_Cache_Generation）のテスト。
 *
 * #417: 予約を受け付けた直後・キャンセルした直後に予約ページを見ても、空き枠の数が
 * 最大5分間（transient の有効期限）古いまま変わらない不具合の修正を検証する。
 *
 * 案1（予約を Availability_Cache_Generation::TARGET_POST_TYPES へ足すだけ）ではなく、
 * 案2（予約だけ別の扱いにし、その予約が実際に関係する日付・月だけの世代番号を進める）を
 * 採用した理由（司の decision record・issue #417 コメント参照）がそのままこのテストの
 * 核心になる: 「無関係な日付・月のキャッシュキーは変わらない」ことを必ず確認する。
 *
 * 世代番号の書き込みはリクエスト終端（shutdown）まで遅延される設計のため
 * （Availability_Cache_Generation と同じ考え方）、各テストは「予約の変更 →
 * Availability_Booking_Cache_Generation::flush_bump() を明示的に呼ぶ → 検証」の順で書く。
 *
 * 安藤レビュー対応（司の decision record 参照）: 世代番号の保存先を、1つの option へ
 * まとめた連想配列から、日付・月ごとに独立した option へ分割した（MEDIUM-1 の是正）。
 * この変更に伴い、以前の実装詳細（連想配列の丸ごと書き戻し・保存件数の上限による間引き）を
 * 検証していたテストは削除・書き換えている。代わりに「異なる日付の更新が互いに影響しない
 * こと」「形式に一致しない日付・月が option 名に使われないこと」「古い option 行の削除が
 * 1日1回だけ実行されること」を新たに検証する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Availability;

use DateTimeImmutable;
use ReflectionClass;
use VKBookingManager\Availability\Availability_Booking_Cache_Generation;
use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\PostTypes\Booking_Post_Type;
use WP_UnitTestCase;

/**
 * 予約の日付・月ごとの世代番号の管理とキャッシュキーへの反映を検証するテスト。
 *
 * @group availability
 */
class Availability_Booking_Cache_Generation_Test extends WP_UnitTestCase {

	/**
	 * 予約の開始日時メタキー（Booking_Admin / Booking_Confirmation_Controller と同じ値）。
	 *
	 * @var string
	 */
	private const META_DATE_START = '_vkbm_booking_service_start';

	/**
	 * 予約の後片付け込み終了日時メタキー。
	 *
	 * @var string
	 */
	private const META_TOTAL_END = '_vkbm_booking_total_end';

	/**
	 * 予約ステータスメタキー。
	 *
	 * @var string
	 */
	private const META_STATUS = '_vkbm_booking_status';

	/**
	 * 各テスト前に option とクラス内部の静的状態を初期化する。
	 */
	protected function setUp(): void {
		parent::setUp();
		delete_option( $this->last_pruned_option_name() );
		$this->reset_internal_state();
	}

	/**
	 * 各テスト後も同様にクリーンアップする（他テストへの汚染を防ぐ）。
	 */
	protected function tearDown(): void {
		// 未 flush の予約（$needs_flush 等）が次のテストへ持ち越されないよう、明示的に流してから消す。
		Availability_Booking_Cache_Generation::flush_bump();
		delete_option( $this->last_pruned_option_name() );
		$this->reset_internal_state();
		parent::tearDown();
	}

	/**
	 * Availability_Booking_Cache_Generation の内部静的プロパティをリフレクション経由で初期化し、
	 * shutdown フックの結線も一旦外す（テスト間の相互汚染を防ぐため）。
	 *
	 * @return void
	 */
	private function reset_internal_state(): void {
		$reflection = new ReflectionClass( Availability_Booking_Cache_Generation::class );

		$array_properties = array( 'pending_daily', 'pending_monthly', 'dirty_post_ids', 'captured_old_snapshot' );
		foreach ( $array_properties as $name ) {
			$property = $reflection->getProperty( $name );
			$property->setAccessible( true );
			$property->setValue( null, array() );
		}

		$needs_flush = $reflection->getProperty( 'needs_flush' );
		$needs_flush->setAccessible( true );
		$needs_flush->setValue( null, false );

		remove_action( 'shutdown', array( Availability_Booking_Cache_Generation::class, 'flush_bump' ), 0 );
	}

	/**
	 * bump 予約された無効化を実行する（本番の shutdown 相当）。
	 *
	 * @return void
	 */
	private function flush(): void {
		Availability_Booking_Cache_Generation::flush_bump();
	}

	/**
	 * daily の世代番号を保存する option 名をリフレクション経由で組み立てる。
	 *
	 * 日付ごとに独立した option へ分割した設計（安藤レビュー MEDIUM-1 是正）を直接検証するため、
	 * private メソッド daily_option_name() をそのまま呼び出す。
	 *
	 * @param string $date 'YYYY-MM-DD' 形式の日付。
	 * @return string
	 */
	private function daily_option_name( string $date ): string {
		$reflection = new ReflectionClass( Availability_Booking_Cache_Generation::class );
		$method     = $reflection->getMethod( 'daily_option_name' );
		$method->setAccessible( true );

		return (string) $method->invoke( null, $date );
	}

	/**
	 * monthly の世代番号を保存する option 名をリフレクション経由で組み立てる。
	 *
	 * @param string $month 'YYYY-MM' 形式の月。
	 * @return string
	 */
	private function monthly_option_name( string $month ): string {
		$reflection = new ReflectionClass( Availability_Booking_Cache_Generation::class );
		$method     = $reflection->getMethod( 'monthly_option_name' );
		$method->setAccessible( true );

		return (string) $method->invoke( null, $month );
	}

	/**
	 * 古い option 行を最後に間引いた日付を保存する option 名を、private 定数から取得する。
	 *
	 * @return string
	 */
	private function last_pruned_option_name(): string {
		$reflection = new ReflectionClass( Availability_Booking_Cache_Generation::class );

		return (string) $reflection->getConstant( 'LAST_PRUNED_OPTION_NAME' );
	}

	/**
	 * private static メソッド bump_daily_generation() をリフレクション経由で直接呼び出す。
	 *
	 * 形式が不正な日付が書き込み側でも弾かれる（option が作られない）ことを検証するために使う。
	 *
	 * @param string $date_key 'YYYY-MM-DD' 形式を想定した値（検証目的でわざと不正な値も渡す）。
	 * @return void
	 */
	private function call_bump_daily_generation( string $date_key ): void {
		$reflection = new ReflectionClass( Availability_Booking_Cache_Generation::class );
		$method     = $reflection->getMethod( 'bump_daily_generation' );
		$method->setAccessible( true );
		$method->invoke( null, $date_key );
	}

	/**
	 * private static メソッド bump_monthly_generation() をリフレクション経由で直接呼び出す。
	 *
	 * @param string $month_key 'YYYY-MM' 形式を想定した値（検証目的でわざと不正な値も渡す）。
	 * @return void
	 */
	private function call_bump_monthly_generation( string $month_key ): void {
		$reflection = new ReflectionClass( Availability_Booking_Cache_Generation::class );
		$method     = $reflection->getMethod( 'bump_monthly_generation' );
		$method->setAccessible( true );
		$method->invoke( null, $month_key );
	}

	/**
	 * private static メソッド parse_meta_datetime() をリフレクション経由で直接呼び出す。
	 *
	 * 「絶対日付＋相対表現」の合成値が拒否されること（安藤・再レビュー LOW-3 の是正）を
	 * queue_range() 経由の間接的な検証ではなく直接検証するために使う。
	 *
	 * @param string $value 検証する日時文字列。
	 * @return DateTimeImmutable|null
	 */
	private function call_parse_meta_datetime( string $value ): ?DateTimeImmutable {
		$reflection = new ReflectionClass( Availability_Booking_Cache_Generation::class );
		$method     = $reflection->getMethod( 'parse_meta_datetime' );
		$method->setAccessible( true );

		return $method->invoke( null, $value );
	}

	/**
	 * private static メソッド queue_range() をリフレクション経由で直接呼び出す。
	 *
	 * 予約の作成を経由せず、開始・終了の値だけを直接渡して無効化範囲の計算を検証するために使う。
	 *
	 * @param string $start_value     開始日時（Y-m-d H:i:s 形式想定）。
	 * @param string $total_end_value 後片付け込みの終了日時（同上）。
	 * @return void
	 */
	private function call_queue_range( string $start_value, string $total_end_value ): void {
		$reflection = new ReflectionClass( Availability_Booking_Cache_Generation::class );
		$method     = $reflection->getMethod( 'queue_range' );
		$method->setAccessible( true );
		$method->invoke( null, $start_value, $total_end_value );
	}

	/**
	 * private static プロパティ $pending_daily の件数をリフレクション経由で読み取る。
	 *
	 * queue_range() が積んだ無効化対象の件数（＝作られる daily 設定値の行数）を、
	 * 実際に option へ書き込む（flush する）前に検証するために使う。
	 *
	 * @return int
	 */
	private function get_pending_daily_count(): int {
		$reflection = new ReflectionClass( Availability_Booking_Cache_Generation::class );
		$property   = $reflection->getProperty( 'pending_daily' );
		$property->setAccessible( true );

		return count( $property->getValue() );
	}

	/**
	 * private const MAX_RANGE_DAYS の値をリフレクション経由で取得する。
	 *
	 * テスト側で上限日数をハードコードして実装側の定数とズレる（片方だけ変更されて
	 * 検証が形骸化する）ことを防ぐため、実装側の定数をそのまま参照する。
	 *
	 * @return int
	 */
	private function max_range_days(): int {
		$reflection = new ReflectionClass( Availability_Booking_Cache_Generation::class );

		return (int) $reflection->getConstant( 'MAX_RANGE_DAYS' );
	}

	/**
	 * 「今日から $days_offset 日後」の日付・日時をサイトのタイムゾーンで返す。
	 *
	 * flush_bump() は毎回「今日より前の日付・月」を option から取り除く（prune_stale_entries()）。
	 * そのため、テストで使う予約日はハードコードした固定日付ではなく、必ず「今より未来」に
	 * なるようにこのヘルパーで動的に計算する（固定日付だと、テスト実行日がその日付を
	 * 過ぎた瞬間に prune で即座に消えてしまい、テストが壊れるため）。
	 *
	 * @param int    $days_offset 今日からのオフセット日数（正の値で未来）。
	 * @param string $format      DateTimeImmutable::format() へ渡すフォーマット。
	 * @return string
	 */
	private function future_date( int $days_offset, string $format = 'Y-m-d' ): string {
		$datetime = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( sprintf( '%+d days', $days_offset ) );

		return $datetime->format( $format );
	}

	/**
	 * Availability_Service::build_cache_key() をリフレクション経由で呼び出す。
	 *
	 * @param Availability_Service $service  対象インスタンス。
	 * @param string               $prefix   'calendar' または 'daily'。
	 * @param string               $date_key calendar は 'YYYY-MM'、daily は 'YYYY-MM-DD'。
	 * @return string キャッシュキー。
	 */
	private function call_build_cache_key( Availability_Service $service, string $prefix, string $date_key ): string {
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'build_cache_key' );
		$method->setAccessible( true );

		return (string) $method->invoke( $service, $prefix, 3, array( 1, 2 ), $date_key, 'Asia/Tokyo', false );
	}

	/**
	 * テスト用の予約投稿を作成する。
	 *
	 * Booking_Confirmation_Controller と同じ順序（投稿作成 → メタを個別に update_post_meta()）で
	 * 作ることで、フロント側の予約確定と同じ経路（save_post_vkbm_booking はメタが書かれる前に
	 * 発火してしまう経路）を再現する。
	 *
	 * @param string $start     開始日時（Y-m-d H:i:s）。
	 * @param string $total_end 後片付け込み終了日時（Y-m-d H:i:s）。
	 * @param string $status    予約ステータス。
	 * @return int 作成した予約投稿ID。
	 */
	private function create_booking( string $start, string $total_end, string $status = 'confirmed' ): int {
		$booking_id = $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $booking_id, self::META_DATE_START, $start );
		update_post_meta( $booking_id, self::META_TOTAL_END, $total_end );
		update_post_meta( $booking_id, self::META_STATUS, $status );

		return $booking_id;
	}

	/**
	 * 予約の新規作成で、その予約が関係する日付・月（前後1日・その月）のキャッシュキーだけが
	 * 変わり、無関係な日付・月のキャッシュキーは変わらないことを確認する（案2の核心）。
	 */
	public function test_booking_create_bumps_generation_for_related_dates_only(): void {
		$service = new Availability_Service();

		$target_date    = $this->future_date( 10 );
		$prev_day       = $this->future_date( 9 );
		$next_day       = $this->future_date( 11 );
		$unrelated_date = $this->future_date( 60 );

		$target_month    = $this->future_date( 10, 'Y-m' );
		$unrelated_month = $this->future_date( 60, 'Y-m' );

		$key_daily_target_1 = $this->call_build_cache_key( $service, 'daily', $target_date );
		$key_daily_target_2 = $this->call_build_cache_key( $service, 'daily', $target_date );
		$this->assertSame( $key_daily_target_1, $key_daily_target_2, '前提: 世代番号が変わらなければ同じ引数で同じキャッシュキーになる' );

		$key_daily_before_target    = $key_daily_target_1;
		$key_daily_before_prevday   = $this->call_build_cache_key( $service, 'daily', $prev_day );
		$key_daily_before_nextday   = $this->call_build_cache_key( $service, 'daily', $next_day );
		$key_daily_before_unrelated = $this->call_build_cache_key( $service, 'daily', $unrelated_date );

		$key_calendar_before_target    = $this->call_build_cache_key( $service, 'calendar', $target_month );
		$key_calendar_before_unrelated = $this->call_build_cache_key( $service, 'calendar', $unrelated_month );

		// target_date 10:00〜10:30 の予約（前後1日を含めた無効化範囲は prev_day〜next_day）。
		$this->create_booking( $target_date . ' 10:00:00', $target_date . ' 10:30:00' );
		$this->flush();

		$this->assertNotSame(
			$key_daily_before_target,
			$this->call_build_cache_key( $service, 'daily', $target_date ),
			'予約日当日の daily キャッシュキーが変わる（正常系）'
		);
		$this->assertNotSame(
			$key_daily_before_prevday,
			$this->call_build_cache_key( $service, 'daily', $prev_day ),
			'前日ぶんも無効化される（深夜またぎ・後片付け時間を考慮、正常系）'
		);
		$this->assertNotSame(
			$key_daily_before_nextday,
			$this->call_build_cache_key( $service, 'daily', $next_day ),
			'翌日ぶんも無効化される（正常系）'
		);
		$this->assertSame(
			$key_daily_before_unrelated,
			$this->call_build_cache_key( $service, 'daily', $unrelated_date ),
			'無関係な日付の daily キャッシュキーは変わらない（案2の核心・本issueの完了条件）'
		);

		$this->assertNotSame(
			$key_calendar_before_target,
			$this->call_build_cache_key( $service, 'calendar', $target_month ),
			'予約月の calendar キャッシュキーが変わる（正常系）'
		);
		$this->assertSame(
			$key_calendar_before_unrelated,
			$this->call_build_cache_key( $service, 'calendar', $unrelated_month ),
			'無関係な月の calendar キャッシュキーは変わらない（案2の核心・本issueの完了条件）'
		);
	}

	/**
	 * フロント側の直接 update_post_meta() によるキャンセル（My_Bookings_Controller::cancel_booking()
	 * と同じ経路。wp_update_post() を経由しないため投稿ライフサイクルのフックが発火しない）でも、
	 * その予約の日付のキャッシュキーが変わることを確認する。
	 */
	public function test_booking_cancel_bumps_generation_for_its_own_date(): void {
		$target_date    = $this->future_date( 10 );
		$unrelated_date = $this->future_date( 60 );

		$booking_id = $this->create_booking( $target_date . ' 10:00:00', $target_date . ' 10:30:00' );
		$this->flush();

		$service              = new Availability_Service();
		$key_before           = $this->call_build_cache_key( $service, 'daily', $target_date );
		$key_unrelated_before = $this->call_build_cache_key( $service, 'daily', $unrelated_date );

		// My_Bookings_Controller::cancel_booking() と同じく update_post_meta() を直接呼ぶだけの経路。
		update_post_meta( $booking_id, self::META_STATUS, 'cancelled' );
		$this->flush();

		$this->assertNotSame(
			$key_before,
			$this->call_build_cache_key( $service, 'daily', $target_date ),
			'フロント側の直接 update_post_meta() によるキャンセルでもキャッシュキーが変わる（正常系・本issueの核心）'
		);
		$this->assertSame(
			$key_unrelated_before,
			$this->call_build_cache_key( $service, 'daily', $unrelated_date ),
			'無関係な日付は変わらない'
		);
	}

	/**
	 * 予約の完全削除でキャッシュキーが変わることを確認する。
	 *
	 * delete_post はメタが削除される前に発火するため、削除後も正しく日付を読めることの検証も兼ねる。
	 */
	public function test_booking_delete_bumps_generation_for_its_own_date(): void {
		$target_date = $this->future_date( 10 );

		$booking_id = $this->create_booking( $target_date . ' 10:00:00', $target_date . ' 10:30:00' );
		$this->flush();

		$service    = new Availability_Service();
		$key_before = $this->call_build_cache_key( $service, 'daily', $target_date );

		wp_delete_post( $booking_id, true );
		$this->flush();

		$this->assertNotSame(
			$key_before,
			$this->call_build_cache_key( $service, 'daily', $target_date ),
			'予約の完全削除でキャッシュキーが変わる（正常系）'
		);
	}

	/**
	 * 予約の日付が変更された場合、変更前・変更後の両方の日付・月のキャッシュキーが変わり、
	 * 無関係な日付は変わらないことを確認する。
	 */
	public function test_booking_date_change_bumps_both_old_and_new_dates(): void {
		// old(+10日) → new(+100日) は必ず別の月をまたぐ（90日差）。unrelated(+200日) はさらに遠い日付。
		$old_date       = $this->future_date( 10 );
		$new_date       = $this->future_date( 100 );
		$unrelated_date = $this->future_date( 200 );
		$old_month      = $this->future_date( 10, 'Y-m' );
		$new_month      = $this->future_date( 100, 'Y-m' );

		$booking_id = $this->create_booking( $old_date . ' 10:00:00', $old_date . ' 10:30:00' );
		$this->flush();

		$service = new Availability_Service();

		$key_old_before       = $this->call_build_cache_key( $service, 'daily', $old_date );
		$key_new_before       = $this->call_build_cache_key( $service, 'daily', $new_date );
		$calendar_old_before  = $this->call_build_cache_key( $service, 'calendar', $old_month );
		$calendar_new_before  = $this->call_build_cache_key( $service, 'calendar', $new_month );
		$key_unrelated_before = $this->call_build_cache_key( $service, 'daily', $unrelated_date );

		// Booking_Admin::save_post() と同じ順序（START → TOTAL_END）で日付を変更する。
		update_post_meta( $booking_id, self::META_DATE_START, $new_date . ' 15:00:00' );
		update_post_meta( $booking_id, self::META_TOTAL_END, $new_date . ' 15:30:00' );
		$this->flush();

		$this->assertNotSame(
			$key_old_before,
			$this->call_build_cache_key( $service, 'daily', $old_date ),
			'変更前の日付も無効化される（変更前の日付だけ古い表示が残るのを防ぐ・本issueの核心）'
		);
		$this->assertNotSame(
			$key_new_before,
			$this->call_build_cache_key( $service, 'daily', $new_date ),
			'変更後の日付も無効化される（本issueの核心）'
		);
		$this->assertNotSame(
			$calendar_old_before,
			$this->call_build_cache_key( $service, 'calendar', $old_month ),
			'変更前の月も無効化される'
		);
		$this->assertNotSame(
			$calendar_new_before,
			$this->call_build_cache_key( $service, 'calendar', $new_month ),
			'変更後の月も無効化される'
		);
		$this->assertSame(
			$key_unrelated_before,
			$this->call_build_cache_key( $service, 'daily', $unrelated_date ),
			'変更に関係ない日付は変わらない'
		);
	}

	/**
	 * 1リクエスト中に同じ予約へ複数回の変更（ステータスの往復・日付変更）があっても、
	 * shutdown 時点で対象日の世代番号が1回しか進まないことを確認する
	 * （Availability_Cache_Generation の $needs_bump 相当の「過剰更新の防止」）。
	 */
	public function test_multiple_changes_in_one_request_bump_generation_only_once_per_date(): void {
		$target_date = $this->future_date( 10 );

		$booking_id = $this->create_booking( $target_date . ' 10:00:00', $target_date . ' 10:30:00' );

		$this->assertSame(
			0,
			Availability_Booking_Cache_Generation::get_daily_generation( $target_date ),
			'前提: shutdown 前はまだ進んでいない'
		);

		// 同一リクエスト中に何度も保存が走る状況をシミュレートする（日付自体は同じ日にとどめる）。
		update_post_meta( $booking_id, self::META_STATUS, 'pending' );
		update_post_meta( $booking_id, self::META_STATUS, 'confirmed' );
		update_post_meta( $booking_id, self::META_DATE_START, $target_date . ' 11:00:00' );
		update_post_meta( $booking_id, self::META_TOTAL_END, $target_date . ' 11:30:00' );

		$this->flush();

		$this->assertSame(
			1,
			Availability_Booking_Cache_Generation::get_daily_generation( $target_date ),
			'1リクエスト中に何度変更があっても、対象日の世代番号は1回しか進まない（過剰更新の防止）'
		);

		// 新たな変更が無い状態で flush_bump() を呼び直しても、何も起きない。
		Availability_Booking_Cache_Generation::flush_bump();
		$this->assertSame(
			1,
			Availability_Booking_Cache_Generation::get_daily_generation( $target_date ),
			'次の変更が無ければ、改めて flush_bump() を呼んでも進まない'
		);
	}

	/**
	 * 予約の変更で、実際に WordPress コアの shutdown フックへ flush_bump() が
	 * 優先度0で登録されることを確認する（結線そのものの検証）。
	 */
	public function test_booking_create_registers_flush_bump_on_shutdown_hook(): void {
		$target_date = $this->future_date( 10 );
		$this->create_booking( $target_date . ' 10:00:00', $target_date . ' 10:30:00' );

		$priority = has_action( 'shutdown', array( Availability_Booking_Cache_Generation::class, 'flush_bump' ) );
		$this->assertSame( 0, $priority, '予約の変更で flush_bump() が shutdown フックへ優先度0で結線される（正常系）' );

		// 後始末: 実際に flush して、以降のテストへ予約状態を持ち越さない。
		$this->flush();
	}

	/**
	 * 無効化対象が何も無い状態で flush_bump() を呼んでも option への書き込みが発生しないことを確認する。
	 *
	 * $needs_flush が立っていない状態なので flush_bump() は早期 return し、
	 * 古い option 行の掃除（maybe_prune_stale_options()）すら呼ばれないことも
	 * 「最後に間引いた日付」の option が変化しないことで確認する。
	 */
	public function test_flush_bump_does_nothing_without_pending_changes(): void {
		$last_pruned_option = $this->last_pruned_option_name();
		$before             = get_option( $last_pruned_option, 'not-set' );

		Availability_Booking_Cache_Generation::flush_bump();

		$after = get_option( $last_pruned_option, 'not-set' );
		$this->assertSame( $before, $after, '無効化対象が無ければ flush_bump() は何もしない（異常系・過剰更新の防止。maybe_prune_stale_options() すら呼ばれない）' );
	}

	/**
	 * get_daily_generation() が option の保存値から世代番号を正しく読み取る／異常値を防御することを確認する。
	 *
	 * 日付ごとに独立した option（daily_option_name() が組み立てる名前）へ分割した設計
	 * （安藤レビュー MEDIUM-1 の是正）のため、対象日の option へ直接値を書き込んで検証する。
	 */
	public function test_get_daily_generation(): void {
		$date        = '2026-04-10';
		$option_name = $this->daily_option_name( $date );

		$test_cases = array(
			array(
				'test_condition_name' => 'option 未設定の場合 => 0（正常系・初期状態）',
				'stored_value'        => null,
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '数値文字列 "5" が保存されている場合 => 5（正常系）',
				'stored_value'        => '5',
				'expected'            => 5,
			),
			array(
				'test_condition_name' => '負の値が保存されている場合 => 0（異常系・防御的に0扱い）',
				'stored_value'        => -3,
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '数値でない文字列が保存されている場合 => 0（異常系・防御的に0扱い）',
				'stored_value'        => 'invalid',
				'expected'            => 0,
			),
		);

		foreach ( $test_cases as $case ) {
			delete_option( $option_name );
			if ( null !== $case['stored_value'] ) {
				update_option( $option_name, $case['stored_value'], false );
			}

			$actual = Availability_Booking_Cache_Generation::get_daily_generation( $date );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * get_monthly_generation() が option の保存値から世代番号を正しく読み取る／異常値を防御することを確認する。
	 */
	public function test_get_monthly_generation(): void {
		$month       = '2026-04';
		$option_name = $this->monthly_option_name( $month );

		$test_cases = array(
			array(
				'test_condition_name' => 'option 未設定の場合 => 0（正常系・初期状態）',
				'stored_value'        => null,
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '数値文字列 "2" が保存されている場合 => 2（正常系）',
				'stored_value'        => '2',
				'expected'            => 2,
			),
			array(
				'test_condition_name' => '負の値が保存されている場合 => 0（異常系・防御的に0扱い）',
				'stored_value'        => -1,
				'expected'            => 0,
			),
		);

		foreach ( $test_cases as $case ) {
			delete_option( $option_name );
			if ( null !== $case['stored_value'] ) {
				update_option( $option_name, $case['stored_value'], false );
			}

			$actual = Availability_Booking_Cache_Generation::get_monthly_generation( $month );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * flush_bump() が、今日・今月より前の日付・月ぶんの option 行を取り除くことを確認する
	 * （際限なく増え続けるのを防ぐための prune）。
	 */
	public function test_flush_bump_prunes_past_dates_and_months(): void {
		$past_daily_option   = $this->daily_option_name( '2000-01-01' );
		$past_monthly_option = $this->monthly_option_name( '2000-01' );

		update_option( $past_daily_option, 3, false );
		update_option( $past_monthly_option, 3, false );

		// 未来日の予約変更で flush をトリガーする。
		$target_date = $this->future_date( 10 );
		$this->create_booking( $target_date . ' 10:00:00', $target_date . ' 10:30:00' );
		$this->flush();

		$this->assertFalse( get_option( $past_daily_option, false ), '今日より前の日付の option 行は flush 時に取り除かれる' );
		$this->assertFalse( get_option( $past_monthly_option, false ), '今月より前の月の option 行は flush 時に取り除かれる' );
	}

	/**
	 * 異なる日付の更新が互いに影響しないことを確認する（安藤レビュー MEDIUM-1 の再発防止）。
	 *
	 * 以前の実装は1つの option へ連想配列をまとめて「読む→足す→丸ごと書き戻す」形だったため、
	 * ほぼ同時に別々の日付の予約を受けると、後から書き戻した側の内容だけが残り、
	 * 先に書き戻された側の変更が消えてしまう競合を持っていた。日付ごとに独立した option へ
	 * 分割した現在の設計では、別々の日付は別々の option 行を触るため、この競合が起こり得ない
	 * ことを、日付Aを複数回進めた後に日付Bだけを更新しても日付Aの世代番号が変わらないことで確認する。
	 */
	public function test_different_dates_do_not_affect_each_other(): void {
		$date_a = $this->future_date( 10 );
		$date_b = $this->future_date( 50 );

		// date_a を2回進めておく（作成 → キャンセル）。
		$booking_a = $this->create_booking( $date_a . ' 10:00:00', $date_a . ' 10:30:00' );
		$this->flush();
		update_post_meta( $booking_a, self::META_STATUS, 'cancelled' );
		$this->flush();

		$this->assertSame( 2, Availability_Booking_Cache_Generation::get_daily_generation( $date_a ), '前提: date_a は2回進んでいる' );
		$this->assertSame( 0, Availability_Booking_Cache_Generation::get_daily_generation( $date_b ), '前提: date_b はまだ変わっていない' );

		// date_b だけを更新する（date_a とは別の option 行を触るはず）。
		$this->create_booking( $date_b . ' 09:00:00', $date_b . ' 09:30:00' );
		$this->flush();

		$this->assertSame(
			2,
			Availability_Booking_Cache_Generation::get_daily_generation( $date_a ),
			'date_b の更新後も date_a の世代番号は変わらない（日付ごとに独立した option 行のため。安藤 MEDIUM-1 の再発防止）'
		);
		$this->assertSame(
			1,
			Availability_Booking_Cache_Generation::get_daily_generation( $date_b ),
			'date_b は自分の世代番号だけ進む'
		);
	}

	/**
	 * 形式に一致しない日付・月が option 名の組み立てに使われないことを確認する
	 * （安藤レビュー LOW-1 の格上げ対応）。読み取り側（get_daily_generation() /
	 * get_monthly_generation()）・書き込み側（bump_daily_generation() /
	 * bump_monthly_generation()）の両方の入口を検証する。
	 */
	public function test_invalid_date_or_month_format_is_rejected(): void {
		$invalid_daily_cases = array(
			array(
				'test_condition_name' => '月13・日45という実在しない日付',
				'value'                => '2026-13-45',
			),
			array(
				'test_condition_name' => '区切り文字がスラッシュ',
				'value'                => '2026/01/01',
			),
			array(
				'test_condition_name' => '日付の後ろに余分な文字列が付いている',
				'value'                => "2026-01-01' OR '1'='1",
			),
		);

		foreach ( $invalid_daily_cases as $case ) {
			$this->assertSame(
				0,
				Availability_Booking_Cache_Generation::get_daily_generation( $case['value'] ),
				$case['test_condition_name'] . ' は読み取り側で 0 として扱われる（異常系）'
			);

			// 書き込み側（bump_daily_generation()）でも拒否され、option が作られないことを確認する。
			$option_name = $this->daily_option_name( $case['value'] );
			delete_option( $option_name );
			$this->call_bump_daily_generation( $case['value'] );
			$this->assertFalse(
				get_option( $option_name, false ),
				$case['test_condition_name'] . ' は書き込み側でも拒否され、option が作られない'
			);
		}

		$invalid_monthly_cases = array(
			array(
				'test_condition_name' => '13月という実在しない月',
				'value'                => '2026-13',
			),
			array(
				'test_condition_name' => 'ゼロ埋めされていない月',
				'value'                => '2026-1',
			),
		);

		foreach ( $invalid_monthly_cases as $case ) {
			$this->assertSame(
				0,
				Availability_Booking_Cache_Generation::get_monthly_generation( $case['value'] ),
				$case['test_condition_name'] . ' は読み取り側で 0 として扱われる（異常系）'
			);

			$option_name = $this->monthly_option_name( $case['value'] );
			delete_option( $option_name );
			$this->call_bump_monthly_generation( $case['value'] );
			$this->assertFalse(
				get_option( $option_name, false ),
				$case['test_condition_name'] . ' は書き込み側でも拒否され、option が作られない'
			);
		}
	}

	/**
	 * 古い option 行の削除（maybe_prune_stale_options()）が1日に1回だけ実行され、
	 * 同じ日のうちの2回目の flush では実行されないことを確認する。
	 */
	public function test_stale_option_pruning_runs_at_most_once_per_day(): void {
		$stale_option = $this->daily_option_name( '2000-01-01' );

		update_option( $stale_option, 5, false );

		// 1回目の flush（未来日の予約変更でトリガー）で削除される。
		$this->create_booking( $this->future_date( 10 ) . ' 10:00:00', $this->future_date( 10 ) . ' 10:30:00' );
		$this->flush();
		$this->assertFalse( get_option( $stale_option, false ), '1回目の flush で過去日の option 行が削除される' );

		// 削除されたことを確認した状態を再現するため、同じ過去日の option をもう一度作る。
		update_option( $stale_option, 5, false );

		// 同じ日のうちにもう一度、別の予約変更で flush をトリガーする。
		$this->create_booking( $this->future_date( 20 ) . ' 09:00:00', $this->future_date( 20 ) . ' 09:30:00' );
		$this->flush();

		$this->assertSame(
			5,
			get_option( $stale_option ),
			'同じ日のうちの2回目の flush では削除処理が走らない（1日1回だけの制約。司の指示）'
		);
	}

	/**
	 * 削除対象外（今日・今月以降）の option 行が、prune 実行時に消えないことを確認する。
	 *
	 * flush をトリガーする予約日は、今月の世代番号を意図せず進めてしまわないよう、
	 * 「今月とは確実に別の月」になる日付を使う。1か月は最大でも31日のため、
	 * 実行日が今月何日であっても +60日 すれば必ず今月を跨いで別の月になる
	 * （司の差し戻し・司が実値確認済みの FAIL 修正: 以前は future_date(10) を使っており、
	 * 実行日によっては今月と同じ月になって today_month の世代番号が11→12へ進んでしまい、
	 * 「今月ぶんは削除されない」という本来の確認目的とは無関係な値のズレで失敗していた）。
	 */
	public function test_stale_option_pruning_keeps_current_and_future_entries(): void {
		$today      = current_datetime()->format( 'Y-m-d' );
		$this_month = current_datetime()->format( 'Y-m' );
		$future     = $this->future_date( 30 );

		// このテストで flush をトリガーする予約日（今月・隣接日を巻き込まないよう today/future とは別に用意する）。
		$trigger_date = $this->future_date( 60 );

		$today_option      = $this->daily_option_name( $today );
		$future_option     = $this->daily_option_name( $future );
		$this_month_option = $this->monthly_option_name( $this_month );

		update_option( $today_option, 7, false );
		update_option( $future_option, 9, false );
		update_option( $this_month_option, 11, false );

		$this->create_booking( $trigger_date . ' 10:00:00', $trigger_date . ' 10:30:00' );
		$this->flush();

		$this->assertSame( 7, get_option( $today_option ), '今日ぶんの option 行は削除されない' );
		$this->assertSame( 9, get_option( $future_option ), '未来ぶんの option 行は削除されない' );
		$this->assertSame( 11, get_option( $this_month_option ), '今月ぶんの option 行は削除されない（世代番号も進まない別月の予約で flush をトリガーしているため）' );
	}

	/**
	 * 過去日を含む予約の変更で、同一リクエスト（flush_bump() の同一呼び出し）内に進めた
	 * 世代番号が、直後の掃除（maybe_prune_stale_options()）で消されないことを確認する
	 * （安藤・再レビュー LOW-1 の是正・司の差し戻し「掃除の実行順が逆になっている」への対応）。
	 *
	 * 修正前は bump（世代番号を進める） → 掃除、の順だったため、過去日ぶんの世代番号を
	 * 1へ進めた直後にその option 行が「今日より前」の条件で削除され、次に読んだときには
	 * option 未設定＝0（初回更新前と同じ値）に戻っていた。例: 昨日の予約を「無断キャンセル」へ
	 * 変更すると、昨日・一昨日の世代番号が1へ進んだ直後に消えてしまう。
	 * 掃除 → bump の順（案A）へ入れ替えたことで、このリクエストで新しく書き込む値は
	 * 掃除の対象にならないことを検証する。
	 */
	public function test_flush_bump_does_not_erase_generation_bumped_for_past_date_in_same_flush(): void {
		// future_date() はオフセットが負の値でも「今日から $days_offset 日後」として動作するため、
		// -1 を渡せば「昨日」の日付が取れる（ヘルパー名は future_date だが汎用的に使える）。
		$yesterday  = $this->future_date( -1 );
		$day_before = $this->future_date( -2 );
		$today      = $this->future_date( 0 );

		// 昨日開催・後片付け終了の予約を作成する。無効化範囲は前後1日を含むため
		// 一昨日・昨日・今日の3日ぶんが対象になる。
		$booking_id = $this->create_booking( $yesterday . ' 10:00:00', $yesterday . ' 10:30:00' );

		// この1回の flush_bump() 呼び出しの中で、掃除（今日より前の行を削除）と
		// bump（一昨日・昨日ぶんを1へ進める書き込み）の両方が実行される。
		$this->flush();

		$this->assertSame(
			1,
			Availability_Booking_Cache_Generation::get_daily_generation( $yesterday ),
			'過去日（昨日）ぶんの世代番号が、同一flush内の掃除で消されず1のまま残る（本差し戻しの核心）'
		);
		$this->assertSame(
			1,
			Availability_Booking_Cache_Generation::get_daily_generation( $day_before ),
			'一昨日ぶん（前後1日の範囲に含まれる）も同様に消されず1のまま残る'
		);
		$this->assertSame(
			1,
			Availability_Booking_Cache_Generation::get_daily_generation( $today ),
			'今日ぶんは元々削除対象外だが、念のため正しく1へ進んでいることも確認する'
		);

		// 司の差し戻し文中のシナリオ（昨日の予約を「無断キャンセル」へ変更）も重ねて確認する。
		update_post_meta( $booking_id, self::META_STATUS, 'no_show' );
		$this->flush();

		$this->assertSame(
			2,
			Availability_Booking_Cache_Generation::get_daily_generation( $yesterday ),
			'過去日の予約をステータス変更しても、世代番号は0へ戻らずさらに進む'
		);
	}

	/**
	 * 「絶対日付＋相対表現」を連結した合成値が拒否されることを確認する
	 * （安藤・再レビュー LOW-3 の是正・司の差し戻し「日時の読み取りで末尾を固定する」への対応）。
	 *
	 * 修正前の正規表現 `/^\d{4}-\d{2}-\d{2}/` は先頭しか固定していなかったため、
	 * '2026-01-01 10:00:00 +1 year' のような値が preg_match の判定を通過し、
	 * フォールバックの DateTimeImmutable コンストラクタが「1年後」の意味で警告なしに
	 * 解釈してしまっていた（has_datetime_parse_errors() でも弾けない）。
	 */
	public function test_parse_meta_datetime_rejects_relative_expression_after_absolute_date(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '時刻付きの正しい形式 => 解釈できる（正常系）',
				'value'                => '2026-01-01 10:00:00',
				'expected_null'        => false,
			),
			array(
				'test_condition_name' => '日付のみの正しい形式（フォールバック経路） => 解釈できる（正常系）',
				'value'                => '2026-01-01',
				'expected_null'        => false,
			),
			array(
				'test_condition_name' => '時刻付き絶対日付＋相対表現の合成値 => 拒否される（異常系・本差し戻しの核心）',
				'value'                => '2026-01-01 10:00:00 +1 year',
				'expected_null'        => true,
			),
			array(
				'test_condition_name' => '日付のみ＋相対表現の合成値 => 拒否される（異常系）',
				'value'                => '2026-01-01 +3 days',
				'expected_null'        => true,
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = $this->call_parse_meta_datetime( $case['value'] );

			if ( $case['expected_null'] ) {
				$this->assertNull( $actual, $case['test_condition_name'] );
			} else {
				$this->assertInstanceOf( DateTimeImmutable::class, $actual, $case['test_condition_name'] );
			}
		}
	}

	/**
	 * 開始と終了が極端に離れた値（データ不整合を想定）でも、queue_range() が積む無効化対象の
	 * 件数が期間（MAX_RANGE_DAYS）ぶんに収まり、反復回数の安全弁（MAX_RANGE_ITERATIONS=2000）
	 * まで達しないことを確認する（安藤・再レビュー LOW-4 の追加是正・司の差し戻し
	 * 「無効化範囲の上限を、回数ではなく期間で抑える」への対応）。
	 *
	 * 修正前は回数の安全弁だけだったため、開始・終了が極端に離れた1件の不整合データで
	 * 最大2000件の設定値が作られ得た。期間でクランプすることで、この上限がずっと小さく
	 * （MAX_RANGE_DAYS + 3日。両端を含めた +1・前後1日ぶんのバッファで +2。テスト本体の
	 * コメント参照）頭打ちになることを検証する。
	 */
	public function test_queue_range_clamps_extremely_distant_range_by_period_not_iteration_count(): void {
		$start_date = $this->future_date( 10 );
		// 開始から10年後という、通常運用では起こり得ない極端な終了日（データ不整合の想定）。
		$far_future_end_date = ( new DateTimeImmutable( $start_date, wp_timezone() ) )->modify( '+10 years' )->format( 'Y-m-d' );

		$this->call_queue_range( $start_date . ' 10:00:00', $far_future_end_date . ' 10:30:00' );

		$pending_daily_count = $this->get_pending_daily_count();

		// 上限の数え方（司の差し戻し・実測 369 に合わせた是正）:
		// クランプ後の終了日は「開始日 + MAX_RANGE_DAYS 日」。開始日から終了日までの
		// 日数は、両端（開始日・終了日）を含めて数えるため MAX_RANGE_DAYS + 1 日になる
		// （例: MAX_RANGE_DAYS=2 なら 開始・+1日・+2日 の3日 = 2+1）。
		// これに前後1日ぶんのバッファ（開始日の前日・終了日の翌日）を加えると
		// MAX_RANGE_DAYS + 1 + 2 = MAX_RANGE_DAYS + 3 が実際の上限になる。
		$max_expected_by_period = $this->max_range_days() + 3;

		$this->assertGreaterThan(
			0,
			$pending_daily_count,
			'前提: 無効化対象そのものは作られている'
		);
		$this->assertLessThanOrEqual(
			$max_expected_by_period,
			$pending_daily_count,
			'開始・終了が極端に離れていても、作られる daily 設定値の行数は期間（MAX_RANGE_DAYS）ぶんに収まる（本差し戻しの核心）'
		);
		$this->assertLessThan(
			2000,
			$pending_daily_count,
			'反復回数の安全弁（MAX_RANGE_ITERATIONS=2000）に達する遥か手前で、期間のクランプにより頭打ちになる'
		);
	}
}
