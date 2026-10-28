<?php
/**
 * 予約一覧の「ご利用になる方」の番号検索。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Attendees;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\Bookings\Reserver_Matcher;
use VKBookingManager\Common\Attendee_Name;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;

/**
 * 番号の完全一致で予約を絞り込み、一致した人を一覧する。
 *
 * 比較は名簿の保存時と同じ `Attendee_Roster::normalize_number()` でそろえる。
 */
class Attendee_Number_Search {
	/** 検索欄の名前（クエリ変数）。 */
	public const QUERY_VAR = 'vkbm_attendee_number';

	/** 一度に絞り込みへ使う、番号が一致した名簿の人数の上限。 */
	private const MAX_ROSTER_MATCHES = 100;

	/** 一致した人として一覧に出す人数の上限。 */
	private const MAX_PEOPLE = 50;

	/** 番号の写しで一致した予約を、一覧の人を探すために読む件数の上限。 */
	private const MAX_BOOKINGS = 100;

	/**
	 * 1リクエスト内で、正規化した番号ごとの予約の写しの照合結果を覚えておく。
	 *
	 * @var array<string,string[]>
	 */
	private static $copied_numbers_cache = array();

	/**
	 * 1リクエスト内で、正規化した番号ごとの名簿の一致を覚えておく。
	 *
	 * @var array<string,array<int,array{user_id:int,id:string,name:string,number:string}>>
	 */
	private static $roster_matches_cache = array();

	/**
	 * 1リクエスト内で、正規化した番号ごとに、名簿の一致が人数の上限に達したかを覚えておく。
	 *
	 * @var array<string,bool>
	 */
	private static $roster_truncated_cache = array();

	/**
	 * 直近の find_people() で、一覧が上限で切れた（取りこぼしの可能性がある）か。
	 *
	 * @var bool
	 */
	private $people_truncated = false;

	/**
	 * 直近の find_people() で、権限が無くて一覧から外した人がいたか。
	 *
	 * @var bool
	 */
	private $people_hidden = false;

	/**
	 * 名簿サービス。
	 *
	 * @var Attendee_Roster
	 */
	private $roster;

	/**
	 * コンストラクタ。
	 *
	 * @param Attendee_Roster|null $roster 名簿サービス。
	 */
	public function __construct( ?Attendee_Roster $roster = null ) {
		$this->roster = $roster ?? new Attendee_Roster();
	}

	/**
	 * 番号機能が有効かを返す。
	 *
	 * @param array<string,mixed>|null $settings 設定値。省略時は保存値。
	 * @return bool 有効なら true。
	 */
	public static function is_enabled( ?array $settings = null ): bool {
		$settings = $settings ?? ( new Settings_Repository() )->get_settings();
		return ! empty( $settings['attendee_number_enabled'] );
	}

	/**
	 * 番号の見出しを返す。設定が空なら「お客様番号」にあたる既定の語を返す。
	 *
	 * @param array<string,mixed>|null $settings 設定値。省略時は保存値。
	 * @return string 見出し。
	 */
	public static function get_label( ?array $settings = null ): string {
		$settings = $settings ?? ( new Settings_Repository() )->get_settings();
		$label    = trim( (string) ( $settings['attendee_number_label'] ?? '' ) );
		return '' !== $label ? $label : __( 'Customer number', 'vk-booking-manager' );
	}

	/**
	 * 覚えておいた照合結果を捨てる（番号や名簿を書き換えた後にやり直すとき用）。
	 *
	 * @return void
	 */
	public static function reset_cache(): void {
		self::$copied_numbers_cache   = array();
		self::$roster_matches_cache   = array();
		self::$roster_truncated_cache = array();
	}

	/**
	 * 検索欄に入力された番号をリクエストから読む。
	 *
	 * 絞り込み・一致した人の表示・入力欄の再表示で、同じ読み取りを使う。
	 *
	 * @return string 長さを制限した入力。無い場合・配列などの場合は空文字。
	 */
	public static function get_requested_input(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 一覧の絞り込み条件を読むだけで状態は変えない。文字列であることを確かめてから unslash とサニタイズをする。
		$value = $_GET[ self::QUERY_VAR ] ?? '';
		if ( ! is_string( $value ) ) {
			return '';
		}
		return mb_substr( trim( sanitize_text_field( wp_unslash( $value ) ) ), 0, Reserver_Matcher::MAX_KEYWORD_LENGTH );
	}

	/**
	 * 入力された番号を、検索用に長さを制限して正規化する。
	 *
	 * @param string $input 検索欄の入力。
	 * @return string 正規化した番号。空の場合は空文字。
	 */
	public static function normalize_input( string $input ): string {
		return Attendee_Roster::normalize_number( mb_substr( $input, 0, Reserver_Matcher::MAX_KEYWORD_LENGTH ) );
	}

	/**
	 * 予約を番号で絞り込む SQL 条件を返す。
	 *
	 * 予約に残る番号の写しで一致する予約と、写しが無く名簿の今の番号で一致する予約の両方を対象にする。
	 *
	 * @param string $normalized 正規化済みの番号。
	 * @return string `AND (...)` 形式の条件。
	 */
	public function build_where( string $normalized ): string {
		global $wpdb;

		$conditions = array();

		// 予約に残る番号の写し。名簿から消された人も、写しで探せる。
		$raw_numbers = $this->find_copied_numbers( $normalized );
		if ( $raw_numbers ) {
			$placeholders = implode( ', ', array_fill( 0, count( $raw_numbers ), '%s' ) );
			// プレースホルダーだけを組み立て、値はすべて prepare に渡す。
			$sql          = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} AS copy_meta WHERE copy_meta.post_id = {$wpdb->posts}.ID AND copy_meta.meta_key = %s AND copy_meta.meta_value IN ({$placeholders}))";
			$conditions[] = $wpdb->prepare( $sql, array_merge( array( Booking_Attendee_Resolver::META_NUMBER ), $raw_numbers ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- 上で組み立てた、値を含まない SQL を使う。
		}

		// 写しが無い予約は、一覧の表示と同じく名簿の今の番号で一致させる。
		foreach ( $this->find_roster_matches( $normalized ) as $match ) {
			$conditions[] = $this->build_roster_condition( $match['user_id'], $match['id'] );
		}

		if ( ! $conditions ) {
			return ' AND 1=0 ';
		}
		return ' AND (' . implode( ' OR ', $conditions ) . ') ';
	}

	/**
	 * 番号が一致した名簿の人を、権限のある範囲で返す。
	 *
	 * 名簿にいる人に加えて、名簿に無く予約の写しだけで一致した人も返す。
	 *
	 * @param string $normalized 正規化済みの番号。
	 * @return array<int,array{name:string,number:string,user_id:int,member_name:string,is_self:bool,in_roster:bool}> 一致した人。
	 */
	public function find_people( string $normalized ): array {
		$people    = array();
		$denied    = array();
		$rosters   = array();
		$matches   = $this->find_roster_matches( $normalized );
		$truncated = ! empty( self::$roster_truncated_cache[ $normalized ] );

		foreach ( $matches as $match ) {
			$key = $match['user_id'] . '|' . $match['id'];
			// ユーザー編集画面で名簿を見られる権限が無い会員の人は、予約の写しからも出さない。
			if ( ! current_user_can( 'edit_user', $match['user_id'] ) ) {
				$denied[ $key ] = true;
				continue;
			}
			$user           = get_userdata( $match['user_id'] );
			$people[ $key ] = array(
				'name'        => $match['name'],
				'number'      => $match['number'],
				'user_id'     => $match['user_id'],
				'member_name' => $user ? $user->display_name : '',
				'is_self'     => Booking_Attendee_Resolver::ID_SELF === $match['id'],
				'in_roster'   => true,
			);
		}

		$raw_numbers = $this->find_copied_numbers( $normalized );
		if ( $raw_numbers ) {
			$booking_ids = get_posts(
				array(
					'post_type'              => Booking_Post_Type::POST_TYPE,
					'post_status'            => 'any',
					'fields'                 => 'ids',
					'posts_per_page'         => self::MAX_BOOKINGS,
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
					'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- 番号の写しに一致する予約だけを取る。
						array(
							'key'     => Booking_Attendee_Resolver::META_NUMBER,
							'value'   => $raw_numbers,
							'compare' => 'IN',
						),
					),
				)
			);
			// 予約ごとの読み込みを繰り返さないよう、投稿とメタをまとめて読む。
			_prime_post_caches( $booking_ids, false, true );
			// 予約者の会員情報もまとめて読み込む。
			$author_ids = array_values( array_unique( array_filter( array_map( 'intval', wp_list_pluck( array_map( 'get_post', $booking_ids ), 'post_author' ) ) ) ) );
			if ( $author_ids ) {
				cache_users( $author_ids );
			}
			$truncated = $truncated || count( $booking_ids ) >= self::MAX_BOOKINGS;
			foreach ( $booking_ids as $booking_id ) {
				$author = (int) get_post_field( 'post_author', $booking_id );
				$id     = trim( (string) get_post_meta( $booking_id, Booking_Attendee_Resolver::META_ID, true ) );
				$name   = trim( (string) get_post_meta( $booking_id, Attendee_Name::META_KEY, true ) );
				$key    = $author . '|' . ( '' !== $id ? $id : Booking_Attendee_Resolver::ID_SELF );
				// 名簿に無い手入力の人は、名前が違えば別の人として数える。
				if ( '' === $id || Booking_Attendee_Resolver::ID_MANUAL === $id ) {
					$key .= '|' . $name;
				}
				if ( isset( $people[ $key ] ) || isset( $denied[ $key ] ) ) {
					continue;
				}

				// 番号を変えた人など、予約者の今の名簿に居る人は、名簿の人として扱う。
				$current = $this->find_current_roster_row( $author, $id, $name, $rosters );
				if ( $current ) {
					$roster_key = $author . '|' . $current['id'];
					if ( isset( $people[ $roster_key ] ) || isset( $denied[ $roster_key ] ) ) {
						continue;
					}
					if ( ! current_user_can( 'edit_user', $author ) ) {
						$denied[ $roster_key ] = true;
						continue;
					}
					$member                = get_userdata( $author );
					$people[ $roster_key ] = array(
						'name'        => (string) $current['name'],
						'number'      => trim( (string) $current['number'] ),
						'user_id'     => $author,
						'member_name' => $member ? $member->display_name : '',
						'is_self'     => Booking_Attendee_Resolver::ID_SELF === $current['id'],
						'in_roster'   => true,
					);
					continue;
				}

				$people[ $key ] = array(
					'name'        => $name,
					'number'      => trim( (string) get_post_meta( $booking_id, Booking_Attendee_Resolver::META_NUMBER, true ) ),
					'user_id'     => 0,
					'member_name' => '',
					'is_self'     => false,
					'in_roster'   => false,
				);
			}
		}

		$people                 = array_values( $people );
		$this->people_truncated = $truncated || count( $people ) > self::MAX_PEOPLE;
		$this->people_hidden    = ! empty( $denied );
		return array_slice( $people, 0, self::MAX_PEOPLE );
	}

	/**
	 * 直近の find_people() の一覧が、件数の上限で切れた（取りこぼしの可能性がある）かを返す。
	 *
	 * @return bool 上限に達していれば true。
	 */
	public function is_people_truncated(): bool {
		return $this->people_truncated;
	}

	/**
	 * 予約者の今の名簿から、予約に残る「ご利用になる方」にあたる行を探す。
	 *
	 * ID があれば ID で、ID が無ければ名前で引く。手入力の人は名簿に居ない人として扱う。
	 *
	 * @param int                                        $author  予約者（会員）の ID。
	 * @param string                                     $id      予約に残る名簿の人の ID。
	 * @param string                                     $name    予約に残る名前。
	 * @param array<int,array<int,array<string,string>>> $rosters 会員ごとの名簿（呼び出し側で使い回す入れ物）。
	 * @return array<string,string>|null 見つかった行。無ければ null。
	 */
	private function find_current_roster_row( int $author, string $id, string $name, array &$rosters ): ?array {
		if ( $author <= 0 || Booking_Attendee_Resolver::ID_MANUAL === $id ) {
			return null;
		}
		if ( ! isset( $rosters[ $author ] ) ) {
			$user               = get_userdata( $author );
			$rosters[ $author ] = $user ? $this->roster->get_with_self( $user ) : array();
		}
		foreach ( $rosters[ $author ] as $row ) {
			if ( '' !== $id ) {
				if ( (string) $row['id'] === $id ) {
					return $row;
				}
			} elseif ( '' !== $name && (string) $row['name'] === $name ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * 番号が一致した名簿の人のうち、権限が無くて一覧に出せない人（予約の写しだけで一致した人を含む）がいるかを返す。
	 *
	 * @param string $normalized 正規化済みの番号。
	 * @return bool 出せない人がいれば true。
	 */
	public function has_hidden_people( string $normalized ): bool {
		// 名簿の今の番号で一致した人に加え、予約の写しだけで一致した人も含めて判定するため、一覧の組み立て結果を使う。
		$this->find_people( $normalized );
		return $this->people_hidden;
	}

	/**
	 * 予約に保存された番号の写しのうち、正規化すると同じになる元の値を返す。
	 *
	 * 写しは入力したままの表記で保存しているため、SQL で比べず PHP 側で正規化して照合する。
	 *
	 * @param string $normalized 正規化済みの番号。
	 * @return string[] 予約に保存されている元の表記。
	 */
	private function find_copied_numbers( string $normalized ): array {
		global $wpdb;

		if ( isset( self::$copied_numbers_cache[ $normalized ] ) ) {
			return self::$copied_numbers_cache[ $normalized ];
		}

		$values = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- 保存されている番号の種類を、検索のたびに調べる。
			$wpdb->prepare(
				"SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''",
				Booking_Attendee_Resolver::META_NUMBER
			)
		);

		$matched = array();
		foreach ( $values as $value ) {
			if ( Attendee_Roster::normalize_number( (string) $value ) === $normalized ) {
				$matched[] = (string) $value;
			}
		}
		self::$copied_numbers_cache[ $normalized ] = $matched;
		return $matched;
	}

	/**
	 * 今の名簿の番号が一致する人を返す。
	 *
	 * @param string $normalized 正規化済みの番号。
	 * @return array<int,array{user_id:int,id:string,name:string,number:string}> 一致した名簿の人。
	 */
	private function find_roster_matches( string $normalized ): array {
		if ( isset( self::$roster_matches_cache[ $normalized ] ) ) {
			return self::$roster_matches_cache[ $normalized ];
		}
		$user_ids = get_users(
			array(
				'meta_key'   => Attendee_Roster::get_number_meta_key(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- 番号の検索用に保存したメタキー。
				'meta_value' => $normalized, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- 同上。
				'fields'     => 'ID',
				'number'     => self::MAX_ROSTER_MATCHES,
			)
		);

		self::$roster_truncated_cache[ $normalized ] = count( $user_ids ) >= self::MAX_ROSTER_MATCHES;
		if ( $user_ids ) {
			cache_users( array_map( 'intval', $user_ids ) );
		}

		$matches = array();
		foreach ( $user_ids as $user_id ) {
			$user = get_userdata( (int) $user_id );
			if ( ! $user ) {
				continue;
			}
			foreach ( $this->roster->get_with_self( $user ) as $row ) {
				if ( Attendee_Roster::normalize_number( (string) $row['number'] ) === $normalized ) {
					$matches[] = array(
						'user_id' => (int) $user_id,
						'id'      => (string) $row['id'],
						'name'    => (string) $row['name'],
						'number'  => trim( (string) $row['number'] ),
					);
				}
			}
		}
		self::$roster_matches_cache[ $normalized ] = $matches;
		return $matches;
	}

	/**
	 * 名簿の人の予約を、番号の写しが無いものに限って選ぶ SQL 条件を返す。
	 *
	 * ID の値が無く名前も無い過去予約は、一覧の表示と同じく本人の予約として扱う。
	 *
	 * @param int    $user_id 名簿の持ち主の ID。
	 * @param string $id      名簿の人の ID。
	 * @return string SQL 条件。
	 */
	private function build_roster_condition( int $user_id, string $id ): string {
		global $wpdb;

		$posts = $wpdb->posts;
		$meta  = $wpdb->postmeta;

		// 番号の写しが無く、持ち主と名簿の人の ID が一致する予約。
		$sql  = "({$posts}.post_author = %d";
		$sql .= " AND NOT EXISTS (SELECT 1 FROM {$meta} AS copy_meta WHERE copy_meta.post_id = {$posts}.ID AND copy_meta.meta_key = %s AND copy_meta.meta_value <> '')";
		$sql .= " AND (EXISTS (SELECT 1 FROM {$meta} AS id_meta WHERE id_meta.post_id = {$posts}.ID AND id_meta.meta_key = %s AND id_meta.meta_value = %s)";
		$args = array( $user_id, Booking_Attendee_Resolver::META_NUMBER, Booking_Attendee_Resolver::META_ID, $id );

		// 本人は、ID も名前も無い過去予約も含める。
		if ( Booking_Attendee_Resolver::ID_SELF === $id ) {
			$sql   .= " OR (NOT EXISTS (SELECT 1 FROM {$meta} AS other_id_meta WHERE other_id_meta.post_id = {$posts}.ID AND other_id_meta.meta_key = %s AND other_id_meta.meta_value <> '')";
			$sql   .= " AND NOT EXISTS (SELECT 1 FROM {$meta} AS name_meta WHERE name_meta.post_id = {$posts}.ID AND name_meta.meta_key = %s AND name_meta.meta_value <> ''))";
			$args[] = Booking_Attendee_Resolver::META_ID;
			$args[] = Attendee_Name::META_KEY;
		}
		$sql .= '))';

		return $wpdb->prepare( $sql, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名とプレースホルダーだけを連結し、値はすべて prepare に渡す。
	}
}
