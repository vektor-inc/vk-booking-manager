#!/usr/bin/env node
const fs = require('fs');
const path = require('path');
const sass = require('sass');
const CleanCSS = require('clean-css');

const ROOT = path.resolve(__dirname, '..');
const SOURCE_DIR = path.join(ROOT, 'assets', 'scss');
const OUTPUT_DIR = path.join(ROOT, 'build', 'assets', 'css');

const BUNDLES = {
	'vkbm-frontend.min.css': [
		'variables.scss',
		'common.scss',
	],
	'vkbm-auth.min.css': [
		'variables.scss',
		'buttons.scss',
		'alert.scss',
		'auth-forms.scss',
	],
	'vkbm-editor.min.css': [
		'variables.scss',
		'utility.scss',
		'buttons.scss',
		'alert.scss',
		'auth-forms.scss',
		'admin-editor-fixes.scss',
		'common.scss',
	],
	'vkbm-admin.min.css': [
		'variables.scss',
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

function readScssFile(fileName) {
	const fullPath = path.join(SOURCE_DIR, fileName);
	if (!fs.existsSync(fullPath)) {
		throw new Error(`Missing source: ${fullPath}`);
	}
	const result = sass.compile(fullPath, {
		style: 'expanded',
		loadPaths: [SOURCE_DIR],
	});
	return result.css;
}

function minifyCss(css) {
	const result = new CleanCSS({ level: 2 }).minify(css);
	if (result.errors && result.errors.length) {
		throw new Error(result.errors.join('\n'));
	}
	return result.styles.trim();
}

function buildBundles() {
	fs.mkdirSync(OUTPUT_DIR, { recursive: true });
	Object.entries(BUNDLES).forEach(([outputName, sources]) => {
		const parts = sources.map(readScssFile);
		const bundled = minifyCss(parts.join('\n'));
		const outputPath = path.join(OUTPUT_DIR, outputName);
		fs.writeFileSync(outputPath, `${bundled}\n`, 'utf8');
	});
}

buildBundles();
