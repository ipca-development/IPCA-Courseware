#!/usr/bin/env python3
"""Safe runtime preflight for analytics CLI secrets.

Prints ONLY:
  OPENAI_API_KEY: AVAILABLE|MISSING
  CW_DB_PASS: AVAILABLE|MISSING
  ASR credentials: AVAILABLE|MISSING

Never prints values, lengths, prefixes, suffixes, or hashes.
"""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "analytics"))

from lib.runtime_secrets import availability_label, ensure_cli_env_loaded  # noqa: E402


def main() -> int:
    ensure_cli_env_loaded()
    rows = [
        ("OPENAI_API_KEY", availability_label("OPENAI_API_KEY")),
        ("CW_DB_PASS", availability_label("CW_DB_PASS")),
        ("ASR credentials", availability_label("ASR_CREDENTIALS")),
    ]
    for name, label in rows:
        print(f"{name}: {label}")
    # Exit 0 only if OpenAI + DB are available (ASR optional / same key).
    if rows[0][1] == "AVAILABLE" and rows[1][1] == "AVAILABLE":
        return 0
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
