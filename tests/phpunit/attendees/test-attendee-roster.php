<?php
/**
 * 名簿保存サービスのテスト。
 *
 * @package VKBookingManager
 */

use VKBookingManager\Attendees\Attendee_Roster;

/**
 * 名簿の検証、保存、番号メタ同期を確認する。
 */
class Test_Attendee_Roster extends WP_UnitTestCase {
	/**
	 * 名簿サービス。
	 *
	 * @var Attendee_Roster
	 */
	private $roster;

	/**
	 * テスト対象を準備する。
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->roster = new Attendee_Roster();
	}

	/**
	 * 番号の表記揺れを同じ検索値へ正規化できることを確認する。
	 *
	 * @return void
	 */
	public function test_normalize_number(): void {
		$cases = array(
			array(
				'test_condition_name' => '全角英数字と前後空白を半角小文字にする',
				'input'               => ' ＡＢＣ１２３ ',
				'expected'            => 'abc123',
			),
			array(
				'test_condition_name' => '半角大文字を小文字にする',
				'input'               => 'Card-X',
				'expected'            => 'card-x',
			),
			array(
				'test_condition_name' => '空白だけは空文字にする',
				'input'               => '　 ',
				'expected'            => '',
			),
		);
		foreach ( $cases as $case ) {
			$this->assertSame( $case['expected'], Attendee_Roster::normalize_number( $case['input'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * 名前、本人、上限の検証を確認する。
	 *
	 * @return void
	 */
	public function test_sanitize_rows(): void {
		$valid = array(
			array(
				'id'   => 'self',
				'name' => '本人',
			),
			array(
				'id'   => '',
				'name' => '家族',
			),
		);
		$this->assertCount( 2, $this->roster->sanitize_rows( $valid ), '正常な本人と家族を保存形式へ変換する' );
		$this->assertWPError(
			$this->roster->sanitize_rows(
				array(
					array(
						'id'   => 'self',
						'name' => '',
					),
				)
			),
			'名前が空ならエラーにする'
		);
		$this->assertWPError(
			$this->roster->sanitize_rows(
				array(
					array(
						'id'   => 'other',
						'name' => '家族',
					),
				)
			),
			'本人が無ければエラーにする'
		);
		$too_many = array(
			array(
				'id'   => 'self',
				'name' => '本人',
			),
		);
		for ( $index = 0; $index <= Attendee_Roster::MAX_ATTENDEES; $index++ ) {
			$too_many[] = array(
				'id'   => 'person-' . $index,
				'name' => '家族' . $index,
			);
		}
		$this->assertWPError( $this->roster->sanitize_rows( $too_many ), '本人以外が20件を超えたらエラーにする' );
	}

	/**
	 * 保存時に番号検索用メタを1番号1行で作り直すことを確認する。
	 *
	 * @return void
	 */
	public function test_save(): void {
		$user_id = self::factory()->user->create();
		$rows    = array(
			array(
				'id'     => 'self',
				'name'   => '本人',
				'kana'   => '',
				'number' => ' Ａ-１ ',
			),
			array(
				'id'     => 'person-1',
				'name'   => '家族',
				'kana'   => '',
				'number' => 'B-2',
			),
		);
		$this->assertTrue( $this->roster->save( $user_id, $rows ), '正常な名簿を保存できる' );
		$this->assertSame( array( 'a-1', 'b-2' ), get_user_meta( $user_id, Attendee_Roster::get_number_meta_key(), false ), '正規化した番号を複数行で保存する' );

		$rows[1]['number'] = '';
		$this->assertTrue( $this->roster->save( $user_id, $rows ), '番号を空に変更した名簿を保存できる' );
		$this->assertSame( array( 'a-1' ), get_user_meta( $user_id, Attendee_Roster::get_number_meta_key(), false ), '古い番号メタを削除して作り直す' );
	}

	/**
	 * 初回保存では一意な meta 行だけを作成することを確認する。
	 *
	 * @return void
	 */
	public function test_save_initial_value(): void {
		$user_id = self::factory()->user->create();
		$rows    = array(
			array(
				'id'     => 'self',
				'name'   => '本人',
				'kana'   => '',
				'number' => '',
			),
		);
		$this->assertTrue( $this->roster->save( $user_id, $rows ), 'meta が無い状態から保存できる' );
		$this->assertCount( 1, get_user_meta( $user_id, Attendee_Roster::get_roster_meta_key(), false ), '名簿 meta は1行だけ作る' );
	}

	/**
	 * 描画後に別経路で追加された行を一括保存で消さないことを確認する。
	 *
	 * @return void
	 */
	public function test_save_with_rendered_base(): void {
		$user_id = self::factory()->user->create();
		$base    = array(
			array(
				'id'     => 'self',
				'name'   => '本人',
				'kana'   => '',
				'number' => '',
			),
		);
		$this->assertTrue( $this->roster->save( $user_id, $base ), '描画時の名簿を保存できる' );
		$latest   = $base;
		$latest[] = array(
			'id'     => 'concurrent',
			'name'   => '同時追加',
			'kana'   => '',
			'number' => '',
		);
		update_user_meta( $user_id, Attendee_Roster::get_roster_meta_key(), $latest );
		$desired            = $base;
		$desired[0]['name'] = '本人変更';
		$this->assertTrue( $this->roster->save( $user_id, $desired, $base ), '描画時の名簿を基準に変更行だけ保存できる' );
		$saved = $this->roster->get( $user_id );
		$this->assertSame( '本人変更', $saved[0]['name'], 'スタッフの変更を反映する' );
		$this->assertSame( '同時追加', $saved[1]['name'], '描画後に追加された行を残す' );
	}

	/**
	 * 保存前 meta が無く、描画側だけに本人行がある場合も初回保存できることを確認する。
	 *
	 * @return void
	 */
	public function test_save_with_rendered_self_and_no_meta(): void {
		$user_id = self::factory()->user->create();
		$self    = array(
			'id'     => 'self',
			'name'   => '本人',
			'kana'   => '',
			'number' => '',
		);

		$this->assertTrue( $this->roster->save( $user_id, array( $self ), array( $self ) ), 'meta が無くても描画済み本人行を保存できる' );
		$this->assertSame( 'self', $this->roster->get( $user_id )[0]['id'], '保存結果に本人行を残す' );
	}

	/**
	 * 比較書き込みが競合した場合に読み直して再試行することを確認する。
	 *
	 * @return void
	 */
	public function test_save_retries_after_conflict(): void {
		$user_id = self::factory()->user->create();
		$key     = Attendee_Roster::get_roster_meta_key();
		update_user_meta(
			$user_id,
			$key,
			array(
				array(
					'id'     => 'self',
					'name'   => '旧名',
					'kana'   => '',
					'number' => '',
				),
			)
		);
		$changed = false;
		$filter  = static function ( $check, $object_id, $meta_key ) use ( $user_id, $key, &$changed ) {
			global $wpdb;
			if ( ! $changed && $user_id === (int) $object_id && $key === $meta_key ) {
				$changed = true;
				// オブジェクトキャッシュを消さず、別プロセスが DB を更新した状態を再現する。
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- キャッシュを消さない別プロセスの競合を再現する。
					$wpdb->usermeta,
					array(
						'meta_value' => maybe_serialize(
							array(
								array(
									'id'     => 'self',
									'name'   => '旧名',
									'kana'   => '',
									'number' => '',
								),
								array(
									'id'     => 'concurrent',
									'name'   => '同時追加',
									'kana'   => '',
									'number' => '',
								),
							)
						),
					),
					array(
						'user_id'  => $user_id,
						'meta_key' => $key,
					),
					array( '%s' ),
					array( '%d', '%s' )
				);
				return false;
			}
			return $check;
		};
		add_filter( 'update_user_metadata', $filter, 10, 3 );
		$result = $this->roster->save(
			$user_id,
			array(
				array(
					'id'     => 'self',
					'name'   => '新名',
					'kana'   => '',
					'number' => '',
				),
			)
		);
		remove_filter( 'update_user_metadata', $filter, 10 );
		$this->assertTrue( $result, '競合後の再試行で保存できる' );
		$this->assertSame( '新名', $this->roster->get( $user_id )[0]['name'], '再読込後の値へ書き込む' );
		$this->assertSame( '同時追加', $this->roster->get( $user_id )[1]['name'], '同時に追加された未変更行を残す' );
	}

	/**
	 * マルチサイトではサイト ID 付きのキーを返すことを確認する。
	 *
	 * @return void
	 */
	public function test_get_roster_meta_key(): void {
		$expected = is_multisite() ? 'vkbm_attendees_site_' . get_current_blog_id() : 'vkbm_attendees';
		$this->assertSame( $expected, Attendee_Roster::get_roster_meta_key(), '環境に応じた名簿キーを返す' );
		$number_expected = is_multisite() ? 'vkbm_attendee_number_site_' . get_current_blog_id() : 'vkbm_attendee_number';
		$this->assertSame( $number_expected, Attendee_Roster::get_number_meta_key(), '環境に応じた番号キーを返す' );
	}
}
