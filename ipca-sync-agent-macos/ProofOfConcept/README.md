# IPCA Sync Agent Garmin Local Proof

This is the first technical proof for `IPCA Sync Agent`.

It does not build the full macOS app and does not upload anything to IPCA.training. It only proves that a normal Desktop Mac can use installed Google Chrome Stable, a dedicated IPCA-owned profile, localhost-only Chrome DevTools Protocol, a visible human-operated Garmin login, one authenticated FlyGarmin logbook JSON request, and one Garmin source download.

## What It Uses

- Google Chrome Stable at `/Applications/Google Chrome.app/Contents/MacOS/Google Chrome`
- Dedicated profile:
  `~/Library/Application Support/IPCA Sync Agent/GarminChromeProfile`
- Local proof downloads:
  `~/Library/Application Support/IPCA Sync Agent/ProofDownloads`
- Localhost-only DevTools:
  `--remote-debugging-address=127.0.0.1`
- A random high local port per run

It does not use stealth plugins, fingerprint masking, user-agent spoofing, CAPTCHA bypass, the user’s normal Chrome profile, Node, npm, Python, Docker, or Homebrew.

## Manual Test Sequence

Run from the repository root on the always-online Desktop Mac:

```shell
swift ipca-sync-agent-macos/ProofOfConcept/GarminLocalProof.swift
```

Then:

1. Confirm visible Google Chrome opens to FlyGarmin.
2. Complete Garmin username/password, MFA, and any human verification manually.
3. Wait until the Garmin Logbook is visibly loaded in Chrome.
4. Press Enter in the proof harness for the one-shot verification request.
5. Confirm the output prints only sanitized metadata:
   - HTTP status
   - content type
   - top-level JSON keys
   - entry count
   - cursor presence
   - first `flightDataLogUUID`
6. Confirm one source file and one metadata JSON file are saved under `ProofDownloads`.

Expected final line:

```text
RESULT: PASSED
```

If Garmin loops on “Verify you are human,” stop immediately and do not retry automatically.

## Important Safety Rules

- Do not delete the dedicated Garmin Chrome profile automatically.
- Do not point the proof at the user’s normal Chrome profile.
- Do not upload downloaded source files from this proof.
- Do not proceed to the full SwiftUI app until this proof passes on the Desktop Mac.
