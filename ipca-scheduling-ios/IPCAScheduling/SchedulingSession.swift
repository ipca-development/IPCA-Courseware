import Foundation
import SwiftUI

@MainActor
final class SchedulingSession: ObservableObject {
    enum LaunchState: Equatable {
        case launching
        case signedOut
        case signedIn
    }

    @Published private(set) var launchState: LaunchState = .launching
    @Published private(set) var bootstrap: SchedulerBootstrapResponse?
    @Published private(set) var reservations: [SchedulerReservation] = []
    @Published private(set) var detailWarnings: [String: [SchedulerWarning]] = [:]
    @Published private(set) var isRefreshing = false
    @Published private(set) var lastUpdated: Date?
    @Published private(set) var isShowingCachedData = false
    @Published var errorMessage: String?
    @Published var selectedDate: Date
    @Published var filters: ScheduleFilters
    @Published var selectedTab = 0

    let api: SchedulerAPIClient
    let cache: ScheduleDiskCache
    let connectivity: ConnectivityMonitor
    let tokenStore: any SessionTokenStoring
    let previewScreen: PreviewScreen?
    let now: Date

    private var hasStarted = false
    private var lastForegroundRefresh: Date?
    private static let bootstrapCacheKey = "ipca.scheduling.bootstrap"
    private static let filterCacheKey = "ipca.scheduling.filters"

    init(
        api: SchedulerAPIClient = SchedulerAPIClient(),
        cache: ScheduleDiskCache = ScheduleDiskCache(),
        connectivity: ConnectivityMonitor? = nil,
        tokenStore: any SessionTokenStoring = KeychainSessionTokenStore(),
        previewScreen: PreviewScreen? = nil,
        now: Date? = nil
    ) {
        self.api = api
        self.cache = cache
        self.connectivity = connectivity ?? ConnectivityMonitor()
        self.tokenStore = tokenStore
        let resolvedPreview = previewScreen ?? Self.previewArgument()
        self.previewScreen = resolvedPreview
        self.now = now ?? (resolvedPreview == nil ? Date() : SchedulerFixtures.now)
        let initialClock = SchedulerClock(timezoneIdentifier: "America/Los_Angeles")
        selectedDate = self.now
        if let data = UserDefaults.standard.data(forKey: Self.filterCacheKey),
           let saved = try? JSONDecoder().decode(ScheduleFilters.self, from: data) {
            filters = saved
        } else {
            filters = .empty
        }
        selectedDate = initialClock.date(fromDayKey: initialClock.dayKey(for: self.now)) ?? self.now
    }

    var user: SchedulerUser? { bootstrap?.user }
    var capabilities: SchedulerCapabilities? { bootstrap?.capabilities }
    var operationalTimezone: String { bootstrap?.operationalTimezone ?? "America/Los_Angeles" }
    var clock: SchedulerClock { SchedulerClock(timezoneIdentifier: operationalTimezone) }
    var isStaffExperience: Bool { capabilities?.resourceSearch == true }
    var todayKey: String { clock.dayKey(for: now) }

    var personalReservations: [SchedulerReservation] {
        guard let userID = user?.id else { return reservations }
        return reservations.filter { reservation in
            reservation.crew.contains { $0.personID == userID }
        }
    }

    var selectedDayReservations: [SchedulerReservation] {
        let key = clock.dayKey(for: selectedDate)
        return reservations
            .filter { $0.localDateKey == key && !$0.isCancelled }
            .sorted { $0.startLocal < $1.startLocal }
    }

    var todaySections: [(TodaySection, [SchedulerReservation])] {
        TodayOrganizer.sections(
            reservations: personalReservations,
            dayKey: todayKey,
            now: now,
            clock: clock
        )
    }

    var nextUpcomingReservation: SchedulerReservation? {
        personalReservations
            .filter { !$0.isCancelled && !$0.isCompleted }
            .filter { clock.date(fromLocal: $0.startLocal).map { $0 > now } ?? false }
            .sorted { $0.startLocal < $1.startLocal }
            .first
    }

    func start() async {
        guard !hasStarted else { return }
        hasStarted = true
        if let previewScreen {
            if previewScreen == .login {
                launchState = .signedOut
            } else {
                installFixtures(for: previewScreen)
            }
            return
        }

        guard let token = tokenStore.token() else {
            launchState = .signedOut
            return
        }
        await api.setBearerToken(token)
        if let cachedBootstrap = loadCachedBootstrap() {
            bootstrap = cachedBootstrap
            launchState = .signedIn
            await loadCachedSchedule()
        }
        do {
            let response = try await api.bootstrap()
            bootstrap = response
            saveBootstrap(response)
            launchState = .signedIn
            await refresh(force: true)
        } catch {
            if handleAuthentication(error) { return }
            if bootstrap != nil {
                errorMessage = friendly(error)
                isShowingCachedData = true
            } else {
                launchState = .signedOut
                errorMessage = friendly(error)
            }
        }
    }

    func signIn(email: String, password: String) async {
        guard !email.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty,
              !password.isEmpty else {
            errorMessage = "Enter your email and password."
            return
        }
        isRefreshing = true
        errorMessage = nil
        defer { isRefreshing = false }
        do {
            let login = try await api.login(
                email: email.trimmingCharacters(in: .whitespacesAndNewlines),
                password: password,
                device: DeviceIdentity.payload
            )
            try tokenStore.save(token: login.token)
            await api.setBearerToken(login.token)
            let response = try await api.bootstrap()
            bootstrap = response
            saveBootstrap(response)
            launchState = .signedIn
            await refresh(force: true)
        } catch {
            clearAuthentication(keepError: true)
            errorMessage = friendly(error)
        }
    }

    func signOut() async {
        if previewScreen == nil { try? await api.logout() }
        clearAuthentication(keepError: false)
        await cache.clear()
    }

    func refresh(force: Bool = false) async {
        guard launchState == .signedIn, let userID = user?.id else { return }
        if isRefreshing && !force { return }
        isRefreshing = true
        errorMessage = nil
        defer { isRefreshing = false }

        let range = fetchRange(containing: selectedDate)
        if reservations.isEmpty { await loadCachedSchedule(range: range) }
        guard previewScreen == nil else {
            lastUpdated = now
            isShowingCachedData = false
            return
        }
        do {
            let response = try await api.schedule(
                from: range.start,
                to: range.end,
                filters: filters
            )
            reservations = response.reservations
            let refreshed = response.refreshedAt.flatMap(ISO8601DateFormatter().date) ?? Date()
            lastUpdated = refreshed
            isShowingCachedData = false
            lastForegroundRefresh = Date()
            await cache.save(
                response,
                userID: userID,
                start: range.start,
                end: range.end,
                filters: filters,
                savedAt: refreshed
            )
        } catch {
            if handleAuthentication(error) { return }
            isShowingCachedData = !reservations.isEmpty
            errorMessage = friendly(error)
        }
    }

    func refreshOnForeground() async {
        let elapsed = lastForegroundRefresh.map { Date().timeIntervalSince($0) } ?? .infinity
        if elapsed >= 300 { await refresh() }
    }

    func selectDate(_ date: Date) {
        selectedDate = date
        UISelectionFeedbackGenerator().selectionChanged()
    }

    func moveWeek(by value: Int) {
        if let date = clock.calendar.date(byAdding: .weekOfYear, value: value, to: selectedDate) {
            selectDate(date)
        }
    }

    func goToToday() {
        selectDate(clock.date(fromDayKey: todayKey) ?? now)
    }

    func applyFilters(_ newFilters: ScheduleFilters) async {
        filters = newFilters
        if let data = try? JSONEncoder().encode(newFilters) {
            UserDefaults.standard.set(data, forKey: Self.filterCacheKey)
        }
        UINotificationFeedbackGenerator().notificationOccurred(.success)
        await refresh(force: true)
    }

    func detail(for reservation: SchedulerReservation) async -> ReservationDetailResponse {
        if previewScreen != nil {
            return reservation.id == SchedulerFixtures.featuredReservation.id
                ? SchedulerFixtures.detail
                : ReservationDetailResponse(
                    ok: true,
                    operationalTimezone: operationalTimezone,
                    reservation: reservation,
                    validation: SchedulerValidation(result: "allowed", warnings: [])
                )
        }
        do {
            let response = try await api.reservation(uuid: reservation.reservationUUID)
            detailWarnings[reservation.id] = response.validation?.warnings ?? []
            if let index = reservations.firstIndex(where: { $0.id == reservation.id }) {
                reservations[index] = response.reservation
            }
            return response
        } catch {
            if handleAuthentication(error) {
                return ReservationDetailResponse(
                    ok: true,
                    operationalTimezone: operationalTimezone,
                    reservation: reservation,
                    validation: nil
                )
            }
            errorMessage = friendly(error)
            return ReservationDetailResponse(
                ok: true,
                operationalTimezone: operationalTimezone,
                reservation: reservation,
                validation: SchedulerValidation(
                    result: "allowed",
                    warnings: detailWarnings[reservation.id] ?? []
                )
            )
        }
    }

    func resourceOptions(type: String, query: String = "") async -> [SchedulerResourceItem] {
        guard previewScreen == nil else {
            switch type {
            case "aircraft":
                return [
                    SchedulerResourceItem(id: 42, registration: "N428EA", displayName: "Alpha Trainer", aircraftType: "Pipistrel Alpha", homeAirport: "KTRM", role: nil, code: nil, name: nil),
                    SchedulerResourceItem(id: 28, registration: "N397EA", displayName: "Alpha Trainer", aircraftType: "Pipistrel Alpha", homeAirport: "KTRM", role: nil, code: nil, name: nil)
                ]
            case "person":
                return [
                    SchedulerResourceItem(id: 102, registration: nil, displayName: "Jarne Deruyck", aircraftType: nil, homeAirport: nil, role: "student", code: nil, name: nil),
                    SchedulerResourceItem(id: 101, registration: nil, displayName: "Tasha Welvis", aircraftType: nil, homeAirport: nil, role: "student", code: nil, name: nil)
                ]
            default:
                return []
            }
        }
        do {
            return try await api.resources(type: type, query: query).items
        } catch {
            errorMessage = friendly(error)
            return []
        }
    }

    private func installFixtures(for screen: PreviewScreen) {
        bootstrap = SchedulerFixtures.bootstrap
        reservations = SchedulerFixtures.schedule.reservations
        detailWarnings[SchedulerFixtures.featuredReservation.id] = [SchedulerFixtures.warning]
        selectedDate = clock.date(fromDayKey: "2026-08-19") ?? now
        lastUpdated = now.addingTimeInterval(-60)
        launchState = .signedIn
        if screen == .schedule || screen == .filters { selectedTab = 1 }
    }

    private func fetchRange(containing date: Date) -> ScheduleRange {
        let calendar = clock.calendar
        let startDate = calendar.date(byAdding: .day, value: -7, to: date) ?? date
        let endDate = calendar.date(byAdding: .day, value: 21, to: date) ?? date
        return ScheduleRange(start: clock.dayKey(for: startDate), end: clock.dayKey(for: endDate))
    }

    private func loadCachedSchedule(range: ScheduleRange? = nil) async {
        guard let userID = user?.id else { return }
        let range = range ?? fetchRange(containing: selectedDate)
        guard let cached = await cache.load(
            userID: userID,
            start: range.start,
            end: range.end,
            filters: filters
        ) else { return }
        reservations = cached.response.reservations
        lastUpdated = cached.savedAt
        isShowingCachedData = true
    }

    private func saveBootstrap(_ response: SchedulerBootstrapResponse) {
        if let data = try? JSONEncoder().encode(response) {
            UserDefaults.standard.set(data, forKey: Self.bootstrapCacheKey)
        }
    }

    private func loadCachedBootstrap() -> SchedulerBootstrapResponse? {
        guard let data = UserDefaults.standard.data(forKey: Self.bootstrapCacheKey) else { return nil }
        return try? JSONDecoder().decode(SchedulerBootstrapResponse.self, from: data)
    }

    @discardableResult
    private func handleAuthentication(_ error: Error) -> Bool {
        guard let apiError = error as? SchedulerAPIError,
              apiError.isAuthenticationFailure || apiError.isAccountIneligible else {
            return false
        }
        clearAuthentication(keepError: true)
        errorMessage = apiError.localizedDescription
        return true
    }

    private func clearAuthentication(keepError: Bool) {
        tokenStore.clear()
        Task { await api.setBearerToken(nil) }
        bootstrap = nil
        reservations = []
        detailWarnings = [:]
        lastUpdated = nil
        isShowingCachedData = false
        launchState = .signedOut
        UserDefaults.standard.removeObject(forKey: Self.bootstrapCacheKey)
        if !keepError { errorMessage = nil }
    }

    private func friendly(_ error: Error) -> String {
        if let apiError = error as? SchedulerAPIError {
            return apiError.localizedDescription
        }
        return "We couldn't update the schedule."
    }

    private static func previewArgument() -> PreviewScreen? {
        let arguments = ProcessInfo.processInfo.arguments
        guard let index = arguments.firstIndex(of: "--ui-preview"),
              arguments.indices.contains(index + 1) else {
            return nil
        }
        return PreviewScreen(rawValue: arguments[index + 1])
    }
}
