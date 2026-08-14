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

    func sendMessage(conversationUUID: String, clientID: String, body: String) async throws -> MessageDTO {
        let envelope: MessageEnvelope = try await post("api/communication/messages.php", body: [
            "conversation_uuid": conversationUUID,
            "client_id": clientID,
            "body": body
        ])
        return envelope.message
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
