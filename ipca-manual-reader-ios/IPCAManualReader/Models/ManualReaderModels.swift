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
    var error: String?

    enum CodingKeys: String, CodingKey {
        case ok
        case loggedIn = "logged_in"
        case user
        case canReadManuals = "can_read_manuals"
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
    var logoUrl: String?
    var hasProgress: Bool
    var hasPageMap: Bool
    var continueSectionId: Int?
    var continueStableAnchor: String?

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
        case logoUrl = "logo_url"
        case hasProgress = "has_progress"
        case hasPageMap = "has_page_map"
        case continueSectionId = "continue_section_id"
        case continueStableAnchor = "continue_stable_anchor"
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
        guard let path = coverImageUrl ?? coverUrl,
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
    var children: [NavNode]?

    enum CodingKeys: String, CodingKey {
        case id
        case title
        case sectionKey = "section_key"
        case stableAnchor = "stable_anchor"
        case isNavigable = "is_navigable"
        case isGroup = "is_group"
        case isSeparator = "is_separator"
        case children
    }

    var nodeID: String {
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

    var id: Int { sectionId }

    enum CodingKeys: String, CodingKey {
        case sectionId = "section_id"
        case sectionTitle = "section_title"
        case stableAnchor = "stable_anchor"
    }
}

// MARK: - Reader settings

enum ReaderTheme: String, CaseIterable, Identifiable, Codable {
    case light
    case sepia
    case dark

    var id: String { rawValue }

    var label: String {
        switch self {
        case .light: "Light"
        case .sepia: "Sepia"
        case .dark: "Dark"
        }
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

struct ReaderSettings: Codable, Equatable {
    var theme: ReaderTheme = .light
    var zoom: ReaderZoomMode = .fitWidth
    var showFilmstrip: Bool = true
}

struct LocalBookmark: Codable, Identifiable, Hashable {
    var id: UUID
    var bookKey: String
    var pageNumber: Int
    var label: String
    var createdAt: Date
}

// Official frozen page dimensions (matches ControlledPublishingReaderLayoutProfile)
enum ManualPageLayout {
    static let width: CGFloat = 816
    static let height: CGFloat = 1056
}
