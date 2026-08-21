import XCTest
@testable import IPCAScheduling

final class TimelineGeometryTests: XCTestCase {
    func testOperationalWindowUsesStableBaselineAndPredictablePadding() {
        let sparse = TimelineDayWindow.operationalDay(
            dayKey: "2026-08-20",
            spans: [
                TimelineReservationSpan(
                    startLocal: "2026-08-20T08:00:00.000",
                    endLocal: "2026-08-20T09:00:00.000"
                )
            ]
        )
        let early = TimelineDayWindow.operationalDay(
            dayKey: "2026-08-20",
            spans: [
                TimelineReservationSpan(
                    startLocal: "2026-08-20T04:40:00.000",
                    endLocal: "2026-08-20T05:10:00.000"
                )
            ]
        )

        XCTAssertEqual(sparse.startMinute, 360)
        XCTAssertEqual(sparse.endMinute, 1_320)
        XCTAssertEqual(early.startMinute, 210)
        XCTAssertEqual(early.endMinute, 1_320)
    }

    func testGeometryProducesDeterministicXAndWidthFromOperationalWallClock() throws {
        let window = TimelineDayWindow(dayKey: "2026-03-08", startMinute: 360, endMinute: 1_320)
        let geometry = TimelineGeometry(window: window, contentWidth: 960)

        let frame = try XCTUnwrap(
            geometry.frame(
                startLocal: "2026-03-08T08:00:00.000",
                endLocal: "2026-03-08T10:00:00.000"
            )
        )

        XCTAssertEqual(frame.x, 120, accuracy: 0.0001)
        XCTAssertEqual(frame.width, 120, accuracy: 0.0001)
    }

    func testFramesClipAtDayWindowAndViewport() throws {
        let window = TimelineDayWindow(dayKey: "2026-08-20", startMinute: 360, endMinute: 1_320)
        let geometry = TimelineGeometry(window: window, contentWidth: 960)

        let dayClipped = try XCTUnwrap(
            geometry.frame(
                startLocal: "2026-08-19T23:00:00.000",
                endLocal: "2026-08-20T07:00:00.000"
            )
        )
        XCTAssertEqual(dayClipped, TimelineItemFrame(x: 0, width: 60))

        let viewportClipped = try XCTUnwrap(
            geometry.clippedFrame(
                startLocal: "2026-08-20T08:00:00.000",
                endLocal: "2026-08-20T10:00:00.000",
                viewport: 150 ... 200
            )
        )
        XCTAssertEqual(viewportClipped.x, 150)
        XCTAssertEqual(viewportClipped.width, 50)
        XCTAssertTrue(viewportClipped.isLeadingClipped)
        XCTAssertTrue(viewportClipped.isTrailingClipped)
        XCTAssertNil(
            geometry.clippedFrame(
                startLocal: "2026-08-20T08:00:00.000",
                endLocal: "2026-08-20T10:00:00.000",
                viewport: 300 ... 400
            )
        )
    }

    func testFullDayStandardAndDetailedScalesArePredictable() {
        let window = TimelineDayWindow(dayKey: "2026-08-20", startMinute: 360, endMinute: 1_320)

        XCTAssertEqual(TimelineScale.fullDay.title, "Full Day")
        XCTAssertEqual(TimelineScale.fullDay.contentWidth(viewportWidth: 400, window: window), 400)
        XCTAssertEqual(TimelineScale.standard.contentWidth(viewportWidth: 400, window: window), 960)
        XCTAssertEqual(TimelineScale.detailed.contentWidth(viewportWidth: 400, window: window), 1_920)
    }

    func testCivilTwilightDefinesMorningAndEveningFAANightRegions() throws {
        let window = TimelineDayWindow(dayKey: "2026-08-19", startMinute: 300, endMinute: 1_320)
        let geometry = TimelineGeometry(window: window, contentWidth: 1_020)
        let layout = try XCTUnwrap(
            TimelineAstronomyLayout.make(day: astronomyDay, geometry: geometry)
        )

        XCTAssertEqual(try XCTUnwrap(layout.morningNight).x, 0, accuracy: 0.001)
        XCTAssertEqual(try XCTUnwrap(layout.morningNight).width, 43.9167, accuracy: 0.01)
        XCTAssertEqual(try XCTUnwrap(layout.morningTwilight).width, 25.9667, accuracy: 0.01)
        XCTAssertEqual(try XCTUnwrap(layout.eveningTwilight).width, 25.9667, accuracy: 0.01)
        XCTAssertEqual(
            try XCTUnwrap(layout.eveningNight).x,
            try XCTUnwrap(layout.eveningCivilTwilightX),
            accuracy: 0.001
        )
        XCTAssertLessThan(
            try XCTUnwrap(layout.morningCivilTwilightX),
            try XCTUnwrap(layout.sunriseX)
        )
        XCTAssertLessThan(
            try XCTUnwrap(layout.sunsetX),
            try XCTUnwrap(layout.eveningCivilTwilightX)
        )
    }

    func testAstronomyMarkersScaleWithAllTimelineDensities() throws {
        let window = TimelineDayWindow(dayKey: "2026-08-19", startMinute: 300, endMinute: 1_320)
        let full = TimelineGeometry(window: window, viewportWidth: 510, scale: .fullDay)
        let standard = TimelineGeometry(window: window, viewportWidth: 510, scale: .standard)
        let detailed = TimelineGeometry(window: window, viewportWidth: 510, scale: .detailed)
        let fullLayout = try XCTUnwrap(TimelineAstronomyLayout.make(day: astronomyDay, geometry: full))
        let standardLayout = try XCTUnwrap(TimelineAstronomyLayout.make(day: astronomyDay, geometry: standard))
        let detailedLayout = try XCTUnwrap(TimelineAstronomyLayout.make(day: astronomyDay, geometry: detailed))

        XCTAssertEqual(
            try XCTUnwrap(standardLayout.sunsetX),
            try XCTUnwrap(fullLayout.sunsetX) * 2,
            accuracy: 0.01
        )
        XCTAssertEqual(
            try XCTUnwrap(detailedLayout.sunsetX),
            try XCTUnwrap(standardLayout.sunsetX) * 2,
            accuracy: 0.01
        )
    }

    func testAstronomyMarkersRemainInTimelineCoordinatesDuringScrolling() throws {
        let window = TimelineDayWindow(dayKey: "2026-08-19", startMinute: 300, endMinute: 1_320)
        let geometry = TimelineGeometry(window: window, contentWidth: 2_040)
        let layout = try XCTUnwrap(TimelineAstronomyLayout.make(day: astronomyDay, geometry: geometry))
        let contentX = try XCTUnwrap(layout.sunsetX)
        let horizontalScrollOffset = 1_100.0

        XCTAssertEqual(contentX, geometry.x(forMinute: 1_166.5), accuracy: 0.01)
        XCTAssertEqual(contentX - horizontalScrollOffset, 633, accuracy: 0.01)
    }

    func testOperationalLocalAstronomyGeometryHandlesDSTAndSeasonalTwilight() throws {
        let spring = SchedulerAstronomyDay(
            date: "2026-03-08",
            morningCivilTwilightBegin: "2026-03-08T06:38:48.000",
            sunrise: "2026-03-08T07:03:35.000",
            sunset: "2026-03-08T18:47:01.000",
            eveningCivilTwilightEnd: "2026-03-08T19:11:48.000",
            operationalTimezone: "America/Los_Angeles",
            locationID: 1,
            airportIdentifier: "KTRM",
            calculationMethod: "php_date_sun_info_civil_twilight_v1"
        )
        let winter = SchedulerAstronomyDay(
            date: "2026-12-21",
            morningCivilTwilightBegin: "2026-12-21T06:17:42.000",
            sunrise: "2026-12-21T06:45:13.000",
            sunset: "2026-12-21T16:40:29.000",
            eveningCivilTwilightEnd: "2026-12-21T17:08:00.000",
            operationalTimezone: "America/Los_Angeles",
            locationID: 1,
            airportIdentifier: "KTRM",
            calculationMethod: "php_date_sun_info_civil_twilight_v1"
        )
        let springGeometry = TimelineGeometry(
            window: TimelineDayWindow(dayKey: spring.date, startMinute: 0, endMinute: 1_440),
            contentWidth: 1_440
        )
        let winterGeometry = TimelineGeometry(
            window: TimelineDayWindow(dayKey: winter.date, startMinute: 0, endMinute: 1_440),
            contentWidth: 1_440
        )
        let springLayout = try XCTUnwrap(TimelineAstronomyLayout.make(day: spring, geometry: springGeometry))
        let winterLayout = try XCTUnwrap(TimelineAstronomyLayout.make(day: winter, geometry: winterGeometry))

        XCTAssertEqual(try XCTUnwrap(springLayout.sunriseX), 423.5833, accuracy: 0.01)
        XCTAssertEqual(try XCTUnwrap(winterLayout.sunriseX), 405.2167, accuracy: 0.01)
        XCTAssertNotEqual(
            try XCTUnwrap(springLayout.eveningTwilight).width,
            try XCTUnwrap(winterLayout.eveningTwilight).width,
            accuracy: 0.01
        )
    }

    private var astronomyDay: SchedulerAstronomyDay {
        SchedulerAstronomyDay(
            date: "2026-08-19",
            morningCivilTwilightBegin: "2026-08-19T05:43:55.000",
            sunrise: "2026-08-19T06:09:53.000",
            sunset: "2026-08-19T19:26:30.000",
            eveningCivilTwilightEnd: "2026-08-19T19:52:28.000",
            operationalTimezone: "America/Los_Angeles",
            locationID: 1,
            airportIdentifier: "KTRM",
            calculationMethod: "php_date_sun_info_civil_twilight_v1"
        )
    }
}
