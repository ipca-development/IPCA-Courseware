import Foundation
import SwiftUI

@MainActor
final class LibraryViewModel: ObservableObject {
    @Published var books: [LibraryBook] = []
    @Published var isLoading = false
    @Published var errorMessage: String?

    private let cachedLibraryKey = "ipca.manual_reader.cached_library"

    init() {
        if let data = UserDefaults.standard.data(forKey: cachedLibraryKey),
           let response = try? JSONDecoder().decode(LibraryResponse.self, from: data) {
            books = response.books
        }
    }

    func load() async {
        await ManualDownloadManager.shared.refreshStatuses(for: books)
        guard let client = ManualReaderSessionStore.shared.client else {
            errorMessage = "Configure the IPCA server URL in Settings."
            return
        }
        isLoading = true
        errorMessage = nil
        defer { isLoading = false }
        do {
            let response = try await client.fetchLibrary()
            books = response.books
            if let data = try? JSONEncoder().encode(response) {
                UserDefaults.standard.set(data, forKey: cachedLibraryKey)
            }
            await ManualDownloadManager.shared.refreshStatuses(for: books)
        } catch {
            if books.isEmpty {
                errorMessage = error.localizedDescription
            }
        }
    }
}

@MainActor
final class ReaderViewModel: ObservableObject {
    let book: LibraryBook

    @Published var pages: [FrozenPageMeta] = []
    @Published var currentIndex = 0
    @Published var nav: [NavNode] = []
    @Published var sectionPageIndex: [Int: Int] = [:]
    @Published var isLoading = true
    @Published var errorMessage: String?
    @Published var currentPageHTML: String = ""
    @Published var searchResults: [SearchResult] = []
    @Published var isSearching = false

    private var progressTimer: Task<Void, Never>?
    private var offlinePackage: OfflineManualPackage?

    init(book: LibraryBook) {
        self.book = book
    }

    var currentPage: FrozenPageMeta? {
        guard pages.indices.contains(currentIndex) else { return nil }
        return pages[currentIndex]
    }

    var pageCount: Int { pages.count }

    func load() async {
        guard let client = ManualReaderSessionStore.shared.client else { return }
        isLoading = true
        errorMessage = nil
        defer { isLoading = false }

        do {
            let isPreview = book.isDraftPreview
            let package = try await ManualDownloadManager.shared.ensureDownloaded(
                book: book,
                client: client,
                forceRefresh: false
            )
            offlinePackage = package
            pages = package.pageMap.pages.sorted { $0.pageNumber < $1.pageNumber }
            nav = package.tableOfContents.nav
            sectionPageIndex = package.tableOfContents.sectionPageIndex.reduce(into: [:]) { result, pair in
                if let id = Int(pair.key) {
                    result[id] = pair.value
                }
            }

            var startIndex = 0
            if !isPreview,
               let anchor = book.continueStableAnchor, !anchor.isEmpty,
               let match = pages.firstIndex(where: { $0.stableAnchor == anchor }) {
                startIndex = match
            } else if !isPreview,
                      let pageNum = book.continuePageNumber,
                      let match = pages.firstIndex(where: { $0.pageNumber == pageNum }) {
                startIndex = match
            }
            await goToIndex(startIndex, persistProgress: false)
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    func goToIndex(_ index: Int, persistProgress: Bool = true) async {
        guard pages.indices.contains(index) else { return }
        currentIndex = index
        await loadPageHTML(at: index)
        if persistProgress {
            scheduleProgressSave()
        }
    }

    func goToPageNumber(_ pageNumber: Int) async {
        guard let index = pages.firstIndex(where: { $0.pageNumber == pageNumber }) else { return }
        await goToIndex(index)
    }

    func goToSection(_ sectionId: Int) async {
        guard let pageNumber = sectionPageIndex[sectionId],
              let index = pages.firstIndex(where: { $0.pageNumber == pageNumber }) else { return }
        await goToIndex(index)
    }

    func nextPage() async {
        await goToIndex(currentIndex + 1)
    }

    func previousPage() async {
        await goToIndex(currentIndex - 1)
    }

    func search(query: String) async {
        let trimmed = query.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else {
            searchResults = []
            return
        }
        isSearching = true
        defer { isSearching = false }
        let needle = trimmed.lowercased()
        var matches: [SearchResult] = []

        func collect(_ nodes: [NavNode]) {
            for node in nodes {
                if let sectionID = node.id,
                   node.isNavigable != false,
                   let title = node.title,
                   title.lowercased().contains(needle) {
                    matches.append(
                        SearchResult(
                            sectionId: sectionID,
                            sectionTitle: title,
                            stableAnchor: node.stableAnchor
                        )
                    )
                }
                if let children = node.children {
                    collect(children)
                }
            }
        }
        collect(nav)
        searchResults = Array(matches.prefix(40))
    }

    func toggleBookmark(label: String) {
        guard let page = currentPage else { return }
        let store = ManualReaderSessionStore.shared
        if let existing = store.bookmarks(for: book.bookKey).first(where: { $0.pageNumber == page.pageNumber }) {
            store.removeBookmark(existing)
        } else {
            store.addBookmark(bookKey: book.bookKey, pageNumber: page.pageNumber, label: label)
        }
    }

    func isCurrentPageBookmarked() -> Bool {
        guard let page = currentPage else { return false }
        return ManualReaderSessionStore.shared.bookmarks(for: book.bookKey).contains { $0.pageNumber == page.pageNumber }
    }

    private func loadPageHTML(at index: Int) async {
        guard pages.indices.contains(index),
              let client = ManualReaderSessionStore.shared.client else { return }
        let pageNumber = pages[index].pageNumber
        let settings = ManualReaderSessionStore.shared.settings

        if let response = offlinePackage?.page(number: pageNumber),
           let html = response.pageHtml {
            currentPageHTML = client.pageHTMLDocument(
                pageHtml: html,
                settings: settings,
                embeddedEditorCSS: offlinePackage?.editorCSS,
                embeddedReaderCSS: offlinePackage?.readerCSS
            )
            return
        }

        errorMessage = "This page is not available in the offline manual package."
    }

    func reloadCurrentPageStyles() async {
        await loadPageHTML(at: currentIndex)
    }

    private func scheduleProgressSave() {
        guard !book.isDraftPreview else { return }
        progressTimer?.cancel()
        progressTimer = Task { [weak self] in
            try? await Task.sleep(nanoseconds: 800_000_000)
            await self?.saveProgress()
        }
    }

    private func saveProgress() async {
        guard !book.isDraftPreview,
              let client = ManualReaderSessionStore.shared.client,
              let page = currentPage,
              let sectionId = page.sectionId else { return }
        _ = try? await client.saveProgress(
            bookKey: book.bookKey,
            sectionId: sectionId,
            stableAnchor: page.stableAnchor ?? "",
            pageNumber: page.pageNumber,
            versionId: book.versionId > 0 ? book.versionId : nil
        )
    }
}
