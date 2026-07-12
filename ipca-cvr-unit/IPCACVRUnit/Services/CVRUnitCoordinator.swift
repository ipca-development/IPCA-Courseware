import Combine
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
    private weak var network: NetworkMonitor?
    private weak var remoteIPads: RemoteIPadLinkManager?
    private weak var store: RecordingStore?
    private weak var settings: SettingsStore?
    private weak var uploadManager: UploadManager?

    func bind(
        audio: AudioRecorderManager,
        beacon: AvionicsBeaconManager,
        network: NetworkMonitor,
        remoteIPads: RemoteIPadLinkManager,
        store: RecordingStore,
        settings: SettingsStore,
        uploadManager: UploadManager
    ) {
        guard self.audio == nil else { return }
        self.audio = audio
        self.beacon = beacon
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

        audio.$isInternalMicWarning
            .removeDuplicates()
            .receive(on: RunLoop.main)
            .sink { [weak self] warning in
                self?.handleAudioWarningChanged(warning)
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

        handleBeaconTriggerEnabled(settings.isBeaconTriggerEnabled)
    }

    func appBecameActive() {
        attemptPendingUploads()
    }

    func appEnteredBackground() {
        if settings?.isBeaconTriggerEnabled == true {
            beacon?.startScan(scanAll: false)
            log("App entered background. Beacon listener confirmed active.")
        }
        audio?.appDidEnterBackground()
    }

    func appWillEnterForeground() {
        audio?.appWillEnterForeground()
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
            stopRecording(reason: "Avionics beacon OFF.")
        }
    }

    private func handleMatchingBeaconAdvertisement() async {
        guard settings?.isBeaconTriggerEnabled == true else { return }
        guard audio?.isRecording != true, mode != .starting else { return }
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
            mode = .recording
            log("Recording started: \(audio.activeRecordingID ?? "unknown").")
            handleAudioWarningChanged(audio.isInternalMicWarning)
        } else {
            mode = .error
            log("Recording failed: \(audio.lastError)")
        }
    }

    private func stopRecording(reason: String) {
        guard let audio, let store, let settings else { return }
        mode = .stopping
        log("\(reason) Stopping and storing cockpit voice recording.")
        UIApplication.shared.isIdleTimerDisabled = false

        guard var recording = audio.stopRecording(language: settings.language) else {
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
        recording.flightSessionID = recording.id
        recording.segmentIndex = 1

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
        let hasPending = !store.pendingUploadIDs().isEmpty
        if hasPending {
            mode = .uploading
        } else if audio?.isRecording == true {
            mode = .recording
        } else if mode != .error {
            mode = .standby
        }
        uploadManager.uploadPending(store: store, settings: settings, network: network)
    }

    private func handleAudioWarningChanged(_ warning: Bool) {
        guard let audio else { return }
        guard warning else {
            remoteIPads?.clearAudioSourceWarning()
            log("Audio source restored: \(audio.selectedInputName).")
            return
        }
        let message = "Audio source is \(audio.selectedInputName). Reset USB-C EarPods audio path."
        remoteIPads?.publishAudioSourceWarning(message)
        log(message)
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
