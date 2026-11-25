<?php
/**
 * プラン（サービスメニュー）の言語ごとのタイトル・抜粋・その他条件の保存と、表示する値の決め方。
 *
 * 仕様は docs/specification-multilingual.md を正とする。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Multilingual;

use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\REST\Reservation_Content_Routes;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

use function add_filter;
use function apply_filters;
use function array_key_exists;
use function delete_post_meta;
use function determine_locale;
use function get_post;
use function get_post_meta;
use function get_the_excerpt;
use function get_the_title;
use function in_array;
use function is_array;
use function is_scalar;
use function is_string;
use function post_password_required;
use function remove_filter;
use function sanitize_text_field;
use function sanitize_textarea_field;
use function strpos;
use function trim;
use function update_post_meta;
use function wp_kses_post;
use function wp_slash;

/**
 * プランの言語ごとの文言を扱う。
 *
 * 保存先は post meta `_vkbm_multilingual_texts`。
 * `array( 'en_US' => array( 'title' => 'Cut', 'excerpt' => '...', 'other_conditions' => '...' ) )` の形で、入力のある項目だけを保存する。
 * 表示には「多言語設定」タブで追加済みの言語の値だけを使う（タブで削除した言語の値は残っていても使わない）。
 */
class Service_Menu_Texts {
	/**
	 * 言語ごとの文言を保存する post meta のキー。
	 */
	public const META_KEY = '_vkbm_multilingual_texts';

	/**
	 * 編集画面の入力欄の name の先頭（`vkbm_service_menu_multilingual[en_US][title]`）。
	 */
	public const INPUT_NAME = 'vkbm_service_menu_multilingual';

	/**
	 * 表示する文言を通すフィルターフックの名前（呼び方の `vkbm_label` に揃える）。
	 */
	public const FILTER_HOOK = 'vkbm_service_menu_text';

	/**
	 * 言語ごとに登録できる項目のキー。並びは編集画面の欄の並びと同じにする。
	 */
	public const FIELD_KEYS = array( 'title', 'excerpt', 'other_conditions' );

	/**
	 * 基本の「その他条件」の post meta のキー。
	 */
	private const META_OTHER_CONDITIONS = '_vkbm_other_conditions';

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'rest_prepare_' . Service_Menu_Post_Type::POST_TYPE, array( self::class, 'localize_rest_response' ), 10, 3 );
	}

	/**
	 * 保存済みの言語ごとの文言を、形を整えて返す（削除済みの言語の値も含む）。
	 *
	 * @param int $post_id プランの投稿 ID。
	 * @return array<string, array<string, string>> ロケール => （項目のキー => 値）。値が空の項目・言語は含めない。
	 */
	public static function get_stored( int $post_id ): array {
		$stored = get_post_meta( $post_id, self::META_KEY, true );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$texts = array();
		foreach ( $stored as $locale => $values ) {
			$locale = Multilingual_Labels::sanitize_locale( $locale );
			if ( '' === $locale || ! is_array( $values ) ) {
				continue;
			}

			// 直接書き換えられた meta に備えて、文字列で空でない値だけを使う。
			foreach ( self::FIELD_KEYS as $key ) {
				if ( isset( $values[ $key ] ) && is_string( $values[ $key ] ) && '' !== trim( $values[ $key ] ) ) {
					$texts[ $locale ][ $key ] = trim( $values[ $key ] );
				}
			}
		}

		return $texts;
	}

	/**
	 * 指定した言語で登録した文言を返す。
	 *
	 * @param int    $post_id プランの投稿 ID。
	 * @param string $locale  ロケール（例: en_US）。
	 * @param string $key     項目のキー（FIELD_KEYS のいずれか）。
	 * @return string 登録した値。「多言語設定」タブに無い言語・空欄・対象外のキーなら空文字。
	 */
	public static function get_value( int $post_id, string $locale, string $key ): string {
		if ( '' === $locale || ! in_array( $key, self::FIELD_KEYS, true ) || ! in_array( $locale, Multilingual_Labels::get_locales(), true ) ) {
			return '';
		}

		$texts = self::get_stored( $post_id );

		return $texts[ $locale ][ $key ] ?? '';
	}

	/**
	 * 表示する文言を「表示中の言語で登録した値 → 基本の値」の順で決め、フィルターフックに通して返す。
	 *
	 * 表示中の言語は determine_locale() で判定する（呼び方の vkbm_resolve_label() と同じ）。
	 *
	 * @param int    $post_id プランの投稿 ID。
	 * @param string $key     項目のキー（FIELD_KEYS のいずれか）。
	 * @param string $base    基本の値（投稿タイトル・抜粋・`_vkbm_other_conditions`）。
	 * @return string 表示する文言（出力用の安全化はしていない）。
	 */
	public static function resolve( int $post_id, string $key, string $base ): string {
		$locale = determine_locale();
		$text   = self::get_value( $post_id, $locale, $key );

		// 表示中の言語の値が無ければ、基本の値を使う。
		if ( '' === $text ) {
			$text = $base;
		}

		/**
		 * 表示するプランのタイトル・抜粋・その他条件を差し替える。
		 *
		 * WPML・Polylang など、Bogo 以外の多言語化プラグインを使うサイトで、言語に合わせた文言へ差し替えるときに使う。
		 *
		 * @param string $text    表示する文言（表示中の言語で登録した値 → 基本の値の順で決めた値）。
		 * @param string $key     項目のキー（title・excerpt・other_conditions）。
		 * @param string $locale  表示中のロケール（determine_locale() の値。例: en_US）。
		 * @param int    $post_id プランの投稿 ID。
		 */
		return (string) apply_filters( 'vkbm_service_menu_text', $text, $key, $locale, $post_id ); // フック名は FILTER_HOOK と揃える。
	}

	/**
	 * タイトル・抜粋を表示中の言語の値に置き換えた投稿を返す。
	 *
	 * get_the_title()・get_the_excerpt() にそのまま渡せるよう、複製した投稿の post_title・post_excerpt を置き換える
	 * （「非公開:」の付け方・the_title などのフィルター・抜粋の自動生成は、基本の値と同じ処理を通る）。
	 * キャッシュ上の投稿を書き換えないよう、置き換えるときは必ず複製する。
	 *
	 * @param WP_Post $post プランの投稿。
	 * @return WP_Post 置き換えた投稿。置き換える値が無ければ渡した投稿そのもの。
	 */
	public static function localize_post( WP_Post $post ): WP_Post {
		if ( Service_Menu_Post_Type::POST_TYPE !== $post->post_type ) {
			return $post;
		}

		$title   = self::resolve( (int) $post->ID, 'title', (string) $post->post_title );
		$excerpt = self::resolve( (int) $post->ID, 'excerpt', (string) $post->post_excerpt );
		if ( $title === $post->post_title && $excerpt === $post->post_excerpt ) {
			return $post;
		}

		$localized               = clone $post;
		$localized->post_title   = $title;
		$localized->post_excerpt = $excerpt;

		return $localized;
	}

	/**
	 * 表示するプランのタイトルを返す（get_the_title() と同じ整形）。
	 *
	 * @param int|WP_Post $post プランの投稿または投稿 ID。
	 * @return string タイトル（出力用の安全化はしていない）。投稿が無ければ空文字。
	 */
	public static function get_title( $post ): string {
		$post = get_post( $post );
		if ( ! $post instanceof WP_Post ) {
			return '';
		}

		return (string) get_the_title( self::localize_post( $post ) );
	}

	/**
	 * 表示するプランの「その他条件」を返す。
	 *
	 * @param int $post_id プランの投稿 ID。
	 * @return string その他条件（出力用の安全化はしていない）。
	 */
	public static function get_other_conditions( int $post_id ): string {
		$base = get_post_meta( $post_id, self::META_OTHER_CONDITIONS, true );

		return self::resolve( $post_id, 'other_conditions', is_string( $base ) ? $base : '' );
	}

	/**
	 * 予約画面が読む REST（vkbm/v1）の応答の title.rendered・excerpt.rendered を、表示中の言語の値にする。
	 *
	 * wp/v2（ブロックエディタなど管理画面が読む REST）の応答は変えない。
	 * 値の組み立ては WordPress 本体の WP_REST_Posts_Controller::prepare_item_for_response() に揃える。
	 *
	 * @param WP_REST_Response $response 応答。
	 * @param WP_Post          $post     プランの投稿。
	 * @param WP_REST_Request  $request  リクエスト。
	 * @return WP_REST_Response 応答。
	 */
	public static function localize_rest_response( $response, $post, $request ) {
		if ( ! $response instanceof WP_REST_Response || ! $post instanceof WP_Post || ! $request instanceof WP_REST_Request ) {
			return $response;
		}

		// 予約画面の REST（vkbm/v1）だけを対象にする。
		if ( 0 !== strpos( $request->get_route(), '/' . Reservation_Content_Routes::REST_NAMESPACE . '/' ) ) {
			return $response;
		}

		$localized = self::localize_post( $post );
		if ( $localized === $post ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return $response;
		}

		// 本体と同じく、パスワード保護の「保護中:」を付けずにタイトルを組み立てる。
		if ( isset( $data['title'] ) && is_array( $data['title'] ) && array_key_exists( 'rendered', $data['title'] ) ) {
			$no_prefix = static function (): string {
				return '%s';
			};
			add_filter( 'protected_title_format', $no_prefix );
			$data['title']['rendered'] = get_the_title( $localized );
			remove_filter( 'protected_title_format', $no_prefix );
		}

		if ( isset( $data['excerpt'] ) && is_array( $data['excerpt'] ) && array_key_exists( 'rendered', $data['excerpt'] ) ) {
			/** This filter is documented in wp-includes/post-template.php */
			$excerpt = apply_filters( 'get_the_excerpt', $localized->post_excerpt, $localized ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress 本体のフック.
			/** This filter is documented in wp-includes/post-template.php */
			$excerpt = apply_filters( 'the_excerpt', $excerpt ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress 本体のフック.

			$data['excerpt']['rendered'] = post_password_required( $post ) ? '' : $excerpt;
		}

		$response->set_data( $data );

		return $response;
	}

	/**
	 * 編集画面から送られた値を、保存用の形にする。
	 *
	 * - 「多言語設定」タブで追加済みの言語だけを受け付ける。
	 * - タイトルは sanitize_text_field()、その他条件は sanitize_textarea_field()（基本の欄と同じ）。
	 * - 抜粋は、HTML を自由に書けない利用者なら wp_kses_post()（WordPress 本体の抜粋の保存と同じ）。
	 * - 前後の空白を除いて空になった項目は保存しない（空欄は基本の値で表示するため）。
	 *
	 * @param mixed    $raw                  送信値（`vkbm_service_menu_multilingual`。wp_unslash 済み）。
	 * @param string[] $locales              追加済みの言語。
	 * @param bool     $allow_unfiltered_html 抜粋に HTML を自由に書ける利用者か（unfiltered_html 権限）。
	 * @return array<string, array<string, string>> ロケール => （項目のキー => 値）。送られた言語は、全部空でも空の配列で含める。
	 */
	public static function sanitize_input( $raw, array $locales, bool $allow_unfiltered_html ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$texts = array();
		foreach ( $raw as $locale => $values ) {
			$locale = Multilingual_Labels::sanitize_locale( $locale );
			if ( '' === $locale || ! in_array( $locale, $locales, true ) || ! is_array( $values ) ) {
				continue;
			}

			$texts[ $locale ] = array();
			foreach ( self::FIELD_KEYS as $key ) {
				if ( ! isset( $values[ $key ] ) || ! is_scalar( $values[ $key ] ) ) {
					continue;
				}

				$value = self::sanitize_value( $key, (string) $values[ $key ], $allow_unfiltered_html );
				if ( '' !== $value ) {
					$texts[ $locale ][ $key ] = $value;
				}
			}
		}

		return $texts;
	}

	/**
	 * 保存済みの値に、編集画面から送られた言語の値を上書きした結果を返す。
	 *
	 * 送られた言語は送られた値で置き換える（空欄にした項目は消える）。
	 * 送られなかった言語（タブで削除した言語など）の値は残す。
	 *
	 * @param array<string, array<string, string>> $stored    保存済みの値（get_stored() の値）。
	 * @param array<string, array<string, string>> $submitted 送られた値（sanitize_input() の値）。
	 * @return array<string, array<string, string>> 保存する値。値が空の言語は含めない。
	 */
	public static function merge( array $stored, array $submitted ): array {
		foreach ( $submitted as $locale => $values ) {
			unset( $stored[ $locale ] );
			if ( array() !== $values ) {
				$stored[ $locale ] = $values;
			}
		}

		return $stored;
	}

	/**
	 * 言語ごとの文言を保存する。値が 1 つも無ければ post meta を消す。
	 *
	 * @param int                                  $post_id プランの投稿 ID。
	 * @param array<string, array<string, string>> $texts   保存する値（merge() の値）。
	 * @return void
	 */
	public static function save( int $post_id, array $texts ): void {
		if ( array() === $texts ) {
			delete_post_meta( $post_id, self::META_KEY );
			return;
		}

		// update_post_meta() は渡された値を unslash するため、先に slash して「\」が消えないようにする。
		update_post_meta( $post_id, self::META_KEY, wp_slash( $texts ) );
	}

	/**
	 * 1 項目分の送信値をサニタイズする。
	 *
	 * @param string $key                   項目のキー。
	 * @param string $value                 送信値。
	 * @param bool   $allow_unfiltered_html 抜粋に HTML を自由に書ける利用者か。
	 * @return string サニタイズし、前後の空白を除いた値。
	 */
	private static function sanitize_value( string $key, string $value, bool $allow_unfiltered_html ): string {
		if ( 'title' === $key ) {
			return trim( sanitize_text_field( $value ) );
		}

		if ( 'other_conditions' === $key ) {
			return trim( sanitize_textarea_field( $value ) );
		}

		return trim( $allow_unfiltered_html ? $value : wp_kses_post( $value ) );
	}
}
