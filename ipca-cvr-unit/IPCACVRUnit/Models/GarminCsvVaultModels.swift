import Foundation

enum GarminCsvSyncState: String, Codable, Equatable {
    case pending
    case uploading
    case synced
    case duplicate
    case failed
    case skippedGpsOnly
}

struct GarminCsvVaultRecord: Identifiable, Codable, Equatable {
    var id: String
    var sha256: String
    var originalFilename: String
    var sourcePathOnCard: String
    var dataLogType: GarminDataLogType
    var aircraftIdent: String
    var importProfile: String
    var firstUtc: Date?
    var lastUtc: Date?
    var byteCount: Int64
    var importedAt: Date
    var lastSeenAt: Date
    var syncState: GarminCsvSyncState
    var flightRecordID: String?
    var uploadComponentID: String?
    var uploadUuid: String?
    var serverCsvFileUuid: String?
    var serverReceiptID: String?
    var lastError: String
}

struct GarminCsvVaultIndex: Codable, Equatable {
    var records: [GarminCsvVaultRecord] = []
}

struct GarminSDCardScanSummary: Equatable {
    var scannedAt: Date
    var cardAvailable: Bool
    var dataRichFound: Int
    var gpsOnlySkipped: Int
    var alreadyKnown: Int
    var imported: Int
    var matchedFlightRecord: Bool
    var message: String
}

struct GarminSDCardCandidate: Equatable {
    var fileURL: URL
    var filename: String
    var relativePath: String
    var metadata: G3XFlightStreamMetadata
    var classification: GarminCsvClassification
    var modificationDate: Date?
}
