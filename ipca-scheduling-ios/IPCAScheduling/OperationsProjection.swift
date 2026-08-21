import Foundation

enum OperationsLens: String, CaseIterable, Identifiable {
    case aircraft
    case instructors
    case students

    var id: String { rawValue }

    var title: String {
        switch self {
        case .aircraft: "Aircraft"
        case .instructors: "Instructors"
        case .students: "Students"
        }
    }
}

enum OperationsResourceID: Hashable {
    case aircraft(Int)
    case person(id: Int)
    case namedPerson(normalizedName: String)
}

struct OperationsResource: Identifiable, Hashable {
    let id: OperationsResourceID
    let lens: OperationsLens
    let primaryLabel: String
    let secondaryLabel: String?
    let tertiaryLabel: String?

    init(
        id: OperationsResourceID,
        lens: OperationsLens,
        primaryLabel: String,
        secondaryLabel: String?,
        tertiaryLabel: String? = nil
    ) {
        self.id = id
        self.lens = lens
        self.primaryLabel = primaryLabel
        self.secondaryLabel = secondaryLabel
        self.tertiaryLabel = tertiaryLabel
    }
}

struct OperationsProjectionItem: Identifiable, Hashable {
    let resourceID: OperationsResourceID
    let reservation: SchedulerReservation

    var id: String {
        "\(String(describing: resourceID))-\(reservation.reservationUUID)"
    }

    var reservationID: String { reservation.reservationUUID }
    var startLocal: String { reservation.startLocal }
    var endLocal: String { reservation.endLocal }
}

struct OperationsResourceRow: Identifiable, Hashable {
    let resource: OperationsResource
    let items: [OperationsProjectionItem]

    var id: OperationsResourceID { resource.id }

    func contains(reservationID: String) -> Bool {
        items.contains { $0.reservationID == reservationID }
    }
}

/// A replaceable row-ordering policy. The default deliberately preserves the
/// canonical input's first-seen order instead of imposing alphabetical order.
struct OperationsRowSortingStrategy {
    private let sortRows: ([OperationsResourceRow]) -> [OperationsResourceRow]

    init(_ sortRows: @escaping ([OperationsResourceRow]) -> [OperationsResourceRow]) {
        self.sortRows = sortRows
    }

    func callAsFunction(_ rows: [OperationsResourceRow]) -> [OperationsResourceRow] {
        sortRows(rows)
    }

    static let sourceOrder = OperationsRowSortingStrategy { $0 }

    static func comparator(
        _ areInIncreasingOrder: @escaping (OperationsResource, OperationsResource) -> Bool
    ) -> OperationsRowSortingStrategy {
        OperationsRowSortingStrategy { rows in
            rows.enumerated().sorted { lhs, rhs in
                if areInIncreasingOrder(lhs.element.resource, rhs.element.resource) { return true }
                if areInIncreasingOrder(rhs.element.resource, lhs.element.resource) { return false }
                return lhs.offset < rhs.offset
            }.map(\.element)
        }
    }
}

struct OperationsProjection {
    let lens: OperationsLens
    let rows: [OperationsResourceRow]

    var reservationIDs: Set<String> {
        Set(rows.flatMap(\.items).map(\.reservationID))
    }

    func contains(reservationID: String) -> Bool {
        rows.contains { $0.contains(reservationID: reservationID) }
    }

    /// Keeps a selection only while the selected canonical reservation remains
    /// represented in the newly projected lens/filter result.
    func retainingSelection(_ reservationID: String?) -> String? {
        guard let reservationID, contains(reservationID: reservationID) else { return nil }
        return reservationID
    }

    func rowID(containing reservationID: String?) -> OperationsResourceID? {
        guard let reservationID else { return nil }
        return rows.first { $0.contains(reservationID: reservationID) }?.id
    }
}

enum OperationsProjector {
    static func project(
        reservations: [SchedulerReservation],
        lens: OperationsLens,
        dayKey: String? = nil,
        sorting: OperationsRowSortingStrategy = .sourceOrder
    ) -> OperationsProjection {
        var resources: [OperationsResource] = []
        var itemsByResource: [OperationsResourceID: [OperationsProjectionItem]] = [:]

        for reservation in reservations where dayKey == nil || reservation.localDateKey == dayKey {
            for resource in projectedResources(for: reservation, lens: lens) {
                if itemsByResource[resource.id] == nil {
                    resources.append(resource)
                    itemsByResource[resource.id] = []
                }
                itemsByResource[resource.id, default: []].append(
                    OperationsProjectionItem(resourceID: resource.id, reservation: reservation)
                )
            }
        }

        let rows = resources.map { resource in
            let items = itemsByResource[resource.id, default: []].sorted {
                if $0.startLocal != $1.startLocal { return $0.startLocal < $1.startLocal }
                if $0.endLocal != $1.endLocal { return $0.endLocal < $1.endLocal }
                return $0.reservationID < $1.reservationID
            }
            return OperationsResourceRow(resource: resource, items: items)
        }

        return OperationsProjection(lens: lens, rows: sorting(rows))
    }

    private static func projectedResources(
        for reservation: SchedulerReservation,
        lens: OperationsLens
    ) -> [OperationsResource] {
        switch lens {
        case .aircraft:
            let aircraft = reservation.aircraft
            return [
                OperationsResource(
                    id: .aircraft(aircraft.id),
                    lens: lens,
                    primaryLabel: aircraft.registration,
                    secondaryLabel: aircraft.displayName ?? aircraft.aircraftType,
                    tertiaryLabel: aircraft.displayName == nil ? nil : aircraft.aircraftType
                )
            ]

        case .instructors:
            return people(in: reservation, matchingRole: "instructor", lens: lens)

        case .students:
            return people(in: reservation, matchingRole: "student", lens: lens)
        }
    }

    private static func people(
        in reservation: SchedulerReservation,
        matchingRole role: String,
        lens: OperationsLens
    ) -> [OperationsResource] {
        var seen = Set<OperationsResourceID>()
        return reservation.crew.compactMap { member in
            guard normalized(member.role) == role else { return nil }
            let name = member.personName.trimmingCharacters(in: .whitespacesAndNewlines)
            guard !name.isEmpty else { return nil }
            let id: OperationsResourceID = member.personID.map(OperationsResourceID.person(id:))
                ?? .namedPerson(normalizedName: normalized(name))
            guard seen.insert(id).inserted else { return nil }
            return OperationsResource(
                id: id,
                lens: lens,
                primaryLabel: name,
                secondaryLabel: member.pilotFunction?.nonemptyPresentationValue
            )
        }
    }

    private static func normalized(_ value: String) -> String {
        value.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
    }
}

private extension String {
    var nonemptyPresentationValue: String? {
        let value = trimmingCharacters(in: .whitespacesAndNewlines)
        return value.isEmpty || value.uppercased() == "NONE" ? nil : value
    }
}
