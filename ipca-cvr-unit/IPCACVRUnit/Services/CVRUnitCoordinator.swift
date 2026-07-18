import Combine
import AVFoundation
import Foundation
import UIKit

enum CVRUnitMode: String {
    case standby = "Standby"
    case starting = "Starting"
    case recording = "Recording"
    case stopping = "Stopping"
    case pendingUpload = "Pending Upload"
    case uploading = "Uploading"
    case error = "Error"
}

@MainActor
final class CVRUnitCoordinator: ObservableObject {
    @Published private(set) var mode: CVRUnitMode = .standby
    @Published private(set) var eventLog: [String] = []

    private var cancellables: Set<AnyCancellable> = []
    private weak var audio: AudioRecorderManager?
    private weak var beacon: AvionicsBeaconManager?
    private weak var gps: GPSLocationManager?
    private weak var network: NetworkMonitor?
    private weak var remoteIPads: RemoteIPadLinkManager?
    private weak var store: RecordingStore?
    private weak var settings: SettingsStore?
    private weak var uploadManager: UploadManager?
    private var activeRecordingSessionID: String?
    private var activeRecorderToken: Data?
    private var activeRecordingEvents: [CVRRecordingEvent] = []
    private var beaconLossStartedAt: Date?
    private var activeSegmentIndex = 1
    private var activePreviousSegmentID: String?
    private var activeSourceGapSummary: String?
    private var recoveredContinuationSessionID: String?
    private var recoveredPreviousSegmentID: String?
    private var recoveredNextSegmentIndex = 1
    private var recoveredPreviousSegmentEndedAt: Date?

    func bind(
        audio: AudioRecorderManager,
        beacon: AvionicsBeaconManager,
        gps: GPSLocationManager,
        network: NetworkMonitor,
        remoteIPads: RemoteIPadLinkManager,
        store: RecordingStore,
        settings: SettingsStore,
        uploadManager: UploadManager
    ) {
        guard self.audio == nil else { return }
        self.audio = audio
        self.beacon = beacon
        self.gps = gps
        self.network = network
        self.remoteIPads = remoteIPads
        self.store = store
        self.settings = settings
        self.uploadManager = uploadManager

        network.$statusText
            .receive(on: RunLoop.main)
            .sink { [weak self] _ in
                self?.attemptPendingUploads()
            }
            .store(in: &cancellables)

        uploadManager.$activeUploads
            .removeDuplicates()
            .receive(on: RunLoop.main)
            .sink { [weak self] _ in
                self?.refreshModeFromCurrentState()
            }
            .store(in: &cancellables)

        store.$recordings
            .receive(on: RunLoop.main)
            .sink { [weak self] _ in
                self?.refreshModeFromCurrentState()
            }
            .store(in: &cancellables)

        settings.$isBeaconTriggerEnabled
            .removeDuplicates()
            .receive(on: RunLoop.main)
            .sink { [weak self] enabled in
                self?.handleBeaconTriggerEnabled(enabled)
            }
            .store(in: &cancellables)

        beacon.$avionicsPowerState
            .compactMap { $0 }
            .receive(on: RunLoop.main)
            .sink { [weak self] state in
                Task { @MainActor in
                    await self?.handleAvionicsPowerState(state)
                }
            }
            .store(in: &cancellables)

        beacon.onMatchingBeaconAdvertisement = { [weak self] in
            Task { @MainActor in
                await self?.handleMatchingBeaconAdvertisement()
            }
        }
        beacon.onBeaconRelationshipAvailable = { [weak self] in
            Task { @MainActor in
                self?.refreshBeaconRecorderToken(reason: "Beacon GATT relationship available.")
            }
        }
        beacon.onBeaconCommunicationLost = { [weak self] in
            Task { @MainActor in
                self?.handleBeaconCommunicationLost()
            }
        }
        beacon.onBeaconRebootDetected = { [weak self] oldBoot, newBoot, reason in
            Task { @MainActor in
                self?.recordEvent(
                    severity: "warning",
                    type: "beacon_power_loss",
                    message: "Beacon reboot detected during recording.",
                    metadata: [
                        "old_boot_counter": "\(oldBoot)",
                        "new_boot_counter": "\(newBoot)",
                        "reset_reason": reason.rawValue
                    ]
                )
            }
        }

        audio.$isInternalMicWarning
            .removeDuplicates()
            .receive(on: RunLoop.main)
            .sink { [weak self] warning in
                self?.handleAudioWarningChanged(warning)
            }
            .store(in: &cancellables)
        audio.onAudioEvent = { [weak self] event in
            Task { @MainActor in
                self?.appendEvent(event)
            }
        }
        audio.onAudioSegmentsChanged = { [weak self] segments, activePath in
            Task { @MainActor in
                guard let self,
                      let recordingID = audio.activeRecordingID,
                      let startedAt = audio.activeRecordingStartedAt else { return }
                self.saveActiveManifest(recordingID: recordingID, startedAt: startedAt, finalizedSegments: segments, activeSegmentPath: activePath)
            }
        }

        remoteIPads.$resetCommandRequestedAt
            .compactMap { $0 }
            .receive(on: RunLoop.main)
            .sink { [weak self] _ in
                Task { @MainActor in
                    await self?.resetAudioRoute(source: "instructor/student iPad")
                }
            }
            .store(in: &cancellables)

        Task { @MainActor in
            await recoverInterruptedRecordingIfNeeded()
            handleBeaconTriggerEnabled(settings.isBeaconTriggerEnabled)
        }
    }

    func appBecameActive() {
        recordEvent(severity: "info", type: "app_became_active", message: "Cockpit Recorder app became active.")
        attemptPendingUploads()
    }

    func appEnteredBackground() {
        if settings?.isBeaconTriggerEnabled == true {
            beacon?.startScan(scanAll: false)
            log("App entered background. Beacon listener confirmed active.")
        }
        recordEvent(severity: "info", type: "app_backgrounded", message: "Cockpit Recorder app entered background.")
        audio?.appDidEnterBackground()
    }

    func appWillEnterForeground() {
        audio?.appWillEnterForeground()
        recordEvent(severity: "info", type: "app_foregrounded", message: "Cockpit Recorder app returned to foreground.")
        attemptPendingUploads()
    }

    func resetAudioRoute(source: String = "local UI") async {
        guard let audio else { return }
        log("Audio route reset requested by \(source).")
        await audio.resetAudioRoute()
        handleAudioWarningChanged(audio.isInternalMicWarning)
    }

    private func handleBeaconTriggerEnabled(_ enabled: Bool) {
        if enabled {
            beacon?.startScan(scanAll: false)
            log("Beacon trigger enabled. Listening for ESP-32 avionics beacon.")
        } else {
            beacon?.stopScan()
            log("Beacon trigger disabled.")
        }
    }

    private func handleAvionicsPowerState(_ state: AvionicsPowerState) async {
        guard settings?.isBeaconTriggerEnabled == true else { return }
        switch state {
        case .on:
            guard audio?.isRecording != true, mode != .starting else { return }
            await startRecording()
        case .off:
            guard audio?.isRecording == true else { return }
            await stopRecording(reason: "Beacon unavailable beyond iPhone finalization window.")
        }
    }

    private func handleMatchingBeaconAdvertisement() async {
        guard settings?.isBeaconTriggerEnabled == true else { return }
        if audio?.isRecording == true {
            refreshBeaconRecorderToken(reason: "Beacon rediscovered during active recording.")
            return
        }
        guard mode != .starting else { return }
        log("ESP-32 beacon advertisement received. Starting recording.")
        await startRecording()
    }

    private func startRecording() async {
        guard let audio, let settings else { return }
        mode = .starting
        log("Starting cockpit voice recording.")
        UIApplication.shared.isIdleTimerDisabled = true

        let started = await audio.startRecording(language: settings.language)
        if started {
            let sessionID = recoveredContinuationSessionID ?? audio.activeRecordingID
            activeRecordingSessionID = sessionID
            activeRecorderToken = Self.randomRecorderToken()
            activeSegmentIndex = recoveredNextSegmentIndex
            activePreviousSegmentID = recoveredPreviousSegmentID
            activeRecordingEvents = []
            if let previousEnded = recoveredPreviousSegmentEndedAt,
               let startedAt = audio.activeRecordingStartedAt {
                let gap = max(0, startedAt.timeIntervalSince(previousEnded))
                activeSourceGapSummary = String(format: "App was closed or restarted during recording. Generated silence gap before this segment: %.1f seconds.", gap)
                recordEvent(severity: "warning", type: "app_restart_gap", message: "Recovered recording after app restart; replay should fill missing interval with generated silence.", durationSeconds: gap)
            } else {
                activeSourceGapSummary = nil
            }
            recoveredContinuationSessionID = nil
            recoveredPreviousSegmentID = nil
            recoveredNextSegmentIndex = 1
            recoveredPreviousSegmentEndedAt = nil
            recordEvent(severity: "info", type: "recording_started", message: "Recording started.")
            refreshBeaconRecorderToken(reason: "Recording started.")
            if let recordingID = audio.activeRecordingID, let startedAt = audio.activeRecordingStartedAt {
                gps?.startCapture(recordingID: recordingID, startedAt: startedAt)
                saveActiveManifest(recordingID: recordingID, startedAt: startedAt, finalizedSegments: [], activeSegmentPath: nil)
            }
            mode = .recording
            log("Recording started: \(audio.activeRecordingID ?? "unknown").")
            handleAudioWarningChanged(audio.isInternalMicWarning)
        } else {
            mode = .error
            log("Recording failed: \(audio.lastError)")
        }
    }

    private func stopRecording(reason: String) async {
        guard let audio, let store, let settings else { return }
        let sessionID = activeRecordingSessionID
        mode = .stopping
        log("\(reason) Stopping and storing cockpit voice recording.")
        UIApplication.shared.isIdleTimerDisabled = false

        guard var recording = await audio.stopRecording(language: settings.language) else {
            mode = .standby
            return
        }

        if let aircraft = settings.selectedAircraft {
            recording.aircraftID = aircraft.id
            recording.aircraftRegistration = aircraft.registration
            recording.aircraftDisplayName = aircraft.displayName
            recording.aircraftType = aircraft.aircraftType
            recording.aircraftADSBHex = aircraft.adsbHex
        }
        recording.gpsSamplesPath = gps?.stopCaptureAndSave(recordingID: recording.id)
        recording.flightSessionID = sessionID ?? recording.id
        recording.segmentIndex = activeSegmentIndex
        recording.previousSegmentID = activePreviousSegmentID
        recording.sourceGapSummary = activeSourceGapSummary
        recording.beaconDiagnosticsPath = beacon?.saveDiagnostics(
            recordingID: recording.id,
            recordingSessionID: recording.flightSessionID,
            recordingEndReason: reason
        )
        recordEvent(severity: "info", type: "recording_stopped", message: reason)
        recording.recordingEventsPath = saveRecordingEvents(recordingID: recording.id)
        activeRecordingSessionID = nil
        activeRecorderToken = nil
        activeRecordingEvents = []
        beaconLossStartedAt = nil
        activeSegmentIndex = 1
        activePreviousSegmentID = nil
        activeSourceGapSummary = nil
        clearActiveManifest()
        beacon?.setRecorderToken(nil)

        store.add(recording)
        log("Stored audio permanently on iPhone: \(recording.id).")
        mode = .pendingUpload
        attemptPendingUploads()
    }

    private func attemptPendingUploads() {
        guard let store, let settings, let uploadManager, let network else { return }
        guard network.canUpload(allowCellular: settings.allowCellularUpload) else {
            if mode == .uploading {
                mode = .pendingUpload
            }
            return
        }
        refreshModeFromCurrentState()
        uploadManager.uploadPending(store: store, settings: settings, network: network)
    }

    private func refreshModeFromCurrentState() {
        guard mode != .starting, mode != .stopping, mode != .error else { return }
        if audio?.isRecording == true {
            mode = .recording
            return
        }
        if uploadManager?.activeUploads.isEmpty == false {
            mode = .uploading
            return
        }
        if store?.pendingUploadIDs().isEmpty == false {
            mode = .pendingUpload
            return
        }
        mode = .standby
    }

    private func handleAudioWarningChanged(_ warning: Bool) {
        guard let audio else { return }
        guard warning else {
            remoteIPads?.clearAudioSourceWarning()
            log("Audio source restored: \(audio.selectedInputName).")
            recordEvent(severity: "info", type: "audio_source_restored", message: "Audio source restored: \(audio.selectedInputName)")
            return
        }
        let message = "Audio source is \(audio.selectedInputName). Reset USB-C EarPods audio path."
        remoteIPads?.publishAudioSourceWarning(message)
        log(message)
        recordEvent(severity: "warning", type: "audio_source_warning", message: message)
    }

    private func refreshBeaconRecorderToken(reason: String) {
        guard audio?.isRecording == true, let activeRecorderToken else { return }
        beacon?.setRecorderToken(activeRecorderToken)
        log("\(reason) Refreshed opaque recorder token with beacon.")
    }

    private static func randomRecorderToken() -> Data {
        Data((0..<16).map { _ in UInt8.random(in: UInt8.min...UInt8.max) })
    }

    private func handleBeaconCommunicationLost() {
        log("Beacon GATT relationship lost. Continuing recording during reconnection window.")
        beaconLossStartedAt = Date()
        recordEvent(severity: "warning", type: "beacon_signal_loss", message: "Beacon communication lost; recording continued.")
    }

    private func appendEvent(_ event: CVRRecordingEvent) {
        guard audio?.isRecording == true || activeRecordingSessionID != nil else { return }
        activeRecordingEvents.append(event)
        if activeRecordingEvents.count > 500 {
            activeRecordingEvents.removeFirst(activeRecordingEvents.count - 500)
        }
        log("\(event.type): \(event.message)")
    }

    private func recordEvent(severity: String, type: String, message: String, durationSeconds: Double? = nil, metadata: [String: String] = [:]) {
        appendEvent(CVRRecordingEvent(severity: severity, type: type, message: message, durationSeconds: durationSeconds, metadata: metadata))
    }

    private func saveRecordingEvents(recordingID: String) -> String? {
        do {
            let directory = try RecordingStore.recordingsDirectory()
            let url = directory.appendingPathComponent("\(recordingID).events.json")
            let encoder = JSONEncoder()
            encoder.outputFormatting = [.prettyPrinted, .sortedKeys]
            encoder.dateEncodingStrategy = .custom { date, encoder in
                let formatter = ISO8601DateFormatter()
                formatter.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
                formatter.timeZone = TimeZone(secondsFromGMT: 0)
                var container = encoder.singleValueContainer()
                try container.encode(formatter.string(from: date))
            }
            let data = try encoder.encode(activeRecordingEvents)
            try data.write(to: url, options: [.atomic])
            return url.path
        } catch {
            log("Could not save recording events: \(error.localizedDescription)")
            return nil
        }
    }

    private func saveActiveManifest(recordingID: String, startedAt: Date, finalizedSegments: [AudioRecordingSegment], activeSegmentPath: String?) {
        guard let settings else { return }
        do {
            let manifest = ActiveRecordingManifest(
                recordingID: recordingID,
                sessionID: activeRecordingSessionID ?? recordingID,
                segmentIndex: activeSegmentIndex,
                previousSegmentID: activePreviousSegmentID,
                recorderTokenHex: activeRecorderToken?.map { String(format: "%02X", $0) }.joined() ?? "",
                startedAt: startedAt,
                filePath: try RecordingStore.recordingsDirectory().appendingPathComponent("\(recordingID).m4a").path,
                finalizedSegments: finalizedSegments,
                activeSegmentPath: activeSegmentPath,
                aircraftID: settings.selectedAircraft?.id,
                aircraftRegistration: settings.selectedAircraft?.registration,
                aircraftDisplayName: settings.selectedAircraft?.displayName,
                aircraftType: settings.selectedAircraft?.aircraftType,
                aircraftADSBHex: settings.selectedAircraft?.adsbHex
            )
            let url = try Self.activeManifestURL()
            let encoder = JSONEncoder()
            encoder.dateEncodingStrategy = .iso8601
            encoder.outputFormatting = [.prettyPrinted, .sortedKeys]
            let data = try encoder.encode(manifest)
            try data.write(to: url, options: [.atomic])
        } catch {
            log("Could not save active recording manifest: \(error.localizedDescription)")
        }
    }

    private func recoverInterruptedRecordingIfNeeded() async {
        guard let store, let settings else { return }
        do {
            let url = try Self.activeManifestURL()
            guard FileManager.default.fileExists(atPath: url.path) else { return }
            let data = try Data(contentsOf: url)
            let decoder = JSONDecoder()
            decoder.dateDecodingStrategy = .iso8601
            let manifest = try decoder.decode(ActiveRecordingManifest.self, from: data)
            let finalizedSegments = (manifest.finalizedSegments ?? []).filter { FileManager.default.fileExists(atPath: $0.filePath) && $0.duration > 0 }
            guard !finalizedSegments.isEmpty else {
                clearActiveManifest()
                log("Interrupted recording had no finalized segments to recover.")
                return
            }
            let audioURL = try RecordingStore.recordingsDirectory().appendingPathComponent("\(manifest.recordingID).m4a")
            try await AudioRecorderManager.mergeSegments(finalizedSegments, outputURL: audioURL)
            let size = (try? audioURL.resourceValues(forKeys: [.fileSizeKey]).fileSize).map(Int64.init) ?? 0
            let duration = finalizedSegments.reduce(0) { $0 + max(0, $1.duration) }
            let events = [
                CVRRecordingEvent(severity: "error", type: "app_restart", message: "Cockpit Recorder app restarted while an active recording manifest existed."),
                CVRRecordingEvent(severity: "warning", type: "audio_gap", message: "Audio after app termination is missing and must be represented as generated silence in replay.")
            ]
            let eventsPath = saveRecoveredEvents(recordingID: manifest.recordingID, events: events)
            var recovered = Recording(
                id: manifest.recordingID,
                serverID: nil,
                startedAt: manifest.startedAt,
                duration: duration,
                filePath: audioURL.path,
                inputDeviceName: "Recovered audio segment",
                aircraftID: manifest.aircraftID ?? settings.selectedAircraft?.id,
                aircraftRegistration: manifest.aircraftRegistration ?? settings.selectedAircraft?.registration,
                aircraftDisplayName: manifest.aircraftDisplayName ?? settings.selectedAircraft?.displayName,
                aircraftType: manifest.aircraftType ?? settings.selectedAircraft?.aircraftType,
                aircraftADSBHex: manifest.aircraftADSBHex ?? settings.selectedAircraft?.adsbHex,
                fileSize: size,
                uploadStatus: .pending,
                transcriptStatus: .pending,
                uploadProgress: 0,
                transcriptProgress: 0,
                language: settings.language,
                transcript: "",
                lastError: "Recovered after app restart. Missing interval after this segment must be filled with generated silence.",
                recordingEventsPath: eventsPath,
                flightSessionID: manifest.sessionID,
                segmentIndex: manifest.segmentIndex,
                previousSegmentID: manifest.previousSegmentID,
                sourceGapSummary: "App was closed before this recording could be finalized normally."
            )
            recovered.beaconDiagnosticsPath = beacon?.saveDiagnostics(
                recordingID: recovered.id,
                recordingSessionID: recovered.flightSessionID,
                recordingEndReason: "Recovered after app restart"
            )
            store.add(recovered)
            recoveredContinuationSessionID = manifest.sessionID
            recoveredPreviousSegmentID = manifest.recordingID
            recoveredNextSegmentIndex = manifest.segmentIndex + 1
            recoveredPreviousSegmentEndedAt = manifest.startedAt.addingTimeInterval(duration)
            clearActiveManifest()
            log("Recovered interrupted recording segment: \(manifest.recordingID)")
        } catch {
            log("Active recording recovery failed: \(error.localizedDescription)")
            clearActiveManifest()
        }
    }

    private func saveRecoveredEvents(recordingID: String, events: [CVRRecordingEvent]) -> String? {
        do {
            let directory = try RecordingStore.recordingsDirectory()
            let url = directory.appendingPathComponent("\(recordingID).events.json")
            let encoder = JSONEncoder()
            encoder.outputFormatting = [.prettyPrinted, .sortedKeys]
            encoder.dateEncodingStrategy = .iso8601
            try encoder.encode(events).write(to: url, options: [.atomic])
            return url.path
        } catch {
            return nil
        }
    }

    private func clearActiveManifest() {
        if let url = try? Self.activeManifestURL() {
            try? FileManager.default.removeItem(at: url)
        }
    }

    private static func activeManifestURL() throws -> URL {
        let base = try FileManager.default.url(for: .applicationSupportDirectory, in: .userDomainMask, appropriateFor: nil, create: true)
        let dir = base.appendingPathComponent("IPCACVRUnit", isDirectory: true)
        try FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        return dir.appendingPathComponent("active-recording-manifest.json")
    }

    private func log(_ message: String) {
        let formatter = ISO8601DateFormatter()
        let line = "\(formatter.string(from: Date())) \(message)"
        eventLog.insert(line, at: 0)
        if eventLog.count > 200 {
            eventLog.removeLast(eventLog.count - 200)
        }
    }
}

private struct ActiveRecordingManifest: Codable {
    var recordingID: String
    var sessionID: String
    var segmentIndex: Int
    var previousSegmentID: String?
    var recorderTokenHex: String
    var startedAt: Date
    var filePath: String
    var finalizedSegments: [AudioRecordingSegment]?
    var activeSegmentPath: String?
    var aircraftID: Int?
    var aircraftRegistration: String?
    var aircraftDisplayName: String?
    var aircraftType: String?
    var aircraftADSBHex: String?
}
