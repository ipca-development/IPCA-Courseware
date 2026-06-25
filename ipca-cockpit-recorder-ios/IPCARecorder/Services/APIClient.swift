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

    enum CodingKeys: String, CodingKey {
        case ok
        case error
        case fileType = "file_type"
        case chunkIndex = "chunk_index"
        case totalChunks = "total_chunks"
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

    func uploadRequest(for recording: Recording, language: String, boundary: String) throws -> URLRequest {
        let url = serverURL.appending(path: "api/recordings/upload.php")
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.timeoutInterval = 3600
        request.setValue("multipart/form-data; boundary=\(boundary)", forHTTPHeaderField: "Content-Type")
        return request
    }

    func chunkUploadRequest(
        recording: Recording,
        fileType: String,
        chunkIndex: Int,
        totalChunks: Int,
        totalSize: Int64,
        originalFilename: String,
        mimeType: String
    ) -> URLRequest {
        let url = serverURL.appending(path: "api/recordings/upload_chunk.php")
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.timeoutInterval = 300
        request.setValue("application/octet-stream", forHTTPHeaderField: "Content-Type")
        request.setValue(recording.id, forHTTPHeaderField: "X-IPCA-Recording-ID")
        request.setValue(fileType, forHTTPHeaderField: "X-IPCA-File-Type")
        request.setValue(String(chunkIndex), forHTTPHeaderField: "X-IPCA-Chunk-Index")
        request.setValue(String(totalChunks), forHTTPHeaderField: "X-IPCA-Total-Chunks")
        request.setValue(String(totalSize), forHTTPHeaderField: "X-IPCA-Total-Size")
        request.setValue(originalFilename, forHTTPHeaderField: "X-IPCA-Original-Filename")
        request.setValue(mimeType, forHTTPHeaderField: "X-IPCA-Mime-Type")
        return request
    }

    func finalizeChunkedUploadRequest(for recording: Recording, language: String) throws -> URLRequest {
        let url = serverURL.appending(path: "api/recordings/upload_finalize.php")
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.timeoutInterval = 3600
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        var payload: [String: Any] = [
            "recording_id": recording.id,
            "started_at": ISO8601DateFormatter().string(from: recording.startedAt),
            "duration": recording.duration,
            "input_device": recording.inputDeviceName,
            "aircraft_id": recording.aircraftID ?? 0,
            "language": language
        ]
        if let altimeter = recording.altimeterSettingInHg {
            payload["altimeter_setting_inhg"] = altimeter
            payload["altimeter_setting_source"] = "app_logged"
        }
        if let elevation = recording.airportElevationFt {
            payload["airport_elevation_ft"] = elevation
            payload["airport_elevation_source"] = "app_logged"
        }
        if let oat = recording.oatC {
            payload["oat_c"] = oat
            payload["oat_source"] = "app_logged"
        }
        request.httpBody = try JSONSerialization.data(withJSONObject: payload)
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

        var fields: [(String, String)] = [
            ("recording_id", recording.id),
            ("started_at", ISO8601DateFormatter().string(from: recording.startedAt)),
            ("duration", String(recording.duration)),
            ("input_device", recording.inputDeviceName),
            ("aircraft_id", recording.aircraftID.map(String.init) ?? ""),
            ("language", language)
        ]
        if let altimeter = recording.altimeterSettingInHg {
            fields.append(("altimeter_setting_inhg", String(altimeter)))
            fields.append(("altimeter_setting_source", "app_logged"))
        }
        if let elevation = recording.airportElevationFt {
            fields.append(("airport_elevation_ft", String(elevation)))
            fields.append(("airport_elevation_source", "app_logged"))
        }
        if let oat = recording.oatC {
            fields.append(("oat_c", String(oat)))
            fields.append(("oat_source", "app_logged"))
        }

        for field in fields {
            try write("--\(boundary)\r\n")
            try write("Content-Disposition: form-data; name=\"\(field.0)\"\r\n\r\n")
            try write("\(field.1)\r\n")
        }

        try appendFile(
            fieldName: "audio",
            fileURL: audioURL,
            contentType: "audio/mp4",
            boundary: boundary,
            handle: handle,
            write: write
        )

        if let ahrsPath = recording.ahrsSamplesPath {
            let ahrsURL = URL(fileURLWithPath: ahrsPath)
            if FileManager.default.fileExists(atPath: ahrsURL.path) {
                try appendFile(
                    fieldName: "ahrs",
                    fileURL: ahrsURL,
                    contentType: "application/json",
                    boundary: boundary,
                    handle: handle,
                    write: write
                )
            }
        }

        if let gpsPath = recording.gpsSamplesPath {
            let gpsURL = URL(fileURLWithPath: gpsPath)
            if FileManager.default.fileExists(atPath: gpsURL.path) {
                try appendFile(
                    fieldName: "gps",
                    fileURL: gpsURL,
                    contentType: "application/json",
                    boundary: boundary,
                    handle: handle,
                    write: write
                )
            }
        }

        try write("--\(boundary)--\r\n")
        return bodyURL
    }

    private func appendFile(
        fieldName: String,
        fileURL: URL,
        contentType: String,
        boundary: String,
        handle: FileHandle,
        write: (String) throws -> Void
    ) throws {
        try write("--\(boundary)\r\n")
        try write("Content-Disposition: form-data; name=\"\(fieldName)\"; filename=\"\(fileURL.lastPathComponent)\"\r\n")
        try write("Content-Type: \(contentType)\r\n\r\n")

        let fileHandle = try FileHandle(forReadingFrom: fileURL)
        defer { try? fileHandle.close() }
        while true {
            let data = try fileHandle.read(upToCount: 1024 * 1024) ?? Data()
            if data.isEmpty { break }
            try handle.write(contentsOf: data)
        }
        try write("\r\n")
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
