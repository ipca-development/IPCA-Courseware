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
    @Published var pendingActions = false
    @Published var showingActions = false
    @Published var needsActionCount = 0
    @Published var people: [PublicUser] = []
    @Published var selectedTab: AppTab = .messages
    @Published var pendingCommunityPostUUID: String?
    @Published var isOnline = true
    @Published var showingPushPrimer = false
    @Published var lastSyncAt: Date?
    @Published var notificationsAuthorized = false
    @Published var hidesTabBar = false

    let persistence: PersistenceController
    let store: StoreWriter
    private var api: APIClient
    private var outbox: OutboxWorker
    private let syncGate = SyncGenerationGate()
    private var syncTask: Task<Void, Never>?
    private var outboxTask: Task<Void, Never>?
    private let monitor = NWPathMonitor()
    private var syncBackoffSeconds: Double = 3

    private let tokenAccount = "sessionToken"
    private let serverDefaultsKey = "ipca.app.serverURL"
    private let userDefaultsKey = "ipca.app.userJSON"
    private let pushPrimerKey = "ipca.app.pushPrimerDone"
    static let productionServerURL = "https://ipca.training"

    init(persistence: PersistenceController = .shared) {
        self.persistence = persistence
        self.store = StoreWriter(context: persistence.newBackgroundContext())
        Self.migrateLegacyServerURL()
        let url = Self.storedServerURL()
        self.api = APIClient(baseURL: url)
        self.outbox = OutboxWorker(store: store, api: api)
        monitor.pathUpdateHandler = { [weak self] path in
            let online = path.status == .satisfied
            Task { @MainActor in
                guard let self else { return }
                let wasOffline = !self.isOnline
                self.isOnline = online
                guard online else { return }
                if wasOffline {
                    self.syncBackoffSeconds = 3
                }
                await self.outbox.drain()
                await self.syncNow()
            }
        }
        monitor.start(queue: DispatchQueue(label: "ipca.network"))
    }

    var serverURLString: String {
        get { UserDefaults.standard.string(forKey: serverDefaultsKey) ?? Self.productionServerURL }
        set { UserDefaults.standard.set(newValue, forKey: serverDefaultsKey) }
    }

    func restoreIfPossible() async {
        guard let token = KeychainStore.string(for: tokenAccount) else { return }
        await api.setToken(token)
        do {
            let bootstrap = try await api.bootstrap()
            await applyBootstrap(bootstrap, token: token)
            await preparePush()
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
                await preparePush()
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
            await preparePush()
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

    func send(conversationUUID: String, body: String, attachments: [PendingAttachment] = [], replyTo: ReplyToDTO? = nil) async {
        let trimmed = body.trimmingCharacters(in: .whitespacesAndNewlines)
        guard (!trimmed.isEmpty || !attachments.isEmpty), let user else { return }
        _ = await store.enqueueSend(
            conversationUUID: conversationUUID,
            body: trimmed,
            senderUUID: user.uuid,
            attachments: attachments,
            replyTo: replyTo
        )
        await outbox.drain()
    }

    func react(messageUUID: String, emoji: String) async {
        actionError = nil
        do {
            let dto = try await api.react(messageUUID: messageUUID, emoji: emoji)
            await store.applyMessage(dto, currentUserUUID: user?.uuid ?? "")
        } catch let error as APIClientError {
            actionError = error.errorDescription ?? "Couldn't save that reaction."
        } catch {
            actionError = "Couldn't save that reaction."
        }
    }

    func downloadURL(for attachmentUUID: String) async -> URL? {
        do {
            return try await api.attachmentDownloadURL(attachmentUUID)
        } catch {
            return nil
        }
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

    func acknowledge(messageUUID: String) async {
        actionError = nil
        do {
            let ack = try await api.acknowledge(
                messageUUID: messageUUID,
                acknowledgementUUID: UUID().uuidString.lowercased()
            )
            await store.markAcknowledged(messageUUID: ack.messageUUID)
            await refreshNeedsAction()
        } catch let error as APIClientError {
            actionError = error.errorDescription ?? "Couldn't acknowledge that message."
        } catch {
            actionError = "Couldn't acknowledge that message."
        }
    }

    func loadActions() async -> [ActionItemDTO] {
        do {
            let envelope = try await api.actions()
            needsActionCount = envelope.needsActionCount
            return envelope.actions
        } catch {
            return []
        }
    }

    func loadTraining() async -> TrainingSummaryDTO? {
        do {
            return try await api.training()
        } catch {
            return nil
        }
    }

    func loadTrainingVideoFeed(cursor: Int = 0) async -> TrainingVideoFeedDTO? {
        do {
            return try await api.trainingVideoFeed(cursor: cursor)
        } catch {
            return nil
        }
    }

    func loadTrainingVideo(_ videoUUID: String) async -> TrainingVideoDTO? {
        do {
            return try await api.trainingVideo(videoUUID)
        } catch {
            return nil
        }
    }

    func loadTrainingVideoPlayback(_ videoUUID: String, download: Bool = false) async -> TrainingVideoPlaybackDTO? {
        actionError = nil
        do {
            return try await api.trainingVideoPlayback(videoUUID, download: download)
        } catch let error as APIClientError {
            actionError = error.errorDescription ?? "Couldn't play that video."
            return nil
        } catch {
            actionError = "Couldn't play that video."
            return nil
        }
    }

    func loadTrainingVideoComments(_ videoUUID: String) async -> [TrainingVideoCommentDTO] {
        do {
            return try await api.trainingVideoComments(videoUUID)
        } catch {
            return []
        }
    }

    func recordTrainingVideoView(_ videoUUID: String) async -> TrainingVideoDTO? {
        do {
            return try await api.trainingVideoView(videoUUID)
        } catch {
            return nil
        }
    }

    func toggleTrainingVideoLike(_ video: TrainingVideoDTO) async -> TrainingVideoDTO? {
        actionError = nil
        do {
            return try await (video.liked ? api.trainingVideoUnlike(video.videoUUID) : api.trainingVideoLike(video.videoUUID))
        } catch let error as APIClientError {
            actionError = error.errorDescription ?? "Couldn't update that like."
            return nil
        } catch {
            actionError = "Couldn't update that like."
            return nil
        }
    }

    func commentOnTrainingVideo(_ videoUUID: String, body: String) async -> TrainingVideoCommentDTO? {
        actionError = nil
        do {
            return try await api.trainingVideoComment(
                videoUUID: videoUUID,
                body: body,
                commentUUID: UUID().uuidString.lowercased()
            )
        } catch let error as APIClientError {
            actionError = error.errorDescription ?? "Couldn't post that comment."
            return nil
        } catch {
            actionError = "Couldn't post that comment."
            return nil
        }
    }

    func loadCommunityFeed(cursor: Int = 0) async -> CommunityFeedDTO? {
        do {
            return try await api.communityFeed(cursor: cursor)
        } catch {
            return nil
        }
    }

    func loadCommunityPost(_ postUUID: String) async -> CommunityPostDTO? {
        do {
            return try await api.communityPost(postUUID).post
        } catch {
            return nil
        }
    }

    func loadCommunityComments(_ postUUID: String) async -> [CommunityCommentDTO] {
        do {
            return try await api.communityComments(postUUID)
        } catch {
            return []
        }
    }

    func toggleCommunityLike(_ post: CommunityPostDTO) async -> CommunityPostDTO? {
        actionError = nil
        do {
            return try await (post.liked ? api.communityUnlike(post.postUUID) : api.communityLike(post.postUUID))
        } catch let error as APIClientError {
            actionError = error.errorDescription ?? "Couldn't update that like."
            return nil
        } catch {
            actionError = "Couldn't update that like."
            return nil
        }
    }

    func commentOnCommunityPost(_ postUUID: String, body: String) async -> CommunityCommentDTO? {
        actionError = nil
        do {
            return try await api.communityComment(
                postUUID: postUUID,
                body: body,
                commentUUID: UUID().uuidString.lowercased()
            )
        } catch let error as APIClientError {
            actionError = error.errorDescription ?? "Couldn't post that comment."
            return nil
        } catch {
            actionError = "Couldn't post that comment."
            return nil
        }
    }

    func deleteCommunityPost(_ postUUID: String) async -> Bool {
        actionError = nil
        do {
            try await api.communityDelete(postUUID)
            return true
        } catch let error as APIClientError {
            actionError = error.errorDescription ?? "Couldn't delete that post."
            return false
        } catch {
            actionError = "Couldn't delete that post."
            return false
        }
    }

    func reportCommunityPost(_ postUUID: String, reason: String, details: String = "") async -> Bool {
        actionError = nil
        do {
            _ = try await api.communityReport(postUUID: postUUID, reason: reason, details: details)
            return true
        } catch let error as APIClientError {
            actionError = error.errorDescription ?? "Couldn't report that post."
            return false
        } catch {
            actionError = "Couldn't report that post."
            return false
        }
    }

    func publishCommunityPost(caption: String, body: String, data: Data, filename: String, mimeType: String, durationMs: Int, poster: Data? = nil) async -> CommunityPostDTO? {
        actionError = nil
        do {
            let mediaUUID = UUID().uuidString.lowercased()
            let presign = try await api.communityPresign(
                mediaUUID: mediaUUID,
                filename: filename,
                mimeType: mimeType,
                byteSize: data.count,
                durationMs: durationMs
            )
            guard let putURL = URL(string: presign.putURL) else {
                actionError = "Couldn't upload that photo."
                return nil
            }
            try await api.uploadPresigned(url: putURL, data: data, contentType: mimeType, extraHeaders: presign.headers)
            if mimeType.hasPrefix("video/"),
               let poster,
               !poster.isEmpty,
               let posterURLString = presign.posterPutURL,
               let posterURL = URL(string: posterURLString) {
                try await api.uploadPresigned(
                    url: posterURL,
                    data: poster,
                    contentType: "image/jpeg",
                    extraHeaders: presign.posterHeaders.isEmpty ? presign.headers : presign.posterHeaders
                )
            }
            try await api.communityComplete(mediaUUID: mediaUUID)
            return try await api.communityCreate(
                caption: caption,
                body: body,
                mediaUUIDs: [mediaUUID],
                postUUID: UUID().uuidString.lowercased()
            )
        } catch let error as APIClientError {
            actionError = error.errorDescription ?? "Couldn't share that post."
            return nil
        } catch {
            actionError = "Couldn't share that post."
            return nil
        }
    }

    func openActions() {
        showingActions = true
        selectedConversationUUID = nil
        pendingActions = true
    }

    func requestPushAuthorization() async {
        guard isAuthenticated else { return }
        do {
            let granted = try await UNUserNotificationCenter.current().requestAuthorization(options: [.alert, .badge, .sound])
            notificationsAuthorized = granted
            await MainActor.run {
                UIApplication.shared.registerForRemoteNotifications()
            }
            if !granted {
                await registerPush(token: nil, authorized: false)
            }
        } catch {
            notificationsAuthorized = false
            await registerPush(token: nil, authorized: false)
        }
    }

    func preparePush() async {
        let settings = await UNUserNotificationCenter.current().notificationSettings()
        notificationsAuthorized = settings.authorizationStatus == .authorized || settings.authorizationStatus == .provisional
        if UserDefaults.standard.bool(forKey: pushPrimerKey) {
            await requestPushAuthorization()
            return
        }
        if settings.authorizationStatus == .notDetermined {
            showingPushPrimer = true
            return
        }
        UserDefaults.standard.set(true, forKey: pushPrimerKey)
        await requestPushAuthorization()
    }

    func enablePushFromPrimer() async {
        UserDefaults.standard.set(true, forKey: pushPrimerKey)
        showingPushPrimer = false
        await requestPushAuthorization()
    }

    func skipPushPrimer() async {
        UserDefaults.standard.set(true, forKey: pushPrimerKey)
        showingPushPrimer = false
        notificationsAuthorized = false
        await registerPush(token: nil, authorized: false)
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
        guard url.scheme == "ipca" else { return }
        if url.host == "actions" {
            selectedTab = .messages
            openActions()
            return
        }
        if url.host == "community" {
            let uuid = url.pathComponents.dropFirst().first
            guard let uuid, !uuid.isEmpty else { return }
            openCommunityPost(uuid)
            return
        }
        guard url.host == "c" else { return }
        let uuid = url.pathComponents.dropFirst().first
        guard let uuid, !uuid.isEmpty else { return }
        openConversationFromNotification(uuid)
    }

    func openConversationFromNotification(_ conversationUUID: String) {
        selectedTab = .messages
        showingActions = false
        selectedConversationUUID = conversationUUID
        pendingConversationUUID = conversationUUID
    }

    func openCommunityPost(_ postUUID: String) {
        selectedTab = .community
        pendingCommunityPostUUID = postUUID
    }

    func refreshBadge() async {
        let total = await store.unreadTotal()
        try? await UNUserNotificationCenter.current().setBadgeCount(total)
        await refreshNeedsAction()
    }

    func refreshNeedsAction() async {
        needsActionCount = await store.pendingActionCount()
    }

    func syncNow() async {
        guard isAuthenticated, isOnline else { return }
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
            lastSyncAt = Date()
            syncBackoffSeconds = 3
            await refreshBadge()
            await refreshNeedsAction()
        } catch let error as APIClientError {
            if error.httpStatus == 401 || error.errorCode == "account_ineligible" {
                clearSession()
            } else {
                syncBackoffSeconds = min(30, max(syncBackoffSeconds, 3) * 2)
            }
        } catch {
            syncBackoffSeconds = min(30, max(syncBackoffSeconds, 3) * 2)
        }
    }

    private func startLoops() async {
        stopLoops()
        await outbox.start()
        await syncNow()
        syncTask = Task { [weak self] in
            while let self, !Task.isCancelled {
                let delay = self.isOnline ? self.syncBackoffSeconds : 8
                try? await Task.sleep(for: .seconds(delay))
                if self.isOnline {
                    await self.syncNow()
                }
            }
        }
        outboxTask = Task { [weak self] in
            while let self, !Task.isCancelled {
                try? await Task.sleep(for: .seconds(self.isOnline ? 2 : 8))
                if self.isOnline {
                    await self.outbox.drain()
                }
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
        needsActionCount = response.needsActionCount
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
        pendingActions = false
        showingActions = false
        needsActionCount = 0
        actionError = nil
        people = []
        selectedTab = .messages
        pendingCommunityPostUUID = nil
        showingPushPrimer = false
        lastSyncAt = nil
        notificationsAuthorized = false
        hidesTabBar = false
        syncBackoffSeconds = 3
        Task { try? await UNUserNotificationCenter.current().setBadgeCount(0) }
        Task { await api.setToken(nil) }
    }

    private static func storedServerURL() -> URL {
        let value = UserDefaults.standard.string(forKey: "ipca.app.serverURL") ?? productionServerURL
        return URL(string: value) ?? URL(string: productionServerURL)!
    }

    private static func migrateLegacyServerURL() {
        let key = "ipca.app.serverURL"
        let stored = UserDefaults.standard.string(forKey: key)
        if stored == nil || stored == "https://courseware.europilotcenter.com" {
            UserDefaults.standard.set(productionServerURL, forKey: key)
        }
    }
}
