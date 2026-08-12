import SwiftUI

struct LibraryView: View {
    @ObservedObject private var session = ManualReaderSessionStore.shared
    @StateObject private var viewModel = LibraryViewModel()
    @State private var selectedBook: LibraryBook?

    private let columns = [
        GridItem(.adaptive(minimum: 180, maximum: 220), spacing: 24),
    ]

    var body: some View {
        NavigationStack {
            ScrollView {
                if viewModel.isLoading && viewModel.books.isEmpty {
                    ProgressView("Loading library…")
                        .frame(maxWidth: .infinity, minHeight: 300)
                } else if let error = viewModel.errorMessage, viewModel.books.isEmpty {
                    ContentUnavailableView {
                        Label("Could Not Load Library", systemImage: "exclamationmark.triangle")
                    } description: {
                        Text(error)
                    } actions: {
                        Button("Retry") { Task { await viewModel.load() } }
                    }
                    .padding(.top, 80)
                } else if viewModel.books.isEmpty {
                    ContentUnavailableView {
                        Label("No Manuals", systemImage: "books.vertical")
                    } description: {
                        Text("Released manuals will appear here when available.")
                    }
                    .padding(.top, 80)
                } else {
                    LazyVGrid(columns: columns, spacing: 28) {
                        ForEach(viewModel.books) { book in
                            Button {
                                selectedBook = book
                            } label: {
                                LibraryBookCard(
                                    book: book,
                                    baseURL: session.baseURL
                                )
                            }
                            .buttonStyle(.plain)
                        }
                    }
                    .padding(24)
                }
            }
            .background(IPCAReaderTheme.shelfBackground.ignoresSafeArea())
            .navigationTitle("Manuals")
            .toolbar {
                ToolbarItem(placement: .topBarTrailing) {
                    NavigationLink {
                        ReaderSettingsView(showServer: false)
                    } label: {
                        Image(systemName: "gearshape")
                    }
                }
                ToolbarItem(placement: .topBarLeading) {
                    Menu {
                        if let user = session.user {
                            Text(user.name.isEmpty ? user.email : user.name)
                        }
                        Button("Sign Out", role: .destructive) {
                            Task { await session.logout() }
                        }
                    } label: {
                        Image(systemName: "person.circle")
                    }
                }
            }
            .refreshable { await viewModel.load() }
            .task { await viewModel.load() }
            .fullScreenCover(item: $selectedBook) { book in
                ReaderView(book: book)
            }
        }
    }
}

private struct LibraryBookCard: View {
    let book: LibraryBook
    let baseURL: URL?

    private var resolvedCoverURL: URL? {
        if let absolute = book.coverAbsoluteURL { return absolute }
        guard let baseURL else { return nil }
        return ManualReaderAPIClient.absoluteURL(
            from: book.coverImageUrl ?? book.coverUrl,
            baseURL: baseURL
        )
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            ZStack(alignment: .bottomLeading) {
                RoundedRectangle(cornerRadius: 8, style: .continuous)
                    .fill(
                        LinearGradient(
                            colors: [IPCAReaderTheme.accent, IPCAReaderTheme.accent.opacity(0.75)],
                            startPoint: .topLeading,
                            endPoint: .bottomTrailing
                        )
                    )
                    .aspectRatio(3 / 4, contentMode: .fit)
                    .shadow(color: .black.opacity(0.15), radius: 8, y: 4)

                if let url = resolvedCoverURL {
                    AsyncImage(url: url) { phase in
                        if case .success(let image) = phase {
                            image
                                .resizable()
                                .scaledToFill()
                                .clipShape(RoundedRectangle(cornerRadius: 8, style: .continuous))
                        }
                    }
                    .aspectRatio(3 / 4, contentMode: .fit)
                }

                VStack(alignment: .leading, spacing: 2) {
                    Text(book.manualCode)
                        .font(.caption.weight(.bold))
                    Text("v\(book.versionLabel)")
                        .font(.caption2)
                }
                .foregroundStyle(.white)
                .padding(10)
            }

            VStack(alignment: .leading, spacing: 4) {
                Text(book.displayTitle)
                    .font(.headline)
                    .foregroundStyle(.primary)
                    .lineLimit(2)
                if book.isDraftPreview {
                    Text("Draft preview")
                        .font(.caption.weight(.semibold))
                        .foregroundStyle(.orange)
                } else if book.hasProgress {
                    Text("Continue reading")
                        .font(.caption)
                        .foregroundStyle(IPCAReaderTheme.accent)
                }
            }
        }
    }
}
