<?php
/**
 * get_daily_slots() に渡された引数を記録するテストダブル（issue #431）。
 *
 * 既存の Availability_Service_Test_Double（test-booking-confirmation-controller.php）は
 * 固定スロットを返すだけで呼び出し引数を記録しないため、引数の受け渡し自体を検証する
 * test-booking-resource-tag-passthrough.php のために別クラス・別ファイルとして用意する
 * （このプロジェクトの PHPCS ルール Generic.Files.OneObjectStructurePerFile に合わせ、
 * テスト本体とは別ファイルに分ける）。
 *
 * ファイル名は phpunit.xml.dist の `<directory prefix="test-" suffix=".php">` に
 * 一致させており、PHPUnit のテストスイート読み込み対象になる（このファイル自体は
 * WP_UnitTestCase を継承せずテストメソッドを持たないため、実行されるテスト数には
 * 影響しない。クラス定義だけが読み込まれる）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use VKBookingManager\Availability\Availability_Service;

/**
 * get_daily_slots() の呼び出し引数を記録するテストダブル。
 */
class Availability_Service_Args_Recording_Test_Double extends Availability_Service {
	/**
	 * get_daily_slots() が返す固定スロット。
	 *
	 * @var array<string, mixed>
	 */
	private array $slot;

	/**
	 * 直近の get_daily_slots() 呼び出しに渡された引数（未呼び出しなら null）。
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $last_get_daily_slots_args = null;

	/**
	 * 固定で返すスロットを受け取る。
	 *
	 * @param array<string, mixed> $slot get_daily_slots() が返す固定スロット。
	 */
	public function __construct( array $slot ) {
		$this->slot = $slot;
	}

	/**
	 * 呼び出し引数を記録し、固定スロットを1件返す。
	 *
	 * @param array<string, mixed> $args 呼び出し引数（記録用に保持する）。
	 * @return array<string, mixed>
	 */
	public function get_daily_slots( array $args ) {
		$this->last_get_daily_slots_args = $args;

		return array(
			'slots' => array( $this->slot ),
		);
	}
}
