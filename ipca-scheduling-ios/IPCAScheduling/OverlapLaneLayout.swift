import Foundation

struct OverlapLaneInput<ID: Hashable>: Identifiable, Equatable {
    let id: ID
    let start: Double
    let end: Double
}

struct OverlapLanePlacement<ID: Hashable>: Identifiable, Equatable {
    let id: ID
    let lane: Int
    let laneCount: Int
    let group: Int
}

struct OverlapLaneMetrics: Equatable {
    let minimumRowHeight: Double
    let laneHeight: Double
    let laneSpacing: Double
    let verticalPadding: Double

    init(
        minimumRowHeight: Double = 52,
        laneHeight: Double = 36,
        laneSpacing: Double = 4,
        verticalPadding: Double = 8
    ) {
        self.minimumRowHeight = minimumRowHeight
        self.laneHeight = laneHeight
        self.laneSpacing = laneSpacing
        self.verticalPadding = verticalPadding
    }

    func rowHeight(laneCount: Int) -> Double {
        let count = max(1, laneCount)
        let lanesHeight = Double(count) * laneHeight + Double(count - 1) * laneSpacing
        return max(minimumRowHeight, verticalPadding * 2 + lanesHeight)
    }
}

struct OverlapRowInput<RowID: Hashable, ItemID: Hashable>: Identifiable, Equatable {
    let id: RowID
    let intervals: [OverlapLaneInput<ItemID>]
}

struct OverlapRowLayout<RowID: Hashable, ItemID: Hashable>: Identifiable, Equatable {
    let id: RowID
    let placements: [OverlapLanePlacement<ItemID>]
    let laneCount: Int
    let height: Double
}

private struct PendingOverlapLanePlacement<ID: Hashable> {
    let sourceIndex: Int
    let id: ID
    let lane: Int
    let group: Int
}

enum OverlapLaneLayout {
    /// Assigns each interval to the lowest reusable lane. Endpoints are
    /// half-open, so an item ending exactly when another starts does not
    /// overlap it.
    static func layout<ID: Hashable>(
        _ intervals: [OverlapLaneInput<ID>]
    ) -> [OverlapLanePlacement<ID>] {
        let valid = intervals.enumerated()
            .filter { $0.element.end > $0.element.start }
            .sorted {
                if $0.element.start != $1.element.start {
                    return $0.element.start < $1.element.start
                }
                if $0.element.end != $1.element.end {
                    return $0.element.end < $1.element.end
                }
                return $0.offset < $1.offset
            }

        guard !valid.isEmpty else { return [] }

        var pending: [PendingOverlapLanePlacement<ID>] = []
        var laneEnds: [Double] = []
        var currentGroup = -1
        var groupMaximumEnd = -Double.infinity
        var groupRanges: [Range<Int>] = []
        var groupStartIndex = 0

        for (sourceIndex, interval) in valid {
            if currentGroup == -1 || interval.start >= groupMaximumEnd {
                if currentGroup >= 0 {
                    groupRanges.append(groupStartIndex ..< pending.count)
                }
                currentGroup += 1
                groupStartIndex = pending.count
                laneEnds = []
                groupMaximumEnd = interval.end
            } else {
                groupMaximumEnd = max(groupMaximumEnd, interval.end)
            }

            let lane = laneEnds.firstIndex { $0 <= interval.start } ?? laneEnds.count
            if lane == laneEnds.count {
                laneEnds.append(interval.end)
            } else {
                laneEnds[lane] = interval.end
            }
            pending.append(
                PendingOverlapLanePlacement(
                    sourceIndex: sourceIndex,
                    id: interval.id,
                    lane: lane,
                    group: currentGroup
                )
            )
        }
        groupRanges.append(groupStartIndex ..< pending.count)

        var laneCounts: [Int: Int] = [:]
        for range in groupRanges {
            guard let group = pending[range].first?.group else { continue }
            laneCounts[group] = (pending[range].map(\.lane).max() ?? 0) + 1
        }

        return pending
            .map {
                (
                    sourceIndex: $0.sourceIndex,
                    placement: OverlapLanePlacement(
                        id: $0.id,
                        lane: $0.lane,
                        laneCount: laneCounts[$0.group] ?? 1,
                        group: $0.group
                    )
                )
            }
            .sorted { $0.sourceIndex < $1.sourceIndex }
            .map(\.placement)
    }

    static func layoutRows<RowID: Hashable, ItemID: Hashable>(
        _ rows: [OverlapRowInput<RowID, ItemID>],
        metrics: OverlapLaneMetrics = OverlapLaneMetrics()
    ) -> [OverlapRowLayout<RowID, ItemID>] {
        rows.map { row in
            let placements = layout(row.intervals)
            let laneCount = placements.map(\.laneCount).max() ?? 1
            return OverlapRowLayout(
                id: row.id,
                placements: placements,
                laneCount: laneCount,
                height: metrics.rowHeight(laneCount: laneCount)
            )
        }
    }
}
