import Foundation

enum CVROperationalTab: String, Codable, CaseIterable, Identifiable {
    case dispatch
    case recorder
    case inFlight
    case garmin

    var id: String { rawValue }

    var title: String {
        switch self {
        case .dispatch: return "Dispatch"
        case .recorder: return "Recorder"
        case .inFlight: return "In-Flight"
        case .garmin: return "Garmin"
        }
    }

    var systemImage: String {
        switch self {
        case .dispatch: return "checklist"
        case .recorder: return "waveform"
        case .inFlight: return "airplane"
        case .garmin: return "doc.badge.arrow.up"
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
            selectedTab: .dispatch,
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
        if oilPercentage == nil { items.append("OIL PERCENTAGE REQUIRED") }
        return items
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
    var status: CVRFlightRecordStatus
    var endingHobbs: Double?
    var endingTacho: Double?
    var fuelRemaining: String?
    var endingOilPercentage: Int?
    var maintenanceRemark: String?
    var createdAt: Date
    var updatedAt: Date
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
