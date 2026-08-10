import Combine
import CryptoKit
import Foundation

enum CVRWorkflowUploadTrigger: Equatable {
    case routine
    case appForeground
    case networkRestored
    case explicitRetry
    case enrollmentSucceeded

    var permitsAuthenticationProbe: Bool {
        switch self {
        case .appForeground, .networkRestored, .explicitRetry:
            true
        case .routine, .enrollmentSucceeded:
            false
        }
    }
}

@MainActor
final class UploadManager: ObservableObject {
    @Published private(set) var activeUploads: Set<String> = []
    private(set) var activeWorkflowUploadIDs: Set<String> = []
    private let chunkSize = 512 * 1024
    private let maxChunkAttempts = 8
    private var retryTasks: [String: Task<Void, Never>] = [:]
    private weak var networkMonitor: NetworkMonitor?
    private var workflowAuthenticationPausedCredential: String?
    private var workflowAuthenticationProbeInFlight = false
    private var activeWorkflowAuthenticationRequestIDs: Set<String> = []
    private var workflowReconciliationScanInFlight = false
    private var reconcilingWorkflowComponentIDs: Set<String> = []
    private var deferredWorkflowReconciliationIDs: Set<String> = []
    private var activeLiveAudioSegmentKeys: Set<String> = []
    private static let liveAudioUploadedDefaultsKey = "ipca.cvr.liveAudioSegments.uploaded.v1"
    private static let liveAudioPendingDefaultsKey = "ipca.cvr.liveAudioSegments.pending.v1"
    /// Bumped on Log SYNC so a hung prior upload Task cannot overwrite a newer attempt.
    private var workflowUploadEpochs: [String: Int] = [:]

    private struct WorkflowAcceptedMetadata {
        var receiptID: String
        var payloadSHA256: String
        var verifiedAt: Date
        var canonicalIdentifiers: [String: String]
    }

    private struct PendingLiveAudioSegment: Codable {
        var key: String
        var recordingID: String
        var operationalSessionUUID: String
        var flightRecordUUID: String
        var segment: AudioRecordingSegment
        var sha256: String
        var language: String
    }

    private lazy var session: URLSession = {
        let configuration = URLSessionConfiguration.default
        configuration.timeoutIntervalForRequest = 120
        configuration.timeoutIntervalForResource = 6 * 3600
        configuration.waitsForConnectivity = true
        configuration.allowsCellularAccess = true
        return URLSession(configuration: configuration)
    }()

    func configureNetworkMonitor(_ network: NetworkMonitor) {
        networkMonitor = network
    }

    func uploadFinalizedLiveAudioSegments(
        _ segments: [AudioRecordingSegment],
        recordingID: String,
        operationalSessionUUID: String?,
        flightRecordUUID: String?,
        settings: SettingsStore
    ) {
        guard !settings.isSimulationModeEnabled,
              let operationalSessionUUID,
              !operationalSessionUUID.isEmpty,
              let flightRecordUUID,
              !flightRecordUUID.isEmpty else {
            return
        }

        let uploaded = Set(
            UserDefaults.standard.stringArray(forKey: Self.liveAudioUploadedDefaultsKey) ?? []
        )
        for segment in segments.sorted(by: { $0.index < $1.index }) {
            let url = URL(fileURLWithPath: segment.filePath)
            guard FileManager.default.fileExists(atPath: url.path),
                  let data = try? Data(contentsOf: url, options: [.mappedIfSafe]),
                  !data.isEmpty else {
                continue
            }
            let sha256 = SHA256.hash(data: data).map { String(format: "%02x", $0) }.joined()
            let key = "\(recordingID.lowercased()):\(segment.index):\(sha256)"
            guard !uploaded.contains(key) else {
                continue
            }
            persistPendingLiveAudioSegment(PendingLiveAudioSegment(
                key: key,
                recordingID: recordingID,
                operationalSessionUUID: operationalSessionUUID,
                flightRecordUUID: flightRecordUUID,
                segment: segment,
                sha256: sha256,
                language: settings.language
            ))
        }
        retryQueuedLiveAudioSegments(settings: settings)
    }

    func retryQueuedLiveAudioSegments(settings: SettingsStore) {
        guard !settings.isSimulationModeEnabled,
              let networkMonitor,
              networkMonitor.canUpload(allowCellular: settings.allowCellularUpload),
              let serverURL = settings.normalizedServerURL,
              let credential = settings.deviceCredential,
              !credential.isEmpty else {
            return
        }
        let uploaded = Set(
            UserDefaults.standard.stringArray(forKey: Self.liveAudioUploadedDefaultsKey) ?? []
        )
        for pending in pendingLiveAudioSegments() {
            if uploaded.contains(pending.key) {
                removePendingLiveAudioSegment(pending.key)
                continue
            }
            guard !activeLiveAudioSegmentKeys.contains(pending.key) else { continue }
            let url = URL(fileURLWithPath: pending.segment.filePath)
            guard let data = try? Data(contentsOf: url, options: [.mappedIfSafe]),
                  !data.isEmpty,
                  SHA256.hash(data: data).map({ String(format: "%02x", $0) }).joined()
                    == pending.sha256 else {
                continue
            }
            activeLiveAudioSegmentKeys.insert(pending.key)
            Task {
                defer {
                    Task { @MainActor in
                        self.activeLiveAudioSegmentKeys.remove(pending.key)
                    }
                }
                do {
                    let response = try await APIClient(serverURL: serverURL).uploadLiveAudioSegment(
                        credential: credential,
                        recordingID: pending.recordingID,
                        operationalSessionUUID: pending.operationalSessionUUID,
                        flightRecordUUID: pending.flightRecordUUID,
                        segment: pending.segment,
                        sha256: pending.sha256,
                        language: pending.language,
                        audioData: data
                    )
                    guard response.ok else {
                        throw APIClientError.badResponse(
                            response.error ?? "Live audio segment was not accepted."
                        )
                    }
                    self.markLiveAudioSegmentUploaded(pending.key)
                    self.removePendingLiveAudioSegment(pending.key)
                } catch {
                    // The durable queue and immutable local file survive connectivity,
                    // app suspension, and process restart. Recording never blocks.
                }
            }
        }
    }

    private func markLiveAudioSegmentUploaded(_ key: String) {
        var uploaded = Set(
            UserDefaults.standard.stringArray(forKey: Self.liveAudioUploadedDefaultsKey) ?? []
        )
        uploaded.insert(key)
        UserDefaults.standard.set(Array(uploaded.prefix(5_000)), forKey: Self.liveAudioUploadedDefaultsKey)
    }

    private func pendingLiveAudioSegments() -> [PendingLiveAudioSegment] {
        guard let data = UserDefaults.standard.data(forKey: Self.liveAudioPendingDefaultsKey),
              let records = try? JSONDecoder().decode([PendingLiveAudioSegment].self, from: data) else {
            return []
        }
        return records
    }

    private func persistPendingLiveAudioSegment(_ record: PendingLiveAudioSegment) {
        var records = pendingLiveAudioSegments()
        if let index = records.firstIndex(where: { $0.key == record.key }) {
            records[index] = record
        } else {
            records.append(record)
        }
        if let data = try? JSONEncoder().encode(records) {
            UserDefaults.standard.set(data, forKey: Self.liveAudioPendingDefaultsKey)
        }
    }

    private func removePendingLiveAudioSegment(_ key: String) {
        let records = pendingLiveAudioSegments().filter { $0.key != key }
        if let data = try? JSONEncoder().encode(records) {
            UserDefaults.standard.set(data, forKey: Self.liveAudioPendingDefaultsKey)
        }
    }

    func uploadPending(store: RecordingStore, settings: SettingsStore, network: NetworkMonitor) {
        guard !settings.isSimulationModeEnabled else { return }
        guard network.canUpload(allowCellular: settings.allowCellularUpload) else { return }
        for id in store.pendingUploadIDs() {
            upload(recordingID: id, store: store, settings: settings)
        }
    }

    func upload(recordingID: String, store: RecordingStore, settings: SettingsStore) {
        if let networkMonitor,
           !networkMonitor.canUpload(allowCellular: settings.allowCellularUpload) {
            store.update(recordingID) {
                if $0.uploadStatus != .uploaded {
                    $0.uploadStatus = .pending
                    $0.lastError = ""
                }
            }
            return
        }
        guard settings.isServerURLConfigured else {
            store.update(recordingID) {
                $0.uploadStatus = .failed
                $0.lastError = "Server URL is not configured."
                scheduleRetryFields(recording: &$0, reason: $0.lastError)
            }
            scheduleRetry(recordingID: recordingID, store: store, settings: settings)
            return
        }

        guard !activeUploads.contains(recordingID) else { return }
        if let recording = store.recording(id: recordingID),
           recording.uploadStatus == .failed,
           let next = recording.nextUploadRetryAt,
           next > Date() {
            scheduleRetry(recordingID: recordingID, store: store, settings: settings)
            return
        }

        retryTasks[recordingID]?.cancel()
        retryTasks[recordingID] = nil
        activeUploads.insert(recordingID)
        store.update(recordingID) {
            if $0.uploadStatus != .uploaded {
                $0.uploadStatus = .uploading
            }
            if $0.uploadProgress < 0.01 {
                $0.lastError = "Starting automatic upload..."
            }
        }

        Task {
            defer {
                Task { @MainActor in
                    self.activeUploads.remove(recordingID)
                }
            }

            do {
                try await performUpload(recordingID: recordingID, store: store, settings: settings)
            } catch {
                await MainActor.run {
                    if store.recording(id: recordingID)?.uploadStatus == .uploaded {
                        store.update(recordingID) {
                            $0.transcriptStatus = .failed
                            $0.lastError = "Upload is complete. Transcript follow-up failed: \(error.localizedDescription)"
                            $0.uploadRetryCount = nil
                            $0.nextUploadRetryAt = nil
                        }
                    } else {
                        store.update(recordingID) {
                            $0.uploadStatus = .failed
                            scheduleRetryFields(recording: &$0, reason: error.localizedDescription)
                        }
                        self.scheduleRetry(recordingID: recordingID, store: store, settings: settings)
                    }
                }
            }
        }
    }

    func uploadQueuedWorkflowComponents(
        workflow: CVRWorkflowStore,
        settings: SettingsStore,
        trigger: CVRWorkflowUploadTrigger = .routine
    ) {
        guard !settings.isSimulationModeEnabled else {
            if trigger == .explicitRetry {
                for component in workflow.queuedWorkflowComponents() where component.componentType == "dispatch_metadata" {
                    workflow.updateUploadComponent(
                        id: component.id,
                        state: .needsUserAction,
                        progress: component.progress ?? 0,
                        lastError: "Simulation mode is on. Turn it off in Admin, then tap SYNC again."
                    )
                }
            }
            return
        }
        let currentCredential = settings.deviceCredential ?? ""
        if trigger == .enrollmentSucceeded
            || (workflowAuthenticationPausedCredential != nil
                && workflowAuthenticationPausedCredential != currentCredential) {
            clearWorkflowAuthenticationPause()
        }
        if trigger != .routine {
            deferredWorkflowReconciliationIDs = []
            // Explicit SYNC must not stay parked on auth-pause forever.
            if trigger == .explicitRetry {
                clearWorkflowAuthenticationPause()
            }
        }
        let workflowSyncAuthenticationPaused = workflowAuthenticationPausedCredential == currentCredential
        if let networkMonitor,
           !networkMonitor.canUpload(allowCellular: settings.allowCellularUpload) {
            if trigger == .explicitRetry {
                for component in workflow.queuedWorkflowComponents() where component.componentType == "dispatch_metadata" {
                    workflow.updateUploadComponent(
                        id: component.id,
                        state: .needsUserAction,
                        progress: component.progress ?? 0,
                        lastError: settings.allowCellularUpload
                            ? "No network path is available for upload. Reconnect Wi‑Fi/cellular, then tap SYNC."
                            : "Cellular upload is disabled and Wi‑Fi is unavailable. Enable cellular upload in Admin or join Wi‑Fi, then tap SYNC."
                    )
                }
            }
            return
        }
        let supportedTypes = Set([
            "schedule_duty_sync", "dispatch_metadata", "garmin_csv", "flight_events",
            "recorder_verification", "flight_record_closure", "operational_leg_review"
        ])
        let components = workflow.queuedWorkflowComponents().filter {
            supportedTypes.contains($0.componentType)
        }
        let scheduleBlockedSchedulerIDs = Set(workflow.state.uploadComponents.compactMap { component -> String? in
            guard component.componentType == "schedule_duty_sync",
                  component.state != .serverVerified,
                  component.state != .uploaded,
                  component.state != .superseded,
                  let data = component.requestPayloadSnapshot,
                  let payload = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
                  let schedulerRecordID = payload["scheduler_record_id"] as? String else {
                return nil
            }
            return schedulerRecordID.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        })
        if trigger == .explicitRetry {
            // A hung prior Task can leave IDs in activeUploads while Log force-retry
            // already reset those components to queued — SYNC then looks like a no-op.
            for component in components {
                workflowUploadEpochs[component.id, default: 0] += 1
                activeUploads.remove(component.id)
                activeWorkflowUploadIDs.remove(component.id)
                activeWorkflowAuthenticationRequestIDs.remove(component.id)
            }
        }
        guard !components.isEmpty else {
            if trigger == .explicitRetry {
                workflow.reportSynchronizationMessage(
                    "No queued Dispatch uploads were found to retry. Open Log again after a moment, or export History for support."
                )
            }
            return
        }
        var rescanForClosureReconciliation = false
        guard let baseURL = settings.normalizedServerURL else {
            for component in components where component.componentType == "dispatch_metadata" {
                workflow.updateUploadComponent(
                    id: component.id,
                    state: .needsUserAction,
                    progress: component.progress ?? 0,
                    lastError: "Server URL is not configured. Open Admin, configure the server, then retry Dispatch upload."
                )
            }
            return
        }

        let allReconciliationComponents = workflow.workflowComponentsRequiringReconciliation(
            explicitRetry: trigger == .explicitRetry
        )
        let reconciliationBlockedIDs = Set(allReconciliationComponents.map(\.id))
        let reconciliationComponents = allReconciliationComponents.filter {
            !deferredWorkflowReconciliationIDs.contains($0.id)
        }
        let reconciliationIsAuthenticationProbe = workflowSyncAuthenticationPaused
            && trigger.permitsAuthenticationProbe
            && !workflowAuthenticationProbeInFlight
            && activeWorkflowAuthenticationRequestIDs.isEmpty
        let mayStartReconciliation = !workflowSyncAuthenticationPaused
            || reconciliationIsAuthenticationProbe
        if !reconciliationComponents.isEmpty,
           !workflowReconciliationScanInFlight,
           mayStartReconciliation {
            workflowReconciliationScanInFlight = true
            reconcilingWorkflowComponentIDs = Set(reconciliationComponents.map(\.id))
            if reconciliationIsAuthenticationProbe {
                workflowAuthenticationProbeInFlight = true
            }
            Task {
                defer {
                    Task { @MainActor in
                        self.workflowReconciliationScanInFlight = false
                        self.reconcilingWorkflowComponentIDs = []
                        if reconciliationIsAuthenticationProbe {
                            self.workflowAuthenticationProbeInFlight = false
                        }
                        self.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                    }
                }
                await self.reconcileWorkflowComponents(
                    reconciliationComponents,
                    workflow: workflow,
                    settings: settings,
                    baseURL: baseURL,
                    currentCredential: currentCredential
                )
            }
        }

        for component in components {
            let usesWorkflowAuthentication = component.componentType != "garmin_csv"
            // Reconciliation-first: uncertain or reconciliation-required components never
            // take the normal POST path until NOT_FOUND clears that requirement.
            if reconciliationBlockedIDs.contains(component.id) {
                continue
            }
            if reconcilingWorkflowComponentIDs.contains(component.id)
                || (workflowReconciliationScanInFlight
                    && workflowSyncAuthenticationPaused
                    && usesWorkflowAuthentication) {
                continue
            }
            if workflowSyncAuthenticationPaused && usesWorkflowAuthentication {
                guard trigger.permitsAuthenticationProbe,
                      !workflowAuthenticationProbeInFlight,
                      activeWorkflowAuthenticationRequestIDs.isEmpty else {
                    continue
                }
            }
            if component.componentType == "schedule_duty_sync" {
                uploadQueuedScheduleDutyComponent(
                    component,
                    workflow: workflow,
                    settings: settings,
                    baseURL: baseURL,
                    credential: currentCredential
                )
                continue
            }
            if component.componentType == "operational_leg_review" {
                uploadQueuedOperationalLegReviewComponent(
                    component,
                    workflow: workflow,
                    settings: settings,
                    baseURL: baseURL,
                    credential: currentCredential
                )
                continue
            }
            guard let context = workflow.workflowUploadContext(componentID: component.id) else {
                if trigger == .explicitRetry {
                    workflow.updateUploadComponent(
                        id: component.id,
                        state: .needsUserAction,
                        progress: component.progress ?? 0,
                        lastError: "Local Dispatch evidence for this flight could not be loaded. Export History for support."
                    )
                }
                continue
            }
            if component.componentType == "dispatch_metadata",
               let schedulerRecordID = context.dispatch.schedulerRecordID?
                    .trimmingCharacters(in: .whitespacesAndNewlines)
                    .lowercased(),
               scheduleBlockedSchedulerIDs.contains(schedulerRecordID) {
                // A material reservation edit or schedule-window update must be
                // acknowledged first; otherwise Dispatch can race a UUID that
                // the scheduler has not created yet. Schedule completion invokes
                // this uploader again and releases the queued Dispatch.
                continue
            }
            if component.componentType == "dispatch_metadata",
               workflow.scheduleDutyReplacementIsPending(
                   schedulerRecordID: context.dispatch.schedulerRecordID
               ) {
                // Preserve causal order after offline edits: the replacement
                // scheduler row must exist before its Dispatch claims it.
                continue
            }
            if component.componentType == "flight_record_closure",
               !workflow.flightClosureIsComplete(context.flightRecord, dispatch: context.dispatch) {
                // Restored/admin closures may already exist under a different component_uuid.
                // Reconcile against the flight-scoped server closure before demanding meters.
                if component.reconciliationRequired == false {
                    workflow.updateUploadComponent(
                        id: component.id,
                        state: .needsUserAction,
                        progress: component.progress ?? 0,
                        lastError: "Ending Hobbs and Ending Tacho are required before closure upload."
                    )
                } else if component.reconciliationRequired != true {
                    _ = workflow.markReconciliationRequired(
                        id: component.id,
                        message: "Checking whether Flight Closure already exists on the server..."
                    )
                    rescanForClosureReconciliation = true
                }
                continue
            }
            if component.componentType != "dispatch_metadata",
               component.componentType != "garmin_csv",
               context.dispatch.serverDispatchID == nil {
                continue
            }
            guard !activeUploads.contains(component.id) else { continue }
            let isAuthenticationProbe = workflowSyncAuthenticationPaused && usesWorkflowAuthentication
            if isAuthenticationProbe {
                workflowAuthenticationProbeInFlight = true
            }
            let uploadEpoch = workflowUploadEpochs[component.id] ?? 0
            activeUploads.insert(component.id)
            activeWorkflowUploadIDs.insert(component.id)
            if usesWorkflowAuthentication {
                activeWorkflowAuthenticationRequestIDs.insert(component.id)
            }
            let componentLabel: String = switch component.componentType {
            case "dispatch_metadata": "Dispatch"
            case "garmin_csv": "Garmin CSV"
            case "flight_events": "Flight Event"
            case "recorder_verification": "Recorder Verification"
            default: "Flight Closure"
            }
            workflow.updateUploadComponent(
                id: component.id,
                state: .uploading,
                progress: max(component.progress ?? 0.01, 0.01),
                lastError: "Starting \(componentLabel) upload..."
            )

            Task {
                var triggerReconciliationAfterCompletion = false
                defer {
                    if workflowUploadEpochs[component.id] == uploadEpoch {
                        activeWorkflowUploadIDs.remove(component.id)
                        activeUploads.remove(component.id)
                        activeWorkflowAuthenticationRequestIDs.remove(component.id)
                        if isAuthenticationProbe {
                            workflowAuthenticationProbeInFlight = false
                        }
                        if triggerReconciliationAfterCompletion {
                            workflow.recoverOrphanedUploads(
                                activeComponentIDs: activeWorkflowUploadIDs
                            )
                            uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                        }
                    }
                }
                do {
                    if component.componentType == "dispatch_metadata" {
                        let result = try await uploadWorkflowDispatchComponent(
                            component: component,
                            context: context,
                            settings: settings,
                            baseURL: baseURL,
                            workflow: workflow
                        )
                        guard workflowUploadEpochs[component.id] == uploadEpoch else { return }
                        clearWorkflowAuthenticationPause()
                        guard workflowUploadEpochs[component.id] == uploadEpoch else { return }
                        let persisted = workflow.persistReconciliationMatch(
                            componentID: component.id,
                            serverReceiptID: result.receiptID,
                            authoritativePayloadSHA256: result.payloadSHA256,
                            serverVerificationAt: result.verifiedAt,
                            canonicalIdentifiers: result.canonicalIdentifiers
                        )
                        guard workflowUploadEpochs[component.id] == uploadEpoch else { return }
                        guard persisted else {
                            triggerReconciliationAfterCompletion = true
                            _ = workflow.markReconciliationRequired(
                                id: component.id,
                                message: "Server accepted Dispatch; local receipt persistence will reconcile automatically."
                            )
                            return
                        }
                        uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                        return
                    } else if component.componentType == "garmin_csv" {
                        let serverReceiptID = try await uploadWorkflowGarminComponent(
                            component: component,
                            flightRecordID: context.flightRecord.id,
                            baseURL: baseURL,
                            workflow: workflow
                        )
                        guard workflowUploadEpochs[component.id] == uploadEpoch else { return }
                        workflow.updateUploadComponent(
                            id: component.id,
                            state: .serverVerified,
                            progress: 1,
                            lastError: "",
                            serverReceiptID: serverReceiptID
                        )
                    } else {
                        let result = try await uploadWorkflowEvidenceComponent(
                            component: component,
                            context: context,
                            settings: settings,
                            baseURL: baseURL,
                            workflow: workflow
                        )
                        guard workflowUploadEpochs[component.id] == uploadEpoch else { return }
                        clearWorkflowAuthenticationPause()
                        let persisted = workflow.persistVerifiedWorkflowComponent(
                            componentID: component.id,
                            serverReceiptID: result.receiptID,
                            authoritativePayloadSHA256: result.payloadSHA256,
                            serverVerificationAt: result.verifiedAt,
                            canonicalIdentifiers: result.canonicalIdentifiers
                        )
                        guard workflowUploadEpochs[component.id] == uploadEpoch else { return }
                        if !persisted {
                            triggerReconciliationAfterCompletion = true
                            _ = workflow.markReconciliationRequired(
                                id: component.id,
                                message: "Server accepted workflow evidence; local metadata persistence will reconcile automatically."
                            )
                        }
                    }
                } catch {
                    guard workflowUploadEpochs[component.id] == uploadEpoch else { return }
                    if component.componentType == "garmin_csv" {
                        workflow.updateUploadComponent(
                            id: component.id,
                            state: .failed,
                            progress: component.progress ?? 0,
                            lastError: error.localizedDescription
                        )
                        return
                    }
                    if case APIClientError.synchronization(let failure) = error,
                       failure.errorCode == "DUPLICATE_ALREADY_VERIFIED" {
                        triggerReconciliationAfterCompletion = true
                        clearWorkflowAuthenticationPause()
                        _ = workflow.markReconciliationRequired(
                            id: component.id,
                            message: "Server reports an existing immutable item; authoritative metadata reconciliation is required."
                        )
                        return
                    }
                    if Self.isUncertainTransportFailure(error)
                        || error.localizedDescription.contains("authoritative verification metadata") {
                        triggerReconciliationAfterCompletion = true
                        _ = workflow.markReconciliationRequired(
                            id: component.id,
                            message: "The request outcome is uncertain; reconciliation will run before another upload."
                        )
                    }
                    guard workflowUploadEpochs[component.id] == uploadEpoch else { return }
                    let outcome = workflow.recordWorkflowUploadFailure(
                        id: component.id,
                        progress: component.progress ?? 0,
                        error: error
                    )
                    if outcome == .authenticationPaused {
                        workflowAuthenticationPausedCredential = currentCredential
                    }
                }
            }
        }

        if rescanForClosureReconciliation, !workflowReconciliationScanInFlight {
            Task { @MainActor in
                self.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings, trigger: trigger)
            }
        }
    }

    private func uploadQueuedScheduleDutyComponent(
        _ component: CVRUploadComponentRecord,
        workflow: CVRWorkflowStore,
        settings: SettingsStore,
        baseURL: URL,
        credential: String
    ) {
        guard !activeUploads.contains(component.id) else { return }
        guard !credential.isEmpty else {
            workflow.updateUploadComponent(
                id: component.id,
                state: .queued,
                progress: component.progress ?? 0,
                lastError: "Schedule update is queued until this CVR Unit is enrolled."
            )
            return
        }
        guard let snapshot = component.requestPayloadSnapshot,
              let payload = try? JSONSerialization.jsonObject(with: snapshot) as? [String: Any] else {
            workflow.updateUploadComponent(
                id: component.id,
                state: .needsUserAction,
                progress: component.progress ?? 0,
                lastError: "The queued reservation payload is unavailable."
            )
            return
        }
        activeUploads.insert(component.id)
        activeWorkflowUploadIDs.insert(component.id)
        activeWorkflowAuthenticationRequestIDs.insert(component.id)
        workflow.updateUploadComponent(
            id: component.id,
            state: .uploading,
            progress: 0.1,
            lastError: "Synchronizing reservation..."
        )
        Task {
            defer {
                activeUploads.remove(component.id)
                activeWorkflowUploadIDs.remove(component.id)
                activeWorkflowAuthenticationRequestIDs.remove(component.id)
            }
            do {
                let response = try await APIClient(serverURL: baseURL).syncScheduleDuty(
                    payload: payload,
                    credential: credential
                )
                guard response.ok else {
                    throw APIClientError.badResponse(response.error ?? "Schedule reservation was not accepted.")
                }
                clearWorkflowAuthenticationPause()
                workflow.updateUploadComponent(
                    id: component.id,
                    state: .serverVerified,
                    progress: 1,
                    lastError: (response.warnings ?? []).joined(separator: " "),
                    serverReceiptID: response.schedulerRecordID,
                    requestID: response.requestID
                )
                uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
            } catch {
                let outcome = workflow.recordWorkflowUploadFailure(
                    id: component.id,
                    progress: component.progress ?? 0,
                    error: error
                )
                if outcome == .authenticationPaused {
                    workflowAuthenticationPausedCredential = credential
                }
            }
        }
        _ = settings
    }

    private func uploadQueuedOperationalLegReviewComponent(
        _ component: CVRUploadComponentRecord,
        workflow: CVRWorkflowStore,
        settings: SettingsStore,
        baseURL: URL,
        credential: String
    ) {
        guard !activeUploads.contains(component.id) else { return }
        guard !credential.isEmpty else {
            workflow.updateUploadComponent(
                id: component.id,
                state: .queued,
                progress: component.progress ?? 0,
                lastError: "Legs are verified locally · server synchronization waits for enrollment."
            )
            return
        }
        if workflow.closureSynchronizationPending(for: component.flightRecordID) {
            workflow.updateUploadComponent(
                id: component.id,
                state: .queued,
                progress: component.progress ?? 0,
                lastError: "Legs are verified locally · waiting to synchronize Check-In first."
            )
            return
        }
        guard let snapshot = component.requestPayloadSnapshot,
              let payload = try? JSONSerialization.jsonObject(with: snapshot) as? [String: Any] else {
            workflow.updateUploadComponent(
                id: component.id,
                state: .needsUserAction,
                progress: component.progress ?? 0,
                lastError: "The locally verified leg revision could not be loaded."
            )
            return
        }

        activeUploads.insert(component.id)
        activeWorkflowUploadIDs.insert(component.id)
        activeWorkflowAuthenticationRequestIDs.insert(component.id)
        workflow.updateUploadComponent(
            id: component.id,
            state: .uploading,
            progress: 0.1,
            lastError: "Synchronizing locally verified legs..."
        )
        Task {
            defer {
                activeUploads.remove(component.id)
                activeWorkflowUploadIDs.remove(component.id)
                activeWorkflowAuthenticationRequestIDs.remove(component.id)
            }
            do {
                let response = try await APIClient(serverURL: baseURL).acceptOperationalLegReview(
                    payload: payload,
                    credential: credential
                )
                clearWorkflowAuthenticationPause()
                workflow.updateUploadComponent(
                    id: component.id,
                    state: .serverVerified,
                    progress: 1,
                    lastError: "",
                    serverReceiptID: response.revisionUUID
                )
            } catch {
                let message = error.localizedDescription.lowercased()
                if message.contains("check-in must be synchronized")
                    || message.contains("completed check-in")
                    || message.contains("flight closure") {
                    workflow.updateUploadComponent(
                        id: component.id,
                        state: .queued,
                        progress: 0,
                        lastError: "Legs are verified locally · server synchronization waits for Check-In.",
                        errorCode: "DEPENDENCY_PENDING",
                        retryable: true
                    )
                } else {
                    let outcome = workflow.recordWorkflowUploadFailure(
                        id: component.id,
                        progress: component.progress ?? 0,
                        error: error
                    )
                    if outcome == .authenticationPaused {
                        workflowAuthenticationPausedCredential = credential
                    }
                }
            }
        }
        _ = settings
    }

    func retryWorkflowSynchronization(workflow: CVRWorkflowStore, settings: SettingsStore) {
        uploadQueuedWorkflowComponents(workflow: workflow, settings: settings, trigger: .explicitRetry)
    }

    private func clearWorkflowAuthenticationPause() {
        workflowAuthenticationPausedCredential = nil
        workflowAuthenticationProbeInFlight = false
    }

    private func reconcileWorkflowComponents(
        _ components: [CVRUploadComponentRecord],
        workflow: CVRWorkflowStore,
        settings: SettingsStore,
        baseURL: URL,
        currentCredential: String
    ) async {
        guard !currentCredential.isEmpty else {
            workflowAuthenticationPausedCredential = currentCredential
            for component in components {
                workflow.applyReconciliationDisposition(
                    componentID: component.id,
                    state: .queued,
                    message: "CVR Unit enrollment is required before workflow reconciliation.",
                    errorCode: "AUTHENTICATION_REQUIRED",
                    retryable: true,
                    reconciliationRequired: true
                )
            }
            return
        }

        var requestItems: [WorkflowReconciliationRequestItem] = []
        for component in components {
            guard let context = workflow.workflowUploadContext(componentID: component.id) else {
                deferredWorkflowReconciliationIDs.insert(component.id)
                workflow.applyReconciliationDisposition(
                    componentID: component.id,
                    state: .queued,
                    message: "Local workflow evidence is temporarily unavailable for reconciliation.",
                    errorCode: "TEMPORARY_TECHNICAL_FAILURE",
                    retryable: true,
                    reconciliationRequired: true
                )
                continue
            }
            do {
                let snapshot: Data
                if let preserved = component.requestPayloadSnapshot {
                    snapshot = preserved
                } else {
                    let payload = component.componentType == "dispatch_metadata"
                        ? try workflowDispatchPayload(
                            dispatch: context.dispatch,
                            flightRecord: context.flightRecord,
                            consents: context.consents.filter {
                                $0.dispatchID == context.dispatch.id
                                    && $0.dispatchVersion == context.dispatch.version
                            },
                            settings: settings
                        )
                        : try workflowEvidencePayload(component: component, context: context, settings: settings)
                    snapshot = try JSONSerialization.data(withJSONObject: payload, options: [.sortedKeys])
                    guard snapshot.count <= CVRWorkflowStore.maximumRequestPayloadSnapshotBytes else {
                        throw APIClientError.badResponse(
                            "Workflow request payload snapshot exceeds the \(CVRWorkflowStore.maximumRequestPayloadSnapshotBytes)-byte limit."
                        )
                    }
                    guard workflow.persistRequestPayloadSnapshot(
                        componentID: component.id,
                        payload: snapshot,
                        reconciliationRequired: true
                    ) else {
                        throw APIClientError.badResponse("Workflow request payload snapshot could not be persisted.")
                    }
                }
                let payload = try JSONDecoder().decode([String: APIJSONValue].self, from: snapshot)
                requestItems.append(WorkflowReconciliationRequestItem(
                    itemID: component.id,
                    componentType: component.componentType,
                    dispatchUUID: context.dispatch.id.lowercased(),
                    dispatchVersion: component.componentType == "dispatch_metadata"
                        ? context.dispatch.version : nil,
                    flightRecordUUID: context.flightRecord.id.lowercased(),
                    componentUUID: component.componentType == "dispatch_metadata"
                        ? nil : component.id.lowercased(),
                    payload: payload
                ))
            } catch {
                deferredWorkflowReconciliationIDs.insert(component.id)
                workflow.applyReconciliationDisposition(
                    componentID: component.id,
                    state: .queued,
                    message: "Could not prepare preserved workflow evidence for reconciliation: \(error.localizedDescription)",
                    errorCode: "TEMPORARY_TECHNICAL_FAILURE",
                    retryable: true,
                    reconciliationRequired: true
                )
            }
        }

        let client = APIClient(serverURL: baseURL)
        for start in stride(from: 0, to: requestItems.count, by: 25) {
            let end = min(start + 25, requestItems.count)
            let batch = Array(requestItems[start..<end])
            do {
                let response = try await client.reconcileWorkflowSync(
                    request: WorkflowReconciliationRequest(items: batch),
                    credential: currentCredential
                )
                if !response.ok {
                    if response.errorCode == "AUTHENTICATION_REQUIRED" {
                        workflowAuthenticationPausedCredential = currentCredential
                    }
                    for item in batch {
                        deferredWorkflowReconciliationIDs.insert(item.itemID)
                        workflow.applyReconciliationDisposition(
                            componentID: item.itemID,
                            state: .queued,
                            message: response.error ?? "Workflow reconciliation is temporarily unavailable.",
                            errorCode: response.errorCode ?? "TEMPORARY_TECHNICAL_FAILURE",
                            retryable: true,
                            reconciliationRequired: true
                        )
                    }
                    if response.errorCode == "AUTHENTICATION_REQUIRED" { return }
                    continue
                }
                clearWorkflowAuthenticationPause()

                for item in batch {
                    guard let result = response.results.first(where: { $0.itemID == item.itemID }) else {
                        deferredWorkflowReconciliationIDs.insert(item.itemID)
                        workflow.applyReconciliationDisposition(
                            componentID: item.itemID,
                            state: .queued,
                            message: "The reconciliation response omitted this item; it will retry automatically.",
                            errorCode: "TEMPORARY_TECHNICAL_FAILURE",
                            retryable: true,
                            reconciliationRequired: true
                        )
                        continue
                    }
                    applyReconciliationResult(result, workflow: workflow)
                    if result.status == .authenticationRequired {
                        workflowAuthenticationPausedCredential = currentCredential
                    }
                }
                if response.results.contains(where: { $0.status == .authenticationRequired }) {
                    return
                }
            } catch {
                let outcome = CVRWorkflowStore.classifyReconciliationEndpointFailure(error)
                if outcome.authenticationRequired {
                    workflowAuthenticationPausedCredential = currentCredential
                }
                for item in batch {
                    deferredWorkflowReconciliationIDs.insert(item.itemID)
                    workflow.applyReconciliationDisposition(
                        componentID: item.itemID,
                        state: .queued,
                        message: outcome.message,
                        errorCode: outcome.errorCode,
                        retryable: true,
                        reconciliationRequired: true
                    )
                }
                if outcome.authenticationRequired { return }
            }
        }
    }

    private func applyReconciliationResult(
        _ result: WorkflowReconciliationResult,
        workflow: CVRWorkflowStore
    ) {
        switch result.status {
        case .verifiedMatch:
            guard let receiptID = result.receiptID,
                  let payloadSHA256 = result.payloadSHA256,
                  let receivedAt = Self.parseServerDate(result.receivedAt),
                  let canonicalValues = result.canonicalIdentifiers else {
                deferredWorkflowReconciliationIDs.insert(result.itemID)
                workflow.applyReconciliationDisposition(
                    componentID: result.itemID,
                    state: .queued,
                    message: "Verified reconciliation result omitted required authoritative metadata.",
                    errorCode: "TEMPORARY_TECHNICAL_FAILURE",
                    retryable: true,
                    reconciliationRequired: true
                )
                return
            }
            let persisted = workflow.persistReconciliationMatch(
                componentID: result.itemID,
                serverReceiptID: receiptID,
                authoritativePayloadSHA256: payloadSHA256,
                serverVerificationAt: receivedAt,
                canonicalIdentifiers: canonicalValues.compactMapValues(\.stringValue)
            )
            if !persisted {
                deferredWorkflowReconciliationIDs.insert(result.itemID)
                _ = workflow.markReconciliationRequired(
                    id: result.itemID,
                    message: "Authoritative reconciliation matched, but local persistence will retry automatically."
                )
            }
        case .notFound:
            workflow.applyReconciliationDisposition(
                componentID: result.itemID,
                state: .queued,
                message: "",
                errorCode: "NOT_FOUND",
                retryable: true,
                reconciliationRequired: false
            )
        case .immutableConflict:
            workflow.applyReconciliationDisposition(
                componentID: result.itemID,
                state: .failed,
                message: result.error ?? "Immutable server evidence conflicts with the preserved local payload. Technical review is required.",
                errorCode: "IMMUTABLE_CONFLICT",
                retryable: false,
                reconciliationRequired: false
            )
        case .userCorrectionRequired:
            workflow.applyReconciliationDisposition(
                componentID: result.itemID,
                state: .needsUserAction,
                message: result.error ?? "Operational values must be corrected before this component can synchronize.",
                errorCode: "USER_CORRECTION_REQUIRED",
                retryable: false,
                reconciliationRequired: false
            )
        case .dependencyNotReady, .temporaryTechnicalFailure:
            deferredWorkflowReconciliationIDs.insert(result.itemID)
            workflow.applyReconciliationDisposition(
                componentID: result.itemID,
                state: .queued,
                message: result.error ?? "Workflow reconciliation will retry automatically.",
                errorCode: result.status.rawValue,
                retryable: true,
                reconciliationRequired: true
            )
        case .authenticationRequired:
            deferredWorkflowReconciliationIDs.insert(result.itemID)
            workflow.applyReconciliationDisposition(
                componentID: result.itemID,
                state: .queued,
                message: result.error ?? "Workflow authentication is required.",
                errorCode: "AUTHENTICATION_REQUIRED",
                retryable: true,
                reconciliationRequired: true
            )
        }
    }

    private func preservedWorkflowPayload(
        component: CVRUploadComponentRecord,
        generatedPayload: [String: Any],
        workflow: CVRWorkflowStore
    ) throws -> [String: Any] {
        if let snapshot = component.requestPayloadSnapshot {
            guard let payload = try JSONSerialization.jsonObject(with: snapshot) as? [String: Any] else {
                throw APIClientError.invalidJSON("Preserved workflow request payload is not a JSON object.")
            }
            return payload
        }
        let snapshot = try JSONSerialization.data(withJSONObject: generatedPayload, options: [.sortedKeys])
        guard snapshot.count <= CVRWorkflowStore.maximumRequestPayloadSnapshotBytes else {
            throw APIClientError.badResponse(
                "Workflow request payload snapshot exceeds the \(CVRWorkflowStore.maximumRequestPayloadSnapshotBytes)-byte limit."
            )
        }
        guard workflow.persistRequestPayloadSnapshot(componentID: component.id, payload: snapshot) else {
            throw APIClientError.badResponse("Workflow request payload snapshot could not be durably persisted.")
        }
        return generatedPayload
    }

    private static func parseServerDate(_ value: String?) -> Date? {
        guard let value, !value.isEmpty else { return nil }
        let fractional = ISO8601DateFormatter()
        fractional.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        if let date = fractional.date(from: value) ?? ISO8601DateFormatter().date(from: value) {
            return date
        }
        let databaseTimestamp = DateFormatter()
        databaseTimestamp.calendar = Calendar(identifier: .gregorian)
        databaseTimestamp.locale = Locale(identifier: "en_US_POSIX")
        databaseTimestamp.timeZone = TimeZone(secondsFromGMT: 0)
        databaseTimestamp.dateFormat = value.contains(".")
            ? "yyyy-MM-dd HH:mm:ss.SSS"
            : "yyyy-MM-dd HH:mm:ss"
        return databaseTimestamp.date(from: value)
    }

    private static func isUncertainTransportFailure(_ error: Error) -> Bool {
        guard let urlError = error as? URLError else { return false }
        return [.timedOut, .networkConnectionLost, .cannotConnectToHost].contains(urlError.code)
    }

    private func scheduleRetryFields(recording: inout Recording, reason: String) {
        let nextCount = (recording.uploadRetryCount ?? 0) + 1
        let delay = min(120, max(30, 30 * (1 << min(nextCount - 1, 2))))
        recording.uploadRetryCount = nextCount
        recording.nextUploadRetryAt = Date().addingTimeInterval(TimeInterval(delay))
        recording.lastError = "\(reason) Retrying in \(delay)s."
    }

    private func uploadWorkflowDispatchComponent(
        component: CVRUploadComponentRecord,
        context: (
            dispatch: CVRDispatchRecord,
            flightRecord: CVRIncompleteFlightRecord,
            consents: [CVRConsentRecord],
            events: [CVRFlightEventRecord],
            verifications: [CVRRecorderVerificationRecord]
        ),
        settings: SettingsStore,
        baseURL: URL,
        workflow: CVRWorkflowStore
    ) async throws -> WorkflowAcceptedMetadata {
        guard let credential = settings.deviceCredential, !credential.isEmpty else {
            throw APIClientError.badResponse("CVR Unit is not enrolled. Generate an enrollment code in IPCA.training and enter it in Admin.")
        }
        let initialDispatch = context.dispatch
        let initialFlightRecord = context.flightRecord
        guard component.flightRecordID == initialFlightRecord.id,
              initialDispatch.id == initialFlightRecord.dispatchID else {
            throw APIClientError.badResponse("Dispatch upload data is no longer linked to the active Flight Record.")
        }

        _ = workflow.repairDispatchAircraftAlignment(selectedAircraft: settings.selectedAircraft)
        guard let refreshedContext = workflow.workflowUploadContext(componentID: component.id) else {
            throw APIClientError.badResponse("Dispatch upload context is unavailable after repair.")
        }
        let dispatch = refreshedContext.dispatch
        let flightRecord = refreshedContext.flightRecord

        workflow.updateUploadComponent(
            id: component.id,
            state: .uploading,
            progress: 0.25,
            lastError: "Sending Dispatch metadata..."
        )
        let generatedPayload = try workflowDispatchPayload(
            dispatch: dispatch,
            flightRecord: flightRecord,
            consents: refreshedContext.consents.filter {
                $0.dispatchID == dispatch.id && $0.dispatchVersion == dispatch.version
            },
            settings: settings
        )
        let payload = try preservedWorkflowPayload(
            component: component,
            generatedPayload: generatedPayload,
            workflow: workflow
        )
        let response = try await APIClient(serverURL: baseURL).syncDispatch(payload: payload, credential: credential)
        guard response.ok,
              let receiptID = response.receipt?.receiptID,
              !receiptID.isEmpty,
              let payloadSHA256 = response.receipt?.payloadSHA256,
              !payloadSHA256.isEmpty,
              let verifiedAt = Self.parseServerDate(response.receipt?.serverVerifiedAt),
              let serverDispatch = response.dispatch else {
            throw APIClientError.badResponse(
                response.error ?? "Server accepted Dispatch without complete authoritative verification metadata."
            )
        }
        return WorkflowAcceptedMetadata(
            receiptID: receiptID,
            payloadSHA256: payloadSHA256,
            verifiedAt: verifiedAt,
            canonicalIdentifiers: [
                "server_dispatch_id": String(serverDispatch.id),
                "dispatch_uuid": serverDispatch.dispatchUUID,
                "dispatch_version": String(serverDispatch.dispatchVersion),
                "flight_record_uuid": serverDispatch.flightRecordUUID
            ]
        )
    }

    private func workflowDispatchPayload(
        dispatch: CVRDispatchRecord,
        flightRecord: CVRIncompleteFlightRecord,
        consents: [CVRConsentRecord],
        settings: SettingsStore
    ) throws -> [String: Any] {
        let iso = ISO8601DateFormatter()
        iso.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        let day = DateFormatter()
        day.calendar = Calendar(identifier: .gregorian)
        day.locale = Locale(identifier: "en_US_POSIX")
        day.timeZone = TimeZone.current
        day.dateFormat = "yyyy-MM-dd"

        let uploadTail = settings.selectedAircraft?.registration
            .trimmingCharacters(in: .whitespacesAndNewlines)
            .uppercased()
            ?? dispatch.tailNumber.uppercased()
        let uploadAircraftID = settings.selectedAircraft?.id ?? dispatch.aircraftID
        let operationalConfig = settings.selectedAircraft?.operationalConfig ?? .safeDefaults
        let plannedDeparture = CVROperationalIdentityLocal.normalizeAirport(dispatch.plannedDepartureAirport)
        let plannedDestination = CVROperationalIdentityLocal.normalizeAirport(dispatch.plannedDestinationAirport)
        let isOperationalSession = dispatch.operationalSessionModelVersion
            == CVROperationalSessionRecord.modelVersion
        guard isOperationalSession || (!plannedDeparture.isEmpty && !plannedDestination.isEmpty) else {
            throw APIClientError.badResponse(
                "Departure and destination airports are required before Dispatch can synchronize."
            )
        }

        var dispatchPayload: [String: Any] = [
            "id": dispatch.id.lowercased(),
            "organization_id": dispatch.organizationID,
            "scheduled_date": day.string(from: dispatch.scheduledDate),
            "tail_number": uploadTail,
            "mission_code": dispatch.missionCode,
            "planned_departure_airport": plannedDeparture,
            "planned_destination_airport": plannedDestination,
            "crew": dispatch.crew.map { assignment in
                var member: [String: Any] = [
                    "id": assignment.id,
                    "person_name": assignment.personName,
                    "role": assignment.role.rawValue,
                    "pilot_function": assignment.effectivePilotFunction.rawValue,
                    "is_pic": assignment.hasPICResponsibility,
                    "is_primary_customer": assignment.role == .student
                        && assignment.effectivePilotFunction == .pilotFlying
                ]
                if let personID = assignment.personID {
                    member["person_id"] = personID
                }
                return member
            },
            "fuel_onboard": dispatch.fuelOnboard,
            "fuel_unit": operationalConfig.fuelUnit,
            "fuel_capacity": operationalConfig.fuelCapacity,
            "dispatch_source": dispatch.dispatchSource,
            "creator_identity": dispatch.creatorIdentity,
            "created_at": iso.string(from: dispatch.createdAt),
            "modified_at": iso.string(from: dispatch.modifiedAt),
            "version": dispatch.version,
            "consent_status": isOperationalSession ? "not_required" : dispatch.consentStatus,
            "status": dispatch.status.rawValue,
            "configured_cvr_unit_id": dispatch.configuredCVRUnitID,
            "configured_beacon_id": dispatch.configuredBeaconID
        ]
        if let aircraftID = uploadAircraftID {
            dispatchPayload["aircraft_id"] = aircraftID
        }
        if let startingHobbs = dispatch.startingHobbs {
            dispatchPayload["starting_hobbs"] = startingHobbs
        }
        if let startingTacho = dispatch.startingTacho {
            dispatchPayload["starting_tacho"] = startingTacho
        }
        if let oilPercentage = dispatch.oilPercentage {
            dispatchPayload["oil_percentage"] = oilPercentage
        }
        if let oilQuantity = dispatch.effectiveStartingOilQuantity {
            dispatchPayload["oil_quantity"] = oilQuantity
            dispatchPayload["oil_unit"] = dispatch.effectiveStartingOilUnit
            if dispatch.effectiveStartingOilUnit == "%" {
                dispatchPayload["oil_percentage"] = Int(oilQuantity.rounded())
            }
        }
        if let value = dispatch.previousFlightRecordID { dispatchPayload["previous_flight_record_id"] = value.lowercased() }
        if let value = dispatch.previousEndingHobbs { dispatchPayload["previous_ending_hobbs"] = value }
        if let value = dispatch.previousEndingTacho { dispatchPayload["previous_ending_tacho"] = value }
        if let value = dispatch.previousFuelRemaining { dispatchPayload["previous_fuel_remaining"] = value }
        if let value = dispatch.previousOilPercentage { dispatchPayload["previous_oil_percentage"] = value }
        if let value = dispatch.effectivePreviousOilQuantity {
            dispatchPayload["previous_ending_oil_quantity"] = value
            dispatchPayload["previous_ending_oil_unit"] = dispatch.previousEndingOilUnit ?? "%"
        }
        if let value = dispatch.refueledSincePreviousFlight { dispatchPayload["refueled_since_previous_flight"] = value }
        if let value = dispatch.oilServicedSincePreviousFlight { dispatchPayload["oil_serviced_since_previous_flight"] = value }
        if let schedulerRecordID = dispatch.schedulerRecordID {
            dispatchPayload["scheduler_record_id"] = schedulerRecordID
        }
        if let scheduledStartTime = dispatch.scheduledStartTime {
            dispatchPayload["scheduled_start_time"] = iso.string(from: scheduledStartTime)
        }
        if let scheduledEndTime = dispatch.scheduledEndTime {
            dispatchPayload["scheduled_end_time"] = iso.string(from: scheduledEndTime)
        }
        if let identity = dispatch.operationalIdentity {
            dispatchPayload["operational_identity"] = CVROperationalIdentityLocal.payloadDictionary(from: identity)
            dispatchPayload["reservation_uuid"] = identity.reservationUUID
            dispatchPayload["leg_uuid"] = identity.legUUID
        }
        if let reservationUUID = dispatch.reservationUUID {
            dispatchPayload["reservation_uuid"] = reservationUUID.lowercased()
        }
        if isOperationalSession {
            guard let operationalSessionUUID = dispatch.operationalSessionUUID else {
                throw APIClientError.badResponse("Operational Session identity is missing.")
            }
            dispatchPayload["operational_session_uuid"] = operationalSessionUUID.lowercased()
            dispatchPayload["session_model_version"] = CVROperationalSessionRecord.modelVersion
        }

        var payload: [String: Any] = [
            "flight_record_uuid": flightRecord.id.lowercased(),
            "dispatch": dispatchPayload,
            "consents": isOperationalSession ? [] : consents.map { consent in
                var consentPayload: [String: Any] = [
                    "id": consent.id.lowercased(),
                    "person_name": consent.personName,
                    "crew_role": consent.crewRole.rawValue,
                    "consent_result": consent.consentResult,
                    "timestamp": iso.string(from: consent.timestamp),
                    "device_id": consent.deviceID,
                    "dispatch_id": consent.dispatchID.lowercased(),
                    "dispatch_version": consent.dispatchVersion,
                    "consent_text_version": consent.consentTextVersion,
                    "app_version": consent.appVersion
                ]
                if let personID = consent.personID {
                    consentPayload["person_id"] = personID
                }
                return consentPayload
            }
        ]
        if isOperationalSession, let operationalSessionUUID = dispatch.operationalSessionUUID {
            payload["operational_session_uuid"] = operationalSessionUUID.lowercased()
            payload["session_model_version"] = CVROperationalSessionRecord.modelVersion
        }
        return payload
    }

    private func uploadWorkflowEvidenceComponent(
        component: CVRUploadComponentRecord,
        context: (
            dispatch: CVRDispatchRecord,
            flightRecord: CVRIncompleteFlightRecord,
            consents: [CVRConsentRecord],
            events: [CVRFlightEventRecord],
            verifications: [CVRRecorderVerificationRecord]
        ),
        settings: SettingsStore,
        baseURL: URL,
        workflow: CVRWorkflowStore
    ) async throws -> WorkflowAcceptedMetadata {
        guard let credential = settings.deviceCredential, !credential.isEmpty else {
            throw APIClientError.badResponse("CVR Unit is not enrolled. Workflow evidence remains stored locally.")
        }
        workflow.updateUploadComponent(
            id: component.id,
            state: .uploading,
            progress: 0.25,
            lastError: "Sending immutable \(component.componentType.replacingOccurrences(of: "_", with: " ")) evidence..."
        )
        let generatedPayload = try workflowEvidencePayload(component: component, context: context, settings: settings)
        let payload = try preservedWorkflowPayload(
            component: component,
            generatedPayload: generatedPayload,
            workflow: workflow
        )
        let response = try await APIClient(serverURL: baseURL).syncWorkflowEvidence(
            payload: payload,
            credential: credential
        )
        guard response.ok,
              let receiptID = response.receipt?.receiptID,
              !receiptID.isEmpty,
              let payloadSHA256 = response.receipt?.payloadSHA256,
              !payloadSHA256.isEmpty,
              let verifiedAt = Self.parseServerDate(response.receipt?.serverVerifiedAt),
              let canonicalValues = response.canonicalIdentifiers,
              !canonicalValues.isEmpty else {
            throw APIClientError.badResponse(
                response.error ?? "Server accepted workflow evidence without complete authoritative verification metadata."
            )
        }
        return WorkflowAcceptedMetadata(
            receiptID: receiptID,
            payloadSHA256: payloadSHA256,
            verifiedAt: verifiedAt,
            canonicalIdentifiers: canonicalValues.compactMapValues(\.stringValue)
        )
    }

    private func workflowEvidencePayload(
        component: CVRUploadComponentRecord,
        context: (
            dispatch: CVRDispatchRecord,
            flightRecord: CVRIncompleteFlightRecord,
            consents: [CVRConsentRecord],
            events: [CVRFlightEventRecord],
            verifications: [CVRRecorderVerificationRecord]
        ),
        settings: SettingsStore
    ) throws -> [String: Any] {
        let iso = ISO8601DateFormatter()
        iso.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        let evidence: [String: Any]

        switch component.componentType {
        case "flight_events":
            let eventID = component.localFilePath.map { String($0.dropFirst("event:".count)) }
            guard let event = context.events.first(where: { $0.id == eventID }) else {
                throw APIClientError.badResponse("The queued Flight Event is missing from local history.")
            }
            var item: [String: Any] = [
                "event_uuid": event.id.lowercased(),
                "event_type": event.eventType,
                "timestamp_utc": iso.string(from: event.timestampUTC),
                "timestamp_local": iso.string(from: event.timestampLocal),
                "source": event.source,
                "confidence": event.confidence,
                "creation_method": event.creationMethod
            ]
            if let value = event.recordingSessionID { item["recording_session_id"] = value }
            if let value = event.deviceMonotonicTime { item["device_monotonic_time"] = value }
            if let value = event.audioOffset { item["audio_offset"] = value }
            if let value = event.latitude { item["latitude"] = value }
            if let value = event.longitude { item["longitude"] = value }
            if let value = event.altitude { item["altitude"] = value }
            if let value = event.groundSpeed { item["ground_speed"] = value }
            if let value = event.userIdentity { item["user_identity"] = value }
            if let metadata = event.metadata, !metadata.isEmpty { item["metadata"] = metadata }
            evidence = item
        case "recorder_verification":
            let verificationID = component.localFilePath.map { String($0.dropFirst("verification:".count)) }
            guard let verification = context.verifications.first(where: { $0.id == verificationID }) else {
                throw APIClientError.badResponse("The queued Recorder Verification is missing from local history.")
            }
            evidence = [
                "verification_uuid": verification.id.lowercased(),
                "timestamp": iso.string(from: verification.timestamp),
                "device_id": verification.deviceID,
                "app_version": verification.appVersion,
                "user_identity": verification.userIdentity,
                "audio_route_status": verification.audioRouteStatus,
                "beacon_status": verification.beaconStatus,
                "gps_status": verification.gpsStatus,
                "storage_status": verification.storageStatus,
                "thermal_status": verification.thermalStatus,
                "battery_status": verification.batteryStatus,
                "permission_status": verification.permissionStatus,
                "file_writing_test_result": verification.fileWritingTestResult,
                "warnings": verification.warnings,
                "accepted_nonblocking_warnings": verification.acceptedNonblockingWarnings
            ]
        case "flight_record_closure":
            let flight = context.flightRecord
            var item: [String: Any] = [
                "closure_uuid": component.id.lowercased(),
                "status": flight.status.rawValue,
                "updated_at": iso.string(from: flight.updatedAt)
            ]
            if let value = flight.endingHobbs { item["ending_hobbs"] = value }
            if let value = flight.endingTacho { item["ending_tacho"] = value }
            // Compatibility only: old server builds required fuel_remaining.
            // Its presence is optional locally and never gates Flight Closure.
            if let value = flight.fuelRemaining?.trimmingCharacters(in: .whitespacesAndNewlines),
               !value.isEmpty {
                item["fuel_remaining"] = value
            }
            if let value = flight.verifiedTakeoffCount { item["verified_takeoff_count"] = value }
            if let value = flight.verifiedLandingCount { item["verified_landing_count"] = value }
            if let value = flight.autoDetectedTakeoffCount { item["auto_detected_takeoff_count"] = value }
            if let value = flight.autoDetectedLandingCount { item["auto_detected_landing_count"] = value }
            if let value = flight.maintenanceRemark { item["maintenance_remark"] = value }
            if let value = flight.checkInComments { item["check_in_comments"] = value }
            if let value = flight.verifiedDestinationAirport {
                let normalized = CVROperationalIdentityLocal.normalizeAirport(value)
                if !normalized.isEmpty {
                    item["verified_destination_airport"] = normalized
                }
            }
            if let value = flight.checkInMode { item["check_in_mode"] = value.rawValue }
            // Carry block times on Check-In so admin Master Logbook can show OFF/ON even if
            // individual flight-event components are still syncing.
            // ON Block must be OFF + Hobbs delta — never Transient Stop / Shutdown button time.
            let flightEvents = context.events.filter { $0.flightRecordID == flight.id }
            if let offBlock = flightEvents.first(where: { $0.eventType == "engine_start_off_block" }) {
                item["off_block_utc"] = iso.string(from: offBlock.timestampUTC)
            }
            if let calculated = flight.calculatedArrivalAt {
                item["on_block_utc"] = iso.string(from: calculated)
                item["on_block_source"] = "off_block_plus_hobbs_increment"
            } else if let offBlock = flightEvents.first(where: { $0.eventType == "engine_start_off_block" }),
                      let startHobbs = context.dispatch.startingHobbs,
                      let endHobbs = flight.endingHobbs {
                let arrival = offBlock.timestampUTC.addingTimeInterval(max(0, endHobbs - startHobbs) * 3600)
                item["on_block_utc"] = iso.string(from: arrival)
                item["on_block_source"] = "off_block_plus_hobbs_increment"
            }
            evidence = item
        default:
            throw APIClientError.badResponse("Unsupported workflow evidence component.")
        }

        var payload: [String: Any] = [
            "schema_version": 1,
            "component_uuid": component.id.lowercased(),
            "component_type": component.componentType,
            "flight_record_uuid": context.flightRecord.id.lowercased(),
            "dispatch_uuid": context.dispatch.id.lowercased(),
            "evidence": evidence
        ]
        if let operationalSessionUUID = context.dispatch.operationalSessionUUID {
            payload["operational_session_uuid"] = operationalSessionUUID.lowercased()
        }
        return payload
    }

    func uploadGarminCSVAttachment(
        fileURL: URL,
        originalFilename: String,
        flightRecordID: String,
        settings: SettingsStore,
        progress: @escaping (Double) -> Void
    ) async throws {
        guard let baseURL = settings.normalizedServerURL else {
            throw APIClientError.invalidServerURL
        }
        guard let credential = settings.deviceCredential, !credential.isEmpty else {
            throw APIClientError.badResponse("CVR Unit is not enrolled.")
        }
        let client = APIClient(serverURL: baseURL)
        let uploadUUID = UUID().uuidString.lowercased()
        let fileSize = try fileSize(fileURL)
        let totalChunks = max(1, Int(ceil(Double(fileSize) / Double(chunkSize))))
        for chunkIndex in 0..<totalChunks {
            let offset = Int64(chunkIndex * chunkSize)
            let count = min(chunkSize, Int(fileSize - offset))
            let chunkData = try readChunk(
                fileURL: fileURL,
                offset: offset,
                count: count,
                chunkIndex: chunkIndex
            )
            progress(max(0.01, Double(chunkIndex) / Double(totalChunks) * 0.95))
            var lastError: Error?
            for attempt in 1...maxChunkAttempts {
                do {
                    let response = try await client.uploadCvrCsvChunk(
                        credential: credential,
                        uploadUUID: uploadUUID,
                        sessionUUID: nil,
                        chunkIndex: chunkIndex,
                        totalChunks: totalChunks,
                        totalSize: fileSize,
                        originalFilename: originalFilename,
                        chunkData: chunkData
                    )
                    guard response.ok else {
                        throw APIClientError.badResponse(response.error ?? "Garmin CSV chunk upload failed.")
                    }
                    lastError = nil
                    break
                } catch {
                    lastError = error
                    if attempt < maxChunkAttempts {
                        try await Task.sleep(for: .seconds(min(30, attempt * attempt * 2)))
                    }
                }
            }
            if let lastError {
                throw lastError
            }
            progress(min(0.95, Double(chunkIndex + 1) / Double(totalChunks) * 0.95))
        }
        progress(0.98)
        let finalized = try await client.finalizeCvrCsvUpload(
            credential: credential,
            uploadUUID: uploadUUID,
            workflowFlightRecordUUID: flightRecordID
        )
        guard finalized.ok else {
            throw APIClientError.badResponse(finalized.error ?? "Server rejected Garmin CSV.")
        }
        guard finalized.workflowLinked == true else {
            throw APIClientError.badResponse("Server stored the Garmin CSV but did not link it to the selected flight.")
        }
        progress(1)
    }

    private func uploadWorkflowGarminComponent(component: CVRUploadComponentRecord, flightRecordID: String, baseURL: URL, workflow: CVRWorkflowStore) async throws -> String {
        let fileURL = try workflowComponentFileURL(component)
        return try await uploadGarminFile(
            fileURL: fileURL,
            originalFilename: fileURL.lastPathComponent,
            flightRecordID: flightRecordID,
            baseURL: baseURL
        ) { value, message in
            let uploadState: CVRUploadComponentState = value >= 0.98 ? .uploaded : .uploading
            workflow.updateUploadComponent(
                id: component.id,
                state: uploadState,
                progress: value,
                lastError: message
            )
        }
    }

    private func uploadGarminFile(
        fileURL: URL,
        originalFilename: String,
        flightRecordID: String,
        baseURL: URL,
        progress: @escaping (Double, String) -> Void
    ) async throws -> String {
        let fileSize = try fileSize(fileURL)
        let totalChunks = max(1, Int(ceil(Double(fileSize) / Double(chunkSize))))

        for chunkIndex in 0..<totalChunks {
            let offset = Int64(chunkIndex * chunkSize)
            let count = min(chunkSize, Int(fileSize - offset))
            let chunkData = try readChunk(fileURL: fileURL, offset: offset, count: count, chunkIndex: chunkIndex)
            let request = workflowChunkUploadRequest(
                baseURL: baseURL,
                recordingID: flightRecordID,
                fileType: "g3x",
                chunkIndex: chunkIndex,
                totalChunks: totalChunks,
                totalSize: fileSize,
                chunkSize: count,
                originalFilename: originalFilename,
                mimeType: "text/csv"
            )

            progress(
                max(0.01, Double(chunkIndex) / Double(totalChunks) * 0.95),
                "Uploading CSV chunk \(chunkIndex + 1)/\(totalChunks)..."
            )

            var lastError: Error?
            for attempt in 1...maxChunkAttempts {
                do {
                    let (data, response) = try await send(request: request, body: chunkData)
                    let decoded = try APIClient(serverURL: baseURL).decodeChunkUploadResponse(data: data, response: response)
                    if !decoded.ok {
                        throw APIClientError.badResponse(decoded.error ?? "Chunk upload failed.")
                    }
                    lastError = nil
                    break
                } catch {
                    lastError = error
                    if attempt < maxChunkAttempts {
                        try await Task.sleep(nanoseconds: UInt64(min(30, attempt * attempt * 2)) * 1_000_000_000)
                    }
                }
            }
            if let lastError {
                throw lastError
            }

            progress(
                min(0.95, Double(chunkIndex + 1) / Double(totalChunks) * 0.95),
                "Uploaded CSV chunk \(chunkIndex + 1)/\(totalChunks)"
            )
        }

        progress(0.98, "Finalizing Garmin CSV on server...")

        var finalize = URLRequest(url: baseURL.appending(path: "api/recordings/g3x_finalize.php"))
        finalize.httpMethod = "POST"
        finalize.timeoutInterval = 3600
        finalize.setValue("application/json", forHTTPHeaderField: "Content-Type")
        finalize.httpBody = try JSONSerialization.data(withJSONObject: [
            "recording_id": flightRecordID,
            "import_profile": "cvr_workflow_garmin_share"
        ])
        let (data, response) = try await data(for: finalize)
        guard let http = response as? HTTPURLResponse, (200..<300).contains(http.statusCode) else {
            throw APIClientError.badResponse("Server finalize failed.")
        }
        let decoded = try JSONSerialization.jsonObject(with: data) as? [String: Any]
        if let ok = decoded?["ok"] as? Bool, ok == false {
            throw APIClientError.badResponse((decoded?["error"] as? String) ?? "Server rejected Garmin CSV.")
        }
        guard let receipt = decoded?["receipt"] as? [String: Any],
              let receiptID = receipt["receipt_id"] as? String,
              !receiptID.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty else {
            throw APIClientError.badResponse("Server did not return a Garmin CSV verification receipt.")
        }
        return receiptID
    }

    private func workflowChunkUploadRequest(
        baseURL: URL,
        recordingID: String,
        fileType: String,
        chunkIndex: Int,
        totalChunks: Int,
        totalSize: Int64,
        chunkSize: Int,
        originalFilename: String,
        mimeType: String
    ) -> URLRequest {
        let url = baseURL.appending(path: "api/recordings/upload_chunk.php")
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.timeoutInterval = 120
        request.setValue("application/octet-stream", forHTTPHeaderField: "Content-Type")
        request.setValue(recordingID, forHTTPHeaderField: "X-IPCA-Recording-ID")
        request.setValue(fileType, forHTTPHeaderField: "X-IPCA-File-Type")
        request.setValue(String(chunkIndex), forHTTPHeaderField: "X-IPCA-Chunk-Index")
        request.setValue(String(totalChunks), forHTTPHeaderField: "X-IPCA-Total-Chunks")
        request.setValue(String(totalSize), forHTTPHeaderField: "X-IPCA-Total-Size")
        request.setValue(String(chunkSize), forHTTPHeaderField: "X-IPCA-Chunk-Size")
        request.setValue(originalFilename, forHTTPHeaderField: "X-IPCA-Original-Filename")
        request.setValue(mimeType, forHTTPHeaderField: "X-IPCA-Mime-Type")
        return request
    }

    private func workflowComponentFileURL(_ component: CVRUploadComponentRecord) throws -> URL {
        guard let localFilePath = component.localFilePath?.trimmingCharacters(in: .whitespacesAndNewlines),
              !localFilePath.isEmpty else {
            throw APIClientError.badResponse("Garmin CSV local file reference is missing. Share the CSV to the app again.")
        }

        let fileManager = FileManager.default
        if localFilePath.hasPrefix("/") {
            let absoluteURL = URL(fileURLWithPath: localFilePath)
            if fileManager.fileExists(atPath: absoluteURL.path) {
                return absoluteURL
            }
            if let fallback = try? workflowGarminImportDirectory().appendingPathComponent(absoluteURL.lastPathComponent),
               fileManager.fileExists(atPath: fallback.path) {
                return fallback
            }
            throw APIClientError.badResponse("Garmin CSV is no longer in local storage. Share the CSV to the app again.")
        }

        let relative = localFilePath.replacingOccurrences(of: "GarminImports/", with: "")
        let url = try workflowGarminImportDirectory().appendingPathComponent(relative)
        guard fileManager.fileExists(atPath: url.path) else {
            throw APIClientError.badResponse("Garmin CSV is no longer in local storage. Share the CSV to the app again.")
        }
        return url
    }

    private func workflowGarminImportDirectory() throws -> URL {
        let base = try FileManager.default.url(
            for: .applicationSupportDirectory,
            in: .userDomainMask,
            appropriateFor: nil,
            create: true
        )
        return base.appendingPathComponent("IPCACVRUnit/GarminImports", isDirectory: true)
    }

    private func scheduleRetry(recordingID: String, store: RecordingStore, settings: SettingsStore) {
        retryTasks[recordingID]?.cancel()
        guard let recording = store.recording(id: recordingID),
              recording.uploadStatus == .failed,
              let nextUploadRetryAt = recording.nextUploadRetryAt else { return }
        let delay = max(1, nextUploadRetryAt.timeIntervalSinceNow)
        retryTasks[recordingID] = Task { [weak self, weak store, weak settings] in
            try? await Task.sleep(nanoseconds: UInt64(delay * 1_000_000_000))
            await MainActor.run {
                guard let self, let store, let settings else { return }
                self.retryTasks[recordingID] = nil
                self.upload(recordingID: recordingID, store: store, settings: settings)
            }
        }
    }

    private func performUpload(recordingID: String, store: RecordingStore, settings: SettingsStore) async throws {
        guard let baseURL = settings.normalizedServerURL else {
            throw APIClientError.invalidServerURL
        }
        guard let recording = store.recording(id: recordingID) else { return }

        let client = APIClient(serverURL: baseURL)
        let uploadResponse = try await performChunkedUpload(recording: recording, language: settings.language, client: client, store: store)
        if !uploadResponse.ok {
            throw APIClientError.badResponse(uploadResponse.error ?? "Upload failed.")
        }

        let serverRecordingID = uploadResponse.recording?.recordingID ?? recordingID
        await MainActor.run {
            store.update(recordingID) {
                $0.serverID = serverRecordingID
                $0.uploadStatus = .uploaded
                $0.uploadProgress = 1
                $0.transcriptStatus = .transcribing
                $0.transcriptProgress = uploadResponse.recording?.progress ?? 0
                $0.uploadRetryCount = nil
                $0.nextUploadRetryAt = nil
                $0.lastError = ""
            }
        }

        do {
            try await pollTranscript(recordingID: recordingID, serverRecordingID: serverRecordingID, store: store, client: client)
        } catch {
            await MainActor.run {
                store.update(recordingID) {
                    $0.uploadStatus = .uploaded
                    $0.uploadProgress = 1
                    $0.transcriptStatus = .failed
                    $0.lastError = "Upload complete. Transcript follow-up failed: \(error.localizedDescription)"
                    $0.uploadRetryCount = nil
                    $0.nextUploadRetryAt = nil
                }
            }
        }
    }

    private func performChunkedUpload(recording: Recording, language: String, client: APIClient, store: RecordingStore) async throws -> UploadResponse {
        let audioURL = try RecordingStore.resolvedFileURL(
            preferredPath: recording.filePath,
            recordingID: recording.id,
            fallbackFilename: "\(recording.id).m4a"
        )
        var files: [(type: String, url: URL, filename: String, mime: String, size: Int64)] = [
            ("audio", audioURL, audioURL.lastPathComponent, mimeType(for: audioURL), try fileSize(audioURL))
        ]

        if let gpsPath = recording.gpsSamplesPath {
            if let url = try? RecordingStore.resolvedFileURL(
                preferredPath: gpsPath,
                recordingID: recording.id,
                fallbackFilename: "\(recording.id).gps.json"
            ) {
                files.append(("gps", url, url.lastPathComponent, "application/json", try fileSize(url)))
            }
        }

        if let beaconPath = recording.beaconDiagnosticsPath {
            if let url = try? RecordingStore.resolvedFileURL(
                preferredPath: beaconPath,
                recordingID: recording.id,
                fallbackFilename: "\(recording.id).beacon.json"
            ) {
                files.append(("beacon", url, url.lastPathComponent, "application/json", try fileSize(url)))
            }
        }

        if let eventsPath = recording.recordingEventsPath {
            if let url = try? RecordingStore.resolvedFileURL(
                preferredPath: eventsPath,
                recordingID: recording.id,
                fallbackFilename: "\(recording.id).events.json"
            ) {
                files.append(("events", url, url.lastPathComponent, "application/json", try fileSize(url)))
            }
        }

        let totalBytes = max(1, files.reduce(Int64(0)) { $0 + max(0, $1.size) })
        var uploadedBytes: Int64 = 0

        await MainActor.run {
            store.update(recording.id) {
                $0.uploadProgress = 0.01
                $0.lastError = "Preparing chunked upload..."
            }
        }

        for file in files where file.size > 0 {
            uploadedBytes = try await uploadFileChunks(
                recording: recording,
                client: client,
                fileType: file.type,
                fileURL: file.url,
                originalFilename: file.filename,
                mimeType: file.mime,
                fileSize: file.size,
                uploadedBytes: uploadedBytes,
                totalBytes: totalBytes,
                store: store
            )
        }

        await MainActor.run {
            store.update(recording.id) {
                $0.uploadProgress = 0.99
                $0.lastError = "Finalizing audio package..."
            }
        }

        let finalizeRequest = try client.finalizeChunkedUploadRequest(for: recording, language: language)
        let (data, response) = try await data(for: finalizeRequest)
        return try client.decodeUploadResponse(data: data, response: response)
    }

    private func uploadFileChunks(
        recording: Recording,
        client: APIClient,
        fileType: String,
        fileURL: URL,
        originalFilename: String,
        mimeType: String,
        fileSize: Int64,
        uploadedBytes: Int64,
        totalBytes: Int64,
        store: RecordingStore
    ) async throws -> Int64 {
        let totalChunks = Int(ceil(Double(fileSize) / Double(chunkSize)))
        var completedBytes = uploadedBytes
        var receivedChunks = Set<Int>()

        do {
            let status = try await client.chunkUploadStatus(recordingID: recording.id, fileType: fileType)
            if status.ok, let chunks = status.receivedChunks {
                receivedChunks = Set(chunks)
            }
        } catch {
            // Resume is optional; continue from the beginning if status is unavailable.
        }

        let startIndex = firstMissingChunkIndex(received: receivedChunks, totalChunks: totalChunks)
        if startIndex >= totalChunks {
            return uploadedBytes + fileSize
        }

        if startIndex > 0 {
            let bytesAlreadyUploadedForFile = Int64(min(Int64(startIndex) * Int64(chunkSize), fileSize))
            completedBytes = uploadedBytes + bytesAlreadyUploadedForFile
            await MainActor.run {
                store.update(recording.id) {
                    $0.uploadProgress = min(0.98, Double(completedBytes) / Double(totalBytes) * 0.98)
                    $0.lastError = "Resuming \(fileType) upload at chunk \(startIndex + 1)/\(totalChunks)..."
                }
            }
        }

        for chunkIndex in startIndex..<totalChunks {
            let offset = Int64(chunkIndex * chunkSize)
            let count = min(chunkSize, Int(fileSize - offset))
            let chunkData = try readChunk(fileURL: fileURL, offset: offset, count: count, chunkIndex: chunkIndex)

            let request = client.chunkUploadRequest(
                recording: recording,
                fileType: fileType,
                chunkIndex: chunkIndex,
                totalChunks: totalChunks,
                totalSize: fileSize,
                chunkSize: count,
                originalFilename: originalFilename,
                mimeType: mimeType
            )

            await MainActor.run {
                store.update(recording.id) {
                    let baseProgress = Double(completedBytes) / Double(totalBytes) * 0.98
                    $0.uploadProgress = max(0.01, min(0.98, baseProgress))
                    $0.lastError = "Uploading \(fileType) chunk \(chunkIndex + 1)/\(totalChunks)..."
                }
            }

            var lastError: Error?
            for attempt in 1...maxChunkAttempts {
                do {
                    let (data, response) = try await send(request: request, body: chunkData)
                    let chunkResponse = try client.decodeChunkUploadResponse(data: data, response: response)
                    if !chunkResponse.ok {
                        throw APIClientError.badResponse(chunkResponse.error ?? "Chunk upload failed.")
                    }
                    lastError = nil
                    break
                } catch {
                    lastError = error
                    if attempt < maxChunkAttempts {
                        let delayNs = UInt64(min(30, attempt * attempt * 2)) * 1_000_000_000
                        await MainActor.run {
                            store.update(recording.id) {
                                $0.lastError = "Retrying \(fileType) chunk \(chunkIndex + 1)/\(totalChunks) (attempt \(attempt + 1)/\(maxChunkAttempts)): \(error.localizedDescription)"
                            }
                        }
                        try await Task.sleep(nanoseconds: delayNs)
                    }
                }
            }

            if let lastError {
                if (fileType == "beacon" || fileType == "events"), isUnsupportedOptionalSidecar(error: lastError) {
                    await MainActor.run {
                        store.update(recording.id) {
                            $0.lastError = "Server does not accept \(fileType) diagnostics yet. Continuing without \(fileType).json."
                        }
                    }
                    return uploadedBytes
                }
                throw APIClientError.badResponse(
                    "Failed \(fileType) chunk \(chunkIndex + 1)/\(totalChunks): \(lastError.localizedDescription)"
                )
            }

            completedBytes += Int64(count)
            await MainActor.run {
                store.update(recording.id) {
                    $0.uploadProgress = min(0.98, Double(completedBytes) / Double(totalBytes) * 0.98)
                    $0.lastError = "Uploaded \(fileType) chunk \(chunkIndex + 1)/\(totalChunks)"
                }
            }
        }

        return completedBytes
    }

    private func firstMissingChunkIndex(received: Set<Int>, totalChunks: Int) -> Int {
        guard !received.isEmpty else { return 0 }
        for index in 0..<totalChunks where !received.contains(index) {
            return index
        }
        return totalChunks
    }

    private func isUnsupportedOptionalSidecar(error: Error) -> Bool {
        let message = error.localizedDescription.lowercased()
        return message.contains("invalid file type") || message.contains("unsupported file type")
    }

    private func send(request: URLRequest, body: Data) async throws -> (Data, URLResponse) {
        let tempURL = FileManager.default.temporaryDirectory
            .appendingPathComponent("ipca-cvr-upload-chunk-\(UUID().uuidString).bin")
        try body.write(to: tempURL, options: .atomic)
        defer { try? FileManager.default.removeItem(at: tempURL) }

        return try await withCheckedThrowingContinuation { continuation in
            var request = request
            request.httpBody = nil
            let task = session.uploadTask(with: request, fromFile: tempURL) { data, response, error in
                if let error {
                    continuation.resume(throwing: error)
                    return
                }
                guard let data, let response else {
                    continuation.resume(throwing: APIClientError.badResponse("Upload returned no response."))
                    return
                }
                continuation.resume(returning: (data, response))
            }

            task.resume()
        }
    }

    private func data(for request: URLRequest) async throws -> (Data, URLResponse) {
        try await withCheckedThrowingContinuation { continuation in
            let task = session.dataTask(with: request) { data, response, error in
                if let error {
                    continuation.resume(throwing: error)
                    return
                }
                guard let data, let response else {
                    continuation.resume(throwing: APIClientError.badResponse("Server returned no response."))
                    return
                }
                continuation.resume(returning: (data, response))
            }
            task.resume()
        }
    }

    private func readChunk(fileURL: URL, offset: Int64, count: Int, chunkIndex: Int) throws -> Data {
        let handle = try FileHandle(forReadingFrom: fileURL)
        defer { try? handle.close() }
        try handle.seek(toOffset: UInt64(offset))
        let data = try handle.read(upToCount: count) ?? Data()
        if data.count != count {
            throw APIClientError.badResponse(
                "Could not read chunk \(chunkIndex + 1). Expected \(count) bytes, got \(data.count)."
            )
        }
        return data
    }

    private func fileSize(_ url: URL) throws -> Int64 {
        let values = try url.resourceValues(forKeys: [.fileSizeKey, .isUbiquitousItemKey, .ubiquitousItemDownloadingStatusKey])
        if values.isUbiquitousItem == true,
           values.ubiquitousItemDownloadingStatus != URLUbiquitousItemDownloadingStatus.current {
            throw APIClientError.badResponse("Recording file is still downloading from iCloud.")
        }
        return Int64(values.fileSize ?? 0)
    }

    private func mimeType(for url: URL) -> String {
        switch url.pathExtension.lowercased() {
        case "wav":
            return "audio/wav"
        case "mp3":
            return "audio/mpeg"
        case "aac":
            return "audio/aac"
        case "caf":
            return "audio/x-caf"
        default:
            return "audio/mp4"
        }
    }

    private func pollTranscript(recordingID: String, serverRecordingID: String, store: RecordingStore, client: APIClient) async throws {
        for _ in 0..<180 {
            try await Task.sleep(nanoseconds: 2_000_000_000)
            let status = try await client.status(recordingID: serverRecordingID)
            if let remote = status.recording {
                await MainActor.run {
                    store.update(recordingID) {
                        $0.transcriptProgress = remote.progress
                        $0.replayStatus = remote.reconstructionStatus
                        $0.replayProgress = remote.reconstructionProgress
                        $0.replayStage = remote.reconstructionStage
                        $0.lastError = remote.error
                        if remote.transcriptionStatus == "ready" {
                            $0.transcriptStatus = .ready
                        } else if remote.transcriptionStatus == "failed" {
                            $0.transcriptStatus = .failed
                        } else {
                            $0.transcriptStatus = .transcribing
                        }
                    }
                }

                if remote.transcriptionStatus == "ready" {
                    let transcript = try await client.transcript(recordingID: serverRecordingID)
                    await MainActor.run {
                        store.update(recordingID) {
                            $0.transcriptStatus = .ready
                            $0.transcript = transcript.transcript ?? ""
                        }
                    }
                    return
                }

                if remote.transcriptionStatus == "failed" {
                    throw APIClientError.badResponse(remote.error.isEmpty ? "Transcription failed." : remote.error)
                }
            }
        }

        throw APIClientError.badResponse("Timed out waiting for transcript.")
    }
}
