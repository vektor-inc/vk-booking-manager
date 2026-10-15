<?php
/**
 * 管理画面の文言に、呼び方（リソース名称。初期値は「スタッフ」）が差し込まれることのテスト。
 *
 * issue #502。お知らせ・シフト編集画面・シフト一覧の一括追加・シフト・予約表の列見出しバッジを検証する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use ReflectionMethod;
use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Admin\Setup_Notices;
use VKBookingManager\Admin\Shift_Dashboard_Page;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Shift_Post_Type;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Shifts\Shift_Editor;
use WP_UnitTestCase;

/**
 * 呼び方を差し込んだ管理画面の文言を検証する。
 *
 * 呼び方を変える検証は Pro 版で行う（無料版は呼び方を変えられないため）。
 * 初期値（スタッフ）の検証は無料版でも行う。
 *
 * @group admin
 */
class Resource_Label_Admin_Text_Test extends WP_UnitTestCase {

	/**
	 * テスト前に設定を初期化し、管理者でログインする。
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( Settings_Repository::OPTION_KEY );
		Industry_Presets::clear_cache();
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * テスト後に設定・画面・リクエスト・ログイン状態を戻す。
	 */
	public function tear_down(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		Industry_Presets::clear_cache();
		$_GET = array();
		unset( $GLOBALS['current_screen'] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * 呼び方を設定として保存する。
	 *
	 * @param string $singular 単数形の呼び方。
	 * @param string $plural   複数形の呼び方。
	 */
	private function set_resource_labels( string $singular, string $plural ): void {
		Industry_Presets::clear_cache();
		update_option(
			Settings_Repository::OPTION_KEY,
			array_merge(
				( new Settings_Repository() )->get_default_settings(),
				array(
					'industry_preset'         => 'custom',
					'resource_label_singular' => $singular,
					'resource_label_plural'   => $plural,
				)
			)
		);
	}

	/**
	 * 設定を初期値のままにするか、呼び方を変えるかを切り替える。
	 *
	 * @param array<string, string>|null $labels 呼び方（singular / plural）。null なら初期値のまま。
	 */
	private function apply_case_labels( ?array $labels ): void {
		delete_option( Settings_Repository::OPTION_KEY );
		Industry_Presets::clear_cache();
		if ( null !== $labels ) {
			$this->set_resource_labels( $labels['singular'], $labels['plural'] );
		}
	}

	/**
	 * Pro 版でなければテストをスキップする（呼び方を変える検証用）。
	 */
	private function skip_if_free_edition(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '呼び方を変える検証は Pro 版で行う。' );
		}
	}

	/**
	 * 渡された処理の出力を文字列で返す。
	 *
	 * @param callable $callback 出力する処理。
	 * @return string
	 */
	private function capture( callable $callback ): string {
		ob_start();
		$callback();
		return (string) ob_get_clean();
	}

	/**
	 * 管理画面上部の初期設定のお知らせ（リソース未登録）の文言に呼び方が入ることを検証する。
	 */
	public function test_get_setup_items(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '初期値のまま => 「Staff」',
				'labels'              => null,
				'pro_only'            => false,
				'expected'            => array(
					'message'         => 'No Staff have been registered yet. First, add Staff.',
					'primary_label'   => 'Add Staff',
					'secondary_label' => 'Staff list',
				),
			),
			array(
				'test_condition_name' => '客室に変更 => 「客室」',
				'labels'              => array(
					'singular' => '客室',
					'plural'   => '客室',
				),
				'pro_only'            => true,
				'expected'            => array(
					'message'         => 'No 客室 have been registered yet. First, add 客室.',
					'primary_label'   => 'Add 客室',
					'secondary_label' => '客室 list',
				),
			),
			array(
				'test_condition_name' => '単数形と複数形が違う（room / rooms）=> メッセージは複数形・ボタンは単数形と複数形',
				'labels'              => array(
					'singular' => 'room',
					'plural'   => 'rooms',
				),
				'pro_only'            => true,
				'expected'            => array(
					'message'         => 'No rooms have been registered yet. First, add room.',
					'primary_label'   => 'Add room',
					'secondary_label' => 'rooms list',
				),
			),
		);

		$method = new ReflectionMethod( Setup_Notices::class, 'get_setup_items' );
		$method->setAccessible( true );

		foreach ( $test_cases as $case ) {
			if ( $case['pro_only'] && Pro_Upsell::is_free_edition() ) {
				continue;
			}
			$this->apply_case_labels( $case['labels'] );

			$staff_item = null;
			foreach ( $method->invoke( new Setup_Notices() ) as $item ) {
				if ( 'staff' === $item['id'] ) {
					$staff_item = $item;
				}
			}

			// 無料版はスタッフ機能が無効のため、このお知らせ項目自体が出ない。
			if ( Pro_Upsell::is_free_edition() ) {
				$this->assertNull( $staff_item, $case['test_condition_name'] . ' => 無料版ではお知らせ項目が出ない' );
				continue;
			}

			$this->assertIsArray( $staff_item, $case['test_condition_name'] . ' => リソースのお知らせ項目がある' );
			foreach ( $case['expected'] as $key => $expected ) {
				$this->assertSame( $expected, $staff_item[ $key ], $case['test_condition_name'] . ' => ' . $key );
			}
		}
	}

	/**
	 * シフト編集画面の見出しと選択肢の初期表示に呼び方が入ることを検証する。
	 */
	public function test_render_meta_box(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '初期値のまま => 見出しは「Staff」・選択肢は「Select Staff」（「（リソース）」は付かない）',
				'labels'              => null,
				'pro_only'            => false,
				'expected_contains'   => array( '>Staff</label>' ),
				'expected_missing'    => array( 'Staff (resources)', 'Select staff' ),
			),
			array(
				'test_condition_name' => '客室に変更 => 見出し・選択肢が「客室」',
				'labels'              => array(
					'singular' => '客室',
					'plural'   => '客室',
				),
				'pro_only'            => true,
				'expected_contains'   => array( '>客室</label>', 'Select 客室' ),
				'expected_missing'    => array( 'Staff (resources)', 'Select staff' ),
			),
		);

		foreach ( $test_cases as $case ) {
			if ( $case['pro_only'] && Pro_Upsell::is_free_edition() ) {
				continue;
			}
			$this->apply_case_labels( $case['labels'] );

			$resource_id = (int) $this->factory()->post->create(
				array(
					'post_type'   => Resource_Post_Type::POST_TYPE,
					'post_status' => 'publish',
				)
			);
			$post_id     = (int) $this->factory()->post->create(
				array(
					'post_type'   => Shift_Post_Type::POST_TYPE,
					'post_status' => 'publish',
				)
			);
			update_post_meta( $post_id, '_vkbm_shift_resource_id', $resource_id );

			$html = $this->capture( fn() => ( new Shift_Editor() )->render_meta_box( get_post( $post_id ) ) );

			foreach ( $case['expected_contains'] as $needle ) {
				$this->assertStringContainsString( $needle, $html, $case['test_condition_name'] . ' => 含む: ' . $needle );
			}
			foreach ( $case['expected_missing'] as $needle ) {
				$this->assertStringNotContainsString( $needle, $html, $case['test_condition_name'] . ' => 含まない: ' . $needle );
			}
		}
	}

	/**
	 * シフト一覧の一括追加の説明文と、一括登録後のお知らせの文言を検証する。
	 */
	public function test_render_bulk_create_panel(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '初期値のまま => 「Staffごと」に加えて、公開するまで予約に使われない旨',
				'labels'              => null,
				'pro_only'            => false,
				'expected'            => 'Register draft shifts for the specified month for each Staff at once. Drafts are not used for reservations until they are published.',
			),
			array(
				'test_condition_name' => '客室に変更 => 「客室ごと」',
				'labels'              => array(
					'singular' => '客室',
					'plural'   => '客室',
				),
				'pro_only'            => true,
				'expected'            => 'Register draft shifts for the specified month for each 客室 at once. Drafts are not used for reservations until they are published.',
			),
		);

		// シフト一覧画面（edit.php?post_type=vkbm_shift）として扱わせる。
		set_current_screen( 'edit-' . Shift_Post_Type::POST_TYPE );
		$this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		foreach ( $test_cases as $case ) {
			if ( $case['pro_only'] && Pro_Upsell::is_free_edition() ) {
				continue;
			}
			$this->apply_case_labels( $case['labels'] );

			$html = $this->capture( fn() => ( new Shift_Editor() )->render_bulk_create_panel() );

			$this->assertStringContainsString( $case['expected'], preg_replace( '/\s+/', ' ', $html ), $case['test_condition_name'] );
		}
	}

	/**
	 * 一括登録後の完了のお知らせの末尾に、公開するまで予約に使われない旨が付くことを検証する。
	 */
	public function test_render_bulk_create_notice(): void {
		set_current_screen( 'edit-' . Shift_Post_Type::POST_TYPE );
		$_GET['vkbm_shift_bulk_created'] = '3';
		$_GET['vkbm_shift_bulk_skipped'] = '1';
		$_GET['vkbm_shift_bulk_year']    = '2026';
		$_GET['vkbm_shift_bulk_month']   = '10';

		$html = $this->capture( fn() => ( new Shift_Editor() )->render_bulk_create_notice() );

		$this->assertStringContainsString( '(Created: 3 / Skip: 1). They are not used for reservations until published.', $html );
	}

	/**
	 * シフト・予約表の列見出しバッジの文字を、役職 → 抜粋 → 無し（空）の順で決めることを検証する。
	 */
	public function test_derive_resource_role(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '役職あり（抜粋もあり）=> 役職',
				'role'                => '  店長  ',
				'excerpt'             => '抜粋の文',
				'expected'            => '店長',
			),
			array(
				'test_condition_name' => '役職なし・抜粋あり（タグ混じり）=> 抜粋（タグは除く）',
				'role'                => '',
				'excerpt'             => '<b>抜粋</b>の文',
				'expected'            => '抜粋の文',
			),
			array(
				'test_condition_name' => '役職も抜粋も空白だけ => 空（固定の「Staff」を出さない）',
				'role'                => '   ',
				'excerpt'             => '   ',
				'expected'            => '',
			),
			array(
				'test_condition_name' => '役職も抜粋も無し => 空（固定の「Staff」を出さない）',
				'role'                => null,
				'excerpt'             => '',
				'expected'            => '',
			),
		);

		$method = new ReflectionMethod( Shift_Dashboard_Page::class, 'derive_resource_role' );
		$method->setAccessible( true );
		$page = new Shift_Dashboard_Page();

		foreach ( $test_cases as $case ) {
			$resource_id = (int) $this->factory()->post->create(
				array(
					'post_type'    => Resource_Post_Type::POST_TYPE,
					'post_status'  => 'publish',
					'post_excerpt' => $case['excerpt'],
				)
			);
			if ( null !== $case['role'] ) {
				update_post_meta( $resource_id, '_vkbm_resource_role', $case['role'] );
			}

			$this->assertSame( $case['expected'], $method->invoke( $page, get_post( $resource_id ) ), $case['test_condition_name'] );
		}
	}

	/**
	 * 「Please select %s.」のうち、リソース用（文脈 resource）の日本語訳に空白が入らず、
	 * 個人情報保護方針のお知らせ用（文脈なし）の訳と別になっていることを検証する。
	 */
	public function test_resource_select_message_translation(): void {
		$mo_file = dirname( __DIR__, 3 ) . '/languages/vk-booking-manager-ja.mo';
		if ( ! file_exists( $mo_file ) ) {
			$this->markTestSkipped( '日本語の翻訳ファイルが無いためスキップする。' );
		}

		$domain = 'vk-booking-manager';
		unload_textdomain( $domain );
		load_textdomain( $domain, $mo_file );

		$resource_message = sprintf( _x( 'Please select %s.', 'resource', $domain ), '客室' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain
		$other_message    = sprintf( __( 'Please select %s.', $domain ), '個人情報保護方針' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain

		unload_textdomain( $domain );

		$this->assertSame( '客室を選択してください。', $resource_message, 'リソース用 => 空白なし' );
		$this->assertSame( '個人情報保護方針 を選択してください。', $other_message, '個人情報保護方針のお知らせ用 => 従来の訳のまま' );
	}
}
