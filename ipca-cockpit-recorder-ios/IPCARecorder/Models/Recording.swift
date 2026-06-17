import Foundation

enum UploadStatus: String, Codable, CaseIterable {
    case pending
    case uploading
    case uploaded
    case failed

    var label: String {
        switch self {
        case .pending: "Pending"
        case .uploading: "Uploading"
        case .uploaded: "Uploaded"
        case .failed: "Failed"
        }
    }
}

enum TranscriptStatus: String, Codable, CaseIterable {
    case pending
    case transcribing
    case ready
    case failed

    var label: String {
        switch self {
        case .pending: "Pending"
        case .transcribing: "Transcribing"
        case .ready: "Transcript Ready"
        case .failed: "Failed"
        }
    }
}

struct Recording: Identifiable, Codable, Equatable {
    var id: String
    var serverID: String?
    var startedAt: Date
    var duration: TimeInterval
    var filePath: String
    var inputDeviceName: String
    var fileSize: Int64
    var uploadStatus: UploadStatus
    var transcriptStatus: TranscriptStatus
    var uploadProgress: Double
    var transcriptProgress: Int
    var language: String
    var transcript: String
    var lastError: String

    var fileURL: URL {
        URL(fileURLWithPath: filePath)
    }

    var statusLabel: String {
        if transcriptStatus == .ready {
            return transcriptStatus.label
        }
        if transcriptStatus == .transcribing {
            return transcriptStatus.label
        }
        if uploadStatus == .failed || transcriptStatus == .failed {
            return "Failed"
        }
        return uploadStatus.label
    }
}

struct AudioInputInfo: Identifiable, Equatable {
    var id: String
    var name: String
    var portType: String
    var isUSB: Bool
    var isBuiltInMic: Bool
}
