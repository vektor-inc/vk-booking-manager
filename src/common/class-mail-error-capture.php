<?php
/**
 * wp_mail() の送信結果とエラー文を、直前の送信と取り違えずに取得するための共通ヘルパー。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Common;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Error;
use function add_action;
use function remove_action;
use function __;

/**
 * wp_mail() 呼び出しの直前・直後だけ wp_mail_failed を購読し、
 * 「今回の送信」が失敗したかどうかを確かめてからエラー文を読み取る。
 *
 * 仮予約など複数のメールを連続して送る場面で、直前の別送信の失敗理由を
 * 誤って今回の結果として記録しないようにするための仕組み。
 * エラー文は「今回」の wp_mail_failed が渡す WP_Error のメッセージ → PHPMailer の
 * ErrorInfo → 「不明なエラー」の優先順位で採用する（レビュー対応 #510: SMTP 系
 * プラグインが wp_mail() を差し替える環境では、グローバル $phpmailer に前回送信分の
 * ErrorInfo が残ったままのことがあるため、今回確実に捕捉できた WP_Error のメッセージを
 * 優先する）。
 */
class Mail_Error_Capture {
	/**
	 * コールバックを実行して wp_mail() を送信し、成否とエラー文をまとめて返す。
	 *
	 * @param callable $send_callback wp_mail() を呼び出し、その戻り値（bool）をそのまま返すコールバック。
	 * @return array{sent:bool,error:string} 送信できたか、失敗時のエラー文（成功時は空文字）。
	 */
	public static function send( callable $send_callback ): array {
		// 今回の送信中に wp_mail_failed が発火したかどうかを記録するためのフラグ。
		$failed_during_this_call = false;
		// wp_mail_failed が渡す WP_Error のメッセージ（最優先で使う。空の場合のみ PHPMailer の ErrorInfo で代替する）。
		$captured_error_message = '';

		$on_wp_mail_failed = static function ( $wp_error ) use ( &$failed_during_this_call, &$captured_error_message ): void {
			$failed_during_this_call = true;

			if ( $wp_error instanceof WP_Error ) {
				$message = $wp_error->get_error_message();
				if ( '' !== $message ) {
					$captured_error_message = $message;
				}
			}
		};

		// 今回の送信の間だけ購読する（他の送信のエラーを拾わないよう、直後に必ず外す）。
		add_action( 'wp_mail_failed', $on_wp_mail_failed );

		try {
			$sent = (bool) $send_callback();
		} finally {
			remove_action( 'wp_mail_failed', $on_wp_mail_failed );
		}

		if ( $sent ) {
			return array(
				'sent'  => true,
				'error' => '',
			);
		}

		if ( ! $failed_during_this_call ) {
			// wp_mail_failed が発火しないまま失敗した場合（pre_wp_mail フィルターによる
			// 短絡など）は、今回の失敗理由を確認できないため、直前の送信のグローバル状態
			// （$phpmailer->ErrorInfo）を誤って使わず「不明なエラー」とする。
			return array(
				'sent'  => false,
				'error' => __( 'Unknown error', 'vk-booking-manager' ),
			);
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property name.
		global $phpmailer;

		// レビュー対応 #510: 今回の wp_mail_failed で確実に捕捉できたメッセージを最優先する。
		// PHPMailer の ErrorInfo は、SMTP 系プラグインが wp_mail() 自体を差し替える環境だと
		// 今回の送信で更新されず、グローバル $phpmailer に前回送信分が残ったままのことがある。
		$error = '';
		if ( '' !== $captured_error_message ) {
			$error = $captured_error_message;
		} elseif ( isset( $phpmailer ) && is_object( $phpmailer ) && isset( $phpmailer->ErrorInfo ) && '' !== $phpmailer->ErrorInfo ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property name.
			$error = (string) $phpmailer->ErrorInfo; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property name.
		} else {
			$error = __( 'Unknown error', 'vk-booking-manager' );
		}

		return array(
			'sent'  => false,
			'error' => $error,
		);
	}
}
