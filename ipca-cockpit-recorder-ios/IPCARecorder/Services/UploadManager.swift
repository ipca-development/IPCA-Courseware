import Combine
import Foundation

@MainActor
final class UploadManager: ObservableObject {
    @Published private(set) var activeUploads: Set<String> = []
    private let chunkSize = 512 * 1024
    private let maxChunkAttempts = 5

    private lazy var session: URLSession = {
        let configuration = URLSessionConfiguration.default
        configuration.timeoutIntervalForRequest = 3600
        configuration.timeoutIntervalForResource = 24 * 3600
        configuration.waitsForConnectivity = true
        return URLSession(configuration: configuration)
    }()

    func upload(recordingID: String, store: RecordingStore, settings: SettingsStore) {
        guard !activeUploads.contains(recordingID) else { return }
        guard settings.isServerURLConfigured else {
            store.update(recordingID) {
                $0.uploadStatus = .failed
                $0.lastError = "Server URL is not configured. Open Settings and enter the IPCA Courseware site URL, for example https://your-domain.com."
            }
            return
        }

        activeUploads.insert(recordingID)
        store.update(recordingID) {
            $0.uploadStatus = .uploading
            $0.uploadProgress = 0
            $0.lastError = ""
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
        var files: [(type: String, url: URL, filename: String, mime: String, size: Int64)] = [
            ("audio", recording.fileURL, recording.fileURL.lastPathComponent, "audio/mp4", try fileSize(recording.fileURL))
        ]

        if let ahrsPath = recording.ahrsSamplesPath {
            let url = URL(fileURLWithPath: ahrsPath)
            if FileManager.default.fileExists(atPath: url.path) {
                files.append(("ahrs", url, url.lastPathComponent, "application/json", try fileSize(url)))
            }
        }

        if let gpsPath = recording.gpsSamplesPath {
            let url = URL(fileURLWithPath: gpsPath)
            if FileManager.default.fileExists(atPath: url.path) {
                files.append(("gps", url, url.lastPathComponent, "application/json", try fileSize(url)))
            }
        }

        let totalBytes = max(1, files.reduce(Int64(0)) { $0 + max(0, $1.size) })
        var uploadedBytes: Int64 = 0

        for file in files where file.size > 0 {
            uploadedBytes = try await uploadFileChunks(
                recording: recording,
                client: client,
                fileType: file.type,
                fileURL: file.url,
                originalFilename: file.filename,
                mimeType: file.mime,
                fileSize: file.size,
                uploadedBytes: uploadedBytes,
                totalBytes: totalBytes,
                store: store
            )
        }

        await MainActor.run {
            store.update(recording.id) {
                $0.uploadProgress = 0.99
                $0.lastError = "Finalizing upload package..."
            }
        }

        let finalizeRequest = try client.finalizeChunkedUploadRequest(for: recording, language: language)
        let (data, response) = try await data(for: finalizeRequest)
        return try client.decodeUploadResponse(data: data, response: response)
    }

    private func uploadFileChunks(
        recording: Recording,
        client: APIClient,
        fileType: String,
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

        for chunkIndex in 0..<totalChunks {
            let offset = Int64(chunkIndex * chunkSize)
            let count = min(chunkSize, Int(fileSize - offset))
            let chunkURL = try makeChunkFile(fileURL: fileURL, offset: offset, count: count, fileType: fileType, chunkIndex: chunkIndex)
            defer { try? FileManager.default.removeItem(at: chunkURL) }

            let request = client.chunkUploadRequest(
                recording: recording,
                fileType: fileType,
                chunkIndex: chunkIndex,
                totalChunks: totalChunks,
                totalSize: fileSize,
                originalFilename: originalFilename,
                mimeType: mimeType
            )

            var lastError: Error?
            for attempt in 1...maxChunkAttempts {
                do {
                    let (data, response) = try await upload(request: request, bodyURL: chunkURL)
                    let chunkResponse = try client.decodeChunkUploadResponse(data: data, response: response)
                    if !chunkResponse.ok {
                        throw APIClientError.badResponse(chunkResponse.error ?? "Chunk upload failed.")
                    }
                    lastError = nil
                    break
                } catch {
                    lastError = error
                    if attempt < maxChunkAttempts {
                        await MainActor.run {
                            store.update(recording.id) {
                                $0.lastError = "Retrying \(fileType) chunk \(chunkIndex + 1)/\(totalChunks)..."
                            }
                        }
                        try await Task.sleep(nanoseconds: UInt64(attempt) * 1_000_000_000)
                    }
                }
            }

            if let lastError {
                throw lastError
            }

            completedBytes += Int64(count)
            await MainActor.run {
                store.update(recording.id) {
                    $0.uploadProgress = min(0.98, Double(completedBytes) / Double(totalBytes) * 0.98)
                    $0.lastError = "Uploaded \(fileType) chunk \(chunkIndex + 1)/\(totalChunks)"
                }
            }
        }

        return completedBytes
    }

    private func upload(request: URLRequest, bodyURL: URL) async throws -> (Data, URLResponse) {
        try await withCheckedThrowingContinuation { continuation in
            let task = session.uploadTask(with: request, fromFile: bodyURL) { data, response, error in
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

    private func makeChunkFile(fileURL: URL, offset: Int64, count: Int, fileType: String, chunkIndex: Int) throws -> URL {
        let handle = try FileHandle(forReadingFrom: fileURL)
        defer { try? handle.close() }
        try handle.seek(toOffset: UInt64(offset))
        let data = try handle.read(upToCount: count) ?? Data()
        if data.isEmpty {
            throw APIClientError.badResponse("Could not read \(fileType) chunk \(chunkIndex + 1).")
        }
        let chunkURL = FileManager.default.temporaryDirectory
            .appendingPathComponent("ipca-\(fileType)-\(UUID().uuidString)-\(chunkIndex).part")
        try data.write(to: chunkURL, options: .atomic)
        return chunkURL
    }

    private func fileSize(_ url: URL) throws -> Int64 {
        let values = try url.resourceValues(forKeys: [.fileSizeKey])
        return Int64(values.fileSize ?? 0)
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
