<?php
/**
 * リソース編集画面の言語別タイトル欄。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Multilingual;

use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\Capabilities\Capabilities;
use WP_Post;

/** 言語別のリソース名を入力するメタボックス。 */
class Resource_Multilingual_Meta_Box {
	public const META_BOX_ID   = 'vkbm_resource_multilingual';
	private const NONCE_ACTION = 'vkbm_resource_multilingual';
	private const NONCE_NAME   = '_vkbm_resource_multilingual_nonce';

	/** フックを登録する。 */
	public function register(): void {
		add_action( 'add_meta_boxes_' . Resource_Post_Type::POST_TYPE, array( $this, 'add_meta_box' ) );
		add_action( 'save_post_' . Resource_Post_Type::POST_TYPE, array( $this, 'save_post' ), 10, 2 );
	}

	/** 言語が登録されているときだけメタボックスを追加する。 */
	public function add_meta_box(): void {
		if ( array() !== Multilingual_Labels::get_locales() ) {
			add_meta_box( self::META_BOX_ID, __( 'Multilingual settings', 'vk-booking-manager' ), array( $this, 'render' ), Resource_Post_Type::POST_TYPE, 'normal', 'high' );
		}
	}

	/**
	 * 言語ごとの入力欄を表示する。
	 *
	 * @param WP_Post $post リソース投稿。
	 */
	public function render( WP_Post $post ): void {
		$stored       = Resource_Texts::get_stored( (int) $post->ID );
		$translations = Multilingual_Settings_Panel::get_available_translations();
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<div class="vkbm-service-menu-multilingual">
			<p class="vkbm-service-menu-multilingual__intro"><?php esc_html_e( 'Enter the title shown on pages in each added language. Empty fields show the basic title.', 'vk-booking-manager' ); ?></p>
			<?php if ( current_user_can( Capabilities::MANAGE_PROVIDER_SETTINGS ) ) : ?>
				<p class="vkbm-service-menu-multilingual__intro"><a href="<?php echo esc_url( admin_url( 'admin.php?page=vkbm-provider-settings&tab=multilingual' ) ); ?>"><?php esc_html_e( 'Multilingual settings', 'vk-booking-manager' ); ?></a></p>
			<?php endif; ?>
			<?php foreach ( Multilingual_Labels::get_locales() as $locale ) : ?>
				<?php $id = 'vkbm-resource-multilingual-' . $locale . '-title'; ?>
				<section class="vkbm-service-menu-multilingual__language" aria-labelledby="<?php echo esc_attr( $id . '-heading' ); ?>">
					<h3 class="vkbm-service-menu-multilingual__language-title" id="<?php echo esc_attr( $id . '-heading' ); ?>"><?php echo esc_html( Multilingual_Settings_Panel::format_heading( Multilingual_Settings_Panel::get_language_name( $locale, $translations ), $locale ) ); ?></h3>
					<table class="form-table" role="presentation"><tbody><tr>
						<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Title', 'vk-booking-manager' ); ?></label></th>
						<td><input type="text" class="large-text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( Resource_Texts::INPUT_NAME . '[' . $locale . '][title]' ); ?>" value="<?php echo esc_attr( $stored[ $locale ]['title'] ?? '' ); ?>" placeholder="<?php echo esc_attr( $post->post_title ); ?>" lang="<?php echo esc_attr( Service_Menu_Multilingual_Meta_Box::get_lang_attribute( $locale ) ); ?>" dir="auto" /></td>
					</tr></tbody></table>
				</section>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * nonce と投稿権限を確認して入力値を保存する。
	 *
	 * @param int     $post_id 投稿 ID。
	 * @param WP_Post $post リソース投稿。
	 */
	public function save_post( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || Resource_Post_Type::POST_TYPE !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$raw = isset( $_POST[ Resource_Texts::INPUT_NAME ] ) && is_array( $_POST[ Resource_Texts::INPUT_NAME ] ) ? wp_unslash( $_POST[ Resource_Texts::INPUT_NAME ] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Resource_Texts::sanitize_input() でサニタイズする。
			: array();
		Resource_Texts::save( $post_id, Resource_Texts::sanitize_input( $raw ) );
	}
}
