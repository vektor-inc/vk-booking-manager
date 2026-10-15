<?php
/**
 * #691: サービスメニュー編集の sanitize 系メソッドが、unslash 済みの値から「\」を消さないことのテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use ReflectionClass;
use VKBookingManager\Admin\Service_Menu_Editor;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use WP_UnitTestCase;

/**
 * Service_Menu_Editor の sanitize_text_value() / sanitize_textarea_value() のテスト。
 *
 * @group admin
 */
class Service_Menu_Editor_Unslash_Test extends WP_UnitTestCase {

	/**
	 * テスト前の $_POST。
	 *
	 * @var array<string, mixed>
	 */
	private $original_post = array();

	/**
	 * $_POST を退避する。
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->original_post = $_POST;
	}

	/**
	 * $_POST を復元する。
	 */
	protected function tearDown(): void {
		$_POST = $this->original_post;
		parent::tearDown();
	}

	/**
	 * Invoke a private sanitize method via Reflection.
	 *
	 * @param string               $method Method name.
	 * @param array<string, mixed> $data   Submitted data (already unslashed).
	 * @param string               $key    Array key.
	 * @return string
	 */
	private function call_private( string $method, array $data, string $key ): string {
		$editor     = new Service_Menu_Editor();
		$reflection = new ReflectionClass( $editor );
		$target     = $reflection->getMethod( $method );
		$target->setAccessible( true );
		return $target->invoke( $editor, $data, $key );
	}

	/**
	 * unslash 済みの値に含まれる「\」が、sanitize で消えないこと。
	 */
	public function test_sanitize_text_value_and_sanitize_textarea_value(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'テキスト: 「\」を含む値がそのまま返る',
				'method'              => 'sanitize_text_value',
				'data'                => array( 'catch_copy' => 'C:\\path\\file' ),
				'key'                 => 'catch_copy',
				'expected'            => 'C:\\path\\file',
			),
			array(
				'test_condition_name' => 'テキスト: 「\」の連続（2つ）が2つのまま返る',
				'method'              => 'sanitize_text_value',
				'data'                => array( 'catch_copy' => 'a\\\\b' ),
				'key'                 => 'catch_copy',
				'expected'            => 'a\\\\b',
			),
			array(
				'test_condition_name' => 'テキスト: 「\」の無い値は変わらない',
				'method'              => 'sanitize_text_value',
				'data'                => array( 'catch_copy' => 'カット' ),
				'key'                 => 'catch_copy',
				'expected'            => 'カット',
			),
			array(
				'test_condition_name' => 'テキスト: キーが無ければ空文字',
				'method'              => 'sanitize_text_value',
				'data'                => array(),
				'key'                 => 'catch_copy',
				'expected'            => '',
			),
			array(
				'test_condition_name' => 'テキストエリア: 「\」を含む値がそのまま返る',
				'method'              => 'sanitize_textarea_value',
				'data'                => array( 'internal_memo' => "C:\\path\n次の行" ),
				'key'                 => 'internal_memo',
				'expected'            => "C:\\path\n次の行",
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame(
				$case['expected'],
				$this->call_private( $case['method'], $case['data'], $case['key'] ),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * sanitize_price_tiers(): 料金区分の label に含まれる「\」が消えないこと。
	 */
	public function test_sanitize_price_tiers(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '「\」を含む label がそのまま残る',
				'raw'                 => array(
					'label' => array( '大人\\学生' ),
					'price' => array( '5000' ),
				),
				'expected'            => '大人\\学生',
			),
			array(
				'test_condition_name' => '「\」を含まない label は変わらない',
				'raw'                 => array(
					'label' => array( '大人' ),
					'price' => array( '5000' ),
				),
				'expected'            => '大人',
			),
		);

		$editor = new Service_Menu_Editor();
		$method = ( new ReflectionClass( $editor ) )->getMethod( 'sanitize_price_tiers' );
		$method->setAccessible( true );

		foreach ( $test_cases as $case ) {
			$result = $method->invoke( $editor, $case['raw'] );
			$this->assertSame( $case['expected'], $result[0]['label'] ?? null, $case['test_condition_name'] );
		}
	}

	/**
	 * save_post(): WordPress が付けたスラッシュ付きの $_POST から、入力どおりの値がメタに保存されること。
	 */
	public function test_save_post(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '「\」を含むキャッチコピー・社内メモが入力どおり保存される',
				'input'               => 'C:\\path',
				'expected'            => 'C:\\path',
			),
			array(
				'test_condition_name' => '「\」を含まない値は変わらない',
				'input'               => 'カット',
				'expected'            => 'カット',
			),
		);

		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$editor = new Service_Menu_Editor();

		foreach ( $test_cases as $case ) {
			$menu_id = (int) $this->factory()->post->create(
				array(
					'post_type'   => Service_Menu_Post_Type::POST_TYPE,
					'post_status' => 'publish',
				)
			);
			$post    = get_post( $menu_id );

			// WordPress は $_POST にスラッシュ追加済みの値を入れる（wp_magic_quotes）。
			$_POST = array(
				'_vkbm_service_menu_nonce' => wp_create_nonce( 'vkbm_service_menu_meta' ),
				'vkbm_service_menu'        => array(
					'catch_copy'    => wp_slash( $case['input'] ),
					'internal_memo' => wp_slash( $case['input'] ),
				),
			);
			$editor->save_post( $menu_id, $post );

			$this->assertSame( $case['expected'], get_post_meta( $menu_id, '_vkbm_catch_copy', true ), $case['test_condition_name'] . '（catch_copy）' );
			$this->assertSame( $case['expected'], get_post_meta( $menu_id, '_vkbm_internal_memo', true ), $case['test_condition_name'] . '（internal_memo）' );
		}
	}
}
