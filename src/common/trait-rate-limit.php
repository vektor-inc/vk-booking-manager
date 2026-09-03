<?php
/**
 * Rate limit utility trait.
 *
 * IP単位での簡易レート制限を提供する共通トレイト。
 * 認証系（Auth_Shortcodes）とREST APIの公開エンドポイント
 * （Booking_Draft_Controller など）から再利用される。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Common;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use function apply_filters;
use function do_action;
use function get_transient;
use function in_array;
use function is_array;
use function preg_replace;
use function sanitize_text_field;
use function set_transient;
use function sha1;
use function substr;
use function time;
use function trim;
use function wp_cache_add;
use function wp_cache_get;
use function wp_cache_incr;
use function wp_cache_set;
use function wp_unslash;
use function wp_using_ext_object_cache;

/**
 * Provides shared IP-based rate limiting utilities.
 *
 * IPアドレス単位でのレート制限処理を提供する。
 * `consume_rate_limit_token()` を呼ぶと、指定されたウィンドウ内で
 * 許可された回数を超えた場合に false を返す。
 *
 * 内部実装は二経路のハイブリッド構成。
 * - 永続オブジェクトキャッシュが有効な環境では `wp_cache_add` / `wp_cache_incr`
 *   によるアトミックなインクリメントで race condition を回避する。
 * - そうでない環境では MySQL `GET_LOCK` で短期排他をかけて transient の
 *   read-modify-write を直列化する。
 *
 * Hybrid implementation:
 * - When a persistent object cache is in use, atomic increment via wp_cache_*
 *   is used to avoid the classic transient read-modify-write race.
 * - Otherwise, MySQL GET_LOCK is used to briefly serialize the legacy
 *   transient-based path so concurrent requests do not lose counter ticks.
 */
trait Rate_Limit_Trait {

	/**
	 * Object cache group for rate limit counters.
	 *
	 * オブジェクトキャッシュ経路で使用するキャッシュグループ名。
	 *
	 * @var string
	 */
	private static string $vkbm_rl_cache_group = 'vkbm_rate_limit';

	/**
	 * Consume a rate limit token for the given action.
	 *
	 * 指定アクションに対してレート制限トークンを1つ消費する。
	 * 上限を超えている場合は false を返し、呼び出し側で 429 などを返す想定。
	 *
	 * 内部では永続オブジェクトキャッシュの有無に応じて二経路に分岐し、
	 * いずれの場合もカウンタ更新が並列実行で取りこぼされないように工夫している。
	 *
	 * @param string $action Action key (e.g. login, register, draft).
	 * @param int    $max    Max attempts allowed within the window.
	 * @param int    $window Window length in seconds.
	 * @return bool True if the request is allowed, false if rate limited.
	 */
	protected function consume_rate_limit_token( string $action, int $max, int $window ): bool {
		// テスト等から無効化したい場合に使えるフィルタ。
		// Allows tests or custom integrations to bypass rate limiting per action.
		$enabled = apply_filters( 'vkbm_rate_limit_enabled', true, $action );
		if ( ! $enabled ) {
			return true;
		}

		// クライアントIPが特定できない場合は制限を行わない（誤検知防止）。
		$ip = $this->get_client_ip();
		if ( '' === $ip ) {
			return true;
		}

		// 上限・ウィンドウは正の値であることを保証する。
		if ( $max <= 0 || $window <= 0 ) {
			return true;
		}

		// アクション名とIPからキーの素となるハッシュを生成する。
		// transient / object cache / GET_LOCK のキー名はすべてこの hash を流用する。
		$hash = substr( sha1( $action . '|' . $ip ), 0, 20 );

		// 経路の自動判定。永続オブジェクトキャッシュが有効なら object_cache、
		// そうでなければ db_lock（GET_LOCK + transient フォールバック）を使う。
		$auto_storage = wp_using_ext_object_cache() ? 'object_cache' : 'db_lock';

		/**
		 * Filters the storage backend used for rate limit counters.
		 *
		 * レート制限カウンタの保存先（経路）を上書きするためのフィルタ。
		 * **テスト・診断用途を想定しており、外部での恒久利用は推奨しません。**
		 * 想定外の戻り値（`'object_cache'` / `'db_lock'` 以外）の場合は安全側に倒して
		 * 自動判定結果を採用する。
		 *
		 * Intended for tests and diagnostics; not recommended for permanent
		 * production overrides. Unknown values fall back to the auto-detected
		 * storage to keep behavior safe.
		 *
		 * @since 1.0.1
		 *
		 * @param string $auto_storage Auto-detected storage route. Either
		 *                             `'object_cache'` or `'db_lock'`.
		 * @param string $action       Action key passed to consume_rate_limit_token().
		 */
		$storage = apply_filters( 'vkbm_rate_limit_storage', $auto_storage, $action );
		if ( 'object_cache' !== $storage && 'db_lock' !== $storage ) {
			$storage = $auto_storage;
		}

		if ( 'object_cache' === $storage ) {
			return $this->consume_via_object_cache( $hash, $max, $window );
		}

		return $this->consume_via_db_lock( $action, $hash, $max, $window );
	}

	/**
	 * Consume a rate limit token via the WP object cache (atomic increment).
	 *
	 * 永続オブジェクトキャッシュ経路で1トークンを消費する。
	 * `wp_cache_add` で先着1名のみがカウンタ・ウィンドウキーを初期化し、
	 * 以降は `wp_cache_incr` でアトミックに +1 する。
	 *
	 * Counter key と Window key は同じ TTL（残り window 秒）で書き込み、
	 * ウィンドウ越境を検知したら同じくアトミックに再初期化する。
	 *
	 * @param string $hash   Hash derived from action and IP.
	 * @param int    $max    Max attempts allowed within the window.
	 * @param int    $window Window length in seconds.
	 * @return bool True if allowed, false if blocked.
	 */
	private function consume_via_object_cache( string $hash, int $max, int $window ): bool {
		$counter_key = 'cnt_' . $hash;
		$window_key  = 'win_' . $hash;
		$group       = self::$vkbm_rl_cache_group;
		$now         = time();

		// キャッシュ TTL は reset 値とのズレに耐性を持たせるため、window + 余裕(5秒)を入れる。
		// reset 自体（unix time）は window 秒後をそのまま使うが、TTL を少し長めにすることで、
		// バックエンドや時刻同期のズレで「reset は過去なのに TTL でキーが消滅しない」状態を吸収する。
		// Add a small safety margin so the cache TTL outlives the logical reset timestamp,
		// even if the cache backend or clock drifts slightly.
		$cache_ttl = $window + 5;

		// ウィンドウキー（リセット時刻）を取得する。未設定なら新規初期化フェーズに入る。
		$reset = wp_cache_get( $window_key, $group );
		$reset = is_numeric( $reset ) ? (int) $reset : 0;

		// ウィンドウ未設定 or 越境済みなら、wp_cache_add で原子的に初期化を試みる。
		// 同時実行で複数リクエストが入った場合でも、wp_cache_add で成功するのは1名のみ。
		if ( $reset <= $now ) {
			$new_reset = $now + $window;
			$added     = wp_cache_add( $window_key, $new_reset, $group, $cache_ttl );
			if ( $added ) {
				// 自分が先着の初期化担当だった場合のみカウンタを 0 で作成する。
				wp_cache_add( $counter_key, 0, $group, $cache_ttl );
				$reset = $new_reset;
			} else {
				// 別リクエストが先に初期化していた。最新の reset を取り直す。
				$current_reset = wp_cache_get( $window_key, $group );
				$reset         = is_numeric( $current_reset ) ? (int) $current_reset : 0;

				// 救済パス: 取り直した reset がそれでも過去（または不正な値）の場合、
				// バックエンドの TTL とウィンドウ reset 値の同期が崩れている可能性がある。
				// この状態を放置するとウィンドウが永久にリセットされないため、
				// wp_cache_set で強制上書きし、カウンタも 0 にリセットする。
				// Recovery path: if the freshly-read reset is still in the past (or invalid),
				// the cache TTL and the logical reset have drifted apart. Force a fresh
				// window via wp_cache_set so we never get stuck without resetting.
				if ( $reset <= $now ) {
					wp_cache_set( $window_key, $new_reset, $group, $cache_ttl );
					wp_cache_set( $counter_key, 0, $group, $cache_ttl );
					$reset = $new_reset;
				}
			}
		}

		// アトミックに +1。
		// 何らかの理由（キャッシュ削除直後など）で 0 のカウンタが消えていた場合は
		// wp_cache_add で再生成してから再 incr する。
		$incremented = wp_cache_incr( $counter_key, 1, $group );
		if ( false === $incremented ) {
			// 再生成時の TTL も window_key と揃える（reset 値とのズレを最小化）。
			wp_cache_add( $counter_key, 0, $group, max( 1, $reset - $now + 5 ) );
			$incremented = wp_cache_incr( $counter_key, 1, $group );
			if ( false === $incremented ) {
				// それでもダメな場合は安全側に倒して許可しておく（誤検知防止優先）。
				return true;
			}
		}

		// 上限を超えていたら拒否。カウンタは取り消さない（次の window で自然に切り替わる）。
		if ( (int) $incremented > $max ) {
			return false;
		}

		return true;
	}

	/**
	 * Consume a rate limit token via DB lock + transient.
	 *
	 * 永続オブジェクトキャッシュが無い環境で使うフォールバック経路。
	 * MySQL `GET_LOCK` で短時間ロックを取り、ロック区間内で
	 * transient の read-modify-write を直列化する。
	 *
	 * ロック取得に失敗した場合は、レート制限を強制せず許可（true）を返す。
	 * これは現状の「IP が取れない場合は許可」と同じ「誤検知防止優先」の方針に合わせている。
	 * ただし、攻撃者が意図的にロックを保持してレート制限を実質無効化する経路は
	 * 監視できるよう、取得失敗時には `vkbm_rate_limit_lock_contention` アクションを発火する。
	 *
	 * @param string $action Action key. Used for monitoring/observability only.
	 * @param string $hash   Hash derived from action and IP.
	 * @param int    $max    Max attempts allowed within the window.
	 * @param int    $window Window length in seconds.
	 * @return bool True if allowed, false if blocked.
	 */
	private function consume_via_db_lock( string $action, string $hash, int $max, int $window ): bool {
		global $wpdb;

		$transient_key = 'vkbm_rl_' . $hash;
		// GET_LOCK は 64 文字以内（MySQL 5.7 以降）。`vkbm_rl_` (8) + hash (20) で 28 文字なので余裕。
		$lock_name = 'vkbm_rl_' . $hash;
		$now       = time();

		$got_lock = null;
		if ( isset( $wpdb ) && is_object( $wpdb ) ) {
			// タイムアウト 0 秒 = 即時取得を試みる。
			// $wpdb->prepare で SQL インジェクション対策を行う。
			$got_lock = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		// 取得失敗 (NULL = エラー、'0' = タイムアウト) なら許可して終わる。
		// 現状仕様（IP が取れない場合 true）と整合させた誤検知防止優先のフォールバック。
		if ( null === $got_lock || '1' !== (string) $got_lock ) {
			/**
			 * Fires when GET_LOCK acquisition fails on the db_lock route.
			 *
			 * 監視・観測用フック。攻撃者が意図的にロックを保持してレート制限を
			 * 無効化する経路を検知するため、取得失敗時にこのアクションを発火する。
			 * IP の直接露出を避けるため、引数は sha1 由来の hash のままにしている。
			 *
			 * Observability hook for monitoring potential lock-holding attacks
			 * that bypass rate limiting. The hash argument is the sha1-derived
			 * identifier (not the raw IP) for privacy.
			 *
			 * @since 1.0.1
			 *
			 * @param string $action Action key (e.g. 'login', 'draft_save').
			 * @param string $hash   sha1-derived hash for the action+ip pair.
			 */
			do_action( 'vkbm_rate_limit_lock_contention', $action, $hash );
			return true;
		}

		try {
			$state = get_transient( $transient_key );
			if ( ! is_array( $state ) ) {
				$state = array(
					'count' => 0,
					'reset' => $now + $window,
				);
			}

			$reset = isset( $state['reset'] ) ? (int) $state['reset'] : 0;
			$count = isset( $state['count'] ) ? (int) $state['count'] : 0;

			// ウィンドウを過ぎていればカウンタをリセットする。
			if ( $reset <= $now ) {
				$reset = $now + $window;
				$count = 0;
			}

			// 上限到達済みなら現在の状態を保持したまま拒否する。
			if ( $count >= $max ) {
				$ttl = max( 1, $reset - $now );
				set_transient(
					$transient_key,
					array(
						'count' => $count,
						'reset' => $reset,
					),
					$ttl
				);
				return false;
			}

			// 通常時はカウンタを +1 して保存する。
			++$count;
			$ttl = max( 1, $reset - $now );
			set_transient(
				$transient_key,
				array(
					'count' => $count,
					'reset' => $reset,
				),
				$ttl
			);

			return true;
		} finally {
			// 例外発生時にもロックを必ず解放する。
			if ( isset( $wpdb ) && is_object( $wpdb ) ) {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
	}

	/**
	 * Get client IP address for rate limiting purposes.
	 *
	 * レート制限用のクライアントIPを取得する。
	 * 信頼できるプロキシ経由のリクエストの場合のみ X-Forwarded-For を採用する。
	 * `vkbm_trusted_proxy_ips` フィルタで信頼するプロキシIPを指定可能。
	 *
	 * @return string
	 */
	protected function get_client_ip(): string {
		$remote_addr = '';

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$remote_addr = trim( sanitize_text_field( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) );
		}

		// IPアドレスとして妥当な文字のみ許可する。
		$remote_addr = (string) preg_replace( '/[^0-9a-fA-F:\\.]/', '', $remote_addr );

		$trusted_proxies = apply_filters( 'vkbm_trusted_proxy_ips', array() );
		if ( ! is_array( $trusted_proxies ) ) {
			$trusted_proxies = array();
		}

		$forwarded_ip = '';
		if (
			'' !== $remote_addr
			&& ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] )
			&& in_array( $remote_addr, $trusted_proxies, true )
		) {
			// 信頼できるプロキシ経由の場合のみ XFF を採用する。
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
			$candidates   = explode( ',', sanitize_text_field( (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			$forwarded_ip = trim( (string) ( $candidates[0] ?? '' ) );
			$forwarded_ip = (string) preg_replace( '/[^0-9a-fA-F:\\.]/', '', $forwarded_ip );
		}

		$ip = '' !== $forwarded_ip ? $forwarded_ip : $remote_addr;

		return is_string( $ip ) ? $ip : '';
	}
}
