import Foundation
import OSLog

struct UploadResumeStatus: Equatable, Sendable {
    let receivedChunks: Set<Int>
    let receivedByteCount: Int64

    static let empty = UploadResumeStatus(receivedChunks: [], receivedByteCount: 0)
}

struct ServerReceipt: Codable, Equatable, Sendable {
    let receiptUUID: String
    let objectID: String
    let sha256: String
    let byteCount: Int64
    let verified: Bool
    let duplicate: Bool

    enum CodingKeys: String, CodingKey {
        case receiptUUID = "receipt_uuid"
        case objectID = "object_id"
        case sha256
        case byteCount = "byte_count"
        case verified
        case duplicate
    }
}

protocol GarminUploadServing: Sendable {
    func resume(uploadID: UUID) async throws -> UploadResumeStatus
    func uploadChunk(
        uploadID: UUID,
        requestID: UUID,
        file: IngestionFile,
        chunkIndex: Int,
        totalChunks: Int,
        data: Data
    ) async throws -> UploadResumeStatus
    func finalize(uploadID: UUID) async throws -> ServerReceipt
}

struct GarminUploadService: GarminUploadServing {
    let baseURL: URL
    let bearerCredential: String
    var session: URLSession = .shared

    func resume(uploadID: UUID) async throws -> UploadResumeStatus {
        var components = URLComponents(
            url: try endpoint("/api/garmin-sync/upload_chunk.php"),
            resolvingAgainstBaseURL: true
        )!
        components.queryItems = [.init(name: "upload_uuid", value: uploadID.uuidString)]
        var request = authorizedRequest(url: components.url!, method: "GET")
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        let (data, response) = try await session.data(for: request)
        guard let http = response as? HTTPURLResponse else { throw GarminSyncError.invalidServerResponse }
        if http.statusCode == 404 {
            let error = try? JSONDecoder().decode(APIErrorEnvelope.self, from: data)
            if error?.errorCode == "UPLOAD_NOT_FOUND" { return .empty }
        }
        try validateHTTP(http, data: data)
        let payload = try decode(UploadResponse.self, data)
        return payload.resumeStatus
    }

    func uploadChunk(
        uploadID: UUID,
        requestID: UUID,
        file: IngestionFile,
        chunkIndex: Int,
        totalChunks: Int,
        data: Data
    ) async throws -> UploadResumeStatus {
        let boundary = "IPCA-\(UUID().uuidString)"
        let fields = [
            ("upload_uuid", uploadID.uuidString),
            ("request_uuid", requestID.uuidString),
            ("expected_sha256", file.sourceHash),
            ("expected_byte_count", String(file.sourceSize)),
            ("chunk_index", String(chunkIndex)),
            ("total_chunks", String(totalChunks)),
            ("original_filename", file.originalFilename)
        ]
        var body = Data()
        for (name, value) in fields {
            body.appendMultipart("--\(boundary)\r\n")
            body.appendMultipart("Content-Disposition: form-data; name=\"\(name)\"\r\n\r\n")
            body.appendMultipart("\(value)\r\n")
        }
        body.appendMultipart("--\(boundary)\r\n")
        body.appendMultipart(
            "Content-Disposition: form-data; name=\"chunk\"; filename=\"chunk-\(chunkIndex).bin\"\r\n"
        )
        body.appendMultipart("Content-Type: application/octet-stream\r\n\r\n")
        body.append(data)
        body.appendMultipart("\r\n--\(boundary)--\r\n")

        var request = authorizedRequest(
            url: try endpoint("/api/garmin-sync/upload_chunk.php"),
            method: "POST"
        )
        request.setValue("multipart/form-data; boundary=\(boundary)", forHTTPHeaderField: "Content-Type")
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.httpBody = body
        let payload: UploadResponse = try await perform(request)
        return payload.resumeStatus
    }

    func finalize(uploadID: UUID) async throws -> ServerReceipt {
        var request = authorizedRequest(
            url: try endpoint("/api/garmin-sync/finalize.php"),
            method: "POST"
        )
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.httpBody = try JSONEncoder().encode(["upload_uuid": uploadID.uuidString])
        let payload: FinalizeResponse = try await perform(request)
        guard let receipt = payload.receipt else { throw GarminSyncError.invalidServerResponse }
        return receipt
    }

    private func endpoint(_ path: String) throws -> URL {
        guard let url = URL(string: path, relativeTo: baseURL) else {
            throw GarminSyncError.invalidServerResponse
        }
        return url
    }

    private func authorizedRequest(url: URL, method: String) -> URLRequest {
        var request = URLRequest(url: url)
        request.httpMethod = method
        request.setValue("Bearer \(bearerCredential)", forHTTPHeaderField: "Authorization")
        return request
    }

    private func perform<T: Decodable>(_ request: URLRequest) async throws -> T {
        let (data, response) = try await session.data(for: request)
        guard let http = response as? HTTPURLResponse else { throw GarminSyncError.invalidServerResponse }
        try validateHTTP(http, data: data)
        return try decode(T.self, data)
    }

    private func validateHTTP(_ response: HTTPURLResponse, data: Data) throws {
        guard (200..<300).contains(response.statusCode) else {
            let envelope = try? JSONDecoder().decode(APIErrorEnvelope.self, from: data)
            throw GarminSyncError.serverRejected(
                envelope?.error ?? envelope?.errorCode ?? "HTTP \(response.statusCode)"
            )
        }
    }

    private func decode<T: Decodable>(_ type: T.Type, _ data: Data) throws -> T {
        do { return try JSONDecoder().decode(type, from: data) }
        catch { throw GarminSyncError.invalidServerResponse }
    }
}

private struct APIErrorEnvelope: Decodable {
    let error: String?
    let errorCode: String?

    enum CodingKeys: String, CodingKey {
        case error
        case errorCode = "error_code"
    }
}

private struct UploadResponse: Decodable {
    let receivedChunks: [Int]?
    let receivedByteCount: Int64?

    enum CodingKeys: String, CodingKey {
        case receivedChunks = "received_chunks"
        case receivedByteCount = "received_byte_count"
    }

    var resumeStatus: UploadResumeStatus {
        .init(
            receivedChunks: Set(receivedChunks ?? []),
            receivedByteCount: receivedByteCount ?? 0
        )
    }
}

private struct FinalizeResponse: Decodable {
    let receipt: ServerReceipt?
}

private extension Data {
    mutating func appendMultipart(_ string: String) {
        append(Data(string.utf8))
    }
}

enum SyncStateMachine {
    static func allows(_ from: IngestionState, _ to: IngestionState) -> Bool {
        switch (from, to) {
        case (.discovered, .copying),
             (.copying, .localVerified),
             (.copying, .failed),
             (.failed, .copying),
             (.localVerified, .waitingForUpload),
             (.waitingForUpload, .uploading),
             (.uploading, .waitingForUpload),
             (.uploading, .serverVerified),
             (.uploading, .failed),
             (.failed, .waitingForUpload):
            true
        default:
            from == to
        }
    }
}

struct UploadQueue {
    static let chunkSize = 1024 * 1024

    private let store: LocalIngestionStore
    private let service: any GarminUploadServing
    private let logger = Logger(subsystem: "com.ipca.garmin-sync", category: "upload")

    init(store: LocalIngestionStore, service: any GarminUploadServing) {
        self.store = store
        self.service = service
    }

    func enqueueVerifiedFiles() async throws {
        for file in try await store.files(in: [.localVerified]) {
            try await transition(file, to: .waitingForUpload)
        }
    }

    func run(progress: @escaping @Sendable (UploadProgress) -> Void) async throws {
        try await enqueueVerifiedFiles()
        let files = try await store.files(in: [.waitingForUpload, .uploading])
        var verifiedFiles = 0
        for file in files {
            try await upload(
                file,
                verifiedFiles: verifiedFiles,
                totalFiles: files.count,
                progress: progress
            )
            verifiedFiles += 1
            progress(.init(
                verifiedFiles: verifiedFiles,
                totalFiles: files.count,
                currentFilename: file.originalFilename,
                uploadedChunks: totalChunks(for: file),
                totalChunks: totalChunks(for: file)
            ))
        }
    }

    private func upload(
        _ file: IngestionFile,
        verifiedFiles: Int,
        totalFiles: Int,
        progress: @escaping @Sendable (UploadProgress) -> Void
    ) async throws {
        guard let localPath = file.localPath else {
            throw GarminSyncError.verificationFailed(file.relativePath)
        }
        try await transition(file, to: .uploading)
        do {
            var remote = try await service.resume(uploadID: file.uploadID)
            let chunkCount = totalChunks(for: file)
            let handle = try FileHandle(forReadingFrom: URL(fileURLWithPath: localPath))
            defer { try? handle.close() }

            for index in 0..<chunkCount where !remote.receivedChunks.contains(index) {
                try handle.seek(toOffset: UInt64(index * Self.chunkSize))
                guard let chunk = try handle.read(upToCount: Self.chunkSize), !chunk.isEmpty else {
                    throw GarminSyncError.verificationFailed(file.relativePath)
                }
                remote = try await service.uploadChunk(
                    uploadID: file.uploadID,
                    requestID: file.uploadID,
                    file: file,
                    chunkIndex: index,
                    totalChunks: chunkCount,
                    data: chunk
                )
                guard remote.receivedChunks.contains(index) else {
                    throw GarminSyncError.serverRejected("Chunk \(index) was not acknowledged")
                }
                try await store.updateState(
                    id: file.id,
                    state: .uploading,
                    uploadedBytes: remote.receivedByteCount
                )
                progress(.init(
                    verifiedFiles: verifiedFiles,
                    totalFiles: totalFiles,
                    currentFilename: file.originalFilename,
                    uploadedChunks: remote.receivedChunks.count,
                    totalChunks: chunkCount
                ))
            }
            guard remote.receivedChunks == Set(0..<chunkCount) else {
                throw GarminSyncError.serverRejected("Upload session is missing chunks")
            }
            let receipt = try await service.finalize(uploadID: file.uploadID)
            try await verifyAndComplete(receipt, file: file)
        } catch {
            try? await store.recordUploadFailure(id: file.id, error: error.localizedDescription)
            logger.error(
                "Upload failed for file \(file.id.uuidString, privacy: .public): \(error.localizedDescription, privacy: .public)"
            )
            throw error
        }
    }

    private func totalChunks(for file: IngestionFile) -> Int {
        max(1, Int((file.sourceSize + Int64(Self.chunkSize) - 1) / Int64(Self.chunkSize)))
    }

    private func verifyAndComplete(_ receipt: ServerReceipt, file: IngestionFile) async throws {
        guard receipt.verified,
              receipt.sha256 == file.sourceHash,
              receipt.byteCount == file.sourceSize,
              !receipt.objectID.isEmpty,
              !receipt.receiptUUID.isEmpty else {
            try? await store.markServerVerificationRejected(id: file.id)
            throw GarminSyncError.serverRejected("Final receipt hash, size, or verification mismatch")
        }
        let receiptJSON = String(
            decoding: try JSONEncoder().encode(receipt),
            as: UTF8.self
        )
        try await store.updateState(
            id: file.id,
            state: .serverVerified,
            uploadedBytes: file.sourceSize,
            serverObjectID: receipt.objectID,
            serverReceiptUUID: receipt.receiptUUID,
            serverReceiptJSON: receiptJSON
        )
        logger.info("Server verified upload \(file.uploadID.uuidString, privacy: .public)")
    }

    private func transition(_ file: IngestionFile, to state: IngestionState) async throws {
        guard SyncStateMachine.allows(file.state, state) else {
            throw NSError(
                domain: "GarminSync.State",
                code: 1,
                userInfo: [NSLocalizedDescriptionKey: "Invalid transition \(file.state.rawValue) → \(state.rawValue)"]
            )
        }
        try await store.updateState(id: file.id, state: state)
    }
}
