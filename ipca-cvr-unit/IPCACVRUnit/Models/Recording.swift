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

struct CVRRecordingEvent: Identifiable, Codable, Equatable {
    var id: String
    var timestamp: Date
    var severity: String
    var type: String
    var message: String
    var durationSeconds: Double?
    var metadata: [String: String]

    init(
        id: String = UUID().uuidString,
        timestamp: Date = Date(),
        severity: String,
        type: String,
        message: String,
        durationSeconds: Double? = nil,
        metadata: [String: String] = [:]
    ) {
        self.id = id
        self.timestamp = timestamp
        self.severity = severity
        self.type = type
        self.message = message
        self.durationSeconds = durationSeconds
        self.metadata = metadata
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
    var gpsSamplesPath: String?
    var beaconDiagnosticsPath: String?
    var recordingEventsPath: String?
    var flightSessionID: String
    var segmentIndex: Int
    var previousSegmentID: String?
    var isTestRecording: Bool
    var sourceGapSummary: String?
    var uploadRetryCount: Int?
    var nextUploadRetryAt: Date?

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
        gpsSamplesPath: String? = nil,
        beaconDiagnosticsPath: String? = nil,
        recordingEventsPath: String? = nil,
        flightSessionID: String? = nil,
        segmentIndex: Int = 1,
        previousSegmentID: String? = nil,
        isTestRecording: Bool = false,
        sourceGapSummary: String? = nil,
        uploadRetryCount: Int? = nil,
        nextUploadRetryAt: Date? = nil
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
        self.gpsSamplesPath = gpsSamplesPath
        self.beaconDiagnosticsPath = beaconDiagnosticsPath
        self.recordingEventsPath = recordingEventsPath
        self.flightSessionID = flightSessionID ?? id
        self.segmentIndex = max(1, segmentIndex)
        self.previousSegmentID = previousSegmentID
        self.isTestRecording = isTestRecording
        self.sourceGapSummary = sourceGapSummary
        self.uploadRetryCount = uploadRetryCount
        self.nextUploadRetryAt = nextUploadRetryAt
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

    func shouldAttemptUpload(now: Date = Date()) -> Bool {
        switch uploadStatus {
        case .pending, .uploading:
            return true
        case .failed:
            guard let nextUploadRetryAt else { return true }
            return nextUploadRetryAt <= now
        case .uploaded:
            return false
        }
    }
}

enum GPSConnectionState: String {
    case permissionNeeded
    case ready
    case recording
    case denied
    case unavailable
    case failed
}

struct GPSSample: Codable, Equatable {
    var timestamp: Date
    var secondsSinceRecordingStart: Double
    var latitude: Double
    var longitude: Double
    var altitude: Double
    var speedMetersPerSecond: Double
    var speedKnots: Double
    var course: Double
    var horizontalAccuracy: Double
    var verticalAccuracy: Double
}

struct AudioInputInfo: Identifiable, Equatable {
    var id: String
    var name: String
    var portType: String
    var isUSB: Bool
    var isAcceptedExternalInput: Bool
    var isBuiltInMic: Bool
}
