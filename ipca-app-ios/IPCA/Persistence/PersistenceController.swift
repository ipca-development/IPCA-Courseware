import CoreData
import Foundation

enum LocalMessageState: String {
    case queued
    case sending
    case serverReceived = "server_received"
    case failed
}

final class PersistenceController {
    static let shared = PersistenceController()

    let container: NSPersistentContainer

    init(inMemory: Bool = false) {
        let model = Self.makeModel()
        container = NSPersistentContainer(name: "IPCA", managedObjectModel: model)
        if inMemory {
            let description = NSPersistentStoreDescription()
            description.type = NSInMemoryStoreType
            container.persistentStoreDescriptions = [description]
        } else {
            let description = container.persistentStoreDescriptions.first
            description?.setOption(true as NSNumber, forKey: NSPersistentHistoryTrackingKey)
            description?.setOption(true as NSNumber, forKey: NSPersistentStoreRemoteChangeNotificationPostOptionKey)
        }
        container.loadPersistentStores { _, error in
            if let error {
                fatalError("Core Data failed: \(error)")
            }
        }
        container.viewContext.mergePolicy = NSMergeByPropertyObjectTrumpMergePolicy
        container.viewContext.automaticallyMergesChangesFromParent = true
    }

    var viewContext: NSManagedObjectContext { container.viewContext }

    func newBackgroundContext() -> NSManagedObjectContext {
        let context = container.newBackgroundContext()
        context.mergePolicy = NSMergeByPropertyObjectTrumpMergePolicy
        return context
    }

    static func makeModel() -> NSManagedObjectModel {
        let model = NSManagedObjectModel()

        let conversation = NSEntityDescription()
        conversation.name = "Conversation"
        conversation.managedObjectClassName = "ConversationEntity"
        conversation.properties = [
            string("conversationUUID", indexed: true),
            string("conversationType"),
            string("title"),
            int64("lastMessageSeq"),
            optionalDate("lastMessageAt"),
            string("preview"),
            int64("unreadCount"),
            optionalDate("updatedAt")
        ]

        let message = NSEntityDescription()
        message.name = "Message"
        message.managedObjectClassName = "MessageEntity"
        message.properties = [
            string("messageUUID"),
            string("clientID", indexed: true),
            string("conversationUUID", indexed: true),
            int64("seq"),
            string("body"),
            string("senderUserUUID"),
            string("senderType"),
            date("createdAt"),
            string("localState"),
            bool("isFromMe")
        ]

        let member = NSEntityDescription()
        member.name = "Member"
        member.managedObjectClassName = "MemberEntity"
        member.properties = [
            string("conversationUUID", indexed: true),
            string("userUUID"),
            string("name"),
            string("role"),
            int64("lastReadSeq")
        ]

        let person = NSEntityDescription()
        person.name = "Person"
        person.managedObjectClassName = "PersonEntity"
        person.properties = [
            string("userUUID", indexed: true),
            string("name"),
            string("email"),
            string("role")
        ]

        let outbox = NSEntityDescription()
        outbox.name = "OutboxOperation"
        outbox.managedObjectClassName = "OutboxOperationEntity"
        outbox.properties = [
            string("operationID", indexed: true),
            string("type"),
            string("state"),
            string("dependsOnJSON"),
            data("payloadJSON"),
            string("clientMessageID"),
            string("conversationUUID"),
            int64("attemptCount"),
            date("nextAttemptAt"),
            string("lastInternalError"),
            date("createdAt")
        ]

        let meta = NSEntityDescription()
        meta.name = "Meta"
        meta.managedObjectClassName = "MetaEntity"
        meta.properties = [
            string("key", indexed: true),
            string("value")
        ]

        model.entities = [conversation, message, member, person, outbox, meta]
        return model
    }

    private static func string(_ name: String, indexed: Bool = false) -> NSAttributeDescription {
        attribute(name, .stringAttributeType, indexed: indexed)
    }

    private static func int64(_ name: String) -> NSAttributeDescription {
        attribute(name, .integer64AttributeType)
    }

    private static func bool(_ name: String) -> NSAttributeDescription {
        attribute(name, .booleanAttributeType)
    }

    private static func date(_ name: String) -> NSAttributeDescription {
        attribute(name, .dateAttributeType)
    }

    private static func optionalDate(_ name: String) -> NSAttributeDescription {
        let attr = attribute(name, .dateAttributeType)
        attr.isOptional = true
        return attr
    }

    private static func data(_ name: String) -> NSAttributeDescription {
        attribute(name, .binaryDataAttributeType)
    }

    private static func attribute(_ name: String, _ type: NSAttributeType, indexed: Bool = false) -> NSAttributeDescription {
        let attr = NSAttributeDescription()
        attr.name = name
        attr.attributeType = type
        attr.isOptional = false
        if type == .stringAttributeType {
            attr.defaultValue = ""
        }
        if type == .integer64AttributeType {
            attr.defaultValue = 0
        }
        if type == .booleanAttributeType {
            attr.defaultValue = false
        }
        return attr
    }
}

@objc(ConversationEntity)
final class ConversationEntity: NSManagedObject {
    @NSManaged var conversationUUID: String
    @NSManaged var conversationType: String
    @NSManaged var title: String
    @NSManaged var lastMessageSeq: Int64
    @NSManaged var lastMessageAt: Date?
    @NSManaged var preview: String
    @NSManaged var unreadCount: Int64
    @NSManaged var updatedAt: Date?
}

@objc(MessageEntity)
final class MessageEntity: NSManagedObject {
    @NSManaged var messageUUID: String
    @NSManaged var clientID: String
    @NSManaged var conversationUUID: String
    @NSManaged var seq: Int64
    @NSManaged var body: String
    @NSManaged var senderUserUUID: String
    @NSManaged var senderType: String
    @NSManaged var createdAt: Date
    @NSManaged var localState: String
    @NSManaged var isFromMe: Bool
}

@objc(MemberEntity)
final class MemberEntity: NSManagedObject {
    @NSManaged var conversationUUID: String
    @NSManaged var userUUID: String
    @NSManaged var name: String
    @NSManaged var role: String
    @NSManaged var lastReadSeq: Int64
}

@objc(PersonEntity)
final class PersonEntity: NSManagedObject {
    @NSManaged var userUUID: String
    @NSManaged var name: String
    @NSManaged var email: String
    @NSManaged var role: String
}

@objc(OutboxOperationEntity)
final class OutboxOperationEntity: NSManagedObject {
    @NSManaged var operationID: String
    @NSManaged var type: String
    @NSManaged var state: String
    @NSManaged var dependsOnJSON: String
    @NSManaged var payloadJSON: Data
    @NSManaged var clientMessageID: String
    @NSManaged var conversationUUID: String
    @NSManaged var attemptCount: Int64
    @NSManaged var nextAttemptAt: Date
    @NSManaged var lastInternalError: String
    @NSManaged var createdAt: Date
}

@objc(MetaEntity)
final class MetaEntity: NSManagedObject {
    @NSManaged var key: String
    @NSManaged var value: String
}
