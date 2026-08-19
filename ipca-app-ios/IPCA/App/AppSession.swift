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
    @Published var pendingSafetyReportUUID: String?
    @Published var isOnline = true
    @Published var showingPushPrimer = false
    @Published var lastSyncAt: Date?
    @Published var notificationsAuthorized = false
    @Published var hidesTabBar = false
    @Published var showingForgotPassword = false
    @Published var showingPasswordReset = false
    @Published var pendingResetToken = ""
    @Published var showingRemoteSessionCode = false
    @Published var pendingRemoteSessionCodeID = ""

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
                presentPendingRemoteSessionCodeIfNeeded()
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

    func loadProfile() async throws -> ProfileEnvelope {
        try await api.profile()
    }

    func savePersonalProfile(_ profile: ProfileDetails) async throws {
        let envelope = try await api.savePersonalProfile(profile)
        applyProfileUser(envelope.user)
    }

    func saveEmergencyContacts(_ contacts: [EmergencyContact]) async throws {
        let envelope = try await api.saveEmergencyContacts(contacts)
        applyProfileUser(envelope.user)
    }

    func changeAccountPassword(current: String, new: String, confirm: String) async throws {
        try await api.changePassword(current: current, new: new, confirm: confirm)
    }

    func uploadProfilePhoto(data: Data, mimeType: String) async throws {
        let envelope = try await api.uploadProfilePhoto(data: data, mimeType: mimeType)
        applyProfileUser(envelope.user)
    }

    func requestPasswordReset(email: String) async throws -> String {
        let response = try await api.forgotPassword(email: email)
        return response.message ?? "If an account with that email exists, a password reset link has been sent."
    }

    func validateResetToken(_ token: String) async throws -> PasswordResetEnvelope {
        try await api.validateResetToken(token)
    }

    func completePasswordReset(token: String, password: String, confirm: String) async throws -> String {
        let response = try await api.resetPassword(token: token, password: password, confirm: confirm)
        pendingResetToken = ""
        return response.message ?? "Your password has been reset successfully. You can now sign in with your new password."
    }

    private func applyProfileUser(_ user: PublicUser) {
        self.user = user
        persistUser(user)
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

    func updateGroupMembers(conversationUUID: String, add: [PublicUser] = [], removeUUIDs: [String] = []) async -> Bool {
        actionError = nil
        do {
            let conversation = try await api.updateGroupMembers(
                conversationUUID: conversationUUID,
                addUserUUIDs: add.map(\.uuid),
                removeUserUUIDs: removeUUIDs
            )
            _ = await store.applySync(
                SyncResponse(ok: true, cursor: 0, hasMore: false, conversations: [conversation], messages: [], reads: []),
                currentUserUUID: user?.uuid ?? "",
                generation: syncGate.begin(),
                gate: syncGate,
                advancesCursor: false
            )
            if !conversation.members.contains(where: { $0.user.uuid == user?.uuid }) {
                selectedConversationUUID = nil
            }
            return true
        } catch let error as APIClientError {
            actionError = error.errorDescription ?? "Couldn't update the group."
            return false
        } catch {
            actionError = "Couldn't update the group."
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

    func reportTrainingVideoProgress(videoUUID: String, positionMs: Int, durationMs: Int) async -> TrainingVideoDTO? {
        do {
            let updated = try await api.trainingVideoProgress(
                videoUUID: videoUUID,
                positionMs: positionMs,
                durationMs: durationMs
            )
            TrainingVideoWatchStore.shared.remove(videoUUID: videoUUID, ownerUserUUID: user?.uuid ?? "")
            return updated
        } catch {
            TrainingVideoWatchStore.shared.queue(
                videoUUID: videoUUID,
                positionMs: positionMs,
                durationMs: durationMs,
                ownerUserUUID: user?.uuid ?? ""
            )
            return nil
        }
    }

    func flushTrainingVideoProgress() async {
        let owner = user?.uuid ?? ""
        for item in TrainingVideoWatchStore.shared.pending(ownerUserUUID: owner) {
            _ = await reportTrainingVideoProgress(
                videoUUID: item.videoUUID,
                positionMs: item.positionMs,
                durationMs: item.durationMs
            )
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

    func loadSafetyReports() async throws -> [SafetyReportDTO] {
        try await api.safetyReports()
    }

    func loadSafetyFlightCandidates(eventAt: Date) async throws -> [SafetyFlightCandidateDTO] {
        try await api.safetyFlightCandidates(
            eventAtUTC: ISO8601DateFormatter().string(from: eventAt)
        )
    }

    func loadSafetyReport(_ reportUUID: String) async throws -> SafetyReportDTO {
        try await api.safetyReport(reportUUID)
    }

    func createAndSubmitSafetyReport(
        _ input: SafetyReportInput,
        attachments: [SafetyDraftAttachment] = []
    ) async throws -> SafetyReportDTO {
        guard isOnline else {
            throw SafetySubmissionError.offline
        }
        guard let userUUID = user?.uuid, !userUUID.isEmpty else {
            throw SafetySubmissionError.missingIdentity
        }
        var state = IdentifiedSafetyDraftStore.loadSubmission(userUUID: userUUID)
            ?? SafetySubmissionDraft(
                input: input,
                idempotencyKey: UUID().uuidString.lowercased(),
                remoteReportUUID: nil,
                attachments: attachments
            )
        let priorAttachments = Dictionary(
            uniqueKeysWithValues: state.attachments.map { ($0.attachmentUUID, $0) }
        )
        state.input = input
        state.attachments = attachments.map { attachment in
            var reconciled = attachment
            reconciled.uploaded = priorAttachments[attachment.attachmentUUID]?.uploaded ?? attachment.uploaded
            return reconciled
        }
        IdentifiedSafetyDraftStore.save(state, userUUID: userUUID)

        let reportUUID: String
        if let existing = state.remoteReportUUID, !existing.isEmpty {
            if state.remoteInput != input {
                _ = try await api.updateSafetyReport(existing, input: input)
                state.remoteInput = input
                IdentifiedSafetyDraftStore.save(state, userUUID: userUUID)
            }
            reportUUID = existing
        } else {
            let created = try await api.createSafetyReport(input, idempotencyKey: state.idempotencyKey)
            reportUUID = created.reportUUID
            state.remoteReportUUID = reportUUID
            state.remoteInput = input
            IdentifiedSafetyDraftStore.save(state, userUUID: userUUID)
        }

        for index in state.attachments.indices where !state.attachments[index].uploaded {
            let attachment = state.attachments[index]
            let data = try SafetyDraftAttachmentFileStore.data(for: attachment)
            let presign = try await api.safetyAttachmentPresign(
                reportUUID: reportUUID,
                attachmentUUID: attachment.attachmentUUID,
                filename: attachment.filename,
                mimeType: attachment.mimeType,
                byteSize: data.count
            )
            guard let putURL = URL(string: presign.putURL) else {
                throw APIClientError.invalidURL
            }
            try await api.uploadPresigned(
                url: putURL,
                data: data,
                contentType: attachment.mimeType,
                extraHeaders: presign.headers
            )
            _ = try await api.completeSafetyAttachment(attachment.attachmentUUID)
            state.attachments[index].uploaded = true
            IdentifiedSafetyDraftStore.save(state, userUUID: userUUID)
        }

        do {
            return try await api.submitSafetyReport(reportUUID)
        } catch let error as APIClientError
            where error.httpStatus == 409 && error.errorCode == "workflow_gate_failed" {
            let report = try await api.safetyReport(reportUUID)
            guard report.status != "draft", report.status != "returned" else { throw error }
            return report
        }
    }

    func loadSafetyMailbox(_ reportUUID: String) async throws -> [SafetyMailboxMessageDTO] {
        try await api.safetyMailbox(reportUUID)
    }

    func postSafetyMailbox(_ reportUUID: String, body: String) async throws {
        guard isOnline else { throw SafetySubmissionError.offline }
        try await api.postSafetyMailbox(
            reportUUID,
            body: body.trimmingCharacters(in: .whitespacesAndNewlines)
        )
    }

    func submitAnonymousSafetyReport(_ input: SafetyReportInput) async throws -> AnonymousSafetyReceipt {
        var draft = AnonymousSafetyDraftStore.load()
        if draft?.input != input {
            draft = SafetySubmissionDraft(
                input: input,
                idempotencyKey: UUID().uuidString.lowercased(),
                remoteReportUUID: nil,
                attachments: []
            )
        }
        guard let draft else { throw SafetySubmissionError.invalidDraft }
        AnonymousSafetyDraftStore.save(draft)
        guard isOnline else {
            throw SafetySubmissionError.offlineAnonymous
        }
        let receipt = try await api.submitAnonymousSafetyReport(input, idempotencyKey: draft.idempotencyKey)
        try AnonymousSafetyReceiptStore.save(receipt)
        AnonymousSafetyDraftStore.clear()
        return receipt
    }

    func configureSafetyServer(_ serverURL: String) async {
        let trimmed = serverURL.trimmingCharacters(in: CharacterSet(charactersIn: "/"))
        guard let url = URL(string: trimmed) else { return }
        await api.setBaseURL(url)
    }

    func loadAnonymousSafetyStatus() async throws -> AnonymousSafetyStatus {
        guard let receiptID = AnonymousSafetyReceiptStore.receiptID,
              let secret = AnonymousSafetyReceiptStore.receiptSecret else {
            throw SafetySubmissionError.missingReceipt
        }
        return try await api.anonymousSafetyStatus(receiptID: receiptID, receiptSecret: secret)
    }

    func loadAnonymousSafetyMailbox() async throws -> [SafetyMailboxMessageDTO] {
        guard let receiptID = AnonymousSafetyReceiptStore.receiptID,
              let secret = AnonymousSafetyReceiptStore.receiptSecret else {
            throw SafetySubmissionError.missingReceipt
        }
        return try await api.anonymousSafetyMailbox(receiptID: receiptID, receiptSecret: secret)
    }

    func postAnonymousSafetyMailbox(_ body: String) async throws {
        guard isOnline else { throw SafetySubmissionError.offlineAnonymous }
        guard let receiptID = AnonymousSafetyReceiptStore.receiptID,
              let secret = AnonymousSafetyReceiptStore.receiptSecret else {
            throw SafetySubmissionError.missingReceipt
        }
        try await api.postAnonymousSafetyMailbox(
            receiptID: receiptID,
            receiptSecret: secret,
            body: body.trimmingCharacters(in: .whitespacesAndNewlines)
        )
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
        if url.host == "reset" { // ipca://reset?token=
            let token = URLComponents(url: url, resolvingAgainstBaseURL: false)?
                .queryItems?
                .first(where: { $0.name == "token" })?
                .value ?? ""
            pendingResetToken = token
            showingPasswordReset = true
            showingForgotPassword = false
            return
        }
        if url.host == "code" {
            let codeID = URLComponents(url: url, resolvingAgainstBaseURL: false)?
                .queryItems?
                .first(where: { $0.name == "code_id" })?
                .value ?? ""
            guard !codeID.isEmpty else { return }
            openRemoteSessionCode(codeID)
            return
        }
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
        if url.host == "safety" {
            let uuid = url.pathComponents.dropFirst().first
            guard let uuid, !uuid.isEmpty else { return }
            openSafetyReport(uuid)
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

    func openSafetyReport(_ reportUUID: String) {
        selectedTab = .safety
        pendingSafetyReportUUID = reportUUID
    }

    func openRemoteSessionCode(_ codeID: String) {
        let trimmed = codeID.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else { return }
        pendingRemoteSessionCodeID = trimmed
        presentPendingRemoteSessionCodeIfNeeded()
    }

    func closeRemoteSessionCode() {
        pendingRemoteSessionCodeID = ""
        showingRemoteSessionCode = false
    }

    private func presentPendingRemoteSessionCodeIfNeeded() {
        guard isAuthenticated, !pendingRemoteSessionCodeID.isEmpty else { return }
        showingRemoteSessionCode = true
    }

    func loadRemoteSessionCode(codeID: String) async throws -> RemoteSessionCodeEnvelope {
        try await api.remoteSessionCode(codeID: codeID)
    }

    func markRemoteSessionCodeViewed(codeID: String) async throws {
        try await api.markRemoteSessionCodeViewed(codeID: codeID)
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
        presentPendingRemoteSessionCodeIfNeeded()
    }

    private func applyBootstrap(_ response: BootstrapResponse, token: String) async {
        user = response.user
        capabilities = response.capabilities
        updateRequired = response.updateRequired
        isAuthenticated = true
        needsActionCount = response.needsActionCount
        persistUser(response.user)
        await api.setToken(token)
        presentPendingRemoteSessionCodeIfNeeded()
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
        showingForgotPassword = false
        showingPasswordReset = false
        pendingResetToken = ""
        showingRemoteSessionCode = false
        pendingRemoteSessionCodeID = ""
        needsActionCount = 0
        actionError = nil
        people = []
        selectedTab = .messages
        pendingCommunityPostUUID = nil
        pendingSafetyReportUUID = nil
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

enum SafetySubmissionError: LocalizedError {
    case offline
    case offlineAnonymous
    case missingReceipt
    case missingIdentity
    case invalidDraft

    var errorDescription: String? {
        switch self {
        case .offline:
            return "You're offline. Your identified report remains saved as a draft on this device."
        case .offlineAnonymous:
            return "You're offline. Your anonymous draft remains saved only on this device and is not linked to an account."
        case .missingReceipt:
            return "No anonymous report receipt is saved on this device."
        case .missingIdentity:
            return "Sign in again before submitting this identified report."
        case .invalidDraft:
            return "The saved safety draft could not be prepared."
        }
    }
}
