<?php
/**
 * メニュー一覧ブロックのカードの説明文（抜粋）のテスト（#1110）。
 *
 * 抜粋が空のプランで、テーマなどの抜粋フィルターが「いま表示中の投稿」（グローバル $post）を
 * 参照すると、全カードがページ側の投稿の文になる不具合の回帰テスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Frontend;

use VKBookingManager\Blocks\Menu_Loop_Block;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use WP_UnitTestCase;

/**
 * カードごとに自プランの説明文が出ることを検証するテスト。
 *
 * @group blocks
 */
class Menu_Loop_Card_Excerpt_Test extends WP_UnitTestCase {

	/**
	 * テーマの抜粋生成を模したフィルター。
	 *
	 * 抜粋が空のとき、引数の投稿ではなくグローバル $post の本文（get_the_content()）から説明文を作る。
	 *
	 * @param string $text 抜粋。
	 * @return string 説明文。
	 */
	public function theme_like_excerpt_filter( $text ): string {
		if ( '' !== (string) $text ) {
			return (string) $text;
		}

		return wp_trim_words( wp_strip_all_tags( get_the_content() ), 20, '' );
	}

	/**
	 * 描画結果から、メニューごとの説明文を取り出す。
	 *
	 * @param string $html     描画結果。
	 * @param int[]  $menu_ids メニューの投稿 ID。
	 * @return array<int,string> メニュー ID => 説明文（説明文が無いときは空文字）。
	 */
	private function extract_excerpts( string $html, array $menu_ids ): array {
		$excerpts = array();
		foreach ( $menu_ids as $menu_id ) {
			$excerpts[ $menu_id ] = '';
			// カード（article）単位で切り出してから説明文の要素を探す。
			if ( ! preg_match( '#<article[^>]*data-menu-id="' . $menu_id . '"[^>]*>(.*?)</article>#s', $html, $card ) ) {
				continue;
			}
			if ( preg_match( '#<div class="vkbm-menu-loop__card-excerpt">(.*?)</div>#s', $card[1], $excerpt ) ) {
				$excerpts[ $menu_id ] = trim( wp_strip_all_tags( $excerpt[1] ) );
			}
		}

		return $excerpts;
	}

	/**
	 * 抜粋の有無・本文・抜粋フィルターの組み合わせごとに、各カードの説明文を検証する。
	 */
	public function test_render_block(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'グローバル $post を参照する抜粋フィルターあり・抜粋が空で本文が異なる2件 => 各カードに自プランの本文が出る',
				'theme_filter'        => true,
				'plans'               => array(
					array(
						'excerpt' => '',
						'content' => 'Cut plan body',
					),
					array(
						'excerpt' => '',
						'content' => 'Color plan body',
					),
				),
				'expected'            => array( 'Cut plan body', 'Color plan body' ),
			),
			array(
				'test_condition_name' => '抜粋フィルターなし（WordPress 本体のみ）・抜粋が空で本文が異なる2件 => 各カードに自プランの本文が出る',
				'theme_filter'        => false,
				'plans'               => array(
					array(
						'excerpt' => '',
						'content' => 'Perm plan body',
					),
					array(
						'excerpt' => '',
						'content' => 'Spa plan body',
					),
				),
				'expected'            => array( 'Perm plan body', 'Spa plan body' ),
			),
			array(
				'test_condition_name' => 'グローバル $post を参照する抜粋フィルターあり・抜粋が入力済みの2件 => 各カードに自プランの抜粋が出る',
				'theme_filter'        => true,
				'plans'               => array(
					array(
						'excerpt' => 'Cut excerpt',
						'content' => 'Cut long body',
					),
					array(
						'excerpt' => 'Color excerpt',
						'content' => 'Color long body',
					),
				),
				'expected'            => array( 'Cut excerpt', 'Color excerpt' ),
			),
			array(
				'test_condition_name' => 'グローバル $post を参照する抜粋フィルターあり・抜粋も本文も空の1件 => 説明文を出さない',
				'theme_filter'        => true,
				'plans'               => array(
					array(
						'excerpt' => '',
						'content' => '',
					),
				),
				'expected'            => array( '' ),
			),
		);

		foreach ( $test_cases as $index => $case ) {
			// 固定ページを表示し、テンプレートのループ内と同じくグローバル $post をそのページにしておく。
			$page_id = (int) $this->factory()->post->create(
				array(
					'post_type'    => 'page',
					'post_title'   => 'Page',
					'post_content' => 'Page side body',
				)
			);
			$this->go_to( get_permalink( $page_id ) );
			the_post();

			// テストケースのプランを作る。
			// 担当できるスタッフがいないプランは一覧に出ない（#1144）ため、「すべて」で担当できる公開中のスタッフを用意する。
			$this->factory()->post->create(
				array(
					'post_type'   => Resource_Post_Type::POST_TYPE,
					'post_status' => 'publish',
				)
			);
			$menu_ids = array();
			foreach ( $case['plans'] as $plan_index => $plan ) {
				$menu_id = (int) $this->factory()->post->create(
					array(
						'post_type'    => Service_Menu_Post_Type::POST_TYPE,
						'post_status'  => 'publish',
						'post_title'   => 'Plan ' . $index . '-' . $plan_index,
						'post_excerpt' => $plan['excerpt'],
						'post_content' => $plan['content'],
						'menu_order'   => $plan_index,
					)
				);
				update_post_meta( $menu_id, Service_Menu_Post_Type::META_STAFF_ALL, '1' );
				$menu_ids[] = $menu_id;
			}

			// テーマの抜粋生成を模して、本体の抜粋生成をフィルターに差し替える。
			if ( $case['theme_filter'] ) {
				remove_filter( 'get_the_excerpt', 'wp_trim_excerpt', 10 );
				add_filter( 'get_the_excerpt', array( $this, 'theme_like_excerpt_filter' ), 10 );
			}

			// ブロックを描画して、カードごとの説明文を取り出す。
			$html     = ( new Menu_Loop_Block() )->render_block(
				array(
					'loopId'      => 'excerpt-test-' . $index,
					'showExcerpt' => true,
				)
			);
			$excerpts = $this->extract_excerpts( $html, $menu_ids );

			foreach ( $menu_ids as $plan_index => $menu_id ) {
				$this->assertSame( $case['expected'][ $plan_index ], $excerpts[ $menu_id ], $case['test_condition_name'] . '（' . ( $plan_index + 1 ) . '件目）' );
			}

			// 描画後は表示中のページが元に戻っている。
			$this->assertSame( $page_id, get_the_ID(), $case['test_condition_name'] . '（描画後のグローバル $post）' );
			$this->assertSame( 'Page side body', get_the_content(), $case['test_condition_name'] . '（描画後の本文）' );

			// 差し替えたフィルターとプランを元に戻す。
			if ( $case['theme_filter'] ) {
				remove_filter( 'get_the_excerpt', array( $this, 'theme_like_excerpt_filter' ), 10 );
				add_filter( 'get_the_excerpt', 'wp_trim_excerpt', 10, 2 );
			}
			foreach ( $menu_ids as $menu_id ) {
				wp_delete_post( $menu_id, true );
			}
		}
	}
}
