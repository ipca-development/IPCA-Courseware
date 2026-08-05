import Foundation

/// One planned leg in the Create Local Dispatch editor (pre-Dispatch).
struct CVRLocalDispatchDraftLeg: Codable, Equatable, Identifiable {
    /// Immutable identity; sequence display is independent.
    var legUUID: String
    var departureAirport: String
    var arrivalAirport: String
    /// planned | active | checked_in | dispatched — erase blocked unless planned.
    var status: String

    var id: String { legUUID }

    var isErasable: Bool {
        let normalized = status.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        return normalized.isEmpty || normalized == "planned"
    }
}

/// Offline draft for Create Local Dispatch: mission + ordered legs with stable UUIDs.
struct CVRLocalDispatchDraft: Codable, Equatable {
    var reservationUUID: String
    var selectedMissionCode: String
    var legs: [CVRLocalDispatchDraftLeg]

    static let persistenceKey = "cvr_local_dispatch_draft_v1"

    static func fresh(homeAirport: String = "") -> CVRLocalDispatchDraft {
        let dep = CVROperationalIdentityLocal.normalizeAirport(homeAirport)
        return CVRLocalDispatchDraft(
            reservationUUID: UUID().uuidString.lowercased(),
            selectedMissionCode: "",
            legs: [
                CVRLocalDispatchDraftLeg(
                    legUUID: UUID().uuidString.lowercased(),
                    departureAirport: dep,
                    arrivalAirport: "",
                    status: "planned"
                ),
            ]
        )
    }

    /// Ordered airport chain for create: [dep0, arr0/dep1, arr1, …].
    var airportChain: [String] {
        guard let first = legs.first else { return [] }
        var chain = [CVROperationalIdentityLocal.normalizeAirport(first.departureAirport)]
        for leg in legs {
            chain.append(CVROperationalIdentityLocal.normalizeAirport(leg.arrivalAirport))
        }
        return chain
    }

    var legUUIDs: [String] { legs.map(\.legUUID) }

    mutating func addLeg() {
        let previousArrival = CVROperationalIdentityLocal.normalizeAirport(legs.last?.arrivalAirport ?? "")
        legs.append(
            CVRLocalDispatchDraftLeg(
                legUUID: UUID().uuidString.lowercased(),
                departureAirport: previousArrival,
                arrivalAirport: "",
                status: "planned"
            )
        )
    }

    /// Returns false when erase is refused (sole leg, or non-planned status).
    @discardableResult
    mutating func eraseLeg(id: String) -> Bool {
        guard let index = legs.firstIndex(where: { $0.legUUID == id }) else { return false }
        if legs.count == 1 { return false }
        guard legs[index].isErasable else { return false }
        legs.remove(at: index)
        reapplyContinuity()
        return true
    }

    mutating func setDeparture(legIndex: Int, airport: String) {
        guard legs.indices.contains(legIndex) else { return }
        // Only Leg 1 departure is user-editable; later deps are inherited.
        guard legIndex == 0 else { return }
        legs[0].departureAirport = Self.sanitizeAirportInput(airport)
        reapplyContinuity()
    }

    mutating func setArrival(legIndex: Int, airport: String) {
        guard legs.indices.contains(legIndex) else { return }
        // Edit only this leg's arrival. Continuity updates the next leg's departure —
        // it must never blank another leg's destination.
        legs[legIndex].arrivalAirport = Self.sanitizeAirportInput(airport)
        reapplyContinuity()
    }

    /// Propagate each leg's arrival into the next leg's departure.
    mutating func reapplyContinuity() {
        guard !legs.isEmpty else { return }
        for index in 1..<legs.count {
            legs[index].departureAirport =
                CVROperationalIdentityLocal.normalizeAirport(legs[index - 1].arrivalAirport)
        }
    }

    var canSubmit: Bool { validationMessage == nil }

    /// Plain-language validation; nil when ready to create.
    var validationMessage: String? {
        let mission = selectedMissionCode.trimmingCharacters(in: .whitespacesAndNewlines)
        if mission.isEmpty {
            return "Select a flight mission."
        }
        guard !legs.isEmpty else {
            return "Add at least one flight leg."
        }

        let firstDep = CVROperationalIdentityLocal.normalizeAirport(legs[0].departureAirport)
        if firstDep.isEmpty {
            return "Enter the departure airport."
        }
        if !Self.isValidICAOIdentifier(firstDep) {
            return "Airport code must be a valid ICAO identifier."
        }

        for (index, leg) in legs.enumerated() {
            let arr = CVROperationalIdentityLocal.normalizeAirport(leg.arrivalAirport)
            if arr.isEmpty {
                return index == 0
                    ? "Enter the destination airport."
                    : "Enter the destination for Leg \(index + 1)."
            }
            if !Self.isValidICAOIdentifier(arr) {
                return "Airport code must be a valid ICAO identifier."
            }
            if index > 0 {
                let expectedDep = CVROperationalIdentityLocal.normalizeAirport(legs[index - 1].arrivalAirport)
                let actualDep = CVROperationalIdentityLocal.normalizeAirport(leg.departureAirport)
                if expectedDep != actualDep || expectedDep.isEmpty {
                    return "Enter the destination for Leg \(index)."
                }
            }
        }
        return nil
    }

    // MARK: - Airport helpers

    static func sanitizeAirportInput(_ value: String) -> String {
        let upper = value.uppercased()
        let filtered = upper.filter { $0.isLetter || $0.isNumber }
        return String(filtered.prefix(4))
    }

    /// ICAO-style identifier: 3–4 alphanumeric characters (matches normalizeAirport uppercase).
    static func isValidICAOIdentifier(_ value: String) -> Bool {
        let normalized = CVROperationalIdentityLocal.normalizeAirport(value)
        guard (3...4).contains(normalized.count) else { return false }
        return normalized.unicodeScalars.allSatisfy { CharacterSet.alphanumerics.contains($0) }
    }

    // MARK: - Mission filter

    /// Aircraft flight-training missions only (exclude briefing, simulator, theory, meetings).
    static func isAircraftFlightMission(code: String, description: String) -> Bool {
        let haystack = (description + " " + code).uppercased()

        let excluded = [
            "FSTD", "SIMULATOR", "AATD", "FNPT", "BRIEFING", "THEORY", "MEETING",
            "GROUND SCHOOL", "CLASSROOM",
        ]
        for token in excluded where haystack.contains(token) {
            return false
        }
        // Lesson/brief hour tags: "(1.0h LB)" without DUAL/PIC/SOLO.
        if haystack.range(of: #"\bLB\b"#, options: .regularExpression) != nil {
            let hasFlight = haystack.contains("DUAL") || haystack.contains("PIC") || haystack.contains("SOLO")
            if !hasFlight { return false }
        }
        // Require an aircraft-flight activity marker when present in catalogue.
        if haystack.contains("DUAL") || haystack.contains("PIC") || haystack.contains("SOLO")
            || haystack.contains("NIGHT") || haystack.contains("X-C") || haystack.contains("XC") {
            return true
        }
        // Unknown catalogue shape: exclude rather than allow free-form non-flight.
        return false
    }

    static func missionPickerTitle(code: String, description: String) -> String {
        let name = description.trimmingCharacters(in: .whitespacesAndNewlines)
        if name.isEmpty { return code }
        return "\(code) — \(name)"
    }

    // MARK: - Persistence

    static func load(from defaults: UserDefaults = .standard) -> CVRLocalDispatchDraft? {
        guard let data = defaults.data(forKey: persistenceKey) else { return nil }
        return try? JSONDecoder().decode(CVRLocalDispatchDraft.self, from: data)
    }

    func save(to defaults: UserDefaults = .standard) {
        guard let data = try? JSONEncoder().encode(self) else { return }
        defaults.set(data, forKey: Self.persistenceKey)
    }

    static func clear(from defaults: UserDefaults = .standard) {
        defaults.removeObject(forKey: persistenceKey)
    }
}
