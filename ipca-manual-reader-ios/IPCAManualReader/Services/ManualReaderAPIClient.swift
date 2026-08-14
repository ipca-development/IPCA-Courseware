import Foundation

enum ManualReaderAPIError: LocalizedError {
    case invalidServerURL
    case unauthorized
    case forbidden
    case badResponse(String)
    case invalidJSON(String)

    var errorDescription: String? {
        switch self {
        case .invalidServerURL: "Server URL is invalid."
        case .unauthorized: "Please sign in again."
        case .forbidden: "You do not have access to manuals."
        case .badResponse(let message): message
        case .invalidJSON(let message): message
        }
    }
}

struct ManualReaderAPIClient {
    var baseURL: URL
    var session: URLSession

    init(baseURL: URL, session: URLSession? = nil) {
        self.baseURL = baseURL
        if let session {
            self.session = session
        } else {
            let config = URLSessionConfiguration.default
            config.timeoutIntervalForRequest = 20
            config.timeoutIntervalForResource = 30
            config.waitsForConnectivity = false
            self.session = URLSession(configuration: config)
        }
    }

    static func absoluteURL(from path: String?, baseURL: URL) -> URL? {
        guard let path, !path.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty else { return nil }
        if path.hasPrefix("http://") || path.hasPrefix("https://") {
            return URL(string: path)
        }
        if path.hasPrefix("/") {
            var components = URLComponents(url: baseURL, resolvingAgainstBaseURL: false)
            components?.path = path
            return components?.url
        }
        return baseURL.appending(path: path)
    }

    // MARK: - Auth

    func fetchSession() async throws -> AuthSessionResponse {
        if await isAuthAPIAvailable() {
            do {
                return try await get("student/api/manual_reader_auth_api.php", query: [("action", "session")])
            } catch ManualReaderAPIError.badResponse(let message) where message.contains("HTTP 5") {
                return try await fetchSessionViaLibrary()
            }
        }
        return try await fetchSessionViaLibrary()
    }

    func login(email: String, password: String) async throws -> AuthSessionResponse {
        if await isAuthAPIAvailable() {
            do {
                let body: [String: String] = [
                    "action": "login",
                    "email": email,
                    "password": password,
                ]
                return try await postJSON("student/api/manual_reader_auth_api.php", body: body)
            } catch ManualReaderAPIError.badResponse(let message) where message.contains("HTTP 5") {
                return try await loginViaWebForm(email: email, password: password)
            }
        }
        return try await loginViaWebForm(email: email, password: password)
    }

    func logout() async throws {
        if await isAuthAPIAvailable() {
            _ = try await postJSON("student/api/manual_reader_auth_api.php", body: ["action": "logout"]) as OKResponse
            return
        }
        var request = URLRequest(url: baseURL.appending(path: "logout.php"))
        request.httpMethod = "GET"
        _ = try await session.data(for: request)
    }

    /// Probe whether the mobile auth API has been deployed on this server.
    private func isAuthAPIAvailable() async -> Bool {
        var components = URLComponents(
            url: baseURL.appending(path: "student/api/manual_reader_auth_api.php"),
            resolvingAgainstBaseURL: false
        )
        components?.queryItems = [URLQueryItem(name: "action", value: "session")]
        guard let url = components?.url else { return false }

        var request = URLRequest(url: url)
        request.httpMethod = "GET"
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.timeoutInterval = 8

        do {
            let (_, response) = try await session.data(for: request)
            guard let http = response as? HTTPURLResponse else { return false }
            return (200...299).contains(http.statusCode)
        } catch {
            return false
        }
    }

    /// Legacy login via existing web form — used until manual_reader_auth_api.php is deployed.
    private func loginViaWebForm(email: String, password: String) async throws -> AuthSessionResponse {
        var request = URLRequest(url: baseURL.appending(path: "login.php"))
        request.httpMethod = "POST"
        request.setValue("application/x-www-form-urlencoded", forHTTPHeaderField: "Content-Type")
        request.httpBody = formURLEncodedBody([
            "email": email,
            "password": password,
        ])

        let (data, response) = try await session.data(for: request)
        guard let http = response as? HTTPURLResponse else {
            throw ManualReaderAPIError.badResponse("No response from login.")
        }

        if http.url?.path.contains("login.php") == true {
            let html = String(data: data, encoding: .utf8) ?? ""
            if html.localizedCaseInsensitiveContains("invalid email") || html.contains("login-error") {
                throw ManualReaderAPIError.badResponse("Invalid email or password.")
            }
        }

        let library = try await fetchLibrary()
        guard library.ok else {
            throw ManualReaderAPIError.forbidden
        }

        let displayName = email.split(separator: "@").first.map(String.init) ?? email
        return AuthSessionResponse(
            ok: true,
            loggedIn: true,
            user: ReaderUser(id: 0, email: email, name: displayName, role: ""),
            canReadManuals: true,
            error: nil
        )
    }

    private func fetchSessionViaLibrary() async throws -> AuthSessionResponse {
        do {
            let library = try await fetchLibrary()
            if library.ok {
                return AuthSessionResponse(ok: true, loggedIn: true, user: nil, canReadManuals: true, error: nil)
            }
        } catch ManualReaderAPIError.unauthorized {
            return AuthSessionResponse(ok: true, loggedIn: false, user: nil, canReadManuals: nil, error: nil)
        } catch ManualReaderAPIError.forbidden {
            return AuthSessionResponse(ok: true, loggedIn: false, user: nil, canReadManuals: nil, error: nil)
        } catch ManualReaderAPIError.invalidJSON {
            return AuthSessionResponse(ok: true, loggedIn: false, user: nil, canReadManuals: nil, error: nil)
        }
        return AuthSessionResponse(ok: true, loggedIn: false, user: nil, canReadManuals: nil, error: nil)
    }

    private func formURLEncodedBody(_ fields: [String: String]) -> Data {
        let query = fields
            .map { key, value in
                "\(formURLEncode(key))=\(formURLEncode(value))"
            }
            .joined(separator: "&")
        return Data(query.utf8)
    }

    private func formURLEncode(_ value: String) -> String {
        var allowed = CharacterSet.alphanumerics
        allowed.insert(charactersIn: "-._~")
        return value.addingPercentEncoding(withAllowedCharacters: allowed) ?? value
    }

    // MARK: - Reader

    func fetchLibrary() async throws -> LibraryResponse {
        try await get("student/api/manual_reader_api.php", query: [("action", "library")])
    }

    private func readerQuery(
        bookKey: String,
        versionId: Int?,
        isPreview: Bool,
        action: String,
        extra: [(String, String)] = []
    ) -> [(String, String)] {
        var query = extra
        query.append(("action", action))
        query.append(("book", bookKey))
        if let versionId, versionId > 0 {
            query.append(("version_id", String(versionId)))
        }
        if isPreview {
            query.append(("preview", "1"))
        }
        return query
    }

    func fetchPageMap(bookKey: String, versionId: Int? = nil, isPreview: Bool = false) async throws -> PageMapResponse {
        try await get(
            "student/api/manual_reader_api.php",
            query: readerQuery(bookKey: bookKey, versionId: versionId, isPreview: isPreview, action: "page_map")
        )
    }

    func fetchPaginateSource(
        bookKey: String,
        versionId: Int? = nil,
        isPreview: Bool = false
    ) async throws -> Data {
        let query = readerQuery(
            bookKey: bookKey,
            versionId: versionId,
            isPreview: isPreview,
            action: "paginate_source"
        )
        var components = URLComponents(
            url: baseURL.appending(path: "student/api/manual_reader_api.php"),
            resolvingAgainstBaseURL: false
        )
        components?.queryItems = query.map { URLQueryItem(name: $0.0, value: $0.1) }
        guard let url = components?.url else { throw ManualReaderAPIError.invalidServerURL }
        var request = URLRequest(url: url)
        request.httpMethod = "GET"
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.timeoutInterval = 90
        let (data, response) = try await session.data(for: request)
        try validate(response: response, data: data)
        guard let envelope = try JSONSerialization.jsonObject(with: data) as? [String: Any],
              let source = envelope["source"],
              JSONSerialization.isValidJSONObject(source) else {
            throw ManualReaderAPIError.badResponse("Invalid pagination source response.")
        }
        return try JSONSerialization.data(withJSONObject: source)
    }

    func downloadManualPages(
        bookKey: String,
        pageNumbers: [Int],
        versionId: Int? = nil,
        isPreview: Bool = false
    ) async throws -> ManualPageBatchResponse {
        try await get(
            "student/api/manual_reader_api.php",
            query: readerQuery(
                bookKey: bookKey,
                versionId: versionId,
                isPreview: isPreview,
                action: "download_pages",
                extra: [("page_numbers", pageNumbers.map(String.init).joined(separator: ","))]
            ),
            timeoutInterval: 90
        )
    }

    func fetchPage(bookKey: String, pageNumber: Int, versionId: Int? = nil, isPreview: Bool = false) async throws -> FrozenPageResponse {
        try await get(
            "student/api/manual_reader_api.php",
            query: readerQuery(
                bookKey: bookKey,
                versionId: versionId,
                isPreview: isPreview,
                action: "page",
                extra: [("page_number", String(pageNumber))]
            )
        )
    }

    func fetchToc(bookKey: String, versionId: Int? = nil, isPreview: Bool = false) async throws -> TocResponse {
        try await get(
            "student/api/manual_reader_api.php",
            query: readerQuery(bookKey: bookKey, versionId: versionId, isPreview: isPreview, action: "toc_with_pages")
        )
    }

    func fetchProgress(bookKey: String, versionId: Int? = nil, isPreview: Bool = false) async throws -> ProgressGetResponse {
        try await get(
            "student/api/manual_reader_api.php",
            query: readerQuery(bookKey: bookKey, versionId: versionId, isPreview: isPreview, action: "progress_get")
        )
    }

    func saveProgress(bookKey: String, sectionId: Int, stableAnchor: String, pageNumber: Int, versionId: Int? = nil) async throws {
        var body: [String: Any] = [
            "book_key": bookKey,
            "section_id": sectionId,
            "stable_anchor": stableAnchor,
            "page_number": pageNumber,
        ]
        if let versionId, versionId > 0 {
            body["version_id"] = versionId
        }
        _ = try await postJSON("student/api/manual_reader_api.php?action=progress_save", body: body) as OKResponse
    }

    func searchTitles(bookKey: String, query: String, versionId: Int? = nil, isPreview: Bool = false) async throws -> SearchTitlesResponse {
        try await get(
            "student/api/manual_reader_api.php",
            query: readerQuery(
                bookKey: bookKey,
                versionId: versionId,
                isPreview: isPreview,
                action: "search_titles",
                extra: [("q", query)]
            )
        )
    }

    // MARK: - Transport

    private func get<T: Decodable>(
        _ path: String,
        query: [(String, String)],
        timeoutInterval: TimeInterval? = nil
    ) async throws -> T {
        var components = URLComponents(url: baseURL.appending(path: path), resolvingAgainstBaseURL: false)
        components?.queryItems = query.map { URLQueryItem(name: $0.0, value: $0.1) }
        guard let url = components?.url else { throw ManualReaderAPIError.invalidServerURL }

        var request = URLRequest(url: url)
        request.httpMethod = "GET"
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        if let timeoutInterval {
            request.timeoutInterval = timeoutInterval
        }

        let (data, response) = try await session.data(for: request)
        try validate(response: response, data: data)
        return try decode(T.self, from: data, response: response)
    }

    private func postJSON<T: Decodable>(_ path: String, body: [String: Any]) async throws -> T {
        let url = baseURL.appending(path: path)
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.httpBody = try JSONSerialization.data(withJSONObject: body)

        let (data, response) = try await session.data(for: request)
        try validate(response: response, data: data)
        return try decode(T.self, from: data, response: response)
    }

    private func validate(response: URLResponse, data: Data) throws {
        guard let http = response as? HTTPURLResponse else { return }
        if http.statusCode == 401 {
            throw ManualReaderAPIError.unauthorized
        }
        if http.statusCode == 403 {
            throw ManualReaderAPIError.forbidden
        }
        if http.statusCode >= 400 {
            throw ManualReaderAPIError.badResponse(friendlyHTTPError(statusCode: http.statusCode, data: data, url: http.url))
        }
    }

    private func friendlyHTTPError(statusCode: Int, data: Data, url: URL?) -> String {
        if let serverMessage = apiErrorMessage(from: data), !serverMessage.isEmpty {
            return serverMessage
        }
        if statusCode == 404 {
            let path = url?.path ?? "requested path"
            return "Endpoint not found (HTTP 404): \(path). If this persists after updating the app, contact IPCA support."
        }
        if (500...599).contains(statusCode) {
            return "Server error (HTTP \(statusCode)). The login service may need an update on the server — try again after deploy, or contact IPCA support."
        }
        let text = String(data: data, encoding: .utf8)?
            .replacingOccurrences(of: "\n", with: " ")
            .trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
        if text.localizedCaseInsensitiveContains("<html") {
            if text.localizedCaseInsensitiveContains("404") {
                return "Server returned HTTP 404 — the requested API is not available on this server."
            }
            return "Server returned HTTP \(statusCode) (HTML error page)."
        }
        if text.isEmpty {
            return "HTTP \(statusCode): empty response"
        }
        if text.count > 200 {
            return "HTTP \(statusCode): \(String(text.prefix(200)))..."
        }
        return "HTTP \(statusCode): \(text)"
    }

    private func apiErrorMessage(from data: Data) -> String? {
        guard let object = try? JSONSerialization.jsonObject(with: data) as? [String: Any] else {
            return nil
        }
        let message = (object["error"] as? String)?.trimmingCharacters(in: .whitespacesAndNewlines)
        return message?.isEmpty == false ? message : nil
    }

    private func decode<T: Decodable>(_ type: T.Type, from data: Data, response: URLResponse) throws -> T {
        let decoder = JSONDecoder()
        do {
            let decoded = try decoder.decode(T.self, from: data)
            if let envelope = decoded as? OKResponse, envelope.ok == false {
                throw ManualReaderAPIError.badResponse(envelope.error ?? "Request failed")
            }
            if let auth = decoded as? AuthSessionResponse, auth.ok == false {
                throw ManualReaderAPIError.badResponse(auth.error ?? "Authentication failed")
            }
            return decoded
        } catch let error as ManualReaderAPIError {
            throw error
        } catch {
            let url = (response as? HTTPURLResponse)?.url?.absoluteString ?? "unknown URL"
            throw ManualReaderAPIError.invalidJSON("Invalid JSON from \(url): \(responsePreview(data))")
        }
    }

    private func responsePreview(_ data: Data) -> String {
        let text = String(data: data, encoding: .utf8)?
            .replacingOccurrences(of: "\n", with: " ")
            .trimmingCharacters(in: .whitespacesAndNewlines) ?? "\(data.count) bytes"
        if text.count > 400 {
            return String(text.prefix(400)) + "..."
        }
        return text.isEmpty ? "empty response" : text
    }
}

extension ManualReaderAPIClient {
    /// Wrap frozen page HTML with the same stylesheets as the web reader for pixel-identical layout.
    func pageHTMLDocument(
        pageHtml: String,
        settings: ReaderSettings,
        cssVersion: String = "",
        embeddedContentCSS: String? = nil,
        embeddedReaderCSS: String? = nil,
        layout: PageLayoutConfiguration? = nil
    ) -> String {
        let v = cssVersion.isEmpty ? String(Int(Date().timeIntervalSince1970)) : cssVersion
        let contentCSS = baseURL.appending(path: "assets/manual_reader_content.css").absoluteString + "?v=\(v)"
        let readerCSS = baseURL.appending(path: "assets/manual_reader.css").absoluteString + "?v=\(v)"
        let stylesheetMarkup: String
        if let embeddedContentCSS, let embeddedReaderCSS {
            stylesheetMarkup = "<style>\(embeddedContentCSS)\n\(embeddedReaderCSS)</style>"
        } else {
            stylesheetMarkup = """
              <link rel="stylesheet" href="\(contentCSS)">
              <link rel="stylesheet" href="\(readerCSS)">
            """
        }
        let theme = settings.theme.rawValue
        let zoomClass: String = {
            switch settings.zoom {
            case .fitWidth: "is-fit-width"
            case .fitPage: "is-fit-page"
            case .percent75: "is-zoom-75"
            case .percent100: "is-zoom-100"
            case .percent125: "is-zoom-125"
            }
        }()
        let fontScale = settings.fontSize.scale
        let pageBackground: String
        let pageText: String
        switch settings.theme {
        case .light:
            pageBackground = "#ffffff"
            pageText = "#0f172a"
        case .sepia:
            pageBackground = "#f4ecd8"
            pageText = "#3d3428"
        case .dark:
            pageBackground = "#2c2c2e"
            pageText = "#f5f5f7"
        }
        let pageWidth = layout?.pageWidth ?? Double(ManualPageLayout.width)
        let pageHeight = layout?.pageHeight ?? Double(ManualPageLayout.height)
        let scaledFontRules = layout == nil
            ? [8, 9, 10, 11, 12, 14, 16, 18, 24].map { size in
                let scaled = Double(size) * fontScale
                return ".mr-ios-frame [data-font-size=\"\(size)\"] { font-size: \(scaled)pt !important; }"
            }.joined(separator: "\n")
            : ""
        let frameFontRule = layout == nil ? "font-size: \(11 * fontScale)pt;" : ""

        return """
        <!DOCTYPE html>
        <html lang="en">
        <head>
          <meta charset="utf-8">
          <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
          \(stylesheetMarkup)
          <style>
            html, body, .mr-body, .mr-app, .mr-ios-shell, .mr-page-frame {
              margin: 0;
              padding: 0;
              width: 100%;
              height: 100%;
              max-height: none;
              overflow: hidden;
              background: \(pageBackground) !important;
              color: \(pageText);
            }
            .mr-ios-shell { display: flex; align-items: flex-start; justify-content: center; }
            .mr-page-frame { display: block; flex: none; }
            .mr-ios-frame {
              position: relative;
              width: \(pageWidth)px;
              height: \(pageHeight)px;
              transform-origin: top center;
              background: \(pageBackground);
            }
            .mr-ios-frame .cpb-sheet { margin: 0 auto; box-shadow: none !important; \(frameFontRule) }
            .reader-generated-page {
              margin: 0 !important;
              box-shadow: none !important;
              background: \(pageBackground) !important;
              color: \(pageText) !important;
            }
            .reader-page-header-region, .reader-page-body, .reader-page-footer-region { max-width: none !important; }
            .reader-generated-page .reader-page-body,
            .reader-generated-page .cpb-heading,
            .reader-generated-page .cpb-section-title,
            .reader-generated-page .cpb-paragraph,
            .reader-generated-page .cpb-list,
            .reader-generated-page .cpb-page-header-table td,
            .reader-generated-page .cpb-page-footer-table td {
              color: \(pageText) !important;
            }
            .reader-page-header-region > .cpb-page-header,
            .reader-page-footer-region > .cpb-page-footer {
              position: static !important;
              inset: auto !important;
              width: 100% !important;
              height: 100% !important;
              margin: 0 !important;
              box-sizing: border-box !important;
            }
            .reader-page-footer-region {
              display: flex;
              align-items: flex-end;
            }
            \(scaledFontRules)
            .mr-app .cpb-block-chrome, .mr-app .cpb-dropzone, .mr-app .cpb-change-marker, .mr-app .cpb-page-layout-toggle { display: none !important; }
          </style>
        </head>
        <body class="mr-body" data-mr-theme="\(theme)">
          <div class="mr-app mr-ios-shell">
            <div class="mr-page-frame \(zoomClass)">
              <div class="mr-ios-frame mr-page-scale">
                \(pageHtml)
              </div>
            </div>
          </div>
        </body>
        </html>
        """
    }
}
