import CryptoKit
import AppKit
import Foundation
import SQLite3

enum CursorPersistenceError: Error, LocalizedError {
    case invalidCursor(String)
    case writeFailed
    case readbackMismatch

    var errorDescription: String? {
        switch self {
        case .invalidCursor(let reason): return "Garmin cursor persistence failed: \(reason)"
        case .writeFailed: return "Garmin cursor persistence failed: UserDefaults did not synchronize."
        case .readbackMismatch: return "Garmin cursor persistence failed: saved cursor did not match read-back cursor."
        }
    }
}

struct CursorValueDiagnostic {
    var present: Bool
    var length: Int
    var firstSix: String
    var lastFour: String
    var fingerprint: String
    var storageKey: String
    var storageRepository: String
    var updatedAt: Date?
}

final class CursorStore {
    private let defaults: UserDefaults
    private let synchronizeDefaults: (UserDefaults) -> Bool
    let storageRepository: String

    init(
        defaults: UserDefaults = .standard,
        storageRepository: String = "UserDefaults.standard",
        synchronize: ((UserDefaults) -> Bool)? = nil
    ) {
        self.defaults = defaults
        self.storageRepository = storageRepository
        self.synchronizeDefaults = synchronize ?? { $0.synchronize() }
    }

    static func storageKey(provider: String) -> String { "cursor.\(provider)" }
    static func cursorUpdatedAtKey(provider: String) -> String { "cursorUpdatedAt.\(provider)" }
    static func bootstrapCompletedKey(provider: String) -> String { "bootstrapCompleted.\(provider)" }

    func cursor(provider: String) -> String? {
        defaults.string(forKey: Self.storageKey(provider: provider))
    }

    @discardableResult
    func setCursor(_ cursor: String, provider: String) -> Bool {
        if let previous = self.cursor(provider: provider), previous != cursor {
            logCursor("CURSOR_OVERWRITTEN", provider: provider, value: cursor)
        }
        defaults.set(cursor, forKey: Self.storageKey(provider: provider))
        defaults.set(Date().timeIntervalSince1970, forKey: Self.cursorUpdatedAtKey(provider: provider))
        return synchronizeDefaults(defaults)
    }

    func persistBootstrapCursor(_ cursor: String, provider: String) throws {
        guard Self.validationRejectionReason(cursor) == nil else {
            throw CursorPersistenceError.invalidCursor(Self.validationRejectionReason(cursor) ?? "invalid cursor")
        }
        logCursor("BOOTSTRAP_CURSOR_SAVE_STARTED", provider: provider, value: cursor)
        guard setCursor(cursor, provider: provider) else {
            throw CursorPersistenceError.writeFailed
        }
        logCursor("BOOTSTRAP_CURSOR_SAVE_COMPLETED", provider: provider, value: cursor)
        let readback = self.cursor(provider: provider)
        logCursor("BOOTSTRAP_CURSOR_READBACK", provider: provider, value: readback)
        guard readback == cursor else {
            logCursor("BOOTSTRAP_CURSOR_MATCH", provider: provider, value: readback, extra: "match=no")
            throw CursorPersistenceError.readbackMismatch
        }
        logCursor("BOOTSTRAP_CURSOR_MATCH", provider: provider, value: readback, extra: "match=yes")
        defaults.set(true, forKey: Self.bootstrapCompletedKey(provider: provider))
        _ = synchronizeDefaults(defaults)
        logCursor("BOOTSTRAP_STATE_COMPLETED", provider: provider, value: readback)
    }

    func bootstrapCompleted(provider: String) -> Bool {
        defaults.bool(forKey: Self.bootstrapCompletedKey(provider: provider))
    }

    func clearCursor(provider: String, reason: String) {
        let existing = cursor(provider: provider)
        defaults.removeObject(forKey: Self.storageKey(provider: provider))
        defaults.removeObject(forKey: Self.cursorUpdatedAtKey(provider: provider))
        defaults.removeObject(forKey: Self.bootstrapCompletedKey(provider: provider))
        _ = synchronizeDefaults(defaults)
        logCursor("CURSOR_CLEARED", provider: provider, value: existing, extra: "reason=\(reason)")
    }

    func cursorUpdatedAt(provider: String) -> Date? {
        let value = defaults.double(forKey: Self.cursorUpdatedAtKey(provider: provider))
        return value > 0 ? Date(timeIntervalSince1970: value) : nil
    }

    func cursorDiagnostic(provider: String) -> (present: Bool, length: Int, updatedAt: Date?) {
        let value = cursor(provider: provider) ?? ""
        return (!value.isEmpty, value.count, cursorUpdatedAt(provider: provider))
    }

    func valueDiagnostic(provider: String, value: String? = nil) -> CursorValueDiagnostic {
        let cursorValue = value ?? cursor(provider: provider)
        let present = cursorValue != nil && cursorValue?.isEmpty == false
        let raw = cursorValue ?? ""
        let fingerprint = SHA256.hash(data: Data(raw.utf8)).map { String(format: "%02x", $0) }.joined()
        return CursorValueDiagnostic(
            present: present,
            length: raw.count,
            firstSix: String(raw.prefix(6)),
            lastFour: String(raw.suffix(4)),
            fingerprint: fingerprint,
            storageKey: Self.storageKey(provider: provider),
            storageRepository: storageRepository,
            updatedAt: cursorUpdatedAt(provider: provider)
        )
    }

    static func validationRejectionReason(_ cursor: String?) -> String? {
        guard let cursor else { return "missing" }
        guard !cursor.isEmpty else { return "empty-string" }
        return nil
    }

    func logCursor(_ event: String, provider: String, value: String? = nil, extra: String = "") {
        let diagnostic = valueDiagnostic(provider: provider, value: value)
        let parts = [
            event,
            "present=\(diagnostic.present ? "yes" : "no")",
            "length=\(diagnostic.length)",
            "first6=\(diagnostic.firstSix.isEmpty ? "n/a" : diagnostic.firstSix)",
            "last4=\(diagnostic.lastFour.isEmpty ? "n/a" : diagnostic.lastFour)",
            "sha256=\(diagnostic.fingerprint)",
            "storageKey=\(diagnostic.storageKey)",
            "storageRepository=\(diagnostic.storageRepository)",
            extra
        ].filter { !$0.isEmpty }
        LoggingService.shared.info(parts.joined(separator: " "))
    }
}

final class LocalArtifactStore {
    private let baseDirectory: URL

    init() {
        let appSupport = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask).first!
        baseDirectory = appSupport.appendingPathComponent("IPCA Sync Agent/Artifacts/Garmin", isDirectory: true)
        try? FileManager.default.createDirectory(at: baseDirectory, withIntermediateDirectories: true)
    }

    func saveDownloadedSource(provider: String, entryID: String, sourceUUID: String, response: [String: JSONValue]) throws -> DownloadedArtifact {
        if response["timeout"]?.bool == true {
            throw GarminError.timeout("Garmin source download timed out for \(sourceUUID). Try Sync Now again.")
        }
        if response["humanVerification"]?.bool == true { throw GarminError.humanVerification }
        if response["loginRequired"]?.bool == true { throw GarminError.loginRequired }
        let status = response["status"]?.int ?? 0
        guard (200...299).contains(status) else {
            let attempts = response["attempts"]?.array?.compactMap { $0.object?["status"]?.int }.map(String.init).joined(separator: ", ") ?? ""
            throw GarminError.downloadFailed("Garmin source download failed with HTTP \(status).\(attempts.isEmpty ? "" : " Tried statuses: \(attempts).")")
        }
        guard let base64 = response["base64"]?.string,
              let bytes = Data(base64Encoded: base64),
              !bytes.isEmpty else {
            throw GarminError.downloadFailed("Garmin source download was empty or invalid.")
        }
        let contentType = response["contentType"]?.string ?? "application/octet-stream"
        let originalFilename = safeFilename(response["filename"]?.string ?? "\(sourceUUID).bin")
        let classification = classifyDownloadedContent(bytes: bytes, contentType: contentType, filename: originalFilename)
        if classification == "GPS_ONLY_GPX" {
            throw GarminError.gpxOnly("Skipped Garmin GPX/track-only source \(sourceUUID).")
        }

        let date = Date()
        let parts = Calendar(identifier: .gregorian).dateComponents(in: TimeZone(secondsFromGMT: 0)!, from: date)
        let dir = baseDirectory
            .appendingPathComponent(String(format: "%04d", parts.year ?? 0), isDirectory: true)
            .appendingPathComponent(String(format: "%02d", parts.month ?? 0), isDirectory: true)
            .appendingPathComponent(String(format: "%02d", parts.day ?? 0), isDirectory: true)
            .appendingPathComponent(safePath(entryID), isDirectory: true)
            .appendingPathComponent(safePath(sourceUUID), isDirectory: true)
        try FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)

        let fileURL = dir.appendingPathComponent(originalFilename)
        let tmpURL = dir.appendingPathComponent(".\(originalFilename).tmp")
        try bytes.write(to: tmpURL, options: .atomic)
        if FileManager.default.fileExists(atPath: fileURL.path) {
            try FileManager.default.removeItem(at: fileURL)
        }
        try FileManager.default.moveItem(at: tmpURL, to: fileURL)

        let sha256 = SHA256.hash(data: bytes).map { String(format: "%02x", $0) }.joined()
        let artifact = DownloadedArtifact(
            provider: provider,
            entryID: entryID,
            sourceUUID: sourceUUID,
            artifactType: "GARMIN_ORIGINAL_SOURCE",
            originalFilename: originalFilename,
            contentType: response["contentType"]?.string ?? "application/octet-stream",
            contentDisposition: response["contentDisposition"]?.string,
            localPath: fileURL.path,
            byteSize: bytes.count,
            sha256: sha256,
            sourceClassification: classification,
            metadata: [:],
            downloadedAt: date
        )
        let metadataURL = dir.appendingPathComponent("metadata.json")
        let metadata = try JSONEncoder.ipca.encode(artifact)
        try metadata.write(to: metadataURL, options: .atomic)
        return artifact
    }

    func saveTrackJSON(provider: String, entryID: String, trackUUID: String, bytes: Data, response: GarminTrackResponse, requestPath: String, contentType: String, trackClassification: String = "GARMIN_TRACK_UNCLASSIFIED") throws -> DownloadedArtifact {
        let dir = baseDirectory
            .appendingPathComponent(safePath(entryID), isDirectory: true)
            .appendingPathComponent(safePath(trackUUID), isDirectory: true)
        try FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)

        let fileURL = dir.appendingPathComponent("track.json")
        let tmpURL = dir.appendingPathComponent(".track.json.tmp")
        try bytes.write(to: tmpURL, options: .atomic)
        if FileManager.default.fileExists(atPath: fileURL.path) {
            try FileManager.default.removeItem(at: fileURL)
        }
        try FileManager.default.moveItem(at: tmpURL, to: fileURL)

        let sha256 = SHA256.hash(data: bytes).map { String(format: "%02x", $0) }.joined()
        let fieldCount = response.sessions.reduce(0) { $0 + $1.fields.count }
        let sourceNames = response.sessions
            .flatMap { $0.sources ?? [] }
            .compactMap(\.name)
        let sourceTypes = response.sessions
            .flatMap { $0.sources ?? [] }
            .compactMap(\.type)
        let artifact = DownloadedArtifact(
            provider: provider,
            entryID: entryID,
            sourceUUID: trackUUID,
            artifactType: "GARMIN_TRACK_NORMALIZED_JSON",
            originalFilename: "track.json",
            contentType: contentType.isEmpty ? "application/json" : contentType,
            contentDisposition: nil,
            localPath: fileURL.path,
            byteSize: bytes.count,
            sha256: sha256,
            sourceClassification: trackClassification,
            metadata: [
                "trackUUID": .string(trackUUID),
                "requestPath": .string(requestPath),
                "trackClassification": .string(trackClassification),
                "formatVersion": response.formatVersion.map { .number(Double($0)) } ?? .null,
                "sessionCount": .number(Double(response.sessions.count)),
                "fieldCount": .number(Double(fieldCount)),
                "sourceNames": .array(sourceNames.map { .string($0) }),
                "sourceTypes": .array(sourceTypes.map { .string($0) })
            ],
            downloadedAt: Date()
        )
        let metadataURL = dir.appendingPathComponent("metadata.json")
        let metadata = try JSONEncoder.ipca.encode(artifact)
        try metadata.write(to: metadataURL, options: .atomic)
        return artifact
    }

    func openDownloads() {
        NSWorkspace.shared.open(baseDirectory)
    }

    private func safeFilename(_ value: String) -> String {
        let allowed = CharacterSet(charactersIn: "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789._-")
        let result = String(value.unicodeScalars.map { allowed.contains($0) ? Character($0) : "-" }).trimmingCharacters(in: CharacterSet(charactersIn: "-."))
        return result.isEmpty ? "garmin-source.bin" : result
    }

    private func safePath(_ value: String) -> String {
        safeFilename(value)
    }

    private func classifyDownloadedContent(bytes: Data, contentType: String, filename: String) -> String {
        let lowerContentType = contentType.lowercased()
        let lowerFilename = filename.lowercased()
        let sample = String(data: bytes.prefix(2048), encoding: .utf8)?.lowercased() ?? ""
        if lowerContentType.contains("gpx") || lowerFilename.hasSuffix(".gpx") || sample.contains("<gpx") {
            return "GPS_ONLY_GPX"
        }
        if lowerContentType.contains("csv") || lowerFilename.hasSuffix(".csv") || sample.contains(",") {
            if sample.contains("engine") || sample.contains("rpm") || sample.contains("g3x") || sample.contains("fuel") || sample.contains("hobbs") {
                return "CSV_G3X_FULL_OR_PARTIAL_AVIONICS"
            }
            return "CSV_UNKNOWN"
        }
        return "UNKNOWN"
    }
}

final class LocalQueueStore {
    private var db: OpaquePointer?
    private let dbURL: URL
    private let lock = NSLock()
    private let durable: Bool

    init(inMemory: Bool = false) throws {
        let appSupport = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask).first!
        let dir = appSupport.appendingPathComponent("IPCA Sync Agent", isDirectory: true)
        try FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        dbURL = dir.appendingPathComponent("sync-agent.sqlite")
        durable = !inMemory
        let path = inMemory ? ":memory:" : dbURL.path
        if sqlite3_open(path, &db) != SQLITE_OK {
            throw NSError(domain: "LocalQueueStore", code: 1, userInfo: [NSLocalizedDescriptionKey: "Could not open local queue database."])
        }
        try migrate()
    }

    deinit {
        sqlite3_close(db)
    }

    func enqueue(_ artifact: DownloadedArtifact) throws {
        lock.lock()
        defer { lock.unlock() }
        let now = Date()
        let sql = """
        INSERT INTO upload_queue
          (provider, entry_id, source_uuid, artifact_type, idempotency_key, local_path, sha256, byte_size, content_type, metadata_json, state, attempts, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'queued', 0, ?, ?)
        ON CONFLICT(idempotency_key) DO UPDATE SET
          local_path = excluded.local_path,
          byte_size = excluded.byte_size,
          content_type = excluded.content_type,
          metadata_json = excluded.metadata_json,
          updated_at = excluded.updated_at
        """
        let metadataData = try JSONEncoder.ipca.encode(artifact.metadata)
        let metadataJSON = String(data: metadataData, encoding: .utf8) ?? "{}"
        try execute(sql, [
            artifact.provider,
            artifact.entryID,
            artifact.sourceUUID,
            artifact.artifactType,
            artifact.idempotencyKey,
            artifact.localPath,
            artifact.sha256,
            artifact.byteSize,
            artifact.contentType,
            metadataJSON,
            now.timeIntervalSince1970,
            now.timeIntervalSince1970
        ])
    }

    func pending(limit: Int = 20) throws -> [UploadQueueItem] {
        lock.lock()
        defer { lock.unlock() }
        let now = Date().timeIntervalSince1970
        let sql = """
        SELECT id, provider, entry_id, source_uuid, artifact_type, idempotency_key, local_path, sha256, byte_size, content_type, metadata_json, state, attempts,
               next_retry_at, last_error, server_status, created_at, updated_at, completed_at
        FROM upload_queue
        WHERE state IN ('queued','retry_wait','failed')
          AND (next_retry_at IS NULL OR next_retry_at <= ?)
        ORDER BY created_at ASC
        LIMIT ?
        """
        var statement: OpaquePointer?
        guard sqlite3_prepare_v2(db, sql, -1, &statement, nil) == SQLITE_OK else { return [] }
        defer { sqlite3_finalize(statement) }
        sqlite3_bind_double(statement, 1, now)
        sqlite3_bind_int(statement, 2, Int32(limit))
        var items: [UploadQueueItem] = []
        while sqlite3_step(statement) == SQLITE_ROW {
            items.append(readQueueItem(statement))
        }
        return items
    }

    func markUploading(_ id: Int64) throws {
        try updateState(id, state: .uploading, error: nil, serverStatus: nil, nextRetryAt: nil, incrementAttempts: true)
    }

    func markServerResult(_ id: Int64, status: String) throws {
        let finalState: QueueState = status == "already_exists" ? .alreadyExists : (status == "review_required" ? .reviewRequired : .uploaded)
        try updateState(id, state: finalState, error: nil, serverStatus: status, nextRetryAt: nil, completed: true)
    }

    func markRetry(_ id: Int64, error: String, attempts: Int) throws {
        let delay = min(30 * 60, Int(pow(2.0, Double(max(0, attempts)))) * 30)
        try updateState(id, state: .retryWait, error: error, serverStatus: nil, nextRetryAt: Date().addingTimeInterval(TimeInterval(delay)))
    }

    func pendingCount() throws -> Int {
        try scalarInt("SELECT COUNT(*) FROM upload_queue WHERE state NOT IN ('uploaded','already_exists','completed')")
    }

    func uploadQueueStateCounts() throws -> [String: Int] {
        try stateCounts(table: "upload_queue")
    }

    func backfillTrackStateCounts() throws -> [String: Int] {
        try stateCounts(table: "garmin_backfill_tracks")
    }

    func backfillSkipTrackUUIDs() throws -> Set<String> {
        lock.lock()
        defer { lock.unlock() }
        let sql = """
        SELECT DISTINCT source_uuid
        FROM upload_queue
        WHERE artifact_type = 'GARMIN_TRACK_NORMALIZED_JSON'
          AND state IN ('queued','uploading','retry_wait','uploaded','already_exists','completed','review_required')
        """
        var statement: OpaquePointer?
        guard sqlite3_prepare_v2(db, sql, -1, &statement, nil) == SQLITE_OK else { return [] }
        defer { sqlite3_finalize(statement) }
        var values = Set<String>()
        while sqlite3_step(statement) == SQLITE_ROW {
            values.insert(text(statement, 0).lowercased())
        }
        return values
    }

    func ignoredGPSOnlyBackfillTrackUUIDs() throws -> Set<String> {
        lock.lock()
        defer { lock.unlock() }
        let sql = "SELECT track_uuid FROM garmin_backfill_tracks WHERE state = 'ignored_gps_only'"
        var statement: OpaquePointer?
        guard sqlite3_prepare_v2(db, sql, -1, &statement, nil) == SQLITE_OK else { return [] }
        defer { sqlite3_finalize(statement) }
        var values = Set<String>()
        while sqlite3_step(statement) == SQLITE_ROW {
            values.insert(text(statement, 0).lowercased())
        }
        return values
    }

    func markBackfillTrack(trackUUID: String, entryID: String, runID: String, state: GarminBackfillTrackState, error: String? = nil) throws {
        lock.lock()
        defer { lock.unlock() }
        let now = Date().timeIntervalSince1970
        let sql = """
        INSERT INTO garmin_backfill_tracks (track_uuid, entry_id, run_id, state, last_error, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(track_uuid) DO UPDATE SET
          entry_id = excluded.entry_id,
          run_id = excluded.run_id,
          state = excluded.state,
          last_error = excluded.last_error,
          updated_at = excluded.updated_at
        """
        try execute(sql, [trackUUID.lowercased(), entryID, runID, state.rawValue, error as Any, now, now])
    }

    func updateBackfillTrackState(trackUUID: String, state: GarminBackfillTrackState, error: String? = nil) throws {
        lock.lock()
        defer { lock.unlock() }
        let sql = """
        UPDATE garmin_backfill_tracks
        SET state = ?,
            last_error = ?,
            updated_at = ?
        WHERE track_uuid = ?
        """
        try execute(sql, [state.rawValue, error as Any, Date().timeIntervalSince1970, trackUUID.lowercased()])
    }

    func backfillTrackState(trackUUID: String) throws -> GarminBackfillTrackState? {
        lock.lock()
        defer { lock.unlock() }
        var statement: OpaquePointer?
        guard sqlite3_prepare_v2(db, "SELECT state FROM garmin_backfill_tracks WHERE track_uuid = ?", -1, &statement, nil) == SQLITE_OK else { return nil }
        defer { sqlite3_finalize(statement) }
        sqlite3_bind_text(statement, 1, trackUUID.lowercased(), -1, SQLITE_TRANSIENT)
        guard sqlite3_step(statement) == SQLITE_ROW else { return nil }
        return GarminBackfillTrackState(rawValue: text(statement, 0))
    }

    func recordBackfillRun(runID: String, source: String, result: GarminBackfillDiscoveryResult?, status: String, error: String?) throws {
        lock.lock()
        defer { lock.unlock() }
        let now = Date().timeIntervalSince1970
        let resultData = try result.map { try JSONEncoder.ipca.encode($0) }
        let resultJSON = resultData.flatMap { String(data: $0, encoding: .utf8) }
        let sql = """
        INSERT INTO garmin_backfill_runs (run_id, source, status, result_json, error, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(run_id) DO UPDATE SET
          source = excluded.source,
          status = excluded.status,
          result_json = excluded.result_json,
          error = excluded.error,
          updated_at = excluded.updated_at
        """
        try execute(sql, [runID, source, status, resultJSON as Any, error as Any, now, now])
    }

    func isHealthy() -> Bool {
        durable && (try? scalarInt("SELECT COUNT(*) FROM upload_queue")) != nil
    }

    private func migrate() throws {
        if durable {
            try exec("PRAGMA journal_mode=WAL")
        }
        try execute("""
        CREATE TABLE IF NOT EXISTS upload_queue (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          provider TEXT NOT NULL,
          entry_id TEXT NOT NULL,
          source_uuid TEXT NOT NULL,
          artifact_type TEXT NOT NULL DEFAULT 'GARMIN_ORIGINAL_SOURCE',
          idempotency_key TEXT NOT NULL UNIQUE,
          local_path TEXT NOT NULL,
          sha256 TEXT NOT NULL,
          byte_size INTEGER NOT NULL,
          content_type TEXT NOT NULL DEFAULT 'application/octet-stream',
          metadata_json TEXT NULL,
          state TEXT NOT NULL,
          attempts INTEGER NOT NULL DEFAULT 0,
          next_retry_at REAL NULL,
          last_error TEXT NULL,
          server_status TEXT NULL,
          created_at REAL NOT NULL,
          updated_at REAL NOT NULL,
          completed_at REAL NULL
        )
        """, [])
        try addColumnIfMissing(table: "upload_queue", column: "artifact_type", definition: "TEXT NOT NULL DEFAULT 'GARMIN_ORIGINAL_SOURCE'")
        try addColumnIfMissing(table: "upload_queue", column: "content_type", definition: "TEXT NOT NULL DEFAULT 'application/octet-stream'")
        try addColumnIfMissing(table: "upload_queue", column: "metadata_json", definition: "TEXT NULL")
        try execute("CREATE TABLE IF NOT EXISTS event_log (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at REAL NOT NULL, level TEXT NOT NULL, message TEXT NOT NULL)", [])
        try execute("CREATE TABLE IF NOT EXISTS sync_runs (id INTEGER PRIMARY KEY AUTOINCREMENT, provider TEXT NOT NULL, status TEXT NOT NULL, started_at REAL NOT NULL, completed_at REAL NULL, cursor TEXT NULL)", [])
        try execute("CREATE TABLE IF NOT EXISTS remote_entries (entry_id TEXT PRIMARY KEY, provider TEXT NOT NULL, raw_json TEXT NOT NULL, updated_at REAL NOT NULL)", [])
        try execute("CREATE TABLE IF NOT EXISTS source_artifacts (source_uuid TEXT PRIMARY KEY, entry_id TEXT NOT NULL, local_path TEXT NOT NULL, sha256 TEXT NOT NULL, created_at REAL NOT NULL)", [])
        try execute("CREATE TABLE IF NOT EXISTS server_acknowledgments (idempotency_key TEXT PRIMARY KEY, status TEXT NOT NULL, created_at REAL NOT NULL)", [])
        try execute("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)", [])
        try execute("CREATE TABLE IF NOT EXISTS provider_connections (provider TEXT PRIMARY KEY, status TEXT NOT NULL, updated_at REAL NOT NULL)", [])
        try execute("""
        CREATE TABLE IF NOT EXISTS garmin_backfill_tracks (
          track_uuid TEXT PRIMARY KEY,
          entry_id TEXT NOT NULL,
          run_id TEXT NOT NULL,
          state TEXT NOT NULL,
          last_error TEXT NULL,
          created_at REAL NOT NULL,
          updated_at REAL NOT NULL
        )
        """, [])
        try execute("""
        CREATE TABLE IF NOT EXISTS garmin_backfill_runs (
          run_id TEXT PRIMARY KEY,
          source TEXT NOT NULL,
          status TEXT NOT NULL,
          result_json TEXT NULL,
          error TEXT NULL,
          created_at REAL NOT NULL,
          updated_at REAL NOT NULL
        )
        """, [])
        try reconcileUploadedBackfillTracks()
    }

    private func reconcileUploadedBackfillTracks() throws {
        try execute("""
        UPDATE garmin_backfill_tracks
        SET state = 'uploaded',
            last_error = NULL,
            updated_at = (
              SELECT COALESCE(upload_queue.completed_at, upload_queue.updated_at)
              FROM upload_queue
              WHERE upload_queue.source_uuid = garmin_backfill_tracks.track_uuid
                AND upload_queue.artifact_type = 'GARMIN_TRACK_NORMALIZED_JSON'
                AND upload_queue.state IN ('uploaded','already_exists','completed')
              LIMIT 1
            )
        WHERE EXISTS (
          SELECT 1
          FROM upload_queue
          WHERE upload_queue.source_uuid = garmin_backfill_tracks.track_uuid
            AND upload_queue.artifact_type = 'GARMIN_TRACK_NORMALIZED_JSON'
            AND upload_queue.state IN ('uploaded','already_exists','completed')
        )
        """, [])
    }

    private func exec(_ sql: String) throws {
        var errorMessage: UnsafeMutablePointer<CChar>?
        if sqlite3_exec(db, sql, nil, nil, &errorMessage) != SQLITE_OK {
            let message = errorMessage.map { String(cString: $0) } ?? String(cString: sqlite3_errmsg(db))
            sqlite3_free(errorMessage)
            throw NSError(domain: "LocalQueueStore", code: 3, userInfo: [NSLocalizedDescriptionKey: message])
        }
    }

    private func addColumnIfMissing(table: String, column: String, definition: String) throws {
        var statement: OpaquePointer?
        guard sqlite3_prepare_v2(db, "PRAGMA table_info(\(table))", -1, &statement, nil) == SQLITE_OK else { return }
        defer { sqlite3_finalize(statement) }
        while sqlite3_step(statement) == SQLITE_ROW {
            if let cString = sqlite3_column_text(statement, 1), String(cString: cString) == column {
                return
            }
        }
        try execute("ALTER TABLE \(table) ADD COLUMN \(column) \(definition)", [])
    }

    private func updateState(_ id: Int64, state: QueueState, error: String?, serverStatus: String?, nextRetryAt: Date?, incrementAttempts: Bool = false, completed: Bool = false) throws {
        let now = Date().timeIntervalSince1970
        let sql = """
        UPDATE upload_queue
        SET state = ?,
            attempts = attempts + ?,
            next_retry_at = ?,
            last_error = ?,
            server_status = ?,
            updated_at = ?,
            completed_at = ?
        WHERE id = ?
        """
        try execute(sql, [
            state.rawValue,
            incrementAttempts ? 1 : 0,
            nextRetryAt?.timeIntervalSince1970 as Any,
            error as Any,
            serverStatus as Any,
            now,
            completed ? now : NSNull(),
            id
        ])
    }

    private func readQueueItem(_ statement: OpaquePointer?) -> UploadQueueItem {
        UploadQueueItem(
            id: sqlite3_column_int64(statement, 0),
            provider: text(statement, 1),
            entryID: text(statement, 2),
            sourceUUID: text(statement, 3),
            artifactType: text(statement, 4),
            idempotencyKey: text(statement, 5),
            localPath: text(statement, 6),
            sha256: text(statement, 7),
            byteSize: Int(sqlite3_column_int64(statement, 8)),
            contentType: text(statement, 9),
            metadataJSON: nullableText(statement, 10),
            state: QueueState(rawValue: text(statement, 11)) ?? .failed,
            attempts: Int(sqlite3_column_int(statement, 12)),
            nextRetryAt: date(statement, 13),
            lastError: nullableText(statement, 14),
            serverStatus: nullableText(statement, 15),
            createdAt: Date(timeIntervalSince1970: sqlite3_column_double(statement, 16)),
            updatedAt: Date(timeIntervalSince1970: sqlite3_column_double(statement, 17)),
            completedAt: date(statement, 18)
        )
    }

    private func execute(_ sql: String, _ values: [Any]) throws {
        var statement: OpaquePointer?
        guard sqlite3_prepare_v2(db, sql, -1, &statement, nil) == SQLITE_OK else {
            throw NSError(domain: "LocalQueueStore", code: 2, userInfo: [NSLocalizedDescriptionKey: String(cString: sqlite3_errmsg(db))])
        }
        defer { sqlite3_finalize(statement) }
        bind(values, to: statement)
        var step = sqlite3_step(statement)
        while step == SQLITE_ROW {
            step = sqlite3_step(statement)
        }
        guard step == SQLITE_DONE else {
            throw NSError(domain: "LocalQueueStore", code: 3, userInfo: [NSLocalizedDescriptionKey: String(cString: sqlite3_errmsg(db))])
        }
    }

    private func scalarInt(_ sql: String) throws -> Int {
        var statement: OpaquePointer?
        guard sqlite3_prepare_v2(db, sql, -1, &statement, nil) == SQLITE_OK else { return 0 }
        defer { sqlite3_finalize(statement) }
        return sqlite3_step(statement) == SQLITE_ROW ? Int(sqlite3_column_int(statement, 0)) : 0
    }

    private func stateCounts(table: String) throws -> [String: Int] {
        lock.lock()
        defer { lock.unlock() }
        let allowedTables = ["upload_queue", "garmin_backfill_tracks"]
        guard allowedTables.contains(table) else { return [:] }
        let sql = "SELECT state, COUNT(*) FROM \(table) GROUP BY state"
        var statement: OpaquePointer?
        guard sqlite3_prepare_v2(db, sql, -1, &statement, nil) == SQLITE_OK else { return [:] }
        defer { sqlite3_finalize(statement) }
        var counts: [String: Int] = [:]
        while sqlite3_step(statement) == SQLITE_ROW {
            counts[text(statement, 0)] = Int(sqlite3_column_int(statement, 1))
        }
        return counts
    }

    private func bind(_ values: [Any], to statement: OpaquePointer?) {
        for (index, value) in values.enumerated() {
            let position = Int32(index + 1)
            if value is NSNull {
                sqlite3_bind_null(statement, position)
            } else if let value = value as? String {
                sqlite3_bind_text(statement, position, value, -1, SQLITE_TRANSIENT)
            } else if let value = value as? Int {
                sqlite3_bind_int64(statement, position, Int64(value))
            } else if let value = value as? Int64 {
                sqlite3_bind_int64(statement, position, value)
            } else if let value = value as? Double {
                sqlite3_bind_double(statement, position, value)
            } else {
                sqlite3_bind_null(statement, position)
            }
        }
    }

    private func text(_ statement: OpaquePointer?, _ index: Int32) -> String {
        guard let cString = sqlite3_column_text(statement, index) else { return "" }
        return String(cString: cString)
    }

    private func nullableText(_ statement: OpaquePointer?, _ index: Int32) -> String? {
        sqlite3_column_type(statement, index) == SQLITE_NULL ? nil : text(statement, index)
    }

    private func date(_ statement: OpaquePointer?, _ index: Int32) -> Date? {
        sqlite3_column_type(statement, index) == SQLITE_NULL ? nil : Date(timeIntervalSince1970: sqlite3_column_double(statement, index))
    }
}

private let SQLITE_TRANSIENT = unsafeBitCast(-1, to: sqlite3_destructor_type.self)

extension JSONEncoder {
    static var ipca: JSONEncoder {
        let encoder = JSONEncoder()
        encoder.outputFormatting = [.prettyPrinted, .sortedKeys]
        encoder.dateEncodingStrategy = .iso8601
        return encoder
    }
}
