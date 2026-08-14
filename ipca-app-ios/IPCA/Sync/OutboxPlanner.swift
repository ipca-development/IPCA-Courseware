import Foundation

enum OutboxOpType: String, Codable, Equatable {
    case sendMessage = "send_message"
    case uploadAttachment = "upload_attachment"
}

enum OutboxOpState: String, Codable, Equatable {
    case queued
    case running
    case succeeded
    case failed
}

struct OutboxOp: Equatable {
    var id: UUID
    var type: OutboxOpType
    var state: OutboxOpState
    var dependsOn: [UUID]
    var attemptCount: Int
    var nextAttemptAt: Date
    var payloadJSON: Data
}

enum OutboxPlanner {
    static func recoverInterrupted(_ operations: [OutboxOp], now: Date = Date()) -> [OutboxOp] {
        operations.map { operation in
            guard operation.state == .running else { return operation }
            var recovered = operation
            recovered.state = .queued
            recovered.nextAttemptAt = now
            return recovered
        }
    }

    static func dependenciesSatisfied(_ operation: OutboxOp, in operations: [OutboxOp]) -> Bool {
        let byID = Dictionary(uniqueKeysWithValues: operations.map { ($0.id, $0) })
        return operation.dependsOn.allSatisfy { dependency in
            byID[dependency]?.state == .succeeded
        }
    }

    static func nextRunnable(_ operations: [OutboxOp], now: Date = Date()) -> OutboxOp? {
        operations
            .filter { $0.state == .queued && $0.nextAttemptAt <= now && dependenciesSatisfied($0, in: operations) }
            .sorted { $0.nextAttemptAt < $1.nextAttemptAt }
            .first
    }

    static func retryDelay(attemptCount: Int) -> TimeInterval {
        min(60, pow(2, Double(max(0, attemptCount - 1))))
    }

    static func isRetryable(httpStatus: Int?, errorCode: String?) -> Bool {
        if errorCode == "conflict" { return true }
        guard let httpStatus else { return true }
        if httpStatus == 409 { return true }
        if httpStatus == 401 || httpStatus == 403 { return false }
        if (400..<500).contains(httpStatus) { return false }
        return true
    }
}

final class SyncGenerationGate {
    private var generation = 0

    func begin() -> Int {
        generation += 1
        return generation
    }

    func shouldApply(_ token: Int) -> Bool {
        token == generation
    }
}
