import Combine
import CryptoKit
import Foundation

@MainActor
final class GarminSDCardRecoveryService: ObservableObject {
    @Published private(set) var isScanning = false
    @Published private(set) var cardConfigured = false
    @Published private(set) var cardAvailable = false
    @Published private(set) var lastSummary: GarminSDCardScanSummary?
    @Published private(set) var lastError = ""

    private let preferredFolderNames = ["data_log", "fdr_log"]
    private let maxScanDepth = 5

    func refreshBookmarkState(settings: SettingsStore) {
        cardConfigured = settings.garminSDCardBookmarkData != nil
        cardAvailable = false
        guard cardConfigured, let root = settings.resolvedGarminSDCardRootURL() else {
            cardAvailable = false
            return
        }
        var isDirectory: ObjCBool = false
        cardAvailable = FileManager.default.fileExists(atPath: root.path, isDirectory: &isDirectory) && isDirectory.boolValue
    }

    func scanAndImportIfNeeded(
        settings: SettingsStore,
        vault: GarminCsvVaultStore,
        workflow: CVRWorkflowStore
    ) async -> GarminSDCardScanSummary? {
        guard !settings.isSimulationModeEnabled else { return nil }
        refreshBookmarkState(settings: settings)
        guard cardConfigured else {
            let summary = GarminSDCardScanSummary(
                scannedAt: Date(),
                cardAvailable: false,
                dataRichFound: 0,
                gpsOnlySkipped: 0,
                alreadyKnown: 0,
                imported: 0,
                matchedFlightRecord: false,
                message: "Garmin SD card folder is not configured. Set it once in Admin."
            )
            lastSummary = summary
            return summary
        }
        guard cardAvailable, let root = settings.resolvedGarminSDCardRootURL() else {
            let summary = GarminSDCardScanSummary(
                scannedAt: Date(),
                cardAvailable: false,
                dataRichFound: 0,
                gpsOnlySkipped: 0,
                alreadyKnown: 0,
                imported: 0,
                matchedFlightRecord: false,
                message: "Insert the USB-C SD card reader and open Garmin Recovery again."
            )
            lastSummary = summary
            return summary
        }

        isScanning = true
        defer { isScanning = false }

        let accessed = root.startAccessingSecurityScopedResource()
        defer {
            if accessed {
                root.stopAccessingSecurityScopedResource()
            }
        }

        do {
            let candidates = try discoverDataRichCandidates(root: root)
            var gpsOnlySkipped = 0
            var alreadyKnown = 0
            var imported = 0
            var matched = false

            let flightRecord = workflow.state.activeFlightRecord
            let expectedTail = settings.selectedAircraft?.registration
                ?? workflow.state.activeDispatch?.tailNumber
                ?? ""
            let recordingWindow = recordingWindow(for: workflow)

            let best = selectBestCandidate(
                candidates,
                expectedTail: expectedTail,
                recordingWindow: recordingWindow
            )

            for candidate in candidates {
                let sha = try sha256(for: candidate.fileURL)
                let hadVaultRecord = vault.record(forSHA256: sha) != nil
                if hadVaultRecord {
                    alreadyKnown += 1
                }

                guard let best, best.fileURL == candidate.fileURL, let flightRecord else { continue }

                let componentID = workflow.importGarminCSVFromRecovery(
                    sourceURL: candidate.fileURL,
                    sourceLabel: "sd_card_auto_import"
                )
                if let componentID {
                    _ = try vault.ingest(
                        sourceURL: candidate.fileURL,
                        relativePath: candidate.relativePath,
                        metadata: candidate.metadata,
                        classification: candidate.classification,
                        flightRecordID: flightRecord.id,
                        uploadComponentID: componentID
                    )
                    if !hadVaultRecord || workflow.state.uploadComponents.contains(where: { $0.id == componentID && $0.state == .queued }) {
                        imported += 1
                    }
                    matched = true
                }
            }

            // Count skipped GPS-only during discovery pass
            gpsOnlySkipped = max(0, (try countGpsOnlySkipped(root: root)) - candidates.count)

            let message: String
            if let best, matched {
                message = "Imported data-rich log \(best.filename) for the active Flight Record."
            } else if candidates.isEmpty {
                message = "No data-rich Garmin CSV files were found on the SD card."
            } else if best == nil {
                message = "Found data-rich logs but none matched this aircraft or flight window."
            } else if flightRecord == nil {
                message = "Data-rich logs found. Create or recover a Flight Record before import."
            } else {
                message = "Scan complete. \(alreadyKnown) file(s) were already stored locally."
            }

            let summary = GarminSDCardScanSummary(
                scannedAt: Date(),
                cardAvailable: true,
                dataRichFound: candidates.count,
                gpsOnlySkipped: gpsOnlySkipped,
                alreadyKnown: alreadyKnown,
                imported: imported,
                matchedFlightRecord: matched,
                message: message
            )
            lastSummary = summary
            lastError = ""
            return summary
        } catch {
            lastError = error.localizedDescription
            let summary = GarminSDCardScanSummary(
                scannedAt: Date(),
                cardAvailable: true,
                dataRichFound: 0,
                gpsOnlySkipped: 0,
                alreadyKnown: 0,
                imported: 0,
                matchedFlightRecord: false,
                message: error.localizedDescription
            )
            lastSummary = summary
            return summary
        }
    }

    private struct RecordingWindow {
        var start: Date
        var end: Date
    }

    private func recordingWindow(for workflow: CVRWorkflowStore) -> RecordingWindow? {
        guard let flightRecord = workflow.state.activeFlightRecord else { return nil }
        let start = flightRecord.createdAt
        let end = flightRecord.updatedAt.addingTimeInterval(15 * 60)
        return RecordingWindow(start: start, end: end)
    }

    private func selectBestCandidate(
        _ candidates: [GarminSDCardCandidate],
        expectedTail: String,
        recordingWindow: RecordingWindow?
    ) -> GarminSDCardCandidate? {
        let scored = candidates.compactMap { candidate -> (GarminSDCardCandidate, Int)? in
            var score = 0
            if !expectedTail.isEmpty,
               GarminCsvClassifier.registrationsMatch(candidate.metadata.aircraftIdent, expectedTail) {
                score += 100
            }
            if let window = recordingWindow,
               let start = candidate.metadata.startUtc,
               let end = candidate.metadata.endUtc {
                if start <= window.end && end >= window.start {
                    score += 80
                } else {
                    let delta = abs(start.timeIntervalSince(window.start))
                    if delta <= 3600 {
                        score += max(0, 40 - Int(delta / 60))
                    }
                }
            }
            if candidate.classification.dataLogType == .fullAvionics {
                score += 20
            }
            if let modified = candidate.modificationDate {
                score += min(10, Int(Date().timeIntervalSince(modified) / -3600))
            }
            return score > 0 ? (candidate, score) : nil
        }
        return scored.max(by: { $0.1 < $1.1 })?.0 ?? candidates.first
    }

    private func discoverDataRichCandidates(root: URL) throws -> [GarminSDCardCandidate] {
        var results: [GarminSDCardCandidate] = []
        let fileManager = FileManager.default
        let enumerator = fileManager.enumerator(
            at: root,
            includingPropertiesForKeys: [.isRegularFileKey, .contentModificationDateKey],
            options: [.skipsHiddenFiles]
        )
        while let item = enumerator?.nextObject() as? URL {
            let depth = item.pathComponents.count - root.pathComponents.count
            if depth > maxScanDepth {
                enumerator?.skipDescendants()
                continue
            }
            guard item.pathExtension.lowercased() == "csv" else { continue }
            if !isPreferredPath(item, relativeTo: root) {
                continue
            }
            guard let candidate = try classifyCandidate(item, root: root) else { continue }
            if candidate.classification.isDataRich {
                results.append(candidate)
            }
        }
        return results.sorted {
            ($0.modificationDate ?? .distantPast) > ($1.modificationDate ?? .distantPast)
        }
    }

    private func countGpsOnlySkipped(root: URL) throws -> Int {
        var count = 0
        let fileManager = FileManager.default
        let enumerator = fileManager.enumerator(at: root, includingPropertiesForKeys: [.isRegularFileKey], options: [.skipsHiddenFiles])
        while let item = enumerator?.nextObject() as? URL {
            guard item.pathExtension.lowercased() == "csv" else { continue }
            if !isPreferredPath(item, relativeTo: root) { continue }
            if let candidate = try classifyCandidate(item, root: root),
               !candidate.classification.isDataRich {
                count += 1
            }
        }
        return count
    }

    private func isPreferredPath(_ url: URL, relativeTo root: URL) -> Bool {
        let relative = url.path.replacingOccurrences(of: root.path + "/", with: "").lowercased()
        if preferredFolderNames.contains(where: { relative.contains($0) }) {
            return true
        }
        // Also accept CSV at card root when bookmark points directly at data_log.
        return relative.hasSuffix(".csv")
    }

    private func classifyCandidate(_ url: URL, root: URL) throws -> GarminSDCardCandidate? {
        let preview = try G3XFlightStreamParser.parsePreview(fileURL: url)
        let classification = GarminCsvClassifier.classify(headers: preview.headers)
        guard classification.dataLogType != .invalid else { return nil }
        let values = try url.resourceValues(forKeys: [.contentModificationDateKey])
        let relative = url.path.replacingOccurrences(of: root.path + "/", with: "")
        return GarminSDCardCandidate(
            fileURL: url,
            filename: url.lastPathComponent,
            relativePath: relative,
            metadata: preview.metadata,
            classification: classification,
            modificationDate: values.contentModificationDate
        )
    }

    private func sha256(for url: URL) throws -> String {
        let data = try Data(contentsOf: url)
        return SHA256.hash(data: data).map { String(format: "%02x", $0) }.joined()
    }
}
