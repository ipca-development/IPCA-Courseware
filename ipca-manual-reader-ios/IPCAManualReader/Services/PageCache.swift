import Foundation
import Combine

actor PageCache {
    static let shared = PageCache()

    private var storage: [String: FrozenPageResponse] = [:]
    private let maxEntries = 48

    private func key(bookKey: String, page: Int) -> String {
        "\(bookKey.uppercased())#\(page)"
    }

    func get(bookKey: String, page: Int) -> FrozenPageResponse? {
        storage[key(bookKey: bookKey, page: page)]
    }

    func set(bookKey: String, page: Int, response: FrozenPageResponse) {
        storage[key(bookKey: bookKey, page: page)] = response
        if storage.count > maxEntries {
            if let oldest = storage.keys.sorted().first {
                storage.removeValue(forKey: oldest)
            }
        }
    }

    func clear(bookKey: String? = nil) {
        if let bookKey {
            let prefix = bookKey.uppercased() + "#"
            storage = storage.filter { !$0.key.hasPrefix(prefix) }
        } else {
            storage.removeAll()
        }
    }
}

struct OfflineManualPackage: Codable {
    let bookID: String
    let versionID: Int
    let downloadedAt: Date
    let pageMap: PageMapResponse
    let tableOfContents: TocResponse
    let pages: [FrozenPageResponse]
    let coverImageData: Data?
    let editorCSS: String?
    let readerCSS: String?

    func page(number: Int) -> FrozenPageResponse? {
        pages.first { $0.pageNumber == number }
    }
}

enum ManualDownloadStatus: Equatable {
    case notDownloaded
    case downloading(Double)
    case availableOffline(Date)
    case failed(String)

    var isAvailableOffline: Bool {
        if case .availableOffline = self { return true }
        return false
    }
}

private actor ManualPackageDiskStore {
    private let fileManager = FileManager.default

    private var rootURL: URL {
        let base = fileManager.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]
        return base
            .appendingPathComponent("IPCAManualReader", isDirectory: true)
            .appendingPathComponent("ManualDownloads", isDirectory: true)
    }

    private func packageURL(for bookID: String) -> URL {
        let safeName = bookID.map { character in
            character.isLetter || character.isNumber || character == "-" ? character : "_"
        }
        return rootURL.appendingPathComponent(String(safeName) + ".json")
    }

    func load(bookID: String, versionID: Int) -> OfflineManualPackage? {
        let url = packageURL(for: bookID)
        guard let data = try? Data(contentsOf: url),
              let package = try? JSONDecoder().decode(OfflineManualPackage.self, from: data),
              package.versionID == versionID else {
            return nil
        }
        return package
    }

    func save(_ package: OfflineManualPackage) throws {
        try fileManager.createDirectory(at: rootURL, withIntermediateDirectories: true)
        let data = try JSONEncoder().encode(package)
        try data.write(to: packageURL(for: package.bookID), options: .atomic)
    }

    func remove(bookID: String) throws {
        let url = packageURL(for: bookID)
        if fileManager.fileExists(atPath: url.path) {
            try fileManager.removeItem(at: url)
        }
    }
}

@MainActor
final class ManualDownloadManager: ObservableObject {
    static let shared = ManualDownloadManager()

    @Published private(set) var statuses: [String: ManualDownloadStatus] = [:]
    @Published private(set) var packages: [String: OfflineManualPackage] = [:]

    private let diskStore = ManualPackageDiskStore()

    private init() {}

    func status(for book: LibraryBook) -> ManualDownloadStatus {
        statuses[book.id] ?? .notDownloaded
    }

    func package(for book: LibraryBook) async -> OfflineManualPackage? {
        if let package = packages[book.id], package.versionID == book.versionId {
            return package
        }
        guard let package = await diskStore.load(bookID: book.id, versionID: book.versionId) else {
            return nil
        }
        packages[book.id] = package
        statuses[book.id] = .availableOffline(package.downloadedAt)
        return package
    }

    func refreshStatuses(for books: [LibraryBook]) async {
        for book in books {
            if let package = await diskStore.load(bookID: book.id, versionID: book.versionId) {
                packages[book.id] = package
                statuses[book.id] = .availableOffline(package.downloadedAt)
            } else if statuses[book.id] == nil {
                statuses[book.id] = .notDownloaded
            }
        }
    }

    func ensureDownloaded(
        book: LibraryBook,
        client: ManualReaderAPIClient,
        forceRefresh: Bool = false
    ) async throws -> OfflineManualPackage {
        if !forceRefresh, let existing = await package(for: book) {
            return existing
        }

        statuses[book.id] = .downloading(0)
        do {
            let versionID = book.versionId > 0 ? book.versionId : nil
            let isPreview = book.isDraftPreview
            async let pageMapTask = client.fetchPageMap(
                bookKey: book.bookKey,
                versionId: versionID,
                isPreview: isPreview
            )
            async let tocTask = client.fetchToc(
                bookKey: book.bookKey,
                versionId: versionID,
                isPreview: isPreview
            )

            var coverData: Data?
            if let baseURL = ManualReaderSessionStore.shared.baseURL,
               let coverURL = ManualReaderAPIClient.absoluteURL(
                   from: book.coverImageUrl ?? book.coverUrl,
                   baseURL: baseURL
               ),
               let result = try? await client.session.data(from: coverURL),
               let response = result.1 as? HTTPURLResponse,
               (200...299).contains(response.statusCode) {
                coverData = result.0
            }

            async let editorCSSTask = downloadTextAsset(
                path: "assets/controlled_book_editor.css",
                client: client
            )
            async let readerCSSTask = downloadTextAsset(
                path: "assets/manual_reader.css",
                client: client
            )
            let (pageMap, tableOfContents) = try await (pageMapTask, tocTask)
            let pageNumbers = pageMap.pages
                .map(\.pageNumber)
                .sorted()
            var downloadedPages: [FrozenPageResponse] = []
            downloadedPages.reserveCapacity(pageNumbers.count)

            var batchStart = 0
            while batchStart < pageNumbers.count {
                try Task.checkCancellation()
                let batchEnd = min(batchStart + 8, pageNumbers.count)
                let batchNumbers = Array(pageNumbers[batchStart..<batchEnd])
                let batch = try await downloadBatchWithRetry(
                    book: book,
                    pageNumbers: batchNumbers,
                    client: client
                )
                downloadedPages.append(contentsOf: batch.pages)
                batchStart = batchEnd
                statuses[book.id] = .downloading(
                    Double(batchStart) / Double(max(pageNumbers.count, 1))
                )
            }
            guard downloadedPages.count == pageNumbers.count else {
                throw ManualReaderAPIError.badResponse(
                    "The server returned an incomplete manual download."
                )
            }

            let (editorCSS, readerCSS) = await (editorCSSTask, readerCSSTask)

            let package = OfflineManualPackage(
                bookID: book.id,
                versionID: book.versionId,
                downloadedAt: Date(),
                pageMap: pageMap,
                tableOfContents: tableOfContents,
                pages: downloadedPages,
                coverImageData: coverData,
                editorCSS: editorCSS,
                readerCSS: readerCSS
            )
            try await diskStore.save(package)
            packages[book.id] = package
            statuses[book.id] = .availableOffline(package.downloadedAt)
            return package
        } catch {
            statuses[book.id] = .failed(error.localizedDescription)
            throw error
        }
    }

    func removeDownload(for book: LibraryBook) async {
        try? await diskStore.remove(bookID: book.id)
        packages.removeValue(forKey: book.id)
        statuses[book.id] = .notDownloaded
        await PageCache.shared.clear(bookKey: book.id)
    }

    func readingProgress(for book: LibraryBook) -> Double? {
        guard book.hasProgress,
              let package = packages[book.id],
              !package.pageMap.pages.isEmpty else {
            return nil
        }
        let currentPage: Int?
        if let pageNumber = book.continuePageNumber, pageNumber > 0 {
            currentPage = pageNumber
        } else if let anchor = book.continueStableAnchor, !anchor.isEmpty {
            currentPage = package.pageMap.pages.first { $0.stableAnchor == anchor }?.pageNumber
        } else {
            currentPage = nil
        }
        guard let currentPage else { return nil }
        return min(1, max(0, Double(currentPage) / Double(package.pageMap.pages.count)))
    }

    private func downloadBatchWithRetry(
        book: LibraryBook,
        pageNumbers: [Int],
        client: ManualReaderAPIClient
    ) async throws -> ManualPageBatchResponse {
        for attempt in 0..<3 {
            do {
                return try await client.downloadManualPages(
                    bookKey: book.bookKey,
                    pageNumbers: pageNumbers,
                    versionId: book.versionId > 0 ? book.versionId : nil,
                    isPreview: book.isDraftPreview
                )
            } catch {
                if attempt == 2 {
                    throw error
                }
                try await Task.sleep(for: .milliseconds(600 * (attempt + 1)))
            }
        }
        throw CancellationError()
    }

    private func downloadTextAsset(
        path: String,
        client: ManualReaderAPIClient
    ) async -> String? {
        let url = client.baseURL.appending(path: path)
        guard let result = try? await client.session.data(from: url),
              let response = result.1 as? HTTPURLResponse,
              (200...299).contains(response.statusCode) else {
            return nil
        }
        return String(data: result.0, encoding: .utf8)
    }
}
