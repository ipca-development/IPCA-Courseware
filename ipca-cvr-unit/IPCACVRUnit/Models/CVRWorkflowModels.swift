import Foundation

enum CVROperationalTab: String, Codable, CaseIterable, Identifiable {
    case scheduled
    case dispatch
    case recorder
    case inFlight
    case garmin
    case log

    var id: String { rawValue }

    var title: String {
        switch self {
        case .scheduled: return "Scheduled"
        case .dispatch: return "Dispatch"
        case .recorder: return "Recorder"
        case .inFlight: return "In-Flight"
        case .garmin: return "Garmin"
        case .log: return "Log"
        }
    }

    var systemImage: String {
        switch self {
        case .scheduled: return "calendar"
        case .dispatch: return "checklist"
        case .recorder: return "waveform"
        case .inFlight: return "airplane"
        case .garmin: return "doc.badge.arrow.up"
        case .log: return "list.bullet.clipboard"
        }
    }
}

enum CVRDispatchStatus: String, Codable {
    case noDispatch
    case dispatchIncomplete
    case consentRequired
    case tailNumberConflict
    case readyForVerification
    case dispatchVerified
    case flightRecordLoggingEnabled

    var displayTitle: String {
        switch self {
        case .noDispatch: return "NO DISPATCH"
        case .dispatchIncomplete: return "DISPATCH INCOMPLETE"
        case .consentRequired: return "CONSENT REQUIRED"
        case .tailNumberConflict: return "TAIL NUMBER CONFLICT"
        case .readyForVerification: return "READY FOR VERIFICATION"
        case .dispatchVerified: return "DISPATCH VERIFIED"
        case .flightRecordLoggingEnabled: return "FLIGHT RECORD LOGGING ENABLED"
        }
    }
}

enum CVRFlightRecordStatus: String, Codable {
    case dispatchDraft
    case dispatchReady
    case recorderVerificationRequired
    case standingByForAvionics
    case recording
    case offBlockConfirmationMissing
    case shutdownVerificationRequired
    case awaitingAvionicsOff
    case awaitingGarmin
    case awaitingUpload
    case uploading
    case serverReconciliation
    case administrativeReviewRequired
    case logbookReady
    case replayProcessing
    case complete
}

enum CVRCrewRole: String, Codable, CaseIterable, Identifiable {
    case student
    case instructor
    case pic
    case safetyPilot
    case observer
    case passenger
    case maintenance
    case unknown

    var id: String { rawValue }

    var label: String {
        switch self {
        case .student: return "Student"
        case .instructor: return "Instructor"
        case .pic: return "PIC"
        case .safetyPilot: return "Safety Pilot"
        case .observer: return "Observer"
        case .passenger: return "Passenger"
        case .maintenance: return "Maintenance"
        case .unknown: return "Unknown"
        }
    }
}

enum CVRUploadComponentState: String, Codable {
    case notReady
    case queued
    case uploading
    case uploaded
    case serverVerified
    case failed
    case needsUserAction
    case superseded
}

enum CVRCheckInMode: String, Codable, Equatable {
    case transientStop
    case engineShutdown
}

struct CVRPlannedLegRecord: Identifiable, Codable, Equatable {
    var id: String
    var reservationUUID: String
    var legUUID: String
    var sequenceNumber: Int
    var departureAirport: String
    var destinationAirport: String
    var missionCode: String
    var tailNumber: String
    var schedulerRecordID: String?
    var plannedStartAt: Date?
    var plannedEndAt: Date?
    /// planned | active | checked_in
    var status: String
}

struct CVROperationalSessionContext: Codable, Equatable {
    var reservationUUID: String?
    var engineSessionContinuityActive: Bool
    var plannedLegs: [CVRPlannedLegRecord]
    /// 1-based index of the active/current leg within plannedLegs (or 1 when single-leg).
    var currentLegIndex: Int?
    var pendingCheckInMode: CVRCheckInMode?
    var carryoverHobbs: Double?
    var carryoverTacho: Double?
    var carryoverFuel: String?
    /// Oil from the previous leg Dispatch (Check-In does not collect oil).
    var carryoverOilPercentage: Int?
    var carryoverOilQuantity: Double?
    var carryoverOilUnit: String?
    /// Crew selected on the previous leg Dispatch — default for the next leg.
    var carryoverCrew: [CVRCrewAssignment]?
    var awaitingAvionicsOffConfirmation: Bool
    var continuityEngineStartSynthesized: Bool
    /// Request soft-start of a new recording after next-leg Dispatch/recorder ready.
    var pendingSoftStartRecording: Bool

    static var empty: CVROperationalSessionContext {
        CVROperationalSessionContext(
            reservationUUID: nil,
            engineSessionContinuityActive: false,
            plannedLegs: [],
            currentLegIndex: nil,
            pendingCheckInMode: nil,
            carryoverHobbs: nil,
            carryoverTacho: nil,
            carryoverFuel: nil,
            carryoverOilPercentage: nil,
            carryoverOilQuantity: nil,
            carryoverOilUnit: nil,
            carryoverCrew: nil,
            awaitingAvionicsOffConfirmation: false,
            continuityEngineStartSynthesized: false,
            pendingSoftStartRecording: false
        )
    }
}

struct CVRWorkflowState: Codable, Equatable {
    var selectedTab: CVROperationalTab
    var activeDispatch: CVRDispatchRecord?
    var activeFlightRecord: CVRIncompleteFlightRecord?
    var consents: [CVRConsentRecord]
    var recorderVerifications: [CVRRecorderVerificationRecord]
    var flightEvents: [CVRFlightEventRecord]
    var flightLegs: [CVRFlightLegRecord]
    var uploadComponents: [CVRUploadComponentRecord]
    var discrepancies: [CVRDiscrepancyRecord]
    /// Phase 3 multi-leg / engine continuity. Optional for older flight-workflow.json.
    var operationalSession: CVROperationalSessionContext?
    var updatedAt: Date

    static var empty: CVRWorkflowState {
        CVRWorkflowState(
            selectedTab: .scheduled,
            activeDispatch: nil,
            activeFlightRecord: nil,
            consents: [],
            recorderVerifications: [],
            flightEvents: [],
            flightLegs: [],
            uploadComponents: [],
            discrepancies: [],
            operationalSession: nil,
            updatedAt: Date()
        )
    }

    var engineSessionContinuityActive: Bool {
        operationalSession?.engineSessionContinuityActive == true
    }

    var plannedLegs: [CVRPlannedLegRecord] {
        operationalSession?.plannedLegs ?? []
    }
}

struct CVRDispatchRecord: Identifiable, Codable, Equatable {
    var id: String
    var serverDispatchID: String?
    var organizationID: Int
    var scheduledDate: Date
    var scheduledStartTime: Date?
    var scheduledEndTime: Date?
    var tailNumber: String
    var aircraftID: Int?
    var missionCode: String
    var plannedDepartureAirport: String
    var plannedDestinationAirport: String
    var crew: [CVRCrewAssignment]
    var startingHobbs: Double?
    var startingTacho: Double?
    var fuelOnboard: String
    var oilPercentage: Int?
    var startingOilQuantity: Double?
    var startingOilUnit: String?
    var dispatchSource: String
    var schedulerRecordID: String?
    var creatorIdentity: String
    var createdAt: Date
    var modifiedAt: Date
    var version: Int
    var consentStatus: String
    var status: CVRDispatchStatus
    var configuredCVRUnitID: String
    var configuredBeaconID: String
    var previousFlightRecordID: String?
    var previousEndingHobbs: Double?
    var previousEndingTacho: Double?
    var previousFuelRemaining: String?
    var previousOilPercentage: Int?
    var previousEndingOilQuantity: Double?
    var previousEndingOilUnit: String?
    var refueledSincePreviousFlight: Bool?
    var oilServicedSincePreviousFlight: Bool?
    /// Phase 2D offline canonical identity. Nil when canonical writes are disabled.
    var operationalIdentity: CVRLocalOperationalIdentity?

    var missingItems: [String] {
        var items: [String] = []
        if tailNumber.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty { items.append("TAIL NUMBER REQUIRED") }
        if aircraftID == nil { items.append("AIRCRAFT REQUIRED") }
        if missionCode.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty { items.append("MISSION CODE REQUIRED") }
        let departure = plannedDepartureAirport.trimmingCharacters(in: .whitespacesAndNewlines).uppercased()
        let destination = plannedDestinationAirport.trimmingCharacters(in: .whitespacesAndNewlines).uppercased()
        if departure.isEmpty { items.append("Departure airport is required.") }
        if destination.isEmpty { items.append("Destination airport is required.") }
        if crew.isEmpty { items.append("CREW REQUIRED") }
        if crew.contains(where: { $0.role == .unknown }) { items.append("CREW FUNCTION REQUIRED") }
        if startingHobbs == nil { items.append("STARTING HOBBS REQUIRED") }
        if startingTacho == nil { items.append("STARTING TACHO REQUIRED") }
        if fuelOnboard.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty { items.append("FUEL QUANTITY REQUIRED") }
        if effectiveStartingOilQuantity == nil { items.append("OIL QUANTITY REQUIRED") }
        return items
    }

    var continuityDiscrepancies: [String] {
        var items: [String] = []
        if let expected = previousEndingHobbs, let actual = startingHobbs, abs(actual - expected) > 0.1 {
            items.append(String(format: "HOBBS DISCREPANCY: EXPECTED %.1f FROM PREVIOUS END", expected))
        }
        if let expected = previousEndingTacho, let actual = startingTacho, abs(actual - expected) > 0.1 {
            items.append(String(format: "TACHO DISCREPANCY: EXPECTED %.1f FROM PREVIOUS END", expected))
        }
        if let expected = previousFuelRemaining.flatMap(Self.numericQuantity),
           let actual = Self.numericQuantity(fuelOnboard),
           Self.relativeDifference(actual, expected) > 0.20 {
            if actual > expected {
                if refueledSincePreviousFlight != true {
                    items.append("FUEL DISCREPANCY >20%: CONFIRM AIRCRAFT WAS REFUELED")
                }
            } else {
                items.append("FUEL DISCREPANCY >20%: REFUELING DOES NOT EXPLAIN LOWER QUANTITY")
            }
        }
        if let expected = effectivePreviousOilQuantity, let actual = effectiveStartingOilQuantity,
           Self.relativeDifference(actual, expected) > 0.20 {
            if actual > expected {
                if oilServicedSincePreviousFlight != true {
                    items.append("OIL DISCREPANCY >20%: CONFIRM OIL WAS SERVICED")
                }
            } else {
                items.append("OIL DISCREPANCY >20%: SERVICING DOES NOT EXPLAIN LOWER QUANTITY")
            }
        }
        return items
    }

    private static func numericQuantity(_ value: String) -> Double? {
        Double(value.replacingOccurrences(of: "USG", with: "", options: .caseInsensitive)
            .trimmingCharacters(in: .whitespacesAndNewlines))
    }

    private static func relativeDifference(_ lhs: Double, _ rhs: Double) -> Double {
        abs(lhs - rhs) / max(abs(rhs), 0.1)
    }

    var effectiveStartingOilQuantity: Double? {
        startingOilQuantity ?? oilPercentage.map(Double.init)
    }

    var effectiveStartingOilUnit: String {
        startingOilUnit?.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty == false
            ? startingOilUnit!
            : "%"
    }

    var effectivePreviousOilQuantity: Double? {
        previousEndingOilQuantity ?? previousOilPercentage.map(Double.init)
    }
}

struct CVRCrewAssignment: Identifiable, Codable, Equatable {
    var id: String
    var personID: Int?
    var personName: String
    var role: CVRCrewRole
}

struct CVRConsentRecord: Identifiable, Codable, Equatable {
    var id: String
    var personID: Int?
    var personName: String
    var crewRole: CVRCrewRole
    var consentResult: Bool
    var timestamp: Date
    var deviceID: String
    var dispatchID: String
    var dispatchVersion: Int
    var consentTextVersion: String
    var appVersion: String
}

struct CVRIncompleteFlightRecord: Identifiable, Codable, Equatable {
    var id: String
    var serverFlightRecordID: String?
    var dispatchID: String
    var recordingSessionID: String?
    var recordingStartedAt: Date?
    var status: CVRFlightRecordStatus
    var endingHobbs: Double?
    var endingTacho: Double?
    var fuelRemaining: String?
    var endingOilPercentage: Int?
    var endingOilQuantity: Double?
    var endingOilUnit: String?
    var verifiedTakeoffCount: Int?
    var verifiedLandingCount: Int?
    var autoDetectedTakeoffCount: Int?
    var autoDetectedLandingCount: Int?
    var maintenanceRemark: String?
    var checkInComments: String?
    var verifiedDestinationAirport: String?
    var checkInMode: CVRCheckInMode?
    var calculatedArrivalAt: Date?
    var arrivalCalculationSource: String?
    var createdAt: Date
    var updatedAt: Date

    var effectiveEndingOilQuantity: Double? {
        endingOilQuantity ?? endingOilPercentage.map(Double.init)
    }

    var effectiveEndingOilUnit: String {
        endingOilUnit?.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty == false
            ? endingOilUnit!
            : "%"
    }
}

enum CVRWorkflowArchiveStatus: String, Codable {
    case uploadPending
    case serverVerified
}

struct CVRWorkflowArchiveRecord: Identifiable, Codable, Equatable {
    var id: String
    var schemaVersion: Int
    var flightRecordID: String
    var dispatch: CVRDispatchRecord
    var flightRecord: CVRIncompleteFlightRecord
    var consents: [CVRConsentRecord]
    var recorderVerifications: [CVRRecorderVerificationRecord]
    var flightEvents: [CVRFlightEventRecord]
    var flightLegs: [CVRFlightLegRecord]
    var uploadComponents: [CVRUploadComponentRecord]
    var discrepancies: [CVRDiscrepancyRecord]
    var recordingSessionIDs: [String]
    var archivedAt: Date
    var appVersion: String
    var status: CVRWorkflowArchiveStatus
}

struct CVRRecorderVerificationRecord: Identifiable, Codable, Equatable {
    var id: String
    var dispatchID: String
    var flightRecordID: String
    var deviceID: String
    var appVersion: String
    var timestamp: Date
    var userIdentity: String
    var audioRouteStatus: String
    var beaconStatus: String
    var gpsStatus: String
    var storageStatus: String
    var thermalStatus: String
    var batteryStatus: String
    var permissionStatus: String
    var fileWritingTestResult: String
    var warnings: [String]
    var acceptedNonblockingWarnings: [String]
}

struct CVRFlightEventRecord: Identifiable, Codable, Equatable {
    var id: String
    var flightRecordID: String
    var recordingSessionID: String?
    var eventType: String
    var timestampUTC: Date
    var timestampLocal: Date
    var deviceMonotonicTime: Double?
    var audioOffset: Double?
    var latitude: Double?
    var longitude: Double?
    var altitude: Double?
    var groundSpeed: Double?
    var source: String
    var confidence: Double
    var creationMethod: String
    var userIdentity: String?
    var metadata: [String: String]?

    init(
        id: String,
        flightRecordID: String,
        recordingSessionID: String?,
        eventType: String,
        timestampUTC: Date,
        timestampLocal: Date,
        deviceMonotonicTime: Double?,
        audioOffset: Double?,
        latitude: Double?,
        longitude: Double?,
        altitude: Double?,
        groundSpeed: Double?,
        source: String,
        confidence: Double,
        creationMethod: String,
        userIdentity: String?,
        metadata: [String: String]? = nil
    ) {
        self.id = id
        self.flightRecordID = flightRecordID
        self.recordingSessionID = recordingSessionID
        self.eventType = eventType
        self.timestampUTC = timestampUTC
        self.timestampLocal = timestampLocal
        self.deviceMonotonicTime = deviceMonotonicTime
        self.audioOffset = audioOffset
        self.latitude = latitude
        self.longitude = longitude
        self.altitude = altitude
        self.groundSpeed = groundSpeed
        self.source = source
        self.confidence = confidence
        self.creationMethod = creationMethod
        self.userIdentity = userIdentity
        self.metadata = metadata
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        id = try container.decode(String.self, forKey: .id)
        flightRecordID = try container.decode(String.self, forKey: .flightRecordID)
        recordingSessionID = try container.decodeIfPresent(String.self, forKey: .recordingSessionID)
        eventType = try container.decode(String.self, forKey: .eventType)
        timestampUTC = try container.decode(Date.self, forKey: .timestampUTC)
        timestampLocal = try container.decode(Date.self, forKey: .timestampLocal)
        deviceMonotonicTime = try container.decodeIfPresent(Double.self, forKey: .deviceMonotonicTime)
        audioOffset = try container.decodeIfPresent(Double.self, forKey: .audioOffset)
        latitude = try container.decodeIfPresent(Double.self, forKey: .latitude)
        longitude = try container.decodeIfPresent(Double.self, forKey: .longitude)
        altitude = try container.decodeIfPresent(Double.self, forKey: .altitude)
        groundSpeed = try container.decodeIfPresent(Double.self, forKey: .groundSpeed)
        source = try container.decode(String.self, forKey: .source)
        confidence = try container.decode(Double.self, forKey: .confidence)
        creationMethod = try container.decode(String.self, forKey: .creationMethod)
        userIdentity = try container.decodeIfPresent(String.self, forKey: .userIdentity)
        metadata = try container.decodeIfPresent([String: String].self, forKey: .metadata)
    }
}

struct CVRFlightLegRecord: Identifiable, Codable, Equatable {
    var id: String
    var flightRecordID: String
    var sequenceNumber: Int
    var reservationUUID: String?
    var legUUID: String?
    var departureAirport: String?
    var arrivalAirport: String?
    var legOpeningTimestamp: Date?
    var takeoffTimestamp: Date?
    var landingTimestamp: Date?
    var legClosingTimestamp: Date?
    var startHobbsAllocation: Double?
    var endHobbsAllocation: Double?
    var hobbsDuration: Double?
    var actualElapsedDuration: TimeInterval?
    var takeoffCount: Int
    var landingCount: Int
    var touchAndGoCount: Int
    var stopAndGoCount: Int
    var fullStopLandingCount: Int
    var reviewStatus: String
}

struct CVRUploadComponentRecord: Identifiable, Codable, Equatable {
    var id: String
    var serverID: String?
    var flightRecordID: String
    var componentType: String
    var localFilePath: String?
    var sha256: String?
    var byteCount: Int64?
    var state: CVRUploadComponentState
    var progress: Double?
    var attemptCount: Int
    var lastError: String
    var lastAttemptAt: Date?
    var serverVerificationAt: Date?
    var serverReceiptID: String?
    var errorCode: String? = nil
    var retryable: Bool? = nil
    var userActionRequired: Bool? = nil
    var requestID: String? = nil
    var reconciliationRequired: Bool? = nil
    var authoritativePayloadSHA256: String? = nil
    var canonicalIdentifiers: [String: String]? = nil
    var requestPayloadSnapshot: Data? = nil
}

struct CVRDiscrepancyRecord: Identifiable, Codable, Equatable {
    var id: String
    var flightRecordID: String
    var discrepancyType: String
    var severity: String
    var message: String
    var evidence: [String: String]
    var status: String
    var createdAt: Date
}

struct CVRFlightLogEntry: Identifiable, Codable, Equatable {
    var flightRecordID: String
    var dispatchUUID: String
    var schedulerRecordID: String?
    var reservationUUID: String?
    var legUUID: String?
    var aircraftRegistration: String
    var scheduledDate: String
    var crewNames: [String]?
    var departureAirport: String
    var departureTime: String?
    var arrivalAirport: String
    var arrivalTime: String?
    var startingHobbs: Double?
    var startingTacho: Double?
    var endingHobbs: Double?
    var endingTacho: Double?
    var fuelRemaining: String?
    var endingOilPercentage: Int?
    var endingOilQuantity: Double?
    var endingOilUnit: String?
    var totalHobbsTime: Double?
    var hasGarminCSV: Bool
    var serverUploadStatus: String?
    var serverUploadProgress: Int?
    var serverUploadError: String?
    var audioUploadStatus: String?
    var transcriptStatus: String?
    var transcriptProgress: Int?
    var transcriptError: String?
    var takeoffCount: Int?
    var landingCount: Int?
    var serverComponentCount: Int?

    var id: String { flightRecordID }

    enum CodingKeys: String, CodingKey {
        case flightRecordID = "flight_record_uuid"
        case dispatchUUID = "dispatch_uuid"
        case schedulerRecordID = "scheduler_record_id"
        case reservationUUID = "reservation_uuid"
        case legUUID = "leg_uuid"
        case aircraftRegistration = "aircraft_registration"
        case scheduledDate = "scheduled_date"
        case crewNames = "crew_names"
        case departureAirport = "departure_airport"
        case departureTime = "departure_time"
        case arrivalAirport = "arrival_airport"
        case arrivalTime = "arrival_time"
        case startingHobbs = "starting_hobbs"
        case startingTacho = "starting_tacho"
        case endingHobbs = "ending_hobbs"
        case endingTacho = "ending_tacho"
        case fuelRemaining = "fuel_remaining"
        case endingOilPercentage = "ending_oil_percentage"
        case endingOilQuantity = "ending_oil_quantity"
        case endingOilUnit = "ending_oil_unit"
        case totalHobbsTime = "total_hobbs_time"
        case hasGarminCSV = "has_garmin_csv"
        case serverUploadStatus = "server_upload_status"
        case serverUploadProgress = "server_upload_progress"
        case serverUploadError = "server_upload_error"
        case audioUploadStatus = "audio_upload_status"
        case transcriptStatus = "transcript_status"
        case transcriptProgress = "transcript_progress"
        case transcriptError = "transcript_error"
        case takeoffCount = "takeoff_count"
        case landingCount = "landing_count"
        case serverComponentCount = "server_component_count"
    }
}

struct CVRFlightLogsResponse: Codable {
    var ok: Bool
    var flightLogs: [CVRFlightLogEntry]
    var error: String?

    enum CodingKeys: String, CodingKey {
        case ok
        case flightLogs = "flight_logs"
        case error
    }
}

struct CVRPendingGarminCSV: Identifiable, Equatable {
    var id: String
    var fileURL: URL
    var originalFilename: String
    var sha256: String
    /// Preserved once the instructor associates the CSV with a Log flight.
    var targetFlightRecordID: String?
    var stagedAt: Date
    var lastFailureMessage: String?
}

@MainActor
final class CVRFlightLogStore: ObservableObject {
    @Published private(set) var entries: [CVRFlightLogEntry] = []
    @Published private(set) var isRefreshing = false
    @Published private(set) var isUploading = false
    @Published private(set) var isAdjusting = false
    @Published private(set) var uploadProgress = 0.0
    @Published private(set) var lastError = ""
    @Published var pendingGarminCSV: CVRPendingGarminCSV?
    @Published private(set) var locallyAttachedGarminFlightRecordIDs: Set<String> = []

    private static let syncFirstMessage =
        "The Garmin file is stored on this device. Synchronize the flight first, then retry. You will not need to select the file again."

    init() {
        restorePendingGarminImport()
    }

    /// Reloads durable pending Garmin state before Log becomes interactive.
    func preparePendingGarminImportForLog() {
        if pendingGarminCSV == nil {
            restorePendingGarminImport()
        } else if let pending = pendingGarminCSV, let flightID = pending.targetFlightRecordID {
            locallyAttachedGarminFlightRecordIDs.insert(flightID)
        }
    }

    func refresh(settings: SettingsStore) async {
        preparePendingGarminImportForLog()
        guard let baseURL = settings.normalizedServerURL,
              let credential = settings.deviceCredential,
              !credential.isEmpty else {
            lastError = "Enroll this CVR Unit before loading the aircraft flight log."
            return
        }
        isRefreshing = true
        defer { isRefreshing = false }
        do {
            let response = try await APIClient(serverURL: baseURL).flightLogs(credential: credential)
            guard response.ok else {
                throw APIClientError.badResponse(response.error ?? "Flight log request failed.")
            }
            entries = response.flightLogs.sorted {
                if $0.scheduledDate == $1.scheduledDate {
                    return ($0.departureTime ?? "") > ($1.departureTime ?? "")
                }
                return $0.scheduledDate > $1.scheduledDate
            }
            if lastError == Self.syncFirstMessage
                || lastError.localizedCaseInsensitiveContains("Garmin file") {
                // Keep operational Garmin recovery messaging across refresh.
            } else {
                lastError = ""
            }
            if let pending = pendingGarminCSV, let message = pending.lastFailureMessage, !message.isEmpty {
                lastError = message
            }
        } catch is CancellationError {
            return
        } catch let error as URLError where error.code == .cancelled {
            return
        } catch {
            lastError = error.localizedDescription
        }
    }

    @discardableResult
    func stageGarminCSV(from sourceURL: URL) -> Bool {
        let accessed = sourceURL.startAccessingSecurityScopedResource()
        defer {
            if accessed {
                sourceURL.stopAccessingSecurityScopedResource()
            }
        }
        do {
            guard sourceURL.pathExtension.caseInsensitiveCompare("csv") == .orderedSame else {
                throw APIClientError.badResponse("Select a Garmin CSV file.")
            }
            let data = try Data(contentsOf: sourceURL)
            guard !data.isEmpty else {
                throw APIClientError.badResponse("The selected CSV file is empty.")
            }

            // Stage the CSV file first.
            let directory = try CVRPendingGarminPersistence.importsDirectory()
            let fileID = UUID().uuidString.lowercased()
            let relativePath = CVRPendingGarminPersistence.relativePath(forImportID: fileID)
            let destination = directory.appendingPathComponent("\(fileID).csv")
            try data.write(to: destination, options: [.atomic])
            let digest = CVRPendingGarminPersistence.sha256Hex(of: data)
            let stagedAt = Date()
            let metadata = CVRPendingGarminMetadata(
                id: fileID,
                relativeFilePath: relativePath,
                originalFilename: sourceURL.lastPathComponent,
                sha256: digest,
                targetFlightRecordID: nil,
                stagedAt: stagedAt,
                lastFailureMessage: nil
            )

            // Persist metadata atomically and decode-verify before exposing UI pending state.
            let verified: CVRPendingGarminMetadata
            do {
                verified = try CVRPendingGarminPersistence.writeMetadata(metadata)
            } catch {
                lastError = "The Garmin file was copied to this device, but it is not ready for retry yet. Try selecting the file again."
                return false
            }

            pendingGarminCSV = CVRPendingGarminCSV(
                id: verified.id,
                fileURL: destination,
                originalFilename: verified.originalFilename,
                sha256: verified.sha256,
                targetFlightRecordID: verified.targetFlightRecordID,
                stagedAt: verified.stagedAt,
                lastFailureMessage: verified.lastFailureMessage
            )
            lastError = ""
            return true
        } catch {
            lastError = "Could not receive Garmin CSV: \(error.localizedDescription)"
            return false
        }
    }

    func uploadPendingGarminCSV(
        to entry: CVRFlightLogEntry,
        settings: SettingsStore,
        uploadManager: UploadManager
    ) async {
        guard var pending = pendingGarminCSV else { return }
        pending.targetFlightRecordID = entry.flightRecordID
        do {
            try persistPendingAssociation(pending, failureMessage: pending.lastFailureMessage)
        } catch {
            lastError = "The Garmin file is on this device, but the flight association could not be saved for retry."
            return
        }
        pendingGarminCSV = pending
        locallyAttachedGarminFlightRecordIDs.insert(entry.flightRecordID)
        isUploading = true
        uploadProgress = 0
        defer { isUploading = false }
        do {
            try await uploadManager.uploadGarminCSVAttachment(
                fileURL: pending.fileURL,
                originalFilename: pending.originalFilename,
                flightRecordID: entry.flightRecordID,
                settings: settings
            ) { [weak self] progress in
                self?.uploadProgress = progress
            }
            if let index = entries.firstIndex(where: { $0.flightRecordID == entry.flightRecordID }) {
                entries[index].hasGarminCSV = true
            }
            uploadProgress = 1
            lastError = ""
            await refresh(settings: settings)
            clearPendingGarminAfterVerifiedSuccess(fileURL: pending.fileURL)
        } catch is CancellationError {
            await preservePendingFailure(
                pending,
                message: "Garmin CSV upload was interrupted. The file is still ready to retry."
            )
        } catch let error as URLError where error.code == .cancelled {
            await preservePendingFailure(
                pending,
                message: "Garmin CSV upload was interrupted. The file is still ready to retry."
            )
        } catch {
            let message = error.localizedDescription
            let operational: String
            if message.localizedCaseInsensitiveContains("does not belong")
                || message.localizedCaseInsensitiveContains("could not be linked")
                || message.localizedCaseInsensitiveContains("dispatch") {
                operational = Self.syncFirstMessage
            } else if message.localizedCaseInsensitiveContains("another")
                && message.localizedCaseInsensitiveContains("aircraft") {
                operational = "This Garmin file could not be attached to the selected flight for this aircraft. The file remains on this device for correction."
            } else {
                operational = Self.syncFirstMessage
            }
            await preservePendingFailure(pending, message: operational)
        }
    }

    /// Retries a preserved pending Garmin finalize after Dispatch/workflow sync catches up.
    func retryPendingGarminCSV(
        settings: SettingsStore,
        uploadManager: UploadManager
    ) async {
        preparePendingGarminImportForLog()
        guard let pending = pendingGarminCSV,
              let flightRecordID = pending.targetFlightRecordID else {
            if pendingGarminCSV != nil {
                lastError = Self.syncFirstMessage
            }
            return
        }
        if let entry = entries.first(where: { $0.flightRecordID == flightRecordID }) {
            await uploadPendingGarminCSV(to: entry, settings: settings, uploadManager: uploadManager)
            return
        }
        // Flight not yet visible in Log — keep pending; do not clear or re-stage.
        lastError = Self.syncFirstMessage
        await preservePendingFailure(pending, message: Self.syncFirstMessage)
    }

    func hasLocallyAttachedGarminCSV(flightRecordID: String) -> Bool {
        locallyAttachedGarminFlightRecordIDs.contains(flightRecordID)
            || pendingGarminCSV?.targetFlightRecordID == flightRecordID
    }

    func retryServerProcessing(_ entry: CVRFlightLogEntry, settings: SettingsStore) async {
        guard let baseURL = settings.normalizedServerURL,
              let credential = settings.deviceCredential,
              !credential.isEmpty else {
            lastError = "Enroll this CVR Unit before retrying flight processing."
            return
        }
        do {
            let response = try await APIClient(serverURL: baseURL).retryFlightLog(
                flightRecordID: entry.flightRecordID,
                credential: credential
            )
            guard response.ok else {
                throw APIClientError.badResponse(response.error ?? "Flight retry was not accepted.")
            }
            lastError = ""
            await refresh(settings: settings)
        } catch {
            lastError = "Flight re-upload failed: \(error.localizedDescription)"
        }
    }

    func cancelPendingGarminCSV() {
        if let fileURL = pendingGarminCSV?.fileURL {
            try? FileManager.default.removeItem(at: fileURL)
        }
        try? CVRPendingGarminPersistence.clearMetadata()
        pendingGarminCSV = nil
        uploadProgress = 0
    }

    private func restorePendingGarminImport() {
        let result = CVRPendingGarminPersistence.restorePending()
        if let metadata = result.metadata, let fileURL = result.fileURL {
            pendingGarminCSV = CVRPendingGarminCSV(
                id: metadata.id,
                fileURL: fileURL,
                originalFilename: metadata.originalFilename,
                sha256: metadata.sha256,
                targetFlightRecordID: metadata.targetFlightRecordID,
                stagedAt: metadata.stagedAt,
                lastFailureMessage: metadata.lastFailureMessage
            )
            if let flightID = metadata.targetFlightRecordID {
                locallyAttachedGarminFlightRecordIDs.insert(flightID)
            }
            if let message = result.recoveryMessage, !message.isEmpty {
                lastError = message
            }
            return
        }
        pendingGarminCSV = nil
        if let message = result.recoveryMessage, !message.isEmpty {
            lastError = message
        }
    }

    private func persistPendingAssociation(
        _ pending: CVRPendingGarminCSV,
        failureMessage: String?
    ) throws {
        let metadata = CVRPendingGarminMetadata(
            id: pending.id,
            relativeFilePath: CVRPendingGarminPersistence.relativePath(forImportID: pending.id),
            originalFilename: pending.originalFilename,
            sha256: pending.sha256,
            targetFlightRecordID: pending.targetFlightRecordID,
            stagedAt: pending.stagedAt,
            lastFailureMessage: failureMessage
        )
        _ = try CVRPendingGarminPersistence.writeMetadata(metadata)
    }

    private func preservePendingFailure(_ pending: CVRPendingGarminCSV, message: String) async {
        var updated = pending
        updated.lastFailureMessage = message
        do {
            try persistPendingAssociation(updated, failureMessage: message)
            pendingGarminCSV = updated
            lastError = message
        } catch {
            pendingGarminCSV = updated
            lastError = message
        }
    }

    private func clearPendingGarminAfterVerifiedSuccess(fileURL: URL) {
        try? CVRPendingGarminPersistence.clearMetadata()
        try? FileManager.default.removeItem(at: fileURL)
        pendingGarminCSV = nil
        uploadProgress = 0
    }

    @discardableResult
    func adjustFlightLog(
        _ entry: CVRFlightLogEntry,
        departureAirport: String,
        arrivalAirport: String,
        crewNames: [String],
        startingHobbs: Double?,
        startingTacho: Double?,
        endingHobbs: Double?,
        endingTacho: Double?,
        fuelRemaining: String,
        settings: SettingsStore
    ) async -> Bool {
        guard let startingHobbs,
              let startingTacho,
              startingHobbs >= 0,
              startingTacho >= 0,
              let endingHobbs,
              let endingTacho,
              endingHobbs >= startingHobbs,
              endingTacho >= startingTacho else {
            lastError = "Ending Hobbs and Tacho must be valid and cannot be lower than their starting values."
            return false
        }
        let fuel = fuelRemaining.trimmingCharacters(in: .whitespacesAndNewlines)
        let numericFuel = fuel.components(
            separatedBy: CharacterSet(charactersIn: "0123456789.-").inverted
        ).joined()
        guard let fuelValue = Double(numericFuel), fuelValue >= 0 else {
            lastError = "Fuel remaining must be a valid non-negative quantity."
            return false
        }
        guard let baseURL = settings.normalizedServerURL,
              let credential = settings.deviceCredential,
              !credential.isEmpty else {
            lastError = "Enroll this CVR Unit before adjusting a flight log."
            return false
        }

        isAdjusting = true
        defer { isAdjusting = false }
        let departure = departureAirport.trimmingCharacters(in: .whitespacesAndNewlines).uppercased()
        let arrival = arrivalAirport.trimmingCharacters(in: .whitespacesAndNewlines).uppercased()
        let crew = crewNames
            .map { $0.trimmingCharacters(in: .whitespacesAndNewlines) }
            .filter { !$0.isEmpty }
        guard !crew.isEmpty else {
            lastError = "At least one crew member is required."
            return false
        }
        let payload: [String: Any] = [
            "flight_record_uuid": entry.flightRecordID.lowercased(),
            "dispatch_uuid": entry.dispatchUUID.lowercased(),
            "aircraft_registration": entry.aircraftRegistration.uppercased(),
            "departure_airport": departure,
            "arrival_airport": arrival,
            "crew_names": crew,
            "starting_hobbs": startingHobbs,
            "starting_tacho": startingTacho,
            "ending_hobbs": endingHobbs,
            "ending_tacho": endingTacho,
            "fuel_remaining": fuelValue
        ]
        do {
            let response = try await APIClient(serverURL: baseURL).adjustFlightLog(
                payload: payload,
                credential: credential
            )
            guard response.ok, response.adjustmentUUID?.isEmpty == false else {
                throw APIClientError.badResponse(response.error ?? "The adjusted flight log was not verified.")
            }
            lastError = ""
            await refresh(settings: settings)
            var adjustedEntry = entry
            adjustedEntry.departureAirport = departure
            adjustedEntry.arrivalAirport = arrival
            adjustedEntry.crewNames = crew
            adjustedEntry.startingHobbs = startingHobbs
            adjustedEntry.startingTacho = startingTacho
            adjustedEntry.endingHobbs = endingHobbs
            adjustedEntry.endingTacho = endingTacho
            adjustedEntry.fuelRemaining = String(fuelValue)
            adjustedEntry.totalHobbsTime = endingHobbs - startingHobbs
            if let index = entries.firstIndex(where: { $0.flightRecordID == entry.flightRecordID }) {
                entries[index] = adjustedEntry
            } else {
                entries.append(adjustedEntry)
            }
            return true
        } catch {
            lastError = "Flight log adjustment failed: \(error.localizedDescription)"
            return false
        }
    }
}
