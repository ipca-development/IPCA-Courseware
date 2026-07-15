import AppKit
import Foundation
import Network
import os
import Security
import ServiceManagement
import UserNotifications

final class SettingsStore: ObservableObject {
    @Published var apiBaseURL: String {
        didSet { UserDefaults.standard.set(apiBaseURL, forKey: "apiBaseURL") }
    }
    @Published var syncIntervalMinutes: Int {
        didSet { UserDefaults.standard.set(syncIntervalMinutes, forKey: "syncIntervalMinutes") }
    }
    @Published var retainUploadedArtifactsDays: Int {
        didSet { UserDefaults.standard.set(retainUploadedArtifactsDays, forKey: "retainUploadedArtifactsDays") }
    }
    @Published var openWindowAtLaunch: Bool {
        didSet { UserDefaults.standard.set(openWindowAtLaunch, forKey: "openWindowAtLaunch") }
    }
    @Published var notificationsEnabled: Bool {
        didSet { UserDefaults.standard.set(notificationsEnabled, forKey: "notificationsEnabled") }
    }

    init() {
        apiBaseURL = UserDefaults.standard.string(forKey: "apiBaseURL") ?? "https://ipca.training"
        syncIntervalMinutes = UserDefaults.standard.object(forKey: "syncIntervalMinutes") as? Int ?? 10
        retainUploadedArtifactsDays = UserDefaults.standard.object(forKey: "retainUploadedArtifactsDays") as? Int ?? 30
        openWindowAtLaunch = UserDefaults.standard.object(forKey: "openWindowAtLaunch") as? Bool ?? true
        notificationsEnabled = UserDefaults.standard.object(forKey: "notificationsEnabled") as? Bool ?? true
    }

    var validatedAPIBaseURL: URL? {
        guard let url = URL(string: apiBaseURL), url.scheme == "https" else { return nil }
        return url
    }
}

final class KeychainStore {
    private let service = "com.ipca.syncagent"
    private let account = "sync-agent-token"

    func saveToken(_ token: String) throws {
        let data = Data(token.utf8)
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: account
        ]
        SecItemDelete(query as CFDictionary)
        let add: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: account,
            kSecAttrAccessible as String: kSecAttrAccessibleWhenUnlockedThisDeviceOnly,
            kSecValueData as String: data
        ]
        let status = SecItemAdd(add as CFDictionary, nil)
        guard status == errSecSuccess else { throw NSError(domain: "KeychainStore", code: Int(status)) }
    }

    func loadToken() -> String? {
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: account,
            kSecReturnData as String: true,
            kSecMatchLimit as String: kSecMatchLimitOne
        ]
        var item: CFTypeRef?
        guard SecItemCopyMatching(query as CFDictionary, &item) == errSecSuccess,
              let data = item as? Data else { return nil }
        return String(data: data, encoding: .utf8)
    }

    func deleteToken() {
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: account
        ]
        SecItemDelete(query as CFDictionary)
    }
}

final class LoggingService {
    static let shared = LoggingService()
    private let logger = Logger(subsystem: "com.ipca.syncagent", category: "SyncAgent")
    private let logDirectory: URL
    private let fileURL: URL
    private let queue = DispatchQueue(label: "com.ipca.syncagent.logs")

    init() {
        let base = FileManager.default.urls(for: .libraryDirectory, in: .userDomainMask).first!
        logDirectory = base.appendingPathComponent("Logs/IPCA Sync Agent", isDirectory: true)
        try? FileManager.default.createDirectory(at: logDirectory, withIntermediateDirectories: true)
        fileURL = logDirectory.appendingPathComponent("sync-agent.log")
    }

    func info(_ message: String) {
        logger.info("\(message, privacy: .public)")
        append("INFO", message)
    }

    func error(_ message: String) {
        logger.error("\(message, privacy: .public)")
        append("ERROR", message)
    }

    func openLogs() {
        NSWorkspace.shared.open(logDirectory)
    }

    private func append(_ level: String, _ message: String) {
        let sanitized = message
            .replacingOccurrences(of: "Authorization", with: "[redacted-header]")
            .replacingOccurrences(of: "Bearer", with: "[redacted-token-prefix]")
        let line = "\(ISO8601DateFormatter().string(from: Date())) [\(level)] \(sanitized)\n"
        queue.async {
            if let data = line.data(using: .utf8) {
                if FileManager.default.fileExists(atPath: self.fileURL.path),
                   let handle = try? FileHandle(forWritingTo: self.fileURL) {
                    try? handle.seekToEnd()
                    try? handle.write(contentsOf: data)
                    try? handle.close()
                } else {
                    try? data.write(to: self.fileURL, options: .atomic)
                }
            }
        }
    }
}

final class NotificationService {
    func requestAuthorizationIfNeeded() {
        UNUserNotificationCenter.current().requestAuthorization(options: [.alert, .sound]) { _, _ in }
    }

    func notify(title: String, body: String) {
        let content = UNMutableNotificationContent()
        content.title = title
        content.body = body
        let request = UNNotificationRequest(identifier: UUID().uuidString, content: content, trigger: nil)
        UNUserNotificationCenter.current().add(request)
    }
}

final class LaunchAtLoginService: ObservableObject {
    @Published var isEnabled: Bool = SMAppService.mainApp.status == .enabled

    func refresh() {
        isEnabled = SMAppService.mainApp.status == .enabled
    }

    func setEnabled(_ enabled: Bool) throws {
        if enabled {
            if SMAppService.mainApp.status != .enabled {
                try SMAppService.mainApp.register()
            }
        } else {
            if SMAppService.mainApp.status == .enabled {
                try SMAppService.mainApp.unregister()
            }
        }
        refresh()
    }
}

final class NetworkMonitor: ObservableObject {
    @Published private(set) var isOnline = true
    private let monitor = NWPathMonitor()
    private let queue = DispatchQueue(label: "com.ipca.syncagent.network")

    init() {
        monitor.pathUpdateHandler = { [weak self] path in
            DispatchQueue.main.async {
                self?.isOnline = path.status == .satisfied
            }
        }
        monitor.start(queue: queue)
    }
}
