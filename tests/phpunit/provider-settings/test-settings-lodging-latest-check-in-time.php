<?php
/**
 * 基本設定の「最終チェックイン時刻（宿泊プラン）」（lodging_latest_check_in_time）の保存に関するテスト（#1136）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\ProviderSettings;

use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use WP_UnitTestCase;

/**
 * 最終チェックイン時刻の既定値のサニタイズと、別タブの保存での引き継ぎを検証する。
 *
 * @group provider-settings
 * @group lodging
 */
class Settings_Lodging_Latest_Check_In_Time_Test extends WP_UnitTestCase {

	/**
	 * setUp で退避する全体設定（tearDown で復元）。
	 *
	 * @var mixed
	 */
	private $original_settings = false;

	/**
	 * テスト前に全体設定を退避し、未保存の状態から始める。
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->original_settings = get_option( Settings_Repository::OPTION_KEY, false );
		delete_option( Settings_Repository::OPTION_KEY );
	}

	/**
	 * テスト後に全体設定を元に戻す。
	 */
	protected function tearDown(): void {
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		parent::tearDown();
	}

	/**
	 * get_default_settings: 初期値は未設定（空文字）。業種プリセット「宿泊」でも値を入れない。
	 */
	public function test_get_default_settings(): void {
		$defaults = ( new Settings_Repository() )->get_default_settings();

		$this->assertSame( '', $defaults[ Settings_Repository::KEY_LODGING_LATEST_CHECK_IN_TIME ], '最終チェックイン時刻の初期値は未設定' );
	}

	/**
	 * sanitize_lodging_latest_check_in_time: 「時」「分」のプルダウンの送信値と、HH:MM の直接の値を保存用の値にする。
	 */
	public function test_sanitize_lodging_latest_check_in_time(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '時 20・分 30 => 20:30',
				'input'               => array(
					'lodging_latest_check_in_hour'   => '20',
					'lodging_latest_check_in_minute' => '30',
				),
				'data'                => array(),
				'expected'            => '20:30',
			),
			array(
				'test_condition_name' => '時が「設定しない」（空文字）・分は無効で送られない => 空文字',
				'input'               => array( 'lodging_latest_check_in_hour' => '' ),
				'data'                => array( 'lodging_latest_check_in_time' => '20:00' ),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '時 21 だけ（分の無効を解く処理が動かなかった） => 21:00',
				'input'               => array( 'lodging_latest_check_in_hour' => '21' ),
				'data'                => array(),
				'expected'            => '21:00',
			),
			array(
				'test_condition_name' => '時のプルダウンが送られれば、HH:MM の値より優先する',
				'input'               => array(
					'lodging_latest_check_in_hour'   => '19',
					'lodging_latest_check_in_minute' => '00',
				),
				'data'                => array( 'lodging_latest_check_in_time' => '22:00' ),
				'expected'            => '19:00',
			),
			array(
				'test_condition_name' => 'HH:MM を直接渡す（REST など） => その値',
				'input'               => array( 'lodging_latest_check_in_time' => '18:30' ),
				'data'                => array( 'lodging_latest_check_in_time' => '18:30' ),
				'expected'            => '18:30',
			),
			array(
				'test_condition_name' => '不正な時 25 => 空文字',
				'input'               => array(
					'lodging_latest_check_in_hour'   => '25',
					'lodging_latest_check_in_minute' => '00',
				),
				'data'                => array(),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '不正な HH:MM 24:00 => 空文字',
				'input'               => array(),
				'data'                => array( 'lodging_latest_check_in_time' => '24:00' ),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '時が配列（不正な送信） => 空文字',
				'input'               => array( 'lodging_latest_check_in_hour' => array( '20' ) ),
				'data'                => array(),
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame(
				$case['expected'],
				Settings_Sanitizer::sanitize_lodging_latest_check_in_time( $case['input'], $case['data'] ),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * save_settings: プルダウンの送信値を HH:MM で保存し、プルダウンの送信名は保存しない。
	 * 欄が送られない保存（別のタブなど）では保存済みの値を引き継ぎ、「設定しない」の保存は未設定にする。
	 */
	public function test_save_settings(): void {
		$repository = new Settings_Repository();
		$service    = new Settings_Service( $repository, new Settings_Sanitizer() );

		$test_cases = array(
			array(
				'test_condition_name' => '時 20・分 00 を保存 => 20:00',
				'payload'             => array(
					'lodging_latest_check_in_hour'   => '20',
					'lodging_latest_check_in_minute' => '00',
				),
				'expected'            => '20:00',
			),
			array(
				'test_condition_name' => '欄が送られない別タブの保存 => 保存済みの 20:00 を引き継ぐ',
				'payload'             => array( 'provider_name' => '別の保存' ),
				'expected'            => '20:00',
			),
			array(
				'test_condition_name' => '時 21・分 30 に変えて保存 => 21:30',
				'payload'             => array(
					'lodging_latest_check_in_hour'   => '21',
					'lodging_latest_check_in_minute' => '30',
				),
				'expected'            => '21:30',
			),
			array(
				'test_condition_name' => '「設定しない」で保存 => 未設定（空文字）',
				'payload'             => array( 'lodging_latest_check_in_hour' => '' ),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '未設定のまま別タブの保存 => 未設定のまま',
				'payload'             => array( 'provider_name' => 'もう一度別の保存' ),
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			$result = $service->save_settings( $case['payload'] );
			$this->assertTrue( $result, $case['test_condition_name'] . '（保存の成功）' );

			$stored = get_option( Settings_Repository::OPTION_KEY );
			$this->assertSame( $case['expected'], $stored[ Settings_Repository::KEY_LODGING_LATEST_CHECK_IN_TIME ], $case['test_condition_name'] );
			$this->assertArrayNotHasKey( Settings_Sanitizer::LODGING_LATEST_CHECK_IN_HOUR_FIELD, $stored, $case['test_condition_name'] . '（時の送信名は保存しない）' );
			$this->assertArrayNotHasKey( Settings_Sanitizer::LODGING_LATEST_CHECK_IN_MINUTE_FIELD, $stored, $case['test_condition_name'] . '（分の送信名は保存しない）' );
		}
	}
}
