import Foundation

/// Display helpers for the Dispatch page Route Overview (informational after create).
enum CVRDispatchRouteOverview {
    struct Leg: Equatable {
        var legUUID: String
        var sequenceNumber: Int
        var departureAirport: String
        var destinationAirport: String
        var status: String
    }

    /// Ordered planned legs by sequence_number ascending.
    static func ordered(_ legs: [Leg]) -> [Leg] {
        legs.sorted {
            if $0.sequenceNumber == $1.sequenceNumber {
                return $0.legUUID < $1.legUUID
            }
            return $0.sequenceNumber < $1.sequenceNumber
        }
    }

    /// Current leg uses active Dispatch `leg_uuid` when present; otherwise `currentLegIndex`.
    static func isCurrent(
        legUUID: String,
        sequenceNumber: Int,
        currentLegUUID: String?,
        currentLegIndex: Int?
    ) -> Bool {
        if let currentLegUUID,
           let normalized = CVROperationalIdentityLocal.normalizeUUID(currentLegUUID) {
            return CVROperationalIdentityLocal.normalizeUUID(legUUID) == normalized
        }
        if let currentLegIndex {
            return sequenceNumber == currentLegIndex
        }
        return false
    }

    /// Plain operational state for Route Overview — never sync/upload terminology.
    static func displayStatus(status: String) -> String {
        switch status.trimmingCharacters(in: .whitespacesAndNewlines).lowercased() {
        case "checked_in", "checked-in":
            return "Checked In"
        case "active":
            return "Active"
        case "dispatched":
            return "Dispatched"
        case "cancelled", "canceled":
            return "Cancelled"
        case "planned", "scheduled", "":
            return "Scheduled"
        default:
            if status.localizedCaseInsensitiveContains("check") {
                return "Checked In"
            }
            return "Scheduled"
        }
    }

    static func isCheckedIn(status: String) -> Bool {
        let normalized = status.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        return normalized == "checked_in"
            || normalized == "checked-in"
            || status.localizedCaseInsensitiveContains("check")
    }

    /// SF Symbol for checked-in legs — matches Log page COMPLETE treatment.
    static let checkedInStatusIcon = "checkmark.seal.fill"

    /// Route corrections are locked once any leg has been dispatched, is in progress, checked in, or cancelled.
    /// Pre-dispatch planned-only routes remain editable (Create Local Dispatch / Dispatch route editor).
    static func isRouteEditingLocked(statuses: [String]) -> Bool {
        statuses.contains { status in
            let normalized = status.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
            return normalized == "active"
                || normalized == "checked_in"
                || normalized == "dispatched"
                || normalized == "cancelled"
                || normalized == "canceled"
        }
    }

    static func routeLine(departure: String, arrival: String) -> String {
        let dep = departure.trimmingCharacters(in: .whitespacesAndNewlines).uppercased()
        let arr = arrival.trimmingCharacters(in: .whitespacesAndNewlines).uppercased()
        let left = dep.isEmpty ? "—" : dep
        let right = arr.isEmpty ? "—" : arr
        return "\(left) → \(right)"
    }
}
