import Combine
import AVFoundation
import Foundation
import UIKit

private let cvrUppercaseHexTable = Array("0123456789ABCDEF".utf8)

private enum CVRUnitDateFormatting {
    static let eventDateFormatter: ISO8601DateFormatter = {
        let formatter = ISO8601DateFormatter()
        formatter.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        formatter.timeZone = TimeZone(secondsFromGMT: 0)
        return formatter
    }()
}

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
    private static let maximumRecoveredContinuationGap: TimeInterval = 10 * 60

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
    private weak var workflow: CVRWorkflowStore?
    private weak var crewMessages: CrewMessagesStore?
    private var activeRecordingSessionID: String?
    private var activeRecorderToken: Data?
    private var activeRecordingEvents: [CVRRecordingEvent] = []
    private var activeFinalizedSegments: [AudioRecordingSegment] = []
    private var activeSegmentPath: String?
    private var beaconLossStartedAt: Date?
    private var activeSegmentIndex = 1
    private var activePreviousSegmentID: String?
    private var activeSourceGapSummary: String?
    private var lastThermalState: ProcessInfo.ThermalState?
    private var recoveredContinuationSessionID: String?
    private var recoveredPreviousSegmentID: String?
    private var recoveredNextSegmentIndex = 1
    private var recoveredPreviousSegmentEndedAt: Date?
    private var recoveredAudioPrelude: RecoveredAudioPrelude?
    private var lastNetworkUploadAvailable = false
    private let liveCockpitMonitor = LiveCockpitMonitorStore()

    func bind(
        audio: AudioRecorderManager,
        beacon: AvionicsBeaconManager,
        gps: GPSLocationManager,
        network: NetworkMonitor,
        remoteIPads: RemoteIPadLinkManager,
        store: RecordingStore,
        settings: SettingsStore,
        uploadManager: UploadManager,
        workflow: CVRWorkflowStore,
        crewMessages: CrewMessagesStore
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
        self.workflow = workflow
        self.crewMessages = crewMessages
        uploadManager.configureNetworkMonitor(network)
        crewMessages.bind(settings: settings, network: network, workflow: workflow)
        liveCockpitMonitor.bind(
            audio: audio,
            settings: settings,
            network: network,
            workflow: workflow
        )

        network.$statusText
            .receive(on: RunLoop.main)
            .sink { [weak self] _ in
                guard let self, let network = self.network, let settings = self.settings else { return }
                let canUpload = network.canUpload(allowCellular: settings.allowCellularUpload)
                let networkWasRestored = canUpload && !self.lastNetworkUploadAvailable
                self.lastNetworkUploadAvailable = canUpload
                if canUpload,
                   let workflow = self.workflow {
                    workflow.recoverOrphanedUploads(
                        activeComponentIDs: self.uploadManager?.activeWorkflowUploadIDs ?? []
                    )
                    workflow.requeueConnectivityFailedUploads()
                    if let store = self.store {
                        store.repairFlightSessionLinks(workflow.recordingSessionFlightRecordLinks())
                        store.requeueConnectivityFailedUploads()
                    }
                    self.attemptPendingUploads()
                    self.uploadManager?.uploadQueuedWorkflowComponents(
                        workflow: workflow,
                        settings: settings,
                        trigger: networkWasRestored ? .networkRestored : .routine
                    )
                    self.uploadManager?.retryQueuedLiveAudioSegments(settings: settings)
                    self.attemptLiveAudioSegmentUploads()
                }
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

        settings.$expectedBeaconIdentityHex
            .removeDuplicates()
            .receive(on: RunLoop.main)
            .sink { [weak beacon] identityHex in
                beacon?.setExpectedBeaconIdentityHex(identityHex)
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

        gps.$latestSample
            .compactMap { $0 }
            .receive(on: RunLoop.main)
            .sink { [weak self] sample in
                guard let self, let workflow = self.workflow else { return }
                guard workflow.considerInferredEngineStartFromTaxi(gpsSample: sample) else { return }
                if let settings = self.settings {
                    self.uploadManager?.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                }
                self.log("Off Block inferred from taxi motion (forgotten Engine Start).")
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
            guard let recordingID = audio.activeRecordingID,
                  let startedAt = audio.activeRecordingStartedAt else { return }
            Task { @MainActor in
                guard let self else { return }
                self.activeFinalizedSegments = segments
                self.activeSegmentPath = activePath
                self.saveActiveManifest(recordingID: recordingID, startedAt: startedAt, finalizedSegments: segments, activeSegmentPath: activePath)
                self.uploadLiveAudioSegments(segments, recordingID: recordingID)
            }
        }
        gps.onFlightTransition = { [weak self] transition in
            guard let self else { return }
            self.workflow?.recordGPSFlightTransition(transition)
            if let workflow = self.workflow, let settings = self.settings {
                self.uploadManager?.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
            }
        }
        gps.onEvidenceSample = { [weak self] sample in
            guard let self, let workflow = self.workflow else { return }
            workflow.recordGPSPositionEvidence(sample)
            if let settings = self.settings {
                self.uploadManager?.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
            }
        }

        lastThermalState = ProcessInfo.processInfo.thermalState
        NotificationCenter.default.publisher(for: ProcessInfo.thermalStateDidChangeNotification)
            .receive(on: RunLoop.main)
            .sink { [weak self] _ in
                self?.handleThermalStateChanged(source: "thermal_state_change")
            }
            .store(in: &cancellables)

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
        crewMessages?.appBecameActive()
        liveCockpitMonitor.appBecameActive()
        attemptPendingUploads()
        if let settings {
            uploadManager?.retryQueuedLiveAudioSegments(settings: settings)
        }
    }

    func appEnteredBackground() {
        crewMessages?.appEnteredBackground()
        liveCockpitMonitor.appEnteredBackground()
        if settings?.isBeaconTriggerEnabled == true {
            beacon?.startScan(scanAll: false)
            log("App entered background. Beacon listener confirmed active.")
        }
        recordEvent(severity: "info", type: "app_backgrounded", message: "Cockpit Recorder app entered background.")
        audio?.appDidEnterBackground()
    }

    func appWillEnterForeground() {
        audio?.appWillEnterForeground()
        crewMessages?.appBecameActive()
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
        let simulationActive = settings?.isSimulationModeEnabled == true
        guard settings?.isBeaconTriggerEnabled == true || simulationActive else { return }
        switch state {
        case .on:
            workflow?.noteAvionicsPowerState(isOn: true)
            guard audio?.isRecording != true, mode != .starting else { return }
            if simulationActive && activeRecordingSessionID != nil && mode == .recording {
                return
            }
            // Soft-split may have stopped recording while power remained ON; only auto-start
            // when a flight record exists and is ready (or continuity next-leg soft-start is requested).
            if workflow?.state.activeFlightRecord == nil {
                return
            }
            await startRecording()
        case .off:
            workflow?.noteAvionicsPowerState(isOn: false)
            if simulationActive {
                if activeRecordingSessionID != nil || mode == .recording {
                    await stopRecording(reason: "Simulation avionics OFF.")
                }
                let shouldComplete = workflow?.state.activeFlightRecord?.status == .awaitingAvionicsOff
                    || workflow?.state.operationalSession?.awaitingAvionicsOffConfirmation == true
                    || workflow?.state.activeFlightRecord?.endingHobbs != nil
                workflow?.markAvionicsOffAfterShutdown()
                if shouldComplete,
                   workflow?.completeEngineShutdownAfterAvionicsOff() == true,
                   let workflow,
                   let settings {
                    uploadManager?.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                }
                return
            }
            guard audio?.isRecording == true else {
                let shouldComplete = workflow?.state.operationalSession?.awaitingAvionicsOffConfirmation == true
                    || workflow?.state.activeFlightRecord?.status == .awaitingAvionicsOff
                workflow?.markAvionicsOffAfterShutdown()
                if shouldComplete,
                   workflow?.completeEngineShutdownAfterAvionicsOff() == true,
                   let workflow,
                   let settings {
                    uploadManager?.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                }
                return
            }
            await stopRecording(reason: "Beacon unavailable beyond iPhone finalization window.")
            let shouldComplete = workflow?.state.operationalSession?.awaitingAvionicsOffConfirmation == true
                || workflow?.state.activeFlightRecord?.status == .awaitingAvionicsOff
                || (workflow?.state.activeFlightRecord?.endingHobbs != nil
                    && workflow?.state.activeFlightRecord?.checkInMode == .engineShutdown)
            workflow?.markAvionicsOffAfterShutdown()
            if shouldComplete,
               workflow?.completeEngineShutdownAfterAvionicsOff() == true,
               let workflow,
               let settings {
                uploadManager?.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
            }
        }
    }

    /// Finalize the current leg recording while avionics may remain ON (Transient Stop soft-split).
    func finalizeRecordingForLegBoundary(reason: String) async {
        guard audio?.isRecording == true || activeRecordingSessionID != nil || mode == .recording else {
            return
        }
        await stopRecording(reason: reason)
    }

    /// Start a new recording for the next leg while avionics remain ON.
    func softStartRecordingIfAvionicsOn() async {
        guard audio?.isRecording != true, mode != .starting else { return }
        let simulationActive = settings?.isSimulationModeEnabled == true
        let avionicsOn = beacon?.currentState == .avionicsOn
            || beacon?.currentState == .temporarilyMissing
            || beacon?.isSimulationOverrideActive == true
            || simulationActive
        guard avionicsOn || settings?.isBeaconTriggerEnabled != true else { return }
        guard workflow?.state.activeFlightRecord != nil else { return }
        await startRecording()
        workflow?.consumePendingSoftStartRecording()
    }

    private func handleMatchingBeaconAdvertisement() async {
        workflow?.cancelPostFlightGarminCountdownIfBeaconReturned()
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

        if settings.isSimulationModeEnabled {
            log("Starting simulation session (no audio logging).")
            UIApplication.shared.isIdleTimerDisabled = true
            let fakeID = "sim-\(UUID().uuidString.lowercased())"
            let startedAt = Date()
            activeRecordingSessionID = fakeID
            activeRecorderToken = Self.randomRecorderToken()
            activeSegmentIndex = 1
            activePreviousSegmentID = nil
            activeRecordingEvents = []
            activeFinalizedSegments = []
            activeSegmentPath = nil
            activeSourceGapSummary = nil
            recordEvent(severity: "info", type: "simulation_session_started", message: "Simulation session started without audio capture.")
            let airportICAOs = [
                workflow?.state.activeDispatch?.plannedDepartureAirport ?? "",
                workflow?.state.activeDispatch?.plannedDestinationAirport ?? "",
            ]
            gps?.startCapture(recordingID: fakeID, startedAt: startedAt, airportICAOs: airportICAOs)
            workflow?.linkRecordingSession(recordingID: fakeID, startedAt: startedAt)
            mode = .recording
            return
        }

        log("Starting cockpit voice recording.")
        UIApplication.shared.isIdleTimerDisabled = true

        let started = await audio.startRecording(language: settings.language)
        if started {
            let recoveryGap = recoveredContinuationGap(resumedAt: audio.activeRecordingStartedAt)
            let shouldMergeRecoveredPrelude = recoveredAudioPrelude != nil
                && recoveryGap != nil
                && (recoveryGap ?? .greatestFiniteMagnitude) <= Self.maximumRecoveredContinuationGap
            if !shouldMergeRecoveredPrelude, let prelude = recoveredAudioPrelude {
                storeRecoveredPreludeAsInterruptedRecording(prelude, gap: recoveryGap)
            }

            let sessionID = shouldMergeRecoveredPrelude ? (recoveredContinuationSessionID ?? audio.activeRecordingID) : audio.activeRecordingID
            activeRecordingSessionID = sessionID
            activeRecorderToken = Self.randomRecorderToken()
            activeSegmentIndex = shouldMergeRecoveredPrelude ? recoveredNextSegmentIndex : 1
            activePreviousSegmentID = shouldMergeRecoveredPrelude ? recoveredPreviousSegmentID : nil
            activeRecordingEvents = shouldMergeRecoveredPrelude ? (recoveredAudioPrelude?.events ?? []) : []
            activeFinalizedSegments = []
            activeSegmentPath = nil
            if let previousEnded = recoveredPreviousSegmentEndedAt,
               let startedAt = audio.activeRecordingStartedAt {
                let gap = max(0, startedAt.timeIntervalSince(previousEnded))
                if shouldMergeRecoveredPrelude {
                    activeSourceGapSummary = String(format: "App was closed or restarted during recording. Generated silence gap before this segment: %.1f seconds.", gap)
                    recordEvent(severity: "warning", type: "app_restart_gap", message: "Recovered recording after app restart; replay should fill missing interval with generated silence.", durationSeconds: gap)
                } else {
                    activeSourceGapSummary = nil
                    recordEvent(severity: "info", type: "recovered_audio_split", message: "Recovered audio was stored as a separate interrupted recording because the continuation gap was too large.", durationSeconds: gap)
                }
            } else {
                activeSourceGapSummary = nil
            }
            if !shouldMergeRecoveredPrelude {
                clearRecoveredContinuationState()
            }
            recordEvent(severity: "info", type: "recording_started", message: "Recording started.")
            recordCurrentThermalStateIfNeeded(source: "recording_start")
            refreshBeaconRecorderToken(reason: "Recording started.")
            if let recordingID = audio.activeRecordingID, let startedAt = audio.activeRecordingStartedAt {
                let airportICAOs = [
                    workflow?.state.activeDispatch?.plannedDepartureAirport ?? "",
                    workflow?.state.activeDispatch?.plannedDestinationAirport ?? "",
                ]
                gps?.startCapture(recordingID: recordingID, startedAt: startedAt, airportICAOs: airportICAOs)
                saveActiveManifest(recordingID: recordingID, startedAt: startedAt, finalizedSegments: [], activeSegmentPath: nil)
                workflow?.linkRecordingSession(recordingID: activeRecordingSessionID ?? recordingID, startedAt: startedAt)
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
        UIApplication.shared.isIdleTimerDisabled = false

        if settings.isSimulationModeEnabled {
            log("\(reason) Ending simulation session.")
            if let sessionID {
                _ = gps?.stopCaptureAndSave(recordingID: sessionID)
            }
            activeRecordingSessionID = nil
            activeRecorderToken = nil
            mode = .standby
            recordEvent(severity: "info", type: "simulation_session_stopped", message: reason)
            return
        }

        log("\(reason) Stopping and storing cockpit voice recording.")

        guard var recording = await audio.stopRecording(language: settings.language, postGainDB: settings.postRecordingGainDB) else {
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
        var mergedRecoveredPrelude = false
        if let prelude = recoveredAudioPrelude {
            do {
                let currentURL = URL(fileURLWithPath: recording.filePath)
                let combinedURL = currentURL.deletingLastPathComponent().appendingPathComponent("\(recording.id).combined.m4a")
                let gap = max(0, recording.startedAt.timeIntervalSince(prelude.startedAt.addingTimeInterval(prelude.duration)))
                if gap <= Self.maximumRecoveredContinuationGap {
                    let combinedDuration = try await AudioRecorderManager.mergeAudioFiles(
                        [
                            (URL(fileURLWithPath: prelude.filePath), 0),
                            (currentURL, prelude.duration + gap)
                        ],
                        outputURL: combinedURL
                    )
                    try? FileManager.default.removeItem(at: currentURL)
                    try FileManager.default.moveItem(at: combinedURL, to: currentURL)
                    recording.startedAt = prelude.startedAt
                    recording.duration = combinedDuration
                    recording.fileSize = (try? currentURL.resourceValues(forKeys: [.fileSizeKey]).fileSize).map(Int64.init) ?? recording.fileSize
                    recording.inputDeviceName = "Recovered continuous session"
                    recording.segmentIndex = 1
                    recording.previousSegmentID = nil
                    recording.sourceGapSummary = String(format: "App was closed/restarted; generated %.1f seconds of silence between recovered audio and resumed recording.", gap)
                    recordEvent(severity: "info", type: "audio_session_merged", message: "Merged recovered pre-close audio, generated silence gap, and resumed audio into one upload.", durationSeconds: gap)
                    mergedRecoveredPrelude = true
                } else {
                    storeRecoveredPreludeAsInterruptedRecording(prelude, gap: gap)
                    recordEvent(severity: "info", type: "recovered_audio_split", message: "Recovered audio was stored as a separate interrupted recording because the continuation gap was too large.", durationSeconds: gap)
                }
            } catch {
                recording.lastError = "Could not merge recovered audio session: \(error.localizedDescription)"
                recordEvent(severity: "error", type: "audio_session_merge_failed", message: recording.lastError)
            }
            clearRecoveredContinuationState()
        }
        recording.gpsSamplesPath = gps?.stopCaptureAndSave(recordingID: recording.id)
        let recordingSessionID = sessionID ?? recording.id
        recording.flightSessionID = workflow?.state.activeFlightRecord?.id ?? recordingSessionID
        recording.operationalSessionID = workflow?.state.activeOperationalSession?.id
        workflow?.linkRecordingSession(recordingID: recordingSessionID, startedAt: recording.startedAt)
        if !mergedRecoveredPrelude {
            recording.segmentIndex = activeSegmentIndex
            recording.previousSegmentID = activePreviousSegmentID
            recording.sourceGapSummary = activeSourceGapSummary
        }
        recording.beaconDiagnosticsPath = beacon?.saveDiagnostics(
            recordingID: recording.id,
            recordingSessionID: recordingSessionID,
            recordingEndReason: reason
        )
        recordEvent(severity: "info", type: "recording_stopped", message: reason)
        if settings.postRecordingGainDB > 0 {
            recordEvent(
                severity: "info",
                type: "post_recording_gain_applied",
                message: "Post-recording gain was applied to the finalized audio file.",
                metadata: ["gain_db": String(format: "%.1f", settings.postRecordingGainDB)]
            )
        }
        recording.recordingEventsPath = saveRecordingEvents(recordingID: recording.id)
        activeRecordingSessionID = nil
        activeRecorderToken = nil
        activeRecordingEvents = []
        activeFinalizedSegments = []
        activeSegmentPath = nil
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

    private func attemptLiveAudioSegmentUploads() {
        guard let recordingID = audio?.activeRecordingID ?? activeRecordingSessionID,
              !activeFinalizedSegments.isEmpty else {
            return
        }
        uploadLiveAudioSegments(activeFinalizedSegments, recordingID: recordingID)
    }

    private func uploadLiveAudioSegments(_ segments: [AudioRecordingSegment], recordingID: String) {
        guard let settings, let uploadManager else { return }
        uploadManager.uploadFinalizedLiveAudioSegments(
            segments,
            recordingID: recordingID,
            operationalSessionUUID: workflow?.state.activeOperationalSession?.id
                ?? workflow?.state.activeDispatch?.operationalSessionUUID,
            flightRecordUUID: workflow?.state.activeFlightRecord?.id,
            settings: settings
        )
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
        _ = workflow?.beginPostFlightGarminCountdown(now: beaconLossStartedAt ?? Date())
        recordEvent(severity: "warning", type: "beacon_signal_loss", message: "Beacon communication lost; recording continued.")
    }

    private func appendEvent(_ event: CVRRecordingEvent) {
        guard audio?.isRecording == true || activeRecordingSessionID != nil else { return }
        activeRecordingEvents.append(event)
        if activeRecordingEvents.count > 500 {
            activeRecordingEvents.removeFirst(activeRecordingEvents.count - 500)
        }
        saveActiveManifestIfPossible()
        log("\(event.type): \(event.message)")
    }

    private func recordEvent(severity: String, type: String, message: String, durationSeconds: Double? = nil, metadata: [String: String] = [:]) {
        appendEvent(CVRRecordingEvent(severity: severity, type: type, message: message, durationSeconds: durationSeconds, metadata: metadata))
    }

    private func handleThermalStateChanged(source: String) {
        let state = ProcessInfo.processInfo.thermalState
        guard state != lastThermalState else { return }
        lastThermalState = state
        log("iPhone thermal state changed: \(thermalStateLabel(state)).")
        guard audio?.isRecording == true || activeRecordingSessionID != nil else { return }
        recordThermalEvent(state: state, source: source)
    }

    private func recordCurrentThermalStateIfNeeded(source: String) {
        let state = ProcessInfo.processInfo.thermalState
        lastThermalState = state
        guard state == .fair || state == .serious || state == .critical else { return }
        recordThermalEvent(state: state, source: source)
    }

    private func recordThermalEvent(state: ProcessInfo.ThermalState, source: String) {
        let label = thermalStateLabel(state)
        let overtemp = state == .serious || state == .critical
        let severity: String
        switch state {
        case .nominal, .fair:
            severity = "info"
        case .serious:
            severity = "warning"
        case .critical:
            severity = "error"
        @unknown default:
            severity = "warning"
        }
        recordEvent(
            severity: severity,
            type: "iphone_thermal_state",
            message: overtemp ? "iPhone overtemp state detected: \(label)." : "iPhone thermal state changed: \(label).",
            metadata: [
                "thermal_state": label,
                "source": source,
                "overtemp": overtemp ? "1" : "0"
            ]
        )
    }

    private func thermalStateLabel(_ state: ProcessInfo.ThermalState) -> String {
        switch state {
        case .nominal: return "nominal"
        case .fair: return "fair"
        case .serious: return "serious"
        case .critical: return "critical"
        @unknown default: return "unknown"
        }
    }

    private func saveRecordingEvents(recordingID: String) -> String? {
        saveRecordingEvents(recordingID: recordingID, events: activeRecordingEvents)
    }

    private func saveRecordingEvents(recordingID: String, events: [CVRRecordingEvent]) -> String? {
        do {
            let directory = try RecordingStore.recordingsDirectory()
            let url = directory.appendingPathComponent("\(recordingID).events.json")
            let encoder = JSONEncoder()
            encoder.outputFormatting = [.prettyPrinted, .sortedKeys]
            encoder.dateEncodingStrategy = .custom { date, encoder in
                var container = encoder.singleValueContainer()
                try container.encode(CVRUnitDateFormatting.eventDateFormatter.string(from: date))
            }
            let data = try encoder.encode(events)
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
                recorderTokenHex: activeRecorderToken?.cvrHexString ?? "",
                startedAt: startedAt,
                filePath: try RecordingStore.recordingsDirectory().appendingPathComponent("\(recordingID).m4a").path,
                finalizedSegments: finalizedSegments,
                activeSegmentPath: activeSegmentPath,
                aircraftID: settings.selectedAircraft?.id,
                aircraftRegistration: settings.selectedAircraft?.registration,
                aircraftDisplayName: settings.selectedAircraft?.displayName,
                aircraftType: settings.selectedAircraft?.aircraftType,
                aircraftADSBHex: settings.selectedAircraft?.adsbHex,
                events: activeRecordingEvents
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
            let duration = try await AudioRecorderManager.mergeSegments(finalizedSegments, outputURL: audioURL)
            let size = (try? audioURL.resourceValues(forKeys: [.fileSizeKey]).fileSize).map(Int64.init) ?? 0
            var events = manifest.events ?? []
            events.append(contentsOf: [
                CVRRecordingEvent(severity: "error", type: "app_restart", message: "Cockpit Recorder app restarted while an active recording manifest existed."),
                CVRRecordingEvent(severity: "warning", type: "audio_gap", message: "Audio after app termination is missing and must be represented as generated silence in replay.")
            ])
            recoveredAudioPrelude = RecoveredAudioPrelude(
                id: manifest.recordingID,
                startedAt: manifest.startedAt,
                duration: duration,
                filePath: audioURL.path,
                fileSize: size,
                sessionID: manifest.sessionID,
                segmentIndex: manifest.segmentIndex,
                aircraftID: manifest.aircraftID,
                aircraftRegistration: manifest.aircraftRegistration,
                aircraftDisplayName: manifest.aircraftDisplayName,
                aircraftType: manifest.aircraftType,
                aircraftADSBHex: manifest.aircraftADSBHex,
                events: events
            )
            recoveredContinuationSessionID = manifest.sessionID
            recoveredPreviousSegmentID = manifest.recordingID
            recoveredNextSegmentIndex = manifest.segmentIndex + 1
            recoveredPreviousSegmentEndedAt = manifest.startedAt.addingTimeInterval(duration)
            clearActiveManifest()
            log("Recovered interrupted recording prelude for later merge: \(manifest.recordingID)")
        } catch {
            log("Active recording recovery failed: \(error.localizedDescription)")
            clearActiveManifest()
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
        let line = "\(CVRUnitDateFormatting.eventDateFormatter.string(from: Date())) \(message)"
        eventLog.insert(line, at: 0)
        if eventLog.count > 200 {
            eventLog.removeLast(eventLog.count - 200)
        }
    }

    private func saveActiveManifestIfPossible() {
        guard let audio,
              let recordingID = audio.activeRecordingID,
              let startedAt = audio.activeRecordingStartedAt else { return }
        saveActiveManifest(
            recordingID: recordingID,
            startedAt: startedAt,
            finalizedSegments: activeFinalizedSegments,
            activeSegmentPath: activeSegmentPath
        )
    }

    private func recoveredContinuationGap(resumedAt: Date?) -> TimeInterval? {
        guard let resumedAt else { return nil }
        if let previousEnded = recoveredPreviousSegmentEndedAt {
            return max(0, resumedAt.timeIntervalSince(previousEnded))
        }
        guard let prelude = recoveredAudioPrelude else { return nil }
        return max(0, resumedAt.timeIntervalSince(prelude.startedAt.addingTimeInterval(prelude.duration)))
    }

    private func storeRecoveredPreludeAsInterruptedRecording(_ prelude: RecoveredAudioPrelude, gap: TimeInterval?) {
        guard let store, let settings else { return }
        var events = prelude.events
        events.append(CVRRecordingEvent(
            severity: "info",
            type: "recovered_recording_closed_separately",
            message: "Recovered interrupted audio was closed as a separate recording instead of being merged into the next recording.",
            durationSeconds: gap
        ))
        let eventsPath = saveRecordingEvents(recordingID: prelude.id, events: events)
        let gapText = gap.map { String(format: " Continuation gap before the next recording was %.1f seconds, exceeding the %.0f second merge limit.", $0, Self.maximumRecoveredContinuationGap) } ?? ""
        let recording = Recording(
            id: prelude.id,
            serverID: nil,
            startedAt: prelude.startedAt,
            duration: prelude.duration,
            filePath: prelude.filePath,
            inputDeviceName: "Recovered interrupted session",
            aircraftID: prelude.aircraftID,
            aircraftRegistration: prelude.aircraftRegistration,
            aircraftDisplayName: prelude.aircraftDisplayName,
            aircraftType: prelude.aircraftType,
            aircraftADSBHex: prelude.aircraftADSBHex,
            fileSize: prelude.fileSize,
            uploadStatus: .pending,
            transcriptStatus: .pending,
            uploadProgress: 0,
            transcriptProgress: 0,
            language: settings.language,
            transcript: "",
            lastError: "Recovered after app restart and queued as a separate interrupted recording.",
            recordingEventsPath: eventsPath,
            flightSessionID: workflow?.state.activeFlightRecord?.id ?? prelude.sessionID,
            operationalSessionID: workflow?.state.activeOperationalSession?.id,
            segmentIndex: prelude.segmentIndex,
            sourceGapSummary: "App was closed or restarted during recording. Recovered pre-close audio was saved as a separate interrupted recording.\(gapText)"
        )
        store.add(recording)
        log("Stored recovered interrupted audio separately: \(prelude.id).")
    }

    private func clearRecoveredContinuationState() {
        recoveredAudioPrelude = nil
        recoveredContinuationSessionID = nil
        recoveredPreviousSegmentID = nil
        recoveredNextSegmentIndex = 1
        recoveredPreviousSegmentEndedAt = nil
    }
}

private extension Data {
    var cvrHexString: String {
        var output: [UInt8] = []
        output.reserveCapacity(count * 2)
        for byte in self {
            output.append(cvrUppercaseHexTable[Int(byte >> 4)])
            output.append(cvrUppercaseHexTable[Int(byte & 0x0F)])
        }
        return String(decoding: output, as: UTF8.self)
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
    var events: [CVRRecordingEvent]?
}

private struct RecoveredAudioPrelude {
    var id: String
    var startedAt: Date
    var duration: TimeInterval
    var filePath: String
    var fileSize: Int64
    var sessionID: String
    var segmentIndex: Int
    var aircraftID: Int?
    var aircraftRegistration: String?
    var aircraftDisplayName: String?
    var aircraftType: String?
    var aircraftADSBHex: String?
    var events: [CVRRecordingEvent]
}
