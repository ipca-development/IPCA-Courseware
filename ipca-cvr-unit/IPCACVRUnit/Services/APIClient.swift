import Foundation

struct APIRecording: Codable {
    var id: Int
    var recordingID: String
    var uploadStatus: String
    var transcriptionStatus: String
    var progress: Int
    var error: String

    enum CodingKeys: String, CodingKey {
        case id
        case recordingID = "recording_id"
        case uploadStatus = "upload_status"
        case transcriptionStatus = "transcription_status"
        case progress
        case error
    }
}

struct UploadResponse: Codable {
    var ok: Bool
    var recording: APIRecording?
    var error: String?
}

struct ChunkUploadResponse: Codable {
    var ok: Bool
    var error: String?
    var fileType: String?
    var chunkIndex: Int?
    var totalChunks: Int?
    var alreadyPresent: Bool?

    enum CodingKeys: String, CodingKey {
        case ok
        case error
        case fileType = "file_type"
        case chunkIndex = "chunk_index"
        case totalChunks = "total_chunks"
        case alreadyPresent = "already_present"
    }
}

struct ChunkUploadStatusResponse: Codable {
    var ok: Bool
    var error: String?
    var fileType: String?
    var receivedChunks: [Int]?
    var receivedCount: Int?
    var totalChunks: Int?
    var totalSize: Int?

    enum CodingKeys: String, CodingKey {
        case ok
        case error
        case fileType = "file_type"
        case receivedChunks = "received_chunks"
        case receivedCount = "received_count"
        case totalChunks = "total_chunks"
        case totalSize = "total_size"
    }
}

struct StatusResponse: Codable {
    var ok: Bool
    var recording: APIRecording?
    var error: String?
}

struct TranscriptResponse: Codable {
    var ok: Bool
    var recordingID: String?
    var transcriptionStatus: String?
    var language: String?
    var transcript: String?
    var error: String?

    enum CodingKeys: String, CodingKey {
        case ok
        case recordingID = "recording_id"
        case transcriptionStatus = "transcription_status"
        case language
        case transcript
        case error
    }
}

struct AircraftListResponse: Codable {
    var ok: Bool
    var aircraft: [CockpitAircraft]
    var error: String?
}

enum APIClientError: LocalizedError {
    case invalidServerURL
    case badResponse(String)
    case invalidJSON(String)
    case missingRecordingFile

    var errorDescription: String? {
        switch self {
        case .invalidServerURL: "Server URL is invalid."
        case .badResponse(let message): message
        case .invalidJSON(let message): message
        case .missingRecordingFile: "Recording file is missing."
        }
    }
}

struct APIClient {
    var serverURL: URL

    func chunkUploadRequest(
        recording: Recording,
        fileType: String,
        chunkIndex: Int,
        totalChunks: Int,
        totalSize: Int64,
        chunkSize: Int,
        originalFilename: String,
        mimeType: String
    ) -> URLRequest {
        let url = serverURL.appending(path: "api/recordings/upload_chunk.php")
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.timeoutInterval = 120
        request.setValue("application/octet-stream", forHTTPHeaderField: "Content-Type")
        request.setValue(recording.id, forHTTPHeaderField: "X-IPCA-Recording-ID")
        request.setValue(fileType, forHTTPHeaderField: "X-IPCA-File-Type")
        request.setValue(String(chunkIndex), forHTTPHeaderField: "X-IPCA-Chunk-Index")
        request.setValue(String(totalChunks), forHTTPHeaderField: "X-IPCA-Total-Chunks")
        request.setValue(String(totalSize), forHTTPHeaderField: "X-IPCA-Total-Size")
        request.setValue(String(chunkSize), forHTTPHeaderField: "X-IPCA-Chunk-Size")
        request.setValue(originalFilename, forHTTPHeaderField: "X-IPCA-Original-Filename")
        request.setValue(mimeType, forHTTPHeaderField: "X-IPCA-Mime-Type")
        return request
    }

    func chunkUploadStatus(recordingID: String, fileType: String) async throws -> ChunkUploadStatusResponse {
        let url = try endpoint("api/recordings/upload_chunk.php", queryItems: [
            URLQueryItem(name: "recording_id", value: recordingID),
            URLQueryItem(name: "file_type", value: fileType),
        ])
        var request = URLRequest(url: url)
        request.httpMethod = "GET"
        request.timeoutInterval = 60
        let (data, response) = try await URLSession.shared.data(for: request)
        try validate(response: response, data: data)
        return try decode(ChunkUploadStatusResponse.self, from: data, response: response)
    }

    func finalizeChunkedUploadRequest(for recording: Recording, language: String) throws -> URLRequest {
        let url = serverURL.appending(path: "api/recordings/upload_finalize.php")
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.timeoutInterval = 3600
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.httpBody = try JSONSerialization.data(withJSONObject: finalizePayload(for: recording, language: language))
        return request
    }

    func status(recordingID: String) async throws -> StatusResponse {
        let url = try endpoint("api/recordings/status.php", queryItems: [
            URLQueryItem(name: "id", value: recordingID)
        ])
        let (data, response) = try await URLSession.shared.data(from: url)
        try validate(response: response, data: data)
        return try decode(StatusResponse.self, from: data, response: response)
    }

    func transcript(recordingID: String) async throws -> TranscriptResponse {
        let url = try endpoint("api/recordings/transcript.php", queryItems: [
            URLQueryItem(name: "id", value: recordingID)
        ])
        let (data, response) = try await URLSession.shared.data(from: url)
        try validate(response: response, data: data)
        return try decode(TranscriptResponse.self, from: data, response: response)
    }

    func aircraft() async throws -> AircraftListResponse {
        let url = serverURL.appending(path: "api/recordings/aircraft.php")
        let (data, response) = try await URLSession.shared.data(from: url)
        try validate(response: response, data: data)
        return try decode(AircraftListResponse.self, from: data, response: response)
    }

    func decodeUploadResponse(data: Data, response: URLResponse) throws -> UploadResponse {
        try validate(response: response, data: data)
        return try decode(UploadResponse.self, from: data, response: response)
    }

    func decodeChunkUploadResponse(data: Data, response: URLResponse) throws -> ChunkUploadResponse {
        try validate(response: response, data: data)
        return try decode(ChunkUploadResponse.self, from: data, response: response)
    }

    private func finalizePayload(for recording: Recording, language: String) -> [String: Any] {
        [
            "recording_id": recording.id,
            "started_at": ISO8601DateFormatter().string(from: recording.startedAt),
            "duration": recording.duration,
            "input_device": recording.inputDeviceName,
            "aircraft_id": recording.aircraftID ?? 0,
            "import_profile": "audio_only",
            "language": language,
            "flight_session_uid": recording.flightSessionID,
            "flight_segment_index": recording.segmentIndex,
            "previous_segment_uid": recording.previousSegmentID ?? "",
            "is_test_recording": recording.isTestRecording ? 1 : 0,
            "source_gap_summary": recording.sourceGapSummary ?? ""
        ]
    }

    private func validate(response: URLResponse, data: Data) throws {
        guard let http = response as? HTTPURLResponse else { return }
        if http.statusCode >= 400 {
            throw APIClientError.badResponse("HTTP \(http.statusCode): \(responsePreview(data))")
        }
    }

    private func decode<T: Decodable>(_ type: T.Type, from data: Data, response: URLResponse) throws -> T {
        do {
            return try JSONDecoder().decode(type, from: data)
        } catch {
            let url = (response as? HTTPURLResponse)?.url?.absoluteString ?? "unknown URL"
            throw APIClientError.invalidJSON("Server did not return valid JSON from \(url). Response: \(responsePreview(data))")
        }
    }

    private func endpoint(_ path: String, queryItems: [URLQueryItem]) throws -> URL {
        var components = URLComponents(url: serverURL.appending(path: path), resolvingAgainstBaseURL: false)
        components?.queryItems = queryItems
        guard let url = components?.url else {
            throw APIClientError.invalidServerURL
        }
        return url
    }

    private func responsePreview(_ data: Data) -> String {
        let text = String(data: data, encoding: .utf8) ?? "\(data.count) bytes"
        let compact = text
            .replacingOccurrences(of: "\n", with: " ")
            .replacingOccurrences(of: "\r", with: " ")
            .trimmingCharacters(in: .whitespacesAndNewlines)
        if compact.count > 500 {
            return String(compact.prefix(500)) + "..."
        }
        return compact.isEmpty ? "empty response" : compact
    }
}
