import CryptoKit
import Foundation

enum CVRWorkflowFailureOutcome: Equatable {
    case queued
    case authenticationPaused
    case userCorrectionRequired
    case technicalReviewRequired
}

@MainActor
final class CVRWorkflowStore: ObservableObject {
    static let maximumRequestPayloadSnapshotBytes = 256 * 1024

    @Published private(set) var state: CVRWorkflowState = .empty
    @Published private(set) var archives: [CVRWorkflowArchiveRecord] = []
    @Published private(set) var lastError = ""

    private let encoder: JSONEncoder
    private let decoder: JSONDecoder
    private var archiveRewriteSafe = true

    init() {
        encoder = JSONEncoder()
        encoder.outputFormatting = [.prettyPrinted, .sortedKeys]
        encoder.dateEncodingStrategy = .iso8601

        decoder = JSONDecoder()
        decoder.dateDecodingStrategy = .iso8601
    }

    func load() async {
        var diagnostics: [String] = []
        do {
            diagnostics.append(contentsOf: try loadArchives())
        } catch {
            archives = []
            archiveRewriteSafe = false
            diagnostics.append("Historical workflow archive recovery failed: \(error.localizedDescription)")
        }

        do {
            let url = try storeURL()
            if FileManager.default.fileExists(atPath: url.path) {
                let data = try Data(contentsOf: url)
                state = try decoder.decode(CVRWorkflowState.self, from: data)
                var changed = false
                if state.selectedTab != .scheduled {
                    state.selectedTab = .scheduled
                    changed = true
                }
                changed = recoverInterruptedActiveUploads() || changed
                changed = recoverIncompleteActiveVerificationMetadata() || changed
                changed = Self.repairStaleDispatchConsents(in: &state) || changed
                changed = Self.requeueLegacyAdvisoryDispatchFailure(in: &state) || changed
                changed = ensureDispatchUploadComponent() || changed
                changed = ensureEvidenceUploadComponents() || changed
                changed = reconcileClosureUploadComponents() || changed
                if let flightRecord = state.activeFlightRecord,
                   flightRecord.endingHobbs != nil,
                   flightRecord.endingTacho != nil {
                    if finishEndedFlightLocally() {
                        changed = false
                    }
                }
                if changed {
                    save()
                }
            }
        } catch {
            diagnostics.append("Active workflow recovery failed: \(error.localizedDescription)")
        }
        lastError = diagnostics.joined(separator: "\n")
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
        if state.activeDispatch != nil || state.activeFlightRecord != nil {
            lastError = "Finish and save the current flight before creating another Dispatch. Uploads may continue after the flight is saved."
            return
        }

        let carryover = latestClosedCarryover(for: registration)
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
            startingHobbs: carryover?.endingHobbs,
            startingTacho: carryover?.endingTacho,
            fuelOnboard: carryover?.fuelRemaining ?? "",
            oilPercentage: carryover?.oilPercentage,
            startingOilQuantity: carryover?.oilQuantity,
            startingOilUnit: carryover?.oilUnit ?? selectedAircraft.operationalConfig.oilUnit,
            dispatchSource: carryover == nil ? "iphone_offline_local" : "previous_locally_closed_flight_carryover",
            schedulerRecordID: nil,
            creatorIdentity: "local_cvr_unit",
            createdAt: Date(),
            modifiedAt: Date(),
            version: 1,
            consentStatus: "not_required_yet",
            status: .dispatchIncomplete,
            configuredCVRUnitID: cvrUnitID,
            configuredBeaconID: beaconID,
            previousFlightRecordID: carryover?.flightRecordID,
            previousEndingHobbs: carryover?.endingHobbs,
            previousEndingTacho: carryover?.endingTacho,
            previousFuelRemaining: carryover?.fuelRemaining,
            previousOilPercentage: carryover?.oilPercentage,
            previousEndingOilQuantity: carryover?.oilQuantity,
            previousEndingOilUnit: carryover?.oilUnit,
            refueledSincePreviousFlight: nil,
            oilServicedSincePreviousFlight: nil
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

    func openDispatchFromScheduledSession(
        _ session: CVRScheduledSession,
        selectedAircraft: CockpitAircraft?,
        cvrUnitID: String,
        beaconID: String,
        isAudioRecording: Bool
    ) {
        guard let selectedAircraft, scheduledSessionMatchesAircraft(session, selectedAircraft: selectedAircraft) else {
            lastError = "This scheduled flight does not match the aircraft enrolled to this CVR Unit."
            return
        }
        if let active = state.activeDispatch,
           active.schedulerRecordID == session.schedulerRecordID {
            selectTab(.dispatch)
            return
        }
        if state.activeFlightRecord != nil {
            guard !isAudioRecording else {
                lastError = "Stop the active recording before opening another scheduled flight."
                return
            }
            if state.activeDispatch != nil {
                guard archiveActiveWorkflow() else { return }
            }
            mutate {
                $0.activeDispatch = nil
                $0.activeFlightRecord = nil
                $0.consents = []
                $0.recorderVerifications = []
                $0.flightEvents = []
                $0.flightLegs = []
                $0.uploadComponents = []
                $0.discrepancies = []
            }
        } else if state.activeDispatch != nil {
            mutate {
                $0.activeDispatch = nil
                $0.consents = []
                $0.recorderVerifications = []
                $0.flightEvents = []
                $0.flightLegs = []
                $0.uploadComponents = []
                $0.discrepancies = []
            }
        }

        if state.activeDispatch == nil {
            createOrOpenLocalDispatch(selectedAircraft: selectedAircraft, cvrUnitID: cvrUnitID, beaconID: beaconID)
        }
        updateActiveDispatch { dispatch in
            dispatch.scheduledDate = session.dateTime(nil) ?? Date()
            dispatch.scheduledStartTime = session.dateTime(session.scheduledStartTime)
            dispatch.scheduledEndTime = session.dateTime(session.scheduledEndTime)
            dispatch.schedulerRecordID = session.schedulerRecordID
            dispatch.missionCode = session.missionCode
            dispatch.plannedDepartureAirport = session.plannedDepartureAirport
            dispatch.plannedDestinationAirport = session.plannedDestinationAirport
            dispatch.dispatchSource = "scheduled_session"
            dispatch.crew = session.crew.map { member in
                CVRCrewAssignment(
                    id: UUID().uuidString,
                    personID: member.personID,
                    personName: member.personName,
                    role: Self.crewRole(from: member.role)
                )
            }
        }
        selectTab(.dispatch)
    }

    func canOpenScheduledSession(
        _ session: CVRScheduledSession,
        selectedAircraft: CockpitAircraft?,
        isAudioRecording: Bool
    ) -> Bool {
        _ = session
        _ = selectedAircraft
        if state.activeDispatch?.schedulerRecordID == session.schedulerRecordID {
            return true
        }
        return !isAudioRecording
    }

    func requiresArchivingBeforeScheduledSession(_ session: CVRScheduledSession) -> Bool {
        guard state.activeDispatch != nil,
              state.activeDispatch?.schedulerRecordID != session.schedulerRecordID else {
            return false
        }
        if let flightRecord = state.activeFlightRecord {
            let endingMetersEntered = flightRecord.endingHobbs != nil && flightRecord.endingTacho != nil
            let shutdownSaved = state.flightEvents.contains {
                $0.flightRecordID == flightRecord.id
                    && $0.eventType == "shutdown_verification_completed"
            }
            if endingMetersEntered || shutdownSaved {
                return false
            }
        }
        return true
    }

    private func scheduledSessionMatchesAircraft(
        _ session: CVRScheduledSession,
        selectedAircraft: CockpitAircraft
    ) -> Bool {
        session.aircraftID == selectedAircraft.id
            || Self.normalizedTail(session.aircraftRegistration) == Self.normalizedTail(selectedAircraft.registration)
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
            dispatch.modifiedAt = Date()
            let materialChanged = previousMaterialSignature != Self.materialSignature(dispatch)
            if materialChanged {
                dispatch.version += 1
                $0.consents = []
                dispatch.consentStatus = "invalidated_by_dispatch_change"
                if $0.activeFlightRecord?.status == .recorderVerificationRequired {
                    $0.activeFlightRecord = nil
                }
            }
            dispatch.status = Self.dispatchStatus(for: dispatch, consents: $0.consents)
            if !materialChanged,
               previousStatus == .flightRecordLoggingEnabled,
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
            recordingStartedAt: nil,
            status: .recorderVerificationRequired,
            endingHobbs: nil,
            endingTacho: nil,
            fuelRemaining: nil,
            endingOilPercentage: nil,
            endingOilQuantity: nil,
            endingOilUnit: nil,
            verifiedTakeoffCount: nil,
            verifiedLandingCount: nil,
            autoDetectedTakeoffCount: nil,
            autoDetectedLandingCount: nil,
            maintenanceRemark: nil,
            createdAt: Date(),
            updatedAt: Date()
        )
        let dispatchComponent = CVRUploadComponentRecord(
            id: "dispatch-\(dispatch.id)-v\(dispatch.version)",
            serverID: nil,
            flightRecordID: flightRecord.id,
            componentType: "dispatch_metadata",
            localFilePath: nil,
            sha256: nil,
            byteCount: nil,
            state: .queued,
            progress: 0,
            attemptCount: 0,
            lastError: "",
            lastAttemptAt: nil,
            serverVerificationAt: nil,
            serverReceiptID: nil
        )

        mutate {
            dispatch.status = .flightRecordLoggingEnabled
            dispatch.consentStatus = "complete"
            dispatch.modifiedAt = Date()
            $0.activeDispatch = dispatch
            $0.activeFlightRecord = flightRecord
            if !$0.uploadComponents.contains(where: { $0.id == dispatchComponent.id }) {
                $0.uploadComponents.append(dispatchComponent)
            }
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
            $0.uploadComponents.removeAll {
                $0.flightRecordID == flightRecord.id && $0.componentType == "recorder_verification"
            }
            $0.uploadComponents.append(evidenceComponent(
                flightRecordID: flightRecord.id,
                type: "recorder_verification",
                evidenceID: verification.id
            ))
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
            audioOffset: flightRecord.recordingStartedAt.map { max(0, now.timeIntervalSince($0)) },
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
            $0.uploadComponents.append(eventUploadComponent(event))
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

    func recordManualTakeoffAdjustment(gpsSample: GPSSample?) {
        recordInFlightAction(eventType: "manual_takeoff_adjustment", creationMethod: "two_second_hold", gpsSample: gpsSample)
    }

    func recordManualLandingAdjustment(gpsSample: GPSSample?) {
        recordInFlightAction(eventType: "manual_landing_adjustment", creationMethod: "two_second_hold", gpsSample: gpsSample)
    }

    func operationCounts(for flightRecordID: String) -> (autoTakeoffs: Int, autoLandings: Int, manualTakeoffs: Int, manualLandings: Int, displayTakeoffs: Int, displayLandings: Int) {
        let events = state.flightEvents.filter { $0.flightRecordID == flightRecordID }
        let autoTakeoffs = events.filter { $0.eventType == "gps_takeoff_provisional" }.count
        let autoLandings = events.filter { $0.eventType == "gps_landing_provisional" }.count
        let manualTakeoffs = events.filter { $0.eventType == "manual_takeoff_adjustment" }.count
        let manualLandings = events.filter { $0.eventType == "manual_landing_adjustment" }.count
        return (
            autoTakeoffs,
            autoLandings,
            manualTakeoffs,
            manualLandings,
            autoTakeoffs + manualTakeoffs,
            autoLandings + manualLandings
        )
    }

    func recordGPSFlightTransition(_ transition: GPSFlightTransition) {
        guard let flightRecord = state.activeFlightRecord,
              state.flightEvents.contains(where: {
                  $0.flightRecordID == flightRecord.id && $0.eventType == "engine_start_off_block"
              }),
              !state.flightEvents.contains(where: {
                  $0.flightRecordID == flightRecord.id && $0.eventType == "engine_shutdown_on_block"
              }) else {
            return
        }

        let eventType: String
        let timestamp: Date
        let sample: GPSSample
        var metadata: [String: String] = [:]
        switch transition {
        case .takeoff(let detectedAt, let detectedSample, let kind):
            let takeoffs = state.flightEvents.filter {
                $0.flightRecordID == flightRecord.id && $0.eventType == "gps_takeoff_provisional"
            }.count
            let landings = state.flightEvents.filter {
                $0.flightRecordID == flightRecord.id && $0.eventType == "gps_landing_provisional"
            }.count
            guard takeoffs <= landings else { return }
            eventType = "gps_takeoff_provisional"
            timestamp = detectedAt
            sample = detectedSample
            metadata["takeoff_kind"] = kind.rawValue
        case .landing(let detectedAt, let detectedSample, let kind):
            let takeoffs = state.flightEvents.filter {
                $0.flightRecordID == flightRecord.id && $0.eventType == "gps_takeoff_provisional"
            }.count
            let landings = state.flightEvents.filter {
                $0.flightRecordID == flightRecord.id && $0.eventType == "gps_landing_provisional"
            }.count
            guard takeoffs > landings else { return }
            eventType = "gps_landing_provisional"
            timestamp = detectedAt
            sample = detectedSample
            metadata["landing_kind"] = kind.rawValue
        }
        let event = CVRFlightEventRecord(
            id: UUID().uuidString,
            flightRecordID: flightRecord.id,
            recordingSessionID: flightRecord.recordingSessionID,
            eventType: eventType,
            timestampUTC: timestamp,
            timestampLocal: timestamp,
            deviceMonotonicTime: ProcessInfo.processInfo.systemUptime,
            audioOffset: flightRecord.recordingStartedAt.map { max(0, timestamp.timeIntervalSince($0)) },
            latitude: sample.latitude,
            longitude: sample.longitude,
            altitude: sample.altitude,
            groundSpeed: sample.speedKnots,
            source: "gps_realtime_provisional",
            confidence: 0.85,
            creationMethod: "airport_cycle_gates",
            userIdentity: nil,
            metadata: metadata
        )
        mutate {
            $0.flightEvents.append(event)
            $0.uploadComponents.append(eventUploadComponent(event))
        }
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
            $0.uploadComponents.append(eventUploadComponent(event))
        }
    }

    @discardableResult
    func recordShutdownVerification(
        endingHobbs: Double?,
        endingTacho: Double?,
        verifiedTakeoffCount: Int,
        verifiedLandingCount: Int,
        maintenanceRemark: String,
        gpsSample: GPSSample?
    ) -> Bool {
        guard state.flightEvents.contains(where: {
            $0.flightRecordID == state.activeFlightRecord?.id && $0.eventType == "engine_shutdown_on_block"
        }) else {
            lastError = "Engine shutdown must be recorded before post-flight values can be saved."
            return false
        }
        return saveFlightClosureValues(
            endingHobbs: endingHobbs,
            endingTacho: endingTacho,
            verifiedTakeoffCount: verifiedTakeoffCount,
            verifiedLandingCount: verifiedLandingCount,
            maintenanceRemark: maintenanceRemark,
            gpsSample: gpsSample,
            repairExistingClosureUpload: false
        )
    }

    func flightClosureIsComplete(
        _ flightRecord: CVRIncompleteFlightRecord,
        dispatch explicitDispatch: CVRDispatchRecord? = nil
    ) -> Bool {
        guard let endingHobbs = flightRecord.endingHobbs,
              let endingTacho = flightRecord.endingTacho else {
            return false
        }
        let dispatch = explicitDispatch ?? (
            state.activeFlightRecord?.id == flightRecord.id ? state.activeDispatch : nil
        )
        guard let dispatch else { return endingHobbs >= 0 && endingTacho >= 0 }
        if let startingHobbs = dispatch.startingHobbs, endingHobbs < startingHobbs { return false }
        if let startingTacho = dispatch.startingTacho, endingTacho < startingTacho { return false }
        return true
    }

    func closureUploadFailure() -> CVRUploadComponentRecord? {
        state.uploadComponents.first {
            $0.componentType == "flight_record_closure"
                && $0.errorCode != "IMMUTABLE_CONFLICT"
                && ($0.state == .failed || $0.state == .needsUserAction)
        }
    }

    var canEditFlightClosure: Bool {
        guard state.activeFlightRecord != nil, state.activeDispatch != nil else { return false }
        guard let flightRecord = state.activeFlightRecord else { return false }
        if closureUploadFailure() != nil {
            return !flightClosureIsComplete(flightRecord)
        }
        if flightClosureIsComplete(flightRecord) { return false }
        return state.uploadComponents.contains {
            $0.componentType == "flight_record_closure" && $0.flightRecordID == flightRecord.id
        } || state.flightEvents.contains {
            $0.flightRecordID == flightRecord.id && $0.eventType == "engine_shutdown_on_block"
        }
    }

    @discardableResult
    func repairCompletedClosureUploadIfNeeded() -> Bool {
        guard let flightRecord = state.activeFlightRecord,
              flightClosureIsComplete(flightRecord),
              closureUploadFailure() != nil else {
            return false
        }
        return mutate {
            $0.uploadComponents.removeAll {
                $0.flightRecordID == flightRecord.id
                    && $0.componentType == "flight_record_closure"
                    && ($0.state == .failed || $0.state == .needsUserAction)
            }
            if !$0.uploadComponents.contains(where: {
                $0.flightRecordID == flightRecord.id
                    && $0.componentType == "flight_record_closure"
            }) {
                $0.uploadComponents.append(evidenceComponent(
                    flightRecordID: flightRecord.id,
                    type: "flight_record_closure",
                    evidenceID: flightRecord.id
                ))
            }
        }
    }

    @discardableResult
    func saveFlightClosureValues(
        endingHobbs: Double?,
        endingTacho: Double?,
        verifiedTakeoffCount: Int,
        verifiedLandingCount: Int,
        maintenanceRemark: String,
        gpsSample: GPSSample?,
        repairExistingClosureUpload: Bool
    ) -> Bool {
        guard var flightRecord = state.activeFlightRecord,
              let dispatch = state.activeDispatch else { return false }
        guard let endingHobbs, endingHobbs >= (dispatch.startingHobbs ?? 0) else {
            lastError = "Ending Hobbs must be present and cannot be lower than Starting Hobbs."
            return false
        }
        guard let endingTacho, endingTacho >= (dispatch.startingTacho ?? 0) else {
            lastError = "Ending Tacho must be present and cannot be lower than Starting Tacho."
            return false
        }
        guard verifiedTakeoffCount >= 0, verifiedLandingCount >= 0 else {
            lastError = "Takeoff and landing counts must be zero or greater."
            return false
        }

        let counts = operationCounts(for: flightRecord.id)
        let event = makeFlightEvent(
            flightRecord: flightRecord,
            eventType: "shutdown_verification_completed",
            source: repairExistingClosureUpload ? "closure_upload_repair" : "manual_shutdown_verification",
            creationMethod: repairExistingClosureUpload ? "upload_repair_form" : "post_flight_form",
            gpsSample: gpsSample,
            metadata: [
                "verified_takeoff_count": String(verifiedTakeoffCount),
                "verified_landing_count": String(verifiedLandingCount),
                "auto_takeoff_count": String(counts.autoTakeoffs + counts.manualTakeoffs),
                "auto_landing_count": String(counts.autoLandings + counts.manualLandings),
            ]
        )

        let persisted = mutate {
            flightRecord.endingHobbs = endingHobbs
            flightRecord.endingTacho = endingTacho
            flightRecord.verifiedTakeoffCount = verifiedTakeoffCount
            flightRecord.verifiedLandingCount = verifiedLandingCount
            flightRecord.autoDetectedTakeoffCount = counts.displayTakeoffs
            flightRecord.autoDetectedLandingCount = counts.displayLandings
            flightRecord.maintenanceRemark = maintenanceRemark.trimmingCharacters(in: .whitespacesAndNewlines)
            flightRecord.status = .awaitingUpload
            flightRecord.updatedAt = event.timestampUTC
            $0.activeFlightRecord = flightRecord
            let supersededEventIDs = Set($0.flightEvents.filter {
                $0.flightRecordID == flightRecord.id && $0.eventType == "shutdown_verification_completed"
            }.map(\.id))
            $0.flightEvents.removeAll { supersededEventIDs.contains($0.id) }
            $0.uploadComponents.removeAll {
                $0.componentType == "flight_events"
                    && supersededEventIDs.contains(String(($0.localFilePath ?? "").dropFirst("event:".count)))
            }
            $0.flightEvents.append(event)
            $0.uploadComponents.append(eventUploadComponent(event))

            // A repaired closure is new immutable evidence. Reusing the failed
            // component UUID can conflict with a request the server accepted
            // before the client lost its response.
            $0.uploadComponents.removeAll {
                $0.flightRecordID == flightRecord.id
                    && $0.componentType == "flight_record_closure"
            }
            $0.uploadComponents.append(evidenceComponent(
                flightRecordID: flightRecord.id,
                type: "flight_record_closure",
                evidenceID: flightRecord.id
            ))
            $0.selectedTab = repairExistingClosureUpload ? $0.selectedTab : .log
        }
        return persisted
    }

    func importGarminCSV(from sourceURL: URL) {
        _ = importGarminCSVFromRecovery(sourceURL: sourceURL, sourceLabel: "ios_share_sheet")
    }

    @discardableResult
    func importGarminCSVFromRecovery(sourceURL: URL, sourceLabel: String) -> String? {
        guard var flightRecord = state.activeFlightRecord else {
            lastError = "Create or recover a Flight Record before importing Garmin CSV."
            return nil
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
                return nil
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
            if state.uploadComponents.contains(where: {
                $0.flightRecordID == flightRecord.id
                    && $0.componentType == "garmin_csv"
                    && ($0.sha256?.caseInsensitiveCompare(digest) == .orderedSame)
            }) {
                return state.uploadComponents.first(where: {
                    $0.flightRecordID == flightRecord.id
                        && $0.componentType == "garmin_csv"
                        && ($0.sha256?.caseInsensitiveCompare(digest) == .orderedSame)
                })?.id
            }

            let component = CVRUploadComponentRecord(
                id: UUID().uuidString,
                serverID: nil,
                flightRecordID: flightRecord.id,
                componentType: "garmin_csv",
                localFilePath: "GarminImports/\(destination.lastPathComponent)",
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
                source: sourceLabel,
                creationMethod: sourceLabel == "ios_share_sheet" ? "document_open_url" : "sd_card_auto_import",
                gpsSample: nil
            )

            mutate {
                flightRecord.status = .awaitingUpload
                flightRecord.updatedAt = event.timestampUTC
                $0.activeFlightRecord = flightRecord
                $0.uploadComponents.append(component)
                $0.flightEvents.append(event)
                $0.uploadComponents.append(eventUploadComponent(event))
                $0.selectedTab = .log
            }
            return component.id
        } catch {
            lastError = "Could not import Garmin CSV: \(error.localizedDescription)"
            return nil
        }
    }

    func updateUploadComponent(
        id: String,
        state: CVRUploadComponentState,
        progress: Double,
        lastError: String = "",
        serverReceiptID: String? = nil,
        errorCode: String? = nil,
        retryable: Bool? = nil,
        userActionRequired: Bool? = nil,
        requestID: String? = nil
    ) {
        if self.state.uploadComponents.contains(where: { $0.id == id }) {
            mutate {
                guard let index = $0.uploadComponents.firstIndex(where: { $0.id == id }) else { return }
                updateComponent(
                    &$0.uploadComponents[index],
                    state: state,
                    progress: progress,
                    lastError: lastError,
                    serverReceiptID: serverReceiptID,
                    errorCode: errorCode,
                    retryable: retryable,
                    userActionRequired: userActionRequired,
                    requestID: requestID
                )
            }
            return
        }
        guard let archiveIndex = archives.firstIndex(where: {
            $0.uploadComponents.contains(where: { $0.id == id })
        }), let componentIndex = archives[archiveIndex].uploadComponents.firstIndex(where: { $0.id == id }) else {
            return
        }
        var updated = archives
        updateComponent(
            &updated[archiveIndex].uploadComponents[componentIndex],
            state: state,
            progress: progress,
            lastError: lastError,
            serverReceiptID: serverReceiptID,
            errorCode: errorCode,
            retryable: retryable,
            userActionRequired: userActionRequired,
            requestID: requestID
        )
        updated[archiveIndex].status = updated[archiveIndex].uploadComponents.allSatisfy { $0.state == .serverVerified } ? .serverVerified : .uploadPending
        do {
            try saveArchives(updated)
            archives = updated
        } catch {
            self.lastError = "Could not persist archived upload receipt: \(error.localizedDescription)"
        }
    }

    func workflowComponentsRequiringReconciliation(explicitRetry: Bool = false) -> [CVRUploadComponentRecord] {
        let requiresReconciliation: (CVRUploadComponentRecord) -> Bool = { component in
            guard component.componentType != "garmin_csv",
                  component.state == .queued || component.state == .serverVerified else {
                return false
            }
            if explicitRetry && component.state != .serverVerified {
                return true
            }
            if component.reconciliationRequired == true {
                return true
            }
            if component.state == .serverVerified {
                return !Self.hasCompleteVerificationMetadata(component)
            }
            if component.reconciliationRequired == false {
                return false
            }
            return component.attemptCount > 0 && !Self.hasCompleteVerificationMetadata(component)
        }
        return state.uploadComponents.filter(requiresReconciliation)
            + archives.flatMap(\.uploadComponents).filter(requiresReconciliation)
    }

    @discardableResult
    func persistRequestPayloadSnapshot(
        componentID: String,
        payload: Data,
        reconciliationRequired: Bool? = nil
    ) -> Bool {
        guard payload.count <= Self.maximumRequestPayloadSnapshotBytes else {
            lastError = "Workflow request payload snapshot exceeds the \(Self.maximumRequestPayloadSnapshotBytes)-byte limit; operational evidence was preserved without the oversized snapshot."
            return false
        }
        if state.uploadComponents.contains(where: { $0.id == componentID }) {
            return mutate {
                guard let index = $0.uploadComponents.firstIndex(where: { $0.id == componentID }) else { return }
                $0.uploadComponents[index].requestPayloadSnapshot = payload
                if let reconciliationRequired {
                    $0.uploadComponents[index].reconciliationRequired = reconciliationRequired
                }
            }
        }
        guard let archiveIndex = archives.firstIndex(where: {
            $0.uploadComponents.contains(where: { $0.id == componentID })
        }), let componentIndex = archives[archiveIndex].uploadComponents.firstIndex(where: { $0.id == componentID }) else {
            return false
        }
        var updated = archives
        updated[archiveIndex].uploadComponents[componentIndex].requestPayloadSnapshot = payload
        if let reconciliationRequired {
            updated[archiveIndex].uploadComponents[componentIndex].reconciliationRequired = reconciliationRequired
        }
        do {
            try saveArchives(updated)
            archives = updated
            lastError = ""
            return true
        } catch {
            lastError = "Could not durably preserve the workflow request payload: \(error.localizedDescription)"
            return false
        }
    }

    @discardableResult
    func markReconciliationRequired(id: String, message: String) -> Bool {
        updateComponentAtomically(id: id) { component in
            component.state = .queued
            component.progress = 0
            component.lastError = message
            component.reconciliationRequired = true
            component.retryable = true
            component.userActionRequired = false
        }
    }

    @discardableResult
    func persistVerifiedWorkflowComponent(
        componentID: String,
        serverReceiptID: String,
        authoritativePayloadSHA256: String,
        serverVerificationAt: Date,
        canonicalIdentifiers: [String: String]
    ) -> Bool {
        guard !serverReceiptID.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty,
              !authoritativePayloadSHA256.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty else {
            lastError = "Server verification metadata is incomplete."
            return false
        }
        return updateComponentAtomically(id: componentID) { component in
            component.serverReceiptID = serverReceiptID
            component.authoritativePayloadSHA256 = authoritativePayloadSHA256
            component.serverVerificationAt = serverVerificationAt
            component.canonicalIdentifiers = canonicalIdentifiers
            component.serverID = Self.primaryServerIdentifier(
                componentType: component.componentType,
                canonicalIdentifiers: canonicalIdentifiers
            )
            component.reconciliationRequired = false
            component.state = .serverVerified
            component.progress = 1
            component.lastError = ""
            component.errorCode = nil
            component.retryable = false
            component.userActionRequired = false
            component.lastAttemptAt = Date()
        } validation: {
            Self.hasCompleteVerificationMetadata($0)
        }
    }

    @discardableResult
    func persistReconciliationMatch(
        componentID: String,
        serverReceiptID: String,
        authoritativePayloadSHA256: String,
        serverVerificationAt: Date,
        canonicalIdentifiers: [String: String]
    ) -> Bool {
        guard let serverDispatchID = canonicalIdentifiers["server_dispatch_id"] else {
            return persistVerifiedWorkflowComponent(
                componentID: componentID,
                serverReceiptID: serverReceiptID,
                authoritativePayloadSHA256: authoritativePayloadSHA256,
                serverVerificationAt: serverVerificationAt,
                canonicalIdentifiers: canonicalIdentifiers
            )
        }
        guard !serverReceiptID.isEmpty, !authoritativePayloadSHA256.isEmpty else {
            return false
        }
        if let currentIndex = state.uploadComponents.firstIndex(where: { $0.id == componentID }) {
            var verifiedComponent = state.uploadComponents[currentIndex]
            Self.applyVerifiedMetadata(
                to: &verifiedComponent,
                receiptID: serverReceiptID,
                payloadSHA256: authoritativePayloadSHA256,
                verificationAt: serverVerificationAt,
                canonicalIdentifiers: canonicalIdentifiers
            )
            guard Self.hasCompleteVerificationMetadata(verifiedComponent) else { return false }
            return mutate {
                guard var dispatch = $0.activeDispatch,
                      let index = $0.uploadComponents.firstIndex(where: { $0.id == componentID }) else { return }
                dispatch.serverDispatchID = serverDispatchID
                $0.activeDispatch = dispatch
                $0.uploadComponents[index] = verifiedComponent
            }
        }
        guard let archiveIndex = archives.firstIndex(where: {
            $0.uploadComponents.contains(where: { $0.id == componentID })
        }), let componentIndex = archives[archiveIndex].uploadComponents.firstIndex(where: { $0.id == componentID }) else {
            return false
        }
        var updated = archives
        updated[archiveIndex].dispatch.serverDispatchID = serverDispatchID
        Self.applyVerifiedMetadata(
            to: &updated[archiveIndex].uploadComponents[componentIndex],
            receiptID: serverReceiptID,
            payloadSHA256: authoritativePayloadSHA256,
            verificationAt: serverVerificationAt,
            canonicalIdentifiers: canonicalIdentifiers
        )
        guard Self.hasCompleteVerificationMetadata(updated[archiveIndex].uploadComponents[componentIndex]) else {
            return false
        }
        updated[archiveIndex].status = updated[archiveIndex].uploadComponents.allSatisfy {
            $0.state == .serverVerified
        } ? .serverVerified : .uploadPending
        do {
            try saveArchives(updated)
            archives = updated
            lastError = ""
            return true
        } catch {
            lastError = "Could not durably persist reconciled Dispatch metadata: \(error.localizedDescription)"
            return false
        }
    }

    func applyReconciliationDisposition(
        componentID: String,
        state: CVRUploadComponentState,
        message: String,
        errorCode: String,
        retryable: Bool,
        reconciliationRequired: Bool
    ) {
        _ = updateComponentAtomically(id: componentID) { component in
            component.state = state
            component.progress = 0
            component.lastError = message
            component.errorCode = errorCode
            component.retryable = retryable
            component.userActionRequired = errorCode == "USER_CORRECTION_REQUIRED"
            component.reconciliationRequired = reconciliationRequired
            component.lastAttemptAt = Date()
        }
    }

    @discardableResult
    func recordWorkflowUploadFailure(id: String, progress: Double, error: Error) -> CVRWorkflowFailureOutcome {
        let classification = Self.classifyWorkflowUploadFailure(error)
        updateUploadComponent(
            id: id,
            state: classification.state,
            progress: progress,
            lastError: classification.message,
            errorCode: classification.errorCode,
            retryable: classification.retryable,
            userActionRequired: classification.userActionRequired,
            requestID: classification.requestID
        )
        return classification.outcome
    }

    @discardableResult
    func persistVerifiedDispatch(
        componentID: String,
        serverDispatchID: String,
        serverReceiptID: String,
        flightRecordID: String
    ) -> Bool {
        if state.activeFlightRecord?.id == flightRecordID {
            guard state.activeDispatch != nil,
                  state.uploadComponents.contains(where: { $0.id == componentID }) else {
                lastError = "Could not durably link the verified Dispatch to its active local flight."
                return false
            }
            return mutate {
                guard var dispatch = $0.activeDispatch,
                      let componentIndex = $0.uploadComponents.firstIndex(where: { $0.id == componentID }) else { return }
                dispatch.serverDispatchID = serverDispatchID
                $0.activeDispatch = dispatch
                updateComponent(
                    &$0.uploadComponents[componentIndex],
                    state: .serverVerified,
                    progress: 1,
                    lastError: "",
                    serverReceiptID: serverReceiptID
                )
            }
        }
        guard let archiveIndex = archives.firstIndex(where: { $0.flightRecordID == flightRecordID }),
              let componentIndex = archives[archiveIndex].uploadComponents.firstIndex(where: { $0.id == componentID }) else {
            lastError = "Could not durably link the verified Dispatch to its local flight."
            return false
        }
        var updated = archives
        updated[archiveIndex].dispatch.serverDispatchID = serverDispatchID
        updateComponent(
            &updated[archiveIndex].uploadComponents[componentIndex],
            state: .serverVerified,
            progress: 1,
            lastError: "",
            serverReceiptID: serverReceiptID
        )
        updated[archiveIndex].status = updated[archiveIndex].uploadComponents.allSatisfy {
            $0.state == .serverVerified
        } ? .serverVerified : .uploadPending
        do {
            try saveArchives(updated)
            archives = updated
            lastError = ""
            return true
        } catch {
            lastError = "Could not durably persist the verified Dispatch: \(error.localizedDescription)"
            return false
        }
    }

    func recoverOrphanedUploads(activeComponentIDs: Set<String>) {
        mutate {
            for index in $0.uploadComponents.indices {
                guard $0.uploadComponents[index].state == .uploading,
                      !activeComponentIDs.contains($0.uploadComponents[index].id) else {
                    continue
                }
                $0.uploadComponents[index].state = .queued
                if $0.uploadComponents[index].componentType != "garmin_csv" {
                    $0.uploadComponents[index].reconciliationRequired = true
                }
                $0.uploadComponents[index].progress = 0
                $0.uploadComponents[index].lastError = "Upload task ended before local completion; queued for recovery."
            }
        }

        var updated = archives
        var changed = false
        for archiveIndex in updated.indices {
            for componentIndex in updated[archiveIndex].uploadComponents.indices {
                let component = updated[archiveIndex].uploadComponents[componentIndex]
                guard component.state == .uploading,
                      !activeComponentIDs.contains(component.id) else {
                    continue
                }
                updated[archiveIndex].uploadComponents[componentIndex].state = .queued
                if component.componentType != "garmin_csv" {
                    updated[archiveIndex].uploadComponents[componentIndex].reconciliationRequired = true
                }
                updated[archiveIndex].uploadComponents[componentIndex].progress = 0
                updated[archiveIndex].uploadComponents[componentIndex].lastError =
                    "Upload task ended before local completion; queued for recovery."
                updated[archiveIndex].status = .uploadPending
                changed = true
            }
        }
        guard changed else { return }
        do {
            try saveArchives(updated)
            archives = updated
        } catch {
            lastError = "Could not persist orphaned archive upload recovery: \(error.localizedDescription)"
        }
    }

    func queuedWorkflowComponents() -> [CVRUploadComponentRecord] {
        let eligible: (CVRUploadComponentRecord) -> Bool = {
            $0.state == .queued
        }
        return state.uploadComponents.filter(eligible) + archives.flatMap(\.uploadComponents).filter(eligible)
    }

    func recordingSessionFlightRecordLinks() -> [String: String] {
        var links: [String: String] = [:]
        if let flightRecord = state.activeFlightRecord,
           let recordingSessionID = flightRecord.recordingSessionID,
           !recordingSessionID.isEmpty {
            links[recordingSessionID] = flightRecord.id
        }
        for archive in archives {
            let sessionIDs = archive.recordingSessionIDs
                + [archive.flightRecord.recordingSessionID].compactMap { $0 }
            for sessionID in sessionIDs where !sessionID.isEmpty {
                links[sessionID] = archive.flightRecordID
            }
        }
        return links
    }

    func recordingIdentifiers(forFlightRecordID flightRecordID: String) -> Set<String> {
        var identifiers = Set([flightRecordID])
        if state.activeFlightRecord?.id == flightRecordID,
           let recordingSessionID = state.activeFlightRecord?.recordingSessionID {
            identifiers.insert(recordingSessionID)
        }
        if let archive = archives.first(where: { $0.flightRecordID == flightRecordID }) {
            identifiers.formUnion(archive.recordingSessionIDs)
            if let recordingSessionID = archive.flightRecord.recordingSessionID {
                identifiers.insert(recordingSessionID)
            }
        }
        return identifiers
    }

    func requeueConnectivityFailedUploads() {
        mutate {
            for index in $0.uploadComponents.indices {
                guard $0.uploadComponents[index].state == .failed,
                      $0.uploadComponents[index].retryable == true
                        || ($0.uploadComponents[index].errorCode == nil
                            && Self.isConnectivityFailure($0.uploadComponents[index].lastError)) else {
                    continue
                }
                $0.uploadComponents[index].state = .queued
                $0.uploadComponents[index].progress = 0
                $0.uploadComponents[index].lastError = ""
            }
        }

        var updated = archives
        var changed = false
        for archiveIndex in updated.indices {
            for componentIndex in updated[archiveIndex].uploadComponents.indices {
                let component = updated[archiveIndex].uploadComponents[componentIndex]
                guard component.state == .failed,
                      component.retryable == true
                        || (component.errorCode == nil
                            && Self.isConnectivityFailure(component.lastError)) else {
                    continue
                }
                updated[archiveIndex].uploadComponents[componentIndex].state = .queued
                updated[archiveIndex].uploadComponents[componentIndex].progress = 0
                updated[archiveIndex].uploadComponents[componentIndex].lastError = ""
                updated[archiveIndex].status = .uploadPending
                changed = true
            }
        }
        guard changed else { return }
        do {
            try saveArchives(updated)
            archives = updated
            lastError = ""
        } catch {
            lastError = "Could not restore offline flight uploads: \(error.localizedDescription)"
        }
    }

    func workflowUploadContext(componentID: String) -> (
        dispatch: CVRDispatchRecord,
        flightRecord: CVRIncompleteFlightRecord,
        consents: [CVRConsentRecord],
        events: [CVRFlightEventRecord],
        verifications: [CVRRecorderVerificationRecord]
    )? {
        if state.uploadComponents.contains(where: { $0.id == componentID }),
           let dispatch = state.activeDispatch,
           let flightRecord = state.activeFlightRecord {
            return (dispatch, flightRecord, state.consents, state.flightEvents, state.recorderVerifications)
        }
        guard let archive = archives.first(where: {
            $0.uploadComponents.contains(where: { $0.id == componentID })
        }) else { return nil }
        return (archive.dispatch, archive.flightRecord, archive.consents, archive.flightEvents, archive.recorderVerifications)
    }

    func linkRecordingSession(recordingID: String, startedAt: Date) {
        guard !recordingID.isEmpty else { return }
        mutate {
            guard var flightRecord = $0.activeFlightRecord else { return }
            flightRecord.recordingSessionID = recordingID
            flightRecord.recordingStartedAt = startedAt
            flightRecord.updatedAt = Date()
            $0.activeFlightRecord = flightRecord
            for index in $0.flightEvents.indices where $0.flightEvents[index].flightRecordID == flightRecord.id {
                $0.flightEvents[index].recordingSessionID = recordingID
                $0.flightEvents[index].audioOffset = max(0, $0.flightEvents[index].timestampUTC.timeIntervalSince(startedAt))
            }
        }
    }

    func activeWorkflowExportURL() throws -> URL {
        let directory = FileManager.default.temporaryDirectory.appendingPathComponent("IPCA-CVR-Exports", isDirectory: true)
        try FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
        let suffix = state.activeFlightRecord?.id ?? state.activeDispatch?.id ?? "workflow"
        let url = directory.appendingPathComponent("IPCA-CVR-active-\(suffix).json")
        try encoder.encode(state).write(to: url, options: [.atomic])
        return url
    }

    func dispatchUploadFailure() -> CVRUploadComponentRecord? {
        state.uploadComponents.first {
            $0.componentType == "dispatch_metadata" && ($0.state == .failed || $0.state == .needsUserAction)
        }
    }

    func dispatchUploadVerified() -> Bool {
        state.uploadComponents.contains {
            $0.componentType == "dispatch_metadata" && $0.state == .serverVerified
        }
    }

    func dispatchUploadInProgress() -> Bool {
        state.uploadComponents.contains {
            $0.componentType == "dispatch_metadata" && ($0.state == .queued || $0.state == .uploading)
        }
    }

    enum DispatchContinuityUploadIssue: Equatable {
        case oilServicing
        case refueling
    }

    func dispatchContinuityUploadIssue() -> DispatchContinuityUploadIssue? {
        if let error = dispatchUploadFailure()?.lastError.lowercased() {
            if error.contains("oil") && error.contains("servic") {
                return .oilServicing
            }
            if error.contains("refuel") {
                return .refueling
            }
        }
        if dispatchMissingItems.contains(where: { $0.contains("CONFIRM OIL WAS SERVICED") }) {
            return .oilServicing
        }
        if dispatchMissingItems.contains(where: { $0.contains("CONFIRM AIRCRAFT WAS REFUELED") }) {
            return .refueling
        }
        return nil
    }

    var canRepairFailedDispatchUpload: Bool {
        isDispatchLocked && dispatchUploadFailure() != nil
    }

    @discardableResult
    func updateActiveDispatchForUploadRepair(_ update: (inout CVRDispatchRecord) -> Void) -> Bool {
        guard canRepairFailedDispatchUpload else {
            lastError = "Dispatch can only be repaired while a Dispatch upload is failing."
            return false
        }
        return mutate {
            guard var dispatch = $0.activeDispatch else { return }
            update(&dispatch)
            dispatch.modifiedAt = Date()
            $0.activeDispatch = dispatch
            for index in $0.uploadComponents.indices {
                guard $0.uploadComponents[index].componentType == "dispatch_metadata" else { continue }
                if $0.uploadComponents[index].state == .failed || $0.uploadComponents[index].state == .needsUserAction {
                    $0.uploadComponents[index].state = .queued
                    $0.uploadComponents[index].lastError = ""
                    $0.uploadComponents[index].progress = 0
                }
            }
        }
    }

    func failedActiveUploadComponents() -> [CVRUploadComponentRecord] {
        state.uploadComponents.filter { $0.state == .failed || $0.state == .needsUserAction }
    }

    static func normalizedTail(_ value: String) -> String {
        value.uppercased().filter { $0.isLetter || $0.isNumber }
    }

    private static func isConnectivityFailure(_ message: String) -> Bool {
        let normalized = message.lowercased()
        return normalized.contains("offline")
            || normalized.contains("internet connection")
            || normalized.contains("network connection")
            || normalized.contains("not connected to the internet")
            || normalized.contains("could not connect")
            || normalized.contains("connection was lost")
            || normalized.contains("timed out")
    }

    private struct WorkflowFailureClassification {
        var outcome: CVRWorkflowFailureOutcome
        var state: CVRUploadComponentState
        var message: String
        var errorCode: String?
        var retryable: Bool?
        var userActionRequired: Bool?
        var requestID: String?
    }

    private static func classifyWorkflowUploadFailure(_ error: Error) -> WorkflowFailureClassification {
        if case APIClientError.synchronization(let failure) = error {
            let outcome: CVRWorkflowFailureOutcome
            let state: CVRUploadComponentState
            switch failure.errorCode {
            case "TEMPORARY_TECHNICAL_FAILURE", "DEPENDENCY_NOT_READY":
                outcome = .queued
                state = .queued
            case "AUTHENTICATION_REQUIRED":
                outcome = .authenticationPaused
                state = .queued
            case "USER_CORRECTION_REQUIRED":
                outcome = failure.userActionRequired ? .userCorrectionRequired : .technicalReviewRequired
                state = failure.userActionRequired ? .needsUserAction : .failed
            case "TECHNICAL_REVIEW_REQUIRED":
                outcome = .technicalReviewRequired
                state = .failed
            default:
                if failure.retryable {
                    outcome = .queued
                    state = .queued
                } else if failure.userActionRequired {
                    outcome = .userCorrectionRequired
                    state = .needsUserAction
                } else {
                    outcome = .technicalReviewRequired
                    state = .failed
                }
            }
            return WorkflowFailureClassification(
                outcome: outcome,
                state: state,
                message: failure.error,
                errorCode: failure.errorCode,
                retryable: failure.retryable,
                userActionRequired: failure.userActionRequired,
                requestID: failure.requestID
            )
        }

        // Compatibility only for older endpoints that return text instead of error_code.
        let message = error.localizedDescription
        let normalized = message.lowercased()
        let outcome: CVRWorkflowFailureOutcome
        let state: CVRUploadComponentState
        if normalized.contains("device token")
            || normalized.contains("credential")
            || normalized.contains("not enrolled")
            || normalized.contains("authentication") {
            outcome = .authenticationPaused
            state = .queued
        } else if normalized.contains("http 5")
            || isConnectivityFailure(message)
            || normalized.contains("payload snapshot")
            || normalized.contains("authoritative verification metadata") {
            outcome = .queued
            state = .queued
        } else if normalized.contains("dispatch metadata must be verified")
            || normalized.contains("dispatch meter baseline is unavailable")
            || normalized.contains("dispatch is not owned") {
            outcome = .queued
            state = .queued
        } else if normalized.contains("ending hobbs")
            || normalized.contains("ending tacho")
            || normalized.contains("fuel_remaining")
            || normalized.contains("oil")
            || normalized.contains("consent")
            || normalized.contains("tail number")
            || normalized.contains("scheduled session") {
            outcome = .userCorrectionRequired
            state = .needsUserAction
        } else {
            outcome = .technicalReviewRequired
            state = .failed
        }
        return WorkflowFailureClassification(
            outcome: outcome,
            state: state,
            message: message,
            errorCode: nil,
            retryable: state == .queued,
            userActionRequired: state == .needsUserAction,
            requestID: nil
        )
    }

    static func classifyReconciliationEndpointFailure(
        _ error: Error
    ) -> (authenticationRequired: Bool, errorCode: String, message: String) {
        if case APIClientError.synchronization(let failure) = error {
            return (
                failure.errorCode == "AUTHENTICATION_REQUIRED",
                failure.errorCode,
                failure.error
            )
        }
        return (
            false,
            "TEMPORARY_TECHNICAL_FAILURE",
            "Workflow reconciliation is temporarily unavailable: \(error.localizedDescription)"
        )
    }

    func dispatchTailMismatch(enrolledRegistration: String?) -> Bool {
        guard let dispatch = state.activeDispatch else { return false }
        let enrolled = Self.normalizedTail(enrolledRegistration ?? "")
        guard !enrolled.isEmpty else { return false }
        return Self.normalizedTail(dispatch.tailNumber) != enrolled
    }

    @discardableResult
    func repairDispatchAircraftAlignment(selectedAircraft: CockpitAircraft?) -> Bool {
        guard let aircraft = selectedAircraft else {
            lastError = "Assign the enrolled aircraft in Admin before retrying upload."
            return false
        }
        let enrolledTail = aircraft.registration.trimmingCharacters(in: .whitespacesAndNewlines).uppercased()
        guard !enrolledTail.isEmpty else {
            lastError = "Enrolled aircraft registration is missing."
            return false
        }
        guard state.activeDispatch != nil else {
            lastError = "No active Dispatch is available to repair."
            return false
        }
        if let dispatch = state.activeDispatch,
           Self.normalizedTail(dispatch.tailNumber) == Self.normalizedTail(enrolledTail),
           dispatch.aircraftID == aircraft.id {
            return true
        }
        mutate {
            guard var dispatch = $0.activeDispatch else { return }
            dispatch.tailNumber = enrolledTail
            dispatch.aircraftID = aircraft.id
            dispatch.modifiedAt = Date()
            $0.activeDispatch = dispatch
            for index in $0.uploadComponents.indices {
                guard $0.uploadComponents[index].componentType == "dispatch_metadata" else { continue }
                if $0.uploadComponents[index].state == .failed || $0.uploadComponents[index].state == .needsUserAction {
                    $0.uploadComponents[index].state = .queued
                    $0.uploadComponents[index].lastError = ""
                    $0.uploadComponents[index].progress = 0
                }
            }
        }
        return true
    }

    func requeueFailedUploads(componentTypes: Set<String>? = nil) {
        mutate {
            let includesDispatch = componentTypes == nil || componentTypes?.contains("dispatch_metadata") == true
            if includesDispatch {
                _ = Self.repairStaleDispatchConsents(in: &$0)
            }
            for index in $0.uploadComponents.indices {
                let component = $0.uploadComponents[index]
                guard component.state == .failed || component.state == .needsUserAction else { continue }
                if let componentTypes, !componentTypes.contains(component.componentType) { continue }
                $0.uploadComponents[index].state = .queued
                $0.uploadComponents[index].lastError = ""
                $0.uploadComponents[index].progress = 0
            }
        }
    }

    func requeueFailedUploads(forFlightRecordID flightRecordID: String) {
        mutate {
            guard $0.activeFlightRecord?.id == flightRecordID else { return }
            for index in $0.uploadComponents.indices {
                guard $0.uploadComponents[index].state == .failed
                    || $0.uploadComponents[index].state == .needsUserAction else {
                    continue
                }
                $0.uploadComponents[index].state = .queued
                $0.uploadComponents[index].lastError = ""
                $0.uploadComponents[index].progress = 0
            }
        }

        guard let archiveIndex = archives.firstIndex(where: { $0.flightRecordID == flightRecordID }) else {
            return
        }
        var updated = archives
        var changed = false
        for componentIndex in updated[archiveIndex].uploadComponents.indices {
            guard updated[archiveIndex].uploadComponents[componentIndex].state == .failed
                || updated[archiveIndex].uploadComponents[componentIndex].state == .needsUserAction else {
                continue
            }
            updated[archiveIndex].uploadComponents[componentIndex].state = .queued
            updated[archiveIndex].uploadComponents[componentIndex].lastError = ""
            updated[archiveIndex].uploadComponents[componentIndex].progress = 0
            changed = true
        }
        guard changed else { return }
        updated[archiveIndex].status = .uploadPending
        do {
            try saveArchives(updated)
            archives = updated
            lastError = ""
        } catch {
            lastError = "Could not requeue archived flight uploads: \(error.localizedDescription)"
        }
    }

    func archiveExportURL(id: String) throws -> URL {
        guard let archive = archives.first(where: { $0.id == id }) else {
            throw CocoaError(.fileNoSuchFile)
        }
        let directory = FileManager.default.temporaryDirectory.appendingPathComponent("IPCA-CVR-Exports", isDirectory: true)
        try FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
        let url = directory.appendingPathComponent("IPCA-CVR-\(archive.flightRecordID).json")
        try encoder.encode(archive).write(to: url, options: [.atomic])
        return url
    }

    func resetForNextFlightIfComplete(archiveCompletedWorkflow: Bool = true) {
        guard let flightRecord = state.activeFlightRecord,
              flightRecord.endingHobbs != nil,
              flightRecord.endingTacho != nil else {
            lastError = "Check-In must be saved locally before opening the next flight."
            return
        }
        if archiveCompletedWorkflow {
            guard archiveActiveWorkflow() else { return }
        }

        mutate {
            $0.activeDispatch = nil
            $0.activeFlightRecord = nil
            $0.consents = []
            $0.recorderVerifications = []
            $0.flightEvents = []
            $0.flightLegs = []
            $0.uploadComponents = []
            $0.discrepancies = []
            $0.selectedTab = .scheduled
        }
    }

    @discardableResult
    func finishEndedFlightLocally() -> Bool {
        guard let flightRecord = state.activeFlightRecord,
              flightRecord.endingHobbs != nil,
              flightRecord.endingTacho != nil else {
            lastError = "Ending Hobbs and Tacho are required before finishing the flight."
            return false
        }
        guard archiveActiveWorkflow() else { return false }
        mutate {
            $0.activeDispatch = nil
            $0.activeFlightRecord = nil
            $0.consents = []
            $0.recorderVerifications = []
            $0.flightEvents = []
            $0.flightLegs = []
            $0.uploadComponents = []
            $0.discrepancies = []
            $0.selectedTab = .log
        }
        return true
    }

    func completeSimulationFlight() {
        guard let flightRecord = state.activeFlightRecord else {
            lastError = "No active flight record to complete in simulation."
            return
        }
        let now = Date()
        mutate {
            for index in $0.uploadComponents.indices where $0.uploadComponents[index].flightRecordID == flightRecord.id {
                $0.uploadComponents[index].state = .serverVerified
                $0.uploadComponents[index].serverReceiptID = "simulation-local"
                $0.uploadComponents[index].serverVerificationAt = now
                $0.uploadComponents[index].lastError = ""
                $0.uploadComponents[index].progress = 1
            }
            if var record = $0.activeFlightRecord {
                record.status = .complete
                record.updatedAt = now
                $0.activeFlightRecord = record
            }
        }
    }

    @discardableResult
    func finishSimulationDemo(clearAvionicsSimulation: () -> Void) -> Bool {
        if state.activeFlightRecord == nil {
            clearAvionicsSimulation()
            return true
        }
        completeSimulationFlight()
        guard let flightRecord = state.activeFlightRecord else {
            clearAvionicsSimulation()
            return true
        }
        let components = state.uploadComponents.filter { $0.flightRecordID == flightRecord.id }
        if components.isEmpty {
            lastError = "Complete Dispatch and post-flight verification before finishing the simulation demo."
            return false
        }
        resetForNextFlightIfComplete(archiveCompletedWorkflow: false)
        clearAvionicsSimulation()
        return state.activeFlightRecord == nil
    }

    func resetSimulationWorkflow(clearAvionicsSimulation: () -> Void) {
        mutate {
            $0.activeDispatch = nil
            $0.activeFlightRecord = nil
            $0.consents = []
            $0.recorderVerifications = []
            $0.flightEvents = []
            $0.flightLegs = []
            $0.uploadComponents = []
            $0.discrepancies = []
            $0.selectedTab = .scheduled
        }
        clearAvionicsSimulation()
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
                    !state.consents.contains {
                        $0.dispatchID == dispatch.id
                            && $0.dispatchVersion == dispatch.version
                            && $0.personName == assignment.personName
                            && $0.crewRole == assignment.role
                            && $0.consentResult
                    }
                }
                .map { "\($0.role.label.uppercased()) CONSENT REQUIRED" }
            items.append(contentsOf: consentMissing)
        }
        return Array(Set(items)).sorted()
    }

    @discardableResult
    private func mutate(_ update: (inout CVRWorkflowState) -> Void) -> Bool {
        var candidate = state
        update(&candidate)
        candidate.updatedAt = Date()
        do {
            let url = try storeURL()
            let data = try encoder.encode(candidate)
            try data.write(to: url, options: [.atomic])
            _ = try decoder.decode(CVRWorkflowState.self, from: Data(contentsOf: url))
            state = candidate
            lastError = ""
            return true
        } catch {
            lastError = "Workflow save failed; the change was not accepted: \(error.localizedDescription)"
            return false
        }
    }

    private func updateComponentAtomically(
        id: String,
        update: (inout CVRUploadComponentRecord) -> Void,
        validation: (CVRUploadComponentRecord) -> Bool = { _ in true }
    ) -> Bool {
        if let index = state.uploadComponents.firstIndex(where: { $0.id == id }) {
            var component = state.uploadComponents[index]
            update(&component)
            guard validation(component) else { return false }
            return mutate {
                guard let currentIndex = $0.uploadComponents.firstIndex(where: { $0.id == id }) else { return }
                $0.uploadComponents[currentIndex] = component
            }
        }
        guard let archiveIndex = archives.firstIndex(where: {
            $0.uploadComponents.contains(where: { $0.id == id })
        }), let componentIndex = archives[archiveIndex].uploadComponents.firstIndex(where: { $0.id == id }) else {
            return false
        }
        var updated = archives
        update(&updated[archiveIndex].uploadComponents[componentIndex])
        guard validation(updated[archiveIndex].uploadComponents[componentIndex]) else { return false }
        updated[archiveIndex].status = updated[archiveIndex].uploadComponents.allSatisfy {
            $0.state == .serverVerified
        } ? .serverVerified : .uploadPending
        do {
            try saveArchives(updated)
            archives = updated
            lastError = ""
            return true
        } catch {
            lastError = "Could not durably persist workflow upload metadata: \(error.localizedDescription)"
            return false
        }
    }

    private static func applyVerifiedMetadata(
        to component: inout CVRUploadComponentRecord,
        receiptID: String,
        payloadSHA256: String,
        verificationAt: Date,
        canonicalIdentifiers: [String: String]
    ) {
        component.serverReceiptID = receiptID
        component.authoritativePayloadSHA256 = payloadSHA256
        component.serverVerificationAt = verificationAt
        component.canonicalIdentifiers = canonicalIdentifiers
        component.serverID = primaryServerIdentifier(
            componentType: component.componentType,
            canonicalIdentifiers: canonicalIdentifiers
        )
        component.reconciliationRequired = false
        component.state = .serverVerified
        component.progress = 1
        component.lastError = ""
        component.errorCode = nil
        component.retryable = false
        component.userActionRequired = false
        component.lastAttemptAt = Date()
    }

    private static func primaryServerIdentifier(
        componentType: String,
        canonicalIdentifiers: [String: String]
    ) -> String? {
        switch componentType {
        case "dispatch_metadata":
            canonicalIdentifiers["server_dispatch_id"]
        case "flight_events":
            canonicalIdentifiers["server_event_id"] ?? canonicalIdentifiers["event_server_id"]
        case "recorder_verification":
            canonicalIdentifiers["server_verification_id"] ?? canonicalIdentifiers["verification_server_id"]
        case "flight_record_closure":
            canonicalIdentifiers["server_closure_id"] ?? canonicalIdentifiers["closure_server_id"]
        default:
            nil
        }
    }

    private static func hasCompleteVerificationMetadata(_ component: CVRUploadComponentRecord) -> Bool {
        guard component.serverReceiptID?.isEmpty == false,
              component.authoritativePayloadSHA256?.isEmpty == false,
              component.serverVerificationAt != nil,
              let identifiers = component.canonicalIdentifiers else {
            return false
        }
        let common = ["dispatch_uuid", "flight_record_uuid"]
        guard common.allSatisfy({ identifiers[$0]?.isEmpty == false }) else { return false }
        switch component.componentType {
        case "dispatch_metadata":
            return ["server_dispatch_id", "dispatch_version"].allSatisfy {
                identifiers[$0]?.isEmpty == false
            }
        case "flight_events":
            return ["server_evidence_batch_id", "server_batch_uuid", "component_uuid",
                    "component_type", "event_uuid"].allSatisfy {
                identifiers[$0]?.isEmpty == false
            } && (identifiers["server_event_id"]?.isEmpty == false
                || identifiers["event_server_id"]?.isEmpty == false)
        case "recorder_verification":
            return ["server_evidence_batch_id", "server_batch_uuid", "component_uuid",
                    "component_type", "verification_uuid"].allSatisfy {
                identifiers[$0]?.isEmpty == false
            } && (identifiers["server_verification_id"]?.isEmpty == false
                || identifiers["verification_server_id"]?.isEmpty == false)
        case "flight_record_closure":
            return ["server_evidence_batch_id", "server_batch_uuid", "component_uuid",
                    "component_type", "closure_uuid"].allSatisfy {
                identifiers[$0]?.isEmpty == false
            } && (identifiers["server_closure_id"]?.isEmpty == false
                || identifiers["closure_server_id"]?.isEmpty == false)
        default:
            return true
        }
    }

    private func updateComponent(
        _ component: inout CVRUploadComponentRecord,
        state: CVRUploadComponentState,
        progress: Double,
        lastError: String,
        serverReceiptID: String?,
        errorCode: String? = nil,
        retryable: Bool? = nil,
        userActionRequired: Bool? = nil,
        requestID: String? = nil
    ) {
        let previousState = component.state
        if state == .serverVerified {
            guard let serverReceiptID,
                  !serverReceiptID.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty else {
                component.state = .failed
                component.lastError = "Server verification receipt is missing."
                component.lastAttemptAt = Date()
                return
            }
            component.serverVerificationAt = Date()
            component.serverReceiptID = serverReceiptID
        }
        if state == .uploading && previousState != .uploading {
            component.attemptCount += 1
        }
        component.state = state
        component.progress = min(max(progress, 0), 1)
        component.lastError = lastError
        component.lastAttemptAt = Date()
        component.errorCode = errorCode
        component.retryable = retryable
        component.userActionRequired = userActionRequired
        component.requestID = requestID
    }

    private func archiveActiveWorkflow() -> Bool {
        guard let dispatch = state.activeDispatch,
              let flightRecord = state.activeFlightRecord else {
            lastError = "Cannot archive an incomplete workflow."
            return false
        }
        if archives.contains(where: { $0.flightRecordID == flightRecord.id }) {
            return true
        }
        let components = state.uploadComponents.filter { $0.flightRecordID == flightRecord.id }
        let archive = CVRWorkflowArchiveRecord(
            id: UUID().uuidString,
            schemaVersion: 2,
            flightRecordID: flightRecord.id,
            dispatch: dispatch,
            flightRecord: flightRecord,
            consents: state.consents.filter { $0.dispatchID == dispatch.id },
            recorderVerifications: state.recorderVerifications.filter { $0.flightRecordID == flightRecord.id },
            flightEvents: state.flightEvents.filter { $0.flightRecordID == flightRecord.id },
            flightLegs: state.flightLegs.filter { $0.flightRecordID == flightRecord.id },
            uploadComponents: components,
            discrepancies: state.discrepancies.filter { $0.flightRecordID == flightRecord.id },
            recordingSessionIDs: [flightRecord.recordingSessionID].compactMap { $0 },
            archivedAt: Date(),
            appVersion: Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String ?? "unknown",
            status: !components.isEmpty && components.allSatisfy { $0.state == .serverVerified }
                ? .serverVerified
                : .uploadPending
        )
        do {
            var updated = archives
            updated.append(archive)
            try saveArchives(updated)
            archives = updated
            return true
        } catch {
            lastError = "Flight history archive failed. NEXT FLIGHT was blocked: \(error.localizedDescription)"
            return false
        }
    }

    private func latestClosedCarryover(for registration: String) -> (
        flightRecordID: String,
        endingHobbs: Double,
        endingTacho: Double,
        fuelRemaining: String,
        oilPercentage: Int?,
        oilQuantity: Double?,
        oilUnit: String?
    )? {
        let normalizedRegistration = registration.trimmingCharacters(in: .whitespacesAndNewlines).uppercased()
        return archives
            .filter {
                $0.dispatch.tailNumber.trimmingCharacters(in: .whitespacesAndNewlines).uppercased() == normalizedRegistration
                    && $0.flightRecord.endingHobbs != nil
                    && $0.flightRecord.endingTacho != nil
                    && !($0.flightRecord.fuelRemaining ?? "").trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
            }
            .sorted { $0.archivedAt > $1.archivedAt }
            .compactMap { archive in
                guard let endingHobbs = archive.flightRecord.endingHobbs,
                      let endingTacho = archive.flightRecord.endingTacho,
                      let fuelRemaining = archive.flightRecord.fuelRemaining else {
                    return nil
                }
                return (
                    archive.flightRecordID,
                    endingHobbs,
                    endingTacho,
                    fuelRemaining,
                    archive.flightRecord.endingOilPercentage,
                    archive.flightRecord.effectiveEndingOilQuantity,
                    archive.flightRecord.effectiveEndingOilUnit
                )
            }
            .first
    }

    @discardableResult
    private func ensureDispatchUploadComponent() -> Bool {
        guard let dispatch = state.activeDispatch,
              let flightRecord = state.activeFlightRecord,
              flightRecord.dispatchID == dispatch.id else {
            return false
        }
        let componentID = "dispatch-\(dispatch.id)-v\(dispatch.version)"
        guard !state.uploadComponents.contains(where: {
            $0.componentType == "dispatch_metadata" && $0.flightRecordID == flightRecord.id
        }) else {
            return false
        }
        state.uploadComponents.append(CVRUploadComponentRecord(
            id: componentID,
            serverID: nil,
            flightRecordID: flightRecord.id,
            componentType: "dispatch_metadata",
            localFilePath: nil,
            sha256: nil,
            byteCount: nil,
            state: .queued,
            progress: 0,
            attemptCount: 0,
            lastError: "",
            lastAttemptAt: nil,
            serverVerificationAt: nil,
            serverReceiptID: nil
        ))
        state.updatedAt = Date()
        return true
    }

    private func recoverInterruptedActiveUploads() -> Bool {
        var changed = false
        for index in state.uploadComponents.indices where state.uploadComponents[index].state == .uploading {
            state.uploadComponents[index].state = .queued
            if state.uploadComponents[index].componentType != "garmin_csv" {
                state.uploadComponents[index].reconciliationRequired = true
            }
            state.uploadComponents[index].lastError = "Upload was interrupted and has been queued for recovery."
            changed = true
        }
        return changed
    }

    private func recoverIncompleteActiveVerificationMetadata() -> Bool {
        var changed = false
        for index in state.uploadComponents.indices {
            let component = state.uploadComponents[index]
            guard component.componentType != "garmin_csv" else { continue }
            if component.state == .serverVerified && !Self.hasCompleteVerificationMetadata(component) {
                state.uploadComponents[index].state = .queued
                state.uploadComponents[index].reconciliationRequired = true
                state.uploadComponents[index].lastError =
                    "Server verification metadata is incomplete; queued for authoritative reconciliation."
                changed = true
            } else if component.state == .queued,
                      component.attemptCount > 0,
                      !Self.hasCompleteVerificationMetadata(component) {
                state.uploadComponents[index].reconciliationRequired = true
                changed = true
            }
        }
        return changed
    }

    private func ensureEvidenceUploadComponents() -> Bool {
        guard let flightRecord = state.activeFlightRecord else { return false }
        var changed = false
        for event in state.flightEvents where event.flightRecordID == flightRecord.id {
            let path = "event:\(event.id)"
            if !state.uploadComponents.contains(where: { $0.componentType == "flight_events" && $0.localFilePath == path }) {
                state.uploadComponents.append(eventUploadComponent(event))
                changed = true
            }
        }
        if let verification = state.recorderVerifications.last(where: { $0.flightRecordID == flightRecord.id }),
           !state.uploadComponents.contains(where: { $0.componentType == "recorder_verification" && $0.localFilePath == "verification:\(verification.id)" }) {
            state.uploadComponents.append(evidenceComponent(
                flightRecordID: flightRecord.id,
                type: "recorder_verification",
                evidenceID: verification.id
            ))
            changed = true
        }
        if (flightRecord.status == .awaitingGarmin || flightRecord.status == .awaitingUpload || flightRecord.status == .complete),
           flightClosureIsComplete(flightRecord),
           !state.uploadComponents.contains(where: { $0.componentType == "flight_record_closure" && $0.flightRecordID == flightRecord.id }) {
            state.uploadComponents.append(evidenceComponent(
                flightRecordID: flightRecord.id,
                type: "flight_record_closure",
                evidenceID: flightRecord.id
            ))
            changed = true
        }
        return changed
    }

    private func reconcileClosureUploadComponents() -> Bool {
        guard let flightRecord = state.activeFlightRecord else { return false }
        var changed = false
        for index in state.uploadComponents.indices {
            guard state.uploadComponents[index].componentType == "flight_record_closure",
                  state.uploadComponents[index].flightRecordID == flightRecord.id else { continue }
            if flightClosureIsComplete(flightRecord) {
                if state.uploadComponents[index].state == .needsUserAction,
                   state.uploadComponents[index].lastError.contains("Ending Hobbs") {
                    state.uploadComponents[index].state = .queued
                    state.uploadComponents[index].lastError = ""
                    changed = true
                }
                continue
            }
            if state.uploadComponents[index].state == .queued || state.uploadComponents[index].state == .uploading {
                state.uploadComponents[index].state = .needsUserAction
                state.uploadComponents[index].lastError = "Ending Hobbs and Ending Tacho are required before closure upload."
                changed = true
            }
        }
        return changed
    }

    private func eventUploadComponent(_ event: CVRFlightEventRecord) -> CVRUploadComponentRecord {
        evidenceComponent(
            flightRecordID: event.flightRecordID,
            type: "flight_events",
            evidenceID: event.id
        )
    }

    private func evidenceComponent(
        flightRecordID: String,
        type: String,
        evidenceID: String
    ) -> CVRUploadComponentRecord {
        let prefix = type == "flight_events" ? "event" : (type == "recorder_verification" ? "verification" : "closure")
        return CVRUploadComponentRecord(
            id: UUID().uuidString,
            serverID: nil,
            flightRecordID: flightRecordID,
            componentType: type,
            localFilePath: "\(prefix):\(evidenceID)",
            sha256: nil,
            byteCount: nil,
            state: .queued,
            progress: 0,
            attemptCount: 0,
            lastError: "",
            lastAttemptAt: nil,
            serverVerificationAt: nil,
            serverReceiptID: nil
        )
    }

    private func appendFlightEvent(
        flightRecord: CVRIncompleteFlightRecord,
        eventType: String,
        source: String,
        creationMethod: String,
        gpsSample: GPSSample?,
        metadata: [String: String]? = nil
    ) {
        let event = makeFlightEvent(
            flightRecord: flightRecord,
            eventType: eventType,
            source: source,
            creationMethod: creationMethod,
            gpsSample: gpsSample,
            metadata: metadata
        )
        mutate {
            $0.flightEvents.append(event)
            $0.uploadComponents.append(eventUploadComponent(event))
        }
    }

    private func makeFlightEvent(
        flightRecord: CVRIncompleteFlightRecord,
        eventType: String,
        source: String,
        creationMethod: String,
        gpsSample: GPSSample?,
        metadata: [String: String]? = nil
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
            audioOffset: flightRecord.recordingStartedAt.map { max(0, now.timeIntervalSince($0)) },
            latitude: gpsSample?.latitude,
            longitude: gpsSample?.longitude,
            altitude: gpsSample?.altitude,
            groundSpeed: gpsSample?.speedKnots,
            source: source,
            confidence: 1.0,
            creationMethod: creationMethod,
            userIdentity: "local_cvr_unit",
            metadata: metadata
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

    private func loadArchives() throws -> [String] {
        let url = try archivesURL()
        guard FileManager.default.fileExists(atPath: url.path) else {
            archives = []
            archiveRewriteSafe = true
            return []
        }
        let sourceData = try Data(contentsOf: url)
        let rawRecords = try CVRArchiveRecordRecovery.records(in: sourceData)
        var recovered: [CVRWorkflowArchiveRecord] = []
        var diagnostics: [String] = []
        var allDamagedRecordsQuarantined = true
        for (recordIndex, rawRecord) in rawRecords.enumerated() {
            do {
                recovered.append(try decoder.decode(CVRWorkflowArchiveRecord.self, from: rawRecord))
            } catch {
                do {
                    let quarantineURL = try quarantineArchiveRecord(
                        rawRecord,
                        recordIndex: recordIndex,
                        decodingError: error
                    )
                    diagnostics.append(
                        "Historical archive record \(recordIndex + 1) was quarantined at \(quarantineURL.lastPathComponent): \(error.localizedDescription)"
                    )
                } catch {
                    allDamagedRecordsQuarantined = false
                    diagnostics.append(
                        "Historical archive record \(recordIndex + 1) is incompatible and could not be quarantined: \(error.localizedDescription)"
                    )
                }
            }
        }
        archiveRewriteSafe = allDamagedRecordsQuarantined
        var changed = false
        for archiveIndex in recovered.indices {
            let closureIsComplete = Self.archivedClosureIsComplete(
                recovered[archiveIndex].flightRecord,
                dispatch: recovered[archiveIndex].dispatch
            )
            for componentIndex in recovered[archiveIndex].uploadComponents.indices
            {
                let component = recovered[archiveIndex].uploadComponents[componentIndex]
                let componentState = component.state
                if componentState == .uploading {
                    recovered[archiveIndex].uploadComponents[componentIndex].state = .queued
                    if component.componentType != "garmin_csv" {
                        recovered[archiveIndex].uploadComponents[componentIndex].reconciliationRequired = true
                    }
                    recovered[archiveIndex].uploadComponents[componentIndex].lastError = "Upload was interrupted and has been queued for recovery."
                    recovered[archiveIndex].status = .uploadPending
                    changed = true
                } else if component.componentType != "garmin_csv",
                          componentState == .serverVerified,
                          !Self.hasCompleteVerificationMetadata(component) {
                    recovered[archiveIndex].uploadComponents[componentIndex].state = .queued
                    recovered[archiveIndex].uploadComponents[componentIndex].reconciliationRequired = true
                    recovered[archiveIndex].uploadComponents[componentIndex].lastError =
                        "Server verification metadata is incomplete; queued for authoritative reconciliation."
                    recovered[archiveIndex].status = .uploadPending
                    changed = true
                } else if component.componentType != "garmin_csv",
                          componentState == .queued,
                          component.attemptCount > 0,
                          !Self.hasCompleteVerificationMetadata(component) {
                    recovered[archiveIndex].uploadComponents[componentIndex].reconciliationRequired = true
                    recovered[archiveIndex].status = .uploadPending
                    changed = true
                } else if Self.isLegacyAdvisoryDispatchFailure(component) {
                    recovered[archiveIndex].uploadComponents[componentIndex].state = .queued
                    recovered[archiveIndex].uploadComponents[componentIndex].lastError = ""
                    recovered[archiveIndex].uploadComponents[componentIndex].progress = 0
                    recovered[archiveIndex].status = .uploadPending
                    changed = true
                } else if component.componentType == "flight_record_closure",
                          (componentState == .needsUserAction
                            || (componentState == .failed
                                && component.lastError.localizedCaseInsensitiveContains("fuel_remaining"))),
                          closureIsComplete {
                    recovered[archiveIndex].uploadComponents[componentIndex].state = .queued
                    recovered[archiveIndex].uploadComponents[componentIndex].lastError = ""
                    recovered[archiveIndex].uploadComponents[componentIndex].progress = 0
                    recovered[archiveIndex].status = .uploadPending
                    changed = true
                }
            }
        }
        let damagedRecordCount = rawRecords.count - recovered.count
        if (changed || damagedRecordCount > 0) && allDamagedRecordsQuarantined {
            try saveArchives(recovered)
        }
        archives = recovered
        return diagnostics
    }

    private func quarantineArchiveRecord(
        _ rawRecord: Data,
        recordIndex: Int,
        decodingError: Error
    ) throws -> URL {
        let directory = try archiveQuarantineDirectory()
        let digest = SHA256.hash(data: rawRecord).map { String(format: "%02x", $0) }.joined()
        let evidenceURL = directory.appendingPathComponent("archive-record-\(digest).json")
        if !FileManager.default.fileExists(atPath: evidenceURL.path) {
            try rawRecord.write(to: evidenceURL, options: [.atomic])
        }
        let diagnosticURL = directory.appendingPathComponent("archive-record-\(digest).diagnostic.txt")
        if !FileManager.default.fileExists(atPath: diagnosticURL.path) {
            let diagnostic = """
            record_index=\(recordIndex)
            quarantined_at_utc=\(ISO8601DateFormatter().string(from: Date()))
            sha256=\(digest)
            decoding_error=\(decodingError.localizedDescription)
            """
            try Data(diagnostic.utf8).write(to: diagnosticURL, options: [.atomic])
        }
        return evidenceURL
    }

    private static func archivedClosureIsComplete(
        _ flightRecord: CVRIncompleteFlightRecord,
        dispatch: CVRDispatchRecord
    ) -> Bool {
        guard let endingHobbs = flightRecord.endingHobbs,
              let endingTacho = flightRecord.endingTacho,
              endingHobbs >= (dispatch.startingHobbs ?? 0),
              endingTacho >= (dispatch.startingTacho ?? 0) else {
            return false
        }
        return true
    }

    private static func requeueLegacyAdvisoryDispatchFailure(in workflow: inout CVRWorkflowState) -> Bool {
        var changed = false
        for index in workflow.uploadComponents.indices
        where isLegacyAdvisoryDispatchFailure(workflow.uploadComponents[index]) {
            workflow.uploadComponents[index].state = .queued
            workflow.uploadComponents[index].lastError = ""
            workflow.uploadComponents[index].progress = 0
            changed = true
        }
        return changed
    }

    private static func isLegacyAdvisoryDispatchFailure(_ component: CVRUploadComponentRecord) -> Bool {
        guard component.componentType == "dispatch_metadata",
              component.state == .failed || component.state == .needsUserAction else {
            return false
        }
        let error = component.lastError.lowercased()
        return error.contains("scheduled session times do not match the dispatch")
            || error.contains("hobbs discrepancy")
            || error.contains("tacho discrepancy")
            || (error.contains("fuel") && error.contains("20%"))
            || (error.contains("oil") && error.contains("20%"))
    }

    private func saveArchives(_ records: [CVRWorkflowArchiveRecord]) throws {
        guard archiveRewriteSafe else {
            throw CocoaError(.fileWriteNoPermission, userInfo: [
                NSLocalizedDescriptionKey:
                    "Archive updates are paused because damaged evidence could not be quarantined safely."
            ])
        }
        let url = try archivesURL()
        try encoder.encode(records).write(to: url, options: [.atomic])
        let verification = try decoder.decode([CVRWorkflowArchiveRecord].self, from: Data(contentsOf: url))
        guard verification.map(\.id) == records.map(\.id) else {
            throw CocoaError(.fileWriteUnknown)
        }
    }

    private func archiveQuarantineDirectory() throws -> URL {
        let base = try FileManager.default.url(
            for: .applicationSupportDirectory,
            in: .userDomainMask,
            appropriateFor: nil,
            create: true
        )
        let directory = base.appendingPathComponent(
            "IPCACVRUnit/ArchiveQuarantine",
            isDirectory: true
        )
        try FileManager.default.createDirectory(
            at: directory,
            withIntermediateDirectories: true
        )
        return directory
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

    private func archivesURL() throws -> URL {
        let base = try FileManager.default.url(
            for: .applicationSupportDirectory,
            in: .userDomainMask,
            appropriateFor: nil,
            create: true
        )
        let dir = base.appendingPathComponent("IPCACVRUnit", isDirectory: true)
        try FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        return dir.appendingPathComponent("workflow-archives.json")
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

    private static func repairStaleDispatchConsents(in workflow: inout CVRWorkflowState) -> Bool {
        guard var dispatch = workflow.activeDispatch,
              workflow.uploadComponents.contains(where: {
                  $0.componentType == "dispatch_metadata"
                      && ($0.state == .failed || $0.state == .needsUserAction)
                      && $0.lastError.localizedCaseInsensitiveContains("current-version consent")
              }) else {
            return false
        }

        var repairedConsents = workflow.consents
        for assignment in dispatch.crew {
            if repairedConsents.contains(where: {
                $0.dispatchID == dispatch.id
                    && $0.dispatchVersion == dispatch.version
                    && $0.personName == assignment.personName
                    && $0.crewRole == assignment.role
                    && $0.consentResult
            }) {
                continue
            }
            guard let accepted = workflow.consents.last(where: {
                $0.dispatchID == dispatch.id
                    && $0.personName == assignment.personName
                    && $0.crewRole == assignment.role
                    && $0.consentResult
            }) else {
                return false
            }
            repairedConsents.append(CVRConsentRecord(
                id: UUID().uuidString,
                personID: accepted.personID,
                personName: accepted.personName,
                crewRole: accepted.crewRole,
                consentResult: true,
                timestamp: accepted.timestamp,
                deviceID: accepted.deviceID,
                dispatchID: dispatch.id,
                dispatchVersion: dispatch.version,
                consentTextVersion: accepted.consentTextVersion,
                appVersion: accepted.appVersion
            ))
        }

        guard hasRequiredConsents(dispatch: dispatch, consents: repairedConsents) else {
            return false
        }
        workflow.consents = repairedConsents
        dispatch.consentStatus = "complete"
        workflow.activeDispatch = dispatch
        for index in workflow.uploadComponents.indices {
            guard workflow.uploadComponents[index].componentType == "dispatch_metadata",
                  workflow.uploadComponents[index].lastError.localizedCaseInsensitiveContains("current-version consent"),
                  workflow.uploadComponents[index].state == .failed
                      || workflow.uploadComponents[index].state == .needsUserAction else {
                continue
            }
            workflow.uploadComponents[index].state = .queued
            workflow.uploadComponents[index].progress = 0
            workflow.uploadComponents[index].lastError = "Recovered consent version metadata; Dispatch is queued for retry."
        }
        return true
    }

    private static func hasRequiredConsents(dispatch: CVRDispatchRecord, consents: [CVRConsentRecord]) -> Bool {
        guard !dispatch.crew.isEmpty else { return false }
        return dispatch.crew.allSatisfy { assignment in
            consents.contains {
                $0.dispatchID == dispatch.id
                    && $0.dispatchVersion == dispatch.version
                    && $0.personName == assignment.personName
                    && $0.crewRole == assignment.role
                    && $0.consentResult
            }
        }
    }

    private static func materialSignature(_ dispatch: CVRDispatchRecord) -> String {
        let crewSignature = dispatch.crew
            .map { assignment in assignment.personName + ":" + assignment.role.rawValue }
            .joined(separator: "|")
        let values: [String] = [
            dispatch.tailNumber,
            String(dispatch.aircraftID ?? 0),
            dispatch.missionCode,
            dispatch.startingHobbs.map { String(format: "%.4f", $0) } ?? "",
            dispatch.startingTacho.map { String(format: "%.4f", $0) } ?? "",
            dispatch.fuelOnboard.trimmingCharacters(in: .whitespacesAndNewlines),
            dispatch.effectiveStartingOilQuantity.map { String(format: "%.4f", $0) } ?? "",
            dispatch.effectiveStartingOilUnit,
            dispatch.refueledSincePreviousFlight.map(String.init) ?? "",
            dispatch.oilServicedSincePreviousFlight.map(String.init) ?? "",
            crewSignature
        ]
        return values.joined(separator: "#")
    }

    private static func crewRole(from value: String) -> CVRCrewRole {
        let normalized = value
            .trimmingCharacters(in: .whitespacesAndNewlines)
            .replacingOccurrences(of: "-", with: "")
            .replacingOccurrences(of: "_", with: "")
            .lowercased()
        return CVRCrewRole.allCases.first {
            $0.rawValue.replacingOccurrences(of: "_", with: "").lowercased() == normalized
                || $0.label.replacingOccurrences(of: " ", with: "").lowercased() == normalized
        } ?? .unknown
    }
}
