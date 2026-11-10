<?php
/**
 * 日付選択画面のサービスメニューカードの画像比率を検証する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\REST;

use VKBookingManager\Blocks\Menu_Loop_Block;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\REST\Menu_Preview_Controller;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * プレビュー REST のカード比率テスト。
 */
class Menu_Preview_Image_Aspect_Ratio_Test extends WP_UnitTestCase {
	/**
	 * 設定値ごとのカード HTML を検証する。
	 */
	public function test_get_menu_preview_image_aspect_ratio(): void {
		$menu_id       = self::factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => '画像比率テスト',
			)
		);
		$attachment_id = self::factory()->attachment->create(
			array(
				'post_mime_type' => 'image/jpeg',
				'file'           => '2026/11/menu-preview.jpg',
			)
		);
		wp_update_attachment_metadata(
			$attachment_id,
			array(
				'file'   => '2026/11/menu-preview.jpg',
				'width'  => 800,
				'height' => 600,
			)
		);
		update_post_meta( $menu_id, '_thumbnail_id', $attachment_id );
		$request = new WP_REST_Request( 'GET', '/vkbm/v1/menu-preview/' . $menu_id );
		$request->set_param( 'id', $menu_id );
		$overrides  = array(
			'showDetailButton'  => false,
			'showReserveButton' => false,
		);
		$default    = ( new Menu_Loop_Block() )->render_menu_card( $menu_id, $overrides );
		$test_cases = array(
			array(
				'test_condition_name' => '未保存では従来のHTML',
				'settings'            => array(),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '正方形を適用',
				'settings'            => array( 'reservation_menu_list_image_aspect_ratio' => '1-1' ),
				'expected'            => '1-1',
			),
			array(
				'test_condition_name' => 'ワイドを適用',
				'settings'            => array( 'reservation_menu_list_image_aspect_ratio' => '16-9' ),
				'expected'            => '16-9',
			),
			array(
				'test_condition_name' => '許可外の値は従来のHTML',
				'settings'            => array( 'reservation_menu_list_image_aspect_ratio' => 'invalid' ),
				'expected'            => '',
			),
		);
		foreach ( $test_cases as $case ) {
			update_option( Settings_Repository::OPTION_KEY, $case['settings'] );
			$response    = ( new Menu_Preview_Controller( new Menu_Loop_Block() ) )->get_menu_preview( $request );
			$actual      = $response->get_data()['html'];
			$ratio_class = $case['expected'] ? ' vkbm-menu-loop__card-media--ratio-' . $case['expected'] : '';
			$this->assertSame( $default, str_replace( $ratio_class, '', $actual ), $case['test_condition_name'] );
			if ( $case['expected'] ) {
				$this->assertStringContainsString( $ratio_class . '"', $actual, $case['test_condition_name'] );
			}
		}
	}
}
