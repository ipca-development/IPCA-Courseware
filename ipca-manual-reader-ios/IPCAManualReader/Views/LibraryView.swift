import SwiftUI
import UIKit

struct LibraryView: View {
    @ObservedObject private var session = ManualReaderSessionStore.shared
    @ObservedObject private var downloads = ManualDownloadManager.shared
    @StateObject private var viewModel = LibraryViewModel()
    @State private var selectedBook: LibraryBook?
    @State private var selectedDestination: LibraryDestination = .library
    @State private var searchText = ""
    @State private var selectedFilter: LibraryFilter = .all
    @State private var selectedCategory: ManualCategory?
    @State private var utilityDestination: LibraryDestination?
    @State private var openingBook: LibraryBook?
    @State private var openingBookmark: LocalBookmark?
    @State private var openingHighlight: TextHighlightAnchor?

    var body: some View {
        GeometryReader { proxy in
            Group {
                if UIDevice.current.userInterfaceIdiom == .pad {
                    iPadLayout(size: proxy.size)
                } else {
                    iPhoneLayout
                }
            }
        }
        .background(IPCAReaderTheme.shelfBackground.ignoresSafeArea())
        .preferredColorScheme(.light)
        .overlay {
            if let openingBook {
                ZStack {
                    Color.black.opacity(0.34)
                        .ignoresSafeArea()
                    VStack(spacing: 14) {
                        ProgressView()
                            .controlSize(.large)
                        Text("Opening manual…")
                            .font(.headline)
                        Text(openingBook.displayTitle)
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                            .multilineTextAlignment(.center)
                    }
                    .padding(.horizontal, 30)
                    .padding(.vertical, 24)
                    .background(.regularMaterial, in: RoundedRectangle(cornerRadius: 16))
                }
                .transition(.opacity)
                .zIndex(100)
            }
        }
        .task { await viewModel.load() }
        .fullScreenCover(item: $selectedBook) { book in
            ReaderView(
                book: book,
                initialBookmark: openingBookmark,
                initialHighlight: openingHighlight
            ) {
                selectedBook = nil
                openingBookmark = nil
                openingHighlight = nil
            }
            .onAppear { openingBook = nil }
        }
        .sheet(item: $utilityDestination) { destination in
            NavigationStack {
                destinationContent(
                    destination,
                    isPhone: true,
                    availableWidth: UIScreen.main.bounds.width
                )
                .toolbar {
                    ToolbarItem(placement: .cancellationAction) {
                        Button("Done") { utilityDestination = nil }
                    }
                }
            }
        }
    }

    private func iPadLayout(size: CGSize) -> some View {
        let isLandscape = size.width > size.height
        let sidebarWidth: CGFloat = isLandscape ? 240 : 204
        return HStack(spacing: 0) {
            LibrarySidebar(
                width: sidebarWidth,
                selection: $selectedDestination,
                user: session.user,
                canReviewManuals: session.canAddReviewerNotes,
                onSignOut: { Task { await session.logout() } }
            )
            NavigationStack {
                destinationContent(
                    selectedDestination,
                    isPhone: false,
                    availableWidth: max(0, size.width - sidebarWidth)
                )
            }
        }
    }

    private var iPhoneLayout: some View {
        TabView(selection: $selectedDestination) {
            NavigationStack {
                destinationContent(.home, isPhone: true, availableWidth: UIScreen.main.bounds.width)
            }
            .tabItem { Label("Home", systemImage: "house.fill") }
            .tag(LibraryDestination.home)

            NavigationStack {
                destinationContent(.library, isPhone: true, availableWidth: UIScreen.main.bounds.width)
            }
            .tabItem { Label("My Library", systemImage: "books.vertical") }
            .tag(LibraryDestination.library)

            NavigationStack {
                destinationContent(.annexes, isPhone: true, availableWidth: UIScreen.main.bounds.width)
            }
            .tabItem { Label("Annexes", systemImage: "doc.on.doc") }
            .tag(LibraryDestination.annexes)

            NavigationStack {
                destinationContent(.downloads, isPhone: true, availableWidth: UIScreen.main.bounds.width)
            }
            .tabItem { Label("Downloads", systemImage: "arrow.down.to.line") }
            .tag(LibraryDestination.downloads)

            NavigationStack {
                MoreView(
                    user: session.user,
                    canReviewManuals: session.canAddReviewerNotes,
                    onOpen: { utilityDestination = $0 },
                    onSignOut: { Task { await session.logout() } }
                )
            }
            .tabItem { Label("More", systemImage: "ellipsis") }
            .tag(LibraryDestination.more)
        }
        .tint(IPCAReaderTheme.navy)
    }

    @ViewBuilder
    private func destinationContent(
        _ destination: LibraryDestination,
        isPhone: Bool,
        availableWidth: CGFloat
    ) -> some View {
        switch destination {
        case .reviewerNotes:
            ReviewerNotesLibraryView(books: viewModel.books) { book, thread in
                presentReader(book, highlight: thread.navigationAnchor)
            }
        case .personalNotes:
            PersonalNotesLibraryView(books: viewModel.books) { book, highlight in
                presentReader(book, highlight: highlight)
            }
        case .help:
            LibraryPlaceholderView(
                title: "Help & Support",
                message: "For assistance with manuals or access, contact IPCA support.",
                systemImage: "questionmark.circle"
            )
        case .more:
            MoreView(
                user: session.user,
                canReviewManuals: session.canAddReviewerNotes,
                onOpen: { utilityDestination = $0 },
                onSignOut: { Task { await session.logout() } }
            )
        default:
            LibraryContentView(
                books: viewModel.books,
                isLoading: viewModel.isLoading,
                errorMessage: viewModel.errorMessage,
                destination: destination,
                isPhone: isPhone,
                availableWidth: availableWidth,
                baseURL: session.baseURL,
                user: session.user,
                searchText: $searchText,
                selectedFilter: $selectedFilter,
                selectedCategory: $selectedCategory,
                onSelectBook: { presentReader($0) },
                onSelectBookmark: { book, bookmark in
                    presentReader(book, bookmark: bookmark)
                },
                onRetry: { Task { await viewModel.load() } }
            )
        }
    }

    private func presentReader(
        _ book: LibraryBook,
        bookmark: LocalBookmark? = nil,
        highlight: TextHighlightAnchor? = nil
    ) {
#if DEBUG
        print("READER_PRESENT_REQUEST book=\(book.id)")
#endif
        guard openingBook == nil, selectedBook == nil else { return }
        openingBookmark = bookmark
        openingHighlight = highlight
        openingBook = book
        Task { @MainActor in
            // Give SwiftUI one frame to paint immediate tap feedback before
            // constructing and presenting the full-screen reader.
            try? await Task.sleep(for: .milliseconds(80))
            guard openingBook?.id == book.id else { return }
            selectedBook = book
        }
    }

}

private enum LibraryDestination: String, CaseIterable, Identifiable {
    case home = "Home"
    case library = "My Library"
    case annexes = "Annexes"
    case downloads = "Downloads"
    case bookmarks = "Bookmarks"
    case personalNotes = "Personal Notes"
    case reviewerNotes = "Reviewer Notes"
    case help = "Help & Support"
    case more = "More"

    var id: String { rawValue }

    var systemImage: String {
        switch self {
        case .home: "house"
        case .library: "books.vertical"
        case .annexes: "doc.on.doc"
        case .downloads: "arrow.down.to.line"
        case .bookmarks: "bookmark"
        case .personalNotes: "note.text"
        case .reviewerNotes: "text.bubble"
        case .help: "questionmark.circle"
        case .more: "ellipsis"
        }
    }
}

private enum LibraryFilter: String, CaseIterable, Identifiable {
    case all = "All Manuals"
    case downloaded = "Downloaded"
    case inProgress = "In Progress"
    case completed = "Completed"
    case recentlyUpdated = "Recently Updated"

    var id: String { rawValue }
}

private struct ManualCategory: Identifiable, Hashable {
    let id: String

    var title: String { "\(id) Manuals" }
    var systemImage: String { "books.vertical" }
}

private extension LibraryBook {
    var category: ManualCategory {
        ManualCategory(id: bookKey.uppercased())
    }

    var searchableMetadata: String {
        [
            displayTitle,
            manualCode,
            bookKey,
            versionLabel,
            category.title,
            lifecycleStatus ?? "",
        ].joined(separator: " ").lowercased()
    }

    var lifecycleBadgeLabel: String {
        switch lifecycleStatus?.trimmingCharacters(in: .whitespacesAndNewlines).lowercased() {
        case "released": "Approved"
        case "in_review": "Draft Review"
        case "approved": "Awaiting Approval"
        default: "Draft"
        }
    }

    var lifecycleBadgeColor: Color {
        lifecycleStatus?.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
            == "released" ? Color.green : Color.orange
    }
}

private struct LibrarySidebar: View {
    let width: CGFloat
    @Binding var selection: LibraryDestination
    let user: ReaderUser?
    let canReviewManuals: Bool
    let onSignOut: () -> Void

    private var primaryItems: [LibraryDestination] {
        var items: [LibraryDestination] = [
            .home, .library, .annexes, .downloads, .bookmarks, .personalNotes,
        ]
        if canReviewManuals { items.append(.reviewerNotes) }
        items.append(.help)
        return items
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            Image("IPCALogoWhite")
                .resizable()
                .scaledToFit()
                .frame(maxWidth: width - 52, maxHeight: 92, alignment: .leading)
                .padding(.horizontal, 26)
                .padding(.top, 28)
                .padding(.bottom, 30)

            VStack(spacing: 7) {
                ForEach(primaryItems) { item in
                    Button {
                        selection = item
                    } label: {
                        Label(item.rawValue, systemImage: item.systemImage)
                            .font(.subheadline.weight(selection == item ? .semibold : .medium))
                            .frame(maxWidth: .infinity, alignment: .leading)
                            .padding(.horizontal, 16)
                            .frame(height: 46)
                            .background(
                                RoundedRectangle(cornerRadius: 11, style: .continuous)
                                    .fill(selection == item ? Color.white.opacity(0.09) : .clear)
                            )
                    }
                    .buttonStyle(.plain)
                    .foregroundStyle(.white.opacity(selection == item ? 1 : 0.82))
                }
            }
            .padding(.horizontal, 14)

            Spacer(minLength: 24)

            Menu {
                Button("Sign Out", role: .destructive, action: onSignOut)
            } label: {
                UserProfileCard(user: user, compact: width < 220)
            }
            .buttonStyle(.plain)
            .padding(14)
        }
        .frame(width: width)
        .background(
            LinearGradient(
                colors: [IPCAReaderTheme.navy, Color(red: 13 / 255, green: 30 / 255, blue: 53 / 255)],
                startPoint: .top,
                endPoint: .bottom
            )
            .ignoresSafeArea()
        )
    }
}

private struct UserProfileCard: View {
    let user: ReaderUser?
    let compact: Bool

    private var displayName: String {
        guard let user else { return "IPCA User" }
        return user.name.isEmpty ? user.email : user.name
    }

    private var roleName: String {
        guard let role = user?.role, !role.isEmpty else { return "Reader" }
        return role.replacingOccurrences(of: "_", with: " ").capitalized
    }

    private var initials: String {
        let parts = displayName.split(separator: " ")
        let value = parts.prefix(2).compactMap(\.first).map(String.init).joined()
        return value.isEmpty ? "IP" : value.uppercased()
    }

    var body: some View {
        HStack(spacing: 11) {
            Circle()
                .fill(Color.white.opacity(0.16))
                .overlay {
                    Text(initials)
                        .font(.caption.weight(.bold))
                        .foregroundStyle(.white)
                }
                .frame(width: 40, height: 40)

            if !compact {
                VStack(alignment: .leading, spacing: 2) {
                    Text(displayName)
                        .font(.caption.weight(.semibold))
                        .lineLimit(1)
                    Text(roleName)
                        .font(.caption2)
                        .foregroundStyle(.white.opacity(0.68))
                }
                Spacer(minLength: 0)
                Image(systemName: "chevron.right")
                    .font(.caption2.weight(.bold))
                    .foregroundStyle(.white.opacity(0.6))
            }
        }
        .foregroundStyle(.white)
        .padding(11)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(
            RoundedRectangle(cornerRadius: 12, style: .continuous)
                .fill(Color.white.opacity(0.055))
                .overlay {
                    RoundedRectangle(cornerRadius: 12, style: .continuous)
                        .stroke(Color.white.opacity(0.09), lineWidth: 1)
                }
        )
    }
}

private struct LibraryContentView: View {
    @ObservedObject private var downloads = ManualDownloadManager.shared

    let books: [LibraryBook]
    let isLoading: Bool
    let errorMessage: String?
    let destination: LibraryDestination
    let isPhone: Bool
    let availableWidth: CGFloat
    let baseURL: URL?
    let user: ReaderUser?
    @Binding var searchText: String
    @Binding var selectedFilter: LibraryFilter
    @Binding var selectedCategory: ManualCategory?
    let onSelectBook: (LibraryBook) -> Void
    let onSelectBookmark: (LibraryBook, LocalBookmark) -> Void
    let onRetry: () -> Void

    private var coverWidth: CGFloat {
        if isPhone { return 128 }
        return availableWidth > 900 ? 142 : 126
    }

    private var contentPadding: CGFloat {
        isPhone ? 20 : (availableWidth > 900 ? 34 : 28)
    }

    private var title: String {
        switch destination {
        case .home: "Home"
        case .downloads: "Downloads"
        case .annexes: "Annexes"
        case .bookmarks: "Bookmarks"
        case .personalNotes: "Personal Notes"
        default: "My Library"
        }
    }

    private var subtitle: String {
        switch destination {
        case .downloads: "Manuals available without an internet connection."
        case .annexes: "Annex books for the manuals available to your account."
        case .bookmarks: "Return to manuals containing your saved bookmarks."
        case .personalNotes: "Review your personal notes, grouped by manual."
        default: "All manuals and books, always at your fingertips."
        }
    }

    private var destinationBooks: [LibraryBook] {
        switch destination {
        case .annexes:
            return books.filter { $0.isAnnexBook && !$0.isDraftPreview }
        case .bookmarks, .personalNotes, .reviewerNotes, .help, .more:
            return books
        default:
            return books.filter { !$0.isAnnexBook }
        }
    }

    private var visibleBooks: [LibraryBook] {
        destinationBooks.filter { book in
            if destination == .downloads && !downloads.status(for: book).isAvailableOffline {
                return false
            }
            if destination == .bookmarks
                && ManualReaderSessionStore.shared.bookmarks(for: book.bookKey).isEmpty {
                return false
            }
            if destination != .annexes, let selectedCategory, book.category != selectedCategory {
                return false
            }
            let query = searchText.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
            if !query.isEmpty && !book.searchableMetadata.contains(query) {
                return false
            }
            switch selectedFilter {
            case .all:
                return true
            case .downloaded:
                return downloads.status(for: book).isAvailableOffline
            case .inProgress:
                return book.hasProgress && (downloads.readingProgress(for: book) ?? 0) < 0.995
            case .completed:
                return (downloads.readingProgress(for: book) ?? 0) >= 0.995
            case .recentlyUpdated:
                return book.releasedAt != nil || book.effectiveDate != nil
            }
        }
    }

    private var continueReading: [LibraryBook] {
        visibleBooks.filter(\.hasProgress)
    }

    private var categoryGroups: [(ManualCategory, [LibraryBook])] {
        availableCategories.compactMap { category, _ in
            let matches = visibleBooks.filter { $0.category == category }
            return matches.isEmpty ? nil : (category, matches)
        }
    }

    private var availableCategories: [(ManualCategory, Int)] {
        let grouped = Dictionary(grouping: books.filter { !$0.isAnnexBook }, by: \.category)
        return grouped
            .map { ($0.key, $0.value.count) }
            .sorted { $0.0.id.localizedStandardCompare($1.0.id) == .orderedAscending }
    }

    var body: some View {
        ScrollView {
            LazyVStack(alignment: .leading, spacing: isPhone ? 28 : 34) {
                LibraryHeader(title: title, subtitle: subtitle, user: user, isPhone: isPhone)
                searchAndFilters

                if isLoading && books.isEmpty {
                    ProgressView("Loading library…")
                        .frame(maxWidth: .infinity, minHeight: 280)
                } else if let errorMessage, books.isEmpty {
                    ContentUnavailableView {
                        Label("Could Not Load Library", systemImage: "exclamationmark.triangle")
                    } description: {
                        Text(errorMessage)
                    } actions: {
                        Button("Retry", action: onRetry)
                    }
                    .frame(maxWidth: .infinity, minHeight: 280)
                } else if destinationBooks.isEmpty {
                    ContentUnavailableView(
                        destination == .annexes ? "No Annexes" : "No Manuals",
                        systemImage: destination == .annexes ? "doc.on.doc" : "books.vertical",
                        description: Text(
                            destination == .annexes
                                ? "Annex books available to your account will appear here."
                                : "Manuals available to your account will appear here."
                        )
                    )
                    .frame(maxWidth: .infinity, minHeight: 280)
                } else if visibleBooks.isEmpty {
                    ContentUnavailableView.search(text: searchText)
                        .frame(maxWidth: .infinity, minHeight: 240)
                } else {
                    if destination == .bookmarks {
                        BookmarksByManualSection(
                            books: visibleBooks,
                            onSelectBook: onSelectBook,
                            onSelectBookmark: onSelectBookmark
                        )
                    } else {
                        if !continueReading.isEmpty {
                            ManualShelf(
                                title: "Continue Reading",
                                books: continueReading,
                                cardWidth: coverWidth,
                                baseURL: baseURL,
                                showsProgress: true,
                                showsRevisionMetadata: destination != .annexes,
                                onSelect: onSelectBook
                            )
                        }

                        if destination != .annexes && !availableCategories.isEmpty {
                            CategoriesSection(
                                categories: availableCategories,
                                selectedCategory: $selectedCategory,
                                availableWidth: availableWidth,
                                isPhone: isPhone
                            )
                        }

                        if destination == .annexes {
                            ManualShelf(
                                title: "Annexes",
                                books: visibleBooks,
                                cardWidth: coverWidth,
                                baseURL: baseURL,
                                showsProgress: false,
                                showsRevisionMetadata: false,
                                onSelect: onSelectBook
                            )
                        } else {
                            ForEach(categoryGroups, id: \.0.id) { category, categoryBooks in
                                ManualShelf(
                                    title: category.title,
                                    books: categoryBooks,
                                    cardWidth: coverWidth,
                                    baseURL: baseURL,
                                    showsProgress: false,
                                    showsRevisionMetadata: true,
                                    onSelect: onSelectBook
                                )
                            }
                        }
                    }
                }
            }
            .padding(.horizontal, contentPadding)
            .padding(.top, isPhone ? 18 : 30)
            .padding(.bottom, 36)
        }
        .background(IPCAReaderTheme.shelfBackground)
        .refreshable { onRetry() }
        .toolbar(.hidden, for: .navigationBar)
    }

    private var searchAndFilters: some View {
        HStack(spacing: 12) {
            HStack(spacing: 10) {
                Image(systemName: "magnifyingglass")
                    .foregroundStyle(.secondary)
                TextField("Search manuals and books", text: $searchText)
                    .textInputAutocapitalization(.never)
                    .autocorrectionDisabled()
                if !searchText.isEmpty {
                    Button {
                        searchText = ""
                    } label: {
                        Image(systemName: "xmark.circle.fill")
                            .foregroundStyle(.tertiary)
                    }
                    .buttonStyle(.plain)
                }
            }
            .padding(.horizontal, 14)
            .frame(height: isPhone ? 44 : 48)
            .background(
                RoundedRectangle(cornerRadius: 12, style: .continuous)
                    .fill(.white)
                    .overlay {
                        RoundedRectangle(cornerRadius: 12, style: .continuous)
                            .stroke(IPCAReaderTheme.divider)
                    }
                    .shadow(color: .black.opacity(0.035), radius: 7, y: 2)
            )

            Menu {
                Picker("Filter", selection: $selectedFilter) {
                    ForEach(LibraryFilter.allCases) { filter in
                        Text(filter.rawValue).tag(filter)
                    }
                }
                if selectedCategory != nil {
                    Divider()
                    Button("Clear Category") { selectedCategory = nil }
                }
            } label: {
                Label(isPhone ? "" : "Filters", systemImage: "line.3.horizontal.decrease")
                    .font(.subheadline.weight(.semibold))
                    .foregroundStyle(IPCAReaderTheme.navy)
                    .padding(.horizontal, isPhone ? 14 : 18)
                    .frame(height: isPhone ? 44 : 48)
                    .background(
                        RoundedRectangle(cornerRadius: 12, style: .continuous)
                            .fill(.white)
                            .overlay {
                                RoundedRectangle(cornerRadius: 12, style: .continuous)
                                    .stroke(IPCAReaderTheme.divider)
                            }
                    )
            }
        }
    }
}

private struct LibraryHeader: View {
    let title: String
    let subtitle: String
    let user: ReaderUser?
    let isPhone: Bool

    private var initials: String {
        let name = user?.name.isEmpty == false ? user?.name ?? "" : user?.email ?? "IP"
        return name.split(separator: " ").prefix(2).compactMap(\.first).map(String.init).joined().uppercased()
    }

    var body: some View {
        HStack(alignment: .top, spacing: 16) {
            VStack(alignment: .leading, spacing: 4) {
                if isPhone {
                    Image("IPCALogoWhite")
                        .resizable()
                        .scaledToFit()
                        .frame(width: 84, height: 42)
                        .padding(.horizontal, 10)
                        .background(
                            RoundedRectangle(cornerRadius: 8, style: .continuous)
                                .fill(IPCAReaderTheme.navy)
                        )
                        .padding(.bottom, 8)
                }
                Text(title)
                    .font(isPhone ? .title2.bold() : .largeTitle.bold())
                    .foregroundStyle(IPCAReaderTheme.navy)
                Text(subtitle)
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }
            Spacer()
            HStack(spacing: 12) {
                Button(action: {}) {
                    Image(systemName: "bell")
                        .font(.body.weight(.medium))
                        .frame(width: 38, height: 38)
                        .background(Circle().fill(.white))
                }
                .buttonStyle(.plain)
                .foregroundStyle(IPCAReaderTheme.navy)

                Circle()
                    .fill(IPCAReaderTheme.navy.opacity(0.1))
                    .overlay {
                        Text(initials.isEmpty ? "IP" : initials)
                            .font(.caption.weight(.bold))
                            .foregroundStyle(IPCAReaderTheme.navy)
                    }
                    .frame(width: 40, height: 40)
            }
        }
    }
}

private struct ManualShelf: View {
    @State private var showsAll = false

    let title: String
    let books: [LibraryBook]
    let cardWidth: CGFloat
    let baseURL: URL?
    let showsProgress: Bool
    var showsRevisionMetadata: Bool = true
    let onSelect: (LibraryBook) -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 14) {
            HStack {
                Text(title)
                    .font(.headline)
                    .foregroundStyle(IPCAReaderTheme.navy)
                Spacer()
                Button("See All") { showsAll = true }
                    .font(.caption.weight(.semibold))
                    .foregroundStyle(IPCAReaderTheme.navy)
            }

            ScrollView(.horizontal, showsIndicators: false) {
                LazyHStack(alignment: .top, spacing: 16) {
                    ForEach(books) { book in
                        ManualCoverCard(
                            book: book,
                            baseURL: baseURL,
                            width: cardWidth,
                            showsProgress: showsProgress,
                            showsRevisionMetadata: showsRevisionMetadata,
                            onSelect: { onSelect(book) }
                        )
                    }
                }
                .scrollTargetLayout()
                .padding(.vertical, 2)
                .padding(.trailing, cardWidth * 0.35)
            }
            .scrollTargetBehavior(.viewAligned)
        }
        .sheet(isPresented: $showsAll) {
            NavigationStack {
                ScrollView {
                    LazyVGrid(
                        columns: [GridItem(.adaptive(minimum: 128, maximum: 150), spacing: 22)],
                        alignment: .leading,
                        spacing: 24
                    ) {
                        ForEach(books) { book in
                            ManualCoverCard(
                                book: book,
                                baseURL: baseURL,
                                width: 138,
                                showsProgress: showsProgress,
                                showsRevisionMetadata: showsRevisionMetadata,
                                onSelect: {
                                    showsAll = false
                                    Task { @MainActor in
                                        try? await Task.sleep(for: .milliseconds(350))
                                        onSelect(book)
                                    }
                                }
                            )
                        }
                    }
                    .padding(24)
                }
                .background(IPCAReaderTheme.shelfBackground)
                .navigationTitle(title)
                .toolbar {
                    ToolbarItem(placement: .confirmationAction) {
                        Button("Done") { showsAll = false }
                    }
                }
            }
        }
    }
}

private struct ManualCoverCard: View {
    @ObservedObject private var downloads = ManualDownloadManager.shared

    let book: LibraryBook
    let baseURL: URL?
    let width: CGFloat
    let showsProgress: Bool
    var showsRevisionMetadata: Bool = true
    let onSelect: () -> Void

    private var resolvedCoverURL: URL? {
        guard let coverPath = book.coverPageThumbnailUrl,
              let baseURL else { return nil }
        return ManualReaderAPIClient.absoluteURL(
            from: coverPath,
            baseURL: baseURL
        )
    }

    private var cachedAuthoritativeCoverData: Data? {
        guard let package = downloads.packages[book.id],
              package.coverImageKind == "authoritative_page_thumbnail_v1" else { return nil }
        return package.coverImageData
    }

    private var progress: Double? {
        downloads.readingProgress(for: book)
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 9) {
            Button {
#if DEBUG
                print("READER_CARD_TAP book=\(book.id)")
#endif
                onSelect()
            } label: {
                cover
                    .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            .accessibilityLabel("Open \(book.displayTitle)")

            if showsRevisionMetadata {
                VStack(alignment: .leading, spacing: 5) {
                    Text(book.lifecycleBadgeLabel)
                        .font(.system(size: 9, weight: .bold))
                        .foregroundStyle(.white)
                        .padding(.horizontal, 7)
                        .padding(.vertical, 3)
                        .background(
                            Capsule(style: .continuous)
                                .fill(book.lifecycleBadgeColor.opacity(0.94))
                        )
                    Text("Revision \(book.versionLabel)")
                        .font(.caption.weight(.semibold))
                        .foregroundStyle(IPCAReaderTheme.navy)
                        .lineLimit(1)
                }
            }

            HStack(spacing: 8) {
                if showsProgress || book.hasProgress {
                    Text(progress.map { "\(Int($0 * 100))%" } ?? "Continue")
                        .font(.subheadline.weight(.semibold))
                        .foregroundStyle(.secondary)
                }
                Spacer(minLength: 4)

                primaryDownloadControl

                Menu {
                    downloadMenu
                } label: {
                    Image(systemName: "ellipsis")
                        .font(.title3.weight(.bold))
                        .foregroundStyle(.secondary)
                        .frame(width: 28, height: 28)
                }
                .buttonStyle(.plain)
            }
        }
        .frame(width: width, alignment: .leading)
        .contextMenu {
            downloadMenu
        }
    }

    private var cover: some View {
        ZStack(alignment: .topTrailing) {
            RoundedRectangle(cornerRadius: 10, style: .continuous)
                .fill(Color.white)
                .aspectRatio(0.68, contentMode: .fit)

            if let url = resolvedCoverURL {
                AuthenticatedCoverImage(
                    url: url,
                    cachedAuthoritativeData: cachedAuthoritativeCoverData
                )
                .frame(width: width, height: width / 0.68)
                .clipShape(RoundedRectangle(cornerRadius: 10, style: .continuous))
            } else if let data = cachedAuthoritativeCoverData,
                      let image = UIImage(data: data) {
                Image(uiImage: image)
                    .resizable()
                    .scaledToFit()
                    .frame(width: width, height: width / 0.68)
                    .clipShape(RoundedRectangle(cornerRadius: 10, style: .continuous))
            } else {
                CoverThumbnailUnavailableView()
            }
        }
        .frame(width: width, height: width / 0.68)
        .clipped()
        .overlay(
            RoundedRectangle(cornerRadius: 10, style: .continuous)
                .stroke(Color.black.opacity(0.12), lineWidth: 0.75)
        )
        .shadow(color: .black.opacity(0.28), radius: 10, x: 3, y: 7)
    }

    @ViewBuilder
    private var primaryDownloadControl: some View {
        switch downloads.status(for: book) {
        case .downloading(let progress):
            ProgressView(value: progress)
                .progressViewStyle(.circular)
                .frame(width: 28, height: 28)
        case .notDownloaded:
            Button(action: download) {
                Image(systemName: "icloud.and.arrow.down")
                    .font(.system(size: 20, weight: .light))
                    .foregroundStyle(Color.gray.opacity(0.48))
                    .frame(width: 28, height: 28)
            }
            .buttonStyle(.plain)
            .accessibilityLabel("Download manual")
        case .updateAvailable:
            Button(action: downloadUpdate) {
                Image(systemName: "icloud.and.arrow.down")
                    .font(.system(size: 20, weight: .light))
                    .foregroundStyle(.orange)
                    .frame(width: 28, height: 28)
            }
            .buttonStyle(.plain)
            .accessibilityLabel("Download update")
        case .availableOffline:
            Image(systemName: "icloud")
                .font(.system(size: 20, weight: .light))
                .foregroundStyle(Color.gray.opacity(0.48))
                .frame(width: 28, height: 28)
                .accessibilityLabel("Available offline")
        case .failed:
            Button(action: download) {
                Image(systemName: "exclamationmark.icloud")
                    .font(.system(size: 20, weight: .light))
                    .foregroundStyle(Color.gray.opacity(0.48))
                    .frame(width: 28, height: 28)
            }
            .buttonStyle(.plain)
            .accessibilityLabel("Retry download")
        }
    }

    @ViewBuilder
    private var downloadMenu: some View {
        switch downloads.status(for: book) {
        case .availableOffline:
            Text("Version \(book.versionLabel) · Available offline")
            Button("Re-download", action: downloadUpdate)
            Button("Delete Local Download", role: .destructive) {
                Task { await downloads.removeDownload(for: book) }
            }
        case .downloading:
            Text("Downloading…")
            Button("Cancel Download", role: .destructive) {
                Task { await downloads.removeDownload(for: book) }
            }
        case .updateAvailable(let priorVersion):
            Text("Update \(book.versionLabel) available · installed \(priorVersion)")
            Button("Download Update", action: downloadUpdate)
            Button("Delete Local Download", role: .destructive) {
                Task { await downloads.removeDownload(for: book) }
            }
        case .failed:
            Text("Download failed")
            Button("Retry Download", action: download)
            Button("Delete Local Download", role: .destructive) {
                Task { await downloads.removeDownload(for: book) }
            }
        default:
            Text("Not downloaded")
            Button("Download", action: download)
        }
        Button("Open Manual", action: onSelect)
    }

    private func download() {
        guard let client = ManualReaderSessionStore.shared.client else { return }
        Task { _ = try? await downloads.ensureDownloaded(book: book, client: client) }
    }

    private func downloadUpdate() {
        guard let client = ManualReaderSessionStore.shared.client else { return }
        Task {
            _ = try? await downloads.ensureDownloaded(
                book: book,
                client: client,
                forceRefresh: true
            )
        }
    }
}

private struct AuthenticatedCoverImage: View {
    let url: URL
    let cachedAuthoritativeData: Data?
    @State private var image: UIImage?
    @State private var failed = false
    @State private var failureMessage = ""
    @State private var retryRevision = 0

    var body: some View {
        Group {
            if let image {
                Image(uiImage: image)
                    .resizable()
                    .scaledToFit()
            } else if let cachedAuthoritativeData,
                      let fallback = UIImage(data: cachedAuthoritativeData) {
                Image(uiImage: fallback)
                    .resizable()
                    .scaledToFit()
            } else if failed {
                VStack(spacing: 8) {
                    Image(systemName: "exclamationmark.triangle")
                        .foregroundStyle(.secondary)
                    Text("Cover unavailable")
                        .font(.caption2)
                        .foregroundStyle(.secondary)
                    Button("Retry") {
                        failed = false
                        failureMessage = ""
                        retryRevision += 1
                    }
                    .font(.caption.weight(.semibold))
                }
            } else {
                ProgressView()
            }
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .background(Color.white)
        .task(id: "\(url.absoluteString)-\(retryRevision)") {
            guard let client = ManualReaderSessionStore.shared.client else {
                failureMessage = "Reader session is unavailable."
                failed = true
                return
            }
            for attempt in 0..<3 {
                do {
                    var request = URLRequest(url: url)
                    request.timeoutInterval = 65
                    request.cachePolicy = .reloadRevalidatingCacheData
                    let (data, response) = try await client.session.data(for: request)
                    guard let http = response as? HTTPURLResponse else {
                        throw ManualReaderAPIError.badResponse("No HTTP response.")
                    }
                    if http.statusCode == 401, attempt < 2 {
                        try await Task.sleep(for: .milliseconds(750 * (attempt + 1)))
                        continue
                    }
                    guard (200...299).contains(http.statusCode) else {
                        let detail = String(data: data, encoding: .utf8)?
                            .trimmingCharacters(in: .whitespacesAndNewlines)
                        throw ManualReaderAPIError.badResponse(
                            detail?.isEmpty == false ? detail! : "HTTP \(http.statusCode)"
                        )
                    }
                    guard http.mimeType == "image/png", let loaded = UIImage(data: data) else {
                        throw ManualReaderAPIError.badResponse(
                            "Expected PNG thumbnail; received \(http.mimeType ?? "unknown content")."
                        )
                    }
                    image = loaded
                    failureMessage = ""
                    failed = false
                    return
                } catch is CancellationError {
                    return
                } catch {
                    failureMessage = error.localizedDescription
#if DEBUG
                    print(
                        "READER_COVER_FAILED url=\(url.absoluteString) "
                            + "attempt=\(attempt + 1) error=\(failureMessage)"
                    )
#endif
                    failed = true
                    return
                }
            }
        }
    }
}

private struct CoverThumbnailUnavailableView: View {
    var body: some View {
        VStack(spacing: 8) {
            Image(systemName: "doc.richtext")
                .font(.title2)
                .foregroundStyle(Color.gray.opacity(0.55))
            Text("Cover unavailable")
                .font(.caption)
                .foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .background(Color.white)
    }
}

private struct BookmarksByManualSection: View {
    @ObservedObject private var session = ManualReaderSessionStore.shared
    let books: [LibraryBook]
    let onSelectBook: (LibraryBook) -> Void
    let onSelectBookmark: (LibraryBook, LocalBookmark) -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 18) {
            ForEach(books) { book in
                let bookmarks = session.bookmarks(for: book.bookKey)
                VStack(alignment: .leading, spacing: 10) {
                    Button { onSelectBook(book) } label: {
                        HStack {
                            Image(systemName: "book.closed.fill")
                                .foregroundStyle(IPCAReaderTheme.navy)
                            VStack(alignment: .leading, spacing: 2) {
                                Text(book.displayTitle)
                                    .font(.headline)
                                    .foregroundStyle(Color.black)
                                Text("\(book.manualCode) · \(bookmarks.count) bookmark\(bookmarks.count == 1 ? "" : "s")")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                            Spacer()
                            Image(systemName: "chevron.right")
                                .foregroundStyle(.secondary)
                        }
                    }
                    .buttonStyle(.plain)

                    ForEach(bookmarks) { bookmark in
                        Button { onSelectBookmark(book, bookmark) } label: {
                            HStack {
                                Image(systemName: "bookmark.fill")
                                    .foregroundStyle(IPCAReaderTheme.navy)
                                Text(bookmark.label)
                                    .foregroundStyle(Color.black)
                                Spacer()
                                Text("p. \(bookmark.pageNumber)")
                                    .foregroundStyle(.secondary)
                                    .monospacedDigit()
                            }
                            .padding(.leading, 8)
                        }
                        .buttonStyle(.plain)
                    }
                }
                .padding(16)
                .background(
                    RoundedRectangle(cornerRadius: 14, style: .continuous)
                        .fill(Color.white)
                )
            }
        }
    }
}

private struct PersonalNotesLibraryView: View {
    @ObservedObject private var session = ManualReaderSessionStore.shared
    @State private var expandedNoteIDs: Set<UUID> = []
    @State private var editingNote: TextHighlightAnchor?
    @State private var noteDraft = ""
    @State private var pendingDeletion: TextHighlightAnchor?
    let books: [LibraryBook]
    let onSelect: (LibraryBook, TextHighlightAnchor) -> Void

    private var groupedNotes: [(LibraryBook, [TextHighlightAnchor])] {
        books.compactMap { book in
            let notes = session.highlights
                .filter {
                    $0.bookKey == book.bookKey
                        && $0.deletedAt == nil
                        && !($0.personalNote ?? "").trimmingCharacters(
                            in: .whitespacesAndNewlines
                        ).isEmpty
                }
                .sorted { $0.createdAt > $1.createdAt }
            return notes.isEmpty ? nil : (book, notes)
        }
    }

    var body: some View {
        ScrollView {
            LazyVStack(alignment: .leading, spacing: 18) {
                LibraryHeader(
                    title: "Personal Notes",
                    subtitle: "Your private notes, grouped by manual.",
                    user: ManualReaderSessionStore.shared.user,
                    isPhone: UIDevice.current.userInterfaceIdiom != .pad
                )
                if groupedNotes.isEmpty {
                    ContentUnavailableView(
                        "No Personal Notes",
                        systemImage: "note.text",
                        description: Text("Notes added while reading will appear here.")
                    )
                    .frame(maxWidth: .infinity, minHeight: 280)
                } else {
                    ForEach(groupedNotes, id: \.0.id) { book, notes in
                        VStack(alignment: .leading, spacing: 10) {
                            Text(book.displayTitle)
                                .font(.headline)
                                .foregroundStyle(IPCAReaderTheme.navy)
                            ForEach(notes) { note in
                                VStack(spacing: 0) {
                                    Button {
                                        withAnimation(.easeInOut(duration: 0.18)) {
                                            if expandedNoteIDs.contains(note.id) {
                                                expandedNoteIDs.remove(note.id)
                                            } else {
                                                expandedNoteIDs.insert(note.id)
                                            }
                                        }
                                    } label: {
                                        HStack(alignment: .top, spacing: 10) {
                                            Rectangle()
                                                .fill(Color.yellow)
                                                .frame(width: 4)
                                            VStack(alignment: .leading, spacing: 4) {
                                                Text(note.personalNote ?? "")
                                                    .foregroundStyle(Color.black)
                                                    .multilineTextAlignment(.leading)
                                                Text("Page \(note.pageNumber) · \(note.selectedText)")
                                                    .font(.caption)
                                                    .foregroundStyle(.secondary)
                                                    .lineLimit(2)
                                            }
                                            Spacer()
                                            Image(
                                                systemName: expandedNoteIDs.contains(note.id)
                                                    ? "chevron.down"
                                                    : "chevron.right"
                                            )
                                            .foregroundStyle(.secondary)
                                        }
                                        .padding(14)
                                    }
                                    .buttonStyle(.plain)

                                    if expandedNoteIDs.contains(note.id) {
                                        Divider()
                                            .padding(.horizontal, 14)
                                        VStack(alignment: .leading, spacing: 12) {
                                            Text("Highlighted text")
                                                .font(.caption.weight(.semibold))
                                                .foregroundStyle(.secondary)
                                            Text(note.selectedText)
                                                .foregroundStyle(Color.black)
                                                .padding(10)
                                                .frame(maxWidth: .infinity, alignment: .leading)
                                                .background(
                                                    Color.yellow.opacity(0.2),
                                                    in: RoundedRectangle(cornerRadius: 8)
                                                )
                                            Text("Personal note")
                                                .font(.caption.weight(.semibold))
                                                .foregroundStyle(.secondary)
                                            Text(note.personalNote ?? "")
                                                .foregroundStyle(Color.black)
                                            Text(
                                                note.createdAt.formatted(
                                                    date: .abbreviated,
                                                    time: .shortened
                                                )
                                            )
                                            .font(.caption2)
                                            .foregroundStyle(.secondary)
                                            HStack {
                                                Button("Edit") {
                                                    noteDraft = note.personalNote ?? ""
                                                    editingNote = note
                                                }
                                                Button("Delete", role: .destructive) {
                                                    pendingDeletion = note
                                                }
                                                Spacer()
                                            }
                                            Button {
                                                onSelect(book, note)
                                            } label: {
                                                Label("Open in Book", systemImage: "book")
                                                    .frame(maxWidth: .infinity)
                                            }
                                            .buttonStyle(.borderedProminent)
                                            .tint(IPCAReaderTheme.navy)
                                        }
                                        .padding(14)
                                    }
                                }
                                .background(Color.white, in: RoundedRectangle(cornerRadius: 12))
                            }
                        }
                    }
                }
            }
            .padding(24)
        }
        .background(IPCAReaderTheme.shelfBackground)
        .navigationTitle("Personal Notes")
        .sheet(item: $editingNote) { note in
            NavigationStack {
                TextEditor(text: $noteDraft)
                    .foregroundStyle(Color.black)
                    .scrollContentBackground(.hidden)
                    .padding(14)
                    .background(Color(uiColor: .secondarySystemBackground))
                    .navigationTitle("Edit Personal Note")
                    .navigationBarTitleDisplayMode(.inline)
                    .toolbar {
                        ToolbarItem(placement: .cancellationAction) {
                            Button("Cancel") { editingNote = nil }
                        }
                        ToolbarItem(placement: .confirmationAction) {
                            Button("Save") {
                                session.updateHighlight(
                                    id: note.id,
                                    personalNote: .some(
                                        noteDraft.trimmingCharacters(
                                            in: .whitespacesAndNewlines
                                        )
                                    )
                                )
                                editingNote = nil
                                if let versionID = note.versionID {
                                    Task {
                                        await session.syncAnnotations(
                                            bookKey: note.bookKey,
                                            versionID: versionID
                                        )
                                    }
                                }
                            }
                            .disabled(
                                noteDraft.trimmingCharacters(
                                    in: .whitespacesAndNewlines
                                ).isEmpty
                            )
                        }
                    }
            }
            .preferredColorScheme(.light)
        }
        .alert(
            "Confirm you want to delete your personal note?",
            isPresented: Binding(
                get: { pendingDeletion != nil },
                set: { if !$0 { pendingDeletion = nil } }
            )
        ) {
            Button("NO", role: .cancel) { pendingDeletion = nil }
            Button("YES", role: .destructive) {
                guard let note = pendingDeletion else { return }
                session.updateHighlight(id: note.id, personalNote: .some(nil))
                expandedNoteIDs.remove(note.id)
                pendingDeletion = nil
                if let versionID = note.versionID {
                    Task {
                        await session.syncAnnotations(
                            bookKey: note.bookKey,
                            versionID: versionID
                        )
                    }
                }
            }
        }
    }
}

private struct CategoriesSection: View {
    let categories: [(ManualCategory, Int)]
    @Binding var selectedCategory: ManualCategory?
    let availableWidth: CGFloat
    let isPhone: Bool

    private var columns: [GridItem] {
        let count: Int
        if isPhone {
            count = 2
        } else if availableWidth > 850 {
            count = min(4, max(1, categories.count))
        } else {
            count = min(3, max(1, categories.count))
        }
        return Array(repeating: GridItem(.flexible(), spacing: 12), count: count)
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 14) {
            HStack {
                Text("Categories")
                    .font(.headline)
                    .foregroundStyle(IPCAReaderTheme.navy)
                Spacer()
                if selectedCategory != nil {
                    Button("Show All") { selectedCategory = nil }
                        .font(.caption.weight(.semibold))
                }
            }

            LazyVGrid(columns: columns, spacing: 12) {
                ForEach(categories, id: \.0.id) { category, count in
                    Button {
                        selectedCategory = selectedCategory == category ? nil : category
                    } label: {
                        VStack(spacing: 8) {
                            Image(systemName: category.systemImage)
                                .font(.title2)
                                .foregroundStyle(IPCAReaderTheme.navy)
                            Text(category.title)
                                .font(.caption.weight(.semibold))
                                .foregroundStyle(.primary)
                                .multilineTextAlignment(.center)
                                .lineLimit(2)
                            Text("\(count) \(count == 1 ? "Manual" : "Manuals")")
                                .font(.caption2)
                                .foregroundStyle(.secondary)
                        }
                        .frame(maxWidth: .infinity, minHeight: isPhone ? 92 : 104)
                        .padding(10)
                        .background(
                            RoundedRectangle(cornerRadius: 12, style: .continuous)
                                .fill(.white)
                                .overlay {
                                    RoundedRectangle(cornerRadius: 12, style: .continuous)
                                        .stroke(
                                            selectedCategory == category
                                                ? IPCAReaderTheme.navy.opacity(0.5)
                                                : IPCAReaderTheme.divider,
                                            lineWidth: selectedCategory == category ? 1.5 : 1
                                        )
                                }
                                .shadow(color: .black.opacity(0.025), radius: 5, y: 2)
                        )
                    }
                    .buttonStyle(.plain)
                }
            }
        }
    }
}

private extension ReviewNoteThread {
    var navigationAnchor: TextHighlightAnchor {
        TextHighlightAnchor(
            id: UUID(uuidString: threadUUID) ?? UUID(),
            bookKey: bookKey,
            versionID: versionID,
            pageNumber: pageNumber,
            selectedText: selectedText,
            sourceFragmentID: sourceFragmentID,
            stableAnchor: stableAnchor,
            startOffset: startOffset ?? 0,
            endOffset: endOffset ?? ((startOffset ?? 0) + selectedText.count),
            prefix: nil,
            suffix: nil,
            color: .fluorescentBlue,
            personalNote: nil,
            clientUpdatedAt: nil,
            deletedAt: nil,
            createdAt: Date()
        )
    }
}

private struct ReviewerThreadLibraryItem: Identifiable {
    let book: LibraryBook
    let thread: ReviewNoteThread
    var id: String { "\(book.id)-\(thread.threadUUID)" }
}

private struct ReviewerNotesLibraryView: View {
    @ObservedObject private var session = ManualReaderSessionStore.shared
    let books: [LibraryBook]
    let onOpen: (LibraryBook, ReviewNoteThread) -> Void
    @State private var items: [ReviewerThreadLibraryItem] = []
    @State private var selectedItem: ReviewerThreadLibraryItem?
    @State private var messageDraft = ""
    @State private var isLoading = true
    @State private var errorMessage: String?

    private var groupedItems: [(LibraryBook, [ReviewerThreadLibraryItem])] {
        books.compactMap { book in
            let matching = items.filter { $0.book.id == book.id }
            return matching.isEmpty ? nil : (book, matching)
        }
    }

    var body: some View {
        ScrollView {
            LazyVStack(alignment: .leading, spacing: 18) {
                LibraryHeader(
                    title: "Reviewer Notes",
                    subtitle: "Shared reviewer conversations, grouped by manual.",
                    user: session.user,
                    isPhone: UIDevice.current.userInterfaceIdiom != .pad
                )
                if isLoading {
                    ProgressView("Loading reviewer notes…")
                        .frame(maxWidth: .infinity, minHeight: 280)
                } else if let errorMessage, items.isEmpty {
                    ContentUnavailableView(
                        "Unable to Load Reviewer Notes",
                        systemImage: "exclamationmark.triangle",
                        description: Text(errorMessage)
                    )
                    .frame(maxWidth: .infinity, minHeight: 280)
                } else if groupedItems.isEmpty {
                    ContentUnavailableView(
                        "No Reviewer Notes",
                        systemImage: "text.bubble",
                        description: Text("Shared reviewer conversations will appear here.")
                    )
                    .frame(maxWidth: .infinity, minHeight: 280)
                } else {
                    ForEach(groupedItems, id: \.0.id) { book, threads in
                        VStack(alignment: .leading, spacing: 10) {
                            Text(book.displayTitle)
                                .font(.headline)
                                .foregroundStyle(IPCAReaderTheme.navy)
                            ForEach(threads) { item in
                                Button {
                                    messageDraft = ""
                                    selectedItem = item
                                } label: {
                                    HStack(alignment: .top, spacing: 10) {
                                        Rectangle()
                                            .fill(Color.blue)
                                            .frame(width: 4)
                                        VStack(alignment: .leading, spacing: 4) {
                                            Text(
                                                item.thread.comments.last?.body
                                                    ?? item.thread.selectedText
                                            )
                                            .foregroundStyle(Color.black)
                                            .multilineTextAlignment(.leading)
                                            .lineLimit(3)
                                            Text(
                                                "Page \(item.thread.pageNumber) · "
                                                    + item.thread.selectedText
                                            )
                                            .font(.caption)
                                            .foregroundStyle(.secondary)
                                            .lineLimit(2)
                                            if let lastComment = item.thread.comments.last {
                                                Text(
                                                    ReviewNoteTimestampFormatter.display(
                                                        lastComment.createdAtUTC
                                                    )
                                                )
                                                .font(.caption2)
                                                .foregroundStyle(.secondary)
                                            }
                                        }
                                        Spacer()
                                        VStack(spacing: 5) {
                                            Image(systemName: "bubble.left.and.bubble.right")
                                                .foregroundStyle(IPCAReaderTheme.navy)
                                            Text("\(item.thread.comments.count)")
                                                .font(.caption2.monospacedDigit())
                                                .foregroundStyle(.secondary)
                                        }
                                        Image(systemName: "chevron.right")
                                            .foregroundStyle(.secondary)
                                    }
                                    .padding(14)
                                    .frame(maxWidth: .infinity, alignment: .leading)
                                    .background(
                                        Color.white,
                                        in: RoundedRectangle(cornerRadius: 12)
                                    )
                                }
                                .buttonStyle(.plain)
                            }
                        }
                    }
                }
            }
            .padding(24)
        }
        .background(IPCAReaderTheme.shelfBackground)
        .navigationTitle("Reviewer Notes")
        .task(id: books.map { String($0.id) }.joined(separator: ",")) {
            await load(showLoading: true)
            while !Task.isCancelled {
                try? await Task.sleep(for: .seconds(5))
                if Task.isCancelled { break }
                await load(showLoading: false)
            }
        }
        .sheet(item: $selectedItem) { item in
            ReviewerConversationSheet(
                selectedText: item.thread.selectedText,
                thread: selectedItem?.thread ?? item.thread,
                isLoading: false,
                errorMessage: errorMessage,
                pendingNotes: session.pendingReviewNotes.filter {
                    $0.threadUUID == item.thread.threadUUID
                },
                draft: $messageDraft,
                onSend: { sendMessage(to: item) },
                onOpenInBook: {
                    let target = selectedItem ?? item
                    selectedItem = nil
                    DispatchQueue.main.async {
                        onOpen(target.book, target.thread)
                    }
                }
            )
        }
    }

    private func load(showLoading: Bool) async {
        guard ManualReaderSessionStore.shared.canAddReviewerNotes,
              let client = ManualReaderSessionStore.shared.client else {
            items = []
            isLoading = false
            return
        }
        if showLoading {
            isLoading = true
        }
        errorMessage = nil
        var loaded: [ReviewerThreadLibraryItem] = []
        var failures: [String] = []
        for book in books where book.isDraftPreview {
            do {
                let response = try await client.fetchReviewThreads(
                    bookKey: book.bookKey,
                    versionId: book.versionId
                )
                loaded.append(contentsOf: (response.threads ?? []).map {
                    ReviewerThreadLibraryItem(book: book, thread: $0)
                })
            } catch {
                failures.append("\(book.displayTitle): \(error.localizedDescription)")
            }
        }
        items = loaded.sorted { $0.thread.updatedAtUTC > $1.thread.updatedAtUTC }
        if let selectedItem,
           let refreshed = items.first(where: { $0.id == selectedItem.id }) {
            self.selectedItem = refreshed
        }
        errorMessage = failures.isEmpty ? nil : failures.joined(separator: "\n")
        if showLoading {
            isLoading = false
        }
    }

    private func sendMessage(to item: ReviewerThreadLibraryItem) {
        let text = messageDraft.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !text.isEmpty, let client = session.client else { return }
        messageDraft = ""
        Task {
            do {
                let updated = try await client.addReviewComment(
                    bookKey: item.book.bookKey,
                    versionId: item.book.versionId,
                    threadUUID: item.thread.threadUUID,
                    body: text
                )
                let replacement = ReviewerThreadLibraryItem(book: item.book, thread: updated)
                if let index = items.firstIndex(where: { $0.id == item.id }) {
                    items[index] = replacement
                }
                selectedItem = replacement
                errorMessage = nil
            } catch {
                messageDraft = text
                errorMessage = error.localizedDescription
            }
        }
    }
}

private struct MoreView: View {
    let user: ReaderUser?
    let canReviewManuals: Bool
    let onOpen: (LibraryDestination) -> Void
    let onSignOut: () -> Void

    var body: some View {
        List {
            Section {
                VStack(alignment: .leading, spacing: 4) {
                    Text(user?.name.isEmpty == false ? user?.name ?? "" : user?.email ?? "IPCA User")
                        .font(.headline)
                    Text((user?.role ?? "Reader").replacingOccurrences(of: "_", with: " ").capitalized)
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                }
            }
            Section {
                Button { onOpen(.bookmarks) } label: {
                    Label("Bookmarks", systemImage: "bookmark")
                }
                Button { onOpen(.personalNotes) } label: {
                    Label("Personal Notes", systemImage: "note.text")
                }
                if canReviewManuals {
                    Button { onOpen(.reviewerNotes) } label: {
                        Label("Reviewer Notes", systemImage: "text.bubble")
                    }
                }
                Button { onOpen(.help) } label: {
                    Label("Help & Support", systemImage: "questionmark.circle")
                }
            }
            Section {
                Button("Sign Out", role: .destructive, action: onSignOut)
            }
        }
        .navigationTitle("More")
        .tint(IPCAReaderTheme.navy)
    }
}

private struct LibraryPlaceholderView: View {
    let title: String
    let message: String
    let systemImage: String

    var body: some View {
        ContentUnavailableView(title, systemImage: systemImage, description: Text(message))
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            .background(IPCAReaderTheme.shelfBackground)
            .navigationTitle(title)
    }
}
