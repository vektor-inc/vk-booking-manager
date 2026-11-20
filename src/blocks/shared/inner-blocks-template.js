/**
 * InnerBlocks（子ブロックを置く領域）の template（最初に並べる子ブロックの一覧）の渡し先を、
 * エディタが registerBlockType() の設定の template を読むかどうかで出し分ける。
 * 読むエディタは、WordPress 本体の 7.2 以降。Gutenberg プラグインが有効なときは、本体ではなくプラグインの 23.8 以降。
 *
 * - 読むエディタ: registerBlockType() の設定に template を入れ、InnerBlocks には渡さない。
 *   このエディタでは InnerBlocks の template プロップが非推奨で、渡すとコンソールに警告が出るため。
 * - 読まないエディタ: InnerBlocks の template プロップに渡し、設定には入れない。
 *   設定の template を読まないため、設定だけでは子ブロックが並ばない。
 *
 * ブロック側では、同じ template を次の 2 か所に渡す。
 * - index.js の設定: `...getBlockTypeTemplateSettings( TEMPLATE )`
 * - edit.js の InnerBlocks: `template={ getInnerBlocksTemplate( TEMPLATE ) }`
 *
 * 判定の値は PHP（Inner_Blocks_Template::localize_editor_script()）がブロックのエディタ用スクリプトへ渡す。
 */

/**
 * 設定の template をエディタが読むか（本体 7.2 以降。Gutenberg プラグインが有効ならその 23.8 以降）を判定する。
 *
 * PHP の Inner_Blocks_Template::is_block_type_template_supported() の結果を、
 * wp_localize_script() 経由の window.vkbmInnerBlocksTemplate.blockTypeTemplateSupported から読む。
 * wp_localize_script() は真偽値を文字列にする（true は "1"、false は ""）ため、"1" を true として扱う。
 * 値が無いときは false にする。false 側は読むエディタでも子ブロックが並ぶ（警告が出るだけ）ため、
 * 判定できないときの安全側になる。
 *
 * @return {boolean} 設定の template を読むエディタなら true。
 */
export function isBlockTypeTemplateSupported() {
	const value = window.vkbmInnerBlocksTemplate?.blockTypeTemplateSupported;
	return value === true || value === '1';
}

/**
 * registerBlockType() の設定へ展開して加える template を返す。
 *
 * @param {Array} template 最初に並べる子ブロックの一覧（例: [ [ 'core/paragraph' ] ]）。
 * @return {Object} 設定の template を読むエディタなら { template }、読まないエディタなら空のオブジェクト。
 */
export function getBlockTypeTemplateSettings( template ) {
	return isBlockTypeTemplateSupported() ? { template } : {};
}

/**
 * InnerBlocks の template プロップに渡す値を返す。設定の template を読むエディタでは undefined を返して渡さない。
 *
 * @param {Array} template 最初に並べる子ブロックの一覧（例: [ [ 'core/paragraph' ] ]）。
 * @return {Array|undefined} 読まないエディタなら template、読むエディタなら undefined。
 */
export function getInnerBlocksTemplate( template ) {
	return isBlockTypeTemplateSupported() ? undefined : template;
}
