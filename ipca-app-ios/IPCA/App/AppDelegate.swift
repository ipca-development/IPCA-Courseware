import UIKit
import UserNotifications

final class IPCAAppDelegate: NSObject, UIApplicationDelegate, UNUserNotificationCenterDelegate {
    weak var session: AppSession? {
        didSet { flushPending() }
    }

    private var pendingToken: String?
    private var pendingConversationUUID: String?
    private var pendingCommunityPostUUID: String?
    private var pendingSafetyReportUUID: String?
    private var pendingRemoteSessionCodeID: String?

    func application(
        _ application: UIApplication,
        didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]? = nil
    ) -> Bool {
        UNUserNotificationCenter.current().delegate = self
        if let info = launchOptions?[.remoteNotification] as? [AnyHashable: Any] {
            if let codeID = Self.remoteSessionCodeID(from: info) {
                pendingRemoteSessionCodeID = codeID
            } else {
                pendingConversationUUID = Self.conversationUUID(from: info)
                pendingCommunityPostUUID = Self.communityPostUUID(from: info)
                pendingSafetyReportUUID = Self.safetyReportUUID(from: info)
            }
        }
        return true
    }

    func application(_ application: UIApplication, didRegisterForRemoteNotificationsWithDeviceToken deviceToken: Data) {
        let token = deviceToken.map { String(format: "%02x", $0) }.joined()
        pendingToken = token
        flushPending()
    }

    func application(_ application: UIApplication, didFailToRegisterForRemoteNotificationsWithError error: Error) {
        Task { @MainActor in
            await session?.registerPush(token: nil, authorized: false)
        }
    }

    func userNotificationCenter(
        _ center: UNUserNotificationCenter,
        willPresent notification: UNNotification,
        withCompletionHandler completionHandler: @escaping (UNNotificationPresentationOptions) -> Void
    ) {
        let info = notification.request.content.userInfo
        if let codeID = Self.remoteSessionCodeID(from: info) {
            Task { @MainActor in
                session?.openRemoteSessionCode(codeID)
            }
            completionHandler([.sound, .badge])
            return
        }
        let uuid = Self.conversationUUID(from: info)
        Task { @MainActor in
            await session?.syncNow()
            await session?.refreshBadge()
        }
        if let uuid, uuid == session?.selectedConversationUUID {
            completionHandler([.badge])
        } else {
            completionHandler([.banner, .sound, .badge])
        }
    }

    func userNotificationCenter(
        _ center: UNUserNotificationCenter,
        didReceive response: UNNotificationResponse,
        withCompletionHandler completionHandler: @escaping () -> Void
    ) {
        let info = response.notification.request.content.userInfo
        if let codeID = Self.remoteSessionCodeID(from: info) {
            pendingRemoteSessionCodeID = codeID
            flushPending()
        } else if let uuid = Self.safetyReportUUID(from: info) {
            pendingSafetyReportUUID = uuid
            flushPending()
        } else if let uuid = Self.communityPostUUID(from: response.notification.request.content.userInfo) {
            pendingCommunityPostUUID = uuid
            flushPending()
        } else if let uuid = Self.conversationUUID(from: response.notification.request.content.userInfo) {
            pendingConversationUUID = uuid
            flushPending()
        }
        Task { @MainActor in
            await session?.syncNow()
        }
        completionHandler()
    }

    private func flushPending() {
        guard let session else { return }
        if let token = pendingToken {
            pendingToken = nil
            Task { @MainActor in
                await session.registerPush(token: token, authorized: true)
            }
        }
        if let uuid = pendingConversationUUID {
            pendingConversationUUID = nil
            Task { @MainActor in
                session.openConversationFromNotification(uuid)
                await session.syncNow()
            }
        }
        if let uuid = pendingCommunityPostUUID {
            pendingCommunityPostUUID = nil
            Task { @MainActor in
                session.openCommunityPost(uuid)
            }
        }
        if let uuid = pendingSafetyReportUUID {
            pendingSafetyReportUUID = nil
            Task { @MainActor in
                session.openSafetyReport(uuid)
            }
        }
        if let codeID = pendingRemoteSessionCodeID {
            pendingRemoteSessionCodeID = nil
            Task { @MainActor in
                session.openRemoteSessionCode(codeID)
            }
        }
    }

    static func conversationUUID(from userInfo: [AnyHashable: Any]) -> String? {
        if let value = userInfo["conversation_uuid"] as? String, !value.isEmpty {
            return value
        }
        return nil
    }

    static func communityPostUUID(from userInfo: [AnyHashable: Any]) -> String? {
        if let value = userInfo["community_post_uuid"] as? String, !value.isEmpty {
            return value
        }
        return nil
    }

    static func safetyReportUUID(from userInfo: [AnyHashable: Any]) -> String? {
        if let value = userInfo["safety_report_uuid"] as? String, !value.isEmpty {
            return value
        }
        if let value = userInfo["report_uuid"] as? String,
           (userInfo["type"] as? String)?.hasPrefix("safety") == true,
           !value.isEmpty {
            return value
        }
        return nil
    }

    static func remoteSessionCodeID(from userInfo: [AnyHashable: Any]) -> String? {
        let type = (userInfo["type"] as? String) ?? ""
        guard type == "remote_session_code" else { return nil }
        if let value = userInfo["code_id"] as? String, !value.isEmpty {
            return value
        }
        return nil
    }
}
