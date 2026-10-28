<?php
/**
 * 予約のご利用になる方の照合・名簿への保存のテスト。
 *
 * @package VKBookingManager
 */

use VKBookingManager\Attendees\Attendee_Roster;
use VKBookingManager\Attendees\Booking_Attendee_Resolver;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\ProviderSettings\Settings_Repository;

/**
 * Booking_Attendee_Resolver の照合結果を確認する。
 *
 * @group attendees
 */
class Test_Booking_Attendee_Resolver extends WP_UnitTestCase {
	/**
	 * 家族の UUID。
	 */
	private const FAMILY_ID = '11111111-1111-4111-8111-111111111111';

	/**
	 * 他人の名簿にだけある UUID。
	 */
	private const OTHERS_ID = '22222222-2222-4222-8222-222222222222';

	/**
	 * 名簿サービス。
	 *
	 * @var Attendee_Roster
	 */
	private $roster;

	/**
	 * 名簿の持ち主。
	 *
	 * @var int
	 */
	private $owner_id;

	/**
	 * 他の会員。
	 *
	 * @var int
	 */
	private $other_id;

	/**
	 * テスト対象と名簿を準備する。
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->roster   = new Attendee_Roster();
		$this->owner_id = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'first_name' => '一郎',
				'last_name'  => '山田',
			)
		);
		$this->other_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $this->owner_id );

		$self = array(
			'id'     => 'self',
			'name'   => '山田 一郎',
			'kana'   => '',
			'number' => 'S-1',
		);
		$this->roster->save(
			$this->owner_id,
			array(
				$self,
				array(
					'id'     => self::FAMILY_ID,
					'name'   => '山田 太郎',
					'kana'   => '',
					'number' => 'F-2',
				),
			)
		);
		$this->roster->save(
			$this->other_id,
			array(
				array(
					'id'     => 'self',
					'name'   => '他人',
					'kana'   => '',
					'number' => '',
				),
				array(
					'id'     => self::OTHERS_ID,
					'name'   => '他人の家族',
					'kana'   => '',
					'number' => 'O-9',
				),
			)
		);
	}

	/**
	 * 名簿機能・番号機能を有効にした設定値を返す。
	 *
	 * @param array<string,mixed> $override 上書きする設定。
	 * @return array<string,mixed> 設定値。
	 */
	private function settings( array $override = array() ): array {
		$settings = array_merge(
			( new Settings_Repository() )->get_settings(),
			array(
				'attendee_roster_enabled'   => true,
				'attendee_roster_show_self' => true,
				'attendee_number_enabled'   => true,
			)
		);
		return array_merge( $settings, $override );
	}

	/**
	 * 管理者権限を持つスタッフでログインする。
	 *
	 * @return int スタッフのユーザー ID。
	 */
	private function login_as_staff(): int {
		$staff_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $staff_id )->add_cap( Capabilities::MANAGE_RESERVATIONS );
		if ( is_multisite() ) {
			grant_super_admin( $staff_id );
		}
		wp_set_current_user( $staff_id );
		return $staff_id;
	}

	/**
	 * 名簿の選択を検証し、名前と番号をサーバー側の名簿から写すことを確認する。
	 *
	 * @return void
	 */
	public function test_resolve(): void {
		$cases = array(
			array(
				'test_condition_name' => '本人を選ぶ => プロフィールの名前と名簿の番号を写す',
				'settings'            => $this->settings(),
				'input'               => array( 'attendee_id' => 'self' ),
				'expected'            => array(
					'id'     => 'self',
					'name'   => '一郎 山田',
					'number' => 'S-1',
				),
			),
			array(
				'test_condition_name' => '名簿の人を選ぶ => 送られた名前を無視して名簿の名前と番号を写す',
				'settings'            => $this->settings(),
				'input'               => array(
					'attendee_id'     => self::FAMILY_ID,
					'attendee_name'   => '改ざんされた名前',
					'attendee_number' => '999',
				),
				'expected'            => array(
					'id'     => self::FAMILY_ID,
					'name'   => '山田 太郎',
					'number' => 'F-2',
				),
			),
			array(
				'test_condition_name' => '番号機能が無効 => 番号は写さない',
				'settings'            => $this->settings( array( 'attendee_number_enabled' => false ) ),
				'input'               => array( 'attendee_id' => self::FAMILY_ID ),
				'expected'            => array(
					'id'     => self::FAMILY_ID,
					'name'   => '山田 太郎',
					'number' => '',
				),
			),
			array(
				'test_condition_name' => '名簿に無い人を手入力 => manual で名前だけ保存する',
				'settings'            => $this->settings(),
				'input'               => array(
					'attendee_id'     => 'manual',
					'attendee_name'   => '知人',
					'attendee_number' => '会員が送った番号',
				),
				'expected'            => array(
					'id'     => 'manual',
					'name'   => '知人',
					'number' => '',
				),
			),
			array(
				'test_condition_name' => '名簿機能が無効で名前を入力 => 従来どおり手入力として manual を保存する',
				'settings'            => $this->settings(
					array(
						'attendee_roster_enabled'    => false,
						'booking_attendee_name_mode' => 'optional',
					)
				),
				'input'               => array(
					'attendee_id'   => self::FAMILY_ID,
					'attendee_name' => 'ポチ',
				),
				'expected'            => array(
					'id'     => 'manual',
					'name'   => 'ポチ',
					'number' => '',
				),
			),
			array(
				'test_condition_name' => '選択が送られず名前も空 => 何も保存しない',
				'settings'            => $this->settings(),
				'input'               => array(),
				'expected'            => array(
					'id'     => '',
					'name'   => '',
					'number' => '',
				),
			),
		);

		foreach ( $cases as $case ) {
			$actual = ( new Booking_Attendee_Resolver( $this->roster ) )->resolve( $case['settings'], $this->owner_id, $case['input'], false );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * 他人の UUID・削除済みの UUID・存在しない選択を拒否することを確認する。
	 *
	 * @return void
	 */
	public function test_resolve_rejects_invalid_selection(): void {
		// 名簿から削除済みの UUID を用意する。
		$this->roster->save(
			$this->owner_id,
			array(
				array(
					'id'     => 'self',
					'name'   => '山田 一郎',
					'kana'   => '',
					'number' => 'S-1',
				),
			)
		);

		$cases = array(
			array(
				'test_condition_name' => '他人の名簿の UUID => attendee_not_found',
				'settings'            => $this->settings(),
				'input'               => array( 'attendee_id' => self::OTHERS_ID ),
				'owner_id'            => $this->owner_id,
				'expected'            => 'attendee_not_found',
			),
			array(
				'test_condition_name' => '削除済みの UUID => attendee_not_found',
				'settings'            => $this->settings(),
				'input'               => array( 'attendee_id' => self::FAMILY_ID ),
				'owner_id'            => $this->owner_id,
				'expected'            => 'attendee_not_found',
			),
			array(
				'test_condition_name' => '形式が不正な ID => attendee_not_found',
				'settings'            => $this->settings(),
				'input'               => array( 'attendee_id' => 'not-a-uuid' ),
				'owner_id'            => $this->owner_id,
				'expected'            => 'attendee_not_found',
			),
			array(
				'test_condition_name' => '本人を表示しない設定で本人を選ぶ => attendee_not_found',
				'settings'            => $this->settings( array( 'attendee_roster_show_self' => false ) ),
				'input'               => array( 'attendee_id' => 'self' ),
				'owner_id'            => $this->owner_id,
				'expected'            => 'attendee_not_found',
			),
			array(
				'test_condition_name' => '持ち主がいない代理予約で本人を選ぶ => attendee_not_found',
				'settings'            => $this->settings(),
				'input'               => array( 'attendee_id' => 'self' ),
				'owner_id'            => 0,
				'expected'            => 'attendee_not_found',
			),
			array(
				'test_condition_name' => '会員が手入力の名前を空にする => attendee_name_required',
				'settings'            => $this->settings(),
				'input'               => array(
					'attendee_id'   => 'manual',
					'attendee_name' => '',
				),
				'owner_id'            => $this->owner_id,
				'expected'            => 'attendee_name_required',
			),
		);

		foreach ( $cases as $case ) {
			$actual = ( new Booking_Attendee_Resolver( $this->roster ) )->resolve( $case['settings'], $case['owner_id'], $case['input'], false );
			$this->assertWPError( $actual, $case['test_condition_name'] );
			$this->assertSame( $case['expected'], $actual->get_error_code(), $case['test_condition_name'] );
		}
	}

	/**
	 * 「名簿に保存する」で先に名簿へ登録し、同じ UUID の再送では1件しか増えないことを確認する。
	 *
	 * @return void
	 */
	public function test_resolve_saves_new_attendee_idempotently(): void {
		$new_id   = '33333333-3333-4333-8333-333333333333';
		$resolver = new Booking_Attendee_Resolver( $this->roster );
		$input    = array(
			'attendee_id'             => 'manual',
			'attendee_name'           => '新しい家族',
			'attendee_new_id'         => $new_id,
			'attendee_save_to_roster' => true,
		);

		$cases = array(
			array(
				'test_condition_name' => '1回目の保存 => UUID で名簿に登録され、予約にも写る',
				'input'               => $input,
				'expected_result'     => array(
					'id'     => $new_id,
					'name'   => '新しい家族',
					'number' => '',
				),
				'expected_count'      => 3,
			),
			array(
				'test_condition_name' => '同じ UUID の再送 => 名簿は増えず、同じ結果',
				'input'               => $input,
				'expected_result'     => array(
					'id'     => $new_id,
					'name'   => '新しい家族',
					'number' => '',
				),
				'expected_count'      => 3,
			),
			array(
				'test_condition_name' => '同じ UUID で名前を変えて再送 => 名簿の名前を上書きしない',
				'input'               => array_merge( $input, array( 'attendee_name' => '別の名前' ) ),
				'expected_result'     => array(
					'id'     => $new_id,
					'name'   => '新しい家族',
					'number' => '',
				),
				'expected_count'      => 3,
			),
			array(
				'test_condition_name' => '保存を指定しない手入力 => 名簿は増えず manual',
				'input'               => array(
					'attendee_id'   => 'manual',
					'attendee_name' => '保存しない人',
				),
				'expected_result'     => array(
					'id'     => 'manual',
					'name'   => '保存しない人',
					'number' => '',
				),
				'expected_count'      => 3,
			),
		);

		foreach ( $cases as $case ) {
			$actual = $resolver->resolve( $this->settings(), $this->owner_id, $case['input'], false );
			$this->assertSame( $case['expected_result'], $actual, $case['test_condition_name'] );
			$this->assertCount( $case['expected_count'], $this->roster->get( $this->owner_id ), $case['test_condition_name'] );
		}
	}

	/**
	 * 名簿が上限に達している場合は保存せずエラーにすることを確認する。
	 *
	 * @return void
	 */
	public function test_resolve_rejects_save_over_limit(): void {
		$rows = array(
			array(
				'id'     => 'self',
				'name'   => '山田 一郎',
				'kana'   => '',
				'number' => '',
			),
		);
		for ( $i = 0; $i < Attendee_Roster::MAX_ATTENDEES; $i++ ) {
			$rows[] = array(
				'id'     => wp_generate_uuid4(),
				'name'   => 'メンバー' . $i,
				'kana'   => '',
				'number' => '',
			);
		}
		$this->assertTrue( $this->roster->save( $this->owner_id, $rows ) );

		$actual = ( new Booking_Attendee_Resolver( $this->roster ) )->resolve(
			$this->settings(),
			$this->owner_id,
			array(
				'attendee_id'             => 'manual',
				'attendee_name'           => '21人目',
				'attendee_save_to_roster' => true,
			),
			false
		);

		$this->assertWPError( $actual, '21人目の保存はエラー' );
		$this->assertSame( 'vkbm_attendee_limit', $actual->get_error_code() );
		$this->assertCount( Attendee_Roster::MAX_ATTENDEES + 1, $this->roster->get( $this->owner_id ), '名簿は増えない' );
	}

	/**
	 * 代理予約では、一致した会員の名簿と照合し、権限がある場合だけ会員の名簿へ保存できることを確認する。
	 *
	 * @return void
	 */
	public function test_resolve_proxy(): void {
		$this->login_as_staff();
		$resolver = new Booking_Attendee_Resolver( $this->roster );
		$new_id   = '44444444-4444-4444-8444-444444444444';

		$cases = array(
			array(
				'test_condition_name' => '一致した会員の名簿の人を選ぶ => 名簿から写す',
				'owner_id'            => $this->owner_id,
				'input'               => array( 'attendee_id' => self::FAMILY_ID ),
				'expected'            => array(
					'id'     => self::FAMILY_ID,
					'name'   => '山田 太郎',
					'number' => 'F-2',
				),
			),
			array(
				'test_condition_name' => '別の会員の UUID を選ぶ => 拒否',
				'owner_id'            => $this->owner_id,
				'input'               => array( 'attendee_id' => self::OTHERS_ID ),
				'expected'            => 'attendee_not_found',
			),
			array(
				'test_condition_name' => '手入力して会員の名簿に保存・番号も入力 => 登録され番号も写る',
				'owner_id'            => $this->owner_id,
				'input'               => array(
					'attendee_id'             => 'manual',
					'attendee_name'           => '代理で入力した人',
					'attendee_number'         => 'P-7',
					'attendee_new_id'         => $new_id,
					'attendee_save_to_roster' => true,
				),
				'expected'            => array(
					'id'     => $new_id,
					'name'   => '代理で入力した人',
					'number' => 'P-7',
				),
			),
			array(
				'test_condition_name' => '会員が見つからない代理予約で保存を指定 => 保存できないため manual のまま',
				'owner_id'            => 0,
				'input'               => array(
					'attendee_id'             => 'manual',
					'attendee_name'           => '会員不明の人',
					'attendee_save_to_roster' => true,
				),
				'expected'            => array(
					'id'     => 'manual',
					'name'   => '会員不明の人',
					'number' => '',
				),
			),
			array(
				'test_condition_name' => '手入力の名前が空の代理予約 => manual（名前は空）を保存する',
				'owner_id'            => $this->owner_id,
				'input'               => array(
					'attendee_id'   => 'manual',
					'attendee_name' => '',
				),
				'expected'            => array(
					'id'     => 'manual',
					'name'   => '',
					'number' => '',
				),
			),
			array(
				'test_condition_name' => '選択を送らず空のままの代理予約 => 本人扱いにならないよう manual を保存する',
				'owner_id'            => $this->owner_id,
				'input'               => array(),
				'expected'            => array(
					'id'     => 'manual',
					'name'   => '',
					'number' => '',
				),
			),
		);

		foreach ( $cases as $case ) {
			$actual = $resolver->resolve( $this->settings(), $case['owner_id'], $case['input'], true );
			if ( is_string( $case['expected'] ) ) {
				$this->assertWPError( $actual, $case['test_condition_name'] );
				$this->assertSame( $case['expected'], $actual->get_error_code(), $case['test_condition_name'] );
				continue;
			}
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}

		// 予約管理権限はあるがユーザー編集権限が無いスタッフは、会員の名簿へ保存できない。
		$limited_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_userdata( $limited_id )->add_cap( Capabilities::MANAGE_RESERVATIONS );
		wp_set_current_user( $limited_id );
		$actual = $resolver->resolve(
			$this->settings(),
			$this->owner_id,
			array(
				'attendee_id'             => 'manual',
				'attendee_name'           => '権限が無い保存',
				'attendee_save_to_roster' => true,
			),
			true
		);
		$this->assertWPError( $actual, 'ユーザー編集権限が無いスタッフの保存は拒否' );
		$this->assertSame( 'attendee_roster_forbidden', $actual->get_error_code() );

		// 同じスタッフは、会員の名簿の人（UUID・本人）も選べず、選択肢にも中身が出ない。
		foreach ( array( self::FAMILY_ID, 'self' ) as $id ) {
			$actual = $resolver->resolve( $this->settings(), $this->owner_id, array( 'attendee_id' => $id ), true );
			$this->assertWPError( $actual, 'ユーザー編集権限が無いスタッフは名簿の人を選べない: ' . $id );
			$this->assertSame( 'attendee_not_found', $actual->get_error_code() );
		}
		$options = $resolver->get_options( $this->settings(), get_userdata( $this->owner_id ), true );
		$this->assertSame( array(), $options['items'], 'ユーザー編集権限が無いスタッフには名簿の中身を返さない' );
		$this->assertFalse( $options['has_owner'], 'ユーザー編集権限が無いスタッフには会員を明かさない' );
		$this->assertSame( '', $options['owner_name'] );
	}

	/**
	 * 予約確認画面へ渡す選択肢を確認する。
	 *
	 * @return void
	 */
	public function test_get_options(): void {
		$owner    = get_userdata( $this->owner_id );
		$resolver = new Booking_Attendee_Resolver( $this->roster );

		$cases = array(
			array(
				'test_condition_name' => '名簿機能が無効 => enabled が false',
				'settings'            => $this->settings( array( 'attendee_roster_enabled' => false ) ),
				'owner'               => $owner,
				'expected'            => array(
					'enabled' => false,
				),
			),
			array(
				'test_condition_name' => '本人を表示する => 本人と家族を番号付きで返す',
				'settings'            => $this->settings(),
				'owner'               => $owner,
				'expected'            => array(
					'enabled'       => true,
					'item_ids'      => array( 'self', self::FAMILY_ID ),
					'item_numbers'  => array( 'S-1', 'F-2' ),
					'has_owner'     => true,
					'can_save'      => true,
					'limit_reached' => false,
				),
			),
			array(
				'test_condition_name' => '本人を表示しない・番号機能が無効 => 家族だけを番号なしで返す',
				'settings'            => $this->settings(
					array(
						'attendee_roster_show_self' => false,
						'attendee_number_enabled'   => false,
					)
				),
				'owner'               => $owner,
				'expected'            => array(
					'enabled'       => true,
					'item_ids'      => array( self::FAMILY_ID ),
					'item_numbers'  => array( '' ),
					'has_owner'     => true,
					'can_save'      => true,
					'limit_reached' => false,
				),
			),
			array(
				'test_condition_name' => '代理予約で会員が未確定 => 選択肢なし・保存不可',
				'settings'            => $this->settings(),
				'owner'               => null,
				'expected'            => array(
					'enabled'       => true,
					'item_ids'      => array(),
					'item_numbers'  => array(),
					'has_owner'     => false,
					'can_save'      => false,
					'limit_reached' => false,
				),
			),
		);

		foreach ( $cases as $case ) {
			$options = $resolver->get_options( $case['settings'], $case['owner'], false );
			if ( false === $case['expected']['enabled'] ) {
				$this->assertSame( $case['expected'], $options, $case['test_condition_name'] );
				continue;
			}
			$this->assertTrue( $options['enabled'], $case['test_condition_name'] );
			$this->assertSame( $case['expected']['item_ids'], array_column( $options['items'], 'id' ), $case['test_condition_name'] );
			$this->assertSame( $case['expected']['item_numbers'], array_column( $options['items'], 'number' ), $case['test_condition_name'] );
			$this->assertSame( $case['expected']['has_owner'], $options['has_owner'], $case['test_condition_name'] );
			$this->assertSame( $case['expected']['can_save'], (bool) $options['can_save'], $case['test_condition_name'] );
			$this->assertSame( $case['expected']['limit_reached'], $options['limit_reached'], $case['test_condition_name'] );
		}
	}
}
