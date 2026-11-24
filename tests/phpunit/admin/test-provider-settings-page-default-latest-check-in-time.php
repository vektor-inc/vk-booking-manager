<?php
/**
 * 基本設定画面の「最終チェックイン時刻（宿泊プラン）」の行と、保存後の注意（#1136）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use ReflectionMethod;
use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Admin\Provider_Settings_Page;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use WP_UnitTestCase;

/**
 * 最終チェックイン時刻の行の位置・選択状態、入力エラー時の再表示、保存後の注意を検証する。
 *
 * @group admin
 * @group lodging
 */
class Provider_Settings_Page_Default_Latest_Check_In_Time_Test extends WP_UnitTestCase {

	/**
	 * 管理者でログインする。
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '業種プリセット（宿泊）は Pro 版限定のため、無料版ではスキップする。' );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * 保存済みの設定・クエリ文字列・設定エラー・キャッシュを掃除する。
	 */
	protected function tearDown(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		Industry_Presets::clear_cache();
		$_GET                          = array();
		$GLOBALS['wp_settings_errors'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- テスト間で設定エラーの蓄積を掃除する。
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * 業種と最終チェックイン時刻を保存する。
	 *
	 * @param string $industry 業種プリセットのキー。
	 * @param string $time     最終チェックイン時刻（HH:MM または空文字）。
	 */
	private function save_settings( string $industry, string $time ): void {
		update_option(
			Settings_Repository::OPTION_KEY,
			array_merge(
				( new Settings_Repository() )->get_default_settings(),
				array(
					'industry_preset' => $industry,
					Settings_Repository::KEY_LODGING_LATEST_CHECK_IN_TIME => $time,
				)
			)
		);
		Industry_Presets::clear_cache();
	}

	/**
	 * 設定画面を作る。
	 *
	 * @return Provider_Settings_Page
	 */
	private function create_page(): Provider_Settings_Page {
		return new Provider_Settings_Page( new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() ) );
	}

	/**
	 * システム設定タブを描画して HTML を返す。
	 *
	 * @return string 描画結果の HTML。
	 */
	private function render_system_tab(): string {
		$page = $this->create_page();

		$_GET = array( 'tab' => 'system' );
		ob_start();
		$page->render_page();
		$html = (string) ob_get_clean();
		$_GET = array();

		return $html;
	}

	/**
	 * 描画結果から、指定した id のセレクトの開始タグと選ばれている値を取り出す。
	 *
	 * @param string $html 描画結果。
	 * @param string $id   セレクトの id 属性。
	 * @return array{tag: string, values: array<int, string>, selected: array<int, string>} 開始タグ・選択肢の値・選ばれている値。
	 */
	private function parse_select( string $html, string $id ): array {
		$matched = preg_match( '/(<select\s+id="' . preg_quote( $id, '/' ) . '"[^>]*>)(.*?)<\/select>/s', $html, $matches );
		$this->assertSame( 1, $matched, $id . ' のセレクトが描画されること' );

		preg_match_all( '/<option value="([^"]*)"\s*([^>]*)>/', $matches[2], $options, PREG_SET_ORDER );
		$values   = array();
		$selected = array();
		foreach ( $options as $option ) {
			$values[] = $option[1];
			if ( str_contains( $option[2], 'selected' ) ) {
				$selected[] = $option[1];
			}
		}

		return array(
			'tag'      => $matches[1],
			'values'   => $values,
			'selected' => $selected,
		);
	}

	/**
	 * render_page: 宿泊のサイトのシステム設定タブで、「休前日・特定日」の行の直後に最終チェックイン時刻の行を出し、保存値を選ぶ。
	 * 宿泊を使わないサイトでは出さない。
	 */
	public function test_render_page(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '宿泊・未設定 => 「設定しない」が選ばれ、分は無効',
				'industry'            => 'lodging',
				'time'                => '',
				'expected'            => array(
					'row'             => true,
					'hour_selected'   => array( '' ),
					'minute_values'   => array( '00', '30' ),
					'minute_selected' => array(),
					'minute_disabled' => true,
				),
			),
			array(
				'test_condition_name' => '宿泊・20:30 => 20 時 30 分が選ばれ、分は有効',
				'industry'            => 'lodging',
				'time'                => '20:30',
				'expected'            => array(
					'row'             => true,
					'hour_selected'   => array( '20' ),
					'minute_values'   => array( '00', '30' ),
					'minute_selected' => array( '30' ),
					'minute_disabled' => false,
				),
			),
			array(
				'test_condition_name' => '宿泊・REST で書き込んだ 20:15 => 書き換えずに 15 分を選択肢に加えて選ぶ',
				'industry'            => 'lodging',
				'time'                => '20:15',
				'expected'            => array(
					'row'             => true,
					'hour_selected'   => array( '20' ),
					'minute_values'   => array( '00', '15', '30' ),
					'minute_selected' => array( '15' ),
					'minute_disabled' => false,
				),
			),
			array(
				'test_condition_name' => '宿泊を使わない業種 => 行を出さない',
				'industry'            => 'custom',
				'time'                => '20:00',
				'expected'            => array( 'row' => false ),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->save_settings( $case['industry'], $case['time'] );
			$html = $this->render_system_tab();

			$this->assertSame( $case['expected']['row'], str_contains( $html, 'id="vkbm-default-latest-check-in-time"' ), $case['test_condition_name'] . '（行の有無）' );
			if ( ! $case['expected']['row'] ) {
				continue;
			}

			// 「休前日・特定日」の行の直後に置く。
			$this->assertMatchesRegularExpression(
				'/id="vkbm-lodging-rate-row".*?<\/tr>\s*<tr class="vkbm-provider-settings__tab-system" id="vkbm-default-latest-check-in-time">/s',
				$html,
				$case['test_condition_name'] . '（休前日・特定日の直後）'
			);
			$this->assertStringContainsString( esc_html__( 'Latest check-in time (lodging plans)', 'vk-booking-manager' ), $html, $case['test_condition_name'] . '（ラベル）' );
			$this->assertStringContainsString( 'class="vkbm-provider-settings__label--keep-words"', $html, $case['test_condition_name'] . '（ラベルを語の途中で割らない）' );
			foreach ( array(
				'On the booking screen, the estimated check-in time can be chosen every 30 minutes from the check-in time of each plan up to this time.',
				'If the latest check-in time is set on the edit screen of a plan, that setting takes priority.',
				'If neither is set, times up to 23:30 can be chosen.',
			) as $sentence ) {
				$this->assertStringContainsString( esc_html( __( $sentence, 'vk-booking-manager' ) ), $html, $case['test_condition_name'] . '（説明文）' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- 画面と同じ原文を翻訳して比べるため。
			}

			$hour   = $this->parse_select( $html, 'vkbm-default-latest-check-in-hour' );
			$minute = $this->parse_select( $html, 'vkbm-default-latest-check-in-minute' );

			$this->assertStringContainsString( 'name="vkbm_provider_settings[lodging_latest_check_in_hour]"', $hour['tag'], $case['test_condition_name'] . '（時の送信名）' );
			$this->assertStringContainsString( 'name="vkbm_provider_settings[lodging_latest_check_in_minute]"', $minute['tag'], $case['test_condition_name'] . '（分の送信名）' );
			$this->assertStringContainsString( 'data-vkbm-minute-target="#vkbm-default-latest-check-in-minute"', $hour['tag'], $case['test_condition_name'] . '（分の無効を切り替える対象）' );
			$this->assertSame( 25, count( $hour['values'] ), $case['test_condition_name'] . '（「設定しない」+ 0〜23 時）' );
			$this->assertSame( '', $hour['values'][0], $case['test_condition_name'] . '（時の先頭は「設定しない」）' );
			$this->assertSame( $case['expected']['hour_selected'], $hour['selected'], $case['test_condition_name'] . '（時の選択）' );
			$this->assertSame( $case['expected']['minute_values'], $minute['values'], $case['test_condition_name'] . '（分の選択肢）' );
			$this->assertSame( $case['expected']['minute_selected'], $minute['selected'], $case['test_condition_name'] . '（分の選択）' );
			$this->assertSame( $case['expected']['minute_disabled'], str_contains( $minute['tag'], 'disabled' ), $case['test_condition_name'] . '（分の無効）' );
		}
	}

	/**
	 * sanitize_old_input: 入力エラー時の再表示で、欄が送られた保存だけ送信値を復元する。
	 */
	public function test_sanitize_old_input(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '時 21・分 30 が送られた => 21:30 を復元する',
				'input'               => array(
					'lodging_latest_check_in_hour'   => '21',
					'lodging_latest_check_in_minute' => '30',
				),
				'expected'            => '21:30',
			),
			array(
				'test_condition_name' => '「設定しない」が送られた => 空文字を復元する',
				'input'               => array( 'lodging_latest_check_in_hour' => '' ),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '欄が送られない（別のタブ） => 復元しない（保存済みの値の表示に任せる）',
				'input'               => array( 'provider_name' => '店舗' ),
				'expected'            => null,
			),
		);

		$page   = $this->create_page();
		$method = new ReflectionMethod( Provider_Settings_Page::class, 'sanitize_old_input' );
		$method->setAccessible( true );

		foreach ( $test_cases as $case ) {
			$output = $method->invoke( $page, $case['input'] );

			$this->assertSame(
				$case['expected'],
				$output[ Settings_Repository::KEY_LODGING_LATEST_CHECK_IN_TIME ] ?? null,
				$case['test_condition_name']
			);
		}
	}

	/**
	 * maybe_notify_lodging_latest_check_in_time_ignored: 基本設定の値が使われない宿泊プランがあるときだけ、その数を注意で知らせる。
	 */
	public function test_maybe_notify_lodging_latest_check_in_time_ignored(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '基本設定 18:00・チェックイン時刻 19:00 の未設定プラン 2 件 => 2 件の注意',
				'time'                => '18:00',
				'check_ins'           => array( '19:00', '19:30', '15:00' ),
				'expected_count'      => 2,
			),
			array(
				'test_condition_name' => '基本設定 18:00・チェックイン時刻 19:00 の未設定プラン 1 件 => 1 件の注意',
				'time'                => '18:00',
				'check_ins'           => array( '19:00', '15:00' ),
				'expected_count'      => 1,
			),
			array(
				'test_condition_name' => 'すべてのプランのチェックイン時刻が基本設定の値以前 => 注意なし',
				'time'                => '20:00',
				'check_ins'           => array( '15:00', '20:00' ),
				'expected_count'      => 0,
			),
			array(
				'test_condition_name' => '基本設定が未設定 => 注意なし',
				'time'                => '',
				'check_ins'           => array( '19:00' ),
				'expected_count'      => 0,
			),
		);

		foreach ( $test_cases as $case ) {
			$GLOBALS['wp_settings_errors'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- テスト間で設定エラーの蓄積を掃除する。
			$this->save_settings( 'lodging', $case['time'] );

			// ケースごとに泊単位のプランを作り直す。
			$old_ids = get_posts(
				array(
					'post_type'      => Service_Menu_Post_Type::POST_TYPE,
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			);
			foreach ( $old_ids as $old_id ) {
				wp_delete_post( (int) $old_id, true );
			}
			foreach ( $case['check_ins'] as $check_in ) {
				$menu_id = (int) self::factory()->post->create(
					array(
						'post_type'   => Service_Menu_Post_Type::POST_TYPE,
						'post_status' => 'publish',
					)
				);
				update_post_meta( $menu_id, Service_Menu_Post_Type::META_BOOKING_UNIT, Service_Menu_Post_Type::BOOKING_UNIT_NIGHT );
				update_post_meta( $menu_id, Service_Menu_Post_Type::META_CHECK_IN_TIME, $check_in );
			}

			$method = new ReflectionMethod( Provider_Settings_Page::class, 'maybe_notify_lodging_latest_check_in_time_ignored' );
			$method->setAccessible( true );
			$method->invoke( $this->create_page() );

			$found = null;
			foreach ( get_settings_errors( 'vkbm-provider-settings' ) as $error ) {
				if ( 'vkbm_provider_settings_lodging_latest_check_in_time_ignored' === $error['code'] ) {
					$found = $error;
				}
			}

			$this->assertSame( $case['expected_count'] > 0, null !== $found, $case['test_condition_name'] );
			if ( null === $found ) {
				continue;
			}

			$this->assertSame( 'warning', $found['type'], $case['test_condition_name'] . '（種別）' );
			$this->assertStringContainsString(
				esc_html(
					sprintf(
						/* translators: 1: number of lodging plans, 2: latest check-in time on the General Settings page (HH:MM). */
						_n(
							'%1$d lodging plan has a check-in time later than the latest check-in time (%2$s), so the latest check-in time is not used for it.',
							'%1$d lodging plans have a check-in time later than the latest check-in time (%2$s), so the latest check-in time is not used for them.',
							$case['expected_count'],
							'vk-booking-manager'
						),
						$case['expected_count'],
						$case['time']
					)
				),
				$found['message'],
				$case['test_condition_name'] . '（件数と基本設定の値）'
			);
			$this->assertStringContainsString( 'edit.php?post_type=' . Service_Menu_Post_Type::POST_TYPE, $found['message'], $case['test_condition_name'] . '（サービスメニューの一覧へのリンク）' );
		}
	}
}
