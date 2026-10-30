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
use VKBookingManager\PostTypes\Booking_Post_Type;
use function __;
use function absint;
use function add_action;
use function add_query_arg;
use function add_submenu_page;
use function admin_url;
use function check_admin_referer;
use function checked;
use function current_user_can;
use function esc_attr;
use function esc_attr__;
use function esc_html;
use function esc_html__;
use function esc_textarea;
use function esc_url;
use function get_option;
use function get_post_type;
use function get_userdata;
use function is_array;
use function mysql2date;
use function esc_html_x;
use function paginate_links;
use function remove_query_arg;
use function sanitize_key;
use function sanitize_text_field;
use function selected;
use function wp_kses;
use function wp_kses_post;
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
	 * ゲストに付けたフラグの解除用 nonce アクション名。
	 */
	private const GUEST_REMOVE_NONCE_ACTION = 'vkbm_guest_flag_remove';

	/**
	 * ゲストに付けたフラグの解除用 nonce フィールド名。
	 */
	private const GUEST_REMOVE_NONCE_NAME = '_vkbm_guest_flag_remove_nonce';

	/**
	 * ゲストに付けたフラグの一覧で、1 ページに表示する件数。
	 */
	private const GUEST_PER_PAGE = 50;

	/**
	 * ゲストに付けたフラグの一覧の見出し要素 ID（他の画面からのリンク先）。
	 */
	private const GUEST_SECTION_ID = 'vkbm-guest-flags';

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
		add_action( 'admin_init', array( $this, 'handle_guest_flag_removal' ) );
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

		// ゲストに付けたフラグの解除ボタンは、押したあと確認ダイアログで了承された場合だけ送信する。
		$confirm_submit = "(function () {
			document.querySelectorAll('form[data-vkbm-confirm]').forEach(function (form) {
				form.addEventListener('submit', function (event) {
					if (!window.confirm(form.getAttribute('data-vkbm-confirm'))) {
						event.preventDefault();
					}
				});
			});
		})();";
		wp_add_inline_script( 'vkbm-customer-flags', $confirm_submit );
	}

	/**
	 * ゲストに付けたフラグの解除リクエストを処理する。
	 */
	public function handle_guest_flag_removal(): void {
		// 解除ボタンからのリクエスト以外は処理しない。
		if ( ! isset( $_POST[ self::GUEST_REMOVE_NONCE_NAME ] ) ) {
			return;
		}

		if ( ! current_user_can( $this->capability ) ) {
			return;
		}

		check_admin_referer( self::GUEST_REMOVE_NONCE_ACTION, self::GUEST_REMOVE_NONCE_NAME );

		// 解除する記録の ID と、解除後に表示を戻す絞り込み条件を取り出す。
		$record_id = isset( $_POST['vkbm_guest_flag_id'] ) ? sanitize_key( wp_unslash( $_POST['vkbm_guest_flag_id'] ) ) : '';
		$removed   = $this->service->delete_guest_flag_by_id( $record_id );

		$query_args = array( 'page' => self::MENU_SLUG );
		if ( $removed ) {
			$query_args['guest_removed'] = '1';
		} else {
			$query_args['guest_error'] = '1';
		}
		$type_filter = isset( $_POST['guest_type'] ) ? sanitize_key( wp_unslash( $_POST['guest_type'] ) ) : '';
		if ( '' !== $type_filter ) {
			$query_args['guest_type'] = $type_filter;
		}
		$search = isset( $_POST['guest_q'] ) ? sanitize_text_field( wp_unslash( $_POST['guest_q'] ) ) : '';
		if ( '' !== $search ) {
			$query_args['guest_q'] = $search;
		}

		wp_safe_redirect( add_query_arg( $query_args, admin_url( 'admin.php' ) ) . '#' . self::GUEST_SECTION_ID );
		exit;
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
			// 種別は保存できたが、削除した種別のゲストの記録を消せなかった場合は、画面で知らせる。
			if ( $this->service->has_guest_cleanup_failed() ) {
				$query_args['guest_cleanup_error'] = '1';
			}
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 表示専用のフラグ。
		$has_cleanup_error = isset( $_GET['guest_cleanup_error'] ) && '1' === $_GET['guest_cleanup_error'];
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Customer flags', 'vk-booking-manager' ); ?></h1>

			<?php if ( $updated ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html__( 'Customer flag types saved.', 'vk-booking-manager' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $has_cleanup_error ) : ?>
				<div class="notice notice-warning is-dismissible">
					<p>
						<?php
						// 1 つの翻訳関数に 1 文ずつ入れ、言語に応じた区切りで結合する。
						echo esc_html(
							Nomination_Min_Guests_Message::join_sentences(
								__( 'The customer flag types were saved, but some guest flags of the deleted types could not be removed.', 'vk-booking-manager' ),
								__( 'Please wait a moment and save again.', 'vk-booking-manager' )
							)
						);
						?>
					</p>
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
			<p class="description">
				<?php echo esc_html__( 'Customers who book without signing up always see the default message instead of the message below.', 'vk-booking-manager' ); ?>
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

			<?php $this->render_guest_flags_section( $types ); ?>
		</div>
		<?php
	}

	/**
	 * 「ゲストに付けたフラグ」の一覧（種別での絞り込み・メール／電話の検索・解除ボタン付き）を描画する。
	 *
	 * @param array<int, array<string, mixed>> $types フラグ種別定義（絞り込みの選択肢に使う）。
	 */
	private function render_guest_flags_section( array $types ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 表示専用の絞り込み条件。
		$type_filter = isset( $_GET['guest_type'] ) ? sanitize_key( wp_unslash( $_GET['guest_type'] ) ) : '';
		$search      = isset( $_GET['guest_q'] ) ? sanitize_text_field( wp_unslash( $_GET['guest_q'] ) ) : '';
		$paged       = isset( $_GET['guest_paged'] ) ? max( 1, absint( wp_unslash( $_GET['guest_paged'] ) ) ) : 1;
		$removed     = isset( $_GET['guest_removed'] ) && '1' === $_GET['guest_removed'];
		$has_error   = isset( $_GET['guest_error'] ) && '1' === $_GET['guest_error'];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$flags       = $this->service->list_guest_flags( $type_filter, $search );
		$total       = count( $flags );
		$total_pages = max( 1, (int) ceil( $total / self::GUEST_PER_PAGE ) );
		$paged       = min( $paged, $total_pages );
		$page_flags  = array_slice( $flags, ( $paged - 1 ) * self::GUEST_PER_PAGE, self::GUEST_PER_PAGE );
		$is_filtered = '' !== $type_filter || '' !== $search;
		?>
		<h2 id="<?php echo esc_attr( self::GUEST_SECTION_ID ); ?>"><?php echo esc_html__( 'Flags set for guests', 'vk-booking-manager' ); ?></h2>

		<?php if ( $removed ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php echo esc_html__( 'The flag was removed.', 'vk-booking-manager' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( $has_error ) : ?>
			<div class="notice notice-error is-dismissible">
				<p><?php echo esc_html__( 'Failed to remove the flag.', 'vk-booking-manager' ); ?></p>
			</div>
		<?php endif; ?>

		<p class="description">
			<?php
			echo esc_html(
				Nomination_Min_Guests_Message::join_sentences(
					__( 'These are the flags set for customers who book without signing up.', 'vk-booking-manager' ),
					__( 'You can set them on the reservation edit screen.', 'vk-booking-manager' )
				)
			);
			?>
		</p>
		<p class="description">
			<?php
			echo esc_html(
				Nomination_Min_Guests_Message::join_sentences(
					__( 'The flags stay here even after the reservation is anonymized.', 'vk-booking-manager' ),
					__( 'Flags for members are managed on the user edit screen.', 'vk-booking-manager' )
				)
			);
			?>
		</p>

		<form method="get" action="" class="vkbm-customer-flag__guest-filter">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
			<label for="vkbm-guest-flag-type" class="screen-reader-text"><?php echo esc_html__( 'Filter by flag type', 'vk-booking-manager' ); ?></label>
			<select id="vkbm-guest-flag-type" name="guest_type">
				<option value=""><?php echo esc_html__( 'All flag types', 'vk-booking-manager' ); ?></option>
				<?php foreach ( $types as $type ) : ?>
					<option value="<?php echo esc_attr( (string) $type['id'] ); ?>" <?php selected( $type_filter, (string) $type['id'] ); ?>><?php echo esc_html( (string) $type['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<label for="vkbm-guest-flag-search" class="screen-reader-text"><?php echo esc_html__( 'Search by email address or telephone number', 'vk-booking-manager' ); ?></label>
			<input type="search" id="vkbm-guest-flag-search" name="guest_q" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Email address or telephone number', 'vk-booking-manager' ); ?>" />
			<button type="submit" class="button"><?php echo esc_html__( 'Filter', 'vk-booking-manager' ); ?></button>
			<?php if ( $is_filtered ) : ?>
				<a href="<?php echo esc_url( self::get_guest_list_url() ); ?>"><?php echo esc_html__( 'Clear conditions', 'vk-booking-manager' ); ?></a>
			<?php endif; ?>
		</form>

		<div class="vkbm-customer-flag__guest-table-wrap">
		<table class="wp-list-table widefat fixed striped vkbm-customer-flag__guest-table">
			<thead>
				<tr>
					<th scope="col" class="vkbm-customer-flag__col-guest-type"><?php echo esc_html__( 'Flag', 'vk-booking-manager' ); ?></th>
					<th scope="col" class="vkbm-customer-flag__col-guest-contact"><?php echo esc_html__( 'Email address / Telephone number', 'vk-booking-manager' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Memo (reason)', 'vk-booking-manager' ); ?></th>
					<th scope="col" class="vkbm-customer-flag__col-guest-date"><?php echo esc_html__( 'Set on / Set by', 'vk-booking-manager' ); ?></th>
					<th scope="col" class="vkbm-customer-flag__col-guest-source"><?php echo esc_html__( 'Original reservation', 'vk-booking-manager' ); ?></th>
					<th scope="col" class="vkbm-customer-flag__col-guest-action"><?php echo esc_html__( 'Action', 'vk-booking-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $page_flags ) ) : ?>
					<tr>
						<td colspan="6">
							<?php echo esc_html( $is_filtered ? __( 'No flags match the conditions.', 'vk-booking-manager' ) : __( 'No flags have been set for guests yet.', 'vk-booking-manager' ) ); ?>
						</td>
					</tr>
				<?php endif; ?>
				<?php foreach ( $page_flags as $flag ) : ?>
					<?php $this->render_guest_flag_row( $flag, $type_filter, $search ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>

		<?php if ( $total_pages > 1 ) : ?>
			<div class="tablenav">
				<div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						(string) paginate_links(
							array(
								'base'      => add_query_arg( 'guest_paged', '%#%', remove_query_arg( array( 'guest_removed', 'guest_error' ) ) ) . '#' . self::GUEST_SECTION_ID,
								'format'    => '',
								'current'   => $paged,
								'total'     => $total_pages,
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
							)
						)
					);
					?>
				</div>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * ゲストに付けたフラグ 1 件分の行を描画する。
	 *
	 * @param array<string, mixed> $flag        Customer_Flag_Service::list_guest_flags() が返す解決済みフラグ情報。
	 * @param string               $type_filter 表示中の種別での絞り込み（解除後に表示を戻すため）。
	 * @param string               $search      表示中の検索語（解除後に表示を戻すため）。
	 */
	private function render_guest_flag_row( array $flag, string $type_filter, string $search ): void {
		$contact_label = '' !== (string) $flag['email'] ? (string) $flag['email'] : (string) $flag['phone'];
		/* translators: %s: 解除するフラグの連絡先（メールアドレスまたは電話番号）。 */
		$remove_aria = sprintf( __( 'Remove the flag set for %s', 'vk-booking-manager' ), $contact_label );

		// 確認ダイアログには、解除する対象の連絡先を入れる。
		$confirm_message = Nomination_Min_Guests_Message::join_sentences(
			/* translators: %s: 解除するフラグの連絡先（メールアドレスまたは電話番号）。 */
			sprintf( __( 'Remove the flag set for %s?', 'vk-booking-manager' ), $contact_label ),
			__( 'It will no longer be applied to this customer, including booking blocks.', 'vk-booking-manager' )
		);

		// 付けた日時と、付けたユーザー名。
		$assigned_at = '' !== (string) $flag['assigned_at'] ? mysql2date( (string) get_option( 'date_format' ), (string) $flag['assigned_at'] ) : '';
		$assigned_by = (int) $flag['assigned_by'] > 0 ? get_userdata( (int) $flag['assigned_by'] ) : false;

		// 元の予約。消えている・開けない予約はリンクにしない。
		$source_id   = (int) $flag['source_booking_id'];
		$source_url  = '';
		$source_gone = false;
		if ( $source_id > 0 ) {
			if ( Booking_Post_Type::POST_TYPE !== get_post_type( $source_id ) ) {
				$source_gone = true;
			} elseif ( current_user_can( Capabilities::MANAGE_RESERVATIONS ) ) {
				$source_url = admin_url( 'post.php?post=' . $source_id . '&action=edit' );
			}
		}
		?>
		<tr>
			<td>
				<strong><?php echo esc_html( (string) $flag['label'] ); ?></strong>
				<?php if ( ! empty( $flag['blocks_booking'] ) ) : ?>
					<br /><span class="vkbm-customer-flag__guest-blocks"><?php echo esc_html__( 'Blocks new bookings', 'vk-booking-manager' ); ?></span>
				<?php endif; ?>
			</td>
			<td>
				<?php if ( '' !== (string) $flag['email'] ) : ?>
					<?php echo esc_html( (string) $flag['email'] ); ?><br />
				<?php endif; ?>
				<?php echo esc_html( (string) $flag['phone'] ); ?>
			</td>
			<td><?php echo wp_kses( nl2br( esc_html( (string) $flag['memo'] ) ), array( 'br' => array() ) ); ?></td>
			<td>
				<?php echo esc_html( $assigned_at ); ?>
				<?php if ( $assigned_by instanceof \WP_User ) : ?>
					<br /><?php echo esc_html( (string) $assigned_by->display_name ); ?>
				<?php endif; ?>
			</td>
			<td>
				<?php if ( '' !== $source_url ) : ?>
					<a href="<?php echo esc_url( $source_url ); ?>">#<?php echo esc_html( (string) $source_id ); ?></a>
				<?php elseif ( $source_gone ) : ?>
					<?php echo esc_html__( 'Deleted', 'vk-booking-manager' ); ?>
				<?php endif; ?>
			</td>
			<td>
				<form method="post" action="" data-vkbm-confirm="<?php echo esc_attr( $confirm_message ); ?>">
					<?php wp_nonce_field( self::GUEST_REMOVE_NONCE_ACTION, self::GUEST_REMOVE_NONCE_NAME ); ?>
					<input type="hidden" name="vkbm_guest_flag_id" value="<?php echo esc_attr( (string) $flag['id'] ); ?>" />
					<input type="hidden" name="guest_type" value="<?php echo esc_attr( $type_filter ); ?>" />
					<input type="hidden" name="guest_q" value="<?php echo esc_attr( $search ); ?>" />
					<button type="submit" class="button button-small" aria-label="<?php echo esc_attr( $remove_aria ); ?>"><?php echo esc_html_x( 'Remove', 'button to remove a customer flag', 'vk-booking-manager' ); ?></button>
				</form>
			</td>
		</tr>
		<?php
	}

	/**
	 * ゲストに付けたフラグの一覧（この設定ページ内の節）の URL を返す。
	 *
	 * @return string 一覧の URL。
	 */
	public static function get_guest_list_url(): string {
		return admin_url( 'admin.php?page=' . self::MENU_SLUG ) . '#' . self::GUEST_SECTION_ID;
	}

	/**
	 * ゲストに付けたフラグの一覧へのリンクを描画する。
	 *
	 * 一覧を開く権限（フラグ種別の設定ページと同じ）が無いユーザーには、何も出さない。
	 *
	 * @param string $link_text リンクの文言。
	 * @return void
	 */
	public static function render_guest_list_link( string $link_text ): void {
		if ( ! current_user_can( Capabilities::MANAGE_PROVIDER_SETTINGS ) ) {
			return;
		}

		echo '<p><a href="' . esc_url( self::get_guest_list_url() ) . '">' . esc_html( $link_text ) . '</a></p>';
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
