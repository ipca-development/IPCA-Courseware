import Foundation

struct TimelineAccessibilityFormatter {
    /// Retained as presentation context; canonical local values remain
    /// timezone-free wall-clock strings and are never converted through UTC.
    let operationalTimezone: String

    init(operationalTimezone: String) {
        self.operationalTimezone = operationalTimezone
    }

    func summary(
        for reservation: SchedulerReservation,
        lens: OperationsLens? = nil,
        resource: OperationsResource? = nil,
        warningCount: Int = 0
    ) -> String {
        var sentences: [String] = []
        let timeRange = OperationalLocalClock.timeRange(
            start: reservation.startLocal,
            end: reservation.endLocal
        )

        sentences.append("\(reservation.title), \(timeRange)")
        sentences.append("Status: \(spokenStatus(reservation.status))")

        if lens != .aircraft {
            sentences.append("Aircraft: \(reservation.aircraft.registration)")
        } else if let resource {
            sentences.append("Aircraft row: \(resource.primaryLabel)")
        }

        let instructors = crewNames(role: "instructor", in: reservation)
        if lens != .instructors, !instructors.isEmpty {
            sentences.append("Instructor\(instructors.count == 1 ? "" : "s"): \(spokenList(instructors))")
        } else if lens == .instructors, let resource {
            sentences.append("Instructor row: \(resource.primaryLabel)")
        }

        let students = crewNames(role: "student", in: reservation)
        if lens != .students, !students.isEmpty {
            sentences.append("Student\(students.count == 1 ? "" : "s"): \(spokenList(students))")
        } else if lens == .students, let resource {
            sentences.append("Student row: \(resource.primaryLabel)")
        }

        let route = reservation.route.airportChain
            .map { $0.trimmingCharacters(in: .whitespacesAndNewlines) }
            .filter { !$0.isEmpty }
        if !route.isEmpty {
            sentences.append("Route: \(route.joined(separator: " to "))")
        }
        if warningCount > 0 {
            sentences.append(
                warningCount == 1
                    ? "Contains one server warning"
                    : "Contains \(warningCount) server warnings"
            )
        }

        return sentences.map { $0.hasSuffix(".") ? $0 : "\($0)." }.joined(separator: " ")
    }

    private func crewNames(role: String, in reservation: SchedulerReservation) -> [String] {
        reservation.crew.compactMap { member in
            guard member.role.trimmingCharacters(in: .whitespacesAndNewlines).lowercased() == role else {
                return nil
            }
            let name = member.personName.trimmingCharacters(in: .whitespacesAndNewlines)
            return name.isEmpty ? nil : name
        }
    }

    private func spokenStatus(_ status: String) -> String {
        status
            .replacingOccurrences(of: "_", with: " ")
            .replacingOccurrences(of: "-", with: " ")
            .capitalized
    }

    private func spokenList(_ values: [String]) -> String {
        guard let last = values.last else { return "" }
        guard values.count > 1 else { return last }
        if values.count == 2 {
            return "\(values[0]) and \(last)"
        }
        return "\(values.dropLast().joined(separator: ", ")), and \(last)"
    }
}
