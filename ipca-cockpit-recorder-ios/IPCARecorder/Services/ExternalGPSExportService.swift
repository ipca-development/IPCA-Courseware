import Foundation

enum ExternalGPSExportService {
    static func exportFiles(entries: [ExternalGPSLogEntry]) throws -> [URL] {
        let timestamp = fileTimestampFormatter.string(from: Date())
        let directory = FileManager.default.temporaryDirectory
            .appendingPathComponent("external-gps-test-\(timestamp)", isDirectory: true)
        try FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)

        let jsonURL = directory.appendingPathComponent("external-gps-test-\(timestamp).json")
        let csvURL = directory.appendingPathComponent("external-gps-test-\(timestamp).csv")

        let encoder = JSONEncoder()
        encoder.outputFormatting = [.prettyPrinted, .sortedKeys]
        encoder.dateEncodingStrategy = .iso8601
        try encoder.encode(entries).write(to: jsonURL, options: [.atomic])
        try csv(entries: entries).write(to: csvURL, atomically: true, encoding: .utf8)

        return [jsonURL, csvURL]
    }

    private static func csv(entries: [ExternalGPSLogEntry]) -> String {
        let header = [
            "timestamp",
            "kind",
            "marker",
            "event",
            "latitude",
            "longitude",
            "altitude_meters",
            "horizontal_accuracy",
            "vertical_accuracy",
            "speed_meters_per_second",
            "speed_knots",
            "course_degrees",
            "source_information_available",
            "is_produced_by_accessory",
            "is_simulated_by_software",
            "location_age_seconds"
        ]

        let rows = entries.map { entry in
            [
                isoFormatter.string(from: entry.timestamp),
                entry.kind.rawValue,
                entry.marker,
                entry.event ?? "",
                number(entry.latitude),
                number(entry.longitude),
                number(entry.altitudeMeters),
                number(entry.horizontalAccuracy),
                number(entry.verticalAccuracy),
                number(entry.speedMetersPerSecond),
                number(entry.speedKnots),
                number(entry.courseDegrees),
                bool(entry.sourceInformationAvailable),
                bool(entry.isProducedByAccessory),
                bool(entry.isSimulatedBySoftware),
                number(entry.locationAgeSeconds)
            ].map(escape).joined(separator: ",")
        }

        return ([header.joined(separator: ",")] + rows).joined(separator: "\n") + "\n"
    }

    private static func number(_ value: Double?) -> String {
        guard let value else { return "" }
        return String(format: "%.8f", value)
    }

    private static func bool(_ value: Bool?) -> String {
        guard let value else { return "null" }
        return value ? "true" : "false"
    }

    private static func escape(_ value: String) -> String {
        if value.contains(",") || value.contains("\"") || value.contains("\n") {
            return "\"\(value.replacingOccurrences(of: "\"", with: "\"\""))\""
        }
        return value
    }

    private static let isoFormatter: ISO8601DateFormatter = {
        let formatter = ISO8601DateFormatter()
        formatter.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        formatter.timeZone = TimeZone(secondsFromGMT: 0)
        return formatter
    }()

    private static let fileTimestampFormatter: DateFormatter = {
        let formatter = DateFormatter()
        formatter.dateFormat = "yyyyMMdd-HHmmss"
        return formatter
    }()
}
