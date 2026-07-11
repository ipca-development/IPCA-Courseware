import Combine
import Foundation

@MainActor
final class UploadManager: ObservableObject {
    @Published private(set) var activeUploads: Set<String> = []
    private let chunkSize = 512 * 1024
    private let maxChunkAttempts = 8

    private lazy var session: URLSession = {
        let configuration = URLSessionConfiguration.default
        configuration.timeoutIntervalForRequest = 120
        configuration.timeoutIntervalForResource = 6 * 3600
        configuration.waitsForConnectivity = true
        configuration.allowsCellularAccess = true
        return URLSession(configuration: configuration)
    }()

    func uploadPending(store: RecordingStore, settings: SettingsStore, network: NetworkMonitor) {
        guard network.canUpload(allowCellular: settings.allowCellularUpload) else { return }
        for id in store.pendingUploadIDs() {
            upload(recordingID: id, store: store, settings: settings)
        }
    }

    func upload(recordingID: String, store: RecordingStore, settings: SettingsStore) {
        guard settings.isServerURLConfigured else {
            store.update(recordingID) {
                $0.uploadStatus = .failed
                $0.lastError = "Server URL is not configured."
            }
            return
        }

        guard !activeUploads.contains(recordingID) else { return }

        activeUploads.insert(recordingID)
        store.update(recordingID) {
            if $0.uploadStatus != .uploaded {
                $0.uploadStatus = .uploading
            }
            if $0.uploadProgress < 0.01 {
                $0.lastError = "Starting automatic upload..."
            }
        }

        Task {
            defer {
                Task { @MainActor in
                    self.activeUploads.remove(recordingID)
                }
            }

            do {
                try await performUpload(recordingID: recordingID, store: store, settings: settings)
            } catch {
                await MainActor.run {
                    store.update(recordingID) {
                        $0.uploadStatus = .failed
                        $0.lastError = error.localizedDescription
                    }
                }
            }
        }
    }

    private func performUpload(recordingID: String, store: RecordingStore, settings: SettingsStore) async throws {
        guard let baseURL = settings.normalizedServerURL else {
            throw APIClientError.invalidServerURL
        }
        guard let recording = store.recording(id: recordingID) else { return }

        let client = APIClient(serverURL: baseURL)
        let uploadResponse = try await performChunkedUpload(recording: recording, language: settings.language, client: client, store: store)
        if !uploadResponse.ok {
            throw APIClientError.badResponse(uploadResponse.error ?? "Upload failed.")
        }

        let serverRecordingID = uploadResponse.recording?.recordingID ?? recordingID
        await MainActor.run {
            store.update(recordingID) {
                $0.serverID = serverRecordingID
                $0.uploadStatus = .uploaded
                $0.uploadProgress = 1
                $0.transcriptStatus = .transcribing
                $0.transcriptProgress = uploadResponse.recording?.progress ?? 0
                $0.lastError = ""
            }
        }

        try await pollTranscript(recordingID: recordingID, serverRecordingID: serverRecordingID, store: store, client: client)
    }

    private func performChunkedUpload(recording: Recording, language: String, client: APIClient, store: RecordingStore) async throws -> UploadResponse {
        let audioURL = try RecordingStore.resolvedFileURL(
            preferredPath: recording.filePath,
            recordingID: recording.id,
            fallbackFilename: "\(recording.id).m4a"
        )
        let audioSize = try fileSize(audioURL)
        let totalBytes = max(1, audioSize)

        await MainActor.run {
            store.update(recording.id) {
                $0.uploadProgress = 0.01
                $0.lastError = "Preparing chunked audio upload..."
            }
        }

        _ = try await uploadFileChunks(
            recording: recording,
            client: client,
            fileURL: audioURL,
            originalFilename: audioURL.lastPathComponent,
            mimeType: mimeType(for: audioURL),
            fileSize: audioSize,
            uploadedBytes: 0,
            totalBytes: totalBytes,
            store: store
        )

        await MainActor.run {
            store.update(recording.id) {
                $0.uploadProgress = 0.99
                $0.lastError = "Finalizing audio package..."
            }
        }

        let finalizeRequest = try client.finalizeChunkedUploadRequest(for: recording, language: language)
        let (data, response) = try await data(for: finalizeRequest)
        return try client.decodeUploadResponse(data: data, response: response)
    }

    private func uploadFileChunks(
        recording: Recording,
        client: APIClient,
        fileURL: URL,
        originalFilename: String,
        mimeType: String,
        fileSize: Int64,
        uploadedBytes: Int64,
        totalBytes: Int64,
        store: RecordingStore
    ) async throws -> Int64 {
        let totalChunks = Int(ceil(Double(fileSize) / Double(chunkSize)))
        var completedBytes = uploadedBytes
        var receivedChunks = Set<Int>()

        do {
            let status = try await client.chunkUploadStatus(recordingID: recording.id, fileType: "audio")
            if status.ok, let chunks = status.receivedChunks {
                receivedChunks = Set(chunks)
            }
        } catch {
            // Resume is optional; continue from the beginning if status is unavailable.
        }

        let startIndex = firstMissingChunkIndex(received: receivedChunks, totalChunks: totalChunks)
        if startIndex >= totalChunks {
            return uploadedBytes + fileSize
        }

        if startIndex > 0 {
            let bytesAlreadyUploadedForFile = Int64(min(Int64(startIndex) * Int64(chunkSize), fileSize))
            completedBytes = uploadedBytes + bytesAlreadyUploadedForFile
            await MainActor.run {
                store.update(recording.id) {
                    $0.uploadProgress = min(0.98, Double(completedBytes) / Double(totalBytes) * 0.98)
                    $0.lastError = "Resuming audio upload at chunk \(startIndex + 1)/\(totalChunks)..."
                }
            }
        }

        for chunkIndex in startIndex..<totalChunks {
            let offset = Int64(chunkIndex * chunkSize)
            let count = min(chunkSize, Int(fileSize - offset))
            let chunkData = try readChunk(fileURL: fileURL, offset: offset, count: count, chunkIndex: chunkIndex)

            let request = client.chunkUploadRequest(
                recording: recording,
                fileType: "audio",
                chunkIndex: chunkIndex,
                totalChunks: totalChunks,
                totalSize: fileSize,
                chunkSize: count,
                originalFilename: originalFilename,
                mimeType: mimeType
            )

            await MainActor.run {
                store.update(recording.id) {
                    let baseProgress = Double(completedBytes) / Double(totalBytes) * 0.98
                    $0.uploadProgress = max(0.01, min(0.98, baseProgress))
                    $0.lastError = "Uploading audio chunk \(chunkIndex + 1)/\(totalChunks)..."
                }
            }

            var lastError: Error?
            for attempt in 1...maxChunkAttempts {
                do {
                    let (data, response) = try await send(request: request, body: chunkData)
                    let chunkResponse = try client.decodeChunkUploadResponse(data: data, response: response)
                    if !chunkResponse.ok {
                        throw APIClientError.badResponse(chunkResponse.error ?? "Chunk upload failed.")
                    }
                    lastError = nil
                    break
                } catch {
                    lastError = error
                    if attempt < maxChunkAttempts {
                        let delayNs = UInt64(min(30, attempt * attempt * 2)) * 1_000_000_000
                        await MainActor.run {
                            store.update(recording.id) {
                                $0.lastError = "Retrying audio chunk \(chunkIndex + 1)/\(totalChunks) (attempt \(attempt + 1)/\(maxChunkAttempts)): \(error.localizedDescription)"
                            }
                        }
                        try await Task.sleep(nanoseconds: delayNs)
                    }
                }
            }

            if let lastError {
                throw APIClientError.badResponse(
                    "Failed audio chunk \(chunkIndex + 1)/\(totalChunks): \(lastError.localizedDescription)"
                )
            }

            completedBytes += Int64(count)
            await MainActor.run {
                store.update(recording.id) {
                    $0.uploadProgress = min(0.98, Double(completedBytes) / Double(totalBytes) * 0.98)
                    $0.lastError = "Uploaded audio chunk \(chunkIndex + 1)/\(totalChunks)"
                }
            }
        }

        return completedBytes
    }

    private func firstMissingChunkIndex(received: Set<Int>, totalChunks: Int) -> Int {
        guard !received.isEmpty else { return 0 }
        for index in 0..<totalChunks where !received.contains(index) {
            return index
        }
        return totalChunks
    }

    private func send(request: URLRequest, body: Data) async throws -> (Data, URLResponse) {
        let tempURL = FileManager.default.temporaryDirectory
            .appendingPathComponent("ipca-cvr-audio-chunk-\(UUID().uuidString).bin")
        try body.write(to: tempURL, options: .atomic)
        defer { try? FileManager.default.removeItem(at: tempURL) }

        return try await withCheckedThrowingContinuation { continuation in
            var request = request
            request.httpBody = nil
            let task = session.uploadTask(with: request, fromFile: tempURL) { data, response, error in
                if let error {
                    continuation.resume(throwing: error)
                    return
                }
                guard let data, let response else {
                    continuation.resume(throwing: APIClientError.badResponse("Upload returned no response."))
                    return
                }
                continuation.resume(returning: (data, response))
            }

            task.resume()
        }
    }

    private func data(for request: URLRequest) async throws -> (Data, URLResponse) {
        try await withCheckedThrowingContinuation { continuation in
            let task = session.dataTask(with: request) { data, response, error in
                if let error {
                    continuation.resume(throwing: error)
                    return
                }
                guard let data, let response else {
                    continuation.resume(throwing: APIClientError.badResponse("Server returned no response."))
                    return
                }
                continuation.resume(returning: (data, response))
            }
            task.resume()
        }
    }

    private func readChunk(fileURL: URL, offset: Int64, count: Int, chunkIndex: Int) throws -> Data {
        let handle = try FileHandle(forReadingFrom: fileURL)
        defer { try? handle.close() }
        try handle.seek(toOffset: UInt64(offset))
        let data = try handle.read(upToCount: count) ?? Data()
        if data.count != count {
            throw APIClientError.badResponse(
                "Could not read audio chunk \(chunkIndex + 1). Expected \(count) bytes, got \(data.count)."
            )
        }
        return data
    }

    private func fileSize(_ url: URL) throws -> Int64 {
        let values = try url.resourceValues(forKeys: [.fileSizeKey, .isUbiquitousItemKey, .ubiquitousItemDownloadingStatusKey])
        if values.isUbiquitousItem == true,
           values.ubiquitousItemDownloadingStatus != URLUbiquitousItemDownloadingStatus.current {
            throw APIClientError.badResponse("Recording file is still downloading from iCloud.")
        }
        return Int64(values.fileSize ?? 0)
    }

    private func mimeType(for url: URL) -> String {
        switch url.pathExtension.lowercased() {
        case "wav":
            return "audio/wav"
        case "mp3":
            return "audio/mpeg"
        case "aac":
            return "audio/aac"
        case "caf":
            return "audio/x-caf"
        default:
            return "audio/mp4"
        }
    }

    private func pollTranscript(recordingID: String, serverRecordingID: String, store: RecordingStore, client: APIClient) async throws {
        for _ in 0..<180 {
            try await Task.sleep(nanoseconds: 2_000_000_000)
            let status = try await client.status(recordingID: serverRecordingID)
            if let remote = status.recording {
                await MainActor.run {
                    store.update(recordingID) {
                        $0.transcriptProgress = remote.progress
                        $0.lastError = remote.error
                        if remote.transcriptionStatus == "ready" {
                            $0.transcriptStatus = .ready
                        } else if remote.transcriptionStatus == "failed" {
                            $0.transcriptStatus = .failed
                        } else {
                            $0.transcriptStatus = .transcribing
                        }
                    }
                }

                if remote.transcriptionStatus == "ready" {
                    let transcript = try await client.transcript(recordingID: serverRecordingID)
                    await MainActor.run {
                        store.update(recordingID) {
                            $0.transcriptStatus = .ready
                            $0.transcript = transcript.transcript ?? ""
                        }
                    }
                    return
                }

                if remote.transcriptionStatus == "failed" {
                    throw APIClientError.badResponse(remote.error.isEmpty ? "Transcription failed." : remote.error)
                }
            }
        }

        throw APIClientError.badResponse("Timed out waiting for transcript.")
    }
}
