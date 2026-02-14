#!/usr/bin/env python3
from pathlib import Path
import re


ROOT = Path(__file__).resolve().parents[1]
SOURCE_DIR = ROOT / "assets" / "scss"
OUTPUT_DIR = ROOT / "build" / "assets" / "css"

BUNDLES = {
	"vkbm-frontend.min.css": [
		"variables.scss",
		"common.scss",
	],
	"vkbm-auth.min.css": [
		"variables.scss",
		"buttons.scss",
		"alert.scss",
		"auth-forms.scss",
	],
	"vkbm-editor.min.css": [
		"variables.scss",
		"utility.scss",
		"buttons.scss",
		"alert.scss",
		"auth-forms.scss",
		"admin-editor-fixes.scss",
		"common.scss",
	],
	"vkbm-admin.min.css": [
		"variables.scss",
		"utility.scss",
		"buttons.scss",
		"admin-notice.scss",
		"admin-table.scss",
		"admin-schedule.scss",
		"admin-provider-settings.scss",
		"admin-shift-editor.scss",
		"admin-shift-bulk-create.scss",
		"admin-shift-dashboard.scss",
		"admin-service-menu-quick-edit.scss",
		"admin-post-order.scss",
		"admin-term-order.scss",
		"admin-style-guide.scss",
		"admin-core.scss",
		"common.scss",
	],
}


def strip_comments(css: str) -> str:
    return re.sub(r"/\*.*?\*/", "", css, flags=re.S)


def normalize(css: str) -> str:
    css = strip_comments(css)
    css = re.sub(r"@import\s+url\([^)]+\);\s*", "", css)
    css = re.sub(r"\s+", " ", css)
    css = re.sub(r"\s*([{}:;,])\s*", r"\1", css)
    return css.strip()


def main() -> None:
    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    for output_name, sources in BUNDLES.items():
        parts = []
        for source in sources:
            path = SOURCE_DIR / source
            if not path.exists():
                raise SystemExit(f"Missing source: {path}")
            parts.append(path.read_text(encoding="utf-8"))
        bundled = normalize("\n".join(parts))
        (OUTPUT_DIR / output_name).write_text(bundled + "\n", encoding="utf-8")


if __name__ == "__main__":
    main()
