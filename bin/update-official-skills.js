const fs = require('fs/promises');
const path = require('path');
const { spawn } = require('child_process');

const repoRoot = path.resolve(__dirname, '..');
const tempRoot = path.join(repoRoot, '.tmp');
const tempRepo = path.join(tempRoot, 'agent-skills');
const repoUrl = 'https://github.com/WordPress/agent-skills.git';
const skills = [
	'wp-plugin-development',
	'wp-block-development',
	'wp-rest-api',
	'wp-performance',
	'wp-project-triage',
];
const antigravityRoot = path.join(repoRoot, '.agent', 'skills');
const codexRoot = path.join(repoRoot, '.codex', 'skills');
const officialSkillRoots = [
	path.join(repoRoot, '.codex', 'skills'),
	path.join(repoRoot, '.cursor', 'skills'),
	path.join(repoRoot, '.github', 'skills'),
	path.join(repoRoot, '.claude', 'skills'),
];

const runCommand = (command, args, options) => {
	return new Promise((resolve, reject) => {
		const child = spawn(command, args, { stdio: 'inherit', ...options });
		child.on('error', reject);
		child.on('close', (code) => {
			if (code === 0) {
				resolve();
				return;
			}
			reject(new Error(`${command} exited with code ${code}`));
		});
	});
};

const removeTempRepo = async () => {
	await fs.rm(tempRepo, { recursive: true, force: true });
};

const ensureTempRoot = async () => {
	await fs.mkdir(tempRoot, { recursive: true });
};

const cloneOfficialSkills = async () => {
	// Clean clone directory (EN) / クローン先を掃除 (JP)
	await removeTempRepo();
	await ensureTempRoot();

	// Clone official repo (EN) / 公式リポジトリをクローン (JP)
	await runCommand('git', ['clone', '--depth=1', repoUrl, tempRepo], {
		cwd: repoRoot,
	});
};

const buildSkillpack = async () => {
	// Build skillpack (EN) / スキルパックをビルド (JP)
	await runCommand('node', ['shared/scripts/skillpack-build.mjs', '--clean'], {
		cwd: tempRepo,
	});
};

const installSkillpack = async () => {
	// Install selected skills (EN) / 指定スキルをインストール (JP)
	await runCommand(
		'node',
		[
			'shared/scripts/skillpack-install.mjs',
			`--dest=${repoRoot}`,
			'--targets=codex,vscode,cursor,claude',
			`--skills=${skills.join(',')}`,
		],
		{ cwd: tempRepo }
	);
};

const patchPerformanceSkillDocs = async () => {
	const oldCommand =
		'- `node skills/wp-performance/scripts/perf_inspect.mjs --path=<path> [--url=<url>]`';
	const newCommand = [
		'- `node .cursor/skills/wp-performance/scripts/perf_inspect.mjs --path=<path> [--url=<url>]` (from repo root)',
		'- `node scripts/perf_inspect.mjs --path=<path> [--url=<url>]` (from `.cursor/skills/wp-performance/`)',
	].join('\n');

	for (const root of officialSkillRoots) {
		const targetPath = path.join(root, 'wp-performance', 'SKILL.md');
		let content = '';

		try {
			content = await fs.readFile(targetPath, 'utf8');
		} catch (error) {
			if (error && error.code === 'ENOENT') {
				continue;
			}

			throw error;
		}

		if (!content.includes(oldCommand)) {
			continue;
		}

		const nextContent = content.replace(oldCommand, newCommand);
		await fs.writeFile(targetPath, nextContent, 'utf8');
	}
};

const syncAntigravitySkills = async () => {
	// Mirror official skills for Antigravity (EN) / Antigravity 用に公式スキルを反映 (JP)
	await fs.mkdir(antigravityRoot, { recursive: true });

	for (const skill of skills) {
		const sourcePath = path.join(codexRoot, skill);
		const targetPath = path.join(antigravityRoot, skill);

		await fs.rm(targetPath, { recursive: true, force: true });
		await fs.cp(sourcePath, targetPath, { recursive: true });
	}
};

const syncCustomSkills = async () => {
	// Overlay custom rules (EN) / 自社ルールを重ねる (JP)
	await runCommand('node', ['bin/sync-ai-skills.js'], { cwd: repoRoot });
};

const run = async () => {
	try {
		await cloneOfficialSkills();
		await buildSkillpack();
		await installSkillpack();
		await patchPerformanceSkillDocs();
		await syncAntigravitySkills();
		await syncCustomSkills();
	} finally {
		// Cleanup temp repo (EN) / 一時ディレクトリを削除 (JP)
		await removeTempRepo();
	}
};

run().catch((error) => {
	process.stderr.write(`${error.message}\n`);
	process.exit(1);
});
