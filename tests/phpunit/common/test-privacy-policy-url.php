<?php
/**
 * プライバシーポリシーの表示方法とリンク先の判定（issue #1168）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Common;

use VKBookingManager\Capabilities\Roles_Manager;
use VKBookingManager\Common\Page_Reference;
use VKBookingManager\Common\Privacy_Policy_Url;
use WP_UnitTestCase;

/**
 * Privacy_Policy_Url の表示方法の読み取り・リンク先の決定・選んだ固定ページの警告を検証する。
 *
 * @group common
 */
class Privacy_Policy_Url_Test extends WP_UnitTestCase {

	/**
	 * 文言の検証は英語（原文）で行う。
	 *
	 * サイトの言語が日本語で、先に動いた他のテストが日本語の翻訳を読み込んでいると、
	 * 警告文が日本語になり、実行する順番で結果が変わるため。
	 */
	protected function setUp(): void {
		parent::setUp();
		switch_to_locale( 'en_US' );
		unload_textdomain( 'vk-booking-manager', true );
	}

	/**
	 * 切り替えた言語・ホスト・パーマリンク構造・ログインを元に戻す。
	 */
	protected function tearDown(): void {
		restore_previous_locale();
		remove_all_filters( 'pre_option_home' );
		$this->set_permalink_structure( '' );
		wp_set_current_user( 0 );
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
					'post_title'  => 'Privacy',
					'post_name'   => 'privacy',
					'post_status' => 'publish',
				),
				$args
			)
		);
	}

	/**
	 * get_mode() が、選択肢の値だけを通し、それ以外を「表示しない」にすることを検証する。
	 */
	public function test_get_mode(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'url => url',
				'settings'            => array( 'provider_privacy_policy_mode' => 'url' ),
				'expected'            => 'url',
			),
			array(
				'test_condition_name' => 'content => content',
				'settings'            => array( 'provider_privacy_policy_mode' => 'content' ),
				'expected'            => 'content',
			),
			array(
				'test_condition_name' => '大文字・前後の空白 => 整えて url',
				'settings'            => array( 'provider_privacy_policy_mode' => ' URL ' ),
				'expected'            => 'url',
			),
			array(
				'test_condition_name' => '選択肢に無い値 => none',
				'settings'            => array( 'provider_privacy_policy_mode' => 'unknown' ),
				'expected'            => 'none',
			),
			array(
				'test_condition_name' => '配列 => none',
				'settings'            => array( 'provider_privacy_policy_mode' => array( 'url' ) ),
				'expected'            => 'none',
			),
			array(
				'test_condition_name' => 'キーが無い => none',
				'settings'            => array(),
				'expected'            => 'none',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Privacy_Policy_Url::get_mode( $case['settings'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * get_url() が、選んだ固定ページ（公開中のみ）か「その他」の URL をリンク先にすることを検証する。
	 *
	 * 既存の設定（URL だけ・外部サイトの URL・旧ドメインの URL・未設定）は、更新前と同じ URL を返す。
	 */
	public function test_get_url(): void {
		$this->set_permalink_structure( '/%postname%/' );

		$published_id = $this->create_page();
		$draft_id     = $this->create_page(
			array(
				'post_name'   => 'draft-privacy',
				'post_status' => 'draft',
			)
		);
		$trash_id     = $this->create_page(
			array(
				'post_name'   => 'trashed-privacy',
				'post_status' => 'trash',
			)
		);
		$deleted_id   = $this->create_page( array( 'post_name' => 'deleted-privacy' ) );
		wp_delete_post( $deleted_id, true );
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);

		$test_cases = array(
			array(
				'test_condition_name' => '既存の設定: URL だけ（外部サイト） => その URL（更新前と同じ）',
				'settings'            => array(
					'provider_privacy_policy_mode' => 'url',
					'provider_privacy_policy_url'  => 'https://external.example.com/privacy/',
				),
				'expected'            => 'https://external.example.com/privacy/',
			),
			array(
				'test_condition_name' => '既存の設定: URL だけ（旧ドメイン） => その URL（外部サイトと区別できないため、更新前と同じ）',
				'settings'            => array(
					'provider_privacy_policy_mode' => 'url',
					'provider_privacy_policy_url'  => 'https://old.example.com/privacy/',
				),
				'expected'            => 'https://old.example.com/privacy/',
			),
			array(
				'test_condition_name' => '既存の設定: URL の前後に空白 => 空白を除いた URL',
				'settings'            => array(
					'provider_privacy_policy_mode' => 'url',
					'provider_privacy_policy_url'  => '  https://external.example.com/privacy/ ',
				),
				'expected'            => 'https://external.example.com/privacy/',
			),
			array(
				'test_condition_name' => '既存の設定: ページ指定で URL も空 => 空文字',
				'settings'            => array(
					'provider_privacy_policy_mode' => 'url',
					'provider_privacy_policy_url'  => '',
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '公開中の固定ページを選択（URL 無し） => そのページのパーマリンク（変更前はリンクが出なかった）',
				'settings'            => array(
					'provider_privacy_policy_mode'    => 'url',
					'provider_privacy_policy_page_id' => $published_id,
				),
				'expected'            => home_url( '/privacy/' ),
			),
			array(
				'test_condition_name' => '公開中の固定ページを選択（ID は文字列）と別の URL => 固定ページを優先',
				'settings'            => array(
					'provider_privacy_policy_mode'    => 'url',
					'provider_privacy_policy_page_id' => (string) $published_id,
					'provider_privacy_policy_url'     => 'https://external.example.com/privacy/',
				),
				'expected'            => home_url( '/privacy/' ),
			),
			array(
				'test_condition_name' => '下書きの固定ページを選択し URL も残っている => 空文字（残っている URL へは戻さない）',
				'settings'            => array(
					'provider_privacy_policy_mode'    => 'url',
					'provider_privacy_policy_page_id' => $draft_id,
					'provider_privacy_policy_url'     => home_url( '/draft-privacy/' ),
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => 'ゴミ箱の固定ページを選択 => 空文字',
				'settings'            => array(
					'provider_privacy_policy_mode'    => 'url',
					'provider_privacy_policy_page_id' => $trash_id,
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '完全に削除した固定ページを選択 => 空文字',
				'settings'            => array(
					'provider_privacy_policy_mode'    => 'url',
					'provider_privacy_policy_page_id' => $deleted_id,
					'provider_privacy_policy_url'     => 'https://external.example.com/privacy/',
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '固定ページ以外（投稿）の ID => 空文字',
				'settings'            => array(
					'provider_privacy_policy_mode'    => 'url',
					'provider_privacy_policy_page_id' => $post_id,
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '本文を入力する方式 => 空文字（固定ページを選んでいても使わない）',
				'settings'            => array(
					'provider_privacy_policy_mode'    => 'content',
					'provider_privacy_policy_page_id' => $published_id,
					'provider_privacy_policy_url'     => 'https://external.example.com/privacy/',
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '既存の設定: 表示しない（none）で URL あり => 空文字（更新前と同じ）',
				'settings'            => array(
					'provider_privacy_policy_mode' => 'none',
					'provider_privacy_policy_url'  => 'https://external.example.com/privacy/',
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '既存の設定: 表示方法のキーが無い => 空文字',
				'settings'            => array( 'provider_privacy_policy_url' => 'https://external.example.com/privacy/' ),
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Privacy_Policy_Url::get_url( $case['settings'] ), $case['test_condition_name'] );
		}

		// DB ごと別ドメインへ移したあとも、選んだ固定ページは移した先のドメインの URL になる（設定のし直しが要らない）。
		add_filter(
			'pre_option_home',
			static function () {
				return 'https://new.example.com';
			},
			PHP_INT_MAX
		);
		$this->assertSame(
			'https://new.example.com/privacy/',
			Privacy_Policy_Url::get_url(
				array(
					'provider_privacy_policy_mode'    => 'url',
					'provider_privacy_policy_page_id' => $published_id,
				)
			),
			'ドメインを移したあと => 新しいドメインのパーマリンク'
		);
	}

	/**
	 * get_problem() が、選んだ固定ページが公開中でないときだけ警告用情報を返すことを検証する。
	 */
	public function test_get_problem(): void {
		$published_id = $this->create_page();
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
		$deleted_id   = $this->create_page();
		wp_delete_post( $deleted_id, true );

		$test_cases = array(
			array(
				'test_condition_name' => '公開中の固定ページ => null',
				'settings'            => array( 'provider_privacy_policy_page_id' => $published_id ),
				'expected'            => null,
			),
			array(
				'test_condition_name' => '固定ページを選んでいない（外部サイトの URL） => null（「その他」の URL は判定しない）',
				'settings'            => array( 'provider_privacy_policy_url' => 'https://old.example.com/privacy/' ),
				'expected'            => null,
			),
			array(
				'test_condition_name' => '未設定 => null',
				'settings'            => array(),
				'expected'            => null,
			),
			array(
				'test_condition_name' => '下書き => 未公開とページ名',
				'settings'            => array( 'provider_privacy_policy_page_id' => $draft_id ),
				'expected'            => array(
					'type'       => Page_Reference::PROBLEM_UNPUBLISHED,
					'page_id'    => $draft_id,
					'page_title' => 'Draft Privacy',
				),
			),
			array(
				'test_condition_name' => 'ゴミ箱 => ゴミ箱とページ名',
				'settings'            => array( 'provider_privacy_policy_page_id' => $trash_id ),
				'expected'            => array(
					'type'       => Page_Reference::PROBLEM_TRASH,
					'page_id'    => $trash_id,
					'page_title' => 'Trashed Privacy',
				),
			),
			array(
				'test_condition_name' => '完全に削除済み => 見つからない（ページ名は空）',
				'settings'            => array( 'provider_privacy_policy_page_id' => $deleted_id ),
				'expected'            => array(
					'type'       => Page_Reference::PROBLEM_NOT_FOUND,
					'page_id'    => $deleted_id,
					'page_title' => '',
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Privacy_Policy_Url::get_problem( $case['settings'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * build_problem_message() が、状態とページを直せるかに応じて「次にすること」を変え、直すまでどうなるかを添えることを検証する。
	 */
	public function test_build_problem_message(): void {
		// 業務オーナー（BM 設定はできるが、固定ページの権限を外されている）のロールを用意する。
		( new Roles_Manager() )->sync_roles();
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$owner_id = self::factory()->user->create( array( 'role' => Roles_Manager::ROLE_PROVIDER_OWNER ) );
		$draft_id = $this->create_page( array( 'post_status' => 'draft' ) );
		$trash_id = $this->create_page( array( 'post_status' => 'trash' ) );

		$test_cases = array(
			array(
				'test_condition_name' => '未公開・ページを直せる（管理者） => ページ名をエスケープして含み、公開を勧める',
				'user_id'             => $admin_id,
				'problem'             => array(
					'type'       => Page_Reference::PROBLEM_UNPUBLISHED,
					'page_id'    => $draft_id,
					'page_title' => 'Privacy <b>Draft</b>',
				),
				'expected_contains'   => 'The page &quot;Privacy &lt;b&gt;Draft&lt;/b&gt;&quot; selected as the privacy policy page is not published.',
				'expected_action'     => 'Please publish the page.',
			),
			array(
				'test_condition_name' => '未公開・ページを直せない（業務オーナー） => 公開ではなく選び直しを勧める',
				'user_id'             => $owner_id,
				'problem'             => array(
					'type'       => Page_Reference::PROBLEM_UNPUBLISHED,
					'page_id'    => $draft_id,
					'page_title' => 'Draft Privacy',
				),
				'expected_contains'   => 'Draft Privacy',
				'expected_action'     => 'Please select the privacy policy page again.',
			),
			array(
				'test_condition_name' => 'ゴミ箱・ページを直せる（管理者） => ゴミ箱から戻して公開することを勧める（戻すと下書きになるため）',
				'user_id'             => $admin_id,
				'problem'             => array(
					'type'       => Page_Reference::PROBLEM_TRASH,
					'page_id'    => $trash_id,
					'page_title' => 'Trashed Privacy',
				),
				'expected_contains'   => 'The page &quot;Trashed Privacy&quot; selected as the privacy policy page is in the trash.',
				'expected_action'     => 'Please restore the page from the trash and publish it.',
			),
			array(
				'test_condition_name' => 'ゴミ箱・ページを直せない（業務オーナー） => 選び直しを勧める',
				'user_id'             => $owner_id,
				'problem'             => array(
					'type'       => Page_Reference::PROBLEM_TRASH,
					'page_id'    => $trash_id,
					'page_title' => 'Trashed Privacy',
				),
				'expected_contains'   => 'Trashed Privacy',
				'expected_action'     => 'Please select the privacy policy page again.',
			),
			array(
				'test_condition_name' => '削除済み・管理者 => 選び直しを勧める',
				'user_id'             => $admin_id,
				'problem'             => array(
					'type'       => Page_Reference::PROBLEM_NOT_FOUND,
					'page_id'    => 999999,
					'page_title' => '',
				),
				'expected_contains'   => 'The page selected as the privacy policy page has been deleted.',
				'expected_action'     => 'Please select the privacy policy page again.',
			),
		);

		foreach ( $test_cases as $case ) {
			wp_set_current_user( $case['user_id'] );
			$message = Privacy_Policy_Url::build_problem_message( $case['problem'] );

			$this->assertStringContainsString( $case['expected_contains'], $message, $case['test_condition_name'] . '（何が起きているか）' );
			$this->assertStringContainsString( $case['expected_action'], $message, $case['test_condition_name'] . '（次にすること）' );
			$this->assertStringEndsWith(
				'Until then, the user registration form does not ask for consent to the privacy policy, and the footer of the reservation page does not show the link either.',
				$message,
				$case['test_condition_name'] . '（直すまでどうなるか）'
			);
		}

		$this->assertSame( '', Privacy_Policy_Url::build_problem_message( array( 'type' => 'unknown' ) ), '種類が不明 => 空文字' );
	}

	/**
	 * get_problem_action() が、そのページを直せる利用者には直す画面を、直せないときは同意項目タブの欄を返すことを検証する。
	 */
	public function test_get_problem_action(): void {
		( new Roles_Manager() )->sync_roles();
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$owner_id = self::factory()->user->create( array( 'role' => Roles_Manager::ROLE_PROVIDER_OWNER ) );
		$draft_id = $this->create_page( array( 'post_status' => 'draft' ) );
		$trash_id = $this->create_page( array( 'post_status' => 'trash' ) );

		$test_cases = array(
			array(
				'test_condition_name' => '未公開・管理者 => そのページの編集画面',
				'user_id'             => $admin_id,
				'problem'             => array(
					'type'    => Page_Reference::PROBLEM_UNPUBLISHED,
					'page_id' => $draft_id,
				),
				'expected_url_part'   => 'post.php?post=' . $draft_id . '&action=edit',
				'expected_label'      => 'Edit the page',
				'expected_settings'   => false,
			),
			array(
				'test_condition_name' => 'ゴミ箱・管理者 => 固定ページのゴミ箱の一覧',
				'user_id'             => $admin_id,
				'problem'             => array(
					'type'    => Page_Reference::PROBLEM_TRASH,
					'page_id' => $trash_id,
				),
				'expected_url_part'   => 'edit.php?post_status=trash&post_type=page',
				'expected_label'      => 'Open the trash',
				'expected_settings'   => false,
			),
			array(
				'test_condition_name' => '未公開・業務オーナー（固定ページの権限なし） => 同意項目タブのプライバシーポリシーページの欄',
				'user_id'             => $owner_id,
				'problem'             => array(
					'type'    => Page_Reference::PROBLEM_UNPUBLISHED,
					'page_id' => $draft_id,
				),
				'expected_url_part'   => 'admin.php?page=vkbm-provider-settings&tab=consent#vkbm-provider-privacy-policy-page',
				'expected_label'      => 'Set the privacy policy page',
				'expected_settings'   => true,
			),
			array(
				'test_condition_name' => '削除済み・管理者 => 同意項目タブのプライバシーポリシーページの欄',
				'user_id'             => $admin_id,
				'problem'             => array(
					'type'    => Page_Reference::PROBLEM_NOT_FOUND,
					'page_id' => 999999,
				),
				'expected_url_part'   => 'admin.php?page=vkbm-provider-settings&tab=consent#vkbm-provider-privacy-policy-page',
				'expected_label'      => 'Set the privacy policy page',
				'expected_settings'   => true,
			),
		);

		foreach ( $test_cases as $case ) {
			wp_set_current_user( $case['user_id'] );
			$action = Privacy_Policy_Url::get_problem_action( $case['problem'] );

			$this->assertStringContainsString( $case['expected_url_part'], $action['url'], $case['test_condition_name'] . '（URL）' );
			$this->assertSame( $case['expected_label'], $action['label'], $case['test_condition_name'] . '（ラベル）' );
			$this->assertSame( $case['expected_settings'], $action['is_settings'], $case['test_condition_name'] . '（設定欄への案内か）' );
		}
	}

	/**
	 * get_active_problem() が、表示方法が「プライバシーポリシーページを指定」のときだけ警告用情報を返すことを検証する。
	 */
	public function test_get_active_problem(): void {
		$draft_id     = $this->create_page(
			array(
				'post_title'  => 'Draft Privacy',
				'post_status' => 'draft',
			)
		);
		$published_id = $this->create_page();

		$test_cases = array(
			array(
				'test_condition_name' => 'ページ指定・下書きの固定ページ => 未公開の警告用情報',
				'settings'            => array(
					'provider_privacy_policy_mode'    => 'url',
					'provider_privacy_policy_page_id' => $draft_id,
				),
				'expected'            => array(
					'type'       => Page_Reference::PROBLEM_UNPUBLISHED,
					'page_id'    => $draft_id,
					'page_title' => 'Draft Privacy',
				),
			),
			array(
				'test_condition_name' => 'ページ指定・公開中の固定ページ => null',
				'settings'            => array(
					'provider_privacy_policy_mode'    => 'url',
					'provider_privacy_policy_page_id' => $published_id,
				),
				'expected'            => null,
			),
			array(
				'test_condition_name' => '本文を入力する方式・下書きの固定ページが残っている => null（選んだページを使わない）',
				'settings'            => array(
					'provider_privacy_policy_mode'    => 'content',
					'provider_privacy_policy_page_id' => $draft_id,
				),
				'expected'            => null,
			),
			array(
				'test_condition_name' => '表示方法のキーが無い・下書きの固定ページ => null',
				'settings'            => array( 'provider_privacy_policy_page_id' => $draft_id ),
				'expected'            => null,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Privacy_Policy_Url::get_active_problem( $case['settings'] ), $case['test_condition_name'] );
		}
	}
}
