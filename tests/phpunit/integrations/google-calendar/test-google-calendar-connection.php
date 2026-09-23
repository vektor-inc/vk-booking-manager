<?php
/**
 * Google カレンダー連携の接続状態を保存・読み出すクラスのテスト。
 *
 * issue #475。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Integrations\GoogleCalendar;

use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Connection;
use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Secret_Store;
use WP_UnitTestCase;
use function delete_option;
use function get_option;
use function update_option;

/**
 * Google_Calendar_Connection のテスト。
 */
class Test_Google_Calendar_Connection extends WP_UnitTestCase {

	/**
	 * テスト対象。
	 *
	 * @var Google_Calendar_Connection
	 */
	private $connection;

	/**
	 * 各テストの前に、保存済みの接続情報を消しておく。
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		delete_option( Google_Calendar_Connection::OPTION_NAME );
		$this->connection = new Google_Calendar_Connection();

		if ( ! Google_Calendar_Secret_Store::is_available() ) {
			$this->markTestSkipped( 'この環境では openssl の AES-256-GCM が使えないためスキップする。' );
		}
	}

	/**
	 * 各テストの後に、保存済みの接続情報を消す。
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( Google_Calendar_Connection::OPTION_NAME );

		parent::tear_down();
	}

	/**
	 * 接続状態の判定が、保存されている内容ごとに期待どおりになること。
	 *
	 * @return void
	 */
	public function test_get_status(): void {
		$encrypted_refresh = (string) Google_Calendar_Secret_Store::encrypt( 'refresh-token' );

		$test_cases = array(
			array(
				'test_condition_name' => '一度も接続していない => 未接続',
				'stored'              => null,
				'expected'            => 'disconnected',
			),
			array(
				'test_condition_name' => 'アクセス許可があり接続済みとして保存されている => 接続済み',
				'stored'              => array(
					'status'        => 'connected',
					'refresh_token' => $encrypted_refresh,
				),
				'expected'            => 'connected',
			),
			array(
				'test_condition_name' => 'エラーとして保存されている => エラー',
				'stored'              => array(
					'status'        => 'error',
					'refresh_token' => $encrypted_refresh,
				),
				'expected'            => 'error',
			),
			array(
				'test_condition_name' => '接続済みだがアクセス許可を復号できない（salt の入れ替えなど） => エラー',
				'stored'              => array(
					'status'        => 'connected',
					'refresh_token' => 'v1:broken:broken:broken',
				),
				'expected'            => 'error',
			),
			array(
				'test_condition_name' => '知らない状態が保存されている => 未接続',
				'stored'              => array(
					'status'        => 'something-unknown',
					'refresh_token' => $encrypted_refresh,
				),
				'expected'            => 'disconnected',
			),
		);

		foreach ( $test_cases as $case ) {
			if ( null === $case['stored'] ) {
				delete_option( Google_Calendar_Connection::OPTION_NAME );
			} else {
				update_option( Google_Calendar_Connection::OPTION_NAME, $case['stored'], false );
			}

			$this->assertSame( $case['expected'], $this->connection->get_status(), $case['test_condition_name'] );
		}
	}

	/**
	 * Google から受け取ったアクセス許可を保存できること。
	 *
	 * 2回目の接続でリフレッシュトークンが返ってこない場合に、保存済みのものを引き継ぐことも確かめる。
	 * Google は、同意画面を再度通さなかった場合にリフレッシュトークンを返さないため。
	 *
	 * @return void
	 */
	public function test_save_tokens(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'アクセス許可が揃っている => 保存でき、接続済みになる',
				'tokens'              => array(
					'access_token'  => 'access-token-1',
					'refresh_token' => 'refresh-token-1',
					'expires_in'    => 3600,
					'email'         => 'owner@example.com',
				),
				'expected'            => 'connected',
			),
			array(
				'test_condition_name' => 'リフレッシュトークンが無い初回 => 保存せず未接続のまま',
				'tokens'              => array(
					'access_token' => 'access-token-only',
					'expires_in'   => 3600,
				),
				'expected'            => 'disconnected',
			),
		);

		foreach ( $test_cases as $case ) {
			delete_option( Google_Calendar_Connection::OPTION_NAME );

			$saved = $this->connection->save_tokens( $case['tokens'] );

			$this->assertSame( 'connected' === $case['expected'], $saved, $case['test_condition_name'] );
			$this->assertSame( $case['expected'], $this->connection->get_status(), $case['test_condition_name'] );
		}

		// 保存した内容が、平文のまま DB に残っていないこと。
		delete_option( Google_Calendar_Connection::OPTION_NAME );
		$this->connection->save_tokens(
			array(
				'access_token'  => 'access-token-2',
				'refresh_token' => 'refresh-token-2',
				'expires_in'    => 3600,
				'email'         => 'owner@example.com',
			)
		);

		$stored = get_option( Google_Calendar_Connection::OPTION_NAME );
		$this->assertIsArray( $stored, '保存された接続情報が配列であること' );
		$this->assertStringNotContainsString( 'refresh-token-2', (string) wp_json_encode( $stored ), 'リフレッシュトークンが平文で保存されていないこと' );
		$this->assertStringNotContainsString( 'access-token-2', (string) wp_json_encode( $stored ), 'アクセストークンが平文で保存されていないこと' );
		$this->assertSame( 'refresh-token-2', $this->connection->get_refresh_token(), '復号すると元のリフレッシュトークンに戻ること' );
		$this->assertSame( 'owner@example.com', $this->connection->get_account_email(), '連携したアカウントのメールアドレスが保存されること' );

		// 2回目の接続でリフレッシュトークンが返ってこなくても、保存済みのものを引き継ぐこと。
		$this->connection->save_tokens(
			array(
				'access_token' => 'access-token-3',
				'expires_in'   => 3600,
			)
		);
		$this->assertSame( 'refresh-token-2', $this->connection->get_refresh_token(), '2回目にリフレッシュトークンが無くても、保存済みのものを引き継ぐこと' );
	}

	/**
	 * 別の Google アカウントで繋ぎ直したとき、前のアカウントの反映先カレンダーが
	 * 引き継がれないこと（安藤レビュー指摘・issue #476 の土台）。
	 *
	 * @return void
	 */
	public function test_save_tokens_when_account_changes(): void {
		// アカウントA で接続し、反映先カレンダーを選ぶ。
		delete_option( Google_Calendar_Connection::OPTION_NAME );
		$this->connection->save_tokens(
			array(
				'access_token'  => 'access-token-a',
				'refresh_token' => 'refresh-token-a',
				'expires_in'    => 3600,
				'email'         => 'account-a@example.com',
			)
		);
		$this->connection->set_calendar( 'calendar-a@example.com', 'アカウントAの予定' );
		$connected_at_a = $this->connection->get_state()['connected_at'];

		$test_cases = array(
			array(
				'test_condition_name'  => '別アカウント（新しいリフレッシュトークン付き） => 反映先カレンダーが空に戻り、新しいリフレッシュトークンで接続済みになる',
				'tokens'               => array(
					'access_token'  => 'access-token-b',
					'refresh_token' => 'refresh-token-b',
					'expires_in'    => 3600,
					'email'         => 'account-b@example.com',
				),
				'expected_saved'       => true,
				'expected_calendar_id' => '',
				'expected_refresh'     => 'refresh-token-b',
			),
		);

		foreach ( $test_cases as $case ) {
			$saved = $this->connection->save_tokens( $case['tokens'] );

			$this->assertSame( $case['expected_saved'], $saved, $case['test_condition_name'] );
			$this->assertSame( $case['expected_calendar_id'], $this->connection->get_calendar_id(), $case['test_condition_name'] . '（反映先カレンダーが引き継がれないこと）' );
			$this->assertSame( '', $this->connection->get_calendar_summary(), $case['test_condition_name'] . '（反映先カレンダー名も引き継がれないこと）' );
			$this->assertSame( $case['expected_refresh'], $this->connection->get_refresh_token(), $case['test_condition_name'] );
			$this->assertSame( 'account-b@example.com', $this->connection->get_account_email(), $case['test_condition_name'] . '（新しいアカウントに更新されること）' );
			$this->assertGreaterThanOrEqual( $connected_at_a, $this->connection->get_state()['connected_at'], $case['test_condition_name'] . '（連携開始時刻が更新されること）' );
		}
	}

	/**
	 * 別の Google アカウントへの繋ぎ直しで、新しいリフレッシュトークンが返ってこなかった場合、
	 * 前のアカウントのリフレッシュトークンを引き継がずに保存を失敗させること。
	 *
	 * 引き継いでしまうと、画面は「アカウントBに接続済み」と表示されるのに、実際には
	 * アカウントAの許可のまま動き続けてしまう。
	 *
	 * @return void
	 */
	public function test_save_tokens_when_account_changes_without_new_refresh_token(): void {
		delete_option( Google_Calendar_Connection::OPTION_NAME );
		$this->connection->save_tokens(
			array(
				'access_token'  => 'access-token-a',
				'refresh_token' => 'refresh-token-a',
				'expires_in'    => 3600,
				'email'         => 'account-a@example.com',
			)
		);
		$this->connection->set_calendar( 'calendar-a@example.com', 'アカウントAの予定' );

		$saved = $this->connection->save_tokens(
			array(
				'access_token' => 'access-token-b',
				'expires_in'   => 3600,
				'email'        => 'account-b@example.com',
			)
		);

		$this->assertFalse( $saved, '新しいリフレッシュトークンが無いまま別アカウントへ切り替えることはできないこと' );
		$this->assertSame( 'refresh-token-a', $this->connection->get_refresh_token(), '前のアカウントのリフレッシュトークンを引き継がないこと' );
		$this->assertSame( 'calendar-a@example.com', $this->connection->get_calendar_id(), '保存に失敗した場合は反映先カレンダーもそのまま残ること' );
	}

	/**
	 * 有効期限が切れたアクセストークンを返さないこと。
	 *
	 * 期限ぎりぎりのトークンで Google を呼ぶと、通信中に失効して失敗しうるため、
	 * 期限の60秒前を過ぎたものは期限切れとして扱う。
	 *
	 * @return void
	 */
	public function test_get_access_token(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '有効期限まで1時間ある => そのまま使える',
				'expires_in'          => 3600,
				'expected'            => 'access-token',
			),
			array(
				'test_condition_name' => '有効期限まで30秒（余裕60秒より短い） => 期限切れ扱い',
				'expires_in'          => 30,
				'expected'            => null,
			),
			array(
				'test_condition_name' => '有効期限の情報が無い => 期限切れ扱い',
				'expires_in'          => 0,
				'expected'            => null,
			),
		);

		foreach ( $test_cases as $case ) {
			delete_option( Google_Calendar_Connection::OPTION_NAME );

			$this->connection->save_tokens(
				array(
					'access_token'  => 'access-token',
					'refresh_token' => 'refresh-token',
					'expires_in'    => $case['expires_in'],
				)
			);

			$this->assertSame( $case['expected'], $this->connection->get_access_token(), $case['test_condition_name'] );
		}
	}

	/**
	 * 反映先カレンダーを保存できること、および接続していない状態では保存しないこと。
	 *
	 * @return void
	 */
	public function test_set_calendar(): void {
		// 未接続の状態では、カレンダーだけが保存されないこと。
		$this->connection->set_calendar( 'primary@example.com', '仕事' );
		$this->assertSame( '', $this->connection->get_calendar_id(), '未接続の状態では反映先を保存しないこと' );

		$this->connection->save_tokens(
			array(
				'access_token'  => 'access-token',
				'refresh_token' => 'refresh-token',
				'expires_in'    => 3600,
			)
		);
		$this->connection->set_calendar( 'primary@example.com', '仕事' );

		$this->assertSame( 'primary@example.com', $this->connection->get_calendar_id(), '反映先のカレンダー ID が保存されること' );
		$this->assertSame( '仕事', $this->connection->get_calendar_summary(), '反映先のカレンダー名が保存されること' );
		$this->assertTrue( $this->connection->is_ready(), '接続済みで反映先も選ばれている状態になること' );
	}

	/**
	 * エラーの記録・連携の解除が期待どおりに動くこと。
	 *
	 * エラーの記録では保存済みのアクセス許可を消さない（再接続したときに、反映先カレンダーの
	 * 選択をやり直さずに済むようにするため）。
	 *
	 * @return void
	 */
	public function test_mark_error(): void {
		$this->connection->save_tokens(
			array(
				'access_token'  => 'access-token',
				'refresh_token' => 'refresh-token',
				'expires_in'    => 3600,
			)
		);
		$this->connection->set_calendar( 'primary@example.com', '仕事' );

		$this->connection->mark_error( 'invalid_grant', 'Googleとの連携が切れています。' );

		$this->assertSame( 'error', $this->connection->get_status(), 'エラー状態になること' );
		$this->assertSame( 'primary@example.com', $this->connection->get_calendar_id(), 'エラーでも反映先の選択は残ること' );
		$this->assertFalse( $this->connection->is_connected(), 'エラー状態は接続済みとして扱わないこと' );

		$state = $this->connection->get_state();
		$this->assertSame( 'Googleとの連携が切れています。', $state['error_message'], '画面に出すエラー内容が取り出せること' );
	}

	/**
	 * 連携を解除すると、保存している情報がすべて消えること。
	 *
	 * @return void
	 */
	public function test_clear(): void {
		$this->connection->save_tokens(
			array(
				'access_token'  => 'access-token',
				'refresh_token' => 'refresh-token',
				'expires_in'    => 3600,
			)
		);

		$this->connection->clear();

		$this->assertSame( 'disconnected', $this->connection->get_status(), '解除すると未接続に戻ること' );
		$this->assertNull( $this->connection->get_refresh_token(), '解除するとアクセス許可が残らないこと' );
		$this->assertFalse( get_option( Google_Calendar_Connection::OPTION_NAME, false ), '解除すると保存そのものが消えること' );
	}
}
