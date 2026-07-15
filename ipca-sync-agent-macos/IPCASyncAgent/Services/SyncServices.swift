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
            "source_uuids": item.sourceUUIDs
        ]
        _ = try await jsonRequest(path: "/api/sync-agent/garmin_entries.php", body: body)
    }

    func uploadSource(_ queueItem: UploadQueueItem) async throws -> String {
        guard let fileData = FileManager.default.contents(atPath: queueItem.localPath) else {
            throw IPCAApiError.server("The downloaded Garmin source file is missing locally.")
        }
        let body: [String: Any] = [
            "provider": queueItem.provider,
            "entry_id": queueItem.entryID,
            "source_uuid": queueItem.sourceUUID,
            "idempotency_key": queueItem.idempotencyKey,
            "sha256": queueItem.sha256,
            "byte_size": queueItem.byteSize,
            "filename": URL(fileURLWithPath: queueItem.localPath).lastPathComponent,
            "content_base64": fileData.base64EncodedString()
        ]
        let response = try await jsonRequest(path: "/api/sync-agent/garmin_source.php", body: body)
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

    private func jsonRequest(path: String, body: [String: Any]) async throws -> [String: Any] {
        guard let base = settings.validatedAPIBaseURL else { throw IPCAApiError.invalidBaseURL }
        guard let token = keychain.loadToken(), !token.isEmpty else { throw IPCAApiError.missingToken }
        guard let url = URL(string: path, relativeTo: base)?.absoluteURL else { throw IPCAApiError.invalidBaseURL }
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        request.httpBody = try JSONSerialization.data(withJSONObject: body, options: [])
        let (data, response) = try await URLSession.shared.data(for: request)
        let statusCode = (response as? HTTPURLResponse)?.statusCode ?? 0
        let decoded = (try? JSONSerialization.jsonObject(with: data)) as? [String: Any] ?? [:]
        guard (200...299).contains(statusCode) else {
            throw IPCAApiError.server((decoded["error"] as? String) ?? "IPCA.training returned HTTP \(statusCode).")
        }
        return decoded
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
    @Published var browserStatus = "Not Open"
    @Published var networkStatus = "Unknown"
    @Published var lastSuccessfulSync: Date?
    @Published var nextScheduledSync: Date?
    @Published var newEntriesFound = 0
    @Published var filesDownloaded = 0
    @Published var filesUploaded = 0
    @Published var pendingUploads = 0
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

    init() {
        let localQueue: LocalQueueStore
        do {
            localQueue = try LocalQueueStore()
        } catch {
            LoggingService.shared.error("Could not open durable local queue database: \(error.localizedDescription)")
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

        let localCoordinator = SyncCoordinator(state: self, provider: localGarminProvider, api: localAPI, queue: localQueue, cursorStore: cursorStore, keychain: keychain)
        let localScheduler = SyncScheduler(state: self, coordinator: localCoordinator, settings: settings)
        let localRepairService = RepairService(state: self, browser: browser, api: localAPI, queue: localQueue, cursorStore: cursorStore)

        coordinator = localCoordinator
        scheduler = localScheduler
        repairService = localRepairService
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
            let result = try await provider.verifyConnection()
            state?.garminStatus = result.connected ? "Connected" : "Action Required"
            state?.status = result.connected ? .connected : .actionRequired
            LoggingService.shared.info("Garmin verified with \(result.entryCount) visible entries.")
        } catch {
            state?.garminStatus = "Garmin Authentication Required"
            state?.notifications.notify(title: "Garmin Login Required", body: "Open IPCA Sync Agent and reconnect Garmin.")
            state?.setError(error.localizedDescription)
        }
    }

    @MainActor
    func syncNow(manual: Bool = false) async {
        if isSyncing { return }
        isSyncing = true
        defer { isSyncing = false }
        do {
            state?.status = .syncing
            state?.lastError = "Reading Garmin Logbook..."
            let entries = try await provider.discoverNewItems()
            state?.newEntriesFound = entries.count
            state?.lastError = entries.isEmpty ? "No new Garmin entries with source files were found." : "Found \(entries.count) Garmin entr\(entries.count == 1 ? "y" : "ies"). Downloading source files..."
            for entry in entries {
                state?.status = .downloading
                state?.lastError = "Downloading Garmin sources for entry \(entry.entryID)..."
                let artifacts = try await provider.download(item: entry)
                state?.filesDownloaded += artifacts.count
                for artifact in artifacts {
                    try queue.enqueue(artifact)
                }
            }
            state?.pendingUploads = (try? queue.pendingCount()) ?? 0
            if keychain.loadToken() == nil {
                state?.lastError = "Garmin files were downloaded locally. Paste the IPCA Sync Agent device token to upload them."
            } else {
                state?.lastError = "Uploading pending Garmin files to IPCA.training..."
                for entry in entries {
                    try await api.uploadEntry(entry)
                }
                try await uploadPending()
                try await api.completeSync(provider: provider.identifier, cursor: cursorStore.cursor(provider: provider.identifier))
                state?.lastError = ""
            }
            state?.lastSuccessfulSync = Date()
            state?.nextScheduledSync = Date().addingTimeInterval(TimeInterval((state?.settings.syncIntervalMinutes ?? 10) * 60))
            state?.status = .idle
        } catch {
            state?.setError(error.localizedDescription)
        }
    }

    @MainActor
    func uploadPending() async throws {
        state?.status = .uploading
        let items = try queue.pending()
        state?.pendingUploads = items.count
        for item in items {
            do {
                try queue.markUploading(item.id)
                let status = try await api.uploadSource(item)
                try queue.markServerResult(item.id, status: status)
                state?.filesUploaded += 1
            } catch {
                try queue.markRetry(item.id, error: error.localizedDescription, attempts: item.attempts + 1)
                LoggingService.shared.error("Upload retry scheduled for \(item.sourceUUID): \(error.localizedDescription)")
            }
        }
        state?.pendingUploads = (try? queue.pendingCount()) ?? 0
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
