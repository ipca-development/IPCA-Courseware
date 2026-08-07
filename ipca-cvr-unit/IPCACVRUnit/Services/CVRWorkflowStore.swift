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
            if repairConsentFailuresInArchives() {
                diagnostics.append("Repaired Phase 3 operational consent for archived Dispatch uploads.")
            }
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
                   flightRecord.endingTacho != nil,
                   flightRecord.status != .awaitingAvionicsOff,
                   state.operationalSession?.awaitingAvionicsOffConfirmation != true {
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

    func createOrOpenLocalDispatch(
        selectedAircraft: CockpitAircraft?,
        cvrUnitID: String,
        beaconID: String,
        canonicalWriteEnabled: Bool = false
    ) {
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
            lastError = "Finish Check-In for the current leg before creating another Dispatch."
            return
        }

        let continuity = state.operationalSession
        let carryover = resolvedLegCarryover(for: registration)
        let dispatchID = UUID().uuidString
        // Local same-airport default (e.g. KTRM → KTRM). Blank destination must never be created.
        let homeAirport = CVROperationalIdentityLocal.normalizeAirport(selectedAircraft.homeAirport)
        guard !homeAirport.isEmpty else {
            lastError = "Enter the departure airport."
            return
        }
        var operationalIdentity: CVRLocalOperationalIdentity?
        if canonicalWriteEnabled {
            do {
                operationalIdentity = try CVROperationalIdentityLocal.createOfflineBundle(
                    organizationID: 1,
                    dispatchUUID: dispatchID,
                    organizationTimezoneIANA: TimeZone.current.identifier,
                    originAirport: homeAirport,
                    destinationAirport: homeAirport,
                    reservationUUID: continuity?.reservationUUID
                )
            } catch {
                CVROperationalIdentityLocal.logSanitized("offline_dispatch_identity_create_failed", fields: [
                    "error_class": String(describing: type(of: error)),
                    "tail": registration,
                ])
                lastError = "Unable to create the Dispatch. Please try again."
                return
            }
        }

        let dispatch = CVRDispatchRecord(
            id: dispatchID,
            serverDispatchID: nil,
            organizationID: 1,
            scheduledDate: Date(),
            scheduledStartTime: nil,
            scheduledEndTime: nil,
            tailNumber: registration,
            aircraftID: selectedAircraft.id,
            missionCode: "",
            plannedDepartureAirport: homeAirport,
            plannedDestinationAirport: homeAirport,
            crew: [],
            startingHobbs: carryover?.endingHobbs,
            startingTacho: carryover?.endingTacho,
            fuelOnboard: carryover?.fuelRemaining ?? "",
            oilPercentage: carryover?.oilPercentage,
            startingOilQuantity: carryover?.oilQuantity,
            startingOilUnit: carryover?.oilUnit ?? selectedAircraft.operationalConfig.oilUnit,
            dispatchSource: continuity?.engineSessionContinuityActive == true
                ? "transient_stop_carryover"
                : (carryover == nil ? "iphone_offline_local" : "previous_locally_closed_flight_carryover"),
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
            oilServicedSincePreviousFlight: nil,
            operationalIdentity: operationalIdentity
        )

        let persisted = mutate {
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
        if !persisted {
            if canonicalWriteEnabled {
                lastError = "Unable to create the Dispatch. Please try again."
                CVROperationalIdentityLocal.logSanitized("offline_dispatch_persist_failed", fields: [
                    "dispatch_uuid": dispatchID.lowercased(),
                    "error_class": "persist_failed",
                ])
            }
        }
    }

    /// Create a local multi-leg reservation (e.g. KTRM → KPSP → KBUR → KTRM) and open Dispatch for leg 1.
    func createLocalMultiLegReservation(
        airports: [String],
        selectedAircraft: CockpitAircraft?,
        cvrUnitID: String,
        beaconID: String,
        missionCode: String = "",
        canonicalWriteEnabled: Bool = false,
        reservationUUID: String? = nil,
        legUUIDs: [String]? = nil
    ) {
        guard let selectedAircraft else {
            lastError = "Aircraft configuration is required before creating a Dispatch."
            return
        }
        let normalizedAirports = airports.map { CVROperationalIdentityLocal.normalizeAirport($0) }
        guard normalizedAirports.count >= 2,
              normalizedAirports.allSatisfy({ !$0.isEmpty }) else {
            lastError = "Enter the departure airport and destination for each leg."
            return
        }
        guard normalizedAirports.allSatisfy({ CVRLocalDispatchDraft.isValidICAOIdentifier($0) }) else {
            lastError = "Airport code must be a valid ICAO identifier."
            return
        }
        let legCount = normalizedAirports.count - 1
        if let legUUIDs, legUUIDs.count != legCount {
            lastError = "Unable to create the Dispatch. Please try again."
            return
        }
        if state.activeDispatch != nil || state.activeFlightRecord != nil {
            lastError = "Finish Check-In for the current leg before creating another Dispatch."
            return
        }
        if state.engineSessionContinuityActive || !remainingOpenPlannedLegs.isEmpty {
            lastError = "Open the remaining planned leg, or Cancel Remaining Legs on Schedule, before creating a new Dispatch."
            return
        }

        let registration = selectedAircraft.registration
        let carryover = resolvedLegCarryover(for: registration)
        let dispatchIDs = (0..<legCount).map { _ in UUID().uuidString }
        var identities: [CVRLocalOperationalIdentity] = []
        var resolvedReservationUUID = reservationUUID.flatMap { CVROperationalIdentityLocal.normalizeUUID($0) }
            ?? UUID().uuidString.lowercased()
        if canonicalWriteEnabled {
            do {
                let minted = try CVROperationalIdentityLocal.createOfflineMultiLegBundles(
                    organizationID: 1,
                    reservationUUID: resolvedReservationUUID,
                    organizationTimezoneIANA: TimeZone.current.identifier,
                    airports: normalizedAirports,
                    dispatchUUIDs: dispatchIDs,
                    legUUIDs: legUUIDs
                )
                resolvedReservationUUID = minted.reservationUUID
                identities = minted.identities
            } catch {
                CVROperationalIdentityLocal.logSanitized("offline_multileg_identity_create_failed", fields: [
                    "error_class": String(describing: type(of: error)),
                    "tail": registration,
                ])
                lastError = "Unable to create the multi-leg Dispatch. Please try again."
                return
            }
        } else {
            identities = (0..<legCount).map { index in
                let legUUID: String
                if let provided = legUUIDs?[index],
                   let normalized = CVROperationalIdentityLocal.normalizeUUID(provided) {
                    legUUID = normalized
                } else {
                    legUUID = UUID().uuidString.lowercased()
                }
                return CVRLocalOperationalIdentity(
                    reservationUUID: resolvedReservationUUID,
                    legUUID: legUUID,
                    organizationID: 1,
                    reservationType: "flight_training",
                    activityDomain: "flight",
                    organizationTimezoneIANA: TimeZone.current.identifier,
                    originAirport: normalizedAirports[index],
                    destinationAirport: normalizedAirports[index + 1],
                    plannedStartAtUTC: nil,
                    plannedEndAtUTC: nil,
                    aliases: [],
                    linkageMethod: CVROperationalIdentityLocal.linkageOfflineCreate
                )
            }
        }

        let plannedLegs: [CVRPlannedLegRecord] = identities.enumerated().map { index, identity in
            CVRPlannedLegRecord(
                id: identity.legUUID,
                reservationUUID: resolvedReservationUUID,
                legUUID: identity.legUUID,
                sequenceNumber: index + 1,
                departureAirport: normalizedAirports[index],
                destinationAirport: normalizedAirports[index + 1],
                missionCode: missionCode,
                tailNumber: registration,
                schedulerRecordID: nil,
                plannedStartAt: Date(),
                plannedEndAt: nil,
                // Remains Scheduled until DISPATCH FLIGHT confirms the current leg.
                status: "planned"
            )
        }

        let firstIdentity = canonicalWriteEnabled ? identities.first : nil
        let dispatch = CVRDispatchRecord(
            id: dispatchIDs[0],
            serverDispatchID: nil,
            organizationID: 1,
            scheduledDate: Date(),
            scheduledStartTime: nil,
            scheduledEndTime: nil,
            tailNumber: registration,
            aircraftID: selectedAircraft.id,
            missionCode: missionCode,
            plannedDepartureAirport: normalizedAirports[0],
            plannedDestinationAirport: normalizedAirports[1],
            crew: [],
            startingHobbs: carryover?.endingHobbs,
            startingTacho: carryover?.endingTacho,
            fuelOnboard: carryover?.fuelRemaining ?? "",
            oilPercentage: carryover?.oilPercentage,
            startingOilQuantity: carryover?.oilQuantity,
            startingOilUnit: carryover?.oilUnit ?? selectedAircraft.operationalConfig.oilUnit,
            dispatchSource: "local_multileg_reservation",
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
            oilServicedSincePreviousFlight: nil,
            operationalIdentity: firstIdentity
        )

        let persisted = mutate {
            $0.operationalSession = CVROperationalSessionContext(
                reservationUUID: resolvedReservationUUID,
                engineSessionContinuityActive: false,
                plannedLegs: plannedLegs,
                currentLegIndex: 1,
                pendingCheckInMode: nil,
                carryoverHobbs: nil,
                carryoverTacho: nil,
                carryoverFuel: nil,
                carryoverOilPercentage: nil,
                carryoverOilQuantity: nil,
                carryoverOilUnit: nil,
                carryoverCrew: nil,
                awaitingAvionicsOffConfirmation: false,
                continuityEngineStartSynthesized: false,
                pendingSoftStartRecording: false
            )
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
        if !persisted {
            lastError = "Unable to create the multi-leg Dispatch. Please try again."
        }
    }

    func openDispatchFromScheduledSession(
        _ session: CVRScheduledSession,
        selectedAircraft: CockpitAircraft?,
        cvrUnitID: String,
        beaconID: String,
        isAudioRecording: Bool,
        canonicalWriteEnabled: Bool = false
    ) {
        openDispatchFromLeg(
            session: session,
            plannedLeg: nil,
            selectedAircraft: selectedAircraft,
            cvrUnitID: cvrUnitID,
            beaconID: beaconID,
            isAudioRecording: isAudioRecording,
            canonicalWriteEnabled: canonicalWriteEnabled
        )
    }

    func openDispatchFromPlannedLeg(
        _ plannedLeg: CVRPlannedLegRecord,
        selectedAircraft: CockpitAircraft?,
        cvrUnitID: String,
        beaconID: String,
        isAudioRecording: Bool,
        canonicalWriteEnabled: Bool = false
    ) {
        openDispatchFromLeg(
            session: nil,
            plannedLeg: plannedLeg,
            selectedAircraft: selectedAircraft,
            cvrUnitID: cvrUnitID,
            beaconID: beaconID,
            isAudioRecording: isAudioRecording,
            canonicalWriteEnabled: canonicalWriteEnabled
        )
    }

    private func openDispatchFromLeg(
        session: CVRScheduledSession?,
        plannedLeg: CVRPlannedLegRecord?,
        selectedAircraft: CockpitAircraft?,
        cvrUnitID: String,
        beaconID: String,
        isAudioRecording: Bool,
        canonicalWriteEnabled: Bool
    ) {
        let departure = CVROperationalIdentityLocal.normalizeAirport(
            session?.plannedDepartureAirport ?? plannedLeg?.departureAirport ?? ""
        )
        let destination = CVROperationalIdentityLocal.normalizeAirport(
            session?.plannedDestinationAirport ?? plannedLeg?.destinationAirport ?? ""
        )
        guard !departure.isEmpty else {
            lastError = "Enter the departure airport."
            return
        }
        guard !destination.isEmpty else {
            lastError = "Enter the destination airport."
            return
        }
        let missionCode = session?.missionCode ?? plannedLeg?.missionCode ?? ""
        let schedulerRecordID = session?.schedulerRecordID ?? plannedLeg?.schedulerRecordID
        let reservationUUID = session?.reservationUUID ?? plannedLeg?.reservationUUID
        let legUUID = session?.legUUID ?? plannedLeg?.legUUID
        let registration = selectedAircraft?.registration
            ?? session?.aircraftRegistration
            ?? plannedLeg?.tailNumber
            ?? ""

        guard let selectedAircraft,
              session == nil || scheduledSessionMatchesAircraft(session!, selectedAircraft: selectedAircraft) else {
            lastError = "This scheduled flight does not match the aircraft enrolled to this CVR Unit."
            return
        }

        if let active = state.activeDispatch {
            let sameScheduler = schedulerRecordID != nil && active.schedulerRecordID == schedulerRecordID
            let sameLeg = legUUID != nil && active.operationalIdentity?.legUUID == legUUID
            if sameScheduler || sameLeg {
                selectTab(.dispatch)
                return
            }
        }

        if state.engineSessionContinuityActive,
           let continuityReservation = state.operationalSession?.reservationUUID,
           let reservationUUID,
           continuityReservation.lowercased() != reservationUUID.lowercased() {
            lastError = "End the continuous engine session on Schedule (Engine Was Shut Down or Cancel Remaining Legs) before opening a different reservation."
            return
        }

        if state.activeFlightRecord != nil {
            guard !isAudioRecording || state.engineSessionContinuityActive else {
                lastError = "Stop the active recording before opening another scheduled flight."
                return
            }
            if state.activeDispatch != nil, state.activeFlightRecord?.endingHobbs == nil {
                lastError = "Complete Check-In for the current leg before opening the next leg."
                return
            }
            if state.activeDispatch != nil {
                guard archiveActiveWorkflow() else { return }
            }
            clearActiveLegStatePreservingSession(selectScheduled: false)
        } else if state.activeDispatch != nil {
            clearActiveLegStatePreservingSession(selectScheduled: false)
        }

        let continuity = state.operationalSession
        let useContinuity = continuity?.engineSessionContinuityActive == true
        let carryover = resolvedLegCarryover(for: registration)
        let dispatchID = UUID().uuidString
        var operationalIdentity: CVRLocalOperationalIdentity?
        if canonicalWriteEnabled {
            do {
                operationalIdentity = try CVROperationalIdentityLocal.createOfflineBundle(
                    organizationID: 1,
                    dispatchUUID: dispatchID,
                    organizationTimezoneIANA: TimeZone.current.identifier,
                    originAirport: departure,
                    destinationAirport: destination,
                    schedulerRecordID: schedulerRecordID,
                    reservationUUID: reservationUUID,
                    legUUID: legUUID
                )
            } catch {
                CVROperationalIdentityLocal.logSanitized("offline_leg_identity_create_failed", fields: [
                    "error_class": String(describing: type(of: error)),
                ])
                lastError = "Unable to open Dispatch for this leg. Please try again."
                return
            }
        } else if let reservationUUID, let legUUID,
                  let normalizedReservation = CVROperationalIdentityLocal.normalizeUUID(reservationUUID),
                  let normalizedLeg = CVROperationalIdentityLocal.normalizeUUID(legUUID) {
            // Preserve already-minted local/planned leg identity without enabling server canonical writes.
            operationalIdentity = CVRLocalOperationalIdentity(
                reservationUUID: normalizedReservation,
                legUUID: normalizedLeg,
                organizationID: 1,
                reservationType: "flight_training",
                activityDomain: "flight",
                organizationTimezoneIANA: TimeZone.current.identifier,
                originAirport: CVROperationalIdentityLocal.normalizeAirport(departure),
                destinationAirport: CVROperationalIdentityLocal.normalizeAirport(destination),
                plannedStartAtUTC: nil,
                plannedEndAtUTC: nil,
                aliases: [],
                linkageMethod: CVROperationalIdentityLocal.linkageOfflineCreate
            )
        }

        let dispatch = CVRDispatchRecord(
            id: dispatchID,
            serverDispatchID: nil,
            organizationID: 1,
            scheduledDate: session?.dateTime(nil) ?? Date(),
            scheduledStartTime: session?.dateTime(session?.scheduledStartTime),
            scheduledEndTime: session?.dateTime(session?.scheduledEndTime),
            tailNumber: selectedAircraft.registration,
            aircraftID: selectedAircraft.id,
            missionCode: missionCode,
            plannedDepartureAirport: departure,
            plannedDestinationAirport: destination,
            crew: {
                // Scheduled sessions must keep the online schedule crew for claim validation.
                // Meter/fuel/oil carryover is separate and must not replace scheduled crew identity.
                if session != nil {
                    return (session?.crew ?? []).map { member in
                        CVRCrewAssignment(
                            id: UUID().uuidString,
                            personID: member.personID,
                            personName: member.personName,
                            role: Self.crewRole(from: member.role)
                        )
                    }
                }
                if let carried = previousLegCrewCarryover(for: selectedAircraft.registration), !carried.isEmpty {
                    return Self.remintedCrewAssignments(carried)
                }
                return []
            }(),
            startingHobbs: carryover?.endingHobbs,
            startingTacho: carryover?.endingTacho,
            fuelOnboard: carryover?.fuelRemaining ?? "",
            oilPercentage: carryover?.oilPercentage,
            startingOilQuantity: carryover?.oilQuantity,
            startingOilUnit: carryover?.oilUnit ?? selectedAircraft.operationalConfig.oilUnit,
            dispatchSource: useContinuity
                ? "transient_stop_carryover"
                : (session != nil ? "scheduled_session" : "local_planned_leg"),
            schedulerRecordID: schedulerRecordID,
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
            oilServicedSincePreviousFlight: nil,
            operationalIdentity: operationalIdentity
        )

        _ = mutate {
            var sessionContext = $0.operationalSession ?? .empty
            if let reservationUUID {
                sessionContext.reservationUUID = reservationUUID.lowercased()
            }
            if let plannedLeg {
                Self.activatePlannedLeg(plannedLeg.legUUID, in: &sessionContext)
            } else if let legUUID, sessionContext.plannedLegs.contains(where: { $0.legUUID == legUUID }) {
                Self.activatePlannedLeg(legUUID, in: &sessionContext)
            } else if let session {
                // Seed planned legs from this reservation group if not already local.
                if sessionContext.plannedLegs.isEmpty, let reservationUUID {
                    sessionContext.reservationUUID = reservationUUID.lowercased()
                }
                sessionContext.currentLegIndex = 1
                _ = session
            }
            Self.sanitizePlannedLegStatuses(in: &sessionContext)
            sessionContext.pendingCheckInMode = nil
            sessionContext.awaitingAvionicsOffConfirmation = false
            sessionContext.continuityEngineStartSynthesized = false
            sessionContext.pendingSoftStartRecording = useContinuity
            $0.operationalSession = sessionContext
            $0.activeDispatch = dispatch
            $0.activeFlightRecord = nil
            $0.consents = []
            $0.recorderVerifications = []
            $0.flightEvents = []
            $0.flightLegs = $0.flightLegs
            $0.uploadComponents = []
            $0.discrepancies = []
            $0.selectedTab = .dispatch
        }
    }

    func canOpenScheduledSession(
        _ session: CVRScheduledSession,
        selectedAircraft: CockpitAircraft?,
        isAudioRecording: Bool
    ) -> Bool {
        _ = selectedAircraft
        if state.activeDispatch?.schedulerRecordID == session.schedulerRecordID {
            return true
        }
        if let legUUID = session.legUUID,
           state.activeDispatch?.operationalIdentity?.legUUID == legUUID {
            return true
        }
        if state.engineSessionContinuityActive {
            return true
        }
        return !isAudioRecording
    }

    func requiresArchivingBeforeScheduledSession(_ session: CVRScheduledSession) -> Bool {
        guard state.activeDispatch != nil,
              state.activeDispatch?.schedulerRecordID != session.schedulerRecordID else {
            return false
        }
        if let legUUID = session.legUUID,
           state.activeDispatch?.operationalIdentity?.legUUID == legUUID {
            return false
        }
        if let flightRecord = state.activeFlightRecord {
            return flightRecord.endingHobbs == nil || flightRecord.endingTacho == nil
        }
        return false
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
            checkInComments: nil,
            verifiedDestinationAirport: nil,
            checkInMode: nil,
            calculatedArrivalAt: nil,
            arrivalCalculationSource: nil,
            createdAt: Date(),
            updatedAt: Date()
        )
        if var identity = dispatch.operationalIdentity {
            do {
                identity = try CVROperationalIdentityLocal.appendingWorkflowFlightRecordAlias(
                    to: identity,
                    flightRecordUUID: flightRecord.id
                )
                dispatch.operationalIdentity = identity
            } catch {
                CVROperationalIdentityLocal.logSanitized("offline_flight_record_alias_failed", fields: [
                    "error_class": String(describing: type(of: error)),
                    "dispatch_uuid": dispatch.id.lowercased(),
                ])
                lastError = "Unable to confirm the Dispatch. Please try again."
                return
            }
        }
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
            // Phase 3: no consent UI — mint accepted operational-test consents so server intake succeeds.
            $0.consents = Self.ensuredOperationalConsents(
                for: dispatch,
                existing: $0.consents,
                deviceID: dispatch.configuredCVRUnitID,
                appVersion: Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String ?? "1.0"
            )
            if !$0.uploadComponents.contains(where: { $0.id == dispatchComponent.id }) {
                $0.uploadComponents.append(dispatchComponent)
            }
            var session = $0.operationalSession ?? .empty
            Self.markCurrentPlannedLeg(dispatchedIn: &session, dispatch: dispatch)
            $0.operationalSession = session
            $0.selectedTab = .recorder
        }
        // Continuity legs must create Off Block locally as soon as the Flight Record exists.
        if state.engineSessionContinuityActive {
            _ = synthesizeEngineContinuityIfNeeded(gpsSample: nil)
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
            if var session = $0.operationalSession, session.engineSessionContinuityActive {
                session.pendingSoftStartRecording = true
                $0.operationalSession = session
            }
            $0.selectedTab = .inFlight
        }
    }

    func consumePendingSoftStartRecording() {
        guard state.operationalSession?.pendingSoftStartRecording == true else { return }
        mutate {
            guard var session = $0.operationalSession else { return }
            session.pendingSoftStartRecording = false
            $0.operationalSession = session
        }
    }

    func recordTransientStopOnBlock(gpsSample: GPSSample?) {
        guard hasRemainingPlannedLegAfterCurrent else {
            lastError = "Transient Stop is only available when another leg remains. Use Engine Shutdown for the final leg."
            return
        }
        guard var flightRecord = state.activeFlightRecord else { return }
        let hasEngineRunning = state.flightEvents.contains {
            $0.flightRecordID == flightRecord.id && $0.eventType == "engine_start_off_block"
        } || state.engineSessionContinuityActive
        guard hasEngineRunning else { return }
        guard !state.flightEvents.contains(where: {
            $0.flightRecordID == flightRecord.id
                && ($0.eventType == "transient_stop_on_block" || $0.eventType == "engine_shutdown_on_block")
        }) else {
            return
        }

        let event = makeFlightEvent(
            flightRecord: flightRecord,
            eventType: "transient_stop_on_block",
            source: "manual_transient_stop_hold",
            creationMethod: "three_second_hold",
            gpsSample: gpsSample
        )

        mutate {
            flightRecord.checkInMode = .transientStop
            flightRecord.status = .shutdownVerificationRequired
            flightRecord.updatedAt = event.timestampUTC
            $0.activeFlightRecord = flightRecord
            $0.flightEvents.append(event)
            $0.uploadComponents.append(eventUploadComponent(event))
            var session = $0.operationalSession ?? .empty
            session.pendingCheckInMode = .transientStop
            session.engineSessionContinuityActive = true
            $0.operationalSession = session
        }
    }

    func beginTransientStopCheckIn() {
        mutate {
            var session = $0.operationalSession ?? .empty
            session.pendingCheckInMode = .transientStop
            $0.operationalSession = session
            if var flight = $0.activeFlightRecord {
                flight.checkInMode = .transientStop
                flight.status = .shutdownVerificationRequired
                flight.updatedAt = Date()
                $0.activeFlightRecord = flight
            }
        }
    }

    /// Persists Engine Start / Off Block locally before UI confirmation. Returns false if not saved.
    @discardableResult
    func recordEngineStartOffBlock(gpsSample: GPSSample?) -> Bool {
        guard var flightRecord = state.activeFlightRecord else {
            lastError = "Off Block could not be recorded. Open Dispatch first."
            return false
        }
        guard !state.flightEvents.contains(where: { $0.flightRecordID == flightRecord.id && $0.eventType == "engine_start_off_block" }) else {
            return true
        }

        let now = Date()
        var metadata: [String: String] = [
            "flight_record_uuid": flightRecord.id.lowercased(),
        ]
        if let legUUID = state.activeDispatch?.operationalIdentity?.legUUID,
           let normalizedLeg = CVROperationalIdentityLocal.normalizeUUID(legUUID) {
            metadata["leg_uuid"] = normalizedLeg
        }
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
            userIdentity: "local_cvr_unit",
            metadata: metadata
        )

        let persisted = mutate {
            flightRecord.status = .recording
            flightRecord.updatedAt = now
            $0.activeFlightRecord = flightRecord
            $0.flightEvents.append(event)
            $0.uploadComponents.append(eventUploadComponent(event))
            if var session = $0.operationalSession {
                session.engineSessionContinuityActive = true
                $0.operationalSession = session
            } else {
                var session = CVROperationalSessionContext.empty
                session.engineSessionContinuityActive = true
                session.reservationUUID = $0.activeDispatch?.operationalIdentity?.reservationUUID
                $0.operationalSession = session
            }
        }
        if !persisted {
            lastError = "Off Block was not saved on this device. Hold Engine Start again."
        }
        return persisted
    }

    /// After Transient Stop, next leg inherits a running engine — synthesize OFF Block without UI Engine Start.
    @discardableResult
    func synthesizeEngineContinuityIfNeeded(gpsSample: GPSSample?) -> Bool {
        guard state.engineSessionContinuityActive,
              var flightRecord = state.activeFlightRecord else { return false }
        guard !state.flightEvents.contains(where: {
            $0.flightRecordID == flightRecord.id && $0.eventType == "engine_start_off_block"
        }) else { return true }

        let now = Date()
        var metadata: [String: String] = [
            "continuity": "true",
            "flight_record_uuid": flightRecord.id.lowercased(),
        ]
        if let legUUID = state.activeDispatch?.operationalIdentity?.legUUID,
           let normalizedLeg = CVROperationalIdentityLocal.normalizeUUID(legUUID) {
            metadata["leg_uuid"] = normalizedLeg
        }
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
            source: "engine_session_continuity",
            confidence: 1.0,
            creationMethod: "transient_stop_carryover",
            userIdentity: "local_cvr_unit",
            metadata: metadata
        )
        let persisted = mutate {
            flightRecord.status = .recording
            flightRecord.updatedAt = now
            $0.activeFlightRecord = flightRecord
            $0.flightEvents.append(event)
            $0.uploadComponents.append(eventUploadComponent(event))
            if var session = $0.operationalSession {
                session.continuityEngineStartSynthesized = true
                $0.operationalSession = session
            }
        }
        return persisted
    }

    func beginEngineShutdownCheckIn() {
        mutate {
            var session = $0.operationalSession ?? .empty
            session.pendingCheckInMode = .engineShutdown
            $0.operationalSession = session
            if var flight = $0.activeFlightRecord {
                flight.checkInMode = .engineShutdown
                flight.updatedAt = Date()
                $0.activeFlightRecord = flight
            }
        }
    }

    var pendingCheckInMode: CVRCheckInMode? {
        state.operationalSession?.pendingCheckInMode ?? state.activeFlightRecord?.checkInMode
    }

    var needsEngineStart: Bool {
        !state.engineSessionContinuityActive
    }

    /// True when at least one unfinished planned leg remains after the current leg.
    /// Transient Stop is only offered when this is true (never for single-leg / last-leg flights).
    var hasRemainingPlannedLegAfterCurrent: Bool {
        let legs = state.plannedLegs
        guard legs.count > 1 else { return false }

        let currentUUID = (state.activeDispatch?.operationalIdentity?.legUUID)
            .flatMap { CVROperationalIdentityLocal.normalizeUUID($0) }
        let currentIndex = state.operationalSession?.currentLegIndex

        return legs.contains { leg in
            let status = leg.status.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
            if status == "checked_in" || status == "cancelled" || status == "canceled" {
                return false
            }
            let legUUID = CVROperationalIdentityLocal.normalizeUUID(leg.legUUID) ?? leg.legUUID.lowercased()
            if let currentUUID, legUUID == currentUUID {
                return false
            }
            if currentUUID == nil, let currentIndex, leg.sequenceNumber == currentIndex {
                return false
            }
            return true
        }
    }

    func estimatedCheckInHobbs() -> Double? {
        guard let start = state.activeDispatch?.startingHobbs else { return nil }
        let hobbsIncrement = engineRunningHobbsIncrementHours()
        return ((start + hobbsIncrement) * 10).rounded() / 10
    }

    func estimatedCheckInTacho() -> Double? {
        guard let start = state.activeDispatch?.startingTacho else { return nil }
        let hobbsIncrement = engineRunningHobbsIncrementHours()
        return ((start + hobbsIncrement * 0.70) * 10).rounded() / 10
    }

    /// Hobbs increment estimate for the active leg from Off-Block / continuity start to now.
    func engineRunningHobbsIncrementHours() -> Double {
        let events = state.flightEvents.filter { $0.flightRecordID == state.activeFlightRecord?.id }
        let off = events.first { $0.eventType == "engine_start_off_block" }?.timestampUTC
        guard let off else { return 0 }
        return max(0, Date().timeIntervalSince(off) / 3600.0)
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
        recordInFlightAction(eventType: "manual_takeoff_adjustment", creationMethod: "one_second_hold", gpsSample: gpsSample)
    }

    func recordManualLandingAdjustment(gpsSample: GPSSample?) {
        recordInFlightAction(eventType: "manual_landing_adjustment", creationMethod: "one_second_hold", gpsSample: gpsSample)
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
              }) || state.engineSessionContinuityActive,
              !state.flightEvents.contains(where: {
                  $0.flightRecordID == flightRecord.id
                      && ($0.eventType == "engine_shutdown_on_block"
                          || $0.eventType == "transient_stop_on_block")
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
        let hasEngineRunning = state.flightEvents.contains {
            $0.flightRecordID == flightRecord.id && $0.eventType == "engine_start_off_block"
        } || state.engineSessionContinuityActive
        guard hasEngineRunning else { return }
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
            flightRecord.checkInMode = .engineShutdown
            flightRecord.updatedAt = event.timestampUTC
            $0.activeFlightRecord = flightRecord
            $0.flightEvents.append(event)
            $0.uploadComponents.append(eventUploadComponent(event))
            var session = $0.operationalSession ?? .empty
            session.pendingCheckInMode = .engineShutdown
            session.engineSessionContinuityActive = false
            $0.operationalSession = session
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
        return saveCheckInValues(
            endingHobbs: endingHobbs,
            endingTacho: endingTacho,
            fuelRemaining: nil,
            verifiedDestinationAirport: nil,
            verifiedTakeoffCount: verifiedTakeoffCount,
            verifiedLandingCount: verifiedLandingCount,
            comments: maintenanceRemark,
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
        let fuel = (flightRecord.fuelRemaining ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
        guard !fuel.isEmpty else { return false }
        let destination = (flightRecord.verifiedDestinationAirport ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
        guard !destination.isEmpty else { return false }
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
        } || pendingCheckInMode != nil || state.flightEvents.contains {
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
        saveCheckInValues(
            endingHobbs: endingHobbs,
            endingTacho: endingTacho,
            fuelRemaining: nil,
            verifiedDestinationAirport: nil,
            verifiedTakeoffCount: verifiedTakeoffCount,
            verifiedLandingCount: verifiedLandingCount,
            comments: maintenanceRemark,
            gpsSample: gpsSample,
            repairExistingClosureUpload: repairExistingClosureUpload
        )
    }

    @discardableResult
    func saveCheckInValues(
        endingHobbs: Double?,
        endingTacho: Double?,
        fuelRemaining: String?,
        verifiedDestinationAirport: String?,
        verifiedTakeoffCount: Int,
        verifiedLandingCount: Int,
        comments: String,
        gpsSample: GPSSample?,
        repairExistingClosureUpload: Bool
    ) -> Bool {
        guard var flightRecord = state.activeFlightRecord,
              let dispatch = state.activeDispatch else { return false }
        let mode = pendingCheckInMode ?? flightRecord.checkInMode ?? .engineShutdown
        if mode == .engineShutdown {
            guard state.flightEvents.contains(where: {
                $0.flightRecordID == flightRecord.id && $0.eventType == "engine_shutdown_on_block"
            }) else {
                lastError = "Engine shutdown must be recorded before Check-In can be saved."
                return false
            }
        }
        if mode == .transientStop {
            guard state.flightEvents.contains(where: {
                $0.flightRecordID == flightRecord.id && $0.eventType == "transient_stop_on_block"
            }) else {
                lastError = "Transient Stop must be recorded before Check-In can be saved."
                return false
            }
        }
        guard let endingHobbs, endingHobbs >= (dispatch.startingHobbs ?? 0) else {
            lastError = "Ending Hobbs must be present and cannot be lower than Starting Hobbs."
            return false
        }
        guard let endingTacho, endingTacho >= (dispatch.startingTacho ?? 0) else {
            lastError = "Ending Tacho must be present and cannot be lower than Starting Tacho."
            return false
        }
        let fuel = (fuelRemaining ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
        guard !fuel.isEmpty else {
            lastError = "Fuel Remaining is required at Check-In."
            return false
        }
        let destination = CVROperationalIdentityLocal.normalizeAirport(
            verifiedDestinationAirport ?? dispatch.plannedDestinationAirport
        )
        guard !destination.isEmpty else {
            lastError = "Enter the destination airport."
            return false
        }
        guard verifiedTakeoffCount >= 0, verifiedLandingCount >= 0 else {
            lastError = "Takeoff and landing counts must be zero or greater."
            return false
        }
        // New flights must have a local Off Block event before Check-In completes.
        // Continuity legs synthesize engine_start_off_block at Dispatch confirmation.
        guard state.flightEvents.contains(where: {
            $0.flightRecordID == flightRecord.id && $0.eventType == "engine_start_off_block"
        }) else {
            lastError = "Off Block is not saved on this device yet. Record Engine Start before Check-In."
            return false
        }

        let counts = operationCounts(for: flightRecord.id)
        let offBlock = state.flightEvents.first {
            $0.flightRecordID == flightRecord.id && $0.eventType == "engine_start_off_block"
        }
        let hobbsIncrement = dispatch.startingHobbs.map { endingHobbs - $0 } ?? 0
        let calculatedArrival = offBlock.map {
            $0.timestampLocal.addingTimeInterval(max(0, hobbsIncrement) * 3600)
        }
        let arrivalSource = "off_block_plus_hobbs_increment"
        var eventMetadata: [String: String] = [
            "verified_takeoff_count": String(verifiedTakeoffCount),
            "verified_landing_count": String(verifiedLandingCount),
            "auto_takeoff_count": String(counts.autoTakeoffs + counts.manualTakeoffs),
            "auto_landing_count": String(counts.autoLandings + counts.manualLandings),
            "check_in_mode": mode.rawValue,
            "verified_destination_airport": destination,
            "arrival_calculation_source": arrivalSource,
            "hobbs_increment": String(format: "%.2f", hobbsIncrement),
        ]
        if let calculatedArrival {
            eventMetadata["calculated_arrival_at_local"] = ISO8601DateFormatter().string(from: calculatedArrival)
        }
        let event = makeFlightEvent(
            flightRecord: flightRecord,
            eventType: "shutdown_verification_completed",
            source: repairExistingClosureUpload ? "closure_upload_repair" : "check_in",
            creationMethod: repairExistingClosureUpload ? "upload_repair_form" : "check_in_form",
            gpsSample: gpsSample,
            metadata: eventMetadata
        )

        let legRecord = CVRFlightLegRecord(
            id: dispatch.operationalIdentity?.legUUID ?? UUID().uuidString.lowercased(),
            flightRecordID: flightRecord.id,
            sequenceNumber: (state.operationalSession?.plannedLegs.first(where: {
                $0.legUUID == dispatch.operationalIdentity?.legUUID
            })?.sequenceNumber) ?? (state.flightLegs.count + 1),
            reservationUUID: dispatch.operationalIdentity?.reservationUUID ?? state.operationalSession?.reservationUUID,
            legUUID: dispatch.operationalIdentity?.legUUID,
            departureAirport: dispatch.plannedDepartureAirport,
            arrivalAirport: destination,
            legOpeningTimestamp: state.flightEvents.first {
                $0.flightRecordID == flightRecord.id && $0.eventType == "engine_start_off_block"
            }?.timestampUTC ?? flightRecord.createdAt,
            takeoffTimestamp: state.flightEvents.first {
                $0.flightRecordID == flightRecord.id
                    && ($0.eventType == "gps_takeoff_provisional" || $0.eventType == "manual_takeoff_adjustment")
            }?.timestampUTC,
            landingTimestamp: state.flightEvents.last {
                $0.flightRecordID == flightRecord.id
                    && ($0.eventType == "gps_landing_provisional" || $0.eventType == "manual_landing_adjustment")
            }?.timestampUTC,
            legClosingTimestamp: event.timestampUTC,
            startHobbsAllocation: dispatch.startingHobbs,
            endHobbsAllocation: endingHobbs,
            hobbsDuration: dispatch.startingHobbs.map { endingHobbs - $0 },
            actualElapsedDuration: nil,
            takeoffCount: verifiedTakeoffCount,
            landingCount: verifiedLandingCount,
            touchAndGoCount: 0,
            stopAndGoCount: 0,
            fullStopLandingCount: verifiedLandingCount,
            reviewStatus: "checked_in"
        )

        let persisted = mutate {
            flightRecord.endingHobbs = endingHobbs
            flightRecord.endingTacho = endingTacho
            flightRecord.fuelRemaining = fuel
            flightRecord.endingOilPercentage = dispatch.oilPercentage
            flightRecord.endingOilQuantity = dispatch.effectiveStartingOilQuantity
            flightRecord.endingOilUnit = dispatch.startingOilUnit ?? dispatch.effectiveStartingOilUnit
            flightRecord.verifiedDestinationAirport = destination
            flightRecord.verifiedTakeoffCount = verifiedTakeoffCount
            flightRecord.verifiedLandingCount = verifiedLandingCount
            flightRecord.autoDetectedTakeoffCount = counts.displayTakeoffs
            flightRecord.autoDetectedLandingCount = counts.displayLandings
            flightRecord.maintenanceRemark = comments.trimmingCharacters(in: .whitespacesAndNewlines)
            flightRecord.checkInComments = comments.trimmingCharacters(in: .whitespacesAndNewlines)
            flightRecord.checkInMode = mode
            flightRecord.calculatedArrivalAt = calculatedArrival
            flightRecord.arrivalCalculationSource = arrivalSource
            if mode == .engineShutdown {
                flightRecord.status = .awaitingAvionicsOff
            } else {
                flightRecord.status = .awaitingUpload
            }
            flightRecord.updatedAt = event.timestampUTC
            $0.activeFlightRecord = flightRecord
            // Persist crew-verified destination onto the active Dispatch without blanking departure.
            if var activeDispatch = $0.activeDispatch {
                activeDispatch.plannedDestinationAirport = CVROperationalIdentityLocal.preservingNonEmptyAirport(
                    existing: activeDispatch.plannedDestinationAirport,
                    incoming: destination
                )
                if var identity = activeDispatch.operationalIdentity {
                    identity.destinationAirport = CVROperationalIdentityLocal.preservingNonEmptyAirport(
                        existing: identity.destinationAirport,
                        incoming: destination
                    )
                    activeDispatch.operationalIdentity = identity
                }
                activeDispatch.modifiedAt = event.timestampUTC
                $0.activeDispatch = activeDispatch
            }
            $0.flightLegs.removeAll { $0.flightRecordID == flightRecord.id }
            $0.flightLegs.append(legRecord)

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
            $0.uploadComponents.removeAll {
                $0.flightRecordID == flightRecord.id
                    && $0.componentType == "flight_record_closure"
            }
            $0.uploadComponents.append(evidenceComponent(
                flightRecordID: flightRecord.id,
                type: "flight_record_closure",
                evidenceID: flightRecord.id
            ))

            var session = $0.operationalSession ?? .empty
            session.pendingCheckInMode = mode
            session.carryoverHobbs = endingHobbs
            session.carryoverTacho = endingTacho
            session.carryoverFuel = fuel
            session.carryoverCrew = dispatch.crew
            session.carryoverOilPercentage = dispatch.oilPercentage
            session.carryoverOilQuantity = dispatch.effectiveStartingOilQuantity
            session.carryoverOilUnit = dispatch.effectiveStartingOilUnit
            let currentLegUUID = dispatch.operationalIdentity?.legUUID
            if let legUUID = currentLegUUID,
               let index = session.plannedLegs.firstIndex(where: {
                   CVROperationalIdentityLocal.normalizeUUID($0.legUUID)
                       == CVROperationalIdentityLocal.normalizeUUID(legUUID)
               }) {
                session.plannedLegs[index].status = "checked_in"
                session.plannedLegs[index].destinationAirport = CVROperationalIdentityLocal.preservingNonEmptyAirport(
                    existing: session.plannedLegs[index].destinationAirport,
                    incoming: destination
                )
            } else if let index = session.currentLegIndex.flatMap({ desired in
                session.plannedLegs.firstIndex(where: { $0.sequenceNumber == desired })
            }) {
                session.plannedLegs[index].status = "checked_in"
                session.plannedLegs[index].destinationAirport = CVROperationalIdentityLocal.preservingNonEmptyAirport(
                    existing: session.plannedLegs[index].destinationAirport,
                    incoming: destination
                )
            }
            Self.sanitizePlannedLegStatuses(in: &session)
            if mode == .transientStop {
                session.engineSessionContinuityActive = true
                session.awaitingAvionicsOffConfirmation = false
            } else {
                session.engineSessionContinuityActive = false
                session.awaitingAvionicsOffConfirmation = true
            }
            $0.operationalSession = session
        }
        guard persisted else { return false }

        if mode == .transientStop {
            return completeTransientStopLocally()
        }
        return true
    }

    @discardableResult
    func completeTransientStopLocally() -> Bool {
        guard let flightRecord = state.activeFlightRecord,
              flightRecord.endingHobbs != nil,
              flightRecord.endingTacho != nil,
              !(flightRecord.fuelRemaining ?? "").isEmpty else {
            lastError = "Check-In must be saved before continuing to the next leg."
            return false
        }
        guard archiveActiveWorkflow() else { return false }
        return mutate {
            var session = $0.operationalSession ?? .empty
            session.engineSessionContinuityActive = true
            session.pendingCheckInMode = nil
            session.awaitingAvionicsOffConfirmation = false
            session.continuityEngineStartSynthesized = false
            session.pendingSoftStartRecording = false
            $0.operationalSession = session
            $0.activeDispatch = nil
            $0.activeFlightRecord = nil
            $0.consents = []
            $0.recorderVerifications = []
            $0.flightEvents = []
            $0.uploadComponents = []
            $0.discrepancies = []
            $0.selectedTab = .scheduled
        }
    }

    @discardableResult
    func completeEngineShutdownAfterAvionicsOff() -> Bool {
        guard let flightRecord = state.activeFlightRecord,
              flightRecord.endingHobbs != nil,
              flightRecord.endingTacho != nil else {
            lastError = "Check-In must be saved before completing Engine Shutdown."
            return false
        }
        guard archiveActiveWorkflow() else { return false }
        return mutate {
            if var session = $0.operationalSession, Self.hasOpenPlannedLegs(in: session) {
                session.engineSessionContinuityActive = false
                session.pendingCheckInMode = nil
                session.awaitingAvionicsOffConfirmation = false
                session.continuityEngineStartSynthesized = false
                session.pendingSoftStartRecording = false
                Self.sanitizePlannedLegStatuses(in: &session)
                $0.operationalSession = session
            } else {
                $0.operationalSession = nil
            }
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

    /// Remaining unfinished planned legs in the current operational session (excluding the active Dispatch leg).
    var remainingOpenPlannedLegs: [CVRPlannedLegRecord] {
        let currentUUID = (state.activeDispatch?.operationalIdentity?.legUUID)
            .flatMap { CVROperationalIdentityLocal.normalizeUUID($0) }
        return state.plannedLegs.filter { leg in
            let status = leg.status.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
            if status == "checked_in" || status == "cancelled" || status == "canceled" {
                return false
            }
            let legUUID = CVROperationalIdentityLocal.normalizeUUID(leg.legUUID) ?? leg.legUUID.lowercased()
            if let currentUUID, legUUID == currentUUID {
                return false
            }
            return true
        }
    }

    /// Continuity is on, no active flight — Schedule can recover a mistaken Transient Stop.
    var canRecoverBrokenEngineContinuity: Bool {
        state.engineSessionContinuityActive
            && state.activeFlightRecord == nil
            && state.activeDispatch == nil
            && !remainingOpenPlannedLegs.isEmpty
    }

    /// Active next-leg Dispatch was opened under false continuity (engine actually off).
    var canClearFalseContinuityOnActiveLeg: Bool {
        guard state.engineSessionContinuityActive,
              let flight = state.activeFlightRecord else { return false }
        let hasSynthesized = state.flightEvents.contains {
            $0.flightRecordID == flight.id
                && $0.eventType == "engine_start_off_block"
                && ($0.source == "engine_session_continuity" || $0.creationMethod == "transient_stop_carryover")
        }
        let hasRealOffBlock = state.flightEvents.contains {
            $0.flightRecordID == flight.id
                && $0.eventType == "engine_start_off_block"
                && $0.source != "engine_session_continuity"
                && $0.creationMethod != "transient_stop_carryover"
        }
        return hasSynthesized && !hasRealOffBlock && flight.endingHobbs == nil
    }

    /// Convert a mistaken Transient Stop into Engine Shutdown before Check-In is saved.
    @discardableResult
    func convertTransientStopToEngineShutdown(gpsSample: GPSSample? = nil) -> Bool {
        guard var flightRecord = state.activeFlightRecord else { return false }
        guard state.flightEvents.contains(where: {
            $0.flightRecordID == flightRecord.id && $0.eventType == "transient_stop_on_block"
        }) else {
            lastError = "No Transient Stop is active to convert."
            return false
        }
        guard !state.flightEvents.contains(where: {
            $0.flightRecordID == flightRecord.id && $0.eventType == "engine_shutdown_on_block"
        }) else {
            return true
        }
        guard flightRecord.endingHobbs == nil else {
            lastError = "Check-In already saved for Transient Stop. End continuity on Schedule, then open the next leg with Engine Start."
            return false
        }

        let shutdown = makeFlightEvent(
            flightRecord: flightRecord,
            eventType: "engine_shutdown_on_block",
            source: "manual_convert_transient_to_shutdown",
            creationMethod: "operator_correction",
            gpsSample: gpsSample
        )
        let persisted = mutate {
            let transientIDs = Set($0.flightEvents.filter {
                $0.flightRecordID == flightRecord.id && $0.eventType == "transient_stop_on_block"
            }.map(\.id))
            $0.flightEvents.removeAll { transientIDs.contains($0.id) }
            $0.uploadComponents.removeAll { component in
                guard component.flightRecordID == flightRecord.id,
                      component.componentType == "flight_events",
                      let path = component.localFilePath else { return false }
                return transientIDs.contains { path == "event:\($0)" }
            }
            flightRecord.checkInMode = .engineShutdown
            flightRecord.status = .shutdownVerificationRequired
            flightRecord.updatedAt = shutdown.timestampUTC
            $0.activeFlightRecord = flightRecord
            $0.flightEvents.append(shutdown)
            $0.uploadComponents.append(eventUploadComponent(shutdown))
            var session = $0.operationalSession ?? .empty
            session.pendingCheckInMode = .engineShutdown
            session.engineSessionContinuityActive = false
            session.pendingSoftStartRecording = false
            session.continuityEngineStartSynthesized = false
            $0.operationalSession = session
        }
        if !persisted {
            lastError = "Could not convert Transient Stop to Engine Shutdown."
        }
        return persisted
    }

    /// After a mistaken Transient Check-In: keep unused legs, require Engine Start for the next Dispatch.
    @discardableResult
    func endEngineContinuityPreservingUnusedLegs() -> Bool {
        guard state.engineSessionContinuityActive else {
            lastError = "No continuous engine session is active."
            return false
        }
        if canClearFalseContinuityOnActiveLeg {
            return clearFalseContinuityOnActiveLeg()
        }
        guard state.activeFlightRecord == nil else {
            lastError = "Finish or Undispatch the active leg before ending engine continuity."
            return false
        }
        return mutate {
            guard var session = $0.operationalSession else { return }
            session.engineSessionContinuityActive = false
            session.pendingCheckInMode = nil
            session.awaitingAvionicsOffConfirmation = false
            session.continuityEngineStartSynthesized = false
            session.pendingSoftStartRecording = false
            Self.sanitizePlannedLegStatuses(in: &session)
            $0.operationalSession = session
            $0.selectedTab = .scheduled
        }
    }

    /// Remove a continuity-synthesized Off Block so the crew can use real Engine Start.
    @discardableResult
    func clearFalseContinuityOnActiveLeg() -> Bool {
        guard canClearFalseContinuityOnActiveLeg,
              let flightRecord = state.activeFlightRecord else {
            lastError = "This leg does not have a continuity Off Block to clear."
            return false
        }
        return mutate {
            let synthesizedIDs = Set($0.flightEvents.filter {
                $0.flightRecordID == flightRecord.id
                    && $0.eventType == "engine_start_off_block"
                    && ($0.source == "engine_session_continuity" || $0.creationMethod == "transient_stop_carryover")
            }.map(\.id))
            $0.flightEvents.removeAll { synthesizedIDs.contains($0.id) }
            $0.uploadComponents.removeAll { component in
                guard component.flightRecordID == flightRecord.id,
                      component.componentType == "flight_events",
                      let path = component.localFilePath else { return false }
                return synthesizedIDs.contains { path == "event:\($0)" }
            }
            if var flight = $0.activeFlightRecord {
                flight.status = .recorderVerificationRequired
                flight.updatedAt = Date()
                $0.activeFlightRecord = flight
            }
            if var session = $0.operationalSession {
                session.engineSessionContinuityActive = false
                session.continuityEngineStartSynthesized = false
                session.pendingSoftStartRecording = false
                $0.operationalSession = session
            }
        }
    }

    /// Cancel unused planned legs and end continuity so completed-leg uploads are not blocked by leftover route state.
    @discardableResult
    func cancelUnusedPlannedLegsAndEndSession() -> Bool {
        guard state.activeFlightRecord == nil else {
            lastError = "Finish or Undispatch the active leg before cancelling remaining legs."
            return false
        }
        guard var session = state.operationalSession, Self.hasOpenPlannedLegs(in: session) || session.engineSessionContinuityActive else {
            lastError = "There are no remaining planned legs to cancel."
            return false
        }
        return mutate {
            guard var session = $0.operationalSession else { return }
            for index in session.plannedLegs.indices {
                let status = session.plannedLegs[index].status.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
                if status != "checked_in" && status != "cancelled" && status != "canceled" {
                    session.plannedLegs[index].status = "cancelled"
                }
            }
            session.engineSessionContinuityActive = false
            session.pendingCheckInMode = nil
            session.awaitingAvionicsOffConfirmation = false
            session.continuityEngineStartSynthesized = false
            session.pendingSoftStartRecording = false
            session.currentLegIndex = nil
            if session.plannedLegs.allSatisfy({
                let status = $0.status.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
                return status == "checked_in" || status == "cancelled" || status == "canceled"
            }) {
                $0.operationalSession = nil
            } else {
                $0.operationalSession = session
            }
            $0.selectedTab = .scheduled
        }
    }

    private static func hasOpenPlannedLegs(in session: CVROperationalSessionContext) -> Bool {
        session.plannedLegs.contains { leg in
            let status = leg.status.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
            return status != "checked_in" && status != "cancelled" && status != "canceled"
        }
    }

    func markAvionicsOffAfterShutdown() {
        mutate {
            if var flight = $0.activeFlightRecord, flight.status == .awaitingAvionicsOff {
                flight.status = .awaitingUpload
                flight.updatedAt = Date()
                $0.activeFlightRecord = flight
            }
            if var session = $0.operationalSession {
                session.awaitingAvionicsOffConfirmation = false
                $0.operationalSession = session
            }
        }
    }

    /// True when Dispatch is locked locally but no operational flight evidence has started yet.
    var canUndispatchActiveFlight: Bool {
        guard isDispatchLocked,
              let flightRecord = state.activeFlightRecord else { return false }
        if flightRecord.recordingStartedAt != nil { return false }
        if flightRecord.endingHobbs != nil || flightRecord.endingTacho != nil { return false }
        if state.flightEvents.contains(where: { $0.flightRecordID == flightRecord.id }) {
            return false
        }
        return true
    }

    /// Undo accidental DISPATCH FLIGHT before Off Block / recording / Check-In.
    /// Releases the schedule claim on the server when this Dispatch was synced or linked to a reservation.
    @discardableResult
    func undispatchActiveFlight(settings: SettingsStore) async -> Bool {
        guard canUndispatchActiveFlight,
              let dispatch = state.activeDispatch,
              let flightRecord = state.activeFlightRecord else {
            lastError = "Undispatch is only available before Off Block, recording, or Check-In."
            return false
        }

        let needsServerRelease = dispatch.serverDispatchID != nil
            || dispatchUploadVerified()
            || !(dispatch.schedulerRecordID ?? "").trimmingCharacters(in: .whitespacesAndNewlines).isEmpty

        if needsServerRelease {
            guard let url = settings.normalizedServerURL,
                  let credential = settings.deviceCredential,
                  !credential.isEmpty else {
                lastError = "Connect and enroll this CVR Unit before Undispatching a synchronized Dispatch."
                return false
            }
            do {
                let response = try await APIClient(serverURL: url).releaseDispatch(
                    dispatchUUID: dispatch.id,
                    schedulerRecordID: dispatch.schedulerRecordID,
                    credential: credential
                )
                if !response.ok {
                    lastError = response.error ?? "Server could not Undispatch this flight."
                    return false
                }
            } catch {
                lastError = error.localizedDescription
                return false
            }
        }

        let clearEntirely = needsServerRelease
            || !(dispatch.schedulerRecordID ?? "").trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
        let flightID = flightRecord.id
        let dispatchID = dispatch.id

        _ = mutate {
            $0.uploadComponents.removeAll { $0.flightRecordID == flightID }
            $0.flightEvents.removeAll { $0.flightRecordID == flightID }
            $0.recorderVerifications.removeAll { $0.flightRecordID == flightID }
            $0.consents.removeAll { $0.dispatchID == dispatchID }
            $0.discrepancies.removeAll { $0.flightRecordID == flightID }
            $0.activeFlightRecord = nil
            if clearEntirely {
                $0.activeDispatch = nil
                $0.selectedTab = .scheduled
                if var session = $0.operationalSession {
                    Self.unmarkDispatchedPlannedLeg(in: &session, dispatchID: dispatchID, flightRecordID: flightID)
                    $0.operationalSession = session
                }
            } else if var draft = $0.activeDispatch {
                draft.status = Self.dispatchStatus(for: draft, consents: [])
                draft.consentStatus = ""
                draft.modifiedAt = Date()
                draft.serverDispatchID = nil
                $0.activeDispatch = draft
                if var session = $0.operationalSession {
                    Self.unmarkDispatchedPlannedLeg(in: &session, dispatchID: dispatchID, flightRecordID: flightID)
                    $0.operationalSession = session
                }
            }
        }
        lastError = ""
        return true
    }

    private func clearActiveLegStatePreservingSession(selectScheduled: Bool) {
        mutate {
            $0.activeDispatch = nil
            $0.activeFlightRecord = nil
            $0.consents = []
            $0.recorderVerifications = []
            $0.flightEvents = []
            $0.uploadComponents = []
            $0.discrepancies = []
            if selectScheduled {
                $0.selectedTab = .scheduled
            }
        }
    }

    /// Fill empty Dispatch meters / fuel / oil from continuity or latest closed archive.
    /// When `serverFuelUSG` is provided (admin uplift / server fuel state), prefer it over local carryover.
    /// Crew is only backfilled during an active continuous engine session (next leg), never for a brand-new local Dispatch.
    func backfillDispatchCarryoverIfNeeded(serverFuelUSG: Double? = nil) {
        guard !isDispatchLocked,
              var dispatch = state.activeDispatch else { return }

        let registration = dispatch.tailNumber
        let continuityActive = state.engineSessionContinuityActive
            || dispatch.dispatchSource == "transient_stop_carryover"
        let carryover = resolvedLegCarryover(for: registration)
        var changed = false

        if continuityActive,
           dispatch.crew.isEmpty,
           let carriedCrew = previousLegCrewCarryover(for: registration),
           !carriedCrew.isEmpty {
            dispatch.crew = Self.remintedCrewAssignments(carriedCrew)
            changed = true
        }
        if dispatch.startingHobbs == nil, let hobbs = carryover?.endingHobbs {
            dispatch.startingHobbs = hobbs
            dispatch.previousEndingHobbs = hobbs
            changed = true
        }
        if dispatch.startingTacho == nil, let tacho = carryover?.endingTacho {
            dispatch.startingTacho = tacho
            dispatch.previousEndingTacho = tacho
            changed = true
        }

        let fuelEmpty = dispatch.fuelOnboard.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
        if let serverFuel = serverFuelUSG, serverFuel >= 0 {
            let formatted = String(format: "%.1f", serverFuel)
            let cleaned = dispatch.fuelOnboard
                .replacingOccurrences(of: "USG", with: "", options: .caseInsensitive)
                .trimmingCharacters(in: .whitespacesAndNewlines)
            let current = Double(cleaned)
            if fuelEmpty || current == nil || abs((current ?? -1) - serverFuel) > 0.05 {
                dispatch.fuelOnboard = formatted
                if dispatch.previousFuelRemaining == nil
                    || dispatch.previousFuelRemaining?.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty == true {
                    dispatch.previousFuelRemaining = formatted
                }
                changed = true
            }
        } else if fuelEmpty,
                  let fuel = carryover?.fuelRemaining,
                  !fuel.isEmpty {
            dispatch.fuelOnboard = fuel
            dispatch.previousFuelRemaining = fuel
            changed = true
        }

        if dispatch.effectiveStartingOilQuantity == nil,
           let oil = carryover?.oilQuantity {
            dispatch.startingOilQuantity = oil
            dispatch.oilPercentage = carryover?.oilPercentage
            dispatch.startingOilUnit = carryover?.oilUnit ?? dispatch.startingOilUnit
            dispatch.previousEndingOilQuantity = oil
            dispatch.previousOilPercentage = carryover?.oilPercentage
            dispatch.previousEndingOilUnit = carryover?.oilUnit
            changed = true
        } else if dispatch.effectiveStartingOilQuantity == nil,
                  let oilPct = carryover?.oilPercentage {
            dispatch.oilPercentage = oilPct
            dispatch.startingOilQuantity = Double(oilPct)
            dispatch.startingOilUnit = carryover?.oilUnit ?? "%"
            changed = true
        }
        if dispatch.previousFlightRecordID == nil, let previousID = carryover?.flightRecordID {
            dispatch.previousFlightRecordID = previousID
            changed = true
        }

        guard changed else { return }
        dispatch.modifiedAt = Date()
        dispatch.status = Self.dispatchStatus(for: dispatch, consents: state.consents)
        _ = mutate {
            $0.activeDispatch = dispatch
        }
    }

    private func continuityCarryover(for registration: String) -> (
        flightRecordID: String,
        endingHobbs: Double,
        endingTacho: Double,
        fuelRemaining: String,
        oilPercentage: Int?,
        oilQuantity: Double?,
        oilUnit: String?
    )? {
        guard let session = state.operationalSession,
              session.engineSessionContinuityActive,
              let hobbs = session.carryoverHobbs,
              let tacho = session.carryoverTacho,
              let fuel = session.carryoverFuel,
              !fuel.isEmpty else {
            return nil
        }
        _ = registration
        return (
            "continuity-carryover",
            hobbs,
            tacho,
            fuel,
            session.carryoverOilPercentage,
            session.carryoverOilQuantity,
            session.carryoverOilUnit
        )
    }

    /// Merges continuity session values with the latest closed archive so oil/crew
    /// still fill when session oil fields are missing (or continuity is partial).
    private func resolvedLegCarryover(for registration: String) -> (
        flightRecordID: String,
        endingHobbs: Double,
        endingTacho: Double,
        fuelRemaining: String,
        oilPercentage: Int?,
        oilQuantity: Double?,
        oilUnit: String?
    )? {
        let continuity = continuityCarryover(for: registration)
        let archived = latestClosedCarryover(for: registration)
        guard continuity != nil || archived != nil else { return nil }

        guard let hobbs = continuity?.endingHobbs ?? archived?.endingHobbs,
              let tacho = continuity?.endingTacho ?? archived?.endingTacho else {
            return nil
        }
        let fuel = (continuity?.fuelRemaining ?? archived?.fuelRemaining ?? "")
            .trimmingCharacters(in: .whitespacesAndNewlines)
        guard !fuel.isEmpty else { return nil }

        return (
            continuity?.flightRecordID ?? archived?.flightRecordID ?? "carryover",
            hobbs,
            tacho,
            fuel,
            continuity?.oilPercentage ?? archived?.oilPercentage,
            continuity?.oilQuantity ?? archived?.oilQuantity,
            {
                let unit = (continuity?.oilUnit ?? archived?.oilUnit)?
                    .trimmingCharacters(in: .whitespacesAndNewlines)
                return (unit?.isEmpty == false) ? unit : nil
            }()
        )
    }

    /// Crew remembered from the previous closed leg — session first, then latest archive.
    private func previousLegCrewCarryover(for registration: String? = nil) -> [CVRCrewAssignment]? {
        if let crew = state.operationalSession?.carryoverCrew, !crew.isEmpty {
            return crew
        }
        let normalizedRegistration = (registration
            ?? state.operationalSession?.plannedLegs.first?.tailNumber
            ?? state.activeDispatch?.tailNumber
            ?? "")
            .trimmingCharacters(in: .whitespacesAndNewlines)
            .uppercased()
        guard !normalizedRegistration.isEmpty else { return nil }

        let reservation = state.operationalSession?.reservationUUID
            .flatMap { CVROperationalIdentityLocal.normalizeUUID($0) }

        let candidates = archives
            .filter {
                $0.dispatch.tailNumber.trimmingCharacters(in: .whitespacesAndNewlines).uppercased()
                    == normalizedRegistration
                    && !$0.dispatch.crew.isEmpty
            }
            .sorted { $0.archivedAt > $1.archivedAt }

        if let reservation,
           let match = candidates.first(where: {
               CVROperationalIdentityLocal.normalizeUUID(
                   $0.dispatch.operationalIdentity?.reservationUUID ?? ""
               ) == reservation
           }) {
            return match.dispatch.crew
        }
        return candidates.first?.dispatch.crew
    }

    private static func remintedCrewAssignments(_ crew: [CVRCrewAssignment]) -> [CVRCrewAssignment] {
        crew.map { member in
            CVRCrewAssignment(
                id: UUID().uuidString,
                personID: member.personID,
                personName: member.personName,
                role: member.role
            )
        }
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
                $0.uploadComponents[index].requestPayloadSnapshot = nil
                if $0.uploadComponents[index].state == .failed || $0.uploadComponents[index].state == .needsUserAction {
                    $0.uploadComponents[index].state = .queued
                    $0.uploadComponents[index].lastError = ""
                    $0.uploadComponents[index].progress = 0
                }
            }
        }
        return true
    }

    /// Restore Dispatch crew from the matching online scheduled session (fixes carryover overwrite).
    @discardableResult
    func repairDispatchCrewFromScheduledSessions(_ sessions: [CVRScheduledSession]) -> Bool {
        guard let dispatch = state.activeDispatch,
              let schedulerRecordID = dispatch.schedulerRecordID?.trimmingCharacters(in: .whitespacesAndNewlines),
              !schedulerRecordID.isEmpty else {
            return false
        }
        guard let session = sessions.first(where: {
            $0.schedulerRecordID.caseInsensitiveCompare(schedulerRecordID) == .orderedSame
        }) else {
            return false
        }
        let scheduledCrew = session.crew.map { member in
            CVRCrewAssignment(
                id: UUID().uuidString,
                personID: member.personID,
                personName: member.personName,
                role: Self.crewRole(from: member.role)
            )
        }
        guard !scheduledCrew.isEmpty else { return false }
        let currentSignature = dispatch.crew
            .map { "\($0.personID ?? 0):\($0.personName.lowercased()):\($0.role.rawValue)" }
            .sorted()
            .joined(separator: "|")
        let scheduledSignature = scheduledCrew
            .map { "\($0.personID ?? 0):\($0.personName.lowercased()):\($0.role.rawValue)" }
            .sorted()
            .joined(separator: "|")
        guard currentSignature != scheduledSignature else { return true }

        return mutate {
            guard var active = $0.activeDispatch else { return }
            active.crew = scheduledCrew
            active.modifiedAt = Date()
            $0.activeDispatch = active
            // Phase 3 operational consents must follow repaired crew.
            $0.consents = Self.ensuredOperationalConsents(
                for: active,
                existing: [],
                deviceID: active.configuredCVRUnitID,
                appVersion: Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String ?? "1.0"
            )
            for index in $0.uploadComponents.indices {
                guard $0.uploadComponents[index].componentType == "dispatch_metadata" else { continue }
                $0.uploadComponents[index].requestPayloadSnapshot = nil
                if $0.uploadComponents[index].state == .failed
                    || $0.uploadComponents[index].state == .needsUserAction
                    || $0.uploadComponents[index].state == .queued {
                    $0.uploadComponents[index].state = .queued
                    $0.uploadComponents[index].lastError = ""
                    $0.uploadComponents[index].progress = 0
                }
            }
        }
    }

    /// Same repair for archived Dispatch rows (Log RETRY path).
    @discardableResult
    func repairArchivedDispatchCrewFromScheduledSessions(
        flightRecordID: String,
        sessions: [CVRScheduledSession]
    ) -> Bool {
        guard let archiveIndex = archives.firstIndex(where: { $0.flightRecord.id == flightRecordID }) else {
            return false
        }
        var archive = archives[archiveIndex]
        guard let schedulerRecordID = archive.dispatch.schedulerRecordID?
            .trimmingCharacters(in: .whitespacesAndNewlines),
              !schedulerRecordID.isEmpty,
              let session = sessions.first(where: {
                  $0.schedulerRecordID.caseInsensitiveCompare(schedulerRecordID) == .orderedSame
              }) else {
            return false
        }
        let scheduledCrew = session.crew.map { member in
            CVRCrewAssignment(
                id: UUID().uuidString,
                personID: member.personID,
                personName: member.personName,
                role: Self.crewRole(from: member.role)
            )
        }
        guard !scheduledCrew.isEmpty else { return false }
        archive.dispatch.crew = scheduledCrew
        archive.dispatch.modifiedAt = Date()
        archive.consents = Self.ensuredOperationalConsents(
            for: archive.dispatch,
            existing: [],
            deviceID: archive.dispatch.configuredCVRUnitID,
            appVersion: Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String ?? "1.0"
        )
        for index in archive.uploadComponents.indices {
            guard archive.uploadComponents[index].componentType == "dispatch_metadata" else { continue }
            archive.uploadComponents[index].requestPayloadSnapshot = nil
            if archive.uploadComponents[index].state == .failed
                || archive.uploadComponents[index].state == .needsUserAction {
                archive.uploadComponents[index].state = .queued
                archive.uploadComponents[index].lastError = ""
                archive.uploadComponents[index].progress = 0
            }
        }
        archives[archiveIndex] = archive
        do {
            try saveArchives(archives)
            return true
        } catch {
            lastError = "Could not save the repaired Dispatch crew: \(error.localizedDescription)"
            return false
        }
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

        var updated = archives
        var changed = false
        for archiveIndex in updated.indices {
            let includesDispatch = componentTypes == nil || componentTypes?.contains("dispatch_metadata") == true
            if includesDispatch {
                changed = Self.repairArchivedDispatchConsents(in: &updated[archiveIndex]) || changed
            }
            for componentIndex in updated[archiveIndex].uploadComponents.indices {
                let component = updated[archiveIndex].uploadComponents[componentIndex]
                guard component.state == .failed || component.state == .needsUserAction else { continue }
                if let componentTypes, !componentTypes.contains(component.componentType) { continue }
                updated[archiveIndex].uploadComponents[componentIndex].state = .queued
                updated[archiveIndex].uploadComponents[componentIndex].lastError = ""
                updated[archiveIndex].uploadComponents[componentIndex].progress = 0
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
            lastError = "Could not requeue archived flight uploads: \(error.localizedDescription)"
        }
    }

    func requeueFailedUploads(forFlightRecordID flightRecordID: String) {
        mutate {
            guard $0.activeFlightRecord?.id == flightRecordID else { return }
            _ = Self.repairStaleDispatchConsents(in: &$0)
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
        var changed = Self.repairArchivedDispatchConsents(in: &updated[archiveIndex])
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

    /// After a successful server Log refresh, drop fully verified local archives that no longer exist online.
    /// Keeps upload-pending archives so unfinished sync work remains visible.
    @discardableResult
    func pruneServerVerifiedArchives(keepingFlightRecordIDs remoteFlightRecordIDs: Set<String>) -> Int {
        let keep = Set(remoteFlightRecordIDs.map { $0.lowercased() })
        let before = archives.count
        let retained = archives.filter { archive in
            if archive.status != .serverVerified {
                return true
            }
            return keep.contains(archive.flightRecordID.lowercased())
        }
        let removed = before - retained.count
        guard removed > 0 else { return 0 }
        do {
            try saveArchives(retained)
            archives = retained
            lastError = ""
        } catch {
            lastError = "Could not clear removed flights from local History."
            return 0
        }
        return removed
    }

    func resetForNextFlightIfComplete(archiveCompletedWorkflow: Bool = true) {
        guard let flightRecord = state.activeFlightRecord,
              flightRecord.endingHobbs != nil,
              flightRecord.endingTacho != nil else {
            lastError = "Check-In must be saved locally before opening the next flight."
            return
        }
        if flightRecord.status == .awaitingAvionicsOff
            || state.operationalSession?.awaitingAvionicsOffConfirmation == true {
            lastError = "Wait for avionics OFF before completing Engine Shutdown."
            return
        }
        if archiveCompletedWorkflow {
            guard archiveActiveWorkflow() else { return }
        }

        mutate {
            if var session = $0.operationalSession, Self.hasOpenPlannedLegs(in: session) {
                session.engineSessionContinuityActive = false
                session.pendingCheckInMode = nil
                session.awaitingAvionicsOffConfirmation = false
                session.continuityEngineStartSynthesized = false
                session.pendingSoftStartRecording = false
                Self.sanitizePlannedLegStatuses(in: &session)
                $0.operationalSession = session
            } else {
                $0.operationalSession = nil
            }
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
        if flightRecord.status == .awaitingAvionicsOff
            || state.operationalSession?.awaitingAvionicsOffConfirmation == true {
            return false
        }
        if flightRecord.checkInMode == .transientStop
            || state.operationalSession?.pendingCheckInMode == .transientStop {
            return completeTransientStopLocally()
        }
        return completeEngineShutdownAfterAvionicsOff()
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

    /// Reservation-scoped crew: after leg 1 is checked in / later legs opened, people and roles cannot change.
    /// Different crew or a PIC role swap requires a new reservation (same rule as online schedule).
    var isReservationCrewLocked: Bool {
        guard let session = state.operationalSession else { return false }
        if session.plannedLegs.contains(where: {
            let status = $0.status.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
            return status == "checked_in"
        }) {
            return true
        }
        if (session.currentLegIndex ?? 1) > 1 {
            return true
        }
        if let carryover = session.carryoverCrew, !carryover.isEmpty,
           (session.currentLegIndex ?? 1) > 1 {
            return true
        }
        return false
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
                    archive.flightRecord.endingOilPercentage ?? archive.dispatch.oilPercentage,
                    archive.flightRecord.effectiveEndingOilQuantity ?? archive.dispatch.effectiveStartingOilQuantity,
                    {
                        if let ending = archive.flightRecord.endingOilUnit?
                            .trimmingCharacters(in: .whitespacesAndNewlines),
                           !ending.isEmpty {
                            return ending
                        }
                        if archive.dispatch.effectiveStartingOilQuantity != nil {
                            return archive.dispatch.effectiveStartingOilUnit
                        }
                        return archive.dispatch.startingOilUnit
                    }()
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

    /// Local route may be edited until the first leg is actually dispatched.
    var canEditLocalRoute: Bool {
        guard state.activeDispatch != nil, !isDispatchLocked else { return false }
        let statuses = state.plannedLegs.map(\.status)
        return !CVRDispatchRouteOverview.isRouteEditingLocked(statuses: statuses)
    }

    func sanitizeRouteStatusesIfNeeded() {
        guard state.operationalSession != nil else { return }
        _ = mutate {
            var session = $0.operationalSession ?? .empty
            Self.sanitizePlannedLegStatuses(in: &session)
            $0.operationalSession = session
        }
    }

    /// Replace planned route from the Create/Edit Local Dispatch draft while preserving UUIDs.
    func applyLocalRouteDraft(_ draft: CVRLocalDispatchDraft) {
        lastError = ""
        guard canEditLocalRoute else {
            lastError = "The route can no longer be changed after a leg has been dispatched."
            return
        }
        guard !draft.legs.isEmpty else {
            lastError = "Add at least one flight leg."
            return
        }
        for (index, leg) in draft.legs.enumerated() {
            let dep = CVROperationalIdentityLocal.normalizeAirport(leg.departureAirport)
            let arr = CVROperationalIdentityLocal.normalizeAirport(leg.arrivalAirport)
            if index == 0 && (dep.isEmpty || !CVRLocalDispatchDraft.isValidICAOIdentifier(dep)) {
                lastError = dep.isEmpty ? "Enter the departure airport." : "Airport code must be a valid ICAO identifier."
                return
            }
            if arr.isEmpty {
                lastError = index == 0 ? "Enter the destination airport." : "Enter the destination for Leg \(index + 1)."
                return
            }
            if !CVRLocalDispatchDraft.isValidICAOIdentifier(arr)
                || (index > 0 && !CVRLocalDispatchDraft.isValidICAOIdentifier(dep)) {
                lastError = "Airport code must be a valid ICAO identifier."
                return
            }
            if index > 0 {
                let expected = CVROperationalIdentityLocal.normalizeAirport(draft.legs[index - 1].arrivalAirport)
                if expected != dep {
                    lastError = "Enter the destination for Leg \(index)."
                    return
                }
            }
        }

        let mission = draft.selectedMissionCode.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
            ? (state.activeDispatch?.missionCode ?? "")
            : draft.selectedMissionCode
        let reservation = CVROperationalIdentityLocal.normalizeUUID(draft.reservationUUID)
            ?? state.operationalSession?.reservationUUID
            ?? UUID().uuidString.lowercased()
        let tail = state.activeDispatch?.tailNumber ?? ""

        let plannedLegs: [CVRPlannedLegRecord] = draft.legs.enumerated().map { index, leg in
            CVRPlannedLegRecord(
                id: leg.legUUID,
                reservationUUID: reservation,
                legUUID: leg.legUUID,
                sequenceNumber: index + 1,
                departureAirport: CVROperationalIdentityLocal.normalizeAirport(leg.departureAirport),
                destinationAirport: CVROperationalIdentityLocal.normalizeAirport(leg.arrivalAirport),
                missionCode: mission,
                tailNumber: tail,
                schedulerRecordID: nil,
                plannedStartAt: Date(),
                plannedEndAt: nil,
                status: "planned"
            )
        }

        _ = mutate {
            var session = $0.operationalSession ?? .empty
            session.reservationUUID = reservation
            session.plannedLegs = plannedLegs
            session.currentLegIndex = 1
            Self.sanitizePlannedLegStatuses(in: &session)
            $0.operationalSession = session
            if var dispatch = $0.activeDispatch, let first = plannedLegs.first {
                dispatch.plannedDepartureAirport = first.departureAirport
                dispatch.plannedDestinationAirport = first.destinationAirport
                if !mission.isEmpty {
                    dispatch.missionCode = mission
                }
                if var identity = dispatch.operationalIdentity {
                    identity.reservationUUID = reservation
                    identity.legUUID = first.legUUID
                    identity.originAirport = first.departureAirport
                    identity.destinationAirport = first.destinationAirport
                    dispatch.operationalIdentity = identity
                }
                dispatch.modifiedAt = Date()
                $0.activeDispatch = dispatch
            }
        }
    }

    private static func activatePlannedLeg(_ legUUID: String, in session: inout CVROperationalSessionContext) {
        let normalized = CVROperationalIdentityLocal.normalizeUUID(legUUID) ?? legUUID.lowercased()
        for index in session.plannedLegs.indices {
            let legNormalized = CVROperationalIdentityLocal.normalizeUUID(session.plannedLegs[index].legUUID)
                ?? session.plannedLegs[index].legUUID.lowercased()
            let status = session.plannedLegs[index].status.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
            if legNormalized == normalized {
                if status != "checked_in" && status != "cancelled" && status != "canceled" {
                    session.plannedLegs[index].status = "active"
                }
                session.currentLegIndex = session.plannedLegs[index].sequenceNumber
            } else if status == "active" {
                session.plannedLegs[index].status = "planned"
            }
        }
        sanitizePlannedLegStatuses(in: &session)
    }

    private static func markCurrentPlannedLeg(dispatchedIn session: inout CVROperationalSessionContext, dispatch: CVRDispatchRecord) {
        let currentUUID = dispatch.operationalIdentity?.legUUID
        let currentIndex = session.currentLegIndex ?? 1
        for index in session.plannedLegs.indices {
            let leg = session.plannedLegs[index]
            let status = leg.status.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
            if status == "checked_in" || status == "cancelled" || status == "canceled" {
                continue
            }
            let matchesUUID: Bool = {
                guard let currentUUID,
                      let left = CVROperationalIdentityLocal.normalizeUUID(leg.legUUID),
                      let right = CVROperationalIdentityLocal.normalizeUUID(currentUUID) else { return false }
                return left == right
            }()
            let matchesIndex = currentUUID == nil && leg.sequenceNumber == currentIndex
            if matchesUUID || matchesIndex {
                session.plannedLegs[index].status = "dispatched"
                session.currentLegIndex = leg.sequenceNumber
            } else if status == "active" || status == "dispatched" {
                session.plannedLegs[index].status = "planned"
            }
        }
        sanitizePlannedLegStatuses(in: &session)
    }

    private static func unmarkDispatchedPlannedLeg(
        in session: inout CVROperationalSessionContext,
        dispatchID: String,
        flightRecordID: String
    ) {
        _ = dispatchID
        _ = flightRecordID
        for index in session.plannedLegs.indices {
            let status = session.plannedLegs[index].status.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
            if status == "dispatched" {
                session.plannedLegs[index].status = "active"
            }
        }
        sanitizePlannedLegStatuses(in: &session)
    }

    /// At most one Active/Dispatched current leg; checked-in/cancelled are preserved.
    private static func sanitizePlannedLegStatuses(in session: inout CVROperationalSessionContext) {
        let currentUUID = session.plannedLegs.first(where: {
            let status = $0.status.lowercased()
            return status == "active" || status == "dispatched"
        })?.legUUID
        let preferredUUID = currentUUID
            ?? session.currentLegIndex.flatMap { index in
                session.plannedLegs.first(where: { $0.sequenceNumber == index })?.legUUID
            }
        var sawCurrent = false
        for index in session.plannedLegs.indices {
            let status = session.plannedLegs[index].status.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
            if status == "checked_in" || status == "cancelled" || status == "canceled" {
                continue
            }
            let isPreferred = preferredUUID.map {
                (CVROperationalIdentityLocal.normalizeUUID(session.plannedLegs[index].legUUID)
                    ?? session.plannedLegs[index].legUUID.lowercased())
                    == (CVROperationalIdentityLocal.normalizeUUID($0) ?? $0.lowercased())
            } ?? (session.plannedLegs[index].sequenceNumber == (session.currentLegIndex ?? 1))

            if (status == "active" || status == "dispatched") && (!isPreferred || sawCurrent) {
                session.plannedLegs[index].status = "planned"
            } else if (status == "active" || status == "dispatched") && isPreferred {
                sawCurrent = true
            }
        }
    }

    private static func dispatchStatus(for dispatch: CVRDispatchRecord, consents: [CVRConsentRecord]) -> CVRDispatchStatus {
        _ = consents
        if !dispatch.missingItems.isEmpty {
            return .dispatchIncomplete
        }
        // Phase 3 operational flight-test: no crew consent gate.
        return .readyForVerification
    }

    private static func hasRequiredConsents(dispatch: CVRDispatchRecord, consents: [CVRConsentRecord]) -> Bool {
        _ = dispatch
        _ = consents
        return true
    }

    /// Phase 3 operational-test consent text version. Marks server-bound consent evidence as waived UI.
    private static let operationalConsentTextVersion = "phase3_operational_flight_test_waiver"

    private static func ensuredOperationalConsents(
        for dispatch: CVRDispatchRecord,
        existing: [CVRConsentRecord],
        deviceID: String,
        appVersion: String
    ) -> [CVRConsentRecord] {
        var consents = existing.filter { $0.dispatchID == dispatch.id }
        let now = Date()
        for assignment in dispatch.crew {
            if consents.contains(where: {
                $0.dispatchID == dispatch.id
                    && $0.dispatchVersion == dispatch.version
                    && $0.personName == assignment.personName
                    && $0.crewRole == assignment.role
                    && $0.consentResult
            }) {
                continue
            }
            consents.removeAll {
                $0.dispatchID == dispatch.id
                    && $0.personName == assignment.personName
                    && $0.crewRole == assignment.role
            }
            consents.append(CVRConsentRecord(
                id: UUID().uuidString,
                personID: assignment.personID,
                personName: assignment.personName,
                crewRole: assignment.role,
                consentResult: true,
                timestamp: now,
                deviceID: deviceID.isEmpty ? "local_cvr_unit" : deviceID,
                dispatchID: dispatch.id,
                dispatchVersion: dispatch.version,
                consentTextVersion: operationalConsentTextVersion,
                appVersion: appVersion
            ))
        }
        let other = existing.filter { $0.dispatchID != dispatch.id }
        return other + consents
    }

    private static func repairStaleDispatchConsents(in workflow: inout CVRWorkflowState) -> Bool {
        guard var dispatch = workflow.activeDispatch,
              workflow.uploadComponents.contains(where: {
                  $0.componentType == "dispatch_metadata"
                      && ($0.state == .failed || $0.state == .needsUserAction)
                      && $0.lastError.localizedCaseInsensitiveContains("consent")
              }) else {
            return false
        }

        let appVersion = Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String ?? "1.0"
        let repairedConsents = ensuredOperationalConsents(
            for: dispatch,
            existing: workflow.consents,
            deviceID: dispatch.configuredCVRUnitID,
            appVersion: appVersion
        )
        workflow.consents = repairedConsents
        dispatch.consentStatus = "complete"
        workflow.activeDispatch = dispatch
        for index in workflow.uploadComponents.indices {
            guard workflow.uploadComponents[index].componentType == "dispatch_metadata",
                  workflow.uploadComponents[index].lastError.localizedCaseInsensitiveContains("consent"),
                  workflow.uploadComponents[index].state == .failed
                      || workflow.uploadComponents[index].state == .needsUserAction else {
                continue
            }
            // Drop the failed empty-consent snapshot so retry rebuilds with operational consents.
            workflow.uploadComponents[index].requestPayloadSnapshot = nil
            workflow.uploadComponents[index].reconciliationRequired = true
            workflow.uploadComponents[index].state = .queued
            workflow.uploadComponents[index].progress = 0
            workflow.uploadComponents[index].lastError = "Recovered Phase 3 operational consent; Dispatch is queued for retry."
        }
        return true
    }

    @discardableResult
    private static func repairArchivedDispatchConsents(in archive: inout CVRWorkflowArchiveRecord) -> Bool {
        let consentFailedComponents = archive.uploadComponents.filter {
            $0.componentType == "dispatch_metadata"
                && ($0.state == .failed || $0.state == .needsUserAction || $0.state == .queued)
                && $0.lastError.localizedCaseInsensitiveContains("consent")
        }
        let missingCrewConsents = archive.dispatch.crew.contains { assignment in
            !archive.consents.contains {
                $0.dispatchID == archive.dispatch.id
                    && $0.dispatchVersion == archive.dispatch.version
                    && $0.personName == assignment.personName
                    && $0.crewRole == assignment.role
                    && $0.consentResult
            }
        }
        let hasDispatchUpload = archive.uploadComponents.contains { $0.componentType == "dispatch_metadata" }
        guard !consentFailedComponents.isEmpty || (hasDispatchUpload && missingCrewConsents) else {
            return false
        }

        let appVersion = Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String ?? "1.0"
        archive.consents = ensuredOperationalConsents(
            for: archive.dispatch,
            existing: archive.consents,
            deviceID: archive.dispatch.configuredCVRUnitID,
            appVersion: appVersion
        )
        archive.dispatch.consentStatus = "complete"

        for index in archive.uploadComponents.indices {
            guard archive.uploadComponents[index].componentType == "dispatch_metadata" else { continue }
            let mentionsConsent = archive.uploadComponents[index].lastError
                .localizedCaseInsensitiveContains("consent")
            let isFailed = archive.uploadComponents[index].state == .failed
                || archive.uploadComponents[index].state == .needsUserAction
            guard mentionsConsent || isFailed || missingCrewConsents else { continue }

            // Clear the failed empty-consent snapshot so retry rebuilds a valid payload.
            archive.uploadComponents[index].requestPayloadSnapshot = nil
            archive.uploadComponents[index].reconciliationRequired = true
            archive.uploadComponents[index].state = .queued
            archive.uploadComponents[index].progress = 0
            archive.uploadComponents[index].lastError =
                "Recovered Phase 3 operational consent; Dispatch is queued for retry."
        }
        return true
    }

    @discardableResult
    private func repairConsentFailuresInArchives() -> Bool {
        guard archiveRewriteSafe else { return false }
        var updated = archives
        var changed = false
        for index in updated.indices {
            if Self.repairArchivedDispatchConsents(in: &updated[index]) {
                updated[index].status = .uploadPending
                changed = true
            }
        }
        guard changed else { return false }
        do {
            try saveArchives(updated)
            archives = updated
            return true
        } catch {
            lastError = "Could not repair archived Dispatch consent uploads: \(error.localizedDescription)"
            return false
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
