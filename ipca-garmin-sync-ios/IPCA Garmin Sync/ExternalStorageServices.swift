import CryptoKit
import Foundation
import OSLog
import UIKit

final class ExternalStorageAccessService {
    private let defaults: UserDefaults
    private let bookmarkKey = "garminSync.externalFolderBookmark"

    init(defaults: UserDefaults = .standard) {
        self.defaults = defaults
    }

    func persistBookmark(for folder: URL) throws {
        let data = try folder.bookmarkData(
            options: .minimalBookmark,
            includingResourceValuesForKeys: [.isDirectoryKey],
            relativeTo: nil
        )
        defaults.set(data, forKey: bookmarkKey)
    }

    func restoreFolder() throws -> URL {
        guard let data = defaults.data(forKey: bookmarkKey) else {
            throw GarminSyncError.bookmarkUnavailable
        }
        var stale = false
        let url = try URL(
            resolvingBookmarkData: data,
            options: [.withoutUI],
            relativeTo: nil,
            bookmarkDataIsStale: &stale
        )
        if stale {
            try persistBookmark(for: url)
        }
        return url
    }

    func withAccess<T>(to folder: URL, operation: () throws -> T) throws -> T {
        let scoped = folder.startAccessingSecurityScopedResource()
        defer {
            if scoped { folder.stopAccessingSecurityScopedResource() }
        }
        return try operation()
    }

    func withAccess<T>(to folder: URL, operation: () async throws -> T) async throws -> T {
        let scoped = folder.startAccessingSecurityScopedResource()
        defer {
            if scoped { folder.stopAccessingSecurityScopedResource() }
        }
        return try await operation()
    }
}

struct GarminCardScanner {
    private let fileManager: FileManager

    init(fileManager: FileManager = .default) {
        self.fileManager = fileManager
    }

    func scan(folder: URL, progress: @Sendable (ScanProgress) -> Void = { _ in }) throws -> [ScannedFile] {
        let values = try folder.resourceValues(forKeys: [.isDirectoryKey])
        guard values.isDirectory == true else { throw GarminSyncError.invalidFolder }
        var enumerationError: Error?
        guard let enumerator = fileManager.enumerator(
            at: folder,
            includingPropertiesForKeys: [.isRegularFileKey, .fileSizeKey, .contentModificationDateKey],
            options: [.skipsHiddenFiles],
            errorHandler: { _, error in
                enumerationError = error
                return false
            }
        ) else {
            throw GarminSyncError.invalidFolder
        }

        var files: [ScannedFile] = []
        for case let url as URL in enumerator {
            guard url.pathExtension.caseInsensitiveCompare("csv") == .orderedSame else { continue }
            let metadata = try url.resourceValues(forKeys: [.isRegularFileKey, .fileSizeKey, .contentModificationDateKey])
            guard metadata.isRegularFile == true else { continue }
            let relative = String(url.path.dropFirst(folder.path.count)).trimmingCharacters(in: CharacterSet(charactersIn: "/"))
            guard let byteSize = metadata.fileSize, byteSize > 0 else {
                throw GarminSyncError.verificationFailed(relative)
            }
            files.append(
                ScannedFile(
                    relativePath: relative,
                    size: Int64(byteSize),
                    modificationDate: metadata.contentModificationDate ?? .distantPast
                )
            )
            progress(.init(
                phase: .scanning,
                current: files.count,
                total: 0,
                path: relative,
                filesChecked: files.count,
                newFiles: 0,
                copiedFiles: 0,
                errors: 0
            ))
        }
        if let enumerationError {
            throw enumerationError
        }
        return files.sorted { $0.relativePath.localizedStandardCompare($1.relativePath) == .orderedAscending }
    }
}

struct FileHashService {
    private let chunkSize = 1024 * 1024

    func sha256(url: URL) throws -> String {
        let handle = try FileHandle(forReadingFrom: url)
        defer { try? handle.close() }
        var hasher = SHA256()
        while let data = try handle.read(upToCount: chunkSize), !data.isEmpty {
            hasher.update(data: data)
        }
        return hasher.finalize().map { String(format: "%02x", $0) }.joined()
    }
}
