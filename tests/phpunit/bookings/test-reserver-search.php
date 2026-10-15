<?php
/**
 * 予約編集画面の予約者検索（Reserver_Search）のテスト（#711）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use VKBookingManager\Bookings\Reserver_Search;
use WP_UnitTestCase;

/**
 * Reserver_Search の検索対象・件数上限・ラベルを検証する。
 *
 * @group bookings
 */
class Reserver_Search_Test extends WP_UnitTestCase {

	/**
	 * 検索結果のユーザー名（user_login）の一覧を返す。
	 *
	 * @param array{users: array<int, array{id: int, label: string, email: string}>, has_more: bool} $result 検索結果。
	 * @return array<int, string>
	 */
	private function logins_of( array $result ): array {
		$logins = array();
		foreach ( $result['users'] as $row ) {
			$user     = get_userdata( $row['id'] );
			$logins[] = $user ? $user->user_login : '';
		}
		sort( $logins );

		return $logins;
	}

	/**
	 * 検索対象の各項目（姓・名・ふりがな・表示名・ユーザー名・メール）と空白の扱いを検証する。
	 */
	public function test_search(): void {
		$yamada_id = $this->factory()->user->create(
			array(
				'user_login'   => 'rs_yamada',
				'user_email'   => 'rs-taro@example.com',
				'display_name' => 'ヤマダ表示',
				'role'         => 'subscriber',
			)
		);
		update_user_meta( $yamada_id, 'last_name', '山田' );
		update_user_meta( $yamada_id, 'first_name', '太郎' );
		update_user_meta( $yamada_id, 'vkbm_kana_name', 'やまだ たろう' );

		$sato_id = $this->factory()->user->create(
			array(
				'user_login'   => 'rs_sato',
				'user_email'   => 'hanako@rs-sample.test',
				'display_name' => 'Sato Display',
				'role'         => 'subscriber',
			)
		);
		update_user_meta( $sato_id, 'last_name', 'Sato' );
		update_user_meta( $sato_id, 'first_name', 'Hanako' );

		$test_cases = array(
			array(
				'test_condition_name' => '姓で検索すると一致する => rs_yamada',
				'keyword'             => '山田',
				'expected'            => array( 'rs_yamada' ),
			),
			array(
				'test_condition_name' => '名で検索すると一致する => rs_yamada',
				'keyword'             => '太郎',
				'expected'            => array( 'rs_yamada' ),
			),
			array(
				'test_condition_name' => '姓名を空白なしで検索すると一致する => rs_yamada',
				'keyword'             => '山田太郎',
				'expected'            => array( 'rs_yamada' ),
			),
			array(
				'test_condition_name' => '姓名を半角空白区切りで検索すると一致する => rs_yamada',
				'keyword'             => '山田 太郎',
				'expected'            => array( 'rs_yamada' ),
			),
			array(
				'test_condition_name' => '姓名を全角空白区切りで検索すると一致する => rs_yamada',
				'keyword'             => '山田　太郎',
				'expected'            => array( 'rs_yamada' ),
			),
			array(
				'test_condition_name' => '名姓の順で検索しても一致する => rs_yamada',
				'keyword'             => '太郎山田',
				'expected'            => array( 'rs_yamada' ),
			),
			array(
				'test_condition_name' => 'ふりがなで検索すると一致する => rs_yamada',
				'keyword'             => 'やまだ',
				'expected'            => array( 'rs_yamada' ),
			),
			array(
				'test_condition_name' => 'ふりがなを空白なしで検索すると一致する => rs_yamada',
				'keyword'             => 'やまだたろう',
				'expected'            => array( 'rs_yamada' ),
			),
			array(
				'test_condition_name' => '表示名で検索すると一致する => rs_yamada',
				'keyword'             => 'ヤマダ表示',
				'expected'            => array( 'rs_yamada' ),
			),
			array(
				'test_condition_name' => 'ユーザー名の一部で検索すると一致する => rs_sato',
				'keyword'             => 'rs_sat',
				'expected'            => array( 'rs_sato' ),
			),
			array(
				'test_condition_name' => 'メールアドレスの一部で検索すると一致する => rs_sato',
				'keyword'             => 'hanako@rs-sample',
				'expected'            => array( 'rs_sato' ),
			),
			array(
				'test_condition_name' => '英字の姓名は大文字小文字を区別せず一致する => rs_sato',
				'keyword'             => 'sato hanako',
				'expected'            => array( 'rs_sato' ),
			),
			array(
				'test_condition_name' => '複数人に当たる場合は全員返る => rs_sato と rs_yamada',
				'keyword'             => 'rs_',
				'expected'            => array( 'rs_sato', 'rs_yamada' ),
			),
			array(
				'test_condition_name' => 'どこにも当たらない場合は空 => 空',
				'keyword'             => '存在しない人xyz',
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '空のキーワードは検索しない => 空',
				'keyword'             => '',
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '空白だけのキーワードは検索しない => 空',
				'keyword'             => " \t　",
				'expected'            => array(),
			),
			array(
				'test_condition_name' => 'LIKE のワイルドカード文字は文字として扱う => 空',
				'keyword'             => '%',
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$result = Reserver_Search::search( $case['keyword'] );
			$this->assertSame( $case['expected'], $this->logins_of( $result ), $case['test_condition_name'] );
		}
	}

	/**
	 * 権限（ロール）の無いユーザーは候補に出さない。
	 */
	public function test_search_excludes_users_without_role(): void {
		$this->factory()->user->create(
			array(
				'user_login' => 'rsrole_with',
				'role'       => 'subscriber',
			)
		);
		$without_role = $this->factory()->user->create( array( 'user_login' => 'rsrole_without' ) );
		get_userdata( $without_role )->set_role( '' );

		$test_cases = array(
			array(
				'test_condition_name' => 'ロールを持つユーザーは出る => rsrole_with のみ（ロール無しは除外）',
				'keyword'             => 'rsrole_',
				'expected'            => array( 'rsrole_with' ),
			),
			array(
				'test_condition_name' => 'ロールの無いユーザー名で直接検索しても出ない => 空',
				'keyword'             => 'rsrole_without',
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], $this->logins_of( Reserver_Search::search( $case['keyword'] ) ), $case['test_condition_name'] );
		}
	}

	/**
	 * 返す項目が ID・表示ラベル・メールアドレスだけであること。
	 */
	public function test_search_returned_fields(): void {
		$user_id = $this->factory()->user->create(
			array(
				'user_login' => 'rs_fields',
				'user_email' => 'rs-fields@example.com',
				'role'       => 'subscriber',
			)
		);
		update_user_meta( $user_id, 'last_name', '田中' );
		update_user_meta( $user_id, 'first_name', '次郎' );

		$result = Reserver_Search::search( 'rs_fields' );

		$this->assertCount( 1, $result['users'] );
		$this->assertSame(
			array(
				'id'    => $user_id,
				'label' => '田中 次郎',
				'email' => 'rs-fields@example.com',
			),
			$result['users'][0],
			'ID・表示ラベル（姓 名）・メールアドレスだけを返す'
		);
		$this->assertFalse( $result['has_more'] );
	}

	/**
	 * 件数の上限（10件）と、上限を超える一致があるかの判定を検証する。
	 */
	public function test_search_limit(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '9人が一致 => 9件・続きなし',
				'prefix'              => 'rslimit9_',
				'created'             => 9,
				'expected'            => array(
					'count'    => 9,
					'has_more' => false,
				),
			),
			array(
				'test_condition_name' => '上限ちょうどの10人が一致 => 10件・続きなし',
				'prefix'              => 'rslimit10_',
				'created'             => 10,
				'expected'            => array(
					'count'    => 10,
					'has_more' => false,
				),
			),
			array(
				'test_condition_name' => '上限を超える11人が一致 => 10件・続きあり',
				'prefix'              => 'rslimit11_',
				'created'             => 11,
				'expected'            => array(
					'count'    => 10,
					'has_more' => true,
				),
			),
			array(
				'test_condition_name' => '15人が一致 => 10件・続きあり',
				'prefix'              => 'rslimit15_',
				'created'             => 15,
				'expected'            => array(
					'count'    => 10,
					'has_more' => true,
				),
			),
		);

		foreach ( $test_cases as $case ) {
			for ( $i = 1; $i <= $case['created']; $i++ ) {
				$this->factory()->user->create(
					array(
						'user_login' => $case['prefix'] . sprintf( '%02d', $i ),
						'role'       => 'subscriber',
					)
				);
			}

			$result = Reserver_Search::search( $case['prefix'] );

			$this->assertSame(
				$case['expected'],
				array(
					'count'    => count( $result['users'] ),
					'has_more' => $result['has_more'],
				),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * キーワードから半角・全角の空白を取り除き、長さを制限する。
	 */
	public function test_normalize_keyword(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '半角空白を取り除く => 山田太郎',
				'keyword'             => '山田 太郎',
				'expected'            => '山田太郎',
			),
			array(
				'test_condition_name' => '全角空白と前後の空白を取り除く => 山田太郎',
				'keyword'             => '　山田　太郎 ',
				'expected'            => '山田太郎',
			),
			array(
				'test_condition_name' => '空白だけなら空文字 => 空文字',
				'keyword'             => '　 　',
				'expected'            => '',
			),
			array(
				'test_condition_name' => '100文字を超える分は切り捨てる => 100文字',
				'keyword'             => str_repeat( 'あ', 150 ),
				'expected'            => str_repeat( 'あ', 100 ),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Reserver_Search::normalize_keyword( $case['keyword'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * 表示ラベルの優先順位（姓名 > ふりがな > ユーザー名）を検証する。
	 */
	public function test_resolve_label(): void {
		$with_name = $this->factory()->user->create( array( 'user_login' => 'rs_label_name' ) );
		update_user_meta( $with_name, 'last_name', '鈴木' );
		update_user_meta( $with_name, 'first_name', '一郎' );
		update_user_meta( $with_name, 'vkbm_kana_name', 'すずき いちろう' );

		$with_kana = $this->factory()->user->create( array( 'user_login' => 'rs_label_kana' ) );
		update_user_meta( $with_kana, 'vkbm_kana_name', 'すずき' );

		$login_only = $this->factory()->user->create( array( 'user_login' => 'rs_label_login' ) );

		$test_cases = array(
			array(
				'test_condition_name' => '姓名があれば姓名 => 鈴木 一郎',
				'user_id'             => $with_name,
				'expected'            => '鈴木 一郎',
			),
			array(
				'test_condition_name' => '姓名が無ければふりがな => すずき',
				'user_id'             => $with_kana,
				'expected'            => 'すずき',
			),
			array(
				'test_condition_name' => 'どちらも無ければユーザー名 => rs_label_login',
				'user_id'             => $login_only,
				'expected'            => 'rs_label_login',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Reserver_Search::resolve_label( get_userdata( $case['user_id'] ) ), $case['test_condition_name'] );
		}
	}
}
