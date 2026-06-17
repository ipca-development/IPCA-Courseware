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

    func uploadRequest(for recording: Recording, language: String, boundary: String) throws -> URLRequest {
        let url = serverURL.appending(path: "api/recordings/upload.php")
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.timeoutInterval = 3600
        request.setValue("multipart/form-data; boundary=\(boundary)", forHTTPHeaderField: "Content-Type")
        return request
    }

    func makeMultipartBodyFile(for recording: Recording, language: String, boundary: String) throws -> URL {
        let audioURL = recording.fileURL
        guard FileManager.default.fileExists(atPath: audioURL.path) else {
            throw APIClientError.missingRecordingFile
        }

        let bodyURL = FileManager.default.temporaryDirectory
            .appendingPathComponent("ipca-upload-\(recording.id).tmp")
        FileManager.default.createFile(atPath: bodyURL.path, contents: nil)
        let handle = try FileHandle(forWritingTo: bodyURL)
        defer { try? handle.close() }

        func write(_ string: String) throws {
            guard let data = string.data(using: .utf8) else { return }
            try handle.write(contentsOf: data)
        }

        let fields: [(String, String)] = [
            ("recording_id", recording.id),
            ("started_at", ISO8601DateFormatter().string(from: recording.startedAt)),
            ("duration", String(recording.duration)),
            ("input_device", recording.inputDeviceName),
            ("language", language)
        ]

        for field in fields {
            try write("--\(boundary)\r\n")
            try write("Content-Disposition: form-data; name=\"\(field.0)\"\r\n\r\n")
            try write("\(field.1)\r\n")
        }

        try write("--\(boundary)\r\n")
        try write("Content-Disposition: form-data; name=\"audio\"; filename=\"\(audioURL.lastPathComponent)\"\r\n")
        try write("Content-Type: audio/mp4\r\n\r\n")

        let audioHandle = try FileHandle(forReadingFrom: audioURL)
        defer { try? audioHandle.close() }
        while true {
            let data = try audioHandle.read(upToCount: 1024 * 1024) ?? Data()
            if data.isEmpty { break }
            try handle.write(contentsOf: data)
        }

        try write("\r\n--\(boundary)--\r\n")
        return bodyURL
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

    func decodeUploadResponse(data: Data, response: URLResponse) throws -> UploadResponse {
        try validate(response: response, data: data)
        return try decode(UploadResponse.self, from: data, response: response)
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
