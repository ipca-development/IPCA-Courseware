"""Runtime secret loading for analytics / CLI (no plaintext in git or SQLite).

Resolution order (first usable wins per logical secret):
  1. Process environment (already exported / systemd Environment=)
  2. Approved server-side EnvironmentFile(s), if present and readable:
       $IPCA_ANALYTICS_ENV_FILE (explicit override)
       /etc/ipca/analytics.env
       /etc/ipca/ipca-courseware-cli.env
       /etc/ipca/secrets.env
  3. Repository .env ONLY for non-secret defaults (host/name/user/port/model).
     Never loads password / API key / token material from the repo.

PHP-FPM pool env is NOT inherited by Python CLI. That is a process-environment
injection issue, not a missing-secret issue. Use analytics.env / systemd /
run-with-analytics-env.sh (optionally synced from the FPM pool by root).

EV[...] DigitalOcean ciphertext is always rejected as unusable.
This module NEVER logs or returns secret values.
"""

from __future__ import annotations

import os
from pathlib import Path
from typing import Iterable

_APPROVED_ENV_FILES = (
    Path("/etc/ipca/analytics.env"),
    Path("/etc/ipca/ipca-courseware-cli.env"),
    Path("/etc/ipca/secrets.env"),
)

# Allowlisted keys that may be read from a PHP-FPM pool file when PHP_FPM_POOL /
# IPCA_FPM_POOL is set (same pattern as scripts/garmin/start-garmin-worker.sh).
# This does not modify the pool; it only injects into the current process env.
_FPM_ALLOWLIST = frozenset(
    {
        "CW_OPENAI_API_KEY",
        "CW_OPENAI_MODEL",
        "CW_OPENAI_ASR_MODEL",
        "CW_DB_HOST",
        "CW_DB_PORT",
        "CW_DB_NAME",
        "CW_DB_USER",
        "CW_DB_PASS",
    }
)

# Logical name → environment aliases (first usable wins).
_SECRET_ALIASES: dict[str, tuple[str, ...]] = {
    "OPENAI_API_KEY": ("CW_OPENAI_API_KEY", "OPENAI_API_KEY"),
    "CW_DB_PASS": ("CW_DB_PASS",),
    "ASR_CREDENTIALS": ("CW_OPENAI_API_KEY", "OPENAI_API_KEY"),  # ASR uses OpenAI key
}

# Never load these from repository .env (even if present as plaintext).
_REPO_FORBIDDEN_KEYS = frozenset(
    {
        "CW_OPENAI_API_KEY",
        "OPENAI_API_KEY",
        "CW_DB_PASS",
        "CW_SPACES_SECRET",
        "CW_SPACES_KEY",
        "CW_HEYGEN_API_KEY",
        "MAIL_SMTP_PASSWORD",
        "POSTMARK_SERVER_TOKEN",
        "POSTMARK_INBOUND_WEBHOOK_SECRET",
        "POSTMARK_TRACKING_WEBHOOK_SECRET",
        "GARMIN_WORKER_TOKEN",
        "CW_ADSBEXCHANGE_API_KEY",
        "CW_CESIUM_ION_TOKEN",
        "CW_OPENSKY_TRINO_PASSWORD",
    }
)

# Non-secret defaults allowed from repository .env when unset.
_REPO_ALLOWED_DEFAULTS = frozenset(
    {
        "CW_DB_HOST",
        "CW_DB_PORT",
        "CW_DB_NAME",
        "CW_DB_USER",
        "CW_OPENAI_MODEL",
        "CW_OPENAI_ASR_MODEL",
        "CW_PUBLIC_BASE_URL",
        "CW_CDN_BASE",
    }
)

_LOADED_FILES: list[str] = []
_BOOTSTRAPPED = False


class RuntimeSecretError(RuntimeError):
    """Raised when a required runtime secret is missing or unusable."""


def _is_ev_ciphertext(value: str) -> bool:
    return value.startswith("EV[")


def _strip_value(raw: str) -> str:
    return raw.strip().strip('"').strip("'")


def _is_usable_openai(value: str) -> bool:
    v = _strip_value(value)
    return bool(v) and not _is_ev_ciphertext(v) and v.startswith("sk-")


def _is_usable_generic(value: str) -> bool:
    v = _strip_value(value)
    return bool(v) and not _is_ev_ciphertext(v)


def _apply_env_line(key: str, raw: str, *, allow_secrets: bool) -> None:
    if not key:
        return
    if key in os.environ and os.environ.get(key, "") != "":
        return  # process env wins; do not overwrite
    val = _strip_value(raw)
    if not val:
        return
    if _is_ev_ciphertext(val):
        return
    if not allow_secrets and key in _REPO_FORBIDDEN_KEYS:
        return
    if not allow_secrets and key not in _REPO_ALLOWED_DEFAULTS:
        return
    os.environ[key] = val


def _load_env_file(path: Path, *, allow_secrets: bool) -> bool:
    if not path.is_file():
        return False
    try:
        text = path.read_text(errors="ignore")
    except OSError:
        return False
    for line in text.splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        if line.startswith("export "):
            line = line[7:].strip()
        key, raw = line.split("=", 1)
        _apply_env_line(key.strip(), raw, allow_secrets=allow_secrets)
    return True


def _load_fpm_pool_allowlist(path: Path) -> int:
    """Parse env[KEY]=value lines for allowlisted analytics keys only."""
    import re

    if not path.is_file():
        return 0
    try:
        text = path.read_text(errors="ignore")
    except OSError:
        return 0
    loaded = 0
    pattern = re.compile(
        r"^\s*env\[([A-Za-z0-9_]+)\]\s*=\s*(.*?)\s*;?\s*$",
        re.MULTILINE,
    )
    for match in pattern.finditer(text):
        key = match.group(1)
        if key not in _FPM_ALLOWLIST:
            continue
        _apply_env_line(key, match.group(2), allow_secrets=True)
        if key in os.environ and os.environ.get(key):
            loaded += 1
    return loaded


def ensure_cli_env_loaded() -> list[str]:
    """Load approved server-side env files / optional FPM pool, then non-secret repo defaults."""
    global _BOOTSTRAPPED, _LOADED_FILES
    if _BOOTSTRAPPED:
        return list(_LOADED_FILES)
    _BOOTSTRAPPED = True
    _LOADED_FILES = []

    explicit = os.environ.get("IPCA_ANALYTICS_ENV_FILE", "").strip()
    candidates: list[Path] = []
    if explicit:
        candidates.append(Path(explicit))
    candidates.extend(_APPROVED_ENV_FILES)

    seen: set[str] = set()
    for path in candidates:
        sp = str(path)
        if sp in seen:
            continue
        seen.add(sp)
        if _load_env_file(path, allow_secrets=True):
            _LOADED_FILES.append(sp)

    # Optional: inject allowlisted keys from PHP-FPM pool into this process only.
    # Process env and EnvironmentFiles above still win (not overwritten).
    fpm = (
        os.environ.get("IPCA_FPM_POOL", "").strip()
        or os.environ.get("PHP_FPM_POOL", "").strip()
    )
    if fpm:
        n = _load_fpm_pool_allowlist(Path(fpm))
        if n:
            _LOADED_FILES.append(f"{fpm} (FPM allowlist, {n} keys)")

    # Repo .env — non-secret defaults only
    root = Path(__file__).resolve().parents[2]
    repo_env = root / ".env"
    if _load_env_file(repo_env, allow_secrets=False):
        _LOADED_FILES.append(str(repo_env) + " (non-secret defaults only)")

    return list(_LOADED_FILES)


def availability_label(logical_name: str) -> str:
    """Return only AVAILABLE or MISSING — never values, lengths, prefixes."""
    ensure_cli_env_loaded()
    aliases = _SECRET_ALIASES.get(logical_name, (logical_name,))
    if logical_name == "OPENAI_API_KEY" or logical_name == "ASR_CREDENTIALS":
        for alias in aliases:
            if _is_usable_openai(os.environ.get(alias, "")):
                return "AVAILABLE"
        return "MISSING"
    for alias in aliases:
        if _is_usable_generic(os.environ.get(alias, "")):
            return "AVAILABLE"
    return "MISSING"


def secret_shape(value: str | None) -> str:
    """Legacy shape helper for admin UIs. Prefer availability_label for preflight."""
    if not value:
        return "missing"
    v = _strip_value(value)
    if _is_ev_ciphertext(v):
        return "ev_ciphertext"
    if v.startswith("sk-"):
        return "openai_sk"
    return "present"


def peek_secret_status(logical_name: str) -> dict:
    """Availability metadata without exposing secret material."""
    ensure_cli_env_loaded()
    aliases = _SECRET_ALIASES.get(logical_name, (logical_name,))
    label = availability_label(logical_name)
    found = []
    for alias in aliases:
        raw = os.environ.get(alias, "")
        found.append({"alias": alias, "shape": secret_shape(raw or None)})
    return {
        "logical_name": logical_name,
        "usable": label == "AVAILABLE",
        "availability": label,
        "aliases": found,
        "loaded_env_files": list(_LOADED_FILES),
        "cli_env_candidates": [str(p) for p in _APPROVED_ENV_FILES],
    }


def get_runtime_secret(logical_name: str, *, required: bool = True) -> str | None:
    """Return a usable runtime secret or raise/return None."""
    ensure_cli_env_loaded()
    aliases: Iterable[str] = _SECRET_ALIASES.get(logical_name, (logical_name,))
    for alias in aliases:
        value = _strip_value(os.environ.get(alias, ""))
        if not value:
            continue
        if _is_ev_ciphertext(value):
            if required:
                raise RuntimeSecretError(
                    f"{logical_name}: found DigitalOcean EV[...] ciphertext in {alias}; "
                    "inject plaintext via PHP-FPM → /etc/ipca/analytics.env (or process env). "
                    "This repository cannot decrypt EV secrets."
                )
            return None
        if logical_name in ("OPENAI_API_KEY", "ASR_CREDENTIALS") and not _is_usable_openai(value):
            continue
        return value

    if required:
        raise RuntimeSecretError(
            f"{logical_name}: runtime secret unavailable in process environment. "
            f"PHP-FPM env is not inherited by CLI. Configure /etc/ipca/analytics.env "
            f"(or systemd EnvironmentFile) with one of {tuple(aliases)}. "
            "Do not commit plaintext secrets; do not use repository .env for secrets."
        )
    return None
