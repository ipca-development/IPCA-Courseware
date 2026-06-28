import Foundation

struct G3XMatchCandidate: Identifiable, Equatable {
    var id: String
    var recording: SharedRecordingEntry
    var score: Double
    var overlapSeconds: TimeInterval
    var isManualFallback: Bool
}

enum G3XRecordingMatcher {
    private static let autoTolerance: TimeInterval = 30 * 60
    private static let manualTolerance: TimeInterval = 3 * 3600

    static func match(metadata: G3XFlightStreamMetadata, recordings: [SharedRecordingEntry]) -> G3XMatchCandidate? {
        displayCandidates(metadata: metadata, recordings: recordings).first
    }

    static func displayCandidates(metadata: G3XFlightStreamMetadata, recordings: [SharedRecordingEntry]) -> [G3XMatchCandidate] {
        let auto = rankedCandidates(
            metadata: metadata,
            recordings: recordings,
            requireOverlap: true,
            excludeExistingG3X: true,
            filterByTail: true,
            tolerance: autoTolerance
        )
        if !auto.isEmpty {
            return auto
        }

        return rankedCandidates(
            metadata: metadata,
            recordings: recordings,
            requireOverlap: false,
            excludeExistingG3X: false,
            filterByTail: false,
            tolerance: manualTolerance
        ).map { candidate in
            var copy = candidate
            copy.isManualFallback = true
            return copy
        }
    }

    static func rankedCandidates(metadata: G3XFlightStreamMetadata, recordings: [SharedRecordingEntry]) -> [G3XMatchCandidate] {
        displayCandidates(metadata: metadata, recordings: recordings)
    }

    private static func rankedCandidates(
        metadata: G3XFlightStreamMetadata,
        recordings: [SharedRecordingEntry],
        requireOverlap: Bool,
        excludeExistingG3X: Bool,
        filterByTail: Bool,
        tolerance: TimeInterval
    ) -> [G3XMatchCandidate] {
        let g3xStart = metadata.startUtc
        let g3xEnd = metadata.endUtc
        let tail = normalizedTail(metadata.aircraftIdent)

        let filtered = recordings.filter { recording in
            if excludeExistingG3X && recording.hasG3X {
                return false
            }
            if filterByTail && !tail.isEmpty && !tailMatches(recording: recording, g3xTail: tail) {
                return false
            }
            return true
        }

        var candidates: [G3XMatchCandidate] = []
        for recording in filtered {
            let overlap = overlapSeconds(
                recording: recording,
                g3xStart: g3xStart,
                g3xEnd: g3xEnd,
                tolerance: tolerance
            )

            if requireOverlap, let g3xStart, let g3xEnd {
                if overlap <= 0 {
                    continue
                }
                _ = g3xStart
                _ = g3xEnd
            }

            let score = scoreFor(
                recording: recording,
                metadata: metadata,
                overlap: overlap,
                g3xTail: tail
            )

            candidates.append(G3XMatchCandidate(
                id: recording.id,
                recording: recording,
                score: score,
                overlapSeconds: max(0, overlap),
                isManualFallback: false
            ))
        }

        return candidates.sorted {
            if $0.score != $1.score {
                return $0.score > $1.score
            }
            return $0.recording.startedAt > $1.recording.startedAt
        }
    }

    private static func overlapSeconds(
        recording: SharedRecordingEntry,
        g3xStart: Date?,
        g3xEnd: Date?,
        tolerance: TimeInterval
    ) -> TimeInterval {
        guard let g3xStart, let g3xEnd else {
            return 0
        }
        let overlapStart = max(recording.startedAt, g3xStart.addingTimeInterval(-tolerance))
        let overlapEnd = min(recording.endedAt, g3xEnd.addingTimeInterval(tolerance))
        return overlapEnd.timeIntervalSince(overlapStart)
    }

    private static func scoreFor(
        recording: SharedRecordingEntry,
        metadata: G3XFlightStreamMetadata,
        overlap: TimeInterval,
        g3xTail: String
    ) -> Double {
        var score = 0.0

        if !g3xTail.isEmpty && tailMatches(recording: recording, g3xTail: g3xTail) {
            score += 40
        }

        if let g3xStart = metadata.startUtc, let g3xEnd = metadata.endUtc, overlap > 0 {
            let g3xDuration = max(1, g3xEnd.timeIntervalSince(g3xStart))
            let recordingDuration = max(1, recording.duration)
            score += (overlap / min(g3xDuration, recordingDuration)) * 100
        } else if let g3xStart = metadata.startUtc {
            let startDelta = abs(recording.startedAt.timeIntervalSince(g3xStart))
            score += max(0, 80 - startDelta / 60)
        }

        if recording.hasG3X {
            score -= 5
        }

        return score
    }

    static func tailMatches(recording: SharedRecordingEntry, g3xTail: String) -> Bool {
        let tail = normalizedTail(g3xTail)
        if tail.isEmpty {
            return true
        }

        let registration = normalizedTail(recording.aircraftRegistration ?? "")
        let displayName = normalizedTail(recording.aircraftDisplayName ?? "")

        if registration.isEmpty && displayName.isEmpty {
            return true
        }

        if registration == tail || displayName == tail {
            return true
        }

        if !registration.isEmpty && (registration.contains(tail) || tail.contains(registration)) {
            return true
        }

        if !displayName.isEmpty && (displayName.contains(tail) || tail.contains(displayName)) {
            return true
        }

        return false
    }

    private static func normalizedTail(_ value: String) -> String {
        value
            .trimmingCharacters(in: .whitespacesAndNewlines)
            .uppercased()
            .replacingOccurrences(of: "-", with: "")
    }
}
