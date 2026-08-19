import XCTest
@testable import IPCAGarminSync

private actor FixtureUploadServer: GarminUploadServing {
    enum Mode { case interrupt, success, wrongHash }
    let mode: Mode
    private var receivedChunks: Set<Int>
    private var expectedHash = ""
    private var expectedSize: Int64 = 0
    private var uploadedIndexes: [Int] = []

    init(mode: Mode, receivedChunks: Set<Int> = []) {
        self.mode = mode
        self.receivedChunks = receivedChunks
    }

    func resume(uploadID: UUID) async throws -> UploadResumeStatus {
        UploadResumeStatus(receivedChunks: receivedChunks, receivedByteCount: 0)
    }

    func uploadChunk(
        uploadID: UUID,
        requestID: UUID,
        file: IngestionFile,
        chunkIndex: Int,
        totalChunks: Int,
        data: Data
    ) async throws -> UploadResumeStatus {
        expectedHash = file.sourceHash
        expectedSize = file.sourceSize
        if mode == .interrupt { throw URLError(.networkConnectionLost) }
        uploadedIndexes.append(chunkIndex)
        receivedChunks.insert(chunkIndex)
        return UploadResumeStatus(
            receivedChunks: receivedChunks,
            receivedByteCount: min(file.sourceSize, Int64(receivedChunks.count * UploadQueue.chunkSize))
        )
    }

    func finalize(uploadID: UUID) async throws -> ServerReceipt {
        ServerReceipt(
            receiptUUID: "receipt-uuid",
            objectID: "object-uuid",
            sha256: mode == .wrongHash ? String(repeating: "0", count: 64) : expectedHash,
            byteCount: expectedSize,
            verified: true,
            duplicate: false
        )
    }

    func indexes() -> [Int] { uploadedIndexes }
}

private final class GarminURLProtocol: URLProtocol {
    static var handler: ((URLRequest) throws -> (HTTPURLResponse, Data))?

    override class func canInit(with request: URLRequest) -> Bool { true }
    override class func canonicalRequest(for request: URLRequest) -> URLRequest { request }

    override func startLoading() {
        do {
            guard let handler = Self.handler else { throw URLError(.badServerResponse) }
            let (response, data) = try handler(request)
            client?.urlProtocol(self, didReceive: response, cacheStoragePolicy: .notAllowed)
            client?.urlProtocol(self, didLoad: data)
            client?.urlProtocolDidFinishLoading(self)
        } catch {
            client?.urlProtocol(self, didFailWithError: error)
        }
    }

    override func stopLoading() {}
}

final class GarminSyncUploadTests: XCTestCase {
    override func tearDown() {
        GarminURLProtocol.handler = nil
        super.tearDown()
    }

    private func localVerifiedFixture(size: Int = 2_500_000) async throws -> (URL, LocalIngestionStore, IngestionFile) {
        let root = FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString)
        try FileManager.default.createDirectory(at: root, withIntermediateDirectories: true)
        let local = root.appendingPathComponent("fixture.csv")
        try Data(repeating: 0x5A, count: size).write(to: local)
        let hash = try FileHashService().sha256(url: local)
        let store = try LocalIngestionStore(databaseURL: root.appendingPathComponent("ledger.sqlite"))
        var file = try await store.discover(
            ScannedFile(relativePath: "GARMIN/fixture.csv", size: Int64(size), modificationDate: Date()),
            sourceHash: hash
        )
        try await store.updateState(
            id: file.id,
            state: .localVerified,
            localPath: local.path,
            localSize: file.sourceSize,
            destinationHash: hash
        )
        file = try await store.allFiles().first!
        return (root, store, file)
    }

    func testInterruptedUploadPreservesStableIDAndQueueResumesIdempotently() async throws {
        let (root, store, original) = try await localVerifiedFixture()
        defer { try? FileManager.default.removeItem(at: root) }
        let interrupted = UploadQueue(store: store, service: FixtureUploadServer(mode: .interrupt))

        do {
            try await interrupted.run { _ in }
            XCTFail("Expected interruption")
        } catch {
            let interruptedFile = try await store.allFiles().first
            XCTAssertEqual(interruptedFile?.state, .waitingForUpload)
            XCTAssertEqual(interruptedFile?.retryCount, 1)
        }
        let queued = try await store.allFiles().first
        XCTAssertEqual(queued?.uploadID, original.uploadID)

        let resumed = UploadQueue(store: store, service: FixtureUploadServer(mode: .success))
        try await resumed.run { _ in }
        let completed = try await store.allFiles().first!
        XCTAssertEqual(completed.uploadID, original.uploadID)
        XCTAssertEqual(completed.state, .serverVerified)
        XCTAssertEqual(completed.serverObjectID, "object-uuid")
        XCTAssertEqual(completed.serverReceiptUUID, "receipt-uuid")
        XCTAssertTrue(completed.serverReceiptJSON?.contains("receipt-uuid") == true)
        XCTAssertEqual(completed.serverVerificationStatus, "VERIFIED")
        XCTAssertTrue(FileManager.default.fileExists(atPath: completed.localPath!))

        try await resumed.run { _ in }
        let idempotent = try await store.allFiles().first
        XCTAssertEqual(idempotent?.state, .serverVerified)
    }

    func testNoncontiguousChunkResumeUploadsOnlyMissingIndex() async throws {
        let (root, store, _) = try await localVerifiedFixture()
        defer { try? FileManager.default.removeItem(at: root) }
        let server = FixtureUploadServer(mode: .success, receivedChunks: [0, 2])
        try await UploadQueue(store: store, service: server).run { _ in }

        let indexes = await server.indexes()
        let completed = try await store.allFiles().first
        XCTAssertEqual(indexes, [1])
        XCTAssertEqual(completed?.state, .serverVerified)
    }

    func testWrongServerHashIsRejectedAndNeverMarkedVerified() async throws {
        let (root, store, _) = try await localVerifiedFixture()
        defer { try? FileManager.default.removeItem(at: root) }
        let queue = UploadQueue(store: store, service: FixtureUploadServer(mode: .wrongHash))

        do {
            try await queue.run { _ in }
            XCTFail("Expected hash rejection")
        } catch {
            XCTAssertTrue(error.localizedDescription.contains("rejected"))
        }
        let rejected = try await store.allFiles().first
        XCTAssertEqual(rejected?.state, .waitingForUpload)
        XCTAssertEqual(rejected?.serverVerificationStatus, "REJECTED")
        XCTAssertNil(rejected?.serverObjectID)
        XCTAssertEqual(rejected?.retryCount, 1)
    }

    func testURLProtocolUsesDocumentedPathsAndMultipartContract() async throws {
        let (root, _, file) = try await localVerifiedFixture(size: 32)
        defer { try? FileManager.default.removeItem(at: root) }
        let configuration = URLSessionConfiguration.ephemeral
        configuration.protocolClasses = [GarminURLProtocol.self]
        let session = URLSession(configuration: configuration)
        let uploadID = file.uploadID
        var requestNumber = 0

        GarminURLProtocol.handler = { request in
            requestNumber += 1
            XCTAssertEqual(request.value(forHTTPHeaderField: "Authorization"), "Bearer secret-token")
            let url = try XCTUnwrap(request.url)
            switch requestNumber {
            case 1:
                XCTAssertEqual(request.httpMethod, "GET")
                XCTAssertEqual(url.path, "/api/garmin-sync/upload_chunk.php")
                XCTAssertEqual(URLComponents(url: url, resolvingAgainstBaseURL: false)?.queryItems?.first?.name, "upload_uuid")
                return Self.response(
                    url: url,
                    status: 404,
                    json: #"{"ok":false,"error":"No upload.","error_code":"UPLOAD_NOT_FOUND","retryable":false}"#
                )
            case 2:
                XCTAssertEqual(request.httpMethod, "POST")
                XCTAssertEqual(url.path, "/api/garmin-sync/upload_chunk.php")
                XCTAssertTrue(request.value(forHTTPHeaderField: "Content-Type")?.hasPrefix("multipart/form-data; boundary=") == true)
                let body = String(decoding: try Self.bodyData(request), as: UTF8.self)
                for field in [
                    "name=\"chunk\"", "name=\"upload_uuid\"", "name=\"request_uuid\"",
                    "name=\"expected_sha256\"", "name=\"expected_byte_count\"",
                    "name=\"chunk_index\"", "name=\"total_chunks\"", "name=\"original_filename\""
                ] {
                    XCTAssertTrue(body.contains(field), "Missing multipart field \(field)")
                }
                XCTAssertTrue(body.contains(uploadID.uuidString))
                XCTAssertTrue(body.contains(file.sourceHash))
                XCTAssertTrue(body.contains(file.originalFilename))
                return Self.response(
                    url: url,
                    json: #"{"ok":true,"received_chunks":[0],"received_byte_count":32}"#
                )
            default:
                XCTAssertEqual(request.httpMethod, "POST")
                XCTAssertEqual(url.path, "/api/garmin-sync/finalize.php")
                let json = try JSONSerialization.jsonObject(with: try Self.bodyData(request)) as? [String: String]
                XCTAssertEqual(json?["upload_uuid"], uploadID.uuidString)
                return Self.response(
                    url: url,
                    json: """
                    {"ok":true,"status":"verified","receipt":{
                      "receipt_uuid":"receipt-http","object_id":"object-http",
                      "sha256":"\(file.sourceHash)","byte_count":32,
                      "verified":true,"duplicate":false
                    }}
                    """
                )
            }
        }

        let service = GarminUploadService(
            baseURL: URL(string: "https://example.test/base/")!,
            bearerCredential: "secret-token",
            session: session
        )
        let emptyResume = try await service.resume(uploadID: uploadID)
        XCTAssertEqual(emptyResume, .empty)
        let chunk = try await service.uploadChunk(
            uploadID: uploadID,
            requestID: UUID(),
            file: file,
            chunkIndex: 0,
            totalChunks: 1,
            data: Data(repeating: 0x5A, count: 32)
        )
        XCTAssertEqual(chunk.receivedChunks, Set([0]))
        let receipt = try await service.finalize(uploadID: uploadID)
        XCTAssertEqual(receipt.objectID, "object-http")
        XCTAssertEqual(receipt.receiptUUID, "receipt-http")
    }

    func testStateMachineRejectsUnsafeTransitions() {
        XCTAssertTrue(SyncStateMachine.allows(.copying, .localVerified))
        XCTAssertTrue(SyncStateMachine.allows(.uploading, .serverVerified))
        XCTAssertFalse(SyncStateMachine.allows(.discovered, .serverVerified))
        XCTAssertFalse(SyncStateMachine.allows(.serverVerified, .uploading))
    }

    private static func response(
        url: URL,
        status: Int = 200,
        json: String
    ) -> (HTTPURLResponse, Data) {
        (
            HTTPURLResponse(
                url: url,
                statusCode: status,
                httpVersion: nil,
                headerFields: ["Content-Type": "application/json"]
            )!,
            Data(json.utf8)
        )
    }

    private static func bodyData(_ request: URLRequest) throws -> Data {
        if let body = request.httpBody { return body }
        let stream = try XCTUnwrap(request.httpBodyStream)
        stream.open()
        defer { stream.close() }
        var data = Data()
        var buffer = [UInt8](repeating: 0, count: 4096)
        while stream.hasBytesAvailable {
            let count = stream.read(&buffer, maxLength: buffer.count)
            if count < 0 { throw stream.streamError ?? URLError(.cannotDecodeContentData) }
            if count == 0 { break }
            data.append(buffer, count: count)
        }
        return data
    }
}
