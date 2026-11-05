<?php
/**
 * 予約ブロック描画時のフロント設定読み込みテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Blocks;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Auth\Auth_Shortcodes;
use VKBookingManager\Blocks\Reservation_Block;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use WP_UnitTestCase;

/**
 * 投稿本文に予約ブロックが無い場合の設定出力を検証する。
 *
 * @group blocks
 * @group lodging
 */
class Reservation_Block_Render_Config_Test extends WP_UnitTestCase {
	/**
	 * 元の設定。
	 *
	 * @var mixed
	 */
	private $original_settings;

	/**
	 * 元のグローバル投稿。
	 *
	 * @var mixed
	 */
	private $original_post;

	/**
	 * 元のスクリプトレジストリ。
	 *
	 * @var mixed
	 */
	private $original_wp_scripts;

	/**
	 * 元のスタイルレジストリ。
	 *
	 * @var \WP_Styles
	 */
	private $original_wp_styles;

	/**
	 * テスト前のグローバル状態を保存する。
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->original_settings   = get_option( Settings_Repository::OPTION_KEY, false );
		$this->original_post       = $GLOBALS['post'] ?? null;
		$this->original_wp_scripts = $GLOBALS['wp_scripts'] ?? null;
		$this->original_wp_styles  = clone wp_styles();
	}

	/**
	 * 設定とグローバル状態を復元する。
	 */
	protected function tearDown(): void {
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		Industry_Presets::clear_cache();
		$GLOBALS['post']       = $this->original_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- テスト前の投稿へ戻すため。
		$GLOBALS['wp_scripts'] = $this->original_wp_scripts; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- テスト前のスクリプトレジストリへ戻すため。

		$GLOBALS['wp_styles'] = $this->original_wp_styles; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- テスト前のスタイルレジストリへ戻すため。

		parent::tearDown();
	}

	/**
	 * 予約ブロックの描画時に業種別の isLodging 設定が出力されることを検証する。
	 */
	public function test_enqueue_reservation_config_on_render(): void {
		$cases = array(
			array(
				'test_condition_name' => '宿泊 => isLodging が true',
				'preset'              => 'lodging',
				'expected'            => ! Pro_Upsell::is_free_edition(),
			),
			array(
				'test_condition_name' => 'カスタム => isLodging が false',
				'preset'              => 'custom',
				'expected'            => false,
			),
		);

		foreach ( $cases as $case ) {
			// 投稿本文には予約ブロックを置かず、描画時の経路だけを使う。
			$GLOBALS['post'] = self::factory()->post->create_and_get( array( 'post_content' => '<!-- wp:paragraph --><p>sample</p><!-- /wp:paragraph -->' ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- has_block() 経路を使わない投稿へ差し替えるため。
			$defaults        = ( new Settings_Repository() )->get_default_settings();
			update_option( Settings_Repository::OPTION_KEY, array_merge( $defaults, array( 'industry_preset' => $case['preset'] ) ) );
			Industry_Presets::clear_cache();
			$GLOBALS['wp_scripts'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- ケースごとにインラインスクリプトを初期化するため。

			$settings_service = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
			$block            = new Reservation_Block( new Auth_Shortcodes( $settings_service ) );
			$content          = '<div class="wp-block-vk-booking-manager-reservation"></div>';
			$result           = $block->enqueue_reservation_config_on_render( $content, array( 'blockName' => 'vk-booking-manager/reservation' ) );
			$first_scripts    = wp_scripts()->get_data( 'vkbm-reservation-config', 'before' );
			$result           = $block->enqueue_reservation_config_on_render( $result, array( 'blockName' => 'vk-booking-manager/reservation' ) );

			$this->assertSame( $content, $result, $case['test_condition_name'] . '（HTML は変更しない）' );
			$this->assertFalse( has_block( 'vk-booking-manager/reservation', $GLOBALS['post'] ), $case['test_condition_name'] . '（投稿本文に予約ブロックが無い）' );

			$inline_scripts = wp_scripts()->get_data( 'vkbm-reservation-config', 'before' );
			$this->assertIsArray( $inline_scripts, $case['test_condition_name'] );
			$this->assertSame( $first_scripts, $inline_scripts, $case['test_condition_name'] . '（2回目は設定を追加しない）' );
			$this->assertSame( 1, preg_match( '/window\.vkbmReservationConfig\s*=\s*(\{.*\});/s', implode( '', $inline_scripts ), $matches ), $case['test_condition_name'] );
			$config = json_decode( $matches[1], true );
			$this->assertIsArray( $config, $case['test_condition_name'] );
			$this->assertSame( $case['expected'], $config['isLodging'], $case['test_condition_name'] );
		}
	}

	/**
	 * ソース・ビルド・登録結果が共有カード CSS を参照し、共通部品に依存することを検証する。
	 */
	public function test_register_block(): void {
		$block_type = \WP_Block_Type_Registry::get_instance()->get_registered( 'vk-booking-manager/reservation' );
		$cases      = array(
			array(
				'test_condition_name' => 'ソースのメタデータに共有カード CSS がある',
				'actual'              => wp_json_file_decode( VKBM_PLUGIN_DIR_PATH . 'src/blocks/reservation/block.json', array( 'associative' => true ) )['style'],
			),
			array(
				'test_condition_name' => 'ビルドのメタデータに共有カード CSS がある',
				'actual'              => wp_json_file_decode( VKBM_PLUGIN_DIR_PATH . 'build/blocks/reservation/block.json', array( 'associative' => true ) )['style'],
			),
			array(
				'test_condition_name' => '登録済みブロックがエディター・フロント共通の CSS として参照する',
				'actual'              => $block_type->style_handles,
			),
		);
		foreach ( $cases as $case ) {
			$this->assertContains( 'vkbm-shared-menu-card', $case['actual'], $case['test_condition_name'] );
		}
		$this->assertTrue( wp_style_is( 'vkbm-shared-menu-card', 'registered' ) );
		$this->assertSame( array( 'vkbm-components' ), wp_styles()->registered['vkbm-shared-menu-card']->deps );
	}

	/**
	 * 本文外からの描画でカード CSS とギャラリーを読み込み、重複や他ブロックへの漏れが無いことを検証する。
	 */
	public function test_do_blocks(): void {
		$cases = array(
			array(
				'test_condition_name' => '本文外の予約ブロックを描画すると必要なアセットを読み込む',
				'content'             => '<!-- wp:vk-booking-manager/reservation --><div class="wp-block-vk-booking-manager-reservation vkbm-reservation-block"></div><!-- /wp:vk-booking-manager/reservation -->',
				'config_enqueued'     => false,
				'expected'            => 1,
			),
			array(
				'test_condition_name' => '設定が読み込み済みでも複数の予約ブロックでアセットを1回だけ読み込む',
				'content'             => str_repeat( '<!-- wp:vk-booking-manager/reservation --><div class="wp-block-vk-booking-manager-reservation vkbm-reservation-block"></div><!-- /wp:vk-booking-manager/reservation -->', 2 ),
				'config_enqueued'     => true,
				'expected'            => 1,
			),
			array(
				'test_condition_name' => '予約ブロック以外ではカード CSS もギャラリーも読み込まない',
				'content'             => '<!-- wp:paragraph --><p>sample</p><!-- /wp:paragraph -->',
				'config_enqueued'     => false,
				'expected'            => 0,
			),
		);
		foreach ( $cases as $case ) {
			// 本文には予約ブロックを置かず、テンプレート等に相当する描画だけを行う。
			$GLOBALS['post']       = self::factory()->post->create_and_get( array( 'post_content' => '' ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- 本文外の描画を検証するため。
			$GLOBALS['wp_scripts'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- ケースごとにスクリプトを初期化するため。
			wp_dequeue_style( 'vkbm-shared-menu-card' );
			if ( $case['config_enqueued'] ) {
				wp_register_script( 'vkbm-reservation-config', false, array(), '1.0', true );
				wp_enqueue_script( 'vkbm-reservation-config' );
			}

			do_blocks( $case['content'] );

			$this->assertFalse( has_block( 'vk-booking-manager/reservation', $GLOBALS['post'] ), $case['test_condition_name'] );
			$this->assertSame( $case['expected'], count( array_keys( wp_styles()->queue, 'vkbm-shared-menu-card', true ) ), $case['test_condition_name'] );
			$this->assertSame( $case['expected'], count( array_keys( wp_scripts()->queue, 'vkbm-menu-gallery-lightbox', true ) ), $case['test_condition_name'] );
			if ( $case['expected'] ) {
				$this->assertStringContainsString( 'vkbmMenuGallery', wp_scripts()->get_data( 'vkbm-menu-gallery-lightbox', 'data' ), $case['test_condition_name'] );
			}
		}
	}
}
