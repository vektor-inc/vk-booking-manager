<?php
/**
 * #427: 予約ページの表示要素（絞り込み検索／サービスメニュー一覧）が
 * REST レスポンスへ正しく反映されることのテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\REST;

use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\REST\Provider_Settings_Controller;
use WP_UnitTestCase;

/**
 * #427: 「予約ページの表示要素」の「絞り込み検索」「サービスメニュー一覧」チェックの
 * 組み合わせ（4パターン）が、フロント（app.js）が参照する REST レスポンスへ
 * そのまま反映されることを検証する。
 *
 * app.js 側の実際の出し分け（絞り込み検索 UI / メニュー一覧 / 両方 / どちらも無し時の
 * フォールバック）は React コンポーネントの分岐のため PHPUnit からは直接実行できない。
 * そのため、分岐の判断材料となるこの REST レスポンス（reservation_show_menu_search /
 * reservation_show_menu_list の値）が保存内容と一致することをここで固定する。
 *
 * @group rest
 * @group provider-settings
 */
class Provider_Settings_Controller_Menu_Display_Test extends WP_UnitTestCase {
	/**
	 * get_settings() のレスポンスが、保存済みの
	 * reservation_show_menu_search / reservation_show_menu_list の組み合わせを
	 * そのまま返すことを検証する。
	 */
	public function test_get_settings(): void {
		$repository = new Settings_Repository();
		$defaults   = $repository->get_default_settings();
		$controller = new Provider_Settings_Controller( $repository );

		$test_cases = array(
			array(
				'test_condition_name' => '両方未チェックの場合 => 両方 false（現状どおり絞り込み検索側にフォールバックする前提のデータ）',
				'conditions'          => array(
					'reservation_show_menu_search' => false,
					'reservation_show_menu_list'   => false,
				),
				'expected'            => array(
					'reservation_show_menu_search' => false,
					'reservation_show_menu_list'   => false,
				),
			),
			array(
				'test_condition_name' => '絞り込み検索のみチェックの場合 => search=true / list=false',
				'conditions'          => array(
					'reservation_show_menu_search' => true,
					'reservation_show_menu_list'   => false,
				),
				'expected'            => array(
					'reservation_show_menu_search' => true,
					'reservation_show_menu_list'   => false,
				),
			),
			array(
				'test_condition_name' => 'サービスメニュー一覧のみチェックの場合 => search=false / list=true（既存サイトの既定挙動）',
				'conditions'          => array(
					'reservation_show_menu_search' => false,
					'reservation_show_menu_list'   => true,
				),
				'expected'            => array(
					'reservation_show_menu_search' => false,
					'reservation_show_menu_list'   => true,
				),
			),
			array(
				'test_condition_name' => '両方チェックの場合 => 両方 true',
				'conditions'          => array(
					'reservation_show_menu_search' => true,
					'reservation_show_menu_list'   => true,
				),
				'expected'            => array(
					'reservation_show_menu_search' => true,
					'reservation_show_menu_list'   => true,
				),
			),
		);

		foreach ( $test_cases as $case ) {
			// サニタイズ後の値を直接保存する（保存経路（フォーム送信→サニタイズ）の検証は
			// Settings_Sanitizer_Test の責務のため、ここではリポジトリ→コントローラーの
			// 受け渡しのみを対象にする）。
			$repository->update_settings( array_merge( $defaults, $case['conditions'] ) );

			$data = $controller->get_settings()->get_data();

			foreach ( $case['expected'] as $key => $value ) {
				$this->assertSame( $value, $data[ $key ], $case['test_condition_name'] . " (key: {$key})" );
			}
		}
	}

	/**
	 * #427 司の方針: 新設定が未保存の既存サイト（および新規サイト）でも表示を変えないため、
	 * オプション自体が保存されていない状態（フレッシュインストール相当）でも
	 * reservation_show_menu_search が false として返ることを固定する（境界値）。
	 */
	public function test_get_settings_defaults_to_menu_search_disabled_when_option_not_saved(): void {
		$repository = new Settings_Repository();
		delete_option( Settings_Repository::OPTION_KEY );

		$controller = new Provider_Settings_Controller( $repository );
		$data       = $controller->get_settings()->get_data();

		$this->assertFalse( $data['reservation_show_menu_search'] );
		// 既存の既定値（サービスメニュー一覧は表示 ON）が変わっていないことも合わせて確認する。
		$this->assertTrue( $data['reservation_show_menu_list'] );
	}

	/**
	 * #427 安藤レビュー指摘（LOW）: 上のテストはオプション自体が無い（フレッシュインストール）
	 * ケースしか見ていない。ここでは「設定は保存済みだが、新しいキー
	 * reservation_show_menu_search だけが無い既存サイト」（#427 以前から運用しているサイトを
	 * 想定）を再現し、Settings_Repository::get_settings() のデフォルト値マージにより
	 * reservation_show_menu_search が false として返り、既存の
	 * reservation_show_menu_list の保存値はそのまま維持されることを固定する。
	 *
	 * #427 安藤再レビュー指摘（LOW）: reservation_show_menu_list の保存値に既定値（true）と
	 * 同じ値を使うと、保存値が取り込まれずに既定値で補われた場合でもテストが通ってしまい、
	 * 「保存値がそのまま維持される」ことを検証できていなかった。既定値と区別できる false を
	 * 保存値に使うよう修正した。これは同時に「サービスメニュー一覧 OFF の既存サイトは、
	 * 絞り込み検索へ自動で切り替わる」境目のケースにもなっている。
	 */
	public function test_get_settings_defaults_to_menu_search_disabled_when_existing_site_lacks_new_key(): void {
		$repository = new Settings_Repository();
		$defaults   = $repository->get_default_settings();

		// #427 以前の既存サイトを模した保存値: reservation_show_menu_search キー自体が無く、
		// reservation_show_menu_list は OFF（既定値 true と区別できる値。かつ一覧 OFF の既存
		// サイトが絞り込み検索へ自動で切り替わる境目のケースにもなる）で保存されているものとする。
		$existing_site_settings = $defaults;
		unset( $existing_site_settings['reservation_show_menu_search'] );
		$existing_site_settings['reservation_show_menu_list'] = false;

		update_option( Settings_Repository::OPTION_KEY, $existing_site_settings );

		$controller = new Provider_Settings_Controller( $repository );
		$data       = $controller->get_settings()->get_data();

		$this->assertFalse( $data['reservation_show_menu_search'] );
		$this->assertFalse( $data['reservation_show_menu_list'] );
	}
}
