import Foundation

actor APIClient {
    private var baseURL: URL
    private var token: String?

    init(baseURL: URL, token: String? = nil) {
        self.baseURL = baseURL
        self.token = token
    }

    func setBaseURL(_ url: URL) {
        baseURL = url
    }

    func setToken(_ token: String?) {
        self.token = token
    }

    func login(email: String, password: String) async throws -> LoginResponse {
        try await post("api/communication/auth.php", body: [
            "action": "login",
            "email": email,
            "password": password,
            "device": DeviceIdentity.payload
        ], authorized: false)
    }

    func logout() async throws {
        let _: OKEnvelope = try await post("api/communication/auth.php", body: ["action": "logout"])
    }

    func bootstrap() async throws -> BootstrapResponse {
        try await get("api/communication/bootstrap.php")
    }

    func sync(cursor: Int) async throws -> SyncResponse {
        try await get("api/communication/sync.php", query: ["cursor": String(cursor)])
    }

    func createDirect(peerUserUUID: String) async throws -> ConversationDTO {
        let envelope: ConversationEnvelope = try await post("api/communication/conversations.php", body: [
            "type": "direct",
            "peer_user_uuid": peerUserUUID
        ])
        return envelope.conversation
    }

    func createGroup(title: String, memberUUIDs: [String]) async throws -> ConversationDTO {
        let envelope: ConversationEnvelope = try await post("api/communication/conversations.php", body: [
            "type": "group",
            "title": title,
            "member_user_uuids": memberUUIDs
        ])
        return envelope.conversation
    }

    func sendMessage(
        conversationUUID: String,
        clientID: String,
        body: String,
        attachmentUUIDs: [String] = [],
        replyToMessageUUID: String? = nil
    ) async throws -> MessageDTO {
        var payload: [String: Any] = [
            "conversation_uuid": conversationUUID,
            "client_id": clientID,
            "body": body
        ]
        if !attachmentUUIDs.isEmpty {
            payload["attachment_uuids"] = attachmentUUIDs
        }
        if let replyToMessageUUID, !replyToMessageUUID.isEmpty {
            payload["reply_to_message_uuid"] = replyToMessageUUID
        }
        let envelope: MessageEnvelope = try await post("api/communication/messages.php", body: payload)
        return envelope.message
    }

    func react(messageUUID: String, emoji: String) async throws -> MessageDTO {
        let envelope: MessageEnvelope = try await post("api/communication/reactions.php", body: [
            "message_uuid": messageUUID,
            "emoji": emoji,
            "reaction_uuid": UUID().uuidString.lowercased()
        ])
        return envelope.message
    }

    func presignAttachment(
        conversationUUID: String,
        attachmentUUID: String,
        filename: String,
        mimeType: String,
        byteSize: Int
    ) async throws -> AttachmentPresignEnvelope {
        try await post("api/communication/attachments.php", body: [
            "action": "presign",
            "conversation_uuid": conversationUUID,
            "attachment_uuid": attachmentUUID,
            "filename": filename,
            "mime_type": mimeType,
            "byte_size": byteSize
        ])
    }

    func completeAttachment(attachmentUUID: String) async throws {
        let _: AttachmentCompleteEnvelope = try await post("api/communication/attachments.php", body: [
            "action": "complete",
            "attachment_uuid": attachmentUUID
        ])
    }

    func attachmentDownloadURL(_ attachmentUUID: String) async throws -> URL {
        let envelope: AttachmentDownloadEnvelope = try await get(
            "api/communication/attachments.php",
            query: ["action": "download", "attachment_uuid": attachmentUUID]
        )
        guard let url = URL(string: envelope.getURL) else { throw APIClientError.decoding }
        return url
    }

    func uploadPresigned(url: URL, data: Data, contentType: String, extraHeaders: [String: String] = [:]) async throws {
        var request = URLRequest(url: url)
        request.httpMethod = "PUT"
        request.httpBody = data
        var sentContentType = false
        for (name, value) in extraHeaders {
            request.setValue(value, forHTTPHeaderField: name)
            if name.lowercased() == "content-type" {
                sentContentType = true
            }
        }
        if !sentContentType {
            request.setValue(contentType, forHTTPHeaderField: "Content-Type")
        }
        let response: URLResponse
        do {
            (_, response) = try await URLSession.shared.data(for: request)
        } catch {
            throw APIClientError.transport
        }
        let status = (response as? HTTPURLResponse)?.statusCode ?? 0
        guard (200..<300).contains(status) else {
            throw APIClientError.server(status: status, message: "Couldn't upload that file.", code: "upload_failed")
        }
    }

    func markRead(conversationUUID: String, lastReadSeq: Int) async throws {
        let _: OKEnvelope = try await post("api/communication/receipts.php", body: [
            "conversation_uuid": conversationUUID,
            "last_read_seq": lastReadSeq
        ])
    }

    func directory(query: String) async throws -> [PublicUser] {
        let envelope: DirectoryEnvelope = try await get("api/communication/directory.php", query: ["q": query])
        return envelope.people
    }

    func registerDevice(apnsToken: String?, authorized: Bool) async throws {
        var body: [String: Any] = DeviceIdentity.payload
        body["push_authorized"] = authorized
        body["apns_environment"] = DeviceIdentity.apnsEnvironment
        if let apnsToken, !apnsToken.isEmpty {
            body["apns_token"] = apnsToken
        }
        let _: OKEnvelope = try await post("api/communication/devices.php", body: body)
    }

    func acknowledge(messageUUID: String, acknowledgementUUID: String) async throws -> AckDTO {
        let envelope: AcknowledgementEnvelope = try await post("api/communication/acknowledgements.php", body: [
            "message_uuid": messageUUID,
            "acknowledgement_uuid": acknowledgementUUID
        ])
        return envelope.acknowledgement
    }

    func actions() async throws -> ActionsEnvelope {
        try await get("api/communication/actions.php")
    }

    func training() async throws -> TrainingSummaryDTO {
        try await get("api/communication/training.php")
    }

    func trainingVideoFeed(cursor: Int = 0) async throws -> TrainingVideoFeedDTO {
        var query: [String: String] = ["action": "feed"]
        if cursor > 0 {
            query["cursor"] = String(cursor)
        }
        return try await get("api/communication/training_videos.php", query: query)
    }

    func trainingVideo(_ videoUUID: String) async throws -> TrainingVideoDTO {
        let envelope: TrainingVideoEnvelope = try await get("api/communication/training_videos.php", query: [
            "action": "detail",
            "video_uuid": videoUUID
        ])
        return envelope.video
    }

    func trainingVideoPlayback(_ videoUUID: String, download: Bool = false) async throws -> TrainingVideoPlaybackDTO {
        try await get("api/communication/training_videos.php", query: [
            "action": download ? "download" : "play",
            "video_uuid": videoUUID
        ])
    }

    func trainingVideoComments(_ videoUUID: String) async throws -> [TrainingVideoCommentDTO] {
        let envelope: TrainingVideoCommentsEnvelope = try await get("api/communication/training_videos.php", query: [
            "action": "comments",
            "video_uuid": videoUUID
        ])
        return envelope.comments
    }

    func trainingVideoView(_ videoUUID: String) async throws -> TrainingVideoDTO {
        let envelope: TrainingVideoEnvelope = try await post("api/communication/training_videos.php", body: [
            "action": "view",
            "video_uuid": videoUUID
        ])
        return envelope.video
    }

    func trainingVideoLike(_ videoUUID: String) async throws -> TrainingVideoDTO {
        let envelope: TrainingVideoEnvelope = try await post("api/communication/training_videos.php", body: [
            "action": "like",
            "video_uuid": videoUUID
        ])
        return envelope.video
    }

    func trainingVideoUnlike(_ videoUUID: String) async throws -> TrainingVideoDTO {
        let envelope: TrainingVideoEnvelope = try await post("api/communication/training_videos.php", body: [
            "action": "unlike",
            "video_uuid": videoUUID
        ])
        return envelope.video
    }

    func trainingVideoComment(videoUUID: String, body: String, commentUUID: String) async throws -> TrainingVideoCommentDTO {
        let envelope: TrainingVideoCommentEnvelope = try await post("api/communication/training_videos.php", body: [
            "action": "comment",
            "video_uuid": videoUUID,
            "body": body,
            "comment_uuid": commentUUID
        ])
        return envelope.comment
    }

    func communityFeed(cursor: Int = 0) async throws -> CommunityFeedDTO {
        var query: [String: String] = ["action": "feed"]
        if cursor > 0 {
            query["cursor"] = String(cursor)
        }
        return try await get("api/communication/community.php", query: query)
    }

    func communityPost(_ postUUID: String) async throws -> CommunityPostEnvelope {
        try await get("api/communication/community.php", query: [
            "action": "post",
            "post_uuid": postUUID
        ])
    }

    func communityComments(_ postUUID: String) async throws -> [CommunityCommentDTO] {
        let envelope: CommunityCommentsEnvelope = try await get("api/communication/community.php", query: [
            "action": "comments",
            "post_uuid": postUUID
        ])
        return envelope.comments
    }

    func communityPresign(
        mediaUUID: String,
        filename: String,
        mimeType: String,
        byteSize: Int,
        durationMs: Int
    ) async throws -> CommunityPresignEnvelope {
        try await post("api/communication/community.php", body: [
            "action": "presign",
            "media_uuid": mediaUUID,
            "filename": filename,
            "mime_type": mimeType,
            "byte_size": byteSize,
            "duration_ms": durationMs
        ])
    }

    func communityComplete(mediaUUID: String) async throws {
        let _: OKEnvelope = try await post("api/communication/community.php", body: [
            "action": "complete",
            "media_uuid": mediaUUID
        ])
    }

    func communityCreate(caption: String, body: String, mediaUUIDs: [String], postUUID: String) async throws -> CommunityPostDTO {
        let envelope: CommunityPostEnvelope = try await post("api/communication/community.php", body: [
            "action": "create",
            "caption": caption,
            "body": body,
            "media_uuids": mediaUUIDs,
            "post_uuid": postUUID
        ])
        return envelope.post
    }

    func communityLike(_ postUUID: String) async throws -> CommunityPostDTO {
        let envelope: CommunityPostEnvelope = try await post("api/communication/community.php", body: [
            "action": "like",
            "post_uuid": postUUID
        ])
        return envelope.post
    }

    func communityUnlike(_ postUUID: String) async throws -> CommunityPostDTO {
        let envelope: CommunityPostEnvelope = try await post("api/communication/community.php", body: [
            "action": "unlike",
            "post_uuid": postUUID
        ])
        return envelope.post
    }

    func communityComment(postUUID: String, body: String, commentUUID: String) async throws -> CommunityCommentDTO {
        let envelope: CommunityCommentEnvelope = try await post("api/communication/community.php", body: [
            "action": "comment",
            "post_uuid": postUUID,
            "body": body,
            "comment_uuid": commentUUID
        ])
        return envelope.comment
    }

    func communityDelete(_ postUUID: String) async throws {
        let _: CommunityDeleteEnvelope = try await post("api/communication/community.php", body: [
            "action": "delete",
            "post_uuid": postUUID
        ])
    }

    func communityReport(postUUID: String, reason: String, details: String) async throws -> CommunityReportEnvelope {
        try await post("api/communication/community.php", body: [
            "action": "report",
            "post_uuid": postUUID,
            "reason": reason,
            "details": details
        ])
    }

    private func get<T: Decodable>(_ path: String, query: [String: String] = [:], authorized: Bool = true) async throws -> T {
        try await send(path, method: "GET", query: query, body: nil, authorized: authorized)
    }

    private func post<T: Decodable>(_ path: String, body: [String: Any], authorized: Bool = true) async throws -> T {
        try await send(path, method: "POST", query: [:], body: body, authorized: authorized)
    }

    private func send<T: Decodable>(
        _ path: String,
        method: String,
        query: [String: String],
        body: [String: Any]?,
        authorized: Bool
    ) async throws -> T {
        guard var components = URLComponents(url: baseURL.appendingAPIPath(path), resolvingAgainstBaseURL: false) else {
            throw APIClientError.invalidURL
        }
        if !query.isEmpty {
            components.queryItems = query.map { URLQueryItem(name: $0.key, value: $0.value) }
        }
        guard let url = components.url else { throw APIClientError.invalidURL }

        var request = URLRequest(url: url)
        request.httpMethod = method
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        if authorized, let token, !token.isEmpty {
            request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        }
        if let body {
            request.setValue("application/json", forHTTPHeaderField: "Content-Type")
            request.httpBody = try JSONSerialization.data(withJSONObject: body)
        }

        let data: Data
        let response: URLResponse
        do {
            (data, response) = try await URLSession.shared.data(for: request)
        } catch {
            throw APIClientError.transport
        }

        let status = (response as? HTTPURLResponse)?.statusCode ?? 0
        if status == 401 || status == 403 || !(200..<300).contains(status) {
            let payload = try? JSONDecoder().decode(APIErrorPayload.self, from: data)
            throw APIClientError.server(
                status: status,
                message: payload?.error ?? "Something went wrong.",
                code: payload?.errorCode ?? ""
            )
        }
        do {
            return try JSONDecoder().decode(T.self, from: data)
        } catch {
            throw APIClientError.decoding
        }
    }
}

private extension URL {
    func appendingAPIPath(_ path: String) -> URL {
        let trimmedBase = absoluteString.hasSuffix("/") ? absoluteString : absoluteString + "/"
        let trimmedPath = path.hasPrefix("/") ? String(path.dropFirst()) : path
        return URL(string: trimmedBase + trimmedPath) ?? self.appendingPathComponent(trimmedPath)
    }
}
