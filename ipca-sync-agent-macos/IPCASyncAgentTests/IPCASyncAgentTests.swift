import XCTest
@testable import IPCASyncAgent

final class IPCASyncAgentTests: XCTestCase {
    private let garminProviderID = "garmin_flygarmin_logbook"

    private func isolatedCursorStore(name: String = UUID().uuidString, synchronize: ((UserDefaults) -> Bool)? = nil) -> CursorStore {
        let suiteName = "IPCASyncAgentTests.\(name)"
        let defaults = UserDefaults(suiteName: suiteName)!
        defaults.removePersistentDomain(forName: suiteName)
        return CursorStore(defaults: defaults, storageRepository: "UserDefaults.\(suiteName)", synchronize: synchronize)
    }

    func testIdempotencyKeyKeepsGpsAndFullSourcesSeparate() {
        let one = DownloadedArtifact(
            provider: "garmin_flygarmin_logbook",
            entryID: "entry-1",
            sourceUUID: "gps-source",
            artifactType: "GARMIN_ORIGINAL_SOURCE",
            originalFilename: "gps.csv",
            contentType: "text/csv",
            contentDisposition: nil,
            localPath: "/tmp/gps.csv",
            byteSize: 10,
            sha256: String(repeating: "a", count: 64),
            sourceClassification: "CSV_UNKNOWN",
            metadata: [:],
            downloadedAt: Date()
        )
        let two = DownloadedArtifact(
            provider: "garmin_flygarmin_logbook",
            entryID: "entry-1",
            sourceUUID: "full-source",
            artifactType: "GARMIN_ORIGINAL_SOURCE",
            originalFilename: "full.csv",
            contentType: "text/csv",
            contentDisposition: nil,
            localPath: "/tmp/full.csv",
            byteSize: 10,
            sha256: String(repeating: "a", count: 64),
            sourceClassification: "CSV_G3X_FULL_OR_PARTIAL_AVIONICS",
            metadata: [:],
            downloadedAt: Date()
        )
        XCTAssertNotEqual(one.idempotencyKey, two.idempotencyKey)
    }

    func testChromeLocatorDoesNotUsePersonalProfilePath() {
        let browser = BrowserSessionController()
        XCTAssertFalse(browser.garminProfileDirectory.path.contains("Google/Chrome/Default"))
        XCTAssertTrue(browser.garminProfileDirectory.path.contains("IPCA Sync Agent/GarminChromeProfile"))
    }

    func testIncrementalLogbookURLUsesSinceParameter() throws {
        let url = try GarminRoutes.incrementalLogbookURL(version: "abc/123+version")
        XCTAssertEqual(url.scheme, "https")
        XCTAssertEqual(url.host, "fly.garmin.com")
        XCTAssertEqual(url.path, "/fly-garmin/api/logbook")
        XCTAssertTrue(url.absoluteString.contains("since="))
        XCTAssertFalse(url.absoluteString.contains("version="))
    }

    func testIncrementalLogbookURLAcceptsFoundationNormalizedBasePathAndBase64Padding() throws {
        let cursor = "MjAyNi0wNy0xNVQxMzozNDoyNS43OTMtMDU6MDA="
        let url = try GarminRoutes.incrementalLogbookURL(version: cursor)

        XCTAssertTrue(GarminRoutes.isValidLogbookAPIURL(url))
        XCTAssertEqual(url.path, "/fly-garmin/api/logbook")
        XCTAssertTrue(url.absoluteString.contains("since=MjAyNi0wNy0xNVQxMzozNDoyNS43OTMtMDU6MDA%3D"))
        XCTAssertEqual(URLComponents(url: url, resolvingAgainstBaseURL: false)?.queryItems?.first?.value, cursor)
    }

    func testTrackURLUsesKnownLogbookRoute() throws {
        let url = try GarminRoutes.trackURL(trackUUID: "12345678-1234-1234-1234-123456789abc")
        XCTAssertEqual(url.absoluteString, "https://fly.garmin.com/fly-garmin/api/logbook/tracks/12345678-1234-1234-1234-123456789abc/")
        XCTAssertTrue(GarminRoutes.isValidLogbookAPIURL(url))
    }

    func testMissingCursorExpressionDoesNotFetchUnknownURL() {
        let expression = GarminProvider.logbookExpression(cursor: nil)
        XCTAssertTrue(expression.contains("GARMIN_INITIAL_SYNC_BOOTSTRAP_REQUIRED"))
        XCTAssertFalse(expression.contains("fetch("))
    }

    func testIncrementalExpressionFetchesKnownURLAfterPageLoad() throws {
        let expression = try GarminProvider.incrementalLogbookExpression(version: "cursor-123")
        XCTAssertTrue(expression.contains("fetch(logbookUrl"))
        XCTAssertTrue(expression.contains("cache: 'no-store'"))
        XCTAssertTrue(expression.contains("since=cursor-123"))
        XCTAssertFalse(expression.contains("_ipca_sync"))
    }

    func testEmptyIncrementalLogbookResponseIsConfirmedAndNoChange() {
        let response: [String: JSONValue] = [
            "status": .number(200),
            "jsonOk": .bool(true),
            "cursor": .string("cursor-after-empty-sync"),
            "rawEntryCount": .number(0),
            "entries": .array([]),
            "topLevelKeys": .array([.string("version"), .string("entries")])
        ]

        XCTAssertTrue(GarminProvider.confirmedLogbookEndpoint(response))
        XCTAssertFalse(GarminProvider.shouldReportLogbookEndpointUnavailable(response))

        let items = GarminProvider.remoteItems(
            from: .object(["entries": .array([])]),
            provider: "garmin_flygarmin_logbook",
            requireArtifacts: true
        )
        XCTAssertTrue(items.isEmpty)
    }

    func testLogbookEntryWithCanonicalTrackUUIDReturnsRemoteSyncItem() {
        let trackUUID = "12345678-1234-1234-1234-123456789abc"
        let entryUUID = "abcdefab-1234-1234-1234-abcdefabcdef"
        let json: JSONValue = .object([
            "entries": .array([
                .object([
                    "uuid": .string(entryUUID),
                    "version": .string("entry-version"),
                    "canonicalTrackUUID": .string(trackUUID)
                ])
            ])
        ])

        let items = GarminProvider.remoteItems(
            from: json,
            provider: "garmin_flygarmin_logbook",
            requireArtifacts: true
        )

        XCTAssertEqual(items.count, 1)
        XCTAssertEqual(items.first?.entryID, entryUUID)
        XCTAssertEqual(items.first?.trackUUIDs, [trackUUID])
        XCTAssertTrue(items.first?.sourceUUIDs.isEmpty == true)
    }

    func testTrackDownloadExpressionUsesInPageFetchForTrackURL() throws {
        let trackUUID = "12345678-1234-1234-1234-123456789abc"
        let expression = try GarminProvider.trackDownloadExpression(trackUUID: trackUUID)

        XCTAssertTrue(expression.contains("/fly-garmin/api/logbook/tracks/\(trackUUID)/"))
        XCTAssertTrue(expression.contains("fetch(requestUrl"))
        XCTAssertTrue(expression.contains("credentials: 'include'"))
        XCTAssertTrue(expression.contains("cache: 'no-store'"))
        XCTAssertTrue(expression.contains("redirect: 'follow'"))
        XCTAssertFalse(expression.contains("Cookie"))
    }

    func testTrackMetricsCountRowsAcrossMultipleSessions() throws {
        let response = GarminTrackResponse(
            formatVersion: 1,
            sessions: [
                GarminTrackSession(
                    fields: [GarminTrackField(fieldType: "time", engine: nil), GarminTrackField(fieldType: "altitude", engine: nil)],
                    data: [
                        .array([.string("2026-01-01T00:00:00Z"), .number(100)]),
                        .array([.string("2026-01-01T00:00:01Z"), .number(101)])
                    ],
                    sources: nil
                ),
                GarminTrackSession(
                    fields: [GarminTrackField(fieldType: "time", engine: nil), GarminTrackField(fieldType: "ias", engine: nil)],
                    data: [
                        .array([.string("2026-01-01T00:01:00Z"), .number(90)]),
                        .array([.string("2026-01-01T00:01:01Z"), .number(91)]),
                        .array([.string("2026-01-01T00:01:02Z"), .number(92)])
                    ],
                    sources: nil
                )
            ]
        )

        let metrics = GarminProvider.trackMetrics(response: response, responseByteCount: 1234)

        XCTAssertEqual(metrics.sessionCount, 2)
        XCTAssertEqual(metrics.totalFieldCount, 4)
        XCTAssertEqual(metrics.rowsPerSession, [2, 3])
        XCTAssertEqual(metrics.totalTelemetryRows, 5)
        XCTAssertEqual(metrics.firstTimestamp, "2026-01-01T00:00:00Z")
        XCTAssertEqual(metrics.lastTimestamp, "2026-01-01T00:01:02Z")
    }

    func testMalformedLogbookResponseIsEndpointUnavailableWithDiagnosticFields() {
        let response: [String: JSONValue] = [
            "status": .number(200),
            "jsonOk": .bool(false),
            "expectedShape": .bool(false),
            "contentType": .string("text/plain"),
            "responseByteCount": .number(12),
            "bodyPreview": .string("not-json"),
            "logbookURL": .string("https://fly.garmin.com/fly-garmin/api/logbook/?since=cursor"),
            "topLevelKeys": .array([])
        ]

        XCTAssertFalse(GarminProvider.confirmedLogbookEndpoint(response))
        XCTAssertTrue(GarminProvider.shouldReportLogbookEndpointUnavailable(response))
    }

    func testUnauthorizedLogbookResponseIsAuthenticationProblem() {
        let unauthorized: [String: JSONValue] = [
            "status": .number(401),
            "jsonOk": .bool(false),
            "expectedShape": .bool(false),
            "contentType": .string("application/json"),
            "bodyPreview": .string("{\"error\":\"unauthorized\"}")
        ]
        let loginPage: [String: JSONValue] = [
            "status": .number(200),
            "jsonOk": .bool(false),
            "expectedShape": .bool(false),
            "contentType": .string("text/html"),
            "bodyPreview": .string("<html><title>Sign in</title><input type=\"password\"></html>")
        ]

        XCTAssertTrue(GarminProvider.logbookProbeAuthenticationProblem(unauthorized))
        XCTAssertTrue(GarminProvider.logbookProbeAuthenticationProblem(loginPage))
        XCTAssertFalse(GarminProvider.shouldReportLogbookEndpointUnavailable(unauthorized))
        XCTAssertFalse(GarminProvider.shouldReportLogbookEndpointUnavailable(loginPage))
    }

    func testRawTrackResponseIsStoredByteForByte() throws {
        let rawJSON = #"{"formatVersion":1,"sessions":[{"fields":[{"fieldType":"time"}],"data":[["2026-01-01T00:00:00Z"]],"sources":[]}]}"#
        let bytes = Data(rawJSON.utf8)
        let decoded = try JSONDecoder().decode(GarminTrackResponse.self, from: bytes)
        let artifact = try LocalArtifactStore().saveTrackJSON(
            provider: "garmin_flygarmin_logbook",
            entryID: "test-entry-byte-for-byte",
            trackUUID: "12345678-1234-1234-1234-123456789abc",
            bytes: bytes,
            response: decoded,
            requestPath: "/fly-garmin/api/logbook/tracks/12345678-1234-1234-1234-123456789abc/",
            contentType: "application/json"
        )
        let stored = try Data(contentsOf: URL(fileURLWithPath: artifact.localPath))

        XCTAssertEqual(stored, bytes)
    }

    func testBootstrapResponseStoresExactVersionString() throws {
        let store = isolatedCursorStore()
        let version = "MjAyNi0wNy0xNVQxMzozNDoyNS43OTMtMDU6MDA="

        try store.persistBootstrapCursor(version, provider: garminProviderID)

        XCTAssertEqual(store.cursor(provider: garminProviderID), version)
        XCTAssertTrue(store.bootstrapCompleted(provider: garminProviderID))
    }

    func testCursorSurvivesRecreationOfSyncCoordinatorStorage() throws {
        let suiteName = "IPCASyncAgentTests.\(UUID().uuidString)"
        let defaults = UserDefaults(suiteName: suiteName)!
        defaults.removePersistentDomain(forName: suiteName)
        let firstStore = CursorStore(defaults: defaults, storageRepository: "UserDefaults.\(suiteName)")
        let version = "cursor-value-with-padding=="

        try firstStore.persistBootstrapCursor(version, provider: garminProviderID)
        let recreatedStore = CursorStore(defaults: defaults, storageRepository: "UserDefaults.\(suiteName)")

        XCTAssertEqual(recreatedStore.cursor(provider: garminProviderID), version)
        XCTAssertTrue(SyncCoordinator.savedCursorIsValid(cursorStore: recreatedStore, providerIdentifier: garminProviderID))
    }

    func testCursorSurvivesApplicationRestartStorageReopen() throws {
        let suiteName = "IPCASyncAgentTests.\(UUID().uuidString)"
        let defaults = UserDefaults(suiteName: suiteName)!
        defaults.removePersistentDomain(forName: suiteName)
        let version = "restart-cursor-with-padding="

        try CursorStore(defaults: defaults, storageRepository: "UserDefaults.\(suiteName)").persistBootstrapCursor(version, provider: garminProviderID)
        let reopenedDefaults = UserDefaults(suiteName: suiteName)!
        let reopenedStore = CursorStore(defaults: reopenedDefaults, storageRepository: "UserDefaults.\(suiteName)")

        XCTAssertEqual(reopenedStore.cursor(provider: garminProviderID), version)
    }

    func testBase64CursorPaddingIsPreserved() throws {
        let store = isolatedCursorStore()
        let version = "MjAyNi0wNy0xNVQxMzozNDoyNS43OTMtMDU6MDA="

        try store.persistBootstrapCursor(version, provider: garminProviderID)

        XCTAssertTrue(store.cursor(provider: garminProviderID)?.hasSuffix("=") == true)
        XCTAssertEqual(store.cursor(provider: garminProviderID), version)
    }

    func testHistoricalLoadingStatusDoesNotClearCursor() throws {
        let store = isolatedCursorStore()
        let version = "historical-loading-must-not-clear="
        try store.persistBootstrapCursor(version, provider: garminProviderID)

        _ = SyncCoordinator.logbookEndpointUnavailableMessage(hasValidSavedCursor: true)

        XCTAssertEqual(store.cursor(provider: garminProviderID), version)
        XCTAssertTrue(store.bootstrapCompleted(provider: garminProviderID))
    }

    func testEmptyEntriesArrayDoesNotClearCursor() throws {
        let store = isolatedCursorStore()
        let version = "empty-entries-must-not-clear="
        try store.persistBootstrapCursor(version, provider: garminProviderID)
        let response: [String: JSONValue] = [
            "status": .number(200),
            "jsonOk": .bool(true),
            "cursor": .string(version),
            "rawEntryCount": .number(0),
            "entries": .array([])
        ]

        XCTAssertTrue(GarminProvider.confirmedLogbookEndpoint(response))
        XCTAssertTrue(GarminProvider.remoteItems(from: .object(["entries": .array([])]), provider: garminProviderID, requireArtifacts: true).isEmpty)
        XCTAssertEqual(store.cursor(provider: garminProviderID), version)
    }

    func testBootstrapSuccessIsBlockedWhenPersistenceFails() {
        let store = isolatedCursorStore { _ in false }

        XCTAssertThrowsError(try store.persistBootstrapCursor("cursor-that-cannot-sync", provider: garminProviderID))
        XCTAssertFalse(store.bootstrapCompleted(provider: garminProviderID))
    }

    func testBootstrapWritingAndSyncReadingUseSameKeyAndRepository() throws {
        let store = isolatedCursorStore()
        let version = "same-key-and-repository="
        try store.persistBootstrapCursor(version, provider: garminProviderID)
        let diagnostic = store.valueDiagnostic(provider: garminProviderID)

        XCTAssertEqual(diagnostic.storageKey, CursorStore.storageKey(provider: garminProviderID))
        XCTAssertEqual(store.cursor(provider: garminProviderID), version)
        XCTAssertTrue(diagnostic.storageRepository.hasPrefix("UserDefaults."))
    }

    func testAfterBootstrapSucceedsNextSyncNowDoesNotReturnBootstrapRequiredMessage() throws {
        let store = isolatedCursorStore()
        try store.persistBootstrapCursor("valid-saved-cursor=", provider: garminProviderID)

        let message = SyncCoordinator.logbookEndpointUnavailableMessage(
            hasValidSavedCursor: SyncCoordinator.savedCursorIsValid(cursorStore: store, providerIdentifier: garminProviderID)
        )

        XCTAssertFalse(message.contains("Reload Garmin Logbook for Initial Sync"))
        XCTAssertTrue(message.contains("saved cursor remains valid"))
    }
}
