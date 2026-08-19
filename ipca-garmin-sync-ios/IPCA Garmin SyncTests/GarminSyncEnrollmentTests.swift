import XCTest
@testable import IPCAGarminSync

private final class EnrollmentURLProtocol: URLProtocol {
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

private final class MemoryGarminCredentialStore: GarminSyncCredentialStoring {
    private(set) var credential: String?

    init(credential: String? = nil) {
        self.credential = credential
    }

    func load() throws -> String? {
        credential
    }

    func save(_ credential: String) throws {
        self.credential = credential
    }
}

final class GarminSyncEnrollmentTests: XCTestCase {
    override func tearDown() {
        EnrollmentURLProtocol.handler = nil
        super.tearDown()
    }

    func testEnrollmentPostsContractAndAutomaticallyStoresOneTimeCredential() async throws {
        let configuration = URLSessionConfiguration.ephemeral
        configuration.protocolClasses = [EnrollmentURLProtocol.self]
        let session = URLSession(configuration: configuration)
        let store = MemoryGarminCredentialStore()

        EnrollmentURLProtocol.handler = { request in
            XCTAssertEqual(request.url?.path, "/api/garmin-sync/enroll.php")
            XCTAssertEqual(request.httpMethod, "POST")
            XCTAssertEqual(request.value(forHTTPHeaderField: "Content-Type"), "application/json")
            XCTAssertNil(request.value(forHTTPHeaderField: "Authorization"))
            let body = try Self.bodyData(request)
            let json = try XCTUnwrap(
                JSONSerialization.jsonObject(with: body) as? [String: String]
            )
            XCTAssertEqual(json["enrollment_code"], "GARMIN-ONCE")
            XCTAssertEqual(json["device_uuid"], "stable-device-uuid")
            XCTAssertEqual(json["display_name"], "Hangar iPad")

            let url = try XCTUnwrap(request.url)
            return (
                HTTPURLResponse(
                    url: url,
                    statusCode: 200,
                    httpVersion: nil,
                    headerFields: ["Content-Type": "application/json"]
                )!,
                Data(#"{"ok":true,"credential":"one-time-secret","credential_uuid":"credential-id"}"#.utf8)
            )
        }

        let result = try await GarminSyncEnrollmentService(
            baseURL: URL(string: "https://ipca.training")!,
            credentialStore: store,
            session: session
        ).enroll(
            code: "GARMIN-ONCE",
            deviceUUID: "stable-device-uuid",
            displayName: "Hangar iPad"
        )

        XCTAssertEqual(result.credentialUUID, "credential-id")
        XCTAssertEqual(store.credential, "one-time-secret")
    }

    @MainActor
    func testEnrollmentStatusRecoversFromInjectedGarminSyncStore() throws {
        let root = FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString)
        defer { try? FileManager.default.removeItem(at: root) }
        let defaults = try XCTUnwrap(UserDefaults(suiteName: UUID().uuidString))

        let enrolled = SyncViewModel(
            supportDirectory: root,
            defaults: defaults,
            credentialStore: MemoryGarminCredentialStore(credential: "isolated-secret")
        )
        XCTAssertEqual(enrolled.enrollmentStatus, .enrolled)

        let notEnrolled = SyncViewModel(
            supportDirectory: root,
            defaults: defaults,
            credentialStore: MemoryGarminCredentialStore()
        )
        XCTAssertEqual(notEnrolled.enrollmentStatus, .notEnrolled)
        XCTAssertEqual(notEnrolled.serverURL, "https://ipca.training")
    }

    func testGarminSyncKeychainAccountIsIsolatedFromLegacyManualAccount() {
        XCTAssertEqual(GarminSyncKeychainCredentialStore.service, "com.ipca.garmin-sync")
        XCTAssertEqual(
            GarminSyncKeychainCredentialStore.account,
            "garmin-sync-enrollment-credential"
        )
        XCTAssertNotEqual(GarminSyncKeychainCredentialStore.account, "bearer-token")
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
