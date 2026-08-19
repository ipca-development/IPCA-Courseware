# IPCA Garmin Sync — Hardware Proof of Concept

Physical SD readers, Files-provider behavior, and security-scoped bookmark restoration are not verified by simulator or fixture tests.

## Test environment

- Finding: **PENDING**
- iPhone/iPad model: **PENDING**
- iOS/iPadOS version: **PENDING**
- SD reader make/model/interface: **PENDING**
- Garmin SD card/filesystem format: **PENDING**

## On-device checklist

- [ ] Install a signed build on an iOS/iPadOS 17+ device.
- [ ] Connect the supported SD reader and insert a representative Garmin card.
- [ ] Confirm the card appears in Files without granting the app broad storage access.
- [ ] Choose the card root with the folder document picker.
- [ ] Confirm nested folders are traversed and every `.csv`/`.CSV` is counted.
- [ ] Background and foreground the app during hashing and copying; confirm progress resumes or safely retries.
- [ ] Disconnect during copy; relaunch and confirm `.partial` removal and retry without source changes.
- [ ] Complete a scan; confirm “Safe to eject” appears only after every new file is locally verified.
- [ ] Eject using the OS/reader’s documented procedure, return the card, then tap “SD Card Returned.”
- [ ] Relaunch with the reader disconnected; confirm verified local files remain available for upload.
- [ ] Reconnect the same card and perform an identical full scan; confirm no duplicate local file record.
- [ ] Replace one CSV’s content while reusing its filename; confirm a new ledger record and local copy.
- [ ] Relaunch and confirm the saved folder bookmark restores when the provider permits it.
- [ ] Revoke Files-provider access or move the folder; confirm the app asks the user to choose again.
- [ ] Upload on Wi-Fi, interrupt connectivity, and confirm the same persisted upload UUID resumes.
- [ ] Verify the server rejects a deliberately wrong finalize hash/size and the app does not mark it verified.
- [ ] Verify server-verified local files remain in Application Support.
- [ ] Inspect device logs and confirm no Bearer credential appears.

## Findings

- Folder picker/provider behavior: **PENDING**
- Security-scoped bookmark persistence across reboot: **PENDING**
- Reader disconnect/reconnect behavior: **PENDING**
- Large-card performance and thermal behavior: **PENDING**
- Safe-eject operator flow: **PENDING**
- Upload interruption/resume behavior on device: **PENDING**
- Credential/log inspection: **PENDING**
