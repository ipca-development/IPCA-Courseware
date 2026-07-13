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

## Same-Droplet Worker

The Garmin Cloud integration runs entirely on the existing IPCA.training Linux Droplet:

- web server
- PHP-FPM
- IPCA.training PHP application
- Node.js
- Playwright Chromium
- Garmin worker systemd service
- Garmin browser profile
- Garmin private download staging

The worker binds only to `127.0.0.1:8791`. PHP connects to `http://127.0.0.1:8791`. Do not expose port `8791`, do not create a public worker host, and do not proxy the worker through Nginx.

## Canonical Environment

The active PHP-FPM pool file is the single source of truth:

`/etc/php/<active-version>/fpm/pool.d/www.conf`

Add this block once:

```ini
; BEGIN IPCA GARMIN ENV
env[GARMIN_WORKER_MODE] = server_worker
env[GARMIN_WORKER_URL] = http://127.0.0.1:8791
env[GARMIN_WORKER_PORT] = 8791
env[GARMIN_WORKER_HOST] = 127.0.0.1
env[GARMIN_BROWSER_PROFILE_DIR] = /var/lib/ipca/garmin/browser-profile
env[GARMIN_PRIVATE_DOWNLOAD_DIR] = /var/lib/ipca/garmin/downloads
env[GARMIN_WORKER_TOKEN] = <secret-token>
; END IPCA GARMIN ENV
```

The implementation supports `server_worker`, `remote_worker`, and `local_cli`; use `server_worker` for this topology.

Do not create a permanent `/etc/ipca/*.env` file. The systemd worker uses `scripts/garmin/start-garmin-worker.sh`, which reads the PHP-FPM pool file, extracts only allowlisted Garmin variables, exports them to Node, and then uses `exec` to run `flygarmin-worker.js serve`.

## Deployment Checklist

Discover the actual application root and service names:

```shell
set -e
. /etc/os-release && echo "$PRETTY_NAME"
sudo find /var/www /srv /opt /home -maxdepth 4 -type d -name scripts 2>/dev/null | sort
systemctl list-units --type=service --all 'php*-fpm.service' --no-pager
systemctl list-units --type=service --all 'nginx.service' 'apache2.service' 'httpd.service' --no-pager
```

Then set deployment variables in your shell:

```shell
export APP_ROOT="/actual/path/to/IPCA-Courseware"
export PHP_FPM_SERVICE="php8.3-fpm"
export PHP_FPM_POOL="/etc/php/8.3/fpm/pool.d/www.conf"
export WEB_SERVICE="nginx"
```

Install Node and Playwright dependencies on the same Droplet:

```shell
sudo apt-get update
sudo apt-get install -y ca-certificates curl gnupg
node --version || curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
node --version || sudo apt-get install -y nodejs
cd "$APP_ROOT/scripts/garmin"
npm install
npx playwright install-deps chromium
npx playwright install chromium
```

Create the dedicated user and private persistent storage:

```shell
sudo useradd --system --home /var/lib/ipca/garmin --shell /usr/sbin/nologin ipca-garmin || true
sudo mkdir -p /var/lib/ipca/garmin/browser-profile /var/lib/ipca/garmin/downloads
sudo chown -R ipca-garmin:ipca-garmin /var/lib/ipca/garmin
sudo chmod 700 /var/lib/ipca/garmin /var/lib/ipca/garmin/browser-profile /var/lib/ipca/garmin/downloads
```

Add the Garmin environment block to the active PHP-FPM pool:

```shell
sudo cp "$PHP_FPM_POOL" "$PHP_FPM_POOL.bak.$(date +%Y%m%d%H%M%S)"
openssl rand -hex 32
sudoedit "$PHP_FPM_POOL"
```

Ensure the launcher is executable and not writable by the worker user:

```shell
sudo chown root:root "$APP_ROOT/scripts/garmin/start-garmin-worker.sh"
sudo chmod 755 "$APP_ROOT/scripts/garmin/start-garmin-worker.sh"
```

Create the systemd service:

```shell
sudo tee /etc/systemd/system/garmin-worker.service >/dev/null <<EOF
[Unit]
Description=IPCA Garmin Worker
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=ipca-garmin
Group=ipca-garmin
WorkingDirectory=$APP_ROOT/scripts/garmin
Environment=PHP_FPM_POOL=$PHP_FPM_POOL
ExecStart=$APP_ROOT/scripts/garmin/start-garmin-worker.sh
Restart=on-failure
RestartSec=10
NoNewPrivileges=true
PrivateTmp=true

[Install]
WantedBy=multi-user.target
EOF
```

Restart services:

```shell
sudo systemctl daemon-reload
sudo systemctl restart "$PHP_FPM_SERVICE"
sudo systemctl reload "$WEB_SERVICE"
sudo systemctl enable garmin-worker
sudo systemctl restart garmin-worker
sudo systemctl status garmin-worker --no-pager
```

Verify localhost-only worker health:

```shell
sudo ss -ltnp | grep ':8791'
TOKEN_PRESENT="$(sudo awk '/^[[:space:]]*env\[GARMIN_WORKER_TOKEN\][[:space:]]*=/ { found=1 } END { print found ? "yes" : "no" }' "$PHP_FPM_POOL")"
echo "token_present=$TOKEN_PRESENT"
TOKEN="$(sudo awk 'BEGIN{p="^[[:space:]]*env\\[GARMIN_WORKER_TOKEN\\][[:space:]]*=[[:space:]]*"} $0 ~ p { sub(p,"",$0); sub(/[[:space:]]*;[[:space:]]*$/,"",$0); print }' "$PHP_FPM_POOL")"
curl -sS -X POST http://127.0.0.1:8791/garmin-worker \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer ${TOKEN}" \
  -d '{"operation":"status"}'
unset TOKEN
```

Verify invalid-token rejection:

```shell
curl -i -X POST http://127.0.0.1:8791/garmin-worker \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer invalid" \
  -d '{"operation":"status"}'
```

Create a temporary admin-only PHP diagnostic, verify, then remove it:

```shell
sudo tee "$APP_ROOT/public/admin/garmin_env_probe.php" >/dev/null <<'EOF'
<?php
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';
cw_require_admin();
header('Content-Type: application/json');
echo json_encode([
  'mode' => getenv('GARMIN_WORKER_MODE') ?: null,
  'url' => getenv('GARMIN_WORKER_URL') ?: null,
  'host' => getenv('GARMIN_WORKER_HOST') ?: null,
  'port' => getenv('GARMIN_WORKER_PORT') ?: null,
  'profile_dir' => getenv('GARMIN_BROWSER_PROFILE_DIR') ?: null,
  'download_dir' => getenv('GARMIN_PRIVATE_DOWNLOAD_DIR') ?: null,
  'token_present' => (getenv('GARMIN_WORKER_TOKEN') ?: '') !== '',
]);
EOF
```

Open `/admin/garmin_env_probe.php` as an admin, confirm values are visible and token is only reported as present, then remove it:

```shell
sudo rm "$APP_ROOT/public/admin/garmin_env_probe.php"
```

Initial authentication happens on the same Droplet and writes the browser profile to `/var/lib/ipca/garmin/browser-profile`. Use SSH X11 forwarding or temporary VNC through an SSH tunnel only:

```shell
sudo systemctl stop garmin-worker
sudo -u ipca-garmin -H env PHP_FPM_POOL="$PHP_FPM_POOL" "$APP_ROOT/scripts/garmin/start-garmin-worker.sh" login
```

Verify the profile in a fresh headless process without printing secrets:

```shell
sudo -u ipca-garmin -H env PHP_FPM_POOL="$PHP_FPM_POOL" "$APP_ROOT/scripts/garmin/start-garmin-worker.sh" status
```

After login, stop the temporary display service and leave only the headless worker running:

```shell
sudo systemctl restart garmin-worker
```

Open `/admin/flight_log_garmin_connection.php` and run:

1. Test Connection
2. Initial Synchronization
3. Download / Classify source logs
4. Verify GPS-only plus full-avionics grouping
5. Verify session matching or expected review-required status
6. Mark UI Visible
7. Enable scheduled testing sync only after all gates pass

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
