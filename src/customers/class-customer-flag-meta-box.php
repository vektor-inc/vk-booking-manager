<?php
/**
 * 予約編集画面に顧客フラグの割り当て UI と警告表示を追加するメタボックス。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Customers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\PostTypes\Booking_Post_Type;
use WP_Post;
use function __;
use function add_action;
use function add_meta_box;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_textarea;
use function absint;
use function get_current_user_id;
use function get_post_field;
use function is_array;
use function sanitize_key;
use function sanitize_text_field;
use function sanitize_textarea_field;
use function selected;
use function wp_nonce_field;
use function wp_unslash;
use function wp_verify_nonce;

/**
 * 予約編集画面のサイドに顧客フラグのメタボックスを表示し、
 * フラグの割り当て・解除と、ブロック種別フラグの警告表示を行う。
 *
 * フラグは顧客（予約者ユーザー）単位で管理されるため、保存先は予約投稿のメタではなく
 * 予約者ユーザーの user_meta である点に注意。
 */
class Customer_Flag_Meta_Box {
	/**
	 * メタボックスのHTML要素 ID。
	 */
	private const META_BOX_ID = 'vkbm-customer-flag';

	/**
	 * nonce アクション名。
	 */
	private const NONCE_ACTION = 'vkbm_customer_flag_meta';

	/**
	 * nonce フィールド名。
	 */
	private const NONCE_NAME = '_vkbm_customer_flag_nonce';

	/**
	 * 顧客フラグサービス。
	 *
	 * @var Customer_Flag_Service
	 */
	private $service;

	/**
	 * コンストラクタ。
	 *
	 * @param Customer_Flag_Service|null $service 顧客フラグサービス。未指定なら既定実装を生成する。
	 */
	public function __construct( ?Customer_Flag_Service $service = null ) {
		$this->service = $service ?? new Customer_Flag_Service();
	}

	/**
	 * フックを登録する。
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		// 顧客メールは Booking_Admin が優先度 10 で保存するため、確実にその後で保存する。
		add_action( 'save_post_' . Booking_Post_Type::POST_TYPE, array( $this, 'save' ), 20, 1 );
	}

	/**
	 * メタボックスを登録する。
	 */
	public function add_meta_box(): void {
		// フラグの登録・参照は管理者・オーナーのみに限定する。
		if ( ! current_user_can( Capabilities::MANAGE_RESERVATIONS ) ) {
			return;
		}

		add_meta_box(
			self::META_BOX_ID,
			__( 'Customer flag', 'vk-booking-manager' ),
			array( $this, 'render' ),
			Booking_Post_Type::POST_TYPE,
			'side',
			'high'
		);
	}

	/**
	 * メタボックスの内容を描画する。
	 *
	 * @param WP_Post $post 予約投稿。
	 */
	public function render( WP_Post $post ): void {
		// 権限の二重チェック（メタボックス登録時に加え描画時にも確認する）。
		if ( ! current_user_can( Capabilities::MANAGE_RESERVATIONS ) ) {
			return;
		}

		$user_id = (int) $post->post_author;

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		// 予約者が顧客でない予約（代理予約で会員と紐付かず、代理入力した管理者が予約者になっている等）では、
		// フラグが管理者自身に付いてしまうため設定させない。
		if ( ! $this->service->can_have_flag( $user_id ) ) {
			echo '<p>' . esc_html__( 'Customer flags cannot be set because this reservation is not linked to a member.', 'vk-booking-manager' ) . '</p>';
			return;
		}

		// 描画時点の予約者を保持しておき、保存時に予約者が変更されていた場合に
		// 別顧客へフラグを誤って書き込むのを防ぐ（保存処理側で照合する）。
		echo '<input type="hidden" name="vkbm_customer_flag_user_id" value="' . esc_attr( (string) $user_id ) . '" />';

		$types        = $this->service->get_types();
		$current_flag = $this->service->get_flag( $user_id );
		$current_id   = null !== $current_flag ? (string) $current_flag['type_id'] : '';
		$current_memo = null !== $current_flag ? (string) $current_flag['memo'] : '';

		// 種別が一つも定義されていない場合は、設定ページへの導線を案内する。
		if ( empty( $types ) ) {
			echo '<p>' . esc_html__( 'No customer flag types are defined yet.', 'vk-booking-manager' ) . '</p>';
			Customer_Flag_Settings_Page::render_settings_link( __( 'Define customer flag types', 'vk-booking-manager' ), true );
			return;
		}

		// 付与済みのフラグのラベルを表示する。ブロック種別は赤の警告で目立たせ、
		// それ以外（VIP など好意的な種別もある）は警告色にしない。
		if ( null !== $current_flag && ! empty( $current_flag['blocks_booking'] ) ) {
			echo '<div class="notice notice-error inline vkbm-customer-flag__notice">';
			echo '<p><strong>' . esc_html( $current_flag['label'] ) . '</strong><br />';
			echo esc_html__( 'This customer is set to block new bookings.', 'vk-booking-manager' ) . '</p>';
			echo '</div>';
		} elseif ( null !== $current_flag ) {
			echo '<div class="notice notice-info inline vkbm-customer-flag__notice">';
			echo '<p><strong>' . esc_html( $current_flag['label'] ) . '</strong></p>';
			echo '</div>';
		}

		// フラグ種別の選択ドロップダウン。先頭は「なし（解除）」。
		echo '<p><label for="vkbm-customer-flag-type"><strong>' . esc_html__( 'Flag', 'vk-booking-manager' ) . '</strong></label><br />';
		echo '<select id="vkbm-customer-flag-type" name="vkbm_customer_flag_type" class="vkbm-customer-flag__field">';
		echo '<option value="">' . esc_html__( 'None', 'vk-booking-manager' ) . '</option>';
		foreach ( $types as $type ) {
			$type_id = (string) $type['id'];
			echo '<option value="' . esc_attr( $type_id ) . '" ' . selected( $current_id, $type_id, false ) . '>' . esc_html( (string) $type['label'] ) . '</option>';
		}
		echo '</select></p>';

		// メモ（理由）。
		echo '<p><label for="vkbm-customer-flag-memo"><strong>' . esc_html__( 'Memo (reason)', 'vk-booking-manager' ) . '</strong></label><br />';
		echo '<textarea id="vkbm-customer-flag-memo" name="vkbm_customer_flag_memo" rows="3" class="vkbm-customer-flag__field">' . esc_textarea( $current_memo ) . '</textarea></p>';

		echo '<p class="description">' . esc_html__( 'Flags are saved to the reserver and shared across all of their reservations.', 'vk-booking-manager' ) . '</p>';
		Customer_Flag_Settings_Page::render_settings_link( __( 'Edit customer flag types', 'vk-booking-manager' ), false );
	}

	/**
	 * メタボックスの入力値を保存する。
	 *
	 * @param int $post_id 予約投稿 ID。
	 */
	public function save( int $post_id ): void {
		// 自作の nonce フィールドが送信されたリクエストのみ処理する（REST やクイック編集を除外）。
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		// フラグの登録は管理者・オーナーのみに限定する。
		if ( ! current_user_can( Capabilities::MANAGE_RESERVATIONS ) ) {
			return;
		}

		// 予約者は Booking_Admin が優先度 10 で更新済みのため、更新後の投稿者を取得する。
		$user_id = (int) get_post_field( 'post_author', $post_id );

		// 描画時と保存時で予約者が異なる場合は、管理者が見ていた顧客とは別の顧客へ
		// フラグを書き込んでしまうため、フラグ更新をスキップする。
		$rendered_user_id = isset( $_POST['vkbm_customer_flag_user_id'] ) ? absint( wp_unslash( $_POST['vkbm_customer_flag_user_id'] ) ) : 0;
		if ( $rendered_user_id <= 0 || $rendered_user_id !== $user_id ) {
			return;
		}

		// 予約者を変えると、Booking_Admin は wp_update_post() で投稿者を書き換え、その中で再度この保存処理が呼ばれる。
		// 書き換え前に呼ばれた場合でも別顧客へ書き込まないよう、送信された予約者が描画時と異なる保存はスキップする。
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 下で author_id のみ取り出して数値化する。
		$booking_fields = isset( $_POST['vkbm_booking'] ) && is_array( $_POST['vkbm_booking'] ) ? wp_unslash( $_POST['vkbm_booking'] ) : array();
		if ( isset( $booking_fields['author_id'] ) ) {
			$submitted_user_id = absint( $booking_fields['author_id'] );
			if ( $submitted_user_id > 0 && $submitted_user_id !== $rendered_user_id ) {
				return;
			}
		}

		// フラグ選択欄が描画されていない場合（種別が未定義で select を出力していない等）は、
		// vkbm_customer_flag_type が送信されない。これを「なし（解除）」と誤解釈して既存の
		// 割り当てを消さないよう、選択欄が送信されていないリクエストでは何もしない。
		if ( ! isset( $_POST['vkbm_customer_flag_type'] ) ) {
			return;
		}

		$type_id = sanitize_key( wp_unslash( $_POST['vkbm_customer_flag_type'] ) );
		$memo    = isset( $_POST['vkbm_customer_flag_memo'] ) ? sanitize_textarea_field( wp_unslash( $_POST['vkbm_customer_flag_memo'] ) ) : '';

		// 種別 ID が空文字なら割り当て解除、それ以外は割り当て（サービス側で検証）。
		$this->service->set_flag( $user_id, $type_id, $memo, get_current_user_id() );
	}
}
