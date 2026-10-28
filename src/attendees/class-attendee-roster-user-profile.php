<?php
/**
 * ユーザー編集画面の名簿編集欄。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Attendees;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use WP_User;

/**
 * スタッフ向けに名簿を表示し、「ユーザーを更新」で保存する。
 */
class Attendee_Roster_User_Profile {
	private const NONCE_ACTION = 'vkbm_attendee_roster_user_profile';
	private const NONCE_NAME   = '_vkbm_attendee_roster_nonce';

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
	 * フックを登録する。
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'show_user_profile', array( $this, 'render' ) );
		add_action( 'edit_user_profile', array( $this, 'render' ) );
		add_action( 'personal_options_update', array( $this, 'save' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save' ) );
		add_action( 'admin_notices', array( $this, 'render_duplicate_notice' ) );
	}

	/**
	 * 指定ユーザーの名簿を編集できるか返す。
	 *
	 * @param int $user_id ユーザー ID。
	 * @return bool 編集できる場合 true。
	 */
	public function can_edit( int $user_id ): bool {
		return current_user_can( Capabilities::MANAGE_RESERVATIONS ) && current_user_can( 'edit_user', $user_id );
	}

	/**
	 * 名簿編集欄を描画する。
	 *
	 * @param WP_User $user 編集対象ユーザー。
	 * @return void
	 */
	public function render( WP_User $user ): void {
		if ( ! $this->can_edit( $user->ID ) ) {
			return;
		}
		$settings = ( new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() ) )->get_settings();
		$label    = trim( (string) ( $settings['attendee_roster_label'] ?? '' ) );
		$label    = '' !== $label ? $label : __( 'People receiving the service', 'vk-booking-manager' );
		if ( empty( $settings['attendee_roster_enabled'] ) ) {
			printf( '<h2>%1$s</h2><p class="description">%2$s</p>', esc_html( $label ), esc_html__( 'The saved list feature is off. Saved entries are retained.', 'vk-booking-manager' ) );
			return;
		}
		$saved_rows    = $this->roster->get( $user->ID );
		$rows          = $this->ensure_self( $user, $saved_rows );
		$show_kana     = ! empty( $settings['attendee_roster_kana_enabled'] );
		$show_number   = ! empty( $settings['attendee_number_enabled'] );
		$kana_label    = trim( (string) ( $settings['attendee_roster_kana_label'] ?? '' ) );
		$number_label  = trim( (string) ( $settings['attendee_number_label'] ?? '' ) );
		$original_json = wp_json_encode( $saved_rows );
		$duplicates    = $show_number ? $this->roster->find_duplicate_number_users( $user->ID, $rows ) : array();
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		// phpcs:disable WordPress.WP.I18n.MissingTranslatorsComment -- 各プレースホルダーには同じ行で対象の名前または上限値を渡す。
		?>
		<h2><?php echo esc_html( $label ); ?></h2>
		<p class="description"><?php esc_html_e( 'Edit names and customer numbers, then click Update User. Past reservation records are not changed when an entry is deleted.', 'vk-booking-manager' ); ?></p>
		<p class="description"><?php printf( esc_html__( 'Enter a blank row to add an entry (up to %d entries).', 'vk-booking-manager' ), esc_html( (string) Attendee_Roster::MAX_ATTENDEES ) ); ?></p>
		<input type="hidden" name="vkbm_attendees_original" value="<?php echo esc_attr( false !== $original_json ? $original_json : '[]' ); ?>">
		<table class="widefat striped vkbm-attendee-roster-table" id="vkbm-attendee-roster">
			<thead><tr><th><?php esc_html_e( 'Name', 'vk-booking-manager' ); ?></th>
			<?php
			if ( $show_kana ) :
				?>
				<th><?php echo esc_html( '' !== $kana_label ? $kana_label : __( 'Furigana', 'vk-booking-manager' ) ); ?></th><?php endif; ?>
				<?php
				if ( $show_number ) :
					?>
				<th><?php echo esc_html( '' !== $number_label ? $number_label : __( 'Customer number', 'vk-booking-manager' ) ); ?></th><?php endif; ?><th><?php esc_html_e( 'Delete', 'vk-booking-manager' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $rows as $index => $row ) : ?>
				<tr>
					<td data-colname="<?php esc_attr_e( 'Name', 'vk-booking-manager' ); ?>"><input type="hidden" name="vkbm_attendees[<?php echo esc_attr( (string) $index ); ?>][id]" value="<?php echo esc_attr( $row['id'] ); ?>"><input type="text" class="regular-text" aria-label="<?php echo esc_attr( sprintf( __( 'Name: %s', 'vk-booking-manager' ), $row['name'] ) ); ?>" name="vkbm_attendees[<?php echo esc_attr( (string) $index ); ?>][name]" value="<?php echo esc_attr( $row['name'] ); ?>" required <?php wp_readonly( 'self' === $row['id'] ); ?>>
					<?php
					if ( ! $show_kana ) :
						?>
						<input type="hidden" name="vkbm_attendees[<?php echo esc_attr( (string) $index ); ?>][kana]" value="<?php echo esc_attr( $row['kana'] ); ?>"><?php endif; ?>
						<?php
						if ( ! $show_number ) :
							?>
						<input type="hidden" name="vkbm_attendees[<?php echo esc_attr( (string) $index ); ?>][number]" value="<?php echo esc_attr( $row['number'] ); ?>"><?php endif; ?></td>
					<?php
					if ( $show_kana ) :
						?>
						<td data-colname="<?php echo esc_attr( '' !== $kana_label ? $kana_label : __( 'Furigana', 'vk-booking-manager' ) ); ?>"><input type="text" class="regular-text" aria-label="<?php echo esc_attr( sprintf( __( 'Furigana: %s', 'vk-booking-manager' ), $row['name'] ) ); ?>" name="vkbm_attendees[<?php echo esc_attr( (string) $index ); ?>][kana]" value="<?php echo esc_attr( $row['kana'] ); ?>" <?php wp_readonly( 'self' === $row['id'] ); ?>></td>
						<?php endif; ?>
					<?php
					if ( $show_number ) :
						?>
						<td data-colname="<?php echo esc_attr( '' !== $number_label ? $number_label : __( 'Customer number', 'vk-booking-manager' ) ); ?>"><input type="text" class="regular-text" aria-label="<?php echo esc_attr( sprintf( __( 'Number: %s', 'vk-booking-manager' ), $row['name'] ) ); ?>" name="vkbm_attendees[<?php echo esc_attr( (string) $index ); ?>][number]" value="<?php echo esc_attr( $row['number'] ); ?>"<?php echo isset( $duplicates[ Attendee_Roster::normalize_number( $row['number'] ) ] ) ? ' aria-describedby="vkbm-attendee-number-duplicate-' . esc_attr( (string) $index ) . '"' : ''; ?>>
						<?php $this->render_duplicate_description( $duplicates[ Attendee_Roster::normalize_number( $row['number'] ) ] ?? null, 'vkbm-attendee-number-duplicate-' . $index ); ?></td>
						<?php endif; ?>
					<td data-colname="<?php esc_attr_e( 'Delete', 'vk-booking-manager' ); ?>">
					<?php
					if ( 'self' === $row['id'] ) :
						?>
						<?php esc_html_e( 'Yourself', 'vk-booking-manager' ); ?>
						<?php
else :
	?>
						<label><input type="checkbox" aria-label="<?php echo esc_attr( sprintf( __( 'Delete %s', 'vk-booking-manager' ), $row['name'] ) ); ?>" name="vkbm_attendees[<?php echo esc_attr( (string) $index ); ?>][delete]" value="1"> <?php esc_html_e( 'Delete', 'vk-booking-manager' ); ?></label><?php endif; ?></td>
				</tr>
			<?php endforeach; ?>
			<?php $empty_row_limit = min( count( $rows ) + 3, Attendee_Roster::MAX_ATTENDEES + 1 ); ?>
			<?php for ( $index = count( $rows ); $index < $empty_row_limit; $index++ ) : ?>
				<tr><td data-colname="<?php esc_attr_e( 'Name', 'vk-booking-manager' ); ?>"><input type="hidden" name="vkbm_attendees[<?php echo esc_attr( (string) $index ); ?>][id]" value=""><input type="text" class="regular-text" name="vkbm_attendees[<?php echo esc_attr( (string) $index ); ?>][name]" value="" aria-label="<?php esc_attr_e( 'Name for a new entry', 'vk-booking-manager' ); ?>"></td>
				<?php
				if ( $show_kana ) :
					?>
					<td data-colname="<?php echo esc_attr( '' !== $kana_label ? $kana_label : __( 'Furigana', 'vk-booking-manager' ) ); ?>"><input type="text" class="regular-text" name="vkbm_attendees[<?php echo esc_attr( (string) $index ); ?>][kana]" value="" aria-label="<?php esc_attr_e( 'Furigana for a new entry', 'vk-booking-manager' ); ?>"></td><?php endif; ?>
					<?php
					if ( $show_number ) :
						?>
					<td data-colname="<?php echo esc_attr( '' !== $number_label ? $number_label : __( 'Customer number', 'vk-booking-manager' ) ); ?>"><input type="text" class="regular-text" name="vkbm_attendees[<?php echo esc_attr( (string) $index ); ?>][number]" value="" aria-label="<?php esc_attr_e( 'Number for a new entry', 'vk-booking-manager' ); ?>"></td><?php endif; ?><td class="vkbm-attendee-roster-table__empty-action"></td></tr>
			<?php endfor; ?>
			</tbody>
		</table>
		<?php // phpcs:enable WordPress.WP.I18n.MissingTranslatorsComment ?>
		<?php
	}

	/**
	 * 送信された名簿を保存する。
	 *
	 * @param int $user_id 編集対象ユーザー ID。
	 * @return void
	 */
	public function save( int $user_id ): void {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) || ! $this->can_edit( $user_id ) ) {
			return;
		}
		$settings = ( new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() ) )->get_settings();
		if ( empty( $settings['attendee_roster_enabled'] ) ) {
			return;
		}
		$posted = isset( $_POST['vkbm_attendees'] ) && is_array( $_POST['vkbm_attendees'] ) ? wp_unslash( $_POST['vkbm_attendees'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 各値はサービスで個別にサニタイズする。
		$rows   = array();
		foreach ( $posted as $row ) {
			if ( ! is_array( $row ) || ! empty( $row['delete'] ) || ( '' === trim( (string) ( $row['name'] ?? '' ) ) && '' === (string) ( $row['id'] ?? '' ) ) ) {
				continue;
			}
			$rows[] = $row;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		$raw      = $_POST['vkbm_attendees_original'] ?? '[]'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- 型を確認してから文字列だけを unslash し、復号後の各値を検証する。
		$original = is_string( $raw ) ? json_decode( wp_unslash( $raw ), true ) : array();
		$original = $this->validate_original_rows( $original );
		$result   = $this->roster->save( $user_id, $this->ensure_self( $user, $rows ), $original );
		if ( is_wp_error( $result ) ) {
			add_action(
				'user_profile_update_errors',
				static function ( $errors ) use ( $result ): void {
					$errors->add( $result->get_error_code(), $result->get_error_message() );
				}
			);
		}
	}

	/**
	 * 番号が別会員の名簿と重複していることを、該当する番号欄の下に表示する。
	 *
	 * @param array{number:string,user_ids:int[]}|null $duplicate 重複の情報。重複が無ければ null。
	 * @param string                                   $id        説明文の id。
	 * @return void
	 */
	private function render_duplicate_description( ?array $duplicate, string $id ): void {
		if ( null === $duplicate ) {
			return;
		}
		echo '<p class="description vkbm-attendee-number-duplicate" id="' . esc_attr( $id ) . '">';
		echo esc_html__( 'This number is also saved for another member:', 'vk-booking-manager' ) . ' ';
		echo wp_kses( $this->get_member_links_html( $duplicate['user_ids'] ), array( 'a' => array( 'href' => array() ) ) );
		echo '</p>';
	}

	/**
	 * ユーザー編集画面の保存後に、番号の重複を警告する。
	 *
	 * 保存は止めず、相手会員の編集画面へのリンクを添えて知らせる。
	 *
	 * @return void
	 */
	public function render_duplicate_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 保存後の表示かを確かめるだけで、状態は変えない。
		if ( ! $screen || ! in_array( $screen->id, array( 'user-edit', 'profile' ), true ) || ! isset( $_GET['updated'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 表示対象のユーザーを読むだけで、状態は変えない。
		$user_id = 'profile' === $screen->id ? get_current_user_id() : absint( wp_unslash( $_GET['user_id'] ?? 0 ) );
		$user    = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user || ! $this->can_edit( $user->ID ) ) {
			return;
		}
		$settings = ( new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() ) )->get_settings();
		if ( empty( $settings['attendee_roster_enabled'] ) || empty( $settings['attendee_number_enabled'] ) ) {
			return;
		}
		$duplicates = $this->roster->find_duplicate_number_users( $user->ID, $this->roster->get_with_self( $user ) );
		if ( ! $duplicates ) {
			return;
		}
		$number_label = trim( (string) ( $settings['attendee_number_label'] ?? '' ) );
		$number_label = '' !== $number_label ? $number_label : __( 'Customer number', 'vk-booking-manager' );

		echo '<div class="notice notice-warning" role="alert"><p>';
		/* translators: %s: Customer number label. */
		echo esc_html( sprintf( __( 'The same %s is saved for another member.', 'vk-booking-manager' ), $number_label ) );
		echo ' ' . esc_html__( 'Check the numbers on the edit screen of each member.', 'vk-booking-manager' );
		echo '</p><ul>';
		foreach ( $duplicates as $duplicate ) {
			echo '<li>' . esc_html( $duplicate['number'] ) . ': ';
			echo wp_kses( $this->get_member_links_html( $duplicate['user_ids'] ), array( 'a' => array( 'href' => array() ) ) );
			echo '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * 会員の編集画面へのリンクを、読点区切りの HTML にする。編集できない会員は名前を出さず「別の会員」とだけ表示する。
	 *
	 * @param int[] $user_ids 会員の ID。
	 * @return string エスケープ済みの HTML。
	 */
	private function get_member_links_html( array $user_ids ): string {
		$items  = array();
		$hidden = false;
		foreach ( $user_ids as $user_id ) {
			$member = get_userdata( $user_id );
			if ( ! $member ) {
				continue;
			}
			// 編集権限の無い会員は、名前も出さない（予約一覧と同じ扱い）。
			if ( ! current_user_can( 'edit_user', $user_id ) ) {
				$hidden = true;
				continue;
			}
			$name    = '' !== trim( $member->display_name ) ? $member->display_name : $member->user_login;
			$url     = get_edit_user_link( $user_id );
			$items[] = $url ? sprintf( '<a href="%1$s">%2$s</a>', esc_url( $url ), esc_html( $name ) ) : esc_html( $name );
		}
		if ( $hidden ) {
			$items[] = esc_html__( 'another member', 'vk-booking-manager' );
		}
		return implode( esc_html_x( ', ', 'separator between member names', 'vk-booking-manager' ), $items );
	}

	/**
	 * 描画時名簿の JSON を競合判定に使える行だけへ絞る。
	 *
	 * @param mixed $rows JSON から復号した値。
	 * @return array<int,array{id:string,name:string,kana:string,number:string}> 検証済みの描画時名簿。
	 */
	private function validate_original_rows( $rows ): array {
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$validated = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['id'], $row['name'], $row['kana'], $row['number'] ) || ! is_string( $row['id'] ) || ! is_string( $row['name'] ) || ! is_string( $row['kana'] ) || ! is_string( $row['number'] ) ) {
				continue;
			}
			$validated[] = array(
				'id'     => $row['id'],
				'name'   => $row['name'],
				'kana'   => $row['kana'],
				'number' => $row['number'],
			);
		}
		return $validated;
	}

	/**
	 * 本人行を補い、プロフィールの名前を反映する。
	 *
	 * @param WP_User|null $user ユーザー。
	 * @param array<mixed> $rows 名簿。
	 * @return array<mixed> 本人行を含む名簿。
	 */
	private function ensure_self( ?WP_User $user, array $rows ): array {
		return $this->roster->with_self( $user, $rows );
	}
}
