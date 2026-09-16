<?php
/**
 * 指名スタッフID（resource_id）の正規化タイミングに関するテスト（issue #465 / PR #466）。
 *
 * CodeRabbit 指摘: resolve_staff_ids() は無料版で候補スタッフ配列（戻り値）を基本スタッフへ
 * 正規化するが、呼び出し元（get_calendar_meta() / get_daily_slots() / get_unavailability_reason()）
 * が保持している $preferred_staff_id 自体は正規化されないままだった。そのため resource_id が
 * 0より大きい値で渡ってくると、キャッシュキーの組み立てと generate_slots_for_date() へ
 * 「スタッフを指名している」という状態（$preferred_staff_id > 0）がそのまま渡り、
 * 無料版でも resource_id の有無だけでレスポンス（自動割当の可否・枠の集約）とキャッシュが
 * 分かれてしまっていた。
 *
 * 無料版の予約フロント（src/blocks/reservation/app.js）は「無料版では常にデフォルトスタッフID
 * を送る」実装のため、この経路は机上のものではなく通常フローで実際に発生する。
 *
 * 対応: REST引数から $preferred_staff_id を読み取る時点
 * （Availability_Service::resolve_preferred_staff_id()）で無料版なら常に0へ正規化するよう変更した。
 * このファイルでは、
 * 1. resolve_preferred_staff_id() 自体（private のため ReflectionMethod 経由）が、無料版では
 *    resource_id の値に関わらず常に0を返すことを検証する。
 * 2. 正規化後の値を使う build_cache_key() が、無料版では resource_id の有無に関わらず
 *    同じキャッシュキーになる（＝インスタンスが分かれない）ことを検証する。
 * 3. 実際の公開メソッド get_daily_slots() / get_calendar_meta() が、無料版では resource_id を
 *    指定した場合と指定しない場合とで完全に同じレスポンスを返すことを検証する
 *    （キャッシュ経由で同一の配列が返ることまで確認するため、2回目の呼び出しは
 *    「別内容を再計算した末にたまたま一致した」ではなく「同じキャッシュを読んだ」ことの
 *    証拠になる）。
 * 4. Pro版（Staff_Editor::is_enabled() === true）では、この修正後も resource_id の指定が
 *    従来どおり「指名」として扱われ、担当外スタッフを指名すればエラーになることを検証する
 *    （正規化が無料版限定であることの回帰確認）。
 *
 * 安藤さんレビュー・HIGH是正（PR #466 再指摘）: 上記1〜4の対応（resource_id 読み取り時点の
 * 正規化）だけでは、枠生成・キャッシュキーへ渡す「スタッフを指名している」状態
 * （$is_staff_preferred）まで $preferred_staff_id（常に0）に連動して常に false になってしまい、
 * 無料版で「予約済みの時間帯を自動割り当て相当として返す（予約済み枠を除外しない）」という
 * origin/main には無かった機能回帰を引き起こしていた。無料版は担当が常に基本スタッフ1名固定
 * なので、そのスタッフへの予約は常に「指名あり」と同じ扱い（予約済み枠を除外）にしないと
 * いけない。対応として get_calendar_meta() / get_daily_slots() に
 * `$is_staff_preferred = ! Staff_Editor::is_enabled() || $preferred_staff_id > 0;` を導入し、
 * 枠生成・キャッシュキーへはこちらを渡すよう変更した（$preferred_staff_id 自体は1〜4のとおり
 * 正規化されたままなので、CodeRabbit指摘の「resource_id有無でレスポンスが変わる」は再発しない）。
 * 5. この項目を検証するため、無料版で基本スタッフに予約が入っている時間帯が
 *    get_daily_slots() のレスポンスから除外されることを確認するテストを追加した。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Availability;

use DateTimeImmutable;
use DateTimeZone;
use ReflectionMethod;
use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\PostTypes\Shift_Post_Type;
use VKBookingManager\Staff\Staff_Editor;
use WP_Error;
use WP_Post;
use WP_UnitTestCase;
use function current_datetime;
use function get_post;
use function get_posts;
use function update_post_meta;
use function wp_update_post;

/**
 * $preferred_staff_id の正規化タイミングを検証するテストクラス。
 *
 * @group availability
 * @group preferred-staff-id-normalization
 */
class Preferred_Staff_Id_Normalization_Test extends WP_UnitTestCase {

	/**
	 * 元のサイトタイムゾーン文字列（テスト後に復元する）。
	 *
	 * @var string
	 */
	private $original_timezone_string = '';

	/**
	 * 予約締切・上限日数の相対計算をブレさせないため、サイトTZをAsia/Tokyoに固定する。
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->original_timezone_string = (string) get_option( 'timezone_string', '' );
		update_option( 'timezone_string', 'Asia/Tokyo' );
	}

	/**
	 * サイトタイムゾーンを元に戻し、指名機能の静的キャッシュをクリアする。
	 */
	protected function tearDown(): void {
		update_option( 'timezone_string', $this->original_timezone_string );
		Staff_Editor::clear_nomination_enabled_cache();
		parent::tearDown();
	}

	/**
	 * resolve_preferred_staff_id() が、無料版では resource_id の値に関わらず常に0を返すことを検証する。
	 *
	 * Pro版では既存どおり resource_id の値をそのまま返す（回帰確認）。
	 */
	public function test_resolve_preferred_staff_id_normalizes_only_in_free_edition(): void {
		$service    = new Availability_Service();
		$reflection = new ReflectionMethod( Availability_Service::class, 'resolve_preferred_staff_id' );
		$reflection->setAccessible( true );

		$without_resource_id = $reflection->invoke( $service, array() );
		$with_resource_id    = $reflection->invoke( $service, array( 'resource_id' => 555 ) );

		if ( Staff_Editor::is_enabled() ) {
			$this->assertSame( 0, $without_resource_id, 'Pro版・resource_id未指定 => 0（正常系・従来どおり）' );
			$this->assertSame( 555, $with_resource_id, 'Pro版・resource_id指定 => その値をそのまま返す（正常系・従来どおり）' );
		} else {
			$this->assertSame( 0, $without_resource_id, '無料版・resource_id未指定 => 0（正常系）' );
			$this->assertSame( 0, $with_resource_id, '無料版・resource_id指定（0より大きい値） => 信用せず0へ正規化する（異常系・本PRの核心、CodeRabbit指摘）' );
		}
	}

	/**
	 * 正規化後の値を使う build_cache_key() が、無料版では resource_id の有無に関わらず
	 * 同じキャッシュキーになることを検証する（無料版では別々のキャッシュに分かれてはいけない）。
	 *
	 * Pro版では従来どおり、resource_id の有無でキャッシュキーが分かれることも合わせて確認する。
	 */
	public function test_build_cache_key_reflects_normalized_preferred_staff_id(): void {
		$service = new Availability_Service();

		$preferred_reflection = new ReflectionMethod( Availability_Service::class, 'resolve_preferred_staff_id' );
		$preferred_reflection->setAccessible( true );

		$cache_key_reflection = new ReflectionMethod( Availability_Service::class, 'build_cache_key' );
		$cache_key_reflection->setAccessible( true );

		$preferred_without = $preferred_reflection->invoke( $service, array() );
		$preferred_with    = $preferred_reflection->invoke( $service, array( 'resource_id' => 555 ) );

		$key_without = (string) $cache_key_reflection->invoke( $service, 'daily', 3, array( 10 ), '2026-04-01', 'Asia/Tokyo', $preferred_without > 0 );
		$key_with    = (string) $cache_key_reflection->invoke( $service, 'daily', 3, array( 10 ), '2026-04-01', 'Asia/Tokyo', $preferred_with > 0 );

		if ( Staff_Editor::is_enabled() ) {
			$this->assertNotSame( $key_without, $key_with, 'Pro版では resource_id の有無でキャッシュキーが分かれる（正常系・従来どおり「指名」として区別される）' );
		} else {
			$this->assertSame( $key_without, $key_with, '無料版では resource_id の有無でキャッシュキーが分かれてはいけない（異常系・本PRの核心）' );
		}
	}

	/**
	 * 無料版で get_daily_slots() が、resource_id を指定した場合と指定しない場合とで
	 * 完全に同じレスポンスを返すことを検証する（本 CodeRabbit 指摘の核心）。
	 *
	 * 2回目の呼び出しがキャッシュ（1回目が書き込んだ transient）をそのまま読むことまで
	 * assertSame() で確認する。修正前はキャッシュキーが分かれるため2回目はキャッシュを
	 * ヒットせず、$preferred_staff_id > 0 = true のまま generate_slots_for_date() へ渡り
	 * 自動割当が無効化された別内容が生成されて red になる。
	 */
	public function test_get_daily_slots_free_edition_response_identical_with_and_without_resource_id(): void {
		if ( Staff_Editor::is_enabled() ) {
			$this->markTestSkipped( '無料版限定の挙動のため、有料版ビルドではスキップする（issue #465 / PR #466）。' );
		}

		$this->draft_all_published_resources();
		$default_staff_id = $this->create_staff();
		$menu_id          = $this->create_menu();
		update_post_meta( $menu_id, '_vkbm_duration_minutes', 130 );
		update_post_meta( $menu_id, '_vkbm_buffer_after_minutes', 10 );

		$now    = current_datetime();
		$target = $now->modify( '+2 months' );
		$year   = (int) $target->format( 'Y' );
		$month  = (int) $target->format( 'n' );

		$this->create_full_month_shift(
			$default_staff_id,
			$year,
			$month,
			'open',
			array(
				array(
					'start' => '10:00',
					'end'   => '19:00',
				),
			)
		);

		$service = new Availability_Service();

		// 実際に予約可能な日を1件見つける（メニュー設定（曜日制限等）の既定値に依存しないため、
		// 決め打ちの日付を使わずカレンダーから探す）。
		$calendar = $service->get_calendar_meta(
			array(
				'menu_id'  => $menu_id,
				'year'     => $year,
				'month'    => $month,
				'timezone' => 'Asia/Tokyo',
			)
		);
		$this->assertIsArray( $calendar, '前提: get_calendar_meta() がエラーを返さないこと' );

		$available_date = null;
		foreach ( $calendar['days'] as $day ) {
			if ( $day['available_slots'] > 0 ) {
				$available_date = $day['date'];
				break;
			}
		}
		$this->assertNotNull( $available_date, '前提: 基本スタッフの勤務時間から予約可能な日が1件はあること' );

		$args_without_resource_id = array(
			'menu_id'  => $menu_id,
			'date'     => $available_date,
			'timezone' => 'Asia/Tokyo',
		);
		$result_without           = $service->get_daily_slots( $args_without_resource_id );

		$this->assertIsArray( $result_without, 'resource_id未指定でエラーにならないこと' );
		$this->assertNotEmpty( $result_without['slots'], '前提: 実際にスロットが生成されるシナリオであること（比較が自明にならないようにするため）' );

		$args_with_resource_id                = $args_without_resource_id;
		$args_with_resource_id['resource_id'] = $default_staff_id;
		$result_with                          = $service->get_daily_slots( $args_with_resource_id );

		$this->assertSame(
			$result_without,
			$result_with,
			'無料版では resource_id の有無でレスポンス（slots・meta とも）が変わってはいけない（CodeRabbit指摘・PR#466の核心）'
		);
	}

	/**
	 * 無料版で get_calendar_meta() が、resource_id を指定した場合と指定しない場合とで
	 * 完全に同じレスポンスを返すことを検証する（get_daily_slots() と同じ観点、月次カレンダー版）。
	 */
	public function test_get_calendar_meta_free_edition_response_identical_with_and_without_resource_id(): void {
		if ( Staff_Editor::is_enabled() ) {
			$this->markTestSkipped( '無料版限定の挙動のため、有料版ビルドではスキップする（issue #465 / PR #466）。' );
		}

		$this->draft_all_published_resources();
		$default_staff_id = $this->create_staff();
		$menu_id          = $this->create_menu();
		update_post_meta( $menu_id, '_vkbm_duration_minutes', 130 );
		update_post_meta( $menu_id, '_vkbm_buffer_after_minutes', 10 );

		$now    = current_datetime();
		$target = $now->modify( '+2 months' );
		$year   = (int) $target->format( 'Y' );
		$month  = (int) $target->format( 'n' );

		$this->create_full_month_shift(
			$default_staff_id,
			$year,
			$month,
			'open',
			array(
				array(
					'start' => '10:00',
					'end'   => '19:00',
				),
			)
		);

		$service = new Availability_Service();

		$args_without_resource_id = array(
			'menu_id'  => $menu_id,
			'year'     => $year,
			'month'    => $month,
			'timezone' => 'Asia/Tokyo',
		);
		$result_without           = $service->get_calendar_meta( $args_without_resource_id );

		$this->assertIsArray( $result_without, 'resource_id未指定でエラーにならないこと' );
		$has_available_day = false;
		foreach ( $result_without['days'] as $day ) {
			if ( $day['available_slots'] > 0 ) {
				$has_available_day = true;
				break;
			}
		}
		$this->assertTrue( $has_available_day, '前提: 実際に予約可能な日が生成されるシナリオであること（比較が自明にならないようにするため）' );

		$args_with_resource_id                = $args_without_resource_id;
		$args_with_resource_id['resource_id'] = $default_staff_id;
		$result_with                          = $service->get_calendar_meta( $args_with_resource_id );

		$this->assertSame(
			$result_without,
			$result_with,
			'無料版では resource_id の有無でレスポンス（days・meta とも）が変わってはいけない（CodeRabbit指摘・PR#466の核心）'
		);
	}

	/**
	 * Pro版では、この修正後も resource_id の指定が従来どおり「指名」として扱われることを検証する
	 * （正規化が無料版限定であることの回帰確認）。
	 *
	 * - resource_id未指定 => メタの resource_id は null。
	 * - 担当スタッフを指名 => メタの resource_id にそのIDがそのまま反映される。
	 * - 担当外のスタッフを指名 => staff_not_assigned エラーになる（正規化で骨抜きになっていないこと）。
	 */
	public function test_get_daily_slots_pro_edition_still_treats_resource_id_as_preference(): void {
		if ( ! Staff_Editor::is_enabled() ) {
			$this->markTestSkipped( 'Pro版限定の回帰確認のため、無料版ビルドではスキップする（issue #465 / PR #466）。' );
		}

		$assigned_staff = $this->create_staff();
		$other_staff    = $this->create_staff();
		$menu_id        = $this->create_menu();
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $assigned_staff ) );

		$date = ( new DateTimeImmutable( 'now', new DateTimeZone( 'Asia/Tokyo' ) ) )->modify( '+3 days' )->format( 'Y-m-d' );

		$service = new Availability_Service();

		$result_without = $service->get_daily_slots(
			array(
				'menu_id'  => $menu_id,
				'date'     => $date,
				'timezone' => 'Asia/Tokyo',
			)
		);
		$this->assertIsArray( $result_without, 'resource_id未指定なら担当設定どおり解決されエラーにならない（正常系・従来どおり）' );
		$this->assertNull( $result_without['meta']['resource_id'], 'resource_id未指定時はメタのresource_idがnull（正常系・従来どおり）' );

		$result_with_assigned = $service->get_daily_slots(
			array(
				'menu_id'     => $menu_id,
				'date'        => $date,
				'timezone'    => 'Asia/Tokyo',
				'resource_id' => $assigned_staff,
			)
		);
		$this->assertIsArray( $result_with_assigned, '担当スタッフを指名すればエラーにならない（正常系・従来どおり）' );
		$this->assertSame( $assigned_staff, $result_with_assigned['meta']['resource_id'], 'Pro版では指名したスタッフIDがそのままメタへ反映される（正常系・従来どおり）' );

		$result_with_other = $service->get_daily_slots(
			array(
				'menu_id'     => $menu_id,
				'date'        => $date,
				'timezone'    => 'Asia/Tokyo',
				'resource_id' => $other_staff,
			)
		);
		$this->assertInstanceOf( WP_Error::class, $result_with_other, 'Pro版では担当外のスタッフを指名するとエラーになる（異常系・従来どおり、正規化で骨抜きになっていないことの確認）' );
		$this->assertSame( 'staff_not_assigned', $result_with_other->get_error_code(), 'Pro版では担当外のスタッフを指名するとエラーになる（異常系・従来どおり）' );
	}

	/**
	 * 無料版で、基本スタッフに予約が入っている時間帯が get_daily_slots() のレスポンスから
	 * 除外されることを検証する（安藤さんレビュー・HIGH是正の核心）。
	 *
	 * 修正前（$is_staff_preferred が resource_id の有無＝常に false のまま）は、予約済みの
	 * 時間帯も自動割り当て相当として枠に残り続け、かつ無料版は定員が常に1のため
	 * フロント側の満枠判定（capacity > 1 が条件）をすり抜けて「押せる枠」として表示されて
	 * しまっていた（origin/main には無かった機能回帰）。修正後は無料版でも常に
	 * skip_booked_slots=true 相当で枠を作るため、予約済みの時間帯はレスポンスに含まれない。
	 *
	 * 予約を作る前に generate_slots_for_date()（private）を ReflectionMethod 経由で直接
	 * 呼び出し、実際に生成される最初のスロットの開始/終了時刻を取得してから、その時刻と
	 * 完全に一致する予約を作成する。get_daily_slots()（public・キャッシュあり）自体は
	 * 予約作成後に1回だけ呼ぶため、transientキャッシュの新旧に関する不確実性を排除できる。
	 *
	 * 予約作成後の呼び出しは、予約前の探索に使った $service とは別の新しい
	 * Availability_Service インスタンスで行う。get_bookings_for_staff_date() の結果は
	 * $booking_cache（インスタンスプロパティ、staff_id-date キー）にプロセス内キャッシュ
	 * されるため、同一インスタンスを使い回すと「予約が無かった時点」の結果が
	 * 予約作成後の呼び出しにも残ってしまう（transientとは別の、この設計に特有の罠）。
	 */
	public function test_get_daily_slots_free_edition_excludes_already_booked_slot(): void {
		if ( Staff_Editor::is_enabled() ) {
			$this->markTestSkipped( '無料版限定の挙動のため、有料版ビルドではスキップする（issue #465 / PR #466）。' );
		}

		$this->draft_all_published_resources();
		$default_staff_id = $this->create_staff();
		$menu_id          = $this->create_menu();
		update_post_meta( $menu_id, '_vkbm_duration_minutes', 130 );
		update_post_meta( $menu_id, '_vkbm_buffer_after_minutes', 10 );

		$now    = current_datetime();
		$target = $now->modify( '+2 months' );
		$year   = (int) $target->format( 'Y' );
		$month  = (int) $target->format( 'n' );

		$this->create_full_month_shift(
			$default_staff_id,
			$year,
			$month,
			'open',
			array(
				array(
					'start' => '10:00',
					'end'   => '19:00',
				),
			)
		);

		$service = new Availability_Service();

		// 予約可能な日を1件見つける（calendar側のキャッシュ名前空間は daily とは別なので、
		// ここで get_calendar_meta() を呼んでも後続の get_daily_slots() のキャッシュには影響しない）。
		$calendar = $service->get_calendar_meta(
			array(
				'menu_id'  => $menu_id,
				'year'     => $year,
				'month'    => $month,
				'timezone' => 'Asia/Tokyo',
			)
		);
		$this->assertIsArray( $calendar, '前提: get_calendar_meta() がエラーを返さないこと' );

		$available_date = null;
		foreach ( $calendar['days'] as $day ) {
			if ( $day['available_slots'] > 0 ) {
				$available_date = $day['date'];
				break;
			}
		}
		$this->assertNotNull( $available_date, '前提: 基本スタッフの勤務時間から予約可能な日が1件はあること' );

		// generate_slots_for_date()（private）を直接呼び、予約を作る前の「素の」スロット一覧を
		// 取得する。get_daily_slots() のtransientキャッシュには一切触れない。
		$menu_post = get_post( $menu_id );
		$this->assertInstanceOf( WP_Post::class, $menu_post );
		$reflection = new ReflectionMethod( Availability_Service::class, 'generate_slots_for_date' );
		$reflection->setAccessible( true );
		$preview_slots = $reflection->invoke(
			$service,
			$menu_post,
			array( $default_staff_id ),
			$available_date,
			new DateTimeZone( 'Asia/Tokyo' ),
			true // is_staff_preferred=true で、予約前の「集約されていない」生スロットを見る。
		);
		$this->assertNotEmpty( $preview_slots, '前提: 実際にスロットが生成されるシナリオであること（比較が自明にならないようにするため）' );

		// generate_slots_for_date() の戻り値は 'start_at' / 'end_at' / 'service_end_at' を
		// DATE_ATOM形式の文字列で持つ（build_slots_from_entry() 由来の DateTimeImmutable から
		// 生成側で整形済み）。予約メタは 'Y-m-d H:i:s' 形式のため、一度 DateTimeImmutable へ
		// パースしてから整形し直す。
		$booked_slot = $preview_slots[0];

		// 見つけた最初のスロットの開始/終了時刻と完全に一致する予約を作成する。
		$this->create_booking_post(
			$default_staff_id,
			$menu_id,
			( new DateTimeImmutable( $booked_slot['start_at'] ) )->format( 'Y-m-d H:i:s' ),
			( new DateTimeImmutable( $booked_slot['service_end_at'] ) )->format( 'Y-m-d H:i:s' ),
			( new DateTimeImmutable( $booked_slot['end_at'] ) )->format( 'Y-m-d H:i:s' )
		);

		// get_daily_slots()（public）を予約作成後に初めて呼ぶ。この日付・引数の組み合わせに
		// 対する daily transientキャッシュはこの呼び出しが最初なので古いキャッシュを読む心配が
		// 無い。さらに新しい Availability_Service インスタンスを使うことで、$service が
		// 上のカレンダー探索・プレビュー呼び出しで溜め込んだ $booking_cache（予約が無かった
		// 時点の結果）も引き継がない。
		$fresh_service = new Availability_Service();
		$result        = $fresh_service->get_daily_slots(
			array(
				'menu_id'  => $menu_id,
				'date'     => $available_date,
				'timezone' => 'Asia/Tokyo',
			)
		);
		$this->assertIsArray( $result, '予約後も get_daily_slots() はエラーを返さない' );

		$returned_starts = array_column( $result['slots'], 'start_at' );

		$this->assertNotContains(
			$booked_slot['start_at'],
			$returned_starts,
			'無料版でも、予約済みの時間帯はレスポンスから除外されるべき（安藤さんレビュー・HIGH是正の核心。修正前は origin/main と異なり除外されず「押せる枠」として残っていた）'
		);
	}

	/**
	 * 既存の公開済みリソース（スタッフ）投稿を全て下書きに落とす。
	 *
	 * Resource_Post_Type::get_default_staff_id() は「公開中の resource がちょうど1件」を
	 * 優先して解決するため、テストシナリオを確定させるために事前に呼び出す
	 * （test-resolve-staff-ids-free-default-staff-fallback.php と同じ方針）。
	 */
	private function draft_all_published_resources(): void {
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

	/**
	 * スタッフ（リソース）投稿を作成する。
	 *
	 * @return int スタッフ投稿ID。
	 */
	private function create_staff(): int {
		return (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * サービスメニュー投稿を作成する。
	 *
	 * @return int メニュー投稿ID。
	 */
	private function create_menu(): int {
		return (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * 対象月の全日について、同一ステータス・同一スロット構成のシフトを作成する。
	 *
	 * @param int                               $staff_id スタッフID。
	 * @param int                               $year     年。
	 * @param int                               $month    月。
	 * @param string                            $status   1日ごとのステータス（open / regular_holiday 等）。
	 * @param array<int, array<string, string>> $slots    1日ごとの勤務スロット（start/end のペア）。
	 * @return int シフト投稿ID。
	 */
	private function create_full_month_shift( int $staff_id, int $year, int $month, string $status, array $slots ): int {
		// タイムゾーン依存のズレを避けるため、DateTimeImmutable ベースで月の日数を算出する
		// （test-resolve-staff-ids-free-default-staff-fallback.php と同じ方針）。
		$days_in_month = (int) ( new DateTimeImmutable( sprintf( '%04d-%02d-01', $year, $month ) ) )->format( 't' );

		$days = array();
		for ( $day = 1; $day <= $days_in_month; $day++ ) {
			$days[ $day ] = array(
				'status' => $status,
				'slots'  => $slots,
			);
		}

		$shift_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Shift_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $shift_id, '_vkbm_shift_resource_id', $staff_id );
		update_post_meta( $shift_id, '_vkbm_shift_year', $year );
		update_post_meta( $shift_id, '_vkbm_shift_month', $month );
		update_post_meta( $shift_id, '_vkbm_shift_days', $days );

		return $shift_id;
	}

	/**
	 * 予約投稿を作成する（tests/phpunit/availability/test-exclusive-booking.php の
	 * create_booking_post() と同じメタキー構成）。
	 *
	 * @param int    $staff_id  担当スタッフID。
	 * @param int    $menu_id   サービスメニューID。
	 * @param string $start     予約サービス開始日時（Y-m-d H:i:s）。
	 * @param string $end       予約サービス終了日時（Y-m-d H:i:s、バッファ含まず）。
	 * @param string $total_end 予約枠終了日時（Y-m-d H:i:s、バッファ込み）。
	 * @return int 予約投稿ID。
	 */
	private function create_booking_post( int $staff_id, int $menu_id, string $start, string $end, string $total_end ): int {
		$booking_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $booking_id, '_vkbm_booking_service_start', $start );
		update_post_meta( $booking_id, '_vkbm_booking_service_end', $end );
		update_post_meta( $booking_id, '_vkbm_booking_total_end', $total_end );
		update_post_meta( $booking_id, '_vkbm_booking_resource_id', $staff_id );
		update_post_meta( $booking_id, '_vkbm_booking_service_id', $menu_id );
		// キャンセル・無断キャンセル以外の任意のステータス（get_bookings_for_staff_date() が
		// 予約消費として数える条件は「no_show / cancelled 以外」）。
		update_post_meta( $booking_id, '_vkbm_booking_status', 'confirmed' );

		return $booking_id;
	}
}
