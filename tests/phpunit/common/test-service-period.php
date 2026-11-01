<?php
/**
 * 提供期間ユーティリティ Service_Period のテスト（#972）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Common;

use VKBookingManager\Common\Service_Period;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use WP_Query;
use WP_UnitTestCase;

/**
 * Service_Period の静的メソッドを検証するテスト。
 *
 * @group common
 * @group service-period
 */
class Service_Period_Test extends WP_UnitTestCase {

	/**
	 * テスト前の日付書式。
	 *
	 * @var mixed
	 */
	private $original_date_format;

	/**
	 * 日付書式を固定する。
	 */
	public function set_up(): void {
		parent::set_up();
		$this->original_date_format = get_option( 'date_format' );
		update_option( 'date_format', 'Y年n月j日' );
	}

	/**
	 * 日付書式を元に戻す。
	 */
	public function tear_down(): void {
		update_option( 'date_format', $this->original_date_format );
		parent::tear_down();
	}

	/**
	 * sanitize_date: 実在する Y-m-d だけを通し、それ以外は空文字（無期限）にする。
	 */
	public function test_sanitize_date(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '実在する日付 => そのまま',
				'input'               => '2026-07-20',
				'expected'            => '2026-07-20',
			),
			array(
				'test_condition_name' => 'うるう日 => そのまま',
				'input'               => '2028-02-29',
				'expected'            => '2028-02-29',
			),
			array(
				'test_condition_name' => '前後に空白がある日付 => 空白を除いて通す',
				'input'               => ' 2026-07-20 ',
				'expected'            => '2026-07-20',
			),
			array(
				'test_condition_name' => '空文字 => 空文字（無期限）',
				'input'               => '',
				'expected'            => '',
			),
			array(
				'test_condition_name' => '存在しない日付 => 空文字',
				'input'               => '2026-02-30',
				'expected'            => '',
			),
			array(
				'test_condition_name' => 'うるう年でない年の 2/29 => 空文字',
				'input'               => '2027-02-29',
				'expected'            => '',
			),
			array(
				'test_condition_name' => 'スラッシュ区切り => 空文字',
				'input'               => '2026/07/20',
				'expected'            => '',
			),
			array(
				'test_condition_name' => '文字列でない値 => 空文字',
				'input'               => array( '2026-07-20' ),
				'expected'            => '',
			),
			array(
				'test_condition_name' => 'null => 空文字',
				'input'               => null,
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Service_Period::sanitize_date( $case['input'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * is_date_within: 両端を含み、空欄の側は無期限として扱う。
	 */
	public function test_is_date_within(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '開始日の当日 => 期間内（境界）',
				'date'                => '2026-07-20',
				'start'               => '2026-07-20',
				'end'                 => '2026-08-31',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '開始日の前日 => 期間外',
				'date'                => '2026-07-19',
				'start'               => '2026-07-20',
				'end'                 => '2026-08-31',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '終了日の当日 => 期間内（境界）',
				'date'                => '2026-08-31',
				'start'               => '2026-07-20',
				'end'                 => '2026-08-31',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '終了日の翌日 => 期間外',
				'date'                => '2026-09-01',
				'start'               => '2026-07-20',
				'end'                 => '2026-08-31',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '開始日だけ指定し、それ以降の日 => 期間内（終了は無期限）',
				'date'                => '2030-01-01',
				'start'               => '2026-07-20',
				'end'                 => '',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '開始日だけ指定し、開始日の前日 => 期間外',
				'date'                => '2026-07-19',
				'start'               => '2026-07-20',
				'end'                 => '',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '終了日だけ指定し、それ以前の日 => 期間内（開始は無期限）',
				'date'                => '2000-01-01',
				'start'               => '',
				'end'                 => '2026-08-31',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '終了日だけ指定し、終了日の翌日 => 期間外',
				'date'                => '2026-09-01',
				'start'               => '',
				'end'                 => '2026-08-31',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '両方空欄 => 常に期間内（制限なし）',
				'date'                => '2026-09-01',
				'start'               => '',
				'end'                 => '',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '開始日と終了日が同じ日の当日 => 期間内（1日だけ）',
				'date'                => '2026-08-01',
				'start'               => '2026-08-01',
				'end'                 => '2026-08-01',
				'expected'            => true,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Service_Period::is_date_within( $case['date'], $case['start'], $case['end'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * is_reversed: 両方あって開始日が終了日より後のときだけ逆転とする。
	 */
	public function test_is_reversed(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '開始日が終了日より後 => 逆転',
				'start'               => '2026-09-01',
				'end'                 => '2026-08-31',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '開始日と終了日が同じ => 逆転ではない',
				'start'               => '2026-08-31',
				'end'                 => '2026-08-31',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '開始日が終了日より前 => 逆転ではない',
				'start'               => '2026-07-20',
				'end'                 => '2026-08-31',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '開始日だけ => 逆転ではない',
				'start'               => '2026-09-01',
				'end'                 => '',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '終了日だけ => 逆転ではない',
				'start'               => '',
				'end'                 => '2026-08-31',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '両方空欄 => 逆転ではない',
				'start'               => '',
				'end'                 => '',
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Service_Period::is_reversed( $case['start'], $case['end'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * is_ended: 終了日が今日より前のときだけ終了済みとする（終了日の当日はまだ終了していない）。
	 */
	public function test_is_ended(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '終了日が昨日 => 終了済み',
				'end'                 => '2026-08-30',
				'today'               => '2026-08-31',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '終了日が今日 => 終了していない（境界）',
				'end'                 => '2026-08-31',
				'today'               => '2026-08-31',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '終了日が明日 => 終了していない',
				'end'                 => '2026-09-01',
				'today'               => '2026-08-31',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '終了日が空欄 => 終了していない（無期限）',
				'end'                 => '',
				'today'               => '2026-08-31',
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Service_Period::is_ended( $case['end'], $case['today'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * get_period: 保存値を読み、日付として読めない値は無期限にする。
	 */
	public function test_get_period(): void {
		$menu_id = (int) $this->factory()->post->create( array( 'post_type' => Service_Menu_Post_Type::POST_TYPE ) );
		$this->assertSame(
			array(
				'start' => '',
				'end'   => '',
			),
			Service_Period::get_period( $menu_id ),
			'メタなし => 両方無期限'
		);

		update_post_meta( $menu_id, Service_Period::META_START, '2026-07-20' );
		update_post_meta( $menu_id, Service_Period::META_END, 'broken' );
		$this->assertSame(
			array(
				'start' => '2026-07-20',
				'end'   => '',
			),
			Service_Period::get_period( $menu_id ),
			'終了日が日付として読めない => その側だけ無期限'
		);
	}

	/**
	 * format_label: 両方・開始日のみ・終了日のみ・空欄の文言をサイトの日付書式で作る。
	 */
	public function test_format_label(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '両方 => 開始日と終了日を並べる',
				'start'               => '2026-07-20',
				'end'                 => '2026-08-31',
				/* translators: 1: service period start date, 2: service period end date. */
				'expected'            => sprintf( _x( '%1$s - %2$s', 'service period range', 'vk-booking-manager' ), '2026年7月20日', '2026年8月31日' ),
			),
			array(
				'test_condition_name' => '開始日のみ => 「から」の文言',
				'start'               => '2026-07-20',
				'end'                 => '',
				/* translators: %s: service period start date. */
				'expected'            => sprintf( __( 'From %s', 'vk-booking-manager' ), '2026年7月20日' ),
			),
			array(
				'test_condition_name' => '終了日のみ => 「まで」の文言',
				'start'               => '',
				'end'                 => '2026-08-31',
				/* translators: %s: service period end date. */
				'expected'            => sprintf( __( 'Until %s', 'vk-booking-manager' ), '2026年8月31日' ),
			),
			array(
				'test_condition_name' => '両方空欄 => 空文字',
				'start'               => '',
				'end'                 => '',
				'expected'            => '',
			),
			array(
				'test_condition_name' => '日付として読めない値は空欄と同じ扱い => 空文字',
				'start'               => 'abc',
				'end'                 => '',
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Service_Period::format_label( $case['start'], $case['end'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * format_label_html: 文言は format_label() と同じで、各日付が折り返さない塊の要素で包まれる。
	 */
	public function test_format_label_html(): void {
		$wrap       = static function ( string $date ): string {
			return '<span class="vkbm-service-period__date">' . $date . '</span>';
		};
		$test_cases = array(
			array(
				'test_condition_name' => '両方 => 日付ごとに要素で包む',
				'start'               => '2026-07-20',
				'end'                 => '2026-08-31',
				/* translators: 1: service period start date, 2: service period end date. */
				'expected'            => sprintf( _x( '%1$s - %2$s', 'service period range', 'vk-booking-manager' ), $wrap( '2026年7月20日' ), $wrap( '2026年8月31日' ) ),
			),
			array(
				'test_condition_name' => '開始日のみ',
				'start'               => '2026-07-20',
				'end'                 => '',
				/* translators: %s: service period start date. */
				'expected'            => $this->expected_one_sided( __( 'From %s', 'vk-booking-manager' ), $wrap( '2026年7月20日' ) ),
			),
			array(
				'test_condition_name' => '終了日のみ',
				'start'               => '',
				'end'                 => '2026-08-31',
				/* translators: %s: service period end date. */
				'expected'            => $this->expected_one_sided( __( 'Until %s', 'vk-booking-manager' ), $wrap( '2026年8月31日' ) ),
			),
			array(
				'test_condition_name' => '両方空欄 => 空文字',
				'start'               => '',
				'end'                 => '',
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			$html = Service_Period::format_label_html( $case['start'], $case['end'] );
			$this->assertSame( $case['expected'], $html, $case['test_condition_name'] );
			$this->assertSame(
				Service_Period::format_label( $case['start'], $case['end'] ),
				wp_strip_all_tags( $html ),
				$case['test_condition_name'] . ' / タグを除くと format_label() と同じ'
			);
		}
	}

	/**
	 * 期待値用: 翻訳文言（%s を含む）のうち、日付以外の文字の部分を .vkbm-service-period__text の要素で包む。
	 *
	 * @param string $template 翻訳された文言（%s が日付の位置）。
	 * @param string $date_html 日付の要素の HTML。
	 * @return string 期待する HTML。
	 */
	private function expected_one_sided( string $template, string $date_html ): string {
		$wrap_text = static function ( string $text ): string {
			return (string) preg_replace( '/^(\s*)(.+?)(\s*)$/us', '$1<span class="vkbm-service-period__text">$2</span>$3', $text );
		};
		$parts     = explode( '%s', $template, 2 );
		return ( '' === $parts[0] ? '' : $wrap_text( $parts[0] ) ) . $date_html . ( '' === $parts[1] ? '' : $wrap_text( $parts[1] ) );
	}

	/**
	 * build_not_ended_meta_query: 終了済みのメニューだけを除く。
	 */
	public function test_build_not_ended_meta_query(): void {
		$today = '2026-08-31';

		$no_period  = (int) $this->factory()->post->create( array( 'post_type' => Service_Menu_Post_Type::POST_TYPE ) );
		$empty_end  = (int) $this->factory()->post->create( array( 'post_type' => Service_Menu_Post_Type::POST_TYPE ) );
		$ends_today = (int) $this->factory()->post->create( array( 'post_type' => Service_Menu_Post_Type::POST_TYPE ) );
		$ended      = (int) $this->factory()->post->create( array( 'post_type' => Service_Menu_Post_Type::POST_TYPE ) );
		$upcoming   = (int) $this->factory()->post->create( array( 'post_type' => Service_Menu_Post_Type::POST_TYPE ) );
		update_post_meta( $empty_end, Service_Period::META_END, '' );
		update_post_meta( $ends_today, Service_Period::META_END, '2026-08-31' );
		update_post_meta( $ended, Service_Period::META_END, '2026-08-30' );
		update_post_meta( $upcoming, Service_Period::META_START, '2026-09-10' );
		update_post_meta( $upcoming, Service_Period::META_END, '2026-10-10' );

		$query = new WP_Query(
			array(
				'post_type'      => Service_Menu_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array( Service_Period::build_not_ended_meta_query( $today ) ),
			)
		);

		$ids = array_map( 'intval', $query->posts );
		$this->assertContains( $no_period, $ids, '期間なし => 残る' );
		$this->assertContains( $empty_end, $ids, '終了日が空 => 残る' );
		$this->assertContains( $ends_today, $ids, '終了日が今日 => 残る（境界）' );
		$this->assertContains( $upcoming, $ids, '開始前 => 残る' );
		$this->assertNotContains( $ended, $ids, '終了日が昨日 => 除かれる' );
	}

	/**
	 * リビジョン復元: 逆転した提供期間を含むリビジョン（公開済みメニューの自動保存で生じうる）を復元しても、
	 * 逆転した期間は戻さず復元前の値を保つ。逆転していない期間は復元される。
	 */
	public function test_restore_revision_keeps_previous_period_when_reversed(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '逆転した期間のリビジョンを復元 => 復元前の値のまま',
				'revision_period'     => array(
					'start' => '2027-09-01',
					'end'   => '2027-08-31',
				),
				'expected'            => array(
					'start' => '2027-07-01',
					'end'   => '2027-07-31',
				),
			),
			array(
				'test_condition_name' => '正しい期間のリビジョンを復元 => 復元される',
				'revision_period'     => array(
					'start' => '2027-05-01',
					'end'   => '2027-05-31',
				),
				'expected'            => array(
					'start' => '2027-05-01',
					'end'   => '2027-05-31',
				),
			),
			array(
				'test_condition_name' => '期間なしのリビジョンを復元 => 無期限になる',
				'revision_period'     => array(
					'start' => '',
					'end'   => '',
				),
				'expected'            => array(
					'start' => '',
					'end'   => '',
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$menu_id = (int) $this->factory()->post->create(
				array(
					'post_type'   => Service_Menu_Post_Type::POST_TYPE,
					'post_status' => 'publish',
				)
			);
			update_post_meta( $menu_id, Service_Period::META_START, '2027-07-01' );
			update_post_meta( $menu_id, Service_Period::META_END, '2027-07-31' );

			$revision_id = (int) wp_insert_post(
				array(
					'post_type'   => 'revision',
					'post_status' => 'inherit',
					'post_parent' => $menu_id,
					'post_name'   => $menu_id . '-revision-v1',
					'post_title'  => 'revision',
				)
			);
			// 自動保存は REST の保存後の検証を通らないため、逆転した値をそのままリビジョンへ置く。
			if ( '' !== $case['revision_period']['start'] ) {
				update_metadata( 'post', $revision_id, Service_Period::META_START, $case['revision_period']['start'] );
			}
			if ( '' !== $case['revision_period']['end'] ) {
				update_metadata( 'post', $revision_id, Service_Period::META_END, $case['revision_period']['end'] );
			}

			wp_restore_post_revision( $revision_id );

			$this->assertSame( $case['expected'], Service_Period::get_period( $menu_id ), $case['test_condition_name'] );
		}
	}

	/**
	 * 提供期間のメタが REST に公開され、リビジョン対象になっている。
	 */
	public function test_meta_is_registered_for_rest_and_revisions(): void {
		( new Service_Menu_Post_Type() )->register_meta();
		$registered = get_registered_meta_keys( 'post', Service_Menu_Post_Type::POST_TYPE );
		foreach ( array( Service_Period::META_START, Service_Period::META_END ) as $meta_key ) {
			$this->assertArrayHasKey( $meta_key, $registered, $meta_key . ' は登録されている' );
			$this->assertNotEmpty( $registered[ $meta_key ]['show_in_rest'], $meta_key . ' は REST に公開される' );
			$this->assertContains( $meta_key, ( new Service_Menu_Post_Type() )->get_revisioned_meta_keys(), $meta_key . ' はリビジョン対象' );
		}
	}

	/**
	 * REST 経由の保存: 開始日が終了日より後の提供期間は保存されず、前回の値（なければ無期限）に戻る。
	 */
	public function test_rest_save_rejects_reversed_service_period(): void {
		( new Service_Menu_Post_Type() )->register_meta();
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );

		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		$test_cases = array(
			array(
				'test_condition_name' => '正しい期間を REST で保存 => 保存される',
				'meta'                => array(
					Service_Period::META_START => '2027-07-20',
					Service_Period::META_END   => '2027-08-31',
				),
				'expected'            => array(
					'start' => '2027-07-20',
					'end'   => '2027-08-31',
				),
			),
			array(
				'test_condition_name' => '開始日が終了日より後の期間を REST で保存 => 前回の値のまま',
				'meta'                => array(
					Service_Period::META_START => '2027-09-01',
					Service_Period::META_END   => '2027-08-31',
				),
				'expected'            => array(
					'start' => '2027-07-20',
					'end'   => '2027-08-31',
				),
			),
			array(
				'test_condition_name' => '終了日だけを前回の開始日より前にして REST で保存 => 前回の値のまま',
				'meta'                => array( Service_Period::META_END => '2027-07-01' ),
				'expected'            => array(
					'start' => '2027-07-20',
					'end'   => '2027-08-31',
				),
			),
			array(
				'test_condition_name' => '両方を空にして REST で保存 => 無期限になる',
				'meta'                => array(
					Service_Period::META_START => '',
					Service_Period::META_END   => '',
				),
				'expected'            => array(
					'start' => '',
					'end'   => '',
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$request = new \WP_REST_Request( 'POST', '/wp/v2/vkbm_service_menu/' . $menu_id );
			$request->set_body_params( array( 'meta' => $case['meta'] ) );
			$response = rest_do_request( $request );
			$this->assertSame( 200, $response->get_status(), $case['test_condition_name'] );
			$this->assertSame( $case['expected'], Service_Period::get_period( $menu_id ), $case['test_condition_name'] );
		}

		// 新規作成で逆転した期間を送ったときは、無期限になる。
		$request = new \WP_REST_Request( 'POST', '/wp/v2/vkbm_service_menu' );
		$request->set_body_params(
			array(
				'title'  => 'REST 新規',
				'status' => 'publish',
				'meta'   => array(
					Service_Period::META_START => '2027-09-01',
					Service_Period::META_END   => '2027-08-31',
				),
			)
		);
		$response = rest_do_request( $request );
		$this->assertSame( 201, $response->get_status(), '新規作成' );
		$this->assertSame(
			array(
				'start' => '',
				'end'   => '',
			),
			Service_Period::get_period( (int) $response->get_data()['id'] ),
			'新規作成で逆転した期間 => 無期限'
		);
	}
}
