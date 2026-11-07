<?php
/**
 * ユーザー削除時の予約保護。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use VKBookingManager\Bookings\Booking_Anonymizer;
use VKBookingManager\Bookings\Booking_User_Deletion;
use VKBookingManager\Bookings\Booking_Source;
use VKBookingManager\Attendees\Booking_Attendee_Resolver;
use WP_UnitTestCase;

/**
 * ユーザー削除時の予約保持を検証する。
 *
 * @group bookings
 */
class Booking_User_Deletion_Test extends WP_UnitTestCase {
	/**
	 * 元の送信値。
	 *
	 * @var array
	 */
	private $saved_post;

	/** テストごとの管理画面を用意する。 */
	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$this->saved_post = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- テスト終了時に送信値を復元する。
		set_current_screen( 'users' );
		$GLOBALS['pagenow'] = 'users.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- ユーザー削除画面を再現する。
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/** 送信値と画面を戻す。 */
	public function tear_down(): void {
		$_POST = $this->saved_post;
		delete_transient( 'vkbm_user_deletion_' . get_current_user_id() );
		set_current_screen( 'front' );
		unset( $GLOBALS['pagenow'] );
		parent::tear_down();
	}

	/**
	 * 予約を作る。
	 *
	 * @param int    $author 投稿者。
	 * @param string $end 終了日時。
	 * @param string $status 投稿状態。
	 * @return int 予約 ID。
	 */
	private function booking( int $author, string $end, string $status = 'publish' ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => 'vkbm_booking',
				'post_author' => $author,
				'post_status' => $status,
				'post_date'   => 'future' === $status ? '2099-01-01 12:00:00' : '2000-01-01 12:00:00',
				'post_title'  => '予約者 / 日時',
				'meta_input'  => array(
					'_vkbm_booking_total_end'          => $end,
					'_vkbm_booking_customer_name'      => '予約者',
					'_vkbm_booking_customer_email'     => 'customer@example.org',
					'_vkbm_booking_billed_total_price' => '5500',
					'_vkbm_booking_guests'             => '2',
					'_vkbm_booking_attendee_id'        => 'person-1',
				),
			)
		);
	}

	/**
	 * 本体の削除・割り当てと匿名化の組み合わせで予約と集計値を残す。
	 */
	public function test_delete_user(): void {
		$target = self::factory()->user->create( array( 'display_name' => '移動先' ) );
		$cases  = array(
			array(
				'test_condition_name' => '削除と匿名化',
				'reassign'            => null,
				'choice'              => 'anonymize',
				'anonymous'           => true,
			),
			array(
				'test_condition_name' => '削除と個人情報保持',
				'reassign'            => null,
				'choice'              => 'keep',
				'anonymous'           => false,
			),
			array(
				'test_condition_name' => '割り当てと匿名化',
				'reassign'            => $target,
				'choice'              => 'anonymize',
				'anonymous'           => true,
			),
			array(
				'test_condition_name' => '割り当てと個人情報保持',
				'reassign'            => $target,
				'choice'              => 'keep',
				'anonymous'           => false,
			),
			array(
				'test_condition_name' => '指定なし',
				'reassign'            => null,
				'choice'              => null,
				'anonymous'           => false,
			),
		);
		foreach ( $cases as $case ) {
			foreach ( array( 'publish', 'draft', 'trash', 'pending', 'private', 'future', 'auto-draft' ) as $status ) {
				$customer = self::factory()->user->create( array( 'role' => 'subscriber' ) );
				$_POST    = null === $case['choice'] ? array() : array(
					'action'                 => 'dodelete',
					'_wpnonce'               => wp_create_nonce( 'delete-users' ),
					'vkbm_booking_anonymize' => $case['choice'],
				);
				$bookings = array();
				foreach ( array(
					'past'    => '2000-01-01 12:00:00',
					'future'  => '2099-01-01 12:00:00',
					'undated' => '',
				) as $kind => $end ) {
					$bookings[ $kind ] = $this->booking( $customer, $end, $status );
				}
				$this->assertSame( $status, get_post_status( $bookings['past'] ) );
				$groups = ( new Booking_User_Deletion() )->summarize( array( $customer ) );
				$this->assertSame( 3, array_sum( array_map( 'count', $groups ) ), '処理対象と確認件数が一致する' );
				delete_transient( 'vkbm_user_deletion_' . get_current_user_id() );
				wp_delete_user( $customer, $case['reassign'] );
				// データだけでなく、利用者に伝える結果の種類と文言も確かめる。
				ob_start();
				( new Booking_User_Deletion() )->render_notices();
				$html = ob_get_clean();
				if ( null === $case['choice'] ) {
					$this->assertSame( '', $html );
				} else {
					$notice_key = $case['anonymous'] ? ( null === $case['reassign'] ? 'anonymized' : 'anonymized_reassigned' ) : ( null === $case['reassign'] ? 'kept_past' : 'reassigned' );
					$this->assert_notice( $notice_key, $html, 'reassigned' === $notice_key ? 3 : 1 );
					if ( ! $case['anonymous'] ) {
						$this->assertStringNotContainsString( 'notice-success', $html );
					}
				}

				foreach ( $bookings as $kind => $id ) {
					$anonymous = 'past' === $kind && $case['anonymous'];
					$this->assertNotNull( get_post( $id ), $case['test_condition_name'] );
					$this->assertSame( $anonymous ? 0 : (int) $case['reassign'], (int) get_post( $id )->post_author, $case['test_condition_name'] );
					$this->assertSame( $anonymous, Booking_Anonymizer::is_anonymized( $id ), $case['test_condition_name'] );
					$this->assertSame( $anonymous ? '' : 'customer@example.org', get_post_meta( $id, '_vkbm_booking_customer_email', true ) );
					$expected_marker = ! $anonymous && null === $case['reassign'];
					$has_marker      = '' !== (string) get_post_meta( $id, Booking_User_Deletion::META_AUTHOR_DELETED_AT, true );
					$this->assertSame( $expected_marker, $has_marker );
					$this->assertSame( '5500', get_post_meta( $id, '_vkbm_booking_billed_total_price', true ) );
					$this->assertSame( '2', get_post_meta( $id, '_vkbm_booking_guests', true ) );
					if ( $anonymous ) {
						$this->assertSame( 'user_deletion', Booking_Anonymizer::get_anonymized_by( $id ) );
					} elseif ( null !== $case['reassign'] ) {
						$this->assertSame( Booking_Attendee_Resolver::ID_MANUAL, get_post_meta( $id, Booking_Attendee_Resolver::META_ID, true ) );
					}
				}
			}
		}
		$this->assertFalse( get_post_type_object( 'vkbm_booking' )->delete_with_user );
	}

	/** 不正な指定や別画面からの指定で匿名化しない。 */
	public function test_validated_choice(): void {
		$cases = array(
			array(
				'test_condition_name' => 'nonceなし',
				'nonce'               => '',
				'action'              => 'dodelete',
				'choice'              => 'anonymize',
				'screen'              => 'users',
				'role'                => 'administrator',
			),
			array(
				'test_condition_name' => '操作が違う',
				'nonce'               => 'valid',
				'action'              => 'update',
				'choice'              => 'anonymize',
				'screen'              => 'users',
				'role'                => 'administrator',
			),
			array(
				'test_condition_name' => '配列は拒否',
				'nonce'               => 'valid',
				'action'              => 'dodelete',
				'choice'              => array( 'anonymize' ),
				'screen'              => 'users',
				'role'                => 'administrator',
			),
			array(
				'test_condition_name' => '別画面',
				'nonce'               => 'valid',
				'action'              => 'dodelete',
				'choice'              => 'anonymize',
				'screen'              => 'edit',
				'role'                => 'administrator',
			),
			array(
				'test_condition_name' => '権限なし',
				'nonce'               => 'valid',
				'action'              => 'dodelete',
				'choice'              => 'anonymize',
				'screen'              => 'users',
				'role'                => 'subscriber',
			),
		);
		foreach ( $cases as $case ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => $case['role'] ) ) );
			set_current_screen( $case['screen'] );
			$GLOBALS['pagenow'] = $case['screen'] . '.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- 削除画面と別画面を再現する。
			$customer           = self::factory()->user->create();
			$id                 = $this->booking( $customer, '2000-01-01 12:00:00' );
			$_POST              = array(
				'action'                 => $case['action'],
				'_wpnonce'               => 'valid' === $case['nonce'] ? wp_create_nonce( 'delete-users' ) : $case['nonce'],
				'vkbm_booking_anonymize' => $case['choice'],
			);
			wp_delete_user( $customer );
			$this->assertFalse( Booking_Anonymizer::is_anonymized( $id ), $case['test_condition_name'] );
			$this->assertSame( 0, (int) get_post( $id )->post_author );
			$this->assertSame( '配列は拒否' === $case['test_condition_name'], is_array( get_transient( 'vkbm_user_deletion_' . get_current_user_id() ) ) );
		}
	}

	/** スタッフの予約を匿名化せず、保存しても管理者に付け替えない。 */
	public function test_keep_deleted_author(): void {
		foreach ( array( 'administrator', 'subscriber' ) as $role ) {
			$staff = self::factory()->user->create( array( 'role' => $role ) );
			get_user_by( 'id', $staff )->add_cap( 'vkbm_manage_reservations' );
			$id    = $this->booking( $staff, '2000-01-01 12:00:00' );
			$_POST = array(
				'action'                 => 'dodelete',
				'_wpnonce'               => wp_create_nonce( 'delete-users' ),
				'vkbm_booking_anonymize' => 'anonymize',
			);
			wp_delete_user( $staff );
			wp_update_post(
				array(
					'ID'          => $id,
					'post_author' => get_current_user_id(),
				)
			);
			$this->assertSame( 0, (int) get_post( $id )->post_author );
			$this->assertFalse( Booking_Anonymizer::is_anonymized( $id ) );
			// 一括操作・自動匿名化と同じ処理は、印のある予約にも使える。
			$this->assertSame( Booking_Anonymizer::RESULT_ANONYMIZED, ( new Booking_Anonymizer() )->anonymize( $id ) );
		}
	}

	/** 失敗しても削除を止めず、割り当て先へ個人情報を移さない。 */
	public function test_delete_user_failed(): void {
		$anonymizer = new class() extends Booking_Anonymizer {
			/**
			 * 保存失敗を再現する。
			 *
			 * @param int    $id 予約。
			 * @param string $by きっかけ。
			 * @return string 結果。
			 */
			public function anonymize( int $id, string $by = self::ANONYMIZED_BY_BULK ): string {
				return self::RESULT_FAILED;
			}
		};
		$handler    = new Booking_User_Deletion( $anonymizer );
		wp_get_current_user()->add_cap( 'vkbm_manage_reservations' );
		foreach ( array( null, self::factory()->user->create() ) as $reassign ) {
			$customer = self::factory()->user->create();
			$id       = $this->booking( $customer, '2000-01-01 12:00:00' );
			$_POST    = array(
				'action'                 => 'dodelete',
				'_wpnonce'               => wp_create_nonce( 'delete-users' ),
				'vkbm_booking_anonymize' => 'anonymize',
			);
			// 依存注入した処理で失敗させ、本体の後続フックは投稿者で対象外になる。
			$handler->delete_user( $customer, $reassign );
			$this->assertTrue( wp_delete_user( $customer, $reassign ) );
			$this->assertSame( 0, (int) get_post( $id )->post_author );
			$this->assertNotEmpty( get_post_meta( $id, Booking_User_Deletion::META_AUTHOR_DELETED_AT, true ) );
			$this->assertSame( 'customer@example.org', get_post_meta( $id, '_vkbm_booking_customer_email', true ) );
		}
		ob_start();
		$handler->render_notices();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'edit.php?post_type=vkbm_booking', $html );
		$this->assertStringContainsString( 'post=' . $id, $html );
		ob_start();
		$handler->render_notices();
		$this->assertSame( '', ob_get_clean(), '通知は一度だけ' );
	}

	/** 確認画面は分類の合計・未選択の必須ラジオ・リンク・スタッフ除外をそろえる。 */
	public function test_render_confirmation(): void {
		$handler = new Booking_User_Deletion();
		wp_get_current_user()->add_cap( 'vkbm_manage_reservations' );
		$customer = self::factory()->user->create();
		$staff    = self::factory()->user->create();
		get_user_by( 'id', $staff )->add_cap( 'vkbm_manage_reservations' );
		$past          = $this->booking( $customer, '2000-01-01 12:00:00' );
		$future        = $this->booking( $customer, '2099-01-01 12:00:00' );
		$staff_booking = $this->booking( $staff, '2000-01-01 12:00:00' );
		$groups        = $handler->summarize( array( $customer, $staff ) );
		$this->assertSame( array( $past ), $groups['past'] );
		$this->assertSame( array( $future ), $groups['pending'] );
		$this->assertSame( array( $staff_booking ), $groups['staff'] );
		ob_start();
		$handler->render_confirmation( wp_get_current_user(), array( $customer, $staff ) );
		$html = ob_get_clean();
		$this->assertSame( 2, substr_count( $html, ' required' ) );
		$this->assertStringNotContainsString( 'checked', $html );
		$this->assertStringContainsString( 'aria-describedby="vkbm-user-deletion-fields vkbm-user-deletion-help"', $html );
		$this->assertStringContainsString( 'post=' . $future, $html );
		$this->assertStringContainsString( __( 'If you attribute content to another user: anonymized reservations are not moved.', 'vk-booking-manager' ), $html, '予約だけでも本体の割り当て欄がある' );
		foreach ( array( array( $staff ), array(), array( get_current_user_id() ) ) as $ids ) {
			ob_start();
			$handler->render_confirmation( wp_get_current_user(), $ids );
			$markup = ob_get_clean();
			$this->assertStringNotContainsString( 'type="radio"', $markup );
		}
		set_current_screen( 'users-network' );
		ob_start();
		$handler->render_confirmation( wp_get_current_user(), array( $customer ) );
		$this->assertSame( '', ob_get_clean() );
	}

	/** 管理権限に応じて一覧リンクと後日の操作案内を出し分ける。 */
	public function test_link_list(): void {
		$customer = self::factory()->user->create();
		$this->booking( $customer, '2000-01-01 12:00:00' );
		$handler = new Booking_User_Deletion();
		$cases   = array(
			array( 'test_condition_name' => '予約管理権限あり', 'allowed' => true ),
			array( 'test_condition_name' => 'ユーザー削除のみ可能', 'allowed' => false ),
			array( 'test_condition_name' => '予約管理権限を再付与', 'allowed' => true ),
		);
		foreach ( $cases as $case ) {
			// ユーザー削除権限を残し、予約管理権限だけを切り替える。
			wp_get_current_user()->add_cap( 'vkbm_manage_reservations', $case['allowed'] );
			$this->assertTrue( current_user_can( 'delete_users' ) );
			$message = __( 'reservation list', 'vk-booking-manager' ) . ' <script>';
			$link    = Booking_User_Deletion::link_list( $message );
			$this->assertStringNotContainsString( '<script>', $link );
			$this->assertSame( $case['allowed'], str_contains( $link, '<a href=' ), $case['test_condition_name'] );
			if ( ! $case['allowed'] ) {
				$this->assertSame( esc_html( $message ), $link );
			}
			// 確認画面には実行可能な操作だけを案内する。
			ob_start();
			$handler->render_confirmation( wp_get_current_user(), array( $customer ) );
			$html = ob_get_clean();
			$help = __( 'Reservations you do not anonymize now can be anonymized later from the reservation list.', 'vk-booking-manager' );
			$this->assertSame( $case['allowed'], str_contains( $html, 'edit.php?post_type=vkbm_booking' ), $case['test_condition_name'] );
			if ( ! $case['allowed'] ) {
				$this->assertStringNotContainsString( $help, $html );
			}
		}
	}

	/** 一覧・編集の削除済み表示をゲストより優先し、予約者を選び直せる。 */
	public function test_save_post(): void {
		$customer = self::factory()->user->create();
		$target   = self::factory()->user->create();
		$id       = $this->booking( $customer, '2000-01-01 12:00:00' );
		update_post_meta( $id, Booking_Source::META_KEY, Booking_Source::VALUE_GUEST );
		$_POST = array();
		wp_delete_user( $customer );
		$handler = new Booking_User_Deletion();
		$this->assertArrayHasKey( 'vkbm_author_deleted', $handler->add_post_state( array(), get_post( $id ) ) );
		$admin = new \VKBookingManager\Bookings\Booking_Admin();
		ob_start();
		$admin->render_meta_box( get_post( $id ) );
		$html = ob_get_clean();
		$this->assertStringContainsString( __( 'Deleted user', 'vk-booking-manager' ), $html );
		$this->assertStringNotContainsString( __( 'Guest booking (not linked to a member)', 'vk-booking-manager' ), $html );
		$this->assertStringContainsString( 'edit.php?post_type=vkbm_booking', $html );
		get_user_by( 'id', get_current_user_id() )->add_cap( 'vkbm_manage_reservations' );
		$_POST = array(
			'_vkbm_booking_meta_nonce' => wp_create_nonce( 'vkbm_booking_meta' ),
			'vkbm_booking'             => array(
				'date'       => '2026-10-10',
				'start_time' => '15:00',
				'end_time'   => '16:00',
				'status'     => 'cancelled',
				'author_id'  => $target,
			),
		);
		$admin->save_post( $id, get_post( $id ) );
		$this->assertSame( $target, (int) get_post( $id )->post_author );
		$this->assertSame( '', get_post_meta( $id, Booking_User_Deletion::META_AUTHOR_DELETED_AT, true ) );
		$this->assertSame( Booking_Attendee_Resolver::ID_MANUAL, get_post_meta( $id, Booking_Attendee_Resolver::META_ID, true ) );
	}

	/** 削除後に残した予約は、一括操作と Pro の自動匿名化でも処理できる。 */
	public function test_retained_bookings_can_be_anonymized_later(): void {
		$customer = self::factory()->user->create();
		$bulk     = $this->booking( $customer, '2000-01-01 12:00:00' );
		$auto     = $this->booking( $customer, '2000-01-01 12:00:00' );
		$future   = $this->booking( $customer, '2099-01-01 12:00:00' );
		$_POST    = array();
		wp_delete_user( $customer );
		get_user_by( 'id', get_current_user_id() )->add_cap( 'vkbm_manage_reservations' );
		$action = new \VKBookingManager\Bookings\Booking_Anonymize_Bulk_Action( new Booking_Anonymizer() );
		$action->handle_bulk_action( '', $action::ACTION, array( $bulk, $future ) );
		$this->assertSame( 'bulk', Booking_Anonymizer::get_anonymized_by( $bulk ) );
		$this->assertFalse( Booking_Anonymizer::is_anonymized( $future ) );
		if ( class_exists( \VKBookingManager\Bookings\Booking_Auto_Anonymizer::class ) && ! \VKBookingManager\Admin\Pro_Upsell::is_free_edition() ) {
			delete_option( 'vkbm_booking_auto_anonymize_lock' );
			delete_option( 'vkbm_booking_auto_anonymize_state' );
			update_option( 'vkbm_provider_settings', array( 'booking_auto_anonymize_months' => 3 ) );
			( new \VKBookingManager\Bookings\Booking_Auto_Anonymizer() )->run();
			$this->assertSame( 'auto', Booking_Anonymizer::get_anonymized_by( $auto ) );
			$this->assertFalse( Booking_Anonymizer::is_anonymized( $future ) );
			delete_option( 'vkbm_booking_auto_anonymize_state' );
		}
		$this->assertSame( array(), ( new Booking_User_Deletion() )->add_post_state( array(), get_post( $bulk ) ) );
	}

	/** 確認画面の集計は予約数が増えても、投稿本文の取得や予約ごとの SQL を行わない。 */
	public function test_summarize(): void {
		global $wpdb;
		$customer = self::factory()->user->create();
		$handler  = new Booking_User_Deletion();
		foreach ( array( 1, 30 ) as $number ) {
			for ( $i = 0; $i < $number; ++$i ) {
				$this->booking( $customer, '2000-01-01 12:00:00' );
			}
			wp_cache_flush();
			$queries = array();
			$capture = static function ( $query ) use ( &$queries ) {
				$queries[] = $query;
				return $query;
			};
			add_filter( 'query', $capture );
			$before = $wpdb->num_queries;
			$groups = $handler->summarize( array( $customer ) );
			$count  = $wpdb->num_queries - $before;
			remove_filter( 'query', $capture );
			$this->assertLessThanOrEqual( 12, $count, '予約数に比例する SQL を発行しない' );
			foreach ( $queries as $query ) {
				$this->assertStringNotContainsString( "SELECT {$wpdb->posts}.*", $query );
				$this->assertStringNotContainsString( "SELECT * FROM {$wpdb->posts}", $query );
			}
			$this->assertCount( 1 === $number ? 1 : 31, $groups['past'] );
		}
	}
	/**
	 * 通知の種類と翻訳済み本文を照合する。
	 *
	 * @param string $key 結果の種類。
	 * @param string $html 通知 HTML。
	 * @param int    $count 件数。
	 */
	private function assert_notice( string $key, string $html, int $count = 1 ): void {
		$expected = array(
			'anonymized'            => array( 'success', 'The personal data of %s reservation was anonymized, and the reservation was kept.' ),
			'anonymized_reassigned' => array( 'success', 'The personal data of %s reservation was anonymized.' ),
			'kept_past'             => array( 'info', '%s past reservation was kept with its personal data.' ),
			'reassigned'            => array( 'info', '%1$s reservation was moved to %2$s with its personal data intact.' ),
			'not_ended'             => array( 'warning', '%s reservation has not ended, so it was kept with its personal data.' ),
			'no_datetime'           => array( 'warning', '%s reservation has no date set, so it was not anonymized.' ),
			'staff'                 => array( 'info', '%s reservation of a user who can manage reservations was kept without anonymization.' ),
		);
		$this->assertStringContainsString( 'notice-' . $expected[ $key ][0], $html, $key );
		$plural  = array(
			'anonymized'            => 'The personal data of %s reservations was anonymized, and the reservations were kept.',
			'anonymized_reassigned' => 'The personal data of %s reservations was anonymized.',
			'kept_past'             => '%s past reservations were kept with their personal data.',
			'reassigned'            => '%1$s reservations were moved to %2$s with their personal data intact.',
			'not_ended'             => '%s reservations have not ended, so they were kept with their personal data.',
			'no_datetime'           => '%s reservations have no date set, so they were not anonymized.',
			'staff'                 => '%s reservations of users who can manage reservations were kept without anonymization.',
		);
		$message = sprintf( _n( $expected[ $key ][1], $plural[ $key ], $count, 'vk-booking-manager' ), number_format_i18n( $count ), '移動先' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralSingle, WordPress.WP.I18n.NonSingularStringLiteralPlural -- 通知の期待文をケース配列で照合するテスト。
		$this->assertStringContainsString( $message, wp_strip_all_tags( $html ), $key );
	}

	/** 選択欄が出ない削除と送信値省略でも、保持・割り当ての結果を通知する。 */
	public function test_delete_user_without_choice(): void {
		$target = self::factory()->user->create( array( 'display_name' => '移動先' ) );
		$cases  = array(
			array(
				'test_condition_name' => '終了前',
				'end'                 => '2099-01-01 12:00:00',
				'staff'               => false,
				'reassign'            => null,
				'expected'            => 'not_ended',
			),
			array(
				'test_condition_name' => '日時未設定',
				'end'                 => '',
				'staff'               => false,
				'reassign'            => null,
				'expected'            => 'no_datetime',
			),
			array(
				'test_condition_name' => 'スタッフ',
				'end'                 => '2000-01-01 12:00:00',
				'staff'               => true,
				'reassign'            => null,
				'expected'            => 'staff',
			),
			array(
				'test_condition_name' => '終了前を割り当て',
				'end'                 => '2099-01-01 12:00:00',
				'staff'               => false,
				'reassign'            => $target,
				'expected'            => 'reassigned',
			),
			array(
				'test_condition_name' => '過去予約の値省略',
				'end'                 => '2000-01-01 12:00:00',
				'staff'               => false,
				'reassign'            => null,
				'expected'            => 'kept_past',
			),
		);
		foreach ( $cases as $case ) {
			$customer = self::factory()->user->create();
			if ( $case['staff'] ) {
				get_user_by( 'id', $customer )->add_cap( 'vkbm_manage_reservations' );
			}
			$id    = $this->booking( $customer, $case['end'] );
			$_POST = array(
				'action'   => 'dodelete',
				'_wpnonce' => wp_create_nonce( 'delete-users' ),
			);
			if ( 'kept_past' !== $case['expected'] ) {
				ob_start();
				( new Booking_User_Deletion() )->render_confirmation( wp_get_current_user(), array( $customer ) );
				$this->assertStringNotContainsString( 'type="radio"', ob_get_clean(), $case['test_condition_name'] );
			}
			wp_delete_user( $customer, $case['reassign'] );
			ob_start();
			( new Booking_User_Deletion() )->render_notices();
			$html = ob_get_clean();
			$this->assert_notice( $case['expected'], $html );
			$this->assertStringNotContainsString( 'notice-success', $html );
			$this->assertFalse( Booking_Anonymizer::is_anonymized( $id ) );
		}
	}

	/** ネットワーク・画面外・別操作の nonce は匿名化も通知も許可しない。 */
	public function test_is_confirmed_screen_request(): void {
		foreach ( array( 'network', 'cli', 'bulk-users', 'valid-anonymize', 'valid-keep' ) as $kind ) {
			set_current_screen( 'network' === $kind ? 'users-network' : 'users' );
			$GLOBALS['pagenow'] = 'users.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- 画面を再現する。
			if ( 'cli' === $kind ) {
				unset( $GLOBALS['pagenow'] );
			}
			$_POST = array(
				'action'                 => 'dodelete',
				'_wpnonce'               => wp_create_nonce( 'bulk-users' === $kind ? 'bulk-users' : 'delete-users' ),
				'vkbm_booking_anonymize' => 'valid-keep' === $kind ? 'keep' : 'anonymize',
			);
			$valid = 0 === strpos( $kind, 'valid-' );
			$this->assertSame( $valid, Booking_User_Deletion::is_confirmed_screen_request(), $kind );
			$customer = self::factory()->user->create();
			$id       = $this->booking( $customer, '2000-01-01 12:00:00' );
			wp_delete_user( $customer );
			$this->assertSame( 'valid-anonymize' === $kind, Booking_Anonymizer::is_anonymized( $id ), $kind );
			$this->assertSame( $valid, is_array( get_transient( 'vkbm_user_deletion_' . get_current_user_id() ) ), $kind );
			delete_transient( 'vkbm_user_deletion_' . get_current_user_id() );
		}
	}

	/** nonce・ユーザー一覧権限・予約管理権限の不足では投稿者も印も変更しない。 */
	public function test_save_post_unauthorized(): void {
		$operator = get_current_user_id();
		$target   = self::factory()->user->create();
		foreach ( array( 'nonce', 'list_users', 'manage' ) as $missing ) {
			wp_set_current_user( $operator );
			$customer = self::factory()->user->create();
			$id       = $this->booking( $customer, '2000-01-01 12:00:00' );
			$_POST    = array();
			wp_delete_user( $customer );
			$marker = get_post_meta( $id, Booking_User_Deletion::META_AUTHOR_DELETED_AT, true );
			$user   = get_user_by( 'id', self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
			$user->add_cap( 'edit_posts' );
			if ( 'list_users' !== $missing ) {
				$user->add_cap( 'list_users' );
			}
			if ( 'manage' !== $missing ) {
				$user->add_cap( 'vkbm_manage_reservations' );
			}
			wp_set_current_user( $user->ID );
			$_POST = array(
				'_vkbm_booking_meta_nonce' => 'nonce' === $missing ? '' : wp_create_nonce( 'vkbm_booking_meta' ),
				'vkbm_booking'             => array(
					'author_id'  => $target,
					'date'       => '2026-10-10',
					'start_time' => '15:00',
					'end_time'   => '16:00',
					'status'     => 'cancelled',
				),
			);
			( new \VKBookingManager\Bookings\Booking_Admin() )->save_post( $id, get_post( $id ) );
			$this->assertSame( 0, (int) get_post( $id )->post_author, $missing );
			$this->assertSame( $marker, get_post_meta( $id, Booking_User_Deletion::META_AUTHOR_DELETED_AT, true ), $missing );
		}
		wp_set_current_user( $operator );
	}

	/** 投稿保存が失敗しても、両方の投稿者解除経路で DB 更新とキャッシュ破棄を行う。 */
	public function test_clear_post_author(): void {
		foreach ( array( 'retained', 'anonymized', 'normal' ) as $kind ) {
			$customer = self::factory()->user->create();
			$id       = $this->booking( $customer, '2000-01-01 12:00:00' );
			if ( 'anonymized' === $kind ) {
				update_post_meta( $id, Booking_Anonymizer::META_ANONYMIZED_AT, '2000-01-01 12:00:00' );
			}
			$reject = static function ( $empty, $postarr ) use ( $id, $kind ) {
				return 'normal' !== $kind && (int) ( $postarr['ID'] ?? 0 ) === $id ? true : $empty;
			};
			$_POST  = array();
			add_filter( 'wp_insert_post_empty_content', $reject, 10, 2 );
			try {
				( new Booking_User_Deletion() )->delete_user( $customer, self::factory()->user->create() );
			} finally {
				remove_filter( 'wp_insert_post_empty_content', $reject, 10 );
			}
			// retained は再割り当てを避けて、保持側の解除も実行する。
			if ( 'anonymized' !== $kind ) {
				add_filter( 'wp_insert_post_empty_content', $reject, 10, 2 );
				try {
					( new Booking_User_Deletion() )->delete_user( $customer );
				} finally {
					remove_filter( 'wp_insert_post_empty_content', $reject, 10 );
				}
			}
			$this->assertSame( 0, (int) get_post( $id )->post_author, $kind );
			wp_delete_user( $customer, self::factory()->user->create() );
			$this->assertSame( 0, (int) get_post( $id )->post_author, $kind );
		}
	}

	/** 未知の結果は表示せず、重要度の順序と予約タイトルの閲覧権限を守る。 */
	public function test_render_notices(): void {
		$handler = new Booking_User_Deletion();
		$id      = $this->booking( self::factory()->user->create(), '2099-01-01 12:00:00' );
		foreach ( array( false, true ) as $can_manage ) {
			if ( $can_manage ) {
				wp_get_current_user()->add_cap( 'vkbm_manage_reservations' );
			} else {
				wp_get_current_user()->add_cap( 'vkbm_manage_reservations', false );
			}
			$counts = array(
				'unknown'                              => 1,
				'staff'                                => 1,
				'kept_past'                            => 1,
				'reassigned_123'                       => 1,
				'anonymized_reassigned'                => 1,
				'anonymized'                           => 1,
				Booking_Anonymizer::RESULT_NO_DATETIME => 1,
				Booking_Anonymizer::RESULT_NOT_ENDED   => 1,
				Booking_Anonymizer::RESULT_FAILED      => 1,
				'_links'                               => array( Booking_Anonymizer::RESULT_NOT_ENDED => array( $id ) ),
			);
			set_transient( 'vkbm_user_deletion_' . get_current_user_id(), $counts );
			ob_start();
			$handler->render_notices();
			$html = ob_get_clean();
			$this->assertSame( 8, substr_count( $html, '<div class="notice ' ) );
			preg_match_all( '/notice notice-([a-z]+)/', $html, $matches );
			$this->assertSame( array( 'error', 'warning', 'warning', 'success', 'success', 'info', 'info', 'info' ), $matches[1] );
			$positions = array();
			foreach ( array( 'not_ended', 'no_datetime', 'anonymized', 'anonymized_reassigned', 'reassigned', 'kept_past', 'staff' ) as $key ) {
				ob_start();
				set_transient( 'vkbm_user_deletion_' . get_current_user_id(), array( 'reassigned' === $key ? 'reassigned_123' : $key => 1 ) );
				$handler->render_notices();
				$single = ob_get_clean();
				preg_match( '/<p>(.*?)<\/p>/', $single, $paragraph );
				$positions[] = strpos( $html, $paragraph[0] );
			}
			$sorted = $positions;
			sort( $sorted );
			$this->assertSame( $sorted, $positions, '同じ通知種別の中でも順序を固定する' );
			$this->assertSame( $can_manage, str_contains( $html, 'post=' . $id ), $html );
			$this->assertSame( $can_manage, str_contains( $html, '予約者 / 日時' ) );
			$this->assertSame( $can_manage, str_contains( $html, 'edit.php?post_type=vkbm_booking' ) );
			// 保存失敗・終了前・過去の通知でも、権限のない操作を案内しない。
			foreach ( array(
				__( 'Run "Anonymize personal data" from the reservation list.', 'vk-booking-manager' ),
				_n( 'After it ends, you can anonymize it from the reservation list.', 'After they end, you can anonymize them from the reservation list.', 1, 'vk-booking-manager' ),
				_n( 'You can anonymize it later from the reservation list.', 'You can anonymize them later from the reservation list.', 1, 'vk-booking-manager' ),
			) as $help ) {
				$this->assertSame( $can_manage, str_contains( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ), $help ), $help );
			}
		}
	}
}
