import Foundation
import SQLite3

private let sqliteTransient = unsafeBitCast(-1, to: sqlite3_destructor_type.self)

struct DiscoveryOutcome {
    let file: IngestionFile
    let wasKnown: Bool
}

actor LocalIngestionStore {
    private var db: OpaquePointer?

    init(databaseURL: URL) throws {
        try FileManager.default.createDirectory(
            at: databaseURL.deletingLastPathComponent(),
            withIntermediateDirectories: true
        )
        db = try Self.openDatabase(at: databaseURL)
    }

    deinit { sqlite3_close(db) }

    private static func openDatabase(at url: URL) throws -> OpaquePointer {
        var database: OpaquePointer?
        guard sqlite3_open_v2(
            url.path,
            &database,
            SQLITE_OPEN_CREATE | SQLITE_OPEN_READWRITE | SQLITE_OPEN_FULLMUTEX,
            nil
        ) == SQLITE_OK, let database else {
            sqlite3_close(database)
            throw NSError(domain: "GarminSync.SQLite", code: 1)
        }
        do {
            try execute(database, "PRAGMA journal_mode=WAL;")
            try execute(database, "PRAGMA foreign_keys=ON;")
            try execute(database, """
            CREATE TABLE IF NOT EXISTS files (
              id TEXT PRIMARY KEY,
              relative_path TEXT NOT NULL,
              original_filename TEXT NOT NULL,
              source_size INTEGER NOT NULL,
              source_modification REAL NOT NULL,
              source_hash TEXT NOT NULL,
              local_path TEXT,
              local_size INTEGER,
              destination_hash TEXT,
              state TEXT NOT NULL CHECK(state IN ('DISCOVERED','COPYING','LOCAL_VERIFIED','WAITING_FOR_UPLOAD','UPLOADING','SERVER_VERIFIED','FAILED')),
              upload_id TEXT NOT NULL UNIQUE,
              uploaded_bytes INTEGER NOT NULL DEFAULT 0,
              local_verification_status TEXT NOT NULL DEFAULT 'PENDING',
              upload_status TEXT NOT NULL DEFAULT 'NOT_QUEUED',
              server_object_id TEXT,
              server_receipt_uuid TEXT,
              server_receipt_json TEXT,
              server_verification_status TEXT NOT NULL DEFAULT 'PENDING',
              retry_count INTEGER NOT NULL DEFAULT 0,
              error_message TEXT,
              first_seen REAL NOT NULL,
              last_seen REAL NOT NULL,
              created_at REAL NOT NULL,
              updated_at REAL NOT NULL,
              UNIQUE(source_hash)
            );
            CREATE INDEX IF NOT EXISTS files_state_idx ON files(state);
            CREATE TABLE IF NOT EXISTS scan_snapshots (
              id TEXT PRIMARY KEY,
              started_at REAL NOT NULL,
              completed_at REAL NOT NULL,
              folder_display_name TEXT NOT NULL,
              member_count INTEGER NOT NULL,
              device_id TEXT NOT NULL,
              found_count INTEGER NOT NULL,
              previously_known_count INTEGER NOT NULL,
              newly_copied_count INTEGER NOT NULL,
              completion_status TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS scan_snapshot_members (
              snapshot_id TEXT NOT NULL REFERENCES scan_snapshots(id),
              file_id TEXT NOT NULL REFERENCES files(id),
              relative_path TEXT NOT NULL,
              original_filename TEXT NOT NULL,
              size INTEGER NOT NULL,
              modification_date REAL NOT NULL,
              source_hash TEXT NOT NULL,
              PRIMARY KEY(snapshot_id, relative_path)
            );
            """)
            try migrateLegacySchema(database)
            return database
        } catch {
            sqlite3_close(database)
            throw error
        }
    }

    private static func migrateLegacySchema(_ database: OpaquePointer) throws {
        let fileColumns: [(String, String)] = [
            ("original_filename", "TEXT NOT NULL DEFAULT ''"),
            ("local_verification_status", "TEXT NOT NULL DEFAULT 'PENDING'"),
            ("upload_status", "TEXT NOT NULL DEFAULT 'NOT_QUEUED'"),
            ("server_object_id", "TEXT"),
            ("server_receipt_uuid", "TEXT"),
            ("server_receipt_json", "TEXT"),
            ("server_verification_status", "TEXT NOT NULL DEFAULT 'PENDING'"),
            ("retry_count", "INTEGER NOT NULL DEFAULT 0"),
            ("first_seen", "REAL NOT NULL DEFAULT 0"),
            ("last_seen", "REAL NOT NULL DEFAULT 0")
        ]
        let snapshotColumns: [(String, String)] = [
            ("device_id", "TEXT NOT NULL DEFAULT 'unknown'"),
            ("found_count", "INTEGER NOT NULL DEFAULT 0"),
            ("previously_known_count", "INTEGER NOT NULL DEFAULT 0"),
            ("newly_copied_count", "INTEGER NOT NULL DEFAULT 0"),
            ("completion_status", "TEXT NOT NULL DEFAULT 'COMPLETED'")
        ]
        let memberColumns = [("original_filename", "TEXT NOT NULL DEFAULT ''")]
        try addMissingColumns(database, table: "files", columns: fileColumns)
        try addMissingColumns(database, table: "scan_snapshots", columns: snapshotColumns)
        try addMissingColumns(database, table: "scan_snapshot_members", columns: memberColumns)
        try execute(database, """
        UPDATE files SET
          original_filename = CASE WHEN original_filename = '' THEN relative_path ELSE original_filename END,
          first_seen = CASE WHEN first_seen = 0 THEN created_at ELSE first_seen END,
          last_seen = CASE WHEN last_seen = 0 THEN updated_at ELSE last_seen END,
          local_verification_status = CASE WHEN state IN ('LOCAL_VERIFIED','WAITING_FOR_UPLOAD','UPLOADING','SERVER_VERIFIED') THEN 'VERIFIED' ELSE local_verification_status END,
          upload_status = CASE
            WHEN state = 'SERVER_VERIFIED' THEN 'VERIFIED'
            WHEN state = 'UPLOADING' THEN 'UPLOADING'
            WHEN state = 'WAITING_FOR_UPLOAD' THEN 'WAITING'
            ELSE upload_status END,
          server_verification_status = CASE WHEN state = 'SERVER_VERIFIED' THEN 'VERIFIED' ELSE server_verification_status END;
        """)
    }

    private static func addMissingColumns(
        _ database: OpaquePointer,
        table: String,
        columns: [(String, String)]
    ) throws {
        var statement: OpaquePointer?
        guard sqlite3_prepare_v2(database, "PRAGMA table_info(\(table))", -1, &statement, nil) == SQLITE_OK else {
            throw sqliteError(database)
        }
        var existing = Set<String>()
        while sqlite3_step(statement) == SQLITE_ROW {
            existing.insert(String(cString: sqlite3_column_text(statement, 1)))
        }
        sqlite3_finalize(statement)
        for (name, definition) in columns where !existing.contains(name) {
            try execute(database, "ALTER TABLE \(table) ADD COLUMN \(name) \(definition)")
        }
    }

    func discover(_ scanned: ScannedFile, sourceHash: String) throws -> IngestionFile {
        try discoverResult(scanned, sourceHash: sourceHash).file
    }

    func discoverResult(_ scanned: ScannedFile, sourceHash: String) throws -> DiscoveryOutcome {
        let now = Date()
        if let existing = try file(hash: sourceHash) {
            try run(
                "UPDATE files SET last_seen = ?, source_modification = ?, original_filename = ?, updated_at = ? WHERE id = ?",
                [.double(now.timeIntervalSince1970), .double(scanned.modificationDate.timeIntervalSince1970),
                 .text(URL(fileURLWithPath: scanned.relativePath).lastPathComponent),
                 .double(now.timeIntervalSince1970), .text(existing.id.uuidString)]
            )
            return DiscoveryOutcome(file: try file(id: existing.id)!, wasKnown: true)
        }
        let file = IngestionFile(
            id: UUID(),
            relativePath: scanned.relativePath,
            originalFilename: URL(fileURLWithPath: scanned.relativePath).lastPathComponent,
            sourceSize: scanned.size,
            sourceModificationDate: scanned.modificationDate,
            sourceHash: sourceHash,
            localPath: nil,
            localSize: nil,
            destinationHash: nil,
            state: .discovered,
            uploadID: UUID(),
            uploadedBytes: 0,
            localVerificationStatus: "PENDING",
            uploadStatus: "NOT_QUEUED",
            serverObjectID: nil,
            serverReceiptUUID: nil,
            serverReceiptJSON: nil,
            serverVerificationStatus: "PENDING",
            retryCount: 0,
            errorMessage: nil,
            firstSeen: now,
            lastSeen: now,
            createdAt: now,
            updatedAt: now
        )
        try run("""
        INSERT INTO files (
          id, relative_path, original_filename, source_size, source_modification, source_hash,
          state, upload_id, uploaded_bytes, local_verification_status, upload_status,
          server_verification_status, retry_count, first_seen, last_seen, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 'PENDING', 'NOT_QUEUED', 'PENDING', 0, ?, ?, ?, ?)
        """, [
            .text(file.id.uuidString), .text(file.relativePath), .text(file.originalFilename),
            .integer(file.sourceSize), .double(file.sourceModificationDate.timeIntervalSince1970),
            .text(file.sourceHash), .text(file.state.rawValue), .text(file.uploadID.uuidString),
            .double(now.timeIntervalSince1970), .double(now.timeIntervalSince1970),
            .double(now.timeIntervalSince1970), .double(now.timeIntervalSince1970)
        ])
        return DiscoveryOutcome(file: file, wasKnown: false)
    }

    func updateState(
        id: UUID,
        state: IngestionState,
        localPath: String? = nil,
        localSize: Int64? = nil,
        destinationHash: String? = nil,
        uploadedBytes: Int64? = nil,
        serverObjectID: String? = nil,
        serverReceiptUUID: String? = nil,
        serverReceiptJSON: String? = nil,
        error: String? = nil
    ) throws {
        let audit = auditStatuses(for: state)
        try run("""
        UPDATE files SET state = ?,
          local_path = COALESCE(?, local_path),
          local_size = COALESCE(?, local_size),
          destination_hash = COALESCE(?, destination_hash),
          uploaded_bytes = COALESCE(?, uploaded_bytes),
          local_verification_status = COALESCE(?, local_verification_status),
          upload_status = COALESCE(?, upload_status),
          server_object_id = COALESCE(?, server_object_id),
          server_receipt_uuid = COALESCE(?, server_receipt_uuid),
          server_receipt_json = COALESCE(?, server_receipt_json),
          server_verification_status = COALESCE(?, server_verification_status),
          error_message = ?,
          updated_at = ?
        WHERE id = ?
        """, [
            .text(state.rawValue), .optionalText(localPath), .optionalInteger(localSize),
            .optionalText(destinationHash), .optionalInteger(uploadedBytes),
            .optionalText(audit.local), .optionalText(audit.upload), .optionalText(serverObjectID),
            .optionalText(serverReceiptUUID), .optionalText(serverReceiptJSON),
            .optionalText(audit.server), .optionalText(error),
            .double(Date().timeIntervalSince1970), .text(id.uuidString)
        ])
    }

    func recordUploadFailure(id: UUID, error: String) throws {
        try run("""
        UPDATE files SET state = 'WAITING_FOR_UPLOAD', upload_status = 'WAITING',
          retry_count = retry_count + 1, error_message = ?, updated_at = ? WHERE id = ?
        """, [.text(error), .double(Date().timeIntervalSince1970), .text(id.uuidString)])
    }

    func markServerVerificationRejected(id: UUID) throws {
        try run(
            "UPDATE files SET server_verification_status = 'REJECTED', updated_at = ? WHERE id = ?",
            [.double(Date().timeIntervalSince1970), .text(id.uuidString)]
        )
    }

    func createCompletedSnapshot(
        id: UUID = UUID(),
        startedAt: Date,
        completedAt: Date = Date(),
        folderDisplayName: String,
        deviceID: String,
        foundCount: Int,
        previouslyKnownCount: Int,
        newlyCopiedCount: Int,
        members: [ScanSnapshotMember]
    ) throws {
        try transaction {
            try run("""
            INSERT INTO scan_snapshots (
              id, started_at, completed_at, folder_display_name, member_count, device_id,
              found_count, previously_known_count, newly_copied_count, completion_status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'COMPLETED')
            """, [
                .text(id.uuidString), .double(startedAt.timeIntervalSince1970),
                .double(completedAt.timeIntervalSince1970), .text(folderDisplayName),
                .integer(Int64(members.count)), .text(deviceID), .integer(Int64(foundCount)),
                .integer(Int64(previouslyKnownCount)), .integer(Int64(newlyCopiedCount))
            ])
            for member in members {
                try run("""
                INSERT INTO scan_snapshot_members (
                  snapshot_id, file_id, relative_path, original_filename, size, modification_date, source_hash
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
                """, [
                    .text(id.uuidString), .text(member.fileID.uuidString), .text(member.relativePath),
                    .text(member.originalFilename), .integer(member.size),
                    .double(member.modificationDate.timeIntervalSince1970), .text(member.sourceHash)
                ])
            }
        }
    }

    func allFiles() throws -> [IngestionFile] {
        try queryFiles("\(fileSelect) ORDER BY created_at, relative_path")
    }

    func files(in states: [IngestionState]) throws -> [IngestionFile] {
        guard !states.isEmpty else { return [] }
        let placeholders = states.map { _ in "?" }.joined(separator: ",")
        return try queryFiles(
            "\(fileSelect) WHERE state IN (\(placeholders)) ORDER BY created_at",
            states.map { .text($0.rawValue) }
        )
    }

    func snapshots() throws -> [ScanSnapshot] {
        var statement: OpaquePointer?
        let sql = """
        SELECT id, started_at, completed_at, folder_display_name, member_count, device_id,
               found_count, previously_known_count, newly_copied_count, completion_status
        FROM scan_snapshots ORDER BY completed_at DESC
        """
        guard sqlite3_prepare_v2(db, sql, -1, &statement, nil) == SQLITE_OK else { throw databaseError() }
        defer { sqlite3_finalize(statement) }
        var result: [ScanSnapshot] = []
        while sqlite3_step(statement) == SQLITE_ROW {
            result.append(.init(
                id: UUID(uuidString: text(statement, 0))!,
                startedAt: date(statement, 1),
                completedAt: date(statement, 2),
                folderDisplayName: text(statement, 3),
                memberCount: Int(sqlite3_column_int64(statement, 4)),
                deviceID: text(statement, 5),
                foundCount: Int(sqlite3_column_int64(statement, 6)),
                previouslyKnownCount: Int(sqlite3_column_int64(statement, 7)),
                newlyCopiedCount: Int(sqlite3_column_int64(statement, 8)),
                completionStatus: text(statement, 9)
            ))
        }
        return result
    }

    func recoverInterruptedWork(partialDirectory: URL) throws {
        try run("""
        UPDATE files SET state = 'DISCOVERED', local_verification_status = 'PENDING',
          error_message = 'Copy interrupted; ready to retry.', updated_at = ? WHERE state = 'COPYING'
        """, [.double(Date().timeIntervalSince1970)])
        try run("""
        UPDATE files SET state = 'WAITING_FOR_UPLOAD', upload_status = 'WAITING',
          error_message = 'Upload interrupted; ready to resume.', updated_at = ? WHERE state = 'UPLOADING'
        """, [.double(Date().timeIntervalSince1970)])
        guard let items = try? FileManager.default.contentsOfDirectory(
            at: partialDirectory,
            includingPropertiesForKeys: nil
        ) else { return }
        for item in items where item.pathExtension == "partial" {
            try? FileManager.default.removeItem(at: item)
        }
    }

    private var fileSelect: String {
        """
        SELECT id, relative_path, original_filename, source_size, source_modification, source_hash,
          local_path, local_size, destination_hash, state, upload_id, uploaded_bytes,
          local_verification_status, upload_status, server_object_id, server_receipt_uuid,
          server_receipt_json, server_verification_status, retry_count, error_message,
          first_seen, last_seen, created_at, updated_at
        FROM files
        """
    }

    private func file(id: UUID) throws -> IngestionFile? {
        try queryFiles("\(fileSelect) WHERE id = ? LIMIT 1", [.text(id.uuidString)]).first
    }

    private func file(hash: String) throws -> IngestionFile? {
        try queryFiles(
            "\(fileSelect) WHERE source_hash = ? LIMIT 1",
            [.text(hash)]
        ).first
    }

    private func queryFiles(_ sql: String, _ bindings: [Binding] = []) throws -> [IngestionFile] {
        var statement: OpaquePointer?
        guard sqlite3_prepare_v2(db, sql, -1, &statement, nil) == SQLITE_OK else { throw databaseError() }
        defer { sqlite3_finalize(statement) }
        try bind(bindings, to: statement)
        var result: [IngestionFile] = []
        while sqlite3_step(statement) == SQLITE_ROW {
            result.append(.init(
                id: UUID(uuidString: text(statement, 0))!,
                relativePath: text(statement, 1),
                originalFilename: text(statement, 2),
                sourceSize: sqlite3_column_int64(statement, 3),
                sourceModificationDate: date(statement, 4),
                sourceHash: text(statement, 5),
                localPath: optionalText(statement, 6),
                localSize: optionalInteger(statement, 7),
                destinationHash: optionalText(statement, 8),
                state: IngestionState(rawValue: text(statement, 9))!,
                uploadID: UUID(uuidString: text(statement, 10))!,
                uploadedBytes: sqlite3_column_int64(statement, 11),
                localVerificationStatus: text(statement, 12),
                uploadStatus: text(statement, 13),
                serverObjectID: optionalText(statement, 14),
                serverReceiptUUID: optionalText(statement, 15),
                serverReceiptJSON: optionalText(statement, 16),
                serverVerificationStatus: text(statement, 17),
                retryCount: Int(sqlite3_column_int64(statement, 18)),
                errorMessage: optionalText(statement, 19),
                firstSeen: date(statement, 20),
                lastSeen: date(statement, 21),
                createdAt: date(statement, 22),
                updatedAt: date(statement, 23)
            ))
        }
        return result
    }

    private func auditStatuses(for state: IngestionState) -> (local: String?, upload: String?, server: String?) {
        switch state {
        case .discovered: ("PENDING", "NOT_QUEUED", "PENDING")
        case .copying: ("PENDING", nil, nil)
        case .localVerified: ("VERIFIED", "NOT_QUEUED", nil)
        case .waitingForUpload: ("VERIFIED", "WAITING", nil)
        case .uploading: ("VERIFIED", "UPLOADING", nil)
        case .serverVerified: ("VERIFIED", "VERIFIED", "VERIFIED")
        case .failed: ("FAILED", nil, nil)
        }
    }

    private enum Binding {
        case text(String), integer(Int64), double(Double), null
        static func optionalText(_ value: String?) -> Self { value.map(Self.text) ?? .null }
        static func optionalInteger(_ value: Int64?) -> Self { value.map(Self.integer) ?? .null }
    }

    private func run(_ sql: String, _ bindings: [Binding] = []) throws {
        var statement: OpaquePointer?
        guard sqlite3_prepare_v2(db, sql, -1, &statement, nil) == SQLITE_OK else { throw databaseError() }
        defer { sqlite3_finalize(statement) }
        try bind(bindings, to: statement)
        guard sqlite3_step(statement) == SQLITE_DONE else { throw databaseError() }
    }

    private func bind(_ bindings: [Binding], to statement: OpaquePointer?) throws {
        for (offset, value) in bindings.enumerated() {
            let index = Int32(offset + 1)
            let result: Int32
            switch value {
            case .text(let value): result = sqlite3_bind_text(statement, index, value, -1, sqliteTransient)
            case .integer(let value): result = sqlite3_bind_int64(statement, index, value)
            case .double(let value): result = sqlite3_bind_double(statement, index, value)
            case .null: result = sqlite3_bind_null(statement, index)
            }
            guard result == SQLITE_OK else { throw databaseError() }
        }
    }

    private func transaction(_ operation: () throws -> Void) throws {
        try execute("BEGIN IMMEDIATE")
        do {
            try operation()
            try execute("COMMIT")
        } catch {
            try? execute("ROLLBACK")
            throw error
        }
    }

    private func execute(_ sql: String) throws {
        guard sqlite3_exec(db, sql, nil, nil, nil) == SQLITE_OK else { throw databaseError() }
    }

    private static func execute(_ db: OpaquePointer, _ sql: String) throws {
        guard sqlite3_exec(db, sql, nil, nil, nil) == SQLITE_OK else { throw sqliteError(db) }
    }

    private static func sqliteError(_ db: OpaquePointer) -> NSError {
        NSError(
            domain: "GarminSync.SQLite",
            code: Int(sqlite3_errcode(db)),
            userInfo: [NSLocalizedDescriptionKey: String(cString: sqlite3_errmsg(db))]
        )
    }

    private func databaseError() -> NSError { Self.sqliteError(db!) }
    private func text(_ statement: OpaquePointer?, _ column: Int32) -> String {
        String(cString: sqlite3_column_text(statement, column))
    }
    private func optionalText(_ statement: OpaquePointer?, _ column: Int32) -> String? {
        sqlite3_column_type(statement, column) == SQLITE_NULL ? nil : text(statement, column)
    }
    private func optionalInteger(_ statement: OpaquePointer?, _ column: Int32) -> Int64? {
        sqlite3_column_type(statement, column) == SQLITE_NULL ? nil : sqlite3_column_int64(statement, column)
    }
    private func date(_ statement: OpaquePointer?, _ column: Int32) -> Date {
        Date(timeIntervalSince1970: sqlite3_column_double(statement, column))
    }
}
