import XCTest
@testable import IPCAScheduling

final class OverlapLaneLayoutTests: XCTestCase {
    func testTouchingIntervalsReuseLaneAndStartIndependentGroup() {
        let placements = OverlapLaneLayout.layout([
            OverlapLaneInput(id: "a", start: 0, end: 60),
            OverlapLaneInput(id: "b", start: 60, end: 120),
            OverlapLaneInput(id: "c", start: 120, end: 180)
        ])

        XCTAssertEqual(placements.map(\.lane), [0, 0, 0])
        XCTAssertEqual(placements.map(\.laneCount), [1, 1, 1])
        XCTAssertEqual(placements.map(\.group), [0, 1, 2])
    }

    func testContainedAndThreeWayOverlapUseThreeLanes() {
        let placements = dictionary(
            OverlapLaneLayout.layout([
                OverlapLaneInput(id: "outer", start: 0, end: 100),
                OverlapLaneInput(id: "contained", start: 20, end: 80),
                OverlapLaneInput(id: "third", start: 40, end: 60)
            ])
        )

        XCTAssertEqual(placements["outer"]?.lane, 0)
        XCTAssertEqual(placements["contained"]?.lane, 1)
        XCTAssertEqual(placements["third"]?.lane, 2)
        XCTAssertEqual(Set(placements.values.map(\.laneCount)), [3])
    }

    func testMultipleIndependentOverlapGroupsGetTheirOwnLaneCounts() {
        let placements = dictionary(
            OverlapLaneLayout.layout([
                OverlapLaneInput(id: "a", start: 0, end: 40),
                OverlapLaneInput(id: "b", start: 10, end: 30),
                OverlapLaneInput(id: "c", start: 40, end: 50),
                OverlapLaneInput(id: "d", start: 100, end: 140),
                OverlapLaneInput(id: "e", start: 110, end: 130)
            ])
        )

        XCTAssertEqual(placements["a"]?.laneCount, 2)
        XCTAssertEqual(placements["b"]?.laneCount, 2)
        XCTAssertEqual(placements["c"]?.laneCount, 1)
        XCTAssertEqual(placements["d"]?.laneCount, 2)
        XCTAssertEqual(placements["e"]?.laneCount, 2)
        XCTAssertNotEqual(placements["a"]?.group, placements["d"]?.group)
    }

    func testOnlyAffectedRowsExpand() {
        let layouts = OverlapLaneLayout.layoutRows([
            OverlapRowInput(
                id: "clear",
                intervals: [
                    OverlapLaneInput(id: "a", start: 0, end: 60),
                    OverlapLaneInput(id: "b", start: 60, end: 120)
                ]
            ),
            OverlapRowInput(
                id: "overlap",
                intervals: [
                    OverlapLaneInput(id: "c", start: 0, end: 90),
                    OverlapLaneInput(id: "d", start: 30, end: 60)
                ]
            )
        ])
        let byRow = Dictionary(uniqueKeysWithValues: layouts.map { ($0.id, $0) })

        XCTAssertEqual(byRow["clear"]?.laneCount, 1)
        XCTAssertEqual(byRow["clear"]?.height, 52)
        XCTAssertEqual(byRow["overlap"]?.laneCount, 2)
        XCTAssertEqual(byRow["overlap"]?.height, 92)
    }

    private func dictionary(
        _ placements: [OverlapLanePlacement<String>]
    ) -> [String: OverlapLanePlacement<String>] {
        Dictionary(uniqueKeysWithValues: placements.map { ($0.id, $0) })
    }
}
