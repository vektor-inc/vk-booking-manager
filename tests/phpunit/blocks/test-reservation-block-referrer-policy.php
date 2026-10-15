<?php
/**
 * #643: 確認画面（予約ブロックのページの ?draft=<token>）にだけ Referrer-Policy を付けることのテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Blocks;

use VKBookingManager\Auth\Auth_Shortcodes;
use VKBookingManager\Blocks\Reservation_Block;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use WP_UnitTestCase;

/**
 * Reservation_Block::filter_confirmation_referrer_policy() のテスト。
 *
 * @group blocks
 */
class Reservation_Block_Referrer_Policy_Test extends WP_UnitTestCase {
	/** @var array<string, mixed> */
	private array $get_backup = array();

	protected function setUp(): void {
		parent::setUp();
		$this->get_backup = $_GET;
		$_GET             = array();
	}

	protected function tearDown(): void {
		$_GET = $this->get_backup;
		parent::tearDown();
	}

	private function build_block(): Reservation_Block {
		$service = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );

		return new Reservation_Block( new Auth_Shortcodes( $service ) );
	}

	private function go_to_page_with_content( string $content ): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => $content,
			)
		);
		$this->go_to( get_permalink( $post_id ) );
	}

	public function test_adds_no_referrer_on_confirmation_screen(): void {
		$this->go_to_page_with_content( '<!-- wp:vk-booking-manager/reservation /-->' );
		$_GET['draft'] = 'abc123';

		$headers = $this->build_block()->filter_confirmation_referrer_policy( array() );

		$this->assertSame( 'no-referrer', $headers['Referrer-Policy'] );
	}

	public function test_does_not_add_header_without_draft_param(): void {
		$this->go_to_page_with_content( '<!-- wp:vk-booking-manager/reservation /-->' );

		$headers = $this->build_block()->filter_confirmation_referrer_policy( array() );

		$this->assertArrayNotHasKey( 'Referrer-Policy', $headers );
	}

	public function test_does_not_add_header_on_page_without_reservation_block(): void {
		$this->go_to_page_with_content( '<p>other page</p>' );
		$_GET['draft'] = 'abc123';

		$headers = $this->build_block()->filter_confirmation_referrer_policy( array() );

		$this->assertArrayNotHasKey( 'Referrer-Policy', $headers );
	}
}
