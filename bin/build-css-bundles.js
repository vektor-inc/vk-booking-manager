#!/usr/bin/env node
const fs = require( 'fs' );
const path = require( 'path' );
const sass = require( 'sass' );
const CleanCSS = require( 'clean-css' );

const ROOT = path.resolve( __dirname, '..' );
const SOURCE_DIR = path.join( ROOT, 'assets', 'scss' );
const OUTPUT_DIR = path.join( ROOT, 'build', 'assets', 'css' );

const BUNDLES = {
	// --vkbm--* カスタムプロパティの既定値（:root ブロック）はここだけに出力する。
	// 他のバンドルから variables.scss を外し、この1本にまとめることで :root の重複出力を
	// 防ぐ（issue #420）。Common_Styles::register_styles() が他バンドルの $deps にこの
	// ハンドルを指定し、enqueue_block_assets（フロント・管理画面・ブロックエディターの
	// キャンバス iframe いずれでも発火する）で確実に読み込ませる。
	'vkbm-variables.min.css': [ 'variables.scss' ],
	// 全管理画面に出る通知（セットアップ案内など）専用の小さなバンドル。
	// 管理画面用の vkbm-admin は VKBM の画面だけで読み込む（issue #659）。
	'vkbm-admin-notice.min.css': [ 'admin-notice.scss' ],
	'vkbm-frontend.min.css': [ 'common.scss' ],
	// ブロック（予約・メニュー一覧・メニューカード・予約ボタン）と認証ショートコードで
	// 共有する部品。各ブロックの style-index.css に同じ内容をコピーさせず、ここ1本に
	// 集約する（issue #660）。各 block.json の style と vkbm-auth の $deps で参照する。
	// ブロックが無いページには読み込まれない（vkbm-frontend の common.scss は従来どおり
	// 全フロントページ）。
	'vkbm-components.min.css': [ 'alert.scss', 'utility.scss', 'buttons.scss' ],
	// 認証フォーム固有の部分のみ。buttons / alert は vkbm-components に依存して読み込む。
	'vkbm-auth.min.css': [ 'auth-forms.scss', 'attendee-roster.scss' ],
	// id が付いていて `wp-` で始まらず、`.editor-styles-wrapper` / `.wp-block` を含むスタイルシートは、
	// ブロックエディターのキャンバスの末尾へ複製され、ブロックの CSS を上書きする。
	// ブロックエディター用の補正のみ。ボタン・アラートなどの部品は含めない。部品は
	// vkbm-auth 経由で vkbm-components（さらに vkbm-frontend）に依存して読み込み、各ブロックの
	// CSS より前に1回だけ出す。部品をここに含めると、エディターのキャンバス内でブロック自身の
	// CSS より後に読まれて上書きし、ブロックの見た目が変わるため。vkbm-admin には依存させない
	// （依存させると全ブロックエディターに読み込まれ、VKBM の画面だけで読み込む方針（issue #659）に反する）。
	'vkbm-editor.min.css': [ 'admin-editor-fixes.scss' ],
	'vkbm-admin.min.css': [
		'variables-admin.scss',
		'utility.scss',
		'buttons.scss',
		'alert.scss',
		'auth-forms.scss',
		'admin-table.scss',
		'admin-schedule.scss',
		'admin-provider-settings.scss',
		'admin-shift-editor.scss',
		'admin-shift-bulk-create.scss',
		'admin-shift-dashboard.scss',
		'admin-resource-delete-guard.scss',
		'admin-service-menu-quick-edit.scss',
		'admin-service-menu-gallery.scss',
		'admin-order.scss',
		'admin-style-guide.scss',
		'admin-email-log.scss',
		'admin-customer-flag.scss',
		'admin-core.scss',
		'common.scss',
	],
};

function readScssFile( fileName ) {
	const fullPath = path.join( SOURCE_DIR, fileName );
	if ( ! fs.existsSync( fullPath ) ) {
		throw new Error( `Missing source: ${ fullPath }` );
	}
	const result = sass.compile( fullPath, {
		style: 'expanded',
		loadPaths: [ SOURCE_DIR ],
	} );
	return result.css;
}

function minifyCss( css ) {
	const result = new CleanCSS( { level: 2 } ).minify( css );
	if ( result.errors && result.errors.length ) {
		throw new Error( result.errors.join( '\n' ) );
	}
	return result.styles.trim();
}

function buildBundles() {
	fs.mkdirSync( OUTPUT_DIR, { recursive: true } );
	Object.entries( BUNDLES ).forEach( ( [ outputName, sources ] ) => {
		const parts = sources.map( readScssFile );
		const bundled = minifyCss( parts.join( '\n' ) );
		const outputPath = path.join( OUTPUT_DIR, outputName );
		fs.writeFileSync( outputPath, `${ bundled }\n`, 'utf8' );
	} );
}

buildBundles();
