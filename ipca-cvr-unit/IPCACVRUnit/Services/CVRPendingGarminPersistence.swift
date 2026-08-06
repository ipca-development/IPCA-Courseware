import CryptoKit
import Foundation

/// Durable pending Garmin CSV metadata stored beside staged imports under Application Support.
/// Paths are always relative to Application Support so sandbox remounts remain valid.
struct CVRPendingGarminMetadata: Codable, Equatable {
    var id: String
    /// Relative to Application Support, e.g. `PendingGarminImports/<uuid>.csv`.
    var relativeFilePath: String
    var originalFilename: String
    var sha256: String
    var targetFlightRecordID: String?
    var stagedAt: Date
    /// Operational failure text for retry UI; never a reason to discard the staged file.
    var lastFailureMessage: String?
}

enum CVRPendingGarminPersistence {
    static let importsDirectoryName = "PendingGarminImports"
    static let metadataFileName = "pending-metadata.json"

    /// Test-only override for Application Support root. Production must leave this nil.
    static var testSupportDirectoryOverride: URL?

    struct RestoreResult: Equatable {
        var metadata: CVRPendingGarminMetadata?
        var fileURL: URL?
        var recoveryMessage: String?
    }

    static func applicationSupportDirectory(fileManager: FileManager = .default) throws -> URL {
        if let override = testSupportDirectoryOverride {
            try fileManager.createDirectory(at: override, withIntermediateDirectories: true)
            return override
        }
        return try fileManager.url(
            for: .applicationSupportDirectory,
            in: .userDomainMask,
            appropriateFor: nil,
            create: true
        )
    }

    static func importsDirectory(fileManager: FileManager = .default) throws -> URL {
        let directory = try applicationSupportDirectory(fileManager: fileManager)
            .appendingPathComponent(importsDirectoryName, isDirectory: true)
        try fileManager.createDirectory(at: directory, withIntermediateDirectories: true)
        return directory
    }

    static func metadataURL(fileManager: FileManager = .default) throws -> URL {
        try importsDirectory(fileManager: fileManager).appendingPathComponent(metadataFileName)
    }

    static func relativePath(forImportID id: String) -> String {
        "\(importsDirectoryName)/\(id).csv"
    }

    static func resolveFileURL(
        relativePath: String,
        fileManager: FileManager = .default
    ) throws -> URL {
        let support = try applicationSupportDirectory(fileManager: fileManager)
        let cleaned = relativePath
            .trimmingCharacters(in: CharacterSet(charactersIn: "/"))
            .replacingOccurrences(of: "\\", with: "/")
        guard !cleaned.isEmpty,
              !cleaned.contains(".."),
              cleaned.hasPrefix("\(importsDirectoryName)/") else {
            throw CocoaError(.fileReadCorruptFile)
        }
        return support.appendingPathComponent(cleaned)
    }

    static func sha256Hex(ofFileAt url: URL) throws -> String {
        let data = try Data(contentsOf: url)
        return SHA256.hash(data: data).map { String(format: "%02x", $0) }.joined()
    }

    static func sha256Hex(of data: Data) -> String {
        SHA256.hash(data: data).map { String(format: "%02x", $0) }.joined()
    }

    /// Writes metadata atomically and decode-verifies the on-disk payload before returning.
    static func writeMetadata(
        _ metadata: CVRPendingGarminMetadata,
        fileManager: FileManager = .default
    ) throws -> CVRPendingGarminMetadata {
        // Foundation's `.iso8601` date strategy truncates fractional seconds on encode.
        // Normalize before write so decode-verify equality is stable for `Date()` staging.
        var normalized = metadata
        normalized.stagedAt = Date(
            timeIntervalSince1970: floor(metadata.stagedAt.timeIntervalSince1970)
        )

        let encoder = JSONEncoder()
        encoder.dateEncodingStrategy = .iso8601
        encoder.outputFormatting = [.sortedKeys]
        let data = try encoder.encode(normalized)
        let url = try metadataURL(fileManager: fileManager)
        try data.write(to: url, options: [.atomic])
        let verified = try readMetadata(fileManager: fileManager)
        guard verified == normalized else {
            throw CocoaError(.fileWriteUnknown)
        }
        return verified
    }

    static func readMetadata(fileManager: FileManager = .default) throws -> CVRPendingGarminMetadata {
        let url = try metadataURL(fileManager: fileManager)
        let data = try Data(contentsOf: url)
        let decoder = JSONDecoder()
        decoder.dateDecodingStrategy = .iso8601
        return try decoder.decode(CVRPendingGarminMetadata.self, from: data)
    }

    static func clearMetadata(fileManager: FileManager = .default) throws {
        let url = try metadataURL(fileManager: fileManager)
        if fileManager.fileExists(atPath: url.path) {
            try fileManager.removeItem(at: url)
        }
    }

    /// Restores a pending import only when the staged file exists and SHA-256 matches.
    static func restorePending(fileManager: FileManager = .default) -> RestoreResult {
        let metadataURL: URL
        do {
            metadataURL = try self.metadataURL(fileManager: fileManager)
        } catch {
            return RestoreResult(metadata: nil, fileURL: nil, recoveryMessage: nil)
        }
        guard fileManager.fileExists(atPath: metadataURL.path) else {
            return RestoreResult(metadata: nil, fileURL: nil, recoveryMessage: nil)
        }

        let metadata: CVRPendingGarminMetadata
        do {
            metadata = try readMetadata(fileManager: fileManager)
        } catch {
            return RestoreResult(
                metadata: nil,
                fileURL: nil,
                recoveryMessage: "A Garmin file may still be on this device, but its pending record could not be restored. Contact IPCA support before selecting another file."
            )
        }

        let fileURL: URL
        do {
            fileURL = try resolveFileURL(relativePath: metadata.relativeFilePath, fileManager: fileManager)
        } catch {
            return RestoreResult(
                metadata: nil,
                fileURL: nil,
                recoveryMessage: "A Garmin pending record was found, but the stored file path is not valid. The file was not attached to any flight."
            )
        }

        guard fileManager.fileExists(atPath: fileURL.path) else {
            // Preserve metadata for diagnostics; do not invent a pending UI row.
            return RestoreResult(
                metadata: nil,
                fileURL: nil,
                recoveryMessage: "The Garmin file recorded on this device is missing. It was not attached to any flight. Contact IPCA support if the file is still needed."
            )
        }

        let digest: String
        do {
            digest = try sha256Hex(ofFileAt: fileURL)
        } catch {
            return RestoreResult(
                metadata: nil,
                fileURL: nil,
                recoveryMessage: "The Garmin file on this device could not be verified. It was not attached to any flight."
            )
        }

        guard digest.caseInsensitiveCompare(metadata.sha256) == .orderedSame else {
            // Keep file on disk for explicit correction; do not restore as pending or relink.
            return RestoreResult(
                metadata: nil,
                fileURL: fileURL,
                recoveryMessage: "The Garmin file on this device no longer matches the stored checksum. It was not attached to any flight. Select the correct file again if needed."
            )
        }

        var verified = metadata
        verified.sha256 = digest
        return RestoreResult(
            metadata: verified,
            fileURL: fileURL,
            recoveryMessage: metadata.lastFailureMessage
        )
    }
}
