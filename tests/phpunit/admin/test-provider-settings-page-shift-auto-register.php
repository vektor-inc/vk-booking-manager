<?php
/**
 * 基本設定画面の保存成功時に、シフトの自動登録がその場で実行されることのテスト
 * （Provider_Settings_Page::handle_form_submission() → Shift_Editor::run_auto_register_on_settings_saved()）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use DateTimeImmutable;
use RuntimeException;
use VKBookingManager\Admin\Provider_Settings_Page;
use VKBookingManager\PostTypes\Shift_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use VKBookingManager\Shifts\Shift_Editor;
use WP_UnitTestCase;

/**
 * 安藤レビュー指摘T1（値を変えない保存でも自動登録が動くこと）・
 * 値が変わった保存でも登録処理が二重に走らないこと（安藤レビュー指摘T1の整理）を検証する。
 *
 * @group admin
 * @group shifts
 */
class Provider_Settings_Page_Shift_Auto_Register_Test extends WP_UnitTestCase {

	private const CRON_HOOK = 'vkbm_shift_auto_register_daily';

	/**
	 * 管理者でログインし、テスト間で共有される入力値を初期化する。
	 */
	protected function setUp(): void {
		parent::setUp();
		$admin_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user_id );
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		unset( $GLOBALS['wp_settings_errors'] );
	}

	/**
	 * 設定オプション・WP-Cron の予約・リクエスト値を掃除し、他のテストへ影響しないようにする。
	 */
	protected function tearDown(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		wp_clear_scheduled_hook( self::CRON_HOOK );
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		unset( $GLOBALS['wp_settings_errors'] );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * 設定値を変えずに保存しても、自動登録が有効なら登録されることを検証する
	 * （完全に削除したシフトが作り直される。安藤レビュー指摘T1）。
	 *
	 * update_option() は保存前後で値が変わらない場合 update_option_{OPTION_KEY} フックを
	 * 発火させないため、フックだけに頼ると再現できないシナリオ。Provider_Settings_Page 経由の
	 * 保存成功時の呼び出し口（run_auto_register_on_settings_saved()）に一本化したことで、
	 * フックの発火有無に関わらず実行されることを確認する。
	 */
	public function test_handle_form_submission_runs_auto_register_even_when_value_is_unchanged(): void {
		$resource_id = $this->create_resource( 'unchanged-save' );
		$months      = $this->get_offset_months( 1 );

		// 事前状態: 有効な月数（翌月まで）を保存し、その場で今月・翌月分の公開シフトを作っておく。
		update_option( Settings_Repository::OPTION_KEY, array( 'shift_auto_register_months' => 1 ) );
		( new Shift_Editor() )->handle_auto_register();

		$existing_ids = $this->get_shift_ids_for( $resource_id, $months[0]['year'], $months[0]['month'] );
		$this->assertCount( 1, $existing_ids, '前提: 今月分が登録済み' );

		// 今月分のシフトを完全に削除する（ゴミ箱経由ではなく force delete）。
		wp_delete_post( $existing_ids[0], true );
		$this->assertSame(
			array(),
			$this->get_shift_ids_for( $resource_id, $months[0]['year'], $months[0]['month'] ),
			'前提: 完全に削除した'
		);

		// 設定値を変えずに（shift_auto_register_months=1のまま）フォームを再送信する。
		$this->submit_form( $this->create_page(), array( 'shift_auto_register_months' => '1' ) );

		$ids = $this->get_shift_ids_for( $resource_id, $months[0]['year'], $months[0]['month'] );
		$this->assertCount( 1, $ids, '値を変えずに保存しても、完全に削除された月がその場で作り直される' );
		$this->assertSame( 'publish', get_post_status( $ids[0] ) );
	}

	/**
	 * 保存が失敗した場合は、シフトの自動登録を実行しないことを検証する。
	 *
	 * Settings_Service::save_settings() が WP_Error を返すケースを、テスト用のサブクラスで
	 * 強制的に再現する（実際のバリデーションエラーの組み立てはこのテストの関心事ではないため）。
	 */
	public function test_handle_form_submission_does_not_run_auto_register_when_save_fails(): void {
		$service = new Failing_Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$editor  = new Counting_Shift_Editor();
		$page    = new Provider_Settings_Page( $service, 'manage_options', 'vkbm-shift-dashboard', $editor );

		$this->submit_form( $page, array( 'shift_auto_register_months' => '1' ) );

		$this->assertSame(
			0,
			$editor->run_auto_register_on_settings_saved_call_count,
			'保存が失敗した場合は自動登録の実行口を呼ばない'
		);
	}

	/**
	 * 自動登録が無効（0）のまま保存した場合は、何も登録されないことを検証する。
	 */
	public function test_handle_form_submission_does_not_register_shifts_when_disabled(): void {
		$resource_id = $this->create_resource( 'disabled-save' );
		$months      = $this->get_offset_months( 3 );

		$this->submit_form( $this->create_page(), array( 'shift_auto_register_months' => '0' ) );

		foreach ( $months as $offset => $month ) {
			$this->assertSame(
				array(),
				$this->get_shift_ids_for( $resource_id, $month['year'], $month['month'] ),
				'無効のまま保存しても登録されない / offset=' . $offset
			);
		}
	}

	/**
	 * 値が変わった保存でも、登録処理（handle_auto_register()）は1回だけ実行されることを検証する。
	 *
	 * その場での実行を Provider_Settings_Page 経由の呼び出し口に一本化し、
	 * handle_settings_updated()（update_option_{OPTION_KEY} フック）側では実行しないように
	 * 整理したため（安藤レビュー指摘T1）、値が変わった保存でも二重に実行されないことを、
	 * 実際にフックを登録した Shift_Editor で回数を数えて確認する。
	 */
	public function test_handle_form_submission_runs_auto_register_only_once_when_value_changes(): void {
		$editor = new Counting_Shift_Editor();
		// update_option_/add_option_{OPTION_KEY} フック（handle_settings_updated() 等）を実際に
		// 登録し、Provider_Settings_Page 経由の呼び出しと二重に実行されないことを検証できるようにする。
		$editor->register();

		// 事前状態: 変更前の設定を保存しておく（有効な値）。
		update_option( Settings_Repository::OPTION_KEY, array( 'shift_auto_register_months' => 1 ) );
		// 事前保存はフック経由のため登録処理自体は呼ばれるが、これから数える対象ではないのでリセットする。
		$editor->handle_auto_register_call_count = 0;

		$page = $this->create_page( $editor );

		// 値を変えて（1→3）フォームを送信する。update_option_{OPTION_KEY} フックも発火する。
		$this->submit_form( $page, array( 'shift_auto_register_months' => '3' ) );

		$this->assertSame(
			1,
			$editor->handle_auto_register_call_count,
			'値が変わった保存でも、登録処理（handle_auto_register()）は1回だけ実行される'
		);
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
	 * 実際の依存関係で設定画面を生成する。
	 *
	 * @param Shift_Editor|null $shift_editor 注入する Shift_Editor（未指定なら実物を新規生成）。
	 * @return Provider_Settings_Page 設定画面。
	 */
	private function create_page( ?Shift_Editor $shift_editor = null ): Provider_Settings_Page {
		$service = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );

		return new Provider_Settings_Page( $service, 'manage_options', 'vkbm-shift-dashboard', $shift_editor ?? new Shift_Editor() );
	}

	/**
	 * $_POST を組み立てて handle_form_submission() を呼び出す。
	 *
	 * handle_form_submission() は保存後に wp_safe_redirect() と exit を含むため、
	 * wp_redirect フックで例外を投げて exit の直前で処理を止め、保存結果だけを検証できるようにする
	 * （tests/phpunit/admin/test-provider-settings-page-license-key.php と同じ手法）。
	 *
	 * @param Provider_Settings_Page $page                    テスト対象の設定画面。
	 * @param array<string, mixed>   $provider_settings_payload $_POST['vkbm_provider_settings'] に渡す値。
	 */
	private function submit_form( Provider_Settings_Page $page, array $provider_settings_payload ): void {
		$_POST    = array(
			'vkbm_provider_settings_nonce' => wp_create_nonce( 'vkbm_provider_settings_save' ),
			'vkbm_provider_settings'       => $provider_settings_payload,
		);
		$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() が参照するリクエストをテスト用に同期する。

		$intercept_redirect = static function (): void {
			throw new RuntimeException( 'wp_redirect intercepted for test' );
		};
		add_filter( 'wp_redirect', $intercept_redirect );
		try {
			$page->handle_form_submission();
			$this->fail( 'handle_form_submission() はリダイレクトのため wp_redirect フックで止まる想定' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'wp_redirect intercepted for test', $exception->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', $intercept_redirect );
		}
	}
}
