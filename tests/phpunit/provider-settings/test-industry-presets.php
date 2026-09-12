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
	 * この3項目は Industry_Presets::FIELD_KEYS（業種プリセットの対象9項目）に
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
