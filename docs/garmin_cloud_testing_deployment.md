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
env[PLAYWRIGHT_BROWSERS_PATH] = /var/lib/ipca/garmin/playwright-browsers
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
sudo apt-get install -y ca-certificates curl gnupg xvfb x11vnc openbox dbus-x11 x11-utils
node --version || curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
node --version || sudo apt-get install -y nodejs
cd "$APP_ROOT/scripts/garmin"
npm install
npx playwright install-deps chromium
```

Create the dedicated user and private persistent storage:

```shell
sudo useradd --system --home /var/lib/ipca/garmin --shell /usr/sbin/nologin ipca-garmin || true
sudo mkdir -p /var/lib/ipca/garmin/browser-profile /var/lib/ipca/garmin/downloads /var/lib/ipca/garmin/playwright-browsers
sudo chown -R ipca-garmin:ipca-garmin /var/lib/ipca/garmin
sudo chmod 700 /var/lib/ipca/garmin /var/lib/ipca/garmin/browser-profile /var/lib/ipca/garmin/downloads /var/lib/ipca/garmin/playwright-browsers
```

Install Chromium into the persistent browser path:

```shell
cd "$APP_ROOT/scripts/garmin"
sudo -u ipca-garmin -H env PLAYWRIGHT_BROWSERS_PATH=/var/lib/ipca/garmin/playwright-browsers npx playwright install chromium
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
sudo chown root:root "$APP_ROOT/scripts/garmin/garmin-auth-session.sh"
sudo chmod 755 "$APP_ROOT/scripts/garmin/garmin-auth-session.sh"
sudo chown root:root "$APP_ROOT/scripts/garmin/garmin-auth-browser.js"
sudo chmod 644 "$APP_ROOT/scripts/garmin/garmin-auth-browser.js"
```

Repeat those ownership and mode commands after each code deployment if the deployment process resets file ownership. The auth helper is reachable through a tightly scoped sudoers rule and must not be writable by `www-data`.

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

## Temporary Garmin Authentication Session

The Garmin Connection page can start a temporary headed Chromium session on the same Droplet. This uses:

- Xvfb display `:95`
- x11vnc bound to `127.0.0.1:5905`
- openbox as a minimal window manager
- Playwright Chromium using `/var/lib/ipca/garmin/browser-profile`
- runtime files under `/run/ipca/garmin-auth/`

Do not open firewall ports for VNC. The Admin connects through SSH tunneling only:

```shell
ssh -L 5905:127.0.0.1:5905 root@157.230.237.72
```

Then open:

```text
vnc://localhost:5905
```

Create a tightly scoped sudoers rule for the web user. Adjust `www-data` only if the web server runs PHP as a different user:

```shell
sudo visudo -f /etc/sudoers.d/ipca-garmin-auth
```

Add:

```text
www-data ALL=(root) NOPASSWD: /var/www/ipca/scripts/garmin/garmin-auth-session.sh start
www-data ALL=(root) NOPASSWD: /var/www/ipca/scripts/garmin/garmin-auth-session.sh status
www-data ALL=(root) NOPASSWD: /var/www/ipca/scripts/garmin/garmin-auth-session.sh verify
www-data ALL=(root) NOPASSWD: /var/www/ipca/scripts/garmin/garmin-auth-session.sh stop
```

The sudoers file must remain mode `0440`:

```shell
sudo chmod 440 /etc/sudoers.d/ipca-garmin-auth
sudo visudo -c
```

Do not add `self-test` to the sudoers file. It is a shell-level diagnostic only and is not exposed through the Admin UI.

Helper self-test:

```shell
sudo /var/www/ipca/scripts/garmin/garmin-auth-session.sh self-test
```

This checks `openssl`, password generation, password length, safe character set, runtime path, required binaries, and helper syntax without starting Xvfb, openbox, x11vnc, Chromium, or stopping `garmin-worker`. It does not print the generated password.

Password-generation regression test:

```shell
/var/www/ipca/scripts/garmin/test-garmin-auth-helper.sh
```

This runs under `set -euo pipefail`, verifies the 14-character safe password generation path, and does not print the generated password.

Post-deployment verification order:

1. Deploy the code.
2. Restore secure helper ownership and modes:

```shell
sudo chown root:root /var/www/ipca/scripts/garmin/garmin-auth-session.sh
sudo chmod 755 /var/www/ipca/scripts/garmin/garmin-auth-session.sh
sudo chown root:root /var/www/ipca/scripts/garmin/garmin-auth-browser.js
sudo chmod 644 /var/www/ipca/scripts/garmin/garmin-auth-browser.js
```

3. Confirm sudoers syntax:

```shell
sudo visudo -c
```

4. Confirm idle status:

```shell
sudo -u www-data sudo -n \
  /var/www/ipca/scripts/garmin/garmin-auth-session.sh status
```

5. Start the auth session directly for validation:

```shell
sudo -u www-data sudo -n \
  /var/www/ipca/scripts/garmin/garmin-auth-session.sh start
```

6. Do not display or copy the returned VNC password into logs or documentation.
7. Confirm state becomes `awaiting_admin_login`.
8. Confirm localhost-only listener:

```shell
sudo ss -ltnp | grep ':5905'
```

Expected: `127.0.0.1:5905`, not `0.0.0.0:5905`.

9. Confirm the normal worker is paused during authentication:

```shell
systemctl is-active garmin-worker
```

10. Cancel the direct test:

```shell
sudo -u www-data sudo -n \
  /var/www/ipca/scripts/garmin/garmin-auth-session.sh stop
```

11. Confirm `garmin-worker` restarts.
12. Then test through `/admin/flight_log_garmin_connection.php`.

Manual auth test:

1. Open `/admin/flight_log_garmin_connection.php`.
2. Click `Start Garmin Authentication Session`.
3. Confirm state `awaiting_admin_login`, expiration time, SSH command, VNC URL, and one-time VNC password.
4. On the Mac, run `ssh -L 5905:127.0.0.1:5905 root@157.230.237.72`.
5. Open `vnc://localhost:5905` and enter the one-time VNC password.
6. Complete Garmin username/password, MFA, trusted-device prompts, and security challenges.
7. Click `Complete Authentication`.
8. Confirm the UI reports `authenticated`.
9. Confirm `garmin-worker` restarted:

```shell
sudo systemctl status garmin-worker --no-pager
```

10. Run `Test Connection`, then `Initial Synchronization`.

Cancellation and expiration must terminate Chromium, x11vnc, openbox, Xvfb, remove temporary runtime files, preserve the Garmin browser profile, and restart `garmin-worker`.

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
