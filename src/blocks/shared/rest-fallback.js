/**
 * REST API の ?rest_route= フォールバックを扱う apiFetch ミドルウェア（issue #489）。
 *
 * パーマリンク構造は DB 上に設定されているのに、サーバー側（.htaccess 等）へ
 * WordPress の書き換えルールが反映されていない環境では、/wp-json/ への通信が
 * サーバーの 404 HTML を返し、apiFetch が invalid_json エラーになる。このモジュールは、
 * その invalid_json エラーだけを検知して ?rest_route= 形式のルート URL で1回だけ
 * 再試行する apiFetch ミドルウェアを登録する。
 */
import apiFetch from '@wordpress/api-fetch';

// 同一ページ内で一度でもフォールバックに成功したかどうか。成功後は以降のリクエストを
// 最初からフォールバック形式で送る。モジュール変数（メモリ上）にのみ保持し、
// localStorage 等へは保存しない（ページを開き直せば false に戻る）。
let hasSucceededWithFallback = false;

// このモジュールを import した各エントリで二重登録しないためのフラグ。
let middlewareRegistered = false;

/**
 * apiFetch のルート URL ミドルウェア（@wordpress/api-fetch の
 * amendOptionsWithRootURL）と同じ規則で、フォールバック用ルート URL と
 * path を結合した絶対 URL を組み立てる。
 *
 * フォールバック用ルートは `.../index.php?rest_route=/` の形式（末尾が `/`）で
 * 渡ってくる想定。ルート側はそのまま使い、path 側の先頭の `/` を1つ取り除いてから
 * 連結する（安藤レビュー指摘 MEDIUM-4: ルート末尾を削る実装だと、path が先頭の
 * `/` を持たない呼び出しで区切り文字が失われるため、path 側を正規化する方式に変更）。
 * path 自身が持つクエリ文字列の `?` は、ルート側が既に `?` を含むため `&` に
 * 置き換える。
 *
 * @param {string} path             apiFetch に渡された path（例: '/vkbm/v1/foo?bar=1'）。
 * @param {string} restFallbackRoot フォールバック用ルート URL。
 * @return {string} フォールバック用の絶対 URL。
 */
export const buildFallbackUrl = ( path, restFallbackRoot ) => {
	const normalizedPath =
		restFallbackRoot.indexOf( '?' ) !== -1
			? path.replace( '?', '&' )
			: path;
	const relativePath = normalizedPath.replace( /^\//, '' );

	return restFallbackRoot + relativePath;
};

/**
 * apiFetch の options（path 指定）を、フォールバック用ルートを使った url 指定へ
 * 差し替える。
 *
 * @param {Object} options          apiFetch に渡された options。
 * @param {string} restFallbackRoot フォールバック用ルート URL。
 * @return {Object} url 指定へ差し替えた options。
 */
const toFallbackOptions = ( options, restFallbackRoot ) => {
	const { path, ...rest } = options;
	return {
		...rest,
		url: buildFallbackUrl( path, restFallbackRoot ),
	};
};

/**
 * 失敗時に安全に再試行できる HTTP メソッドかどうかを判定する。
 *
 * method 未指定（apiFetch の既定は GET）も再試行対象に含める。POST 等の
 * 副作用を伴うメソッドは、/wp-json/ 側で実際にリクエストが処理されていた
 * 場合に二重実行になるおそれがあるため、初回失敗時の再試行対象からは外す
 * （安藤レビュー指摘 MEDIUM-1）。なお、既にフォールバックに成功した後の
 * 「最初からフォールバック形式で送る」動作は、再試行ではなくその回にとって
 * 唯一の試行のため、メソッドを問わず適用する。
 *
 * @param {Object} options apiFetch に渡された options。
 * @return {boolean} 再試行してよい場合 true。
 */
const isRetryableMethod = ( options ) => {
	const method =
		typeof options.method === 'string'
			? options.method.toUpperCase()
			: 'GET';

	return 'GET' === method || 'HEAD' === method;
};

/**
 * REST フォールバック用の apiFetch ミドルウェアを登録する。
 *
 * `window.vkbmReservationConfig` の restRoot / restFallbackRoot が無い、または
 * 両者が同じ（既に基本パーマリンクで ?rest_route= 形式になっている）場合は、
 * フォールバックする意味が無いため何もしない。
 *
 * 他プラグインの apiFetch 呼び出しにも影響しうる点を考慮し、対象は
 * `path` 指定かつ invalid_json エラーのリクエストのみに絞り、実害を最小化する。
 *
 * @return {void}
 */
export const registerRestFallbackMiddleware = () => {
	if ( middlewareRegistered ) {
		return;
	}
	middlewareRegistered = true;

	const config =
		typeof window !== 'undefined' && window.vkbmReservationConfig
			? window.vkbmReservationConfig
			: {};
	const restRoot = typeof config.restRoot === 'string' ? config.restRoot : '';
	const restFallbackRoot =
		typeof config.restFallbackRoot === 'string'
			? config.restFallbackRoot
			: '';

	if ( ! restRoot || ! restFallbackRoot || restRoot === restFallbackRoot ) {
		return;
	}

	apiFetch.use( ( options, next ) => {
		// path 指定以外（url を直接指定したリクエストなど）は対象外。
		if ( typeof options.path !== 'string' ) {
			return next( options );
		}

		// 既に同一ページ内でフォールバックに成功していれば、最初からフォールバック
		// 形式で送る（無駄な失敗リクエストを毎回挟まない）。POST 等も含め、
		// メソッドを問わず適用する（これは「再試行」ではなく、その回にとって
		// 唯一の試行のため、副作用の二重実行を心配する必要が無い）。
		if ( hasSucceededWithFallback ) {
			return next( toFallbackOptions( options, restFallbackRoot ) );
		}

		// GET/HEAD（method 未指定を含む）以外は、初回失敗時の自動再試行の対象外。
		// /wp-json/ 側で実際に処理済みの可能性がある副作用ありのリクエストを
		// 二重実行しないため（安藤レビュー指摘 MEDIUM-1）。
		if ( ! isRetryableMethod( options ) ) {
			return next( options );
		}

		return next( options ).catch( ( error ) => {
			if ( error?.code !== 'invalid_json' ) {
				throw error;
			}

			// invalid_json のときだけ、フォールバック用ルートで1回だけ再試行する。
			return next( toFallbackOptions( options, restFallbackRoot ) ).then(
				( response ) => {
					hasSucceededWithFallback = true;
					return response;
				}
			);
		} );
	} );
};

/**
 * テスト用: モジュール内部状態（登録済みフラグ・フォールバック成功フラグ）をリセットする。
 *
 * @return {void}
 */
export const __resetRestFallbackStateForTests = () => {
	hasSucceededWithFallback = false;
	middlewareRegistered = false;
};
