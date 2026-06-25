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
        if aircraftType.isEmpty {
            return name
        }
        return "\(name) (\(aircraftType))"
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
    var altimeterSettingInHg: Double?
    var airportElevationFt: Double?
    var oatC: Double?
    var fileSize: Int64
    var uploadStatus: UploadStatus
    var transcriptStatus: TranscriptStatus
    var uploadProgress: Double
    var transcriptProgress: Int
    var language: String
    var transcript: String
    var lastError: String
    var ahrsSamplesPath: String?
    var gpsSamplesPath: String?

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
        if !name.isEmpty {
            return name
        }
        if !registration.isEmpty {
            return registration
        }
        return "Not selected"
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
    var isAcceptedExternalInput: Bool
    var isBuiltInMic: Bool
}

enum AHRSConnectionState: String {
    case disconnected
    case scanning
    case connecting
    case connected
}

struct AHRSSample: Codable, Equatable {
    var timestamp: Date
    var secondsSinceRecordingStart: Double
    var roll: Double
    var pitch: Double
    var yaw: Double
    var acceleration: Double
    var magneticHeading: Double
    var rotationVectorAccuracy: Int?
    var magneticFieldAccuracy: Int?
    var magneticX: Double?
    var magneticY: Double?
    var magneticZ: Double?
    var headingQuality: Int?
    var aviationPitch: Double?
    var aviationRoll: Double?
    var calibratedPitch: Double?
    var calibratedRoll: Double?
    var pitchLevelOffset: Double?
    var rollLevelOffset: Double?
    var compassDeviation: Double?
    var magneticVariation: Double?
    var correctedMagneticHeading: Double?
    var trueHeading: Double?
    var rawLine: String
}

struct AHRSCalibration: Codable, Equatable {
    var pitchLevelOffset: Double
    var rollLevelOffset: Double
    var compassDeviation: Double
    var magneticVariation: Double
    var levelReferenceSetAt: Date?
    var magneticHeadingReferenceSetAt: Date?
    var knownMagneticHeadingUsed: Double?
    var rawCompassHeadingAtCalibration: Double?

    static let empty = AHRSCalibration(
        pitchLevelOffset: 0,
        rollLevelOffset: 0,
        compassDeviation: 0,
        magneticVariation: 0,
        levelReferenceSetAt: nil,
        magneticHeadingReferenceSetAt: nil,
        knownMagneticHeadingUsed: nil,
        rawCompassHeadingAtCalibration: nil
    )
}

enum GPSConnectionState: String {
    case unavailable
    case permissionNeeded
    case ready
    case recording
    case denied
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
