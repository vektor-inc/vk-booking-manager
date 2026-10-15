<?php
/**
 * ユーザー編集画面に顧客フラグの編集欄を追加する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Customers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\Capabilities\Capabilities;
use WP_User;
use function __;
use function add_action;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_textarea;
use function get_current_user_id;
use function sanitize_key;
use function sanitize_text_field;
use function sanitize_textarea_field;
use function selected;
use function wp_nonce_field;
use function wp_unslash;
use function wp_verify_nonce;

/**
 * ユーザー編集画面（ユーザー > プロフィール編集）に、顧客フラグの種別とメモ（理由）の編集欄を表示・保存する。
 *
 * 予約編集画面のメタボックスと同じ user_meta を読み書きするため、どちらで編集しても同じフラグになる。
 */
class Customer_Flag_User_Profile {
	/**
	 * nonce アクション名。
	 */
	private const NONCE_ACTION = 'vkbm_customer_flag_user_profile';

	/**
	 * nonce フィールド名。
	 */
	private const NONCE_NAME = '_vkbm_customer_flag_user_profile_nonce';

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
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'show_user_profile', array( $this, 'render' ) );
		add_action( 'edit_user_profile', array( $this, 'render' ) );
		add_action( 'personal_options_update', array( $this, 'save' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save' ) );
	}

	/**
	 * 現在のユーザーが、指定ユーザーの顧客フラグを編集できるかを返す。
	 *
	 * 顧客本人に自分のフラグを見せないよう、予約を管理できる管理者・オーナーに限定する。
	 *
	 * @param int $user_id 編集対象のユーザー ID。
	 * @return bool 編集できる場合は true。
	 */
	public function can_edit_flag( int $user_id ): bool {
		return current_user_can( Capabilities::MANAGE_RESERVATIONS )
			&& current_user_can( 'edit_user', $user_id )
			&& $this->service->can_have_flag( $user_id );
	}

	/**
	 * ユーザー編集画面に顧客フラグの編集欄を描画する。
	 *
	 * @param WP_User $user 編集対象のユーザー。
	 * @return void
	 */
	public function render( WP_User $user ): void {
		if ( ! $this->can_edit_flag( $user->ID ) ) {
			return;
		}

		$types        = $this->service->get_types();
		$current_flag = $this->service->get_flag( $user->ID );
		$current_id   = null !== $current_flag ? (string) $current_flag['type_id'] : '';
		$current_memo = null !== $current_flag ? (string) $current_flag['memo'] : '';

		echo '<h2>' . esc_html__( 'Customer flag', 'vk-booking-manager' ) . '</h2>';

		// 種別が一つも定義されていない場合は、設定ページへの導線だけを案内する。
		if ( empty( $types ) ) {
			echo '<p>' . esc_html__( 'No customer flag types are defined yet.', 'vk-booking-manager' ) . '</p>';
			Customer_Flag_Settings_Page::render_settings_link( __( 'Define customer flag types', 'vk-booking-manager' ), true );
			return;
		}

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="vkbm-customer-flag-type"><?php echo esc_html__( 'Flag', 'vk-booking-manager' ); ?></label></th>
				<td>
					<?php
					// ブロック種別が付いている場合は、新規予約をブロックしていることを明示する。
					if ( null !== $current_flag && ! empty( $current_flag['blocks_booking'] ) ) {
						echo '<div class="notice notice-error inline"><p>' . esc_html__( 'This customer is set to block new bookings.', 'vk-booking-manager' ) . '</p></div>';
					}
					?>
					<select id="vkbm-customer-flag-type" name="vkbm_customer_flag_type">
						<option value=""><?php echo esc_html__( 'None', 'vk-booking-manager' ); ?></option>
						<?php foreach ( $types as $type ) : ?>
							<option value="<?php echo esc_attr( (string) $type['id'] ); ?>" <?php selected( $current_id, (string) $type['id'] ); ?>><?php echo esc_html( (string) $type['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="vkbm-customer-flag-memo"><?php echo esc_html__( 'Memo (reason)', 'vk-booking-manager' ); ?></label></th>
				<td>
					<textarea id="vkbm-customer-flag-memo" name="vkbm_customer_flag_memo" rows="3" class="large-text"><?php echo esc_textarea( $current_memo ); ?></textarea>
					<p class="description"><?php echo esc_html__( 'Flags are saved to the reserver and shared across all of their reservations.', 'vk-booking-manager' ); ?></p>
					<?php Customer_Flag_Settings_Page::render_settings_link( __( 'Edit customer flag types', 'vk-booking-manager' ), false ); ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * ユーザー編集画面から送信された顧客フラグを保存する。
	 *
	 * @param int $user_id 編集対象のユーザー ID。
	 * @return void
	 */
	public function save( int $user_id ): void {
		// 自作の nonce フィールドが送信されたリクエストのみ処理する（編集欄を描画していない画面からの保存を除外）。
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! $this->can_edit_flag( $user_id ) ) {
			return;
		}

		// 選択欄が送信されていないリクエストを「なし（解除）」と誤解釈して、既存の割り当てを消さない。
		if ( ! isset( $_POST['vkbm_customer_flag_type'] ) ) {
			return;
		}

		$type_id = sanitize_key( wp_unslash( $_POST['vkbm_customer_flag_type'] ) );
		$memo    = isset( $_POST['vkbm_customer_flag_memo'] ) ? sanitize_textarea_field( wp_unslash( $_POST['vkbm_customer_flag_memo'] ) ) : '';

		// 種別 ID が空文字なら割り当て解除、それ以外は割り当て（サービス側で検証）。
		$this->service->set_flag( $user_id, $type_id, $memo, get_current_user_id() );
	}
}
