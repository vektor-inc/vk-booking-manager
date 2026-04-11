import { wpCli } from './helpers';

/**
 * wp_set_object_terms() でスタッフにリソースタグを設定する。
 * Set resource tags on a staff post via wp_set_object_terms().
 *
 * @param postId スタッフの post ID
 * @param slugs  設定するタグのスラッグ配列
 */
export const setResourceTags = ( postId: string, slugs: string[] ): void => {
	const slugArray = slugs.map( ( s ) => `'${ s }'` ).join( ', ' );
	wpCli(
		`eval "wp_set_object_terms( ${ postId }, array( ${ slugArray } ), 'vkbm_resource_tag' );"`
	);
};

/**
 * スタッフのリソースタグをクリアする。
 * Clear resource tags from a staff post.
 *
 * @param postId スタッフの post ID
 */
export const clearResourceTags = ( postId: string ): void => {
	wpCli(
		`eval "wp_set_object_terms( ${ postId }, array(), 'vkbm_resource_tag' );"`
	);
};

/**
 * プロバイダー設定で resource_tag_display_enabled を切り替える。
 * Toggle the resource_tag_display_enabled provider setting.
 *
 * @param enabled true で有効、false で無効
 */
export const setTagDisplayEnabled = ( enabled: boolean ): void => {
	const val = enabled ? 'true' : 'false';
	wpCli(
		`eval "
			\\\$s = get_option( 'vkbm_provider_settings', array() );
			\\\$s['resource_tag_display_enabled'] = ${ val };
			update_option( 'vkbm_provider_settings', \\\$s );
		"`
	);
};
