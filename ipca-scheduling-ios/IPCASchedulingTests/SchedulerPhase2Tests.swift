import XCTest
@testable import IPCAScheduling

final class SchedulerURLProtocol: URLProtocol {
    static var handler: ((URLRequest) throws -> (Int, Data))?

    override class func canInit(with request: URLRequest) -> Bool { true }
    override class func canonicalRequest(for request: URLRequest) -> URLRequest { request }

    override func startLoading() {
        do {
            let (status, data) = try Self.handler?(request) ?? (500, Data())
            let response = HTTPURLResponse(
                url: request.url!,
                statusCode: status,
                httpVersion: nil,
                headerFields: ["Content-Type": "application/json"]
            )!
            client?.urlProtocol(self, didReceive: response, cacheStoragePolicy: .notAllowed)
            client?.urlProtocol(self, didLoad: data)
            client?.urlProtocolDidFinishLoading(self)
        } catch {
            client?.urlProtocol(self, didFailWithError: error)
        }
    }

    override func stopLoading() {}
}

final class MemoryTokenStore: SessionTokenStoring {
    var value: String?

    func token() -> String? { value }
    func save(token: String) throws { value = token }
    func clear() { value = nil }
}

@MainActor
final class SchedulerPhase2Tests: XCTestCase {
    private var urlSession: URLSession!
    private var temporaryDirectory: URL!
    private var tokenStore: MemoryTokenStore!

    override func setUp() {
        super.setUp()
        let configuration = URLSessionConfiguration.ephemeral
        configuration.protocolClasses = [SchedulerURLProtocol.self]
        urlSession = URLSession(configuration: configuration)
        temporaryDirectory = FileManager.default.temporaryDirectory
            .appendingPathComponent(UUID().uuidString, isDirectory: true)
        try? FileManager.default.createDirectory(at: temporaryDirectory, withIntermediateDirectories: true)
        tokenStore = MemoryTokenStore()
        SchedulerURLProtocol.handler = nil
        UserDefaults.standard.removeObject(forKey: "ipca.scheduling.bootstrap")
        UserDefaults.standard.removeObject(forKey: "ipca.scheduling.filters")
    }

    override func tearDown() {
        SchedulerURLProtocol.handler = nil
        UserDefaults.standard.removeObject(forKey: "ipca.scheduling.bootstrap")
        UserDefaults.standard.removeObject(forKey: "ipca.scheduling.filters")
        try? FileManager.default.removeItem(at: temporaryDirectory)
        urlSession.invalidateAndCancel()
        super.tearDown()
    }

    func testSuccessfulLoginUsesSchedulerAuthWithoutBearer() async throws {
        SchedulerURLProtocol.handler = { request in
            XCTAssertEqual(request.url?.path, "/api/scheduler/auth.php")
            XCTAssertEqual(request.httpMethod, "POST")
            XCTAssertNil(request.value(forHTTPHeaderField: "Authorization"))
            let body = try XCTUnwrap(Self.requestBody(request))
            let json = try XCTUnwrap(JSONSerialization.jsonObject(with: body) as? [String: Any])
            XCTAssertEqual(json["action"] as? String, "login")
            XCTAssertEqual(json["email"] as? String, "kay@example.test")
            XCTAssertNil(json["password"] as? NSNull)
            return (200, Self.loginJSON)
        }
        let client = makeClient()
        let result = try await client.login(
            email: "kay@example.test",
            password: "secret",
            device: DevicePayload(
                deviceUUID: "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa",
                platform: "iphone",
                model: "iPhone",
                osVersion: "19.0",
                appVersion: "1.0"
            )
        )
        XCTAssertEqual(result.token, "session-token")
        XCTAssertEqual(result.user.name, "Kay Vereeken")
    }

    func testSessionRestorationUsesBearerAndLoadsSchedule() async throws {
        tokenStore.value = "restored-token"
        SchedulerURLProtocol.handler = { request in
            XCTAssertEqual(request.value(forHTTPHeaderField: "Authorization"), "Bearer restored-token")
            switch request.url?.lastPathComponent {
            case "bootstrap.php": return (200, Self.bootstrapJSON)
            case "schedule.php": return (200, Self.scheduleJSON)
            default: return (404, Data())
            }
        }
        let session = makeAppSession()
        await session.start()
        XCTAssertEqual(session.launchState, .signedIn)
        XCTAssertEqual(session.user?.id, 42)
        XCTAssertEqual(session.reservations.count, 4)
        XCTAssertFalse(session.isShowingCachedData)
    }

    func testRevokedSessionReturnsToLogin() async throws {
        tokenStore.value = "revoked-token"
        SchedulerURLProtocol.handler = { _ in
            (401, Data("""
            {"ok":false,"error_code":"unauthenticated","message":"Credential revoked.","retryable":false,"user_action_required":false}
            """.utf8))
        }
        let session = makeAppSession()
        await session.start()
        XCTAssertEqual(session.launchState, .signedOut)
        XCTAssertNil(tokenStore.value)
    }

    func testAccountIneligibleHasFriendlyMessage() async throws {
        tokenStore.value = "inactive-token"
        SchedulerURLProtocol.handler = { _ in
            (403, Data("""
            {"ok":false,"error_code":"account_ineligible","message":"Account expired.","retryable":false,"user_action_required":false}
            """.utf8))
        }
        let session = makeAppSession()
        await session.start()
        XCTAssertEqual(session.launchState, .signedOut)
        XCTAssertEqual(session.errorMessage, "Your IPCA account is currently unavailable.")
    }

    func testBootstrapCapabilitiesAndTimezoneDecode() throws {
        let response = try JSONDecoder().decode(SchedulerBootstrapResponse.self, from: Self.bootstrapJSON)
        XCTAssertTrue(response.capabilities.scheduleRead)
        XCTAssertTrue(response.capabilities.resourceSearch)
        XCTAssertFalse(response.capabilities.dispatch)
        XCTAssertEqual(response.operationalTimezone, "America/Los_Angeles")
        XCTAssertEqual(response.scheduler.maxRangeDays, 31)
    }

    func testMalformedBootstrapIsRejected() async {
        SchedulerURLProtocol.handler = { _ in (200, Data(#"{"ok":true}"#.utf8)) }
        let client = makeClient(token: "token")
        do {
            let _: SchedulerBootstrapResponse = try await client.bootstrap()
            XCTFail("Expected malformed payload")
        } catch let error as SchedulerAPIError {
            XCTAssertEqual(error, .malformedResponse)
        } catch {
            XCTFail("Unexpected error \(error)")
        }
    }

    func testTodayNoReservation() {
        let sections = TodayOrganizer.sections(
            reservations: [],
            dayKey: "2026-08-19",
            now: SchedulerFixtures.now,
            clock: clock
        )
        XCTAssertTrue(sections.isEmpty)
    }

    func testTodayOneReservationBecomesNext() {
        let reservation = SchedulerFixtures.featuredReservation
        let sections = TodayOrganizer.sections(
            reservations: [reservation],
            dayKey: "2026-08-19",
            now: SchedulerFixtures.now,
            clock: clock
        )
        XCTAssertEqual(sections.map(\.0), [.next])
        XCTAssertEqual(sections.first?.1.first?.id, reservation.id)
    }

    func testTodayMultipleUsesCanonicalInProgressAndCompletedStates() {
        let sections = TodayOrganizer.sections(
            reservations: SchedulerFixtures.schedule.reservations,
            dayKey: "2026-08-19",
            now: SchedulerFixtures.now,
            clock: clock
        )
        XCTAssertEqual(sections.map(\.0), [.inProgress, .next, .completed])
        XCTAssertEqual(sections.first?.1.first?.status, "claimed")
        XCTAssertEqual(sections.last?.1.first?.status, "completed")
    }

    func testCancelledReservationIsNotShownToday() {
        let cancelled = replacing(SchedulerFixtures.featuredReservation, status: "cancelled")
        let sections = TodayOrganizer.sections(
            reservations: [cancelled],
            dayKey: "2026-08-19",
            now: SchedulerFixtures.now,
            clock: clock
        )
        XCTAssertTrue(sections.isEmpty)
    }

    func testScheduleRequestUsesBoundedRangeAndFilters() async throws {
        SchedulerURLProtocol.handler = { request in
            let components = try XCTUnwrap(URLComponents(url: request.url!, resolvingAgainstBaseURL: false))
            let query = Dictionary(uniqueKeysWithValues: (components.queryItems ?? []).map { ($0.name, $0.value ?? "") })
            XCTAssertEqual(query["start"], "2026-08-17")
            XCTAssertEqual(query["end"], "2026-08-23")
            XCTAssertEqual(query["aircraft_id"], "42")
            XCTAssertEqual(query["reservation_type"], "flight_training")
            return (200, Self.scheduleJSON)
        }
        let client = makeClient(token: "token")
        let result = try await client.schedule(
            from: "2026-08-17",
            to: "2026-08-23",
            filters: ScheduleFilters(
                aircraftID: 42,
                aircraftLabel: "N428EA",
                participantUserID: nil,
                participantLabel: nil,
                cohortID: nil,
                cohortLabel: nil,
                reservationType: "flight_training"
            )
        )
        XCTAssertEqual(result.reservations.count, 4)
    }

    func testSelectedDayAndEmptyDay() {
        let selected = SchedulerFixtures.schedule.reservations.filter { $0.localDateKey == "2026-08-19" }
        let empty = SchedulerFixtures.schedule.reservations.filter { $0.localDateKey == "2026-08-25" }
        XCTAssertEqual(selected.count, 3)
        XCTAssertTrue(empty.isEmpty)
    }

    func testWeekNavigationKeepsSevenOperationalDates() throws {
        let date = try XCTUnwrap(clock.date(fromDayKey: "2026-08-19"))
        let week = clock.week(containing: date)
        XCTAssertEqual(week.count, 7)
        XCTAssertEqual(clock.dayKey(for: week.first!), "2026-08-17")
        XCTAssertEqual(clock.dayKey(for: week.last!), "2026-08-23")
    }

    func testFilterPersistenceShape() throws {
        let filters = ScheduleFilters(
            aircraftID: 42,
            aircraftLabel: "N428EA",
            participantUserID: 102,
            participantLabel: "Jarne Deruyck",
            cohortID: 3,
            cohortLabel: "PPL 2026",
            reservationType: "flight_training"
        )
        XCTAssertEqual(try JSONDecoder().decode(ScheduleFilters.self, from: JSONEncoder().encode(filters)), filters)
    }

    func testReservationDetailSupportsMultiLegCrewWarningAndLock() {
        let detail = SchedulerFixtures.detail
        XCTAssertEqual(detail.reservation.route.legs.count, 3)
        XCTAssertEqual(detail.reservation.crew.count, 2)
        XCTAssertEqual(detail.validation?.warnings.first?.code, "aircraft_overlap")
        XCTAssertFalse(detail.reservation.lock.locked)

        let locked = SchedulerFixtures.schedule.reservations.first { $0.status == "claimed" }
        XCTAssertEqual(locked?.lock.locked, true)
        XCTAssertEqual(locked?.route.legs.count, 2)
    }

    func testCancelledDetailRetainsReadableCanonicalStatus() {
        let cancelled = replacing(SchedulerFixtures.featuredReservation, status: "cancelled")
        XCTAssertTrue(cancelled.isCancelled)
        XCTAssertEqual(cancelled.title, "Navigation Training")
        XCTAssertFalse(cancelled.route.airportChain.isEmpty)
    }

    func testPSTAndPDTLocalTimesDoNotShift() {
        XCTAssertEqual(clock.time("2026-01-15T10:00:00.000"), "10:00")
        XCTAssertEqual(clock.time("2026-07-15T10:00:00.000"), "10:00")
        XCTAssertEqual(clock.dayKey(for: clock.date(fromLocal: "2026-01-15T10:00:00.000")!), "2026-01-15")
        XCTAssertEqual(clock.dayKey(for: clock.date(fromLocal: "2026-07-15T10:00:00.000")!), "2026-07-15")
    }

    func testDSTBoundaryFixturesPreserveWallClockPresentation() {
        XCTAssertEqual(clock.time("2026-03-08T02:30:00.000"), "02:30")
        XCTAssertEqual(clock.time("2026-11-01T01:30:00.000"), "01:30")
        XCTAssertNil(clock.date(fromLocal: "2026-03-08T02:30:00.000"))
        XCTAssertNotNil(clock.date(fromLocal: "2026-11-01T01:30:00.000"))
    }

    func testOnlineRefreshWritesDiskCache() async throws {
        let cache = ScheduleDiskCache(directory: temporaryDirectory)
        await cache.save(
            SchedulerFixtures.schedule,
            userID: 42,
            start: "2026-08-12",
            end: "2026-09-09",
            filters: .empty,
            aircraftResources: SchedulerFixtures.workstationAircraft,
            personResources: SchedulerFixtures.workstationPeople,
            savedAt: SchedulerFixtures.now
        )
        let loaded = await cache.load(
            userID: 42,
            start: "2026-08-12",
            end: "2026-09-09",
            filters: .empty
        )
        XCTAssertEqual(loaded?.response.reservations.count, 4)
        XCTAssertEqual(loaded?.savedAt, SchedulerFixtures.now)
        XCTAssertEqual(loaded?.aircraftResources?.count, SchedulerFixtures.workstationAircraft.count)
        XCTAssertEqual(loaded?.personResources?.count, SchedulerFixtures.workstationPeople.count)
    }

    func testOfflineRestartUsesCache() async throws {
        tokenStore.value = "cached-token"
        UserDefaults.standard.set(Self.bootstrapJSON, forKey: "ipca.scheduling.bootstrap")
        let cache = ScheduleDiskCache(directory: temporaryDirectory)
        await cache.save(
            SchedulerFixtures.schedule,
            userID: 42,
            start: "2026-08-12",
            end: "2026-09-09",
            filters: .empty,
            aircraftResources: SchedulerFixtures.workstationAircraft,
            personResources: SchedulerFixtures.workstationPeople,
            savedAt: SchedulerFixtures.now
        )
        SchedulerURLProtocol.handler = { _ in throw URLError(.notConnectedToInternet) }
        let session = makeAppSession(cache: cache)
        await session.start()
        XCTAssertEqual(session.launchState, .signedIn)
        XCTAssertEqual(session.reservations.count, 4)
        XCTAssertEqual(session.aircraftResources.count, SchedulerFixtures.workstationAircraft.count)
        XCTAssertTrue(session.isShowingCachedData)
        XCTAssertNotNil(session.errorMessage)
    }

    func testOfflineWithoutCacheShowsRetryableErrorState() async throws {
        tokenStore.value = "cached-token"
        UserDefaults.standard.set(Self.bootstrapJSON, forKey: "ipca.scheduling.bootstrap")
        SchedulerURLProtocol.handler = { _ in throw URLError(.notConnectedToInternet) }
        let session = makeAppSession()
        await session.start()
        XCTAssertEqual(session.launchState, .signedIn)
        XCTAssertTrue(session.reservations.isEmpty)
        XCTAssertNotNil(session.errorMessage)
    }

    func testServerFailureDoesNotExposeRawPayload() async {
        SchedulerURLProtocol.handler = { _ in
            (500, Data("<b>PHP fatal error</b>".utf8))
        }
        let client = makeClient(token: "token")
        do {
            let _: SchedulerBootstrapResponse = try await client.bootstrap()
            XCTFail("Expected failure")
        } catch let error as SchedulerAPIError {
            XCTAssertEqual(error.errorCode, "server_error")
            XCTAssertFalse(error.localizedDescription.contains("PHP"))
        } catch {
            XCTFail("Unexpected error")
        }
    }

    private var clock: SchedulerClock {
        SchedulerClock(timezoneIdentifier: "America/Los_Angeles")
    }

    private func makeClient(token: String? = nil) -> SchedulerAPIClient {
        SchedulerAPIClient(
            baseURL: URL(string: "https://example.test")!,
            bearerToken: token,
            session: urlSession
        )
    }

    private func makeAppSession(cache: ScheduleDiskCache? = nil) -> SchedulingSession {
        SchedulingSession(
            api: makeClient(),
            cache: cache ?? ScheduleDiskCache(directory: temporaryDirectory),
            connectivity: ConnectivityMonitor(),
            tokenStore: tokenStore,
            previewScreen: nil,
            now: SchedulerFixtures.now
        )
    }

    private func replacing(_ source: SchedulerReservation, status: String) -> SchedulerReservation {
        SchedulerReservation(
            reservationUUID: source.reservationUUID + "-\(status)",
            schedulerRecordID: source.schedulerRecordID,
            reservationType: source.reservationType,
            reservationTypeLabel: source.reservationTypeLabel,
            startLocal: source.startLocal,
            endLocal: source.endLocal,
            operationalTimezone: source.operationalTimezone,
            status: status,
            lock: source.lock,
            aircraft: source.aircraft,
            mission: source.mission,
            cohort: source.cohort,
            crew: source.crew,
            route: source.route,
            notes: source.notes,
            evidence: source.evidence,
            updatedAt: source.updatedAt,
            authorizedActions: source.authorizedActions
        )
    }

    private static let loginJSON = Data("""
    {
      "ok": true,
      "token": "session-token",
      "user": {"id": 42, "uuid": "f0000000-0000-4000-8000-000000000042", "email": "kay@example.test", "name": "Kay Vereeken", "role": "supervisor"},
      "device": {"id": 1, "device_uuid": "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa", "organization_id": 1, "platform": "iphone"}
    }
    """.utf8)

    private static let bootstrapJSON = try! JSONEncoder().encode(SchedulerFixtures.bootstrap)
    private static let scheduleJSON = try! JSONEncoder().encode(SchedulerFixtures.schedule)

    private static func requestBody(_ request: URLRequest) -> Data? {
        if let data = request.httpBody { return data }
        guard let stream = request.httpBodyStream else { return nil }
        stream.open()
        defer { stream.close() }
        var data = Data()
        let buffer = UnsafeMutablePointer<UInt8>.allocate(capacity: 4096)
        defer { buffer.deallocate() }
        while stream.hasBytesAvailable {
            let count = stream.read(buffer, maxLength: 4096)
            if count <= 0 { break }
            data.append(buffer, count: count)
        }
        return data
    }
}
