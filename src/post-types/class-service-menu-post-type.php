<?php
/**
 * Registers the Service Menu custom post type.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\PostTypes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\Assets\Common_Styles;
use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Common\Price_Tiers;
use VKBookingManager\Common\Reservation_Day;
use VKBookingManager\Common\VKBM_Helper;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use VKBookingManager\TermOrder\Term_Order_Manager;
use WP_Post;
use function add_action;
use function current_user_can;
use function get_current_screen;
use function get_post;
use function get_term_meta;
use function get_the_terms;
use function is_wp_error;
use function register_post_meta;
use function register_rest_field;
use function wp_enqueue_script;
use function wp_enqueue_style;

/**
 * Registers the Service Menu custom post type and related taxonomy.
 */
class Service_Menu_Post_Type {
	public const POST_TYPE                         = 'vkbm_service_menu';
	public const TAXONOMY                          = 'vkbm_service_menu_tag';
	public const TAXONOMY_GROUP                    = 'vkbm_service_menu_group';
	private const TERM_GROUP_DISPLAY_MODE_META_KEY = 'vkbm_menu_group_display_mode';
	private const META_OTHER_CONDITIONS            = '_vkbm_other_conditions';
	private const META_STAFF_IDS                   = '_vkbm_staff_ids';
	/**
	 * 「すべてのリソースが担当できる」フラグのメタキー（#485）。
	 *
	 * true のとき、担当できるリソースは個別選択（META_STAFF_IDS）ではなく
	 * 「公開中の全リソース」になる（今後追加するリソースも自動的に対象）。
	 * 未設定（既定）は false ＝「担当できるリソースを選ぶ」で、従来どおり
	 * META_STAFF_IDS の個別選択を使う。個別選択の配列は「すべて」選択中も
	 * 消さずに保持する（「選ぶ」へ戻したときに以前の選択を復元できるようにするため。
	 * #391・#412 の「親スイッチ OFF 時も子設定を残す」方針と同じ）。
	 * 空配列（未設定）の意味は変えない（「すべて」とは別）。
	 *
	 * Service_Menu_Editor など他クラスからも参照するため public にしている。
	 */
	public const META_STAFF_ALL               = '_vkbm_staff_all';
	private const META_RESERVATION_DAY_TYPE   = '_vkbm_reservation_day_type';
	private const META_DISABLE_NOMINATION_FEE = '_vkbm_disable_nomination_fee';
	// メニュー単位で指名機能を無効化するメタキー（#391）。既定（未設定）は「指名を使う」。
	private const META_DISABLE_NOMINATION          = '_vkbm_disable_nomination';
	private const META_MAX_CAPACITY                = '_vkbm_max_capacity';
	private const META_MIN_CAPACITY                = '_vkbm_min_capacity';
	private const META_ALLOW_MULTIPLE_GUESTS       = '_vkbm_allow_multiple_guests';
	private const META_EXCLUSIVE_WHEN_BOOKED       = '_vkbm_exclusive_when_booked';
	private const META_EXCLUSIVE_USER_SELECTABLE   = '_vkbm_exclusive_user_selectable';
	private const META_EXCLUSIVE_FEE_PER_PERSON    = '_vkbm_exclusive_fee_per_person';
	private const META_EXCLUSIVE_FEE_EXEMPT_GUESTS = '_vkbm_exclusive_fee_exempt_guests';
	private const META_PRICE_TIERS                 = '_vkbm_price_tiers';

	/**
	 * Staff title cache.
	 *
	 * @var array<int, string>
	 */
	private array $staff_title_cache = array();

	/**
	 * Availability service（遅延生成）。
	 *
	 * get_nomination_min_guests_rest_field() が REST レスポンスのメニュー1件ごとに
	 * 呼ばれるため、その都度 `new Availability_Service()`（内部で `new Settings_Repository()`
	 * も生成）していると、予約ブロックの `per_page: 100` 取得で1リクエストあたり最大100個
	 * 生成されてしまう（安藤レビュー指摘）。get_availability_service() で1個だけ生成して
	 * 使い回す。
	 *
	 * @var Availability_Service|null
	 */
	private ?Availability_Service $availability_service = null;

	/**
	 * 公開中リソースIDのリクエスト内キャッシュ（#485）。
	 *
	 * 「すべて」フラグのメニューごとに get_posts() を発行すると、予約ブロックの
	 * REST 取得（メニュー最大100件）で1リクエストあたり最大100回クエリが走るため、
	 * 1リクエスト内では1回だけ取得して使い回す。
	 * 投稿の追加・更新・削除で無効化できるよう、取得時点の
	 * wp_cache_get_last_changed( 'posts' ) を併せて保持し、値が変わっていれば再取得する
	 * （テスト中や同一リクエスト内でリソースを公開した直後でも古い一覧を返さないため）。
	 *
	 * @var array{last_changed: string, ids: array<int>}|null
	 */
	private static ?array $published_resource_ids_cache = null;

	/**
	 * Hook registrations for the post type and taxonomy.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'init', array( $this, 'register_taxonomy' ) );
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_fields' ) );
		// カスタムメタもリビジョンに保存・復元できるよう、対象メタキーを登録する。
		add_filter( 'wp_post_revision_meta_keys', array( $this, 'filter_revision_meta_keys' ), 10, 2 );
		add_action( self::TAXONOMY_GROUP . '_add_form_fields', array( $this, 'render_group_term_add_fields' ) );
		add_action( self::TAXONOMY_GROUP . '_edit_form_fields', array( $this, 'render_group_term_edit_fields' ), 10, 2 );
		add_action( 'created_' . self::TAXONOMY_GROUP, array( $this, 'save_group_term_display_mode' ) );
		add_action( 'edited_' . self::TAXONOMY_GROUP, array( $this, 'save_group_term_display_mode' ) );
		add_filter( 'manage_edit-' . self::POST_TYPE . '_columns', array( $this, 'filter_admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'render_admin_columns' ), 10, 2 );
		add_action( 'quick_edit_custom_box', array( $this, 'render_quick_edit_fields' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_quick_edit_assets' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_quick_edit' ), 20, 3 );
	}

	/**
	 * Register the Service Menu custom post type.
	 */
	public function register_post_type(): void {
		if ( post_type_exists( self::POST_TYPE ) ) {
			return;
		}

		$labels = array(
			'name'                  => __( 'Service Menu', 'vk-booking-manager' ),
			'singular_name'         => __( 'Service Menu', 'vk-booking-manager' ),
			'menu_name'             => __( 'BM Service', 'vk-booking-manager' ),
			'name_admin_bar'        => __( 'Service Menu', 'vk-booking-manager' ),
			'add_new'               => __( 'New addition', 'vk-booking-manager' ),
			'add_new_item'          => __( 'Add service', 'vk-booking-manager' ),
			'edit_item'             => __( 'Edit service menu', 'vk-booking-manager' ),
			'new_item'              => __( 'New service menu', 'vk-booking-manager' ),
			'view_item'             => __( 'Display service menu', 'vk-booking-manager' ),
			'search_items'          => __( 'Search service menu', 'vk-booking-manager' ),
			'not_found'             => __( 'Service menu not found.', 'vk-booking-manager' ),
			'not_found_in_trash'    => __( 'There is no service menu in the trash can.', 'vk-booking-manager' ),
			'all_items'             => __( 'All services', 'vk-booking-manager' ),
			'archives'              => __( 'Service menu archive', 'vk-booking-manager' ),
			'attributes'            => __( 'Service menu attributes', 'vk-booking-manager' ),
			'insert_into_item'      => __( 'Insert into service menu', 'vk-booking-manager' ),
			'uploaded_to_this_item' => __( 'Upload to this service menu', 'vk-booking-manager' ),
		);

		$args = array(
			'labels'              => $labels,
			'public'              => true,
			'publicly_queryable'  => true,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_admin_bar'   => true,
			'show_in_nav_menus'   => false,
			'show_in_rest'        => true,
			'supports'            => array( 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields', 'revisions' ),
			'has_archive'         => false,
			'hierarchical'        => false,
			'rewrite'             => false,
			'menu_position'       => 25,
			'menu_icon'           => 'dashicons-clipboard',
			'capabilities'        => $this->get_post_type_capabilities(),
			'map_meta_cap'        => false,
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Add custom columns to the admin list table.
	 *
	 * @param array<string, string> $columns Current column definitions.
	 * @return array<string, string>
	 */
	public function filter_admin_columns( array $columns ): array {
		if ( empty( $columns['title'] ) ) {
			return $columns;
		}

		$reordered = array();
		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				continue;
			}

			$reordered[ $key ] = $label;

			if ( 'title' !== $key ) {
				continue;
			}

			$reordered['vkbm_price'] = __( 'Fee', 'vk-booking-manager' );
			// Use the configurable duration label from provider settings.
			// 基本設定の所要時間ラベルを使用する。
			// 出力先（列見出し）は vkbm_staff と同じ未エスケープの sink だが、このラベルのエスケープ有無は
			// 今回（#485）のスコープ外のため未エスケープのまま据え置く（安藤レビュー LOW）。
			$reordered['vkbm_duration']             = vkbm_get_duration_label();
			$reordered['vkbm_reservation_deadline'] = __( 'Reservation deadline', 'vk-booking-manager' );
			$reordered['vkbm_buffer_after']         = __( 'Post-service buffer', 'vk-booking-manager' );
			if ( Staff_Editor::is_enabled() ) {
				// スタッフ編集が有効な場合のみ担当リソース列を表示する。見出しは基本設定のリソースラベル（複数形）を使う（#485）。
				// 列見出しは WordPress 本体が未エスケープで出力するため、利用者入力由来のラベルはここでエスケープする。
				$reordered['vkbm_staff'] = esc_html( self::get_available_staff_label() );
			}
			// Use the configurable other conditions label from provider settings.
			// 基本設定のその他条件ラベルを使用する。
			// vkbm_duration と同じく、このラベルも同じ sink だが今回（#485）のスコープ外のため未エスケープのまま据え置く。
			$reordered['vkbm_other_conditions']     = vkbm_get_other_conditions_label();
			$reordered['vkbm_reservation_day_type'] = __( 'Reservation date', 'vk-booking-manager' );
		}

		return $reordered;
	}

	/**
	 * Render custom admin columns for the list table.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Current post ID.
	 */
	public function render_admin_columns( string $column, int $post_id ): void {
		$price                          = (int) get_post_meta( $post_id, '_vkbm_base_price', true );
		$duration                       = (int) get_post_meta( $post_id, '_vkbm_duration_minutes', true );
		$buffer_meta                    = get_post_meta( $post_id, '_vkbm_buffer_after_minutes', true );
		$buffer_has_value               = '' !== $buffer_meta || metadata_exists( 'post', $post_id, '_vkbm_buffer_after_minutes' );
		$buffer                         = $buffer_has_value ? (int) $buffer_meta : 0;
		$reservation_deadline_meta      = get_post_meta( $post_id, '_vkbm_reservation_deadline_hours', true );
		$reservation_deadline_has_value = '' !== $reservation_deadline_meta || metadata_exists( 'post', $post_id, '_vkbm_reservation_deadline_hours' );
		$reservation_deadline           = $reservation_deadline_has_value ? (int) $reservation_deadline_meta : 0;
		// 個別選択の担当リソース（保存値そのもの）。クイック編集のプリフィルにも使うため、
		// 「すべて」フラグで展開した候補ではなく保存されている個別選択を読む。
		$staff_ids = self::get_selected_staff_ids( $post_id );
		// 「すべてのリソースが担当できる」フラグ（#485）。
		$is_all_staff           = self::is_all_staff_assigned( $post_id );
		$other_conditions       = get_post_meta( $post_id, self::META_OTHER_CONDITIONS, true );
		$other_conditions       = is_string( $other_conditions ) ? $other_conditions : '';
		$reservation_day_type   = (string) get_post_meta( $post_id, self::META_RESERVATION_DAY_TYPE, true );
		$disable_nomination_fee = (string) get_post_meta( $post_id, self::META_DISABLE_NOMINATION_FEE, true );
		// #515: 「料金区分で設定されているメニューか」の判定は、公開側メニューカードと共通の
		// Price_Tiers::is_menu_using_price_tiers() を使う（一覧の料金列・クイック編集の注記の両方で使用）。
		$uses_price_tiers = Price_Tiers::is_menu_using_price_tiers( $post_id );
		// クイック編集の「料金区分を編集」リンク先（編集画面の料金区分欄）。区分で設定されている
		// メニューのみ組み立てる（それ以外は空のまま。JS 側は uses-price-tiers が空なら参照しない）。
		$price_tiers_edit_url = '';
		if ( $uses_price_tiers ) {
			$edit_link = get_edit_post_link( $post_id, 'raw' );
			if ( is_string( $edit_link ) && '' !== $edit_link ) {
				// この値は data 属性として一旦保持し、出力時に render_quick_edit_data_span() の
				// esc_attr() で改めてエスケープする（DB 保存やさらなる加工を経る値と同様、組み立て時は
				// esc_url() ではなく esc_url_raw() を使う。安藤レビュー指摘）。
				$price_tiers_edit_url = esc_url_raw( $edit_link . '#vkbm-price-tiers-field' );
			}
		}

		// クイック編集（service-menu-quick-edit.js）がプリフィルに使う data 属性の値。
		// 各列に同じ span を出力するため、値の組み立てと出力（render_quick_edit_data_span）を1か所にまとめる。
		$quick_edit_data = array(
			'base-price'                 => $price > 0 ? (string) $price : '',
			'duration-minutes'           => $duration > 0 ? (string) $duration : '',
			'buffer-after-minutes'       => $buffer_has_value ? (string) $buffer : '',
			'staff-ids'                  => (string) wp_json_encode( $staff_ids ),
			'staff-all'                  => $is_all_staff ? '1' : '',
			'other-conditions'           => (string) wp_json_encode( $other_conditions ),
			'reservation-deadline-hours' => $reservation_deadline_has_value ? (string) $reservation_deadline : '',
			'reservation-day-type'       => $reservation_day_type,
			'disable-nomination-fee'     => '1' === $disable_nomination_fee ? '1' : '',
			'uses-price-tiers'           => $uses_price_tiers ? '1' : '',
			'price-tiers-edit-url'       => $price_tiers_edit_url,
		);

		switch ( $column ) {
			case 'vkbm_price':
				if ( $uses_price_tiers ) {
					// #515: 料金区分で設定されているメニューは、基本料金の代わりに区分を全件
					// 「区分名: 料金」で縦に表示する（省略しない）。0円の区分も「0」と表示し「—」にはしない。
					$tiers = Price_Tiers::normalize_tiers( get_post_meta( $post_id, self::META_PRICE_TIERS, true ) );
					$rows  = array();
					foreach ( $tiers as $tier ) {
						$rows[] = sprintf(
							'<li>%1$s: <span class="vkbm-admin-price-tiers-price">%2$s</span></li>',
							esc_html( $tier['label'] ),
							esc_html( number_format_i18n( $tier['price'] ) )
						);
					}
					echo '<span class="screen-reader-text">' . esc_html__( 'Price categories', 'vk-booking-manager' ) . '</span>';
					echo wp_kses_post( '<ul class="vkbm-admin-price-tiers-list">' . implode( '', $rows ) . '</ul>' );
				} else {
					echo esc_html( $price > 0 ? number_format_i18n( $price ) : '—' );
				}
				$this->render_quick_edit_data_span( $quick_edit_data );
				break;

			case 'vkbm_duration':
				echo esc_html(
					$duration > 0
						? sprintf(
							/* translators: %s: minutes. */
							__( '%s minutes', 'vk-booking-manager' ),
							number_format_i18n( $duration )
						)
						: '—'
				);
				$this->render_quick_edit_data_span( $quick_edit_data );
				break;

			case 'vkbm_reservation_deadline':
				$effective_deadline = $reservation_deadline_has_value
					? $reservation_deadline
					: $this->get_provider_reservation_deadline_default();
				echo esc_html(
					sprintf(
						/* translators: %s: hours. */
						__( '%s hours ago', 'vk-booking-manager' ),
						number_format_i18n( $effective_deadline )
					)
				);
				$this->render_quick_edit_data_span( $quick_edit_data );
				break;

			case 'vkbm_buffer_after':
				$effective_buffer = $buffer_has_value ? $buffer : $this->get_provider_buffer_after_default();
				echo esc_html(
					sprintf(
						/* translators: %s: buffer minutes. */
						__( '%s minutes', 'vk-booking-manager' ),
						number_format_i18n( $effective_buffer )
					)
				);
				$this->render_quick_edit_data_span( $quick_edit_data );
				break;

			case 'vkbm_staff':
				if ( ! Staff_Editor::is_enabled() ) {
					return;
				}

				// 「すべて」のメニューは名前を並べず1語で表示する（#485。人数が増えても列が延々と長くならないようにするため）。
				if ( $is_all_staff ) {
					echo esc_html( self::get_all_staff_label() );
					break;
				}

				if ( empty( $staff_ids ) ) {
					echo esc_html( '—' );
					break;
				}

				$staff_posts = get_posts(
					array(
						'post_type'      => Resource_Post_Type::POST_TYPE,
						'post_status'    => array( 'publish' ),
						'posts_per_page' => -1,
						'orderby'        => array(
							'menu_order' => 'ASC',
							'title'      => 'ASC',
						),
						'include'        => $staff_ids,
					)
				);

				$names = array_values(
					array_filter(
						array_map(
							static function ( WP_Post $post ): string {
								return get_the_title( $post );
							},
							array_filter(
								$staff_posts,
								static function ( $post ): bool {
									return $post instanceof WP_Post;
								}
							)
						),
						static function ( string $name ): bool {
							return '' !== $name;
						}
					)
				);

				echo $names ? wp_kses_post( implode( '<br>', array_map( 'esc_html', $names ) ) ) : esc_html( '—' );
				break;

			case 'vkbm_other_conditions':
				if ( '' === $other_conditions ) {
					echo esc_html( '—' );
				} else {
					$excerpt = wp_html_excerpt( wp_strip_all_tags( $other_conditions ), 80, '…' );
					echo esc_html( $excerpt );
				}
				$this->render_quick_edit_data_span( $quick_edit_data );
				break;
			case 'vkbm_reservation_day_type':
				if ( '' === $reservation_day_type ) {
					echo esc_html( '—' );
					break;
				}

				// 種別→ラベルの写像は共有ヘルパーに集約している（曜日指定・日付指定にも対応）。
				echo esc_html( Reservation_Day::label( $reservation_day_type ) );
				break;
		}
	}

	/**
	 * Retrieve the provider's default buffer setting.
	 *
	 * @return int
	 */
	private function get_provider_buffer_after_default(): int {
		static $default = null;

		if ( null !== $default ) {
			return $default;
		}

		$repository = new Settings_Repository();
		$settings   = $repository->get_settings();
		$default    = isset( $settings['provider_service_menu_buffer_after_minutes'] ) ? (int) $settings['provider_service_menu_buffer_after_minutes'] : 0;

		return $default;
	}

	/**
	 * Retrieve the provider's default reservation deadline.
	 *
	 * @return int
	 */
	private function get_provider_reservation_deadline_default(): int {
		static $default = null;

		if ( null !== $default ) {
			return $default;
		}

		$repository = new Settings_Repository();
		$settings   = $repository->get_settings();
		$default    = isset( $settings['provider_reservation_deadline_hours'] ) ? (int) $settings['provider_reservation_deadline_hours'] : 0;

		return $default;
	}

	/**
	 * Resolve staff title from cache.
	 *
	 * @param int $staff_id Staff post ID.
	 * @return string
	 */
	private function get_staff_title( int $staff_id ): string {
		if ( isset( $this->staff_title_cache[ $staff_id ] ) ) {
			return $this->staff_title_cache[ $staff_id ];
		}

		$post = get_post( $staff_id );
		if ( ! $post instanceof WP_Post ) {
			$this->staff_title_cache[ $staff_id ] = '';
			return '';
		}

		$this->staff_title_cache[ $staff_id ] = vkbm_get_resource_display_name( $staff_id );

		return $this->staff_title_cache[ $staff_id ];
	}

	/**
	 * Render custom fields for Quick Edit.
	 *
	 * @param string $column_name Column key.
	 * @param string $post_type   Post type.
	 */
	public function render_quick_edit_fields( string $column_name, string $post_type ): void {
		if ( self::POST_TYPE !== $post_type ) {
			return;
		}

		// Render once (WordPress calls this per visible custom column).
		static $rendered = false;
		if ( $rendered ) {
			return;
		}

		$columns = array( 'vkbm_price', 'vkbm_duration', 'vkbm_buffer_after', 'vkbm_other_conditions' );
		if ( Staff_Editor::is_enabled() ) {
			$columns[] = 'vkbm_staff';
		}
		if ( ! in_array( $column_name, $columns, true ) ) {
			return;
		}

		$rendered = true;

		$staff_posts = array();
		if ( Staff_Editor::is_enabled() ) {
			$staff_posts = get_posts(
				array(
					'post_type'      => Resource_Post_Type::POST_TYPE,
					'post_status'    => array( 'publish' ),
					'posts_per_page' => -1,
					'orderby'        => array(
						'menu_order' => 'ASC',
						'title'      => 'ASC',
					),
				)
			);
		}
		$tax_label = VKBM_Helper::get_tax_included_label();

		wp_nonce_field( 'vkbm_service_menu_quick_edit', '_vkbm_service_menu_quick_nonce' );
		?>
			<fieldset class="inline-edit-col-right vkbm-service-menu-quick-edit">
				<div class="inline-edit-col">
					<div class="inline-edit-group">
						<label>
							<?php
							/**
							 * #515 レビュー対応（植草）: ラベルが「Price」から「Basic price」に伸び、
							 * 税込ラベル付きだと「Basic price (tax included)」相当になる。他のラベルと
							 * 同じ固定10emの列幅・nowrapのままだと入力欄に重なるおそれがあるため、
							 * このラベルだけ vkbm-qe-base-price-title を付けて折り返しを許容する
							 * （design-rules「日本語で10文字を超える場合はnowrap必須ではない」と同じ考え方を
							 * 英語ラベルにも適用）。折り返しても列幅（10em）の内側で改行されるだけなので、
							 * 入力欄への重なりは起きない。
							 */
							?>
							<span class="title vkbm-qe-base-price-title">
								<?php
								esc_html_e( 'Basic price', 'vk-booking-manager' );
								if ( '' !== $tax_label ) {
									echo ' ' . esc_html( $tax_label );
								}
								?>
							</span>
							<span class="input-text-wrap">
								<?php
								/**
								 * #515: 区分で設定されているメニューでも、この基本料金欄は disabled/readonly に
								 * しない。空送信すると save_quick_edit() -> update_meta_value() が
								 * _vkbm_base_price メタごと削除してしまうため（disabled/readonly は値を送信
								 * しないか、readonly でも意図に反して編集を制限するため）。「基本料金は使われ
								 * ない」旨は下の注記（vkbm-qe-price-tiers-notice）で伝え、入力欄自体は常に
								 * 編集可能なままにする。
								 *
								 * aria-describedby はここでは固定で付けない。行ごとに区分の有無が変わるため、
								 * PHP側で常時付けると区分を使っていない行でも「非表示の注記」を指したままになる
								 * （hidden 要素への aria-describedby はスクリーンリーダーの実装によって扱いが
								 * 割れるため、確実に無くす）。JS（service-menu-quick-edit.js）が
								 * usesPriceTiers の値に応じて行ごとに付け外しする（安藤レビュー指摘）。
								 */
								?>
								<input type="number" name="vkbm_service_menu_quick[base_price]" class="vkbm-qe-base-price" min="0" step="1" value="" />
							</span>
						</label>
						<?php
						/**
						 * #515: 料金区分で設定されているメニューの行だけ、JS
						 * （service-menu-quick-edit.js）が hidden を外して表示する注記とリンク。
						 * label の外に置く理由・aria-describedby の使い方は、直後のバッファ説明文と同じ
						 * （label 内に置くとスクリーンリーダーが入力欄名にリンク文言まで連結して読み上げる）。
						 * リンク href は行ごとに異なる編集画面URLのため、初期値は "#" とし JS が
						 * data-price-tiers-edit-url から差し込む。
						 *
						 * 説明文とリンクは `<br>` で余白を作らず（design-rules の「`<br />` で余白を作らない」）、
						 * 2つの `<p>` に分けて CSS のマージン（vkbm-qe-buffer-after-description と同じ手法）で
						 * 分離する。aria-describedby の対象は外側の div の id 1つのまま。`hidden` 属性自体は
						 * 読み上げを止めない（aria-describedby で参照された文はスクリーンリーダーに読み上げ
						 * られる）ため、区分を使わない行では JS（service-menu-quick-edit.js）が基本料金欄の
						 * aria-describedby を外し、この注記が読み上げられないようにする（安藤レビュー指摘）。
						 */
						?>
						<div class="description vkbm-qe-price-tiers-notice" id="vkbm-qe-price-tiers-notice" hidden>
							<p class="vkbm-qe-price-tiers-notice-text"><?php esc_html_e( 'This menu uses price categories, so the basic price is not used for bookings.', 'vk-booking-manager' ); ?></p>
							<p class="vkbm-qe-price-tiers-notice-link"><a href="#" class="vkbm-qe-price-tiers-link" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Edit price categories', 'vk-booking-manager' ); ?></a></p>
						</div>
						<?php
						// #391: クイック編集はテンプレートを一覧ページに1回だけ描画し、JS側で行ごとの
						// データ属性を差し込む方式のため、この時点では対象の特定メニューIDが無い
						// （どの行に適用されるか未確定）。メニュー単位の判定はできないため、
						// サイト全体の指名機能スイッチのみで表示可否を決める（従来どおり）。
						?>
						<?php if ( Staff_Editor::is_nomination_enabled() ) : ?>
						<label>
							<span class="title"><?php echo esc_html( vkbm_get_nomination_fee_label() ); ?></span>
							<span class="input-text-wrap">
								<label>
									<input type="checkbox" name="vkbm_service_menu_quick[disable_nomination_fee]" class="vkbm-qe-disable-nomination-fee" value="1" />
									<?php esc_html_e( 'Disable for this service menu', 'vk-booking-manager' ); ?>
								</label>
								<?php
								// #412 B-5: クイック編集はメニュー単位で指名を無効化しているかどうかを
								// 判定できないため（上記コメント参照）、このチェックはメニュー単位で
								// 指名を使っていない場合は意味を持たないことを注記する。
								?>
								<p class="description"><?php esc_html_e( 'Ignored if this service menu does not use staff nomination.', 'vk-booking-manager' ); ?></p>
							</span>
						</label>
						<?php endif; ?>
						<label>
							<span class="title"><?php echo esc_html( vkbm_get_duration_label() ); ?></span>
							<span class="input-text-wrap">
								<input type="number" name="vkbm_service_menu_quick[duration_minutes]" class="vkbm-qe-duration-minutes" min="0" step="1" value="" /> <?php esc_html_e( 'minutes', 'vk-booking-manager' ); ?>
							</span>
						</label>
						<label>
							<span class="title"><?php esc_html_e( 'Reservation deadline', 'vk-booking-manager' ); ?></span>
							<span class="input-text-wrap">
								<input type="number" name="vkbm_service_menu_quick[reservation_deadline_hours]" class="vkbm-qe-reservation-deadline-hours" min="0" step="1" value="" /> <?php esc_html_e( 'hours ago', 'vk-booking-manager' ); ?>
							</span>
						</label>
						<label>
							<span class="title"><?php esc_html_e( 'Post-service buffer', 'vk-booking-manager' ); ?></span>
							<span class="input-text-wrap">
								<input type="number" name="vkbm_service_menu_quick[buffer_after_minutes]" class="vkbm-qe-buffer-after-minutes" min="0" step="1" value="" aria-describedby="vkbm-qe-buffer-after-minutes-description" /> <?php esc_html_e( 'minutes', 'vk-booking-manager' ); ?>
							</span>
						</label>
						<?php
						/**
						 * この説明文（リンク付き）は input と紐付く label の内側には置かない。
						 * label 内に置くと、スクリーンリーダーが入力欄の名前としてリンク文言まで
						 * 連結して読み上げてしまう。
						 * label の外に出しつつ、aria-describedby で入力欄と説明文の関連付けを保つ。
						 */
						?>
						<p class="description vkbm-qe-buffer-after-description" id="vkbm-qe-buffer-after-minutes-description">
							<?php
							// 未記入の場合の説明（基本設定画面の該当欄へのリンク付き）。
							printf(
								/* translators: %s: link to the post-service buffer setting on the provider settings page. */
								esc_html__( 'If it is left blank, the information entered on %s will be reflected.', 'vk-booking-manager' ),
								sprintf(
									'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
									esc_url( admin_url( 'admin.php?page=vkbm-provider-settings&tab=system#vkbm-service-menu-buffer-after-default' ) ),
									esc_html__( 'Post-service buffer on the General Settings page', 'vk-booking-manager' )
								)
							);
							?>
						</p>
						<?php if ( Staff_Editor::is_enabled() ) : ?>
							<?php
							// 担当できるリソース（#485）。「すべて」「選ぶ」のラジオ2択にし、「選ぶ」のときだけ
							// 個別チェックリストを表示する（表示切替は service-menu-quick-edit.js。編集画面と同じ作法）。
							// クイック編集は行 DOM を投稿間で使い回すため、id ではなく class と name で扱い、
							// 現在値のプリフィルは JS が data-staff-all / data-staff-ids から行う。
							$quick_edit_staff_label = self::get_available_staff_label();
							?>
							<?php
							// role="group" + aria-label で、ラジオ2つとチェックリストの集合が「担当できるリソース」の
							// グループであることを読み上げに伝える（メタボックス側の fieldset + legend と同等。植草レビュー）。
							// fieldset + legend にしないのは、.vkbm-qe-staff に display: grid を当てているため legend が
							// グリッド項目にならず 10em + 1fr の2列が崩れるため。
							?>
							<div class="vkbm-qe-staff" role="group" aria-label="<?php echo esc_attr( $quick_edit_staff_label ); ?>">
								<span class="title"><?php echo esc_html( $quick_edit_staff_label ); ?></span>
								<span class="input-text-wrap">
									<label class="vkbm-qe-staff-mode">
										<input type="radio" name="vkbm_service_menu_quick[staff_all]" class="vkbm-qe-staff-all" value="1" />
										<?php echo esc_html( self::get_all_staff_option_label() ); ?>
									</label>
									<label class="vkbm-qe-staff-mode">
										<input type="radio" name="vkbm_service_menu_quick[staff_all]" class="vkbm-qe-staff-all" value="0" checked />
										<?php echo esc_html( self::get_choose_staff_option_label() ); ?>
									</label>
									<ul class="vkbm-qe-staff-checkboxes">
										<?php foreach ( $staff_posts as $staff_post ) : ?>
											<?php if ( ! $staff_post instanceof WP_Post ) : ?>
												<?php continue; ?>
											<?php endif; ?>
											<li>
												<label>
													<input type="checkbox" name="vkbm_service_menu_quick[staff_ids][]" class="vkbm-qe-staff-id" value="<?php echo esc_attr( (string) $staff_post->ID ); ?>" />
													<?php echo esc_html( get_the_title( $staff_post ) ); ?>
												</label>
											</li>
										<?php endforeach; ?>
									</ul>
								</span>
							</div>
						<?php endif; ?>
						<label>
							<span class="title"><?php echo esc_html( vkbm_get_other_conditions_label() ); ?></span>
							<span class="input-text-wrap">
								<textarea name="vkbm_service_menu_quick[other_conditions]" class="vkbm-qe-other-conditions" rows="6"></textarea>
							</span>
						</label>
						<label>
							<span class="title"><?php esc_html_e( 'Reservation date', 'vk-booking-manager' ); ?></span>
							<span class="input-text-wrap">
								<select name="vkbm_service_menu_quick[reservation_day_type]" class="vkbm-qe-reservation-day-type">
									<option value=""><?php esc_html_e( 'Not specified', 'vk-booking-manager' ); ?></option>
									<option value="weekend"><?php esc_html_e( 'Saturdays and Sundays only', 'vk-booking-manager' ); ?></option>
									<option value="weekday"><?php esc_html_e( 'Weekdays only', 'vk-booking-manager' ); ?></option>
									<?php
									// 曜日指定・日付指定は詳細設定が必要でクイック編集では設定できない。
									// 既定は disabled にして新規選択を防ぎ（詳細未設定のまま選ぶと保存時に無通知で
									// 指定なしへ戻るのを防ぐ）、現在値が custom のメニューでのみ JS で有効化して
									// 既存値を表示・保持できるようにする。title で理由を説明する。
									$custom_option_title = esc_attr__( 'This option requires detailed settings. Edit it in the full service menu editor.', 'vk-booking-manager' );
									?>
									<option value="custom_weekday" disabled title="<?php echo esc_attr( $custom_option_title ); ?>"><?php esc_html_e( 'Specified days of the week', 'vk-booking-manager' ); ?></option>
									<option value="custom_date" disabled title="<?php echo esc_attr( $custom_option_title ); ?>"><?php esc_html_e( 'Specified dates', 'vk-booking-manager' ); ?></option>
								</select>
							</span>
						</label>
					</div>
				</div>
			</fieldset>
			<?php
	}

	/**
	 * Enqueue Quick Edit JS for service menu list table.
	 *
	 * @param string $hook_suffix Current admin hook.
	 */
	public function enqueue_quick_edit_assets( string $hook_suffix ): void {
		if ( 'edit.php' !== $hook_suffix ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style( Common_Styles::ADMIN_HANDLE );

		wp_enqueue_script(
			'vkbm-service-menu-quick-edit',
			VKBM_PLUGIN_DIR_URL . 'assets/js/service-menu-quick-edit.js',
			array( 'jquery', 'inline-edit-post' ),
			VKBM_VERSION,
			true
		);
	}

	/**
	 * Persist Quick Edit submissions (price/duration/buffer).
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post instance.
	 * @param bool    $update  Whether this is an existing post being updated.
	 */
	public function save_quick_edit( int $post_id, WP_Post $post, bool $update ): void {
		if ( ! $update ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( self::POST_TYPE !== $post->post_type ) {
			return;
		}

		if ( ! isset( $_POST['_vkbm_service_menu_quick_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verification just below.
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['_vkbm_service_menu_quick_nonce'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified below.
		if ( ! wp_verify_nonce( $nonce, 'vkbm_service_menu_quick_edit' ) ) {
			return;
		}

		if ( ! current_user_can( Capabilities::MANAGE_SERVICE_MENUS, $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['vkbm_service_menu_quick'] ) || ! is_array( $_POST['vkbm_service_menu_quick'] ) ) {
			return;
		}

		$base_price           = $this->sanitize_numeric_value( isset( $_POST['vkbm_service_menu_quick']['base_price'] ) ? sanitize_text_field( wp_unslash( $_POST['vkbm_service_menu_quick']['base_price'] ) ) : '' );
		$duration             = $this->sanitize_numeric_value( isset( $_POST['vkbm_service_menu_quick']['duration_minutes'] ) ? sanitize_text_field( wp_unslash( $_POST['vkbm_service_menu_quick']['duration_minutes'] ) ) : '' );
		$buffer_after         = $this->sanitize_numeric_value( isset( $_POST['vkbm_service_menu_quick']['buffer_after_minutes'] ) ? sanitize_text_field( wp_unslash( $_POST['vkbm_service_menu_quick']['buffer_after_minutes'] ) ) : '' );
		$reservation_deadline = $this->sanitize_numeric_value( isset( $_POST['vkbm_service_menu_quick']['reservation_deadline_hours'] ) ? sanitize_text_field( wp_unslash( $_POST['vkbm_service_menu_quick']['reservation_deadline_hours'] ) ) : '' );
		$staff_ids            = Staff_Editor::is_enabled() ? $this->sanitize_staff_ids( isset( $_POST['vkbm_service_menu_quick']['staff_ids'] ) && is_array( $_POST['vkbm_service_menu_quick']['staff_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['vkbm_service_menu_quick']['staff_ids'] ) ) : array() ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by array_map( 'absint' ) and sanitize_staff_ids().
		// 「すべてのリソースが担当できる」ラジオ（#485）。Pro 版でラジオが送信されたときだけ更新する
		// （無料版や、ラジオを含まない古い行 DOM からの送信では触らない）。
		$staff_all              = ( Staff_Editor::is_enabled() && isset( $_POST['vkbm_service_menu_quick']['staff_all'] ) )
			? ( '1' === sanitize_text_field( wp_unslash( $_POST['vkbm_service_menu_quick']['staff_all'] ) ) ? '1' : '' )
			: null;
		$other_conditions       = isset( $_POST['vkbm_service_menu_quick']['other_conditions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['vkbm_service_menu_quick']['other_conditions'] ) ) : '';
		$reservation_day_type   = $this->sanitize_reservation_day_type( isset( $_POST['vkbm_service_menu_quick']['reservation_day_type'] ) ? sanitize_text_field( wp_unslash( $_POST['vkbm_service_menu_quick']['reservation_day_type'] ) ) : '', $post_id );
		$disable_nomination_fee = ! empty( $_POST['vkbm_service_menu_quick']['disable_nomination_fee'] ) ? '1' : '';

		$this->update_meta_value( $post_id, '_vkbm_base_price', $base_price );
		$this->update_meta_value( $post_id, '_vkbm_duration_minutes', $duration );
		$this->update_meta_value( $post_id, '_vkbm_buffer_after_minutes', $buffer_after );
		$this->update_meta_value( $post_id, '_vkbm_reservation_deadline_hours', $reservation_deadline );
		if ( null !== $staff_ids ) {
			// 「すべて」選択中でも個別選択は消さずに保持する（「選ぶ」へ戻したとき復元するため）。
			$this->update_meta_value( $post_id, self::META_STAFF_IDS, $staff_ids, true );
		}
		if ( null !== $staff_all ) {
			// '' のときは update_meta_value() がメタを削除し、未設定（既定 false）に戻る。
			$this->update_meta_value( $post_id, self::META_STAFF_ALL, $staff_all );
		}
		$this->update_meta_value( $post_id, self::META_OTHER_CONDITIONS, $other_conditions );
		$this->update_meta_value( $post_id, self::META_RESERVATION_DAY_TYPE, $reservation_day_type );
		$this->update_meta_value( $post_id, self::META_DISABLE_NOMINATION_FEE, $disable_nomination_fee );
	}

	/**
	 * Sanitize numeric values. Returns empty string if non-numeric or blank.
	 *
	 * @param mixed $raw Raw value.
	 * @return string
	 */
	private function sanitize_numeric_value( $raw ): string {
		$raw = trim( (string) $raw );

		if ( '' === $raw || ! is_numeric( $raw ) ) {
			return '';
		}

		$value = max( 0, (int) $raw );

		return (string) $value;
	}

	/**
	 * Sanitize reservation day type.
	 *
	 * 許容値への正規化は共有ヘルパーに集約している。曜日指定・日付指定はクイック編集では
	 * 詳細設定を編集できないため、対応する詳細メタが既に保存されている場合のみ許可し、
	 * そうでなければ「指定なし」へ戻す（詳細未設定の custom 種別で全曜日不可になるのを防ぐ）。
	 *
	 * @param mixed $raw     Raw value.
	 * @param int   $post_id 対象の投稿ID（custom 種別の詳細メタ有無の確認に使用）。
	 * @return string
	 */
	private function sanitize_reservation_day_type( $raw, int $post_id = 0 ): string {
		$value = Reservation_Day::sanitize_type( sanitize_text_field( wp_unslash( (string) $raw ) ) );

		if ( Reservation_Day::TYPE_CUSTOM_WEEKDAY === $value ) {
			// メタキーは Reservation_Day を単一の定義元として参照する（保存側との drift 防止）。
			$config = get_post_meta( $post_id, Reservation_Day::META_CUSTOM_WEEKDAYS, true );
			if ( ! is_array( $config ) || empty( $config ) ) {
				return Reservation_Day::TYPE_NONE;
			}
		} elseif ( Reservation_Day::TYPE_CUSTOM_DATE === $value ) {
			$config = get_post_meta( $post_id, Reservation_Day::META_CUSTOM_DATES, true );
			if ( ! is_array( $config ) || empty( $config ) ) {
				return Reservation_Day::TYPE_NONE;
			}
		}

		return $value;
	}

	/**
	 * Update or delete post meta.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $meta_key    Meta key.
	 * @param mixed  $value       Value to store.
	 * @param bool   $allow_array Whether to allow array values.
	 */
	private function update_meta_value( int $post_id, string $meta_key, $value, bool $allow_array = false ): void {
		if ( ! $allow_array && '' === $value ) {
			delete_post_meta( $post_id, $meta_key );
			return;
		}

		if ( $allow_array && empty( $value ) ) {
			delete_post_meta( $post_id, $meta_key );
			return;
		}

		update_post_meta( $post_id, $meta_key, $value );
	}

	/**
	 * Sanitize staff ID array for quick edit and the REST meta API.
	 *
	 * register_meta() の sanitize_callback としても登録しているため public にしている。
	 * private のままだと register_meta() 側の is_callable() 判定が false になり、
	 * フィルターが付かずに REST 書き込みが schema 検証だけで通ってしまう（#485 で修正）。
	 *
	 * @param mixed $ids Raw IDs.
	 * @return array<int>
	 */
	public function sanitize_staff_ids( $ids ): array {
		return self::normalize_staff_ids( $ids );
	}

	/**
	 * 担当リソースID配列を正規化する（int キャスト・0以下の除外・重複排除・添字の詰め直し）。
	 *
	 * 保存時の sanitize と読み取り時の正規化で同じ規則を使うために1か所へまとめている。
	 *
	 * @param mixed $ids 正規化前の値（配列以外は空配列として扱う）。
	 * @return array<int> 正規化済みのリソースID配列。
	 */
	private static function normalize_staff_ids( $ids ): array {
		if ( ! is_array( $ids ) ) {
			return array();
		}

		$ids = array_map(
			static function ( $id ) {
				return (int) $id;
			},
			$ids
		);

		$ids = array_filter(
			$ids,
			static function ( int $id ): bool {
				return $id > 0;
			}
		);

		return array_values( array_unique( $ids ) );
	}

	/**
	 * メニューで「すべてのリソースが担当できる」（META_STAFF_ALL）が選ばれているかを返す（#485）。
	 *
	 * 無料版（Staff_Editor::is_enabled() === false）ではメニュー側の担当設定自体を使わない
	 * （担当は常に基本スタッフ1名）ため、メタの値に関わらず常に false を返す。
	 * Pro 版から無料版へ切り替えてメタが残っていても、無料版の挙動に影響させない。
	 *
	 * @param int $menu_id サービスメニューの投稿ID。
	 * @return bool 「すべて」が選ばれていれば true。
	 */
	public static function is_all_staff_assigned( int $menu_id ): bool {
		if ( $menu_id <= 0 || ! Staff_Editor::is_enabled() ) {
			return false;
		}

		return (bool) get_post_meta( $menu_id, self::META_STAFF_ALL, true );
	}

	/**
	 * メニューに個別選択で保存されている担当リソースID配列（META_STAFF_IDS）を返す（#485）。
	 *
	 * 「すべて」フラグの状態は見ない。編集画面のチェックボックスの初期状態や
	 * クイック編集のプリフィルなど「保存されている個別選択そのもの」が必要な箇所で使う。
	 * 予約・表示で実際に担当できるリソースを求めるときは get_assignable_staff_ids() を使う。
	 *
	 * @param int $menu_id サービスメニューの投稿ID。
	 * @return array<int> 正規化済みのリソースID配列（未設定は空配列）。
	 */
	public static function get_selected_staff_ids( int $menu_id ): array {
		if ( $menu_id <= 0 ) {
			return array();
		}

		return self::normalize_staff_ids( get_post_meta( $menu_id, self::META_STAFF_IDS, true ) );
	}

	/**
	 * メニューを実際に担当できるリソースID配列を返す（読み取り側の単一の入口、#485）。
	 *
	 * - 「すべて」（is_all_staff_assigned()）のとき: 公開中の全リソースID
	 *   （表示順は管理画面のリソース並び順 menu_order → タイトル）。
	 * - それ以外: 個別選択（META_STAFF_IDS）を正規化した配列。空配列（未設定）はそのまま
	 *   空配列を返し、「未設定」の意味は変えない（自動割当不可として扱うのは呼び出し側の従来どおり）。
	 *
	 * 空き枠計算・予約下書き・予約確定・お気に入り・メニュー一覧の絞り込み・カード表示は
	 * すべてこのメソッドを通し、「すべて」の展開ロジックを複製しない。
	 *
	 * @param int $menu_id サービスメニューの投稿ID。
	 * @return array<int> 担当できるリソースID配列。
	 */
	public static function get_assignable_staff_ids( int $menu_id ): array {
		if ( $menu_id <= 0 ) {
			return array();
		}

		$is_all    = self::is_all_staff_assigned( $menu_id );
		$staff_ids = $is_all ? self::get_published_resource_ids() : self::get_selected_staff_ids( $menu_id );

		/**
		 * メニューを担当できるリソースID配列を絞り込む・差し替えるためのフィルター（#485）。
		 *
		 * 将来「指名不可」などリソース側の属性で候補から外す必要が出たときに、
		 * 読み取り側の各所を触らずここ1か所で除外できるようにする拡張点。
		 *
		 * @param array<int> $staff_ids 担当できるリソースID配列（正規化済み）。
		 * @param int        $menu_id   サービスメニューの投稿ID。
		 * @param bool       $is_all    「すべてのリソースが担当できる」が選ばれていれば true。
		 */
		$filtered = apply_filters( 'vkbm_menu_assignable_staff_ids', $staff_ids, $menu_id, $is_all );

		// フィルターの戻り値は「正の整数の一意な配列」という形式だけを再正規化する（配列以外は空配列、
		// 文字列IDは int 化、0以下と重複は除外）。実在する公開中のリソースかどうかは検証しない
		// （フィルター側がリソースを追加する用途を塞がないため）。存在しないIDが混ざった場合の扱いは
		// 呼び出し側で一様ではない。名前リスト表示（Menu_Loop_Block::render_meta_information()）や
		// お気に入りの判定（User_Favorites_Controller::is_resource_assignable()）では get_posts の
		// include や公開状態の確認で無視されるが、担当できるリソース数を数える箇所
		// （Booking_Confirmation_Controller::count_menu_staff()、Booking_Draft_Controller の
		// $staff_count）は count() するだけで存在確認をしないため、フィルターが存在しないIDを
		// 足すと複数人一括予約の上限人数が水増しされ得る（安藤レビュー LOW）。
		return self::normalize_staff_ids( $filtered );
	}

	/**
	 * 公開中のリソース（vkbm_resource）の投稿IDを、管理画面の並び順で返す（#485）。
	 *
	 * リクエスト内キャッシュを使う。無効化条件は self::$published_resource_ids_cache を参照。
	 *
	 * @return array<int> 公開中リソースの投稿ID配列。
	 */
	public static function get_published_resource_ids(): array {
		if ( ! post_type_exists( Resource_Post_Type::POST_TYPE ) ) {
			return array();
		}

		// 投稿の追加・更新・削除があると wp_cache_get_last_changed( 'posts' ) の値が変わる。
		// 取得時点の値と一致する間だけキャッシュを使い、変わっていれば再取得する。
		$last_changed = (string) wp_cache_get_last_changed( 'posts' );
		if ( null !== self::$published_resource_ids_cache && self::$published_resource_ids_cache['last_changed'] === $last_changed ) {
			return self::$published_resource_ids_cache['ids'];
		}

		$ids = get_posts(
			array(
				'post_type'      => Resource_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
			)
		);

		$ids = self::normalize_staff_ids( $ids );

		self::$published_resource_ids_cache = array(
			'last_changed' => $last_changed,
			'ids'          => $ids,
		);

		return $ids;
	}

	/**
	 * 公開中リソースIDのリクエスト内キャッシュを破棄する（#485）。
	 *
	 * 通常は last_changed による自動無効化で足りるが、テストなどで明示的に破棄したい場合に使う。
	 */
	public static function clear_published_resource_ids_cache(): void {
		self::$published_resource_ids_cache = null;
	}

	/**
	 * 「すべての〇〇」（〇〇＝基本設定のリソースラベル複数形）の表示文言を返す（#485）。
	 *
	 * フロントのカード・管理画面一覧・編集画面のラジオボタンで同じ文言を使うため1か所にまとめる。
	 * リソースラベルは「スタッフ」以外（ルーム・コートなど人以外）にも変更されうるため
	 * 「全員」ではなくラベルを差し込む形にしている。
	 *
	 * @return string 例: 「すべてのスタッフ」。
	 */
	public static function get_all_staff_label(): string {
		return sprintf(
			/* translators: %s: resource label (plural), e.g. "Staff". */
			__( 'All %s', 'vk-booking-manager' ),
			vkbm_get_resource_label_plural()
		);
	}

	/**
	 * 「担当できる〇〇」（〇〇＝基本設定のリソースラベル複数形）の見出し文言を返す（#485）。
	 *
	 * 管理画面一覧の列見出し・クイック編集・編集画面の legend で共通に使う。
	 *
	 * @return string 例: 「担当できるスタッフ」。
	 */
	public static function get_available_staff_label(): string {
		return sprintf(
			/* translators: %s: resource label (plural), e.g. "Staff". */
			__( 'Available %s', 'vk-booking-manager' ),
			vkbm_get_resource_label_plural()
		);
	}

	/**
	 * ラジオ「すべての〇〇が担当できる」の選択肢文言を返す（#485）。
	 *
	 * 編集画面のメタボックスとクイック編集で同じ文言を使う。
	 *
	 * @return string 例: 「すべてのスタッフが担当できる」。
	 */
	public static function get_all_staff_option_label(): string {
		return sprintf(
			/* translators: %s: resource label (plural), e.g. "Staff". */
			__( 'All %s can be in charge', 'vk-booking-manager' ),
			vkbm_get_resource_label_plural()
		);
	}

	/**
	 * ラジオ「担当できる〇〇を選ぶ」の選択肢文言を返す（#485）。
	 *
	 * 編集画面のメタボックスとクイック編集で同じ文言を使う。
	 *
	 * @return string 例: 「担当できるスタッフを選ぶ」。
	 */
	public static function get_choose_staff_option_label(): string {
		return sprintf(
			/* translators: %s: resource label (plural), e.g. "Staff". */
			__( 'Choose which %s can be in charge', 'vk-booking-manager' ),
			vkbm_get_resource_label_plural()
		);
	}

	/**
	 * クイック編集のプリフィル用 data 属性を持つ非表示 span を出力する。
	 *
	 * 一覧の複数列（料金・所要時間・予約締切・サービス後バッファ）で同じ span を出すため、
	 * 属性名と値の組み立てをここに集約する（列ごとに printf を複製しない）。
	 *
	 * @param array<string, string> $data data 属性名（"data-" を除いたケバブケース）=> 値。
	 */
	private function render_quick_edit_data_span( array $data ): void {
		$attributes = '';
		foreach ( $data as $name => $value ) {
			// 属性名は本メソッドの呼び出し元が固定文字列で指定するため、値のみエスケープする。
			$attributes .= sprintf( ' data-%1$s="%2$s"', $name, esc_attr( (string) $value ) );
		}

		// $attributes は上で esc_attr 済み、クラス名・style は固定文字列。
		echo '<span class="vkbm-service-menu-qe" style="display:none"' . $attributes . '></span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attribute values are escaped above.
	}

	/**
	 * Register the taxonomy used to group service menus.
	 */
	public function register_taxonomy(): void {
		if ( ! taxonomy_exists( self::TAXONOMY ) ) {
			$this->register_tag_taxonomy();
		}

		if ( ! taxonomy_exists( self::TAXONOMY_GROUP ) ) {
			$this->register_group_taxonomy();
		}
	}

	/**
	 * Register the taxonomy used to tag service menus.
	 */
	private function register_tag_taxonomy(): void {
		$labels = array(
			'name'              => __( 'Service Tag', 'vk-booking-manager' ),
			'singular_name'     => __( 'Service Tag', 'vk-booking-manager' ),
			'search_items'      => __( 'Find your service tag', 'vk-booking-manager' ),
			'all_items'         => __( 'All service tags', 'vk-booking-manager' ),
			'parent_item'       => __( 'Parent service tag', 'vk-booking-manager' ),
			'parent_item_colon' => __( 'Parent service tag:', 'vk-booking-manager' ),
			'edit_item'         => __( 'Edit service tag', 'vk-booking-manager' ),
			'update_item'       => __( 'Update service tag', 'vk-booking-manager' ),
			'add_new_item'      => __( 'Add service tag', 'vk-booking-manager' ),
			'new_item_name'     => __( 'New Service Tag Name', 'vk-booking-manager' ),
			'menu_name'         => __( 'Service Tag', 'vk-booking-manager' ),
		);

		$args = array(
			'labels'            => $labels,
			'public'            => false,
			'show_ui'           => true,
			'show_in_nav_menus' => false,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'hierarchical'      => true,
			'capabilities'      => array(
				'manage_terms' => Capabilities::MANAGE_SERVICE_MENUS,
				'edit_terms'   => Capabilities::MANAGE_SERVICE_MENUS,
				'delete_terms' => Capabilities::MANAGE_SERVICE_MENUS,
				'assign_terms' => Capabilities::MANAGE_SERVICE_MENUS,
			),
		);

		register_taxonomy( self::TAXONOMY, self::POST_TYPE, $args );
	}

	/**
	 * Register the taxonomy used to group service menus.
	 */
	private function register_group_taxonomy(): void {
		$labels = array(
			'name'              => __( 'Service Menu Group', 'vk-booking-manager' ),
			'singular_name'     => __( 'Service Menu Group', 'vk-booking-manager' ),
			'search_items'      => __( 'Search service menu group', 'vk-booking-manager' ),
			'all_items'         => __( 'All service menu groups', 'vk-booking-manager' ),
			'parent_item'       => __( 'Parent service menu group', 'vk-booking-manager' ),
			'parent_item_colon' => __( 'Parent service menu group:', 'vk-booking-manager' ),
			'edit_item'         => __( 'Edit service menu group', 'vk-booking-manager' ),
			'update_item'       => __( 'Update service menu group', 'vk-booking-manager' ),
			'add_new_item'      => __( 'Add service menu group', 'vk-booking-manager' ),
			'new_item_name'     => __( 'New Service Menu Group Name', 'vk-booking-manager' ),
			'menu_name'         => __( 'Service Group', 'vk-booking-manager' ),
		);

		$args = array(
			'labels'            => $labels,
			'public'            => false,
			'show_ui'           => true,
			'show_in_nav_menus' => false,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'hierarchical'      => true,
			'capabilities'      => array(
				'manage_terms' => Capabilities::MANAGE_SERVICE_MENUS,
				'edit_terms'   => Capabilities::MANAGE_SERVICE_MENUS,
				'delete_terms' => Capabilities::MANAGE_SERVICE_MENUS,
				'assign_terms' => Capabilities::MANAGE_SERVICE_MENUS,
			),
		);

		register_taxonomy( self::TAXONOMY_GROUP, self::POST_TYPE, $args );
	}

	/**
	 * Render group display mode field on add form.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 */
	public function render_group_term_add_fields( string $taxonomy ): void {
		if ( self::TAXONOMY_GROUP !== $taxonomy ) {
			return;
		}
		?>
		<div class="form-field term-display-mode-wrap">
			<label for="vkbm-menu-group-display-mode"><?php esc_html_e( 'Display mode when displaying all items', 'vk-booking-manager' ); ?></label>
			<select id="vkbm-menu-group-display-mode" name="vkbm_menu_group_display_mode">
				<option value="inherit"><?php esc_html_e( 'Use common settings', 'vk-booking-manager' ); ?></option>
				<option value="text"><?php esc_html_e( 'text', 'vk-booking-manager' ); ?></option>
				<option value="card"><?php esc_html_e( 'card', 'vk-booking-manager' ); ?></option>
			</select>
			<p class="description"><?php esc_html_e( 'Applies only when the menu loop displays all groups.', 'vk-booking-manager' ); ?></p>
		</div>
		<?php
		wp_nonce_field( 'vkbm_menu_group_display_mode', 'vkbm_menu_group_display_mode_nonce' );
	}

	/**
	 * Render group display mode field on edit form.
	 *
	 * @param \WP_Term $term     Term object.
	 * @param string   $taxonomy Taxonomy slug.
	 */
	public function render_group_term_edit_fields( \WP_Term $term, string $taxonomy ): void {
		if ( self::TAXONOMY_GROUP !== $taxonomy ) {
			return;
		}

		$value = (string) get_term_meta( (int) $term->term_id, self::TERM_GROUP_DISPLAY_MODE_META_KEY, true );
		$value = '' === $value ? 'inherit' : $value;
		?>
		<tr class="form-field term-display-mode-wrap">
			<th scope="row"><label for="vkbm-menu-group-display-mode"><?php esc_html_e( 'Display mode when displaying all items', 'vk-booking-manager' ); ?></label></th>
			<td>
				<select id="vkbm-menu-group-display-mode" name="vkbm_menu_group_display_mode">
					<option value="inherit" <?php selected( $value, 'inherit' ); ?>><?php esc_html_e( 'Use common settings', 'vk-booking-manager' ); ?></option>
					<option value="text" <?php selected( $value, 'text' ); ?>><?php esc_html_e( 'text', 'vk-booking-manager' ); ?></option>
					<option value="card" <?php selected( $value, 'card' ); ?>><?php esc_html_e( 'card', 'vk-booking-manager' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Applies only when the menu loop displays all groups.', 'vk-booking-manager' ); ?></p>
				<?php wp_nonce_field( 'vkbm_menu_group_display_mode', 'vkbm_menu_group_display_mode_nonce' ); ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Save display mode for menu group terms.
	 *
	 * @param int $term_id Term ID.
	 */
	public function save_group_term_display_mode( int $term_id ): void {
		if ( ! current_user_can( Capabilities::MANAGE_SERVICE_MENUS ) ) {
			return;
		}

		$nonce = isset( $_POST['vkbm_menu_group_display_mode_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['vkbm_menu_group_display_mode_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified below.
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'vkbm_menu_group_display_mode' ) ) {
			return;
		}

		$raw_value = isset( $_POST['vkbm_menu_group_display_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['vkbm_menu_group_display_mode'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$value     = sanitize_key( is_string( $raw_value ) ? $raw_value : '' );
		$allowed   = array( 'inherit', 'text', 'card' );

		if ( ! in_array( $value, $allowed, true ) ) {
			$value = 'inherit';
		}

		if ( 'inherit' === $value ) {
			delete_term_meta( $term_id, self::TERM_GROUP_DISPLAY_MODE_META_KEY );
		} else {
			update_term_meta( $term_id, self::TERM_GROUP_DISPLAY_MODE_META_KEY, $value );
		}

		wp_cache_delete( $term_id, 'term_meta' );
	}

	/**
	 * Register REST-exposed post meta.
	 */
	public function register_meta(): void {
		register_post_meta(
			self::POST_TYPE,
			'_vkbm_base_price',
			array(
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => true,
				'sanitize_callback' => array( $this, 'sanitize_price_meta' ),
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_DISABLE_NOMINATION_FEE,
			array(
				'type'              => 'boolean',
				'single'            => true,
				'default'           => false,
				'show_in_rest'      => true,
				'sanitize_callback' => static function ( $value ): bool {
					return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
				},
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
			)
		);

		// メニュー単位で指名機能を無効化するかどうか（#391）。既定OFF（＝指名を使う）。
		// サイト全体の指名機能スイッチがONのときだけ管理画面に入力欄が現れる（サイト全体OFF時は
		// メニュー単位の判定 is_nomination_enabled_for_menu() が常に false を返すため無意味になる）。
		register_post_meta(
			self::POST_TYPE,
			self::META_DISABLE_NOMINATION,
			array(
				'type'              => 'boolean',
				'single'            => true,
				'default'           => false,
				'show_in_rest'      => true,
				'sanitize_callback' => static function ( $value ): bool {
					return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
				},
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
					// 編集権限に加え、Pro版 かつ サイト全体の指名機能がON のときのみREST書き込みを許可する。
					// このメタ自体が「このメニューで指名機能を使うか」を決めるため、判定にメニュー単位の
					// is_nomination_enabled_for_menu()（このメタを読む関数）を使うと自己参照になる。
					// そのためサイト全体の設定（is_nomination_enabled()）だけをゲートに使う
					// （別データソースのサイト全体オプションを見るため、本メタ自身の自己参照は起きない）。
					//
					// 注意（#412 A-3）: 本メタ自身のゲートは上記の理由で安全だが、他のメニューメタ
					// （_vkbm_allow_multiple_guests・_vkbm_price_tiers・_vkbm_exclusive_* 等）の
					// auth_callback は本メタの保存済み値を is_nomination_enabled_for_menu() 経由で
					// 読むように変更済みである。そのため同一 REST リクエストで本メタと他のメニューメタを
					// 同時に送ると、WordPress がメタを処理する順序次第で他メタ側が stale な本メタの値を
					// 読んでしまい得る（＝順序依存）。権限昇格ではない（edit_post は既存の
					// _vkbm_disable_nomination_fee と同じ権限マッピングで、本メタのゲートは
					// それより厳しい「Pro版 かつ サイト全体の指名機能ON」を要求する）ため、
					// 許容する設計判断とする。順序に依存させたくないクライアントは、本メタを
					// 別リクエストで先に確定させてから他のメニューメタを送ること。
					if ( ! current_user_can( 'edit_post', $post_id ) ) {
						return false;
					}
					$is_pro = class_exists( 'Free_Version_Deactivator' ) && \Free_Version_Deactivator::is_pro_edition( VKBM_PLUGIN_FILE );
					return $is_pro && Staff_Editor::is_nomination_enabled();
				},
			)
		);

		// 最大同時予約人数（デフォルト1。Pro版で2以上に設定可能）。
		// Maximum simultaneous bookings per slot (default 1, configurable in Pro edition).
		register_post_meta(
			self::POST_TYPE,
			self::META_MAX_CAPACITY,
			array(
				'type'              => 'integer',
				'single'            => true,
				'default'           => 1,
				'show_in_rest'      => true,
				'sanitize_callback' => static function ( $value ): int {
					$int = (int) $value;
					return max( 1, $int );
				},
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
			)
		);

		// 最小催行人数（グループ開催型）。同じ時間枠の合計予約人数がこの値に達したら開催確定とみなす表示用設定。
		// デフォルト0は「制約なし（催行判定なし）」で従来挙動と完全互換。上限（max_capacity）との整合は
		// 保存ゲート（Service_Menu_Editor::save_post）でクランプする（register_meta では単体メタしか参照できないため）。
		register_post_meta(
			self::POST_TYPE,
			self::META_MIN_CAPACITY,
			array(
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => true,
				'sanitize_callback' => static function ( $value ): int {
					// 0以上の整数に丸める（0=制約なし）。負値は0へ。
					return max( 0, (int) $value );
				},
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
					// 編集権限に加え、save_post() と同じ業務ゲート（Pro版 かつ 予約枠の定員機能ON）を
					// REST メタAPI経由の書き込みにも適用する。ゲート外で値を保持させると、後から
					// Pro版化・予約枠の定員機能を有効化した際に意図せず催行判定・受付制限が
					// 効き始めてしまうため、保存経路で防ぐ。
					//
					// #393（安藤レビュー指摘）: 以前はここに「指名OFF」条件も含めていたが、
					// Service_Menu_Editor::save_post() は #392 で「指名OFF」条件を外しており
					// （min_capacity は指名ONのメニューでも保存される）、この auth_callback だけが
					// 古いゲートのまま取り残されていた（指名を使うメニューでは REST 経由の
					// min_capacity 書き込みだけが拒否される不整合。管理画面の保存はクラシックな
					// $_POST 経由で auth_callback を通らないため実害はなかったが、save_post() 側の
					// ゲートと完全に一致させる）。
					if ( ! current_user_can( 'edit_post', $post_id ) ) {
						return false;
					}
					$is_pro = class_exists( 'Free_Version_Deactivator' ) && \Free_Version_Deactivator::is_pro_edition( VKBM_PLUGIN_FILE );
					return $is_pro && Staff_Editor::is_slot_capacity_enabled();
				},
			)
		);

		// 複数人予約を許可するかどうか（Pro版・予約枠の定員機能ON時のみ有効。既定OFF）。
		// #392: 指名を使うメニューでも「1件の予約で申し込める人数」を定員まで受け付けられるようにするため、
		// 「指名OFF」条件は表示／保存条件から外した（このメニューで指名を使うか否かに関わらず設定できる）。
		register_post_meta(
			self::POST_TYPE,
			self::META_ALLOW_MULTIPLE_GUESTS,
			array(
				'type'              => 'boolean',
				'single'            => true,
				'default'           => false,
				'show_in_rest'      => true,
				'sanitize_callback' => static function ( $value ): bool {
					return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
				},
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
					// 編集権限に加え、save_post() と同じゲート（Pro版 かつ 予約枠の定員機能ON）をREST書き込みにも適用する。
					// これにより REST メタAPI経由で業務ロジック制約を迂回されるのを防ぐ（読み取りは show_in_rest で別途許可）。
					// #392: 以前は「指名OFF」も条件に含めていたが、指名を使うメニューでも複数人一括予約を
					// 利用できるようにするため外した（Staff_Editor::is_multi_guest_available_for_menu() 参照）。
					if ( ! current_user_can( 'edit_post', $post_id ) ) {
						return false;
					}
					return Staff_Editor::is_multi_guest_available_for_menu( $post_id );
				},
			)
		);

		// 貸し切り予約（予約が入ったらその時間帯を貸し切りにする）。複数人予約ON時のみ意味を持つ（既定OFF）。
		// ONのメニューでは予約確定時に予約レコードへ _vkbm_booking_exclusive が付与され、
		// 1件でも予約が入ると残り枠があっても他のユーザーは予約できなくなる（#304）。
		register_post_meta(
			self::POST_TYPE,
			self::META_EXCLUSIVE_WHEN_BOOKED,
			array(
				'type'              => 'boolean',
				'single'            => true,
				'default'           => false,
				'show_in_rest'      => true,
				'sanitize_callback' => static function ( $value ): bool {
					return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
				},
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
					// #392: 以前は複数人予約フラグ・料金区分と同じゲート（Pro版 かつ 指名OFF）を共有していたが、
					// 複数人予約フラグ・料金区分は指名OFF条件を外したため（is_multi_guest_available_for_menu()
					// 参照）、貸切系の3設定（本メタもその1つ）は専用の
					// Staff_Editor::is_exclusive_booking_available_for_menu()（Pro版 かつ 予約枠の定員機能ON）を
					// 使う。#440でこちらのゲートからも「指名OFF」条件を外し、指名を使う
					// メニューでもこの3設定を保存できるようにした（予約時の排他は「メニュー全体」ではなく
					// 「担当スタッフ単位」になる。実装は Booking_Draft_Controller /
					// Booking_Confirmation_Controller 側）。REST メタAPI経由で業務ロジック制約を
					// 迂回されるのを防ぐ（読み取りは show_in_rest で別途許可）。
					//
					// NOTE: ここでは _vkbm_allow_multiple_guests（保存済み値）は読まない。price_tiers と同様、
					// REST で「複数人予約ON＋貸し切りON」を同一リクエストで保存する際にメタの処理順序によって
					// stale な保存済み値で誤って拒否され得る（race）ため。複数人予約OFFのメニューに貸し切りメタが
					// 混入しても、受付停止の判定側 is_menu_exclusive_when_booked() がフルゲート（Pro＋全体の
					// 複数人予約ON＋メニューの allow ON）を再適用して無害化する（load-bearing な主防御）。
					if ( ! current_user_can( 'edit_post', $post_id ) ) {
						return false;
					}
					return Staff_Editor::is_exclusive_booking_available_for_menu( $post_id );
				},
			)
		);

		// ユーザーによる貸し切り指定を受け付けるか（#305）。複数人予約ON時のみ意味を持つ（既定OFF）。
		// ONのメニューでは、予約者がフロントの予約画面で「この時間帯を貸切にする」を選択でき、
		// 選択された予約には _vkbm_booking_exclusive が付与され、以降その枠は他のユーザーが予約できなくなる。
		register_post_meta(
			self::POST_TYPE,
			self::META_EXCLUSIVE_USER_SELECTABLE,
			array(
				'type'              => 'boolean',
				'single'            => true,
				'default'           => false,
				'show_in_rest'      => true,
				'sanitize_callback' => static function ( $value ): bool {
					return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
				},
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
					// #392: 貸切系の3設定（本メタもその1つ）専用のゲート
					// Staff_Editor::is_exclusive_booking_available_for_menu()（Pro版 かつ 予約枠の定員機能ON）を
					// REST 書き込みに適用する。#440でこのゲートから「指名OFF」条件を外し、
					// 指名を使うメニューでも保存できるようにした。メタAPI経由で業務ロジック制約を
					// 迂回されるのを防ぐ（読み取りは show_in_rest で別途許可）。
					// NOTE: ここでは保存済みの複数人予約フラグ（_vkbm_allow_multiple_guests）は読まない。
					// 料金区分・貸し切り設定と同様、同一リクエストで複数メタを保存する際の stale 値による
					// 誤拒否（race）を避けるため。複数人予約OFFのメニューにこのメタが混入しても、
					// フロント表示・サーバ側ガード（is_user_exclusive_selectable 相当のフルゲート再適用）で無害化する。
					if ( ! current_user_can( 'edit_post', $post_id ) ) {
						return false;
					}
					return Staff_Editor::is_exclusive_booking_available_for_menu( $post_id );
				},
			)
		);

		// 貸し切り料金（1人あたり・#305）。ユーザー貸し切り指定ONのメニューでのみ意味を持つ（既定0）。
		// 実際の課金額は「per_person × 申込人数」で、サーバ側（予約確定・下書き）で権威的に再計算する。
		register_post_meta(
			self::POST_TYPE,
			self::META_EXCLUSIVE_FEE_PER_PERSON,
			array(
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => true,
				'sanitize_callback' => static function ( $value ): int {
					// 0以上の整数に丸める（負値は0）。
					return max( 0, (int) $value );
				},
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
					// #392: 貸切系の3設定専用のゲート（Staff_Editor::is_exclusive_booking_available_for_menu()）を適用する。
					if ( ! current_user_can( 'edit_post', $post_id ) ) {
						return false;
					}
					return Staff_Editor::is_exclusive_booking_available_for_menu( $post_id );
				},
			)
		);

		// 貸し切り料金を適用しない申込人数（#305）。この人数「以上」の申し込みでは貸し切り料金を加算しない。
		// 0（または空＝デフォルト0）は「上限なし＝常に加算」を意味する。ユーザー貸し切り指定ON時のみ意味を持つ。
		register_post_meta(
			self::POST_TYPE,
			self::META_EXCLUSIVE_FEE_EXEMPT_GUESTS,
			array(
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => true,
				'sanitize_callback' => static function ( $value ): int {
					// 0以上の整数に丸める（0=上限なし＝常に加算）。負値は0へ。
					return max( 0, (int) $value );
				},
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
					// #392: 貸切系の3設定専用のゲート（Staff_Editor::is_exclusive_booking_available_for_menu()）を適用する。
					if ( ! current_user_can( 'edit_post', $post_id ) ) {
						return false;
					}
					return Staff_Editor::is_exclusive_booking_available_for_menu( $post_id );
				},
			)
		);

		// 料金区分（大人料金・子供料金など）。複数人予約ON時のみ意味を持つ。
		// 1件でも定義すると基本料金×人数ではなく区分料金で計算する（併用なし）。
		// #392: 指名を使うメニューでも料金区分を利用できるようにするため、「指名OFF」条件は
		// 表示／保存条件から外した。
		register_post_meta(
			self::POST_TYPE,
			self::META_PRICE_TIERS,
			array(
				'type'              => 'array',
				'single'            => true,
				'show_in_rest'      => array(
					'schema' => array(
						'type'    => 'array',
						'items'   => array(
							'type'       => 'object',
							'properties' => array(
								'label' => array( 'type' => 'string' ),
								'price' => array( 'type' => 'integer' ),
							),
						),
						'context' => array( 'view', 'edit' ),
					),
				),
				'sanitize_callback' => static function ( $value ): array {
					return Price_Tiers::sanitize_tiers( $value );
				},
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
					// 編集権限に加え、複数人予約フラグと同じゲート（Pro版 かつ 予約枠の定員機能ON）を REST 書き込みに適用する。
					// メタAPI経由で業務ロジック制約を迂回されるのを防ぐ（読み取りは show_in_rest で別途許可）。
					//
					// NOTE: ここで _vkbm_allow_multiple_guests（保存済み値）は読まない。
					// REST で「複数人予約ON＋料金区分」を同一リクエストで保存する際、メタの処理順序によっては
					// allow フラグ書き込み前に料金区分の auth が走り、stale な保存済み値で誤って拒否され得る（race）。
					// 複数人予約OFFのメニューに区分が混入しても、料金計算側がフルゲート（allow＋Pro＋予約枠の
					// 定員機能ON）を再適用して無害化するため、多層防御は計算側で担保される。
					// #392: 以前は「指名OFF」も条件に含めていたが、指名を使うメニューでも料金区分を
					// 利用できるようにするため外した（Staff_Editor::is_multi_guest_available_for_menu() 参照）。
					if ( ! current_user_can( 'edit_post', $post_id ) ) {
						return false;
					}
					return Staff_Editor::is_multi_guest_available_for_menu( $post_id );
				},
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_STAFF_IDS,
			array(
				'type'              => 'array',
				'single'            => true,
				'show_in_rest'      => array(
					'schema' => array(
						'type'    => 'array',
						'items'   => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'context' => array( 'view', 'edit' ),
					),
				),
				'sanitize_callback' => array( $this, 'sanitize_staff_ids' ),
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
			)
		);

		// 「すべてのリソースが担当できる」フラグ（#485）。既定 false ＝個別選択を使う。
		// フロント（app.js）は展開後の候補を REST 計算フィールド vkbm_assignable_staff_ids で
		// 受け取るため、このメタ自体は主に管理画面の表示・保存と REST 書き込みのために公開する。
		register_post_meta(
			self::POST_TYPE,
			self::META_STAFF_ALL,
			array(
				'type'              => 'boolean',
				'single'            => true,
				'default'           => false,
				'show_in_rest'      => true,
				'sanitize_callback' => static function ( $value ): bool {
					// REST からは真偽値、管理画面フォームからは '1' / '' で届くため、
					// 'false' などの文字列表現も正しく解釈できる rest_sanitize_boolean() で丸める。
					return rest_sanitize_boolean( $value );
				},
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
					// 無料版ではメニュー側の担当設定自体が存在しない（編集画面のメタボックスも
					// 表示しない）ため、REST 経由の書き込みも Pro 版のみに限定する。
					if ( ! current_user_can( 'edit_post', $post_id ) ) {
						return false;
					}
					return Staff_Editor::is_enabled();
				},
			)
		);
	}

	/**
	 * リビジョンに保存・復元するメタキーの一覧を返す。
	 *
	 * サービスメニューの設定値（料金・所要時間・スタッフ等）はすべてカスタムメタで管理しているため、
	 * リビジョンへ含めないとタイトル・本文のみが記録され、設定値の履歴が残らない。
	 * ここに列挙したキーは WordPress の標準リビジョン機構（WP 6.4 以降）によって
	 * 保存時にリビジョンへコピーされ、復元時に投稿へ書き戻される。
	 *
	 * 廃止済みのメタ（_vkbm_max_guests_per_booking）と旧仕様の互換用メタ（_vkbm_online_available）は
	 * 保存時に削除されるため、リビジョン対象には含めない。
	 *
	 * @return array<int, string> リビジョン対象のメタキー。
	 */
	public function get_revisioned_meta_keys(): array {
		return array(
			'_vkbm_base_price',
			self::META_DISABLE_NOMINATION_FEE,
			self::META_DISABLE_NOMINATION,
			self::META_MAX_CAPACITY,
			self::META_MIN_CAPACITY,
			self::META_ALLOW_MULTIPLE_GUESTS,
			self::META_EXCLUSIVE_WHEN_BOOKED,
			self::META_EXCLUSIVE_USER_SELECTABLE,
			self::META_EXCLUSIVE_FEE_PER_PERSON,
			self::META_EXCLUSIVE_FEE_EXEMPT_GUESTS,
			self::META_PRICE_TIERS,
			self::META_STAFF_IDS,
			self::META_STAFF_ALL,
			'_vkbm_catch_copy',
			'_vkbm_internal_memo',
			'_vkbm_duration_minutes',
			'_vkbm_buffer_after_minutes',
			'_vkbm_reservation_deadline_hours',
			'_vkbm_max_advance_booking_days',
			self::META_RESERVATION_DAY_TYPE,
			'_vkbm_online_unavailable',
			'_vkbm_is_archived',
			'_vkbm_use_detail_page',
			self::META_OTHER_CONDITIONS,
			'_vkbm_fixed_start_times',
		);
	}

	/**
	 * サービスメニューのカスタムメタをリビジョン対象に追加する。
	 *
	 * `wp_post_revision_meta_keys` フィルターのコールバック。対象投稿タイプが
	 * サービスメニューのときのみ、このプラグインのメタキーを既存リストへ追加する。
	 *
	 * @param array<int, string> $keys      現在のリビジョン対象メタキー。
	 * @param string             $post_type 対象の投稿タイプ。
	 * @return array<int, string> 追加後のメタキー一覧（重複は排除）。
	 */
	public function filter_revision_meta_keys( array $keys, string $post_type ): array {
		// 対象外の投稿タイプでは何も変更しない。
		if ( self::POST_TYPE !== $post_type ) {
			return $keys;
		}

		// 既存のキーと統合し、重複を除いて返す。
		return array_values( array_unique( array_merge( $keys, $this->get_revisioned_meta_keys() ) ) );
	}

	/**
	 * Register REST-exposed fields for menu group ordering.
	 */
	public function register_rest_fields(): void {
		register_rest_field(
			self::POST_TYPE,
			'vkbm_menu_group',
			array(
				'get_callback' => array( $this, 'get_menu_group_rest_field' ),
				'schema'       => array(
					'description' => __( 'Primary service menu group information.', 'vk-booking-manager' ),
					'type'        => array( 'object', 'null' ),
					'context'     => array( 'view', 'edit' ),
					'properties'  => array(
						'id'    => array( 'type' => 'integer' ),
						'name'  => array( 'type' => 'string' ),
						'order' => array( 'type' => 'integer' ),
					),
				),
			)
		);

		// 指名を使うメニューの最低申し込み人数（受付制限、#393）を読み取り専用フィールドとして公開する。
		// フロント（app.js）は自前で適用条件（指名可否・複数人一括予約・予約枠の定員機能ON・定員2以上）を
		// 再計算せず、この値をそのまま使う。これにより、フロントがサイト全体の「予約枠の定員機能」
		// スイッチ（Staff_Editor::is_slot_capacity_enabled()）を見落として、サーバー側では実効0のはずの
		// 下限がフロントにだけ残り予約できなくなる不整合（安藤レビュー指摘）を防ぐ。判定条件は
		// Availability_Service::get_menu_nomination_min_guests() の1箇所に集約する。
		register_rest_field(
			self::POST_TYPE,
			'vkbm_nomination_min_guests',
			array(
				'get_callback' => array( $this, 'get_nomination_min_guests_rest_field' ),
				'schema'       => array(
					'description' => __( 'Minimum number of guests required to book this menu when staff nomination is used (0 = no restriction).', 'vk-booking-manager' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'default'     => 0,
					'minimum'     => 0,
				),
			)
		);

		// メニューを実際に担当できるリソースID配列（#485）。「すべてのリソースが担当できる」
		// フラグを公開中の全リソースへ展開した結果を読み取り専用で返す。フロント（app.js の
		// extractAssignableStaffIds）は meta._vkbm_staff_ids より先にこの値を使い、
		// 「すべて」の展開ロジックをフロントで二重実装しない。判定は
		// Service_Menu_Post_Type::get_assignable_staff_ids() の1か所に集約する。
		register_rest_field(
			self::POST_TYPE,
			'vkbm_assignable_staff_ids',
			array(
				'get_callback' => array( $this, 'get_assignable_staff_ids_rest_field' ),
				'schema'       => array(
					'description' => __( 'IDs of the resources (staff) that can be in charge of this menu, with "all resources" expanded to the published resources.', 'vk-booking-manager' ),
					'type'        => 'array',
					'items'       => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
			)
		);
	}

	/**
	 * REST レスポンス用に、メニューを担当できるリソースID配列を返す（#485）。
	 *
	 * @param array<string, mixed> $post REST post data.
	 * @return array<int> 担当できるリソースID配列（「すべて」は公開中の全リソースへ展開済み）。
	 */
	public function get_assignable_staff_ids_rest_field( array $post ): array {
		$post_id = isset( $post['id'] ) ? (int) $post['id'] : 0;
		if ( $post_id <= 0 ) {
			return array();
		}

		return self::get_assignable_staff_ids( $post_id );
	}

	/**
	 * REST レスポンス用に、指名を使うメニューの最低申し込み人数を解決する。
	 *
	 * 指名を使うメニューの最低申し込み人数（受付制限）を Availability_Service に委譲して返す。
	 * 表示専用の最少催行人数（get_menu_min_capacity）とは別の取得経路であることに注意。
	 *
	 * @param array<string, mixed> $post REST post data.
	 * @return int 最低申し込み人数（0=制限なし）。
	 */
	public function get_nomination_min_guests_rest_field( array $post ): int {
		$post_id = isset( $post['id'] ) ? (int) $post['id'] : 0;
		if ( $post_id <= 0 ) {
			return 0;
		}

		$menu_post = get_post( $post_id );
		if ( ! $menu_post instanceof WP_Post ) {
			return 0;
		}

		return $this->get_availability_service()->get_menu_nomination_min_guests( $menu_post );
	}

	/**
	 * Availability_Service のインスタンスを遅延生成して返す。
	 *
	 * 初回呼び出し時にのみ生成し、以降は使い回す（安藤レビュー指摘：REST レスポンスの
	 * メニュー1件ごとに新規生成すると、予約ブロックの `per_page: 100` 取得で
	 * 1リクエストあたり最大100個生成されてしまうため）。
	 *
	 * @return Availability_Service
	 */
	private function get_availability_service(): Availability_Service {
		if ( null === $this->availability_service ) {
			$this->availability_service = new Availability_Service();
		}

		return $this->availability_service;
	}

	/**
	 * Resolve the primary group term for REST responses.
	 *
	 * @param array<string, mixed> $post REST post data.
	 * @return array<string, mixed>|null
	 */
	public function get_menu_group_rest_field( array $post ): ?array {
		$post_id = isset( $post['id'] ) ? (int) $post['id'] : 0;
		if ( $post_id <= 0 ) {
			return null;
		}

		$terms = get_the_terms( $post_id, self::TAXONOMY_GROUP );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return null;
		}

		$primary = self::resolve_primary_group_term( $terms );
		if ( ! $primary ) {
			return null;
		}

		return array(
			'id'    => (int) $primary->term_id,
			'name'  => (string) $primary->name,
			'order' => self::get_group_order_value( (int) $primary->term_id ),
		);
	}

	/**
	 * Sort service menus by group order and menu order.
	 *
	 * @param array<int, WP_Post> $posts Service menu posts.
	 * @return array<int, WP_Post>
	 */
	public static function sort_menus_by_group( array $posts ): array {
		$posts = array_values( $posts );
		$index = array();

		foreach ( $posts as $post ) {
			$terms   = get_the_terms( $post, self::TAXONOMY_GROUP );
			$primary = ( empty( $terms ) || is_wp_error( $terms ) )
				? null
				: self::resolve_primary_group_term( $terms );

			$index[ $post->ID ] = array(
				'group_order' => $primary ? self::get_group_order_value( (int) $primary->term_id ) : PHP_INT_MAX,
				'group_name'  => $primary ? (string) $primary->name : '',
				'has_group'   => $primary ? 1 : 0,
				'menu_order'  => (int) $post->menu_order,
				'title'       => (string) $post->post_title,
			);
		}

		usort(
			$posts,
			static function ( WP_Post $a, WP_Post $b ) use ( $index ): int {
				$meta_a = $index[ $a->ID ] ?? null;
				$meta_b = $index[ $b->ID ] ?? null;

				if ( ! $meta_a || ! $meta_b ) {
					return 0;
				}

				if ( $meta_a['group_order'] !== $meta_b['group_order'] ) {
					return $meta_a['group_order'] <=> $meta_b['group_order'];
				}

				if ( $meta_a['has_group'] !== $meta_b['has_group'] ) {
					return $meta_a['has_group'] > $meta_b['has_group'] ? -1 : 1;
				}

				$group_name_compare = strcmp( $meta_a['group_name'], $meta_b['group_name'] );
				if ( 0 !== $group_name_compare ) {
					return $group_name_compare;
				}

				if ( $meta_a['menu_order'] !== $meta_b['menu_order'] ) {
					return $meta_a['menu_order'] <=> $meta_b['menu_order'];
				}

				return strcmp( $meta_a['title'], $meta_b['title'] );
			}
		);

		return $posts;
	}

	/**
	 * Pick primary group term based on stored order (fallback: name).
	 *
	 * @param array<int, mixed> $terms Term list.
	 * @return object|null
	 */
	private static function resolve_primary_group_term( array $terms ): ?object {
		usort(
			$terms,
			static function ( $a, $b ): int {
				$order_a = self::get_group_order_value( (int) $a->term_id );
				$order_b = self::get_group_order_value( (int) $b->term_id );

				if ( $order_a !== $order_b ) {
					return $order_a <=> $order_b;
				}

				return strcmp( (string) $a->name, (string) $b->name );
			}
		);

		return $terms[0] ?? null;
	}

	/**
	 * Get group order value (smaller comes first).
	 *
	 * @param int $term_id Term ID.
	 * @return int
	 */
	private static function get_group_order_value( int $term_id ): int {
		$value = (string) get_term_meta( $term_id, Term_Order_Manager::META_KEY, true );
		$value = trim( $value );

		if ( '' === $value || ! is_numeric( $value ) ) {
			return PHP_INT_MAX;
		}

		return (int) $value;
	}

	/**
	 * Sanitize stored price meta.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public function sanitize_price_meta( $value ): int {
		if ( is_numeric( $value ) ) {
			$int_value = (int) $value;
			return $int_value > 0 ? $int_value : 0;
		}

		return 0;
	}

	/**
	 * Capability map for the post type.
	 *
	 * @return array<string, string>
	 */
	private function get_post_type_capabilities(): array {
		return array(
			'edit_post'              => Capabilities::MANAGE_SERVICE_MENUS,
			'read_post'              => Capabilities::VIEW_SERVICE_MENUS,
			'delete_post'            => Capabilities::MANAGE_SERVICE_MENUS,
			'edit_posts'             => Capabilities::MANAGE_SERVICE_MENUS,
			'edit_others_posts'      => Capabilities::MANAGE_SERVICE_MENUS,
			'publish_posts'          => Capabilities::MANAGE_SERVICE_MENUS,
			'read_private_posts'     => Capabilities::VIEW_SERVICE_MENUS,
			'delete_posts'           => Capabilities::MANAGE_SERVICE_MENUS,
			'delete_private_posts'   => Capabilities::MANAGE_SERVICE_MENUS,
			'delete_published_posts' => Capabilities::MANAGE_SERVICE_MENUS,
			'delete_others_posts'    => Capabilities::MANAGE_SERVICE_MENUS,
			'edit_private_posts'     => Capabilities::MANAGE_SERVICE_MENUS,
			'edit_published_posts'   => Capabilities::MANAGE_SERVICE_MENUS,
			'create_posts'           => Capabilities::MANAGE_SERVICE_MENUS,
		);
	}
}
