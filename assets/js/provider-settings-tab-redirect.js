/**
 * BM 設定画面で、旧位置の URL（例: tab=system#vkbm-mail-from-email）を開いたときに、
 * `#` 以降が指す設定行が属するタブへ移すための判定処理（#629）。
 *
 * 画面は全タブの行を1つのフォームに出力し、表示中でないタブの行を CSS で隠す。
 * そのため、設定項目を別のタブへ移した後に旧 URL を開くと、行は存在するのに隠れていて見えない。
 * 行のクラス（vkbm-provider-settings__tab-xxx）から所属タブを読み取り、現在のタブと違えば移動先を返す。
 *
 * DOM 操作から切り離した純粋関数にして、Jest（assets/js/__tests__/provider-settings-tab-redirect.test.js）
 * で検証する。通常のスクリプトとして読み込まれるため import / export 構文は使わない。
 * ブラウザでは window.vkbmProviderSettingsTabRedirect へ、Node（Jest）では module.exports へ公開する。
 *
 * @param {Window|Object} root 公開先のグローバルオブジェクト。
 */
( function ( root ) {
	'use strict';

	const TAB_CLASS_PATTERN =
		/(?:^|\s)vkbm-provider-settings__tab-([a-z0-9_-]+)(?=\s|$)/;

	/**
	 * 行の class 属性の値から、その行が属するタブのスラッグを取り出す。
	 *
	 * @param {string} className 行の class 属性の値。
	 * @return {string} タブのスラッグ。タブのクラスが無ければ空文字。
	 */
	function getTabSlugFromClassName( className ) {
		if ( 'string' !== typeof className ) {
			return '';
		}

		const matched = className.match( TAB_CLASS_PATTERN );

		return matched ? matched[ 1 ] : '';
	}

	/**
	 * 現在のタブと行の所属タブを比べ、移動すべきタブのスラッグを返す。
	 *
	 * @param {string}   currentTab    表示中のタブのスラッグ。
	 * @param {string}   className     `#` 以降が指す行の class 属性の値。
	 * @param {string[]} availableTabs 画面のタブ一覧にあるタブのスラッグ。省略時は絞り込まない。
	 * @return {string} 移動先のタブのスラッグ。移動が不要、または移動先が画面に無いときは空文字。
	 */
	function resolveRedirectTab( currentTab, className, availableTabs ) {
		const rowTab = getTabSlugFromClassName( className );

		if ( ! rowTab || rowTab === currentTab ) {
			return '';
		}

		// 移動先のタブが画面に無いと、移動先でも同じ判定になり再読み込みを繰り返すため移動しない。
		if (
			Array.isArray( availableTabs ) &&
			! availableTabs.includes( rowTab )
		) {
			return '';
		}

		return rowTab;
	}

	/**
	 * 現在の URL の tab パラメータだけを差し替えた URL を返す（`#` 以降はそのまま残す）。
	 *
	 * @param {string} href 現在の URL。
	 * @param {string} tab  移動先のタブのスラッグ。
	 * @return {string} 差し替え後の URL。
	 */
	function buildRedirectUrl( href, tab ) {
		const url = new URL( href );

		url.searchParams.set( 'tab', tab );

		return url.toString();
	}

	const api = {
		getTabSlugFromClassName,
		resolveRedirectTab,
		buildRedirectUrl,
	};

	// root への公開は分岐に関わらず必ず行い、Node/Jest 向けの module.exports は追加の代入として行う
	// （shift-time-utils.js と同じ方針）。
	root.vkbmProviderSettingsTabRedirect = api;
	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = api;
	}
} )( typeof window !== 'undefined' ? window : this ); // eslint-disable-line no-invalid-this
