<?php
/**
 * ゲスト（会員登録なしのお客様）に付ける顧客フラグのサービス層のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Customers;

use VKBookingManager\Customers\Customer_Flag_Repository;
use VKBookingManager\Customers\Customer_Flag_Service;
use WP_UnitTestCase;

/**
 * ゲストの連絡先（メールアドレス・電話番号）に付けるフラグの保存・解除・照合・一覧・消去を検証する。
 *
 * @group customer-flags
 */
class Customer_Flag_Service_Guest_Test extends WP_UnitTestCase {
	use Customer_Flag_Options_Cleanup_Trait;

	/**
	 * テスト対象サービス。
	 *
	 * @var Customer_Flag_Service
	 */
	private $service;

	/**
	 * 各テストの前に、種別を登録しデータを初期化する。
	 */
	public function set_up(): void {
		parent::set_up();

		$this->delete_all_flag_options();
		$this->service = new Customer_Flag_Service();
		$this->service->save_types(
			array(
				array(
					'id'             => 'caution',
					'label'          => '要注意',
					'blocks_booking' => false,
					'block_message'  => '',
				),
				array(
					'id'             => 'blacklist',
					'label'          => 'ブラックリスト',
					'blocks_booking' => true,
					'block_message'  => 'ご予約をお受けできません。',
				),
			)
		);
	}

	/**
	 * 各テストの後に、データを片付ける。
	 */
	public function tear_down(): void {
		$this->delete_all_flag_options();

		parent::tear_down();
	}

	/**
	 * ゲストの連絡先へのフラグの付与・解除が、そろえた値で 1 人 1 件の記録として保存されることを検証する。
	 */
	public function test_set_guest_flag(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'メール・電話の両方を、そろえた値で 1 件の記録にする（正常系）',
				'conditions'          => array(
					'email'   => '  Guest@Example.COM ',
					'phone'   => '０９０-1234-5678',
					'type_id' => 'blacklist',
				),
				'expected'            => array(
					'result' => true,
					'count'  => 1,
					'email'  => 'guest@example.com',
					'phone'  => '09012345678',
				),
			),
			array(
				'test_condition_name' => 'メールだけでも保存できる（電話は空。正常系）',
				'conditions'          => array(
					'email'   => 'only@example.com',
					'phone'   => '',
					'type_id' => 'caution',
				),
				'expected'            => array(
					'result' => true,
					'count'  => 1,
					'email'  => 'only@example.com',
					'phone'  => '',
				),
			),
			array(
				'test_condition_name' => '電話だけでも保存できる（メールは空。正常系）',
				'conditions'          => array(
					'email'   => '',
					'phone'   => '090 9999 0000',
					'type_id' => 'caution',
				),
				'expected'            => array(
					'result' => true,
					'count'  => 1,
					'email'  => '',
					'phone'  => '09099990000',
				),
			),
			array(
				'test_condition_name' => 'メール・電話が両方空なら保存しない（異常系）',
				'conditions'          => array(
					'email'   => '  ',
					'phone'   => 'abc',
					'type_id' => 'caution',
				),
				'expected'            => array(
					'result' => false,
					'count'  => 0,
					'email'  => null,
					'phone'  => null,
				),
			),
			array(
				'test_condition_name' => '存在しない種別は保存しない（異常系）',
				'conditions'          => array(
					'email'   => 'x@example.com',
					'phone'   => '',
					'type_id' => 'unknown',
				),
				'expected'            => array(
					'result' => false,
					'count'  => 0,
					'email'  => null,
					'phone'  => null,
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->delete_all_flag_options();
			$this->restore_types();

			$c      = $case['conditions'];
			$result = $this->service->set_guest_flag( $c['email'], $c['phone'], $c['type_id'], 'メモ', 7, 99 );

			$records = $this->service->get_guest_records();
			$this->assertSame( $case['expected']['result'], $result, $case['test_condition_name'] . ' / 戻り値' );
			$this->assertCount( $case['expected']['count'], $records, $case['test_condition_name'] . ' / 件数' );
			if ( null !== $case['expected']['email'] ) {
				$this->assertSame( $case['expected']['email'], $records[0]['email'], $case['test_condition_name'] . ' / メール' );
				$this->assertSame( $case['expected']['phone'], $records[0]['phone'], $case['test_condition_name'] . ' / 電話' );
				$this->assertSame( 99, $records[0]['source_booking_id'], $case['test_condition_name'] . ' / 元の予約' );
				$this->assertSame( 7, $records[0]['assigned_by'], $case['test_condition_name'] . ' / 付けた人' );
			}
		}
	}

	/**
	 * メール・電話のどちらかが一致する既存の記録を、1 件にまとめて上書きし、同一内容の再保存では監査情報を温存することを検証する。
	 */
	public function test_set_guest_flag_merges_and_preserves_audit(): void {
		// メールだけの記録と、電話だけの記録を別々に作る。
		$this->service->set_guest_flag( 'a@example.com', '', 'caution', '', 1, 10 );
		$this->service->set_guest_flag( '', '09011112222', 'caution', '', 1, 11 );
		$this->service->set_guest_flag( 'other@example.com', '', 'caution', '', 1, 12 );
		$this->assertCount( 3, $this->service->get_guest_records(), '別々の記録が 3 件ある' );

		// メールと電話の両方を持つ予約から付けると、両方に一致する 2 件がまとまる（無関係な記録は残る）。
		$this->service->set_guest_flag( 'a@example.com', '09011112222', 'blacklist', '再発', 2, 13 );
		$records = $this->service->get_guest_records();
		$this->assertCount( 2, $records, '一致する 2 件が 1 件にまとまり、無関係な 1 件が残る' );

		$merged = array_values(
			array_filter(
				$records,
				static function ( array $record ): bool {
					return 'a@example.com' === $record['email'];
				}
			)
		);
		$this->assertSame( '09011112222', $merged[0]['phone'], 'まとめた記録に電話も入る' );
		$this->assertSame( 'blacklist', $merged[0]['type_id'], 'まとめた記録は新しい種別になる' );

		// 同じ内容で保存し直しても、付けた人・日時は変わらない。
		$before = $this->service->get_guest_records();
		$this->service->set_guest_flag( 'a@example.com', '09011112222', 'blacklist', '再発', 99, 14 );
		$this->assertSame( $before, $this->service->get_guest_records(), '同一内容の再保存では記録が変わらない' );
	}

	/**
	 * 予約からの付与・変更は、その予約の連絡先だけに効き、一致しなかった側の連絡先のフラグは外れないことを検証する。
	 */
	public function test_set_guest_flag_keeps_unmatched_contact_flags(): void {
		// 予約 1（メール A・電話 P）に付ける。
		$this->service->set_guest_flag( 'a@example.com', '09011112222', 'blacklist', '無断キャンセル', 1, 10 );

		// 同じ人の予約 2（メール A・電話 Q）で同じ種別を保存しても、電話 P のブロックは外れない。
		$this->service->set_guest_flag( 'a@example.com', '09033334444', 'blacklist', '無断キャンセル', 2, 11 );
		$this->assertNotNull( $this->service->find_blocking_guest_flag( '', '09011112222' ), '電話 P が引き続きブロック対象' );
		$this->assertNotNull( $this->service->find_blocking_guest_flag( '', '09033334444' ), '電話 Q もブロック対象' );
		$this->assertNotNull( $this->service->find_blocking_guest_flag( 'a@example.com', '' ), 'メール A もブロック対象' );

		// メールだけの予約で種別を変えても、残った電話側の記録（元の種別・付けた人・予約）は変わらない。
		$this->service->set_guest_flag( 'a@example.com', '', 'caution', '', 3, 12 );
		$phone_p = array_values(
			array_filter(
				$this->service->get_guest_records(),
				static function ( array $record ): bool {
					return '09011112222' === $record['phone'];
				}
			)
		);
		$this->assertCount( 1, $phone_p, '電話 P の記録が 1 件残る' );
		$this->assertSame( 'blacklist', $phone_p[0]['type_id'], '残った記録は元の種別のまま' );
		$this->assertSame( 1, (int) $phone_p[0]['assigned_by'], '残った記録は元の付けた人のまま' );
		$this->assertSame( 10, (int) $phone_p[0]['source_booking_id'], '残った記録は元の予約のまま' );
		$this->assertSame( '', $phone_p[0]['email'], '一致した側のメールだけ取り除かれる' );
		$this->assertNotNull( $this->service->find_blocking_guest_flag( '', '09011112222' ), '電話 P が引き続きブロック対象' );
		$this->assertNull( $this->service->find_blocking_guest_flag( 'a@example.com', '' ), 'メール A は今回の種別（止めない）になる' );
	}

	/**
	 * 片方の連絡先しか無い予約で付け直しても、もう片方の連絡先が残ることを検証する。
	 */
	public function test_set_guest_flag_with_single_contact_keeps_other_contact(): void {
		$this->service->set_guest_flag( 'a@example.com', '09011112222', 'blacklist', '', 1, 10 );

		// 電話だけの予約から付け直しても、メールのブロックは残る。
		$this->service->set_guest_flag( '', '09011112222', 'blacklist', '再発', 2, 11 );
		$this->assertNotNull( $this->service->find_blocking_guest_flag( 'a@example.com', '' ), 'メールが引き続きブロック対象' );
		$this->assertNotNull( $this->service->find_blocking_guest_flag( '', '09011112222' ), '電話もブロック対象' );

		// 逆に、メールだけの予約から付け直しても、電話のブロックは残る。
		$this->service->set_guest_flag( 'a@example.com', '', 'blacklist', '再発2', 3, 12 );
		$this->assertNotNull( $this->service->find_blocking_guest_flag( '', '09011112222' ), '電話が引き続きブロック対象' );
		$this->assertNotNull( $this->service->find_blocking_guest_flag( 'a@example.com', '' ), 'メールもブロック対象' );
	}

	/**
	 * メール・電話とも同じ予約で付け直すと、記録が 1 件に置き換わり重複しないことを検証する。
	 */
	public function test_set_guest_flag_with_same_contacts_replaces_record(): void {
		$this->service->set_guest_flag( 'a@example.com', '09011112222', 'caution', '', 1, 10 );
		$this->service->set_guest_flag( 'a@example.com', '09011112222', 'blacklist', '変更', 2, 11 );

		$records = $this->service->get_guest_records();
		$this->assertCount( 1, $records, '記録が 1 件に置き換わる' );
		$this->assertSame( 'blacklist', $records[0]['type_id'] );
		$this->assertSame( 'a@example.com', $records[0]['email'] );
		$this->assertSame( '09011112222', $records[0]['phone'] );
	}

	/**
	 * 「なし」（種別 ID が空）での保存と ID 指定の解除が、メールか電話のどちらか一致する記録をすべて消すことを検証する。
	 */
	public function test_clear_guest_flag(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'メールだけ一致する記録も消える（正常系）',
				'conditions'          => array(
					'email' => 'a@example.com',
					'phone' => '09000000000',
				),
				'expected'            => array( 'other@example.com', '' ),
			),
			array(
				'test_condition_name' => '電話だけ一致する記録も消える（正常系）',
				'conditions'          => array(
					'email' => 'unmatched@example.com',
					'phone' => '09011112222',
				),
				'expected'            => array( 'a@example.com', 'other@example.com' ),
			),
			array(
				'test_condition_name' => 'どちらも一致しなければ何も消えない（境界値）',
				'conditions'          => array(
					'email' => 'unmatched@example.com',
					'phone' => '09000000000',
				),
				'expected'            => array( 'a@example.com', 'other@example.com', '' ),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->delete_all_flag_options();
			$this->restore_types();
			$this->service->set_guest_flag( 'a@example.com', '', 'caution' );
			$this->service->set_guest_flag( 'other@example.com', '', 'caution' );
			$this->service->set_guest_flag( '', '09011112222', 'caution' );

			// 種別 ID が空文字の保存は解除として扱う。
			$this->service->set_guest_flag( $case['conditions']['email'], $case['conditions']['phone'], '' );

			$emails = array_column( $this->service->get_guest_records(), 'email' );
			sort( $emails );
			$expected = $case['expected'];
			sort( $expected );
			$this->assertSame( $expected, $emails, $case['test_condition_name'] );
		}

		// 連絡先が両方空の解除は何もできない。
		$this->assertFalse( $this->service->clear_guest_flag( '', '' ), '連絡先が空の解除は失敗する（異常系）' );

		// 記録 ID を指定した解除（一覧の解除ボタン）。
		$records = $this->service->get_guest_records();
		$this->assertNotEmpty( $records, '解除前に記録がある' );
		$this->assertTrue( $this->service->delete_guest_flag_by_id( $records[0]['id'] ), 'ID 指定で解除できる' );
		$this->assertCount( count( $records ) - 1, $this->service->get_guest_records(), 'ID 指定で 1 件だけ消える' );
		$this->assertFalse( $this->service->delete_guest_flag_by_id( '' ), 'ID が空なら失敗する（異常系）' );
	}

	/**
	 * 種別を削除すると、その種別のゲストの記録も消えることを検証する。
	 */
	public function test_save_types_removes_guest_records_of_deleted_types(): void {
		$this->service->set_guest_flag( 'a@example.com', '', 'blacklist' );
		$this->service->set_guest_flag( 'b@example.com', '', 'caution' );

		// 「ブラックリスト」を削除する。
		$this->service->save_types(
			array(
				array(
					'id'    => 'caution',
					'label' => '要注意',
				),
				array(
					'id'     => 'blacklist',
					'label'  => 'ブラックリスト',
					'delete' => '1',
				),
			)
		);

		$this->assertSame( array( 'b@example.com' ), array_column( $this->service->get_guest_records(), 'email' ), '削除した種別の記録だけが消える' );
		$this->assertNull( $this->service->find_blocking_guest_flag( 'a@example.com', '' ), '削除した種別はブロックに使われない' );
	}

	/**
	 * 記録の書き換えの排他を取れないとき、消去・付与・解除は失敗と分かる値を返し、記録を壊さないことを検証する。
	 */
	public function test_guest_flag_writes_fail_visibly_when_lock_is_held(): void {
		$this->service->set_guest_flag( 'erase@example.com', '', 'blacklist' );
		$this->hold_guest_lock_for_test();

		$this->assertNull( $this->service->erase_guest_flags( 'erase@example.com', array() ), '排他を取れない消去は null（件数 0 の成功と区別する。異常系）' );
		$this->assertFalse( $this->service->set_guest_flag( 'new@example.com', '', 'blacklist' ), '排他を取れない付与は false（異常系）' );
		$this->assertFalse( $this->service->clear_guest_flag( 'erase@example.com', '' ), '排他を取れない解除は false（異常系）' );
		$this->assertSame( array( 'erase@example.com' ), array_column( $this->service->get_guest_records(), 'email' ), '記録はそのまま残る' );

		$this->release_guest_lock_for_test();
		$this->assertSame( 1, $this->service->erase_guest_flags( 'erase@example.com', array() ), '排他が空けば消せる（正常系）' );
	}

	/**
	 * 種別の削除で記録を消せなかったときに知らせ、次の保存で消せることを検証する。
	 */
	public function test_save_types_reports_guest_cleanup_failure_and_retries(): void {
		$this->service->set_guest_flag( 'a@example.com', '', 'blacklist' );
		$types = array(
			array(
				'id'    => 'caution',
				'label' => '要注意',
			),
			array(
				'id'     => 'blacklist',
				'label'  => 'ブラックリスト',
				'delete' => '1',
			),
		);

		$this->hold_guest_lock_for_test();
		$this->assertTrue( $this->service->save_types( $types ), '種別の保存自体は成功する' );
		$this->assertTrue( $this->service->has_guest_cleanup_failed(), '記録を消せなかったことが分かる（異常系）' );
		$this->assertCount( 1, $this->service->get_guest_records(), '消せなかった記録が残る' );

		// 排他が空いてからもう一度保存すると、残っていた記録が消える。
		$this->release_guest_lock_for_test();
		$this->assertTrue( $this->service->save_types( $types ), '再保存も成功する' );
		$this->assertFalse( $this->service->has_guest_cleanup_failed(), '消せたら失敗扱いが解ける（正常系）' );
		$this->assertSame( array(), $this->service->get_guest_records(), '前回消し損ねた記録も消える' );
	}

	/**
	 * 排他の中の読み直しが、「存在しない option」の記憶に邪魔されず、別のリクエストが先に作った記録を見ることを検証する。
	 */
	public function test_update_guest_records_ignores_stale_notoptions(): void {
		// option が無い状態を get_option に記憶させる（notoptions キャッシュに載る）。
		$this->assertFalse( get_option( Customer_Flag_Repository::OPTION_GUEST_FLAGS, false ) );

		// 別のリクエストが、キャッシュを通さず直接 option を作った状況。
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->options,
			array(
				'option_name'  => Customer_Flag_Repository::OPTION_GUEST_FLAGS,
				'option_value' => maybe_serialize(
					array(
						array(
							'id'                => 'other1',
							'email'             => 'other@example.com',
							'phone'             => '',
							'type_id'           => 'blacklist',
							'memo'              => '',
							'assigned_by'       => 0,
							'assigned_at'       => '2026-01-01 00:00:00',
							'source_booking_id' => 0,
						),
					)
				),
				'autoload'     => 'no',
			)
		);

		$seen = null;
		( new Customer_Flag_Repository() )->update_guest_records(
			static function ( array $records ) use ( &$seen ): ?array {
				$seen = $records;
				return null;
			}
		);

		$this->assertCount( 1, (array) $seen, '排他の中で最新の記録を読めている' );
	}

	/**
	 * 連絡先の照合と、予約を止める種別のゲストのフラグの検出を検証する。
	 */
	public function test_find_blocking_guest_flag(): void {
		$this->service->set_guest_flag( 'blocked@example.com', '09011112222', 'blacklist' );
		$this->service->set_guest_flag( 'noted@example.com', '09033334444', 'caution' );

		$test_cases = array(
			array(
				'test_condition_name' => 'メールだけ一致（大文字・空白つき）で止める種別を検出する（正常系）',
				'conditions'          => array(
					'email' => ' BLOCKED@Example.com ',
					'phone' => '09000000000',
				),
				'expected'            => 'blacklist',
			),
			array(
				'test_condition_name' => '電話だけ一致（全角・ハイフンつき）で止める種別を検出する（正常系）',
				'conditions'          => array(
					'email' => 'unmatched@example.com',
					'phone' => '０９０-1111-2222',
				),
				'expected'            => 'blacklist',
			),
			array(
				'test_condition_name' => '止めない種別は検出しない（正常系）',
				'conditions'          => array(
					'email' => 'noted@example.com',
					'phone' => '09033334444',
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => 'どちらも一致しなければ検出しない（異常系）',
				'conditions'          => array(
					'email' => 'unmatched@example.com',
					'phone' => '09000000000',
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '連絡先が空なら、空の項目を持つ記録とも一致しない（境界値）',
				'conditions'          => array(
					'email' => '',
					'phone' => '',
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '+81 表記は 090 表記と一致しない（境界値）',
				'conditions'          => array(
					'email' => '',
					'phone' => '+81 90-1111-2222',
				),
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			$flag = $this->service->find_blocking_guest_flag( $case['conditions']['email'], $case['conditions']['phone'] );
			$this->assertSame( $case['expected'], null !== $flag ? $flag['type_id'] : '', $case['test_condition_name'] );
		}

		// 電話だけの記録が、空のメールの照合に当たらないこと。
		$this->service->set_guest_flag( '', '09055556666', 'blacklist' );
		$this->assertNull( $this->service->find_blocking_guest_flag( '', '09077778888' ), '電話だけの記録は、別の電話では一致しない' );
	}

	/**
	 * 会員の今のメール・電話でゲストのフラグと照合できることと、ゲストの記録が空なら何もしないことを検証する。
	 */
	public function test_find_blocking_guest_flag_for_user(): void {
		$user_id = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_email' => 'member@example.com',
			)
		);
		update_user_meta( $user_id, 'phone_number', '09012340000' );

		$this->assertNull( $this->service->find_blocking_guest_flag_for_user( $user_id ), 'ゲストの記録が無ければ止めない' );

		$this->service->set_guest_flag( 'someone@example.com', '09012340000', 'blacklist' );
		$flag = $this->service->find_blocking_guest_flag_for_user( $user_id );
		$this->assertSame( 'blacklist', null !== $flag ? $flag['type_id'] : '', '会員の電話番号がゲストのフラグと一致すれば止める' );

		$this->service->clear_guest_flag( 'someone@example.com', '09012340000' );
		$this->service->set_guest_flag( 'member@example.com', '', 'blacklist' );
		$flag = $this->service->find_blocking_guest_flag_for_user( $user_id );
		$this->assertSame( 'blacklist', null !== $flag ? $flag['type_id'] : '', '会員のメールがゲストのフラグと一致すれば止める' );

		$this->service->clear_guest_flag( 'member@example.com', '' );
		$this->service->set_guest_flag( 'member@example.com', '', 'caution' );
		$this->assertNull( $this->service->find_blocking_guest_flag_for_user( $user_id ), '止めない種別なら止めない' );
		$this->assertNull( $this->service->find_blocking_guest_flag_for_user( 0 ), '存在しないユーザーは止めない（異常系）' );
	}

	/**
	 * 一覧用の絞り込み・検索が、種別とメール・電話の一部で働くことを検証する。
	 */
	public function test_list_guest_flags(): void {
		$this->service->set_guest_flag( 'taro@example.com', '09011112222', 'blacklist' );
		$this->service->set_guest_flag( 'hanako@example.org', '', 'caution' );
		$this->service->set_guest_flag( '', '08099990000', 'caution' );

		$test_cases = array(
			array(
				'test_condition_name' => '条件なしなら全件（正常系）',
				'conditions'          => array(
					'type'   => '',
					'search' => '',
				),
				'expected'            => 3,
			),
			array(
				'test_condition_name' => '種別で絞り込める（正常系）',
				'conditions'          => array(
					'type'   => 'caution',
					'search' => '',
				),
				'expected'            => 2,
			),
			array(
				'test_condition_name' => 'メールの一部（大文字でも）で検索できる（正常系）',
				'conditions'          => array(
					'type'   => '',
					'search' => 'EXAMPLE.org',
				),
				'expected'            => 1,
			),
			array(
				'test_condition_name' => '電話の一部（ハイフンつき）で検索できる（正常系）',
				'conditions'          => array(
					'type'   => '',
					'search' => '0809999',
				),
				'expected'            => 1,
			),
			array(
				'test_condition_name' => '種別と検索語の両方で絞り込める（正常系）',
				'conditions'          => array(
					'type'   => 'blacklist',
					'search' => 'taro',
				),
				'expected'            => 1,
			),
			array(
				'test_condition_name' => '一致しなければ 0 件（異常系）',
				'conditions'          => array(
					'type'   => '',
					'search' => 'nobody',
				),
				'expected'            => 0,
			),
		);

		foreach ( $test_cases as $case ) {
			$flags = $this->service->list_guest_flags( $case['conditions']['type'], $case['conditions']['search'] );
			$this->assertCount( $case['expected'], $flags, $case['test_condition_name'] );
		}
	}

	/**
	 * 個人データの消去で、メールが一致する記録と、電話が一致する記録だけが消えることを検証する。
	 */
	public function test_erase_guest_flags(): void {
		$this->service->set_guest_flag( 'erase@example.com', '09011112222', 'blacklist' );
		$this->service->set_guest_flag( '', '09033334444', 'blacklist' );
		$this->service->set_guest_flag( 'keep@example.com', '09055556666', 'blacklist' );

		$removed = $this->service->erase_guest_flags( 'ERASE@example.com', array( '０９０-3333-4444', '', '09099990000' ) );

		$this->assertSame( 2, $removed, 'メール一致の 1 件と電話一致の 1 件が消える' );
		$this->assertSame( array( 'keep@example.com' ), array_column( $this->service->get_guest_records(), 'email' ), '無関係な記録は残る' );
		$this->assertSame( 0, $this->service->erase_guest_flags( '', array( '09055556666' ) ), 'メールが空なら何も消さない（異常系）' );
		$this->assertSame( 0, $this->service->erase_guest_flags( 'nobody@example.com', array() ), '一致しなければ 0 件（境界値）' );
	}

	/**
	 * ゲストの記録が autoload なしの option に保存されることを検証する。
	 */
	public function test_save_guest_records(): void {
		$this->service->set_guest_flag( 'auto@example.com', '', 'caution' );

		global $wpdb;
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Customer_Flag_Repository::OPTION_GUEST_FLAGS ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertContains( $autoload, array( 'no', 'off', 'auto-off' ), '全ページで読み込まれないよう autoload なしで保存する' );

		// 記録が無くなったら option ごと消える。
		$this->service->clear_guest_flag( 'auto@example.com', '' );
		$this->assertFalse( get_option( Customer_Flag_Repository::OPTION_GUEST_FLAGS, false ), '空になったら option を残さない' );
	}

	/**
	 * テストで使う種別定義を登録し直す。
	 */
	private function restore_types(): void {
		$this->service->save_types(
			array(
				array(
					'id'             => 'caution',
					'label'          => '要注意',
					'blocks_booking' => false,
					'block_message'  => '',
				),
				array(
					'id'             => 'blacklist',
					'label'          => 'ブラックリスト',
					'blocks_booking' => true,
					'block_message'  => 'ご予約をお受けできません。',
				),
			)
		);
	}
}
