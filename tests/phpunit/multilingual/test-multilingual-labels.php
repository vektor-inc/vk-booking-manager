<?php
/**
 * 言語ごとの呼び方（#1186）のテスト。
 *
 * - 呼び方を返す関数（resource-labels.php）が「表示中の言語の値 → 基本の値 → 翻訳の既定値」の順で決め、フィルターに通すこと
 * - 保存（Multilingual_Labels）が、多言語設定タブの欄が送られたときだけ保存し直すこと
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Multilingual;

use VKBookingManager\Multilingual\Multilingual_Labels;
use VKBookingManager\Multilingual\Multilingual_Settings_Panel;
use VKBookingManager\Multilingual\Rest_Locale;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_UnitTestCase;

/**
 * 言語ごとの呼び方のテストクラス。
 *
 * @group multilingual
 */
class Multilingual_Labels_Test extends WP_UnitTestCase {
	/**
	 * 文章の取得順と保存時の複数行サニタイズを確認する。
	 *
	 * @return void
	 */
	public function test_policy_text_resolution_and_sanitizing(): void {
		$key = 'provider_cancellation_policy';
		$this->assertSame( '基本', Multilingual_Labels::resolve_text( $key, '基本', '既定', 'en_US' ) );
		$this->assertSame( '既定', Multilingual_Labels::resolve_text( $key, '', '既定', 'en_US' ) );
		$this->assertSame( '基本', Multilingual_Labels::resolve_text( $key, '基本', '既定', 'ja_JP' ) );
		add_filter(
			Multilingual_Labels::TEXT_FILTER_HOOK,
			static function ( $value ) {
				return '差し替え';
			}
		);
		$this->assertSame( '差し替え', Multilingual_Labels::resolve_text( $key, '基本', '既定', 'en_US' ) );
		remove_all_filters( Multilingual_Labels::TEXT_FILTER_HOOK );
		Multilingual_Labels::save_from_request(
			array(
				Multilingual_Labels::SUBMITTED_KEY => '1',
				Multilingual_Labels::INPUT_NAME    => array( 'en_US' => array( $key => "Line 1\n<script>alert(1)</script>Line 2" ) ),
			)
		);
		$this->assertSame( "Line 1\nLine 2", Multilingual_Labels::resolve_text( $key, '基本', '既定', 'en_US' ) );
		Multilingual_Labels::save_from_request(
			array(
				Multilingual_Labels::SUBMITTED_KEY => '1',
				Multilingual_Labels::INPUT_NAME    => array( 'en_US' => array( $key => '' ) ),
			)
		);
		$this->assertSame( '基本', Multilingual_Labels::resolve_text( $key, '基本', '既定', 'en_US' ) );
		$this->assertSame( '既定', Multilingual_Labels::resolve_text( $key, '', '既定', 'en_US' ) );
	}

	/**
	 * 表示中のロケールを差し替えるフィルターの戻り値。空文字なら差し替えない。
	 *
	 * @var string
	 */
	private string $current_locale = '';

	/**
	 * 各テスト前に設定を消し、翻訳の既定値が英語の原文で返る状態にする。
	 *
	 * 先行テストが日本語の翻訳を読み込んだまま残すことがあるため、textdomain をアンロードする
	 * （tests/phpunit/common/test-guests-label-helpers.php と同じ理由）。
	 */
	public function set_up(): void {
		parent::set_up();

		delete_option( Settings_Repository::OPTION_KEY );
		delete_option( Multilingual_Labels::OPTION_KEY );

		if ( function_exists( 'unload_textdomain' ) ) {
			unload_textdomain( 'vk-booking-manager', false );
		}

		$this->current_locale = '';
		add_filter( 'locale', array( $this, 'filter_locale' ) );
	}

	/**
	 * 各テスト後に設定とフィルターを片付け、翻訳を読み込み直す。
	 */
	public function tear_down(): void {
		remove_filter( 'locale', array( $this, 'filter_locale' ) );
		remove_all_filters( Multilingual_Labels::FILTER_HOOK );
		remove_all_filters( Multilingual_Labels::TEXT_FILTER_HOOK );
		delete_option( Settings_Repository::OPTION_KEY );
		delete_option( Multilingual_Labels::OPTION_KEY );

		$mo_path = dirname( __DIR__, 3 ) . '/languages/vk-booking-manager-ja.mo';
		if ( file_exists( $mo_path ) && function_exists( 'load_textdomain' ) ) {
			load_textdomain( 'vk-booking-manager', $mo_path );
		}

		parent::tear_down();
	}

	/**
	 * テスト用に、表示中のロケール（get_locale() / determine_locale() の元）を差し替える。
	 *
	 * @param string $locale 元のロケール。
	 * @return string 差し替えたロケール。
	 */
	public function filter_locale( $locale ) {
		return '' !== $this->current_locale ? $this->current_locale : $locale;
	}

	/**
	 * 条件（基本の値・言語ごとの値・表示中のロケール・フィルター）を整える。
	 *
	 * @param array<string, mixed> $conditions 条件。
	 * @return void
	 */
	private function apply_conditions( array $conditions ): void {
		delete_option( Settings_Repository::OPTION_KEY );
		delete_option( Multilingual_Labels::OPTION_KEY );
		remove_all_filters( Multilingual_Labels::FILTER_HOOK );

		if ( isset( $conditions['base'] ) ) {
			update_option( Settings_Repository::OPTION_KEY, $conditions['base'] );
		}
		if ( isset( $conditions['multilingual'] ) ) {
			update_option( Multilingual_Labels::OPTION_KEY, $conditions['multilingual'] );
		}
		if ( isset( $conditions['filter'] ) ) {
			add_filter( Multilingual_Labels::FILTER_HOOK, $conditions['filter'], 10, 3 );
		}
		$this->current_locale = (string) ( $conditions['locale'] ?? '' );
	}

	/**
	 * 呼び方を返す関数が「表示中の言語の値 → 基本の値 → 翻訳の既定値」の順で決め、フィルターに通すこと。
	 *
	 * 各呼び方のキーについて、対応する取得関数（vkbm_get_{key}()）の戻り値を確かめる。
	 */
	public function test_vkbm_resolve_label(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '英語のページで英語の値を登録済み => 英語の値',
				'conditions'          => array(
					'locale'       => 'en_US',
					'base'         => array( 'menu_label' => 'プラン' ),
					'multilingual' => array( 'en_US' => array( 'menu_label' => 'Plan' ) ),
				),
				'key'                 => 'menu_label',
				'expected'            => 'Plan',
			),
			array(
				'test_condition_name' => '英語のページで英語の値が空欄 => 基本の値',
				'conditions'          => array(
					'locale'       => 'en_US',
					'base'         => array( 'menu_label' => 'プラン' ),
					'multilingual' => array( 'en_US' => array( 'duration_label' => 'Time' ) ),
				),
				'key'                 => 'menu_label',
				'expected'            => 'プラン',
			),
			array(
				'test_condition_name' => '英語の値も基本の値も空 => 翻訳の既定値',
				'conditions'          => array(
					'locale'       => 'en_US',
					'base'         => array( 'menu_label' => '' ),
					'multilingual' => array( 'en_US' => array() ),
				),
				'key'                 => 'menu_label',
				'expected'            => 'Menu',
			),
			array(
				'test_condition_name' => '言語を追加していないサイト => 基本の値（今までどおり）',
				'conditions'          => array(
					'locale' => 'en_US',
					'base'   => array( 'guests_count_label' => '宿泊人数' ),
				),
				'key'                 => 'guests_count_label',
				'expected'            => '宿泊人数',
			),
			array(
				'test_condition_name' => '別の言語（ドイツ語）のページでは英語の値を使わない => 基本の値',
				'conditions'          => array(
					'locale'       => 'de_DE',
					'base'         => array( 'other_conditions_label' => '注意事項' ),
					'multilingual' => array( 'en_US' => array( 'other_conditions_label' => 'Notes' ) ),
				),
				'key'                 => 'other_conditions_label',
				'expected'            => '注意事項',
			),
			array(
				'test_condition_name' => '所要時間ラベルも言語の値を使う',
				'conditions'          => array(
					'locale'       => 'en_US',
					'base'         => array( 'duration_label' => '所要時間' ),
					'multilingual' => array( 'en_US' => array( 'duration_label' => 'Duration' ) ),
				),
				'key'                 => 'duration_label',
				'expected'            => 'Duration',
			),
			array(
				'test_condition_name' => '指名なしラベルも言語の値を使う',
				'conditions'          => array(
					'locale'       => 'en_US',
					'base'         => array( 'no_nomination_label' => '指名なし' ),
					'multilingual' => array( 'en_US' => array( 'no_nomination_label' => 'Any staff' ) ),
				),
				'key'                 => 'no_nomination_label',
				'expected'            => 'Any staff',
			),
			array(
				'test_condition_name' => '指名料ラベルの言語の値が空欄 => 基本の値',
				'conditions'          => array(
					'locale'       => 'en_US',
					'base'         => array( 'nomination_fee_label' => '指名料' ),
					'multilingual' => array( 'en_US' => array( 'nomination_fee_label' => '' ) ),
				),
				'key'                 => 'nomination_fee_label',
				'expected'            => '指名料',
			),
			array(
				'test_condition_name' => '数量の単位の言語の値あり => 言語の値',
				'conditions'          => array(
					'locale'       => 'en_US',
					'base'         => array( 'guests_unit_label' => '名' ),
					'multilingual' => array( 'en_US' => array( 'guests_unit_label' => 'people' ) ),
				),
				'key'                 => 'guests_unit_label',
				'expected'            => 'people',
			),
			array(
				'test_condition_name' => '数量の単位の言語の値が空欄 => 基本の値（基本の値の空文字は単位なしのまま）',
				'conditions'          => array(
					'locale'       => 'en_US',
					'base'         => array( 'guests_unit_label' => '' ),
					'multilingual' => array( 'en_US' => array() ),
				),
				'key'                 => 'guests_unit_label',
				'expected'            => '',
			),
			array(
				'test_condition_name' => 'フィルターフックで差し替え（キーとロケールを受け取る）',
				'conditions'          => array(
					'locale'       => 'en_US',
					'base'         => array( 'menu_label' => 'プラン' ),
					'multilingual' => array( 'en_US' => array( 'menu_label' => 'Plan' ) ),
					'filter'       => static function ( $label, $key, $locale ) {
						return $label . '|' . $key . '|' . $locale;
					},
				),
				'key'                 => 'menu_label',
				'expected'            => 'Plan|menu_label|en_US',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->apply_conditions( $case['conditions'] );

			$function = 'vkbm_get_' . $case['key'];
			$actual   = $function();

			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * メニューの呼び方（複数形）が、言語の複数形 → （言語の単数形が無ければ）基本の値の複数形 → 単数形の順で決まること。
	 */
	public function test_vkbm_get_menu_label_plural(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '言語の複数形あり => 言語の複数形',
				'conditions'          => array(
					'locale'       => 'en_US',
					'base'         => array(
						'menu_label'        => 'Course',
						'menu_label_plural' => 'Courses',
					),
					'multilingual' => array(
						'en_US' => array(
							'menu_label'        => 'Plan',
							'menu_label_plural' => 'Plans',
						),
					),
				),
				'expected'            => 'Plans',
			),
			array(
				'test_condition_name' => '言語の単数形だけ登録 => 基本の値の複数形ではなく言語の単数形',
				'conditions'          => array(
					'locale'       => 'en_US',
					'base'         => array(
						'menu_label'        => 'Course',
						'menu_label_plural' => 'Courses',
					),
					'multilingual' => array( 'en_US' => array( 'menu_label' => 'Plan' ) ),
				),
				'expected'            => 'Plan',
			),
			array(
				'test_condition_name' => '言語の値が無い => 基本の値の複数形',
				'conditions'          => array(
					'locale'       => 'en_US',
					'base'         => array(
						'menu_label'        => 'Course',
						'menu_label_plural' => 'Courses',
					),
					'multilingual' => array( 'en_US' => array() ),
				),
				'expected'            => 'Courses',
			),
			array(
				'test_condition_name' => '言語の値も基本の値の複数形も無い => 基本の値の単数形',
				'conditions'          => array(
					'locale'       => 'en_US',
					'base'         => array( 'menu_label' => 'プラン' ),
					'multilingual' => array( 'en_US' => array() ),
				),
				'expected'            => 'プラン',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->apply_conditions( $case['conditions'] );

			$this->assertSame( $case['expected'], vkbm_get_menu_label_plural(), $case['test_condition_name'] );
		}
	}

	/**
	 * switch_to_locale() で言語を切り替えている間は、切り替え後の言語の値が選ばれること（通知メールの言語の切り替えで使う）。
	 */
	public function test_vkbm_get_menu_label(): void {
		// サイトの言語をドイツ語にし、英語とドイツ語の値を登録する。
		$this->apply_conditions(
			array(
				'locale'       => 'de_DE',
				'base'         => array( 'menu_label' => 'プラン' ),
				'multilingual' => array(
					'en_US' => array( 'menu_label' => 'Plan' ),
					'de_DE' => array( 'menu_label' => 'Tarif' ),
				),
			)
		);

		$this->assertSame( 'Tarif', vkbm_get_menu_label(), '切り替え前 => サイトの言語（ドイツ語）の値' );

		// en_US はインストールしなくても切り替えられる。
		$this->assertTrue( switch_to_locale( 'en_US' ) );
		$this->assertSame( 'Plan', vkbm_get_menu_label(), 'switch_to_locale( en_US ) の間 => 英語の値' );
		restore_previous_locale();

		$this->assertSame( 'Tarif', vkbm_get_menu_label(), '元に戻した後 => サイトの言語（ドイツ語）の値' );
	}

	/**
	 * 多言語設定タブの欄が送られたときだけ保存し直し、別のタブの保存では言語ごとの値を消さないこと。
	 */
	public function test_save_from_request(): void {
		$saved = array( 'en_US' => array( 'menu_label' => 'Plan' ) );

		$test_cases = array(
			array(
				'test_condition_name' => '別のタブの保存（印なし） => 保存し直さず、登録済みの値が残る',
				'post'                => array(
					'vkbm_provider_settings' => array( 'provider_name' => 'Shop' ),
				),
				'expected_return'     => false,
				'expected'            => $saved,
			),
			array(
				'test_condition_name' => '多言語設定タブの保存 => 送られた値で置き換える（空欄の項目は保存しない）',
				'post'                => array(
					Multilingual_Labels::SUBMITTED_KEY => '1',
					Multilingual_Labels::INPUT_NAME    => array(
						'en_US' => array(
							'menu_label'     => ' Course ',
							'duration_label' => '',
						),
					),
				),
				'expected_return'     => true,
				'expected'            => array( 'en_US' => array( 'menu_label' => 'Course' ) ),
			),
			array(
				'test_condition_name' => '言語を削除して保存 => 削除した言語の値が消える',
				'post'                => array(
					Multilingual_Labels::SUBMITTED_KEY => '1',
					Multilingual_Labels::INPUT_NAME    => array(
						'de_DE' => array( 'menu_label' => 'Tarif' ),
					),
				),
				'expected_return'     => true,
				'expected'            => array( 'de_DE' => array( 'menu_label' => 'Tarif' ) ),
			),
			array(
				'test_condition_name' => '欄が送られなかった項目（画面に欄が無い） => 保存済みの値を残す',
				'post'                => array(
					Multilingual_Labels::SUBMITTED_KEY => '1',
					Multilingual_Labels::INPUT_NAME    => array(
						'en_US' => array( 'duration_label' => 'Duration' ),
					),
				),
				'expected_return'     => true,
				'expected'            => array(
					'en_US' => array(
						'duration_label' => 'Duration',
						'menu_label'     => 'Plan',
					),
				),
			),
			array(
				'test_condition_name' => '言語を全部削除して保存（印だけ届く） => 空になる',
				'post'                => array(
					Multilingual_Labels::SUBMITTED_KEY => '1',
				),
				'expected_return'     => true,
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '入力が全部空の言語 => 追加した言語として残る',
				'post'                => array(
					Multilingual_Labels::SUBMITTED_KEY => '1',
					Multilingual_Labels::INPUT_NAME    => array(
						'en_US' => array( 'menu_label' => '' ),
					),
				),
				'expected_return'     => true,
				'expected'            => array( 'en_US' => array() ),
			),
			array(
				'test_condition_name' => '不正なロケール・対象外のキー・HTML => 捨てる・取り除く',
				'post'                => array(
					Multilingual_Labels::SUBMITTED_KEY => '1',
					Multilingual_Labels::INPUT_NAME    => array(
						'en_US'      => array(
							'menu_label'              => '<b>Plan</b>',
							'resource_label_singular' => 'Guide',
						),
						'../evil'    => array( 'menu_label' => 'x' ),
						'de_DE_form' => array( 'menu_label' => array( 'x' ) ),
					),
				),
				'expected_return'     => true,
				'expected'            => array(
					'en_US'      => array( 'menu_label' => 'Plan' ),
					'de_DE_form' => array(),
				),
			),
		);

		foreach ( $test_cases as $case ) {
			update_option( Multilingual_Labels::OPTION_KEY, $saved );

			$actual_return = Multilingual_Labels::save_from_request( $case['post'] );

			$this->assertSame( $case['expected_return'], $actual_return, $case['test_condition_name'] . '（戻り値）' );
			$this->assertSame( $case['expected'], Multilingual_Labels::get_all(), $case['test_condition_name'] );
		}
	}

	/**
	 * 仮予約完了の言語別案内文を基本設定と同じ上限・改行に整える。
	 */
	public function test_pending_message_uses_base_setting_sanitizer(): void {
		Multilingual_Labels::save_from_request(
			array(
				Multilingual_Labels::SUBMITTED_KEY => '1',
				Multilingual_Labels::INPUT_NAME => array(
					'en_US' => array( 'provider_booking_complete_message_pending' => str_repeat( 'a', 998 ) . "\r\n" . 'beyond' ),
				),
			)
		);
		$this->assertSame( str_repeat( 'a', 998 ) . "\nb", Multilingual_Labels::get_value( 'en_US', 'provider_booking_complete_message_pending' ) );
	}

	/**
	 * 文章欄に空欄時の案内を出し、長文のプレースホルダーを出さない。
	 *
	 * @return void
	 */
	public function test_multilingual_panel_textareas(): void {
		update_option( Multilingual_Labels::OPTION_KEY, array( 'en_US' => array() ) );
		$panel = new Multilingual_Settings_Panel( array(), false, false, admin_url( 'admin.php?page=vkbm-provider-settings&tab=notation' ) );
		ob_start();
		$panel->render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Booking guidance text', $html );
		$this->assertStringContainsString( 'If left empty, the text in the basic settings is used.', $html );
		$this->assertStringContainsString( 'For tentative reservation completion guidance, the standard text is used if the basic setting is also empty.', $html );
		$this->assertStringContainsString( 'To edit the tentative reservation completion guidance in the basic settings, set &quot;Reservation status&quot; to &quot;Make a tentative reservation&quot;.', $html );
		$this->assertStringContainsString( 'tab=consent#vkbm-provider-cancellation-policy', $html );
		$this->assertStringContainsString( 'tab=store#vkbm-provider-payment-method', $html );
		$this->assertStringContainsString( 'tab=system#vkbm-provider-booking-status-mode', $html );
		$this->assertStringContainsString( 'id="vkbm-provider-booking-status-mode"', file_get_contents( dirname( __DIR__, 3 ) . '/src/admin/class-provider-settings-page.php' ) );
		$this->assertStringContainsString( 'name="vkbm_multilingual_labels[en_US][provider_cancellation_policy]"', $html );
		$this->assertDoesNotMatchRegularExpression( '/<textarea[^>]*placeholder=/', $html );
		$this->assertMatchesRegularExpression( '/<textarea[^>]*data-multilingual-key="provider_booking_complete_message_pending"[^>]*maxlength="1000"/', $html );
		$this->assertDoesNotMatchRegularExpression( '/<textarea[^>]*data-multilingual-key="provider_cancellation_policy"[^>]*maxlength=/', $html );
		$this->assertDoesNotMatchRegularExpression( '/<textarea[^>]*data-multilingual-key="provider_payment_method"[^>]*maxlength=/', $html );
	}

	/**
	 * 予約画面の REST が文章の言語別値と基本値を返す。
	 *
	 * @return void
	 */
	public function test_provider_settings_text_fallback(): void {
		update_option( Settings_Repository::OPTION_KEY, array( 'provider_payment_method' => '基本の支払い案内' ) );
		update_option( Multilingual_Labels::OPTION_KEY, array( 'en_US' => array( 'provider_payment_method' => 'English payment', 'provider_booking_complete_message_pending' => 'English pending' ) ) );
		$request = new \WP_REST_Request( 'GET', '/vkbm/v1/provider-settings' );
		$request->set_param( 'vkbm_locale', 'en_US' );
		$response = rest_do_request( $request );
		$this->assertSame( 'English payment', $response->get_data()['payment_method'] );
		$this->assertSame( 'English pending', $response->get_data()['booking_complete_message_pending'] );
		update_option( Multilingual_Labels::OPTION_KEY, array( 'en_US' => array() ) );
		$response = rest_do_request( $request );
		$this->assertSame( '基本の支払い案内', $response->get_data()['payment_method'] );
		$this->assertNotSame( '', $response->get_data()['booking_complete_message_pending'] );
	}

	/**
	 * 予約画面の REST を、添えられたページの言語へ切り替える条件（Rest_Locale::resolve_locale()）。
	 */
	public function test_resolve_locale(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'このプラグインの REST の読み取りで en_US => 切り替える',
				'method'              => 'GET',
				'route'               => '/vkbm/v1/provider-settings',
				'raw'                 => 'en_US',
				'expected'            => 'en_US',
			),
			array(
				'test_condition_name' => '確認画面へ進むときの下書きの作成（POST /vkbm/v1/drafts） => 切り替える',
				'method'              => 'POST',
				'route'               => '/vkbm/v1/drafts',
				'raw'                 => 'en_US',
				'expected'            => 'en_US',
			),
			array(
				'test_condition_name' => '予約の確定など、それ以外の書き込み => 切り替えない（通知メールの言語を変えない）',
				'method'              => 'POST',
				'route'               => '/vkbm/v1/bookings',
				'raw'                 => 'en_US',
				'expected'            => '',
			),
			array(
				'test_condition_name' => '別のプラグインの REST => 切り替えない',
				'method'              => 'GET',
				'route'               => '/wp/v2/posts',
				'raw'                 => 'en_US',
				'expected'            => '',
			),
			array(
				'test_condition_name' => 'このサイトに無い言語 => 切り替えない',
				'method'              => 'GET',
				'route'               => '/vkbm/v1/provider-settings',
				'raw'                 => 'xx_YY',
				'expected'            => '',
			),
			array(
				'test_condition_name' => '形式に合わない値 => 切り替えない',
				'method'              => 'GET',
				'route'               => '/vkbm/v1/provider-settings',
				'raw'                 => array( 'en_US' ),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '今と同じ言語 => 切り替えない',
				'method'              => 'GET',
				'route'               => '/vkbm/v1/provider-settings',
				'raw'                 => 'de_DE',
				'expected'            => '',
			),
		);

		// 今の言語をドイツ語にする。
		$this->current_locale = 'de_DE';

		foreach ( $test_cases as $case ) {
			$actual = Rest_Locale::resolve_locale( $case['method'], $case['route'], $case['raw'] );

			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * REST のフックで言語を切り替え、応答の後で元の言語へ戻すこと（Rest_Locale::maybe_switch_locale() / maybe_restore_locale()）。
	 */
	public function test_maybe_switch_locale(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '予約画面の読み取りに en_US を添えた => 処理中は en_US、応答の後でドイツ語へ戻る',
				'method'              => 'GET',
				'route'               => '/vkbm/v1/provider-settings',
				'locale'              => 'en_US',
				'expected_during'     => 'en_US',
			),
			array(
				'test_condition_name' => '予約の確定（POST）に en_US を添えた => 切り替えない',
				'method'              => 'POST',
				'route'               => '/vkbm/v1/bookings',
				'locale'              => 'en_US',
				'expected_during'     => 'de_DE',
			),
			array(
				'test_condition_name' => '言語を添えていない => 切り替えない',
				'method'              => 'GET',
				'route'               => '/vkbm/v1/provider-settings',
				'locale'              => null,
				'expected_during'     => 'de_DE',
			),
		);

		$this->current_locale = 'de_DE';
		$rest_locale          = new Rest_Locale();
		$rest_locale->register();

		foreach ( $test_cases as $case ) {
			$request = new \WP_REST_Request( $case['method'], $case['route'] );
			if ( null !== $case['locale'] ) {
				$request->set_param( Rest_Locale::PARAM, $case['locale'] );
			}

			// REST サーバーが処理する前後と同じ順で、2つのフックを通す。
			$result = apply_filters( 'rest_pre_dispatch', null, rest_get_server(), $request ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress 本体のフックを呼ぶ。
			$this->assertNull( $result, $case['test_condition_name'] . '（先に決まった応答は変えない）' );
			$this->assertSame( $case['expected_during'], determine_locale(), $case['test_condition_name'] . '（処理中）' );

			$response = new \WP_REST_Response( array() );
			$this->assertSame( $response, apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request ), $case['test_condition_name'] . '（応答は変えない）' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress 本体のフックを呼ぶ。
			$this->assertSame( 'de_DE', determine_locale(), $case['test_condition_name'] . '（応答の後）' );
		}

		remove_filter( 'rest_pre_dispatch', array( $rest_locale, 'maybe_switch_locale' ), 10 );
		remove_filter( 'rest_post_dispatch', array( $rest_locale, 'maybe_restore_locale' ), 10 );
	}

	/**
	 * 空き枠のキャッシュ名が言語ごとに分かれること（指名なしの呼び方・カレンダーの注記が別の言語のページへ混ざらないため）。
	 */
	public function test_build_cache_key(): void {
		$service    = new \VKBookingManager\Availability\Availability_Service();
		$reflection = new \ReflectionClass( $service );
		$method     = $reflection->getMethod( 'build_cache_key' );
		$method->setAccessible( true );

		$keys = array();
		foreach ( array( 'daily', 'calendar' ) as $prefix ) {
			foreach ( array( 'de_DE', 'en_US' ) as $locale ) {
				$this->current_locale       = $locale;
				$keys[ $prefix ][ $locale ] = (string) $method->invoke( $service, $prefix, 3, array( 1 ), '2026-04-01', false );
			}

			$this->assertNotSame( $keys[ $prefix ]['de_DE'], $keys[ $prefix ]['en_US'], $prefix . ': 言語が違う => キャッシュ名も違う' );
			$this->assertStringEndsWith( '_lde_de', $keys[ $prefix ]['de_DE'], $prefix . ': キャッシュ名の末尾に言語が入る' );
		}

		// 同じ言語なら同じキャッシュ名になる（言語以外の条件でキャッシュが効かなくならない）。
		$this->current_locale = 'en_US';
		$this->assertSame( $keys['daily']['en_US'], (string) $method->invoke( $service, 'daily', 3, array( 1 ), '2026-04-01', false ), '同じ言語 => 同じキャッシュ名' );
	}

	/**
	 * 条件で見せない欄も hidden 付きで出力し、保存のたびに値が送られること（Multilingual_Settings_Panel::get_fields()）。
	 */
	public function test_get_fields(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '指名機能が無効 => 指名なし・指名料の欄は隠して出す',
				'nomination_enabled'  => false,
				'industry_hidden'     => array(),
				'expected_hidden'     => array( 'no_nomination_label', 'nomination_fee_label' ),
			),
			array(
				'test_condition_name' => '指名機能が有効・業種プリセットで単位を自動設定 => 単位の欄だけ隠して出す',
				'nomination_enabled'  => true,
				'industry_hidden'     => array( 'guests_unit_label' ),
				'expected_hidden'     => array( 'guests_unit_label' ),
			),
		);

		foreach ( $test_cases as $case ) {
			$panel  = new Multilingual_Settings_Panel( $case['industry_hidden'], $case['nomination_enabled'], false, 'https://example.com/' );
			$fields = $panel->get_fields( 'en_US' );

			$keys   = array_column( $fields, 'key' );
			$hidden = array_values( array_column( array_filter( $fields, static fn( $field ) => $field['hidden'] ), 'key' ) );

			$this->assertSame( Multilingual_Labels::FIELD_KEYS, $keys, $case['test_condition_name'] . '（全部の欄を出す）' );
			$this->assertSame( $case['expected_hidden'], $hidden, $case['test_condition_name'] );
		}
	}

	/**
	 * 追加できる言語が「インストール済みの言語と英語から、サイトの言語と追加済みの言語を除いたもの」になること。
	 */
	public function test_get_addable_locales(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '日本語のサイトで言語を追加していない => 英語とインストール済みの言語',
				'site_locale'         => 'ja',
				'installed'           => array( 'ja', 'de_DE' ),
				'added'               => array(),
				'expected'            => array( 'en_US', 'de_DE' ),
			),
			array(
				'test_condition_name' => '英語を追加済み => 英語を除く',
				'site_locale'         => 'ja',
				'installed'           => array( 'ja', 'de_DE' ),
				'added'               => array( 'en_US' ),
				'expected'            => array( 'de_DE' ),
			),
			array(
				'test_condition_name' => '英語のサイト => サイトの言語の英語を除く',
				'site_locale'         => 'en_US',
				'installed'           => array( 'ja' ),
				'added'               => array(),
				'expected'            => array( 'ja' ),
			),
			array(
				'test_condition_name' => '全部追加済み => 空',
				'site_locale'         => 'ja',
				'installed'           => array( 'ja' ),
				'added'               => array( 'en_US' ),
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = Multilingual_Settings_Panel::get_addable_locales( $case['site_locale'], $case['installed'], $case['added'] );

			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * 複数形の欄を出すかを、登録する言語で判定すること（日本語の管理画面でも英語の複数形の欄を出す）。
	 */
	public function test_locale_has_plural_forms(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '英語 => 複数形あり',
				'locale'              => 'en_US',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'ドイツ語 => 複数形あり',
				'locale'              => 'de_DE',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '日本語 => 複数形なし',
				'locale'              => 'ja',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '中国語 => 複数形なし',
				'locale'              => 'zh_CN',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '韓国語 => 複数形なし',
				'locale'              => 'ko_KR',
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Multilingual_Labels::locale_has_plural_forms( $case['locale'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * プレースホルダー（空欄のときに表示される呼び方）が、基本の値、無ければ既定の呼び方になること。
	 */
	public function test_get_placeholders(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '基本の値あり => 基本の値',
				'base'                => array( 'menu_label' => 'プラン' ),
				'values'              => array(),
				'key'                 => 'menu_label',
				'expected'            => 'プラン',
			),
			array(
				'test_condition_name' => '基本の値なし => 既定の呼び方',
				'base'                => array( 'menu_label' => '' ),
				'values'              => array(),
				'key'                 => 'menu_label',
				'expected'            => 'Menu',
			),
			array(
				'test_condition_name' => '複数形は、その言語の単数形を登録していればその単数形',
				'base'                => array( 'menu_label' => 'プラン' ),
				'values'              => array( 'menu_label' => 'Plan' ),
				'key'                 => 'menu_label_plural',
				'expected'            => 'Plan',
			),
			array(
				'test_condition_name' => '単数形を空にしたときの複数形 => 基本の値の単数形（言語の単数形は混ぜない）',
				'base'                => array( 'menu_label' => 'プラン' ),
				'values'              => array( 'menu_label' => 'Plan' ),
				'key'                 => 'menu_label_plural_base',
				'expected'            => 'プラン',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->apply_conditions(
				array(
					'base'         => $case['base'],
					'multilingual' => array( 'en_US' => $case['values'] ),
				)
			);

			$actual = Multilingual_Settings_Panel::get_placeholders( 'en_US', $case['values'] );

			$this->assertSame( $case['expected'], $actual[ $case['key'] ], $case['test_condition_name'] );
		}
	}
}
