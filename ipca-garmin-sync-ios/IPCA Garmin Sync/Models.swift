import Foundation

enum IngestionState: String, Codable, CaseIterable {
    case discovered = "DISCOVERED"
    case copying = "COPYING"
    case localVerified = "LOCAL_VERIFIED"
    case waitingForUpload = "WAITING_FOR_UPLOAD"
    case uploading = "UPLOADING"
    case serverVerified = "SERVER_VERIFIED"
    case failed = "FAILED"
}

struct ScannedFile: Identifiable, Hashable {
    let relativePath: String
    let size: Int64
    let modificationDate: Date
    var id: String { relativePath }
}

struct IngestionFile: Identifiable, Hashable {
    let id: UUID
    let relativePath: String
    let originalFilename: String
    let sourceSize: Int64
    let sourceModificationDate: Date
    let sourceHash: String
    let localPath: String?
    let localSize: Int64?
    let destinationHash: String?
    let state: IngestionState
    let uploadID: UUID
    let uploadedBytes: Int64
    let localVerificationStatus: String
    let uploadStatus: String
    let serverObjectID: String?
    let serverReceiptUUID: String?
    let serverReceiptJSON: String?
    let serverVerificationStatus: String
    let retryCount: Int
    let errorMessage: String?
    let firstSeen: Date
    let lastSeen: Date
    let createdAt: Date
    let updatedAt: Date
}

struct ScanSnapshot: Identifiable, Hashable {
    let id: UUID
    let startedAt: Date
    let completedAt: Date
    let folderDisplayName: String
    let memberCount: Int
    let deviceID: String
    let foundCount: Int
    let previouslyKnownCount: Int
    let newlyCopiedCount: Int
    let completionStatus: String
}

struct ScanSnapshotMember: Hashable {
    let snapshotID: UUID
    let fileID: UUID
    let relativePath: String
    let originalFilename: String
    let size: Int64
    let modificationDate: Date
    let sourceHash: String
}

struct ScanProgress: Sendable {
    enum Phase: String, Sendable {
        case scanning = "Scanning"
        case hashing = "Hashing"
        case copying = "Copying"
    }

    let phase: Phase
    let current: Int
    let total: Int
    let path: String
    let filesChecked: Int
    let newFiles: Int
    let copiedFiles: Int
    let errors: Int
}

struct CardSnapshotResult {
    let snapshotID: UUID?
    let files: [IngestionFile]
    let safeToEject: Bool
    let foundCount: Int
    let previouslyKnownCount: Int
    let newlyCopiedCount: Int
    let errorCount: Int
}

struct UploadProgress: Sendable {
    let verifiedFiles: Int
    let totalFiles: Int
    let currentFilename: String
    let uploadedChunks: Int
    let totalChunks: Int
}

enum GarminSyncError: LocalizedError {
    case bookmarkUnavailable
    case invalidFolder
    case sourceChanged(String)
    case verificationFailed(String)
    case serverRejected(String)
    case invalidServerResponse

    var errorDescription: String? {
        switch self {
        case .bookmarkUnavailable: "The selected folder is no longer available. Choose it again."
        case .invalidFolder: "The selected location is not a readable folder."
        case .sourceChanged(let path): "The source changed while copying \(path). Scan again."
        case .verificationFailed(let path): "Local verification failed for \(path)."
        case .serverRejected(let reason): "The server rejected the upload: \(reason)"
        case .invalidServerResponse: "The server returned an invalid response."
        }
    }
}
