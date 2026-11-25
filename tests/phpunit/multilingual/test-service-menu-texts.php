<?php
/**
 * プランの言語ごとのタイトル・抜粋・その他条件（#1187）のテスト。
 *
 * - 表示する文言が「表示中の言語の値 → 基本の値」の順で決まり、追加済みの言語の値だけが使われること
 * - 予約画面の REST（vkbm/v1）の応答だけが言語に合わせて変わること
 * - 編集画面のメタボックスの表示・保存（サニタイズ・削除済み言語の値を残すこと）
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Multilingual;

use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Multilingual\Multilingual_Labels;
use VKBookingManager\Multilingual\Multilingual_Settings_Panel;
use VKBookingManager\Multilingual\Service_Menu_Multilingual_Meta_Box;
use VKBookingManager\Multilingual\Service_Menu_Texts;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * プランの言語ごとの文言のテストクラス。
 *
 * @group multilingual
 */
class Service_Menu_Texts_Test extends WP_UnitTestCase {
	/**
	 * 表示中のロケールを差し替えるフィルターの戻り値。空文字なら差し替えない。
	 *
	 * @var string
	 */
	private string $current_locale = '';

	/**
	 * テスト用のプランの投稿 ID。
	 *
	 * @var int
	 */
	private int $menu_id = 0;

	/**
	 * 各テスト前に、言語の設定を消してテスト用のプランを作る。
	 */
	public function set_up(): void {
		parent::set_up();

		delete_option( Multilingual_Labels::OPTION_KEY );
		$this->current_locale = '';
		add_filter( 'locale', array( $this, 'filter_locale' ) );

		$this->menu_id = self::factory()->post->create(
			array(
				'post_type'    => Service_Menu_Post_Type::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'カット',
				'post_excerpt' => 'カットの説明',
				'post_content' => '本文',
			)
		);
		update_post_meta( $this->menu_id, '_vkbm_other_conditions', '雨天中止' );
	}

	/**
	 * 各テスト後に設定とフィルターを片付ける。
	 */
	public function tear_down(): void {
		remove_filter( 'locale', array( $this, 'filter_locale' ) );
		remove_all_filters( Service_Menu_Texts::FILTER_HOOK );
		delete_option( Multilingual_Labels::OPTION_KEY );
		unset( $_POST[ Service_Menu_Texts::INPUT_NAME ], $_POST['_vkbm_service_menu_multilingual_nonce'] );

		$mo_path = dirname( __DIR__, 3 ) . '/languages/vk-booking-manager-ja.mo';
		if ( ! is_textdomain_loaded( 'vk-booking-manager' ) && file_exists( $mo_path ) ) {
			load_textdomain( 'vk-booking-manager', $mo_path );
		}

		parent::tear_down();
	}

	/**
	 * テスト用に、表示中のロケール（get_locale() / determine_locale() の元）を差し替える。
	 *
	 * @param string $locale 元のロケール。
	 * @return string 差し替えたロケール。
	 */
	public function filter_locale( $locale ) {
		return '' !== $this->current_locale ? $this->current_locale : $locale;
	}

	/**
	 * 条件（追加済みの言語・保存済みの言語ごとの値・表示中のロケール・フィルター）を整える。
	 *
	 * @param array<string, mixed> $conditions 条件。
	 * @return void
	 */
	private function apply_conditions( array $conditions ): void {
		delete_option( Multilingual_Labels::OPTION_KEY );
		delete_post_meta( $this->menu_id, Service_Menu_Texts::META_KEY );
		remove_all_filters( Service_Menu_Texts::FILTER_HOOK );

		if ( isset( $conditions['locales'] ) ) {
			update_option( Multilingual_Labels::OPTION_KEY, array_fill_keys( $conditions['locales'], array() ) );
		}
		if ( isset( $conditions['texts'] ) ) {
			update_post_meta( $this->menu_id, Service_Menu_Texts::META_KEY, $conditions['texts'] );
		}
		if ( isset( $conditions['filter'] ) ) {
			add_filter( Service_Menu_Texts::FILTER_HOOK, $conditions['filter'], 10, 4 );
		}
		$this->current_locale = (string) ( $conditions['locale'] ?? '' );
	}

	/**
	 * 表示する文言が「表示中の言語の値 → 基本の値」の順で決まり、フィルターに通ること。
	 */
	public function test_resolve(): void {
		$en_texts = array(
			'en_US' => array(
				'title'            => 'Cut',
				'other_conditions' => 'Cancelled if it rains',
			),
		);

		$test_cases = array(
			array(
				'test_condition_name' => '英語を追加し、英語のタイトルがあり、英語のページ => 英語のタイトル',
				'conditions'          => array(
					'locales' => array( 'en_US' ),
					'texts'   => $en_texts,
					'locale'  => 'en_US',
				),
				'key'                 => 'title',
				'base'                => 'カット',
				'expected'            => 'Cut',
			),
			array(
				'test_condition_name' => '英語を追加し、英語のその他条件があり、英語のページ => 英語のその他条件',
				'conditions'          => array(
					'locales' => array( 'en_US' ),
					'texts'   => $en_texts,
					'locale'  => 'en_US',
				),
				'key'                 => 'other_conditions',
				'base'                => '雨天中止',
				'expected'            => 'Cancelled if it rains',
			),
			array(
				'test_condition_name' => '英語の抜粋が空欄で、英語のページ => 基本の値',
				'conditions'          => array(
					'locales' => array( 'en_US' ),
					'texts'   => $en_texts,
					'locale'  => 'en_US',
				),
				'key'                 => 'excerpt',
				'base'                => 'カットの説明',
				'expected'            => 'カットの説明',
			),
			array(
				'test_condition_name' => '英語のタイトルがあり、日本語のページ => 基本の値',
				'conditions'          => array(
					'locales' => array( 'en_US' ),
					'texts'   => $en_texts,
					'locale'  => 'ja',
				),
				'key'                 => 'title',
				'base'                => 'カット',
				'expected'            => 'カット',
			),
			array(
				'test_condition_name' => '言語を追加していないサイトで、英語のページ => 基本の値',
				'conditions'          => array(
					'locale' => 'en_US',
				),
				'key'                 => 'title',
				'base'                => 'カット',
				'expected'            => 'カット',
			),
			array(
				'test_condition_name' => 'タブで英語を削除した（英語の値が残っている）サイトで、英語のページ => 基本の値',
				'conditions'          => array(
					'locales' => array( 'de_DE' ),
					'texts'   => $en_texts,
					'locale'  => 'en_US',
				),
				'key'                 => 'title',
				'base'                => 'カット',
				'expected'            => 'カット',
			),
			array(
				'test_condition_name' => 'en_US の値があり、en_GB のページ => 基本の値（ロケールは完全一致で探す）',
				'conditions'          => array(
					'locales' => array( 'en_US', 'en_GB' ),
					'texts'   => $en_texts,
					'locale'  => 'en_GB',
				),
				'key'                 => 'title',
				'base'                => 'カット',
				'expected'            => 'カット',
			),
			array(
				'test_condition_name' => 'フィルターでキー・ロケール・投稿 ID を受け取って差し替える => 差し替えた値',
				'conditions'          => array(
					'locales' => array( 'en_US' ),
					'texts'   => $en_texts,
					'locale'  => 'en_US',
					'filter'  => static function ( $text, $key, $locale, $post_id ) {
						return $text . '|' . $key . '|' . $locale . '|' . ( $post_id > 0 ? 'id' : 'no-id' );
					},
				),
				'key'                 => 'title',
				'base'                => 'カット',
				'expected'            => 'Cut|title|en_US|id',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->apply_conditions( $case['conditions'] );

			$actual = Service_Menu_Texts::resolve( $this->menu_id, $case['key'], $case['base'] );

			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * 保存済みの値が、ロケールの形式に合い、文字列で空でない値だけに整えられること。
	 */
	public function test_get_stored(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '空欄・形式に合わないロケール・対象外のキーを含む => 整えた値',
				'stored'              => array(
					'en_US'   => array(
						'title'   => ' Cut ',
						'excerpt' => '',
						'unknown' => 'x',
					),
					'invalid' => array( 'title' => 'x' ),
					'de_DE'   => array( 'excerpt' => array( 'x' ) ),
				),
				'expected'            => array( 'en_US' => array( 'title' => 'Cut' ) ),
			),
			array(
				'test_condition_name' => '複数の言語の値 => そのまま',
				'stored'              => array(
					'en_US' => array( 'title' => 'Cut' ),
					'de_DE' => array( 'other_conditions' => 'Regen' ),
				),
				'expected'            => array(
					'en_US' => array( 'title' => 'Cut' ),
					'de_DE' => array( 'other_conditions' => 'Regen' ),
				),
			),
			array(
				'test_condition_name' => '配列でない値 => 空',
				'stored'              => 'broken',
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			update_post_meta( $this->menu_id, Service_Menu_Texts::META_KEY, $case['stored'] );

			$this->assertSame( $case['expected'], Service_Menu_Texts::get_stored( $this->menu_id ), $case['test_condition_name'] );
		}
	}

	/**
	 * タイトル・抜粋を置き換えた投稿が、言語の値があるときだけ複製されて返ること。
	 */
	public function test_localize_post(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '英語のタイトル・抜粋があり、英語のページ => 両方を置き換えた複製',
				'conditions'          => array(
					'locales' => array( 'en_US' ),
					'texts'   => array(
						'en_US' => array(
							'title'   => 'Cut',
							'excerpt' => 'About the cut',
						),
					),
					'locale'  => 'en_US',
				),
				'expected'            => array(
					'is_same' => false,
					'title'   => 'Cut',
					'excerpt' => 'About the cut',
				),
			),
			array(
				'test_condition_name' => '英語のタイトルだけがあり、英語のページ => タイトルだけを置き換えた複製',
				'conditions'          => array(
					'locales' => array( 'en_US' ),
					'texts'   => array( 'en_US' => array( 'title' => 'Cut' ) ),
					'locale'  => 'en_US',
				),
				'expected'            => array(
					'is_same' => false,
					'title'   => 'Cut',
					'excerpt' => 'カットの説明',
				),
			),
			array(
				'test_condition_name' => '日本語のページ => 渡した投稿そのもの',
				'conditions'          => array(
					'locales' => array( 'en_US' ),
					'texts'   => array( 'en_US' => array( 'title' => 'Cut' ) ),
					'locale'  => 'ja',
				),
				'expected'            => array(
					'is_same' => true,
					'title'   => 'カット',
					'excerpt' => 'カットの説明',
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->apply_conditions( $case['conditions'] );
			$post = get_post( $this->menu_id );

			$actual = Service_Menu_Texts::localize_post( $post );

			$this->assertSame( $case['expected']['is_same'], $actual === $post, $case['test_condition_name'] . '（同じ投稿か）' );
			$this->assertSame( $case['expected']['title'], $actual->post_title, $case['test_condition_name'] . '（タイトル）' );
			$this->assertSame( $case['expected']['excerpt'], $actual->post_excerpt, $case['test_condition_name'] . '（抜粋）' );
			// キャッシュ上の投稿は書き換えない。
			$this->assertSame( 'カット', get_post( $this->menu_id )->post_title, $case['test_condition_name'] . '（元の投稿）' );
		}
	}

	/**
	 * 表示するタイトルが、get_the_title() と同じ整形（非公開の「非公開:」など）で返ること。
	 */
	public function test_get_title(): void {
		$private_id = self::factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'private',
				'post_title'  => '非公開プラン',
			)
		);
		update_post_meta( $private_id, Service_Menu_Texts::META_KEY, array( 'en_US' => array( 'title' => 'Private plan' ) ) );

		$test_cases = array(
			array(
				'test_condition_name' => '英語のタイトルがあり、英語のページ（投稿 ID で渡す） => 英語のタイトル',
				'conditions'          => array(
					'locales' => array( 'en_US' ),
					'texts'   => array( 'en_US' => array( 'title' => 'Cut' ) ),
					'locale'  => 'en_US',
				),
				'post'                => $this->menu_id,
				'expected'            => 'Cut',
			),
			array(
				'test_condition_name' => '言語を追加していないサイト => 基本のタイトル',
				'conditions'          => array(
					'locale' => 'en_US',
				),
				'post'                => $this->menu_id,
				'expected'            => 'カット',
			),
			array(
				'test_condition_name' => '非公開のプランで、英語のページ => 英語のタイトルに「非公開:」の書式が付く',
				'conditions'          => array(
					'locales' => array( 'en_US' ),
					'locale'  => 'en_US',
				),
				'post'                => $private_id,
				'expected'            => sprintf( __( 'Private: %s' ), 'Private plan' ), // phpcs:ignore WordPress.WP.I18n.MissingArgDomain, WordPress.WP.I18n.MissingTranslatorsComment -- WordPress 本体の文言と比べる.
			),
			array(
				'test_condition_name' => '存在しない投稿 ID => 空文字',
				'conditions'          => array(),
				'post'                => 999999,
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->apply_conditions( $case['conditions'] );

			$this->assertSame( $case['expected'], Service_Menu_Texts::get_title( $case['post'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * 表示する「その他条件」が、表示中の言語の値 → 基本の値の順で返ること。
	 */
	public function test_get_other_conditions(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '英語のその他条件があり、英語のページ => 英語の値',
				'conditions'          => array(
					'locales' => array( 'en_US' ),
					'texts'   => array( 'en_US' => array( 'other_conditions' => 'Cancelled if it rains' ) ),
					'locale'  => 'en_US',
				),
				'expected'            => 'Cancelled if it rains',
			),
			array(
				'test_condition_name' => '英語のその他条件が空欄で、英語のページ => 基本の値',
				'conditions'          => array(
					'locales' => array( 'en_US' ),
					'texts'   => array( 'en_US' => array( 'title' => 'Cut' ) ),
					'locale'  => 'en_US',
				),
				'expected'            => '雨天中止',
			),
			array(
				'test_condition_name' => '言語を追加していないサイト => 基本の値',
				'conditions'          => array(),
				'expected'            => '雨天中止',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->apply_conditions( $case['conditions'] );

			$this->assertSame( $case['expected'], Service_Menu_Texts::get_other_conditions( $this->menu_id ), $case['test_condition_name'] );
		}
	}

	/**
	 * 予約画面の REST（vkbm/v1）の応答だけ、タイトル・抜粋が表示中の言語の値になること。
	 */
	public function test_localize_rest_response(): void {
		$texts = array(
			'en_US' => array(
				'title'   => 'Cut',
				'excerpt' => 'About the cut',
			),
		);

		$test_cases = array(
			array(
				'test_condition_name' => 'vkbm/v1 で英語のページ => 英語のタイトル・抜粋',
				'conditions'          => array(
					'locales' => array( 'en_US' ),
					'texts'   => $texts,
					'locale'  => 'en_US',
				),
				'route'               => '/vkbm/v1/service-menus/' . $this->menu_id,
				'expected'            => array(
					'title'   => 'Cut',
					'excerpt' => "<p>About the cut</p>\n",
				),
			),
			array(
				'test_condition_name' => 'wp/v2（管理画面などが読む REST）で英語 => 変えない',
				'conditions'          => array(
					'locales' => array( 'en_US' ),
					'texts'   => $texts,
					'locale'  => 'en_US',
				),
				'route'               => '/wp/v2/' . Service_Menu_Post_Type::POST_TYPE . '/' . $this->menu_id,
				'expected'            => array(
					'title'   => 'base-title',
					'excerpt' => 'base-excerpt',
				),
			),
			array(
				'test_condition_name' => 'vkbm/v1 で日本語のページ => 変えない',
				'conditions'          => array(
					'locales' => array( 'en_US' ),
					'texts'   => $texts,
					'locale'  => 'ja',
				),
				'route'               => '/vkbm/v1/service-menus/' . $this->menu_id,
				'expected'            => array(
					'title'   => 'base-title',
					'excerpt' => 'base-excerpt',
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->apply_conditions( $case['conditions'] );
			$request  = new WP_REST_Request( 'GET', $case['route'] );
			$response = new WP_REST_Response(
				array(
					'id'      => $this->menu_id,
					'title'   => array( 'rendered' => 'base-title' ),
					'excerpt' => array(
						'rendered'  => 'base-excerpt',
						'protected' => false,
					),
				)
			);

			$data = Service_Menu_Texts::localize_rest_response( $response, get_post( $this->menu_id ), $request )->get_data();

			$this->assertSame( $case['expected']['title'], $data['title']['rendered'], $case['test_condition_name'] . '（タイトル）' );
			$this->assertSame( $case['expected']['excerpt'], $data['excerpt']['rendered'], $case['test_condition_name'] . '（抜粋）' );
		}
	}

	/**
	 * 編集画面から送られた値が、項目ごとのサニタイズを経て保存用の形になること。
	 */
	public function test_sanitize_input(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'タイトルのタグ・その他条件のタグを除き、その他条件の改行は残す',
				'raw'                 => array(
					'en_US' => array(
						'title'            => ' <b>Cut</b> ',
						'other_conditions' => "Line 1\n<script>x</script>Line 2",
					),
				),
				'allow_unfiltered'    => false,
				'expected'            => array(
					'en_US' => array(
						'title'            => 'Cut',
						'other_conditions' => "Line 1\nLine 2",
					),
				),
			),
			array(
				'test_condition_name' => 'HTML を自由に書けない利用者の抜粋 => script を除き、許可されたタグは残す',
				'raw'                 => array( 'en_US' => array( 'excerpt' => '<strong>Hi</strong><script>alert(1)</script>' ) ),
				'allow_unfiltered'    => false,
				'expected'            => array( 'en_US' => array( 'excerpt' => '<strong>Hi</strong>alert(1)' ) ),
			),
			array(
				'test_condition_name' => 'HTML を自由に書ける利用者の抜粋 => そのまま',
				'raw'                 => array( 'en_US' => array( 'excerpt' => '<strong>Hi</strong><script>alert(1)</script>' ) ),
				'allow_unfiltered'    => true,
				'expected'            => array( 'en_US' => array( 'excerpt' => '<strong>Hi</strong><script>alert(1)</script>' ) ),
			),
			array(
				'test_condition_name' => '空欄だけの言語 => 空の配列（送られた言語として残す）',
				'raw'                 => array(
					'en_US' => array(
						'title'   => '  ',
						'excerpt' => '',
					),
				),
				'allow_unfiltered'    => false,
				'expected'            => array( 'en_US' => array() ),
			),
			array(
				'test_condition_name' => '追加していない言語・形式に合わないロケール・対象外のキー・配列の値 => 捨てる',
				'raw'                 => array(
					'fr_FR'   => array( 'title' => 'Coupe' ),
					'invalid' => array( 'title' => 'x' ),
					'en_US'   => array(
						'unknown' => 'x',
						'title'   => array( 'x' ),
					),
				),
				'allow_unfiltered'    => false,
				'expected'            => array( 'en_US' => array() ),
			),
			array(
				'test_condition_name' => '配列でない送信値 => 空',
				'raw'                 => 'broken',
				'allow_unfiltered'    => false,
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = Service_Menu_Texts::sanitize_input( $case['raw'], array( 'en_US', 'de_DE' ), $case['allow_unfiltered'] );

			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * 送られた言語だけが置き換わり、送られなかった言語の値は残ること。
	 */
	public function test_merge(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '英語を送り、ドイツ語は送らない => 英語を置き換え、ドイツ語は残す',
				'stored'              => array(
					'en_US' => array(
						'title'   => 'Old',
						'excerpt' => 'Old excerpt',
					),
					'de_DE' => array( 'title' => 'Schnitt' ),
				),
				'submitted'           => array( 'en_US' => array( 'title' => 'Cut' ) ),
				'expected'            => array(
					'de_DE' => array( 'title' => 'Schnitt' ),
					'en_US' => array( 'title' => 'Cut' ),
				),
			),
			array(
				'test_condition_name' => '英語を全部空欄で送る => 英語の値を消す',
				'stored'              => array( 'en_US' => array( 'title' => 'Old' ) ),
				'submitted'           => array( 'en_US' => array() ),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '保存済みの値が無く、何も送らない => 空',
				'stored'              => array(),
				'submitted'           => array(),
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Service_Menu_Texts::merge( $case['stored'], $case['submitted'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * 値があれば post meta に保存し（「\」を残す）、値が無ければ post meta を消すこと。
	 */
	public function test_save(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '「\」を含む値 => そのまま保存',
				'texts'               => array( 'en_US' => array( 'title' => 'C:\\Cut' ) ),
				'expected'            => array( 'en_US' => array( 'title' => 'C:\\Cut' ) ),
			),
			array(
				'test_condition_name' => '値が無い => post meta を消す',
				'texts'               => array(),
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			update_post_meta( $this->menu_id, Service_Menu_Texts::META_KEY, array( 'en_US' => array( 'title' => 'Old' ) ) );

			Service_Menu_Texts::save( $this->menu_id, $case['texts'] );

			$this->assertSame( $case['expected'], get_post_meta( $this->menu_id, Service_Menu_Texts::META_KEY, true ), $case['test_condition_name'] );
		}
	}

	/**
	 * メタボックスの保存が、nonce・権限を確かめ、送られた言語の値だけを置き換えること。
	 */
	public function test_save_post(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $admin_id )->add_cap( Capabilities::MANAGE_SERVICE_MENUS );
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$meta_box      = new Service_Menu_Multilingual_Meta_Box();

		$test_cases = array(
			array(
				'test_condition_name' => '編集できる利用者が英語を送る => 英語を保存し、削除済みのドイツ語の値は残す',
				'user_id'             => $admin_id,
				'with_nonce'          => true,
				'post'                => array(
					'en_US' => array(
						'title'            => 'Cut',
						'excerpt'          => '',
						'other_conditions' => 'Cancelled if it rains',
					),
				),
				'expected'            => array(
					'de_DE' => array( 'title' => 'Schnitt' ),
					'en_US' => array(
						'title'            => 'Cut',
						'other_conditions' => 'Cancelled if it rains',
					),
				),
			),
			array(
				'test_condition_name' => 'nonce が無い => 保存しない',
				'user_id'             => $admin_id,
				'with_nonce'          => false,
				'post'                => array( 'en_US' => array( 'title' => 'Cut' ) ),
				'expected'            => array( 'de_DE' => array( 'title' => 'Schnitt' ) ),
			),
			array(
				'test_condition_name' => '編集できない利用者 => 保存しない',
				'user_id'             => $subscriber_id,
				'with_nonce'          => true,
				'post'                => array( 'en_US' => array( 'title' => 'Cut' ) ),
				'expected'            => array( 'de_DE' => array( 'title' => 'Schnitt' ) ),
			),
		);

		foreach ( $test_cases as $case ) {
			// 英語だけを追加済みにし、タブで削除したドイツ語の値が残っている状態にする。
			$this->apply_conditions(
				array(
					'locales' => array( 'en_US' ),
					'texts'   => array( 'de_DE' => array( 'title' => 'Schnitt' ) ),
				)
			);
			wp_set_current_user( $case['user_id'] );
			$_POST[ Service_Menu_Texts::INPUT_NAME ] = $case['post'];
			if ( $case['with_nonce'] ) {
				$_POST['_vkbm_service_menu_multilingual_nonce'] = wp_create_nonce( 'vkbm_service_menu_multilingual' );
			} else {
				unset( $_POST['_vkbm_service_menu_multilingual_nonce'] );
			}

			$meta_box->save_post( $this->menu_id, get_post( $this->menu_id ) );

			$this->assertSame( $case['expected'], get_post_meta( $this->menu_id, Service_Menu_Texts::META_KEY, true ), $case['test_condition_name'] );
		}
	}

	/**
	 * 言語を追加しているときだけメタボックスを登録すること。
	 */
	public function test_add_meta_box(): void {
		global $wp_meta_boxes;

		$test_cases = array(
			array(
				'test_condition_name' => '英語を追加している => 登録する',
				'locales'             => array( 'en_US' ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '言語を追加していない => 登録しない',
				'locales'             => array(),
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$wp_meta_boxes = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- テストごとにメタボックスの登録を空にする.
			$this->apply_conditions( array( 'locales' => $case['locales'] ) );

			( new Service_Menu_Multilingual_Meta_Box() )->add_meta_box();

			$actual = isset( $wp_meta_boxes[ Service_Menu_Post_Type::POST_TYPE ]['normal']['high'][ Service_Menu_Multilingual_Meta_Box::META_BOX_ID ] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * メタボックスに、追加した言語ごとの見出しと入力欄（lang・dir・プレースホルダー）が出ること。
	 */
	public function test_render(): void {
		$this->apply_conditions(
			array(
				'locales' => array( 'en_US', 'de_DE' ),
				'texts'   => array( 'en_US' => array( 'title' => 'Cut' ) ),
			)
		);

		ob_start();
		( new Service_Menu_Multilingual_Meta_Box() )->render( get_post( $this->menu_id ) );
		$html = (string) ob_get_clean();

		$test_cases = array(
			array(
				'test_condition_name' => '英語の見出しに section の aria-labelledby が向く',
				'needle'              => 'aria-labelledby="vkbm-service-menu-multilingual-heading-en_US"',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '英語のタイトル欄に保存済みの値・基本の値のプレースホルダー・lang・dir が付く',
				'needle'              => 'name="vkbm_service_menu_multilingual[en_US][title]" value="Cut" placeholder="カット" lang="en-US" dir="auto"',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'ドイツ語の抜粋欄は 3 行で、基本の抜粋がプレースホルダー',
				'needle'              => 'name="vkbm_service_menu_multilingual[de_DE][excerpt]" rows="3" placeholder="カットの説明" lang="de-DE"',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'ドイツ語のその他条件欄は 4 行で、基本の値がプレースホルダー',
				'needle'              => 'name="vkbm_service_menu_multilingual[de_DE][other_conditions]" rows="4" placeholder="雨天中止"',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '追加していない言語の欄は出さない',
				'needle'              => '[fr_FR]',
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], is_int( strpos( $html, $case['needle'] ) ), $case['test_condition_name'] );
		}
	}

	/**
	 * ロケールが、HTML の lang 属性の値になること。
	 */
	public function test_get_lang_attribute(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'en_US => en-US',
				'locale'              => 'en_US',
				'expected'            => 'en-US',
			),
			array(
				'test_condition_name' => 'de_DE_formal => de-DE（末尾の区別は含めない）',
				'locale'              => 'de_DE_formal',
				'expected'            => 'de-DE',
			),
			array(
				'test_condition_name' => 'ja => ja',
				'locale'              => 'ja',
				'expected'            => 'ja',
			),
			array(
				'test_condition_name' => '形式に合わない値 => 空文字',
				'locale'              => 'X',
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Service_Menu_Multilingual_Meta_Box::get_lang_attribute( $case['locale'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * 「多言語設定」タブに、プランの編集画面で言語ごとに入力できる旨の案内が出ること。
	 */
	public function test_multilingual_settings_panel_render(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $admin_id )->add_cap( Capabilities::MANAGE_SERVICE_MENUS );
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		// 案内の文言を英語の原文で確かめるため、翻訳を外す（tear_down() で読み込み直す）。
		unload_textdomain( 'vk-booking-manager', false );

		$test_cases = array(
			array(
				'test_condition_name' => 'プランを編集できる利用者 => 呼び方を一覧画面へのリンクにする',
				'user_id'             => $admin_id,
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'プランを編集できない利用者 => リンクにしない',
				'user_id'             => $subscriber_id,
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			wp_set_current_user( $case['user_id'] );
			$panel = new Multilingual_Settings_Panel( array(), false, false, admin_url( 'admin.php?page=vkbm-provider-settings&tab=notation' ) );

			ob_start();
			$panel->render();
			$html = (string) ob_get_clean();

			$this->assertStringContainsString( 'for each language on the edit screen of each', $html, $case['test_condition_name'] . '（案内）' );
			$this->assertSame( $case['expected'], is_int( strpos( $html, 'edit.php?post_type=' . Service_Menu_Post_Type::POST_TYPE ) ), $case['test_condition_name'] );
		}
	}
}
