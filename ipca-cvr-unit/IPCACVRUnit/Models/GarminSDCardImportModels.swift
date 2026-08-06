import Foundation

/// External SD-card / folder accessibility — separate from file upload status.
enum GarminExternalFolderAccessState: String, Equatable {
    case notConfigured
    case checking
    case available
    case unavailable
    case accessNeedsRestoration
    case configuredFolderEmpty
    case error
}

/// Import / sync status for one Garmin CSV candidate.
enum GarminSDCardImportState: String, Equatable {
    case checkingStatus
    case new
    case storedOnIPhone
    case uploadPending
    case uploading
    case uploadedLinkingPending
    case syncFailed
    case syncedAndLinked
    case alreadySynced
    case duplicateOfPendingImport
    case gpsOnly
    case invalid
    case unsupported
    case unreadable
    case unknown
}

enum GarminSDCardImportFilter: String, CaseIterable, Identifiable {
    case all
    case new
    case needsAttention
    case synced

    var id: String { rawValue }

    var title: String {
        switch self {
        case .all: return "All"
        case .new: return "New"
        case .needsAttention: return "Needs Attention"
        case .synced: return "Synced"
        }
    }
}

/// Last import/resume outcome shown until the crew starts another import or dismisses it.
enum GarminSDCardImportResultKind: Equatable {
    case success
    case pending
    case failure
}

struct GarminSDCardImportResult: Equatable {
    var kind: GarminSDCardImportResultKind
    var filename: String
    var message: String
}

struct GarminExternalFolderDisplayInfo: Equatable {
    var folderName: String
    var volumeName: String
    var configuredAt: Date?
    var associatedTail: String
}

struct GarminSDCardCandidate: Identifiable, Equatable {
    var id: String { contentKey }
    /// Stable identity for UI: sha256 when known, else path+size+mtime.
    var contentKey: String
    var filename: String
    var relativePath: String
    var byteCount: Int64
    var modificationDate: Date?
    var classification: GarminCsvClassification
    var importState: GarminSDCardImportState
    var aircraftIdent: String
    var startUtc: Date?
    var endUtc: Date?
    var rowCount: Int
    var sha256: String?
    var linkedFlightRecordID: String?
    var isRecommended: Bool
    var matchWarning: String?
    var excludedReason: String?
    var serverStatusCheckedAt: Date?
    var usingCachedServerStatus: Bool
    /// Security-scoped source URL valid only while access is held during copy.
    var externalURL: URL?

    var probableDurationLabel: String {
        guard let start = startUtc, let end = endUtc, end > start else { return "" }
        let minutes = Int(end.timeIntervalSince(start) / 60.0)
        let hours = minutes / 60
        let rem = minutes % 60
        return String(format: "%d:%02d flight", hours, rem)
    }

    var displayStatusLabel: String {
        switch importState {
        case .checkingStatus: return "Checking import status…"
        case .new: return "New"
        case .storedOnIPhone: return "Stored on iPhone"
        case .uploadPending: return "Upload Pending — Waiting for network"
        case .uploading: return "Uploading"
        case .uploadedLinkingPending: return "Uploaded — Linking pending"
        case .syncFailed: return "Sync Failed — Tap to retry"
        case .syncedAndLinked:
            if let linkedFlightRecordID, !linkedFlightRecordID.isEmpty {
                return "Synced & Linked — Flight Record \(shortID(linkedFlightRecordID))"
            }
            return "Synced & Linked"
        case .alreadySynced:
            if let linkedFlightRecordID, !linkedFlightRecordID.isEmpty {
                return "Already Synced — Flight Record \(shortID(linkedFlightRecordID))"
            }
            return "Already Synced"
        case .duplicateOfPendingImport: return "Duplicate of Pending Import"
        case .gpsOnly: return "GPS Only — Not Eligible"
        case .invalid: return excludedReason ?? "Invalid CSV"
        case .unsupported: return "Unsupported CSV Format"
        case .unreadable: return "Unable to Read"
        case .unknown: return "Classification Uncertain"
        }
    }

    var isExcluded: Bool {
        switch importState {
        case .gpsOnly, .invalid, .unsupported, .unreadable, .unknown:
            return true
        default:
            return false
        }
    }

    var canImport: Bool {
        switch importState {
        case .new, .syncFailed:
            return classification.isDataRich
        default:
            return false
        }
    }

    var canResume: Bool {
        switch importState {
        case .storedOnIPhone, .uploadPending, .uploadedLinkingPending, .syncFailed, .duplicateOfPendingImport:
            return true
        default:
            return false
        }
    }

    private func shortID(_ value: String) -> String {
        let trimmed = value.trimmingCharacters(in: .whitespacesAndNewlines)
        guard trimmed.count > 8 else { return trimmed }
        return String(trimmed.prefix(8))
    }
}

enum GarminExternalFolderAccessError: LocalizedError, Equatable {
    case notConfigured
    case unavailable
    case accessNeedsRestoration
    case emptyFolder
    case busy
    case backgrounded
    case verificationFailed(String)
    case copyFailed(String)
    case other(String)

    var errorDescription: String? {
        switch self {
        case .notConfigured:
            return "Select the Garmin folder on the aircraft SD card."
        case .unavailable:
            return "Insert the configured Garmin SD card and try again."
        case .accessNeedsRestoration:
            return "IPCA can no longer access the previously selected Garmin folder."
        case .emptyFolder:
            return "The Garmin folder is available but contains no CSV files."
        case .busy:
            return "Another Garmin SD Card Import is already in progress."
        case .backgrounded:
            return "Garmin SD Card Import paused because the app left the foreground."
        case .verificationFailed(let message), .copyFailed(let message), .other(let message):
            return message
        }
    }
}
