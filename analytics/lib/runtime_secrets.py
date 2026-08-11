"""Runtime secret loading for analytics / CLI (no plaintext in git or SQLite).

Platform convention (see CompliancePostmarkConfig):
  - Web: PHP-FPM pool injects plaintext env
  - CLI: source /etc/ipca/ipca-courseware-cli.env before scripts
  - DigitalOcean App Platform: EV[...] ciphertext is decrypted by DO at runtime

This module NEVER decrypts EV[...]. It only accepts already-injected plaintext.
It NEVER logs or returns secret values to callers that print diagnostics —
use status helpers that return shapes only.
"""

from __future__ import annotations

import os
from pathlib import Path
from typing import Iterable

# Optional CLI env files (sourced by ops; not committed).
_CLI_ENV_CANDIDATES = (
    Path("/etc/ipca/ipca-courseware-cli.env"),
    Path("/etc/ipca/secrets.env"),
    Path.home() / ".config/ipca/secrets.env",
)

# Logical name → environment aliases (first usable wins).
_SECRET_ALIASES: dict[str, tuple[str, ...]] = {
    "OPENAI_API_KEY": ("CW_OPENAI_API_KEY", "OPENAI_API_KEY"),
    "CW_DB_PASS": ("CW_DB_PASS",),
}


class RuntimeSecretError(RuntimeError):
    """Raised when a required runtime secret is missing or unusable."""


def _is_ev_ciphertext(value: str) -> bool:
    return value.startswith("EV[")


def _is_usable_openai(value: str) -> bool:
    return bool(value) and value.startswith("sk-") and not _is_ev_ciphertext(value)


def _load_cli_env_file(path: Path) -> None:
    """Load KEY=VALUE into os.environ if not already set. Skips EV ciphertext."""
    if not path.is_file():
        return
    try:
        text = path.read_text(errors="ignore")
    except OSError:
        return
    for line in text.splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        if line.startswith("export "):
            line = line[7:].strip()
        key, raw = line.split("=", 1)
        key = key.strip()
        val = raw.strip().strip('"').strip("'")
        if not key or key in os.environ:
            continue
        if _is_ev_ciphertext(val):
            # Do not promote DO ciphertext into the process as if it were usable.
            continue
        os.environ[key] = val


def ensure_cli_env_loaded() -> list[str]:
    """Attempt to load approved CLI env files. Returns paths that were read."""
    loaded: list[str] = []
    for path in _CLI_ENV_CANDIDATES:
        if path.is_file():
            _load_cli_env_file(path)
            loaded.append(str(path))
    return loaded


def secret_shape(value: str | None) -> str:
    """Safe diagnostic shape — never the secret itself."""
    if not value:
        return "missing"
    if _is_ev_ciphertext(value):
        return "ev_ciphertext"
    if value.startswith("sk-"):
        return "openai_sk"
    return f"present_len_{len(value)}"


def peek_secret_status(logical_name: str) -> dict:
    """Return availability metadata without exposing secret material."""
    ensure_cli_env_loaded()
    aliases = _SECRET_ALIASES.get(logical_name, (logical_name,))
    found = []
    for alias in aliases:
        raw = os.environ.get(alias, "")
        found.append({"alias": alias, "shape": secret_shape(raw or None)})
    usable = False
    if logical_name == "OPENAI_API_KEY":
        for alias in aliases:
            if _is_usable_openai(os.environ.get(alias, "")):
                usable = True
                break
    else:
        for alias in aliases:
            v = os.environ.get(alias, "")
            if v and not _is_ev_ciphertext(v):
                usable = True
                break
    return {
        "logical_name": logical_name,
        "usable": usable,
        "aliases": found,
        "cli_env_candidates": [str(p) for p in _CLI_ENV_CANDIDATES],
    }


def get_runtime_secret(logical_name: str, *, required: bool = True) -> str | None:
    """Return a usable runtime secret or raise/return None.

    Example:
        key = get_runtime_secret("OPENAI_API_KEY")
    """
    ensure_cli_env_loaded()
    aliases: Iterable[str] = _SECRET_ALIASES.get(logical_name, (logical_name,))
    for alias in aliases:
        value = os.environ.get(alias, "")
        if not value:
            continue
        if _is_ev_ciphertext(value):
            if required:
                raise RuntimeSecretError(
                    f"{logical_name}: found DigitalOcean EV[...] ciphertext in {alias}; "
                    "inject plaintext via App Platform / FPM / /etc/ipca/ipca-courseware-cli.env "
                    "(this repository cannot decrypt EV secrets)."
                )
            return None
        if logical_name == "OPENAI_API_KEY" and not _is_usable_openai(value):
            continue
        return value

    if required:
        raise RuntimeSecretError(
            f"{logical_name}: runtime secret unavailable. "
            f"Set one of {tuple(aliases)} in the process environment "
            "(DigitalOcean App Platform, PHP-FPM pool, or /etc/ipca/ipca-courseware-cli.env). "
            "Do not commit plaintext secrets."
        )
    return None
