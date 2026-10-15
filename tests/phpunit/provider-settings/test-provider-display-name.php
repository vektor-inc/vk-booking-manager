<?php
/**
 * Provider_Display_Name（お客様向け文言に差し込む事業者名の解決）の単体テスト（#801）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\ProviderSettings;

use VKBookingManager\ProviderSettings\Provider_Display_Name;
use WP_UnitTestCase;
use function update_option;

/**
 * Provider_Display_Name::resolve() を検証するテストクラス。
 *
 * @group provider-settings
 */
class Provider_Display_Name_Test extends WP_UnitTestCase {

	/**
	 * 事業者名があればそれを、空ならサイト名を返すこと。
	 */
	public function test_resolve(): void {
		update_option( 'blogname', 'テストサイト' );

		$test_cases = array(
			array(
				'test_condition_name' => '事業者名が設定されている => 事業者名を返す',
				'settings'            => array( 'provider_name' => 'テスト事業者' ),
				'expected'            => 'テスト事業者',
			),
			array(
				'test_condition_name' => '事業者名の前後に空白がある => 空白を除いて返す',
				'settings'            => array( 'provider_name' => '  テスト事業者  ' ),
				'expected'            => 'テスト事業者',
			),
			array(
				'test_condition_name' => '事業者名が空 => サイト名を返す',
				'settings'            => array( 'provider_name' => '' ),
				'expected'            => 'テストサイト',
			),
			array(
				'test_condition_name' => '事業者名のキーが無い => サイト名を返す',
				'settings'            => array(),
				'expected'            => 'テストサイト',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame(
				$case['expected'],
				Provider_Display_Name::resolve( $case['settings'] ),
				$case['test_condition_name']
			);
		}
	}
}
