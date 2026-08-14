import SwiftUI

struct ReaderView: View {
    @Environment(\.dismiss) private var dismiss
    @ObservedObject private var session = ManualReaderSessionStore.shared
    @ObservedObject private var downloads = ManualDownloadManager.shared
    @StateObject private var viewModel: ReaderViewModel

    @State private var showChrome = false
    @State private var showTOC = false
    @State private var showSearch = false
    @State private var showSettings = false
    @State private var searchQuery = ""
    @State private var controlsHideTask: Task<Void, Never>?

    init(book: LibraryBook) {
        _viewModel = StateObject(wrappedValue: ReaderViewModel(book: book))
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
            ZStack {
                readerBackground.ignoresSafeArea()

                if viewModel.isLoading {
                    VStack(spacing: 14) {
                        ProgressView(value: openingProgress)
                            .progressViewStyle(.linear)
                            .frame(maxWidth: 280)
                        Text(openingMessage)
                            .font(.subheadline.weight(.medium))
                    }
                    .padding(24)
                } else if let error = viewModel.errorMessage, viewModel.pages.isEmpty {
                    ContentUnavailableView(
                        "Unable to Open Manual",
                        systemImage: "exclamationmark.triangle",
                        description: Text(error)
                    )
                } else {
                    physicalBookReader(size: safeSize)
                        .frame(width: safeSize.width, height: safeSize.height)
                        .offset(
                            x: (proxy.safeAreaInsets.leading - proxy.safeAreaInsets.trailing) / 2,
                            y: (proxy.safeAreaInsets.top - proxy.safeAreaInsets.bottom) / 2
                        )
                }

                if showChrome && !viewModel.isLoading {
                    ReaderControlsOverlay(
                        pageDescription: pageDescription,
                        isBookmarked: viewModel.isCurrentPageBookmarked(),
                        onClose: { dismiss() },
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
                        showSettings: $showSettings,
                        searchContent: { searchPopover },
                        contentsContent: { contentsPopover },
                        settingsContent: { settingsPopover },
                        onThemeSelected: applyTheme
                    )
                    .padding(.top, max(8, proxy.safeAreaInsets.top + 4))
                    .padding(.horizontal, 18)
                    .frame(maxHeight: .infinity, alignment: .top)
                    .transition(.opacity.combined(with: .move(edge: .top)))
                }
            }
            .task(
                id: "\(Int(safeSize.width))x\(Int(safeSize.height))-\(session.settings.fontSize.rawValue)"
            ) {
                await viewModel.updateLayout(
                    viewport: safeSize,
                    isLandscape: safeSize.width > safeSize.height
                )
            }
        }
        .statusBarHidden(true)
        .persistentSystemOverlays(.hidden)
        .task { await viewModel.load() }
        .onChange(of: viewModel.currentIndex) { _, newIndex in
            Task { await viewModel.goToIndex(newIndex) }
        }
        .onChange(of: session.settings) { _, _ in
            Task { await viewModel.reloadCurrentPageStyles() }
        }
        .onDisappear {
            controlsHideTask?.cancel()
        }
    }

    private var openingProgress: Double? {
        if case .downloading(let progress) = downloads.status(for: viewModel.book) {
            return progress
        }
        return nil
    }

    private var openingMessage: String {
        if case .downloading(let progress) = downloads.status(for: viewModel.book) {
            return "Downloading manual… \(Int(progress * 100))%"
        }
        return "Opening manual…"
    }

    private var readerBackground: some View {
        Color.white
    }

    private func physicalBookReader(size: CGSize) -> some View {
        let landscape = size.width > size.height
        let layout = viewModel.activeLayout ?? PageLayoutConfiguration.make(
            viewport: size,
            isLandscape: landscape,
            fontScale: session.settings.fontSize.scale
        )
        let pageWidth = CGFloat(layout.pageWidth)
        let pageHeight = CGFloat(layout.pageHeight)
        let readerWidth = landscape ? pageWidth * 2 : pageWidth

        return ZStack {
            if let baseURL = session.baseURL, !viewModel.pageHTMLByIndex.isEmpty {
                BookPageCurlView(
                    pages: viewModel.pages,
                    htmlByIndex: viewModel.pageHTMLByIndex,
                    baseURL: baseURL,
                    isLandscape: landscape,
                    pageSize: CGSize(width: pageWidth, height: pageHeight),
                    currentIndex: $viewModel.currentIndex,
                    onTap: toggleControls
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
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }

    private var pageDescription: String {
        guard let page = viewModel.currentPage else { return "" }
        let readerPage = "Reader page \(page.pageNumber) of \(viewModel.pageCount)"
        if let officialPage = viewModel.currentOfficialLocation?.officialPageNumber {
            return "\(readerPage) · Official page \(officialPage)"
        }
        return readerPage
    }

    private var contentsPopover: some View {
        TableOfContentsView(
            nav: viewModel.nav,
            sectionPageIndex: viewModel.sectionPageIndex,
            currentSectionId: viewModel.currentPage?.sectionId
        ) { sectionId in
            showTOC = false
            Task { await viewModel.goToSection(sectionId) }
        }
        .frame(minWidth: 390, idealWidth: 430, minHeight: 520)
    }

    private var searchPopover: some View {
        SearchSheetView(
            query: $searchQuery,
            results: viewModel.searchResults,
            sectionPageIndex: viewModel.sectionPageIndex,
            isSearching: viewModel.isSearching
        ) { sectionId in
            showSearch = false
            Task { await viewModel.goToSection(sectionId) }
        }
        .frame(minWidth: 390, idealWidth: 430, minHeight: 480)
        .onChange(of: searchQuery) { _, newValue in
            Task { await viewModel.search(query: newValue) }
        }
    }

    private var settingsPopover: some View {
        CompactReaderSettingsView {
            Task { await viewModel.reloadCurrentPageStyles() }
        }
        .frame(width: 320)
    }

    private func toggleControls() {
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
            guard !Task.isCancelled, !showTOC, !showSearch, !showSettings else { return }
            await MainActor.run {
                withAnimation(.easeInOut(duration: 0.2)) {
                    showChrome = false
                }
            }
        }
    }

    private func applyTheme(_ theme: ReaderTheme) {
        session.settings.theme = theme
        session.saveSettings()
        Task { await viewModel.reloadCurrentPageStyles() }
        scheduleControlsAutoHide()
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

private struct ReaderControlsOverlay<SearchContent: View, ContentsContent: View, SettingsContent: View>: View {
    let pageDescription: String
    let isBookmarked: Bool
    let onClose: () -> Void
    let onSearch: () -> Void
    let onContents: () -> Void
    let onBookmark: () -> Void
    @Binding var showSearch: Bool
    @Binding var showContents: Bool
    @Binding var showSettings: Bool
    @ViewBuilder let searchContent: () -> SearchContent
    @ViewBuilder let contentsContent: () -> ContentsContent
    @ViewBuilder let settingsContent: () -> SettingsContent
    let onThemeSelected: (ReaderTheme) -> Void

    var body: some View {
        HStack(alignment: .top, spacing: 14) {
            Button(action: onClose) {
                Image(systemName: "xmark")
            }
            .accessibilityLabel("Close manual")
            .frame(width: 42, height: 42)
            .background(IPCAReaderTheme.navy)
            .clipShape(Circle())

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

                    Button {
                        showSettings = true
                    } label: {
                        Text("AA")
                            .font(.system(size: 14, weight: .semibold, design: .rounded))
                            .frame(width: 36, height: 36)
                    }
                    .accessibilityLabel("Reader settings")
                    .popover(isPresented: $showSettings, arrowEdge: .top) {
                        settingsContent()
                    }

                    Menu {
                        ForEach(ReaderTheme.allCases) { theme in
                            Button(theme.label) {
                                onThemeSelected(theme)
                            }
                        }
                    } label: {
                        Image(systemName: "circle.lefthalf.filled")
                            .frame(width: 36, height: 36)
                    }
                    .accessibilityLabel("Reader theme")
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

            Spacer(minLength: 56)
        }
        .foregroundStyle(.white)
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

private struct CompactReaderSettingsView: View {
    @ObservedObject private var session = ManualReaderSessionStore.shared
    let onChanged: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 18) {
            Text("Reader Settings")
                .font(.headline)

            VStack(alignment: .leading, spacing: 8) {
                Text("Text size")
                    .font(.caption.weight(.semibold))
                    .foregroundStyle(.secondary)
                Picker("Text size", selection: $session.settings.fontSize) {
                    ForEach(ReaderFontSize.allCases) { size in
                        Text(size.label).tag(size)
                    }
                }
                .labelsHidden()
            }

            VStack(alignment: .leading, spacing: 8) {
                Text("Appearance")
                    .font(.caption.weight(.semibold))
                    .foregroundStyle(.secondary)
                Picker("Appearance", selection: $session.settings.theme) {
                    ForEach(ReaderTheme.allCases) { theme in
                        Text(theme.label).tag(theme)
                    }
                }
                .pickerStyle(.segmented)
                .labelsHidden()
            }
        }
        .padding(20)
        .onChange(of: session.settings.fontSize) { _, _ in
            session.saveSettings()
            onChanged()
        }
        .onChange(of: session.settings.theme) { _, _ in
            session.saveSettings()
            onChanged()
        }
    }
}

struct TableOfContentsView: View {
    let nav: [NavNode]
    let sectionPageIndex: [Int: Int]
    let currentSectionId: Int?
    let onSelect: (Int) -> Void

    var body: some View {
        NavigationStack {
            List {
                ForEach(flatten(nav), id: \.id) { row in
                    if row.node.isSeparator == true {
                        Divider()
                    } else {
                        Button {
                            if let sectionId = row.node.id {
                                onSelect(sectionId)
                            }
                        } label: {
                            HStack {
                                Text(row.node.title ?? "Section")
                                    .font(row.depth == 0 ? .headline : .body)
                                    .foregroundStyle(row.node.isNavigable == true ? .primary : .secondary)
                                    .padding(.leading, CGFloat(row.depth) * 16)
                                Spacer()
                                if let sectionId = row.node.id, let page = sectionPageIndex[sectionId] {
                                    Text("\(page)")
                                        .foregroundStyle(.secondary)
                                        .monospacedDigit()
                                }
                            }
                        }
                        .disabled(row.node.isNavigable != true || row.node.id == nil)
                    }
                }
            }
            .navigationTitle("Contents")
            .navigationBarTitleDisplayMode(.inline)
        }
    }

    private struct TocRow: Identifiable {
        var id: String
        var node: NavNode
        var depth: Int
    }

    private func flatten(_ nodes: [NavNode], depth: Int = 0) -> [TocRow] {
        var rows: [TocRow] = []
        for node in nodes {
            rows.append(TocRow(id: node.nodeID, node: node, depth: depth))
            if let children = node.children, !children.isEmpty {
                rows.append(contentsOf: flatten(children, depth: depth + 1))
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
    let onSelect: (Int) -> Void

    var body: some View {
        NavigationStack {
            List {
                if isSearching {
                    ProgressView()
                }
                ForEach(results) { result in
                    Button {
                        onSelect(result.sectionId)
                    } label: {
                        HStack {
                            Text(result.sectionTitle)
                            Spacer()
                            if let page = sectionPageIndex[result.sectionId] {
                                Text("p. \(page)")
                                    .foregroundStyle(.secondary)
                            }
                        }
                    }
                }
            }
            .searchable(text: $query, prompt: "Search section titles")
            .navigationTitle("Search")
            .navigationBarTitleDisplayMode(.inline)
        }
    }
}

struct BookmarksSheetView: View {
    @ObservedObject private var session = ManualReaderSessionStore.shared
    let bookKey: String
    let onSelect: (Int) -> Void

    var body: some View {
        NavigationStack {
            List {
                ForEach(session.bookmarks(for: bookKey)) { bookmark in
                    Button {
                        onSelect(bookmark.pageNumber)
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

struct ReaderSettingsView: View {
    @ObservedObject private var session = ManualReaderSessionStore.shared
    var showServer: Bool
    var onChanged: (() -> Void)?

    @State private var serverURL = ""
    @Environment(\.dismiss) private var dismiss

    init(showServer: Bool = false, onChanged: (() -> Void)? = nil) {
        self.showServer = showServer
        self.onChanged = onChanged
    }

    var body: some View {
        NavigationStack {
            Form {
                if showServer {
                    Section("Server") {
                        TextField("Server URL", text: $serverURL)
                            .textInputAutocapitalization(.never)
                            .autocorrectionDisabled()
                        Button("Save Server URL") {
                            try? session.setServerURL(serverURL)
                        }
                    }
                }

                Section("Appearance") {
                    Picker("Theme", selection: $session.settings.theme) {
                        ForEach(ReaderTheme.allCases) { theme in
                            Text(theme.label).tag(theme)
                        }
                    }
                    Picker("Text Size", selection: $session.settings.fontSize) {
                        ForEach(ReaderFontSize.allCases) { size in
                            Text(size.label).tag(size)
                        }
                    }
                }
            }
            .navigationTitle("Reading Settings")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .confirmationAction) {
                    Button("Done") {
                        session.saveSettings()
                        onChanged?()
                        dismiss()
                    }
                }
            }
            .onAppear {
                serverURL = session.baseURL?.absoluteString ?? ""
            }
            .onChange(of: session.settings.theme) { _, _ in session.saveSettings(); onChanged?() }
            .onChange(of: session.settings.fontSize) { _, _ in session.saveSettings(); onChanged?() }
        }
    }
}
