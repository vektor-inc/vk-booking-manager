<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Customers;

use VKBookingManager\Common\Nomination_Min_Guests_Message;
use VKBookingManager\Customers\Customer_Flag_Repository;
use VKBookingManager\Customers\Customer_Flag_Service;
use WP_UnitTestCase;

/**
 * 顧客フラグサービスのテスト。
 *
 * @group customer-flags
 */
class Customer_Flag_Service_Test extends WP_UnitTestCase {
	use Customer_Flag_Options_Cleanup_Trait;

	/**
	 * テスト対象サービス。
	 *
	 * @var Customer_Flag_Service
	 */
	private $service;

	/**
	 * 予約顧客（購読者ロール）のユーザーを作成する。
	 *
	 * @return int ユーザー ID。
	 */
	private function create_customer(): int {
		return self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	public function set_up(): void {
		parent::set_up();

		// 各テスト前にオプションをクリーンにし、テスト間で割り当て状態が漏れないようにする。
		$this->delete_all_flag_options();

		$this->service = new Customer_Flag_Service();

		// 既知の ID を持つフラグ種別を事前に登録しておく。
		// - caution      : 非ブロック種別。
		// - blacklist    : ブロック種別（メッセージあり）。
		// - silent_block : ブロック種別（メッセージなし＝既定文言の確認用）。
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
				array(
					'id'             => 'silent_block',
					'label'          => '出入禁止',
					'blocks_booking' => true,
					'block_message'  => '',
				),
			)
		);
	}

	public function tear_down(): void {
		$this->delete_all_flag_options();

		parent::tear_down();
	}

	/**
	 * フラグを割り当てられるユーザー（予約顧客）かの判定を検証する。
	 */
	public function test_can_have_flag(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '購読者（予約顧客）は割り当てられる（正常系）',
				'conditions'          => array( 'role' => 'subscriber' ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '管理者は割り当てられない（代理予約で管理者自身に付くのを防ぐ）',
				'conditions'          => array( 'role' => 'administrator' ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '編集者は割り当てられない（正常系）',
				'conditions'          => array( 'role' => 'editor' ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '存在しないユーザー ID は割り当てられない（異常系）',
				'conditions'          => array( 'role' => '' ),
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$user_id = '' === $case['conditions']['role']
				? PHP_INT_MAX
				: self::factory()->user->create( array( 'role' => $case['conditions']['role'] ) );

			$this->assertSame( $case['expected'], $this->service->can_have_flag( $user_id ), $case['test_condition_name'] );
		}
	}

	/**
	 * フラグ種別定義のサニタイズを検証する。
	 */
	public function test_sanitize_types(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'ラベルとIDが揃った2件はそのまま2件として正規化される（正常系）',
				'conditions'          => array(
					'raw' => array(
						array(
							'id'             => 'vip',
							'label'          => 'VIP',
							'blocks_booking' => false,
							'block_message'  => '',
						),
						array(
							'id'             => 'ng',
							'label'          => 'NG',
							'blocks_booking' => true,
							'block_message'  => 'お断りします。',
						),
					),
				),
				'expected'            => array(
					'count'  => 2,
					'labels' => array(
						'VIP' => false,
						'NG'  => true,
					),
				),
			),
			array(
				'test_condition_name' => 'ID未指定の行にも自動でIDが付与され登録される（正常系）',
				'conditions'          => array(
					'raw' => array(
						array(
							'label'          => '常連',
							'blocks_booking' => false,
							'block_message'  => '',
						),
					),
				),
				'expected'            => array(
					'count'  => 1,
					'labels' => array(
						'常連' => false,
					),
				),
			),
			array(
				'test_condition_name' => 'ラベルが空の行は除外される（異常系）',
				'conditions'          => array(
					'raw' => array(
						array(
							'id'             => 'keep',
							'label'          => '残す',
							'blocks_booking' => false,
							'block_message'  => '',
						),
						array(
							'id'             => 'drop',
							'label'          => '',
							'blocks_booking' => true,
							'block_message'  => '消える',
						),
					),
				),
				'expected'            => array(
					'count'  => 1,
					'labels' => array(
						'残す' => false,
					),
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = $this->service->sanitize_types( $case['conditions']['raw'] );

			$this->assertCount( $case['expected']['count'], $actual, $case['test_condition_name'] );

			// ラベル => ブロック可否 のマップを作って期待値と突き合わせる。
			$actual_map = array();
			foreach ( $actual as $type ) {
				$actual_map[ $type['label'] ] = $type['blocks_booking'];
				// 自動採番を含め ID は必ず非空であること。
				$this->assertNotSame( '', (string) $type['id'], $case['test_condition_name'] );
			}

			foreach ( $case['expected']['labels'] as $label => $blocks ) {
				$this->assertArrayHasKey( $label, $actual_map, $case['test_condition_name'] );
				$this->assertSame( $blocks, $actual_map[ $label ], $case['test_condition_name'] );
			}
		}
	}

	/**
	 * フラグ種別の保存が冪等に成功を返すことを検証する。
	 */
	public function test_save_types(): void {
		$types = array(
			array(
				'id'             => 'vip',
				'label'          => 'VIP',
				'blocks_booking' => false,
				'block_message'  => '',
			),
		);

		$test_cases = array(
			array(
				'test_condition_name' => '初回保存は成功してtrueを返す（正常系）',
				'conditions'          => array( 'types' => $types ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '同一内容の再保存も冪等に成功してtrueを返す（境界値）',
				'conditions'          => array( 'types' => $types ),
				'expected'            => true,
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = $this->service->save_types( $case['conditions']['types'] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}

		// 保存内容が取得できることも確認する。
		$loaded = $this->service->get_types();
		$this->assertCount( 1, $loaded, '保存した種別が1件取得できる' );
		$this->assertSame( 'VIP', $loaded[0]['label'], '保存した種別のラベルが取得できる' );
	}

	/**
	 * フラグの割り当てと解決を検証する。
	 */
	public function test_set_flag(): void {
		$user_id  = $this->create_customer();
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$test_cases = array(
			array(
				'test_condition_name' => '非ブロック種別を割り当てるとラベル・種別IDが解決される（正常系）',
				'conditions'          => array(
					'type_id' => 'caution',
					'memo'    => '遅刻が多い',
				),
				'expected'            => array(
					'type_id'        => 'caution',
					'label'          => '要注意',
					'blocks_booking' => false,
					'memo'           => '遅刻が多い',
				),
			),
			array(
				'test_condition_name' => 'ブロック種別を割り当てるとブロック可否がtrueで解決される（正常系）',
				'conditions'          => array(
					'type_id' => 'blacklist',
					'memo'    => '無断キャンセル',
				),
				'expected'            => array(
					'type_id'        => 'blacklist',
					'label'          => 'ブラックリスト',
					'blocks_booking' => true,
					'memo'           => '無断キャンセル',
				),
			),
			array(
				'test_condition_name' => '存在しない種別IDは割り当てられずフラグは無し（異常系）',
				'conditions'          => array(
					'type_id' => 'unknown_type',
					'memo'    => '',
				),
				'expected'            => null,
			),
			array(
				'test_condition_name' => '予約顧客でないユーザー（管理者）には割り当てられない（異常系）',
				'conditions'          => array(
					'type_id' => 'caution',
					'memo'    => '',
					'user'    => 'admin',
				),
				'expected'            => null,
			),
		);

		foreach ( $test_cases as $case ) {
			$target_id = isset( $case['conditions']['user'] ) ? $admin_id : $user_id;

			// 各ケースを独立させるため、事前に割り当てを解除する。
			$this->service->clear_flag( $target_id );

			$this->service->set_flag( $target_id, $case['conditions']['type_id'], $case['conditions']['memo'] );
			$actual = $this->service->get_flag( $target_id );

			if ( null === $case['expected'] ) {
				$this->assertNull( $actual, $case['test_condition_name'] );
				continue;
			}

			$this->assertIsArray( $actual, $case['test_condition_name'] );
			$this->assertSame( $case['expected']['type_id'], $actual['type_id'], $case['test_condition_name'] );
			$this->assertSame( $case['expected']['label'], $actual['label'], $case['test_condition_name'] );
			$this->assertSame( $case['expected']['blocks_booking'], $actual['blocks_booking'], $case['test_condition_name'] );
			$this->assertSame( $case['expected']['memo'], $actual['memo'], $case['test_condition_name'] );
		}
	}

	/**
	 * 種別・メモが変わらない再保存では監査情報（assigned_at / assigned_by）が温存されることを検証する。
	 *
	 * 実質的な変更が無い再保存のたびに assigned_at が書き換わると、付与日時の意味が失われてしまうため、
	 * 同一内容なら早期 return して既存の監査情報を保持する挙動を担保する。
	 */
	public function test_set_flag_preserves_audit_info_on_unchanged_resave(): void {
		$user_id = $this->create_customer();

		// 最初の割り当て。assigned_by を明示して監査情報を確定させる。
		$repository = new Customer_Flag_Repository();

		$this->service->set_flag( $user_id, 'caution', '遅刻が多い', 11 );
		$first = $repository->get_assignment( $user_id );

		$this->assertIsArray( $first, '初回割り当てが保存されていること' );
		$this->assertSame( 11, (int) $first['assigned_by'], '初回の操作ユーザーIDが保存されていること' );

		// 種別・メモを変えずに、別ユーザー・時刻で再保存しても監査情報は温存される。
		$this->service->set_flag( $user_id, 'caution', '遅刻が多い', 22 );
		$resaved = $repository->get_assignment( $user_id );

		$this->assertSame( $first['assigned_at'], $resaved['assigned_at'], '同一内容の再保存では付与日時が温存されること' );
		$this->assertSame( 11, (int) $resaved['assigned_by'], '同一内容の再保存では操作ユーザーIDが温存されること' );

		// メモを変更した場合は監査情報（assigned_at / assigned_by）が更新される（実質的な変更があるため）。
		// assigned_at は current_time( 'mysql' ) の秒単位のため、変更前に明確な過去日時を書き込んでおき、
		// メモ変更後に必ず異なる日時へ更新されることを決定的に検証する（同一秒内実行によるフレーキーを避ける）。
		$old_assigned_at     = '2000-01-01 00:00:00';
		$seed                = $resaved;
		$seed['assigned_at'] = $old_assigned_at;
		$repository->save_assignment( $user_id, $seed );

		$this->service->set_flag( $user_id, 'caution', '無断キャンセルに変更', 22 );
		$changed = $repository->get_assignment( $user_id );

		$this->assertSame( '無断キャンセルに変更', (string) $changed['memo'], 'メモ変更が反映されること' );
		$this->assertSame( 22, (int) $changed['assigned_by'], '内容変更時は操作ユーザーIDが更新されること' );
		$this->assertNotSame( $old_assigned_at, (string) $changed['assigned_at'], '内容変更時は付与日時が更新されること' );
	}

	/**
	 * フラグの解除を検証する。
	 */
	public function test_clear_flag(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '割り当て済みのフラグを解除するとフラグは無しになる（正常系）',
				'conditions'          => array(
					'assign_before' => true,
				),
				'expected'            => null,
			),
			array(
				'test_condition_name' => '割り当てが無い状態で解除しても例外なくフラグは無し（境界値）',
				'conditions'          => array(
					'assign_before' => false,
				),
				'expected'            => null,
			),
		);

		foreach ( $test_cases as $case ) {
			$user_id = $this->create_customer();
			if ( $case['conditions']['assign_before'] ) {
				$this->service->set_flag( $user_id, 'caution', '理由' );
			}

			$this->service->clear_flag( $user_id );
			$actual = $this->service->get_flag( $user_id );

			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * 予約ブロック対象かどうかの判定を検証する。
	 */
	public function test_is_blocked(): void {
		$user_id = $this->create_customer();

		$test_cases = array(
			array(
				'test_condition_name' => 'ブロック種別が割り当てられていればtrue（正常系）',
				'conditions'          => array( 'type_id' => 'blacklist' ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '非ブロック種別が割り当てられていればfalse（正常系）',
				'conditions'          => array( 'type_id' => 'caution' ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'フラグが無ければfalse（境界値）',
				'conditions'          => array( 'type_id' => '' ),
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->service->clear_flag( $user_id );

			if ( '' !== $case['conditions']['type_id'] ) {
				$this->service->set_flag( $user_id, $case['conditions']['type_id'], '' );
			}

			$actual = $this->service->is_blocked( $user_id );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * ブロック時メッセージの解決を検証する。
	 */
	public function test_get_block_message(): void {
		$user_id = $this->create_customer();

		// 既定文言に差し込まれる事業者名を固定する。
		update_option( 'vkbm_provider_settings', array( 'provider_name' => 'テスト事業者' ) );

		$test_cases = array(
			array(
				'test_condition_name' => 'ブロック種別に固有メッセージがあればそれを返す（正常系）',
				'conditions'          => array( 'type_id' => 'blacklist' ),
				'expected'            => 'ご予約をお受けできません。',
			),
			array(
				'test_condition_name' => 'ブロック種別でメッセージ未設定なら既定文言を返す（正常系）',
				'conditions'          => array( 'type_id' => 'silent_block' ),
				// 既定文言は実装と同じ翻訳関数・結合方法で組み立て、ロケール変更に追従させる。
				'expected'            => Nomination_Min_Guests_Message::join_sentences(
					__( 'This reservation cannot be accepted.', 'vk-booking-manager' ),
					sprintf(
						/* translators: %s: provider (site) name */
						__( 'Please contact %s.', 'vk-booking-manager' ),
						'テスト事業者'
					)
				),
			),
			array(
				'test_condition_name' => '非ブロック種別では空文字を返す（異常系）',
				'conditions'          => array( 'type_id' => 'caution' ),
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->service->clear_flag( $user_id );
			$this->service->set_flag( $user_id, $case['conditions']['type_id'], '' );

			$actual = $this->service->get_block_message( $user_id );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * 新規の種別に付く ID がラベルと無関係に生成されることを検証する。
	 *
	 * ラベルから ID を作ると、名前を変えた種別の元の名前で新しい種別を作ったときに ID が衝突して
	 * 新しい種別が捨てられるため、同じラベルでも毎回別の ID になることを確認する。
	 */
	public function test_sanitize_types_generates_label_independent_id(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '同じラベルの新規行を2回サニタイズしても別の ID になる（正常系）',
				'conditions'          => array( 'label' => '要注意' ),
				'expected'            => array( 'legacy_id' => 'flag_' . substr( md5( '要注意' ), 0, 8 ) ),
			),
		);

		foreach ( $test_cases as $case ) {
			$raw    = array(
				array(
					'label'          => $case['conditions']['label'],
					'blocks_booking' => false,
					'block_message'  => '',
				),
			);
			$first  = $this->service->sanitize_types( $raw );
			$second = $this->service->sanitize_types( $raw );

			$this->assertNotSame( $first[0]['id'], $second[0]['id'], $case['test_condition_name'] );
			$this->assertNotSame( $case['expected']['legacy_id'], $first[0]['id'], $case['test_condition_name'] . ' / ラベル由来の ID ではない' );
		}
	}

	/**
	 * 名前を変えた種別の元の名前で新しい種別を作っても、新しい種別が保存されることを検証する。
	 */
	public function test_save_types_keeps_new_type_with_renamed_types_old_label(): void {
		$this->service->save_types(
			array(
				array(
					'label'          => '出禁',
					'blocks_booking' => true,
					'block_message'  => '',
				),
			)
		);
		$saved = $this->service->get_types();

		// 既存の種別の名前を変え、元の名前で新しい種別を追加して保存する。
		$this->service->save_types(
			array(
				array(
					'id'             => $saved[0]['id'],
					'label'          => '出入り禁止',
					'blocks_booking' => true,
					'block_message'  => '',
				),
				array(
					'label'          => '出禁',
					'blocks_booking' => false,
					'block_message'  => '',
				),
			)
		);

		$labels = array_column( $this->service->get_types(), 'label' );
		$this->assertSame( array( '出入り禁止', '出禁' ), $labels, '名前変更した種別と、元の名前の新しい種別の両方が残る' );
	}

	/**
	 * 削除した種別と同じ名前の種別を作り直しても、削除前の割り当てが復活しないことを検証する。
	 */
	public function test_recreated_type_does_not_revive_old_assignment(): void {
		$user_id = $this->create_customer();

		$test_cases = array(
			array(
				'test_condition_name' => '削除欄のチェックで消した種別を同名で作り直してもブロックされない（異常系）',
				'conditions'          => array( 'delete_by' => 'delete_flag' ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '一覧から外して消した種別を同名で作り直してもブロックされない（異常系）',
				'conditions'          => array( 'delete_by' => 'omit' ),
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->delete_all_flag_options();

			// ブロック種別を作って顧客に割り当てる。
			$this->service->save_types(
				array(
					array(
						'label'          => 'ブロック',
						'blocks_booking' => true,
						'block_message'  => '',
					),
				)
			);
			$type_id = $this->service->get_types()[0]['id'];
			$this->service->set_flag( $user_id, $type_id, '' );
			$this->assertTrue( $this->service->is_blocked( $user_id ), $case['test_condition_name'] . ' / 前提: 削除前はブロックされる' );

			// 種別を削除する。
			if ( 'delete_flag' === $case['conditions']['delete_by'] ) {
				$this->service->save_types(
					array(
						array(
							'id'             => $type_id,
							'label'          => 'ブロック',
							'blocks_booking' => true,
							'block_message'  => '',
							'delete'         => '1',
						),
					)
				);
			} else {
				$this->service->save_types( array() );
			}
			$this->assertSame( array(), $this->service->get_types(), $case['test_condition_name'] . ' / 種別が削除されている' );

			// 同じ名前の種別を作り直す。
			$this->service->save_types(
				array(
					array(
						'label'          => 'ブロック',
						'blocks_booking' => true,
						'block_message'  => '',
					),
				)
			);

			$this->assertSame( $case['expected'], $this->service->is_blocked( $user_id ), $case['test_condition_name'] );
		}
	}

	/**
	 * 種別を削除すると、その種別の割り当てデータ（メモを含む）も消え、他の種別の割り当ては残ることを検証する。
	 */
	public function test_save_types_deletes_assignments_of_deleted_type(): void {
		$repository = new Customer_Flag_Repository();

		$test_cases = array(
			array(
				'test_condition_name' => '削除した種別の割り当ては消える（正常系）',
				'conditions'          => array( 'user' => 'deleted' ),
				'expected'            => null,
			),
			array(
				'test_condition_name' => '残した種別の割り当ては消えない（正常系）',
				'conditions'          => array( 'user' => 'kept' ),
				'expected'            => 'caution',
			),
		);

		// 2 種別をそれぞれ別の顧客に割り当てたうえで、「ブラックリスト」だけを削除する。
		$user_ids = array(
			'deleted' => $this->create_customer(),
			'kept'    => $this->create_customer(),
		);
		$this->service->set_flag( $user_ids['deleted'], 'blacklist', '無断キャンセル' );
		$this->service->set_flag( $user_ids['kept'], 'caution', '遅刻常習' );
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
					'block_message'  => '',
					'delete'         => '1',
				),
			)
		);

		foreach ( $test_cases as $case ) {
			$assignment = $repository->get_assignment( $user_ids[ $case['conditions']['user'] ] );
			$actual     = null !== $assignment ? $assignment['type_id'] : null;

			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * 既存の種別でラベルを空にして保存しても、種別が消えないことを検証する。
	 */
	public function test_save_types_restores_cleared_label_of_existing_type(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '既存の種別のラベルを空にして保存すると、保存済みのラベルで残る（異常系）',
				'conditions'          => array(
					'id'    => 'caution',
					'label' => '',
				),
				'expected'            => '要注意',
			),
			array(
				'test_condition_name' => 'ラベルを書き換えた場合は新しいラベルで保存される（正常系）',
				'conditions'          => array(
					'id'    => 'caution',
					'label' => '注意',
				),
				'expected'            => '注意',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->service->save_types(
				array(
					array(
						'id'             => $case['conditions']['id'],
						'label'          => $case['conditions']['label'],
						'blocks_booking' => false,
						'block_message'  => '',
					),
				)
			);

			$type = $this->service->get_type( $case['conditions']['id'] );
			$this->assertNotNull( $type, $case['test_condition_name'] );
			$this->assertSame( $case['expected'], $type['label'], $case['test_condition_name'] );

			// 次のケースのために元のラベルへ戻す。
			$this->service->save_types(
				array(
					array(
						'id'             => 'caution',
						'label'          => '要注意',
						'blocks_booking' => false,
						'block_message'  => '',
					),
				)
			);
		}
	}

	/**
	 * 顧客がメールアドレスを変えても、フラグ（予約ブロック）が外れないことを検証する。
	 *
	 * メールアドレスに割り当てていたときは、プロフィールでメールアドレスを変えるだけでブロックを外せたため。
	 */
	public function test_is_blocked_after_email_change(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'ブロック種別の顧客がメールアドレスを変えてもブロックされたまま（正常系）',
				'conditions'          => array(
					'type_id'   => 'blacklist',
					'new_email' => 'changed-blocked@example.com',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '非ブロック種別の顧客がメールアドレスを変えてもブロックされない（正常系）',
				'conditions'          => array(
					'type_id'   => 'caution',
					'new_email' => 'changed-caution@example.com',
				),
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$user_id = $this->create_customer();
			$this->service->set_flag( $user_id, $case['conditions']['type_id'], '' );

			wp_update_user(
				array(
					'ID'         => $user_id,
					'user_email' => $case['conditions']['new_email'],
				)
			);

			$this->assertSame( $case['expected'], $this->service->is_blocked( $user_id ), $case['test_condition_name'] );
		}
	}

	/**
	 * 割り当てが予約者ユーザーの user_meta に保存されることを検証する。
	 */
	public function test_assignment_is_saved_to_user_meta(): void {
		$user_id = $this->create_customer();
		$this->service->set_flag( $user_id, 'caution', '遅刻常習', 7 );

		$stored = get_user_meta( $user_id, Customer_Flag_Repository::USER_META_ASSIGNMENT, true );

		$this->assertIsArray( $stored, '割り当てがユーザーメタに保存される' );
		$this->assertSame( 'caution', $stored['type_id'], 'ユーザーメタに種別 ID が保存される' );
		$this->assertSame( '遅刻常習', $stored['memo'], 'ユーザーメタにメモが保存される' );
	}

	/**
	 * 事業者名が空のとき、既定のブロック時メッセージの問い合わせ先がサイト名になることを確認する。
	 *
	 * @return void
	 */
	public function test_get_default_block_message_falls_back_to_site_name(): void {
		// 事業者名を空にし、サイト名を固定する。
		update_option( 'vkbm_provider_settings', array( 'provider_name' => '' ) );
		update_option( 'blogname', 'テストサイト' );

		$this->assertStringContainsString( 'テストサイト', $this->service->get_default_block_message() );
	}
}
