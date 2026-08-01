import Combine
import Foundation

@MainActor
final class UploadManager: ObservableObject {
    @Published private(set) var activeUploads: Set<String> = []
    private let chunkSize = 512 * 1024
    private let maxChunkAttempts = 8
    private var retryTasks: [String: Task<Void, Never>] = [:]

    private lazy var session: URLSession = {
        let configuration = URLSessionConfiguration.default
        configuration.timeoutIntervalForRequest = 120
        configuration.timeoutIntervalForResource = 6 * 3600
        configuration.waitsForConnectivity = true
        configuration.allowsCellularAccess = true
        return URLSession(configuration: configuration)
    }()

    func uploadPending(store: RecordingStore, settings: SettingsStore, network: NetworkMonitor) {
        guard !settings.isSimulationModeEnabled else { return }
        guard network.canUpload(allowCellular: settings.allowCellularUpload) else { return }
        for id in store.pendingUploadIDs() {
            upload(recordingID: id, store: store, settings: settings)
        }
    }

    func upload(recordingID: String, store: RecordingStore, settings: SettingsStore) {
        guard settings.isServerURLConfigured else {
            store.update(recordingID) {
                $0.uploadStatus = .failed
                $0.lastError = "Server URL is not configured."
                scheduleRetryFields(recording: &$0, reason: $0.lastError)
            }
            scheduleRetry(recordingID: recordingID, store: store, settings: settings)
            return
        }

        guard !activeUploads.contains(recordingID) else { return }
        if let recording = store.recording(id: recordingID),
           recording.uploadStatus == .failed,
           let next = recording.nextUploadRetryAt,
           next > Date() {
            scheduleRetry(recordingID: recordingID, store: store, settings: settings)
            return
        }

        retryTasks[recordingID]?.cancel()
        retryTasks[recordingID] = nil
        activeUploads.insert(recordingID)
        store.update(recordingID) {
            if $0.uploadStatus != .uploaded {
                $0.uploadStatus = .uploading
            }
            if $0.uploadProgress < 0.01 {
                $0.lastError = "Starting automatic upload..."
            }
        }

        Task {
            defer {
                Task { @MainActor in
                    self.activeUploads.remove(recordingID)
                }
            }

            do {
                try await performUpload(recordingID: recordingID, store: store, settings: settings)
            } catch {
                await MainActor.run {
                    if store.recording(id: recordingID)?.uploadStatus == .uploaded {
                        store.update(recordingID) {
                            $0.transcriptStatus = .failed
                            $0.lastError = "Upload is complete. Transcript follow-up failed: \(error.localizedDescription)"
                            $0.uploadRetryCount = nil
                            $0.nextUploadRetryAt = nil
                        }
                    } else {
                        store.update(recordingID) {
                            $0.uploadStatus = .failed
                            scheduleRetryFields(recording: &$0, reason: error.localizedDescription)
                        }
                        self.scheduleRetry(recordingID: recordingID, store: store, settings: settings)
                    }
                }
            }
        }
    }

    func uploadQueuedWorkflowComponents(workflow: CVRWorkflowStore, settings: SettingsStore) {
        guard !settings.isSimulationModeEnabled else { return }
        let supportedTypes = Set([
            "dispatch_metadata", "garmin_csv", "flight_events",
            "recorder_verification", "flight_record_closure"
        ])
        let components = workflow.queuedWorkflowComponents().filter {
            supportedTypes.contains($0.componentType)
        }
        guard !components.isEmpty else { return }
        guard let baseURL = settings.normalizedServerURL else {
            for component in components where component.componentType == "dispatch_metadata" {
                workflow.updateUploadComponent(
                    id: component.id,
                    state: .needsUserAction,
                    progress: component.progress ?? 0,
                    lastError: "Server URL is not configured. Open Admin, configure the server, then retry Dispatch upload."
                )
            }
            return
        }

        for component in components {
            guard let context = workflow.workflowUploadContext(componentID: component.id) else { continue }
            if component.componentType == "flight_record_closure",
               !workflow.flightClosureIsComplete(context.flightRecord) {
                workflow.updateUploadComponent(
                    id: component.id,
                    state: .needsUserAction,
                    progress: component.progress ?? 0,
                    lastError: "Ending Hobbs, Ending Tacho, fuel remaining, and oil remaining are required before closure upload."
                )
                continue
            }
            if component.componentType != "dispatch_metadata",
               component.componentType != "garmin_csv",
               context.dispatch.serverDispatchID == nil {
                continue
            }
            guard !activeUploads.contains(component.id) else { continue }
            activeUploads.insert(component.id)
            let componentLabel: String = switch component.componentType {
            case "dispatch_metadata": "Dispatch"
            case "garmin_csv": "Garmin CSV"
            case "flight_events": "Flight Event"
            case "recorder_verification": "Recorder Verification"
            default: "Flight Closure"
            }
            workflow.updateUploadComponent(
                id: component.id,
                state: .uploading,
                progress: max(component.progress ?? 0.01, 0.01),
                lastError: "Starting \(componentLabel) upload..."
            )

            Task {
                defer {
                    Task { @MainActor in
                        self.activeUploads.remove(component.id)
                    }
                }
                do {
                    let serverReceiptID: String
                    if component.componentType == "dispatch_metadata" {
                        let result = try await uploadWorkflowDispatchComponent(
                            component: component,
                            context: context,
                            settings: settings,
                            baseURL: baseURL,
                            workflow: workflow
                        )
                        serverReceiptID = result.receiptID
                        await MainActor.run {
                            workflow.markDispatchStoredOnServer(
                                serverDispatchID: result.serverDispatchID,
                                flightRecordID: context.flightRecord.id
                            )
                            self.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                        }
                    } else if component.componentType == "garmin_csv" {
                        serverReceiptID = try await uploadWorkflowGarminComponent(
                            component: component,
                            flightRecordID: context.flightRecord.id,
                            baseURL: baseURL,
                            workflow: workflow
                        )
                    } else {
                        serverReceiptID = try await uploadWorkflowEvidenceComponent(
                            component: component,
                            context: context,
                            settings: settings,
                            baseURL: baseURL,
                            workflow: workflow
                        )
                    }
                    await MainActor.run {
                        workflow.updateUploadComponent(
                            id: component.id,
                            state: .serverVerified,
                            progress: 1,
                            lastError: "",
                            serverReceiptID: serverReceiptID
                        )
                    }
                } catch {
                    await MainActor.run {
                        workflow.updateUploadComponent(id: component.id, state: .failed, progress: component.progress ?? 0, lastError: error.localizedDescription)
                    }
                }
            }
        }
    }

    private func scheduleRetryFields(recording: inout Recording, reason: String) {
        let nextCount = (recording.uploadRetryCount ?? 0) + 1
        let delay = min(120, max(30, 30 * (1 << min(nextCount - 1, 2))))
        recording.uploadRetryCount = nextCount
        recording.nextUploadRetryAt = Date().addingTimeInterval(TimeInterval(delay))
        recording.lastError = "\(reason) Retrying in \(delay)s."
    }

    private func uploadWorkflowDispatchComponent(
        component: CVRUploadComponentRecord,
        context: (
            dispatch: CVRDispatchRecord,
            flightRecord: CVRIncompleteFlightRecord,
            consents: [CVRConsentRecord],
            events: [CVRFlightEventRecord],
            verifications: [CVRRecorderVerificationRecord]
        ),
        settings: SettingsStore,
        baseURL: URL,
        workflow: CVRWorkflowStore
    ) async throws -> (receiptID: String, serverDispatchID: String) {
        guard let credential = settings.deviceCredential, !credential.isEmpty else {
            throw APIClientError.badResponse("CVR Unit is not enrolled. Generate an enrollment code in IPCA.training and enter it in Admin.")
        }
        let initialDispatch = context.dispatch
        let initialFlightRecord = context.flightRecord
        guard component.flightRecordID == initialFlightRecord.id,
              initialDispatch.id == initialFlightRecord.dispatchID else {
            throw APIClientError.badResponse("Dispatch upload data is no longer linked to the active Flight Record.")
        }

        _ = workflow.repairDispatchAircraftAlignment(selectedAircraft: settings.selectedAircraft)
        guard let refreshedContext = workflow.workflowUploadContext(componentID: component.id) else {
            throw APIClientError.badResponse("Dispatch upload context is unavailable after repair.")
        }
        let dispatch = refreshedContext.dispatch
        let flightRecord = refreshedContext.flightRecord

        workflow.updateUploadComponent(
            id: component.id,
            state: .uploading,
            progress: 0.25,
            lastError: "Sending Dispatch metadata and consent evidence..."
        )
        let payload = workflowDispatchPayload(
            dispatch: dispatch,
            flightRecord: flightRecord,
            consents: refreshedContext.consents.filter {
                $0.dispatchID == dispatch.id && $0.dispatchVersion == dispatch.version
            },
            settings: settings
        )
        let response = try await APIClient(serverURL: baseURL).syncDispatch(payload: payload, credential: credential)
        guard response.ok,
              let receiptID = response.receipt?.receiptID,
              !receiptID.isEmpty,
              let serverDispatchID = response.dispatch?.id else {
            throw APIClientError.badResponse(response.error ?? "Server did not verify the Dispatch.")
        }
        return (receiptID, String(serverDispatchID))
    }

    private func workflowDispatchPayload(
        dispatch: CVRDispatchRecord,
        flightRecord: CVRIncompleteFlightRecord,
        consents: [CVRConsentRecord],
        settings: SettingsStore
    ) -> [String: Any] {
        let iso = ISO8601DateFormatter()
        iso.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        let day = DateFormatter()
        day.calendar = Calendar(identifier: .gregorian)
        day.locale = Locale(identifier: "en_US_POSIX")
        day.timeZone = TimeZone.current
        day.dateFormat = "yyyy-MM-dd"

        let uploadTail = settings.selectedAircraft?.registration
            .trimmingCharacters(in: .whitespacesAndNewlines)
            .uppercased()
            ?? dispatch.tailNumber.uppercased()
        let uploadAircraftID = settings.selectedAircraft?.id ?? dispatch.aircraftID
        let operationalConfig = settings.selectedAircraft?.operationalConfig ?? .safeDefaults

        var dispatchPayload: [String: Any] = [
            "id": dispatch.id.lowercased(),
            "organization_id": dispatch.organizationID,
            "scheduled_date": day.string(from: dispatch.scheduledDate),
            "tail_number": uploadTail,
            "mission_code": dispatch.missionCode,
            "planned_departure_airport": dispatch.plannedDepartureAirport,
            "planned_destination_airport": dispatch.plannedDestinationAirport,
            "crew": dispatch.crew.map { assignment in
                var member: [String: Any] = [
                    "id": assignment.id,
                    "person_name": assignment.personName,
                    "role": assignment.role.rawValue
                ]
                if let personID = assignment.personID {
                    member["person_id"] = personID
                }
                return member
            },
            "fuel_onboard": dispatch.fuelOnboard,
            "fuel_unit": operationalConfig.fuelUnit,
            "fuel_capacity": operationalConfig.fuelCapacity,
            "dispatch_source": dispatch.dispatchSource,
            "creator_identity": dispatch.creatorIdentity,
            "created_at": iso.string(from: dispatch.createdAt),
            "modified_at": iso.string(from: dispatch.modifiedAt),
            "version": dispatch.version,
            "consent_status": dispatch.consentStatus,
            "status": dispatch.status.rawValue,
            "configured_cvr_unit_id": dispatch.configuredCVRUnitID,
            "configured_beacon_id": dispatch.configuredBeaconID
        ]
        if let aircraftID = uploadAircraftID {
            dispatchPayload["aircraft_id"] = aircraftID
        }
        if let startingHobbs = dispatch.startingHobbs {
            dispatchPayload["starting_hobbs"] = startingHobbs
        }
        if let startingTacho = dispatch.startingTacho {
            dispatchPayload["starting_tacho"] = startingTacho
        }
        if let oilPercentage = dispatch.oilPercentage {
            dispatchPayload["oil_percentage"] = oilPercentage
        }
        if let oilQuantity = dispatch.effectiveStartingOilQuantity {
            dispatchPayload["oil_quantity"] = oilQuantity
            dispatchPayload["oil_unit"] = dispatch.effectiveStartingOilUnit
            if dispatch.effectiveStartingOilUnit == "%" {
                dispatchPayload["oil_percentage"] = Int(oilQuantity.rounded())
            }
        }
        if let value = dispatch.previousFlightRecordID { dispatchPayload["previous_flight_record_id"] = value.lowercased() }
        if let value = dispatch.previousEndingHobbs { dispatchPayload["previous_ending_hobbs"] = value }
        if let value = dispatch.previousEndingTacho { dispatchPayload["previous_ending_tacho"] = value }
        if let value = dispatch.previousFuelRemaining { dispatchPayload["previous_fuel_remaining"] = value }
        if let value = dispatch.previousOilPercentage { dispatchPayload["previous_oil_percentage"] = value }
        if let value = dispatch.effectivePreviousOilQuantity {
            dispatchPayload["previous_ending_oil_quantity"] = value
            dispatchPayload["previous_ending_oil_unit"] = dispatch.previousEndingOilUnit ?? "%"
        }
        if let value = dispatch.refueledSincePreviousFlight { dispatchPayload["refueled_since_previous_flight"] = value }
        if let value = dispatch.oilServicedSincePreviousFlight { dispatchPayload["oil_serviced_since_previous_flight"] = value }
        if let schedulerRecordID = dispatch.schedulerRecordID {
            dispatchPayload["scheduler_record_id"] = schedulerRecordID
        }
        if let scheduledStartTime = dispatch.scheduledStartTime {
            dispatchPayload["scheduled_start_time"] = iso.string(from: scheduledStartTime)
        }
        if let scheduledEndTime = dispatch.scheduledEndTime {
            dispatchPayload["scheduled_end_time"] = iso.string(from: scheduledEndTime)
        }

        return [
            "flight_record_uuid": flightRecord.id.lowercased(),
            "dispatch": dispatchPayload,
            "consents": consents.map { consent in
                var consentPayload: [String: Any] = [
                    "id": consent.id.lowercased(),
                    "person_name": consent.personName,
                    "crew_role": consent.crewRole.rawValue,
                    "consent_result": consent.consentResult,
                    "timestamp": iso.string(from: consent.timestamp),
                    "device_id": consent.deviceID,
                    "dispatch_id": consent.dispatchID.lowercased(),
                    "dispatch_version": consent.dispatchVersion,
                    "consent_text_version": consent.consentTextVersion,
                    "app_version": consent.appVersion
                ]
                if let personID = consent.personID {
                    consentPayload["person_id"] = personID
                }
                return consentPayload
            }
        ]
    }

    private func uploadWorkflowEvidenceComponent(
        component: CVRUploadComponentRecord,
        context: (
            dispatch: CVRDispatchRecord,
            flightRecord: CVRIncompleteFlightRecord,
            consents: [CVRConsentRecord],
            events: [CVRFlightEventRecord],
            verifications: [CVRRecorderVerificationRecord]
        ),
        settings: SettingsStore,
        baseURL: URL,
        workflow: CVRWorkflowStore
    ) async throws -> String {
        guard let credential = settings.deviceCredential, !credential.isEmpty else {
            throw APIClientError.badResponse("CVR Unit is not enrolled. Workflow evidence remains stored locally.")
        }
        workflow.updateUploadComponent(
            id: component.id,
            state: .uploading,
            progress: 0.25,
            lastError: "Sending immutable \(component.componentType.replacingOccurrences(of: "_", with: " ")) evidence..."
        )
        let payload = try workflowEvidencePayload(component: component, context: context, settings: settings)
        let response = try await APIClient(serverURL: baseURL).syncWorkflowEvidence(
            payload: payload,
            credential: credential
        )
        guard response.ok,
              let receiptID = response.receipt?.receiptID,
              !receiptID.isEmpty else {
            throw APIClientError.badResponse(response.error ?? "Server did not verify workflow evidence.")
        }
        return receiptID
    }

    private func workflowEvidencePayload(
        component: CVRUploadComponentRecord,
        context: (
            dispatch: CVRDispatchRecord,
            flightRecord: CVRIncompleteFlightRecord,
            consents: [CVRConsentRecord],
            events: [CVRFlightEventRecord],
            verifications: [CVRRecorderVerificationRecord]
        ),
        settings: SettingsStore
    ) throws -> [String: Any] {
        let iso = ISO8601DateFormatter()
        iso.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        let evidence: [String: Any]

        switch component.componentType {
        case "flight_events":
            let eventID = component.localFilePath.map { String($0.dropFirst("event:".count)) }
            guard let event = context.events.first(where: { $0.id == eventID }) else {
                throw APIClientError.badResponse("The queued Flight Event is missing from local history.")
            }
            var item: [String: Any] = [
                "event_uuid": event.id.lowercased(),
                "event_type": event.eventType,
                "timestamp_utc": iso.string(from: event.timestampUTC),
                "timestamp_local": iso.string(from: event.timestampLocal),
                "source": event.source,
                "confidence": event.confidence,
                "creation_method": event.creationMethod
            ]
            if let value = event.recordingSessionID { item["recording_session_id"] = value }
            if let value = event.deviceMonotonicTime { item["device_monotonic_time"] = value }
            if let value = event.audioOffset { item["audio_offset"] = value }
            if let value = event.latitude { item["latitude"] = value }
            if let value = event.longitude { item["longitude"] = value }
            if let value = event.altitude { item["altitude"] = value }
            if let value = event.groundSpeed { item["ground_speed"] = value }
            if let value = event.userIdentity { item["user_identity"] = value }
            if let metadata = event.metadata, !metadata.isEmpty { item["metadata"] = metadata }
            evidence = item
        case "recorder_verification":
            let verificationID = component.localFilePath.map { String($0.dropFirst("verification:".count)) }
            guard let verification = context.verifications.first(where: { $0.id == verificationID }) else {
                throw APIClientError.badResponse("The queued Recorder Verification is missing from local history.")
            }
            evidence = [
                "verification_uuid": verification.id.lowercased(),
                "timestamp": iso.string(from: verification.timestamp),
                "device_id": verification.deviceID,
                "app_version": verification.appVersion,
                "user_identity": verification.userIdentity,
                "audio_route_status": verification.audioRouteStatus,
                "beacon_status": verification.beaconStatus,
                "gps_status": verification.gpsStatus,
                "storage_status": verification.storageStatus,
                "thermal_status": verification.thermalStatus,
                "battery_status": verification.batteryStatus,
                "permission_status": verification.permissionStatus,
                "file_writing_test_result": verification.fileWritingTestResult,
                "warnings": verification.warnings,
                "accepted_nonblocking_warnings": verification.acceptedNonblockingWarnings
            ]
        case "flight_record_closure":
            let flight = context.flightRecord
            var item: [String: Any] = [
                "closure_uuid": component.id.lowercased(),
                "status": flight.status.rawValue,
                "updated_at": iso.string(from: flight.updatedAt)
            ]
            if let value = flight.endingHobbs { item["ending_hobbs"] = value }
            if let value = flight.endingTacho { item["ending_tacho"] = value }
            if let value = flight.fuelRemaining { item["fuel_remaining"] = value }
            item["fuel_unit"] = settings.selectedAircraft?.operationalConfig.fuelUnit ?? AircraftOperationalConfig.safeDefaults.fuelUnit
            if let value = flight.effectiveEndingOilQuantity {
                item["ending_oil_quantity"] = value
                item["ending_oil_unit"] = flight.effectiveEndingOilUnit
                if flight.effectiveEndingOilUnit == "%" {
                    item["ending_oil_percentage"] = Int(value.rounded())
                }
            }
            if let value = flight.verifiedTakeoffCount { item["verified_takeoff_count"] = value }
            if let value = flight.verifiedLandingCount { item["verified_landing_count"] = value }
            if let value = flight.autoDetectedTakeoffCount { item["auto_detected_takeoff_count"] = value }
            if let value = flight.autoDetectedLandingCount { item["auto_detected_landing_count"] = value }
            if let value = flight.maintenanceRemark { item["maintenance_remark"] = value }
            evidence = item
        default:
            throw APIClientError.badResponse("Unsupported workflow evidence component.")
        }

        return [
            "schema_version": 1,
            "component_uuid": component.id.lowercased(),
            "component_type": component.componentType,
            "flight_record_uuid": context.flightRecord.id.lowercased(),
            "dispatch_uuid": context.dispatch.id.lowercased(),
            "evidence": evidence
        ]
    }

    func uploadGarminCSVAttachment(
        fileURL: URL,
        originalFilename: String,
        flightRecordID: String,
        settings: SettingsStore,
        progress: @escaping (Double) -> Void
    ) async throws {
        guard let baseURL = settings.normalizedServerURL else {
            throw APIClientError.invalidServerURL
        }
        guard let credential = settings.deviceCredential, !credential.isEmpty else {
            throw APIClientError.badResponse("CVR Unit is not enrolled.")
        }
        _ = credential
        _ = try await uploadGarminFile(
            fileURL: fileURL,
            originalFilename: originalFilename,
            flightRecordID: flightRecordID,
            baseURL: baseURL
        ) { value, _ in
            progress(value)
        }
    }

    private func uploadWorkflowGarminComponent(component: CVRUploadComponentRecord, flightRecordID: String, baseURL: URL, workflow: CVRWorkflowStore) async throws -> String {
        let fileURL = try workflowComponentFileURL(component)
        return try await uploadGarminFile(
            fileURL: fileURL,
            originalFilename: fileURL.lastPathComponent,
            flightRecordID: flightRecordID,
            baseURL: baseURL
        ) { value, message in
            let uploadState: CVRUploadComponentState = value >= 0.98 ? .uploaded : .uploading
            workflow.updateUploadComponent(
                id: component.id,
                state: uploadState,
                progress: value,
                lastError: message
            )
        }
    }

    private func uploadGarminFile(
        fileURL: URL,
        originalFilename: String,
        flightRecordID: String,
        baseURL: URL,
        progress: @escaping (Double, String) -> Void
    ) async throws -> String {
        let fileSize = try fileSize(fileURL)
        let totalChunks = max(1, Int(ceil(Double(fileSize) / Double(chunkSize))))

        for chunkIndex in 0..<totalChunks {
            let offset = Int64(chunkIndex * chunkSize)
            let count = min(chunkSize, Int(fileSize - offset))
            let chunkData = try readChunk(fileURL: fileURL, offset: offset, count: count, chunkIndex: chunkIndex)
            let request = workflowChunkUploadRequest(
                baseURL: baseURL,
                recordingID: flightRecordID,
                fileType: "g3x",
                chunkIndex: chunkIndex,
                totalChunks: totalChunks,
                totalSize: fileSize,
                chunkSize: count,
                originalFilename: originalFilename,
                mimeType: "text/csv"
            )

            progress(
                max(0.01, Double(chunkIndex) / Double(totalChunks) * 0.95),
                "Uploading CSV chunk \(chunkIndex + 1)/\(totalChunks)..."
            )

            var lastError: Error?
            for attempt in 1...maxChunkAttempts {
                do {
                    let (data, response) = try await send(request: request, body: chunkData)
                    let decoded = try APIClient(serverURL: baseURL).decodeChunkUploadResponse(data: data, response: response)
                    if !decoded.ok {
                        throw APIClientError.badResponse(decoded.error ?? "Chunk upload failed.")
                    }
                    lastError = nil
                    break
                } catch {
                    lastError = error
                    if attempt < maxChunkAttempts {
                        try await Task.sleep(nanoseconds: UInt64(min(30, attempt * attempt * 2)) * 1_000_000_000)
                    }
                }
            }
            if let lastError {
                throw lastError
            }

            progress(
                min(0.95, Double(chunkIndex + 1) / Double(totalChunks) * 0.95),
                "Uploaded CSV chunk \(chunkIndex + 1)/\(totalChunks)"
            )
        }

        progress(0.98, "Finalizing Garmin CSV on server...")

        var finalize = URLRequest(url: baseURL.appending(path: "api/recordings/g3x_finalize.php"))
        finalize.httpMethod = "POST"
        finalize.timeoutInterval = 3600
        finalize.setValue("application/json", forHTTPHeaderField: "Content-Type")
        finalize.httpBody = try JSONSerialization.data(withJSONObject: [
            "recording_id": flightRecordID,
            "import_profile": "cvr_workflow_garmin_share"
        ])
        let (data, response) = try await data(for: finalize)
        guard let http = response as? HTTPURLResponse, (200..<300).contains(http.statusCode) else {
            throw APIClientError.badResponse("Server finalize failed.")
        }
        let decoded = try JSONSerialization.jsonObject(with: data) as? [String: Any]
        if let ok = decoded?["ok"] as? Bool, ok == false {
            throw APIClientError.badResponse((decoded?["error"] as? String) ?? "Server rejected Garmin CSV.")
        }
        guard let receipt = decoded?["receipt"] as? [String: Any],
              let receiptID = receipt["receipt_id"] as? String,
              !receiptID.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty else {
            throw APIClientError.badResponse("Server did not return a Garmin CSV verification receipt.")
        }
        return receiptID
    }

    private func workflowChunkUploadRequest(
        baseURL: URL,
        recordingID: String,
        fileType: String,
        chunkIndex: Int,
        totalChunks: Int,
        totalSize: Int64,
        chunkSize: Int,
        originalFilename: String,
        mimeType: String
    ) -> URLRequest {
        let url = baseURL.appending(path: "api/recordings/upload_chunk.php")
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.timeoutInterval = 120
        request.setValue("application/octet-stream", forHTTPHeaderField: "Content-Type")
        request.setValue(recordingID, forHTTPHeaderField: "X-IPCA-Recording-ID")
        request.setValue(fileType, forHTTPHeaderField: "X-IPCA-File-Type")
        request.setValue(String(chunkIndex), forHTTPHeaderField: "X-IPCA-Chunk-Index")
        request.setValue(String(totalChunks), forHTTPHeaderField: "X-IPCA-Total-Chunks")
        request.setValue(String(totalSize), forHTTPHeaderField: "X-IPCA-Total-Size")
        request.setValue(String(chunkSize), forHTTPHeaderField: "X-IPCA-Chunk-Size")
        request.setValue(originalFilename, forHTTPHeaderField: "X-IPCA-Original-Filename")
        request.setValue(mimeType, forHTTPHeaderField: "X-IPCA-Mime-Type")
        return request
    }

    private func workflowComponentFileURL(_ component: CVRUploadComponentRecord) throws -> URL {
        guard let localFilePath = component.localFilePath?.trimmingCharacters(in: .whitespacesAndNewlines),
              !localFilePath.isEmpty else {
            throw APIClientError.badResponse("Garmin CSV local file reference is missing. Share the CSV to the app again.")
        }

        let fileManager = FileManager.default
        if localFilePath.hasPrefix("/") {
            let absoluteURL = URL(fileURLWithPath: localFilePath)
            if fileManager.fileExists(atPath: absoluteURL.path) {
                return absoluteURL
            }
            if let fallback = try? workflowGarminImportDirectory().appendingPathComponent(absoluteURL.lastPathComponent),
               fileManager.fileExists(atPath: fallback.path) {
                return fallback
            }
            throw APIClientError.badResponse("Garmin CSV is no longer in local storage. Share the CSV to the app again.")
        }

        let relative = localFilePath.replacingOccurrences(of: "GarminImports/", with: "")
        let url = try workflowGarminImportDirectory().appendingPathComponent(relative)
        guard fileManager.fileExists(atPath: url.path) else {
            throw APIClientError.badResponse("Garmin CSV is no longer in local storage. Share the CSV to the app again.")
        }
        return url
    }

    private func workflowGarminImportDirectory() throws -> URL {
        let base = try FileManager.default.url(
            for: .applicationSupportDirectory,
            in: .userDomainMask,
            appropriateFor: nil,
            create: true
        )
        return base.appendingPathComponent("IPCACVRUnit/GarminImports", isDirectory: true)
    }

    private func scheduleRetry(recordingID: String, store: RecordingStore, settings: SettingsStore) {
        retryTasks[recordingID]?.cancel()
        guard let recording = store.recording(id: recordingID),
              recording.uploadStatus == .failed,
              let nextUploadRetryAt = recording.nextUploadRetryAt else { return }
        let delay = max(1, nextUploadRetryAt.timeIntervalSinceNow)
        retryTasks[recordingID] = Task { [weak self, weak store, weak settings] in
            try? await Task.sleep(nanoseconds: UInt64(delay * 1_000_000_000))
            await MainActor.run {
                guard let self, let store, let settings else { return }
                self.retryTasks[recordingID] = nil
                self.upload(recordingID: recordingID, store: store, settings: settings)
            }
        }
    }

    private func performUpload(recordingID: String, store: RecordingStore, settings: SettingsStore) async throws {
        guard let baseURL = settings.normalizedServerURL else {
            throw APIClientError.invalidServerURL
        }
        guard let recording = store.recording(id: recordingID) else { return }

        let client = APIClient(serverURL: baseURL)
        let uploadResponse = try await performChunkedUpload(recording: recording, language: settings.language, client: client, store: store)
        if !uploadResponse.ok {
            throw APIClientError.badResponse(uploadResponse.error ?? "Upload failed.")
        }

        let serverRecordingID = uploadResponse.recording?.recordingID ?? recordingID
        await MainActor.run {
            store.update(recordingID) {
                $0.serverID = serverRecordingID
                $0.uploadStatus = .uploaded
                $0.uploadProgress = 1
                $0.transcriptStatus = .transcribing
                $0.transcriptProgress = uploadResponse.recording?.progress ?? 0
                $0.uploadRetryCount = nil
                $0.nextUploadRetryAt = nil
                $0.lastError = ""
            }
        }

        do {
            try await pollTranscript(recordingID: recordingID, serverRecordingID: serverRecordingID, store: store, client: client)
        } catch {
            await MainActor.run {
                store.update(recordingID) {
                    $0.uploadStatus = .uploaded
                    $0.uploadProgress = 1
                    $0.transcriptStatus = .failed
                    $0.lastError = "Upload complete. Transcript follow-up failed: \(error.localizedDescription)"
                    $0.uploadRetryCount = nil
                    $0.nextUploadRetryAt = nil
                }
            }
        }
    }

    private func performChunkedUpload(recording: Recording, language: String, client: APIClient, store: RecordingStore) async throws -> UploadResponse {
        let audioURL = try RecordingStore.resolvedFileURL(
            preferredPath: recording.filePath,
            recordingID: recording.id,
            fallbackFilename: "\(recording.id).m4a"
        )
        var files: [(type: String, url: URL, filename: String, mime: String, size: Int64)] = [
            ("audio", audioURL, audioURL.lastPathComponent, mimeType(for: audioURL), try fileSize(audioURL))
        ]

        if let gpsPath = recording.gpsSamplesPath {
            if let url = try? RecordingStore.resolvedFileURL(
                preferredPath: gpsPath,
                recordingID: recording.id,
                fallbackFilename: "\(recording.id).gps.json"
            ) {
                files.append(("gps", url, url.lastPathComponent, "application/json", try fileSize(url)))
            }
        }

        if let beaconPath = recording.beaconDiagnosticsPath {
            if let url = try? RecordingStore.resolvedFileURL(
                preferredPath: beaconPath,
                recordingID: recording.id,
                fallbackFilename: "\(recording.id).beacon.json"
            ) {
                files.append(("beacon", url, url.lastPathComponent, "application/json", try fileSize(url)))
            }
        }

        if let eventsPath = recording.recordingEventsPath {
            if let url = try? RecordingStore.resolvedFileURL(
                preferredPath: eventsPath,
                recordingID: recording.id,
                fallbackFilename: "\(recording.id).events.json"
            ) {
                files.append(("events", url, url.lastPathComponent, "application/json", try fileSize(url)))
            }
        }

        let totalBytes = max(1, files.reduce(Int64(0)) { $0 + max(0, $1.size) })
        var uploadedBytes: Int64 = 0

        await MainActor.run {
            store.update(recording.id) {
                $0.uploadProgress = 0.01
                $0.lastError = "Preparing chunked upload..."
            }
        }

        for file in files where file.size > 0 {
            uploadedBytes = try await uploadFileChunks(
                recording: recording,
                client: client,
                fileType: file.type,
                fileURL: file.url,
                originalFilename: file.filename,
                mimeType: file.mime,
                fileSize: file.size,
                uploadedBytes: uploadedBytes,
                totalBytes: totalBytes,
                store: store
            )
        }

        await MainActor.run {
            store.update(recording.id) {
                $0.uploadProgress = 0.99
                $0.lastError = "Finalizing audio package..."
            }
        }

        let finalizeRequest = try client.finalizeChunkedUploadRequest(for: recording, language: language)
        let (data, response) = try await data(for: finalizeRequest)
        return try client.decodeUploadResponse(data: data, response: response)
    }

    private func uploadFileChunks(
        recording: Recording,
        client: APIClient,
        fileType: String,
        fileURL: URL,
        originalFilename: String,
        mimeType: String,
        fileSize: Int64,
        uploadedBytes: Int64,
        totalBytes: Int64,
        store: RecordingStore
    ) async throws -> Int64 {
        let totalChunks = Int(ceil(Double(fileSize) / Double(chunkSize)))
        var completedBytes = uploadedBytes
        var receivedChunks = Set<Int>()

        do {
            let status = try await client.chunkUploadStatus(recordingID: recording.id, fileType: fileType)
            if status.ok, let chunks = status.receivedChunks {
                receivedChunks = Set(chunks)
            }
        } catch {
            // Resume is optional; continue from the beginning if status is unavailable.
        }

        let startIndex = firstMissingChunkIndex(received: receivedChunks, totalChunks: totalChunks)
        if startIndex >= totalChunks {
            return uploadedBytes + fileSize
        }

        if startIndex > 0 {
            let bytesAlreadyUploadedForFile = Int64(min(Int64(startIndex) * Int64(chunkSize), fileSize))
            completedBytes = uploadedBytes + bytesAlreadyUploadedForFile
            await MainActor.run {
                store.update(recording.id) {
                    $0.uploadProgress = min(0.98, Double(completedBytes) / Double(totalBytes) * 0.98)
                    $0.lastError = "Resuming \(fileType) upload at chunk \(startIndex + 1)/\(totalChunks)..."
                }
            }
        }

        for chunkIndex in startIndex..<totalChunks {
            let offset = Int64(chunkIndex * chunkSize)
            let count = min(chunkSize, Int(fileSize - offset))
            let chunkData = try readChunk(fileURL: fileURL, offset: offset, count: count, chunkIndex: chunkIndex)

            let request = client.chunkUploadRequest(
                recording: recording,
                fileType: fileType,
                chunkIndex: chunkIndex,
                totalChunks: totalChunks,
                totalSize: fileSize,
                chunkSize: count,
                originalFilename: originalFilename,
                mimeType: mimeType
            )

            await MainActor.run {
                store.update(recording.id) {
                    let baseProgress = Double(completedBytes) / Double(totalBytes) * 0.98
                    $0.uploadProgress = max(0.01, min(0.98, baseProgress))
                    $0.lastError = "Uploading \(fileType) chunk \(chunkIndex + 1)/\(totalChunks)..."
                }
            }

            var lastError: Error?
            for attempt in 1...maxChunkAttempts {
                do {
                    let (data, response) = try await send(request: request, body: chunkData)
                    let chunkResponse = try client.decodeChunkUploadResponse(data: data, response: response)
                    if !chunkResponse.ok {
                        throw APIClientError.badResponse(chunkResponse.error ?? "Chunk upload failed.")
                    }
                    lastError = nil
                    break
                } catch {
                    lastError = error
                    if attempt < maxChunkAttempts {
                        let delayNs = UInt64(min(30, attempt * attempt * 2)) * 1_000_000_000
                        await MainActor.run {
                            store.update(recording.id) {
                                $0.lastError = "Retrying \(fileType) chunk \(chunkIndex + 1)/\(totalChunks) (attempt \(attempt + 1)/\(maxChunkAttempts)): \(error.localizedDescription)"
                            }
                        }
                        try await Task.sleep(nanoseconds: delayNs)
                    }
                }
            }

            if let lastError {
                if (fileType == "beacon" || fileType == "events"), isUnsupportedOptionalSidecar(error: lastError) {
                    await MainActor.run {
                        store.update(recording.id) {
                            $0.lastError = "Server does not accept \(fileType) diagnostics yet. Continuing without \(fileType).json."
                        }
                    }
                    return uploadedBytes
                }
                throw APIClientError.badResponse(
                    "Failed \(fileType) chunk \(chunkIndex + 1)/\(totalChunks): \(lastError.localizedDescription)"
                )
            }

            completedBytes += Int64(count)
            await MainActor.run {
                store.update(recording.id) {
                    $0.uploadProgress = min(0.98, Double(completedBytes) / Double(totalBytes) * 0.98)
                    $0.lastError = "Uploaded \(fileType) chunk \(chunkIndex + 1)/\(totalChunks)"
                }
            }
        }

        return completedBytes
    }

    private func firstMissingChunkIndex(received: Set<Int>, totalChunks: Int) -> Int {
        guard !received.isEmpty else { return 0 }
        for index in 0..<totalChunks where !received.contains(index) {
            return index
        }
        return totalChunks
    }

    private func isUnsupportedOptionalSidecar(error: Error) -> Bool {
        let message = error.localizedDescription.lowercased()
        return message.contains("invalid file type") || message.contains("unsupported file type")
    }

    private func send(request: URLRequest, body: Data) async throws -> (Data, URLResponse) {
        let tempURL = FileManager.default.temporaryDirectory
            .appendingPathComponent("ipca-cvr-upload-chunk-\(UUID().uuidString).bin")
        try body.write(to: tempURL, options: .atomic)
        defer { try? FileManager.default.removeItem(at: tempURL) }

        return try await withCheckedThrowingContinuation { continuation in
            var request = request
            request.httpBody = nil
            let task = session.uploadTask(with: request, fromFile: tempURL) { data, response, error in
                if let error {
                    continuation.resume(throwing: error)
                    return
                }
                guard let data, let response else {
                    continuation.resume(throwing: APIClientError.badResponse("Upload returned no response."))
                    return
                }
                continuation.resume(returning: (data, response))
            }

            task.resume()
        }
    }

    private func data(for request: URLRequest) async throws -> (Data, URLResponse) {
        try await withCheckedThrowingContinuation { continuation in
            let task = session.dataTask(with: request) { data, response, error in
                if let error {
                    continuation.resume(throwing: error)
                    return
                }
                guard let data, let response else {
                    continuation.resume(throwing: APIClientError.badResponse("Server returned no response."))
                    return
                }
                continuation.resume(returning: (data, response))
            }
            task.resume()
        }
    }

    private func readChunk(fileURL: URL, offset: Int64, count: Int, chunkIndex: Int) throws -> Data {
        let handle = try FileHandle(forReadingFrom: fileURL)
        defer { try? handle.close() }
        try handle.seek(toOffset: UInt64(offset))
        let data = try handle.read(upToCount: count) ?? Data()
        if data.count != count {
            throw APIClientError.badResponse(
                "Could not read chunk \(chunkIndex + 1). Expected \(count) bytes, got \(data.count)."
            )
        }
        return data
    }

    private func fileSize(_ url: URL) throws -> Int64 {
        let values = try url.resourceValues(forKeys: [.fileSizeKey, .isUbiquitousItemKey, .ubiquitousItemDownloadingStatusKey])
        if values.isUbiquitousItem == true,
           values.ubiquitousItemDownloadingStatus != URLUbiquitousItemDownloadingStatus.current {
            throw APIClientError.badResponse("Recording file is still downloading from iCloud.")
        }
        return Int64(values.fileSize ?? 0)
    }

    private func mimeType(for url: URL) -> String {
        switch url.pathExtension.lowercased() {
        case "wav":
            return "audio/wav"
        case "mp3":
            return "audio/mpeg"
        case "aac":
            return "audio/aac"
        case "caf":
            return "audio/x-caf"
        default:
            return "audio/mp4"
        }
    }

    private func pollTranscript(recordingID: String, serverRecordingID: String, store: RecordingStore, client: APIClient) async throws {
        for _ in 0..<180 {
            try await Task.sleep(nanoseconds: 2_000_000_000)
            let status = try await client.status(recordingID: serverRecordingID)
            if let remote = status.recording {
                await MainActor.run {
                    store.update(recordingID) {
                        $0.transcriptProgress = remote.progress
                        $0.replayStatus = remote.reconstructionStatus
                        $0.replayProgress = remote.reconstructionProgress
                        $0.replayStage = remote.reconstructionStage
                        $0.lastError = remote.error
                        if remote.transcriptionStatus == "ready" {
                            $0.transcriptStatus = .ready
                        } else if remote.transcriptionStatus == "failed" {
                            $0.transcriptStatus = .failed
                        } else {
                            $0.transcriptStatus = .transcribing
                        }
                    }
                }

                if remote.transcriptionStatus == "ready" {
                    let transcript = try await client.transcript(recordingID: serverRecordingID)
                    await MainActor.run {
                        store.update(recordingID) {
                            $0.transcriptStatus = .ready
                            $0.transcript = transcript.transcript ?? ""
                        }
                    }
                    return
                }

                if remote.transcriptionStatus == "failed" {
                    throw APIClientError.badResponse(remote.error.isEmpty ? "Transcription failed." : remote.error)
                }
            }
        }

        throw APIClientError.badResponse("Timed out waiting for transcript.")
    }
}
