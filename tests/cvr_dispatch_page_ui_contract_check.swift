import Foundation

private func require(_ condition: @autoclosure () -> Bool, _ message: String) {
    guard condition() else {
        FileHandle.standardError.write(Data(("FAIL " + message + "\n").utf8))
        exit(1)
    }
}

@main
private enum DispatchPageUIContractCheck {
    static func main() {
        let leg1 = "11111111-1111-4111-8111-111111111111"
        let leg2 = "22222222-2222-4222-8222-222222222222"
        let leg3 = "33333333-3333-4333-8333-333333333333"

        let unsorted = [
            makeLeg(uuid: leg3, sequence: 3, dep: "KBUR", arr: "KTRM", status: "planned"),
            makeLeg(uuid: leg1, sequence: 1, dep: "KTRM", arr: "KPSP", status: "checked_in"),
            makeLeg(uuid: leg2, sequence: 2, dep: "KPSP", arr: "KBUR", status: "active"),
        ]

        let ordered = CVRDispatchRouteOverview.ordered(unsorted)
        require(ordered.map(\.legUUID) == [leg1, leg2, leg3], "8 all ordered legs by sequence_number")
        require(CVRDispatchRouteOverview.displayStatus(status: ordered[0].status) == "Checked In",
                "10 checked-in legs show Checked In")
        require(CVRDispatchRouteOverview.isCheckedIn(status: ordered[0].status),
                "checked-in legs use green complete-style marker")
        require(CVRDispatchRouteOverview.checkedInStatusIcon == "checkmark.seal.fill",
                "checked-in marker matches Log COMPLETE seal icon")
        require(CVRDispatchRouteOverview.displayStatus(status: ordered[2].status) == "Scheduled",
                "11 future legs show Scheduled")
        require(CVRDispatchRouteOverview.routeLine(departure: ordered[0].departureAirport, arrival: ordered[0].destinationAirport) == "KTRM → KPSP",
                "route line formatting")

        require(
            CVRDispatchRouteOverview.isCurrent(
                legUUID: ordered[1].legUUID,
                sequenceNumber: ordered[1].sequenceNumber,
                currentLegUUID: leg2,
                currentLegIndex: 2
            ),
            "9 current leg highlighted using leg_uuid"
        )
        require(
            !CVRDispatchRouteOverview.isCurrent(
                legUUID: ordered[0].legUUID,
                sequenceNumber: ordered[0].sequenceNumber,
                currentLegUUID: leg2,
                currentLegIndex: 2
            ),
            "current leg is not inferred only from array position"
        )
        require(
            CVRDispatchRouteOverview.isCurrent(
                legUUID: ordered[1].legUUID,
                sequenceNumber: ordered[1].sequenceNumber,
                currentLegUUID: nil,
                currentLegIndex: 2
            ),
            "currentLegIndex fallback marks LEG 2"
        )

        require(CVRDispatchRouteOverview.isRouteEditingLocked(statuses: ordered.map(\.status)),
                "13 route editing locked after a leg is dispatched/active/checked-in")
        require(!CVRDispatchRouteOverview.isRouteEditingLocked(statuses: ["planned", "planned"]),
                "route remains editable only before first dispatched leg")
        require(CVRDispatchRouteOverview.isRouteEditingLocked(statuses: ["planned", "dispatched", "planned"]),
                "route locks after a leg is dispatched")
        require(CVRDispatchRouteOverview.isRouteEditingLocked(statuses: ["active", "planned"]),
                "route locks while a leg is active in flight")

        // At most one Active: display statuses for a pre-dispatch route are all Scheduled.
        for status in ["planned", "planned", "planned"] {
            require(CVRDispatchRouteOverview.displayStatus(status: status) == "Scheduled",
                    "pre-dispatch legs display as Scheduled")
        }
        require(CVRDispatchRouteOverview.displayStatus(status: "dispatched") == "Dispatched",
                "dispatched leg display status")
        require(CVRDispatchRouteOverview.displayStatus(status: "active") == "Active",
                "in-progress leg display status")

        print("OK: Dispatch page route overview contract checks passed.")
    }

    private static func makeLeg(
        uuid: String,
        sequence: Int,
        dep: String,
        arr: String,
        status: String
    ) -> CVRDispatchRouteOverview.Leg {
        CVRDispatchRouteOverview.Leg(
            legUUID: uuid,
            sequenceNumber: sequence,
            departureAirport: dep,
            destinationAirport: arr,
            status: status
        )
    }
}
