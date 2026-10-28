<?php
/**
 * 宿泊のときのシフト編集画面（販売日の登録）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Shifts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\ProviderSettings\Industry_Presets;

/**
 * 宿泊のときのシフト編集画面の出力・JS への受け渡し・保存値の組み立てを担当する。
 *
 * 宿泊かどうかは Industry_Presets::supports( 'lodging' ) だけで判定する。
 * 宿泊では、シフトは客室を販売する日の登録に使う（日ごとに「販売する」か「販売しない」）。
 * データの形（_vkbm_shift_days）は他の業種と同じで、画面と保存の振る舞いだけが異なる。
 */
class Shift_Lodging_Editor {
	/**
	 * 画面の種類を送る隠し項目の名前（vkbm_shift[screen_mode]）。
	 */
	public const SCREEN_MODE_FIELD = 'screen_mode';

	/**
	 * 通常の画面（宿泊以外）。この項目が送られないときもこの値として扱う。
	 */
	public const SCREEN_MODE_STANDARD = 'standard';

	/**
	 * 宿泊の画面（販売日の登録）。
	 */
	public const SCREEN_MODE_LODGING = 'lodging';

	/**
	 * 販売日の状態を送る隠し項目の名前（vkbm_shift[sale_days_json]）。
	 */
	public const SALE_DAYS_FIELD = 'sale_days_json';

	private const STATUS_OPEN        = 'open';
	private const STATUS_UNAVAILABLE = 'unavailable';

	/**
	 * 今のサイトで宿泊の画面を使うかを返す。
	 *
	 * @return bool
	 */
	public static function is_lodging_screen(): bool {
		return Industry_Presets::supports( 'lodging' );
	}

	/**
	 * 今のサイトの画面の種類を返す。
	 *
	 * @return string SCREEN_MODE_LODGING または SCREEN_MODE_STANDARD。
	 */
	public static function get_current_screen_mode(): string {
		return self::is_lodging_screen() ? self::SCREEN_MODE_LODGING : self::SCREEN_MODE_STANDARD;
	}

	/**
	 * 送信された画面の種類が、今のサイトの画面の種類と一致するかを返す。
	 *
	 * 画面を開いたあとに業種を切り替えられた場合の保護に使う。
	 * 画面の種類が送られていない（空）ときは通常の画面として扱う。
	 *
	 * @param string $submitted_mode 送信された画面の種類。
	 * @return bool
	 */
	public static function matches_current_screen_mode( string $submitted_mode ): bool {
		$submitted_mode = '' === $submitted_mode ? self::SCREEN_MODE_STANDARD : $submitted_mode;

		return self::get_current_screen_mode() === $submitted_mode;
	}

	/**
	 * 日のデータが「販売する」を表すかを返す。
	 *
	 * @param mixed $entry 日のデータ（status と slots を持つ配列）。
	 * @return bool
	 */
	public static function is_sale_entry( $entry ): bool {
		return Shift_Sale_Days::is_sale_entry( $entry );
	}

	/**
	 * 日ごとのデータを、画面へ渡す「日 => 販売するか」の形にする。
	 *
	 * データに無い日は「販売しない」にする（月の全日を返す）。
	 *
	 * @param array<int, array<string, mixed>> $days  日ごとのデータ。
	 * @param int                              $year  年。
	 * @param int                              $month 月。
	 * @return array<int, bool> 日（1 から月末）=> 販売するか。
	 */
	public static function build_sale_flags( array $days, int $year, int $month ): array {
		$flags = array();
		$last  = (int) gmdate( 't', gmmktime( 0, 0, 0, $month, 1, $year ) );

		for ( $day = 1; $day <= $last; $day++ ) {
			$flags[ $day ] = self::is_sale_entry( $days[ $day ] ?? null );
		}

		return $flags;
	}

	/**
	 * 送信された販売日の JSON を、日ごとの「販売するか」に変換する。
	 *
	 * @param string $json  JSON 文字列（例: {"1":1,"2":0}）。
	 * @param int    $year  年。
	 * @param int    $month 月。
	 * @return array<int, bool>|null 月の全日の値。JSON が配列でない・空のときは null。
	 */
	public static function parse_sale_flags( string $json, int $year, int $month ): ?array {
		$data = json_decode( $json, true );

		if ( ! is_array( $data ) || empty( $data ) || $year <= 0 || $month <= 0 ) {
			return null;
		}

		$flags = array();
		$last  = (int) gmdate( 't', gmmktime( 0, 0, 0, $month, 1, $year ) );

		for ( $day = 1; $day <= $last; $day++ ) {
			$value         = $data[ (string) $day ] ?? $data[ $day ] ?? 0;
			$flags[ $day ] = ( true === $value || 1 === $value || '1' === $value );
		}

		return $flags;
	}

	/**
	 * 宿泊の保存値（月の全日の状態付きデータ）を組み立てる。
	 *
	 * 前に保存されていた値とつなぎ合わせ、運営者が触っていない日は元の値を残す。
	 * - 「販売する」の日: 前が open / temporary_open なら前の値のまま。それ以外（休み系・データ無し）は open＋その日の既定の時間帯。
	 *   既定の時間帯は客室のシフトテンプレート、無ければ基本営業時間から作った値で、どちらも無ければ空。
	 *   時間枠のプランの予約枠は、この時間帯から作られる。
	 * - 「販売しない」の日: 前が休み系（unavailable / regular_holiday / temporary_closed）なら前の値のまま。
	 *   それ以外（前が販売する・前のデータが無い）は unavailable（休み）。
	 * 販売する日は、時間帯が空でも open で明示的に残す（保存時に時間帯の無い open の日を捨てる処理を通さないため）。
	 * 切り替えた日には、客室のシフトテンプレート、無ければ基本営業時間の時間帯を入れる。
	 *
	 * @param array<int, bool>                              $flags         日（1 から月末）=> 販売するか。
	 * @param array<int, array<string, mixed>>              $previous_days 前に保存されていた日ごとのデータ（正規化済み。無ければ空）。
	 * @param array<int, array<int, array<string, string>>> $open_slots_by_day 日（1 から月末）=> 販売する日にしたときの時間帯。
	 * @return array<int, array<string, mixed>> 日 => status と slots を持つデータ。
	 */
	public static function build_days_for_save( array $flags, array $previous_days, array $open_slots_by_day = array() ): array {
		$days = array();

		foreach ( $flags as $day => $on_sale ) {
			$day      = (int) $day;
			$previous = $previous_days[ $day ] ?? null;

			if ( $on_sale ) {
				$days[ $day ] = self::is_sale_entry( $previous )
					? $previous
					: array(
						'status' => self::STATUS_OPEN,
						'slots'  => $open_slots_by_day[ $day ] ?? array(),
					);
				continue;
			}

			$days[ $day ] = ( is_array( $previous ) && ! self::is_sale_entry( $previous ) )
				? $previous
				: array(
					'status' => self::STATUS_UNAVAILABLE,
					'slots'  => array(),
				);
		}

		return $days;
	}

	/**
	 * 宿泊のときの、日ごとの編集部分（説明・一括ボタン・販売日の表）を出力する。
	 *
	 * 年月と客室の選択欄は Shift_Editor 側で出力する（他の業種と共通）。
	 *
	 * @param string $flags_json 画面の初期状態の JSON（日 => 販売するか）。
	 */
	public function render_days_editor( string $flags_json ): void {
		$resource_label = vkbm_get_resource_label_singular();
		?>
		<input type="hidden" id="vkbm-shift-lodging-days-json" name="vkbm_shift[<?php echo esc_attr( self::SALE_DAYS_FIELD ); ?>]" value="<?php echo esc_attr( $flags_json ); ?>" />

		<div class="vkbm-shift-lodging-description">
			<p>
				<?php
				/* translators: %s: label of the resource (e.g. room). */
				echo esc_html( sprintf( __( 'Check the days this %s is on sale.', 'vk-booking-manager' ), $resource_label ) );
				?>
				<?php esc_html_e( 'Bookings are not accepted on unchecked days.', 'vk-booking-manager' ); ?>
				<?php esc_html_e( 'Changes take effect when you save.', 'vk-booking-manager' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'Days are registered month by month.', 'vk-booking-manager' ); ?>
				<?php esc_html_e( 'Months with no registration are treated as not on sale, and stays spanning into them cannot be booked.', 'vk-booking-manager' ); ?>
			</p>
			<p>
				<?php
				/* translators: %s: label of the resource (e.g. room). */
				echo esc_html( sprintf( __( 'Changing the month or %s resets the days on sale shown here to the defaults.', 'vk-booking-manager' ), $resource_label ) );
				?>
			</p>
		</div>

		<div class="vkbm-shift-lodging-bulk">
			<button type="button" class="button vkbm-shift-lodging-bulk-on"><?php esc_html_e( 'Put all days on sale', 'vk-booking-manager' ); ?></button>
			<button type="button" class="button vkbm-shift-lodging-bulk-off"><?php esc_html_e( 'Take all days off sale', 'vk-booking-manager' ); ?></button>
		</div>
		<div class="vkbm-shift-lodging-bulk-status" id="vkbm-shift-lodging-bulk-status" role="status" aria-live="polite"></div>

		<fieldset class="vkbm-shift-days vkbm-shift-lodging-days vkbm-schedule-container" id="vkbm-shift-days">
			<legend class="vkbm-shift-lodging-legend"><?php esc_html_e( 'Days on sale', 'vk-booking-manager' ); ?></legend>
			<table class="form-table vkbm-setting-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Date', 'vk-booking-manager' ); ?></th>
						<th scope="col"><?php echo esc_html_x( 'On sale', 'table column heading', 'vk-booking-manager' ); ?></th>
					</tr>
				</thead>
				<tbody id="vkbm-shift-days-body"></tbody>
			</table>
		</fieldset>

		<script type="text/template" id="vkbm-shift-lodging-row-template">
			<tr class="vkbm-shift-lodging-row" data-day="__DAY__">
				<th scope="row" class="vkbm-shift-lodging-date" id="vkbm-shift-lodging-date-__DAY__"></th>
				<td class="vkbm-shift-lodging-cell">
					<label class="vkbm-shift-lodging-sale-label">
						<input type="checkbox" class="vkbm-shift-lodging-sale" data-day="__DAY__" aria-labelledby="vkbm-shift-lodging-date-__DAY__ vkbm-shift-lodging-sale-text-__DAY__" />
						<span id="vkbm-shift-lodging-sale-text-__DAY__"><?php echo esc_html_x( 'On sale', 'checkbox label', 'vk-booking-manager' ); ?></span>
					</label>
					<span class="vkbm-shift-lodging-off-label" aria-hidden="true" hidden><?php esc_html_e( 'Not on sale', 'vk-booking-manager' ); ?></span>
				</td>
			</tr>
		</script>
		<?php
	}

	/**
	 * 宿泊用の JS へ渡す設定を組み立てる。
	 *
	 * @param array<string, mixed> $state Shift_Editor::get_editor_state() の戻り値。
	 * @return array<string, mixed>
	 */
	public function build_script_config( array $state ): array {
		return array(
			'daysJsonField'    => '#vkbm-shift-lodging-days-json',
			'daysTableBody'    => '#vkbm-shift-days-body',
			'yearSelector'     => '#vkbm-shift-year',
			'monthSelector'    => '#vkbm-shift-month',
			'resourceSelector' => '#vkbm-shift-resource',
			'rowTemplate'      => '#vkbm-shift-lodging-row-template',
			'bulkOnSelector'   => '.vkbm-shift-lodging-bulk-on',
			'bulkOffSelector'  => '.vkbm-shift-lodging-bulk-off',
			'bulkStatus'       => '#vkbm-shift-lodging-bulk-status',
			'flags'            => (object) self::build_sale_flags( is_array( $state['days_for_editor'] ?? null ) ? $state['days_for_editor'] : array(), (int) $state['year'], (int) $state['month'] ),
			'initialResource'  => (int) $state['resource_id'],
			'initialYear'      => (int) $state['year'],
			'initialMonth'     => (int) $state['month'],
			'strings'          => array(
				/* translators: 1: month number, 2: day of month, 3: weekday. */
				'dateFormat'   => __( '%1$d/%2$d (%3$s)', 'vk-booking-manager' ),
				'bulkOnDone'   => __( 'All days are now on sale.', 'vk-booking-manager' ),
				'bulkOffDone'  => __( 'All days are now off sale.', 'vk-booking-manager' ),
				'notApplied'   => __( 'Changes are not applied until you save.', 'vk-booking-manager' ),
				'loading'      => __( 'Loading the default days on sale.', 'vk-booking-manager' ),
				'loadingRow'   => __( 'Loading…', 'vk-booking-manager' ),
				'savedShown'   => __( 'Showing the saved days on sale.', 'vk-booking-manager' ),
				'resetDone'    => __( 'The days on sale have been reset to the defaults.', 'vk-booking-manager' ),
				/* translators: %s: label of the resource (e.g. room). */
				'noResource'   => sprintf( __( 'Select a %s to load the default days on sale.', 'vk-booking-manager' ), vkbm_get_resource_label_singular() ),
				'loadFailed'   => __( 'The default days on sale could not be loaded.', 'vk-booking-manager' ),
				'allOffSale'   => __( 'All days are shown as not on sale.', 'vk-booking-manager' ),
				'weekdayShort' => array(
					'sun' => __( 'Sun', 'vk-booking-manager' ),
					'mon' => __( 'Mon', 'vk-booking-manager' ),
					'tue' => __( 'Tue', 'vk-booking-manager' ),
					'wed' => __( 'Wed', 'vk-booking-manager' ),
					'thu' => __( 'Thu', 'vk-booking-manager' ),
					'fri' => __( 'Fri', 'vk-booking-manager' ),
					'sat' => __( 'Sat', 'vk-booking-manager' ),
				),
			),
			'ajax'             => array(
				'url'   => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'vkbm_shift_template' ),
			),
		);
	}
}
