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
    @Published private(set) var pageHTMLByIndex: [Int: String] = [:]
    @Published var searchResults: [SearchResult] = []
    @Published var isSearching = false

    private var progressTimer: Task<Void, Never>?
    private var offlinePackage: OfflineManualPackage?
    private var personalPageHTMLByNumber: [Int: String] = [:]
    private var paginationFontSize: ReaderFontSize?
    private let paginationEngine = HTMLPaginationEngine()

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
            try? await repaginateFromSource(preservingCurrentPosition: false)

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
        preparePageHTML(around: index)
        currentPageHTML = pageHTMLByIndex[index] ?? ""
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

    func pageHTML(at index: Int) -> String? {
        pageHTMLByIndex[index]
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
        let blockAnchor = rawHTMLForCurrentPage().flatMap(firstBlockAnchor)
        if let existing = store.bookmarks(for: book.bookKey).first(where: {
            if let blockAnchor, let existingBlockAnchor = $0.blockAnchor, !existingBlockAnchor.isEmpty {
                return existingBlockAnchor == blockAnchor
            }
            return $0.pageNumber == page.pageNumber
        }) {
            store.removeBookmark(existing)
        } else {
            store.addBookmark(
                bookKey: book.bookKey,
                pageNumber: page.pageNumber,
                label: label,
                stableAnchor: page.stableAnchor,
                blockAnchor: blockAnchor
            )
        }
    }

    func isCurrentPageBookmarked() -> Bool {
        guard let page = currentPage else { return false }
        let blockAnchor = rawHTMLForCurrentPage().flatMap(firstBlockAnchor)
        return ManualReaderSessionStore.shared.bookmarks(for: book.bookKey).contains {
            if let blockAnchor, let existingBlockAnchor = $0.blockAnchor, !existingBlockAnchor.isEmpty {
                return existingBlockAnchor == blockAnchor
            }
            return $0.pageNumber == page.pageNumber
        }
    }

    private func preparePageHTML(around index: Int) {
        let retainedRange = (index - 4)...(index + 4)
        pageHTMLByIndex = pageHTMLByIndex.filter { retainedRange.contains($0.key) }
        for candidate in (index - 3)...(index + 3) where pages.indices.contains(candidate) {
            preparePageHTML(at: candidate)
        }
    }

    private func preparePageHTML(at index: Int) {
        guard pageHTMLByIndex[index] == nil,
              pages.indices.contains(index),
              let client = ManualReaderSessionStore.shared.client else { return }
        let pageNumber = pages[index].pageNumber
        let settings = ManualReaderSessionStore.shared.settings

        let rawHTML = personalPageHTMLByNumber[pageNumber]
            ?? offlinePackage?.page(number: pageNumber)?.pageHtml
        guard let html = rawHTML else { return }
        pageHTMLByIndex[index] = client.pageHTMLDocument(
            pageHtml: html,
            settings: settings,
            embeddedEditorCSS: offlinePackage?.editorCSS,
            embeddedReaderCSS: offlinePackage?.readerCSS
        )
    }

    func reloadCurrentPageStyles() async {
        if paginationFontSize != ManualReaderSessionStore.shared.settings.fontSize {
            try? await repaginateFromSource(preservingCurrentPosition: true)
        }
        pageHTMLByIndex.removeAll()
        preparePageHTML(around: currentIndex)
        currentPageHTML = pageHTMLByIndex[currentIndex] ?? ""
    }

    private func repaginateFromSource(preservingCurrentPosition: Bool) async throws {
        guard let package = offlinePackage,
              let sourceData = package.paginateSourceData,
              let editorCSS = package.editorCSS,
              let readerCSS = package.readerCSS,
              let baseURL = ManualReaderSessionStore.shared.baseURL else { return }

        let currentRawHTML = preservingCurrentPosition && pages.indices.contains(currentIndex)
            ? personalPageHTMLByNumber[pages[currentIndex].pageNumber]
                ?? package.page(number: pages[currentIndex].pageNumber)?.pageHtml
            : nil
        let blockAnchor = currentRawHTML.flatMap(firstBlockAnchor)
        let sectionAnchor = preservingCurrentPosition ? currentPage?.stableAnchor : nil
        let sectionID = preservingCurrentPosition ? currentPage?.sectionId : nil
        let fontSize = ManualReaderSessionStore.shared.settings.fontSize
        let result = try await paginationEngine.paginate(
            sourceData: sourceData,
            editorCSS: editorCSS,
            readerCSS: readerCSS,
            fontScale: fontSize.scale,
            baseURL: baseURL
        )
        guard !result.pages.isEmpty else { return }

        pages = result.pages
        personalPageHTMLByNumber = result.pageHTMLByNumber
        sectionPageIndex = result.sectionPageIndex
        paginationFontSize = fontSize

        guard preservingCurrentPosition else { return }
        if let blockAnchor,
           let match = pages.firstIndex(where: {
               result.pageHTMLByNumber[$0.pageNumber]?.contains("data-stable-anchor=\"\(blockAnchor)\"") == true
           }) {
            currentIndex = match
        } else if let sectionAnchor,
                  let match = pages.firstIndex(where: { $0.stableAnchor == sectionAnchor }) {
            currentIndex = match
        } else if let sectionID,
                  let match = pages.firstIndex(where: { $0.sectionId == sectionID }) {
            currentIndex = match
        } else {
            currentIndex = min(currentIndex, pages.count - 1)
        }
    }

    private func firstBlockAnchor(in html: String) -> String? {
        guard let range = html.range(
            of: #"data-stable-anchor="([^"]+)""#,
            options: .regularExpression
        ) else { return nil }
        let match = String(html[range])
        guard let start = match.firstIndex(of: "\""),
              let end = match[match.index(after: start)...].firstIndex(of: "\"") else { return nil }
        return String(match[match.index(after: start)..<end])
    }

    private func rawHTMLForCurrentPage() -> String? {
        guard let page = currentPage else { return nil }
        return personalPageHTMLByNumber[page.pageNumber]
            ?? offlinePackage?.page(number: page.pageNumber)?.pageHtml
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
        let officialPageNumber = offlinePackage?.pageMap.pages.first(where: {
            if let stableAnchor = page.stableAnchor, !stableAnchor.isEmpty {
                return $0.stableAnchor == stableAnchor
            }
            return $0.sectionId == sectionId
        })?.pageNumber ?? page.pageNumber
        _ = try? await client.saveProgress(
            bookKey: book.bookKey,
            sectionId: sectionId,
            stableAnchor: page.stableAnchor ?? "",
            pageNumber: officialPageNumber,
            versionId: book.versionId > 0 ? book.versionId : nil
        )
    }
}
