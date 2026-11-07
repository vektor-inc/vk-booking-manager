<?php
/**
 * ユーザー削除時に予約を残し、明示された場合だけ個人情報を匿名化する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );
namespace VKBookingManager\Bookings;

use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\Assets\Common_Styles;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Attendees\Booking_Attendee_Resolver;

/** ユーザー削除の確認・処理・結果通知。 */
class Booking_User_Deletion {
	/** 削除済みの投稿者を示す日時（UTC）。 */
	public const META_AUTHOR_DELETED_AT = '_vkbm_booking_author_deleted_at';

	/**
	 * 匿名化の処理本体。
	 *
	 * @var Booking_Anonymizer
	 */
	private $anonymizer;

	/**
	 * 処理本体を受け取る。
	 *
	 * @param Booking_Anonymizer|null $anonymizer 匿名化の処理本体。
	 */
	public function __construct( ?Booking_Anonymizer $anonymizer = null ) {
		$this->anonymizer = $anonymizer ?? new Booking_Anonymizer();
	}

	/** フックを登録する。 */
	public function register(): void {
		add_action( 'delete_user', array( $this, 'delete_user' ), 10, 2 );
		add_action( 'delete_user_form', array( $this, 'render_confirmation' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
		add_filter( 'display_post_states', array( $this, 'add_post_state' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
	}

	/**
	 * 通常のユーザー管理画面からの正しい削除操作かを判定する。
	 *
	 * @return bool 画面・操作・権限・nonce が正しいか。
	 */
	public static function is_confirmed_screen_request(): bool {
		global $pagenow;
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ! is_admin() || is_network_admin() || 'users.php' !== $pagenow || ! current_user_can( 'delete_users' ) ) {
			return false;
		}
		// 本体の削除操作専用の nonce だけを受け付ける。
		$action = isset( $_POST['action'] ) && is_string( $_POST['action'] ) ? wp_unslash( $_POST['action'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 許可値と厳密に比較する。
		$nonce  = isset( $_POST['_wpnonce'] ) && is_string( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		return 'dodelete' === $action && (bool) wp_verify_nonce( $nonce, 'delete-users' );
	}

	/**
	 * 検証済み画面の匿名化指定を取得する。未指定・不正な値は匿名化しない。
	 *
	 * @return string anonymize / keep / 空文字（画面外）。
	 */
	public static function get_validated_choice(): string {
		if ( ! self::is_confirmed_screen_request() ) {
			return '';
		}
		$value = isset( $_POST['vkbm_booking_anonymize'] ) && is_string( $_POST['vkbm_booking_anonymize'] ) ? wp_unslash( $_POST['vkbm_booking_anonymize'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- 直前の is_confirmed_screen_request() で nonce 検証済み。許可値と厳密に比較する。
		return 'anonymize' === $value ? 'anonymize' : 'keep';
	}

	/**
	 * 投稿者で予約を検索する。確認画面では本文を読み込まず、メタをまとめてキャッシュする。
	 *
	 * @param int[] $user_ids 削除するユーザー ID。
	 * @return object[] ID と post_author を持つ行。
	 */
	private function find_bookings( array $user_ids ): array {
		global $wpdb;
		$user_ids = array_filter( array_map( 'absint', $user_ids ) );
		if ( ! $user_ids ) {
			return array();
		}
		$ids = implode( ',', $user_ids );
		// ID は absint 済み。本体と同じくステータスで対象を制限しない。本文やタイトルを集計用に取得しない。
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_author FROM {$wpdb->posts} WHERE post_type = %s AND post_author IN ($ids) ORDER BY ID", Booking_Post_Type::POST_TYPE ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- 実行直前の対象を取得し、投稿本文を読み込まない。
		update_meta_cache( 'post', array_map( 'intval', wp_list_pluck( $rows, 'ID' ) ) );
		return $rows;
	}

	/**
	 * 対象の予約を保持する。割り当ての有無は WordPress の引数で判断する。
	 *
	 * @param int      $user_id 削除するユーザー。
	 * @param int|null $reassign 割り当て先。
	 */
	public function delete_user( int $user_id, ?int $reassign = null ): void {
		$user   = get_userdata( $user_id );
		$staff  = $user && Reserver_Matcher::is_staff( $user );
		$choice = self::get_validated_choice();
		$counts = array();
		$links  = array();
		foreach ( $this->find_bookings( array( $user_id ) ) as $row ) {
			$id     = (int) $row->ID;
			$result = $this->anonymizer->check_anonymizable( $id );
			if ( ! $staff && 'anonymize' === $choice && Booking_Anonymizer::RESULT_ANONYMIZED === $result ) {
				$result = $this->anonymizer->anonymize( $id, Booking_Anonymizer::ANONYMIZED_BY_USER_DELETION );
				if ( Booking_Anonymizer::RESULT_ANONYMIZED === $result ) {
					$key            = null === $reassign ? 'anonymized' : 'anonymized_reassigned';
					$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
					continue;
				}
			}
			// 匿名化済みの投稿者は 0 のままにし、本体の割り当て対象からも外す。
			if ( Booking_Anonymizer::is_anonymized( $id ) ) {
				$this->clear_post_author( $id );
				continue;
			}
			if ( null !== $reassign && Booking_Anonymizer::RESULT_FAILED !== $result ) {
				update_post_meta( $id, Booking_Attendee_Resolver::META_ID, Booking_Attendee_Resolver::ID_MANUAL );
				$key = 'reassigned_' . $reassign;
			} else {
				// 先に印を付け、WordPress が投稿者 0 を実行者へ補うのを防ぐ。
				update_post_meta( $id, self::META_AUTHOR_DELETED_AT, gmdate( 'Y-m-d H:i:s' ) );
				$this->clear_post_author( $id );
				$key = $staff ? 'staff' : ( Booking_Anonymizer::RESULT_ANONYMIZED === $result ? 'kept_past' : $result );
			}
			$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
			if ( in_array( $key, array( Booking_Anonymizer::RESULT_FAILED, Booking_Anonymizer::RESULT_NOT_ENDED ), true ) ) {
				$links[ $key ][] = $id;
			}
		}
		// 選択欄がない場合も、正しい画面操作なら保持した結果を通知する。
		if ( self::is_confirmed_screen_request() && $counts ) {
			$key   = 'vkbm_user_deletion_' . get_current_user_id();
			$saved = get_transient( $key );
			$saved = is_array( $saved ) ? $saved : array();
			foreach ( $counts as $result => $count ) {
				$saved[ $result ] = ( $saved[ $result ] ?? 0 ) + $count;
			}
			foreach ( $links as $result => $ids ) {
				$saved['_links'][ $result ] = array_merge( $saved['_links'][ $result ] ?? array(), $ids );
			}
			set_transient( $key, $saved, 300 );
		}
	}

	/**
	 * 投稿者を外し、通常の保存が失敗した場合は DB を直接更新する。
	 *
	 * @param int $id 予約 ID。
	 */
	private function clear_post_author( int $id ): void {
		global $wpdb;
		$result = wp_update_post(
			array(
				'ID'          => $id,
				'post_author' => 0,
			),
			true
		);
		if ( is_wp_error( $result ) || ! $result ) {
			// 保存フィルターなどが拒否しても、本体による削除・再割り当ての対象から外す。
			$wpdb->update( $wpdb->posts, array( 'post_author' => 0 ), array( 'ID' => $id ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- 投稿者を外すための保存失敗時の後処理。
			clean_post_cache( $id );
		}
	}

	/**
	 * 削除済みユーザーの予約に一覧の状態表示を加える。
	 *
	 * @param array    $states 状態表示。
	 * @param \WP_Post $post 予約。
	 * @return array 状態表示。
	 */
	public function add_post_state( array $states, \WP_Post $post ): array {
		if ( Booking_Post_Type::POST_TYPE === $post->post_type && self::has_deleted_author( $post->ID ) ) {
			$states['vkbm_author_deleted'] = __( 'User deleted', 'vk-booking-manager' );
		}
		return $states;
	}

	/**
	 * 匿名化表示に優先させず、ユーザー削除の印を判定する。
	 *
	 * @param int $id 予約 ID。
	 * @return bool 削除済みか。
	 */
	public static function has_deleted_author( int $id ): bool {
		return ! Booking_Anonymizer::is_anonymized( $id ) && '' !== (string) get_post_meta( $id, self::META_AUTHOR_DELETED_AT, true );
	}

	/**
	 * ユーザー管理画面に必要なスタイルだけを読み込む。
	 *
	 * @param string $hook 管理画面。
	 */
	public function enqueue_styles( string $hook ): void {
		if ( 'users.php' === $hook && ! is_network_admin() ) {
			wp_enqueue_style( Common_Styles::ADMIN_HANDLE );
		}
	}

	/**
	 * 確認画面の集計を取得する。処理本体と同じメタの判定を使う。
	 *
	 * @param int[] $user_ids 削除するユーザー。
	 * @return array 分類ごとの予約 ID。
	 */
	public function summarize( array $user_ids ): array {
		$groups   = array(
			'past'    => array(),
			'pending' => array(),
			'staff'   => array(),
		);
		$user_ids = array_filter( array_map( 'absint', $user_ids ) );
		if ( ! $user_ids ) {
			return $groups;
		}
		// 権限判定に必要なユーザーも一度に読み込む。
		$users = get_users( array( 'include' => array_map( 'absint', $user_ids ) ) );
		$staff = array();
		foreach ( $users as $user ) {
			$staff[ $user->ID ] = Reserver_Matcher::is_staff( $user );
		}
		foreach ( $this->find_bookings( $user_ids ) as $row ) {
			$id                 = (int) $row->ID;
			$result             = Booking_Anonymizer::check_booking_metadata( $id );
			$group              = ! empty( $staff[ $row->post_author ] ) ? 'staff' : ( Booking_Anonymizer::RESULT_ANONYMIZED === $result ? 'past' : 'pending' );
			$groups[ $group ][] = $id;
		}
		return $groups;
	}

	/**
	 * 本体と同じく投稿・リンク・追加コンテンツで割り当て選択欄の有無を調べる。
	 *
	 * @param int[] $user_ids 削除するユーザー。
	 * @return bool 割り当て選択欄があるか。
	 */
	private function has_content_choices( array $user_ids ): bool {
		global $wpdb;
		if ( apply_filters( 'users_have_additional_content', false, $user_ids ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- 本体の確認画面と同じ判定を使う。
			return true;
		}
		$ids = implode( ',', array_map( 'absint', $user_ids ) );
		if ( '' === $ids ) {
			return false;
		}
		// 6.9 / 7.1 の本体は delete_with_user によらず、投稿かリンクがあれば選択欄を出す。
		return (bool) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_author IN ($ids) LIMIT 1" ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- 実行直前の対象を取得し、投稿本文を読み込まない。
			|| (bool) $wpdb->get_var( "SELECT link_id FROM {$wpdb->links} WHERE link_owner IN ($ids) LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- 実行直前の対象を取得し、投稿本文を読み込まない。
	}

	/**
	 * 確認画面の予約案内を描画する。
	 *
	 * @param \WP_User $current_user 操作するユーザー。
	 * @param int[]    $user_ids 削除対象。
	 */
	public function render_confirmation( $current_user, array $user_ids ): void {
		if ( is_network_admin() || ! current_user_can( 'delete_users' ) ) {
			return;
		}
		// 本体が削除しない自分自身を集計から除く。
		$user_ids = array_values( array_diff( array_map( 'absint', $user_ids ), array( get_current_user_id(), 0 ) ) );
		if ( ! $user_ids ) {
			return;
		}
		$groups = $this->summarize( $user_ids );
		$total  = array_sum( array_map( 'count', $groups ) );
		if ( 0 === $total ) {
			return;
		}
		$past = count( $groups['past'] );
		?>
		<div class="notice notice-warning inline vkbm-user-deletion">
			<h2><?php echo esc_html( _n( 'Reservations of this user', 'Reservations of these users', count( $user_ids ), 'vk-booking-manager' ) ); ?></h2>
			<p>
			<?php
			/* translators: %s: Number of reservations. */
			echo esc_html( sprintf( _n( '%s reservation in total.', '%s reservations in total.', $total, 'vk-booking-manager' ), number_format_i18n( $total ) ) );
			echo ' ' . esc_html( _n( 'It is kept, whichever options you choose.', 'All of them are kept, whichever options you choose.', $total, 'vk-booking-manager' ) );
			if ( $past ) {
				/* translators: %s: Number of reservations. */
				echo ' ' . esc_html( sprintf( __( 'Past reservations that can be anonymized: %s', 'vk-booking-manager' ), number_format_i18n( $past ) ) );
			}
			?>
			</p>
			<?php if ( $past ) : ?>
			<fieldset aria-describedby="vkbm-user-deletion-fields vkbm-user-deletion-help">
				<legend><?php echo esc_html( _n( 'Anonymize the past reservations of the user being deleted?', 'Anonymize the past reservations of the users being deleted?', count( $user_ids ), 'vk-booking-manager' ) ); ?></legend>
				<div class="vkbm-user-deletion__choices">
					<label><input type="radio" name="vkbm_booking_anonymize" value="anonymize" required /><?php esc_html_e( 'Anonymize', 'vk-booking-manager' ); ?></label>
					<label><input type="radio" name="vkbm_booking_anonymize" value="keep" required /><?php esc_html_e( 'Do not anonymize', 'vk-booking-manager' ); ?></label>
				</div>
				<p id="vkbm-user-deletion-fields">
				<?php
				/* translators: %s: Personal data fields. */
				echo esc_html( sprintf( __( '* %s will be anonymized.', 'vk-booking-manager' ), Booking_Anonymizer::get_field_labels_text() ) );
				echo ' ' . esc_html__( 'This cannot be undone.', 'vk-booking-manager' );
				echo ' ' . esc_html__( 'Reservations that have not ended are not anonymized.', 'vk-booking-manager' );
				?>
				</p>
				<p id="vkbm-user-deletion-help">
				<?php
				esc_html_e( 'Dates, menus, prices, and reservation statuses stay, so sales and booking totals do not change.', 'vk-booking-manager' );
				if ( current_user_can( Capabilities::MANAGE_RESERVATIONS ) ) {
					echo ' ' . wp_kses_post( self::link_list( __( 'Reservations you do not anonymize now can be anonymized later from the reservation list.', 'vk-booking-manager' ) ) );
				}
				?>
				</p>
			</fieldset>
			<?php endif; ?>
			<?php if ( $this->has_content_choices( $user_ids ) ) : ?>
			<p>
				<?php
				if ( $past ) {
					esc_html_e( 'If you attribute content to another user: anonymized reservations are not moved.', 'vk-booking-manager' );
					echo ' ' . esc_html__( "All other reservations move with their personal data and appear on that user's My Page.", 'vk-booking-manager' );
				} else {
					esc_html_e( "If you attribute content to another user: all reservations move with their personal data and appear on that user's My Page.", 'vk-booking-manager' );
				}
				?>
			</p>
			<?php endif; ?>
			<?php if ( $groups['pending'] ) : ?>
			<div><p>
				<?php
				$count = count( $groups['pending'] );
				/* translators: %s: Number of reservations. */
				echo esc_html( sprintf( _n( '%s reservation has not ended or has no date.', '%s reservations have not ended or have no date.', $count, 'vk-booking-manager' ), number_format_i18n( $count ) ) );
				echo ' ' . esc_html( _n( 'The customer can no longer check or cancel it from My Page, so contact the customer if needed.', 'The customer can no longer check or cancel them from My Page, so contact the customer if needed.', $count, 'vk-booking-manager' ) );
				?>
			</p><?php $this->render_booking_links( $groups['pending'] ); ?></div>
			<?php endif; ?>
			<?php if ( $groups['staff'] ) : ?>
			<p>
				<?php
				$count = count( $groups['staff'] );
				/* translators: %s: Number of reservations. */
				echo esc_html( sprintf( _n( '%s reservation of a user who can manage reservations is not anonymized, because it may be a reservation entered for another customer.', '%s reservations of users who can manage reservations are not anonymized, because they may include reservations entered for other customers.', $count, 'vk-booking-manager' ), number_format_i18n( $count ) ) );
				?>
			</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * 予約一覧の文言をリンクにする。
	 *
	 * @param string $message 翻訳済みの文。
	 * @return string 安全な HTML。
	 */
	public static function link_list( string $message ): string {
		if ( ! current_user_can( Capabilities::MANAGE_RESERVATIONS ) ) {
			return esc_html( $message );
		}
		$label = __( 'reservation list', 'vk-booking-manager' );
		return str_replace( esc_html( $label ), '<a href="' . esc_url( admin_url( 'edit.php?post_type=vkbm_booking' ) ) . '">' . esc_html( $label ) . '</a>', esc_html( $message ) );
	}

	/**
	 * 予約タイトルを最大 10 件リンクで並べる。
	 *
	 * @param int[] $ids 予約 ID。
	 */
	private function render_booking_links( array $ids ): void {
		if ( ! current_user_can( Capabilities::MANAGE_RESERVATIONS ) ) {
			return;
		}
		// 表示する 10 件だけ投稿をまとめて読み込む。
		$posts = get_posts(
			array(
				'post_type'      => Booking_Post_Type::POST_TYPE,
				'post_status'    => array_values( get_post_stati() ),
				'post__in'       => array_slice( $ids, 0, 10 ),
				'posts_per_page' => 10,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		echo '<ul>';
		foreach ( $posts as $post ) {
			echo '<li><a href="' . esc_url( get_edit_post_link( $post->ID ) ) . '">' . esc_html( $post->post_title ) . '</a></li>';
		}
		if ( count( $ids ) > 10 ) {
			/* translators: %s: Number of additional reservations. */
			echo '<li>' . esc_html( sprintf( __( 'and %s more', 'vk-booking-manager' ), number_format_i18n( count( $ids ) - 10 ) ) ) . '</li>';
		}
		echo '</ul>';
	}

	/** ユーザー管理画面で結果を一度だけ表示する。 */
	public function render_notices(): void {
		global $pagenow;
		if ( 'users.php' !== $pagenow || is_network_admin() || ! current_user_can( 'delete_users' ) ) {
			return;
		}
		$key    = 'vkbm_user_deletion_' . get_current_user_id();
		$counts = get_transient( $key );
		if ( ! is_array( $counts ) ) {
			return;
		}
		delete_transient( $key );
		$links = $counts['_links'] ?? array();
		unset( $counts['_links'] );
		// 失敗・警告を先に示し、予約の作成順で重要な通知が埋もれるのを防ぐ。
		$priority = array_flip( array( Booking_Anonymizer::RESULT_FAILED, Booking_Anonymizer::RESULT_NOT_ENDED, Booking_Anonymizer::RESULT_NO_DATETIME, 'anonymized', 'anonymized_reassigned', 'reassigned', 'kept_past', 'staff' ) );
		uksort(
			$counts,
			static function ( $a, $b ) use ( $priority ) {
				$a_group    = 0 === strpos( $a, 'reassigned_' ) ? 'reassigned' : $a;
				$b_group    = 0 === strpos( $b, 'reassigned_' ) ? 'reassigned' : $b;
				$comparison = ( $priority[ $a_group ] ?? PHP_INT_MAX ) <=> ( $priority[ $b_group ] ?? PHP_INT_MAX );
				return 0 !== $comparison ? $comparison : strcmp( $a, $b );
			}
		);
		foreach ( $counts as $result => $count ) {
			$type = 'info';
			if ( 'anonymized' === $result ) {
				$type = 'success';
				/* translators: %s: Number of reservations. */
				$message = sprintf( _n( 'The personal data of %s reservation was anonymized, and the reservation was kept.', 'The personal data of %s reservations was anonymized, and the reservations were kept.', $count, 'vk-booking-manager' ), number_format_i18n( $count ) );
			} elseif ( 'anonymized_reassigned' === $result ) {
				$type = 'success';
				/* translators: %s: Number of reservations. */
				$message  = sprintf( _n( 'The personal data of %s reservation was anonymized.', 'The personal data of %s reservations was anonymized.', $count, 'vk-booking-manager' ), number_format_i18n( $count ) );
				$message .= ' ' . _n( 'It was kept, but not moved to the assigned user.', 'They were kept, but not moved to the assigned user.', $count, 'vk-booking-manager' );
			} elseif ( 0 === strpos( $result, 'reassigned_' ) ) {
				$user = get_userdata( (int) substr( $result, strlen( 'reassigned_' ) ) );
				/* translators: 1: Number of reservations, 2: Assigned user's name. */
				$message = sprintf( _n( '%1$s reservation was moved to %2$s with its personal data intact.', '%1$s reservations were moved to %2$s with their personal data intact.', $count, 'vk-booking-manager' ), number_format_i18n( $count ), $user ? $user->display_name : '' );
			} elseif ( 'staff' === $result ) {
				/* translators: %s: Number of reservations. */
				$message  = sprintf( _n( '%s reservation of a user who can manage reservations was kept without anonymization.', '%s reservations of users who can manage reservations were kept without anonymization.', $count, 'vk-booking-manager' ), number_format_i18n( $count ) );
				$message .= ' ' . _n( 'It may be a reservation entered for another customer.', 'They may include reservations entered for other customers.', $count, 'vk-booking-manager' );
			} elseif ( Booking_Anonymizer::RESULT_FAILED === $result ) {
				$type = 'error';
				/* translators: %s: Number of reservations. */
				$message = sprintf( _n( '%s reservation could not be anonymized because saving failed.', '%s reservations could not be anonymized because saving failed.', $count, 'vk-booking-manager' ), number_format_i18n( $count ) );
				if ( current_user_can( Capabilities::MANAGE_RESERVATIONS ) ) {
					$message .= ' ' . __( 'Run "Anonymize personal data" from the reservation list.', 'vk-booking-manager' );
				}
			} elseif ( Booking_Anonymizer::RESULT_NO_DATETIME === $result ) {
				$type = 'warning';
				/* translators: %s: Number of reservations. */
				$message = sprintf( _n( '%s reservation has no date set, so it was not anonymized.', '%s reservations have no date set, so they were not anonymized.', $count, 'vk-booking-manager' ), number_format_i18n( $count ) );
			} elseif ( Booking_Anonymizer::RESULT_NOT_ENDED === $result ) {
				$type = 'warning';
				/* translators: %s: Number of reservations. */
				$message = sprintf( _n( '%s reservation has not ended, so it was kept with its personal data.', '%s reservations have not ended, so they were kept with their personal data.', $count, 'vk-booking-manager' ), number_format_i18n( $count ) );
				if ( current_user_can( Capabilities::MANAGE_RESERVATIONS ) ) {
					$message .= ' ' . _n( 'After it ends, you can anonymize it from the reservation list.', 'After they end, you can anonymize them from the reservation list.', $count, 'vk-booking-manager' );
				}
			} elseif ( 'kept_past' === $result ) {
				/* translators: %s: Number of reservations. */
				$message = sprintf( _n( '%s past reservation was kept with its personal data.', '%s past reservations were kept with their personal data.', $count, 'vk-booking-manager' ), number_format_i18n( $count ) );
				if ( current_user_can( Capabilities::MANAGE_RESERVATIONS ) ) {
					$message .= ' ' . _n( 'You can anonymize it later from the reservation list.', 'You can anonymize them later from the reservation list.', $count, 'vk-booking-manager' );
				}
			} else {
				continue;
			}
			echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . wp_kses_post( self::link_list( $message ) ) . '</p>';
			if ( ! empty( $links[ $result ] ) ) {
				$this->render_booking_links( $links[ $result ] );
			}
			echo '</div>';
		}
	}
}
