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
	'vkbm-frontend.min.css': [ 'common.scss' ],
	'vkbm-auth.min.css': [
		'buttons.scss',
		'alert.scss',
		'auth-forms.scss',
	],
	'vkbm-editor.min.css': [
		'utility.scss',
		'buttons.scss',
		'alert.scss',
		'auth-forms.scss',
		'admin-editor-fixes.scss',
		'common.scss',
	],
	'vkbm-admin.min.css': [
		'variables-admin.scss',
		'utility.scss',
		'buttons.scss',
		'alert.scss',
		'auth-forms.scss',
		'admin-notice.scss',
		'admin-table.scss',
		'admin-schedule.scss',
		'admin-provider-settings.scss',
		'admin-shift-editor.scss',
		'admin-shift-bulk-create.scss',
		'admin-shift-dashboard.scss',
		'admin-service-menu-quick-edit.scss',
		'admin-post-order.scss',
		'admin-term-order.scss',
		'admin-style-guide.scss',
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
