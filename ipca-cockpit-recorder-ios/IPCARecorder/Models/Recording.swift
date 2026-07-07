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

    var garminImportProfile: String {
        let haystack = "\(registration) \(displayName) \(aircraftType)".uppercased()
        if haystack.contains("AL172") || haystack.contains("ALSIM") || haystack.contains("G1000") {
            return "garmin_g1000nxi"
        }
        return "garmin_g3x"
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
    var g3xCsvPath: String?
    var g3xImportedAt: Date?
    var g3xAircraftIdent: String?
    var g3xMatchMethod: String?
    var g3xRowCount: Int
    var g3xServerSynced: Bool
    var flightSessionID: String
    var segmentIndex: Int
    var previousSegmentID: String?
    var isTestRecording: Bool
    var sourceGapSummary: String?

    var garminImportProfile: String {
        let haystack = "\(aircraftRegistration ?? "") \(aircraftDisplayName ?? "") \(aircraftType ?? "")".uppercased()
        if haystack.contains("AL172") || haystack.contains("ALSIM") || haystack.contains("G1000") {
            return "garmin_g1000nxi"
        }
        return "garmin_g3x"
    }

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
        altimeterSettingInHg: Double?,
        airportElevationFt: Double?,
        oatC: Double?,
        fileSize: Int64,
        uploadStatus: UploadStatus,
        transcriptStatus: TranscriptStatus,
        uploadProgress: Double,
        transcriptProgress: Int,
        language: String,
        transcript: String,
        lastError: String,
        ahrsSamplesPath: String?,
        gpsSamplesPath: String?,
        g3xCsvPath: String? = nil,
        g3xImportedAt: Date? = nil,
        g3xAircraftIdent: String? = nil,
        g3xMatchMethod: String? = nil,
        g3xRowCount: Int = 0,
        g3xServerSynced: Bool = false,
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
        self.altimeterSettingInHg = altimeterSettingInHg
        self.airportElevationFt = airportElevationFt
        self.oatC = oatC
        self.fileSize = fileSize
        self.uploadStatus = uploadStatus
        self.transcriptStatus = transcriptStatus
        self.uploadProgress = uploadProgress
        self.transcriptProgress = transcriptProgress
        self.language = language
        self.transcript = transcript
        self.lastError = lastError
        self.ahrsSamplesPath = ahrsSamplesPath
        self.gpsSamplesPath = gpsSamplesPath
        self.g3xCsvPath = g3xCsvPath
        self.g3xImportedAt = g3xImportedAt
        self.g3xAircraftIdent = g3xAircraftIdent
        self.g3xMatchMethod = g3xMatchMethod
        self.g3xRowCount = g3xRowCount
        self.g3xServerSynced = g3xServerSynced
        self.flightSessionID = flightSessionID ?? id
        self.segmentIndex = max(1, segmentIndex)
        self.previousSegmentID = previousSegmentID
        self.isTestRecording = isTestRecording
        self.sourceGapSummary = sourceGapSummary
    }

    enum CodingKeys: String, CodingKey {
        case id
        case serverID
        case startedAt
        case duration
        case filePath
        case inputDeviceName
        case aircraftID
        case aircraftRegistration
        case aircraftDisplayName
        case aircraftType
        case aircraftADSBHex
        case altimeterSettingInHg
        case airportElevationFt
        case oatC
        case fileSize
        case uploadStatus
        case transcriptStatus
        case uploadProgress
        case transcriptProgress
        case language
        case transcript
        case lastError
        case ahrsSamplesPath
        case gpsSamplesPath
        case g3xCsvPath
        case g3xImportedAt
        case g3xAircraftIdent
        case g3xMatchMethod
        case g3xRowCount
        case g3xServerSynced
        case flightSessionID
        case segmentIndex
        case previousSegmentID
        case isTestRecording
        case sourceGapSummary
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        id = try container.decode(String.self, forKey: .id)
        serverID = try container.decodeIfPresent(String.self, forKey: .serverID)
        startedAt = try container.decode(Date.self, forKey: .startedAt)
        duration = try container.decode(TimeInterval.self, forKey: .duration)
        filePath = try container.decode(String.self, forKey: .filePath)
        inputDeviceName = try container.decode(String.self, forKey: .inputDeviceName)
        aircraftID = try container.decodeIfPresent(Int.self, forKey: .aircraftID)
        aircraftRegistration = try container.decodeIfPresent(String.self, forKey: .aircraftRegistration)
        aircraftDisplayName = try container.decodeIfPresent(String.self, forKey: .aircraftDisplayName)
        aircraftType = try container.decodeIfPresent(String.self, forKey: .aircraftType)
        aircraftADSBHex = try container.decodeIfPresent(String.self, forKey: .aircraftADSBHex)
        altimeterSettingInHg = try container.decodeIfPresent(Double.self, forKey: .altimeterSettingInHg)
        airportElevationFt = try container.decodeIfPresent(Double.self, forKey: .airportElevationFt)
        oatC = try container.decodeIfPresent(Double.self, forKey: .oatC)
        fileSize = try container.decode(Int64.self, forKey: .fileSize)
        uploadStatus = try container.decode(UploadStatus.self, forKey: .uploadStatus)
        transcriptStatus = try container.decode(TranscriptStatus.self, forKey: .transcriptStatus)
        uploadProgress = try container.decode(Double.self, forKey: .uploadProgress)
        transcriptProgress = try container.decode(Int.self, forKey: .transcriptProgress)
        language = try container.decode(String.self, forKey: .language)
        transcript = try container.decode(String.self, forKey: .transcript)
        lastError = try container.decode(String.self, forKey: .lastError)
        ahrsSamplesPath = try container.decodeIfPresent(String.self, forKey: .ahrsSamplesPath)
        gpsSamplesPath = try container.decodeIfPresent(String.self, forKey: .gpsSamplesPath)
        g3xCsvPath = try container.decodeIfPresent(String.self, forKey: .g3xCsvPath)
        g3xImportedAt = try container.decodeIfPresent(Date.self, forKey: .g3xImportedAt)
        g3xAircraftIdent = try container.decodeIfPresent(String.self, forKey: .g3xAircraftIdent)
        g3xMatchMethod = try container.decodeIfPresent(String.self, forKey: .g3xMatchMethod)
        g3xRowCount = try container.decodeIfPresent(Int.self, forKey: .g3xRowCount) ?? 0
        g3xServerSynced = try container.decodeIfPresent(Bool.self, forKey: .g3xServerSynced) ?? false
        flightSessionID = try container.decodeIfPresent(String.self, forKey: .flightSessionID) ?? id
        segmentIndex = max(1, try container.decodeIfPresent(Int.self, forKey: .segmentIndex) ?? 1)
        previousSegmentID = try container.decodeIfPresent(String.self, forKey: .previousSegmentID)
        isTestRecording = try container.decodeIfPresent(Bool.self, forKey: .isTestRecording) ?? false
        sourceGapSummary = try container.decodeIfPresent(String.self, forKey: .sourceGapSummary)
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
        if !name.isEmpty {
            return name
        }
        if !registration.isEmpty {
            return registration
        }
        return "Not selected"
    }

    var flightSessionLabel: String {
        if segmentIndex <= 1 {
            return flightSessionID == id ? "Single segment" : "Flight segment 1"
        }
        return "Flight segment \(segmentIndex)"
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

    var needsUploadRetry: Bool {
        uploadStatus == .pending || uploadStatus == .failed || uploadStatus == .uploading
    }

    var hasG3XData: Bool {
        g3xCsvPath != nil
    }

    var needsG3XUpload: Bool {
        hasG3XData && !g3xServerSynced
    }

    var g3xLabel: String {
        guard hasG3XData else { return "Not attached" }
        var parts: [String] = []
        if let ident = g3xAircraftIdent, !ident.isEmpty {
            parts.append(ident)
        }
        if g3xRowCount > 0 {
            parts.append("\(g3xRowCount) rows")
        }
        if g3xServerSynced {
            parts.append("synced")
        } else if uploadStatus == .uploaded {
            parts.append("pending sync")
        } else {
            parts.append("will upload with flight")
        }
        return parts.joined(separator: " · ")
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
    var quaternionW: Double?
    var quaternionX: Double?
    var quaternionY: Double?
    var quaternionZ: Double?
    var roll: Double
    var pitch: Double
    var yaw: Double
    var acceleration: Double
    var accelerationX: Double?
    var accelerationY: Double?
    var accelerationZ: Double?
    var linearAccelerationX: Double?
    var linearAccelerationY: Double?
    var linearAccelerationZ: Double?
    var gravityX: Double?
    var gravityY: Double?
    var gravityZ: Double?
    var fusedHeading: Double?
    var slipSkid: Double?
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
