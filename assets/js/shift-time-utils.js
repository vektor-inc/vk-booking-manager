/**
 * シフト編集画面（assets/js/shift-editor.js）で使う、時刻・時間帯の検査処理。
 *
 * 画面で選んだ時刻を保存用の隠し項目（vkbm_shift[days_json]）へ書き込む前の検査と、
 * 保存済みの時間帯を画面へ読み込むときの検査を、DOM 操作から切り離した純粋関数として
 * 独立させ、Jest（assets/js/__tests__/shift-time-utils.test.js）でロジックだけを
 * 検証できるようにする（coding-rules.md「テスト容易性」）。
 *
 * このファイルは通常のスクリプトとして wp_enqueue_script() で直接読み込まれるため、
 * import/export 構文は使わない。ブラウザでは window.vkbmShiftTimeUtils へ、
 * Node（Jest）では module.exports へ、同じ関数を公開する。
 *
 * @param {Window|Object} root 公開先のグローバルオブジェクト（ブラウザでは window、
 *                             Node/Jest では this）。
 */
( function ( root ) {
	'use strict';

	/**
	 * 時刻文字列（HH:MM）を検査し、正しければ前後の空白を除いた値を返す。
	 *
	 * 00:00〜23:59 に加えて、終了時刻として使う 24:00 も受け付ける。24:01〜24:59 は不正とする
	 * （PHP 側 Shift_Editor::sanitize_time() と同じ基準。#501）。
	 *
	 * @param {*} time 検査する値。
	 * @return {string} 正しい時刻ならその値、不正なら空文字。
	 */
	function sanitizeTime( time ) {
		if ( 'string' !== typeof time ) {
			return '';
		}

		const trimmed = time.trim();
		return /^(([01][0-9]|2[0-3]):[0-5][0-9]|24:00)$/.test( trimmed )
			? trimmed
			: '';
	}

	/**
	 * 開始・終了の時刻から時間帯を作る。どちらかが不正、または終了が開始より後でなければ null。
	 *
	 * @param {*} start 開始時刻。
	 * @param {*} end   終了時刻。
	 * @return {{start: string, end: string}|null} 時間帯、または保存できない場合は null。
	 */
	function buildSlot( start, end ) {
		const sanitizedStart = sanitizeTime( start );
		const sanitizedEnd = sanitizeTime( end );

		// HH:MM 形式どうしは文字列比較で時刻の前後を判定できる。
		if (
			! sanitizedStart ||
			! sanitizedEnd ||
			sanitizedEnd <= sanitizedStart
		) {
			return null;
		}

		return { start: sanitizedStart, end: sanitizedEnd };
	}

	/**
	 * 画面のプルダウン（時・分）の値から時間帯を作る。
	 *
	 * @param {string} startHour   開始（時）。
	 * @param {string} startMinute 開始（分）。
	 * @param {string} endHour     終了（時）。
	 * @param {string} endMinute   終了（分）。
	 * @return {{start: string, end: string}|null} 時間帯、または保存できない場合は null。
	 */
	function buildSlotFromSelectValues(
		startHour,
		startMinute,
		endHour,
		endMinute
	) {
		return buildSlot(
			`${ startHour }:${ startMinute }`,
			`${ endHour }:${ endMinute }`
		);
	}

	/**
	 * 終了（時）として 24 を選んだときに使える唯一の終了（分）。24:00 だけが正しい時刻のため。
	 */
	const END_OF_DAY_HOUR = '24';
	const END_OF_DAY_MINUTE = '00';

	/**
	 * 終了（時）の値のもとで、終了（分）の選択肢を選べるかどうかを判定する（#501）。
	 *
	 * 終了（時）が 24 のときは 00 分だけを選べる。それ以外の時はどの分も選べる。
	 *
	 * @param {string} endHour 終了（時）の値。
	 * @param {string} minute  判定する終了（分）の選択肢の値。
	 * @return {boolean} 選べるなら true。
	 */
	function isEndMinuteSelectable( endHour, minute ) {
		if ( END_OF_DAY_HOUR !== String( endHour ) ) {
			return true;
		}

		return END_OF_DAY_MINUTE === String( minute );
	}

	/**
	 * 終了（時）の値に合わせて、終了（分）として入れておくべき値を返す（#501）。
	 *
	 * 終了（時）が 24 なら 00 分にそろえる（24:30 のような保存できない時刻が残り、更新時に
	 * 黙って捨てられるのを防ぐ）。それ以外の時は今の値をそのまま返す。
	 *
	 * @param {string} endHour   終了（時）の値。
	 * @param {string} endMinute 今の終了（分）の値。
	 * @return {string} 終了（分）として入れておくべき値。
	 */
	function resolveEndMinute( endHour, endMinute ) {
		return isEndMinuteSelectable( endHour, endMinute )
			? endMinute
			: END_OF_DAY_MINUTE;
	}

	/**
	 * 保存済みデータなどから受け取った時間帯の一覧を検査し、保存できるものだけを返す。
	 *
	 * @param {*} slots 時間帯（{start, end}）の配列。
	 * @return {Array<{start: string, end: string}>} 検査を通った時間帯の配列。
	 */
	function normalizeSlotList( slots ) {
		if ( ! Array.isArray( slots ) ) {
			return [];
		}

		return slots
			.map( ( slot ) => {
				if ( ! slot || 'object' !== typeof slot ) {
					return null;
				}

				return buildSlot( slot.start || '', slot.end || '' );
			} )
			.filter( ( slot ) => !! slot );
	}

	const api = {
		sanitizeTime,
		buildSlot,
		buildSlotFromSelectValues,
		normalizeSlotList,
		isEndMinuteSelectable,
		resolveEndMinute,
	};

	// root への公開は分岐に関わらず必ず行い、Node/Jest 向けの module.exports は追加の代入として行う
	// （booking-quick-edit-status-guard.js と同じ方針。ブラウザに module グローバルがあっても
	// window 側の公開が抜けないようにする）。
	root.vkbmShiftTimeUtils = api;
	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = api;
	}
} )( typeof window !== 'undefined' ? window : this ); // eslint-disable-line no-invalid-this
