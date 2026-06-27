import Foundation

struct G3XMatchCandidate: Identifiable, Equatable {
    var id: String
    var recording: SharedRecordingEntry
    var score: Double
    var overlapSeconds: TimeInterval
}

enum G3XRecordingMatcher {
    private static let tolerance: TimeInterval = 15 * 60

    static func match(metadata: G3XFlightStreamMetadata, recordings: [SharedRecordingEntry]) -> G3XMatchCandidate? {
        rankedCandidates(metadata: metadata, recordings: recordings).first
    }

    static func rankedCandidates(metadata: G3XFlightStreamMetadata, recordings: [SharedRecordingEntry]) -> [G3XMatchCandidate] {
        guard let g3xStart = metadata.startUtc, let g3xEnd = metadata.endUtc else {
            return []
        }

        let tail = metadata.aircraftIdent.trimmingCharacters(in: .whitespacesAndNewlines).uppercased()
        let filtered = recordings.filter { recording in
            guard !recording.hasG3X else { return false }
            if tail.isEmpty { return true }
            let registration = recording.aircraftRegistration?.trimmingCharacters(in: .whitespacesAndNewlines).uppercased() ?? ""
            if registration.isEmpty { return true }
            return registration == tail
        }

        var candidates: [G3XMatchCandidate] = []
        for recording in filtered {
            let overlapStart = max(recording.startedAt, g3xStart.addingTimeInterval(-tolerance))
            let overlapEnd = min(recording.endedAt, g3xEnd.addingTimeInterval(tolerance))
            let overlap = overlapEnd.timeIntervalSince(overlapStart)
            guard overlap > 0 else { continue }

            let g3xDuration = max(1, g3xEnd.timeIntervalSince(g3xStart))
            let recordingDuration = max(1, recording.duration)
            let overlapRatio = overlap / min(g3xDuration, recordingDuration)
            let startDelta = abs(recording.startedAt.timeIntervalSince(g3xStart))
            let score = overlapRatio * 100 - min(startDelta / 60, 30)

            candidates.append(G3XMatchCandidate(
                id: recording.id,
                recording: recording,
                score: score,
                overlapSeconds: overlap
            ))
        }

        return candidates.sorted { $0.score > $1.score }
    }
}
