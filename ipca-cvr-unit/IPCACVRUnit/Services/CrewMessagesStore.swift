import Combine
import Foundation

struct CVRCrewMessage: Codable, Identifiable, Equatable {
    var messageUUID: String
    var operationalSessionUUID: String
    var workflowFlightRecordUUID: String?
    var body: String
    var sentAtUTC: Date
    var localAcknowledgedAtUTC: Date?

    var id: String { messageUUID }

    enum CodingKeys: String, CodingKey {
        case messageUUID = "message_uuid"
        case operationalSessionUUID = "operational_session_uuid"
        case workflowFlightRecordUUID = "workflow_flight_record_uuid"
        case body
        case sentAtUTC = "sent_at_utc"
        case localAcknowledgedAtUTC = "local_acknowledged_at_utc"
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        messageUUID = try container.decode(String.self, forKey: .messageUUID)
        operationalSessionUUID = try container.decode(String.self, forKey: .operationalSessionUUID)
        workflowFlightRecordUUID = try container.decodeIfPresent(
            String.self,
            forKey: .workflowFlightRecordUUID
        )
        body = try container.decode(String.self, forKey: .body)
        sentAtUTC = try Self.decodeDate(container, forKey: .sentAtUTC)
        localAcknowledgedAtUTC = try Self.decodeOptionalDate(
            container,
            forKey: .localAcknowledgedAtUTC
        )
    }

    func encode(to encoder: Encoder) throws {
        var container = encoder.container(keyedBy: CodingKeys.self)
        try container.encode(messageUUID, forKey: .messageUUID)
        try container.encode(operationalSessionUUID, forKey: .operationalSessionUUID)
        try container.encodeIfPresent(workflowFlightRecordUUID, forKey: .workflowFlightRecordUUID)
        try container.encode(body, forKey: .body)
        try container.encode(Self.iso8601String(sentAtUTC), forKey: .sentAtUTC)
        if let localAcknowledgedAtUTC {
            try container.encode(
                Self.iso8601String(localAcknowledgedAtUTC),
                forKey: .localAcknowledgedAtUTC
            )
        }
    }

    private static func decodeDate(
        _ container: KeyedDecodingContainer<CodingKeys>,
        forKey key: CodingKeys
    ) throws -> Date {
        let value = try container.decode(String.self, forKey: key)
        if let date = ISO8601DateFormatter().date(from: value) {
            return date
        }
        let sqlFormatter = DateFormatter()
        sqlFormatter.locale = Locale(identifier: "en_US_POSIX")
        sqlFormatter.calendar = Calendar(identifier: .gregorian)
        sqlFormatter.timeZone = TimeZone(secondsFromGMT: 0)
        sqlFormatter.dateFormat = "yyyy-MM-dd HH:mm:ss.SSS"
        if let date = sqlFormatter.date(from: value) {
            return date
        }
        sqlFormatter.dateFormat = "yyyy-MM-dd HH:mm:ss"
        if let date = sqlFormatter.date(from: value) {
            return date
        }
        throw DecodingError.dataCorruptedError(
            forKey: key,
            in: container,
            debugDescription: "Expected a UTC ISO-8601 or SQL timestamp."
        )
    }

    private static func decodeOptionalDate(
        _ container: KeyedDecodingContainer<CodingKeys>,
        forKey key: CodingKeys
    ) throws -> Date? {
        guard container.contains(key), try !container.decodeNil(forKey: key) else { return nil }
        return try decodeDate(container, forKey: key)
    }

    private static func iso8601String(_ date: Date) -> String {
        let formatter = ISO8601DateFormatter()
        formatter.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        return formatter.string(from: date)
    }
}

struct CVRCrewMessagesResponse: Codable {
    var ok: Bool
    var activeSession: Bool?
    var operationalSessionUUID: String?
    var messages: [CVRCrewMessage]
    var error: String?

    enum CodingKeys: String, CodingKey {
        case ok
        case activeSession = "active_session"
        case operationalSessionUUID = "operational_session_uuid"
        case messages
        case error
    }
}

struct CVRCrewMessageAcknowledgementRequest: Codable, Equatable {
    var messageUUID: String
    var operationalSessionUUID: String
    var acknowledgementUUID: String
    var deviceEventAtUTC: Date

    enum CodingKeys: String, CodingKey {
        case messageUUID = "message_uuid"
        case operationalSessionUUID = "operational_session_uuid"
        case acknowledgementUUID = "acknowledgement_uuid"
        case deviceEventAtUTC = "device_event_at_utc"
    }
}

struct CVRCrewMessageAcknowledgementResponse: Codable {
    var ok: Bool
    var acknowledged: Bool?
    var messageUUID: String?
    var acknowledgementUUID: String?
    var error: String?

    enum CodingKeys: String, CodingKey {
        case ok
        case acknowledged
        case messageUUID = "message_uuid"
        case acknowledgementUUID = "acknowledgement_uuid"
        case error
    }
}

@MainActor
final class CrewMessagesStore: ObservableObject {
    @Published private(set) var messages: [CVRCrewMessage] = []
    @Published private(set) var serverActiveOperationalSessionUUID: String?
    @Published private(set) var serverSessionResolutionReceived = false

    private struct PersistedState: Codable {
        var messages: [CVRCrewMessage]
        var pendingAcknowledgements: [CVRCrewMessageAcknowledgementRequest]
    }

    private weak var settings: SettingsStore?
    private weak var network: NetworkMonitor?
    private weak var workflow: CVRWorkflowStore?
    private var pendingAcknowledgements: [CVRCrewMessageAcknowledgementRequest] = []
    private var cancellables: Set<AnyCancellable> = []
    private var pollingTask: Task<Void, Never>?
    private var requestTask: Task<Void, Never>?
    private var isForeground = false

    init() {
        load()
    }

    deinit {
        pollingTask?.cancel()
        requestTask?.cancel()
    }

    var oldestUnacknowledgedMessage: CVRCrewMessage? {
        guard let sessionUUID = messageSessionUUID else { return nil }
        return messages
            .filter {
                $0.operationalSessionUUID.caseInsensitiveCompare(sessionUUID) == .orderedSame
                    && $0.localAcknowledgedAtUTC == nil
            }
            .min(by: { $0.sentAtUTC < $1.sentAtUTC })
    }

    func bind(settings: SettingsStore, network: NetworkMonitor, workflow: CVRWorkflowStore) {
        guard self.settings == nil else { return }
        self.settings = settings
        self.network = network
        self.workflow = workflow

        network.$isSatisfied
            .removeDuplicates()
            .receive(on: RunLoop.main)
            .sink { [weak self] _ in
                self?.serverActiveOperationalSessionUUID = nil
                self?.serverSessionResolutionReceived = false
                self?.refreshPolling(immediate: true)
            }
            .store(in: &cancellables)

        workflow.$state
            .map { state in
                [
                    state.activeOperationalSession?.id ?? "",
                    state.activeOperationalSession?.state.rawValue ?? "",
                    state.activeDispatch?.operationalSessionUUID ?? "",
                    state.activeFlightRecord?.id ?? ""
                ].joined(separator: ":")
            }
            .removeDuplicates()
            .receive(on: RunLoop.main)
            .sink { [weak self] _ in
                self?.refreshPolling(immediate: true)
            }
            .store(in: &cancellables)
    }

    func appBecameActive() {
        isForeground = true
        refreshPolling(immediate: true)
    }

    func appEnteredBackground() {
        isForeground = false
        pollingTask?.cancel()
        pollingTask = nil
    }

    func acknowledge(_ message: CVRCrewMessage) {
        guard message.localAcknowledgedAtUTC == nil,
              message.operationalSessionUUID.caseInsensitiveCompare(messageSessionUUID ?? "") == .orderedSame,
              let index = messages.firstIndex(where: { $0.messageUUID == message.messageUUID }) else {
            return
        }

        let acknowledgedAt = Date()
        messages[index].localAcknowledgedAtUTC = acknowledgedAt
        if !pendingAcknowledgements.contains(where: { $0.messageUUID == message.messageUUID }) {
            pendingAcknowledgements.append(
                CVRCrewMessageAcknowledgementRequest(
                    messageUUID: message.messageUUID,
                    operationalSessionUUID: message.operationalSessionUUID,
                    acknowledgementUUID: UUID().uuidString.lowercased(),
                    deviceEventAtUTC: acknowledgedAt
                )
            )
        }
        save()
        requestNow()
    }

    private var localOperationalSessionUUID: String? {
        guard let state = workflow?.state else { return nil }
        if let session = state.activeOperationalSession {
            switch session.state {
            case .intended, .evidenceCapturing, .endingStateSecured:
                return session.id.lowercased()
            case .evidenceClosed, .finalized, .cancelled:
                return nil
            }
        }
        guard let flight = state.activeFlightRecord,
              let dispatch = state.activeDispatch,
              flight.dispatchID == dispatch.id,
              let candidate = dispatch.operationalSessionUUID?
                .trimmingCharacters(in: .whitespacesAndNewlines),
              UUID(uuidString: candidate) != nil else {
            return nil
        }
        return candidate.lowercased()
    }

    private var messageSessionUUID: String? {
        serverSessionResolutionReceived
            ? serverActiveOperationalSessionUUID
            : localOperationalSessionUUID
    }

    private var canReachMessageService: Bool {
        isForeground
            && network?.isSatisfied == true
            && settings?.normalizedServerURL != nil
            && settings?.deviceCredential?.isEmpty == false
            && settings?.isSimulationModeEnabled == false
    }

    private var canPollActiveSession: Bool {
        canReachMessageService
    }

    private func refreshPolling(immediate: Bool) {
        guard canReachMessageService else {
            pollingTask?.cancel()
            pollingTask = nil
            return
        }

        if immediate {
            requestNow()
        }
        guard canPollActiveSession else {
            pollingTask?.cancel()
            pollingTask = nil
            return
        }
        guard pollingTask == nil else { return }
        pollingTask = Task { [weak self] in
            while !Task.isCancelled {
                guard let self else { return }
                let interval: Duration = self.messageSessionUUID == nil ? .seconds(15) : .seconds(5)
                try? await Task.sleep(for: interval)
                guard !Task.isCancelled else { return }
                guard self.canPollActiveSession else {
                    self.pollingTask = nil
                    return
                }
                self.requestNow()
            }
        }
    }

    private func requestNow() {
        guard canReachMessageService, requestTask == nil else { return }
        requestTask = Task { [weak self] in
            guard let self else { return }
            await self.synchronize()
            self.requestTask = nil
        }
    }

    private func synchronize() async {
        guard canReachMessageService,
              let serverURL = settings?.normalizedServerURL,
              let credential = settings?.deviceCredential else {
            return
        }

        let client = APIClient(serverURL: serverURL)
        for acknowledgement in pendingAcknowledgements {
            do {
                let response = try await client.acknowledgeCrewMessage(
                    acknowledgement,
                    credential: credential
                )
                guard response.ok, response.acknowledged != false else { continue }
                pendingAcknowledgements.removeAll {
                    $0.acknowledgementUUID == acknowledgement.acknowledgementUUID
                }
                save()
            } catch {
                // Messaging is advisory to recording. Keep the durable item for the next poll.
            }
        }

        do {
            let response = try await client.pendingCrewMessages(
                operationalSessionUUID: "",
                credential: credential
            )
            guard response.ok else { return }
            serverSessionResolutionReceived = true
            let resolved = response.operationalSessionUUID?
                .trimmingCharacters(in: .whitespacesAndNewlines)
            serverActiveOperationalSessionUUID = (
                response.activeSession != false
                    && resolved.map({ UUID(uuidString: $0) != nil }) == true
            ) ? resolved?.lowercased() : nil
            merge(response.messages, sessionUUID: serverActiveOperationalSessionUUID)
        } catch {
            // A failed message poll must never affect recording or workflow state.
        }
    }

    private func merge(_ fetched: [CVRCrewMessage], sessionUUID: String?) {
        var changed = false
        for var message in fetched {
            guard message.operationalSessionUUID.caseInsensitiveCompare(sessionUUID ?? "") == .orderedSame else {
                continue
            }
            if let index = messages.firstIndex(where: { $0.messageUUID == message.messageUUID }) {
                message.localAcknowledgedAtUTC = messages[index].localAcknowledgedAtUTC
                if messages[index] != message {
                    messages[index] = message
                    changed = true
                }
            } else {
                messages.append(message)
                changed = true
            }
        }
        if changed {
            messages.sort { $0.sentAtUTC < $1.sentAtUTC }
            save()
        }
    }

    private func load() {
        do {
            let url = try storeURL()
            guard FileManager.default.fileExists(atPath: url.path) else { return }
            let decoder = JSONDecoder()
            decoder.dateDecodingStrategy = .iso8601
            let state = try decoder.decode(PersistedState.self, from: Data(contentsOf: url))
            messages = state.messages
            pendingAcknowledgements = state.pendingAcknowledgements
        } catch {
            print("CrewMessagesStore load failed: \(error)")
        }
    }

    private func save() {
        do {
            let url = try storeURL()
            let encoder = JSONEncoder()
            encoder.outputFormatting = [.prettyPrinted, .sortedKeys]
            encoder.dateEncodingStrategy = .iso8601
            let data = try encoder.encode(
                PersistedState(
                    messages: messages,
                    pendingAcknowledgements: pendingAcknowledgements
                )
            )
            try data.write(to: url, options: .atomic)
        } catch {
            print("CrewMessagesStore save failed: \(error)")
        }
    }

    private func storeURL() throws -> URL {
        let applicationSupport = try FileManager.default.url(
            for: .applicationSupportDirectory,
            in: .userDomainMask,
            appropriateFor: nil,
            create: true
        )
        let directory = applicationSupport.appending(path: "IPCACVRUnit", directoryHint: .isDirectory)
        try FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
        return directory.appending(path: "crew-messages.json")
    }
}
