import Foundation

struct APIErrorPayload: Decodable {
    var ok: Bool?
    var error: String?
    var errorCode: String?

    enum CodingKeys: String, CodingKey {
        case ok, error
        case errorCode = "error_code"
    }
}

struct PublicUser: Codable, Hashable, Identifiable {
    var id: Int
    var uuid: String
    var email: String
    var name: String
    var firstName: String
    var lastName: String
    var role: String
    var photoPath: String

    enum CodingKeys: String, CodingKey {
        case id, uuid, email, name, role
        case firstName = "first_name"
        case lastName = "last_name"
        case photoPath = "photo_path"
    }
}

struct PublicDevice: Codable {
    var deviceUUID: String
    var platform: String
    var model: String
    var appVersion: String
    var lastSeenAtUTC: String?

    enum CodingKeys: String, CodingKey {
        case model, platform
        case deviceUUID = "device_uuid"
        case appVersion = "app_version"
        case lastSeenAtUTC = "last_seen_at_utc"
    }
}

struct ServerCapabilities: Codable, Equatable {
    var protocolVersion: Int
    var minAppVersion: String
    var minIOSVersion: String
    var messagingEnabled: Bool
    var groupsEnabled: Bool
    var attachmentsEnabled: Bool
    var systemMessagesEnabled: Bool
    var trainingEnabled: Bool
    var communityEnabled: Bool
    var communityPostingEnabled: Bool

    enum CodingKeys: String, CodingKey {
        case protocolVersion = "protocol_version"
        case minAppVersion = "min_app_version"
        case minIOSVersion = "min_ios_version"
        case messagingEnabled = "messaging_enabled"
        case groupsEnabled = "groups_enabled"
        case attachmentsEnabled = "attachments_enabled"
        case systemMessagesEnabled = "system_messages_enabled"
        case trainingEnabled = "training_enabled"
        case communityEnabled = "community_enabled"
        case communityPostingEnabled = "community_posting_enabled"
    }

    static let disabled = ServerCapabilities(
        protocolVersion: 1,
        minAppVersion: "1.0.0",
        minIOSVersion: "17.0",
        messagingEnabled: false,
        groupsEnabled: false,
        attachmentsEnabled: false,
        systemMessagesEnabled: false,
        trainingEnabled: false,
        communityEnabled: false,
        communityPostingEnabled: false
    )
}

struct LoginResponse: Decodable {
    var ok: Bool
    var token: String
    var user: PublicUser
    var device: PublicDevice
    var capabilities: ServerCapabilities
}

struct BootstrapResponse: Decodable {
    var ok: Bool
    var protocolVersion: Int
    var minAppVersion: String
    var minIOSVersion: String
    var updateRequired: Bool
    var user: PublicUser
    var device: PublicDevice
    var capabilities: ServerCapabilities
    var unreadCount: Int
    var needsActionCount: Int

    enum CodingKeys: String, CodingKey {
        case ok, user, device, capabilities
        case protocolVersion = "protocol_version"
        case minAppVersion = "min_app_version"
        case minIOSVersion = "min_ios_version"
        case updateRequired = "update_required"
        case unreadCount = "unread_count"
        case needsActionCount = "needs_action_count"
    }
}

struct ConversationMember: Codable, Hashable {
    var user: PublicUser
    var memberRole: String
    var lastReadSeq: Int
    var lastReadAtUTC: String?

    enum CodingKeys: String, CodingKey {
        case user
        case memberRole = "member_role"
        case lastReadSeq = "last_read_seq"
        case lastReadAtUTC = "last_read_at_utc"
    }
}

struct ConversationPreview: Codable, Hashable {
    var messageUUID: String
    var seq: Int
    var body: String
    var senderUserID: Int?
    var createdAtUTC: String

    enum CodingKeys: String, CodingKey {
        case seq, body
        case messageUUID = "message_uuid"
        case senderUserID = "sender_user_id"
        case createdAtUTC = "created_at_utc"
    }
}

struct ConversationDTO: Codable, Hashable, Identifiable {
    var conversationUUID: String
    var conversationType: String
    var title: String
    var lastMessageSeq: Int
    var lastMessageAtUTC: String?
    var createdAtUTC: String
    var members: [ConversationMember]
    var preview: ConversationPreview?
    var unreadCount: Int
    var viewerLastReadSeq: Int

    var id: String { conversationUUID }

    enum CodingKeys: String, CodingKey {
        case title, members, preview
        case conversationUUID = "conversation_uuid"
        case conversationType = "conversation_type"
        case lastMessageSeq = "last_message_seq"
        case lastMessageAtUTC = "last_message_at_utc"
        case createdAtUTC = "created_at_utc"
        case unreadCount = "unread_count"
        case viewerLastReadSeq = "viewer_last_read_seq"
    }
}

struct MessageDTO: Codable, Hashable, Identifiable {
    var messageUUID: String
    var conversationUUID: String
    var seq: Int
    var clientID: String
    var senderUserUUID: String?
    var senderType: String
    var body: String
    var createdAtUTC: String
    var serverReceived: Bool

    var id: String { messageUUID }

    enum CodingKeys: String, CodingKey {
        case body, seq
        case messageUUID = "message_uuid"
        case conversationUUID = "conversation_uuid"
        case clientID = "client_id"
        case senderUserUUID = "sender_user_uuid"
        case senderType = "sender_type"
        case createdAtUTC = "created_at_utc"
        case serverReceived = "server_received"
    }
}

struct SyncResponse: Decodable {
    var ok: Bool
    var cursor: Int
    var hasMore: Bool
    var conversations: [ConversationDTO]
    var messages: [MessageDTO]
    var reads: [ReadCursorDTO]

    enum CodingKeys: String, CodingKey {
        case ok, cursor, conversations, messages, reads
        case hasMore = "has_more"
    }
}

struct ReadCursorDTO: Codable, Hashable {
    var conversationUUID: String
    var userUUID: String
    var lastReadSeq: Int
    var lastReadAtUTC: String?

    enum CodingKeys: String, CodingKey {
        case conversationUUID = "conversation_uuid"
        case userUUID = "user_uuid"
        case lastReadSeq = "last_read_seq"
        case lastReadAtUTC = "last_read_at_utc"
    }
}

struct ConversationEnvelope: Decodable {
    var ok: Bool
    var conversation: ConversationDTO
}

struct ConversationsEnvelope: Decodable {
    var ok: Bool
    var conversations: [ConversationDTO]
}

struct MessageEnvelope: Decodable {
    var ok: Bool
    var message: MessageDTO
}

struct MessagesEnvelope: Decodable {
    var ok: Bool
    var messages: [MessageDTO]
}

struct DirectoryEnvelope: Decodable {
    var ok: Bool
    var people: [PublicUser]
}

struct OKEnvelope: Decodable {
    var ok: Bool
}

enum APIClientError: LocalizedError {
    case invalidURL
    case decoding
    case server(status: Int, message: String, code: String)
    case transport

    var errorDescription: String? {
        switch self {
        case .invalidURL:
            return "Enter a valid server address."
        case .decoding:
            return "The server returned an unexpected response."
        case .transport:
            return "Couldn't reach IPCA. Try again in a moment."
        case .server(_, let message, _):
            return message
        }
    }

    var errorCode: String? {
        if case .server(_, _, let code) = self { return code }
        return nil
    }

    var httpStatus: Int? {
        if case .server(let status, _, _) = self { return status }
        return nil
    }
}
