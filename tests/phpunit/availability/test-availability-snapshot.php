<?php
/**
 * Availability_Service の空き状況計算結果を固定するテスト（スナップショット）。
 *
 * 宿泊モード追加（親 issue #527）の準備として、空き枠の計算を「時間枠戦略」へ切り出す前（issue #569）に、
 * 今の計算結果を固定する（issue #568）。切り出しの前後でこのテストが無変更のまま通ることで、
 * 計算結果が変わっていないことを確かめる。
 *
 * - 対象は公開メソッド Availability_Service::get_calendar_meta()（月カレンダーの日ごとの空き状況）と
 *   Availability_Service::get_daily_slots()（時間枠一覧）の戻り値のみ。
 * - private メソッド（generate_slots_for_date() など）は名前で呼ばない（リフレクションも使わない）。
 *   切り出しでメソッドの置き場所が変わっても、このテストを書き換えずに済むようにするため。
 * - 時刻依存を避けるため、時刻に関係しないケースは固定の遠い未来日（2099年3月）を使う。
 *   「受付の締切」「受付上限日数」のケースだけは「今日」が基準になるため、境界から十分離した
 *   相対日付（今日から +10 日 / +60 日など）を使う。
 * - 戻り値の generated_at（呼び出し時刻）は比較せず、形式だけを確認する。
 * - 無料版の挙動は、既存テスト（test-preferred-staff-id-normalization.php など）と同じく、
 *   無料版ビルドで実行したときだけ走る（Pro 版ビルドではスキップ）。Pro 限定のケースは逆に
 *   無料版ビルドでスキップする。両方の実行経路は npm run phpunit / npm run phpunit:free。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Availability;

use DateTimeImmutable;
use DateTimeZone;
use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\PostTypes\Shift_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Resources\Resource_Tag_Taxonomy;
use VKBookingManager\Staff\Staff_Editor;
use WP_Error;
use WP_UnitTestCase;

/**
 * Availability_Service::get_calendar_meta() / get_daily_slots() の戻り値を固定するテスト。
 *
 * メソッド名: 版・異常系はケースの edition（pro / free / any）で1本にまとめている。*_response_shape の2本だけは、
 * ケース配列ではなくキー構成を個別に assert する検証で、まとめると読みにくくなるため規約（test_ + 対象メソッド名）の例外とする。
 *
 * @group availability
 * @group availability-snapshot
 */
class Availability_Snapshot_Test extends WP_UnitTestCase {

	/**
	 * テスト前の全体設定（option）。
	 *
	 * @var mixed
	 */
	private $original_settings = false;

	/**
	 * テスト前のサイトのタイムゾーン設定。
	 *
	 * @var string
	 */
	private $original_timezone_string = '';

	/**
	 * テスト前の状態を退避し、サイトのタイムゾーンを Asia/Tokyo に固定する。
	 *
	 * ISO8601（+09:00）と予約の保存形式（サイトのタイムゾーンの Y-m-d H:i:s）を一致させるため。
	 */
	public function set_up(): void {
		parent::set_up();
		$this->original_settings        = get_option( Settings_Repository::OPTION_KEY, false );
		$this->original_timezone_string = (string) get_option( 'timezone_string', '' );
		update_option( 'timezone_string', 'Asia/Tokyo' );
	}

	/**
	 * 全体設定・タイムゾーン・静的キャッシュをテスト前の状態へ戻す。
	 */
	public function tear_down(): void {
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		update_option( 'timezone_string', $this->original_timezone_string );
		Staff_Editor::clear_nomination_enabled_cache();
		parent::tear_down();
	}

	/**
	 * シフトの1日分のデータ（状態 + 勤務時間帯）を作る。
	 *
	 * @param string                         $status 日の状態（open / regular_holiday / temporary_open / temporary_closed / unavailable）。
	 * @param array<int, array<int, string>> $ranges 勤務時間帯の配列（[ 開始, 終了 ] の組）。
	 * @return array<string, mixed>
	 */
	private function shift_entry( string $status, array $ranges ): array {
		$slots = array();
		foreach ( $ranges as $range ) {
			$slots[] = array(
				'start' => $range[0],
				'end'   => $range[1],
			);
		}

		return array(
			'status' => $status,
			'slots'  => $slots,
		);
	}

	/**
	 * 営業日（open）の1日分のシフトデータを作る。
	 *
	 * @param string $start 開始（HH:MM）。
	 * @param string $end   終了（HH:MM。24:00 可）。
	 * @return array<string, mixed>
	 */
	private function open_day( string $start, string $end ): array {
		return $this->shift_entry( 'open', array( array( $start, $end ) ) );
	}

	/**
	 * 予約1件分の定義を作る。
	 *
	 * @param string $staff       担当スタッフのラベル。
	 * @param string $start       サービス開始（Y-m-d H:i:s）。
	 * @param string $service_end サービス終了（Y-m-d H:i:s。バッファを含まない）。
	 * @param string $total_end   枠の終了（Y-m-d H:i:s。バッファを含む）。
	 * @param array  $options     guests（人数）・exclusive（貸切）・status・other_menu（別メニューの予約）。
	 * @return array<string, mixed>
	 */
	private function booking( string $staff, string $start, string $service_end, string $total_end, array $options = array() ): array {
		return array_merge(
			array(
				'staff'       => $staff,
				'start'       => $start,
				'service_end' => $service_end,
				'total_end'   => $total_end,
				'guests'      => 1,
				'exclusive'   => false,
				'status'      => 'confirmed',
				'other_menu'  => false,
			),
			$options
		);
	}

	/**
	 * シナリオ（サイト設定・スタッフ・メニュー・シフト・予約）を作成する。
	 *
	 * @param array<string, mixed> $scenario シナリオ定義。
	 *   - nomination: サイト全体の指名機能（既定 false）。
	 *   - slot_capacity: 予約枠の定員機能（既定 true）。
	 *   - staff: array<ラベル, array{tags?: string[]}>。
	 *   - tags: 作成するリソースタグのラベル配列。
	 *   - menu_staff: メニューの担当スタッフのラベル配列（Pro 版のみ参照される）。
	 *   - menu_meta: メニューに追加で保存するメタ。
	 *   - shifts: array{staff, year, month, days?: array<int, entry>, all_days?: entry} の配列。
	 *   - bookings: booking() で作った予約の配列。
	 * @return array{menu_id:int, staffs:array<string,int>, labels:array<int,string>, tags:array<string,int>}
	 */
	private function build_scenario( array $scenario ): array {
		// サイト全体の設定。スロットの刻みは 60 分、サイト全体の予約締切は 0 時間に固定する。
		$repository                                      = new Settings_Repository();
		$settings                                        = $repository->get_settings();
		$settings['staff_enabled']                       = (bool) ( $scenario['nomination'] ?? false );
		$settings['slot_capacity_enabled']               = (bool) ( $scenario['slot_capacity'] ?? true );
		$settings['provider_slot_step_minutes']          = 60;
		$settings['provider_reservation_deadline_hours'] = 0;
		update_option( Settings_Repository::OPTION_KEY, $settings );
		Staff_Editor::clear_nomination_enabled_cache();

		// 無料版は「公開中のリソースがちょうど1件」を基本スタッフとして扱うため、既存の公開リソースを下書きに落とす。
		if ( ! Staff_Editor::is_enabled() ) {
			$published_ids = get_posts(
				array(
					'post_type'      => Resource_Post_Type::POST_TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			foreach ( $published_ids as $post_id ) {
				wp_update_post(
					array(
						'ID'          => $post_id,
						'post_status' => 'draft',
					)
				);
			}
		}

		// リソースタグ。
		$tags = array();
		foreach ( (array) ( $scenario['tags'] ?? array() ) as $tag_label ) {
			$term = wp_insert_term( 'tag_' . $tag_label . '_' . wp_generate_password( 6, false ), Resource_Tag_Taxonomy::TAXONOMY );
			$this->assertIsArray( $term, 'リソースタグを作成できること: ' . $tag_label );
			$tags[ $tag_label ] = (int) $term['term_id'];
		}

		// スタッフ（リソース）。
		$staffs = array();
		$labels = array();
		foreach ( (array) ( $scenario['staff'] ?? array() ) as $staff_label => $staff_def ) {
			$staff_id = (int) $this->factory()->post->create(
				array(
					'post_type'   => Resource_Post_Type::POST_TYPE,
					'post_status' => 'publish',
					'post_title'  => 'staff_' . $staff_label,
				)
			);
			if ( ! empty( $staff_def['tags'] ) ) {
				$term_ids = array();
				foreach ( $staff_def['tags'] as $tag_label ) {
					$term_ids[] = $tags[ $tag_label ];
				}
				wp_set_object_terms( $staff_id, $term_ids, Resource_Tag_Taxonomy::TAXONOMY );
			}
			$staffs[ $staff_label ] = $staff_id;
			$labels[ $staff_id ]    = $staff_label;
		}

		// メニュー。所要時間 60 分・バッファ 0 分・締切 0 時間・受付上限なしを既定にする。
		$menu_id   = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$menu_meta = array_merge(
			array(
				'_vkbm_duration_minutes'           => 60,
				'_vkbm_buffer_after_minutes'       => 0,
				'_vkbm_reservation_deadline_hours' => 0,
				'_vkbm_max_advance_booking_days'   => 0,
			),
			(array) ( $scenario['menu_meta'] ?? array() )
		);
		foreach ( $menu_meta as $meta_key => $meta_value ) {
			update_post_meta( $menu_id, $meta_key, $meta_value );
		}
		$menu_staff_ids = array();
		foreach ( (array) ( $scenario['menu_staff'] ?? array() ) as $staff_label ) {
			$menu_staff_ids[] = $staffs[ $staff_label ];
		}
		if ( ! empty( $menu_staff_ids ) ) {
			update_post_meta( $menu_id, '_vkbm_staff_ids', $menu_staff_ids );
		}

		// シフト。
		foreach ( (array) ( $scenario['shifts'] ?? array() ) as $shift ) {
			$days = (array) ( $shift['days'] ?? array() );
			if ( isset( $shift['all_days'] ) ) {
				$days_in_month = (int) ( new DateTimeImmutable( sprintf( '%04d-%02d-01', $shift['year'], $shift['month'] ) ) )->format( 't' );
				for ( $day = 1; $day <= $days_in_month; $day++ ) {
					$days[ $day ] = $shift['all_days'];
				}
			}

			$shift_id = (int) $this->factory()->post->create(
				array(
					'post_type'   => Shift_Post_Type::POST_TYPE,
					'post_status' => 'publish',
				)
			);
			update_post_meta( $shift_id, '_vkbm_shift_resource_id', $staffs[ $shift['staff'] ] );
			update_post_meta( $shift_id, '_vkbm_shift_year', $shift['year'] );
			update_post_meta( $shift_id, '_vkbm_shift_month', $shift['month'] );
			update_post_meta( $shift_id, '_vkbm_shift_days', $days );
		}

		// 予約。
		$other_menu_id = 0;
		foreach ( (array) ( $scenario['bookings'] ?? array() ) as $booking ) {
			$booking_menu_id = $menu_id;
			if ( $booking['other_menu'] ) {
				if ( 0 === $other_menu_id ) {
					$other_menu_id = (int) $this->factory()->post->create(
						array(
							'post_type'   => Service_Menu_Post_Type::POST_TYPE,
							'post_status' => 'publish',
						)
					);
				}
				$booking_menu_id = $other_menu_id;
			}

			$booking_id = (int) $this->factory()->post->create(
				array(
					'post_type'   => Booking_Post_Type::POST_TYPE,
					'post_status' => 'publish',
				)
			);
			update_post_meta( $booking_id, '_vkbm_booking_service_start', $booking['start'] );
			update_post_meta( $booking_id, '_vkbm_booking_service_end', $booking['service_end'] );
			update_post_meta( $booking_id, '_vkbm_booking_total_end', $booking['total_end'] );
			update_post_meta( $booking_id, '_vkbm_booking_resource_id', $staffs[ $booking['staff'] ] );
			update_post_meta( $booking_id, '_vkbm_booking_service_id', $booking_menu_id );
			update_post_meta( $booking_id, '_vkbm_booking_status', $booking['status'] );
			update_post_meta( $booking_id, '_vkbm_booking_guests', $booking['guests'] );
			if ( $booking['exclusive'] ) {
				update_post_meta( $booking_id, '_vkbm_booking_exclusive', true );
			}
		}

		return array(
			'menu_id' => $menu_id,
			'staffs'  => $staffs,
			'labels'  => $labels,
			'tags'    => $tags,
		);
	}

	/**
	 * ISO8601 形式の日時を、比較しやすい形式（Y-m-d H:i）へ整える。
	 *
	 * @param string $iso ISO8601 形式の日時。
	 * @return string
	 */
	private function format_datetime( string $iso ): string {
		return ( new DateTimeImmutable( $iso ) )->format( 'Y-m-d H:i' );
	}

	/**
	 * get_daily_slots() の戻り値（成功時）を、比較しやすい文字列の配列へ整える。
	 *
	 * 例: `2099-03-10 10:00-11:00 svc 11:00 A cap1 rem1 booked0`（指名あり、担当スタッフ A）
	 * 例: `2099-03-10 10:00-11:00 svc 11:00 auto(A,B) cap1 rem1 booked0`（指名なし。割り当て候補 A・B）
	 * 貸切で閉じた枠は末尾に ` EXCLUSIVE` が付く。
	 *
	 * @param array<string, mixed> $result get_daily_slots() の戻り値。
	 * @param array<int, string>   $labels スタッフ ID => ラベル。
	 * @return array<int, string>
	 */
	private function summarize_slots( array $result, array $labels ): array {
		$summary = array();

		foreach ( $result['slots'] as $slot ) {
			$start = new DateTimeImmutable( $slot['start_at'] );
			$end   = new DateTimeImmutable( $slot['end_at'] );
			$svc   = new DateTimeImmutable( $slot['service_end_at'] );

			if ( null === $slot['staff'] ) {
				$assignable = array();
				foreach ( $slot['assignable_staff_ids'] as $staff_id ) {
					$assignable[] = $labels[ $staff_id ];
				}
				$who    = 'auto(' . implode( ',', $assignable ) . ')';
				$booked = (int) $slot['booked_guests'];
			} else {
				$who    = $labels[ $slot['staff']['id'] ];
				$booked = (int) $slot['guest_count'];
			}

			$summary[] = sprintf(
				'%s %s-%s svc %s %s cap%d rem%d booked%d%s',
				$start->format( 'Y-m-d' ),
				$start->format( 'H:i' ),
				$end->format( 'H:i' ),
				$svc->format( 'H:i' ),
				$who,
				(int) $slot['capacity'],
				(int) $slot['remaining'],
				$booked,
				empty( $slot['exclusive_closed'] ) ? '' : ' EXCLUSIVE'
			);
		}

		return $summary;
	}

	/**
	 * get_daily_slots() を呼び、結果を比較用に整える（エラーは `error:コード` の文字列）。
	 *
	 * @param array<string, mixed> $context    build_scenario() の戻り値。
	 * @param string               $date       日付（Y-m-d）。
	 * @param string|null          $resource   指名するスタッフのラベル。null で指名なし。'bogus' は実在しない ID。
	 * @param array<int, string>   $tag_labels 絞り込みに使うリソースタグのラベル。
	 * @param int|string|null      $menu_id    メニュー ID の差し替え。null は作成したメニュー、'non_menu_post' はメニューではない投稿。
	 * @param mixed                $raw        get_daily_slots() の戻り値そのもの（参照渡しで受け取る）。
	 * @return array<int, string>|string
	 */
	private function fetch_daily_summary( array $context, string $date, ?string $resource = null, array $tag_labels = array(), $menu_id = null, &$raw = null ) {
		$args = array(
			'menu_id'  => $this->resolve_menu_id( $context, $menu_id ),
			'date'     => $date,
			'timezone' => 'Asia/Tokyo',
		);
		if ( null !== $resource ) {
			$args['resource_id'] = 'bogus' === $resource ? 99999999 : $context['staffs'][ $resource ];
		}
		if ( ! empty( $tag_labels ) ) {
			$args['resource_tag_ids'] = array_map(
				static function ( string $label ) use ( $context ): int {
					return $context['tags'][ $label ];
				},
				$tag_labels
			);
		}

		// 呼び出しごとに新しいインスタンスを使う（予約の取得結果はインスタンス内にキャッシュされるため）。
		$raw = ( new Availability_Service() )->get_daily_slots( $args );
		if ( $raw instanceof WP_Error ) {
			return 'error:' . $raw->get_error_code();
		}

		return $this->summarize_slots( $raw, $context['labels'] );
	}

	/**
	 * メニュー ID の差し替え指定を解決する。
	 *
	 * @param array<string, mixed> $context build_scenario() の戻り値。
	 * @param int|string|null      $menu_id 差し替え指定（null: 作成したメニュー / 'non_menu_post': メニューではない投稿 / それ以外: その値）。
	 * @return int
	 */
	private function resolve_menu_id( array $context, $menu_id ): int {
		if ( null === $menu_id ) {
			return $context['menu_id'];
		}
		if ( 'non_menu_post' === $menu_id ) {
			return (int) $this->factory()->post->create( array( 'post_type' => 'post' ) );
		}

		return (int) $menu_id;
	}

	/**
	 * get_calendar_meta() の日ごとの結果を、比較しやすい文字列へ整える。
	 *
	 * 例: `avail=3 first=2099-03-10 10:00 status=normal holiday=0 disabled=0 notes=0`
	 * first は初回の空き枠の開始（無ければ `-`）、notes はメモの件数（文言は翻訳されるため件数のみ）。
	 *
	 * @param array<string, mixed> $day get_calendar_meta() の days の1要素。
	 * @return string
	 */
	private function summarize_day( array $day ): string {
		return sprintf(
			'avail=%d first=%s status=%s holiday=%d disabled=%d notes=%d',
			(int) $day['available_slots'],
			null === $day['first_start_at'] ? '-' : $this->format_datetime( $day['first_start_at'] ),
			$day['shift_status'],
			$day['is_holiday'] ? 1 : 0,
			$day['is_disabled'] ? 1 : 0,
			count( $day['notes'] )
		);
	}

	/**
	 * get_calendar_meta() を呼び、指定した日だけを比較用に整える（エラーは `error:コード` の文字列）。
	 *
	 * @param array<string, mixed> $context    build_scenario() の戻り値。
	 * @param int                  $year       年。
	 * @param int                  $month      月。
	 * @param array<int, string>   $dates      取り出す日付（Y-m-d）。
	 * @param string|null          $resource   指名するスタッフのラベル。null で指名なし。'bogus' は実在しない ID。
	 * @param array<int, string>   $tag_labels 絞り込みに使うリソースタグのラベル。
	 * @param mixed                $raw        get_calendar_meta() の戻り値そのもの（参照渡しで受け取る）。
	 * @return array<string, string>|string 日付 => 要約。
	 */
	private function fetch_calendar_summary( array $context, int $year, int $month, array $dates, ?string $resource = null, array $tag_labels = array(), &$raw = null ) {
		$args = array(
			'menu_id'  => $context['menu_id'],
			'year'     => $year,
			'month'    => $month,
			'timezone' => 'Asia/Tokyo',
		);
		if ( null !== $resource ) {
			$args['resource_id'] = 'bogus' === $resource ? 99999999 : $context['staffs'][ $resource ];
		}
		if ( ! empty( $tag_labels ) ) {
			$args['resource_tag_ids'] = array_map(
				static function ( string $label ) use ( $context ): int {
					return $context['tags'][ $label ];
				},
				$tag_labels
			);
		}

		$raw = ( new Availability_Service() )->get_calendar_meta( $args );
		if ( $raw instanceof WP_Error ) {
			return 'error:' . $raw->get_error_code();
		}

		$by_date = array();
		foreach ( $raw['days'] as $day ) {
			$by_date[ $day['date'] ] = $this->summarize_day( $day );
		}

		$summary = array();
		foreach ( $dates as $date ) {
			$summary[ $date ] = $by_date[ $date ] ?? 'missing';
		}

		return $summary;
	}

	/**
	 * Pro 版ビルド以外では、ケース配列を使わない Pro 限定のテストをスキップする。
	 */
	private function skip_unless_pro_edition(): void {
		if ( ! Staff_Editor::is_enabled() ) {
			$this->markTestSkipped( '指名・定員・貸切・リソースタグは Pro 版の機能のため、無料版ビルドではスキップする。無料版の挙動は edition が free のケースで固定している。' );
		}
	}

	/**
	 * ケースの edition が、いま実行しているビルド（Pro 版／無料版）で実行すべきものかを返す。
	 *
	 * - pro: Pro 版ビルドだけで実行する（既定）。
	 * - free: 無料版ビルドだけで実行する（npm run phpunit:free）。
	 * - any: 版に関係なく実行する。
	 *
	 * @param string $edition ケースの edition（pro / free / any）。
	 * @return bool
	 */
	private function is_case_for_current_edition( string $edition ): bool {
		if ( 'any' === $edition ) {
			return true;
		}

		return ( 'pro' === $edition ) === Staff_Editor::is_enabled();
	}

	/**
	 * 受付の締切・受付上限日数のケース用に、「今日」基準の相対日付と、その月のシフトを作る。
	 *
	 * 締切（720時間 = 30日）の境界から十分離した日（+10 / +60 日）と、受付上限（30日）の境界（+30 / +31 日）、
	 * その中間（+20 日）を返す。today はこの呼び出し時点の日付で、実行中に日付が変わったか
	 * （JST の 0 時をまたいだか）の判定に使う。
	 *
	 * @return array{near:DateTimeImmutable, far:DateTimeImmutable, adv_in:DateTimeImmutable, adv_out:DateTimeImmutable, mid:DateTimeImmutable, shifts:array<int, array<string, mixed>>, today:string}
	 */
	private function build_relative_dates(): array {
		$today = new DateTimeImmutable( 'today', new DateTimeZone( 'Asia/Tokyo' ) );

		$dates = array(
			'near'    => $today->modify( '+10 days' ),
			'far'     => $today->modify( '+60 days' ),
			'adv_in'  => $today->modify( '+30 days' ),
			'adv_out' => $today->modify( '+31 days' ),
			'mid'     => $today->modify( '+20 days' ),
		);

		// 各日付が属する月の全日分のシフト（A が 10:00-13:00 勤務）。
		$shifts = array();
		foreach ( $dates as $date ) {
			$shifts[ $date->format( 'Y-m' ) ] = array(
				'staff'    => 'A',
				'year'     => (int) $date->format( 'Y' ),
				'month'    => (int) $date->format( 'n' ),
				'all_days' => $this->open_day( '10:00', '13:00' ),
			);
		}

		$dates['shifts'] = array_values( $shifts );
		$dates['today']  = $today->format( 'Y-m-d' );

		return $dates;
	}

	/**
	 * 実行中に日付が変わった（JST の 0 時をまたいだ）場合は、相対日付の前提が崩れるためテストを未完了にする。
	 *
	 * 結果を集めたあと、assertSame の前に呼ぶ。
	 *
	 * @param string $started_day build_relative_dates() の today（開始時の日付）。
	 */
	private function mark_incomplete_if_day_changed( string $started_day ): void {
		$now_day = ( new DateTimeImmutable( 'today', new DateTimeZone( 'Asia/Tokyo' ) ) )->format( 'Y-m-d' );
		if ( $now_day !== $started_day ) {
			$this->markTestIncomplete( '実行中に日付が変わった（JST の 0 時をまたいだ）ため、今日基準の相対日付のケースは判定できない。再実行する。' );
		}
	}

	/**
	 * get_daily_slots() の時間枠一覧を固定する（Pro 版）。
	 *
	 * 指名あり／なし、定員、貸切、リソースタグ絞り込み、開始時刻の固定、バッファ、受付の締切、
	 * 受付上限日数、日をまたぐ予約、異常系（エラーコード）を固定する。
	 * 各ケースの edition で実行する版を分ける（pro: Pro 版のみ・既定 / free: 無料版のみ / any: 版に関係なく実行）。
	 */
	public function test_get_daily_slots(): void {
		// 受付の締切・受付上限日数のケース用の相対日付とシフト（境界から十分離した日を使う）。
		$relative_dates  = $this->build_relative_dates();
		$near_date       = $relative_dates['near'];
		$far_date        = $relative_dates['far'];
		$adv_in          = $relative_dates['adv_in'];
		$adv_out         = $relative_dates['adv_out'];
		$mid_date        = $relative_dates['mid'];
		$relative_shifts = $relative_dates['shifts'];

		// 2099-03-10 の標準シフト: A は 10:00-13:00、B は 10:00-12:00。
		$shifts_a_b = array(
			array(
				'staff' => 'A',
				'year'  => 2099,
				'month' => 3,
				'days'  => array( 10 => $this->open_day( '10:00', '13:00' ) ),
			),
			array(
				'staff' => 'B',
				'year'  => 2099,
				'month' => 3,
				'days'  => array( 10 => $this->open_day( '10:00', '12:00' ) ),
			),
		);
		$shift_a    = array( $shifts_a_b[0] );

		// 無料版のシナリオ: 基本スタッフ A のみ（メニューの担当設定・定員は効かない）。11:00 に予約あり。
		$free_scenario = array(
			'staff'     => array( 'A' => array() ),
			'menu_meta' => array( '_vkbm_max_capacity' => 3 ),
			'shifts'    => $shift_a,
			'bookings'  => array( $this->booking( 'A', '2099-03-10 11:00:00', '2099-03-10 12:00:00', '2099-03-10 12:00:00' ) ),
		);
		$free_expected = array(
			'2099-03-10 10:00-11:00 svc 11:00 A cap1 rem1 booked0',
			'2099-03-10 12:00-13:00 svc 13:00 A cap1 rem1 booked0',
		);

		// 日をまたぐ予約（3/10 22:00 から 3/11 10:00 まで、枠は 10:30 まで）。
		$cross_day_booking = $this->booking( 'A', '2099-03-10 22:00:00', '2099-03-11 10:00:00', '2099-03-11 10:30:00' );
		$cross_day_shifts  = array(
			array(
				'staff' => 'A',
				'year'  => 2099,
				'month' => 3,
				'days'  => array(
					10 => $this->open_day( '18:00', '24:00' ),
					11 => $this->open_day( '09:00', '13:00' ),
				),
			),
		);

		$test_cases = array(
			// ---- 指名なし（自動割り当て）。
			array(
				'test_condition_name' => '指名なし・予約なし・A(10-13)とB(10-12) => 同じ時間枠が1つに集約され、候補は勤務中のスタッフ全員',
				'scenario'            => array(
					'staff'      => array(
						'A' => array(),
						'B' => array(),
					),
					'menu_staff' => array( 'A', 'B' ),
					'shifts'     => $shifts_a_b,
				),
				'date'                => '2099-03-10',
				'expected'            => array(
					'2099-03-10 10:00-11:00 svc 11:00 auto(A,B) cap1 rem1 booked0',
					'2099-03-10 11:00-12:00 svc 12:00 auto(A,B) cap1 rem1 booked0',
					'2099-03-10 12:00-13:00 svc 13:00 auto(A) cap1 rem1 booked0',
				),
			),
			array(
				'test_condition_name' => '指名なし・Aの10:00に予約あり => 10:00枠はBが空いているので残り1（予約済みの枠も残る）',
				'scenario'            => array(
					'staff'      => array(
						'A' => array(),
						'B' => array(),
					),
					'menu_staff' => array( 'A', 'B' ),
					'shifts'     => $shifts_a_b,
					'bookings'   => array( $this->booking( 'A', '2099-03-10 10:00:00', '2099-03-10 11:00:00', '2099-03-10 11:00:00' ) ),
				),
				'date'                => '2099-03-10',
				'expected'            => array(
					'2099-03-10 10:00-11:00 svc 11:00 auto(A,B) cap1 rem1 booked1',
					'2099-03-10 11:00-12:00 svc 12:00 auto(A,B) cap1 rem1 booked0',
					'2099-03-10 12:00-13:00 svc 13:00 auto(A) cap1 rem1 booked0',
				),
			),
			array(
				'test_condition_name' => '指名なし・AとBの両方の10:00に予約あり => 10:00枠は残り0の満枠（枠自体は残る）',
				'scenario'            => array(
					'staff'      => array(
						'A' => array(),
						'B' => array(),
					),
					'menu_staff' => array( 'A', 'B' ),
					'shifts'     => $shifts_a_b,
					'bookings'   => array(
						$this->booking( 'A', '2099-03-10 10:00:00', '2099-03-10 11:00:00', '2099-03-10 11:00:00' ),
						$this->booking( 'B', '2099-03-10 10:00:00', '2099-03-10 11:00:00', '2099-03-10 11:00:00' ),
					),
				),
				'date'                => '2099-03-10',
				'expected'            => array(
					'2099-03-10 10:00-11:00 svc 11:00 auto(A,B) cap1 rem0 booked2',
					'2099-03-10 11:00-12:00 svc 12:00 auto(A,B) cap1 rem1 booked0',
					'2099-03-10 12:00-13:00 svc 13:00 auto(A) cap1 rem1 booked0',
				),
			),
			array(
				'test_condition_name' => '指名なし・キャンセル済み／無断キャンセルの予約 => 枠を消費しない',
				'scenario'            => array(
					'staff'      => array( 'A' => array() ),
					'menu_staff' => array( 'A' ),
					'shifts'     => $shift_a,
					'bookings'   => array(
						$this->booking( 'A', '2099-03-10 10:00:00', '2099-03-10 11:00:00', '2099-03-10 11:00:00', array( 'status' => 'cancelled' ) ),
						$this->booking( 'A', '2099-03-10 11:00:00', '2099-03-10 12:00:00', '2099-03-10 12:00:00', array( 'status' => 'no_show' ) ),
					),
				),
				'date'                => '2099-03-10',
				'expected'            => array(
					'2099-03-10 10:00-11:00 svc 11:00 auto(A) cap1 rem1 booked0',
					'2099-03-10 11:00-12:00 svc 12:00 auto(A) cap1 rem1 booked0',
					'2099-03-10 12:00-13:00 svc 13:00 auto(A) cap1 rem1 booked0',
				),
			),
			// ---- 指名あり。
			array(
				'test_condition_name' => '指名あり・Aを指名・Aの11:00に予約あり => 予約済みの枠は一覧から除外される',
				'scenario'            => array(
					'nomination' => true,
					'staff'      => array(
						'A' => array(),
						'B' => array(),
					),
					'menu_staff' => array( 'A', 'B' ),
					'shifts'     => $shifts_a_b,
					'bookings'   => array( $this->booking( 'A', '2099-03-10 11:00:00', '2099-03-10 12:00:00', '2099-03-10 12:00:00' ) ),
				),
				'date'                => '2099-03-10',
				'resource'            => 'A',
				'expected'            => array(
					'2099-03-10 10:00-11:00 svc 11:00 A cap1 rem1 booked0',
					'2099-03-10 12:00-13:00 svc 13:00 A cap1 rem1 booked0',
				),
			),
			array(
				'test_condition_name' => '指名あり（サイト設定）・指名しない（resource_id なし）・定員3・Aの10:00に1名の予約あり => 指名を使うメニューは1枠1組のため残り0（#392）',
				'scenario'            => array(
					'nomination' => true,
					'staff'      => array( 'A' => array() ),
					'menu_staff' => array( 'A' ),
					'menu_meta'  => array( '_vkbm_max_capacity' => 3 ),
					'shifts'     => $shift_a,
					'bookings'   => array( $this->booking( 'A', '2099-03-10 10:00:00', '2099-03-10 11:00:00', '2099-03-10 11:00:00' ) ),
				),
				'date'                => '2099-03-10',
				'expected'            => array(
					'2099-03-10 10:00-11:00 svc 11:00 auto(A) cap3 rem0 booked1',
					'2099-03-10 11:00-12:00 svc 12:00 auto(A) cap3 rem3 booked0',
					'2099-03-10 12:00-13:00 svc 13:00 auto(A) cap3 rem3 booked0',
				),
			),
			// ---- 定員。
			array(
				'test_condition_name' => '定員3・指名なし・10:00に2名、11:00に3名の予約あり => 残り1／残り0（満枠も残る）／残り3',
				'scenario'            => array(
					'staff'      => array( 'A' => array() ),
					'menu_staff' => array( 'A' ),
					'menu_meta'  => array(
						'_vkbm_max_capacity'          => 3,
						'_vkbm_allow_multiple_guests' => 1,
					),
					'shifts'     => $shift_a,
					'bookings'   => array(
						$this->booking( 'A', '2099-03-10 10:00:00', '2099-03-10 11:00:00', '2099-03-10 11:00:00', array( 'guests' => 2 ) ),
						$this->booking( 'A', '2099-03-10 11:00:00', '2099-03-10 12:00:00', '2099-03-10 12:00:00', array( 'guests' => 3 ) ),
					),
				),
				'date'                => '2099-03-10',
				'expected'            => array(
					'2099-03-10 10:00-11:00 svc 11:00 auto(A) cap3 rem1 booked2',
					'2099-03-10 11:00-12:00 svc 12:00 auto(A) cap3 rem0 booked3',
					'2099-03-10 12:00-13:00 svc 13:00 auto(A) cap3 rem3 booked0',
				),
			),
			array(
				'test_condition_name' => '定員のメタが3でも予約枠の定員機能がOFF => 定員は1に固定される（境界）',
				'scenario'            => array(
					'slot_capacity' => false,
					'staff'         => array( 'A' => array() ),
					'menu_staff'    => array( 'A' ),
					'menu_meta'     => array( '_vkbm_max_capacity' => 3 ),
					'shifts'        => $shift_a,
				),
				'date'                => '2099-03-10',
				'expected'            => array(
					'2099-03-10 10:00-11:00 svc 11:00 auto(A) cap1 rem1 booked0',
					'2099-03-10 11:00-12:00 svc 12:00 auto(A) cap1 rem1 booked0',
					'2099-03-10 12:00-13:00 svc 13:00 auto(A) cap1 rem1 booked0',
				),
			),
			// ---- 貸切。
			array(
				'test_condition_name' => '貸切・指名なし・このメニューの貸切予約が10:00にある => 10:00枠は貸切で閉じる（残り0）',
				'scenario'            => array(
					'staff'      => array(
						'A' => array(),
						'B' => array(),
					),
					'menu_staff' => array( 'A', 'B' ),
					'shifts'     => $shifts_a_b,
					'bookings'   => array( $this->booking( 'A', '2099-03-10 10:00:00', '2099-03-10 11:00:00', '2099-03-10 11:00:00', array( 'exclusive' => true ) ) ),
				),
				'date'                => '2099-03-10',
				'expected'            => array(
					'2099-03-10 10:00-11:00 svc 11:00 auto(A,B) cap1 rem0 booked1 EXCLUSIVE',
					'2099-03-10 11:00-12:00 svc 12:00 auto(A,B) cap1 rem1 booked0',
					'2099-03-10 12:00-13:00 svc 13:00 auto(A) cap1 rem1 booked0',
				),
			),
			array(
				'test_condition_name' => '貸切・指名なし・別メニューの貸切予約が10:00にある => このメニューの枠は閉じない',
				'scenario'            => array(
					'staff'      => array(
						'A' => array(),
						'B' => array(),
					),
					'menu_staff' => array( 'A', 'B' ),
					'shifts'     => $shifts_a_b,
					'bookings'   => array(
						$this->booking(
							'A',
							'2099-03-10 10:00:00',
							'2099-03-10 11:00:00',
							'2099-03-10 11:00:00',
							array(
								'exclusive'  => true,
								'other_menu' => true,
							)
						),
					),
				),
				'date'                => '2099-03-10',
				'expected'            => array(
					'2099-03-10 10:00-11:00 svc 11:00 auto(A,B) cap1 rem1 booked0',
					'2099-03-10 11:00-12:00 svc 12:00 auto(A,B) cap1 rem1 booked0',
					'2099-03-10 12:00-13:00 svc 13:00 auto(A) cap1 rem1 booked0',
				),
			),
			array(
				'test_condition_name' => '貸切・指名あり・Aを指名・Aの10:00に貸切予約あり => 10:00枠は一覧から除外される',
				'scenario'            => array(
					'nomination' => true,
					'staff'      => array(
						'A' => array(),
						'B' => array(),
					),
					'menu_staff' => array( 'A', 'B' ),
					'shifts'     => $shifts_a_b,
					'bookings'   => array( $this->booking( 'A', '2099-03-10 10:00:00', '2099-03-10 11:00:00', '2099-03-10 11:00:00', array( 'exclusive' => true ) ) ),
				),
				'date'                => '2099-03-10',
				'resource'            => 'A',
				'expected'            => array(
					'2099-03-10 11:00-12:00 svc 12:00 A cap1 rem1 booked0',
					'2099-03-10 12:00-13:00 svc 13:00 A cap1 rem1 booked0',
				),
			),
			array(
				'test_condition_name' => '貸切・指名あり・指名しない・担当AとB・Aだけ貸切予約あり => Bが空いているので10:00枠は閉じない',
				'scenario'            => array(
					'nomination' => true,
					'staff'      => array(
						'A' => array(),
						'B' => array(),
					),
					'menu_staff' => array( 'A', 'B' ),
					'shifts'     => $shifts_a_b,
					'bookings'   => array( $this->booking( 'A', '2099-03-10 10:00:00', '2099-03-10 11:00:00', '2099-03-10 11:00:00', array( 'exclusive' => true ) ) ),
				),
				'date'                => '2099-03-10',
				'expected'            => array(
					'2099-03-10 10:00-11:00 svc 11:00 auto(A,B) cap1 rem1 booked1',
					'2099-03-10 11:00-12:00 svc 12:00 auto(A,B) cap1 rem1 booked0',
					'2099-03-10 12:00-13:00 svc 13:00 auto(A) cap1 rem1 booked0',
				),
			),
			array(
				'test_condition_name' => '貸切・指名あり・指名しない・担当Aのみ・Aに貸切予約あり => 候補が全員貸切のため10:00枠は閉じる',
				'scenario'            => array(
					'nomination' => true,
					'staff'      => array( 'A' => array() ),
					'menu_staff' => array( 'A' ),
					'shifts'     => $shift_a,
					'bookings'   => array( $this->booking( 'A', '2099-03-10 10:00:00', '2099-03-10 11:00:00', '2099-03-10 11:00:00', array( 'exclusive' => true ) ) ),
				),
				'date'                => '2099-03-10',
				'expected'            => array(
					'2099-03-10 10:00-11:00 svc 11:00 auto(A) cap1 rem0 booked1 EXCLUSIVE',
					'2099-03-10 11:00-12:00 svc 12:00 auto(A) cap1 rem1 booked0',
					'2099-03-10 12:00-13:00 svc 13:00 auto(A) cap1 rem1 booked0',
				),
			),
			// ---- リソースタグでの絞り込み（A=X、B=Y、C=X+Y。3人とも 10:00-11:00 勤務）。
			array(
				'test_condition_name' => 'タグ絞り込みなし => 候補は A・B・C 全員',
				'scenario'            => $this->tag_scenario(),
				'date'                => '2099-03-10',
				'expected'            => array(
					'2099-03-10 10:00-11:00 svc 11:00 auto(A,B,C) cap1 rem1 booked0',
				),
			),
			array(
				'test_condition_name' => 'タグ X で絞り込み => 候補は X を持つ A・C',
				'scenario'            => $this->tag_scenario(),
				'date'                => '2099-03-10',
				'tag_labels'          => array( 'X' ),
				'expected'            => array(
					'2099-03-10 10:00-11:00 svc 11:00 auto(A,C) cap1 rem1 booked0',
				),
			),
			array(
				'test_condition_name' => 'タグ X と Y（AND条件）で絞り込み => 候補は両方を持つ C だけ',
				'scenario'            => $this->tag_scenario(),
				'date'                => '2099-03-10',
				'tag_labels'          => array( 'X', 'Y' ),
				'expected'            => array(
					'2099-03-10 10:00-11:00 svc 11:00 auto(C) cap1 rem1 booked0',
				),
			),
			array(
				'test_condition_name' => 'タグ Z（持つスタッフがいない）で絞り込み => resource_tag_no_match エラー（異常系）',
				'scenario'            => $this->tag_scenario(),
				'date'                => '2099-03-10',
				'tag_labels'          => array( 'Z' ),
				'expected'            => 'error:resource_tag_no_match',
			),
			array(
				'test_condition_name' => 'タグ X で絞り込み・指名ありでAを指名 => Aの枠が返る（指名とタグの両立）',
				'scenario'            => $this->tag_scenario( true ),
				'date'                => '2099-03-10',
				'resource'            => 'A',
				'tag_labels'          => array( 'X' ),
				'expected'            => array(
					'2099-03-10 10:00-11:00 svc 11:00 A cap1 rem1 booked0',
				),
			),
			array(
				'test_condition_name' => 'タグ Y で絞り込み・指名ありでAを指名（AはYを持たない） => resource_tag_no_match エラー（異常系）',
				'scenario'            => $this->tag_scenario( true ),
				'date'                => '2099-03-10',
				'resource'            => 'A',
				'tag_labels'          => array( 'Y' ),
				'expected'            => 'error:resource_tag_no_match',
			),
			// ---- 開始時刻の固定・バッファ。
			array(
				'test_condition_name' => '開始時刻の固定 [12:30,10:30,12:00,09:00,10:15] => 勤務時間内で枠が収まる 10:30 と 12:00 だけ（範囲外・はみ出し・不正な分は無視）',
				'scenario'            => array(
					'staff'      => array( 'A' => array() ),
					'menu_staff' => array( 'A' ),
					'menu_meta'  => array( '_vkbm_fixed_start_times' => array( '12:30', '10:30', '12:00', '09:00', '10:15' ) ),
					'shifts'     => $shift_a,
				),
				'date'                => '2099-03-10',
				'expected'            => array(
					'2099-03-10 10:30-11:30 svc 11:30 auto(A) cap1 rem1 booked0',
					'2099-03-10 12:00-13:00 svc 13:00 auto(A) cap1 rem1 booked0',
				),
			),
			array(
				'test_condition_name' => '開始時刻の固定 [10:30,12:00]・バッファ30分 => 12:00開始は枠（バッファ込み）が勤務時間をはみ出すため 10:30 だけ',
				'scenario'            => array(
					'staff'      => array( 'A' => array() ),
					'menu_staff' => array( 'A' ),
					'menu_meta'  => array(
						'_vkbm_fixed_start_times'    => array( '10:30', '12:00' ),
						'_vkbm_buffer_after_minutes' => 30,
					),
					'shifts'     => $shift_a,
				),
				'date'                => '2099-03-10',
				'expected'            => array(
					'2099-03-10 10:30-12:00 svc 11:30 auto(A) cap1 rem1 booked0',
				),
			),
			array(
				'test_condition_name' => 'バッファ30分（固定開始時刻なし） => 枠はバッファ込みで勤務時間に収まるものだけ（12:00開始は除外）',
				'scenario'            => array(
					'staff'      => array( 'A' => array() ),
					'menu_staff' => array( 'A' ),
					'menu_meta'  => array( '_vkbm_buffer_after_minutes' => 30 ),
					'shifts'     => $shift_a,
				),
				'date'                => '2099-03-10',
				'expected'            => array(
					'2099-03-10 10:00-11:30 svc 11:00 auto(A) cap1 rem1 booked0',
					'2099-03-10 11:00-12:30 svc 12:00 auto(A) cap1 rem1 booked0',
				),
			),
			// ---- 日をまたぐ予約。
			array(
				'test_condition_name' => '日をまたぐ予約 3/10 22:00〜3/11 10:30 の前日（3/10 18:00-24:00 勤務・指名あり） => 21:00 開始まで（22:00・23:00 は埋まる）',
				'scenario'            => array(
					'nomination' => true,
					'staff'      => array( 'A' => array() ),
					'menu_staff' => array( 'A' ),
					'shifts'     => $cross_day_shifts,
					'bookings'   => array( $cross_day_booking ),
				),
				'date'                => '2099-03-10',
				'resource'            => 'A',
				'expected'            => array(
					'2099-03-10 18:00-19:00 svc 19:00 A cap1 rem1 booked0',
					'2099-03-10 19:00-20:00 svc 20:00 A cap1 rem1 booked0',
					'2099-03-10 20:00-21:00 svc 21:00 A cap1 rem1 booked0',
					'2099-03-10 21:00-22:00 svc 22:00 A cap1 rem1 booked0',
				),
			),
			array(
				'test_condition_name' => '日をまたぐ予約 3/10 22:00〜3/11 10:30 の翌日（3/11 09:00-13:00 勤務・指名あり） => 予約の終わり（10:30）以降の 11:00 開始から',
				'scenario'            => array(
					'nomination' => true,
					'staff'      => array( 'A' => array() ),
					'menu_staff' => array( 'A' ),
					'shifts'     => $cross_day_shifts,
					'bookings'   => array( $cross_day_booking ),
				),
				'date'                => '2099-03-11',
				'resource'            => 'A',
				'expected'            => array(
					'2099-03-11 11:00-12:00 svc 12:00 A cap1 rem1 booked0',
					'2099-03-11 12:00-13:00 svc 13:00 A cap1 rem1 booked0',
				),
			),
			array(
				'test_condition_name' => '日をまたぐ予約 3/10 22:00〜3/11 10:30 の翌日・指名なし => 予約と重なる 09:00・10:00 枠は残り0で残り、11:00 以降は残り1',
				'scenario'            => array(
					'staff'      => array( 'A' => array() ),
					'menu_staff' => array( 'A' ),
					'shifts'     => $cross_day_shifts,
					'bookings'   => array( $cross_day_booking ),
				),
				'date'                => '2099-03-11',
				'expected'            => array(
					'2099-03-11 09:00-10:00 svc 10:00 auto(A) cap1 rem0 booked1',
					'2099-03-11 10:00-11:00 svc 11:00 auto(A) cap1 rem0 booked1',
					'2099-03-11 11:00-12:00 svc 12:00 auto(A) cap1 rem1 booked0',
					'2099-03-11 12:00-13:00 svc 13:00 auto(A) cap1 rem1 booked0',
				),
			),
			array(
				'test_condition_name' => '予約なし・3/10 18:00-24:00 勤務・指名あり => 24:00 終了の勤務でも 23:00-24:00 の枠まで作られる（枠の終了は翌 00:00）',
				'scenario'            => array(
					'nomination' => true,
					'staff'      => array( 'A' => array() ),
					'menu_staff' => array( 'A' ),
					'shifts'     => $cross_day_shifts,
				),
				'date'                => '2099-03-10',
				'resource'            => 'A',
				'expected'            => array(
					'2099-03-10 18:00-19:00 svc 19:00 A cap1 rem1 booked0',
					'2099-03-10 19:00-20:00 svc 20:00 A cap1 rem1 booked0',
					'2099-03-10 20:00-21:00 svc 21:00 A cap1 rem1 booked0',
					'2099-03-10 21:00-22:00 svc 22:00 A cap1 rem1 booked0',
					'2099-03-10 22:00-23:00 svc 23:00 A cap1 rem1 booked0',
					'2099-03-10 23:00-00:00 svc 00:00 A cap1 rem1 booked0',
				),
			),
			// ---- 受付の締切（メニューの締切 720 時間 = 30 日）。
			array(
				'test_condition_name' => '受付の締切720時間（30日）・10日後 => 締切より前の日なので枠は無い',
				'scenario'            => array(
					'nomination' => true,
					'staff'      => array( 'A' => array() ),
					'menu_staff' => array( 'A' ),
					'menu_meta'  => array( '_vkbm_reservation_deadline_hours' => 720 ),
					'shifts'     => $relative_shifts,
				),
				'date'                => $near_date->format( 'Y-m-d' ),
				'resource'            => 'A',
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '受付の締切720時間（30日）・60日後 => 締切より後の日なので枠がすべて出る',
				'scenario'            => array(
					'nomination' => true,
					'staff'      => array( 'A' => array() ),
					'menu_staff' => array( 'A' ),
					'menu_meta'  => array( '_vkbm_reservation_deadline_hours' => 720 ),
					'shifts'     => $relative_shifts,
				),
				'date'                => $far_date->format( 'Y-m-d' ),
				'resource'            => 'A',
				'expected'            => array(
					$far_date->format( 'Y-m-d' ) . ' 10:00-11:00 svc 11:00 A cap1 rem1 booked0',
					$far_date->format( 'Y-m-d' ) . ' 11:00-12:00 svc 12:00 A cap1 rem1 booked0',
					$far_date->format( 'Y-m-d' ) . ' 12:00-13:00 svc 13:00 A cap1 rem1 booked0',
				),
			),
			// ---- 受付上限日数（30日）。
			array(
				'test_condition_name' => '受付上限30日・20日後 => 上限の範囲内なので枠が出る',
				'scenario'            => array(
					'nomination' => true,
					'staff'      => array( 'A' => array() ),
					'menu_staff' => array( 'A' ),
					'menu_meta'  => array( '_vkbm_max_advance_booking_days' => 30 ),
					'shifts'     => $relative_shifts,
				),
				'date'                => $mid_date->format( 'Y-m-d' ),
				'resource'            => 'A',
				'expected'            => array(
					$mid_date->format( 'Y-m-d' ) . ' 10:00-11:00 svc 11:00 A cap1 rem1 booked0',
					$mid_date->format( 'Y-m-d' ) . ' 11:00-12:00 svc 12:00 A cap1 rem1 booked0',
					$mid_date->format( 'Y-m-d' ) . ' 12:00-13:00 svc 13:00 A cap1 rem1 booked0',
				),
			),
			array(
				'test_condition_name' => '受付上限30日・ちょうど30日後 => 上限の日付を含むので枠が出る（境界）',
				'scenario'            => array(
					'nomination' => true,
					'staff'      => array( 'A' => array() ),
					'menu_staff' => array( 'A' ),
					'menu_meta'  => array( '_vkbm_max_advance_booking_days' => 30 ),
					'shifts'     => $relative_shifts,
				),
				'date'                => $adv_in->format( 'Y-m-d' ),
				'resource'            => 'A',
				'expected'            => array(
					$adv_in->format( 'Y-m-d' ) . ' 10:00-11:00 svc 11:00 A cap1 rem1 booked0',
					$adv_in->format( 'Y-m-d' ) . ' 11:00-12:00 svc 12:00 A cap1 rem1 booked0',
					$adv_in->format( 'Y-m-d' ) . ' 12:00-13:00 svc 13:00 A cap1 rem1 booked0',
				),
			),
			array(
				'test_condition_name' => '受付上限30日・31日後 => 上限を超えるので枠は無い（境界）',
				'scenario'            => array(
					'nomination' => true,
					'staff'      => array( 'A' => array() ),
					'menu_staff' => array( 'A' ),
					'menu_meta'  => array( '_vkbm_max_advance_booking_days' => 30 ),
					'shifts'     => $relative_shifts,
				),
				'date'                => $adv_out->format( 'Y-m-d' ),
				'resource'            => 'A',
				'expected'            => array(),
			),
			// ---- 異常系（エラーコード）。
			array(
				'test_condition_name' => '指名ありで、メニューの担当ではないBを指名 => staff_not_assigned エラー（異常系）',
				'scenario'            => array(
					'nomination' => true,
					'staff'      => array(
						'A' => array(),
						'B' => array(),
					),
					'menu_staff' => array( 'A' ),
					'shifts'     => $shifts_a_b,
				),
				'date'                => '2099-03-10',
				'resource'            => 'B',
				'expected'            => 'error:staff_not_assigned',
			),
			array(
				'test_condition_name' => 'メニューに担当スタッフが未設定・指名なし => staff_not_configured エラー（異常系）',
				'scenario'            => array(
					'staff'  => array( 'A' => array() ),
					'shifts' => $shift_a,
				),
				'date'                => '2099-03-10',
				'expected'            => 'error:staff_not_configured',
			),
			array(
				'test_condition_name' => '予約のない勤務なしの日（シフトの無い 3/12） => 枠は空配列',
				'scenario'            => array(
					'staff'      => array( 'A' => array() ),
					'menu_staff' => array( 'A' ),
					'shifts'     => $shift_a,
				),
				'date'                => '2099-03-12',
				'expected'            => array(),
			),
			// ---- 無料版（無料版ビルドだけで実行）。担当は常に基本スタッフ1名で、指名・定員・メニューの担当設定は効かない。
			array(
				'edition'             => 'free',
				'test_condition_name' => '無料版・resource_id なし => 基本スタッフの枠。予約済みの 11:00 枠は除外、定員は常に1',
				'scenario'            => $free_scenario,
				'date'                => '2099-03-10',
				'expected'            => $free_expected,
			),
			array(
				'edition'             => 'free',
				'test_condition_name' => '無料版・基本スタッフの ID を resource_id に指定 => resource_id なしと同じ結果',
				'scenario'            => $free_scenario,
				'date'                => '2099-03-10',
				'resource'            => 'A',
				'expected'            => $free_expected,
			),
			array(
				'edition'             => 'free',
				'test_condition_name' => '無料版・実在しない ID を resource_id に指定 => resource_id なしと同じ結果（指名は信用されない・異常系）',
				'scenario'            => $free_scenario,
				'date'                => '2099-03-10',
				'resource'            => 'bogus',
				'expected'            => $free_expected,
			),
			// ---- 入力の異常系（版に関係なく実行）。
			array(
				'edition'             => 'any',
				'test_condition_name' => '日付が不正な文字列 => invalid_date エラー（異常系）',
				'scenario'            => array(
					'staff'      => array( 'A' => array() ),
					'menu_staff' => array( 'A' ),
					'shifts'     => $shift_a,
				),
				'date'                => 'abc',
				'expected'            => 'error:invalid_date',
			),
			array(
				'edition'             => 'any',
				'test_condition_name' => 'メニュー ID が 0 => menu_not_found エラー（異常系）',
				'scenario'            => array(
					'staff'      => array( 'A' => array() ),
					'menu_staff' => array( 'A' ),
					'shifts'     => $shift_a,
				),
				'date'                => '2099-03-10',
				'menu_id'             => 0,
				'expected'            => 'error:menu_not_found',
			),
			array(
				'edition'             => 'any',
				'test_condition_name' => 'メニューではない投稿の ID => menu_not_found エラー（異常系）',
				'scenario'            => array(
					'staff'      => array( 'A' => array() ),
					'menu_staff' => array( 'A' ),
					'shifts'     => $shift_a,
				),
				'date'                => '2099-03-10',
				'menu_id'             => 'non_menu_post',
				'expected'            => 'error:menu_not_found',
			),
		);

		foreach ( $test_cases as $case ) {
			$edition = $case['edition'] ?? 'pro';
			if ( ! $this->is_case_for_current_edition( $edition ) ) {
				continue;
			}

			$context = $this->build_scenario( $case['scenario'] );
			$actual  = $this->fetch_daily_summary( $context, $case['date'], $case['resource'] ?? null, $case['tag_labels'] ?? array(), $case['menu_id'] ?? null, $raw );

			// 今日基準の相対日付のケースは、実行中に日付が変わったら判定しない。
			$this->mark_incomplete_if_day_changed( $relative_dates['today'] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );

			if ( 'free' === $edition ) {
				$this->assertNull( $raw['meta']['resource_id'], $case['test_condition_name'] . ' / meta.resource_id は常に null' );
				$this->assertFalse( $raw['slots'][0]['auto_assign'], $case['test_condition_name'] . ' / 無料版は自動割り当て扱いにならない' );
			}
		}
	}

	/**
	 * get_daily_slots() の戻り値の形（キーの構成・値の型）を固定する（Pro 版）。
	 *
	 * 指名あり（担当スタッフ付き）の枠と、指名なし（自動割り当て）の枠の両方のキー構成を確認する。
	 * generated_at は呼び出し時刻のため、値の比較はせず ISO8601 形式であることだけを確認する。
	 */
	public function test_get_daily_slots_response_shape(): void {
		$this->skip_unless_pro_edition();

		$sorted = static function ( array $keys ): array {
			sort( $keys );
			return $keys;
		};

		$shifts = array(
			array(
				'staff' => 'A',
				'year'  => 2099,
				'month' => 3,
				'days'  => array( 10 => $this->open_day( '10:00', '12:00' ) ),
			),
		);

		// 指名あり。
		$context  = $this->build_scenario(
			array(
				'nomination' => true,
				'staff'      => array( 'A' => array() ),
				'menu_staff' => array( 'A' ),
				'shifts'     => $shifts,
			)
		);
		$staff_id = $context['staffs']['A'];
		$result   = ( new Availability_Service() )->get_daily_slots(
			array(
				'menu_id'     => $context['menu_id'],
				'date'        => '2099-03-10',
				'timezone'    => 'Asia/Tokyo',
				'resource_id' => $staff_id,
			)
		);

		$this->assertSame( $sorted( array( 'date', 'timezone', 'slots', 'meta' ) ), $sorted( array_keys( $result ) ), '指名あり: 戻り値の最上位のキー' );
		$this->assertSame( '2099-03-10', $result['date'], '指名あり: date' );
		$this->assertSame( 'Asia/Tokyo', $result['timezone'], '指名あり: timezone' );
		$this->assertSame( $sorted( array( 'generated_at', 'menu_id', 'resource_id' ) ), $sorted( array_keys( $result['meta'] ) ), '指名あり: meta のキー' );
		$this->assertSame( $context['menu_id'], $result['meta']['menu_id'], '指名あり: meta.menu_id' );
		$this->assertSame( $staff_id, $result['meta']['resource_id'], '指名あり: meta.resource_id は指名したスタッフ ID' );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+09:00$/', $result['meta']['generated_at'], '指名あり: meta.generated_at は ISO8601' );
		$this->assertCount( 2, $result['slots'], '指名あり: 10:00-12:00 勤務・60分メニューは2枠' );

		$slot = $result['slots'][0];
		$this->assertSame(
			$sorted( array( 'slot_id', 'start_at', 'end_at', 'service_end_at', 'duration_minutes', 'staff', 'capacity', 'remaining', 'guest_count', 'min_capacity', 'booked_guests', 'exclusive_closed', 'flags', 'auto_assign' ) ),
			$sorted( array_keys( $slot ) ),
			'指名あり: 枠のキー'
		);
		$this->assertSame( $staff_id . '-20990310010000', $slot['slot_id'], '指名あり: slot_id は スタッフID-UTC日時' );
		$this->assertSame( '2099-03-10T10:00:00+09:00', $slot['start_at'], '指名あり: start_at' );
		$this->assertSame( '2099-03-10T11:00:00+09:00', $slot['end_at'], '指名あり: end_at' );
		$this->assertSame( '2099-03-10T11:00:00+09:00', $slot['service_end_at'], '指名あり: service_end_at' );
		$this->assertSame( 60, $slot['duration_minutes'], '指名あり: duration_minutes' );
		$this->assertSame( $sorted( array( 'id', 'name', 'resource_tags', 'avatar' ) ), $sorted( array_keys( $slot['staff'] ) ), '指名あり: staff のキー' );
		$this->assertSame( $staff_id, $slot['staff']['id'], '指名あり: staff.id' );
		$this->assertSame( 1, $slot['capacity'], '指名あり: capacity' );
		$this->assertSame( 1, $slot['remaining'], '指名あり: remaining' );
		$this->assertSame( 0, $slot['guest_count'], '指名あり: guest_count' );
		$this->assertSame( 0, $slot['min_capacity'], '指名あり: min_capacity' );
		$this->assertSame( 0, $slot['booked_guests'], '指名あり: booked_guests' );
		$this->assertFalse( $slot['exclusive_closed'], '指名あり: exclusive_closed' );
		$this->assertFalse( $slot['auto_assign'], '指名あり: auto_assign' );
		$this->assertSame(
			array(
				'is_last_slot_of_day'   => false,
				'requires_confirmation' => false,
			),
			$slot['flags'],
			'指名あり: 最初の枠の flags'
		);
		$this->assertTrue( $result['slots'][1]['flags']['is_last_slot_of_day'], '指名あり: 最後の枠だけ is_last_slot_of_day が true' );

		// 指名なし（自動割り当て）。
		$context  = $this->build_scenario(
			array(
				'staff'      => array( 'A' => array() ),
				'menu_staff' => array( 'A' ),
				'shifts'     => $shifts,
			)
		);
		$staff_id = $context['staffs']['A'];
		$result   = ( new Availability_Service() )->get_daily_slots(
			array(
				'menu_id'  => $context['menu_id'],
				'date'     => '2099-03-10',
				'timezone' => 'Asia/Tokyo',
			)
		);

		$this->assertNull( $result['meta']['resource_id'], '指名なし: meta.resource_id は null' );
		$this->assertCount( 2, $result['slots'], '指名なし: 10:00-12:00 勤務・60分メニューは2枠' );

		$slot = $result['slots'][0];
		$this->assertSame(
			$sorted( array( 'slot_id', 'start_at', 'end_at', 'service_end_at', 'duration_minutes', 'staff', 'staff_label', 'assignable_staff_ids', 'capacity', 'remaining', 'exclusive_closed', 'flags', 'auto_assign', 'min_capacity', 'booked_guests' ) ),
			$sorted( array_keys( $slot ) ),
			'指名なし: 枠のキー'
		);
		$this->assertMatchesRegularExpression( '/^auto-' . $context['menu_id'] . '-[0-9a-f]{32}$/', $slot['slot_id'], '指名なし: slot_id は auto-メニューID-ハッシュ' );
		$this->assertNull( $slot['staff'], '指名なし: staff は null' );
		$this->assertIsString( $slot['staff_label'], '指名なし: staff_label は文字列' );
		$this->assertSame( array( $staff_id ), $slot['assignable_staff_ids'], '指名なし: assignable_staff_ids' );
		$this->assertTrue( $slot['auto_assign'], '指名なし: auto_assign' );
		$this->assertTrue( $result['slots'][1]['flags']['is_last_slot_of_day'], '指名なし: 最後の枠だけ is_last_slot_of_day が true' );
	}

	/**
	 * get_calendar_meta() の月カレンダー（日ごとの空き状況）を固定する（Pro 版）。
	 *
	 * 休みの区分、指名あり／なし、定員、貸切、リソースタグ絞り込み、開始時刻の固定、受付の締切、
	 * 受付上限日数、日をまたぐ予約、異常系（エラーコード）を固定する。
	 */
	public function test_get_calendar_meta(): void {
		// 受付の締切・受付上限日数のケース用の相対日付とシフト（境界から十分離した日を使う）。
		$relative_dates  = $this->build_relative_dates();
		$near_date       = $relative_dates['near'];
		$far_date        = $relative_dates['far'];
		$adv_in          = $relative_dates['adv_in'];
		$adv_out         = $relative_dates['adv_out'];
		$mid_date        = $relative_dates['mid'];
		$relative_shifts = $relative_dates['shifts'];

		// 無料版のシナリオ: 基本スタッフ A のみ。10日は11:00に予約、11日は定休日、12日は10:00の1枠が予約済み。
		$free_scenario = array(
			'staff'     => array( 'A' => array() ),
			'menu_meta' => array( '_vkbm_max_capacity' => 3 ),
			'shifts'    => array(
				array(
					'staff' => 'A',
					'year'  => 2099,
					'month' => 3,
					'days'  => array(
						10 => $this->open_day( '10:00', '13:00' ),
						11 => $this->shift_entry( 'regular_holiday', array( array( '10:00', '13:00' ) ) ),
						12 => $this->open_day( '10:00', '11:00' ),
					),
				),
			),
			'bookings'  => array(
				$this->booking( 'A', '2099-03-10 11:00:00', '2099-03-10 12:00:00', '2099-03-10 12:00:00' ),
				$this->booking( 'A', '2099-03-12 10:00:00', '2099-03-12 11:00:00', '2099-03-12 11:00:00' ),
			),
		);
		$free_dates    = array( '2099-03-10', '2099-03-11', '2099-03-12', '2099-03-13' );
		$free_expected = array(
			'2099-03-10' => 'avail=2 first=2099-03-10 10:00 status=normal holiday=0 disabled=0 notes=0',
			'2099-03-11' => 'avail=0 first=- status=holiday holiday=1 disabled=1 notes=0',
			'2099-03-12' => 'avail=0 first=- status=normal holiday=0 disabled=1 notes=0',
			'2099-03-13' => 'avail=0 first=- status=normal holiday=0 disabled=1 notes=0',
		);

		$shift_a_days = static function ( array $days ): array {
			return array(
				array(
					'staff' => 'A',
					'year'  => 2099,
					'month' => 3,
					'days'  => $days,
				),
			);
		};

		$cross_day_scenario = array(
			'nomination' => true,
			'staff'      => array( 'A' => array() ),
			'menu_staff' => array( 'A' ),
			'shifts'     => $shift_a_days(
				array(
					10 => $this->open_day( '18:00', '24:00' ),
					11 => $this->open_day( '09:00', '13:00' ),
				)
			),
		);

		$test_cases = array(
			array(
				'test_condition_name' => '休みの区分（営業・定休日・臨時休業・臨時営業・シフト未登録・勤務時間なし・シフトの無い日） => 日ごとの状態',
				'scenario'            => array(
					'staff'      => array( 'A' => array() ),
					'menu_staff' => array( 'A' ),
					'shifts'     => $shift_a_days(
						array(
							10 => $this->open_day( '10:00', '13:00' ),
							11 => $this->shift_entry( 'regular_holiday', array( array( '10:00', '13:00' ) ) ),
							12 => $this->shift_entry( 'temporary_closed', array( array( '10:00', '13:00' ) ) ),
							13 => $this->shift_entry( 'temporary_open', array( array( '10:00', '12:00' ) ) ),
							14 => $this->shift_entry( 'unavailable', array() ),
							16 => $this->shift_entry( 'open', array() ),
						)
					),
				),
				'year'                => 2099,
				'month'               => 3,
				'dates'               => array( '2099-03-01', '2099-03-10', '2099-03-11', '2099-03-12', '2099-03-13', '2099-03-14', '2099-03-15', '2099-03-16', '2099-03-31' ),
				'expected'            => array(
					'2099-03-01' => 'avail=0 first=- status=normal holiday=0 disabled=1 notes=0',
					'2099-03-10' => 'avail=3 first=2099-03-10 10:00 status=normal holiday=0 disabled=0 notes=0',
					'2099-03-11' => 'avail=0 first=- status=holiday holiday=1 disabled=1 notes=0',
					'2099-03-12' => 'avail=0 first=- status=special_close holiday=1 disabled=1 notes=0',
					'2099-03-13' => 'avail=2 first=2099-03-13 10:00 status=special_open holiday=0 disabled=0 notes=0',
					'2099-03-14' => 'avail=0 first=- status=off holiday=1 disabled=1 notes=1',
					'2099-03-15' => 'avail=0 first=- status=normal holiday=0 disabled=1 notes=0',
					'2099-03-16' => 'avail=0 first=- status=normal holiday=0 disabled=1 notes=0',
					'2099-03-31' => 'avail=0 first=- status=normal holiday=0 disabled=1 notes=0',
				),
			),
			array(
				'test_condition_name' => '指名なし・複数スタッフの日の状態が混在 => open があれば normal、無ければ先頭の状態。休みのスタッフの枠は数えない',
				'scenario'            => array(
					'staff'      => array(
						'A' => array(),
						'B' => array(),
					),
					'menu_staff' => array( 'A', 'B' ),
					'shifts'     => array(
						array(
							'staff' => 'A',
							'year'  => 2099,
							'month' => 3,
							'days'  => array(
								20 => $this->shift_entry( 'regular_holiday', array( array( '10:00', '12:00' ) ) ),
								21 => $this->open_day( '10:00', '12:00' ),
								22 => $this->shift_entry( 'regular_holiday', array( array( '10:00', '12:00' ) ) ),
							),
						),
						array(
							'staff' => 'B',
							'year'  => 2099,
							'month' => 3,
							'days'  => array(
								20 => $this->open_day( '10:00', '12:00' ),
								21 => $this->shift_entry( 'regular_holiday', array( array( '10:00', '12:00' ) ) ),
								22 => $this->shift_entry( 'temporary_closed', array( array( '10:00', '12:00' ) ) ),
							),
						),
					),
				),
				'year'                => 2099,
				'month'               => 3,
				'dates'               => array( '2099-03-20', '2099-03-21', '2099-03-22' ),
				'expected'            => array(
					'2099-03-20' => 'avail=2 first=2099-03-20 10:00 status=normal holiday=0 disabled=0 notes=0',
					'2099-03-21' => 'avail=2 first=2099-03-21 10:00 status=normal holiday=0 disabled=0 notes=0',
					'2099-03-22' => 'avail=0 first=- status=holiday holiday=1 disabled=1 notes=0',
				),
			),
			array(
				'test_condition_name' => '指名あり・Aを指名・3/10 は10:00と11:00が予約済み、3/11 は10:00だけ予約済み => 0件で無効／1件で11:00から',
				'scenario'            => array(
					'nomination' => true,
					'staff'      => array( 'A' => array() ),
					'menu_staff' => array( 'A' ),
					'shifts'     => $shift_a_days(
						array(
							10 => $this->open_day( '10:00', '12:00' ),
							11 => $this->open_day( '10:00', '12:00' ),
						)
					),
					'bookings'   => array(
						$this->booking( 'A', '2099-03-10 10:00:00', '2099-03-10 11:00:00', '2099-03-10 11:00:00' ),
						$this->booking( 'A', '2099-03-10 11:00:00', '2099-03-10 12:00:00', '2099-03-10 12:00:00' ),
						$this->booking( 'A', '2099-03-11 10:00:00', '2099-03-11 11:00:00', '2099-03-11 11:00:00' ),
					),
				),
				'resource'            => 'A',
				'year'                => 2099,
				'month'               => 3,
				'dates'               => array( '2099-03-10', '2099-03-11' ),
				'expected'            => array(
					'2099-03-10' => 'avail=0 first=- status=normal holiday=0 disabled=1 notes=0',
					'2099-03-11' => 'avail=1 first=2099-03-11 11:00 status=normal holiday=0 disabled=0 notes=0',
				),
			),
			array(
				'test_condition_name' => '指名なし・10:00-11:00 勤務でその枠が予約済み => 実際に予約可能な枠がないため無効',
				'scenario'            => array(
					'staff'      => array( 'A' => array() ),
					'menu_staff' => array( 'A' ),
					'shifts'     => $shift_a_days( array( 10 => $this->open_day( '10:00', '11:00' ) ) ),
					'bookings'   => array( $this->booking( 'A', '2099-03-10 10:00:00', '2099-03-10 11:00:00', '2099-03-10 11:00:00' ) ),
				),
				'year'                => 2099,
				'month'               => 3,
				'dates'               => array( '2099-03-10' ),
				'expected'            => array(
					'2099-03-10' => 'avail=0 first=- status=normal holiday=0 disabled=1 notes=0',
				),
			),
			array(
				'test_condition_name' => '定員3・指名なし・10:00の枠が3名で満席 => 予約可能な11:00の1枠だけを数える',
				'scenario'            => array(
					'staff'      => array( 'A' => array() ),
					'menu_staff' => array( 'A' ),
					'menu_meta'  => array(
						'_vkbm_max_capacity'          => 3,
						'_vkbm_allow_multiple_guests' => 1,
					),
					'shifts'     => $shift_a_days( array( 10 => $this->open_day( '10:00', '12:00' ) ) ),
					'bookings'   => array( $this->booking( 'A', '2099-03-10 10:00:00', '2099-03-10 11:00:00', '2099-03-10 11:00:00', array( 'guests' => 3 ) ) ),
				),
				'year'                => 2099,
				'month'               => 3,
				'dates'               => array( '2099-03-10' ),
				'expected'            => array(
					'2099-03-10' => 'avail=1 first=2099-03-10 11:00 status=normal holiday=0 disabled=0 notes=0',
				),
			),
			array(
				'test_condition_name' => '貸切・指名なし・3/10 は全枠が貸切で閉じる、3/11 は10:00だけ閉じる => 0件で無効／1件で11:00から（貸切で閉じた枠は数えない）',
				'scenario'            => array(
					'staff'      => array( 'A' => array() ),
					'menu_staff' => array( 'A' ),
					'shifts'     => $shift_a_days(
						array(
							10 => $this->open_day( '10:00', '12:00' ),
							11 => $this->open_day( '10:00', '12:00' ),
						)
					),
					'bookings'   => array(
						$this->booking( 'A', '2099-03-10 10:00:00', '2099-03-10 12:00:00', '2099-03-10 12:00:00', array( 'exclusive' => true ) ),
						$this->booking( 'A', '2099-03-11 10:00:00', '2099-03-11 11:00:00', '2099-03-11 11:00:00', array( 'exclusive' => true ) ),
					),
				),
				'year'                => 2099,
				'month'               => 3,
				'dates'               => array( '2099-03-10', '2099-03-11' ),
				'expected'            => array(
					'2099-03-10' => 'avail=0 first=- status=normal holiday=0 disabled=1 notes=0',
					'2099-03-11' => 'avail=1 first=2099-03-11 11:00 status=normal holiday=0 disabled=0 notes=0',
				),
			),
			array(
				'test_condition_name' => 'タグ絞り込みなし（A=X が 10:00-11:00、C=X+Y が 11:00-13:00 勤務） => 3枠・10:00から',
				'scenario'            => $this->tag_calendar_scenario(),
				'year'                => 2099,
				'month'               => 3,
				'dates'               => array( '2099-03-10' ),
				'expected'            => array(
					'2099-03-10' => 'avail=3 first=2099-03-10 10:00 status=normal holiday=0 disabled=0 notes=0',
				),
			),
			array(
				'test_condition_name' => 'タグ X で絞り込み => A と C の枠で3枠・10:00から',
				'scenario'            => $this->tag_calendar_scenario(),
				'year'                => 2099,
				'month'               => 3,
				'dates'               => array( '2099-03-10' ),
				'tag_labels'          => array( 'X' ),
				'expected'            => array(
					'2099-03-10' => 'avail=3 first=2099-03-10 10:00 status=normal holiday=0 disabled=0 notes=0',
				),
			),
			array(
				'test_condition_name' => 'タグ Y で絞り込み => B（勤務なし）と C の枠で2枠・11:00から',
				'scenario'            => $this->tag_calendar_scenario(),
				'year'                => 2099,
				'month'               => 3,
				'dates'               => array( '2099-03-10' ),
				'tag_labels'          => array( 'Y' ),
				'expected'            => array(
					'2099-03-10' => 'avail=2 first=2099-03-10 11:00 status=normal holiday=0 disabled=0 notes=0',
				),
			),
			array(
				'test_condition_name' => 'タグ Z（持つスタッフがいない）で絞り込み => resource_tag_no_match エラー（異常系）',
				'scenario'            => $this->tag_calendar_scenario(),
				'year'                => 2099,
				'month'               => 3,
				'dates'               => array( '2099-03-10' ),
				'tag_labels'          => array( 'Z' ),
				'expected'            => 'error:resource_tag_no_match',
			),
			array(
				'test_condition_name' => '開始時刻の固定 [10:30,12:00]・10:00-13:00 勤務 => 2枠・10:30から',
				'scenario'            => array(
					'staff'      => array( 'A' => array() ),
					'menu_staff' => array( 'A' ),
					'menu_meta'  => array( '_vkbm_fixed_start_times' => array( '10:30', '12:00' ) ),
					'shifts'     => $shift_a_days( array( 10 => $this->open_day( '10:00', '13:00' ) ) ),
				),
				'year'                => 2099,
				'month'               => 3,
				'dates'               => array( '2099-03-10' ),
				'expected'            => array(
					'2099-03-10' => 'avail=2 first=2099-03-10 10:30 status=normal holiday=0 disabled=0 notes=0',
				),
			),
			array(
				'test_condition_name' => '日をまたぐ予約 3/10 22:00〜3/11 10:30・指名あり・Aを指名 => 3/10 は6枠中4枠（18:00から）、3/11 は4枠中2枠（11:00から）',
				'scenario'            => array_merge(
					$cross_day_scenario,
					array( 'bookings' => array( $this->booking( 'A', '2099-03-10 22:00:00', '2099-03-11 10:00:00', '2099-03-11 10:30:00' ) ) )
				),
				'resource'            => 'A',
				'year'                => 2099,
				'month'               => 3,
				'dates'               => array( '2099-03-10', '2099-03-11' ),
				'expected'            => array(
					'2099-03-10' => 'avail=4 first=2099-03-10 18:00 status=normal holiday=0 disabled=0 notes=0',
					'2099-03-11' => 'avail=2 first=2099-03-11 11:00 status=normal holiday=0 disabled=0 notes=0',
				),
			),
			array(
				'test_condition_name' => '予約なし・指名あり・Aを指名・3/10 は 18:00-24:00 勤務 => 6枠（24:00 終了の枠を含む）',
				'scenario'            => $cross_day_scenario,
				'resource'            => 'A',
				'year'                => 2099,
				'month'               => 3,
				'dates'               => array( '2099-03-10', '2099-03-11' ),
				'expected'            => array(
					'2099-03-10' => 'avail=6 first=2099-03-10 18:00 status=normal holiday=0 disabled=0 notes=0',
					'2099-03-11' => 'avail=4 first=2099-03-11 09:00 status=normal holiday=0 disabled=0 notes=0',
				),
			),
			array(
				'test_condition_name' => '受付の締切720時間（30日）・10日後 => 空き枠なし（無効）',
				'scenario'            => array(
					'nomination' => true,
					'staff'      => array( 'A' => array() ),
					'menu_staff' => array( 'A' ),
					'menu_meta'  => array( '_vkbm_reservation_deadline_hours' => 720 ),
					'shifts'     => $relative_shifts,
				),
				'resource'            => 'A',
				'year'                => (int) $near_date->format( 'Y' ),
				'month'               => (int) $near_date->format( 'n' ),
				'dates'               => array( $near_date->format( 'Y-m-d' ) ),
				'expected'            => array(
					$near_date->format( 'Y-m-d' ) => 'avail=0 first=- status=normal holiday=0 disabled=1 notes=0',
				),
			),
			array(
				'test_condition_name' => '受付の締切720時間（30日）・60日後 => 3枠',
				'scenario'            => array(
					'nomination' => true,
					'staff'      => array( 'A' => array() ),
					'menu_staff' => array( 'A' ),
					'menu_meta'  => array( '_vkbm_reservation_deadline_hours' => 720 ),
					'shifts'     => $relative_shifts,
				),
				'resource'            => 'A',
				'year'                => (int) $far_date->format( 'Y' ),
				'month'               => (int) $far_date->format( 'n' ),
				'dates'               => array( $far_date->format( 'Y-m-d' ) ),
				'expected'            => array(
					$far_date->format( 'Y-m-d' ) => 'avail=3 first=' . $far_date->format( 'Y-m-d' ) . ' 10:00 status=normal holiday=0 disabled=0 notes=0',
				),
			),
			array(
				'test_condition_name' => '受付上限30日・20日後 => 3枠',
				'scenario'            => array(
					'nomination' => true,
					'staff'      => array( 'A' => array() ),
					'menu_staff' => array( 'A' ),
					'menu_meta'  => array( '_vkbm_max_advance_booking_days' => 30 ),
					'shifts'     => $relative_shifts,
				),
				'resource'            => 'A',
				'year'                => (int) $mid_date->format( 'Y' ),
				'month'               => (int) $mid_date->format( 'n' ),
				'dates'               => array( $mid_date->format( 'Y-m-d' ) ),
				'expected'            => array(
					$mid_date->format( 'Y-m-d' ) => 'avail=3 first=' . $mid_date->format( 'Y-m-d' ) . ' 10:00 status=normal holiday=0 disabled=0 notes=0',
				),
			),
			array(
				'test_condition_name' => '受付上限30日・ちょうど30日後 => 3枠（境界）',
				'scenario'            => array(
					'nomination' => true,
					'staff'      => array( 'A' => array() ),
					'menu_staff' => array( 'A' ),
					'menu_meta'  => array( '_vkbm_max_advance_booking_days' => 30 ),
					'shifts'     => $relative_shifts,
				),
				'resource'            => 'A',
				'year'                => (int) $adv_in->format( 'Y' ),
				'month'               => (int) $adv_in->format( 'n' ),
				'dates'               => array( $adv_in->format( 'Y-m-d' ) ),
				'expected'            => array(
					$adv_in->format( 'Y-m-d' ) => 'avail=3 first=' . $adv_in->format( 'Y-m-d' ) . ' 10:00 status=normal holiday=0 disabled=0 notes=0',
				),
			),
			array(
				'test_condition_name' => '受付上限30日・31日後 => 空き枠なし（無効・境界）',
				'scenario'            => array(
					'nomination' => true,
					'staff'      => array( 'A' => array() ),
					'menu_staff' => array( 'A' ),
					'menu_meta'  => array( '_vkbm_max_advance_booking_days' => 30 ),
					'shifts'     => $relative_shifts,
				),
				'resource'            => 'A',
				'year'                => (int) $adv_out->format( 'Y' ),
				'month'               => (int) $adv_out->format( 'n' ),
				'dates'               => array( $adv_out->format( 'Y-m-d' ) ),
				'expected'            => array(
					$adv_out->format( 'Y-m-d' ) => 'avail=0 first=- status=normal holiday=0 disabled=1 notes=0',
				),
			),
			array(
				'edition'             => 'any',
				'test_condition_name' => '年が範囲外（1999） => invalid_year エラー（異常系）',
				'scenario'            => array(
					'staff'      => array( 'A' => array() ),
					'menu_staff' => array( 'A' ),
				),
				'year'                => 1999,
				'month'               => 3,
				'dates'               => array(),
				'expected'            => 'error:invalid_year',
			),
			array(
				'edition'             => 'any',
				'test_condition_name' => '月が範囲外（13） => invalid_month エラー（異常系）',
				'scenario'            => array(
					'staff'      => array( 'A' => array() ),
					'menu_staff' => array( 'A' ),
				),
				'year'                => 2099,
				'month'               => 13,
				'dates'               => array(),
				'expected'            => 'error:invalid_month',
			),
			array(
				'test_condition_name' => 'メニューに担当スタッフが未設定 => staff_not_configured エラー（異常系）',
				'scenario'            => array(
					'staff' => array( 'A' => array() ),
				),
				'year'                => 2099,
				'month'               => 3,
				'dates'               => array(),
				'expected'            => 'error:staff_not_configured',
			),
			// ---- 無料版（無料版ビルドだけで実行）。予約済みの枠は数えず、定休日は休み。resource_id は結果に影響しない。
			array(
				'edition'             => 'free',
				'test_condition_name' => '無料版・resource_id なし => 予約済みの枠は数えない。定休日は休み',
				'scenario'            => $free_scenario,
				'year'                => 2099,
				'month'               => 3,
				'dates'               => $free_dates,
				'expected'            => $free_expected,
			),
			array(
				'edition'             => 'free',
				'test_condition_name' => '無料版・基本スタッフの ID を resource_id に指定 => resource_id なしと同じ結果',
				'scenario'            => $free_scenario,
				'resource'            => 'A',
				'year'                => 2099,
				'month'               => 3,
				'dates'               => $free_dates,
				'expected'            => $free_expected,
			),
			array(
				'edition'             => 'free',
				'test_condition_name' => '無料版・実在しない ID を resource_id に指定 => resource_id なしと同じ結果（指名は信用されない・異常系）',
				'scenario'            => $free_scenario,
				'resource'            => 'bogus',
				'year'                => 2099,
				'month'               => 3,
				'dates'               => $free_dates,
				'expected'            => $free_expected,
			),
		);

		foreach ( $test_cases as $case ) {
			$edition = $case['edition'] ?? 'pro';
			if ( ! $this->is_case_for_current_edition( $edition ) ) {
				continue;
			}

			$context = $this->build_scenario( $case['scenario'] );
			$actual  = $this->fetch_calendar_summary( $context, $case['year'], $case['month'], $case['dates'], $case['resource'] ?? null, $case['tag_labels'] ?? array(), $raw );

			// 今日基準の相対日付のケースは、実行中に日付が変わったら判定しない。
			$this->mark_incomplete_if_day_changed( $relative_dates['today'] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );

			if ( 'free' === $edition ) {
				$this->assertCount( 31, $raw['days'], $case['test_condition_name'] . ' / 3月は31日分' );
				$this->assertNull( $raw['meta']['resource_id'], $case['test_condition_name'] . ' / meta.resource_id は常に null' );
			}
		}
	}

	/**
	 * get_calendar_meta() の戻り値の形（キーの構成・件数・値の型）を固定する（Pro 版）。
	 *
	 * generated_at は呼び出し時刻のため、値の比較はせず ISO8601 形式であることだけを確認する。
	 */
	public function test_get_calendar_meta_response_shape(): void {
		$this->skip_unless_pro_edition();

		$sorted = static function ( array $keys ): array {
			sort( $keys );
			return $keys;
		};

		$context = $this->build_scenario(
			array(
				'staff'      => array( 'A' => array() ),
				'menu_staff' => array( 'A' ),
				'shifts'     => array(
					array(
						'staff' => 'A',
						'year'  => 2099,
						'month' => 3,
						'days'  => array( 10 => $this->open_day( '10:00', '12:00' ) ),
					),
				),
			)
		);

		$result = ( new Availability_Service() )->get_calendar_meta(
			array(
				'menu_id'  => $context['menu_id'],
				'year'     => 2099,
				'month'    => 3,
				'timezone' => 'Asia/Tokyo',
			)
		);

		$this->assertSame( $sorted( array( 'year', 'month', 'days', 'meta' ) ), $sorted( array_keys( $result ) ), '戻り値の最上位のキー' );
		$this->assertSame( 2099, $result['year'], 'year' );
		$this->assertSame( 3, $result['month'], 'month' );
		$this->assertCount( 31, $result['days'], '3月は31日分' );
		$this->assertSame( $sorted( array( 'menu_id', 'resource_id', 'timezone', 'generated_at' ) ), $sorted( array_keys( $result['meta'] ) ), 'meta のキー' );
		$this->assertSame( $context['menu_id'], $result['meta']['menu_id'], 'meta.menu_id' );
		$this->assertNull( $result['meta']['resource_id'], 'meta.resource_id は指名なしなら null' );
		$this->assertSame( 'Asia/Tokyo', $result['meta']['timezone'], 'meta.timezone' );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+09:00$/', $result['meta']['generated_at'], 'meta.generated_at は ISO8601' );

		$this->assertSame( '2099-03-01', $result['days'][0]['date'], '最初の日は 1 日' );
		$this->assertSame( '2099-03-31', $result['days'][30]['date'], '最後の日は 31 日' );

		$day = $result['days'][9];
		$this->assertSame(
			$sorted( array( 'date', 'available_slots', 'first_start_at', 'shift_status', 'is_holiday', 'is_disabled', 'notes' ) ),
			$sorted( array_keys( $day ) ),
			'日ごとのキー'
		);
		$this->assertSame( '2099-03-10', $day['date'], 'date' );
		$this->assertSame( 2, $day['available_slots'], 'available_slots' );
		$this->assertSame( '2099-03-10T10:00:00+09:00', $day['first_start_at'], 'first_start_at' );
		$this->assertSame( 'normal', $day['shift_status'], 'shift_status' );
		$this->assertFalse( $day['is_holiday'], 'is_holiday' );
		$this->assertFalse( $day['is_disabled'], 'is_disabled' );
		$this->assertSame( array(), $day['notes'], 'notes' );

		$empty_day = $result['days'][0];
		$this->assertNull( $empty_day['first_start_at'], '空き枠の無い日の first_start_at は null' );
	}

	/**
	 * リソースタグ絞り込み用のシナリオ（日別の枠用）。A=タグX、B=タグY、C=タグX+Y、3人とも 2099-03-10 の 10:00-11:00 勤務。
	 *
	 * @param bool $nomination サイト全体の指名機能を有効にするか。
	 * @return array<string, mixed>
	 */
	private function tag_scenario( bool $nomination = false ): array {
		$days = array( 10 => $this->open_day( '10:00', '11:00' ) );

		return array(
			'nomination' => $nomination,
			'tags'       => array( 'X', 'Y', 'Z' ),
			'staff'      => array(
				'A' => array( 'tags' => array( 'X' ) ),
				'B' => array( 'tags' => array( 'Y' ) ),
				'C' => array( 'tags' => array( 'X', 'Y' ) ),
			),
			'menu_staff' => array( 'A', 'B', 'C' ),
			'shifts'     => array(
				array(
					'staff' => 'A',
					'year'  => 2099,
					'month' => 3,
					'days'  => $days,
				),
				array(
					'staff' => 'B',
					'year'  => 2099,
					'month' => 3,
					'days'  => $days,
				),
				array(
					'staff' => 'C',
					'year'  => 2099,
					'month' => 3,
					'days'  => $days,
				),
			),
		);
	}

	/**
	 * リソースタグ絞り込み用のシナリオ（月カレンダー用）。
	 *
	 * A=タグX は 2099-03-10 の 10:00-11:00、B=タグY は勤務なし、C=タグX+Y は 11:00-13:00 勤務。
	 *
	 * @return array<string, mixed>
	 */
	private function tag_calendar_scenario(): array {
		return array(
			'tags'       => array( 'X', 'Y', 'Z' ),
			'staff'      => array(
				'A' => array( 'tags' => array( 'X' ) ),
				'B' => array( 'tags' => array( 'Y' ) ),
				'C' => array( 'tags' => array( 'X', 'Y' ) ),
			),
			'menu_staff' => array( 'A', 'B', 'C' ),
			'shifts'     => array(
				array(
					'staff' => 'A',
					'year'  => 2099,
					'month' => 3,
					'days'  => array( 10 => $this->open_day( '10:00', '11:00' ) ),
				),
				array(
					'staff' => 'C',
					'year'  => 2099,
					'month' => 3,
					'days'  => array( 10 => $this->open_day( '11:00', '13:00' ) ),
				),
			),
		);
	}
}
