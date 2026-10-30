<?php
/**
 * 管理画面でのゲスト予約（会員登録なしの予約）の見分け。
 *
 * 予約一覧の絞り込み「ゲスト予約のみ」と、一覧・編集画面で使う判定を持つ。
 * 無料版・Pro 版の両方で動かすため、Pro 版だけの iCal 取り込み（External_Booking_Display）には置かない。
 * 絞り込みのプルダウンはこのクラスが出し、Pro 版の取り込み元の選択肢はフィルターで足してもらう。
 * 仕様は docs/specification-guest-booking.md の「9. 管理画面」。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Bookings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_Query;

/**
 * ゲスト予約の判定と、予約一覧の絞り込み。
 */
class Guest_Booking_Admin {

	/**
	 * 予約一覧の「予約の出どころ」の絞り込みの、クエリ文字列のキー。
	 *
	 * Pro 版の External_Booking_Display::FILTER_QUERY_VAR と同じ値にそろえる（同じプルダウンを共有するため）。
	 */
	public const FILTER_QUERY_VAR = 'vkbm_booking_source';

	/**
	 * 絞り込みの値: ゲスト予約のみ。
	 */
	public const FILTER_GUEST = 'guest';

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'restrict_manage_posts', array( $this, 'render_source_filter' ), 10, 1 );
		add_action( 'pre_get_posts', array( $this, 'apply_guest_filter' ) );
	}

	/**
	 * 予約が、ゲスト予約（会員登録なしの予約）かを判定する。
	 *
	 * 後から会員に紐付けた予約も、印が残っているのでゲスト予約として扱う。
	 *
	 * @param int $booking_id 予約の投稿 ID。
	 * @return bool ゲスト予約なら true。
	 */
	public static function is_guest_booking( int $booking_id ): bool {
		return Booking_Source::VALUE_GUEST === get_post_meta( $booking_id, Booking_Source::META_KEY, true );
	}

	/**
	 * 予約一覧の絞り込みに「ゲスト予約のみ」を出すかを判定する。
	 *
	 * 受付方法が「会員のみ」で、ゲスト予約が 1 件も無いサイトでは出さない（見た目を変えないため）。
	 *
	 * @return bool 出すなら true。
	 */
	private function should_offer_guest_filter(): bool {
		// 絞り込み中は必ず出す（絞り込みを外せなくなるのと、空の一覧の理由が分からなくなるのを防ぐ）。
		if ( self::FILTER_GUEST === self::get_requested_filter() ) {
			return true;
		}

		$settings = get_option( Settings_Repository::OPTION_KEY, array() );
		$mode     = Settings_Repository::normalize_booking_customer_mode( is_array( $settings ) ? ( $settings[ Settings_Repository::KEY_BOOKING_CUSTOMER_MODE ] ?? null ) : null );
		if ( Settings_Repository::BOOKING_CUSTOMER_MODE_MEMBER !== $mode ) {
			return true;
		}

		// 受付方法を「会員のみ」に戻した後も、すでにあるゲスト予約は絞り込めるようにする。
		$guest_ids = get_posts(
			array(
				'post_type'      => Booking_Post_Type::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'none',
				'meta_key'       => Booking_Source::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- 1 件だけの存在確認。
				'meta_value'     => Booking_Source::VALUE_GUEST, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- 1 件だけの存在確認。
			)
		);

		return array() !== $guest_ids;
	}

	/**
	 * 予約一覧に「予約の出どころ」の絞り込みを出す。
	 *
	 * 選択肢が「すべて」だけのとき（無料版で、ゲスト予約を使っていないサイト）は何も出さない。
	 *
	 * @param string $post_type 表示中の一覧の投稿タイプ。
	 * @return void
	 */
	public function render_source_filter( string $post_type ): void {
		if ( Booking_Post_Type::POST_TYPE !== $post_type ) {
			return;
		}

		$options = array( '' => __( 'All bookings', 'vk-booking-manager' ) );

		/**
		 * 予約一覧の「予約の出どころ」の絞り込みに、選択肢を足す口（Pro 版の外部カレンダーの取り込み元に使う）。
		 *
		 * @param array<string, string> $options 選択肢（値 => 表示名）。
		 */
		$default_options = $options;
		$options         = apply_filters( 'vkbm_booking_source_filter_options', $options );
		if ( ! is_array( $options ) ) {
			$options = $default_options;
		}

		if ( $this->should_offer_guest_filter() ) {
			$options[ self::FILTER_GUEST ] = __( 'Guest bookings only', 'vk-booking-manager' );
		}

		if ( count( $options ) <= 1 ) {
			return;
		}

		$current = self::get_requested_filter();
		?>
		<label for="vkbm-booking-source-filter" class="screen-reader-text"><?php esc_html_e( 'Filter bookings', 'vk-booking-manager' ); ?></label>
		<select name="<?php echo esc_attr( self::FILTER_QUERY_VAR ); ?>" id="vkbm-booking-source-filter">
			<?php foreach ( $options as $value => $label ) : ?>
				<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $current, (string) $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * 「ゲスト予約のみ」の絞り込みを、検索条件に反映する。
	 *
	 * @param WP_Query $query 投稿クエリ。
	 * @return void
	 */
	public function apply_guest_filter( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || Booking_Post_Type::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}

		if ( self::FILTER_GUEST !== self::get_requested_filter() ) {
			return;
		}

		$condition = array(
			'key'   => Booking_Source::META_KEY,
			'value' => Booking_Source::VALUE_GUEST,
		);

		self::add_meta_condition( $query, $condition );
	}

	/**
	 * 他の処理が付けた meta_query を残し、条件を AND で重ねる。
	 *
	 * Pro 版の External_Booking_Display からも使う。
	 *
	 * @param WP_Query             $query     投稿クエリ。
	 * @param array<string, mixed> $condition 足す条件。
	 * @return void
	 */
	public static function add_meta_condition( WP_Query $query, array $condition ): void {
		$existing   = $query->get( 'meta_query' );
		$meta_query = is_array( $existing ) && array() !== $existing ? array( $existing, $condition ) : array( $condition );
		$query->set( 'meta_query', array_merge( array( 'relation' => 'AND' ), $meta_query ) );
	}

	/**
	 * 絞り込みの指定をクエリ文字列から読む。
	 *
	 * Pro 版の選択肢（site・external）の値も、プルダウンで選ばれたままに保つため、そのまま返す。
	 *
	 * @return string 選ばれている値。指定が無いときは空文字（すべて）。
	 */
	public static function get_requested_filter(): string {
		// 一覧の表示を絞るだけで、状態を変えないため nonce は不要。
		$value = isset( $_GET[ self::FILTER_QUERY_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::FILTER_QUERY_VAR ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return $value;
	}
}
