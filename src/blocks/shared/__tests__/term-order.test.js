import { sortTermsByOrder } from '../term-order';

/**
 * sortTermsByOrder() のテスト（#463）。
 *
 * 公開画面の予約ページのリソースタグ検索チェックボックス一覧が、REST から
 * 取得した順（名前順）のまま表示されてしまう不具合の再発防止テスト。
 * サーバー側（Term_Order_Manager::get_order_value_for_rest()）が REST レスポンスに
 * 足す 'order' フィールドを使って正しく並べ替えられることを確認する。
 */
describe( 'sortTermsByOrder', () => {
	it( '正常系: order の値の昇順に並べ替える', () => {
		const terms = [
			{ id: 1, name: 'Bravo', order: 2 },
			{ id: 2, name: 'Alpha', order: 3 },
			{ id: 3, name: 'Charlie', order: 1 },
		];

		const sorted = sortTermsByOrder( terms ).map( ( term ) => term.id );

		expect( sorted ).toEqual( [ 3, 1, 2 ] );
	} );

	it( '正常系: order が同じタームどうしは名前順にする（サーバー側 usort 実装と同じ扱い）', () => {
		const terms = [
			{ id: 1, name: 'Charlie', order: 1 },
			{ id: 2, name: 'Alpha', order: 1 },
			{ id: 3, name: 'Bravo', order: 1 },
		];

		const sorted = sortTermsByOrder( terms ).map( ( term ) => term.id );

		expect( sorted ).toEqual( [ 2, 3, 1 ] );
	} );

	it( '境界値: order フィールドが無い・数値でないタームは 0 として扱い、一覧から消さない', () => {
		const terms = [
			{ id: 1, name: 'HasOrder', order: 5 },
			{ id: 2, name: 'NoOrderField' },
			{ id: 3, name: 'InvalidOrder', order: 'invalid' },
		];

		const sorted = sortTermsByOrder( terms );

		// 3件とも一覧から消えないこと。
		expect( sorted ).toHaveLength( 3 );
		// order が無い・不正な2件は 0 扱いのため名前順（InvalidOrder → NoOrderField）で
		// 先頭に来て、最後に order:5 の HasOrder が来る。
		expect( sorted.map( ( term ) => term.id ) ).toEqual( [ 3, 2, 1 ] );
	} );

	it( '異常系: 配列でない値を渡した場合は空配列を返す', () => {
		expect( sortTermsByOrder( null ) ).toEqual( [] );
		expect( sortTermsByOrder( undefined ) ).toEqual( [] );
	} );

	it( '元の配列を変更せず、新しい配列を返す', () => {
		const terms = [
			{ id: 1, name: 'B', order: 2 },
			{ id: 2, name: 'A', order: 1 },
		];
		const original = [ ...terms ];

		const sorted = sortTermsByOrder( terms );

		expect( terms ).toEqual( original );
		expect( sorted ).not.toBe( terms );
	} );
} );
