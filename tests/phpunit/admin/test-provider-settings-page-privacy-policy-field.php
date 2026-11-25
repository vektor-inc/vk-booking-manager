<?php
/**
 * 設定画面の「プライバシーポリシー」欄（ページ指定の固定ページの選択＋「その他（URL を入力）」。issue #1168）のテスト。
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
 * 「プライバシーポリシーのページを指定」のプルダウンの中身・選択状態・URL 欄の表示・警告・並び・読み上げの結び付けを検証する。
 *
 * @group admin
 */
class Provider_Settings_Page_Privacy_Policy_Field_Test extends WP_UnitTestCase {

	/**
	 * ログインしている管理者のユーザー ID。
	 *
	 * @var int
	 */
	private $admin_id = 0;

	/**
	 * BM 設定の権限を持つ管理者でログインし、文言を英語（原文）で検証できるようにする。
	 */
	protected function setUp(): void {
		parent::setUp();
		switch_to_locale( 'en_US' );
		unload_textdomain( 'vk-booking-manager', true );
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $this->admin_id )->add_cap( Capabilities::MANAGE_PROVIDER_SETTINGS );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * 保存済みの設定とクエリ文字列・言語・パーマリンク構造を元に戻す。
	 */
	protected function tearDown(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		$_GET = array();
		wp_set_current_user( 0 );
		restore_previous_locale();
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
					'post_title'  => 'Privacy Page',
					'post_status' => 'publish',
				),
				$args
			)
		);
	}

	/**
	 * 指定した設定で同意項目タブを描画し、HTML を返す。
	 *
	 * @param array<string,mixed> $settings 保存する設定。
	 * @return string 描画した HTML。
	 */
	private function render_consent_tab( array $settings ): string {
		update_option( Settings_Repository::OPTION_KEY, $settings );
		$page = new Provider_Settings_Page( new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() ), Capabilities::MANAGE_PROVIDER_SETTINGS );

		$_GET = array( 'tab' => 'consent' );
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
	 * プライバシーポリシーのページのプルダウンの中の option を取り出す。
	 *
	 * @param string $html  HTML。
	 * @param string $value option の value。
	 * @return array{tag:string,label:string}|null 開始タグとラベル。無ければ null。
	 */
	private function option_by_value( string $html, string $value ): ?array {
		if ( 1 !== preg_match( '/<select[^>]*id="vkbm-provider-privacy-policy-page"[^>]*>(.*?)<\/select>/s', $html, $select ) ) {
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
	 * 表示方法・選択状態・URL 欄・警告・並び・読み上げの結び付けが、保存されている設定（既存の URL だけの設定を含む）に応じて出ることを検証する。
	 */
	public function test_render_page(): void {
		$this->set_permalink_structure( '/%postname%/' );
		// 業務オーナー（BM 設定はできるが、固定ページの権限を外されている）。
		( new Roles_Manager() )->sync_roles();
		$owner_id = self::factory()->user->create( array( 'role' => Roles_Manager::ROLE_PROVIDER_OWNER ) );

		// 公開中の固定ページが1件も無いときは、固定ページを追加できる人にだけ追加するリンクを出す。
		$html = $this->render_consent_tab( array( 'provider_privacy_policy_mode' => 'url' ) );
		$this->assertStringContainsString( 'post-new.php?post_type=page', $this->inner_html_by_id( $html, 'vkbm-provider-privacy-policy-page-description' ), '公開中の固定ページが無い・管理者 => 追加するリンクを出す' );
		wp_set_current_user( $owner_id );
		$html = $this->render_consent_tab( array( 'provider_privacy_policy_mode' => 'url' ) );
		$this->assertStringNotContainsString( 'post-new.php?post_type=page', $this->inner_html_by_id( $html, 'vkbm-provider-privacy-policy-page-description' ), '公開中の固定ページが無い・業務オーナー => 追加するリンクは出さない' );
		wp_set_current_user( $this->admin_id );

		$published_id = $this->create_page( array( 'post_name' => 'privacy' ) );
		$draft_id     = $this->create_page(
			array(
				'post_title'  => 'Draft Privacy',
				'post_status' => 'draft',
			)
		);
		$trash_id     = $this->create_page(
			array(
				'post_title'  => 'Trashed Privacy',
				'post_status' => 'trash',
			)
		);
		$deleted_id   = $this->create_page( array( 'post_title' => 'Deleted Privacy' ) );
		wp_delete_post( $deleted_id, true );

		$test_cases = array(
			array(
				'test_condition_name'         => '未設定 => ページ指定の欄ごと隠し、「選択してください」が選ばれ、URL 欄も隠す',
				'settings'                    => array(),
				'expected_mode_hidden'        => true,
				'expected_selected'           => '',
				'expected_option_label'       => null,
				'expected_url_hidden'         => true,
				'expected_url_value'          => '',
				'expected_page_link'          => '',
				'expected_warning'            => false,
				'expected_warning_link'       => null,
				'expected_select_describedby' => 'vkbm-provider-privacy-policy-page-description',
			),
			array(
				'test_condition_name'         => 'ページ指定・公開中の固定ページを選択 => URL の行（新しいタブで開くリンク）を出し、URL 欄は隠す（残っている相対パスの URL は絶対 URL にして出す）',
				'settings'                    => array(
					'provider_privacy_policy_mode'    => 'url',
					'provider_privacy_policy_page_id' => $published_id,
					'provider_privacy_policy_url'     => '/privacy/',
				),
				'expected_mode_hidden'        => false,
				'expected_selected'           => (string) $published_id,
				'expected_option_label'       => null,
				'expected_url_hidden'         => true,
				'expected_url_value'          => home_url( '/privacy/' ),
				'expected_page_link'          => get_permalink( $published_id ),
				'expected_warning'            => false,
				'expected_warning_link'       => null,
				'expected_select_describedby' => 'vkbm-provider-privacy-policy-page-info vkbm-provider-privacy-policy-page-description',
			),
			array(
				'test_condition_name'         => '既存の設定: ページ指定・外部サイトの URL だけ => 「その他」が選ばれ、URL 欄に URL を出す（警告は出さない）',
				'settings'                    => array(
					'provider_privacy_policy_mode' => 'url',
					'provider_privacy_policy_url'  => 'https://external.example.com/privacy/',
				),
				'expected_mode_hidden'        => false,
				'expected_selected'           => 'other',
				'expected_option_label'       => null,
				'expected_url_hidden'         => false,
				'expected_url_value'          => 'https://external.example.com/privacy/',
				'expected_page_link'          => '',
				'expected_warning'            => false,
				'expected_warning_link'       => null,
				'expected_select_describedby' => 'vkbm-provider-privacy-policy-page-description',
			),
			array(
				'test_condition_name'         => '既存の設定: ページ指定・旧ドメインの URL だけ => 「その他」のまま（外部サイトと区別できないため警告は出さない）',
				'settings'                    => array(
					'provider_privacy_policy_mode' => 'url',
					'provider_privacy_policy_url'  => 'https://old.example.com/privacy/',
				),
				'expected_mode_hidden'        => false,
				'expected_selected'           => 'other',
				'expected_option_label'       => null,
				'expected_url_hidden'         => false,
				'expected_url_value'          => 'https://old.example.com/privacy/',
				'expected_page_link'          => '',
				'expected_warning'            => false,
				'expected_warning_link'       => null,
				'expected_select_describedby' => 'vkbm-provider-privacy-policy-page-description',
			),
			array(
				'test_condition_name'         => '既存の設定: 本文を入力する方式で URL が残っている => ページ指定の欄は隠すが、「その他」と URL は保つ（送信しても消えない）',
				'settings'                    => array(
					'provider_privacy_policy_mode' => 'content',
					'provider_privacy_policy_url'  => 'https://external.example.com/privacy/',
				),
				'expected_mode_hidden'        => true,
				'expected_selected'           => 'other',
				'expected_option_label'       => null,
				'expected_url_hidden'         => false,
				'expected_url_value'          => 'https://external.example.com/privacy/',
				'expected_page_link'          => '',
				'expected_warning'            => false,
				'expected_warning_link'       => null,
				'expected_select_describedby' => 'vkbm-provider-privacy-policy-page-description',
			),
			array(
				'test_condition_name'         => '下書きの固定ページを選択 => 状態付きで残して選ばれ、「固定ページを編集する」リンク付きの警告を出す',
				'settings'                    => array(
					'provider_privacy_policy_mode'    => 'url',
					'provider_privacy_policy_page_id' => $draft_id,
				),
				'expected_mode_hidden'        => false,
				'expected_selected'           => (string) $draft_id,
				'expected_option_label'       => 'Draft Privacy',
				'expected_url_hidden'         => true,
				'expected_url_value'          => '',
				'expected_page_link'          => '',
				'expected_warning'            => true,
				'expected_warning_link'       => 'post.php?post=' . $draft_id,
				'expected_warning_text'       => 'Please publish the page.',
				'expected_select_describedby' => 'vkbm-provider-privacy-policy-page-problem vkbm-provider-privacy-policy-page-description',
			),
			array(
				'test_condition_name'         => 'ゴミ箱の固定ページを選択・業務オーナー（固定ページの権限なし） => 選び直しを勧める、リンク無しの警告を出す（この欄で選び直す）',
				'user_id'                     => $owner_id,
				'settings'                    => array(
					'provider_privacy_policy_mode'    => 'url',
					'provider_privacy_policy_page_id' => $trash_id,
				),
				'expected_mode_hidden'        => false,
				'expected_selected'           => (string) $trash_id,
				'expected_option_label'       => 'Trashed Privacy',
				'expected_url_hidden'         => true,
				'expected_url_value'          => '',
				'expected_page_link'          => '',
				'expected_warning'            => true,
				'expected_warning_link'       => 'none',
				'expected_warning_text'       => 'Please select the privacy policy page again.',
				'expected_select_describedby' => 'vkbm-provider-privacy-policy-page-problem vkbm-provider-privacy-policy-page-description',
			),
			array(
				'test_condition_name'         => '削除された固定ページを選択 => 「選択してください」に戻り、リンク無しの警告を出す',
				'settings'                    => array(
					'provider_privacy_policy_mode'    => 'url',
					'provider_privacy_policy_page_id' => $deleted_id,
				),
				'expected_mode_hidden'        => false,
				'expected_selected'           => '',
				'expected_option_label'       => null,
				'expected_url_hidden'         => true,
				'expected_url_value'          => '',
				'expected_page_link'          => '',
				'expected_warning'            => true,
				'expected_warning_link'       => 'none',
				'expected_warning_text'       => 'The page selected as the privacy policy page has been deleted.',
				'expected_select_describedby' => 'vkbm-provider-privacy-policy-page-problem vkbm-provider-privacy-policy-page-description',
			),
		);

		foreach ( $test_cases as $case ) {
			wp_set_current_user( $case['user_id'] ?? $this->admin_id );
			$html = $this->render_consent_tab( $case['settings'] );

			// 表示方法の選択肢の文言と、ページ指定の欄の出し入れ。
			$this->assertStringContainsString( 'Specify the privacy policy page', $html, $case['test_condition_name'] . '（表示方法の選択肢）' );
			$mode_field_tag = $this->opening_tag_by_id( $html, 'vkbm-provider-privacy-policy-url-field' );
			$this->assertSame( $case['expected_mode_hidden'], false !== strpos( $mode_field_tag, 'hidden' ), $case['test_condition_name'] . '（ページ指定の欄の hidden）' );

			// プルダウン本体。
			$select_tag = $this->opening_tag_by_id( $html, 'vkbm-provider-privacy-policy-page' );
			$this->assertStringStartsWith( '<select', $select_tag, $case['test_condition_name'] . '（プルダウン）' );
			$this->assertStringContainsString( 'name="vkbm_provider_settings[provider_privacy_policy_page_id]"', $select_tag, $case['test_condition_name'] . '（name）' );
			$this->assertSame( $case['expected_select_describedby'], $this->attribute( $select_tag, 'aria-describedby' ), $case['test_condition_name'] . '（プルダウンの aria-describedby）' );
			$this->assertStringContainsString( 'for="vkbm-provider-privacy-policy-page"', $html, $case['test_condition_name'] . '（プルダウンのラベル）' );

			// 公開中のページと「その他」はいつでも選べる。
			$this->assertNotNull( $this->option_by_value( $html, (string) $published_id ), $case['test_condition_name'] . '（公開中のページの選択肢）' );
			$this->assertNotNull( $this->option_by_value( $html, 'other' ), $case['test_condition_name'] . '（その他の選択肢）' );

			// 選択状態。
			$selected = $this->option_by_value( $html, $case['expected_selected'] );
			$this->assertNotNull( $selected, $case['test_condition_name'] . '（選択肢の存在）' );
			$this->assertStringContainsString( 'selected', $selected['tag'], $case['test_condition_name'] . '（選択状態）' );
			if ( null !== $case['expected_option_label'] ) {
				$this->assertStringContainsString( $case['expected_option_label'], $selected['label'], $case['test_condition_name'] . '（選択中のページ名）' );
				$this->assertNotSame( $case['expected_option_label'], $selected['label'], $case['test_condition_name'] . '（状態のラベル付き）' );
			}

			// 並び: プルダウン →（URL の行／状態の警告）→ 説明文 →「その他」の URL 欄。
			$select_pos      = strpos( $html, 'id="vkbm-provider-privacy-policy-page"' );
			$description_pos = strpos( $html, 'id="vkbm-provider-privacy-policy-page-description"' );
			$url_field_pos   = strpos( $html, 'id="vkbm-provider-privacy-policy-other-url-field"' );
			$this->assertLessThan( $description_pos, $select_pos, $case['test_condition_name'] . '（プルダウンは説明文より前）' );
			$this->assertLessThan( $url_field_pos, $description_pos, $case['test_condition_name'] . '（説明文は URL 欄より前）' );
			foreach ( array( 'vkbm-provider-privacy-policy-page-info', 'vkbm-provider-privacy-policy-page-problem' ) as $before_description_id ) {
				$pos = strpos( $html, 'id="' . $before_description_id . '"' );
				if ( false !== $pos ) {
					$this->assertLessThan( $description_pos, $pos, $case['test_condition_name'] . '（' . $before_description_id . ' は説明文より前）' );
				}
			}

			// 「その他」の URL 欄。
			$field_tag = $this->opening_tag_by_id( $html, 'vkbm-provider-privacy-policy-other-url-field' );
			$this->assertSame( $case['expected_url_hidden'], false !== strpos( $field_tag, 'hidden' ), $case['test_condition_name'] . '（URL 欄の hidden）' );
			$input_tag = $this->opening_tag_by_id( $html, 'vkbm-provider-privacy-policy-url' );
			$this->assertStringContainsString( 'name="vkbm_provider_settings[provider_privacy_policy_url]"', $input_tag, $case['test_condition_name'] . '（URL の name）' );
			$this->assertSame( $case['expected_url_value'], html_entity_decode( (string) $this->attribute( $input_tag, 'value' ) ), $case['test_condition_name'] . '（URL の値）' );
			$this->assertSame( 'vkbm-provider-privacy-policy-url-description', $this->attribute( $input_tag, 'aria-describedby' ), $case['test_condition_name'] . '（URL 欄の aria-describedby）' );

			// 選択中のページの URL の行。
			$info_tag = $this->opening_tag_by_id( $html, 'vkbm-provider-privacy-policy-page-info' );
			if ( '' !== $case['expected_page_link'] ) {
				$this->assertNotSame( '', $info_tag, $case['test_condition_name'] . '（URL の行）' );
				$this->assertSame( 1, preg_match( '/<a[^>]*href="' . preg_quote( esc_url( $case['expected_page_link'] ), '/' ) . '"[^>]*target="_blank"/', $this->inner_html_by_id( $html, 'vkbm-provider-privacy-policy-page-info' ) ), $case['test_condition_name'] . '（新しいタブで開くリンク）' );
			} else {
				$this->assertSame( '', $info_tag, $case['test_condition_name'] . '（URL の行は出さない）' );
			}

			// 警告はプライバシーポリシーについて1件だけ。リンクは状態ごとに1つ。
			$this->assertSame( $case['expected_warning'] ? 1 : 0, substr_count( $html, 'vkbm-privacy-policy-page-warning' ), $case['test_condition_name'] . '（警告）' );
			if ( null !== $case['expected_warning_link'] ) {
				$warning_html = $this->inner_html_by_id( $html, 'vkbm-provider-privacy-policy-page-problem' );
				$this->assertNotSame( '', $warning_html, $case['test_condition_name'] . '（警告の本文）' );
				if ( 'none' === $case['expected_warning_link'] ) {
					$this->assertStringNotContainsString( '<a ', $warning_html, $case['test_condition_name'] . '（この欄で選び直すのでリンクは無い）' );
				} else {
					$this->assertStringContainsString( $case['expected_warning_link'], $warning_html, $case['test_condition_name'] . '（行き先のリンク）' );
				}
				$this->assertStringContainsString( $case['expected_warning_text'], $warning_html, $case['test_condition_name'] . '（次にすること）' );
			}
		}
	}
}
