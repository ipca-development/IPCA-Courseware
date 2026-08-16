import SwiftUI
import UIKit

struct ReaderView: View {
    @Environment(\.dismiss) private var dismiss
    @ObservedObject private var session = ManualReaderSessionStore.shared
    @ObservedObject private var downloads = ManualDownloadManager.shared
    @StateObject private var viewModel: ReaderViewModel

    @State private var showChrome = false
    @State private var showTOC = false
    @State private var showSearch = false
    @State private var showBookmarks = false
    @State private var searchQuery = ""
    @State private var controlsHideTask: Task<Void, Never>?
    @State private var isExiting = false
    @State private var renderedPages: Set<Int> = []
    @State private var pendingExternalURL: URL?
    @State private var pendingTextSelection: ReaderTextSelection?
    private let onExit: (() -> Void)?
    private let initialBookmark: LocalBookmark?

    init(
        book: LibraryBook,
        initialBookmark: LocalBookmark? = nil,
        onExit: (() -> Void)? = nil
    ) {
        _viewModel = StateObject(wrappedValue: ReaderViewModel(book: book))
        self.initialBookmark = initialBookmark
        self.onExit = onExit
    }

    var body: some View {
        GeometryReader { proxy in
            let safeSize = CGSize(
                width: max(
                    1,
                    proxy.size.width - proxy.safeAreaInsets.leading - proxy.safeAreaInsets.trailing
                ),
                height: max(
                    1,
                    proxy.size.height - proxy.safeAreaInsets.top - proxy.safeAreaInsets.bottom
                )
            )
            let requiredPages = requiredVisiblePageIndexes(
                isLandscape: safeSize.width > safeSize.height
            )
            let readyPages = requiredPages.intersection(renderedPages)
            let pagesAreReady = !requiredPages.isEmpty && readyPages.count == requiredPages.count
            let isOpening = viewModel.isLoading || (!viewModel.pages.isEmpty && !pagesAreReady)
            ZStack {
                readerBackground.ignoresSafeArea()

                if let error = viewModel.errorMessage, viewModel.pages.isEmpty {
                    ContentUnavailableView(
                        "Unable to Open Manual",
                        systemImage: "exclamationmark.triangle",
                        description: Text(error)
                    )
                } else if !viewModel.pages.isEmpty {
                    physicalBookReader(size: safeSize)
                        .frame(width: safeSize.width, height: safeSize.height)
                }

                if isOpening {
                    VStack(spacing: 14) {
                        ProgressView(
                            value: openingProgress(
                                readyCount: readyPages.count,
                                requiredCount: requiredPages.count
                            )
                        )
                        .progressViewStyle(.linear)
                        .frame(maxWidth: 280)
                        Text(openingMessage(pagesAreReady: pagesAreReady))
                            .font(.subheadline.weight(.medium))
                    }
                    .padding(24)
                    .background(.regularMaterial, in: RoundedRectangle(cornerRadius: 14))
                }

                if showChrome && !isOpening {
                    ReaderControlsOverlay(
                        pageDescription: pageDescription,
                        isBookmarked: viewModel.isCurrentPageBookmarked(),
                        isExiting: isExiting,
                        onClose: exitReader,
                        onSearch: {
                            showSearch = true
                            scheduleControlsAutoHide()
                        },
                        onContents: {
                            showTOC = true
                            scheduleControlsAutoHide()
                        },
                        onBookmark: {
                            viewModel.toggleBookmark(label: "Page \(viewModel.currentPage?.pageNumber ?? 0)")
                            scheduleControlsAutoHide()
                        },
                        showSearch: $showSearch,
                        showContents: $showTOC,
                        showBookmarks: $showBookmarks,
                        searchContent: { searchPopover },
                        contentsContent: { contentsPopover },
                        bookmarksContent: { bookmarksPopover }
                    )
                    .padding(.top, max(8, proxy.safeAreaInsets.top + 4))
                    .padding(.horizontal, 18)
                    .frame(maxHeight: .infinity, alignment: .top)
                    .transition(.opacity.combined(with: .move(edge: .top)))
                }
            }
            .task(
                id: "\(Int(proxy.size.width))x\(Int(proxy.size.height))-\(viewModel.publicationLayout?.pageWidthPX ?? 0)"
            ) {
                await viewModel.updateLayout(
                    viewport: proxy.size,
                    safeAreaInsets: ReaderEdgeInsets(
                        top: Double(proxy.safeAreaInsets.top),
                        leading: Double(proxy.safeAreaInsets.leading),
                        bottom: Double(proxy.safeAreaInsets.bottom),
                        trailing: Double(proxy.safeAreaInsets.trailing)
                    ),
                    isLandscape: safeSize.width > safeSize.height
                )
            }
        }
        .statusBarHidden(true)
        .persistentSystemOverlays(.hidden)
        .task {
            await viewModel.load()
            if let initialBookmark {
                await viewModel.goToBookmark(initialBookmark)
            }
        }
        .onChange(of: viewModel.currentIndex) { _, newIndex in
            Task { await viewModel.goToIndex(newIndex) }
        }
        .onDisappear {
            controlsHideTask?.cancel()
        }
        .alert(
            "Open External Website?",
            isPresented: Binding(
                get: { pendingExternalURL != nil },
                set: { if !$0 { pendingExternalURL = nil } }
            )
        ) {
            Button("Cancel", role: .cancel) { pendingExternalURL = nil }
            Button("Open in Browser") {
                guard let url = pendingExternalURL else { return }
                pendingExternalURL = nil
                UIApplication.shared.open(url)
            }
        } message: {
            Text(
                "You are visiting a website outside of this app.\n"
                    + (pendingExternalURL?.absoluteString ?? "")
            )
        }
        .confirmationDialog(
            "Highlight Selected Text",
            isPresented: Binding(
                get: { pendingTextSelection != nil },
                set: { if !$0 { pendingTextSelection = nil } }
            ),
            titleVisibility: .visible
        ) {
            ForEach(ReaderHighlightColor.allCases) { color in
                Button(color.label) {
                    guard let selection = pendingTextSelection else { return }
                    pendingTextSelection = nil
                    Task { await viewModel.addHighlight(selection, color: color) }
                }
            }
            Button("Cancel", role: .cancel) { pendingTextSelection = nil }
        } message: {
            Text(pendingTextSelection?.selectedText ?? "")
        }
    }

    private func openingProgress(readyCount: Int, requiredCount: Int) -> Double {
        if case .downloading(let progress) = downloads.status(for: viewModel.book) {
            return max(viewModel.openingProgress, 0.05 + (0.50 * progress))
        }
        if viewModel.isLoading {
            return viewModel.openingProgress
        }
        return 0.85 + (0.15 * Double(readyCount) / Double(max(requiredCount, 1)))
    }

    private func openingMessage(pagesAreReady: Bool) -> String {
        if case .downloading(let progress) = downloads.status(for: viewModel.book) {
            return "Downloading manual… \(Int(progress * 100))%"
        }
        return pagesAreReady ? "Pages ready" : viewModel.openingMessage
    }

    private func requiredVisiblePageIndexes(isLandscape: Bool) -> Set<Int> {
        guard viewModel.pages.indices.contains(viewModel.currentIndex) else { return [] }
        guard isLandscape else { return [viewModel.currentIndex] }
        let pageNumber = viewModel.pages[viewModel.currentIndex].pageNumber
        if pageNumber.isMultiple(of: 2) {
            return Set([viewModel.currentIndex - 1, viewModel.currentIndex].filter {
                viewModel.pages.indices.contains($0)
            })
        }
        return Set([viewModel.currentIndex, viewModel.currentIndex + 1].filter {
            viewModel.pages.indices.contains($0)
        })
    }

    private var readerBackground: some View {
        pageBackgroundColor
    }

    private var pageBackgroundColor: Color { .white }

    private var readerColorScheme: ColorScheme { .light }

    private func physicalBookReader(size: CGSize) -> AnyView {
        let landscape = size.width > size.height
        guard let layout = viewModel.activeLayout else {
            return AnyView(ProgressView().frame(maxWidth: .infinity, maxHeight: .infinity))
        }
        let pageWidth = CGFloat(layout.pageWidth)
        let pageHeight = CGFloat(layout.pageHeight)
        let readerWidth = landscape
            ? pageWidth * 2 + CGFloat(layout.gutterWidth)
            : pageWidth
        let contentBaseURL = session.baseURL ?? URL(fileURLWithPath: Bundle.main.bundlePath)

        return AnyView(ZStack {
            if !viewModel.pageHTMLByIndex.isEmpty {
                BookPageCurlView(
                    pages: viewModel.pages,
                    htmlByIndex: viewModel.pageHTMLByIndex,
                    baseURL: contentBaseURL,
                    isLandscape: landscape,
                    pageSize: CGSize(width: pageWidth, height: pageHeight),
                    pageBackground: pageBackgroundColor,
                    bookKey: viewModel.book.bookKey,
                    currentIndex: $viewModel.currentIndex,
                    onTap: toggleControls,
                    onPageReady: { renderedPages.insert($0) },
                    onNavigateToAnchor: { anchor in
                        Task { await viewModel.goToStableAnchor(anchor) }
                    },
                    onNavigateToSection: { sectionID in
                        Task { await viewModel.goToSection(sectionID) }
                    },
                    onExternalLink: { pendingExternalURL = $0 },
                    onTextSelection: { pendingTextSelection = $0 }
                )
                .id(landscape)

                if landscape {
                    BookGutterView()
                }
#if DEBUG
                if ProcessInfo.processInfo.arguments.contains("--pagination-debug") {
                    PaginationDebugOverlay(
                        layout: layout,
                        metrics: viewModel.currentPersonalPage?.metrics,
                        repeats: landscape ? 2 : 1
                    )
                }
#endif
            } else {
                ProgressView()
            }
        }
        .frame(width: readerWidth, height: pageHeight)
        .background(readerBackground)
        .shadow(color: .black.opacity(0.045), radius: 5, y: 1)
        .frame(maxWidth: .infinity, maxHeight: .infinity))
    }

    private var pageDescription: String {
        guard let page = viewModel.currentPage else { return "" }
        return "Page \(page.pageNumber) of \(viewModel.pageCount)"
    }

    private var contentsPopover: some View {
        TableOfContentsView(
            nav: viewModel.nav,
            sectionPageIndex: viewModel.sectionPageIndex,
            referencePageIndex: viewModel.tocReferencePageIndex,
            currentSectionId: viewModel.currentPage?.sectionId
        ) { node in
            showTOC = false
            Task { await viewModel.goToTOCNode(node) }
        }
        .frame(minWidth: 390, idealWidth: 430, minHeight: 520)
        .foregroundStyle(.primary)
        .preferredColorScheme(readerColorScheme)
    }

    private var searchPopover: some View {
        SearchSheetView(
            query: $searchQuery,
            results: viewModel.searchResults,
            sectionPageIndex: viewModel.sectionPageIndex,
            isSearching: viewModel.isSearching
        ) { result in
            showSearch = false
            Task {
                await viewModel.selectSearchResult(result, query: searchQuery)
            }
        }
        .frame(minWidth: 390, idealWidth: 430, minHeight: 480)
        .foregroundStyle(.primary)
        .preferredColorScheme(readerColorScheme)
        .onChange(of: searchQuery) { _, newValue in
            Task { await viewModel.search(query: newValue) }
        }
    }

    private var bookmarksPopover: some View {
        BookmarksSheetView(bookKey: viewModel.book.bookKey) { bookmark in
            showBookmarks = false
            Task { await viewModel.goToBookmark(bookmark) }
        }
        .frame(minWidth: 360, idealWidth: 400, minHeight: 420)
        .foregroundStyle(.primary)
        .preferredColorScheme(readerColorScheme)
    }

    private func toggleControls() {
        guard !isExiting else { return }
        controlsHideTask?.cancel()
        withAnimation(.easeInOut(duration: 0.18)) {
            showChrome.toggle()
        }
        if showChrome {
            scheduleControlsAutoHide()
        }
    }

    private func scheduleControlsAutoHide() {
        controlsHideTask?.cancel()
        controlsHideTask = Task {
            try? await Task.sleep(for: .seconds(4))
            guard !Task.isCancelled, !showTOC, !showSearch, !showBookmarks else { return }
            await MainActor.run {
                withAnimation(.easeInOut(duration: 0.2)) {
                    showChrome = false
                }
            }
        }
    }

    private func exitReader() {
        guard !isExiting else { return }
        isExiting = true
        controlsHideTask?.cancel()
        showSearch = false
        showTOC = false
        showBookmarks = false
        if let onExit {
            onExit()
        } else {
            dismiss()
        }
    }
}

#if DEBUG
private struct PaginationDebugOverlay: View {
    let layout: PageLayoutConfiguration
    let metrics: ReaderPageMetrics?
    let repeats: Int

    var body: some View {
        HStack(spacing: 0) {
            ForEach(0..<repeats, id: \.self) { _ in
                ZStack(alignment: .topLeading) {
                    debugFrame(layout.headerFrame, color: .red, label: "HEADER")
                    debugFrame(layout.contentFrame, color: .blue, label: "BODY")
                    debugFrame(layout.footerFrame, color: .green, label: "FOOTER")
                    ForEach(
                        Array((metrics?.blockMeasurements ?? []).enumerated()),
                        id: \.offset
                    ) { index, block in
                        debugFrame(
                            ReaderRect(
                                x: layout.contentFrame.x + block.frame.x,
                                y: layout.contentFrame.y + block.frame.y,
                                width: block.frame.width,
                                height: block.frame.height
                            ),
                            color: .orange,
                            label: "\(index) \(block.semanticType)"
                        )
                    }
                }
                .frame(
                    width: CGFloat(layout.pageWidth),
                    height: CGFloat(layout.pageHeight)
                )
            }
        }
        .allowsHitTesting(false)
    }

    private func debugFrame(
        _ frame: ReaderRect,
        color: Color,
        label: String
    ) -> some View {
        Rectangle()
            .stroke(color, style: StrokeStyle(lineWidth: 1, dash: [4, 3]))
            .overlay(alignment: .topLeading) {
                Text(label)
                    .font(.system(size: 8, weight: .bold, design: .monospaced))
                    .foregroundStyle(color)
                    .padding(2)
            }
            .frame(width: CGFloat(frame.width), height: CGFloat(frame.height))
            .offset(x: CGFloat(frame.x), y: CGFloat(frame.y))
    }
}
#endif

private struct ReaderControlsOverlay<
    SearchContent: View,
    ContentsContent: View,
    BookmarksContent: View
>: View {
    let pageDescription: String
    let isBookmarked: Bool
    let isExiting: Bool
    let onClose: () -> Void
    let onSearch: () -> Void
    let onContents: () -> Void
    let onBookmark: () -> Void
    @Binding var showSearch: Bool
    @Binding var showContents: Bool
    @Binding var showBookmarks: Bool
    @ViewBuilder let searchContent: () -> SearchContent
    @ViewBuilder let contentsContent: () -> ContentsContent
    @ViewBuilder let bookmarksContent: () -> BookmarksContent

    var body: some View {
        HStack(alignment: .top, spacing: 14) {
            Button(action: onClose) {
                Image(systemName: "xmark")
            }
            .accessibilityLabel("Close manual")
            .frame(width: 42, height: 42)
            .background(IPCAReaderTheme.navy)
            .clipShape(Circle())
            .contentShape(Circle())
            .foregroundStyle(.white)
            .disabled(isExiting)
            .zIndex(10)

            Spacer(minLength: 0)

            VStack(spacing: 3) {
                HStack(spacing: 5) {
                    controlButton("magnifyingglass", label: "Search", action: onSearch)
                        .popover(isPresented: $showSearch, arrowEdge: .top) {
                            searchContent()
                        }

                    controlButton("list.bullet", label: "Table of contents", action: onContents)
                        .popover(isPresented: $showContents, arrowEdge: .top) {
                            contentsContent()
                        }

                    controlButton(
                        isBookmarked ? "bookmark.fill" : "bookmark",
                        label: isBookmarked ? "Remove bookmark" : "Bookmark page",
                        action: onBookmark
                    )

                    controlButton(
                        "book.pages",
                        label: "Show bookmarks",
                        action: { showBookmarks = true }
                    )
                    .popover(isPresented: $showBookmarks, arrowEdge: .top) {
                        bookmarksContent()
                    }
                }

                if !pageDescription.isEmpty {
                    Text(pageDescription)
                        .font(.caption2.weight(.medium))
                        .foregroundStyle(.white.opacity(0.72))
                        .monospacedDigit()
                }
            }
            .padding(.horizontal, 10)
            .padding(.vertical, 5)
            .background(.ultraThinMaterial)
            .background(IPCAReaderTheme.navy.opacity(0.94))
            .clipShape(Capsule())
            .shadow(color: .black.opacity(0.2), radius: 10, y: 4)
            .foregroundStyle(.white)

            Spacer(minLength: 56)
        }
        .font(.body.weight(.semibold))
        .buttonStyle(.plain)
    }

    private func controlButton(
        _ systemName: String,
        label: String,
        action: @escaping () -> Void
    ) -> some View {
        Button(action: action) {
            Image(systemName: systemName)
                .frame(width: 36, height: 36)
                .contentShape(Rectangle())
        }
        .accessibilityLabel(label)
    }
}

struct TableOfContentsView: View {
    let nav: [NavNode]
    let sectionPageIndex: [Int: Int]
    let referencePageIndex: [String: Int]
    let currentSectionId: Int?
    let onSelect: (NavNode) -> Void

    var body: some View {
        NavigationStack {
            List {
                ForEach(flatten(nav), id: \.id) { row in
                    if row.node.isSeparator == true {
                        Divider()
                            .listRowBackground(Color.clear)
                            .listRowSeparator(.hidden)
                    } else if row.node.isNavigable == true,
                              pageNumber(for: row.node) != nil {
                        Button {
                            onSelect(row.node)
                        } label: {
                            tocRowLabel(row)
                        }
                        .buttonStyle(.plain)
                        .listRowBackground(Color.white)
                    } else {
                        tocRowLabel(row)
                            .listRowBackground(Color.white)
                    }
                }
            }
            .scrollContentBackground(.hidden)
            .background(Color.white)
            .navigationTitle("Contents")
            .navigationBarTitleDisplayMode(.inline)
        }
        .background(Color.white)
        .preferredColorScheme(.light)
    }

    private struct TocRow: Identifiable {
        var id: String
        var node: NavNode
        var depth: Int
    }

    @ViewBuilder
    private func tocRowLabel(_ row: TocRow) -> some View {
        HStack {
            Text(row.node.title ?? "Section")
                .font(row.node.isGroup == true || row.depth == 0 ? .headline : .body)
                .foregroundStyle(Color.black)
                .padding(.leading, CGFloat(row.depth) * 16)
            Spacer()
            if let page = pageNumber(for: row.node) {
                Text("\(page)")
                    .foregroundStyle(Color.black.opacity(0.65))
                    .monospacedDigit()
            }
        }
    }

    private func pageNumber(for node: NavNode) -> Int? {
        if let reference = normalizedReference(node.scrollSectionRef),
           let page = referencePageIndex[reference] {
            return page
        }
        guard let sectionID = node.id, sectionID > 0 else { return nil }
        return sectionPageIndex[sectionID]
    }

    private func normalizedReference(_ reference: String?) -> String? {
        guard let reference else { return nil }
        let normalized = reference.trimmingCharacters(in: CharacterSet(charactersIn: ". "))
        return normalized.isEmpty ? nil : normalized
    }

    private func subtitleLevel(_ node: NavNode) -> Int? {
        guard node.labelStyle == "subtitle",
              let reference = normalizedReference(node.scrollSectionRef) else {
            return nil
        }
        return max(0, reference.split(separator: ".").count - 1)
    }

    private func flatten(
        _ nodes: [NavNode],
        depth: Int = 0,
        path: String = "root"
    ) -> [TocRow] {
        var rows: [TocRow] = []
        for (index, node) in nodes.enumerated() {
            let nodePath = "\(path).\(index)"
            if let level = subtitleLevel(node), level > 2 {
                continue
            }
            if node.isSeparator == true || !(node.title ?? "").trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
                rows.append(TocRow(id: "\(nodePath)-\(node.nodeID)", node: node, depth: depth))
            }
            if let children = node.children, !children.isEmpty {
                rows.append(
                    contentsOf: flatten(
                        children,
                        depth: depth + 1,
                        path: nodePath
                    )
                )
            }
        }
        return rows
    }
}

struct SearchSheetView: View {
    @Binding var query: String
    let results: [SearchResult]
    let sectionPageIndex: [Int: Int]
    let isSearching: Bool
    let onSelect: (SearchResult) -> Void

    var body: some View {
        NavigationStack {
            List {
                if isSearching {
                    ProgressView()
                }
                ForEach(results) { result in
                    Button {
                        onSelect(result)
                    } label: {
                        VStack(alignment: .leading, spacing: 4) {
                            HStack {
                                Text(result.sectionTitle)
                                    .foregroundStyle(Color.black)
                                Spacer()
                                if let page = result.pageNumber ?? sectionPageIndex[result.sectionId] {
                                    Text("p. \(page)")
                                        .foregroundStyle(.secondary)
                                }
                            }
                            if let excerpt = result.excerpt, !excerpt.isEmpty {
                                highlightedText(excerpt)
                                    .font(.caption)
                                    .lineLimit(3)
                            }
                        }
                    }
                    .buttonStyle(.plain)
                    .listRowBackground(Color.white)
                }
            }
            .scrollContentBackground(.hidden)
            .background(Color.white)
            .searchable(text: $query, prompt: "Search manual")
            .navigationTitle("Search")
            .navigationBarTitleDisplayMode(.inline)
        }
        .preferredColorScheme(.light)
    }

    private func highlightedText(_ value: String) -> Text {
        var attributed = AttributedString(value)
        attributed.foregroundColor = .black
        let needle = query.trimmingCharacters(in: .whitespacesAndNewlines)
        if !needle.isEmpty,
           let range = attributed.range(of: needle, options: .caseInsensitive) {
            attributed[range].backgroundColor = .yellow
            attributed[range].foregroundColor = .black
        }
        return Text(attributed)
    }
}

struct BookmarksSheetView: View {
    @ObservedObject private var session = ManualReaderSessionStore.shared
    let bookKey: String
    let onSelect: (LocalBookmark) -> Void

    var body: some View {
        NavigationStack {
            List {
                ForEach(session.bookmarks(for: bookKey)) { bookmark in
                    Button {
                        onSelect(bookmark)
                    } label: {
                        HStack {
                            Text(bookmark.label)
                            Spacer()
                            Text("p. \(bookmark.pageNumber)")
                                .foregroundStyle(.secondary)
                        }
                    }
                }
                .onDelete { indexSet in
                    let items = session.bookmarks(for: bookKey)
                    indexSet.map { items[$0] }.forEach(session.removeBookmark)
                }
            }
            .navigationTitle("Bookmarks")
            .navigationBarTitleDisplayMode(.inline)
        }
    }
}

