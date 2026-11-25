<?php
/**
 * サービスメニューとリソースの投稿複製。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Admin;

use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use WP_Error;
use WP_Post;

/**
 * 投稿一覧の複製操作と下書き作成を扱う。
 */
class Post_Duplicator {
	/**
	 * 投稿一覧と管理画面アクションを登録する。
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'post_row_actions', array( $this, 'add_row_action' ), 10, 2 );
		add_action( 'admin_action_vkbm_duplicate_post', array( $this, 'handle_action' ) );
		add_action( 'admin_notices', array( $this, 'render_duplicate_notice' ) );
	}

	/**
	 * 編集可能な対象投稿へ複製リンクを追加する。
	 *
	 * @param array<string,string> $actions 既存の行アクション。
	 * @param WP_Post              $post    一覧の投稿。
	 * @return array<string,string> 複製リンクを追加した行アクション。
	 */
	public function add_row_action( array $actions, WP_Post $post ): array {
		if ( ! $this->can_duplicate( $post ) ) {
			return $actions;
		}

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'vkbm_duplicate_post',
					'post'   => $post->ID,
				),
				admin_url( 'admin.php' )
			),
			'vkbm_duplicate_post_' . $post->ID
		);
		/* translators: %s: 投稿タイトル。 */
		$label                     = sprintf( __( 'Duplicate “%s”', 'vk-booking-manager' ), _draft_or_post_title( $post ) );
		$actions['vkbm_duplicate'] = '<a href="' . esc_url( $url ) . '" aria-label="' . esc_attr( $label ) . '">' . esc_html__( 'Duplicate', 'vk-booking-manager' ) . '</a>';
		return $actions;
	}

	/**
	 * 複製対象と編集・作成権限を検証する。
	 *
	 * @param WP_Post $post 複製元の投稿。
	 * @return bool 複製可能なら true。
	 */
	public function can_duplicate( WP_Post $post ): bool {
		if ( ! in_array( $post->post_type, array( Service_Menu_Post_Type::POST_TYPE, Resource_Post_Type::POST_TYPE ), true ) ) {
			return false;
		}
		$post_type = get_post_type_object( $post->post_type );
		return $post_type && current_user_can( 'edit_post', $post->ID ) && current_user_can( $post_type->cap->create_posts );
	}

	/**
	 * nonce を検証して下書きを作り、編集画面へ移動する。
	 *
	 * @return void
	 */
	public function handle_action(): void {
		$post_id = isset( $_GET['post'] ) && is_scalar( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
		$nonce   = isset( $_GET['_wpnonce'] ) && is_string( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! $post_id || ! wp_verify_nonce( $nonce, 'vkbm_duplicate_post_' . $post_id ) ) {
			wp_die(
				esc_html__( 'Invalid duplication request.', 'vk-booking-manager' ),
				'',
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}

		$new_id = $this->duplicate_post( $post_id );
		if ( is_wp_error( $new_id ) ) {
			wp_die(
				esc_html( $new_id->get_error_message() ),
				'',
				array(
					'response'  => 'vkbm_duplicate_forbidden' === $new_id->get_error_code() ? 403 : 500,
					'back_link' => true,
				)
			);
		}
		$url = add_query_arg(
			'vkbm_duplicated',
			wp_create_nonce( 'vkbm_duplicate_notice_' . $new_id ),
			get_edit_post_link( $new_id, 'raw' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * 複製先の編集画面（従来の編集画面）に下書き作成結果を表示する。
	 *
	 * ブロックエディタの画面では admin_notices の出力が表示されないため出さない。
	 * サービスメニューのブロックエディタでは Service_Menu_Editor が読み込む
	 * service-menu-editor-notices.js が、同じ文言を core/notices で表示する。
	 *
	 * @return void
	 */
	public function render_duplicate_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base || $screen->is_block_editor() ) {
			return;
		}

		$message = self::get_duplicate_notice_message();
		if ( '' === $message ) {
			return;
		}

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * 複製直後の編集画面なら、下書き作成結果の文言を返す。
	 *
	 * 従来の編集画面（admin_notices）とブロックエディタ（core/notices）の両方が、
	 * この判定（nonce・下書き・対象投稿タイプ・権限）を通ったときだけ通知を出す。
	 *
	 * @return string 通知の文言。表示しない場合は空文字。
	 */
	public static function get_duplicate_notice_message(): string {
		if ( ! isset( $_GET['post'], $_GET['vkbm_duplicated'] ) || ! is_scalar( $_GET['post'] ) || ! is_string( $_GET['vkbm_duplicated'] ) ) {
			return '';
		}

		// 遷移先の投稿と一時的な通知トークンを照合する。
		$post_id = absint( wp_unslash( $_GET['post'] ) );
		$nonce   = sanitize_text_field( wp_unslash( $_GET['vkbm_duplicated'] ) );
		$post    = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'draft' !== $post->post_status || ! in_array( $post->post_type, array( Service_Menu_Post_Type::POST_TYPE, Resource_Post_Type::POST_TYPE ), true ) || ! current_user_can( 'edit_post', $post_id ) || ! wp_verify_nonce( $nonce, 'vkbm_duplicate_notice_' . $post_id ) ) {
			return '';
		}

		return __( 'A copy was created as a draft. Review it before publishing.', 'vk-booking-manager' );
	}

	/**
	 * 投稿本体、保存メタ、分類を新しい下書きへ複製する。
	 *
	 * @param int $post_id 複製元の投稿 ID。
	 * @return int|WP_Error 新しい投稿 ID またはエラー。
	 */
	public function duplicate_post( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || ! $this->can_duplicate( $post ) ) {
			return new WP_Error( 'vkbm_duplicate_forbidden', __( 'You cannot duplicate this post.', 'vk-booking-manager' ) );
		}

		// 複製先は常に現在の利用者が作成する下書きにする。
		$new_id = wp_insert_post(
			array(
				'post_type'      => $post->post_type,
				'post_status'    => 'draft',
				'post_author'    => get_current_user_id(),
				'post_title'     => wp_slash( $post->post_title ),
				'post_content'   => wp_slash( $post->post_content ),
				'post_excerpt'   => wp_slash( $post->post_excerpt ),
				'post_password'  => wp_slash( $post->post_password ),
				'menu_order'     => $post->menu_order,
				'comment_status' => $post->comment_status,
				'ping_status'    => $post->ping_status,
			),
			true
		);
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}
		if ( ! $new_id ) {
			return new WP_Error( 'vkbm_duplicate_insert_failed', __( 'Could not create the draft.', 'vk-booking-manager' ) );
		}

		// 一時的なコアメタを除き、同じキーの複数値も保持する。
		$excluded = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_desired_post_slug', '_vkbm_external_calendar_status' );
		foreach ( get_post_meta( $post_id ) as $key => $values ) {
			if ( in_array( $key, $excluded, true ) || 0 === strncmp( (string) $key, '_wp_trash_meta_', 15 ) ) {
				continue;
			}
			delete_post_meta( $new_id, $key );
			foreach ( $values as $value ) {
				if ( false === add_post_meta( $new_id, $key, wp_slash( maybe_unserialize( $value ) ) ) ) {
					wp_delete_post( $new_id, true );
					return new WP_Error( 'vkbm_duplicate_meta_failed', __( 'Could not copy the post settings.', 'vk-booking-manager' ) );
				}
			}
		}

		// 投稿タイプに結び付いた分類だけを複製する。
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$term_ids = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $term_ids ) ) {
				wp_delete_post( $new_id, true );
				return $term_ids;
			}
			$result = wp_set_object_terms( $new_id, $term_ids, $taxonomy );
			if ( is_wp_error( $result ) ) {
				wp_delete_post( $new_id, true );
				return $result;
			}
		}
		return $new_id;
	}
}
