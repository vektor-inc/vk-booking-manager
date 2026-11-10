<?php
/**
 * BM 設定 > 基本設定の設定メニュー（グループ見出し付きのナビゲーション）のテスト（issue #1065）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use DOMDocument;
use DOMXPath;
use VKBookingManager\Admin\Provider_Settings_Navigation;
use VKBookingManager\Admin\Provider_Settings_Page;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use WP_UnitTestCase;
use function add_action;
use function add_filter;
use function admin_url;
use function remove_action;
use function remove_filter;
use function wp_set_current_user;

/**
 * 設定メニューのグループ構成・現在地の表示・拡張プラグインのリンクの差し込みを検証するテストクラス。
 *
 * @group admin
 */
class Provider_Settings_Navigation_Test extends WP_UnitTestCase {

	/**
	 * テスト用の拡張タブのスラッグ。
	 */
	private const EXTENSION_TAB = 'vkbm-test-extension';

	/**
	 * 拡張プラグインがリンクを出力するアクションのコールバック（後片付け用）。
	 *
	 * @var callable|null
	 */
	private $extra_tabs_action = null;

	/**
	 * 拡張タブを許可するフィルターのコールバック（後片付け用）。
	 *
	 * @var callable|null
	 */
	private $allowed_tabs_filter = null;

	/**
	 * 管理者でログインする。
	 */
	protected function setUp(): void {
		parent::setUp();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET = array();
	}

	/**
	 * 登録したフック・クエリ文字列・設定を元へ戻す。
	 */
	protected function tearDown(): void {
		if ( null !== $this->extra_tabs_action ) {
			remove_action( 'vkbm_provider_settings_extra_tabs', $this->extra_tabs_action );
			$this->extra_tabs_action = null;
		}
		if ( null !== $this->allowed_tabs_filter ) {
			remove_filter( 'vkbm_provider_settings_allowed_tabs', $this->allowed_tabs_filter );
			$this->allowed_tabs_filter = null;
		}
		delete_option( Settings_Repository::OPTION_KEY );
		$_GET = array();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * 決済プラグインと同じ方法で、拡張タブのリンクと許可スラッグを登録する。
	 */
	private function register_extension_tab(): void {
		$this->allowed_tabs_filter = static function ( array $allowed_tabs ): array {
			$allowed_tabs[] = self::EXTENSION_TAB;
			return $allowed_tabs;
		};
		add_filter( 'vkbm_provider_settings_allowed_tabs', $this->allowed_tabs_filter );

		$this->extra_tabs_action = static function ( string $active_tab, string $base_url ): void {
			printf(
				'<a href="%s" class="nav-tab%s">拡張テスト</a>',
				esc_url( add_query_arg( 'tab', self::EXTENSION_TAB, $base_url ) ),
				self::EXTENSION_TAB === $active_tab ? ' nav-tab-active' : ''
			);
		};
		add_action( 'vkbm_provider_settings_extra_tabs', $this->extra_tabs_action, 10, 2 );
	}

	/**
	 * 指定したタブで設定画面を描画し、XPath を返す。
	 *
	 * @param string $tab 表示するタブ。
	 * @return DOMXPath 描画結果の XPath。
	 */
	private function render_page_xpath( string $tab ): DOMXPath {
		$page = new Provider_Settings_Page(
			new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() ),
			'manage_options',
			''
		);

		$_GET['tab'] = $tab;

		ob_start();
		$page->render_page();
		$html = (string) ob_get_clean();

		$document = new DOMDocument();
		libxml_use_internal_errors( true );
		$document->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>' );
		libxml_clear_errors();

		return new DOMXPath( $document );
	}

	/**
	 * 設定メニューのグループ構成が、条件に応じて空のグループを除いて返ること。
	 */
	public function test_get_groups(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '連携・ライセンス・拡張プラグインなし => 「拡張・連携」グループは出ない',
				'conditions'          => array( false, false, false ),
				'expected'            => array(
					'service'  => array( 'store', 'system', 'email', 'design' ),
					'customer' => array( 'attendees', 'registration', 'consent' ),
					'admin'    => array( 'advanced' ),
					'help'     => array( 'faq' ),
				),
			),
			array(
				'test_condition_name' => '連携あり・ライセンスあり => 「拡張・連携」に連携、「管理」にライセンスが入る',
				'conditions'          => array( true, true, false ),
				'expected'            => array(
					'service'   => array( 'store', 'system', 'email', 'design' ),
					'customer'  => array( 'attendees', 'registration', 'consent' ),
					'extension' => array( 'integration' ),
					'admin'     => array( 'advanced', 'license' ),
					'help'      => array( 'faq' ),
				),
			),
			array(
				'test_condition_name' => '拡張プラグインのリンクだけある => 項目が空でも「拡張・連携」グループは出る',
				'conditions'          => array( false, false, true ),
				'expected'            => array(
					'service'   => array( 'store', 'system', 'email', 'design' ),
					'customer'  => array( 'attendees', 'registration', 'consent' ),
					'extension' => array(),
					'admin'     => array( 'advanced' ),
					'help'      => array( 'faq' ),
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$groups = Provider_Settings_Navigation::get_groups( ...$case['conditions'] );

			$actual = array();
			foreach ( $groups as $group ) {
				$actual[ $group['id'] ] = array_column( $group['items'], 'slug' );
			}

			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * 現在の項目だけに aria-current="page" が付き、本文の上に現在の項目名の見出しが出ること。
	 */
	public function test_render_page_marks_current_item(): void {
		$xpath = $this->render_page_xpath( 'system' );

		$nav_label = $xpath->query( '//nav[contains(@class,"vkbm-provider-settings__nav--sidebar")]/@aria-label' );
		$this->assertSame( 1, $nav_label->length, 'サイドバーの nav 要素は 1 つ' );
		$this->assertNotSame( '', $nav_label->item( 0 )->textContent, 'nav 要素にラベルが付く' );

		$current = $xpath->query( '//nav[contains(@class,"vkbm-provider-settings__nav--sidebar")]//a[@aria-current="page"]' );
		$this->assertSame( 1, $current->length, '現在の項目は 1 つだけ' );
		$this->assertStringContainsString( 'tab=system', $current->item( 0 )->getAttribute( 'href' ), '現在の項目はシステム設定' );

		$title = $xpath->query( '//h2[contains(@class,"vkbm-provider-settings__panel-title")]' );
		$this->assertSame( 1, $title->length, '本文の上に見出しが 1 つ' );
		$this->assertSame( trim( $current->item( 0 )->textContent ), trim( $title->item( 0 )->textContent ), '見出しは現在の項目名' );

		$summary = $xpath->query( '//details[contains(@class,"vkbm-provider-settings__nav-mobile")]/summary' );
		$this->assertSame( 1, $summary->length, '狭い幅用の開閉式メニューがある' );
		$this->assertStringContainsString( trim( $current->item( 0 )->textContent ), $summary->item( 0 )->textContent, '開閉見出しに現在の項目名が入る' );
	}

	/**
	 * 各グループが見出しとリストで組まれ、項目のリンク先（?page=...&tab=...）が従来どおりであること。
	 */
	public function test_render_page_group_structure_and_urls(): void {
		$xpath = $this->render_page_xpath( 'store' );

		$groups = $xpath->query( '//nav[contains(@class,"vkbm-provider-settings__nav--sidebar")]//div[contains(@class,"vkbm-provider-settings__nav-group")]' );
		// 連携・ライセンス・拡張プラグインが無い環境では、予約サービス・お客様・管理・ヘルプの 4 グループ。
		$this->assertGreaterThanOrEqual( 4, $groups->length, '見出し付きのグループが並ぶ' );

		foreach ( $groups as $group ) {
			$title_id = $xpath->query( './p[contains(@class,"vkbm-provider-settings__nav-group-title")]/@id', $group )->item( 0 )->textContent;
			$list     = $xpath->query( './ul[@aria-labelledby="' . $title_id . '"]', $group );
			$this->assertSame( 1, $list->length, 'リストが見出しに関連付く: ' . $title_id );
		}

		$links = $xpath->query( '//nav[contains(@class,"vkbm-provider-settings__nav--sidebar")]//a[contains(@class,"vkbm-provider-settings__nav-link")]/@href' );
		foreach ( $links as $href ) {
			$this->assertStringContainsString( admin_url( 'admin.php' ) . '?page=vkbm-provider-settings&tab=', $href->value, '従来の URL 形式のまま' );
		}
	}

	/**
	 * 拡張プラグインのリンクが「拡張・連携」グループへ差し込まれ、表示中なら現在地として扱われること。
	 */
	public function test_render_page_with_extension_tab(): void {
		$this->register_extension_tab();

		// 拡張タブを表示中: リンクに aria-current が補われ、見出しにリンクの文字が入る。
		$xpath = $this->render_page_xpath( self::EXTENSION_TAB );
		$extra = $xpath->query( '//nav[contains(@class,"vkbm-provider-settings__nav--sidebar")]//li[contains(@class,"vkbm-provider-settings__nav-extra")]/a[contains(@class,"nav-tab")]' );
		$this->assertSame( 1, $extra->length, '拡張プラグインのリンクが設定メニュー内に出る' );
		$this->assertSame( 'page', $extra->item( 0 )->getAttribute( 'aria-current' ), '表示中の拡張タブに aria-current が補われる' );

		$group_title = $xpath->query( '//nav[contains(@class,"vkbm-provider-settings__nav--sidebar")]//li[contains(@class,"vkbm-provider-settings__nav-extra")]/ancestor::div[contains(@class,"vkbm-provider-settings__nav-group")]/p' );
		$this->assertSame( 1, $group_title->length, '拡張タブは見出し付きのグループに入る' );

		$title = $xpath->query( '//h2[contains(@class,"vkbm-provider-settings__panel-title")]' );
		$this->assertSame( '拡張テスト', trim( $title->item( 0 )->textContent ), '見出しは拡張タブのリンクの文字' );

		$current_count = $xpath->query( '//nav[contains(@class,"vkbm-provider-settings__nav--sidebar")]//a[@aria-current="page"]' )->length;
		$this->assertSame( 1, $current_count, '拡張タブ表示中は本体の項目に現在地が付かない' );

		// 本体のタブを表示中: 拡張プラグインのリンクは現在地にならない。
		$xpath = $this->render_page_xpath( 'faq' );
		$extra = $xpath->query( '//nav[contains(@class,"vkbm-provider-settings__nav--sidebar")]//li[contains(@class,"vkbm-provider-settings__nav-extra")]/a[contains(@class,"nav-tab")]' );
		$this->assertSame( 1, $extra->length, '本体のタブ表示中も拡張プラグインのリンクは出る' );
		$this->assertFalse( $extra->item( 0 )->hasAttribute( 'aria-current' ), '本体のタブ表示中は拡張プラグインのリンクに aria-current が付かない' );
	}

	/**
	 * 拡張プラグインのリンクが無いときは、拡張プラグインの差し込み領域を出さないこと。
	 */
	public function test_render_page_without_extension_tab(): void {
		$xpath = $this->render_page_xpath( 'store' );

		$extra = $xpath->query( '//li[contains(@class,"vkbm-provider-settings__nav-extra")]' );
		$this->assertSame( 0, $extra->length, '拡張プラグインのリンクが無ければ差し込み領域も出ない' );
	}

	/**
	 * 現在の項目名を、a 要素の class だけで判定して取り出すこと（class 以外の属性に同じ文字列があっても誤認しない）。
	 */
	public function test_get_active_label_for_extension_link(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'class 以外の属性に nav-tab-active を含む別リンク => 現在地と誤認しない',
				'html'                => '<a href="#" class="nav-tab" data-state="nav-tab-active">誤認リンク</a><a href="#" class="nav-tab">別リンク</a>',
				'expected'            => '',
			),
			array(
				'test_condition_name' => '誤認しうるリンクの後に本当の現在地 => 本当の現在地の文字を返す',
				'html'                => '<a href="#" class="nav-tab" title="nav-tab-active">誤認リンク</a><a href="#" class="nav-tab nav-tab-active"> 本物 <span>の名前</span> </a><a href="#" class="nav-tab">後続</a>',
				'expected'            => '本物 の名前',
			),
		);

		foreach ( $test_cases as $case ) {
			$navigation = new Provider_Settings_Navigation( 'vkbm-unknown', 'http://example.org/wp-admin/admin.php?page=vkbm-provider-settings', false, false, $case['html'] );
			$this->assertSame( $case['expected'], $navigation->get_active_label(), $case['test_condition_name'] );
		}
	}

	/**
	 * 拡張フックは画面ごとに 1 回だけ実行され、サイドバーと狭い幅用メニューの両方で現在のリンクだけに aria-current が付くこと。
	 */
	public function test_extra_tabs_hook_runs_once_and_current_link_is_marked(): void {
		$this->register_extension_tab();

		$calls = 0;
		$count = static function () use ( &$calls ): void {
			++$calls;
		};
		add_action( 'vkbm_provider_settings_extra_tabs', $count );

		$xpath = $this->render_page_xpath( self::EXTENSION_TAB );
		remove_action( 'vkbm_provider_settings_extra_tabs', $count );

		$this->assertSame( 1, $calls, '拡張フックは 1 回だけ実行される' );

		foreach ( array( 'sidebar', 'mobile' ) as $variant ) {
			$current = $xpath->query( '//nav[contains(@class,"vkbm-provider-settings__nav--' . $variant . '")]//a[@aria-current="page"]' );
			$this->assertSame( 1, $current->length, $variant . ' の現在のリンクは 1 つだけ' );
			$this->assertSame( '拡張テスト', trim( $current->item( 0 )->textContent ), $variant . ' の現在のリンクは拡張タブ' );
		}
	}

	/**
	 * id 付きの拡張リンクを、サイドバーと狭い幅用メニューの両方へ出しても id が重複しないこと。
	 */
	public function test_extension_link_id_is_not_duplicated(): void {
		$this->register_extension_tab();
		$id_action = static function (): void {
			echo '<a id="vkbm-test-extension-id" href="#" class="nav-tab">ID付き</a>';
		};
		add_action( 'vkbm_provider_settings_extra_tabs', $id_action );

		$xpath = $this->render_page_xpath( 'store' );
		remove_action( 'vkbm_provider_settings_extra_tabs', $id_action );

		$this->assertSame( 1, $xpath->query( '//*[@id="vkbm-test-extension-id"]' )->length, '拡張リンクの id は画面内で 1 つだけ' );
		$this->assertSame( 2, $xpath->query( '//a[normalize-space(text())="ID付き"]' )->length, 'リンク自体は両方のナビに出る' );
	}
}
