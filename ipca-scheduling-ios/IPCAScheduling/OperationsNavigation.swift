import Foundation

struct OperationsNavigationTarget: Equatable {
    let dayKey: String
    let lens: OperationsLens
    let reservationID: String
    let resourceID: OperationsResourceID
}

enum OperationsNavigator {
    static func target(
        for reservation: SchedulerReservation,
        preferredLens: OperationsLens = .aircraft
    ) -> OperationsNavigationTarget? {
        let projection = OperationsProjector.project(
            reservations: [reservation],
            lens: preferredLens,
            dayKey: reservation.localDateKey
        )
        guard let resourceID = projection.rowID(containing: reservation.id) else {
            return nil
        }
        return OperationsNavigationTarget(
            dayKey: reservation.localDateKey,
            lens: preferredLens,
            reservationID: reservation.id,
            resourceID: resourceID
        )
    }

    static func search(
        reservations: [SchedulerReservation],
        query: String
    ) -> [SchedulerReservation] {
        let needle = normalized(query)
        guard !needle.isEmpty else { return [] }
        return reservations
            .filter { reservation in
                let values = [
                    reservation.aircraft.registration,
                    reservation.title,
                    reservation.mission?.code ?? "",
                    reservation.mission?.name ?? "",
                    reservation.crew.map(\.personName).joined(separator: " "),
                    reservation.route.airportChain.joined(separator: " ")
                ]
                return normalized(values.joined(separator: " ")).contains(needle)
            }
            .sorted {
                if $0.startLocal != $1.startLocal { return $0.startLocal < $1.startLocal }
                return $0.id < $1.id
            }
    }

    private static func normalized(_ value: String) -> String {
        value
            .folding(options: [.caseInsensitive, .diacriticInsensitive], locale: .current)
            .trimmingCharacters(in: .whitespacesAndNewlines)
            .lowercased()
    }
}
