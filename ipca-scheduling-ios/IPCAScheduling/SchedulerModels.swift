import Foundation

struct APIErrorEnvelope: Decodable {
    let ok: Bool?
    let errorCode: String?
    let message: String?
    let retryable: Bool?
    let userActionRequired: Bool?

    enum CodingKeys: String, CodingKey {
        case ok
        case errorCode = "error_code"
        case message
        case retryable
        case userActionRequired = "user_action_required"
    }
}

struct SchedulerUser: Codable, Hashable {
    let id: Int
    let uuid: String?
    let email: String?
    let name: String
    let role: String?
}

struct AuthDevice: Codable, Hashable {
    let id: Int?
    let deviceUUID: String?
    let organizationID: Int?
    let platform: String?

    enum CodingKeys: String, CodingKey {
        case id, platform
        case deviceUUID = "device_uuid"
        case organizationID = "organization_id"
    }
}

struct LoginResponse: Decodable {
    let ok: Bool
    let token: String
    let user: SchedulerUser
    let device: AuthDevice?
}

struct SchedulerCapabilities: Codable, Hashable {
    let scheduleRead: Bool
    let reservationCreate: Bool
    let reservationEdit: Bool
    let reservationCancel: Bool
    let reservationUndispatch: Bool
    let manualCheckin: Bool
    let dispatch: Bool
    let viewTraining: Bool
    let resourceSearch: Bool

    enum CodingKeys: String, CodingKey {
        case scheduleRead = "schedule_read"
        case reservationCreate = "reservation_create"
        case reservationEdit = "reservation_edit"
        case reservationCancel = "reservation_cancel"
        case reservationUndispatch = "reservation_undispatch"
        case manualCheckin = "manual_checkin"
        case dispatch
        case viewTraining = "view_training"
        case resourceSearch = "resource_search"
    }
}

struct SchedulerOrganization: Codable, Hashable {
    let id: Int
}

struct SchedulerConfiguration: Codable, Hashable {
    let maxRangeDays: Int
    let overlapPolicy: String
    let scheduleTimeSemantics: String
    let recurringReservationsSupported: Bool
    let comprehensiveAvailabilitySupported: Bool

    enum CodingKeys: String, CodingKey {
        case maxRangeDays = "max_range_days"
        case overlapPolicy = "overlap_policy"
        case scheduleTimeSemantics = "schedule_time_semantics"
        case recurringReservationsSupported = "recurring_reservations_supported"
        case comprehensiveAvailabilitySupported = "comprehensive_availability_supported"
    }
}

struct SchedulerBootstrapResponse: Codable {
    let ok: Bool
    let user: SchedulerUser
    let organization: SchedulerOrganization
    let capabilities: SchedulerCapabilities
    let operationalTimezone: String
    let scheduler: SchedulerConfiguration

    enum CodingKeys: String, CodingKey {
        case ok, user, organization, capabilities, scheduler
        case operationalTimezone = "operational_timezone"
    }
}

struct ScheduleRange: Codable, Hashable {
    let start: String
    let end: String
}

struct AircraftSummary: Codable, Hashable {
    let id: Int
    let registration: String
    let displayName: String?
    let aircraftType: String?
    let homeAirport: String?

    enum CodingKeys: String, CodingKey {
        case id, registration
        case displayName = "display_name"
        case aircraftType = "aircraft_type"
        case homeAirport = "home_airport"
    }
}

struct MissionSummary: Codable, Hashable {
    let id: Int?
    let code: String?
    let name: String?
}

struct CohortSummary: Codable, Hashable {
    let id: Int?
    let name: String?
}

struct CrewMember: Codable, Hashable, Identifiable {
    let personID: Int?
    let personName: String
    let role: String
    let pilotFunction: String?
    let isPIC: Bool?

    var id: String { "\(personID ?? 0)-\(personName)-\(role)" }

    enum CodingKeys: String, CodingKey {
        case role
        case personID = "person_id"
        case personName = "person_name"
        case pilotFunction = "pilot_function"
        case isPIC = "is_pic"
    }
}

struct ReservationLeg: Codable, Hashable, Identifiable {
    let sequenceNumber: Int
    let legUUID: String?
    let originAirport: String
    let destinationAirport: String
    let status: String?

    var id: String { legUUID ?? "\(sequenceNumber)-\(originAirport)-\(destinationAirport)" }

    enum CodingKeys: String, CodingKey {
        case status
        case sequenceNumber = "sequence_number"
        case legUUID = "leg_uuid"
        case originAirport = "origin_airport"
        case destinationAirport = "destination_airport"
    }
}

struct ReservationRoute: Codable, Hashable {
    let airportChain: [String]
    let legs: [ReservationLeg]

    enum CodingKeys: String, CodingKey {
        case airportChain = "airport_chain"
        case legs
    }
}

struct ReservationLock: Codable, Hashable {
    let locked: Bool
    let reason: String?
}

struct ReservationEvidence: Codable, Hashable {
    let hasDispatch: Bool?
    let hasRecording: Bool?
    let hasClosure: Bool?
    let hasDebrief: Bool?

    enum CodingKeys: String, CodingKey {
        case hasDispatch = "has_dispatch"
        case hasRecording = "has_recording"
        case hasClosure = "has_closure"
        case hasDebrief = "has_debrief"
    }
}

struct AuthorizedActions: Codable, Hashable {
    let edit: Bool
    let reschedule: Bool
    let cancel: Bool
    let undispatch: Bool
    let manualCheckin: Bool
    let dispatch: Bool

    enum CodingKeys: String, CodingKey {
        case edit, reschedule, cancel, undispatch, dispatch
        case manualCheckin = "manual_checkin"
    }
}

struct SchedulerReservation: Codable, Hashable, Identifiable {
    let reservationUUID: String
    let schedulerRecordID: String
    let reservationType: String
    let reservationTypeLabel: String
    let startLocal: String
    let endLocal: String
    let operationalTimezone: String
    let status: String
    let lock: ReservationLock
    let aircraft: AircraftSummary
    let mission: MissionSummary?
    let cohort: CohortSummary?
    let crew: [CrewMember]
    let route: ReservationRoute
    let notes: String
    let evidence: ReservationEvidence?
    let updatedAt: String
    let authorizedActions: AuthorizedActions

    var id: String { reservationUUID }
    var localDateKey: String { String(startLocal.prefix(10)) }
    var isCancelled: Bool { status.lowercased() == "cancelled" }
    var isCompleted: Bool { status.lowercased() == "completed" }
    var isInProgress: Bool {
        ["active", "claimed"].contains(status.lowercased())
    }
    var title: String {
        let missionName = mission?.name?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
        return missionName.isEmpty ? reservationTypeLabel : missionName
    }
    var missionLine: String? {
        let parts = [mission?.code, mission?.name]
            .compactMap { $0?.trimmingCharacters(in: .whitespacesAndNewlines) }
            .filter { !$0.isEmpty }
        return parts.isEmpty ? nil : parts.joined(separator: " · ")
    }
    var crewSummary: String? {
        let names = crew.map(\.personName).filter { !$0.isEmpty }
        return names.isEmpty ? nil : names.joined(separator: " · ")
    }

    enum CodingKeys: String, CodingKey {
        case status, lock, aircraft, mission, cohort, crew, route, notes, evidence
        case reservationUUID = "reservation_uuid"
        case schedulerRecordID = "scheduler_record_id"
        case reservationType = "reservation_type"
        case reservationTypeLabel = "reservation_type_label"
        case startLocal = "start_local"
        case endLocal = "end_local"
        case operationalTimezone = "operational_timezone"
        case updatedAt = "updated_at"
        case authorizedActions = "authorized_actions"
    }
}

struct ScheduleRangeResponse: Codable {
    let ok: Bool
    let range: ScheduleRange
    let operationalTimezone: String
    let reservations: [SchedulerReservation]
    let refreshedAt: String?

    enum CodingKeys: String, CodingKey {
        case ok, range, reservations
        case operationalTimezone = "operational_timezone"
        case refreshedAt = "refreshed_at"
    }
}

struct SchedulerWarning: Codable, Hashable, Identifiable {
    let code: String
    let resourceType: String?
    let resourceID: Int?
    let message: String
    let conflictingReservationUUID: String?

    var id: String { "\(code)-\(resourceID ?? 0)-\(conflictingReservationUUID ?? "")" }

    enum CodingKeys: String, CodingKey {
        case code, message
        case resourceType = "resource_type"
        case resourceID = "resource_id"
        case conflictingReservationUUID = "conflicting_reservation_uuid"
    }
}

struct SchedulerValidation: Codable, Hashable {
    let result: String
    let warnings: [SchedulerWarning]
}

struct ReservationDetailResponse: Codable {
    let ok: Bool
    let operationalTimezone: String
    let reservation: SchedulerReservation
    let validation: SchedulerValidation?

    enum CodingKeys: String, CodingKey {
        case ok, reservation, validation
        case operationalTimezone = "operational_timezone"
    }
}

struct SchedulerResourceItem: Codable, Hashable, Identifiable {
    let id: Int
    let registration: String?
    let displayName: String?
    let aircraftType: String?
    let homeAirport: String?
    let role: String?
    let code: String?
    let name: String?

    var label: String {
        registration ?? displayName ?? [code, name].compactMap { $0 }.joined(separator: " · ")
    }

    enum CodingKeys: String, CodingKey {
        case id, registration, role, code, name
        case displayName = "display_name"
        case aircraftType = "aircraft_type"
        case homeAirport = "home_airport"
    }
}

struct SchedulerResourcesResponse: Codable {
    let ok: Bool
    let resourceType: String
    let items: [SchedulerResourceItem]

    enum CodingKeys: String, CodingKey {
        case ok, items
        case resourceType = "resource_type"
    }
}

struct CachedSchedule: Codable {
    let response: ScheduleRangeResponse
    let savedAt: Date
}

struct ScheduleFilters: Codable, Equatable {
    var aircraftID: Int?
    var aircraftLabel: String?
    var participantUserID: Int?
    var participantLabel: String?
    var cohortID: Int?
    var cohortLabel: String?
    var reservationType: String?

    var isEmpty: Bool {
        aircraftID == nil && participantUserID == nil && cohortID == nil && reservationType == nil
    }

    static let empty = ScheduleFilters()
}

enum TodaySection: String, CaseIterable, Identifiable {
    case inProgress = "In Progress"
    case next = "Next"
    case later = "Later Today"
    case completed = "Completed"

    var id: String { rawValue }
}
