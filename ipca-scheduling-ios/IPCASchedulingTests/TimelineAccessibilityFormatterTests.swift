import XCTest
@testable import IPCAScheduling

final class TimelineAccessibilityFormatterTests: XCTestCase {
    private let formatter = TimelineAccessibilityFormatter(
        operationalTimezone: "America/Los_Angeles"
    )

    func testSummaryCombinesTimeStatusResourcesAndRouteCoherently() {
        let reservation = PresentationTestReservations.make(
            id: "accessible",
            start: "2026-08-20T08:00:00.000",
            end: "2026-08-20T10:00:00.000"
        )

        XCTAssertEqual(
            formatter.summary(for: reservation),
            "Pattern Training, 08:00 – 10:00. Status: Scheduled. "
                + "Aircraft: N428EA. Instructor: Kay Instructor. Student: Sam Student. "
                + "Route: KTRM to KPSP to KTRM."
        )
    }

    func testLensContextNamesRowWithoutRepeatingResourceSection() {
        let reservation = PresentationTestReservations.make(
            id: "student-row",
            start: "2026-08-20T13:00:00.000",
            end: "2026-08-20T14:00:00.000"
        )
        let resource = OperationsResource(
            id: .person(id: 20),
            lens: .students,
            primaryLabel: "Sam Student",
            secondaryLabel: "PF"
        )

        let summary = formatter.summary(
            for: reservation,
            lens: .students,
            resource: resource
        )

        XCTAssertTrue(summary.contains("Student row: Sam Student."))
        XCTAssertFalse(summary.contains("Student: Sam Student."))
        XCTAssertTrue(summary.contains("Instructor: Kay Instructor."))
    }

    func testOperationalLocalTimesRemainWallClockTimesAcrossSeasons() {
        let winter = PresentationTestReservations.make(
            id: "winter",
            start: "2026-01-15T10:00:00.000",
            end: "2026-01-15T11:00:00.000"
        )
        let summer = PresentationTestReservations.make(
            id: "summer",
            start: "2026-07-15T10:00:00.000",
            end: "2026-07-15T11:00:00.000"
        )

        XCTAssertTrue(formatter.summary(for: winter).contains("10:00 – 11:00"))
        XCTAssertTrue(formatter.summary(for: summer).contains("10:00 – 11:00"))
    }

    func testWarningCountIsSpokenWithoutColorDependency() {
        let reservation = PresentationTestReservations.make(
            id: "warning",
            start: "2026-08-20T10:00:00.000",
            end: "2026-08-20T11:00:00.000"
        )

        XCTAssertTrue(
            formatter.summary(for: reservation, warningCount: 2)
                .contains("Contains 2 server warnings.")
        )
    }
}
