import Foundation

actor APIClient {
    private var baseURL: URL
    private var token: String?
    private let urlSession: URLSession
    private let anonymousURLSession: URLSession

    init(
        baseURL: URL,
        token: String? = nil,
        urlSession: URLSession = .shared,
        anonymousURLSession: URLSession? = nil
    ) {
        self.baseURL = baseURL
        self.token = token
        self.urlSession = urlSession
        if let anonymousURLSession {
            self.anonymousURLSession = anonymousURLSession
        } else {
            let configuration = URLSessionConfiguration.ephemeral
            configuration.httpCookieStorage = nil
            configuration.httpShouldSetCookies = false
            configuration.urlCache = nil
            configuration.requestCachePolicy = .reloadIgnoringLocalCacheData
            self.anonymousURLSession = URLSession(configuration: configuration)
        }
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

    func forgotPassword(email: String) async throws -> PasswordResetEnvelope {
        try await post("api/communication/auth.php", body: [
            "action": "forgot_password",
            "email": email
        ], authorized: false)
    }

    func validateResetToken(_ token: String) async throws -> PasswordResetEnvelope {
        try await post("api/communication/auth.php", body: [
            "action": "validate_reset_token",
            "token": token
        ], authorized: false)
    }

    func resetPassword(token: String, password: String, confirm: String) async throws -> PasswordResetEnvelope {
        try await post("api/communication/auth.php", body: [
            "action": "reset_password",
            "token": token,
            "password": password,
            "password_confirm": confirm
        ], authorized: false)
    }

    func remoteSessionCode(codeID: String) async throws -> RemoteSessionCodeEnvelope {
        try await get("api/communication/remote_session_code.php", query: ["code_id": codeID])
    }

    func markRemoteSessionCodeViewed(codeID: String) async throws {
        let _: OKEnvelope = try await post("api/communication/remote_session_code.php", body: [
            "code_id": codeID,
            "viewed": 1
        ])
    }

    func profile() async throws -> ProfileEnvelope {
        try await get("api/communication/profile.php")
    }

    func savePersonalProfile(_ profile: ProfileDetails) async throws -> ProfileEnvelope {
        try await post("api/communication/profile.php", body: [
            "action": "save_personal",
            "first_name": profile.firstName,
            "last_name": profile.lastName,
            "street_address": profile.streetAddress,
            "street_number": profile.streetNumber,
            "zip_code": profile.zipCode,
            "city": profile.city,
            "state_region": profile.stateRegion,
            "country_code": profile.countryCode,
            "cellphone": profile.cellphone,
            "secondary_email": profile.secondaryEmail,
            "date_of_birth": profile.dateOfBirth,
            "place_of_birth": profile.placeOfBirth,
            "nationality": profile.nationality,
            "id_passport_number": profile.idPassportNumber,
            "gender": profile.gender,
            "marital_status": profile.maritalStatus,
            "hair_color": profile.hairColor,
            "eye_color": profile.eyeColor,
            "weight_kg": profile.weightKg,
            "height_cm": profile.heightCm
        ])
    }

    func saveEmergencyContacts(_ contacts: [EmergencyContact]) async throws -> ProfileEnvelope {
        try await post("api/communication/profile.php", body: [
            "action": "save_emergency",
            "emergency_contacts": contacts.map {
                [
                    "sort_order": $0.sortOrder,
                    "contact_name": $0.contactName,
                    "relationship": $0.relationship,
                    "phone": $0.phone
                ] as [String: Any]
            }
        ])
    }

    func changePassword(current: String, new: String, confirm: String) async throws {
        let _: OKEnvelope = try await post("api/communication/profile.php", body: [
            "action": "change_password",
            "current_password": current,
            "new_password": new,
            "new_password_confirm": confirm
        ])
    }

    func uploadProfilePhoto(data: Data, mimeType: String) async throws -> ProfileEnvelope {
        try await send(
            "api/communication/profile.php",
            method: "POST",
            query: ["action": "photo"],
            body: nil,
            authorized: true,
            rawBody: data,
            contentType: mimeType
        )
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

    func updateGroupMembers(conversationUUID: String, addUserUUIDs: [String] = [], removeUserUUIDs: [String] = []) async throws -> ConversationDTO {
        let envelope: ConversationEnvelope = try await post("api/communication/conversations.php", body: [
            "type": "group_members",
            "conversation_uuid": conversationUUID,
            "add_user_uuids": addUserUUIDs,
            "remove_user_uuids": removeUserUUIDs
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
            (_, response) = try await urlSession.data(for: request)
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

    func trainingVideoProgress(videoUUID: String, positionMs: Int, durationMs: Int) async throws -> TrainingVideoDTO {
        let envelope: TrainingVideoEnvelope = try await post("api/communication/training_videos.php", body: [
            "action": "progress",
            "video_uuid": videoUUID,
            "position_ms": positionMs,
            "duration_ms": durationMs
        ])
        return envelope.video
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

    func createSafetyReport(_ input: SafetyReportInput, idempotencyKey: String) async throws -> SafetyReportDTO {
        var body = input.apiPayload
        body["action"] = "create"
        let envelope: SafetyReportEnvelope = try await post(
            "api/safety/reports.php",
            body: body,
            idempotencyKey: idempotencyKey
        )
        return envelope.report
    }

    func safetyReports() async throws -> [SafetyReportDTO] {
        let envelope: SafetyReportsEnvelope = try await get("api/safety/reports.php", query: ["action": "list"])
        return envelope.reports
    }

    func safetyFlightCandidates(eventAtUTC: String) async throws -> [SafetyFlightCandidateDTO] {
        let envelope: SafetyFlightCandidatesEnvelope = try await get("api/safety/reports.php", query: [
            "action": "flight_candidates",
            "event_at_utc": eventAtUTC
        ])
        return envelope.flightCandidates
    }

    func safetyReport(_ reportUUID: String) async throws -> SafetyReportDTO {
        let envelope: SafetyReportEnvelope = try await get("api/safety/reports.php", query: [
            "action": "detail",
            "report_uuid": reportUUID
        ])
        return envelope.report
    }

    func updateSafetyReport(_ reportUUID: String, input: SafetyReportInput) async throws -> SafetyReportDTO {
        var body = input.apiPayload
        body["action"] = "update"
        body["report_uuid"] = reportUUID
        let envelope: SafetyReportEnvelope = try await post("api/safety/reports.php", body: body)
        return envelope.report
    }

    func submitSafetyReport(_ reportUUID: String) async throws -> SafetyReportDTO {
        let envelope: SafetyReportEnvelope = try await post("api/safety/reports.php", body: [
            "action": "submit",
            "report_uuid": reportUUID
        ])
        return envelope.report
    }

    func safetyMailbox(_ reportUUID: String) async throws -> [SafetyMailboxMessageDTO] {
        let envelope: SafetyMailboxEnvelope = try await get("api/safety/mailbox.php", query: [
            "report_uuid": reportUUID
        ])
        return envelope.messages
    }

    func postSafetyMailbox(_ reportUUID: String, body: String) async throws {
        let _: SafetyUpdateEnvelope = try await post("api/safety/mailbox.php", body: [
            "report_uuid": reportUUID,
            "body": body
        ])
    }

    func safetyAttachmentPresign(
        reportUUID: String,
        attachmentUUID: String,
        filename: String,
        mimeType: String,
        byteSize: Int
    ) async throws -> SafetyAttachmentPresignDTO {
        let envelope: SafetyAttachmentPresignEnvelope = try await post("api/safety/attachments.php", body: [
            "action": "presign",
            "report_uuid": reportUUID,
            "attachment_uuid": attachmentUUID,
            "filename": filename,
            "mime_type": mimeType,
            "byte_size": byteSize
        ])
        return envelope.attachment
    }

    func completeSafetyAttachment(_ attachmentUUID: String) async throws -> SafetyAttachmentDTO {
        let envelope: SafetyAttachmentEnvelope = try await post("api/safety/attachments.php", body: [
            "action": "complete",
            "attachment_uuid": attachmentUUID
        ])
        return envelope.attachment
    }

    func submitAnonymousSafetyReport(
        _ input: SafetyReportInput,
        idempotencyKey: String
    ) async throws -> AnonymousSafetyReceipt {
        try await post(
            "api/safety/anonymous/submit.php",
            body: input.apiPayload,
            authorized: false,
            idempotencyKey: idempotencyKey,
            privacyIsolated: true
        )
    }

    func anonymousSafetyStatus(receiptID: String, receiptSecret: String) async throws -> AnonymousSafetyStatus {
        try await post("api/safety/anonymous/status.php", body: [
            "receipt_id": receiptID,
            "secret": receiptSecret
        ], authorized: false, privacyIsolated: true)
    }

    func anonymousSafetyMailbox(receiptID: String, receiptSecret: String) async throws -> [SafetyMailboxMessageDTO] {
        let envelope: SafetyMailboxEnvelope = try await post("api/safety/anonymous/mailbox.php", body: [
            "action": "list",
            "receipt_id": receiptID,
            "secret": receiptSecret
        ], authorized: false, privacyIsolated: true)
        return envelope.messages
    }

    func postAnonymousSafetyMailbox(receiptID: String, receiptSecret: String, body: String) async throws {
        let _: SafetyUpdateEnvelope = try await post("api/safety/anonymous/mailbox.php", body: [
            "action": "post",
            "receipt_id": receiptID,
            "secret": receiptSecret,
            "body": body
        ], authorized: false, privacyIsolated: true)
    }

    private func get<T: Decodable>(_ path: String, query: [String: String] = [:], authorized: Bool = true) async throws -> T {
        try await send(path, method: "GET", query: query, body: nil, authorized: authorized)
    }

    private func post<T: Decodable>(
        _ path: String,
        body: [String: Any],
        authorized: Bool = true,
        idempotencyKey: String? = nil,
        privacyIsolated: Bool = false
    ) async throws -> T {
        try await send(
            path,
            method: "POST",
            query: [:],
            body: body,
            authorized: authorized,
            idempotencyKey: idempotencyKey,
            privacyIsolated: privacyIsolated
        )
    }

    private func send<T: Decodable>(
        _ path: String,
        method: String,
        query: [String: String],
        body: [String: Any]?,
        authorized: Bool,
        rawBody: Data? = nil,
        contentType: String? = nil,
        idempotencyKey: String? = nil,
        privacyIsolated: Bool = false
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
        if method == "POST" {
            request.setValue(idempotencyKey ?? UUID().uuidString, forHTTPHeaderField: "Idempotency-Key")
        }
        if authorized, let token, !token.isEmpty {
            request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        }
        if let rawBody {
            request.setValue(contentType ?? "application/octet-stream", forHTTPHeaderField: "Content-Type")
            request.httpBody = rawBody
        } else if let body {
            request.setValue("application/json", forHTTPHeaderField: "Content-Type")
            request.httpBody = try JSONSerialization.data(withJSONObject: body)
        }

        let data: Data
        let response: URLResponse
        do {
            let session = privacyIsolated ? anonymousURLSession : urlSession
            (data, response) = try await session.data(for: request)
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
