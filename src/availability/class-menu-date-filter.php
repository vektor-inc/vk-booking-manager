<?php
/**
 * 絞り込み検索の日付指定で、選んだ日（宿泊のサイトは選んだ日程）に予約できるサービスメニューを判定する。
 *
 * 予約ブロックの絞り込み検索に日付を入れると、その日に予約画面のカレンダーで選べる
 * （受付の時間枠が1つ以上ある）メニューだけを一覧に残す。判定そのものは
 * Availability_Service::is_date_bookable_for_menu() に任せ、このクラスは
 * 複数メニューをまとめて判定することと、結果の短時間キャッシュを受け持つ。
 *
 * 宿泊のサイト（Pro 版）はチェックイン日・チェックアウト日で絞り込む。泊のプランは
 * Availability_Service::is_stay_bookable_for_menu()（その日程で空室が1室以上あるか）、
 * 時間枠のプラン（日帰り入浴・貸切風呂など）はチェックイン日1日で is_date_bookable_for_menu() を使う。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Availability;

use VKBookingManager\Common\Lodging_Price;
use VKBookingManager\Common\Resource_Tag_Id_List;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\Resources\Resource_Tag_Taxonomy;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use function current_datetime;
use function sanitize_text_field;
use function wp_unslash;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 日付で予約できるメニューを判定するクラス。
 */
class Menu_Date_Filter {

	/**
	 * 判定結果をキャッシュする秒数。
	 *
	 * @var int
	 */
	public const CACHE_TTL = MINUTE_IN_SECONDS;

	/**
	 * 空き状況の計算元。
	 *
	 * @var Availability_Service
	 */
	private Availability_Service $availability_service;

	/**
	 * コンストラクタ。
	 *
	 * @param Availability_Service|null $availability_service 空き状況の計算元。省略時は新規に作る。
	 */
	public function __construct( ?Availability_Service $availability_service = null ) {
		$this->availability_service = $availability_service ?? new Availability_Service();
	}

	/**
	 * このサイトが、チェックイン日・チェックアウト日で絞り込む宿泊のサイトかを返す。
	 *
	 * 宿泊のサイト（Pro 版かつ業種プリセットが宿泊）だけ true。それ以外は選んだ1日で絞り込む。
	 *
	 * @return bool 宿泊の日程で絞り込むサイトなら true。
	 */
	public static function is_stay_mode(): bool {
		return Industry_Presets::supports( 'lodging' );
	}

	/**
	 * 日付文字列が Y-m-d 形式の実在する日付かを返す。
	 *
	 * @param string $date 日付文字列。
	 * @return bool 実在する Y-m-d なら true。
	 */
	public static function is_valid_date( string $date ): bool {
		return Availability_Service::is_valid_date_string( $date );
	}

	/**
	 * URL やリンクで引き継ぐ日付（date / check_out_date）を検証し、使える値だけを返す。
	 *
	 * 詳細ページへのリンク・予約ボタンのリンク・個別ページからの転送先に付ける日付をそろえるために使う。
	 * date が Y-m-d として実在しなければ両方とも空にする。
	 * 宿泊のサイトでは、check_out_date が実在し日程に矛盾が無いときだけ残す（チェックアウト日が空・矛盾のときは両方とも空）。
	 * 宿泊以外のサイトでは check_out_date を空にする。過去日かどうかは判定しない（受け取った側が案内に使う）。
	 *
	 * @param string $date           チェックイン日または選んだ日（Y-m-d）。
	 * @param string $check_out_date チェックアウト日（Y-m-d）。
	 * @return array<string,string> 値が残ったキー（date・check_out_date）だけを持つ配列。引き継ぐ日付が無ければ空配列。
	 */
	public static function normalize_carry_dates( string $date, string $check_out_date = '' ): array {
		if ( ! self::is_valid_date( $date ) ) {
			return array();
		}

		if ( ! self::is_stay_mode() ) {
			return array( 'date' => $date );
		}

		// 宿泊: 日程が不完全（チェックアウト日が空）なときは引き継がない。予約ブロック側（JS）も同じ扱い。
		if ( ! self::is_valid_date( $check_out_date ) || null !== Night_Strategy::validate_stay_range( $date, $check_out_date ) ) {
			return array();
		}

		return array(
			'date'           => $date,
			'check_out_date' => $check_out_date,
		);
	}

	/**
	 * 表示中の URL のクエリ（date / check_out_date）から、引き継ぐ日付を返す。
	 *
	 * 検証は {@see self::normalize_carry_dates()} と同じ。
	 *
	 * @return array<string,string> 値が残ったキー（date・check_out_date）だけを持つ配列。引き継ぐ日付が無ければ空配列。
	 */
	public static function carry_dates_from_request(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 表示内容を引き継ぐだけの読み取り専用パラメーター。
		$date           = isset( $_GET['date'] ) && is_string( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : '';
		$check_out_date = isset( $_GET['check_out_date'] ) && is_string( $_GET['check_out_date'] ) ? sanitize_text_field( wp_unslash( $_GET['check_out_date'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return self::normalize_carry_dates( $date, $check_out_date );
	}

	/**
	 * 渡したメニューのうち、選んだ日に予約できるものの ID を返す。
	 *
	 * 日付が空・不正なら、絞り込まず渡した ID をそのまま返す。
	 * 宿泊のサイトでは $date をチェックイン日として扱い、チェックアウト日が空・不正なときも絞り込まない。
	 * 宿泊のサイトで日程に矛盾（チェックアウト日がチェックイン日以前・泊数が上限超え）があるときは、予約できるメニューなしにする。
	 * 返す ID の並びは渡した順を保つ。
	 *
	 * @param array<int> $menu_ids       判定するメニュー投稿 ID（表示順）。
	 * @param string     $date           選んだ日（Y-m-d。宿泊のサイトはチェックイン日）。
	 * @param int        $staff_id       絞り込み検索で選択中のリソース（スタッフ・客室）の投稿 ID。0 は指名なし。
	 * @param array<int> $tag_ids        絞り込み検索で選択中のリソースタグのターム ID（AND 条件）。
	 * @param string     $check_out_date チェックアウト日（Y-m-d）。宿泊のサイトだけ使い、それ以外では無視する。
	 * @return array<int> 選んだ日（日程）に予約できるメニュー ID。
	 */
	public function filter_menu_ids( array $menu_ids, string $date, int $staff_id = 0, array $tag_ids = array(), string $check_out_date = '' ): array {
		$menu_ids = array_values( array_map( 'intval', $menu_ids ) );

		// 日付が空、または Y-m-d として読めない場合は絞り込まない。
		// 2000〜2100年の外の日付は REST 側の検証が 400 で弾くため、通常はここへ届かない。
		// 直接呼ばれた場合に備え、下の範囲判定でも「予約できるメニューなし」にする。
		$is_readable_date = 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $date_parts ) && checkdate( (int) $date_parts[2], (int) $date_parts[3], (int) $date_parts[1] );
		if ( ! $is_readable_date ) {
			return $menu_ids;
		}

		// 宿泊のサイトはチェックアウト日まで揃っているときだけ絞り込む。日程に矛盾があれば予約できるメニューなし。
		$is_stay_mode = self::is_stay_mode();
		if ( ! $is_stay_mode ) {
			$check_out_date = '';
		} elseif ( ! self::is_valid_date( $check_out_date ) ) {
			return $menu_ids;
		} elseif ( null !== Night_Strategy::validate_stay_range( $date, $check_out_date ) ) {
			return array();
		}

		// 今日より前・受付できる期間より先の日付は、判定もキャッシュもせず「予約できるメニューなし」にする
		// （範囲外の日付ごとにキャッシュが増えるのを防ぐ）。
		if ( ! $this->is_date_in_range( $menu_ids, $date ) ) {
			return array();
		}

		// 実際の判定に使う値へそろえてからキャッシュ名を作る（無効な値で別のキャッシュ名を作らせない）。
		$staff_id  = $this->resolve_effective_staff_id( $staff_id );
		$tag_ids   = $this->drop_unknown_tag_ids( Resource_Tag_Id_List::normalize( $tag_ids ) );
		$cache_key = $this->build_cache_key( $date, $staff_id, $tag_ids, $check_out_date );

		// 日付・リソース・タグの組み合わせごとに「メニューID => 予約できるか」を1つの値として持つ。
		$cached = get_transient( $cache_key );
		$map    = is_array( $cached ) ? $cached : array();
		$dirty  = false;

		foreach ( $menu_ids as $menu_id ) {
			if ( isset( $map[ $menu_id ] ) ) {
				continue;
			}

			$args = array(
				'resource_id'      => $staff_id,
				'resource_tag_ids' => $tag_ids,
			);

			// 宿泊のサイトの泊のプランは日程全体の空室で、時間枠のプランはチェックイン日1日で判定する。
			$map[ $menu_id ] = $is_stay_mode && Lodging_Price::is_applicable( $menu_id )
				? $this->availability_service->is_stay_bookable_for_menu( $menu_id, $date, $check_out_date, $args )
				: $this->availability_service->is_date_bookable_for_menu( $menu_id, $date, $args );
			$dirty           = true;
		}

		if ( $dirty ) {
			set_transient( $cache_key, $map, self::CACHE_TTL );
		}

		return array_values(
			array_filter(
				$menu_ids,
				static function ( int $menu_id ) use ( $map ): bool {
					return ! empty( $map[ $menu_id ] );
				}
			)
		);
	}

	/**
	 * 存在しないリソースタグの ID を落とす。
	 *
	 * 存在しない ID でキャッシュ名（transient）が増え続けないよう、キャッシュ名を作る前に、
	 * 実在するタグだけにそろえる（組み合わせの数を実在するタグまでに抑える）。
	 *
	 * 存在しないタグを含めて指定しても一覧が広がらない（指定なしと同じ結果になる）のは、
	 * 呼び出し側が日付の判定より前にタグで絞り込んでいるため。この関数が落とすのはキャッシュ名に使うタグだけで、
	 * 絞り込みの結果そのものは変えない。
	 *
	 * @param array<int> $tag_ids 正規化済みのリソースタグのターム ID。
	 * @return array<int> 実在するリソースタグのターム ID。
	 */
	private function drop_unknown_tag_ids( array $tag_ids ): array {
		return array_values(
			array_filter(
				$tag_ids,
				static function ( int $tag_id ): bool {
					return (bool) term_exists( $tag_id, Resource_Tag_Taxonomy::TAXONOMY );
				}
			)
		);
	}

	/**
	 * 実際の判定に使うリソース（スタッフ）の投稿 ID を返す。
	 *
	 * サイト全体の指名機能が無効（Staff_Editor::is_nomination_enabled() が false。無料版を含む）、
	 * または公開中のリソースでない ID は 0（指名なし）として扱う。
	 * 絞り込み検索のスタッフ欄の表示条件、およびメニュー一覧のスタッフ絞り込み
	 * （Menu_Loop_Block::is_menu_visible_for_staff_filter()）と同じ条件にそろえている。
	 *
	 * @param int $staff_id 絞り込み検索で選択中のリソースの投稿 ID。
	 * @return int 判定に使うリソースの投稿 ID（指名なしは 0）。
	 */
	private function resolve_effective_staff_id( int $staff_id ): int {
		if ( $staff_id <= 0 || ! Staff_Editor::is_nomination_enabled() ) {
			return 0;
		}

		$post = get_post( $staff_id );

		return ( $post && Resource_Post_Type::POST_TYPE === $post->post_type && 'publish' === $post->post_status ) ? $staff_id : 0;
	}

	/**
	 * 日付が判定する範囲（今日以降、かつ受付できる期間内）かを返す。
	 *
	 * 受付日数は基本設定の値を基準にし、メニュー側で上書きしている場合はそのうち最も長いものまで許す
	 * （上書きで長くしたメニューを誤って除外しないため）。受付日数が無制限の場合は 2100 年末までとする。
	 *
	 * @param array<int> $menu_ids 判定するメニュー投稿 ID。
	 * @param string     $date     対象日（Y-m-d）。
	 * @return bool 判定する範囲内なら true。
	 */
	private function is_date_in_range( array $menu_ids, string $date ): bool {
		$today = current_datetime()->format( 'Y-m-d' );
		if ( $date < $today ) {
			return false;
		}

		$settings = ( new Settings_Repository() )->get_settings();
		$max_days = max( 0, (int) ( $settings['provider_max_advance_booking_days'] ?? 0 ) );
		foreach ( $menu_ids as $menu_id ) {
			$meta = get_post_meta( $menu_id, '_vkbm_max_advance_booking_days', true );
			if ( '' === $meta ) {
				continue;
			}
			$menu_days = max( 0, (int) $meta );
			// 0 は無制限。
			if ( 0 === $menu_days || 0 === $max_days ) {
				$max_days = 0;
				break;
			}
			$max_days = max( $max_days, $menu_days );
		}

		$latest = $max_days > 0
			? current_datetime()->modify( sprintf( '+%d days', $max_days ) )->format( 'Y-m-d' )
			: '2100-12-31';

		return $date <= min( $latest, '2100-12-31' );
	}

	/**
	 * 判定結果のキャッシュ名を作る。
	 *
	 * サイト全体の世代番号（シフト・メニュー・スタッフ・設定の変更で進む）と、
	 * その日の世代番号（その日に関わる予約の変更で進む）を名前に混ぜる。
	 * どちらかが進むと古い名前は参照されなくなり、予約や設定の変更が一覧にすぐ反映される。
	 * 宿泊の日程指定（$check_out_date あり）は、チェックイン日からチェックアウト日までの全日の世代番号を混ぜる
	 * （期間の途中の日に入った予約でも古い結果が残らないようにするため）。
	 *
	 * @param string     $date           対象日（Y-m-d。宿泊はチェックイン日）。
	 * @param int        $staff_id       リソースの投稿 ID（0 は指名なし）。
	 * @param array<int> $tag_ids        リソースタグのターム ID。
	 * @param string     $check_out_date チェックアウト日（Y-m-d）。宿泊の日程指定でないときは空文字。
	 * @return string キャッシュ名。
	 */
	private function build_cache_key( string $date, int $staff_id, array $tag_ids, string $check_out_date = '' ): string {
		$sorted_tag_ids = $tag_ids;
		sort( $sorted_tag_ids );

		// 非公開メニューを予約できる権限の有無で結果が変わるため、キャッシュ名を分ける。
		$private_flag = $this->availability_service->can_book_private_menu() ? 1 : 0;

		if ( '' === $check_out_date ) {
			return sprintf(
				'vkbm_menudate_g%d_bg%d_%s_s%d_t%s_p%d',
				Availability_Cache_Generation::get_generation(),
				Availability_Booking_Cache_Generation::get_daily_generation( $date ),
				$date,
				max( 0, $staff_id ),
				md5( implode( '-', $sorted_tag_ids ) ),
				$private_flag
			);
		}

		return sprintf(
			'vkbm_menustay_g%d_bg%s_%s_%s_s%d_t%s_p%d',
			Availability_Cache_Generation::get_generation(),
			Availability_Booking_Cache_Generation::get_range_generation_hash( $date, $check_out_date ),
			$date,
			$check_out_date,
			max( 0, $staff_id ),
			md5( implode( '-', $sorted_tag_ids ) ),
			$private_flag
		);
	}
}
