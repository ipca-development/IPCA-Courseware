import Foundation
import Security

protocol GarminSyncCredentialStoring {
    func load() throws -> String?
    func save(_ credential: String) throws
}

struct GarminSyncKeychainCredentialStore: GarminSyncCredentialStoring {
    static let service = "com.ipca.garmin-sync"
    static let account = "garmin-sync-enrollment-credential"

    func save(_ credential: String) throws {
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: Self.service,
            kSecAttrAccount as String: Self.account
        ]
        let attributes: [String: Any] = [
            kSecValueData as String: Data(credential.utf8),
            kSecAttrAccessible as String: kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly
        ]
        let status = SecItemUpdate(query as CFDictionary, attributes as CFDictionary)
        if status == errSecItemNotFound {
            var insertion = query
            attributes.forEach { insertion[$0.key] = $0.value }
            let insertionStatus = SecItemAdd(insertion as CFDictionary, nil)
            guard insertionStatus == errSecSuccess else {
                throw NSError(domain: NSOSStatusErrorDomain, code: Int(insertionStatus))
            }
        } else if status != errSecSuccess {
            throw NSError(domain: NSOSStatusErrorDomain, code: Int(status))
        }
    }

    func load() throws -> String? {
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: Self.service,
            kSecAttrAccount as String: Self.account,
            kSecReturnData as String: true,
            kSecMatchLimit as String: kSecMatchLimitOne
        ]
        var result: CFTypeRef?
        let status = SecItemCopyMatching(query as CFDictionary, &result)
        if status == errSecItemNotFound { return nil }
        guard status == errSecSuccess, let data = result as? Data else {
            throw NSError(domain: NSOSStatusErrorDomain, code: Int(status))
        }
        return String(decoding: data, as: UTF8.self)
    }
}

struct GarminSyncEnrollmentResult: Equatable {
    let credentialUUID: String?
}

struct GarminSyncEnrollmentService {
    let baseURL: URL
    let credentialStore: any GarminSyncCredentialStoring
    var session: URLSession = .shared

    func enroll(code: String, deviceUUID: String, displayName: String) async throws -> GarminSyncEnrollmentResult {
        guard let url = URL(string: "/api/garmin-sync/enroll.php", relativeTo: baseURL) else {
            throw GarminSyncError.invalidServerResponse
        }
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.timeoutInterval = 60
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.httpBody = try JSONEncoder().encode(
            EnrollmentRequest(
                enrollmentCode: code,
                deviceUUID: deviceUUID,
                displayName: displayName
            )
        )

        let (data, response) = try await session.data(for: request)
        guard let http = response as? HTTPURLResponse else {
            throw GarminSyncError.invalidServerResponse
        }
        guard (200..<300).contains(http.statusCode) else {
            let envelope = try? JSONDecoder().decode(EnrollmentResponse.self, from: data)
            throw GarminSyncError.serverRejected(envelope?.error ?? "HTTP \(http.statusCode)")
        }
        guard let payload = try? JSONDecoder().decode(EnrollmentResponse.self, from: data),
              payload.ok,
              let credential = payload.credential,
              !credential.isEmpty else {
            throw GarminSyncError.invalidServerResponse
        }
        try credentialStore.save(credential)
        return GarminSyncEnrollmentResult(credentialUUID: payload.credentialUUID)
    }
}

private struct EnrollmentRequest: Encodable {
    let enrollmentCode: String
    let deviceUUID: String
    let displayName: String

    enum CodingKeys: String, CodingKey {
        case enrollmentCode = "enrollment_code"
        case deviceUUID = "device_uuid"
        case displayName = "display_name"
    }
}

private struct EnrollmentResponse: Decodable {
    let ok: Bool
    let credential: String?
    let credentialUUID: String?
    let error: String?

    enum CodingKeys: String, CodingKey {
        case ok
        case credential
        case credentialUUID = "credential_uuid"
        case error
    }
}
