/**
 * Shared logic for loading native binaries.
 *
 * Used by both the install script (to check if we need node-gyp) and
 * the main module (to load the binary at runtime).
 */
'use strict';

var path = require('path');
var fs = require('fs');

var BINARIES_DIR = path.join(__dirname, 'binaries');
var LOCAL_BUILD_PATH = path.join(__dirname, 'build', 'Release', 'fs_ext.node');

// Cached binding and the source it was loaded from
var cachedBinding = null;
var cachedSource = null;

/**
 * Find the best matching prebuilt binary for the current platform.
 * Returns the filename if found, null otherwise.
 */
function findPrebuiltBinary() {
	var platform = process.platform;
	var arch = process.arch;
	var nodeVersionNum = parseInt(process.versions.node.split('.')[0], 10);

	// Check if running in Electron
	var isElectron = !!(process.versions && process.versions.electron);
	var electronVersion = isElectron ? process.versions.electron : null;

	var files;
	try {
		files = fs.readdirSync(BINARIES_DIR);
	} catch (e) {
		return null;
	}

	// Filter files that match our platform/arch
	// Format: fs-ext-{platform}-{arch}[-libc]-{runtime}-{version}.node
	var platformArch = platform + '-' + arch;
	var candidates = files.filter(function (f) {
		return (
			f.startsWith('fs-ext-') &&
			f.includes(platformArch) &&
			f.endsWith('.node')
		);
	});

	if (candidates.length === 0) {
		return null;
	}

	// Try Electron binary first
	if (isElectron && electronVersion) {
		var electronBinary = candidates.find(function (f) {
			return f.includes('electron-' + electronVersion);
		});
		if (electronBinary) {
			return electronBinary;
		}
	}

	// Try to find exact Node version match
	var nodeVersion = nodeVersionNum + '.0.0';
	var nodeBinary = candidates.find(function (f) {
		return f.includes('node-' + nodeVersion);
	});

	if (nodeBinary) {
		return nodeBinary;
	}

	// Find highest version <= current
	var nodeCandidates = candidates
		.filter(function (f) {
			return f.includes('-node-');
		})
		.map(function (f) {
			var match = f.match(/-node-(\d+)\./);
			return match ? { file: f, version: parseInt(match[1], 10) } : null;
		})
		.filter(function (c) {
			return c && c.version <= nodeVersionNum;
		})
		.sort(function (a, b) {
			return b.version - a.version;
		});

	if (nodeCandidates.length > 0) {
		return nodeCandidates[0].file;
	}

	return null;
}

/**
 * Try to load a prebuilt binary.
 * Returns the loaded module if successful, null otherwise.
 */
function loadPrebuilt() {
	var binaryName = findPrebuiltBinary();
	if (!binaryName) {
		return null;
	}

	try {
		return require(path.join(BINARIES_DIR, binaryName));
	} catch (e) {
		return null;
	}
}

/**
 * Try to load the local build (from build/Release/fs_ext.node).
 * Returns the loaded module if successful, null otherwise.
 */
function loadLocalBuild() {
	try {
		return require(LOCAL_BUILD_PATH);
	} catch (e) {
		return null;
	}
}

/**
 * Load the native module with explicit source selection.
 *
 * @param {string} [source] - 'prebuilt', 'local', or undefined for auto (prebuilt first, then local)
 * @returns {Object|null} The loaded binding or null if loading failed
 */
function loadNativeModule(source) {
	// If we have a cached binding from the same source, return it
	if (cachedBinding && (source === undefined || source === cachedSource)) {
		return cachedBinding;
	}

	var binding = null;

	if (source === 'local') {
		binding = loadLocalBuild();
		if (binding) {
			cachedBinding = binding;
			cachedSource = 'local';
		}
	} else if (source === 'prebuilt') {
		binding = loadPrebuilt();
		if (binding) {
			cachedBinding = binding;
			cachedSource = 'prebuilt';
		}
	} else {
		// Auto mode: try prebuilt first, then local
		binding = loadPrebuilt();
		if (binding) {
			cachedBinding = binding;
			cachedSource = 'prebuilt';
		} else {
			binding = loadLocalBuild();
			if (binding) {
				cachedBinding = binding;
				cachedSource = 'local';
			}
		}
	}

	return binding;
}

/**
 * Switch the native module source. Clears cache and reloads.
 * Useful for testing to switch between prebuilt and local builds.
 *
 * @param {string} source - 'prebuilt' or 'local'
 * @returns {Object|null} The loaded binding or null if loading failed
 */
function useNativeModule(source) {
	cachedBinding = null;
	cachedSource = null;
	return loadNativeModule(source);
}

/**
 * Get the current binding source.
 * @returns {string|null} 'prebuilt', 'local', or null if not loaded
 */
function getNativeModuleSource() {
	return cachedSource;
}

/**
 * Get the path to a prebuilt binary without loading it.
 * Returns { path, name } if found, null otherwise.
 */
function getPrebuiltPath() {
	var binaryName = findPrebuiltBinary();
	if (!binaryName) {
		return null;
	}
	return {
		name: binaryName,
		path: path.join(BINARIES_DIR, binaryName)
	};
}

module.exports = {
	findPrebuiltBinary: findPrebuiltBinary,
	loadPrebuilt: loadPrebuilt,
	loadLocalBuild: loadLocalBuild,
	loadNativeModule: loadNativeModule,
	useNativeModule: useNativeModule,
	getNativeModuleSource: getNativeModuleSource,
	getPrebuiltPath: getPrebuiltPath,
	BINARIES_DIR: BINARIES_DIR,
	LOCAL_BUILD_PATH: LOCAL_BUILD_PATH
};
