import XCTest
@testable import IPCAScheduling

final class WorkstationFixtureTests: XCTestCase {
    func testPrimaryFixtureExercisesBusyOperationalDay() {
        let reservations = SchedulerFixtures.workstationSchedule.reservations
            .filter { $0.localDateKey == "2026-08-19" }

        XCTAssertGreaterThanOrEqual(SchedulerFixtures.workstationAircraft.count, 10)
        XCTAssertGreaterThanOrEqual(reservations.count, 18)
        XCTAssertTrue(reservations.contains { durationMinutes($0) == 30 })
        XCTAssertTrue(reservations.contains { durationMinutes($0) == 60 })
        XCTAssertTrue(reservations.contains { durationMinutes($0) == 120 })
        XCTAssertTrue(reservations.contains { durationMinutes($0) == 240 })
        XCTAssertTrue(reservations.contains { $0.route.airportChain.count >= 4 })
        XCTAssertTrue(reservations.contains { $0.status == "claimed" })
        XCTAssertTrue(reservations.contains { $0.status == "completed" })
        XCTAssertGreaterThanOrEqual(SchedulerFixtures.workstationWarnings.count, 3)
    }

    func testSparseFixturePreservesUnusedCanonicalResources() {
        let reservations = SchedulerFixtures.sparseSchedule.reservations
        let usedAircraft = Set(reservations.map(\.aircraft.id))
        let allAircraft = Set(SchedulerFixtures.workstationAircraft.map(\.id))

        XCTAssertEqual(reservations.count, 4)
        XCTAssertGreaterThanOrEqual(allAircraft.count, 10)
        XCTAssertGreaterThanOrEqual(allAircraft.subtracting(usedAircraft).count, 6)
    }

    func testInspectorFixturesCoverCompactAndExceptionalContent() {
        let standard = SchedulerFixtures.workstationFeaturedReservation
        let noInstructor = SchedulerFixtures.workstationNoInstructorReservation
        let multipleCrew = SchedulerFixtures.workstationCrewReservation
        let warning = SchedulerFixtures.workstationWarningReservation
        let completed = SchedulerFixtures.workstationSchedule.reservations.first {
            $0.status == "completed"
        }

        XCTAssertFalse(standard.crew.isEmpty)
        XCTAssertGreaterThanOrEqual(standard.route.legs.count, 2)
        XCTAssertFalse(standard.notes.isEmpty)
        XCTAssertFalse(noInstructor.crew.contains {
            $0.role.lowercased().contains("instructor")
        })
        XCTAssertGreaterThanOrEqual(multipleCrew.crew.count, 4)
        XCTAssertFalse(SchedulerFixtures.workstationWarnings[warning.id]?.isEmpty ?? true)
        XCTAssertNotNil(completed)
    }

    func testWeekFixtureSupportsAllResourceLensesAndEmptyDays() {
        let clock = SchedulerClock(timezoneIdentifier: "America/Los_Angeles")
        let week = clock.week(containing: SchedulerFixtures.now)
        let dayKeys = Set(week.map { clock.dayKey(for: $0) })
        let reservations = SchedulerFixtures.workstationSchedule.reservations.filter {
            dayKeys.contains($0.localDateKey) && !$0.isCancelled
        }

        XCTAssertEqual(week.count, 7)
        XCTAssertTrue(week.contains {
            let key = clock.dayKey(for: $0)
            return !reservations.contains { $0.localDateKey == key }
        })
        for lens in OperationsLens.allCases {
            let projection = OperationsProjector.project(
                reservations: reservations,
                lens: lens
            )
            XCTAssertFalse(projection.rows.isEmpty, "\(lens) Week projection is empty")
            XCTAssertFalse(projection.reservationIDs.isEmpty)
        }
        XCTAssertFalse(SchedulerFixtures.workstationWarnings.isEmpty)
    }

    func testRangeValidationDecodesBackwardCompatibly() throws {
        let withValidation = """
        {
          "reservation_uuid":"test",
          "scheduler_record_id":"test",
          "reservation_type":"flight_training",
          "reservation_type_label":"Flight Training",
          "start_local":"2026-08-19T09:00:00.000",
          "end_local":"2026-08-19T10:00:00.000",
          "operational_timezone":"America/Los_Angeles",
          "status":"scheduled",
          "lock":{"locked":false,"reason":null},
          "aircraft":{"id":42,"registration":"N428EA"},
          "mission":null,
          "cohort":null,
          "crew":[],
          "route":{"airport_chain":[],"legs":[]},
          "notes":"",
          "evidence":null,
          "updated_at":"2026-08-19T08:00:00.000",
          "authorized_actions":{"edit":false,"reschedule":false,"cancel":false,"undispatch":false,"manual_checkin":false,"dispatch":false},
          "validation":{"result":"allowed_with_warning","warnings":[{"code":"aircraft_overlap","resource_type":"aircraft","resource_id":42,"message":"Overlap warning.","conflicting_reservation_uuid":"other"}]}
        }
        """
        let enriched = try JSONDecoder().decode(
            SchedulerReservation.self,
            from: Data(withValidation.utf8)
        )
        XCTAssertEqual(enriched.validation?.warnings.first?.code, "aircraft_overlap")

        let legacy = try JSONEncoder().encode(SchedulerFixtures.featuredReservation)
        XCTAssertNoThrow(try JSONDecoder().decode(SchedulerReservation.self, from: legacy))
    }

    func testSortingStrategyCanBeReplacedWithoutChangingProjection() {
        let reservations = SchedulerFixtures.workstationSchedule.reservations
            .filter { $0.localDateKey == "2026-08-19" }
        let reverse = OperationsRowSortingStrategy.comparator {
            $0.primaryLabel.localizedStandardCompare($1.primaryLabel) == .orderedDescending
        }
        let projection = OperationsProjector.project(
            reservations: reservations,
            lens: .aircraft,
            sorting: reverse
        )

        XCTAssertEqual(projection.rows.first?.resource.primaryLabel, "SIM-1")
        XCTAssertEqual(projection.reservationIDs, Set(reservations.map(\.id)))
    }

    func testStressFixtureProjectsAndLaysOutAtOperationalDensity() {
        let reservations = SchedulerFixtures.stressSchedule.reservations
            .filter { $0.localDateKey == "2026-08-19" }
        XCTAssertGreaterThanOrEqual(reservations.count, 40)
        XCTAssertLessThanOrEqual(SchedulerFixtures.stressSchedule.reservations.count, 50)

        measure {
            for lens in OperationsLens.allCases {
                let projection = OperationsProjector.project(
                    reservations: reservations,
                    lens: lens
                )
                _ = projection.rows.map { row in
                    let intervals: [OverlapLaneInput<String>] = row.items.compactMap { item in
                            guard let start = OperationalLocalClock.minute(
                                item.startLocal,
                                relativeTo: "2026-08-19",
                                boundary: .start
                            ), let end = OperationalLocalClock.minute(
                                item.endLocal,
                                relativeTo: "2026-08-19",
                                boundary: .end
                            ) else {
                                return nil
                            }
                            return OverlapLaneInput(id: item.id, start: start, end: end)
                        }
                    return OverlapLaneLayout.layout(intervals)
                }
            }
        }
    }

    private func durationMinutes(_ reservation: SchedulerReservation) -> Int {
        guard let start = OperationalLocalClock.minute(
            reservation.startLocal,
            relativeTo: reservation.localDateKey,
            boundary: .start
        ), let end = OperationalLocalClock.minute(
            reservation.endLocal,
            relativeTo: reservation.localDateKey,
            boundary: .end
        ) else {
            return 0
        }
        return Int(end - start)
    }
}
