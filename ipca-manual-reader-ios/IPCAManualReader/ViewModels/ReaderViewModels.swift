import Foundation
import SwiftUI

@MainActor
final class LibraryViewModel: ObservableObject {
    @Published var books: [LibraryBook] = []
    @Published var isLoading = false
    @Published var errorMessage: String?

    func load() async {
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
        } catch {
            errorMessage = error.localizedDescription
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
    private var prefetchTasks: [Task<Void, Never>] = []

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
            let versionId = book.versionId > 0 ? book.versionId : nil
            let isPreview = book.isDraftPreview
            async let mapTask = client.fetchPageMap(bookKey: book.bookKey, versionId: versionId, isPreview: isPreview)
            async let tocTask = client.fetchToc(bookKey: book.bookKey, versionId: versionId, isPreview: isPreview)

            let progress: ProgressGetResponse? = isPreview
                ? nil
                : try await client.fetchProgress(
                    bookKey: book.bookKey,
                    versionId: versionId,
                    isPreview: false
                )
            let (map, toc) = try await (mapTask, tocTask)
            pages = map.pages.sorted { $0.pageNumber < $1.pageNumber }
            nav = toc.nav
            sectionPageIndex = toc.sectionPageIndex.reduce(into: [:]) { result, pair in
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
                      let pageNum = progress?.progress?.pageNumber,
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
        prefetchNeighbors(around: index)
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
        guard !trimmed.isEmpty, let client = ManualReaderSessionStore.shared.client else {
            searchResults = []
            return
        }
        isSearching = true
        defer { isSearching = false }
        do {
            let response = try await client.searchTitles(
                bookKey: book.bookKey,
                query: trimmed,
                versionId: book.versionId > 0 ? book.versionId : nil,
                isPreview: book.isDraftPreview
            )
            searchResults = response.results
        } catch {
            searchResults = []
        }
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

        if let cached = await PageCache.shared.get(bookKey: book.id, page: pageNumber),
           let html = cached.pageHtml {
            currentPageHTML = client.pageHTMLDocument(pageHtml: html, settings: settings)
            return
        }

        do {
            let response = try await client.fetchPage(
                bookKey: book.bookKey,
                pageNumber: pageNumber,
                versionId: book.versionId > 0 ? book.versionId : nil,
                isPreview: book.isDraftPreview
            )
            await PageCache.shared.set(bookKey: book.id, page: pageNumber, response: response)
            currentPageHTML = client.pageHTMLDocument(pageHtml: response.pageHtml ?? "", settings: settings)
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    func reloadCurrentPageStyles() async {
        await loadPageHTML(at: currentIndex)
    }

    private func prefetchNeighbors(around index: Int) {
        prefetchTasks.forEach { $0.cancel() }
        prefetchTasks.removeAll()
        guard let client = ManualReaderSessionStore.shared.client else { return }

        for offset in [-1, 1, 2] {
            let target = index + offset
            guard pages.indices.contains(target) else { continue }
            let pageNumber = pages[target].pageNumber
            let cacheKey = book.id
            prefetchTasks.append(Task {
                if await PageCache.shared.get(bookKey: cacheKey, page: pageNumber) != nil { return }
                guard let client = ManualReaderSessionStore.shared.client else { return }
                if let response = try? await client.fetchPage(
                    bookKey: book.bookKey,
                    pageNumber: pageNumber,
                    versionId: book.versionId > 0 ? book.versionId : nil,
                    isPreview: book.isDraftPreview
                ) {
                    await PageCache.shared.set(bookKey: cacheKey, page: pageNumber, response: response)
                }
            })
        }
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
