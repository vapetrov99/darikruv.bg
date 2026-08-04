#!/usr/bin/env python3
"""Remove exception message leakage from API route JSON responses."""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1] / "src" / "api" / "routes"
LINE_RE = re.compile(r"^[ \t]*'error'\s*=>\s*\$e->getMessage\(\),?\s*\r?\n", re.M)


def main() -> None:
    total = 0
    for path in sorted(ROOT.glob("*.php")):
        text = path.read_text(encoding="utf-8")
        new_text, n = LINE_RE.subn("", text)
        if n:
            path.write_text(new_text, encoding="utf-8")
            total += n
            print(f"updated {path.name}: removed {n}")
    print(f"TOTAL_REMOVED {total}")


if __name__ == "__main__":
    main()
