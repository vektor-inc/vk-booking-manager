#!/usr/bin/env node
/**
 * Install script that tries prebuilt binaries first, falling back to node-gyp.
 *
 * This avoids requiring build tools (Python, C++ compiler, etc.) on platforms
 * where we ship prebuilt binaries.
 */
'use strict';

var spawn = require('child_process').spawn;
var prebuilt = require('./load-prebuilt');

var ROOT = __dirname;

/**
 * Try to load a prebuilt binary for the current platform.
 * Returns true if successful, false otherwise.
 */
function tryLoadPrebuilt() {
	var info = prebuilt.getPrebuiltPath();
	if (!info) {
		console.log(
			'No prebuilt binary found for ' +
				process.platform +
				'-' +
				process.arch
		);
		return false;
	}

	try {
		require(info.path);
		console.log('Using prebuilt binary: ' + info.name);
		return true;
	} catch (e) {
		console.log('Failed to load ' + info.name + ': ' + e.message);
		return false;
	}
}

/**
 * Run node-gyp to build from source.
 */
function buildFromSource() {
	console.log('Building from source with node-gyp...');

	var nodeGyp = process.platform === 'win32' ? 'node-gyp.cmd' : 'node-gyp';
	var args = ['rebuild'];

	var child = spawn(nodeGyp, args, {
		cwd: ROOT,
		stdio: 'inherit',
		shell: process.platform === 'win32'
	});

	child.on('close', function (code) {
		process.exit(code || 0);
	});

	child.on('error', function (err) {
		console.error('Failed to run node-gyp:', err.message);
		console.error('Please ensure you have build tools installed.');
		console.error('See: https://github.com/nodejs/node-gyp#installation');
		process.exit(1);
	});
}

// Main
if (tryLoadPrebuilt()) {
	console.log('Prebuilt binary works, skipping compilation.');
	process.exit(0);
} else {
	buildFromSource();
}
