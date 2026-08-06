import Foundation

/// Runtime classification checks for Garmin SD Card Import candidate scanning.
/// Compile: swiftc -parse-as-library GarminCsvClassifier.swift G3XFlightStreamParser.swift this.swift -o /tmp/cvr_sd_classify
/// (paths relative to IPCACVRUnit Services/Shared)

@main
enum GarminSDCardClassificationCheck {
    static func main() throws {
        let root = URL(fileURLWithPath: FileManager.default.currentDirectoryPath)
        let fixtures = root.appendingPathComponent("tests/fixtures/garmin")
        let rich = fixtures.appendingPathComponent("full_avionics.csv")
        let gps = fixtures.appendingPathComponent("gps_only.csv")

        // Data-rich fixture
        switch GarminCsvClassifier.classifyImportCandidate(fileURL: rich) {
        case .classified(let classification):
            require(classification.isDataRich, "full_avionics is data-rich")
            require(
                classification.dataLogType == .fullAvionics || classification.dataLogType == .partialAvionics,
                "full_avionics classified as avionics"
            )
        default:
            fail("full_avionics must classify as data-rich Garmin")
        }

        // GPS-only fixture
        switch GarminCsvClassifier.classifyImportCandidate(fileURL: gps) {
        case .gpsOnly:
            break
        case .classified(let classification):
            require(!classification.isDataRich && classification.dataLogType == .gpsOnly, "gps_only not data-rich")
        default:
            fail("gps_only must classify as GPS-only / not importable")
        }

        // Empty file
        let empty = FileManager.default.temporaryDirectory.appendingPathComponent("empty_garmin_test.csv")
        try Data().write(to: empty)
        defer { try? FileManager.default.removeItem(at: empty) }
        switch GarminCsvClassifier.classifyImportCandidate(fileURL: empty) {
        case .invalid:
            break
        default:
            fail("empty file must be invalid")
        }

        // Unrelated CSV
        let unrelated = FileManager.default.temporaryDirectory.appendingPathComponent("unrelated_garmin_test.csv")
        try "name,value\nalice,1\n".write(to: unrelated, atomically: true, encoding: .utf8)
        defer { try? FileManager.default.removeItem(at: unrelated) }
        switch GarminCsvClassifier.classifyImportCandidate(fileURL: unrelated) {
        case .unsupported, .invalid, .unknown:
            break
        case .classified(let classification) where !classification.isDataRich:
            break
        default:
            fail("unrelated CSV must not be data-rich importable")
        }

        // Header order / case normalization via classify(headers:)
        let reordered = GarminCsvClassifier.classify(headers: [
            "Longitude (deg)", "RPM", "Latitude (deg)", "Date (yyyy-mm-dd)", "Pitch (deg)"
        ])
        require(reordered.isDataRich, "header order irrelevant for data-rich")

        let latLonOnly = GarminCsvClassifier.classify(headers: ["timestamp", "latitude", "longitude"])
        require(latLonOnly.dataLogType == .gpsOnly && !latLonOnly.isDataRich, "lat/lon alone is GPS-only")

        print("PASS garmin SD card classification runtime checks")
    }

    static func require(_ condition: Bool, _ message: String) {
        if !condition { fail(message) }
    }

    static func fail(_ message: String) -> Never {
        fputs("FAIL: \(message)\n", stderr)
        exit(1)
    }
}
