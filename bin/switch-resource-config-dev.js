const fs = require('fs');
const path = require('path');

const edition = process.argv[2];
if (edition !== 'free' && edition !== 'pro') {
	console.error('Usage: node bin/switch-resource-config-dev.js <free|pro>');
	process.exit(1);
}

const repoRoot = process.cwd();
const configDir = path.join(repoRoot, 'src', 'post-types');
const sourcePath = path.join(
	configDir,
	`resource-post-type-config-${edition}.php`
);
const targetPath = path.join(configDir, 'resource-post-type-config.php');

const staffDir = path.join(repoRoot, 'src', 'staff');
const staffSourcePath = path.join(
	staffDir,
	`class-staff-editor-${edition}.php`
);
const staffTargetPath = path.join(staffDir, 'class-staff-editor.php');

if (!fs.existsSync(sourcePath)) {
	console.error(`Config source not found: ${sourcePath}`);
	process.exit(1);
}

if (!fs.existsSync(staffSourcePath)) {
	console.error(`Staff editor source not found: ${staffSourcePath}`);
	process.exit(1);
}

fs.copyFileSync(sourcePath, targetPath);
fs.copyFileSync(staffSourcePath, staffTargetPath);
