<?php
/**
 * リソース名の言語別保存と表示。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Multilingual;

use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\REST\Reservation_Content_Routes;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

/** リソースのタイトルを表示中の言語へ切り替える。 */
class Resource_Texts {
	public const META_KEY    = '_vkbm_resource_multilingual_texts';
	public const INPUT_NAME  = 'vkbm_resource_multilingual';
	public const FILTER_HOOK = 'vkbm_resource_text';

	/** REST の表示用応答にフックを登録する。 */
	public function register(): void {
		add_filter( 'rest_prepare_' . Resource_Post_Type::POST_TYPE, array( self::class, 'localize_rest_response' ), 10, 3 );
	}

	/**
	 * 保存済みのタイトルを取得する。
	 *
	 * @param int $post_id 投稿 ID。
	 * @return array<string, array<string, string>> 言語別の値。
	 */
	public static function get_stored( int $post_id ): array {
		$raw = get_post_meta( $post_id, self::META_KEY, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$stored = array();
		foreach ( $raw as $locale => $fields ) {
			$locale = Multilingual_Labels::sanitize_locale( $locale );
			if ( '' !== $locale && is_array( $fields ) && is_string( $fields['title'] ?? null ) && '' !== trim( $fields['title'] ) ) {
				$stored[ $locale ] = array( 'title' => trim( $fields['title'] ) );
			}
		}
		return $stored;
	}

	/**
	 * 登録済み言語のタイトルを基本値と照合する。
	 *
	 * @param int    $post_id 投稿 ID。
	 * @param string $base 基本のタイトル。
	 * @return string 表示用タイトル。
	 */
	public static function resolve( int $post_id, string $base ): string {
		$locale = determine_locale();
		$stored = self::get_stored( $post_id );
		$title  = in_array( $locale, Multilingual_Labels::get_locales(), true ) ? ( $stored[ $locale ]['title'] ?? '' ) : '';
		if ( '' === $title ) {
			$title = $base;
		}
		return (string) apply_filters( self::FILTER_HOOK, $title, 'title', $locale, $post_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- 定数に接頭辞付きフック名を保持する。
	}

	/**
	 * WordPress のタイトル整形を保持して言語別タイトルを返す。
	 *
	 * @param int|WP_Post $post リソース投稿。
	 * @return string 表示用タイトル。
	 */
	public static function get_title( $post ): string {
		$post = get_post( $post );
		if ( ! $post instanceof WP_Post || Resource_Post_Type::POST_TYPE !== $post->post_type ) {
			return '';
		}
		$localized             = clone $post;
		$localized->post_title = self::resolve( (int) $post->ID, (string) $post->post_title );
		return (string) get_the_title( $localized );
	}

	/**
	 * 予約画面の REST のタイトルだけを言語別へ変更する。
	 *
	 * @param mixed $response REST 応答。
	 * @param mixed $post 投稿。
	 * @param mixed $request REST リクエスト。
	 * @return mixed REST 応答。
	 */
	public static function localize_rest_response( $response, $post, $request ) {
		if ( ! $response instanceof WP_REST_Response || ! $post instanceof WP_Post || ! $request instanceof WP_REST_Request || 0 !== strpos( $request->get_route(), '/' . Reservation_Content_Routes::REST_NAMESPACE . '/' ) ) {
			return $response;
		}
		$data = $response->get_data();
		if ( is_array( $data ) && isset( $data['title']['rendered'] ) ) {
			$data['title']['rendered'] = wp_kses_post( self::get_title( $post ) );
			$response->set_data( $data );
		}
		return $response;
	}

	/**
	 * 言語別の入力をサニタイズする。
	 *
	 * @param mixed $raw 送信値。
	 * @return array<string, array<string, string>> 保存する値。
	 */
	public static function sanitize_input( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$values = array();
		foreach ( $raw as $locale => $fields ) {
			$locale = Multilingual_Labels::sanitize_locale( $locale );
			if ( '' === $locale || ! in_array( $locale, Multilingual_Labels::get_locales(), true ) || ! is_array( $fields ) ) {
				continue;
			}
			$values[ $locale ] = array();
			if ( is_scalar( $fields['title'] ?? null ) ) {
				$title = trim( sanitize_text_field( (string) $fields['title'] ) );
				if ( '' !== $title ) {
					$values[ $locale ]['title'] = $title;
				}
			}
		}
		return $values;
	}

	/**
	 * 送信した言語だけを更新する。
	 *
	 * @param int                                  $post_id 投稿 ID。
	 * @param array<string, array<string, string>> $submitted 送信値。
	 */
	public static function save( int $post_id, array $submitted ): void {
		$stored = self::get_stored( $post_id );
		foreach ( $submitted as $locale => $fields ) {
			unset( $stored[ $locale ] );
			if ( array() !== $fields ) {
				$stored[ $locale ] = $fields;
			}
		}
		if ( array() === $stored ) {
			delete_post_meta( $post_id, self::META_KEY );
		} else {
			update_post_meta( $post_id, self::META_KEY, $stored );
		}
	}
}
