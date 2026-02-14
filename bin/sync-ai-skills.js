const fs = require('fs/promises');
const path = require('path');

const repoRoot = path.resolve(__dirname, '..');
const skillName = 'vkbm-project-rules';

const skillTemplatePath = path.join(
	repoRoot,
	'docs',
	'ai-skills',
	skillName,
	'SKILL.md'
);

const referenceSources = [
	{
		name: 'common.md',
		sourcePath: path.join(repoRoot, 'docs', 'common', 'common.md'),
	},
	{
		name: 'coding-rules.md',
		sourcePath: path.join(
			repoRoot,
			'docs',
			'ai-skills',
			'skills',
			'coding-rules.md'
		),
	},
	{
		name: 'design-rules.md',
		sourcePath: path.join(
			repoRoot,
			'docs',
			'ai-skills',
			'skills',
			'design-rules.md'
		),
	},
	{
		name: 'phpunit.md',
		sourcePath: path.join(
			repoRoot,
			'docs',
			'ai-skills',
			'skills',
			'phpunit.md'
		),
	},
];

const targetSkillRoots = [
	path.join(repoRoot, '.codex', 'skills'),
	path.join(repoRoot, '.cursor', 'skills'),
	path.join(repoRoot, '.github', 'skills'),
	path.join(repoRoot, '.claude', 'skills'),
	path.join(repoRoot, '.agent', 'skills'),
];

const ensureDir = async (dirPath) => {
	await fs.mkdir(dirPath, { recursive: true });
};

const readText = async (filePath) => {
	return fs.readFile(filePath, 'utf8');
};

const writeText = async (filePath, content) => {
	await ensureDir(path.dirname(filePath));
	await fs.writeFile(filePath, content, 'utf8');
};

const syncSkill = async (targetRoot) => {
	// Copy skill template (EN) / スキルの雛形をコピー (JP)
	const skillTemplate = await readText(skillTemplatePath);
	const targetSkillDir = path.join(targetRoot, skillName);
	const targetSkillPath = path.join(targetSkillDir, 'SKILL.md');
	await writeText(targetSkillPath, skillTemplate);

	// Copy references (EN) / 参照資料をコピー (JP)
	const targetReferencesDir = path.join(targetSkillDir, 'references');
	await ensureDir(targetReferencesDir);

	for (const reference of referenceSources) {
		const referenceContent = await readText(reference.sourcePath);
		const targetReferencePath = path.join(
			targetReferencesDir,
			reference.name
		);
		await writeText(targetReferencePath, referenceContent);
	}
};

const run = async () => {
	// Validate inputs (EN) / 入力ファイルの存在確認 (JP)
	await readText(skillTemplatePath);
	for (const reference of referenceSources) {
		await readText(reference.sourcePath);
	}

	// Sync to each target (EN) / 各ターゲットへ同期 (JP)
	for (const targetRoot of targetSkillRoots) {
		await syncSkill(targetRoot);
	}

	// Log summary (EN) / まとめて表示 (JP)
	const targets = targetSkillRoots.map((targetRoot) => `- ${targetRoot}`);
	process.stdout.write(
		`OK: synced ${skillName} to\n${targets.join('\n')}\n`
	);
};

run().catch((error) => {
	process.stderr.write(`${error.message}\n`);
	process.exit(1);
});
