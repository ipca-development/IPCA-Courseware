import Foundation

// MARK: - API envelopes

struct OKResponse: Codable {
    var ok: Bool
    var error: String?
}

struct AuthSessionResponse: Codable {
    var ok: Bool
    var loggedIn: Bool?
    var user: ReaderUser?
    var canReadManuals: Bool?
    var canReviewManuals: Bool?
    var error: String?

    enum CodingKeys: String, CodingKey {
        case ok
        case loggedIn = "logged_in"
        case user
        case canReadManuals = "can_read_manuals"
        case canReviewManuals = "can_review_manuals"
        case error
    }
}

struct ReaderUser: Codable, Equatable {
    var id: Int
    var email: String
    var name: String
    var role: String
}

struct LibraryResponse: Codable {
    var ok: Bool
    var books: [LibraryBook]
    var error: String?
}

struct LibraryBook: Codable, Identifiable, Hashable {
    var bookId: Int
    var bookKey: String
    var bookTitle: String
    var manualCode: String
    var versionId: Int
    var versionLabel: String
    var effectiveDate: String?
    var releasedAt: String?
    var lifecycleStatus: String?
    var isPreview: Bool?
    var coverUrl: String?
    var coverImageUrl: String?
    var coverPageThumbnailUrl: String?
    var logoUrl: String?
    var hasProgress: Bool
    var hasPageMap: Bool
    var continueSectionId: Int?
    var continueStableAnchor: String?
    var continuePageNumber: Int?

    var id: String { "\(bookKey)-\(versionId)" }

    enum CodingKeys: String, CodingKey {
        case bookId = "book_id"
        case bookKey = "book_key"
        case bookTitle = "book_title"
        case manualCode = "manual_code"
        case versionId = "version_id"
        case versionLabel = "version_label"
        case effectiveDate = "effective_date"
        case releasedAt = "released_at"
        case lifecycleStatus = "lifecycle_status"
        case isPreview = "is_preview"
        case coverUrl = "cover_url"
        case coverImageUrl = "cover_image_url"
        case coverPageThumbnailUrl = "cover_page_thumbnail_url"
        case logoUrl = "logo_url"
        case hasProgress = "has_progress"
        case hasPageMap = "has_page_map"
        case continueSectionId = "continue_section_id"
        case continueStableAnchor = "continue_stable_anchor"
        case continuePageNumber = "continue_page_number"
    }

    var displayTitle: String {
        if bookTitle.isEmpty { return bookKey }
        return bookTitle
    }

    var isDraftPreview: Bool {
        if isPreview == true {
            return true
        }
        guard let lifecycleStatus else {
            return false
        }
        let status = lifecycleStatus.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        return ["draft", "in_review", "approved"].contains(status)
    }

    var coverAbsoluteURL: URL? {
        guard let path = coverPageThumbnailUrl ?? coverImageUrl ?? coverUrl,
              !path.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty else { return nil }
        if path.hasPrefix("http://") || path.hasPrefix("https://") {
            return URL(string: path)
        }
        return nil
    }
}

struct PageMapResponse: Codable {
    var ok: Bool?
    var bookKey: String?
    var versionId: Int?
    var versionLabel: String?
    var bookTitle: String?
    var pageCount: Int?
    var pages: [FrozenPageMeta]
    var layoutProfile: String?
    var layoutHash: String?
    var pageMapHash: String?
    var sourceHash: String?
    var styleHash: String?
    var manualPageBreakHash: String?
    var manifestHash: String?
    var error: String?

    enum CodingKeys: String, CodingKey {
        case ok
        case bookKey = "book_key"
        case versionId = "version_id"
        case versionLabel = "version_label"
        case bookTitle = "book_title"
        case pageCount = "page_count"
        case pages
        case layoutProfile = "layout_profile"
        case layoutHash = "layout_hash"
        case pageMapHash = "page_map_hash"
        case sourceHash = "source_hash"
        case styleHash = "style_hash"
        case manualPageBreakHash = "manual_page_break_hash"
        case manifestHash = "manifest_hash"
        case error
    }
}

struct FrozenPageMeta: Codable, Identifiable, Hashable {
    var pageNumber: Int
    var sectionId: Int?
    var stableAnchor: String?
    var pageType: String?
    var isCover: Bool
    var isSectionStart: Bool
    var isMajorSectionStart: Bool
    var sectionTitle: String?

    var id: Int { pageNumber }

    enum CodingKeys: String, CodingKey {
        case pageNumber = "page_number"
        case sectionId = "section_id"
        case stableAnchor = "stable_anchor"
        case pageType = "page_type"
        case isCover = "is_cover"
        case isSectionStart = "is_section_start"
        case isMajorSectionStart = "is_major_section_start"
        case sectionTitle = "section_title"
    }
}

struct FrozenPageResponse: Codable {
    var ok: Bool?
    var pageNumber: Int?
    var pageHtml: String?
    var bookKey: String?
    var error: String?

    enum CodingKeys: String, CodingKey {
        case ok
        case pageNumber = "page_number"
        case pageHtml = "page_html"
        case bookKey = "book_key"
        case error
    }
}

struct ManualPageBatchResponse: Codable {
    var ok: Bool
    var pages: [FrozenPageResponse]
    var error: String?
}

struct TocResponse: Codable {
    var ok: Bool?
    var nav: [NavNode]
    var sectionPageIndex: [String: Int]
    var pageCount: Int?
    var error: String?

    enum CodingKeys: String, CodingKey {
        case ok
        case nav
        case sectionPageIndex = "section_page_index"
        case pageCount = "page_count"
        case error
    }
}

struct NavNode: Codable, Identifiable, Hashable {
    var id: Int?
    var title: String?
    var sectionKey: String?
    var stableAnchor: String?
    var isNavigable: Bool?
    var isGroup: Bool?
    var isSeparator: Bool?
    var labelStyle: String?
    var scrollSectionRef: String?
    var children: [NavNode]?

    enum CodingKeys: String, CodingKey {
        case id
        case title
        case sectionKey = "section_key"
        case stableAnchor = "stable_anchor"
        case isNavigable = "is_navigable"
        case isGroup = "is_group"
        case isSeparator = "is_separator"
        case labelStyle = "label_style"
        case scrollSectionRef = "scroll_section_ref"
        case children
    }

    var nodeID: String {
        if let scrollSectionRef, !scrollSectionRef.isEmpty {
            return "r-\(id ?? 0)-\(scrollSectionRef)"
        }
        if let id { return "s-\(id)" }
        return "k-\(sectionKey ?? title ?? UUID().uuidString)"
    }
}

struct ProgressGetResponse: Codable {
    var ok: Bool
    var progress: ReadingProgress?
    var defaultSectionId: Int?
    var error: String?

    enum CodingKeys: String, CodingKey {
        case ok
        case progress
        case defaultSectionId = "default_section_id"
        case error
    }
}

struct ReadingProgress: Codable {
    var sectionId: Int?
    var stableAnchor: String?
    var scrollPct: Int?

    enum CodingKeys: String, CodingKey {
        case sectionId = "section_id"
        case stableAnchor = "stable_anchor"
        case scrollPct = "scroll_pct"
    }

    var pageNumber: Int? {
        guard let scrollPct, scrollPct > 0 else { return nil }
        return scrollPct
    }
}

struct SearchTitlesResponse: Codable {
    var ok: Bool
    var results: [SearchResult]
    var error: String?
}

struct SearchResult: Codable, Identifiable, Hashable {
    var sectionId: Int
    var sectionTitle: String
    var stableAnchor: String?
    var pageNumber: Int?
    var excerpt: String?

    var id: String { "\(sectionId)-\(pageNumber ?? 0)-\(stableAnchor ?? "")" }

    enum CodingKeys: String, CodingKey {
        case sectionId = "section_id"
        case sectionTitle = "section_title"
        case stableAnchor = "stable_anchor"
        case pageNumber = "page_number"
        case excerpt
    }
}

// MARK: - Immutable publication package

enum ReaderPublicationContract {
    static let cssGeneratorVersion = "book-style-css-v2"
}

enum JSONValue: Codable, Equatable {
    case object([String: JSONValue])
    case array([JSONValue])
    case string(String)
    case number(Double)
    case bool(Bool)
    case null

    init(from decoder: Decoder) throws {
        let container = try decoder.singleValueContainer()
        if container.decodeNil() { self = .null }
        else if let value = try? container.decode(Bool.self) { self = .bool(value) }
        else if let value = try? container.decode(Double.self) { self = .number(value) }
        else if let value = try? container.decode(String.self) { self = .string(value) }
        else if let value = try? container.decode([String: JSONValue].self) { self = .object(value) }
        else { self = .array(try container.decode([JSONValue].self)) }
    }

    func encode(to encoder: Encoder) throws {
        var container = encoder.singleValueContainer()
        switch self {
        case .object(let value): try container.encode(value)
        case .array(let value): try container.encode(value)
        case .string(let value): try container.encode(value)
        case .number(let value): try container.encode(value)
        case .bool(let value): try container.encode(value)
        case .null: try container.encodeNil()
        }
    }
}

struct PublicationPackageResponse: Codable {
    let ok: Bool
    let bookKey: String
    let versionID: Int
    let versionLabel: String
    let lifecycleStatus: String
    let isPreview: Bool
    let publicationPackage: PublicationPackage
    let error: String?

    enum CodingKeys: String, CodingKey {
        case ok
        case bookKey = "book_key"
        case versionID = "version_id"
        case versionLabel = "version_label"
        case lifecycleStatus = "lifecycle_status"
        case isPreview = "is_preview"
        case publicationPackage = "publication_package"
        case error
    }
}

struct PublicationPackage: Codable {
    let manifestVersion: String
    let manifestHash: String
    let manifest: BookStyleManifest
    let css: PublicationCSS
    let templates: PublicationTemplates
    let assets: [PublicationAsset]

    enum CodingKeys: String, CodingKey {
        case manifestVersion = "manifest_version"
        case manifestHash = "manifest_hash"
        case manifest
        case css
        case templates
        case assets
    }

    var canonicalManifestJSON: Data {
        (try? JSONEncoder.canonical.encode(manifest)) ?? Data()
    }
}

struct PublicationCSS: Codable {
    let filename: String
    let mediaType: String
    let hashAlgorithm: String
    let hash: String
    let content: String

    enum CodingKeys: String, CodingKey {
        case filename
        case mediaType = "media_type"
        case hashAlgorithm = "hash_algorithm"
        case hash
        case content
    }
}

struct PublicationAsset: Codable {
    let descriptor: String
    let descriptorHash: String
    let kind: String
    let url: String?
    let contentHash: String?
    let hashAlgorithm: String
    let fontFamily: String?
    let fontStack: String?

    enum CodingKeys: String, CodingKey {
        case descriptor
        case descriptorHash = "descriptor_hash"
        case kind
        case url
        case contentHash = "content_hash"
        case hashAlgorithm = "hash_algorithm"
        case fontFamily = "font_family"
        case fontStack = "font_stack"
    }
}

struct PublicationTemplates: Codable {
    let main: PublicationTemplateScope
    let annex: PublicationTemplateScope
}

struct PublicationTemplateScope: Codable {
    let available: Bool
    let config: JSONValue
    let rendered: PublicationRenderedTemplate?
}

struct PublicationRenderedTemplate: Codable {
    let sourceSectionID: Int
    let headerHTML: String
    let footerHTML: String
    let headerHash: String
    let footerHash: String
    let templateHash: String

    enum CodingKeys: String, CodingKey {
        case sourceSectionID = "source_section_id"
        case headerHTML = "header_html"
        case footerHTML = "footer_html"
        case headerHash = "header_hash"
        case footerHash = "footer_hash"
        case templateHash = "template_hash"
    }
}

struct BookStyleManifest: Codable {
    let schemaVersion: String
    let book: PublicationBookIdentity
    let styles: JSONValue
    let pageBands: JSONValue
    let layout: PublicationLayout
    let layoutHash: String
    let renderPipeline: PublicationRenderPipeline
    let templateIdentity: JSONValue
    let assetIdentity: [PublicationAssetIdentity]

    enum CodingKeys: String, CodingKey {
        case schemaVersion = "schema_version"
        case book
        case styles
        case pageBands = "page_bands"
        case layout
        case layoutHash = "layout_hash"
        case renderPipeline = "render_pipeline"
        case templateIdentity = "template_identity"
        case assetIdentity = "asset_identity"
    }
}

struct PublicationBookIdentity: Codable {
    let bookKey: String
    let manualCode: String
    let versionID: Int
    let versionLabel: String

    enum CodingKeys: String, CodingKey {
        case bookKey = "book_key"
        case manualCode = "manual_code"
        case versionID = "version_id"
        case versionLabel = "version_label"
    }
}

struct PublicationLayout: Codable, Hashable {
    let layoutProfile: String
    let paperSize: String
    let pageWidthPX: Double
    let pageHeightPX: Double
    let sheetPaddingTopPX: Double
    let sheetPaddingBottomPX: Double
    let sheetPaddingXPX: Double
    let headerBandPX: Double
    let footerBandPX: Double
    let headerMarginBottomPX: Double
    let footerMarginTopPX: Double
    let bodyCapacityPX: Double
    let fontFamily: String
    let fontSizePT: Double
    let lineHeight: Double
    let lineHeightPX: Double
    let charsPerLine: Int
    let splitWordsPerChunk: Int

    enum CodingKeys: String, CodingKey {
        case layoutProfile = "layout_profile"
        case paperSize = "paper_size"
        case pageWidthPX = "page_width_px"
        case pageHeightPX = "page_height_px"
        case sheetPaddingTopPX = "sheet_padding_top_px"
        case sheetPaddingBottomPX = "sheet_padding_bottom_px"
        case sheetPaddingXPX = "sheet_padding_x_px"
        case headerBandPX = "header_band_px"
        case footerBandPX = "footer_band_px"
        case headerMarginBottomPX = "header_margin_bottom_px"
        case footerMarginTopPX = "footer_margin_top_px"
        case bodyCapacityPX = "body_capacity_px"
        case fontFamily = "font_family"
        case fontSizePT = "font_size_pt"
        case lineHeight = "line_height"
        case lineHeightPX = "line_height_px"
        case charsPerLine = "chars_per_line"
        case splitWordsPerChunk = "split_words_per_chunk"
    }
}

struct PublicationRenderPipeline: Codable {
    let rendererVersion: String
    let rendererSourceHash: String?
    let templateVersion: String
    let cssGeneratorVersion: String

    enum CodingKeys: String, CodingKey {
        case rendererVersion = "renderer_version"
        case rendererSourceHash = "renderer_source_hash"
        case templateVersion = "template_version"
        case cssGeneratorVersion = "css_generator_version"
    }
}

struct PublicationAssetIdentity: Codable {
    let descriptor: String
    let descriptorHash: String

    enum CodingKeys: String, CodingKey {
        case descriptor
        case descriptorHash = "descriptor_hash"
    }
}

private extension JSONEncoder {
    static var canonical: JSONEncoder {
        let encoder = JSONEncoder()
        encoder.outputFormatting = [.sortedKeys, .withoutEscapingSlashes]
        return encoder
    }
}

// MARK: - Reader settings

enum ReaderTheme: String, CaseIterable, Identifiable, Codable {
    case original
    case sepia
    case dark

    var id: String { rawValue }

    var label: String {
        switch self {
        case .original: "Original"
        case .sepia: "Sepia"
        case .dark: "Dark"
        }
    }

    init(from decoder: Decoder) throws {
        let value = try decoder.singleValueContainer().decode(String.self)
        self = value == "light" ? .original : (ReaderTheme(rawValue: value) ?? .original)
    }
}

enum ReaderZoomMode: String, CaseIterable, Identifiable, Codable {
    case fitWidth = "fit-width"
    case fitPage = "fit-page"
    case percent75 = "75"
    case percent100 = "100"
    case percent125 = "125"

    var id: String { rawValue }

    var label: String {
        switch self {
        case .fitWidth: "Fit Width"
        case .fitPage: "Fit Page"
        case .percent75: "75%"
        case .percent100: "100%"
        case .percent125: "125%"
        }
    }
}

enum ReaderFontSize: String, CaseIterable, Identifiable, Codable {
    case small
    case standard
    case large
    case extraLarge

    var id: String { rawValue }

    var label: String {
        switch self {
        case .small: "Small"
        case .standard: "Standard"
        case .large: "Large"
        case .extraLarge: "Extra Large"
        }
    }

    var scale: Double {
        switch self {
        case .small: 0.9
        case .standard: 1
        case .large: 1.12
        case .extraLarge: 1.24
        }
    }
}

struct ReaderSettings: Codable, Equatable {
    var theme: ReaderTheme = .original
    var zoom: ReaderZoomMode = .fitWidth
    var showFilmstrip: Bool = true
    var fontSize: ReaderFontSize = .standard

    private enum CodingKeys: String, CodingKey {
        case theme
        case zoom
        case showFilmstrip
        case fontSize
    }

    init() {}

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        theme = try container.decodeIfPresent(ReaderTheme.self, forKey: .theme) ?? .original
        zoom = try container.decodeIfPresent(ReaderZoomMode.self, forKey: .zoom) ?? .fitWidth
        showFilmstrip = try container.decodeIfPresent(Bool.self, forKey: .showFilmstrip) ?? true
        fontSize = try container.decodeIfPresent(ReaderFontSize.self, forKey: .fontSize) ?? .standard
    }
}

enum ReaderDisplayMode {
    /// Canonical controlled manuals always display the server-frozen page map.
    static let controlledFrozenPages = true
}

struct LocalBookmark: Codable, Identifiable, Hashable {
    var id: UUID
    var bookKey: String
    var versionID: Int?
    /// Legacy personal page snapshot retained for decoding existing bookmarks.
    var pageNumber: Int
    var label: String
    var createdAt: Date
    var stableAnchor: String?
    var blockAnchor: String?
    var officialLocation: OfficialDocumentLocation?
    var semanticLocation: SemanticReaderLocation?
    var personalReaderPageNumber: Int?
    var clientUpdatedAt: Date?
    var deletedAt: Date?
}

enum ReaderHighlightColor: String, Codable, CaseIterable, Identifiable {
    case fluorescentYellow
    case fluorescentGreen
    case fluorescentBlue
    case fluorescentRed

    var id: String { rawValue }

    var label: String {
        switch self {
        case .fluorescentYellow: "Fluo Yellow"
        case .fluorescentGreen: "Fluo Green"
        case .fluorescentBlue: "Fluo Blue"
        case .fluorescentRed: "Fluo Red"
        }
    }

    var cssColor: String {
        switch self {
        case .fluorescentYellow: "#fff34d"
        case .fluorescentGreen: "#67ff75"
        case .fluorescentBlue: "#65dfff"
        case .fluorescentRed: "#ff7180"
        }
    }
}

struct ReaderTextSelection: Codable, Hashable {
    var selectedText: String
    var sourceFragmentID: String?
    var stableAnchor: String?
    var startOffset: Int
    var endOffset: Int
    var prefix: String?
    var suffix: String?
    var existingHighlightID: UUID?
    var opensPersonalNote: Bool?
    var opensReviewerNote: Bool?
}

struct TextHighlightAnchor: Codable, Identifiable, Hashable {
    var id: UUID
    var bookKey: String
    var versionID: Int?
    var pageNumber: Int
    var selectedText: String
    var sourceFragmentID: String?
    var stableAnchor: String?
    var startOffset: Int
    var endOffset: Int
    var prefix: String?
    var suffix: String?
    var color: ReaderHighlightColor
    var personalNote: String?
    var clientUpdatedAt: Date?
    var deletedAt: Date?
    var createdAt: Date
}

struct ReaderServerAnnotation: Codable {
    var annotationUUID: String
    var kind: String
    var bookKey: String
    var versionID: Int
    var pageNumber: Int
    var label: String?
    var selectedText: String?
    var sourceFragmentID: String?
    var stableAnchor: String?
    var startOffset: Int?
    var endOffset: Int?
    var prefix: String?
    var suffix: String?
    var color: String?
    var personalNote: String?
    var clientUpdatedAtUTC: String
    var serverUpdatedAtUTC: String?
    var deletedAtUTC: String?
    var createdAtUTC: String?

    enum CodingKeys: String, CodingKey {
        case annotationUUID = "annotation_uuid"
        case kind
        case bookKey = "book_key"
        case versionID = "version_id"
        case pageNumber = "page_number"
        case label
        case selectedText = "selected_text"
        case sourceFragmentID = "source_fragment_id"
        case stableAnchor = "stable_anchor"
        case startOffset = "start_offset"
        case endOffset = "end_offset"
        case prefix, suffix, color
        case personalNote = "personal_note"
        case clientUpdatedAtUTC = "client_updated_at_utc"
        case serverUpdatedAtUTC = "server_updated_at_utc"
        case deletedAtUTC = "deleted_at_utc"
        case createdAtUTC = "created_at_utc"
    }
}

struct ReaderAnnotationSyncResponse: Codable {
    var ok: Bool
    var canReviewManuals: Bool?
    var annotations: [ReaderServerAnnotation]

    enum CodingKeys: String, CodingKey {
        case ok
        case canReviewManuals = "can_review_manuals"
        case annotations
    }
}

struct ReviewNoteAuthor: Codable, Hashable, Identifiable {
    var id: Int
    var name: String
    var role: String
    var photoURL: String?
    var initials: String

    enum CodingKeys: String, CodingKey {
        case id, name, role, initials
        case photoURL = "photo_url"
    }
}

struct ReviewNoteComment: Codable, Identifiable, Hashable {
    var commentUUID: String
    var body: String
    var createdAtUTC: String
    var updatedAtUTC: String
    var author: ReviewNoteAuthor

    var id: String { commentUUID }

    enum CodingKeys: String, CodingKey {
        case commentUUID = "comment_uuid"
        case body
        case createdAtUTC = "created_at_utc"
        case updatedAtUTC = "updated_at_utc"
        case author
    }
}

struct ReviewNoteThread: Codable, Identifiable, Hashable {
    var threadUUID: String
    var versionID: Int
    var bookKey: String
    var pageNumber: Int
    var selectedText: String
    var sourceFragmentID: String?
    var stableAnchor: String?
    var startOffset: Int?
    var endOffset: Int?
    var status: String
    var createdAtUTC: String
    var updatedAtUTC: String
    var createdBy: ReviewNoteAuthor
    var comments: [ReviewNoteComment]

    var id: String { threadUUID }

    enum CodingKeys: String, CodingKey {
        case threadUUID = "thread_uuid"
        case versionID = "version_id"
        case bookKey = "book_key"
        case pageNumber = "page_number"
        case selectedText = "selected_text"
        case sourceFragmentID = "source_fragment_id"
        case stableAnchor = "stable_anchor"
        case startOffset = "start_offset"
        case endOffset = "end_offset"
        case status
        case createdAtUTC = "created_at_utc"
        case updatedAtUTC = "updated_at_utc"
        case createdBy = "created_by"
        case comments
    }
}

struct ReviewThreadsResponse: Codable {
    var ok: Bool
    var threads: [ReviewNoteThread]?
    var thread: ReviewNoteThread?
}

struct PendingReviewNote: Codable, Identifiable, Hashable {
    var id: UUID
    var threadUUID: String?
    var commentUUID: UUID
    var bookKey: String
    var versionID: Int
    var pageNumber: Int
    var selection: ReaderTextSelection
    var body: String
    var createdAt: Date
}

// Official frozen page dimensions (matches ControlledPublishingReaderLayoutProfile)
enum ManualPageLayout {
    static let width: CGFloat = 816
    static let height: CGFloat = 1056
}
