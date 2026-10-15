<?php
/**
 * 1日分の空き枠を計算する戦略のインターフェース。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Availability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DateTimeZone;
use WP_Post;

/**
 * 空き枠の計算方式（時間枠で予約する方式など）を切り替えるための戦略インターフェース。
 *
 * Availability_Service は、予約方式ごとの計算をこのインターフェースの実装へ委ねる。
 */
interface Slot_Strategy {

	/**
	 * 指定日の空き枠を生成する。
	 *
	 * @param WP_Post      $menu_post          メニュー投稿。
	 * @param array<int>   $staff_ids          候補スタッフ（リソース）ID。
	 * @param string       $date               日付（Y-m-d）。
	 * @param DateTimeZone $timezone           タイムゾーン。
	 * @param bool         $is_staff_preferred スタッフ指名ありか。
	 * @return array<int, array<string, mixed>>
	 */
	public function generate_slots_for_date( WP_Post $menu_post, array $staff_ids, string $date, DateTimeZone $timezone, bool $is_staff_preferred ): array;
}
