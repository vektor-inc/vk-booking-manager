import { wpCliArgs, wpEvalPhp } from './helpers';

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

/**
 * プロバイダー設定で「絞り込み検索」（reservation_show_menu_search）と
 * その子項目「リソースタグ検索」（resource_tag_search_enabled）をまとめて切り替える（issue #431）。
 * Toggle the "Filter search" and its child "Resource tag search" provider settings together (#431).
 *
 * リソースタグ検索は絞り込み検索の子項目のため、親がOFFのままではUI上／サーバー側
 * サニタイズの両方で機能しない。テストでは常に両方をセットで切り替える。
 *
 * @param enabled true で両方有効、false で両方無効
 */
export const setResourceTagSearchEnabled = ( enabled: boolean ): void => {
	const val = enabled ? 'true' : 'false';
	wpEvalPhp(
		`
			$s = get_option( 'vkbm_provider_settings', array() );
			$s['reservation_show_menu_search'] = ${ val };
			$s['resource_tag_search_enabled'] = ${ val };
			update_option( 'vkbm_provider_settings', $s );
		`
	);
};

/**
 * リソースタグのタームIDを、スラッグから取得する（存在しなければ作成する）。
 * Resolve a resource tag term ID from its slug, creating the term if it does not exist yet.
 *
 * タグの照合はターム ID で行う仕様（issue #431 実装メモ）のため、e2e でも
 * REST リクエストへ渡すタグ ID をここで解決する。
 *
 * @param slug タグのスラッグ（[a-z0-9_-]+ のみ）
 * @param name タグ名（新規作成時のみ使用）
 * @return タームID（数値文字列）
 */
export const getOrCreateResourceTagId = (
	slug: string,
	name: string
): string => {
	if ( ! SLUG_ALLOWED.test( slug ) ) {
		throw new Error( `Invalid resource tag slug: "${ slug }"` );
	}

	// `wp term list --field=term_id` は該当タームが無くても異常終了せず
	// 空文字を返すため、catch ではなく戻り値の空判定で新規作成に振り分ける。
	// `wp term list --field=term_id` exits successfully with empty output
	// when no matching term exists, so branch on the empty result rather
	// than a thrown error.
	let termId = '';
	try {
		termId = wpCliArgs( [
			'term',
			'list',
			'vkbm_resource_tag',
			`--slug=${ slug }`,
			'--field=term_id',
		] ).trim();
	} catch {
		termId = '';
	}

	if ( termId ) {
		return termId;
	}

	// 存在しない場合は新規作成する。
	return wpCliArgs( [
		'term',
		'create',
		'vkbm_resource_tag',
		name,
		`--slug=${ slug }`,
		'--porcelain',
	] ).trim();
};
