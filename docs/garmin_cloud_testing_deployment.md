# Garmin Cloud Testing Deployment

This integration is manual-only until the testing environment passes all deployment acceptance gates.

Scheduled synchronization must remain disabled until all of these are true in `ipca_garmin_provider_states.acceptance_checks_json`:

- worker authentication test succeeded
- initial Garmin logbook synchronization succeeded
- cursor persistence succeeded
- at least one `flightDataLogUUID` was discovered
- at least one Garmin source was downloaded
- source classification succeeded
- immutable evidence storage succeeded
- validation succeeded
- Flight Session matching succeeded or produced an expected review-required result
- Flight Log -> Garmin Connection visibly shows the status

The server enforces this in `GarminProviderStateService`; attempting to enable `scheduled_sync_enabled` before the gates pass throws an error.

## Worker

The first supported deployment mode is `remote_worker` or `server_worker`.

Required environment:

- `GARMIN_WORKER_MODE=remote_worker` or `GARMIN_WORKER_MODE=server_worker`
- `GARMIN_WORKER_URL` for PHP-to-worker HTTP calls, or `GARMIN_WORKER_COMMAND` for developer CLI convenience
- `GARMIN_BROWSER_PROFILE_DIR` on the managed worker
- `GARMIN_PRIVATE_DOWNLOAD_DIR` on the managed worker
- optional `GARMIN_WORKER_TOKEN` for HTTP bearer authentication

Initial authentication happens on the worker:

```shell
cd scripts/garmin
npm install
node flygarmin-worker.js login
```

The admin completes Garmin username/password, MFA, trusted-device prompts, and any Garmin security challenge in that browser. No Garmin password, MFA code, cookie, or session header belongs in IPCA.training config or source code.

## Same-Flight Multi-Log Acceptance

For one Garmin entry containing one GPS-only log and one full avionics/G3X log, the testing acceptance result must confirm:

- both UUIDs are downloaded
- both original files are retained independently
- both are linked to one Garmin entry/source group
- both are associated with one Flight Session or a single expected review-required group
- full avionics is `PRIMARY_OPERATIONAL`
- GPS-only is `SUPPORTING_GPS`
- no duplicate Flight Session or Operational Flight Record is created
- Flight Log -> Garmin Connection presents one grouped Garmin flight with two source files

GPS-only logs are valid supported evidence. They may support GPS replay and matching, but must not be used for Hobbs, Tacho, fuel, attitude, airspeed, or operational flight record calculations.
