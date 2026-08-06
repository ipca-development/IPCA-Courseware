import Foundation
import UIKit

/// Owns the low-level lifecycle for the Garmin SD Card external folder: probing whether the
/// configured bookmark currently resolves, enumerating CSV files while access is held, and
/// safely copying an external file into app storage before any upload is attempted.
///
/// This service never touches `CVRFlightLogStore.pendingGarminCSV` or the Garmin vault — an
/// external access failure must never remove or invalidate a locally staged pending import.
@MainActor
final class GarminExternalFolderAccessService: ObservableObject {
    @Published private(set) var accessState: GarminExternalFolderAccessState = .notConfigured
    @Published private(set) var isBusy = false
    @Published private(set) var lastError = ""
    @Published private(set) var isBackgrounded = false

    private var backgroundObserver: NSObjectProtocol?
    private var foregroundObserver: NSObjectProtocol?

    init() {
        backgroundObserver = NotificationCenter.default.addObserver(
            forName: UIApplication.willResignActiveNotification,
            object: nil,
            queue: .main
        ) { [weak self] _ in
            Task { @MainActor [weak self] in
                self?.isBackgrounded = true
            }
        }
        foregroundObserver = NotificationCenter.default.addObserver(
            forName: UIApplication.didBecomeActiveNotification,
            object: nil,
            queue: .main
        ) { [weak self] _ in
            Task { @MainActor [weak self] in
                self?.isBackgrounded = false
            }
        }
    }

    deinit {
        if let backgroundObserver {
            NotificationCenter.default.removeObserver(backgroundObserver)
        }
        if let foregroundObserver {
            NotificationCenter.default.removeObserver(foregroundObserver)
        }
    }

    /// Resolves the persisted bookmark, confirms the folder is still a readable directory,
    /// and checks whether it currently contains any CSV files. Never mutates local pending
    /// Garmin state — only reflects the state of the external folder itself.
    func probeAvailability(settings: SettingsStore) async {
        guard !isBusy else { return }
        guard settings.hasGarminSDCardFolderConfigured else {
            accessState = .notConfigured
            lastError = ""
            return
        }
        guard !isBackgrounded else {
            accessState = .checking
            return
        }

        isBusy = true
        accessState = .checking
        defer { isBusy = false }

        do {
            let token = try settings.beginGarminSDCardAccess()
            defer { token.stop() }

            var isDirectoryFlag: ObjCBool = false
            guard FileManager.default.fileExists(atPath: token.url.path, isDirectory: &isDirectoryFlag),
                  isDirectoryFlag.boolValue else {
                accessState = .unavailable
                lastError = "The configured Garmin folder is no longer a valid directory."
                return
            }

            let files = Self.enumerateCSVFiles(under: token.url)
            accessState = files.isEmpty ? .configuredFolderEmpty : .available
            lastError = ""
        } catch let error as GarminExternalFolderAccessError {
            accessState = (error == .accessNeedsRestoration) ? .accessNeedsRestoration : .unavailable
            lastError = error.localizedDescription
        } catch {
            accessState = .unavailable
            lastError = error.localizedDescription
        }
    }

    /// Enumerates `.csv` files under the configured root while access is held. The returned
    /// URLs remain security-scoped sub-paths of the root and are only guaranteed to resolve
    /// while another `withAccess` (or the same access window) is active.
    func enumerateCSVFiles(settings: SettingsStore) async throws -> [URL] {
        try await withAccess(settings: settings) { root in
            Self.enumerateCSVFiles(under: root)
        }
    }

    /// Pure, non-actor-isolated enumeration helper so background scanning work (e.g. from
    /// `Task.detached`) can reuse it without hopping back onto the main actor per file.
    /// Recurses at most `maxDepth` levels below `root` (SD cards typically nest a `Data Log`
    /// or `FPL/LOG` style folder one or two levels deep).
    nonisolated static func enumerateCSVFiles(under root: URL, maxDepth: Int = 3) -> [URL] {
        var results: [URL] = []
        func walk(_ directory: URL, depth: Int) {
            guard depth <= maxDepth,
                  let entries = try? FileManager.default.contentsOfDirectory(
                    at: directory,
                    includingPropertiesForKeys: [.isDirectoryKey, .fileSizeKey, .contentModificationDateKey],
                    options: [.skipsHiddenFiles]
                  ) else { return }
            for entry in entries {
                let isDirectory = (try? entry.resourceValues(forKeys: [.isDirectoryKey]))?.isDirectory ?? false
                if isDirectory {
                    walk(entry, depth: depth + 1)
                } else if entry.pathExtension.caseInsensitiveCompare("csv") == .orderedSame {
                    results.append(entry)
                }
            }
        }
        walk(root, depth: 0)
        return results
    }

    /// Runs `work` with the resolved root URL while security-scoped access is held, always
    /// balancing start/stop via `defer` regardless of how `work` completes. Rejects concurrent
    /// scans (`isBusy`) and backgrounded access attempts so the app never holds a stale scope.
    func withAccess<T>(settings: SettingsStore, work: (URL) async throws -> T) async throws -> T {
        guard !isBusy else { throw GarminExternalFolderAccessError.busy }
        guard !isBackgrounded else { throw GarminExternalFolderAccessError.backgrounded }

        isBusy = true
        let token: GarminSDCardAccessToken
        do {
            token = try settings.beginGarminSDCardAccess()
        } catch {
            isBusy = false
            throw error
        }
        defer {
            token.stop()
            isBusy = false
        }
        return try await work(token.url)
    }

    /// Copies an external (security-scoped) file into `Caches/GarminSDCardImportTemp` so the
    /// rest of the import pipeline (hashing, staging, upload) never depends on external access
    /// remaining open. The caller is responsible for holding access (via `withAccess`) for the
    /// duration of this call, and for deleting the temporary copy once finished with it.
    nonisolated func copyExternalFileToTemporary(url: URL) throws -> URL {
        let fileManager = FileManager.default
        let tempDirectory = try Self.temporaryImportDirectory()
        let destination = tempDirectory.appendingPathComponent(UUID().uuidString.lowercased() + ".csv")
        if fileManager.fileExists(atPath: destination.path) {
            try fileManager.removeItem(at: destination)
        }
        try fileManager.copyItem(at: url, to: destination)

        guard let attributes = try? fileManager.attributesOfItem(atPath: destination.path),
              let size = attributes[.size] as? Int,
              size > 0 else {
            try? fileManager.removeItem(at: destination)
            throw GarminExternalFolderAccessError.copyFailed("The copied file is empty or unreadable.")
        }
        return destination
    }

    nonisolated static func temporaryImportDirectory() throws -> URL {
        let caches = try FileManager.default.url(
            for: .cachesDirectory,
            in: .userDomainMask,
            appropriateFor: nil,
            create: true
        )
        let directory = caches.appendingPathComponent("GarminSDCardImportTemp", isDirectory: true)
        try FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
        return directory
    }
}
