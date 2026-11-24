<?php
/**
 * SiteGuard_Rest_Api_Check（SiteGuard が予約画面の通信を止めているかの判定、#1154）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Integrations\SiteGuard;

use VKBookingManager\Integrations\SiteGuard\SiteGuard_Rest_Api_Check;
use WP_UnitTestCase;

/**
 * SiteGuard と同じ一致判定と、設定値から「止められている」と判定する条件を検証する。
 *
 * @group integrations
 */
class SiteGuard_Rest_Api_Check_Test extends WP_UnitTestCase {

	/**
	 * テスト後に SiteGuard の設定値を消す。
	 */
	public function tear_down(): void {
		delete_option( SiteGuard_Rest_Api_Check::OPTION_NAME );
		parent::tear_down();
	}

	/**
	 * SiteGuard が有効で、予約画面の通信を止める設定値を返す。
	 *
	 * @param string $exclude 除外リスト（カンマ区切り）。
	 * @return array<string, string> 設定値。
	 */
	private function blocking_config( string $exclude ): array {
		return array(
			'block_author_query_enable' => '1',
			'disable_restapi_enable'    => '1',
			'disable_restapi_exclude'   => $exclude,
		);
	}

	/**
	 * get_required_exclude_line(): 除外に書く行は vkbm/v1 で、readme.txt の FAQ にも同じ行が載っている。
	 */
	public function test_get_required_exclude_line(): void {
		$this->assertSame( 'vkbm/v1', SiteGuard_Rest_Api_Check::get_required_exclude_line() );

		// readme.txt は手で書くため、コードの値とずれていないかをここで確かめる。
		$readme = (string) file_get_contents( dirname( __DIR__, 4 ) . '/readme.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- ローカルのファイルを読むため。
		$this->assertStringContainsString( '`' . SiteGuard_Rest_Api_Check::get_required_exclude_line() . '`', $readme );
	}

	/**
	 * is_route_excluded(): SiteGuard の rest_pre_dispatch 側と同じく、`/<行>` と完全一致するか `/<行>/` で始まるときだけ除外になる。
	 */
	public function test_is_route_excluded(): void {
		$route      = '/vkbm/v1/service-menus';
		$test_cases = array(
			array(
				'test_condition_name' => 'vkbm/v1 の行がある => 除外',
				'exclude'             => 'oembed,contact-form-7,akismet,vkbm/v1',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'vkbm の行（ネームスペースの手前の区切りまで） => /vkbm/ で始まるので除外',
				'exclude'             => 'vkbm',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '前後に空白・スラッシュがある行 => SiteGuard と同じく取り除いてから比べるので除外',
				'exclude'             => 'oembed, /vkbm/v1/ ,akismet',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'vkbm/v（前方一致だけ） => 区切りで一致しないので除外にならない',
				'exclude'             => 'vkbm/v',
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'vkbm/v1x（似た別のネームスペース） => 除外にならない',
				'exclude'             => 'vkbm/v1x',
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'wp/v2 だけ => 除外にならない',
				'exclude'             => 'oembed,wp/v2',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '空要素・空白だけの要素 => 無視して除外にならない（/ 1 本にもならない）',
				'exclude'             => ',, ,/',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '除外リストが空 => 除外にならない',
				'exclude'             => '',
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], SiteGuard_Rest_Api_Check::is_route_excluded( $route, $case['exclude'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * is_blocking_config(): 2 つの機能が両方有効で、予約画面の通信先が除外で覆われていないときだけ true。
	 */
	public function test_is_blocking_config(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '両方有効・除外に vkbm/v1 が無い => 止められている',
				'config'              => $this->blocking_config( 'oembed,contact-form-7,akismet' ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '両方有効・除外に vkbm/v1 がある => 止められていない',
				'config'              => $this->blocking_config( 'oembed,contact-form-7,akismet,vkbm/v1' ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '両方有効・除外に vkbm がある => 予約画面の通信先はすべて /vkbm/ で始まるので止められていない',
				'config'              => $this->blocking_config( 'vkbm' ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '両方有効・除外が vkbm/v（前方一致だけ） => 止められている',
				'config'              => $this->blocking_config( 'vkbm/v' ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '両方有効・除外が vkbm/v1/service-menus だけ => ほかの通信先が止められている',
				'config'              => $this->blocking_config( 'vkbm/v1/service-menus' ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '両方有効・予約画面の主な通信先 6 本を個別に除外 => resources・calendar-meta など残りの vkbm/v1 が止められている',
				'config'              => $this->blocking_config( 'vkbm/v1/provider-settings,vkbm/v1/service-menus,vkbm/v1/menu-loop,vkbm/v1/availabilities,vkbm/v1/drafts,vkbm/v1/bookings' ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '両方有効・除外が /vkbm/v1/（前後にスラッシュ） => SiteGuard と同じく取り除くので止められていない',
				'config'              => $this->blocking_config( 'oembed, /vkbm/v1/ ' ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '両方有効・除外に wp/v2 の 3 行だけ（#1153 より前の案内） => vkbm/v1 が止められている',
				'config'              => $this->blocking_config( 'wp/v2/vkbm_service_menu,wp/v2/vkbm_resource,wp/v2/vkbm_resource_tag' ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '「ユーザー名漏えい防御」が無効 => 止められていない',
				'config'              => array_merge( $this->blocking_config( '' ), array( 'block_author_query_enable' => '0' ) ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '「REST API 無効化」が無効 => 止められていない',
				'config'              => array_merge( $this->blocking_config( '' ), array( 'disable_restapi_enable' => '0' ) ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '除外リストのキーが無い（形が想定外） => 通知を出さない側（false）',
				'config'              => array(
					'block_author_query_enable' => '1',
					'disable_restapi_enable'    => '1',
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '除外リストが文字列でない（形が想定外） => false',
				'config'              => array_merge( $this->blocking_config( '' ), array( 'disable_restapi_exclude' => array( 'oembed' ) ) ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '有効の値が文字列 1 でない（形が想定外） => false',
				'config'              => array_merge( $this->blocking_config( '' ), array( 'block_author_query_enable' => 1 ) ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '設定値が無い（get_option が false） => false',
				'config'              => false,
				'expected'            => false,
			),
			array(
				'test_condition_name' => '設定値が配列でない => false',
				'config'              => 'broken',
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], SiteGuard_Rest_Api_Check::is_blocking_config( $case['config'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * is_blocking_reservation_requests(): SiteGuard が有効化されていて、保存された設定値が止める状態のときだけ true。
	 */
	public function test_is_blocking_reservation_requests(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'SiteGuard が有効・止める設定 => true',
				'active'              => true,
				'option'              => $this->blocking_config( 'oembed' ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'SiteGuard が有効・除外に vkbm/v1 がある => false',
				'active'              => true,
				'option'              => $this->blocking_config( 'oembed,vkbm/v1' ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'SiteGuard が無効（設定値だけ残っている） => false',
				'active'              => false,
				'option'              => $this->blocking_config( 'oembed' ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'SiteGuard が有効・設定値が保存されていない => false',
				'active'              => true,
				'option'              => null,
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			// SiteGuard の設定値を保存する（null は未保存）。
			if ( null === $case['option'] ) {
				delete_option( SiteGuard_Rest_Api_Check::OPTION_NAME );
			} else {
				update_option( SiteGuard_Rest_Api_Check::OPTION_NAME, $case['option'] );
			}

			$check = new SiteGuard_Rest_Api_Check( $case['active'] );
			$this->assertSame( $case['expected'], $check->is_blocking_reservation_requests(), $case['test_condition_name'] );
		}
	}
}
