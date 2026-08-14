import Foundation
import WebKit

enum HTMLPaginationError: LocalizedError {
    case invalidSource
    case missingEngineResource
    case validationFailed([PaginationDiagnostic])
    case failed(String)

    var errorDescription: String? {
        switch self {
        case .invalidSource:
            "The manual pagination source is invalid."
        case .missingEngineResource:
            "The versioned reader pagination engine is missing from the app bundle."
        case .validationFailed(let diagnostics):
            "Pagination failed source-integrity validation: "
                + diagnostics.prefix(4).map(\.message).joined(separator: " | ")
        case .failed(let message):
            "Unable to paginate the manual: \(message)"
        }
    }
}

@MainActor
final class HTMLPaginationEngine: NSObject, WKNavigationDelegate, WKScriptMessageHandler {
    private var webView: WKWebView?
    private var continuation: CheckedContinuation<PersonalPaginationResult, Error>?
    private var activeLayout: PageLayoutConfiguration?

    func paginate(
        sourceData: Data,
        contentCSS: String,
        readerCSS: String,
        layout: PageLayoutConfiguration,
        baseURL: URL,
        officialPageByAnchor: [String: Int] = [:],
        officialPageBySection: [Int: Int] = [:],
        officialPageTotal: Int? = nil
    ) async throws -> PersonalPaginationResult {
        guard continuation == nil else {
            throw HTMLPaginationError.failed("Another pagination operation is still running.")
        }
        guard JSONSerialization.isValidJSONObject(
            try JSONSerialization.jsonObject(with: sourceData)
        ) else {
            throw HTMLPaginationError.invalidSource
        }
        guard let engineURL = Bundle(for: HTMLPaginationEngine.self).url(
            forResource: "ReaderPaginationCore",
            withExtension: "js"
        ), let engineSource = try? String(contentsOf: engineURL, encoding: .utf8) else {
            throw HTMLPaginationError.missingEngineResource
        }

        let configuration = WKWebViewConfiguration()
        configuration.defaultWebpagePreferences.allowsContentJavaScript = true
        configuration.userContentController.add(self, name: "pagination")
        let webView = WKWebView(
            frame: CGRect(
                x: -CGFloat(layout.pageWidth * 2),
                y: 0,
                width: CGFloat(layout.pageWidth),
                height: CGFloat(layout.pageHeight)
            ),
            configuration: configuration
        )
        webView.navigationDelegate = self
        webView.isHidden = false
        webView.alpha = 0.01
        webView.isUserInteractionEnabled = false
        self.webView = webView
        activeLayout = layout
        measurementWindow()?.addSubview(webView)

        let sourceBase64 = sourceData.base64EncodedString()
        let layoutData = try JSONEncoder().encode(layout)
        guard let layoutJSON = String(data: layoutData, encoding: .utf8) else {
            throw HTMLPaginationError.invalidSource
        }
        let html = paginationDocument(
            sourceBase64: sourceBase64,
            layoutJSON: layoutJSON,
            contentCSS: contentCSS,
            readerCSS: readerCSS,
            engineSource: engineSource,
            officialPageByAnchor: officialPageByAnchor,
            officialPageBySection: officialPageBySection,
            officialPageTotal: officialPageTotal
        )

        return try await withCheckedThrowingContinuation { continuation in
            self.continuation = continuation
            webView.loadHTMLString(html, baseURL: baseURL)
        }
    }

    func userContentController(
        _ userContentController: WKUserContentController,
        didReceive message: WKScriptMessage
    ) {
        guard message.name == "pagination", let continuation, let activeLayout else { return }
        self.continuation = nil
        defer { tearDown() }

        do {
            guard JSONSerialization.isValidJSONObject(message.body) else {
                throw HTMLPaginationError.invalidSource
            }
            let data = try JSONSerialization.data(withJSONObject: message.body)
            let response = try JSONDecoder().decode(PaginationResponse.self, from: data)
            if let error = response.error {
                let context = response.validation.diagnostics
                    .suffix(10)
                    .map(\.message)
                    .joined(separator: " | ")
                throw HTMLPaginationError.failed(
                    context.isEmpty ? error : error + " | " + context
                )
            }
            guard response.validation.isValid else {
                throw HTMLPaginationError.validationFailed(response.validation.diagnostics)
            }
            let sectionIndex = response.sectionPageIndex.reduce(into: [Int: Int]()) { output, pair in
                if let sectionID = Int(pair.key) {
                    output[sectionID] = pair.value
                }
            }
            continuation.resume(
                returning: PersonalPaginationResult(
                    personalPages: response.pages,
                    sectionPageIndex: sectionIndex,
                    validation: response.validation,
                    normalizerVersion: response.normalizerVersion,
                    engineVersion: response.engineVersion,
                    layout: activeLayout
                )
            )
        } catch {
            continuation.resume(throwing: error)
        }
    }

    func webView(
        _ webView: WKWebView,
        didFail navigation: WKNavigation!,
        withError error: Error
    ) {
        fail(error)
    }

    func webView(
        _ webView: WKWebView,
        didFailProvisionalNavigation navigation: WKNavigation!,
        withError error: Error
    ) {
        fail(error)
    }

    private func fail(_ error: Error) {
        guard let continuation else { return }
        self.continuation = nil
        continuation.resume(throwing: error)
        tearDown()
    }

    private func tearDown() {
        webView?.configuration.userContentController.removeScriptMessageHandler(forName: "pagination")
        webView?.removeFromSuperview()
        webView = nil
        activeLayout = nil
    }

    private func measurementWindow() -> UIWindow? {
        UIApplication.shared.connectedScenes
            .compactMap { $0 as? UIWindowScene }
            .flatMap(\.windows)
            .first { $0.isKeyWindow }
    }

    private func paginationDocument(
        sourceBase64: String,
        layoutJSON: String,
        contentCSS: String,
        readerCSS: String,
        engineSource: String,
        officialPageByAnchor: [String: Int],
        officialPageBySection: [Int: Int],
        officialPageTotal: Int?
    ) -> String {
        let safeContentCSS = contentCSS.replacingOccurrences(of: "</style", with: "<\\/style")
        let safeReaderCSS = readerCSS.replacingOccurrences(of: "</style", with: "<\\/style")
        let safeEngine = engineSource.replacingOccurrences(of: "</script", with: "<\\/script")
        let anchorJSON = jsonString(officialPageByAnchor)
        let sectionJSON = jsonString(
            Dictionary(uniqueKeysWithValues: officialPageBySection.map { (String($0.key), $0.value) })
        )
        return """
        <!doctype html>
        <html>
        <head>
          <meta charset="utf-8">
          <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
          <style>
          \(safeContentCSS)
          \(safeReaderCSS)
          html, body {
            margin: 0;
            padding: 0;
            width: \(layoutJSONValue(layoutJSON, key: "pageWidth"))px;
            min-height: \(layoutJSONValue(layoutJSON, key: "pageHeight"))px;
            background: #fff;
          }
          #pagination-measure-host {
            position: absolute;
            inset: 0 auto auto 0;
            visibility: hidden;
            pointer-events: none;
          }
          .reader-generated-page .cpb-sheet,
          .reader-generated-page .cpb-block {
            max-width: 100% !important;
          }
          .reader-generated-page .reader-page-header-region > .cpb-page-header,
          .reader-generated-page .reader-page-footer-region > .cpb-page-footer {
            position: static !important;
            inset: auto !important;
            width: 100% !important;
            height: 100% !important;
            margin: 0 !important;
            box-sizing: border-box !important;
          }
          .reader-semantic-piece {
            break-inside: avoid;
            max-width: 100%;
          }
          .reader-semantic-piece table {
            width: 100%;
            max-width: 100%;
            table-layout: fixed;
          }
          .reader-semantic-piece img {
            max-width: 100%;
            height: auto;
          }
          </style>
        </head>
        <body>
          <div id="pagination-measure-host"></div>
          <script>
          window.IPCAPaginationInput = {
            source: JSON.parse(decodeURIComponent(escape(atob('\(sourceBase64)')))),
            layout: \(layoutJSON),
            officialPageByAnchor: \(anchorJSON),
            officialPageBySection: \(sectionJSON),
            officialPageTotal: \(officialPageTotal.map(String.init) ?? "null")
          };
          </script>
          <script>\(safeEngine)</script>
        </body>
        </html>
        """
    }

    private func layoutJSONValue(_ json: String, key: String) -> String {
        guard let data = json.data(using: .utf8),
              let object = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
              let number = object[key] as? NSNumber else {
            return "1"
        }
        return number.stringValue
    }

    private func jsonString<T: Encodable>(_ value: T) -> String {
        guard let data = try? JSONEncoder().encode(value),
              let json = String(data: data, encoding: .utf8) else {
            return "{}"
        }
        return json
    }
}

private struct PaginationResponse: Decodable {
    let pages: [PersonalReaderPage]
    let sectionPageIndex: [String: Int]
    let validation: PaginationValidationSummary
    let normalizerVersion: String
    let engineVersion: String
    let error: String?

    enum CodingKeys: String, CodingKey {
        case pages
        case sectionPageIndex = "section_page_index"
        case validation
        case normalizerVersion = "normalizer_version"
        case engineVersion = "engine_version"
        case error
    }
}
