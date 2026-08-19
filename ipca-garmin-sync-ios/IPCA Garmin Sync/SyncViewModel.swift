import Foundation
import OSLog
import UIKit

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

    enum EnrollmentStatus: Equatable {
        case notEnrolled
        case enrolling
        case enrolled
        case failed(String)

        var displayText: String {
            switch self {
            case .notEnrolled: "Not enrolled"
            case .enrolling: "Enrolling…"
            case .enrolled: "Enrolled"
            case .failed(let message): "Enrollment failed: \(message)"
            }
        }
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
    @Published var serverURL: String
    @Published var enrollmentCode = ""
    @Published var enrollmentStatus: EnrollmentStatus

    let store: LocalIngestionStore
    private let access: ExternalStorageAccessService
    private let privateDirectory: URL
    private let defaults: UserDefaults
    private let credentialStore: any GarminSyncCredentialStoring
    private let enrollmentSession: URLSession
    private let logger = Logger(subsystem: "com.ipca.garmin-sync", category: "workflow")
    private var retryDestination: WizardStep = .chooseCard

    init(
        supportDirectory: URL? = nil,
        defaults: UserDefaults = .standard,
        credentialStore: any GarminSyncCredentialStoring = GarminSyncKeychainCredentialStore(),
        enrollmentSession: URLSession = .shared
    ) {
        let support = supportDirectory ?? FileManager.default.urls(
            for: .applicationSupportDirectory,
            in: .userDomainMask
        )[0].appendingPathComponent("IPCA Garmin Sync", isDirectory: true)
        self.defaults = defaults
        self.credentialStore = credentialStore
        self.enrollmentSession = enrollmentSession
        access = ExternalStorageAccessService(defaults: defaults)
        privateDirectory = support.appendingPathComponent("Files", isDirectory: true)
        store = try! LocalIngestionStore(databaseURL: support.appendingPathComponent("ingestion.sqlite"))
        serverURL = defaults.string(forKey: "garminSync.serverURL") ?? "https://ipca.training"
        enrollmentStatus = ((try? credentialStore.load()) ?? nil)?.isEmpty == false
            ? .enrolled
            : .notEnrolled
    }

    func recoverOnLaunch() async {
        do {
            recoverEnrollmentStatus()
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
        guard let baseURL = validatedServerURL() else {
            retryDestination = .readyToSynchronize
            step = .failed("Enter a valid server URL in Settings.")
            return
        }
        guard let credential = try? credentialStore.load(), !credential.isEmpty else {
            retryDestination = .readyToSynchronize
            enrollmentStatus = .notEnrolled
            step = .failed("Enroll Garmin Sync in Settings before synchronizing.")
            return
        }
        saveSettings()
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
        defaults.set(serverURL, forKey: "garminSync.serverURL")
        return true
    }

    func enroll() {
        let code = enrollmentCode.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !code.isEmpty else {
            enrollmentStatus = .failed("Enter the one-time enrollment code.")
            return
        }
        guard let baseURL = validatedServerURL() else {
            enrollmentStatus = .failed("Enter a valid server URL.")
            return
        }
        saveSettings()
        enrollmentStatus = .enrolling
        let service = GarminSyncEnrollmentService(
            baseURL: baseURL,
            credentialStore: credentialStore,
            session: enrollmentSession
        )
        let deviceUUID = persistentDeviceID()
        let displayName = "IPCA Garmin Sync – \(UIDevice.current.name)"
        Task {
            do {
                _ = try await service.enroll(
                    code: code,
                    deviceUUID: deviceUUID,
                    displayName: displayName
                )
                enrollmentCode = ""
                enrollmentStatus = .enrolled
            } catch {
                enrollmentStatus = .failed(error.localizedDescription)
            }
        }
    }

    func refreshDebugData() async {
        files = (try? await store.allFiles()) ?? []
        snapshots = (try? await store.snapshots()) ?? []
    }

    private func persistentDeviceID() -> String {
        let key = "garminSync.deviceID"
        if let existing = defaults.string(forKey: key) { return existing }
        let created = UUID().uuidString
        defaults.set(created, forKey: key)
        return created
    }

    private func validatedServerURL() -> URL? {
        guard let components = URLComponents(string: serverURL),
              ["http", "https"].contains(components.scheme?.lowercased() ?? ""),
              components.host != nil else {
            return nil
        }
        return components.url
    }

    private func recoverEnrollmentStatus() {
        enrollmentStatus = ((try? credentialStore.load()) ?? nil)?.isEmpty == false
            ? .enrolled
            : .notEnrolled
    }
}
