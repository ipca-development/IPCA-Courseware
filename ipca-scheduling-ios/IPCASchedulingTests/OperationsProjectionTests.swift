import XCTest
@testable import IPCAScheduling

final class OperationsProjectionTests: XCTestCase {
    func testLensesReuseCanonicalReservationsAndCreateResourceRows() {
        let first = PresentationTestReservations.make(
            id: "first",
            aircraftID: 2,
            registration: "N900ZZ",
            start: "2026-08-20T08:00:00.000",
            end: "2026-08-20T09:00:00.000",
            instructorID: 10,
            instructorName: "Instructor One",
            studentID: 20,
            studentName: "Student One"
        )
        let second = PresentationTestReservations.make(
            id: "second",
            aircraftID: 1,
            registration: "N100AA",
            start: "2026-08-20T09:00:00.000",
            end: "2026-08-20T10:00:00.000",
            instructorID: 10,
            instructorName: "Instructor One",
            studentID: 21,
            studentName: "Student Two"
        )

        let aircraft = OperationsProjector.project(reservations: [first, second], lens: .aircraft)
        let instructors = OperationsProjector.project(reservations: [first, second], lens: .instructors)
        let students = OperationsProjector.project(reservations: [first, second], lens: .students)

        XCTAssertEqual(aircraft.rows.map(\.resource.primaryLabel), ["N900ZZ", "N100AA"])
        XCTAssertEqual(instructors.rows.count, 1)
        XCTAssertEqual(instructors.rows[0].items.map(\.reservation), [first, second])
        XCTAssertEqual(students.rows.map(\.resource.primaryLabel), ["Student One", "Student Two"])
        XCTAssertEqual(students.rows[0].items[0].reservation.reservationUUID, first.reservationUUID)
    }

    func testSortingStrategyIsReplaceableAndNotBakedAlphabetical() {
        let zulu = PresentationTestReservations.make(
            id: "zulu",
            aircraftID: 2,
            registration: "Zulu",
            start: "2026-08-20T08:00:00.000",
            end: "2026-08-20T09:00:00.000"
        )
        let alpha = PresentationTestReservations.make(
            id: "alpha",
            aircraftID: 1,
            registration: "Alpha",
            start: "2026-08-20T10:00:00.000",
            end: "2026-08-20T11:00:00.000"
        )

        let sourceOrder = OperationsProjector.project(
            reservations: [zulu, alpha],
            lens: .aircraft
        )
        let alphabetical = OperationsProjector.project(
            reservations: [zulu, alpha],
            lens: .aircraft,
            sorting: .comparator { $0.primaryLabel < $1.primaryLabel }
        )

        XCTAssertEqual(sourceOrder.rows.map(\.resource.primaryLabel), ["Zulu", "Alpha"])
        XCTAssertEqual(alphabetical.rows.map(\.resource.primaryLabel), ["Alpha", "Zulu"])
    }

    func testDayFilterAndSelectionRetentionFollowProjectedReservations() {
        let selected = PresentationTestReservations.make(
            id: "selected",
            start: "2026-08-20T08:00:00.000",
            end: "2026-08-20T09:00:00.000"
        )
        let anotherDay = PresentationTestReservations.make(
            id: "tomorrow",
            start: "2026-08-21T08:00:00.000",
            end: "2026-08-21T09:00:00.000"
        )

        let projection = OperationsProjector.project(
            reservations: [selected, anotherDay],
            lens: .aircraft,
            dayKey: "2026-08-20"
        )

        XCTAssertEqual(projection.retainingSelection(selected.id), selected.id)
        XCTAssertNil(projection.retainingSelection(anotherDay.id))
        XCTAssertEqual(projection.rowID(containing: selected.id), .aircraft(selected.aircraft.id))
    }
}

enum PresentationTestReservations {
    static func make(
        id: String,
        aircraftID: Int = 42,
        registration: String = "N428EA",
        start: String,
        end: String,
        instructorID: Int = 10,
        instructorName: String = "Kay Instructor",
        studentID: Int = 20,
        studentName: String = "Sam Student",
        status: String = "scheduled",
        route: [String] = ["KTRM", "KPSP", "KTRM"]
    ) -> SchedulerReservation {
        SchedulerReservation(
            reservationUUID: id,
            schedulerRecordID: id,
            reservationType: "flight_training",
            reservationTypeLabel: "Flight Training",
            startLocal: start,
            endLocal: end,
            operationalTimezone: "America/Los_Angeles",
            status: status,
            lock: ReservationLock(locked: false, reason: nil),
            aircraft: AircraftSummary(
                id: aircraftID,
                registration: registration,
                displayName: "Trainer",
                aircraftType: "C172",
                homeAirport: "KTRM"
            ),
            mission: MissionSummary(id: 1, code: "PPL 1", name: "Pattern Training"),
            cohort: CohortSummary(id: 1, name: "PPL"),
            crew: [
                CrewMember(
                    personID: instructorID,
                    personName: instructorName,
                    role: "instructor",
                    pilotFunction: "PM",
                    isPIC: true
                ),
                CrewMember(
                    personID: studentID,
                    personName: studentName,
                    role: "student",
                    pilotFunction: "PF",
                    isPIC: false
                )
            ],
            route: ReservationRoute(airportChain: route, legs: []),
            notes: "",
            evidence: nil,
            updatedAt: start,
            authorizedActions: AuthorizedActions(
                edit: false,
                reschedule: false,
                cancel: false,
                undispatch: false,
                manualCheckin: false,
                dispatch: false
            )
        )
    }
}
