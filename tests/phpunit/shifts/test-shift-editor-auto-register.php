<?php
/**
 * シフトの自動登録（Shift_Editor::handle_auto_register() など）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Shifts;

use DateTimeImmutable;
use ReflectionMethod;
use VKBookingManager\PostTypes\Shift_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Shifts\Shift_Editor;
use WP_UnitTestCase;

/**
 * 対象月の算出・重複スキップ（ゴミ箱の扱いを含む）・公開登録・無効時の無処理・
 * WP-Cron の登録解除（固定時刻・排他ロックを含む）を検証する。
 * 安藤の再レビュー指摘（N2: ロックの排他制御と他の実行のロックを解放しないこと・
 * N3: 自己修復のAjaxスキップ・T2(2): 登録する月数をロック取得後に読み直すこと）への対応も含む。
 *
 * その場（同期的）での自動登録の実行は、設定画面の保存成功時
 * （Provider_Settings_Page::handle_form_submission() から Shift_Editor::
 * run_auto_register_on_settings_saved() 経由）に一本化されている（安藤レビュー指摘T1。
 * 検証は tests/phpunit/admin/test-provider-settings-page-shift-auto-register.php で行う）。
 * このファイルでは、add_option_/update_option_{OPTION_KEY} フック（handle_settings_added() /
 * handle_settings_updated()）が daily スケジュール（毎日の再実行）の管理だけを行い、その場での
 * 自動登録は行わないことを検証する。有効化・admin_init の自己修復（管理画面の表示を遅くしない
 * ための経路）は、これまでどおり WP-Cron への予約（単発実行を含む）のままにしている。
 *
 * @group shifts
 */
class Shift_Editor_Auto_Register_Test extends WP_UnitTestCase {

	private const CRON_HOOK   = 'vkbm_shift_auto_register_daily';
	private const LOCK_OPTION = 'vkbm_shift_auto_register_lock';

	/**
	 * 各テスト後に設定オプション・ロック・WP-Cron の予約を掃除し、他のテストへ影響しないようにする。
	 *
	 * 'wp_doing_ajax' / 'pre_schedule_event' は、アサーション失敗などでテスト内の
	 * remove_filter() 呼び出しまで到達しなかった場合に備えた安全策として、無条件に剥がしておく
	 * （このテストクラス以外でこれらのフックに依存する処理は無い）。
	 */
	protected function tearDown(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		delete_option( self::LOCK_OPTION );
		wp_clear_scheduled_hook( self::CRON_HOOK );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_all_filters( 'pre_schedule_event' );
		parent::tearDown();
	}

	/**
	 * 有効な月数設定ごとに、対象月の算出・公開ステータスでの新規登録・重複月のスキップ
	 * （ゴミ箱のシフトも「登録済み」として扱うことを含む）を検証する。
	 */
	public function test_handle_auto_register(): void {
		$months = $this->get_offset_months( 3 );

		$test_cases = array(
			array(
				'test_condition_name' => '「翌月まで」（1）を設定 => 今月・翌月の2件が公開ステータスで新規登録される',
				'conditions'          => array(
					'months_ahead' => 1,
					'pre_existing' => array(),
				),
				'expected_new'        => array( 0, 1 ),
			),
			array(
				'test_condition_name' => '「2ヶ月先まで」（2）を設定し翌月分が公開シフトで登録済み => 登録済みの月は重複登録されず、今月・2ヶ月先だけ新規登録される',
				'conditions'          => array(
					'months_ahead' => 2,
					// オフセット1（翌月）はあらかじめ公開シフトが登録済みという想定。
					'pre_existing' => array( 1 => 'publish' ),
				),
				'expected_new'        => array( 0, 2 ),
			),
			array(
				'test_condition_name' => '「翌月まで」（1）を設定し翌月分がゴミ箱にある => ゴミ箱のシフトも登録済みとして扱われ、重複登録されない（今月だけ新規登録）',
				'conditions'          => array(
					'months_ahead' => 1,
					// オフセット1（翌月）はゴミ箱に入っているという想定（安藤レビュー指摘M2）。
					'pre_existing' => array( 1 => 'trash' ),
				),
				'expected_new'        => array( 0 ),
			),
		);

		foreach ( $test_cases as $case ) {
			$resource_id = $this->create_resource( 'auto-register-' . wp_json_encode( $case['conditions'] ) );

			// 「登録済み」を再現する pre_existing のシフトは、設定の保存より前に作っておく。
			// 保存（update_option_/add_option_ フック）は、その場で自動登録を同期実行するように
			// なったため（司からの指示）、先に保存すると pre_existing 用の投稿とは別に、保存時点の
			// 実行で先に投稿が作られてしまい、意図した「既存1件だけ」という前提が崩れる。
			$existing_ids = array();
			foreach ( $case['conditions']['pre_existing'] as $offset => $pre_status ) {
				$month                   = $months[ $offset ];
				$existing_ids[ $offset ] = $this->create_shift( $resource_id, $month['year'], $month['month'], $pre_status );
			}

			$this->save_auto_register_setting( $case['conditions']['months_ahead'] );

			( new Shift_Editor() )->handle_auto_register();

			foreach ( $months as $offset => $month ) {
				$label = $case['test_condition_name'] . ' / offset=' . $offset;
				$ids   = $this->get_shift_ids_for( $resource_id, $month['year'], $month['month'] );

				if ( array_key_exists( $offset, $case['conditions']['pre_existing'] ) ) {
					// 登録済み（ゴミ箱を含む）の月は shift_exists() でスキップされ、
					// 既存の1件のまま重複作成されない。ステータスも変わらない。
					$this->assertSame( array( $existing_ids[ $offset ] ), $ids, $label );
					$this->assertSame(
						$case['conditions']['pre_existing'][ $offset ],
						get_post_status( $ids[0] ),
						$label . ' の既存投稿のステータスは変わらない'
					);
					continue;
				}

				if ( in_array( $offset, $case['expected_new'], true ) ) {
					$this->assertCount( 1, $ids, $label );
					$this->assertSame( 'publish', get_post_status( $ids[0] ), $label . ' の投稿ステータス' );
				} else {
					// 設定した月数の範囲外（today+months_ahead を超える月）は登録されない。
					$this->assertSame( array(), $ids, $label . ' は範囲外のため登録されない' );
				}
			}
		}
	}

	/**
	 * create_shift_posts_for_resources()（一括登録・自動登録の共通処理）を Reflection で直接検証する。
	 *
	 * - 'draft' を渡すと下書きで作成されること（安藤レビュー指摘 項目13）
	 * - $include_trash_in_dedup を false（手動の一括登録と同じ既定値）で呼んだ場合、ゴミ箱のシフトは
	 *   「登録済み」に数えず、新しい下書きが作成されること（手動の一括登録の挙動は変えない。安藤レビュー指摘M2）
	 */
	public function test_create_shift_posts_for_resources(): void {
		$months = $this->get_offset_months( 0 );
		$month  = $months[0];

		$test_cases = array(
			array(
				'test_condition_name' => "post_status に 'draft' を渡す => 下書きで作成される",
				'conditions'          => array(
					'pre_status'             => null,
					'post_status'            => 'draft',
					'include_trash_in_dedup' => false,
				),
				'expected_created'    => 1,
				'expected_skipped'    => 0,
				'expected_new_status' => 'draft',
			),
			array(
				'test_condition_name' => 'ゴミ箱のシフトが既にあり、$include_trash_in_dedup=false（手動の一括登録の既定）=> 登録済みに数えず新規作成される',
				'conditions'          => array(
					'pre_status'             => 'trash',
					'post_status'            => 'draft',
					'include_trash_in_dedup' => false,
				),
				'expected_created'    => 1,
				'expected_skipped'    => 0,
				'expected_new_status' => 'draft',
			),
			array(
				'test_condition_name' => 'ゴミ箱のシフトが既にあり、$include_trash_in_dedup=true（自動登録）=> 登録済みとして数えられスキップされる',
				'conditions'          => array(
					'pre_status'             => 'trash',
					'post_status'            => 'publish',
					'include_trash_in_dedup' => true,
				),
				'expected_created'    => 0,
				'expected_skipped'    => 1,
				'expected_new_status' => null,
			),
		);

		foreach ( $test_cases as $case ) {
			$resource_id = $this->create_resource( 'create-shift-posts-' . wp_json_encode( $case['conditions'] ) );

			$existing_id = null;
			if ( null !== $case['conditions']['pre_status'] ) {
				$existing_id = $this->create_shift( $resource_id, $month['year'], $month['month'], $case['conditions']['pre_status'] );
			}

			$resource_post = get_post( $resource_id );
			$method        = new ReflectionMethod( Shift_Editor::class, 'create_shift_posts_for_resources' );
			$method->setAccessible( true );
			$result = $method->invoke(
				new Shift_Editor(),
				array( $resource_post ),
				$month['year'],
				$month['month'],
				$case['conditions']['post_status'],
				$case['conditions']['include_trash_in_dedup']
			);

			$this->assertSame( $case['expected_created'], $result['created'], $case['test_condition_name'] . ' / created' );
			$this->assertSame( $case['expected_skipped'], $result['skipped'], $case['test_condition_name'] . ' / skipped' );

			$ids = $this->get_shift_ids_for( $resource_id, $month['year'], $month['month'] );

			if ( null !== $case['expected_new_status'] ) {
				// 既存（あれば）＋新規の合計件数と、新規作成分のステータスを検証する。
				$new_ids = null === $existing_id ? $ids : array_values( array_diff( $ids, array( $existing_id ) ) );
				$this->assertCount( 1, $new_ids, $case['test_condition_name'] . ' / 新規作成された投稿の件数' );
				$this->assertSame( $case['expected_new_status'], get_post_status( $new_ids[0] ), $case['test_condition_name'] . ' / 新規作成された投稿のステータス' );
			} else {
				// 新規作成されないケース: 既存の1件のみが残る。
				$this->assertSame( array( $existing_id ), $ids, $case['test_condition_name'] );
			}
		}
	}

	/**
	 * 自動登録が無効な場合（未保存の既定値・明示的に0を保存）は何も登録しないことを検証する。
	 */
	public function test_handle_auto_register_does_nothing_when_disabled(): void {
		$months = $this->get_offset_months( 3 );

		$test_cases = array(
			array(
				'test_condition_name' => '設定が未保存（既定値=無効） => 何も登録しない',
				'conditions'          => array( 'save' => false ),
			),
			array(
				'test_condition_name' => '明示的に無効（0）を保存 => 何も登録しない',
				'conditions'          => array(
					'save'  => true,
					'value' => 0,
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$resource_id = $this->create_resource( 'auto-register-disabled-' . wp_json_encode( $case['conditions'] ) );

			delete_option( Settings_Repository::OPTION_KEY );
			if ( $case['conditions']['save'] ) {
				$this->save_auto_register_setting( (int) $case['conditions']['value'] );
			}

			( new Shift_Editor() )->handle_auto_register();

			foreach ( $months as $month ) {
				$ids = $this->get_shift_ids_for( $resource_id, $month['year'], $month['month'] );
				$this->assertSame( array(), $ids, $case['test_condition_name'] );
			}
		}
	}

	/**
	 * 設定の保存（add_option_/update_option_ フック）で daily スケジュールが自動的に登録・解除
	 * されることを検証する。daily の繰り返しスケジュールは固定時刻（サイトのタイムゾーンで
	 * 0:05）で登録される（安藤レビュー指摘 項目5・6）。
	 *
	 * handle_settings_added() / handle_settings_updated() が呼ぶ ensure_daily_schedule() は
	 * daily スケジュールの登録・解除だけを行い、単発実行の予約は行わない（単発実行の予約は
	 * ensure_auto_register_schedule() が daily スケジュールを新規登録した場合のみ行う。
	 * プラグイン有効化時・admin_init の自己修復から呼ばれる経路）ため、保存直後に単発実行が
	 * 予約されないことも合わせて確認する。
	 */
	public function test_daily_schedule_is_registered_and_cleared_by_saving_settings(): void {
		$this->assertFalse( wp_next_scheduled( self::CRON_HOOK ), '前提: 初期状態では未予約' );

		$resource_id = $this->create_resource( 'daily-schedule-only' );
		$months      = $this->get_offset_months( 1 );

		// 有効な月数を保存すると、update_option_{option}（初回保存時は add_option_{option}）フック
		// 経由で自動的にスケジュールされる（明示的に ensure_daily_schedule() 等を呼ばなくてよい）。
		$this->save_auto_register_setting( 1 );

		// 設定画面を経由しない保存（この update_option() 呼び出し）では daily スケジュールの管理
		// だけが行われ、その場での自動登録は実行されないことを確認する（安藤レビュー指摘T1対応の
		// 整理。その場での自動登録は Provider_Settings_Page 経由の保存成功時に一本化されている）。
		foreach ( $months as $offset => $month ) {
			$this->assertSame(
				array(),
				$this->get_shift_ids_for( $resource_id, $month['year'], $month['month'] ),
				'設定画面を経由しない保存では、daily スケジュールの管理のみ行われ、シフトはその場で登録されない / offset=' . $offset
			);
		}

		$events    = $this->get_cron_events_for_hook();
		$recurring = array_values(
			array_filter(
				$events,
				static function ( array $event ): bool {
					return 'daily' === $event['schedule'];
				}
			)
		);
		$single    = array_values(
			array_filter(
				$events,
				static function ( array $event ): bool {
					return false === $event['schedule'];
				}
			)
		);

		$this->assertCount( 1, $recurring, 'daily の繰り返しスケジュールが1件登録される' );
		$this->assertSame(
			array(),
			$single,
			'daily スケジュールの管理（ensure_daily_schedule()）だけでは単発実行は予約されない'
		);

		$next_run_date = ( new DateTimeImmutable( '@' . $recurring[0]['timestamp'] ) )->setTimezone( wp_timezone() );
		$this->assertSame( '00:05', $next_run_date->format( 'H:i' ), 'daily スケジュールの実行時刻はサイトのタイムゾーンで 0:05 に固定されている' );

		// 既にスケジュール済みの場合は、明示的に呼んでも再登録しない（同一の予約時刻を維持する）。
		( new Shift_Editor() )->ensure_auto_register_schedule();
		$this->assertSame( $recurring[0]['timestamp'], $this->get_recurring_schedule_timestamp(), '既にスケジュール済みなら再登録しない' );

		// 「無効」に変更すると、保存だけで自動的にスケジュールが解除される。
		$this->save_auto_register_setting( 0 );
		$this->assertFalse( wp_next_scheduled( self::CRON_HOOK ), '無効に変更すると自動でスケジュールが解除される' );
	}

	/**
	 * ensure_auto_register_schedule()（プラグイン有効化時・admin_init の自己修復から呼ばれる）が、
	 * 新規に daily スケジュールを登録した場合は、これまでどおり反映用の単発実行も1件予約すること
	 * を検証する。管理画面の表示を遅くしないよう、この経路では同期実行しない（司からの指示）。
	 */
	public function test_ensure_auto_register_schedule_still_schedules_single_event_for_activation_and_self_heal(): void {
		$this->save_auto_register_setting( 1 );
		wp_clear_scheduled_hook( self::CRON_HOOK );
		$this->assertFalse( wp_next_scheduled( self::CRON_HOOK ), '前提: 未予約' );

		( new Shift_Editor() )->ensure_auto_register_schedule();

		$events    = $this->get_cron_events_for_hook();
		$recurring = array_values(
			array_filter(
				$events,
				static function ( array $event ): bool {
					return 'daily' === $event['schedule'];
				}
			)
		);
		$single    = array_values(
			array_filter(
				$events,
				static function ( array $event ): bool {
					return false === $event['schedule'];
				}
			)
		);

		$this->assertCount( 1, $recurring, '有効化・自己修復では daily スケジュールが登録される' );
		$this->assertCount( 1, $single, '有効化・自己修復では、その場で実行せず反映用の単発実行を1件予約する' );
	}

	/**
	 * clear_auto_register_schedule()（プラグイン無効化時に使用）が予約済みイベントを解除することを検証する。
	 */
	public function test_clear_auto_register_schedule(): void {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		$this->assertNotFalse( wp_next_scheduled( self::CRON_HOOK ), '前提: スケジュール済み' );

		( new Shift_Editor() )->clear_auto_register_schedule();

		$this->assertFalse( wp_next_scheduled( self::CRON_HOOK ) );
	}

	/**
	 * 自動登録の実行ロック（多重実行の排他制御）を検証する（安藤レビュー指摘 項目7）。
	 *
	 * - 有効なロックが既にある場合は実行されない
	 * - タイムアウトを超えた古いロックは、前回の実行が異常終了したものとみなして奪い取り実行する
	 */
	public function test_handle_auto_register_lock(): void {
		$months = $this->get_offset_months( 0 );
		$month  = $months[0];

		$test_cases = array(
			array(
				'test_condition_name' => '有効なロックが既にある場合 => 実行されず、シフトは作成されない',
				'lock_seconds_ago'    => 10,
				'expect_created'      => false,
			),
			array(
				'test_condition_name' => 'タイムアウト（300秒）を超えた古いロックの場合 => ロックを奪い取って実行される',
				'lock_seconds_ago'    => 301,
				'expect_created'      => true,
			),
		);

		foreach ( $test_cases as $case ) {
			$resource_id = $this->create_resource( 'lock-' . wp_json_encode( $case ) );

			// ロックは設定の保存より前に用意しておく。保存（update_option_/add_option_ フック）は
			// その場で自動登録を同期実行するようになったため（司からの指示）、後からロックを
			// 置くと、保存時点の実行がロックの影響を受けずに先に投稿を作ってしまい、この
			// テストが検証したい「ロックの有無による実行可否」を保存時点の実行と明示呼び出しの
			// 両方に一貫して適用できなくなる。
			add_option( self::LOCK_OPTION, time() - $case['lock_seconds_ago'], '', false );
			$this->save_auto_register_setting( 1 );

			( new Shift_Editor() )->handle_auto_register();

			$ids = $this->get_shift_ids_for( $resource_id, $month['year'], $month['month'] );

			if ( $case['expect_created'] ) {
				$this->assertCount( 1, $ids, $case['test_condition_name'] );
			} else {
				$this->assertSame( array(), $ids, $case['test_condition_name'] );
			}

			delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * release_auto_register_lock() が、取得時に保存した値と現在の値が異なる場合は削除しないことを
	 * 検証する（安藤レビュー指摘N2）。タイムアウトを超えたロックを奪い取った別の実行が既に
	 * いる状態で、こちらの finally が誤ってその新しいロックを解放しないことを保証する。
	 */
	public function test_release_auto_register_lock_does_not_release_other_executions_lock(): void {
		// 「他の実行」が取得済みのロックを想定して保存する（値は取得時刻:ランダム文字列の形式）。
		$other_execution_value = time() . ':other-execution-token';
		add_option( self::LOCK_OPTION, $other_execution_value, '', false );

		$method = new ReflectionMethod( Shift_Editor::class, 'release_auto_register_lock' );
		$method->setAccessible( true );

		// 自分が取得したと仮定する値（実際に保存されている「他の実行」の値とは異なる）で解放を試みる。
		$method->invoke( new Shift_Editor(), $other_execution_value . '-not-mine' );

		$this->assertSame(
			$other_execution_value,
			get_option( self::LOCK_OPTION ),
			'一致しない値では、他の実行が保持しているロックを解放しない'
		);

		// 対称性の確認: 一致する値であれば解放できる。
		$method->invoke( new Shift_Editor(), $other_execution_value );
		$this->assertFalse( get_option( self::LOCK_OPTION ), '一致する値であれば解放される' );
	}

	/**
	 * release_auto_register_lock() が、比較と削除を1本の SQL（DELETE ... WHERE option_value = %s）で
	 * 行うことを検証する（安藤レビュー指摘R1）。get_option() で読んでから削除する2段階の実装だと、
	 * 取得成功時の update_option() が書き込んだ個別のオプションキャッシュが残ったままになり、
	 * 他の実行が直接クエリで値を書き換えていてもキャッシュ越しには「一致」と誤判定してしまう。
	 * この誤判定が起きないことを、DB の実値を直接書き換えて確認する。
	 */
	public function test_release_auto_register_lock_does_not_trust_stale_option_cache(): void {
		global $wpdb;

		$acquire_method = new ReflectionMethod( Shift_Editor::class, 'acquire_auto_register_lock' );
		$acquire_method->setAccessible( true );
		$release_method = new ReflectionMethod( Shift_Editor::class, 'release_auto_register_lock' );
		$release_method->setAccessible( true );

		$editor         = new Shift_Editor();
		$own_lock_value = $acquire_method->invoke( $editor );
		$this->assertNotSame( '', $own_lock_value, '前提: ロックを取得できている' );
		// 取得成功時の update_option() により、個別のオプションキャッシュに own_lock_value が乗る。

		// 「他の実行」がこのロック行を別の値へ書き換えた状況を、update_option() を経由せず
		// 直接クエリで再現する（キャッシュには反映されない）。
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- テストで「他の実行による書き換え」を再現するため直接クエリが必要。
		$wpdb->update(
			$wpdb->options,
			array( 'option_value' => 'other-execution-value' ),
			array( 'option_name' => self::LOCK_OPTION )
		);

		// 自分が取得した（もう有効ではなくなった）値で解放を試みる。
		$release_method->invoke( $editor, $own_lock_value );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- テストで DB の実値を直接検証するため直接クエリが必要。
		$current_db_value = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION )
		);

		$this->assertSame(
			'other-execution-value',
			$current_db_value,
			'他の実行が書き換えたロック行は、個別オプションキャッシュに惑わされず削除されない'
		);
	}

	/**
	 * acquire_auto_register_lock() が、既存のロック行はあるのに値が読めない（空文字）場合に、
	 * 期限切れの奪い取りを試みず、再帰・再試行せずその場で取得失敗（空文字列）として
	 * 抜けることを検証する（安藤レビュー指摘R2）。DB への書き込みが失敗し続ける状況を
	 * 再現できないため、「値が読めない」状態を直接作って確認する。ループ試行回数が
	 * 最大2回に制限されていることの裏付けにもなる（無限ループ・無限再帰であれば
	 * このテスト自体がタイムアウトする）。
	 */
	public function test_acquire_auto_register_lock_returns_empty_string_when_existing_value_is_unreadable(): void {
		global $wpdb;

		// ロック行は存在するが値が空（何らかの理由で読み取れない）状況を、直接クエリで再現する。
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- テストで疑似的に不正な状態を作るため直接クエリが必要。
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				self::LOCK_OPTION,
				''
			)
		);

		$method = new ReflectionMethod( Shift_Editor::class, 'acquire_auto_register_lock' );
		$method->setAccessible( true );

		$result = $method->invoke( new Shift_Editor() );

		$this->assertSame( '', $result, '値が読めない場合は奪い取りを試みず、取得失敗（空文字）で抜ける' );
	}

	/**
	 * add_option_/update_option_{OPTION_KEY} フック（handle_settings_added() /
	 * handle_settings_updated()）が、daily スケジュール（毎日の再実行）の管理だけを行い、
	 * その場（同期的）での自動登録は行わないことを検証する（安藤レビュー指摘T1対応の整理）。
	 *
	 * 値を変えた保存・変えない保存（他の項目だけの変更）・無効→有効・無効のまま、いずれの
	 * パターンでも、この2つのフック経由ではシフトが作られないこと、daily スケジュールだけは
	 * 現在の設定値に追随することを確認する。その場での自動登録（run_auto_register_on_settings_saved()
	 * 経由）の検証は tests/phpunit/admin/test-provider-settings-page-shift-auto-register.php で行う。
	 */
	public function test_handle_settings_added_and_updated_only_manage_daily_schedule(): void {
		$months = $this->get_offset_months( 3 );

		$test_cases = array(
			array(
				'test_condition_name' => '初回保存（add_option_ フック）で有効な値',
				'initial_settings'    => null,
				'saved_settings'      => array( 'shift_auto_register_months' => 1 ),
				'expect_daily'        => true,
			),
			array(
				'test_condition_name' => '値が変わる保存（1→3。update_option_ フック）',
				'initial_settings'    => array( 'shift_auto_register_months' => 1 ),
				'saved_settings'      => array( 'shift_auto_register_months' => 3 ),
				'expect_daily'        => true,
			),
			array(
				// shift_auto_register_months は変わらないが他の項目が変わるケース。
				// update_option() は配列全体で差分判定するため、この場合も update_option_
				// フック自体は発火する（＝WP側の「値が同じなら発火しない」短絡には頼れない）。
				'test_condition_name' => '自動登録の月数は同じで他の項目だけ変更（update_option_ フック）',
				'initial_settings'    => array(
					'shift_auto_register_months' => 1,
					'provider_name'              => 'A',
				),
				'saved_settings'      => array(
					'shift_auto_register_months' => 1,
					'provider_name'              => 'B',
				),
				'expect_daily'        => true,
			),
			array(
				'test_condition_name' => '無効から有効への変更（update_option_ フック）',
				'initial_settings'    => array( 'shift_auto_register_months' => 0 ),
				'saved_settings'      => array( 'shift_auto_register_months' => 1 ),
				'expect_daily'        => true,
			),
			array(
				'test_condition_name' => '無効のまま他の項目だけ変更（update_option_ フック）',
				'initial_settings'    => array( 'shift_auto_register_months' => 0 ),
				'saved_settings'      => array(
					'shift_auto_register_months' => 0,
					'provider_name'              => 'Changed',
				),
				'expect_daily'        => false,
			),
		);

		foreach ( $test_cases as $case ) {
			delete_option( Settings_Repository::OPTION_KEY );
			wp_clear_scheduled_hook( self::CRON_HOOK );
			$resource_id = $this->create_resource( 'schedule-only-' . wp_json_encode( $case['saved_settings'] ) );

			if ( null !== $case['initial_settings'] ) {
				update_option( Settings_Repository::OPTION_KEY, $case['initial_settings'] );
			}

			// 対象の保存（初回は add_option_、既存の場合は update_option_ フックが発火する）。
			update_option( Settings_Repository::OPTION_KEY, $case['saved_settings'] );

			foreach ( $months as $offset => $month ) {
				$this->assertSame(
					array(),
					$this->get_shift_ids_for( $resource_id, $month['year'], $month['month'] ),
					$case['test_condition_name'] . ' / offset=' . $offset . ' : フック経由の保存ではその場で登録されない'
				);
			}

			if ( $case['expect_daily'] ) {
				$this->assertNotFalse( wp_next_scheduled( self::CRON_HOOK ), $case['test_condition_name'] . ' : daily スケジュールが登録される' );
			} else {
				$this->assertFalse( wp_next_scheduled( self::CRON_HOOK ), $case['test_condition_name'] . ' : daily スケジュールは登録されない' );
			}
		}
	}

	/**
	 * get_provider_settings() のリクエスト内キャッシュ（static）が、handle_auto_register() の
	 * 実行のたびに保存後の最新値へ読み直されることを検証する（司からの指摘: 保存前の古い値の
	 * まま動かさない）。
	 *
	 * その場での自動登録の実行は Provider_Settings_Page 経由の保存成功時に一本化されているため
	 * （安藤レビュー指摘T1）、update_option_{OPTION_KEY} フック経由での同期実行はもう発生しない。
	 * ここでは handle_auto_register() 自体（WP-Cron・run_auto_register_on_settings_saved() の
	 * どちらから呼ばれても共通の処理）を直接呼び出し、キャッシュ読み直しの挙動を検証する。
	 */
	public function test_get_provider_settings_cache_is_refreshed_before_auto_register_runs(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		update_option(
			Settings_Repository::OPTION_KEY,
			array(
				'shift_auto_register_months' => 0,
				'provider_name'              => 'Old Name',
			)
		);

		$get_settings_method = new ReflectionMethod( Shift_Editor::class, 'get_provider_settings' );
		$get_settings_method->setAccessible( true );

		$editor = new Shift_Editor();
		// 保存前に一度呼び出し、古い値でキャッシュを温めておく（保存前に既に呼ばれていた状況を再現）。
		// get_provider_settings() の static キャッシュは PHP プロセス単位（このテストの前に実行された
		// 別のテストが残した値が入っている可能性がある）で持ち越されるため、ここでは
		// force_refresh=true を渡して、このテストで保存した 'Old Name' を確実に読み直してから
		// キャッシュに乗せる。
		$warmed = $get_settings_method->invoke( $editor, true );
		$this->assertSame( 'Old Name', $warmed['provider_name'], '前提: 古い値でキャッシュされている' );

		// 新しい値を保存する（この update_option() 自体は、その場での自動登録を実行しない）。
		update_option(
			Settings_Repository::OPTION_KEY,
			array(
				'shift_auto_register_months' => 1,
				'provider_name'              => 'New Name',
			)
		);

		// handle_auto_register() を明示的に呼び出し、キャッシュが最新値へ読み直されることを確認する。
		$editor->handle_auto_register();

		$refreshed = $get_settings_method->invoke( $editor );
		$this->assertSame(
			'New Name',
			$refreshed['provider_name'],
			'handle_auto_register() の実行のたびに、古いキャッシュではなく新しい設定値が読まれる'
		);
	}

	/**
	 * self_heal_auto_register_schedule()（admin_init の自己修復コールバック）が、Ajax リクエスト
	 * （wp_doing_ajax()）では ensure_auto_register_schedule() を呼ばないことを検証する
	 * （安藤レビュー指摘N3: Heartbeat（admin-ajax.php）のたびに get_settings() が走る問題）。
	 */
	public function test_self_heal_auto_register_schedule_skips_during_ajax(): void {
		$this->save_auto_register_setting( 1 );
		wp_clear_scheduled_hook( self::CRON_HOOK );
		$this->assertFalse( wp_next_scheduled( self::CRON_HOOK ), '前提: スケジュール未登録' );

		add_filter( 'wp_doing_ajax', '__return_true' );
		( new Shift_Editor() )->self_heal_auto_register_schedule();
		remove_filter( 'wp_doing_ajax', '__return_true' );

		$this->assertFalse(
			wp_next_scheduled( self::CRON_HOOK ),
			'Ajax リクエストでは自己修復（ensure_auto_register_schedule()）が呼ばれず、スケジュールされない'
		);

		// 対称性の確認: Ajax でなければ通常どおり自己修復される。
		( new Shift_Editor() )->self_heal_auto_register_schedule();
		$this->assertNotFalse( wp_next_scheduled( self::CRON_HOOK ), 'Ajax でなければ自己修復でスケジュールされる' );
	}

	/**
	 * 今月から指定オフセット数分の年月配列を返す（0=今月, 1=翌月, ...）。
	 * Shift_Editor::handle_auto_register() と同じ基準（wp_timezone() の「今日」）で算出する。
	 *
	 * @param int $max_offset 最大オフセット.
	 * @return array<int, array{year:int, month:int}>
	 */
	private function get_offset_months( int $max_offset ): array {
		$timezone = wp_timezone();
		$now      = new DateTimeImmutable( 'now', $timezone );
		$base     = $now->setDate( (int) $now->format( 'Y' ), (int) $now->format( 'n' ), 1 );

		$months = array();
		for ( $offset = 0; $offset <= $max_offset; $offset++ ) {
			$target            = $base->modify( sprintf( '+%d month', $offset ) );
			$months[ $offset ] = array(
				'year'  => (int) $target->format( 'Y' ),
				'month' => (int) $target->format( 'n' ),
			);
		}

		return $months;
	}

	/**
	 * 公開済みのリソース（スタッフ）投稿を作成する。
	 *
	 * @param string $title リソース名.
	 * @return int リソース投稿ID.
	 */
	private function create_resource( string $title ): int {
		return (int) $this->factory()->post->create(
			array(
				'post_type'   => 'vkbm_resource',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
	}

	/**
	 * シフト投稿を作成し、リソース・年・月のメタを付与する。
	 *
	 * @param int    $resource_id リソースID.
	 * @param int    $year        年.
	 * @param int    $month       月.
	 * @param string $post_status 投稿ステータス.
	 * @return int シフト投稿ID.
	 */
	private function create_shift( int $resource_id, int $year, int $month, string $post_status ): int {
		$id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Shift_Post_Type::POST_TYPE,
				'post_status' => $post_status,
			)
		);
		update_post_meta( $id, Shift_Editor::META_RESOURCE, $resource_id );
		update_post_meta( $id, Shift_Editor::META_YEAR, $year );
		update_post_meta( $id, Shift_Editor::META_MONTH, $month );

		return $id;
	}

	/**
	 * 指定リソース・年月のシフト投稿ID一覧を取得する（ステータス問わず）。
	 *
	 * @param int $resource_id リソースID.
	 * @param int $year        年.
	 * @param int $month       月.
	 * @return array<int, int>
	 */
	private function get_shift_ids_for( int $resource_id, int $year, int $month ): array {
		$ids = get_posts(
			array(
				'post_type'      => Shift_Post_Type::POST_TYPE,
				// ゴミ箱のシフトも検証対象にするため 'trash' も含める（安藤レビュー指摘M2のテスト用）。
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'trash' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => array(
					array(
						'key'     => Shift_Editor::META_RESOURCE,
						'value'   => $resource_id,
						'compare' => '=',
					),
					array(
						'key'     => Shift_Editor::META_YEAR,
						'value'   => $year,
						'compare' => '=',
					),
					array(
						'key'     => Shift_Editor::META_MONTH,
						'value'   => $month,
						'compare' => '=',
					),
				),
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * shift_auto_register_months 設定を保存する。
	 *
	 * @param int $value 保存する月数.
	 */
	private function save_auto_register_setting( int $value ): void {
		update_option(
			Settings_Repository::OPTION_KEY,
			array( 'shift_auto_register_months' => $value )
		);
	}

	/**
	 * 自動登録フックに登録されている WP-Cron イベント一覧を取得する（単発・繰り返しの両方）。
	 *
	 * @return array<int, array{timestamp:int, schedule:string|false}>
	 */
	private function get_cron_events_for_hook(): array {
		$cron   = _get_cron_array();
		$events = array();

		if ( ! is_array( $cron ) ) {
			return $events;
		}

		foreach ( $cron as $timestamp => $hooks ) {
			if ( ! isset( $hooks[ self::CRON_HOOK ] ) ) {
				continue;
			}

			foreach ( $hooks[ self::CRON_HOOK ] as $event ) {
				$events[] = array(
					'timestamp' => (int) $timestamp,
					'schedule'  => $event['schedule'] ?? false,
				);
			}
		}

		return $events;
	}

	/**
	 * 自動登録フックの daily 繰り返しスケジュールのタイムスタンプを取得する（無ければ null）。
	 *
	 * @return int|null
	 */
	private function get_recurring_schedule_timestamp(): ?int {
		foreach ( $this->get_cron_events_for_hook() as $event ) {
			if ( 'daily' === $event['schedule'] ) {
				return $event['timestamp'];
			}
		}

		return null;
	}
}
