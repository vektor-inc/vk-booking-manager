<?php
/**
 * 管理画面の予約一覧検索。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Bookings;

use VKBookingManager\Attendees\Attendee_Number_Search;
use VKBookingManager\Attendees\Booking_Attendee_Display;
use VKBookingManager\Common\VKBM_Helper;
use VKBookingManager\PostTypes\Booking_Post_Type;
use WP_Query;

/**
 * 語ごとの標準検索条件に予約情報と予約者を加える。
 */
class Booking_List_Search {

	/**
	 * 予約者の一致が上限を超えたか。
	 *
	 * @var bool
	 */
	private $has_overflow = false;

	/**
	 * 一覧用のフックを登録する。
	 */
	public function register(): void {
		add_action( 'pre_get_posts', array( $this, 'limit_search' ) );
		add_filter( 'posts_search', array( $this, 'filter_search' ), 10, 2 );
		add_filter( 'posts_where', array( $this, 'filter_attendee_number' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'render_overflow_notice' ) );
		add_action( 'admin_notices', array( $this, 'render_attendee_number_result' ) );
		add_filter( 'wp_list_table_class_name', array( $this, 'filter_list_table_class' ), 10, 2 );
	}

	/**
	 * 対象の検索かを判定する。
	 *
	 * @param WP_Query $query 投稿クエリ。
	 * @return bool
	 */
	private function is_booking_search( WP_Query $query ): bool {
		return is_admin() && $query->is_main_query() && Booking_Post_Type::POST_TYPE === $query->get( 'post_type' ) && '' !== trim( (string) $query->get( 's' ) );
	}

	/**
	 * 検索語が電話番号に使う文字だけで構成された4桁以上の番号かを判定する。
	 *
	 * @param string $keyword 検索キーワード。
	 * @return bool 電話番号として照合する場合はtrue。
	 */
	private static function is_phone_keyword( string $keyword ): bool {
		if ( ! preg_match( '/^[0-9０-９\s\-－ー‐()（）]+$/u', $keyword ) ) {
			return false;
		}
		$phone = VKBM_Helper::normalize_phone_number( $keyword );
		return strlen( $phone ) >= 4;
	}

	/**
	 * 標準の語分割より前に100文字へ制限し、電話番号だけの入力は一語にする。
	 *
	 * @param WP_Query $query 投稿クエリ。
	 */
	public function limit_search( WP_Query $query ): void {
		if ( $this->is_booking_search( $query ) ) {
			$query->set( 's', mb_substr( (string) $query->get( 's' ), 0, Reserver_Matcher::MAX_KEYWORD_LENGTH ) );
			// 検索欄の入力を保ったまま、空白を含む電話番号の分割を防ぐ。
			$keyword = (string) $query->get( 's' );
			// 除外語を電話番号へ結合すると除外条件が失われるため、先頭語も含めて確認する。
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress標準の除外語フィルターを尊重する。
			$prefix = apply_filters( 'wp_query_search_exclusion_prefix', '-' );
			foreach ( preg_split( '/\s+/u', trim( $keyword ) ) as $term ) {
				if ( $prefix && 0 === strpos( $term, $prefix ) ) {
					return;
				}
			}
			// 日付などの数字のAND検索を保ち、0始まりの電話番号だけを一語にする。
			if ( self::is_phone_keyword( $keyword ) && 0 === strpos( VKBM_Helper::normalize_phone_number( $keyword ), '0' ) ) {
				$query->set( 'sentence', true );
			}
		}
	}

	/**
	 * 標準と同じ語分割・除外語・列を使い、各語の検索対象を広げる。
	 *
	 * @param string   $search 標準の検索SQL。
	 * @param WP_Query $query 投稿クエリ。
	 * @return string
	 */
	public function filter_search( string $search, WP_Query $query ): string {
		global $wpdb;
		if ( ! $this->is_booking_search( $query ) ) {
			return $search;
		}

		$this->has_overflow = false;
		$columns            = array( 'post_title', 'post_excerpt', 'post_content' );
		$requested          = $query->get( 'search_columns' );
		$requested          = $requested ? (array) $requested : $columns;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress標準の検索列フィルターを尊重する。
		$requested = (array) apply_filters( 'post_search_columns', (array) $requested, $query->get( 's' ), $query );
		$allowed   = array_intersect( $columns, $requested );
		$columns   = $allowed ? $allowed : $columns;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress標準の除外語フィルターを尊重する。
		$prefix     = apply_filters( 'wp_query_search_exclusion_prefix', '-' );
		$wildcard   = $query->get( 'exact' ) ? '' : '%';
		$conditions = array();

		$terms = $query->get( 'search_terms' );
		$terms = $terms ? $terms : array( (string) $query->get( 's' ) );
		foreach ( $terms as $term ) {
			$exclude = $prefix && 0 === strpos( $term, $prefix );
			$term    = $exclude ? substr( $term, strlen( $prefix ) ) : $term;
			$like    = $wildcard . $wpdb->esc_like( $term ) . $wildcard;
			$parts   = array();
			foreach ( $columns as $column ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 列は固定の許可リストに限定する。
				$parts[] = $wpdb->prepare( "{$wpdb->posts}.{$column} LIKE %s", $like );
			}

			// 予約メタをJOINせず、行数とページ送りを保つ。
			$meta = $wpdb->prepare( "(booking_meta.meta_key IN ('_vkbm_booking_customer_name', '_vkbm_booking_customer_email', '_vkbm_booking_attendee_name') AND booking_meta.meta_value LIKE %s)", $like );
			if ( self::is_phone_keyword( $term ) ) {
				$phone = VKBM_Helper::normalize_phone_number( $term );
				$meta .= $wpdb->prepare( " OR (booking_meta.meta_key = '_vkbm_booking_customer_tel' AND booking_meta.meta_value LIKE %s)", '%' . $wpdb->esc_like( $phone ) . '%' );
			}
			$parts[] = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} AS booking_meta WHERE booking_meta.post_id = {$wpdb->posts}.ID AND ({$meta}))";

			// 上限を超えた語は予約者で照合せず、予約に保存された情報だけで検索する。
			if ( current_user_can( 'list_users' ) ) {
				$limit = min( 100, max( 1, (int) apply_filters( 'vkbm_booking_search_user_limit', 100 ) ) );
				$ids   = Reserver_Matcher::find_user_ids( $term, $limit + 1, true );
				$ids   = array_values( array_filter( array_map( 'absint', $ids ) ) );
				if ( count( $ids ) > $limit ) {
					$this->has_overflow = true;
				} elseif ( $ids ) {
					$parts[] = "{$wpdb->posts}.post_author IN (" . implode( ',', $ids ) . ')';
				}
			}
			$conditions[] = ( $exclude ? 'NOT ' : '' ) . '(' . implode( ' OR ', $parts ) . ')';
		}

		$search = ' AND (' . implode( ' AND ', $conditions ) . ') ';
		if ( ! is_user_logged_in() ) {
			$search .= " AND ({$wpdb->posts}.post_password = '') ";
		}
		return $search;
	}

	/**
	 * 予約一覧のメインクエリで、指定された番号に一致する予約へ絞り込む。
	 *
	 * 番号機能がオフの施設では、番号の指定があっても絞り込まない。
	 *
	 * @param string   $where 標準の WHERE 句。
	 * @param WP_Query $query 投稿クエリ。
	 * @return string
	 */
	public function filter_attendee_number( string $where, WP_Query $query ): string {
		if ( ! is_admin() || ! $query->is_main_query() || Booking_Post_Type::POST_TYPE !== $query->get( 'post_type' ) || ! Attendee_Number_Search::is_enabled() ) {
			return $where;
		}
		$number = Attendee_Number_Search::normalize_input( Attendee_Number_Search::get_requested_input() );
		if ( '' === $number ) {
			return $where;
		}
		return $where . ( new Attendee_Number_Search() )->build_where( $number );
	}

	/**
	 * 番号で絞り込んだとき、一致した人を一覧の上に表示する。
	 */
	public function render_attendee_number_result(): void {
		if ( ! $this->is_booking_screen() || ! Attendee_Number_Search::is_enabled() ) {
			return;
		}
		$input  = Attendee_Number_Search::get_requested_input();
		$number = Attendee_Number_Search::normalize_input( $input );
		if ( '' === $number ) {
			return;
		}
		$label  = Attendee_Number_Search::get_label();
		$search = new Attendee_Number_Search();
		$people = $search->find_people( $number );

		echo '<div class="notice notice-info vkbm-attendee-number-result" role="status"><p>';
		/* translators: 1: Customer number label, 2: Entered number. */
		echo esc_html( sprintf( __( 'Showing reservations for %1$s "%2$s".', 'vk-booking-manager' ), $label, $input ) );
		echo '</p>';
		if ( $people ) {
			echo '<ul class="vkbm-attendee-number-result__people">';
			foreach ( $people as $person ) {
				echo '<li>';
				$this->render_matched_person( $person );
				echo '</li>';
			}
			echo '</ul>';
			if ( $search->is_people_truncated() ) {
				echo '<p>' . esc_html__( 'Only some of the matching people are shown because there are many. All matching reservations are shown in the reservation list below.', 'vk-booking-manager' ) . '</p>';
			}
		} elseif ( ! $search->has_hidden_people( $number ) ) {
			// 権限で人が出せないときは、予約は一覧にあるため何も添えない。
			echo '<p>' . esc_html__( 'No matching reservations were found.', 'vk-booking-manager' ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * 一致した人1人分の表示を出力する。
	 *
	 * 名簿の人の名前が会員の表示名と違うときだけ、会員名を「（会員: ○○）」として添え、リンクは会員名に付ける。
	 *
	 * @param array{name:string,number:string,user_id:int,member_name:string,is_self:bool,in_roster:bool} $person 一致した人。
	 */
	private function render_matched_person( array $person ): void {
		$summary  = Booking_Attendee_Display::format_summary( $person['name'], $person['number'] );
		$edit_url = $person['user_id'] > 0 ? get_edit_user_link( $person['user_id'] ) : '';
		if ( ! $edit_url ) {
			echo esc_html( $summary );
			if ( ! $person['in_roster'] ) {
				echo ' ' . esc_html__( '(not registered in the current saved list)', 'vk-booking-manager' );
			}
			return;
		}
		if ( $person['is_self'] || '' === $person['member_name'] || $person['member_name'] === $person['name'] ) {
			printf( '<a href="%1$s">%2$s</a>', esc_url( $edit_url ), esc_html( $summary ) );
			return;
		}
		echo esc_html( $summary ) . ' ';
		$member_link = sprintf( '<a href="%1$s">%2$s</a>', esc_url( $edit_url ), esc_html( $person['member_name'] ) );
		/* translators: %s: Link to the member edit screen (member name). */
		echo wp_kses( sprintf( __( '(member: %s)', 'vk-booking-manager' ), $member_link ), array( 'a' => array( 'href' => array() ) ) );
	}

	/**
	 * 上限超過時に絞り込みを案内する。
	 */
	public function render_overflow_notice(): void {
		if ( ! $this->has_overflow || ! $this->is_booking_screen() ) {
			return;
		}
		echo '<div class="notice notice-warning" role="status"><p>';
		echo esc_html__( 'Account information was excluded from this search because too many reserver accounts match.', 'vk-booking-manager' ) . ' ';
		echo esc_html__( 'Showing results from information saved with reservations.', 'vk-booking-manager' );
		echo ' ' . esc_html__( 'Please enter a more specific name or email address and search again.', 'vk-booking-manager' );
		echo '</p></div>';
	}

	/**
	 * 予約一覧画面かを判定する。
	 *
	 * @return bool
	 */
	private function is_booking_screen(): bool {
		$screen = get_current_screen();
		return $screen && 'edit' === $screen->base && Booking_Post_Type::POST_TYPE === $screen->post_type;
	}

	/**
	 * 予約一覧だけ検索欄を拡張した一覧クラスにする。
	 *
	 * @param string $class_name 一覧クラス名。
	 * @param array  $args 一覧の生成引数。
	 * @return string
	 */
	public function filter_list_table_class( string $class_name, array $args ): string {
		$screen = $args['screen'] ?? null;
		if ( 'WP_Posts_List_Table' === $class_name && $screen instanceof \WP_Screen && 'edit' === $screen->base && Booking_Post_Type::POST_TYPE === $screen->post_type ) {
			return Booking_Posts_List_Table::class;
		}
		return $class_name;
	}

	/**
	 * 権限に応じた検索案内と、番号の欄の説明を出力する。
	 *
	 * @param bool $with_keyword_help 既存の検索欄の案内も出すか。標準の検索欄が出ないときは false。
	 */
	public function render_search_help( bool $with_keyword_help = true ): void {
		if ( ! $this->is_booking_screen() ) {
			return;
		}
		if ( $with_keyword_help ) {
			echo '<p id="vkbm-booking-search-help" class="description vkbm-booking-search-help">';
			if ( current_user_can( 'list_users' ) ) {
				echo esc_html__( 'Search by reservation title, name, phone number (no hyphens needed), email address, or current booking account details (username, phonetic name, etc.).', 'vk-booking-manager' );
			} else {
				echo esc_html__( 'Search by reservation title, name, phone number (no hyphens needed), or email address.', 'vk-booking-manager' );
			}
			echo '</p>';
		}
		if ( Attendee_Number_Search::is_enabled() ) {
			// 番号の欄の説明は、既存の検索欄の案内の下に置く。
			echo '<p id="vkbm-attendee-number-search-help" class="description vkbm-booking-search-help">';
			/* translators: %s: Customer number label. */
			echo esc_html( sprintf( __( '%s is matched exactly.', 'vk-booking-manager' ), Attendee_Number_Search::get_label() ) ) . ' ';
			echo esc_html__( 'Full-width and half-width characters and surrounding spaces are not distinguished.', 'vk-booking-manager' );
			echo '</p>';
		}
	}
}
