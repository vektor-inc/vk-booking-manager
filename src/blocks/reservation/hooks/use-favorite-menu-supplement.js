import { useEffect, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

// 既定の取り寄せ関数。描画のたびに作り直すと effect が毎回走るため、モジュールで1つだけ持つ。
const fetchMenusByPath = ( path ) => apiFetch( { path } );

/**
 * お気に入りのメニューを、取得済みのメニュー一覧から探す。
 *
 * @param {Object}        favorite お気に入り（menu_id を持つ）。
 * @param {Array<Object>} menus    取得済みのメニュー一覧。
 * @return {Object|undefined} 見つかったメニュー。未取得なら undefined。
 */
export const findFavoriteMenu = ( favorite, menus ) => {
	const favoriteMenuId = Number( favorite?.menu_id ) || 0;
	if ( favoriteMenuId <= 0 || ! Array.isArray( menus ) ) {
		return undefined;
	}
	return menus.find( ( menu ) => menu.id === favoriteMenuId );
};

/**
 * お気に入りが指すメニューのうち、メニュー一覧に無いメニューの ID を重複なしで返す。
 *
 * @param {Array<Object>} favorites お気に入り一覧。
 * @param {Array<Object>} menus     取得済みのメニュー一覧。
 * @return {Array<number>} 一覧に無いメニューの ID。
 */
export const findMissingFavoriteMenuIds = ( favorites, menus ) => {
	if ( ! Array.isArray( favorites ) ) {
		return [];
	}
	const loadedIds = new Set(
		( Array.isArray( menus ) ? menus : [] ).map( ( menu ) => menu.id )
	);
	const missing = new Set();
	favorites.forEach( ( favorite ) => {
		const menuId = Number( favorite?.menu_id ) || 0;
		if ( menuId > 0 && ! loadedIds.has( menuId ) ) {
			missing.add( menuId );
		}
	} );
	return Array.from( missing );
};

/**
 * メニュー一覧の末尾に、まだ含まれていないメニューだけを足して返す。
 *
 * @param {Array<Object>} menus     取得済みのメニュー一覧。
 * @param {Array<Object>} additions 足したいメニュー。
 * @return {Array<Object>} 足すものが無ければ menus そのもの。
 */
export const mergeMenus = ( menus, additions ) => {
	const current = Array.isArray( menus ) ? menus : [];
	if ( ! Array.isArray( additions ) || additions.length === 0 ) {
		return current;
	}
	const loadedIds = new Set( current.map( ( menu ) => menu.id ) );
	const newMenus = additions.filter(
		( menu ) => menu && ! loadedIds.has( menu.id )
	);
	return newMenus.length > 0 ? [ ...current, ...newMenus ] : current;
};

/**
 * お気に入りが指すメニューがメニュー一覧（1ページ分だけ取得）に無いとき、
 * 同じ REST 経路に include を付けて取り寄せ、メニュー一覧へ足す（#1144）。
 *
 * メニュー一覧の取得が終わるまでは何もしない（取得前は「一覧に無い」と区別できないため）。
 * 取り寄せても返らなかったメニュー（担当できるリソースがいない・非公開など）は、
 * 同じ一覧の経路では再び取り寄せない。通信に失敗したメニューは、お気に入りやメニュー一覧が
 * 次に更新されたときに取り寄せ直す。
 *
 * @param {Object}                                              args              引数。
 * @param {Array<Object>}                                       args.favorites    お気に入り一覧。
 * @param {Array<Object>}                                       args.menus        取得済みのメニュー一覧。
 * @param {boolean}                                             args.menusLoaded  メニュー一覧の取得が終わっていれば true。
 * @param {(ids:Array<number>)=>string}                         args.buildPath    メニュー ID の配列から取り寄せ用の REST パスを作る関数。
 * @param {(update:(menus:Array<Object>)=>Array<Object>)=>void} args.setMenus     メニュー一覧の state 更新関数。
 * @param {(path:string)=>Promise<Array<Object>>}               [args.fetchMenus] 取り寄せに使う関数（テスト用。既定は apiFetch）。
 */
export const useFavoriteMenuSupplement = ( {
	favorites,
	menus,
	menusLoaded,
	buildPath,
	setMenus,
	fetchMenus = fetchMenusByPath,
} ) => {
	// 取り寄せを頼んだメニュー ID。返らなかった ID を繰り返し頼まないために持つ。
	const requestedIdsRef = useRef( new Set() );
	const buildPathRef = useRef( buildPath );

	useEffect( () => {
		// 一覧の経路（非公開メニューを含めるか）が変わったら、頼んだ記録を捨てて取り寄せ直す。
		if ( buildPathRef.current !== buildPath ) {
			buildPathRef.current = buildPath;
			requestedIdsRef.current = new Set();
		}

		if ( ! menusLoaded ) {
			return;
		}

		// 一覧に無く、まだ頼んでいないメニューだけを取り寄せる。
		const requestedIds = requestedIdsRef.current;
		const missingIds = findMissingFavoriteMenuIds(
			favorites,
			menus
		).filter( ( id ) => ! requestedIds.has( id ) );
		if ( missingIds.length === 0 ) {
			return;
		}
		missingIds.forEach( ( id ) => requestedIds.add( id ) );

		// 依存値が変わっても結果は捨てない（頼んだ記録が残るため、捨てると二度と取り寄せられない）。
		Promise.resolve()
			.then( () => fetchMenus( buildPath( missingIds ) ) )
			.then( ( result ) => {
				if ( Array.isArray( result ) && result.length > 0 ) {
					setMenus( ( current ) => mergeMenus( current, result ) );
				}
			} )
			.catch( () => {
				// 通信の失敗は「予約できないので返らなかった」と違い、時間をおけば取れるため記録から外す。
				// ここでは頼み直さず、お気に入りやメニュー一覧が次に更新されたときに頼み直す（失敗が続いても繰り返し頼まないため）。
				missingIds.forEach( ( id ) => requestedIds.delete( id ) );
			} );
	}, [ favorites, menus, menusLoaded, buildPath, setMenus, fetchMenus ] );
};
