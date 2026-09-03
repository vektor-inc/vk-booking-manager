<?php
/**
 * 予約ボタン共通描画ヘルパーのユニットテスト。
 *
 * メニューループブロックと予約に進むボタンブロックで共有する
 * Reservation_Button_Renderer の挙動を検証する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Frontend;

use VKBookingManager\Blocks\Reservation_Button_Renderer;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_UnitTestCase;

/**
 * Reservation_Button_Renderer の挙動を保証するテスト。
 */
class Reservation_Button_Renderer_Test extends WP_UnitTestCase {

	/**
	 * 各テスト後に基本設定オプションを掃除する。
	 */
	public function tear_down(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		parent::tear_down();
	}

	/**
	 * 指定した値で基本設定オプションを保存する。
	 *
	 * 既定値とマージされるため、必要なキーだけ渡せばよい。
	 *
	 * @param array<string,mixed> $settings 保存する設定値。
	 * @return void
	 */
	private function set_settings( array $settings ): void {
		update_option( Settings_Repository::OPTION_KEY, $settings );
	}

	/**
	 * 実リポジトリを使ったレンダラーを生成する。
	 *
	 * @return Reservation_Button_Renderer
	 */
	private function make_renderer(): Reservation_Button_Renderer {
		return new Reservation_Button_Renderer( new Settings_Repository() );
	}

	/**
	 * サービスメニュー投稿を1件作成する。
	 *
	 * @param array<string,mixed> $meta 付与するメタ。
	 * @return \WP_Post
	 */
	private function create_menu_post( array $meta = array() ): \WP_Post {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_title'  => 'Sample Plan',
				'post_status' => 'publish',
			)
		);

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		return get_post( $post_id );
	}

	/**
	 * 予約ページが設定済みなら menu_id 付きのリンクHTMLを生成する。
	 */
	public function test_render_button_builds_link_with_menu_id(): void {
		$this->set_settings( array( 'reservation_page_url' => 'https://example.com/reserve/' ) );
		$renderer = $this->make_renderer();
		$post     = $this->create_menu_post();

		$html = $renderer->render_button( $post );

		// アンカー要素で menu_id クエリ付きのリンクが出力されること。
		$this->assertStringContainsString( '<a ', $html );
		$this->assertStringContainsString( 'menu_id=' . $post->ID, $html );
		$this->assertStringContainsString( 'vkbm-button', $html );
	}

	/**
	 * オンライン予約不可のプランは無効状態の span を出力する。
	 */
	public function test_render_button_renders_disabled_span_for_unavailable_menu(): void {
		$this->set_settings( array( 'reservation_page_url' => 'https://example.com/reserve/' ) );
		$renderer = $this->make_renderer();
		$post     = $this->create_menu_post(
			array(
				Reservation_Button_Renderer::META_ONLINE_UNAVAILABLE => '1',
			)
		);

		$html = $renderer->render_button( $post );

		// 非活性ボタンは span 要素かつ aria-disabled を持つこと。
		$this->assertStringContainsString( '<span', $html );
		$this->assertStringContainsString( 'aria-disabled="true"', $html );
		$this->assertStringContainsString( 'is-disabled', $html );
		// 死にリンクにならないよう href は出力しないこと。
		$this->assertStringNotContainsString( '<a ', $html );
		// role="link" は aria-disabled と意味が衝突するため出力しないこと。
		$this->assertStringNotContainsString( 'role="link"', $html );
		// 無効理由が title だけでなく読み上げ用テキストとしても要素内に含まれること。
		// 英語文言を直書きせず、本番コードと同じ翻訳ルックアップから期待値を組み立てる
		// （翻訳が読み込まれるロケールでも落ちないようにする）。
		$this->assertStringContainsString( 'screen-reader-text', $html );
		$expected_reason = esc_html( __( 'This menu does not accept online reservations.', 'vk-booking-manager' ) );
		$this->assertStringContainsString( $expected_reason, $html );
	}

	/**
	 * 無効ボタンでもプラン名（accessible_suffix）が読み上げ用テキストに含まれる。
	 */
	public function test_render_disabled_button_includes_plan_name_for_screen_readers(): void {
		$this->set_settings( array( 'reservation_page_url' => 'https://example.com/reserve/' ) );
		$renderer = $this->make_renderer();
		$post     = $this->create_menu_post(
			array(
				Reservation_Button_Renderer::META_ONLINE_UNAVAILABLE => '1',
			)
		);

		$html = $renderer->render_button(
			$post,
			array(
				'accessible_suffix' => 'Sample Plan',
			)
		);

		$this->assertStringContainsString( 'screen-reader-text', $html );
		$this->assertStringContainsString( 'Sample Plan', $html );
	}

	/**
	 * 予約ページ未設定かつ代替URLも無い場合は空文字を返す。
	 */
	public function test_render_button_returns_empty_when_no_url(): void {
		$this->set_settings( array( 'reservation_page_url' => '' ) );
		$renderer = $this->make_renderer();
		$post     = $this->create_menu_post();

		$html = $renderer->render_button( $post );

		$this->assertSame( '', $html );
	}

	/**
	 * オンライン予約不可でも、予約ページ未設定かつ代替URLが無い場合は
	 * 無効ボタンを出さず空文字を返す（リンク解決を disabled 分岐より先に行う）。
	 */
	public function test_render_button_returns_empty_for_unavailable_menu_without_url(): void {
		$this->set_settings( array( 'reservation_page_url' => '' ) );
		$renderer = $this->make_renderer();
		$post     = $this->create_menu_post(
			array(
				Reservation_Button_Renderer::META_ONLINE_UNAVAILABLE => '1',
			)
		);

		// fallback_url を渡さなければ、無効ボタンも出さずに空文字を返すこと。
		$html = $renderer->render_button( $post );

		$this->assertSame( '', $html );
	}

	/**
	 * オンライン予約不可かつ fallback_url がある場合は、無効ボタンを出力する。
	 */
	public function test_render_button_renders_disabled_when_unavailable_with_fallback(): void {
		$this->set_settings( array( 'reservation_page_url' => '' ) );
		$renderer = $this->make_renderer();
		$post     = $this->create_menu_post(
			array(
				Reservation_Button_Renderer::META_ONLINE_UNAVAILABLE => '1',
			)
		);

		$html = $renderer->render_button(
			$post,
			array(
				'fallback_url' => 'https://example.com/fallback/',
			)
		);

		// リンク先が解決できる場合はオンライン予約不可の無効ボタンを表示すること。
		$this->assertStringContainsString( 'aria-disabled="true"', $html );
		$this->assertStringContainsString( 'is-disabled', $html );
		$this->assertStringNotContainsString( '<a ', $html );
	}

	/**
	 * 予約ページ未設定でも fallback_url が指定されていればそれを使う。
	 */
	public function test_render_button_uses_fallback_url_when_page_unset(): void {
		$this->set_settings( array( 'reservation_page_url' => '' ) );
		$renderer = $this->make_renderer();
		$post     = $this->create_menu_post();

		$html = $renderer->render_button(
			$post,
			array(
				'fallback_url' => 'https://example.com/fallback/',
			)
		);

		$this->assertStringContainsString( '<a ', $html );
		$this->assertStringContainsString( 'example.com/fallback', $html );
		// 予約ページ未設定なので menu_id は付かないこと。
		$this->assertStringNotContainsString( 'menu_id=', $html );
	}

	/**
	 * accessible_suffix を渡すと aria-label にプラン名等を含める。
	 */
	public function test_render_button_includes_accessible_name(): void {
		$this->set_settings( array( 'reservation_page_url' => 'https://example.com/reserve/' ) );
		$renderer = $this->make_renderer();
		$post     = $this->create_menu_post();

		$html = $renderer->render_button(
			$post,
			array(
				'accessible_suffix' => 'Sample Plan',
			)
		);

		$this->assertStringContainsString( 'aria-label="', $html );
		$this->assertStringContainsString( 'Sample Plan', $html );
	}

	/**
	 * ラベル未指定時は基本設定の予約ボタンラベルを既定として使う。
	 */
	public function test_get_default_reserve_label_uses_settings(): void {
		$this->set_settings(
			array(
				Reservation_Button_Renderer::SETTING_RESERVE_LABEL => 'いますぐ予約',
			)
		);
		$renderer = $this->make_renderer();

		$this->assertSame( 'いますぐ予約', $renderer->get_default_reserve_label() );
	}
}
