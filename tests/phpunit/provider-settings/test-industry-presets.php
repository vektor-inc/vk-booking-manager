<?php
/**
 * 業種プリセットのテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\ProviderSettings;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Admin\Provider_Settings_Page;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use VKBookingManager\Staff\Staff_Editor;
use WP_UnitTestCase;

/**
 * 業種プリセットの定義・切り替え・実効値を検証する。
 *
 * @group provider-settings
 */
class Industry_Presets_Test extends WP_UnitTestCase {
	/**
	 * 各テスト前に設定を初期化する。
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( Settings_Repository::OPTION_KEY );
		remove_all_filters( 'vkbm_industry_presets' );
		// get_presets() のメモ化キャッシュはリクエスト内で使い回す設計のため、
		// テスト間でフィルターの付け外しの影響を受けないよう明示的に破棄する（#387 レビュー指摘）。
		Industry_Presets::clear_cache();
	}

	/**
	 * 各テスト後に設定とフィルターを破棄する。
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		remove_all_filters( 'vkbm_industry_presets' );
		Industry_Presets::clear_cache();
		parent::tear_down();
	}

	/**
	 * 未設定サイトは custom のまま既存値を返すことを検証する。
	 *
	 * @return void
	 */
	public function test_get_settings(): void {
		$repository = new Settings_Repository();
		$defaults   = $repository->get_default_settings();
		$settings   = $repository->get_settings();

		$this->assertSame( 'custom', $settings['industry_preset'], 'プリセット未設定の既存サイトは custom になること' );
		foreach ( Industry_Presets::FIELD_KEYS as $field_key ) {
			$this->assertSame( $defaults[ $field_key ], $settings[ $field_key ], 'custom では既存の既定値と表示値を変更しないこと: ' . $field_key );
		}
	}

	/**
	 * 非表示項目は保存値と読み取り値の両方へ強制されることを検証する。
	 *
	 * @return void
	 */
	public function test_apply_effective_values(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '業種プリセットの実効値は Pro 版で検証する。' );
		}

		$repository = new Settings_Repository();
		$raw        = array_merge(
			$repository->get_default_settings(),
			array(
				'industry_preset'         => 'seminar',
				'staff_enabled'           => true,
				'slot_capacity_enabled'   => false,
				'resource_label_singular' => 'ユーザー入力',
			)
		);
		$repository->update_settings( $raw );

		$stored   = get_option( Settings_Repository::OPTION_KEY, array() );
		$settings = $repository->get_settings();
		$this->assertFalse( $stored['staff_enabled'], '保存時に非表示の指名機能を無効へ強制すること' );
		$this->assertTrue( $stored['slot_capacity_enabled'], '保存時に非表示の定員機能を有効へ強制すること' );
		$this->assertSame( __( 'Instructor', 'vk-booking-manager' ), $stored['resource_label_singular'], '保存時に非表示の名称を講師へ強制すること' );

		// 保存後に raw option を改変しても、読み取り側でプリセット値へ戻ることを確認する。
		$stored['staff_enabled']           = true;
		$stored['slot_capacity_enabled']   = false;
		$stored['resource_label_singular'] = '別経路の値';
		update_option( Settings_Repository::OPTION_KEY, $stored );
		$settings = $repository->get_settings();
		$this->assertFalse( $settings['staff_enabled'], 'raw option の指名機能を読み取り側で無効へ強制すること' );
		$this->assertTrue( $settings['slot_capacity_enabled'], 'raw option の定員機能を読み取り側で有効へ強制すること' );
		$this->assertSame( __( 'Instructor', 'vk-booking-manager' ), $settings['resource_label_singular'], 'raw option の名称を読み取り側で講師へ強制すること' );
	}

	/**
	 * 表示項目のプリセット切り替え規則を検証する。
	 *
	 * @return void
	 */
	public function test_apply_transition(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '業種プリセットの切り替えは Pro 版で検証する。' );
		}

		$repository = new Settings_Repository();
		$defaults   = $repository->get_default_settings();
		$test_cases = array(
			array(
				'test_condition_name' => 'custom の既定「スタッフ」から activity へ切り替えると「ガイド」になる',
				'current'             => array_merge( $defaults, array( 'industry_preset' => 'custom' ) ),
				'input'               => array(
					'industry_preset'         => 'activity',
					'resource_label_singular' => $defaults['resource_label_singular'],
				),
				'expected'            => __( 'Guide', 'vk-booking-manager' ),
			),
			array(
				'test_condition_name' => 'custom のユーザー編集値から activity へ切り替えても編集値を保持する',
				'current'             => array_merge(
					$defaults,
					array(
						'industry_preset'         => 'custom',
						'resource_label_singular' => 'セラピスト',
					)
				),
				'input'               => array(
					'industry_preset'         => 'activity',
					'resource_label_singular' => 'セラピスト',
				),
				'expected'            => 'セラピスト',
			),
			array(
				'test_condition_name' => 'activity の「ガイド」から salon へ切り替えると「スタッフ」になる',
				'current'             => array_merge(
					$defaults,
					array(
						'industry_preset'     => 'activity',
						'resource_label_menu' => __( 'Guide', 'vk-booking-manager' ),
					)
				),
				'input'               => array(
					'industry_preset'     => 'salon',
					'resource_label_menu' => __( 'Guide', 'vk-booking-manager' ),
				),
				'field_key'           => 'resource_label_menu',
				'expected'            => __( 'Staff available', 'vk-booking-manager' ),
			),
		);

		foreach ( $test_cases as $case ) {
			$sanitized = array_merge( $defaults, $case['input'] );
			$result    = Industry_Presets::apply_transition( $case['current'], $sanitized, $case['input'], $defaults );
			$field_key = $case['field_key'] ?? 'resource_label_singular';
			$this->assertSame( $case['expected'], $result[ $field_key ], $case['test_condition_name'] );
		}
	}

	/**
	 * custom へ戻す場合は値を変更しないことを検証する。
	 *
	 * @return void
	 */
	public function test_apply_transition_to_custom(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '業種プリセットの切り替えは Pro 版で検証する。' );
		}

		$repository = new Settings_Repository();
		$defaults   = $repository->get_default_settings();
		$current    = array_merge(
			$defaults,
			array(
				'industry_preset'         => 'seminar',
				'resource_label_singular' => __( 'Instructor', 'vk-booking-manager' ),
			)
		);
		$input      = array( 'industry_preset' => 'custom' );
		$result     = Industry_Presets::apply_transition( $current, array_merge( $defaults, $input ), $input, $defaults );

		$this->assertSame( 'custom', $result['industry_preset'], 'custom へ切り替わること' );
		$this->assertSame( __( 'Instructor', 'vk-booking-manager' ), $result['resource_label_singular'], '非表示だった項目も保存値を変更しないこと' );
	}

	/**
	 * 無料版では保存値に関わらず custom として扱うことを検証する。
	 *
	 * @return void
	 */
	public function test_get_current_preset(): void {
		// @pro-gate-ignore get_current_preset: markTestSkipped ではなく、期待値側を編集版で分岐させて無料版・Pro 版の両方で検証しているため対象外とする。
		$repository = new Settings_Repository();
		$defaults   = $repository->get_default_settings();
		$actual     = Industry_Presets::get_current_preset( array( 'industry_preset' => 'seminar' ), $defaults );
		$expected   = Pro_Upsell::is_free_edition() ? 'custom' : 'seminar';

		$this->assertSame( $expected, $actual, '無料版は custom 固定、Pro 版は有効な保存値を返すこと' );
	}

	/**
	 * フィルターでプリセットを追加・差し替えできることを検証する。
	 *
	 * @return void
	 */
	public function test_get_presets(): void {
		$repository = new Settings_Repository();
		$defaults   = $repository->get_default_settings();
		add_filter(
			'vkbm_industry_presets',
			static function ( array $presets ): array {
				$presets['salon']['label'] = '差し替えサロン';
				$presets['clinic']         = array(
					'label'  => 'クリニック',
					'fields' => $presets['custom']['fields'],
				);
				return $presets;
			}
		);

		$presets = Industry_Presets::get_presets( $defaults );
		$this->assertSame( '差し替えサロン', $presets['salon']['label'], '既存プリセットを差し替えられること' );
		$this->assertArrayHasKey( 'clinic', $presets, 'プリセットを追加できること' );
	}

	/**
	 * Settings_Service::save_settings() を通した実際の保存経路で、
	 * custom から他プリセットへ切り替えたときに display:false 項目が
	 * 強制上書きされ、display:true 項目もプリセット値へ差し替わることを検証する（#387）。
	 *
	 * JS は display:true 項目についてはフォーム送信前に新プリセットの値を書き込み、
	 * display:false 項目は行ごと disabled にして送信しない（本テストではその送信結果を模する）。
	 *
	 * @return void
	 */
	public function test_save_settings_switches_to_preset_values(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '業種プリセットの切り替えは Pro 版で検証する。' );
		}

		$service  = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$defaults = $service->get_default_settings();

		// custom（既定値のまま）を保存済みの状態にする。
		$service->save_settings( array_merge( $defaults, array( 'industry_preset' => 'custom' ) ) );

		// activity へ切り替える送信を模す。display:true 項目は JS が新プリセット値へ
		// 書き換え済みの状態、display:false 項目（resource_menu_icon 等）は行ごと
		// disabled のため送信されない（$raw から意図的に除外する）。
		$activity_preset = Industry_Presets::get_presets( $defaults )['activity']['fields'];
		$raw             = array_merge( $defaults, array( 'industry_preset' => 'activity' ) );
		foreach ( $activity_preset as $field_key => $field ) {
			if ( $field['display'] ) {
				$raw[ $field_key ] = $field['value'];
			} else {
				unset( $raw[ $field_key ] );
			}
		}

		$result = $service->save_settings( $raw );
		$this->assertTrue( $result, '保存が成功すること' );

		$settings = $service->get_settings();
		foreach ( $activity_preset as $field_key => $field ) {
			// get_presets() の時点で '__default__' は既定値へ解決済みのため、そのまま比較する。
			$this->assertSame(
				$field['value'],
				$settings[ $field_key ],
				'activity 切り替え後、' . $field_key . ' はプリセット値になること'
			);
		}
	}

	/**
	 * Settings_Service::save_settings() を通した実際の保存経路で、
	 * display:true 項目のうちユーザーが個別に編集した値は、プリセット切り替え時にも
	 * 保持されることを検証する（#387 仕様の核心：切り替え前プリセット値と一致しない値は保持）。
	 *
	 * @return void
	 */
	public function test_save_settings_preserves_customized_value_on_preset_switch(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '業種プリセットの切り替えは Pro 版で検証する。' );
		}

		$service  = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$defaults = $service->get_default_settings();

		// custom のまま resource_label_menu をユーザーが編集済みの状態にする。
		$service->save_settings(
			array_merge(
				$defaults,
				array(
					'industry_preset'     => 'custom',
					'resource_label_menu' => 'セラピスト一覧',
				)
			)
		);

		// salon へ切り替える。resource_label_menu は編集済み値のため JS も書き換えない
		// （送信値は変更前のまま）ことを模す。
		$raw = array_merge(
			$defaults,
			array(
				'industry_preset'     => 'salon',
				'resource_label_menu' => 'セラピスト一覧',
			)
		);

		$result = $service->save_settings( $raw );
		$this->assertTrue( $result, '保存が成功すること' );

		$settings = $service->get_settings();
		$this->assertSame(
			'セラピスト一覧',
			$settings['resource_label_menu'],
			'ユーザー編集値は salon 切り替え後も保持されること'
		);
	}

	/**
	 * Pro 版の custom プリセットで、指名機能が無効なため no_nomination_label /
	 * nomination_fee_label の行が描画されず送信もされない保存（provider_name 変更等の
	 * 別項目だけを保存するケース）でも、既存のカスタム編集値が失われないことを検証する（#387）。
	 *
	 * この2項目は FIELD_KEYS の display:true 項目だが、指名機能そのものがオフの間は
	 * 画面上の行が render されず $_POST に含まれない。Industry_Presets::apply_transition()
	 * の「フォーム未送信項目は現在値へ復元する」処理がこのケースを保護する。
	 *
	 * @return void
	 */
	public function test_save_settings_preserves_value_when_row_not_rendered(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '業種プリセットの切り替えは Pro 版で検証する。' );
		}

		$service  = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$defaults = $service->get_default_settings();

		// custom のまま指名機能をオフにし、指名ラベルをカスタム編集済みの状態で保存する。
		$service->save_settings(
			array_merge(
				$defaults,
				array(
					'industry_preset'      => 'custom',
					'staff_enabled'        => false,
					'no_nomination_label'  => 'カスタム未指名ラベル',
					'nomination_fee_label' => 'カスタム指名料ラベル',
				)
			)
		);
		Staff_Editor::clear_nomination_enabled_cache();
		$this->assertFalse( Staff_Editor::is_nomination_enabled(), '前提: 指名機能がオフであること' );

		// 指名機能オフのため no_nomination_label / nomination_fee_label の行は
		// 描画されず、$_POST にも含まれない。provider_name だけを変更した保存を模す。
		$current = $service->get_settings();
		$raw     = $current;
		unset( $raw['no_nomination_label'], $raw['nomination_fee_label'] );
		$raw['provider_name'] = '別の店舗名';

		$result = $service->save_settings( $raw );
		$this->assertTrue( $result, '保存が成功すること' );

		$settings = $service->get_settings();
		$this->assertSame( '別の店舗名', $settings['provider_name'], '対象外項目は通常どおり保存されること' );
		$this->assertSame(
			'カスタム未指名ラベル',
			$settings['no_nomination_label'],
			'行が描画されず未送信でも、既存のカスタム編集値が失われないこと'
		);
		$this->assertSame(
			'カスタム指名料ラベル',
			$settings['nomination_fee_label'],
			'行が描画されず未送信でも、既存のカスタム編集値が失われないこと'
		);
	}

	/**
	 * 無料版で industry_preset=salon を保存しても、実効値は custom のままで
	 * プリセット値が適用されないことを検証する（#387 レビュー指摘・安藤 LOW-6）。
	 *
	 * このテストは free / pro どちらのテストスイートでも実行され（markTestSkipped しない）、
	 * 実行中のエディションに応じて期待値を分岐させる。無料版の書き込み側強制がこれまで
	 * カバーされていなかったための追加。
	 *
	 * 無料版フォームには選択欄自体が無いため、industry_preset キーが明示的に送信される
	 * のは REST 等の非通常経路のみだが、その場合も Settings_Sanitizer::sanitize() が
	 * get_current_preset()（無料版は常に custom を返す）で確定させるため、保存値は
	 * 送信された salon ではなく custom になる（新しい値の送信を無料版では受け付けない）。
	 * これに対し、選択済みプリセットの保存値がそのまま保持される（industry_preset 自体が
	 * 未送信の）ケースの検証は test_save_settings_preserves_selected_preset_across_editions
	 * が担う（#387 レビュー指摘・安藤 LOW-7・案B）。
	 *
	 * @return void
	 */
	public function test_save_settings_ignores_industry_preset_on_free_edition(): void {
		$service  = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$defaults = $service->get_default_settings();

		$raw = array_merge(
			$defaults,
			array(
				'industry_preset'         => 'salon',
				'resource_label_singular' => $defaults['resource_label_singular'],
			)
		);

		$result = $service->save_settings( $raw );
		$this->assertTrue( $result, '保存が成功すること' );

		$settings = $service->get_settings();
		$stored   = get_option( Settings_Repository::OPTION_KEY, array() );

		if ( Pro_Upsell::is_free_edition() ) {
			$this->assertSame( 'custom', $settings['industry_preset'], '無料版では実効値が常に custom であること' );
			$this->assertSame(
				$defaults['resource_label_singular'],
				$settings['resource_label_singular'],
				'無料版では salon のプリセット値が適用されないこと'
			);
			$this->assertSame(
				'custom',
				$stored['industry_preset'] ?? null,
				'無料版で明示的に salon を送信しても、Settings_Sanitizer が custom へ確定させ、保存値として salon が残らないこと'
			);
		} else {
			$this->assertSame( 'salon', $settings['industry_preset'], 'Pro版では salon が適用されること' );
			$this->assertSame(
				__( 'Staff', 'vk-booking-manager' ),
				$settings['resource_label_singular'],
				'Pro版では salon のプリセット値（スタッフ）が適用されること'
			);
		}
	}

	/**
	 * Pro から無料版へ切り替えた状態で他の項目だけを保存しても、以前選択していた
	 * industry_preset の保存値が失われず、再度 Pro へ戻すと復元されることを検証する
	 * （#387 レビュー指摘・安藤 LOW-7）。
	 *
	 * 無料版でのみ意味のある検証のため、Pro版のスイートではスキップする。
	 *
	 * @return void
	 */
	public function test_save_settings_preserves_selected_preset_across_editions(): void {
		if ( ! Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'この検証は無料版スイートでのみ意味を持つ。' );
		}

		// Pro 版で 'seminar' を選んでいた状態を模す（無料版スイートでは通常の保存経路では
		// 作れないため、raw option へ直接書き込んで前提条件を作る）。
		$repository = new Settings_Repository();
		$defaults   = $repository->get_default_settings();
		update_option(
			Settings_Repository::OPTION_KEY,
			array_merge( $defaults, array( 'industry_preset' => 'seminar' ) )
		);

		$service = new Settings_Service( $repository, new Settings_Sanitizer() );
		$raw     = array_merge( $defaults, array( 'provider_name' => '無料版での更新' ) );
		unset( $raw['industry_preset'] ); // 無料版の画面には選択欄自体が無いため送信されない。

		$result = $service->save_settings( $raw );
		$this->assertTrue( $result, '保存が成功すること' );

		$stored = get_option( Settings_Repository::OPTION_KEY, array() );
		$this->assertSame(
			'seminar',
			$stored['industry_preset'] ?? null,
			'無料版で他の項目だけ保存しても、以前選択していたプリセットの保存値が消えないこと'
		);
		$this->assertSame( '無料版での更新', $stored['provider_name'] ?? null, '対象外項目は通常どおり保存されること' );
	}

	/**
	 * 指名機能が無効なとき、duration_label 等（対象外3項目）が設定画面から
	 * 消えないことを確認する（#387 レビュー指摘・安藤・植草の3回目再指摘）。
	 *
	 * この3項目は Industry_Presets::FIELD_KEYS（業種プリセットの対象10項目）に
	 * 含まれないため、指名機能の有効/無効を切り替える `<?php if ( Staff_Editor::
	 * is_nomination_enabled() ) : ?>` ブロックの内側に紛れ込むと、指名機能OFF時
	 * （無料版は常時OFF固定）に画面から消える回帰が起きる（origin/main では
	 * このブロックの外にあり無条件で描画されていた）。
	 *
	 * @return void
	 */
	public function test_provider_settings_page_shows_non_target_labels_when_nomination_disabled(): void {
		$admin_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user_id );

		$service = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$service->save_settings(
			array_merge( $service->get_default_settings(), array( 'staff_enabled' => false ) )
		);
		Staff_Editor::clear_nomination_enabled_cache();
		$this->assertFalse( Staff_Editor::is_nomination_enabled(), '前提: 指名機能が無効であること' );

		$page = new Provider_Settings_Page( $service );
		ob_start();
		$page->render_page();
		$output = (string) ob_get_clean();

		wp_set_current_user( 0 );
		Staff_Editor::clear_nomination_enabled_cache();

		$this->assertStringContainsString( 'id="vkbm-duration-label"', $output, '指名機能が無効でも Duration label が表示されること' );
		$this->assertStringContainsString( 'id="vkbm-other-conditions-label"', $output, '指名機能が無効でも Other conditions label が表示されること' );
		$this->assertStringContainsString( 'id="vkbm-guests-count-label"', $output, '指名機能が無効でも Quantity label が表示されること' );
	}

	/**
	 * 業種を選んだとき、自動設定される項目の行が最初の描画から hidden で出力されることを検証する。
	 *
	 * 行の非表示を provider-settings.js だけで行うと、スクリプトはフッター読み込みのため
	 * JS 実行までの間その行がそのまま見えてしまう。セミナーでは指名機能が自動設定
	 * （無効・非表示）の対象であり、この一瞬の表示が「セミナーなのに指名機能の設定が出る」
	 * という見え方になっていた。
	 *
	 * @return void
	 */
	public function test_provider_settings_page_hides_auto_configured_rows_in_markup(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '業種プリセットの行制御は Pro 版で検証する。' );
		}

		$admin_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user_id );

		$service = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$service->save_settings(
			array_merge( $service->get_default_settings(), array( 'industry_preset' => 'seminar' ) )
		);
		Staff_Editor::clear_nomination_enabled_cache();

		$page = new Provider_Settings_Page( $service );
		ob_start();
		$page->render_page();
		$output = (string) ob_get_clean();

		wp_set_current_user( 0 );
		Staff_Editor::clear_nomination_enabled_cache();

		$this->assertStringContainsString(
			'hidden',
			$this->get_industry_row_tag( $output, 'staff_enabled' ),
			'セミナーでは指名機能の行が hidden 付きで出力されること'
		);
		$this->assertStringContainsString(
			'hidden',
			$this->get_industry_row_tag( $output, 'slot_capacity_enabled' ),
			'セミナーでは予約枠の定員機能の行が hidden 付きで出力されること'
		);
		$this->assertStringNotContainsString(
			'hidden',
			$this->get_industry_row_tag( $output, 'resource_label_menu' ),
			'セミナーで表示対象のリソースラベルの行には hidden が付かないこと'
		);
	}

	/**
	 * カスタムでは業種プリセット対象の行に hidden が付かないことを検証する。
	 *
	 * @return void
	 */
	public function test_provider_settings_page_does_not_hide_rows_on_custom(): void {
		$admin_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user_id );

		$service = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$page    = new Provider_Settings_Page( $service );
		ob_start();
		$page->render_page();
		$output = (string) ob_get_clean();

		wp_set_current_user( 0 );

		$this->assertStringNotContainsString(
			'hidden',
			$this->get_industry_row_tag( $output, 'staff_enabled' ),
			'カスタムでは指名機能の行に hidden が付かないこと'
		);
	}

	/**
	 * get_presets() の一覧に lodging が含まれ、features を持つことを検証する（#577。開発用の切り替えは削除済み）。
	 *
	 * @return void
	 */
	public function test_get_presets_includes_lodging(): void {
		$defaults = ( new Settings_Repository() )->get_default_settings();

		$test_cases = array(
			array(
				'test_condition_name' => '既定は末尾に lodging が加わり features を持つ',
				'third_party'         => false,
				'expected_keys'       => array( 'custom', 'salon', 'activity', 'seminar', 'lodging' ),
				'expected_features'   => array( 'lodging' ),
			),
			array(
				'test_condition_name' => '第三者プリセットの lodging 機能は残る',
				'third_party'         => true,
				'expected_keys'       => array( 'custom', 'salon', 'activity', 'seminar', 'lodging', 'clinic' ),
				'expected_features'   => array( 'lodging' ),
			),
		);

		foreach ( $test_cases as $case ) {
			remove_all_filters( 'vkbm_industry_presets' );
			if ( $case['third_party'] ) {
				add_filter(
					'vkbm_industry_presets',
					static function ( array $presets ): array {
						$presets['clinic'] = array(
							'label'    => 'クリニック',
							'features' => array( 'lodging', 'unknown' ),
							'fields'   => $presets['custom']['fields'],
						);
						return $presets;
					}
				);
			}
			Industry_Presets::clear_cache();

			$presets = Industry_Presets::get_presets( $defaults );
			$this->assertSame( $case['expected_keys'], array_keys( $presets ), $case['test_condition_name'] . '（一覧）' );
			// 第三者プリセットがあるケースはそれ、無いケースは lodging の features を確認する。
			$feature_holder = $case['third_party'] ? 'clinic' : 'lodging';
			$this->assertSame( $case['expected_features'], $presets[ $feature_holder ]['features'], $case['test_condition_name'] . '（features）' );
			// custom と既存3プリセットは features を持たない（宿泊の欄が増えない）。
			foreach ( array( 'custom', 'salon', 'activity', 'seminar' ) as $existing_key ) {
				$this->assertSame( array(), $presets[ $existing_key ]['features'], $case['test_condition_name'] . '（' . $existing_key . ' は features を持たない）' );
			}
		}
	}

	/**
	 * features の正規化（FEATURES にある値だけ残す・custom は常に空）を検証する（#570）。
	 *
	 * @return void
	 */
	public function test_normalize_filtered_presets_features(): void {
		$defaults = ( new Settings_Repository() )->get_default_settings();

		$test_cases = array(
			array(
				'test_condition_name' => 'フィルターで custom に features を足されても空のまま',
				'preset_key'          => 'custom',
				'features'            => array( 'lodging' ),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '未知の機能名と文字列以外は捨て、lodging だけ残す',
				'preset_key'          => 'salon',
				'features'            => array( 'unknown', 123, 'lodging' ),
				'expected'            => array( 'lodging' ),
			),
			array(
				'test_condition_name' => 'features が配列でなければ空',
				'preset_key'          => 'salon',
				'features'            => 'lodging',
				'expected'            => array(),
			),
			array(
				'test_condition_name' => 'features を持たない既存プリセットは空',
				'preset_key'          => 'seminar',
				'features'            => null,
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			remove_all_filters( 'vkbm_industry_presets' );
			add_filter(
				'vkbm_industry_presets',
				static function ( array $presets ) use ( $case ): array {
					if ( null === $case['features'] ) {
						unset( $presets[ $case['preset_key'] ]['features'] );
					} else {
						$presets[ $case['preset_key'] ]['features'] = $case['features'];
					}
					return $presets;
				}
			);
			Industry_Presets::clear_cache();

			$presets = Industry_Presets::get_presets( $defaults );
			$this->assertSame( $case['expected'], $presets[ $case['preset_key'] ]['features'], $case['test_condition_name'] );
		}
	}

	/**
	 * supports() が、無料版・custom・既存3プリセットでは false、宿泊だけ true を返すことを検証する（#570）。
	 *
	 * @return void
	 */
	public function test_supports(): void {
		// @pro-gate-ignore supports: markTestSkipped ではなく、期待値を無料版（常に false）と Pro 版（expected_pro）で分岐させて両方を検証しているため対象外とする。
		$is_free = Pro_Upsell::is_free_edition();

		$test_cases = array(
			array(
				'test_condition_name' => 'custom は lodging を持たない',
				'stored_preset'       => 'custom',
				'feature'             => 'lodging',
				'expected_pro'        => false,
			),
			array(
				'test_condition_name' => 'salon は lodging を持たない',
				'stored_preset'       => 'salon',
				'feature'             => 'lodging',
				'expected_pro'        => false,
			),
			array(
				'test_condition_name' => 'activity は lodging を持たない',
				'stored_preset'       => 'activity',
				'feature'             => 'lodging',
				'expected_pro'        => false,
			),
			array(
				'test_condition_name' => 'seminar は lodging を持たない',
				'stored_preset'       => 'seminar',
				'feature'             => 'lodging',
				'expected_pro'        => false,
			),
			array(
				'test_condition_name' => 'lodging を保存済みなら true（Pro 版）',
				'stored_preset'       => 'lodging',
				'feature'             => 'lodging',
				'expected_pro'        => true,
			),
			array(
				'test_condition_name' => 'FEATURES に無い機能名は常に false',
				'stored_preset'       => 'lodging',
				'feature'             => 'unknown',
				'expected_pro'        => false,
			),
		);

		foreach ( $test_cases as $case ) {
			Industry_Presets::clear_cache();
			update_option(
				Settings_Repository::OPTION_KEY,
				array_merge( ( new Settings_Repository() )->get_default_settings(), array( 'industry_preset' => $case['stored_preset'] ) )
			);

			$expected = $is_free ? false : $case['expected_pro'];
			$this->assertSame( $expected, Industry_Presets::supports( $case['feature'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * 存在しない業種プリセットのキーを送信しても custom として保存されることを検証する。
	 *
	 * @return void
	 */
	public function test_save_settings_falls_back_to_custom_when_preset_unknown(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '業種プリセットの保存は Pro 版で検証する。' );
		}

		$service  = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$defaults = $service->get_default_settings();

		$result = $service->save_settings( array_merge( $defaults, array( 'industry_preset' => 'unknown_preset' ) ) );
		$this->assertTrue( $result, '保存が成功すること' );

		$stored = get_option( Settings_Repository::OPTION_KEY, array() );
		$this->assertSame( 'custom', $stored['industry_preset'], '存在しないキーは custom で保存されること' );
		$this->assertFalse( Industry_Presets::supports( 'lodging' ), 'supports( lodging ) は false' );
	}

	/**
	 * 宿泊を保存済みでも、プリセット一覧から lodging が外されていれば（フィルター）、
	 * custom として扱われ、宿泊の機能が効かないことを検証する。
	 *
	 * 宿泊から別の業種へ切り替えた後・拡張側で宿泊を外した場合に、保存値が残っていても
	 * 画面・保存データが従来どおりになる経路（#570）。
	 *
	 * @return void
	 */
	public function test_saved_lodging_is_ignored_when_preset_removed_by_filter(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '業種プリセットの判定は Pro 版で検証する。' );
		}

		$defaults = ( new Settings_Repository() )->get_default_settings();
		update_option( Settings_Repository::OPTION_KEY, array_merge( $defaults, array( 'industry_preset' => 'lodging' ) ) );
		Industry_Presets::clear_cache();
		$this->assertTrue( Industry_Presets::supports( 'lodging' ), '前提: フィルター前は宿泊が効く' );

		add_filter(
			'vkbm_industry_presets',
			static function ( array $presets ): array {
				unset( $presets['lodging'] );
				return $presets;
			}
		);
		Industry_Presets::clear_cache();

		$this->assertSame( 'custom', Industry_Presets::get_current_preset( array( 'industry_preset' => 'lodging' ), $defaults ), '一覧に無い保存値は custom へ倒れる' );
		$this->assertFalse( Industry_Presets::supports( 'lodging' ), '保存値が lodging でも supports( lodging ) は false' );
	}

	/**
	 * 宿泊プリセットが保存でき、固定項目と呼び方の初期値が適用されることを検証する（#570）。
	 *
	 * @return void
	 */
	public function test_save_settings_applies_lodging_preset_values(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '業種プリセットの保存は Pro 版で検証する。' );
		}

		Industry_Presets::clear_cache();

		$service  = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$defaults = $service->get_default_settings();
		$service->save_settings( array_merge( $defaults, array( 'industry_preset' => 'custom' ) ) );

		// display:true の項目は JS が新プリセット値へ書き換え済み、display:false の項目は送信されない状態を模す。
		$lodging_fields = Industry_Presets::get_presets( $defaults )['lodging']['fields'];
		$raw            = array_merge( $defaults, array( 'industry_preset' => 'lodging' ) );
		foreach ( $lodging_fields as $field_key => $field ) {
			if ( $field['display'] ) {
				$raw[ $field_key ] = $field['value'];
			} else {
				unset( $raw[ $field_key ] );
			}
		}
		$this->assertTrue( $service->save_settings( $raw ), '保存が成功すること' );

		$settings = $service->get_settings();
		$expected = array(
			'industry_preset'         => 'lodging',
			'staff_enabled'           => true,
			'slot_capacity_enabled'   => true,
			'resource_menu_icon'      => 'dashicons-building',
			'resource_label_singular' => __( 'Room', 'vk-booking-manager' ),
			'resource_label_plural'   => __( 'Rooms', 'vk-booking-manager' ),
			'resource_label_menu'     => __( 'Eligible rooms', 'vk-booking-manager' ),
			'no_nomination_label'     => __( 'Any available room', 'vk-booking-manager' ),
			'nomination_fee_label'    => __( 'Nomination fee', 'vk-booking-manager' ),
			'guests_count_label'      => __( 'Number of guests staying', 'vk-booking-manager' ),
		);
		foreach ( $expected as $field_key => $value ) {
			$this->assertSame( $value, $settings[ $field_key ], '宿泊プリセット適用後の ' . $field_key );
		}
		$this->assertTrue( Industry_Presets::supports( 'lodging' ), '宿泊を保存済みなら supports( lodging ) は true' );
	}

	/**
	 * 宿泊で非表示にした指名料ラベルは、宿泊を選んでも保存されず、宿泊以外へ戻すと標準の「指名料」になることを検証する（#736）。
	 *
	 * 宿泊は指名料ラベルの欄を出さない。「Room surcharge」を保存してしまうと、戻した利用者が直せないため。
	 * 「旧: 宿泊で Room surcharge が保存済み」は、未リリースの宿泊を先に試したサイトを想定する。
	 *
	 * @return void
	 */
	public function test_nomination_fee_label_returns_to_default_after_leaving_lodging(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '業種プリセットの保存は Pro 版で検証する。' );
		}

		$test_cases = array(
			array(
				'test_condition_name' => '宿泊を保存してから custom へ戻す => 標準の指名料',
				'legacy_saved_label'  => '',
				'target_preset'       => 'custom',
			),
			array(
				'test_condition_name' => '宿泊を保存してから salon へ切り替える => 標準の指名料',
				'legacy_saved_label'  => '',
				'target_preset'       => 'salon',
			),
			array(
				'test_condition_name' => '旧: 宿泊で Room surcharge が保存済み => custom へ戻すと標準の指名料',
				'legacy_saved_label'  => 'Room surcharge',
				'target_preset'       => 'custom',
			),
			array(
				'test_condition_name' => '旧: 宿泊で Room surcharge が保存済み => salon へ切り替えると標準の指名料',
				'legacy_saved_label'  => 'Room surcharge',
				'target_preset'       => 'salon',
			),
		);

		foreach ( $test_cases as $case ) {
			Industry_Presets::clear_cache();
			$service  = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
			$defaults = $service->get_default_settings();
			$default  = (string) $defaults['nomination_fee_label'];

			$service->save_settings( array_merge( $defaults, array( 'industry_preset' => 'custom' ) ) );

			// 宿泊を選んで保存する。指名料ラベルの欄は非表示（無効）のため送信されない。
			$lodging_input = array_merge( $defaults, array( 'industry_preset' => 'lodging' ) );
			unset( $lodging_input['nomination_fee_label'] );
			$service->save_settings( $lodging_input );
			Industry_Presets::clear_cache();
			$this->assertSame( $default, $service->get_settings()['nomination_fee_label'], $case['test_condition_name'] . ' / 宿泊中も標準の指名料' );

			if ( '' !== $case['legacy_saved_label'] ) {
				// 旧実装で保存された値を、保存済みの生の値として直接書き込む。
				$stored                         = get_option( Settings_Repository::OPTION_KEY );
				$stored['nomination_fee_label'] = $case['legacy_saved_label'];
				update_option( Settings_Repository::OPTION_KEY, $stored );
				Industry_Presets::clear_cache();
				$this->assertSame( $default, $service->get_settings()['nomination_fee_label'], $case['test_condition_name'] . ' / 宿泊中は旧値があっても標準の指名料' );
			}

			// 宿泊以外へ切り替えて保存する（欄は表示・送信される。送信値は画面に出ていた現在値）。
			$back_input = array_merge(
				$service->get_settings(),
				array( 'industry_preset' => $case['target_preset'] )
			);
			$service->save_settings( $back_input );
			Industry_Presets::clear_cache();

			$this->assertSame( $default, $service->get_settings()['nomination_fee_label'], $case['test_condition_name'] );
			$this->assertSame( $default, vkbm_get_nomination_fee_label(), $case['test_condition_name'] . ' / 表示ラベル' );
		}
	}

	/**
	 * 既存3プリセットへの切り替えで、guests_count_label の結果が従来と変わらないことを検証する（#570）。
	 *
	 * @return void
	 */
	public function test_apply_transition_keeps_guests_count_label_for_existing_presets(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '業種プリセットの切り替えは Pro 版で検証する。' );
		}

		$defaults   = ( new Settings_Repository() )->get_default_settings();
		$test_cases = array();
		foreach ( array( 'salon', 'activity', 'seminar' ) as $preset_key ) {
			$test_cases[] = array(
				'test_condition_name' => 'custom の未入力から ' . $preset_key . ' へ切り替えても数量の見出しは未入力のまま',
				'preset_key'          => $preset_key,
				'current_label'       => '',
				'expected'            => '',
			);
			$test_cases[] = array(
				'test_condition_name' => 'custom の編集値から ' . $preset_key . ' へ切り替えても数量の見出しは編集値のまま',
				'preset_key'          => $preset_key,
				'current_label'       => '台数',
				'expected'            => '台数',
			);
		}

		foreach ( $test_cases as $case ) {
			$current   = array_merge(
				$defaults,
				array(
					'industry_preset'    => 'custom',
					'guests_count_label' => $case['current_label'],
				)
			);
			$input     = array(
				'industry_preset'    => $case['preset_key'],
				'guests_count_label' => $case['current_label'],
			);
			$sanitized = array_merge( $current, $input );

			$result = Industry_Presets::apply_transition( $current, $sanitized, $input, $defaults );
			$this->assertSame( $case['expected'], $result['guests_count_label'], $case['test_condition_name'] );

			$presets = Industry_Presets::get_presets( $defaults );
			$this->assertTrue( $presets[ $case['preset_key'] ]['fields']['guests_count_label']['display'], $case['preset_key'] . ' の数量の見出しは編集可' );
			$this->assertSame( $defaults['guests_count_label'], $presets[ $case['preset_key'] ]['fields']['guests_count_label']['value'], $case['preset_key'] . ' の数量の見出しは既定値' );
		}
	}

	/**
	 * 基本設定画面の数量の見出し行が業種プリセットの対象行として描画されることを検証する（#570）。
	 *
	 * @return void
	 */
	public function test_provider_settings_page_marks_guests_count_label_row(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '業種プリセットの対象行の属性は Pro 版で検証する。' );
		}

		$admin_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user_id );

		$service = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$page    = new Provider_Settings_Page( $service );
		ob_start();
		$page->render_page();
		$output = (string) ob_get_clean();
		wp_set_current_user( 0 );

		$this->assertStringContainsString(
			'data-industry-field="guests_count_label"',
			$this->get_industry_row_tag( $output, 'guests_count_label' ),
			'数量の見出し行に data-industry-field が付くこと'
		);
	}

	/**
	 * 出力から、指定した業種プリセット対象行の開始タグを取り出す。
	 *
	 * @param string $output    描画結果。
	 * @param string $field_key 設定キー。
	 * @return string 開始タグ（見つからない場合は空文字列を返さず失敗させる）。
	 */
	private function get_industry_row_tag( string $output, string $field_key ): string {
		$matched = preg_match(
			'/<tr[^>]*data-industry-field="' . preg_quote( $field_key, '/' ) . '"[^>]*>/',
			$output,
			$matches
		);
		$this->assertSame( 1, $matched, $field_key . ' の行が描画されていること' );

		return $matches[0];
	}
}
