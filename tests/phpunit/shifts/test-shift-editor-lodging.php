<?php
/**
 * 宿泊のときのシフト編集画面（販売日の登録）と、宿泊以外の保存が変わらないことのテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Shifts;

use ReflectionMethod;
use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Shift_Post_Type;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Shifts\Shift_Editor;
use VKBookingManager\Shifts\Shift_Lodging_Editor;
use WP_UnitTestCase;

/**
 * 保存・画面の振り分け・販売日の初期値を検証する。
 *
 * 宿泊の検証は Pro 版で行う（無料版では Industry_Presets::supports( 'lodging' ) が常に false のため）。
 *
 * @group shifts
 */
class Shift_Editor_Lodging_Test extends WP_UnitTestCase {

	private const NONCE_ACTION = 'vkbm_shift_meta';
	private const NONCE_NAME   = '_vkbm_shift_meta_nonce';

	/**
	 * 保存に使う Shift_Editor（テスト中は使い回す）。
	 *
	 * @var Shift_Editor|null
	 */
	private ?Shift_Editor $editor = null;

	/**
	 * テスト前に設定とフィルターを初期化し、管理者でログインする。
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( Settings_Repository::OPTION_KEY );
		Industry_Presets::clear_cache();
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * テスト後に設定・フィルター・$_POST・ログイン状態を戻す。
	 */
	public function tear_down(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		Industry_Presets::clear_cache();
		$_POST        = array();
		$this->editor = null;
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * 宿泊系の検証を無料版ではスキップする。
	 */
	private function skip_if_free_edition(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '宿泊の画面は Pro 版で検証する。' );
		}
	}

	/**
	 * 業種プリセットを設定する。
	 *
	 * @param string $preset         保存する業種プリセットのキー。
	 * @param array  $extra_settings 追加で保存する設定。
	 */
	private function set_site( string $preset, array $extra_settings = array() ): void {
		Industry_Presets::clear_cache();
		update_option(
			Settings_Repository::OPTION_KEY,
			array_merge( ( new Settings_Repository() )->get_default_settings(), array( 'industry_preset' => $preset ), $extra_settings )
		);
		// Shift_Editor が読む設定は、リクエスト内で使い回されるため最新の値へ読み直させる。
		$method = new ReflectionMethod( Shift_Editor::class, 'get_provider_settings' );
		$method->setAccessible( true );
		$method->invoke( new Shift_Editor(), true );
	}

	/**
	 * 客室（リソース）を1件作る。
	 *
	 * @return int 投稿 ID。
	 */
	private function create_resource(): int {
		return (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * 保存済みのシフトを1件作る。
	 *
	 * @param int                              $resource_id 客室 ID。
	 * @param int                              $year        年。
	 * @param int                              $month       月。
	 * @param array<int, array<string, mixed>> $days        日ごとのデータ。
	 * @return int 投稿 ID。
	 */
	private function create_shift( int $resource_id, int $year, int $month, array $days ): int {
		return (int) $this->factory()->post->create(
			array(
				'post_type'   => Shift_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'meta_input'  => array(
					'_vkbm_shift_resource_id' => $resource_id,
					'_vkbm_shift_year'        => $year,
					'_vkbm_shift_month'       => $month,
					'_vkbm_shift_days'        => $days,
				),
			)
		);
	}

	/**
	 * 編集画面からの送信を再現して、シフトを保存する。
	 *
	 * @param int                  $post_id シフトの投稿 ID。
	 * @param array<string, mixed> $fields  vkbm_shift の送信内容。
	 */
	private function submit( int $post_id, array $fields ): void {
		$_POST = array(
			self::NONCE_NAME => wp_create_nonce( self::NONCE_ACTION ),
			'vkbm_shift'     => $fields,
		);

		// save_post() は終わりにタイトル更新で自分自身を再登録するため、インスタンスを使い回す
		// （保存のたびに新しく作ると、登録されたインスタンスが増えて保存処理が連鎖し、テストが終わらなくなる）。
		if ( null === $this->editor ) {
			$this->editor = new Shift_Editor();
		}
		$this->editor->save_post( $post_id, get_post( $post_id ) );

		// 次の投稿の作成時に、プラグイン本体が登録した保存処理が残りの送信内容を読まないよう消す。
		$_POST = array();
	}

	/**
	 * 月の全日分の販売日の送信値を作る（指定した日だけ 1、ほかは 0）。
	 *
	 * @param int        $days_in_month 月の日数。
	 * @param array<int> $on_days       販売する日。
	 * @return string JSON 文字列。
	 */
	private function sale_days_json( int $days_in_month, array $on_days ): string {
		$flags = array();
		for ( $day = 1; $day <= $days_in_month; $day++ ) {
			$flags[ $day ] = in_array( $day, $on_days, true ) ? 1 : 0;
		}

		return (string) wp_json_encode( $flags );
	}

	/**
	 * 月の全日分のデータを、日ごとの上書きつきで作る。
	 *
	 * @param int                              $days_in_month 月の日数。
	 * @param string                           $default_status 上書きの無い日の状態。
	 * @param array<int, array<string, mixed>> $overrides      日ごとの上書き。
	 * @return array<int, array<string, mixed>>
	 */
	private function all_days( int $days_in_month, string $default_status, array $overrides = array() ): array {
		$days = array();
		for ( $day = 1; $day <= $days_in_month; $day++ ) {
			$days[ $day ] = $overrides[ $day ] ?? array(
				'status' => $default_status,
				'slots'  => array(),
			);
		}

		return $days;
	}

	/**
	 * 日曜を定休日とする休みの日の上書きを作る。
	 *
	 * @param array<int> $sundays 日曜の日番号。
	 * @return array<int, array<string, mixed>>
	 */
	private function sunday_holidays( array $sundays ): array {
		$overrides = array();
		foreach ( $sundays as $day ) {
			$overrides[ $day ] = array(
				'status' => 'regular_holiday',
				'slots'  => array(),
			);
		}

		return $overrides;
	}

	/**
	 * 宿泊の保存が、前の値とつなぎ合わせて月の全日を状態付きで残すことを検証する。
	 *
	 * 基本設定の定休日は毎週日曜。2026年10月の日曜は 4・11・18・25 日、11月は 1・8・15・22・29 日、
	 * 2027年10月は 3・10・17・24・31 日。
	 */
	public function test_save_post_lodging(): void {
		$this->skip_if_free_edition();

		$slots = array(
			array(
				'start' => '09:00',
				'end'   => '18:00',
			),
		);
		$mixed = array(
			3 => array(
				'status' => 'open',
				'slots'  => $slots,
			),
			4 => array(
				'status' => 'regular_holiday',
				'slots'  => array(),
			),
			5 => array(
				'status' => 'temporary_closed',
				'slots'  => array(),
			),
			6 => array(
				'status' => 'temporary_open',
				'slots'  => $slots,
			),
		);
		$open  = array(
			'status' => 'open',
			'slots'  => array(),
		);

		$test_cases = array(
			array(
				'test_condition_name' => '新規の月で全日を販売する => 全日が open＋空の時間帯で残る',
				'previous'            => null,
				'resource'            => 'same',
				'submit_period'       => array( 2026, 10 ),
				'on_days'             => range( 1, 31 ),
				'sale_days'           => null,
				'expected'            => $this->all_days( 31, 'open' ),
			),
			array(
				'test_condition_name' => '新規の月で定休日の日を触らず（販売しないのまま）保存 => 定休日の日は regular_holiday のまま、ほかの販売しない日は unavailable',
				'previous'            => null,
				'resource'            => 'same',
				'submit_period'       => array( 2026, 10 ),
				'on_days'             => array( 1, 2, 3 ),
				'sale_days'           => null,
				'expected'            => $this->all_days(
					31,
					'unavailable',
					array_replace(
						$this->sunday_holidays( array( 4, 11, 18, 25 ) ),
						array(
							1 => $open,
							2 => $open,
							3 => $open,
						)
					)
				),
			),
			array(
				'test_condition_name' => '定休日・臨時休業・時間帯付きの日を含む月を、触らずそのまま保存 => 元の状態と時間帯が変わらない',
				'previous'            => $mixed,
				'resource'            => 'same',
				'submit_period'       => array( 2026, 10 ),
				'on_days'             => array( 3, 6 ),
				'sale_days'           => null,
				'expected'            => $this->all_days( 31, 'unavailable', $mixed ),
			),
			array(
				'test_condition_name' => '販売しない日を販売するに変え、販売する日を販売しないに変える => 新しい値は open＋空 と unavailable',
				'previous'            => $mixed,
				'resource'            => 'same',
				'submit_period'       => array( 2026, 10 ),
				'on_days'             => array( 4, 5 ),
				'sale_days'           => null,
				'expected'            => $this->all_days(
					31,
					'unavailable',
					array(
						4 => $open,
						5 => $open,
					)
				),
			),
			array(
				'test_condition_name' => '前と違う月（11月）で保存 => 前の値を引き継がず、初期値（日曜は regular_holiday）から組み立てる',
				'previous'            => $mixed,
				'resource'            => 'same',
				'submit_period'       => array( 2026, 11 ),
				'on_days'             => array( 3 ),
				'sale_days'           => null,
				'expected'            => $this->all_days(
					30,
					'unavailable',
					array_replace( $this->sunday_holidays( array( 1, 8, 15, 22, 29 ) ), array( 3 => $open ) )
				),
			),
			array(
				'test_condition_name' => '前と年だけ違う（2027年10月）で保存 => 前の値を引き継がず、初期値から組み立てる',
				'previous'            => $mixed,
				'resource'            => 'same',
				'submit_period'       => array( 2027, 10 ),
				'on_days'             => array(),
				'sale_days'           => null,
				'expected'            => $this->all_days( 31, 'unavailable', $this->sunday_holidays( array( 3, 10, 17, 24, 31 ) ) ),
			),
			array(
				'test_condition_name' => '前と客室だけ違う（同じ年月）で保存 => 前の値（臨時休業など）を引き継がない',
				'previous'            => $mixed,
				'resource'            => 'other',
				'submit_period'       => array( 2026, 10 ),
				'on_days'             => array( 3, 6 ),
				'sale_days'           => null,
				'expected'            => $this->all_days(
					31,
					'unavailable',
					array_replace(
						$this->sunday_holidays( array( 4, 11, 18, 25 ) ),
						array(
							3 => $open,
							6 => $open,
						)
					)
				),
			),
			array(
				'test_condition_name' => '販売日の送信値が空で前の値（同じ客室・年月）がある => 前の値のまま保存する',
				'previous'            => $mixed,
				'resource'            => 'same',
				'submit_period'       => array( 2026, 10 ),
				'on_days'             => array(),
				'sale_days'           => '',
				'expected'            => $mixed,
			),
			array(
				'test_condition_name' => '販売日の送信値が不正な文字列で前の値が無い => 初期値（日曜以外は open＋空、日曜は regular_holiday）を保存する',
				'previous'            => null,
				'resource'            => 'same',
				'submit_period'       => array( 2026, 10 ),
				'on_days'             => array(),
				'sale_days'           => 'not-json',
				'expected'            => $this->all_days( 31, 'open', $this->sunday_holidays( array( 4, 11, 18, 25 ) ) ),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( 'lodging', $this->holiday_settings() );
			$resource_id = $this->create_resource();
			$post_id     = $this->create_shift( $resource_id, 2026, 10, (array) $case['previous'] );
			if ( null === $case['previous'] ) {
				delete_post_meta( $post_id, '_vkbm_shift_days' );
			}
			$submit_resource_id = 'other' === $case['resource'] ? $this->create_resource() : $resource_id;

			list( $year, $month ) = $case['submit_period'];
			$days_in_month        = (int) gmdate( 't', gmmktime( 0, 0, 0, $month, 1, $year ) );

			$this->submit(
				$post_id,
				array(
					'resource_id'                         => (string) $submit_resource_id,
					'year'                                => (string) $year,
					'month'                               => (string) $month,
					Shift_Lodging_Editor::SCREEN_MODE_FIELD => 'lodging',
					Shift_Lodging_Editor::SALE_DAYS_FIELD => null === $case['sale_days'] ? $this->sale_days_json( $days_in_month, $case['on_days'] ) : $case['sale_days'],
				)
			);

			$this->assertSame( $case['expected'], get_post_meta( $post_id, '_vkbm_shift_days', true ), $case['test_condition_name'] );
		}
	}

	/**
	 * 基本設定の定休日（毎週日曜・営業時間なし）の設定を返す。
	 *
	 * @return array<string, mixed>
	 */
	private function holiday_settings(): array {
		return array(
			'provider_regular_holidays'     => array(
				array(
					'frequency' => 'weekly',
					'weekday'   => 'sun',
				),
			),
			'provider_business_hours_basic' => array(),
		);
	}

	/**
	 * 画面を開いたあとに業種が切り替わった場合は、保存値を更新しないことを検証する。
	 */
	public function test_save_post_screen_mode_mismatch(): void {
		$this->skip_if_free_edition();

		$test_cases = array(
			array(
				'test_condition_name' => '宿泊のサイトに、宿泊以外の画面から送信 => 更新しない',
				'site_preset'         => 'lodging',
				'submitted_mode'      => 'standard',
			),
			array(
				'test_condition_name' => '画面の種類が送られない（宿泊以外の画面）を、宿泊のサイトで受信 => 更新しない',
				'site_preset'         => 'lodging',
				'submitted_mode'      => null,
			),
			array(
				'test_condition_name' => '宿泊以外のサイト（custom）に、宿泊の画面から送信 => 更新しない',
				'site_preset'         => 'custom',
				'submitted_mode'      => 'lodging',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( $case['site_preset'] );
			$resource_id = $this->create_resource();
			$original    = $this->all_days( 31, 'regular_holiday' );
			$post_id     = $this->create_shift( $resource_id, 2026, 10, $original );

			// 客室・年・月も、保存済みの値と違う値で送信する。
			$other_resource_id = $this->create_resource();
			$fields            = array(
				'resource_id'                         => (string) $other_resource_id,
				'year'                                => '2027',
				'month'                               => '11',
				Shift_Lodging_Editor::SALE_DAYS_FIELD => $this->sale_days_json( 31, range( 1, 31 ) ),
				'days_json'                           => '{"1":{"status":"unavailable","slots":[]}}',
			);
			if ( null !== $case['submitted_mode'] ) {
				$fields[ Shift_Lodging_Editor::SCREEN_MODE_FIELD ] = $case['submitted_mode'];
			}
			$this->submit( $post_id, $fields );

			$this->assertSame( $original, get_post_meta( $post_id, '_vkbm_shift_days', true ), $case['test_condition_name'] );
			$this->assertSame( $resource_id, (int) get_post_meta( $post_id, '_vkbm_shift_resource_id', true ), $case['test_condition_name'] . '（客室は変わらない）' );
			$this->assertSame( 2026, (int) get_post_meta( $post_id, '_vkbm_shift_year', true ), $case['test_condition_name'] . '（年は変わらない）' );
			$this->assertSame( 10, (int) get_post_meta( $post_id, '_vkbm_shift_month', true ), $case['test_condition_name'] . '（月は変わらない）' );
		}
	}

	/**
	 * 宿泊以外の保存が、これまでと同じ値になることを固定する。
	 */
	public function test_save_post_standard(): void {
		$slots = array(
			array(
				'start' => '09:00',
				'end'   => '18:00',
			),
		);

		$test_cases = array(
			array(
				'test_condition_name' => '宿泊以外（custom）で、画面の種類を送らない従来の送信 => 時間帯の無い open の日は捨てられ、ほかは送信どおり',
				'fields'              => array(
					'days_json' => '{"1":{"status":"open","slots":[{"start":"09:00","end":"18:00"}]},"2":{"status":"unavailable","slots":[]},"3":{"status":"open","slots":[]},"4":{"status":"regular_holiday","slots":[]}}',
				),
				'expected'            => array(
					1 => array(
						'status' => 'open',
						'slots'  => $slots,
					),
					2 => array(
						'status' => 'unavailable',
						'slots'  => array(),
					),
					4 => array(
						'status' => 'regular_holiday',
						'slots'  => array(),
					),
				),
			),
			array(
				'test_condition_name' => '画面の種類 standard を送る宿泊以外の画面 => 同じ値で保存される',
				'fields'              => array(
					Shift_Lodging_Editor::SCREEN_MODE_FIELD => 'standard',
					'days_json' => '{"5":{"status":"temporary_open","slots":[{"start":"09:00","end":"18:00"}]}}',
				),
				'expected'            => array(
					5 => array(
						'status' => 'temporary_open',
						'slots'  => $slots,
					),
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( 'custom' );
			$resource_id = $this->create_resource();
			$post_id     = $this->create_shift( $resource_id, 2026, 10, array() );

			$this->submit(
				$post_id,
				array_merge(
					array(
						'resource_id' => (string) $resource_id,
						'year'        => '2026',
						'month'       => '10',
					),
					$case['fields']
				)
			);

			$this->assertSame( $case['expected'], get_post_meta( $post_id, '_vkbm_shift_days', true ), $case['test_condition_name'] );
			$this->assertSame( 10, (int) get_post_meta( $post_id, '_vkbm_shift_month', true ), '月が保存される' );
		}
	}

	/**
	 * 前の値とのつなぎ合わせが、運営者の操作ごとに期待どおりの値になることを検証する。
	 */
	public function test_build_days_for_save(): void {
		$slots = array(
			array(
				'start' => '09:00',
				'end'   => '18:00',
			),
		);
		$open  = array(
			'status' => 'open',
			'slots'  => array(),
		);
		$off   = array(
			'status' => 'unavailable',
			'slots'  => array(),
		);

		$test_cases = array(
			array(
				'test_condition_name' => '前の値なしで販売する => open＋空',
				'flags'               => array( 1 => true ),
				'previous'            => array(),
				'expected'            => array( 1 => $open ),
			),
			array(
				'test_condition_name' => '前の値なしで販売しない => unavailable',
				'flags'               => array( 1 => false ),
				'previous'            => array(),
				'expected'            => array( 1 => $off ),
			),
			array(
				'test_condition_name' => '前が temporary_open（時間帯あり）のまま販売する => 前の値を残す',
				'flags'               => array( 1 => true ),
				'previous'            => array(
					1 => array(
						'status' => 'temporary_open',
						'slots'  => $slots,
					),
				),
				'expected'            => array(
					1 => array(
						'status' => 'temporary_open',
						'slots'  => $slots,
					),
				),
			),
			array(
				'test_condition_name' => '前が regular_holiday のまま販売しない => regular_holiday を残す',
				'flags'               => array( 1 => false ),
				'previous'            => array(
					1 => array(
						'status' => 'regular_holiday',
						'slots'  => array(),
					),
				),
				'expected'            => array(
					1 => array(
						'status' => 'regular_holiday',
						'slots'  => array(),
					),
				),
			),
			array(
				'test_condition_name' => '前が temporary_closed の日を販売するに変更 => open＋空',
				'flags'               => array( 1 => true ),
				'previous'            => array(
					1 => array(
						'status' => 'temporary_closed',
						'slots'  => array(),
					),
				),
				'expected'            => array( 1 => $open ),
			),
			array(
				'test_condition_name' => '前が時間帯付きの open の日を販売しないに変更 => unavailable（時間帯は消える）',
				'flags'               => array( 1 => false ),
				'previous'            => array(
					1 => array(
						'status' => 'open',
						'slots'  => $slots,
					),
				),
				'expected'            => array( 1 => $off ),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Shift_Lodging_Editor::build_days_for_save( $case['flags'], $case['previous'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * 「販売する」へ切り替えた日に、既定の時間帯が入ることを検証する。
	 */
	public function test_build_days_for_save_with_open_slots(): void {
		$slots       = array(
			array(
				'start' => '09:00',
				'end'   => '18:00',
			),
		);
		$other_slots = array(
			array(
				'start' => '10:00',
				'end'   => '12:00',
			),
		);
		$open_by_day = array(
			1 => $slots,
			2 => $slots,
			3 => array(),
		);

		$test_cases = array(
			array(
				'test_condition_name' => '前が販売しない（unavailable）の日を販売する => 既定の時間帯が入る',
				'flags'               => array( 1 => true ),
				'previous'            => array(
					1 => array(
						'status' => 'unavailable',
						'slots'  => array(),
					),
				),
				'expected'            => array(
					1 => array(
						'status' => 'open',
						'slots'  => $slots,
					),
				),
			),
			array(
				'test_condition_name' => '前が定休日・臨時休業の日を販売する => 既定の時間帯が入る',
				'flags'               => array(
					1 => true,
					2 => true,
				),
				'previous'            => array(
					1 => array(
						'status' => 'regular_holiday',
						'slots'  => array(),
					),
					2 => array(
						'status' => 'temporary_closed',
						'slots'  => array(),
					),
				),
				'expected'            => array(
					1 => array(
						'status' => 'open',
						'slots'  => $slots,
					),
					2 => array(
						'status' => 'open',
						'slots'  => $slots,
					),
				),
			),
			array(
				'test_condition_name' => '前が時間帯付きの販売する日を販売するのまま => 前の時間帯を残す（既定で上書きしない）',
				'flags'               => array( 1 => true ),
				'previous'            => array(
					1 => array(
						'status' => 'open',
						'slots'  => $other_slots,
					),
				),
				'expected'            => array(
					1 => array(
						'status' => 'open',
						'slots'  => $other_slots,
					),
				),
			),
			array(
				'test_condition_name' => '既定の時間帯が無い日・前の値が無い日を販売する => 空の時間帯で保存する',
				'flags'               => array(
					3 => true,
					4 => true,
				),
				'previous'            => array(),
				'expected'            => array(
					3 => array(
						'status' => 'open',
						'slots'  => array(),
					),
					4 => array(
						'status' => 'open',
						'slots'  => array(),
					),
				),
			),
			array(
				'test_condition_name' => '販売しない日には既定の時間帯を入れない',
				'flags'               => array( 1 => false ),
				'previous'            => array(),
				'expected'            => array(
					1 => array(
						'status' => 'unavailable',
						'slots'  => array(),
					),
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Shift_Lodging_Editor::build_days_for_save( $case['flags'], $case['previous'], $open_by_day ), $case['test_condition_name'] );
		}
	}

	/**
	 * 宿泊の保存で、販売しない日を販売するへ切り替えた日に、基本営業時間または客室のシフトテンプレートの時間帯が入ることを検証する。
	 *
	 * 2026年10月の日曜は 4・11・18・25 日（基本設定の定休日は毎週日曜）。
	 */
	public function test_save_post_lodging_sale_slots(): void {
		$this->skip_if_free_edition();

		$business_hours = array(
			array(
				'start' => '09:00',
				'end'   => '18:00',
			),
		);
		$template_hours = array(
			array(
				'start' => '10:00',
				'end'   => '12:00',
			),
		);
		// 2026-10-01 は木曜、2026-10-02 は金曜。
		$off_days = $this->all_days( 31, 'unavailable', $this->sunday_holidays( array( 4, 11, 18, 25 ) ) );

		$test_cases = array(
			array(
				'test_condition_name' => '基本営業時間あり => 販売するへ切り替えた日（木曜・定休日の日曜）に基本営業時間の時間帯が入る',
				'settings'            => array_merge( $this->holiday_settings(), array( 'provider_business_hours_basic' => $business_hours ) ),
				'template'            => null,
				'on_days'             => array( 1, 4 ),
				'expected_days'       => array(
					1 => array(
						'status' => 'open',
						'slots'  => $business_hours,
					),
					4 => array(
						'status' => 'open',
						'slots'  => $business_hours,
					),
					2 => array(
						'status' => 'unavailable',
						'slots'  => array(),
					),
				),
			),
			array(
				'test_condition_name' => '客室のシフトテンプレートに対象の曜日（日・木）の時間帯あり（基本営業時間は使わない）=> テンプレートの時間帯が入る',
				'settings'            => array_merge( $this->holiday_settings(), array( 'provider_business_hours_basic' => $business_hours ) ),
				'template'            => array(
					'use_provider_hours' => false,
					'days'               => array(
						'sun' => $template_hours,
						'thu' => $template_hours,
					),
				),
				'on_days'             => array( 1, 4 ),
				'expected_days'       => array(
					1 => array(
						'status' => 'open',
						'slots'  => $template_hours,
					),
					4 => array(
						'status' => 'open',
						'slots'  => $template_hours,
					),
				),
			),
			array(
				'test_condition_name' => '客室のシフトテンプレートに時間帯が無い曜日で基本営業時間の利用が有効 => 基本営業時間の時間帯が入る',
				'settings'            => array_merge( $this->holiday_settings(), array( 'provider_business_hours_basic' => $business_hours ) ),
				'template'            => array(
					'use_provider_hours' => true,
					'days'               => array( 'thu' => $template_hours ),
				),
				'on_days'             => array( 1, 2 ),
				'expected_days'       => array(
					1 => array(
						'status' => 'open',
						'slots'  => $template_hours,
					),
					2 => array(
						'status' => 'open',
						'slots'  => $business_hours,
					),
				),
			),
			array(
				'test_condition_name' => '客室のシフトテンプレートに時間帯が無い曜日で基本営業時間の利用が無効 => 基本営業時間を使わず空の時間帯で保存する',
				'settings'            => array_merge( $this->holiday_settings(), array( 'provider_business_hours_basic' => $business_hours ) ),
				'template'            => array(
					'use_provider_hours' => false,
					'days'               => array( 'thu' => $template_hours ),
				),
				'on_days'             => array( 1, 2 ),
				'expected_days'       => array(
					1 => array(
						'status' => 'open',
						'slots'  => $template_hours,
					),
					2 => array(
						'status' => 'open',
						'slots'  => array(),
					),
				),
			),
			array(
				'test_condition_name' => '基本営業時間もテンプレートも無い => 空の時間帯で保存する（エラーにしない）',
				'settings'            => $this->holiday_settings(),
				'template'            => null,
				'on_days'             => array( 1, 4 ),
				'expected_days'       => array(
					1 => array(
						'status' => 'open',
						'slots'  => array(),
					),
					4 => array(
						'status' => 'open',
						'slots'  => array(),
					),
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( 'lodging', $case['settings'] );
			$resource_id = $this->create_resource();
			if ( null !== $case['template'] ) {
				update_post_meta( $resource_id, '_vkbm_resource_schedule_template', $case['template'] );
			}
			// 全日が販売しない（日曜は定休日）の保存済みシフトから、対象の日だけ販売するへ切り替える。
			$post_id = $this->create_shift( $resource_id, 2026, 10, $off_days );

			$this->submit(
				$post_id,
				array(
					'resource_id'                         => (string) $resource_id,
					'year'                                => '2026',
					'month'                               => '10',
					Shift_Lodging_Editor::SCREEN_MODE_FIELD => 'lodging',
					Shift_Lodging_Editor::SALE_DAYS_FIELD => $this->sale_days_json( 31, $case['on_days'] ),
				)
			);

			$saved = get_post_meta( $post_id, '_vkbm_shift_days', true );
			foreach ( $case['expected_days'] as $day => $expected ) {
				$this->assertSame( $expected, $saved[ $day ] ?? null, $case['test_condition_name'] . "（{$day}日）" );
			}
			$this->assertCount( 31, $saved, $case['test_condition_name'] . '（月の全日が残る）' );
		}
	}

	/**
	 * 送信された販売日の JSON を、月の全日の真偽値へ変換することを検証する。
	 */
	public function test_parse_sale_flags(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '1 と 0 の JSON（2026年2月は28日）=> 28日分の真偽値。送られていない日は販売しない',
				'json'                => '{"1":1,"2":0,"3":1}',
				'year'                => 2026,
				'month'               => 2,
				'expected_count'      => 28,
				'expected_on'         => array( 1, 3 ),
			),
			array(
				'test_condition_name' => '月の日数を超える日は無視する（2026年2月の 30 日）',
				'json'                => '{"28":1,"30":1}',
				'year'                => 2026,
				'month'               => 2,
				'expected_count'      => 28,
				'expected_on'         => array( 28 ),
			),
			array(
				'test_condition_name' => '空の JSON => null',
				'json'                => '',
				'year'                => 2026,
				'month'               => 2,
				'expected_count'      => null,
				'expected_on'         => array(),
			),
			array(
				'test_condition_name' => '空の配列 => null',
				'json'                => '[]',
				'year'                => 2026,
				'month'               => 2,
				'expected_count'      => null,
				'expected_on'         => array(),
			),
			array(
				'test_condition_name' => '年が不正（0）=> null',
				'json'                => '{"1":1}',
				'year'                => 0,
				'month'               => 2,
				'expected_count'      => null,
				'expected_on'         => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = Shift_Lodging_Editor::parse_sale_flags( $case['json'], $case['year'], $case['month'] );

			if ( null === $case['expected_count'] ) {
				$this->assertNull( $actual, $case['test_condition_name'] );
				continue;
			}

			$this->assertCount( $case['expected_count'], $actual, $case['test_condition_name'] );
			$this->assertSame( $case['expected_on'], array_keys( array_filter( $actual ) ), $case['test_condition_name'] );
		}
	}

	/**
	 * 保存済みの日ごとのデータから、画面の初期状態（データに無い日は販売しない）を作ることを検証する。
	 */
	public function test_build_sale_flags(): void {
		$days = array(
			1 => array(
				'status' => 'open',
				'slots'  => array(),
			),
			2 => array(
				'status' => 'temporary_open',
				'slots'  => array(),
			),
			3 => array(
				'status' => 'regular_holiday',
				'slots'  => array(),
			),
			4 => array(
				'status' => 'temporary_closed',
				'slots'  => array(),
			),
			5 => array(
				'status' => 'unavailable',
				'slots'  => array(),
			),
		);

		$flags = Shift_Lodging_Editor::build_sale_flags( $days, 2026, 10 );

		$this->assertCount( 31, $flags, '月の全日分が返る' );
		$this->assertSame( array( 1, 2 ), array_keys( array_filter( $flags ) ), 'open と temporary_open だけが販売する。データに無い日（6 日以降）は販売しない' );
	}

	/**
	 * 宿泊のとき、初期値の作成が定休日でない日を open＋空の時間帯で必ず作ることを検証する。
	 */
	public function test_derive_default_days(): void {
		$this->skip_if_free_edition();

		$holiday_rules = array(
			'provider_regular_holidays'     => array(
				array(
					'frequency' => 'weekly',
					'weekday'   => 'sun',
				),
			),
			'provider_business_hours_basic' => array(),
		);

		// 2026年10月: 4・11・18・25 日が日曜。
		$test_cases = array(
			array(
				'test_condition_name' => '宿泊 => 営業時間が無くても、日曜（定休日）以外は open＋空、日曜は regular_holiday',
				'preset'              => 'lodging',
				'expected_count'      => 31,
				'expected_day_1'      => array(
					'status' => 'open',
					'slots'  => array(),
				),
				'expected_day_4'      => array(
					'status' => 'regular_holiday',
					'slots'  => array(),
				),
			),
			array(
				'test_condition_name' => '宿泊以外（custom）=> 営業時間が無い日はデータを作らない（従来どおり）',
				'preset'              => 'custom',
				'expected_count'      => 4,
				'expected_day_1'      => null,
				'expected_day_4'      => array(
					'status' => 'regular_holiday',
					'slots'  => array(),
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( $case['preset'], $holiday_rules );

			$actual = ( new Shift_Editor() )->derive_default_days( 0, 2026, 10 );

			$this->assertCount( $case['expected_count'], $actual, $case['test_condition_name'] );
			$this->assertSame( $case['expected_day_1'], $actual[1] ?? null, $case['test_condition_name'] . '（1日）' );
			$this->assertSame( $case['expected_day_4'], $actual[4] ?? null, $case['test_condition_name'] . '（4日）' );
		}
	}

	/**
	 * 客室のスケジュールテンプレートがある経路でも、宿泊のときだけ定休日でない日の open のデータを作ることを検証する。
	 *
	 * 2026年10月: 月曜は 5・12・19・26 日、日曜（定休日）は 4・11・18・25 日。
	 */
	public function test_derive_default_days_with_template(): void {
		$this->skip_if_free_edition();

		$slots = array(
			array(
				'start' => '09:00',
				'end'   => '18:00',
			),
		);

		$test_cases = array(
			array(
				'test_condition_name' => '宿泊 => テンプレートに月曜の時間帯だけがあっても、全日にデータがある（月曜は時間帯付き、日曜は regular_holiday、ほかは open＋空）',
				'preset'              => 'lodging',
				'expected_count'      => 31,
				'expected'            => array(
					1 => array(
						'status' => 'open',
						'slots'  => array(),
					),
					4 => array(
						'status' => 'regular_holiday',
						'slots'  => array(),
					),
					5 => array(
						'status' => 'open',
						'slots'  => $slots,
					),
				),
			),
			array(
				'test_condition_name' => '宿泊以外 => 時間帯のある月曜と定休日の日曜だけにデータがある（従来どおり）',
				'preset'              => 'custom',
				'expected_count'      => 8,
				'expected'            => array(
					1 => null,
					4 => array(
						'status' => 'regular_holiday',
						'slots'  => array(),
					),
					5 => array(
						'status' => 'open',
						'slots'  => $slots,
					),
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( $case['preset'], $this->holiday_settings() );
			$resource_id = $this->create_resource();
			( new \VKBookingManager\Resources\Resource_Schedule_Template_Repository() )->save_template(
				$resource_id,
				array(
					'use_provider_hours' => false,
					'days'               => array( 'mon' => $slots ),
				)
			);

			$actual = ( new Shift_Editor() )->derive_default_days( $resource_id, 2026, 10 );

			$this->assertCount( $case['expected_count'], $actual, $case['test_condition_name'] );
			foreach ( $case['expected'] as $day => $expected_entry ) {
				$this->assertSame( $expected_entry, $actual[ $day ] ?? null, $case['test_condition_name'] . "（{$day}日）" );
			}
		}
	}

	/**
	 * 業種に応じて、シフトの編集画面の出力が振り分けられることを検証する。
	 */
	public function test_render_meta_box(): void {
		$this->skip_if_free_edition();

		$test_cases = array(
			array(
				'test_condition_name' => '宿泊 => 販売日の画面（fieldset・legend・一括ボタン・aria-live）で、時間帯の入力は出さない',
				'preset'              => 'lodging',
				'expected_contains'   => array( '<fieldset', '<legend', 'vkbm-shift-lodging-bulk-on', 'aria-live="polite"', 'vkbm_shift[screen_mode]" value="lodging"', 'vkbm-shift-lodging-row-template' ),
				'expected_missing'    => array( 'vkbm-shift-day-row-template', 'vkbm-shift-slot-template', 'vkbm-shift-add-slot', 'aria-describedby' ),
			),
			array(
				'test_condition_name' => '宿泊以外 => 従来の1日1行の画面（時間帯の入力あり）で、販売日の画面は出さない',
				'preset'              => 'custom',
				'expected_contains'   => array( 'vkbm-shift-day-row-template', 'vkbm-shift-slot-template', 'vkbm-shift-add-slot', 'vkbm_shift[screen_mode]" value="standard"' ),
				'expected_missing'    => array( '<fieldset', 'vkbm-shift-lodging-row-template', 'vkbm-shift-lodging-bulk-on' ),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( $case['preset'] );
			$post_id = $this->create_shift( $this->create_resource(), 2026, 10, array() );

			ob_start();
			( new Shift_Editor() )->render_meta_box( get_post( $post_id ) );
			$html = (string) ob_get_clean();

			foreach ( $case['expected_contains'] as $needle ) {
				$this->assertStringContainsString( $needle, $html, $case['test_condition_name'] . ' => 含む: ' . $needle );
			}
			foreach ( $case['expected_missing'] as $needle ) {
				$this->assertStringNotContainsString( $needle, $html, $case['test_condition_name'] . ' => 含まない: ' . $needle );
			}
		}
	}

	/**
	 * 送信された画面の種類が今の業種と一致するかの判定を検証する。
	 */
	public function test_matches_current_screen_mode(): void {
		$this->skip_if_free_edition();

		$test_cases = array(
			array(
				'test_condition_name' => '宿泊のサイトで lodging => 一致',
				'site_preset'         => 'lodging',
				'submitted'           => 'lodging',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '宿泊のサイトで standard => 不一致',
				'site_preset'         => 'lodging',
				'submitted'           => 'standard',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '宿泊のサイトで空（送られない）=> 不一致',
				'site_preset'         => 'lodging',
				'submitted'           => '',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '宿泊以外のサイトで空（従来の送信）=> 一致',
				'site_preset'         => 'custom',
				'submitted'           => '',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '宿泊以外のサイトで lodging => 不一致',
				'site_preset'         => 'custom',
				'submitted'           => 'lodging',
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( $case['site_preset'] );

			$this->assertSame( $case['expected'], Shift_Lodging_Editor::matches_current_screen_mode( $case['submitted'] ), $case['test_condition_name'] );
		}
	}
}
