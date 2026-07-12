import Foundation

enum AvionicsBeaconExportService {
    static func exportFiles(entries: [AvionicsBeaconLogEntry]) throws -> [URL] {
        let timestamp = fileTimestampFormatter.string(from: Date())
        let directory = FileManager.default.temporaryDirectory
            .appendingPathComponent("avionics-beacon-test-\(timestamp)", isDirectory: true)
        try FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)

        let jsonURL = directory.appendingPathComponent("avionics-beacon-test-\(timestamp).json")
        let csvURL = directory.appendingPathComponent("avionics-beacon-test-\(timestamp).csv")

        let encoder = JSONEncoder()
        encoder.outputFormatting = [.prettyPrinted, .sortedKeys]
        encoder.dateEncodingStrategy = .iso8601
        try encoder.encode(entries).write(to: jsonURL, options: [.atomic])
        try csv(entries: entries).write(to: csvURL, atomically: true, encoding: .utf8)

        return [jsonURL, csvURL]
    }

    private static func csv(entries: [AvionicsBeaconLogEntry]) -> String {
        let header = [
            "timestamp",
            "kind",
            "marker",
            "event",
            "peripheral_identifier",
            "peripheral_name",
            "advertised_local_name",
            "advertised_service_uuids",
            "manufacturer_data_hex",
            "rssi",
            "seconds_since_previous_advertisement",
            "matched_custom_service"
        ]

        let rows = entries.map { entry in
            [
                isoFormatter.string(from: entry.timestamp),
                entry.kind.rawValue,
                entry.marker,
                entry.event ?? "",
                entry.peripheralIdentifier ?? "",
                entry.peripheralName ?? "",
                entry.advertisedLocalName ?? "",
                entry.advertisedServiceUUIDs.joined(separator: "|"),
                entry.manufacturerDataHex ?? "",
                entry.rssi.map(String.init) ?? "",
                number(entry.secondsSincePreviousAdvertisement),
                bool(entry.matchedCustomService)
            ].map(escape).joined(separator: ",")
        }

        return ([header.joined(separator: ",")] + rows).joined(separator: "\n") + "\n"
    }

    private static func number(_ value: Double?) -> String {
        guard let value else { return "" }
        return String(format: "%.3f", value)
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
