import CryptoKit
import Foundation

@MainActor
final class CVRWorkflowStore: ObservableObject {
    @Published private(set) var state: CVRWorkflowState = .empty
    @Published private(set) var lastError = ""

    private let encoder: JSONEncoder
    private let decoder: JSONDecoder

    init() {
        encoder = JSONEncoder()
        encoder.outputFormatting = [.prettyPrinted, .sortedKeys]
        encoder.dateEncodingStrategy = .iso8601

        decoder = JSONDecoder()
        decoder.dateDecodingStrategy = .iso8601
    }

    func load() async {
        do {
            let url = try storeURL()
            guard FileManager.default.fileExists(atPath: url.path) else { return }
            let data = try Data(contentsOf: url)
            state = try decoder.decode(CVRWorkflowState.self, from: data)
            lastError = ""
        } catch {
            lastError = "Workflow recovery failed: \(error.localizedDescription)"
        }
    }

    func selectTab(_ tab: CVROperationalTab) {
        mutate {
            $0.selectedTab = tab
        }
    }

    func createOrOpenLocalDispatch(selectedAircraft: CockpitAircraft?, cvrUnitID: String, beaconID: String) {
        guard let selectedAircraft else {
            lastError = "Aircraft configuration is required before creating a Dispatch."
            return
        }
        let registration = selectedAircraft.registration
        if let existing = state.activeDispatch,
           existing.tailNumber.caseInsensitiveCompare(registration) == .orderedSame,
           Calendar.current.isDate(existing.scheduledDate, inSameDayAs: Date()) {
            mutate {
                $0.selectedTab = .dispatch
            }
            return
        }

        let dispatch = CVRDispatchRecord(
            id: UUID().uuidString,
            serverDispatchID: nil,
            organizationID: 1,
            scheduledDate: Date(),
            scheduledStartTime: nil,
            scheduledEndTime: nil,
            tailNumber: registration,
            aircraftID: selectedAircraft.id,
            missionCode: "",
            plannedDepartureAirport: selectedAircraft.homeAirport,
            plannedDestinationAirport: "",
            crew: [],
            startingHobbs: nil,
            startingTacho: nil,
            fuelOnboard: "",
            oilPercentage: nil,
            dispatchSource: "iphone_offline_local",
            schedulerRecordID: nil,
            creatorIdentity: "local_cvr_unit",
            createdAt: Date(),
            modifiedAt: Date(),
            version: 1,
            consentStatus: "not_required_yet",
            status: .dispatchIncomplete,
            configuredCVRUnitID: cvrUnitID,
            configuredBeaconID: beaconID
        )

        mutate {
            $0.activeDispatch = dispatch
            $0.activeFlightRecord = nil
            $0.consents = []
            $0.recorderVerifications = []
            $0.flightEvents = []
            $0.flightLegs = []
            $0.uploadComponents = []
            $0.discrepancies = []
            $0.selectedTab = .dispatch
        }
    }

    func updateActiveDispatch(_ update: (inout CVRDispatchRecord) -> Void) {
        if isDispatchLocked {
            lastError = "Dispatch is locked after confirmation."
            return
        }

        mutate {
            guard var dispatch = $0.activeDispatch else { return }
            let previousMaterialSignature = Self.materialSignature(dispatch)
            let previousStatus = dispatch.status
            update(&dispatch)
            dispatch.version += 1
            dispatch.modifiedAt = Date()
            let materialChanged = previousMaterialSignature != Self.materialSignature(dispatch)
            dispatch.status = Self.dispatchStatus(for: dispatch, consents: $0.consents)
            if materialChanged {
                $0.consents = []
                dispatch.consentStatus = "invalidated_by_dispatch_change"
                if $0.activeFlightRecord?.status == .recorderVerificationRequired {
                    $0.activeFlightRecord = nil
                }
            } else if previousStatus == .flightRecordLoggingEnabled,
                      $0.activeFlightRecord?.dispatchID == dispatch.id,
                      dispatch.status == .readyForVerification {
                dispatch.status = .flightRecordLoggingEnabled
            }
            $0.activeDispatch = dispatch
        }
    }

    func recordConsent(for assignment: CVRCrewAssignment, accepted: Bool, appVersion: String, deviceID: String) {
        guard let dispatch = state.activeDispatch else { return }
        let consent = CVRConsentRecord(
            id: UUID().uuidString,
            personID: assignment.personID,
            personName: assignment.personName,
            crewRole: assignment.role,
            consentResult: accepted,
            timestamp: Date(),
            deviceID: deviceID,
            dispatchID: dispatch.id,
            dispatchVersion: dispatch.version,
            consentTextVersion: "cvr-recording-safety-training-v1",
            appVersion: appVersion
        )

        mutate {
            $0.consents.removeAll { $0.dispatchID == dispatch.id && $0.personName == assignment.personName && $0.crewRole == assignment.role }
            $0.consents.append(consent)
            if var activeDispatch = $0.activeDispatch {
                activeDispatch.consentStatus = Self.hasRequiredConsents(dispatch: activeDispatch, consents: $0.consents) ? "complete" : "required"
                activeDispatch.status = Self.dispatchStatus(for: activeDispatch, consents: $0.consents)
                $0.activeDispatch = activeDispatch
            }
        }
    }

    func verifyDispatchAndCreateFlightRecord() {
        guard var dispatch = state.activeDispatch else { return }
        let status = Self.dispatchStatus(for: dispatch, consents: state.consents)
        guard status == .readyForVerification || status == .dispatchVerified || status == .flightRecordLoggingEnabled else {
            mutate {
                dispatch.status = status
                $0.activeDispatch = dispatch
            }
            return
        }

        let flightRecord = state.activeFlightRecord ?? CVRIncompleteFlightRecord(
            id: UUID().uuidString,
            serverFlightRecordID: nil,
            dispatchID: dispatch.id,
            recordingSessionID: nil,
            status: .recorderVerificationRequired,
            endingHobbs: nil,
            endingTacho: nil,
            fuelRemaining: nil,
            endingOilPercentage: nil,
            maintenanceRemark: nil,
            createdAt: Date(),
            updatedAt: Date()
        )

        mutate {
            dispatch.status = .flightRecordLoggingEnabled
            dispatch.consentStatus = "complete"
            dispatch.modifiedAt = Date()
            $0.activeDispatch = dispatch
            $0.activeFlightRecord = flightRecord
            $0.selectedTab = .recorder
        }
    }

    func recordRecorderVerification(
        audioRouteStatus: String,
        beaconStatus: String,
        gpsStatus: String,
        storageStatus: String,
        thermalStatus: String,
        batteryStatus: String,
        permissionStatus: String,
        fileWritingTestResult: String,
        warnings: [String],
        acceptedWarnings: [String],
        appVersion: String,
        deviceID: String
    ) {
        guard let dispatch = state.activeDispatch,
              var flightRecord = state.activeFlightRecord else { return }
        let verification = CVRRecorderVerificationRecord(
            id: UUID().uuidString,
            dispatchID: dispatch.id,
            flightRecordID: flightRecord.id,
            deviceID: deviceID,
            appVersion: appVersion,
            timestamp: Date(),
            userIdentity: "local_cvr_unit",
            audioRouteStatus: audioRouteStatus,
            beaconStatus: beaconStatus,
            gpsStatus: gpsStatus,
            storageStatus: storageStatus,
            thermalStatus: thermalStatus,
            batteryStatus: batteryStatus,
            permissionStatus: permissionStatus,
            fileWritingTestResult: fileWritingTestResult,
            warnings: warnings,
            acceptedNonblockingWarnings: acceptedWarnings
        )

        mutate {
            $0.recorderVerifications.removeAll { $0.flightRecordID == flightRecord.id }
            $0.recorderVerifications.append(verification)
            flightRecord.status = .standingByForAvionics
            flightRecord.updatedAt = Date()
            $0.activeFlightRecord = flightRecord
            $0.selectedTab = .inFlight
        }
    }

    func recordEngineStartOffBlock(gpsSample: GPSSample?) {
        guard var flightRecord = state.activeFlightRecord else { return }
        guard !state.flightEvents.contains(where: { $0.flightRecordID == flightRecord.id && $0.eventType == "engine_start_off_block" }) else {
            return
        }

        let now = Date()
        let event = CVRFlightEventRecord(
            id: UUID().uuidString,
            flightRecordID: flightRecord.id,
            recordingSessionID: flightRecord.recordingSessionID,
            eventType: "engine_start_off_block",
            timestampUTC: now,
            timestampLocal: now,
            deviceMonotonicTime: ProcessInfo.processInfo.systemUptime,
            audioOffset: nil,
            latitude: gpsSample?.latitude,
            longitude: gpsSample?.longitude,
            altitude: gpsSample?.altitude,
            groundSpeed: gpsSample?.speedKnots,
            source: "manual_engine_start_hold",
            confidence: 1.0,
            creationMethod: "three_second_hold",
            userIdentity: "local_cvr_unit"
        )

        mutate {
            flightRecord.status = .recording
            flightRecord.updatedAt = now
            $0.activeFlightRecord = flightRecord
            $0.flightEvents.append(event)
        }
    }

    func recordInFlightAction(eventType: String, creationMethod: String, gpsSample: GPSSample?) {
        guard let flightRecord = state.activeFlightRecord else { return }
        appendFlightEvent(
            flightRecord: flightRecord,
            eventType: eventType,
            source: "manual_in_flight_action",
            creationMethod: creationMethod,
            gpsSample: gpsSample
        )
    }

    func recordEngineShutdownOnBlock(gpsSample: GPSSample?) {
        guard var flightRecord = state.activeFlightRecord else { return }
        guard state.flightEvents.contains(where: { $0.flightRecordID == flightRecord.id && $0.eventType == "engine_start_off_block" }) else {
            return
        }
        guard !state.flightEvents.contains(where: { $0.flightRecordID == flightRecord.id && $0.eventType == "engine_shutdown_on_block" }) else {
            return
        }

        let event = makeFlightEvent(
            flightRecord: flightRecord,
            eventType: "engine_shutdown_on_block",
            source: "manual_engine_shutdown_hold",
            creationMethod: "three_second_hold",
            gpsSample: gpsSample
        )

        mutate {
            flightRecord.status = .shutdownVerificationRequired
            flightRecord.updatedAt = event.timestampUTC
            $0.activeFlightRecord = flightRecord
            $0.flightEvents.append(event)
        }
    }

    func recordShutdownVerification(
        endingHobbs: Double?,
        endingTacho: Double?,
        fuelRemaining: String,
        oilPercentage: Int?,
        maintenanceRemark: String,
        gpsSample: GPSSample?
    ) {
        guard var flightRecord = state.activeFlightRecord else { return }
        guard state.flightEvents.contains(where: { $0.flightRecordID == flightRecord.id && $0.eventType == "engine_shutdown_on_block" }) else {
            return
        }

        let event = makeFlightEvent(
            flightRecord: flightRecord,
            eventType: "shutdown_verification_completed",
            source: "manual_shutdown_verification",
            creationMethod: "post_flight_form",
            gpsSample: gpsSample
        )

        mutate {
            flightRecord.endingHobbs = endingHobbs
            flightRecord.endingTacho = endingTacho
            flightRecord.fuelRemaining = fuelRemaining.trimmingCharacters(in: .whitespacesAndNewlines)
            flightRecord.endingOilPercentage = oilPercentage
            flightRecord.maintenanceRemark = maintenanceRemark.trimmingCharacters(in: .whitespacesAndNewlines)
            flightRecord.status = .awaitingGarmin
            flightRecord.updatedAt = event.timestampUTC
            $0.activeFlightRecord = flightRecord
            $0.flightEvents.removeAll { $0.flightRecordID == flightRecord.id && $0.eventType == "shutdown_verification_completed" }
            $0.flightEvents.append(event)
            $0.selectedTab = .garmin
        }
    }

    func importGarminCSV(from sourceURL: URL) {
        guard var flightRecord = state.activeFlightRecord else {
            lastError = "Create or recover a Flight Record before importing Garmin CSV."
            return
        }

        let accessed = sourceURL.startAccessingSecurityScopedResource()
        defer {
            if accessed {
                sourceURL.stopAccessingSecurityScopedResource()
            }
        }

        do {
            guard sourceURL.pathExtension.caseInsensitiveCompare("csv") == .orderedSame else {
                lastError = "Garmin import expects a CSV file."
                return
            }

            let data = try Data(contentsOf: sourceURL)
            let directory = try garminImportDirectory()
            let timestamp = Self.fileTimestampFormatter.string(from: Date())
            let cleanName = sourceURL.deletingPathExtension().lastPathComponent
                .replacingOccurrences(of: "/", with: "-")
                .replacingOccurrences(of: ":", with: "-")
            let destination = directory.appendingPathComponent("\(flightRecord.id)-\(timestamp)-\(cleanName).csv")
            try data.write(to: destination, options: [.atomic])

            let digest = SHA256.hash(data: data).map { String(format: "%02x", $0) }.joined()
            let component = CVRUploadComponentRecord(
                id: UUID().uuidString,
                serverID: nil,
                flightRecordID: flightRecord.id,
                componentType: "garmin_csv",
                localFilePath: destination.path,
                sha256: digest,
                byteCount: Int64(data.count),
                state: .queued,
                progress: 0,
                attemptCount: 0,
                lastError: "",
                lastAttemptAt: nil,
                serverVerificationAt: nil,
                serverReceiptID: nil
            )
            let event = makeFlightEvent(
                flightRecord: flightRecord,
                eventType: "garmin_csv_imported",
                source: "ios_share_sheet",
                creationMethod: "document_open_url",
                gpsSample: nil
            )

            mutate {
                flightRecord.status = .awaitingUpload
                flightRecord.updatedAt = event.timestampUTC
                $0.activeFlightRecord = flightRecord
                $0.uploadComponents.append(component)
                $0.flightEvents.append(event)
                $0.selectedTab = .garmin
            }
        } catch {
            lastError = "Could not import Garmin CSV: \(error.localizedDescription)"
        }
    }

    func updateUploadComponent(id: String, state: CVRUploadComponentState, progress: Double, lastError: String = "", serverReceiptID: String? = nil) {
        mutate {
            guard let index = $0.uploadComponents.firstIndex(where: { $0.id == id }) else { return }
            $0.uploadComponents[index].state = state
            $0.uploadComponents[index].progress = min(max(progress, 0), 1)
            $0.uploadComponents[index].lastError = lastError
            $0.uploadComponents[index].lastAttemptAt = Date()
            if state == .serverVerified {
                $0.uploadComponents[index].serverVerificationAt = Date()
                $0.uploadComponents[index].serverReceiptID = serverReceiptID ?? UUID().uuidString
            }
        }
    }

    func resetForNextFlightIfComplete() {
        guard let flightRecord = state.activeFlightRecord else { return }
        let components = state.uploadComponents.filter { $0.flightRecordID == flightRecord.id }
        guard !components.isEmpty, components.allSatisfy({ $0.state == .serverVerified }) else { return }

        mutate {
            $0.activeDispatch = nil
            $0.activeFlightRecord = nil
            $0.consents = []
            $0.recorderVerifications = []
            $0.flightEvents = []
            $0.flightLegs = []
            $0.uploadComponents = []
            $0.discrepancies = []
            $0.selectedTab = .dispatch
        }
    }

    var isDispatchVerified: Bool {
        guard let dispatch = state.activeDispatch else { return false }
        if state.activeFlightRecord?.dispatchID == dispatch.id {
            switch dispatch.status {
            case .readyForVerification, .dispatchVerified, .flightRecordLoggingEnabled:
                return true
            case .noDispatch, .dispatchIncomplete, .consentRequired, .tailNumberConflict:
                return false
            }
        }
        return dispatch.status == .flightRecordLoggingEnabled || dispatch.status == .dispatchVerified
    }

    var isDispatchLocked: Bool {
        guard let dispatch = state.activeDispatch else { return false }
        return state.activeFlightRecord?.dispatchID == dispatch.id
    }

    var isRecorderVerified: Bool {
        guard let flightRecord = state.activeFlightRecord else { return false }
        return state.recorderVerifications.contains { $0.flightRecordID == flightRecord.id }
    }

    var dispatchMissingItems: [String] {
        guard let dispatch = state.activeDispatch else { return ["DISPATCH REQUIRED"] }
        var items = dispatch.missingItems
        if !Self.hasRequiredConsents(dispatch: dispatch, consents: state.consents) {
            let consentMissing = dispatch.crew
                .filter { assignment in
                    !state.consents.contains { $0.dispatchID == dispatch.id && $0.personName == assignment.personName && $0.crewRole == assignment.role && $0.consentResult }
                }
                .map { "\($0.role.label.uppercased()) CONSENT REQUIRED" }
            items.append(contentsOf: consentMissing)
        }
        return Array(Set(items)).sorted()
    }

    private func mutate(_ update: (inout CVRWorkflowState) -> Void) {
        update(&state)
        state.updatedAt = Date()
        save()
    }

    private func appendFlightEvent(
        flightRecord: CVRIncompleteFlightRecord,
        eventType: String,
        source: String,
        creationMethod: String,
        gpsSample: GPSSample?
    ) {
        let event = makeFlightEvent(
            flightRecord: flightRecord,
            eventType: eventType,
            source: source,
            creationMethod: creationMethod,
            gpsSample: gpsSample
        )
        mutate {
            $0.flightEvents.append(event)
        }
    }

    private func makeFlightEvent(
        flightRecord: CVRIncompleteFlightRecord,
        eventType: String,
        source: String,
        creationMethod: String,
        gpsSample: GPSSample?
    ) -> CVRFlightEventRecord {
        let now = Date()
        return CVRFlightEventRecord(
            id: UUID().uuidString,
            flightRecordID: flightRecord.id,
            recordingSessionID: flightRecord.recordingSessionID,
            eventType: eventType,
            timestampUTC: now,
            timestampLocal: now,
            deviceMonotonicTime: ProcessInfo.processInfo.systemUptime,
            audioOffset: nil,
            latitude: gpsSample?.latitude,
            longitude: gpsSample?.longitude,
            altitude: gpsSample?.altitude,
            groundSpeed: gpsSample?.speedKnots,
            source: source,
            confidence: 1.0,
            creationMethod: creationMethod,
            userIdentity: "local_cvr_unit"
        )
    }

    private func save() {
        do {
            let url = try storeURL()
            let data = try encoder.encode(state)
            try data.write(to: url, options: [.atomic])
            lastError = ""
        } catch {
            lastError = "Workflow save failed: \(error.localizedDescription)"
        }
    }

    private func storeURL() throws -> URL {
        let base = try FileManager.default.url(
            for: .applicationSupportDirectory,
            in: .userDomainMask,
            appropriateFor: nil,
            create: true
        )
        let dir = base.appendingPathComponent("IPCACVRUnit", isDirectory: true)
        try FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        return dir.appendingPathComponent("flight-workflow.json")
    }

    private func garminImportDirectory() throws -> URL {
        let base = try FileManager.default.url(
            for: .applicationSupportDirectory,
            in: .userDomainMask,
            appropriateFor: nil,
            create: true
        )
        let dir = base.appendingPathComponent("IPCACVRUnit/GarminImports", isDirectory: true)
        try FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        return dir
    }

    private static let fileTimestampFormatter: DateFormatter = {
        let formatter = DateFormatter()
        formatter.calendar = Calendar(identifier: .gregorian)
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.timeZone = TimeZone(secondsFromGMT: 0)
        formatter.dateFormat = "yyyyMMdd-HHmmss"
        return formatter
    }()

    private static func dispatchStatus(for dispatch: CVRDispatchRecord, consents: [CVRConsentRecord]) -> CVRDispatchStatus {
        if !dispatch.missingItems.isEmpty {
            return .dispatchIncomplete
        }
        if !hasRequiredConsents(dispatch: dispatch, consents: consents) {
            return .consentRequired
        }
        return .readyForVerification
    }

    private static func hasRequiredConsents(dispatch: CVRDispatchRecord, consents: [CVRConsentRecord]) -> Bool {
        guard !dispatch.crew.isEmpty else { return false }
        return dispatch.crew.allSatisfy { assignment in
            consents.contains {
                $0.dispatchID == dispatch.id
                    && $0.personName == assignment.personName
                    && $0.crewRole == assignment.role
                    && $0.consentResult
            }
        }
    }

    private static func materialSignature(_ dispatch: CVRDispatchRecord) -> String {
        [
            dispatch.tailNumber,
            String(dispatch.aircraftID ?? 0),
            dispatch.missionCode,
            dispatch.crew.map { "\($0.personName):\($0.role.rawValue)" }.joined(separator: "|")
        ].joined(separator: "#")
    }
}
