import Foundation

struct SafetyReportInput: Codable, Equatable {
    var category: String
    var title: String
    var description: String
    var occurredAtUTC: String
    var location: String
    var aircraftRegistration: String
    var immediateAction: String

    var apiPayload: [String: Any] {
        [
            "category": category,
            "title": title,
            "description": description,
            "occurred_at_utc": occurredAtUTC,
            "location": location,
            "aircraft_registration": aircraftRegistration,
            "immediate_action": immediateAction
        ]
    }
}

struct SafetyDraftAttachment: Codable, Equatable, Identifiable {
    let attachmentUUID: String
    let filename: String
    let mimeType: String
    let byteSize: Int
    let localFilename: String
    var uploaded: Bool

    var id: String { attachmentUUID }
}

enum SafetyDraftAttachmentFileStore {
    static let maximumBytes = 25 * 1_024 * 1_024

    static func importFile(at sourceURL: URL, filename: String, mimeType: String) throws -> SafetyDraftAttachment {
        let accessing = sourceURL.startAccessingSecurityScopedResource()
        defer {
            if accessing {
                sourceURL.stopAccessingSecurityScopedResource()
            }
        }
        let values = try sourceURL.resourceValues(forKeys: [.fileSizeKey])
        let byteSize = values.fileSize ?? 0
        guard byteSize > 0, byteSize <= maximumBytes else {
            throw SafetyAttachmentError.invalidSize
        }
        let uuid = UUID().uuidString.lowercased()
        let localFilename = uuid
        let destination = try directory().appendingPathComponent(localFilename, isDirectory: false)
        try FileManager.default.copyItem(at: sourceURL, to: destination)
        try? FileManager.default.setAttributes(
            [.protectionKey: FileProtectionType.completeUntilFirstUserAuthentication],
            ofItemAtPath: destination.path
        )
        return SafetyDraftAttachment(
            attachmentUUID: uuid,
            filename: filename,
            mimeType: mimeType,
            byteSize: byteSize,
            localFilename: localFilename,
            uploaded: false
        )
    }

    static func data(for attachment: SafetyDraftAttachment) throws -> Data {
        try Data(contentsOf: directory().appendingPathComponent(attachment.localFilename))
    }

    static func remove(_ attachment: SafetyDraftAttachment) {
        guard let directory = try? directory() else { return }
        try? FileManager.default.removeItem(at: directory.appendingPathComponent(attachment.localFilename))
    }

    private static func directory() throws -> URL {
        let base = try FileManager.default.url(
            for: .applicationSupportDirectory,
            in: .userDomainMask,
            appropriateFor: nil,
            create: true
        )
        let directory = base.appendingPathComponent("SafetyAttachments", isDirectory: true)
        try FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
        return directory
    }
}

enum SafetyAttachmentError: LocalizedError {
    case invalidSize
    case unsupportedType

    var errorDescription: String? {
        switch self {
        case .invalidSize:
            return "Attachments must be between 1 byte and 25 MB."
        case .unsupportedType:
            return "That attachment type is not supported."
        }
    }
}

struct SafetySubmissionDraft: Codable, Equatable {
    var input: SafetyReportInput
    var idempotencyKey: String
    var remoteReportUUID: String?
    var attachments: [SafetyDraftAttachment]
    var remoteInput: SafetyReportInput? = nil
}

enum IdentifiedSafetyDraftStore {
    private static func key(for userUUID: String) -> String {
        "ipca.safety.identifiedDraft.\(userUUID)"
    }

    static func load(userUUID: String) -> SafetyReportInput? {
        loadSubmission(userUUID: userUUID)?.input
    }

    static func loadSubmission(userUUID: String) -> SafetySubmissionDraft? {
        guard !userUUID.isEmpty,
              let data = UserDefaults.standard.data(forKey: key(for: userUUID)) else { return nil }
        if let state = try? JSONDecoder().decode(SafetySubmissionDraft.self, from: data) {
            return state
        }
        guard let legacy = try? JSONDecoder().decode(SafetyReportInput.self, from: data) else { return nil }
        return SafetySubmissionDraft(
            input: legacy,
            idempotencyKey: UUID().uuidString.lowercased(),
            remoteReportUUID: nil,
            attachments: []
        )
    }

    static func save(_ input: SafetyReportInput, userUUID: String) {
        guard !userUUID.isEmpty else { return }
        var draft = loadSubmission(userUUID: userUUID) ?? SafetySubmissionDraft(
            input: input,
            idempotencyKey: UUID().uuidString.lowercased(),
            remoteReportUUID: nil,
            attachments: []
        )
        if draft.input != input, draft.remoteReportUUID == nil {
            draft.idempotencyKey = UUID().uuidString.lowercased()
        }
        draft.input = input
        save(draft, userUUID: userUUID)
    }

    static func save(_ draft: SafetySubmissionDraft, userUUID: String) {
        guard !userUUID.isEmpty, let data = try? JSONEncoder().encode(draft) else { return }
        UserDefaults.standard.set(data, forKey: key(for: userUUID))
    }

    static func clear(userUUID: String) {
        guard !userUUID.isEmpty else { return }
        UserDefaults.standard.removeObject(forKey: key(for: userUUID))
    }
}

enum AnonymousSafetyDraftStore {
    private static let key = "ipca.safety.anonymousDraft"

    static func load() -> SafetySubmissionDraft? {
        guard let data = UserDefaults.standard.data(forKey: key) else { return nil }
        return try? JSONDecoder().decode(SafetySubmissionDraft.self, from: data)
    }

    static func save(_ draft: SafetySubmissionDraft) {
        guard let data = try? JSONEncoder().encode(draft) else { return }
        UserDefaults.standard.set(data, forKey: key)
    }

    static func clear() {
        UserDefaults.standard.removeObject(forKey: key)
    }
}

struct SafetyReportDTO: Decodable, Hashable, Identifiable {
    var reportUUID: String
    var reference: String
    var title: String
    var category: String
    var description: String
    var status: String
    var occurredAtUTC: String
    var createdAtUTC: String
    var updatedAtUTC: String
    var location: String
    var aircraftRegistration: String
    var immediateAction: String
    var timeline: [SafetyTimelineEventDTO]

    var id: String { reportUUID }

    enum CodingKeys: String, CodingKey {
        case reference, title, category, description, status, location, timeline, updates
        case reportUUID = "report_uuid"
        case occurredAtUTC = "occurred_at_utc"
        case createdAtUTC = "created_at_utc"
        case updatedAtUTC = "updated_at_utc"
        case aircraftRegistration = "aircraft_registration"
        case immediateAction = "immediate_action"
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        reportUUID = try container.decode(String.self, forKey: .reportUUID)
        reference = try container.decodeIfPresent(String.self, forKey: .reference) ?? ""
        title = try container.decodeIfPresent(String.self, forKey: .title) ?? ""
        category = try container.decodeIfPresent(String.self, forKey: .category) ?? ""
        description = try container.decodeIfPresent(String.self, forKey: .description) ?? ""
        status = try container.decodeIfPresent(String.self, forKey: .status) ?? "draft"
        occurredAtUTC = try container.decodeIfPresent(String.self, forKey: .occurredAtUTC) ?? ""
        createdAtUTC = try container.decodeIfPresent(String.self, forKey: .createdAtUTC) ?? ""
        updatedAtUTC = try container.decodeIfPresent(String.self, forKey: .updatedAtUTC) ?? createdAtUTC
        location = try container.decodeIfPresent(String.self, forKey: .location) ?? ""
        aircraftRegistration = try container.decodeIfPresent(String.self, forKey: .aircraftRegistration) ?? ""
        immediateAction = try container.decodeIfPresent(String.self, forKey: .immediateAction) ?? ""
        timeline = try container.decodeIfPresent([SafetyTimelineEventDTO].self, forKey: .timeline)
            ?? container.decodeIfPresent([SafetyTimelineEventDTO].self, forKey: .updates)
            ?? []
    }
}

struct SafetyTimelineEventDTO: Decodable, Hashable, Identifiable {
    var eventUUID: String
    var eventType: String
    var title: String
    var body: String
    var createdAtUTC: String

    var id: String { eventUUID.isEmpty ? "\(eventType)-\(createdAtUTC)-\(title)" : eventUUID }

    enum CodingKeys: String, CodingKey {
        case title, body, direction
        case eventUUID = "event_uuid"
        case updateUUID = "update_uuid"
        case eventType = "event_type"
        case createdAtUTC = "created_at_utc"
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        eventUUID = try container.decodeIfPresent(String.self, forKey: .eventUUID)
            ?? container.decodeIfPresent(String.self, forKey: .updateUUID)
            ?? ""
        eventType = try container.decodeIfPresent(String.self, forKey: .eventType)
            ?? container.decodeIfPresent(String.self, forKey: .direction)
            ?? "update"
        let direction = try container.decodeIfPresent(String.self, forKey: .direction)
        title = try container.decodeIfPresent(String.self, forKey: .title)
            ?? (direction == "from_reporter" ? "You" : "Safety Team")
        body = try container.decodeIfPresent(String.self, forKey: .body) ?? ""
        createdAtUTC = try container.decodeIfPresent(String.self, forKey: .createdAtUTC) ?? ""
    }
}

struct SafetyMailboxMessageDTO: Decodable, Hashable, Identifiable {
    var messageUUID: String
    var body: String
    var senderLabel: String
    var createdAtUTC: String

    var id: String { messageUUID }

    enum CodingKeys: String, CodingKey {
        case body
        case messageUUID = "message_uuid"
        case senderLabel = "sender_label"
        case createdAtUTC = "created_at_utc"
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        messageUUID = try container.decodeIfPresent(String.self, forKey: .messageUUID) ?? UUID().uuidString.lowercased()
        body = try container.decodeIfPresent(String.self, forKey: .body) ?? ""
        senderLabel = try container.decodeIfPresent(String.self, forKey: .senderLabel) ?? "Safety Team"
        createdAtUTC = try container.decodeIfPresent(String.self, forKey: .createdAtUTC) ?? ""
    }
}

struct SafetyReportsEnvelope: Decodable {
    var ok: Bool
    var reports: [SafetyReportDTO]
}

struct SafetyReportEnvelope: Decodable {
    var ok: Bool
    var report: SafetyReportDTO
}

struct SafetyMailboxEnvelope: Decodable {
    var ok: Bool
    var messages: [SafetyMailboxMessageDTO]
}

struct SafetyUpdateEnvelope: Decodable {
    var ok: Bool
    var update: SafetyTimelineEventDTO
}

struct SafetyAttachmentDTO: Decodable, Hashable, Identifiable {
    var attachmentUUID: String
    var filename: String
    var mimeType: String
    var byteSize: Int
    var status: String

    var id: String { attachmentUUID }

    enum CodingKeys: String, CodingKey {
        case filename, status
        case attachmentUUID = "attachment_uuid"
        case mimeType = "mime_type"
        case byteSize = "byte_size"
    }
}

struct SafetyAttachmentPresignEnvelope: Decodable {
    var ok: Bool
    var attachment: SafetyAttachmentPresignDTO
}

struct SafetyAttachmentPresignDTO: Decodable {
    var attachmentUUID: String
    var putURL: String
    var headers: [String: String]
    var expiresIn: Int
    var status: String

    enum CodingKeys: String, CodingKey {
        case headers, status
        case attachmentUUID = "attachment_uuid"
        case putURL = "put_url"
        case expiresIn = "expires_in"
    }
}

struct SafetyAttachmentEnvelope: Decodable {
    var ok: Bool
    var attachment: SafetyAttachmentDTO
}

struct AnonymousSafetyReceipt: Decodable, Hashable {
    var receiptID: String
    var receiptSecret: String
    var reference: String
    var status: String

    enum CodingKeys: String, CodingKey {
        case reference, status
        case receiptID = "receipt_id"
        case receiptSecret = "receipt_secret"
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        receiptID = try container.decode(String.self, forKey: .receiptID)
        receiptSecret = try container.decode(String.self, forKey: .receiptSecret)
        reference = try container.decodeIfPresent(String.self, forKey: .reference) ?? ""
        status = try container.decodeIfPresent(String.self, forKey: .status) ?? "submitted"
    }
}

struct AnonymousSafetyStatus: Decodable, Hashable {
    var ok: Bool
    var receiptID: String
    var reference: String
    var status: String
    var updatedAtUTC: String

    enum CodingKeys: String, CodingKey {
        case ok, reference, status
        case receiptID = "receipt_id"
        case updatedAtUTC = "updated_at_utc"
    }
}
