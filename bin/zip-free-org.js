#!/usr/bin/env node

/**
 * Create WordPress.org compliant zip file for free edition.
 * This script temporarily removes the Domain Path header since languages/
 * directory is excluded from the zip.
 */

const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const distRoot = path.join(process.cwd(), 'dist');
const pluginDir = path.join(distRoot, 'vk-booking-manager');
const pluginFile = path.join(pluginDir, 'vk-booking-manager.php');
const zipFile = path.join(distRoot, 'vk-booking-manager-org.zip');

if (!fs.existsSync(pluginFile)) {
	console.error(`Plugin file not found: ${pluginFile}`);
	process.exit(1);
}

// Read original file content
const originalContent = fs.readFileSync(pluginFile, 'utf8');

// Remove Domain Path header
const modifiedContent = originalContent.replace(/^\s*\*\s*Domain Path:.*$/m, '');

// Write modified content
fs.writeFileSync(pluginFile, modifiedContent);

try {
	// Remove existing zip file
	if (fs.existsSync(zipFile)) {
		fs.unlinkSync(zipFile);
	}

	// Create zip file with exclusions
	const excludePatterns = [
		'vk-booking-manager/phpunit.xml.dist',
		'vk-booking-manager/tests/*',
		'vk-booking-manager/tests/*/*',
		'vk-booking-manager/tests/*/*/*',
		'vk-booking-manager/test/*',
		'vk-booking-manager/test/*/*',
		'vk-booking-manager/test/*/*/*',
		'vk-booking-manager/composer.lock',
		'vk-booking-manager/package-lock.json',
		'vk-booking-manager/docs/*',
		'vk-booking-manager/docs/*/*',
		'vk-booking-manager/docs/*/*/*',
		'vk-booking-manager/load-plugin-textdomain.php',
		'vk-booking-manager/class-vkbm-github-updater.php',
		'vk-booking-manager/languages/*',

	];

	const excludeArgs = excludePatterns.map((pattern) => `-x "${pattern}"`).join(' ');
	const zipCommand = `cd ${distRoot} && zip -qr vk-booking-manager-org.zip vk-booking-manager ${excludeArgs}`;

	execSync(zipCommand, { stdio: 'inherit' });
} finally {
	// Restore original file content
	fs.writeFileSync(pluginFile, originalContent);
}

console.log('WordPress.org compliant zip file created: vk-booking-manager-org.zip');
