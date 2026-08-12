import Foundation

struct APIRecording: Codable {
    var id: Int
    var recordingID: String
    var uploadStatus: String
    var transcriptionStatus: String
    var progress: Int
    var reconstructionStatus: String?
    var reconstructionProgress: Int?
    var reconstructionStage: String?
    var error: String

    enum CodingKeys: String, CodingKey {
        case id
        case recordingID = "recording_id"
        case uploadStatus = "upload_status"
        case transcriptionStatus = "transcription_status"
        case progress
        case reconstructionStatus = "reconstruction_status"
        case reconstructionProgress = "reconstruction_progress"
        case reconstructionStage = "reconstruction_stage"
        case error
    }
}

struct UploadResponse: Codable {
    var ok: Bool
    var recording: APIRecording?
    var error: String?
}

struct ChunkUploadResponse: Codable {
    var ok: Bool
    var error: String?
    var fileType: String?
    var chunkIndex: Int?
    var totalChunks: Int?
    var alreadyPresent: Bool?

    enum CodingKeys: String, CodingKey {
        case ok
        case error
        case fileType = "file_type"
        case chunkIndex = "chunk_index"
        case totalChunks = "total_chunks"
        case alreadyPresent = "already_present"
    }
}

struct ChunkUploadStatusResponse: Codable {
    var ok: Bool
    var error: String?
    var fileType: String?
    var receivedChunks: [Int]?
    var receivedCount: Int?
    var totalChunks: Int?
    var totalSize: Int?

    enum CodingKeys: String, CodingKey {
        case ok
        case error
        case fileType = "file_type"
        case receivedChunks = "received_chunks"
        case receivedCount = "received_count"
        case totalChunks = "total_chunks"
        case totalSize = "total_size"
    }
}

struct LiveAudioSegmentUploadResponse: Codable {
    var ok: Bool
    var segmentID: Int?
    var status: String?
    var alreadyPresent: Bool?
    var error: String?

    enum CodingKeys: String, CodingKey {
        case ok
        case segmentID = "segment_id"
        case status
        case alreadyPresent = "already_present"
        case error
    }
}

struct LiveCockpitMonitorLeaseResponse: Codable {
    var ok: Bool
    var captureRequested: Bool?
    var captureBackendEnabled: Bool?
    var reason: String?
    var broadcastUUID: String?
    var dispatchUUID: String?
    var workflowFlightRecordUUID: String?
    var operationalSessionUUID: String?
    var leaseExpiresInSeconds: Int?
    var chunkDurationSeconds: Int?
    var maxChunkBytes: Int?
    var error: String?

    enum CodingKeys: String, CodingKey {
        case ok
        case captureRequested = "capture_requested"
        case captureBackendEnabled = "capture_backend_enabled"
        case reason
        case broadcastUUID = "broadcast_uuid"
        case dispatchUUID = "dispatch_uuid"
        case workflowFlightRecordUUID = "workflow_flight_record_uuid"
        case operationalSessionUUID = "operational_session_uuid"
        case leaseExpiresInSeconds = "lease_expires_in_seconds"
        case chunkDurationSeconds = "chunk_duration_seconds"
        case maxChunkBytes = "max_chunk_bytes"
        case error
    }
}

struct LiveCockpitMonitorChunkUploadResponse: Codable {
    var ok: Bool
    var alreadyPresent: Bool?
    var chunkUUID: String?
    var error: String?

    enum CodingKeys: String, CodingKey {
        case ok
        case alreadyPresent = "already_present"
        case chunkUUID = "chunk_uuid"
        case error
    }
}

struct StatusResponse: Codable {
    var ok: Bool
    var recording: APIRecording?
    var error: String?
}

struct TranscriptResponse: Codable {
    var ok: Bool
    var recordingID: String?
    var transcriptionStatus: String?
    var language: String?
    var transcript: String?
    var error: String?

    enum CodingKeys: String, CodingKey {
        case ok
        case recordingID = "recording_id"
        case transcriptionStatus = "transcription_status"
        case language
        case transcript
        case error
    }
}

struct AircraftListResponse: Codable {
    var ok: Bool
    var aircraft: [CockpitAircraft]
    var error: String?
}

struct DeviceEnrollmentResponse: Codable {
    var ok: Bool
    var credential: String?
    var credentialUUID: String?
    var aircraftID: Int?
    var aircraftRegistration: String?
    var error: String?

    enum CodingKeys: String, CodingKey {
        case ok
        case credential
        case credentialUUID = "credential_uuid"
        case aircraftID = "aircraft_id"
        case aircraftRegistration = "aircraft_registration"
        case error
    }
}

struct AircraftFuelStateResponse: Codable {
    var quantityUSG: Double?
    var unit: String?
    var capacity: Double?
    var source: String?
    var asOfUTC: String?
    var aircraftRegistration: String?
    var upliftUUID: String?

    enum CodingKeys: String, CodingKey {
        case quantityUSG = "quantity_usg"
        case unit
        case capacity
        case source
        case asOfUTC = "as_of_utc"
        case aircraftRegistration = "aircraft_registration"
        case upliftUUID = "uplift_uuid"
    }
}

struct DeviceStatusResponse: Codable {
    var ok: Bool
    var fuelState: AircraftFuelStateResponse?
    var error: String?

    enum CodingKeys: String, CodingKey {
        case ok
        case fuelState = "fuel_state"
        case error
    }
}

struct DispatchReleaseResponse: Codable {
    var ok: Bool
    var alreadyReleased: Bool?
    var schedulerRecordID: String?
    var dispatchUUID: String?
    var flightRecordUUID: String?
    var errorCode: String?
    var retryable: Bool?
    var userActionRequired: Bool?
    var requestID: String?
    var error: String?

    enum CodingKeys: String, CodingKey {
        case ok
        case alreadyReleased = "already_released"
        case schedulerRecordID = "scheduler_record_id"
        case dispatchUUID = "dispatch_uuid"
        case flightRecordUUID = "flight_record_uuid"
        case errorCode = "error_code"
        case retryable
        case userActionRequired = "user_action_required"
        case requestID = "request_id"
        case error
    }
}

struct ScheduleDutySyncResponse: Codable {
    var ok: Bool
    var alreadyPresent: Bool?
    var schedulerRecordID: String?
    var reservationUUID: String?
    var legUUID: String?
    var dutyFingerprintSHA256: String?
    var errorCode: String?
    var retryable: Bool?
    var userActionRequired: Bool?
    var requestID: String?
    var error: String?
    var warnings: [String]?

    enum CodingKeys: String, CodingKey {
        case ok
        case alreadyPresent = "already_present"
        case schedulerRecordID = "scheduler_record_id"
        case reservationUUID = "reservation_uuid"
        case legUUID = "leg_uuid"
        case dutyFingerprintSHA256 = "duty_fingerprint_sha256"
        case errorCode = "error_code"
        case retryable
        case userActionRequired = "user_action_required"
        case requestID = "request_id"
        case error
        case warnings
    }
}

struct DispatchSyncResponse: Codable {
    struct ServerDispatch: Codable {
        var id: Int
        var dispatchUUID: String
        var dispatchVersion: Int
        var flightRecordUUID: String
        var status: String

        enum CodingKeys: String, CodingKey {
            case id
            case dispatchUUID = "dispatch_uuid"
            case dispatchVersion = "dispatch_version"
            case flightRecordUUID = "flight_record_uuid"
            case status
        }
    }

    struct Receipt: Codable {
        var receiptID: String
        var componentType: String
        var payloadSHA256: String
        var serverVerifiedAt: String

        enum CodingKeys: String, CodingKey {
            case receiptID = "receipt_id"
            case componentType = "component_type"
            case payloadSHA256 = "payload_sha256"
            case serverVerifiedAt = "server_verified_at"
        }
    }

    var ok: Bool
    var alreadyPresent: Bool?
    var continuityWarnings: [String]?
    var dispatch: ServerDispatch?
    var receipt: Receipt?
    var errorCode: String?
    var retryable: Bool?
    var userActionRequired: Bool?
    var requestID: String?
    var error: String?

    enum CodingKeys: String, CodingKey {
        case ok
        case alreadyPresent = "already_present"
        case continuityWarnings = "continuity_warnings"
        case dispatch
        case receipt
        case errorCode = "error_code"
        case retryable
        case userActionRequired = "user_action_required"
        case requestID = "request_id"
        case error
    }
}

struct WorkflowEvidenceSyncResponse: Codable {
    var ok: Bool
    var alreadyPresent: Bool?
    var receipt: DispatchSyncResponse.Receipt?
    var errorCode: String?
    var retryable: Bool?
    var userActionRequired: Bool?
    var requestID: String?
    var canonicalIdentifiers: [String: APIJSONValue]?
    var error: String?

    enum CodingKeys: String, CodingKey {
        case ok
        case alreadyPresent = "already_present"
        case receipt
        case errorCode = "error_code"
        case retryable
        case userActionRequired = "user_action_required"
        case requestID = "request_id"
        case canonicalIdentifiers = "canonical_identifiers"
        case error
    }
}

enum APIJSONValue: Codable, Equatable {
    case string(String)
    case number(Double)
    case bool(Bool)
    case object([String: APIJSONValue])
    case array([APIJSONValue])
    case null

    init(from decoder: Decoder) throws {
        let container = try decoder.singleValueContainer()
        if container.decodeNil() {
            self = .null
        } else if let value = try? container.decode(Bool.self) {
            self = .bool(value)
        } else if let value = try? container.decode(Double.self) {
            self = .number(value)
        } else if let value = try? container.decode(String.self) {
            self = .string(value)
        } else if let value = try? container.decode([String: APIJSONValue].self) {
            self = .object(value)
        } else {
            self = .array(try container.decode([APIJSONValue].self))
        }
    }

    func encode(to encoder: Encoder) throws {
        var container = encoder.singleValueContainer()
        switch self {
        case .string(let value): try container.encode(value)
        case .number(let value): try container.encode(value)
        case .bool(let value): try container.encode(value)
        case .object(let value): try container.encode(value)
        case .array(let value): try container.encode(value)
        case .null: try container.encodeNil()
        }
    }

    var stringValue: String? {
        switch self {
        case .string(let value):
            return value
        case .number(let value):
            return value.rounded() == value ? String(Int64(value)) : String(value)
        case .bool(let value):
            return String(value)
        case .object, .array, .null:
            return nil
        }
    }
}

struct WorkflowReconciliationRequest: Codable {
    var items: [WorkflowReconciliationRequestItem]
}

struct WorkflowReconciliationRequestItem: Codable {
    var itemID: String
    var componentType: String
    var dispatchUUID: String
    var dispatchVersion: Int?
    var flightRecordUUID: String
    var componentUUID: String?
    var payload: [String: APIJSONValue]

    enum CodingKeys: String, CodingKey {
        case itemID = "item_id"
        case componentType = "component_type"
        case dispatchUUID = "dispatch_uuid"
        case dispatchVersion = "dispatch_version"
        case flightRecordUUID = "flight_record_uuid"
        case componentUUID = "component_uuid"
        case payload
    }
}

struct WorkflowReconciliationResponse: Codable {
    var ok: Bool
    var requestID: String?
    var results: [WorkflowReconciliationResult]
    var errorCode: String?
    var retryable: Bool?
    var userActionRequired: Bool?
    var error: String?

    enum CodingKeys: String, CodingKey {
        case ok
        case requestID = "request_id"
        case results
        case errorCode = "error_code"
        case retryable
        case userActionRequired = "user_action_required"
        case error
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        ok = try container.decode(Bool.self, forKey: .ok)
        requestID = try container.decodeIfPresent(String.self, forKey: .requestID)
        results = try container.decodeIfPresent([WorkflowReconciliationResult].self, forKey: .results) ?? []
        errorCode = try container.decodeIfPresent(String.self, forKey: .errorCode)
        retryable = try container.decodeIfPresent(Bool.self, forKey: .retryable)
        userActionRequired = try container.decodeIfPresent(Bool.self, forKey: .userActionRequired)
        error = try container.decodeIfPresent(String.self, forKey: .error)
    }
}

struct WorkflowReconciliationResult: Codable {
    enum Status: String, Codable {
        case verifiedMatch = "VERIFIED_MATCH"
        case notFound = "NOT_FOUND"
        case immutableConflict = "IMMUTABLE_CONFLICT"
        case userCorrectionRequired = "USER_CORRECTION_REQUIRED"
        case dependencyNotReady = "DEPENDENCY_NOT_READY"
        case authenticationRequired = "AUTHENTICATION_REQUIRED"
        case temporaryTechnicalFailure = "TEMPORARY_TECHNICAL_FAILURE"
    }

    var itemID: String
    var componentType: String
    var status: Status
    var receiptID: String?
    var receivedAt: String?
    var payloadSHA256: String?
    var canonicalIdentifiers: [String: APIJSONValue]?
    var retryable: Bool?
    var userActionRequired: Bool?
    var error: String?

    enum CodingKeys: String, CodingKey {
        case itemID = "item_id"
        case componentType = "component_type"
        case status
        case receiptID = "receipt_id"
        case receivedAt = "received_at"
        case payloadSHA256 = "payload_sha256"
        case canonicalIdentifiers = "canonical_identifiers"
        case retryable
        case userActionRequired = "user_action_required"
        case error
    }
}

struct FlightLogAdjustmentResponse: Codable {
    var ok: Bool
    var adjustmentUUID: String?
    var error: String?

    enum CodingKeys: String, CodingKey {
        case ok
        case adjustmentUUID = "adjustment_uuid"
        case error
    }
}

struct FlightLogRetryResponse: Codable {
    var ok: Bool
    var transcriptionsQueued: Int?
    var error: String?

    enum CodingKeys: String, CodingKey {
        case ok
        case transcriptionsQueued = "transcriptions_queued"
        case error
    }
}

struct CvrCsvKnownHashEntry: Codable {
    var sha256: String
    var csvFileUuid: String?
    var status: String?
    /// Present once the server links this CSV to a workflow Flight Record; absent on older
    /// deployments. Consumers must treat a missing value as "unknown" rather than "false".
    var workflowFlightRecordUuid: String?
    var workflowLinked: Bool?

    enum CodingKeys: String, CodingKey {
        case sha256
        case csvFileUuid = "csv_file_uuid"
        case status
        case workflowFlightRecordUuid = "workflow_flight_record_uuid"
        case workflowLinked = "workflow_linked"
    }
}

struct CvrCsvKnownHashesResponse: Codable {
    var ok: Bool
    var known: [CvrCsvKnownHashEntry]
    var unknown: [String]
    var error: String?

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        ok = try container.decode(Bool.self, forKey: .ok)
        known = try container.decodeIfPresent([CvrCsvKnownHashEntry].self, forKey: .known) ?? []
        unknown = try container.decodeIfPresent([String].self, forKey: .unknown) ?? []
        error = try container.decodeIfPresent(String.self, forKey: .error)
    }
}

struct CvrCsvChunkUploadResponse: Codable {
    var ok: Bool
    var uploadUuid: String?
    var receivedChunks: [Int]?
    var complete: Bool?
    var error: String?

    enum CodingKeys: String, CodingKey {
        case ok
        case uploadUuid = "upload_uuid"
        case receivedChunks = "received_chunks"
        case complete
        case error
    }
}

struct CvrCsvFinalizeResponse: Codable {
    var ok: Bool
    var status: String?
    var csvFileUuid: String?
    var sha256: String?
    var workflowLinked: Bool?
    var error: String?

    enum CodingKeys: String, CodingKey {
        case ok
        case status
        case csvFileUuid = "csv_file_uuid"
        case sha256
        case workflowLinked = "workflow_linked"
        case error
    }
}

struct APISynchronizationFailure: Decodable, Equatable {
    var errorCode: String
    var error: String
    var retryable: Bool
    var userActionRequired: Bool
    var requestID: String?
    var receiptID: String?
    var serverDispatchID: Int?
    var dispatchUUID: String?
    var flightRecordUUID: String?
    var componentUUID: String?
    var httpStatus: Int?

    enum CodingKeys: String, CodingKey {
        case errorCode = "error_code"
        case error
        case retryable
        case userActionRequired = "user_action_required"
        case requestID = "request_id"
        case receiptID = "receipt_id"
        case serverDispatchID = "server_dispatch_id"
        case dispatchUUID = "dispatch_uuid"
        case flightRecordUUID = "flight_record_uuid"
        case componentUUID = "component_uuid"
    }
}

struct CVROperationalLegReviewLeg: Codable, Equatable, Identifiable {
    var id: Int { sequenceNumber }
    var sequenceNumber: Int
    var departureAirport: String
    var arrivalAirport: String
    var offBlockUTC: String
    var onBlockUTC: String
    var startingHobbs: Double
    var endingHobbs: Double
    var startingTacho: Double
    var endingTacho: Double
    var takeoffCount: Int
    var landingCount: Int
    var fuelOnboard: Double?
    var fuelRemaining: Double?

    enum CodingKeys: String, CodingKey {
        case sequenceNumber = "sequence_number"
        case legIndex = "leg_index"
        case departureAirport = "departure_airport"
        case arrivalAirport = "arrival_airport"
        case offBlockUTC = "off_block_utc"
        case onBlockUTC = "on_block_utc"
        case startingHobbs = "starting_hobbs"
        case endingHobbs = "ending_hobbs"
        case startingTacho = "starting_tacho"
        case endingTacho = "ending_tacho"
        case takeoffCount = "takeoff_count"
        case landingCount = "landing_count"
        case fuelOnboard = "fuel_onboard"
        case fuelRemaining = "fuel_remaining"
    }

    init(
        sequenceNumber: Int,
        departureAirport: String,
        arrivalAirport: String,
        offBlockUTC: String,
        onBlockUTC: String,
        startingHobbs: Double,
        endingHobbs: Double,
        startingTacho: Double,
        endingTacho: Double,
        takeoffCount: Int,
        landingCount: Int,
        fuelOnboard: Double?,
        fuelRemaining: Double?
    ) {
        self.sequenceNumber = sequenceNumber
        self.departureAirport = departureAirport
        self.arrivalAirport = arrivalAirport
        self.offBlockUTC = offBlockUTC
        self.onBlockUTC = onBlockUTC
        self.startingHobbs = startingHobbs
        self.endingHobbs = endingHobbs
        self.startingTacho = startingTacho
        self.endingTacho = endingTacho
        self.takeoffCount = takeoffCount
        self.landingCount = landingCount
        self.fuelOnboard = fuelOnboard
        self.fuelRemaining = fuelRemaining
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        sequenceNumber = try container.decodeIfPresent(Int.self, forKey: .sequenceNumber)
            ?? container.decode(Int.self, forKey: .legIndex)
        departureAirport = try container.decode(String.self, forKey: .departureAirport)
        arrivalAirport = try container.decode(String.self, forKey: .arrivalAirport)
        offBlockUTC = try container.decode(String.self, forKey: .offBlockUTC)
        onBlockUTC = try container.decode(String.self, forKey: .onBlockUTC)
        startingHobbs = try container.decode(Double.self, forKey: .startingHobbs)
        endingHobbs = try container.decode(Double.self, forKey: .endingHobbs)
        startingTacho = try container.decode(Double.self, forKey: .startingTacho)
        endingTacho = try container.decode(Double.self, forKey: .endingTacho)
        takeoffCount = try container.decode(Int.self, forKey: .takeoffCount)
        landingCount = try container.decode(Int.self, forKey: .landingCount)
        fuelOnboard = try container.decodeIfPresent(Double.self, forKey: .fuelOnboard)
        fuelRemaining = try container.decodeIfPresent(Double.self, forKey: .fuelRemaining)
    }

    func encode(to encoder: Encoder) throws {
        var container = encoder.container(keyedBy: CodingKeys.self)
        try container.encode(sequenceNumber, forKey: .sequenceNumber)
        try container.encode(departureAirport, forKey: .departureAirport)
        try container.encode(arrivalAirport, forKey: .arrivalAirport)
        try container.encode(offBlockUTC, forKey: .offBlockUTC)
        try container.encode(onBlockUTC, forKey: .onBlockUTC)
        try container.encode(startingHobbs, forKey: .startingHobbs)
        try container.encode(endingHobbs, forKey: .endingHobbs)
        try container.encode(startingTacho, forKey: .startingTacho)
        try container.encode(endingTacho, forKey: .endingTacho)
        try container.encode(takeoffCount, forKey: .takeoffCount)
        try container.encode(landingCount, forKey: .landingCount)
        try container.encodeIfPresent(fuelOnboard, forKey: .fuelOnboard)
        try container.encodeIfPresent(fuelRemaining, forKey: .fuelRemaining)
    }
}

struct CVROperationalLegReviewPreview: Codable {
    var operationalSessionUUID: String
    var evidenceSHA256: String?
    var proposedLegs: [CVROperationalLegReviewLeg]
    var startingHobbs: Double?
    var endingHobbs: Double?
    var startingTacho: Double?
    var endingTacho: Double?
    var fuelStart: Double?
    var fuelEnd: Double?
    var fuelBurnTotal: Double?
    var offBlockUTC: String?
    var onBlockUTC: String?
    var verifiedTakeoffCount: Int?
    var verifiedLandingCount: Int?
    var crew: [CVRScheduledCrewMember]?
    var legReviewVerified: Bool?
    var acceptedRevisionUUID: String?
    var acceptedRevisionNumber: Int?

    enum CodingKeys: String, CodingKey {
        case operationalSessionUUID = "operational_session_uuid"
        case evidenceSHA256 = "evidence_sha256"
        case proposedLegs = "proposed_legs"
        case startingHobbs = "starting_hobbs"
        case endingHobbs = "ending_hobbs"
        case startingTacho = "starting_tacho"
        case endingTacho = "ending_tacho"
        case fuelStart = "fuel_start"
        case fuelEnd = "fuel_end"
        case fuelBurnTotal = "fuel_burn_total"
        case offBlockUTC = "off_block_utc"
        case onBlockUTC = "on_block_utc"
        case verifiedTakeoffCount = "verified_takeoff_count"
        case verifiedLandingCount = "verified_landing_count"
        case crew
        case legReviewVerified = "leg_review_verified"
        case acceptedRevisionUUID = "accepted_revision_uuid"
        case acceptedRevisionNumber = "accepted_revision_number"
    }
}

struct CVROperationalLegReviewPreviewResponse: Codable {
    var ok: Bool
    var review: CVROperationalLegReviewPreview
}

struct CVROperationalLegReviewAcceptResponse: Codable {
    var ok: Bool
    var alreadyPresent: Bool?
    var revisionUUID: String
    var revisionNumber: Int
    var legs: [CVROperationalLegReviewLeg]

    enum CodingKeys: String, CodingKey {
        case ok
        case alreadyPresent = "already_present"
        case revisionUUID = "revision_uuid"
        case revisionNumber = "revision_number"
        case legs
    }
}

struct CVROperationalLegReviewStatusResponse: Codable {
    var ok: Bool
    var dispatchUUID: String
    var verified: Bool
    var revisionUUID: String?
    var revisionNumber: Int?

    enum CodingKeys: String, CodingKey {
        case ok
        case dispatchUUID = "dispatch_uuid"
        case verified
        case revisionUUID = "revision_uuid"
        case revisionNumber = "revision_number"
    }
}

enum APIClientError: LocalizedError {
    case invalidServerURL
    case badResponse(String)
    case synchronization(APISynchronizationFailure)
    case invalidJSON(String)
    case missingRecordingFile

    var errorDescription: String? {
        switch self {
        case .invalidServerURL: "Server URL is invalid."
        case .badResponse(let message): message
        case .synchronization(let failure): failure.error
        case .invalidJSON(let message): message
        case .missingRecordingFile: "Recording file is missing."
        }
    }
}

struct APIClient {
    var serverURL: URL

    func chunkUploadRequest(
        recording: Recording,
        fileType: String,
        chunkIndex: Int,
        totalChunks: Int,
        totalSize: Int64,
        chunkSize: Int,
        originalFilename: String,
        mimeType: String
    ) -> URLRequest {
        let url = serverURL.appending(path: "api/recordings/upload_chunk.php")
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.timeoutInterval = 120
        request.setValue("application/octet-stream", forHTTPHeaderField: "Content-Type")
        request.setValue(recording.id, forHTTPHeaderField: "X-IPCA-Recording-ID")
        request.setValue(fileType, forHTTPHeaderField: "X-IPCA-File-Type")
        request.setValue(String(chunkIndex), forHTTPHeaderField: "X-IPCA-Chunk-Index")
        request.setValue(String(totalChunks), forHTTPHeaderField: "X-IPCA-Total-Chunks")
        request.setValue(String(totalSize), forHTTPHeaderField: "X-IPCA-Total-Size")
        request.setValue(String(chunkSize), forHTTPHeaderField: "X-IPCA-Chunk-Size")
        request.setValue(originalFilename, forHTTPHeaderField: "X-IPCA-Original-Filename")
        request.setValue(mimeType, forHTTPHeaderField: "X-IPCA-Mime-Type")
        return request
    }

    func chunkUploadStatus(recordingID: String, fileType: String) async throws -> ChunkUploadStatusResponse {
        let url = try endpoint("api/recordings/upload_chunk.php", queryItems: [
            URLQueryItem(name: "recording_id", value: recordingID),
            URLQueryItem(name: "file_type", value: fileType),
        ])
        var request = URLRequest(url: url)
        request.httpMethod = "GET"
        request.timeoutInterval = 60
        let (data, response) = try await URLSession.shared.data(for: request)
        try validate(response: response, data: data)
        return try decode(ChunkUploadStatusResponse.self, from: data, response: response)
    }

    func uploadLiveAudioSegment(
        credential: String,
        recordingID: String,
        operationalSessionUUID: String,
        flightRecordUUID: String,
        segment: AudioRecordingSegment,
        sha256: String,
        language: String,
        audioData: Data
    ) async throws -> LiveAudioSegmentUploadResponse {
        let url = serverURL.appending(path: "api/cvr/live_audio_segment.php")
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.timeoutInterval = 180
        request.setValue("Bearer \(credential)", forHTTPHeaderField: "Authorization")
        request.setValue("audio/mp4", forHTTPHeaderField: "Content-Type")
        request.setValue(recordingID, forHTTPHeaderField: "X-IPCA-Recording-ID")
        request.setValue(operationalSessionUUID, forHTTPHeaderField: "X-IPCA-Operational-Session-UUID")
        request.setValue(flightRecordUUID, forHTTPHeaderField: "X-IPCA-Flight-Record-UUID")
        request.setValue(String(segment.index), forHTTPHeaderField: "X-IPCA-Segment-Index")
        request.setValue(ISO8601DateFormatter().string(from: segment.startedAt), forHTTPHeaderField: "X-IPCA-Segment-Started-At")
        request.setValue(String(format: "%.3f", segment.duration), forHTTPHeaderField: "X-IPCA-Segment-Duration")
        request.setValue(sha256, forHTTPHeaderField: "X-IPCA-SHA256")
        request.setValue(language, forHTTPHeaderField: "X-IPCA-Language")
        request.httpBody = audioData
        let (data, response) = try await URLSession.shared.data(for: request)
        try validate(response: response, data: data)
        return try decode(LiveAudioSegmentUploadResponse.self, from: data, response: response)
    }

    func liveCockpitMonitorLease(credential: String) async throws -> LiveCockpitMonitorLeaseResponse {
        let url = serverURL.appending(path: "api/cvr/live_cockpit_monitor_lease.php")
        var request = URLRequest(url: url)
        request.httpMethod = "GET"
        request.timeoutInterval = 10
        request.cachePolicy = .reloadIgnoringLocalAndRemoteCacheData
        request.setValue("Bearer \(credential)", forHTTPHeaderField: "Authorization")
        let (data, response) = try await URLSession.shared.data(for: request)
        try validate(response: response, data: data)
        return try decode(LiveCockpitMonitorLeaseResponse.self, from: data, response: response)
    }

    func uploadLiveCockpitMonitorChunk(
        credential: String,
        chunk: LiveCockpitEncodedChunk,
        sha256: String,
        audioData: Data
    ) async throws -> LiveCockpitMonitorChunkUploadResponse {
        let url = serverURL.appending(path: "api/cvr/live_cockpit_monitor_chunk.php")
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.timeoutInterval = 12
        request.cachePolicy = .reloadIgnoringLocalAndRemoteCacheData
        request.setValue("Bearer \(credential)", forHTTPHeaderField: "Authorization")
        request.setValue("audio/mp4", forHTTPHeaderField: "Content-Type")
        request.setValue(chunk.broadcastUUID, forHTTPHeaderField: "X-IPCA-Monitor-Broadcast-UUID")
        request.setValue(chunk.chunkUUID, forHTTPHeaderField: "X-IPCA-Monitor-Chunk-UUID")
        request.setValue(chunk.operationalSessionUUID, forHTTPHeaderField: "X-IPCA-Operational-Session-UUID")
        request.setValue(String(chunk.sequenceNumber), forHTTPHeaderField: "X-IPCA-Monitor-Sequence")
        request.setValue(ISO8601DateFormatter().string(from: chunk.startedAt), forHTTPHeaderField: "X-IPCA-Monitor-Started-At")
        request.setValue(String(format: "%.3f", chunk.duration), forHTTPHeaderField: "X-IPCA-Monitor-Duration")
        request.setValue(sha256, forHTTPHeaderField: "X-IPCA-SHA256")
        request.httpBody = audioData
        let (data, response) = try await URLSession.shared.data(for: request)
        try validate(response: response, data: data)
        return try decode(LiveCockpitMonitorChunkUploadResponse.self, from: data, response: response)
    }

    func finalizeChunkedUploadRequest(for recording: Recording, language: String) throws -> URLRequest {
        let url = serverURL.appending(path: "api/recordings/upload_finalize.php")
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.timeoutInterval = 3600
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.httpBody = try JSONSerialization.data(withJSONObject: finalizePayload(for: recording, language: language))
        return request
    }

    func status(recordingID: String) async throws -> StatusResponse {
        let url = try endpoint("api/recordings/status.php", queryItems: [
            URLQueryItem(name: "id", value: recordingID)
        ])
        let (data, response) = try await URLSession.shared.data(from: url)
        try validate(response: response, data: data)
        return try decode(StatusResponse.self, from: data, response: response)
    }

    func transcript(recordingID: String) async throws -> TranscriptResponse {
        let url = try endpoint("api/recordings/transcript.php", queryItems: [
            URLQueryItem(name: "id", value: recordingID)
        ])
        let (data, response) = try await URLSession.shared.data(from: url)
        try validate(response: response, data: data)
        return try decode(TranscriptResponse.self, from: data, response: response)
    }

    func aircraft() async throws -> AircraftListResponse {
        let url = serverURL.appending(path: "api/recordings/aircraft.php")
        let (data, response) = try await URLSession.shared.data(from: url)
        try validate(response: response, data: data)
        return try decode(AircraftListResponse.self, from: data, response: response)
    }

    func crewUsers() async throws -> CrewUsersResponse {
        let url = serverURL.appending(path: "api/recordings/crew_users.php")
        let (data, response) = try await URLSession.shared.data(from: url)
        try validate(response: response, data: data)
        return try decode(CrewUsersResponse.self, from: data, response: response)
    }

    func missions() async throws -> MissionCatalogResponse {
        let url = serverURL.appending(path: "api/recordings/missions.php")
        let (data, response) = try await URLSession.shared.data(from: url)
        try validate(response: response, data: data)
        return try decode(MissionCatalogResponse.self, from: data, response: response)
    }

    func scheduledSessions(credential: String) async throws -> ScheduledSessionsResponse {
        let timeZone = TimeZone(identifier: "America/Los_Angeles") ?? .current
        var calendar = Calendar(identifier: .gregorian)
        calendar.timeZone = timeZone
        let formatter = DateFormatter()
        formatter.calendar = calendar
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.timeZone = timeZone
        formatter.dateFormat = "yyyy-MM-dd"
        let today = calendar.startOfDay(for: Date())
        let from = calendar.date(byAdding: .day, value: -1, to: today) ?? today
        let to = calendar.date(byAdding: .day, value: 15, to: today) ?? today
        var components = URLComponents(
            url: serverURL.appending(path: "api/cvr/scheduled_sessions.php"),
            resolvingAgainstBaseURL: false
        )
        components?.queryItems = [
            URLQueryItem(name: "from", value: formatter.string(from: from)),
            URLQueryItem(name: "to", value: formatter.string(from: to))
        ]
        guard let url = components?.url else {
            throw APIClientError.invalidServerURL
        }
        var request = URLRequest(url: url)
        request.httpMethod = "GET"
        request.timeoutInterval = 60
        request.setValue("Bearer \(credential)", forHTTPHeaderField: "Authorization")
        let (data, response) = try await URLSession.shared.data(for: request)
        try validate(response: response, data: data)
        return try decode(ScheduledSessionsResponse.self, from: data, response: response)
    }

    func flightLogs(credential: String) async throws -> CVRFlightLogsResponse {
        let url = serverURL.appending(path: "api/cvr/flight_logs.php")
        var request = URLRequest(url: url)
        request.httpMethod = "GET"
        request.timeoutInterval = 60
        request.setValue("Bearer \(credential)", forHTTPHeaderField: "Authorization")
        let (data, response) = try await URLSession.shared.data(for: request)
        try validate(response: response, data: data)
        return try decode(CVRFlightLogsResponse.self, from: data, response: response)
    }

    func pendingCrewMessages(
        operationalSessionUUID: String,
        credential: String
    ) async throws -> CVRCrewMessagesResponse {
        let url = try endpoint("api/cvr/crew_messages.php", queryItems: [
            URLQueryItem(
                name: "operational_session_uuid",
                value: operationalSessionUUID.lowercased()
            )
        ])
        var request = URLRequest(url: url)
        request.httpMethod = "GET"
        request.timeoutInterval = 15
        request.setValue("Bearer \(credential)", forHTTPHeaderField: "Authorization")
        let (data, response) = try await URLSession.shared.data(for: request)
        try validate(response: response, data: data)
        let decoder = JSONDecoder()
        decoder.dateDecodingStrategy = .iso8601
        do {
            return try decoder.decode(CVRCrewMessagesResponse.self, from: data)
        } catch {
            throw APIClientError.invalidJSON(
                "Crew message service returned invalid JSON: \(responsePreview(data))"
            )
        }
    }

    func acknowledgeCrewMessage(
        _ acknowledgement: CVRCrewMessageAcknowledgementRequest,
        credential: String
    ) async throws -> CVRCrewMessageAcknowledgementResponse {
        let url = serverURL.appending(path: "api/cvr/crew_messages.php")
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.timeoutInterval = 15
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue("Bearer \(credential)", forHTTPHeaderField: "Authorization")
        let encoder = JSONEncoder()
        encoder.dateEncodingStrategy = .iso8601
        request.httpBody = try encoder.encode(acknowledgement)
        let (data, response) = try await URLSession.shared.data(for: request)
        try validate(response: response, data: data)
        return try decode(
            CVRCrewMessageAcknowledgementResponse.self,
            from: data,
            response: response
        )
    }

    func operationalLegReview(
        dispatchUUID: String,
        credential: String
    ) async throws -> CVROperationalLegReviewPreviewResponse {
        var components = URLComponents(
            url: serverURL.appending(path: "api/cvr/operational_session_leg_review.php"),
            resolvingAgainstBaseURL: false
        )
        components?.queryItems = [
            URLQueryItem(name: "dispatch_uuid", value: dispatchUUID.lowercased())
        ]
        guard let url = components?.url else {
            throw APIClientError.invalidServerURL
        }
        var request = URLRequest(url: url)
        request.httpMethod = "GET"
        request.timeoutInterval = 60
        request.setValue("Bearer \(credential)", forHTTPHeaderField: "Authorization")
        let (data, response) = try await URLSession.shared.data(for: request)
        try validate(response: response, data: data)
        return try decode(CVROperationalLegReviewPreviewResponse.self, from: data, response: response)
    }

    func operationalLegReviewStatus(
        dispatchUUID: String,
        credential: String
    ) async throws -> CVROperationalLegReviewStatusResponse {
        var components = URLComponents(
            url: serverURL.appending(path: "api/cvr/operational_session_leg_review.php"),
            resolvingAgainstBaseURL: false
        )
        components?.queryItems = [
            URLQueryItem(name: "dispatch_uuid", value: dispatchUUID.lowercased()),
            URLQueryItem(name: "status_only", value: "1"),
        ]
        guard let url = components?.url else {
            throw APIClientError.invalidServerURL
        }
        var request = URLRequest(url: url)
        request.httpMethod = "GET"
        request.timeoutInterval = 30
        request.setValue("Bearer \(credential)", forHTTPHeaderField: "Authorization")
        let (data, response) = try await URLSession.shared.data(for: request)
        try validate(response: response, data: data)
        return try decode(CVROperationalLegReviewStatusResponse.self, from: data, response: response)
    }

    func acceptOperationalLegReview(
        payload: [String: Any],
        credential: String
    ) async throws -> CVROperationalLegReviewAcceptResponse {
        let url = serverURL.appending(path: "api/cvr/operational_session_leg_review.php")
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.timeoutInterval = 120
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue("Bearer \(credential)", forHTTPHeaderField: "Authorization")
        request.httpBody = try JSONSerialization.data(withJSONObject: payload, options: [.sortedKeys])
        let (data, response) = try await URLSession.shared.data(for: request)
        try validate(response: response, data: data)
        return try decode(CVROperationalLegReviewAcceptResponse.self, from: data, response: response)
    }

    func adjustFlightLog(payload: [String: Any], credential: String) async throws -> FlightLogAdjustmentResponse {
        let url = serverURL.appending(path: "api/cvr/flight_log_adjust.php")
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.timeoutInterval = 120
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue("Bearer \(credential)", forHTTPHeaderField: "Authorization")
        request.httpBody = try JSONSerialization.data(withJSONObject: payload, options: [.sortedKeys])
        let (data, response) = try await URLSession.shared.data(for: request)
        try validate(response: response, data: data)
        return try decode(FlightLogAdjustmentResponse.self, from: data, response: response)
    }

    func retryFlightLog(flightRecordID: String, credential: String) async throws -> FlightLogRetryResponse {
        let url = serverURL.appending(path: "api/cvr/flight_log_retry.php")
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.timeoutInterval = 120
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue("Bearer \(credential)", forHTTPHeaderField: "Authorization")
        request.httpBody = try JSONSerialization.data(withJSONObject: [
            "flight_record_uuid": flightRecordID.lowercased()
        ])
        let (data, response) = try await URLSession.shared.data(for: request)
        try validate(response: response, data: data)
        return try decode(FlightLogRetryResponse.self, from: data, response: response)
    }

    func enrollDevice(code: String, deviceUUID: String, displayName: String) async throws -> DeviceEnrollmentResponse {
        let url = serverURL.appending(path: "api/cvr/enroll.php")
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.timeoutInterval = 60
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.httpBody = try JSONSerialization.data(withJSONObject: [
            "enrollment_code": code,
            "device_uuid": deviceUUID,
            "display_name": displayName
        ])
        let (data, response) = try await URLSession.shared.data(for: request)
        try validate(response: response, data: data)
        return try decode(DeviceEnrollmentResponse.self, from: data, response: response)
    }

    func deviceStatus(credential: String) async throws -> DeviceStatusResponse {
        let url = serverURL.appending(path: "api/cvr/device_status.php")
        var request = URLRequest(url: url)
        request.httpMethod = "GET"
        request.timeoutInterval = 30
        request.setValue("Bearer \(credential)", forHTTPHeaderField: "Authorization")
        let (data, response) = try await URLSession.shared.data(for: request)
        try validate(response: response, data: data)
        return try decode(DeviceStatusResponse.self, from: data, response: response)
    }

    func syncDispatch(payload: [String: Any], credential: String) async throws -> DispatchSyncResponse {
        let url = serverURL.appending(path: "api/cvr/dispatch_sync.php")
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.timeoutInterval = 120
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue("Bearer \(credential)", forHTTPHeaderField: "Authorization")
        let dispatchPayload = payload["dispatch"] as? [String: Any]
        request.setValue(dispatchPayload?["id"] as? String, forHTTPHeaderField: "X-IPCA-Request-ID")
        request.httpBody = try JSONSerialization.data(withJSONObject: payload, options: [.sortedKeys])
        let (data, response) = try await URLSession.shared.data(for: request)
        try validate(response: response, data: data)
        return try decode(DispatchSyncResponse.self, from: data, response: response)
    }

    func syncScheduleDuty(payload: [String: Any], credential: String) async throws -> ScheduleDutySyncResponse {
        let url = serverURL.appending(path: "api/cvr/schedule_duty_sync.php")
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.timeoutInterval = 60
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue("Bearer \(credential)", forHTTPHeaderField: "Authorization")
        request.setValue(payload["request_id"] as? String, forHTTPHeaderField: "X-IPCA-Request-ID")
        request.httpBody = try JSONSerialization.data(withJSONObject: payload, options: [.sortedKeys])
        let (data, response) = try await URLSession.shared.data(for: request)
        try validate(response: response, data: data)
        return try decode(ScheduleDutySyncResponse.self, from: data, response: response)
    }

    func releaseDispatch(
        dispatchUUID: String?,
        schedulerRecordID: String?,
        credential: String
    ) async throws -> DispatchReleaseResponse {
        let url = serverURL.appending(path: "api/cvr/dispatch_release.php")
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.timeoutInterval = 60
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue("Bearer \(credential)", forHTTPHeaderField: "Authorization")
        var body: [String: Any] = [:]
        if let dispatchUUID, !dispatchUUID.isEmpty {
            body["dispatch_uuid"] = dispatchUUID
        }
        if let schedulerRecordID, !schedulerRecordID.isEmpty {
            body["scheduler_record_id"] = schedulerRecordID
        }
        request.httpBody = try JSONSerialization.data(withJSONObject: body, options: [.sortedKeys])
        let (data, response) = try await URLSession.shared.data(for: request)
        try validate(response: response, data: data)
        return try decode(DispatchReleaseResponse.self, from: data, response: response)
    }

    func syncWorkflowEvidence(payload: [String: Any], credential: String) async throws -> WorkflowEvidenceSyncResponse {
        let url = serverURL.appending(path: "api/cvr/flight_events_sync.php")
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.timeoutInterval = 120
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue("Bearer \(credential)", forHTTPHeaderField: "Authorization")
        request.setValue(payload["component_uuid"] as? String, forHTTPHeaderField: "X-IPCA-Request-ID")
        request.httpBody = try JSONSerialization.data(withJSONObject: payload, options: [.sortedKeys])
        let (data, response) = try await URLSession.shared.data(for: request)
        try validate(response: response, data: data)
        return try decode(WorkflowEvidenceSyncResponse.self, from: data, response: response)
    }

    func reconcileWorkflowSync(
        request reconciliationRequest: WorkflowReconciliationRequest,
        credential: String
    ) async throws -> WorkflowReconciliationResponse {
        let url = serverURL.appending(path: "api/cvr/sync_reconcile.php")
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.timeoutInterval = 120
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue("Bearer \(credential)", forHTTPHeaderField: "Authorization")
        request.httpBody = try JSONEncoder().encode(reconciliationRequest)
        let (data, response) = try await URLSession.shared.data(for: request)
        try validate(response: response, data: data)
        return try decode(WorkflowReconciliationResponse.self, from: data, response: response)
    }

    func knownGarminCsvHashes(sha256List: [String], aircraftRegistration: String, credential: String) async throws -> CvrCsvKnownHashesResponse {
        let url = serverURL.appending(path: "api/cvr/csv_known_hashes.php")
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.timeoutInterval = 60
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue("Bearer \(credential)", forHTTPHeaderField: "Authorization")
        request.httpBody = try JSONSerialization.data(withJSONObject: [
            "sha256_list": sha256List,
            "aircraft_registration": aircraftRegistration
        ])
        let (data, response) = try await URLSession.shared.data(for: request)
        try validate(response: response, data: data)
        return try decode(CvrCsvKnownHashesResponse.self, from: data, response: response)
    }

    func uploadCvrCsvChunk(
        credential: String,
        uploadUUID: String,
        sessionUUID: String?,
        chunkIndex: Int,
        totalChunks: Int,
        totalSize: Int64,
        originalFilename: String,
        chunkData: Data
    ) async throws -> CvrCsvChunkUploadResponse {
        let boundary = "Boundary-\(UUID().uuidString)"
        let url = serverURL.appending(path: "api/cvr/csv_upload_chunk.php")
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.timeoutInterval = 120
        request.setValue("Bearer \(credential)", forHTTPHeaderField: "Authorization")
        request.setValue("multipart/form-data; boundary=\(boundary)", forHTTPHeaderField: "Content-Type")

        var body = Data()
        func appendField(_ name: String, _ value: String) {
            body.append("--\(boundary)\r\n".data(using: .utf8)!)
            body.append("Content-Disposition: form-data; name=\"\(name)\"\r\n\r\n".data(using: .utf8)!)
            body.append("\(value)\r\n".data(using: .utf8)!)
        }
        appendField("upload_uuid", uploadUUID)
        if let sessionUUID, !sessionUUID.isEmpty {
            appendField("session_uuid", sessionUUID)
        } else {
            appendField("standalone_upload", "1")
        }
        appendField("chunk_index", String(chunkIndex))
        appendField("total_chunks", String(totalChunks))
        appendField("total_size", String(totalSize))
        appendField("original_filename", originalFilename)
        body.append("--\(boundary)\r\n".data(using: .utf8)!)
        body.append("Content-Disposition: form-data; name=\"chunk\"; filename=\"chunk.part\"\r\n".data(using: .utf8)!)
        body.append("Content-Type: application/octet-stream\r\n\r\n".data(using: .utf8)!)
        body.append(chunkData)
        body.append("\r\n".data(using: .utf8)!)
        body.append("--\(boundary)--\r\n".data(using: .utf8)!)
        request.httpBody = body

        let (data, response) = try await URLSession.shared.data(for: request)
        try validate(response: response, data: data)
        return try decode(CvrCsvChunkUploadResponse.self, from: data, response: response)
    }

    func finalizeCvrCsvUpload(
        credential: String,
        uploadUUID: String,
        workflowFlightRecordUUID: String? = nil
    ) async throws -> CvrCsvFinalizeResponse {
        let url = serverURL.appending(path: "api/cvr/csv_upload_finalize.php")
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.timeoutInterval = 3600
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue("Bearer \(credential)", forHTTPHeaderField: "Authorization")
        var payload: [String: Any] = ["upload_uuid": uploadUUID]
        if let workflowFlightRecordUUID, !workflowFlightRecordUUID.isEmpty {
            payload["workflow_flight_record_uuid"] = workflowFlightRecordUUID
        }
        request.httpBody = try JSONSerialization.data(withJSONObject: payload)
        let (data, response) = try await URLSession.shared.data(for: request)
        try validate(response: response, data: data)
        return try decode(CvrCsvFinalizeResponse.self, from: data, response: response)
    }

    func decodeUploadResponse(data: Data, response: URLResponse) throws -> UploadResponse {
        try validate(response: response, data: data)
        return try decode(UploadResponse.self, from: data, response: response)
    }

    func decodeChunkUploadResponse(data: Data, response: URLResponse) throws -> ChunkUploadResponse {
        try validate(response: response, data: data)
        return try decode(ChunkUploadResponse.self, from: data, response: response)
    }

    private func finalizePayload(for recording: Recording, language: String) -> [String: Any] {
        var metadata: [String: Any] = [
            "recording_id": recording.id,
            "started_at": ISO8601DateFormatter().string(from: recording.startedAt),
            "duration": recording.duration,
            "input_device": recording.inputDeviceName,
            "aircraft_id": recording.aircraftID ?? 0,
            "import_profile": "audio_only",
            "language": language,
            "flight_session_uid": recording.flightSessionID,
            "flight_segment_index": recording.segmentIndex,
            "previous_segment_uid": recording.previousSegmentID ?? "",
            "is_test_recording": recording.isTestRecording ? 1 : 0,
            "source_gap_summary": recording.sourceGapSummary ?? ""
        ]
        if let operationalSessionID = recording.operationalSessionID, !operationalSessionID.isEmpty {
            metadata["operational_session_uuid"] = operationalSessionID.lowercased()
        }
        return metadata
    }

    private func validate(response: URLResponse, data: Data) throws {
        guard let http = response as? HTTPURLResponse else { return }
        if http.statusCode >= 400 {
            if var failure = try? JSONDecoder().decode(APISynchronizationFailure.self, from: data) {
                failure.httpStatus = http.statusCode
                throw APIClientError.synchronization(failure)
            }
            throw APIClientError.badResponse("HTTP \(http.statusCode): \(responsePreview(data))")
        }
    }

    private func decode<T: Decodable>(_ type: T.Type, from data: Data, response: URLResponse) throws -> T {
        do {
            return try JSONDecoder().decode(type, from: data)
        } catch {
            let url = (response as? HTTPURLResponse)?.url?.absoluteString ?? "unknown URL"
            throw APIClientError.invalidJSON("Server did not return valid JSON from \(url). Response: \(responsePreview(data))")
        }
    }

    private func endpoint(_ path: String, queryItems: [URLQueryItem]) throws -> URL {
        var components = URLComponents(url: serverURL.appending(path: path), resolvingAgainstBaseURL: false)
        components?.queryItems = queryItems
        guard let url = components?.url else {
            throw APIClientError.invalidServerURL
        }
        return url
    }

    private func responsePreview(_ data: Data) -> String {
        let text = String(data: data, encoding: .utf8) ?? "\(data.count) bytes"
        let compact = text
            .replacingOccurrences(of: "\n", with: " ")
            .replacingOccurrences(of: "\r", with: " ")
            .trimmingCharacters(in: .whitespacesAndNewlines)
        if compact.count > 500 {
            return String(compact.prefix(500)) + "..."
        }
        return compact.isEmpty ? "empty response" : compact
    }
}
