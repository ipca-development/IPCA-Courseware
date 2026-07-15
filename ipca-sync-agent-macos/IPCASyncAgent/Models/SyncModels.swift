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

struct ProviderVerificationResult: Codable {
    var connected: Bool
    var entryCount: Int
    var cursor: String?
    var topLevelKeys: [String]
    var firstSourceUUID: String?
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
    var rawEntry: [String: JSONValue]
}

struct DownloadedArtifact: Codable, Identifiable {
    var id: String { sourceUUID }
    var provider: String
    var entryID: String
    var sourceUUID: String
    var originalFilename: String
    var contentType: String
    var contentDisposition: String?
    var localPath: String
    var byteSize: Int
    var sha256: String
    var downloadedAt: Date

    var idempotencyKey: String {
        "\(provider):\(entryID):\(sourceUUID):\(sha256)"
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
    var idempotencyKey: String
    var localPath: String
    var sha256: String
    var byteSize: Int
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
