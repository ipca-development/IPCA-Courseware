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
            ReaderView(book: book, initialBookmark: openingBookmark) {
                selectedBook = nil
                openingBookmark = nil
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
                destinationContent(.categories, isPhone: true, availableWidth: UIScreen.main.bounds.width)
            }
            .tabItem { Label("Categories", systemImage: "square.grid.2x2") }
            .tag(LibraryDestination.categories)

            NavigationStack {
                destinationContent(.downloads, isPhone: true, availableWidth: UIScreen.main.bounds.width)
            }
            .tabItem { Label("Downloads", systemImage: "arrow.down.to.line") }
            .tag(LibraryDestination.downloads)

            NavigationStack {
                MoreView(
                    user: session.user,
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
        case .personalNotes:
            PersonalNotesLibraryView(books: viewModel.books) { book, highlight in
                presentReader(
                    book,
                    bookmark: LocalBookmark(
                        id: UUID(),
                        bookKey: highlight.bookKey,
                        versionID: highlight.versionID,
                        pageNumber: highlight.pageNumber,
                        label: "Personal Note",
                        createdAt: highlight.createdAt,
                        stableAnchor: highlight.stableAnchor,
                        blockAnchor: highlight.sourceFragmentID,
                        officialLocation: nil,
                        semanticLocation: nil,
                        personalReaderPageNumber: highlight.pageNumber,
                        clientUpdatedAt: highlight.clientUpdatedAt,
                        deletedAt: nil
                    )
                )
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

    private func presentReader(_ book: LibraryBook, bookmark: LocalBookmark? = nil) {
#if DEBUG
        print("READER_PRESENT_REQUEST book=\(book.id)")
#endif
        guard openingBook == nil, selectedBook == nil else { return }
        openingBookmark = bookmark
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
    case categories = "Categories"
    case downloads = "Downloads"
    case bookmarks = "Bookmarks"
    case personalNotes = "Personal Notes"
    case help = "Help & Support"
    case more = "More"

    var id: String { rawValue }

    var systemImage: String {
        switch self {
        case .home: "house"
        case .library: "books.vertical"
        case .categories: "square.grid.2x2"
        case .downloads: "arrow.down.to.line"
        case .bookmarks: "bookmark"
        case .personalNotes: "note.text"
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
}

private struct LibrarySidebar: View {
    let width: CGFloat
    @Binding var selection: LibraryDestination
    let user: ReaderUser?
    let onSignOut: () -> Void

    private let primaryItems: [LibraryDestination] = [
        .home, .library, .categories, .downloads, .bookmarks, .personalNotes, .help,
    ]

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
        case .categories: "Categories"
        case .bookmarks: "Bookmarks"
        case .personalNotes: "Personal Notes"
        default: "My Library"
        }
    }

    private var subtitle: String {
        switch destination {
        case .downloads: "Manuals available without an internet connection."
        case .categories: "Browse the manuals available to your account."
        case .bookmarks: "Return to manuals containing your saved bookmarks."
        case .personalNotes: "Review your personal notes, grouped by manual."
        default: "All manuals and books, always at your fingertips."
        }
    }

    private var visibleBooks: [LibraryBook] {
        books.filter { book in
            if destination == .downloads && !downloads.status(for: book).isAvailableOffline {
                return false
            }
            if destination == .bookmarks
                && ManualReaderSessionStore.shared.bookmarks(for: book.bookKey).isEmpty {
                return false
            }
            if let selectedCategory, book.category != selectedCategory {
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
        let grouped = Dictionary(grouping: books, by: \.category)
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
                } else if books.isEmpty {
                    ContentUnavailableView(
                        "No Manuals",
                        systemImage: "books.vertical",
                        description: Text("Manuals available to your account will appear here.")
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
                                onSelect: onSelectBook
                            )
                        }

                        if !availableCategories.isEmpty {
                            CategoriesSection(
                                categories: availableCategories,
                                selectedCategory: $selectedCategory,
                                availableWidth: availableWidth,
                                isPhone: isPhone
                            )
                        }

                        ForEach(categoryGroups, id: \.0.id) { category, categoryBooks in
                            ManualShelf(
                                title: category.title,
                                books: categoryBooks,
                                cardWidth: coverWidth,
                                baseURL: baseURL,
                                showsProgress: false,
                                onSelect: onSelectBook
                            )
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
    let onSelect: () -> Void

    private var resolvedCoverURL: URL? {
        if let absolute = book.coverAbsoluteURL { return absolute }
        guard let baseURL else { return nil }
        return ManualReaderAPIClient.absoluteURL(
            from: book.coverImageUrl ?? book.coverUrl,
            baseURL: baseURL
        )
    }

    private var progress: Double? {
        downloads.readingProgress(for: book)
    }

    var body: some View {
        Button {
#if DEBUG
            print("READER_CARD_TAP book=\(book.id)")
#endif
            onSelect()
        } label: {
            VStack(alignment: .leading, spacing: 9) {
                cover

                Text(book.displayTitle)
                    .font(.caption.weight(.semibold))
                    .foregroundStyle(.primary)
                    .lineLimit(2)
                    .frame(height: 32, alignment: .topLeading)

                if showsProgress || book.hasProgress {
                    HStack(spacing: 7) {
                        Text(progress.map { "\(Int($0 * 100))%" } ?? "Continue")
                            .font(.caption2)
                            .foregroundStyle(.secondary)
                        ProgressView(value: progress ?? 0.04)
                            .tint(IPCAReaderTheme.navy)
                    }
                }

                DownloadStatusIndicator(book: book)
            }
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .frame(width: width, alignment: .leading)
        .accessibilityLabel("Open \(book.displayTitle)")
        .contextMenu {
            downloadActions
            Button("Open Manual", action: onSelect)
        }
    }

    private var cover: some View {
        ZStack(alignment: .topTrailing) {
            RoundedRectangle(cornerRadius: 10, style: .continuous)
                .fill(IPCAReaderTheme.navy)
                .aspectRatio(0.68, contentMode: .fit)

            if let data = downloads.packages[book.id]?.coverImageData,
               let image = UIImage(data: data) {
                Image(uiImage: image)
                    .resizable()
                    .scaledToFill()
                    .frame(width: width, height: width / 0.68)
                    .clipShape(RoundedRectangle(cornerRadius: 10, style: .continuous))
            } else if let url = resolvedCoverURL {
                AsyncImage(url: url) { phase in
                    switch phase {
                    case .success(let image):
                        image.resizable().scaledToFill()
                    default:
                        coverPlaceholder
                    }
                }
                .frame(width: width, height: width / 0.68)
                .clipShape(RoundedRectangle(cornerRadius: 10, style: .continuous))
            } else {
                coverPlaceholder
            }

            if book.isDraftPreview {
                Text("DRAFT")
                    .font(.system(size: 9, weight: .bold))
                    .foregroundStyle(.white)
                    .padding(.horizontal, 7)
                    .padding(.vertical, 5)
                    .background(.orange, in: Capsule())
                    .padding(7)
            }
        }
        .frame(width: width, height: width / 0.68)
        .clipped()
        .shadow(color: .black.opacity(0.14), radius: 7, y: 4)
    }

    private var coverPlaceholder: some View {
        ZStack(alignment: .bottomLeading) {
            LinearGradient(
                colors: [IPCAReaderTheme.navyLight, IPCAReaderTheme.navy],
                startPoint: .topLeading,
                endPoint: .bottomTrailing
            )
            VStack(alignment: .leading, spacing: 5) {
                Text(book.manualCode.isEmpty ? book.bookKey : book.manualCode)
                    .font(.headline.weight(.bold))
                Text(book.displayTitle)
                    .font(.caption)
                    .lineLimit(3)
            }
            .foregroundStyle(.white)
            .padding(13)
        }
    }

    @ViewBuilder
    private var downloadActions: some View {
        switch downloads.status(for: book) {
        case .availableOffline:
            Button("Update Download") {
                guard let client = ManualReaderSessionStore.shared.client else { return }
                Task {
                    _ = try? await downloads.ensureDownloaded(
                        book: book,
                        client: client,
                        forceRefresh: true
                    )
                }
            }
            Button("Remove Download", role: .destructive) {
                Task { await downloads.removeDownload(for: book) }
            }
        case .downloading:
            EmptyView()
        case .updateAvailable:
            Button("Download Update") {
                guard let client = ManualReaderSessionStore.shared.client else { return }
                Task {
                    _ = try? await downloads.ensureDownloaded(
                        book: book,
                        client: client,
                        forceRefresh: true
                    )
                }
            }
        default:
            Button("Download") {
                guard let client = ManualReaderSessionStore.shared.client else { return }
                Task {
                    _ = try? await downloads.ensureDownloaded(book: book, client: client)
                }
            }
        }
    }
}

private struct DownloadStatusIndicator: View {
    @ObservedObject private var downloads = ManualDownloadManager.shared
    let book: LibraryBook

    var body: some View {
        switch downloads.status(for: book) {
        case .notDownloaded:
            Label("Not downloaded", systemImage: "icloud.and.arrow.down")
                .foregroundStyle(.secondary)
        case .downloading(let progress):
            HStack(spacing: 6) {
                ProgressView()
                    .controlSize(.mini)
                Text("Downloading \(Int(progress * 100))%")
            }
            .foregroundStyle(IPCAReaderTheme.navy)
        case .availableOffline:
            Label("Available offline", systemImage: "checkmark.circle.fill")
                .foregroundStyle(.green)
        case .updateAvailable(let priorVersion):
            Label(
                "Update available · \(book.versionLabel) (installed \(priorVersion))",
                systemImage: "arrow.down.circle.fill"
            )
            .foregroundStyle(.orange)
        case .failed:
            Label("Download failed", systemImage: "exclamationmark.triangle.fill")
                .foregroundStyle(.orange)
        }
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
                                Button { onSelect(book, note) } label: {
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
                                        Image(systemName: "chevron.right")
                                            .foregroundStyle(.secondary)
                                    }
                                    .padding(14)
                                    .background(Color.white, in: RoundedRectangle(cornerRadius: 12))
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
        .navigationTitle("Personal Notes")
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

private struct MoreView: View {
    let user: ReaderUser?
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
