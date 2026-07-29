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

enum GarminCsvClassifier {
    static func classify(headers: [String]) -> GarminCsvClassification {
        let normalized = Set(headers.map(normalizeHeader).filter { !$0.isEmpty })

        let hasTime = hasAny(normalized, candidates: ["dateyyyy-mm-dd", "lcldate", "utctimehhmmss", "utctime", "timestamp", "time"])
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

        if !hasTime || !hasGps {
            return GarminCsvClassification(
                dataLogType: .invalid,
                isDataRich: false,
                reason: "Missing usable time and GPS columns."
            )
        }

        let hasAvionics = hasRpm || hasAirframeHours || hasEngineHours || hasFuelFlow || hasFuelQty || hasAttitude || hasAirspeed

        if hasRpm && (hasAirframeHours || hasEngineHours || hasFuelFlow || hasAttitude || hasAirspeed) {
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

    static func classify(fileURL: URL) -> GarminCsvClassification? {
        do {
            let preview = try G3XFlightStreamParser.parsePreview(fileURL: fileURL)
            return classify(headers: preview.headers)
        } catch {
            return nil
        }
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
