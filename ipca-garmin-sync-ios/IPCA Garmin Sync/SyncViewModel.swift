import Foundation
import OSLog

@MainActor
final class SyncViewModel: ObservableObject {
    enum WizardStep: Equatable {
        case chooseCard
        case capturing
        case returnCard
        case readyToSynchronize
        case uploading
        case complete
        case failed(String)
    }

    @Published var step: WizardStep = .chooseCard
    @Published var progressTitle = ""
    @Published var progressDetail = ""
    @Published var progressFraction = 0.0
    @Published var selectedFolder: URL?
    @Published var files: [IngestionFile] = []
    @Published var snapshots: [ScanSnapshot] = []
    @Published var filesFound = 0
    @Published var filesPreviouslyKnown = 0
    @Published var filesNewlyCopied = 0
    @Published var captureErrors = 0
    @Published var uploadVerifiedFiles = 0
    @Published var uploadTotalFiles = 0
    @Published var serverURL = UserDefaults.standard.string(forKey: "garminSync.serverURL") ?? ""
    @Published var credential = SecureCredentialStore.load()

    let store: LocalIngestionStore
    private let access = ExternalStorageAccessService()
    private let privateDirectory: URL
    private let logger = Logger(subsystem: "com.ipca.garmin-sync", category: "workflow")
    private var retryDestination: WizardStep = .chooseCard

    init() {
        let support = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]
            .appendingPathComponent("IPCA Garmin Sync", isDirectory: true)
        privateDirectory = support.appendingPathComponent("Files", isDirectory: true)
        store = try! LocalIngestionStore(databaseURL: support.appendingPathComponent("ingestion.sqlite"))
    }

    func recoverOnLaunch() async {
        do {
            try await store.recoverInterruptedWork(partialDirectory: privateDirectory)
            selectedFolder = try? access.restoreFolder()
            await refreshDebugData()
            if files.contains(where: { [.localVerified, .waitingForUpload, .uploading].contains($0.state) }) {
                step = .readyToSynchronize
            }
        } catch {
            step = .failed(error.localizedDescription)
        }
    }

    func selectFolder(_ url: URL) {
        do {
            try access.persistBookmark(for: url)
            selectedFolder = url
            step = .chooseCard
        } catch {
            step = .failed(error.localizedDescription)
        }
    }

    func captureCard() {
        guard let folder = selectedFolder else { return }
        retryDestination = .chooseCard
        step = .capturing
        progressFraction = 0
        let service = CardSnapshotService(
            access: access,
            scanner: GarminCardScanner(),
            hashService: FileHashService(),
            copyService: GarminCopyService(),
            store: store,
            privateDirectory: privateDirectory,
            deviceID: persistentDeviceID()
        )
        Task {
            do {
                let result = try await service.capture(folder: folder) { progress in
                    Task { @MainActor in
                        self.progressTitle = progress.phase.rawValue
                        self.progressDetail = """
                        Checked \(progress.filesChecked) · New \(progress.newFiles) · \
                        Copied \(progress.copiedFiles) · Errors \(progress.errors)
                        """
                        if progress.total > 0 {
                            self.progressFraction = Double(progress.current) / Double(progress.total)
                        }
                    }
                }
                await refreshDebugData()
                filesFound = result.foundCount
                filesPreviouslyKnown = result.previouslyKnownCount
                filesNewlyCopied = result.newlyCopiedCount
                captureErrors = result.errorCount
                guard result.safeToEject else {
                    throw GarminSyncError.verificationFailed("scan")
                }
                step = .returnCard
                if let snapshotID = result.snapshotID {
                    logger.info("Completed immutable card snapshot \(snapshotID.uuidString, privacy: .public)")
                }
            } catch {
                await refreshDebugData()
                step = .failed(error.localizedDescription)
            }
        }
    }

    func cardReturned() {
        guard step == .returnCard else { return }
        step = .readyToSynchronize
    }

    func synchronize() {
        guard let baseURL = URL(string: serverURL), !credential.isEmpty else {
            retryDestination = .readyToSynchronize
            step = .failed("Enter a valid server URL and credential in Settings.")
            return
        }
        guard saveSettings() else {
            retryDestination = .readyToSynchronize
            step = .failed("The credential could not be saved securely.")
            return
        }
        retryDestination = .readyToSynchronize
        step = .uploading
        let queue = UploadQueue(
            store: store,
            service: GarminUploadService(baseURL: baseURL, bearerCredential: credential)
        )
        Task {
            do {
                try await queue.run { progress in
                    Task { @MainActor in
                        self.progressTitle = "Synchronizing"
                        self.uploadVerifiedFiles = progress.verifiedFiles
                        self.uploadTotalFiles = progress.totalFiles
                        self.progressDetail = """
                        \(progress.verifiedFiles) of \(progress.totalFiles) files verified · \
                        \(progress.uploadedChunks) of \(progress.totalChunks) chunks
                        """
                        self.progressFraction = progress.totalFiles > 0
                            ? Double(progress.verifiedFiles) / Double(progress.totalFiles)
                            : 1
                    }
                }
                await refreshDebugData()
                step = .complete
            } catch {
                await refreshDebugData()
                step = .failed(error.localizedDescription)
            }
        }
    }

    func retry() {
        step = retryDestination
    }

    @discardableResult
    func saveSettings() -> Bool {
        UserDefaults.standard.set(serverURL, forKey: "garminSync.serverURL")
        do {
            try SecureCredentialStore.save(credential)
            return true
        } catch {
            return false
        }
    }

    func refreshDebugData() async {
        files = (try? await store.allFiles()) ?? []
        snapshots = (try? await store.snapshots()) ?? []
    }

    private func persistentDeviceID() -> String {
        let key = "garminSync.deviceID"
        if let existing = UserDefaults.standard.string(forKey: key) { return existing }
        let created = UUID().uuidString
        UserDefaults.standard.set(created, forKey: key)
        return created
    }
}
