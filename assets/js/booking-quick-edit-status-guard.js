/**
 * #477 植草さんレビュー対応（FAIL）: 予約一覧のクイック編集で、担当スタッフ未割当の予約を
 * 開いたとき、枠を消費するステータス（confirmed・pending）の選択肢を disabled にしていたが、
 * 「現在まさに選ばれている値」まで disabled 対象に含めてしまっていた。指名機能OFF時は
 * 担当が0へ正規化される（src/bookings/class-booking-confirmation-controller.php）ため、
 * 「担当未割当のまま仮予約（pending）」は旧データ限定ではなく日常的に起こりうる状態であり、
 * このとき通知文が「このステータスは選べません」と言いながら今まさに選ばれている値が
 * グレーアウトして見える、という矛盾した画面になっていた。
 *
 * この判定（どの選択肢を disabled にすべきか）を DOM 操作から切り離した純粋関数として
 * 独立させ、Jest（assets/js/__tests__/booking-quick-edit-status-guard.test.js）で
 * ロジックだけを検証できるようにする（coding-rules.md「テスト容易性」）。
 *
 * このファイルは通常のスクリプトとして wp_enqueue_script() で直接読み込まれるため、
 * import/export 構文は使わない（クラシックスクリプトとして読み込むとブラウザで
 * 構文エラーになる）。ブラウザでは window.vkbmBookingQuickEditStatusGuard へ、
 * Node（Jest）では module.exports へ、同じ関数を公開する。
 *
 * @param {Window|Object} root 公開先のグローバルオブジェクト（ブラウザでは window、
 *                             Node/Jest では this）。
 */
( function ( root ) {
	'use strict';

	/**
	 * 指定した選択肢（ステータス）を disabled にすべきかどうかを判定する。
	 *
	 * @param {string}   optionValue    判定対象の選択肢の値（ステータスキー）。
	 * @param {string}   currentStatus  この予約に現在保存されているステータス
	 *                                  （プルダウンを開いた時点の初期選択値）。
	 * @param {string[]} targetStatuses 担当スタッフが必須のステータス一覧
	 *                                  （枠を消費するステータス。is_staff_check_target_status() と同じ基準）。
	 * @return {boolean} true なら disabled にする。
	 */
	function shouldDisableStatusOption(
		optionValue,
		currentStatus,
		targetStatuses
	) {
		if ( optionValue === currentStatus ) {
			// 現在選択中の値は、担当スタッフ必須のステータスであっても disabled にしない。
			// ステータスを維持したまま担当だけ後で割り当てたい操作を妨げないため、また
			// 「選べません」という通知と「今まさに選ばれている」状態が同時に見えて矛盾する
			// ことを避けるため（司からの差し戻し・植草さんFAIL対応）。
			return false;
		}

		return targetStatuses.indexOf( optionValue ) !== -1;
	}

	const api = { shouldDisableStatusOption };

	// #477 安藤さんレビュー対応（LOW）: 以前は `typeof module !== 'undefined' && module.exports`
	// の判定を先に行い、真なら window への公開を else 節でスキップしていた。ブラウザ側に
	// 何らかの `module` グローバルが存在する環境では window.vkbmBookingQuickEditStatusGuard が
	// 定義されず、booking-quick-edit.js 側の statusGuard が null になって早期 return する
	// （選択肢の無効化も通知も出ないまま、サーバー側で保存だけが黙って中断される状態に戻る）。
	// この失敗は無言で起きるため、root への公開は分岐に関わらず必ず行い、Node/Jest 向けの
	// module.exports は追加の代入として行う（else にしない）。
	root.vkbmBookingQuickEditStatusGuard = api;
	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = api;
	}
} )( typeof window !== 'undefined' ? window : this ); // eslint-disable-line no-invalid-this
