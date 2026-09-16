/**
 * REST から取得したタームの配列を、管理画面で保存した並び順で並べ替える（#463）。
 *
 * サーバー側（Term_Order_Manager::get_order_value_for_rest()）が REST レスポンスへ
 * 足す 'order' フィールドの値をそのまま使う。並び順メタが無い・数値でないタームは
 * サーバー側と同じく 0 として扱う（一覧から消さないため）。
 * 並び順が同じタームどうしは名前順にする。これはサーバー側の既存 usort 実装
 * （class-resource-tag-taxonomy.php の get_tag_labels()）と同じ扱いに揃えている。
 * なお class-menu-loop-block.php の get_term_order_value() は欠損値を PHP_INT_MAX
 * （最後尾）として扱うため、この関数とは欠損値の扱いが異なる（#463 安藤さんレビュー指摘）。
 *
 * 元の配列は変更せず、新しい配列を返す。
 *
 * @param {Array} terms REST から取得したタームオブジェクトの配列。
 * @return {Array} 並び順で並べ替えた新しい配列。
 */
export const sortTermsByOrder = ( terms ) => {
	if ( ! Array.isArray( terms ) ) {
		return [];
	}

	const resolveOrder = ( term ) => {
		const value = term?.order;
		return typeof value === 'number' && Number.isFinite( value )
			? value
			: 0;
	};

	const resolveName = ( term ) => {
		return typeof term?.name === 'string' ? term.name : '';
	};

	return [ ...terms ].sort( ( a, b ) => {
		const orderA = resolveOrder( a );
		const orderB = resolveOrder( b );

		if ( orderA !== orderB ) {
			return orderA - orderB;
		}

		const nameA = resolveName( a );
		const nameB = resolveName( b );

		if ( nameA < nameB ) {
			return -1;
		}
		if ( nameA > nameB ) {
			return 1;
		}
		return 0;
	} );
};
