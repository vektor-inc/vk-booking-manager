<?php
/**
 * 泊単位のプランの予約（下書き・確定）に共通する検証と料金計算。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Bookings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DateTimeImmutable;
use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\Availability\Night_Strategy;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Common\Nomination_Min_Guests_Message;
use VKBookingManager\Common\Lodging_Price;
use VKBookingManager\Common\Lodging_Rate;
use VKBookingManager\Common\Price_Tiers;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\Shifts\Shift_Sale_Days;
use VKBookingManager\Staff\Staff_Editor;
use WP_Error;
use function __;
use function _n;
use function count;
use function current_datetime;
use function current_user_can;
use function get_post_meta;
use function in_array;
use function sprintf;
use function vkbm_get_resource_label_singular;

/**
 * チェックイン日・チェックアウト日・客室を受け取る泊の経路の、下書き・確定で共通の処理をまとめたクラス。
 *
 * 日付・客室の検証は Availability_Service::resolve_lodging_stay()（空き状況と同じ判定）に委ね、
 * ここでは予約に固有の確認（客室の指名が必須・予約受付締切・販売日）と、泊数・1泊単価・合計金額の計算を行う。
 * 金額・泊数・チェックイン／チェックアウトの日時は、送られてきた値を使わず、すべてここでサーバー側で計算する。
 */
class Lodging_Booking {

	/**
	 * 予約に保存する泊数のメタキー。
	 */
	public const META_NIGHTS = '_vkbm_booking_nights';

	/**
	 * 予約に保存する1泊あたりの合計（プランの料金＋客室の追加料金。部屋全体）のメタキー。
	 *
	 * すべての泊が同じ金額の予約にだけ保存する。泊ごとに金額が違う予約は META_NIGHT_BREAKDOWN を使う。
	 */
	public const META_PRICE_PER_NIGHT = '_vkbm_booking_price_per_night';

	/**
	 * 予約に保存する泊ごとの内訳のメタキー（#737）。
	 *
	 * 値は `[ [ 'date' => Y-m-d, 'rate' => weekday|pre_holiday|special, 'plan_price' => int, 'room_surcharge' => int, 'price' => int ], ... ]`。
	 * 確定時の金額を固定で残す。後から料金設定を変えても、過去の予約の金額は変わらない。
	 */
	public const META_NIGHT_BREAKDOWN = '_vkbm_booking_night_breakdown';

	/**
	 * 下書きのスロット ID の接頭辞。時間枠のスロット ID と区別するために付ける。
	 */
	public const SLOT_ID_PREFIX = 'lodging-';

	/**
	 * 予約が泊の予約（チェックイン日〜チェックアウト日の1件）かを、予約に保存した泊数で判定する。
	 *
	 * サイトの業種やメニューの現在の設定は見ない。宿泊から別の業種へ切り替えたあとも、
	 * 受付済みの泊の予約を保存した日時のとおりに表示するため（表示の分岐は「予約が泊か」で決める）。
	 *
	 * @param int $booking_id 予約の投稿ID。
	 * @return bool 泊の予約なら true。
	 */
	public static function is_stay_booking( int $booking_id ): bool {
		return self::get_booking_nights( $booking_id ) > 0;
	}

	/**
	 * 予約に保存した泊数を返す。泊の予約でなければ 0。
	 *
	 * @param int $booking_id 予約の投稿ID。
	 * @return int 泊数。
	 */
	public static function get_booking_nights( int $booking_id ): int {
		if ( $booking_id <= 0 ) {
			return 0;
		}

		return max( 0, (int) get_post_meta( $booking_id, self::META_NIGHTS, true ) );
	}

	/**
	 * 泊数の表示用ラベル（例: 1泊）を返す。
	 *
	 * @param int $nights 泊数。
	 * @return string 表示用ラベル。
	 */
	public static function format_nights_label( int $nights ): string {
		return sprintf(
			/* translators: %d: number of nights */
			_n( '%d night', '%d nights', $nights, 'vk-booking-manager' ),
			$nights
		);
	}

	/**
	 * 泊単位のプランを時間枠の経路で予約しようとしたときのエラー。
	 *
	 * @return WP_Error
	 */
	public static function dates_required_error(): WP_Error {
		return new WP_Error(
			'lodging_dates_required',
			__( 'This plan is billed per night. Please select the check-in and check-out dates.', 'vk-booking-manager' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * 期間に販売日でない泊が含まれるときのエラー。
	 *
	 * @return WP_Error
	 */
	public static function stay_not_on_sale_error(): WP_Error {
		return new WP_Error(
			'stay_not_on_sale',
			__( 'The selected dates include a day when reservations are not accepted. Please choose different dates.', 'vk-booking-manager' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * 担当客室の設定を示すエラーを、予約の管理権限のない利用者には汎用のエラーに置き換える。
	 *
	 * 空き状況の窓口（Availability_Controller）と同じ汎用エラーを使う。対象は担当客室の設定を示す
	 * staff_not_assigned・resource_not_found・staff_not_configured。それ以外のエラーはそのまま返す。
	 *
	 * @param WP_Error $error 元のエラー。
	 * @return WP_Error 権限に応じたエラー。
	 */
	public static function mask_room_error_for_visitors( WP_Error $error ): WP_Error {
		if ( ! in_array( $error->get_error_code(), array( 'staff_not_assigned', 'resource_not_found', 'staff_not_configured' ), true ) || current_user_can( Capabilities::MANAGE_RESERVATIONS ) ) {
			return $error;
		}

		return self::build_reservation_unavailable_error();
	}

	/**
	 * 「現在この内容では予約を受け付けていない」ことを伝える汎用エラーを作る（内部設定を示さない）。
	 *
	 * @return WP_Error
	 */
	public static function build_reservation_unavailable_error(): WP_Error {
		// 対処方法を別の文として案内する（1翻訳関数につき1文）。結合は文末が非ASCIIかで区切りを切り替える join_sentences() に任せる。
		$message = Nomination_Min_Guests_Message::join_sentences(
			__( 'We are not currently accepting reservations for this content.', 'vk-booking-manager' ),
			__( 'Please choose a different menu, or contact the site administrator for assistance.', 'vk-booking-manager' )
		);

		return new WP_Error( 'reservation_unavailable', $message, array( 'status' => 400 ) );
	}

	/**
	 * リクエストが泊の経路（チェックイン日・チェックアウト日を含む）かを返す。
	 *
	 * @param array<string, mixed> $params リクエストのパラメーター。
	 * @return bool 泊の経路なら true。
	 */
	public static function is_requested( array $params ): bool {
		return array_key_exists( 'check_in_date', $params ) || array_key_exists( 'check_out_date', $params );
	}

	/**
	 * 泊の予約に必要な検証を行い、保存する日時・泊数・金額を計算して返す。
	 *
	 * $args のキー:
	 * - menu_id, resource_id（客室。必須）, check_in_date, check_out_date（Y-m-d）
	 * - guests（人数）, guest_tiers（料金区分ごとの人数。区分のあるメニューでは人数はこちらから決まる）
	 *   人数は resolve_guests() で決める（空き状況と同じ決め方）。解決した人数と区分の内訳は戻り値の guests / guest_tiers に入る。
	 *
	 * 確認する内容: 宿泊が有効か・泊単位のメニューか・日付・予約受付締切・客室（Availability_Service::resolve_lodging_stay()）、
	 * 客室の指名、期間のすべての泊が販売日であること。
	 * 予約受付締切（チェックイン日時がメニューの締切の内側なら拒否）は resolve_lodging_stay() が空き状況と共有して判定する。
	 *
	 * @param Availability_Service $service 空き状況のサービス。
	 * @param array<string, mixed> $args    引数。
	 * @return array<string, mixed>|WP_Error 検証済みの滞在の情報。問題があればエラー。
	 */
	public static function prepare( Availability_Service $service, array $args ) {
		// 宿泊が有効か・泊単位のメニューか・日付・予約受付締切・客室を検証する（空き状況と同じ判定）。
		$stay = $service->resolve_lodging_stay( $args );
		if ( is_wp_error( $stay ) ) {
			return $stay;
		}

		// 客室の指名は必須（おまかせの割り当ては泊の予約では行わない）。
		$room_id = max( 0, (int) ( $args['resource_id'] ?? 0 ) );
		if ( $room_id <= 0 ) {
			return new WP_Error(
				'room_required',
				sprintf(
					/* translators: %s: resource label (singular), e.g. "Room". */
					__( 'Please select a %s.', 'vk-booking-manager' ),
					vkbm_get_resource_label_singular()
				),
				array( 'status' => 400 )
			);
		}

		$menu_id        = $stay['menu']->ID;
		$timezone       = $stay['timezone'];
		$check_in_date  = $stay['check_in_date'];
		$check_out_date = $stay['check_out_date'];
		$nights         = $stay['nights'];

		// チェックイン・チェックアウトの日時（サイトのタイムゾーン）を作る。
		$check_in_time  = Service_Menu_Post_Type::get_check_in_time( $menu_id );
		$check_out_time = Service_Menu_Post_Type::get_check_out_time( $menu_id );
		$check_in_at    = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $check_in_date . ' ' . $check_in_time, $timezone );
		$check_out_at   = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $check_out_date . ' ' . $check_out_time, $timezone );
		if ( ! $check_in_at || ! $check_out_at || $check_out_at <= $check_in_at ) {
			return new WP_Error( 'invalid_date_range', __( 'The check-out date must be after the check-in date.', 'vk-booking-manager' ), array( 'status' => 400 ) );
		}

		// 期間のすべての泊が、この客室の販売日であること。
		if ( ! self::is_stay_on_sale( $room_id, $check_in_date, $check_out_date ) ) {
			return self::stay_not_on_sale_error();
		}

		// 人数と料金区分の内訳を決める（空き状況の問い合わせと同じ決め方。上限超過は丸めずに拒否する）。
		$resolved = self::resolve_guests( $menu_id, (int) ( $args['guests'] ?? 1 ), $args['guest_tiers'] ?? array() );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		return array(
			'menu_id'          => $menu_id,
			'room_id'          => $room_id,
			'check_in_date'    => $check_in_date,
			'check_out_date'   => $check_out_date,
			'nights'           => $nights,
			'check_in_at'      => $check_in_at->format( 'c' ),
			'check_out_at'     => $check_out_at->format( 'c' ),
			'check_in_stored'  => $check_in_at->format( 'Y-m-d H:i:s' ),
			'check_out_stored' => $check_out_at->format( 'Y-m-d H:i:s' ),
			'guests'           => $resolved['guests'],
			'guest_tiers'      => $resolved['guest_tiers'],
			'price'            => self::calculate_price( $menu_id, $room_id, $check_in_date, $nights, $resolved['guests'], $resolved['guest_tiers'] ),
		);
	}

	/**
	 * 泊単位のプランの人数と料金区分の内訳を決める（空き状況・下書き・確定で共通）。
	 *
	 * 上限は複数人一括予約の設定（Pro 版・予約枠の定員機能・複数人一括予約の許可・最大予約人数・担当客室あり）で決まる。
	 * 設定が揃っていなければ上限は 1 名。上限を超える人数は 1 名に丸めず 400（guests_exceeded）で拒否する
	 * （丸めると、表示した料金より安い金額で予約が確定してしまうため）。
	 * 料金区分（1名1泊あたりのメニューで、区分があり上限が 2 名以上のとき）は、人数を区分ごとの内訳から決める（合計 0 名は 400）。
	 * 追加料金を使わない1室1泊あたりのメニューは区分を使わず、人数は 1 名以上であればよい（料金には影響しない）。
	 * 追加料金を使う1室1泊あたりのメニューは、1名1泊あたりと同じく区分ごとの人数から人数を決める（#934）。
	 *
	 * @param int   $menu_id         メニューの投稿 ID。
	 * @param int   $requested       送られた人数。
	 * @param mixed $requested_tiers 送られた区分ごとの人数。
	 * @return array{guests: int, guest_tiers: array<int, array{label: string, price: int, count: int}>}|WP_Error
	 */
	public static function resolve_guests( int $menu_id, int $requested, $requested_tiers ) {
		$max   = self::get_max_guests( $menu_id );
		$limit = max( 1, $max );

		$guest_tiers = array();
		$guests      = max( 1, $requested );
		$menu_tiers  = self::get_menu_price_tiers( $menu_id, $max );
		if ( ! empty( $menu_tiers ) ) {
			$guest_tiers = Price_Tiers::resolve_guest_tiers( $menu_tiers, $requested_tiers );
			$guests      = Price_Tiers::total_count( $guest_tiers );
			if ( $guests < 1 ) {
				return new WP_Error(
					'guests_required',
					__( 'Please select at least one guest.', 'vk-booking-manager' ),
					array( 'status' => 400 )
				);
			}
		}

		if ( $guests > $limit ) {
			return new WP_Error(
				'guests_exceeded',
				sprintf(
					/* translators: %d: maximum number of guests that can be booked. */
					__( 'The number of guests exceeds the maximum that can be booked (%d).', 'vk-booking-manager' ),
					$limit
				),
				array( 'status' => 400 )
			);
		}

		return array(
			'guests'      => $guests,
			'guest_tiers' => $guest_tiers,
		);
	}

	/**
	 * 1予約で申し込める最大人数を返す（予約画面の人数欄の上限に使う）。
	 *
	 * resolve_guests() の上限と同じ値。複数人一括予約が適用されないメニューは 1 名。
	 *
	 * @param int $menu_id メニューの投稿 ID。
	 * @return int 最大人数（1 以上）。
	 */
	public static function get_guest_limit( int $menu_id ): int {
		return max( 1, self::get_max_guests( $menu_id ) );
	}

	/**
	 * 人数を料金区分ごとに数えるメニューの区分（名前・単価）を返す。
	 *
	 * 区分ごとの人数で料金が決まるのは、区分が定義され、上限が 2 名以上の、次のどちらかのメニューだけ。
	 * - 1名1泊あたりのメニュー（区分ごとの単価 × 人数）。
	 * - 1室1泊あたりで、規定人数を超えた分の追加料金を使うメニュー（区分は超過1名・1泊あたりの追加料金。#934）。
	 * それ以外のメニュー（追加料金を使わない1室1泊あたり・区分が無い・上限が 1 名）は空配列（人数は単一の数値で決まる）。
	 *
	 * @param int      $menu_id メニューの投稿 ID。
	 * @param int|null $max     get_max_guests() の結果。呼び出し側で求め済みなら渡して再計算を避ける。
	 * @return array<int, array{label: string, price: int}> 区分の一覧。区分を使わないときは空配列。
	 */
	public static function get_menu_price_tiers( int $menu_id, ?int $max = null ): array {
		$max         = $max ?? self::get_max_guests( $menu_id );
		$is_per_room = Service_Menu_Post_Type::is_night_price_per_room( $menu_id );
		if ( $max < 2 || ( $is_per_room && ! Service_Menu_Post_Type::is_night_extra_guest_fee_enabled( $menu_id ) ) ) {
			return array();
		}

		return Price_Tiers::normalize_tiers( get_post_meta( $menu_id, '_vkbm_price_tiers', true ) );
	}

	/**
	 * メニュー設定から「1予約あたりの最大人数」を求める。複数人一括予約が適用されない場合は 0。
	 *
	 * 時間枠の予約の最大人数（Booking_Draft_Controller / Booking_Confirmation_Controller の get_max_guests / resolve_guests）と
	 * 同じ条件の3か所目。複数人一括予約の適用条件や上限の式を変えるときは、3か所を同じ式で更新すること。
	 * 「複数人一括予約が有効か」の判定は Service_Menu_Post_Type::is_multi_guest_booking_enabled() に集約している。
	 *
	 * @param int $menu_id メニューの投稿 ID。
	 * @return int 最大人数。適用されない場合は 0。
	 */
	private static function get_max_guests( int $menu_id ): int {
		if ( $menu_id <= 0 || ! Staff_Editor::is_multi_guest_available_for_menu( $menu_id ) ) {
			return 0;
		}

		// 1室1泊あたりのプランは、許可フラグが未保存・OFF でも ON として扱う（#831）。
		if ( ! Service_Menu_Post_Type::is_multi_guest_booking_enabled( $menu_id ) ) {
			return 0;
		}

		// 担当できる客室が無いメニューは、複数人一括予約を許可しない。
		if ( count( Service_Menu_Post_Type::get_assignable_staff_ids( $menu_id ) ) < 1 ) {
			return 0;
		}

		$max_raw = get_post_meta( $menu_id, '_vkbm_max_capacity', true );

		return '' === $max_raw ? 1 : max( 1, (int) $max_raw );
	}

	/**
	 * 泊単位の料金（泊数・泊ごとの単価・合計金額）をサーバー側の設定から計算する。
	 *
	 * 泊ごとの日付（チェックイン日から泊数ぶん。チェックアウト日は含めない）で、特定日・休前日・平日の単価を決めて足す。
	 * 空室一覧・下書き・確定が同じ合計になるよう、料金の計算はすべてここを通す。
	 *
	 * @param int                       $menu_id       メニューの投稿 ID。
	 * @param int                       $room_id       客室（リソース）の投稿 ID。
	 * @param string                    $check_in_date チェックイン日（Y-m-d）。
	 * @param int                       $nights        泊数。
	 * @param int                       $guests        人数。
	 * @param array<int, array<mixed>>  $guest_tiers   料金区分の内訳（無ければ空配列）。
	 * @param array<string, mixed>|null $plan_inputs  Lodging_Price::get_plan_inputs() の結果。客室ごとに繰り返し呼ぶ場合に、1回だけ作って渡す。null ならここで作る。
	 * @return array{nights: int, price_unit: string, plan_price_per_night: int|null, room_surcharge_per_night: int, price_per_night: int|null, total: int, is_uniform_price: bool, night_breakdown: array<int, array{date: string, rate: string, plan_price: int, room_surcharge: int, price: int}>} 計算結果。
	 */
	public static function calculate_price( int $menu_id, int $room_id, string $check_in_date, int $nights, int $guests, array $guest_tiers, ?array $plan_inputs = null ): array {
		return Lodging_Price::calculate(
			array_merge(
				$plan_inputs ?? Lodging_Price::get_plan_inputs( $menu_id ),
				array(
					'nights'         => $nights,
					'night_dates'    => Lodging_Rate::build_night_dates( $check_in_date, $nights ),
					'guests'         => $guests,
					'guest_tiers'    => $guest_tiers,
					'room_surcharge' => Lodging_Price::resolve_room_surcharge_per_night( $menu_id, $room_id ),
				)
			)
		);
	}

	/**
	 * 期間のすべての泊が、客室の販売日かを返す（シフト未登録の日は販売しない）。
	 *
	 * @param int    $room_id        客室（リソース）の投稿 ID。
	 * @param string $check_in_date  チェックイン日（Y-m-d）。
	 * @param string $check_out_date チェックアウト日（Y-m-d）。
	 * @return bool すべての泊が販売日なら true。日付が不正なら false。
	 */
	public static function is_stay_on_sale( int $room_id, string $check_in_date, string $check_out_date ): bool {
		$sale_days = new Shift_Sale_Days();
		$nights    = ( new Night_Strategy( $sale_days ) )->get_night_dates( $check_in_date, $check_out_date );
		if ( empty( $nights ) ) {
			return false;
		}

		$sale_dates = $sale_days->get_sale_dates( array( $room_id ), $nights[0], $nights[ count( $nights ) - 1 ] );

		return array() === array_diff( $nights, $sale_dates[ $room_id ] ?? array() );
	}
}
