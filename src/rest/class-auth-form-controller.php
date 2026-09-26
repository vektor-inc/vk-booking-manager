<?php
/**
 * REST controller for authentication forms.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\Auth\Auth_Shortcodes;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use function add_action;
use function esc_url_raw;
use function get_option;
use function sanitize_key;
use function sanitize_text_field;
use function is_user_logged_in;
use function wp_validate_redirect;

/**
 * Provides login / registration form markup for the frontend block.
 */
class Auth_Form_Controller {
	private const NAMESPACE = 'vkbm/v1';

	/**
	 * Auth shortcodes handler.
	 *
	 * @var Auth_Shortcodes
	 */
	private $shortcodes;

	/**
	 * Constructor.
	 *
	 * @param Auth_Shortcodes $shortcodes Auth shortcodes handler.
	 */
	public function __construct( Auth_Shortcodes $shortcodes ) {
		$this->shortcodes = $shortcodes;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes(): void {
		// Publicly readable: the login / registration form markup must be available
		// to anonymous visitors. Mode-specific authorization (e.g. profile requires
		// login, register requires registrations to be open) is handled inside the
		// callback because it depends on the `type` parameter.
		// 公開情報のため誰でも参照可能。login/register フォームHTMLは未ログインユーザーにも返す必要がある。
		// type パラメータ次第で挙動が変わるため、login 必須等のチェックはコールバック内で個別に行う。
		register_rest_route(
			self::NAMESPACE,
			'/auth-form',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_form' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					// issue #512 レビュー対応（安藤さん指摘）: `error` 引数を self-describing
					// にするための宣言。あえて `enum` は使わない。`enum` はホワイトリスト外の
					// 値を REST 層で 400 として弾いてしまい、フォーム取得そのものが失敗する
					// （JS 側は「フォームを表示できませんでした」という汎用エラーになり、
					// ログインフォーム自体が出せなくなる）。承認済み仕様は「一覧に無い
					// コードは無視して何も表示しない」（200 でフォームは正常に返す）ことを
					// 求めているため、ホワイトリスト判定は
					// Auth_Shortcodes::get_login_error_message() に委ね、ここでは
					// 型とサニタイズだけを宣言する。
					'error' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'description'       => sprintf(
							/* translators: %s: comma-separated list of recognized login error codes. */
							__( 'Login error code from the initial page render. Recognized values: %s. Unrecognized values are ignored and no message is shown.', 'vk-booking-manager' ),
							implode( ', ', $this->shortcodes->get_login_error_codes() )
						),
					),
				),
			)
		);
	}

	/**
	 * Return form markup for the requested mode.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_form( WP_REST_Request $request ): WP_REST_Response {
		$type         = sanitize_text_field( (string) $request->get_param( 'type' ) );
		$redirect     = $this->sanitize_url_param( (string) $request->get_param( 'redirect' ) );
		$action_url   = $this->sanitize_url_param( (string) $request->get_param( 'action_url' ) );
		$login_url    = $this->sanitize_url_param( (string) $request->get_param( 'login_url' ) );
		$register_url = $this->sanitize_url_param( (string) $request->get_param( 'register_url' ) );
		// issue #512: `vkbm_login_error` Cookie の代わりに、フロントが初回の auth-form
		// 取得時だけ渡すログイン失敗コード。ホワイトリスト外は
		// Auth_Shortcodes::render_login_form() 側で無視され、何も表示されない。
		$error_code = sanitize_key( (string) $request->get_param( 'error' ) );

		if ( '' === $action_url ) {
			$action_url = $redirect;
		}

		$markup = '';

		if ( 'register' === $type ) {
			if ( ! get_option( 'users_can_register' ) ) {
				return new WP_REST_Response(
					array(
						'html'    => '',
						'message' => __( 'We are currently not accepting user registration.', 'vk-booking-manager' ),
					),
					403
				);
			}

			$markup = $this->shortcodes->render_registration_form(
				array_filter(
					array(
						'redirect'   => $redirect,
						'login_url'  => $login_url,
						'auto_login' => 'false',
						'action_url' => $action_url,
					)
				)
			);
		} elseif ( 'profile' === $type ) {
			if ( ! is_user_logged_in() ) {
				return new WP_REST_Response(
					array(
						'html' => '',
					),
					401
				);
			}

			$markup = $this->shortcodes->render_profile_form(
				array_filter(
					array(
						'redirect'   => $redirect,
						'action_url' => $action_url,
					)
				)
			);
		} else {
			$markup = $this->shortcodes->render_login_form(
				array_filter(
					array(
						'redirect'                => $redirect,
						'register_url'            => $register_url,
						'show_lost_password_link' => 'true',
						'lost_password_url'       => wp_lostpassword_url( $redirect ),
						'action_url'              => $action_url,
						'error_code'              => $error_code,
					)
				)
			);
		}

		$response = new WP_REST_Response(
			array(
				'html' => $markup,
			)
		);
		$response->set_headers(
			array(
				'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
			)
		);

		return $response;
	}

	/**
	 * Sanitize URL parameters passed from the frontend.
	 *
	 * @param string $url Raw URL value.
	 * @return string
	 */
	private function sanitize_url_param( string $url ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		$sanitized = esc_url_raw( $url );
		if ( '' === $sanitized ) {
			return '';
		}

		$validated = wp_validate_redirect( $sanitized, '' );

		return '' !== $validated ? $validated : '';
	}
}
