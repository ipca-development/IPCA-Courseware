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
    @State private var pageRenderFailures: Set<Int> = []
    @State private var pendingExternalURL: URL?
    @State private var pendingTextSelection: ReaderTextSelection?
    @State private var focusedPageIndex: Int?
    @State private var selectionPageIndex: Int?
    @State private var showHighlightColors = false
    @State private var showPersonalNoteEditor = false
    @State private var personalNoteDraft = ""
    @State private var showReviewerThread = false
    @State private var reviewerNoteDraft = ""
    @State private var isPreparingAnnexPDF = false
    @State private var annexShareError: String?
    private let onExit: (() -> Void)?
    private let initialBookmark: LocalBookmark?
    private let initialHighlight: TextHighlightAnchor?

    init(
        book: LibraryBook,
        initialBookmark: LocalBookmark? = nil,
        initialHighlight: TextHighlightAnchor? = nil,
        onExit: (() -> Void)? = nil
    ) {
        _viewModel = StateObject(wrappedValue: ReaderViewModel(book: book))
        self.initialBookmark = initialBookmark
        self.initialHighlight = initialHighlight
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
            let failedPages = requiredPages.intersection(pageRenderFailures)
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
                        .opacity(isOpening ? 0 : 1)
                        .allowsHitTesting(!isOpening)
                        .accessibilityHidden(isOpening)
                }

                if isOpening {
                    Color.white
                        .ignoresSafeArea()
                    VStack(spacing: 14) {
                        if failedPages.isEmpty {
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
                        } else {
                            Image(systemName: "exclamationmark.triangle")
                                .font(.title2)
                                .foregroundStyle(.orange)
                            Text("The visible pages did not reach stable layout.")
                                .font(.subheadline.weight(.medium))
                            Button("Retry Rendering") {
                                pageRenderFailures.subtract(requiredPages)
                                renderedPages.subtract(requiredPages)
                                Task { await viewModel.reloadCurrentPageStyles() }
                            }
                            .buttonStyle(.borderedProminent)
                            .tint(IPCAReaderTheme.navy)
                        }
                    }
                    .padding(24)
                    .background(Color.white, in: RoundedRectangle(cornerRadius: 14))
                    .zIndex(10)
                }

                if isPreparingAnnexPDF {
                    ProgressView("Preparing Annex PDF…")
                        .padding(22)
                        .background(.regularMaterial, in: RoundedRectangle(cornerRadius: 14))
                        .zIndex(250)
                }

                if showChrome && !isOpening {
                    ReaderControlsOverlay(
                        pageDescription: pageDescription,
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

                if pendingTextSelection != nil && !isOpening {
                    VStack(spacing: 8) {
                        Spacer()
                        if showHighlightColors {
                            HighlightColorMenu(
                                onSelect: applyHighlightColor,
                                onBack: { showHighlightColors = false }
                            )
                        } else {
                            ReaderSelectionActionMenu(
                                hasHighlight: selectedHighlight != nil,
                                canAddReviewerNote: session.canAddReviewerNotes
                                    && viewModel.book.isDraftPreview,
                                onHighlight: { showHighlightColors = true },
                                onPersonalNote: openPersonalNoteEditor,
                                onReviewerNote: openReviewerNoteEditor,
                                onRemoveHighlight: removeSelectedHighlight,
                                onDismiss: dismissSelectionMenu
                            )
                        }
                    }
                    .padding(.horizontal, 20)
                    .padding(.bottom, max(28, proxy.safeAreaInsets.bottom + 18))
                    .transition(.move(edge: .bottom).combined(with: .opacity))
                    .zIndex(200)
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
            if let initialHighlight {
                await viewModel.goToHighlight(initialHighlight)
            } else if let initialBookmark {
                await viewModel.goToBookmark(initialBookmark)
            }
        }
        .onChange(of: viewModel.currentIndex) { _, newIndex in
            focusedPageIndex = nil
            dismissSelectionMenu()
            showPersonalNoteEditor = false
            showReviewerThread = false
            Task { await viewModel.goToIndex(newIndex) }
        }
        .onChange(of: viewModel.htmlGeneration) { _, _ in
            renderedPages.removeAll()
            pageRenderFailures.removeAll()
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
        .alert(
            "Unable to Share Annex",
            isPresented: Binding(
                get: { annexShareError != nil },
                set: { if !$0 { annexShareError = nil } }
            )
        ) {
            Button("OK", role: .cancel) { annexShareError = nil }
        } message: {
            Text(annexShareError ?? "")
        }
        .sheet(isPresented: $showPersonalNoteEditor) {
            PersonalNoteEditorSheet(
                note: $personalNoteDraft,
                hasHighlight: selectedHighlight != nil,
                hasPersonalNote: !(selectedHighlight?.personalNote ?? "").isEmpty,
                onSave: savePersonalNote,
                onRemoveNote: removePersonalNote,
                onRemoveHighlight: removeSelectedHighlight
            )
        }
        .sheet(isPresented: $showReviewerThread) {
            ReviewerConversationSheet(
                selectedText: pendingTextSelection?.selectedText ?? "",
                thread: pendingTextSelection.flatMap {
                    viewModel.reviewThread(matching: $0, at: selectionTargetIndex)
                },
                isLoading: viewModel.isLoadingReviewThreads,
                errorMessage: viewModel.reviewErrorMessage,
                pendingNotes: session.pendingReviewNotes.filter {
                    $0.bookKey == viewModel.book.bookKey
                        && $0.versionID == viewModel.book.versionId
                        && $0.pageNumber
                            == (viewModel.pages.indices.contains(selectionTargetIndex)
                                ? viewModel.pages[selectionTargetIndex].pageNumber
                                : -1)
                },
                draft: $reviewerNoteDraft,
                onSend: sendReviewerNote,
                onOpenInBook: { showReviewerThread = false }
            )
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
                    onTap: { pageIndex in
                        focusedPageIndex = pageIndex
                        controlsHideTask?.cancel()
                        withAnimation(.easeInOut(duration: 0.18)) {
                            showChrome = true
                        }
                        scheduleControlsAutoHide()
                    },
                    onPageReady: {
                        pageRenderFailures.remove($0)
                        renderedPages.insert($0)
                    },
                    onPageRenderFailure: {
                        renderedPages.remove($0)
                        pageRenderFailures.insert($0)
                    },
                    onToggleBookmark: { pageIndex in
                        let pageNumber = viewModel.pages.indices.contains(pageIndex)
                            ? viewModel.pages[pageIndex].pageNumber
                            : 0
                        viewModel.toggleBookmark(
                            at: pageIndex,
                            label: "Page \(pageNumber)"
                        )
                    },
                    onNavigateToAnchor: { anchor in
                        Task { await viewModel.goToStableAnchor(anchor) }
                    },
                    onNavigateToSection: { sectionID in
                        Task { await viewModel.goToSection(sectionID) }
                    },
                    onShareAnnex: { sectionID in
                        shareAnnexPDF(sectionID: sectionID)
                    },
                    onExternalLink: { url in
                        if url.path.hasSuffix("/student/api/manual_reader_annex_pdf.php") {
                            shareAnnexPDF(from: url)
                        } else {
                            pendingExternalURL = url
                        }
                    },
                    onTextSelection: { pageIndex, selection in
                        focusedPageIndex = pageIndex
                        selectionPageIndex = pageIndex
                        pendingTextSelection = selection
                        showHighlightColors = false
                        if selection.opensPersonalNote == true,
                           let highlightID = selection.existingHighlightID,
                           let highlight = session.highlight(id: highlightID),
                           !(highlight.personalNote ?? "").isEmpty {
                            personalNoteDraft = highlight.personalNote ?? ""
                            showPersonalNoteEditor = true
                        } else if selection.opensReviewerNote == true {
                            reviewerNoteDraft = ""
                            showReviewerThread = true
                        }
                    }
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
        guard viewModel.pages.indices.contains(selectedPageIndex) else { return "" }
        let page = viewModel.pages[selectedPageIndex]
        return "Page \(page.pageNumber) of \(viewModel.pageCount)"
    }

    private var selectedPageIndex: Int {
        guard let focusedPageIndex, viewModel.pages.indices.contains(focusedPageIndex) else {
            return viewModel.currentIndex
        }
        return focusedPageIndex
    }

    private var selectionTargetIndex: Int {
        guard let selectionPageIndex,
              viewModel.pages.indices.contains(selectionPageIndex) else {
            return selectedPageIndex
        }
        return selectionPageIndex
    }

    private var selectedHighlight: TextHighlightAnchor? {
        guard let pendingTextSelection else { return nil }
        return viewModel.highlight(
            matching: pendingTextSelection,
            at: selectionTargetIndex
        )
    }

    private func applyHighlightColor(_ color: ReaderHighlightColor) {
        guard let selection = pendingTextSelection else { return }
        let index = selectionTargetIndex
        dismissSelectionMenu()
        Task { await viewModel.addHighlight(selection, color: color, at: index) }
    }

    private func openPersonalNoteEditor() {
        personalNoteDraft = selectedHighlight?.personalNote ?? ""
        showPersonalNoteEditor = true
    }

    private func savePersonalNote() {
        guard let selection = pendingTextSelection else { return }
        let note = personalNoteDraft
        let index = selectionTargetIndex
        showPersonalNoteEditor = false
        dismissSelectionMenu()
        Task {
            await viewModel.savePersonalNote(note, selection: selection, at: index)
        }
    }

    private func removePersonalNote() {
        guard let highlight = selectedHighlight else { return }
        let index = selectionTargetIndex
        viewModel.removePersonalNote(from: highlight, at: index)
        showPersonalNoteEditor = false
        dismissSelectionMenu()
    }

    private func removeSelectedHighlight() {
        guard let highlight = selectedHighlight else { return }
        viewModel.removeHighlight(highlight, at: selectionTargetIndex)
        showPersonalNoteEditor = false
        dismissSelectionMenu()
    }

    private func openReviewerNoteEditor() {
        reviewerNoteDraft = ""
        showReviewerThread = true
        Task { await viewModel.loadReviewThreads() }
    }

    private func sendReviewerNote() {
        guard let selection = pendingTextSelection else { return }
        let text = reviewerNoteDraft.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !text.isEmpty else { return }
        reviewerNoteDraft = ""
        Task {
            await viewModel.sendReviewerNote(
                text,
                selection: selection,
                at: selectionTargetIndex
            )
        }
    }

    private func shareAnnexPDF(from url: URL) {
        guard !isPreparingAnnexPDF, let client = session.client else { return }
        isPreparingAnnexPDF = true
        Task {
            do {
                let (data, response) = try await client.session.data(from: url)
                guard let http = response as? HTTPURLResponse,
                      (200...299).contains(http.statusCode),
                      http.mimeType == "application/pdf",
                      !data.isEmpty else {
                    let message = String(data: data, encoding: .utf8)
                        ?? "The server did not return an Annex PDF."
                    throw ManualReaderAPIError.badResponse(message)
                }
                let disposition = http.value(forHTTPHeaderField: "Content-Disposition") ?? ""
                let filenamePart = disposition.components(separatedBy: "filename=").last
                let suggestedName = filenamePart?.trimmingCharacters(
                    in: CharacterSet(charactersIn: "\"' ")
                )
                let fileURL = FileManager.default.temporaryDirectory
                    .appendingPathComponent(suggestedName ?? "Annex.pdf")
                try data.write(to: fileURL, options: .atomic)
                await MainActor.run {
                    isPreparingAnnexPDF = false
                    presentShareSheet(for: fileURL)
                }
            } catch {
                await MainActor.run {
                    isPreparingAnnexPDF = false
                    annexShareError = error.localizedDescription
                }
            }
        }
    }

    private func shareAnnexPDF(sectionID: Int) {
        guard let baseURL = session.baseURL else { return }
        var components = URLComponents(
            url: baseURL.appending(path: "student/api/manual_reader_annex_pdf.php"),
            resolvingAgainstBaseURL: false
        )
        components?.queryItems = [
            URLQueryItem(name: "book", value: viewModel.book.bookKey),
            URLQueryItem(name: "version_id", value: String(viewModel.book.versionId)),
            URLQueryItem(name: "section_id", value: String(sectionID)),
        ]
        guard let url = components?.url else {
            annexShareError = "The Annex PDF URL could not be created."
            return
        }
        shareAnnexPDF(from: url)
    }

    private func presentShareSheet(for fileURL: URL) {
        let controller = UIActivityViewController(
            activityItems: [fileURL],
            applicationActivities: nil
        )
        guard let scene = UIApplication.shared.connectedScenes
            .compactMap({ $0 as? UIWindowScene })
            .first(where: { $0.activationState == .foregroundActive }),
              let root = scene.windows.first(where: \.isKeyWindow)?.rootViewController else {
            annexShareError = "The share sheet could not be opened."
            return
        }
        var presenter = root
        while let presented = presenter.presentedViewController {
            presenter = presented
        }
        if let popover = controller.popoverPresentationController {
            popover.sourceView = presenter.view
            popover.sourceRect = CGRect(
                x: presenter.view.bounds.midX,
                y: presenter.view.bounds.midY,
                width: 1,
                height: 1
            )
        }
        presenter.present(controller, animated: true)
    }

    private func dismissSelectionMenu() {
        pendingTextSelection = nil
        selectionPageIndex = nil
        showHighlightColors = false
    }

    private var contentsPopover: some View {
        TableOfContentsView(
            nav: viewModel.nav,
            sectionPageIndex: viewModel.sectionPageIndex,
            referencePageIndex: viewModel.tocReferencePageIndex,
            currentSectionId: viewModel.currentPage?.sectionId,
            bookmarkedPageNumbers: Set(
                session.bookmarks(for: viewModel.book.bookKey).map(\.pageNumber)
            )
        ) { node in
            showTOC = false
            Task { await viewModel.goToTOCNode(node) }
        }
        .frame(minWidth: 507, idealWidth: 559, minHeight: 520)
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
    let isExiting: Bool
    let onClose: () -> Void
    let onSearch: () -> Void
    let onContents: () -> Void
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
    let bookmarkedPageNumbers: Set<Int>
    let onSelect: (NavNode) -> Void

    var body: some View {
        NavigationStack {
            List {
                ForEach(flatten(nav), id: \.id) { row in
                    if row.node.isNavigable == true,
                              pageNumber(for: row.node) != nil {
                        Button {
                            onSelect(row.node)
                        } label: {
                            tocRowLabel(row)
                        }
                        .buttonStyle(.plain)
                        .listRowBackground(Color.white)
                        .listRowInsets(rowInsets(for: row))
                    } else {
                        tocRowLabel(row)
                            .listRowBackground(Color.white)
                            .listRowInsets(rowInsets(for: row))
                    }
                }
            }
            .listRowSpacing(0)
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
                .font(row.node.isGroup == true || row.depth == 0 ? .subheadline.weight(.semibold) : .footnote)
                .foregroundStyle(Color.black)
                .padding(.leading, CGFloat(row.depth) * 16)
                .lineLimit(1)
                .minimumScaleFactor(0.72)
            Spacer()
            if let page = pageNumber(for: row.node) {
                if bookmarkedPageNumbers.contains(page) {
                    Image(systemName: "bookmark.fill")
                        .font(.caption2)
                        .foregroundStyle(IPCAReaderTheme.navy)
                        .accessibilityLabel("Bookmarked")
                }
                Text("\(page)")
                    .foregroundStyle(Color.black.opacity(0.65))
                    .monospacedDigit()
            }
        }
    }

    private func rowInsets(for row: TocRow) -> EdgeInsets {
        EdgeInsets(
            top: row.node.isGroup == true ? 3 : 5,
            leading: 16,
            bottom: row.node.isGroup == true ? 3 : 5,
            trailing: 16
        )
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
            if node.isSeparator != true
                && !(node.title ?? "").trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
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
            .safeAreaInset(edge: .top, spacing: 0) {
                HStack(spacing: 8) {
                    Image(systemName: "magnifyingglass")
                        .foregroundStyle(Color.black.opacity(0.65))
                    TextField("Search manual", text: $query)
                        .foregroundStyle(Color.black)
                        .tint(IPCAReaderTheme.navy)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()
                    if !query.isEmpty {
                        Button {
                            query = ""
                        } label: {
                            Image(systemName: "xmark.circle.fill")
                                .foregroundStyle(Color.black.opacity(0.65))
                        }
                        .buttonStyle(.plain)
                    }
                }
                .padding(.horizontal, 12)
                .frame(height: 44)
                .background(
                    Color(uiColor: .secondarySystemBackground),
                    in: RoundedRectangle(cornerRadius: 13, style: .continuous)
                )
                .padding(.horizontal, 12)
                .padding(.vertical, 8)
                .background(Color.white)
            }
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
                                .foregroundStyle(Color.black)
                            Spacer()
                            Text("p. \(bookmark.pageNumber)")
                                .foregroundStyle(Color.black)
                        }
                    }
                    .buttonStyle(.plain)
                    .listRowBackground(Color.white)
                }
                .onDelete { indexSet in
                    let items = session.bookmarks(for: bookKey)
                    indexSet.map { items[$0] }.forEach(session.removeBookmark)
                }
            }
            .scrollContentBackground(.hidden)
            .background(Color.white)
            .navigationTitle("Bookmarks")
            .navigationBarTitleDisplayMode(.inline)
        }
        .background(Color.white)
        .preferredColorScheme(.light)
    }
}

private struct ReaderSelectionActionMenu: View {
    let hasHighlight: Bool
    let canAddReviewerNote: Bool
    let onHighlight: () -> Void
    let onPersonalNote: () -> Void
    let onReviewerNote: () -> Void
    let onRemoveHighlight: () -> Void
    let onDismiss: () -> Void

    var body: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 0) {
                menuButton("Highlight", action: onHighlight)
                Divider().frame(height: 28)
                menuButton("Add Personal Note", action: onPersonalNote)
                if canAddReviewerNote {
                    Divider().frame(height: 28)
                    menuButton("Add Reviewer Note", action: onReviewerNote)
                }
                if hasHighlight {
                    Divider().frame(height: 28)
                    menuButton("Remove Highlight", color: .red, action: onRemoveHighlight)
                }
                Divider().frame(height: 28)
                Button(action: onDismiss) {
                    Image(systemName: "xmark")
                        .frame(width: 42, height: 42)
                }
                .foregroundStyle(Color.black)
            }
            .padding(.horizontal, 8)
        }
        .fixedSize(horizontal: false, vertical: true)
        .background(.ultraThickMaterial, in: Capsule())
        .environment(\.colorScheme, .light)
        .shadow(color: .black.opacity(0.18), radius: 12, y: 4)
    }

    private func menuButton(
        _ title: String,
        color: Color = .black,
        action: @escaping () -> Void
    ) -> some View {
        Button(title, action: action)
            .font(.subheadline.weight(.semibold))
            .foregroundStyle(color)
            .padding(.horizontal, 16)
            .frame(height: 48)
            .buttonStyle(.plain)
    }
}

private struct HighlightColorMenu: View {
    let onSelect: (ReaderHighlightColor) -> Void
    let onBack: () -> Void

    var body: some View {
        HStack(spacing: 18) {
            Button(action: onBack) {
                Image(systemName: "chevron.left")
                    .foregroundStyle(Color.black)
                    .frame(width: 36, height: 36)
            }
            ForEach(ReaderHighlightColor.allCases) { color in
                Button { onSelect(color) } label: {
                    Circle()
                        .fill(Color(hex: color.cssColor))
                        .frame(width: 30, height: 30)
                        .overlay(Circle().stroke(Color.black.opacity(0.12), lineWidth: 1))
                }
                .accessibilityLabel(color.label)
            }
        }
        .padding(.horizontal, 18)
        .padding(.vertical, 10)
        .background(.ultraThickMaterial, in: Capsule())
        .environment(\.colorScheme, .light)
        .shadow(color: .black.opacity(0.18), radius: 12, y: 4)
    }
}

private struct PersonalNoteEditorSheet: View {
    @Environment(\.dismiss) private var dismiss
    @State private var confirmDeletion = false
    @Binding var note: String
    let hasHighlight: Bool
    let hasPersonalNote: Bool
    let onSave: () -> Void
    let onRemoveNote: () -> Void
    let onRemoveHighlight: () -> Void

    var body: some View {
        NavigationStack {
            VStack(alignment: .leading, spacing: 14) {
                TextEditor(text: $note)
                    .foregroundStyle(Color.black)
                    .scrollContentBackground(.hidden)
                    .padding(8)
                    .background(
                        RoundedRectangle(cornerRadius: 10)
                            .fill(Color(uiColor: .secondarySystemBackground))
                    )
                if hasHighlight {
                    HStack {
                        if hasPersonalNote {
                            Button("Delete Note", role: .destructive) {
                                confirmDeletion = true
                            }
                        }
                        Spacer()
                        Button("Remove Highlight", role: .destructive, action: onRemoveHighlight)
                    }
                }
            }
            .padding(18)
            .navigationTitle("Personal Note")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Save", action: onSave)
                        .disabled(note.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
                }
            }
        }
        .preferredColorScheme(.light)
        .alert("Confirm you want to delete your personal note?", isPresented: $confirmDeletion) {
            Button("NO", role: .cancel) {}
            Button("YES", role: .destructive, action: onRemoveNote)
        }
    }
}

struct ReviewerConversationSheet: View {
    @Environment(\.dismiss) private var dismiss
    @ObservedObject private var session = ManualReaderSessionStore.shared
    let selectedText: String
    let thread: ReviewNoteThread?
    let isLoading: Bool
    let errorMessage: String?
    let pendingNotes: [PendingReviewNote]
    @Binding var draft: String
    let onSend: () -> Void
    let onOpenInBook: (() -> Void)?

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                Text(selectedText)
                    .font(.callout)
                    .foregroundStyle(.white)
                    .lineLimit(4)
                    .padding(12)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .background(IPCAReaderTheme.navy)

                if isLoading {
                    ProgressView("Loading reviewer conversation…")
                        .frame(maxWidth: .infinity, maxHeight: .infinity)
                } else {
                    ScrollView {
                        LazyVStack(spacing: 12) {
                            ForEach(thread?.comments ?? []) { comment in
                                reviewerComment(comment)
                            }
                            ForEach(pendingNotes) { note in
                                HStack {
                                    Spacer(minLength: 48)
                                    VStack(alignment: .trailing, spacing: 3) {
                                        Text("Pending sync")
                                            .font(.caption2)
                                            .foregroundStyle(.orange)
                                        Text(note.body)
                                            .foregroundStyle(.white)
                                            .padding(.horizontal, 13)
                                            .padding(.vertical, 9)
                                            .background(
                                                IPCAReaderTheme.navy.opacity(0.72),
                                                in: RoundedRectangle(cornerRadius: 16)
                                            )
                                    }
                                }
                            }
                            if thread?.comments.isEmpty != false && pendingNotes.isEmpty {
                                ContentUnavailableView(
                                    "Start the Review",
                                    systemImage: "bubble.left.and.bubble.right",
                                    description: Text(
                                        "Your reviewer note will be visible to approved reviewers and in the online editor."
                                    )
                                )
                                .padding(.top, 40)
                            }
                        }
                        .padding(16)
                    }
                }

                if let errorMessage, !errorMessage.isEmpty {
                    Text(errorMessage)
                        .font(.caption)
                        .foregroundStyle(.red)
                        .padding(.horizontal)
                }

                VStack(spacing: 8) {
                    HStack(alignment: .bottom, spacing: 8) {
                        TextField("Reviewer note", text: $draft, axis: .vertical)
                            .foregroundStyle(Color.black)
                            .lineLimit(1...5)
                            .padding(10)
                            .background(
                                Color(uiColor: .secondarySystemBackground),
                                in: RoundedRectangle(cornerRadius: 18)
                            )
                        Button(action: onSend) {
                            Image(systemName: "arrow.up.circle.fill")
                                .font(.system(size: 32))
                                .foregroundStyle(IPCAReaderTheme.navy)
                                .background(Circle().fill(Color.white))
                        }
                        .disabled(draft.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
                    }
                    Text("Regulation references will be enabled in the next phase.")
                        .font(.caption2)
                        .foregroundStyle(.white)
                }
                .padding(12)
                .background(IPCAReaderTheme.navy)
            }
            .navigationTitle("Reviewer Notes")
            .navigationBarTitleDisplayMode(.inline)
            .toolbarBackground(IPCAReaderTheme.navy, for: .navigationBar)
            .toolbarBackground(.visible, for: .navigationBar)
            .toolbarColorScheme(.dark, for: .navigationBar)
            .toolbar {
                if let onOpenInBook {
                    ToolbarItem(placement: .navigationBarLeading) {
                        Button {
                            dismiss()
                            onOpenInBook()
                        } label: {
                            Label("Open in Book", systemImage: "book")
                        }
                    }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Done") { dismiss() }
                }
            }
        }
        .preferredColorScheme(.light)
    }

    private func reviewerComment(_ comment: ReviewNoteComment) -> some View {
        let isCurrentUser = comment.author.id == session.user?.id
        return HStack(alignment: .bottom, spacing: 8) {
            if isCurrentUser { Spacer(minLength: 48) }
            if !isCurrentUser { reviewerAvatar(comment.author) }
            VStack(alignment: isCurrentUser ? .trailing : .leading, spacing: 3) {
                Text(comment.author.name)
                    .font(.caption2.weight(.semibold))
                    .foregroundStyle(.secondary)
                Text(comment.body)
                    .foregroundStyle(isCurrentUser ? Color.white : Color.black)
                    .padding(.horizontal, 13)
                    .padding(.vertical, 9)
                    .background(
                        isCurrentUser ? IPCAReaderTheme.navy : Color(uiColor: .secondarySystemBackground),
                        in: RoundedRectangle(cornerRadius: 16)
                    )
            }
            if isCurrentUser { reviewerAvatar(comment.author) }
            if !isCurrentUser { Spacer(minLength: 48) }
        }
    }

    @ViewBuilder
    private func reviewerAvatar(_ author: ReviewNoteAuthor) -> some View {
        let url = ManualReaderAPIClient.absoluteURL(
            from: author.photoURL,
            baseURL: session.baseURL ?? URL(fileURLWithPath: "/")
        )
        if let url {
            AsyncImage(url: url) { image in
                image.resizable().scaledToFill()
            } placeholder: {
                initialsAvatar(author.initials)
            }
            .frame(width: 34, height: 34)
            .clipShape(Circle())
        } else {
            initialsAvatar(author.initials)
        }
    }

    private func initialsAvatar(_ initials: String) -> some View {
        Text(initials)
            .font(.caption2.bold())
            .foregroundStyle(.white)
            .frame(width: 34, height: 34)
            .background(IPCAReaderTheme.navy, in: Circle())
    }
}

private extension Color {
    init(hex: String) {
        let value = UInt64(hex.trimmingCharacters(in: CharacterSet(charactersIn: "#")), radix: 16) ?? 0
        self.init(
            .sRGB,
            red: Double((value >> 16) & 0xff) / 255,
            green: Double((value >> 8) & 0xff) / 255,
            blue: Double(value & 0xff) / 255,
            opacity: 1
        )
    }
}

