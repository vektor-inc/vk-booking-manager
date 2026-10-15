<?php
/**
 * 顧客フラグの種別（ラベル・ブロック可否・メッセージ）を定義する管理ページ。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Customers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\Assets\Common_Styles;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Common\Nomination_Min_Guests_Message;
use function __;
use function add_action;
use function add_query_arg;
use function add_submenu_page;
use function admin_url;
use function check_admin_referer;
use function checked;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_textarea;
use function esc_url;
use function is_array;
use function submit_button;
use function wp_add_inline_script;
use function wp_die;
use function wp_enqueue_script;
use function wp_enqueue_style;
use function wp_register_script;
use function wp_nonce_field;
use function wp_safe_redirect;
use function wp_unslash;

/**
 * 店舗が自由にフラグ種別を定義するための設定ページ。
 *
 * 各種別はラベル（要注意 / VIP など）、予約ブロックの有無、ブロック時メッセージを持つ。
 * 種別は各行の「削除」にチェックを入れて保存すると削除される。
 */
class Customer_Flag_Settings_Page {
	/**
	 * 親メニューのスラッグ（プロバイダー設定メニュー配下に表示する）。
	 */
	private const PARENT_SLUG = 'vkbm-provider-settings';

	/**
	 * このページのスラッグ。
	 */
	public const MENU_SLUG = 'vkbm-customer-flags';

	/**
	 * nonce アクション名。
	 */
	private const NONCE_ACTION = 'vkbm_customer_flag_types_save';

	/**
	 * nonce フィールド名。
	 */
	private const NONCE_NAME = '_vkbm_customer_flag_types_nonce';

	/**
	 * 新規入力用に表示する空行の数。
	 */
	private const EMPTY_ROWS = 3;

	/**
	 * 顧客フラグサービス。
	 *
	 * @var Customer_Flag_Service
	 */
	private $service;

	/**
	 * 必要な権限。
	 *
	 * @var string
	 */
	private $capability;

	/**
	 * add_submenu_page() が返す、このページのフック名。
	 *
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * コンストラクタ。
	 *
	 * @param Customer_Flag_Service|null $service    顧客フラグサービス。未指定なら既定実装を生成する。
	 * @param string                     $capability 必要な権限。
	 */
	public function __construct( ?Customer_Flag_Service $service = null, string $capability = Capabilities::MANAGE_PROVIDER_SETTINGS ) {
		$this->service    = $service ?? new Customer_Flag_Service();
		$this->capability = $capability;
	}

	/**
	 * フックを登録する。
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 11 );
		add_action( 'admin_init', array( $this, 'handle_submission' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * メニューを登録する。
	 */
	public function register_menu(): void {
		$this->page_hook = (string) add_submenu_page(
			self::PARENT_SLUG,
			__( 'Customer flags', 'vk-booking-manager' ),
			__( 'Customer flags', 'vk-booking-manager' ),
			$this->capability,
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * このページで使う CSS とスクリプトを読み込む。
	 *
	 * 「ブロック」のチェックに合わせて、ブロック時メッセージ欄の既定文言（プレースホルダー）を出し分ける。
	 * ブロックしない種別の行に既定文言が出ていると、その種別でもメッセージが表示されるように見えるため。
	 *
	 * @param string $hook_suffix 表示中の管理画面のフック名。
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( '' === $this->page_hook || $hook_suffix !== $this->page_hook ) {
			return;
		}

		wp_enqueue_style( Common_Styles::ADMIN_HANDLE );

		wp_register_script( 'vkbm-customer-flags', false, array(), VKBM_VERSION, true );
		wp_enqueue_script( 'vkbm-customer-flags' );

		$placeholder_toggle = "(function () {
			var rows = document.querySelectorAll('.vkbm-customer-flag-row');
			rows.forEach(function (row) {
				var checkbox = row.querySelector('.vkbm-customer-flag-block');
				var message = row.querySelector('.vkbm-customer-flag-message');
				if (!checkbox || !message) {
					return;
				}
				var toggle = function () {
					message.placeholder = checkbox.checked ? message.getAttribute('data-default-message') : '';
				};
				checkbox.addEventListener('change', toggle);
				toggle();
			});
		})();";
		wp_add_inline_script( 'vkbm-customer-flags', $placeholder_toggle );
	}

	/**
	 * フォーム送信を処理する。
	 */
	public function handle_submission(): void {
		// 対象ページへの保存リクエスト以外は処理しない。
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}

		if ( ! current_user_can( $this->capability ) ) {
			return;
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		// 入力された種別定義をサニタイズして保存する（空ラベル行はサービス側で除外される）。
		// 配列の各要素は Customer_Flag_Service::sanitize_types() で個別にサニタイズするため、ここでは unslash のみ行う。
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 各要素は sanitize_types() でサニタイズする。
		$raw_types = isset( $_POST['vkbm_flag_types'] ) && is_array( $_POST['vkbm_flag_types'] ) ? wp_unslash( $_POST['vkbm_flag_types'] ) : array();

		// 保存結果に応じて、成功/失敗を示すクエリを付けてリダイレクトする。
		$saved = $this->service->save_types( $raw_types );

		$query_args = array( 'page' => self::MENU_SLUG );
		if ( $saved ) {
			$query_args['updated'] = '1';
		} else {
			$query_args['error'] = '1';
		}

		$redirect_url = add_query_arg( $query_args, admin_url( 'admin.php' ) );

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * ページを描画する。
	 */
	public function render_page(): void {
		if ( ! current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'vk-booking-manager' ) );
		}

		$types = $this->service->get_types();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 表示専用のフラグ。
		$updated = isset( $_GET['updated'] ) && '1' === $_GET['updated'];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 表示専用のフラグ。
		$has_error = isset( $_GET['error'] ) && '1' === $_GET['error'];
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Customer flags', 'vk-booking-manager' ); ?></h1>

			<?php if ( $updated ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html__( 'Customer flag types saved.', 'vk-booking-manager' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $has_error ) : ?>
				<div class="notice notice-error is-dismissible">
					<p>
						<?php
						// 1 つの翻訳関数に 1 文ずつ入れ、言語に応じた区切りで結合する。
						echo esc_html(
							Nomination_Min_Guests_Message::join_sentences(
								__( 'Failed to save customer flag types.', 'vk-booking-manager' ),
								__( 'Please try again.', 'vk-booking-manager' )
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<p class="description">
				<?php echo esc_html__( 'Define flag labels freely (e.g. Caution, VIP).', 'vk-booking-manager' ); ?>
			</p>
			<p class="description">
				<?php
				echo esc_html(
					Nomination_Min_Guests_Message::join_sentences(
						__( 'To delete a flag type, check "Delete" and save.', 'vk-booking-manager' ),
						__( 'Flags assigned to customers with that type are also removed, including booking blocks.', 'vk-booking-manager' )
					)
				);
				?>
			</p>

			<form method="post" action="">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th scope="col" class="vkbm-customer-flag__col-label"><?php echo esc_html__( 'Label', 'vk-booking-manager' ); ?></th>
							<th scope="col" class="vkbm-customer-flag__col-block"><?php echo esc_html__( 'Block new bookings', 'vk-booking-manager' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Message shown when blocked', 'vk-booking-manager' ); ?></th>
							<th scope="col" class="vkbm-customer-flag__col-delete"><?php echo esc_html__( 'Delete', 'vk-booking-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$row_index = 0;

						// 既存の種別行を描画する。
						foreach ( $types as $type ) {
							$this->render_row( $row_index, (string) $type['id'], (string) $type['label'], ! empty( $type['blocks_booking'] ), (string) $type['block_message'] );
							++$row_index;
						}

						// 新規入力用の空行を描画する。
						for ( $i = 0; $i < self::EMPTY_ROWS; $i++ ) {
							$this->render_row( $row_index, '', '', false, '' );
							++$row_index;
						}
						?>
					</tbody>
				</table>
				<?php submit_button( __( 'Save', 'vk-booking-manager' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * 種別 1 行分の入力欄を描画する。
	 *
	 * @param int    $index          行インデックス（フィールド名の添字）。
	 * @param string $id             既存種別の ID（新規行は空文字）。
	 * @param string $label          ラベル。
	 * @param bool   $blocks_booking 予約ブロック対象か。
	 * @param string $block_message  ブロック時メッセージ。
	 */
	private function render_row( int $index, string $id, string $label, bool $blocks_booking, string $block_message ): void {
		$base            = 'vkbm_flag_types[' . $index . ']';
		$row_number      = $index + 1;
		$default_message = $this->service->get_default_block_message();

		// スクリーンリーダーで何行目のどの欄かを読み上げられるよう、各入力欄に行番号付きの名前を付ける。
		/* translators: %d: 種別の行番号。 */
		$label_aria = sprintf( __( 'Label of flag type %d', 'vk-booking-manager' ), $row_number );
		/* translators: %d: 種別の行番号。 */
		$block_aria = sprintf( __( 'Block new bookings for flag type %d', 'vk-booking-manager' ), $row_number );
		/* translators: %d: 種別の行番号。 */
		$message_aria = sprintf( __( 'Message shown when flag type %d is blocked', 'vk-booking-manager' ), $row_number );
		/* translators: %d: 種別の行番号。 */
		$delete_aria = sprintf( __( 'Delete flag type %d', 'vk-booking-manager' ), $row_number );
		?>
		<tr class="vkbm-customer-flag-row">
			<td>
				<input type="hidden" name="<?php echo esc_attr( $base . '[id]' ); ?>" value="<?php echo esc_attr( $id ); ?>" />
				<input type="text" class="regular-text vkbm-customer-flag__field" name="<?php echo esc_attr( $base . '[label]' ); ?>" value="<?php echo esc_attr( $label ); ?>" aria-label="<?php echo esc_attr( $label_aria ); ?>" />
			</td>
			<td>
				<label>
					<input type="checkbox" class="vkbm-customer-flag-block" name="<?php echo esc_attr( $base . '[blocks_booking]' ); ?>" value="1" <?php checked( $blocks_booking ); ?> aria-label="<?php echo esc_attr( $block_aria ); ?>" />
					<?php echo esc_html__( 'Block', 'vk-booking-manager' ); ?>
				</label>
			</td>
			<td>
				<?php // 空欄のときに表示される既定の文言を、ブロックする行だけプレースホルダーとして示す（切り替えは enqueue_assets() のスクリプト）。 ?>
				<textarea class="vkbm-customer-flag-message vkbm-customer-flag__field" name="<?php echo esc_attr( $base . '[block_message]' ); ?>" rows="2" placeholder="<?php echo esc_attr( $blocks_booking ? $default_message : '' ); ?>" data-default-message="<?php echo esc_attr( $default_message ); ?>" aria-label="<?php echo esc_attr( $message_aria ); ?>"><?php echo esc_textarea( $block_message ); ?></textarea>
			</td>
			<td>
				<?php // 削除は保存済みの種別のみ対象。新規入力用の空行には削除欄を出さない。 ?>
				<?php if ( '' !== $id ) : ?>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $base . '[delete]' ); ?>" value="1" aria-label="<?php echo esc_attr( $delete_aria ); ?>" />
						<?php echo esc_html__( 'Delete', 'vk-booking-manager' ); ?>
					</label>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * 顧客フラグの設定ページへのリンクを描画する。
	 *
	 * 予約編集画面のメタボックスとユーザー編集画面の両方から使う。
	 * 設定ページの権限が無いユーザーには、必要な場合だけ管理者へ依頼する旨の案内を表示する。
	 *
	 * @param string $link_text           リンクの文言。
	 * @param bool   $show_request_notice 権限が無いユーザーに、管理者へ依頼する案内を表示するか（種別が未登録のときのみ true）。
	 * @return void
	 */
	public static function render_settings_link( string $link_text, bool $show_request_notice ): void {
		if ( current_user_can( Capabilities::MANAGE_PROVIDER_SETTINGS ) ) {
			$url = admin_url( 'admin.php?page=' . self::MENU_SLUG );
			echo '<p><a href="' . esc_url( $url ) . '">' . esc_html( $link_text ) . '</a></p>';
			return;
		}

		if ( ! $show_request_notice ) {
			return;
		}

		echo '<p class="description">' . esc_html__( 'Ask an administrator to set up customer flag types.', 'vk-booking-manager' ) . '</p>';
	}
}
