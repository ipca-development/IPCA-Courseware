import Foundation
import SwiftUI
import UniformTypeIdentifiers

enum G3XDocumentImporter {
    static func importFile(_ url: URL, store: RecordingStore) throws -> String? {
        let parsed = try G3XFlightStreamParser.parse(fileURL: url)
        let candidates = G3XRecordingMatcher.rankedCandidates(
            metadata: parsed.metadata,
            recordings: store.recordings.map {
                SharedRecordingEntry(
                    id: $0.id,
                    startedAt: $0.startedAt,
                    duration: $0.duration,
                    aircraftRegistration: $0.aircraftRegistration,
                    aircraftDisplayName: $0.aircraftDisplayName,
                    hasG3X: $0.hasG3XData
                )
            }
        )

        guard let match = candidates.first else {
            throw G3XParserError.invalidFormat("No local recording matches this Garmin CSV.")
        }

        try store.attachG3X(
            recordingID: match.id,
            csvSourceURL: url,
            metadata: parsed.metadata,
            matchMethod: (candidates.first?.score ?? 0) > 50 ? "auto" : "manual"
        )
        return match.id
    }
}
