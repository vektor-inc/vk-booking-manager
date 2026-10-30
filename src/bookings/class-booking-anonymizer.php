<?php
/**
 * 予約の個人情報を匿名化する処理（issue #870）。
 *
 * 予約の日時・メニュー・担当・人数・料金・ステータスは残したまま、お客様を特定できる情報
 * （氏名・メールアドレス・電話番号・お客様メモ・運営側メモ・予約者との紐付け・添付ファイル）だけを消す。
 * 管理画面の一括操作（{@see Booking_Anonymize_Bulk_Action}）のほか、今後の「WordPress 本体の
 * 個人データの消去」「終了から一定期間が経った予約の自動匿名化」もこのクラスをそのまま呼ぶ。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Bookings;

use DateTimeImmutable;
use VKBookingManager\Integrations\Booking_Event_Dispatcher;
use VKBookingManager\PostTypes\Booking_Post_Type;
use WP_Post;
use function __;
use function add_filter;
use function apply_filters;
use function delete_post_meta;
use function is_wp_error;
use function current_datetime;
use function do_action;
use function get_post;
use function get_post_stati;
use function get_posts;
use function get_post_meta;
use function gmdate;
use function is_array;
use function is_scalar;
use function is_string;
use function sprintf;
use function update_post_meta;
use function wp_delete_attachment;
use function wp_get_list_item_separator;
use function wp_timezone;
use function wp_update_post;

/**
 * 予約の個人情報を匿名化する。
 */
class Booking_Anonymizer {

	/**
	 * 匿名化済みの印（匿名化した日時。UTC の `Y-m-d H:i:s`）を保存するメタキー。
	 */
	public const META_ANONYMIZED_AT = '_vkbm_booking_anonymized_at';

	/**
	 * 匿名化を実行したきっかけを保存するメタキー。
	 */
	public const META_ANONYMIZED_BY = '_vkbm_booking_anonymized_by';

	/** 予約一覧の一括操作による匿名化。 */
	public const ANONYMIZED_BY_BULK = 'bulk';

	/** WordPress 本体の個人データ消去依頼による匿名化。 */
	public const ANONYMIZED_BY_PRIVACY_REQUEST = 'privacy_request';

	/** 自動匿名化の設定による匿名化。 */
	public const ANONYMIZED_BY_AUTO = 'auto';

	/**
	 * 匿名化できた。
	 */
	public const RESULT_ANONYMIZED = 'anonymized';

	/**
	 * 終了日時を過ぎていないため匿名化しなかった。
	 */
	public const RESULT_NOT_ENDED = 'not_ended';

	/**
	 * 日時が未設定のため匿名化しなかった。
	 */
	public const RESULT_NO_DATETIME = 'no_datetime';

	/**
	 * すでに匿名化済みのため処理しなかった。
	 */
	public const RESULT_ALREADY_ANONYMIZED = 'already_anonymized';

	/**
	 * 保存に失敗したため匿名化できなかった（匿名化済みの印は付けていないので、再実行できる）。
	 */
	public const RESULT_FAILED = 'failed';

	/**
	 * 予約の投稿ではないため処理しなかった。
	 */
	public const RESULT_INVALID = 'invalid';

	/**
	 * 予約に添付したファイルの ID（配列）を保存するメタキー。{@see Booking_Admin} の添付欄と同じ値。
	 */
	private const META_ATTACHMENT_IDS = '_vkbm_booking_attachment_ids';

	/**
	 * 予約の編集画面からアップロードしたファイルに付く目印のメタキー。{@see Booking_Admin} が付ける。
	 */
	private const META_UPLOADED_ATTACHMENT = '_vkbm_booking_uploaded_attachment';

	private const META_SERVICE_START = '_vkbm_booking_service_start';
	private const META_SERVICE_END   = '_vkbm_booking_service_end';
	private const META_TOTAL_END     = '_vkbm_booking_total_end';

	/**
	 * 匿名化のあとに外部連携（Google カレンダーなど）へ変更を知らせるディスパッチャー。
	 *
	 * @var Booking_Event_Dispatcher
	 */
	private $event_dispatcher;

	/**
	 * Constructor.
	 *
	 * @param Booking_Event_Dispatcher|null $event_dispatcher 予約の状態変化を外部連携へ橋渡しするディスパッチャー。
	 */
	public function __construct( ?Booking_Event_Dispatcher $event_dispatcher = null ) {
		$this->event_dispatcher = $event_dispatcher ?? new Booking_Event_Dispatcher();
	}

	/**
	 * 匿名化済みの予約の投稿者・タイトル・スラッグを、WordPress 本体の保存で書き換えさせないフックを登録する。
	 */
	public static function register(): void {
		add_filter( 'wp_insert_post_data', array( self::class, 'keep_anonymized_post_data' ), 10, 2 );
	}

	/**
	 * 匿名化済みの予約を保存するとき、投稿者を 0、タイトルとスラッグを保存済みの値に保つ。
	 *
	 * WordPress 本体の保存（編集画面・クイック編集・一括編集・wp_update_post）は、投稿者 0 を空とみなして
	 * 保存した人の ID に置き換えたり、送られたタイトル・スラッグで上書きしたりするため、DB に書く直前で止める。
	 * 匿名化済みの印は匿名化の最後に付けるので、匿名化処理自身の更新はこのフィルターの対象にならない。
	 *
	 * @param array<string, mixed> $data    DB に書き込む投稿データ。
	 * @param array<string, mixed> $postarr 保存を依頼された元のデータ。
	 * @return array<string, mixed>
	 */
	public static function keep_anonymized_post_data( $data, $postarr ) {
		if ( ! is_array( $data ) || ! is_array( $postarr ) || Booking_Post_Type::POST_TYPE !== ( $data['post_type'] ?? '' ) ) {
			return $data;
		}

		$booking_id = (int) ( $postarr['ID'] ?? 0 );
		$existing   = $booking_id > 0 ? get_post( $booking_id ) : null;
		if ( ! $existing instanceof WP_Post || ! self::is_anonymized( $booking_id ) ) {
			return $data;
		}

		$data['post_author'] = 0;
		$data['post_title']  = $existing->post_title;
		$data['post_name']   = $existing->post_name;

		return $data;
	}

	/**
	 * 匿名化の対象にする予約メタの一覧（メタキー => 欄名と置き換える値）を返す。
	 *
	 * 対象項目の定義はここ1か所だけに置く。確認ダイアログ・編集画面の注記に並べる欄名もこの一覧から作る。
	 * 項目を足すときはフィルター `vkbm_booking_anonymize_meta_fields` で1行足す。
	 *
	 * @return array<string, array{label: string, value: string}> メタキーをキーにした、欄名（label）と置き換える値（value）。
	 */
	public static function get_meta_fields(): array {
		$fields = array(
			'_vkbm_booking_customer_name'           => array(
				'label' => __( 'customer name', 'vk-booking-manager' ),
				'value' => self::get_deleted_label(),
			),
			'_vkbm_booking_attendee_name'           => array(
				'label' => __( 'name of the person receiving the service', 'vk-booking-manager' ),
				'value' => '',
			),
			'_vkbm_booking_attendee_id'             => array(
				'label' => __( 'ID of the person receiving the service', 'vk-booking-manager' ),
				'value' => '',
			),
			'_vkbm_booking_attendee_number'         => array(
				'label' => __( 'number of the person receiving the service', 'vk-booking-manager' ),
				'value' => '',
			),
			'_vkbm_booking_customer_tel'            => array(
				'label' => __( 'telephone number', 'vk-booking-manager' ),
				'value' => '',
			),
			'_vkbm_booking_customer_email'          => array(
				'label' => __( 'email address', 'vk-booking-manager' ),
				'value' => '',
			),
			'_vkbm_booking_note'                    => array(
				'label' => __( 'memo', 'vk-booking-manager' ),
				'value' => '',
			),
			'_vkbm_booking_internal_note'           => array(
				'label' => __( 'Management memo', 'vk-booking-manager' ),
				'value' => '',
			),
			// ゲスト予約で、メールの持ち主が確認リンクを開いた日時。確認した本人の行動の記録のため匿名化の対象に含める。
			'_vkbm_booking_guest_verified_at'       => array(
				'label' => __( 'email confirmation date and time', 'vk-booking-manager' ),
				'value' => '',
			),
			// ゲスト予約のキャンセル用リンクのトークンのハッシュ。匿名化した予約は、リンクからキャンセルできないようにする。
			'_vkbm_booking_guest_cancel_token_hash' => array(
				'label' => __( 'cancellation link token', 'vk-booking-manager' ),
				'value' => '',
			),
		);

		/**
		 * 匿名化の対象にする予約メタの一覧を絞り込む。
		 *
		 * 住所など、予約に個人情報を保存する機能を足すときは、ここへメタキーを足す。
		 *
		 * @param array<string, array{label: string, value: string}> $fields メタキーをキーにした、欄名（label）と置き換える値（value）。
		 */
		$filtered = apply_filters( 'vkbm_booking_anonymize_meta_fields', $fields );

		return self::normalize_meta_fields( is_array( $filtered ) ? $filtered : $fields );
	}

	/**
	 * 匿名化の対象にする欄名の一覧を返す。
	 *
	 * @return string[] 欄名の一覧（{@see self::get_meta_fields()} の順）。
	 */
	public static function get_field_labels(): array {
		$labels = array();
		foreach ( self::get_meta_fields() as $field ) {
			$labels[] = $field['label'];
		}

		return $labels;
	}

	/**
	 * 匿名化の対象にする欄名を、区切り文字でつないだ1つの文字列にして返す。
	 *
	 * @return string 例: 「顧客名、電話番号、メールアドレス、メモ、運営側メモ」。
	 */
	public static function get_field_labels_text(): string {
		return implode( wp_get_list_item_separator(), self::get_field_labels() );
	}

	/**
	 * 氏名とタイトルの名前部分に入れる文言を返す。
	 *
	 * @return string 「（削除済み）」。
	 */
	public static function get_deleted_label(): string {
		return __( '(Deleted)', 'vk-booking-manager' );
	}

	/**
	 * 予約が匿名化済みか判定する。
	 *
	 * @param int $booking_id 予約の投稿ID。
	 * @return bool 匿名化済みなら true。
	 */
	public static function is_anonymized( int $booking_id ): bool {
		return '' !== self::get_anonymized_at( $booking_id );
	}

	/**
	 * 匿名化した日時（UTC の `Y-m-d H:i:s`）を返す。
	 *
	 * @param int $booking_id 予約の投稿ID。
	 * @return string 匿名化した日時。匿名化していなければ空文字。
	 */
	public static function get_anonymized_at( int $booking_id ): string {
		return (string) get_post_meta( $booking_id, self::META_ANONYMIZED_AT, true );
	}

	/**
	 * 匿名化を実行したきっかけを返す。
	 *
	 * @param int $booking_id 予約の投稿ID。
	 * @return string `bulk`、`privacy_request`、`auto`、または記録が無い場合の空文字。
	 */
	public static function get_anonymized_by( int $booking_id ): string {
		$value = (string) get_post_meta( $booking_id, self::META_ANONYMIZED_BY, true );

		return in_array( $value, array( self::ANONYMIZED_BY_BULK, self::ANONYMIZED_BY_PRIVACY_REQUEST, self::ANONYMIZED_BY_AUTO ), true ) ? $value : '';
	}

	/**
	 * 予約の終了日時を返す。
	 *
	 * 後片付けの時間まで含めた終了日時（total_end）→ 終了日時（service_end）→ 開始日時（service_start）の
	 * 順に、最初に取れた値を使う。
	 *
	 * @param int $booking_id 予約の投稿ID。
	 * @return DateTimeImmutable|null 終了日時（サイトのタイムゾーン）。どれも取れなければ null。
	 */
	public static function get_end_datetime( int $booking_id ): ?DateTimeImmutable {
		foreach ( array( self::META_TOTAL_END, self::META_SERVICE_END, self::META_SERVICE_START ) as $meta_key ) {
			$value = (string) get_post_meta( $booking_id, $meta_key, true );
			if ( '' === $value ) {
				continue;
			}

			$datetime = self::parse_datetime( $value );
			if ( null !== $datetime ) {
				return $datetime;
			}

			// 値がある候補を読み飛ばすと、より古い日時で終了済みと誤判定するため、判定不能にする。
			return null;
		}

		return null;
	}

	/**
	 * 予約を今匿名化できるか判定する（予約は変更しない）。
	 *
	 * 判定は終了日時だけで行い、予約ステータスは見ない。
	 *
	 * @param int $booking_id 予約の投稿ID。
	 * @return string `RESULT_*` のいずれか。匿名化できるときは {@see self::RESULT_ANONYMIZED}。
	 */
	public function check_anonymizable( int $booking_id ): string {
		$post = get_post( $booking_id );
		if ( ! $post instanceof WP_Post || Booking_Post_Type::POST_TYPE !== $post->post_type ) {
			return self::RESULT_INVALID;
		}

		if ( self::is_anonymized( $booking_id ) ) {
			return self::RESULT_ALREADY_ANONYMIZED;
		}

		$end = self::get_end_datetime( $booking_id );
		if ( null === $end ) {
			return self::RESULT_NO_DATETIME;
		}

		// 終了日時を過ぎた予約だけが対象。ちょうど終了日時の時刻は「まだ過ぎていない」とみなす。
		if ( $end >= current_datetime() ) {
			return self::RESULT_NOT_ENDED;
		}

		return self::RESULT_ANONYMIZED;
	}

	/**
	 * 予約の個人情報を匿名化する。
	 *
	 * @param int    $booking_id    予約の投稿ID。
	 * @param string $anonymized_by 匿名化を実行したきっかけ。
	 * @return string `RESULT_*` のいずれか。匿名化したときは {@see self::RESULT_ANONYMIZED}。
	 */
	public function anonymize( int $booking_id, string $anonymized_by = self::ANONYMIZED_BY_BULK ): string {
		$result = $this->check_anonymizable( $booking_id );
		if ( self::RESULT_ANONYMIZED !== $result ) {
			return $result;
		}

		// 外部連携へ「変更前の内容」として渡すため、メタを書き換える前にスナップショットを取る。
		$snapshot = $this->event_dispatcher->capture_snapshot( $booking_id );

		// 個人情報のメタを置き換える。空にする項目も、メタ自体は残して空文字にする。
		$meta_fields = self::get_meta_fields();
		foreach ( $meta_fields as $meta_key => $field ) {
			// キャンセル用リンクのトークンのハッシュは、空の行を残さず行ごと削除する（会員の予約に空の行を増やさない）。
			if ( Booking_Source::META_GUEST_CANCEL_TOKEN_HASH === $meta_key ) {
				Guest_Cancel_Controller::clear_tokens( $booking_id );
				continue;
			}
			update_post_meta( $booking_id, $meta_key, $field['value'] );
		}

		// タイトル・スラッグ（名前入りの自動生成）・投稿者（予約したお客様のユーザー）を作り直す。
		// post_name を空にすると、WordPress が新しいタイトルから作り直す。
		$title       = $this->build_title( $booking_id );
		$update_post = wp_update_post(
			array(
				'ID'          => $booking_id,
				'post_title'  => $title,
				'post_name'   => '',
				'post_author' => 0,
			),
			true
		);

		// スラッグを変えると、WordPress が古いスラッグ（名前入り）を _wp_old_slug に、
		// ゴミ箱の予約では元のスラッグを _wp_desired_post_slug に残すため、どちらも消す。
		delete_post_meta( $booking_id, '_wp_old_slug' );
		delete_post_meta( $booking_id, '_wp_desired_post_slug' );

		// 書き込みの結果を確かめる。1つでも終わっていなければ、印を付けずに失敗として返す（再実行できる）。
		if ( is_wp_error( $update_post ) || ! $this->is_written( $booking_id, $meta_fields, $title ) ) {
			return self::RESULT_FAILED;
		}

		// 添付ファイルを片付ける。印を付ける前に行い、失敗したときは再実行できるようにする。
		if ( ! $this->remove_attachments( $booking_id ) ) {
			return self::RESULT_FAILED;
		}

		// 許可した値だけを保存し、既存の呼び出し元と不正値は一括操作として記録する。
		if ( ! in_array( $anonymized_by, array( self::ANONYMIZED_BY_BULK, self::ANONYMIZED_BY_PRIVACY_REQUEST, self::ANONYMIZED_BY_AUTO ), true ) ) {
			$anonymized_by = self::ANONYMIZED_BY_BULK;
		}
		update_post_meta( $booking_id, self::META_ANONYMIZED_BY, $anonymized_by );
		if ( self::get_anonymized_by( $booking_id ) !== $anonymized_by ) {
			return self::RESULT_FAILED;
		}

		// 匿名化済みの印は最後に付ける（途中で失敗したときに、印だけ付いて中身が残る状態を避ける）。
		$anonymized_at = gmdate( 'Y-m-d H:i:s' );
		update_post_meta( $booking_id, self::META_ANONYMIZED_AT, $anonymized_at );

		// 印が保存されていなければ、保存ガードが働かず後から個人情報を入れ直せてしまうため失敗として返す（再実行できる）。
		if ( self::get_anonymized_at( $booking_id ) !== $anonymized_at ) {
			return self::RESULT_FAILED;
		}

		// Google カレンダーの予定など、外部に出ている情報を匿名化した値で上書きさせる。
		// 通知メールは予約ステータスの変化で送る仕組みのため、ステータスを変えないここでは送られない。
		// 予約としてまだ作成されていない（スナップショットが無い）場合は、作成イベントを誤って出さないため呼ばない。
		// 前回の実行が途中で失敗していると、今回のスナップショットは既に匿名化された値になり差分が出ないため、
		// 差分が無くても必ず「変更」として通知する。
		if ( null !== $snapshot ) {
			$this->event_dispatcher->dispatch_change( $booking_id, $snapshot, true );
		}

		/**
		 * 予約の個人情報を匿名化したあとに発火する。
		 *
		 * 添付ファイルの片付けは、このアクションの前に済んでいる。匿名化に続けて行う他の処理はここに乗せる。
		 *
		 * @param int $booking_id 予約の投稿ID。
		 */
		do_action( 'vkbm_booking_anonymized', $booking_id );

		return self::RESULT_ANONYMIZED;
	}

	/**
	 * 予約に添付したファイルを片付け、予約との紐付けを外す。
	 *
	 * 予約の編集画面からアップロードしたファイル（目印が付いたもの）は、メディアライブラリとサーバー上から削除する。
	 * メディアライブラリにもともとあったファイルを選んだだけのもの（目印が無いもの）は、
	 * サイトの他の場所で使われている可能性があるため削除せず、紐付けだけを外す。
	 * 目印が付いていても、他の予約が同じファイルを添付している場合は、その予約のために削除せず紐付けだけを外す。
	 * すでにメディアライブラリに無いファイルは、片付け済みとして扱う（失敗後の再実行で止まらないようにするため）。
	 *
	 * @param int $booking_id 予約の投稿ID。
	 * @return bool 片付けが終わり、紐付けも外れていれば true。削除できないファイルがあるか、紐付けが残っていれば false。
	 */
	private function remove_attachments( int $booking_id ): bool {
		$attachment_ids = self::normalize_attachment_ids( get_post_meta( $booking_id, self::META_ATTACHMENT_IDS, true ) );

		$all_removed = true;
		foreach ( $attachment_ids as $attachment_id ) {
			$attachment = get_post( $attachment_id );
			if ( ! $attachment instanceof WP_Post || 'attachment' !== $attachment->post_type ) {
				continue;
			}

			// 目印が無い、または他の予約も添付しているファイルは削除しない。
			if ( '' === (string) get_post_meta( $attachment_id, self::META_UPLOADED_ATTACHMENT, true )
				|| $this->is_attached_to_other_booking( $attachment_id, $booking_id ) ) {
				continue;
			}

			// 削除が止められた場合（フィルターなど）や、削除後も残っている場合は失敗として扱う。
			wp_delete_attachment( $attachment_id, true );
			if ( get_post( $attachment_id ) instanceof WP_Post ) {
				$all_removed = false;
			}
		}

		// 削除できないファイルがあるときは紐付けを残す（再実行で、残ったファイルだけをもう一度試す）。
		if ( ! $all_removed ) {
			return false;
		}

		delete_post_meta( $booking_id, self::META_ATTACHMENT_IDS );

		return array() === get_post_meta( $booking_id, self::META_ATTACHMENT_IDS, false );
	}

	/**
	 * 指定のファイルを、指定の予約以外の予約も添付しているか判定する。
	 *
	 * 添付ファイルの ID は配列として保存されている。保存形式（シリアライズ）の文字列検索では、
	 * 配列の添字（0, 1, 2...）が ID と同じ値のときにも一致するため、検索は候補の絞り込みだけに使い、
	 * 候補の予約ごとに保存されている配列を読み直して ID を照合する。
	 *
	 * @param int $attachment_id      添付ファイルの投稿ID。
	 * @param int $exclude_booking_id 調べる対象から外す予約の投稿ID。
	 * @return bool 他の予約も添付していれば true。
	 */
	private function is_attached_to_other_booking( int $attachment_id, int $exclude_booking_id ): bool {
		$candidate_ids = get_posts(
			array(
				'post_type'        => Booking_Post_Type::POST_TYPE,
				'post_status'      => array_values( get_post_stati() ),
				'post__not_in'     => array( $exclude_booking_id ),
				'numberposts'      => -1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => self::META_ATTACHMENT_IDS,
						'value'   => ';i:' . $attachment_id . ';',
						'compare' => 'LIKE',
					),
				),
			)
		);

		foreach ( $candidate_ids as $candidate_id ) {
			$ids = self::normalize_attachment_ids( get_post_meta( (int) $candidate_id, self::META_ATTACHMENT_IDS, true ) );
			if ( in_array( $attachment_id, $ids, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 保存されている添付ファイルの ID を、正の整数の配列にそろえる。
	 *
	 * @param mixed $raw 保存されている値。
	 * @return int[] 重複を除いた添付ファイルの投稿ID。
	 */
	private static function normalize_attachment_ids( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$ids = array();
		foreach ( $raw as $value ) {
			$id = is_scalar( $value ) ? (int) $value : 0;
			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		return array_values( $ids );
	}

	/**
	 * 匿名化の書き込みが、保存後の値で期待どおりになっているか確かめる。
	 *
	 * update_post_meta() は値が同じときも false を返すため、戻り値ではなく保存後の値で判定する。
	 *
	 * @param int                                                $booking_id  予約の投稿ID。
	 * @param array<string, array{label: string, value: string}> $meta_fields 置き換えた項目。
	 * @param string                                             $title       期待するタイトル。
	 * @return bool すべて期待どおりなら true。
	 */
	private function is_written( int $booking_id, array $meta_fields, string $title ): bool {
		foreach ( $meta_fields as $meta_key => $field ) {
			$saved_value = (string) get_post_meta( $booking_id, $meta_key, true );
			if ( $saved_value !== $field['value'] ) {
				return false;
			}
		}

		$post = get_post( $booking_id );
		if ( ! $post instanceof WP_Post || 0 !== (int) $post->post_author || $title !== $post->post_title ) {
			return false;
		}

		return array() === get_post_meta( $booking_id, '_wp_old_slug', false )
			&& array() === get_post_meta( $booking_id, '_wp_desired_post_slug', false );
	}

	/**
	 * 匿名化後のタイトルを組み立てる。
	 *
	 * 現在のタイトルは分解せず、開始日時から作り直す（管理者が手で書き換えていることがあるため）。
	 *
	 * @param int $booking_id 予約の投稿ID。
	 * @return string 「（削除済み） / 2026.10.1 10:00」の形。開始日時が無ければ「（削除済み）」だけ。
	 */
	private function build_title( int $booking_id ): string {
		$title = self::get_deleted_label();
		$start = self::parse_datetime( (string) get_post_meta( $booking_id, self::META_SERVICE_START, true ) );
		if ( null === $start ) {
			return $title;
		}

		return sprintf( '%1$s / %2$s', $title, $start->format( 'Y.n.j H:i' ) );
	}

	/**
	 * 予約メタの日時を、サイトのタイムゾーンの DateTimeImmutable にする。
	 *
	 * @param string $value 保存されている日時。
	 * @return DateTimeImmutable|null 変換できなければ null。
	 */
	private static function parse_datetime( string $value ): ?DateTimeImmutable {
		if ( 1 !== preg_match( '/\A\d{4}-\d{2}-\d{2}([ T])\d{2}:\d{2}(?::\d{2})?\z/', $value ) ) {
			return null;
		}

		$normalized = str_replace( 'T', ' ', $value );
		if ( 16 === strlen( $normalized ) ) {
			$normalized .= ':00';
		}

		$datetime = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $normalized, wp_timezone() );

		// ゼロ日時や存在しない月日は、createFromFormat() が繰り上げて受け入れるため、書式に戻して一致を確かめる。
		if ( ! $datetime instanceof DateTimeImmutable || $datetime->format( 'Y-m-d H:i:s' ) !== $normalized ) {
			return null;
		}

		return $datetime;
	}

	/**
	 * フィルターを通した一覧から、形の正しい項目だけを残す。
	 *
	 * @param array<mixed> $fields フィルターを通した一覧。
	 * @return array<string, array{label: string, value: string}> 形が正しい項目だけの一覧。
	 */
	private static function normalize_meta_fields( array $fields ): array {
		$normalized = array();
		foreach ( $fields as $meta_key => $field ) {
			if ( ! is_string( $meta_key ) || '' === $meta_key || ! is_array( $field ) ) {
				continue;
			}

			$label = isset( $field['label'] ) && is_scalar( $field['label'] ) ? (string) $field['label'] : $meta_key;
			$value = isset( $field['value'] ) && is_scalar( $field['value'] ) ? (string) $field['value'] : '';

			$normalized[ $meta_key ] = array(
				'label' => $label,
				'value' => $value,
			);
		}

		return $normalized;
	}
}
