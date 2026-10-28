<?php
/**
 * シフト・予約表の日表示にあるリソース状態のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use ReflectionMethod;
use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Admin\Shift_Dashboard_Page;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_UnitTestCase;

/**
 * 日表示のリソース状態を検証する。
 *
 * @group admin
 * @group lodging
 */
class Shift_Dashboard_Resource_Status_Test extends WP_UnitTestCase {

	/**
	 * 元の設定。
	 *
	 * @var mixed
	 */
	private $original_settings;

	/**
	 * 元の設定を保存する。
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->original_settings = get_option( Settings_Repository::OPTION_KEY, false );
	}

	/**
	 * 設定とプリセットのキャッシュを復元する。
	 */
	protected function tearDown(): void {
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		Industry_Presets::clear_cache();
		parent::tearDown();
	}

	/**
	 * 日表示の状態解決を呼び出す。
	 *
	 * @param string $status_key 状態キー。
	 * @param int    $slot_count 時間帯の件数。
	 * @return array{label:string,type:string} 表示用の状態。
	 */
	private function resolve_resource_status( string $status_key, int $slot_count = 0 ): array {
		$method = new ReflectionMethod( Shift_Dashboard_Page::class, 'resolve_resource_status' );
		$method->setAccessible( true );

		return $method->invoke( new Shift_Dashboard_Page(), $status_key, $slot_count, 0.0 );
	}

	/**
	 * 時間帯の無いレーンの表示文言を呼び出す。
	 *
	 * @param array{label:string,type:string} $status_info 表示用の状態。
	 * @return string レーンに表示する文言。
	 */
	private function resolve_empty_lane_label( array $status_info ): string {
		$method = new ReflectionMethod( Shift_Dashboard_Page::class, 'resolve_empty_lane_label' );
		$method->setAccessible( true );

		return $method->invoke( new Shift_Dashboard_Page(), $status_info );
	}

	/**
	 * 宿泊と宿泊以外で、時間帯の無い日の表示を切り替えることを検証する。
	 */
	public function test_resolve_resource_status(): void {
		$defaults = ( new Settings_Repository() )->get_default_settings();
		$cases    = array(
			array(
				'test_condition_name' => '宿泊の販売日（open・時間帯なし）=> 販売日',
				'preset'              => 'lodging',
				'status'              => 'open',
				'expected_label'      => __( 'Days on sale', 'vk-booking-manager' ),
				'expected_type'       => 'working',
			),
			array(
				'test_condition_name' => '宿泊の販売日（open・時間帯あり）=> 販売日',
				'preset'              => 'lodging',
				'status'              => 'open',
				'slot_count'          => 1,
				'expected_label'      => __( 'Days on sale', 'vk-booking-manager' ),
				'expected_type'       => 'working',
				'free_label'          => __( 'Work schedule', 'vk-booking-manager' ),
				'free_type'           => 'working',
			),
			array(
				'test_condition_name' => '宿泊の臨時販売日（temporary_open・時間帯なし）=> 販売日',
				'preset'              => 'lodging',
				'status'              => 'temporary_open',
				'expected_label'      => __( 'Days on sale', 'vk-booking-manager' ),
				'expected_type'       => 'working',
			),
			array(
				'test_condition_name' => '宿泊の販売しない日（休み・時間帯なし）=> 販売しない',
				'preset'              => 'lodging',
				'status'              => 'unavailable',
				'expected_label'      => __( 'Not on sale', 'vk-booking-manager' ),
				'expected_type'       => 'off',
				'free_label'          => __( 'Off', 'vk-booking-manager' ),
				'free_type'           => 'off',
			),
			array(
				'test_condition_name' => '宿泊の定休日（regular_holiday・時間帯なし）=> 販売しない',
				'preset'              => 'lodging',
				'status'              => 'regular_holiday',
				'expected_label'      => __( 'Not on sale', 'vk-booking-manager' ),
				'expected_type'       => 'off',
				'free_label'          => __( 'Regular holiday', 'vk-booking-manager' ),
				'free_type'           => 'off',
			),
			array(
				'test_condition_name' => '宿泊の臨時休業（temporary_closed・時間帯なし）=> 販売しない',
				'preset'              => 'lodging',
				'status'              => 'temporary_closed',
				'expected_label'      => __( 'Not on sale', 'vk-booking-manager' ),
				'expected_type'       => 'off',
				'free_label'          => __( 'Temporary closure', 'vk-booking-manager' ),
				'free_type'           => 'off',
			),
			array(
				'test_condition_name' => '宿泊の販売日が無い月 => 販売日未登録',
				'preset'              => 'lodging',
				'status'              => 'not_set',
				'expected_label'      => __( 'Days on Sale not registered', 'vk-booking-manager' ),
				'expected_type'       => 'pending',
			),
			array(
				'test_condition_name' => '宿泊以外の時間帯なし（open）=> シフト未設定',
				'preset'              => 'custom',
				'status'              => 'open',
				'expected_label'      => __( 'Shift not set', 'vk-booking-manager' ),
				'expected_type'       => 'pending',
			),
		);

		foreach ( $cases as $case ) {
			update_option( Settings_Repository::OPTION_KEY, array_merge( $defaults, array( 'industry_preset' => $case['preset'] ) ) );
			Industry_Presets::clear_cache();
			$actual = $this->resolve_resource_status( $case['status'], $case['slot_count'] ?? 0 );

			// 無料版は lodging を保存しても supports( 'lodging' ) が false のため、既存表示になる。
			if ( Pro_Upsell::is_free_edition() && 'lodging' === $case['preset'] ) {
				$this->assertSame( $case['free_label'] ?? __( 'Shift not set', 'vk-booking-manager' ), $actual['label'], $case['test_condition_name'] . '（無料版）' );
				$this->assertSame( $case['free_type'] ?? 'pending', $actual['type'], $case['test_condition_name'] . '（無料版）' );
				continue;
			}

			$this->assertSame( $case['expected_label'], $actual['label'], $case['test_condition_name'] );
			$this->assertSame( $case['expected_type'], $actual['type'], $case['test_condition_name'] );
		}
	}

	/**
	 * 宿泊の販売日だけ空レーンの案内を表示しないことを検証する。
	 */
	public function test_resolve_empty_lane_label(): void {
		$defaults = ( new Settings_Repository() )->get_default_settings();
		$cases    = array(
			array(
				'test_condition_name' => '宿泊の販売日（時間帯なし）=> 空欄',
				'preset'              => 'lodging',
				'status_info'         => array(
					'label' => __( 'Days on sale', 'vk-booking-manager' ),
					'type'  => 'working',
				),
				'expected'            => '',
				'free_expected'       => __( 'Shift not set.', 'vk-booking-manager' ),
			),
			array(
				'test_condition_name' => '宿泊の販売しない日（時間帯なし）=> 状態ラベル',
				'preset'              => 'lodging',
				'status_info'         => array(
					'label' => __( 'Not on sale', 'vk-booking-manager' ),
					'type'  => 'off',
				),
				'expected'            => __( 'Not on sale', 'vk-booking-manager' ),
			),
			array(
				'test_condition_name' => '宿泊の定休日（時間帯なし）=> 販売しない',
				'preset'              => 'lodging',
				'status'              => 'regular_holiday',
				'expected'            => __( 'Not on sale', 'vk-booking-manager' ),
				'free_expected'       => __( 'Regular holiday', 'vk-booking-manager' ),
			),
			array(
				'test_condition_name' => '宿泊の臨時休業（時間帯なし）=> 販売しない',
				'preset'              => 'lodging',
				'status'              => 'temporary_closed',
				'expected'            => __( 'Not on sale', 'vk-booking-manager' ),
				'free_expected'       => __( 'Temporary closure', 'vk-booking-manager' ),
			),
			array(
				'test_condition_name' => '宿泊の販売日未登録（時間帯なし）=> 未登録案内',
				'preset'              => 'lodging',
				'status_info'         => array(
					'label' => __( 'Days on Sale not registered', 'vk-booking-manager' ),
					'type'  => 'pending',
				),
				'expected'            => __( 'Days on sale are not registered.', 'vk-booking-manager' ),
				'free_expected'       => __( 'Shift not set.', 'vk-booking-manager' ),
			),
			array(
				'test_condition_name' => '宿泊以外の勤務日（時間帯なし）=> 未設定案内',
				'preset'              => 'custom',
				'status_info'         => array(
					'label' => __( 'Working', 'vk-booking-manager' ),
					'type'  => 'working',
				),
				'expected'            => __( 'Shift not set.', 'vk-booking-manager' ),
			),
		);

		foreach ( $cases as $case ) {
			update_option( Settings_Repository::OPTION_KEY, array_merge( $defaults, array( 'industry_preset' => $case['preset'] ) ) );
			Industry_Presets::clear_cache();
			$status_info = isset( $case['status'] ) ? $this->resolve_resource_status( $case['status'] ) : $case['status_info'];
			$actual      = $this->resolve_empty_lane_label( $status_info );
			$expected    = Pro_Upsell::is_free_edition() ? ( $case['free_expected'] ?? $case['expected'] ) : $case['expected'];

			$this->assertSame( $expected, $actual, $case['test_condition_name'] );
		}
	}
}
