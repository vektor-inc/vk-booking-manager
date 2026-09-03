<?php
/**
 * 数量の見出し・単位ヘルパー（resource-labels.php）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Common;

use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_UnitTestCase;

/**
 * 数量の見出し・単位ヘルパーのテストクラス。
 *
 * @group resource-labels
 */
class Guests_Label_Helpers_Test extends WP_UnitTestCase {
	/**
	 * 各テスト前にオプションを初期化し、英語ロケールを確実に確立する。
	 *
	 * 注意（テスト間のロケール汚染対策）:
	 * 先行する別テスト（例: auth のショートコードテスト）が switch_to_locale('ja') と
	 * load_textdomain() で日本語の翻訳テーブルをプロセスに読み込んだまま後始末しないため、
	 * フルスイート実行時はこのテストに ja の textdomain が残留する。
	 * その状態では add_filter('locale','en_US') だけではロード済みキャッシュを上書きできず、
	 * __( 'Number of guests' ) が「人数」、__( 'guests' )（ja.po で空訳）が '' になってしまう。
	 * そのため set_up で textdomain を明示的にアンロードし、英語（原文）が返る状態へ戻す。
	 */
	public function set_up(): void {
		parent::set_up();

		delete_option( Settings_Repository::OPTION_KEY );

		// 残留している可能性のある翻訳テーブルをアンロードし、原文（英語）が返る状態にする。
		// 第2引数 false（reloadable=false）で $l10n_unloaded フラグを立て、
		// WP 6.5+ の JIT 翻訳ロード（.l10n.php の自動再読込）を抑止する。
		// これをしないと __( 'guests' ) が ja の .l10n.php の空訳エントリを引いて '' になる。
		if ( function_exists( 'unload_textdomain' ) ) {
			unload_textdomain( 'vk-booking-manager', false );
		}
	}

	/**
	 * 各テスト後にオプションを削除し、アンロードした textdomain を元に戻す。
	 *
	 * 後続テストが日本語翻訳を前提にしている場合に備え、可能なら再ロードしておく。
	 */
	public function tear_down(): void {
		delete_option( Settings_Repository::OPTION_KEY );

		// 後続テストへの影響を避けるため、可能であれば翻訳テーブルを再ロードして元の状態に近づける。
		$mo_path = dirname( __DIR__, 3 ) . '/languages/vk-booking-manager-ja.mo';
		if ( file_exists( $mo_path ) && function_exists( 'load_textdomain' ) ) {
			load_textdomain( 'vk-booking-manager', $mo_path );
		}

		parent::tear_down();
	}

	/**
	 * 見出しヘルパーは空ならデフォルトへフォールバックし、値があればそれを返す。
	 */
	public function test_vkbm_get_guests_count_label(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'オプション未保存（キー無し）の場合 => 翻訳デフォルト Number of guests',
				'conditions'          => array(
					'options' => null,
					'locale'  => 'en_US',
				),
				'expected'            => 'Number of guests',
			),
			array(
				'test_condition_name' => '空文字保存の場合 => 翻訳デフォルトへフォールバック',
				'conditions'          => array(
					'options' => array( 'guests_count_label' => '' ),
					'locale'  => 'en_US',
				),
				'expected'            => 'Number of guests',
			),
			array(
				'test_condition_name' => '前後スペースのみ保存の場合 => trim 後に空となりデフォルトへフォールバック',
				'conditions'          => array(
					'options' => array( 'guests_count_label' => '   ' ),
					'locale'  => 'en_US',
				),
				'expected'            => 'Number of guests',
			),
			array(
				'test_condition_name' => '台数 を保存した場合 => 台数',
				'conditions'          => array(
					'options' => array( 'guests_count_label' => '台数' ),
					'locale'  => 'en_US',
				),
				'expected'            => '台数',
			),
		);

		foreach ( $test_cases as $case ) {
			delete_option( Settings_Repository::OPTION_KEY );

			$options = $case['conditions']['options'] ?? null;
			if ( null !== $options ) {
				update_option( Settings_Repository::OPTION_KEY, $options );
			}

			// ロケールは `locale` フィルターで強制する（翻訳の有無に依存させない）。
			$locale        = $case['conditions']['locale'] ?? '';
			$locale_filter = static function () use ( $locale ) {
				return $locale;
			};
			if ( is_string( $locale ) && '' !== $locale ) {
				add_filter( 'locale', $locale_filter );
			}

			try {
				$actual = vkbm_get_guests_count_label();
				$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
			} finally {
				if ( is_string( $locale ) && '' !== $locale ) {
					remove_filter( 'locale', $locale_filter );
				}
			}
		}
	}

	/**
	 * 単位ヘルパーは null / '' / 値 を出し分ける。
	 *
	 * - null（キー未保存）  → ロケール既定（日本語「名」/英語「guests」）
	 * - ''（意図的な空保存） → 空文字（単位なし）
	 * - 値                  → その値
	 */
	public function test_vkbm_get_guests_unit_label(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'オプション未保存（キー無し）かつ日本語ロケール => ロケール既定「名」',
				'conditions'          => array(
					'options' => null,
					'locale'  => 'ja',
				),
				'expected'            => '名',
			),
			array(
				'test_condition_name' => 'オプション未保存（キー無し）かつ英語ロケール => ロケール既定「guests」',
				'conditions'          => array(
					'options' => null,
					'locale'  => 'en_US',
				),
				'expected'            => 'guests',
			),
			array(
				'test_condition_name' => '空文字保存（意図的な単位なし） => 空文字',
				'conditions'          => array(
					'options' => array( 'guests_unit_label' => '' ),
					'locale'  => 'ja',
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '台 を保存した場合 => 台',
				'conditions'          => array(
					'options' => array( 'guests_unit_label' => '台' ),
					'locale'  => 'ja',
				),
				'expected'            => '台',
			),
		);

		foreach ( $test_cases as $case ) {
			delete_option( Settings_Repository::OPTION_KEY );

			$options = $case['conditions']['options'] ?? null;
			// null を含むオプションを保存できるよう、配列で指定されている場合のみ更新する。
			if ( null !== $options ) {
				update_option( Settings_Repository::OPTION_KEY, $options );
			}

			// ロケールは `locale` フィルターで強制する。
			// switch_to_locale() は対象言語が未インストールだと false を返し get_locale() が
			// 変わらないため、翻訳の有無に依存しないフィルター方式でテストする。
			$locale        = $case['conditions']['locale'] ?? '';
			$locale_filter = static function () use ( $locale ) {
				return $locale;
			};
			if ( is_string( $locale ) && '' !== $locale ) {
				add_filter( 'locale', $locale_filter );
			}

			try {
				$actual = vkbm_get_guests_unit_label();
				$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
			} finally {
				if ( is_string( $locale ) && '' !== $locale ) {
					remove_filter( 'locale', $locale_filter );
				}
			}
		}
	}

	/**
	 * 数量整形ヘルパーは単位のスペーシングを正しく扱う。
	 *
	 * - 単位なし（空文字）       → 数値のみ
	 * - 全角単位（名/台）        → 数値と詰める（5名 / 5台）
	 * - 半角英字始まりの単位     → 数値との間に半角スペース（5 guests）
	 */
	public function test_vkbm_format_guests_count(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '単位が空文字（単位なし）の場合 => 数値のみ',
				'conditions'          => array(
					'count' => 5,
					'unit'  => '',
				),
				'expected'            => '5',
			),
			array(
				'test_condition_name' => '単位が「名」の場合 => 詰めて 5名',
				'conditions'          => array(
					'count' => 5,
					'unit'  => '名',
				),
				'expected'            => '5名',
			),
			array(
				'test_condition_name' => '単位が「台」の場合 => 詰めて 3台',
				'conditions'          => array(
					'count' => 3,
					'unit'  => '台',
				),
				'expected'            => '3台',
			),
			array(
				'test_condition_name' => '単位が半角英字「guests」の場合 => 半角スペースを挟んで 5 guests',
				'conditions'          => array(
					'count' => 5,
					'unit'  => 'guests',
				),
				'expected'            => '5 guests',
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = vkbm_format_guests_count(
				(int) $case['conditions']['count'],
				$case['conditions']['unit']
			);
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}
}
