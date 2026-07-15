import CryptoKit
import Foundation

protocol SyncProvider {
    var identifier: String { get }
    var displayName: String { get }

    func connectionStatus() async -> ProviderConnectionStatus
    func connect() async throws
    func verifyConnection() async throws -> ProviderVerificationResult
    func discoverNewItems() async throws -> [RemoteSyncItem]
    func download(item: RemoteSyncItem) async throws -> [DownloadedArtifact]
    func disconnect() async throws
}

final class ProviderRegistry {
    let garminProvider: GarminProvider

    init(garminProvider: GarminProvider) {
        self.garminProvider = garminProvider
    }

    var providers: [SyncProvider] { [garminProvider] }
}

enum GarminError: Error, LocalizedError {
    case loginRequired
    case humanVerification
    case timeout(String)
    case gpxOnly(String)
    case unexpectedResponse(String)
    case logbookEndpointUnavailable
    case initialSyncBootstrapRequired
    case noSources
    case downloadFailed(String)

    var errorDescription: String? {
        switch self {
        case .loginRequired: return "Garmin still needs login or MFA. Finish login in Chrome and try again."
        case .humanVerification: return "Garmin is still asking to verify you are human. Finish that step manually in Chrome."
        case .timeout(let message): return message
        case .gpxOnly(let message): return message
        case .unexpectedResponse(let message): return message
        case .logbookEndpointUnavailable: return "GARMIN_LOGBOOK_ENDPOINT_UNAVAILABLE"
        case .initialSyncBootstrapRequired: return "The app does not yet have a valid initial Garmin sync cursor. Reload Garmin Logbook for Initial Sync."
        case .noSources: return "No Garmin source files were found for this entry."
        case .downloadFailed(let message): return message
        }
    }
}

enum GarminRoutes {
    static let logbookBase = "https://fly.garmin.com/fly-garmin/api/logbook/"

    static func incrementalLogbookURL(version: String) throws -> URL {
        guard !version.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty else {
            throw GarminError.initialSyncBootstrapRequired
        }
        var components = URLComponents(string: logbookBase)!
        components.queryItems = [URLQueryItem(name: "since", value: version)]
        guard let url = components.url, isValidLogbookAPIURL(url) else {
            throw GarminError.logbookEndpointUnavailable
        }
        return url
    }

    static func trackURL(trackUUID: String) throws -> URL {
        guard trackUUID.range(of: #"^[A-Fa-f0-9-]{36}$"#, options: .regularExpression) != nil else {
            throw GarminError.logbookEndpointUnavailable
        }
        guard let url = URL(string: "https://fly.garmin.com/fly-garmin/api/logbook/tracks/\(trackUUID)/"),
              isValidLogbookAPIURL(url) else {
            throw GarminError.logbookEndpointUnavailable
        }
        return url
    }

    static func isValidLogbookAPIURL(_ url: URL) -> Bool {
        url.scheme == "https" &&
            url.host == "fly.garmin.com" &&
            url.path.hasPrefix("/fly-garmin/api/logbook/")
    }
}

final class GarminProvider: SyncProvider {
    let identifier = "garmin_flygarmin_logbook"
    let displayName = "Garmin FlyGarmin Logbook"
    private let browser: BrowserSessionController
    private let artifactStore: LocalArtifactStore
    private let cursorStore: CursorStore
    private(set) var lastDebugSummary: GarminDebugSummary?
    private(set) var lastReadinessMessage = ""
    private(set) var lastReturnedCursor: String?

    private struct LogbookCandidate {
        let snapshot: GarminLogbookNetworkSnapshot
        let json: JSONValue?
        let score: Int
        let entryCount: Int
        let topKeys: [String]
        let reason: String
        let result: [String: JSONValue]?
    }

    private struct InitialBootstrapCandidate {
        let snapshot: GarminLogbookNetworkSnapshot
        let json: JSONValue?
        let topKeys: [String]
        let arraySummary: String
        let versionPresent: Bool
        let entryCount: Int?
        let cursorLikePaths: [String]
        let reason: String
    }

    init(browser: BrowserSessionController, artifactStore: LocalArtifactStore, cursorStore: CursorStore) {
        self.browser = browser
        self.artifactStore = artifactStore
        self.cursorStore = cursorStore
    }

    func connectionStatus() async -> ProviderConnectionStatus {
        do {
            let result = try await verifyConnection()
            return result.connected ? .connected : .waitingForLogin
        } catch {
            return .authenticationRequired
        }
    }

    @MainActor
    func connect() async throws {
        try await browser.openGarminForLogin()
    }

    func verifyConnection() async throws -> ProviderVerificationResult {
        let cursor = try requireSavedCursor()
        let object = try await logbookObject(cursor: cursor, mode: .incremental)
        updateDebugSummary(from: object)
        try validateProbe(object)
        return ProviderVerificationResult(
            connected: true,
            entryCount: object["entryCount"]?.int ?? 0,
            cursor: object["cursor"]?.string,
            topLevelKeys: object["topLevelKeys"]?.array?.compactMap(\.string) ?? [],
            firstSourceUUID: object["firstFlightDataLogUUID"]?.string
        )
    }

    func discoverNewItems() async throws -> [RemoteSyncItem] {
        try await discoverNewItems(useCursor: false)
    }

    func reloadLogbookForInitialSync() async throws {
        let client = try await browser.reloadLogbookForInitialSyncCapture()
        lastReadinessMessage = "Reloaded Garmin Logbook. Waiting for confirmed Logbook API response..."
        try await discoverInitialBootstrap(client: client)
        let object: [String: JSONValue]
        if let captured = try await capturedPreloadLogbookObject(client: client) {
            object = captured
        } else {
            object = try await initialBootstrapLogbookObject()
        }
        try validateProbe(object)
        guard let cursor = object["cursor"]?.string, CursorStore.validationRejectionReason(cursor) == nil else {
            cursorStore.logCursor("BOOTSTRAP_CURSOR_REJECTED_REASON", provider: identifier, value: object["cursor"]?.string, extra: "reason=\(CursorStore.validationRejectionReason(object["cursor"]?.string) ?? "unknown")")
            throw GarminError.initialSyncBootstrapRequired
        }
        cursorStore.logCursor("BOOTSTRAP_VERSION_EXTRACTED", provider: identifier, value: cursor)
        try cursorStore.persistBootstrapCursor(cursor, provider: identifier)
        lastReturnedCursor = cursor
        LoggingService.shared.info("Captured initial Garmin cursor. length=\(cursor.count)")
    }

    func discoverInitialBootstrap(client: ChromeDevToolsClient) async throws {
        let start = Date()
        var candidates: [InitialBootstrapCandidate] = []
        var inlineState: [String] = []
        while Date().timeIntervalSince(start) < 180 {
            let snapshots = client.completedGarminAPISnapshots()
            candidates = await evaluateInitialBootstrapCandidates(snapshots, client: client)
            inlineState = (try? await initialBootstrapInlineState(client: client)) ?? []
            let hasLogbookCursor = candidates.contains { $0.versionPresent && $0.snapshot.url.contains("/fly-garmin/api/logbook/") }
            let hasPaging = candidates.contains { candidate in
                candidate.snapshot.url.range(of: "(page|limit|offset|cursor|since|start|end)", options: [.regularExpression, .caseInsensitive]) != nil
            }
            lastReadinessMessage = "Initial bootstrap discovery captured \(candidates.count) JSON/GraphQL candidates..."
            if hasLogbookCursor || (hasPaging && Date().timeIntervalSince(start) >= 20) {
                break
            }
            try await Task.sleep(nanoseconds: 2_000_000_000)
        }
        writeInitialBootstrapDiscoveryDiagnostic(candidates: candidates, inlineState: inlineState, readinessTime: Date().timeIntervalSince(start))
    }

    private func discoverNewItems(useCursor: Bool) async throws -> [RemoteSyncItem] {
        let cursor = try requireSavedCursor()
        let object = try await logbookObject(cursor: cursor, mode: .incremental)
        updateDebugSummary(from: object)
        try validateProbe(object)
        lastReturnedCursor = object["cursor"]?.string
        guard let entries = object["entries"]?.array else { return [] }
        return entries.compactMap { value in
            guard let entry = value.object,
                  let entryID = entry["uuid"]?.string ?? entry["id"]?.string else {
                return nil
            }
            let sources = entry["flightDataLogUUIDs"]?.array?.compactMap(\.string) ?? []
            let tracks = entry["trackUUIDs"]?.array?.compactMap(\.string) ?? []
            return RemoteSyncItem(
                provider: identifier,
                entryID: entryID,
                version: entry["version"]?.string,
                aircraftRegistration: entry["aircraftRegistration"]?.string,
                generatedTrackStart: entry["generatedTrackStart"]?.string,
                generatedTrackStop: entry["generatedTrackStop"]?.string,
                sourceUUIDs: sources,
                trackUUIDs: tracks,
                rawEntry: entry
            )
        }
    }

    func download(item: RemoteSyncItem) async throws -> [DownloadedArtifact] {
        guard !item.sourceUUIDs.isEmpty || !item.trackUUIDs.isEmpty else { return [] }
        var artifacts: [DownloadedArtifact] = []
        for trackUUID in item.trackUUIDs {
            do {
                let artifact = try await downloadTrackJSON(entryID: item.entryID, trackUUID: trackUUID)
                artifacts.append(artifact)
            } catch {
                LoggingService.shared.error("Garmin track JSON download failed for \(trackUUID): \(error.localizedDescription)")
            }
        }
        for sourceUUID in item.sourceUUIDs {
            let fallback = {
                let response = try await self.downloadSourceWithURLSession(sourceUUID: sourceUUID)
                return try self.artifactStore.saveDownloadedSource(
                    provider: self.identifier,
                    entryID: item.entryID,
                    sourceUUID: sourceUUID,
                    response: response
                )
            }
            do {
                let value = try await withTimeout(seconds: 60, message: "Garmin source download timed out for \(sourceUUID).") {
                    try await self.browser.evaluate(Self.sourceDownloadExpression(sourceUUID: sourceUUID))
                }
                let artifact = try artifactStore.saveDownloadedSource(
                    provider: identifier,
                    entryID: item.entryID,
                    sourceUUID: sourceUUID,
                    response: try objectValue(value)
                )
                artifacts.append(artifact)
            } catch GarminError.timeout {
                do { artifacts.append(try await fallback()) }
                catch GarminError.gpxOnly(let message) { LoggingService.shared.info(message) }
            } catch GarminError.loginRequired {
                do { artifacts.append(try await fallback()) }
                catch GarminError.gpxOnly(let message) { LoggingService.shared.info(message) }
            } catch GarminError.downloadFailed {
                do { artifacts.append(try await fallback()) }
                catch GarminError.gpxOnly(let message) { LoggingService.shared.info(message) }
            } catch GarminError.gpxOnly(let message) {
                LoggingService.shared.info(message)
                continue
            } catch {
                throw error
            }
        }
        return artifacts
    }

    private enum LogbookMode {
        case incremental
        case bootstrapCapture
    }

    private func requireSavedCursor() throws -> String {
        let cursor = cursorStore.cursor(provider: identifier)
        cursorStore.logCursor("SYNC_NOW_CURSOR_READ", provider: identifier, value: cursor)
        if let reason = CursorStore.validationRejectionReason(cursor) {
            cursorStore.logCursor("SYNC_NOW_CURSOR_VALIDATION", provider: identifier, value: cursor, extra: "valid=no")
            cursorStore.logCursor("SYNC_NOW_CURSOR_REJECTED_REASON", provider: identifier, value: cursor, extra: "reason=\(reason)")
            throw GarminError.initialSyncBootstrapRequired
        }
        cursorStore.logCursor("SYNC_NOW_CURSOR_VALIDATION", provider: identifier, value: cursor, extra: "valid=yes")
        let exactCursor = cursor ?? ""
        LoggingService.shared.info("Using saved Garmin cursor for incremental sync. length=\(exactCursor.count)")
        return exactCursor
    }

    private func logbookObject(cursor: String?, mode: LogbookMode) async throws -> [String: JSONValue] {
        if mode == .bootstrapCapture, let captured = try await capturedPreloadLogbookObject() {
            return captured
        }
        if mode == .bootstrapCapture {
            throw GarminError.initialSyncBootstrapRequired
        }
        guard let cursor else { throw GarminError.initialSyncBootstrapRequired }
        do {
            let value = try await withTimeout(seconds: 60, message: "Garmin discovery timed out while reading the Logbook.") {
                try await self.browser.evaluate(try Self.incrementalLogbookExpression(version: cursor))
            }
            let object = try objectValue(value)
            return object
        } catch GarminError.timeout {
            throw GarminError.timeout("Garmin incremental Logbook request timed out.")
        }
    }

    private func initialBootstrapLogbookObject() async throws -> [String: JSONValue] {
        let value = try await withTimeout(seconds: 60, message: "Garmin initial Logbook request timed out.") {
            try await self.browser.evaluate(Self.initialBootstrapLogbookExpression())
        }
        return try objectValue(value)
    }

    private func capturedPreloadLogbookObject(client providedClient: ChromeDevToolsClient? = nil) async throws -> [String: JSONValue]? {
        let client: ChromeDevToolsClient
        if let providedClient {
            client = providedClient
        } else {
            client = try await browser.devTools()
        }
        let start = Date()
        var lastStableKey = ""
        var stableSince: Date?
        var lastCandidates: [LogbookCandidate] = []
        while Date().timeIntervalSince(start) < 180 {
            let completed = client.completedGarminAPISnapshots()
            let inFlight = client.inFlightGarminAPICount()
            let recent = Array(completed.suffix(80))
            lastCandidates = await evaluateLogbookCandidates(recent, client: client)
            let best = lastCandidates.sorted { lhs, rhs in
                if lhs.score == rhs.score { return lhs.snapshot.startedAt > rhs.snapshot.startedAt }
                return lhs.score > rhs.score
            }.first

            if let best, let result = best.result {
                let rawEntryCount = best.entryCount
                let cursor = result["cursor"]?.string ?? ""
                lastReadinessMessage = "Garmin candidate score \(best.score) returned \(rawEntryCount) entries. Waiting for data to stabilize..."
                let stableKey = "\(best.snapshot.requestID):\(rawEntryCount):\(cursor)"
                if rawEntryCount >= 0 {
                    if stableKey == lastStableKey {
                        if stableSince == nil { stableSince = Date() }
                        if Date().timeIntervalSince(stableSince ?? Date()) >= 5 {
                            writeDiagnostic(result: result, snapshot: best.snapshot, readinessTime: Date().timeIntervalSince(start), candidates: lastCandidates)
                            return result
                        }
                    } else {
                        stableSince = Date()
                        lastStableKey = stableKey
                    }
                }
            } else {
                let count = completed.count
                lastReadinessMessage = inFlight > 0 ? "Garmin is loading historical flights..." : "Waiting for Garmin Logbook API response... Captured \(count) API responses."
            }
            try await Task.sleep(nanoseconds: 1_000_000_000)
        }
        if let best = lastCandidates.sorted(by: { $0.score > $1.score }).first {
            writeDiagnostic(result: best.result ?? [
                "status": .number(Double(best.snapshot.responseStatus ?? 0)),
                "contentType": .string(best.snapshot.contentType ?? ""),
                "cursor": .null,
                "debugSummary": .object([
                    "entryCount": .number(Double(best.entryCount)),
                    "topLevelKeys": .array(best.topKeys.map { .string($0) }),
                    "entrySummaries": .array([])
                ]),
                "logbookURL": .string(best.snapshot.url)
            ], snapshot: best.snapshot, readinessTime: Date().timeIntervalSince(start), candidates: lastCandidates)
        } else {
            writeCaptureDiagnostic(message: "No Garmin Logbook API response was captured during the 180-second bootstrap window.", candidates: [], readinessTime: Date().timeIntervalSince(start))
        }
        return nil
    }

    private func evaluateInitialBootstrapCandidates(_ snapshots: [GarminLogbookNetworkSnapshot], client: ChromeDevToolsClient) async -> [InitialBootstrapCandidate] {
        var candidates: [InitialBootstrapCandidate] = []
        for snapshot in Array(snapshots.suffix(160)) {
            guard let body = try? await client.responseBody(for: snapshot.requestID),
                  let data = body.data(using: .utf8),
                  let json = try? JSONDecoder().decode(JSONValue.self, from: data) else {
                candidates.append(InitialBootstrapCandidate(snapshot: snapshot, json: nil, topKeys: [], arraySummary: "not-json-or-body-unavailable", versionPresent: false, entryCount: nil, cursorLikePaths: [], reason: "body unavailable or non-JSON"))
                continue
            }
            let object = json.object ?? [:]
            let topKeys = Array(object.keys.sorted().prefix(60))
            let entryCount = object["entries"]?.array?.count
            let versionPresent = object["version"] != nil || object["cursor"] != nil || object["nextCursor"] != nil
            var arrayLines: [String] = []
            summarizeArrays(value: json, path: "$", lines: &arrayLines, depth: 0)
            var cursorPaths: [String] = []
            collectCursorLikePaths(value: json, path: "$", lines: &cursorPaths, depth: 0)
            let reasonParts = [
                snapshot.url.localizedCaseInsensitiveContains("graphql") ? "graphql-like" : nil,
                snapshot.url.contains("/fly-garmin/api/logbook/") ? "logbook-route" : nil,
                entryCount != nil ? "entries-array" : nil,
                versionPresent ? "cursor/version-present" : nil,
                snapshot.url.range(of: "(page|limit|offset|cursor|since)", options: [.regularExpression, .caseInsensitive]) != nil ? "paging-query" : nil
            ].compactMap { $0 }
            candidates.append(InitialBootstrapCandidate(
                snapshot: snapshot,
                json: json,
                topKeys: topKeys,
                arraySummary: arrayLines.prefix(40).joined(separator: " | "),
                versionPresent: versionPresent,
                entryCount: entryCount,
                cursorLikePaths: Array(cursorPaths.prefix(40)),
                reason: reasonParts.joined(separator: ", ")
            ))
        }
        return candidates
    }

    private func summarizeArrays(value: JSONValue, path: String, lines: inout [String], depth: Int) {
        guard depth <= 4, lines.count < 80 else { return }
        if let array = value.array {
            lines.append("\(path):array(count=\(array.count))")
            for (index, item) in array.prefix(2).enumerated() {
                summarizeArrays(value: item, path: "\(path)[\(index)]", lines: &lines, depth: depth + 1)
            }
        } else if let object = value.object {
            for (key, child) in object.prefix(50) {
                summarizeArrays(value: child, path: "\(path).\(key)", lines: &lines, depth: depth + 1)
            }
        }
    }

    private func collectCursorLikePaths(value: JSONValue, path: String, lines: inout [String], depth: Int) {
        guard depth <= 5, lines.count < 80 else { return }
        if path.range(of: "(version|cursor|since|next|page|offset|limit)", options: [.regularExpression, .caseInsensitive]) != nil {
            lines.append("\(path):\(valueType(value))")
        }
        if let array = value.array {
            for (index, item) in array.prefix(2).enumerated() {
                collectCursorLikePaths(value: item, path: "\(path)[\(index)]", lines: &lines, depth: depth + 1)
            }
        } else if let object = value.object {
            for (key, child) in object.prefix(50) {
                collectCursorLikePaths(value: child, path: "\(path).\(key)", lines: &lines, depth: depth + 1)
            }
        }
    }

    private func initialBootstrapInlineState(client: ChromeDevToolsClient) async throws -> [String] {
        let expression = """
        (() => {
          const out = [];
          const add = (label, value) => {
            try {
              if (value === undefined || value === null) return;
              if (Array.isArray(value)) {
                out.push(label + ':array(count=' + value.length + ')');
                return;
              }
              if (typeof value === 'object') {
                out.push(label + ':object(keys=' + Object.keys(value).slice(0, 80).join(',') + ')');
                return;
              }
              out.push(label + ':' + typeof value);
            } catch (_) {}
          };
          add('window.__INITIAL_STATE__', window.__INITIAL_STATE__);
          add('window.__BOOTSTRAP__', window.__BOOTSTRAP__);
          add('window.__APOLLO_STATE__', window.__APOLLO_STATE__);
          add('window.__RELAY_STORE__', window.__RELAY_STORE__);
          add('window.angular', window.angular);
          add('document.ng-app', document.querySelector('[ng-app]')?.getAttribute('ng-app'));
          const scripts = Array.from(document.scripts || []);
          out.push('script-count:' + scripts.length);
          scripts.slice(0, 80).forEach((script, index) => {
            const text = script.textContent || '';
            if (/(logbook|entries|version|cursor|aircraftTypes|currencyItems|graphql|apollo|angular)/i.test(text)) {
              out.push('inline-script[' + index + ']:length=' + text.length + ':hints=' + (text.match(/(logbook|entries|version|cursor|aircraftTypes|currencyItems|graphql|apollo|angular)/ig) || []).slice(0, 20).join(','));
            }
          });
          return out;
        })()
        """
        let value = try await client.evaluate(expression)
        return value.array?.compactMap(\.string) ?? []
    }

    private func writeInitialBootstrapDiscoveryDiagnostic(candidates: [InitialBootstrapCandidate], inlineState: [String], readinessTime: TimeInterval) {
        let logs = FileManager.default.urls(for: .libraryDirectory, in: .userDomainMask).first!
            .appendingPathComponent("Logs/IPCA Sync Agent", isDirectory: true)
        try? FileManager.default.createDirectory(at: logs, withIntermediateDirectories: true)
        let fileURL = logs.appendingPathComponent("garmin-initial-bootstrap-discovery.txt")
        let candidateLines = candidates
            .sorted { lhs, rhs in
                let lhsScore = (lhs.versionPresent ? 50 : 0) + (lhs.entryCount != nil ? 30 : 0)
                let rhsScore = (rhs.versionPresent ? 50 : 0) + (rhs.entryCount != nil ? 30 : 0)
                return lhsScore == rhsScore ? lhs.snapshot.startedAt < rhs.snapshot.startedAt : lhsScore > rhsScore
            }
            .prefix(80)
            .map { candidate in
                [
                    "method=\(candidate.snapshot.method ?? "GET")",
                    "status=\(candidate.snapshot.responseStatus.map(String.init) ?? "n/a")",
                    "contentType=\(candidate.snapshot.contentType ?? "n/a")",
                    "path=\(candidate.snapshot.sanitizedPathWithQueryNames)",
                    "entries=\(candidate.entryCount.map(String.init) ?? "n/a")",
                    "versionOrCursor=\(candidate.versionPresent ? "yes" : "no")",
                    "topKeys=[\(candidate.topKeys.joined(separator: ","))]",
                    "arrays=[\(candidate.arraySummary)]",
                    "cursorPaths=[\(candidate.cursorLikePaths.joined(separator: " | "))]",
                    "reason=\(candidate.reason)"
                ].joined(separator: " ")
            }
            .joined(separator: "\n")
        let lines: [String] = [
            "Timestamp: \(ISO8601DateFormatter().string(from: Date()))",
            "Readiness time seconds: \(String(format: "%.1f", readinessTime))",
            "Purpose: Initial Garmin bootstrap discovery only. Incremental sync remains /api/logbook/?since=<cursor>.",
            "Candidate count: \(candidates.count)",
            "Captured JSON API / GraphQL candidates:",
            candidateLines,
            "Inline / Angular bootstrap state:",
            inlineState.prefix(120).joined(separator: "\n")
        ]
        try? lines.joined(separator: "\n").write(to: fileURL, atomically: true, encoding: String.Encoding.utf8)
    }

    private func writeCaptureDiagnostic(message: String, candidates: [LogbookCandidate], readinessTime: TimeInterval) {
        let best = candidates.sorted { lhs, rhs in
            if lhs.score == rhs.score { return lhs.snapshot.startedAt > rhs.snapshot.startedAt }
            return lhs.score > rhs.score
        }.first
        let result: [String: JSONValue] = [
            "status": .number(Double(best?.snapshot.responseStatus ?? 0)),
            "contentType": .string(best?.snapshot.contentType ?? ""),
            "cursor": .null,
            "debugSummary": .object([
                "entryCount": .number(Double(best?.entryCount ?? 0)),
                "topLevelKeys": .array((best?.topKeys ?? []).map { .string($0) }),
                "entrySummaries": .array([.string(message)])
            ]),
            "logbookURL": .string(best?.snapshot.url ?? "no captured Garmin API response")
        ]
        writeDiagnostic(result: result, snapshot: best?.snapshot, readinessTime: readinessTime, candidates: candidates)
    }

    private func evaluateLogbookCandidates(_ snapshots: [GarminLogbookNetworkSnapshot], client: ChromeDevToolsClient) async -> [LogbookCandidate] {
        var candidates: [LogbookCandidate] = []
        for snapshot in snapshots {
            guard (snapshot.responseStatus ?? 0) == 200,
                  (snapshot.contentType ?? "").lowercased().contains("json"),
                  let body = try? await client.responseBody(for: snapshot.requestID),
                  let data = body.data(using: .utf8),
                  let json = try? JSONDecoder().decode(JSONValue.self, from: data) else {
                candidates.append(LogbookCandidate(snapshot: snapshot, json: nil, score: -100, entryCount: 0, topKeys: [], reason: "not-json-or-unavailable", result: nil))
                continue
            }
            let evaluation = evaluateLogbookCandidate(snapshot: snapshot, json: json)
            candidates.append(evaluation)
        }
        return candidates
    }

    private func evaluateLogbookCandidate(snapshot: GarminLogbookNetworkSnapshot, json: JSONValue) -> LogbookCandidate {
        let object = json.object ?? [:]
        let topKeys = Array(object.keys.sorted().prefix(60))
        var score = 0
        var reasons: [String] = []
        if object["feature_enabled"] != nil || object["featureFlags"] != nil || object["features"] != nil {
            score -= 100
            reasons.append("feature/config response")
        }
        if object["entries"]?.array != nil {
            score += 60
            reasons.append("has entries array")
        }
        if object["version"] != nil {
            score += 20
            reasons.append("has version")
        }
        if object["settings"] != nil {
            score += 20
            reasons.append("has settings")
        }
        if let url = URL(string: snapshot.url), url.path == "/fly-garmin/api/logbook/" {
            score += 30
            reasons.append("canonical logbook path")
        }
        let rawCount = rawEntryCount(json)
        if rawCount > 0 {
            score += min(40, rawCount)
            reasons.append("nonzero entries")
        }
        let entries = normalizeEntries(json)
        if !entries.isEmpty {
            score += 50
            reasons.append("explicit flightDataLogUUID descriptors")
        }
        let expectedShape = object["entries"]?.array != nil && object["version"] != nil && object["settings"] != nil
        let result = expectedShape ? logbookResultObject(
            json: json,
            entries: entries,
            status: snapshot.responseStatus ?? 200,
            contentType: snapshot.contentType ?? "",
            pageURL: "CDP Network.getResponseBody",
            logbookURL: snapshot.url
        ) : nil
        return LogbookCandidate(snapshot: snapshot, json: json, score: score, entryCount: rawCount, topKeys: topKeys, reason: reasons.joined(separator: ", "), result: result)
    }

    private func logbookResultObject(json: JSONValue, entries: [RemoteSyncItem], status: Int, contentType: String, pageURL: String, logbookURL: String) -> [String: JSONValue] {
        let object = json.object ?? [:]
        let debugSummary = GarminDebugSummary(
            entryCount: rawEntryCount(json),
            topLevelKeys: Array(object.keys.sorted().prefix(40)),
            entrySummaries: sanitizedEntrySummaries(from: json)
        )
        lastDebugSummary = debugSummary
        let cursorValue = object["version"]?.string ?? object["cursor"]?.string ?? object["nextCursor"]?.string ?? object["metadata"]?.object?["version"]?.string
        return [
            "status": .number(Double(status)),
            "ok": .bool(true),
            "contentType": .string(contentType),
            "jsonOk": .bool(true),
            "topLevelKeys": .array(Array(object.keys.prefix(30)).map { .string($0) }),
            "entryCount": .number(Double(entries.count)),
            "cursor": cursorValue.map { .string($0) } ?? .null,
            "firstFlightDataLogUUID": entries.first?.sourceUUIDs.first.map { .string($0) } ?? .null,
            "entries": .array(entries.map(remoteItemJSONValue)),
            "debugSummary": .object([
                "entryCount": .number(Double(debugSummary.entryCount)),
                "topLevelKeys": .array(debugSummary.topLevelKeys.map { .string($0) }),
                "entrySummaries": .array(debugSummary.entrySummaries.map { .string($0) })
            ]),
            "loginRequired": .bool(false),
            "humanVerification": .bool(false),
            "pageURL": .string(pageURL),
            "logbookURL": .string(logbookURL)
        ]
    }

    private func rawEntryCount(_ json: JSONValue) -> Int {
        let root = json.object
        return root?["entries"]?.array?.count ?? 0
    }

    private func writeDiagnostic(result: [String: JSONValue], snapshot: GarminLogbookNetworkSnapshot?, readinessTime: TimeInterval?, candidates: [LogbookCandidate]) {
        let logs = FileManager.default.urls(for: .libraryDirectory, in: .userDomainMask).first!
            .appendingPathComponent("Logs/IPCA Sync Agent", isDirectory: true)
        try? FileManager.default.createDirectory(at: logs, withIntermediateDirectories: true)
        let fileURL = logs.appendingPathComponent("garmin-entry-structure.txt")
        let debug = result["debugSummary"]?.object
        let readinessText = readinessTime.map { String(format: "%.1f", $0) } ?? "n/a"
        let requestURLText = snapshot?.url ?? result["logbookURL"]?.string ?? "n/a"
        let requestIDText = snapshot?.requestID ?? "n/a"
        let responseStatusText = snapshot?.responseStatus.map { String($0) } ?? result["status"]?.int.map { String($0) } ?? "n/a"
        let contentTypeText = snapshot?.contentType ?? result["contentType"]?.string ?? "n/a"
        let encodedLengthText = snapshot?.encodedDataLength.map { String($0) } ?? "n/a"
        let durationText = snapshot?.duration.map { String(format: "%.1f", $0) } ?? "n/a"
        let topKeysText = (debug?["topLevelKeys"]?.array?.compactMap(\.string) ?? []).joined(separator: ", ")
        let entryCountText = String(debug?["entryCount"]?.int ?? 0)
        let cursorText = (result["cursor"]?.string?.isEmpty == false) ? "yes" : "no"
        let entriesText = (debug?["entrySummaries"]?.array?.compactMap(\.string) ?? []).joined(separator: "\n\n")
        let candidateText = candidates
            .sorted { lhs, rhs in
                if lhs.score == rhs.score { return lhs.snapshot.startedAt > rhs.snapshot.startedAt }
                return lhs.score > rhs.score
            }
            .prefix(25)
            .map { candidate in
                let keys = candidate.topKeys.joined(separator: ",")
                return [
                    "score=\(candidate.score)",
                    "status=\(candidate.snapshot.responseStatus.map { String($0) } ?? "n/a")",
                    "contentType=\(candidate.snapshot.contentType ?? "n/a")",
                    "entries=\(candidate.entryCount)",
                    "path=\(candidate.snapshot.sanitizedPathWithQueryNames)",
                    "keys=[\(keys)]",
                    "reason=\(candidate.reason)"
                ].joined(separator: " ")
            }
            .joined(separator: "\n")
        let lines: [String] = [
            "Timestamp: \(ISO8601DateFormatter().string(from: Date()))",
            "Readiness time seconds: \(readinessText)",
            "Request URL: \(requestURLText)",
            "Request ID: \(requestIDText)",
            "Response status: \(responseStatusText)",
            "Content type: \(contentTypeText)",
            "Encoded data length: \(encodedLengthText)",
            "Request duration seconds: \(durationText)",
            "Response type: object",
            "Top-level keys: \(topKeysText)",
            "Entry count: \(entryCountText)",
            "Cursor present: \(cursorText)",
            "Captured API candidates:",
            candidateText,
            "Entry structures:",
            entriesText
        ]
        try? lines.joined(separator: "\n").write(to: fileURL, atomically: true, encoding: String.Encoding.utf8)
    }

    static func trackMetrics(response: GarminTrackResponse, responseByteCount: Int) -> GarminTrackMetrics {
        let rowsPerSession = response.sessions.map { $0.data.count }
        let totalRows = rowsPerSession.reduce(0, +)
        let totalFieldCount = response.sessions.reduce(0) { $0 + $1.fields.count }
        var firstTimestamp: String?
        var lastTimestamp: String?
        for session in response.sessions {
            guard let timeIndex = session.fields.firstIndex(where: { $0.fieldType == "time" }),
                  !session.data.isEmpty else { continue }
            if firstTimestamp == nil,
               let firstRow = session.data.first?.array,
               firstRow.indices.contains(timeIndex) {
                firstTimestamp = timestampString(firstRow[timeIndex])
            }
            if let lastRow = session.data.last?.array,
               lastRow.indices.contains(timeIndex),
               let timestamp = timestampString(lastRow[timeIndex]) {
                lastTimestamp = timestamp
            }
        }
        return GarminTrackMetrics(
            responseByteCount: responseByteCount,
            sessionCount: response.sessions.count,
            totalFieldCount: totalFieldCount,
            rowsPerSession: rowsPerSession,
            totalTelemetryRows: totalRows,
            firstTimestamp: firstTimestamp,
            lastTimestamp: lastTimestamp
        )
    }

    private static func timestampString(_ value: JSONValue) -> String? {
        if let string = value.string { return string }
        if let int = value.int { return String(int) }
        return nil
    }

    private func writeTrackDiagnostic(entryID: String, trackUUID: String, path: String, status: Int, contentType: String, response: GarminTrackResponse, metrics: GarminTrackMetrics) {
        let logs = FileManager.default.urls(for: .libraryDirectory, in: .userDomainMask).first!
            .appendingPathComponent("Logs/IPCA Sync Agent", isDirectory: true)
        try? FileManager.default.createDirectory(at: logs, withIntermediateDirectories: true)
        let fileURL = logs.appendingPathComponent("garmin-track-diagnostic.txt")
        let sources = response.sessions
            .flatMap { $0.sources ?? [] }
            .map { source in
                "type=\(source.type ?? "") name=\(source.name ?? "")"
            }
            .joined(separator: "\n")
        let fieldTypes = response.sessions
            .flatMap(\.fields)
            .compactMap(\.fieldType)
        let lines: [String] = [
            "Timestamp: \(ISO8601DateFormatter().string(from: Date()))",
            "Logbook entry UUID: \(entryID)",
            "Track UUID: \(trackUUID)",
            "Request URL path: \(path)",
            "Response status: \(status)",
            "Content type: \(contentType)",
            "Response byte count: \(metrics.responseByteCount)",
            "Format version: \(response.formatVersion.map(String.init) ?? "n/a")",
            "Session count: \(metrics.sessionCount)",
            "Field count: \(metrics.totalFieldCount)",
            "Rows per session: \(metrics.rowsPerSession.map(String.init).joined(separator: ", "))",
            "Total telemetry rows: \(metrics.totalTelemetryRows)",
            "First timestamp: \(metrics.firstTimestamp ?? "n/a")",
            "Last timestamp: \(metrics.lastTimestamp ?? "n/a")",
            "Field descriptors: \(fieldTypes.prefix(120).joined(separator: ", "))",
            "Source descriptor names/types:",
            sources
        ]
        try? lines.joined(separator: "\n").write(to: fileURL, atomically: true, encoding: String.Encoding.utf8)
    }

    private func writeTrackFailureDiagnostic(entryID: String, trackUUID: String, requestURL: String, result: [String: JSONValue]) {
        let logs = FileManager.default.urls(for: .libraryDirectory, in: .userDomainMask).first!
            .appendingPathComponent("Logs/IPCA Sync Agent", isDirectory: true)
        try? FileManager.default.createDirectory(at: logs, withIntermediateDirectories: true)
        let fileURL = logs.appendingPathComponent("garmin-track-failure-diagnostic.txt")
        let lines: [String] = [
            "Timestamp: \(ISO8601DateFormatter().string(from: Date()))",
            "Logbook entry UUID: \(entryID)",
            "Track UUID: \(trackUUID)",
            "Request URL: \(requestURL)",
            "HTTP status: \(result["status"]?.int.map(String.init) ?? "n/a")",
            "Final URL: \(result["finalUrl"]?.string ?? "n/a")",
            "Content type: \(result["contentType"]?.string ?? "n/a")",
            "Response byte count: \(result["byteLength"]?.int.map(String.init) ?? "n/a")",
            "Body preview: \(result["bodyPreview"]?.string ?? "n/a")",
            "Browser evaluation error: \(result["browserEvaluationError"]?.string ?? "none")",
            "Page appears authenticated: \(result["pageAuthenticated"]?.bool == true ? "yes" : "no")"
        ]
        try? lines.joined(separator: "\n").write(to: fileURL, atomically: true, encoding: String.Encoding.utf8)
        LoggingService.shared.error("Garmin raw track download failed entry=\(entryID) track=\(trackUUID) status=\(result["status"]?.int ?? 0) finalUrl=\(result["finalUrl"]?.string ?? "n/a") contentType=\(result["contentType"]?.string ?? "n/a") pageAuthenticated=\(result["pageAuthenticated"]?.bool == true ? "yes" : "no") error=\(result["browserEvaluationError"]?.string ?? "none")")
    }

    private func downloadSourceWithURLSession(sourceUUID: String) async throws -> [String: JSONValue] {
        let cookieHeader = try await browser.garminCookieHeader()
        guard !cookieHeader.isEmpty else { throw GarminError.loginRequired }
        let candidates = [
            "https://fly.garmin.com/fly-garmin/api/logbook/flight-data/\(sourceUUID)",
            "https://fly.garmin.com/fly-garmin/api/logbook/flight-data/\(sourceUUID)/download",
            "https://fly.garmin.com/fly-garmin/api/flight-data/\(sourceUUID)"
        ]
        var attempts: [JSONValue] = []
        for candidate in candidates {
            var request = URLRequest(url: URL(string: candidate)!)
            request.timeoutInterval = 60
            request.setValue("text/csv, application/octet-stream, */*", forHTTPHeaderField: "Accept")
            request.setValue(cookieHeader, forHTTPHeaderField: "Cookie")
            let data: Data
            let response: URLResponse
            do {
                (data, response) = try await URLSession.shared.data(for: request)
            } catch let error as URLError where error.code == .timedOut {
                attempts.append(.object([
                    "status": .number(0),
                    "contentType": .string(""),
                    "error": .string("timedOut"),
                    "path": .string(URL(string: candidate)?.path ?? candidate)
                ]))
                continue
            } catch {
                attempts.append(.object([
                    "status": .number(0),
                    "contentType": .string(""),
                    "error": .string(error.localizedDescription),
                    "path": .string(URL(string: candidate)?.path ?? candidate)
                ]))
                continue
            }
            let http = response as? HTTPURLResponse
            let status = http?.statusCode ?? 0
            let contentType = http?.value(forHTTPHeaderField: "content-type") ?? ""
            attempts.append(.object([
                "status": .number(Double(status)),
                "contentType": .string(contentType),
                "path": .string(URL(string: candidate)?.path ?? candidate)
            ]))
            if status == 401 || status == 403 { continue }
            if (200...299).contains(status), !data.isEmpty {
                let disposition = http?.value(forHTTPHeaderField: "content-disposition") ?? ""
                return [
                    "status": .number(Double(status)),
                    "ok": .bool(true),
                    "contentType": .string(contentType),
                    "contentDisposition": .string(disposition),
                    "filename": .string(filenameFromDisposition(disposition) ?? "\(sourceUUID).bin"),
                    "byteLength": .number(Double(data.count)),
                    "base64": .string(data.base64EncodedString()),
                    "attempts": .array(attempts),
                    "loginRequired": .bool(false),
                    "humanVerification": .bool(false)
                ]
            }
        }
        return [
            "status": .number(Double(attempts.last?.object?["status"]?.int ?? 0)),
            "ok": .bool(false),
            "contentType": .string(""),
            "filename": .string("\(sourceUUID).bin"),
            "byteLength": .number(0),
            "attempts": .array(attempts),
            "loginRequired": .bool(attempts.contains { ($0.object?["status"]?.int ?? 0) == 401 || ($0.object?["status"]?.int ?? 0) == 403 }),
            "humanVerification": .bool(false)
        ]
    }

    private func downloadTrackJSON(entryID: String, trackUUID: String) async throws -> DownloadedArtifact {
        let url = try GarminRoutes.trackURL(trackUUID: trackUUID)
        let path = url.path
        let started = ISO8601DateFormatter().string(from: Date())
        LoggingService.shared.info("Garmin raw track download starting entry=\(entryID) track=\(trackUUID) url=\(url.absoluteString) startedAt=\(started)")
        let value: JSONValue
        do {
            value = try await withTimeout(seconds: 90, message: "Garmin track JSON request timed out for \(trackUUID).") {
                try await self.browser.evaluate(try Self.trackDownloadExpression(trackUUID: trackUUID))
            }
        } catch {
            writeTrackFailureDiagnostic(entryID: entryID, trackUUID: trackUUID, requestURL: url.absoluteString, result: [
                "status": .number(0),
                "finalUrl": .string(url.absoluteString),
                "contentType": .string(""),
                "byteLength": .number(0),
                "bodyPreview": .string(""),
                "browserEvaluationError": .string(error.localizedDescription),
                "pageAuthenticated": .bool(false)
            ])
            throw error
        }
        let result = try objectValue(value)
        let status = result["status"]?.int ?? 0
        let contentType = result["contentType"]?.string ?? ""
        if status == 401 || status == 403 || result["loginRequired"]?.bool == true {
            writeTrackFailureDiagnostic(entryID: entryID, trackUUID: trackUUID, requestURL: url.absoluteString, result: result)
            throw GarminError.loginRequired
        }
        guard (200...299).contains(status) else {
            writeTrackFailureDiagnostic(entryID: entryID, trackUUID: trackUUID, requestURL: url.absoluteString, result: result)
            throw GarminError.downloadFailed("Garmin track JSON failed with HTTP \(status).")
        }
        guard let base64 = result["base64"]?.string,
              let data = Data(base64Encoded: base64),
              !data.isEmpty else {
            writeTrackFailureDiagnostic(entryID: entryID, trackUUID: trackUUID, requestURL: url.absoluteString, result: result)
            throw GarminError.downloadFailed("Garmin track JSON response was empty or invalid.")
        }
        let track = try JSONDecoder().decode(GarminTrackResponse.self, from: data)
        let metrics = Self.trackMetrics(response: track, responseByteCount: data.count)
        let artifact = try artifactStore.saveTrackJSON(
            provider: identifier,
            entryID: entryID,
            trackUUID: trackUUID,
            bytes: data,
            response: track,
            requestPath: path,
            contentType: contentType
        )
        writeTrackDiagnostic(entryID: entryID, trackUUID: trackUUID, path: path, status: status, contentType: contentType, response: track, metrics: metrics)
        LoggingService.shared.info("Garmin raw track download succeeded entry=\(entryID) track=\(trackUUID) status=\(status) bytes=\(metrics.responseByteCount) sessions=\(metrics.sessionCount) fields=\(metrics.totalFieldCount) rows=\(metrics.totalTelemetryRows) firstTimestamp=\(metrics.firstTimestamp ?? "n/a") lastTimestamp=\(metrics.lastTimestamp ?? "n/a")")
        return artifact
    }

    static func trackDownloadExpression(trackUUID: String) throws -> String {
        let url = try GarminRoutes.trackURL(trackUUID: trackUUID)
        let urlJSON = String(data: try JSONEncoder().encode(url.absoluteString), encoding: .utf8) ?? "\"\""
        return """
        (async () => {
          const requestUrl = \(urlJSON);
          const pageAuthenticated = location.href.startsWith('https://fly.garmin.com/fly-garmin/');
          const controller = new AbortController();
          const timeout = setTimeout(() => controller.abort(), 90000);
          try {
            const response = await fetch(requestUrl, {
              method: 'GET',
              credentials: 'include',
              cache: 'no-store',
              redirect: 'follow',
              headers: { 'Accept': 'application/json, text/plain, */*' },
              signal: controller.signal
            });
            const contentType = response.headers.get('content-type') || '';
            const finalUrl = response.url || requestUrl;
            const buffer = await response.arrayBuffer();
            const bytes = new Uint8Array(buffer);
            const text = new TextDecoder().decode(bytes);
            const bodyPreview = text.slice(0, 800)
              .replace(/[A-Za-z0-9_-]{24,}/g, '[redacted-token]')
              .replace(/"[^"]*(token|authorization|cookie|session)[^"]*"\\s*:\\s*"[^"]*"/ig, '"$1":"[redacted]"');
            let json = null;
            let sessionCount = 0;
            let totalFieldCount = 0;
            let rowsPerSession = [];
            let totalTelemetryRows = 0;
            let firstTimestamp = null;
            let lastTimestamp = null;
            try {
              json = JSON.parse(text);
              const sessions = Array.isArray(json?.sessions) ? json.sessions : [];
              sessionCount = sessions.length;
              for (const session of sessions) {
                const fields = Array.isArray(session?.fields) ? session.fields : [];
                const rows = Array.isArray(session?.data) ? session.data : [];
                totalFieldCount += fields.length;
                rowsPerSession.push(rows.length);
                totalTelemetryRows += rows.length;
                const timeIndex = fields.findIndex((field) => field?.fieldType === 'time');
                if (timeIndex >= 0 && rows.length > 0) {
                  const first = Array.isArray(rows[0]) ? rows[0][timeIndex] : null;
                  const lastRow = rows[rows.length - 1];
                  const last = Array.isArray(lastRow) ? lastRow[timeIndex] : null;
                  if (firstTimestamp === null && first !== undefined && first !== null) firstTimestamp = String(first);
                  if (last !== undefined && last !== null) lastTimestamp = String(last);
                }
              }
            } catch (_) {}
            let binary = '';
            const chunkSize = 0x8000;
            for (let i = 0; i < bytes.length; i += chunkSize) {
              binary += String.fromCharCode(...bytes.subarray(i, i + chunkSize));
            }
            const sample = text.slice(0, 1200).toLowerCase();
            const loginRequired = response.status === 401 || response.status === 403 || sample.includes('password') || sample.includes('sign in') || sample.includes('multi-factor') || sample.includes('mfa') || sample.includes('verification code');
            return {
              status: response.status,
              ok: response.ok,
              finalUrl,
              contentType,
              byteLength: bytes.byteLength,
              base64: response.ok ? btoa(binary) : null,
              bodyPreview,
              sessionCount,
              totalFieldCount,
              rowsPerSession,
              totalTelemetryRows,
              firstTimestamp,
              lastTimestamp,
              pageAuthenticated,
              loginRequired,
              browserEvaluationError: null
            };
          } catch (error) {
            return {
              status: 0,
              ok: false,
              finalUrl: requestUrl,
              contentType: '',
              byteLength: 0,
              base64: null,
              bodyPreview: '',
              sessionCount: 0,
              totalFieldCount: 0,
              rowsPerSession: [],
              totalTelemetryRows: 0,
              firstTimestamp: null,
              lastTimestamp: null,
              pageAuthenticated,
              loginRequired: false,
              browserEvaluationError: String(error)
            };
          } finally {
            clearTimeout(timeout);
          }
        })()
        """
    }

    private func withTimeout<T>(seconds: UInt64, message: String, operation: @escaping () async throws -> T) async throws -> T {
        try await withThrowingTaskGroup(of: T.self) { group in
            group.addTask {
                try await operation()
            }
            group.addTask {
                try await Task.sleep(nanoseconds: seconds * 1_000_000_000)
                throw GarminError.timeout(message)
            }
            guard let result = try await group.next() else {
                throw GarminError.timeout(message)
            }
            group.cancelAll()
            return result
        }
    }

    private func normalizeEntries(_ json: JSONValue) -> [RemoteSyncItem] {
        Self.remoteItems(from: json, provider: identifier, requireArtifacts: true)
    }

    static func remoteItems(from json: JSONValue, provider: String, requireArtifacts: Bool) -> [RemoteSyncItem] {
        let root = json.object
        let list = root?["entries"]?.array ?? []
        return list.compactMap { value in
            guard let entry = value.object else { return nil }
            let entryID = entry["uuid"]?.string ??
                entry["id"]?.string ??
                entry["logbookEntryUUID"]?.string ??
                entry["logbookEntryUuid"]?.string
            let sources = collectCSVSourceUUIDs(from: entry)
            let tracks = collectTrackUUIDs(from: entry)
            guard let entryID else { return nil }
            if requireArtifacts, sources.isEmpty, tracks.isEmpty { return nil }
            return RemoteSyncItem(
                provider: provider,
                entryID: entryID,
                version: entry["version"]?.string ?? entry["versionId"]?.string ?? entry["modifiedVersion"]?.string,
                aircraftRegistration: entry["aircraftRegistration"]?.string ?? entry["aircraftTailNumber"]?.string ?? entry["aircraftIdent"]?.string ?? entry["tailNumber"]?.string,
                generatedTrackStart: entry["generatedTrackStart"]?.string ?? entry["trackStart"]?.string ?? entry["startTime"]?.string ?? entry["departureTime"]?.string,
                generatedTrackStop: entry["generatedTrackStop"]?.string ?? entry["trackStop"]?.string ?? entry["endTime"]?.string ?? entry["arrivalTime"]?.string,
                sourceUUIDs: sources,
                trackUUIDs: tracks,
                rawEntry: entry
            )
        }
    }

    static func collectTrackUUIDs(from entry: [String: JSONValue]) -> [String] {
        var values: [String] = []
        var seen = Set<String>()
        func add(_ value: JSONValue?) {
            guard let string = value?.string,
                  string.range(of: #"^[A-Fa-f0-9-]{36}$"#, options: .regularExpression) != nil else {
                return
            }
            let lower = string.lowercased()
            if !seen.contains(lower) {
                seen.insert(lower)
                values.append(lower)
            }
        }
        for key in ["canonicalTrackUUID", "canonicalTrackUuid"] {
            add(entry[key])
        }
        return values
    }

    static func collectCSVSourceUUIDs(from entry: [String: JSONValue]) -> [String] {
        var values: [String] = []
        var seen = Set<String>()
        func add(_ value: JSONValue?) {
            guard let string = value?.string,
                  string.range(of: #"^[A-Fa-f0-9-]{36}$"#, options: .regularExpression) != nil else {
                return
            }
            let lower = string.lowercased()
            if !seen.contains(lower) {
                seen.insert(lower)
                values.append(lower)
            }
        }

        for key in ["flightDataLogUUID", "flightDataLogUuid", "flightDataLogId"] {
            add(entry[key])
        }
        for key in ["flightDataLogUUIDs", "flightDataLogUuids"] {
            guard let array = entry[key]?.array else { continue }
            for item in array {
                add(item)
                if let object = item.object {
                    for nestedKey in ["flightDataLogUUID", "flightDataLogUuid", "flightDataLogId"] {
                        add(object[nestedKey])
                    }
                }
            }
        }
        for key in ["flightDataLogs", "flightDataLog", "dataLogs", "dataLog", "sourceFiles", "sources", "files", "attachments", "downloads", "csvFiles", "csvDownloads"] {
            let value = entry[key]
            let descriptors = value?.array ?? value?.object.map { [.object($0)] } ?? []
            for descriptor in descriptors {
                guard let object = descriptor.object else { continue }
                for nestedKey in ["flightDataLogUUID", "flightDataLogUuid", "flightDataLogId"] {
                    add(object[nestedKey])
                }
            }
        }
        return values
    }

    private func remoteItemJSONValue(_ item: RemoteSyncItem) -> JSONValue {
        .object([
            "uuid": .string(item.entryID),
            "id": .string(item.entryID),
            "version": item.version.map { .string($0) } ?? .null,
            "aircraftRegistration": item.aircraftRegistration.map { .string($0) } ?? .null,
            "generatedTrackStart": item.generatedTrackStart.map { .string($0) } ?? .null,
            "generatedTrackStop": item.generatedTrackStop.map { .string($0) } ?? .null,
            "flightDataLogUUIDs": .array(item.sourceUUIDs.map { .string($0) }),
            "trackUUIDs": .array(item.trackUUIDs.map { .string($0) }),
            "raw": .object(item.rawEntry)
        ])
    }

    private func filenameFromDisposition(_ value: String) -> String? {
        guard let range = value.range(of: #"filename\*?=(?:UTF-8'')?"?([^";]+)"?"#, options: .regularExpression) else {
            return nil
        }
        let part = String(value[range])
        let raw = part
            .replacingOccurrences(of: #"filename\*?=(?:UTF-8'')?"#, with: "", options: .regularExpression)
            .trimmingCharacters(in: CharacterSet(charactersIn: "\"; "))
        return raw.removingPercentEncoding ?? raw
    }

    private func sanitizedEntrySummaries(from json: JSONValue) -> [String] {
        let root = json.object
        let list = root?["entries"]?.array ?? []
        return list.prefix(5).enumerated().compactMap { index, value in
            guard let entry = value.object else { return nil }
            let topKeys = Array(entry.keys.prefix(80)).joined(separator: ",")
            var lines: [String] = []
            describeRelevantFields(value: .object(entry), path: ["entry"], depth: 0, lines: &lines)
            return "Entry \(index + 1) topKeys=[\(topKeys)] details=[\(lines.prefix(80).joined(separator: " | "))]"
        }
    }

    private func describeRelevantFields(value: JSONValue, path: [String], depth: Int, lines: inout [String]) {
        guard depth <= 3, lines.count < 80 else { return }
        let pathText = path.joined(separator: ".")
        let relevant = pathText.range(of: "(flight|data|log|csv|track|source|uuid|file|download)", options: [.regularExpression, .caseInsensitive]) != nil
        if depth > 0, let object = value.object {
            lines.append("\(pathText):keys(\(object.keys.prefix(40).joined(separator: ",")))")
        } else if depth > 0, let array = value.array {
            lines.append("\(pathText):array(count=\(array.count))")
        }
        if relevant {
            var suffix = ""
            if let string = value.string,
               string.range(of: #"^[A-Fa-f0-9-]{36}$"#, options: .regularExpression) != nil {
                suffix = "=\(string)"
            }
            lines.append("\(pathText):\(valueType(value))\(suffix)")
        }
        if let array = value.array {
            for (index, item) in array.prefix(3).enumerated() {
                describeRelevantFields(value: item, path: path + ["[\(index)]"], depth: depth + 1, lines: &lines)
            }
        } else if let object = value.object {
            for (key, item) in object.prefix(40) {
                describeRelevantFields(value: item, path: path + [key], depth: depth + 1, lines: &lines)
            }
        }
    }

    private func valueType(_ value: JSONValue) -> String {
        if value.string != nil { return "string" }
        if value.int != nil { return "number" }
        if value.bool != nil { return "bool" }
        if value.array != nil { return "array" }
        if value.object != nil { return "object" }
        return "null"
    }

    func disconnect() async throws {
        await MainActor.run {
            browser.resetConnectionWithUserConfirmation()
        }
    }

    private func objectValue(_ value: JSONValue) throws -> [String: JSONValue] {
        guard let object = value.object else { throw GarminError.unexpectedResponse("Garmin returned an unexpected response.") }
        return object
    }

    private func validateProbe(_ object: [String: JSONValue]) throws {
        updateDebugSummary(from: object)
        if object["internalError"]?.string == "GARMIN_LOGBOOK_ENDPOINT_UNAVAILABLE" {
            writeLogbookEndpointUnavailableDiagnostic(object: object, reason: "internal endpoint unavailable")
            throw GarminError.logbookEndpointUnavailable
        }
        if object["internalError"]?.string == "GARMIN_INITIAL_SYNC_BOOTSTRAP_REQUIRED" {
            throw GarminError.initialSyncBootstrapRequired
        }
        if object["timeout"]?.bool == true {
            let pageURL = object["pageURL"]?.string ?? "unknown page"
            let logbookURL = object["logbookURL"]?.string ?? "unavailable Garmin API URL"
            throw GarminError.timeout("Garmin Logbook request timed out while Chrome was on \(pageURL). Tried \(logbookURL).")
        }
        if object["humanVerification"]?.bool == true { throw GarminError.humanVerification }
        if Self.logbookProbeAuthenticationProblem(object) { throw GarminError.loginRequired }
        let status = object["status"]?.int ?? 0
        if status == 409 {
            throw GarminError.unexpectedResponse("Garmin rejected the saved sync cursor. The app will retry with a full Logbook request.")
        }
        if Self.confirmedLogbookEndpoint(object) {
            logConfirmedLogbookEndpoint(object)
            return
        }
        if Self.shouldReportLogbookEndpointUnavailable(object) {
            writeLogbookEndpointUnavailableDiagnostic(object: object, reason: "logbook response was not confirmed")
            throw GarminError.logbookEndpointUnavailable
        }
    }

    static func confirmedLogbookEndpoint(_ object: [String: JSONValue]) -> Bool {
        let status = object["status"]?.int ?? 0
        guard status == 200,
              object["jsonOk"]?.bool == true,
              object["cursor"]?.string?.isEmpty == false,
              object["rawEntryCount"]?.int != nil || object["entries"]?.array != nil else {
            return false
        }
        return true
    }

    static func logbookProbeAuthenticationProblem(_ object: [String: JSONValue]) -> Bool {
        let status = object["status"]?.int ?? 0
        if status == 401 || status == 403 { return true }
        if object["loginRequired"]?.bool == true { return true }
        let contentType = object["contentType"]?.string?.lowercased() ?? ""
        let preview = object["bodyPreview"]?.string?.lowercased() ?? ""
        return contentType.contains("text/html") && (
            preview.contains("sign in") ||
            preview.contains("password") ||
            preview.contains("multi-factor") ||
            preview.contains("verification code")
        )
    }

    static func shouldReportLogbookEndpointUnavailable(_ object: [String: JSONValue]) -> Bool {
        if object["internalError"]?.string == "GARMIN_LOGBOOK_ENDPOINT_UNAVAILABLE" { return true }
        if confirmedLogbookEndpoint(object) { return false }
        if logbookProbeAuthenticationProblem(object) { return false }
        return object["expectedShape"]?.bool == false || object["jsonOk"]?.bool != true || !(200...299).contains(object["status"]?.int ?? 0)
    }

    private func writeLogbookEndpointUnavailableDiagnostic(object: [String: JSONValue], reason: String) {
        let logs = FileManager.default.urls(for: .libraryDirectory, in: .userDomainMask).first!
            .appendingPathComponent("Logs/IPCA Sync Agent", isDirectory: true)
        try? FileManager.default.createDirectory(at: logs, withIntermediateDirectories: true)
        let fileURL = logs.appendingPathComponent("garmin-logbook-endpoint-diagnostic.txt")
        let topKeys = object["topLevelKeys"]?.array?.compactMap(\.string) ?? []
        let versionExists = object["cursor"]?.string?.isEmpty == false
        let entriesCount = object["rawEntryCount"]?.int ?? object["entries"]?.array?.count
        let lines: [String] = [
            "Timestamp: \(ISO8601DateFormatter().string(from: Date()))",
            "Reason: \(reason)",
            "Attempted URL: \(object["logbookURL"]?.string ?? "n/a")",
            "HTTP status: \(object["status"]?.int.map(String.init) ?? "n/a")",
            "Content type: \(object["contentType"]?.string ?? "n/a")",
            "Response byte count: \(object["responseByteCount"]?.int.map(String.init) ?? "n/a")",
            "Top-level keys: \(topKeys.joined(separator: ", "))",
            "Version exists: \(versionExists ? "yes" : "no")",
            "Entries exists: \(entriesCount == nil ? "no" : "yes")",
            "Entries count: \(entriesCount.map(String.init) ?? "n/a")",
            "JavaScript evaluation error: \(object["jsEvaluationError"]?.string ?? object["error"]?.string ?? "none")",
            "Response body preview: \(object["bodyPreview"]?.string ?? "n/a")"
        ]
        try? lines.joined(separator: "\n").write(to: fileURL, atomically: true, encoding: String.Encoding.utf8)
        LoggingService.shared.error("Garmin logbook endpoint unavailable: status=\(object["status"]?.int ?? 0) url=\(object["logbookURL"]?.string ?? "n/a") keys=\(topKeys.joined(separator: ",")) version=\(versionExists ? "yes" : "no") entries=\(entriesCount.map(String.init) ?? "n/a")")
    }

    private func logConfirmedLogbookEndpoint(_ object: [String: JSONValue]) {
        let topKeys = object["topLevelKeys"]?.array?.compactMap(\.string) ?? []
        let entriesCount = object["rawEntryCount"]?.int ?? object["entries"]?.array?.count ?? 0
        LoggingService.shared.info("Garmin logbook endpoint confirmed: url=\(object["logbookURL"]?.string ?? "n/a") status=\(object["status"]?.int ?? 0) contentType=\(object["contentType"]?.string ?? "n/a") bytes=\(object["responseByteCount"]?.int ?? 0) version=yes entries=\(entriesCount) keys=\(topKeys.joined(separator: ","))")
    }

    private func updateDebugSummary(from object: [String: JSONValue]) {
        guard let debug = object["debugSummary"]?.object else { return }
        lastDebugSummary = GarminDebugSummary(
            entryCount: debug["entryCount"]?.int ?? 0,
            topLevelKeys: debug["topLevelKeys"]?.array?.compactMap(\.string) ?? [],
            entrySummaries: debug["entrySummaries"]?.array?.compactMap(\.string) ?? []
        )
    }

    static func incrementalLogbookExpression(version: String) throws -> String {
        let url = try GarminRoutes.incrementalLogbookURL(version: version)
        return try logbookFetchExpression(url: url)
    }

    static func initialBootstrapLogbookExpression() throws -> String {
        guard let url = URL(string: GarminRoutes.logbookBase), GarminRoutes.isValidLogbookAPIURL(url) else {
            throw GarminError.logbookEndpointUnavailable
        }
        return try logbookFetchExpression(url: url)
    }

    private static func logbookFetchExpression(url: URL) throws -> String {
        guard GarminRoutes.isValidLogbookAPIURL(url) else { throw GarminError.logbookEndpointUnavailable }
        let urlJSON = String(data: try JSONEncoder().encode(url.absoluteString), encoding: .utf8) ?? "\"\""
        return """
        (async () => {
          const logbookUrl = \(urlJSON);
          const pageURL = location.href;
          const pageTitle = document.title || '';
          let parsedUrl = null;
          try { parsedUrl = new URL(logbookUrl); } catch (_) {}
          if (!parsedUrl || parsedUrl.protocol !== 'https:' || parsedUrl.hostname !== 'fly.garmin.com' || !parsedUrl.pathname.startsWith('/fly-garmin/api/logbook/')) {
            return { status: 0, ok: false, jsonOk: false, expectedShape: false, contentType: '', entryCount: 0, topLevelKeys: [], cursor: null, entries: [], pageURL, pageTitle, logbookURL: '', loginRequired: false, humanVerification: false, internalError: 'GARMIN_LOGBOOK_ENDPOINT_UNAVAILABLE' };
          }
          const controller = new AbortController();
          const timeout = setTimeout(() => controller.abort(), 60000);
          try {
            const response = await fetch(logbookUrl, {
              method: 'GET',
              credentials: 'include',
              cache: 'no-store',
              headers: { 'Accept': 'application/json, text/plain, */*' },
              signal: controller.signal
            });
            const contentType = response.headers.get('content-type') || '';
            const text = await response.text();
            const responseByteCount = new TextEncoder().encode(text).length;
            const bodyPreview = text.slice(0, 800)
              .replace(/[A-Za-z0-9_-]{24,}/g, '[redacted-token]')
              .replace(/"[^"]*(token|authorization|cookie|session)[^"]*"\\s*:\\s*"[^"]*"/ig, '"$1":"[redacted]"');
            const sample = text.slice(0, 1200).toLowerCase();
            let json = null;
            let jsonOk = false;
            try { json = JSON.parse(text); jsonOk = true; } catch (_) {}
            const versionIsString = json && typeof json === 'object' && !Array.isArray(json) && typeof json.version === 'string';
            const entriesIsArray = json && typeof json === 'object' && !Array.isArray(json) && Array.isArray(json.entries);
            const expectedShape = versionIsString && entriesIsArray;
            const list = expectedShape ? json.entries : [];
            const normalize = (entry) => {
              const values = [];
              const tracks = [];
              const seen = new Set();
              const seenTracks = new Set();
              const isUuid = (value) => typeof value === 'string' && /^[a-f0-9-]{36}$/i.test(value);
              const add = (value) => {
                if (!isUuid(value)) return;
                const lower = value.toLowerCase();
                if (!seen.has(lower)) { seen.add(lower); values.push(lower); }
              };
              const addTrack = (value) => {
                if (!isUuid(value)) return;
                const lower = value.toLowerCase();
                if (!seenTracks.has(lower)) { seenTracks.add(lower); tracks.push(lower); }
              };
              ['flightDataLogUUID','flightDataLogUuid','flightDataLogId'].forEach((key) => add(entry[key]));
              ['canonicalTrackUUID','canonicalTrackUuid'].forEach((key) => addTrack(entry[key]));
              ['flightDataLogUUIDs','flightDataLogUuids'].forEach((key) => {
                const sourceList = entry[key];
                if (Array.isArray(sourceList)) {
                  sourceList.forEach((item) => {
                    if (isUuid(item)) add(item);
                    if (item && typeof item === 'object') {
                      ['flightDataLogUUID','flightDataLogUuid','flightDataLogId'].forEach((nestedKey) => add(item[nestedKey]));
                    }
                  });
                }
              });
              return {
                uuid: entry.uuid || entry.id || entry.logbookEntryUUID || entry.logbookEntryUuid || null,
                version: entry.version || entry.versionId || entry.modifiedVersion || null,
                aircraftRegistration: entry.aircraftRegistration || entry.aircraftTailNumber || entry.aircraftIdent || entry.tailNumber || null,
                generatedTrackStart: entry.generatedTrackStart || entry.trackStart || entry.startTime || entry.departureTime || null,
                generatedTrackStop: entry.generatedTrackStop || entry.trackStop || entry.endTime || entry.arrivalTime || null,
                flightDataLogUUIDs: values,
                trackUUIDs: tracks,
                raw: entry
              };
            };
            const entries = list.map(normalize).filter((entry) => entry.uuid);
            return {
              status: response.status,
              ok: response.ok,
              contentType,
              responseByteCount,
              bodyPreview,
              pageURL,
              pageTitle,
              logbookURL: logbookUrl,
              jsonOk,
              expectedShape,
              versionExists: versionIsString,
              entriesExists: entriesIsArray,
              topLevelKeys: json && typeof json === 'object' && !Array.isArray(json) ? Object.keys(json).slice(0, 30) : [],
              rawEntryCount: entriesIsArray ? json.entries.length : null,
              entryCount: entries.length,
              cursor: json?.version || json?.cursor || json?.nextCursor || json?.metadata?.version || null,
              firstFlightDataLogUUID: entries[0]?.flightDataLogUUIDs?.[0] || null,
              entries,
              debugSummary: {
                entryCount: list.length,
                topLevelKeys: json && typeof json === 'object' && !Array.isArray(json) ? Object.keys(json).slice(0, 30) : [],
                entrySummaries: list.slice(0, 5).map((entry, index) => 'Entry ' + (index + 1) + ' topKeys=[' + Object.keys(entry || {}).slice(0, 80).join(',') + ']')
              },
              loginRequired: sample.includes('password') || sample.includes('sign in') || sample.includes('multi-factor') || sample.includes('mfa') || sample.includes('verification code'),
              humanVerification: sample.includes('verify you are human') || sample.includes('human verification') || sample.includes('captcha') || sample.includes('cf-challenge')
            };
          } catch (error) {
            return { status: 0, ok: false, jsonOk: false, expectedShape: false, contentType: '', responseByteCount: 0, bodyPreview: '', entryCount: 0, rawEntryCount: null, topLevelKeys: [], cursor: null, entries: [], pageURL, pageTitle, logbookURL: logbookUrl, timeout: error?.name === 'AbortError', error: String(error), jsEvaluationError: String(error), loginRequired: false, humanVerification: false };
          } finally {
            clearTimeout(timeout);
          }
        })()
        """
    }

    static func logbookExpression(cursor: String?) -> String {
        guard let cursor else {
            return """
            (async () => ({ status: 0, ok: false, jsonOk: false, expectedShape: false, contentType: '', entryCount: 0, topLevelKeys: [], cursor: null, entries: [], logbookURL: '', internalError: 'GARMIN_INITIAL_SYNC_BOOTSTRAP_REQUIRED', loginRequired: false, humanVerification: false }))()
            """
        }
        return (try? incrementalLogbookExpression(version: cursor)) ?? """
        (async () => ({ status: 0, ok: false, jsonOk: false, expectedShape: false, contentType: '', entryCount: 0, topLevelKeys: [], cursor: null, entries: [], logbookURL: '', internalError: 'GARMIN_LOGBOOK_ENDPOINT_UNAVAILABLE', loginRequired: false, humanVerification: false }))()
        """
    }

    /*
        let suffix = cursor.map { "?legacyCursor=\($0)" } ?? ""
        let separator = suffix.isEmpty ? "?" : "&"
        return """
        (async () => {
          const pageURL = location.href;
          const pageTitle = document.title || '';
          if (!location.href.startsWith('https://fly.garmin.com/fly-garmin/')) {
            return { status: 0, ok: false, jsonOk: false, contentType: '', entryCount: 0, topLevelKeys: [], cursor: null, entries: [], pageURL, pageTitle, loginRequired: true, humanVerification: false };
          }
          const resources = performance.getEntriesByType('resource')
            .map((entry) => entry.name || '')
            .filter((url) => url.startsWith('https://fly.garmin.com/fly-garmin/api/'));
          const discoveredLogbookUrl = resources
            .filter((url) => {
              try {
                const parsed = new URL(url);
                return parsed.pathname === '/fly-garmin/api/logbook/';
              } catch (_) {
                return false;
              }
            })
            .reverse()[0] || null;
          const fallbackLogbookUrl = 'removed legacy logbook fallback';
          const baseLogbookUrl = discoveredLogbookUrl || fallbackLogbookUrl;
          const logbookUrl = baseLogbookUrl.includes('removed_legacy_cache_key=')
            ? baseLogbookUrl
            : baseLogbookUrl + (baseLogbookUrl.includes('?') ? '&' : '?') + 'removed_legacy_cache_key=' + Date.now();
          const controller = new AbortController();
          const timeout = setTimeout(() => controller.abort(), 60000);
          try {
          const response = await fetch(logbookUrl, {
            method: 'GET',
            credentials: 'include',
            cache: 'reload',
            headers: { 'Accept': 'application/json, text/plain, * / *' },
            signal: controller.signal
          });
          const contentType = response.headers.get('content-type') || '';
          const text = await response.text();
          const sample = text.slice(0, 1200).toLowerCase();
          let json = null;
          let jsonOk = false;
          try { json = JSON.parse(text); jsonOk = true; } catch (_) {}
          const expectedShape = json && typeof json === 'object' && !Array.isArray(json) && Array.isArray(json.entries) && Object.prototype.hasOwnProperty.call(json, 'version') && Object.prototype.hasOwnProperty.call(json, 'settings');
          const list = expectedShape ? json.entries : [];
          const normalize = (entry) => {
            const values = [];
            const seen = new Set();
            const trackValues = [];
            const seenTracks = new Set();
            const isUuid = (value) => typeof value === 'string' && /^[a-f0-9-]{36}$/i.test(value);
            const add = (value) => {
              if (!isUuid(value)) return;
              const lower = value.toLowerCase();
              if (!seen.has(lower)) { seen.add(lower); values.push(lower); }
            };
            const addTrack = (value) => {
              if (!isUuid(value)) return;
              const lower = value.toLowerCase();
              if (!seenTracks.has(lower)) { seenTracks.add(lower); trackValues.push(lower); }
            };
            ['flightDataLogUUID','flightDataLogUuid','flightDataLogId'].forEach((key) => add(entry[key]));
            ['canonicalTrackUUID','canonicalTrackUuid','trackUUID','trackUuid'].forEach((key) => addTrack(entry[key]));
            ['tracks','trackLogs'].forEach((key) => {
              const list = Array.isArray(entry[key]) ? entry[key] : [];
              list.forEach((item) => {
                if (!item || typeof item !== 'object') return;
                ['uuid','trackUUID','trackUuid','canonicalTrackUUID','canonicalTrackUuid'].forEach((nestedKey) => addTrack(item[nestedKey]));
              });
            });
            ['flightDataLogUUIDs','flightDataLogUuids'].forEach((key) => {
              const list = entry[key];
              if (Array.isArray(list)) {
                list.forEach((item) => {
                  if (isUuid(item)) add(item);
                  if (item && typeof item === 'object') {
                    ['flightDataLogUUID','flightDataLogUuid','flightDataLogId'].forEach((nestedKey) => add(item[nestedKey]));
                  }
                });
              }
            });
            ['flightDataLogs','flightDataLog','dataLogs','dataLog','sourceFiles','sources','files','attachments','downloads','csvFiles','csvDownloads'].forEach((key) => {
              const value = entry[key];
              const list = Array.isArray(value) ? value : (value && typeof value === 'object' ? [value] : []);
              list.forEach((item) => {
                if (!item || typeof item !== 'object') return;
                ['flightDataLogUUID','flightDataLogUuid','flightDataLogId'].forEach((nestedKey) => add(item[nestedKey]));
              });
            });
            return {
              uuid: entry.uuid || entry.id || entry.logbookEntryUUID || entry.logbookEntryUuid || null,
              version: entry.version || entry.versionId || entry.modifiedVersion || null,
              aircraftRegistration: entry.aircraftRegistration || entry.aircraftTailNumber || entry.aircraftIdent || entry.tailNumber || null,
              generatedTrackStart: entry.generatedTrackStart || entry.trackStart || entry.startTime || entry.departureTime || null,
              generatedTrackStop: entry.generatedTrackStop || entry.trackStop || entry.endTime || entry.arrivalTime || null,
              flightDataLogUUIDs: values,
              trackUUIDs: trackValues,
              raw: entry
            };
          };
          const entries = list.map(normalize).filter((entry) => entry.uuid && (entry.flightDataLogUUIDs.length > 0 || entry.trackUUIDs.length > 0));
          const summarize = (entry, index) => {
            const relevant = /(flight|data|log|csv|track|source|uuid|file|download)/i;
            const uuidPattern = /^[a-f0-9-]{36}$/i;
            const topKeys = Object.keys(entry || {}).slice(0, 80);
            const lines = [];
            const typeOf = (value) => Array.isArray(value) ? 'array' : (value === null ? 'null' : typeof value);
            const walk = (value, path, depth) => {
              if (lines.length >= 80 || depth > 3 || value === null || value === undefined) return;
              const pathText = path.join('.');
              if (depth > 0 && typeof value === 'object') {
                lines.push(pathText + ':keys(' + Object.keys(value || {}).slice(0, 40).join(',') + ')');
              }
              if (relevant.test(pathText)) {
                let suffix = '';
                if (typeof value === 'string' && uuidPattern.test(value)) suffix = '=' + value;
                lines.push(pathText + ':' + typeOf(value) + suffix);
              }
              if (Array.isArray(value)) {
                value.slice(0, 3).forEach((item, itemIndex) => walk(item, path.concat('[' + itemIndex + ']'), depth + 1));
              } else if (typeof value === 'object') {
                Object.entries(value).slice(0, 40).forEach(([key, item]) => walk(item, path.concat(key), depth + 1));
              }
            };
            walk(entry, ['entry'], 0);
            return 'Entry ' + (index + 1) + ' topKeys=[' + topKeys.join(',') + '] details=[' + lines.join(' | ') + ']';
          };
          const apiResources = resources
            .map((url) => {
              try {
                const parsed = new URL(url);
                return parsed.pathname + (parsed.search ? '?' + parsed.searchParams.toString().slice(0, 80) : '');
              } catch (_) {
                return '';
              }
            })
            .filter((path) => /(flight|data|log|csv|track|source|download)/i.test(path))
            .slice(-20);
          return {
            status: response.status,
            ok: response.ok,
            contentType,
            pageURL,
            pageTitle,
            logbookURL: logbookUrl,
            discoveredLogbookURL: discoveredLogbookUrl,
            garminAPIResourceCount: resources.length,
            jsonOk,
            expectedShape,
            topLevelKeys: json && typeof json === 'object' && !Array.isArray(json) ? Object.keys(json).slice(0, 30) : [],
            entryCount: entries.length,
            cursor: json?.version || json?.cursor || json?.nextCursor || json?.metadata?.version || null,
            firstFlightDataLogUUID: entries[0]?.flightDataLogUUIDs?.[0] || null,
            entries,
            debugSummary: {
              entryCount: list.length,
              topLevelKeys: json && typeof json === 'object' && !Array.isArray(json) ? Object.keys(json).slice(0, 30) : [],
              entrySummaries: ['Garmin API resources: ' + apiResources.join(' | ')].concat(list.slice(0, 5).map(summarize))
            },
            loginRequired: sample.includes('password') || sample.includes('sign in') || sample.includes('multi-factor') || sample.includes('mfa') || sample.includes('verification code'),
            humanVerification: sample.includes('verify you are human') || sample.includes('human verification') || sample.includes('captcha') || sample.includes('cf-challenge')
          };
          } catch (error) {
            return { status: 0, ok: false, jsonOk: false, contentType: '', entryCount: 0, topLevelKeys: [], cursor: null, entries: [], pageURL, pageTitle, logbookURL, discoveredLogbookURL: discoveredLogbookUrl, garminAPIResourceCount: resources.length, timeout: error?.name === 'AbortError', errorMessage: String(error?.message || 'Garmin request failed') };
          } finally {
            clearTimeout(timeout);
          }
        })()
        """
    }
    */

    static func sourceDownloadExpression(sourceUUID: String) -> String {
        let escaped = sourceUUID.replacingOccurrences(of: "'", with: "\\'")
        return """
        (async () => {
          const uuid = '\(escaped)';
          const candidates = [
            'https://fly.garmin.com/fly-garmin/api/logbook/flight-data/' + encodeURIComponent(uuid),
            'https://fly.garmin.com/fly-garmin/api/logbook/flight-data/' + encodeURIComponent(uuid) + '/download',
            'https://fly.garmin.com/fly-garmin/api/flight-data/' + encodeURIComponent(uuid)
          ];
          const attempts = [];
          for (const url of candidates) {
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 60000);
            try {
              const response = await fetch(url, {
                method: 'GET',
                credentials: 'include',
                cache: 'no-store',
                headers: { 'Accept': 'text/csv, application/octet-stream, */*' },
                signal: controller.signal
              });
              const contentType = response.headers.get('content-type') || '';
              const contentDisposition = response.headers.get('content-disposition') || '';
              const buffer = await response.arrayBuffer();
              const bytes = new Uint8Array(buffer);
              let sample = '';
              if (contentType.toLowerCase().includes('html') || response.status === 401 || response.status === 403) {
                sample = new TextDecoder().decode(bytes.slice(0, 1200)).toLowerCase();
              }
              const loginRequired = response.status === 401 || response.status === 403 || sample.includes('password') || sample.includes('sign in') || sample.includes('multi-factor') || sample.includes('mfa') || sample.includes('verification code');
              const humanVerification = sample.includes('verify you are human') || sample.includes('human verification') || sample.includes('captcha') || sample.includes('cf-challenge');
              attempts.push({ url, status: response.status, contentType, loginRequired, humanVerification });
              if (!response.ok || loginRequired || humanVerification || bytes.byteLength === 0) {
                continue;
              }
              let binary = '';
              const chunkSize = 0x8000;
              for (let i = 0; i < bytes.length; i += chunkSize) {
                binary += String.fromCharCode(...bytes.subarray(i, i + chunkSize));
              }
              const filenameMatch = contentDisposition.match(/filename\\*?=(?:UTF-8'')?"?([^";]+)"?/i);
              return {
                status: response.status,
                ok: response.ok,
                finalUrl: response.url || url,
                contentType,
                contentDisposition,
                filename: filenameMatch ? decodeURIComponent(filenameMatch[1]) : (uuid + '.bin'),
                byteLength: bytes.byteLength,
                base64: btoa(binary),
                attempts,
                loginRequired: false,
                humanVerification: false
              };
            } catch (error) {
              attempts.push({ url, status: 0, contentType: '', timeout: error?.name === 'AbortError', errorMessage: String(error?.message || 'Garmin download failed') });
            } finally {
              clearTimeout(timeout);
            }
          }
          const last = attempts[attempts.length - 1] || { status: 0, contentType: '' };
          return {
            status: last.status || 0,
            ok: false,
            contentType: last.contentType || '',
            filename: uuid + '.bin',
            byteLength: 0,
            attempts,
            timeout: attempts.some((attempt) => attempt.timeout),
            loginRequired: attempts.some((attempt) => attempt.loginRequired),
            humanVerification: attempts.some((attempt) => attempt.humanVerification)
          };
        })()
        """
    }
}
