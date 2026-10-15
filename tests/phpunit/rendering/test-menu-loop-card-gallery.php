<?php
/**
 * メニューカードのサブ画像（#623）の描画テスト。
 *
 * スペック表の直下に登録した画像をすべて並べること、0枚・メタ情報非表示のときは出さないこと、
 * 既存のアイキャッチ画像の出力が変わらないことを確認する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Frontend;

use VKBookingManager\Blocks\Menu_Loop_Block;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use WP_UnitTestCase;

/**
 * カードのサブ画像出力を検証するテスト。
 *
 * @group rendering
 */
class Menu_Loop_Card_Gallery_Test extends WP_UnitTestCase {

	/**
	 * テスト対象のブロックインスタンス。
	 *
	 * @var Menu_Loop_Block
	 */
	private $block;

	/**
	 * 各テスト前にブロックインスタンスを用意する。
	 */
	public function set_up(): void {
		parent::set_up();
		$this->block = new Menu_Loop_Block();
	}

	/**
	 * 画像の添付を、実ファイルを置かずに作成する。
	 *
	 * @param string $file ファイル名（ファイル名ごとに別の URL になる）。
	 * @return int 添付ID。
	 */
	private function create_image( string $file ): int {
		$attachment_id = (int) $this->factory()->attachment->create(
			array(
				'post_mime_type' => 'image/jpeg',
				'file'           => '2026/06/' . $file,
			)
		);
		wp_update_attachment_metadata(
			$attachment_id,
			array(
				'width'  => 1600,
				'height' => 900,
				'file'   => '2026/06/' . $file,
				'sizes'  => array(),
			)
		);

		return $attachment_id;
	}

	/**
	 * サービスメニューを作成する。
	 *
	 * @param array<int,int> $gallery_ids サブ画像の添付ID。
	 * @param bool           $with_price  基本料金と所要時間を設定するか。
	 * @return int メニューの投稿ID。
	 */
	private function create_menu( array $gallery_ids, bool $with_price = true ): int {
		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => '写真つきメニュー',
			)
		);
		if ( $with_price ) {
			update_post_meta( $menu_id, '_vkbm_base_price', 5000 );
			update_post_meta( $menu_id, '_vkbm_duration_minutes', 60 );
		}
		if ( ! empty( $gallery_ids ) ) {
			update_post_meta( $menu_id, Service_Menu_Post_Type::META_GALLERY_IDS, $gallery_ids );
		}

		return $menu_id;
	}

	/**
	 * カード HTML からサブ画像ボタンの data-vkbm-gallery-src を順に取り出す。
	 *
	 * @param string $html カード HTML。
	 * @return array<int,string> 拡大用画像の URL。
	 */
	private function get_gallery_sources( string $html ): array {
		preg_match_all( '/class="vkbm-menu-loop__gallery-button"[^>]*data-vkbm-gallery-src="([^"]*)"/', $html, $matches );

		return $matches[1];
	}

	/**
	 * render_menu_card: サブ画像の出力位置・枚数・除外・表示条件。
	 */
	public function test_render_menu_card_gallery(): void {
		$images = array();
		for ( $i = 1; $i <= 7; $i++ ) {
			$images[] = $this->create_image( 'sub-' . $i . '.jpg' );
		}

		$test_cases = array(
			array(
				'test_condition_name' => '7枚登録 => 上限なしで7枚すべて、登録順に出る',
				'gallery_ids'         => $images,
				'overrides'           => array(),
				'expected_count'      => 7,
			),
			array(
				'test_condition_name' => '0枚 => 何も出さない',
				'gallery_ids'         => array(),
				'overrides'           => array(),
				'expected_count'      => 0,
			),
			array(
				'test_condition_name' => 'メタ情報を表示しない設定 => 登録があっても出さない',
				'gallery_ids'         => $images,
				'overrides'           => array( 'showMeta' => false ),
				'expected_count'      => 0,
			),
		);

		foreach ( $test_cases as $case ) {
			$menu_id = $this->create_menu( $case['gallery_ids'] );
			$html    = $this->block->render_menu_card( $menu_id, $case['overrides'] );

			$sources = $this->get_gallery_sources( $html );
			$this->assertCount( $case['expected_count'], $sources, $case['test_condition_name'] );

			if ( 0 === $case['expected_count'] ) {
				$this->assertStringNotContainsString( 'vkbm-menu-loop__gallery', $html, $case['test_condition_name'] );
				continue;
			}

			// 登録順どおりに並ぶ。
			foreach ( $sources as $index => $source ) {
				$this->assertStringContainsString( 'sub-' . ( $index + 1 ) . '.jpg', $source, $case['test_condition_name'] );
			}

			// スペック表（dl）の直下、カード情報の主要部分（card-meta-main）の中に出る。
			$pos_main    = strpos( $html, 'vkbm-menu-loop__card-meta-main' );
			$pos_dl_end  = strpos( $html, '</dl>' );
			$pos_gallery = strpos( $html, '<ul class="vkbm-menu-loop__gallery"' );
			$pos_side    = strpos( $html, 'vkbm-menu-loop__card-meta-side' );
			$this->assertNotFalse( $pos_gallery, $case['test_condition_name'] );
			$this->assertGreaterThan( $pos_main, $pos_dl_end, $case['test_condition_name'] );
			$this->assertSame( $pos_dl_end + strlen( '</dl>' ), $pos_gallery, $case['test_condition_name'] . ': dl の直後' );
			$this->assertGreaterThan( $pos_gallery, $pos_side, $case['test_condition_name'] . ': 料金・ボタンの前' );

			// サムネイルは拡大ボタンで包まれ、画像の代替テキストは空で、遅延読み込みになる。
			$this->assertStringContainsString( 'aria-label="', $html, $case['test_condition_name'] );
			$this->assertStringContainsString( 'vkbm-menu-loop__gallery-image', $html, $case['test_condition_name'] );
			$this->assertStringContainsString( 'loading="lazy"', $html, $case['test_condition_name'] );
		}
	}

	/**
	 * render_menu_card: 削除済みの添付や画像でない添付は出さない。
	 */
	public function test_render_menu_card_gallery_excludes_invalid_attachments(): void {
		$image_a = $this->create_image( 'keep-a.jpg' );
		$image_b = $this->create_image( 'keep-b.jpg' );
		$deleted = $this->create_image( 'deleted.jpg' );

		$menu_id = $this->create_menu( array( $image_a, $deleted, $image_b ) );
		wp_delete_attachment( $deleted, true );

		$sources = $this->get_gallery_sources( $this->block->render_menu_card( $menu_id ) );

		$this->assertCount( 2, $sources, '削除済みの添付 => 除かれる' );
		$this->assertStringContainsString( 'keep-a.jpg', $sources[0], '1枚目' );
		$this->assertStringContainsString( 'keep-b.jpg', $sources[1], '2枚目' );
	}

	/**
	 * render_menu_card: 料金・所要時間が空でも、サブ画像だけでカードの情報欄が出る。
	 */
	public function test_render_menu_card_gallery_without_other_meta(): void {
		$menu_id = $this->create_menu( array( $this->create_image( 'only.jpg' ) ), false );

		$html = $this->block->render_menu_card( $menu_id );

		$this->assertCount( 1, $this->get_gallery_sources( $html ), '料金・所要時間なし => サブ画像だけでも出る' );
		$this->assertStringContainsString( 'vkbm-menu-loop__card-meta-main', $html, '情報欄の枠がある' );
	}

	/**
	 * render_menu_card: サブ画像を登録しても、アイキャッチ画像の出力は変わらない。
	 */
	public function test_render_menu_card_gallery_keeps_featured_image(): void {
		$featured = $this->create_image( 'featured.jpg' );

		$without_gallery = $this->create_menu( array() );
		update_post_meta( $without_gallery, '_thumbnail_id', $featured );
		$with_gallery = $this->create_menu( array( $this->create_image( 'sub-x.jpg' ), $this->create_image( 'sub-y.jpg' ) ) );
		update_post_meta( $with_gallery, '_thumbnail_id', $featured );

		$pattern = '#<div class="vkbm-menu-loop__card-media">.*?</div>#s';
		preg_match( $pattern, $this->block->render_menu_card( $without_gallery ), $before );
		preg_match( $pattern, $this->block->render_menu_card( $with_gallery ), $after );

		$this->assertNotEmpty( $before, 'アイキャッチ画像が出力される' );
		$this->assertSame( $before[0], $after[0], 'サブ画像の有無でアイキャッチ画像の出力が変わらない' );
		$this->assertStringContainsString( Menu_Loop_Block::CARD_IMAGE_CLASS, $after[0], 'アイキャッチ画像のクラスが残る' );
	}
}
