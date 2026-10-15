<?php
/**
 * Regression test: weekday abbreviations must not be reused as date-unit labels.
 *
 * 曜日略称（Sun / Mon）の msgid を、日・月の単位や日別/月別の切替表示へ流用しない回帰テスト。
 *
 * @package VKBookingManager
 * @see     https://github.com/vektor-inc/vk-booking-manager-pro/issues/804
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use WP_UnitTestCase;

/**
 * Verify that the 'Sun' / 'Mon' msgids are only used for real weekday labels.
 *
 * 'Sun' / 'Mon' の msgid が本当の曜日表示にだけ使われていることを検証する。
 *
 * @group admin
 * @group regression
 */
class Weekday_Msgid_Not_Used_For_Units_Test extends WP_UnitTestCase {

	/**
	 * Files that showed day / month units or view switch labels with the weekday msgids.
	 *
	 * 曜日の msgid を日・月の表示に流用していたファイル（プラグインルートからの相対パス）。
	 *
	 * @return array<string, array{0: string}>
	 */
	public function provide_unit_label_files(): array {
		return array(
			'shift dashboard'     => array( 'src/admin/class-shift-dashboard-page.php' ),
			'auth shortcodes'     => array( 'src/auth/class-auth-shortcodes.php' ),
			'user profile fields' => array( 'src/admin/class-user-profile-fields.php' ),
			'shift editor'        => array( 'src/shifts/class-shift-editor.php' ),
		);
	}

	/**
	 * The files must not use 'Sun' / 'Mon' as the msgid of _e / _x / esc_*_e / esc_*__ / esc_*_x.
	 * Weekday arrays use __() and are not affected.
	 *
	 * 曜日略称を画面へ直接出力していないこと（曜日の配列は __() のため対象外）。
	 *
	 * @dataProvider provide_unit_label_files
	 *
	 * @param string $relative_path Plugin-root relative file path.
	 */
	public function test_weekday_msgid_is_not_printed_as_unit_label( string $relative_path ): void {
		// ファイルの中身を読み込む。
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/' . $relative_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		// 曜日略称を _e / _x / esc_* 系の翻訳関数で出力する記述が無いことを確認する。
		$this->assertDoesNotMatchRegularExpression(
			"/(_e|_x|esc_html_e|esc_attr_e|esc_html__|esc_attr__|esc_html_x|esc_attr_x)\(\s*'(Sun|Mon)'/",
			$source
		);
	}

	/**
	 * The shift editor must not pass 'Sun' as the day label of the JS config.
	 *
	 * シフト編集画面が、日の表示用の設定に 'Sun' を渡していないこと。
	 */
	public function test_shift_editor_day_format_does_not_use_weekday_msgid(): void {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/src/shifts/class-shift-editor.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		// 'dayFormat' が曜日の msgid ではなく、コンテキスト付きの書式文字列であることを確認する。
		$this->assertMatchesRegularExpression( "/'dayFormat'\s*=>\s*_x\(\s*'%s'/", $source );
		$this->assertStringNotContainsString( "'daySuffix'", $source );
	}
}
