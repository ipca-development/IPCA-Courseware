# Airport Operations Flip Board Kiosk

## Routes

- Kiosk display: `/tv/flipboard.php?screen=main`
- Aircraft status board: `/tv/flipboard.php?screen=aircraft`
- Admin control: `/admin/tv_screens/index.php`
- Active message API: `/tv/api/messages.php?screen_key=main`
- Aircraft status API: `/tv/api/aircraft_status.php?hex=a4b605&label=N153PC&home_airport=KTRM`

## Database Setup

Apply the migration:

```bash
mysql "$CW_DB_NAME" < scripts/sql/2026_05_20_tv_screen_messages.sql
mysql "$CW_DB_NAME" < scripts/sql/2026_06_20_tv_screen_playlist_types.sql
```

Use the same MySQL connection settings as the application environment. The API reads active messages where `starts_at` and `ends_at` include the current UTC time. Urgent messages are any `message_type = 'urgent'` or `priority >= 90`; when present, they override normal rotation.

## Message Modes

- `standard`: rotating operational announcements.
- `urgent`: interrupting high-priority operational override.
- `schedule`: departure-board style rows. Put one row per line in the body, using `TITLE | STATUS`.
- `night`: normal board content with dimmed kiosk lighting when opened with `mode=night`.
- `aircraft`: live ADS-B status board for a tail number (for example `N397EA – At the SPC Gate`).
- `aircraft_board`: fleet ADS-B ops grid (same view as `/tv/flipboard.php?screen=aircraft`).
- `radar`: KTRM live radar scope + weather (preloaded in background for instant display).

## Playlist rotation

Create **multiple active messages** with the **same screen key** (for example `aircraft`). Each message is one playlist slot. Set **Display seconds** per slot (5–300). The kiosk cycles through slots in API order (priority, then schedule).

Example on `screen=aircraft`:

1. `standard` — announcement — 30 seconds
2. `radar` — LIVE RADAR — 60 seconds
3. `aircraft_board` — AIRCRAFT OPS — 60 seconds

Point the TV at `/tv/flipboard.php?screen=aircraft`. With no active messages, `screen=aircraft` and `screen=radar` still show their dedicated full-time views.

## Live Aircraft Status (ADS-B Exchange)

The flip board can show real-time aircraft movement using the [ADS-B Exchange API v2](https://gateway.adsbexchange.com/api/aircraft/v2/docs/index.html).

1. Set your RapidAPI key in PHP-FPM (for example `CW_ADSBEXCHANGE_API_KEY` in `/etc/php/*/fpm/pool.d/www.conf`).

2. Apply migrations:

```bash
mysql "$CW_DB_NAME" < scripts/sql/2026_05_31_tv_screen_aircraft_type.sql
mysql "$CW_DB_NAME" < scripts/sql/2026_06_07_tv_screen_aircraft_fields.sql
```

3. In admin, open **Settings** and configure the SPC gate coordinates (defaults target KTRM / Thermal).

4. Add a message with mode **Aircraft (ADS-B)** and set:
   - **ADS-B hex code** (example: `a4b605`)
   - **Preferred name** (example: `N153PC`)
   - **Home base** (example: `KTRM — Thermal`)

Example live status lines:

- `N397EA – At the SPC Gate`
- `N397EA – Taxiing to RWY`
- `N397EA – In Flight (10.2 NM, South-East)`
- `N397EA – Landed in Blythe (KBLH)`

The kiosk polls aircraft status every 15 seconds (configurable in admin) and animates the split-flap board when status changes.

## Audio

The board uses Web Audio API synthesis for mechanical flap clicks, randomized rattle, settling impacts, and a three-note airport chime. Optional production samples can be added here:

- `/public/tv/assets/audio/flaps/click-01.mp3`
- `/public/tv/assets/audio/flaps/click-02.mp3`
- `/public/tv/assets/audio/flaps/rattle-01.mp3`
- `/public/tv/assets/audio/flaps/settle-01.mp3`
- `/public/tv/assets/audio/chimes/airport-chime.mp3`

PA announcements use **OpenAI TTS only** (no browser speech synthesis). Each message can select an airport PA voice in admin. When `audio_url` is empty, the kiosk requests `/tv/api/announcement.php`, which synthesizes speech from `voice_text` (or title/body fallback), caches MP3s under `storage/tv_announcements/`, and plays them through the same Web Audio graph as the chime.

Apply the voice migration after the base TV table:

```bash
mysql "$CW_DB_NAME" < scripts/sql/2026_05_30_tv_screen_pa_voice.sql
```

Chrome requires a user gesture before audio playback on many configurations. The kiosk route includes an `Enable Airport PA Audio` control; for unattended Mac Mini deployment, configure Chrome autoplay policy as shown below.

## Chrome Kiosk Launch

Example command:

```bash
/Applications/Google\ Chrome.app/Contents/MacOS/Google\ Chrome \
  --kiosk "https://IPCA.training/tv/flipboard.php?screen=main" \
  --autoplay-policy=no-user-gesture-required \
  --disable-session-crashed-bubble \
  --disable-infobars \
  --no-first-run
```

For a local/staging URL, replace the domain with the deployed app URL. Use the `screen` query value to target a different message group, for example `screen=dispatch` or `screen=lobby`.

## macOS Startup Automation

1. Create an Automator application or LaunchAgent that runs the Chrome kiosk command.
2. Add it to System Settings -> General -> Login Items for the kiosk user.
3. Disable display sleep and enable automatic login only for the dedicated kiosk account.
4. Connect the Mac Mini audio output to the display or PA input and verify Chrome is allowed to play audio.
5. Set the TV to the native resolution and disable overscan or motion smoothing.

## Fullscreen Setup

- Use Chrome kiosk mode for production.
- Keep display scaling at default/native resolution.
- Confirm the board is not browser-zoomed; set Chrome zoom to 100%.
- For night operations, open `/tv/flipboard.php?screen=main&mode=night`.

## Long-Running Notes

The frontend polls every seven seconds, hashes message payloads, and only re-renders when content changes or the rotation advances. Character tiles are reused rather than recreated during normal operation to reduce garbage collection. The API uses prepared statements and returns only active messages.
