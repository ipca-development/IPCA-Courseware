import Foundation

struct G3XFlightStreamMetadata: Equatable {
    var aircraftIdent: String
    var product: String
    var startUtc: Date?
    var endUtc: Date?
    var rowCount: Int
}

enum G3XFlightStreamParser {
    static func parse(text: String) throws -> (metadata: G3XFlightStreamMetadata, rows: [[String: String]]) {
        let lines = text.split(whereSeparator: \.isNewline).map(String.init)
        guard let metaLine = lines.first else {
            throw G3XParserError.invalidFormat("File is empty.")
        }

        let aircraftIdent = captureQuotedValue(in: metaLine, key: "aircraft_ident") ?? ""
        let product = captureQuotedValue(in: metaLine, key: "product") ?? ""
        guard metaLine.contains("#airframe_info") || !aircraftIdent.isEmpty else {
            throw G3XParserError.invalidFormat("Missing Garmin #airframe_info header.")
        }

        guard lines.count >= 4 else {
            throw G3XParserError.invalidFormat("Garmin CSV header rows are missing.")
        }

        let headers = parseCSVLine(lines[1]).map {
            $0.trimmingCharacters(in: .whitespacesAndNewlines).trimmingCharacters(in: CharacterSet(charactersIn: "#"))
        }
        guard !headers.isEmpty else {
            throw G3XParserError.invalidFormat("Could not read Garmin column headers.")
        }

        var rows: [[String: String]] = []
        rows.reserveCapacity(max(0, lines.count - 3))

        for line in lines.dropFirst(3) {
            let values = parseCSVLine(line)
            if values.allSatisfy({ $0.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty }) {
                continue
            }
            var row: [String: String] = [:]
            for (index, header) in headers.enumerated() {
                let key = header.trimmingCharacters(in: .whitespacesAndNewlines)
                guard !key.isEmpty else { continue }
                row[key] = index < values.count ? values[index] : ""
            }
            rows.append(row)
        }

        guard !rows.isEmpty else {
            throw G3XParserError.invalidFormat("Garmin CSV contains no data rows.")
        }

        let startUtc = rows.compactMap { rowUtcDate(from: $0) }.first
        let endUtc = rows.compactMap { rowUtcDate(from: $0) }.last
        let metadata = G3XFlightStreamMetadata(
            aircraftIdent: aircraftIdent,
            product: product,
            startUtc: startUtc,
            endUtc: endUtc,
            rowCount: rows.count
        )
        return (metadata, rows)
    }

    static func parse(fileURL: URL) throws -> (metadata: G3XFlightStreamMetadata, rows: [[String: String]]) {
        let text = try String(contentsOf: fileURL, encoding: .utf8)
        return try parse(text: text)
    }

    static func rowUtcDate(from row: [String: String]) -> Date? {
        let date = row["Date (yyyy-mm-dd)"]?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
        let utcTime = row["UTC Time (hh:mm:ss)"]?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
        guard !date.isEmpty, !utcTime.isEmpty else { return nil }

        var components = DateComponents()
        components.timeZone = TimeZone(secondsFromGMT: 0)
        let parts = date.split(separator: "-").map(String.init)
        let timeParts = utcTime.split(separator: ":").map(String.init)
        guard parts.count == 3, timeParts.count == 3,
              let year = Int(parts[0]), let month = Int(parts[1]), let day = Int(parts[2]),
              let hour = Int(timeParts[0]), let minute = Int(timeParts[1]), let second = Int(timeParts[2]) else {
            return nil
        }
        components.year = year
        components.month = month
        components.day = day
        components.hour = hour
        components.minute = minute
        components.second = second
        return Calendar(identifier: .gregorian).date(from: components)
    }

    private static func captureQuotedValue(in line: String, key: String) -> String? {
        let pattern = #"\#(key)=\"([^\"]*)\""#
        guard let regex = try? NSRegularExpression(pattern: pattern),
              let match = regex.firstMatch(in: line, range: NSRange(line.startIndex..., in: line)),
              match.numberOfRanges > 1,
              let range = Range(match.range(at: 1), in: line) else {
            return nil
        }
        return String(line[range])
    }

    private static func parseCSVLine(_ line: String) -> [String] {
        var result: [String] = []
        var current = ""
        var inQuotes = false
        let chars = Array(line)
        var index = 0
        while index < chars.count {
            let char = chars[index]
            if char == "\"" {
                if inQuotes, index + 1 < chars.count, chars[index + 1] == "\"" {
                    current.append("\"")
                    index += 1
                } else {
                    inQuotes.toggle()
                }
            } else if char == ",", !inQuotes {
                result.append(current)
                current = ""
            } else {
                current.append(char)
            }
            index += 1
        }
        result.append(current)
        return result
    }
}

enum G3XParserError: LocalizedError {
    case invalidFormat(String)

    var errorDescription: String? {
        switch self {
        case .invalidFormat(let message): message
        }
    }
}
