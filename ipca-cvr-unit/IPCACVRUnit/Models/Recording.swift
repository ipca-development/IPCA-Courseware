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

struct CockpitAircraft: Identifiable, Codable, Equatable {
    var id: Int
    var registration: String
    var displayName: String
    var aircraftType: String
    var adsbHex: String
    var homeAirport: String
    var active: Bool

    var label: String {
        let name = displayName.isEmpty ? registration : displayName
        return aircraftType.isEmpty ? name : "\(name) (\(aircraftType))"
    }

    enum CodingKeys: String, CodingKey {
        case id
        case registration
        case displayName = "display_name"
        case aircraftType = "aircraft_type"
        case adsbHex = "adsb_hex"
        case homeAirport = "home_airport"
        case active
    }
}

struct Recording: Identifiable, Codable, Equatable {
    var id: String
    var serverID: String?
    var startedAt: Date
    var duration: TimeInterval
    var filePath: String
    var inputDeviceName: String
    var aircraftID: Int?
    var aircraftRegistration: String?
    var aircraftDisplayName: String?
    var aircraftType: String?
    var aircraftADSBHex: String?
    var fileSize: Int64
    var uploadStatus: UploadStatus
    var transcriptStatus: TranscriptStatus
    var uploadProgress: Double
    var transcriptProgress: Int
    var language: String
    var transcript: String
    var lastError: String
    var flightSessionID: String
    var segmentIndex: Int
    var previousSegmentID: String?
    var isTestRecording: Bool
    var sourceGapSummary: String?

    init(
        id: String,
        serverID: String?,
        startedAt: Date,
        duration: TimeInterval,
        filePath: String,
        inputDeviceName: String,
        aircraftID: Int?,
        aircraftRegistration: String?,
        aircraftDisplayName: String?,
        aircraftType: String?,
        aircraftADSBHex: String?,
        fileSize: Int64,
        uploadStatus: UploadStatus,
        transcriptStatus: TranscriptStatus,
        uploadProgress: Double,
        transcriptProgress: Int,
        language: String,
        transcript: String,
        lastError: String,
        flightSessionID: String? = nil,
        segmentIndex: Int = 1,
        previousSegmentID: String? = nil,
        isTestRecording: Bool = false,
        sourceGapSummary: String? = nil
    ) {
        self.id = id
        self.serverID = serverID
        self.startedAt = startedAt
        self.duration = duration
        self.filePath = filePath
        self.inputDeviceName = inputDeviceName
        self.aircraftID = aircraftID
        self.aircraftRegistration = aircraftRegistration
        self.aircraftDisplayName = aircraftDisplayName
        self.aircraftType = aircraftType
        self.aircraftADSBHex = aircraftADSBHex
        self.fileSize = fileSize
        self.uploadStatus = uploadStatus
        self.transcriptStatus = transcriptStatus
        self.uploadProgress = uploadProgress
        self.transcriptProgress = transcriptProgress
        self.language = language
        self.transcript = transcript
        self.lastError = lastError
        self.flightSessionID = flightSessionID ?? id
        self.segmentIndex = max(1, segmentIndex)
        self.previousSegmentID = previousSegmentID
        self.isTestRecording = isTestRecording
        self.sourceGapSummary = sourceGapSummary
    }

    var fileURL: URL {
        (try? RecordingStore.resolvedFileURL(
            preferredPath: filePath,
            recordingID: id,
            fallbackFilename: "\(id).m4a"
        )) ?? URL(fileURLWithPath: filePath)
    }

    var aircraftLabel: String {
        let name = aircraftDisplayName?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
        let registration = aircraftRegistration?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
        if !name.isEmpty { return name }
        if !registration.isEmpty { return registration }
        return "Dedicated aircraft not selected"
    }

    var statusLabel: String {
        if transcriptStatus == .ready || transcriptStatus == .transcribing {
            return transcriptStatus.label
        }
        if uploadStatus == .failed || transcriptStatus == .failed {
            return "Failed"
        }
        return uploadStatus.label
    }

    var needsUploadRetry: Bool {
        uploadStatus == .pending || uploadStatus == .failed || uploadStatus == .uploading
    }
}

struct AudioInputInfo: Identifiable, Equatable {
    var id: String
    var name: String
    var portType: String
    var isUSB: Bool
    var isAcceptedExternalInput: Bool
    var isBuiltInMic: Bool
}
