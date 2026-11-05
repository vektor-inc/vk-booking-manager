<?php
/**
 * メニューループ（カード）のアイキャッチ画像の sizes 属性のテスト。
 *
 * カード画像は object-fit: cover で縦長の枠を埋めるため、sizes="auto" のままだと
 * 表示幅だけを基準に解像度の足りない画像が選ばれてぼやける。
 * カード描画時と本文のタグ処理（wp_filter_content_tags）の後の両方で、
 * sizes 属性に auto が付かず、指定した値になっていることを確認する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Frontend;

use VKBookingManager\Blocks\Menu_Loop_Block;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_HTML_Tag_Processor;
use WP_UnitTestCase;

/**
 * カードレイアウトのアイキャッチ画像の sizes 属性を検証するテスト。
 */
class Menu_Loop_Card_Image_Sizes_Test extends WP_UnitTestCase {
	/**
	 * カード画像に指定する sizes 属性の期待値。
	 */
	private const EXPECTED_SIZES = '(max-width: 599.98px) 100vw, 480px';

	/**
	 * テスト対象のブロックインスタンス。
	 *
	 * @var Menu_Loop_Block
	 */
	private $block;

	/**
	 * 各テスト前にブロックインスタンスを用意し、本文フィルターを登録する。
	 */
	public function set_up(): void {
		parent::set_up();
		$this->block = new Menu_Loop_Block();
		// プラグイン本体の登録状況に左右されないよう、テスト用インスタンスでフィルターを登録する。
		add_filter( 'wp_content_img_tag', array( $this->block, 'filter_card_image_tag' ) );
	}

	/**
	 * 各テスト後に登録したフィルターを外す。
	 */
	public function tear_down(): void {
		remove_filter( 'wp_content_img_tag', array( $this->block, 'filter_card_image_tag' ) );
		parent::tear_down();
	}

	/**
	 * 中間サイズを持つ画像の添付ファイルを、実ファイルを置かずにメタ情報だけで生成する。
	 *
	 * srcset の生成に必要なのは添付ファイルのメタ情報だけなので、画像ファイル自体は作らない。
	 *
	 * @return int 作成した添付ファイルの投稿ID。
	 */
	private function create_image_attachment(): int {
		$attachment_id = $this->factory()->attachment->create(
			array(
				'post_mime_type' => 'image/jpeg',
				'file'           => '2026/06/card.jpg',
			)
		);

		// 2048px の元画像から生成された中間サイズを持つ状態にする。
		wp_update_attachment_metadata(
			$attachment_id,
			array(
				'width'  => 2048,
				'height' => 1152,
				'file'   => '2026/06/card.jpg',
				'sizes'  => array(
					'medium'       => array(
						'file'      => 'card-300x169.jpg',
						'width'     => 300,
						'height'    => 169,
						'mime-type' => 'image/jpeg',
					),
					'medium_large' => array(
						'file'      => 'card-768x432.jpg',
						'width'     => 768,
						'height'    => 432,
						'mime-type' => 'image/jpeg',
					),
					'large'        => array(
						'file'      => 'card-1024x576.jpg',
						'width'     => 1024,
						'height'    => 576,
						'mime-type' => 'image/jpeg',
					),
				),
			)
		);

		return (int) $attachment_id;
	}

	/**
	 * アイキャッチ画像を設定したサービスメニューを生成する。
	 *
	 * @return int 作成したサービスメニューの投稿ID。
	 */
	private function create_menu_with_thumbnail(): int {
		$menu_id = $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'テストサービス',
			)
		);

		// カード自体が空にならないよう基本料金を設定し、アイキャッチ画像を紐づける。
		update_post_meta( $menu_id, '_vkbm_base_price', 5000 );
		update_post_meta( $menu_id, '_thumbnail_id', $this->create_image_attachment() );

		return (int) $menu_id;
	}

	/**
	 * HTML の最初の img タグから属性値を取り出す。
	 *
	 * @param string $html      HTML。
	 * @param string $attribute 属性名。
	 * @return string|null 属性値。img タグや属性が無い場合は null。
	 */
	private function get_img_attribute( string $html, string $attribute ): ?string {
		$processor = new WP_HTML_Tag_Processor( $html );
		if ( ! $processor->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
			return null;
		}

		$value = $processor->get_attribute( $attribute );

		return is_string( $value ) ? $value : null;
	}

	/**
	 * カード描画時と本文のタグ処理の後で、カード画像の sizes 属性が指定した値になることを確認する。
	 */
	public function test_card_image_sizes(): void {
		$menu_id = $this->create_menu_with_thumbnail();
		$card    = $this->block->render_menu_card( $menu_id );

		$test_cases = array(
			array(
				'test_condition_name' => 'カード描画直後（REST のプレビューなど本文フィルターを通らない経路） => sizes に auto が付かず指定した値になる',
				'html'                => $card,
			),
			array(
				'test_condition_name' => '本文のタグ処理を通した後（ブロックとして本文に置いた経路） => コアが付け直した auto が取り除かれ指定した値になる',
				'html'                => wp_filter_content_tags( $card, 'the_content' ),
			),
		);

		foreach ( $test_cases as $case ) {
			// 遅延読み込みは維持したまま、sizes だけが指定値になっていることを確認する。
			$this->assertSame( 'lazy', $this->get_img_attribute( $case['html'], 'loading' ), $case['test_condition_name'] );
			$this->assertSame( self::EXPECTED_SIZES, $this->get_img_attribute( $case['html'], 'sizes' ), $case['test_condition_name'] );
			// 画像を見分けるクラスと、既定のクラスの両方が付いていることを確認する。
			$this->assertStringContainsString( 'attachment-large size-large ' . Menu_Loop_Block::CARD_IMAGE_CLASS, (string) $this->get_img_attribute( $case['html'], 'class' ), $case['test_condition_name'] );
			// 中間サイズから srcset が生成されていることを確認する。
			$this->assertStringContainsString( 'card-768x432.jpg 768w', (string) $this->get_img_attribute( $case['html'], 'srcset' ), $case['test_condition_name'] );
		}
	}

	/** 予約一覧は既定の HTML を保ち、各画像比率だけをカードへ反映する。 */
	public function test_get_selection_list(): void {
		$menu_id = $this->create_menu_with_thumbnail();
		delete_option( Settings_Repository::OPTION_KEY );
		// 追加前と同じ属性で描画したカードとラッパーを期待値にする。
		$card       = ( new Menu_Loop_Block() )->render_menu_card(
			$menu_id,
			array(
				'showDetailButton'  => true,
				'showReserveButton' => true,
				'disableTitleLink'  => true,
			)
		);
		$expected   = '<div class="vkbm-menu-loop vkbm-menu-loop--selection vkbm-menu-loop--mode-card"><div class="vkbm-menu-loop__list vkbm-menu-loop__list--card"><section class="vkbm-menu-loop__group vkbm-menu-loop__group--mode-card"><div class="vkbm-menu-loop__list vkbm-menu-loop__list--card">' . $card . '</div></section></div></div>';
		$test_cases = array(
			array(
				'test_condition_name' => '未保存は従来と同じ HTML',
				'settings'            => array(),
				'expected'            => '',
			),
			array(
				'test_condition_name' => 'content は従来と同じ HTML',
				'settings'            => array( 'reservation_menu_list_image_aspect_ratio' => 'content' ),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '元のサイズのクラス',
				'settings'            => array( 'reservation_menu_list_image_aspect_ratio' => 'original' ),
				'expected'            => 'original',
			),
			array(
				'test_condition_name' => '正方形のクラス',
				'settings'            => array( 'reservation_menu_list_image_aspect_ratio' => '1-1' ),
				'expected'            => '1-1',
			),
			array(
				'test_condition_name' => '標準のクラス',
				'settings'            => array( 'reservation_menu_list_image_aspect_ratio' => '4-3' ),
				'expected'            => '4-3',
			),
			array(
				'test_condition_name' => 'ワイドのクラス',
				'settings'            => array( 'reservation_menu_list_image_aspect_ratio' => '16-9' ),
				'expected'            => '16-9',
			),
			array(
				'test_condition_name' => '不正値は従来と同じ HTML',
				'settings'            => array( 'reservation_menu_list_image_aspect_ratio' => 'invalid' ),
				'expected'            => '',
			),
		);
		foreach ( $test_cases as $case ) {
			update_option( Settings_Repository::OPTION_KEY, $case['settings'] );
			$result = ( new Menu_Loop_Block() )->get_selection_list();
			$this->assertSame( array( $menu_id ), $result['menu_ids'], $case['test_condition_name'] );
			if ( '' === $case['expected'] ) {
				$this->assertSame( $expected, $result['html'], $case['test_condition_name'] );
			} else {
				$this->assertStringContainsString( 'vkbm-menu-loop__card-media--ratio-' . $case['expected'], $result['html'], $case['test_condition_name'] );
			}
		}
		delete_option( Settings_Repository::OPTION_KEY );
	}

	/**
	 * 画像比率の属性未保存・既定値・各比率・画像非表示の出力を確認する。
	 */
	public function test_render_menu_card_image_aspect_ratio(): void {
		$menu_id = $this->create_menu_with_thumbnail();
		$default = $this->block->render_menu_card( $menu_id );
		$this->assertStringContainsString( '<div class="vkbm-menu-loop__card-media">', $default );
		$this->assertStringNotContainsString( 'card-media--ratio-', $default );

		// 保存済みのブロックコメントも、現在の属性定義を通して描画する。
		$loop_default = do_blocks( '<!-- wp:vk-booking-manager/menu-loop {"loopId":"image-ratio-test"} /-->' );
		$this->assertStringContainsString( '<div class="vkbm-menu-loop__card-media">', $loop_default );

		$test_cases = array(
			array(
				'test_condition_name' => '属性未保存 => 追加クラスなし',
				'attributes'          => array(),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '本文の高さに合わせる => 追加クラスなし',
				'attributes'          => array( 'imageAspectRatio' => 'content' ),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '元のサイズ => original クラス',
				'attributes'          => array( 'imageAspectRatio' => 'original' ),
				'expected'            => 'original',
			),
			array(
				'test_condition_name' => '正方形 => 1-1 クラス',
				'attributes'          => array( 'imageAspectRatio' => '1-1' ),
				'expected'            => '1-1',
			),
			array(
				'test_condition_name' => '標準 => 4-3 クラス',
				'attributes'          => array( 'imageAspectRatio' => '4-3' ),
				'expected'            => '4-3',
			),
			array(
				'test_condition_name' => 'ワイド => 16-9 クラス',
				'attributes'          => array( 'imageAspectRatio' => '16-9' ),
				'expected'            => '16-9',
			),
			array(
				'test_condition_name' => '不正な値 => 追加クラスなし',
				'attributes'          => array( 'imageAspectRatio' => 'invalid" onclick="alert(1)' ),
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			$html = $this->block->render_menu_card( $menu_id, $case['attributes'] );
			$loop = do_blocks( '<!-- wp:vk-booking-manager/menu-loop ' . wp_json_encode( array_merge( array( 'loopId' => 'image-ratio-test' ), $case['attributes'] ) ) . ' /-->' );
			// 比率クラス以外の HTML は、属性未保存のカードと完全に一致する。
			if ( '' === $case['expected'] ) {
				$this->assertSame( $default, $html, $case['test_condition_name'] );
				$this->assertSame( $loop_default, $loop, $case['test_condition_name'] );
			} else {
				$ratio_class = ' vkbm-menu-loop__card-media--ratio-' . $case['expected'];
				$this->assertStringContainsString( $ratio_class . '"', $html, $case['test_condition_name'] );
				$this->assertSame( $default, str_replace( $ratio_class, '', $html ), $case['test_condition_name'] );
				$this->assertStringContainsString( $ratio_class . '"', $loop, $case['test_condition_name'] );
				$this->assertSame( $loop_default, str_replace( $ratio_class, '', $loop ), $case['test_condition_name'] );
			}
		}

		// 画像非表示やテキスト表示では比率を指定しても画像枠を出さない。
		foreach ( array( array( 'showImage' => false ), array( 'displayMode' => 'text' ) ) as $attributes ) {
			$html = $this->block->render_menu_card( $menu_id, array_merge( $attributes, array( 'imageAspectRatio' => '1-1' ) ) );
			$this->assertStringNotContainsString( 'vkbm-menu-loop__card-media', $html );
		}
	}

	/**
	 * 全体がテキスト表示でも、グループ設定でカード表示になる画像に比率を適用する。
	 */
	public function test_render_group_image_aspect_ratio(): void {
		$menu_id = $this->create_menu_with_thumbnail();
		$term_id = $this->factory()->term->create( array( 'taxonomy' => Service_Menu_Post_Type::TAXONOMY_GROUP ) );
		wp_set_object_terms( $menu_id, array( $term_id ), Service_Menu_Post_Type::TAXONOMY_GROUP );

		// グループ設定の適用条件と、画像非表示時の境界を確認する。
		$test_cases = array(
			array(
				'test_condition_name' => '全グループ・グループはカード => 比率クラスあり',
				'attributes'          => array( 'groupFilterMode' => 'all' ),
				'group_display_mode'  => 'card',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'グループ指定属性なし・グループはカード => 比率クラスあり',
				'attributes'          => array(),
				'group_display_mode'  => 'card',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '指定グループ・グループはカード => 上書きされず比率クラスなし',
				'attributes'          => array(
					'groupFilterMode'  => 'selected',
					'selectedGroupIds' => array( $term_id ),
				),
				'group_display_mode'  => 'card',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '全グループ・グループは継承 => 比率クラスなし',
				'attributes'          => array( 'groupFilterMode' => 'all' ),
				'group_display_mode'  => 'inherit',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '全グループ・グループはテキスト => 比率クラスなし',
				'attributes'          => array( 'groupFilterMode' => 'all' ),
				'group_display_mode'  => 'text',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '全グループ・グループはカード・画像非表示 => 比率クラスなし',
				'attributes'          => array(
					'groupFilterMode' => 'all',
					'showImage'       => false,
				),
				'group_display_mode'  => 'card',
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			update_term_meta( $term_id, 'vkbm_menu_group_display_mode', $case['group_display_mode'] );
			$attributes = array_merge(
				array(
					'loopId'           => 'group-image-ratio-test',
					'displayMode'      => 'text',
					'imageAspectRatio' => '4-3',
				),
				$case['attributes']
			);
			$html       = do_blocks( '<!-- wp:vk-booking-manager/menu-loop ' . wp_json_encode( $attributes ) . ' /-->' );

			// 空の描画を成功扱いにせず、対象メニューが描画されたうえで比率クラスを検証する。
			$this->assertStringContainsString( 'data-menu-id="' . $menu_id . '"', $html, $case['test_condition_name'] );
			if ( $case['expected'] ) {
				$this->assertStringContainsString( 'vkbm-menu-loop__card-media--ratio-4-3', $html, $case['test_condition_name'] );
			} else {
				$this->assertStringNotContainsString( 'card-media--ratio-', $html, $case['test_condition_name'] );
			}
		}
	}

	/**
	 * 本文フィルターが、カード画像以外の画像の sizes="auto" を変えないことを確認する。
	 */
	public function test_filter_card_image_tag_ignores_other_images(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'カード画像のクラスを持たない画像の場合 => auto を残したまま返す',
				'image'               => '<img class="wp-image-1" src="a.jpg" sizes="auto, 480px" loading="lazy">',
				'expected'            => '<img class="wp-image-1" src="a.jpg" sizes="auto, 480px" loading="lazy">',
			),
			array(
				'test_condition_name' => '文字列以外の値が渡された場合 => 受け取った値をそのまま返す',
				'image'               => false,
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], $this->block->filter_card_image_tag( $case['image'] ), $case['test_condition_name'] );
		}
	}
}
