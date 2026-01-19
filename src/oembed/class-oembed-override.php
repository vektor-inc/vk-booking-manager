<?php
namespace VKBookingManager\OEmbed;

use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Override oEmbed title/author for the reservation page URL.
 */
class OEmbed_Override {
	public function register(): void {
		add_filter( 'oembed_response_data', [ $this, 'override_response' ], 10, 2 );
	}

	/**
	 * @param array<string, mixed> $data oEmbed response data.
	 * @param WP_Post              $post Post object.
	 * @return array<string, mixed>
	 */
	public function override_response( array $data, $post ): array {
		$repository = new Settings_Repository();
		$settings   = $repository->get_settings();
		$raw_url    = isset( $settings['reservation_page_url'] ) ? (string) $settings['reservation_page_url'] : '';
		$target_url = vkbm_normalize_reservation_page_url( $raw_url );

		if ( '' === $target_url ) {
			return $data;
		}

		$response_url = '';
		if ( isset( $data['url'] ) && is_string( $data['url'] ) ) {
			$response_url = $data['url'];
		} elseif ( $post instanceof WP_Post ) {
			$response_url = get_permalink( $post );
		}

		if ( '' === $response_url ) {
			return $data;
		}

		if ( $this->normalize_url_for_compare( $response_url ) !== $this->normalize_url_for_compare( $target_url ) ) {
			return $data;
		}

		$provider_name = isset( $settings['provider_name'] ) ? trim( (string) $settings['provider_name'] ) : '';
		if ( '' === $provider_name ) {
			$provider_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		}

		$data['title']       = sprintf( '%s Web reservation', $provider_name );
		$data['author_name'] = $provider_name;

		return $data;
	}

	/**
	 * Normalize URL for equality checks (strip query/fragment, trailing slash).
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	private function normalize_url_for_compare( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		$url = preg_replace( '/[#?].*$/', '', $url );
		$url = untrailingslashit( $url );

		return $url;
	}
}
