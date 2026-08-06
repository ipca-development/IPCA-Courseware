import Foundation

enum GarminDataLogType: String, Codable, Equatable {
    case fullAvionics = "FULL_AVIONICS"
    case partialAvionics = "PARTIAL_AVIONICS"
    case gpsOnly = "GPS_ONLY"
    case invalid = "INVALID"
    case unknownSupported = "UNKNOWN_SUPPORTED"
}

struct GarminCsvClassification: Equatable {
    var dataLogType: GarminDataLogType
    var isDataRich: Bool
    var reason: String
}

/// Result of scanning an arbitrary CSV file discovered on an external folder (SD card).
/// Broader than `GarminCsvClassification` because it must also represent files that are
/// not Garmin flight logs at all (unsupported), or that could not be classified reliably.
enum GarminImportCandidateClassification: Equatable {
    case classified(GarminCsvClassification)
    case gpsOnly
    case invalid(String)
    case unsupported(String)
    case unreadable(String)
    case unknown(String)
}

enum GarminCsvClassifier {
    static func classify(headers: [String]) -> GarminCsvClassification {
        let normalized = Set(headers.map(normalizeHeader).filter { !$0.isEmpty })

        let hasTime = hasAny(normalized, candidates: [
            "dateyyyy-mm-dd", "lcldate", "lcltime", "timehhmmss", "utctimehhmmss", "utctime", "timestamp", "time"
        ])
        let hasLat = hasAny(normalized, candidates: ["latitudedeg", "latitude", "lat"])
        let hasLon = hasAny(normalized, candidates: ["longitudedeg", "longitude", "lon", "lng"])
        let hasGps = hasLat && hasLon
        let hasRpm = hasAny(normalized, candidates: ["rpm", "e1rpm", "engine1rpm"])
        let hasAirframeHours = hasAny(normalized, candidates: ["airframehours", "airframehrs", "hobbs"])
        let hasEngineHours = hasAny(normalized, candidates: ["enginehours", "enginehrs", "tach", "tacho"])
        let hasFuelFlow = hasAny(normalized, candidates: ["fuelflowgalhour", "e1fflow", "fuelflow"])
        let hasFuelQty = hasAny(normalized, candidates: ["fuelqtygal", "fqty1", "fuelquantity", "fuelremaining"])
        let hasAttitude = hasAny(normalized, candidates: ["pitchdeg", "rolldeg", "pitch", "roll"])
        let hasAirspeed = hasAny(normalized, candidates: ["indicatedairspeedkt", "trueairspeedkt", "ias", "tas", "airspeed"])
        let hasOil = hasAny(normalized, candidates: ["oilpress", "e1oilp", "oiltemp", "e1oilt", "oilpressure"])
        let hasManifold = hasAny(normalized, candidates: ["manifoldpress", "e1map", "map"])

        if !hasTime || !hasGps {
            return GarminCsvClassification(
                dataLogType: .invalid,
                isDataRich: false,
                reason: "Missing usable time and GPS columns."
            )
        }

        let hasAvionics = hasRpm || hasAirframeHours || hasEngineHours || hasFuelFlow || hasFuelQty || hasAttitude || hasAirspeed || hasOil || hasManifold

        if hasRpm && (hasAirframeHours || hasEngineHours || hasFuelFlow || hasAttitude || hasAirspeed || hasOil || hasManifold) {
            return GarminCsvClassification(
                dataLogType: .fullAvionics,
                isDataRich: true,
                reason: "Contains time, GPS, RPM, and avionics/engine/fuel fields."
            )
        }
        if hasAvionics {
            return GarminCsvClassification(
                dataLogType: .partialAvionics,
                isDataRich: true,
                reason: "Contains GPS plus partial avionics fields."
            )
        }
        return GarminCsvClassification(
            dataLogType: .gpsOnly,
            isDataRich: false,
            reason: "GPS track only; no engine or avionics fields."
        )
    }

    /// Legacy entry point kept for existing callers that only care about Garmin-format
    /// flight logs. Internally delegates to `classifyImportCandidate` so both code paths
    /// share one source of truth; returns `nil` for anything that isn't a usable Garmin log.
    static func classify(fileURL: URL) -> GarminCsvClassification? {
        switch classifyImportCandidate(fileURL: fileURL) {
        case .classified(let classification):
            return classification
        case .gpsOnly:
            return GarminCsvClassification(
                dataLogType: .gpsOnly,
                isDataRich: false,
                reason: "GPS track only; no engine or avionics fields."
            )
        case .invalid, .unsupported, .unreadable, .unknown:
            return nil
        }
    }

    /// Broad classification for arbitrary CSV files found on an external folder (SD card).
    /// Unlike `classify(fileURL:)`, this never returns `nil` — every file resolves to a
    /// concrete bucket so SD-card scanning UI always has something meaningful to show.
    static func classifyImportCandidate(fileURL: URL) -> GarminImportCandidateClassification {
        let fileManager = FileManager.default
        guard fileManager.fileExists(atPath: fileURL.path) else {
            return .unreadable("The file is no longer available.")
        }
        guard let attributes = try? fileManager.attributesOfItem(atPath: fileURL.path),
              let size = attributes[.size] as? Int else {
            return .unreadable("The file size could not be determined.")
        }
        if size == 0 {
            return .invalid("The file is empty.")
        }

        do {
            let preview = try G3XFlightStreamParser.parsePreview(fileURL: fileURL)
            return mapClassification(classify(headers: preview.headers))
        } catch is G3XParserError {
            // Not a recognizable Garmin #airframe_info export. Fall back to reading the
            // first non-empty line as a plain CSV header (no #airframe_info required).
            guard let headers = try? readSimpleCSVHeaders(fileURL: fileURL) else {
                return .unreadable("The file could not be read as CSV.")
            }
            guard !headers.isEmpty else {
                return .unknown("No recognizable column headers were found.")
            }
            let classification = classify(headers: headers)
            if classification.dataLogType == .invalid {
                return .unsupported("This CSV does not contain recognizable flight time/GPS columns.")
            }
            return mapClassification(classification)
        } catch {
            return .unreadable("The file could not be read: \(error.localizedDescription)")
        }
    }

    private static func mapClassification(_ classification: GarminCsvClassification) -> GarminImportCandidateClassification {
        switch classification.dataLogType {
        case .gpsOnly:
            return .gpsOnly
        case .invalid:
            return .unsupported(classification.reason)
        case .fullAvionics, .partialAvionics, .unknownSupported:
            return .classified(classification)
        }
    }

    /// Reads only the first ~64 KB of the file to recover a plain (non-Garmin-prefixed)
    /// CSV header row for lightweight SD-card scanning.
    private static func readSimpleCSVHeaders(fileURL: URL) throws -> [String] {
        let handle = try FileHandle(forReadingFrom: fileURL)
        defer { try? handle.close() }
        var prefix = try handle.read(upToCount: 64 * 1024) ?? Data()
        if prefix.starts(with: [0xEF, 0xBB, 0xBF]) {
            prefix = Data(prefix.dropFirst(3))
        }
        guard let text = String(data: prefix, encoding: .utf8) else {
            throw CocoaError(.fileReadCorruptFile)
        }
        let lines = text
            .replacingOccurrences(of: "\r\n", with: "\n")
            .replacingOccurrences(of: "\r", with: "\n")
            .split(separator: "\n", omittingEmptySubsequences: false)
            .map(String.init)
        guard let firstNonEmpty = lines.first(where: { !$0.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty }) else {
            return []
        }
        return parseSimpleCSVLine(firstNonEmpty)
            .map { $0.trimmingCharacters(in: .whitespacesAndNewlines).trimmingCharacters(in: CharacterSet(charactersIn: "#\"")) }
            .filter { !$0.isEmpty }
    }

    private static func parseSimpleCSVLine(_ line: String) -> [String] {
        var result: [String] = []
        var current = ""
        var inQuotes = false
        for char in line {
            if char == "\"" {
                inQuotes.toggle()
            } else if char == ",", !inQuotes {
                result.append(current)
                current = ""
            } else {
                current.append(char)
            }
        }
        result.append(current)
        return result
    }

    static func normalizeRegistration(_ value: String) -> String {
        value
            .trimmingCharacters(in: .whitespacesAndNewlines)
            .uppercased()
            .replacingOccurrences(of: "-", with: "")
            .replacingOccurrences(of: " ", with: "")
    }

    static func registrationsMatch(_ lhs: String, _ rhs: String) -> Bool {
        let left = normalizeRegistration(lhs)
        let right = normalizeRegistration(rhs)
        guard !left.isEmpty, !right.isEmpty else { return false }
        return left == right
    }

    private static func normalizeHeader(_ header: String) -> String {
        header
            .trimmingCharacters(in: .whitespacesAndNewlines)
            .lowercased()
            .replacingOccurrences(of: " ", with: "")
            .replacingOccurrences(of: "_", with: "")
            .replacingOccurrences(of: "(", with: "")
            .replacingOccurrences(of: ")", with: "")
            .replacingOccurrences(of: "/", with: "")
    }

    private static func hasAny(_ normalized: Set<String>, candidates: [String]) -> Bool {
        candidates.contains { normalized.contains($0) }
    }
}
