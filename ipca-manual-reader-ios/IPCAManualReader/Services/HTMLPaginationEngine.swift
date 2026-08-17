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
                + diagnostics.filter { $0.severity == .failure }.prefix(6)
                    .map {
                        "\($0.code) page=\($0.pageNumber.map(String.init) ?? "-") "
                            + "fragment=\($0.sourceFragmentID ?? "-"): \($0.message)"
                    }
                    .joined(separator: " | ")
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
        bookStyleCSS: String,
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
            bookStyleCSS: bookStyleCSS,
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

    /// Paginate one source section at a time so a full manual never needs one
    /// document-sized WKWebView. Section boundaries are already hard page
    /// boundaries in ReaderPaginationCore.
    func paginateBySection(
        sourceData: Data,
        bookStyleCSS: String,
        readerCSS: String,
        layout: PageLayoutConfiguration,
        baseURL: URL,
        officialPageByAnchor: [String: Int] = [:],
        officialPageBySection: [Int: Int] = [:],
        officialPageTotal: Int? = nil,
        rewriteString: (String) -> String
    ) async throws -> PersonalPaginationResult {
        guard var source = try JSONSerialization.jsonObject(with: sourceData) as? [String: Any],
              let sections = source["sections"] as? [[String: Any]],
              !sections.isEmpty else {
            throw HTMLPaginationError.invalidSource
        }
        var pages: [PersonalReaderPage] = []
        var sectionPageIndex: [Int: Int] = [:]
        var diagnostics: [PaginationDiagnostic] = []
        var sourceOrderOffset = 0
        var sourceFragmentCount = 0
        var coveredCount = 0
        var normalizerVersion = ReaderPaginationVersion.normalizer
        var engineVersion = ReaderPaginationVersion.engine

        func rewritten(_ value: Any) -> Any {
            if let string = value as? String { return rewriteString(string) }
            if let array = value as? [Any] { return array.map(rewritten) }
            if let dictionary = value as? [String: Any] {
                return dictionary.mapValues(rewritten)
            }
            return value
        }

        for section in sections {
            source["sections"] = [rewritten(section)]
            let chunkData = try JSONSerialization.data(
                withJSONObject: source,
                options: [.sortedKeys]
            )
            let chunk: PersonalPaginationResult
            do {
                chunk = try await paginate(
                    sourceData: chunkData,
                    bookStyleCSS: bookStyleCSS,
                    readerCSS: readerCSS,
                    layout: layout,
                    baseURL: baseURL,
                    officialPageByAnchor: officialPageByAnchor,
                    officialPageBySection: officialPageBySection,
                    officialPageTotal: officialPageTotal
                )
            } catch HTMLPaginationError.validationFailed(let failures) {
                let sectionID = (section["section_id"] as? NSNumber)?.intValue ?? 0
                let detail = failures.filter { $0.severity == .failure }.prefix(6).map {
                    "\($0.code) page=\($0.pageNumber.map(String.init) ?? "-") "
                        + "fragment=\($0.sourceFragmentID ?? "-"): \($0.message)"
                }.joined(separator: " | ")
                throw HTMLPaginationError.failed("Section \(sectionID) failed: \(detail)")
            }
            let pageOffset = pages.count
            pages.append(contentsOf: chunk.personalPages.map {
                shiftedPage($0, pageOffset: pageOffset, sourceOffset: sourceOrderOffset)
            })
            chunk.sectionPageIndex.forEach { sectionID, pageNumber in
                sectionPageIndex[sectionID] = pageNumber + pageOffset
            }
            diagnostics.append(contentsOf: chunk.validation.diagnostics.map {
                shiftedDiagnostic($0, pageOffset: pageOffset)
            })
            let maximumChunkOrder = chunk.personalPages.flatMap(\.coverage)
                .filter { !$0.presentationCopy }
                .map(\.sourceOrder)
                .max() ?? -1
            sourceOrderOffset += maximumChunkOrder + 1
            sourceFragmentCount += chunk.validation.sourceFragmentCount
            coveredCount += chunk.validation.coveredFragmentCount
            normalizerVersion = chunk.normalizerVersion
            engineVersion = chunk.engineVersion
            await Task.yield()
        }

        let emitted = pages.flatMap(\.coverage).filter { !$0.presentationCopy }
        if emitted.map(\.sourceOrder) != emitted.map(\.sourceOrder).sorted() {
            diagnostics.append(
                PaginationDiagnostic(
                    code: "SOURCE_ORDER_CHANGED",
                    severity: .failure,
                    pageNumber: nil,
                    sourceFragmentID: nil,
                    message: "Section-chunk pagination changed global source order."
                )
            )
        }
        let uniqueIDs = Set(emitted.map(\.sourceFragmentID))
        if uniqueIDs.count != coveredCount {
            let grouped = Dictionary(grouping: emitted, by: \.sourceFragmentID)
            let duplicateIDs = grouped.filter { Set($0.value.map(\.sourceOrder)).count > 1 }
                .keys.sorted().prefix(5).joined(separator: ",")
            diagnostics.append(
                PaginationDiagnostic(
                    code: "SOURCE_FRAGMENT_DUPLICATED",
                    severity: .failure,
                    pageNumber: nil,
                    sourceFragmentID: nil,
                    message: "Section-chunk pagination emitted duplicate source fragment identities "
                        + "(\(uniqueIDs.count) unique vs \(coveredCount) covered; "
                        + "examples: \(duplicateIDs))."
                )
            )
        }
        let validation = PaginationValidationSummary(
            isValid: diagnostics.allSatisfy { $0.severity != .failure },
            sourceFragmentCount: sourceFragmentCount,
            coveredFragmentCount: coveredCount,
            diagnostics: diagnostics
        )
        guard validation.isValid, pages.allSatisfy(\.metrics.validationPassed) else {
            throw HTMLPaginationError.validationFailed(diagnostics)
        }
        return PersonalPaginationResult(
            personalPages: pages,
            sectionPageIndex: sectionPageIndex,
            validation: validation,
            normalizerVersion: normalizerVersion,
            engineVersion: engineVersion,
            layout: layout
        )
    }

    private func shiftedPage(
        _ page: PersonalReaderPage,
        pageOffset: Int,
        sourceOffset: Int
    ) -> PersonalReaderPage {
        let number = page.pageNumber + pageOffset
        return PersonalReaderPage(
            pageNumber: number,
            pageHTML: page.pageHTML.replacingOccurrences(
                of: "data-reader-page=\"\(page.pageNumber)\"",
                with: "data-reader-page=\"\(number)\""
            ),
            sectionID: page.sectionID,
            sectionTitle: page.sectionTitle,
            isCover: page.isCover,
            isSectionStart: page.isSectionStart,
            isMajorSectionStart: page.isMajorSectionStart,
            startLocation: shiftedLocation(page.startLocation, by: sourceOffset),
            endLocation: shiftedLocation(page.endLocation, by: sourceOffset),
            officialLocations: page.officialLocations,
            coverage: page.coverage.map {
                SourceFragmentCoverage(
                    sourceFragmentID: $0.sourceFragmentID,
                    sourceOrder: $0.sourceOrder + sourceOffset,
                    rangeStart: $0.rangeStart,
                    rangeEnd: $0.rangeEnd,
                    sourceLength: $0.sourceLength,
                    presentationCopy: $0.presentationCopy
                )
            },
            diagnostics: page.diagnostics.map {
                shiftedDiagnostic($0, pageOffset: pageOffset)
            },
            metrics: page.metrics
        )
    }

    private func shiftedLocation(
        _ location: SemanticReaderLocation?,
        by sourceOffset: Int
    ) -> SemanticReaderLocation? {
        guard let location else { return nil }
        return SemanticReaderLocation(
            sourceFragmentID: location.sourceFragmentID,
            semanticAnchor: location.semanticAnchor,
            sourceOrder: location.sourceOrder + sourceOffset,
            characterOffset: location.characterOffset,
            officialLocation: location.officialLocation
        )
    }

    private func shiftedDiagnostic(
        _ diagnostic: PaginationDiagnostic,
        pageOffset: Int
    ) -> PaginationDiagnostic {
        PaginationDiagnostic(
            code: diagnostic.code,
            severity: diagnostic.severity,
            pageNumber: diagnostic.pageNumber.map { $0 + pageOffset },
            sourceFragmentID: diagnostic.sourceFragmentID,
            message: diagnostic.message
        )
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
        bookStyleCSS: String,
        readerCSS: String,
        engineSource: String,
        officialPageByAnchor: [String: Int],
        officialPageBySection: [Int: Int],
        officialPageTotal: Int?
    ) -> String {
        let safeBookStyleCSS = bookStyleCSS.replacingOccurrences(of: "</style", with: "<\\/style")
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
          \(safeBookStyleCSS)
          \(safeReaderCSS)
          html, body {
            margin: 0;
            padding: 0;
            width: \(layoutJSONValue(layoutJSON, key: "pageWidth"))px;
            min-height: \(layoutJSONValue(layoutJSON, key: "pageHeight"))px;
          }
          #pagination-measure-host {
            position: absolute;
            inset: 0 auto auto 0;
            visibility: hidden;
            pointer-events: none;
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
          .reader-page-header-region > .cpb-page-header,
          .reader-page-footer-region > .cpb-page-footer {
            position: static !important;
            inset: auto !important;
            width: 100% !important;
            height: 100% !important;
            margin: 0 !important;
            box-sizing: border-box !important;
          }
          .reader-page-header-region > .cpb-page-header > .cpb-page-header-table,
          .reader-page-footer-region > .cpb-page-footer > .cpb-page-footer-table,
          .reader-page-header-region > .cpb-page-header > .cpb-page-header-table > tbody,
          .reader-page-footer-region > .cpb-page-footer > .cpb-page-footer-table > tbody,
          .reader-page-header-region > .cpb-page-header > .cpb-page-header-table > tbody > tr,
          .reader-page-footer-region > .cpb-page-footer > .cpb-page-footer-table > tbody > tr,
          .reader-page-header-region > .cpb-page-header > .cpb-page-header-table > tbody > tr > td,
          .reader-page-footer-region > .cpb-page-footer > .cpb-page-footer-table > tbody > tr > td {
            height: 100% !important;
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
