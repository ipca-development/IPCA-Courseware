import Combine
import Foundation

struct CVRCrewUser: Identifiable, Codable, Equatable {
    var id: Int
    var name: String
    var email: String
    var role: String

    var displayName: String {
        let trimmed = name.trimmingCharacters(in: .whitespacesAndNewlines)
        return trimmed.isEmpty ? email : trimmed
    }
}

struct CVRMissionCatalogEntry: Identifiable, Codable, Equatable {
    var program: Int
    var stage: Int
    var phase: Int
    var scenario: Int
    var missionCode: String
    var missionDescription: String

    var id: String { missionCode }

    var displayTitle: String {
        "\(missionCode) - \(missionDescription)"
    }
}

struct CrewUsersResponse: Codable {
    var ok: Bool
    var users: [CVRCrewUser]
    var error: String?
}

struct MissionCatalogResponse: Codable {
    var ok: Bool
    var missions: [CVRMissionCatalogEntry]
    var error: String?
}

struct CVRScheduledCrewMember: Codable, Equatable {
    var personID: Int?
    var personName: String
    var role: String

    enum CodingKeys: String, CodingKey {
        case personID = "person_id"
        case id
        case personName = "person_name"
        case name
        case role
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        personID = try container.decodeIfPresent(Int.self, forKey: .personID)
            ?? container.decodeIfPresent(Int.self, forKey: .id)
        personName = try container.decodeIfPresent(String.self, forKey: .personName)
            ?? container.decodeIfPresent(String.self, forKey: .name)
            ?? ""
        role = try container.decodeIfPresent(String.self, forKey: .role) ?? "unknown"
    }

    func encode(to encoder: Encoder) throws {
        var container = encoder.container(keyedBy: CodingKeys.self)
        try container.encodeIfPresent(personID, forKey: .personID)
        try container.encode(personName, forKey: .personName)
        try container.encode(role, forKey: .role)
    }
}

struct CVRScheduledSession: Identifiable, Codable, Equatable {
    private struct AircraftReference: Decodable {
        var id: Int
        var registration: String
    }

    private struct MissionReference: Decodable {
        var code: String
    }

    var schedulerRecordID: String
    var scheduledDate: String
    var scheduledStartTime: String?
    var scheduledEndTime: String?
    var aircraftID: Int
    var aircraftRegistration: String
    var missionCode: String
    var plannedDepartureAirport: String
    var plannedDestinationAirport: String
    var crew: [CVRScheduledCrewMember]
    var status: String
    /// Phase 2B dual-read / Phase 3 multi-leg. Optional for legacy cache compatibility.
    var reservationUUID: String?
    var legUUID: String?
    var identitySource: String?

    var id: String { schedulerRecordID }

    enum CodingKeys: String, CodingKey {
        case schedulerRecordID = "scheduler_record_id"
        case scheduledDate = "scheduled_date"
        case scheduledStartTime = "scheduled_start_time"
        case scheduledEndTime = "scheduled_end_time"
        case aircraftID = "aircraft_id"
        case aircraftRegistration = "aircraft_registration"
        case aircraft
        case missionCode = "mission_code"
        case mission
        case plannedDepartureAirport = "planned_departure_airport"
        case plannedDestinationAirport = "planned_destination_airport"
        case crew
        case status
        case reservationUUID = "reservation_uuid"
        case legUUID = "leg_uuid"
        case identitySource = "identity_source"
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        if let value = try? container.decode(String.self, forKey: .schedulerRecordID) {
            schedulerRecordID = value
        } else {
            schedulerRecordID = String(try container.decode(Int.self, forKey: .schedulerRecordID))
        }
        scheduledDate = try container.decode(String.self, forKey: .scheduledDate)
        scheduledStartTime = try container.decodeIfPresent(String.self, forKey: .scheduledStartTime)
        scheduledEndTime = try container.decodeIfPresent(String.self, forKey: .scheduledEndTime)
        let aircraftReference = try container.decodeIfPresent(AircraftReference.self, forKey: .aircraft)
        aircraftID = try container.decodeIfPresent(Int.self, forKey: .aircraftID) ?? aircraftReference?.id ?? 0
        aircraftRegistration = try container.decodeIfPresent(String.self, forKey: .aircraftRegistration)
            ?? aircraftReference?.registration
            ?? ""
        let missionReference = try container.decodeIfPresent(MissionReference.self, forKey: .mission)
        missionCode = try container.decodeIfPresent(String.self, forKey: .missionCode) ?? missionReference?.code ?? ""
        plannedDepartureAirport = try container.decodeIfPresent(String.self, forKey: .plannedDepartureAirport) ?? ""
        plannedDestinationAirport = try container.decodeIfPresent(String.self, forKey: .plannedDestinationAirport) ?? ""
        crew = try container.decodeIfPresent([CVRScheduledCrewMember].self, forKey: .crew) ?? []
        status = try container.decodeIfPresent(String.self, forKey: .status) ?? ""
        reservationUUID = try container.decodeIfPresent(String.self, forKey: .reservationUUID)
        legUUID = try container.decodeIfPresent(String.self, forKey: .legUUID)
        identitySource = try container.decodeIfPresent(String.self, forKey: .identitySource)
    }

    func encode(to encoder: Encoder) throws {
        var container = encoder.container(keyedBy: CodingKeys.self)
        try container.encode(schedulerRecordID, forKey: .schedulerRecordID)
        try container.encode(scheduledDate, forKey: .scheduledDate)
        try container.encodeIfPresent(scheduledStartTime, forKey: .scheduledStartTime)
        try container.encodeIfPresent(scheduledEndTime, forKey: .scheduledEndTime)
        try container.encode(aircraftID, forKey: .aircraftID)
        try container.encode(aircraftRegistration, forKey: .aircraftRegistration)
        try container.encode(missionCode, forKey: .missionCode)
        try container.encode(plannedDepartureAirport, forKey: .plannedDepartureAirport)
        try container.encode(plannedDestinationAirport, forKey: .plannedDestinationAirport)
        try container.encode(crew, forKey: .crew)
        try container.encode(status, forKey: .status)
        try container.encodeIfPresent(reservationUUID, forKey: .reservationUUID)
        try container.encodeIfPresent(legUUID, forKey: .legUUID)
        try container.encodeIfPresent(identitySource, forKey: .identitySource)
    }

    func dateTime(_ time: String?) -> Date? {
        let day = scheduledDate.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !day.isEmpty else { return nil }
        let clock = time?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
        if !clock.isEmpty, let timestamp = Self.parseDate(clock) {
            return timestamp
        }
        let candidates = clock.isEmpty ? [day] : ["\(day)T\(clock)", "\(day) \(clock)"]
        for value in candidates {
            if let date = Self.parseDate(value) { return date }
        }
        return nil
    }

    private static func parseDate(_ value: String) -> Date? {
        if let date = ISO8601DateFormatter().date(from: value) {
            return date
        }
        for format in ["yyyy-MM-dd'T'HH:mm:ss", "yyyy-MM-dd'T'HH:mm", "yyyy-MM-dd HH:mm:ss", "yyyy-MM-dd HH:mm", "yyyy-MM-dd"] {
            let formatter = DateFormatter()
            formatter.calendar = Calendar(identifier: .gregorian)
            formatter.locale = Locale(identifier: "en_US_POSIX")
            formatter.timeZone = TimeZone(identifier: "America/Los_Angeles") ?? .current
            formatter.dateFormat = format
            if let date = formatter.date(from: value) {
                return date
            }
        }
        return nil
    }
}

/// Schedule display group: one reservation with ordered selectable legs.
struct CVRScheduledReservationGroup: Identifiable, Equatable {
    var id: String
    var reservationUUID: String?
    var dayStart: Date
    var aircraftRegistration: String
    var legs: [CVRScheduledLegItem]

    var routeSummary: String {
        let airports = legs.flatMap { [$0.departureAirport, $0.destinationAirport] }
        var unique: [String] = []
        for airport in airports {
            let trimmed = airport.trimmingCharacters(in: .whitespacesAndNewlines).uppercased()
            guard !trimmed.isEmpty else { continue }
            if unique.last != trimmed {
                unique.append(trimmed)
            }
        }
        return unique.isEmpty ? "ROUTE TBD" : unique.joined(separator: " → ")
    }
}

enum CVRScheduledLegSource: String, Codable, Equatable {
    case scheduledSession
    case localPlanned
}

struct CVRScheduledLegItem: Identifiable, Equatable {
    var id: String
    var sequenceNumber: Int
    var departureAirport: String
    var destinationAirport: String
    var missionCode: String
    var scheduledStartTime: Date?
    var scheduledEndTime: Date?
    var reservationUUID: String?
    var legUUID: String?
    var schedulerRecordID: String?
    var source: CVRScheduledLegSource
    var session: CVRScheduledSession?
    var status: String

    var routeLabel: String {
        let dep = departureAirport.trimmingCharacters(in: .whitespacesAndNewlines).uppercased()
        let arr = destinationAirport.trimmingCharacters(in: .whitespacesAndNewlines).uppercased()
        return "\(dep.isEmpty ? "TBD" : dep) → \(arr.isEmpty ? "TBD" : arr)"
    }
}

enum CVRScheduledReservationGrouping {
    static func groups(
        from sessions: [CVRScheduledSession],
        localLegs: [CVRPlannedLegRecord],
        calendar: Calendar
    ) -> [CVRScheduledReservationGroup] {
        var groups: [CVRScheduledReservationGroup] = []
        var usedLocalLegIDs = Set<String>()

        var byReservation: [String: [CVRScheduledSession]] = [:]
        var singles: [CVRScheduledSession] = []
        for session in sessions {
            if let reservation = session.reservationUUID?.trimmingCharacters(in: .whitespacesAndNewlines).lowercased(),
               !reservation.isEmpty {
                byReservation[reservation, default: []].append(session)
            } else {
                singles.append(session)
            }
        }

        for (reservationUUID, reservationSessions) in byReservation {
            let ordered = reservationSessions.sorted {
                ($0.dateTime($0.scheduledStartTime) ?? .distantFuture)
                    < ($1.dateTime($1.scheduledStartTime) ?? .distantFuture)
            }
            guard let first = ordered.first else { continue }
            let day = calendar.startOfDay(for: first.dateTime(nil) ?? Date())
            let legs: [CVRScheduledLegItem] = ordered.enumerated().map { index, session in
                CVRScheduledLegItem(
                    id: session.legUUID ?? session.schedulerRecordID,
                    sequenceNumber: index + 1,
                    departureAirport: session.plannedDepartureAirport,
                    destinationAirport: session.plannedDestinationAirport,
                    missionCode: session.missionCode,
                    scheduledStartTime: session.dateTime(session.scheduledStartTime),
                    scheduledEndTime: session.dateTime(session.scheduledEndTime),
                    reservationUUID: reservationUUID,
                    legUUID: session.legUUID,
                    schedulerRecordID: session.schedulerRecordID,
                    source: .scheduledSession,
                    session: session,
                    status: "scheduled"
                )
            }
            groups.append(
                CVRScheduledReservationGroup(
                    id: "reservation-\(reservationUUID)",
                    reservationUUID: reservationUUID,
                    dayStart: day,
                    aircraftRegistration: first.aircraftRegistration,
                    legs: legs
                )
            )
        }

        for session in singles {
            let day = calendar.startOfDay(for: session.dateTime(nil) ?? Date())
            groups.append(
                CVRScheduledReservationGroup(
                    id: "session-\(session.schedulerRecordID)",
                    reservationUUID: session.reservationUUID,
                    dayStart: day,
                    aircraftRegistration: session.aircraftRegistration,
                    legs: [
                        CVRScheduledLegItem(
                            id: session.legUUID ?? session.schedulerRecordID,
                            sequenceNumber: 1,
                            departureAirport: session.plannedDepartureAirport,
                            destinationAirport: session.plannedDestinationAirport,
                            missionCode: session.missionCode,
                            scheduledStartTime: session.dateTime(session.scheduledStartTime),
                            scheduledEndTime: session.dateTime(session.scheduledEndTime),
                            reservationUUID: session.reservationUUID,
                            legUUID: session.legUUID,
                            schedulerRecordID: session.schedulerRecordID,
                            source: .scheduledSession,
                            session: session,
                            status: "scheduled"
                        )
                    ]
                )
            )
        }

        let localByReservation = Dictionary(grouping: localLegs.filter { $0.status != "checked_in" }) {
            $0.reservationUUID
        }
        for (reservationUUID, legs) in localByReservation {
            let ordered = legs.sorted { $0.sequenceNumber < $1.sequenceNumber }
            guard let first = ordered.first else { continue }
            if groups.contains(where: { $0.reservationUUID == reservationUUID }) {
                continue
            }
            let day = calendar.startOfDay(for: first.plannedStartAt ?? Date())
            groups.append(
                CVRScheduledReservationGroup(
                    id: "local-\(reservationUUID)",
                    reservationUUID: reservationUUID,
                    dayStart: day,
                    aircraftRegistration: first.tailNumber,
                    legs: ordered.map { planned in
                        usedLocalLegIDs.insert(planned.id)
                        return CVRScheduledLegItem(
                            id: planned.legUUID,
                            sequenceNumber: planned.sequenceNumber,
                            departureAirport: planned.departureAirport,
                            destinationAirport: planned.destinationAirport,
                            missionCode: planned.missionCode,
                            scheduledStartTime: planned.plannedStartAt,
                            scheduledEndTime: planned.plannedEndAt,
                            reservationUUID: planned.reservationUUID,
                            legUUID: planned.legUUID,
                            schedulerRecordID: planned.schedulerRecordID,
                            source: .localPlanned,
                            session: nil,
                            status: planned.status
                        )
                    }
                )
            )
        }

        return groups.sorted {
            if $0.dayStart == $1.dayStart {
                return ($0.legs.first?.scheduledStartTime ?? .distantFuture)
                    < ($1.legs.first?.scheduledStartTime ?? .distantFuture)
            }
            return $0.dayStart < $1.dayStart
        }
    }
}

struct ScheduledSessionsResponse: Codable {
    var ok: Bool
    var sessions: [CVRScheduledSession]
    var error: String?

    enum CodingKeys: String, CodingKey {
        case ok
        case sessions
        case scheduledSessions = "scheduled_sessions"
        case error
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        ok = try container.decode(Bool.self, forKey: .ok)
        sessions = try container.decodeIfPresent([CVRScheduledSession].self, forKey: .sessions)
            ?? container.decodeIfPresent([CVRScheduledSession].self, forKey: .scheduledSessions)
            ?? []
        error = try container.decodeIfPresent(String.self, forKey: .error)
    }

    func encode(to encoder: Encoder) throws {
        var container = encoder.container(keyedBy: CodingKeys.self)
        try container.encode(ok, forKey: .ok)
        try container.encode(sessions, forKey: .sessions)
        try container.encodeIfPresent(error, forKey: .error)
    }
}

@MainActor
final class ScheduledSessionsStore: ObservableObject {
    @Published private(set) var sessions: [CVRScheduledSession] = []
    @Published private(set) var lastError = ""
    @Published private(set) var isRefreshing = false

    func load() async {
        do {
            let url = try cacheURL()
            guard FileManager.default.fileExists(atPath: url.path) else { return }
            sessions = try JSONDecoder().decode([CVRScheduledSession].self, from: Data(contentsOf: url))
        } catch {
            lastError = "Scheduled flights cache could not be loaded: \(error.localizedDescription)"
        }
    }

    func refresh(settings: SettingsStore) async {
        guard let serverURL = settings.normalizedServerURL else {
            lastError = "Server URL is invalid."
            return
        }
        guard let credential = settings.deviceCredential, !credential.isEmpty else {
            lastError = "Enroll this CVR Unit to load scheduled flights."
            return
        }
        isRefreshing = true
        defer { isRefreshing = false }
        do {
            let response = try await APIClient(serverURL: serverURL).scheduledSessions(credential: credential)
            guard response.ok else {
                throw APIClientError.badResponse(response.error ?? "Could not load scheduled flights.")
            }
            sessions = response.sessions
            try JSONEncoder().encode(sessions).write(to: cacheURL(), options: [.atomic])
            lastError = ""
        } catch {
            lastError = error.localizedDescription
        }
    }

    private func cacheURL() throws -> URL {
        let base = try FileManager.default.url(
            for: .applicationSupportDirectory,
            in: .userDomainMask,
            appropriateFor: nil,
            create: true
        )
        let directory = base.appendingPathComponent("IPCACVRUnit", isDirectory: true)
        try FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
        return directory.appendingPathComponent("scheduled-sessions.json")
    }
}
