/**
 * issue #1059: Pro版のセミナー／宿泊ではシフト・予約表を月表示で開く。
 */
// @category 管理画面
import { test, expect, Page } from '@playwright/test';
import { configureProviderSettings } from '../utils/setup';
import { loginAsAdmin, wpCliArgs, wpEvalPhp } from '../utils/helpers';

const OPTION_NAME = 'vkbm_provider_settings';
const DASHBOARD_URL = '/wp-admin/admin.php?page=vkbm-shift-dashboard';
let originalSettingsJson: string | null = null;

type DashboardView = 'day' | 'month';

/**
 * 指定した表示が選択され、もう一方が非表示になっていることを確認する。
 *
 * @param page     Playwright の Page。
 * @param expected 選択される表示。
 */
async function expectSelectedView( page: Page, expected: DashboardView ) {
	const other: DashboardView = expected === 'day' ? 'month' : 'day';
	const expectedPanel = page.locator(
		`[data-vkbm-view-panel="${ expected }"]`
	);
	const otherPanel = page.locator( `[data-vkbm-view-panel="${ other }"]` );

	await expect( expectedPanel ).toHaveClass( /\bis-active\b/ );
	await expect( expectedPanel ).toBeVisible();
	await expect( otherPanel ).not.toHaveClass( /\bis-active\b/ );
	await expect( otherPanel ).toBeHidden();
	await expect(
		page.locator( `[data-vkbm-view="${ expected }"]` ).first()
	).toHaveAttribute( 'aria-pressed', 'true' );
	await expect(
		page.locator( `[data-vkbm-view="${ other }"]` ).first()
	).toHaveAttribute( 'aria-pressed', 'false' );
}

/**
 * SHOT_DIR が指定されているときだけ、確認用スクリーンショットを保存する。
 *
 * @param page Playwright の Page。
 * @param name 保存するファイル名（拡張子なし）。
 */
async function shot( page: Page, name: string ) {
	const directory = process.env.SHOT_DIR;
	if ( ! directory ) {
		return;
	}
	await page.screenshot( {
		path: `${ directory }/${ name }.png`,
		fullPage: true,
	} );
}

/**
 * テスト前の店舗設定を戻す。
 */
function restoreProviderSettings() {
	if ( originalSettingsJson ) {
		wpCliArgs( [
			'option',
			'update',
			OPTION_NAME,
			originalSettingsJson,
			'--format=json',
		] );
		return;
	}

	try {
		wpCliArgs( [ 'option', 'delete', OPTION_NAME ] );
	} catch {
		// テスト前から option が無ければ何もしない。
	}
}

test.describe.serial( 'issue #1059 Pro版シフト・予約表の業種別初期表示', () => {
	test.beforeAll( () => {
		// この回帰テストをFree版で誤実行して「月表示を確認済み」と扱わないよう、
		// WordPressが認識しているエディションを先に検証する。
		const edition = wpEvalPhp( `
			echo \\VKBookingManager\\Admin\\Pro_Upsell::is_free_edition() ? 'free' : 'pro';
		` ).trim();
		if ( edition !== 'pro' ) {
			throw new Error(
				`issue #1059 e2e requires the Pro edition, detected: ${ edition }`
			);
		}

		try {
			originalSettingsJson = wpCliArgs( [
				'option',
				'get',
				OPTION_NAME,
				'--format=json',
			] );
		} catch {
			originalSettingsJson = null;
		}
	} );

	test.afterAll( () => {
		restoreProviderSettings();
	} );

	for ( const preset of [ 'seminar', 'lodging' ] as const ) {
		// @title Pro版の対象業種では指定なしと不正指定を月表示で開き、有効な指定を優先する
		test( `${ preset } は月表示を既定にし、URLの有効な表示指定を優先する`, async ( {
			page,
		} ) => {
			// @step Pro版の業種をセミナーまたは宿泊へ切り替える
			await configureProviderSettings( { industry_preset: preset } );
			await loginAsAdmin( page );

			for ( const viewport of [
				{ name: 'desktop', width: 1440, height: 1000 },
				{ name: 'mobile', width: 390, height: 844 },
			] ) {
				await page.setViewportSize( viewport );

				// @step 表示指定なしでシフト・予約表を開く
				await page.goto( DASHBOARD_URL );
				// @check 月表示が選択され、日表示は非表示になっている
				await expectSelectedView( page, 'month' );
				await shot(
					page,
					`issue-1059-pro-${ preset }-${ viewport.name }-default-month`
				);

				// @step URLで日表示を明示してシフト・予約表を開く
				await page.goto( `${ DASHBOARD_URL }&vkbm_view=day` );
				// @check URL指定が優先され、日表示が選択されている
				await expectSelectedView( page, 'day' );

				// @step URLで月表示を明示してシフト・予約表を開く
				await page.goto( `${ DASHBOARD_URL }&vkbm_view=month` );
				// @check 月表示が選択されている
				await expectSelectedView( page, 'month' );

				// @step URLで未対応の week 表示を指定してシフト・予約表を開く
				await page.goto( `${ DASHBOARD_URL }&vkbm_view=week` );
				// @check 不正な指定を無視し、業種の既定である月表示が選択されている
				await expectSelectedView( page, 'month' );
			}
		} );
	}
} );
