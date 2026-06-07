import { wpEvalPhp } from './helpers';

/**
 * タグスラッグの許可形式（英数字・ハイフン・アンダースコアのみ）。
 * Allowed slug format: alphanumerics, hyphen, underscore.
 *
 * wp_set_object_terms に渡す slug を PHP リテラルに埋め込むため、PHP リテラル外への
 * 脱出（引用符・バックスラッシュ・改行など）を防ぐ目的でホワイトリスト検証する。
 * Used to validate slugs before interpolating them into a PHP string literal,
 * preventing literal escape via quotes, backslashes, or newlines.
 */
const SLUG_ALLOWED = /^[a-z0-9_-]+$/i;

/**
 * wp_set_object_terms() でスタッフにリソースタグを設定する。
 * Set resource tags on a staff post via wp_set_object_terms().
 *
 * postId は数値文字列であることを、slugs は英数字・ハイフン・アンダースコアのみで
 * 構成されていることを検証してから PHP に埋め込む。
 * Validate postId is numeric and each slug matches an allowlist before
 * interpolating into PHP.
 *
 * @param postId スタッフの post ID（数値文字列）
 * @param slugs  設定するタグのスラッグ配列（[a-z0-9_-]+ のみ）
 */
export const setResourceTags = ( postId: string, slugs: string[] ): void => {
	// postId の数値検証（非数値だと PHP リテラル外への脱出リスク）
	// Validate postId is numeric to prevent PHP literal escape
	if ( ! /^\d+$/.test( postId ) ) {
		throw new Error( `Invalid postId: "${ postId }"` );
	}
	// slug の許可文字検証（ホワイトリスト方式）
	// Validate each slug against the allowlist
	for ( const slug of slugs ) {
		if ( ! SLUG_ALLOWED.test( slug ) ) {
			throw new Error( `Invalid resource tag slug: "${ slug }"` );
		}
	}
	const safePostId = Number.parseInt( postId, 10 );
	const slugArray = slugs.map( ( s ) => `'${ s }'` ).join( ', ' );
	// execFileSync 化に伴い shell 引用符依存の `wpCli(\`eval "..."\`)` 形は使えない。
	// 検証済み値のみが PHP に埋まる前提で wpEvalPhp 経由に切替。
	// After the execFileSync migration the shell-quoted `eval "..."` form
	// no longer works; route the validated values through wpEvalPhp.
	wpEvalPhp(
		`wp_set_object_terms( ${ safePostId }, array( ${ slugArray } ), 'vkbm_resource_tag' );`
	);
};

/**
 * スタッフのリソースタグをクリアする。
 * Clear resource tags from a staff post.
 *
 * @param postId スタッフの post ID（数値文字列）
 */
export const clearResourceTags = ( postId: string ): void => {
	// postId の数値検証（非数値だと PHP リテラル外への脱出リスク）
	// Validate postId is numeric to prevent PHP literal escape
	if ( ! /^\d+$/.test( postId ) ) {
		throw new Error( `Invalid postId: "${ postId }"` );
	}
	const safePostId = Number.parseInt( postId, 10 );
	// 同様に wpEvalPhp 経由で PHP コードを渡す。
	// Same wpEvalPhp routing as setResourceTags.
	wpEvalPhp(
		`wp_set_object_terms( ${ safePostId }, array(), 'vkbm_resource_tag' );`
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
	// 旧形は `wpCli(\`eval "\\\\\$s = get_option(...); ..."\`)` で shell エスケープに依存。
	// wpEvalPhp 経由なら base64 ラップされるため \$ エスケープも不要。
	// The old form relied on shell escaping (\\\$). wpEvalPhp base64-wraps the
	// code so the dollar sign can be written plainly.
	wpEvalPhp(
		`
			$s = get_option( 'vkbm_provider_settings', array() );
			$s['resource_tag_display_enabled'] = ${ val };
			update_option( 'vkbm_provider_settings', $s );
		`
	);
};
