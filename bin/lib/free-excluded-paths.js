const fs = require( 'fs' );
const path = require( 'path' );

/**
 * 無料版の配布物（dist）から取り除く、Pro 版専用のファイル・ディレクトリ。
 *
 * パスはプラグインルートからの相対パス。1 つのファイルの中で `Pro_Upsell::is_free_edition()`
 * などにより実行時に分岐しているコードは、ファイル単位では外せないためここに入れない。
 * ここに足したファイルは、vk-booking-manager.php の `require_once` を
 * `@free-version-remove` の目印で囲み、クラスを使う箇所を `class_exists` で確認すること
 * （無いと無料版が Fatal エラーになる）。
 * 一覧と理由は docs/specification-distribution.md の「Pro専用コードの除外」を正とする。
 */
const FREE_EXCLUDED_PATHS = [
	// Pro 版の自動更新（無料版は class-vkbm-github-updater.php を使う）。
	'class-vkbm-pro-updater.php',
	// Google カレンダー連携（Pro 版限定）。
	'src/integrations/google-calendar',
	// リソース（スタッフ）削除ガード（無料版ではスタッフの管理画面が出ないため動かない）。
	'src/resources/class-resource-delete-guard.php',
	'assets/js/resource-delete-guard.js',
	// 上記を対象にしたテスト（無料版の dist の tests/ に残すと、削除済みのクラスを読んで失敗する）。
	'tests/phpunit/integrations/google-calendar',
	'tests/phpunit/admin/test-setup-notices-google-calendar.php',
	'tests/phpunit/admin/test-provider-settings-page-integration-tab.php',
	'tests/phpunit/resources/test-resource-delete-guard.php',
	'tests/phpunit/uninstall/test-uninstaller-google-revoke.php',
	'tests/e2e/specs/resource-delete-guard.spec.ts',
];

/**
 * dist から Pro 版専用のファイル・ディレクトリを削除する。
 *
 * @param {string}   distRoot dist 内のプラグインルートの絶対パス。
 * @param {string[]} paths    削除対象（プラグインルートからの相対パス）。省略時は FREE_EXCLUDED_PATHS。
 * @return {string[]} 実際に削除した相対パスの配列（存在しないものは含めない）。
 */
function removeFreeExcludedPaths( distRoot, paths = FREE_EXCLUDED_PATHS ) {
	const removed = [];

	for ( const relativePath of paths ) {
		// dist の外や dist 自身を指すパスは、誤って dist 外を消さないよう例外にする。
		const resolvedRoot = path.resolve( distRoot );
		const targetPath = path.resolve( resolvedRoot, relativePath );
		const relativeToRoot = path.relative( resolvedRoot, targetPath );
		if (
			relativeToRoot === '' ||
			relativeToRoot.startsWith( '..' ) ||
			path.isAbsolute( relativeToRoot )
		) {
			throw new Error(
				`Refusing to remove a path outside dist: ${ relativePath }`
			);
		}
		if ( ! fs.existsSync( targetPath ) ) {
			continue;
		}
		fs.rmSync( targetPath, { recursive: true, force: true } );
		removed.push( relativePath );
	}

	return removed;
}

module.exports = { FREE_EXCLUDED_PATHS, removeFreeExcludedPaths };
