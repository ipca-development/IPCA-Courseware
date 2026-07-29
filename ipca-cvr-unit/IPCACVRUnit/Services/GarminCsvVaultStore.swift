import CryptoKit
import Foundation

@MainActor
final class GarminCsvVaultStore: ObservableObject {
    @Published private(set) var records: [GarminCsvVaultRecord] = []
    @Published private(set) var lastError = ""

    private let encoder: JSONEncoder
    private let decoder: JSONDecoder

    init() {
        encoder = JSONEncoder()
        encoder.outputFormatting = [.prettyPrinted, .sortedKeys]
        encoder.dateEncodingStrategy = .iso8601

        decoder = JSONDecoder()
        decoder.dateDecodingStrategy = .iso8601
    }

    func load() async {
        do {
            let url = try indexURL()
            if FileManager.default.fileExists(atPath: url.path) {
                let data = try Data(contentsOf: url)
                records = try decoder.decode(GarminCsvVaultIndex.self, from: data).records
            } else {
                records = []
            }
            try ensureVaultDirectories()
            lastError = ""
        } catch {
            lastError = "Garmin vault load failed: \(error.localizedDescription)"
        }
    }

    func record(forSHA256 sha256: String) -> GarminCsvVaultRecord? {
        records.first { $0.sha256.caseInsensitiveCompare(sha256) == .orderedSame }
    }

    func pendingRecords() -> [GarminCsvVaultRecord] {
        records.filter { $0.syncState == .pending || $0.syncState == .failed }
    }

    func syncedRecordsEligibleForPurge(olderThan cutoff: Date) -> [GarminCsvVaultRecord] {
        records.filter {
            ($0.syncState == .synced || $0.syncState == .duplicate)
                && $0.importedAt < cutoff
        }
    }

    @discardableResult
    func ingest(
        sourceURL: URL,
        relativePath: String,
        metadata: G3XFlightStreamMetadata,
        classification: GarminCsvClassification,
        flightRecordID: String?,
        uploadComponentID: String?
    ) throws -> GarminCsvVaultRecord {
        let data = try Data(contentsOf: sourceURL)
        let digest = SHA256.hash(data: data).map { String(format: "%02x", $0) }.joined()
        if let existing = record(forSHA256: digest) {
            update(existing.id) {
                $0.lastSeenAt = Date()
                if let flightRecordID {
                    $0.flightRecordID = flightRecordID
                }
                if let uploadComponentID {
                    $0.uploadComponentID = uploadComponentID
                }
            }
            return records.first { $0.id == existing.id } ?? existing
        }

        let destination = try vaultFileURL(sha256: digest)
        try data.write(to: destination, options: [.atomic])

        let now = Date()
        let record = GarminCsvVaultRecord(
            id: UUID().uuidString,
            sha256: digest,
            originalFilename: sourceURL.lastPathComponent,
            sourcePathOnCard: relativePath,
            dataLogType: classification.dataLogType,
            aircraftIdent: metadata.aircraftIdent,
            importProfile: metadata.importProfile,
            firstUtc: metadata.startUtc,
            lastUtc: metadata.endUtc,
            byteCount: Int64(data.count),
            importedAt: now,
            lastSeenAt: now,
            syncState: .pending,
            flightRecordID: flightRecordID,
            uploadComponentID: uploadComponentID,
            uploadUuid: nil,
            serverCsvFileUuid: nil,
            serverReceiptID: nil,
            lastError: ""
        )
        records.append(record)
        save()
        return record
    }

    func markSkippedGPSOnly(filename: String, relativePath: String) {
        // No persistent record needed; scan summary only.
        _ = filename
        _ = relativePath
    }

    func update(_ id: String, mutate: (inout GarminCsvVaultRecord) -> Void) {
        guard let index = records.firstIndex(where: { $0.id == id }) else { return }
        mutate(&records[index])
        save()
    }

    func markSynced(id: String, receiptID: String?, csvFileUuid: String?) {
        update(id) {
            $0.syncState = .synced
            $0.serverReceiptID = receiptID
            $0.serverCsvFileUuid = csvFileUuid
            $0.lastError = ""
        }
    }

    func markDuplicate(id: String, csvFileUuid: String?) {
        update(id) {
            $0.syncState = .duplicate
            $0.serverCsvFileUuid = csvFileUuid
            $0.lastError = ""
        }
    }

    func markFailed(id: String, message: String) {
        update(id) {
            $0.syncState = .failed
            $0.lastError = message
        }
    }

    func fileURL(for record: GarminCsvVaultRecord) throws -> URL {
        try vaultFileURL(sha256: record.sha256)
    }

    func purgeRecords(_ ids: Set<String>) {
        let fileManager = FileManager.default
        for id in ids {
            guard let record = records.first(where: { $0.id == id }) else { continue }
            if let url = try? vaultFileURL(sha256: record.sha256) {
                try? fileManager.removeItem(at: url)
            }
        }
        records.removeAll { ids.contains($0.id) }
        save()
    }

    func purgeExpired(retentionDays: Int, maxVaultBytes: Int64) {
        let cutoff = Calendar.current.date(byAdding: .day, value: -max(1, retentionDays), to: Date()) ?? Date.distantPast
        var ids = Set(syncedRecordsEligibleForPurge(olderThan: cutoff).map(\.id))

        var totalBytes = records.reduce(Int64(0)) { partial, record in
            if ids.contains(record.id) { return partial }
            return partial + record.byteCount
        }
        if maxVaultBytes > 0, totalBytes > maxVaultBytes {
            let removable = records
                .filter { ($0.syncState == .synced || $0.syncState == .duplicate) && !ids.contains($0.id) }
                .sorted { $0.importedAt < $1.importedAt }
            for record in removable where totalBytes > maxVaultBytes {
                ids.insert(record.id)
                totalBytes -= record.byteCount
            }
        }
        purgeRecords(ids)
    }

    private func save() {
        do {
            let url = try indexURL()
            let data = try encoder.encode(GarminCsvVaultIndex(records: records))
            try data.write(to: url, options: [.atomic])
            lastError = ""
        } catch {
            lastError = "Garmin vault save failed: \(error.localizedDescription)"
        }
    }

    private func ensureVaultDirectories() throws {
        let base = try baseDirectory()
        let files = base.appendingPathComponent("files", isDirectory: true)
        if !FileManager.default.fileExists(atPath: files.path) {
            try FileManager.default.createDirectory(at: files, withIntermediateDirectories: true)
        }
    }

    private func baseDirectory() throws -> URL {
        let support = try FileManager.default.url(
            for: .applicationSupportDirectory,
            in: .userDomainMask,
            appropriateFor: nil,
            create: true
        )
        let base = support.appendingPathComponent("IPCACVRUnit/GarminVault", isDirectory: true)
        if !FileManager.default.fileExists(atPath: base.path) {
            try FileManager.default.createDirectory(at: base, withIntermediateDirectories: true)
        }
        return base
    }

    private func indexURL() throws -> URL {
        try baseDirectory().appendingPathComponent("index.json")
    }

    private func vaultFileURL(sha256: String) throws -> URL {
        try baseDirectory().appendingPathComponent("files/\(sha256).csv")
    }
}
