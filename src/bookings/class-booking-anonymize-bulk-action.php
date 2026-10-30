<?php
/**
 * 予約一覧の一括操作「個人情報を匿名化」の画面側（issue #870）。
 *
 * 一括操作の選択肢・実行・結果の通知・一覧の「匿名化済み」表示・確認ダイアログの読み込みを担当する。
 * 匿名化そのものは {@see Booking_Anonymizer} が行う。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Bookings;

use VKBookingManager\Assets\Common_Styles;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\PostTypes\Booking_Post_Type;
use WP_Post;
use function __;
use function _n;
use function absint;
use function add_action;
use function add_filter;
use function add_query_arg;
use function array_slice;
use function count;
use function current_user_can;
use function delete_transient;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_url;
use function get_current_screen;
use function get_current_user_id;
use function get_edit_post_link;
use function get_the_title;
use function get_transient;
use function in_array;
use function is_array;
use function number_format_i18n;
use function printf;
use function sanitize_key;
use function set_transient;
use function sprintf;
use function wp_enqueue_script;
use function wp_enqueue_style;
use function wp_localize_script;
use function wp_unslash;

/**
 * 予約一覧の一括操作「個人情報を匿名化」。
 */
class Booking_Anonymize_Bulk_Action {

	/**
	 * 一括操作の選択肢の値。
	 */
	public const ACTION = 'vkbm_anonymize';

	/**
	 * 実行後の結果の通知を出すためにリダイレクト先へ付けるクエリ引数。
	 */
	public const QUERY_VAR_DONE = 'vkbm_anonymize_done';

	private const RESULT_TRANSIENT_PREFIX = 'vkbm_anonymize_result_';
	private const RESULT_TRANSIENT_TTL    = 300;

	/**
	 * 終了前の予約のタイトルを通知に並べる最大件数。これを超える分は「ほか N 件」にまとめる。
	 */
	private const NOT_ENDED_LIST_LIMIT = 10;

	/**
	 * 匿名化の処理本体。
	 *
	 * @var Booking_Anonymizer
	 */
	private $anonymizer;

	/**
	 * Constructor.
	 *
	 * @param Booking_Anonymizer $anonymizer 匿名化の処理本体。
	 */
	public function __construct( Booking_Anonymizer $anonymizer ) {
		$this->anonymizer = $anonymizer;
	}

	/**
	 * フックを登録する。
	 */
	public function register(): void {
		$screen_id = 'edit-' . Booking_Post_Type::POST_TYPE;

		add_filter( 'bulk_actions-' . $screen_id, array( $this, 'add_bulk_action' ) );
		add_filter( 'handle_bulk_actions-' . $screen_id, array( $this, 'handle_bulk_action' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'render_result_notices' ) );
		add_filter( 'display_post_states', array( $this, 'add_anonymized_post_state' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * 一括操作のプルダウンに「個人情報を匿名化」を足す。
	 *
	 * 予約を管理する権限が無いユーザー、およびゴミ箱の一覧には出さない
	 * （ゴミ箱の予約は匿名化より削除が自然なため）。
	 *
	 * @param array<string, string> $actions 既存の一括操作。
	 * @return array<string, string>
	 */
	public function add_bulk_action( array $actions ): array {
		if ( ! current_user_can( Capabilities::MANAGE_RESERVATIONS ) || $this->is_trash_list() ) {
			return $actions;
		}

		$actions[ self::ACTION ] = __( 'Anonymize personal data', 'vk-booking-manager' );

		return $actions;
	}

	/**
	 * 一括操作を実行し、結果を現在のユーザー向けに保存する。
	 *
	 * WordPress 本体が一括操作のフォームの nonce を確認したあとに呼ばれる。
	 * ここでは予約を管理する権限と、予約1件ごとの編集権限を確認する。
	 *
	 * @param string $redirect_to リダイレクト先。
	 * @param string $doaction    実行する一括操作。
	 * @param int[]  $post_ids    選択された予約の投稿ID。
	 * @return string 結果の通知を出すためのクエリ引数を付けたリダイレクト先。
	 */
	public function handle_bulk_action( $redirect_to, $doaction, $post_ids ): string {
		$redirect_to = (string) $redirect_to;
		if ( self::ACTION !== $doaction ) {
			return $redirect_to;
		}

		if ( ! current_user_can( Capabilities::MANAGE_RESERVATIONS ) ) {
			return $redirect_to;
		}

		$result = array(
			Booking_Anonymizer::RESULT_ANONYMIZED         => 0,
			Booking_Anonymizer::RESULT_NOT_ENDED          => array(),
			Booking_Anonymizer::RESULT_NO_DATETIME        => 0,
			Booking_Anonymizer::RESULT_ALREADY_ANONYMIZED => 0,
			Booking_Anonymizer::RESULT_FAILED             => 0,
		);

		foreach ( is_array( $post_ids ) ? $post_ids : array() as $post_id ) {
			$post_id = absint( $post_id );
			if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}

			$outcome = $this->anonymizer->anonymize( $post_id );
			if ( Booking_Anonymizer::RESULT_NOT_ENDED === $outcome ) {
				$result[ $outcome ][] = $post_id;
			} elseif ( isset( $result[ $outcome ] ) ) {
				++$result[ $outcome ];
			}
		}

		set_transient( $this->get_result_transient_key(), $result, self::RESULT_TRANSIENT_TTL );

		return add_query_arg( self::QUERY_VAR_DONE, '1', $redirect_to );
	}

	/**
	 * 実行結果の通知を、結果の種類ごとに別々の通知として出す。
	 *
	 * 一部だけ処理された結果を緑の成功表示だけで出すと、処理されなかった予約を見落とすため、
	 * 成功・終了前・日時未設定・匿名化済みを分ける。1件も匿名化しなかったときは成功の通知を出さない。
	 */
	public function render_result_notices(): void {
		if ( ! $this->is_booking_list_screen() || ! isset( $_GET[ self::QUERY_VAR_DONE ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 結果の表示だけで、状態は変更しない。
			return;
		}

		$key    = $this->get_result_transient_key();
		$result = get_transient( $key );
		if ( ! is_array( $result ) ) {
			return;
		}
		delete_transient( $key );

		$anonymized_count = (int) ( $result[ Booking_Anonymizer::RESULT_ANONYMIZED ] ?? 0 );
		if ( $anonymized_count > 0 ) {
			$link    = '';
			$message = sprintf(
				/* translators: %s: Number of reservations. */
				_n( 'Personal data of %s reservation was anonymized.', 'Personal data of %s reservations were anonymized.', $anonymized_count, 'vk-booking-manager' ),
				number_format_i18n( $anonymized_count )
			);
			if ( ! \VKBookingManager\Admin\Pro_Upsell::is_free_edition() ) {
				$link = '<a href="' . esc_url( admin_url( 'admin.php?page=vkbm-provider-settings&tab=advanced#vkbm-booking-auto-anonymize-months' ) ) . '">' . esc_html__( 'To automatically anonymize reservations after a set period from their end, select a period in Advanced settings.', 'vk-booking-manager' ) . '</a>';
			}
			$this->render_notice(
				'notice-success',
				$message,
				$link
			);
		}

		$not_ended_ids = isset( $result[ Booking_Anonymizer::RESULT_NOT_ENDED ] ) && is_array( $result[ Booking_Anonymizer::RESULT_NOT_ENDED ] )
			? $result[ Booking_Anonymizer::RESULT_NOT_ENDED ]
			: array();
		if ( array() !== $not_ended_ids ) {
			$this->render_not_ended_notice( $not_ended_ids );
		}

		$no_datetime_count = (int) ( $result[ Booking_Anonymizer::RESULT_NO_DATETIME ] ?? 0 );
		if ( $no_datetime_count > 0 ) {
			$this->render_notice(
				'notice-warning',
				sprintf(
					/* translators: %s: Number of reservations. */
					_n( '%s reservation was not anonymized because its date and time is not set.', '%s reservations were not anonymized because their date and time is not set.', $no_datetime_count, 'vk-booking-manager' ),
					number_format_i18n( $no_datetime_count )
				)
			);
		}

		$failed_count = (int) ( $result[ Booking_Anonymizer::RESULT_FAILED ] ?? 0 );
		if ( $failed_count > 0 ) {
			$this->render_notice(
				'notice-error',
				sprintf(
					/* translators: %s: Number of reservations. */
					_n( '%s reservation could not be anonymized because saving failed.', '%s reservations could not be anonymized because saving failed.', $failed_count, 'vk-booking-manager' ),
					number_format_i18n( $failed_count )
				) . ' ' . __( 'Please run it again.', 'vk-booking-manager' )
			);
		}

		$already_count = (int) ( $result[ Booking_Anonymizer::RESULT_ALREADY_ANONYMIZED ] ?? 0 );
		if ( $already_count > 0 ) {
			$this->render_notice(
				'notice-info',
				sprintf(
					/* translators: %s: Number of reservations. */
					_n( '%s reservation was skipped because it is already anonymized.', '%s reservations were skipped because they are already anonymized.', $already_count, 'vk-booking-manager' ),
					number_format_i18n( $already_count )
				)
			);
		}
	}

	/**
	 * 予約タイトルの横に「匿名化済み」を足す（「メール未確認」と同じ出し方）。
	 *
	 * @param array<string, string> $post_states 既存の post state。
	 * @param WP_Post               $post        一覧に出している投稿。
	 * @return array<string, string>
	 */
	public function add_anonymized_post_state( array $post_states, WP_Post $post ): array {
		if ( Booking_Post_Type::POST_TYPE !== $post->post_type ) {
			return $post_states;
		}

		if ( ! Booking_Anonymizer::is_anonymized( $post->ID ) ) {
			return $post_states;
		}

		$post_states['vkbm_anonymized'] = __( 'Anonymized', 'vk-booking-manager' );

		return $post_states;
	}

	/**
	 * 予約一覧に確認ダイアログの JS・CSS を読み込む（ゴミ箱の一覧・権限が無いユーザーには読み込まない）。
	 *
	 * @param string $hook_suffix 現在の管理画面フック。
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'edit.php' !== $hook_suffix || ! $this->is_booking_list_screen() ) {
			return;
		}

		if ( ! current_user_can( Capabilities::MANAGE_RESERVATIONS ) || $this->is_trash_list() ) {
			return;
		}

		wp_enqueue_style( Common_Styles::ADMIN_HANDLE );

		// 確認ダイアログの共通部品（スタッフ削除の確認ダイアログと同じ部品を使う）。
		wp_enqueue_script(
			'vkbm-guard-dialog',
			VKBM_PLUGIN_DIR_URL . 'assets/js/admin-guard-dialog.js',
			array(),
			VKBM_VERSION,
			true
		);

		wp_enqueue_script(
			'vkbm-booking-anonymize-bulk-action',
			VKBM_PLUGIN_DIR_URL . 'assets/js/booking-anonymize-bulk-action.js',
			array( 'vkbm-guard-dialog' ),
			VKBM_VERSION,
			true
		);

		wp_localize_script(
			'vkbm-booking-anonymize-bulk-action',
			'vkbmBookingAnonymize',
			array(
				'action' => self::ACTION,
				'i18n'   => $this->get_dialog_strings(),
			)
		);
	}

	/**
	 * 確認ダイアログの文言を返す。
	 *
	 * 消す欄の名前は、匿名化の対象の定義（{@see Booking_Anonymizer::get_meta_fields()}）から作る。
	 * 項目が増えても、ここを書き換えずに文言へ反映させるため。
	 * 1つの翻訳関数に複数の文を入れないため、本文は段落ごとにキーを分ける。
	 *
	 * @return array<string, string> JS へ渡す文言。%d は JS 側で選択件数に置き換える。
	 */
	public function get_dialog_strings(): array {
		return array(
			// %d は JS 側で選択件数に置き換える（PHP の sprintf は通さない）。
			/* translators: %d: Number of selected reservations. */
			'title'          => __( 'Anonymize personal data of %d selected reservation(s)?', 'vk-booking-manager' ),
			'bodyFields'     => sprintf(
				/* translators: %s: Names of the fields that are cleared, e.g. "customer name, telephone number". */
				__( 'This clears %s, and removes the link to the reserver.', 'vk-booking-manager' ),
				Booking_Anonymizer::get_field_labels_text()
			),
			'bodyAttachment' => __( 'Files uploaded to the History image field on the reservation edit screen are also deleted from the media library, and images chosen from the media library are only removed from the reservation.', 'vk-booking-manager' ),
			'bodyMyPage'     => __( 'The reservations will also no longer appear in the reservation list on My Page.', 'vk-booking-manager' ),
			'bodyKept'       => __( 'Date and time, menu, price, and reservation status are kept, so sales and booking counts do not change.', 'vk-booking-manager' ),
			'bodyFlags'      => __( 'Customer flags set for guests are kept, so they keep applying to new bookings.', 'vk-booking-manager' ),
			'bodyNotEnded'   => __( 'Reservations whose end date and time has not passed yet are not anonymized.', 'vk-booking-manager' ),
			'bodyIrrevers'   => __( 'This action cannot be undone.', 'vk-booking-manager' ),
			'cancel'         => __( 'Cancel', 'vk-booking-manager' ),
			'confirmButton'  => __( 'Anonymize', 'vk-booking-manager' ),
		);
	}

	/**
	 * 終了日時を過ぎていないため匿名化しなかった予約の通知を出す。
	 *
	 * 対象の予約のタイトルを編集画面へのリンクで並べる。上限を超える分は「ほか N 件」にまとめる。
	 *
	 * @param int[] $booking_ids 匿名化しなかった予約の投稿ID。
	 */
	private function render_not_ended_notice( array $booking_ids ): void {
		$count   = count( $booking_ids );
		$message = sprintf(
			/* translators: %s: Number of reservations. */
			_n( '%s reservation was not anonymized because its end date and time has not passed yet.', '%s reservations were not anonymized because their end date and time has not passed yet.', $count, 'vk-booking-manager' ),
			number_format_i18n( $count )
		);

		$items = '';
		foreach ( array_slice( $booking_ids, 0, self::NOT_ENDED_LIST_LIMIT ) as $booking_id ) {
			$title = get_the_title( (int) $booking_id );
			$title = '' !== $title ? $title : sprintf( '#%d', (int) $booking_id );
			$link  = get_edit_post_link( (int) $booking_id );

			$items .= '<li>' . ( $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title ) ) . '</li>';
		}

		$remaining = $count - self::NOT_ENDED_LIST_LIMIT;
		if ( $remaining > 0 ) {
			$items .= '<li>' . esc_html(
				sprintf(
					/* translators: %s: Number of reservations that are not listed. */
					__( 'and %s more', 'vk-booking-manager' ),
					number_format_i18n( $remaining )
				)
			) . '</li>';
		}

		printf(
			'<div class="notice notice-warning is-dismissible"><p>%1$s %2$s</p><ul>%3$s</ul></div>',
			esc_html( $message ),
			esc_html__( 'Run this again after the end date and time has passed.', 'vk-booking-manager' ),
			$items // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 各要素を上でエスケープ済み。
		);
	}

	/**
	 * 1段落の管理通知を出す。
	 *
	 * @param string $css_class WordPress の通知のクラス（notice-success / notice-warning / notice-info）。
	 * @param string $message   通知の文言。
	 * @param string $link      エスケープ済みの案内リンク。
	 */
	private function render_notice( string $css_class, string $message, string $link = '' ): void {
		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s%3$s</p></div>',
			esc_attr( $css_class ),
			esc_html( $message ),
			'' !== $link ? '<br>' . $link : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 呼び出し元でURLと文言をエスケープ済み。
		);
	}

	/**
	 * 結果を保存する transient のキーを返す（ユーザーごと）。
	 *
	 * @return string
	 */
	private function get_result_transient_key(): string {
		return self::RESULT_TRANSIENT_PREFIX . get_current_user_id();
	}

	/**
	 * 今開いているのが予約の一覧画面か判定する。
	 *
	 * @return bool
	 */
	private function is_booking_list_screen(): bool {
		$screen = get_current_screen();

		return $screen && 'edit-' . Booking_Post_Type::POST_TYPE === $screen->id;
	}

	/**
	 * 今開いているのがゴミ箱の一覧か判定する。
	 *
	 * @return bool
	 */
	private function is_trash_list(): bool {
		// 一覧の絞り込みを読むだけで、状態は変更しない。
		$post_status = isset( $_GET['post_status'] ) ? sanitize_key( wp_unslash( $_GET['post_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return in_array( $post_status, array( 'trash' ), true );
	}
}
