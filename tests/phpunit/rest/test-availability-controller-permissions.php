<?php
/**
 * Availability_Controller の権限まわりのテスト（#411）。
 *
 * `/calendar-meta` `/availabilities` は permission_callback => '__return_true' の公開APIのため、
 * サーバー側で必ず current_user_can( vkbm_manage_system_settings ) を再評価し、
 * 非該当者へは診断理由フィールドごとレスポンスから省略すること、
 * および内部設定を示す詳細なエラーメッセージを一般訪問者へ漏らさないことを検証する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\REST;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\PostTypes\Shift_Post_Type;
use VKBookingManager\REST\Availability_Controller;
use WP_REST_Request;
use WP_UnitTestCase;
use function current_datetime;
use function update_post_meta;
use function wp_set_current_user;

/**
 * Availability_Controller の権限まわりのテストクラス。
 *
 * @group rest
 * @group unavailability-reason
 */
class Availability_Controller_Permissions_Test extends WP_UnitTestCase {

	/**
	 * 元のサイトタイムゾーン文字列（テスト後に復元する）。
	 *
	 * @var string
	 */
	private $original_timezone_string = '';

	/**
	 * 予約締切・上限日数の相対計算をブレさせないため、サイトTZをAsia/Tokyoに固定する。
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->original_timezone_string = (string) get_option( 'timezone_string', '' );
		update_option( 'timezone_string', 'Asia/Tokyo' );
	}

	/**
	 * サイトタイムゾーンと現在ユーザーを元に戻す。
	 */
	protected function tearDown(): void {
		update_option( 'timezone_string', $this->original_timezone_string );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * 担当スタッフ未設定（staff_not_configured）のメニューに対する /calendar-meta 応答を、
	 * 匿名訪問者・購読者（権限なし）・管理者（権限あり）でそれぞれ検証する。
	 *
	 * - 権限なし: 内部設定の詳細を含まない汎用文言に差し替えられる。
	 * - 権限あり: 従来どおり詳細なエラーメッセージが返る。
	 */
	public function test_handle_calendar_meta_masks_error_for_users_without_capability(): void {
		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		// 担当スタッフを設定しない => resolve_staff_ids() が staff_not_configured を返す。

		$controller = new Availability_Controller( new Availability_Service() );

		$test_cases = array(
			array(
				'test_condition_name' => '匿名訪問者 => 汎用文言に差し替えられる（内部設定の詳細を含まない）',
				'user_id'             => 0,
				'expect_generic'      => true,
			),
			array(
				'test_condition_name' => '購読者（権限なし） => 汎用文言に差し替えられる',
				'user_id'             => 'subscriber',
				'expect_generic'      => true,
			),
			array(
				'test_condition_name' => '管理者（権限あり） => 従来どおり詳細なエラーメッセージが返る',
				'user_id'             => 'administrator',
				'expect_generic'      => false,
			),
		);

		foreach ( $test_cases as $case ) {
			if ( 'subscriber' === $case['user_id'] || 'administrator' === $case['user_id'] ) {
				$user_id = $this->factory()->user->create( array( 'role' => $case['user_id'] ) );
				wp_set_current_user( $user_id );
			} else {
				wp_set_current_user( 0 );
			}

			$request = new WP_REST_Request( 'GET', '/vkbm/v1/calendar-meta' );
			$request->set_param( 'menu_id', $menu_id );
			$request->set_param( 'year', 2030 );
			$request->set_param( 'month', 1 );
			$request->set_param( 'timezone', 'Asia/Tokyo' );

			$response = $controller->handle_calendar_meta( $request );

			$this->assertInstanceOf( \WP_Error::class, $response, $case['test_condition_name'] );
			$message = $response->get_error_message();
			$code    = $response->get_error_code();

			if ( $case['expect_generic'] ) {
				$this->assertStringNotContainsString( 'staff', strtolower( $message ), $case['test_condition_name'] . ' / 内部設定を示す単語を含んではならない' );
				// #411 安藤さんレビュー指摘: メッセージだけでなく識別子（error code）・data も
				// 固定の汎用値へ置換されていること（元の staff_not_configured が漏れていないこと）を検証する。
				$this->assertNotSame( 'staff_not_configured', $code, $case['test_condition_name'] . ' / 元のエラーコードが漏れてはならない' );
				$this->assertSame( 'reservation_unavailable', $code, $case['test_condition_name'] );
				$this->assertSame(
					'We are not currently accepting reservations for this content. Please choose a different menu, or contact the site administrator for assistance.',
					$message,
					$case['test_condition_name']
				);
				$error_data = $response->get_error_data();
				$this->assertSame( array( 'status' => 400 ), $error_data, $case['test_condition_name'] . ' / data も固定値であるべき' );
			} else {
				$this->assertStringContainsString( 'staff', strtolower( $message ), $case['test_condition_name'] . ' / 管理者には詳細なメッセージが返るべき' );
				$this->assertSame( 'staff_not_configured', $code, $case['test_condition_name'] . ' / 管理者には元のエラーコードが返るべき' );
			}
		}
	}

	/**
	 * #411 麗美さん確認（PR #414 差し戻し）: 担当スタッフ0件（staff_not_configured）のメニューでは
	 * get_calendar_meta() が resolve_staff_ids() の時点で WP_Error を返して早期returnするため、
	 * 成功時パスにしか無かった診断処理（get_unavailability_reason）に到達できず、
	 * 管理者であっても unavailability_reason が一切付与されない配線漏れがあった
	 * （＝完了条件1の診断バナーがまったく出ない不具合）。
	 *
	 * この配線漏れを検出できるのは、get_unavailability_reason() を直接呼ぶテストではなく、
	 * handle_calendar_meta() を通したテストだけ（ロジック自体は正しく、呼び出し経路だけが
	 * 繋がっていなかったため）。エンドポイントを通して検証する。
	 */
	public function test_handle_calendar_meta_attaches_unavailability_reason_to_wp_error_for_admin(): void {
		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		// 担当スタッフを設定しない => resolve_staff_ids() が staff_not_configured を返し、
		// get_calendar_meta() はここで早期return する（診断処理の成功時パスには到達しない）。

		$controller = new Availability_Controller( new Availability_Service() );

		$test_cases = array(
			array(
				'test_condition_name'   => '匿名訪問者 => unavailability_reason はエラーの data にも含まれない（固定応答を崩さない）',
				'user_id'               => 0,
				'expect_reason_present' => false,
			),
			array(
				'test_condition_name'   => '購読者（権限なし） => unavailability_reason はエラーの data にも含まれない',
				'user_id'               => 'subscriber',
				'expect_reason_present' => false,
			),
			array(
				'test_condition_name'   => '管理者（権限あり） => WP_Error の data に unavailability_reason（staff_not_configured）が付与される',
				'user_id'               => 'administrator',
				'expect_reason_present' => true,
			),
		);

		foreach ( $test_cases as $case ) {
			if ( 'subscriber' === $case['user_id'] || 'administrator' === $case['user_id'] ) {
				$user_id = $this->factory()->user->create( array( 'role' => $case['user_id'] ) );
				wp_set_current_user( $user_id );
			} else {
				wp_set_current_user( 0 );
			}

			$request = new WP_REST_Request( 'GET', '/vkbm/v1/calendar-meta' );
			$request->set_param( 'menu_id', $menu_id );
			$request->set_param( 'year', 2030 );
			$request->set_param( 'month', 1 );
			$request->set_param( 'timezone', 'Asia/Tokyo' );

			$response = $controller->handle_calendar_meta( $request );

			$this->assertInstanceOf( \WP_Error::class, $response, $case['test_condition_name'] );
			$error_data = $response->get_error_data();

			if ( $case['expect_reason_present'] ) {
				$this->assertIsArray( $error_data, $case['test_condition_name'] );
				$this->assertArrayHasKey( 'unavailability_reason', $error_data, $case['test_condition_name'] );
				$this->assertSame( 'staff_not_configured', $error_data['unavailability_reason']['code'] ?? null, $case['test_condition_name'] );
				$this->assertStringContainsString( 'staff', strtolower( $error_data['unavailability_reason']['message'] ?? '' ), $case['test_condition_name'] );
			} else {
				// 安藤さんレビュー指摘済みの既存仕様: 権限の無いユーザーへのエラーは
				// 識別子・文言・data のすべてが固定値（array('status' => 400)）で、
				// エラー条件に依らず常に同一であること（内部状態を推測できないこと）を崩さない。
				$this->assertSame( array( 'status' => 400 ), $error_data, $case['test_condition_name'] . ' / data も固定値のままであるべき（unavailability_reason が漏れてはならない）' );
			}
		}
	}

	/**
	 * #411 麗美さん確認（PR #414 差し戻し）: 配線漏れは staff_not_configured（P1）に限らず、
	 * validate_menu() が早期returnする経路（例: メニューがアーカイブ済み＝P3）でも同様に
	 * 起こりうる。エラーコードで分岐せず get_unavailability_reason() を呼ぶ実装になっているかを
	 * 別の理由コードで確認する（P1 だけを直して他の経路を見落とす再発を防ぐ）。
	 */
	public function test_handle_calendar_meta_attaches_unavailability_reason_for_menu_not_bookable_error(): void {
		$staff_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$menu_id  = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );
		// メニューをアーカイブ済みにする => validate_menu() が menu_archived を返し、
		// get_calendar_meta() はここで早期return する（resolve_staff_ids() より前）。
		update_post_meta( $menu_id, '_vkbm_is_archived', '1' );

		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$controller = new Availability_Controller( new Availability_Service() );

		$request = new WP_REST_Request( 'GET', '/vkbm/v1/calendar-meta' );
		$request->set_param( 'menu_id', $menu_id );
		$request->set_param( 'year', 2030 );
		$request->set_param( 'month', 1 );
		$request->set_param( 'timezone', 'Asia/Tokyo' );

		$response = $controller->handle_calendar_meta( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'menu_archived', $response->get_error_code(), '管理者には元のエラーコードが返るべき' );

		$error_data = $response->get_error_data();
		$this->assertIsArray( $error_data );
		$this->assertArrayHasKey( 'unavailability_reason', $error_data, 'menu_archived（P3系）の経路でも診断理由が付与されるべき' );
		$this->assertSame( 'menu_not_bookable', $error_data['unavailability_reason']['code'] ?? null );
	}

	/**
	 * 予約可能日が1件でも存在する場合、管理者であっても診断処理（get_unavailability_reason）が
	 * 呼ばれず、unavailability_reason フィールドがレスポンスに含まれないことを検証する。
	 *
	 * #411 安藤さんレビュー指摘: 診断処理は日数×スタッフ数ぶんの WP_Query を発行しうるため、
	 * 「予約可能日が1件も無いとき」だけに限定する門番（has_bookable_day）が効いているかを確認する。
	 */
	public function test_handle_calendar_meta_skips_diagnosis_when_bookable_day_exists(): void {
		$now    = current_datetime();
		$target = $now->modify( '+2 months' );
		$year   = (int) $target->format( 'Y' );
		$month  = (int) $target->format( 'n' );

		$staff_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$menu_id  = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );
		update_post_meta( $menu_id, '_vkbm_duration_minutes', 60 );

		// 表示月の全日、所要時間に十分な勤務時間（9:00-18:00）を設定する => 予約可能日が存在する。
		$days_in_month = (int) ( new \DateTimeImmutable( sprintf( '%04d-%02d-01', $year, $month ) ) )->format( 't' );
		$days          = array();
		for ( $day = 1; $day <= $days_in_month; $day++ ) {
			$days[ $day ] = array(
				'status' => 'open',
				'slots'  => array(
					array(
						'start' => '09:00',
						'end'   => '18:00',
					),
				),
			);
		}
		$shift_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Shift_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $shift_id, '_vkbm_shift_resource_id', $staff_id );
		update_post_meta( $shift_id, '_vkbm_shift_year', $year );
		update_post_meta( $shift_id, '_vkbm_shift_month', $month );
		update_post_meta( $shift_id, '_vkbm_shift_days', $days );

		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$controller = new Availability_Controller( new Availability_Service() );

		$request = new WP_REST_Request( 'GET', '/vkbm/v1/calendar-meta' );
		$request->set_param( 'menu_id', $menu_id );
		$request->set_param( 'year', $year );
		$request->set_param( 'month', $month );
		$request->set_param( 'timezone', 'Asia/Tokyo' );

		$response = $controller->handle_calendar_meta( $request );
		$this->assertNotInstanceOf( \WP_Error::class, $response );

		$data = $response->get_data();
		$this->assertArrayNotHasKey( 'unavailability_reason', $data, '予約可能日が1件でもあれば診断フィールドは付与されないべき' );
	}

	/**
	 * 予約可能日が1件も無いメニューに対する /calendar-meta 応答で、
	 * unavailability_reason フィールドが権限に応じて存在有無が切り替わることを検証する。
	 *
	 * - 権限なし（匿名・購読者）: フィールド自体がレスポンスに含まれない（CSSで隠すのではなくサーバー側で省略）。
	 * - 権限あり（管理者）: フィールドが含まれ、期待どおりの理由コードを持つ。
	 */
	public function test_handle_calendar_meta_omits_unavailability_reason_for_users_without_capability(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'この診断シナリオ（担当スタッフの勤務時間不足）はPro版限定のスタッフ機能に依存するため、無料版ではスキップする。' );
		}

		$now    = current_datetime();
		$target = $now->modify( '+2 months' );
		$year   = (int) $target->format( 'Y' );
		$month  = (int) $target->format( 'n' );

		$staff_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$menu_id  = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );
		update_post_meta( $menu_id, '_vkbm_duration_minutes', 60 );

		// 勤務時間はあるが、メニューの所要時間（60分）に対して短すぎる（10分）シフトを表示月の全日に設定する。
		// #411 PR #414 レビュー指摘: このPRが src 側で撤去したのと同じタイムゾーン依存パターン
		// （`wp_date( 't', gmmktime(...) )` はサイトTZがUTCより マイナス側の場合に前月の日数を
		// 返しうる）をテストへ再度持ち込まないよう、Availability_Service::days_in_month() と
		// 同じ DateTimeImmutable ベースの算出（本ファイル168行目と同型）に揃える。
		$days_in_month = (int) ( new \DateTimeImmutable( sprintf( '%04d-%02d-01', $year, $month ) ) )->format( 't' );
		$days          = array();
		for ( $day = 1; $day <= $days_in_month; $day++ ) {
			$days[ $day ] = array(
				'status' => 'open',
				'slots'  => array(
					array(
						'start' => '10:00',
						'end'   => '10:10',
					),
				),
			);
		}
		$shift_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Shift_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $shift_id, '_vkbm_shift_resource_id', $staff_id );
		update_post_meta( $shift_id, '_vkbm_shift_year', $year );
		update_post_meta( $shift_id, '_vkbm_shift_month', $month );
		update_post_meta( $shift_id, '_vkbm_shift_days', $days );

		$controller = new Availability_Controller( new Availability_Service() );

		$test_cases = array(
			array(
				'test_condition_name'  => '匿名訪問者 => unavailability_reason フィールドが存在しない',
				'user_id'              => 0,
				'expect_field_present' => false,
			),
			array(
				'test_condition_name'  => '購読者（権限なし） => unavailability_reason フィールドが存在しない',
				'user_id'              => 'subscriber',
				'expect_field_present' => false,
			),
			array(
				'test_condition_name'  => '管理者（権限あり） => unavailability_reason フィールドが存在し、shift_too_short_for_duration を返す',
				'user_id'              => 'administrator',
				'expect_field_present' => true,
			),
		);

		foreach ( $test_cases as $case ) {
			if ( 'subscriber' === $case['user_id'] || 'administrator' === $case['user_id'] ) {
				$user_id = $this->factory()->user->create( array( 'role' => $case['user_id'] ) );
				wp_set_current_user( $user_id );
			} else {
				wp_set_current_user( 0 );
			}

			$request = new WP_REST_Request( 'GET', '/vkbm/v1/calendar-meta' );
			$request->set_param( 'menu_id', $menu_id );
			$request->set_param( 'year', $year );
			$request->set_param( 'month', $month );
			$request->set_param( 'timezone', 'Asia/Tokyo' );

			$response = $controller->handle_calendar_meta( $request );
			$this->assertNotInstanceOf( \WP_Error::class, $response, $case['test_condition_name'] );

			$data = $response->get_data();

			if ( $case['expect_field_present'] ) {
				$this->assertArrayHasKey( 'unavailability_reason', $data, $case['test_condition_name'] );
				$this->assertSame( 'shift_too_short_for_duration', $data['unavailability_reason']['code'] ?? null, $case['test_condition_name'] );
			} else {
				$this->assertArrayNotHasKey( 'unavailability_reason', $data, $case['test_condition_name'] );
			}
		}
	}
}
