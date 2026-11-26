<?php
/**
 * リソース名の多言語表示テスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Multilingual;

use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\Multilingual\Multilingual_Labels;
use VKBookingManager\Multilingual\Resource_Multilingual_Meta_Box;
use VKBookingManager\Multilingual\Resource_Texts;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\Notifications\Booking_Notification_Service;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * リソース名の多言語表示を検証する。
 *
 * @group multilingual
 */
class Resource_Texts_Test extends WP_UnitTestCase {
	/**
	 * テスト用リソース ID。
	 *
	 * @var int
	 */
	private int $resource_id;
	/**
	 * 表示するロケール。
	 *
	 * @var string
	 */
	private string $current_locale = 'en_US';

	/** テスト投稿と言語を用意する。 */
	public function set_up(): void {
		parent::set_up();
		delete_option( Multilingual_Labels::OPTION_KEY );
		add_filter( 'locale', array( $this, 'filter_locale' ) );
		$this->resource_id = self::factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => '田中',
			)
		);
	}

	/** 送信値とフィルターを片付ける。 */
	public function tear_down(): void {
		unset( $_POST[ Resource_Texts::INPUT_NAME ], $_POST['_vkbm_resource_multilingual_nonce'] );
		remove_all_filters( Resource_Texts::FILTER_HOOK );
		remove_filter( 'locale', array( $this, 'filter_locale' ) );
		delete_option( Multilingual_Labels::OPTION_KEY );
		parent::tear_down();
	}

	/**
	 * テスト中の表示言語を指定する。
	 *
	 * @param string $locale 元のロケール。
	 * @return string 表示言語。
	 */
	public function filter_locale( string $locale ): string {
		return $this->current_locale;
	}

	/** 言語別のタイトル、空欄、未追加言語の戻り値を確認する。 */
	public function test_resolve(): void {
		update_post_meta( $this->resource_id, Resource_Texts::META_KEY, array( 'en_US' => array( 'title' => 'Alice' ) ) );
		$cases = array(
			array(
				'test_condition_name' => '言語を追加していない場合は基本の名前',
				'locales'             => array(),
				'expected'            => '田中',
			),
			array(
				'test_condition_name' => '英語を追加した場合は英語の名前',
				'locales'             => array( 'en_US' => array() ),
				'expected'            => 'Alice',
			),
			array(
				'test_condition_name' => '別の言語だけ追加した場合は基本の名前',
				'locales'             => array( 'de_DE' => array() ),
				'expected'            => '田中',
			),
		);
		foreach ( $cases as $case ) {
			update_option( Multilingual_Labels::OPTION_KEY, $case['locales'] );
			$this->assertSame( $case['expected'], Resource_Texts::resolve( $this->resource_id, '田中' ), $case['test_condition_name'] );
		}
		update_option( Multilingual_Labels::OPTION_KEY, array( 'en_US' => array() ) );
		$this->assertSame( 'Alice', Resource_Texts::get_title( $this->resource_id ) );
		$this->assertSame( '田中', vkbm_get_resource_display_name( $this->resource_id ) );
		$this->assertSame( 'Alice', vkbm_get_resource_display_name( $this->resource_id, true ) );
		$snapshot = ( new Availability_Service() )->get_staff_snapshot( array( $this->resource_id ) );
		$this->assertSame( 'Alice', $snapshot[ $this->resource_id ]['name'], '空き状況の応答も言語別の名前' );
	}

	/** 入力をサニタイズし、送信した言語だけ保存する。 */
	public function test_sanitize_input_and_save(): void {
		update_option(
			Multilingual_Labels::OPTION_KEY,
			array(
				'en_US' => array(),
				'de_DE' => array(),
			)
		);
		$cases = array(
			array(
				'test_condition_name' => '有効な言語の HTML は除去する',
				'raw'                 => array( 'en_US' => array( 'title' => '<b>Alice</b>' ) ),
				'expected'            => array( 'en_US' => array( 'title' => 'Alice' ) ),
			),
			array(
				'test_condition_name' => '未追加言語は捨てる',
				'raw'                 => array( 'fr_FR' => array( 'title' => 'Marie' ) ),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '空欄は言語の削除として扱う',
				'raw'                 => array( 'en_US' => array( 'title' => '' ) ),
				'expected'            => array( 'en_US' => array() ),
			),
		);
		foreach ( $cases as $case ) {
			$this->assertSame( $case['expected'], Resource_Texts::sanitize_input( $case['raw'] ), $case['test_condition_name'] );
		}
		Resource_Texts::save( $this->resource_id, array( 'en_US' => array( 'title' => 'Alice' ) ) );
		Resource_Texts::save( $this->resource_id, array( 'de_DE' => array( 'title' => 'Marie' ) ) );
		$this->assertSame(
			array(
				'en_US' => array( 'title' => 'Alice' ),
				'de_DE' => array( 'title' => 'Marie' ),
			),
			Resource_Texts::get_stored( $this->resource_id )
		);
	}

	/** 予約用 REST だけの名前を切り替える。 */
	public function test_localize_rest_response(): void {
		update_option( Multilingual_Labels::OPTION_KEY, array( 'en_US' => array() ) );
		update_post_meta( $this->resource_id, Resource_Texts::META_KEY, array( 'en_US' => array( 'title' => 'Alice' ) ) );
		$cases = array(
			array(
				'test_condition_name' => '予約用 REST は英語名',
				'route'               => '/vkbm/v1/resources',
				'expected'            => 'Alice',
			),
			array(
				'test_condition_name' => '管理用 REST は基本名',
				'route'               => '/wp/v2/resources',
				'expected'            => '田中',
			),
		);
		foreach ( $cases as $case ) {
			$response = new WP_REST_Response( array( 'title' => array( 'rendered' => '田中' ) ) );
			$request  = new WP_REST_Request( 'GET', $case['route'] );
			$data     = Resource_Texts::localize_rest_response( $response, get_post( $this->resource_id ), $request )->get_data();
			$this->assertSame( $case['expected'], $data['title']['rendered'], $case['test_condition_name'] );
		}
	}


	/** nonce と権限がある送信だけ保存する。 */
	public function test_save_post(): void {
		update_option( Multilingual_Labels::OPTION_KEY, array( 'en_US' => array() ) );
		$box                                 = new Resource_Multilingual_Meta_Box();
		$_POST[ Resource_Texts::INPUT_NAME ] = array( 'en_US' => array( 'title' => '<b>Alice</b>' ) );
		$box->save_post( $this->resource_id, get_post( $this->resource_id ) );
		$this->assertSame( array(), Resource_Texts::get_stored( $this->resource_id ), 'nonce が無ければ保存しない' );
		$_POST['_vkbm_resource_multilingual_nonce'] = wp_create_nonce( 'vkbm_resource_multilingual' );
		$box->save_post( $this->resource_id, get_post( $this->resource_id ) );
		$this->assertSame( array(), Resource_Texts::get_stored( $this->resource_id ), '権限が無ければ保存しない' );
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $admin_id )->add_cap( Capabilities::MANAGE_STAFF );
		wp_set_current_user( $admin_id );
		$_POST['_vkbm_resource_multilingual_nonce'] = wp_create_nonce( 'vkbm_resource_multilingual' );
		$box->save_post( $this->resource_id, get_post( $this->resource_id ) );
		$this->assertSame( array( 'en_US' => array( 'title' => 'Alice' ) ), Resource_Texts::get_stored( $this->resource_id ), 'nonce と権限が揃うと保存する' );
	}


	/** 下書きの言語で送るお客様向け確認メールに言語別の名前を出す。 */
	public function test_guest_verification_uses_resource_title(): void {
		update_option( Multilingual_Labels::OPTION_KEY, array( 'en_US' => array() ) );
		update_post_meta( $this->resource_id, Resource_Texts::META_KEY, array( 'en_US' => array( 'title' => 'Alice' ) ) );
		$messages    = array();
		$mail_filter = static function ( $result, $mail ) use ( &$messages ) {
			$messages[] = $mail['message'];
			return true;
		};
		add_filter( 'pre_wp_mail', $mail_filter, 10, 2 );
		try {
			$service              = new Booking_Notification_Service( new Settings_Repository() );
			$this->current_locale = 'ja';
			$cases                = array(
				array(
					'test_condition_name' => '英語の下書きは英語名',
					'locale'              => 'en_US',
					'expected'            => 'Alice',
				),
				array(
					'test_condition_name' => '日本語の下書きは基本名',
					'locale'              => 'ja',
					'expected'            => '田中',
				),
			);
			foreach ( $cases as $case ) {
				$messages = array();
				$this->assertTrue(
					$service->send_guest_verification(
						array(
							'email' => 'guest@example.com',
							'draft' => array(
								'locale'      => $case['locale'],
								'resource_id' => $this->resource_id,
								'lodging'     => array( 'nights' => 1 ),
							),
						)
					),
					$case['test_condition_name']
				);
				$this->assertStringContainsString( $case['expected'], $messages[0], $case['test_condition_name'] );
			}
		} finally {
			remove_filter( 'pre_wp_mail', $mail_filter, 10 );
		}
	}

	/** 言語が登録されたときだけ入力欄を出す。 */
	public function test_meta_box(): void {
		$box = new Resource_Multilingual_Meta_Box();
		ob_start();
		$box->render( get_post( $this->resource_id ) );
		$empty = ob_get_clean();
		$this->assertStringNotContainsString( 'vkbm_resource_multilingual[en_US][title]', $empty );
		update_option( Multilingual_Labels::OPTION_KEY, array( 'en_US' => array() ) );
		ob_start();
		$box->render( get_post( $this->resource_id ) );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'vkbm_resource_multilingual[en_US][title]', $html );
		$this->assertStringContainsString( 'placeholder="田中"', $html );
		$this->assertStringContainsString( 'lang="en-US"', $html );
	}
}
