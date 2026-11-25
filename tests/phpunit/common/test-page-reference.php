<?php
/**
 * 固定ページを ID で参照するための共通処理（issue #1050）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Common;

use VKBookingManager\Capabilities\Roles_Manager;
use VKBookingManager\Common\Page_Reference;
use WP_UnitTestCase;

/**
 * Page_Reference の URL → 固定ページ ID の変換と、ページの状態判定を検証する。
 *
 * @group common
 */
class Page_Reference_Test extends WP_UnitTestCase {

	/**
	 * パーマリンク構造を元に戻し、表示設定を掃除する。
	 */
	public function tear_down(): void {
		$this->set_permalink_structure( '' );
		delete_option( 'show_on_front' );
		delete_option( 'page_on_front' );
		parent::tear_down();
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
					'post_title'  => 'Booking',
					'post_name'   => 'booking',
					'post_status' => 'publish',
				),
				$args
			)
		);
	}

	/**
	 * find_page_id_by_url() が、このサイトの公開中の固定ページを指す URL だけを ID にすることを検証する。
	 */
	public function test_find_page_id_by_url(): void {
		$this->set_permalink_structure( '/%postname%/' );

		$published_id = $this->create_page();
		$draft_id     = $this->create_page(
			array(
				'post_name'   => 'draft-booking',
				'post_status' => 'draft',
			)
		);
		$post_id      = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_name'   => 'news',
				'post_status' => 'publish',
			)
		);
		$front_id     = $this->create_page( array( 'post_name' => 'front' ) );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front_id );

		// テスト環境の home_url はポート付き（例: localhost:9300）のため、ホストにはポートも含める。
		$home_port = wp_parse_url( home_url(), PHP_URL_PORT );
		$host      = (string) wp_parse_url( home_url(), PHP_URL_HOST ) . ( $home_port ? ':' . $home_port : '' );

		$test_cases = array(
			array(
				'test_condition_name' => '公開中の固定ページのパーマリンク => その ID',
				'url'                 => get_permalink( $published_id ),
				'expected'            => $published_id,
			),
			array(
				'test_condition_name' => '末尾スラッシュ無し => 同じページとみなして ID',
				'url'                 => untrailingslashit( get_permalink( $published_id ) ),
				'expected'            => $published_id,
			),
			array(
				'test_condition_name' => 'https 表記（サイトは http） => 同じページとみなして ID',
				'url'                 => 'https://' . $host . '/booking/',
				'expected'            => $published_id,
			),
			array(
				'test_condition_name' => '先頭に www. 付き => 同じページとみなして ID',
				'url'                 => 'http://www.' . $host . '/booking/',
				'expected'            => $published_id,
			),
			array(
				'test_condition_name' => 'クエリ付き（?menu_id=1） => パーマリンクと一致しないので 0',
				'url'                 => get_permalink( $published_id ) . '?menu_id=1',
				'expected'            => 0,
			),
			array(
				'test_condition_name' => 'フラグメント付き（#form） => パーマリンクと一致しないので 0',
				'url'                 => get_permalink( $published_id ) . '#form',
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '下書きの固定ページ => 0',
				'url'                 => home_url( '/draft-booking/' ),
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '固定ページ以外（投稿） => 0',
				'url'                 => get_permalink( $post_id ),
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '旧ドメインの URL => 0',
				'url'                 => 'https://old.example.com/booking/',
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '存在しないパス => 0',
				'url'                 => home_url( '/no-such-page/' ),
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '空文字 => 0',
				'url'                 => '',
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '表示設定で固定ページをトップにしているサイトのトップページ URL => トップに表示するページの ID',
				'url'                 => home_url( '/' ),
				'expected'            => $front_id,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Page_Reference::find_page_id_by_url( $case['url'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * get_page_problem() が、ページの状態を区別して返すことを検証する。
	 */
	public function test_get_page_problem(): void {
		$deleted_id = $this->create_page( array( 'post_name' => 'deleted' ) );
		wp_delete_post( $deleted_id, true );

		$test_cases = array(
			array(
				'test_condition_name' => '公開中 => 問題なし',
				'page_id'             => $this->create_page(),
				'expected'            => Page_Reference::PROBLEM_NONE,
			),
			array(
				'test_condition_name' => 'パスワード保護（公開中） => 問題なし',
				'page_id'             => $this->create_page( array( 'post_password' => 'secret' ) ),
				'expected'            => Page_Reference::PROBLEM_NONE,
			),
			array(
				'test_condition_name' => '下書き => 未公開',
				'page_id'             => $this->create_page( array( 'post_status' => 'draft' ) ),
				'expected'            => Page_Reference::PROBLEM_UNPUBLISHED,
			),
			array(
				'test_condition_name' => 'レビュー待ち => 未公開',
				'page_id'             => $this->create_page( array( 'post_status' => 'pending' ) ),
				'expected'            => Page_Reference::PROBLEM_UNPUBLISHED,
			),
			array(
				'test_condition_name' => '公開予定 => 未公開',
				'page_id'             => $this->create_page(
					array(
						'post_status' => 'future',
						'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
					)
				),
				'expected'            => Page_Reference::PROBLEM_UNPUBLISHED,
			),
			array(
				'test_condition_name' => '非公開 => 未公開',
				'page_id'             => $this->create_page( array( 'post_status' => 'private' ) ),
				'expected'            => Page_Reference::PROBLEM_UNPUBLISHED,
			),
			array(
				'test_condition_name' => 'ゴミ箱 => ゴミ箱',
				'page_id'             => $this->create_page( array( 'post_status' => 'trash' ) ),
				'expected'            => Page_Reference::PROBLEM_TRASH,
			),
			array(
				'test_condition_name' => '完全に削除済み => 見つからない',
				'page_id'             => $deleted_id,
				'expected'            => Page_Reference::PROBLEM_NOT_FOUND,
			),
			array(
				'test_condition_name' => '固定ページ以外（投稿） => 見つからない',
				'page_id'             => self::factory()->post->create( array( 'post_type' => 'post' ) ),
				'expected'            => Page_Reference::PROBLEM_NOT_FOUND,
			),
			array(
				'test_condition_name' => 'ID が 0 => 見つからない',
				'page_id'             => 0,
				'expected'            => Page_Reference::PROBLEM_NOT_FOUND,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Page_Reference::get_page_problem( $case['page_id'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * sanitize_page_id() が、存在する固定ページの ID（ゴミ箱を含む）だけを通すことを検証する。
	 */
	public function test_sanitize_page_id(): void {
		$published_id = $this->create_page();
		$trash_id     = $this->create_page( array( 'post_status' => 'trash' ) );
		$deleted_id   = $this->create_page( array( 'post_name' => 'deleted' ) );
		wp_delete_post( $deleted_id, true );

		$test_cases = array(
			array(
				'test_condition_name' => '公開中の固定ページの ID（文字列） => その ID',
				'value'               => (string) $published_id,
				'expected'            => $published_id,
			),
			array(
				'test_condition_name' => 'ゴミ箱の固定ページの ID => 選択を保つためそのまま通す',
				'value'               => $trash_id,
				'expected'            => $trash_id,
			),
			array(
				'test_condition_name' => '完全に削除済みの ID => 0',
				'value'               => $deleted_id,
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '固定ページ以外（投稿）の ID => 0',
				'value'               => self::factory()->post->create( array( 'post_type' => 'post' ) ),
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '「その他」の値（other） => 0',
				'value'               => 'other',
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '空文字 => 0',
				'value'               => '',
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '負の数 => 0',
				'value'               => -5,
				'expected'            => 0,
			),
			array(
				'test_condition_name' => 'null => 0',
				'value'               => null,
				'expected'            => 0,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Page_Reference::sanitize_page_id( $case['value'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * get_fix_action() が、そのページを今の利用者が直せるときだけ行き先を返すことを検証する（#1168 で予約ページから切り出し）。
	 */
	public function test_get_fix_action(): void {
		// ラベルは英語（原文）で検証する（先に動いた他のテストが日本語の翻訳を読み込んでいると、実行順で結果が変わるため）。
		switch_to_locale( 'en_US' );
		unload_textdomain( 'vk-booking-manager', true );
		( new Roles_Manager() )->sync_roles();
		$admin_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$editor_id     = self::factory()->user->create( array( 'role' => 'editor' ) );
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$owner_id      = self::factory()->user->create( array( 'role' => Roles_Manager::ROLE_PROVIDER_OWNER ) );
		$no_publish_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$no_delete_id  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$draft_id      = $this->create_page( array( 'post_status' => 'draft' ) );
		$trash_id      = $this->create_page( array( 'post_status' => 'trash' ) );
		$others_trash  = $this->create_page(
			array(
				'post_status' => 'trash',
				'post_author' => $admin_id,
			)
		);
		// 固定ページを編集できても公開できない利用者（独自ロールなど）にする。
		get_user_by( 'id', $no_publish_id )->add_cap( 'publish_pages', false );
		// 固定ページの公開・編集はできるが、他人のページを削除（ゴミ箱から戻す）できない利用者（独自ロールなど）にする。
		get_user_by( 'id', $no_delete_id )->add_cap( 'delete_others_pages', false );

		$test_cases = array(
			array(
				'test_condition_name' => '未公開・管理者 => そのページの編集画面',
				'user_id'             => $admin_id,
				'problem'             => Page_Reference::PROBLEM_UNPUBLISHED,
				'page_id'             => $draft_id,
				'expected_url_part'   => 'post.php?post=' . $draft_id . '&action=edit',
				'expected_label'      => 'Edit the page',
			),
			array(
				'test_condition_name' => 'ゴミ箱・編集者 => 固定ページのゴミ箱の一覧',
				'user_id'             => $editor_id,
				'problem'             => Page_Reference::PROBLEM_TRASH,
				'page_id'             => $trash_id,
				'expected_url_part'   => 'edit.php?post_status=trash&post_type=page',
				'expected_label'      => 'Open the trash',
			),
			array(
				'test_condition_name' => '未公開・購読者 => null（選び直しを案内する）',
				'user_id'             => $subscriber_id,
				'problem'             => Page_Reference::PROBLEM_UNPUBLISHED,
				'page_id'             => $draft_id,
				'expected_url_part'   => null,
				'expected_label'      => null,
			),
			array(
				'test_condition_name' => 'ゴミ箱・業務オーナー（固定ページの権限なし） => null',
				'user_id'             => $owner_id,
				'problem'             => Page_Reference::PROBLEM_TRASH,
				'page_id'             => $trash_id,
				'expected_url_part'   => null,
				'expected_label'      => null,
			),
			array(
				'test_condition_name' => '未公開・編集できるが公開できない => null（公開まで直せない）',
				'user_id'             => $no_publish_id,
				'problem'             => Page_Reference::PROBLEM_UNPUBLISHED,
				'page_id'             => $draft_id,
				'expected_url_part'   => null,
				'expected_label'      => null,
			),
			array(
				'test_condition_name' => '他人がゴミ箱に入れたページ・他人のページを戻せない => null',
				'user_id'             => $no_delete_id,
				'problem'             => Page_Reference::PROBLEM_TRASH,
				'page_id'             => $others_trash,
				'expected_url_part'   => null,
				'expected_label'      => null,
			),
			array(
				'test_condition_name' => '削除済み・管理者 => null（選び直すしかない）',
				'user_id'             => $admin_id,
				'problem'             => Page_Reference::PROBLEM_NOT_FOUND,
				'page_id'             => 0,
				'expected_url_part'   => null,
				'expected_label'      => null,
			),
		);

		foreach ( $test_cases as $case ) {
			wp_set_current_user( $case['user_id'] );

			$action = Page_Reference::get_fix_action( $case['problem'], $case['page_id'] );

			if ( null === $case['expected_url_part'] ) {
				$this->assertNull( $action, $case['test_condition_name'] );
				continue;
			}
			$this->assertStringContainsString( $case['expected_url_part'], (string) ( $action['url'] ?? '' ), $case['test_condition_name'] . '（URL）' );
			$this->assertSame( $case['expected_label'], $action['label'] ?? null, $case['test_condition_name'] . '（ラベル）' );
		}
		wp_set_current_user( 0 );
		restore_previous_locale();
	}

	/**
	 * is_same_page_url() が、スキーム・www.・末尾スラッシュの違いだけを同じページとみなすことを検証する。
	 */
	public function test_is_same_page_url(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '同じ URL => true',
				'url_a'               => 'https://example.test/booking/',
				'url_b'               => 'https://example.test/booking/',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'http と https の違い => true',
				'url_a'               => 'http://example.test/booking/',
				'url_b'               => 'https://example.test/booking/',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '先頭の www. の有無 => true',
				'url_a'               => 'https://www.example.test/booking/',
				'url_b'               => 'https://example.test/booking/',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '末尾スラッシュの有無 => true',
				'url_a'               => 'https://example.test/booking',
				'url_b'               => 'https://example.test/booking/',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'ホストの大文字小文字の違い => true',
				'url_a'               => 'https://EXAMPLE.test/booking/',
				'url_b'               => 'https://example.test/booking/',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'パスが違う => false',
				'url_a'               => 'https://example.test/booking/',
				'url_b'               => 'https://example.test/reserve/',
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'クエリの有無 => false',
				'url_a'               => 'https://example.test/booking/?menu_id=1',
				'url_b'               => 'https://example.test/booking/',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '別ドメイン => false',
				'url_a'               => 'https://old.example.com/booking/',
				'url_b'               => 'https://example.test/booking/',
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'ホストが取れない文字列 => false',
				'url_a'               => '/booking/',
				'url_b'               => '/booking/',
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Page_Reference::is_same_page_url( $case['url_a'], $case['url_b'] ), $case['test_condition_name'] );
		}
	}
}
