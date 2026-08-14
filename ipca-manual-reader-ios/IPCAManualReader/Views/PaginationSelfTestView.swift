#if DEBUG
import Darwin
import SwiftUI

struct PaginationSelfTestView: View {
    @StateObject private var runner = PaginationSelfTestRunner()

    var body: some View {
        VStack(spacing: 16) {
            ProgressView()
            Text(runner.status)
                .font(.system(.body, design: .monospaced))
                .multilineTextAlignment(.center)
        }
        .padding()
        .task { await runner.run() }
    }
}

@MainActor
private final class PaginationSelfTestRunner: ObservableObject {
    @Published var status = "Preparing deterministic pagination fixtures…"

    private let engine = HTMLPaginationEngine()

    func run() async {
        do {
            let source = try fixtureSource()
            let configurations = [
                ("ipad-landscape", CGSize(width: 1366, height: 1024), true),
                ("ipad-portrait", CGSize(width: 1024, height: 1366), false),
                ("iphone-portrait", CGSize(width: 393, height: 852), false)
            ]
            var standardCounts: [String: Int] = [:]

            for (name, viewport, landscape) in configurations {
                status = "Testing \(name)…"
                let standardLayout = PageLayoutConfiguration.make(
                    viewport: viewport,
                    isLandscape: landscape,
                    fontScale: ReaderFontSize.standard.scale
                )
                let standard = try await paginate(source: source, layout: standardLayout)
                try assertValid(standard, name: name)
                standardCounts[name] = standard.personalPages.count
                let repeated = try await paginate(source: source, layout: standardLayout)
                try assertValid(repeated, name: "\(name)-repeat")
                guard repeated.personalPages.map(\.pageHTML)
                    == standard.personalPages.map(\.pageHTML) else {
                    throw SelfTestError.failed("\(name): identical inputs produced different pages.")
                }

                let largeLayout = PageLayoutConfiguration.make(
                    viewport: viewport,
                    isLandscape: landscape,
                    fontScale: ReaderFontSize.large.scale
                )
                let large = try await paginate(source: source, layout: largeLayout)
                try assertValid(large, name: "\(name)-large")
                guard large.personalPages.count >= standard.personalPages.count else {
                    throw SelfTestError.failed(
                        "\(name): larger font unexpectedly reduced page count "
                            + "(\(standard.personalPages.count) → \(large.personalPages.count))."
                    )
                }
                if let location = standard.personalPages.dropFirst().first?.startLocation {
                    let restored = large.personalPages.contains { page in
                        page.coverage.contains {
                            $0.sourceFragmentID == location.sourceFragmentID
                                && !$0.presentationCopy
                                && $0.rangeStart <= location.characterOffset
                                && $0.rangeEnd >= location.characterOffset
                        }
                    }
                    guard restored else {
                        throw SelfTestError.failed(
                            "\(name): semantic location was lost after font re-pagination."
                        )
                    }
                }
            }

            let summary = configurations.compactMap { configuration in
                standardCounts[configuration.0].map { "\(configuration.0)=\($0)" }
            }.joined(separator: ",")
            status = "PASS \(summary)"
            print("PAGINATION_SELF_TEST_PASS \(summary)")
            fflush(stdout)
            exit(0)
        } catch {
            status = "FAIL: \(error.localizedDescription)"
            print("PAGINATION_SELF_TEST_FAIL \(error.localizedDescription)")
            fflush(stdout)
            exit(1)
        }
    }

    private func paginate(
        source: Data,
        layout: PageLayoutConfiguration
    ) async throws -> PersonalPaginationResult {
        try await engine.paginate(
            sourceData: source,
            contentCSS: fixtureCSS,
            readerCSS: "",
            layout: layout,
            baseURL: URL(string: "https://reader.invalid/")!,
            officialPageByAnchor: ["section-test": 42],
            officialPageBySection: [101: 42],
            officialPageTotal: 100
        )
    }

    private func assertValid(_ result: PersonalPaginationResult, name: String) throws {
        guard result.validation.isValid else {
            throw SelfTestError.failed("\(name): source coverage validation failed.")
        }
        guard !result.personalPages.isEmpty else {
            throw SelfTestError.failed("\(name): generated no reader pages.")
        }
        let failures = result.validation.diagnostics.filter { $0.severity == .failure }
        guard failures.isEmpty else {
            throw SelfTestError.failed(
                "\(name): " + failures.map(\.message).joined(separator: " | ")
            )
        }
        let emitted = result.personalPages.flatMap(\.coverage).filter { !$0.presentationCopy }
        guard emitted.map(\.sourceOrder) == emitted.map(\.sourceOrder).sorted() else {
            throw SelfTestError.failed("\(name): source order changed.")
        }
        guard result.personalPages.allSatisfy({
            (0...1).contains($0.metrics.contentUtilization)
                && (0...1).contains($0.metrics.whitespaceRatio)
        }) else {
            throw SelfTestError.failed("\(name): invalid deterministic page metrics.")
        }
        let contentPages = result.personalPages.filter { !$0.isCover }
        guard contentPages.allSatisfy({
            $0.startLocation?.officialLocation.officialPageNumber == 42
        }) else {
            throw SelfTestError.failed("\(name): official document location was conflated or lost.")
        }
    }

    private func fixtureSource() throws -> Data {
        let paragraph = Array(
            repeating: "This controlled manual paragraph verifies deterministic semantic pagination and preserves every source word in order.",
            count: 55
        ).joined(separator: " ")
        let longListItem = Array(
            repeating: "A long checklist item may continue safely without losing its marker or source order.",
            count: 28
        ).joined(separator: " ")
        let list = "<li>\(longListItem)</li>" + (2...18).map {
            "<li>Operational checklist item \($0) remains intact whenever it fits.</li>"
        }.joined()
        let oversizedNote = Array(
            repeating: "Oversized NOTE content must split only because the complete callout exceeds a fresh page body.",
            count: 70
        ).joined(separator: " ")
        let oversizedCell = Array(
            repeating: "An oversized technical table row splits only when it cannot fit a fresh body frame.",
            count: 55
        ).joined(separator: " ")
        let rows = "<tr><td>Oversized row</td><td>\(oversizedCell)</td></tr>" + (2...14).map {
            "<tr><td>Item \($0)</td><td>Required technical publication value \($0)</td></tr>"
        }.joined()
        let tocRows = (1...32).map {
            "<div class=\"cpb-toc-row\"><span>Section \($0)</span><span>\($0)</span></div>"
        }.joined()
        let svg = """
        <svg xmlns="http://www.w3.org/2000/svg" width="640" height="320">
          <rect width="640" height="320" fill="white"/>
          <path d="M20 260 L200 80 L380 220 L620 40" stroke="navy" fill="none" stroke-width="8"/>
        </svg>
        """.data(using: .utf8)!.base64EncodedString()

        let units: [[String: Any]] = [
            [
                "unit_key": "heading-1",
                "block_type": "heading",
                "html": "<article class=\"cpb-block cpb-block--heading\" data-stable-anchor=\"section-test\"><h2>6.1 Deterministic Pagination</h2></article>",
                "is_heading": true,
                "atomic": true
            ],
            [
                "unit_key": "paragraph-1",
                "block_type": "paragraph",
                "html": "<article class=\"cpb-block cpb-block--paragraph\" data-stable-anchor=\"paragraph-test\"><div class=\"cpb-paragraph\"><p>\(paragraph)</p></div></article>",
                "splittable": true
            ],
            [
                "unit_key": "note-1",
                "block_type": "callout",
                "html": "<article class=\"cpb-block cpb-block--callout note\" data-stable-anchor=\"note-test\"><strong>NOTE</strong><p>This note is atomic when it fits the available body frame.</p></article>",
                "atomic": true
            ],
            [
                "unit_key": "warning-1",
                "block_type": "callout",
                "html": "<article class=\"cpb-block cpb-block--callout warning\" data-stable-anchor=\"warning-test\"><strong>WARNING</strong><p>Warning content remains atomic when it fits.</p></article>",
                "atomic": true
            ],
            [
                "unit_key": "caution-1",
                "block_type": "callout",
                "html": "<article class=\"cpb-block cpb-block--callout caution\" data-stable-anchor=\"caution-test\"><strong>CAUTION</strong><p>Caution content remains atomic when it fits.</p></article>",
                "atomic": true
            ],
            [
                "unit_key": "oversized-note-1",
                "block_type": "callout",
                "html": "<article class=\"cpb-block cpb-block--callout note\" data-stable-anchor=\"oversized-note-test\"><strong>NOTE</strong><p>\(oversizedNote)</p></article>",
                "atomic": true
            ],
            [
                "unit_key": "heading-2",
                "block_type": "heading",
                "html": "<article class=\"cpb-block cpb-block--heading\" data-stable-anchor=\"section-test-nested\"><h3>6.1.1 Nested Heading</h3></article>",
                "is_heading": true,
                "atomic": true
            ],
            [
                "unit_key": "paragraph-2",
                "block_type": "paragraph",
                "html": "<article class=\"cpb-block cpb-block--paragraph\" data-stable-anchor=\"paragraph-test-short\"><div class=\"cpb-paragraph\"><p>The nested heading remains attached to this meaningful following paragraph.</p></div></article>",
                "splittable": true
            ],
            [
                "unit_key": "list-1",
                "block_type": "list",
                "html": "<article class=\"cpb-block cpb-block--list\" data-stable-anchor=\"list-test\"><ol start=\"3\">\(list)</ol></article>",
                "atomic": true
            ],
            [
                "unit_key": "table-1",
                "block_type": "table",
                "html": "<article class=\"cpb-block cpb-block--table\" data-stable-anchor=\"table-test\"><table><colgroup><col style=\"width:30%\"><col style=\"width:70%\"></colgroup><thead><tr><th>Reference</th><th>Requirement</th></tr></thead><tbody>\(rows)</tbody></table></article>",
                "atomic": true
            ],
            [
                "unit_key": "figure-1",
                "block_type": "image",
                "html": "<article class=\"cpb-block cpb-block--image\" data-stable-anchor=\"figure-test\"><figure><img alt=\"Test diagram\" src=\"data:image/svg+xml;base64,\(svg)\"><figcaption>Figure 1 — Image and caption remain together.</figcaption></figure></article>",
                "atomic": true
            ],
            [
                "unit_key": "historical-shell-1",
                "block_type": "shell",
                "html": "<div class=\"legacy-manual-content\"><h4>6.1.2 Historical Markup</h4><p>Historical container markup is normalized read-only.</p><ul><li>Legacy bullet one</li><li>Legacy bullet two</li></ul></div>",
                "atomic": true
            ],
            [
                "unit_key": "toc-1",
                "block_type": "toc",
                "html": "<h2>Table of Contents</h2><nav class=\"cpb-toc\">\(tocRows)</nav>",
                "atomic": true
            ]
        ]
        let source: [String: Any] = [
            "version_id": 1,
            "layout": ["body_capacity_px": 744],
            "sections": [
                [
                    "section_id": 100,
                    "section_key": "cover",
                    "title": "Cover",
                    "stable_anchor": "cover-test",
                    "content_mode": "cover",
                    "show_header_footer": false,
                    "cover_html": "<div class=\"cpb-cover\" style=\"height:100%;display:flex;align-items:center;justify-content:center\"><h1>IPCA Test Manual</h1></div>",
                    "flags": [
                        "is_section_start": true,
                        "is_major_section_start": true,
                        "force_page_break_before": true,
                        "is_cover": true
                    ],
                    "units": []
                ],
                [
                "section_id": 101,
                "section_key": "part_1",
                "title": "Deterministic Pagination",
                "stable_anchor": "section-test",
                "content_mode": "units",
                "show_header_footer": true,
                "header_template": "<header class=\"cpb-page-header\"><strong>IPCA Test Manual</strong></header>",
                "footer_template": "<footer class=\"cpb-page-footer\">Copyright IPCA · Page: —</footer>",
                "flags": [
                    "is_section_start": true,
                    "is_major_section_start": true,
                    "force_page_break_before": true
                ],
                "units": units
                ]
            ]
        ]
        return try JSONSerialization.data(withJSONObject: source, options: [.sortedKeys])
    }

    private var fixtureCSS: String {
        let productionCSS = Bundle.main.url(
            forResource: "manual_reader_content",
            withExtension: "css"
        ).flatMap { try? String(contentsOf: $0, encoding: .utf8) } ?? ""
        return productionCSS + """

        /* Synthetic fixture supplements; production classes use the stylesheet above. */
        * { box-sizing: border-box; }
        body { font-family: Georgia, serif; line-height: 1.45; }
        .cpb-block { margin: 0 0 10px; }
        h2 { margin: 0 0 8px; font-size: 18pt; line-height: 1.2; }
        p { margin: 0 0 8px; }
        ol, ul { margin: 0; padding-left: 28px; }
        li { margin: 0 0 5px; }
        .cpb-block--callout { border: 1px solid #1f2937; padding: 10px; }
        table { border-collapse: collapse; width: 100%; table-layout: fixed; }
        th, td { border: 1px solid #6b7280; padding: 5px; vertical-align: top; }
        figure { margin: 0; }
        figcaption { margin-top: 6px; font-size: 9pt; }
        .cpb-page-header, .cpb-page-footer { width: 100%; height: 100%; }
        """
    }
}

private enum SelfTestError: LocalizedError {
    case failed(String)

    var errorDescription: String? {
        switch self {
        case .failed(let message): message
        }
    }
}
#endif
