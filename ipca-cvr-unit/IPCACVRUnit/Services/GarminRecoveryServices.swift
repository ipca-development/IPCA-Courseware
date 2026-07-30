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
        records.filter {
            $0.syncState == .pending
                || $0.syncState == .uploading
                || $0.syncState == .failed
        }
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
    @Published private(set) var scanPhase = ""
    @Published private(set) var scanFilesProcessed = 0
    @Published private(set) var scanFilesTotal = 0
    @Published private(set) var scanDataRichFound = 0
    @Published private(set) var scanGpsOnlySkipped = 0

    private let maxScanDepth = 8

    var scanProgress: Double? {
        guard scanFilesTotal > 0 else { return nil }
        return min(1, max(0, Double(scanFilesProcessed) / Double(scanFilesTotal)))
    }

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
            let detail = settings.garminSDCardLastAccessError.trimmingCharacters(in: .whitespacesAndNewlines)
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
                message: detail.isEmpty
                    ? "Could not access the configured SD card folder. Re-select the Garmin folder in Admin with the card inserted."
                    : detail
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
        scanPhase = "Finding CSV files on the SD card"
        scanFilesProcessed = 0
        scanFilesTotal = 0
        scanDataRichFound = 0
        scanGpsOnlySkipped = 0
        defer {
            isScanning = false
            scanPhase = ""
        }

        do {
            let inventory = try await scanInventory(root: root)
            let candidates = inventory.dataRich
            var alreadyKnown = 0
            var imported = 0
            var matched = false
            var vaultFailures = 0

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

            scanPhase = "Copying data-rich files to the local vault"
            scanFilesProcessed = 0
            scanFilesTotal = candidates.count
            for (index, candidate) in candidates.enumerated() {
                do {
                    let sha = try sha256(for: candidate.fileURL)
                    let existingVaultRecord = vault.record(forSHA256: sha)
                    let hadVaultRecord = existingVaultRecord != nil
                    if hadVaultRecord {
                        alreadyKnown += 1
                    }

                    var matchedFlightRecordID: String?
                    var uploadComponentID: String?
                    if let best, best.fileURL == candidate.fileURL, let flightRecord {
                        if existingVaultRecord?.flightRecordID == flightRecord.id,
                           let existingComponentID = existingVaultRecord?.uploadComponentID,
                           workflow.state.uploadComponents.contains(where: { $0.id == existingComponentID }) {
                            uploadComponentID = existingComponentID
                        } else {
                            uploadComponentID = workflow.importGarminCSVFromRecovery(
                                sourceURL: candidate.fileURL,
                                sourceLabel: "sd_card_auto_import"
                            )
                        }
                        if uploadComponentID != nil {
                            matchedFlightRecordID = flightRecord.id
                            matched = true
                        }
                    }

                    // Copy every data-rich CSV into the local vault while the card is
                    // available. Non-matching files synchronize as standalone records;
                    // the best current-flight match also joins the workflow upload.
                    _ = try vault.ingest(
                        sourceURL: candidate.fileURL,
                        relativePath: candidate.relativePath,
                        metadata: candidate.metadata,
                        classification: candidate.classification,
                        flightRecordID: matchedFlightRecordID,
                        uploadComponentID: uploadComponentID
                    )
                    if !hadVaultRecord {
                        imported += 1
                    }
                } catch {
                    vaultFailures += 1
                }
                scanFilesProcessed = index + 1
                await Task.yield()
            }

            let failureDetail = vaultFailures > 0
                ? " \(vaultFailures) data-rich file(s) could not be copied and will be retried on the next scan."
                : ""
            let message: String
            if let best, matched {
                message = "Vaulted \(imported) new data-rich file(s); \(alreadyKnown) already stored; \(inventory.gpsOnly) GPS-only skipped.\(failureDetail) Matched \(best.filename) to the active Flight Record. Server sync starts automatically."
            } else if inventory.csvFiles == 0 {
                message = "No CSV files found under the configured folder (\(root.lastPathComponent)). Select the SD card root or its data_log folder in Admin."
            } else if candidates.isEmpty {
                message = "Found \(inventory.csvFiles) CSV file(s): \(inventory.gpsOnly) GPS-only, \(inventory.unreadable) unreadable. No data-rich engine/avionics logs."
            } else if flightRecord == nil {
                message = "Vaulted \(imported) new data-rich file(s); \(alreadyKnown) already stored; \(inventory.gpsOnly) GPS-only skipped.\(failureDetail) They will synchronize with the server as standalone files."
            } else if best == nil {
                message = "Vaulted \(imported) new data-rich file(s); \(alreadyKnown) already stored; \(inventory.gpsOnly) GPS-only skipped.\(failureDetail) None matched this aircraft or flight window, but server sync will still include them."
            } else {
                message = "Vaulted \(imported) new data-rich file(s); \(alreadyKnown) already stored; \(inventory.gpsOnly) GPS-only skipped.\(failureDetail) Server sync starts automatically."
            }

            let summary = GarminSDCardScanSummary(
                scannedAt: Date(),
                cardAvailable: true,
                csvFilesScanned: inventory.csvFiles,
                dataRichFound: candidates.count,
                gpsOnlySkipped: inventory.gpsOnly,
                unreadableFiles: inventory.unreadable + vaultFailures,
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

    private func scanInventory(root: URL) async throws -> ScanInventory {
        var inventory = ScanInventory()
        let fileManager = FileManager.default
        let enumerator = fileManager.enumerator(
            at: root,
            includingPropertiesForKeys: [.isRegularFileKey, .contentModificationDateKey],
            options: [.skipsHiddenFiles]
        )
        var csvURLs: [URL] = []
        while let item = enumerator?.nextObject() as? URL {
            let depth = item.pathComponents.count - root.pathComponents.count
            if depth > maxScanDepth {
                enumerator?.skipDescendants()
                continue
            }
            guard item.pathExtension.lowercased() == "csv" else { continue }
            csvURLs.append(item)
            scanFilesTotal = csvURLs.count
            if csvURLs.count.isMultiple(of: 20) {
                await Task.yield()
            }
        }

        inventory.csvFiles = csvURLs.count
        scanPhase = "Classifying CSV files"
        scanFilesProcessed = 0
        scanFilesTotal = csvURLs.count
        for (index, item) in csvURLs.enumerated() {
            do {
                guard let candidate = try classifyCandidate(item, root: root) else {
                    inventory.unreadable += 1
                    scanFilesProcessed = index + 1
                    await Task.yield()
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
            scanFilesProcessed = index + 1
            scanDataRichFound = inventory.dataRich.count
            scanGpsOnlySkipped = inventory.gpsOnly
            await Task.yield()
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
    @Published private(set) var syncPhase = ""
    @Published private(set) var syncFilesProcessed = 0
    @Published private(set) var syncFilesTotal = 0
    @Published private(set) var currentFileName = ""
    @Published private(set) var currentFileProgress = 0.0

    var syncProgress: Double? {
        guard syncFilesTotal > 0 else { return nil }
        let completed = Double(syncFilesProcessed) + currentFileProgress
        return min(1, max(0, completed / Double(syncFilesTotal)))
    }

    func syncPending(
        settings: SettingsStore,
        vault: GarminCsvVaultStore,
        workflow: CVRWorkflowStore,
        network: NetworkMonitor,
        uploadManager: UploadManager
    ) async {
        guard !isSyncing else { return }
        guard !settings.isSimulationModeEnabled else { return }
        guard network.canUpload(allowCellular: settings.allowCellularUpload) else { return }
        guard settings.deviceCredential != nil else { return }
        guard let baseURL = settings.normalizedServerURL else { return }

        isSyncing = true
        syncPhase = "Preparing synchronization"
        syncFilesProcessed = 0
        syncFilesTotal = 0
        currentFileName = ""
        currentFileProgress = 0
        defer {
            isSyncing = false
            currentFileName = ""
            currentFileProgress = 0
        }

        vault.purgeExpired(
            retentionDays: settings.garminVaultRetentionDays,
            maxVaultBytes: settings.garminVaultMaxBytes
        )

        let pending = vault.pendingRecords()
        guard !pending.isEmpty else {
            lastError = ""
            syncPhase = "All data-rich files are synchronized"
            return
        }

        do {
            let client = APIClient(serverURL: baseURL)
            let credential = settings.deviceCredential ?? ""
            let hashes = pending.map(\.sha256)
            syncPhase = "Comparing \(pending.count) file(s) with the server"
            syncFilesTotal = pending.count
            let known = try await client.knownGarminCsvHashes(
                sha256List: hashes,
                aircraftRegistration: settings.selectedAircraft?.registration ?? "",
                credential: credential
            )
            let knownSet = Set(known.known.map { $0.sha256.lowercased() })
            var serverKnownCount = 0

            for record in pending {
                if knownSet.contains(record.sha256.lowercased()) {
                    serverKnownCount += 1
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

            let standaloneRecords = vault.pendingRecords().filter { $0.uploadComponentID == nil }
            syncFilesProcessed = serverKnownCount
            syncFilesTotal = serverKnownCount + standaloneRecords.count
            var standaloneErrors: [String] = []
            for (index, record) in standaloneRecords.enumerated() {
                currentFileName = record.originalFilename
                currentFileProgress = 0
                syncPhase = "Uploading file \(index + 1) of \(standaloneRecords.count)"
                do {
                    try await uploadStandaloneRecord(
                        record: record,
                        client: client,
                        credential: credential,
                        vault: vault,
                        sessionUUID: nil
                    )
                } catch {
                    let message = error.localizedDescription
                    vault.markFailed(id: record.id, message: message)
                    standaloneErrors.append("\(record.originalFilename): \(message)")
                }
                currentFileProgress = 0
                syncFilesProcessed = serverKnownCount + index + 1
            }

            lastError = standaloneErrors.joined(separator: "\n")
            syncPhase = standaloneErrors.isEmpty
                ? "Synchronization complete"
                : "Synchronization completed with \(standaloneErrors.count) error(s)"
        } catch {
            lastError = error.localizedDescription
            syncPhase = "Synchronization failed"
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
            currentFileProgress = Double(chunkIndex + 1) / Double(totalChunks)
        }

        syncPhase = "Finalizing \(record.originalFilename)"
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
