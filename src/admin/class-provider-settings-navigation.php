<?php
/**
 * 基本設定画面（BM 設定 > 基本設定）の設定メニュー（左サイドバーと、狭い幅の開閉式メニュー）を組み立てる。
 *
 * 仕様は docs/specification-provider-settings-navigation.md を参照（issue #1065）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Admin;

use WP_HTML_Tag_Processor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 基本設定画面の設定メニューを組み立てて出力するクラス。
 *
 * 項目のリンク先（?page=vkbm-provider-settings&tab=xxx）・タブのスラッグ・保存フォームは
 * 従来のまま変えず、ナビゲーションの並べ方と見た目だけを担当する。
 */
class Provider_Settings_Navigation {

	/**
	 * 拡張プラグインのリンクを差し込む位置（グループの ID）。
	 */
	private const EXTENSION_GROUP_ID = 'extension';

	/**
	 * 表示中のタブスラッグ。
	 *
	 * @var string
	 */
	private string $active_tab;

	/**
	 * タブのリンク URL を組み立てるための基準 URL。
	 *
	 * @var string
	 */
	private string $base_url;

	/**
	 * 拡張プラグインが vkbm_provider_settings_extra_tabs で出力したリンクの HTML。
	 *
	 * @var string
	 */
	private string $extra_tabs_html;

	/**
	 * 中身が空のグループを除いた、グループの一覧。
	 *
	 * @var array<int,array{id:string,label:string,items:array<int,array{slug:string,label:string}>,accepts_extra_tabs:bool}>
	 */
	private array $groups;

	/**
	 * コンストラクタ。
	 *
	 * @param string $active_tab       表示中のタブスラッグ。
	 * @param string $base_url         タブのリンク URL を組み立てるための基準 URL。
	 * @param bool   $show_integration 「連携」を表示するか。
	 * @param bool   $show_license     「ライセンス」を表示するか。
	 * @param string $extra_tabs_html  拡張プラグインが出力したタブのリンク（get_extra_tabs_html() の戻り値）。
	 */
	public function __construct( string $active_tab, string $base_url, bool $show_integration, bool $show_license, string $extra_tabs_html = '' ) {
		$this->active_tab      = $active_tab;
		$this->base_url        = $base_url;
		$this->extra_tabs_html = $extra_tabs_html;
		$has_extra_tabs        = '' !== trim( $extra_tabs_html );
		$this->groups          = self::get_groups( $show_integration, $show_license, $has_extra_tabs );
	}

	/**
	 * 設定メニューのグループと項目の一覧を返す。中身が空になるグループは含めない。
	 *
	 * 「連携」「ライセンス」は条件付きの項目のため、条件を満たさずに項目が無くなったグループは見出しごと外す。
	 * 拡張プラグインのリンクは「拡張・連携」グループの先頭に差し込む。
	 *
	 * @param bool $show_integration 「連携」を表示するか。
	 * @param bool $show_license     「ライセンス」を表示するか。
	 * @param bool $has_extra_tabs   拡張プラグインのリンクがあるか。
	 * @return array<int,array{id:string,label:string,items:array<int,array{slug:string,label:string}>,accepts_extra_tabs:bool}> グループの一覧。
	 */
	public static function get_groups( bool $show_integration, bool $show_license, bool $has_extra_tabs = false ): array {
		$extension_items = array();
		if ( $show_integration ) {
			$extension_items[] = array(
				'slug'  => 'integration',
				'label' => __( 'Integration', 'vk-booking-manager' ),
			);
		}

		$admin_items = array(
			array(
				'slug'  => 'advanced',
				'label' => __( 'Advanced settings', 'vk-booking-manager' ),
			),
		);
		if ( $show_license ) {
			$admin_items[] = array(
				'slug'  => 'license',
				'label' => __( 'License', 'vk-booking-manager' ),
			);
		}

		$groups = array(
			array(
				'id'                 => 'service',
				'label'              => _x( 'Reservation service', 'settings menu group', 'vk-booking-manager' ),
				'items'              => array(
					array(
						'slug'  => 'store',
						'label' => __( 'Business information', 'vk-booking-manager' ),
					),
					array(
						'slug'  => 'system',
						'label' => __( 'System settings', 'vk-booking-manager' ),
					),
					array(
						'slug'  => 'email',
						'label' => __( 'Email settings', 'vk-booking-manager' ),
					),
					array(
						'slug'  => 'design',
						'label' => __( 'Design settings', 'vk-booking-manager' ),
					),
				),
				'accepts_extra_tabs' => false,
			),
			array(
				'id'                 => 'customer',
				'label'              => _x( 'Customers', 'settings menu group', 'vk-booking-manager' ),
				'items'              => array(
					array(
						'slug'  => 'attendees',
						'label' => __( 'People receiving the service', 'vk-booking-manager' ),
					),
					array(
						'slug'  => 'registration',
						'label' => __( 'User registration settings', 'vk-booking-manager' ),
					),
					array(
						'slug'  => 'consent',
						'label' => __( 'User consent items', 'vk-booking-manager' ),
					),
				),
				'accepts_extra_tabs' => false,
			),
			array(
				'id'                 => self::EXTENSION_GROUP_ID,
				'label'              => _x( 'Extensions and integrations', 'settings menu group', 'vk-booking-manager' ),
				'items'              => $extension_items,
				'accepts_extra_tabs' => true,
			),
			array(
				'id'                 => 'admin',
				'label'              => _x( 'Administration', 'settings menu group', 'vk-booking-manager' ),
				'items'              => $admin_items,
				'accepts_extra_tabs' => false,
			),
			array(
				'id'                 => 'help',
				'label'              => _x( 'Help', 'settings menu group', 'vk-booking-manager' ),
				'items'              => array(
					array(
						'slug'  => 'faq',
						'label' => __( 'FAQ', 'vk-booking-manager' ),
					),
				),
				'accepts_extra_tabs' => false,
			),
		);

		// 項目も拡張プラグインのリンクも無いグループは、見出しごと出さない。
		$visible_groups = array();
		foreach ( $groups as $group ) {
			if ( array() === $group['items'] && ! ( $group['accepts_extra_tabs'] && $has_extra_tabs ) ) {
				continue;
			}
			$visible_groups[] = $group;
		}

		return $visible_groups;
	}

	/**
	 * 拡張プラグインが出力するタブのリンクを取得する。
	 *
	 * vkbm_provider_settings_extra_tabs を 1 回だけ実行して出力を受け取る（サイドバーと狭い幅用のメニューで同じ内容を使い回すため）。
	 * 表示中のリンク（nav-tab-active）には、読み上げソフトへ現在地を伝える aria-current="page" を補う。
	 *
	 * @param string $active_tab 表示中のタブスラッグ。
	 * @param string $base_url   タブのリンク URL を組み立てるための基準 URL。
	 * @return string 拡張プラグインが出力した HTML。何も出力されなければ空文字。
	 */
	public static function get_extra_tabs_html( string $active_tab, string $base_url ): string {
		ob_start();

		/**
		 * 設定ページのナビゲーションに拡張プラグインのタブリンクを挿入する拡張ポイント。
		 * 「拡張・連携」グループの先頭に出力される。
		 * 拡張プラグインはここに <a class="nav-tab"> 要素を出力する（表示中のリンクには nav-tab-active を付ける）。
		 *
		 * @param string $active_tab 表示中のタブスラッグ。
		 * @param string $base_url   タブのリンク URL を組み立てるための基準 URL。
		 */
		do_action( 'vkbm_provider_settings_extra_tabs', $active_tab, $base_url );

		$html = trim( (string) ob_get_clean() );
		if ( '' === $html ) {
			return '';
		}

		// 表示中のリンクに aria-current を補う。
		$processor = new WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag( 'a' ) ) {
			if ( $processor->has_class( 'nav-tab-active' ) && null === $processor->get_attribute( 'aria-current' ) ) {
				$processor->set_attribute( 'aria-current', 'page' );
			}
		}

		return $processor->get_updated_html();
	}

	/**
	 * 表示中の項目の名前を返す（本文上の見出しと、狭い幅のメニューの見出しに使う）。
	 *
	 * @return string 項目名。拡張プラグインのタブはリンクの文字から取り、見つからなければ空文字。
	 */
	public function get_active_label(): string {
		foreach ( $this->groups as $group ) {
			foreach ( $group['items'] as $item ) {
				if ( $item['slug'] === $this->active_tab ) {
					return $item['label'];
				}
			}
		}

		return $this->get_active_extra_tab_label();
	}

	/**
	 * 拡張プラグインのリンクのうち、表示中（a 要素の class に nav-tab-active）のリンクの文字を返す。
	 *
	 * 正規表現ではなく HTML パーサーで a 要素の class を見る（class 以外の属性に同じ文字列があっても現在地と誤認しないため）。
	 *
	 * @return string リンクの文字。表示中のリンクが無ければ空文字。
	 */
	private function get_active_extra_tab_label(): string {
		$processor = new WP_HTML_Tag_Processor( $this->extra_tabs_html );
		$in_active = false;
		$label     = '';

		while ( $processor->next_token() ) {
			$token_name = $processor->get_token_name();

			if ( 'A' === $token_name ) {
				// 開始タグなら現在地かを判定し、終了タグなら文字の収集を終える。
				if ( $processor->is_tag_closer() ) {
					if ( $in_active ) {
						break;
					}
				} else {
					$in_active = $processor->has_class( 'nav-tab-active' );
				}
			} elseif ( $in_active && '#text' === $token_name ) {
				$label .= $processor->get_modifiable_text();
			}
		}

		return trim( (string) preg_replace( '/\s+/u', ' ', $label ) );
	}

	/**
	 * 拡張プラグインのリンクの HTML から id 属性を取り除く。
	 *
	 * サイドバーと狭い幅用のメニューへ同じ HTML を 2 回出力するため、2 つ目から id を除いて重複を防ぐ。
	 *
	 * @param string $html 拡張プラグインのリンクの HTML。
	 * @return string id 属性を除いた HTML。
	 */
	private static function remove_ids( string $html ): string {
		$processor = new WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag() ) {
			$processor->remove_attribute( 'id' );
		}

		return $processor->get_updated_html();
	}

	/**
	 * 狭い幅（1100px 以下）で本文の上に出す、開閉式の設定メニューを出力する。
	 *
	 * 選んだ瞬間に移動するプルダウンではなく、開いてリンクを選ぶ形にする（キーボード操作での誤移動を防ぐ）。
	 *
	 * @return void
	 */
	public function render_mobile_menu(): void {
		?>
		<details class="vkbm-provider-settings__nav-mobile">
			<summary class="vkbm-provider-settings__nav-mobile-summary">
				<?php
				printf(
					/* translators: %s: name of the settings item being displayed. */
					esc_html__( 'Settings menu: %s', 'vk-booking-manager' ),
					'<strong>' . esc_html( $this->get_active_label() ) . '</strong>'
				);
				?>
			</summary>
			<?php $this->render_nav( 'mobile' ); ?>
		</details>
		<?php
	}

	/**
	 * 広い幅で本文の左に出す、設定メニュー（サイドバー）を出力する。
	 *
	 * @return void
	 */
	public function render_sidebar(): void {
		$this->render_nav( 'sidebar' );
	}

	/**
	 * グループ見出しと項目リストを持つ nav 要素を出力する。
	 *
	 * @param string $variant 'sidebar'（広い幅の左サイドバー）または 'mobile'（狭い幅の開閉式メニューの中身）。見出しの id を重複させないための接頭辞にも使う。
	 * @return void
	 */
	private function render_nav( string $variant ): void {
		?>
		<nav class="vkbm-provider-settings__nav vkbm-provider-settings__nav--<?php echo esc_attr( $variant ); ?>" aria-label="<?php esc_attr_e( 'Settings menu', 'vk-booking-manager' ); ?>">
			<?php foreach ( $this->groups as $group ) : ?>
				<?php $title_id = 'vkbm-provider-settings-nav-' . $variant . '-' . $group['id']; ?>
				<div class="vkbm-provider-settings__nav-group">
					<p class="vkbm-provider-settings__nav-group-title" id="<?php echo esc_attr( $title_id ); ?>"><?php echo esc_html( $group['label'] ); ?></p>
					<ul class="vkbm-provider-settings__nav-list" aria-labelledby="<?php echo esc_attr( $title_id ); ?>">
						<?php if ( $group['accepts_extra_tabs'] && '' !== trim( $this->extra_tabs_html ) ) : ?>
							<li class="vkbm-provider-settings__nav-extra">
								<?php echo 'mobile' === $variant ? self::remove_ids( $this->extra_tabs_html ) : $this->extra_tabs_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 拡張プラグインが各自エスケープして出力した HTML。 ?>
							</li>
						<?php endif; ?>
						<?php foreach ( $group['items'] as $item ) : ?>
							<li>
								<a
									href="<?php echo esc_url( add_query_arg( 'tab', $item['slug'], $this->base_url ) ); ?>"
									class="vkbm-provider-settings__nav-link"
									<?php echo $item['slug'] === $this->active_tab ? 'aria-current="page"' : ''; ?>
								>
									<?php echo esc_html( $item['label'] ); ?>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endforeach; ?>
		</nav>
		<?php
	}
}
