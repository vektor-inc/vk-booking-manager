#!/usr/bin/env node

/**
 * Merge translations from bundled component files into main translation files.
 * 
 * This script handles two scenarios:
 * 1. booking-ui files (daily-slot-list.js, calendar-grid.js, etc.) are bundled into app.js
 * 2. menu-loop/edit.js is bundled into menu-loop/index.js
 * 
 * Since wp i18n make-json generates separate JSON files for each source file,
 * but these components are bundled together, their translations need to be merged.
 */

const fs = require('fs');
const path = require('path');

const languagesDir = path.join(__dirname, '..', 'languages');
const locale = 'ja';
const domain = 'vk-booking-manager';

// Find all JSON files
const jsonFiles = fs.readdirSync(languagesDir)
	.filter(file => file.endsWith('.json') && file.startsWith(`${domain}-${locale}-`))
	.map(file => ({
		filename: file,
		path: path.join(languagesDir, file),
	}));

let totalMergedCount = 0;

// ============================================
// Part 1: Merge booking-ui files into app.js
// ============================================
const bookingUiDir = path.join(__dirname, '..', 'src', 'blocks', 'reservation', 'booking-ui');
if (fs.existsSync(bookingUiDir)) {
	// 自動的に booking-ui ディレクトリ内の全ての .js ファイルを検出（index.js は除外）
	const bookingUiFiles = fs.readdirSync(bookingUiDir)
		.filter(file => file.endsWith('.js') && file !== 'index.js')
		.map(file => `src/blocks/reservation/booking-ui/${file}`);

	// app.js にバンドルされる reservation 配下の追加コンポーネントもマージ対象に含める。
	// WordPress 側では build/blocks/reservation/view.js のハッシュで翻訳JSONを探すが、
	// 本プラグインでは filter_script_translation_file() で app.js の JSON を返す設計のため、
	// app.js にバンドルされる全ての翻訳を app.js の JSON に集約しておく必要がある。
	const reservationBundledFiles = [
		'src/blocks/reservation/booking-confirm-app.js',
		'src/blocks/reservation/components/booking-summary-items.js',
	];

	const sourcesToMerge = [...bookingUiFiles, ...reservationBundledFiles];

	console.log(
		`\n[Reservation Block] Found ${sourcesToMerge.length} bundled files to merge into app.js:`,
		sourcesToMerge.join(', ')
	);

	// Find app.js translation file
	let appJsFile = null;
	for (const file of jsonFiles) {
		const content = fs.readFileSync(file.path, 'utf8');
		const data = JSON.parse(content);
		if (data.source && data.source.includes('src/blocks/reservation/app.js')) {
			appJsFile = { ...file, data };
			break;
		}
	}

	if (appJsFile) {
		// Find bundled translation files and merge them
		let mergedCount = 0;
		const appMessages = appJsFile.data.locale_data.messages || {};

		for (const source of sourcesToMerge) {
			const bookingUiFile = jsonFiles.find(file => {
				const content = fs.readFileSync(file.path, 'utf8');
				const data = JSON.parse(content);
				return data.source && data.source.includes(source);
			});

			if (bookingUiFile) {
				const bookingUiData = JSON.parse(fs.readFileSync(bookingUiFile.path, 'utf8'));
				const bookingUiMessages = bookingUiData.locale_data?.messages || {};
				
				// Merge messages into app.js translation file
				for (const [key, value] of Object.entries(bookingUiMessages)) {
					if (key !== '' && !appMessages[key]) {
						appMessages[key] = value;
						mergedCount++;
					}
				}
				
				console.log(`  Merged translations from ${path.basename(source)}`);
			} else {
				console.log(`  Warning: Translation file not found for ${source}`);
			}
		}

		// Write merged translation file
		if (mergedCount > 0) {
			appJsFile.data.locale_data.messages = appMessages;
			fs.writeFileSync(appJsFile.path, JSON.stringify(appJsFile.data, null, '\t'));
			console.log(`Merged ${mergedCount} translation entries into ${appJsFile.filename}`);
			totalMergedCount += mergedCount;
		} else {
			console.log('No reservation translations to merge (all translations may already be merged)');
		}
	} else {
		console.log('\n[Reservation Block] app.js translation file not found, skipping booking-ui merge');
	}
}

// ============================================
// Part 2: Merge menu-loop/edit.js into menu-loop/index.js
// ============================================
// index.js now has a dummy translation string, so wp i18n make-json will generate a JSON file for it.
// However, edit.js is bundled into index.js, so we need to merge edit.js's translations into index.js's JSON.
const menuLoopIndexJsFile = jsonFiles.find(file => {
	const content = fs.readFileSync(file.path, 'utf8');
	const data = JSON.parse(content);
	return data.source && data.source.includes('src/blocks/menu-loop/index.js');
});

const menuLoopEditJsFile = jsonFiles.find(file => {
	const content = fs.readFileSync(file.path, 'utf8');
	const data = JSON.parse(content);
	return data.source && data.source.includes('src/blocks/menu-loop/edit.js');
});

if (menuLoopIndexJsFile && menuLoopEditJsFile) {
	console.log('\n[Menu Loop Block] Merging edit.js translations into index.js...');
	
	const indexJsData = JSON.parse(fs.readFileSync(menuLoopIndexJsFile.path, 'utf8'));
	const editJsData = JSON.parse(fs.readFileSync(menuLoopEditJsFile.path, 'utf8'));
	
	const indexMessages = indexJsData.locale_data?.messages || {};
	const editMessages = editJsData.locale_data?.messages || {};
	
	let mergedCount = 0;
	
	// Merge edit.js messages into index.js translation file
	for (const [key, value] of Object.entries(editMessages)) {
		if (key !== '' && !indexMessages[key]) {
			indexMessages[key] = value;
			mergedCount++;
		}
	}
	
	if (mergedCount > 0) {
		indexJsData.locale_data.messages = indexMessages;
		fs.writeFileSync(menuLoopIndexJsFile.path, JSON.stringify(indexJsData, null, '\t'));
		console.log(`Merged ${mergedCount} translation entries from edit.js into ${menuLoopIndexJsFile.filename}`);
		totalMergedCount += mergedCount;
	} else {
		console.log('No menu-loop translations to merge (all translations may already be merged)');
	}
} else {
	if (!menuLoopIndexJsFile) {
		console.log('\n[Menu Loop Block] index.js translation file not found, skipping edit.js merge');
	}
	if (!menuLoopEditJsFile) {
		console.log('\n[Menu Loop Block] edit.js translation file not found, skipping edit.js merge');
	}
}

// Summary
if (totalMergedCount > 0) {
	console.log(`\n✓ Total: Merged ${totalMergedCount} translation entries across all blocks`);
} else {
	console.log('\n✓ No translations to merge (all translations may already be merged)');
}
