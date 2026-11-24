<?php
/**
 * 設定画面の「予約ページ」欄（固定ページの選択＋「その他（URL を入力）」。issue #1050）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Admin\Provider_Settings_Page;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Capabilities\Roles_Manager;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use WP_UnitTestCase;

/**
 * 「予約ページ」のプルダウンの中身・選択状態・URL 欄の表示・警告・並び・読み上げの結び付けを検証する。
 *
 * @group admin
 */
class Provider_Settings_Page_Reservation_Page_Field_Test extends WP_UnitTestCase {

	/**
	 * ログインしている管理者のユーザー ID。
	 *
	 * @var int
	 */
	private $admin_id = 0;

	/**
	 * BM 設定の権限を持つ管理者でログインする。
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $this->admin_id )->add_cap( Capabilities::MANAGE_PROVIDER_SETTINGS );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * 保存済みの設定とクエリ文字列を掃除し、パーマリンク構造を戻す。
	 */
	protected function tearDown(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		$_GET = array();
		wp_set_current_user( 0 );
		$this->set_permalink_structure( '' );
		parent::tearDown();
	}

	/**
	 * 固定ページを1件作る。
	 *
	 * @param array<string,mixed> $args 投稿の上書き項目。
	 * @return int 固定ページ ID。
	 */
	private function create_page( array $args = array() ): int {
		return self::factory()->post->create(
			array_merge(
				array(
					'post_type'   => 'page',
					'post_title'  => 'Booking Page',
					'post_status' => 'publish',
				),
				$args
			)
		);
	}

	/**
	 * 指定した設定でシステムタブを描画し、HTML を返す。
	 *
	 * @param array<string,mixed> $settings 保存する設定。
	 * @return string 描画した HTML。
	 */
	private function render_system_tab( array $settings ): string {
		update_option( Settings_Repository::OPTION_KEY, $settings );
		// 本体と同じく、BM 設定の権限で開ける画面として作る（業務オーナーでも描画できるように）。
		$page = new Provider_Settings_Page( new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() ), Capabilities::MANAGE_PROVIDER_SETTINGS );

		$_GET = array( 'tab' => 'system' );
		ob_start();
		$page->render_page();
		$html = (string) ob_get_clean();
		$_GET = array();

		return $html;
	}

	/**
	 * id で要素の開始タグを取り出す。
	 *
	 * @param string $html HTML。
	 * @param string $id   要素の id。
	 * @return string 開始タグ。無ければ空文字。
	 */
	private function opening_tag_by_id( string $html, string $id ): string {
		return 1 === preg_match( '/<[a-z]+[^>]*\sid="' . preg_quote( $id, '/' ) . '"[^>]*>/', $html, $m ) ? $m[0] : '';
	}

	/**
	 * id で要素（入れ子の無い div / p）の中身を取り出す。
	 *
	 * @param string $html HTML。
	 * @param string $id   要素の id。
	 * @return string 中身。無ければ空文字。
	 */
	private function inner_html_by_id( string $html, string $id ): string {
		return 1 === preg_match( '/<(div|p)[^>]*\sid="' . preg_quote( $id, '/' ) . '"[^>]*>(.*?)<\/\1>/s', $html, $m ) ? $m[2] : '';
	}

	/**
	 * 開始タグから属性の値を取り出す。
	 *
	 * @param string $tag  開始タグ。
	 * @param string $name 属性名。
	 * @return string|null 属性の値。属性が無ければ null。
	 */
	private function attribute( string $tag, string $name ): ?string {
		return 1 === preg_match( '/\s' . preg_quote( $name, '/' ) . '="([^"]*)"/', $tag, $m ) ? $m[1] : null;
	}

	/**
	 * プルダウンの中の option を取り出す。
	 *
	 * @param string $html  HTML。
	 * @param string $value option の value。
	 * @return array{tag:string,label:string}|null 開始タグとラベル。無ければ null。
	 */
	private function option_by_value( string $html, string $value ): ?array {
		if ( 1 !== preg_match( '/<select[^>]*id="vkbm-reservation-page-url"[^>]*>(.*?)<\/select>/s', $html, $select ) ) {
			return null;
		}
		if ( 1 !== preg_match( '/(<option[^>]*\svalue="' . preg_quote( $value, '/' ) . '"[^>]*>)([^<]*)<\/option>/', $select[1], $m ) ) {
			return null;
		}

		return array(
			'tag'   => $m[1],
			'label' => trim( $m[2] ),
		);
	}

	/**
	 * プルダウンの選択肢・選択状態・URL 欄・警告・並び・読み上げの結び付けが、保存されている設定に応じて出ることを検証する。
	 */
	public function test_render_page(): void {
		$this->set_permalink_structure( '/%postname%/' );
		// 業務オーナー（BM 設定はできるが、固定ページの権限を外されている）。
		( new Roles_Manager() )->sync_roles();
		$owner_id = self::factory()->user->create( array( 'role' => Roles_Manager::ROLE_PROVIDER_OWNER ) );

		// 公開中の固定ページが1件も無いときは、固定ページを追加できる人にだけ追加するリンクを出す（押しても権限エラーにならないように）。
		$no_page_cases = array(
			array(
				'test_condition_name' => '公開中の固定ページが無い・管理者 => 追加するリンクを出す',
				'user_id'             => $this->admin_id,
				'expected_link'       => true,
			),
			array(
				'test_condition_name' => '公開中の固定ページが無い・業務オーナー（固定ページの権限なし） => 追加するリンクは出さない',
				'user_id'             => $owner_id,
				'expected_link'       => false,
			),
		);
		foreach ( $no_page_cases as $case ) {
			wp_set_current_user( $case['user_id'] );
			$html = $this->render_system_tab( array() );
			$has_add_link = false !== strpos( $html, 'post-new.php?post_type=page' );
			$this->assertSame( $case['expected_link'], $has_add_link, $case['test_condition_name'] );
		}
		wp_set_current_user( $this->admin_id );

		$published_id = $this->create_page( array( 'post_name' => 'booking' ) );
		$draft_id     = $this->create_page(
			array(
				'post_title'  => 'Draft Booking',
				'post_status' => 'draft',
			)
		);
		$trash_id     = $this->create_page(
			array(
				'post_title'  => 'Trashed Booking',
				'post_status' => 'trash',
			)
		);
		$deleted_id   = $this->create_page( array( 'post_title' => 'Deleted Booking' ) );
		wp_delete_post( $deleted_id, true );
		// 親が非公開でも、公開中の子ページは選べて、選択中なら一覧に残る（#1050 安藤レビュー指摘1）。
		$private_parent_id = $this->create_page(
			array(
				'post_title'  => 'Private Parent',
				'post_status' => 'private',
			)
		);
		$child_id          = $this->create_page(
			array(
				'post_title'  => 'Child Of Private',
				'post_name'   => 'child-of-private',
				'post_parent' => $private_parent_id,
			)
		);
		// 日本語スラッグのページは、URL の行を読める形（%E3… ではなく日本語）で出す。
		$japanese_id = $this->create_page(
			array(
				'post_title' => '予約ページ',
				'post_name'  => '予約',
			)
		);
		$untitled_id = $this->create_page(
			array(
				'post_title'  => '',
				'post_status' => 'draft',
			)
		);

		$test_cases = array(
			array(
				'test_condition_name'          => '未設定 => 「選択してください」が選ばれ、URL 欄は隠す。プルダウンの説明は共通の説明文だけ',
				'settings'                     => array(),
				'expected_selected'            => '',
				'expected_option_label'        => null,
				'expected_url_hidden'          => true,
				'expected_url_value'           => '',
				'expected_page_link'           => '',
				'expected_info_text'           => null,
				'expected_warning'             => false,
				'expected_warning_link'        => null,
				'expected_select_describedby'  => 'vkbm-reservation-page-description',
				'expected_input_describedby'   => 'vkbm-reservation-page-url-description',
			),
			array(
				'test_condition_name'          => '公開中の固定ページを選択 => URL の行（新しいタブで開くリンク）を出し、URL 欄は隠す（残っている相対パスの URL は絶対 URL にして出す）',
				'settings'                     => array(
					'reservation_page_id'  => $published_id,
					'reservation_page_url' => '/booking/',
				),
				'expected_selected'            => (string) $published_id,
				'expected_option_label'        => null,
				'expected_url_hidden'          => true,
				'expected_url_value'           => home_url( '/booking/' ),
				'expected_page_link'           => get_permalink( $published_id ),
				'expected_info_text'           => '/booking/',
				'expected_warning'             => false,
				'expected_warning_link'        => null,
				'expected_select_describedby'  => 'vkbm-reservation-page-info vkbm-reservation-page-description',
				'expected_input_describedby'   => 'vkbm-reservation-page-url-description',
			),
			array(
				'test_condition_name'          => '親が非公開の公開中ページを選択 => 一覧に残って選ばれ、状態の印は付けない',
				'settings'                     => array( 'reservation_page_id' => $child_id ),
				'expected_selected'            => (string) $child_id,
				'expected_option_label'        => 'Child Of Private',
				'expected_option_label_exact'  => true,
				'expected_url_hidden'          => true,
				'expected_url_value'           => '',
				'expected_page_link'           => get_permalink( $child_id ),
				'expected_info_text'           => null,
				'expected_warning'             => false,
				'expected_warning_link'        => null,
				'expected_select_describedby'  => 'vkbm-reservation-page-info vkbm-reservation-page-description',
				'expected_input_describedby'   => 'vkbm-reservation-page-url-description',
			),
			array(
				'test_condition_name'          => '日本語スラッグのページを選択 => URL の行はデコードして読める形で出す',
				'settings'                     => array( 'reservation_page_id' => $japanese_id ),
				'expected_selected'            => (string) $japanese_id,
				'expected_option_label'        => null,
				'expected_url_hidden'          => true,
				'expected_url_value'           => '',
				'expected_page_link'           => get_permalink( $japanese_id ),
				'expected_info_text'           => '/予約/',
				'expected_warning'             => false,
				'expected_warning_link'        => null,
				'expected_select_describedby'  => 'vkbm-reservation-page-info vkbm-reservation-page-description',
				'expected_input_describedby'   => 'vkbm-reservation-page-url-description',
			),
			array(
				'test_condition_name'          => '下書きの固定ページを選択 => 状態付きで残して選ばれ、「固定ページを編集する」リンク付きの警告を出す',
				'settings'                     => array( 'reservation_page_id' => $draft_id ),
				'expected_selected'            => (string) $draft_id,
				'expected_option_label'        => 'Draft Booking',
				'expected_url_hidden'          => true,
				'expected_url_value'           => '',
				'expected_page_link'           => '',
				'expected_info_text'           => null,
				'expected_warning'             => true,
				'expected_warning_link'        => 'post.php?post=' . $draft_id,
				'expected_warning_text'        => 'Please publish the page.',
				'expected_select_describedby'  => 'vkbm-reservation-page-problem vkbm-reservation-page-description',
				'expected_input_describedby'   => 'vkbm-reservation-page-url-description',
			),
			array(
				'test_condition_name'          => 'ゴミ箱の固定ページを選択 => 状態付きで残して選ばれ、「ゴミ箱を開く」リンク付きの警告を出す',
				'settings'                     => array( 'reservation_page_id' => $trash_id ),
				'expected_selected'            => (string) $trash_id,
				'expected_option_label'        => 'Trashed Booking',
				'expected_url_hidden'          => true,
				'expected_url_value'           => '',
				'expected_page_link'           => '',
				'expected_info_text'           => null,
				'expected_warning'             => true,
				'expected_warning_link'        => 'post_status=trash',
				'expected_warning_text'        => 'Please restore the page from the trash and publish it.',
				'expected_select_describedby'  => 'vkbm-reservation-page-problem vkbm-reservation-page-description',
				'expected_input_describedby'   => 'vkbm-reservation-page-url-description',
			),
			array(
				'test_condition_name'          => '下書きの固定ページを選択・業務オーナー（固定ページの権限なし） => 公開ではなく選び直しを勧める、リンク無しの警告を出す（この欄で選び直す）',
				'user_id'                      => $owner_id,
				'settings'                     => array( 'reservation_page_id' => $draft_id ),
				'expected_selected'            => (string) $draft_id,
				'expected_option_label'        => 'Draft Booking',
				'expected_url_hidden'          => true,
				'expected_url_value'           => '',
				'expected_page_link'           => '',
				'expected_info_text'           => null,
				'expected_warning'             => true,
				'expected_warning_link'        => 'none',
				'expected_warning_text'        => 'Please select the reservation page again.',
				'unexpected_warning_text'      => 'publish the page',
				'expected_select_describedby'  => 'vkbm-reservation-page-problem vkbm-reservation-page-description',
				'expected_input_describedby'   => 'vkbm-reservation-page-url-description',
			),
			array(
				'test_condition_name'          => 'ゴミ箱の固定ページを選択・業務オーナー（固定ページの権限なし） => ゴミ箱から戻すのではなく選び直しを勧める、リンク無しの警告を出す',
				'user_id'                      => $owner_id,
				'settings'                     => array( 'reservation_page_id' => $trash_id ),
				'expected_selected'            => (string) $trash_id,
				'expected_option_label'        => 'Trashed Booking',
				'expected_url_hidden'          => true,
				'expected_url_value'           => '',
				'expected_page_link'           => '',
				'expected_info_text'           => null,
				'expected_warning'             => true,
				'expected_warning_link'        => 'none',
				'expected_warning_text'        => 'Please select the reservation page again.',
				'unexpected_warning_text'      => 'from the trash',
				'expected_select_describedby'  => 'vkbm-reservation-page-problem vkbm-reservation-page-description',
				'expected_input_describedby'   => 'vkbm-reservation-page-url-description',
			),
			array(
				'test_condition_name'          => 'タイトルの無い下書きを選択 => 「#ID (no title)」の形で残す',
				'settings'                     => array( 'reservation_page_id' => $untitled_id ),
				'expected_selected'            => (string) $untitled_id,
				'expected_option_label'        => '#' . $untitled_id,
				'expected_url_hidden'          => true,
				'expected_url_value'           => '',
				'expected_page_link'           => '',
				'expected_info_text'           => null,
				'expected_warning'             => true,
				'expected_warning_link'        => 'post.php?post=' . $untitled_id,
				'expected_select_describedby'  => 'vkbm-reservation-page-problem vkbm-reservation-page-description',
				'expected_input_describedby'   => 'vkbm-reservation-page-url-description',
			),
			array(
				'test_condition_name'          => '削除された固定ページを選択 => 「選択してください」に戻り、リンク無しの警告を出す（この欄で選び直す）',
				'settings'                     => array( 'reservation_page_id' => $deleted_id ),
				'expected_selected'            => '',
				'expected_option_label'        => null,
				'expected_url_hidden'          => true,
				'expected_url_value'           => '',
				'expected_page_link'           => '',
				'expected_info_text'           => null,
				'expected_warning'             => true,
				'expected_warning_link'        => 'none',
				'expected_select_describedby'  => 'vkbm-reservation-page-problem vkbm-reservation-page-description',
				'expected_input_describedby'   => 'vkbm-reservation-page-url-description',
			),
			array(
				'test_condition_name'          => 'URL だけ（既存ユーザー・固定ページ以外） => 「その他」が選ばれ、URL 欄を出す',
				'settings'                     => array( 'reservation_page_url' => home_url( '/booking/' ) ),
				'expected_selected'            => 'other',
				'expected_option_label'        => null,
				'expected_url_hidden'          => false,
				'expected_url_value'           => home_url( '/booking/' ),
				'expected_page_link'           => '',
				'expected_info_text'           => null,
				'expected_warning'             => false,
				'expected_warning_link'        => null,
				'expected_select_describedby'  => 'vkbm-reservation-page-description',
				'expected_input_describedby'   => 'vkbm-reservation-page-url-description',
			),
			array(
				'test_condition_name'          => '旧ドメインの URL => 「その他」が選ばれ、URL 欄の中に警告を出し、URL 欄の読み上げに警告を結び付ける',
				'settings'                     => array( 'reservation_page_url' => 'https://old.example.com/booking/' ),
				'expected_selected'            => 'other',
				'expected_option_label'        => null,
				'expected_url_hidden'          => false,
				'expected_url_value'           => 'https://old.example.com/booking/',
				'expected_page_link'           => '',
				'expected_info_text'           => null,
				'expected_warning'             => true,
				'expected_warning_link'        => 'none',
				'expected_select_describedby'  => 'vkbm-reservation-page-description',
				'expected_input_describedby'   => 'vkbm-reservation-page-url-warning vkbm-reservation-page-url-description',
			),
		);

		foreach ( $test_cases as $case ) {
			wp_set_current_user( $case['user_id'] ?? $this->admin_id );
			$html = $this->render_system_tab( $case['settings'] );

			// プルダウン本体（id は旧 URL 欄の着地点を引き継ぐ）。
			$select_tag = $this->opening_tag_by_id( $html, 'vkbm-reservation-page-url' );
			$this->assertStringStartsWith( '<select', $select_tag, $case['test_condition_name'] . '（プルダウン）' );
			$this->assertStringContainsString( 'name="vkbm_provider_settings[reservation_page_id]"', $select_tag, $case['test_condition_name'] . '（name）' );
			$this->assertSame( $case['expected_select_describedby'], $this->attribute( $select_tag, 'aria-describedby' ), $case['test_condition_name'] . '（プルダウンの aria-describedby）' );

			// 公開中のページと「その他」はいつでも選べる。
			$this->assertNotNull( $this->option_by_value( $html, (string) $published_id ), $case['test_condition_name'] . '（公開中のページの選択肢）' );
			$this->assertNotNull( $this->option_by_value( $html, (string) $child_id ), $case['test_condition_name'] . '（親が非公開でも公開中の子ページは選べる）' );
			$this->assertNotNull( $this->option_by_value( $html, 'other' ), $case['test_condition_name'] . '（その他の選択肢）' );
			$this->assertStringNotContainsString( 'post-new.php?post_type=page', $html, $case['test_condition_name'] . '（公開中のページがあれば追加リンクは出さない）' );

			// 選択状態。
			$selected = $this->option_by_value( $html, $case['expected_selected'] );
			$this->assertNotNull( $selected, $case['test_condition_name'] . '（選択肢の存在）' );
			$this->assertStringContainsString( 'selected', $selected['tag'], $case['test_condition_name'] . '（選択状態）' );
			if ( null !== $case['expected_option_label'] ) {
				$this->assertStringContainsString( $case['expected_option_label'], $selected['label'], $case['test_condition_name'] . '（選択中のページ名）' );
				if ( ! empty( $case['expected_option_label_exact'] ) ) {
					$this->assertSame( $case['expected_option_label'], $selected['label'], $case['test_condition_name'] . '（公開中なので状態の印は付けない）' );
				} else {
					$this->assertNotSame( $case['expected_option_label'], $selected['label'], $case['test_condition_name'] . '（状態のラベル付き）' );
				}
			}
			if ( $deleted_id === ( $case['settings']['reservation_page_id'] ?? 0 ) ) {
				$this->assertNull( $this->option_by_value( $html, (string) $deleted_id ), $case['test_condition_name'] . '（削除されたページは一覧に出さない）' );
			}

			// 並び: プルダウン →（URL の行／状態の警告）→ 共通の説明文 →「その他」の URL 欄。
			$description_pos = strpos( $html, 'id="vkbm-reservation-page-description"' );
			$url_field_pos   = strpos( $html, 'id="vkbm-reservation-page-url-field"' );
			$this->assertNotFalse( $description_pos, $case['test_condition_name'] . '（共通の説明文）' );
			$this->assertNotFalse( $url_field_pos, $case['test_condition_name'] . '（URL 欄）' );
			$this->assertLessThan( $url_field_pos, $description_pos, $case['test_condition_name'] . '（説明文は URL 欄より前）' );
			foreach ( array( 'vkbm-reservation-page-info', 'vkbm-reservation-page-problem' ) as $before_description_id ) {
				$pos = strpos( $html, 'id="' . $before_description_id . '"' );
				if ( false !== $pos ) {
					$this->assertLessThan( $description_pos, $pos, $case['test_condition_name'] . '（' . $before_description_id . ' は説明文より前）' );
				}
			}

			// 「その他」の URL 欄。
			$field_tag = $this->opening_tag_by_id( $html, 'vkbm-reservation-page-url-field' );
			$this->assertSame( $case['expected_url_hidden'], false !== strpos( $field_tag, 'hidden' ), $case['test_condition_name'] . '（URL 欄の hidden）' );
			$input_tag = $this->opening_tag_by_id( $html, 'vkbm-reservation-page-url-input' );
			$this->assertStringContainsString( 'name="vkbm_provider_settings[reservation_page_url]"', $input_tag, $case['test_condition_name'] . '（URL の name）' );
			$this->assertSame( $case['expected_url_value'], html_entity_decode( (string) $this->attribute( $input_tag, 'value' ) ), $case['test_condition_name'] . '（URL の値）' );
			$this->assertSame( $case['expected_input_describedby'], $this->attribute( $input_tag, 'aria-describedby' ), $case['test_condition_name'] . '（URL 欄の aria-describedby）' );
			$this->assertNotSame( '', $this->opening_tag_by_id( $html, 'vkbm-reservation-page-url-description' ), $case['test_condition_name'] . '（URL 欄の説明文）' );

			// 選択中のページの URL の行。
			$info_tag = $this->opening_tag_by_id( $html, 'vkbm-reservation-page-info' );
			if ( '' !== $case['expected_page_link'] ) {
				$this->assertNotSame( '', $info_tag, $case['test_condition_name'] . '（URL の行）' );
				$this->assertSame( 1, preg_match( '/<a[^>]*href="' . preg_quote( esc_url( $case['expected_page_link'] ), '/' ) . '"[^>]*target="_blank"/', $html ), $case['test_condition_name'] . '（新しいタブで開くリンク）' );
				if ( null !== $case['expected_info_text'] ) {
					$this->assertStringContainsString( $case['expected_info_text'], $this->inner_html_by_id( $html, 'vkbm-reservation-page-info' ), $case['test_condition_name'] . '（URL の表示文字）' );
				}
			} else {
				$this->assertSame( '', $info_tag, $case['test_condition_name'] . '（URL の行は出さない）' );
			}

			// 警告は予約ページについて1件だけ。リンクは状態ごとに1つ。
			$this->assertSame( $case['expected_warning'] ? 1 : 0, substr_count( $html, 'vkbm-reservation-page-warning' ), $case['test_condition_name'] . '（警告）' );
			if ( null !== $case['expected_warning_link'] ) {
				$warning_html = $this->inner_html_by_id( $html, 'vkbm-reservation-page-problem' ) . $this->inner_html_by_id( $html, 'vkbm-reservation-page-url-warning' );
				$this->assertNotSame( '', $warning_html, $case['test_condition_name'] . '（警告の本文）' );
				if ( 'none' === $case['expected_warning_link'] ) {
					$this->assertStringNotContainsString( '<a ', $warning_html, $case['test_condition_name'] . '（この欄で選び直すのでリンクは無い）' );
				} else {
					$this->assertStringContainsString( $case['expected_warning_link'], $warning_html, $case['test_condition_name'] . '（行き先のリンク）' );
				}
				// 次にすることの文面は、行き先（リンクの有無）と一致させる。
				if ( isset( $case['expected_warning_text'] ) ) {
					$this->assertStringContainsString( $case['expected_warning_text'], $warning_html, $case['test_condition_name'] . '（次にすること）' );
				}
				if ( isset( $case['unexpected_warning_text'] ) ) {
					$this->assertStringNotContainsString( $case['unexpected_warning_text'], $warning_html, $case['test_condition_name'] . '（できない行動を勧めない）' );
				}
			}
		}
	}
}
