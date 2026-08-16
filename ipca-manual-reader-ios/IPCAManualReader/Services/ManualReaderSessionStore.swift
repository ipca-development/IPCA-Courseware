import Foundation
import Combine
import Security

@MainActor
final class ManualReaderSessionStore: ObservableObject {
    static let shared = ManualReaderSessionStore()

    @Published private(set) var baseURL: URL?
    @Published private(set) var user: ReaderUser?
    @Published private(set) var isLoggedIn = false
    @Published var settings = ReaderSettings()
    @Published var bookmarks: [LocalBookmark] = []

    private let baseURLKey = "ipca.manual_reader.base_url"
    private let settingsKey = "ipca.manual_reader.settings"
    private let bookmarksKey = "ipca.manual_reader.bookmarks"
    private let offlineUserKey = "ipca.manual_reader.offline_user"
    private let credentialService = "com.europilotcenter.IPCAManualReader.credentials"
    private let credentialAccount = "manual-reader-login"
    private var apiClient: ManualReaderAPIClient?

    private struct StoredCredentials: Codable {
        let serverURL: String
        let email: String
        let password: String
    }

    private init() {
        if let raw = UserDefaults.standard.string(forKey: baseURLKey),
           let url = URL(string: raw),
           let _ = url.host {
            baseURL = url
        }
        if let data = UserDefaults.standard.data(forKey: settingsKey),
           let decoded = try? JSONDecoder().decode(ReaderSettings.self, from: data) {
            settings = decoded
        }
        if let data = UserDefaults.standard.data(forKey: bookmarksKey),
           let decoded = try? JSONDecoder().decode([LocalBookmark].self, from: data) {
            bookmarks = decoded
        }
        if let data = UserDefaults.standard.data(forKey: offlineUserKey),
           let decoded = try? JSONDecoder().decode(ReaderUser.self, from: data) {
            user = decoded
            isLoggedIn = true
        }
    }

    var client: ManualReaderAPIClient? {
        guard let baseURL else { return nil }
        if let apiClient, apiClient.baseURL == baseURL {
            return apiClient
        }
        let client = ManualReaderAPIClient(baseURL: baseURL)
        apiClient = client
        return client
    }

    func setServerURL(_ string: String) throws {
        var trimmed = string.trimmingCharacters(in: .whitespacesAndNewlines)
        if !trimmed.hasPrefix("http") {
            trimmed = "https://" + trimmed
        }
        guard var components = URLComponents(string: trimmed),
              let host = components.host,
              !host.isEmpty else {
            throw ManualReaderAPIError.invalidServerURL
        }
        if components.path.hasSuffix("/") {
            components.path = String(components.path.dropLast())
        }
        guard let url = components.url else {
            throw ManualReaderAPIError.invalidServerURL
        }
        if baseURL != url {
            apiClient = nil
        }
        baseURL = url
        UserDefaults.standard.set(url.absoluteString, forKey: baseURLKey)
    }

    func saveSettings() {
        if let data = try? JSONEncoder().encode(settings) {
            UserDefaults.standard.set(data, forKey: settingsKey)
        }
    }

    func addBookmark(
        bookKey: String,
        versionID: Int? = nil,
        pageNumber: Int,
        label: String,
        stableAnchor: String? = nil,
        blockAnchor: String? = nil,
        officialLocation: OfficialDocumentLocation? = nil,
        semanticLocation: SemanticReaderLocation? = nil
    ) {
        let bookmark = LocalBookmark(
            id: UUID(),
            bookKey: bookKey,
            versionID: versionID,
            pageNumber: pageNumber,
            label: label,
            createdAt: Date(),
            stableAnchor: stableAnchor,
            blockAnchor: blockAnchor,
            officialLocation: officialLocation,
            semanticLocation: semanticLocation,
            personalReaderPageNumber: pageNumber
        )
        bookmarks.removeAll {
            guard $0.bookKey == bookKey else { return false }
            if let semanticLocation {
                return $0.semanticLocation?.sourceFragmentID == semanticLocation.sourceFragmentID
            }
            return $0.pageNumber == pageNumber
        }
        bookmarks.insert(bookmark, at: 0)
        persistBookmarks()
    }

    func removeBookmark(_ bookmark: LocalBookmark) {
        bookmarks.removeAll { $0.id == bookmark.id }
        persistBookmarks()
    }

    func bookmarks(for bookKey: String) -> [LocalBookmark] {
        bookmarks.filter { $0.bookKey == bookKey }.sorted { $0.pageNumber < $1.pageNumber }
    }

    private func persistBookmarks() {
        if let data = try? JSONEncoder().encode(bookmarks) {
            UserDefaults.standard.set(data, forKey: bookmarksKey)
        }
    }

    func applySession(_ response: AuthSessionResponse) {
        let loggedIn = response.loggedIn == true
        if isLoggedIn != loggedIn {
            isLoggedIn = loggedIn
        }
        if user != response.user {
            user = response.user
        }
        if loggedIn, let user = response.user,
           let data = try? JSONEncoder().encode(user) {
            UserDefaults.standard.set(data, forKey: offlineUserKey)
        } else if !loggedIn {
            UserDefaults.standard.removeObject(forKey: offlineUserKey)
        }
    }

    func clearSession() {
        guard isLoggedIn || user != nil else { return }
        isLoggedIn = false
        user = nil
        UserDefaults.standard.removeObject(forKey: offlineUserKey)
    }

    func restoreSession() async throws {
        guard let client else {
            clearSession()
            return
        }
        let restoredSession = try await client.fetchSession()
        if restoredSession.loggedIn == true {
            applySession(restoredSession)
            return
        }
        if let credentials = loadCredentials(),
           credentials.serverURL == baseURL?.absoluteString {
            let authenticated = try await client.login(
                email: credentials.email,
                password: credentials.password
            )
            applySession(authenticated)
            return
        }
        applySession(restoredSession)
    }

    /// Background session check — never blocks or rebuilds the login UI.
    func restoreSessionIfNeeded() async {
        guard baseURL != nil else { return }
        do {
            try await restoreSession()
        } catch ManualReaderAPIError.unauthorized {
            clearSession()
        } catch {
            // Preserve the last authenticated identity so downloaded manuals remain usable offline.
        }
    }

    func login(email: String, password: String) async throws {
        guard let client else { throw ManualReaderAPIError.invalidServerURL }
        let response = try await client.login(email: email, password: password)
        applySession(response)
        if response.loggedIn == true, let baseURL {
            try saveCredentials(
                StoredCredentials(
                    serverURL: baseURL.absoluteString,
                    email: email,
                    password: password
                )
            )
        }
    }

    func logout() async {
        if let client {
            _ = try? await client.logout()
        }
        deleteCredentials()
        clearSession()
    }

    private func saveCredentials(_ credentials: StoredCredentials) throws {
        let data = try JSONEncoder().encode(credentials)
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: credentialService,
            kSecAttrAccount as String: credentialAccount,
        ]
        SecItemDelete(query as CFDictionary)
        var item = query
        item[kSecValueData as String] = data
        item[kSecAttrAccessible as String] = kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly
        let status = SecItemAdd(item as CFDictionary, nil)
        guard status == errSecSuccess else {
            throw ManualReaderAPIError.badResponse("Unable to securely remember this login.")
        }
    }

    private func loadCredentials() -> StoredCredentials? {
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: credentialService,
            kSecAttrAccount as String: credentialAccount,
            kSecReturnData as String: true,
            kSecMatchLimit as String: kSecMatchLimitOne,
        ]
        var result: CFTypeRef?
        guard SecItemCopyMatching(query as CFDictionary, &result) == errSecSuccess,
              let data = result as? Data else {
            return nil
        }
        return try? JSONDecoder().decode(StoredCredentials.self, from: data)
    }

    private func deleteCredentials() {
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: credentialService,
            kSecAttrAccount as String: credentialAccount,
        ]
        SecItemDelete(query as CFDictionary)
    }
}
