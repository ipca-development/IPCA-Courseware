import CoreData
import Foundation

final class StoreWriter: @unchecked Sendable {
    private let context: NSManagedObjectContext

    init(context: NSManagedObjectContext) {
        self.context = context
    }

    func recoverOutbox() async {
        await context.perform {
            let request = OutboxOperationEntity.fetchRequest()
            request.predicate = NSPredicate(format: "state == %@", OutboxOpState.running.rawValue)
            let rows = (try? self.context.fetch(request)) ?? []
            let now = Date()
            for row in rows {
                row.state = OutboxOpState.queued.rawValue
                row.nextAttemptAt = now
            }
            self.save()
        }
    }

    func enqueueSend(
        conversationUUID: String,
        body: String,
        senderUUID: String,
        attachments: [PendingAttachment] = [],
        replyTo: ReplyToDTO? = nil
    ) async -> String {
        let clientID = UUID().uuidString.lowercased()
        await context.perform {
            let preview: String
            if !body.isEmpty {
                preview = body
            } else if attachments.contains(where: { $0.mimeType.hasPrefix("image/") }) {
                preview = "Photo"
            } else if let name = attachments.first?.filename, !name.isEmpty {
                preview = name
            } else if !attachments.isEmpty {
                preview = "Attachment"
            } else {
                preview = body
            }

            let message = MessageEntity(context: self.context)
            message.messageUUID = clientID
            message.clientID = clientID
            message.conversationUUID = conversationUUID
            message.seq = 0
            message.body = body
            message.senderUserUUID = senderUUID
            message.senderType = "user"
            message.createdAt = Date()
            message.localState = LocalMessageState.queued.rawValue
            message.isFromMe = true
            message.attachmentsJSON = Self.encodeAttachments(attachments.map {
                AttachmentDTO(
                    attachmentUUID: $0.attachmentUUID,
                    filename: $0.filename,
                    mimeType: $0.mimeType,
                    byteSize: $0.byteSize,
                    localPath: $0.localURL.path
                )
            })
            message.requiresAcknowledgement = false
            message.replyAllowed = true
            message.senderDisplayName = ""
            message.replyToJSON = Self.encodeJSON(replyTo)
            message.reactionsJSON = "[]"

            if let conversation = self.conversation(conversationUUID) {
                conversation.preview = preview
                conversation.lastMessageAt = Date()
                conversation.updatedAt = Date()
            }

            var uploadIDs: [UUID] = []
            for attachment in attachments {
                let uploadID = UUID()
                uploadIDs.append(uploadID)
                let uploadPayload: [String: Any] = [
                    "conversation_uuid": conversationUUID,
                    "client_id": clientID,
                    "attachment_uuid": attachment.attachmentUUID,
                    "filename": attachment.filename,
                    "mime_type": attachment.mimeType,
                    "byte_size": attachment.byteSize,
                    "local_path": attachment.localURL.path
                ]
                let upload = OutboxOperationEntity(context: self.context)
                upload.operationID = uploadID.uuidString.lowercased()
                upload.type = OutboxOpType.uploadAttachment.rawValue
                upload.state = OutboxOpState.queued.rawValue
                upload.dependsOnJSON = "[]"
                upload.payloadJSON = (try? JSONSerialization.data(withJSONObject: uploadPayload)) ?? Data()
                upload.clientMessageID = clientID
                upload.conversationUUID = conversationUUID
                upload.attemptCount = 0
                upload.nextAttemptAt = Date()
                upload.lastInternalError = ""
                upload.createdAt = Date()
            }

            var sendPayload: [String: Any] = [
                "conversation_uuid": conversationUUID,
                "client_id": clientID,
                "body": body
            ]
            if !attachments.isEmpty {
                sendPayload["attachment_uuids"] = attachments.map(\.attachmentUUID)
            }
            if let replyTo {
                sendPayload["reply_to_message_uuid"] = replyTo.messageUUID
            }
            let op = OutboxOperationEntity(context: self.context)
            op.operationID = UUID().uuidString.lowercased()
            op.type = OutboxOpType.sendMessage.rawValue
            op.state = OutboxOpState.queued.rawValue
            op.dependsOnJSON = (try? String(data: JSONEncoder().encode(uploadIDs), encoding: .utf8)) ?? "[]"
            op.payloadJSON = (try? JSONSerialization.data(withJSONObject: sendPayload)) ?? Data()
            op.clientMessageID = clientID
            op.conversationUUID = conversationUUID
            op.attemptCount = 0
            op.nextAttemptAt = Date()
            op.lastInternalError = ""
            op.createdAt = Date()
            self.save()
        }
        return clientID
    }

    func unreadTotal() async -> Int {
        await context.perform {
            let request = ConversationEntity.fetchRequest()
            let rows = (try? self.context.fetch(request)) ?? []
            return rows.reduce(0) { $0 + Int($1.unreadCount) }
        }
    }

    func nextRunnableOperation() async -> OutboxOp? {
        await context.perform {
            let request = OutboxOperationEntity.fetchRequest()
            let rows = (try? self.context.fetch(request)) ?? []
            let ops = rows.compactMap { $0.asOutboxOp() }
            guard let next = OutboxPlanner.nextRunnable(ops) else { return nil }
            if let entity = rows.first(where: { $0.operationID == next.id.uuidString.lowercased() }) {
                entity.state = OutboxOpState.running.rawValue
                entity.attemptCount += 1
                self.save()
                var running = next
                running.state = .running
                running.attemptCount = Int(entity.attemptCount)
                return running
            }
            return next
        }
    }

    func markSending(clientID: String) async {
        await context.perform {
            self.message(clientID: clientID)?.localState = LocalMessageState.sending.rawValue
            self.save()
        }
    }

    func markSendSucceeded(clientID: String, dto: MessageDTO) async {
        await context.perform {
            if let message = self.message(clientID: clientID) {
                message.messageUUID = dto.messageUUID
                message.seq = Int64(dto.seq)
                message.createdAt = Self.parseDate(dto.createdAtUTC) ?? message.createdAt
                message.localState = LocalMessageState.serverReceived.rawValue
                message.senderUserUUID = dto.senderUserUUID ?? message.senderUserUUID
                message.attachmentsJSON = Self.mergeAttachmentsJSON(message.attachmentsJSON, incoming: dto.attachments)
            }
            self.finishOutbox(clientID: clientID, type: .sendMessage, state: .succeeded)
            self.save()
        }
    }

    func markOperationSucceeded(operationID: UUID) async {
        await context.perform {
            self.setOutboxState(operationID: operationID.uuidString.lowercased(), state: .succeeded)
            self.save()
        }
    }

    func markSendFailed(clientID: String, retryable: Bool, retryAt: Date, internalError: String) async {
        await self.markOperationFailed(
            operationID: nil,
            clientID: clientID,
            type: .sendMessage,
            retryable: retryable,
            retryAt: retryAt,
            internalError: internalError
        )
    }

    func markOperationFailed(
        operationID: UUID?,
        clientID: String,
        type: OutboxOpType,
        retryable: Bool,
        retryAt: Date,
        internalError: String
    ) async {
        await context.perform {
            if !clientID.isEmpty {
                if let message = self.message(clientID: clientID) {
                    message.localState = retryable ? LocalMessageState.queued.rawValue : LocalMessageState.failed.rawValue
                }
            }
            let request = OutboxOperationEntity.fetchRequest()
            if let operationID {
                request.predicate = NSPredicate(format: "operationID == %@", operationID.uuidString.lowercased())
            } else if !clientID.isEmpty {
                request.predicate = NSPredicate(format: "clientMessageID == %@ AND type == %@", clientID, type.rawValue)
            }
            let rows = (try? self.context.fetch(request)) ?? []
            for op in rows {
                op.state = retryable ? OutboxOpState.queued.rawValue : OutboxOpState.failed.rawValue
                op.nextAttemptAt = retryAt
                op.lastInternalError = String(internalError.prefix(200))
            }
            self.save()
        }
    }

    func retryFailed(clientID: String) async {
        await context.perform {
            self.message(clientID: clientID)?.localState = LocalMessageState.queued.rawValue
            let request = OutboxOperationEntity.fetchRequest()
            request.predicate = NSPredicate(format: "clientMessageID == %@ AND state == %@", clientID, OutboxOpState.failed.rawValue)
            for op in (try? self.context.fetch(request)) ?? [] {
                op.state = OutboxOpState.queued.rawValue
                op.nextAttemptAt = Date()
            }
            self.save()
        }
    }

    func applySync(_ response: SyncResponse, currentUserUUID: String, generation: Int, gate: SyncGenerationGate, advancesCursor: Bool = true) async -> Int? {
        guard gate.shouldApply(generation) else { return nil }
        await context.perform {
            for dto in response.conversations {
                self.upsertConversation(dto, currentUserUUID: currentUserUUID)
            }
            for dto in response.messages {
                self.upsertMessage(dto, currentUserUUID: currentUserUUID)
            }
            for read in response.reads {
                self.upsertRead(read)
            }
            for ack in response.acks ?? [] {
                if ack.userUUID == currentUserUUID {
                    self.upsertAck(ack)
                }
            }
            if advancesCursor {
                self.setMeta("syncCursor", value: String(response.cursor))
            }
            self.save()
        }
        return advancesCursor ? response.cursor : nil
    }

    func upsertConversation(_ dto: ConversationDTO, currentUserUUID: String) {
        let entity = conversation(dto.conversationUUID) ?? ConversationEntity(context: context)
        entity.conversationUUID = dto.conversationUUID
        entity.conversationType = dto.conversationType
        entity.title = displayTitle(for: dto, currentUserUUID: currentUserUUID)
        entity.lastMessageSeq = Int64(dto.lastMessageSeq)
        entity.lastMessageAt = Self.parseDate(dto.lastMessageAtUTC)
        entity.preview = dto.preview?.body ?? entity.preview
        entity.unreadCount = Int64(dto.unreadCount)
        entity.updatedAt = entity.lastMessageAt ?? Self.parseDate(dto.createdAtUTC)

        let existing = members(dto.conversationUUID)
        for member in existing {
            context.delete(member)
        }
        for member in dto.members {
            let row = MemberEntity(context: context)
            row.conversationUUID = dto.conversationUUID
            row.userUUID = member.user.uuid
            row.name = member.user.name
            row.role = member.user.role
            row.lastReadSeq = Int64(member.lastReadSeq)
            row.lastDeliveredSeq = Int64(member.lastDeliveredSeq)
            upsertPerson(member.user)
        }
    }

    func syncCursor() async -> Int {
        await context.perform {
            Int(self.meta("syncCursor") ?? "0") ?? 0
        }
    }

    func setSyncCursor(_ cursor: Int) async {
        await context.perform {
            self.setMeta("syncCursor", value: String(cursor))
            self.save()
        }
    }

    func firstConversationUUID() async -> String? {
        await context.perform {
            let request = ConversationEntity.fetchRequest()
            request.fetchLimit = 1
            request.sortDescriptors = [NSSortDescriptor(key: "updatedAt", ascending: false)]
            return try? self.context.fetch(request).first?.conversationUUID
        }
    }

    func messageCount(conversationUUID: String) async -> Int {
        await context.perform {
            let request = MessageEntity.fetchRequest()
            request.predicate = NSPredicate(format: "conversationUUID == %@", conversationUUID)
            return (try? self.context.count(for: request)) ?? 0
        }
    }

    func bodies(conversationUUID: String) async -> [String] {
        await context.perform {
            let request = MessageEntity.fetchRequest()
            request.predicate = NSPredicate(format: "conversationUUID == %@", conversationUUID)
            request.sortDescriptors = [
                NSSortDescriptor(key: "seq", ascending: true),
                NSSortDescriptor(key: "createdAt", ascending: true)
            ]
            return ((try? self.context.fetch(request)) ?? []).map(\.body)
        }
    }

    func duplicateClientIDs(conversationUUID: String) async -> [String] {
        await context.perform {
            let request = MessageEntity.fetchRequest()
            request.predicate = NSPredicate(format: "conversationUUID == %@", conversationUUID)
            let rows = (try? self.context.fetch(request)) ?? []
            var seen = Set<String>()
            var duplicates: [String] = []
            for row in rows {
                if !seen.insert(row.clientID).inserted {
                    duplicates.append(row.clientID)
                }
            }
            return duplicates
        }
    }

    func applyMessage(_ dto: MessageDTO, currentUserUUID: String) async {
        await context.perform {
            self.upsertMessage(dto, currentUserUUID: currentUserUUID)
            self.save()
        }
    }

    private func upsertMessage(_ dto: MessageDTO, currentUserUUID: String) {
        let existing = message(clientID: dto.clientID) ?? message(uuid: dto.messageUUID) ?? MessageEntity(context: context)
        existing.messageUUID = dto.messageUUID
        existing.clientID = dto.clientID
        existing.conversationUUID = dto.conversationUUID
        existing.seq = Int64(dto.seq)
        existing.body = dto.body
        existing.senderUserUUID = dto.senderUserUUID ?? ""
        existing.senderType = dto.senderType
        existing.createdAt = Self.parseDate(dto.createdAtUTC) ?? existing.createdAt
        existing.localState = LocalMessageState.serverReceived.rawValue
        existing.isFromMe = dto.senderType != "system" && dto.senderUserUUID == currentUserUUID
        existing.attachmentsJSON = Self.mergeAttachmentsJSON(existing.attachmentsJSON, incoming: dto.attachments)
        existing.requiresAcknowledgement = dto.requiresAcknowledgement
        existing.replyAllowed = dto.replyAllowed
        existing.senderDisplayName = dto.senderDisplayName
        existing.replyToJSON = Self.encodeJSON(dto.replyTo)
        existing.reactionsJSON = Self.encodeJSON(dto.reactions)
        if existing.createdAt.timeIntervalSince1970 == 0 {
            existing.createdAt = Date()
        }
    }

    private func upsertRead(_ dto: ReadCursorDTO) {
        let request = MemberEntity.fetchRequest()
        request.predicate = NSPredicate(format: "conversationUUID == %@ AND userUUID == %@", dto.conversationUUID, dto.userUUID)
        if let member = try? context.fetch(request).first {
            member.lastReadSeq = max(member.lastReadSeq, Int64(dto.lastReadSeq))
            member.lastDeliveredSeq = max(member.lastDeliveredSeq, Int64(dto.lastDeliveredSeq))
        }
    }

    private func upsertAck(_ dto: AckDTO) {
        if let message = message(uuid: dto.messageUUID) {
            message.acknowledgedAt = Self.parseDate(dto.acknowledgedAtUTC) ?? message.acknowledgedAt ?? Date()
        }
    }

    func markAcknowledged(messageUUID: String, at: Date = Date()) async {
        await context.perform {
            if let message = self.message(uuid: messageUUID) {
                message.acknowledgedAt = at
                self.save()
            }
        }
    }

    func pendingActionCount() async -> Int {
        await context.perform {
            let request = MessageEntity.fetchRequest()
            request.predicate = NSPredicate(format: "requiresAcknowledgement == YES AND acknowledgedAt == nil AND senderType == %@", "system")
            return (try? self.context.count(for: request)) ?? 0
        }
    }

    func pendingActions() async -> [MessageEntity] {
        await context.perform {
            let request = MessageEntity.fetchRequest()
            request.predicate = NSPredicate(format: "requiresAcknowledgement == YES AND acknowledgedAt == nil AND senderType == %@", "system")
            request.sortDescriptors = [NSSortDescriptor(key: "createdAt", ascending: false)]
            return (try? self.context.fetch(request)) ?? []
        }
    }

    private func upsertPerson(_ user: PublicUser) {
        let request = PersonEntity.fetchRequest()
        request.predicate = NSPredicate(format: "userUUID == %@", user.uuid)
        let person = (try? context.fetch(request).first) ?? PersonEntity(context: context)
        person.userUUID = user.uuid
        person.name = user.name
        person.email = user.email
        person.role = user.role
    }

    private func conversation(_ uuid: String) -> ConversationEntity? {
        let request = ConversationEntity.fetchRequest()
        request.predicate = NSPredicate(format: "conversationUUID == %@", uuid)
        request.fetchLimit = 1
        return try? context.fetch(request).first
    }

    private func message(clientID: String) -> MessageEntity? {
        let request = MessageEntity.fetchRequest()
        request.predicate = NSPredicate(format: "clientID == %@", clientID)
        request.fetchLimit = 1
        return try? context.fetch(request).first
    }

    private func message(uuid: String) -> MessageEntity? {
        guard !uuid.isEmpty else { return nil }
        let request = MessageEntity.fetchRequest()
        request.predicate = NSPredicate(format: "messageUUID == %@", uuid)
        request.fetchLimit = 1
        return try? context.fetch(request).first
    }

    private func members(_ conversationUUID: String) -> [MemberEntity] {
        let request = MemberEntity.fetchRequest()
        request.predicate = NSPredicate(format: "conversationUUID == %@", conversationUUID)
        return (try? context.fetch(request)) ?? []
    }

    private func finishOutbox(clientID: String, type: OutboxOpType, state: OutboxOpState) {
        let request = OutboxOperationEntity.fetchRequest()
        request.predicate = NSPredicate(format: "clientMessageID == %@ AND type == %@", clientID, type.rawValue)
        for op in (try? context.fetch(request)) ?? [] {
            op.state = state.rawValue
        }
    }

    private func setOutboxState(operationID: String, state: OutboxOpState) {
        let request = OutboxOperationEntity.fetchRequest()
        request.predicate = NSPredicate(format: "operationID == %@", operationID)
        if let op = try? context.fetch(request).first {
            op.state = state.rawValue
        }
    }

    private static func encodeAttachments(_ attachments: [AttachmentDTO]) -> String {
        let json = encodeJSON(attachments)
        return json.isEmpty ? "[]" : json
    }

    private static func encodeJSON<T: Encodable>(_ value: T?) -> String {
        guard let value, let data = try? JSONEncoder().encode(value), let json = String(data: data, encoding: .utf8) else {
            return ""
        }
        return json
    }

    private static func mergeAttachmentsJSON(_ existingJSON: String, incoming: [AttachmentDTO]) -> String {
        let existing = (try? JSONDecoder().decode([AttachmentDTO].self, from: Data(existingJSON.utf8))) ?? []
        let localPaths = Dictionary(uniqueKeysWithValues: existing.compactMap { dto -> (String, String)? in
            guard let path = dto.localPath, !path.isEmpty else { return nil }
            return (dto.attachmentUUID, path)
        })
        let merged = incoming.map { dto in
            var copy = dto
            if copy.localPath == nil {
                copy.localPath = localPaths[dto.attachmentUUID]
            }
            return copy
        }
        return encodeAttachments(merged.isEmpty ? existing : merged)
    }

    private func displayTitle(for dto: ConversationDTO, currentUserUUID: String) -> String {
        if dto.conversationType == "group" || dto.conversationType == "announcement" || dto.conversationType == "system", !dto.title.isEmpty {
            return dto.title
        }
        return dto.members.first(where: { $0.user.uuid != currentUserUUID })?.user.name ?? dto.title
    }

    private func meta(_ key: String) -> String? {
        let request = MetaEntity.fetchRequest()
        request.predicate = NSPredicate(format: "key == %@", key)
        return try? context.fetch(request).first?.value
    }

    private func setMeta(_ key: String, value: String) {
        let request = MetaEntity.fetchRequest()
        request.predicate = NSPredicate(format: "key == %@", key)
        let row = (try? context.fetch(request).first) ?? MetaEntity(context: context)
        row.key = key
        row.value = value
    }

    private func save() {
        guard context.hasChanges else { return }
        try? context.save()
    }

    private static func parseDate(_ value: String?) -> Date? {
        guard let value, !value.isEmpty else { return nil }
        let formats = [
            "yyyy-MM-dd HH:mm:ss.SSS",
            "yyyy-MM-dd HH:mm:ss",
            "yyyy-MM-dd'T'HH:mm:ss.SSXXXXX",
            "yyyy-MM-dd'T'HH:mm:ssXXXXX"
        ]
        let formatter = DateFormatter()
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.timeZone = TimeZone(secondsFromGMT: 0)
        for format in formats {
            formatter.dateFormat = format
            if let date = formatter.date(from: value) {
                return date
            }
        }
        return nil
    }
}

private extension OutboxOperationEntity {
    func asOutboxOp() -> OutboxOp? {
        guard let id = UUID(uuidString: operationID),
              let type = OutboxOpType(rawValue: type),
              let state = OutboxOpState(rawValue: state) else {
            return nil
        }
        let depends = (try? JSONDecoder().decode([UUID].self, from: Data(dependsOnJSON.utf8))) ?? []
        return OutboxOp(
            id: id,
            type: type,
            state: state,
            dependsOn: depends,
            attemptCount: Int(attemptCount),
            nextAttemptAt: nextAttemptAt,
            payloadJSON: payloadJSON
        )
    }
}

extension ConversationEntity {
    @nonobjc class func fetchRequest() -> NSFetchRequest<ConversationEntity> {
        NSFetchRequest<ConversationEntity>(entityName: "Conversation")
    }
}

extension MessageEntity {
    @nonobjc class func fetchRequest() -> NSFetchRequest<MessageEntity> {
        NSFetchRequest<MessageEntity>(entityName: "Message")
    }
}

extension MemberEntity {
    @nonobjc class func fetchRequest() -> NSFetchRequest<MemberEntity> {
        NSFetchRequest<MemberEntity>(entityName: "Member")
    }
}

extension PersonEntity {
    @nonobjc class func fetchRequest() -> NSFetchRequest<PersonEntity> {
        NSFetchRequest<PersonEntity>(entityName: "Person")
    }
}

extension OutboxOperationEntity {
    @nonobjc class func fetchRequest() -> NSFetchRequest<OutboxOperationEntity> {
        NSFetchRequest<OutboxOperationEntity>(entityName: "OutboxOperation")
    }
}

extension MetaEntity {
    @nonobjc class func fetchRequest() -> NSFetchRequest<MetaEntity> {
        NSFetchRequest<MetaEntity>(entityName: "Meta")
    }
}
