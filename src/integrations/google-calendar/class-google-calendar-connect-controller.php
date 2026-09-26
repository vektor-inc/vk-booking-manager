<?php
/**
 * Google カレンダー連携の「つなぐ・選ぶ・外す」操作を受け取るクラス。
 *
 * issue #475（親 issue #94: Google カレンダー連携）。
 *
 * ## BM 設定の保存フォームに混ぜない理由
 * BM 設定の画面は全タブで1つのフォームを共有しており、保存ボタンを押すと、いま開いていない
 * タブの入力もまとめて送られる。連携の開始・解除をこのフォームに混ぜると、別タブで入力途中の
 * 設定まで保存されてから Google の画面へ移動してしまう。そのため連携の操作は、WordPress が
 * 管理画面の個別処理用に用意している送信先（`admin-post.php`）で別々に受け取る
 * （issue #475 の「実装上の注意」）。
 *
 * ## 接続の流れ
 * 1. オーナーが「Googleカレンダーと連携する」を押す（`handle_connect()`）
 *    照合用のランダムな文字列（state）と、横取り防止用のランダムな文字列（code_verifier）を作り、
 *    一時データとして保存する
 * 2. ブラウザを送り出す前に、サーバー間通信でライセンスキーごと中継サーバーへ渡し、
 *    使い捨てのチケットを発行してもらう（`Google_Calendar_Relay_Client::start_session()`）。
 *    ライセンスキーをオーナーのブラウザが遷移する URL に載せないための構成（2026-09-21 決定）
 * 3. 発行されたチケットだけを持たせて、中継サーバーの入り口へブラウザを送り出す
 * 4. Google の画面でログインし「許可」を押す
 * 5. Google →（中継サーバー）→ このサイトへ戻ってくる（`handle_callback()`）
 *    state が 1 で保存したものと一致することを確かめ、許可コードを中継サーバー経由で
 *    アクセス許可へ引き換えて保存する
 * 6. 反映先のカレンダーを選ぶ（`handle_select_calendar()`）
 *
 * ## Pro 版限定
 * この機能は Pro 版限定にしている（#475 で判断）。Google への接続情報と中継サーバーの運用費は
 * Vektor が負担するため、無料版を含む全配布先から使える形にはしない。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Integrations\GoogleCalendar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\Admin\Pro_Upsell;
use function add_action;
use function add_query_arg;
use function array_map;
use function is_array;
use function esc_html__;
use function esc_url_raw;
use function hash_equals;
use function sanitize_text_field;
use function wp_generate_password;
use function admin_url;
use function check_admin_referer;
use function current_user_can;
use function delete_transient;
use function get_current_user_id;
use function get_transient;
use function is_wp_error;
use function set_transient;
use function wp_die;
use function wp_redirect; // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- import 文自体への誤検知。実際の呼び出しは redirect_to_relay() 側で理由を説明済み.
use function wp_safe_redirect;
use function wp_unslash;

/**
 * 連携の開始・戻り・カレンダー選択・解除を受け取るクラス。
 */
class Google_Calendar_Connect_Controller {

	/**
	 * 連携を開始するときの `admin-post.php` の action 名。
	 *
	 * @var string
	 */
	public const ACTION_CONNECT = 'vkbm_google_calendar_connect';

	/**
	 * Google から戻ってきたときの `admin-post.php` の action 名。
	 *
	 * @var string
	 */
	public const ACTION_CALLBACK = 'vkbm_google_calendar_callback';

	/**
	 * 反映先カレンダーを保存するときの `admin-post.php` の action 名。
	 *
	 * @var string
	 */
	public const ACTION_SELECT_CALENDAR = 'vkbm_google_calendar_select_calendar';

	/**
	 * 連携を解除するときの `admin-post.php` の action 名。
	 *
	 * @var string
	 */
	public const ACTION_DISCONNECT = 'vkbm_google_calendar_disconnect';

	/**
	 * 接続の途中経過（state・code_verifier）を保存する一時データ名の接頭辞。
	 *
	 * 操作した利用者ごとに分ける。
	 *
	 * @var string
	 */
	private const PENDING_TRANSIENT_PREFIX = 'vkbm_gcal_pending_';

	/**
	 * 接続の途中経過を保持する時間（秒）。
	 *
	 * Google の画面でログインし許可するまでの猶予。過ぎた場合はやり直しになる。
	 *
	 * @var int
	 */
	private const PENDING_TTL = 900;

	/**
	 * 操作結果のお知らせを保存する一時データ名の接頭辞。
	 *
	 * @var string
	 */
	private const NOTICE_TRANSIENT_PREFIX = 'vkbm_gcal_notice_';

	/**
	 * BM 設定画面のスラッグ。
	 *
	 * @var string
	 */
	private const SETTINGS_PAGE_SLUG = 'vkbm-provider-settings';

	/**
	 * 「連携」タブのスラッグ。
	 *
	 * @var string
	 */
	public const SETTINGS_TAB = 'integration';

	/**
	 * 接続状態。
	 *
	 * @var Google_Calendar_Connection
	 */
	private $connection;

	/**
	 * 中継サーバーとの通信クライアント。
	 *
	 * @var Google_Calendar_Relay_Client
	 */
	private $relay_client;

	/**
	 * Google の API を呼ぶクライアント。
	 *
	 * @var Google_Calendar_Api_Client
	 */
	private $api_client;

	/**
	 * 操作に必要な権限。
	 *
	 * @var string
	 */
	private $capability;

	/**
	 * 予定に載せる情報の設定（issue #476）。未注入の場合は反映先カレンダーの選択のみ扱い、
	 * 項目チェックボックスの保存は行わない（後方互換のため任意注入にしている）。
	 *
	 * @var Google_Calendar_Event_Sync_Settings|null
	 */
	private $sync_settings;

	/**
	 * コンストラクタ。
	 *
	 * @param Google_Calendar_Connection               $connection    接続状態。
	 * @param Google_Calendar_Relay_Client             $relay_client  中継サーバーとの通信クライアント。
	 * @param Google_Calendar_Api_Client               $api_client    Google の API を呼ぶクライアント。
	 * @param string                                   $capability    操作に必要な権限。
	 * @param Google_Calendar_Event_Sync_Settings|null $sync_settings 予定に載せる情報の設定。
	 */
	public function __construct(
		Google_Calendar_Connection $connection,
		Google_Calendar_Relay_Client $relay_client,
		Google_Calendar_Api_Client $api_client,
		string $capability = 'manage_options',
		?Google_Calendar_Event_Sync_Settings $sync_settings = null
	) {
		$this->connection    = $connection;
		$this->relay_client  = $relay_client;
		$this->api_client    = $api_client;
		$this->capability    = $capability;
		$this->sync_settings = $sync_settings;
	}

	/**
	 * この機能が有効かどうか（Pro 版限定）。
	 *
	 * `register()` 等の一般的な名前でこの判定を直接書くと、`bin/check-pro-gate-test-skips.js`
	 * が同名メソッドを呼ぶ無関係なテストまで検出対象にしてしまうため、専用の名前に切り出している
	 * （`Resource_Delete_Guard::is_guard_enabled()` と同じ理由）。
	 *
	 * @return bool Pro 版なら true。
	 */
	public static function is_integration_enabled(): bool {
		return ! Pro_Upsell::is_free_edition();
	}

	/**
	 * この機能をオーナーに見せてよいかどうか。
	 *
	 * Pro 版であることに加えて、中継サーバーの接続先が決まっていることを条件にしている。
	 * 接続先が空のまま画面に出すと、押してもつながらない連携ボタンがオーナーに見えてしまうため
	 * （`Google_Calendar_Relay_Client::DEFAULT_BASE_URL` の説明を参照）。
	 *
	 * @return bool 画面に出してよいなら true。
	 */
	public function is_available(): bool {
		return self::is_integration_enabled() && $this->relay_client->is_configured();
	}

	/**
	 * WordPress のフックへ登録する。
	 *
	 * 中継サーバーの接続先が決まっていない間は、連携の受け口そのものを作らない。
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! $this->is_available() ) {
			return;
		}

		add_action( 'admin_post_' . self::ACTION_CONNECT, array( $this, 'handle_connect' ) );
		add_action( 'admin_post_' . self::ACTION_CALLBACK, array( $this, 'handle_callback' ) );
		add_action( 'admin_post_' . self::ACTION_SELECT_CALENDAR, array( $this, 'handle_select_calendar' ) );
		add_action( 'admin_post_' . self::ACTION_DISCONNECT, array( $this, 'handle_disconnect' ) );
	}

	/**
	 * 「Googleカレンダーと連携する」を押されたときの処理。
	 *
	 * 照合用の state と横取り防止用の code_verifier を作り、まず中継サーバーへサーバー間通信で
	 * ライセンスキーごと渡して使い捨てのチケットを発行してもらう（`Google_Calendar_Relay_Client::start_session()`）。
	 * ライセンスキーをオーナーのブラウザが遷移する URL に載せないための構成（2026-09-21 決定。
	 * 安藤レビュー指摘）。state・code_verifier を一時保存したうえで、発行されたチケットだけを
	 * 持たせて中継サーバーの入り口へブラウザを送り出す。
	 *
	 * @return void
	 */
	public function handle_connect(): void {
		$this->verify_request( self::ACTION_CONNECT );

		if ( ! $this->relay_client->has_license_key() ) {
			// ライセンスキーが無いまま送り出しても、この後の start_session() が中継サーバーに
			// 拒否される（Pro 版のライセンスキーを持つサイトからの依頼だけを受け付ける。
			// 2026-09-21 決定）。Google の同意画面まで進めたのに引き換えだけ失敗する
			// 分かりにくい失敗を避けるため、ここで止めてライセンスキーの入力を促す。
			$this->set_notice( 'error', $this->get_license_key_missing_message() );
			$this->redirect_to_settings();
		}

		if ( ! Google_Calendar_Secret_Store::is_available() ) {
			// 暗号化できない環境では、アクセス許可を安全に保存できないため接続させない。
			$this->set_notice( 'error', __( 'This server cannot store the permission securely, so the connection was not started.', 'vk-booking-manager' ) );
			$this->redirect_to_settings();
		}

		$state         = wp_generate_password( 32, false, false );
		$code_verifier = Google_Calendar_Relay_Client::generate_code_verifier();

		// ライセンスキーの検証と、state・code_challenge・site_callback の登録をサーバー間通信で
		// 済ませ、ブラウザには使い捨てのチケットだけを持たせる。
		$ticket = $this->relay_client->start_session(
			$state,
			Google_Calendar_Relay_Client::create_code_challenge( $code_verifier ),
			$this->get_callback_url()
		);

		if ( is_wp_error( $ticket ) ) {
			// ライセンスキーが無効・期限切れの場合は「中継サーバーがエラーを返しました。」では
			// 何を直せばよいか分からないため、ライセンス向けの案内に差し替える（安藤レビュー指摘）。
			// 種別の判定は Google_Calendar_Api_Client::is_authorization_lost() と同じ考え方で、
			// 中継サーバーが `license` を含む専用の種別で返すことを仕様書で要件にしている。
			$message = ( false !== strpos( $ticket->get_error_code(), 'license' ) )
				? $this->get_license_key_invalid_message()
				: $ticket->get_error_message();

			$this->set_notice( 'error', $message );
			$this->redirect_to_settings();
		}

		set_transient(
			$this->get_pending_transient_name(),
			array(
				'state'         => $state,
				'code_verifier' => $code_verifier,
			),
			self::PENDING_TTL
		);

		$this->redirect_to_relay( $this->relay_client->build_authorization_url( $ticket ) );
	}

	/**
	 * Google（中継サーバー経由）から戻ってきたときの処理。
	 *
	 * @return void
	 */
	public function handle_callback(): void {
		$this->verify_access();

		$pending = get_transient( $this->get_pending_transient_name() );
		delete_transient( $this->get_pending_transient_name() );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Google からの戻りのため nonce は付けられない。代わりに一時保存した state と照合する。
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' !== $error ) {
			// オーナーが Google の画面で「許可しない」を選んだ場合など。
			$this->set_notice( 'error', __( 'The connection was cancelled on the Google screen.', 'vk-booking-manager' ) );
			$this->redirect_to_settings();
		}

		if ( ! is_array( $pending ) || empty( $pending['state'] ) || '' === $state || ! hash_equals( (string) $pending['state'], $state ) ) {
			// 途中経過が時間切れで消えた場合、または戻りの内容が一致しない場合。
			$this->set_notice( 'error', $this->get_retry_message() );
			$this->redirect_to_settings();
		}

		if ( '' === $code ) {
			$this->set_notice( 'error', $this->get_retry_message() );
			$this->redirect_to_settings();
		}

		// state と同時に保存しているため通常は欠けないが、未定義キーへ直接アクセスしないよう
		// 安全側に倒す（安藤レビュー指摘）。欠けていれば時間切れ等と同じ扱いでやり直させる。
		$code_verifier = isset( $pending['code_verifier'] ) ? (string) $pending['code_verifier'] : '';

		if ( '' === $code_verifier ) {
			$this->set_notice( 'error', $this->get_retry_message() );
			$this->redirect_to_settings();
		}

		$tokens = $this->relay_client->exchange_code( $code, $code_verifier, $this->get_callback_url() );

		if ( is_wp_error( $tokens ) ) {
			$this->set_notice( 'error', $tokens->get_error_message() );
			$this->redirect_to_settings();
		}

		if ( ! $this->connection->save_tokens( $tokens ) ) {
			$this->set_notice( 'error', __( 'Could not save the permission received from Google.', 'vk-booking-manager' ) );
			$this->redirect_to_settings();
		}

		// 連携し直した直後は、前回の一覧が残っていると別アカウントのカレンダーが出てしまう。
		Google_Calendar_Settings_Panel::flush_calendar_list_cache();

		// 反映先が未選択なら、本人の既定のカレンダーを初期値として入れておく。
		// 接続した直後に「どれも選ばれていない」状態で放置されるのを避けるため。
		if ( '' === $this->connection->get_calendar_id() ) {
			$this->preselect_primary_calendar();
		}

		$this->set_notice( 'success', __( 'Connected to Google Calendar.', 'vk-booking-manager' ) );
		$this->redirect_to_settings();
	}

	/**
	 * ライセンスキーが登録されていないときのお知らせ文を組み立てる。
	 *
	 * ライセンスキーを入力する「ライセンス」タブは `manage_options` を持つ利用者にしか
	 * 表示されない（`Provider_Settings_Page` の `$show_license_tab`）。一方この画面に入れる
	 * 「店舗管理者」は、`Roles_Manager` が `manage_options` を剥がしているため、そのタブを
	 * 開けない。両者に同じ「ライセンスタブで入力してください」を出すと、後者は案内のとおりに
	 * 動けず行き止まりになるため、権限で文言を分ける（安藤レビュー指摘）。
	 *
	 * 翻訳関数には1文ずつ入れる決まりのため、2文に分けて翻訳し、ここでつなぐ。
	 *
	 * @return string お知らせ文。
	 */
	private function get_license_key_missing_message(): string {
		if ( current_user_can( 'manage_options' ) ) {
			return __( 'Please enter your license key on the License tab before connecting to Google Calendar.', 'vk-booking-manager' );
		}

		return __( 'The license key has not been registered.', 'vk-booking-manager' )
			. ' '
			. __( 'Please ask your site administrator.', 'vk-booking-manager' );
	}

	/**
	 * ライセンスキーが受け付けられなかったときのお知らせ文を組み立てる。
	 *
	 * 無効・期限切れのいずれも、オーナーが直すべき先は同じ（ライセンスキーの更新）のため、
	 * 文言は分けていない。行き先の案内は上記と同じ理由で権限によって変える。
	 *
	 * @return string お知らせ文。
	 */
	private function get_license_key_invalid_message(): string {
		if ( current_user_can( 'manage_options' ) ) {
			return __( 'The license key was not accepted.', 'vk-booking-manager' )
				. ' '
				. __( 'Please check the license key on the License tab.', 'vk-booking-manager' );
		}

		return __( 'The license key was not accepted.', 'vk-booking-manager' )
			. ' '
			. __( 'Please ask your site administrator.', 'vk-booking-manager' );
	}

	/**
	 * 「やり直してください」のお知らせ文を組み立てる。
	 *
	 * 翻訳関数には1文ずつ入れる決まりのため、2文を分けて翻訳し、ここでつなぐ。
	 *
	 * @return string お知らせ文。
	 */
	private function get_retry_message(): string {
		return __( 'The connection could not be completed.', 'vk-booking-manager' )
			. ' '
			. __( 'Please try again.', 'vk-booking-manager' );
	}

	/**
	 * 反映先カレンダーが選ばれたときの処理。
	 *
	 * @return void
	 */
	public function handle_select_calendar(): void {
		$this->verify_request( self::ACTION_SELECT_CALENDAR );

		// 「予定に載せる情報」は反映先カレンダーと同じフォーム・同じ保存ボタンで保存する
		// （植草案。issue #476）。カレンダー一覧の取得・照合の成否に関わらず必ず保存する。
		// 以前は照合（この下の一覧との突き合わせ）の成功時にしか呼んでおらず、一覧が取れない
		// 等で早期リターンすると、この項目だけ入力して保存したつもりが保存されていない状態に
		// なっていた（安藤レビュー指摘）。
		$this->save_sync_fields();

		$calendar_id = isset( $_POST['vkbm_google_calendar_id'] ) ? sanitize_text_field( wp_unslash( $_POST['vkbm_google_calendar_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_request() で確認済み.

		if ( '' === $calendar_id ) {
			$this->set_notice(
				'error',
				__( 'Please select the calendar to use.', 'vk-booking-manager' ) . ' ' . $this->get_sync_fields_saved_message()
			);
			$this->redirect_to_settings();
		}

		// 画面に出した一覧に含まれるカレンダーかどうかを、Google に問い合わせて確かめる。
		// 送られてきた ID をそのまま保存すると、書き込めないカレンダーが保存されうるため。
		$calendars = $this->api_client->get_calendar_list();

		if ( is_wp_error( $calendars ) ) {
			$this->set_notice( 'error', $calendars->get_error_message() . ' ' . $this->get_sync_fields_saved_message() );
			$this->redirect_to_settings();
		}

		foreach ( $calendars as $calendar ) {
			if ( $calendar['id'] === $calendar_id ) {
				$this->connection->set_calendar( $calendar['id'], $calendar['summary'] );
				$this->set_notice( 'success', __( 'The calendar to use has been saved.', 'vk-booking-manager' ) );
				$this->redirect_to_settings();
			}
		}

		$this->set_notice(
			'error',
			__( 'The selected calendar was not found.', 'vk-booking-manager' ) . ' ' . $this->get_sync_fields_saved_message()
		);
		$this->redirect_to_settings();
	}

	/**
	 * 「予定に載せる情報」の項目設定は保存できたが、反映先カレンダーは確認できなかった
	 * ことを伝える文を返す（安藤レビュー指摘。項目設定はカレンダーの照合結果に関わらず
	 * 常に保存されるため、カレンダー側でエラーを出す全ての経路でこの文を添える）。
	 *
	 * @return string お知らせ文（1文）。
	 */
	private function get_sync_fields_saved_message(): string {
		return __( 'The settings for what to include in the event have been saved, but the calendar to use could not be confirmed.', 'vk-booking-manager' );
	}

	/**
	 * 「予定に載せる情報」チェックボックスの内容を保存する。
	 *
	 * `Google_Calendar_Event_Sync_Settings` が注入されていない場合は何もしない
	 * （後方互換。テスト等でカレンダー選択のみを確かめる場合に備える）。
	 *
	 * @return void
	 */
	private function save_sync_fields(): void {
		if ( null === $this->sync_settings ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verify_request() で nonce 確認済み。この行はまだ unslash/サニタイズ前の生の値を一時変数へ退避しているだけで、次の行で wp_unslash() ＋ sanitize_text_field() を必ず通す。
		$raw_fields = isset( $_POST['vkbm_google_calendar_sync_fields'] ) ? $_POST['vkbm_google_calendar_sync_fields'] : array();
		$fields     = is_array( $raw_fields ) ? array_map( 'sanitize_text_field', wp_unslash( $raw_fields ) ) : array();

		$this->sync_settings->save( $fields );
	}

	/**
	 * 連携を解除するときの処理。
	 *
	 * Google 側に作成済みの予定は削除しない（親 issue #94 で決定）。
	 *
	 * @return void
	 */
	public function handle_disconnect(): void {
		$this->verify_request( self::ACTION_DISCONNECT );

		$refresh_token = $this->connection->get_refresh_token();

		if ( null !== $refresh_token ) {
			// Google 側の許可の取り消しは、できなくても解除自体は進める。
			// 取り消せないことを理由にこのサイトの情報を残すと、解除したつもりの状態が残るため。
			$this->relay_client->revoke( $refresh_token );
		}

		$this->connection->clear();
		// 別のアカウントで繋ぎ直したときに、前のアカウントのカレンダーが残らないようにする。
		Google_Calendar_Settings_Panel::flush_calendar_list_cache();

		$this->set_notice( 'success', __( 'The connection to Google Calendar has been removed.', 'vk-booking-manager' ) );
		$this->redirect_to_settings();
	}

	/**
	 * 接続した直後に、本人の既定のカレンダーを反映先の初期値として入れる。
	 *
	 * 一覧の取得に失敗しても接続そのものは成立しているため、失敗しても何もしない
	 * （画面でオーナーが選び直せる）。
	 *
	 * @return void
	 */
	private function preselect_primary_calendar(): void {
		$calendars = $this->api_client->get_calendar_list();

		if ( is_wp_error( $calendars ) || array() === $calendars ) {
			return;
		}

		foreach ( $calendars as $calendar ) {
			if ( $calendar['primary'] ) {
				$this->connection->set_calendar( $calendar['id'], $calendar['summary'] );
				return;
			}
		}
	}

	/**
	 * Google からの戻り先になる、このサイトの URL を返す。
	 *
	 * @return string 戻り先の URL。
	 */
	public function get_callback_url(): string {
		return add_query_arg(
			array( 'action' => self::ACTION_CALLBACK ),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * 「連携」タブの URL を返す。
	 *
	 * issue #476。予約編集画面の「連携タブを開く」導線（`Google_Calendar_Event_Sync`）から使う。
	 *
	 * @return string URL。
	 */
	public static function get_settings_tab_url(): string {
		return add_query_arg(
			array(
				'page' => self::SETTINGS_PAGE_SLUG,
				'tab'  => self::SETTINGS_TAB,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * 権限と nonce（使い回し防止の確認用の値）を確認する。
	 *
	 * @param string $action `admin-post.php` の action 名。nonce の照合にも使う。
	 * @return void
	 */
	private function verify_request( string $action ): void {
		$this->verify_access();
		check_admin_referer( $action );
	}

	/**
	 * Pro 版かどうかと権限を確認する。
	 *
	 * @return void
	 */
	private function verify_access(): void {
		if ( ! self::is_integration_enabled() ) {
			wp_die( esc_html__( 'This feature is available in the Pro edition.', 'vk-booking-manager' ), '', array( 'response' => 403 ) );
		}

		if ( ! $this->relay_client->is_configured() ) {
			// 受け口は register() で作っていないため通常は到達しない。直接呼ばれた場合の保険。
			wp_die( esc_html__( 'This feature is not available yet.', 'vk-booking-manager' ), '', array( 'response' => 403 ) );
		}

		if ( ! current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'vk-booking-manager' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * 中継サーバーへブラウザを送り出す。
	 *
	 * 送り先はこのサイトの外になるため `wp_safe_redirect()` は使えない。URL は定数・フィルターで
	 * 組み立てた自前の値で、利用者の入力は含まない。
	 *
	 * 送り出しと同時に処理を終える（`exit`）。テストから呼べるように、`private` ではなく
	 * `protected` にして差し替えられるようにしている。
	 *
	 * @param string $url 送り先の URL。
	 * @return void
	 */
	protected function redirect_to_relay( string $url ): void {
		wp_redirect( esc_url_raw( $url ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- 外部（中継サーバー）への送り出しのため.
		exit;
	}

	/**
	 * BM 設定の「連携」タブへ戻す。
	 *
	 * 送り出しと同時に処理を終える（`exit`）。テストから呼べるように、`private` ではなく
	 * `protected` にして差し替えられるようにしている。
	 *
	 * @return void
	 */
	protected function redirect_to_settings(): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => self::SETTINGS_PAGE_SLUG,
					'tab'  => self::SETTINGS_TAB,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * 操作結果のお知らせを一時保存する（画面へ戻したあとに1度だけ表示する）。
	 *
	 * @param string $type    `success` または `error`。
	 * @param string $message 表示する内容。
	 * @return void
	 */
	private function set_notice( string $type, string $message ): void {
		set_transient(
			self::NOTICE_TRANSIENT_PREFIX . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			60
		);
	}

	/**
	 * 一時保存されたお知らせを取り出して消す。
	 *
	 * @return array{type:string, message:string}|null お知らせ。無ければ null。
	 */
	public static function pull_notice(): ?array {
		$name   = self::NOTICE_TRANSIENT_PREFIX . get_current_user_id();
		$notice = get_transient( $name );

		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return null;
		}

		delete_transient( $name );

		return array(
			'type'    => isset( $notice['type'] ) && 'success' === $notice['type'] ? 'success' : 'error',
			'message' => (string) $notice['message'],
		);
	}

	/**
	 * 接続の途中（Google の画面へ送り出したまま戻ってきていない）かどうかを返す。
	 *
	 * 画面の「接続中」表示の判定に使う。
	 *
	 * @return bool 途中なら true。
	 */
	public function is_pending(): bool {
		return false !== get_transient( $this->get_pending_transient_name() );
	}

	/**
	 * 接続の途中経過を保存する一時データ名を返す。
	 *
	 * @return string 一時データ名。
	 */
	private function get_pending_transient_name(): string {
		return self::PENDING_TRANSIENT_PREFIX . get_current_user_id();
	}
}
