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
        mutate {
            guard var dispatch = $0.activeDispatch else { return }
            let previousMaterialSignature = Self.materialSignature(dispatch)
            update(&dispatch)
            dispatch.version += 1
            dispatch.modifiedAt = Date()
            dispatch.status = Self.dispatchStatus(for: dispatch, consents: $0.consents)
            if previousMaterialSignature != Self.materialSignature(dispatch) {
                $0.consents = []
                dispatch.consentStatus = "invalidated_by_dispatch_change"
                if $0.activeFlightRecord?.status == .recorderVerificationRequired {
                    $0.activeFlightRecord = nil
                }
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

    var isDispatchVerified: Bool {
        state.activeDispatch?.status == .flightRecordLoggingEnabled || state.activeDispatch?.status == .dispatchVerified
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
