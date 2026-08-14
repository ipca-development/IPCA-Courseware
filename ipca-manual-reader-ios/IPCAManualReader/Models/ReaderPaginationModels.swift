import CoreGraphics
import Foundation

enum ReaderPaginationVersion {
    static let normalizer = "reader-normalizer-v1"
    static let engine = "semantic-paginator-v2"
    static let validator = "pagination-validator-v1"
    static let style = "reader-page-frame-v2"
}

enum ReaderLayoutMode: String, Codable, Hashable {
    case singlePage
    case twoPageSpread
}

struct ReaderRect: Codable, Hashable {
    let x: Double
    let y: Double
    let width: Double
    let height: Double

    var maxX: Double { x + width }
    var maxY: Double { y + height }
}

/// One authoritative geometry contract shared by measurement, assembly, display,
/// caching, and QA. Values are CSS points inside the final page WKWebView.
struct PageLayoutConfiguration: Codable, Hashable {
    let pageWidth: Double
    let pageHeight: Double
    let canonicalPageWidth: Double
    let canonicalPageHeight: Double
    let headerFrame: ReaderRect
    let contentFrame: ReaderRect
    let footerFrame: ReaderRect
    let innerMargin: Double
    let outerMargin: Double
    let topMargin: Double
    let bottomMargin: Double
    let viewportWidth: Double
    let viewportHeight: Double
    let mode: ReaderLayoutMode
    let fontScale: Double
    let layoutVersion: String

    static func make(
        viewport: CGSize,
        isLandscape: Bool,
        fontScale: Double,
        publicationLayout: PublicationLayout,
        manifestLayoutHash: String
    ) -> PageLayoutConfiguration {
        let safeWidth = max(320, Double(viewport.width))
        let safeHeight = max(320, Double(viewport.height))
        let canonicalWidth = max(1, publicationLayout.pageWidthPX)
        let canonicalHeight = max(1, publicationLayout.pageHeightPX)
        let pageAspect = canonicalWidth / canonicalHeight
        let spreadGutter = isLandscape ? 6.0 : 0.0
        let pageSlotWidth = (safeWidth - spreadGutter) / (isLandscape ? 2.0 : 1.0)
        let availableHeight = safeHeight
        let pageWidth = max(1, min(pageSlotWidth, availableHeight * pageAspect))
        let pageHeight = pageWidth / pageAspect
        let scale = pageWidth / canonicalWidth

        let sideMargin = publicationLayout.sheetPaddingXPX * scale
        let topMargin = publicationLayout.sheetPaddingTopPX * scale
        let bottomMargin = publicationLayout.sheetPaddingBottomPX * scale
        let headerHeight = publicationLayout.headerBandPX * scale
        let headerGap = publicationLayout.headerMarginBottomPX * scale
        let footerGap = publicationLayout.footerMarginTopPX * scale
        let footerHeight = publicationLayout.footerBandPX * scale
        let contentY = topMargin + headerHeight + headerGap
        let contentHeight = max(1, publicationLayout.bodyCapacityPX * scale)

        return PageLayoutConfiguration(
            pageWidth: pageWidth,
            pageHeight: pageHeight,
            canonicalPageWidth: canonicalWidth,
            canonicalPageHeight: canonicalHeight,
            headerFrame: ReaderRect(
                x: sideMargin,
                y: topMargin,
                width: pageWidth - (sideMargin * 2),
                height: headerHeight
            ),
            contentFrame: ReaderRect(
                x: sideMargin,
                y: contentY,
                width: pageWidth - (sideMargin * 2),
                height: contentHeight
            ),
            footerFrame: ReaderRect(
                x: sideMargin,
                y: contentY + contentHeight + footerGap,
                width: pageWidth - (sideMargin * 2),
                height: footerHeight
            ),
            innerMargin: sideMargin,
            outerMargin: sideMargin,
            topMargin: topMargin,
            bottomMargin: bottomMargin,
            viewportWidth: safeWidth,
            viewportHeight: safeHeight,
            mode: isLandscape ? .twoPageSpread : .singlePage,
            fontScale: max(0.75, min(1.5, fontScale)),
            layoutVersion: "\(ReaderPaginationVersion.style):\(manifestLayoutHash)"
        )
    }

    var cacheIdentity: String {
        [
            layoutVersion,
            mode.rawValue,
            decimal(pageWidth),
            decimal(pageHeight),
            decimal(contentFrame.width),
            decimal(contentFrame.height),
            decimal(fontScale)
        ].joined(separator: ":")
    }

    private func decimal(_ value: Double) -> String {
        String(format: "%.3f", value)
    }
}

/// Controlled-document identity. It never means a generated reader page.
struct OfficialDocumentLocation: Codable, Hashable {
    let sectionID: Int?
    let stableAnchor: String?
    let officialPageNumber: Int?

    enum CodingKeys: String, CodingKey {
        case sectionID = "section_id"
        case stableAnchor = "stable_anchor"
        case officialPageNumber = "official_page_number"
    }
}

/// Logical source position used to survive re-pagination and rotation.
struct SemanticReaderLocation: Codable, Hashable {
    let sourceFragmentID: String
    let semanticAnchor: String
    let sourceOrder: Int
    let characterOffset: Int
    let officialLocation: OfficialDocumentLocation

    enum CodingKeys: String, CodingKey {
        case sourceFragmentID = "source_fragment_id"
        case semanticAnchor = "semantic_anchor"
        case sourceOrder = "source_order"
        case characterOffset = "character_offset"
        case officialLocation = "official_location"
    }
}

struct SourceFragmentCoverage: Codable, Hashable {
    let sourceFragmentID: String
    let sourceOrder: Int
    let rangeStart: Int
    let rangeEnd: Int
    let sourceLength: Int
    let presentationCopy: Bool

    enum CodingKeys: String, CodingKey {
        case sourceFragmentID = "source_fragment_id"
        case sourceOrder = "source_order"
        case rangeStart = "range_start"
        case rangeEnd = "range_end"
        case sourceLength = "source_length"
        case presentationCopy = "presentation_copy"
    }
}

struct PaginationDiagnostic: Codable, Hashable {
    enum Severity: String, Codable {
        case info
        case warning
        case failure
    }

    let code: String
    let severity: Severity
    let pageNumber: Int?
    let sourceFragmentID: String?
    let message: String

    enum CodingKeys: String, CodingKey {
        case code
        case severity
        case pageNumber = "page_number"
        case sourceFragmentID = "source_fragment_id"
        case message
    }
}

struct SemanticBlockMeasurement: Codable, Hashable {
    let sourceFragmentID: String?
    let semanticType: String
    let frame: ReaderRect

    enum CodingKeys: String, CodingKey {
        case sourceFragmentID = "source_fragment_id"
        case semanticType = "semantic_type"
        case frame
    }
}

struct ReaderPageMetrics: Codable, Hashable {
    let contentUtilization: Double
    let whitespaceRatio: Double
    let pageDensity: Double
    let distanceFromLastBlockToFooter: Double
    let headingCount: Int
    let hasComplexTable: Bool
    let hasFigure: Bool
    let priorPageUtilization: Double?
    let priorPageNearCapacity: Bool?
    let forcedBreakBefore: Bool?
    let breakReason: String?
    let blockMeasurements: [SemanticBlockMeasurement]

    enum CodingKeys: String, CodingKey {
        case contentUtilization = "content_utilization"
        case whitespaceRatio = "whitespace_ratio"
        case pageDensity = "page_density"
        case distanceFromLastBlockToFooter = "distance_from_last_block_to_footer"
        case headingCount = "heading_count"
        case hasComplexTable = "has_complex_table"
        case hasFigure = "has_figure"
        case priorPageUtilization = "prior_page_utilization"
        case priorPageNearCapacity = "prior_page_near_capacity"
        case forcedBreakBefore = "forced_break_before"
        case breakReason = "break_reason"
        case blockMeasurements = "block_measurements"
    }
}

/// Dynamically generated personal page. It is deliberately distinct from
/// FrozenPageMeta and OfficialDocumentLocation.
struct PersonalReaderPage: Codable, Identifiable, Hashable {
    let pageNumber: Int
    let pageHTML: String
    let sectionID: Int?
    let sectionTitle: String?
    let isCover: Bool
    let isSectionStart: Bool
    let isMajorSectionStart: Bool
    let startLocation: SemanticReaderLocation?
    let endLocation: SemanticReaderLocation?
    let officialLocations: [OfficialDocumentLocation]
    let coverage: [SourceFragmentCoverage]
    let diagnostics: [PaginationDiagnostic]
    let metrics: ReaderPageMetrics

    var id: Int { pageNumber }

    enum CodingKeys: String, CodingKey {
        case pageNumber = "page_number"
        case pageHTML = "page_html"
        case sectionID = "section_id"
        case sectionTitle = "section_title"
        case isCover = "is_cover"
        case isSectionStart = "is_section_start"
        case isMajorSectionStart = "is_major_section_start"
        case startLocation = "start_location"
        case endLocation = "end_location"
        case officialLocations = "official_locations"
        case coverage
        case diagnostics
        case metrics
    }

    var frozenMeta: FrozenPageMeta {
        FrozenPageMeta(
            pageNumber: pageNumber,
            sectionId: sectionID,
            stableAnchor: startLocation?.officialLocation.stableAnchor,
            pageType: isCover ? "cover" : "content",
            isCover: isCover,
            isSectionStart: isSectionStart,
            isMajorSectionStart: isMajorSectionStart,
            sectionTitle: sectionTitle
        )
    }
}

struct PaginationValidationSummary: Codable, Hashable {
    let isValid: Bool
    let sourceFragmentCount: Int
    let coveredFragmentCount: Int
    let diagnostics: [PaginationDiagnostic]

    enum CodingKeys: String, CodingKey {
        case isValid = "is_valid"
        case sourceFragmentCount = "source_fragment_count"
        case coveredFragmentCount = "covered_fragment_count"
        case diagnostics
    }
}

struct PersonalPaginationResult {
    let personalPages: [PersonalReaderPage]
    let sectionPageIndex: [Int: Int]
    let validation: PaginationValidationSummary
    let normalizerVersion: String
    let engineVersion: String
    let layout: PageLayoutConfiguration

    var pages: [FrozenPageMeta] { personalPages.map(\.frozenMeta) }
    var pageHTMLByNumber: [Int: String] {
        Dictionary(uniqueKeysWithValues: personalPages.map { ($0.pageNumber, $0.pageHTML) })
    }
}
