import { __, sprintf } from '@wordpress/i18n';

/**
 * provider-settings の応答からリソースタグ絞り込み0件時の文言を取り出す。
 *
 * @param {Object} settings provider-settings の応答。
 * @return {string} 表示する文言。
 */
export const resolveResourceTagEmptyMessage = ( settings ) => {
	if (
		typeof settings?.resource_tag_empty_message === 'string' &&
		settings.resource_tag_empty_message.trim() !== ''
	) {
		return settings.resource_tag_empty_message;
	}

	// 古い REST 応答や取得前でも案内が欠けないよう、従来文言へ安全に倒す。
	const resourceLabel =
		typeof settings?.resource_label_plural === 'string' &&
		settings.resource_label_plural.trim() !== ''
			? settings.resource_label_plural
			: __( 'Staff', 'vk-booking-manager' );

	return sprintf(
		/* translators: %s: resource label (plural), customizable via basic settings. */
		__(
			'There are no %s matching the selected conditions.',
			'vk-booking-manager'
		),
		resourceLabel
	);
};
