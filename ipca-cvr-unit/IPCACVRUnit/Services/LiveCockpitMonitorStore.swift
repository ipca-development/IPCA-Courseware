import Combine
import CryptoKit
import Foundation

/// Device-side control and best-effort uploader for ephemeral live listening.
///
/// No chunks are persisted for retry. At most one upload is in flight; all
/// contention, stale audio, and network failures drop monitor audio only.
@MainActor
final class LiveCockpitMonitorStore: ObservableObject {
    @Published private(set) var statusText = "Inactive"

    private weak var audio: AudioRecorderManager?
    private weak var settings: SettingsStore?
    private weak var network: NetworkMonitor?
    private weak var workflow: CVRWorkflowStore?
    private var pollTask: Task<Void, Never>?
    private var uploadInFlight = false
    private var lastValidLeaseAt: Date?
    private var activeBroadcastUUID: String?
    private var activeOperationalSessionUUID: String?

    func bind(
        audio: AudioRecorderManager,
        settings: SettingsStore,
        network: NetworkMonitor,
        workflow: CVRWorkflowStore
    ) {
        guard self.audio == nil else { return }
        self.audio = audio
        self.settings = settings
        self.network = network
        self.workflow = workflow
        audio.onLiveMonitorChunkReady = { [weak self] chunk in
            Task { @MainActor in
                await self?.upload(chunk)
            }
        }
        pollTask = Task { [weak self] in
            while !Task.isCancelled {
                await self?.pollLease()
                try? await Task.sleep(for: .seconds(4))
            }
        }
    }

    func appBecameActive() {
        Task { await pollLease() }
    }

    func appEnteredBackground() {
        // Polling and capture continue under the recorder's background audio
        // entitlement. The server lease remains authoritative.
    }

    private func pollLease() async {
        guard let audio, let settings, let network,
              network.isSatisfied,
              let serverURL = settings.normalizedServerURL,
              let credential = settings.deviceCredential else {
            expireLocalLeaseIfNeeded()
            return
        }
        do {
            let response = try await APIClient(serverURL: serverURL)
                .liveCockpitMonitorLease(credential: credential)
            guard response.ok else {
                throw APIClientError.badResponse(response.error ?? "Monitor lease request failed.")
            }
            audio.configureEngineCaptureAllowed(response.captureBackendEnabled == true)
            guard response.captureRequested == true,
                  let broadcastUUID = response.broadcastUUID,
                  let operationalSessionUUID = response.operationalSessionUUID,
                  audio.isRecording,
                  operationalSessionUUID.lowercased() == currentOperationalSessionUUID?.lowercased()
            else {
                deactivate(reason: response.reason == "device_not_enabled" ? "Not enabled" : "Inactive")
                return
            }
            activeBroadcastUUID = broadcastUUID.lowercased()
            activeOperationalSessionUUID = operationalSessionUUID.lowercased()
            lastValidLeaseAt = Date()
            statusText = "Live broadcast active"
            audio.setLiveMonitorCapture(
                active: true,
                broadcastUUID: activeBroadcastUUID,
                operationalSessionUUID: activeOperationalSessionUUID
            )
        } catch {
            statusText = activeBroadcastUUID == nil ? "Inactive" : "Connection interrupted"
            expireLocalLeaseIfNeeded()
        }
    }

    private var currentOperationalSessionUUID: String? {
        workflow?.state.activeOperationalSession?.id
            ?? workflow?.state.activeDispatch?.operationalSessionUUID
    }

    private func expireLocalLeaseIfNeeded() {
        guard let lastValidLeaseAt else {
            deactivate(reason: "Inactive")
            return
        }
        if Date().timeIntervalSince(lastValidLeaseAt) >= 15 {
            deactivate(reason: "Lease expired")
        }
    }

    private func deactivate(reason: String) {
        activeBroadcastUUID = nil
        activeOperationalSessionUUID = nil
        lastValidLeaseAt = nil
        statusText = reason
        audio?.setLiveMonitorCapture(active: false)
    }

    private func upload(_ chunk: LiveCockpitEncodedChunk) async {
        defer { try? FileManager.default.removeItem(at: chunk.fileURL) }
        guard !uploadInFlight,
              chunk.broadcastUUID.lowercased() == activeBroadcastUUID,
              chunk.operationalSessionUUID.lowercased() == activeOperationalSessionUUID,
              audio?.isRecording == true,
              network?.isSatisfied == true,
              let settings,
              let serverURL = settings.normalizedServerURL,
              let credential = settings.deviceCredential,
              Date().timeIntervalSince(chunk.startedAt) < 20
        else {
            return
        }
        uploadInFlight = true
        defer { uploadInFlight = false }
        do {
            let data = try Data(contentsOf: chunk.fileURL, options: .mappedIfSafe)
            guard data.count <= 768_000 else { return }
            let sha = SHA256.hash(data: data).map { String(format: "%02x", $0) }.joined()
            _ = try await APIClient(serverURL: serverURL).uploadLiveCockpitMonitorChunk(
                credential: credential,
                chunk: chunk,
                sha256: sha,
                audioData: data
            )
        } catch {
            // Intentionally no durable retry. Fresh audio resumes on the next chunk.
            statusText = "Connection interrupted"
        }
    }
}
