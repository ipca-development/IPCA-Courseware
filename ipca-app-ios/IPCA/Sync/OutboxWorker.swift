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
            await uploadAttachment(operation)
        case .sendMessage:
            await sendMessage(operation)
        }
    }

    private func uploadAttachment(_ operation: OutboxOp) async {
        guard let payload = try? JSONSerialization.jsonObject(with: operation.payloadJSON) as? [String: Any],
              let conversationUUID = payload["conversation_uuid"] as? String,
              let clientID = payload["client_id"] as? String,
              let attachmentUUID = payload["attachment_uuid"] as? String,
              let filename = payload["filename"] as? String,
              let mimeType = payload["mime_type"] as? String,
              let localPath = payload["local_path"] as? String else {
            await store.markOperationFailed(
                operationID: operation.id,
                clientID: "",
                type: .uploadAttachment,
                retryable: false,
                retryAt: Date(),
                internalError: "invalid_payload"
            )
            return
        }
        let byteSize = (payload["byte_size"] as? Int) ?? Int(payload["byte_size"] as? String ?? "0") ?? 0
        await store.markSending(clientID: clientID)
        do {
            let data = try Data(contentsOf: URL(fileURLWithPath: localPath))
            let presign = try await api.presignAttachment(
                conversationUUID: conversationUUID,
                attachmentUUID: attachmentUUID,
                filename: filename,
                mimeType: mimeType,
                byteSize: byteSize > 0 ? byteSize : data.count
            )
            guard let putURL = URL(string: presign.putURL) else {
                throw APIClientError.decoding
            }
            let contentType = presign.headers["Content-Type"] ?? mimeType
            try await api.uploadPresigned(url: putURL, data: data, contentType: contentType)
            try await api.completeAttachment(attachmentUUID: presign.attachmentUUID)
            await store.markOperationSucceeded(operationID: operation.id)
        } catch let error as APIClientError {
            let retryable = OutboxPlanner.isRetryable(httpStatus: error.httpStatus, errorCode: error.errorCode)
            let delay = OutboxPlanner.retryDelay(attemptCount: operation.attemptCount)
            await store.markOperationFailed(
                operationID: operation.id,
                clientID: clientID,
                type: .uploadAttachment,
                retryable: retryable,
                retryAt: Date().addingTimeInterval(delay),
                internalError: error.errorCode ?? "transport"
            )
        } catch {
            let delay = OutboxPlanner.retryDelay(attemptCount: operation.attemptCount)
            await store.markOperationFailed(
                operationID: operation.id,
                clientID: clientID,
                type: .uploadAttachment,
                retryable: true,
                retryAt: Date().addingTimeInterval(delay),
                internalError: "transport"
            )
        }
    }

    private func sendMessage(_ operation: OutboxOp) async {
        guard let payload = try? JSONSerialization.jsonObject(with: operation.payloadJSON) as? [String: Any],
              let conversationUUID = payload["conversation_uuid"] as? String,
              let clientID = payload["client_id"] as? String else {
            await store.markSendFailed(
                clientID: "",
                retryable: false,
                retryAt: Date(),
                internalError: "invalid_payload"
            )
            return
        }
        let body = payload["body"] as? String ?? ""
        let attachmentUUIDs = payload["attachment_uuids"] as? [String] ?? []
        let replyToMessageUUID = payload["reply_to_message_uuid"] as? String

        await store.markSending(clientID: clientID)
        do {
            let dto = try await api.sendMessage(
                conversationUUID: conversationUUID,
                clientID: clientID,
                body: body,
                attachmentUUIDs: attachmentUUIDs,
                replyToMessageUUID: replyToMessageUUID
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
