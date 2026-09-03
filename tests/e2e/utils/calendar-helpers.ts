import { expect } from '@playwright/test';
import type { Page, Locator } from '@playwright/test';

/**
 * 予約フォームのカレンダーで「空き枠のある日」を安定して選択する共有ヘルパー。
 *
 * 背景（フレークの原因）:
 * - app.js の fetchCalendar（useEffect 経由）が日別空き状況を Ajax（/vkbm/v1/calendar-meta）で取得する。
 *   取得中は calendarData が null のため dayMetaMap が空。
 * - calendar-grid.js の各日ボタンの disabled 判定は
 *   `! day.inMonth || Boolean(meta.is_disabled) || day.iso < todayIso` で、
 *   ロード中は meta.is_disabled が undefined のため「当月の未来日がすべて enabled」に見える。
 * - Ajax 完了で dayMetaMap が埋まると再レンダリングされ、空きの無い日が disabled に変わる。
 * - この再レンダリングの最中にクリックが着地すると "element is not enabled / not stable" で timeout する。
 *   さらにロード前の見せかけ enabled 日（＝空き無し日）を掴むと後続のスロット選択も落ちる。
 *
 * 対策:
 * 1. .vkbm-calendar が visible になるのを待つ。
 * 2. ロード完了（再レンダリング収束）を待つ。
 *    - calendar-meta の API レスポンス待ち（caller が reserve クリック前にセットアップした promise）と、
 *    - .vkbm-calendar__spinner（isLoading の間だけ描画される決定的シグナル）の detach 待ちを併用。
 *    spinner は setCalendarLoading(true) が render 後の useEffect で走るため未出現のレース窓があるが、
 *    最終的に .vkbm-calendar__day--available の出現を expect.poll で待つことで確実にロード完了を保証する。
 *      （--available は meta.available_slots > 0 = data ロード済みでしか付かない class）
 * 3. 掴む日付は「空き枠のある日（--available かつ enabled）」を優先する。
 *    該当が無い場合のみ従来の enabled セレクタにフォールバックする。
 * 4. クリック対象の日ボタンが visible かつ enabled（stable）になってからクリックする。
 *
 * @param page                 Playwright の Page オブジェクト
 * @param calendarMetaResponse reserve ボタンのクリック前に caller がセットアップした
 *                             page.waitForResponse(/calendar-meta/) の promise（任意）。
 *                             渡された場合はレスポンス着信を待ってから日付選択へ進む。
 */
export async function selectAvailableCalendarDay(
	page: Page,
	calendarMetaResponse?: Promise< unknown >
): Promise< void > {
	// 1. カレンダー表示を待つ。
	await page.waitForSelector( '.vkbm-calendar', {
		state: 'visible',
		timeout: 15000,
	} );

	// 2-a. caller が reserve クリック前に仕込んだ calendar-meta レスポンスを待つ（あれば）。
	//      これにより「クリック直後でまだ spinner 未出現」のレース窓を確実に通過する。
	if ( calendarMetaResponse ) {
		try {
			await calendarMetaResponse;
		} catch {
			// レスポンス待ちが（タイミング等で）取りこぼしても、後続の --available 出現待ちで
			// ロード完了を担保するため、ここでは握りつぶして次へ進む。
		}
	}

	// 2-b. ローディングスピナーが消える（= calendarLoading が false に戻る）のを待つ。
	//      isLoading の間だけ描画される決定的シグナルなので、detach 待ちで再レンダリング収束を待つ。
	const spinner = page.locator( '.vkbm-calendar__spinner' );
	await spinner
		.waitFor( { state: 'detached', timeout: 15000 } )
		.catch( () => {
			// すでに detach 済み・未出現でもよい（最終判定は --available の出現待ちで行う）。
		} );

	// 2-c. 掴める日（available 優先・無ければ enabled）が出現するか判定する。
	//      この処理は表示月ごとに行うため、ヘルパー化して翌月送りでも再利用する。
	const availableDays = page.locator(
		'.vkbm-calendar__day--available:not(:disabled):not([aria-disabled="true"]):not(.vkbm-calendar__day--disabled)'
	);
	// readiness 判定には available だけでなく enabled 件数も含める。
	// available のみを待つと、--available が最後まで付かないケースで poll が
	// タイムアウト（例外）になり、下のフォールバックに到達できずデッドコードになる。
	// available があれば available 件数を、無ければ enabled 件数を返して、
	// どちらか1つでも掴める日が現れた時点で待機を抜ける。
	const enabledDays = page.locator(
		'.vkbm-calendar__day:not(:disabled):not([aria-disabled="true"]):not(.vkbm-calendar__day--disabled)'
	);

	// 表示中の月に掴める日があるかを軽く待って判定する内部ヘルパー。
	// 見つかれば true、タイムアウトすれば false を返す（例外は投げない）。
	// 月境界フォールバックで「現在の月に枠が無いか」を判断するために使う。
	const waitForSelectableDayInCurrentMonth = async (
		timeout: number
	): Promise< boolean > => {
		try {
			await expect
				.poll(
					async () => {
						const availableCount = await availableDays.count();
						return availableCount > 0
							? availableCount
							: enabledDays.count();
					},
					{ timeout }
				)
				.toBeGreaterThan( 0 );
			return true;
		} catch {
			// タイムアウト = この表示月には掴める日が無い。翌月送りの判断に使う。
			return false;
		}
	};

	// まず現在の表示月で掴める日を待つ。
	let hasSelectableDay = await waitForSelectableDayInCurrentMonth( 15000 );

	// 月境界フォールバック（issue #324）:
	// JST 月初など、seeding 月とブラウザ表示月がズレて当月が空に見える場合に備え、
	// 現在の表示月で枠が0件なら「次の月」ボタンを押して翌月で再探索する。
	// 暴走防止に前進は最大2回まで。最終的な ">0" 判定は必ず下に残すため、
	// 真に空のカレンダー（本物の不具合）は従来どおり失敗させる。
	const maxMonthAdvances = 2;
	// 「次の月」ボタンは calendar-grid.js で aria-label `next month`（翻訳関数経由）。
	// e2e は ja ロケールのため実レンダリングは「次の月」になる。英日どちらでも掴めるよう
	// 両 name を OR で指定する。
	const nextMonthButton = page
		.getByRole( 'button', { name: 'next month' } )
		.or( page.getByRole( 'button', { name: '次の月' } ) );
	for (
		let advance = 0;
		! hasSelectableDay && advance < maxMonthAdvances;
		advance++
	) {
		// 「次の月」ボタンが押せなければこれ以上前進できないので打ち切る。
		if ( ( await nextMonthButton.count() ) === 0 ) {
			break;
		}
		await nextMonthButton.first().click();
		// 月遷移に伴う再フェッチ（spinner 表示→消滅）を待ってから再判定する。
		await spinner
			.waitFor( { state: 'detached', timeout: 15000 } )
			.catch( () => {
				// 未出現・既 detach でも次の readiness 待ちで担保する。
			} );
		hasSelectableDay = await waitForSelectableDayInCurrentMonth( 15000 );
	}

	// 翌月送りフォールバックを尽くしても掴める日が無ければ、真に空のカレンダー
	// （本物の不具合）として従来どおり失敗させる。
	expect(
		hasSelectableDay,
		'No selectable calendar day found in the current or next months'
	).toBe( true );

	// 3. 空き枠のある日を優先して掴む。available が無ければ従来の enabled セレクタに
	//    フォールバックする（ロード前の見せかけ enabled 日を避けつつ、available 不在でも進める）。
	const targetDay: Locator =
		( await availableDays.count() ) > 0
			? availableDays.first()
			: enabledDays.first();

	// 4. 対象の日ボタンが visible かつ enabled（stable）になってからクリックする。
	await targetDay.waitFor( { state: 'visible', timeout: 10000 } );
	await expect( targetDay ).toBeEnabled( { timeout: 10000 } );
	await targetDay.click();
}
