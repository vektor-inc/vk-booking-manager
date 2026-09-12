<?php
/**
 * 空き状況キャッシュの世代番号（Availability_Cache_Generation）のテスト。
 *
 * シフト・サービスメニュー・スタッフ・システム設定のいずれかが保存・削除された際に
 * 世代番号が進み、キャッシュ名（build_cache_key()）へ反映されることを検証する（#410 / #412）。
 * このテストが red のときは「シフト等を保存しても予約ページの空き状況が古いまま」という
 * 不具合が再現している状態であり、green で修正が効いていることを示す。
 *
 * 世代番号の加算はリクエスト終端（shutdown）まで遅延される設計のため（安藤レビュー・HIGH
 * 是正）、各テストは「保存操作 → do_action( 'shutdown' ) で明示的にフラッシュ → 検証」の
 * 順で書く。do_action( 'shutdown' ) は本番の1リクエストの終わりに実際に発火するフックを
 * テストから明示的に鳴らしているだけで、PHPUnit プロセス自体を終了させるものではない。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Availability;

use ReflectionClass;
use VKBookingManager\Availability\Availability_Cache_Generation;
use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\PostTypes\Shift_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_UnitTestCase;

/**
 * 世代番号の管理とキャッシュキーへの反映を検証するテスト。
 *
 * @group availability
 */
class Availability_Cache_Generation_Test extends WP_UnitTestCase {

	/**
	 * 各テスト前に世代番号 option を初期状態へ戻す。
	 *
	 * bump() の「1リクエスト1回」ガード（$needs_bump）は flush_bump() 実行時に
	 * 必ず false へ戻る設計のため、明示的なリセット用メソッドは不要（前テストで
	 * flush まで済ませていれば次のテストへ持ち越されない）。
	 */
	protected function setUp(): void {
		parent::setUp();
		delete_option( Availability_Cache_Generation::OPTION_NAME );
	}

	/**
	 * 各テスト後も同様にクリーンアップする（他テストへの汚染を防ぐ）。
	 */
	protected function tearDown(): void {
		delete_option( Availability_Cache_Generation::OPTION_NAME );
		parent::tearDown();
	}

	/**
	 * bump() が予約した世代番号の加算を実行する。
	 *
	 * 本番では shutdown フックから Availability_Cache_Generation::flush_bump() が呼ばれるが、
	 * テストから素の `do_action( 'shutdown' )` を発火すると、WordPress コア側が shutdown に
	 * 登録している無関係な後処理（出力バッファのクローズ等）まで一緒に走ってしまい、
	 * PHPUnit の risky test 判定（"did not (only) close its own output buffers"）に
	 * 引っかかる。flush_bump() を直接呼ぶことで、shutdown フックへの結線自体は
	 * test_flush_bump_is_registered_on_shutdown() で別途検証しつつ、ここでは
	 * 「予約された加算が実行されること」だけを対象を絞って確認する。
	 *
	 * @return void
	 */
	private function flush_shutdown(): void {
		Availability_Cache_Generation::flush_bump();
	}

	/**
	 * Availability_Service::build_cache_key() をリフレクション経由で呼び出す。
	 *
	 * @param Availability_Service $service            対象インスタンス。
	 * @param array<int>           $staff_ids          スタッフID配列。
	 * @param bool                 $is_staff_preferred 担当スタッフ指名の有無。
	 * @return string キャッシュキー。
	 */
	private function call_build_cache_key( Availability_Service $service, array $staff_ids = array( 1, 2 ), bool $is_staff_preferred = false ): string {
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'build_cache_key' );
		$method->setAccessible( true );

		return (string) $method->invoke( $service, 'calendar', 3, $staff_ids, '2026-04', 'Asia/Tokyo', $is_staff_preferred );
	}

	/**
	 * get_generation() が option の保存値から世代番号を正しく読み取る／異常値を防御することを確認する。
	 */
	public function test_get_generation(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'option 未設定の場合 => 0（正常系・初期状態）',
				'stored_value'        => null,
				'expected'            => 0,
			),
			array(
				'test_condition_name' => 'option に数値文字列 "5" が保存されている場合 => 5（正常系）',
				'stored_value'        => '5',
				'expected'            => 5,
			),
			array(
				'test_condition_name' => 'option に負の値 -3 が保存されている場合 => 0（異常系・防御的に0扱い）',
				'stored_value'        => -3,
				'expected'            => 0,
			),
			array(
				'test_condition_name' => 'option に数値でない文字列が保存されている場合 => 0（異常系・防御的に0扱い）',
				'stored_value'        => 'not-a-number',
				'expected'            => 0,
			),
		);

		foreach ( $test_cases as $case ) {
			delete_option( Availability_Cache_Generation::OPTION_NAME );
			if ( null !== $case['stored_value'] ) {
				update_option( Availability_Cache_Generation::OPTION_NAME, $case['stored_value'] );
			}

			$actual = Availability_Cache_Generation::get_generation();
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * bump() → shutdown フラッシュで世代番号が1つ進み、オーバーフロー間際では0へ巻き戻ることを確認する。
	 */
	public function test_bump(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '初期値0から1回進める場合 => 1（正常系）',
				'initial_value'       => null,
				'expected'            => 1,
			),
			array(
				'test_condition_name' => '既に41まで進んでいる場合 => 42（正常系）',
				'initial_value'       => 41,
				'expected'            => 42,
			),
			array(
				'test_condition_name' => 'PHP_INT_MAX 手前まで進んでいる場合 => 0へ巻き戻る（異常系・オーバーフロー境界値）',
				'initial_value'       => PHP_INT_MAX - 1,
				'expected'            => 0,
			),
		);

		foreach ( $test_cases as $case ) {
			delete_option( Availability_Cache_Generation::OPTION_NAME );
			if ( null !== $case['initial_value'] ) {
				update_option( Availability_Cache_Generation::OPTION_NAME, $case['initial_value'] );
			}

			Availability_Cache_Generation::bump();
			$this->flush_shutdown();

			$this->assertSame( $case['expected'], Availability_Cache_Generation::get_generation(), $case['test_condition_name'] );
		}
	}

	/**
	 * 過剰な更新を避けるため、bump() は shutdown で書き込むまで1回しか予約されないことを確認する。
	 *
	 * シフト一括作成のように1リクエストで複数の保存フックが連続発火しても、
	 * update_option() の実書き込みは shutdown 時点で1回に抑える設計であることの検証
	 * （安藤レビュー・HIGH 是正: 全てのメタ書き込みが終わった後に1回だけ進む）。
	 */
	public function test_bump_flushes_only_once_per_shutdown(): void {
		Availability_Cache_Generation::bump();
		Availability_Cache_Generation::bump();
		Availability_Cache_Generation::bump();

		$this->assertSame( 0, Availability_Cache_Generation::get_generation(), 'shutdown 前は書き込まれない（加算は予約されているだけ）' );

		$this->flush_shutdown();
		$this->assertSame( 1, Availability_Cache_Generation::get_generation(), '1回の shutdown では、複数回 bump() を呼んでも1回しか進まない' );

		// 次のリクエスト相当（次の shutdown サイクル）では、改めて bump() を呼べば進む。
		Availability_Cache_Generation::bump();
		$this->flush_shutdown();
		$this->assertSame( 2, Availability_Cache_Generation::get_generation(), '次の shutdown サイクルでは改めて進む' );
	}

	/**
	 * bump() が、実際に WordPress コアの shutdown フックへ flush_bump() を優先度0で
	 * 登録することを確認する（安藤レビュー・HIGH 是正の結線そのものの検証）。
	 *
	 * この PR の他のテストは flush_shutdown() ヘルパーが flush_bump() を直接呼ぶ形で
	 * 「予約された加算が実行されること」だけを検証しているため（素の
	 * `do_action( 'shutdown' )` は WordPress コアの無関係な後処理まで道連れにして
	 * PHPUnit の risky test 判定に引っかかるため避けている）、
	 * 「本当に shutdown へ結線されているか」はこのテストで別途担保する。
	 *
	 * bump() は $needs_bump の値にかかわらず毎回 add_action() を呼び直す
	 * （安藤レビュー・再指摘 LOW の是正）ため、他のテストが $needs_bump を
	 * true のまま残していても（flush まで済ませ忘れていても）このテストの結果は
	 * 変わらない＝実行順に依存しない。その独立性自体は
	 * test_bump_reregisters_shutdown_hook_even_when_needs_bump_flag_is_stale() で
	 * 別途、意図的に $needs_bump を true にした状態から検証する。
	 */
	public function test_bump_registers_flush_bump_on_shutdown_hook(): void {
		Availability_Cache_Generation::bump();

		$priority = has_action( 'shutdown', array( Availability_Cache_Generation::class, 'flush_bump' ) );

		$this->assertSame( 0, $priority, 'bump() は flush_bump() を shutdown フックへ優先度0で登録する（正常系・本issueのHIGH是正の結線）' );

		// 後始末: 実際に shutdown を発火させて、以降のテストへ予約状態を持ち越さない。
		Availability_Cache_Generation::flush_bump();
	}

	/**
	 * $needs_bump が前回のリクエストの残骸で true のまま残っていても、bump() を呼べば
	 * 必ず shutdown フックへ結線し直されることを確認する（安藤レビュー・再指摘 LOW の再発防止）。
	 *
	 * FrankenPHP / RoadRunner / Swoole のようにワーカープロセスが複数リクエストを
	 * またいで生き続ける環境では、静的プロパティ（$needs_bump）はプロセスの寿命だけ
	 * 持続する一方、フック登録（$wp_filter）はリクエストごとにリセットされる。
	 * 「$needs_bump が true なら早期 return する」実装だと、この組み合わせが起きたときに
	 * add_action() が呼ばれず、そのワーカーが生きている間ずっと世代番号が進まなくなる
	 * （初回レビューで MEDIUM として指摘された問題と同じ原因が、条件を狭めて残っていた形）。
	 * ここでは reflection で $needs_bump を true にし、かつ shutdown フックを未登録の
	 * 状態にしてから bump() を呼び、それでも結線されることを確認する。
	 */
	public function test_bump_reregisters_shutdown_hook_even_when_needs_bump_flag_is_stale(): void {
		// 前回のリクエストで flush_bump() が走らなかった状態（$needs_bump が true のまま残り、
		// かつ shutdown フックは今回のリクエストではまだ登録されていない）をシミュレートする。
		$reflection = new ReflectionClass( Availability_Cache_Generation::class );
		$property   = $reflection->getProperty( 'needs_bump' );
		$property->setAccessible( true );
		$property->setValue( null, true );

		remove_action( 'shutdown', array( Availability_Cache_Generation::class, 'flush_bump' ), 0 );
		$this->assertFalse( has_action( 'shutdown', array( Availability_Cache_Generation::class, 'flush_bump' ) ), '前提: shutdown フックは未登録の状態から始める' );

		Availability_Cache_Generation::bump();

		$priority = has_action( 'shutdown', array( Availability_Cache_Generation::class, 'flush_bump' ) );
		$this->assertSame( 0, $priority, '$needs_bump が true のまま残っていても、bump() は shutdown フックへ結線し直す（正常系・LOW再発防止）' );

		// 後始末: 実際に shutdown を発火させて、以降のテストへ予約状態を持ち越さない。
		Availability_Cache_Generation::flush_bump();
	}

	/**
	 * build_cache_key() が世代番号を反映し、世代番号が変わるとキャッシュキーも変わることを確認する。
	 *
	 * これが本 issue の核心: 世代番号が変わらない限りキャッシュキーは変わらず、
	 * 世代番号が進めば同じ引数でも別のキーになり古いキャッシュが参照されなくなる。
	 */
	public function test_build_cache_key_reflects_generation(): void {
		$service = new Availability_Service();

		// 正常系: 世代番号0のときのキーを2回取得しても同じ（キャッシュとして機能する）。
		$key_before_1 = $this->call_build_cache_key( $service );
		$key_before_2 = $this->call_build_cache_key( $service );
		$this->assertSame( $key_before_1, $key_before_2, '世代番号が変わらなければ同じ引数で同じキャッシュキーになる（正常系）' );

		// 正常系: 世代番号を進めると、同じ引数でもキャッシュキーが変わる。
		Availability_Cache_Generation::bump();
		$this->flush_shutdown();
		$key_after = $this->call_build_cache_key( $service );
		$this->assertNotSame( $key_before_1, $key_after, '世代番号が進むと同じ引数でもキャッシュキーが変わる（正常系・本issueの核心）' );
	}

	/**
	 * 担当スタッフ指名の有無（$is_staff_preferred）が異なると、他の引数がすべて同じでも
	 * キャッシュキーが別物になることを確認する（安藤レビュー・MEDIUM 是正）。
	 *
	 * 指名あり（resource_id 指定）と指名なし（自動割り当て）は出力（auto_assign・
	 * 予約済み枠の扱い等）が異なるため、区別せずキャッシュすると片方の結果が
	 * もう片方へ誤って返ってしまう。
	 */
	public function test_build_cache_key_distinguishes_staff_preference(): void {
		$service = new Availability_Service();

		$test_cases = array(
			array(
				'test_condition_name' => '担当スタッフ1人だけのメニューで、指名あり／指名なしが同じ staff_ids になる場合 => 別のキャッシュキーになる（正常系・本issueのMEDIUM）',
				'staff_ids'           => array( 7 ),
			),
			array(
				'test_condition_name' => 'スタッフ複数（自動割り当て候補が2人）の場合でも => 別のキャッシュキーになる（正常系）',
				'staff_ids'           => array( 7, 8 ),
			),
		);

		foreach ( $test_cases as $case ) {
			$key_preferred     = $this->call_build_cache_key( $service, $case['staff_ids'], true );
			$key_not_preferred = $this->call_build_cache_key( $service, $case['staff_ids'], false );

			$this->assertNotSame( $key_preferred, $key_not_preferred, $case['test_condition_name'] );
		}
	}

	/**
	 * キャッシュキー（transient名）が、世代番号・指名有無を含めても WordPress の option 名上限
	 * （191文字）に収まることを確認する（境界値）。
	 *
	 * transient は内部的に `_transient_{key}` / `_transient_timeout_{key}` という option 名で
	 * 保存されるため、最も長くなる `_transient_timeout_` プレフィックス（19文字）を含めて検証する。
	 */
	public function test_build_cache_key_stays_within_option_name_length_limit(): void {
		update_option( Availability_Cache_Generation::OPTION_NAME, PHP_INT_MAX );

		$service         = new Availability_Service();
		$cache_key       = $this->call_build_cache_key( $service, array( 101, 202, 303, 404, 505 ), true );
		$option_name     = '_transient_timeout_' . $cache_key;
		$option_name_len = strlen( $option_name );

		$this->assertLessThanOrEqual( 191, $option_name_len, sprintf( 'PHP_INT_MAX の世代番号・指名フラグ付きでも option 名（%d文字）が191文字以内に収まる（境界値）', $option_name_len ) );
	}

	/**
	 * シフト投稿の新規作成・更新・削除で世代番号が進むことを確認する。
	 *
	 * `save_post_vkbm_shift` はシフト編集画面（class-shift-editor.php:290付近）・
	 * 一括作成（同:559付近）のどちらも wp_insert_post() 経由で発火するため、
	 * ここでは実際の投稿作成/更新/削除を通してフック経由の挙動を検証する。
	 */
	public function test_generation_bumps_on_shift_save_and_delete(): void {
		$this->assertSame( 0, Availability_Cache_Generation::get_generation(), '前提: 初期状態は0' );

		// 正常系: 新規作成で1つ進む。
		$shift_id = $this->factory()->post->create(
			array(
				'post_type'   => Shift_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$this->flush_shutdown();
		$this->assertSame( 1, Availability_Cache_Generation::get_generation(), 'シフト新規作成で世代番号が進む（正常系）' );

		// 正常系: 更新でさらに1つ進む。
		wp_update_post(
			array(
				'ID'         => $shift_id,
				'post_title' => 'updated shift title',
			)
		);
		$this->flush_shutdown();
		$this->assertSame( 2, Availability_Cache_Generation::get_generation(), 'シフト更新で世代番号がさらに進む（正常系）' );

		// 正常系: 削除でさらに1つ進む。
		wp_delete_post( $shift_id, true );
		$this->flush_shutdown();
		$this->assertSame( 3, Availability_Cache_Generation::get_generation(), 'シフト削除で世代番号がさらに進む（正常系）' );
	}

	/**
	 * シフトの一括作成で複数件を保存したとき、すべての meta 書き込みが終わった後に
	 * 世代番号が1回だけ進むことを確認する（安藤レビュー・HIGH の再発防止）。
	 *
	 * Shift_Editor::handle_bulk_create() はループ内で
	 * wp_insert_post() → update_post_meta()（resource/year/month/days）を繰り返す。
	 * 1件目の wp_insert_post() の時点で世代番号が進んでしまうと、2件目以降のメタが
	 * まだ書かれていない「半端な状態」のキャッシュ名が有効になってしまうため、
	 * 全件のメタ書き込みが完了した後（= shutdown 時点）で初めて1回だけ進むことを検証する。
	 */
	public function test_generation_bumps_once_after_all_shifts_in_bulk_create_are_written(): void {
		$created_ids = array();

		// handle_bulk_create() と同じ順序（wp_insert_post → update_post_meta の繰り返し）を
		// 3件ぶんシミュレートする。
		for ( $i = 0; $i < 3; $i++ ) {
			$shift_id      = $this->factory()->post->create(
				array(
					'post_type'   => Shift_Post_Type::POST_TYPE,
					'post_status' => 'draft',
				)
			);
			$created_ids[] = $shift_id;

			// shutdown 前（= まだリクエストの途中）は世代番号が進んでいないことを、
			// ループの途中でも確認する（半端な状態でキャッシュ名が変わっていないことの検証）。
			$this->assertSame( 0, Availability_Cache_Generation::get_generation(), sprintf( '%d件目の wp_insert_post() 直後もまだ世代番号は進んでいない（メタ書き込み前）', $i + 1 ) );

			update_post_meta( $shift_id, '_vkbm_shift_resource_id', 100 + $i );
			update_post_meta( $shift_id, '_vkbm_shift_year', 2026 );
			update_post_meta( $shift_id, '_vkbm_shift_month', 4 );
			update_post_meta( $shift_id, '_vkbm_shift_days', array() );
		}

		$this->assertSame( 0, Availability_Cache_Generation::get_generation(), '全件のメタ書き込みが終わった直後もまだ shutdown 前なので0のまま' );

		$this->flush_shutdown();

		$this->assertSame( 1, Availability_Cache_Generation::get_generation(), '3件作成しても shutdown 後は1回だけ進む（全メタ書き込み完了後にまとめて反映）' );
		$this->assertCount( 3, array_unique( $created_ids ), '前提: 3件とも別々の投稿として作成されている' );
	}

	/**
	 * 新規追加画面を開いただけで作られる auto-draft では世代番号が進まないことを確認する
	 * （安藤レビュー・MEDIUM 是正）。
	 */
	public function test_generation_does_not_bump_on_auto_draft(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'シフトの auto-draft 作成 => 進まない（異常系・除外対象）',
				'post_type'           => Shift_Post_Type::POST_TYPE,
			),
			array(
				'test_condition_name' => 'サービスメニューの auto-draft 作成 => 進まない（異常系・除外対象）',
				'post_type'           => Service_Menu_Post_Type::POST_TYPE,
			),
			array(
				'test_condition_name' => 'スタッフ（リソース）の auto-draft 作成 => 進まない（異常系・除外対象）',
				'post_type'           => Resource_Post_Type::POST_TYPE,
			),
		);

		foreach ( $test_cases as $case ) {
			delete_option( Availability_Cache_Generation::OPTION_NAME );

			$post_id = $this->factory()->post->create(
				array(
					'post_type'   => $case['post_type'],
					'post_status' => 'auto-draft',
				)
			);
			$this->flush_shutdown();
			$this->assertSame( 0, Availability_Cache_Generation::get_generation(), $case['test_condition_name'] . '（作成）' );

			// auto-draft の削除（wp_scheduled_auto_draft_delete の cron 相当）でも進まない。
			wp_delete_post( $post_id, true );
			$this->flush_shutdown();
			$this->assertSame( 0, Availability_Cache_Generation::get_generation(), $case['test_condition_name'] . '（削除）' );
		}
	}

	/**
	 * サービスメニュー投稿の保存で世代番号が進むことを確認する。
	 */
	public function test_generation_bumps_on_service_menu_save(): void {
		$this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$this->flush_shutdown();

		$this->assertSame( 1, Availability_Cache_Generation::get_generation(), 'サービスメニュー保存で世代番号が進む（正常系）' );
	}

	/**
	 * スタッフ（リソース）投稿の保存で世代番号が進むことを確認する。
	 */
	public function test_generation_bumps_on_staff_save(): void {
		$this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$this->flush_shutdown();

		$this->assertSame( 1, Availability_Cache_Generation::get_generation(), 'スタッフ保存で世代番号が進む（正常系）' );
	}

	/**
	 * 対象外の投稿タイプ（通常の投稿）を保存しても世代番号が進まないことを確認する（異常系・スコープ境界）。
	 */
	public function test_generation_does_not_bump_on_unrelated_post_type_save(): void {
		$this->factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);
		$this->flush_shutdown();

		$this->assertSame( 0, Availability_Cache_Generation::get_generation(), '対象外の投稿タイプの保存では世代番号は進まない（異常系）' );
	}

	/**
	 * システム設定（provider settings）の保存で世代番号が進む／実質変化がない保存では進まないことを確認する。
	 */
	public function test_generation_bumps_on_settings_save(): void {
		$repository = new Settings_Repository();

		$test_cases = array(
			array(
				'test_condition_name' => '設定値が実際に変わった場合 => 世代番号が進む（正常系）',
				'change_value'        => true,
				'expected_bumped'     => true,
			),
			array(
				'test_condition_name' => '同じ値で再保存した場合（実質変化なし） => 世代番号は進まない（異常系・過剰更新の防止）',
				'change_value'        => false,
				'expected_bumped'     => false,
			),
		);

		foreach ( $test_cases as $case ) {
			delete_option( Availability_Cache_Generation::OPTION_NAME );

			$settings = $repository->get_settings();
			// まず現在の設定値で1回保存し、以降の差分判定の基準を揃える。
			$repository->update_settings( $settings );
			$this->flush_shutdown();
			delete_option( Availability_Cache_Generation::OPTION_NAME );

			// 実際に保存されている値を読み直す（update_settings() 内部の正規化で
			// 見た目上わずかに構造が変わっても、「無変更の再保存」を正しく無変更として扱うため）。
			$settings = $repository->get_settings();

			if ( $case['change_value'] ) {
				$settings['provider_reservation_deadline_hours'] = (int) $settings['provider_reservation_deadline_hours'] + 1;
			}

			$repository->update_settings( $settings );
			$this->flush_shutdown();

			$expected = $case['expected_bumped'] ? 1 : 0;
			$this->assertSame( $expected, Availability_Cache_Generation::get_generation(), $case['test_condition_name'] );
		}
	}
}
