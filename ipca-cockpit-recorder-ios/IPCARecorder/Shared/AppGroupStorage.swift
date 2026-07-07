import Foundation

enum AppGroupStorage {
    static let identifier = "group.com.ipca.cockpitrecorder.poc"

    static var containerURL: URL? {
        FileManager.default.containerURL(forSecurityApplicationGroupIdentifier: identifier)
    }

    static var sharedRootURL: URL? {
        guard let containerURL else { return nil }
        let url = containerURL.appendingPathComponent("Shared", isDirectory: true)
        try? FileManager.default.createDirectory(at: url, withIntermediateDirectories: true)
        return url
    }

    static var recordingIndexURL: URL? {
        sharedRootURL?.appendingPathComponent("recording_index.json")
    }

    static var pendingImportsURL: URL? {
        sharedRootURL?.appendingPathComponent("pending_g3x_imports.json")
    }

    static var importsDirectoryURL: URL? {
        guard let sharedRootURL else { return nil }
        let url = sharedRootURL.appendingPathComponent("imports", isDirectory: true)
        try? FileManager.default.createDirectory(at: url, withIntermediateDirectories: true)
        return url
    }
}

struct SharedRecordingEntry: Codable, Identifiable, Equatable {
    var id: String
    var startedAt: Date
    var duration: TimeInterval
    var aircraftRegistration: String?
    var aircraftDisplayName: String?
    var hasG3X: Bool

    var aircraftLabel: String {
        let registration = aircraftRegistration?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
        let name = aircraftDisplayName?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
        if !name.isEmpty { return name }
        if !registration.isEmpty { return registration }
        return "Unknown aircraft"
    }

    var endedAt: Date {
        startedAt.addingTimeInterval(duration)
    }
}

struct PendingG3XImport: Codable, Identifiable, Equatable {
    var id: String
    var createdAt: Date
    var sourceFilename: String
    var csvRelativePath: String
    var aircraftIdent: String
    var startUtc: Date?
    var endUtc: Date?
    var rowCount: Int
    var importProfile: String?
    var suggestedRecordingID: String?
    var matchMethod: String
}

enum SharedRecordingIndexStore {
    private static let encoder: JSONEncoder = {
        let encoder = JSONEncoder()
        encoder.outputFormatting = [.prettyPrinted, .sortedKeys]
        encoder.dateEncodingStrategy = .iso8601
        return encoder
    }()

    private static let decoder: JSONDecoder = {
        let decoder = JSONDecoder()
        decoder.dateDecodingStrategy = .iso8601
        return decoder
    }()

    static func writeIndex(_ entries: [SharedRecordingEntry]) {
        guard let url = AppGroupStorage.recordingIndexURL else { return }
        guard let data = try? encoder.encode(entries) else { return }
        try? data.write(to: url, options: [.atomic])
    }

    static func readIndex() -> [SharedRecordingEntry] {
        guard let url = AppGroupStorage.recordingIndexURL,
              FileManager.default.fileExists(atPath: url.path),
              let data = try? Data(contentsOf: url) else {
            return []
        }
        return (try? decoder.decode([SharedRecordingEntry].self, from: data)) ?? []
    }

    static func appendPendingImport(_ pending: PendingG3XImport) {
        var items = readPendingImports()
        items.removeAll { $0.csvRelativePath == pending.csvRelativePath }
        items.insert(pending, at: 0)
        writePendingImports(items)
    }

    static func readPendingImports() -> [PendingG3XImport] {
        guard let url = AppGroupStorage.pendingImportsURL,
              FileManager.default.fileExists(atPath: url.path),
              let data = try? Data(contentsOf: url) else {
            return []
        }
        return (try? decoder.decode([PendingG3XImport].self, from: data)) ?? []
    }

    static func writePendingImports(_ items: [PendingG3XImport]) {
        guard let url = AppGroupStorage.pendingImportsURL else { return }
        guard let data = try? encoder.encode(items) else { return }
        try? data.write(to: url, options: [.atomic])
    }

    static func removePendingImport(id: String) {
        var items = readPendingImports()
        items.removeAll { $0.id == id }
        writePendingImports(items)
    }
}
