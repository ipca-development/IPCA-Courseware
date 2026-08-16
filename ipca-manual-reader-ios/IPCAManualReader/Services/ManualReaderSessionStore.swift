import Foundation
import Combine
import Security

@MainActor
final class ManualReaderSessionStore: ObservableObject {
    static let shared = ManualReaderSessionStore()

    @Published private(set) var baseURL: URL?
    @Published private(set) var user: ReaderUser?
    @Published private(set) var isLoggedIn = false
    @Published private(set) var canAddReviewerNotes = false
    @Published var settings = ReaderSettings()
    @Published var bookmarks: [LocalBookmark] = []
    @Published var highlights: [TextHighlightAnchor] = []
    @Published private(set) var pendingReviewNotes: [PendingReviewNote] = []

    private let baseURLKey = "ipca.manual_reader.base_url"
    private let settingsKey = "ipca.manual_reader.settings"
    private let bookmarksKey = "ipca.manual_reader.bookmarks"
    private let highlightsKey = "ipca.manual_reader.highlights"
    private let pendingReviewNotesKey = "ipca.manual_reader.pending_review_notes"
    private let offlineUserKey = "ipca.manual_reader.offline_user"
    private let credentialService = "com.europilotcenter.IPCAManualReader.credentials"
    private let credentialAccount = "manual-reader-login"
    private var apiClient: ManualReaderAPIClient?
    private var annotationMutationRevision = 0
    private var annotationSyncRunning = false
    private var annotationSyncPending = false

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
        settings.theme = .original
        settings.zoom = .fitWidth
        settings.fontSize = .standard
        if let data = UserDefaults.standard.data(forKey: bookmarksKey),
           let decoded = try? JSONDecoder().decode([LocalBookmark].self, from: data) {
            bookmarks = decoded
        }
        if let data = UserDefaults.standard.data(forKey: highlightsKey),
           let decoded = try? JSONDecoder().decode([TextHighlightAnchor].self, from: data) {
            highlights = decoded
        }
        if let data = UserDefaults.standard.data(forKey: pendingReviewNotesKey),
           let decoded = try? JSONDecoder().decode([PendingReviewNote].self, from: data) {
            pendingReviewNotes = decoded
        }
        if let data = UserDefaults.standard.data(forKey: offlineUserKey),
           let decoded = try? JSONDecoder().decode(ReaderUser.self, from: data) {
            user = decoded
            isLoggedIn = true
            canAddReviewerNotes = decoded.role.lowercased() == "admin"
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
        settings.theme = .original
        settings.zoom = .fitWidth
        settings.fontSize = .standard
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
            personalReaderPageNumber: pageNumber,
            clientUpdatedAt: Date(),
            deletedAt: nil
        )
        bookmarks.removeAll {
            guard $0.bookKey == bookKey else { return false }
            if let semanticLocation {
                return $0.semanticLocation?.sourceFragmentID == semanticLocation.sourceFragmentID
            }
            return $0.pageNumber == pageNumber
        }
        bookmarks.insert(bookmark, at: 0)
        annotationMutationRevision += 1
        persistBookmarks()
    }

    func removeBookmark(_ bookmark: LocalBookmark) {
        guard let index = bookmarks.firstIndex(where: { $0.id == bookmark.id }) else { return }
        bookmarks[index].deletedAt = Date()
        bookmarks[index].clientUpdatedAt = Date()
        annotationMutationRevision += 1
        persistBookmarks()
    }

    func bookmarks(for bookKey: String) -> [LocalBookmark] {
        bookmarks
            .filter { $0.bookKey == bookKey && $0.deletedAt == nil }
            .sorted { $0.pageNumber < $1.pageNumber }
    }

    func bookmarkOrdinal(for bookKey: String, pageNumber: Int) -> Int? {
        let ordered = bookmarks
            .filter { $0.bookKey == bookKey && $0.deletedAt == nil }
            .sorted {
                if $0.createdAt == $1.createdAt { return $0.id.uuidString < $1.id.uuidString }
                return $0.createdAt < $1.createdAt
            }
        guard let index = ordered.firstIndex(where: { $0.pageNumber == pageNumber }) else {
            return nil
        }
        return index + 1
    }

    private func persistBookmarks() {
        if let data = try? JSONEncoder().encode(bookmarks) {
            UserDefaults.standard.set(data, forKey: bookmarksKey)
        }
    }

    func addHighlight(
        bookKey: String,
        versionID: Int?,
        pageNumber: Int,
        selection: ReaderTextSelection,
        color: ReaderHighlightColor
    ) {
        let highlight = TextHighlightAnchor(
            id: UUID(),
            bookKey: bookKey,
            versionID: versionID,
            pageNumber: pageNumber,
            selectedText: selection.selectedText,
            sourceFragmentID: selection.sourceFragmentID,
            stableAnchor: selection.stableAnchor,
            startOffset: selection.startOffset,
            endOffset: selection.endOffset,
            prefix: selection.prefix,
            suffix: selection.suffix,
            color: color,
            personalNote: nil,
            clientUpdatedAt: Date(),
            deletedAt: nil,
            createdAt: Date()
        )
        highlights.insert(highlight, at: 0)
        annotationMutationRevision += 1
        persistHighlights()
    }

    func highlights(for bookKey: String, pageNumber: Int) -> [TextHighlightAnchor] {
        highlights.filter {
            $0.bookKey == bookKey && $0.pageNumber == pageNumber && $0.deletedAt == nil
        }
    }

    func removeHighlight(_ highlight: TextHighlightAnchor) {
        guard let index = highlights.firstIndex(where: { $0.id == highlight.id }) else { return }
        highlights[index].deletedAt = Date()
        highlights[index].clientUpdatedAt = Date()
        annotationMutationRevision += 1
        persistHighlights()
    }

    func highlight(id: UUID) -> TextHighlightAnchor? {
        highlights.first { $0.id == id }
    }

    func highlight(
        matching selection: ReaderTextSelection,
        bookKey: String,
        pageNumber: Int
    ) -> TextHighlightAnchor? {
        if let id = selection.existingHighlightID {
            return highlights.first { $0.id == id && $0.deletedAt == nil }
        }
        return highlights.first {
            guard $0.bookKey == bookKey, $0.pageNumber == pageNumber,
                  $0.deletedAt == nil else { return false }
            if let sourceFragmentID = selection.sourceFragmentID,
               !sourceFragmentID.isEmpty,
               $0.sourceFragmentID != sourceFragmentID {
                return false
            }
            return selection.startOffset < $0.endOffset && selection.endOffset > $0.startOffset
        }
    }

    func updateHighlight(
        id: UUID,
        color: ReaderHighlightColor? = nil,
        personalNote: String?? = nil
    ) {
        guard let index = highlights.firstIndex(where: { $0.id == id }) else { return }
        if let color {
            highlights[index].color = color
        }
        if let personalNote {
            highlights[index].personalNote = personalNote
        }
        highlights[index].clientUpdatedAt = Date()
        annotationMutationRevision += 1
        persistHighlights()
    }

    private func persistHighlights() {
        if let data = try? JSONEncoder().encode(highlights) {
            UserDefaults.standard.set(data, forKey: highlightsKey)
        }
    }

    func queueReviewNote(_ note: PendingReviewNote) {
        pendingReviewNotes.removeAll { $0.id == note.id }
        pendingReviewNotes.append(note)
        persistPendingReviewNotes()
    }

    func removePendingReviewNote(id: UUID) {
        pendingReviewNotes.removeAll { $0.id == id }
        persistPendingReviewNotes()
    }

    private func persistPendingReviewNotes() {
        if let data = try? JSONEncoder().encode(pendingReviewNotes) {
            UserDefaults.standard.set(data, forKey: pendingReviewNotesKey)
        }
    }

    func syncAnnotations(bookKey: String, versionID: Int) async {
        guard let client else { return }
        if annotationSyncRunning {
            annotationSyncPending = true
            return
        }
        annotationSyncRunning = true
        defer { annotationSyncRunning = false }
        repeat {
            annotationSyncPending = false
            let revision = annotationMutationRevision
            let payload = bookmarkPayloads(bookKey: bookKey, versionID: versionID)
                + highlightPayloads(bookKey: bookKey, versionID: versionID)
            do {
                let response = payload.isEmpty
                    ? try await client.pullAnnotations(bookKey: bookKey, versionId: versionID)
                    : try await client.pushAnnotations(
                        bookKey: bookKey,
                        versionId: versionID,
                        annotations: payload
                    )
                if revision == annotationMutationRevision {
                    mergeServerAnnotations(
                        response.annotations,
                        bookKey: bookKey,
                        versionID: versionID
                    )
                } else {
                    annotationSyncPending = true
                }
                if response.canReviewManuals == true {
                    canAddReviewerNotes = true
                }
            } catch ManualReaderAPIError.unauthorized {
                clearSession()
                return
            } catch {
                // Local annotations remain authoritative while offline and retry next load/change.
            }
        } while annotationSyncPending
    }

    private func bookmarkPayloads(bookKey: String, versionID: Int) -> [[String: Any]] {
        bookmarks.compactMap { bookmark in
            guard bookmark.bookKey == bookKey,
                  bookmark.versionID == nil || bookmark.versionID == versionID else { return nil }
            return compactDictionary([
                "annotation_uuid": bookmark.id.uuidString,
                "kind": "bookmark",
                "page_number": bookmark.pageNumber,
                "label": bookmark.label,
                "stable_anchor": bookmark.stableAnchor,
                "source_fragment_id": bookmark.blockAnchor,
                "client_updated_at_utc": serverDate(bookmark.clientUpdatedAt ?? bookmark.createdAt),
                "deleted_at_utc": bookmark.deletedAt.map(serverDate),
            ])
        }
    }

    private func highlightPayloads(bookKey: String, versionID: Int) -> [[String: Any]] {
        highlights.compactMap { highlight in
            guard highlight.bookKey == bookKey,
                  highlight.versionID == nil || highlight.versionID == versionID else { return nil }
            return compactDictionary([
                "annotation_uuid": highlight.id.uuidString,
                "kind": "highlight",
                "page_number": highlight.pageNumber,
                "selected_text": highlight.selectedText,
                "source_fragment_id": highlight.sourceFragmentID,
                "stable_anchor": highlight.stableAnchor,
                "start_offset": highlight.startOffset,
                "end_offset": highlight.endOffset,
                "prefix": highlight.prefix,
                "suffix": highlight.suffix,
                "color": highlight.color.rawValue,
                "personal_note": highlight.personalNote,
                "client_updated_at_utc": serverDate(
                    highlight.clientUpdatedAt ?? highlight.createdAt
                ),
                "deleted_at_utc": highlight.deletedAt.map(serverDate),
            ])
        }
    }

    private func mergeServerAnnotations(
        _ annotations: [ReaderServerAnnotation],
        bookKey: String,
        versionID: Int
    ) {
        bookmarks.removeAll {
            $0.bookKey == bookKey && ($0.versionID == nil || $0.versionID == versionID)
        }
        highlights.removeAll {
            $0.bookKey == bookKey && ($0.versionID == nil || $0.versionID == versionID)
        }
        for annotation in annotations {
            guard let id = UUID(uuidString: annotation.annotationUUID) else { continue }
            let createdAt = parseServerDate(annotation.createdAtUTC)
                ?? parseServerDate(annotation.clientUpdatedAtUTC)
                ?? Date()
            let updatedAt = parseServerDate(annotation.clientUpdatedAtUTC) ?? createdAt
            let deletedAt = parseServerDate(annotation.deletedAtUTC)
            if annotation.kind == "bookmark" {
                bookmarks.append(
                    LocalBookmark(
                        id: id,
                        bookKey: annotation.bookKey,
                        versionID: annotation.versionID,
                        pageNumber: annotation.pageNumber,
                        label: annotation.label ?? "Page \(annotation.pageNumber)",
                        createdAt: createdAt,
                        stableAnchor: annotation.stableAnchor,
                        blockAnchor: annotation.sourceFragmentID,
                        officialLocation: nil,
                        semanticLocation: nil,
                        personalReaderPageNumber: annotation.pageNumber,
                        clientUpdatedAt: updatedAt,
                        deletedAt: deletedAt
                    )
                )
            } else if annotation.kind == "highlight" {
                highlights.append(
                    TextHighlightAnchor(
                        id: id,
                        bookKey: annotation.bookKey,
                        versionID: annotation.versionID,
                        pageNumber: annotation.pageNumber,
                        selectedText: annotation.selectedText ?? "",
                        sourceFragmentID: annotation.sourceFragmentID,
                        stableAnchor: annotation.stableAnchor,
                        startOffset: annotation.startOffset ?? 0,
                        endOffset: annotation.endOffset ?? 0,
                        prefix: annotation.prefix,
                        suffix: annotation.suffix,
                        color: ReaderHighlightColor(rawValue: annotation.color ?? "")
                            ?? .fluorescentYellow,
                        personalNote: annotation.personalNote,
                        clientUpdatedAt: updatedAt,
                        deletedAt: deletedAt,
                        createdAt: createdAt
                    )
                )
            }
        }
        persistBookmarks()
        persistHighlights()
    }

    private func compactDictionary(_ values: [String: Any?]) -> [String: Any] {
        values.reduce(into: [:]) { result, entry in
            if let value = entry.value {
                result[entry.key] = value
            }
        }
    }

    private func serverDate(_ date: Date) -> String {
        ISO8601DateFormatter().string(from: date)
    }

    private func parseServerDate(_ value: String?) -> Date? {
        guard let value, !value.isEmpty else { return nil }
        if let date = ISO8601DateFormatter().date(from: value) {
            return date
        }
        let formatter = DateFormatter()
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.timeZone = TimeZone(secondsFromGMT: 0)
        formatter.dateFormat = "yyyy-MM-dd HH:mm:ss.SSS"
        return formatter.date(from: value)
    }

    func applySession(_ response: AuthSessionResponse) {
        let loggedIn = response.loggedIn == true
        if isLoggedIn != loggedIn {
            isLoggedIn = loggedIn
        }
        if user != response.user {
            user = response.user
        }
        canAddReviewerNotes = response.canReviewManuals == true
            || response.user?.role.lowercased() == "admin"
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
        canAddReviewerNotes = false
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
