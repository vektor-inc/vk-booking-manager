<?php
/**
 * 予約の状態変化を外部連携（Google カレンダー連携など、後続の #475・#476）へ橋渡しするディスパッチャー。
 *
 * 責務は次の3つに限定する（司の decision record・issue #474 参照）。
 * 1. 種別（created/confirmed/updated/cancelled/trashed/restored/deleted）の判定
 * 2. 同一リクエスト内の重複排除（管理画面保存の入れ子等で外部連携まで二重発火しないように）
 * 3. ゴミ箱・復元・完全削除（trashed_post/untrashed_post/before_delete_post）の購読
 *
 * Google などの外部サービスへの通信は一切持たない。永続化（option・ログテーブル・postmeta）も
 * 行わない。公開フック `vkbm_booking_state_changed` を叩くだけで責務を終える。
 *
 * ## 種別の判定方法（呼び出し元ではなくこのクラスが決める理由）
 * 管理画面の1回の保存で「確定」と「日時変更」が同時に起きうるため、呼び出し元に種別を
 * 選ばせると片方が落ちる（司の decision record 参照）。そのため呼び出し元5箇所
 * （Booking_Confirmation_Controller / Booking_Admin::save_post /
 * Booking_Admin::save_quick_edit / My_Bookings_Controller / Shift_Dashboard_Page）は
 * 「保存前後の内容」を渡すだけにし、種別の判定は dispatch_change() 内の
 * determine_event() に集約する。
 *
 * - $before（呼び出し元が保存前に capture_snapshot() で取得したスナップショット）が null
 *   （投稿が存在しない、または metadata_exists() が false＝ステータスメタが一度も
 *   書き込まれていない）の場合は「created」とする。管理画面の新規作成は WordPress が
 *   「新規追加」画面を開いた時点で auto-draft の投稿行を先に作るため、post_status だけでは
 *   新規作成を判定できない（save_post が発火する時点では既に post_status が publish に
 *   書き換わっている）。そのためステータスメタの有無で判定する。
 * - 変更前後でステータスが confirmed 以外 → confirmed に変わっていれば「confirmed」。
 * - 変更前後でステータスが cancelled 以外 → cancelled に変わっていれば「cancelled」。
 * - 無断キャンセル（no_show）への変更・confirmed→pending の巻き戻しは、上記どちらにも
 *   当てはまらないため「updated」に倒す（司の decision record 参照。ペイロードの
 *   `status`／`previous_status` で判別できる）。
 * - いずれにも当てはまらないが内容（日時・担当・メニュー・人数・顧客情報等）に差分があれば
 *   「updated」。差分が無ければ発火しない（変更なしの再保存を無視する）。
 *
 * ## 同一リクエスト内の重複排除（将来の経路追加への防御）
 * Booking_Admin::save_post は、投稿者の変更・タイトルの自動補完で内部的に wp_update_post() を
 * 呼んでおり、これが save_post_vkbm_booking フックを入れ子で再発火させる（司の decision
 * record 参照）。ただし Booking_Admin::save_post() の再入ガード（issue #477）が、入れ子側の
 * 呼び出しを save_post_inner() へ到達する前に止めるため、現在この経路で重複発火が起きること
 * はない。以下のシグネチャによる重複排除は、この経路では実際には働いていない。将来、別の
 * 呼び出し元が増えるなどして同一リクエスト内で同じ「変更後の内容」が複数回発火するように
 * なった場合に備えた防御として残している。booking_id・種別・ペイロードの内容からシグネチャを
 * 作り、同一シグネチャの再発火をこのリクエスト内でスキップする。$dispatched_signatures は
 * インスタンスプロパティなので、呼び出し元5箇所とプラグイン本体（vk-booking-manager.php）で
 * 同一インスタンスを共有する運用を前提とする（Booking_Notification_Service と同じ配線）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\PostTypes\Booking_Post_Type;
use WP_Post;
use function add_action;
use function do_action;
use function get_post;
use function get_post_meta;
use function md5;
use function metadata_exists;
use function serialize; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- unserialize() は使わない。ここでの serialize() は md5() でハッシュ化して同一リクエスト内の重複排除にのみ使う一時的な文字列生成で、永続化・外部への送信・unserialize() での復元は一切行わない（安藤レビュー指摘・再レビュー1対応 #474）。

/**
 * 予約の状態変化を検知し、公開フック `vkbm_booking_state_changed` を発火するディスパッチャー。
 */
class Booking_Event_Dispatcher {
	/**
	 * 予約が新規作成された。
	 *
	 * @var string
	 */
	public const EVENT_CREATED = 'created';

	/**
	 * 予約が確定した（pending 等から confirmed への遷移）。
	 *
	 * @var string
	 */
	public const EVENT_CONFIRMED = 'confirmed';

	/**
	 * 予約の内容が変更された（confirmed/cancelled への遷移以外の変化）。
	 *
	 * @var string
	 */
	public const EVENT_UPDATED = 'updated';

	/**
	 * 予約がキャンセルされた（cancelled 以外から cancelled への遷移）。
	 *
	 * @var string
	 */
	public const EVENT_CANCELLED = 'cancelled';

	/**
	 * 予約がゴミ箱へ移動した。
	 *
	 * @var string
	 */
	public const EVENT_TRASHED = 'trashed';

	/**
	 * 予約がゴミ箱から復元された。
	 *
	 * @var string
	 */
	public const EVENT_RESTORED = 'restored';

	/**
	 * 予約が完全に削除された。
	 *
	 * @var string
	 */
	public const EVENT_DELETED = 'deleted';

	/**
	 * 公開フック名。
	 *
	 * 過去形の `_changed` にしているのは「予約の状態が変わった後に呼ばれる」ことを
	 * 名前で示すため（WordPress 本体の trashed_post / deleted_post と同じ付け方。
	 * 司の decision record 参照）。`status` という語を避けているのは、このプラグインの
	 * 「予約ステータス」（confirmed/pending/cancelled/no_show の4値）と紛らわしく、
	 * ゴミ箱・完全削除・復元がその4値の外側にあるため。
	 *
	 * @var string
	 */
	private const HOOK_NAME = 'vkbm_booking_state_changed';

	/**
	 * 予約ステータス（メタ値）。Booking_Admin 等と同じ文字列を使う。
	 *
	 * @var string
	 */
	private const STATUS_CONFIRMED = 'confirmed';

	/**
	 * 予約ステータス（メタ値）。Booking_Admin 等と同じ文字列を使う。
	 *
	 * @var string
	 */
	private const STATUS_CANCELLED = 'cancelled';

	/**
	 * 予約メタキー（開始日時）。Booking_Admin 等と同じ値。
	 *
	 * @var string
	 */
	private const META_DATE_START = '_vkbm_booking_service_start';

	/**
	 * 予約メタキー（サービス終了日時）。
	 *
	 * @var string
	 */
	private const META_DATE_END = '_vkbm_booking_service_end';

	/**
	 * 予約メタキー（後片付け込みの終了日時）。
	 *
	 * @var string
	 */
	private const META_TOTAL_END = '_vkbm_booking_total_end';

	/**
	 * 予約メタキー（担当スタッフ／リソースID）。
	 *
	 * @var string
	 */
	private const META_RESOURCE_ID = '_vkbm_booking_resource_id';

	/**
	 * 予約メタキー（サービスメニューID）。
	 *
	 * @var string
	 */
	private const META_SERVICE_ID = '_vkbm_booking_service_id';

	/**
	 * 予約メタキー（人数）。
	 *
	 * @var string
	 */
	private const META_GUESTS = '_vkbm_booking_guests';

	/**
	 * 予約メタキー（顧客名）。
	 *
	 * @var string
	 */
	private const META_CUSTOMER = '_vkbm_booking_customer_name';

	/**
	 * 予約メタキー（顧客メールアドレス）。
	 *
	 * @var string
	 */
	private const META_CUSTOMER_MAIL = '_vkbm_booking_customer_email';

	/**
	 * 予約メタキー（顧客電話番号）。
	 *
	 * @var string
	 */
	private const META_CUSTOMER_TEL = '_vkbm_booking_customer_tel';

	/**
	 * 予約メタキー（お客様からのメモ・ご要望）。
	 *
	 * issue #476（Google カレンダー連携）で、予定の説明欄に載せられるようスナップショットへ
	 * 追加した（司の decision record 参照。管理用メモ `_vkbm_booking_internal_note` は
	 * 含めない。店舗スタッフだけが書く内部メモのため、外部連携に一切渡さない設計にしている）。
	 *
	 * @var string
	 */
	private const META_NOTE = '_vkbm_booking_note';

	/**
	 * 予約メタキー（予約ステータス：confirmed/pending/cancelled/no_show）。
	 *
	 * @var string
	 */
	private const META_STATUS = '_vkbm_booking_status';

	/**
	 * このリクエスト内で既に発火済みのシグネチャ（booking_id・種別・内容から組み立てる）。
	 * 入れ子の保存で同じ最終状態を2回発火しようとした場合に、2回目をスキップするために使う。
	 *
	 * @var array<string, bool>
	 */
	private array $dispatched_signatures = array();

	/**
	 * WordPress のフックを登録する。
	 *
	 * ゴミ箱移動・復元・完全削除は Booking_Admin 等の呼び出し元を経由せず、WordPress 本体の
	 * ライフサイクルフックに直接乗る（issue 本文・司の decision record 参照。空き枠キャッシュ更新
	 * （Availability_Booking_Cache_Generation）と同じ場所を使う）。
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'trashed_post', array( $this, 'handle_trashed_post' ) );
		add_action( 'untrashed_post', array( $this, 'handle_untrashed_post' ) );
		// #474 レビュー対応（実機での PHPUnit 失敗で発覚）: 当初は 'delete_post' を購読していたが、
		// wp_delete_post() の実装を実機で確認したところ（wp-includes/post.php）、postmeta の削除
		// （$post_meta_ids をループして delete_metadata_by_mid() を呼ぶ処理）が 'delete_post' の
		// do_action() より前に完了しており、'delete_post' の時点では既にメタが読めなかった
		// （本番の WordPress バージョンでは古い順序だった可能性があり、バージョン依存の実装詳細に
		// 賭けるべきではない）。'before_delete_post' は wp_delete_post() の先頭、他の削除処理が
		// 一切始まる前に発火するため、バージョンに関わらず確実にメタが残っている。
		add_action( 'before_delete_post', array( $this, 'handle_before_delete_post' ), 10, 2 );
	}

	/**
	 * 呼び出し元が「保存前の内容」を取得するためのヘルパー。
	 *
	 * 呼び出し元は、自分自身のメタ書き込みより前にこのメソッドを呼んでスナップショットを
	 * 確保し、書き込み完了後に dispatch_change() へ渡す。
	 *
	 * 投稿が存在しない、対象の投稿タイプでない、auto-draft（WordPress が「新規追加」画面を
	 * 開いた時点で先に作る空の投稿行）、またはステータスメタが一度も書き込まれていない場合は
	 * null を返す。null は「実質的にまだ存在しない予約」を意味し、dispatch_change() 側で
	 * created 判定に使う。
	 *
	 * @param int $booking_id 予約投稿ID。
	 * @return array<string, mixed>|null
	 */
	public function capture_snapshot( int $booking_id ): ?array {
		$post = get_post( $booking_id );

		if ( ! $post instanceof WP_Post || Booking_Post_Type::POST_TYPE !== $post->post_type ) {
			return null;
		}

		if ( 'auto-draft' === $post->post_status ) {
			return null;
		}

		// ステータスメタが一度も書き込まれていない＝この予約はまだ実質的に作成されていない
		// （管理画面の「新規追加」は auto-draft の投稿行が先に作られるため、post_status だけでは
		// 新規作成を判定できない。クラス doc コメント参照）。
		if ( ! metadata_exists( 'post', $booking_id, self::META_STATUS ) ) {
			return null;
		}

		return $this->build_snapshot( $booking_id, $post );
	}

	/**
	 * 予約の状態変化を判定し、変化があれば公開フックを発火する。
	 *
	 * 呼び出し元は、自分自身の保存処理が完了した後にこのメソッドを呼ぶ。$before には
	 * capture_snapshot() で事前に取得したスナップショット（新規作成の場合は null）を渡す。
	 * 「変更後」の内容はこのメソッドが呼び出し時点の DB から読み直す（呼び出し元が保存を
	 * 終えている前提）。
	 *
	 * @param int                       $booking_id 予約投稿ID。
	 * @param array<string, mixed>|null $before    保存前のスナップショット（新規作成は null）。
	 * @return void
	 */
	public function dispatch_change( int $booking_id, ?array $before ): void {
		$post = get_post( $booking_id );

		if ( ! $post instanceof WP_Post || Booking_Post_Type::POST_TYPE !== $post->post_type ) {
			return;
		}

		if ( 'auto-draft' === $post->post_status ) {
			return;
		}

		$after = $this->build_snapshot( $booking_id, $post );

		if ( null === $before ) {
			// 保存前のスナップショットが無い＝この予約は今回のリクエストで実質的に
			// 新規作成された（capture_snapshot() の判定基準参照）。
			$this->maybe_dispatch( self::EVENT_CREATED, $booking_id, null, $after, array_keys( $after ) );
			return;
		}

		$changed = $this->diff_snapshot( $before, $after );

		if ( array() === $changed ) {
			// 変更なしの再保存（例: 内容を何も変えずに保存ボタンを押した）は発火しない。
			return;
		}

		$before_status = (string) ( $before['status'] ?? '' );
		$after_status  = (string) ( $after['status'] ?? '' );

		$event = $this->determine_event( $before_status, $after_status );

		$this->maybe_dispatch( $event, $booking_id, $before, $after, $changed );
	}

	/**
	 * ゴミ箱移動をハンドルする（trashed_post）。
	 *
	 * @param int $post_id 投稿ID。
	 * @return void
	 */
	public function handle_trashed_post( int $post_id ): void {
		$this->dispatch_lifecycle_event( self::EVENT_TRASHED, $post_id );
	}

	/**
	 * ゴミ箱からの復元をハンドルする（untrashed_post）。
	 *
	 * @param int $post_id 投稿ID。
	 * @return void
	 */
	public function handle_untrashed_post( int $post_id ): void {
		$this->dispatch_lifecycle_event( self::EVENT_RESTORED, $post_id );
	}

	/**
	 * 完全削除をハンドルする（before_delete_post）。
	 *
	 * before_delete_post は wp_delete_post() の先頭、他の削除処理（postmeta の削除・
	 * タクソノミー関連の解除等）が一切始まる前に発火する。register() のコメントのとおり、
	 * 'delete_post' は WordPress のバージョンによっては postmeta の削除が既に終わった後に
	 * 発火するため使わない。ここでスナップショットを取っておかないと、完全削除後は
	 * 予約の中身を二度と読めなくなる（Availability_Booking_Cache_Generation と同じ理由）。
	 *
	 * @param int          $post_id 投稿ID。
	 * @param WP_Post|null $post    投稿オブジェクト。
	 * @return void
	 */
	public function handle_before_delete_post( int $post_id, ?WP_Post $post = null ): void {
		if ( ! $post instanceof WP_Post ) {
			$post = get_post( $post_id );
		}

		if ( ! $post instanceof WP_Post || Booking_Post_Type::POST_TYPE !== $post->post_type ) {
			return;
		}

		if ( 'auto-draft' === $post->post_status ) {
			return;
		}

		$snapshot = $this->build_snapshot( $post_id, $post );

		$this->maybe_dispatch( self::EVENT_DELETED, $post_id, $snapshot, $snapshot, array() );
	}

	/**
	 * ゴミ箱移動・復元の共通処理。
	 *
	 * @param string $event   発火する種別（trashed/restored）。
	 * @param int    $post_id 投稿ID。
	 * @return void
	 */
	private function dispatch_lifecycle_event( string $event, int $post_id ): void {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || Booking_Post_Type::POST_TYPE !== $post->post_type ) {
			return;
		}

		$snapshot = $this->build_snapshot( $post_id, $post );

		$this->maybe_dispatch( $event, $post_id, $snapshot, $snapshot, array() );
	}

	/**
	 * ステータスの変更前後から種別を判定する。
	 *
	 * confirmed・cancelled への遷移だけを特別扱いし、それ以外（no_show への変更・
	 * confirmed→pending の巻き戻し等）は updated に倒す（司の decision record 参照）。
	 *
	 * @param string $before_status 変更前のステータス。
	 * @param string $after_status  変更後のステータス。
	 * @return string
	 */
	private function determine_event( string $before_status, string $after_status ): string {
		if ( self::STATUS_CONFIRMED !== $before_status && self::STATUS_CONFIRMED === $after_status ) {
			return self::EVENT_CONFIRMED;
		}

		if ( self::STATUS_CANCELLED !== $before_status && self::STATUS_CANCELLED === $after_status ) {
			return self::EVENT_CANCELLED;
		}

		return self::EVENT_UPDATED;
	}

	/**
	 * 重複排除のうえで公開フックを発火する。
	 *
	 * @param string                    $event      種別。
	 * @param int                       $booking_id 予約投稿ID。
	 * @param array<string, mixed>|null $before     変更前スナップショット（無ければ null）。
	 * @param array<string, mixed>      $after      変更後スナップショット。
	 * @param array<int, string>        $changed    変わったキーの一覧。
	 * @return void
	 */
	private function maybe_dispatch( string $event, int $booking_id, ?array $before, array $after, array $changed ): void {
		$payload = array(
			'booking_id'      => $booking_id,
			'event'           => $event,
			'status'          => (string) ( $after['status'] ?? '' ),
			'previous_status' => null !== $before ? (string) ( $before['status'] ?? '' ) : null,
			'changed'         => array_values( $changed ),
			'booking'         => $after,
		);

		// booking_id・種別・内容からシグネチャを組み立てる。当初は入れ子の保存（Booking_Admin::
		// save_post が wp_update_post() 経由で自分自身を再発火させるケース。クラス doc コメント
		// 参照）でこのシグネチャ照合が2回目をスキップする想定だったが、issue #477 の再入ガードが
		// 入れ子側の呼び出しを save_post_inner() へ到達する前に止めるため、現在この経路ではこの
		// 照合は働いていない。将来の別の呼び出し元追加に備えた防御として残してある。日時等の
		// 非決定的な値は payload に含めていないため、シグネチャは同一リクエスト内で安定する。
		//
		// #474 レビュー対応（安藤レビュー指摘・再レビュー1対応）: シグネチャの組み立てに
		// wp_json_encode() ではなく serialize() を使う。当初は wp_json_encode() が不正な UTF-8
		// （文字化けした顧客名等）で false を返す前提で is_string() ガード＋wp_rand() フォールバックを
		// 入れていたが、実機の WordPress 本体（wp-includes/functions.php）で確認したところ、
		// wp_json_encode() は json_encode() が失敗すると _wp_json_sanity_check() が不正バイトを
		// サニタイズ（例: "\x80" → "?"）してから再エンコードするため、実際には false を返さない。
		// そのためこのガードは効いたことのない分岐だっただけでなく、サニタイズで別々の不正バイト列が
		// 同じ文字列に潰れて衝突する（"A\x80" と "A\x81" がどちらも "A?" になる）本当の穴を
		// 塞げていなかった。serialize() はバイト列をそのまま扱い、スカラーと配列だけの本ペイロードで
		// 失敗しないため、false 分岐そのものが不要になり、この穴も塞がる。
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- unserialize() は使わない。md5() でハッシュ化して重複排除にのみ使う一時的な文字列生成で、永続化・外部送信・unserialize() での復元は行わない。
		$signature = $booking_id . '|' . $event . '|' . md5( serialize( $payload ) );

		if ( isset( $this->dispatched_signatures[ $signature ] ) ) {
			return;
		}

		$this->dispatched_signatures[ $signature ] = true;

		/**
		 * 予約の状態が変わった後に発火する（外部連携用）。
		 *
		 * このフックが叩かれた時点で、予約の状態変化（作成・確定・変更・キャンセル・
		 * ゴミ箱移動・復元・完全削除）は既に確定している。Google カレンダー連携等の
		 * 外部サービスとの同期は、このフックの購読側（後続の #475・#476）が担う。
		 * このディスパッチャー自身は Google 等への通信も永続化も行わない。
		 *
		 * @param string             $event      種別（created/confirmed/updated/cancelled/trashed/restored/deleted）。
		 * @param int                $booking_id 予約投稿ID。
		 * @param array<string, mixed> $payload  予約の状態・変化内容・スナップショット（本クラスの doc 参照）。
		 *                                        $payload['booking'] に顧客の氏名・メールアドレス・
		 *                                        電話番号を含む。`all` フック等でログ出力する購読側を
		 *                                        入れると個人情報がそのままログファイルへ書き出される
		 *                                        ため、購読側の実装でログへ出力しないこと
		 *                                        （安藤レビュー指摘L-1対応 #474）。
		 *                                        do_action() は同期実行で、予約完了画面を返す前に
		 *                                        走る。購読側で重い処理（外部 API 呼び出し等）を
		 *                                        直接行うと、その遅延がそのままお客様の待ち時間になる。
		 *                                        重い処理は shutdown フックや
		 *                                        wp_schedule_single_event() へ逃がすこと
		 *                                        （安藤レビュー指摘L-3対応 #474）。
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- self::HOOK_NAME は 'vkbm_booking_state_changed' の固定文字列（クラス定数）で、プレフィックス済み。
		do_action( self::HOOK_NAME, $event, $booking_id, $payload );
	}

	/**
	 * 予約の現在のスナップショットを組み立てる。
	 *
	 * @param int     $booking_id 予約投稿ID。
	 * @param WP_Post $post       投稿オブジェクト。
	 * @return array<string, mixed>
	 */
	private function build_snapshot( int $booking_id, WP_Post $post ): array {
		return array(
			'start'          => (string) get_post_meta( $booking_id, self::META_DATE_START, true ),
			'end'            => (string) get_post_meta( $booking_id, self::META_DATE_END, true ),
			'total_end'      => (string) get_post_meta( $booking_id, self::META_TOTAL_END, true ),
			'resource_id'    => (int) get_post_meta( $booking_id, self::META_RESOURCE_ID, true ),
			'service_id'     => (int) get_post_meta( $booking_id, self::META_SERVICE_ID, true ),
			'guests'         => (int) get_post_meta( $booking_id, self::META_GUESTS, true ),
			'customer_name'  => (string) get_post_meta( $booking_id, self::META_CUSTOMER, true ),
			'customer_email' => (string) get_post_meta( $booking_id, self::META_CUSTOMER_MAIL, true ),
			'customer_tel'   => (string) get_post_meta( $booking_id, self::META_CUSTOMER_TEL, true ),
			'note'           => (string) get_post_meta( $booking_id, self::META_NOTE, true ),
			'status'         => (string) get_post_meta( $booking_id, self::META_STATUS, true ),
			'post_status'    => (string) $post->post_status,
		);
	}

	/**
	 * 変更前後のスナップショットを比較し、変わったキーの一覧を返す。
	 *
	 * @param array<string, mixed> $before 変更前スナップショット。
	 * @param array<string, mixed> $after  変更後スナップショット。
	 * @return array<int, string>
	 */
	private function diff_snapshot( array $before, array $after ): array {
		$changed = array();

		foreach ( $after as $key => $value ) {
			$before_value = $before[ $key ] ?? null;

			if ( $before_value !== $value ) {
				$changed[] = $key;
			}
		}

		return $changed;
	}
}
