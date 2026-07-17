import Foundation

enum IPCAApiError: Error, LocalizedError {
    case missingToken
    case invalidBaseURL
    case server(String)
    case retryLater

    var errorDescription: String? {
        switch self {
        case .missingToken: return "Paste the IPCA Sync Agent device token first."
        case .invalidBaseURL: return "The IPCA.training address is invalid."
        case .server(let message): return message
        case .retryLater: return "IPCA.training asked the app to retry later."
        }
    }
}

private final class ProgressRequestDelegate: NSObject, URLSessionDataDelegate {
    private let progress: ((Double) -> Void)?
    private var responseData = Data()
    var completion: ((Result<(Data, URLResponse?), Error>) -> Void)?

    init(progress: ((Double) -> Void)?) {
        self.progress = progress
    }

    func urlSession(_ session: URLSession, dataTask: URLSessionDataTask, didReceive data: Data) {
        responseData.append(data)
    }

    func urlSession(_ session: URLSession, task: URLSessionTask, didSendBodyData bytesSent: Int64, totalBytesSent: Int64, totalBytesExpectedToSend: Int64) {
        guard totalBytesExpectedToSend > 0 else { return }
        progress?(min(1, Double(totalBytesSent) / Double(totalBytesExpectedToSend)))
    }

    func urlSession(_ session: URLSession, task: URLSessionTask, didCompleteWithError error: Error?) {
        if let error {
            completion?(.failure(error))
        } else {
            completion?(.success((responseData, task.response)))
        }
    }
}

struct ServerUploadResponse: Decodable {
    var ok: Bool
    var status: String
    var message: String?
}

final class IPCAApiClient {
    private let settings: SettingsStore
    private let keychain: KeychainStore

    init(settings: SettingsStore, keychain: KeychainStore) {
        self.settings = settings
        self.keychain = keychain
    }

    func validateToken() async throws -> Bool {
        let response = try await jsonRequest(path: "/api/sync-agent/status.php", body: ["client": "IPCA Sync Agent"])
        return (response["ok"] as? Bool) == true
    }

    func uploadEntry(_ item: RemoteSyncItem) async throws {
        let body: [String: Any] = [
            "provider": item.provider,
            "entry": encodeJSONObject(item.rawEntry),
            "entry_id": item.entryID,
            "source_uuids": item.sourceUUIDs,
            "track_uuids": item.trackUUIDs
        ]
        _ = try await jsonRequest(path: "/api/sync-agent/garmin_entries.php", body: body)
    }

    func uploadSource(_ queueItem: UploadQueueItem, progress: ((Double) -> Void)? = nil) async throws -> String {
        guard let fileData = FileManager.default.contents(atPath: queueItem.localPath) else {
            throw IPCAApiError.server("The downloaded Garmin source file is missing locally.")
        }
        let body: [String: Any] = [
            "provider": queueItem.provider,
            "entry_id": queueItem.entryID,
            "source_uuid": queueItem.sourceUUID,
            "artifact_type": queueItem.artifactType,
            "idempotency_key": queueItem.idempotencyKey,
            "sha256": queueItem.sha256,
            "byte_size": queueItem.byteSize,
            "filename": URL(fileURLWithPath: queueItem.localPath).lastPathComponent,
            "content_type": queueItem.contentType,
            "metadata": decodeMetadata(queueItem.metadataJSON),
            "content_base64": fileData.base64EncodedString()
        ]
        let response = try await jsonRequest(path: "/api/sync-agent/garmin_source.php", body: body, uploadProgress: progress)
        guard let status = response["status"] as? String else {
            throw IPCAApiError.server("IPCA.training returned an unexpected upload response.")
        }
        if status == "retry_later" { throw IPCAApiError.retryLater }
        if ["accepted", "already_exists", "review_required"].contains(status) {
            return status
        }
        throw IPCAApiError.server((response["message"] as? String) ?? "Upload was rejected by IPCA.training.")
    }

    func completeSync(provider: String, cursor: String?) async throws {
        _ = try await jsonRequest(path: "/api/sync-agent/garmin_sync_complete.php", body: [
            "provider": provider,
            "cursor": cursor as Any
        ])
    }

    private func jsonRequest(path: String, body: [String: Any], uploadProgress: ((Double) -> Void)? = nil) async throws -> [String: Any] {
        guard let base = settings.validatedAPIBaseURL else { throw IPCAApiError.invalidBaseURL }
        guard let token = keychain.loadToken(), !token.isEmpty else { throw IPCAApiError.missingToken }
        guard let url = URL(string: path, relativeTo: base)?.absoluteURL else { throw IPCAApiError.invalidBaseURL }
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        request.httpBody = try JSONSerialization.data(withJSONObject: body, options: [])
        let (data, response): (Data, URLResponse)
        if let uploadProgress {
            (data, response) = try await dataForRequest(request, uploadProgress: uploadProgress)
        } else {
            (data, response) = try await URLSession.shared.data(for: request)
        }
        let statusCode = (response as? HTTPURLResponse)?.statusCode ?? 0
        let decoded = (try? JSONSerialization.jsonObject(with: data)) as? [String: Any] ?? [:]
        guard (200...299).contains(statusCode) else {
            throw IPCAApiError.server((decoded["error"] as? String) ?? "IPCA.training returned HTTP \(statusCode).")
        }
        return decoded
    }

    private func dataForRequest(_ request: URLRequest, uploadProgress: @escaping (Double) -> Void) async throws -> (Data, URLResponse) {
        try await withCheckedThrowingContinuation { continuation in
            let delegate = ProgressRequestDelegate(progress: uploadProgress)
            let session = URLSession(configuration: .default, delegate: delegate, delegateQueue: nil)
            delegate.completion = { result in
                session.finishTasksAndInvalidate()
                switch result {
                case .success(let (data, response)):
                    guard let response else {
                        continuation.resume(throwing: IPCAApiError.server("IPCA.training returned no response."))
                        return
                    }
                    continuation.resume(returning: (data, response))
                case .failure(let error):
                    continuation.resume(throwing: error)
                }
            }
            session.dataTask(with: request).resume()
        }
    }

    private func encodeJSONObject(_ value: [String: JSONValue]) -> [String: Any] {
        value.mapValues { json in
            switch json {
            case .string(let value): return value
            case .number(let value): return value
            case .bool(let value): return value
            case .object(let value): return encodeJSONObject(value)
            case .array(let value): return value.map { encodeJSONValue($0) }
            case .null: return NSNull()
            }
        }
    }

    private func decodeMetadata(_ value: String?) -> Any {
        guard let value,
              let data = value.data(using: .utf8),
              let object = try? JSONSerialization.jsonObject(with: data) else {
            return [:]
        }
        return object
    }

    private func encodeJSONValue(_ json: JSONValue) -> Any {
        switch json {
        case .string(let value): return value
        case .number(let value): return value
        case .bool(let value): return value
        case .object(let value): return encodeJSONObject(value)
        case .array(let value): return value.map { encodeJSONValue($0) }
        case .null: return NSNull()
        }
    }
}

@MainActor
final class AppStateController: ObservableObject {
    @Published var status: AgentStatus = .notConfigured
    @Published var ipcaStatus = "Waiting for Device Token"
    @Published var garminStatus = "Not Connected"
    @Published var garminAuthenticationStatus = GarminAuthenticationState.unknown.rawValue
    @Published var garminCursorStatus = GarminCursorState.initialSyncRequired.rawValue
    @Published var garminSyncStatus = GarminSyncState.idle.rawValue
    @Published var garminBackfillStatus = "Idle"
    @Published var garminBackfillLastResult = "Not run"
    @Published var isBackfillRunning = false
    @Published var browserStatus = "Not Open"
    @Published var networkStatus = "Unknown"
    @Published var queueStartupStatus = "Unknown"
    @Published var lastSuccessfulSync: Date?
    @Published var nextScheduledSync: Date?
    @Published var newEntriesFound = 0
    @Published var filesDownloaded = 0
    @Published var filesUploaded = 0
    @Published var pendingUploads = 0
    @Published var uploadProgressDetail = "No upload queue loaded yet."
    @Published var backfillProgressDetail = "No backfill run in progress."
    @Published var currentWorkDetail = "Idle"
    @Published var pauseRequested = false
    @Published var isPaused = false
    @Published var pauseStatus = "Running"
    @Published var currentItemProgressPercent = 0
    @Published var overallProgressPercent = 0
    @Published var transferProgressDetail = "No active transfer."
    @Published var lastError = ""
    @Published var repairSummary = ""
    @Published var tokenInput = ""

    let settings = SettingsStore()
    let keychain = KeychainStore()
    let network = NetworkMonitor()
    let notifications = NotificationService()
    let launchAtLogin = LaunchAtLoginService()
    let browser = BrowserSessionController()
    let artifactStore = LocalArtifactStore()
    let cursorStore = CursorStore()
    let queue: LocalQueueStore
    let api: IPCAApiClient
    let garminProvider: GarminProvider
    let providerRegistry: ProviderRegistry
    var coordinator: SyncCoordinator!
    var scheduler: SyncScheduler!
    var repairService: RepairService!
    private(set) var queueAvailable = true

    init() {
        let version = Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String ?? "unknown"
        let build = Bundle.main.object(forInfoDictionaryKey: "CFBundleVersion") as? String ?? "unknown"
        LoggingService.shared.markLaunch(version: version, build: build)
        let localQueue: LocalQueueStore
        let localQueueAvailable: Bool
        let localQueueStatus: String
        do {
            localQueue = try LocalQueueStore()
            localQueueAvailable = true
            localQueueStatus = "Durable queue opened successfully"
            LoggingService.shared.info("Durable queue opened successfully")
        } catch {
            localQueueAvailable = false
            localQueueStatus = "Durable queue unavailable"
            LoggingService.shared.error("Durable queue unavailable: \(error.localizedDescription)")
            do {
                localQueue = try LocalQueueStore(inMemory: true)
            } catch {
                fatalError("IPCA Sync Agent could not create a local queue: \(error.localizedDescription)")
            }
        }

        let localAPI = IPCAApiClient(settings: settings, keychain: keychain)
        let localGarminProvider = GarminProvider(browser: browser, artifactStore: artifactStore, cursorStore: cursorStore)
        let localProviderRegistry = ProviderRegistry(garminProvider: localGarminProvider)

        queue = localQueue
        api = localAPI
        garminProvider = localGarminProvider
        providerRegistry = localProviderRegistry
        queueAvailable = localQueueAvailable
        queueStartupStatus = localQueueStatus

        let localCoordinator = SyncCoordinator(state: self, provider: localGarminProvider, api: localAPI, queue: localQueue, cursorStore: cursorStore, keychain: keychain)
        let localScheduler = SyncScheduler(state: self, coordinator: localCoordinator, settings: settings)
        let localRepairService = RepairService(state: self, browser: browser, api: localAPI, queue: localQueue, cursorStore: cursorStore)

        coordinator = localCoordinator
        scheduler = localScheduler
        repairService = localRepairService
        let cursorDiagnostic = cursorStore.cursorDiagnostic(provider: localGarminProvider.identifier)
        LoggingService.shared.info("Garmin cursor diagnostic: present=\(cursorDiagnostic.present ? "yes" : "no") length=\(cursorDiagnostic.length) updatedAt=\(cursorDiagnostic.updatedAt?.formatted(.iso8601) ?? "n/a")")
        cursorStore.logCursor("SYNC_NOW_CURSOR_READ", provider: localGarminProvider.identifier, extra: "context=appLaunch")
        notifications.requestAuthorizationIfNeeded()
        refreshConfigurationState()
        scheduler.start()
    }

    func refreshConfigurationState() {
        if keychain.loadToken() == nil {
            status = .waitingForDeviceToken
            ipcaStatus = "Waiting for Device Token"
        } else {
            ipcaStatus = "Token Saved"
            if status == .waitingForDeviceToken || status == .notConfigured { status = .idle }
        }
        pendingUploads = (try? queue.pendingCount()) ?? 0
        refreshQueueProgress()
        refreshGarminCursorStatus()
    }

    func refreshQueueProgress() {
        let uploadCounts = (try? queue.uploadQueueStateCounts()) ?? [:]
        let backfillCounts = (try? queue.backfillTrackStateCounts()) ?? [:]
        let queued = uploadCounts[QueueState.queued.rawValue, default: 0]
        let uploading = uploadCounts[QueueState.uploading.rawValue, default: 0]
        let retrying = uploadCounts[QueueState.retryWait.rawValue, default: 0]
        let failed = uploadCounts[QueueState.failed.rawValue, default: 0]
        let uploaded = uploadCounts[QueueState.uploaded.rawValue, default: 0] +
            uploadCounts[QueueState.alreadyExists.rawValue, default: 0] +
            uploadCounts[QueueState.completed.rawValue, default: 0]
        pendingUploads = queued + uploading + retrying + failed
        uploadProgressDetail = "Uploaded \(uploaded) / remaining \(pendingUploads) (queued \(queued), uploading \(uploading), retry \(retrying), failed \(failed))"

        let seen = backfillCounts[GarminBackfillTrackState.seen.rawValue, default: 0]
        let backfillQueued = backfillCounts[GarminBackfillTrackState.queued.rawValue, default: 0]
        let backfillUploaded = backfillCounts[GarminBackfillTrackState.uploaded.rawValue, default: 0]
        let ignored = backfillCounts[GarminBackfillTrackState.ignoredGPSOnly.rawValue, default: 0]
        let backfillFailed = backfillCounts[GarminBackfillTrackState.failed.rawValue, default: 0]
        backfillProgressDetail = "Tracks: uploaded \(backfillUploaded), queued \(backfillQueued), seen \(seen), GPS-only ignored \(ignored), failed \(backfillFailed)"
    }

    func updateTransferProgress(phase: String, itemProgress: Double, overallProgress: Double, detail: String) {
        currentItemProgressPercent = Self.percentValue(itemProgress)
        overallProgressPercent = Self.percentValue(overallProgress)
        transferProgressDetail = "\(phase): item \(currentItemProgressPercent)%, overall \(overallProgressPercent)%"
        currentWorkDetail = detail
    }

    func requestPause() {
        pauseRequested = true
        isPaused = false
        pauseStatus = "Pause requested. Finishing the current item..."
        currentWorkDetail = pauseStatus
    }

    func resumeTransfers() {
        pauseRequested = false
        isPaused = false
        pauseStatus = "Running"
        currentWorkDetail = "Resuming queued work..."
        if garminBackfillStatus == "Paused" {
            backfillGarminHistory()
        } else {
            syncNow()
        }
    }

    func markPaused(_ message: String) {
        pauseRequested = false
        isPaused = true
        pauseStatus = "Paused"
        currentWorkDetail = message
        status = .idle
    }

    private static func percentValue(_ fraction: Double) -> Int {
        Int((min(1, max(0, fraction)) * 100).rounded())
    }

    func refreshGarminCursorStatus() {
        let cursor = cursorStore.cursor(provider: garminProvider.identifier)
        if cursor == nil || cursor?.isEmpty == true {
            garminCursorStatus = GarminCursorState.initialSyncRequired.rawValue
        } else {
            garminCursorStatus = GarminCursorState.ready.rawValue
        }
    }

    func saveToken() {
        do {
            try keychain.saveToken(tokenInput.trimmingCharacters(in: .whitespacesAndNewlines))
            tokenInput = ""
            refreshConfigurationState()
            Task { await validateToken() }
        } catch {
            setError("Could not save the IPCA token.")
        }
    }

    func validateToken() async {
        do {
            status = .connecting
            if try await api.validateToken() {
                ipcaStatus = "Connected to IPCA.training"
                status = .idle
            }
        } catch {
            ipcaStatus = "IPCA.training token needs attention"
            setError(error.localizedDescription)
        }
    }

    func connectGarmin() {
        Task { await coordinator.connectGarmin() }
    }

    func confirmGarminLogbook() {
        Task { await coordinator.verifyGarmin() }
    }

    func syncNow() {
        Task { await coordinator.syncNow(manual: true) }
    }

    func backfillGarminHistory() {
        Task { await coordinator.backfillGarminHistory() }
    }

    func reloadGarminForInitialSync() {
        Task { await coordinator.reloadGarminForInitialSync() }
    }

    func reconnectGarmin() {
        connectGarmin()
    }

    func repairConnection() {
        Task {
            let report = await repairService.repair()
            repairSummary = "\(report.state.rawValue): \(report.summary) Recommended action: \(report.recommendedAction)"
        }
    }

    func setError(_ message: String) {
        lastError = message
        status = .error
        LoggingService.shared.error(message)
    }
}

final class SyncCoordinator {
    private weak var state: AppStateController?
    private let provider: GarminProvider
    private let api: IPCAApiClient
    private let queue: LocalQueueStore
    private let cursorStore: CursorStore
    private let keychain: KeychainStore
    private var isSyncing = false

    init(state: AppStateController, provider: GarminProvider, api: IPCAApiClient, queue: LocalQueueStore, cursorStore: CursorStore, keychain: KeychainStore) {
        self.state = state
        self.provider = provider
        self.api = api
        self.queue = queue
        self.cursorStore = cursorStore
        self.keychain = keychain
    }

    @MainActor
    func connectGarmin() async {
        do {
            state?.status = .waitingForGarminLogin
            state?.lastError = ""
            state?.garminStatus = "Waiting for Garmin Login"
            try await provider.connect()
            state?.browserStatus = "Open"
        } catch {
            state?.setError(error.localizedDescription)
        }
    }

    @MainActor
    func verifyGarmin() async {
        do {
            state?.status = .verifyingGarmin
            state?.lastError = ""
            let result = try await provider.verifyConnection()
            state?.garminStatus = result.connected ? "Connected" : "Action Required"
            state?.garminAuthenticationStatus = result.connected ? GarminAuthenticationState.connected.rawValue : GarminAuthenticationState.unknown.rawValue
            state?.status = result.connected ? .connected : .actionRequired
            LoggingService.shared.info("Garmin verified with \(result.entryCount) visible entries.")
        } catch {
            if case GarminError.initialSyncBootstrapRequired = error {
                state?.garminAuthenticationStatus = GarminAuthenticationState.connected.rawValue
                state?.garminCursorStatus = GarminCursorState.initialSyncRequired.rawValue
                state?.garminSyncStatus = GarminSyncState.initialBootstrapRequired.rawValue
                state?.status = .actionRequired
                state?.lastError = error.localizedDescription
            } else if case GarminError.logbookEndpointUnavailable = error {
                state?.garminAuthenticationStatus = GarminAuthenticationState.unknown.rawValue
                state?.refreshGarminCursorStatus()
                state?.garminSyncStatus = GarminSyncState.error.rawValue
                state?.status = .actionRequired
                state?.lastError = Self.logbookEndpointUnavailableMessage(hasValidSavedCursor: self.hasValidSavedCursor())
            } else {
                state?.garminStatus = "Garmin Authentication Required"
                state?.garminAuthenticationStatus = GarminAuthenticationState.loginRequired.rawValue
                state?.notifications.notify(title: "Garmin Login Required", body: "Open IPCA Sync Agent and reconnect Garmin.")
                state?.setError(error.localizedDescription)
            }
        }
    }

    @MainActor
    func syncNow(manual: Bool = false) async {
        if isSyncing { return }
        guard state?.queueAvailable == true else {
            state?.setError("Local durable queue is unavailable in this app launch. Repair the queue before syncing.")
            return
        }
        isSyncing = true
        let runID = UUID().uuidString
        LoggingService.shared.info("Sync run \(runID) started.")
        defer { isSyncing = false }
        do {
            state?.status = .syncing
            state?.isPaused = false
            state?.pauseStatus = "Running"
            state?.garminSyncStatus = GarminSyncState.checkingForUpdates.rawValue
            state?.lastError = "Preparing Garmin sync..."
            let entries: [RemoteSyncItem]
            do {
                entries = try await provider.discoverNewItems()
            } catch {
                throw error
            }
            state?.newEntriesFound = entries.count
            if entries.isEmpty {
                state?.garminSyncStatus = GarminSyncState.noNewFlights.rawValue
                state?.lastError = "Garmin is connected. No new or changed flights."
                if let cursor = provider.lastReturnedCursor {
                    cursorStore.setCursor(cursor, provider: provider.identifier)
                    state?.refreshGarminCursorStatus()
                }
                if keychain.loadToken() != nil {
                    try await uploadPending()
                }
                state?.lastSuccessfulSync = Date()
                state?.nextScheduledSync = Date().addingTimeInterval(TimeInterval((state?.settings.syncIntervalMinutes ?? 10) * 60))
                state?.status = .idle
                LoggingService.shared.info("Sync run \(runID) completed with no new Garmin flights.")
                return
            }
            state?.garminSyncStatus = "\(entries.count) changed entr\(entries.count == 1 ? "y" : "ies") found"
            let downloadedBefore = state?.filesDownloaded ?? 0
            state?.lastError = "Found \(entries.count) Garmin entr\(entries.count == 1 ? "y" : "ies"). Downloading source files..."
            for entry in entries {
                state?.status = .downloading
                state?.lastError = "Downloading Garmin sources for entry \(entry.entryID)..."
                let artifacts = try await provider.download(item: entry)
                state?.filesDownloaded += artifacts.count
                for artifact in artifacts {
                    try queue.enqueue(artifact)
                }
            }
            if (state?.filesDownloaded ?? 0) == downloadedBefore {
                if let cursor = provider.lastReturnedCursor {
                    cursorStore.setCursor(cursor, provider: provider.identifier)
                    state?.refreshGarminCursorStatus()
                }
                state?.lastError = "Garmin returned changed flight metadata, but no explicit CSV or track artifact was present."
                state?.status = .idle
                return
            }
            if let cursor = provider.lastReturnedCursor {
                cursorStore.setCursor(cursor, provider: provider.identifier)
                state?.refreshGarminCursorStatus()
            }
            state?.pendingUploads = (try? queue.pendingCount()) ?? 0
            state?.refreshQueueProgress()
            if keychain.loadToken() == nil {
                state?.lastError = "Garmin files were downloaded locally. Paste the IPCA Sync Agent device token to upload them."
            } else {
                state?.lastError = "Uploading pending Garmin files to IPCA.training..."
                for entry in entries {
                    try await api.uploadEntry(entry)
                }
                try await uploadPending()
                if state?.isPaused == true {
                    LoggingService.shared.info("Sync run \(runID) paused during upload.")
                    return
                }
                try await api.completeSync(provider: provider.identifier, cursor: cursorStore.cursor(provider: provider.identifier))
                state?.lastError = ""
            }
            state?.lastSuccessfulSync = Date()
            state?.nextScheduledSync = Date().addingTimeInterval(TimeInterval((state?.settings.syncIntervalMinutes ?? 10) * 60))
            state?.status = .idle
            LoggingService.shared.info("Sync run \(runID) completed.")
        } catch {
            if case GarminError.initialSyncBootstrapRequired = error {
                state?.garminCursorStatus = GarminCursorState.initialSyncRequired.rawValue
                state?.garminSyncStatus = GarminSyncState.initialBootstrapRequired.rawValue
                state?.status = .actionRequired
                state?.lastError = error.localizedDescription
                LoggingService.shared.error("Sync run \(runID) requires initial Garmin bootstrap.")
            } else if case GarminError.logbookEndpointUnavailable = error {
                state?.refreshGarminCursorStatus()
                state?.garminSyncStatus = GarminSyncState.error.rawValue
                state?.status = .actionRequired
                state?.lastError = Self.logbookEndpointUnavailableMessage(hasValidSavedCursor: self.hasValidSavedCursor())
                LoggingService.shared.error("Sync run \(runID) could not capture a confirmed Garmin Logbook endpoint.")
            } else {
                state?.garminSyncStatus = GarminSyncState.error.rawValue
                state?.setError(error.localizedDescription)
            }
        }
    }

    @MainActor
    func backfillGarminHistory() async {
        if state?.isBackfillRunning == true { return }
        guard state?.queueAvailable == true else {
            state?.setError("Local durable queue is unavailable in this app launch. Repair the queue before backfilling.")
            return
        }
        state?.isBackfillRunning = true
        state?.isPaused = false
        state?.pauseStatus = "Running"
        let runID = UUID().uuidString
        let cursorBefore = cursorStore.cursor(provider: provider.identifier)
        cursorStore.logCursor("BACKFILL_RUN_STARTED", provider: provider.identifier, value: cursorBefore, extra: "runID=\(runID)")
        state?.garminBackfillStatus = "Inspecting Garmin history"
        state?.garminBackfillLastResult = "Backfill run \(runID) started."
        var discoveryResult: GarminBackfillDiscoveryResult?
        do {
            let skipTracks = try queue.backfillSkipTrackUUIDs().union(queue.ignoredGPSOnlyBackfillTrackUUIDs())
            LoggingService.shared.info("BACKFILL_REQUEST_STARTED runID=\(runID) source=GET /fly-garmin/api/logbook/ cursorBeforeSha256=\(cursorStore.valueDiagnostic(provider: provider.identifier, value: cursorBefore).fingerprint)")
            let result = try await provider.discoverHistoricalBackfill(skippingTrackUUIDs: skipTracks, fromDate: "2025-01-01", limit: Int.max)
            discoveryResult = result
            try queue.recordBackfillRun(runID: runID, source: result.sourceDescription, result: result, status: "discovered", error: nil)
            state?.garminBackfillStatus = "Discovered \(result.inspectedEntryCount) entries; selected \(result.selectedItemCount) tracks"
            state?.currentWorkDetail = "Backfill discovered \(result.selectedItemCount) historical tracks from \(result.inspectedEntryCount) Garmin entries."
            state?.refreshQueueProgress()
            LoggingService.shared.info("BACKFILL_DISCOVERY runID=\(runID) rawEntries=\(result.inspectedEntryCount) entriesWithTracks=\(result.entriesWithTracksCount) selectedTracks=\(result.selectedItemCount) skippedCompletedOrQueued=\(result.skippedSeenCount) skippedMissingTrack=\(result.skippedMissingTrackCount) remainingEstimate=\(result.remainingEstimate.map(String.init) ?? "n/a") source=\(result.sourceDescription)")

            var downloaded = 0
            var queued = 0
            var failed = 0
            var skippedGPSOnly = 0
            for (index, item) in result.items.enumerated() {
                let backfillKey = item.trackUUIDs.first ?? item.sourceUUIDs.first ?? item.entryID
                state?.garminBackfillStatus = "Downloading historical Garmin artifact \(index + 1) of \(result.items.count)"
                state?.updateTransferProgress(
                    phase: "Downloading",
                    itemProgress: 0,
                    overallProgress: Double(index) / Double(max(1, result.items.count)),
                    detail: "Downloading historical Garmin artifact \(index + 1) of \(result.items.count). Downloaded \(downloaded), queued \(queued), failed \(failed)."
                )
                try queue.markBackfillTrack(trackUUID: backfillKey, entryID: item.entryID, runID: runID, state: .seen)
                LoggingService.shared.info("BACKFILL_ARTIFACT_SELECTED runID=\(runID) entry=\(item.entryID) key=\(backfillKey) tracks=\(item.trackUUIDs.joined(separator: ",")) sources=\(item.sourceUUIDs.joined(separator: ","))")
                do {
                    let artifacts = try await provider.download(item: item)
                    downloaded += artifacts.count
                    for artifact in artifacts {
                        try queue.enqueue(artifact)
                        queued += 1
                        try queue.markBackfillTrack(trackUUID: artifact.sourceUUID, entryID: item.entryID, runID: runID, state: .queued)
                        state?.refreshQueueProgress()
                        LoggingService.shared.info("BACKFILL_QUEUE_RESULT runID=\(runID) entry=\(item.entryID) track=\(artifact.sourceUUID) result=queued")
                    }
                } catch GarminError.gpxOnly(let message) {
                    skippedGPSOnly += 1
                    try? queue.markBackfillTrack(trackUUID: backfillKey, entryID: item.entryID, runID: runID, state: .ignoredGPSOnly, error: message)
                    LoggingService.shared.info("BACKFILL_ARTIFACT_IGNORED_GPS_ONLY runID=\(runID) entry=\(item.entryID) key=\(backfillKey) reason=\(message)")
                } catch {
                    failed += 1
                    try? queue.markBackfillTrack(trackUUID: backfillKey, entryID: item.entryID, runID: runID, state: .failed, error: error.localizedDescription)
                    LoggingService.shared.error("BACKFILL_ARTIFACT_FAILED runID=\(runID) entry=\(item.entryID) key=\(backfillKey) error=\(error.localizedDescription)")
                }
                state?.updateTransferProgress(
                    phase: "Downloading",
                    itemProgress: 1,
                    overallProgress: Double(index + 1) / Double(max(1, result.items.count)),
                    detail: "Finished historical Garmin artifact \(index + 1) of \(result.items.count). Downloaded \(downloaded), queued \(queued), failed \(failed)."
                )
                if state?.pauseRequested == true {
                    try? queue.recordBackfillRun(runID: runID, source: result.sourceDescription, result: result, status: "paused", error: nil)
                    state?.garminBackfillStatus = "Paused"
                    state?.garminBackfillLastResult = "Paused after track \(index + 1) of \(result.items.count). Click Resume to continue."
                    state?.markPaused("Paused after finishing historical track \(index + 1) of \(result.items.count).")
                    LoggingService.shared.info("BACKFILL_RUN_PAUSED runID=\(runID) completedTracks=\(index + 1) totalTracks=\(result.items.count)")
                    state?.isBackfillRunning = false
                    return
                }
            }
            state?.pendingUploads = (try? queue.pendingCount()) ?? 0
            state?.refreshQueueProgress()
            if keychain.loadToken() != nil {
                state?.garminBackfillStatus = "Uploading queued historical tracks"
                try await uploadPending()
            }
            let pausedDuringUpload = state?.isPaused == true
            let cursorAfter = cursorStore.cursor(provider: provider.identifier)
            cursorStore.logCursor("BACKFILL_CURSOR_AFTER", provider: provider.identifier, value: cursorAfter, extra: "runID=\(runID)")
            guard cursorBefore == cursorAfter else {
                let message = "Critical internal error: Garmin incremental cursor changed during backfill."
                LoggingService.shared.error("BACKFILL_CURSOR_INVARIANT runID=\(runID) result=failed")
                throw GarminError.unexpectedResponse(message)
            }
            LoggingService.shared.info("BACKFILL_CURSOR_INVARIANT runID=\(runID) result=passed")
            try queue.recordBackfillRun(runID: runID, source: result.sourceDescription, result: result, status: "completed", error: nil)
            state?.garminBackfillStatus = pausedDuringUpload ? "Completed; upload paused" : "Completed"
            state?.garminBackfillLastResult = "From 2025-01-01: inspected \(result.inspectedEntryCount), selected \(result.selectedItemCount), downloaded \(downloaded), queued \(queued), GPS-only ignored \(skippedGPSOnly), failed \(failed), skipped \(result.skippedSeenCount)."
            if !pausedDuringUpload {
                state?.currentWorkDetail = "Backfill download complete. Upload queue is processing remaining files."
            }
            state?.refreshQueueProgress()
            LoggingService.shared.info("BACKFILL_RUN_COMPLETED runID=\(runID) downloaded=\(downloaded) queued=\(queued) skippedGPSOnly=\(skippedGPSOnly) failed=\(failed)")
        } catch {
            let cursorAfter = cursorStore.cursor(provider: provider.identifier)
            cursorStore.logCursor("BACKFILL_CURSOR_AFTER", provider: provider.identifier, value: cursorAfter, extra: "runID=\(runID)")
            LoggingService.shared.error("BACKFILL_CURSOR_INVARIANT runID=\(runID) result=\(cursorBefore == cursorAfter ? "passed" : "failed")")
            try? queue.recordBackfillRun(runID: runID, source: discoveryResult?.sourceDescription ?? "GET /fly-garmin/api/logbook/", result: discoveryResult, status: "failed", error: error.localizedDescription)
            state?.garminBackfillStatus = "Failed"
            state?.garminBackfillLastResult = error.localizedDescription
            state?.currentWorkDetail = "Backfill failed: \(error.localizedDescription)"
            state?.refreshQueueProgress()
            LoggingService.shared.error("BACKFILL_RUN_FAILED runID=\(runID) error=\(error.localizedDescription)")
        }
        state?.isBackfillRunning = false
    }

    @MainActor
    func reloadGarminForInitialSync() async {
        do {
            state?.status = .syncing
            state?.garminSyncStatus = GarminSyncState.initialBootstrapRequired.rawValue
            state?.lastError = "Reloading Garmin Logbook for Initial Sync. Leave Chrome visible."
            let countdown = startGarminReadinessCountdown(seconds: 180)
            do {
                try await provider.reloadLogbookForInitialSync()
                countdown.cancel()
            } catch {
                countdown.cancel()
                throw error
            }
            state?.refreshGarminCursorStatus()
            state?.garminSyncStatus = GarminSyncState.idle.rawValue
            guard self.hasValidSavedCursor() else {
                state?.setError("Garmin cursor persistence failed after bootstrap. The app did not save a durable cursor.")
                return
            }
            state?.lastError = "Initial Garmin sync cursor captured. Click Sync Now to check for updates."
            state?.status = .idle
        } catch {
            if case GarminError.logbookEndpointUnavailable = error {
                state?.garminCursorStatus = GarminCursorState.initialSyncRequired.rawValue
                state?.garminSyncStatus = GarminSyncState.initialBootstrapRequired.rawValue
                state?.status = .actionRequired
                state?.lastError = "No Garmin Logbook response was captured after reload. Confirm the Garmin Logbook list is visible, then click Reload Garmin Logbook for Initial Sync again."
            } else {
                state?.setError(error.localizedDescription)
            }
        }
    }

    private func hasValidSavedCursor() -> Bool {
        Self.savedCursorIsValid(cursorStore: cursorStore, providerIdentifier: provider.identifier)
    }

    static func savedCursorIsValid(cursorStore: CursorStore, providerIdentifier: String) -> Bool {
        CursorStore.validationRejectionReason(cursorStore.cursor(provider: providerIdentifier)) == nil
    }

    static func logbookEndpointUnavailableMessage(hasValidSavedCursor: Bool) -> String {
        hasValidSavedCursor ?
            "Garmin incremental Logbook endpoint could not be confirmed. The saved cursor remains valid; try reloading the Garmin Logbook page and Sync Now again." :
            "The app does not yet have a valid initial Garmin sync cursor. Reload Garmin Logbook for Initial Sync."
    }

    @MainActor
    private func startGarminReadinessCountdown(seconds: Int) -> Task<Void, Never> {
        Task { @MainActor [weak state] in
            let started = Date()
            for remaining in stride(from: seconds, through: 1, by: -1) {
                guard !Task.isCancelled else { return }
                let elapsed = Int(Date().timeIntervalSince(started))
                let readiness = state?.garminProvider.lastReadinessMessage ?? "Waiting for Garmin Logbook to finish loading..."
                state?.lastError = "\(readiness) Elapsed \(elapsed)s. Waiting up to \(remaining)s more."
                try? await Task.sleep(nanoseconds: 1_000_000_000)
            }
            if !Task.isCancelled {
                state?.lastError = "Garmin is still loading historical flights. Leave Chrome open and click Sync Now again."
            }
        }
    }

    @MainActor
    func uploadPending() async throws {
        state?.status = .uploading
        state?.isPaused = false
        state?.pauseStatus = "Running"
        let initialPending = max(1, (try? queue.pendingCount()) ?? 1)
        var processedThisRun = 0
        while true {
            let items = try queue.pending()
            guard !items.isEmpty else { break }
            state?.pendingUploads = (try? queue.pendingCount()) ?? items.count
            state?.refreshQueueProgress()
            for item in items {
                do {
                    try queue.markUploading(item.id)
                    state?.refreshQueueProgress()
                    let processedBeforeItem = processedThisRun
                    let artifactType = item.artifactType
                    let sourceUUID = item.sourceUUID
                    state?.updateTransferProgress(
                        phase: "Uploading",
                        itemProgress: 0,
                        overallProgress: Double(processedBeforeItem) / Double(initialPending),
                        detail: "Uploading \(artifactType) for track/source \(sourceUUID)."
                    )
                    let status = try await api.uploadSource(item) { [weak state] progress in
                        Task { @MainActor [weak state] in
                            state?.updateTransferProgress(
                                phase: "Uploading",
                                itemProgress: progress,
                                overallProgress: (Double(processedBeforeItem) + progress) / Double(initialPending),
                                detail: "Uploading \(artifactType) for track/source \(sourceUUID)."
                            )
                        }
                    }
                    try queue.markServerResult(item.id, status: status)
                    if item.artifactType == "GARMIN_TRACK_NORMALIZED_JSON" {
                        try queue.updateBackfillTrackState(trackUUID: item.sourceUUID, state: .uploaded)
                        LoggingService.shared.info("BACKFILL_UPLOAD_RESULT track=\(item.sourceUUID) result=\(status)")
                    } else if item.artifactType == "GARMIN_ORIGINAL_SOURCE" {
                        try queue.updateBackfillTrackState(trackUUID: item.sourceUUID, state: .uploaded)
                        LoggingService.shared.info("BACKFILL_UPLOAD_RESULT flightDataLogUUID=\(item.sourceUUID) result=\(status)")
                    }
                    state?.filesUploaded += 1
                    processedThisRun += 1
                    state?.refreshQueueProgress()
                    state?.updateTransferProgress(
                        phase: "Uploading",
                        itemProgress: 1,
                        overallProgress: Double(processedThisRun) / Double(initialPending),
                        detail: "Uploaded \(processedThisRun) of \(initialPending) queued files in this run."
                    )
                } catch {
                    if item.artifactType == "GARMIN_TRACK_NORMALIZED_JSON" {
                        try? queue.updateBackfillTrackState(trackUUID: item.sourceUUID, state: .failed, error: error.localizedDescription)
                    }
                    try queue.markRetry(item.id, error: error.localizedDescription, attempts: item.attempts + 1)
                    processedThisRun += 1
                    state?.refreshQueueProgress()
                    LoggingService.shared.error("Upload retry scheduled for \(item.sourceUUID): \(error.localizedDescription)")
                }
                if state?.pauseRequested == true {
                    state?.markPaused("Paused after finishing the current upload. \(state?.pendingUploads ?? 0) uploads remain queued.")
                    LoggingService.shared.info("UPLOAD_RUN_PAUSED processed=\(processedThisRun) remaining=\(state?.pendingUploads ?? 0)")
                    return
                }
            }
        }
        state?.pendingUploads = (try? queue.pendingCount()) ?? 0
        state?.refreshQueueProgress()
        state?.currentWorkDetail = (state?.pendingUploads ?? 0) == 0 ? "Upload queue is empty." : "Upload queue paused until retry time or next sync."
    }
}

final class SyncScheduler {
    private weak var state: AppStateController?
    private let coordinator: SyncCoordinator
    private let settings: SettingsStore
    private var task: Task<Void, Never>?

    init(state: AppStateController, coordinator: SyncCoordinator, settings: SettingsStore) {
        self.state = state
        self.coordinator = coordinator
        self.settings = settings
    }

    func start() {
        task?.cancel()
        task = Task {
            while !Task.isCancelled {
                let interval = max(1, settings.syncIntervalMinutes) * 60
                await MainActor.run {
                    state?.nextScheduledSync = Date().addingTimeInterval(TimeInterval(interval))
                }
                try? await Task.sleep(nanoseconds: UInt64(interval) * 1_000_000_000)
                await coordinator.syncNow()
            }
        }
    }
}

final class RepairService {
    private weak var state: AppStateController?
    private let browser: BrowserSessionController
    private let api: IPCAApiClient
    private let queue: LocalQueueStore
    private let cursorStore: CursorStore

    init(state: AppStateController, browser: BrowserSessionController, api: IPCAApiClient, queue: LocalQueueStore, cursorStore: CursorStore) {
        self.state = state
        self.browser = browser
        self.api = api
        self.queue = queue
        self.cursorStore = cursorStore
    }

    func repair() async -> RepairReport {
        let snapshot = await MainActor.run {
            (
                isOnline: state?.network.isOnline ?? true,
                hasToken: state?.keychain.loadToken() != nil
            )
        }
        if snapshot.isOnline == false {
            return RepairReport(state: .offline, summary: "The Mac is offline.", recommendedAction: "Reconnect the Mac to the internet.")
        }
        if !snapshot.hasToken {
            return RepairReport(state: .needsIPCAToken, summary: "No IPCA Sync Agent token is saved.", recommendedAction: "Paste the device token in Settings.")
        }
        if !queue.isHealthy() {
            return RepairReport(state: .localQueueRepairNeeded, summary: "The local upload queue could not be read.", recommendedAction: "Contact IPCA support before resetting anything.")
        }
        do {
            _ = try browser.ensureChromeAvailable()
        } catch {
            return RepairReport(state: .chromeRepairNeeded, summary: "Google Chrome Stable is not installed.", recommendedAction: "Install Google Chrome Stable.")
        }
        do {
            _ = try await api.validateToken()
        } catch {
            return RepairReport(state: .ipcaUnavailable, summary: "IPCA.training could not validate the saved token.", recommendedAction: "Check IPCA.training connectivity or re-enter the token.")
        }
        let pending = (try? queue.pendingCount()) ?? 0
        return RepairReport(state: .healthy, summary: "Chrome, IPCA.training, token, and local queue are ready. Pending uploads: \(pending).", recommendedAction: pending > 0 ? "Click Sync Now." : "No action needed.")
    }
}
