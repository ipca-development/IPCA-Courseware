import XCTest
@testable import IPCAScheduling

final class OperationsNavigationTests: XCTestCase {
    func testSearchPrioritizesLoadedReservationsAcrossOperationalFields() {
        let reservations = SchedulerFixtures.workstationSchedule.reservations

        XCTAssertEqual(
            OperationsNavigator.search(reservations: reservations, query: "Big Bear").first?.id,
            SchedulerFixtures.workstationFeaturedReservation.id
        )
        XCTAssertTrue(
            OperationsNavigator.search(reservations: reservations, query: "Zane Haley")
                .contains { $0.crew.contains { $0.personName == "Zane Haley" } }
        )
        XCTAssertTrue(
            OperationsNavigator.search(reservations: reservations, query: "L35")
                .contains { $0.route.airportChain.contains("L35") }
        )
    }

    func testReservationResultProducesTimelineNavigationTarget() throws {
        let reservation = SchedulerFixtures.workstationFeaturedReservation
        let target = try XCTUnwrap(OperationsNavigator.target(for: reservation))

        XCTAssertEqual(target.dayKey, "2026-08-19")
        XCTAssertEqual(target.lens, .aircraft)
        XCTAssertEqual(target.reservationID, reservation.id)
        XCTAssertEqual(target.resourceID, .aircraft(reservation.aircraft.id))
    }
}
