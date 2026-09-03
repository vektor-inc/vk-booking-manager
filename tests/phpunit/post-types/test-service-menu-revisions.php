<?php
/**
 * サービスメニューのリビジョン（カスタムメタ含む）テスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\PostTypes;

use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use WP_UnitTestCase;
use function do_action;
use function get_post_meta;
use function update_post_meta;
use function wp_get_post_revisions;
use function wp_restore_post_revision;
use function wp_update_post;

/**
 * サービスメニューのリビジョン（カスタムメタ含む）に関するテスト。
 *
 * @group post-types
 */
class Service_Menu_Revisions_Test extends WP_UnitTestCase {
	/**
	 * 各テスト前に投稿タイプ・タクソノミー・メタを登録する。
	 */
	protected function setUp(): void {
		parent::setUp();
		// 投稿タイプ・タクソノミー・メタを登録する。
		do_action( 'init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress コアのフック。
	}

	/**
	 * filter_revision_meta_keys() のテスト。
	 *
	 * 対象投稿タイプのときのみメタキーが追加され、既存キーを保持しつつ重複なくマージされること、
	 * 対象外の投稿タイプではキーが変更されないことを確認する。
	 */
	public function test_filter_revision_meta_keys(): void {
		$service_menu  = new Service_Menu_Post_Type();
		$expected_keys = $service_menu->get_revisioned_meta_keys();

		$test_cases = array(
			array(
				'test_condition_name' => 'サービスメニュー投稿タイプ・既存キーなしの場合 => 全メタキーが追加される',
				'conditions'          => array(
					'keys'      => array(),
					'post_type' => Service_Menu_Post_Type::POST_TYPE,
				),
				'expected'            => $expected_keys,
			),
			array(
				'test_condition_name' => 'サービスメニュー投稿タイプ・既存キーありの場合 => 既存キーを保持しつつ重複なくマージされる',
				'conditions'          => array(
					'keys'      => array( '_other_plugin_meta', '_vkbm_base_price' ),
					'post_type' => Service_Menu_Post_Type::POST_TYPE,
				),
				'expected'            => array_values(
					array_unique(
						array_merge( array( '_other_plugin_meta', '_vkbm_base_price' ), $expected_keys )
					)
				),
			),
			array(
				'test_condition_name' => '別の投稿タイプの場合 => キーは変更されない',
				'conditions'          => array(
					'keys'      => array( '_other_plugin_meta' ),
					'post_type' => 'post',
				),
				'expected'            => array( '_other_plugin_meta' ),
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = $service_menu->filter_revision_meta_keys(
				$case['conditions']['keys'],
				$case['conditions']['post_type']
			);
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * get_revisioned_meta_keys() のテスト。
	 *
	 * 主要なメタキーが含まれること、廃止済み・互換用の旧メタが含まれないこと、
	 * キーに重複がないことを確認する。
	 */
	public function test_get_revisioned_meta_keys(): void {
		$service_menu = new Service_Menu_Post_Type();
		$keys         = $service_menu->get_revisioned_meta_keys();

		$test_cases = array(
			array(
				'test_condition_name' => '基本料金メタ（_vkbm_base_price）が含まれる',
				'conditions'          => '_vkbm_base_price',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'スタッフIDメタ（_vkbm_staff_ids）が含まれる',
				'conditions'          => '_vkbm_staff_ids',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '廃止済みメタ（_vkbm_max_guests_per_booking）は含まれない',
				'conditions'          => '_vkbm_max_guests_per_booking',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '互換用の旧メタ（_vkbm_online_available）は含まれない',
				'conditions'          => '_vkbm_online_available',
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame(
				$case['expected'],
				in_array( $case['conditions'], $keys, true ),
				$case['test_condition_name']
			);
		}

		// メタキーに重複がないことを確認する。
		$this->assertSame( count( $keys ), count( array_unique( $keys ) ), 'メタキーに重複がない' );
	}

	/**
	 * リビジョンへのカスタムメタ保存・復元のテスト。
	 *
	 * supports に revisions を追加し wp_post_revision_meta_keys フィルターでメタキーを登録したことで、
	 * 投稿更新時にカスタムメタがリビジョンへ保存され、リビジョン復元時に投稿へ書き戻されることを確認する。
	 */
	public function test_revisioned_meta_keys_are_saved_and_restored(): void {
		// サービスメニュー投稿を作成する。
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_title'  => 'Cut',
				'post_status' => 'publish',
			)
		);

		// バージョン1: 基本料金 1000 を保存してから更新し、リビジョンに 1000 を残す。
		update_post_meta( $post_id, '_vkbm_base_price', 1000 );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'version 1',
			)
		);

		// バージョン2: 基本料金 2000 に変更してから更新し、リビジョンに 2000 を残す。
		update_post_meta( $post_id, '_vkbm_base_price', 2000 );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'version 2',
			)
		);

		// 現在の投稿の基本料金が 2000 であることを確認する。
		$this->assertSame( 2000, (int) get_post_meta( $post_id, '_vkbm_base_price', true ), '更新後の現在値は 2000' );

		// 最古のリビジョン（バージョン1）を取得する。
		$revisions = wp_get_post_revisions( $post_id, array( 'order' => 'ASC' ) );
		$this->assertNotEmpty( $revisions, 'リビジョンが作成されている' );
		$oldest_revision = array_shift( $revisions );

		// 最古のリビジョンに基本料金 1000 が保存されていることを確認する。
		$this->assertSame(
			1000,
			(int) get_post_meta( $oldest_revision->ID, '_vkbm_base_price', true ),
			'最古のリビジョンには 1000 が保存されている'
		);

		// 最古のリビジョンを復元すると、投稿の基本料金が 1000 に戻ることを確認する。
		wp_restore_post_revision( $oldest_revision->ID );
		$this->assertSame(
			1000,
			(int) get_post_meta( $post_id, '_vkbm_base_price', true ),
			'リビジョン復元後は基本料金が 1000 に戻る'
		);
	}
}
