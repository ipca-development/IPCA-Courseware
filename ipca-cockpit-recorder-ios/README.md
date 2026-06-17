# IPCA Cockpit Recorder iOS POC

Native iPad-only SwiftUI test app for recording audio from a connected USB audio interface and uploading it to the IPCA Courseware backend for asynchronous stub transcription.

## Requirements

- Xcode with iPadOS 17 SDK or newer
- iPad running iPadOS 17+
- USB audio interface connected to the iPad
- Backend migration applied:

```sh
mysql ... < scripts/sql/2026_06_17_cockpit_recorder_poc.sql
```

## Backend API

Configure the app Settings screen with the backend origin, for example:

```text
https://courseware.example.com
```

The app calls:

- `POST /api/recordings/upload`
- `GET /api/recordings/{id}/status`
- `GET /api/recordings/{id}/transcript`
- `GET /api/recordings`

The public API is intentionally unauthenticated for this POC. The admin verification page remains protected at:

```text
/admin/cockpit_recorder.php
```

## Manual iPad Acceptance Test

1. Open `IPCARecorder.xcodeproj` in Xcode.
2. Select an iPad device or iPad simulator. Real USB audio testing requires a physical iPad.
3. Build and run the `IPCARecorder` target.
4. Set the backend server URL in Settings.
5. Connect the USB audio interface.
6. Confirm the Recorder screen shows the USB device as active.
7. Press Record and feed speech/intercom audio into the USB interface.
8. Pause, then Resume.
9. Stop the recording.
10. Confirm the recording appears in Recordings and upload progress starts.
11. Confirm the admin page shows the uploaded audio.
12. Confirm stub transcription completes and the transcript appears in the app and admin page.
