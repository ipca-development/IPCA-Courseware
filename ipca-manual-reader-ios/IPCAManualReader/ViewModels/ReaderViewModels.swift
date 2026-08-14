import Foundation
import CryptoKit
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
    @Published private(set) var tocReferencePageIndex: [String: Int] = [:]
    @Published var isLoading = true
    @Published var errorMessage: String?
    @Published var currentPageHTML: String = ""
    @Published private(set) var pageHTMLByIndex: [Int: String] = [:]
    @Published private(set) var personalPages: [PersonalReaderPage] = []
    @Published private(set) var activeLayout: PageLayoutConfiguration?
    @Published private(set) var publicationLayout: PublicationLayout?
    @Published private(set) var paginationValidation: PaginationValidationSummary?
    @Published var searchResults: [SearchResult] = []
    @Published var isSearching = false

    private var progressTimer: Task<Void, Never>?
    private var offlinePackage: OfflineManualPackage?
    private var personalPageHTMLByNumber: [Int: String] = [:]
    private let paginationEngine = HTMLPaginationEngine()
    private var paginationInProgress = false
    private var pendingRepagination = false

    init(book: LibraryBook) {
        self.book = book
    }

    var currentPage: FrozenPageMeta? {
        guard pages.indices.contains(currentIndex) else { return nil }
        return pages[currentIndex]
    }

    var currentPersonalPage: PersonalReaderPage? {
        guard personalPages.indices.contains(currentIndex) else { return nil }
        return personalPages[currentIndex]
    }

    var currentSemanticLocation: SemanticReaderLocation? {
        currentPersonalPage?.startLocation
    }

    var currentOfficialLocation: OfficialDocumentLocation? {
        resolvedOfficialLocation()
    }

    var pageCount: Int { pages.count }

    func load() async {
#if DEBUG
        print("READER_LOAD_START book=\(book.id)")
#endif
        isLoading = true
        errorMessage = nil
        defer {
            isLoading = false
#if DEBUG
            print(
                "READER_LOAD_END error=\(errorMessage ?? "none") "
                    + "pages=\(pages.count) htmlPages=\(pageHTMLByIndex.count)"
            )
#endif
        }

        if let cachedPackage = await ManualDownloadManager.shared.package(for: book) {
            let openingPageNumber = preferredOpeningPageNumber(in: cachedPackage)
#if DEBUG
            print(
                "READER_CACHE packagePages=\(cachedPackage.pages.count) "
                    + "target=\(openingPageNumber ?? -1) "
                    + "verified=\(cachedPackage.hasVerifiedPublicationStyle)"
            )
#endif
            if cachedPackage.hasCanonicalPublicationPackage,
               openingPageNumber.flatMap({ cachedPackage.page(number: $0)?.pageHtml }) != nil {
                await applyPackage(cachedPackage)
#if DEBUG
                print("READER_LOAD_CACHE_COMPLETE htmlPages=\(pageHTMLByIndex.count)")
#endif
                return
            }
        }

        guard let client = ManualReaderSessionStore.shared.client else {
            errorMessage = "The manual is not available offline and no server URL is configured."
#if DEBUG
            print("READER_LOAD_NO_CLIENT")
#endif
            return
        }

        do {
#if DEBUG
            print("READER_DOWNLOAD_START")
#endif
            let package = try await ManualDownloadManager.shared.ensureDownloaded(
                book: book,
                client: client,
                forceRefresh: false
            )
            await applyPackage(package)
#if DEBUG
            print("READER_DOWNLOAD_COMPLETE htmlPages=\(pageHTMLByIndex.count)")
#endif
        } catch {
            errorMessage = error.localizedDescription
#if DEBUG
            print("READER_LOAD_ERROR \(error.localizedDescription)")
#endif
        }
    }

    private func applyPackage(_ package: OfflineManualPackage) async {
        let isPreview = book.isDraftPreview
        offlinePackage = package
        publicationLayout = package.publicationLayout
        personalPages = []
        personalPageHTMLByNumber = [:]
        paginationValidation = nil
        pages = package.pageMap.pages.sorted { $0.pageNumber < $1.pageNumber }
        nav = package.tableOfContents.nav
        sectionPageIndex = package.tableOfContents.sectionPageIndex.reduce(into: [:]) { result, pair in
            if let id = Int(pair.key) {
                result[id] = pair.value
            }
        }
        tocReferencePageIndex = makeTOCReferencePageIndex(
            package.pages.compactMap { page in
                guard let pageNumber = page.pageNumber, let html = page.pageHtml else { return nil }
                return (pageNumber, html)
            }
        )
        var startIndex = 0
        if !isPreview,
           let anchor = book.continueStableAnchor, !anchor.isEmpty,
           let match = pages.firstIndex(where: { $0.stableAnchor == anchor }),
           package.page(number: pages[match].pageNumber)?.pageHtml != nil {
            startIndex = match
        } else if !isPreview,
                  let pageNum = book.continuePageNumber,
                  let match = pages.firstIndex(where: { $0.pageNumber == pageNum }),
                  package.page(number: pageNum)?.pageHtml != nil {
            startIndex = match
        }
        await goToIndex(startIndex, persistProgress: false)
#if DEBUG
        print(
            "READER_PACKAGE_APPLIED pages=\(pages.count) start=\(startIndex) "
                + "htmlPages=\(pageHTMLByIndex.count) layout=\(activeLayout != nil)"
        )
#endif
    }

    private func preferredOpeningPageNumber(in package: OfflineManualPackage) -> Int? {
        if !book.isDraftPreview,
           let anchor = book.continueStableAnchor,
           !anchor.isEmpty,
           let page = package.pageMap.pages.first(where: { $0.stableAnchor == anchor }) {
            return page.pageNumber
        }
        if !book.isDraftPreview,
           let pageNumber = book.continuePageNumber,
           package.pageMap.pages.contains(where: { $0.pageNumber == pageNumber }) {
            return pageNumber
        }
        return package.pageMap.pages.map(\.pageNumber).min()
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

    func goToTOCNode(_ node: NavNode) async {
        if let reference = node.scrollSectionRef,
           let pageNumber = tocReferencePageIndex[reference] {
            await goToPageNumber(pageNumber)
            return
        }
        if let sectionID = node.id {
            await goToSection(sectionID)
        }
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

    func updateLayout(
        viewport: CGSize,
        safeAreaInsets: ReaderEdgeInsets = .zero,
        isLandscape: Bool
    ) async {
        guard let publicationLayout,
              let manifestLayoutHash = offlinePackage?.publicationPackage?.manifest.layoutHash else {
#if DEBUG
            print("READER_LAYOUT_WAITING publication=\(publicationLayout != nil)")
#endif
            return
        }
        let next = PageLayoutConfiguration.make(
            viewport: viewport,
            safeAreaInsets: safeAreaInsets,
            isLandscape: isLandscape,
            fontScale: 1,
            publicationLayout: publicationLayout,
            manifestLayoutHash: manifestLayoutHash
        )
        guard next != activeLayout else { return }
        activeLayout = next
#if DEBUG
        print(
            "READER_LAYOUT_ACTIVE page=\(next.pageWidth)x\(next.pageHeight) "
                + "htmlPages=\(pageHTMLByIndex.count)"
        )
#endif
        guard offlinePackage != nil else { return }
        if ReaderDisplayMode.controlledFrozenPages {
            pageHTMLByIndex.removeAll()
            preparePageHTML(around: currentIndex)
            currentPageHTML = pageHTMLByIndex[currentIndex] ?? ""
#if DEBUG
            print("READER_LAYOUT_USING_FROZEN_PAGES htmlPages=\(pageHTMLByIndex.count)")
#endif
            return
        }
        do {
            try await repaginateFromSource(preservingCurrentPosition: true)
            pageHTMLByIndex.removeAll()
            preparePageHTML(around: currentIndex)
            currentPageHTML = pageHTMLByIndex[currentIndex] ?? ""
        } catch {
            errorMessage = error.localizedDescription
        }
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
        if let client = ManualReaderSessionStore.shared.client,
           let response = try? await client.searchTitles(
               bookKey: book.bookKey,
               query: trimmed,
               versionId: book.versionId,
               isPreview: book.isDraftPreview
           ),
           response.ok {
            searchResults = response.results
            return
        }
        var matches: [SearchResult] = []

        if let package = offlinePackage {
            for cachedPage in package.pages.sorted(by: {
                ($0.pageNumber ?? 0) < ($1.pageNumber ?? 0)
            }) {
                guard let pageNumber = cachedPage.pageNumber,
                      let html = cachedPage.pageHtml else { continue }
                let plain = html
                    .replacingOccurrences(
                        of: "<[^>]+>",
                        with: " ",
                        options: .regularExpression
                    )
                    .replacingOccurrences(
                        of: "\\s+",
                        with: " ",
                        options: .regularExpression
                    )
                guard let range = plain.lowercased().range(of: needle) else { continue }
                let offset = plain.distance(from: plain.startIndex, to: range.lowerBound)
                let startOffset = max(0, offset - 55)
                let start = plain.index(plain.startIndex, offsetBy: startOffset)
                let end = plain.index(
                    start,
                    offsetBy: min(150, plain.distance(from: start, to: plain.endIndex))
                )
                let meta = pages.first(where: { $0.pageNumber == pageNumber })
                matches.append(
                    SearchResult(
                        sectionId: meta?.sectionId ?? 0,
                        sectionTitle: meta?.sectionTitle ?? "Page \(pageNumber)",
                        stableAnchor: meta?.stableAnchor,
                        pageNumber: pageNumber,
                        excerpt: String(plain[start..<end])
                    )
                )
                if matches.count >= 40 { break }
            }
            if !matches.isEmpty {
                searchResults = matches
                return
            }
        }

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
                            stableAnchor: node.stableAnchor,
                            pageNumber: sectionPageIndex[sectionID],
                            excerpt: nil
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
        let semanticLocation = currentSemanticLocation
        let blockAnchor = semanticLocation?.semanticAnchor
            ?? rawHTMLForCurrentPage().flatMap(firstBlockAnchor)
        let officialLocation = resolvedOfficialLocation()
        if let existing = store.bookmarks(for: book.bookKey).first(where: {
            if let sourceFragmentID = semanticLocation?.sourceFragmentID {
                return $0.semanticLocation?.sourceFragmentID == sourceFragmentID
            }
            if let blockAnchor, let existingBlockAnchor = $0.blockAnchor, !existingBlockAnchor.isEmpty {
                return existingBlockAnchor == blockAnchor
            }
            return $0.pageNumber == page.pageNumber
        }) {
            store.removeBookmark(existing)
        } else {
            store.addBookmark(
                bookKey: book.bookKey,
                versionID: book.versionId,
                pageNumber: page.pageNumber,
                label: label,
                stableAnchor: page.stableAnchor,
                blockAnchor: blockAnchor,
                officialLocation: officialLocation,
                semanticLocation: semanticLocation
            )
        }
    }

    func isCurrentPageBookmarked() -> Bool {
        guard let page = currentPage else { return false }
        let semanticLocation = currentSemanticLocation
        let blockAnchor = semanticLocation?.semanticAnchor
            ?? rawHTMLForCurrentPage().flatMap(firstBlockAnchor)
        return ManualReaderSessionStore.shared.bookmarks(for: book.bookKey).contains {
            if let sourceFragmentID = semanticLocation?.sourceFragmentID {
                return $0.semanticLocation?.sourceFragmentID == sourceFragmentID
            }
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
              pages.indices.contains(index) else { return }
        let client = ManualReaderSessionStore.shared.client
            ?? ManualReaderAPIClient(baseURL: URL(fileURLWithPath: Bundle.main.bundlePath))
        let pageNumber = pages[index].pageNumber
        let settings = ManualReaderSessionStore.shared.settings

        let rawHTML = offlinePackage?.page(number: pageNumber)?.pageHtml
        guard let html = rawHTML,
              let package = offlinePackage,
              let bookStyleCSS = package.bookStyleCSS,
              let publicationLayout = package.publicationLayout else { return }
        pageHTMLByIndex[index] = client.pageHTMLDocument(
            pageHtml: package.rewritePublicationURLs(in: html),
            settings: settings,
            bookStyleCSS: bookStyleCSS,
            readerCSS: "",
            layout: nil,
            publicationLayout: publicationLayout
        )
    }

    func reloadCurrentPageStyles() async {
        if let activeLayout,
           let publicationLayout,
           let manifestLayoutHash = offlinePackage?.publicationPackage?.manifest.layoutHash {
            self.activeLayout = PageLayoutConfiguration.make(
                viewport: CGSize(
                    width: CGFloat(activeLayout.viewportWidth),
                    height: CGFloat(activeLayout.viewportHeight)
                ),
                safeAreaInsets: activeLayout.safeAreaInsets,
                isLandscape: activeLayout.mode == .twoPageSpread,
                fontScale: 1,
                publicationLayout: publicationLayout,
                manifestLayoutHash: manifestLayoutHash
            )
        }
        pageHTMLByIndex.removeAll()
        preparePageHTML(around: currentIndex)
        currentPageHTML = pageHTMLByIndex[currentIndex] ?? ""
    }

    private func repaginateFromSource(preservingCurrentPosition: Bool) async throws {
        if paginationInProgress {
            pendingRepagination = true
            return
        }
        paginationInProgress = true
        defer {
            paginationInProgress = false
            if pendingRepagination {
                pendingRepagination = false
                Task { [weak self] in
                    try? await self?.repaginateFromSource(preservingCurrentPosition: true)
                }
            }
        }
        guard let package = offlinePackage,
              let sourceData = package.paginateSourceData,
              let bookStyleCSS = package.bookStyleCSS,
              let activeLayout else { return }
        let baseURL = ManualReaderSessionStore.shared.baseURL
            ?? URL(fileURLWithPath: Bundle.main.bundlePath)

        let savedSemanticLocation = preservingCurrentPosition ? currentSemanticLocation : nil
        let currentRawHTML = preservingCurrentPosition
            ? rawHTMLForCurrentPage()
            : nil
        let blockAnchor = savedSemanticLocation?.semanticAnchor
            ?? currentRawHTML.flatMap(firstBlockAnchor)
        let sectionAnchor = preservingCurrentPosition ? currentPage?.stableAnchor : nil
        let sectionID = preservingCurrentPosition ? currentPage?.sectionId : nil
        let cacheIdentity = paginationCacheIdentity(
            sourceData: sourceData,
            bookStyleCSS: bookStyleCSS,
            layout: activeLayout,
            publicationManifestHash: package.publicationPackage?.manifestHash ?? "",
            officialLayoutHash: package.pageMap.layoutHash ?? "",
            officialPageCount: package.pageMap.pageCount ?? package.pageMap.pages.count
        )
        let result: PersonalPaginationResult
        if let cached = await PersonalPaginationCache.shared.load(identity: cacheIdentity) {
            result = cached
        } else {
            let officialPages = officialPageLookups(package: package)
            result = try await paginationEngine.paginateBySection(
                sourceData: sourceData,
                bookStyleCSS: bookStyleCSS,
                readerCSS: "",
                layout: activeLayout,
                baseURL: baseURL,
                officialPageByAnchor: officialPages.byAnchor,
                officialPageBySection: officialPages.bySection,
                officialPageTotal: package.pageMap.pageCount ?? package.pageMap.pages.count,
                rewriteString: package.rewritePublicationURLs(in:)
            )
            guard result.validation.isValid,
                  result.personalPages.allSatisfy(\.metrics.validationPassed) else {
                throw NSError(
                    domain: "IPCAManualReader.Pagination",
                    code: 2,
                    userInfo: [
                        NSLocalizedDescriptionKey:
                            "The generated page map failed final geometry validation."
                    ]
                )
            }
            try await PersonalPaginationCache.shared.save(result, identity: cacheIdentity)
        }
        guard !result.pages.isEmpty,
              result.validation.isValid,
              result.personalPages.allSatisfy(\.metrics.validationPassed) else {
            throw NSError(
                domain: "IPCAManualReader.Pagination",
                code: 3,
                userInfo: [
                    NSLocalizedDescriptionKey:
                        "A cached personal page map failed final geometry validation."
                ]
            )
        }

        personalPages = result.personalPages
        pages = result.pages
        personalPageHTMLByNumber = result.pageHTMLByNumber
        tocReferencePageIndex = makeTOCReferencePageIndex(
            result.personalPages.map { ($0.pageNumber, $0.pageHTML) }
        )
        sectionPageIndex = result.sectionPageIndex
        paginationValidation = result.validation
#if DEBUG
        if ProcessInfo.processInfo.arguments.contains("--pagination-debug") {
            print(
                "PAGINATION configuration=\(activeLayout.cacheIdentity) "
                    + "pages=\(result.personalPages.count) "
                    + "fragments=\(result.validation.sourceFragmentCount) "
                    + "valid=\(result.validation.isValid)"
            )
            result.validation.diagnostics.forEach { item in
                print(
                    "PAGINATION \(item.severity.rawValue.uppercased()) "
                        + "page=\(item.pageNumber.map(String.init) ?? "-") "
                        + "fragment=\(item.sourceFragmentID ?? "-") "
                        + "code=\(item.code) \(item.message)"
                )
            }
            result.personalPages.forEach { page in
                let metrics = page.metrics
                print(
                    "PAGINATION PAGE \(page.pageNumber) "
                        + "official=\(page.startLocation?.officialLocation.officialPageNumber.map(String.init) ?? "-")"
                        + "...\(page.endLocation?.officialLocation.officialPageNumber.map(String.init) ?? "-") "
                        + "page=\(String(format: "%.2f", metrics.pageWidth))x"
                        + "\(String(format: "%.2f", metrics.pageHeight)) "
                        + "header=\(metrics.headerFrame) body=\(metrics.contentFrame) "
                        + "footer=\(metrics.footerFrame) "
                        + "scroll=\(String(format: "%.2f", metrics.contentScrollWidth))x"
                        + "\(String(format: "%.2f", metrics.contentScrollHeight)) "
                        + "client=\(String(format: "%.2f", metrics.contentClientWidth))x"
                        + "\(String(format: "%.2f", metrics.contentClientHeight)) "
                        + "maxBlockY=\(String(format: "%.2f", metrics.maxBlockY)) "
                        + "remaining=\(String(format: "%.2f", metrics.remainingBodyHeight)) "
                        + "utilization=\(String(format: "%.1f", metrics.contentUtilization * 100))% "
                        + "validation=\(metrics.validationPassed ? "PASS" : "FAIL") "
                        + "offending=\(metrics.offendingBlockID ?? "-")"
                )
            }
        }
#endif

        guard preservingCurrentPosition else { return }
        if let savedSemanticLocation,
           let match = result.personalPages.firstIndex(where: { page in
               page.coverage.contains { coverage in
                   coverage.sourceFragmentID == savedSemanticLocation.sourceFragmentID
                       && coverage.presentationCopy == false
                       && coverage.rangeStart <= savedSemanticLocation.characterOffset
                       && coverage.rangeEnd >= savedSemanticLocation.characterOffset
               }
           }) {
            currentIndex = match
        } else if let blockAnchor,
           let match = pages.firstIndex(where: {
               result.pageHTMLByNumber[$0.pageNumber]?.contains("data-stable-anchor=\"\(blockAnchor)\"") == true
                   || result.pageHTMLByNumber[$0.pageNumber]?.contains(
                       "data-source-fragment-id=\"\(blockAnchor)\""
                   ) == true
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

    private func officialPageLookups(
        package: OfflineManualPackage
    ) -> (byAnchor: [String: Int], bySection: [Int: Int]) {
        var byAnchor: [String: Int] = [:]
        var bySection: [Int: Int] = [:]
        package.pageMap.pages.sorted { $0.pageNumber < $1.pageNumber }.forEach { page in
            if let sectionID = page.sectionId {
                bySection[sectionID] = bySection[sectionID] ?? page.pageNumber
            }
            if let anchor = page.stableAnchor, !anchor.isEmpty {
                byAnchor[anchor] = byAnchor[anchor] ?? page.pageNumber
            }
        }
        let pattern = #"data-stable-anchor\s*=\s*["']([^"']+)["']"#
        guard let expression = try? NSRegularExpression(pattern: pattern) else {
            return (byAnchor, bySection)
        }
        package.pages.forEach { page in
            guard let html = page.pageHtml else { return }
            let range = NSRange(html.startIndex..<html.endIndex, in: html)
            expression.matches(in: html, range: range).forEach { match in
                guard match.numberOfRanges > 1,
                      let anchorRange = Range(match.range(at: 1), in: html) else { return }
                let anchor = String(html[anchorRange])
                byAnchor[anchor] = byAnchor[anchor] ?? (page.pageNumber ?? 0)
            }
        }
        byAnchor = byAnchor.filter { !$0.key.isEmpty && $0.value > 0 }
        return (byAnchor, bySection)
    }

    private func makeTOCReferencePageIndex(
        _ pages: [(pageNumber: Int, html: String)]
    ) -> [String: Int] {
        let pattern = #"data-section-number\s*=\s*["']([^"']+)["']"#
        guard let expression = try? NSRegularExpression(pattern: pattern) else {
            return [:]
        }
        var result: [String: Int] = [:]
        for page in pages.sorted(by: { $0.pageNumber < $1.pageNumber }) {
            let range = NSRange(page.html.startIndex..<page.html.endIndex, in: page.html)
            expression.matches(in: page.html, range: range).forEach { match in
                guard match.numberOfRanges > 1,
                      let referenceRange = Range(match.range(at: 1), in: page.html) else {
                    return
                }
                let reference = String(page.html[referenceRange])
                    .trimmingCharacters(in: CharacterSet(charactersIn: ". "))
                guard !reference.isEmpty else { return }
                result[reference] = result[reference] ?? page.pageNumber
            }
        }
        return result
    }

    private func paginationCacheIdentity(
        sourceData: Data,
        bookStyleCSS: String,
        layout: PageLayoutConfiguration,
        publicationManifestHash: String,
        officialLayoutHash: String,
        officialPageCount: Int
    ) -> String {
        let sourceHash = SHA256.hash(data: sourceData)
            .map { String(format: "%02x", $0) }
            .joined()
        let raw = [
            book.id,
            String(book.versionId),
            sourceHash,
            SHA256.hash(data: Data(bookStyleCSS.utf8))
                .map { String(format: "%02x", $0) }
                .joined(),
            publicationManifestHash,
            ReaderPaginationVersion.normalizer,
            ReaderPaginationVersion.engine,
            ReaderPaginationVersion.validator,
            ReaderPaginationVersion.style,
            layout.cacheIdentity,
            officialLayoutHash,
            String(officialPageCount)
        ].joined(separator: "|")
        return SHA256.hash(data: Data(raw.utf8))
            .map { String(format: "%02x", $0) }
            .joined()
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

    private func resolvedOfficialLocation() -> OfficialDocumentLocation? {
        guard let page = currentPage else { return nil }
        let semanticOfficial = currentSemanticLocation?.officialLocation
        let officialPage = offlinePackage?.pageMap.pages.first(where: { candidate in
            if let anchor = semanticOfficial?.stableAnchor ?? page.stableAnchor, !anchor.isEmpty {
                return candidate.stableAnchor == anchor
            }
            return candidate.sectionId == (semanticOfficial?.sectionID ?? page.sectionId)
        })
        return OfficialDocumentLocation(
            sectionID: semanticOfficial?.sectionID ?? page.sectionId,
            stableAnchor: semanticOfficial?.stableAnchor ?? page.stableAnchor,
            officialPageNumber: semanticOfficial?.officialPageNumber ?? officialPage?.pageNumber
        )
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
        let officialLocation = resolvedOfficialLocation()
        let officialPageNumber = officialLocation?.officialPageNumber ?? page.pageNumber
        _ = try? await client.saveProgress(
            bookKey: book.bookKey,
            sectionId: officialLocation?.sectionID ?? sectionId,
            stableAnchor: officialLocation?.stableAnchor ?? page.stableAnchor ?? "",
            pageNumber: officialPageNumber,
            versionId: book.versionId > 0 ? book.versionId : nil
        )
    }
}
