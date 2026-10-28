<?php
/**
 * BM 設定のどのタブから保存しても、全設定項目が送信されることのテスト（issue #556）。
 *
 * BM 設定はすべてのタブが 1 つのフォームで、保存時に送信されなかった項目は初期値で埋められる
 * （Settings_Sanitizer::sanitize() の array_merge( $defaults, $input )）。
 * そのため、あるタブで描画したフォームに含まれない設定項目があると、そのタブから保存するたびに
 * その項目が初期値に戻る（「システム」以外のタブで、通貨記号・税表記・サービスメニューの表示方法が
 * 初期値に戻っていた）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use DOMDocument;
use DOMElement;
use DOMXPath;
use VKBookingManager\Admin\Provider_Settings_Page;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use WP_UnitTestCase;

/**
 * タブごとのフォームの送信項目を検証するテストクラス。
 *
 * @group admin
 */
class Provider_Settings_Page_Tab_Form_Fields_Test extends WP_UnitTestCase {

	/**
	 * render_page() のタブ定義の全タブ（保存フォームを持つもの）。
	 *
	 * 「連携」タブは保存フォームの外に描画され、設定項目を持たないため対象外。
	 * 「ライセンス」タブは無料版では存在せず、既定の「店舗」タブとして描画される。
	 */
	private const FORM_TABS = array( 'store', 'system', 'email', 'registration', 'consent', 'design', 'advanced', 'faq', 'license' );

	/**
	 * 入力欄が「システム」タブのときだけ描画される設定項目。
	 */
	private const SYSTEM_TAB_ONLY_KEYS = array( 'reservation_menu_list_display_mode', 'currency_symbol', 'tax_label_text' );

	/**
	 * 管理者でログインし、設定画面を描画できる状態にする。
	 */
	protected function setUp(): void {
		parent::setUp();
		$admin_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user_id );
		// 描画するタブはクエリ文字列の tab で決まるため、テストごとにクエリ文字列を初期化する。
		$_GET = array();
	}

	/**
	 * 保存済みの設定とクエリ文字列を掃除し、他のテストへ影響しないようにする。
	 */
	protected function tearDown(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		$_GET = array();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * どのタブで描画したフォームにも、全設定項目が 1 つずつ含まれることを検証する。
	 *
	 * 全設定項目は、全タブで描画したフォームの設定項目を合算して求める
	 * （項目を追加したときに、特定のタブだけ送信し忘れるのを検出するため）。
	 */
	public function test_render_page(): void {
		$page = $this->create_page();

		// 全タブで描画したフォームの設定項目を合算し、全設定項目とする。
		$all_form_keys = array();
		foreach ( self::FORM_TABS as $tab ) {
			$all_form_keys = array_merge( $all_form_keys, $this->get_form_keys( $this->render_tab( $page, $tab ) ) );
		}
		$all_form_keys = array_values( array_unique( $all_form_keys ) );
		$this->assertNotEmpty( $all_form_keys, '前提: フォームから設定項目を取得できる' );
		// 前提: システムタブだけで描画される 3 項目が、全設定項目に含まれている。
		$this->assertSame( array(), array_values( array_diff( self::SYSTEM_TAB_ONLY_KEYS, $all_form_keys ) ), '前提: 全設定項目に 3 項目が含まれる' );

		$test_cases = array(
			array(
				'test_condition_name' => '「事業者情報」タブで描画 => 全設定項目が含まれ、3 項目は 1 つずつ',
				'tab'                 => 'store',
			),
			array(
				'test_condition_name' => '「システム」タブで描画 => 全設定項目が含まれ、3 項目は入力欄の 1 つずつ（hidden と二重にならない）',
				'tab'                 => 'system',
			),
			array(
				'test_condition_name' => '「メール設定」タブで描画 => 全設定項目が含まれ、3 項目は 1 つずつ',
				'tab'                 => 'email',
			),
			array(
				'test_condition_name' => '「会員登録」タブで描画 => 全設定項目が含まれ、3 項目は 1 つずつ',
				'tab'                 => 'registration',
			),
			array(
				'test_condition_name' => '「同意事項」タブで描画 => 全設定項目が含まれ、3 項目は 1 つずつ',
				'tab'                 => 'consent',
			),
			array(
				'test_condition_name' => '「デザイン」タブで描画 => 全設定項目が含まれ、3 項目は 1 つずつ',
				'tab'                 => 'design',
			),
			array(
				'test_condition_name' => '「詳細設定」タブで描画 => 全設定項目が含まれ、3 項目は 1 つずつ',
				'tab'                 => 'advanced',
			),
			array(
				'test_condition_name' => '「よくある質問」タブで描画 => 全設定項目が含まれ、3 項目は 1 つずつ',
				'tab'                 => 'faq',
			),
			array(
				'test_condition_name' => '「ライセンス」タブ（無料版では事業者情報タブ）で描画 => 全設定項目が含まれ、3 項目は 1 つずつ',
				'tab'                 => 'license',
			),
			array(
				'test_condition_name' => 'タブ指定なし（最初の画面）で描画 => 全設定項目が含まれ、3 項目は 1 つずつ',
				'tab'                 => '',
			),
			array(
				'test_condition_name' => '存在しないタブを指定して描画（事業者情報タブになる） => 全設定項目が含まれ、3 項目は 1 つずつ',
				'tab'                 => 'unknown-tab',
			),
		);

		foreach ( $test_cases as $case ) {
			$html      = $this->render_tab( $page, $case['tab'] );
			$form_keys = $this->get_form_keys( $html );

			// フォームに無い設定項目が無いこと。
			$this->assertSame(
				array(),
				array_values( array_diff( $all_form_keys, $form_keys ) ),
				$case['test_condition_name'] . '（フォームに無い設定項目）'
			);

			// 3 項目が二重に出力されていないこと（入力欄と hidden の両方が出ると、どちらの値で保存されるかが曖昧になる）。
			$counts = array();
			foreach ( self::SYSTEM_TAB_ONLY_KEYS as $key ) {
				$counts[ $key ] = substr_count( $html, 'name="vkbm_provider_settings[' . $key . ']"' );
			}
			$this->assertSame(
				array_fill_keys( self::SYSTEM_TAB_ONLY_KEYS, 1 ),
				$counts,
				$case['test_condition_name'] . '（3 項目の出力数）'
			);
		}

		// よくある質問には、他画面から移動できるアンカーと関連画面へのリンクを出す。
		// メール設定タブと同じ id が重複しないよう、よくある質問側のアンカーは別の id にする。
		$faq_html = $this->render_tab( $page, 'faq' );
		$this->assertSame( 1, substr_count( $faq_html, 'id="vkbm-email-delivery-help-faq"' ), 'よくある質問のメール未着時の確認項目にアンカーが 1 つ付くこと' );
		$this->assertSame( 0, substr_count( $faq_html, 'id="vkbm-email-delivery-help"' ), 'よくある質問にメール設定タブ側のアンカー id が出ないこと' );
		$this->assertStringNotContainsString( 'page=vkbm-email-log', $faq_html, 'メールログが無効のとき、メールログ画面へのリンクが出ないこと' );
		$this->assertStringContainsString( 'tab=email#vkbm-mail-from-email', $faq_html, '送信元メールアドレス設定（メール設定タブ）へのリンクが出ること' );
		$this->assertStringNotContainsString( 'tab=system#vkbm-mail-from-email', $faq_html, '旧位置（システム設定タブ）へのリンクが残らないこと' );

		// メール設定タブには、変更を保存ボタンの下に同じ確認項目を出し、アンカーは 1 つだけにする。
		$email_html = $this->render_tab( $page, 'email' );
		$this->assertStringContainsString( esc_html__( 'This is mainly used as the reply-to email address for customer replies to booking notification emails and other emails sent by this booking system.', 'vk-booking-manager' ), $email_html, '代表メールアドレスを予約通知メールなどの返信先として説明すること' );
		$this->assertStringContainsString( esc_html__( 'This is the sender email address for emails sent by this booking system.', 'vk-booking-manager' ), $email_html, '送信元メールアドレスの役割を簡潔に説明すること' );
		$this->assertStringNotContainsString( 'Customer replies to booking notification emails are delivered to the representative email address.', $email_html, '送信元メールアドレス欄に返信先の重複説明が出ないこと' );
		$this->assertSame( 1, substr_count( $email_html, 'id="vkbm-email-delivery-help"' ), 'メール設定タブのメール未着時の確認項目にアンカーが 1 つ付くこと' );
		// この画面は全タブの行を描画して CSS で隠すため、よくある質問側の行もメール設定タブ表示時に DOM へ出る。id が別名で重複しないことを確かめる。
		$this->assertSame( 1, substr_count( $email_html, 'id="vkbm-email-delivery-help-faq"' ), 'よくある質問側のアンカー id がメール設定タブ表示時にも重複せず 1 つだけ出ること' );
		$this->assertStringContainsString( 'href="#vkbm-mail-from-email"', $email_html, 'メール設定タブでは送信元メールアドレスへのリンクがページ内アンカーになること' );
		$this->assertGreaterThan(
			strpos( $email_html, 'name="submit"' ),
			strpos( $email_html, 'id="vkbm-email-delivery-help"' ),
			'確認項目は変更を保存ボタンより後ろに出ること'
		);

		// メール設定タブ以外には、メール設定タブ側の確認項目を出さない。
		foreach ( array( 'store', 'system', 'advanced' ) as $other_tab ) {
			$this->assertSame( 0, substr_count( $this->render_tab( $page, $other_tab ), 'id="vkbm-email-delivery-help"' ), $other_tab . ' タブに確認項目のアンカーが出ないこと' );
		}

		// メールログの有効・無効で、手順 1 の案内を出し分ける。
		$this->assertStringContainsString( esc_html__( 'Turn on "Enable email log" on this tab and save the settings.', 'vk-booking-manager' ), $email_html, 'メールログが無効なら、このタブで有効にする案内を出すこと' );
		$this->assertStringContainsString( 'tab=email#vkbm-email-log-enabled', $faq_html, 'メールログが無効なら、よくある質問からメール設定タブのメールログ設定へのリンクが出ること' );
		$settings                      = ( new Settings_Repository() )->get_settings();
		$settings['email_log_enabled'] = true;
		( new Settings_Repository() )->update_settings( $settings );
		$email_html_log_enabled = $this->render_tab( $this->create_page(), 'email' );
		$faq_html_log_enabled   = $this->render_tab( $this->create_page(), 'faq' );
		$this->assertStringContainsString( 'page=vkbm-email-log', $faq_html_log_enabled, 'メールログが有効なら、よくある質問にメールログ画面へのリンクが出ること' );
		$this->assertStringNotContainsString( 'tab=email#vkbm-email-log-enabled', $faq_html_log_enabled, 'メールログが有効なら、よくある質問に有効化の案内が出ないこと' );
		$this->assertStringNotContainsString( esc_html__( 'Turn on "Enable email log" on this tab and save the settings.', 'vk-booking-manager' ), $email_html_log_enabled, 'メールログが有効なら、有効にする案内を出さないこと' );
		$this->assertStringContainsString( 'page=vkbm-email-log', $email_html_log_enabled, 'メールログが有効なら、メールログ画面へのリンクを出すこと' );

		// 移した項目は、メール設定タブの行として出力される。
		foreach ( array( 'provider_email', 'mail_from_email', 'booking_reminder_hours', 'email_log_enabled', 'email_log_retention_days' ) as $moved_key ) {
			$this->assertMatchesRegularExpression(
				'/<tr class="vkbm-provider-settings__tab-email"[^>]*>(?:(?!<\/tr>).)*name="vkbm_provider_settings\[' . $moved_key . '\]/s',
				$email_html,
				$moved_key . ' がメール設定タブの行にあること'
			);
		}

		// 送信元メールアドレスがフリーメールの場合は、警告から同じタブの確認項目へ移動できるようにする。
		$settings['mail_from_email'] = 'sender@gmail.com';
		( new Settings_Repository() )->update_settings( $settings );
		$free_mail_html = $this->render_tab( $this->create_page(), 'email' );
		$this->assertStringContainsString( 'href="#vkbm-email-delivery-help"', $free_mail_html, 'フリーメール警告に確認項目へのリンクが出ること' );
	}

	/**
	 * 送信元メールアドレスが、タブごとに正しく保存されることを検証する。
	 *
	 * 入力欄は「メール設定」タブの行にあり、ほかのタブでは hidden で現在値を送る。
	 * 画面の入力欄を書き換えた場合に、メール設定タブでは入力値が、ほかのタブでは保存済みの値が保存される。
	 */
	public function test_save_settings_mail_from_email(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '「メール設定」タブで入力欄を書き換えて保存 => 入力値で保存される',
				'tab'                 => 'email',
				'typed'               => 'typed@example.com',
				'expected'            => 'typed@example.com',
				'expected_count'      => 1,
			),
			array(
				'test_condition_name' => '「メール設定」タブで入力欄を書き換えずに保存 => 保存済みの値のまま',
				'tab'                 => 'email',
				'typed'               => null,
				'expected'            => 'saved@example.com',
				'expected_count'      => 1,
			),
			array(
				'test_condition_name' => '「システム設定」タブから保存 => 保存済みの値のまま',
				'tab'                 => 'system',
				'typed'               => null,
				'expected'            => 'saved@example.com',
				'expected_count'      => 2,
			),
			array(
				'test_condition_name' => '「事業者情報」タブから保存 => 保存済みの値のまま',
				'tab'                 => 'store',
				'typed'               => null,
				'expected'            => 'saved@example.com',
				'expected_count'      => 2,
			),
			array(
				'test_condition_name' => '「高度な設定」タブから保存 => 保存済みの値のまま',
				'tab'                 => 'advanced',
				'typed'               => null,
				'expected'            => 'saved@example.com',
				'expected_count'      => 2,
			),
			array(
				'test_condition_name' => '「FAQ」タブから保存 => 保存済みの値のまま',
				'tab'                 => 'faq',
				'typed'               => null,
				'expected'            => 'saved@example.com',
				'expected_count'      => 2,
			),
		);

		$page    = $this->create_page();
		$service = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );

		foreach ( $test_cases as $case ) {
			// 保存済みの設定を用意する。
			delete_option( Settings_Repository::OPTION_KEY );
			$repository = new Settings_Repository();
			update_option( Settings_Repository::OPTION_KEY, array_merge( $repository->get_default_settings(), array( 'mail_from_email' => 'saved@example.com' ) ) );

			$html = $this->render_tab( $page, $case['tab'] );

			// メール設定タブでは入力欄 1 つだけ、ほかのタブでは入力欄（CSS で非表示）と hidden の 2 つになる。
			$this->assertSame(
				$case['expected_count'],
				substr_count( $html, 'name="vkbm_provider_settings[mail_from_email]"' ),
				$case['test_condition_name'] . '（出力数）'
			);

			// 入力欄を書き換えた状態を再現する。
			if ( null !== $case['typed'] ) {
				$html = preg_replace(
					'/(id="vkbm-mail-from-email"\s+name="vkbm_provider_settings\[mail_from_email\]"\s+value=")[^"]*"/',
					'${1}' . $case['typed'] . '"',
					$html
				);
			}

			$submitted = $this->get_submitted_values( $html );
			$payload   = $submitted['vkbm_provider_settings'] ?? array();
			unset( $payload['license_key'], $payload['delete_license_key'] );

			$result = $service->save_settings( $payload );
			$this->assertNotWPError( $result, $case['test_condition_name'] . '（前提: 保存できる）' );

			$saved_settings = get_option( Settings_Repository::OPTION_KEY, array() );
			$this->assertSame( $case['expected'], $saved_settings['mail_from_email'] ?? '(保存されていない)', $case['test_condition_name'] );
		}
	}

	/**
	 * あるタブで描画したフォームの値をそのまま保存したとき、3 項目が保存済みの値のまま変わらないことを検証する。
	 *
	 * 「保存」ボタンを押したときにブラウザが送る値を、描画した HTML から組み立てて保存処理に渡す。
	 */
	public function test_save_settings(): void {
		$custom_values = array(
			'reservation_menu_list_display_mode' => 'text',
			'currency_symbol'                    => '円',
			'tax_label_text'                     => '（税込価格）',
		);

		$test_cases = array(
			array(
				'test_condition_name' => '3 項目を変更済みで「事業者情報」タブから保存 => 変更済みの値のまま',
				'saved'               => $custom_values,
				'tab'                 => 'store',
				'expected'            => $custom_values,
			),
			array(
				'test_condition_name' => '3 項目を変更済みで「会員登録」タブから保存 => 変更済みの値のまま',
				'saved'               => $custom_values,
				'tab'                 => 'registration',
				'expected'            => $custom_values,
			),
			array(
				'test_condition_name' => '3 項目を変更済みで「デザイン」タブから保存 => 変更済みの値のまま',
				'saved'               => $custom_values,
				'tab'                 => 'design',
				'expected'            => $custom_values,
			),
			array(
				'test_condition_name' => '3 項目を変更済みで「ライセンス」タブ（無料版では事業者情報タブ）から保存 => 変更済みの値のまま',
				'saved'               => $custom_values,
				'tab'                 => 'license',
				'expected'            => $custom_values,
			),
			array(
				'test_condition_name' => '3 項目を変更済みで「システム」タブから保存 => 入力欄の値（変更済みの値）のまま',
				'saved'               => $custom_values,
				'tab'                 => 'system',
				'expected'            => $custom_values,
			),
			array(
				'test_condition_name' => '3 項目を変更済みでタブ指定なし（最初の画面）から保存 => 変更済みの値のまま',
				'saved'               => $custom_values,
				'tab'                 => '',
				'expected'            => $custom_values,
			),
			array(
				'test_condition_name' => '通貨記号・税表記を空欄にして「事業者情報」タブから保存 => 空欄のまま（初期値に置き換わらない）',
				'saved'               => array(
					'reservation_menu_list_display_mode' => 'card',
					'currency_symbol'                    => '',
					'tax_label_text'                     => '',
				),
				'tab'                 => 'store',
				'expected'            => array(
					'reservation_menu_list_display_mode' => 'card',
					'currency_symbol'                    => '',
					'tax_label_text'                     => '',
				),
			),
		);

		$page    = $this->create_page();
		$service = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );

		foreach ( $test_cases as $case ) {
			// 保存済みの設定を用意する（初期値に 3 項目の保存済みの値を重ねる）。
			delete_option( Settings_Repository::OPTION_KEY );
			$repository = new Settings_Repository();
			update_option( Settings_Repository::OPTION_KEY, array_merge( $repository->get_default_settings(), $case['saved'] ) );

			// 指定タブで描画したフォームから、ブラウザが送信する値を組み立てる。
			$submitted = $this->get_submitted_values( $this->render_tab( $page, $case['tab'] ) );
			$payload   = $submitted['vkbm_provider_settings'] ?? array();
			// handle_form_submission() と同じく、設定項目ではないライセンスキーは取り除いてから保存する。
			unset( $payload['license_key'], $payload['delete_license_key'] );

			$result = $service->save_settings( $payload );
			$this->assertNotWPError(
				$result,
				$case['test_condition_name'] . '（前提: 保存できる）' . ( is_wp_error( $result ) ? ' ' . wp_json_encode( $result->get_error_data() ) : '' )
			);

			// 保存後の 3 項目を取り出して比較する。
			$saved_settings = get_option( Settings_Repository::OPTION_KEY, array() );
			$actual         = array();
			foreach ( array_keys( $case['expected'] ) as $key ) {
				$actual[ $key ] = $saved_settings[ $key ] ?? '(保存されていない)';
			}
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * フッターの背景色・文字色（デザイン設定タブの行）が、全タブで 1 つずつ出力され、変更した値で保存されることを検証する（#825）。
	 *
	 * 行は全タブで描画するため、hidden を併せて出すと同じ名前が二重に送られ、
	 * フォーム末尾の hidden が後勝ちになってデザイン設定タブでの変更が元の値に戻る。これを防ぐ。
	 */
	public function test_save_settings_design_footer_colors(): void {
		$saved_values = array(
			'design_footer_background_color' => '#1e3a8a',
			'design_footer_text_color'       => '#ffcc00',
		);
		$typed_values = array(
			'design_footer_background_color' => '#000000',
			'design_footer_text_color'       => '#ffffff',
		);
		// 入力欄の id（値の書き換えに使う）。
		$field_ids = array(
			'design_footer_background_color' => 'vkbm-design-footer-background-color',
			'design_footer_text_color'       => 'vkbm-design-footer-text-color',
		);

		$test_cases = array(
			array(
				'test_condition_name' => '「デザイン」タブで2色を書き換えて保存 => 書き換えた値で保存される',
				'tab'                 => 'design',
				'typed'               => $typed_values,
				'expected'            => $typed_values,
			),
			array(
				'test_condition_name' => '「デザイン」タブで書き換えずに保存 => 保存済みの値のまま',
				'tab'                 => 'design',
				'typed'               => array(),
				'expected'            => $saved_values,
			),
			array(
				'test_condition_name' => '「デザイン」タブで背景色だけを空欄にして保存 => 背景色は空、文字色は保存済みの値のまま',
				'tab'                 => 'design',
				'typed'               => array( 'design_footer_background_color' => '' ),
				'expected'            => array(
					'design_footer_background_color' => '',
					'design_footer_text_color'       => '#ffcc00',
				),
			),
		);
		// デザイン以外のタブ（タブ指定なしを含む）から保存 => 保存済みの値のまま。
		foreach ( array_merge( array_diff( self::FORM_TABS, array( 'design' ) ), array( '' ) ) as $tab ) {
			$test_cases[] = array(
				'test_condition_name' => '「' . ( '' === $tab ? 'タブ指定なし' : $tab ) . '」タブから保存 => 保存済みの値のまま',
				'tab'                 => $tab,
				'typed'               => array(),
				'expected'            => $saved_values,
			);
		}

		$page    = $this->create_page();
		$service = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );

		foreach ( $test_cases as $case ) {
			// 保存済みの設定を用意する。
			delete_option( Settings_Repository::OPTION_KEY );
			$repository = new Settings_Repository();
			update_option( Settings_Repository::OPTION_KEY, array_merge( $repository->get_default_settings(), $saved_values ) );

			$html = $this->render_tab( $page, $case['tab'] );

			// どのタブでも、2色はデザイン設定タブの行の入力欄 1 つずつだけ出力される（hidden と二重にならない）。
			foreach ( array_keys( $saved_values ) as $key ) {
				$this->assertSame(
					1,
					substr_count( $html, 'name="vkbm_provider_settings[' . $key . ']"' ),
					$case['test_condition_name'] . '（' . $key . ' の出力数）'
				);
				$this->assertMatchesRegularExpression(
					'/<tr class="vkbm-provider-settings__tab-design(?: [^"]*)?"[^>]*>(?:(?!<\/tr>).)*name="vkbm_provider_settings\[' . $key . '\]/s',
					$html,
					$case['test_condition_name'] . '（' . $key . ' がデザイン設定タブの行にあること）'
				);
			}

			// 入力欄を書き換えた状態を再現する。
			foreach ( $case['typed'] as $key => $typed ) {
				$html = preg_replace(
					'/(id="' . $field_ids[ $key ] . '"\s+name="vkbm_provider_settings\[' . $key . '\]"\s+value=")[^"]*"/',
					'${1}' . $typed . '"',
					$html,
					-1,
					$replaced
				);
				$this->assertSame( 1, $replaced, $case['test_condition_name'] . '（前提: ' . $key . ' の入力欄を書き換えられる）' );
			}

			$submitted = $this->get_submitted_values( $html );
			$payload   = $submitted['vkbm_provider_settings'] ?? array();
			unset( $payload['license_key'], $payload['delete_license_key'] );

			$result = $service->save_settings( $payload );
			$this->assertNotWPError( $result, $case['test_condition_name'] . '（前提: 保存できる）' );

			// 保存後の2色を取り出して比較する。
			$saved_settings = get_option( Settings_Repository::OPTION_KEY, array() );
			$actual         = array();
			foreach ( array_keys( $case['expected'] ) as $key ) {
				$actual[ $key ] = $saved_settings[ $key ] ?? '(保存されていない)';
			}
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}

		// システム設定タブの「フッター」の説明とデザイン設定タブの色の欄を、互いにリンクでつなぐ。
		$system_html = $this->render_tab( $page, 'system' );
		$design_html = $this->render_tab( $page, 'design' );
		$this->assertStringContainsString( 'tab=design#vkbm-design-footer-colors', $system_html, 'システム設定タブから、デザイン設定タブの色の欄へのリンクが出ること' );
		$this->assertSame( 1, substr_count( $design_html, 'id="vkbm-design-footer-colors"' ), 'デザイン設定タブの色の欄にリンク先の id が 1 つだけ付くこと' );
		$this->assertStringContainsString( 'tab=system#vkbm-reservation-display-elements', $design_html, 'デザイン設定タブから、システム設定タブの「予約ページの表示要素」へのリンクが出ること' );
		$this->assertSame( 1, substr_count( $system_html, 'id="vkbm-reservation-display-elements"' ), 'システム設定タブの「予約ページの表示要素」にリンク先の id が 1 つだけ付くこと' );
	}

	/**
	 * 「見出しをフッターと同じ色にする」（デザイン設定タブのチェックボックス）が、どのタブから保存しても保存済みの値を保ち、
	 * デザイン設定タブでオン・オフを切り替えて保存できることを検証する（#864）。
	 *
	 * チェックを外すとチェックボックスは値を送らないため、直前に置いた値 0 の hidden が送られてオフで保存される。
	 * フッターの色がどちらも空欄で行を隠しているときも、hidden とチェックボックスは送信され、チェックの状態が保たれる。
	 */
	public function test_save_settings_design_menu_heading_use_footer_color(): void {
		$key      = 'design_menu_heading_use_footer_color';
		$field_id = 'vkbm-design-menu-heading-use-footer-color';

		$test_cases = array(
			array(
				'test_condition_name' => '保存済みオフ・フッターの背景色あり（行を表示）・「デザイン」タブでチェックを入れて保存 => オン',
				'saved'               => false,
				'footer_bg'           => '#1e3a8a',
				'tab'                 => 'design',
				'check'               => true,
				'expected'            => true,
			),
			array(
				'test_condition_name' => '保存済みオン・フッターの背景色あり（行を表示）・「デザイン」タブでチェックを外して保存 => オフ',
				'saved'               => true,
				'footer_bg'           => '#1e3a8a',
				'tab'                 => 'design',
				'check'               => false,
				'expected'            => false,
			),
		);
		// フッターの色の有無（行の表示・非表示）を問わず、どのタブ（タブ指定なしを含む）から、チェックを触らずに保存 => 保存済みの値のまま。
		foreach ( array( '', '#1e3a8a' ) as $footer_bg ) {
			foreach ( array( true, false ) as $saved ) {
				foreach ( array_merge( self::FORM_TABS, array( '' ) ) as $tab ) {
					$test_cases[] = array(
						'test_condition_name' => '保存済み' . ( $saved ? 'オン' : 'オフ' ) . '・フッターの色' . ( '' === $footer_bg ? 'なし（行を隠す）' : 'あり（行を表示）' ) . '・「' . ( '' === $tab ? 'タブ指定なし' : $tab ) . '」タブから保存 => 保存済みの値のまま',
						'saved'               => $saved,
						'footer_bg'           => $footer_bg,
						'tab'                 => $tab,
						'check'               => null,
						'expected'            => $saved,
					);
				}
			}
		}

		$page    = $this->create_page();
		$service = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );

		foreach ( $test_cases as $case ) {
			// 保存済みの設定を用意する。
			delete_option( Settings_Repository::OPTION_KEY );
			$repository = new Settings_Repository();
			update_option(
				Settings_Repository::OPTION_KEY,
				array_merge(
					$repository->get_default_settings(),
					array(
						$key                             => $case['saved'],
						'design_footer_background_color' => $case['footer_bg'],
					)
				)
			);

			$html = $this->render_tab( $page, $case['tab'] );

			// どのタブでも、hidden とチェックボックスが 1 つずつ、デザイン設定タブの行に出力される。
			$this->assertSame( 2, substr_count( $html, 'name="vkbm_provider_settings[' . $key . ']"' ), $case['test_condition_name'] . '（出力数）' );
			$this->assertMatchesRegularExpression(
				'/<tr class="vkbm-provider-settings__tab-design(?: [^"]*)?"[^>]*>(?:(?!<\/tr>).)*<input type="hidden" name="vkbm_provider_settings\[' . $key . '\]" value="0" \/>(?:(?!<\/tr>).)*id="' . $field_id . '"/s',
				$html,
				$case['test_condition_name'] . '（hidden がチェックボックスより前にあること）'
			);

			// チェックの付け外しを再現する。
			if ( null !== $case['check'] ) {
				$html = preg_replace( '/(id="' . $field_id . '"[^>]*?)\s+checked=\'checked\'/', '${1}', $html );
				if ( $case['check'] ) {
					$html = preg_replace( '/(id="' . $field_id . '")/', '${1} checked=\'checked\'', $html, -1, $replaced );
					$this->assertSame( 1, $replaced, $case['test_condition_name'] . '（前提: チェックを入れられる）' );
				}
			}

			$submitted = $this->get_submitted_values( $html );
			$payload   = $submitted['vkbm_provider_settings'] ?? array();
			unset( $payload['license_key'], $payload['delete_license_key'] );

			$result = $service->save_settings( $payload );
			$this->assertNotWPError( $result, $case['test_condition_name'] . '（前提: 保存できる）' );

			$saved_settings = get_option( Settings_Repository::OPTION_KEY, array() );
			$this->assertSame( $case['expected'], $saved_settings[ $key ] ?? '(保存されていない)', $case['test_condition_name'] );
		}
	}

	/**
	 * 「見出しをフッターと同じ色にする」の行が、行見出しを持たず、
	 * 保存済みのフッターの背景色・文字色のどちらかがあるときだけ表示で出力されることを検証する（#864）。
	 */
	public function test_render_page_design_menu_heading_use_footer_color_row(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'フッターの背景色・文字色とも空欄 => 行を隠して出力',
				'footer_bg'           => '',
				'footer_text'         => '',
				'expected_hidden'     => true,
			),
			array(
				'test_condition_name' => 'フッターの背景色だけ設定 => 行を表示で出力',
				'footer_bg'           => '#1e3a8a',
				'footer_text'         => '',
				'expected_hidden'     => false,
			),
			array(
				'test_condition_name' => 'フッターの文字色だけ設定 => 行を表示で出力',
				'footer_bg'           => '',
				'footer_text'         => '#ffcc00',
				'expected_hidden'     => false,
			),
			array(
				'test_condition_name' => 'フッターの背景色・文字色とも設定 => 行を表示で出力',
				'footer_bg'           => '#1e3a8a',
				'footer_text'         => '#ffcc00',
				'expected_hidden'     => false,
			),
		);

		$page = $this->create_page();

		foreach ( $test_cases as $case ) {
			// 保存済みのフッターの色を用意する（チェックはオンにして、隠すかどうかがチェックの状態に左右されないことも確かめる）。
			delete_option( Settings_Repository::OPTION_KEY );
			$repository = new Settings_Repository();
			update_option(
				Settings_Repository::OPTION_KEY,
				array_merge(
					$repository->get_default_settings(),
					array(
						'design_footer_background_color'       => $case['footer_bg'],
						'design_footer_text_color'             => $case['footer_text'],
						'design_menu_heading_use_footer_color' => true,
					)
				)
			);

			$html = $this->render_tab( $page, 'design' );

			// 行の開始タグと、行の中身（</tr> まで）を取り出す。
			$this->assertSame(
				1,
				preg_match( '/<tr class="vkbm-provider-settings__tab-design" id="vkbm-design-menu-heading-use-footer-color-row"([^>]*)>(.*?)<\/tr>/s', $html, $matches ),
				$case['test_condition_name'] . '（前提: 行が 1 つ出力される）'
			);

			// 行見出し（th）は置かず、先頭は空の td にする（チェックボックスの左端を上の入力欄とそろえるため colspan でまとめない）。
			$this->assertStringNotContainsString( '<th', $matches[2], $case['test_condition_name'] . '（行見出しを置かない）' );
			$this->assertMatchesRegularExpression( '/^\s*<td><\/td>\s*<td>/', $matches[2], $case['test_condition_name'] . '（先頭が空の td）' );
			$this->assertStringNotContainsString( 'colspan', $matches[2], $case['test_condition_name'] . '（colspan でまとめない）' );

			// フッターの色の有無で hidden 属性を出し分ける。
			$this->assertSame( $case['expected_hidden'], 1 === preg_match( '/(^|\s)hidden(\s|$)/', $matches[1] ), $case['test_condition_name'] );

			// 隠していても、チェック状態は保存済みのまま出力する。
			$this->assertMatchesRegularExpression( '/id="vkbm-design-menu-heading-use-footer-color"[^>]*checked=\'checked\'/', $html, $case['test_condition_name'] . '（チェック状態を保つ）' );
		}
	}

	/**
	 * 指定タブで設定画面を描画し、HTML を返す。
	 *
	 * @param Provider_Settings_Page $page 設定画面。
	 * @param string                 $tab  タブ（空文字はタブ指定なし）。
	 * @return string 描画した HTML。
	 */
	private function render_tab( Provider_Settings_Page $page, string $tab ): string {
		$_GET = '' === $tab ? array() : array( 'tab' => $tab );
		ob_start();
		$page->render_page();
		$html = (string) ob_get_clean();
		$_GET = array();

		return $html;
	}

	/**
	 * HTML に出力された設定項目名（vkbm_provider_settings[項目名]）を重複なしで返す。
	 *
	 * ライセンスキーは設定項目ではなく、保存処理の前に取り除かれるため除外する。
	 *
	 * @param string $html 設定画面の HTML。
	 * @return string[] 設定項目名の一覧。
	 */
	private function get_form_keys( string $html ): array {
		preg_match_all( '/name="vkbm_provider_settings\[([a-z0-9_]+)\]/', $html, $matches );

		return array_values( array_diff( array_unique( $matches[1] ), array( 'license_key', 'delete_license_key' ) ) );
	}

	/**
	 * 設定画面のフォームで「保存」を押したときにブラウザが送信する値を、HTML から組み立てて返す。
	 *
	 * ブラウザの送信ルールに合わせ、無効化（disabled）された入力欄、チェックされていないチェックボックス・
	 * ラジオボタン、行の追加用テンプレート（template 要素の中）は送信しない。
	 *
	 * @param string $html 設定画面の HTML。
	 * @return array<string, mixed> 送信値（$_POST と同じ形）。
	 */
	private function get_submitted_values( string $html ): array {
		$dom = new DOMDocument();
		// HTML5 の要素（template など）に対する警告は検証と無関係なため抑止する。
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$xpath = new DOMXPath( $dom );
		$form  = $xpath->query( '//form' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $form, '前提: 保存フォームが描画されている' );

		$pairs = array();
		foreach ( $xpath->query( '(.//input|.//select|.//textarea)[not(ancestor::template)]', $form ) as $element ) {
			$name = $element->getAttribute( 'name' );
			if ( '' === $name || $element->hasAttribute( 'disabled' ) ) {
				continue;
			}

			$tag = strtolower( $element->nodeName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM のプロパティ名.
			if ( 'textarea' === $tag ) {
				$pairs[] = array( $name, $element->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM のプロパティ名.
				continue;
			}

			if ( 'select' === $tag ) {
				// 選択状態の option、無ければ先頭の option の値が送信される。
				$options  = $xpath->query( './/option', $element );
				$selected = null;
				foreach ( $options as $option ) {
					if ( $option->hasAttribute( 'selected' ) ) {
						$selected = $option;
						break;
					}
				}
				if ( null === $selected && $options->length > 0 ) {
					$selected = $options->item( 0 );
				}
				if ( null !== $selected ) {
					$pairs[] = array( $name, $selected->hasAttribute( 'value' ) ? $selected->getAttribute( 'value' ) : $selected->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM のプロパティ名.
				}
				continue;
			}

			// input 要素: ボタン類は値を送らず、チェックボックス・ラジオボタンはチェック時だけ送る。
			$type = strtolower( '' !== $element->getAttribute( 'type' ) ? $element->getAttribute( 'type' ) : 'text' );
			if ( in_array( $type, array( 'submit', 'button', 'reset', 'image', 'file' ), true ) ) {
				continue;
			}
			$is_checkable = in_array( $type, array( 'checkbox', 'radio' ), true );
			if ( $is_checkable && ! $element->hasAttribute( 'checked' ) ) {
				continue;
			}
			$default_value = $is_checkable ? 'on' : '';
			$pairs[]       = array( $name, $element->hasAttribute( 'value' ) ? $element->getAttribute( 'value' ) : $default_value );
		}

		// ブラウザと同じく「名前=値」を & でつないだ送信データにし、PHP と同じ規則で配列に戻す。
		$query = implode(
			'&',
			array_map(
				static function ( array $pair ): string {
					return rawurlencode( $pair[0] ) . '=' . rawurlencode( $pair[1] );
				},
				$pairs
			)
		);
		parse_str( $query, $submitted );

		return $submitted;
	}

	/**
	 * 実際の依存関係で設定画面を生成する。
	 *
	 * @return Provider_Settings_Page 設定画面。
	 */
	private function create_page(): Provider_Settings_Page {
		$service = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );

		return new Provider_Settings_Page( $service );
	}
}
