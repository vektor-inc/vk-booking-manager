<?php
/**
 * 予約画面の REST を、ページの言語で処理する（#1186）。
 *
 * 仕様は docs/specification-multilingual.md を正とする。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Multilingual;

use WP_REST_Request;

use function add_filter;
use function array_merge;
use function determine_locale;
use function get_available_languages;
use function in_array;
use function is_string;
use function restore_previous_locale;
use function strpos;
use function switch_to_locale;

/**
 * 予約画面から届いた REST に `vkbm_locale` があれば、その言語へ切り替えてから処理する。
 *
 * apiFetch の通信先（/wp-json/）にはページの言語が含まれないため、Bogo の英語ページでも REST はサイトの言語で処理され、
 * 呼び方（vkbm_get_menu_label() など）がサイトの言語の値になる。予約画面の JavaScript（src/blocks/shared/rest-locale.js）が
 * ページの言語を添えるので、その言語へ switch_to_locale() してから処理し、処理後に元へ戻す。
 *
 * 切り替えるのは、このプラグインの REST（/vkbm/v1/）のうち、読み取り（GET・HEAD）と、確認画面へ進むときの下書きの作成（POST /vkbm/v1/drafts）だけ。
 * 予約の確定など通知メールを送る処理は切り替えない（メールの言語は別の issue で、予約の言語に合わせて切り替える）。
 */
class Rest_Locale {
	/**
	 * ページの言語を受け取るパラメータ名。
	 */
	public const PARAM = 'vkbm_locale';

	/**
	 * このプラグインの REST の名前空間（先頭）。
	 */
	private const ROUTE_PREFIX = '/vkbm/v1/';

	/**
	 * 読み取り以外で切り替える REST（メソッド => ルート）。
	 */
	private const SWITCHABLE_WRITE_ROUTES = array(
		'POST' => array( '/vkbm/v1/drafts' ),
	);

	/**
	 * この REST の処理で言語を切り替えたか。
	 *
	 * @var bool
	 */
	private bool $switched = false;

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'rest_pre_dispatch', array( $this, 'maybe_switch_locale' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( $this, 'maybe_restore_locale' ), 10, 1 );
	}

	/**
	 * 対象の REST なら、添えられた言語へ切り替える。
	 *
	 * @param mixed           $result  先に決まった応答（そのまま返す）。
	 * @param mixed           $server  REST サーバー（使わない）。
	 * @param WP_REST_Request $request リクエスト。
	 * @return mixed 受け取った $result。
	 */
	public function maybe_switch_locale( $result, $server, $request ) {
		unset( $server );
		if ( null !== $result || ! $request instanceof WP_REST_Request ) {
			return $result;
		}

		$locale = self::resolve_locale( $request->get_method(), $request->get_route(), $request->get_param( self::PARAM ) );
		if ( '' !== $locale && switch_to_locale( $locale ) ) {
			$this->switched = true;
		}

		return $result;
	}

	/**
	 * 言語を切り替えていたら元に戻す。
	 *
	 * @param mixed $response 応答（そのまま返す）。
	 * @return mixed 受け取った $response。
	 */
	public function maybe_restore_locale( $response ) {
		if ( $this->switched ) {
			restore_previous_locale();
			$this->switched = false;
		}

		return $response;
	}

	/**
	 * 切り替え先の言語を決める。
	 *
	 * @param string $method HTTP メソッド。
	 * @param string $route  ルート（例: /vkbm/v1/provider-settings）。
	 * @param mixed  $raw    `vkbm_locale` の値。
	 * @return string 切り替える言語。切り替えないなら空文字。
	 */
	public static function resolve_locale( string $method, string $route, $raw ): string {
		if ( 0 !== strpos( $route, self::ROUTE_PREFIX ) ) {
			return '';
		}

		$is_readable = in_array( $method, array( 'GET', 'HEAD' ), true );
		if ( ! $is_readable && ! in_array( $route, self::SWITCHABLE_WRITE_ROUTES[ $method ] ?? array(), true ) ) {
			return '';
		}

		$locale = Multilingual_Labels::sanitize_locale( is_string( $raw ) ? $raw : '' );
		// 今と同じ言語・このサイトに無い言語へは切り替えない。
		if ( '' === $locale || determine_locale() === $locale ) {
			return '';
		}
		if ( ! in_array( $locale, array_merge( array( 'en_US' ), get_available_languages() ), true ) ) {
			return '';
		}

		return $locale;
	}
}
