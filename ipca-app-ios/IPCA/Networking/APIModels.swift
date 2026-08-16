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
    var trainingVideosEnabled: Bool
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
        case trainingVideosEnabled = "training_videos_enabled"
        case communityEnabled = "community_enabled"
        case communityPostingEnabled = "community_posting_enabled"
    }

    init(
        protocolVersion: Int,
        minAppVersion: String,
        minIOSVersion: String,
        messagingEnabled: Bool,
        groupsEnabled: Bool,
        attachmentsEnabled: Bool,
        systemMessagesEnabled: Bool,
        trainingEnabled: Bool,
        trainingVideosEnabled: Bool,
        communityEnabled: Bool,
        communityPostingEnabled: Bool
    ) {
        self.protocolVersion = protocolVersion
        self.minAppVersion = minAppVersion
        self.minIOSVersion = minIOSVersion
        self.messagingEnabled = messagingEnabled
        self.groupsEnabled = groupsEnabled
        self.attachmentsEnabled = attachmentsEnabled
        self.systemMessagesEnabled = systemMessagesEnabled
        self.trainingEnabled = trainingEnabled
        self.trainingVideosEnabled = trainingVideosEnabled
        self.communityEnabled = communityEnabled
        self.communityPostingEnabled = communityPostingEnabled
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        protocolVersion = try container.decodeIfPresent(Int.self, forKey: .protocolVersion) ?? 1
        minAppVersion = try container.decodeIfPresent(String.self, forKey: .minAppVersion) ?? "1.0.0"
        minIOSVersion = try container.decodeIfPresent(String.self, forKey: .minIOSVersion) ?? "17.0"
        messagingEnabled = try container.decodeIfPresent(Bool.self, forKey: .messagingEnabled) ?? false
        groupsEnabled = try container.decodeIfPresent(Bool.self, forKey: .groupsEnabled) ?? false
        attachmentsEnabled = try container.decodeIfPresent(Bool.self, forKey: .attachmentsEnabled) ?? false
        systemMessagesEnabled = try container.decodeIfPresent(Bool.self, forKey: .systemMessagesEnabled) ?? false
        trainingEnabled = try container.decodeIfPresent(Bool.self, forKey: .trainingEnabled) ?? false
        trainingVideosEnabled = try container.decodeIfPresent(Bool.self, forKey: .trainingVideosEnabled) ?? false
        communityEnabled = try container.decodeIfPresent(Bool.self, forKey: .communityEnabled) ?? false
        communityPostingEnabled = try container.decodeIfPresent(Bool.self, forKey: .communityPostingEnabled) ?? false
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
        trainingVideosEnabled: false,
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
    var lastDeliveredSeq: Int
    var lastDeliveredAtUTC: String?

    enum CodingKeys: String, CodingKey {
        case user
        case memberRole = "member_role"
        case lastReadSeq = "last_read_seq"
        case lastReadAtUTC = "last_read_at_utc"
        case lastDeliveredSeq = "last_delivered_seq"
        case lastDeliveredAtUTC = "last_delivered_at_utc"
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        user = try container.decode(PublicUser.self, forKey: .user)
        memberRole = try container.decode(String.self, forKey: .memberRole)
        lastReadSeq = try container.decode(Int.self, forKey: .lastReadSeq)
        lastReadAtUTC = try container.decodeIfPresent(String.self, forKey: .lastReadAtUTC)
        lastDeliveredSeq = try container.decodeIfPresent(Int.self, forKey: .lastDeliveredSeq) ?? 0
        lastDeliveredAtUTC = try container.decodeIfPresent(String.self, forKey: .lastDeliveredAtUTC)
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
    var replyAllowed: Bool?

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
        case replyAllowed = "reply_allowed"
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
    var attachments: [AttachmentDTO]
    var requiresAcknowledgement: Bool
    var replyAllowed: Bool
    var senderDisplayName: String
    var replyTo: ReplyToDTO?
    var reactions: [ReactionDTO]

    var id: String { messageUUID }

    enum CodingKeys: String, CodingKey {
        case body, seq, attachments
        case messageUUID = "message_uuid"
        case conversationUUID = "conversation_uuid"
        case clientID = "client_id"
        case senderUserUUID = "sender_user_uuid"
        case senderType = "sender_type"
        case createdAtUTC = "created_at_utc"
        case serverReceived = "server_received"
        case requiresAcknowledgement = "requires_acknowledgement"
        case replyAllowed = "reply_allowed"
        case senderDisplayName = "sender_display_name"
        case replyTo = "reply_to"
        case reactions
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        messageUUID = try container.decode(String.self, forKey: .messageUUID)
        conversationUUID = try container.decode(String.self, forKey: .conversationUUID)
        seq = try container.decode(Int.self, forKey: .seq)
        clientID = try container.decode(String.self, forKey: .clientID)
        senderUserUUID = try container.decodeIfPresent(String.self, forKey: .senderUserUUID)
        senderType = try container.decodeIfPresent(String.self, forKey: .senderType) ?? "user"
        body = try container.decodeIfPresent(String.self, forKey: .body) ?? ""
        createdAtUTC = try container.decode(String.self, forKey: .createdAtUTC)
        serverReceived = try container.decodeIfPresent(Bool.self, forKey: .serverReceived) ?? true
        attachments = try container.decodeIfPresent([AttachmentDTO].self, forKey: .attachments) ?? []
        requiresAcknowledgement = try container.decodeIfPresent(Bool.self, forKey: .requiresAcknowledgement) ?? false
        replyAllowed = try container.decodeIfPresent(Bool.self, forKey: .replyAllowed) ?? true
        senderDisplayName = try container.decodeIfPresent(String.self, forKey: .senderDisplayName) ?? ""
        replyTo = try container.decodeIfPresent(ReplyToDTO.self, forKey: .replyTo)
        reactions = try container.decodeIfPresent([ReactionDTO].self, forKey: .reactions) ?? []
    }
}

struct ReplyToDTO: Codable, Hashable {
    var messageUUID: String
    var senderDisplayName: String
    var bodyPreview: String

    enum CodingKeys: String, CodingKey {
        case messageUUID = "message_uuid"
        case senderDisplayName = "sender_display_name"
        case bodyPreview = "body_preview"
    }
}

struct ReactionDTO: Codable, Hashable, Identifiable {
    var emoji: String
    var count: Int
    var reactedByMe: Bool

    var id: String { emoji }

    enum CodingKeys: String, CodingKey {
        case emoji, count
        case reactedByMe = "reacted_by_me"
    }
}

struct AttachmentDTO: Codable, Hashable, Identifiable {
    var attachmentUUID: String
    var filename: String
    var mimeType: String
    var byteSize: Int
    var localPath: String?

    var id: String { attachmentUUID }

    enum CodingKeys: String, CodingKey {
        case filename
        case attachmentUUID = "attachment_uuid"
        case mimeType = "mime_type"
        case byteSize = "byte_size"
        case localPath = "local_path"
    }
}

struct PendingAttachment: Identifiable, Equatable {
    var id: String { attachmentUUID }
    var attachmentUUID: String
    var filename: String
    var mimeType: String
    var byteSize: Int
    var localURL: URL
}

struct SyncResponse: Decodable {
    var ok: Bool
    var cursor: Int
    var hasMore: Bool
    var conversations: [ConversationDTO]
    var messages: [MessageDTO]
    var reads: [ReadCursorDTO]
    var acks: [AckDTO]?

    enum CodingKeys: String, CodingKey {
        case ok, cursor, conversations, messages, reads, acks
        case hasMore = "has_more"
    }
}

struct ReadCursorDTO: Codable, Hashable {
    var conversationUUID: String
    var userUUID: String
    var lastReadSeq: Int
    var lastReadAtUTC: String?
    var lastDeliveredSeq: Int
    var lastDeliveredAtUTC: String?

    enum CodingKeys: String, CodingKey {
        case conversationUUID = "conversation_uuid"
        case userUUID = "user_uuid"
        case lastReadSeq = "last_read_seq"
        case lastReadAtUTC = "last_read_at_utc"
        case lastDeliveredSeq = "last_delivered_seq"
        case lastDeliveredAtUTC = "last_delivered_at_utc"
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        conversationUUID = try container.decode(String.self, forKey: .conversationUUID)
        userUUID = try container.decode(String.self, forKey: .userUUID)
        lastReadSeq = try container.decodeIfPresent(Int.self, forKey: .lastReadSeq) ?? 0
        lastReadAtUTC = try container.decodeIfPresent(String.self, forKey: .lastReadAtUTC)
        lastDeliveredSeq = try container.decodeIfPresent(Int.self, forKey: .lastDeliveredSeq) ?? 0
        lastDeliveredAtUTC = try container.decodeIfPresent(String.self, forKey: .lastDeliveredAtUTC)
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

struct AttachmentPresignEnvelope: Decodable {
    var ok: Bool
    var attachmentUUID: String
    var putURL: String
    var headers: [String: String]
    var expiresIn: Int

    enum CodingKeys: String, CodingKey {
        case ok, headers
        case attachmentUUID = "attachment_uuid"
        case putURL = "put_url"
        case expiresIn = "expires_in"
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        ok = try container.decodeIfPresent(Bool.self, forKey: .ok) ?? true
        attachmentUUID = try container.decode(String.self, forKey: .attachmentUUID)
        putURL = try container.decode(String.self, forKey: .putURL)
        headers = try container.decodeIfPresent([String: String].self, forKey: .headers) ?? [:]
        expiresIn = try container.decodeIfPresent(Int.self, forKey: .expiresIn) ?? 900
    }
}

struct AttachmentCompleteEnvelope: Decodable {
    var ok: Bool
    var attachmentUUID: String
    var status: String

    enum CodingKeys: String, CodingKey {
        case ok, status
        case attachmentUUID = "attachment_uuid"
    }
}

struct AttachmentDownloadEnvelope: Decodable {
    var ok: Bool
    var getURL: String
    var mimeType: String
    var filename: String

    enum CodingKeys: String, CodingKey {
        case ok
        case getURL = "get_url"
        case mimeType = "mime_type"
        case filename
    }
}

struct AckDTO: Codable, Hashable {
    var acknowledgementUUID: String
    var messageUUID: String
    var conversationUUID: String
    var userUUID: String?
    var acknowledgedAtUTC: String?

    enum CodingKeys: String, CodingKey {
        case acknowledgementUUID = "acknowledgement_uuid"
        case messageUUID = "message_uuid"
        case conversationUUID = "conversation_uuid"
        case userUUID = "user_uuid"
        case acknowledgedAtUTC = "acknowledged_at_utc"
    }
}

struct ActionItemDTO: Codable, Hashable, Identifiable {
    var kind: String
    var messageUUID: String
    var conversationUUID: String
    var title: String
    var body: String
    var createdAtUTC: String
    var source: String

    var id: String { messageUUID }

    enum CodingKeys: String, CodingKey {
        case kind, title, body, source
        case messageUUID = "message_uuid"
        case conversationUUID = "conversation_uuid"
        case createdAtUTC = "created_at_utc"
    }
}

struct ActionsEnvelope: Decodable {
    var ok: Bool
    var needsActionCount: Int
    var actions: [ActionItemDTO]

    enum CodingKeys: String, CodingKey {
        case ok, actions
        case needsActionCount = "needs_action_count"
    }
}

struct TrainingSummaryDTO: Decodable {
    var ok: Bool
    var nextFlight: TrainingFlightDTO?
    var schedule: [TrainingFlightDTO]
    var theory: TrainingTheoryDTO
    var actions: [TrainingActionDTO]
    var deadlines: [TrainingDeadlineDTO]
    var notes: [String]

    enum CodingKeys: String, CodingKey {
        case ok, theory, actions, deadlines, notes, schedule
        case nextFlight = "next_flight"
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        ok = try container.decodeIfPresent(Bool.self, forKey: .ok) ?? true
        nextFlight = try container.decodeIfPresent(TrainingFlightDTO.self, forKey: .nextFlight)
        let decodedSchedule = try container.decodeIfPresent([TrainingFlightDTO].self, forKey: .schedule) ?? []
        if decodedSchedule.isEmpty, let next = nextFlight {
            schedule = [next]
        } else {
            schedule = decodedSchedule
        }
        theory = try container.decode(TrainingTheoryDTO.self, forKey: .theory)
        actions = try container.decodeIfPresent([TrainingActionDTO].self, forKey: .actions) ?? []
        deadlines = try container.decodeIfPresent([TrainingDeadlineDTO].self, forKey: .deadlines) ?? []
        notes = try container.decodeIfPresent([String].self, forKey: .notes) ?? []
    }
}

struct TrainingCrewDTO: Decodable, Hashable {
    var name: String
    var role: String
    var roleLabel: String
    var isSelf: Bool

    enum CodingKeys: String, CodingKey {
        case name, role
        case roleLabel = "role_label"
        case isSelf = "is_self"
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        name = try container.decodeIfPresent(String.self, forKey: .name) ?? ""
        role = try container.decodeIfPresent(String.self, forKey: .role) ?? ""
        roleLabel = try container.decodeIfPresent(String.self, forKey: .roleLabel) ?? ""
        isSelf = try container.decodeIfPresent(Bool.self, forKey: .isSelf) ?? false
    }
}

struct TrainingFlightDTO: Decodable, Hashable, Identifiable {
    var id: String
    var startsAt: String
    var endsAt: String
    var timeZone: String
    var dateLabel: String
    var timeLabel: String
    var whenLabel: String
    var aircraftRegistration: String
    var reservationType: String
    var reservationLabel: String
    var missionCode: String
    var missionName: String
    var missionLabel: String
    var departureAirport: String
    var destinationAirport: String
    var airportChain: [String]
    var route: String
    var role: String
    var crew: [TrainingCrewDTO]
    var withNames: [String]

    enum CodingKeys: String, CodingKey {
        case id, role, crew, route
        case startsAt = "starts_at"
        case endsAt = "ends_at"
        case timeZone = "time_zone"
        case dateLabel = "date_label"
        case timeLabel = "time_label"
        case whenLabel = "when_label"
        case aircraftRegistration = "aircraft_registration"
        case reservationType = "reservation_type"
        case reservationLabel = "reservation_label"
        case missionCode = "mission_code"
        case missionName = "mission_name"
        case missionLabel = "mission_label"
        case departureAirport = "departure_airport"
        case destinationAirport = "destination_airport"
        case airportChain = "airport_chain"
        case withNames = "with_names"
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        startsAt = try container.decodeIfPresent(String.self, forKey: .startsAt) ?? ""
        endsAt = try container.decodeIfPresent(String.self, forKey: .endsAt) ?? ""
        timeZone = try container.decodeIfPresent(String.self, forKey: .timeZone) ?? "America/Los_Angeles"
        dateLabel = try container.decodeIfPresent(String.self, forKey: .dateLabel) ?? ""
        timeLabel = try container.decodeIfPresent(String.self, forKey: .timeLabel) ?? ""
        whenLabel = try container.decodeIfPresent(String.self, forKey: .whenLabel) ?? ""
        aircraftRegistration = try container.decodeIfPresent(String.self, forKey: .aircraftRegistration) ?? ""
        reservationType = try container.decodeIfPresent(String.self, forKey: .reservationType) ?? ""
        reservationLabel = try container.decodeIfPresent(String.self, forKey: .reservationLabel) ?? ""
        missionCode = try container.decodeIfPresent(String.self, forKey: .missionCode) ?? ""
        missionName = try container.decodeIfPresent(String.self, forKey: .missionName) ?? ""
        missionLabel = try container.decodeIfPresent(String.self, forKey: .missionLabel) ?? ""
        departureAirport = try container.decodeIfPresent(String.self, forKey: .departureAirport) ?? ""
        destinationAirport = try container.decodeIfPresent(String.self, forKey: .destinationAirport) ?? ""
        airportChain = try container.decodeIfPresent([String].self, forKey: .airportChain) ?? []
        route = try container.decodeIfPresent(String.self, forKey: .route) ?? airportChain.joined(separator: " → ")
        role = try container.decodeIfPresent(String.self, forKey: .role) ?? ""
        crew = try container.decodeIfPresent([TrainingCrewDTO].self, forKey: .crew) ?? []
        withNames = try container.decodeIfPresent([String].self, forKey: .withNames) ?? []
        id = try container.decodeIfPresent(String.self, forKey: .id) ?? "\(startsAt)-\(aircraftRegistration)-\(missionCode)"
    }
}

struct TrainingTheoryDTO: Decodable, Hashable {
    var enrolled: Bool
    var programTitle: String
    var cohortName: String
    var completedLessons: Int
    var totalLessons: Int
    var percent: Int
    var honestyNote: String

    enum CodingKeys: String, CodingKey {
        case enrolled, percent
        case programTitle = "program_title"
        case cohortName = "cohort_name"
        case completedLessons = "completed_lessons"
        case totalLessons = "total_lessons"
        case honestyNote = "honesty_note"
    }
}

struct TrainingActionDTO: Decodable, Hashable, Identifiable {
    var id: String
    var source: String
    var title: String
    var subtitle: String
    var status: String
    var dueAt: String

    enum CodingKeys: String, CodingKey {
        case id, source, title, subtitle, status
        case dueAt = "due_at"
    }
}

struct TrainingDeadlineDTO: Decodable, Hashable, Identifiable {
    var id: String
    var title: String
    var cohortName: String
    var dueAt: String
    var dueLabel: String
    var daysLeft: Int?

    enum CodingKeys: String, CodingKey {
        case id, title
        case cohortName = "cohort_name"
        case dueAt = "due_at"
        case dueLabel = "due_label"
        case daysLeft = "days_left"
    }
}

struct AcknowledgementEnvelope: Decodable {
    var ok: Bool
    var acknowledgement: AckDTO
}

enum AppTab: Hashable {
    case messages
    case community
    case training
    case trainingVideos
    case me
}

struct CommunityFeedDTO: Decodable {
    var ok: Bool
    var posts: [CommunityPostDTO]
    var nextCursor: Int?
    var postingEnabled: Bool

    enum CodingKeys: String, CodingKey {
        case ok, posts
        case nextCursor = "next_cursor"
        case postingEnabled = "posting_enabled"
    }
}

struct CommunityPostEnvelope: Decodable {
    var ok: Bool
    var post: CommunityPostDTO
    var postingEnabled: Bool?

    enum CodingKeys: String, CodingKey {
        case ok, post
        case postingEnabled = "posting_enabled"
    }
}

struct CommunityPostDTO: Decodable, Hashable, Identifiable {
    var postUUID: String
    var caption: String
    var body: String
    var createdAtUTC: String
    var author: PublicUser
    var canDelete: Bool
    var likeCount: Int
    var liked: Bool
    var commentCount: Int
    var media: [CommunityMediaDTO]

    var id: String { postUUID }

    enum CodingKeys: String, CodingKey {
        case caption, body, author, liked, media
        case postUUID = "post_uuid"
        case createdAtUTC = "created_at_utc"
        case canDelete = "can_delete"
        case likeCount = "like_count"
        case commentCount = "comment_count"
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        postUUID = try container.decode(String.self, forKey: .postUUID)
        caption = try container.decodeIfPresent(String.self, forKey: .caption) ?? ""
        body = try container.decodeIfPresent(String.self, forKey: .body) ?? ""
        createdAtUTC = try container.decode(String.self, forKey: .createdAtUTC)
        author = try container.decode(PublicUser.self, forKey: .author)
        canDelete = try container.decodeIfPresent(Bool.self, forKey: .canDelete) ?? false
        likeCount = try container.decodeIfPresent(Int.self, forKey: .likeCount) ?? 0
        liked = try container.decodeIfPresent(Bool.self, forKey: .liked) ?? false
        commentCount = try container.decodeIfPresent(Int.self, forKey: .commentCount) ?? 0
        media = try container.decodeIfPresent([CommunityMediaDTO].self, forKey: .media) ?? []
    }
}

struct CommunityMediaDTO: Decodable, Hashable, Identifiable {
    var mediaUUID: String
    var kind: String
    var mimeType: String
    var byteSize: Int
    var durationMs: Int
    var status: String?
    var getURL: String?
    var posterURL: String?
    var expiresIn: Int?

    var id: String { mediaUUID }

    enum CodingKeys: String, CodingKey {
        case kind, status
        case mediaUUID = "media_uuid"
        case mimeType = "mime_type"
        case byteSize = "byte_size"
        case durationMs = "duration_ms"
        case getURL = "get_url"
        case posterURL = "poster_url"
        case expiresIn = "expires_in"
    }
}

struct CommunityPresignEnvelope: Decodable {
    var ok: Bool
    var mediaUUID: String
    var putURL: String
    var posterPutURL: String?
    var headers: [String: String]
    var posterHeaders: [String: String]
    var expiresIn: Int

    enum CodingKeys: String, CodingKey {
        case ok, headers
        case mediaUUID = "media_uuid"
        case putURL = "put_url"
        case posterPutURL = "poster_put_url"
        case posterHeaders = "poster_headers"
        case expiresIn = "expires_in"
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        ok = try container.decodeIfPresent(Bool.self, forKey: .ok) ?? true
        mediaUUID = try container.decode(String.self, forKey: .mediaUUID)
        putURL = try container.decode(String.self, forKey: .putURL)
        posterPutURL = try container.decodeIfPresent(String.self, forKey: .posterPutURL)
        headers = try container.decodeIfPresent([String: String].self, forKey: .headers) ?? [:]
        posterHeaders = try container.decodeIfPresent([String: String].self, forKey: .posterHeaders) ?? [:]
        expiresIn = try container.decodeIfPresent(Int.self, forKey: .expiresIn) ?? 900
    }
}

struct CommunityCommentDTO: Decodable, Hashable, Identifiable {
    var commentUUID: String
    var body: String
    var createdAtUTC: String
    var author: PublicUser
    var canDelete: Bool

    var id: String { commentUUID }

    enum CodingKeys: String, CodingKey {
        case body, author
        case commentUUID = "comment_uuid"
        case createdAtUTC = "created_at_utc"
        case canDelete = "can_delete"
    }
}

struct CommunityCommentsEnvelope: Decodable {
    var ok: Bool
    var comments: [CommunityCommentDTO]
}

struct CommunityCommentEnvelope: Decodable {
    var ok: Bool
    var comment: CommunityCommentDTO
}

struct CommunityDeleteEnvelope: Decodable {
    var ok: Bool
    var deleted: Bool
    var postUUID: String

    enum CodingKeys: String, CodingKey {
        case ok, deleted
        case postUUID = "post_uuid"
    }
}

struct CommunityReportEnvelope: Decodable {
    var ok: Bool
    var reportUUID: String
    var alreadyReported: Bool

    enum CodingKeys: String, CodingKey {
        case ok
        case reportUUID = "report_uuid"
        case alreadyReported = "already_reported"
    }
}

struct TrainingVideoFeedDTO: Decodable {
    var ok: Bool
    var videos: [TrainingVideoDTO]
    var nextCursor: Int?

    enum CodingKeys: String, CodingKey {
        case ok, videos
        case nextCursor = "next_cursor"
    }
}

struct TrainingVideoEnvelope: Decodable {
    var ok: Bool
    var video: TrainingVideoDTO
}

struct TrainingVideoDTO: Codable, Hashable, Identifiable {
    var videoUUID: String
    var title: String
    var description: String
    var durationMs: Int
    var durationSeconds: Int
    var byteSize: Int
    var mimeType: String
    var posterURL: String
    var viewCount: Int
    var likeCount: Int
    var liked: Bool
    var commentCount: Int
    var downloadable: Bool
    var availableUntil: String
    var createdAtUTC: String

    var id: String { videoUUID }

    enum CodingKeys: String, CodingKey {
        case title, description, liked, downloadable
        case videoUUID = "video_uuid"
        case durationMs = "duration_ms"
        case durationSeconds = "duration_seconds"
        case byteSize = "byte_size"
        case mimeType = "mime_type"
        case posterURL = "poster_url"
        case viewCount = "view_count"
        case likeCount = "like_count"
        case commentCount = "comment_count"
        case availableUntil = "available_until"
        case createdAtUTC = "created_at_utc"
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        videoUUID = try container.decode(String.self, forKey: .videoUUID)
        title = try container.decodeIfPresent(String.self, forKey: .title) ?? ""
        description = try container.decodeIfPresent(String.self, forKey: .description) ?? ""
        durationMs = try container.decodeIfPresent(Int.self, forKey: .durationMs) ?? 0
        durationSeconds = try container.decodeIfPresent(Int.self, forKey: .durationSeconds) ?? Int((Double(durationMs) / 1000.0).rounded())
        byteSize = try container.decodeIfPresent(Int.self, forKey: .byteSize) ?? 0
        mimeType = try container.decodeIfPresent(String.self, forKey: .mimeType) ?? "video/mp4"
        posterURL = try container.decodeIfPresent(String.self, forKey: .posterURL) ?? ""
        viewCount = try container.decodeIfPresent(Int.self, forKey: .viewCount) ?? 0
        likeCount = try container.decodeIfPresent(Int.self, forKey: .likeCount) ?? 0
        liked = try container.decodeIfPresent(Bool.self, forKey: .liked) ?? false
        commentCount = try container.decodeIfPresent(Int.self, forKey: .commentCount) ?? 0
        downloadable = try container.decodeIfPresent(Bool.self, forKey: .downloadable) ?? false
        availableUntil = try container.decodeIfPresent(String.self, forKey: .availableUntil) ?? ""
        createdAtUTC = try container.decodeIfPresent(String.self, forKey: .createdAtUTC) ?? ""
    }
}

struct TrainingVideoPlaybackDTO: Decodable {
    var ok: Bool?
    var url: String
    var streamURL: String?
    var downloadURL: String?
    var mimeType: String?
    var expiresIn: Int?
    var availableUntil: String?
    var downloadable: Bool?
    var video: TrainingVideoDTO?

    enum CodingKeys: String, CodingKey {
        case ok, url, video, downloadable
        case streamURL = "stream_url"
        case downloadURL = "download_url"
        case mimeType = "mime_type"
        case expiresIn = "expires_in"
        case availableUntil = "available_until"
    }
}

struct TrainingVideoCommentDTO: Decodable, Hashable, Identifiable {
    var commentUUID: String
    var body: String
    var createdAtUTC: String
    var author: PublicUser

    var id: String { commentUUID }

    enum CodingKeys: String, CodingKey {
        case body, author
        case commentUUID = "comment_uuid"
        case createdAtUTC = "created_at_utc"
    }
}

struct TrainingVideoCommentsEnvelope: Decodable {
    var ok: Bool
    var comments: [TrainingVideoCommentDTO]
}

struct TrainingVideoCommentEnvelope: Decodable {
    var ok: Bool
    var comment: TrainingVideoCommentDTO
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
