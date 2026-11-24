<?php
/**
 * 予約時の入力項目（#1147）の共通処理を検証する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Common;

use VKBookingManager\Common\Booking_Custom_Fields;
use WP_UnitTestCase;

/**
 * Booking_Custom_Fields のテスト。
 *
 * @group common
 */
class Booking_Custom_Fields_Test extends WP_UnitTestCase {
	/**
	 * 必須項目の未入力文言が入力形式に合うことを検証する。
	 */
	public function test_required_error_matches_field_type(): void {
		$messages = array(
			'select'   => 'Please select Course.',
			'radio'    => 'Please select Course.',
			'text'     => 'Please enter Course.',
			'textarea' => 'Please enter Course.',
			'number'   => 'Please enter Course.',
		);
		foreach ( $messages as $type => $message ) {
			$definitions = Booking_Custom_Fields::normalize_definitions( array( array( 'id' => 'cf_course', 'label' => 'Course', 'type' => $type, 'required' => true, 'choices' => array( 'A' ) ) ) );
			$this->assertSame( array( 'cf_course' => $message ), Booking_Custom_Fields::validate_values( array(), $definitions, true ), $type );
		}
	}

	/**
	 * 選択式の必須エラーには、予約入力項目専用の日本語訳を使う。
	 */
	public function test_required_error_translation(): void {
		$mo_file = dirname( __DIR__, 3 ) . '/languages/vk-booking-manager-ja.mo';
		if ( ! file_exists( $mo_file ) ) {
			$this->markTestSkipped( '日本語の翻訳ファイルが無いためスキップする。' );
		}

		$domain = 'vk-booking-manager';
		unload_textdomain( $domain );
		load_textdomain( $domain, $mo_file );
		foreach ( array( 'select', 'radio' ) as $type ) {
			$definitions = Booking_Custom_Fields::normalize_definitions( array( array( 'id' => 'cf_course', 'label' => '夕食のコース', 'type' => $type, 'required' => true, 'choices' => array( '和食' ) ) ) );
			$this->assertSame( array( 'cf_course' => '夕食のコースを選択してください。' ), Booking_Custom_Fields::validate_values( array(), $definitions, true ), $type );
		}
		unload_textdomain( $domain );
	}

	/**
	 * 選択肢・数値の設定、回答、履歴の境界を検証する。
	 */
	public function test_choices_and_number_contract(): void {
		$raw = array(
			array( 'id' => 'cf_course', 'label' => 'Course', 'type' => 'select', 'required' => true, 'choices' => "A\n \nB\nA" ),
			array( 'id' => 'cf_breakfast', 'label' => 'Breakfast', 'type' => 'radio', 'choices' => "Yes\nNo" ),
			array( 'id' => 'cf_age', 'label' => 'Age', 'type' => 'number', 'default_value' => '0' ),
		);
		$input = Booking_Custom_Fields::sanitize_definitions_input( $raw );
		$this->assertSame( array(), $input['errors'] );
		$this->assertSame( array( 'A', 'B' ), $input['definitions'][0]['choices'] );
		$this->assertSame( '0', $input['definitions'][2]['default_value'] );
		$this->assertSame( $input['definitions'], Booking_Custom_Fields::normalize_definitions( $input['definitions'] ) );
		$definitions = $input['definitions'];
		foreach ( array( '0', '-2', '1.25', '.5', '1e-3', '1E+3', '' ) as $number ) {
			$values = Booking_Custom_Fields::sanitize_values( array( 'cf_course' => 'B', 'cf_breakfast' => 'No', 'cf_age' => $number ), $definitions );
			$this->assertSame( $number, $values['cf_age'] );
			$this->assertSame( array(), Booking_Custom_Fields::validate_values( $values, $definitions, true ) );
			$snapshot = Booking_Custom_Fields::build_snapshot( $values, $definitions );
			$this->assertSame( array( 'id', 'label', 'type', 'value' ), array_keys( $snapshot[0] ) );
			$this->assertSame( $number, $snapshot[2]['value'] );
		}
		foreach ( array( 'NaN', 'Infinity', 'abc', '1e999', '+1', '1.', '-.5e999', str_repeat( '1', 201 ) ) as $number ) {
			$this->assertArrayHasKey( 'cf_age', Booking_Custom_Fields::validate_values( array( 'cf_course' => 'A', 'cf_age' => $number ), $definitions, true ) );
		}
		$this->assertArrayHasKey( 'cf_course', Booking_Custom_Fields::validate_values( array( 'cf_course' => 'forged' ), $definitions, true ) );
		$this->assertArrayHasKey( 'cf_course', Booking_Custom_Fields::validate_values( Booking_Custom_Fields::sanitize_values( array( 'cf_course' => 'A<script>' ), $definitions ), $definitions, true ) );
		$this->assertArrayHasKey( 'cf_breakfast', Booking_Custom_Fields::validate_values( array( 'cf_course' => 'A', 'cf_breakfast' => 'forged' ), $definitions, true ) );
		$this->assertArrayHasKey( 'cf_course', Booking_Custom_Fields::validate_values( array(), $definitions, true ) );
		$this->assertSame( 'B', Booking_Custom_Fields::normalize_snapshot( array( array( 'id' => 'cf_course', 'label' => 'Course', 'type' => 'select', 'value' => 'B' ) ) )[0]['value'] );
		$empty = Booking_Custom_Fields::sanitize_definitions_input( array( array( 'label' => 'Course', 'type' => 'radio', 'choices' => " \n " ) ) );
		$this->assertArrayHasKey( '0', $empty['errors'] );
		$invalid_default = Booking_Custom_Fields::sanitize_definitions_input( array( array( 'label' => 'Age', 'type' => 'number', 'default_value' => 'NaN' ) ) );
		$this->assertArrayHasKey( '0', $invalid_default['errors'] );
		$this->assertSame( 'choices', $empty['error_fields']['0'] );
		$this->assertSame( 'default_value', $invalid_default['error_fields']['0'] );
		$this->assertSame( 'label', Booking_Custom_Fields::sanitize_definitions_input( array( array( 'label' => '' ) ) )['error_fields']['0'] );
	}

	/**
	 * 下書きの未送信キーと、利用者が空にしたキーを区別する。
	 */
	public function test_draft_values_keep_missing_keys_distinct_from_empty_values(): void {
		$definitions = Booking_Custom_Fields::normalize_definitions(
			array( array( 'id' => 'cf_age', 'label' => 'Age', 'type' => 'number', 'default_value' => '12' ) )
		);
		$this->assertSame( array(), Booking_Custom_Fields::sanitize_values( array(), $definitions, true ) );
		$this->assertSame( array( 'cf_age' => '' ), Booking_Custom_Fields::sanitize_values( array( 'cf_age' => '' ), $definitions, true ) );
		$this->assertSame( array( 'cf_age' => '12' ), Booking_Custom_Fields::sanitize_values( array( 'cf_age' => '12' ), $definitions, true ) );
	}

	/**
	 * 各テストの前に、文言を原文（英語）で比べられるようにする。
	 */
	public function set_up(): void {
		parent::set_up();
		switch_to_locale( 'en_US' );
		unload_textdomain( 'vk-booking-manager', true );
	}

	/**
	 * 各テストの後に、ロケールを戻す。
	 */
	public function tear_down(): void {
		restore_previous_locale();
		parent::tear_down();
	}

	/**
	 * 保存済みの定義を正規化することを検証する。
	 */
	public function test_normalize_definitions(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '正しい 2 行 => 設定した順のまま返す',
				'raw'                 => array(
					array(
						'id'       => 'cf_course',
						'label'    => '夕食のコース',
						'type'     => 'text',
						'required' => true,
					),
					array(
						'id'       => 'cf_allergy',
						'label'    => 'アレルギー',
						'type'     => 'textarea',
						'required' => false,
					),
				),
				'expected'            => array(
					array(
						'id'       => 'cf_course',
						'label'    => '夕食のコース',
						'type'     => 'text',
						'required' => true,
					),
					array(
						'id'       => 'cf_allergy',
						'label'    => 'アレルギー',
						'type'     => 'textarea',
						'required' => false,
					),
				),
			),
			array(
				'test_condition_name' => '未知の種類・必須の文字列 => 1 行のテキスト・true にそろえる',
				'raw'                 => array(
					array(
						'id'       => 'cf_x',
						'label'    => ' <b>項目</b> ',
						'type'     => 'unknown',
						'required' => '1',
					),
				),
				'expected'            => array(
					array(
						'id'       => 'cf_x',
						'label'    => '項目',
						'type'     => 'text',
						'required' => true,
					),
				),
			),
			array(
				'test_condition_name' => '不正な ID・空の項目名・重複した ID の行 => 捨てる',
				'raw'                 => array(
					array(
						'id'    => 'bad id',
						'label' => '不正',
					),
					array(
						'id'    => 'cf_empty',
						'label' => '',
					),
					array(
						'id'    => 'cf_dup',
						'label' => '1 件目',
					),
					array(
						'id'    => 'cf_dup',
						'label' => '2 件目',
					),
				),
				'expected'            => array(
					array(
						'id'       => 'cf_dup',
						'label'    => '1 件目',
						'type'     => 'text',
						'required' => false,
					),
				),
			),
			array(
				'test_condition_name' => '配列でない値 => 空配列',
				'raw'                 => 'broken',
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Booking_Custom_Fields::normalize_definitions( $case['raw'] ), $case['test_condition_name'] );
		}

		// 上限を超えた行は、上限の件数で切る（境界値）。
		$many = array();
		for ( $i = 1; $i <= Booking_Custom_Fields::MAX_FIELDS + 2; $i++ ) {
			$many[] = array(
				'id'    => 'cf_' . $i,
				'label' => '項目' . $i,
			);
		}
		$this->assertCount( Booking_Custom_Fields::MAX_FIELDS, Booking_Custom_Fields::normalize_definitions( $many ), '上限を超えた保存値 => 上限の件数で切る' );
	}

	/**
	 * 設定画面から送られた行の検証を確かめる。
	 */
	public function test_sanitize_definitions_input(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '既存の ID は引き継ぎ、行の並びは送られた順を保つ',
				'raw'                 => array(
					'3' => array(
						'id'       => 'cf_second',
						'label'    => '2 番目に作った項目',
						'type'     => 'textarea',
						'required' => '1',
					),
					'0' => array(
						'id'    => 'cf_first',
						'label' => '最初に作った項目',
						'type'  => 'text',
					),
				),
				'expected_ids'        => array( 'cf_second', 'cf_first' ),
				'expected_labels'     => array( '2 番目に作った項目', '最初に作った項目' ),
				'expected_errors'     => array(),
			),
			array(
				'test_condition_name' => '項目名が空の行 => その行の番号でエラーにし、保存しない',
				'raw'                 => array(
					'0' => array(
						'id'    => 'cf_keep',
						'label' => '残す項目',
					),
					'5' => array(
						'id'    => '',
						'label' => '   ',
					),
				),
				'expected_ids'        => array( 'cf_keep' ),
				'expected_labels'     => array( '残す項目' ),
				'expected_errors'     => array( '5' => 'Please enter the item name.' ),
			),
			array(
				'test_condition_name' => '行が無い（全部削除した）=> 空の定義・エラーなし',
				'raw'                 => array(),
				'expected_ids'        => array(),
				'expected_labels'     => array(),
				'expected_errors'     => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$result = Booking_Custom_Fields::sanitize_definitions_input( $case['raw'] );
			$this->assertSame( $case['expected_ids'], array_column( $result['definitions'], 'id' ), $case['test_condition_name'] );
			$this->assertSame( $case['expected_labels'], array_column( $result['definitions'], 'label' ), $case['test_condition_name'] );
			$this->assertSame( $case['expected_errors'], $result['errors'], $case['test_condition_name'] );
		}

		// 新しい行・重複した ID の行には、新しい ID を振る。
		$result = Booking_Custom_Fields::sanitize_definitions_input(
			array(
				array(
					'id'    => '',
					'label' => '新しい項目',
				),
				array(
					'id'    => 'cf_same',
					'label' => '1 件目',
				),
				array(
					'id'    => 'cf_same',
					'label' => '複製した行',
				),
			)
		);
		$ids    = array_column( $result['definitions'], 'id' );
		$this->assertTrue( Booking_Custom_Fields::is_valid_id( $ids[0] ), '新しい行 => 形式の正しい ID を振る' );
		$this->assertSame( 'cf_same', $ids[1], '最初の行は ID を引き継ぐ' );
		$this->assertNotSame( 'cf_same', $ids[2], '重複した ID の行 => 振り直す' );
		$this->assertCount( 3, array_unique( $ids ), 'ID は重複しない' );

		// 上限ちょうどはエラーにせず、上限を超えたらエラーにして上限の件数で切る（境界値）。
		foreach ( array( Booking_Custom_Fields::MAX_FIELDS, Booking_Custom_Fields::MAX_FIELDS + 1 ) as $count ) {
			$rows = array();
			for ( $i = 0; $i < $count; $i++ ) {
				$rows[] = array(
					'id'    => '',
					'label' => '項目' . $i,
				);
			}
			$result = Booking_Custom_Fields::sanitize_definitions_input( $rows );
			$this->assertCount( Booking_Custom_Fields::MAX_FIELDS, $result['definitions'], $count . ' 件 => 上限の件数まで残す' );
			$this->assertSame( $count > Booking_Custom_Fields::MAX_FIELDS, isset( $result['errors']['_all'] ), $count . ' 件 => 上限を超えたときだけエラー' );
		}
	}

	/**
	 * 回答のサニタイズを検証する。
	 */
	public function test_sanitize_values(): void {
		$definitions = $this->get_definitions();
		$test_cases  = array(
			array(
				'test_condition_name' => '定義にある項目だけを定義の順に取り出し、定義に無い ID の値は捨てる',
				'raw'                 => array(
					'cf_removed' => '消えた項目',
					'cf_allergy' => "えび\nかに",
					'cf_course'  => ' 和食 ',
				),
				'expected'            => array(
					'cf_course'  => '和食',
					'cf_allergy' => "えび\nかに",
				),
			),
			array(
				'test_condition_name' => '1 行の項目の改行・タグ => 取り除く。配列の値 => 空文字',
				'raw'                 => array(
					'cf_course'  => "<b>和</b>\n食",
					'cf_allergy' => array( 'broken' ),
				),
				'expected'            => array(
					'cf_course'  => '和 食',
					'cf_allergy' => '',
				),
			),
			array(
				'test_condition_name' => '配列でない回答 => すべて空文字',
				'raw'                 => 'broken',
				'expected'            => array(
					'cf_course'  => '',
					'cf_allergy' => '',
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Booking_Custom_Fields::sanitize_values( $case['raw'], $definitions ), $case['test_condition_name'] );
		}
	}

	/**
	 * 回答の検証を確かめる。
	 */
	public function test_validate_values(): void {
		$definitions = $this->get_definitions();
		$test_cases  = array(
			array(
				'test_condition_name' => '必須に回答あり・任意が空 => エラーなし',
				'values'              => array(
					'cf_course'  => '和食',
					'cf_allergy' => '',
				),
				'enforce_required'    => true,
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '必須が空 => 「<項目名>を入力してください。」',
				'values'              => array(
					'cf_course'  => '',
					'cf_allergy' => '',
				),
				'enforce_required'    => true,
				'expected'            => array( 'cf_course' => 'Please enter 夕食のコース.' ),
			),
			array(
				'test_condition_name' => '代理予約（必須を確かめない）で必須が空 => エラーなし',
				'values'              => array(),
				'enforce_required'    => false,
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '1 行の項目がちょうど 200 文字 => エラーなし',
				'values'              => array( 'cf_course' => str_repeat( 'あ', 200 ) ),
				'enforce_required'    => true,
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '1 行の項目が 201 文字 => 「<項目名>は200文字以内で入力してください。」',
				'values'              => array( 'cf_course' => str_repeat( 'あ', 201 ) ),
				'enforce_required'    => false,
				'expected'            => array( 'cf_course' => '夕食のコース must be 200 characters or fewer.' ),
			),
			array(
				'test_condition_name' => '複数行の項目が備考の上限を超える => 備考と同じ上限でエラー',
				'values'              => array(
					'cf_course'  => '和食',
					'cf_allergy' => str_repeat( 'い', 1001 ),
				),
				'enforce_required'    => true,
				'expected'            => array( 'cf_allergy' => 'アレルギー must be 1000 characters or fewer.' ),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Booking_Custom_Fields::validate_values( $case['values'], $definitions, $case['enforce_required'] ), $case['test_condition_name'] );
		}

		// エラーは REST の 400 として、項目ごとのエラーを data.fields に入れて返す。
		$error = Booking_Custom_Fields::create_error( array( 'cf_course' => 'Please enter 夕食のコース.' ) );
		$this->assertSame( 'vkbm_custom_field_invalid', $error->get_error_code() );
		$this->assertSame( 'Please enter 夕食のコース.', $error->get_error_message() );
		$this->assertSame( 400, $error->get_error_data()['status'] );
		$this->assertNull( Booking_Custom_Fields::create_error( array() ), 'エラーが無い => null' );
	}

	/**
	 * 保存する記録（回答した時点の項目名・種類と値）を作ることを検証する。
	 */
	public function test_build_snapshot(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '定義の順に、未回答も空文字で記録する',
				'values'              => array( 'cf_allergy' => 'えび' ),
				'definitions'         => $this->get_definitions(),
				'expected'            => array(
					array(
						'id'    => 'cf_course',
						'label' => '夕食のコース',
						'type'  => 'text',
						'value' => '',
					),
					array(
						'id'    => 'cf_allergy',
						'label' => 'アレルギー',
						'type'  => 'textarea',
						'value' => 'えび',
					),
				),
			),
			array(
				'test_condition_name' => '項目が 1 つ => 1 件の記録',
				'values'              => array( 'cf_course' => '和食' ),
				'definitions'         => array_slice( $this->get_definitions(), 0, 1 ),
				'expected'            => array(
					array(
						'id'    => 'cf_course',
						'label' => '夕食のコース',
						'type'  => 'text',
						'value' => '和食',
					),
				),
			),
			array(
				'test_condition_name' => '項目が無い => 空の記録',
				'values'              => array( 'cf_course' => '和食' ),
				'definitions'         => array(),
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Booking_Custom_Fields::build_snapshot( $case['values'], $case['definitions'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * 予約の編集画面から送られた回答で記録を作り直すことを検証する。
	 */
	public function test_apply_admin_input(): void {
		$snapshot = array(
			array(
				'id'    => 'cf_old',
				'label' => '設定から消した項目',
				'type'  => 'text',
				'value' => '当時の回答',
			),
			array(
				'id'    => 'cf_course',
				'label' => '当時の項目名',
				'type'  => 'text',
				'value' => '和食',
			),
		);

		$test_cases = array(
			array(
				'test_condition_name' => '記録の項目は当時の項目名のまま値だけ差し替え、記録に無い今の項目を後ろに足す',
				'snapshot'            => $snapshot,
				'raw'                 => array(
					'cf_course'  => '洋食',
					'cf_allergy' => "えび\nかに",
				),
				'expected'            => array(
					array(
						'id'    => 'cf_old',
						'label' => '設定から消した項目',
						'type'  => 'text',
						'value' => '当時の回答',
					),
					array(
						'id'    => 'cf_course',
						'label' => '当時の項目名',
						'type'  => 'text',
						'value' => '洋食',
					),
					array(
						'id'    => 'cf_allergy',
						'label' => 'アレルギー',
						'type'  => 'textarea',
						'value' => "えび\nかに",
					),
				),
			),
			array(
				'test_condition_name' => '記録が無い予約 => 今の設定の項目で記録を作る',
				'snapshot'            => array(),
				'raw'                 => array( 'cf_course' => '和食' ),
				'expected'            => array(
					array(
						'id'    => 'cf_course',
						'label' => '夕食のコース',
						'type'  => 'text',
						'value' => '和食',
					),
					array(
						'id'    => 'cf_allergy',
						'label' => 'アレルギー',
						'type'  => 'textarea',
						'value' => '',
					),
				),
			),
			array(
				'test_condition_name' => '1 行の項目に上限を超える回答 => 上限で切り詰める',
				'snapshot'            => array(),
				'raw'                 => array( 'cf_course' => str_repeat( 'あ', 205 ) ),
				'expected'            => array(
					array(
						'id'    => 'cf_course',
						'label' => '夕食のコース',
						'type'  => 'text',
						'value' => str_repeat( 'あ', 200 ),
					),
					array(
						'id'    => 'cf_allergy',
						'label' => 'アレルギー',
						'type'  => 'textarea',
						'value' => '',
					),
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Booking_Custom_Fields::apply_admin_input( $case['snapshot'], $this->get_definitions(), $case['raw'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * 通知メールの本文に入れる行を検証する。
	 */
	public function test_build_email_lines(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '1 行の回答 => 「項目名: 値」',
				'snapshot'            => array(
					array(
						'id'    => 'cf_course',
						'label' => '夕食のコース',
						'type'  => 'text',
						'value' => '和食',
					),
				),
				'expected'            => array( '夕食のコース: 和食' ),
			),
			array(
				'test_condition_name' => '複数行の回答 => 項目名の次の行から書き出す',
				'snapshot'            => array(
					array(
						'id'    => 'cf_allergy',
						'label' => 'アレルギー',
						'type'  => 'textarea',
						'value' => "えび\nかに",
					),
				),
				'expected'            => array( 'アレルギー:', "えび\nかに" ),
			),
			array(
				'test_condition_name' => '未回答 => （なし）',
				'snapshot'            => array(
					array(
						'id'    => 'cf_allergy',
						'label' => 'アレルギー',
						'type'  => 'textarea',
						'value' => '',
					),
				),
				'expected'            => array( 'アレルギー: (none)' ),
			),
			array(
				'test_condition_name' => '記録が無い => 行なし',
				'snapshot'            => array(),
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Booking_Custom_Fields::build_email_lines( $case['snapshot'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * 予約に保存した記録を読み出すことを検証する。
	 */
	public function test_get_booking_snapshot(): void {
		$booking_id = (int) self::factory()->post->create();
		$test_cases = array(
			array(
				'test_condition_name' => '保存した記録（バックスラッシュを含む） => そのまま読み出す',
				'meta'                => array(
					array(
						'id'    => 'cf_course',
						'label' => '夕食のコース',
						'type'  => 'text',
						'value' => 'C:\\path',
					),
				),
				'expected'            => array(
					array(
						'id'    => 'cf_course',
						'label' => '夕食のコース',
						'type'  => 'text',
						'value' => 'C:\\path',
					),
				),
			),
			array(
				'test_condition_name' => '匿名化した予約（空文字） => 空の記録',
				'meta'                => '',
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '形の崩れた行 => 捨てる',
				'meta'                => array(
					array(
						'id'    => 'bad',
						'label' => '不正',
					),
					'broken',
				),
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			update_post_meta( $booking_id, Booking_Custom_Fields::META_KEY, wp_slash( $case['meta'] ) );
			$this->assertSame( $case['expected'], Booking_Custom_Fields::get_booking_snapshot( $booking_id ), $case['test_condition_name'] );
		}
	}

	/**
	 * テストで使う項目の定義を返す。
	 *
	 * @return array<int, array{id: string, label: string, type: string, required: bool}> 項目の定義。
	 */
	private function get_definitions(): array {
		return array(
			array(
				'id'       => 'cf_course',
				'label'    => '夕食のコース',
				'type'     => 'text',
				'required' => true,
			),
			array(
				'id'       => 'cf_allergy',
				'label'    => 'アレルギー',
				'type'     => 'textarea',
				'required' => false,
			),
		);
	}
}
