<?php
/**
 * リソース選択文言の業種・呼び名による出し分けテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Resources;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_UnitTestCase;

/**
 * リソース選択文言を検証する。
 */
class Test_Resource_Selection_Strings extends WP_UnitTestCase {

	/**
	 * テスト前の設定。
	 *
	 * @var mixed
	 */
	private $original_settings = false;

	/**
	 * テスト前に翻訳が読み込まれていたか。
	 *
	 * @var bool
	 */
	private $was_textdomain_loaded = false;

	/**
	 * テスト前に翻訳の JIT 読み込み停止印があったか。
	 *
	 * @var bool
	 */
	private $was_textdomain_unloaded = false;

	/**
	 * テスト前の設定を退避する。
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->original_settings       = get_option( Settings_Repository::OPTION_KEY, false );
		$this->was_textdomain_loaded   = is_textdomain_loaded( 'vk-booking-manager' );
		$this->was_textdomain_unloaded = isset( $GLOBALS['l10n_unloaded']['vk-booking-manager'] );
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '呼び名と宿泊プリセットは Pro 版限定のため、無料版ではスキップする。' );
		}

		// 日本語の翻訳テーブルを確実に読み込めるよう、残留している翻訳を先にアンロードする。
		unload_textdomain( 'vk-booking-manager', false );
		load_textdomain( 'vk-booking-manager', VKBM_PLUGIN_DIR_PATH . 'languages/vk-booking-manager-ja.mo' );
	}

	/**
	 * テスト後に設定を復元する。
	 */
	protected function tearDown(): void {
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		Industry_Presets::clear_cache();

		// 第2引数 false でアンロードする（JIT 読み込みも止まるため、後で印を戻す）。
		unload_textdomain( 'vk-booking-manager', false );

		// テスト前に翻訳が読み込まれていた場合は、翻訳テーブルを戻す。
		if ( $this->was_textdomain_loaded ) {
			load_textdomain( 'vk-booking-manager', VKBM_PLUGIN_DIR_PATH . 'languages/vk-booking-manager-ja.mo' );
		}

		// 翻訳テーブルの復元とは独立して、JIT 読み込み停止印を元の状態へ戻す。
		if ( ! $this->was_textdomain_unloaded ) {
			unset( $GLOBALS['l10n_unloaded']['vk-booking-manager'] );
		}

		parent::tearDown();
	}

	/**
	 * 宿泊・宿泊以外・呼び名変更の完成文を確認する。
	 */
	public function test_vkbm_get_resource_selection_strings(): void {
		$lodging_keys = array(
			'heading',
			'checkbox',
			'unchecked',
			'max_capacity_default_1',
			'max_capacity_default_2',
			'max_capacity_nomination_1',
			'max_capacity_nomination_2',
			'min_capacity_nomination_1',
			'multiple_guests_assignment',
			'multiple_guests_conditions',
			'exclusive_minimum_condition',
			'exclusive_when_booked',
			'exclusive_user_selectable',
			'exclusive_when_booked_night',
			'exclusive_user_night',
		);
		$test_cases   = array(
			array(
				'test_condition_name' => '宿泊プリセットと客室の呼び名では指定系になる',
				'preset'              => 'lodging',
				'label'               => '客室',
				'expected_heading'    => '客室指定',
				'expected_checkbox'   => 'このサービスメニューで、予約者が客室を指定できるようにする。',
			),
			array(
				'test_condition_name' => 'サロンプリセットでは指名系を維持する',
				'preset'              => 'salon',
				'label'               => 'スタッフ',
				'expected_heading'    => 'スタッフ指名',
				'expected_checkbox'   => 'このサービスメニューでスタッフ指名を使う。',
				'expected_unchecked'  => 'チェックを外すと、このサービスメニューではスタッフが自動で割り当てられ、予約者は特定のスタッフを選べません。',
			),
			array(
				'test_condition_name' => '宿泊で呼び名を変更すると完成文にも反映する',
				'preset'              => 'lodging',
				'label'               => 'コテージ',
				'expected_heading'    => 'コテージ指定',
				'expected_checkbox'   => 'このサービスメニューで、予約者がコテージを指定できるようにする。',
			),
		);

		$repository = new Settings_Repository();
		foreach ( $test_cases as $case ) {
			$settings                            = $repository->get_default_settings();
			$settings['industry_preset']         = $case['preset'];
			$settings['resource_label_singular'] = $case['label'];
			update_option( Settings_Repository::OPTION_KEY, $settings );
			Industry_Presets::clear_cache();

			$actual = vkbm_get_resource_selection_strings();
			$this->assertSame( $case['expected_heading'], $actual['heading'], $case['test_condition_name'] . ' / 見出し' );
			$this->assertSame( $case['expected_checkbox'], $actual['checkbox'], $case['test_condition_name'] . ' / チェックボックス' );
			if ( isset( $case['expected_unchecked'] ) ) {
				$this->assertSame( $case['expected_unchecked'], $actual['unchecked'], $case['test_condition_name'] . ' / チェックを外した場合の説明' );
			}
			if ( 'lodging' === $case['preset'] ) {
				foreach ( $lodging_keys as $key ) {
					$this->assertArrayHasKey( $key, $actual, $case['test_condition_name'] . ' / 宿泊用キー' );
					$this->assertDoesNotMatchRegularExpression( '/スタッフ|指名|staff|nomination/i', $actual[ $key ], $case['test_condition_name'] . ' / ' . $key );
				}
			}
		}
	}
}
