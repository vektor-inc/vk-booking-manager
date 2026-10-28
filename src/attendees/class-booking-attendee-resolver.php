<?php
/**
 * 予約で選ばれた「ご利用になる方」を名簿と照合し、予約に保存する値へ整える。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Attendees;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\Common\Attendee_Name;
use WP_Error;
use WP_User;

/**
 * 予約確認画面の選択肢の組み立てと、予約確定時の照合・名簿への保存を担う。
 */
class Booking_Attendee_Resolver {
	/** 予約に保存する「ご利用になる方」の ID の post meta キー。 */
	public const META_ID = '_vkbm_booking_attendee_id';

	/** 予約時点の番号の写しの post meta キー。 */
	public const META_NUMBER = '_vkbm_booking_attendee_number';

	/** 本人を表す ID。 */
	public const ID_SELF = 'self';

	/** 名簿に無い人を手入力した予約を表す ID。 */
	public const ID_MANUAL = 'manual';

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
	 * 名簿機能が有効かを返す。
	 *
	 * @param array<string,mixed> $settings 設定値。
	 * @return bool 有効なら true。
	 */
	public static function is_roster_enabled( array $settings ): bool {
		return ! empty( $settings['attendee_roster_enabled'] );
	}

	/**
	 * 名簿の見出しを返す。
	 *
	 * @param array<string,mixed> $settings 設定値。
	 * @return string 設定された見出し。空の場合は既定の見出し。
	 */
	public static function get_roster_label( array $settings ): string {
		$label = trim( (string) ( $settings['attendee_roster_label'] ?? '' ) );
		return '' !== $label ? $label : __( 'People receiving the service', 'vk-booking-manager' );
	}

	/**
	 * 番号の見出しを返す。
	 *
	 * @param array<string,mixed> $settings 設定値。
	 * @return string 設定された見出し。空の場合は既定の見出し。
	 */
	public static function get_number_label( array $settings ): string {
		$label = trim( (string) ( $settings['attendee_number_label'] ?? '' ) );
		return '' !== $label ? $label : __( 'Customer number', 'vk-booking-manager' );
	}

	/**
	 * 代理予約で、会員の名簿を保存できる権限があるかを返す。
	 *
	 * @param int $owner_id 名簿の持ち主（会員）の ID。
	 * @return bool 保存できる場合 true。
	 */
	public function can_save_for_owner( int $owner_id ): bool {
		return ( new Attendee_Roster_User_Profile( $this->roster ) )->can_edit( $owner_id );
	}

	/**
	 * 予約確認画面に出す選択肢を組み立てる。
	 *
	 * @param array<string,mixed> $settings 設定値。
	 * @param WP_User|null        $owner    名簿の持ち主。代理予約で会員が見つからない間は null。
	 * @param bool                $is_proxy 代理予約か。
	 * @return array<string,mixed> 画面に渡す値。
	 */
	public function get_options( array $settings, ?WP_User $owner, bool $is_proxy ): array {
		if ( ! self::is_roster_enabled( $settings ) ) {
			return array( 'enabled' => false );
		}

		$show_self   = ! empty( $settings['attendee_roster_show_self'] );
		$show_number = ! empty( $settings['attendee_number_enabled'] );
		// 代理予約では、名簿を編集できる権限があるときだけ中身を返す。無ければ手入力だけにする。
		if ( $owner && $is_proxy && ! $this->can_save_for_owner( $owner->ID ) ) {
			$owner = null;
		}
		$rows  = $owner ? $this->roster->get_with_self( $owner ) : array();
		$items = array();
		foreach ( $rows as $row ) {
			if ( self::ID_SELF === $row['id'] && ! $show_self ) {
				continue;
			}
			$items[] = array(
				'id'     => $row['id'],
				'name'   => $row['name'],
				'number' => $show_number ? $row['number'] : '',
			);
		}
		$limit_reached = $owner && count( $rows ) - 1 >= Attendee_Roster::MAX_ATTENDEES;

		return array(
			'enabled'        => true,
			'label'          => self::get_roster_label( $settings ),
			'kana_enabled'   => ! empty( $settings['attendee_roster_kana_enabled'] ),
			'kana_label'     => trim( (string) ( $settings['attendee_roster_kana_label'] ?? '' ) ),
			'number_enabled' => $show_number,
			'number_label'   => self::get_number_label( $settings ),
			'has_owner'      => (bool) $owner,
			'owner_name'     => $owner ? $rows[0]['name'] : '',
			'items'          => $items,
			'limit_reached'  => (bool) $limit_reached,
			'max'            => Attendee_Roster::MAX_ATTENDEES,
			'can_save'       => (bool) $owner && ! $limit_reached,
		);
	}

	/**
	 * 選ばれた「ご利用になる方」を検証し、予約に保存する値を返す。
	 *
	 * 名前と番号は送られた値を使わず、名簿にある人はサーバー側の名簿から写す。
	 * 名簿へ保存する指定がある場合は、予約より先に名簿へ保存する。
	 *
	 * @param array<string,mixed> $settings 設定値。
	 * @param int                 $owner_id 名簿の持ち主の ID。持ち主がいない場合は 0。
	 * @param array<string,mixed> $input    画面から送られた値。
	 * @param bool                $is_proxy 代理予約か。
	 * @return array{id:string,name:string,number:string}|WP_Error 保存する値。ID が空文字の場合は何も保存しない。
	 */
	public function resolve( array $settings, int $owner_id, array $input, bool $is_proxy ) {
		$mode     = Attendee_Name::get_mode( $settings );
		$raw_name = Attendee_Name::sanitize( $input['attendee_name'] ?? '' );
		$id       = sanitize_key( trim( (string) ( $input['attendee_id'] ?? '' ) ) );

		// 名簿を使わない場合、または選択が送られない場合は、名簿機能より前からある手入力の扱い。
		if ( ! self::is_roster_enabled( $settings ) || '' === $id ) {
			$name = 'disabled' === $mode ? '' : $raw_name;
			if ( ! $is_proxy && 'required' === $mode && '' === $name ) {
				return $this->create_name_required_error();
			}
			// 代理予約で空のまま確定した場合も manual を保存し、本人の予約とみなされないようにする。
			return $this->build_result( '' !== $name || $is_proxy ? self::ID_MANUAL : '', $name, '' );
		}

		$owner = $owner_id > 0 ? get_userdata( $owner_id ) : false;
		if ( self::ID_MANUAL === $id ) {
			return $this->resolve_manual( $settings, $owner ? $owner : null, $raw_name, $input, $is_proxy );
		}
		// 代理予約で名簿を読む場合も、名簿を編集できる権限を必須にする。
		if ( ! $owner || ( $is_proxy && ! $this->can_save_for_owner( $owner->ID ) ) ) {
			return $this->create_not_found_error();
		}

		// 本人と名簿の人は、持ち主の名簿に存在するものだけを受け付ける。
		if ( self::ID_SELF === $id && empty( $settings['attendee_roster_show_self'] ) ) {
			return $this->create_not_found_error();
		}
		foreach ( $this->roster->get_with_self( $owner ) as $row ) {
			if ( $row['id'] === $id ) {
				return $this->build_result( $id, $row['name'], ! empty( $settings['attendee_number_enabled'] ) ? $row['number'] : '' );
			}
		}
		return $this->create_not_found_error();
	}

	/**
	 * 名簿に無い人の手入力を整え、指定があれば名簿へ保存する。
	 *
	 * @param array<string,mixed> $settings 設定値。
	 * @param WP_User|null        $owner    名簿の持ち主。
	 * @param string              $name     サニタイズ済みの名前。
	 * @param array<string,mixed> $input    画面から送られた値。
	 * @param bool                $is_proxy 代理予約か。
	 * @return array{id:string,name:string,number:string}|WP_Error 保存する値。
	 */
	private function resolve_manual( array $settings, ?WP_User $owner, string $name, array $input, bool $is_proxy ) {
		if ( '' === $name ) {
			return $is_proxy ? $this->build_result( self::ID_MANUAL, '', '' ) : $this->create_name_required_error();
		}

		// 番号は会員が変更できないため、代理予約の入力だけを受け付ける。
		$number = '';
		if ( $is_proxy && ! empty( $settings['attendee_number_enabled'] ) ) {
			$number = mb_substr( sanitize_text_field( (string) ( $input['attendee_number'] ?? '' ) ), 0, 50 );
		}

		if ( ! $owner || empty( $input['attendee_save_to_roster'] ) ) {
			return $this->build_result( self::ID_MANUAL, $name, $number );
		}
		if ( $is_proxy && ! $this->can_save_for_owner( $owner->ID ) ) {
			return new WP_Error(
				'attendee_roster_forbidden',
				__( 'You do not have permission to save to this member\'s list.', 'vk-booking-manager' ),
				array( 'status' => 403 )
			);
		}

		return $this->save_new_attendee( $settings, $owner, $name, $number, $input );
	}

	/**
	 * 新しい人を名簿へ保存し、保存された値を返す。
	 *
	 * 画面が決めた UUID で保存するため、同じ UUID で再送されても名簿には1件しか増えない。
	 *
	 * @param array<string,mixed> $settings 設定値。
	 * @param WP_User             $owner    名簿の持ち主。
	 * @param string              $name     名前。
	 * @param string              $number   番号。
	 * @param array<string,mixed> $input    画面から送られた値。
	 * @return array{id:string,name:string,number:string}|WP_Error 保存された値。
	 */
	private function save_new_attendee( array $settings, WP_User $owner, string $name, string $number, array $input ) {
		$new_id = strtolower( trim( (string) ( $input['attendee_new_id'] ?? '' ) ) );
		if ( ! wp_is_uuid( $new_id, 4 ) ) {
			$new_id = wp_generate_uuid4();
		}

		$rows = $this->roster->get_with_self( $owner );
		if ( ! in_array( $new_id, array_column( $rows, 'id' ), true ) ) {
			$rows[] = array(
				'id'     => $new_id,
				'name'   => $name,
				'kana'   => ! empty( $settings['attendee_roster_kana_enabled'] ) ? mb_substr( sanitize_text_field( (string) ( $input['attendee_kana'] ?? '' ) ), 0, 100 ) : '',
				'number' => $number,
			);
			$saved  = $this->roster->save( $owner->ID, $rows );
			if ( is_wp_error( $saved ) ) {
				$saved->add_data( array( 'status' => 'vkbm_attendee_roster_conflict' === $saved->get_error_code() ? 409 : 400 ) );
				return $saved;
			}
		}

		// 名簿に保存された値（文字数の上限を適用した後の値）を予約へ写す。
		foreach ( $this->roster->get( $owner->ID ) as $row ) {
			if ( $row['id'] === $new_id ) {
				return $this->build_result( $new_id, $row['name'], ! empty( $settings['attendee_number_enabled'] ) ? $row['number'] : '' );
			}
		}
		return $this->create_not_found_error();
	}

	/**
	 * 保存する値の配列を組み立てる。
	 *
	 * @param string $id     ご利用になる方の ID。
	 * @param string $name   名前。
	 * @param string $number 番号。
	 * @return array{id:string,name:string,number:string} 保存する値。
	 */
	private function build_result( string $id, string $name, string $number ): array {
		return array(
			'id'     => $id,
			'name'   => $name,
			'number' => $number,
		);
	}

	/**
	 * 名前が必須のエラーを作る。
	 *
	 * @return WP_Error エラー。
	 */
	private function create_name_required_error(): WP_Error {
		return new WP_Error(
			'attendee_name_required',
			sprintf(
				/* translators: %s: Attendee name field label. */
				__( 'Please enter %s.', 'vk-booking-manager' ),
				Attendee_Name::get_label()
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * 選ばれた人が名簿に無いエラーを作る。
	 *
	 * @return WP_Error エラー。
	 */
	private function create_not_found_error(): WP_Error {
		return new WP_Error(
			'attendee_not_found',
			__( 'The selected person could not be found. Please select again.', 'vk-booking-manager' ),
			array( 'status' => 400 )
		);
	}
}
