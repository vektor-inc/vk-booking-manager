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

use VKBookingManager\Bookings\Booking_Anonymizer;
use VKBookingManager\Bookings\Guest_Booking_Admin;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Common\Nomination_Min_Guests_Message;
use VKBookingManager\Common\VKBM_Helper;
use VKBookingManager\PostTypes\Booking_Post_Type;
use WP_Post;
use function __;
use function add_action;
use function add_meta_box;
use function admin_url;
use function count;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_textarea;
use function esc_url;
use function absint;
use function add_filter;
use function add_query_arg;
use function delete_transient;
use function get_transient;
use function set_transient;
use function str_replace;
use function get_current_user_id;
use function get_option;
use function get_post_field;
use function get_post_meta;
use function get_post_type;
use function in_array;
use function is_array;
use function mysql2date;
use function wp_kses_post;
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
	 * ゲスト予約の欄から送信されたことを示す hidden フィールド名。
	 */
	private const FIELD_GUEST = 'vkbm_customer_flag_guest';

	/**
	 * 描画時のゲストのメールアドレス（そろえた値）を持つ hidden フィールド名。
	 */
	private const FIELD_GUEST_EMAIL = 'vkbm_customer_flag_guest_email';

	/**
	 * 描画時のゲストの電話番号（そろえた値）を持つ hidden フィールド名。
	 */
	private const FIELD_GUEST_PHONE = 'vkbm_customer_flag_guest_phone';

	/**
	 * 描画時に選ばれていた種別 ID を持つ hidden フィールド名（種別もメモも変えない保存を見分けるため）。
	 */
	private const FIELD_GUEST_INITIAL_TYPE = 'vkbm_customer_flag_guest_initial_type';

	/**
	 * 描画時のメモを持つ hidden フィールド名（種別もメモも変えない保存を見分けるため）。
	 */
	private const FIELD_GUEST_INITIAL_MEMO = 'vkbm_customer_flag_guest_initial_memo';

	/**
	 * ゲストのフラグの保存失敗を知らせる通知の transient キー接頭辞（末尾に「ユーザー ID_予約 ID」を付ける）。
	 */
	private const NOTICE_TRANSIENT_PREFIX = 'vkbm_customer_flag_guest_notice_';

	/**
	 * 保存後の画面で通知を出すためのクエリ変数名（値は予約 ID）。
	 */
	private const NOTICE_QUERY_VAR = 'vkbm_customer_flag_guest_notice';

	/**
	 * 予約のお客様メールアドレスを保存する post meta のキー（Booking_Admin::META_CUSTOMER_MAIL と同じ値）。
	 */
	private const META_CUSTOMER_EMAIL = '_vkbm_booking_customer_email';

	/**
	 * 予約のお客様電話番号を保存する post meta のキー（Booking_Admin::META_CUSTOMER_TEL と同じ値）。
	 */
	private const META_CUSTOMER_TEL = '_vkbm_booking_customer_tel';

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
	 * 通知用のリダイレクトフィルターを登録済みか。
	 *
	 * @var bool
	 */
	private $notice_redirect_filter_added = false;

	/**
	 * フックを登録する。
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		// 顧客メールは Booking_Admin が優先度 10 で保存するため、確実にその後で保存する。
		add_action( 'save_post_' . Booking_Post_Type::POST_TYPE, array( $this, 'save' ), 20, 1 );
		// ゲストのフラグを保存できなかったときに、保存後の画面で知らせる。
		add_action( 'admin_notices', array( $this, 'render_guest_save_failed_notice' ) );
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
			// ゲスト予約（会員登録なしの予約）は、会員ではなく予約の連絡先にフラグを付ける。
			if ( Guest_Booking_Admin::is_guest_booking( $post->ID ) ) {
				$this->render_guest_flag( $post );
				return;
			}

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

		// ゲスト予約から会員に紐付けた予約で、その連絡先にゲストのフラグも付いている場合は知らせる。
		// 会員の欄で付け外ししても、ゲストのフラグは変わらないため。
		if ( Guest_Booking_Admin::is_guest_booking( $post->ID ) && array() !== $this->service->find_guest_flags( $this->get_booking_meta( $post->ID, self::META_CUSTOMER_EMAIL ), $this->get_booking_meta( $post->ID, self::META_CUSTOMER_TEL ) ) ) {
			echo '<p class="description">' . esc_html__( 'The contact details of this reservation also have a guest flag.', 'vk-booking-manager' ) . '</p>';
			Customer_Flag_Settings_Page::render_guest_list_link( __( 'View flags set for guests', 'vk-booking-manager' ) );
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

		// ゲスト予約の欄から送信された保存は、会員のフラグではなく連絡先のフラグとして保存する。
		if ( isset( $_POST[ self::FIELD_GUEST ] ) ) {
			$this->save_guest_flag( $post_id );
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

	/**
	 * ゲスト予約（会員登録なしの予約）の顧客フラグ欄を描画する。
	 *
	 * フラグは会員ではなく、予約のメールアドレス・電話番号に付ける。
	 *
	 * @param WP_Post $post 予約投稿。
	 */
	private function render_guest_flag( WP_Post $post ): void {
		// 匿名化済みの予約は連絡先が消えているため、この予約からは変更できない。一覧から解除する。
		if ( Booking_Anonymizer::is_anonymized( $post->ID ) ) {
			echo '<p>' . esc_html__( 'This reservation has been anonymized, so its flag cannot be changed here.', 'vk-booking-manager' ) . '</p>';
			Customer_Flag_Settings_Page::render_guest_list_link( __( 'View flags set for guests', 'vk-booking-manager' ) );
			return;
		}

		$raw_email = $this->get_booking_meta( $post->ID, self::META_CUSTOMER_EMAIL );
		$raw_phone = $this->get_booking_meta( $post->ID, self::META_CUSTOMER_TEL );
		$email     = VKBM_Helper::normalize_email( $raw_email );
		$phone     = VKBM_Helper::normalize_phone_number( $raw_phone );

		// 連絡先がひとつも無ければ、誰に付けるか決められないため欄を出さない。
		if ( '' === $email && '' === $phone ) {
			echo '<p>' . esc_html__( 'Customer flags cannot be set because this reservation has no email address or telephone number.', 'vk-booking-manager' ) . '</p>';
			return;
		}

		$types = $this->service->get_types();
		if ( empty( $types ) ) {
			echo '<p>' . esc_html__( 'No customer flag types are defined yet.', 'vk-booking-manager' ) . '</p>';
			Customer_Flag_Settings_Page::render_settings_link( __( 'Define customer flag types', 'vk-booking-manager' ), true );
			return;
		}

		// 描画時の連絡先を保持しておき、保存時に連絡先が書き換わっていた場合に別の相手へフラグを書き込むのを防ぐ。
		echo '<input type="hidden" name="' . esc_attr( self::FIELD_GUEST ) . '" value="1" />';
		echo '<input type="hidden" name="' . esc_attr( self::FIELD_GUEST_EMAIL ) . '" value="' . esc_attr( $email ) . '" />';
		echo '<input type="hidden" name="' . esc_attr( self::FIELD_GUEST_PHONE ) . '" value="' . esc_attr( $phone ) . '" />';

		// フラグを付ける連絡先を、予約の入力のまま見せる。
		echo '<p><strong>' . esc_html__( 'Contact to flag', 'vk-booking-manager' ) . '</strong>';
		if ( '' !== $email ) {
			/* translators: %s: メールアドレス。 */
			echo '<br />' . esc_html( sprintf( __( 'Email address: %s', 'vk-booking-manager' ), $raw_email ) );
		}
		if ( '' !== $phone ) {
			/* translators: %s: 電話番号。 */
			echo '<br />' . esc_html( sprintf( __( 'Telephone number: %s', 'vk-booking-manager' ), $raw_phone ) );
		}
		echo '</p>';

		// 一致する既存のフラグを表示する。
		$matches = $this->service->find_guest_flags( $email, $phone );
		foreach ( $matches as $match ) {
			$this->render_guest_flag_notice( $match, $post->ID );
		}

		// 複数一致のときは、予約をブロックする種別を初期選択にする（保存でブロックが外れないように）。
		$initial      = $this->pick_initial_flag( $matches );
		$current_id   = null !== $initial ? (string) $initial['type_id'] : '';
		$current_memo = null !== $initial ? (string) $initial['memo'] : '';
		if ( count( $matches ) > 1 && null !== $initial ) {
			/* translators: %s: 残る種別名。 */
			echo '<p class="description">' . esc_html( sprintf( __( 'Different flags match the email address and the telephone number separately. If you change the flag or memo and save, they are combined into one flag, and "%s" is selected now.', 'vk-booking-manager' ), (string) $initial['label'] ) ) . '</p>';
		}
		echo '<input type="hidden" name="' . esc_attr( self::FIELD_GUEST_INITIAL_TYPE ) . '" value="' . esc_attr( $current_id ) . '" />';
		echo '<input type="hidden" name="' . esc_attr( self::FIELD_GUEST_INITIAL_MEMO ) . '" value="' . esc_attr( $current_memo ) . '" />';

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

		echo '<p class="description">' . esc_html(
			Nomination_Min_Guests_Message::join_sentences(
				__( 'The flag applies to this email address and telephone number, and also to a member who uses either of them.', 'vk-booking-manager' ),
				__( 'It stays with them even if you change the contact details of this reservation later.', 'vk-booking-manager' )
			)
		) . '</p>';
		echo '<p class="description">' . esc_html__( 'Selecting "None" and saving removes the flag.', 'vk-booking-manager' ) . '</p>';
		Customer_Flag_Settings_Page::render_guest_list_link( __( 'View flags set for guests', 'vk-booking-manager' ) );
		Customer_Flag_Settings_Page::render_settings_link( __( 'Edit customer flag types', 'vk-booking-manager' ), false );
	}

	/**
	 * ゲストに付いたフラグ 1 件分の通知を描画する。
	 *
	 * ブロック種別は赤、それ以外は青で示し、どの連絡先が一致したか・付けた日・別の予約で付けた場合は元の予約へのリンクを添える。
	 *
	 * @param array<string, mixed> $flag            Customer_Flag_Service::find_guest_flags() が返す解決済みフラグ情報。
	 * @param int                  $current_post_id 表示中の予約投稿 ID。
	 */
	private function render_guest_flag_notice( array $flag, int $current_post_id ): void {
		$matched_labels = array();
		if ( in_array( 'email', (array) $flag['matched'], true ) ) {
			$matched_labels[] = __( 'Email address', 'vk-booking-manager' );
		}
		if ( in_array( 'phone', (array) $flag['matched'], true ) ) {
			$matched_labels[] = __( 'Telephone number', 'vk-booking-manager' );
		}

		$class = ! empty( $flag['blocks_booking'] ) ? 'notice-error' : 'notice-info';
		echo '<div class="notice ' . esc_attr( $class ) . ' inline vkbm-customer-flag__notice">';
		echo '<p><strong>' . esc_html( (string) $flag['label'] ) . '</strong>';
		if ( ! empty( $flag['blocks_booking'] ) ) {
			echo '<br />' . esc_html__( 'This customer is set to block new bookings.', 'vk-booking-manager' );
		}
		echo '</p>';

		// 一致した連絡先と、付けた日を並べる。各行はエスケープ済みの HTML。
		$details = array();
		if ( array() !== $matched_labels ) {
			/* translators: %s: 一致した連絡先の項目名（メールアドレス、電話番号）。 */
			$details[] = esc_html( sprintf( __( 'Matched: %s', 'vk-booking-manager' ), implode( ', ', $matched_labels ) ) );
		}
		if ( '' !== (string) $flag['assigned_at'] ) {
			/* translators: %s: フラグを付けた日付。 */
			$details[] = esc_html( sprintf( __( 'Set on: %s', 'vk-booking-manager' ), mysql2date( (string) get_option( 'date_format' ), (string) $flag['assigned_at'] ) ) );
		}

		// 別の予約で付けたフラグは、元の予約へのリンクを添える。
		$source_id = (int) $flag['source_booking_id'];
		if ( $source_id > 0 && $source_id !== $current_post_id && Booking_Post_Type::POST_TYPE === get_post_type( $source_id ) && current_user_can( Capabilities::MANAGE_RESERVATIONS ) ) {
			/* translators: %s: 元の予約へのリンク。 */
			$details[] = sprintf( esc_html__( 'Set from another reservation: %s', 'vk-booking-manager' ), '<a href="' . esc_url( admin_url( 'post.php?post=' . $source_id . '&action=edit' ) ) . '">#' . esc_html( (string) $source_id ) . '</a>' );
		}

		if ( array() !== $details ) {
			echo '<p class="description">' . wp_kses_post( implode( '<br />', $details ) ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * ゲスト予約の欄の入力値を、予約の連絡先に付けるフラグとして保存する。
	 *
	 * @param int $post_id 予約投稿 ID。
	 */
	private function save_guest_flag( int $post_id ): void {
		// nonce と権限は呼び出し元の save() で確認済み。
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- 上記のとおり確認済みのため。
		// ゲスト予約でない、匿名化済み、会員に紐付け済みの予約には、ゲスト用の保存をしない。
		if (
			! Guest_Booking_Admin::is_guest_booking( $post_id )
			|| Booking_Anonymizer::is_anonymized( $post_id )
			|| $this->service->can_have_flag( (int) get_post_field( 'post_author', $post_id ) )
		) {
			return;
		}

		// 描画時と保存時で連絡先が異なる場合は、管理者が見ていた相手とは別の相手へ
		// フラグを書き込んでしまうため、フラグ更新をスキップする。
		$rendered_email = isset( $_POST[ self::FIELD_GUEST_EMAIL ] ) ? VKBM_Helper::normalize_email( sanitize_text_field( wp_unslash( $_POST[ self::FIELD_GUEST_EMAIL ] ) ) ) : '';
		$rendered_phone = isset( $_POST[ self::FIELD_GUEST_PHONE ] ) ? VKBM_Helper::normalize_phone_number( sanitize_text_field( wp_unslash( $_POST[ self::FIELD_GUEST_PHONE ] ) ) ) : '';
		$email          = VKBM_Helper::normalize_email( $this->get_booking_meta( $post_id, self::META_CUSTOMER_EMAIL ) );
		$phone          = VKBM_Helper::normalize_phone_number( $this->get_booking_meta( $post_id, self::META_CUSTOMER_TEL ) );
		if ( ( '' === $email && '' === $phone ) || $rendered_email !== $email || $rendered_phone !== $phone ) {
			return;
		}

		// フラグ選択欄が描画されていない場合は、「なし（解除）」と誤解釈して既存の記録を消さないよう何もしない。
		if ( ! isset( $_POST['vkbm_customer_flag_type'] ) ) {
			return;
		}

		$type_id = sanitize_key( wp_unslash( $_POST['vkbm_customer_flag_type'] ) );
		$memo    = isset( $_POST['vkbm_customer_flag_memo'] ) ? sanitize_textarea_field( wp_unslash( $_POST['vkbm_customer_flag_memo'] ) ) : '';

		// 種別もメモも描画時から変えていない保存では何もしない。
		// 別々のフラグが付いた連絡先で、予約を保存しただけで 1 件にまとめられ、ブロックが外れるのを防ぐため。
		// 複数行のメモは、送信時に改行が CRLF になるため、改行コードをそろえてから比べる。
		if (
			isset( $_POST[ self::FIELD_GUEST_INITIAL_TYPE ], $_POST[ self::FIELD_GUEST_INITIAL_MEMO ] )
			&& sanitize_key( wp_unslash( $_POST[ self::FIELD_GUEST_INITIAL_TYPE ] ) ) === $type_id
			&& self::normalize_newlines( sanitize_textarea_field( wp_unslash( $_POST[ self::FIELD_GUEST_INITIAL_MEMO ] ) ) ) === self::normalize_newlines( $memo )
		) {
			return;
		}

		// 種別 ID が空文字なら解除、それ以外は付与（サービス側で検証）。
		$saved = $this->service->set_guest_flag( $email, $phone, $type_id, $memo, get_current_user_id(), $post_id );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// 排他を取れない等で保存できなかったときは、黙って終えず管理者に知らせる。
		if ( ! $saved ) {
			$this->push_guest_save_failed_notice( $post_id );
		}
	}

	/**
	 * 改行コードを LF にそろえる。
	 *
	 * @param string $text 対象の文字列。
	 * @return string 改行コードを LF にそろえた文字列。
	 */
	private static function normalize_newlines( string $text ): string {
		return str_replace( array( "\r\n", "\r" ), "\n", $text );
	}

	/**
	 * ゲストのフラグを保存できなかった旨を、保存後のリダイレクト先で表示できるよう transient へ積む。
	 *
	 * @param int $post_id 予約投稿 ID。
	 */
	private function push_guest_save_failed_notice( int $post_id ): void {
		set_transient( self::NOTICE_TRANSIENT_PREFIX . get_current_user_id() . '_' . $post_id, '1', 30 );

		// リダイレクト先に通知表示用のクエリを付ける（多重登録は避ける）。
		if ( ! $this->notice_redirect_filter_added ) {
			$this->notice_redirect_filter_added = true;
			add_filter(
				'redirect_post_location',
				static function ( string $location ) use ( $post_id ): string {
					return add_query_arg( self::NOTICE_QUERY_VAR, (string) $post_id, $location );
				}
			);
		}
	}

	/**
	 * ゲストのフラグを保存できなかった旨の通知を、予約の編集画面に表示する。
	 */
	public function render_guest_save_failed_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 表示専用のクエリ。実際の表示可否は本人の transient で決める。
		$post_id = isset( $_GET[ self::NOTICE_QUERY_VAR ] ) ? absint( wp_unslash( $_GET[ self::NOTICE_QUERY_VAR ] ) ) : 0;
		if ( $post_id <= 0 || ! current_user_can( Capabilities::MANAGE_RESERVATIONS ) ) {
			return;
		}

		$key = self::NOTICE_TRANSIENT_PREFIX . get_current_user_id() . '_' . $post_id;
		if ( false === get_transient( $key ) ) {
			return;
		}
		delete_transient( $key );

		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(
			// 1 つの翻訳関数に 1 文ずつ入れ、言語に応じた区切りで結合する。
			Nomination_Min_Guests_Message::join_sentences(
				__( 'The customer flag for the guest could not be saved.', 'vk-booking-manager' ),
				__( 'Please wait a moment and save again.', 'vk-booking-manager' )
			)
		) . '</p></div>';
	}

	/**
	 * 一致したゲストのフラグから、初期選択にするものを選ぶ。予約をブロックする種別を優先する。
	 *
	 * @param array<int, array<string, mixed>> $matches Customer_Flag_Service::find_guest_flags() が返す一致したフラグ。
	 * @return array<string, mixed>|null 初期選択にするフラグ。無ければ null。
	 */
	private function pick_initial_flag( array $matches ): ?array {
		foreach ( $matches as $match ) {
			if ( ! empty( $match['blocks_booking'] ) ) {
				return $match;
			}
		}

		return array() !== $matches ? $matches[0] : null;
	}

	/**
	 * 予約の post meta を文字列で返す。
	 *
	 * @param int    $post_id  予約投稿 ID。
	 * @param string $meta_key post meta のキー。
	 * @return string 保存値。無ければ空文字。
	 */
	private function get_booking_meta( int $post_id, string $meta_key ): string {
		return (string) get_post_meta( $post_id, $meta_key, true );
	}
}
