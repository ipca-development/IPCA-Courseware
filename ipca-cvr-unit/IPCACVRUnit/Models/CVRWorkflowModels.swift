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
            updatedAt: Date()
        )
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

    var missingItems: [String] {
        var items: [String] = []
        if tailNumber.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty { items.append("TAIL NUMBER REQUIRED") }
        if aircraftID == nil { items.append("AIRCRAFT REQUIRED") }
        if missionCode.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty { items.append("MISSION CODE REQUIRED") }
        if crew.isEmpty { items.append("CREW REQUIRED") }
        if crew.contains(where: { $0.role == .unknown }) { items.append("CREW FUNCTION REQUIRED") }
        if startingHobbs == nil { items.append("STARTING HOBBS REQUIRED") }
        if startingTacho == nil { items.append("STARTING TACHO REQUIRED") }
        if fuelOnboard.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty { items.append("FUEL QUANTITY REQUIRED") }
        if effectiveStartingOilQuantity == nil { items.append("OIL QUANTITY REQUIRED") }
        items.append(contentsOf: continuityDiscrepancies)
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

    var id: String { flightRecordID }

    enum CodingKeys: String, CodingKey {
        case flightRecordID = "flight_record_uuid"
        case dispatchUUID = "dispatch_uuid"
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

    func refresh(settings: SettingsStore) async {
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
            lastError = ""
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
            let directory = try pendingDirectory()
            let fileID = UUID().uuidString
            let destination = directory.appendingPathComponent("\(fileID).csv")
            try data.write(to: destination, options: [.atomic])
            pendingGarminCSV = CVRPendingGarminCSV(
                id: fileID,
                fileURL: destination,
                originalFilename: sourceURL.lastPathComponent
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
        guard let pendingGarminCSV else { return }
        isUploading = true
        uploadProgress = 0
        defer { isUploading = false }
        do {
            try await uploadManager.uploadGarminCSVAttachment(
                fileURL: pendingGarminCSV.fileURL,
                originalFilename: pendingGarminCSV.originalFilename,
                flightRecordID: entry.flightRecordID,
                settings: settings
            ) { [weak self] progress in
                self?.uploadProgress = progress
            }
            locallyAttachedGarminFlightRecordIDs.insert(entry.flightRecordID)
            if let index = entries.firstIndex(where: { $0.flightRecordID == entry.flightRecordID }) {
                entries[index].hasGarminCSV = true
            }
            uploadProgress = 1
            lastError = ""
            await refresh(settings: settings)
            try? FileManager.default.removeItem(at: pendingGarminCSV.fileURL)
            self.pendingGarminCSV = nil
        } catch is CancellationError {
            lastError = "Garmin CSV upload was interrupted. The file is still ready to retry."
        } catch let error as URLError where error.code == .cancelled {
            lastError = "Garmin CSV upload was interrupted. The file is still ready to retry."
        } catch {
            lastError = "Garmin CSV upload failed: \(error.localizedDescription)"
        }
    }

    func hasLocallyAttachedGarminCSV(flightRecordID: String) -> Bool {
        locallyAttachedGarminFlightRecordIDs.contains(flightRecordID)
    }

    func cancelPendingGarminCSV() {
        if let fileURL = pendingGarminCSV?.fileURL {
            try? FileManager.default.removeItem(at: fileURL)
        }
        pendingGarminCSV = nil
        uploadProgress = 0
    }

    @discardableResult
    func adjustFlightLog(
        _ entry: CVRFlightLogEntry,
        departureAirport: String,
        arrivalAirport: String,
        crewNames: [String],
        endingHobbs: Double?,
        endingTacho: Double?,
        fuelRemaining: String,
        settings: SettingsStore
    ) async -> Bool {
        guard let endingHobbs,
              let endingTacho,
              endingHobbs >= (entry.startingHobbs ?? 0),
              endingTacho >= (entry.startingTacho ?? 0) else {
            lastError = "Ending Hobbs and Tacho must be valid and cannot be lower than their starting values."
            return false
        }
        let fuel = fuelRemaining.trimmingCharacters(in: .whitespacesAndNewlines)
        guard let fuelValue = Double(fuel), fuelValue >= 0 else {
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
        guard !departure.isEmpty, !arrival.isEmpty, !crew.isEmpty else {
            lastError = "Departure, arrival, and at least one crew member are required."
            return false
        }
        let payload: [String: Any] = [
            "flight_record_uuid": entry.flightRecordID.lowercased(),
            "dispatch_uuid": entry.dispatchUUID.lowercased(),
            "departure_airport": departure,
            "arrival_airport": arrival,
            "crew_names": crew,
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
            return true
        } catch {
            lastError = "Flight log adjustment failed: \(error.localizedDescription)"
            return false
        }
    }

    private func pendingDirectory() throws -> URL {
        let support = try FileManager.default.url(
            for: .applicationSupportDirectory,
            in: .userDomainMask,
            appropriateFor: nil,
            create: true
        )
        let directory = support.appendingPathComponent("PendingGarminImports", isDirectory: true)
        try FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
        return directory
    }
}
