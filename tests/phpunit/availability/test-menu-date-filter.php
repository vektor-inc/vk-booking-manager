<?php
/**
 * 絞り込み検索の日付指定（選んだ日に予約できるメニューだけを残す）のテスト。
 *
 * - Availability_Service::is_date_bookable_for_menu(): 予約画面のカレンダーで選べる日かの判定
 * - Menu_Date_Filter::filter_menu_ids(): 複数メニューの判定、スタッフ・宿泊サイトの扱い、結果のキャッシュと世代番号による無効化
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Availability;

use DateTimeImmutable;
use DateTimeZone;
use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Availability\Availability_Cache_Generation;
use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\Availability\Menu_Date_Filter;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\PostTypes\Shift_Post_Type;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Resources\Resource_Tag_Taxonomy;
use VKBookingManager\Staff\Staff_Editor;
use WP_UnitTestCase;
use function current_datetime;
use function update_option;
use function update_post_meta;

/**
 * 日付による絞り込みの判定を検証するテストクラス。
 *
 * @group availability
 * @group menu-date-filter
 */
class Menu_Date_Filter_Test extends WP_UnitTestCase {

	/**
	 * テスト前の全体設定（option）。
	 *
	 * @var mixed
	 */
	private $original_settings = false;

	/**
	 * 元のサイトタイムゾーン文字列（テスト後に復元する）。
	 *
	 * @var string
	 */
	private string $original_timezone_string = '';

	/**
	 * サイトタイムゾーンを固定し、業種プリセットを「カスタム」（時間枠のサイト）にする。
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->original_settings        = get_option( Settings_Repository::OPTION_KEY, false );
		$this->original_timezone_string = (string) get_option( 'timezone_string', '' );
		update_option( 'timezone_string', 'Asia/Tokyo' );
		$this->set_industry_preset( 'custom' );
	}

	/**
	 * 全体設定・タイムゾーン・静的キャッシュを元に戻す。
	 */
	protected function tearDown(): void {
		$_GET = array();
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		update_option( 'timezone_string', $this->original_timezone_string );
		Industry_Presets::clear_cache();
		Staff_Editor::clear_nomination_enabled_cache();
		parent::tearDown();
	}

	/**
	 * 業種プリセットを設定する（スタッフ指名は ON にする）。
	 *
	 * @param string $preset 業種プリセットのキー。
	 */
	private function set_industry_preset( string $preset ): void {
		Industry_Presets::clear_cache();
		update_option(
			Settings_Repository::OPTION_KEY,
			array_merge(
				( new Settings_Repository() )->get_default_settings(),
				array(
					'industry_preset' => $preset,
					'staff_enabled'   => true,
				)
			)
		);
		Staff_Editor::clear_nomination_enabled_cache();
	}

	/**
	 * 今日から指定日数後の日付（サイトのタイムゾーン）を返す。
	 *
	 * @param int $days 今日から何日後か（負の値は過去）。
	 * @return DateTimeImmutable その日の日時。
	 */
	private function day_from_today( int $days ): DateTimeImmutable {
		return current_datetime()->setTimezone( new DateTimeZone( 'Asia/Tokyo' ) )->modify( sprintf( '%+d days', $days ) );
	}

	/**
	 * スタッフ（リソース）を用意する。無料版は基本スタッフ1名固定のため、既存の基本スタッフを使う。
	 *
	 * @return int リソースの投稿 ID。
	 */
	private function create_staff(): int {
		if ( Pro_Upsell::is_free_edition() ) {
			$default_staff_id = Resource_Post_Type::get_default_staff_id();
			if ( $default_staff_id > 0 ) {
				return $default_staff_id;
			}
		}

		return (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * 終日（00:00〜23:59）の勤務スロットを持つシフトを、日付が属する月ごとに作る。
	 *
	 * @param int                      $staff_id     スタッフの投稿 ID。
	 * @param array<DateTimeImmutable> $dates        シフトを用意する日付（属する月ぶんのシフトを作る）。
	 * @param array<string>            $closed_dates 休業日にする日付（Y-m-d）。それ以外は勤務日。
	 */
	private function create_shifts( int $staff_id, array $dates, array $closed_dates = array() ): void {
		$months = array();
		foreach ( $dates as $date ) {
			$months[ $date->format( 'Y-m' ) ] = $date;
		}

		foreach ( $months as $month_date ) {
			$days = array();
			$last = (int) $month_date->format( 't' );
			for ( $day = 1; $day <= $last; $day++ ) {
				$date_key     = $month_date->format( 'Y-m' ) . sprintf( '-%02d', $day );
				$days[ $day ] = array(
					'status' => in_array( $date_key, $closed_dates, true ) ? 'regular_holiday' : 'open',
					'slots'  => array(
						array(
							'start' => '00:00',
							'end'   => '23:59',
						),
					),
				);
			}

			$shift_id = (int) $this->factory()->post->create(
				array(
					'post_type'   => Shift_Post_Type::POST_TYPE,
					'post_status' => 'publish',
				)
			);
			update_post_meta( $shift_id, '_vkbm_shift_resource_id', $staff_id );
			update_post_meta( $shift_id, '_vkbm_shift_year', (int) $month_date->format( 'Y' ) );
			update_post_meta( $shift_id, '_vkbm_shift_month', (int) $month_date->format( 'n' ) );
			update_post_meta( $shift_id, '_vkbm_shift_days', $days );
		}
	}

	/**
	 * サービスメニューを作る。
	 *
	 * @param int                 $staff_id 担当スタッフの投稿 ID。
	 * @param array<string,mixed> $meta     追加するメタ（キー => 値）。
	 * @return int メニューの投稿 ID。
	 */
	private function create_menu( int $staff_id, array $meta = array() ): int {
		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );
		update_post_meta( $menu_id, '_vkbm_duration_minutes', 30 );
		update_post_meta( $menu_id, '_vkbm_buffer_after_minutes', 0 );
		update_post_meta( $menu_id, '_vkbm_reservation_deadline_hours', '0' );
		// 予約受付の上限日数に左右されないよう、上限なし（0）を明示する。
		update_post_meta( $menu_id, '_vkbm_max_advance_booking_days', 0 );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $menu_id, $key, $value );
		}

		return $menu_id;
	}

	/**
	 * is_date_bookable_for_menu() が、予約画面のカレンダーで選べる日だけ true を返すことを検証する。
	 */
	public function test_is_date_bookable_for_menu(): void {
		$open_day   = $this->day_from_today( 10 );
		$closed_day = $this->day_from_today( 11 );
		$past_day   = $this->day_from_today( -1 );

		$staff_id = $this->create_staff();
		$this->create_shifts( $staff_id, array( $open_day, $closed_day, $past_day ), array( $closed_day->format( 'Y-m-d' ) ) );

		$menu_id                 = $this->create_menu( $staff_id );
		$menu_limited_id         = $this->create_menu( $staff_id, array( '_vkbm_max_advance_booking_days' => 3 ) );
		$menu_archived_id        = $this->create_menu( $staff_id, array( '_vkbm_is_archived' => '1' ) );
		$menu_online_disabled_id = $this->create_menu( $staff_id, array( '_vkbm_online_unavailable' => '1' ) );

		$test_cases = array(
			array(
				'test_condition_name' => '勤務日で枠がある日 => true（正常系）',
				'menu_id'             => $menu_id,
				'date'                => $open_day->format( 'Y-m-d' ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '休業日 => false（正常系）',
				'menu_id'             => $menu_id,
				'date'                => $closed_day->format( 'Y-m-d' ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '今日より前の日 => false（境界値）',
				'menu_id'             => $menu_id,
				'date'                => $past_day->format( 'Y-m-d' ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '予約受付の上限日数（3日）を超える日 => false',
				'menu_id'             => $menu_limited_id,
				'date'                => $open_day->format( 'Y-m-d' ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'アーカイブ済みのメニュー => false',
				'menu_id'             => $menu_archived_id,
				'date'                => $open_day->format( 'Y-m-d' ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'オンライン予約を受け付けないメニュー => false',
				'menu_id'             => $menu_online_disabled_id,
				'date'                => $open_day->format( 'Y-m-d' ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '存在しないメニュー => false（異常系）',
				'menu_id'             => 999999,
				'date'                => $open_day->format( 'Y-m-d' ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '日付の形式が不正 => false（異常系）',
				'menu_id'             => $menu_id,
				'date'                => '2026/10/18',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '実在しない日付 => false（異常系）',
				'menu_id'             => $menu_id,
				'date'                => '2026-02-31',
				'expected'            => false,
			),
		);

		$service = new Availability_Service();
		foreach ( $test_cases as $case ) {
			$this->assertSame(
				$case['expected'],
				$service->is_date_bookable_for_menu( $case['menu_id'], $case['date'] ),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * filter_menu_ids() が、メニューごとのスタッフの勤務日で絞り込み、指定が無い・不正なときは全件を返すことを検証する。
	 */
	public function test_filter_menu_ids(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'メニューごとに担当スタッフを分けられるのは Pro 版のため、無料版ではスキップする。' );
		}

		$day_a = $this->day_from_today( 10 );
		$day_b = $this->day_from_today( 11 );

		// スタッフ A は day_a だけ、スタッフ B は day_b だけ勤務する。
		$staff_a = $this->create_staff();
		$staff_b = $this->create_staff();
		$this->create_shifts( $staff_a, array( $day_a, $day_b ), array( $day_b->format( 'Y-m-d' ) ) );
		$this->create_shifts( $staff_b, array( $day_a, $day_b ), array( $day_a->format( 'Y-m-d' ) ) );

		$menu_a = $this->create_menu( $staff_a );
		$menu_b = $this->create_menu( $staff_b );
		$menu_c = $this->create_menu( $staff_a );

		$filter = new Menu_Date_Filter();

		$test_cases = array(
			array(
				'test_condition_name' => 'スタッフ A だけ勤務する日 => A 担当のメニューだけ（順序を保つ）',
				'menu_ids'            => array( $menu_a, $menu_b, $menu_c ),
				'date'                => $day_a->format( 'Y-m-d' ),
				'staff_id'            => 0,
				'expected'            => array( $menu_a, $menu_c ),
			),
			array(
				'test_condition_name' => 'スタッフ B だけ勤務する日 => B 担当のメニューだけ',
				'menu_ids'            => array( $menu_a, $menu_b, $menu_c ),
				'date'                => $day_b->format( 'Y-m-d' ),
				'staff_id'            => 0,
				'expected'            => array( $menu_b ),
			),
			array(
				'test_condition_name' => '選択中のスタッフ B を指定し、B が休みの日 => 0 件',
				'menu_ids'            => array( $menu_b ),
				'date'                => $day_a->format( 'Y-m-d' ),
				'staff_id'            => $staff_b,
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '選択中のスタッフ A を指定し、メニュー B（担当外）を渡した日 => 担当外のため除外',
				'menu_ids'            => array( $menu_a, $menu_b ),
				'date'                => $day_a->format( 'Y-m-d' ),
				'staff_id'            => $staff_a,
				'expected'            => array( $menu_a ),
			),
			array(
				'test_condition_name' => '日付が空文字 => 絞り込まず全件（境界値）',
				'menu_ids'            => array( $menu_a, $menu_b, $menu_c ),
				'date'                => '',
				'staff_id'            => 0,
				'expected'            => array( $menu_a, $menu_b, $menu_c ),
			),
			array(
				'test_condition_name' => '日付の形式が不正 => 絞り込まず全件（異常系）',
				'menu_ids'            => array( $menu_a, $menu_b ),
				'date'                => 'abc',
				'staff_id'            => 0,
				'expected'            => array( $menu_a, $menu_b ),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame(
				$case['expected'],
				$filter->filter_menu_ids( $case['menu_ids'], $case['date'], $case['staff_id'] ),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * 宿泊のサイトでは、チェックアウト日が無いと絞り込まず全件を返し、時間枠のサイトではチェックアウト日を無視することを検証する
	 * （宿泊の日程での絞り込みは test-menu-date-filter-lodging.php で検証する）。
	 */
	public function test_filter_menu_ids_without_check_out_date_on_lodging_site(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '宿泊は Pro 版限定のため、無料版ではスキップする。' );
		}

		$open_day   = $this->day_from_today( 10 );
		$closed_day = $this->day_from_today( 11 );

		$staff_id = $this->create_staff();
		$this->create_shifts( $staff_id, array( $open_day, $closed_day ), array( $closed_day->format( 'Y-m-d' ) ) );
		$menu_id = $this->create_menu( $staff_id );

		$test_cases = array(
			array(
				'test_condition_name' => '時間枠のサイトで休業日を指定 => 0 件',
				'preset'              => 'custom',
				'check_out_date'      => '',
				'stay_mode'           => false,
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '時間枠のサイトでチェックアウト日を添えても無視して休業日を判定 => 0 件',
				'preset'              => 'custom',
				'check_out_date'      => $this->day_from_today( 12 )->format( 'Y-m-d' ),
				'stay_mode'           => false,
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '宿泊のサイトでチェックアウト日が無い => 絞り込まず全件',
				'preset'              => 'lodging',
				'check_out_date'      => '',
				'stay_mode'           => true,
				'expected'            => array( $menu_id ),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_industry_preset( $case['preset'] );

			$this->assertSame( $case['stay_mode'], Menu_Date_Filter::is_stay_mode(), $case['test_condition_name'] . ' : is_stay_mode' );
			$this->assertSame(
				$case['expected'],
				( new Menu_Date_Filter() )->filter_menu_ids( array( $menu_id ), $closed_day->format( 'Y-m-d' ), 0, array(), $case['check_out_date'] ),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * 判定結果が短時間キャッシュされ、世代番号が進む（シフトなどが保存される）と再計算されることを検証する。
	 */
	public function test_filter_menu_ids_caches_result_until_generation_changes(): void {
		$day      = $this->day_from_today( 10 );
		$staff_id = $this->create_staff();
		$this->create_shifts( $staff_id, array( $day, $this->day_from_today( 12 ) ) );
		$menu_id = $this->create_menu( $staff_id );

		$counting_service = $this->create_counting_service();

		$filter = new Menu_Date_Filter( $counting_service );
		$date   = $day->format( 'Y-m-d' );

		$this->assertSame( array( $menu_id ), $filter->filter_menu_ids( array( $menu_id ), $date ), '1回目は判定して結果を返す。' );
		$this->assertSame( 1, $counting_service->calls, '1回目は1回判定する。' );

		$this->assertSame( array( $menu_id ), $filter->filter_menu_ids( array( $menu_id ), $date ), '2回目は同じ結果を返す。' );
		$this->assertSame( 1, $counting_service->calls, '2回目はキャッシュを使い、再判定しない。' );

		$filter->filter_menu_ids( array( $menu_id ), $this->day_from_today( 12 )->format( 'Y-m-d' ) );
		$this->assertSame( 2, $counting_service->calls, '別の日付は別のキャッシュ名になり、判定する。' );

		// 無料版にはタグ機能が無いため、タグ条件のキャッシュは Pro 版で確かめる。
		$expected_calls = 2;
		if ( ! Pro_Upsell::is_free_edition() ) {
			// 存在しないタグの ID は落とされるため、実在するタグで確かめる。
			$term = wp_insert_term( 'キャッシュ確認用タグ', Resource_Tag_Taxonomy::TAXONOMY );
			$this->assertIsArray( $term );
			$filter->filter_menu_ids( array( $menu_id ), $date, 0, array( (int) $term['term_id'] ) );
			$expected_calls = 3;
			$this->assertSame( $expected_calls, $counting_service->calls, 'リソースタグの条件が違えば別のキャッシュ名になり、判定する。' );
		}

		// 世代番号を進める（シフト・メニュー・設定の保存で進む仕組みと同じ）。
		$generation_before = Availability_Cache_Generation::get_generation();
		Availability_Cache_Generation::bump();
		Availability_Cache_Generation::flush_bump();
		$this->assertSame( $generation_before + 1, Availability_Cache_Generation::get_generation(), '世代番号が1つ進む。' );

		$filter->filter_menu_ids( array( $menu_id ), $date );
		$this->assertSame( $expected_calls + 1, $counting_service->calls, '世代番号が進んだら古いキャッシュは使わず、再判定する。' );
	}

	/**
	 * 判定の呼び出し回数を数える Availability_Service を作る。
	 *
	 * @return Availability_Service 呼び出し回数 calls を持つサービス。
	 */
	private function create_counting_service(): Availability_Service {
		return new class() extends Availability_Service {
			/**
			 * 判定を呼び出した回数。
			 *
			 * @var int
			 */
			public int $calls = 0;

			/**
			 * 呼び出し回数を数えてから、通常の判定を行う。
			 *
			 * @param int                  $menu_id メニュー投稿 ID。
			 * @param string               $date    対象日（Y-m-d）。
			 * @param array<string, mixed> $args    resource_id / resource_tag_ids。
			 * @return bool 判定結果。
			 */
			public function is_date_bookable_for_menu( int $menu_id, string $date, array $args = array() ): bool {
				++$this->calls;
				return parent::is_date_bookable_for_menu( $menu_id, $date, $args );
			}
		};
	}

	/**
	 * 貸し切りで閉じた枠または満枠だけが残る日は、予約できない日として扱われることを検証する。
	 */
	public function test_is_date_bookable_for_menu_ignores_exclusive_closed_slots(): void {
		$day      = $this->day_from_today( 10 );
		$staff_id = $this->create_staff();
		$menu_id  = $this->create_menu( $staff_id );
		$date     = $day->format( 'Y-m-d' );

		$test_cases = array(
			array(
				'test_condition_name' => '貸し切りで閉じた枠だけ => 予約できない',
				'slots'               => array(
					array(
						'exclusive_closed' => true,
						'remaining'        => 1,
					),
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '貸し切りで閉じた枠と通常の枠 => 予約できる',
				'slots'               => array(
					array(
						'exclusive_closed' => true,
						'remaining'        => 1,
					),
					array(
						'exclusive_closed' => false,
						'remaining'        => 1,
					),
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '貸し切りではない満枠だけ => 予約できない',
				'slots'               => array(
					array(
						'exclusive_closed' => false,
						'remaining'        => 0,
					),
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '枠が無い => 予約できない',
				'slots'               => array(),
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			// 空き枠の計算だけを差し替えて、貸し切りの枠の扱いを確認する。
			$service = new class( $case['slots'] ) extends Availability_Service {
				/**
				 * 返す枠。
				 *
				 * @var array<int, array<string, mixed>>
				 */
				private array $fixed_slots;

				/**
				 * コンストラクタ。
				 *
				 * @param array<int, array<string, mixed>> $fixed_slots 返す枠。
				 */
				public function __construct( array $fixed_slots ) {
					parent::__construct();
					$this->fixed_slots = $fixed_slots;
				}

				/**
				 * 固定の枠を返す計算方式を返す。
				 *
				 * @param \WP_Post $menu_post メニュー投稿。
				 * @return \VKBookingManager\Availability\Slot_Strategy 計算方式。
				 */
				protected function resolve_slot_strategy( \WP_Post $menu_post ): \VKBookingManager\Availability\Slot_Strategy {
					return new class( $this->fixed_slots ) implements \VKBookingManager\Availability\Slot_Strategy {
						/**
						 * 返す枠。
						 *
						 * @var array<int, array<string, mixed>>
						 */
						private array $slots;

						/**
						 * コンストラクタ。
						 *
						 * @param array<int, array<string, mixed>> $slots 返す枠。
						 */
						public function __construct( array $slots ) {
							$this->slots = $slots;
						}

						/**
						 * 固定の枠を返す。
						 *
						 * @param \WP_Post      $menu_post          メニュー投稿。
						 * @param array<int>    $staff_ids          スタッフ ID。
						 * @param string        $date               日付。
						 * @param \DateTimeZone $timezone           タイムゾーン。
						 * @param bool          $is_staff_preferred 指名ありか。
						 * @return array<int, array<string, mixed>> 枠。
						 */
						public function generate_slots_for_date( \WP_Post $menu_post, array $staff_ids, string $date, \DateTimeZone $timezone, bool $is_staff_preferred ): array {
							return $this->slots;
						}
					};
				}
			};

			$this->assertSame( $case['expected'], $service->is_date_bookable_for_menu( $menu_id, $date ), $case['test_condition_name'] );
		}
	}

	/**
	 * 範囲外の日付は判定もキャッシュもせず空配列になり、実在しない・非公開のスタッフ指定は
	 * スタッフ指名なしと同じキャッシュを使うことを検証する。
	 */
	public function test_filter_menu_ids_normalizes_cache_name_and_limits_date_range(): void {
		$day      = $this->day_from_today( 10 );
		$staff_id = $this->create_staff();
		$this->create_shifts( $staff_id, array( $day ) );
		$menu_id = $this->create_menu( $staff_id );
		$date    = $day->format( 'Y-m-d' );

		$service = $this->create_counting_service();
		$filter  = new Menu_Date_Filter( $service );

		$test_cases = array(
			array(
				'test_condition_name' => '今日より前の日付 => 空配列で判定しない',
				'date'                => $this->day_from_today( -1 )->format( 'Y-m-d' ),
				'expected'            => array(),
				'expected_calls'      => 0,
			),
			array(
				'test_condition_name' => '2100年より先の日付 => 空配列で判定しない',
				'date'                => '2101-01-01',
				'expected'            => array(),
				'expected_calls'      => 0,
			),
			array(
				'test_condition_name' => '範囲内の日付 => 判定する',
				'date'                => $date,
				'expected'            => array( $menu_id ),
				'expected_calls'      => 1,
			),
		);

		foreach ( $test_cases as $case ) {
			$service->calls = 0;
			$this->assertSame( $case['expected'], $filter->filter_menu_ids( array( $menu_id ), $case['date'] ), $case['test_condition_name'] );
			$this->assertSame( $case['expected_calls'], $service->calls, $case['test_condition_name'] . ' : 判定回数' );
		}

		// 同じ日付・存在しないスタッフ指定は、指名なし（上で保存済み）と同じキャッシュを使う。
		$service->calls = 0;
		$filter->filter_menu_ids( array( $menu_id ), $date, 999999 );
		$this->assertSame( 0, $service->calls, '存在しないスタッフ ID は指名なしと同じキャッシュ名になる。' );

		$draft_staff_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'draft',
			)
		);
		$filter->filter_menu_ids( array( $menu_id ), $date, $draft_staff_id );
		$this->assertSame( 0, $service->calls, '公開中でないスタッフ ID は指名なしと同じキャッシュ名になる。' );
	}

	/**
	 * 非公開メニューを予約できる権限の有無で、キャッシュ名が分かれることを検証する。
	 */
	public function test_filter_menu_ids_separates_cache_by_private_menu_permission(): void {
		$day      = $this->day_from_today( 10 );
		$staff_id = $this->create_staff();
		$this->create_shifts( $staff_id, array( $day ) );
		$menu_id = $this->create_menu( $staff_id );
		$date    = $day->format( 'Y-m-d' );

		$service = $this->create_counting_service();
		$filter  = new Menu_Date_Filter( $service );

		wp_set_current_user( 0 );
		$filter->filter_menu_ids( array( $menu_id ), $date );
		$this->assertSame( 1, $service->calls, '未ログインで判定する。' );

		wp_set_current_user( (int) $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$filter->filter_menu_ids( array( $menu_id ), $date );
		$this->assertSame( 2, $service->calls, '予約を管理できるユーザーは別のキャッシュ名になり、判定し直す。' );

		wp_set_current_user( 0 );
	}

	/**
	 * サイト全体の指名機能が OFF のときは、選択中のスタッフを無視して指名なしと同じ判定・キャッシュ名になることを検証する。
	 */
	public function test_filter_menu_ids_ignores_staff_when_nomination_disabled(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '無料版は常に指名機能が OFF のため、ON/OFF の切り替えを検証できずスキップする。' );
		}

		$day     = $this->day_from_today( 10 );
		$staff_a = $this->create_staff();
		$staff_b = $this->create_staff();
		// スタッフ A だけ勤務し、スタッフ B は休み。
		$this->create_shifts( $staff_a, array( $day ) );
		$this->create_shifts( $staff_b, array( $day ), array( $day->format( 'Y-m-d' ) ) );
		$menu_id = $this->create_menu( $staff_b );
		$date    = $day->format( 'Y-m-d' );

		$service = $this->create_counting_service();
		$filter  = new Menu_Date_Filter( $service );

		// 指名 ON: 休みのスタッフ B を指定すると、そのスタッフでは予約できない。
		$this->assertSame( array(), $filter->filter_menu_ids( array( $menu_id ), $date, $staff_b ), '指名 ON では選択中のスタッフで判定する。' );

		// 指名 OFF: スタッフ指定を無視し、指名なしと同じ判定（メニューの担当 B が休みなので不可）になり、キャッシュ名も共通。
		update_option(
			Settings_Repository::OPTION_KEY,
			array_merge( ( new Settings_Repository() )->get_settings(), array( 'staff_enabled' => false ) )
		);
		Staff_Editor::clear_nomination_enabled_cache();

		$service->calls = 0;
		$filter->filter_menu_ids( array( $menu_id ), $date, 0 );
		$calls_without_staff = $service->calls;
		$service->calls      = 0;
		$filter->filter_menu_ids( array( $menu_id ), $date, $staff_a );
		$this->assertSame( 0, $service->calls, '指名 OFF ではスタッフ指定を無視し、指名なしと同じキャッシュ名になる。' );
		$this->assertSame( 1, $calls_without_staff, '指名なしの最初の判定は1回行う。' );
	}

	/**
	 * 時間枠のサイトでは、引き継ぐ日付は date だけを検証して残し、check_out_date は常に捨てることを検証する。
	 */
	public function test_normalize_carry_dates(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '実在する date => date だけ残す',
				'date'                => '2098-10-20',
				'check_out_date'      => '',
				'expected'            => array( 'date' => '2098-10-20' ),
			),
			array(
				'test_condition_name' => '時間枠のサイト => check_out_date は捨てる',
				'date'                => '2098-10-20',
				'check_out_date'      => '2098-10-22',
				'expected'            => array( 'date' => '2098-10-20' ),
			),
			array(
				'test_condition_name' => '過去日でも実在すれば残す（受け取った側が案内に使う）',
				'date'                => '2000-01-05',
				'check_out_date'      => '',
				'expected'            => array( 'date' => '2000-01-05' ),
			),
			array(
				'test_condition_name' => '実在しない日付 => 空',
				'date'                => '2098-02-31',
				'check_out_date'      => '',
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '形式が違う => 空',
				'date'                => '20981020',
				'check_out_date'      => '',
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '空 => 空',
				'date'                => '',
				'check_out_date'      => '',
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame(
				$case['expected'],
				Menu_Date_Filter::normalize_carry_dates( $case['date'], $case['check_out_date'] ),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * carry_dates_from_request() が、表示中の URL のクエリから検証済みの日付を取り出すことを検証する。
	 */
	public function test_carry_dates_from_request(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'date がある => 取り出す',
				'get'                 => array( 'date' => '2098-10-20' ),
				'expected'            => array( 'date' => '2098-10-20' ),
			),
			array(
				'test_condition_name' => '不正な date => 空',
				'get'                 => array( 'date' => 'tomorrow' ),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '配列で渡された date => 空',
				'get'                 => array( 'date' => array( '2098-10-20' ) ),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => 'クエリなし => 空',
				'get'                 => array(),
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$_GET = $case['get'];
			$this->assertSame( $case['expected'], Menu_Date_Filter::carry_dates_from_request(), $case['test_condition_name'] );
		}
		$_GET = array();
	}
}
