<?php
/**
 * Registers the Resource custom post type.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\PostTypes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\Capabilities\Capabilities;
use function vkbm_get_resource_label_plural;
use function vkbm_get_resource_label_singular;
use function vkbm_get_resource_menu_icon;

/**
 * Registers the Resource (スタッフ) custom post type.
 */
class Resource_Post_Type {
	public const POST_TYPE = 'vkbm_resource';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
	}

	/**
	 * Register the resource post type.
	 */
	public function register_post_type(): void {
		if ( post_type_exists( self::POST_TYPE ) ) {
			return;
		}

		$singular = vkbm_get_resource_label_singular();
		$plural   = vkbm_get_resource_label_plural();

		$labels = array(
			'name'                  => $plural,
			'singular_name'         => $singular,
			'menu_name'             => sprintf( 'BM %s', $plural ),
			'name_admin_bar'        => $singular,
			'add_new'               => __( 'New addition', 'vk-booking-manager' ),
			/* translators: %s: Resource label (singular). */
			'add_new_item'          => sprintf( __( 'Add %s', 'vk-booking-manager' ), $singular ),
			/* translators: %s: Resource label (singular). */
			'edit_item'             => sprintf( __( 'Edit %s', 'vk-booking-manager' ), $singular ),
			/* translators: %s: Resource label (singular). */
			'new_item'              => sprintf( __( 'New %s', 'vk-booking-manager' ), $singular ),
			/* translators: %s: Resource label (singular). */
			'view_item'             => sprintf( __( 'Show %s', 'vk-booking-manager' ), $singular ),
			/* translators: %s: Resource label (singular). */
			'search_items'          => sprintf( __( 'Search for %s', 'vk-booking-manager' ), $singular ),
			/* translators: %s: Resource label (singular). */
			'not_found'             => sprintf( __( '%s not found.', 'vk-booking-manager' ), $singular ),
			/* translators: %s: Resource label (singular). */
			'not_found_in_trash'    => sprintf( __( 'There is no %s in the trash.', 'vk-booking-manager' ), $singular ),
			/* translators: %s: Resource label (plural). */
			'all_items'             => sprintf( __( 'All %s', 'vk-booking-manager' ), $plural ),
			/* translators: %s: Resource label (plural). */
			'archives'              => sprintf( __( '%s Archive', 'vk-booking-manager' ), $plural ),
			/* translators: %s: Resource label (singular). */
			'attributes'            => sprintf( __( '%s attribute', 'vk-booking-manager' ), $singular ),
			/* translators: %s: Resource label (singular). */
			'insert_into_item'      => sprintf( __( 'Insert into %s', 'vk-booking-manager' ), $singular ),
			/* translators: %s: Resource label (singular). */
			'uploaded_to_this_item' => sprintf( __( 'Upload to this %s', 'vk-booking-manager' ), $singular ),
		);

		$args = array(
			'labels'            => $labels,
			'public'            => false,
			'show_ui'           => true,
			'show_in_menu'      => true,
			'show_in_admin_bar' => false,
			'show_in_nav_menus' => false,
			'show_in_rest'      => true,
			'supports'          => array( 'title' ),
			'has_archive'       => false,
			'hierarchical'      => false,
			'rewrite'           => false,
			'menu_position'     => 26,
			'menu_icon'         => vkbm_get_resource_menu_icon(),
			'capability_type'   => 'post',
			'capabilities'      => $this->get_capabilities(),
			'map_meta_cap'      => false,
		);

		$config = $this->get_config();
		if ( array() !== $config ) {
			$args = array_merge( $args, $config );
		}

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Load build-specific configuration overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function get_config(): array {
		$config_path = __DIR__ . '/resource-post-type-config.php';
		if ( ! file_exists( $config_path ) ) {
			return array();
		}

		$config = require $config_path;
		return is_array( $config ) ? $config : array();
	}

	/**
	 * Capabilities for the resource post type.
	 *
	 * @return array<string, string>
	 */
	private function get_capabilities(): array {
		return array(
			'edit_post'              => Capabilities::MANAGE_STAFF,
			'read_post'              => Capabilities::MANAGE_STAFF,
			'delete_post'            => Capabilities::MANAGE_STAFF,
			'edit_posts'             => Capabilities::MANAGE_STAFF,
			'edit_others_posts'      => Capabilities::MANAGE_STAFF,
			'publish_posts'          => Capabilities::MANAGE_STAFF,
			'read_private_posts'     => Capabilities::MANAGE_STAFF,
			'delete_posts'           => Capabilities::MANAGE_STAFF,
			'delete_private_posts'   => Capabilities::MANAGE_STAFF,
			'delete_published_posts' => Capabilities::MANAGE_STAFF,
			'delete_others_posts'    => Capabilities::MANAGE_STAFF,
			'edit_private_posts'     => Capabilities::MANAGE_STAFF,
			'edit_published_posts'   => Capabilities::MANAGE_STAFF,
			'create_posts'           => Capabilities::MANAGE_STAFF,
		);
	}

	/**
	 * Resolve the Free edition's single default staff (resource) ID.
	 *
	 * Free版では担当スタッフを指名する概念自体が無く、常に「基本スタッフ」1名だけを使う
	 * 設計になっている（Plugin::maybe_create_default_staff() が公開状態の resource を常に
	 * 1件に保つ）。この読み取り側の解決ロジックは元々 Provider_Settings_Controller に
	 * post_title 一致（`__( 'Default Staff', ... )`）で重複実装されていたが、翻訳言語の
	 * 切り替えやスタッフ名の変更で post_title 一致が壊れるため（issue #465）、ここへ集約した。
	 *
	 * 「どの投稿を基本スタッフとみなすか」の判定アルゴリズム自体は
	 * self::resolve_default_staff_from_published_ids() に切り出してあり、書き込み側の
	 * Plugin::maybe_create_default_staff()（公開状態を1件に保つ・#465でこちらも同じ判定へ揃えた）
	 * とも共有している。読み取り・書き込みで判定基準がずれることを防ぐため。
	 *
	 * @return int Default staff (resource) post ID、解決できない場合は 0。
	 */
	public static function get_default_staff_id(): int {
		if ( ! post_type_exists( self::POST_TYPE ) ) {
			return 0;
		}

		$published_staff = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		return self::resolve_default_staff_from_published_ids( $published_staff );
	}

	/**
	 * 公開中の resource ID 一覧から、基本スタッフとみなす1件を判定する（issue #465）。
	 *
	 * 「公開中の resource がちょうど1件ならそれを使う」を優先する。翻訳言語の切り替えや
	 * スタッフ名の変更に影響されないため。0件または複数件（初回起動直後・Pro版からの
	 * 切り替え直後などで、まだ公開状態の整理が済んでいない一時的な状態）のときだけ、
	 * 後方互換として従来の post_title 一致にフォールバックする。
	 *
	 * 呼び出し元は既に公開中の resource ID 一覧を持っている（get_default_staff_id() は
	 * 読み取りのためだけに、Plugin::maybe_create_default_staff() は「公開状態を1件に保つ」
	 * ために別の目的でも同じ一覧を必要とする）ため、ここでは自前でクエリを発行しない。
	 *
	 * @param array<int> $published_ids 公開中（post_status = publish）の resource 投稿ID一覧。
	 * @return int 基本スタッフとみなす投稿ID。判定できない場合は 0。
	 */
	public static function resolve_default_staff_from_published_ids( array $published_ids ): int {
		// 公開中の resource がちょうど1件なら、それを基本スタッフとして扱う（最も頑健）。
		if ( 1 === count( $published_ids ) ) {
			return (int) reset( $published_ids );
		}

		// 0件または複数件の場合は、後方互換のため従来の post_title 一致にフォールバックする。
		$title = __( 'Default Staff', 'vk-booking-manager' );
		foreach ( $published_ids as $staff_id ) {
			$post = get_post( $staff_id );
			if ( $post && $title === $post->post_title ) {
				return (int) $post->ID;
			}
		}

		return 0;
	}
}
