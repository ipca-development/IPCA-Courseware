import Foundation
import Combine

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
    }

    var client: ManualReaderAPIClient? {
        guard let baseURL else { return nil }
        return ManualReaderAPIClient(baseURL: baseURL)
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
        baseURL = url
        UserDefaults.standard.set(url.absoluteString, forKey: baseURLKey)
    }

    func saveSettings() {
        if let data = try? JSONEncoder().encode(settings) {
            UserDefaults.standard.set(data, forKey: settingsKey)
        }
    }

    func addBookmark(bookKey: String, pageNumber: Int, label: String) {
        let bookmark = LocalBookmark(
            id: UUID(),
            bookKey: bookKey,
            pageNumber: pageNumber,
            label: label,
            createdAt: Date()
        )
        bookmarks.removeAll { $0.bookKey == bookKey && $0.pageNumber == pageNumber }
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
    }

    func clearSession() {
        guard isLoggedIn || user != nil else { return }
        isLoggedIn = false
        user = nil
    }

    func restoreSession() async throws {
        guard let client else {
            clearSession()
            return
        }
        let session = try await client.fetchSession()
        applySession(session)
    }

    /// Background session check — never blocks or rebuilds the login UI.
    func restoreSessionIfNeeded() async {
        guard baseURL != nil else { return }
        do {
            try await restoreSession()
        } catch {
            clearSession()
        }
    }

    func login(email: String, password: String) async throws {
        guard let client else { throw ManualReaderAPIError.invalidServerURL }
        let response = try await client.login(email: email, password: password)
        applySession(response)
    }

    func logout() async {
        if let client {
            _ = try? await client.logout()
        }
        clearSession()
    }
}
