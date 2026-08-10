import Combine
import CryptoKit
import Foundation

/// Drives the Garmin SD Card import experience: probing folder access, scanning for CSV
/// candidates, resolving their local/server status, matching them to Log flights, and
/// performing the copy → hash-verify → stage → upload import pipeline.
///
/// Owns a private `GarminExternalFolderAccessService` instance; the app only needs to expose
/// this coordinator as an environment object.
@MainActor
final class GarminSDCardImportCoordinator: ObservableObject {
    @Published var accessState: GarminExternalFolderAccessState = .notConfigured
    @Published var folderInfo: GarminExternalFolderDisplayInfo?
    @Published var candidates: [GarminSDCardCandidate] = []
    @Published var isScanning = false
    @Published var scanPhase = ""
    @Published var scanFilesProcessed = 0
    @Published var scanFilesTotal = 0
    @Published var isImporting = false
    @Published var importPhase = ""
    @Published var lastImportResult: GarminSDCardImportResult?
    @Published var showExcluded = false
    @Published var filter: GarminSDCardImportFilter = .all
    @Published var selectedTargetFlightRecordID: String?
    @Published private(set) var guidedTargetFlightRecordID: String?
    @Published var lastError = ""
    @Published var serverStatusMessage = ""
    @Published var showingSetupSheet = false
    @Published var showingFileSheet = false
    @Published var showingUnavailableAlert = false
    @Published private(set) var importingCandidateID: String?

    let accessService = GarminExternalFolderAccessService()

    /// In-session cache: unchanged files (same path + size + mtime) skip re-parse / re-hash.
    private var fileScanCache: [String: GarminSDCardScanCacheEntry] = [:]
    /// Short-lived server known-hash results keyed by lowercase SHA-256.
    private var serverKnownHashCache: [String: GarminSDCardServerHashCacheEntry] = [:]
    /// Hashes recently confirmed absent on the server (avoid repeat lookups this session).
    private var serverUnknownHashCache: [String: Date] = [:]
    private let serverKnownHashCacheTTL: TimeInterval = 5 * 60

    /// 0...1 while scanning; nil when total is unknown.
    var scanProgress: Double? {
        guard isScanning, scanFilesTotal > 0 else { return nil }
        return min(1, max(0, Double(scanFilesProcessed) / Double(scanFilesTotal)))
    }
    var filteredCandidates: [GarminSDCardCandidate] {
        candidates.filter { candidate in
            if candidate.isExcluded && !showExcluded { return false }
            switch filter {
            case .all:
                return true
            case .new:
                return candidate.importState == .new
            case .needsAttention:
                return [
                    GarminSDCardImportState.storedOnIPhone,
                    .uploadPending,
                    .uploading,
                    .uploadedLinkingPending,
                    .syncFailed,
                    .duplicateOfPendingImport
                ].contains(candidate.importState)
            case .synced:
                return candidate.importState == .syncedAndLinked || candidate.importState == .alreadySynced
            }
        }
    }

    /// Called once at app launch to reflect any already-configured folder without opening
    /// any sheet or touching external storage.
    func bootstrap(settings: SettingsStore) {
        accessState = settings.hasGarminSDCardFolderConfigured ? .checking : .notConfigured
        updateFolderInfo(settings: settings)
    }

    // MARK: - Entry points

    func openFromLogRow(entry: CVRFlightLogEntry, settings: SettingsStore) {
        guidedTargetFlightRecordID = nil
        selectedTargetFlightRecordID = entry.flightRecordID
        filter = .all
        lastError = ""
        guard settings.hasGarminSDCardFolderConfigured else {
            showingSetupSheet = true
            return
        }
        showingFileSheet = true
        Task { await probe(settings: settings) }
    }

    func openGuidedFromLogRow(entry: CVRFlightLogEntry, settings: SettingsStore) {
        guidedTargetFlightRecordID = entry.flightRecordID
        selectedTargetFlightRecordID = entry.flightRecordID
        filter = .new
        lastError = ""
        guard settings.hasGarminSDCardFolderConfigured else {
            showingSetupSheet = true
            return
        }
        showingFileSheet = true
        Task { await probe(settings: settings) }
    }

    func openBrowse(settings: SettingsStore) {
        guidedTargetFlightRecordID = nil
        selectedTargetFlightRecordID = nil
        filter = .all
        lastError = ""
        guard settings.hasGarminSDCardFolderConfigured else {
            showingSetupSheet = true
            return
        }
        showingFileSheet = true
        Task { await probe(settings: settings) }
    }

    // MARK: - Folder lifecycle

    func probe(settings: SettingsStore) async {
        await accessService.probeAvailability(settings: settings)
        accessState = accessService.accessState
        lastError = accessService.lastError
        updateFolderInfo(settings: settings)
        if accessState == .unavailable || accessState == .accessNeedsRestoration {
            showingUnavailableAlert = true
        }
    }

    func selectFolder(_ url: URL, settings: SettingsStore) {
        switch settings.setGarminSDCardFolder(url) {
        case .success(let info):
            folderInfo = info
            showingSetupSheet = false
            showingUnavailableAlert = false
            lastError = ""
            // After first-time / restored setup, continue into the in-app file list.
            showingFileSheet = true
            accessState = .available
            clearScanCaches()
        case .failure(let error):
            lastError = error.localizedDescription
        }
    }

    /// Confirmation is handled by the UI before this is called.
    func clearFolder(settings: SettingsStore) {
        settings.clearGarminSDCardFolder()
        folderInfo = nil
        candidates = []
        accessState = .notConfigured
        lastError = ""
        showingSetupSheet = true
        clearScanCaches()
    }

    private func clearScanCaches() {
        fileScanCache.removeAll(keepingCapacity: false)
        serverKnownHashCache.removeAll(keepingCapacity: false)
        serverUnknownHashCache.removeAll(keepingCapacity: false)
    }

    /// After an import attempt, drop stale server lookups so the next status check can see the upload.
    private func invalidateServerHashCache(for sha256: String?) {
        guard let sha256, !sha256.isEmpty else { return }
        let key = sha256.lowercased()
        serverKnownHashCache.removeValue(forKey: key)
        serverUnknownHashCache.removeValue(forKey: key)
    }

    func dismissImportResult() {
        lastImportResult = nil
    }

    private func setCandidateState(_ contentKey: String, state: GarminSDCardImportState, linkedFlightRecordID: String? = nil) {
        guard let index = candidates.firstIndex(where: { $0.contentKey == contentKey }) else { return }
        candidates[index].importState = state
        if let linkedFlightRecordID {
            candidates[index].linkedFlightRecordID = linkedFlightRecordID
        }
    }

    private func updateFolderInfo(settings: SettingsStore) {
        folderInfo = settings.garminSDCardFolderDisplayInfo
    }

    // MARK: - Scan pipeline

    /// 1) Probe access. 2) Enumerate `.csv` files. 3) Classify + selectively hash (with
    /// in-session cache). 4) Resolve local pending status. 5) Batch-check known hashes
    /// (cached briefly). 6) Match candidates to Log flights. 7) Sort for display.
    func refreshCandidates(
        settings: SettingsStore,
        flightLogs: CVRFlightLogStore,
        network: NetworkMonitor,
        uploadManager: UploadManager
    ) async {
        guard !isScanning else { return }
        isScanning = true
        // Keep lastImportResult — crew needs lasting confirmation after import.
        // Only clear transient scan/access errors here.
        if !isImporting {
            lastError = ""
        }
        scanPhase = "Checking Garmin folder…"
        scanFilesProcessed = 0
        scanFilesTotal = 0
        defer {
            isScanning = false
            scanPhase = ""
            scanFilesProcessed = 0
            scanFilesTotal = 0
        }

        await probe(settings: settings)
        guard accessState == .available else {
            candidates = []
            return
        }

        let selectedTail = settings.selectedAircraft?.registration ?? ""
        let credential = settings.deviceCredential ?? ""
        let baseURL = settings.normalizedServerURL
        let canUploadNow = network.canUpload(allowCellular: settings.allowCellularUpload)
        let pendingSnapshot = flightLogs.pendingGarminCSV
        let logEntries = flightLogs.entries
        let selectedAircraftRegistration = settings.selectedAircraft?.registration ?? ""
        let targetFlightRecordID = selectedTargetFlightRecordID
        let cacheSnapshot = fileScanCache

        do {
            scanPhase = "Finding CSV files…"
            var cacheHits = 0
            var cacheMisses = 0
            let scanned = try await accessService.withAccess(settings: settings) { root in
                let files = GarminExternalFolderAccessService.enumerateCSVFiles(under: root)
                await MainActor.run {
                    self.scanFilesTotal = max(files.count, 1)
                    self.scanFilesProcessed = 0
                    self.scanPhase = files.isEmpty
                        ? "No CSV files found"
                        : "Scanning 0/\(files.count)…"
                }

                var built: [GarminSDCardCandidate] = []
                var nextCache: [String: GarminSDCardScanCacheEntry] = [:]
                built.reserveCapacity(files.count)

                for (index, fileURL) in files.enumerated() {
                    let relative = Self.relativePath(of: fileURL, under: root)
                    let attributes = try? FileManager.default.attributesOfItem(atPath: fileURL.path)
                    let byteCount = Int64((attributes?[.size] as? Int) ?? 0)
                    let modificationDate = attributes?[.modificationDate] as? Date
                    let cacheKey = Self.fileCacheKey(
                        relativePath: relative,
                        byteCount: byteCount,
                        modificationDate: modificationDate
                    )

                    let candidate: GarminSDCardCandidate
                    if let cached = cacheSnapshot[cacheKey] {
                        cacheHits += 1
                        candidate = Self.candidateFromCache(
                            cached,
                            fileURL: fileURL,
                            pending: pendingSnapshot
                        )
                        nextCache[cacheKey] = cached
                    } else {
                        cacheMisses += 1
                        let builtCandidate = await Task.detached(priority: .userInitiated) {
                            Self.buildCandidate(fileURL: fileURL, root: root, pending: pendingSnapshot)
                        }.value
                        candidate = builtCandidate
                        nextCache[cacheKey] = GarminSDCardScanCacheEntry(from: builtCandidate)
                    }

                    built.append(candidate)
                    let processed = index + 1
                    await MainActor.run {
                        self.scanFilesProcessed = processed
                        if cacheHits > 0 && cacheMisses == 0 {
                            self.scanPhase = "Using cache \(processed)/\(files.count)…"
                        } else if cacheHits > 0 {
                            self.scanPhase = "Scanning \(processed)/\(files.count) (\(cacheHits) cached)…"
                        } else {
                            self.scanPhase = "Reading \(processed)/\(files.count)…"
                        }
                    }
                }

                await MainActor.run {
                    self.fileScanCache = nextCache
                }
                return built
            }

            var updated = scanned

            if canUploadNow, !credential.isEmpty, let baseURL {
                let hashesToCheck = Array(Set(updated.compactMap(\.sha256)))
                if !hashesToCheck.isEmpty {
                    scanPhase = "Checking server status…"
                    if scanFilesTotal > 0 {
                        scanFilesProcessed = scanFilesTotal
                    }
                    let known = await resolveKnownHashes(
                        sha256List: hashesToCheck,
                        aircraftRegistration: selectedTail,
                        credential: credential,
                        baseURL: baseURL
                    )
                    updated = updated.map {
                        Self.applyServerKnownStatus(
                            $0,
                            known: known,
                            targetFlightRecordID: targetFlightRecordID
                        )
                    }
                } else {
                    serverStatusMessage = ""
                }
            } else {
                serverStatusMessage = "Offline or not enrolled — showing locally known status only."
            }

            scanPhase = "Finishing…"
            updated = updated.map {
                Self.applyMatching(
                    $0,
                    selectedAircraftRegistration: selectedAircraftRegistration,
                    logEntries: logEntries,
                    targetFlightRecordID: targetFlightRecordID
                )
            }
            candidates = Self.sortCandidates(updated)
        } catch let error as GarminExternalFolderAccessError {
            lastError = error.localizedDescription
            candidates = []
        } catch {
            lastError = error.localizedDescription
            candidates = []
        }
    }

    /// Returns known-hash entries, fetching only hashes missing from the short-lived cache.
    private func resolveKnownHashes(
        sha256List: [String],
        aircraftRegistration: String,
        credential: String,
        baseURL: URL
    ) async -> [CvrCsvKnownHashEntry] {
        let now = Date()
        serverKnownHashCache = serverKnownHashCache.filter {
            now.timeIntervalSince($0.value.checkedAt) <= serverKnownHashCacheTTL
        }
        serverUnknownHashCache = serverUnknownHashCache.filter {
            now.timeIntervalSince($0.value) <= serverKnownHashCacheTTL
        }

        var known: [CvrCsvKnownHashEntry] = []
        var missing: [String] = []
        for sha in sha256List {
            let key = sha.lowercased()
            if let cached = serverKnownHashCache[key] {
                known.append(cached.entry)
            } else if serverUnknownHashCache[key] != nil {
                continue
            } else {
                missing.append(sha)
            }
        }

        guard !missing.isEmpty else {
            // Quiet secondary note — never the primary import outcome.
            serverStatusMessage = ""
            return known
        }

        do {
            let response = try await APIClient(serverURL: baseURL).knownGarminCsvHashes(
                sha256List: missing,
                aircraftRegistration: aircraftRegistration,
                credential: credential
            )
            for entry in response.known {
                let key = entry.sha256.lowercased()
                serverKnownHashCache[key] = GarminSDCardServerHashCacheEntry(entry: entry, checkedAt: now)
                known.append(entry)
            }
            let knownKeys = Set(response.known.map { $0.sha256.lowercased() })
            for sha in missing where !knownKeys.contains(sha.lowercased()) {
                serverUnknownHashCache[sha.lowercased()] = now
            }
            serverStatusMessage = ""
            return known
        } catch {
            serverStatusMessage = "Could not verify server status: \(error.localizedDescription)"
            return known
        }
    }

    // MARK: - Import / resume

    /// Copies the external file into temporary local storage, verifies its checksum (and
    /// re-reads it once more as a defensive check), stages it via the existing
    /// `CVRFlightLogStore.stageGarminCSV` pipeline, and uploads it to the selected target
    /// flight. Never uploads directly from the external URL, and never deletes an existing
    /// local pending import if any step here fails.
    func importCandidate(
        _ candidate: GarminSDCardCandidate,
        settings: SettingsStore,
        flightLogs: CVRFlightLogStore,
        uploadManager: UploadManager,
        network: NetworkMonitor
    ) async {
        guard candidate.canImport else { return }
        guard let externalURL = candidate.externalURL else {
            lastError = "This file is no longer available from the SD card. Rescan and try again."
            lastImportResult = GarminSDCardImportResult(
                kind: .failure,
                filename: candidate.filename,
                message: lastError
            )
            return
        }
        guard let targetFlightRecordID = selectedTargetFlightRecordID,
              let entry = flightLogs.entries.first(where: { $0.flightRecordID == targetFlightRecordID }) else {
            lastError = "Select a flight from the Log before importing this Garmin CSV."
            lastImportResult = GarminSDCardImportResult(
                kind: .failure,
                filename: candidate.filename,
                message: lastError
            )
            return
        }
        if let guidedTargetFlightRecordID,
           guidedTargetFlightRecordID != targetFlightRecordID {
            lastError = "The mandatory Garmin handoff must remain linked to its completed flight."
            lastImportResult = GarminSDCardImportResult(
                kind: .failure,
                filename: candidate.filename,
                message: lastError
            )
            return
        }
        if guidedTargetFlightRecordID == targetFlightRecordID,
           !candidate.isRecommended || candidate.matchWarning != nil {
            lastError = "This CSV is not an exact aircraft and timestamp match for the completed flight."
            lastImportResult = GarminSDCardImportResult(
                kind: .failure,
                filename: candidate.filename,
                message: lastError
            )
            return
        }
        guard !isImporting else { return }

        isImporting = true
        importingCandidateID = candidate.id
        importPhase = "Copying from SD card…"
        lastImportResult = nil
        lastError = ""
        setCandidateState(candidate.contentKey, state: .uploading, linkedFlightRecordID: targetFlightRecordID)
        defer {
            isImporting = false
            importingCandidateID = nil
            importPhase = ""
        }

        var tempURL: URL?
        defer {
            if let tempURL {
                try? FileManager.default.removeItem(at: tempURL)
            }
        }

        do {
            let copied = try await accessService.withAccess(settings: settings) { _ in
                try self.accessService.copyExternalFileToTemporary(url: externalURL)
            }
            tempURL = copied

            importPhase = "Verifying copy…"
            let data = try Data(contentsOf: copied)
            guard !data.isEmpty else {
                throw GarminExternalFolderAccessError.copyFailed("The copied Garmin CSV is empty.")
            }
            let digest = CVRPendingGarminPersistence.sha256Hex(of: data)
            if let expected = candidate.sha256, digest.caseInsensitiveCompare(expected) != .orderedSame {
                throw GarminExternalFolderAccessError.verificationFailed(
                    "The copied file does not match the original checksum. Try importing again."
                )
            }
            // Reopen verify: confirm the staged temporary copy is durably readable before
            // handing it to the existing stage/upload pipeline.
            _ = try Data(contentsOf: copied)

            importPhase = "Storing on this iPhone…"
            guard flightLogs.stageGarminCSV(from: copied) else {
                let message = flightLogs.lastError.isEmpty ? "Could not stage the Garmin CSV." : flightLogs.lastError
                lastError = message
                lastImportResult = GarminSDCardImportResult(
                    kind: .failure,
                    filename: candidate.filename,
                    message: message
                )
                setCandidateState(candidate.contentKey, state: .syncFailed)
                return
            }
            setCandidateState(candidate.contentKey, state: .uploadPending, linkedFlightRecordID: targetFlightRecordID)

            importPhase = "Uploading to flight…"
            await flightLogs.uploadPendingGarminCSV(to: entry, settings: settings, uploadManager: uploadManager)

            let targetLabel = "\(entry.scheduledDate) · \(entry.departureAirport)→\(entry.arrivalAirport)"
            let stillPending = flightLogs.pendingGarminCSV != nil
            let uploadError = flightLogs.lastError.trimmingCharacters(in: .whitespacesAndNewlines)

            if uploadError.isEmpty && !stillPending {
                lastError = ""
                lastImportResult = GarminSDCardImportResult(
                    kind: .success,
                    filename: candidate.filename,
                    message: "Imported and uploaded to \(targetLabel)."
                )
                setCandidateState(candidate.contentKey, state: .syncedAndLinked, linkedFlightRecordID: targetFlightRecordID)
            } else if stillPending {
                let message = uploadError.isEmpty
                    ? "Stored on this iPhone for \(targetLabel). Synchronize the flight, then retry — you will not need the SD card again."
                    : uploadError
                lastError = message
                lastImportResult = GarminSDCardImportResult(
                    kind: .pending,
                    filename: candidate.filename,
                    message: message
                )
                setCandidateState(
                    candidate.contentKey,
                    state: uploadError.isEmpty ? .uploadPending : .syncFailed,
                    linkedFlightRecordID: targetFlightRecordID
                )
            } else {
                lastError = uploadError
                lastImportResult = GarminSDCardImportResult(
                    kind: .failure,
                    filename: candidate.filename,
                    message: uploadError.isEmpty ? "Import did not complete." : uploadError
                )
                setCandidateState(candidate.contentKey, state: .syncFailed)
            }

            invalidateServerHashCache(for: digest)
            importPhase = "Refreshing status…"
            await refreshCandidates(settings: settings, flightLogs: flightLogs, network: network, uploadManager: uploadManager)
            // Refresh can briefly miss a just-uploaded hash; keep the outcome visible on the row.
            if lastImportResult?.kind == .success {
                setCandidateState(candidate.contentKey, state: .syncedAndLinked, linkedFlightRecordID: targetFlightRecordID)
                if let index = candidates.firstIndex(where: {
                    ($0.sha256 ?? "").caseInsensitiveCompare(digest) == .orderedSame
                }) {
                    candidates[index].importState = .syncedAndLinked
                    candidates[index].linkedFlightRecordID = targetFlightRecordID
                }
            } else if lastImportResult?.kind == .pending {
                setCandidateState(
                    candidate.contentKey,
                    state: stillPending && uploadError.isEmpty ? .uploadPending : .syncFailed,
                    linkedFlightRecordID: targetFlightRecordID
                )
            }
        } catch let error as GarminExternalFolderAccessError {
            lastError = error.localizedDescription
            lastImportResult = GarminSDCardImportResult(
                kind: .failure,
                filename: candidate.filename,
                message: error.localizedDescription
            )
            setCandidateState(candidate.contentKey, state: .syncFailed)
        } catch {
            lastError = error.localizedDescription
            lastImportResult = GarminSDCardImportResult(
                kind: .failure,
                filename: candidate.filename,
                message: error.localizedDescription
            )
            setCandidateState(candidate.contentKey, state: .syncFailed)
        }
    }

    /// Resumes an already-staged local pending import (or a preserved failed association)
    /// instead of copying from the SD card again — a duplicate/pending file is resumed, not
    /// re-staged, per the operational contract.
    func resumeCandidate(
        _ candidate: GarminSDCardCandidate,
        settings: SettingsStore,
        flightLogs: CVRFlightLogStore,
        uploadManager: UploadManager
    ) async {
        guard candidate.canResume else { return }
        guard !isImporting else { return }

        isImporting = true
        importingCandidateID = candidate.id
        importPhase = "Retrying upload…"
        lastImportResult = nil
        lastError = ""
        setCandidateState(candidate.contentKey, state: .uploading, linkedFlightRecordID: candidate.linkedFlightRecordID)
        defer {
            isImporting = false
            importingCandidateID = nil
            importPhase = ""
        }

        if let targetID = candidate.linkedFlightRecordID ?? selectedTargetFlightRecordID,
           let entry = flightLogs.entries.first(where: { $0.flightRecordID == targetID }) {
            await flightLogs.uploadPendingGarminCSV(to: entry, settings: settings, uploadManager: uploadManager)
        } else {
            await flightLogs.retryPendingGarminCSV(settings: settings, uploadManager: uploadManager)
        }

        let stillPending = flightLogs.pendingGarminCSV != nil
        let uploadError = flightLogs.lastError.trimmingCharacters(in: .whitespacesAndNewlines)
        if uploadError.isEmpty && !stillPending {
            lastError = ""
            lastImportResult = GarminSDCardImportResult(
                kind: .success,
                filename: candidate.filename,
                message: "Upload completed for \(candidate.filename)."
            )
            setCandidateState(
                candidate.contentKey,
                state: .syncedAndLinked,
                linkedFlightRecordID: candidate.linkedFlightRecordID ?? selectedTargetFlightRecordID
            )
        } else if stillPending {
            let message = uploadError.isEmpty
                ? "Still stored on this iPhone. Synchronize the flight, then retry."
                : uploadError
            lastError = message
            lastImportResult = GarminSDCardImportResult(
                kind: .pending,
                filename: candidate.filename,
                message: message
            )
            setCandidateState(
                candidate.contentKey,
                state: uploadError.isEmpty ? .uploadPending : .syncFailed,
                linkedFlightRecordID: candidate.linkedFlightRecordID
            )
        } else {
            lastError = uploadError
            lastImportResult = GarminSDCardImportResult(
                kind: .failure,
                filename: candidate.filename,
                message: uploadError.isEmpty ? "Retry did not complete." : uploadError
            )
            setCandidateState(candidate.contentKey, state: .syncFailed)
        }

        invalidateServerHashCache(for: candidate.sha256 ?? flightLogs.pendingGarminCSV?.sha256)
    }

    // MARK: - Background scan helpers
    // `buildCandidate` is `nonisolated` so per-file work can run in `Task.detached` without
    // blocking the main actor; progress updates hop back via `MainActor.run`.

    nonisolated private static func buildCandidate(
        fileURL: URL,
        root: URL,
        pending: CVRPendingGarminCSV?
    ) -> GarminSDCardCandidate {
        let fileManager = FileManager.default
        let relativeFilePath = relativePath(of: fileURL, under: root)
        let attributes = try? fileManager.attributesOfItem(atPath: fileURL.path)
        let byteCount = Int64((attributes?[.size] as? Int) ?? 0)
        let modificationDate = attributes?[.modificationDate] as? Date

        var classification = GarminCsvClassification(dataLogType: .unknownSupported, isDataRich: false, reason: "")
        var importState: GarminSDCardImportState = .unknown
        var excludedReason: String?
        var aircraftIdent = ""
        var startUtc: Date?
        var endUtc: Date?
        var rowCount = 0

        switch GarminCsvClassifier.classifyImportCandidate(fileURL: fileURL) {
        case .classified(let result):
            classification = result
            importState = .new
            if result.isDataRich {
                // Always parse data-rich candidates so duration/row count can exclude
                // power-up snippets (often ~4–30 KB with only a second or two of samples).
                if let full = try? G3XFlightStreamParser.parse(fileURL: fileURL) {
                    aircraftIdent = full.metadata.aircraftIdent
                    startUtc = full.metadata.startUtc
                    endUtc = full.metadata.endUtc
                    rowCount = full.metadata.rowCount
                } else if let preview = try? G3XFlightStreamParser.parsePreview(fileURL: fileURL) {
                    aircraftIdent = preview.metadata.aircraftIdent
                }
            } else if let preview = try? G3XFlightStreamParser.parsePreview(fileURL: fileURL) {
                aircraftIdent = preview.metadata.aircraftIdent
            }
        case .gpsOnly:
            classification = GarminCsvClassification(
                dataLogType: .gpsOnly,
                isDataRich: false,
                reason: "GPS track only; no engine or avionics fields."
            )
            importState = .gpsOnly
            excludedReason = "GPS track only — no engine/avionics data to import."
        case .invalid(let reason):
            importState = .invalid
            excludedReason = reason
        case .unsupported(let reason):
            importState = .unsupported
            excludedReason = reason
        case .unreadable(let reason):
            importState = .unreadable
            excludedReason = reason
        case .unknown(let reason):
            importState = .unknown
            excludedReason = reason
        }

        // Power-up / shutdown snippets can be 4–30+ KB (wide G3X headers) with only
        // ~1 second of samples. Size alone is not enough — require real flight length.
        if classification.isDataRich {
            let durationSeconds = Self.flightDurationSeconds(start: startUtc, end: endUtc)
            if let reason = Self.powerUpExclusionReason(
                byteCount: byteCount,
                rowCount: rowCount,
                durationSeconds: durationSeconds
            ) {
                classification = GarminCsvClassification(
                    dataLogType: .invalid,
                    isDataRich: false,
                    reason: reason
                )
                importState = .invalid
                excludedReason = reason
            }
        }

        // Hash only files that are worth tracking precisely: data-rich imports, or files that
        // might already correspond to a locally staged pending import (needed to resolve
        // "resume, don't restage").
        var sha256: String?
        let shouldHash = classification.isDataRich
            || importState == .new
            || (pending != nil && pending?.originalFilename == fileURL.lastPathComponent)
        if shouldHash, byteCount > 0, let data = try? Data(contentsOf: fileURL) {
            sha256 = CVRPendingGarminPersistence.sha256Hex(of: data)
        }

        var linkedFlightRecordID: String?
        if let sha256, let pending, pending.sha256.caseInsensitiveCompare(sha256) == .orderedSame {
            importState = pendingImportState(for: pending)
            linkedFlightRecordID = pending.targetFlightRecordID
        }

        let contentKey = sha256 ?? "\(relativeFilePath)|\(byteCount)|\(modificationDate?.timeIntervalSince1970 ?? 0)"

        return GarminSDCardCandidate(
            contentKey: contentKey,
            filename: fileURL.lastPathComponent,
            relativePath: relativeFilePath,
            byteCount: byteCount,
            modificationDate: modificationDate,
            classification: classification,
            importState: importState,
            aircraftIdent: aircraftIdent,
            startUtc: startUtc,
            endUtc: endUtc,
            rowCount: rowCount,
            sha256: sha256,
            linkedFlightRecordID: linkedFlightRecordID,
            isRecommended: false,
            matchWarning: nil,
            excludedReason: excludedReason,
            serverStatusCheckedAt: nil,
            usingCachedServerStatus: false,
            externalURL: fileURL
        )
    }

    nonisolated private static func pendingImportState(for pending: CVRPendingGarminCSV) -> GarminSDCardImportState {
        if let failure = pending.lastFailureMessage, !failure.isEmpty {
            return .syncFailed
        }
        if pending.targetFlightRecordID != nil {
            return .uploadPending
        }
        return .storedOnIPhone
    }

    nonisolated private static func relativePath(of fileURL: URL, under root: URL) -> String {
        let filePath = fileURL.path
        let rootPath = root.path
        guard filePath.hasPrefix(rootPath) else { return fileURL.lastPathComponent }
        let trimmed = filePath.dropFirst(rootPath.count)
        return trimmed.trimmingCharacters(in: CharacterSet(charactersIn: "/"))
    }

    private static func applyServerKnownStatus(
        _ candidate: GarminSDCardCandidate,
        known: [CvrCsvKnownHashEntry],
        targetFlightRecordID: String?
    ) -> GarminSDCardCandidate {
        // Local incomplete / active states take priority over server-known hashes.
        switch candidate.importState {
        case .checkingStatus, .storedOnIPhone, .uploadPending, .uploading,
             .syncFailed, .duplicateOfPendingImport, .syncedAndLinked:
            return candidate
        default:
            break
        }

        guard let sha256 = candidate.sha256,
              let match = known.first(where: { $0.sha256.caseInsensitiveCompare(sha256) == .orderedSame }) else {
            return candidate
        }
        var updated = candidate
        updated.serverStatusCheckedAt = Date()
        updated.usingCachedServerStatus = false
        let linkedUUID = (match.workflowFlightRecordUuid ?? "").trimmingCharacters(in: .whitespacesAndNewlines)

        if match.workflowLinked == true, !linkedUUID.isEmpty {
            updated.linkedFlightRecordID = linkedUUID
            if let targetFlightRecordID,
               targetFlightRecordID.caseInsensitiveCompare(linkedUUID) != .orderedSame {
                updated.importState = .alreadySynced
            } else {
                updated.importState = .syncedAndLinked
            }
        } else {
            // Server has the file bytes, but workflow linkage is not confirmed.
            updated.importState = .uploadedLinkingPending
            if !linkedUUID.isEmpty {
                updated.linkedFlightRecordID = linkedUUID
            }
        }
        return updated
    }

    private static func applyMatching(
        _ candidate: GarminSDCardCandidate,
        selectedAircraftRegistration: String,
        logEntries: [CVRFlightLogEntry],
        targetFlightRecordID: String?
    ) -> GarminSDCardCandidate {
        guard candidate.canImport || candidate.canResume else { return candidate }
        var updated = candidate

        func tailMatches(_ entry: CVRFlightLogEntry) -> Bool {
            candidate.aircraftIdent.isEmpty
                || GarminCsvClassifier.registrationsMatch(candidate.aircraftIdent, entry.aircraftRegistration)
        }

        func overlaps(_ entry: CVRFlightLogEntry) -> Bool {
            // Prefer operational-day match in Pacific — schedule slots and Garmin local days align here.
            if let start = candidate.startUtc,
               isSameOperationalDay(entry.scheduledDate, referenceDate: start) {
                return true
            }
            if isSameOperationalDay(entry.scheduledDate, referenceDate: candidate.modificationDate) {
                return true
            }
            guard let start = candidate.startUtc else { return false }
            let end = candidate.endUtc ?? start
            guard let entryStart = parseEntryTimestamp(entry.departureTime) else {
                return false
            }
            let entryEnd = parseEntryTimestamp(entry.arrivalTime) ?? entryStart.addingTimeInterval(8 * 3600)
            // Wide operational window: training flights often start before / end after the booked slot.
            let window: TimeInterval = 12 * 3600
            return start <= entryEnd.addingTimeInterval(window) && end >= entryStart.addingTimeInterval(-window)
        }

        if let targetFlightRecordID,
           let target = logEntries.first(where: { $0.flightRecordID == targetFlightRecordID }) {
            if tailMatches(target) && overlaps(target) {
                updated.isRecommended = true
            } else if !tailMatches(target) {
                let ident = candidate.aircraftIdent.isEmpty ? "unknown tail" : candidate.aircraftIdent
                updated.matchWarning = "File reports tail \(ident); selected flight is \(target.aircraftRegistration)."
            } else if !overlaps(target) {
                // Soft warning — import remains allowed; crew can still proceed.
                updated.matchWarning = "Recorded time may not match the selected flight's schedule. Confirm before importing."
            }
            return updated
        }

        let matchingEntries = logEntries.filter { tailMatches($0) && overlaps($0) }
        if matchingEntries.count == 1 {
            updated.isRecommended = true
        } else if !candidate.aircraftIdent.isEmpty,
                  !GarminCsvClassifier.registrationsMatch(candidate.aircraftIdent, selectedAircraftRegistration) {
            updated.matchWarning = "File aircraft ident \(candidate.aircraftIdent) does not match the selected aircraft."
        }
        return updated
    }

    private static func sortCandidates(_ candidates: [GarminSDCardCandidate]) -> [GarminSDCardCandidate] {
        func rank(_ candidate: GarminSDCardCandidate) -> Int {
            if candidate.isRecommended { return 0 }
            switch candidate.importState {
            case .new: return 1
            case .syncFailed, .duplicateOfPendingImport, .uploadedLinkingPending: return 2
            case .storedOnIPhone, .uploadPending, .uploading, .checkingStatus: return 3
            case .syncedAndLinked, .alreadySynced: return 4
            case .gpsOnly, .invalid, .unsupported, .unreadable, .unknown: return 5
            }
        }
        return candidates.sorted { lhs, rhs in
            let lRank = rank(lhs)
            let rRank = rank(rhs)
            if lRank != rRank { return lRank < rRank }
            return (lhs.modificationDate ?? .distantPast) > (rhs.modificationDate ?? .distantPast)
        }
    }

    private static func parseEntryTimestamp(_ value: String?) -> Date? {
        guard let value, !value.isEmpty else { return nil }
        let hasExplicitTimeZone = value.hasSuffix("Z")
            || value.range(of: #"[+-]\d{2}:\d{2}$"#, options: .regularExpression) != nil
        if hasExplicitTimeZone {
            let iso = ISO8601DateFormatter()
            iso.formatOptions = [.withInternetDateTime]
            if let date = iso.date(from: value) { return date }
        }
        let local = DateFormatter()
        local.calendar = Calendar(identifier: .gregorian)
        local.locale = Locale(identifier: "en_US_POSIX")
        local.timeZone = TimeZone(identifier: "America/Los_Angeles") ?? .current
        local.dateFormat = "yyyy-MM-dd'T'HH:mm:ss"
        return local.date(from: value)
    }

    private static func isSameOperationalDay(_ scheduledDate: String, referenceDate: Date?) -> Bool {
        guard let referenceDate else { return false }
        let formatter = DateFormatter()
        formatter.calendar = Calendar(identifier: .gregorian)
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.timeZone = TimeZone(identifier: "America/Los_Angeles") ?? .current
        formatter.dateFormat = "yyyy-MM-dd"
        return formatter.string(from: referenceDate) == scheduledDate
    }

    /// Tiny absolute floor for empty/near-empty files.
    nonisolated private static let minimumEligibleByteCount: Int64 = 8_192
    /// Power-up / idle sessions of several minutes are common; require a real leg length.
    nonisolated private static let minimumEligibleRowCount = 300
    nonisolated private static let minimumEligibleDurationSeconds: TimeInterval = 5 * 60
    /// TEMPORARY TEST OVERRIDE: allow short but non-empty data-rich CSV files
    /// while the complete post-flight handoff is being flight-tested.
    nonisolated private static let allowShortCSVForPostFlightFlowTesting = true

    nonisolated private static func flightDurationSeconds(start: Date?, end: Date?) -> TimeInterval? {
        guard let start, let end, end >= start else { return nil }
        return end.timeIntervalSince(start)
    }

    /// Returns an exclusion reason when the CSV is a power-up / too-short snippet.
    nonisolated private static func powerUpExclusionReason(
        byteCount: Int64,
        rowCount: Int,
        durationSeconds: TimeInterval?
    ) -> String? {
        if byteCount > 0 && byteCount < minimumEligibleByteCount {
            if !allowShortCSVForPostFlightFlowTesting {
                return "Too short — file is too small (\(formatBytes(byteCount)))."
            }
        }
        if let durationSeconds, durationSeconds < minimumEligibleDurationSeconds {
            if !allowShortCSVForPostFlightFlowTesting {
                return "Too short — \(formatDuration(durationSeconds)) of data (need at least 5 minutes)."
            }
        }
        if rowCount > 0 && rowCount < minimumEligibleRowCount {
            if !allowShortCSVForPostFlightFlowTesting {
                return "Too short — \(rowCount) sample(s) (need at least \(minimumEligibleRowCount))."
            }
        }
        // Data-rich headers alone are not enough: if we could not prove duration or samples,
        // do not offer Import (avoids short power-up logs with failed time parse).
        if durationSeconds == nil && rowCount <= 0 {
            if !allowShortCSVForPostFlightFlowTesting {
                return "Too short — could not confirm at least 5 minutes of flight data."
            }
        }
        return nil
    }

    nonisolated private static func formatBytes(_ count: Int64) -> String {
        ByteCountFormatter.string(fromByteCount: count, countStyle: .file)
    }

    nonisolated private static func formatDuration(_ seconds: TimeInterval) -> String {
        let whole = max(0, Int(seconds.rounded()))
        if whole < 60 {
            return "\(whole)s"
        }
        let minutes = whole / 60
        let rem = whole % 60
        return String(format: "%d:%02d", minutes, rem)
    }

    nonisolated private static func fileCacheKey(
        relativePath: String,
        byteCount: Int64,
        modificationDate: Date?
    ) -> String {
        let mtime = modificationDate?.timeIntervalSince1970 ?? 0
        return "\(relativePath)|\(byteCount)|\(mtime)"
    }

    nonisolated private static func candidateFromCache(
        _ cached: GarminSDCardScanCacheEntry,
        fileURL: URL,
        pending: CVRPendingGarminCSV?
    ) -> GarminSDCardCandidate {
        var importState = cached.importState
        var linkedFlightRecordID: String?
        if let sha256 = cached.sha256,
           let pending,
           pending.sha256.caseInsensitiveCompare(sha256) == .orderedSame {
            importState = pendingImportState(for: pending)
            linkedFlightRecordID = pending.targetFlightRecordID
        }

        return GarminSDCardCandidate(
            contentKey: cached.sha256
                ?? "\(cached.relativePath)|\(cached.byteCount)|\(cached.modificationDate?.timeIntervalSince1970 ?? 0)",
            filename: cached.filename,
            relativePath: cached.relativePath,
            byteCount: cached.byteCount,
            modificationDate: cached.modificationDate,
            classification: cached.classification,
            importState: importState,
            aircraftIdent: cached.aircraftIdent,
            startUtc: cached.startUtc,
            endUtc: cached.endUtc,
            rowCount: cached.rowCount,
            sha256: cached.sha256,
            linkedFlightRecordID: linkedFlightRecordID,
            isRecommended: false,
            matchWarning: nil,
            excludedReason: cached.excludedReason,
            serverStatusCheckedAt: nil,
            usingCachedServerStatus: false,
            externalURL: fileURL
        )
    }
}

/// Intrinsic file analysis reused across rescans when path/size/mtime are unchanged.
private struct GarminSDCardScanCacheEntry: Equatable {
    var filename: String
    var relativePath: String
    var byteCount: Int64
    var modificationDate: Date?
    var classification: GarminCsvClassification
    var importState: GarminSDCardImportState
    var excludedReason: String?
    var aircraftIdent: String
    var startUtc: Date?
    var endUtc: Date?
    var rowCount: Int
    var sha256: String?

    init(from candidate: GarminSDCardCandidate) {
        filename = candidate.filename
        relativePath = candidate.relativePath
        byteCount = candidate.byteCount
        modificationDate = candidate.modificationDate
        classification = candidate.classification
        excludedReason = candidate.excludedReason
        aircraftIdent = candidate.aircraftIdent
        startUtc = candidate.startUtc
        endUtc = candidate.endUtc
        rowCount = candidate.rowCount
        sha256 = candidate.sha256
        // Do not persist pending/upload overlays — those are reapplied from Flight Log state.
        switch candidate.importState {
        case .storedOnIPhone, .uploadPending, .uploading, .syncFailed, .duplicateOfPendingImport,
             .uploadedLinkingPending, .syncedAndLinked, .alreadySynced, .checkingStatus:
            importState = candidate.classification.isDataRich ? .new : candidate.importState
        default:
            importState = candidate.importState
        }
    }
}

private struct GarminSDCardServerHashCacheEntry {
    var entry: CvrCsvKnownHashEntry
    var checkedAt: Date
}
