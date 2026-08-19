import Foundation

enum SchedulerAPIError: LocalizedError, Equatable {
    case invalidServerURL
    case transport(String)
    case malformedResponse
    case server(status: Int, code: String, message: String, retryable: Bool)

    var errorCode: String? {
        if case let .server(_, code, _, _) = self { return code }
        return nil
    }

    var isAuthenticationFailure: Bool {
        guard case let .server(status, code, _, _) = self else { return false }
        return status == 401 || ["unauthenticated", "credential_revoked"].contains(code)
    }

    var isAccountIneligible: Bool {
        guard case let .server(_, code, _, _) = self else { return false }
        return code == "account_ineligible"
    }

    var errorDescription: String? {
        switch self {
        case .invalidServerURL:
            return "The scheduling server address is invalid."
        case let .transport(message):
            return message
        case .malformedResponse:
            return "The scheduling server returned an unexpected response."
        case let .server(_, code, message, _):
            switch code {
            case "account_ineligible":
                return "Your IPCA account is currently unavailable."
            case "unauthenticated", "credential_revoked":
                return "Your session has expired. Please sign in again."
            case "forbidden":
                return "You don't have access to this reservation."
            default:
                return message.isEmpty ? "We couldn't update the schedule." : message
            }
        }
    }
}

actor SchedulerAPIClient {
    private var baseURL: URL
    private var bearerToken: String?
    private let session: URLSession
    private let encoder: JSONEncoder
    private let decoder: JSONDecoder

    init(
        baseURL: URL = URL(string: "https://ipca.training")!,
        bearerToken: String? = nil,
        session: URLSession = .shared
    ) {
        self.baseURL = baseURL
        self.bearerToken = bearerToken
        self.session = session
        encoder = JSONEncoder()
        decoder = JSONDecoder()
        decoder.dateDecodingStrategy = .iso8601
    }

    func setBearerToken(_ token: String?) {
        bearerToken = token
    }

    func login(email: String, password: String, device: DevicePayload) async throws -> LoginResponse {
        struct LoginRequest: Encodable {
            let action = "login"
            let email: String
            let password: String
            let device: DevicePayload
        }
        return try await send(
            path: "api/scheduler/auth.php",
            method: "POST",
            authorized: false,
            body: LoginRequest(email: email, password: password, device: device)
        )
    }

    func logout() async throws {
        struct LogoutRequest: Encodable { let action = "logout" }
        struct LogoutResponse: Decodable { let ok: Bool }
        let _: LogoutResponse = try await send(
            path: "api/scheduler/auth.php",
            method: "POST",
            authorized: true,
            body: LogoutRequest()
        )
    }

    func bootstrap() async throws -> SchedulerBootstrapResponse {
        try await send(path: "api/scheduler/bootstrap.php", method: "GET", authorized: true)
    }

    func schedule(
        from start: String,
        to end: String,
        filters: ScheduleFilters = .empty
    ) async throws -> ScheduleRangeResponse {
        var items = [
            URLQueryItem(name: "start", value: start),
            URLQueryItem(name: "end", value: end)
        ]
        if let id = filters.aircraftID {
            items.append(URLQueryItem(name: "aircraft_id", value: String(id)))
        }
        if let id = filters.participantUserID {
            items.append(URLQueryItem(name: "participant_user_id", value: String(id)))
        }
        if let id = filters.cohortID {
            items.append(URLQueryItem(name: "cohort_id", value: String(id)))
        }
        if let type = filters.reservationType {
            items.append(URLQueryItem(name: "reservation_type", value: type))
        }
        return try await send(
            path: "api/scheduler/schedule.php",
            method: "GET",
            queryItems: items,
            authorized: true
        )
    }

    func reservation(uuid: String) async throws -> ReservationDetailResponse {
        try await send(
            path: "api/scheduler/reservations.php",
            method: "GET",
            queryItems: [URLQueryItem(name: "reservation_uuid", value: uuid)],
            authorized: true
        )
    }

    func resources(type: String, query: String = "", limit: Int = 30) async throws -> SchedulerResourcesResponse {
        try await send(
            path: "api/scheduler/resources.php",
            method: "GET",
            queryItems: [
                URLQueryItem(name: "type", value: type),
                URLQueryItem(name: "q", value: query),
                URLQueryItem(name: "limit", value: String(limit))
            ],
            authorized: true
        )
    }

    private func send<Response: Decodable>(
        path: String,
        method: String,
        queryItems: [URLQueryItem] = [],
        authorized: Bool,
        body: (any Encodable)? = nil
    ) async throws -> Response {
        guard var components = URLComponents(
            url: baseURL.appendingPathComponent(path),
            resolvingAgainstBaseURL: false
        ) else {
            throw SchedulerAPIError.invalidServerURL
        }
        if !queryItems.isEmpty { components.queryItems = queryItems }
        guard let url = components.url else { throw SchedulerAPIError.invalidServerURL }

        var request = URLRequest(url: url)
        request.httpMethod = method
        request.timeoutInterval = 25
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        if authorized {
            guard let bearerToken, !bearerToken.isEmpty else {
                throw SchedulerAPIError.server(
                    status: 401,
                    code: "unauthenticated",
                    message: "Please sign in.",
                    retryable: false
                )
            }
            request.setValue("Bearer \(bearerToken)", forHTTPHeaderField: "Authorization")
        }
        if let body {
            request.setValue("application/json", forHTTPHeaderField: "Content-Type")
            request.httpBody = try encoder.encode(AnyEncodable(body))
        }

        let data: Data
        let response: URLResponse
        do {
            (data, response) = try await session.data(for: request)
        } catch let error as URLError {
            throw SchedulerAPIError.transport(error.localizedDescription)
        } catch {
            throw SchedulerAPIError.transport("We couldn't reach the scheduling server.")
        }
        guard let http = response as? HTTPURLResponse else {
            throw SchedulerAPIError.malformedResponse
        }
        guard (200 ... 299).contains(http.statusCode) else {
            let envelope = try? decoder.decode(APIErrorEnvelope.self, from: data)
            throw SchedulerAPIError.server(
                status: http.statusCode,
                code: envelope?.errorCode ?? "server_error",
                message: envelope?.message ?? "We couldn't update the schedule.",
                retryable: envelope?.retryable ?? (http.statusCode >= 500)
            )
        }
        do {
            return try decoder.decode(Response.self, from: data)
        } catch {
            throw SchedulerAPIError.malformedResponse
        }
    }
}

private struct AnyEncodable: Encodable {
    private let encodeClosure: (Encoder) throws -> Void

    init(_ value: any Encodable) {
        encodeClosure = value.encode
    }

    func encode(to encoder: Encoder) throws {
        try encodeClosure(encoder)
    }
}
