import Foundation

struct TimelineReservationSpan: Equatable {
    let startLocal: String
    let endLocal: String

    init(startLocal: String, endLocal: String) {
        self.startLocal = startLocal
        self.endLocal = endLocal
    }

    init(_ reservation: SchedulerReservation) {
        startLocal = reservation.startLocal
        endLocal = reservation.endLocal
    }
}

struct TimelineDayWindow: Equatable {
    let dayKey: String
    let startMinute: Double
    let endMinute: Double

    var durationMinutes: Double { endMinute - startMinute }

    init(dayKey: String, startMinute: Double, endMinute: Double) {
        precondition(startMinute >= 0 && endMinute <= 1_440 && startMinute < endMinute)
        self.dayKey = dayKey
        self.startMinute = startMinute
        self.endMinute = endMinute
    }

    /// Builds one stable, order-independent operational window for a whole day.
    /// The baseline remains visible on sparse days; early/late work extends it
    /// using fixed padding and rounding increments.
    static func operationalDay(
        dayKey: String,
        spans: [TimelineReservationSpan],
        baseline: ClosedRange<Double> = 360 ... 1_320,
        paddingMinutes: Double = 60,
        roundingMinutes: Double = 30
    ) -> TimelineDayWindow {
        precondition(roundingMinutes > 0)
        precondition(paddingMinutes >= 0)

        let bounds = spans.compactMap { span -> (Double, Double)? in
            guard let start = OperationalLocalClock.minute(
                span.startLocal,
                relativeTo: dayKey,
                boundary: .start
            ), let end = OperationalLocalClock.minute(
                span.endLocal,
                relativeTo: dayKey,
                boundary: .end
            ), end > start else {
                return nil
            }
            return (start, end)
        }

        let earliest = bounds.map(\.0).min() ?? baseline.lowerBound
        let latest = bounds.map(\.1).max() ?? baseline.upperBound
        let paddedStart = min(baseline.lowerBound, earliest - paddingMinutes)
        let paddedEnd = max(baseline.upperBound, latest + paddingMinutes)
        let roundedStart = floor(paddedStart / roundingMinutes) * roundingMinutes
        let roundedEnd = ceil(paddedEnd / roundingMinutes) * roundingMinutes

        return TimelineDayWindow(
            dayKey: dayKey,
            startMinute: max(0, roundedStart),
            endMinute: min(1_440, roundedEnd)
        )
    }

    static func operationalDay(
        dayKey: String,
        reservations: [SchedulerReservation],
        baseline: ClosedRange<Double> = 360 ... 1_320,
        paddingMinutes: Double = 60,
        roundingMinutes: Double = 30
    ) -> TimelineDayWindow {
        operationalDay(
            dayKey: dayKey,
            spans: reservations.map(TimelineReservationSpan.init),
            baseline: baseline,
            paddingMinutes: paddingMinutes,
            roundingMinutes: roundingMinutes
        )
    }
}

enum TimelineScale: String, CaseIterable, Identifiable {
    case fullDay
    case standard
    case detailed

    var id: String { rawValue }

    var title: String {
        switch self {
        case .fullDay: "Full Day"
        case .standard: "Standard"
        case .detailed: "Detailed"
        }
    }

    func contentWidth(viewportWidth: Double, window: TimelineDayWindow) -> Double {
        switch self {
        case .fullDay:
            return max(0, viewportWidth)
        case .standard:
            return max(viewportWidth, window.durationMinutes)
        case .detailed:
            return max(viewportWidth, window.durationMinutes * 2)
        }
    }
}

struct TimelineItemFrame: Equatable {
    let x: Double
    let width: Double
}

struct ClippedTimelineItemFrame: Equatable {
    let x: Double
    let width: Double
    let isLeadingClipped: Bool
    let isTrailingClipped: Bool
}

struct TimelineGeometry: Equatable {
    let window: TimelineDayWindow
    let contentWidth: Double

    var pointsPerMinute: Double {
        contentWidth / window.durationMinutes
    }

    init(window: TimelineDayWindow, contentWidth: Double) {
        precondition(contentWidth > 0)
        self.window = window
        self.contentWidth = contentWidth
    }

    init(window: TimelineDayWindow, viewportWidth: Double, scale: TimelineScale) {
        self.init(
            window: window,
            contentWidth: scale.contentWidth(viewportWidth: viewportWidth, window: window)
        )
    }

    func x(forMinute minute: Double) -> Double {
        (minute - window.startMinute) * pointsPerMinute
    }

    func frame(startLocal: String, endLocal: String) -> TimelineItemFrame? {
        guard let start = OperationalLocalClock.minute(
            startLocal,
            relativeTo: window.dayKey,
            boundary: .start
        ), let end = OperationalLocalClock.minute(
            endLocal,
            relativeTo: window.dayKey,
            boundary: .end
        ) else {
            return nil
        }

        let visibleStart = max(window.startMinute, start)
        let visibleEnd = min(window.endMinute, end)
        guard visibleEnd > visibleStart else { return nil }

        return TimelineItemFrame(
            x: x(forMinute: visibleStart),
            width: (visibleEnd - visibleStart) * pointsPerMinute
        )
    }

    func frame(for reservation: SchedulerReservation) -> TimelineItemFrame? {
        frame(startLocal: reservation.startLocal, endLocal: reservation.endLocal)
    }

    /// Clips an already day-clipped item to the horizontal scroll viewport.
    func clippedFrame(
        startLocal: String,
        endLocal: String,
        viewport: ClosedRange<Double>
    ) -> ClippedTimelineItemFrame? {
        guard let frame = frame(startLocal: startLocal, endLocal: endLocal) else { return nil }
        let itemEnd = frame.x + frame.width
        let clippedStart = max(frame.x, viewport.lowerBound)
        let clippedEnd = min(itemEnd, viewport.upperBound)
        guard clippedEnd > clippedStart else { return nil }

        return ClippedTimelineItemFrame(
            x: clippedStart,
            width: clippedEnd - clippedStart,
            isLeadingClipped: clippedStart > frame.x,
            isTrailingClipped: clippedEnd < itemEnd
        )
    }
}

struct TimelineAstronomyRegion: Equatable {
    let x: Double
    let width: Double
}

struct TimelineAstronomyLayout: Equatable {
    let morningNight: TimelineAstronomyRegion?
    let morningTwilight: TimelineAstronomyRegion?
    let eveningTwilight: TimelineAstronomyRegion?
    let eveningNight: TimelineAstronomyRegion?
    let morningCivilTwilightX: Double?
    let sunriseX: Double?
    let sunsetX: Double?
    let eveningCivilTwilightX: Double?

    static func make(
        day: SchedulerAstronomyDay,
        geometry: TimelineGeometry
    ) -> TimelineAstronomyLayout? {
        guard day.date == geometry.window.dayKey,
              let civilBegin = minute(day.morningCivilTwilightBegin, day: day.date),
              let sunrise = minute(day.sunrise, day: day.date),
              let sunset = minute(day.sunset, day: day.date),
              let civilEnd = minute(day.eveningCivilTwilightEnd, day: day.date),
              civilBegin < sunrise,
              sunrise < sunset,
              sunset < civilEnd else {
            return nil
        }

        return TimelineAstronomyLayout(
            morningNight: region(
                from: geometry.window.startMinute,
                to: civilBegin,
                geometry: geometry
            ),
            morningTwilight: region(from: civilBegin, to: sunrise, geometry: geometry),
            eveningTwilight: region(from: sunset, to: civilEnd, geometry: geometry),
            eveningNight: region(
                from: civilEnd,
                to: geometry.window.endMinute,
                geometry: geometry
            ),
            morningCivilTwilightX: marker(civilBegin, geometry: geometry),
            sunriseX: marker(sunrise, geometry: geometry),
            sunsetX: marker(sunset, geometry: geometry),
            eveningCivilTwilightX: marker(civilEnd, geometry: geometry)
        )
    }

    private static func minute(_ value: String?, day: String) -> Double? {
        guard let value else { return nil }
        return OperationalLocalClock.minute(value, relativeTo: day, boundary: .start)
    }

    private static func marker(
        _ minute: Double,
        geometry: TimelineGeometry
    ) -> Double? {
        guard minute >= geometry.window.startMinute,
              minute <= geometry.window.endMinute else {
            return nil
        }
        return geometry.x(forMinute: minute)
    }

    private static func region(
        from start: Double,
        to end: Double,
        geometry: TimelineGeometry
    ) -> TimelineAstronomyRegion? {
        let visibleStart = max(start, geometry.window.startMinute)
        let visibleEnd = min(end, geometry.window.endMinute)
        guard visibleEnd > visibleStart else { return nil }
        return TimelineAstronomyRegion(
            x: geometry.x(forMinute: visibleStart),
            width: (visibleEnd - visibleStart) * geometry.pointsPerMinute
        )
    }
}

enum OperationalLocalBoundary {
    case start
    case end
}

/// Extracts wall-clock positions directly from canonical operational-local
/// values. Deliberately avoiding Date conversion prevents device timezone and
/// daylight-saving transitions from shifting presentation geometry.
enum OperationalLocalClock {
    static func timeRange(start: String, end: String) -> String {
        "\(time(start)) – \(time(end))"
    }

    static func time(_ localValue: String) -> String {
        guard localValue.count >= 16 else { return localValue }
        let hourStart = localValue.index(localValue.startIndex, offsetBy: 11)
        let minuteStart = localValue.index(localValue.startIndex, offsetBy: 14)
        guard let hour = Int(localValue[hourStart ..< localValue.index(hourStart, offsetBy: 2)]),
              let minute = Int(localValue[minuteStart ..< localValue.index(minuteStart, offsetBy: 2)]),
              (0 ... 23).contains(hour),
              (0 ... 59).contains(minute) else {
            return localValue
        }
        return "\(String(format: "%02d", hour)):\(String(format: "%02d", minute))"
    }

    static func minute(
        _ localValue: String,
        relativeTo dayKey: String,
        boundary: OperationalLocalBoundary
    ) -> Double? {
        guard localValue.count >= 16 else { return nil }
        let valueDay = String(localValue.prefix(10))

        if valueDay < dayKey { return 0 }
        if valueDay > dayKey { return 1_440 }

        let hourStart = localValue.index(localValue.startIndex, offsetBy: 11)
        let minuteStart = localValue.index(localValue.startIndex, offsetBy: 14)
        guard let hour = Int(localValue[hourStart ..< localValue.index(hourStart, offsetBy: 2)]),
              let minute = Int(localValue[minuteStart ..< localValue.index(minuteStart, offsetBy: 2)]),
              (0 ... 23).contains(hour),
              (0 ... 59).contains(minute) else {
            return nil
        }

        var result = Double(hour * 60 + minute)
        if localValue.count >= 19 {
            let secondStart = localValue.index(localValue.startIndex, offsetBy: 17)
            if let second = Int(localValue[secondStart ..< localValue.index(secondStart, offsetBy: 2)]),
               (0 ... 59).contains(second) {
                result += Double(second) / 60
            }
        }

        // The boundary parameter documents call-site intent and leaves room for
        // future canonical end-boundary semantics without consulting UTC.
        _ = boundary
        return result
    }
}
