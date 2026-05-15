<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use VKBookingManager\Bookings\Booking_Draft_Controller;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;
use function add_filter;
use function add_option;
use function delete_option;
use function delete_transient;
use function get_option;
use function get_transient;
use function mb_strlen;
use function remove_all_filters;
use function remove_filter;
use function str_repeat;
use function update_option;
use function wp_json_encode;
use function wp_set_current_user;

if ( ! defined( 'VKBM_TESTS_OWNER_INDEX_PREFIX' ) ) {
	define( 'VKBM_TESTS_OWNER_INDEX_PREFIX', 'vkbm_draft_owner_idx_' );
}

if ( ! defined( 'VKBM_TESTS_OWNER_LOCK_PREFIX' ) ) {
	define( 'VKBM_TESTS_OWNER_LOCK_PREFIX', 'vkbm_draft_owner_lock_' );
}

if ( ! defined( 'VKBM_TESTS_OWNER_LOCK_TTL_SECONDS' ) ) {
	define( 'VKBM_TESTS_OWNER_LOCK_TTL_SECONDS', 2 );
}

/**
 * @group bookings
 */
class Booking_Draft_Controller_Test extends WP_UnitTestCase {
	private const TRANSIENT_PREFIX = 'vkbm_draft_';
	private const OWNER_COOKIE     = 'vkbm_draft_owner';

	/** @var array<int, string> */
	private array $tokens = [];

	/** @var array<int, string> */
	private array $owner_ids = [];

	/** @var array<string, mixed> */
	private array $cookie_backup = [];

	protected function setUp(): void {
		parent::setUp();
		$this->cookie_backup = $_COOKIE;
	}

	protected function tearDown(): void {
		foreach ( $this->tokens as $token ) {
			delete_transient( self::TRANSIENT_PREFIX . $token );
		}
		foreach ( $this->owner_ids as $owner_id ) {
			delete_transient( VKBM_TESTS_OWNER_INDEX_PREFIX . sha1( $owner_id ) );
			// owner lock も毎テストごとに掃除する（取り逃しがあると後続テストの取得失敗を引き起こすため）。
			// Clean up the owner lock option as well so that any leftover lock does not break subsequent tests.
			delete_option( VKBM_TESTS_OWNER_LOCK_PREFIX . sha1( $owner_id ) );
		}
		$this->tokens    = [];
		$this->owner_ids = [];
		$_COOKIE         = $this->cookie_backup;
		wp_set_current_user( 0 );
		// テスト間でフィルタが残らないように掃除する。
		// Clean up filters so they do not leak across test methods.
		remove_all_filters( 'vkbm_draft_memo_max_length' );
		parent::tearDown();
	}

	public function test_logged_in_owner_can_access_and_others_cannot(): void {
		$menu_id  = $this->create_menu();
		$owner_id = $this->factory()->user->create();
		wp_set_current_user( $owner_id );

		$controller = new Booking_Draft_Controller();
		$token      = $this->save_draft(
			$controller,
			$this->build_payload( $menu_id, '2024-02-01T10:00:00+09:00' )
		);

		$request  = $this->build_get_request( $token );
		$response = $controller->get_draft( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );

		$other_id = $this->factory()->user->create();
		wp_set_current_user( $other_id );
		$forbidden = $controller->get_draft( $request );
		$this->assertInstanceOf( WP_Error::class, $forbidden );
		$this->assertSame( 'forbidden_draft', $forbidden->get_error_code() );
	}

	/**
	 * owner 単位の保持上限を超えると、最古の draft を FIFO で静かに削除し
	 * 新規 draft はエラーなく作成できることを検証する。
	 *
	 * - 上限ちょうど（10件目）まではいずれも残っている
	 * - 上限超え（11件目）の作成時に最古 1 件が delete_transient で消える
	 * - 20件追加した場合は最新 10 件のみが残り、それ以前は全て消える
	 * - 他 owner の draft には影響しない
	 */
	public function test_enforce_draft_quota_per_owner(): void {
		$test_cases = [
			[
				'test_condition_name' => '同一 owner で 10 件作成（上限ちょうど）→ 全件保持',
				'conditions'          => [
					'create_count' => 10,
				],
				'expected'            => [
					'survive_indexes' => range( 0, 9 ),
					'evicted_indexes' => [],
				],
			],
			[
				'test_condition_name' => '同一 owner で 11 件作成（上限+1）→ 最古 1 件が evict され残り 10 件',
				'conditions'          => [
					'create_count' => 11,
				],
				'expected'            => [
					'survive_indexes' => range( 1, 10 ),
					'evicted_indexes' => [ 0 ],
				],
			],
			[
				'test_condition_name' => '同一 owner で 20 件作成（上限の 2 倍）→ 直近 10 件のみ残り、それ以前は全て evict',
				'conditions'          => [
					'create_count' => 20,
				],
				'expected'            => [
					'survive_indexes' => range( 10, 19 ),
					'evicted_indexes' => range( 0, 9 ),
				],
			],
		];

		foreach ( $test_cases as $case ) {
			$menu_id = $this->create_menu();

			// 他 owner の既存 draft が今回の owner の操作に巻き込まれていないことを確認する。
			// 上限超過処理（FIFO evict）の発火前に作成しておくことで、
			// 「既存の他 owner draft が evict されない」ことを確実に検証する。
			$other_user_id     = $this->factory()->user->create();
			$this->owner_ids[] = 'user:' . $other_user_id;
			wp_set_current_user( $other_user_id );
			$other_controller = new Booking_Draft_Controller();
			$other_token      = $this->save_draft(
				$other_controller,
				$this->build_payload( $menu_id, '2024-04-15T11:00:00+09:00' )
			);

			// 対象 owner に切り替え、上限を発火させる。
			$owner_id = $this->factory()->user->create();
			wp_set_current_user( $owner_id );
			$this->owner_ids[] = 'user:' . $owner_id;

			$controller = new Booking_Draft_Controller();
			$tokens     = [];

			// 同一 owner で連続して draft を作成。
			for ( $i = 0; $i < $case['conditions']['create_count']; $i++ ) {
				$tokens[] = $this->save_draft(
					$controller,
					$this->build_payload( $menu_id, sprintf( '2024-03-%02dT10:00:00+09:00', ( $i % 28 ) + 1 ) )
				);
			}

			// 存続が期待される draft は transient に残っている。
			foreach ( $case['expected']['survive_indexes'] as $index ) {
				$this->assertNotFalse(
					get_transient( self::TRANSIENT_PREFIX . $tokens[ $index ] ),
					$case['test_condition_name'] . ' / index=' . $index . ' は残っているはず'
				);
			}

			// 上限を超えて evict された draft の transient は false（不在）になる。
			foreach ( $case['expected']['evicted_indexes'] as $index ) {
				$this->assertFalse(
					get_transient( self::TRANSIENT_PREFIX . $tokens[ $index ] ),
					$case['test_condition_name'] . ' / index=' . $index . ' は evict されているはず'
				);
			}

			// 他 owner の既存 draft が evict 処理に巻き込まれず生存していることを最後に確認する。
			$this->assertNotFalse(
				get_transient( self::TRANSIENT_PREFIX . $other_token ),
				$case['test_condition_name'] . ' / 他 owner の既存 draft は残っているはず'
			);

			// テストケース毎にクリーンアップ（手動）。
			foreach ( $tokens as $token ) {
				delete_transient( self::TRANSIENT_PREFIX . $token );
			}
			delete_transient( self::TRANSIENT_PREFIX . $other_token );
			$this->tokens = []; // 重複削除を避ける
			wp_set_current_user( 0 );
		}
	}

	/**
	 * フィルタ `vkbm_draft_max_per_owner` で上限を上書きできることを検証する。
	 *
	 * - フィルタで上限 3 に設定した状態で 4 件作成 → 最古 1 件が evict
	 * - 異常値（0 以下）を返した場合は既定値（10）にフォールバック
	 */
	public function test_vkbm_draft_max_per_owner_filter(): void {
		$test_cases = [
			[
				'test_condition_name' => 'フィルタで上限 3 に設定 → 4 件作成で最古 1 件が evict',
				'conditions'          => [
					'filter_value' => 3,
					'create_count' => 4,
				],
				'expected'            => [
					'survive_indexes' => [ 1, 2, 3 ],
					'evicted_indexes' => [ 0 ],
				],
			],
			[
				'test_condition_name' => 'フィルタで 0 を返す → 既定値 10 にフォールバック',
				'conditions'          => [
					'filter_value' => 0,
					'create_count' => 10,
				],
				'expected'            => [
					'survive_indexes' => range( 0, 9 ),
					'evicted_indexes' => [],
				],
			],
		];

		foreach ( $test_cases as $case ) {
			$filter_value = $case['conditions']['filter_value'];
			$filter_cb    = static function () use ( $filter_value ) {
				return $filter_value;
			};
			add_filter( 'vkbm_draft_max_per_owner', $filter_cb );

			// アサーション失敗時もフィルタを確実に解除して後続テストを汚染しないよう
			// add_filter 以降を try/finally でラップする。
			// Wrap assertions in try/finally so the filter is always removed even on failure.
			try {
				$menu_id  = $this->create_menu();
				$owner_id = $this->factory()->user->create();
				wp_set_current_user( $owner_id );
				$this->owner_ids[] = 'user:' . $owner_id;

				$controller = new Booking_Draft_Controller();
				$tokens     = [];

				for ( $i = 0; $i < $case['conditions']['create_count']; $i++ ) {
					$tokens[] = $this->save_draft(
						$controller,
						$this->build_payload( $menu_id, sprintf( '2024-05-%02dT10:00:00+09:00', ( $i % 28 ) + 1 ) )
					);
				}

				foreach ( $case['expected']['survive_indexes'] as $index ) {
					$this->assertNotFalse(
						get_transient( self::TRANSIENT_PREFIX . $tokens[ $index ] ),
						$case['test_condition_name'] . ' / index=' . $index . ' は残っているはず'
					);
				}

				foreach ( $case['expected']['evicted_indexes'] as $index ) {
					$this->assertFalse(
						get_transient( self::TRANSIENT_PREFIX . $tokens[ $index ] ),
						$case['test_condition_name'] . ' / index=' . $index . ' は evict されているはず'
					);
				}

				foreach ( $tokens as $token ) {
					delete_transient( self::TRANSIENT_PREFIX . $token );
				}
				$this->tokens = [];
				wp_set_current_user( 0 );
			} finally {
				remove_filter( 'vkbm_draft_max_per_owner', $filter_cb );
			}
		}
	}

	/**
	 * 同一 token を別 owner で再保存した時、旧 owner index にエントリが残らないことを検証する。
	 *
	 * 匿名（cookie owner）で draft を作成 → 同じ token のままログインユーザーで再保存すると
	 * payload 上の owner は user_id に切り替わる。
	 * このとき旧 owner index（cookie owner 側）に古いエントリが残り続けると、
	 * 後で旧 owner 側の evict が走った時に現行 draft の transient が誤って削除される。
	 * save_draft 内で旧 owner index から該当 token を除去する処理が機能していることを確認する。
	 */
	public function test_save_draft_migrates_owner_index_on_owner_change(): void {
		$menu_id = $this->create_menu();

		// まず未ログイン（cookie owner）で draft を作成する。
		wp_set_current_user( 0 );
		unset( $_COOKIE[ self::OWNER_COOKIE ] );

		$controller = new Booking_Draft_Controller();
		$token      = $this->save_draft(
			$controller,
			$this->build_payload( $menu_id, '2024-07-01T10:00:00+09:00' )
		);

		// 払い出された owner_cookie の値を payload から取得して旧 owner_id を特定する。
		$initial_payload = get_transient( self::TRANSIENT_PREFIX . $token );
		$this->assertIsArray( $initial_payload, '匿名作成直後の payload は配列で取得できるはず' );
		$cookie_owner_key = (string) ( $initial_payload['owner_key'] ?? '' );
		$this->assertNotSame( '', $cookie_owner_key, '匿名作成時は owner_key が払い出されるはず' );

		$cookie_owner_id   = 'cookie:' . $cookie_owner_key;
		$cookie_index_key  = VKBM_TESTS_OWNER_INDEX_PREFIX . sha1( $cookie_owner_id );
		$this->owner_ids[] = $cookie_owner_id;

		// 匿名作成直後は cookie owner の index にエントリが 1 件入っている。
		$cookie_index_before = get_transient( $cookie_index_key );
		$this->assertIsArray( $cookie_index_before, '匿名作成後、cookie owner の index は配列で取得できるはず' );
		$cookie_tokens_before = array_map(
			static function ( array $entry ): string {
				return (string) ( $entry['token'] ?? '' );
			},
			$cookie_index_before
		);
		$this->assertContains( $token, $cookie_tokens_before, '匿名作成後、cookie owner の index に該当 token が含まれるはず' );

		// 続いて同じ token のままログインユーザーとして再保存する（owner_user_id 側に切り替わる）。
		$login_user_id = $this->factory()->user->create();
		wp_set_current_user( $login_user_id );
		$this->owner_ids[] = 'user:' . $login_user_id;

		$resaved_token = $this->save_draft(
			$controller,
			array_merge(
				$this->build_payload( $menu_id, '2024-07-02T10:00:00+09:00' ),
				array( 'token' => $token )
			)
		);
		$this->assertSame( $token, $resaved_token, '同一 token を渡した場合は同じ token で再保存されるはず' );

		// 旧 owner（cookie 側）の index から該当 token が除去されている。
		// 元々 1 件だけだったので、index ごと削除（false 返却）でも、空配列でもなく
		// 「該当 token を含まない」状態であれば OK とする。
		$cookie_index_after = get_transient( $cookie_index_key );
		if ( is_array( $cookie_index_after ) ) {
			$cookie_tokens_after = array_map(
				static function ( array $entry ): string {
					return (string) ( $entry['token'] ?? '' );
				},
				$cookie_index_after
			);
			$this->assertNotContains( $token, $cookie_tokens_after, '旧 owner（cookie）の index に該当 token が残っていないはず' );
		} else {
			$this->assertFalse( $cookie_index_after, '旧 owner の index は空になり transient ごと削除されているはず' );
		}

		// 新しい owner（user）の index には該当 token が登録されている。
		$user_owner_id     = 'user:' . $login_user_id;
		$user_index_key    = VKBM_TESTS_OWNER_INDEX_PREFIX . sha1( $user_owner_id );
		$user_index_after  = get_transient( $user_index_key );
		$this->assertIsArray( $user_index_after, '再保存後、新 owner（user）の index は配列で取得できるはず' );
		$user_tokens_after = array_map(
			static function ( array $entry ): string {
				return (string) ( $entry['token'] ?? '' );
			},
			$user_index_after
		);
		$this->assertContains( $token, $user_tokens_after, '再保存後、新 owner（user）の index に該当 token が含まれるはず' );

		// draft 本体も新 owner で生きている。
		$this->assertNotFalse(
			get_transient( self::TRANSIENT_PREFIX . $token ),
			'再保存後も draft transient 自体は残っているはず'
		);
	}

	/**
	 * delete_draft 実行後に owner index が縮むことを検証する。
	 *
	 * - 3 件作成し、index は 3 件保持
	 * - 真ん中の 1 件を delete_draft → index は 2 件に縮み、残った 2 token のみを含む
	 * - 全件 delete → owner index transient 自体が削除される（get_transient が false）
	 */
	public function test_delete_draft_pops_owner_index(): void {
		$menu_id  = $this->create_menu();
		$owner_id = $this->factory()->user->create();
		wp_set_current_user( $owner_id );
		$this->owner_ids[] = 'user:' . $owner_id;

		$controller = new Booking_Draft_Controller();

		// 3 件作成して owner index に蓄積させる。
		$token_a = $this->save_draft( $controller, $this->build_payload( $menu_id, '2024-06-01T10:00:00+09:00' ) );
		$token_b = $this->save_draft( $controller, $this->build_payload( $menu_id, '2024-06-02T10:00:00+09:00' ) );
		$token_c = $this->save_draft( $controller, $this->build_payload( $menu_id, '2024-06-03T10:00:00+09:00' ) );

		$index_key = VKBM_TESTS_OWNER_INDEX_PREFIX . sha1( 'user:' . $owner_id );

		// 3 件登録直後は index に 3 エントリ。
		$index = get_transient( $index_key );
		$this->assertIsArray( $index, '3 件作成後の owner index は配列で取得できるはず' );
		$this->assertCount( 3, $index, '3 件作成後の owner index は 3 エントリ' );

		// 真ん中の token_b を削除する。
		$delete_request = new WP_REST_Request( 'DELETE', '/vkbm/v1/drafts/' . $token_b );
		$delete_request->set_url_params( [ 'token' => $token_b ] );
		$response = $controller->delete_draft( $delete_request );
		$this->assertInstanceOf( WP_REST_Response::class, $response, 'delete_draft は WP_REST_Response を返すはず' );

		// owner index は 2 エントリに縮み、token_b は含まれない。
		$index = get_transient( $index_key );
		$this->assertIsArray( $index, 'delete_draft 後も owner index は配列' );
		$this->assertCount( 2, $index, 'delete_draft 後の owner index は 2 エントリ' );
		$remaining_tokens = array_map(
			static function ( array $entry ): string {
				return (string) ( $entry['token'] ?? '' );
			},
			$index
		);
		$this->assertContains( $token_a, $remaining_tokens, 'token_a は index に残るはず' );
		$this->assertContains( $token_c, $remaining_tokens, 'token_c は index に残るはず' );
		$this->assertNotContains( $token_b, $remaining_tokens, 'token_b は index から除去されるはず' );

		// 残り 2 件も削除すると owner index transient ごと消える（空配列保持を避けるため）。
		foreach ( [ $token_a, $token_c ] as $token ) {
			$delete_request = new WP_REST_Request( 'DELETE', '/vkbm/v1/drafts/' . $token );
			$delete_request->set_url_params( [ 'token' => $token ] );
			$controller->delete_draft( $delete_request );
		}
		$this->assertFalse( get_transient( $index_key ), '全件削除後は owner index transient ごと消えるはず' );
	}

	public function test_anonymous_owner_cookie_required(): void {
		$menu_id = $this->create_menu();
		wp_set_current_user( 0 );
		unset( $_COOKIE[ self::OWNER_COOKIE ] );

		$controller = new Booking_Draft_Controller();
		$token      = $this->save_draft(
			$controller,
			$this->build_payload( $menu_id, '2024-02-02T10:00:00+09:00' )
		);

		$request  = $this->build_get_request( $token );
		$forbidden = $controller->get_draft( $request );
		$this->assertInstanceOf( WP_Error::class, $forbidden );
		$this->assertSame( 'forbidden_draft', $forbidden->get_error_code() );

		$payload = get_transient( self::TRANSIENT_PREFIX . $token );
		$owner_key = is_array( $payload ) ? (string) ( $payload['owner_key'] ?? '' ) : '';
		$this->assertNotSame( '', $owner_key );

		$_COOKIE[ self::OWNER_COOKIE ] = $owner_key;
		$response = $controller->get_draft( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );

		$_COOKIE[ self::OWNER_COOKIE ] = 'invalid';
		$forbidden_again = $controller->get_draft( $request );
		$this->assertInstanceOf( WP_Error::class, $forbidden_again );
		$this->assertSame( 'forbidden_draft', $forbidden_again->get_error_code() );
	}

	/**
	 * resolve_memo_max_length() がフィルタ反映後の値を正しく返すことを検証する。
	 *
	 * フロント側（予約ブロックの textarea maxlength）から参照する public API
	 * として挙動が固定であることを担保する。
	 */
	public function test_resolve_memo_max_length(): void {
		// 条件 → 期待値の配列。フィルタ未設定／正常上書き／0 以下／巨大値クランプ／負値。
		$test_cases = [
			[
				'test_condition_name' => 'フィルタ未適用なら既定値 1000 を返す（正常系）',
				'filter_value'        => null,
				'expected'            => 1000,
			],
			[
				'test_condition_name' => 'フィルタで 500 を返したら 500 を返す（正常系：上書き）',
				'filter_value'        => 500,
				'expected'            => 500,
			],
			[
				'test_condition_name' => 'フィルタで 999999 を返したら MEMO_HARD_MAX_LENGTH=10000 で打ち止め（境界値：上側クランプ）',
				'filter_value'        => 999999,
				'expected'            => 10000,
			],
			[
				'test_condition_name' => 'フィルタで 0 を返したら既定値にフォールバック（異常系：無効値）',
				'filter_value'        => 0,
				'expected'            => 1000,
			],
			[
				'test_condition_name' => 'フィルタで負値 -1 を返しても既定値にフォールバック（異常系：無効値）',
				'filter_value'        => -1,
				'expected'            => 1000,
			],
		];

		foreach ( $test_cases as $case ) {
			// ケースごとにフィルタを差し込む（null のときは付けない）。
			if ( null !== $case['filter_value'] ) {
				$filter_value = (int) $case['filter_value'];
				add_filter(
					'vkbm_draft_memo_max_length',
					static function () use ( $filter_value ) {
						return $filter_value;
					}
				);
			}

			$actual = Booking_Draft_Controller::resolve_memo_max_length();

			$this->assertSame(
				$case['expected'],
				$actual,
				$case['test_condition_name']
			);

			// 次のケースに副作用が漏れないようフィルタを掃除する。
			remove_all_filters( 'vkbm_draft_memo_max_length' );
		}
	}

	/**
	 * POST /vkbm/v1/drafts の入力に対する静的な文字数・配列長上限が
	 * 正しく適用されることを検証する（issue #201）。
	 *
	 * sanitize 後の文字列を mb_substr で切り詰める仕様のため、
	 * 上限を超えた入力でもエラーにせず保存できる挙動も同時に確認する。
	 */
	public function test_save_draft_truncates_oversize_input(): void {
		$menu_id = $this->create_menu();
		$base_payload = $this->build_payload( $menu_id, '2024-03-01T10:00:00+09:00' );

		// テストの配列：条件 → save_draft 後の payload に対する期待値。
		// expected の値は保存後 transient から取り出した payload に対して検証する。
		$test_cases = [
			[
				'test_condition_name' => 'memo に 2000 文字を渡すと 1000 文字に切り詰められる（正常系：上限超過）',
				'overrides'           => [
					'memo' => str_repeat( 'あ', 2000 ),
				],
				'memo_filter'         => null,
				'expected'            => [
					'memo_length' => 1000,
					'memo_value'  => str_repeat( 'あ', 1000 ),
				],
			],
			[
				'test_condition_name' => 'memo が上限ちょうど 1000 文字なら切り詰められない（正常系：境界値）',
				'overrides'           => [
					'memo' => str_repeat( 'a', 1000 ),
				],
				'memo_filter'         => null,
				'expected'            => [
					'memo_length' => 1000,
					'memo_value'  => str_repeat( 'a', 1000 ),
				],
			],
			[
				'test_condition_name' => 'vkbm_draft_memo_max_length フィルタで 500 文字に上書きできる（正常系：フィルタ適用）',
				'overrides'           => [
					'memo' => str_repeat( 'b', 2000 ),
				],
				'memo_filter'         => 500,
				'expected'            => [
					'memo_length' => 500,
					'memo_value'  => str_repeat( 'b', 500 ),
				],
			],
			[
				'test_condition_name' => 'menu_label / staff_label / slot.staff_label は各 200 文字に切り詰められる（正常系：上限超過）',
				'overrides'           => [
					'menu_label'  => str_repeat( 'm', 300 ),
					'staff_label' => str_repeat( 's', 300 ),
					'slot'        => [
						'staff_label' => str_repeat( 't', 300 ),
					],
				],
				'memo_filter'         => null,
				'expected'            => [
					'menu_label_length'       => 200,
					'staff_label_length'      => 200,
					'slot_staff_label_length' => 200,
				],
			],
			[
				'test_condition_name' => 'slot_id / start_at / end_at / service_end_at / meta.timezone は各 64 文字に切り詰められる（異常系：上限超過）',
				'overrides'           => [
					'slot' => [
						'slot_id'        => str_repeat( 'x', 100 ),
						'start_at'       => str_repeat( 'y', 100 ),
						'end_at'         => str_repeat( 'z', 100 ),
						'service_end_at' => str_repeat( 'w', 100 ),
					],
					'meta' => [
						'timezone' => str_repeat( 'q', 100 ),
					],
				],
				'memo_filter'         => null,
				'expected'            => [
					'slot_id_length'        => 64,
					'start_at_length'       => 64,
					'end_at_length'         => 64,
					'service_end_at_length' => 64,
					'timezone_length'       => 64,
				],
			],
			[
				'test_condition_name' => 'date に 100 文字を渡すと 64 文字に切り詰められる（異常系：上限超過 / issue #201 安藤レビュー 3-1）',
				'overrides'           => [
					'date' => str_repeat( 'd', 100 ),
				],
				'memo_filter'         => null,
				'expected'            => [
					'date_length' => 64,
				],
			],
			[
				'test_condition_name' => 'slot.staff.name に 300 文字を渡すと 200 文字に切り詰められる（異常系：上限超過 / issue #201 安藤レビュー 3-2）',
				'overrides'           => [
					'slot' => [
						'staff' => [
							'id'   => 1,
							'name' => str_repeat( 'n', 300 ),
						],
					],
				],
				'memo_filter'         => null,
				'expected'            => [
					'slot_staff_name_length' => 200,
				],
			],
			[
				'test_condition_name' => 'vkbm_draft_memo_max_length が 999999 を返しても MEMO_HARD_MAX_LENGTH=10000 で打ち止め（異常系：上側クランプ / issue #201 安藤レビュー 3-3）',
				'overrides'           => [
					'memo' => str_repeat( 'c', 20000 ),
				],
				'memo_filter'         => 999999,
				'expected'            => [
					'memo_length' => 10000,
				],
			],
			[
				'test_condition_name' => 'assignable_staff_ids に 100 件渡すと 50 件に切り詰められる（境界値：配列長）',
				'overrides'           => [
					'slot' => [
						'assignable_staff_ids' => range( 1, 100 ),
					],
				],
				'memo_filter'         => null,
				'expected'            => [
					'assignable_staff_count' => 50,
					'assignable_staff_first' => 1,
					'assignable_staff_last'  => 50,
				],
			],
		];

		foreach ( $test_cases as $case ) {
			// 認証/Cookieに依存しないように毎回ログインユーザーで保存する。
			$user_id = $this->factory()->user->create();
			wp_set_current_user( $user_id );

			// memo フィルタの設定（ケースごとに付け外しする）。
			if ( null !== $case['memo_filter'] ) {
				$filter_value = (int) $case['memo_filter'];
				add_filter(
					'vkbm_draft_memo_max_length',
					static function () use ( $filter_value ) {
						return $filter_value;
					}
				);
			}

			// ベースペイロードに override をマージ。slot/meta はネスト配列のため明示的に上書きする。
			$payload = $base_payload;
			if ( isset( $case['overrides']['memo'] ) ) {
				$payload['memo'] = $case['overrides']['memo'];
			}
			if ( isset( $case['overrides']['date'] ) ) {
				$payload['date'] = $case['overrides']['date'];
			}
			if ( isset( $case['overrides']['menu_label'] ) ) {
				$payload['menu_label'] = $case['overrides']['menu_label'];
			}
			if ( isset( $case['overrides']['staff_label'] ) ) {
				$payload['staff_label'] = $case['overrides']['staff_label'];
			}
			if ( isset( $case['overrides']['slot'] ) && is_array( $case['overrides']['slot'] ) ) {
				$payload['slot'] = array_merge( $payload['slot'], $case['overrides']['slot'] );
			}
			if ( isset( $case['overrides']['meta'] ) && is_array( $case['overrides']['meta'] ) ) {
				$payload['meta'] = array_merge( $payload['meta'] ?? [], $case['overrides']['meta'] );
			}

			$controller = new Booking_Draft_Controller();
			$token      = $this->save_draft( $controller, $payload );

			$stored = get_transient( self::TRANSIENT_PREFIX . $token );
			$this->assertIsArray( $stored, $case['test_condition_name'] );

			$expected = $case['expected'];

			if ( array_key_exists( 'memo_length', $expected ) ) {
				$this->assertSame(
					$expected['memo_length'],
					$this->utf8_length( (string) $stored['memo'] ),
					$case['test_condition_name'] . ' (memo length)'
				);
			}
			if ( array_key_exists( 'memo_value', $expected ) ) {
				$this->assertSame(
					$expected['memo_value'],
					(string) $stored['memo'],
					$case['test_condition_name'] . ' (memo value)'
				);
			}
			if ( array_key_exists( 'menu_label_length', $expected ) ) {
				$this->assertSame(
					$expected['menu_label_length'],
					$this->utf8_length( (string) $stored['menu_label'] ),
					$case['test_condition_name'] . ' (menu_label length)'
				);
			}
			if ( array_key_exists( 'staff_label_length', $expected ) ) {
				$this->assertSame(
					$expected['staff_label_length'],
					$this->utf8_length( (string) $stored['staff_label'] ),
					$case['test_condition_name'] . ' (staff_label length)'
				);
			}
			if ( array_key_exists( 'slot_staff_label_length', $expected ) ) {
				$this->assertSame(
					$expected['slot_staff_label_length'],
					$this->utf8_length( (string) $stored['slot']['staff_label'] ),
					$case['test_condition_name'] . ' (slot.staff_label length)'
				);
			}
			if ( array_key_exists( 'slot_id_length', $expected ) ) {
				$this->assertSame(
					$expected['slot_id_length'],
					$this->utf8_length( (string) $stored['slot']['slot_id'] ),
					$case['test_condition_name'] . ' (slot.slot_id length)'
				);
			}
			if ( array_key_exists( 'start_at_length', $expected ) ) {
				$this->assertSame(
					$expected['start_at_length'],
					$this->utf8_length( (string) $stored['slot']['start_at'] ),
					$case['test_condition_name'] . ' (slot.start_at length)'
				);
			}
			if ( array_key_exists( 'end_at_length', $expected ) ) {
				$this->assertSame(
					$expected['end_at_length'],
					$this->utf8_length( (string) $stored['slot']['end_at'] ),
					$case['test_condition_name'] . ' (slot.end_at length)'
				);
			}
			if ( array_key_exists( 'service_end_at_length', $expected ) ) {
				$this->assertSame(
					$expected['service_end_at_length'],
					$this->utf8_length( (string) $stored['slot']['service_end_at'] ),
					$case['test_condition_name'] . ' (slot.service_end_at length)'
				);
			}
			if ( array_key_exists( 'timezone_length', $expected ) ) {
				$this->assertSame(
					$expected['timezone_length'],
					$this->utf8_length( (string) $stored['meta']['timezone'] ),
					$case['test_condition_name'] . ' (meta.timezone length)'
				);
			}
			if ( array_key_exists( 'date_length', $expected ) ) {
				$this->assertSame(
					$expected['date_length'],
					$this->utf8_length( (string) $stored['date'] ),
					$case['test_condition_name'] . ' (date length)'
				);
			}
			if ( array_key_exists( 'slot_staff_name_length', $expected ) ) {
				$this->assertSame(
					$expected['slot_staff_name_length'],
					$this->utf8_length( (string) $stored['slot']['staff']['name'] ),
					$case['test_condition_name'] . ' (slot.staff.name length)'
				);
			}
			if ( array_key_exists( 'assignable_staff_count', $expected ) ) {
				$this->assertSame(
					$expected['assignable_staff_count'],
					count( $stored['slot']['assignable_staff_ids'] ),
					$case['test_condition_name'] . ' (assignable_staff_ids count)'
				);
			}
			if ( array_key_exists( 'assignable_staff_first', $expected ) ) {
				$this->assertSame(
					$expected['assignable_staff_first'],
					(int) $stored['slot']['assignable_staff_ids'][0],
					$case['test_condition_name'] . ' (assignable_staff_ids first)'
				);
			}
			if ( array_key_exists( 'assignable_staff_last', $expected ) ) {
				$ids = $stored['slot']['assignable_staff_ids'];
				$this->assertSame(
					$expected['assignable_staff_last'],
					(int) $ids[ count( $ids ) - 1 ],
					$case['test_condition_name'] . ' (assignable_staff_ids last)'
				);
			}

			// ケースごとにフィルタを掃除し、状態が次のケースに引き継がれないようにする。
			remove_all_filters( 'vkbm_draft_memo_max_length' );
			wp_set_current_user( 0 );
		}
	}

	/**
	 * UTF-8 のコードポイント単位で文字数を返すヘルパー。
	 *
	 * mbstring 拡張が無効な環境でもテストが Fatal にならないように、
	 * `mb_strlen` が無ければ `preg_split('//u', ...)` でフォールバックする。
	 * プロダクション側の truncate_text() と同じフォールバック方針に揃える。
	 *
	 * @param string $value 文字列。
	 * @return int          コードポイント数。
	 */
	private function utf8_length( string $value ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return (int) mb_strlen( $value, 'UTF-8' );
		}

		$chars = preg_split( '//u', $value, -1, PREG_SPLIT_NO_EMPTY );

		return is_array( $chars ) ? count( $chars ) : 0;
	}

	/**
	 * with_owner_lock の正常系（取得→callback 実行→解放）と戻り値の伝播を検証する。
	 *
	 * - callback の戻り値がそのまま返る
	 * - callback 実行中はロック option が存在し、終了後は削除される
	 * - 取得失敗を仕込んだ場合は callback が呼ばれず null が返る
	 */
	public function test_with_owner_lock_normal_flow(): void {
		$test_cases = [
			[
				'test_condition_name' => '正常系：callback の戻り値（文字列）がそのまま返る',
				'preexisting_lock'    => null,
				'callback_return'     => 'ok',
				'expected_result'     => 'ok',
				'expected_called'     => true,
			],
			[
				'test_condition_name' => '正常系：callback の戻り値（配列）がそのまま返る',
				'preexisting_lock'    => null,
				'callback_return'     => [ 'a' => 1 ],
				'expected_result'     => [ 'a' => 1 ],
				'expected_called'     => true,
			],
			[
				'test_condition_name' => '異常系：他リクエストがロック保持中（TTL 内）→ callback が呼ばれず null が返る',
				'preexisting_lock'    => [
					'token'      => 'someone-else',
					'created_at' => 0, // 後で time() を入れる
				],
				'callback_return'     => 'should-not-return',
				'expected_result'     => null,
				'expected_called'     => false,
			],
		];

		foreach ( $test_cases as $case ) {
			$owner_id          = 'user:' . $this->factory()->user->create();
			$this->owner_ids[] = $owner_id;

			// TTL 内の有効ロックを仕込むケース。
			if ( null !== $case['preexisting_lock'] ) {
				$lock                       = $case['preexisting_lock'];
				$lock['created_at']         = time(); // 現在時刻を入れて TTL 内に収める
				add_option(
					VKBM_TESTS_OWNER_LOCK_PREFIX . sha1( $owner_id ),
					$lock,
					'',
					'no'
				);
			}

			$controller = new Booking_Draft_Controller();
			$called     = false;
			$result     = $this->invoke_with_owner_lock(
				$controller,
				$owner_id,
				static function () use ( $case, &$called ) {
					$called = true;
					return $case['callback_return'];
				}
			);

			$this->assertSame(
				$case['expected_called'],
				$called,
				$case['test_condition_name'] . ' / callback 呼び出し回数'
			);
			$this->assertSame(
				$case['expected_result'],
				$result,
				$case['test_condition_name'] . ' / 戻り値'
			);

			// 正常系（既存ロックなし）の場合、callback 終了後はロックが解放されているはず。
			if ( null === $case['preexisting_lock'] ) {
				$this->assertFalse(
					(bool) get_option( VKBM_TESTS_OWNER_LOCK_PREFIX . sha1( $owner_id ), false ),
					$case['test_condition_name'] . ' / 終了後はロックが解放されているはず'
				);
			}

			// 各ケース後のクリーンアップ。
			delete_option( VKBM_TESTS_OWNER_LOCK_PREFIX . sha1( $owner_id ) );
		}
	}

	/**
	 * with_owner_lock が callback 完了後にロックを確実に解放し、
	 * 同一 owner で再度ロックを取得できることを検証する。
	 */
	public function test_with_owner_lock_releases_after_callback(): void {
		$owner_id          = 'user:' . $this->factory()->user->create();
		$this->owner_ids[] = $owner_id;

		$controller = new Booking_Draft_Controller();

		// 1 回目: 正常に callback 実行。
		$first = $this->invoke_with_owner_lock(
			$controller,
			$owner_id,
			static function (): string {
				return 'first';
			}
		);
		$this->assertSame( 'first', $first, '1 回目は正常に実行されるはず' );

		// 2 回目: 解放後なので再度取得できる。
		$second = $this->invoke_with_owner_lock(
			$controller,
			$owner_id,
			static function (): string {
				return 'second';
			}
		);
		$this->assertSame( 'second', $second, '解放後は同一 owner で再度ロック取得できるはず' );
	}

	/**
	 * callback 内で例外が発生してもロックが try-finally で解放されることを検証する。
	 *
	 * - callback 内で例外を throw
	 * - with_owner_lock は例外を再 throw
	 * - その後同一 owner で再度ロックが取得できる（解放されている）
	 */
	public function test_with_owner_lock_releases_on_exception(): void {
		$owner_id          = 'user:' . $this->factory()->user->create();
		$this->owner_ids[] = $owner_id;

		$controller = new Booking_Draft_Controller();

		$caught = null;
		try {
			$this->invoke_with_owner_lock(
				$controller,
				$owner_id,
				static function (): void {
					throw new \RuntimeException( 'boom' );
				}
			);
		} catch ( \RuntimeException $e ) {
			$caught = $e;
		}

		$this->assertNotNull( $caught, 'callback の例外は呼び出し元に伝播するはず' );
		$this->assertSame( 'boom', $caught->getMessage() );

		// 例外発生後も同一 owner で再度ロックが取れる（finally で解放されているはず）。
		$result = $this->invoke_with_owner_lock(
			$controller,
			$owner_id,
			static function (): string {
				return 'after-exception';
			}
		);
		$this->assertSame(
			'after-exception',
			$result,
			'例外発生時も finally で解放されるため、同一 owner で再度ロック取得できるはず'
		);
	}

	/**
	 * acquire_owner_lock を直接叩き、同一 owner のロック保持中は再取得が失敗し、
	 * 別 owner のロックは成功することを検証する。
	 */
	public function test_owner_lock_blocks_concurrent_acquire(): void {
		$owner_a           = 'user:' . $this->factory()->user->create();
		$owner_b           = 'user:' . $this->factory()->user->create();
		$this->owner_ids[] = $owner_a;
		$this->owner_ids[] = $owner_b;

		$controller = new Booking_Draft_Controller();

		// owner_a のロックを取得（保持したまま）。
		$token_a = $this->invoke_acquire_owner_lock( $controller, $owner_a );
		$this->assertIsString( $token_a, 'owner_a の最初の取得は成功するはず' );

		$test_cases = [
			[
				'test_condition_name' => '異常系：同一 owner で保持中は再取得失敗（null）',
				'owner_id'            => $owner_a,
				'expected_null'       => true,
			],
			[
				'test_condition_name' => '正常系：別 owner なら成功（トークンが返る）',
				'owner_id'            => $owner_b,
				'expected_null'       => false,
			],
		];

		$tokens_acquired = [];
		foreach ( $test_cases as $case ) {
			$got = $this->invoke_acquire_owner_lock( $controller, $case['owner_id'] );

			if ( $case['expected_null'] ) {
				$this->assertNull( $got, $case['test_condition_name'] );
			} else {
				$this->assertIsString( $got, $case['test_condition_name'] );
				$tokens_acquired[ $case['owner_id'] ] = $got;
			}
		}

		// 後始末。
		$this->invoke_release_owner_lock( $controller, $owner_a, $token_a );
		foreach ( $tokens_acquired as $owner_id => $token ) {
			$this->invoke_release_owner_lock( $controller, $owner_id, $token );
		}
	}

	/**
	 * 故意に古いロックを仕込み、リトライ中に stale lock が破棄されて再取得が成功することを検証する。
	 *
	 * created_at を OWNER_LOCK_TTL_SECONDS + 1 秒以上前にした状態でロックを仕込み、
	 * with_owner_lock が stale 判定で破棄して取得に成功する挙動を確認する。
	 */
	public function test_owner_lock_recovers_from_stale_lock(): void {
		$test_cases = [
			[
				'test_condition_name' => '正常系（境界値）：created_at が TTL+1 秒前の stale lock は破棄され取得成功',
				'lock_age_offset'     => VKBM_TESTS_OWNER_LOCK_TTL_SECONDS + 1,
				'expected_recovered'  => true,
			],
			[
				'test_condition_name' => '正常系（境界値）：created_at が TTL+60 秒前の stale lock は破棄され取得成功',
				'lock_age_offset'     => VKBM_TESTS_OWNER_LOCK_TTL_SECONDS + 60,
				'expected_recovered'  => true,
			],
			[
				// TTL 超過の閾値は「> TTL」なので TTL 秒以下は stale ではない。
				// テスト実行時間の揺れで時計が 1 秒進む可能性を避けるため、
				// 境界値は「TTL-1 秒前」とし stale 判定にならないことを安定検証する。
				'test_condition_name' => '異常系（境界値）：created_at が TTL-1 秒前なら stale 扱いせず取得失敗（null）',
				'lock_age_offset'     => VKBM_TESTS_OWNER_LOCK_TTL_SECONDS - 1,
				'expected_recovered'  => false,
			],
		];

		foreach ( $test_cases as $case ) {
			$owner_id          = 'user:' . $this->factory()->user->create();
			$this->owner_ids[] = $owner_id;

			// 故意に古い created_at を入れたロックを仕込む。
			add_option(
				VKBM_TESTS_OWNER_LOCK_PREFIX . sha1( $owner_id ),
				[
					'token'      => 'stale-token',
					'created_at' => time() - $case['lock_age_offset'],
				],
				'',
				'no'
			);

			$controller = new Booking_Draft_Controller();
			$called     = false;
			$result     = $this->invoke_with_owner_lock(
				$controller,
				$owner_id,
				static function () use ( &$called ): string {
					$called = true;
					return 'recovered';
				}
			);

			if ( $case['expected_recovered'] ) {
				$this->assertTrue(
					$called,
					$case['test_condition_name'] . ' / stale lock 回収後に callback が実行されるはず'
				);
				$this->assertSame(
					'recovered',
					$result,
					$case['test_condition_name'] . ' / callback の戻り値が返るはず'
				);
				// 終了後はロックが解放されているはず。
				$this->assertFalse(
					(bool) get_option( VKBM_TESTS_OWNER_LOCK_PREFIX . sha1( $owner_id ), false ),
					$case['test_condition_name'] . ' / 終了後はロックが解放されているはず'
				);
			} else {
				$this->assertFalse(
					$called,
					$case['test_condition_name'] . ' / 取得失敗時は callback が呼ばれないはず'
				);
				$this->assertNull(
					$result,
					$case['test_condition_name'] . ' / 取得失敗時は null が返るはず'
				);
				// 仕込んだロック自体は残っているはず（誤って消していないことの確認）。
				$remaining = get_option( VKBM_TESTS_OWNER_LOCK_PREFIX . sha1( $owner_id ), null );
				$this->assertIsArray(
					$remaining,
					$case['test_condition_name'] . ' / 取得失敗時は仕込んだロックは残っているはず'
				);
				$this->assertSame(
					'stale-token',
					(string) ( $remaining['token'] ?? '' ),
					$case['test_condition_name'] . ' / 取得失敗時は仕込んだロックのトークンが残っているはず'
				);
			}

			delete_option( VKBM_TESTS_OWNER_LOCK_PREFIX . sha1( $owner_id ) );
		}
	}

	/**
	 * release_owner_lock がトークン不一致時にロックを誤って削除しないことを検証する。
	 *
	 * 他リクエスト（プロセス B）が取得したロックをこちら（プロセス A）が誤って解放してしまうと、
	 * クリティカルセクションを侵食しうるため、トークン照合は必須。
	 */
	public function test_release_owner_lock_token_mismatch(): void {
		$test_cases = [
			[
				'test_condition_name' => '異常系：トークン不一致 → 削除されない',
				'stored_token'        => 'real-token-from-other-process',
				'release_token'       => 'wrong-token',
				'expected_removed'    => false,
			],
			[
				'test_condition_name' => '異常系：空文字を渡しても削除されない',
				'stored_token'        => 'real-token',
				'release_token'       => '',
				'expected_removed'    => false,
			],
			[
				'test_condition_name' => '正常系：トークン一致 → 削除される',
				'stored_token'        => 'real-token-match',
				'release_token'       => 'real-token-match',
				'expected_removed'    => true,
			],
		];

		foreach ( $test_cases as $case ) {
			$owner_id          = 'user:' . $this->factory()->user->create();
			$this->owner_ids[] = $owner_id;

			$lock_key = VKBM_TESTS_OWNER_LOCK_PREFIX . sha1( $owner_id );
			add_option(
				$lock_key,
				[
					'token'      => $case['stored_token'],
					'created_at' => time(),
				],
				'',
				'no'
			);

			$controller = new Booking_Draft_Controller();
			$this->invoke_release_owner_lock( $controller, $owner_id, $case['release_token'] );

			$after = get_option( $lock_key, null );

			if ( $case['expected_removed'] ) {
				$this->assertFalse(
					(bool) $after,
					$case['test_condition_name'] . ' / 一致時はロックが削除されるはず'
				);
			} else {
				$this->assertIsArray(
					$after,
					$case['test_condition_name'] . ' / 不一致時はロックが削除されず残っているはず'
				);
				$this->assertSame(
					$case['stored_token'],
					(string) ( $after['token'] ?? '' ),
					$case['test_condition_name'] . ' / 不一致時は元のトークンがそのまま残っているはず'
				);
			}

			delete_option( $lock_key );
		}
	}

	/**
	 * release_owner_lock が「自分の release コール直前に reclaim された別ロック」を
	 * 誤って削除しないことを検証する（条件付き DELETE / CAS の正当性）。
	 *
	 * シナリオ:
	 * 1. A が acquire_owner_lock で正規にロックを取得
	 * 2. 手動で update_option を叩いて option_value を別ロック値（B 相当）に置換する
	 *    （A の critical section 中に B が TTL 経過判定して reclaim した状況の再現）
	 * 3. A の release_owner_lock を呼ぶ
	 *    - 現在の実装では get_option 時点で値が変わっているので hash_equals は false →
	 *      早期 return パスに乗り、$wpdb->delete は呼ばれず B のロックは残る
	 *    - $wpdb->delete に到達するパスでも、WHERE option_value が B 相当に変わっているため
	 *      0 行返却で B のロックは残る（DB レイヤの CAS）
	 *
	 * いずれの経路でも「他リクエストの新ロックを消し去らない」という安全性が成り立つことを確認する。
	 */
	public function test_release_owner_lock_does_not_delete_reclaimed_lock(): void {
		$test_cases = [
			[
				'test_condition_name' => '異常系：A の release 前に option_value が別ロックに reclaim されていたら DB 上の値は破壊されない',
				// option_value が完全に別ロックに置換された（=hash_equals false パスで止まる）ケース。
				'reclaim_to'          => [
					'token'      => 'reclaimed-by-other',
					'created_at' => 0, // foreach の中で time() を入れる
				],
			],
		];

		foreach ( $test_cases as $case ) {
			$owner_id          = 'user:' . $this->factory()->user->create();
			$this->owner_ids[] = $owner_id;
			$lock_key          = VKBM_TESTS_OWNER_LOCK_PREFIX . sha1( $owner_id );

			$controller = new Booking_Draft_Controller();

			// 1) 正規に acquire（A がロック取得）。
			$a_token = $this->invoke_acquire_owner_lock( $controller, $owner_id );
			$this->assertIsString( $a_token, 'A の acquire は成功するはず' );

			// 2) 別ロック値で reclaim を仕込む（B 相当の新ロックに置換）。
			$reclaimed                = $case['reclaim_to'];
			$reclaimed['created_at']  = time();
			update_option( $lock_key, $reclaimed );

			// 3) A の release_owner_lock を呼ぶ。
			$this->invoke_release_owner_lock( $controller, $owner_id, $a_token );

			// 4) B のロックがそのまま残っていることを確認する。
			$after = get_option( $lock_key, null );
			$this->assertIsArray(
				$after,
				$case['test_condition_name'] . ' / 競合相手のロックは残っているはず'
			);
			$this->assertSame(
				$reclaimed['token'],
				(string) ( $after['token'] ?? '' ),
				$case['test_condition_name'] . ' / 競合相手のトークンが破壊されていないはず'
			);

			delete_option( $lock_key );
		}
	}

	/**
	 * release_owner_lock の正常系 CAS（自分のロックを正しく削除できる）を検証する。
	 *
	 * acquire → release を素直に通した場合、option が削除されていることを確認する。
	 * release_owner_lock_token_mismatch / does_not_delete_reclaimed_lock が「削除しない」側を
	 * カバーしているので、こちらは「削除する」側の対称テスト。
	 */
	public function test_release_owner_lock_deletes_own_lock(): void {
		$owner_id          = 'user:' . $this->factory()->user->create();
		$this->owner_ids[] = $owner_id;
		$lock_key          = VKBM_TESTS_OWNER_LOCK_PREFIX . sha1( $owner_id );

		$controller = new Booking_Draft_Controller();

		// 1) acquire（自分のロックを取得）。
		$token = $this->invoke_acquire_owner_lock( $controller, $owner_id );
		$this->assertIsString( $token, '取得は成功するはず' );
		$this->assertIsArray(
			get_option( $lock_key, null ),
			'取得直後はロック option が wp_options に存在するはず'
		);

		// 2) release（自分のロックを CAS で正しく削除）。
		$this->invoke_release_owner_lock( $controller, $owner_id, $token );

		// 3) option が削除されていることを確認する。
		$this->assertFalse(
			(bool) get_option( $lock_key, false ),
			'release 後は自分のロックが原子的に削除されているはず'
		);
	}

	/**
	 * ロック導入後も既存の enforce_draft_quota_per_owner の挙動が壊れていないことを確認する。
	 *
	 * 既存テスト test_enforce_draft_quota_per_owner と同じケースを別ファイル/別メソッドで再走させる役回り。
	 * ロックを噛ませた以外は仕様変更がないため、結果も同じになるはず。
	 */
	public function test_enforce_draft_quota_per_owner_with_lock(): void {
		$test_cases = [
			[
				'test_condition_name' => 'ロック導入後：同一 owner で 10 件作成 → 全件保持',
				'create_count'        => 10,
				'expected_survive'    => range( 0, 9 ),
				'expected_evicted'    => [],
			],
			[
				'test_condition_name' => 'ロック導入後：同一 owner で 11 件作成 → 最古 1 件が evict',
				'create_count'        => 11,
				'expected_survive'    => range( 1, 10 ),
				'expected_evicted'    => [ 0 ],
			],
			[
				'test_condition_name' => 'ロック導入後：同一 owner で 20 件作成 → 直近 10 件のみ残る',
				'create_count'        => 20,
				'expected_survive'    => range( 10, 19 ),
				'expected_evicted'    => range( 0, 9 ),
			],
		];

		foreach ( $test_cases as $case ) {
			$menu_id = $this->create_menu();

			// 他 owner の draft が巻き込まれないことも併せて確認する。
			$other_user_id     = $this->factory()->user->create();
			$this->owner_ids[] = 'user:' . $other_user_id;
			wp_set_current_user( $other_user_id );
			$other_controller = new Booking_Draft_Controller();
			$other_token      = $this->save_draft(
				$other_controller,
				$this->build_payload( $menu_id, '2024-08-15T11:00:00+09:00' )
			);

			$owner_id = $this->factory()->user->create();
			wp_set_current_user( $owner_id );
			$this->owner_ids[] = 'user:' . $owner_id;

			$controller = new Booking_Draft_Controller();
			$tokens     = [];

			for ( $i = 0; $i < $case['create_count']; $i++ ) {
				$tokens[] = $this->save_draft(
					$controller,
					$this->build_payload( $menu_id, sprintf( '2024-09-%02dT10:00:00+09:00', ( $i % 28 ) + 1 ) )
				);
			}

			foreach ( $case['expected_survive'] as $index ) {
				$this->assertNotFalse(
					get_transient( self::TRANSIENT_PREFIX . $tokens[ $index ] ),
					$case['test_condition_name'] . ' / index=' . $index . ' は残っているはず'
				);
			}
			foreach ( $case['expected_evicted'] as $index ) {
				$this->assertFalse(
					get_transient( self::TRANSIENT_PREFIX . $tokens[ $index ] ),
					$case['test_condition_name'] . ' / index=' . $index . ' は evict されているはず'
				);
			}

			$this->assertNotFalse(
				get_transient( self::TRANSIENT_PREFIX . $other_token ),
				$case['test_condition_name'] . ' / 他 owner の既存 draft は残っているはず'
			);

			foreach ( $tokens as $token ) {
				delete_transient( self::TRANSIENT_PREFIX . $token );
			}
			delete_transient( self::TRANSIENT_PREFIX . $other_token );
			$this->tokens = [];
			wp_set_current_user( 0 );
		}
	}

	/**
	 * ロック導入後も delete_draft 時の owner index 縮みが正しく動作することを検証する。
	 *
	 * 既存テスト test_delete_draft_pops_owner_index の回帰確認版。
	 */
	public function test_delete_draft_pops_owner_index_with_lock(): void {
		$menu_id  = $this->create_menu();
		$owner_id = $this->factory()->user->create();
		wp_set_current_user( $owner_id );
		$this->owner_ids[] = 'user:' . $owner_id;

		$controller = new Booking_Draft_Controller();

		$token_a = $this->save_draft( $controller, $this->build_payload( $menu_id, '2024-10-01T10:00:00+09:00' ) );
		$token_b = $this->save_draft( $controller, $this->build_payload( $menu_id, '2024-10-02T10:00:00+09:00' ) );
		$token_c = $this->save_draft( $controller, $this->build_payload( $menu_id, '2024-10-03T10:00:00+09:00' ) );

		$index_key = VKBM_TESTS_OWNER_INDEX_PREFIX . sha1( 'user:' . $owner_id );

		$index = get_transient( $index_key );
		$this->assertIsArray( $index, '3 件作成後の owner index は配列で取得できるはず' );
		$this->assertCount( 3, $index, '3 件作成後の owner index は 3 エントリ' );

		// 真ん中の token_b を削除する（ロックを介して index が縮むはず）。
		$delete_request = new WP_REST_Request( 'DELETE', '/vkbm/v1/drafts/' . $token_b );
		$delete_request->set_url_params( [ 'token' => $token_b ] );
		$response = $controller->delete_draft( $delete_request );
		$this->assertInstanceOf( WP_REST_Response::class, $response, 'delete_draft は WP_REST_Response を返すはず' );

		$index = get_transient( $index_key );
		$this->assertIsArray( $index, 'delete_draft 後も owner index は配列' );
		$this->assertCount( 2, $index, 'delete_draft 後の owner index は 2 エントリ' );

		$remaining_tokens = array_map(
			static function ( array $entry ): string {
				return (string) ( $entry['token'] ?? '' );
			},
			$index
		);
		$this->assertContains( $token_a, $remaining_tokens );
		$this->assertContains( $token_c, $remaining_tokens );
		$this->assertNotContains( $token_b, $remaining_tokens );

		// 全件削除後は owner index ごと消える。
		foreach ( [ $token_a, $token_c ] as $token ) {
			$delete_request = new WP_REST_Request( 'DELETE', '/vkbm/v1/drafts/' . $token );
			$delete_request->set_url_params( [ 'token' => $token ] );
			$controller->delete_draft( $delete_request );
		}
		$this->assertFalse( get_transient( $index_key ), '全件削除後は owner index transient ごと消えるはず' );
	}

	/**
	 * stale lock 回収パスの ABA 競合対策が機能していることを検証する。
	 *
	 * acquire_owner_lock は stale lock 検出時、`delete_option` + `add_option` ではなく
	 * 「option_value が自分の見た stale 値と一致する場合のみ置換する」条件付き UPDATE
	 * を 1 クエリで実行する。これにより、A が stale を観測した直後に別リクエスト B が
	 * 先に reclaim した場合、A の UPDATE は WHERE 条件不一致で 0 行が返り、ロック取得失敗
	 * となる（B の新ロックを誤って消し去って両者が critical section に入る事故を防ぐ）。
	 *
	 * シミュレーション手順:
	 * 1. stale な created_at を持つロックを仕込む
	 * 2. acquire_owner_lock を呼ぶ前に、別リクエスト B 相当の更新を手動で
	 *    update_option で書き込む（reclaim 済みの状態を作る）
	 * 3. acquire_owner_lock を呼ぶ → $wpdb->update の WHERE が一致せず 0 行 → 取得失敗
	 */
	public function test_acquire_owner_lock_stale_reclaim_loses_to_concurrent(): void {
		$test_cases = [
			[
				'test_condition_name' => '異常系：stale lock 観測後に他リクエストが先に reclaim → 自分の condition UPDATE は 0 行で取得失敗（null）',
				'lock_age_offset'     => VKBM_TESTS_OWNER_LOCK_TTL_SECONDS + 5,
				'concurrent_reclaim'  => true,
				'expected_null'       => true,
			],
			[
				'test_condition_name' => '正常系：stale lock 観測後、誰も先に reclaim していなければ自分の condition UPDATE が成功（トークンが返る）',
				'lock_age_offset'     => VKBM_TESTS_OWNER_LOCK_TTL_SECONDS + 5,
				'concurrent_reclaim'  => false,
				'expected_null'       => false,
			],
		];

		foreach ( $test_cases as $case ) {
			$owner_id          = 'user:' . $this->factory()->user->create();
			$this->owner_ids[] = $owner_id;
			$lock_key          = VKBM_TESTS_OWNER_LOCK_PREFIX . sha1( $owner_id );

			// 1) stale lock を仕込む。
			add_option(
				$lock_key,
				[
					'token'      => 'stale-token',
					'created_at' => time() - $case['lock_age_offset'],
				],
				'',
				'no'
			);

			// 2) 他リクエスト B 相当が先に reclaim している状況を作る（option_value を別値に書き換え）。
			if ( $case['concurrent_reclaim'] ) {
				update_option(
					$lock_key,
					[
						'token'      => 'other-request-token',
						'created_at' => time(),
					]
				);
			}

			// 3) acquire_owner_lock を呼ぶ。stale 観測時の値は古い 'stale-token' のため、
			//    concurrent_reclaim=true の場合は $wpdb->update の WHERE が一致せず 0 行 → null。
			$controller = new Booking_Draft_Controller();
			$got        = $this->invoke_acquire_owner_lock( $controller, $owner_id );

			if ( $case['expected_null'] ) {
				$this->assertNull(
					$got,
					$case['test_condition_name']
				);
				// B が書き込んだロックは消されずに残っていることも併せて確認する。
				$after = get_option( $lock_key, null );
				$this->assertIsArray(
					$after,
					$case['test_condition_name'] . ' / 競合相手のロックは残っているはず'
				);
				$this->assertSame(
					'other-request-token',
					(string) ( $after['token'] ?? '' ),
					$case['test_condition_name'] . ' / 競合相手のロックトークンは破壊されていないはず'
				);
			} else {
				$this->assertIsString(
					$got,
					$case['test_condition_name']
				);
				// 自分のロックが反映されているはず。
				$after = get_option( $lock_key, null );
				$this->assertIsArray( $after, $case['test_condition_name'] );
				$this->assertSame(
					$got,
					(string) ( $after['token'] ?? '' ),
					$case['test_condition_name'] . ' / 自分のロックトークンが書き込まれているはず'
				);

				// 後始末。
				$this->invoke_release_owner_lock( $controller, $owner_id, $got );
			}

			delete_option( $lock_key );
		}
	}

	/**
	 * private メソッド with_owner_lock を Reflection で呼び出すヘルパー。
	 *
	 * @param Booking_Draft_Controller $controller Controller instance.
	 * @param string                   $owner_id   Owner identifier.
	 * @param callable                 $callback   Critical section.
	 * @return mixed
	 */
	private function invoke_with_owner_lock( Booking_Draft_Controller $controller, string $owner_id, callable $callback ) {
		$ref = new \ReflectionClass( $controller );
		$method = $ref->getMethod( 'with_owner_lock' );
		$method->setAccessible( true );
		return $method->invoke( $controller, $owner_id, $callback );
	}

	/**
	 * private メソッド acquire_owner_lock を Reflection で呼び出すヘルパー。
	 *
	 * @param Booking_Draft_Controller $controller Controller instance.
	 * @param string                   $owner_id   Owner identifier.
	 * @return string|null
	 */
	private function invoke_acquire_owner_lock( Booking_Draft_Controller $controller, string $owner_id ): ?string {
		$ref = new \ReflectionClass( $controller );
		$method = $ref->getMethod( 'acquire_owner_lock' );
		$method->setAccessible( true );
		$result = $method->invoke( $controller, $owner_id );
		return null === $result ? null : (string) $result;
	}

	/**
	 * private メソッド release_owner_lock を Reflection で呼び出すヘルパー。
	 *
	 * @param Booking_Draft_Controller $controller Controller instance.
	 * @param string                   $owner_id   Owner identifier.
	 * @param string                   $lock_token Lock token from acquire_owner_lock().
	 * @return void
	 */
	private function invoke_release_owner_lock( Booking_Draft_Controller $controller, string $owner_id, string $lock_token ): void {
		$ref = new \ReflectionClass( $controller );
		$method = $ref->getMethod( 'release_owner_lock' );
		$method->setAccessible( true );
		$method->invoke( $controller, $owner_id, $lock_token );
	}

	private function create_menu(): int {
		return (int) $this->factory()->post->create(
			[
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			]
		);
	}

	/**
	 * @param int    $menu_id Menu ID.
	 * @param string $start_at Slot start datetime.
	 * @return array<string, mixed>
	 */
	private function build_payload( int $menu_id, string $start_at ): array {
		$date = substr( $start_at, 0, 10 );

		return [
			'menu_id' => $menu_id,
			'date'    => $date,
			'slot'    => [
				'slot_id'   => 'slot-1',
				'start_at'  => $start_at,
				'end_at'    => $date . 'T10:30:00+09:00',
			],
			'meta'    => [
				'timezone' => 'Asia/Tokyo',
			],
		];
	}

	private function save_draft( Booking_Draft_Controller $controller, array $payload ): string {
		$request  = new WP_REST_Request( 'POST', '/vkbm/v1/drafts' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $payload ) );

		$response = $controller->save_draft( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );

		$data  = $response->get_data();
		$token = isset( $data['token'] ) ? (string) $data['token'] : '';
		$this->assertNotSame( '', $token );
		$this->tokens[] = $token;

		return $token;
	}

	private function build_get_request( string $token ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/vkbm/v1/drafts/' . $token );
		$request->set_url_params(
			[
				'token' => $token,
			]
		);

		return $request;
	}
}
