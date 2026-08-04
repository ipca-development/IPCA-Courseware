import Foundation

private struct TestArchive: Codable, Equatable {
    let id: String
    let evidence: String
}

private func require(_ condition: @autoclosure () -> Bool, _ message: String) {
    guard condition() else {
        FileHandle.standardError.write(Data(("FAIL " + message + "\n").utf8))
        exit(1)
    }
}

@main
private enum Phase1AArchiveRecoveryCheck {
    static func main() throws {
        let validA = #"{"id":"flight-a","evidence":"preserved, with comma"}"#
        let incompatible = #"{"id":"damaged-flight","legacy_evidence":{"nested":[1,2,3]}}"#
        let validB = #"{"id":"flight-b","evidence":"continues"}"#
        let source = Data("[\(validA),\(incompatible),\(validB)]".utf8)
        let rawRecords = try CVRArchiveRecordRecovery.records(in: source)

        require(rawRecords.count == 3, "record splitter must retain every top-level archive record")
        require(String(decoding: rawRecords[1], as: UTF8.self) == incompatible,
                "damaged raw archive evidence must remain byte-for-byte available")

        let decoder = JSONDecoder()
        let valid = rawRecords.compactMap { try? decoder.decode(TestArchive.self, from: $0) }
        require(valid.map(\.id) == ["flight-a", "flight-b"],
                "one incompatible archive must not suppress later valid archives")
        require(valid.first?.evidence == "preserved, with comma",
                "commas and nested JSON must not split archive evidence")

        print("OK: Phase 1A archive record recovery checks passed.")
    }
}
