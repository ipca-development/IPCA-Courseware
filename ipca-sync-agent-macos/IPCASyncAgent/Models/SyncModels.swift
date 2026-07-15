import CryptoKit
import Foundation

enum AgentStatus: String, CaseIterable {
    case notConfigured = "Not Configured"
    case connecting = "Connecting"
    case waitingForDeviceToken = "Waiting for Device Token"
    case waitingForGarminLogin = "Waiting for Garmin Login"
    case verifyingGarmin = "Verifying Garmin"
    case connected = "Connected"
    case syncing = "Syncing"
    case downloading = "Downloading"
    case uploading = "Uploading"
    case idle = "Idle"
    case offline = "Offline"
    case ipcaUnavailable = "IPCA.training Unavailable"
    case garminAuthenticationRequired = "Garmin Authentication Required"
    case actionRequired = "Action Required"
    case error = "Error"
}

enum ProviderConnectionStatus: String, Codable {
    case unknown
    case disconnected
    case waitingForLogin
    case connected
    case authenticationRequired
    case error
}

enum GarminAuthenticationState: String {
    case connected = "Connected"
    case loginRequired = "Login Required"
    case unknown = "Unknown"
}

enum GarminCursorState: String {
    case ready = "Ready"
    case initialSyncRequired = "Initial Sync Required"
    case invalid = "Invalid"
}

enum GarminSyncState: String {
    case idle = "Idle"
    case checkingForUpdates = "Checking for updates"
    case noNewFlights = "No new Garmin flights"
    case changedEntriesFound = "Changed entries found"
    case initialBootstrapRequired = "Initial bootstrap required"
    case error = "Error"
}

struct ProviderVerificationResult: Codable {
    var connected: Bool
    var entryCount: Int
    var cursor: String?
    var topLevelKeys: [String]
    var firstSourceUUID: String?
}

struct GarminDebugSummary: Codable {
    var entryCount: Int
    var topLevelKeys: [String]
    var entrySummaries: [String]
}

struct RemoteSyncItem: Codable, Identifiable {
    var id: String { entryID }
    var provider: String
    var entryID: String
    var version: String?
    var aircraftRegistration: String?
    var generatedTrackStart: String?
    var generatedTrackStop: String?
    var sourceUUIDs: [String]
    var trackUUIDs: [String]
    var rawEntry: [String: JSONValue]
}

struct GarminBackfillDiscoveryResult: Codable {
    var inspectedEntryCount: Int
    var entriesWithTracksCount: Int
    var selectedItemCount: Int
    var skippedSeenCount: Int
    var skippedMissingTrackCount: Int
    var remainingEstimate: Int?
    var items: [RemoteSyncItem]
    var sourceDescription: String
    var responseVersion: String?
}

enum GarminBackfillTrackState: String, Codable {
    case seen
    case queued
    case downloaded
    case uploaded
    case failed
    case ignoredGPSOnly = "ignored_gps_only"
}

struct GarminTrackResponse: Codable {
    var formatVersion: Int?
    var sessions: [GarminTrackSession]
}

struct GarminTrackSession: Codable {
    var fields: [GarminTrackField]
    var data: [JSONValue]
    var sources: [GarminTrackSource]?
}

struct GarminTrackField: Codable {
    var fieldType: String?
    var engine: Int?
}

struct GarminTrackSource: Codable {
    var type: String?
    var name: String?
}

struct GarminTrackMetrics: Codable {
    var responseByteCount: Int
    var sessionCount: Int
    var totalFieldCount: Int
    var rowsPerSession: [Int]
    var totalTelemetryRows: Int
    var firstTimestamp: String?
    var lastTimestamp: String?
}

struct DownloadedArtifact: Codable, Identifiable {
    var id: String { sourceUUID }
    var provider: String
    var entryID: String
    var sourceUUID: String
    var artifactType: String
    var originalFilename: String
    var contentType: String
    var contentDisposition: String?
    var localPath: String
    var byteSize: Int
    var sha256: String
    var sourceClassification: String
    var metadata: [String: JSONValue]
    var downloadedAt: Date

    var idempotencyKey: String {
        let raw = "\(provider):\(entryID):\(artifactType):\(sourceUUID):\(sha256)"
        let data = Data(raw.utf8)
        return SHA256.hash(data: data).map { String(format: "%02x", $0) }.joined()
    }
}

enum QueueState: String, Codable {
    case discovered
    case downloaded
    case queued
    case uploading
    case uploaded
    case alreadyExists = "already_exists"
    case reviewRequired = "review_required"
    case retryWait = "retry_wait"
    case failed
    case completed
}

struct UploadQueueItem: Identifiable, Codable {
    var id: Int64
    var provider: String
    var entryID: String
    var sourceUUID: String
    var artifactType: String
    var idempotencyKey: String
    var localPath: String
    var sha256: String
    var byteSize: Int
    var contentType: String
    var metadataJSON: String?
    var state: QueueState
    var attempts: Int
    var nextRetryAt: Date?
    var lastError: String?
    var serverStatus: String?
    var createdAt: Date
    var updatedAt: Date
    var completedAt: Date?
}

enum RepairState: String {
    case healthy = "Healthy"
    case needsGarminLogin = "Needs Garmin Login"
    case needsIPCAToken = "Needs IPCA Token"
    case offline = "Offline"
    case ipcaUnavailable = "IPCA.training Unavailable"
    case chromeRepairNeeded = "Chrome Repair Needed"
    case localQueueRepairNeeded = "Local Queue Repair Needed"
    case manualReviewNeeded = "Manual Review Needed"
}

struct RepairReport {
    var state: RepairState
    var summary: String
    var recommendedAction: String
}

enum JSONValue: Codable {
    case string(String)
    case number(Double)
    case bool(Bool)
    case object([String: JSONValue])
    case array([JSONValue])
    case null

    init(from decoder: Decoder) throws {
        let container = try decoder.singleValueContainer()
        if container.decodeNil() {
            self = .null
        } else if let value = try? container.decode(Bool.self) {
            self = .bool(value)
        } else if let value = try? container.decode(Double.self) {
            self = .number(value)
        } else if let value = try? container.decode(String.self) {
            self = .string(value)
        } else if let value = try? container.decode([String: JSONValue].self) {
            self = .object(value)
        } else {
            self = .array(try container.decode([JSONValue].self))
        }
    }

    func encode(to encoder: Encoder) throws {
        var container = encoder.singleValueContainer()
        switch self {
        case .string(let value): try container.encode(value)
        case .number(let value): try container.encode(value)
        case .bool(let value): try container.encode(value)
        case .object(let value): try container.encode(value)
        case .array(let value): try container.encode(value)
        case .null: try container.encodeNil()
        }
    }

    var string: String? {
        if case .string(let value) = self { return value }
        return nil
    }

    var int: Int? {
        if case .number(let value) = self { return Int(value) }
        return nil
    }

    var bool: Bool? {
        if case .bool(let value) = self { return value }
        return nil
    }

    var object: [String: JSONValue]? {
        if case .object(let value) = self { return value }
        return nil
    }

    var array: [JSONValue]? {
        if case .array(let value) = self { return value }
        return nil
    }
}
