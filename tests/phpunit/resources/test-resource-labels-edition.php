<?php
/**
 * #609: 呼び方（リソース名称）が、無料版では保存値ではなく既定値で表示されることのテスト。
 *
 * 無料版のテストは無料版のテストスイート（npm run phpunit:free）でだけ動き、
 * Pro 版のテストは Pro 版のテストスイート（npm run phpunit）でだけ動く。
 * 文言を英語のまま検証できるよう、ロケールを en_US に固定する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Resources;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Admin\Provider_Settings_Page;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use VKBookingManager\REST\Provider_Settings_Controller;
use WP_UnitTestCase;
use function add_filter;
use function delete_option;
use function get_option;
use function ob_get_clean;
use function ob_start;
use function remove_all_filters;
use function restore_previous_locale;
use function switch_to_locale;
use function update_option;
use function vkbm_get_resource_label_menu;
use function vkbm_get_resource_label_plural;
use function vkbm_get_resource_label_singular;
use function vkbm_get_resource_tag_empty_message;
use function vkbm_get_saved_resource_label_singular;
use function vkbm_should_use_default_resource_labels;
use function wp_set_current_user;

/**
 * 呼び方の取得関数・REST・設定保存・設定画面の案内を、無料版／Pro 版ごとに検証する。
 *
 * @group resources
 */
class Resource_Labels_Edition_Test extends WP_UnitTestCase {

	/**
	 * ロケールを切り替えたかどうか。
	 *
	 * @var bool
	 */
	private $switched_locale = false;

	/**
	 * テスト前に保存値を消し、ロケールを英語に固定する。
	 */
	public function set_up(): void {
		parent::set_up();

		$this->switched_locale = switch_to_locale( 'en_US' );
		delete_option( Settings_Repository::OPTION_KEY );
	}

	/**
	 * テスト後に保存値とロケールを元へ戻す。
	 */
	public function tear_down(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		if ( $this->switched_locale ) {
			restore_previous_locale();
		}
		$_GET = array();
		wp_set_current_user( 0 );
		remove_all_filters( 'vkbm_provider_settings_response' );

		parent::tear_down();
	}

	/**
	 * 無料版以外のときはテストをスキップする。
	 */
	private function skip_unless_free(): void {
		if ( ! Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '無料版の挙動のテストのため、Pro 版ではスキップする。' );
		}
	}

	/**
	 * 無料版のときはテストをスキップする。
	 */
	private function skip_if_free(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'Pro 版の挙動のテストのため、無料版ではスキップする。' );
		}
	}

	/**
	 * 保存値を差し替える。
	 *
	 * @param array<string,string> $labels 保存する呼び方（singular / plural / menu）。
	 */
	private function save_labels( array $labels ): void {
		$repository = new Settings_Repository();
		$settings   = $repository->get_default_settings();
		foreach ( $labels as $key => $value ) {
			$settings[ 'resource_label_' . $key ] = $value;
		}
		update_option( Settings_Repository::OPTION_KEY, $settings );
	}

	/**
	 * 呼び方の取得関数の返り値を配列で返す。
	 *
	 * @return array<string,string>
	 */
	private function get_label_function_values(): array {
		return array(
			'singular' => vkbm_get_resource_label_singular(),
			'plural'   => vkbm_get_resource_label_plural(),
			'menu'     => vkbm_get_resource_label_menu(),
		);
	}

	/**
	 * 無料版では、保存値が何であっても 3 つの関数が既定値を返す。
	 */
	public function test_get_resource_label_functions_free(): void {
		$this->skip_unless_free();

		$test_cases = array(
			array(
				'test_condition_name' => '3 項目とも「客室」系に保存 => すべて既定値',
				'conditions'          => array(
					'singular' => '客室',
					'plural'   => '客室たち',
					'menu'     => '空き客室',
				),
				'expected'            => array(
					'singular' => 'Staff',
					'plural'   => 'Staff',
					'menu'     => 'Staff available',
				),
			),
			array(
				'test_condition_name' => '単数形だけ変更（複数形は既定のまま）=> 複数形も「Staff」（単数形へ寄せる処理が効かない）',
				'conditions'          => array(
					'singular' => '客室',
				),
				'expected'            => array(
					'singular' => 'Staff',
					'plural'   => 'Staff',
					'menu'     => 'Staff available',
				),
			),
			array(
				'test_condition_name' => '複数形だけ変更 => 複数形も既定値',
				'conditions'          => array(
					'plural' => '客室たち',
				),
				'expected'            => array(
					'singular' => 'Staff',
					'plural'   => 'Staff',
					'menu'     => 'Staff available',
				),
			),
			array(
				'test_condition_name' => '初期値のまま（Pro 版を使ったことがない）=> 今までどおり既定値',
				'conditions'          => array(),
				'expected'            => array(
					'singular' => 'Staff',
					'plural'   => 'Staff',
					'menu'     => 'Staff available',
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->save_labels( $case['conditions'] );
			$this->assertSame( $case['expected'], $this->get_label_function_values(), $case['test_condition_name'] );
		}
	}

	/**
	 * Pro 版では、今までどおり保存値を返す。
	 */
	public function test_get_resource_label_functions_pro(): void {
		$this->skip_if_free();

		$test_cases = array(
			array(
				'test_condition_name' => '3 項目とも保存 => 保存値',
				'conditions'          => array(
					'singular' => '客室',
					'plural'   => '客室たち',
					'menu'     => '空き客室',
				),
				'expected'            => array(
					'singular' => '客室',
					'plural'   => '客室たち',
					'menu'     => '空き客室',
				),
			),
			array(
				'test_condition_name' => '単数形だけ変更 => 複数形は単数形へ寄せる（従来挙動）',
				'conditions'          => array(
					'singular' => '客室',
				),
				'expected'            => array(
					'singular' => '客室',
					'plural'   => '客室',
					'menu'     => 'Staff available',
				),
			),
			array(
				'test_condition_name' => '初期値のまま => 既定値',
				'conditions'          => array(),
				'expected'            => array(
					'singular' => 'Staff',
					'plural'   => 'Staff',
					'menu'     => 'Staff available',
				),
			),
			array(
				'test_condition_name' => '空白だけの保存値 => 既定値へフォールバック',
				'conditions'          => array(
					'singular' => '  ',
					'plural'   => '  ',
					'menu'     => '  ',
				),
				'expected'            => array(
					'singular' => 'Staff',
					'plural'   => 'Staff',
					'menu'     => 'Staff available',
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->save_labels( $case['conditions'] );
			$this->assertSame( $case['expected'], $this->get_label_function_values(), $case['test_condition_name'] );
		}
	}

	/**
	 * 判定結果は、エディションの判定結果と常に同じになる（覚えた結果を返す）。
	 */
	public function test_vkbm_should_use_default_resource_labels(): void {
		$expected = Pro_Upsell::is_free_edition();

		$test_cases = array(
			array(
				'test_condition_name' => '初回の判定 => エディションの判定結果と同じ',
			),
			array(
				'test_condition_name' => '2 回目（覚えた結果）=> 同じ結果',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $expected, vkbm_should_use_default_resource_labels(), $case['test_condition_name'] );
		}
	}

	/**
	 * 無料版・Pro 版とも、保存値そのものを返す関数は既定値へ置き換えない。
	 */
	public function test_vkbm_get_saved_resource_label_singular(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '「客室」を保存 => 無料版でも「客室」',
				'conditions'          => array( 'singular' => '客室' ),
				'expected'            => '客室',
			),
			array(
				'test_condition_name' => '初期値のまま => Staff',
				'conditions'          => array(),
				'expected'            => 'Staff',
			),
			array(
				'test_condition_name' => '空白だけ => Staff',
				'conditions'          => array( 'singular' => ' ' ),
				'expected'            => 'Staff',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->save_labels( $case['conditions'] );
			$this->assertSame( $case['expected'], vkbm_get_saved_resource_label_singular(), $case['test_condition_name'] );
		}
	}

	/**
	 * REST の窓口が返す呼び方を検証する（無料版: 既定値 / Pro 版: 保存値）。
	 */
	public function test_get_settings(): void {
		$is_free = Pro_Upsell::is_free_edition();

		$test_cases = array(
			array(
				'test_condition_name' => '「客室」を保存 => ' . ( $is_free ? '無料版は「Staff」' : 'Pro 版は「客室」' ),
				'conditions'          => array(
					'singular' => '客室',
					'plural'   => '客室たち',
				),
				'expected'            => $is_free
					? array(
						'singular' => 'Staff',
						'plural'   => 'Staff',
					)
					: array(
						'singular' => '客室',
						'plural'   => '客室たち',
					),
			),
			array(
				'test_condition_name' => '単数形だけ「客室」を保存 => ' . ( $is_free ? '無料版は両方「Staff」' : 'Pro 版の複数形は保存値の「Staff」のまま（単数形へ寄せない）' ),
				'conditions'          => array( 'singular' => '客室' ),
				'expected'            => $is_free
					? array(
						'singular' => 'Staff',
						'plural'   => 'Staff',
					)
					: array(
						'singular' => '客室',
						'plural'   => 'Staff',
					),
			),
			array(
				'test_condition_name' => '初期値のまま => 「Staff」',
				'conditions'          => array(),
				'expected'            => array(
					'singular' => 'Staff',
					'plural'   => 'Staff',
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->save_labels( $case['conditions'] );
			$controller = new Provider_Settings_Controller( new Settings_Repository() );
			$data       = $controller->get_settings()->get_data();

			$this->assertSame( $case['expected']['singular'], $data['resource_label_singular'], $case['test_condition_name'] );
			$this->assertSame( $case['expected']['plural'], $data['resource_label_plural'], $case['test_condition_name'] );
		}
	}

	/**
	 * リソースタグ絞り込み0件時の文言が、実効プリセットと複数名から生成される。
	 */
	public function test_vkbm_get_resource_tag_empty_message(): void {
		$repository = new Settings_Repository();
		$defaults   = $repository->get_default_settings();
		$is_free    = Pro_Upsell::is_free_edition();
		$test_cases = array(
			array(
				'test_condition_name' => 'サロン => 人物向けの文言',
				'conditions'          => array(
					'industry_preset'       => 'salon',
					'resource_label_plural' => 'Stylists',
				),
				'expected'            => $is_free ? 'No matching Staff.' : 'There are no Staff matching the selected conditions.',
			),
			array(
				'test_condition_name' => '宿泊 => 無生物向けの文言と実効複数名',
				'conditions'          => array(
					'industry_preset'       => 'lodging',
					'resource_label_plural' => 'Guest rooms',
				),
				'expected'            => $is_free ? 'No matching Staff.' : 'No Guest rooms are available matching the selected conditions.',
			),
			array(
				'test_condition_name' => 'カスタム => 種類に依存しない短い文言',
				'conditions'          => array(
					'industry_preset'       => 'custom',
					'resource_label_plural' => 'Vehicles',
				),
				'expected'            => $is_free ? 'No matching Staff.' : 'No matching Vehicles.',
			),
			array(
				'test_condition_name' => '未知の保存値 => カスタムへ安全に倒す',
				'conditions'          => array(
					'industry_preset'       => 'unknown-industry',
					'resource_label_plural' => 'Booths',
				),
				'expected'            => $is_free ? 'No matching Staff.' : 'No matching Booths.',
			),
		);

		foreach ( $test_cases as $case ) {
			update_option( Settings_Repository::OPTION_KEY, array_merge( $defaults, $case['conditions'] ) );
			$this->assertSame( $case['expected'], vkbm_get_resource_tag_empty_message(), $case['test_condition_name'] );
		}
	}

	/**
	 * REST のレスポンスは、項目名はそのままで、フィルターも今までどおり通る。
	 */
	public function test_get_settings_response_filter(): void {
		$this->save_labels( array( 'singular' => '客室' ) );
		add_filter(
			'vkbm_provider_settings_response',
			static function ( array $response ): array {
				$response['vkbm_test_extra'] = 'added';
				return $response;
			}
		);

		$controller = new Provider_Settings_Controller( new Settings_Repository() );
		$data       = $controller->get_settings()->get_data();

		$this->assertArrayHasKey( 'resource_label_singular', $data, '項目名 resource_label_singular が残る' );
		$this->assertArrayHasKey( 'resource_label_plural', $data, '項目名 resource_label_plural が残る' );
		$this->assertSame( 'added', $data['vkbm_test_extra'], 'フィルターで追加した項目が返る' );
	}

	/**
	 * 無料版で基本設定を保存しても、呼び方の保存値（生の値）は消えない。
	 */
	public function test_save_settings_free(): void {
		$this->skip_unless_free();

		$test_cases = array(
			array(
				'test_condition_name' => '入力欄が送られない保存 => 「客室」のまま',
				'conditions'          => array(
					'singular' => '客室',
					'plural'   => '客室たち',
					'menu'     => '空き客室',
				),
				'expected'            => array(
					'singular' => '客室',
					'plural'   => '客室たち',
					'menu'     => '空き客室',
				),
			),
			array(
				'test_condition_name' => '単数形だけ保存されている => そのまま残る',
				'conditions'          => array( 'singular' => '客室' ),
				'expected'            => array(
					'singular' => '客室',
					'plural'   => 'Staff',
					'menu'     => 'Staff available',
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->save_labels( $case['conditions'] );
			$service = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );

			// 無料版には呼び方の入力欄が無いため、呼び方を含まない入力で保存する。
			$result = $service->save_settings( array( 'provider_name' => 'テスト店舗' ) );
			$this->assertNotWPError( $result, $case['test_condition_name'] );

			$raw = get_option( Settings_Repository::OPTION_KEY );
			$this->assertSame( $case['expected']['singular'], $raw['resource_label_singular'], $case['test_condition_name'] );
			$this->assertSame( $case['expected']['plural'], $raw['resource_label_plural'], $case['test_condition_name'] );
			$this->assertSame( $case['expected']['menu'], $raw['resource_label_menu'], $case['test_condition_name'] );
		}
	}

	/**
	 * 基本設定の画面（システムタブ）を描画して返す。
	 *
	 * @return string 描画結果。
	 */
	private function render_system_tab(): string {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$page = new Provider_Settings_Page(
			new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() ),
			'manage_options',
			''
		);

		$_GET['tab'] = 'system';

		ob_start();
		$page->render_page();

		return (string) ob_get_clean();
	}

	/**
	 * 無料版の基本設定画面に出る、呼び方の案内を検証する。
	 */
	public function test_render_page_free_notice(): void {
		$this->skip_unless_free();

		$test_cases = array(
			array(
				'test_condition_name' => '単数形を「客室」に変更済み => 案内に「客室」が出る',
				'conditions'          => array( 'singular' => '客室' ),
				'expected'            => 'The resource name is set to &quot;客室&quot; in the Pro edition',
			),
			array(
				'test_condition_name' => '単数形・複数形は初期値で、メニュー名だけ変更済み => 呼び方を差し込まない別文言',
				'conditions'          => array( 'menu' => '空き客室' ),
				'expected'            => 'The resource name has been changed in the Pro edition.',
			),
			array(
				'test_condition_name' => '複数形だけ変更済み => 複数形が案内に出る',
				'conditions'          => array( 'plural' => '客室たち' ),
				'expected'            => 'The resource name is set to &quot;客室たち&quot; in the Pro edition',
			),
			array(
				'test_condition_name' => '初期値のまま（Pro 版で変えていない）=> 案内なし',
				'conditions'          => array(),
				'expected'            => null,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->save_labels( $case['conditions'] );
			$html = $this->render_system_tab();

			if ( null === $case['expected'] ) {
				$this->assertStringNotContainsString( 'The resource name ', $html, $case['test_condition_name'] );
			} else {
				$this->assertStringContainsString( $case['expected'], $html, $case['test_condition_name'] );
			}
			$this->assertStringNotContainsString( 'name="vkbm_provider_settings[resource_label_singular]"', $html, $case['test_condition_name'] . '（入力欄は出さない）' );
		}
	}

	/**
	 * Pro 版の基本設定画面は今までどおり入力欄が出て、案内は出ない。
	 */
	public function test_render_page_pro_input(): void {
		$this->skip_if_free();

		$this->save_labels( array( 'singular' => '客室' ) );
		$html = $this->render_system_tab();

		$this->assertStringContainsString( 'name="vkbm_provider_settings[resource_label_singular]"', $html, '入力欄が出る' );
		$this->assertStringNotContainsString( 'The resource name ', $html, '案内は出ない' );
	}
}
