import Combine
import Foundation
import Network
import SwiftUI
import UIKit
import UserNotifications

@MainActor
final class AppSession: ObservableObject {
    @Published var isAuthenticated = false
    @Published var user: PublicUser?
    @Published var capabilities = ServerCapabilities.disabled
    @Published var loginError: String?
    @Published var actionError: String?
    @Published var isLoggingIn = false
    @Published var updateRequired = false
    @Published var selectedConversationUUID: String?
    @Published var pendingConversationUUID: String?
    @Published var people: [PublicUser] = []

    let persistence: PersistenceController
    let store: StoreWriter
    private var api: APIClient
    private var outbox: OutboxWorker
    private let syncGate = SyncGenerationGate()
    private var syncTask: Task<Void, Never>?
    private var outboxTask: Task<Void, Never>?
    private let monitor = NWPathMonitor()

    private let tokenAccount = "sessionToken"
    private let serverDefaultsKey = "ipca.app.serverURL"
    private let userDefaultsKey = "ipca.app.userJSON"

    init(persistence: PersistenceController = .shared) {
        self.persistence = persistence
        self.store = StoreWriter(context: persistence.newBackgroundContext())
        let url = Self.storedServerURL()
        self.api = APIClient(baseURL: url)
        self.outbox = OutboxWorker(store: store, api: api)
        monitor.pathUpdateHandler = { [weak self] path in
            guard path.status == .satisfied else { return }
            Task { @MainActor in
                await self?.outbox.drain()
                await self?.syncNow()
            }
        }
        monitor.start(queue: DispatchQueue(label: "ipca.network"))
    }

    var serverURLString: String {
        get { UserDefaults.standard.string(forKey: serverDefaultsKey) ?? "https://courseware.europilotcenter.com" }
        set { UserDefaults.standard.set(newValue, forKey: serverDefaultsKey) }
    }

    func restoreIfPossible() async {
        guard let token = KeychainStore.string(for: tokenAccount) else { return }
        await api.setToken(token)
        do {
            let bootstrap = try await api.bootstrap()
            await applyBootstrap(bootstrap, token: token)
            await requestPushAuthorization()
            await startLoops()
        } catch let error as APIClientError {
            if error.httpStatus == 401 || error.errorCode == "account_ineligible" || error.errorCode == "credential_revoked" {
                clearSession()
            }
        } catch {
            if let data = UserDefaults.standard.data(forKey: userDefaultsKey),
               let user = try? JSONDecoder().decode(PublicUser.self, from: data) {
                self.user = user
                self.isAuthenticated = true
                await startLoops()
            }
        }
    }

    func login(email: String, password: String, serverURL: String, startBackgroundLoops: Bool = true) async {
        loginError = nil
        isLoggingIn = true
        defer { isLoggingIn = false }
        guard let url = URL(string: serverURL.trimmingCharacters(in: CharacterSet(charactersIn: "/"))) else {
            loginError = "Enter a valid server address."
            return
        }
        serverURLString = url.absoluteString
        await api.setBaseURL(url)
        do {
            let response = try await api.login(email: email.trimmingCharacters(in: .whitespacesAndNewlines), password: password)
            try KeychainStore.setString(response.token, for: tokenAccount)
            await applyLogin(response)
            await requestPushAuthorization()
            if startBackgroundLoops {
                await startLoops()
            }
        } catch let error as APIClientError {
            loginError = error.errorDescription
        } catch {
            loginError = error.localizedDescription.isEmpty ? "Couldn't sign in. Try again." : error.localizedDescription
        }
    }

    func enqueueOnly(conversationUUID: String, body: String) async -> String {
        let trimmed = body.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty, let user else { return "" }
        return await store.enqueueSend(conversationUUID: conversationUUID, body: trimmed, senderUUID: user.uuid)
    }

    func logout() async {
        try? await api.logout()
        stopLoops()
        clearSession()
    }

    func send(conversationUUID: String, body: String) async {
        let trimmed = body.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty, let user else { return }
        _ = await store.enqueueSend(conversationUUID: conversationUUID, body: trimmed, senderUUID: user.uuid)
        await outbox.drain()
    }

    func retry(clientID: String) async {
        await store.retryFailed(clientID: clientID)
        await outbox.drain()
    }

    func openDirect(with person: PublicUser) async -> Bool {
        actionError = nil
        do {
            let conversation = try await api.createDirect(peerUserUUID: person.uuid)
            _ = await store.applySync(
                SyncResponse(ok: true, cursor: 0, hasMore: false, conversations: [conversation], messages: [], reads: []),
                currentUserUUID: user?.uuid ?? "",
                generation: syncGate.begin(),
                gate: syncGate,
                advancesCursor: false
            )
            selectedConversationUUID = conversation.conversationUUID
            return true
        } catch let error as APIClientError {
            actionError = error.errorDescription ?? "Couldn't start that conversation."
            return false
        } catch {
            actionError = "Couldn't start that conversation."
            return false
        }
    }

    func createGroup(title: String, members: [PublicUser]) async -> Bool {
        actionError = nil
        do {
            let conversation = try await api.createGroup(title: title, memberUUIDs: members.map(\.uuid))
            _ = await store.applySync(
                SyncResponse(ok: true, cursor: 0, hasMore: false, conversations: [conversation], messages: [], reads: []),
                currentUserUUID: user?.uuid ?? "",
                generation: syncGate.begin(),
                gate: syncGate,
                advancesCursor: false
            )
            selectedConversationUUID = conversation.conversationUUID
            return true
        } catch let error as APIClientError {
            actionError = error.errorDescription ?? "Couldn't create that group."
            return false
        } catch {
            actionError = "Couldn't create that group."
            return false
        }
    }

    func searchPeople(_ query: String) async {
        do {
            people = try await api.directory(query: query)
        } catch {
            people = []
        }
    }

    func markRead(conversationUUID: String, seq: Int) async {
        do {
            try await api.markRead(conversationUUID: conversationUUID, lastReadSeq: seq)
        } catch {
            // Read receipts retry on the next open; not user-facing.
        }
        await refreshBadge()
    }

    func requestPushAuthorization() async {
        guard isAuthenticated else { return }
        do {
            let granted = try await UNUserNotificationCenter.current().requestAuthorization(options: [.alert, .badge, .sound])
            await MainActor.run {
                UIApplication.shared.registerForRemoteNotifications()
            }
            if !granted {
                await registerPush(token: nil, authorized: false)
            }
        } catch {
            await registerPush(token: nil, authorized: false)
        }
    }

    func registerPush(token: String?, authorized: Bool) async {
        guard isAuthenticated else { return }
        do {
            try await api.registerDevice(apnsToken: token, authorized: authorized)
        } catch {
            // Token registration retries on the next launch or login.
        }
    }

    func handleOpenURL(_ url: URL) {
        guard url.scheme == "ipca", url.host == "c" else { return }
        let uuid = url.pathComponents.dropFirst().first
        guard let uuid, !uuid.isEmpty else { return }
        openConversationFromNotification(uuid)
    }

    func openConversationFromNotification(_ conversationUUID: String) {
        selectedConversationUUID = conversationUUID
        pendingConversationUUID = conversationUUID
    }

    func refreshBadge() async {
        let total = await store.unreadTotal()
        try? await UNUserNotificationCenter.current().setBadgeCount(total)
    }

    func syncNow() async {
        guard isAuthenticated else { return }
        let token = syncGate.begin()
        do {
            var cursor = await store.syncCursor()
            var keepGoing = true
            while keepGoing {
                let response = try await api.sync(cursor: cursor)
                if let applied = await store.applySync(response, currentUserUUID: user?.uuid ?? "", generation: token, gate: syncGate) {
                    cursor = applied
                    await store.setSyncCursor(cursor)
                    keepGoing = response.hasMore
                } else {
                    keepGoing = false
                }
            }
            await refreshBadge()
        } catch let error as APIClientError {
            if error.httpStatus == 401 || error.errorCode == "account_ineligible" {
                clearSession()
            }
        } catch {
            // Stay on cached data.
        }
    }

    private func startLoops() async {
        stopLoops()
        await outbox.start()
        await syncNow()
        syncTask = Task { [weak self] in
            while let self, !Task.isCancelled {
                try? await Task.sleep(for: .seconds(3))
                await self.syncNow()
            }
        }
        outboxTask = Task { [weak self] in
            while let self, !Task.isCancelled {
                try? await Task.sleep(for: .seconds(2))
                await self.outbox.drain()
            }
        }
    }

    private func stopLoops() {
        syncTask?.cancel()
        outboxTask?.cancel()
        syncTask = nil
        outboxTask = nil
    }

    private func applyLogin(_ response: LoginResponse) async {
        user = response.user
        capabilities = response.capabilities
        isAuthenticated = true
        persistUser(response.user)
        await api.setToken(response.token)
    }

    private func applyBootstrap(_ response: BootstrapResponse, token: String) async {
        user = response.user
        capabilities = response.capabilities
        updateRequired = response.updateRequired
        isAuthenticated = true
        persistUser(response.user)
        await api.setToken(token)
    }

    private func persistUser(_ user: PublicUser) {
        if let data = try? JSONEncoder().encode(user) {
            UserDefaults.standard.set(data, forKey: userDefaultsKey)
        }
    }

    private func clearSession() {
        KeychainStore.delete(account: tokenAccount)
        UserDefaults.standard.removeObject(forKey: userDefaultsKey)
        user = nil
        isAuthenticated = false
        capabilities = .disabled
        selectedConversationUUID = nil
        pendingConversationUUID = nil
        actionError = nil
        people = []
        Task { try? await UNUserNotificationCenter.current().setBadgeCount(0) }
        Task { await api.setToken(nil) }
    }

    private static func storedServerURL() -> URL {
        let value = UserDefaults.standard.string(forKey: "ipca.app.serverURL") ?? "https://courseware.europilotcenter.com"
        return URL(string: value) ?? URL(string: "https://courseware.europilotcenter.com")!
    }
}
