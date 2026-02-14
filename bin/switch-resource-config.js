const fs = require('fs');
const path = require('path');

const edition = process.argv[2];
if (edition !== 'free' && edition !== 'pro') {
	console.error('Usage: node bin/switch-resource-config.js <free|pro> [distDirName]');
	process.exit(1);
}

const distDirName = process.argv[3] || 'vk-booking-manager';
const distRoot = path.join(process.cwd(), 'dist', distDirName);
const configDir = path.join(distRoot, 'src', 'post-types');
const sourcePath = path.join(
	configDir,
	`resource-post-type-config-${edition}.php`
);
const targetPath = path.join(configDir, 'resource-post-type-config.php');
const staffDir = path.join(distRoot, 'src', 'staff');
const staffSourcePath = path.join(
	staffDir,
	`class-staff-editor-${edition}.php`
);
const staffTargetPath = path.join(staffDir, 'class-staff-editor.php');

if (!fs.existsSync(sourcePath)) {
	console.error(`Config source not found: ${sourcePath}`);
	process.exit(1);
}

fs.copyFileSync(sourcePath, targetPath);

const otherEdition = edition === 'free' ? 'pro' : 'free';
const otherPath = path.join(
	configDir,
	`resource-post-type-config-${otherEdition}.php`
);
const otherStaffPath = path.join(
	staffDir,
	`class-staff-editor-${otherEdition}.php`
);

if (fs.existsSync(otherPath)) {
	fs.unlinkSync(otherPath);
}

if (fs.existsSync(sourcePath)) {
	fs.unlinkSync(sourcePath);
}

if (!fs.existsSync(staffSourcePath)) {
	console.error(`Staff editor source not found: ${staffSourcePath}`);
	process.exit(1);
}

fs.copyFileSync(staffSourcePath, staffTargetPath);

if (fs.existsSync(otherStaffPath)) {
	fs.unlinkSync(otherStaffPath);
}

if (fs.existsSync(staffSourcePath)) {
	fs.unlinkSync(staffSourcePath);
}

if (edition === 'pro') {
	const pluginFilePath = path.join(distRoot, 'vk-booking-manager.php');
	if (fs.existsSync(pluginFilePath)) {
		const contents = fs.readFileSync(pluginFilePath, 'utf8');
		const updated = contents.replace(
			/^\s*\*\s*Plugin Name:.*$/m,
			' * Plugin Name: VK Booking Manager Pro'
		);
		if (updated !== contents) {
			fs.writeFileSync(pluginFilePath, updated);
		}
	}
}

if (edition === 'free') {
	const pluginFilePath = path.join(distRoot, 'vk-booking-manager.php');
	if (fs.existsSync(pluginFilePath)) {
		const contents = fs.readFileSync(pluginFilePath, 'utf8');
		const updated = contents
			.replace(
				/^\s*\*\s*Plugin Name:.*$/m,
				' * Plugin Name: VK Booking Manager'
			)
			.replace(
				/^(\s*\*\s*Plugin URI:\s*https:\/\/github\.com\/vektor-inc\/vk-booking-manager)-pro(\/?)/im,
				'$1$2'
			);
		if (updated !== contents) {
			fs.writeFileSync(pluginFilePath, updated);
		}
	}

	const freeReadmePath = path.join(distRoot, 'README-FREE.md');
	const readmePath = path.join(distRoot, 'README.md');
	if (fs.existsSync(freeReadmePath)) {
		fs.copyFileSync(freeReadmePath, readmePath);
		fs.unlinkSync(freeReadmePath);
	}
}
