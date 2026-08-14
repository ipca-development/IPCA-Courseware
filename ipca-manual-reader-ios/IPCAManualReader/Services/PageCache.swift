import Foundation
import Combine
import CryptoKit

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
    /// Legacy download field retained only so existing packages decode.
    let editorCSS: String?
    let contentCSS: String?
    let readerCSS: String?
    let readerStyleVersion: String?
    let paginateSourceData: Data?
    let publicationPackage: PublicationPackage?
    let publicationManifestJSON: Data?
    let publicationAssets: [OfflinePublicationAsset]?

    func page(number: Int) -> FrozenPageResponse? {
        pages.first { $0.pageNumber == number }
    }

    var hasVerifiedPublicationStyle: Bool {
        guard let publicationPackage,
              let publicationManifestJSON,
              !publicationPackage.css.content.isEmpty,
              publicationPackage.manifestVersion.hasPrefix("book-style-manifest-v1-"),
              publicationPackage.manifest.renderPipeline.cssGeneratorVersion
                == ReaderPublicationContract.cssGeneratorVersion,
              sha256(publicationManifestJSON) == publicationPackage.manifestHash,
              sha256(Data(publicationPackage.css.content.utf8)) == publicationPackage.css.hash else {
            return false
        }
        return true
    }

    var hasCanonicalPublicationPackage: Bool {
        guard hasVerifiedPublicationStyle, let publicationPackage else { return false }
        let downloadedByURL = Dictionary(
            uniqueKeysWithValues: (publicationAssets ?? []).map { ($0.sourceURL, $0) }
        )
        return publicationPackage.assets.compactMap { asset -> Bool? in
            guard let url = asset.url else { return nil }
            guard let local = downloadedByURL[url] else { return false }
            if let expected = asset.contentHash, !expected.isEmpty {
                return sha256(local.data) == expected.lowercased()
            }
            return true
        }.allSatisfy { $0 }
    }

    var bookStyleCSS: String? {
        hasVerifiedPublicationStyle ? publicationPackage?.css.content : nil
    }

    var publicationLayout: PublicationLayout? {
        hasVerifiedPublicationStyle ? publicationPackage?.manifest.layout : nil
    }

    func rewritePublicationURLs(in html: String) -> String {
        let replacements = (publicationAssets ?? []).flatMap { asset in
            [asset.sourceURL, asset.resolvedURL].map { ($0, asset.dataURL) }
        }.sorted { $0.0.count > $1.0.count }
        return replacements.reduce(html) { result, replacement in
            result.replacingOccurrences(of: replacement.0, with: replacement.1)
            }
    }

    func rewrittenPaginateSourceData() -> Data? {
        guard let paginateSourceData,
              let object = try? JSONSerialization.jsonObject(with: paginateSourceData) else {
            return nil
        }
        func rewrite(_ value: Any) -> Any {
            if let string = value as? String { return rewritePublicationURLs(in: string) }
            if let array = value as? [Any] { return array.map(rewrite) }
            if let dictionary = value as? [String: Any] {
                return dictionary.mapValues(rewrite)
            }
            return value
        }
        let rewritten = rewrite(object)
        guard JSONSerialization.isValidJSONObject(rewritten) else { return nil }
        return try? JSONSerialization.data(withJSONObject: rewritten, options: [.sortedKeys])
    }

    private func sha256(_ data: Data) -> String {
        SHA256.hash(data: data).map { String(format: "%02x", $0) }.joined()
    }
}

struct OfflinePublicationAsset: Codable {
    let descriptor: String
    let sourceURL: String
    let resolvedURL: String
    let mediaType: String
    let contentHash: String?
    let data: Data

    var dataURL: String {
        "data:\(mediaType);base64,\(data.base64EncodedString())"
    }
}

struct PersonalPaginationSnapshot: Codable {
    let cacheIdentity: String
    let personalPages: [PersonalReaderPage]
    let sectionPageIndex: [Int: Int]
    let validation: PaginationValidationSummary
    let normalizerVersion: String
    let engineVersion: String
    let layout: PageLayoutConfiguration

    var result: PersonalPaginationResult {
        PersonalPaginationResult(
            personalPages: personalPages,
            sectionPageIndex: sectionPageIndex,
            validation: validation,
            normalizerVersion: normalizerVersion,
            engineVersion: engineVersion,
            layout: layout
        )
    }
}

actor PersonalPaginationCache {
    static let shared = PersonalPaginationCache()

    private let fileManager = FileManager.default

    private var rootURL: URL {
        fileManager.urls(for: .cachesDirectory, in: .userDomainMask)[0]
            .appendingPathComponent("IPCAManualReader", isDirectory: true)
            .appendingPathComponent("PersonalPagination", isDirectory: true)
    }

    func load(identity: String) -> PersonalPaginationResult? {
        let url = cacheURL(identity: identity)
        guard let data = try? Data(contentsOf: url),
              let snapshot = try? JSONDecoder().decode(PersonalPaginationSnapshot.self, from: data),
              snapshot.cacheIdentity == identity,
              snapshot.validation.isValid else {
            return nil
        }
        return snapshot.result
    }

    func save(_ result: PersonalPaginationResult, identity: String) throws {
        try fileManager.createDirectory(at: rootURL, withIntermediateDirectories: true)
        let snapshot = PersonalPaginationSnapshot(
            cacheIdentity: identity,
            personalPages: result.personalPages,
            sectionPageIndex: result.sectionPageIndex,
            validation: result.validation,
            normalizerVersion: result.normalizerVersion,
            engineVersion: result.engineVersion,
            layout: result.layout
        )
        let data = try JSONEncoder().encode(snapshot)
        try data.write(to: cacheURL(identity: identity), options: .atomic)
    }

    private func cacheURL(identity: String) -> URL {
        let safeName = identity.map { character in
            character.isLetter || character.isNumber || character == "-" ? character : "_"
        }
        return rootURL.appendingPathComponent(String(safeName) + ".json")
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
        statuses[book.id] = package.hasCanonicalPublicationPackage
            ? .availableOffline(package.downloadedAt)
            : .notDownloaded
        return package
    }

    func refreshStatuses(for books: [LibraryBook]) async {
        for book in books {
            if let package = await diskStore.load(bookID: book.id, versionID: book.versionId) {
                packages[book.id] = package
                statuses[book.id] = package.hasCanonicalPublicationPackage
                    ? .availableOffline(package.downloadedAt)
                    : .notDownloaded
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
        if !forceRefresh,
           let existing = await package(for: book),
           existing.hasCanonicalPublicationPackage,
           existing.paginateSourceData != nil {
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
            async let paginateSourceTask = client.fetchPaginateSource(
                bookKey: book.bookKey,
                versionId: versionID,
                isPreview: isPreview
            )
            async let publicationPackageTask = client.fetchPublicationPackage(
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

            let (pageMap, tableOfContents, paginateSourceData, publicationResponse) = try await (
                pageMapTask,
                tocTask,
                paginateSourceTask,
                publicationPackageTask
            )
            guard publicationResponse.ok,
                  publicationResponse.versionID == book.versionId else {
                throw ManualReaderAPIError.badResponse(
                    publicationResponse.error ?? "Publication package version mismatch."
                )
            }
            let publicationPackage = publicationResponse.publicationPackage
            let manifestJSON = publicationPackage.canonicalManifestJSON
            guard sha256(manifestJSON) == publicationPackage.manifestHash else {
                throw ManualReaderAPIError.badResponse("Publication manifest hash verification failed.")
            }
            guard sha256(Data(publicationPackage.css.content.utf8)) == publicationPackage.css.hash else {
                throw ManualReaderAPIError.badResponse("Book-style CSS hash verification failed.")
            }
            let pageNumbers = pageMap.pages
                .map(\.pageNumber)
                .sorted()
            var openingNumbers = Array(pageNumbers.prefix(1))
            if let continuePage = book.continuePageNumber,
               pageNumbers.contains(continuePage),
               !openingNumbers.contains(continuePage) {
                openingNumbers.append(continuePage)
            }
            let openingBatch = try await downloadBatchWithRetry(
                book: book,
                pageNumbers: openingNumbers,
                client: client
            )

            let starterPackage = OfflineManualPackage(
                bookID: book.id,
                versionID: book.versionId,
                downloadedAt: Date(),
                pageMap: pageMap,
                tableOfContents: tableOfContents,
                pages: openingBatch.pages,
                coverImageData: coverData,
                editorCSS: nil,
                contentCSS: nil,
                readerCSS: nil,
                readerStyleVersion: ReaderPaginationVersion.style,
                paginateSourceData: paginateSourceData,
                publicationPackage: publicationPackage,
                publicationManifestJSON: manifestJSON,
                publicationAssets: []
            )
            try await diskStore.save(starterPackage)
            packages[book.id] = starterPackage
            statuses[book.id] = .downloading(
                Double(openingBatch.pages.count) / Double(max(pageNumbers.count, 1))
            )
            Task { @MainActor [weak self] in
                await self?.completeDownload(
                    book: book,
                    client: client,
                    starterPackage: starterPackage,
                    pageNumbers: pageNumbers
                )
            }
            return starterPackage
        } catch {
            statuses[book.id] = .failed(error.localizedDescription)
            throw error
        }
    }

    private func completeDownload(
        book: LibraryBook,
        client: ManualReaderAPIClient,
        starterPackage: OfflineManualPackage,
        pageNumbers: [Int]
    ) async {
        do {
            guard let publicationPackage = starterPackage.publicationPackage else { return }
            let publicationAssets = try await downloadPublicationAssets(
                publicationPackage.assets,
                client: client
            )
            var downloadedByNumber = Dictionary(
                uniqueKeysWithValues: starterPackage.pages.compactMap { page in
                    page.pageNumber.map { ($0, page) }
                }
            )
            let missingNumbers = pageNumbers.filter { downloadedByNumber[$0] == nil }
            var batchStart = 0
            while batchStart < missingNumbers.count {
                try Task.checkCancellation()
                let batchEnd = min(batchStart + 8, missingNumbers.count)
                let batchNumbers = Array(missingNumbers[batchStart..<batchEnd])
                let batch = try await downloadBatchWithRetry(
                    book: book,
                    pageNumbers: batchNumbers,
                    client: client
                )
                for page in batch.pages {
                    if let pageNumber = page.pageNumber {
                        downloadedByNumber[pageNumber] = page
                    }
                }
                batchStart = batchEnd
                statuses[book.id] = .downloading(
                    Double(downloadedByNumber.count) / Double(max(pageNumbers.count, 1))
                )
            }
            guard downloadedByNumber.count == pageNumbers.count else {
                throw ManualReaderAPIError.badResponse(
                    "The server returned an incomplete manual download."
                )
            }
            let completedPackage = OfflineManualPackage(
                bookID: starterPackage.bookID,
                versionID: starterPackage.versionID,
                downloadedAt: starterPackage.downloadedAt,
                pageMap: starterPackage.pageMap,
                tableOfContents: starterPackage.tableOfContents,
                pages: downloadedByNumber.values.sorted {
                    ($0.pageNumber ?? 0) < ($1.pageNumber ?? 0)
                },
                coverImageData: starterPackage.coverImageData,
                editorCSS: nil,
                contentCSS: nil,
                readerCSS: nil,
                readerStyleVersion: starterPackage.readerStyleVersion,
                paginateSourceData: starterPackage.paginateSourceData,
                publicationPackage: publicationPackage,
                publicationManifestJSON: starterPackage.publicationManifestJSON,
                publicationAssets: publicationAssets
            )
            try await diskStore.save(completedPackage)
            packages[book.id] = completedPackage
            statuses[book.id] = .availableOffline(completedPackage.downloadedAt)
        } catch {
            statuses[book.id] = .failed(error.localizedDescription)
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

    private func downloadPublicationAssets(
        _ assets: [PublicationAsset],
        client: ManualReaderAPIClient
    ) async throws -> [OfflinePublicationAsset] {
        var downloaded: [OfflinePublicationAsset] = []
        for asset in assets.sorted(by: { $0.descriptor < $1.descriptor }) {
            guard let sourceURL = asset.url else { continue }
            guard let url = ManualReaderAPIClient.absoluteURL(from: sourceURL, baseURL: client.baseURL) else {
                throw ManualReaderAPIError.badResponse("Invalid publication asset URL: \(sourceURL)")
            }
            let (data, response) = try await client.session.data(from: url)
            guard let http = response as? HTTPURLResponse,
                  (200...299).contains(http.statusCode) else {
                throw ManualReaderAPIError.badResponse("Unable to download publication asset: \(sourceURL)")
            }
            if let expected = asset.contentHash,
               !expected.isEmpty,
               sha256(data) != expected.lowercased() {
                throw ManualReaderAPIError.badResponse(
                    "Publication asset hash verification failed: \(sourceURL)"
                )
            }
            let mediaType = http.value(forHTTPHeaderField: "Content-Type")?
                .split(separator: ";").first.map(String.init)
                ?? mediaType(for: url.pathExtension)
            downloaded.append(
                OfflinePublicationAsset(
                    descriptor: asset.descriptor,
                    sourceURL: sourceURL,
                    resolvedURL: url.absoluteString,
                    mediaType: mediaType,
                    contentHash: asset.contentHash,
                    data: data
                )
            )
        }
        return downloaded
    }

    private func mediaType(for pathExtension: String) -> String {
        switch pathExtension.lowercased() {
        case "png": "image/png"
        case "jpg", "jpeg": "image/jpeg"
        case "gif": "image/gif"
        case "svg": "image/svg+xml"
        case "webp": "image/webp"
        default: "application/octet-stream"
        }
    }

    private func sha256(_ data: Data) -> String {
        SHA256.hash(data: data).map { String(format: "%02x", $0) }.joined()
    }
}
