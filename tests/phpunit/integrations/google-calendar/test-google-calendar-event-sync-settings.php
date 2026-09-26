<?php
/**
 * 「予定に載せる情報」の設定を扱うクラスのテスト。
 *
 * issue #476。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Integrations\GoogleCalendar;

use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Event_Sync_Settings;
use WP_UnitTestCase;
use function delete_option;

/**
 * Google_Calendar_Event_Sync_Settings のテスト。
 */
class Test_Google_Calendar_Event_Sync_Settings extends WP_UnitTestCase {

	/**
	 * テスト対象。
	 *
	 * @var Google_Calendar_Event_Sync_Settings
	 */
	private $settings;

	/**
	 * 各テストの前に、保存済みの設定を消してテスト対象を作り直す。
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		delete_option( Google_Calendar_Event_Sync_Settings::OPTION_NAME );
		$this->settings = new Google_Calendar_Event_Sync_Settings();
	}

	/**
	 * 各テストの後に、保存した設定を消す。
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( Google_Calendar_Event_Sync_Settings::OPTION_NAME );

		parent::tear_down();
	}

	/**
	 * 未保存のときは PHP 定数の初期値（予約人数・担当・管理画面リンク）が返ること。
	 *
	 * 管理用メモに相当する項目は選択肢（get_field_keys()）に含まれないこと。
	 */
	public function test_get_enabled_fields_defaults(): void {
		$this->assertSame(
			Google_Calendar_Event_Sync_Settings::DEFAULT_ENABLED_FIELDS,
			$this->settings->get_enabled_fields(),
			'未保存のときは初期値（予約人数・担当・予約管理画面へのリンク）を返すこと'
		);

		$this->assertNotContains(
			'internal_note',
			Google_Calendar_Event_Sync_Settings::get_field_keys(),
			'管理用メモに相当するキーは選択肢に含まれないこと'
		);
	}

	/**
	 * save() と get_enabled_fields()/is_enabled() の組み合わせを検証する。
	 */
	public function test_save_and_get_enabled_fields(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '選べる項目だけを保存 => そのまま返る（正常系）',
				'save'                => array(
					Google_Calendar_Event_Sync_Settings::FIELD_CUSTOMER_NAME,
					Google_Calendar_Event_Sync_Settings::FIELD_CUSTOMER_NOTE,
				),
				'expected'            => array(
					Google_Calendar_Event_Sync_Settings::FIELD_CUSTOMER_NAME,
					Google_Calendar_Event_Sync_Settings::FIELD_CUSTOMER_NOTE,
				),
			),
			array(
				'test_condition_name' => '空配列を保存 => 何もオンにならない（正常系。全項目オフ）',
				'save'                => array(),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '選べない項目（改ざん・管理用メモ相当）を含めて保存 => 無視される（異常系）',
				'save'                => array(
					Google_Calendar_Event_Sync_Settings::FIELD_GUESTS,
					'internal_note',
					'not_a_real_field',
				),
				'expected'            => array( Google_Calendar_Event_Sync_Settings::FIELD_GUESTS ),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->settings->save( $case['save'] );

			$this->assertSame( $case['expected'], $this->settings->get_enabled_fields(), $case['test_condition_name'] );

			foreach ( $case['expected'] as $field ) {
				$this->assertTrue( $this->settings->is_enabled( $field ), $case['test_condition_name'] );
			}
		}
	}
}
