<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Common;

use VKBookingManager\Common\Rate_Limit_Trait;
use WP_UnitTestCase;
use function add_action;
use function add_filter;
use function delete_transient;
use function remove_all_actions;
use function remove_all_filters;
use function wp_cache_delete;
use function wp_cache_set;

/**
 * Test subject that exposes the trait via public wrappers.
 *
 * Rate_Limit_Trait の protected メソッドをテストで呼び出せるよう公開する。
 */
class Rate_Limit_Trait_Stub {
	use Rate_Limit_Trait {
		consume_rate_limit_token as public;
		get_client_ip as public;
	}
}

/**
 * @group common
 */
class Rate_Limit_Trait_Test extends WP_UnitTestCase {

	/** @var array<string, mixed> */
	private array $server_backup = array();

	protected function setUp(): void {
		parent::setUp();
		$this->server_backup = $_SERVER;
	}

	protected function tearDown(): void {
		$_SERVER = $this->server_backup;
		// テスト間でレート制限の transient を消しておく。
		// 既存テスト用の bootstrap で `vkbm_rate_limit_enabled` を false にしているため、
		// このテストでは個別に true を返すフィルタを差し込んでいる。
		// → そのフィルタもクリーンアップする。
		remove_all_filters( 'vkbm_rate_limit_enabled' );
		// テスト本体側で add_filter したものを assert 失敗時にも確実に解除する。
		// Always clean trusted-proxy filter so a failing test does not leak state.
		remove_all_filters( 'vkbm_trusted_proxy_ips' );
		// 経路強制フィルタも解除しておく（テスト間で経路が引き継がれないようにする）。
		// Clear storage-route override filter between tests.
		remove_all_filters( 'vkbm_rate_limit_storage' );
		// 監視アクションもクリーンアップ。
		// Clean up the lock-contention observability hook between tests.
		remove_all_actions( 'vkbm_rate_limit_lock_contention' );
		parent::tearDown();
	}

	public function test_returns_true_when_ip_is_empty(): void {
		// テストの bootstrap で無効化されている状態を上書きしてレート制限を有効にする。
		add_filter( 'vkbm_rate_limit_enabled', '__return_true' );
		unset( $_SERVER['REMOTE_ADDR'] );

		$stub = new Rate_Limit_Trait_Stub();
		$this->assertTrue( $stub->consume_rate_limit_token( 'action_no_ip', 1, 60 ) );
	}

	public function test_allows_within_limit_and_blocks_when_exceeded(): void {
		add_filter( 'vkbm_rate_limit_enabled', '__return_true' );
		$_SERVER['REMOTE_ADDR'] = '192.0.2.10';

		// テストごとに transient を確実にリセットする。
		$this->clear_state_for( 'limited_action', '192.0.2.10' );

		$stub = new Rate_Limit_Trait_Stub();

		// 上限 3 を 3 回までは true、4 回目以降は false。
		$this->assertTrue( $stub->consume_rate_limit_token( 'limited_action', 3, 60 ) );
		$this->assertTrue( $stub->consume_rate_limit_token( 'limited_action', 3, 60 ) );
		$this->assertTrue( $stub->consume_rate_limit_token( 'limited_action', 3, 60 ) );
		$this->assertFalse( $stub->consume_rate_limit_token( 'limited_action', 3, 60 ) );
	}

	public function test_filter_can_disable_rate_limit(): void {
		// 明示的に false（無効化）を設定するフィルタを差し込む。
		add_filter( 'vkbm_rate_limit_enabled', '__return_false' );
		$_SERVER['REMOTE_ADDR'] = '192.0.2.20';

		$this->clear_state_for( 'disabled_action', '192.0.2.20' );

		$stub = new Rate_Limit_Trait_Stub();

		// 上限 1 でも 2 回以上 true になる（レート制限が無効化されているため）。
		$this->assertTrue( $stub->consume_rate_limit_token( 'disabled_action', 1, 60 ) );
		$this->assertTrue( $stub->consume_rate_limit_token( 'disabled_action', 1, 60 ) );
	}

	public function test_get_client_ip_returns_remote_addr_by_default(): void {
		$_SERVER['REMOTE_ADDR']          = '203.0.113.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7';

		$stub = new Rate_Limit_Trait_Stub();

		// 信頼するプロキシ設定がない場合、XFF は無視され REMOTE_ADDR が採用される。
		$this->assertSame( '203.0.113.5', $stub->get_client_ip() );
	}

	public function test_get_client_ip_uses_xff_when_proxy_is_trusted(): void {
		$_SERVER['REMOTE_ADDR']          = '203.0.113.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7, 192.0.2.1';

		$callback = static function () {
			return array( '203.0.113.5' );
		};
		add_filter( 'vkbm_trusted_proxy_ips', $callback );

		$stub = new Rate_Limit_Trait_Stub();

		// 信頼するプロキシ経由なら XFF の先頭IPを採用する。
		$this->assertSame( '198.51.100.7', $stub->get_client_ip() );

		// 解除は tearDown() で確実に行う。
	}

	/**
	 * Object cache 経路の上限到達テスト（フィルタで強制）。
	 *
	 * Forces the object_cache route and verifies blocking after the limit.
	 */
	public function test_blocks_when_exceeded_with_object_cache_route(): void {
		$this->force_storage( 'object_cache' );
		add_filter( 'vkbm_rate_limit_enabled', '__return_true' );
		$_SERVER['REMOTE_ADDR'] = '192.0.2.30';

		$this->clear_state_for( 'oc_limited', '192.0.2.30' );

		$stub = new Rate_Limit_Trait_Stub();

		// 上限 2: 1〜2 回目 true、3 回目で false。
		$this->assertTrue( $stub->consume_rate_limit_token( 'oc_limited', 2, 60 ) );
		$this->assertTrue( $stub->consume_rate_limit_token( 'oc_limited', 2, 60 ) );
		$this->assertFalse( $stub->consume_rate_limit_token( 'oc_limited', 2, 60 ) );
	}

	/**
	 * Object cache 経路で異なる action が独立してカウントされることを確認する。
	 *
	 * Different actions must not share the counter on the object_cache route.
	 */
	public function test_actions_are_isolated_on_object_cache_route(): void {
		$this->force_storage( 'object_cache' );
		add_filter( 'vkbm_rate_limit_enabled', '__return_true' );
		$_SERVER['REMOTE_ADDR'] = '192.0.2.31';

		$this->clear_state_for( 'oc_action_a', '192.0.2.31' );
		$this->clear_state_for( 'oc_action_b', '192.0.2.31' );

		$stub = new Rate_Limit_Trait_Stub();

		// action A は 1 だけ消費。
		$this->assertTrue( $stub->consume_rate_limit_token( 'oc_action_a', 1, 60 ) );
		// 2 回目は false。
		$this->assertFalse( $stub->consume_rate_limit_token( 'oc_action_a', 1, 60 ) );
		// action B は独立しているのでまだ余裕がある。
		$this->assertTrue( $stub->consume_rate_limit_token( 'oc_action_b', 1, 60 ) );
		$this->assertFalse( $stub->consume_rate_limit_token( 'oc_action_b', 1, 60 ) );
	}

	/**
	 * Object cache 経路でウィンドウ越境後にカウンタがリセットされることを確認する。
	 *
	 * After the window expires, the counter must reset on the object_cache route.
	 */
	public function test_window_reset_on_object_cache_route(): void {
		$this->force_storage( 'object_cache' );
		add_filter( 'vkbm_rate_limit_enabled', '__return_true' );
		$_SERVER['REMOTE_ADDR'] = '192.0.2.32';

		$this->clear_state_for( 'oc_reset', '192.0.2.32' );

		$stub = new Rate_Limit_Trait_Stub();

		// window = 1 秒、上限 1 で限界まで消費。
		$this->assertTrue( $stub->consume_rate_limit_token( 'oc_reset', 1, 1 ) );
		$this->assertFalse( $stub->consume_rate_limit_token( 'oc_reset', 1, 1 ) );

		// ウィンドウ越境を確実にするため、内部の window key を強制的に過去時刻で上書きする。
		// 時間注入：実時間 sleep よりも安定するため、キャッシュキーを直接書き換える。
		$this->expire_object_cache_window( 'oc_reset', '192.0.2.32' );

		// ウィンドウが切れたので再び消費可能になる。
		$this->assertTrue( $stub->consume_rate_limit_token( 'oc_reset', 1, 1 ) );
	}

	/**
	 * DB lock 経路（既存の transient ベース挙動）が保たれていることを確認する。
	 *
	 * Existing transient-based behavior must keep working on the db_lock route.
	 */
	public function test_blocks_when_exceeded_with_db_lock_route(): void {
		$this->force_storage( 'db_lock' );
		add_filter( 'vkbm_rate_limit_enabled', '__return_true' );
		$_SERVER['REMOTE_ADDR'] = '192.0.2.40';

		$this->clear_state_for( 'db_limited', '192.0.2.40' );

		$stub = new Rate_Limit_Trait_Stub();

		// 上限 2: 1〜2 回目 true、3 回目で false。
		$this->assertTrue( $stub->consume_rate_limit_token( 'db_limited', 2, 60 ) );
		$this->assertTrue( $stub->consume_rate_limit_token( 'db_limited', 2, 60 ) );
		$this->assertFalse( $stub->consume_rate_limit_token( 'db_limited', 2, 60 ) );
	}

	/**
	 * GET_LOCK 取得失敗時に監視アクションが発火され、許可フォールバックされることを確認する。
	 *
	 * 別 mysqli コネクションで対象ロックを先に握り、db_lock 経路から GET_LOCK が
	 * 取得できない状況をシミュレートする。trait は許可（true）を返し、同時に
	 * `vkbm_rate_limit_lock_contention` アクションが発火される想定。
	 *
	 * Simulates lock contention by holding the same GET_LOCK from a side
	 * mysqli connection. The trait should return true (fail-open) and fire
	 * the observability action.
	 */
	public function test_fires_lock_contention_action_when_get_lock_fails(): void {
		// MySQL に直接アクセスできない環境では skip。
		// Skip on environments without a usable mysqli (e.g. sqlite-driven CI variants).
		if ( ! \class_exists( 'mysqli' ) ) {
			$this->markTestSkipped( 'mysqli not available' );
		}

		$this->force_storage( 'db_lock' );
		add_filter( 'vkbm_rate_limit_enabled', '__return_true' );
		$_SERVER['REMOTE_ADDR'] = '192.0.2.60';

		$action = 'lock_contention_test';
		$ip     = '192.0.2.60';
		$this->clear_state_for( $action, $ip );
		$hash      = substr( sha1( $action . '|' . $ip ), 0, 20 );
		$lock_name = 'vkbm_rl_' . $hash;

		// 別コネクションで対象ロックを先に握る。
		// このコネクションを保持している間は $wpdb 側からは GET_LOCK が取れない。
		$side = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		if ( $side->connect_errno ) {
			$this->markTestSkipped( 'mysqli connection failed: ' . $side->connect_error );
		}

		$prepared = $side->prepare( 'SELECT GET_LOCK(?, 0)' );
		$prepared->bind_param( 's', $lock_name );
		$prepared->execute();
		$result = $prepared->get_result();
		$row    = $result ? $result->fetch_row() : null;
		$prepared->close();

		// 別コネクションが先にロックを取れていない場合はテスト自体が成立しないので skip。
		if ( ! $row || '1' !== (string) $row[0] ) {
			$side->close();
			$this->markTestSkipped( 'side connection could not acquire GET_LOCK' );
		}

		// アクション発火を捕捉する。
		$captured = array();
		add_action(
			'vkbm_rate_limit_lock_contention',
			static function ( $a, $h ) use ( &$captured ): void {
				$captured[] = array(
					'action' => $a,
					'hash'   => $h,
				);
			},
			10,
			2
		);

		$stub = new Rate_Limit_Trait_Stub();
		try {
			// ロック取得失敗 → 許可フォールバック（true）+ 監視アクション発火。
			$this->assertTrue( $stub->consume_rate_limit_token( $action, 1, 60 ) );
			$this->assertCount( 1, $captured, '監視アクションは必ず 1 回発火する想定' );
			$this->assertSame( $action, $captured[0]['action'] );
			$this->assertSame( $hash, $captured[0]['hash'] );
		} finally {
			// 別コネクションのロックを必ず解放する。
			$release = $side->prepare( 'SELECT RELEASE_LOCK(?)' );
			$release->bind_param( 's', $lock_name );
			$release->execute();
			$release->close();
			$side->close();
		}
	}

	/**
	 * 経路強制フィルタが想定外の値を返した場合、自動判定にフォールバックすることを確認する。
	 *
	 * Unknown values from the storage filter must fall back to the auto-detected route.
	 */
	public function test_invalid_storage_filter_value_falls_back_to_auto(): void {
		add_filter(
			'vkbm_rate_limit_storage',
			static function () {
				// 不正な値。trait 側で安全側に倒される想定。
				return 'redis_cluster_does_not_exist';
			}
		);
		add_filter( 'vkbm_rate_limit_enabled', '__return_true' );
		$_SERVER['REMOTE_ADDR'] = '192.0.2.50';

		$this->clear_state_for( 'fallback_action', '192.0.2.50' );

		$stub = new Rate_Limit_Trait_Stub();

		// 自動判定経路で動くため、上限通りに振る舞う。
		$this->assertTrue( $stub->consume_rate_limit_token( 'fallback_action', 1, 60 ) );
		$this->assertFalse( $stub->consume_rate_limit_token( 'fallback_action', 1, 60 ) );
	}

	/**
	 * Force a specific storage route via the filter.
	 *
	 * テスト用に経路を強制するヘルパ。
	 *
	 * @param string $storage 'object_cache' or 'db_lock'.
	 */
	private function force_storage( string $storage ): void {
		add_filter(
			'vkbm_rate_limit_storage',
			static function () use ( $storage ) {
				return $storage;
			}
		);
	}

	/**
	 * Remove both transient and object cache entries used internally by the trait.
	 *
	 * テスト間で残存状態が引き継がれないように、両経路で使うキーを掃除する。
	 *
	 * @param string $action Action key.
	 * @param string $ip     Client IP.
	 */
	private function clear_state_for( string $action, string $ip ): void {
		$hash = substr( sha1( $action . '|' . $ip ), 0, 20 );
		// db_lock 経路の transient。
		delete_transient( 'vkbm_rl_' . $hash );
		// object_cache 経路のキャッシュキー。
		wp_cache_delete( 'cnt_' . $hash, 'vkbm_rate_limit' );
		wp_cache_delete( 'win_' . $hash, 'vkbm_rate_limit' );
	}

	/**
	 * Force the object cache window to be already expired.
	 *
	 * object_cache 経路のウィンドウキーを過去時刻で上書きして、
	 * 次回呼び出し時にウィンドウ越境扱いになるようにする。
	 *
	 * 既存値・既存 TTL の影響を排除するため、一度 wp_cache_delete してから
	 * 新たに過去時刻で wp_cache_set し直している。これにより
	 * バックエンドの「set は TTL を再計算する／しない」といった挙動差に依らず、
	 * 「過去 reset が確実に格納された状態」を直接検証できる。
	 *
	 * Reset both keys first so the window key carries a fresh past timestamp
	 * regardless of how the cache backend treats wp_cache_set on existing keys.
	 *
	 * @param string $action Action key.
	 * @param string $ip     Client IP.
	 */
	private function expire_object_cache_window( string $action, string $ip ): void {
		$hash = substr( sha1( $action . '|' . $ip ), 0, 20 );
		// 既存値・既存 TTL の影響を完全に排除してから set し直す。
		wp_cache_delete( 'win_' . $hash, 'vkbm_rate_limit' );
		wp_cache_delete( 'cnt_' . $hash, 'vkbm_rate_limit' );
		// 1 秒前にしておけば、time() <= reset の判定で越境扱いになる。
		wp_cache_set( 'win_' . $hash, time() - 1, 'vkbm_rate_limit', 60 );
	}
}
