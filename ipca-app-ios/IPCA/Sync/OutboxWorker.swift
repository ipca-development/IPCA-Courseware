import Foundation

actor OutboxWorker {
    private let store: StoreWriter
    private let api: APIClient
    private var running = false

    init(store: StoreWriter, api: APIClient) {
        self.store = store
        self.api = api
    }

    func start() async {
        await store.recoverOutbox()
        await drain()
    }

    func drain() async {
        guard !running else { return }
        running = true
        defer { running = false }
        while let operation = await store.nextRunnableOperation() {
            await execute(operation)
        }
    }

    private func execute(_ operation: OutboxOp) async {
        switch operation.type {
        case .uploadAttachment:
            await store.markSendFailed(
                clientID: operation.id.uuidString.lowercased(),
                retryable: false,
                retryAt: Date(),
                internalError: "attachments_not_in_phase_1"
            )
        case .sendMessage:
            await sendMessage(operation)
        }
    }

    private func sendMessage(_ operation: OutboxOp) async {
        guard let payload = try? JSONSerialization.jsonObject(with: operation.payloadJSON) as? [String: String],
              let conversationUUID = payload["conversation_uuid"],
              let clientID = payload["client_id"],
              let body = payload["body"] else {
            await store.markSendFailed(
                clientID: "",
                retryable: false,
                retryAt: Date(),
                internalError: "invalid_payload"
            )
            return
        }

        await store.markSending(clientID: clientID)
        do {
            let dto = try await api.sendMessage(
                conversationUUID: conversationUUID,
                clientID: clientID,
                body: body
            )
            await store.markSendSucceeded(clientID: clientID, dto: dto)
        } catch let error as APIClientError {
            let retryable = OutboxPlanner.isRetryable(httpStatus: error.httpStatus, errorCode: error.errorCode)
            let delay = OutboxPlanner.retryDelay(attemptCount: operation.attemptCount)
            await store.markSendFailed(
                clientID: clientID,
                retryable: retryable,
                retryAt: Date().addingTimeInterval(delay),
                internalError: error.errorCode ?? "transport"
            )
        } catch {
            let delay = OutboxPlanner.retryDelay(attemptCount: operation.attemptCount)
            await store.markSendFailed(
                clientID: clientID,
                retryable: true,
                retryAt: Date().addingTimeInterval(delay),
                internalError: "transport"
            )
        }
    }
}
