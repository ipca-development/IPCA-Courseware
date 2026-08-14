import Foundation

@main
struct OutboxPlannerCheck {
    static func main() {
        var failed = 0

        func expect(_ name: String, _ ok: Bool) {
            print(ok ? "PASS  \(name)" : "FAIL  \(name)")
            if !ok { failed += 1 }
        }

        let now = Date()
        let dep = UUID()
        let sendID = UUID()
        let running = OutboxOp(
            id: UUID(),
            type: .sendMessage,
            state: .running,
            dependsOn: [],
            attemptCount: 1,
            nextAttemptAt: now,
            payloadJSON: Data()
        )
        let recovered = OutboxPlanner.recoverInterrupted([running], now: now)
        expect("interrupted running operations return to queued", recovered.first?.state == .queued)

        let blocked = OutboxOp(
            id: sendID,
            type: .sendMessage,
            state: .queued,
            dependsOn: [dep],
            attemptCount: 0,
            nextAttemptAt: now,
            payloadJSON: Data()
        )
        let upload = OutboxOp(
            id: dep,
            type: .uploadAttachment,
            state: .queued,
            dependsOn: [],
            attemptCount: 0,
            nextAttemptAt: now,
            payloadJSON: Data()
        )
        expect("send waits for attachment dependency", OutboxPlanner.nextRunnable([blocked, upload], now: now)?.id == dep)

        let readyUpload = OutboxOp(
            id: dep,
            type: .uploadAttachment,
            state: .succeeded,
            dependsOn: [],
            attemptCount: 1,
            nextAttemptAt: now,
            payloadJSON: Data()
        )
        expect("send runs after attachment_ready", OutboxPlanner.nextRunnable([blocked, readyUpload], now: now)?.id == sendID)

        let future = OutboxOp(
            id: UUID(),
            type: .sendMessage,
            state: .queued,
            dependsOn: [],
            attemptCount: 2,
            nextAttemptAt: now.addingTimeInterval(30),
            payloadJSON: Data()
        )
        expect("backoff delays retry", OutboxPlanner.nextRunnable([future], now: now) == nil)
        expect("retry delay grows", OutboxPlanner.retryDelay(attemptCount: 3) > OutboxPlanner.retryDelay(attemptCount: 1))
        expect("auth failures are not retryable", OutboxPlanner.isRetryable(httpStatus: 401, errorCode: "unauthenticated") == false)
        expect("transport failures are retryable", OutboxPlanner.isRetryable(httpStatus: 503, errorCode: nil) == true)
        expect("duplicate conflict is retryable/idempotent", OutboxPlanner.isRetryable(httpStatus: 409, errorCode: "conflict") == true)

        let gate = SyncGenerationGate()
        let first = gate.begin()
        let second = gate.begin()
        expect("stale sync generation is ignored", gate.shouldApply(first) == false && gate.shouldApply(second) == true)

        if failed > 0 {
            print("\n\(failed) failed")
            exit(1)
        }
        print("\nAll outbox planner checks passed")
    }
}
