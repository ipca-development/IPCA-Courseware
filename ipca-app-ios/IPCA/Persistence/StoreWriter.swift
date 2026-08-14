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

    func enqueueSend(conversationUUID: String, body: String, senderUUID: String) async -> String {
        let clientID = UUID().uuidString.lowercased()
        await context.perform {
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

            if let conversation = self.conversation(conversationUUID) {
                conversation.preview = body
                conversation.lastMessageAt = Date()
                conversation.updatedAt = Date()
            }

            let payload: [String: String] = [
                "conversation_uuid": conversationUUID,
                "client_id": clientID,
                "body": body
            ]
            let op = OutboxOperationEntity(context: self.context)
            op.operationID = UUID().uuidString.lowercased()
            op.type = OutboxOpType.sendMessage.rawValue
            op.state = OutboxOpState.queued.rawValue
            op.dependsOnJSON = "[]"
            op.payloadJSON = (try? JSONSerialization.data(withJSONObject: payload)) ?? Data()
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
            }
            self.finishOutbox(clientID: clientID, state: .succeeded)
            self.save()
        }
    }

    func markSendFailed(clientID: String, retryable: Bool, retryAt: Date, internalError: String) async {
        await context.perform {
            if let message = self.message(clientID: clientID) {
                message.localState = retryable ? LocalMessageState.queued.rawValue : LocalMessageState.failed.rawValue
            }
            let request = OutboxOperationEntity.fetchRequest()
            request.predicate = NSPredicate(format: "clientMessageID == %@", clientID)
            if let op = try? self.context.fetch(request).first {
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
            request.predicate = NSPredicate(format: "clientMessageID == %@", clientID)
            if let op = try? self.context.fetch(request).first {
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
        existing.isFromMe = dto.senderUserUUID == currentUserUUID
        if existing.createdAt.timeIntervalSince1970 == 0 {
            existing.createdAt = Date()
        }
    }

    private func upsertRead(_ dto: ReadCursorDTO) {
        let request = MemberEntity.fetchRequest()
        request.predicate = NSPredicate(format: "conversationUUID == %@ AND userUUID == %@", dto.conversationUUID, dto.userUUID)
        if let member = try? context.fetch(request).first {
            member.lastReadSeq = Int64(dto.lastReadSeq)
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

    private func finishOutbox(clientID: String, state: OutboxOpState) {
        let request = OutboxOperationEntity.fetchRequest()
        request.predicate = NSPredicate(format: "clientMessageID == %@", clientID)
        if let op = try? context.fetch(request).first {
            op.state = state.rawValue
        }
    }

    private func displayTitle(for dto: ConversationDTO, currentUserUUID: String) -> String {
        if dto.conversationType == "group", !dto.title.isEmpty {
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
