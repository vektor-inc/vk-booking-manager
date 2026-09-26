<?php
/**
 * BM 設定の「連携」タブに出す、Google カレンダー連携の画面を組み立てるクラス。
 *
 * issue #475（親 issue #94: Google カレンダー連携）。
 *
 * ## 出し分ける状態（issue #475 の植草案）
 * | 状態 | 表示 |
 * |---|---|
 * | 未接続 | 説明文と「Googleカレンダーと連携する」ボタン |
 * | 接続中 | 「Googleと連携しています…」 |
 * | 接続済み | 連携中のアカウント名・反映先カレンダーの選択・「連携を解除」 |
 * | エラー | 「Googleとの連携が切れています。」と「再接続する」 |
 * | 解除の確認 | 解除後どうなるかを示したうえで「解除する」「キャンセル」 |
 *
 * ## BM 設定の保存フォームの外に置く
 * BM 設定の画面は全タブで1つのフォームを共有しており、保存ボタンを押すといま開いていない
 * タブの入力もまとめて送られる。この画面のボタンはその共有フォームには入れず、`admin-post.php`
 * 宛の独立したフォームとして出す（issue #475 の「実装上の注意」）。そのため
 * `Provider_Settings_Page` 側では、「連携」タブのときに共有フォーム自体を描画しない。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Integrations\GoogleCalendar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use function add_query_arg;
use function admin_url;
use function checked;
use function delete_transient;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_html_e;
use function esc_url;
use function get_transient;
use function in_array;
use function is_wp_error;
use function sanitize_key;
use function selected;
use function set_transient;
use function sprintf;
use function submit_button;
use function vkbm_get_resource_label_singular;
use function wp_nonce_field;
use function wp_unslash;

/**
 * 「連携」タブの中身を描画するクラス。
 */
class Google_Calendar_Settings_Panel {

	/**
	 * カレンダー一覧を一時的に保存しておく名前。
	 *
	 * 「連携」タブを開くたびに Google へ問い合わせると表示が遅くなるため、短時間だけ使い回す。
	 *
	 * @var string
	 */
	private const CALENDAR_LIST_TRANSIENT = 'vkbm_google_calendar_list';

	/**
	 * カレンダー一覧を使い回す時間（秒）。
	 *
	 * @var int
	 */
	private const CALENDAR_LIST_TTL = 300;

	/**
	 * 解除の確認画面を出すときに URL へ付ける値。
	 *
	 * @var string
	 */
	public const VIEW_DISCONNECT_CONFIRM = 'disconnect-confirm';

	/**
	 * 接続状態。
	 *
	 * @var Google_Calendar_Connection
	 */
	private $connection;

	/**
	 * Google の API を呼ぶクライアント。
	 *
	 * @var Google_Calendar_Api_Client
	 */
	private $api_client;

	/**
	 * 連携の操作を受け取るコントローラー。
	 *
	 * @var Google_Calendar_Connect_Controller
	 */
	private $controller;

	/**
	 * 予定に載せる情報の設定（issue #476）。
	 *
	 * @var Google_Calendar_Event_Sync_Settings
	 */
	private $sync_settings;

	/**
	 * コンストラクタ。
	 *
	 * @param Google_Calendar_Connection          $connection    接続状態。
	 * @param Google_Calendar_Api_Client          $api_client    Google の API を呼ぶクライアント。
	 * @param Google_Calendar_Connect_Controller  $controller    連携の操作を受け取るコントローラー。
	 * @param Google_Calendar_Event_Sync_Settings $sync_settings 予定に載せる情報の設定。
	 */
	public function __construct(
		Google_Calendar_Connection $connection,
		Google_Calendar_Api_Client $api_client,
		Google_Calendar_Connect_Controller $controller,
		Google_Calendar_Event_Sync_Settings $sync_settings
	) {
		$this->connection    = $connection;
		$this->api_client    = $api_client;
		$this->controller    = $controller;
		$this->sync_settings = $sync_settings;
	}

	/**
	 * 「連携」タブをオーナーに見せてよいかどうか。
	 *
	 * Pro 版であることと、中継サーバーの接続先が決まっていることの両方を満たす場合のみ true。
	 * 判定そのものは `Google_Calendar_Connect_Controller::is_available()` に持たせ、
	 * 画面側とフックの登録側で条件がずれないようにしている。
	 *
	 * @return bool 見せてよいなら true。
	 */
	public function is_available(): bool {
		return $this->controller->is_available();
	}

	/**
	 * 「連携」タブの中身を出力する。
	 *
	 * @return void
	 */
	public function render(): void {
		?>
		<div class="vkbm-google-calendar">
			<h2><?php esc_html_e( 'Google Calendar', 'vk-booking-manager' ); ?></h2>
			<?php
			$this->render_notice();

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 表示する内容の切り替えのみで、変更は伴わない.
			$view = isset( $_GET['vkbm_gcal_view'] ) ? sanitize_key( wp_unslash( $_GET['vkbm_gcal_view'] ) ) : '';

			if ( self::VIEW_DISCONNECT_CONFIRM === $view && $this->connection->is_connected() ) {
				$this->render_disconnect_confirm();
			} else {
				$this->render_status();
			}
			?>
		</div>
		<?php
	}

	/**
	 * 直前の操作結果のお知らせを出力する。
	 *
	 * @return void
	 */
	private function render_notice(): void {
		$notice = Google_Calendar_Connect_Controller::pull_notice();

		if ( null === $notice ) {
			return;
		}

		$class = 'success' === $notice['type'] ? 'notice-success' : 'notice-error';
		?>
		<div class="notice <?php echo esc_attr( $class ); ?> inline">
			<p><?php echo esc_html( $notice['message'] ); ?></p>
		</div>
		<?php
	}

	/**
	 * 現在の接続状態に応じた画面を出力する。
	 *
	 * @return void
	 */
	private function render_status(): void {
		$status = $this->connection->get_status();

		if ( Google_Calendar_Connection::STATUS_CONNECTED === $status ) {
			$this->render_connected();
			return;
		}

		if ( Google_Calendar_Connection::STATUS_ERROR === $status ) {
			$this->render_error();
			return;
		}

		if ( $this->controller->is_pending() ) {
			$this->render_pending();
			return;
		}

		$this->render_disconnected();
	}

	/**
	 * 未接続の画面を出力する。
	 *
	 * @return void
	 */
	private function render_disconnected(): void {
		?>
		<p class="description">
			<?php esc_html_e( 'Bookings are automatically reflected in the Google Calendar you select when they are created, changed, or cancelled.', 'vk-booking-manager' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( 'Bookings made before you connect are not added to the calendar.', 'vk-booking-manager' ); ?>
		</p>
		<?php $this->render_connect_form( __( 'Connect to Google Calendar', 'vk-booking-manager' ) ); ?>
		<?php
	}

	/**
	 * 接続中（Google の画面へ送り出したまま戻ってきていない）の画面を出力する。
	 *
	 * @return void
	 */
	private function render_pending(): void {
		?>
		<p role="status"><?php esc_html_e( 'Connecting to Google…', 'vk-booking-manager' ); ?></p>
		<p class="description">
			<?php esc_html_e( 'If the Google screen did not open, start the connection again.', 'vk-booking-manager' ); ?>
		</p>
		<?php $this->render_connect_form( __( 'Start the connection again', 'vk-booking-manager' ) ); ?>
		<?php
	}

	/**
	 * 連携が切れているときの画面を出力する。
	 *
	 * @return void
	 */
	private function render_error(): void {
		?>
		<div class="notice notice-error inline">
			<p><?php esc_html_e( 'The connection to Google has been lost.', 'vk-booking-manager' ); ?></p>
			<p><?php esc_html_e( 'Bookings are not being reflected in the calendar.', 'vk-booking-manager' ); ?></p>
		</div>
		<?php $this->render_connect_form( __( 'Reconnect', 'vk-booking-manager' ) ); ?>
		<?php
	}

	/**
	 * 接続済みの画面（アカウント名・反映先カレンダーの選択・解除）を出力する。
	 *
	 * 中継サーバーへの一時的な到達不可・不調では `get_status()` は `connected` のまま
	 * （`Google_Calendar_Api_Client::get_access_token()` を参照）で、この画面が呼ばれる。
	 * その場合はここでカレンダー一覧の取得（アクセストークンの取り直し）が失敗し、
	 * 下の `is_wp_error( $calendars )` の分岐でその旨のお知らせが出る。連携そのものが
	 * 切れた（`error` 状態）わけではないため「連携が切れています」という文言は出さず、
	 * カレンダー選択の欄だけにその時点の失敗理由（到達不可・抑止中など）を表示する。
	 *
	 * @return void
	 */
	private function render_connected(): void {
		$calendars = $this->get_calendar_choices();
		?>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Connected account', 'vk-booking-manager' ); ?></th>
					<td>
						<p>
							<?php
							$account_email = $this->connection->get_account_email();
							echo esc_html( '' !== $account_email ? $account_email : __( 'Connected to Google Calendar.', 'vk-booking-manager' ) );
							?>
						</p>
					</td>
				</tr>
			</tbody>
		</table>
		<p class="description">
			<?php esc_html_e( 'Bookings made before you connect are not added to the calendar.', 'vk-booking-manager' ); ?>
		</p>
		<?php /* 「予定に載せる情報」は、カレンダー一覧が取得できない・0件のときも表示する（植草レビュー指摘）。フォーム自体は常に出し、カレンダーIDは選べないときは保存済みの値をそのまま維持する隠しフィールドにする。 */ ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( Google_Calendar_Connect_Controller::ACTION_SELECT_CALENDAR ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( Google_Calendar_Connect_Controller::ACTION_SELECT_CALENDAR ); ?>" />
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="vkbm-google-calendar-id"><?php esc_html_e( 'Calendar to use', 'vk-booking-manager' ); ?></label>
						</th>
						<td>
							<?php if ( is_wp_error( $calendars ) ) : ?>
								<div class="notice notice-error inline">
									<p><?php echo esc_html( $calendars->get_error_message() ); ?></p>
								</div>
								<input type="hidden" name="vkbm_google_calendar_id" value="<?php echo esc_attr( $this->connection->get_calendar_id() ); ?>" />
							<?php elseif ( array() === $calendars ) : ?>
								<p class="description">
									<?php esc_html_e( 'No calendar that allows adding events was found in this Google account.', 'vk-booking-manager' ); ?>
								</p>
								<input type="hidden" name="vkbm_google_calendar_id" value="<?php echo esc_attr( $this->connection->get_calendar_id() ); ?>" />
							<?php else : ?>
								<select id="vkbm-google-calendar-id" name="vkbm_google_calendar_id">
									<?php foreach ( $calendars as $calendar ) : ?>
										<option
											value="<?php echo esc_attr( $calendar['id'] ); ?>"
											<?php selected( $this->connection->get_calendar_id(), $calendar['id'] ); ?>
										>
											<?php echo esc_html( $calendar['summary'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>
			<?php $this->render_sync_fields_fieldset(); ?>
			<?php submit_button( __( 'Save the calendar to use', 'vk-booking-manager' ), 'primary', 'submit', false ); ?>
		</form>
		<p>
			<a
				class="button button-link-delete"
				href="<?php echo esc_url( $this->get_disconnect_confirm_url() ); ?>"
			>
				<?php esc_html_e( 'Remove the connection', 'vk-booking-manager' ); ?>
			</a>
		</p>
		<?php
	}

	/**
	 * 「予定に載せる情報」チェックボックスを出力する。
	 *
	 * 反映先カレンダーの選択と同じフォーム・同じ保存ボタンにまとめる（植草案。issue #476）。
	 * 管理用メモ（内部メモ）は選択肢に出さない
	 * （{@see Google_Calendar_Event_Sync_Settings::get_field_keys()} 参照）。
	 *
	 * @return void
	 */
	private function render_sync_fields_fieldset(): void {
		$enabled = $this->sync_settings->get_enabled_fields();
		$options = array(
			Google_Calendar_Event_Sync_Settings::FIELD_GUESTS         => __( 'Number of guests', 'vk-booking-manager' ),
			/* translators: %s: 担当の呼び名（設定で変更可）。 */
			Google_Calendar_Event_Sync_Settings::FIELD_STAFF          => sprintf( __( '%s in charge', 'vk-booking-manager' ), vkbm_get_resource_label_singular() ),
			Google_Calendar_Event_Sync_Settings::FIELD_ADMIN_LINK     => __( 'Link to the reservation management screen', 'vk-booking-manager' ),
			Google_Calendar_Event_Sync_Settings::FIELD_CUSTOMER_NAME  => __( 'Customer name', 'vk-booking-manager' ),
			Google_Calendar_Event_Sync_Settings::FIELD_CUSTOMER_TEL   => __( 'Customer phone number', 'vk-booking-manager' ),
			Google_Calendar_Event_Sync_Settings::FIELD_CUSTOMER_EMAIL => __( 'Customer email address', 'vk-booking-manager' ),
			Google_Calendar_Event_Sync_Settings::FIELD_CUSTOMER_NOTE  => __( 'Notes and requests from the customer', 'vk-booking-manager' ),
		);
		?>
		<fieldset class="vkbm-google-calendar__sync-fields">
			<legend><strong><?php esc_html_e( 'Information to include in the calendar event', 'vk-booking-manager' ); ?></strong></legend>
			<p class="description">
				<?php echo esc_html( $this->get_sync_fields_description() ); ?>
			</p>
			<ul>
				<?php foreach ( $options as $key => $label ) : ?>
					<li>
						<label>
							<input
								type="checkbox"
								name="vkbm_google_calendar_sync_fields[]"
								value="<?php echo esc_attr( $key ); ?>"
								<?php checked( in_array( $key, $enabled, true ) ); ?>
							/>
							<?php echo esc_html( $label ); ?>
						</label>
					</li>
				<?php endforeach; ?>
			</ul>
		</fieldset>
		<?php
	}

	/**
	 * 「予定に載せる情報」フィールドセットの説明文を組み立てる。
	 *
	 * 初期値オンの項目（予約人数・担当・予約管理画面へのリンク）もあるため、「すべてオフ」と
	 * 読める書き方をしない。実際にオフなのはお客様に関する項目だけと分かる文にする
	 * （植草レビュー指摘）。1つの翻訳関数に複数文を入れないよう、文ごとに分けてから連結する
	 * （coding-rules.md の国際化ルールに準拠。`Setup_Notices::get_permalink_htaccess_notice_message()`
	 * と同じ方式）。
	 *
	 * @return string 説明文。
	 */
	private function get_sync_fields_description(): string {
		$message  = __( 'Choose what to include in the event created in Google Calendar.', 'vk-booking-manager' );
		$message .= __( ' Items related to the customer (name, phone number, email address, and notes) are off by default because Google Calendar may be seen by more people than the reservation management screen (sharing, phone notifications, etc.).', 'vk-booking-manager' );

		return $message;
	}

	/**
	 * 解除の確認画面を出力する。
	 *
	 * ブラウザの確認ダイアログではなく画面として出す。ダイアログは内容を十分に説明できず、
	 * 「Google 側に作成済みの予定は残る」ことを伝えきれないため。
	 *
	 * @return void
	 */
	private function render_disconnect_confirm(): void {
		?>
		<div class="notice notice-warning inline">
			<p><?php esc_html_e( 'Do you want to remove the connection?', 'vk-booking-manager' ); ?></p>
			<p><?php esc_html_e( 'After it is removed, new bookings will no longer be reflected in the calendar.', 'vk-booking-manager' ); ?></p>
			<p><?php esc_html_e( 'Events already created in Google will remain.', 'vk-booking-manager' ); ?></p>
		</div>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( Google_Calendar_Connect_Controller::ACTION_DISCONNECT ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( Google_Calendar_Connect_Controller::ACTION_DISCONNECT ); ?>" />
			<p>
				<button type="submit" class="button button-link-delete">
					<?php esc_html_e( 'Remove the connection', 'vk-booking-manager' ); ?>
				</button>
				<a class="button" href="<?php echo esc_url( $this->get_tab_url() ); ?>">
					<?php esc_html_e( 'Cancel', 'vk-booking-manager' ); ?>
				</a>
			</p>
		</form>
		<?php
	}

	/**
	 * 連携を開始するフォーム（ボタン1つ）を出力する。
	 *
	 * @param string $label ボタンに出す文言。
	 * @return void
	 */
	private function render_connect_form( string $label ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( Google_Calendar_Connect_Controller::ACTION_CONNECT ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( Google_Calendar_Connect_Controller::ACTION_CONNECT ); ?>" />
			<?php submit_button( $label, 'primary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * カレンダーの一覧を取得する（短時間だけ使い回す）。
	 *
	 * @return array<int, array{id:string, summary:string, primary:bool}>|\WP_Error 一覧、または失敗の内容。
	 */
	private function get_calendar_choices() {
		$cached = get_transient( self::CALENDAR_LIST_TRANSIENT );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$calendars = $this->api_client->get_calendar_list();

		if ( is_wp_error( $calendars ) ) {
			return $calendars;
		}

		set_transient( self::CALENDAR_LIST_TRANSIENT, $calendars, self::CALENDAR_LIST_TTL );

		return $calendars;
	}

	/**
	 * 使い回しているカレンダーの一覧を捨てる。
	 *
	 * 連携の解除・再接続のように、一覧が変わる操作のあとに呼ぶ。
	 *
	 * @return void
	 */
	public static function flush_calendar_list_cache(): void {
		delete_transient( self::CALENDAR_LIST_TRANSIENT );
	}

	/**
	 * 「連携」タブの URL を返す。
	 *
	 * @return string URL。
	 */
	private function get_tab_url(): string {
		return add_query_arg(
			array(
				'page' => 'vkbm-provider-settings',
				'tab'  => Google_Calendar_Connect_Controller::SETTINGS_TAB,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * 解除の確認画面の URL を返す。
	 *
	 * @return string URL。
	 */
	private function get_disconnect_confirm_url(): string {
		return add_query_arg( 'vkbm_gcal_view', self::VIEW_DISCONNECT_CONFIRM, $this->get_tab_url() );
	}
}
