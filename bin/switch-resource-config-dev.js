const fs = require( 'fs' );
const path = require( 'path' );
const { injectGeneratedFileBanner } = require( './lib/generated-file-banner' );

const edition = process.argv[ 2 ];
if ( edition !== 'free' && edition !== 'pro' ) {
	console.error( 'Usage: node bin/switch-resource-config-dev.js <free|pro>' );
	process.exit( 1 );
}

const repoRoot = process.cwd();
const configDir = path.join( repoRoot, 'src', 'post-types' );
const sourcePath = path.join(
	configDir,
	`resource-post-type-config-${ edition }.php`
);
const targetPath = path.join( configDir, 'resource-post-type-config.php' );

const staffDir = path.join( repoRoot, 'src', 'staff' );
const staffSourcePath = path.join(
	staffDir,
	`class-staff-editor-${ edition }.php`
);
const staffTargetPath = path.join( staffDir, 'class-staff-editor.php' );

if ( ! fs.existsSync( sourcePath ) ) {
	console.error( `Config source not found: ${ sourcePath }` );
	process.exit( 1 );
}

if ( ! fs.existsSync( staffSourcePath ) ) {
	console.error( `Staff editor source not found: ${ staffSourcePath }` );
	process.exit( 1 );
}

fs.copyFileSync( sourcePath, targetPath );
// コピー直後に「直接編集禁止」バナーを注入する（冪等）。
injectGeneratedFileBanner( targetPath );

fs.copyFileSync( staffSourcePath, staffTargetPath );
// コピー直後に「直接編集禁止」バナーを注入する（冪等）。
injectGeneratedFileBanner( staffTargetPath );
