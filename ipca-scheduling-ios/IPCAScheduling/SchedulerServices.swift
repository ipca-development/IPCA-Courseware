import Foundation
import Network
import Security
import UIKit

struct DevicePayload: Codable, Equatable {
    let deviceUUID: String
    let platform: String
    let model: String
    let osVersion: String
    let appVersion: String

    enum CodingKeys: String, CodingKey {
        case deviceUUID = "device_uuid"
        case platform, model
        case osVersion = "os_version"
        case appVersion = "app_version"
    }
}

enum DeviceIdentity {
    private static let defaultsKey = "ipca.scheduling.deviceUUID"

    static var payload: DevicePayload {
        let uuid: String
        if let existing = UserDefaults.standard.string(forKey: defaultsKey), !existing.isEmpty {
            uuid = existing
        } else {
            uuid = UUID().uuidString.lowercased()
            UserDefaults.standard.set(uuid, forKey: defaultsKey)
        }
        return DevicePayload(
            deviceUUID: uuid,
            platform: UIDevice.current.userInterfaceIdiom == .pad ? "ipad" : "iphone",
            model: UIDevice.current.model,
            osVersion: UIDevice.current.systemVersion,
            appVersion: Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String ?? "1.0"
        )
    }
}

enum SchedulerKeychain {
    static let service = "training.ipca.scheduling"
    static let tokenAccount = "sessionToken"

    static func token() -> String? {
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: tokenAccount,
            kSecReturnData as String: true,
            kSecMatchLimit as String: kSecMatchLimitOne
        ]
        var result: CFTypeRef?
        guard SecItemCopyMatching(query as CFDictionary, &result) == errSecSuccess,
              let data = result as? Data,
              let value = String(data: data, encoding: .utf8),
              !value.isEmpty else {
            return nil
        }
        return value
    }

    static func save(token: String) throws {
        let base: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: tokenAccount
        ]
        let attributes: [String: Any] = [kSecValueData as String: Data(token.utf8)]
        let status = SecItemUpdate(base as CFDictionary, attributes as CFDictionary)
        if status == errSecSuccess { return }
        guard status == errSecItemNotFound else { throw KeychainError.saveFailed }
        var add = base
        add.merge(attributes) { _, new in new }
        add[kSecAttrAccessible as String] = kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly
        guard SecItemAdd(add as CFDictionary, nil) == errSecSuccess else {
            throw KeychainError.saveFailed
        }
    }

    static func clear() {
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: tokenAccount
        ]
        SecItemDelete(query as CFDictionary)
    }

    enum KeychainError: LocalizedError {
        case saveFailed

        var errorDescription: String? { "Couldn't save this session securely." }
    }
}

protocol SessionTokenStoring {
    func token() -> String?
    func save(token: String) throws
    func clear()
}

struct KeychainSessionTokenStore: SessionTokenStoring {
    func token() -> String? { SchedulerKeychain.token() }
    func save(token: String) throws { try SchedulerKeychain.save(token: token) }
    func clear() { SchedulerKeychain.clear() }
}

actor ScheduleDiskCache {
    private let directory: URL
    private let encoder: JSONEncoder
    private let decoder: JSONDecoder

    init(directory: URL? = nil) {
        let base = directory
            ?? FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask).first!
        self.directory = base.appendingPathComponent("IPCAScheduling/ScheduleCache", isDirectory: true)
        encoder = JSONEncoder()
        encoder.dateEncodingStrategy = .iso8601
        decoder = JSONDecoder()
        decoder.dateDecodingStrategy = .iso8601
    }

    func load(userID: Int, start: String, end: String, filters: ScheduleFilters) -> CachedSchedule? {
        let url = fileURL(userID: userID, start: start, end: end, filters: filters)
        guard let data = try? Data(contentsOf: url) else { return nil }
        return try? decoder.decode(CachedSchedule.self, from: data)
    }

    func save(
        _ response: ScheduleRangeResponse,
        userID: Int,
        start: String,
        end: String,
        filters: ScheduleFilters,
        savedAt: Date = Date()
    ) {
        do {
            try FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
            let data = try encoder.encode(CachedSchedule(response: response, savedAt: savedAt))
            try data.write(
                to: fileURL(userID: userID, start: start, end: end, filters: filters),
                options: .atomic
            )
        } catch {
            // Cache failure must never prevent an authoritative online read.
        }
    }

    func clear() {
        try? FileManager.default.removeItem(at: directory)
    }

    private func fileURL(userID: Int, start: String, end: String, filters: ScheduleFilters) -> URL {
        let filterData = (try? JSONEncoder().encode(filters)) ?? Data()
        let filterHash = filterData.reduce(into: UInt64(5381)) { value, byte in
            value = ((value << 5) &+ value) &+ UInt64(byte)
        }
        return directory.appendingPathComponent("\(userID)-\(start)-\(end)-\(filterHash).json")
    }
}

@MainActor
final class ConnectivityMonitor: ObservableObject {
    @Published private(set) var isOnline = true
    private let monitor = NWPathMonitor()
    private let queue = DispatchQueue(label: "training.ipca.scheduling.network")

    init() {
        monitor.pathUpdateHandler = { [weak self] path in
            Task { @MainActor in self?.isOnline = path.status == .satisfied }
        }
        monitor.start(queue: queue)
    }

    deinit { monitor.cancel() }
}

struct SchedulerClock {
    let timezoneIdentifier: String

    var timezone: TimeZone {
        TimeZone(identifier: timezoneIdentifier) ?? TimeZone(identifier: "America/Los_Angeles")!
    }

    var calendar: Calendar {
        var calendar = Calendar(identifier: .gregorian)
        calendar.locale = Locale(identifier: "en_US_POSIX")
        calendar.timeZone = timezone
        calendar.firstWeekday = 2
        return calendar
    }

    func date(fromLocal value: String) -> Date? {
        let formatter = DateFormatter()
        formatter.calendar = calendar
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.timeZone = timezone
        formatter.dateFormat = value.contains(".") ? "yyyy-MM-dd'T'HH:mm:ss.SSS" : "yyyy-MM-dd'T'HH:mm:ss"
        formatter.isLenient = false
        return formatter.date(from: String(value.prefix(value.contains(".") ? 23 : 19)))
    }

    func dayKey(for date: Date) -> String {
        let formatter = DateFormatter()
        formatter.calendar = calendar
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.timeZone = timezone
        formatter.dateFormat = "yyyy-MM-dd"
        return formatter.string(from: date)
    }

    func date(fromDayKey key: String) -> Date? {
        let formatter = DateFormatter()
        formatter.calendar = calendar
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.timeZone = timezone
        formatter.dateFormat = "yyyy-MM-dd"
        formatter.isLenient = false
        return formatter.date(from: key)
    }

    func timeRange(start: String, end: String) -> String {
        "\(time(start)) – \(time(end))"
    }

    func time(_ local: String) -> String {
        guard local.count >= 16 else { return local }
        let hour = Int(local.dropFirst(11).prefix(2)) ?? 0
        let minute = String(local.dropFirst(14).prefix(2))
        let suffix = hour >= 12 ? "PM" : "AM"
        let twelveHour = hour % 12 == 0 ? 12 : hour % 12
        return "\(twelveHour):\(minute) \(suffix)"
    }

    func longDate(_ date: Date) -> String {
        formatted(date, format: "EEEE, MMMM d")
    }

    func monthTitle(_ date: Date) -> String {
        formatted(date, format: "MMMM yyyy")
    }

    func weekdayNarrow(_ date: Date) -> String { formatted(date, format: "EEEEE") }
    func weekdayLong(_ date: Date) -> String { formatted(date, format: "EEEE") }
    func dayNumber(_ date: Date) -> String { formatted(date, format: "d") }
    func monthDay(_ date: Date) -> String { formatted(date, format: "MMM d") }

    func relativeStart(_ local: String, now: Date) -> String? {
        guard let start = date(fromLocal: local), start > now else { return nil }
        let minutes = max(1, Int(start.timeIntervalSince(now) / 60))
        if minutes < 60 { return "Starts in \(minutes) min" }
        let hours = minutes / 60
        let remainder = minutes % 60
        return remainder == 0 ? "Starts in \(hours) hr" : "Starts in \(hours) hr \(remainder) min"
    }

    func startOfWeek(containing date: Date) -> Date {
        calendar.dateInterval(of: .weekOfYear, for: date)?.start ?? date
    }

    func week(containing date: Date) -> [Date] {
        let start = startOfWeek(containing: date)
        return (0 ..< 7).compactMap { calendar.date(byAdding: .day, value: $0, to: start) }
    }

    private func formatted(_ date: Date, format: String) -> String {
        let formatter = DateFormatter()
        formatter.calendar = calendar
        formatter.locale = Locale(identifier: "en_US")
        formatter.timeZone = timezone
        formatter.dateFormat = format
        return formatter.string(from: date)
    }
}

enum TodayOrganizer {
    static func sections(
        reservations: [SchedulerReservation],
        dayKey: String,
        now: Date,
        clock: SchedulerClock
    ) -> [(TodaySection, [SchedulerReservation])] {
        let day = reservations
            .filter { $0.localDateKey == dayKey && !$0.isCancelled }
            .sorted { $0.startLocal < $1.startLocal }
        let inProgress = day.filter(\.isInProgress)
        let completed = day.filter(\.isCompleted)
        let upcoming = day.filter {
            !$0.isInProgress && !$0.isCompleted
                && (clock.date(fromLocal: $0.startLocal).map { $0 >= now } ?? true)
        }
        var result: [(TodaySection, [SchedulerReservation])] = []
        if !inProgress.isEmpty { result.append((.inProgress, inProgress)) }
        if let next = upcoming.first { result.append((.next, [next])) }
        if upcoming.count > 1 { result.append((.later, Array(upcoming.dropFirst()))) }
        if !completed.isEmpty { result.append((.completed, completed)) }
        return result
    }
}
