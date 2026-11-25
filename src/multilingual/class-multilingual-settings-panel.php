<?php
/**
 * BM 設定「多言語設定」タブの画面。
 *
 * 仕様は docs/specification-multilingual.md を正とする。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Multilingual;

use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;

use function __;
use function _x;
use function admin_url;
use function array_diff;
use function array_merge;
use function array_unique;
use function array_values;
use function current_user_can;
use function defined;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_html_e;
use function esc_textarea;
use function esc_url;
use function function_exists;
use function get_available_languages;
use function get_locale;
use function in_array;
use function is_array;
use function restore_previous_locale;
use function sprintf;
use function switch_to_locale;
use function vkbm_get_base_label;
use function vkbm_get_menu_label;
use function vkbm_get_other_conditions_label;
use function wp_get_available_translations;

/**
 * 「多言語設定」タブの入力欄を描画する。
 *
 * 保存は Provider_Settings_Page::handle_form_submission() から Multilingual_Labels::save_from_request() を呼んで行う。
 * 言語の追加・削除は assets/js/multilingual-settings.js が画面の上だけで行い、「変更を保存」で確定する。
 */
class Multilingual_Settings_Panel {
	/**
	 * 業種プリセットで自動設定され、「表記設定」タブで隠れている項目のキー。
	 *
	 * @var string[]
	 */
	private array $industry_hidden_fields;

	/**
	 * 指名機能が有効か（指名なしラベル・指名料ラベルの欄を出すか）。
	 *
	 * @var bool
	 */
	private bool $nomination_enabled;

	/**
	 * 業種が宿泊か（指名なしラベルの項目名を「指定なしの表記」にするか）。
	 *
	 * @var bool
	 */
	private bool $is_lodging;

	/**
	 * 「表記設定」タブの URL。
	 *
	 * @var string
	 */
	private string $notation_url;

	/**
	 * 画面の表示条件を受け取る。
	 *
	 * @param string[] $industry_hidden_fields 業種プリセットで隠れている項目のキー。
	 * @param bool     $nomination_enabled     指名機能が有効か。
	 * @param bool     $is_lodging             業種が宿泊か。
	 * @param string   $notation_url           「表記設定」タブの URL。
	 */
	public function __construct( array $industry_hidden_fields, bool $nomination_enabled, bool $is_lodging, string $notation_url ) {
		$this->industry_hidden_fields = $industry_hidden_fields;
		$this->nomination_enabled     = $nomination_enabled;
		$this->is_lodging             = $is_lodging;
		$this->notation_url           = $notation_url;
	}

	/**
	 * 追加できる言語（ロケール）の一覧を返す。
	 *
	 * このサイトにインストール済みの言語と英語（en_US）から、サイトの言語と追加済みの言語を除く。
	 *
	 * @param string   $site_locale       サイトの言語。
	 * @param string[] $installed_locales インストール済みの言語（get_available_languages() の値）。
	 * @param string[] $added_locales     追加済みの言語。
	 * @return string[] 追加できる言語。
	 */
	public static function get_addable_locales( string $site_locale, array $installed_locales, array $added_locales ): array {
		$candidates = array_unique( array_merge( array( 'en_US' ), $installed_locales ) );

		return array_values( array_diff( $candidates, array( $site_locale ), $added_locales ) );
	}

	/**
	 * 言語の名前（その言語での表記）を返す。
	 *
	 * @param string                              $locale       ロケール。
	 * @param array<string, array<string, mixed>> $translations wp_get_available_translations() の値。
	 * @return string 言語の名前。分からなければロケールそのもの。
	 */
	public static function get_language_name( string $locale, array $translations ): string {
		// en_US は翻訳の一覧に含まれないため、WordPress の言語の選択欄と同じ名前にする。
		if ( 'en_US' === $locale ) {
			return 'English (United States)';
		}

		$name = $translations[ $locale ]['native_name'] ?? '';

		return is_string( $name ) && '' !== $name ? $name : $locale;
	}

	/**
	 * 言語ごとの欄の定義を返す。並び・項目名・表示条件は「表記設定」タブと揃える。
	 *
	 * 条件で隠す欄も、行を出さずに消すのではなく `hidden` 付きで出力する（'hidden' => true）。
	 * 行が無いと保存のたびにその欄の値が送られず、隠している間に保存すると登録済みの値が消えるため。
	 *
	 * @param string $locale 欄を出す言語。
	 * @return array<int, array{key: string, label: string, hidden: bool}> 欄の定義。
	 */
	public function get_fields( string $locale ): array {
		$fields = array(
			array(
				'key'    => 'duration_label',
				'label'  => __( 'Duration label', 'vk-booking-manager' ),
				'hidden' => false,
			),
			array(
				'key'    => 'other_conditions_label',
				'label'  => __( 'Other conditions label', 'vk-booking-manager' ),
				'hidden' => false,
			),
			array(
				'key'    => 'menu_label',
				'label'  => __( 'Service menu term', 'vk-booking-manager' ),
				'hidden' => false,
			),
		);

		// 複数形の欄は、管理画面の言語ではなく登録する言語に複数形があるかで出し分ける。
		// 複数形の無い言語では一度も欄を出さず値が保存されないため、隠して残す必要は無い。
		if ( Multilingual_Labels::locale_has_plural_forms( $locale ) ) {
			$fields[] = array(
				'key'    => 'menu_label_plural',
				'label'  => __( 'Service menu term (plural)', 'vk-booking-manager' ),
				'hidden' => false,
			);
		}

		// 業種プリセットで自動設定される項目は、「表記設定」タブと同じく行ごと隠す。
		$fields[] = array(
			'key'    => 'guests_count_label',
			'label'  => __( 'Quantity label', 'vk-booking-manager' ),
			'hidden' => $this->is_industry_hidden( 'guests_count_label' ),
		);
		$fields[] = array(
			'key'    => 'guests_unit_label',
			'label'  => __( 'Quantity unit', 'vk-booking-manager' ),
			'hidden' => $this->is_industry_hidden( 'guests_unit_label' ),
		);

		// 指名なしラベル・指名料ラベルは、「表記設定」タブと同じく指名機能が無効なときは見せない。
		$fields[] = array(
			'key'    => 'no_nomination_label',
			'label'  => $this->is_lodging ? __( 'No selection label', 'vk-booking-manager' ) : __( 'No nomination label', 'vk-booking-manager' ),
			'hidden' => ! $this->nomination_enabled || $this->is_industry_hidden( 'no_nomination_label' ),
		);
		$fields[] = array(
			'key'    => 'nomination_fee_label',
			'label'  => __( 'Nomination fee label', 'vk-booking-manager' ),
			'hidden' => ! $this->nomination_enabled || $this->is_industry_hidden( 'nomination_fee_label' ),
		);

		// 予約画面と通知で使う文章を同じ言語の欄にまとめる。
		foreach ( array(
			'provider_cancellation_policy'              => __( 'Cancellation policy', 'vk-booking-manager' ),
			'provider_payment_method'                   => __( 'Payment instructions', 'vk-booking-manager' ),
			'provider_booking_complete_message_pending' => __( 'Guidance text for tentative reservation completion', 'vk-booking-manager' ),
		) as $key => $label ) {
			$fields[] = array(
				'key'    => $key,
				'label'  => $label,
				'hidden' => false,
			);
		}

		return $fields;
	}

	/**
	 * 業種プリセットで自動設定され、「表記設定」タブで隠れている項目かを返す。
	 *
	 * @param string $key 呼び方のキー。
	 * @return bool 隠れている項目なら true。
	 */
	private function is_industry_hidden( string $key ): bool {
		return in_array( $key, $this->industry_hidden_fields, true );
	}

	/**
	 * 欄のプレースホルダー（空欄のときに表示される呼び方）を、その言語で求める。
	 *
	 * 基本の値があればその値、無ければその言語の既定の呼び方になる。翻訳を対象の言語で取るため、
	 * switch_to_locale() で一時的に言語を切り替えて求める（インストールされていない言語は切り替えられないため、今の言語で求める）。
	 * 複数形は、その言語で単数形を登録していればその単数形になる（vkbm_get_menu_label_plural() と同じ決め方）。
	 *
	 * 戻り値の `menu_label_plural_base` には、単数形を空にしたときの複数形のプレースホルダー（基本の値の複数形、
	 * 無ければ単数形の基本の値）を入れる。画面で単数形を書き換えたときに JavaScript が使う。
	 *
	 * @param string                $locale 対象の言語。
	 * @param array<string, string> $values その言語で登録済みの値。
	 * @return array<string, string> 呼び方のキー => プレースホルダー。
	 */
	public static function get_placeholders( string $locale, array $values ): array {
		$switched = switch_to_locale( $locale );

		$placeholders = array();
		foreach ( Multilingual_Labels::FIELD_KEYS as $key ) {
			if ( ! in_array( $key, Multilingual_Labels::TEXT_KEYS, true ) ) {
				$placeholders[ $key ] = vkbm_get_base_label( $key );
			}
		}
		$placeholders['menu_label_plural_base'] = $placeholders['menu_label_plural'];
		// 複数形の空欄は、その言語の単数形 → 基本の値の複数形 → 単数形の基本の値の順で表示される。
		if ( '' !== ( $values['menu_label'] ?? '' ) ) {
			$placeholders['menu_label_plural'] = $values['menu_label'];
		}

		if ( $switched ) {
			restore_previous_locale();
		}

		return $placeholders;
	}

	/**
	 * タブの中身を出力する。
	 *
	 * @return void
	 */
	public function render(): void {
		$labels          = Multilingual_Labels::get_all();
		$translations    = self::get_available_translations();
		$addable_locales = self::get_addable_locales( get_locale(), get_available_languages(), array_keys( $labels ) );
		$has_addable     = array() !== $addable_locales;
		$bogo_packs_url  = $this->get_bogo_language_packs_url();
		$notation_link   = '<a href="' . esc_url( $this->notation_url ) . '">' . esc_html__( 'Notation settings', 'vk-booking-manager' ) . '</a>';
		// 追加済みの言語も、画面で削除したあとに追加し直せるよう、ひな形を用意する（並びは候補の並び → 追加済みの言語）。
		$template_languages = array();
		foreach ( array_unique( array_merge( self::get_addable_locales( get_locale(), get_available_languages(), array() ), array_keys( $labels ) ) ) as $locale ) {
			$template_languages[ $locale ] = self::get_language_name( $locale, $translations );
		}
		?>
		<div class="vkbm-multilingual" id="vkbm-multilingual">
			<p class="vkbm-multilingual__intro"><?php esc_html_e( 'Register the terms shown on pages in languages other than the site language, such as English, for each language.', 'vk-booking-manager' ); ?></p>
			<p class="vkbm-multilingual__intro">
				<?php
				// 2 つの文を、言語ごとの区切り方（日本語は空白なし）でつないで出す。
				$light_text_sentence = sprintf(
					/* translators: %s: link to the "Notation settings" tab. */
					esc_html__( 'The light text in each field is the term shown when the field is left empty (the term set in "%s").', 'vk-booking-manager' ),
					$notation_link
				);
				printf(
					/* translators: 1: first sentence, 2: second sentence. Remove the space if your language does not put a space between sentences. */
					esc_html( _x( '%1$s %2$s', 'two sentences in a row', 'vk-booking-manager' ) ),
					$light_text_sentence, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 組み立て時にエスケープ済み.
					esc_html__( 'If the light text is not in that language, enter the term for that language.', 'vk-booking-manager' )
				);
				?>
			</p>
			<p class="vkbm-multilingual__intro"><?php esc_html_e( 'Create pages for each language with a multilingual plugin such as Bogo.', 'vk-booking-manager' ); ?></p>
			<p class="vkbm-multilingual__intro">
				<?php
				printf(
					/* translators: 1: Label of the "Other conditions" field. 2: Service menu term (singular), linked to the list of service menus. */
					esc_html__( 'You can enter the title, excerpt, and %1$s for each language on the edit screen of each %2$s.', 'vk-booking-manager' ),
					esc_html( vkbm_get_other_conditions_label() ),
					$this->get_service_menu_list_link() // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 組み立て時にエスケープ済み.
				);
				?>
			</p>

			<?php // 言語を全部削除した保存でも判別できるよう、印を必ず送る。 ?>
			<input type="hidden" name="<?php echo esc_attr( Multilingual_Labels::SUBMITTED_KEY ); ?>" value="1" />

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Add a language', 'vk-booking-manager' ); ?></th>
						<td>
							<div class="vkbm-multilingual__add">
								<select id="vkbm-multilingual-add-locale" aria-label="<?php esc_attr_e( 'Language to add', 'vk-booking-manager' ); ?>" <?php disabled( ! $has_addable ); ?>>
									<option value=""><?php esc_html_e( 'Select a language', 'vk-booking-manager' ); ?></option>
									<?php foreach ( $addable_locales as $locale ) : ?>
										<option value="<?php echo esc_attr( $locale ); ?>"><?php echo esc_html( self::format_heading( $template_languages[ $locale ], $locale ) ); ?></option>
									<?php endforeach; ?>
								</select>
								<?php // 言語を選ぶまでは押せないようにする（選択に合わせて assets/js/multilingual-settings.js が切り替える）。 ?>
								<button type="button" class="button button-secondary" id="vkbm-multilingual-add" disabled>
									<?php esc_html_e( '+ Add language', 'vk-booking-manager' ); ?>
								</button>
							</div>
							<p class="description vkbm-multilingual__add-help" <?php echo $has_addable ? '' : 'hidden'; ?>><?php esc_html_e( 'The list shows the languages installed on this site.', 'vk-booking-manager' ); ?></p>
							<p class="description vkbm-multilingual__no-more" <?php echo $has_addable ? 'hidden' : ''; ?>><?php esc_html_e( 'There are no more languages to add.', 'vk-booking-manager' ); ?></p>
							<p class="description">
								<?php if ( '' !== $bogo_packs_url ) : ?>
									<?php
									printf(
										/* translators: %s: link to the "Language Packs" screen of Bogo. */
										esc_html__( 'Languages not in the list can be selected after you install them on the "%s" screen of Bogo.', 'vk-booking-manager' ),
										'<a href="' . esc_url( $bogo_packs_url ) . '">' . esc_html__( 'Language Packs', 'vk-booking-manager' ) . '</a>'
									);
									?>
								<?php else : ?>
									<?php esc_html_e( 'Languages not in the list can be selected after you install them on this site.', 'vk-booking-manager' ); ?>
								<?php endif; ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>

			<div class="vkbm-multilingual__languages" id="vkbm-multilingual-languages">
				<?php foreach ( $labels as $locale => $values ) : ?>
					<?php $this->render_language( $locale, self::get_language_name( $locale, $translations ), $values ); ?>
				<?php endforeach; ?>
			</div>
			<p class="vkbm-multilingual__empty" <?php echo array() === $labels ? '' : 'hidden'; ?>><?php esc_html_e( 'No languages have been added yet.', 'vk-booking-manager' ); ?></p>
			<?php // 言語の追加・削除の結果を読み上げる領域。文言は assets/js/multilingual-settings.js が入れる。 ?>
			<p id="vkbm-multilingual-status" class="screen-reader-text" aria-live="polite"></p>

			<?php // 言語ごとに、入力欄のひな形を用意する（プレースホルダーと複数形の欄の有無が言語ごとに違うため）。プルダウンの候補もこの並びで作り直す。 ?>
			<?php foreach ( $template_languages as $locale => $name ) : ?>
				<template class="vkbm-multilingual__template" data-locale="<?php echo esc_attr( $locale ); ?>" data-option-label="<?php echo esc_attr( self::format_heading( $name, $locale ) ); ?>">
					<?php $this->render_language( $locale, $name, array() ); ?>
				</template>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * 見出しに出す「言語の名前（ロケール）」を組み立てる。
	 *
	 * @param string $name   言語の名前。
	 * @param string $locale ロケール。
	 * @return string 見出しの文字列（エスケープ前）。
	 */
	public static function format_heading( string $name, string $locale ): string {
		return sprintf(
			/* translators: 1: Language name, 2: Locale code (e.g. en_US). */
			_x( '%1$s (%2$s)', 'multilingual language heading', 'vk-booking-manager' ),
			$name,
			$locale
		);
	}

	/**
	 * 1 言語分の見出し・削除ボタン・入力欄を出力する。
	 *
	 * @param string                $locale ロケール。
	 * @param string                $name   言語の名前。
	 * @param array<string, string> $values 登録済みの値。
	 * @return void
	 */
	private function render_language( string $locale, string $name, array $values ): void {
		$heading_id   = 'vkbm-multilingual-heading-' . $locale;
		$placeholders = self::get_placeholders( $locale, $values );
		?>
		<section class="vkbm-multilingual__language" data-locale="<?php echo esc_attr( $locale ); ?>" data-language-name="<?php echo esc_attr( $name ); ?>" aria-labelledby="<?php echo esc_attr( $heading_id ); ?>">
			<div class="vkbm-multilingual__language-header">
				<?php // 言語を削除したとき、次の言語の見出しへフォーカスを移すため tabindex="-1" を付ける。 ?>
				<h3 class="vkbm-multilingual__language-title" id="<?php echo esc_attr( $heading_id ); ?>" tabindex="-1"><?php echo esc_html( self::format_heading( $name, $locale ) ); ?></h3>
				<button
					type="button"
					class="vkbm-button vkbm-button__sm vkbm-button-outline vkbm-button-outline__danger vkbm-multilingual__remove"
					aria-label="<?php echo esc_attr( sprintf( /* translators: %s: Language name. */ _x( 'Delete “%s”', 'multilingual language button', 'vk-booking-manager' ), $name ) ); ?>"
				><?php esc_html_e( 'Delete', 'vk-booking-manager' ); ?></button>
			</div>
			<h4 class="vkbm-multilingual__subheading"><?php esc_html_e( 'Terms', 'vk-booking-manager' ); ?></h4>
			<table class="form-table vkbm-multilingual__terms-table" role="presentation">
				<tbody>
					<?php foreach ( $this->get_fields( $locale ) as $field ) : ?>
						<?php if ( 'provider_cancellation_policy' === $field['key'] ) : ?>
				</tbody>
			</table>
			<h4 class="vkbm-multilingual__subheading vkbm-multilingual__subheading--guidance"><?php esc_html_e( 'Booking guidance text', 'vk-booking-manager' ); ?></h4>
			<p class="description vkbm-multilingual__guidance-description"><?php esc_html_e( 'If left empty, the text in the basic settings is used.', 'vk-booking-manager' ); ?> <?php esc_html_e( 'For tentative reservation completion guidance, the standard text is used if the basic setting is also empty.', 'vk-booking-manager' ); ?></p>
			<p class="description vkbm-multilingual__guidance-description"><?php esc_html_e( 'Edit basic settings', 'vk-booking-manager' ); ?>:
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=vkbm-provider-settings&tab=consent#vkbm-provider-cancellation-policy' ) ); ?>"><?php esc_html_e( 'Cancellation policy', 'vk-booking-manager' ); ?></a> ·
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=vkbm-provider-settings&tab=store#vkbm-provider-payment-method' ) ); ?>"><?php esc_html_e( 'Payment instructions', 'vk-booking-manager' ); ?></a> ·
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=vkbm-provider-settings&tab=system#vkbm-provider-booking-status-mode' ) ); ?>"><?php esc_html_e( 'Guidance text for tentative reservation completion', 'vk-booking-manager' ); ?></a>
			</p>
			<p class="description vkbm-multilingual__guidance-description"><?php esc_html_e( 'To edit the tentative reservation completion guidance in the basic settings, set "Reservation status" to "Make a tentative reservation".', 'vk-booking-manager' ); ?></p>
			<table class="form-table" role="presentation">
				<tbody>
						<?php endif; ?>
						<?php
						$key      = $field['key'];
						$input_id = 'vkbm-multilingual-' . $locale . '-' . $key;
						?>
						<tr <?php echo $field['hidden'] ? 'hidden' : ''; ?>>
							<th scope="row">
								<label for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
							</th>
							<td>
								<?php if ( in_array( $key, Multilingual_Labels::TEXT_KEYS, true ) ) : ?>
									<textarea class="large-text" rows="4" id="<?php echo esc_attr( $input_id ); ?>" name="<?php echo esc_attr( Multilingual_Labels::INPUT_NAME . '[' . $locale . '][' . $key . ']' ); ?>" lang="<?php echo esc_attr( str_replace( '_', '-', $locale ) ); ?>" dir="auto" data-multilingual-key="<?php echo esc_attr( $key ); ?>"
									<?php
									if ( 'provider_booking_complete_message_pending' === $key ) :
										?>
										maxlength="<?php echo esc_attr( (string) Settings_Sanitizer::BOOKING_COMPLETE_MESSAGE_MAX_LENGTH ); ?>"<?php endif; ?>><?php echo esc_textarea( $values[ $key ] ?? '' ); ?></textarea>
								<?php else : ?>
								<input
									type="text"
									class="regular-text"
									id="<?php echo esc_attr( $input_id ); ?>"
									name="<?php echo esc_attr( Multilingual_Labels::INPUT_NAME . '[' . $locale . '][' . $key . ']' ); ?>"
									value="<?php echo esc_attr( $values[ $key ] ?? '' ); ?>"
									placeholder="<?php echo esc_attr( $placeholders[ $key ] ?? '' ); ?>"
									data-multilingual-key="<?php echo esc_attr( $key ); ?>"
									<?php if ( 'menu_label_plural' === $key ) : ?>
										<?php // 単数形を空にしたときに戻す、複数形のプレースホルダー（基本の値の複数形、無ければ単数形の基本の値）。 ?>
										data-base-placeholder="<?php echo esc_attr( $placeholders['menu_label_plural_base'] ); ?>"
									<?php endif; ?>
								/>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>
		<?php
	}

	/**
	 * インストール済みの言語の名前の一覧を返す。
	 *
	 * WordPress の「設定 > 一般」のサイトの言語の選択欄と同じ一覧を使う（取得結果は WordPress が一時保存する）。
	 *
	 * @return array<string, array<string, mixed>> ロケール => 言語の情報。
	 */
	public static function get_available_translations(): array {
		if ( ! function_exists( 'wp_get_available_translations' ) ) {
			require_once ABSPATH . 'wp-admin/includes/translation-install.php';
		}

		$translations = wp_get_available_translations();

		return is_array( $translations ) ? $translations : array();
	}

	/**
	 * サービスメニューの呼び方（単数形）を、サービスメニューの一覧画面へのリンクにして返す。
	 *
	 * @return string エスケープ済みの HTML。一覧を開けない利用者には文字だけを返す。
	 */
	private function get_service_menu_list_link(): string {
		$label = esc_html( vkbm_get_menu_label() );
		if ( ! current_user_can( Capabilities::MANAGE_SERVICE_MENUS ) ) {
			return $label;
		}

		return '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . Service_Menu_Post_Type::POST_TYPE ) ) . '">' . $label . '</a>';
	}

	/**
	 * Bogo の「言語パック」画面の URL を返す。
	 *
	 * @return string Bogo が有効で、その画面を開ける利用者なら URL。それ以外は空文字。
	 */
	private function get_bogo_language_packs_url(): string {
		if ( ! defined( 'BOGO_VERSION' ) || ! current_user_can( 'bogo_manage_language_packs' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Bogo が登録する権限（言語パック画面を開ける利用者）。
			return '';
		}

		return admin_url( 'admin.php?page=bogo' );
	}
}
