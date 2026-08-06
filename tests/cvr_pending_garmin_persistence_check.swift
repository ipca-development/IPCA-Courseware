import CryptoKit
import Foundation

private func require(_ condition: @autoclosure () -> Bool, _ message: String) {
    guard condition() else {
        FileHandle.standardError.write(Data(("FAIL " + message + "\n").utf8))
        exit(1)
    }
}

@main
private enum PendingGarminPersistenceCheck {
    static func main() throws {
        let root = FileManager.default.temporaryDirectory
            .appendingPathComponent("cvr-pending-garmin-\(UUID().uuidString)", isDirectory: true)
        try FileManager.default.createDirectory(at: root, withIntermediateDirectories: true)
        defer {
            CVRPendingGarminPersistence.testSupportDirectoryOverride = nil
            try? FileManager.default.removeItem(at: root)
        }
        CVRPendingGarminPersistence.testSupportDirectoryOverride = root

        let csvBody = Data("UTC,Latitude,Longitude\n2026-08-05T12:00:00Z,33.6,-116.1\n".utf8)
        let importID = UUID().uuidString.lowercased()
        let relative = CVRPendingGarminPersistence.relativePath(forImportID: importID)
        require(relative == "PendingGarminImports/\(importID).csv", "metadata uses a relative PendingGarminImports path")
        require(!relative.hasPrefix("/"), "relative path must not be absolute")

        let imports = try CVRPendingGarminPersistence.importsDirectory()
        let fileURL = imports.appendingPathComponent("\(importID).csv")
        try csvBody.write(to: fileURL, options: [.atomic])
        let digest = CVRPendingGarminPersistence.sha256Hex(of: csvBody)
        let flightID = "9eea2946-ee49-438d-a5b8-ae11cf19e082"
        let stagedAt = Date(timeIntervalSince1970: 1_775_000_000)

        // 1 + 2: persist metadata with relative path after staging file
        let metadata = CVRPendingGarminMetadata(
            id: importID,
            relativeFilePath: relative,
            originalFilename: "test_log_KTRM.csv",
            sha256: digest,
            targetFlightRecordID: flightID,
            stagedAt: stagedAt,
            lastFailureMessage: "The Garmin file is stored on this device. Synchronize the flight first, then retry. You will not need to select the file again."
        )
        let verified = try CVRPendingGarminPersistence.writeMetadata(metadata)
        require(verified == metadata, "atomic write must decode-verify identical metadata")
        require(FileManager.default.fileExists(atPath: fileURL.path), "staged CSV remains on disk")

        // Fractional Date() must survive ISO8601 encode/decode verify (production staging path).
        var fractional = metadata
        fractional.stagedAt = Date(timeIntervalSince1970: 1_775_000_000.446)
        let fractionalVerified = try CVRPendingGarminPersistence.writeMetadata(fractional)
        require(
            fractionalVerified.stagedAt.timeIntervalSince1970 == 1_775_000_000,
            "writeMetadata normalizes fractional stagedAt for ISO8601 round-trip"
        )
        require(fractionalVerified.id == fractional.id, "fractional write preserves import id")
        require(fractionalVerified.sha256 == fractional.sha256, "fractional write preserves sha256")
        // Restore whole-second metadata for remaining checks.
        _ = try CVRPendingGarminPersistence.writeMetadata(metadata)

        let metadataPath = try CVRPendingGarminPersistence.metadataURL().path
        require(
            FileManager.default.fileExists(atPath: metadataPath),
            "pending-metadata.json exists beside imports"
        )

        // 3: restart restore
        let restored = CVRPendingGarminPersistence.restorePending()
        require(restored.metadata?.id == importID, "restore pending UUID")
        require(restored.metadata?.originalFilename == "test_log_KTRM.csv", "restore original filename")
        require(restored.metadata?.sha256 == digest, "restore SHA-256")
        require(restored.metadata?.targetFlightRecordID == flightID, "restore target Flight Record UUID")
        require(restored.metadata?.lastFailureMessage?.contains("Synchronize the flight first") == true,
                "restore retry/failure state")
        require(restored.fileURL?.path == fileURL.path, "restore resolves relative path")

        // 4 already covered by matching hash above

        // 5: missing file does not create false pending
        try FileManager.default.removeItem(at: fileURL)
        let missing = CVRPendingGarminPersistence.restorePending()
        require(missing.metadata == nil, "missing file must not restore pending import")
        require(missing.recoveryMessage?.isEmpty == false, "missing file shows recovery message")
        // re-stage for later checks
        try csvBody.write(to: fileURL, options: [.atomic])
        _ = try CVRPendingGarminPersistence.writeMetadata(metadata)

        // 6: hash mismatch does not finalize/relink
        try Data("tampered".utf8).write(to: fileURL, options: [.atomic])
        let mismatch = CVRPendingGarminPersistence.restorePending()
        require(mismatch.metadata == nil, "hash mismatch must not restore pending")
        require(mismatch.fileURL != nil, "hash mismatch preserves file for correction")
        require(mismatch.recoveryMessage?.localizedCaseInsensitiveContains("checksum") == true,
                "hash mismatch shows safe recovery message")
        try csvBody.write(to: fileURL, options: [.atomic])
        _ = try CVRPendingGarminPersistence.writeMetadata(metadata)

        // 7 + 8: temporary / dispatch-not-ready preserve metadata
        var failed = metadata
        failed.lastFailureMessage = "The Garmin file is stored on this device. Synchronize the flight first, then retry. You will not need to select the file again."
        _ = try CVRPendingGarminPersistence.writeMetadata(failed)
        let afterFailure = try CVRPendingGarminPersistence.readMetadata()
        require(afterFailure.targetFlightRecordID == flightID, "temporary failure keeps flight association")
        require(afterFailure.sha256 == digest, "temporary failure keeps SHA-256")
        require(FileManager.default.fileExists(atPath: fileURL.path), "temporary failure keeps staged file")

        // 9: wrong-aircraft style failure keeps file; no relink fields changed
        var wrongAircraft = failed
        wrongAircraft.lastFailureMessage = "This Garmin file could not be attached to the selected flight for this aircraft. The file remains on this device for correction."
        _ = try CVRPendingGarminPersistence.writeMetadata(wrongAircraft)
        let wrongAircraftRead = try CVRPendingGarminPersistence.readMetadata()
        require(wrongAircraftRead.targetFlightRecordID == flightID,
                "wrong-aircraft rejection does not silently retarget")
        require(FileManager.default.fileExists(atPath: fileURL.path), "wrong-aircraft keeps file")

        // 11: retry uses same SHA-256 and target
        let retryRestore = CVRPendingGarminPersistence.restorePending()
        require(retryRestore.metadata?.sha256 == digest, "retry uses same SHA-256")
        require(retryRestore.metadata?.targetFlightRecordID == flightID, "retry uses same target flight")

        // 10: verified success clears persisted metadata
        try CVRPendingGarminPersistence.clearMetadata()
        try FileManager.default.removeItem(at: fileURL)
        let cleared = CVRPendingGarminPersistence.restorePending()
        require(cleared.metadata == nil && cleared.fileURL == nil, "success clears pending restore")
        let clearedMetadataPath = try CVRPendingGarminPersistence.metadataURL().path
        require(
            !FileManager.default.fileExists(atPath: clearedMetadataPath),
            "success removes pending-metadata.json"
        )

        // 12: no duplicate metadata file after single write
        try csvBody.write(to: fileURL, options: [.atomic])
        _ = try CVRPendingGarminPersistence.writeMetadata(metadata)
        _ = try CVRPendingGarminPersistence.writeMetadata(metadata)
        let contents = try FileManager.default.contentsOfDirectory(atPath: imports.path)
            .filter { $0.hasSuffix(".json") }
        require(contents == ["pending-metadata.json"], "exactly one metadata record exists")

        print("OK: CVR pending Garmin persistence checks passed.")
    }
}
