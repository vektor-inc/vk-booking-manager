<?php
/**
 * 白い面を持つ部品の文字色（--vkbm--color--surface-text）の指定を検証するテスト。
 *
 * issue #845: Lightning のダーク設定などで --vkbm--color--text-primary が明るい色になると、
 * プラグインが自分で白い背景を持つ部品（時間枠・確認画面の要約など）の文字が白地に明るい色で
 * 読めなくなる。白い面を持つ部品は、背景色と固定の濃い文字色（surface-text）を必ず対で指定する。
 *
 * ビルド成果物（build/ 配下。CI ではビルドされない）ではなく、ソースの SCSS を直接検証する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Assets;

use PHPUnit\Framework\TestCase;

/**
 * 白い面と文字色の対指定を検証するテストクラス。
 *
 * @group assets
 */
class Surface_Text_Styles_Test extends TestCase {

	/**
	 * surface-text が :root で定義され、濃い色であることを検証する。
	 */
	public function test_surface_text_variable_is_defined_as_a_dark_color(): void {
		$content = $this->read_local_file( 'assets/scss/variables.scss' );

		$this->assertSame(
			1,
			preg_match( '/--vkbm--color--surface-text:\s*(#[0-9a-fA-F]{3,6});/', $content, $matches ),
			'--vkbm--color--surface-text が固定の色で定義されていません。'
		);
		$this->assertStringNotContainsString(
			'surface-text: var(',
			$content,
			'surface-text はテーマの色を拾わない固定値にしてください。'
		);

		$hex = ltrim( $matches[1], '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		$luminance = 0.0;
		foreach ( array( 0.2126, 0.7152, 0.0722 ) as $i => $weight ) {
			$channel    = hexdec( substr( $hex, $i * 2, 2 ) ) / 255;
			$luminance += $weight * ( $channel <= 0.03928 ? $channel / 12.92 : pow( ( $channel + 0.055 ) / 1.055, 2.4 ) );
		}
		$this->assertGreaterThanOrEqual( 4.5, 1.05 / ( $luminance + 0.05 ), '白地で 4.5:1 以上のコントラストが必要です。' );
	}

	/**
	 * 白い面を持つ部品が、背景色と surface-text を対で指定していることを検証する。
	 *
	 * @dataProvider provide_surface_selectors
	 *
	 * @param string $file     SCSS ファイルのプラグイン内パス。
	 * @param string $selector セレクタ（最初の宣言ブロック（ネストした規則の手前まで）を見る）。
	 */
	public function test_white_surface_components_pair_background_with_surface_text( string $file, string $selector ): void {
		$content = $this->read_local_file( $file );

		$this->assertSame(
			1,
			preg_match( '/' . preg_quote( $selector, '/' ) . '\s*\{([^{}]*)/s', $content, $matches ),
			$selector . ' のルールが見つかりません。'
		);
		// 見出し（#864）は、オン時だけ出力する --vkbm--color--menu-heading-* の代替値として背景色・surface-text を持つ。
		$this->assertMatchesRegularExpression( '/background(-color)?:\s*(#|var\(--vkbm--color--bg-|var\(--vkbm--color--menu-heading-bg,\s*#)/', $matches[1], $selector . ' に背景色の指定がありません。' );
		$this->assertMatchesRegularExpression( '/(?<![\w-])color:\s*var\((--vkbm--color--menu-heading-text,\s*var\()?--vkbm--color--surface-text/', $matches[1], $selector . ' に surface-text の文字色が対で指定されていません。' );
		$this->assertStringNotContainsString( 'color: var(--vkbm--color--text-primary', $matches[1], $selector . ' で text-primary を使わないでください。' );
	}

	/**
	 * 対象の（ファイル, セレクタ）の一覧。
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function provide_surface_selectors(): array {
		$reservation = 'src/blocks/reservation/style.scss';

		return array(
			'time slot button'      => array( $reservation, '.vkbm-slot-list__item' ),
			'confirm summary'       => array( $reservation, '.vkbm-confirm__summary' ),
			'favorites'             => array( $reservation, '.vkbm-favorites' ),
			'pricing'               => array( $reservation, '.vkbm-plan-summary__pricing' ),
			'detail heading'        => array( $reservation, '.vkbm-reservation-detail__heading' ),
			'footer modal'          => array( $reservation, '.vkbm-footer-modal__dialog' ),
			'menu loop group title' => array( 'src/blocks/menu-loop/style.scss', '.vkbm-menu-loop__group-title' ),
			'auth card'             => array( 'assets/scss/auth-forms.scss', '.vkbm-auth-card' ),
		);
	}

	/**
	 * レイアウト用の箱は背景を持たず、テーマの背景に従うことを検証する。
	 *
	 * @dataProvider provide_transparent_selectors
	 *
	 * @param string $file     SCSS ファイルのプラグイン内パス。
	 * @param string $selector セレクタ。
	 */
	public function test_layout_boxes_follow_the_theme_background( string $file, string $selector ): void {
		$content = $this->read_local_file( $file );

		$this->assertSame(
			1,
			preg_match( '/' . preg_quote( $selector, '/' ) . '\s*\{([^{}]*)/s', $content, $matches ),
			$selector . ' のルールが見つかりません。'
		);
		$this->assertMatchesRegularExpression( '/background:\s*transparent;/', $matches[1], $selector . ' の背景は透明にしてください。' );
	}

	/**
	 * 背景を透明にするセレクタの一覧。
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function provide_transparent_selectors(): array {
		return array(
			'menu loop card item'      => array( 'src/blocks/menu-loop/style.scss', '.vkbm-menu-loop__card-item' ),
			'menu search field preview' => array( 'src/blocks/menu-search/style.scss', '.vkbm-menu-search__field-preview' ),
		);
	}

	/**
	 * 背景を持たない文字が、濃い色の固定ではなくテーマの文字色に従うことを検証する。
	 *
	 * @dataProvider provide_inherit_selectors
	 *
	 * @param string $selector セレクタ。
	 */
	public function test_text_without_background_follows_the_theme_text_color( string $selector ): void {
		$content = $this->read_local_file( 'src/blocks/reservation/style.scss' );

		$this->assertSame(
			1,
			preg_match( '/' . preg_quote( $selector, '/' ) . '\s*\{([^{}]*)/s', $content, $matches ),
			$selector . ' のルールが見つかりません。'
		);
		$this->assertMatchesRegularExpression( '/\bcolor:\s*inherit;/', $matches[1], $selector . ' の文字色はテーマに従う指定にしてください。' );
	}

	/**
	 * テーマの文字色に従うセレクタの一覧。
	 *
	 * @return array<string, array{0: string}>
	 */
	public function provide_inherit_selectors(): array {
		return array(
			'header brand name'     => array( '.vkbm-reservation-header__brand-name' ),
			'user section title'    => array( '.vkbm-user-section__title' ),
			'confirm policy'        => array( '.vkbm-confirm__policy' ),
			'pricing placeholder'   => array( '.vkbm-plan-summary__pricing--placeholder' ),
			'calendar nav button'   => array( '.vkbm-calendar__nav' ),
			'calendar weekdays'     => array( '.vkbm-calendar__weekdays span' ),
			'calendar day button'   => array( '.vkbm-calendar__day' ),
			'header nav link'       => array( '.vkbm-reservation-header__nav-link:not(:hover):not(:active):not(.has-background)' ),
			'header nav active'     => array( '.vkbm-reservation-header__nav .vkbm-reservation-header__nav-link.is-active' ),
		);
	}

	/**
	 * ヘッダーナビのリンクが、マウスを乗せている間・押している間も .vkbm-button__link の固定色に戻らないことを検証する。
	 */
	public function test_header_nav_link_keeps_inherit_color_on_hover_and_active(): void {
		$content = $this->read_local_file( 'src/blocks/reservation/style.scss' );

		$this->assertSame(
			1,
			preg_match( '/\.vkbm-reservation-header__nav-link:hover:not\(\.has-background\),\s*\.vkbm-reservation-header__nav-link:active:not\(\.has-background\)\s*\{([^{}]*)/s', $content, $matches ),
			'ヘッダーナビの :hover / :active のルールが見つかりません。'
		);
		$this->assertMatchesRegularExpression( '/\bcolor:\s*inherit;/', $matches[1], ':hover / :active でもテーマの文字色に従う指定にしてください。' );
	}

	/**
	 * 宿泊カレンダーの端の日が、固定の濃い色ではなく周囲の文字色に従うことを検証する。
	 */
	public function test_stay_calendar_range_ends_follow_the_theme_text_color(): void {
		$content = $this->read_local_file( 'src/blocks/reservation/lodging/lodging.scss' );

		$this->assertSame(
			1,
			preg_match( '/\.vkbm-calendar__day--range-end\s*\{([^{}]*)/s', $content, $matches ),
			'range-end のルールが見つかりません。'
		);
		$this->assertMatchesRegularExpression( '/\bcolor:\s*inherit;/', $matches[1], '端の日の文字色は周囲に従う指定にしてください。' );
		$this->assertStringNotContainsString( 'text-primary', $matches[1], '端の日で text-primary を使わないでください。' );
	}

	/**
	 * 白い面（ログインのカード・メニュー一覧の見出し帯）の中のリンクが、テーマのリンク色に左右されず白地で読めることを検証する。
	 */
	public function test_links_inside_white_surfaces_keep_a_readable_color(): void {
		$auth = $this->read_local_file( 'assets/scss/auth-forms.scss' );
		$this->assertSame(
			1,
			preg_match( '/\.vkbm-auth-card a:where\(:not\(\.vkbm-button\)\)\s*\{([^{}]*)/s', $auth, $matches ),
			'ログインのカード内のリンクのルールが見つかりません。'
		);
		$this->assertMatchesRegularExpression( '/\bcolor:\s*var\(--vkbm--color--link,\s*#1772a2\);/', $matches[1], 'カード内のリンクは link 色にしてください。' );

		$variables = $this->read_local_file( 'assets/scss/variables.scss' );
		$this->assertSame( 1, preg_match( '/--vkbm--color--link:\s*var\(--vkbm--color--accent-text,\s*(#[0-9a-fA-F]{6})\);/', $variables, $link ), 'link 色が accent-text に基づいていません。' );
		$this->assertSame( '#1772a2', strtolower( $link[1] ), '定義と、SCSS の代替値が一致していません。' );
		$this->assertGreaterThanOrEqual(
			4.5,
			$this->contrast_ratio( $this->luminance( $this->hex_to_linear( $link[1] ) ), 1.0 ),
			'link 色は白地で 4.5:1 以上が必要です。'
		);

		$menu = $this->read_local_file( 'src/blocks/menu-loop/style.scss' );
		$this->assertSame( 1, preg_match( '/\.vkbm-menu-loop__group-title\s*\{(.*?)\n\}/s', $menu, $matches ), 'グループ見出しのルールが見つかりません。' );
		$this->assertMatchesRegularExpression( '/\ba\s*\{\s*color:\s*inherit;/', $matches[1], '見出し帯の中のリンクは帯の文字色に従う指定にしてください。' );
	}

	/**
	 * 白い面の中の見出しが、テーマの見出し色ではなく親の文字色に従うことを検証する。
	 *
	 * @dataProvider provide_surface_heading_selectors
	 *
	 * @param string $file     SCSS ファイルのプラグイン内パス。
	 * @param string $selector セレクタ。
	 */
	public function test_headings_inside_white_surfaces_inherit_the_surface_text_color( string $file, string $selector ): void {
		$content = $this->read_local_file( $file );

		$this->assertSame(
			1,
			preg_match( '/' . preg_quote( $selector, '/' ) . '\s*\{([^{}]*)/s', $content, $matches ),
			$selector . ' のルールが見つかりません。'
		);
		$this->assertMatchesRegularExpression( '/\bcolor:\s*inherit;/', $matches[1], $selector . ' の文字色は親（白い面）に従う指定にしてください。' );
	}

	/**
	 * 白い面の中の見出しのセレクタの一覧。
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function provide_surface_heading_selectors(): array {
		return array(
			'favorites title'    => array( 'src/blocks/reservation/style.scss', '.vkbm-favorites__title' ),
			'footer modal title' => array( 'src/blocks/reservation/style.scss', '.vkbm-footer-modal__title' ),
			'auth card title'    => array( 'assets/scss/auth-forms.scss', '.vkbm-auth-card__title' ),
			'auth card headings' => array( 'assets/scss/auth-forms.scss', '.vkbm-auth-card :is(h2, h3, h4)' ),
		);
	}

	/**
	 * メニュー一覧のカード内の文字が、透明なカードの背景（テーマの背景）に合わせて読めることを検証する。
	 */
	public function test_menu_loop_card_text_follows_the_theme_and_price_adapts_to_dark_backgrounds(): void {
		$content = $this->read_local_file( 'src/blocks/menu-loop/style.scss' );

		$this->assertSame( 1, preg_match( '/\.vkbm-menu-loop__card-catch\s*\{([^{}]*)/s', $content, $matches ), 'card-catch のルールが見つかりません。' );
		$this->assertMatchesRegularExpression( '/color:\s*var\(--vkbm--color--menu-card-catch,\s*inherit\);/', $matches[1], 'キャッチコピーの既定色は inherit にしてください。' );

		$this->assertSame( 1, preg_match( '/\.vkbm-menu-loop__card-excerpt\s*\{([^{}]*)/s', $content, $matches ), 'card-excerpt のルールが見つかりません。' );
		$this->assertMatchesRegularExpression( '/\bcolor:\s*inherit;/', $matches[1], '抜粋の文字色は inherit にしてください。' );

		$this->assertDoesNotMatchRegularExpression( '/(?<![-\w])color:[^;]*preset--color--contrast/', $content, 'メニュー一覧のカードにテーマのプリセット色の固定を残さないでください。' );
		$this->assertStringNotContainsString( 'color-mix(in srgb, currentcolor', $content );
		$this->assertMatchesRegularExpression(
			'/@supports \(color: oklch\(from red l c h\)\)\s*\{[^@]*vkbm-menu-loop__card-price,[^@]*vkbm-menu-loop__card-prices-value[^@]*oklch\(from currentcolor/s',
			$content,
			'価格の色は、文字色の明るさに合わせる指定を @supports で包んで足してください。'
		);
	}

	/**
	 * 価格の明度式を SCSS から読み取り、代表的な本文色と背景の組み合わせで 4.5:1 以上になることを検証する。
	 *
	 * 価格の色（OKLCH）は sRGB の色域外になることがあり、ブラウザごとに収め方が異なりうる。
	 * そのため次の3方式で計算し、最も低いコントラスト比で判定する。
	 * - CSS Color 4 の色域マッピング（Local MINDE。彩度の二分探索 + deltaEOK の JND 0.02 判定 + 最後のクリップ）
	 * - 単純なクリップ（線形 sRGB を 0〜1 に丸める）
	 * - 彩度を色域の境界まで下げる方式（参考。実際のブラウザの挙動より高めに出る）
	 *
	 * @dataProvider provide_price_color_pairs
	 *
	 * @param string $text_color 本文色（価格の色が継承元とする色）。
	 * @param string $background 背景色。
	 */
	public function test_price_color_keeps_contrast_for_typical_theme_colors( string $text_color, string $background ): void {
		$content = $this->read_local_file( 'src/blocks/menu-loop/style.scss' );

		$this->assertSame(
			1,
			preg_match( '/oklch\(from currentcolor clamp\(([\d.]+),\s*calc\(([\d.]+) \+ ([\d.]+) \* l\),\s*([\d.]+)\)\s+([\d.]+)\s+([\d.]+)\)/', $content, $m ),
			'価格の明度式が見つかりません。'
		);
		list( $min, $offset, $slope, $max, $chroma, $hue ) = array_map( 'floatval', array_slice( $m, 1 ) );

		$lightness = min( $max, max( $min, $offset + $slope * $this->oklab_lightness( $this->hex_to_linear( $text_color ) ) ) );
		$bg_lum    = $this->luminance( $this->hex_to_linear( $background ) );
		$ratios    = $this->price_contrast_ratios( $lightness, $chroma, $hue, $bg_lum );

		$this->assertGreaterThanOrEqual(
			4.5,
			min( $ratios ),
			sprintf(
				'本文色 %s・背景 %s で価格のコントラスト比が 色域マッピング %.2f:1 / 単純クリップ %.2f:1 / 彩度低減 %.2f:1 です。',
				$text_color,
				$background,
				$ratios['css_gamut_mapping'],
				$ratios['clip'],
				$ratios['chroma_reduce']
			)
		);
	}

	/**
	 * 価格の色の背景に対するコントラスト比を、3方式の色域外処理それぞれで求める。
	 *
	 * @param float $lightness OKLCH の明度。
	 * @param float $chroma    OKLCH の彩度。
	 * @param float $hue       OKLCH の色相（度）。
	 * @param float $bg_lum    背景の相対輝度。
	 * @return array{css_gamut_mapping: float, clip: float, chroma_reduce: float}
	 */
	private function price_contrast_ratios( float $lightness, float $chroma, float $hue, float $bg_lum ): array {
		$to_ratio = function ( array $rgb ) use ( $bg_lum ): float {
			return $this->contrast_ratio( $this->luminance( $rgb ), $bg_lum );
		};

		return array(
			'css_gamut_mapping' => $to_ratio( $this->oklch_to_linear_srgb_css_gamut_mapping( $lightness, $chroma, $hue ) ),
			'clip'              => $to_ratio( $this->clip_rgb( $this->oklch_to_linear_srgb( $lightness, $chroma, $hue ) ) ),
			'chroma_reduce'     => $to_ratio( $this->oklch_to_linear_srgb_in_gamut( $lightness, $chroma, $hue ) ),
		);
	}

	/**
	 * 代表的な（本文色, 背景）の組。
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function provide_price_color_pairs(): array {
		return array(
			'#000 on white'      => array( '#000000', '#ffffff' ),
			'#222 on white'      => array( '#222222', '#ffffff' ),
			'#333 on white'      => array( '#333333', '#ffffff' ),
			'#555 on white'      => array( '#555555', '#ffffff' ),
			'#666 on white'      => array( '#666666', '#ffffff' ),
			'#f9f9f9 on #272727' => array( '#f9f9f9', '#272727' ),
			'#ddd on #272727'    => array( '#dddddd', '#272727' ),
			'#fff on #272727'    => array( '#ffffff', '#272727' ),
			'#fff on #1a1a1a'    => array( '#ffffff', '#1a1a1a' ),
			'#fff on #333'       => array( '#ffffff', '#333333' ),
			'#ddd on #333'       => array( '#dddddd', '#333333' ),
			'#f9f9f9 on #000'    => array( '#f9f9f9', '#000000' ),
		);
	}

	/**
	 * 16 進数の色を線形 sRGB に変換する。
	 *
	 * @param string $hex 例: #333333。
	 * @return float[]
	 */
	private function hex_to_linear( string $hex ): array {
		$hex = ltrim( $hex, '#' );
		$out = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$v     = hexdec( substr( $hex, $i * 2, 2 ) ) / 255;
			$out[] = $v <= 0.04045 ? $v / 12.92 : pow( ( $v + 0.055 ) / 1.055, 2.4 );
		}
		return $out;
	}

	/**
	 * 線形 sRGB から Oklab の明度 L を求める。
	 *
	 * @param float[] $rgb 線形 sRGB。
	 * @return float
	 */
	private function oklab_lightness( array $rgb ): float {
		$l = pow( 0.4122214708 * $rgb[0] + 0.5363325363 * $rgb[1] + 0.0514459929 * $rgb[2], 1 / 3 );
		$m = pow( 0.2119034982 * $rgb[0] + 0.6806995451 * $rgb[1] + 0.1073969566 * $rgb[2], 1 / 3 );
		$s = pow( 0.0883024619 * $rgb[0] + 0.2817188376 * $rgb[1] + 0.6299787005 * $rgb[2], 1 / 3 );
		return 0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s;
	}

	/**
	 * OKLCH を線形 sRGB に変換する（色域外でもそのまま返す）。
	 *
	 * @param float $lightness 明度。
	 * @param float $chroma    彩度。
	 * @param float $hue       色相（度）。
	 * @return float[]
	 */
	private function oklch_to_linear_srgb( float $lightness, float $chroma, float $hue ): array {
		$a = $chroma * cos( deg2rad( $hue ) );
		$b = $chroma * sin( deg2rad( $hue ) );
		$l = pow( $lightness + 0.3963377774 * $a + 0.2158037573 * $b, 3 );
		$m = pow( $lightness - 0.1055613458 * $a - 0.0638541728 * $b, 3 );
		$s = pow( $lightness - 0.0894841775 * $a - 1.2914855480 * $b, 3 );
		return array(
			4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
			-1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
			-0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s,
		);
	}

	/**
	 * 色域外なら彩度を色域の境界まで下げて sRGB の範囲に収めた線形 sRGB を返す。
	 *
	 * CSS Color 4 の色域マッピング（Local MINDE）とは別物で、コントラストを高めに見積もる。参考値として使う。
	 *
	 * @param float $lightness 明度。
	 * @param float $chroma    彩度。
	 * @param float $hue       色相（度）。
	 * @return float[]
	 */
	private function oklch_to_linear_srgb_in_gamut( float $lightness, float $chroma, float $hue ): array {
		$in_gamut = static function ( array $rgb ): bool {
			foreach ( $rgb as $v ) {
				if ( $v < -1e-6 || $v > 1 + 1e-6 ) {
					return false;
				}
			}
			return true;
		};

		if ( ! $in_gamut( $this->oklch_to_linear_srgb( $lightness, $chroma, $hue ) ) ) {
			$low  = 0.0;
			$high = $chroma;
			for ( $i = 0; $i < 40; $i++ ) {
				$mid = ( $low + $high ) / 2;
				if ( $in_gamut( $this->oklch_to_linear_srgb( $lightness, $mid, $hue ) ) ) {
					$low = $mid;
				} else {
					$high = $mid;
				}
			}
			$chroma = $low;
		}

		return array_map(
			static function ( float $v ): float {
				return min( 1.0, max( 0.0, $v ) );
			},
			$this->oklch_to_linear_srgb( $lightness, $chroma, $hue )
		);
	}

	/**
	 * 線形 sRGB を 0〜1 に丸める（単純なクリップ）。
	 *
	 * @param float[] $rgb 線形 sRGB。
	 * @return float[]
	 */
	private function clip_rgb( array $rgb ): array {
		return array_map(
			static function ( float $v ): float {
				return min( 1.0, max( 0.0, $v ) );
			},
			$rgb
		);
	}

	/**
	 * 線形 sRGB を Oklab（L, a, b）に変換する。
	 *
	 * @param float[] $rgb 線形 sRGB。
	 * @return float[]
	 */
	private function linear_srgb_to_oklab( array $rgb ): array {
		$l = $this->cbrt( 0.4122214708 * $rgb[0] + 0.5363325363 * $rgb[1] + 0.0514459929 * $rgb[2] );
		$m = $this->cbrt( 0.2119034982 * $rgb[0] + 0.6806995451 * $rgb[1] + 0.1073969566 * $rgb[2] );
		$s = $this->cbrt( 0.0883024619 * $rgb[0] + 0.2817188376 * $rgb[1] + 0.6299787005 * $rgb[2] );
		return array(
			0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s,
			1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s,
			0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s,
		);
	}

	/**
	 * 負の値にも使える立方根。
	 *
	 * @param float $v 値。
	 * @return float
	 */
	private function cbrt( float $v ): float {
		return $v < 0 ? -pow( -$v, 1 / 3 ) : pow( $v, 1 / 3 );
	}

	/**
	 * CSS Color 4 の色域マッピング（Local MINDE）で OKLCH を sRGB に収めた線形 sRGB を返す。
	 *
	 * 彩度を二分探索し、クリップした色と元の色の deltaEOK が JND（0.02）未満になる最大の彩度を採る。
	 * 手順は https://www.w3.org/TR/css-color-4/#binsearch に従う。
	 *
	 * @param float $lightness 明度。
	 * @param float $chroma    彩度。
	 * @param float $hue       色相（度）。
	 * @return float[]
	 */
	private function oklch_to_linear_srgb_css_gamut_mapping( float $lightness, float $chroma, float $hue ): array {
		$jnd     = 0.02;
		$epsilon = 0.0001;

		if ( $lightness >= 1 ) {
			return array( 1.0, 1.0, 1.0 );
		}
		if ( $lightness <= 0 ) {
			return array( 0.0, 0.0, 0.0 );
		}

		$in_gamut = static function ( array $rgb ): bool {
			foreach ( $rgb as $v ) {
				if ( $v < -1e-9 || $v > 1 + 1e-9 ) {
					return false;
				}
			}
			return true;
		};
		$delta_e  = function ( array $rgb_a, array $oklab_b ): float {
			$a = $this->linear_srgb_to_oklab( $rgb_a );
			return sqrt( pow( $a[0] - $oklab_b[0], 2 ) + pow( $a[1] - $oklab_b[1], 2 ) + pow( $a[2] - $oklab_b[2], 2 ) );
		};

		$origin = $this->oklch_to_linear_srgb( $lightness, $chroma, $hue );
		if ( $in_gamut( $origin ) ) {
			return $origin;
		}

		$clipped    = $this->clip_rgb( $origin );
		$origin_lab = $this->linear_srgb_to_oklab( $origin );
		if ( $delta_e( $clipped, $origin_lab ) < $jnd ) {
			return $clipped;
		}

		$min          = 0.0;
		$max          = $chroma;
		$min_in_gamut = true;
		while ( $max - $min > $epsilon ) {
			$mid     = ( $min + $max ) / 2;
			$current = $this->oklch_to_linear_srgb( $lightness, $mid, $hue );
			if ( $min_in_gamut && $in_gamut( $current ) ) {
				$min = $mid;
				continue;
			}
			$clipped = $this->clip_rgb( $current );
			$e       = $delta_e( $clipped, $this->linear_srgb_to_oklab( $current ) );
			if ( $e < $jnd ) {
				if ( $jnd - $e < $epsilon ) {
					return $clipped;
				}
				$min_in_gamut = false;
				$min          = $mid;
			} else {
				$max = $mid;
			}
		}

		return $clipped;
	}

	/**
	 * 線形 sRGB の相対輝度。
	 *
	 * @param float[] $rgb 線形 sRGB。
	 * @return float
	 */
	private function luminance( array $rgb ): float {
		return 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2];
	}

	/**
	 * コントラスト比。
	 *
	 * @param float $a 相対輝度。
	 * @param float $b 相対輝度。
	 * @return float
	 */
	private function contrast_ratio( float $a, float $b ): float {
		return ( max( $a, $b ) + 0.05 ) / ( min( $a, $b ) + 0.05 );
	}

	/**
	 * プラグイン内のファイルを読み込む。
	 *
	 * @param string $relative_path プラグインルートからの相対パス。
	 * @return string
	 */
	private function read_local_file( string $relative_path ): string {
		$content = file_get_contents( VKBM_PLUGIN_DIR_PATH . $relative_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertIsString( $content, $relative_path . ' を読み込めません。' );

		return $content;
	}
}
