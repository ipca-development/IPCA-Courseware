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
            canReviewManuals: nil,
            error: nil
        )
    }

    private func fetchSessionViaLibrary() async throws -> AuthSessionResponse {
        do {
            let library = try await fetchLibrary()
            if library.ok {
                return AuthSessionResponse(ok: true, loggedIn: true, user: nil, canReadManuals: true, canReviewManuals: nil, error: nil)
            }
        } catch ManualReaderAPIError.unauthorized {
            return AuthSessionResponse(ok: true, loggedIn: false, user: nil, canReadManuals: nil, canReviewManuals: nil, error: nil)
        } catch ManualReaderAPIError.forbidden {
            return AuthSessionResponse(ok: true, loggedIn: false, user: nil, canReadManuals: nil, canReviewManuals: nil, error: nil)
        } catch ManualReaderAPIError.invalidJSON {
            return AuthSessionResponse(ok: true, loggedIn: false, user: nil, canReadManuals: nil, canReviewManuals: nil, error: nil)
        }
        return AuthSessionResponse(ok: true, loggedIn: false, user: nil, canReadManuals: nil, canReviewManuals: nil, error: nil)
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

    func fetchPublicationPackage(
        bookKey: String,
        versionId: Int? = nil,
        isPreview: Bool = false
    ) async throws -> PublicationPackageResponse {
        try await get(
            "student/api/manual_reader_api.php",
            query: readerQuery(
                bookKey: bookKey,
                versionId: versionId,
                isPreview: isPreview,
                action: "publication_package"
            ),
            timeoutInterval: 90
        )
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

    func pullAnnotations(
        bookKey: String,
        versionId: Int
    ) async throws -> ReaderAnnotationSyncResponse {
        try await get(
            "student/api/manual_reader_api.php",
            query: [
                ("action", "annotations_pull"),
                ("book", bookKey),
                ("version_id", String(versionId)),
            ]
        )
    }

    func pushAnnotations(
        bookKey: String,
        versionId: Int,
        annotations: [[String: Any]]
    ) async throws -> ReaderAnnotationSyncResponse {
        try await postJSON(
            "student/api/manual_reader_api.php?action=annotations_push",
            body: [
                "book": bookKey,
                "version_id": versionId,
                "annotations": annotations,
            ]
        )
    }

    func fetchReviewThreads(
        bookKey: String,
        versionId: Int
    ) async throws -> ReviewThreadsResponse {
        try await get(
            "student/api/manual_reader_api.php",
            query: [
                ("action", "review_threads"),
                ("book", bookKey),
                ("version_id", String(versionId)),
            ]
        )
    }

    func createReviewThread(
        bookKey: String,
        versionId: Int,
        selection: ReaderTextSelection,
        pageNumber: Int,
        body text: String,
        threadUUID: UUID = UUID(),
        commentUUID: UUID = UUID()
    ) async throws -> ReviewNoteThread {
        let response: ReviewThreadsResponse = try await postJSON(
            "student/api/manual_reader_api.php?action=review_thread_create",
            body: [
                "book": bookKey,
                "version_id": versionId,
                "body": text,
                "anchor": [
                    "thread_uuid": threadUUID.uuidString,
                    "comment_uuid": commentUUID.uuidString,
                    "page_number": pageNumber,
                    "selected_text": selection.selectedText,
                    "source_fragment_id": selection.sourceFragmentID ?? "",
                    "stable_anchor": selection.stableAnchor ?? "",
                    "start_offset": selection.startOffset,
                    "end_offset": selection.endOffset,
                ],
            ]
        )
        guard let thread = response.thread else {
            throw ManualReaderAPIError.badResponse("Reviewer thread was not returned.")
        }
        return thread
    }

    func addReviewComment(
        bookKey: String,
        versionId: Int,
        threadUUID: String,
        body text: String,
        commentUUID: UUID = UUID()
    ) async throws -> ReviewNoteThread {
        let response: ReviewThreadsResponse = try await postJSON(
            "student/api/manual_reader_api.php?action=review_comment_add",
            body: [
                "book": bookKey,
                "version_id": versionId,
                "thread_uuid": threadUUID,
                "comment_uuid": commentUUID.uuidString,
                "body": text,
            ]
        )
        guard let thread = response.thread else {
            throw ManualReaderAPIError.badResponse("Reviewer thread was not returned.")
        }
        return thread
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
        if http.url?.path.hasSuffix("/login.php") == true {
            throw ManualReaderAPIError.unauthorized
        }
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
    /// Wrap canonical publication HTML without restyling its semantic DOM.
    func pageHTMLDocument(
        pageHtml: String,
        settings: ReaderSettings,
        bookStyleCSS: String,
        readerCSS: String,
        layout: PageLayoutConfiguration? = nil,
        publicationLayout: PublicationLayout,
        highlights: [TextHighlightAnchor] = [],
        searchTerm: String? = nil
    ) -> String {
        let safeBookStyleCSS = bookStyleCSS.replacingOccurrences(of: "</style", with: "<\\/style")
        let safeReaderCSS = readerCSS.replacingOccurrences(of: "</style", with: "<\\/style")
        let theme = ReaderTheme.original.rawValue
        let pageWidth = layout?.pageWidth ?? publicationLayout.pageWidthPX
        let pageHeight = layout?.pageHeight ?? publicationLayout.pageHeightPX
        let isValidatedPersonalPage = layout != nil
        let annotationScript = ReaderHTMLAnnotationService.script(
            highlights: highlights,
            searchTerm: searchTerm
        )

        return """
        <!DOCTYPE html>
        <html lang="en">
        <head>
          <meta charset="utf-8">
          <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=4, user-scalable=yes">
          <style id="book-style-css">\(safeBookStyleCSS)</style>
          <style id="reader-frame-css">\(safeReaderCSS)</style>
          <style>
            html, body, .mr-body, .mr-app, .mr-ios-shell, .mr-page-frame {
              margin: 0;
              padding: 0;
              width: 100%;
              height: 100%;
              max-height: none;
              overflow: hidden;
            }
            .mr-ios-shell { display: flex; align-items: flex-start; justify-content: center; }
            .mr-page-frame { display: block; flex: none; }
            .mr-body[data-reader-validated="1"],
            .mr-body[data-reader-validated="1"] .mr-app,
            .mr-body[data-reader-validated="1"] .mr-ios-shell,
            .mr-body[data-reader-validated="1"] .mr-page-frame,
            .mr-body[data-reader-validated="1"] .mr-ios-frame {
              overflow: visible;
            }
            .mr-ios-frame {
              position: relative;
              width: \(pageWidth)px;
              height: \(pageHeight)px;
              transform-origin: top left;
              -webkit-touch-callout: none;
              -webkit-user-select: text;
              user-select: text;
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
            .mr-app .cpb-block-chrome, .mr-app .cpb-dropzone, .mr-app .cpb-change-marker, .mr-app .cpb-page-layout-toggle { display: none !important; }
            .mr-user-highlight, .mr-search-hit {
              color: #000 !important;
              border-radius: 2px;
              box-decoration-break: clone;
              -webkit-box-decoration-break: clone;
            }
            .mr-search-hit { background: #fff34d !important; }
            .mr-user-highlight.is-noted {
              border-left: 4px solid #f4c430 !important;
              padding-left: 2px;
            }
          </style>
        </head>
        <body class="mr-body" data-mr-theme="\(theme)" data-reader-validated="\(isValidatedPersonalPage ? "1" : "0")">
          <div class="mr-app mr-ios-shell">
            <div class="mr-page-frame">
              <div class="mr-ios-frame mr-page-scale" data-layout-bound="\(isValidatedPersonalPage ? "1" : "0")">
                \(pageHtml)
              </div>
            </div>
          </div>
          \(annotationScript)
        </body>
        </html>
        """
    }
}

enum ReaderHTMLAnnotationService {
    static func script(highlights: [TextHighlightAnchor], searchTerm: String?) -> String {
        let payload: [[String: Any]] = highlights.map {
            [
                "text": $0.selectedText,
                "fragment": $0.sourceFragmentID ?? "",
                "anchor": $0.stableAnchor ?? "",
                "start": $0.startOffset,
                "color": $0.color.cssColor,
                "id": $0.id.uuidString,
                "noted": !($0.personalNote ?? "").isEmpty,
            ]
        }
        let payloadData = try? JSONSerialization.data(withJSONObject: payload)
        let payloadJSON = payloadData.flatMap { String(data: $0, encoding: .utf8) } ?? "[]"
        let searchData = try? JSONSerialization.data(withJSONObject: [searchTerm ?? ""])
        let searchJSON = searchData.flatMap { String(data: $0, encoding: .utf8) } ?? "[\"\"]"
        return """
        <script>
        (function() {
          const highlights = \(payloadJSON.replacingOccurrences(of: "</script", with: "<\\/script"));
          const searchTerm = \(searchJSON)[0];
          const root = document.querySelector('.mr-ios-frame') || document.body;
          const blocked = new Set(['SCRIPT', 'STYLE', 'MARK']);
          function textNodes(scope) {
            const walker = document.createTreeWalker(scope, NodeFilter.SHOW_TEXT, {
              acceptNode(node) {
                return node.nodeValue && node.nodeValue.length && !blocked.has(node.parentElement?.tagName)
                  ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
              }
            });
            const nodes = [];
            while (walker.nextNode()) nodes.push(walker.currentNode);
            return nodes;
          }
          function wrap(node, start, length, className, color, item) {
            if (!node || length <= 0) return;
            const range = document.createRange();
            range.setStart(node, start);
            range.setEnd(node, start + length);
            wrapRange(range, className, color, item);
          }
          function wrapRange(range, className, color, item) {
            const mark = document.createElement('mark');
            mark.className = className;
            if (color) mark.style.backgroundColor = color;
            if (item && item.id) mark.dataset.highlightId = item.id;
            if (item && item.noted) mark.classList.add('is-noted');
            try {
              range.surroundContents(mark);
            } catch (_) {
              const contents = range.extractContents();
              mark.appendChild(contents);
              range.insertNode(mark);
            }
          }
          function boundaryAt(nodes, offset) {
            let consumed = 0;
            for (const node of nodes) {
              const next = consumed + node.nodeValue.length;
              if (offset <= next) {
                return { node: node, offset: Math.max(0, offset - consumed) };
              }
              consumed = next;
            }
            const last = nodes[nodes.length - 1];
            return last ? { node: last, offset: last.nodeValue.length } : null;
          }
          function wrapGlobal(nodes, start, length, className, color, item) {
            const from = boundaryAt(nodes, start);
            const to = boundaryAt(nodes, start + length);
            if (!from || !to) return false;
            const range = document.createRange();
            range.setStart(from.node, from.offset);
            range.setEnd(to.node, to.offset);
            wrapRange(range, className, color, item);
            return true;
          }
          function highlightExact(item) {
            let scope = root;
            if (item.fragment) {
              const escaped = CSS.escape(item.fragment);
              scope = root.querySelector('[data-source-fragment-id="' + escaped + '"],'
                + '[data-fragment-id="' + escaped + '"],'
                + '[data-source-fragment="' + escaped + '"]') || scope;
            } else if (item.anchor) {
              scope = document.getElementById(item.anchor)
                || root.querySelector('[data-stable-anchor="' + CSS.escape(item.anchor) + '"]')
                || scope;
            }
            const nodes = textNodes(scope);
            const fullText = nodes.map(node => node.nodeValue).join('');
            if (item.start >= 0
                && fullText.substr(item.start, item.text.length) === item.text
                && wrapGlobal(
                  nodes, item.start, item.text.length, 'mr-user-highlight', item.color, item
                )) {
              return;
            }
            const found = fullText.indexOf(item.text);
            if (found >= 0) {
              wrapGlobal(nodes, found, item.text.length, 'mr-user-highlight', item.color, item);
            }
          }
          highlights.forEach(highlightExact);
          if (searchTerm && searchTerm.trim()) {
            const needle = searchTerm.toLocaleLowerCase();
            let count = 0;
            for (const node of textNodes(root)) {
              if (count >= 200) break;
              let offset = 0;
              while (count < 200) {
                const found = node.nodeValue.toLocaleLowerCase().indexOf(needle, offset);
                if (found < 0) break;
                wrap(node, found, searchTerm.length, 'mr-search-hit', null, null);
                count += 1;
                break;
              }
            }
          }
        })();
        </script>
        """
    }
}
