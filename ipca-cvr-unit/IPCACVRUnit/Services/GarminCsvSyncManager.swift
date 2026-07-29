import Combine
import Foundation

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
