import Combine
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

@MainActor
final class GarminSDCardRecoveryService: ObservableObject {
    @Published private(set) var isScanning = false
    @Published private(set) var cardConfigured = false
    @Published private(set) var cardAvailable = false
    @Published private(set) var lastSummary: GarminSDCardScanSummary?
    @Published private(set) var lastError = ""

    private let maxScanDepth = 8

    func refreshBookmarkState(settings: SettingsStore) {
        cardConfigured = settings.garminSDCardBookmarkData != nil
        cardAvailable = false
        guard cardConfigured else { return }
        if settings.garminSDCardBookmarkIsStale {
            cardAvailable = false
            return
        }
        guard let access = settings.beginGarminSDCardAccess() else { return }
        defer { access.stopAccess() }
        var isDirectory: ObjCBool = false
        cardAvailable = FileManager.default.fileExists(atPath: access.url.path, isDirectory: &isDirectory) && isDirectory.boolValue
    }

    func scanAndImportIfNeeded(
        settings: SettingsStore,
        vault: GarminCsvVaultStore,
        workflow: CVRWorkflowStore
    ) async -> GarminSDCardScanSummary? {
        guard !settings.isSimulationModeEnabled else { return nil }
        refreshBookmarkState(settings: settings)
        guard cardConfigured else {
            let summary = GarminSDCardScanSummary(
                scannedAt: Date(),
                cardAvailable: false,
                csvFilesScanned: 0,
                dataRichFound: 0,
                gpsOnlySkipped: 0,
                unreadableFiles: 0,
                alreadyKnown: 0,
                imported: 0,
                matchedFlightRecord: false,
                message: "Garmin SD card folder is not configured. Set it once in Admin."
            )
            lastSummary = summary
            return summary
        }
        if settings.garminSDCardBookmarkIsStale {
            let summary = GarminSDCardScanSummary(
                scannedAt: Date(),
                cardAvailable: false,
                csvFilesScanned: 0,
                dataRichFound: 0,
                gpsOnlySkipped: 0,
                unreadableFiles: 0,
                alreadyKnown: 0,
                imported: 0,
                matchedFlightRecord: false,
                message: "The saved SD card folder bookmark is stale. Re-select the Garmin folder in Admin with the card inserted."
            )
            lastSummary = summary
            return summary
        }
        guard let access = settings.beginGarminSDCardAccess() else {
            let summary = GarminSDCardScanSummary(
                scannedAt: Date(),
                cardAvailable: false,
                csvFilesScanned: 0,
                dataRichFound: 0,
                gpsOnlySkipped: 0,
                unreadableFiles: 0,
                alreadyKnown: 0,
                imported: 0,
                matchedFlightRecord: false,
                message: "Could not access the configured SD card folder. Re-select the Garmin folder in Admin with the card inserted."
            )
            lastSummary = summary
            return summary
        }
        defer { access.stopAccess() }

        let root = access.url
        var isDirectory: ObjCBool = false
        guard FileManager.default.fileExists(atPath: root.path, isDirectory: &isDirectory), isDirectory.boolValue else {
            let summary = GarminSDCardScanSummary(
                scannedAt: Date(),
                cardAvailable: false,
                csvFilesScanned: 0,
                dataRichFound: 0,
                gpsOnlySkipped: 0,
                unreadableFiles: 0,
                alreadyKnown: 0,
                imported: 0,
                matchedFlightRecord: false,
                message: "Insert the USB-C SD card reader and open Garmin Recovery again."
            )
            lastSummary = summary
            cardAvailable = false
            return summary
        }
        cardAvailable = true

        isScanning = true
        defer { isScanning = false }

        do {
            let inventory = try scanInventory(root: root)
            let candidates = inventory.dataRich
            var alreadyKnown = 0
            var imported = 0
            var matched = false

            let flightRecord = workflow.state.activeFlightRecord
            let expectedTail = settings.selectedAircraft?.registration
                ?? workflow.state.activeDispatch?.tailNumber
                ?? ""
            let recordingWindow = recordingWindow(for: workflow)

            let best = selectBestCandidate(
                candidates,
                expectedTail: expectedTail,
                recordingWindow: recordingWindow
            )

            for candidate in candidates {
                let sha = try sha256(for: candidate.fileURL)
                let hadVaultRecord = vault.record(forSHA256: sha) != nil
                if hadVaultRecord {
                    alreadyKnown += 1
                }

                guard let best, best.fileURL == candidate.fileURL, let flightRecord else { continue }

                let componentID = workflow.importGarminCSVFromRecovery(
                    sourceURL: candidate.fileURL,
                    sourceLabel: "sd_card_auto_import"
                )
                if let componentID {
                    _ = try vault.ingest(
                        sourceURL: candidate.fileURL,
                        relativePath: candidate.relativePath,
                        metadata: candidate.metadata,
                        classification: candidate.classification,
                        flightRecordID: flightRecord.id,
                        uploadComponentID: componentID
                    )
                    if !hadVaultRecord || workflow.state.uploadComponents.contains(where: { $0.id == componentID && $0.state == .queued }) {
                        imported += 1
                    }
                    matched = true
                }
            }

            let message: String
            if let best, matched {
                message = "Imported data-rich log \(best.filename) for the active Flight Record."
            } else if inventory.csvFiles == 0 {
                message = "No CSV files found under the configured folder (\(root.lastPathComponent)). Select the SD card root or its data_log folder in Admin."
            } else if candidates.isEmpty {
                message = "Found \(inventory.csvFiles) CSV file(s): \(inventory.gpsOnly) GPS-only, \(inventory.unreadable) unreadable. No data-rich engine/avionics logs."
            } else if best == nil {
                message = "Found data-rich logs but none matched this aircraft or flight window."
            } else if flightRecord == nil {
                message = "Found \(candidates.count) data-rich log(s). Create or recover a Flight Record before import."
            } else {
                message = "Scan complete. \(alreadyKnown) file(s) were already stored locally."
            }

            let summary = GarminSDCardScanSummary(
                scannedAt: Date(),
                cardAvailable: true,
                csvFilesScanned: inventory.csvFiles,
                dataRichFound: candidates.count,
                gpsOnlySkipped: inventory.gpsOnly,
                unreadableFiles: inventory.unreadable,
                alreadyKnown: alreadyKnown,
                imported: imported,
                matchedFlightRecord: matched,
                message: message
            )
            lastSummary = summary
            lastError = ""
            return summary
        } catch {
            lastError = error.localizedDescription
            let summary = GarminSDCardScanSummary(
                scannedAt: Date(),
                cardAvailable: true,
                csvFilesScanned: 0,
                dataRichFound: 0,
                gpsOnlySkipped: 0,
                unreadableFiles: 0,
                alreadyKnown: 0,
                imported: 0,
                matchedFlightRecord: false,
                message: error.localizedDescription
            )
            lastSummary = summary
            return summary
        }
    }

    private struct ScanInventory {
        var csvFiles = 0
        var unreadable = 0
        var gpsOnly = 0
        var dataRich: [GarminSDCardCandidate] = []
    }

    private struct RecordingWindow {
        var start: Date
        var end: Date
    }

    private func recordingWindow(for workflow: CVRWorkflowStore) -> RecordingWindow? {
        guard let flightRecord = workflow.state.activeFlightRecord else { return nil }
        let start = flightRecord.createdAt
        let end = flightRecord.updatedAt.addingTimeInterval(15 * 60)
        return RecordingWindow(start: start, end: end)
    }

    private func selectBestCandidate(
        _ candidates: [GarminSDCardCandidate],
        expectedTail: String,
        recordingWindow: RecordingWindow?
    ) -> GarminSDCardCandidate? {
        let scored = candidates.compactMap { candidate -> (GarminSDCardCandidate, Int)? in
            var score = 0
            if !expectedTail.isEmpty,
               GarminCsvClassifier.registrationsMatch(candidate.metadata.aircraftIdent, expectedTail) {
                score += 100
            }
            if let window = recordingWindow,
               let start = candidate.metadata.startUtc,
               let end = candidate.metadata.endUtc {
                if start <= window.end && end >= window.start {
                    score += 80
                } else {
                    let delta = abs(start.timeIntervalSince(window.start))
                    if delta <= 3600 {
                        score += max(0, 40 - Int(delta / 60))
                    }
                }
            }
            if candidate.classification.dataLogType == .fullAvionics {
                score += 20
            }
            if let modified = candidate.modificationDate {
                score += min(10, Int(Date().timeIntervalSince(modified) / -3600))
            }
            return score > 0 ? (candidate, score) : nil
        }
        return scored.max(by: { $0.1 < $1.1 })?.0 ?? candidates.first
    }

    private func scanInventory(root: URL) throws -> ScanInventory {
        var inventory = ScanInventory()
        let fileManager = FileManager.default
        let enumerator = fileManager.enumerator(
            at: root,
            includingPropertiesForKeys: [.isRegularFileKey, .contentModificationDateKey],
            options: [.skipsHiddenFiles]
        )
        while let item = enumerator?.nextObject() as? URL {
            let depth = item.pathComponents.count - root.pathComponents.count
            if depth > maxScanDepth {
                enumerator?.skipDescendants()
                continue
            }
            guard item.pathExtension.lowercased() == "csv" else { continue }
            inventory.csvFiles += 1
            do {
                guard let candidate = try classifyCandidate(item, root: root) else {
                    inventory.unreadable += 1
                    continue
                }
                if candidate.classification.isDataRich {
                    inventory.dataRich.append(candidate)
                } else {
                    inventory.gpsOnly += 1
                }
            } catch {
                inventory.unreadable += 1
            }
        }
        inventory.dataRich.sort {
            ($0.modificationDate ?? .distantPast) > ($1.modificationDate ?? .distantPast)
        }
        return inventory
    }

    private func classifyCandidate(_ url: URL, root: URL) throws -> GarminSDCardCandidate? {
        let preview = try G3XFlightStreamParser.parsePreview(fileURL: url)
        let classification = GarminCsvClassifier.classify(headers: preview.headers)
        guard classification.dataLogType != .invalid else { return nil }
        let values = try url.resourceValues(forKeys: [.contentModificationDateKey])
        let relative = relativePath(for: url, root: root)
        return GarminSDCardCandidate(
            fileURL: url,
            filename: url.lastPathComponent,
            relativePath: relative,
            metadata: preview.metadata,
            classification: classification,
            modificationDate: values.contentModificationDate
        )
    }

    private func relativePath(for url: URL, root: URL) -> String {
        let rootPath = root.standardizedFileURL.path
        let filePath = url.standardizedFileURL.path
        if filePath.hasPrefix(rootPath + "/") {
            return String(filePath.dropFirst(rootPath.count + 1))
        }
        return url.lastPathComponent
    }

    private func sha256(for url: URL) throws -> String {
        let data = try Data(contentsOf: url)
        return SHA256.hash(data: data).map { String(format: "%02x", $0) }.joined()
    }
}

@MainActor
final class GarminCsvSyncManager: ObservableObject {
    @Published private(set) var isSyncing = false
    @Published private(set) var lastError = ""

    func syncPending(
        settings: SettingsStore,
        vault: GarminCsvVaultStore,
        workflow: CVRWorkflowStore,
        network: NetworkMonitor,
        uploadManager: UploadManager
    ) async {
        guard !settings.isSimulationModeEnabled else { return }
        guard network.canUpload(allowCellular: settings.allowCellularUpload) else { return }
        guard settings.deviceCredential != nil else { return }
        guard let baseURL = settings.normalizedServerURL else { return }

        isSyncing = true
        defer { isSyncing = false }

        vault.purgeExpired(
            retentionDays: settings.garminVaultRetentionDays,
            maxVaultBytes: settings.garminVaultMaxBytes
        )

        let pending = vault.pendingRecords()
        guard !pending.isEmpty else {
            lastError = ""
            return
        }

        do {
            let client = APIClient(serverURL: baseURL)
            let credential = settings.deviceCredential ?? ""
            let hashes = pending.map(\.sha256)
            let known = try await client.knownGarminCsvHashes(
                sha256List: hashes,
                aircraftRegistration: settings.selectedAircraft?.registration ?? "",
                credential: credential
            )
            let knownSet = Set(known.known.map { $0.sha256.lowercased() })

            for record in pending {
                if knownSet.contains(record.sha256.lowercased()) {
                    let match = known.known.first { $0.sha256.caseInsensitiveCompare(record.sha256) == .orderedSame }
                    vault.markDuplicate(id: record.id, csvFileUuid: match?.csvFileUuid)
                    if let componentID = record.uploadComponentID {
                        workflow.updateUploadComponent(
                            id: componentID,
                            state: .serverVerified,
                            progress: 1,
                            lastError: "",
                            serverReceiptID: match?.csvFileUuid ?? "duplicate-\(record.sha256.prefix(12))"
                        )
                    }
                }
            }

            uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)

            for record in vault.records {
                guard let componentID = record.uploadComponentID,
                      let component = workflow.state.uploadComponents.first(where: { $0.id == componentID }) else {
                    continue
                }
                if component.state == .serverVerified, record.syncState == .pending || record.syncState == .uploading {
                    vault.markSynced(id: record.id, receiptID: component.serverReceiptID, csvFileUuid: nil)
                }
            }

            for record in vault.pendingRecords() where record.uploadComponentID == nil {
                try await uploadStandaloneRecord(
                    record: record,
                    client: client,
                    credential: credential,
                    vault: vault,
                    sessionUUID: workflow.state.activeFlightRecord?.recordingSessionID
                )
            }

            lastError = ""
        } catch {
            lastError = error.localizedDescription
        }
    }

    private func uploadStandaloneRecord(
        record: GarminCsvVaultRecord,
        client: APIClient,
        credential: String,
        vault: GarminCsvVaultStore,
        sessionUUID: String?
    ) async throws {
        let fileURL = try vault.fileURL(for: record)
        let fileSize = try fileSize(fileURL)
        let uploadUUID = record.uploadUuid ?? UUID().uuidString.lowercased()
        vault.update(record.id) {
            $0.uploadUuid = uploadUUID
            $0.syncState = .uploading
        }

        let chunkSize = 512 * 1024
        let totalChunks = max(1, Int(ceil(Double(fileSize) / Double(chunkSize))))
        for chunkIndex in 0..<totalChunks {
            let offset = Int64(chunkIndex * chunkSize)
            let count = min(chunkSize, Int(fileSize - offset))
            let chunkData = try readChunk(fileURL: fileURL, offset: offset, count: count)
            _ = try await client.uploadCvrCsvChunk(
                credential: credential,
                uploadUUID: uploadUUID,
                sessionUUID: sessionUUID,
                chunkIndex: chunkIndex,
                totalChunks: totalChunks,
                totalSize: fileSize,
                originalFilename: record.originalFilename,
                chunkData: chunkData
            )
        }

        let finalize = try await client.finalizeCvrCsvUpload(credential: credential, uploadUUID: uploadUUID)
        guard finalize.ok else {
            vault.markFailed(id: record.id, message: finalize.error ?? "Server rejected Garmin CSV finalize.")
            return
        }
        if finalize.status?.lowercased() == "duplicate" {
            vault.markDuplicate(id: record.id, csvFileUuid: finalize.csvFileUuid)
        } else {
            vault.markSynced(id: record.id, receiptID: finalize.csvFileUuid, csvFileUuid: finalize.csvFileUuid)
        }
    }

    private func fileSize(_ url: URL) throws -> Int64 {
        let values = try url.resourceValues(forKeys: [.fileSizeKey])
        return Int64(values.fileSize ?? 0)
    }

    private func readChunk(fileURL: URL, offset: Int64, count: Int) throws -> Data {
        let handle = try FileHandle(forReadingFrom: fileURL)
        defer { try? handle.close() }
        try handle.seek(toOffset: UInt64(offset))
        return try handle.read(upToCount: count) ?? Data()
    }
}
