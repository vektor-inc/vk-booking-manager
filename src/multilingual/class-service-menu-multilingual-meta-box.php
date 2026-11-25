<?php
/**
 * プラン（サービスメニュー）の編集画面のメタボックス「多言語設定」。
 *
 * 仕様は docs/specification-multilingual.md を正とする。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Multilingual;

use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use WP_Post;

use function __;
use function _x;
use function add_action;
use function add_meta_box;
use function add_query_arg;
use function admin_url;
use function current_user_can;
use function defined;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_textarea;
use function esc_url;
use function get_post_meta;
use function get_the_excerpt;
use function is_array;
use function preg_match;
use function sanitize_text_field;
use function sprintf;
use function str_replace;
use function vkbm_get_other_conditions_label;
use function wp_is_post_autosave;
use function wp_is_post_revision;
use function wp_nonce_field;
use function wp_strip_all_tags;
use function wp_unslash;
use function wp_verify_nonce;

/**
 * 「多言語設定」タブで追加した言語ごとに、プランのタイトル・抜粋・その他条件の入力欄を出し、保存する。
 */
class Service_Menu_Multilingual_Meta_Box {
	/**
	 * メタボックスの ID。
	 */
	public const META_BOX_ID = 'vkbm_service_menu_multilingual';

	/**
	 * nonce のアクション名。
	 */
	private const NONCE_ACTION = 'vkbm_service_menu_multilingual';

	/**
	 * nonce の送信名。
	 */
	private const NONCE_NAME = '_vkbm_service_menu_multilingual_nonce';

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public function register(): void {
		// 既存のメタボックス「VK Booking Manager」（Service_Menu_Editor、優先度 1）より後に登録し、その下に並べる。
		add_action( 'add_meta_boxes_' . Service_Menu_Post_Type::POST_TYPE, array( $this, 'add_meta_box' ) );
		add_action( 'save_post_' . Service_Menu_Post_Type::POST_TYPE, array( $this, 'save_post' ), 10, 2 );
	}

	/**
	 * メタボックスを登録する。「多言語設定」タブで言語を 1 つも追加していなければ登録しない。
	 *
	 * @return void
	 */
	public function add_meta_box(): void {
		if ( array() === Multilingual_Labels::get_locales() ) {
			return;
		}

		add_meta_box(
			self::META_BOX_ID,
			__( 'Multilingual settings', 'vk-booking-manager' ),
			array( $this, 'render' ),
			Service_Menu_Post_Type::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * メタボックスの中身を出力する。
	 *
	 * @param WP_Post $post プランの投稿。
	 * @return void
	 */
	public function render( WP_Post $post ): void {
		$locales                = Multilingual_Labels::get_locales();
		$translations           = Multilingual_Settings_Panel::get_available_translations();
		$stored                 = Service_Menu_Texts::get_stored( (int) $post->ID );
		$placeholders           = array(
			'title'            => (string) $post->post_title,
			// 抜粋が空のときは本文から自動で作られる抜粋が表示されるため、表示と同じ値を出す。
			'excerpt'          => wp_strip_all_tags( (string) get_the_excerpt( $post ) ),
			'other_conditions' => (string) get_post_meta( $post->ID, '_vkbm_other_conditions', true ),
		);
		$other_conditions_label = vkbm_get_other_conditions_label();

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<div class="vkbm-service-menu-multilingual">
			<p class="vkbm-service-menu-multilingual__intro">
				<?php
				printf(
					/* translators: 1: Label of the "Other conditions" field. 2: Link to the "Multilingual settings" tab. */
					esc_html__( 'Enter the title, excerpt, and %1$s shown on pages in the languages added in "%2$s".', 'vk-booking-manager' ),
					esc_html( $other_conditions_label ),
					$this->get_multilingual_tab_link() // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 組み立て時にエスケープ済み.
				);
				?>
			</p>
			<p class="vkbm-service-menu-multilingual__intro">
				<?php
				printf(
					/* translators: %s: Label of the "Other conditions" field. */
					esc_html__( 'Fields left empty show the light text, which is the value entered in the basic title, excerpt, and %s fields.', 'vk-booking-manager' ),
					esc_html( $other_conditions_label )
				);
				?>
			</p>
			<p class="vkbm-service-menu-multilingual__intro">
				<?php
				printf(
					/* translators: 1: first sentence, 2: second sentence. Remove the space if your language does not put a space between sentences. */
					esc_html( _x( '%1$s %2$s', 'two sentences in a row', 'vk-booking-manager' ) ),
					esc_html__( 'The content cannot be entered for each language.', 'vk-booking-manager' ),
					esc_html__( 'If needed, write the text for each language in the content.', 'vk-booking-manager' )
				);
				?>
			</p>

			<?php foreach ( $locales as $locale ) : ?>
				<?php $this->render_language( $locale, Multilingual_Settings_Panel::get_language_name( $locale, $translations ), $stored[ $locale ] ?? array(), $placeholders, $other_conditions_label ); ?>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * 編集画面から送られた値を保存する。
	 *
	 * @param int     $post_id プランの投稿 ID。
	 * @param WP_Post $post    プランの投稿。
	 * @return void
	 */
	public function save_post( int $post_id, WP_Post $post ): void {
		// メタボックスから送られた保存だけを扱う（nonce の確認）。
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		// 自動保存・リビジョンでは保存しない。
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( Service_Menu_Post_Type::POST_TYPE !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$raw = isset( $_POST[ Service_Menu_Texts::INPUT_NAME ] ) && is_array( $_POST[ Service_Menu_Texts::INPUT_NAME ] )
			? wp_unslash( $_POST[ Service_Menu_Texts::INPUT_NAME ] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Service_Menu_Texts::sanitize_input() でサニタイズする.
			: array();

		// 送られた言語の値だけを置き換え、送られなかった言語（タブで削除した言語など）の値は残す。
		$submitted = Service_Menu_Texts::sanitize_input( $raw, Multilingual_Labels::get_locales(), current_user_can( 'unfiltered_html' ) );
		Service_Menu_Texts::save( $post_id, Service_Menu_Texts::merge( Service_Menu_Texts::get_stored( $post_id ), $submitted ) );
	}

	/**
	 * ロケールを、HTML の lang 属性の値（言語と地域。例: en-US）にする。
	 *
	 * @param string $locale ロケール（例: en_US・de_DE_formal）。
	 * @return string lang 属性の値。
	 */
	public static function get_lang_attribute( string $locale ): string {
		// de_DE_formal・pt_PT_ao90 などの末尾の区別は lang 属性に含めない。
		if ( 1 === preg_match( '/^[a-z]{2,3}(?:_[A-Z]{2})?/', $locale, $matches ) ) {
			return str_replace( '_', '-', $matches[0] );
		}

		return '';
	}

	/**
	 * 「多言語設定」タブへのリンクを返す。設定画面を開けない利用者には文字だけを返す。
	 *
	 * @return string エスケープ済みの HTML。
	 */
	private function get_multilingual_tab_link(): string {
		$label = esc_html__( 'Multilingual settings', 'vk-booking-manager' );
		if ( ! current_user_can( Capabilities::MANAGE_PROVIDER_SETTINGS ) ) {
			return $label;
		}

		$url = add_query_arg(
			array(
				'page' => 'vkbm-provider-settings',
				'tab'  => 'multilingual',
			),
			admin_url( 'admin.php' )
		);

		return '<a href="' . esc_url( $url ) . '">' . $label . '</a>';
	}

	/**
	 * 1 言語分の見出しと入力欄を出力する。
	 *
	 * @param string                $locale                 ロケール。
	 * @param string                $name                   言語の名前。
	 * @param array<string, string> $values                 登録済みの値。
	 * @param array<string, string> $placeholders           項目のキー => 基本の値。
	 * @param string                $other_conditions_label その他条件の呼び方。
	 * @return void
	 */
	private function render_language( string $locale, string $name, array $values, array $placeholders, string $other_conditions_label ): void {
		$heading_id = 'vkbm-service-menu-multilingual-heading-' . $locale;
		$lang       = self::get_lang_attribute( $locale );
		$fields     = array(
			'title'            => __( 'Title', 'vk-booking-manager' ),
			'excerpt'          => __( 'Excerpt', 'vk-booking-manager' ),
			'other_conditions' => $other_conditions_label,
		);
		?>
		<section class="vkbm-service-menu-multilingual__language" aria-labelledby="<?php echo esc_attr( $heading_id ); ?>">
			<h3 class="vkbm-service-menu-multilingual__language-title" id="<?php echo esc_attr( $heading_id ); ?>"><?php echo esc_html( Multilingual_Settings_Panel::format_heading( $name, $locale ) ); ?></h3>
			<table class="form-table" role="presentation">
				<tbody>
					<?php foreach ( $fields as $key => $label ) : ?>
						<?php
						$input_id   = 'vkbm-service-menu-multilingual-' . $locale . '-' . $key;
						$input_name = Service_Menu_Texts::INPUT_NAME . '[' . $locale . '][' . $key . ']';
						$value      = $values[ $key ] ?? '';
						?>
						<tr>
							<th scope="row">
								<label for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $label ); ?></label>
							</th>
							<td>
								<?php if ( 'title' === $key ) : ?>
									<input type="text" class="large-text" id="<?php echo esc_attr( $input_id ); ?>" name="<?php echo esc_attr( $input_name ); ?>" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $placeholders[ $key ] ); ?>" lang="<?php echo esc_attr( $lang ); ?>" dir="auto" />
								<?php else : ?>
									<textarea class="large-text" id="<?php echo esc_attr( $input_id ); ?>" name="<?php echo esc_attr( $input_name ); ?>" rows="<?php echo 'excerpt' === $key ? '3' : '4'; ?>" placeholder="<?php echo esc_attr( $placeholders[ $key ] ); ?>" lang="<?php echo esc_attr( $lang ); ?>" dir="auto"><?php echo esc_textarea( $value ); ?></textarea>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>
		<?php
	}
}
