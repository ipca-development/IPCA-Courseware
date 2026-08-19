# Safety Management System deployment runbook

The SMS is feature-gated. Do not expose either reporting channel until the
database migration, privacy configuration, role assignments, and operational
acceptance checks below are complete.

## Required secrets

Configure these outside the repository:

- `CW_SAFETY_RATE_LIMIT_KEY`: random HMAC key used only to transform transient
  network addresses for anonymous abuse controls.
- `CW_SAFETY_IDENTITY_LOOKUP_KEY`: separate random HMAC key for confidential
  reporter subject references.
- `CW_SAFETY_ANALYTICS_LINK_KEY`: separate random HMAC key for approved
  cross-domain correlation references.
- `CW_SAFETY_VAULT_KEY`: base64 encoding of exactly 32 random bytes, used for
  authenticated encryption of restricted reporter identities.
- `CW_SAFETY_VAULT_KEY_REFERENCE`: non-secret rotation label such as
  `production-2026-v1`.
- `CW_SAFETY_ORGANIZATION_ID`: organization receiving the anonymous channel.

Use separate keys per environment. Never reuse the rate-limit, lookup, or vault
keys. Back up the vault key in the approved secret-management system; losing it
makes restricted reporter identities unrecoverable.

## Deployment order

1. Revoke and rotate the exposed legacy SMS database credential.
2. Resolve the legacy relationship discrepancy and all migration gates in
   `legacy_sms_migration_reconciliation.md`.
3. Back up the target database and test restoration.
4. Apply `scripts/sql/2026_08_17_safety_management_foundation.sql` to a staging
   MySQL 8 database.
5. Apply `scripts/sql/2026_08_18_safety_aircraft_occurrence_intake.sql`.
6. Obtain the Safety Manager-approved internal occurrence-type manifest. Import
   it with `php scripts/safety/occurrence_type_import.php manifest.json --apply`.
   Do not substitute ECCAIRS/ADREP values or either legacy hard-coded client list.
   Verify the reporter API returns at least one active type before rollout.
7. Schedule `scripts/safety/occurrence_flight_link_worker.php` to run after CVR
   and Garmin intake processing (and periodically) so reports submitted before
   evidence arrival acquire their durable dispatch/session/flight-record links.
8. Run all `tests/safety_*_check.php` contracts and the iOS safety contract/build.
9. Assign the minimum organization-scoped roles in
   `ipca_safety_role_assignments`; do not infer Safety roles from broad platform
   roles.
10. Verify object storage is private, presigned uploads expire, upload completion
   is checked, and malware scanning/quarantine operations are active.
11. Exercise identified, restricted, and anonymous reports end to end, including
   receipt recovery, feedback, investigation, actions, effectiveness review,
   residual-risk acceptance, and manager-approved closure.
12. Pilot with a restricted cohort. Review response times, failed mailbox
   attempts, feedback completion, overdue actions, audit-chain verification,
   and privacy incidents.
13. Obtain written Safety Manager, privacy/security, and accountable executive
    acceptance before enabling the capability flags for production.

After sign-off, enable the approved organization and mobile capability:

```sql
UPDATE ipca_safety_config
SET config_value = JSON_OBJECT('value', TRUE)
WHERE organization_id = 1
  AND config_key = 'enabled';

UPDATE ipca_communication_app_config
SET config_value = '1'
WHERE config_key = 'safety_reporting_enabled';
```

Enable anonymous reporting separately if its privacy and abuse-control pilot has
been accepted by setting both `ipca_safety_config.anonymous_reporting_enabled`
and `ipca_communication_app_config.anonymous_reporting_enabled` to true/`1`.

## Mandatory production gates

- No unresolved high-severity security or privacy defects.
- Anonymous requests contain no bearer token, account/device identifier, raw IP
  persistence, or analytics SDK identity.
- Restricted reporter identity is absent from reports, updates, attachments,
  staff projections, event actors, logs, and AI payloads; vault access is
  permission-gated and audited with a reason.
- Closure cannot bypass acknowledgement, reportability, completed
  investigation, accepted residual risk, action evidence/effectiveness,
  reporter feedback, and human Safety Manager approval.
- AI output remains advisory, de-identified, provenance-bound, and
  human-reviewed. AI cannot decide reportability, blame/just culture, risk
  acceptance, action approval, or closure.
- Legacy source totals, quarantines, deduplications, malformed-date decisions,
  repaired encoding, and attachment checksums reconcile to an approved manifest.

If any gate fails, leave `safety_reporting_enabled` and
`anonymous_reporting_enabled` disabled and roll back the application release;
the additive database records may remain for forensic review.

## ECCAIRS 2 rollout

ECCAIRS transmission is a separate fail-closed capability. Apply
`scripts/sql/2026_08_18_safety_eccairs2_integration.sql` and follow
`docs/safety/eccairs2_integration.md`.

Do not enable an ECCAIRS connection until EASA onboarding provides:

- environment-specific credentials and IP allowlisting;
- reporting/responsible entity identifiers;
- the current taxonomy and general version identifiers;
- confirmation of the current API paths and payload contract.
- the official, fingerprinted taxonomy package matching the configured version.

Import and activate the taxonomy package before importing a mapping version.
Install the XSD 1.1 validation dependency and configure
`CW_ECCAIRS_TAXONOMY_ARCHIVE_PATH` plus
`CW_ECCAIRS_XSD11_PYTHON_PATH`. Sandbox and UAT evidence must demonstrate human
approval bound to the canonical digest, separate REST/E5X artifact hashes,
XSD 1.1 validation, rejection handling, status reconciliation, and uncertain-delivery
recovery. Production additionally requires written Safety Manager and
privacy/security acceptance. Legacy PDFs are migration evidence only and must
never be queued as new ECCAIRS notifications.

Do not enable E5X export merely from the taxonomy ZIP. It defines XML schemas
but no E5X container. Obtain an authority-confirmed packaging profile and
configure its adapter and profile identifier before setting
`CW_ECCAIRS_E5X_PACKAGER_COMMAND` and `CW_ECCAIRS_E5X_PACKAGER_PROFILE`.
