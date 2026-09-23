<?php
/**
 * Google カレンダー連携で受け取ったアクセス許可を暗号化・復号するクラスのテスト。
 *
 * issue #475。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Integrations\GoogleCalendar;

use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Secret_Store;
use WP_UnitTestCase;

/**
 * Google_Calendar_Secret_Store のテスト。
 */
class Test_Google_Calendar_Secret_Store extends WP_UnitTestCase {

	/**
	 * 暗号化した値が、元の平文へ戻せること。
	 *
	 * あわせて、同じ平文を2回暗号化しても異なる文字列になること（毎回ランダムな初期化ベクトルを
	 * 使っていること）を確かめる。同じ結果になると、DB を見ただけで「同じ値が入っている」ことが
	 * 分かってしまうため。
	 *
	 * @return void
	 */
	public function test_encrypt(): void {
		if ( ! Google_Calendar_Secret_Store::is_available() ) {
			$this->markTestSkipped( 'この環境では openssl の AES-256-GCM が使えないためスキップする。' );
		}

		$test_cases = array(
			array(
				'test_condition_name' => '一般的なリフレッシュトークンの形 => そのまま復号できる',
				'plain'               => '1//0abcdefghijklmnopqrstuvwxyz-ABCDEFGHIJKLMNOPQRSTUVWXYZ_0123456789',
			),
			array(
				'test_condition_name' => '記号を含む文字列 => そのまま復号できる',
				'plain'               => 'ya29.a0AfH6SM!"#$%&\'()=~|`{+*}<>?_',
			),
			array(
				'test_condition_name' => '日本語を含む文字列 => そのまま復号できる',
				'plain'               => 'テスト用のトークン値',
			),
			array(
				'test_condition_name' => '1文字 => そのまま復号できる',
				'plain'               => 'a',
			),
		);

		foreach ( $test_cases as $case ) {
			$encrypted = Google_Calendar_Secret_Store::encrypt( $case['plain'] );

			$this->assertIsString( $encrypted, $case['test_condition_name'] );
			$this->assertNotSame( $case['plain'], $encrypted, $case['test_condition_name'] . '（平文がそのまま残っていないこと）' );
			$this->assertSame( $case['plain'], Google_Calendar_Secret_Store::decrypt( (string) $encrypted ), $case['test_condition_name'] );

			// 同じ平文でも、暗号化のたびに異なる文字列になること。
			$encrypted_again = Google_Calendar_Secret_Store::encrypt( $case['plain'] );
			$this->assertNotSame( $encrypted, $encrypted_again, $case['test_condition_name'] . '（毎回異なる暗号文になること）' );
		}

		// 空文字は暗号化の対象外（保存すべき値が無い）。
		$this->assertNull( Google_Calendar_Secret_Store::encrypt( '' ), '空文字 => null を返す' );
	}

	/**
	 * 壊れた値・改ざんされた値を渡したときに、例外ではなく null を返すこと。
	 *
	 * 呼び出し側（Google_Calendar_Connection）は null を「連携が切れている」として扱い、
	 * 再接続を促す。ここで例外が出ると管理画面が開けなくなるため、必ず null で返す。
	 *
	 * @return void
	 */
	public function test_decrypt(): void {
		if ( ! Google_Calendar_Secret_Store::is_available() ) {
			$this->markTestSkipped( 'この環境では openssl の AES-256-GCM が使えないためスキップする。' );
		}

		$valid = (string) Google_Calendar_Secret_Store::encrypt( 'test-refresh-token' );
		$parts = explode( ':', $valid );

		// 暗号文の一部を別の値へ差し替えた、改ざん相当の文字列を作る。
		$tampered_parts = $parts;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- 改ざん相当の値を作るためのエンコード（難読化目的ではない）.
		$tampered_parts[3] = rtrim( strtr( base64_encode( 'tampered-cipher-text' ), '+/', '-_' ), '=' );
		$tampered          = implode( ':', $tampered_parts );

		$test_cases = array(
			array(
				'test_condition_name' => '空文字 => null',
				'stored'              => '',
			),
			array(
				'test_condition_name' => '暗号化されていないただの文字列 => null',
				'stored'              => 'plain-text-token',
			),
			array(
				'test_condition_name' => '区切りの数が足りない => null',
				'stored'              => 'v1:abc:def',
			),
			array(
				'test_condition_name' => '知らない版番号 => null',
				'stored'              => 'v9:' . $parts[1] . ':' . $parts[2] . ':' . $parts[3],
			),
			array(
				'test_condition_name' => '暗号文が改ざんされている => null（認証タグで検出される）',
				'stored'              => $tampered,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertNull( Google_Calendar_Secret_Store::decrypt( $case['stored'] ), $case['test_condition_name'] );
		}

		// 正しい値は復号できること（上の異常系と対にして確認する）。
		$this->assertSame( 'test-refresh-token', Google_Calendar_Secret_Store::decrypt( $valid ), '正しい値 => 復号できる' );
	}
}
