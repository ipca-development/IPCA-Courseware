import Foundation

@MainActor
final class UploadManager: ObservableObject {
    @Published private(set) var activeUploads: Set<String> = []

    private lazy var session: URLSession = {
        let configuration = URLSessionConfiguration.default
        configuration.timeoutIntervalForRequest = 3600
        configuration.timeoutIntervalForResource = 24 * 3600
        configuration.waitsForConnectivity = true
        return URLSession(configuration: configuration)
    }()

    func upload(recordingID: String, store: RecordingStore, settings: SettingsStore) {
        guard !activeUploads.contains(recordingID) else { return }
        guard settings.normalizedServerURL != nil else {
            store.update(recordingID) {
                $0.uploadStatus = .failed
                $0.lastError = "Server URL is invalid."
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
        let boundary = "Boundary-\(UUID().uuidString)"
        let bodyURL = try client.makeMultipartBodyFile(for: recording, language: settings.language, boundary: boundary)
        defer { try? FileManager.default.removeItem(at: bodyURL) }

        let request = try client.uploadRequest(for: recording, language: settings.language, boundary: boundary)
        let (data, response) = try await upload(request: request, bodyURL: bodyURL, recordingID: recordingID, store: store)
        let uploadResponse = try client.decodeUploadResponse(data: data, response: response)
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
            }
        }

        try await pollTranscript(recordingID: recordingID, serverRecordingID: serverRecordingID, store: store, client: client)
    }

    private func upload(request: URLRequest, bodyURL: URL, recordingID: String, store: RecordingStore) async throws -> (Data, URLResponse) {
        try await withCheckedThrowingContinuation { continuation in
            var observation: NSKeyValueObservation?
            let task = session.uploadTask(with: request, fromFile: bodyURL) { data, response, error in
                observation?.invalidate()
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

            observation = task.progress.observe(\.fractionCompleted, options: [.new]) { progress, _ in
                Task { @MainActor in
                    store.update(recordingID) {
                        $0.uploadProgress = progress.fractionCompleted
                    }
                }
            }

            task.resume()
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
