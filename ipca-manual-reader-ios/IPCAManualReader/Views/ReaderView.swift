import SwiftUI

struct ReaderView: View {
    @Environment(\.dismiss) private var dismiss
    @ObservedObject private var session = ManualReaderSessionStore.shared
    @ObservedObject private var downloads = ManualDownloadManager.shared
    @StateObject private var viewModel: ReaderViewModel

    @State private var showChrome = true
    @State private var showTOC = false
    @State private var showSearch = false
    @State private var showSettings = false
    @State private var showBookmarks = false
    @State private var searchQuery = ""
    @State private var containerSize: CGSize = .zero

    init(book: LibraryBook) {
        _viewModel = StateObject(wrappedValue: ReaderViewModel(book: book))
    }

    var body: some View {
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
                ContentUnavailableView("Unable to Open Manual", systemImage: "exclamationmark.triangle", description: Text(error))
            } else {
                pageReader
            }

            if showChrome {
                chromeOverlay
            }
        }
        .statusBarHidden(!showChrome)
        .persistentSystemOverlays(showChrome ? .automatic : .hidden)
        .sheet(isPresented: $showTOC) {
            TableOfContentsView(
                nav: viewModel.nav,
                sectionPageIndex: viewModel.sectionPageIndex,
                currentSectionId: viewModel.currentPage?.sectionId
            ) { sectionId in
                showTOC = false
                Task { await viewModel.goToSection(sectionId) }
            }
        }
        .sheet(isPresented: $showSearch) {
            SearchSheetView(
                query: $searchQuery,
                results: viewModel.searchResults,
                sectionPageIndex: viewModel.sectionPageIndex,
                isSearching: viewModel.isSearching
            ) { sectionId in
                showSearch = false
                Task { await viewModel.goToSection(sectionId) }
            }
            .onChange(of: searchQuery) { _, newValue in
                Task { await viewModel.search(query: newValue) }
            }
        }
        .sheet(isPresented: $showSettings) {
            ReaderSettingsView(showServer: false) {
                Task { await viewModel.reloadCurrentPageStyles() }
            }
        }
        .sheet(isPresented: $showBookmarks) {
            BookmarksSheetView(bookKey: viewModel.book.bookKey) { pageNumber in
                showBookmarks = false
                Task { await viewModel.goToPageNumber(pageNumber) }
            }
        }
        .task { await viewModel.load() }
        .onChange(of: session.settings) { _, _ in
            Task { await viewModel.reloadCurrentPageStyles() }
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
        Group {
            switch session.settings.theme {
            case .light: Color(red: 0.92, green: 0.92, blue: 0.94)
            case .sepia: Color(red: 0.91, green: 0.87, blue: 0.81)
            case .dark: Color(red: 0.11, green: 0.11, blue: 0.12)
            }
        }
    }

    private var pageReader: some View {
        GeometryReader { proxy in
            ZStack {
                if let baseURL = session.baseURL, !viewModel.currentPageHTML.isEmpty {
                    ManualPageWebView(
                        html: viewModel.currentPageHTML,
                        baseURL: baseURL,
                        zoomMode: session.settings.zoom,
                        containerSize: proxy.size
                    )
                    .id(viewModel.currentPage?.pageNumber ?? 0)
                    .transition(.asymmetric(
                        insertion: .move(edge: swipeEdge).combined(with: .opacity),
                        removal: .move(edge: swipeEdge == .leading ? .trailing : .leading).combined(with: .opacity)
                    ))
                } else {
                    ProgressView()
                }
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            .onAppear { containerSize = proxy.size }
            .onChange(of: proxy.size) { _, newSize in containerSize = newSize }
            .contentShape(Rectangle())
            .gesture(pageSwipeGesture)
            .onTapGesture {
                withAnimation(.easeInOut(duration: 0.2)) {
                    showChrome.toggle()
                }
            }
        }
    }

    @State private var swipeEdge: Edge = .trailing

    private var pageSwipeGesture: some Gesture {
        DragGesture(minimumDistance: 24, coordinateSpace: .local)
            .onEnded { value in
                let horizontal = value.translation.width
                if horizontal < -50 {
                    swipeEdge = .trailing
                    Task { await viewModel.nextPage() }
                } else if horizontal > 50 {
                    swipeEdge = .leading
                    Task { await viewModel.previousPage() }
                }
            }
    }

    private var chromeOverlay: some View {
        VStack(spacing: 0) {
            if viewModel.book.isDraftPreview {
                Text("Draft preview — not the official released manual")
                    .font(.caption.weight(.semibold))
                    .foregroundStyle(.orange)
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 8)
                    .background(Color.orange.opacity(0.15))
            }
            topBar
            Spacer()
            if session.settings.showFilmstrip {
                filmstrip
            }
            bottomBar
        }
        .transition(.opacity)
    }

    private var topBar: some View {
        HStack(spacing: 16) {
            Button { dismiss() } label: {
                Image(systemName: "chevron.backward")
                    .font(.body.weight(.semibold))
            }
            VStack(alignment: .leading, spacing: 2) {
                Text(viewModel.book.displayTitle)
                    .font(.headline)
                    .lineLimit(1)
                if let page = viewModel.currentPage {
                    Text("Page \(page.pageNumber) of \(viewModel.pageCount)")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }
            Spacer()
            Button { showSearch = true } label: { Image(systemName: "magnifyingglass") }
            Button { showTOC = true } label: { Image(systemName: "list.bullet") }
            Button {
                viewModel.toggleBookmark(label: "Page \(viewModel.currentPage?.pageNumber ?? 0)")
            } label: {
                Image(systemName: viewModel.isCurrentPageBookmarked() ? "bookmark.fill" : "bookmark")
            }
            Button { showSettings = true } label: { Image(systemName: "textformat.size") }
        }
        .padding(.horizontal, 20)
        .padding(.vertical, 12)
        .background(.ultraThinMaterial)
    }

    private var bottomBar: some View {
        HStack {
            Button {
                Task { await viewModel.previousPage() }
            } label: {
                Label("Previous", systemImage: "chevron.left")
            }
            .disabled(viewModel.currentIndex <= 0)

            Spacer()

            Button { showBookmarks = true } label: {
                Image(systemName: "book.closed")
            }

            Spacer()

            Button {
                Task { await viewModel.nextPage() }
            } label: {
                Label("Next", systemImage: "chevron.right")
            }
            .disabled(viewModel.currentIndex >= viewModel.pageCount - 1)
        }
        .labelStyle(.iconOnly)
        .font(.title3)
        .padding(.horizontal, 28)
        .padding(.vertical, 14)
        .background(.ultraThinMaterial)
    }

    private var filmstrip: some View {
        ScrollViewReader { proxy in
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 10) {
                    ForEach(Array(viewModel.pages.enumerated()), id: \.element.id) { index, page in
                        Button {
                            Task { await viewModel.goToIndex(index) }
                        } label: {
                            VStack(spacing: 4) {
                                RoundedRectangle(cornerRadius: 4)
                                    .fill(Color.white.opacity(page.isCover ? 0.35 : 0.18))
                                    .frame(width: 44, height: 58)
                                    .overlay {
                                        Text("\(page.pageNumber)")
                                            .font(.caption2.weight(.semibold))
                                    }
                                if page.isSectionStart {
                                    Circle()
                                        .fill(IPCAReaderTheme.accent)
                                        .frame(width: 4, height: 4)
                                }
                            }
                            .padding(4)
                            .background(index == viewModel.currentIndex ? Color.white.opacity(0.22) : Color.clear)
                            .clipShape(RoundedRectangle(cornerRadius: 8))
                        }
                        .buttonStyle(.plain)
                        .id(index)
                    }
                }
                .padding(.horizontal, 16)
                .padding(.vertical, 8)
            }
            .background(.ultraThinMaterial)
            .onChange(of: viewModel.currentIndex) { _, newIndex in
                withAnimation {
                    proxy.scrollTo(newIndex, anchor: .center)
                }
            }
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
                    Picker("Page Zoom", selection: $session.settings.zoom) {
                        ForEach(ReaderZoomMode.allCases) { mode in
                            Text(mode.label).tag(mode)
                        }
                    }
                    Toggle("Page Scrubber", isOn: $session.settings.showFilmstrip)
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
            .onChange(of: session.settings.zoom) { _, _ in session.saveSettings(); onChanged?() }
            .onChange(of: session.settings.showFilmstrip) { _, _ in session.saveSettings() }
        }
    }
}
