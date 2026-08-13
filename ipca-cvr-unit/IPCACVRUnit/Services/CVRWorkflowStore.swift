import CryptoKit
import Foundation

enum CVRWorkflowFailureOutcome: Equatable {
    case queued
    case authenticationPaused
    case userCorrectionRequired
    case technicalReviewRequired
}

enum CVRScheduleDutySyncPhase: Equatable {
    case queued
    case syncing
    case synced
    case syncedWithWarning
    case attention
}

struct CVRScheduleDutySyncInfo: Equatable {
    var phase: CVRScheduleDutySyncPhase
    var message: String
}

@MainActor
final class CVRWorkflowStore: ObservableObject {
    static let maximumRequestPayloadSnapshotBytes = 256 * 1024

    private static let inferredEngineStartAvionicsDwellSeconds: TimeInterval = 60
    private static let inferredEngineStartTaxiSpeedKnots: Double = 3
    private static let inferredEngineStartTaxiConfirmSeconds: TimeInterval = 5
    private static let inferredEngineStartWarmUpSeconds: TimeInterval = 180
    private static let inferredEngineStartMaxLookbackSeconds: TimeInterval = 300
    private static let iso8601UTC: ISO8601DateFormatter = {
        let formatter = ISO8601DateFormatter()
        formatter.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        formatter.timeZone = TimeZone(secondsFromGMT: 0)
        return formatter
    }()

    @Published private(set) var state: CVRWorkflowState = .empty
    @Published private(set) var archives: [CVRWorkflowArchiveRecord] = []
    /// Soft-voided Log rows (local hide). Includes remote-only flight record IDs.
    @Published private(set) var voidedFlightRecordIDs: Set<String> = []
    @Published private(set) var lastError = ""
    @Published private(set) var scheduleRefreshRevision = 0

    func reportSynchronizationMessage(_ message: String) {
        lastError = message
    }

    private let encoder: JSONEncoder
    private let decoder: JSONDecoder
    private var archiveRewriteSafe = true
    /// Wall-clock when avionics first came ON for the current power session (arms taxi inference).
    private var avionicsOnSince: Date?
    /// Continuous taxi-speed window start for forgotten Engine Start inference.
    private var taxiMotionAboveThresholdSince: Date?

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
        loadVoidedFlightRecordIDs()
        // Archives marked voided before the dedicated void set existed.
        for archive in archives where archive.voidedAt != nil {
            voidedFlightRecordIDs.insert(archive.flightRecordID.lowercased())
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
        canonicalWriteEnabled: Bool = false,
        operationalSessionModelEnabled: Bool = false,
        missionCode: String = "",
        crew: [CVRCrewAssignment] = [],
        informativeRouteAirports: [String] = [],
        forceNewReservation: Bool = false,
        scheduledStartTime: Date? = nil,
        scheduledEndTime: Date? = nil
    ) {
        guard let selectedAircraft else {
            lastError = "Aircraft configuration is required before creating a Dispatch."
            return
        }
        lastError = ""
        let registration = selectedAircraft.registration
        let routeAirports = informativeRouteAirports.map {
            CVROperationalIdentityLocal.normalizeAirport($0)
        }
        if !routeAirports.isEmpty {
            guard routeAirports.count >= 2,
                  routeAirports.allSatisfy({
                      !$0.isEmpty && CVRLocalDispatchDraft.isValidICAOIdentifier($0)
                  }) else {
                lastError = "Complete each airport in the informative route, or leave the route empty."
                return
            }
        }
        if !forceNewReservation,
           let existing = state.activeDispatch,
           existing.tailNumber.caseInsensitiveCompare(registration) == .orderedSame,
           Calendar.current.isDate(existing.scheduledDate, inSameDayAs: Date()) {
            mutate {
                $0.selectedTab = .dispatch
            }
            return
        }
        if state.activeFlightRecord != nil || (state.activeDispatch != nil && !forceNewReservation) {
            lastError = "Finish Check-In for the current leg before creating another Dispatch."
            return
        }
        if forceNewReservation {
            guard let scheduledStartTime,
                  let scheduledEndTime,
                  scheduledEndTime > scheduledStartTime else {
                lastError = "A valid Schedule Start and Schedule End are required."
                return
            }
        }

        let continuity = state.operationalSession
        let carryover = resolvedLegCarryover(for: registration)
        let dispatchID = UUID().uuidString
        // Local same-airport default (e.g. KTRM → KTRM). Blank destination must never be created.
        let homeAirport = CVROperationalIdentityLocal.normalizeAirport(selectedAircraft.homeAirport)
        guard operationalSessionModelEnabled || !homeAirport.isEmpty else {
            lastError = "Enter the departure airport."
            return
        }
        var operationalIdentity: CVRLocalOperationalIdentity?
        let reservationUUID = (operationalSessionModelEnabled || forceNewReservation)
            ? UUID().uuidString.lowercased()
            : continuity?.reservationUUID
        if canonicalWriteEnabled && !operationalSessionModelEnabled {
            do {
                operationalIdentity = try CVROperationalIdentityLocal.createOfflineBundle(
                    organizationID: 1,
                    dispatchUUID: dispatchID,
                    organizationTimezoneIANA: TimeZone.current.identifier,
                    originAirport: homeAirport,
                    destinationAirport: homeAirport,
                    reservationUUID: reservationUUID
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

        let schedulerRecordID = forceNewReservation ? reservationUUID : nil
        var operationalCalendar = Calendar(identifier: .gregorian)
        operationalCalendar.timeZone = TimeZone(identifier: "America/Los_Angeles") ?? .current
        var dispatch = CVRDispatchRecord(
            id: dispatchID,
            serverDispatchID: nil,
            organizationID: 1,
            scheduledDate: scheduledStartTime.map { operationalCalendar.startOfDay(for: $0) } ?? Date(),
            scheduledStartTime: scheduledStartTime,
            scheduledEndTime: scheduledEndTime,
            tailNumber: registration,
            aircraftID: selectedAircraft.id,
            missionCode: missionCode.trimmingCharacters(in: .whitespacesAndNewlines).uppercased(),
            plannedDepartureAirport: routeAirports.first ?? homeAirport,
            plannedDestinationAirport: routeAirports.count >= 2
                ? (routeAirports.last ?? "")
                : (operationalSessionModelEnabled ? "" : homeAirport),
            informativeRouteAirports: routeAirports,
            informativePlannedLegUUIDs: routeAirports.count >= 2
                ? (0..<(routeAirports.count - 1)).map { _ in UUID().uuidString.lowercased() }
                : [],
            crew: crew,
            startingHobbs: carryover?.endingHobbs,
            startingTacho: carryover?.endingTacho,
            fuelOnboard: carryover?.fuelRemaining ?? "",
            oilPercentage: carryover?.oilPercentage,
            startingOilQuantity: carryover?.oilQuantity,
            startingOilUnit: carryover?.oilUnit ?? selectedAircraft.operationalConfig.oilUnit,
            dispatchSource: continuity?.engineSessionContinuityActive == true
                ? "transient_stop_carryover"
                : (carryover == nil ? "iphone_offline_local" : "previous_locally_closed_flight_carryover"),
            schedulerRecordID: schedulerRecordID,
            reservationUUID: reservationUUID,
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
            operationalSessionUUID: nil,
            operationalSessionModelVersion: operationalSessionModelEnabled
                ? CVROperationalSessionRecord.modelVersion
                : nil,
            operationalIdentity: operationalIdentity
        )

        let persisted = mutate {
            if operationalSessionModelEnabled {
                // A new local reservation must never inherit the previous
                // execution's legacy planned-leg/continuity context.
                $0.operationalSession = nil
            }
            if forceNewReservation {
                Self.queueLocalScheduleCreation(dispatch: &dispatch, state: &$0)
            }
            $0.activeDispatch = dispatch
            $0.activeFlightRecord = nil
            $0.activeOperationalSession = nil
            $0.consents = []
            $0.recorderVerifications = []
            $0.flightEvents = []
            $0.flightLegs = []
            $0.uploadComponents.removeAll { $0.componentType != "schedule_duty_sync" }
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
            $0.uploadComponents.removeAll { $0.componentType != "schedule_duty_sync" }
            $0.discrepancies = []
            $0.selectedTab = .dispatch
        }
        if !persisted {
            lastError = "Unable to create the multi-leg Dispatch. Please try again."
        }
    }

    func openDispatchFromScheduledSession(
        _ session: CVRScheduledSession,
        reservationSessions: [CVRScheduledSession] = [],
        selectedAircraft: CockpitAircraft?,
        cvrUnitID: String,
        beaconID: String,
        isAudioRecording: Bool,
        canonicalWriteEnabled: Bool = false,
        operationalSessionModelEnabled: Bool = false
    ) {
        openDispatchFromLeg(
            session: session,
            reservationSessions: reservationSessions,
            plannedLeg: nil,
            selectedAircraft: selectedAircraft,
            cvrUnitID: cvrUnitID,
            beaconID: beaconID,
            isAudioRecording: isAudioRecording,
            canonicalWriteEnabled: canonicalWriteEnabled,
            operationalSessionModelEnabled: operationalSessionModelEnabled
        )
    }

    func openDispatchFromPlannedLeg(
        _ plannedLeg: CVRPlannedLegRecord,
        selectedAircraft: CockpitAircraft?,
        cvrUnitID: String,
        beaconID: String,
        isAudioRecording: Bool,
        canonicalWriteEnabled: Bool = false,
        operationalSessionModelEnabled: Bool = false
    ) {
        openDispatchFromLeg(
            session: nil,
            reservationSessions: [],
            plannedLeg: plannedLeg,
            selectedAircraft: selectedAircraft,
            cvrUnitID: cvrUnitID,
            beaconID: beaconID,
            isAudioRecording: isAudioRecording,
            canonicalWriteEnabled: canonicalWriteEnabled,
            operationalSessionModelEnabled: operationalSessionModelEnabled
        )
    }

    private func openDispatchFromLeg(
        session: CVRScheduledSession?,
        reservationSessions: [CVRScheduledSession],
        plannedLeg: CVRPlannedLegRecord?,
        selectedAircraft: CockpitAircraft?,
        cvrUnitID: String,
        beaconID: String,
        isAudioRecording: Bool,
        canonicalWriteEnabled: Bool,
        operationalSessionModelEnabled: Bool
    ) {
        let departure = CVROperationalIdentityLocal.normalizeAirport(
            session?.plannedDepartureAirport ?? plannedLeg?.departureAirport ?? ""
        )
        let destination = CVROperationalIdentityLocal.normalizeAirport(
            session?.plannedDestinationAirport ?? plannedLeg?.destinationAirport ?? ""
        )
        guard operationalSessionModelEnabled || !departure.isEmpty else {
            lastError = "Enter the departure airport."
            return
        }
        guard operationalSessionModelEnabled || !destination.isEmpty else {
            lastError = "Enter the destination airport."
            return
        }
        let missionCode = session?.missionCode ?? plannedLeg?.missionCode ?? ""
        let schedulerRecordID = session?.schedulerRecordID ?? plannedLeg?.schedulerRecordID
        let reservationUUID = session?.reservationUUID
            ?? plannedLeg?.reservationUUID
            ?? (operationalSessionModelEnabled ? UUID().uuidString.lowercased() : nil)
        let legUUID = session?.legUUID ?? plannedLeg?.legUUID
        let registration = selectedAircraft?.registration
            ?? session?.aircraftRegistration
            ?? plannedLeg?.tailNumber
            ?? ""
        let informativeRouteAirports = Self.informativeRouteAirports(
            sessions: reservationSessions.isEmpty ? [session].compactMap { $0 } : reservationSessions,
            fallbackDeparture: departure,
            fallbackDestination: destination
        )
        let informativePlannedLegUUIDs = Self.informativePlannedLegUUIDs(
            sessions: reservationSessions.isEmpty ? [session].compactMap { $0 } : reservationSessions
        )

        guard let selectedAircraft,
              session == nil || scheduledSessionMatchesAircraft(session!, selectedAircraft: selectedAircraft) else {
            lastError = "This scheduled flight does not match the aircraft enrolled to this CVR Unit."
            return
        }

        if let active = state.activeDispatch {
            if let legUUID,
               let activeLeg = active.operationalIdentity?.legUUID,
               CVROperationalIdentityLocal.normalizeUUID(legUUID)
                   == CVROperationalIdentityLocal.normalizeUUID(activeLeg) {
                selectTab(.dispatch)
                return
            }
            // Multi-leg expansions share scheduler_record_id — only treat as already-open
            // when we cannot distinguish legs (legacy single-leg rows without leg_uuid).
            let sameScheduler = schedulerRecordID != nil && active.schedulerRecordID == schedulerRecordID
            let canDistinguishLegs = legUUID != nil && active.operationalIdentity?.legUUID != nil
            if sameScheduler && !canDistinguishLegs {
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
        if canonicalWriteEnabled && !operationalSessionModelEnabled {
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
        } else if !operationalSessionModelEnabled, let reservationUUID, let legUUID,
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
            informativeRouteAirports: informativeRouteAirports,
            informativePlannedLegUUIDs: informativePlannedLegUUIDs,
            crew: {
                // Scheduled sessions must keep the online schedule crew for claim validation.
                // Meter/fuel/oil carryover is separate and must not replace scheduled crew identity.
                if session != nil {
                    return (session?.crew ?? []).map { member in
                        CVRCrewAssignment(
                            id: UUID().uuidString,
                            personID: member.personID,
                            personName: member.personName,
                            role: Self.crewRole(from: member.role),
                            pilotFunction: Self.pilotFunction(from: member.pilotFunction),
                            isPIC: member.isPIC
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
            reservationUUID: reservationUUID,
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
            operationalSessionUUID: nil,
            operationalSessionModelVersion: operationalSessionModelEnabled
                ? CVROperationalSessionRecord.modelVersion
                : nil,
            operationalIdentity: operationalIdentity
        )

        _ = mutate {
            var sessionContext = $0.operationalSession ?? .empty
            if let reservationUUID {
                sessionContext.reservationUUID = reservationUUID.lowercased()
            }
            if operationalSessionModelEnabled {
                sessionContext.plannedLegs = []
                sessionContext.currentLegIndex = nil
            } else if let plannedLeg {
                Self.activatePlannedLeg(plannedLeg.legUUID, in: &sessionContext)
            } else if let session {
                // Seed from all reservation siblings so Transient Stop / next-leg continuity works
                // the same as local multi-leg create (Schedule UI already groups by reservation_uuid).
                Self.seedPlannedLegsFromScheduledReservation(
                    into: &sessionContext,
                    openingSession: session,
                    reservationSessions: reservationSessions,
                    registration: selectedAircraft.registration
                )
                if let legUUID {
                    Self.activatePlannedLeg(legUUID, in: &sessionContext)
                } else {
                    sessionContext.currentLegIndex = sessionContext.plannedLegs.first?.sequenceNumber ?? 1
                }
            } else if let legUUID, sessionContext.plannedLegs.contains(where: {
                CVROperationalIdentityLocal.normalizeUUID($0.legUUID)
                    == CVROperationalIdentityLocal.normalizeUUID(legUUID)
            }) {
                Self.activatePlannedLeg(legUUID, in: &sessionContext)
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
            $0.uploadComponents.removeAll { $0.componentType != "schedule_duty_sync" }
            $0.discrepancies = []
            $0.selectedTab = .dispatch
        }
    }

    private static func informativeRouteAirports(
        sessions: [CVRScheduledSession],
        fallbackDeparture: String,
        fallbackDestination: String
    ) -> [String] {
        let ordered = sessions.sorted {
            let left = $0.legSequenceNumber ?? Int.max
            let right = $1.legSequenceNumber ?? Int.max
            if left != right { return left < right }
            return ($0.dateTime($0.scheduledStartTime) ?? .distantFuture)
                < ($1.dateTime($1.scheduledStartTime) ?? .distantFuture)
        }
        var airports: [String] = []
        func append(_ value: String) {
            let airport = CVROperationalIdentityLocal.normalizeAirport(value)
            guard !airport.isEmpty, airports.last != airport else { return }
            airports.append(airport)
        }
        for session in ordered {
            append(session.plannedDepartureAirport)
            append(session.plannedDestinationAirport)
        }
        if airports.isEmpty {
            append(fallbackDeparture)
            append(fallbackDestination)
        }
        return airports
    }

    private static func informativePlannedLegUUIDs(sessions: [CVRScheduledSession]) -> [String] {
        sessions.sorted {
            let left = $0.legSequenceNumber ?? Int.max
            let right = $1.legSequenceNumber ?? Int.max
            if left != right { return left < right }
            return ($0.dateTime($0.scheduledStartTime) ?? .distantFuture)
                < ($1.dateTime($1.scheduledStartTime) ?? .distantFuture)
        }.compactMap {
            CVROperationalIdentityLocal.normalizeUUID($0.legUUID ?? "")
        }
    }

    func canOpenScheduledSession(
        _ session: CVRScheduledSession,
        selectedAircraft: CockpitAircraft?,
        isAudioRecording: Bool
    ) -> Bool {
        _ = selectedAircraft
        if let active = state.activeDispatch {
            if let legUUID = session.legUUID,
               let activeLeg = active.operationalIdentity?.legUUID,
               CVROperationalIdentityLocal.normalizeUUID(legUUID)
                   == CVROperationalIdentityLocal.normalizeUUID(activeLeg) {
                return true
            }
            // Shared scheduler_record_id across multi-leg expansions — only treat as
            // already-open when legs cannot be distinguished.
            if active.schedulerRecordID == session.schedulerRecordID,
               session.legUUID == nil || active.operationalIdentity?.legUUID == nil {
                return true
            }
        }
        if state.engineSessionContinuityActive {
            return true
        }
        return !isAudioRecording
    }

    func requiresArchivingBeforeScheduledSession(_ session: CVRScheduledSession) -> Bool {
        guard let active = state.activeDispatch else { return false }
        if let legUUID = session.legUUID,
           let activeLeg = active.operationalIdentity?.legUUID,
           CVROperationalIdentityLocal.normalizeUUID(legUUID)
               == CVROperationalIdentityLocal.normalizeUUID(activeLeg) {
            return false
        }
        // Same scheduler_record_id is normal for multi-leg siblings — do not force archive
        // solely on that match when leg UUIDs differ.
        if active.schedulerRecordID == session.schedulerRecordID,
           session.legUUID == nil || active.operationalIdentity?.legUUID == nil {
            return false
        }
        if active.schedulerRecordID == session.schedulerRecordID,
           session.legUUID != nil,
           active.operationalIdentity?.legUUID != nil {
            // Sibling leg of the same reservation — only archive if unfinished flight meters exist.
            if let flightRecord = state.activeFlightRecord {
                return flightRecord.endingHobbs == nil || flightRecord.endingTacho == nil
            }
            return false
        }
        if active.schedulerRecordID != session.schedulerRecordID {
            if let flightRecord = state.activeFlightRecord {
                return flightRecord.endingHobbs == nil || flightRecord.endingTacho == nil
            }
            return false
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
            let previousDutySignature = Self.dutyMaterialSignature(dispatch)
            let previousStatus = dispatch.status
            update(&dispatch)
            dispatch.modifiedAt = Date()
            let materialChanged = previousMaterialSignature != Self.materialSignature(dispatch)
            let dutyChanged = previousDutySignature != Self.dutyMaterialSignature(dispatch)
            if materialChanged {
                dispatch.version += 1
                $0.consents = []
                dispatch.consentStatus = "invalidated_by_dispatch_change"
                if $0.activeFlightRecord?.status == .recorderVerificationRequired {
                    $0.activeFlightRecord = nil
                }
            }
            if dutyChanged {
                Self.remintAndQueueScheduledDutyReplacement(dispatch: &dispatch, state: &$0)
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

    @discardableResult
    func updateActiveScheduleWindow(start: Date, end: Date) -> Bool {
        var scheduleCalendar = Calendar(identifier: .gregorian)
        scheduleCalendar.timeZone = TimeZone(identifier: "America/Los_Angeles") ?? .current
        guard !isDispatchLocked else {
            lastError = "Schedule times cannot be changed after Dispatch."
            return false
        }
        guard end > start,
              scheduleCalendar.isDate(start, inSameDayAs: end) else {
            lastError = "Scheduled Arrival must be later than Scheduled Departure on the same day."
            return false
        }
        var shouldStartUpload = false
        mutate {
            guard var dispatch = $0.activeDispatch,
                  $0.activeFlightRecord == nil,
                  dispatch.schedulerRecordID != nil,
                  dispatch.reservationUUID != nil else {
                return
            }
            dispatch.scheduledDate = scheduleCalendar.startOfDay(for: start)
            dispatch.scheduledStartTime = start
            dispatch.scheduledEndTime = end
            dispatch.modifiedAt = Date()
            let schedulerKey = dispatch.schedulerRecordID?
                .trimmingCharacters(in: .whitespacesAndNewlines)
                .lowercased()
            if var session = $0.operationalSession {
                for index in session.plannedLegs.indices {
                    let plannedScheduler = session.plannedLegs[index].schedulerRecordID?
                        .trimmingCharacters(in: .whitespacesAndNewlines)
                        .lowercased()
                    guard plannedScheduler == schedulerKey else { continue }
                    session.plannedLegs[index].plannedStartAt = start
                    session.plannedLegs[index].plannedEndAt = end
                }
                $0.operationalSession = session
            }
            Self.queueScheduledDutyWindowUpdate(dispatch: dispatch, state: &$0)
            shouldStartUpload = !$0.uploadComponents.contains {
                $0.componentType == "schedule_duty_sync" && $0.state == .uploading
            }
            $0.activeDispatch = dispatch
        }
        lastError = ""
        return shouldStartUpload
    }

    @discardableResult
    func updateActiveInformativeRoute(airports: [String]) -> Bool {
        guard !isDispatchLocked, var dispatch = state.activeDispatch else {
            lastError = "The informative route can only be edited before Dispatch."
            return false
        }
        let normalized = airports.map(CVROperationalIdentityLocal.normalizeAirport)
        if !normalized.isEmpty {
            guard normalized.count >= 2,
                  normalized.allSatisfy(CVRLocalDispatchDraft.isValidICAOIdentifier) else {
                lastError = "Enter at least two valid airport identifiers, or leave the route empty."
                return false
            }
        }
        dispatch.informativeRouteAirports = normalized
        dispatch.informativePlannedLegUUIDs = normalized.count >= 2
            ? (0..<(normalized.count - 1)).map { _ in UUID().uuidString.lowercased() }
            : []
        dispatch.plannedDepartureAirport = normalized.first ?? ""
        dispatch.plannedDestinationAirport = normalized.last ?? ""
        dispatch.modifiedAt = Date()
        var shouldStartUpload = false
        _ = mutate {
            $0.activeDispatch = dispatch
            Self.queueScheduledDutyWindowUpdate(dispatch: dispatch, state: &$0)
            shouldStartUpload = !$0.uploadComponents.contains {
                $0.componentType == "schedule_duty_sync" && $0.state == .uploading
            }
        }
        lastError = ""
        return shouldStartUpload
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
        var operationalSession = state.activeOperationalSession
        if dispatch.operationalSessionModelVersion == CVROperationalSessionRecord.modelVersion {
            guard let reservationUUID = dispatch.reservationUUID
                    ?? dispatch.operationalIdentity?.reservationUUID,
                  CVROperationalIdentityLocal.normalizeUUID(reservationUUID) != nil,
                  let startingHobbs = dispatch.startingHobbs,
                  let startingTacho = dispatch.startingTacho else {
                lastError = "Reservation and starting meters are required to confirm this Dispatch."
                return
            }
            let sessionUUID = dispatch.operationalSessionUUID
                ?? operationalSession?.id
                ?? UUID().uuidString.lowercased()
            if let existing = operationalSession,
               existing.id.lowercased() != sessionUUID.lowercased()
                    || existing.dispatchID.lowercased() != dispatch.id.lowercased()
                    || existing.workflowFlightRecordUUID.lowercased() != flightRecord.id.lowercased()
                    || existing.modelVersion != CVROperationalSessionRecord.modelVersion {
                lastError = "The saved Operational Session identity does not match this Dispatch."
                return
            }
            dispatch.operationalSessionUUID = sessionUUID
            operationalSession = operationalSession ?? CVROperationalSessionRecord(
                id: sessionUUID,
                reservationUUID: reservationUUID.lowercased(),
                dispatchID: dispatch.id.lowercased(),
                workflowFlightRecordUUID: flightRecord.id.lowercased(),
                modelVersion: CVROperationalSessionRecord.modelVersion,
                state: .intended,
                dispatchConfirmedAtUTC: Date(),
                aircraftID: dispatch.aircraftID,
                aircraftRegistration: dispatch.tailNumber,
                startingHobbs: startingHobbs,
                startingTacho: startingTacho,
                startingFuelQuantity: Self.decimalQuantity(from: dispatch.fuelOnboard),
                startingFuelUnit: "USG",
                startingOilQuantity: dispatch.startingOilQuantity
                    ?? dispatch.oilPercentage.map(Double.init),
                startingOilUnit: dispatch.startingOilQuantity != nil
                    ? dispatch.startingOilUnit
                    : (dispatch.oilPercentage == nil ? nil : "PERCENT")
            )
        }
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
            dispatch.consentStatus = dispatch.operationalSessionModelVersion
                == CVROperationalSessionRecord.modelVersion ? "not_required" : "complete"
            dispatch.modifiedAt = Date()
            $0.activeDispatch = dispatch
            $0.activeFlightRecord = flightRecord
            $0.activeOperationalSession = operationalSession
            if dispatch.operationalSessionModelVersion == CVROperationalSessionRecord.modelVersion {
                $0.consents.removeAll { $0.dispatchID == dispatch.id }
            } else {
                $0.consents = Self.ensuredOperationalConsents(
                    for: dispatch,
                    existing: $0.consents,
                    deviceID: dispatch.configuredCVRUnitID,
                    appVersion: Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String ?? "1.0"
                )
            }
            if !$0.uploadComponents.contains(where: { $0.id == dispatchComponent.id }) {
                $0.uploadComponents.append(dispatchComponent)
            }
            var session = $0.operationalSession ?? .empty
            Self.markCurrentPlannedLeg(dispatchedIn: &session, dispatch: dispatch)
            $0.operationalSession = session
            // In-Flight is opened after auto recorder verification at Dispatch confirm.
        }
        // Continuity legs must create Off Block locally as soon as the Flight Record exists.
        if state.engineSessionContinuityActive {
            _ = synthesizeEngineContinuityIfNeeded(gpsSample: nil)
        }
    }

    private static func decimalQuantity(from value: String) -> Double? {
        Double(value.split(whereSeparator: { $0 == " " || $0 == "\t" }).first ?? "")
    }

    /// Cancel unused planned legs while keeping the active Engine Shutdown Check-In open.
    @discardableResult
    func cancelRemainingPlannedLegsForEarlyShutdown() -> Bool {
        guard hasRemainingPlannedLegAfterCurrent else { return true }
        return mutate {
            guard var session = $0.operationalSession else { return }
            let currentUUID = ($0.activeDispatch?.operationalIdentity?.legUUID)
                .flatMap { CVROperationalIdentityLocal.normalizeUUID($0) }
            let currentIndex = session.currentLegIndex
            for index in session.plannedLegs.indices {
                let status = session.plannedLegs[index].status.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
                if status == "checked_in" || status == "cancelled" || status == "canceled" {
                    continue
                }
                let legUUID = CVROperationalIdentityLocal.normalizeUUID(session.plannedLegs[index].legUUID)
                    ?? session.plannedLegs[index].legUUID.lowercased()
                let isCurrent: Bool
                if let currentUUID {
                    isCurrent = legUUID == currentUUID
                } else if let currentIndex {
                    isCurrent = session.plannedLegs[index].sequenceNumber == currentIndex
                } else {
                    isCurrent = false
                }
                if isCurrent {
                    continue
                }
                session.plannedLegs[index].status = "cancelled"
            }
            session.engineSessionContinuityActive = false
            session.pendingSoftStartRecording = false
            session.continuityEngineStartSynthesized = false
            $0.operationalSession = session
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
            creationMethod: "two_second_hold",
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
        persistEngineStartOffBlock(
            gpsSample: gpsSample,
            timestamp: Date(),
            source: "manual_engine_start_hold",
            creationMethod: "two_second_hold",
            confidence: 1.0,
            extraMetadata: [:]
        )
    }

    /// Beacon avionics power edge — arms taxi-inferred Off Block after dwell.
    func noteAvionicsPowerState(isOn: Bool, at date: Date = Date()) {
        if isOn {
            if avionicsOnSince == nil {
                avionicsOnSince = date
            }
        } else {
            avionicsOnSince = nil
            taxiMotionAboveThresholdSince = nil
        }
    }

    /// Dummy-proof: if Engine Start was forgotten, infer Off Block once sustained taxi is detected.
    /// No user confirmation — UI Engine Start button disappears when continuity becomes active.
    @discardableResult
    func considerInferredEngineStartFromTaxi(gpsSample: GPSSample?) -> Bool {
        guard needsEngineStart,
              state.activeFlightRecord != nil,
              let sample = gpsSample else {
            taxiMotionAboveThresholdSince = nil
            return false
        }
        guard sample.horizontalAccuracy >= 0,
              sample.horizontalAccuracy <= 50,
              sample.speedMetersPerSecond >= 0 else {
            return false
        }

        let now = sample.timestamp
        let avionicsSince = avionicsOnSince ?? state.activeFlightRecord?.recordingStartedAt
        guard let avionicsSince,
              now.timeIntervalSince(avionicsSince) >= Self.inferredEngineStartAvionicsDwellSeconds else {
            taxiMotionAboveThresholdSince = nil
            return false
        }

        if sample.speedKnots > Self.inferredEngineStartTaxiSpeedKnots {
            if taxiMotionAboveThresholdSince == nil {
                taxiMotionAboveThresholdSince = now
            }
        } else {
            taxiMotionAboveThresholdSince = nil
            return false
        }

        guard let motionSince = taxiMotionAboveThresholdSince,
              now.timeIntervalSince(motionSince) >= Self.inferredEngineStartTaxiConfirmSeconds else {
            return false
        }

        let warmUp = now.addingTimeInterval(-Self.inferredEngineStartWarmUpSeconds)
        let earliest = now.addingTimeInterval(-Self.inferredEngineStartMaxLookbackSeconds)
        var offBlock = warmUp
        offBlock = max(offBlock, avionicsSince)
        offBlock = max(offBlock, earliest)
        offBlock = min(offBlock, now)

        let saved = persistEngineStartOffBlock(
            gpsSample: sample,
            timestamp: offBlock,
            source: "auto_motion_inferred",
            creationMethod: "taxi_motion_after_avionics",
            confidence: 0.85,
            extraMetadata: [
                "inferred": "true",
                "avionics_on_utc": Self.iso8601UTC.string(from: avionicsSince),
                "taxi_detected_utc": Self.iso8601UTC.string(from: now),
                "taxi_speed_kt": String(format: "%.1f", sample.speedKnots),
                "warm_up_seconds": String(Int(Self.inferredEngineStartWarmUpSeconds)),
            ]
        )
        if saved {
            taxiMotionAboveThresholdSince = nil
        }
        return saved
    }

    @discardableResult
    private func persistEngineStartOffBlock(
        gpsSample: GPSSample?,
        timestamp: Date,
        source: String,
        creationMethod: String,
        confidence: Double,
        extraMetadata: [String: String]
    ) -> Bool {
        guard var flightRecord = state.activeFlightRecord else {
            lastError = "Off Block could not be recorded. Open Dispatch first."
            return false
        }
        guard !state.flightEvents.contains(where: { $0.flightRecordID == flightRecord.id && $0.eventType == "engine_start_off_block" }) else {
            return true
        }

        var metadata: [String: String] = [
            "flight_record_uuid": flightRecord.id.lowercased(),
        ]
        for (key, value) in extraMetadata {
            metadata[key] = value
        }
        if let legUUID = state.activeDispatch?.operationalIdentity?.legUUID,
           let normalizedLeg = CVROperationalIdentityLocal.normalizeUUID(legUUID) {
            metadata["leg_uuid"] = normalizedLeg
        }
        let event = CVRFlightEventRecord(
            id: UUID().uuidString,
            flightRecordID: flightRecord.id,
            recordingSessionID: flightRecord.recordingSessionID,
            eventType: "engine_start_off_block",
            timestampUTC: timestamp,
            timestampLocal: timestamp,
            deviceMonotonicTime: ProcessInfo.processInfo.systemUptime,
            audioOffset: flightRecord.recordingStartedAt.map { max(0, timestamp.timeIntervalSince($0)) },
            latitude: gpsSample?.latitude,
            longitude: gpsSample?.longitude,
            altitude: gpsSample?.altitude,
            groundSpeed: gpsSample?.speedKnots,
            source: source,
            confidence: confidence,
            creationMethod: creationMethod,
            userIdentity: "local_cvr_unit",
            metadata: metadata
        )

        let persisted = mutate {
            flightRecord.status = .recording
            flightRecord.updatedAt = Date()
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

    /// Uses the immutable model version saved on this execution rather than the
    /// current rollout flag, so a policy rollback cannot change a session in flight.
    var usesOperationalSessionModelV1: Bool {
        state.activeOperationalSession?.modelVersion == CVROperationalSessionRecord.modelVersion
            || state.activeDispatch?.operationalSessionModelVersion == CVROperationalSessionRecord.modelVersion
    }

    /// Stage 2: durably secure authoritative session endpoint evidence while
    /// cockpit audio and GPS continue until actual Avionics OFF.
    @discardableResult
    func secureOperationalSessionEndingValues(
        endingHobbs: Double?,
        endingTacho: Double?,
        fuelRemaining: String?,
        gpsSample: GPSSample?
    ) -> Bool {
        guard usesOperationalSessionModelV1,
              var flightRecord = state.activeFlightRecord,
              let dispatch = state.activeDispatch,
              var operationalSession = state.activeOperationalSession else {
            lastError = "No active Operational Session is available."
            return false
        }
        guard state.flightEvents.contains(where: {
            $0.flightRecordID == flightRecord.id && $0.eventType == "engine_shutdown_on_block"
        }) else {
            lastError = "Engine Shutdown must be confirmed before flight data can be secured."
            return false
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
        guard let fuelQuantity = Self.decimalQuantity(from: fuel), fuelQuantity >= 0 else {
            lastError = "Fuel Remaining is required."
            return false
        }

        let now = Date()
        let event = makeFlightEvent(
            flightRecord: flightRecord,
            eventType: "ending_aircraft_state_secured",
            source: "crew_secured_ending_state",
            creationMethod: "secure_flight_data",
            gpsSample: gpsSample,
            metadata: [
                "ending_hobbs": String(format: "%.1f", endingHobbs),
                "ending_tacho": String(format: "%.1f", endingTacho),
                "ending_fuel": String(format: "%.1f", fuelQuantity),
                "fuel_unit": operationalSession.startingFuelUnit,
            ]
        )

        let persisted = mutate {
            flightRecord.endingHobbs = endingHobbs
            flightRecord.endingTacho = endingTacho
            flightRecord.fuelRemaining = String(format: "%.1f", fuelQuantity)
            flightRecord.checkInMode = .engineShutdown
            flightRecord.status = .awaitingAvionicsOff
            flightRecord.updatedAt = now
            $0.activeFlightRecord = flightRecord

            operationalSession.endingHobbs = endingHobbs
            operationalSession.endingTacho = endingTacho
            operationalSession.endingFuelQuantity = fuelQuantity
            operationalSession.endingFuelUnit = operationalSession.startingFuelUnit
            operationalSession.endingStateSecuredAtUTC = now
            operationalSession.state = .endingStateSecured
            $0.activeOperationalSession = operationalSession

            var continuity = $0.operationalSession ?? .empty
            continuity.pendingCheckInMode = .engineShutdown
            continuity.engineSessionContinuityActive = false
            continuity.awaitingAvionicsOffConfirmation = true
            continuity.carryoverHobbs = endingHobbs
            continuity.carryoverTacho = endingTacho
            continuity.carryoverFuel = String(format: "%.1f", fuelQuantity)
            $0.operationalSession = continuity

            $0.flightEvents.append(event)
            $0.uploadComponents.append(eventUploadComponent(event))
        }
        if persisted {
            lastError = ""
        }
        return persisted
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
        case .takeoff(let detectedAt, let detectedSample, let kind, let airportIdentifier):
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
            if let airportIdentifier, !airportIdentifier.isEmpty {
                metadata["airport_identifier"] = airportIdentifier
                metadata["airport_provenance"] = "ios_gps_faa_nasr"
            }
        case .landing(let detectedAt, let detectedSample, let kind, let airportIdentifier):
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
            if let airportIdentifier, !airportIdentifier.isEmpty {
                metadata["airport_identifier"] = airportIdentifier
                metadata["airport_provenance"] = "ios_gps_faa_nasr"
            }
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

    func recordGPSPositionEvidence(_ sample: GPSSample) {
        guard let flightRecord = state.activeFlightRecord,
              state.flightEvents.contains(where: {
                  $0.flightRecordID == flightRecord.id && $0.eventType == "engine_start_off_block"
              }) || state.engineSessionContinuityActive,
              !state.flightEvents.contains(where: {
                  $0.flightRecordID == flightRecord.id && $0.eventType == "engine_shutdown_on_block"
              }) else {
            return
        }
        let event = CVRFlightEventRecord(
            id: UUID().uuidString,
            flightRecordID: flightRecord.id,
            recordingSessionID: flightRecord.recordingSessionID,
            eventType: "gps_position_sample",
            timestampUTC: sample.timestamp,
            timestampLocal: sample.timestamp,
            deviceMonotonicTime: ProcessInfo.processInfo.systemUptime,
            audioOffset: flightRecord.recordingStartedAt.map {
                max(0, sample.timestamp.timeIntervalSince($0))
            },
            latitude: sample.latitude,
            longitude: sample.longitude,
            altitude: sample.altitude,
            groundSpeed: sample.speedKnots,
            source: "ios_core_location",
            confidence: sample.horizontalAccuracy <= 20 ? 0.95 : 0.80,
            creationMethod: "fifteen_second_evidence_sample",
            userIdentity: nil,
            metadata: [
                "horizontal_accuracy_m": String(format: "%.1f", sample.horizontalAccuracy),
                "vertical_accuracy_m": String(format: "%.1f", sample.verticalAccuracy),
                "course_deg": String(format: "%.1f", sample.course),
                "provenance": "ios_core_location"
            ]
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
            creationMethod: "two_second_hold",
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
            $0.activeOperationalSession = nil
            $0.consents = []
            $0.recorderVerifications = []
            $0.flightEvents = []
            $0.uploadComponents.removeAll { $0.componentType != "schedule_duty_sync" }
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
            $0.activeOperationalSession = nil
            $0.consents = []
            $0.recorderVerifications = []
            $0.flightEvents = []
            $0.flightLegs = []
            $0.uploadComponents.removeAll { $0.componentType != "schedule_duty_sync" }
            $0.discrepancies = []
            $0.selectedTab = .scheduled
        }
    }

    @discardableResult
    func beginPostFlightGarminCountdown(now: Date = Date()) -> Bool {
        guard let dispatch = state.activeDispatch,
              let flight = state.activeFlightRecord,
              flight.endingHobbs != nil,
              flight.endingTacho != nil,
              flight.checkInMode == .engineShutdown
                || state.operationalSession?.pendingCheckInMode == .engineShutdown else {
            return false
        }
        if state.postFlightGarminHandoff?.flightRecordUUID == flight.id {
            return true
        }
        return mutate {
            $0.postFlightGarminHandoff = CVRPostFlightGarminHandoff(
                flightRecordUUID: flight.id.lowercased(),
                dispatchUUID: dispatch.id.lowercased(),
                reservationUUID: dispatch.reservationUUID?.lowercased(),
                operationalSessionUUID: dispatch.operationalSessionUUID?.lowercased(),
                aircraftRegistration: dispatch.tailNumber,
                phase: .waitingForGarminData,
                beaconLossConfirmedAt: now,
                countdownEndsAt: now.addingTimeInterval(30),
                selectedCSVHash: nil,
                uploadReceiptID: nil,
                legReviewRevisionUUID: nil,
                cardReturnedConfirmedAt: nil
            )
            $0.selectedTab = .inFlight
        }
    }

    func cancelPostFlightGarminCountdownIfBeaconReturned(now: Date = Date()) {
        guard let handoff = state.postFlightGarminHandoff,
              handoff.phase == .waitingForGarminData,
              now < handoff.countdownEndsAt else {
            return
        }
        _ = mutate {
            if $0.postFlightGarminHandoff?.flightRecordUUID == handoff.flightRecordUUID {
                $0.postFlightGarminHandoff = nil
            }
        }
    }

    func advancePostFlightGarminHandoff(to phase: CVRPostFlightGarminPhase) {
        _ = mutate {
            guard var handoff = $0.postFlightGarminHandoff else { return }
            handoff.phase = phase
            $0.postFlightGarminHandoff = handoff
            if phase == .selectingCSV || phase == .uploadingCSV
                || phase == .uploadVerified || phase == .verifyingLegs
                || phase == .returnCardToGarmin {
                $0.selectedTab = .log
            }
        }
    }

    @discardableResult
    func reconcilePostFlightGarminUpload(flightRecordIDsWithCSV: Set<String>) -> Bool {
        guard let handoff = state.postFlightGarminHandoff,
              handoff.phase == .selectingCSV || handoff.phase == .uploadingCSV,
              flightRecordIDsWithCSV.contains(handoff.flightRecordUUID.lowercased()) else {
            return false
        }
        advancePostFlightGarminHandoff(to: .uploadVerified)
        return true
    }

    func completePostFlightGarminHandoff() {
        _ = mutate {
            guard var handoff = $0.postFlightGarminHandoff else { return }
            handoff.phase = .completed
            handoff.cardReturnedConfirmedAt = Date()
            $0.postFlightGarminHandoff = handoff
        }
    }

    func markPostFlightLegReviewAccepted(revisionUUID: String) {
        _ = mutate {
            guard var handoff = $0.postFlightGarminHandoff else { return }
            handoff.legReviewRevisionUUID = revisionUUID.lowercased()
            handoff.phase = .returnCardToGarmin
            $0.postFlightGarminHandoff = handoff
        }
    }

    func acceptOperationalLegReviewLocally(
        revisionUUID: String,
        dispatchUUID: String,
        flightRecordUUID: String,
        payload: Data,
        advancesPostFlightHandoff: Bool
    ) throws {
        guard payload.count <= Self.maximumRequestPayloadSnapshotBytes else {
            throw NSError(
                domain: "IPCACVRUnit.LegReview",
                code: 1,
                userInfo: [NSLocalizedDescriptionKey: "The verified leg revision is too large to store locally."]
            )
        }
        let revision = revisionUUID.lowercased()
        let component = CVRUploadComponentRecord(
            id: revision,
            serverID: nil,
            flightRecordID: flightRecordUUID.lowercased(),
            componentType: "operational_leg_review",
            localFilePath: "leg_review:\(dispatchUUID.lowercased())",
            sha256: nil,
            byteCount: Int64(payload.count),
            state: .queued,
            progress: 0,
            attemptCount: 0,
            lastError: "Legs verified locally · server synchronization pending",
            lastAttemptAt: nil,
            serverVerificationAt: nil,
            serverReceiptID: nil,
            requestPayloadSnapshot: payload
        )

        if state.activeFlightRecord?.id.lowercased() == flightRecordUUID.lowercased() {
            _ = mutate {
                $0.uploadComponents.removeAll {
                    $0.componentType == "operational_leg_review"
                        && $0.flightRecordID.lowercased() == flightRecordUUID.lowercased()
                }
                $0.uploadComponents.append(component)
                if advancesPostFlightHandoff, var handoff = $0.postFlightGarminHandoff {
                    handoff.legReviewRevisionUUID = revision
                    handoff.phase = .returnCardToGarmin
                    $0.postFlightGarminHandoff = handoff
                }
            }
            return
        }

        guard let archiveIndex = archives.firstIndex(where: {
            $0.flightRecordID.lowercased() == flightRecordUUID.lowercased()
        }) else {
            throw NSError(
                domain: "IPCACVRUnit.LegReview",
                code: 2,
                userInfo: [NSLocalizedDescriptionKey: "The local flight record for this leg review could not be found."]
            )
        }
        var updated = archives
        updated[archiveIndex].uploadComponents.removeAll {
            $0.componentType == "operational_leg_review"
        }
        updated[archiveIndex].uploadComponents.append(component)
        try saveArchives(updated)
        archives = updated
        if advancesPostFlightHandoff {
            markPostFlightLegReviewAccepted(revisionUUID: revision)
        }
    }

    var locallyAcceptedLegReviewDispatchUUIDs: Set<String> {
        let components = state.uploadComponents + archives.flatMap(\.uploadComponents)
        return Set(components.compactMap { component in
            guard component.componentType == "operational_leg_review",
                  let snapshot = component.requestPayloadSnapshot,
                  let payload = try? JSONSerialization.jsonObject(with: snapshot) as? [String: Any],
                  let dispatchUUID = payload["dispatch_uuid"] as? String else {
                return nil
            }
            return dispatchUUID.lowercased()
        })
    }

    /// Check-In is saved but Avionics OFF never arrived — archive anyway so History/Log are not orphaned.
    @discardableResult
    func forceFinalizeEngineShutdownAfterCheckIn() -> Bool {
        guard let flightRecord = state.activeFlightRecord,
              flightRecord.endingHobbs != nil,
              flightRecord.endingTacho != nil else {
            lastError = "Save Engine Shutdown Check-In before finalizing."
            return false
        }
        markAvionicsOffAfterShutdown()
        return completeEngineShutdownAfterAvionicsOff()
    }

    var canForceFinalizeEngineShutdown: Bool {
        guard let flight = state.activeFlightRecord else { return false }
        guard flight.endingHobbs != nil, flight.endingTacho != nil else { return false }
        return flight.status == .awaitingAvionicsOff
            || state.operationalSession?.awaitingAvionicsOffConfirmation == true
            || flight.checkInMode == .engineShutdown
    }

    /// Soft-hide a Log row. Does not delete server Master Logbook data.
    @discardableResult
    func voidFlightLog(flightRecordID: String) -> Bool {
        let key = flightRecordID.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        guard !key.isEmpty else { return false }
        voidedFlightRecordIDs.insert(key)
        if let index = archives.firstIndex(where: { $0.flightRecordID.lowercased() == key }) {
            var updated = archives
            updated[index].voidedAt = Date()
            do {
                try saveArchives(updated)
                archives = updated
            } catch {
                lastError = "Could not save voided Log state: \(error.localizedDescription)"
                return false
            }
        }
        persistVoidedFlightRecordIDs()
        return true
    }

    func isFlightLogVoided(_ flightRecordID: String) -> Bool {
        voidedFlightRecordIDs.contains(flightRecordID.trimmingCharacters(in: .whitespacesAndNewlines).lowercased())
    }

    /// Drop leftover ROUTE session when every planned leg is already checked in / cancelled and nothing is active.
    func clearIdleCompletedOperationalSessionIfNeeded() {
        guard state.activeDispatch == nil, state.activeFlightRecord == nil,
              let session = state.operationalSession,
              !Self.hasOpenPlannedLegs(in: session) else { return }
        _ = mutate {
            $0.operationalSession = nil
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

    var latestContinuableReservation: CVRReservationContinuationCandidate? {
        guard state.activeDispatch == nil, state.activeFlightRecord == nil,
              remainingOpenPlannedLegs.isEmpty else { return nil }
        guard let latest = archives.max(by: { $0.archivedAt < $1.archivedAt }),
              latest.flightRecord.checkInMode == .engineShutdown,
              latest.flightRecord.endingHobbs != nil,
              latest.flightRecord.endingTacho != nil,
              !(latest.flightRecord.fuelRemaining ?? "").isEmpty,
              let reservationUUID = continuationReservationUUID(for: latest) else {
            return nil
        }
        let completedLegCount = archives.filter {
            continuationReservationUUID(for: $0)?.lowercased() == reservationUUID.lowercased()
        }.count
        let departure = CVROperationalIdentityLocal.normalizeAirport(
            latest.flightRecord.verifiedDestinationAirport
                ?? latest.dispatch.plannedDestinationAirport
        )
        return CVRReservationContinuationCandidate(
            archiveID: latest.id,
            reservationUUID: reservationUUID,
            schedulerRecordID: latest.dispatch.schedulerRecordID,
            aircraftRegistration: latest.dispatch.tailNumber,
            departureAirport: departure,
            missionCode: latest.dispatch.missionCode,
            completedLegCount: max(1, completedLegCount)
        )
    }

    @discardableResult
    func continueReservation(
        _ candidate: CVRReservationContinuationCandidate,
        departureAirport: String,
        destinationAirport: String,
        selectedAircraft: CockpitAircraft?,
        cvrUnitID: String,
        beaconID: String,
        isAudioRecording: Bool,
        canonicalWriteEnabled: Bool,
        operationalSessionModelEnabled: Bool
    ) -> Bool {
        guard state.activeDispatch == nil, state.activeFlightRecord == nil, !isAudioRecording else {
            lastError = "Finish the active recording and flight before continuing this reservation."
            return false
        }
        guard let selectedAircraft,
              Self.normalizedTail(selectedAircraft.registration)
                == Self.normalizedTail(candidate.aircraftRegistration) else {
            lastError = "This reservation belongs to a different aircraft."
            return false
        }
        guard let archive = archives.first(where: { $0.id == candidate.archiveID }),
              let reservationUUID = continuationReservationUUID(for: archive),
              reservationUUID.lowercased() == candidate.reservationUUID.lowercased() else {
            lastError = "The completed reservation is no longer available. Refresh the Log and try again."
            return false
        }
        let departure = CVROperationalIdentityLocal.normalizeAirport(departureAirport)
        let destination = CVROperationalIdentityLocal.normalizeAirport(destinationAirport)
        guard !departure.isEmpty, !destination.isEmpty else {
            lastError = "Enter both departure and destination airports."
            return false
        }
        let now = Date()
        let planned = CVRPlannedLegRecord(
            id: UUID().uuidString.lowercased(),
            reservationUUID: reservationUUID.lowercased(),
            legUUID: UUID().uuidString.lowercased(),
            sequenceNumber: candidate.completedLegCount + 1,
            departureAirport: departure,
            destinationAirport: destination,
            missionCode: candidate.missionCode,
            tailNumber: selectedAircraft.registration,
            schedulerRecordID: candidate.schedulerRecordID,
            plannedStartAt: now,
            plannedEndAt: now.addingTimeInterval(2 * 3600),
            status: "planned"
        )
        guard mutate({
            var session = CVROperationalSessionContext.empty
            session.reservationUUID = reservationUUID.lowercased()
            session.plannedLegs = [planned]
            session.carryoverHobbs = archive.flightRecord.endingHobbs
            session.carryoverTacho = archive.flightRecord.endingTacho
            session.carryoverFuel = archive.flightRecord.fuelRemaining
            session.carryoverOilPercentage = archive.flightRecord.endingOilPercentage
            session.carryoverOilQuantity = archive.flightRecord.endingOilQuantity
            session.carryoverOilUnit = archive.flightRecord.endingOilUnit
            session.carryoverCrew = archive.dispatch.crew
            $0.operationalSession = session
        }) else {
            lastError = "The continued reservation could not be saved on this CVR Unit."
            return false
        }
        openDispatchFromPlannedLeg(
            planned,
            selectedAircraft: selectedAircraft,
            cvrUnitID: cvrUnitID,
            beaconID: beaconID,
            isAudioRecording: false,
            canonicalWriteEnabled: canonicalWriteEnabled,
            operationalSessionModelEnabled: operationalSessionModelEnabled
        )
        return state.activeDispatch != nil
    }

    private func continuationReservationUUID(for archive: CVRWorkflowArchiveRecord) -> String? {
        let value = archive.dispatch.reservationUUID
            ?? archive.dispatch.operationalIdentity?.reservationUUID
            ?? archive.operationalSession?.reservationUUID
        guard let normalized = value.flatMap(CVROperationalIdentityLocal.normalizeUUID) else {
            return nil
        }
        return normalized
    }

    var hasQueuedScheduleDutyReplacement: Bool {
        state.uploadComponents.contains { $0.componentType == "schedule_duty_sync" }
    }

    var scheduleDutySyncInfo: CVRScheduleDutySyncInfo? {
        var relevantSchedulers = Set(remainingOpenPlannedLegs.compactMap {
            $0.schedulerRecordID?.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        })
        if let active = state.activeDispatch?.schedulerRecordID?
            .trimmingCharacters(in: .whitespacesAndNewlines)
            .lowercased(), !active.isEmpty {
            relevantSchedulers.insert(active)
        }
        guard !relevantSchedulers.isEmpty else { return nil }
        guard let component = state.uploadComponents.last(where: { component in
            guard component.componentType == "schedule_duty_sync",
                  let data = component.requestPayloadSnapshot,
                  let payload = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
                  let value = payload["scheduler_record_id"] as? String else {
                return false
            }
            return relevantSchedulers.contains(
                value.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
            )
        }) else {
            return nil
        }
        switch component.state {
        case .serverVerified, .uploaded:
            let warning = component.lastError.trimmingCharacters(in: .whitespacesAndNewlines)
            return CVRScheduleDutySyncInfo(
                phase: warning.isEmpty ? .synced : .syncedWithWarning,
                message: warning.isEmpty
                    ? "This reservation and its identity are confirmed on the online schedule."
                    : "Reservation synced with overlap warning: \(warning)"
            )
        case .uploading:
            return CVRScheduleDutySyncInfo(
                phase: .syncing,
                message: "Sending this reservation to the online schedule now."
            )
        case .needsUserAction:
            let message = component.lastError.trimmingCharacters(in: .whitespacesAndNewlines)
            return CVRScheduleDutySyncInfo(
                phase: .attention,
                message: message.isEmpty
                    ? "The reservation is saved locally but server synchronization needs attention."
                    : message
            )
        case .failed:
            if component.retryable != false {
                return CVRScheduleDutySyncInfo(
                    phase: .queued,
                    message: "Saved safely on this CVR Unit. It will retry automatically when internet connectivity returns."
                )
            }
            let message = component.lastError.trimmingCharacters(in: .whitespacesAndNewlines)
            return CVRScheduleDutySyncInfo(
                phase: .attention,
                message: message.isEmpty
                    ? "The reservation is saved locally but server synchronization needs attention."
                    : message
            )
        case .queued, .notReady:
            return CVRScheduleDutySyncInfo(
                phase: .queued,
                message: "Saved safely on this CVR Unit and queued. It will sync automatically when internet connectivity is available."
            )
        case .superseded:
            return nil
        }
    }

    var locallySupersededSchedulerRecordIDs: Set<String> {
        Set(state.uploadComponents.compactMap { component in
            guard component.componentType == "schedule_duty_sync",
                  let data = component.requestPayloadSnapshot,
                  let payload = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
                  let value = payload["supersedes_scheduler_record_id"] as? String else {
                return nil
            }
            return value.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        })
    }

    func scheduleDutyReplacementIsPending(schedulerRecordID: String?) -> Bool {
        let expected = schedulerRecordID?
            .trimmingCharacters(in: .whitespacesAndNewlines)
            .lowercased() ?? ""
        guard !expected.isEmpty else { return false }
        return state.uploadComponents.contains { component in
            guard component.componentType == "schedule_duty_sync",
                  component.state != .serverVerified,
                  component.state != .superseded,
                  let data = component.requestPayloadSnapshot,
                  let payload = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
                  let value = payload["scheduler_record_id"] as? String else {
                return false
            }
            return value.trimmingCharacters(in: .whitespacesAndNewlines).lowercased() == expected
        }
    }

    /// Remove a non-dispatched local replacement after a successful server refresh proves
    /// that its reservation no longer exists (for example, it was cancelled online).
    @discardableResult
    func discardRejectedScheduledDraftMissingFromServer(
        serverSessions: [CVRScheduledSession]
    ) -> Bool {
        guard let dispatch = state.activeDispatch,
              state.activeFlightRecord == nil,
              dispatch.status != .dispatchVerified,
              dispatch.status != .flightRecordLoggingEnabled else {
            return false
        }
        let dispatchScheduler = dispatch.schedulerRecordID?
            .trimmingCharacters(in: .whitespacesAndNewlines)
            .lowercased() ?? ""
        guard !dispatchScheduler.isEmpty else { return false }

        let rejectedReplacement = state.uploadComponents.contains { component in
            guard component.componentType == "schedule_duty_sync",
                  component.flightRecordID == dispatch.id,
                  component.userActionRequired == true,
                  let data = component.requestPayloadSnapshot,
                  let payload = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
                  let superseded = payload["supersedes_scheduler_record_id"] as? String else {
                return false
            }
            return !superseded.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
        }
        guard rejectedReplacement else { return false }

        let serverSchedulerIDs = Set(serverSessions.map {
            $0.schedulerRecordID.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        })
        guard !serverSchedulerIDs.contains(dispatchScheduler) else { return false }

        let dispatchID = dispatch.id
        let persisted = mutate {
            $0.activeDispatch = nil
            $0.activeFlightRecord = nil
            $0.activeOperationalSession = nil
            $0.consents = []
            $0.recorderVerifications = []
            $0.flightEvents = []
            $0.flightLegs = []
            $0.uploadComponents.removeAll {
                $0.componentType == "schedule_duty_sync" && $0.flightRecordID == dispatchID
            }
            $0.discrepancies = []
            if !$0.engineSessionContinuityActive {
                $0.operationalSession = nil
            }
            $0.selectedTab = .scheduled
        }
        if persisted {
            lastError = ""
        }
        return persisted
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
            let now = Date()
            if var flight = $0.activeFlightRecord, flight.status == .awaitingAvionicsOff {
                flight.status = .awaitingUpload
                flight.updatedAt = now
                $0.activeFlightRecord = flight
                if $0.activeOperationalSession?.modelVersion == CVROperationalSessionRecord.modelVersion,
                   flight.endingHobbs != nil,
                   flight.endingTacho != nil,
                   !$0.uploadComponents.contains(where: {
                       $0.flightRecordID == flight.id && $0.componentType == "flight_record_closure"
                   }) {
                    // The closure is intentionally queued only after Avionics OFF.
                    // Securing meters must not close the server Operational Session.
                    $0.uploadComponents.append(evidenceComponent(
                        flightRecordID: flight.id,
                        type: "flight_record_closure",
                        evidenceID: flight.id
                    ))
                }
            }
            if var operationalSession = $0.activeOperationalSession,
               operationalSession.modelVersion == CVROperationalSessionRecord.modelVersion,
               operationalSession.state == .endingStateSecured {
                operationalSession.state = .evidenceClosed
                operationalSession.avionicsOffAtUTC = now
                $0.activeOperationalSession = operationalSession
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
        guard isDispatchLocked,
              let dispatch = state.activeDispatch,
              let flightRecord = state.activeFlightRecord else {
            lastError = "There is no active Dispatch to release."
            return false
        }

        let containsOperationalEvidence = !canUndispatchActiveFlight
        let needsServerRelease = dispatch.serverDispatchID != nil
            || dispatchUploadVerified()
            || !(dispatch.schedulerRecordID ?? "").trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
        if containsOperationalEvidence && !needsServerRelease {
            lastError = "A local evidence-bearing flight cannot be discarded without an administrative server release."
            return false
        }

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
                if containsOperationalEvidence && response.alreadyReleased != true {
                    lastError = "The server must administratively release this evidence-bearing Dispatch before it can be cleared locally."
                    return false
                }
            } catch {
                lastError = error.localizedDescription
                return false
            }
        }

        if containsOperationalEvidence && !archiveAdministrativelyReleasedWorkflow() {
            return false
        }

        let flightID = flightRecord.id
        let dispatchID = dispatch.id
        let replacementDispatchID = UUID().uuidString.lowercased()
        let returnsToSchedule = needsServerRelease
            || !(dispatch.schedulerRecordID ?? "").trimmingCharacters(in: .whitespacesAndNewlines).isEmpty

        _ = mutate {
            $0.uploadComponents.removeAll { $0.flightRecordID == flightID }
            $0.flightEvents.removeAll { $0.flightRecordID == flightID }
            $0.recorderVerifications.removeAll { $0.flightRecordID == flightID }
            $0.consents.removeAll { $0.dispatchID == dispatchID }
            $0.discrepancies.removeAll { $0.flightRecordID == flightID }
            $0.activeFlightRecord = nil
            $0.activeOperationalSession = nil
            if var draft = $0.activeDispatch {
                // Undispatch revokes the acknowledged execution, not the prepared
                // aircraft state. Preserve explicit crew decisions and fuel/oil
                // acknowledgements, but never reuse the released Dispatch UUID.
                draft.id = replacementDispatchID
                draft.status = Self.dispatchStatus(for: draft, consents: [])
                draft.consentStatus = ""
                draft.createdAt = Date()
                draft.modifiedAt = Date()
                draft.version = 1
                draft.serverDispatchID = nil
                draft.operationalSessionUUID = nil
                $0.activeDispatch = draft
                for index in $0.uploadComponents.indices
                    where $0.uploadComponents[index].componentType == "schedule_duty_sync"
                        && $0.uploadComponents[index].flightRecordID == dispatchID {
                    $0.uploadComponents[index].flightRecordID = replacementDispatchID
                }
                if var session = $0.operationalSession {
                    Self.unmarkDispatchedPlannedLeg(in: &session, dispatchID: dispatchID, flightRecordID: flightID)
                    $0.operationalSession = session
                }
            }
            $0.selectedTab = returnsToSchedule ? .scheduled : .dispatch
        }
        lastError = ""
        return true
    }

    /// Preserve an evidence-bearing workflow after an administrator has already released
    /// its server Dispatch. This is a terminal local archive, never a deletion or retry source.
    private func archiveAdministrativelyReleasedWorkflow() -> Bool {
        guard let dispatch = state.activeDispatch,
              let flightRecord = state.activeFlightRecord else {
            lastError = "Cannot archive the administratively released workflow."
            return false
        }
        if archives.contains(where: { $0.flightRecordID == flightRecord.id }) {
            return true
        }

        var components = state.uploadComponents.filter { $0.flightRecordID == flightRecord.id }
        for index in components.indices
            where components[index].state != .serverVerified
                && components[index].state != .uploaded {
            components[index].state = .superseded
            components[index].lastError = "Administrative server release confirmed; retained as cancelled evidence."
        }
        var cancelledSession = state.activeOperationalSession
        if cancelledSession?.modelVersion == CVROperationalSessionRecord.modelVersion {
            cancelledSession?.state = .cancelled
        }
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
            status: .serverVerified,
            operationalSession: cancelledSession
        )
        do {
            var updated = archives
            updated.append(archive)
            try saveArchives(updated)
            archives = updated
            return true
        } catch {
            lastError = "Released flight evidence could not be archived locally: \(error.localizedDescription)"
            return false
        }
    }

    private func clearActiveLegStatePreservingSession(selectScheduled: Bool) {
        mutate {
            $0.activeDispatch = nil
            $0.activeFlightRecord = nil
            $0.consents = []
            $0.recorderVerifications = []
            $0.flightEvents = []
            $0.uploadComponents.removeAll { $0.componentType != "schedule_duty_sync" }
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
                role: member.role,
                pilotFunction: member.pilotFunction,
                isPIC: member.isPIC
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
            let persisted = mutate {
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
            if persisted && state == .serverVerified,
               self.state.uploadComponents.contains(where: {
                   $0.id == id && $0.componentType == "schedule_duty_sync"
               }) {
                scheduleRefreshRevision &+= 1
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
                  component.componentType != "schedule_duty_sync",
                  component.componentType != "operational_leg_review",
                  component.state == .queued || component.state == .serverVerified else {
                return false
            }
            // Explicit Log SYNC must not force reconciliation for never-attempted
            // components — that blocked Dispatch POST and made SYNC look like a no-op.
            if component.reconciliationRequired == true {
                return true
            }
            if component.state == .serverVerified {
                return !Self.hasCompleteVerificationMetadata(component)
            }
            if component.reconciliationRequired == false {
                return false
            }
            // Fresh queued components POST normally; only prior uncertain attempts reconcile first.
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
        let persisted = updateComponentAtomically(id: componentID) { component in
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
        if persisted {
            hydrateFlightClosureMetersIfNeeded(
                componentID: componentID,
                canonicalIdentifiers: canonicalIdentifiers
            )
        }
        return persisted
    }

    /// When a restored/admin closure is flight-scoped reconciled, copy meters onto the local Flight Record
    /// so Log/Check-In no longer treat the leg as missing Ending Hobbs/Tacho.
    private func hydrateFlightClosureMetersIfNeeded(
        componentID: String,
        canonicalIdentifiers: [String: String]
    ) {
        guard let endingHobbs = Double(canonicalIdentifiers["ending_hobbs"] ?? ""),
              let endingTacho = Double(canonicalIdentifiers["ending_tacho"] ?? "") else {
            return
        }
        let fuel = canonicalIdentifiers["fuel_remaining"]?
            .trimmingCharacters(in: .whitespacesAndNewlines)
        let destination = canonicalIdentifiers["verified_destination_airport"]?
            .trimmingCharacters(in: .whitespacesAndNewlines)

        if let component = state.uploadComponents.first(where: { $0.id == componentID }),
           component.componentType == "flight_record_closure",
           var flight = state.activeFlightRecord,
           flight.id == component.flightRecordID {
            var changed = false
            if flight.endingHobbs == nil {
                flight.endingHobbs = endingHobbs
                changed = true
            }
            if flight.endingTacho == nil {
                flight.endingTacho = endingTacho
                changed = true
            }
            if (flight.fuelRemaining ?? "").isEmpty, let fuel, !fuel.isEmpty {
                flight.fuelRemaining = fuel
                changed = true
            }
            if (flight.verifiedDestinationAirport ?? "").isEmpty, let destination, !destination.isEmpty {
                flight.verifiedDestinationAirport = destination
                changed = true
            }
            if changed {
                _ = mutate {
                    $0.activeFlightRecord = flight
                }
            }
            return
        }

        guard let archiveIndex = archives.firstIndex(where: {
            $0.uploadComponents.contains(where: { $0.id == componentID && $0.componentType == "flight_record_closure" })
        }) else {
            return
        }
        var updated = archives
        var flight = updated[archiveIndex].flightRecord
        var changed = false
        if flight.endingHobbs == nil {
            flight.endingHobbs = endingHobbs
            changed = true
        }
        if flight.endingTacho == nil {
            flight.endingTacho = endingTacho
            changed = true
        }
        if (flight.fuelRemaining ?? "").isEmpty, let fuel, !fuel.isEmpty {
            flight.fuelRemaining = fuel
            changed = true
        }
        if (flight.verifiedDestinationAirport ?? "").isEmpty, let destination, !destination.isEmpty {
            flight.verifiedDestinationAirport = destination
            changed = true
        }
        guard changed else { return }
        updated[archiveIndex].flightRecord = flight
        do {
            try saveArchives(updated)
            archives = updated
        } catch {
            lastError = "Could not hydrate Flight Closure meters from server reconciliation: \(error.localizedDescription)"
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
        if avionicsOnSince == nil {
            avionicsOnSince = startedAt
        }
        mutate {
            guard var flightRecord = $0.activeFlightRecord else { return }
            flightRecord.recordingSessionID = recordingID
            flightRecord.recordingStartedAt = startedAt
            flightRecord.updatedAt = Date()
            $0.activeFlightRecord = flightRecord
            if var operationalSession = $0.activeOperationalSession,
               operationalSession.modelVersion == CVROperationalSessionRecord.modelVersion,
               operationalSession.state == .intended {
                operationalSession.state = .evidenceCapturing
                $0.activeOperationalSession = operationalSession
            }
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
                role: Self.crewRole(from: member.role),
                pilotFunction: Self.pilotFunction(from: member.pilotFunction),
                isPIC: member.isPIC
            )
        }
        guard !scheduledCrew.isEmpty else { return false }
        let currentSignature = dispatch.crew
            .map { "\($0.personID ?? 0):\($0.personName.lowercased()):\($0.role.rawValue):\($0.effectivePilotFunction.rawValue):\($0.hasPICResponsibility)" }
            .sorted()
            .joined(separator: "|")
        let scheduledSignature = scheduledCrew
            .map { "\($0.personID ?? 0):\($0.personName.lowercased()):\($0.role.rawValue):\($0.effectivePilotFunction.rawValue):\($0.hasPICResponsibility)" }
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
                role: Self.crewRole(from: member.role),
                pilotFunction: Self.pilotFunction(from: member.pilotFunction),
                isPIC: member.isPIC
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

    /// User-initiated Log SYNC: recover stuck queued/uploading Dispatch uploads that
    /// `requeueFailedUploads` ignores (it only resets failed / needsUserAction).
    @discardableResult
    func forceRetryPendingUploads(forFlightRecordID flightRecordID: String) -> Bool {
        var changed = false

        mutate {
            guard $0.activeFlightRecord?.id == flightRecordID else { return }
            _ = Self.repairStaleDispatchConsents(in: &$0)
            if Self.ensureDispatchUploadComponent(in: &$0) {
                changed = true
            }
            for index in $0.uploadComponents.indices {
                guard Self.shouldForceRetryWorkflowComponent($0.uploadComponents[index]) else { continue }
                Self.resetWorkflowComponentForForceRetry(&$0.uploadComponents[index])
                changed = true
            }
        }

        guard let archiveIndex = archives.firstIndex(where: { $0.flightRecordID == flightRecordID }) else {
            return changed
        }
        var updated = archives
        changed = Self.repairArchivedDispatchConsents(in: &updated[archiveIndex]) || changed
        if Self.ensureArchivedDispatchUploadComponent(in: &updated[archiveIndex]) {
            changed = true
        }
        for componentIndex in updated[archiveIndex].uploadComponents.indices {
            guard Self.shouldForceRetryWorkflowComponent(updated[archiveIndex].uploadComponents[componentIndex]) else {
                continue
            }
            Self.resetWorkflowComponentForForceRetry(&updated[archiveIndex].uploadComponents[componentIndex])
            changed = true
        }
        guard changed else { return false }
        updated[archiveIndex].status = .uploadPending
        do {
            try saveArchives(updated)
            archives = updated
            lastError = ""
            return true
        } catch {
            lastError = "Could not force-retry archived flight uploads: \(error.localizedDescription)"
            return false
        }
    }

    private static func shouldForceRetryWorkflowComponent(_ component: CVRUploadComponentRecord) -> Bool {
        switch component.state {
        case .serverVerified, .superseded, .uploaded, .notReady:
            return false
        case .failed, .needsUserAction, .uploading, .queued:
            return true
        }
    }

    private static func resetWorkflowComponentForForceRetry(_ component: inout CVRUploadComponentRecord) {
        component.state = .queued
        component.progress = 0
        component.lastError = ""
        // User-initiated SYNC must take the normal POST path. Stale reconciliation
        // flags were leaving Dispatch at 0% with no visible error.
        component.reconciliationRequired = false
        if component.componentType != "schedule_duty_sync"
            && component.componentType != "operational_leg_review" {
            component.requestPayloadSnapshot = nil
        }
        component.userActionRequired = false
        component.retryable = true
        if component.componentType == "dispatch_metadata" {
            component.attemptCount = 0
        }
    }

    private static func ensureDispatchUploadComponent(in state: inout CVRWorkflowState) -> Bool {
        guard let dispatch = state.activeDispatch,
              let flightRecord = state.activeFlightRecord,
              flightRecord.dispatchID == dispatch.id else {
            return false
        }
        guard !state.uploadComponents.contains(where: {
            $0.componentType == "dispatch_metadata" && $0.flightRecordID == flightRecord.id
        }) else {
            return false
        }
        state.uploadComponents.append(CVRUploadComponentRecord(
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
        ))
        state.updatedAt = Date()
        return true
    }

    private static func ensureArchivedDispatchUploadComponent(in archive: inout CVRWorkflowArchiveRecord) -> Bool {
        guard !archive.uploadComponents.contains(where: { $0.componentType == "dispatch_metadata" }) else {
            return false
        }
        archive.uploadComponents.insert(
            CVRUploadComponentRecord(
                id: "dispatch-\(archive.dispatch.id)-v\(archive.dispatch.version)",
                serverID: nil,
                flightRecordID: archive.flightRecordID,
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
            ),
            at: 0
        )
        return true
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
            $0.uploadComponents.removeAll { $0.componentType != "schedule_duty_sync" }
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
            clearIdleCompletedOperationalSessionIfNeeded()
            clearAvionicsSimulation()
            return true
        }
        // Never discard a saved Check-In — archive first (same as Avionics OFF).
        if state.activeFlightRecord?.endingHobbs != nil,
           state.activeFlightRecord?.endingTacho != nil {
            completeSimulationFlight()
            markAvionicsOffAfterShutdown()
            guard completeEngineShutdownAfterAvionicsOff() else { return false }
            clearAvionicsSimulation()
            return state.activeFlightRecord == nil
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
        // No Check-In yet — keep blocking wipe; require Check-In then finalize.
        lastError = "Save Check-In before finishing the simulation demo so the flight is archived."
        return false
    }

    func resetSimulationWorkflow(clearAvionicsSimulation: () -> Void) {
        // Prefer archive over discard when Check-In meters already exist.
        if let flight = state.activeFlightRecord,
           flight.endingHobbs != nil,
           flight.endingTacho != nil {
            completeSimulationFlight()
            markAvionicsOffAfterShutdown()
            if !completeEngineShutdownAfterAvionicsOff() {
                lastError = lastError.isEmpty
                    ? "Could not archive the checked-in flight before Reset. Fix the archive error first."
                    : lastError
                return
            }
            clearIdleCompletedOperationalSessionIfNeeded()
            clearAvionicsSimulation()
            return
        }
        if state.activeFlightRecord != nil || state.activeDispatch != nil {
            lastError = "Finish or Undispatch the active leg before Reset. Checked-in flights must be archived, not discarded."
            return
        }
        clearIdleCompletedOperationalSessionIfNeeded()
        mutate {
            $0.consents = []
            $0.recorderVerifications = []
            $0.flightEvents = []
            $0.flightLegs = []
            $0.uploadComponents.removeAll { $0.componentType != "schedule_duty_sync" }
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
        if session.engineSessionContinuityActive {
            return true
        }
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
        if component.componentType == "schedule_duty_sync", state == .serverVerified {
            component.reconciliationRequired = false
        }
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
        var completedSession = state.activeOperationalSession
        if completedSession?.modelVersion == CVROperationalSessionRecord.modelVersion {
            completedSession?.state = .finalized
        }
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
                : .uploadPending,
            operationalSession: completedSession
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
            if component.componentType == "schedule_duty_sync" {
                if component.errorCode == "IMMUTABLE_CONFLICT"
                    && component.lastError.localizedCaseInsensitiveContains(
                        "Unsupported reconciliation component type"
                    ) {
                    let wasAccepted = component.serverReceiptID?.isEmpty == false
                    state.uploadComponents[index].state = wasAccepted ? .serverVerified : .queued
                    state.uploadComponents[index].progress = wasAccepted ? 1 : 0
                    state.uploadComponents[index].lastError = ""
                    state.uploadComponents[index].errorCode = nil
                    state.uploadComponents[index].retryable = wasAccepted ? false : true
                    state.uploadComponents[index].userActionRequired = false
                    state.uploadComponents[index].reconciliationRequired = false
                    changed = true
                }
                continue
            }
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

    private func voidedFlightRecordIDsURL() throws -> URL {
        let base = try FileManager.default.url(
            for: .applicationSupportDirectory,
            in: .userDomainMask,
            appropriateFor: nil,
            create: true
        )
        let dir = base.appendingPathComponent("IPCACVRUnit", isDirectory: true)
        try FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        return dir.appendingPathComponent("voided-flight-log-ids.json")
    }

    private func loadVoidedFlightRecordIDs() {
        do {
            let url = try voidedFlightRecordIDsURL()
            guard FileManager.default.fileExists(atPath: url.path) else { return }
            let ids = try decoder.decode([String].self, from: Data(contentsOf: url))
            voidedFlightRecordIDs = Set(ids.map { $0.lowercased() })
        } catch {
            // Non-fatal — void list starts empty.
        }
    }

    private func persistVoidedFlightRecordIDs() {
        do {
            let url = try voidedFlightRecordIDsURL()
            let data = try encoder.encode(Array(voidedFlightRecordIDs).sorted())
            try data.write(to: url, options: [.atomic])
        } catch {
            lastError = "Could not persist voided Log IDs: \(error.localizedDescription)"
        }
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
            // Older builds marked a leg Active merely by opening its Dispatch
            // editor. No operational transition exists until a Flight Record is
            // created by DISPATCH FLIGHT, so restore those drafts to Scheduled.
            if $0.activeDispatch != nil && $0.activeFlightRecord == nil {
                for index in session.plannedLegs.indices {
                    let status = session.plannedLegs[index].status
                        .trimmingCharacters(in: .whitespacesAndNewlines)
                        .lowercased()
                    if status == "active" || status == "dispatched" {
                        session.plannedLegs[index].status = "planned"
                    }
                }
            }
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

    private static func seedPlannedLegsFromScheduledReservation(
        into session: inout CVROperationalSessionContext,
        openingSession: CVRScheduledSession,
        reservationSessions: [CVRScheduledSession],
        registration: String
    ) {
        let reservationKey = (openingSession.reservationUUID ?? "")
            .trimmingCharacters(in: .whitespacesAndNewlines)
            .lowercased()

        var siblings: [CVRScheduledSession]
        if !reservationKey.isEmpty {
            siblings = reservationSessions.filter {
                ($0.reservationUUID ?? "")
                    .trimmingCharacters(in: .whitespacesAndNewlines)
                    .lowercased() == reservationKey
            }
            if siblings.isEmpty {
                siblings = [openingSession]
            }
        } else if !reservationSessions.isEmpty {
            siblings = reservationSessions
        } else {
            siblings = [openingSession]
        }

        siblings.sort(by: CVRScheduledReservationGrouping.compareScheduledSessions)
        if !reservationKey.isEmpty {
            session.reservationUUID = reservationKey
        }

        let seeded: [CVRPlannedLegRecord] = siblings.enumerated().map { index, sibling in
            let legUUID = sibling.legUUID.flatMap { CVROperationalIdentityLocal.normalizeUUID($0) }
                ?? UUID().uuidString.lowercased()
            return CVRPlannedLegRecord(
                id: legUUID,
                reservationUUID: session.reservationUUID ?? reservationKey,
                legUUID: legUUID,
                sequenceNumber: sibling.legSequenceNumber ?? (index + 1),
                departureAirport: CVROperationalIdentityLocal.normalizeAirport(sibling.plannedDepartureAirport),
                destinationAirport: CVROperationalIdentityLocal.normalizeAirport(sibling.plannedDestinationAirport),
                missionCode: sibling.missionCode,
                tailNumber: registration.isEmpty ? sibling.aircraftRegistration : registration,
                schedulerRecordID: sibling.schedulerRecordID,
                plannedStartAt: sibling.dateTime(sibling.scheduledStartTime),
                plannedEndAt: sibling.dateTime(sibling.scheduledEndTime),
                status: "planned"
            )
        }
        // Contiguous 1-based sequence after stable sort.
        let normalizedSeeded = seeded.enumerated().map { index, leg -> CVRPlannedLegRecord in
            var copy = leg
            copy.sequenceNumber = index + 1
            return copy
        }

        guard !normalizedSeeded.isEmpty else { return }

        if session.plannedLegs.isEmpty {
            session.plannedLegs = normalizedSeeded
            return
        }

        let existingReservation = (session.reservationUUID ?? "").lowercased()
        if !reservationKey.isEmpty, existingReservation == reservationKey || existingReservation.isEmpty {
            session.reservationUUID = reservationKey.isEmpty ? session.reservationUUID : reservationKey
            var byUUID: [String: CVRPlannedLegRecord] = [:]
            for leg in session.plannedLegs {
                let key = CVROperationalIdentityLocal.normalizeUUID(leg.legUUID) ?? leg.legUUID.lowercased()
                byUUID[key] = leg
            }
            session.plannedLegs = normalizedSeeded.map { seededLeg in
                let key = CVROperationalIdentityLocal.normalizeUUID(seededLeg.legUUID)
                    ?? seededLeg.legUUID.lowercased()
                if var existing = byUUID[key] {
                    // Keep checked_in / cancelled progress; refresh route metadata from schedule.
                    existing.sequenceNumber = seededLeg.sequenceNumber
                    existing.departureAirport = seededLeg.departureAirport
                    existing.destinationAirport = seededLeg.destinationAirport
                    existing.missionCode = seededLeg.missionCode
                    existing.schedulerRecordID = seededLeg.schedulerRecordID ?? existing.schedulerRecordID
                    existing.plannedStartAt = seededLeg.plannedStartAt ?? existing.plannedStartAt
                    existing.plannedEndAt = seededLeg.plannedEndAt ?? existing.plannedEndAt
                    return existing
                }
                return seededLeg
            }
            return
        }

        if !session.engineSessionContinuityActive {
            session.plannedLegs = normalizedSeeded
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
                    // Opening the Dispatch editor only selects the planned leg.
                    // DISPATCH FLIGHT performs the actual status transition.
                    session.plannedLegs[index].status = "planned"
                }
                session.currentLegIndex = session.plannedLegs[index].sequenceNumber
            } else if status == "active" || status == "dispatched" {
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
                session.plannedLegs[index].status = "planned"
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

        if dispatch.operationalSessionModelVersion == CVROperationalSessionRecord.modelVersion {
            workflow.consents.removeAll { $0.dispatchID == dispatch.id }
            dispatch.consentStatus = "not_required"
            workflow.activeDispatch = dispatch
            for index in workflow.uploadComponents.indices
            where workflow.uploadComponents[index].componentType == "dispatch_metadata"
                && workflow.uploadComponents[index].lastError.localizedCaseInsensitiveContains("consent") {
                workflow.uploadComponents[index].requestPayloadSnapshot = nil
                workflow.uploadComponents[index].reconciliationRequired = true
                workflow.uploadComponents[index].state = .queued
                workflow.uploadComponents[index].progress = 0
                workflow.uploadComponents[index].lastError = "Dispatch is queued for server verification."
            }
            return true
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

        if archive.dispatch.operationalSessionModelVersion == CVROperationalSessionRecord.modelVersion {
            archive.consents.removeAll { $0.dispatchID == archive.dispatch.id }
            archive.dispatch.consentStatus = "not_required"
            for index in archive.uploadComponents.indices
            where archive.uploadComponents[index].componentType == "dispatch_metadata" {
                archive.uploadComponents[index].requestPayloadSnapshot = nil
                archive.uploadComponents[index].reconciliationRequired = true
                archive.uploadComponents[index].state = .queued
                archive.uploadComponents[index].progress = 0
                archive.uploadComponents[index].lastError = "Dispatch is queued for server verification."
            }
            return true
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
            .map { assignment in
                assignment.personName
                    + ":" + assignment.role.rawValue
                    + ":" + assignment.effectivePilotFunction.rawValue
                    + ":" + String(assignment.hasPICResponsibility)
            }
            .sorted()
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

    /// Duty-only signature. Route, meters, fuel, oil and remarks intentionally do
    /// not create a new reservation.
    private static func dutyMaterialSignature(_ dispatch: CVRDispatchRecord) -> String {
        let crew = dispatch.crew.map { assignment in
            let identity = assignment.personID.map(String.init)
                ?? assignment.personName.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
            return [
                identity,
                assignment.role.rawValue,
                assignment.effectivePilotFunction.rawValue,
                assignment.hasPICResponsibility ? "1" : "0",
                assignment.role == .student && assignment.effectivePilotFunction == .pilotFlying ? "1" : "0",
            ].joined(separator: ":")
        }.sorted().joined(separator: "|")
        return [
            String(dispatch.organizationID),
            String(dispatch.aircraftID ?? 0),
            normalizedTail(dispatch.tailNumber),
            dispatch.operationalIdentity?.reservationType.lowercased() ?? "flight_training",
            dispatch.missionCode.trimmingCharacters(in: .whitespacesAndNewlines).uppercased(),
            crew,
        ].joined(separator: "#")
    }

    /// Queue a brand-new Local Dispatch reservation for idempotent scheduler creation.
    /// The frozen snapshot is authoritative across offline retries and app restarts.
    private static func queueLocalScheduleCreation(
        dispatch: inout CVRDispatchRecord,
        state: inout CVRWorkflowState
    ) {
        guard let schedulerRecordID = dispatch.schedulerRecordID,
              let reservationUUID = dispatch.reservationUUID,
              schedulerRecordID.lowercased() == reservationUUID.lowercased(),
              let start = dispatch.scheduledStartTime,
              let end = dispatch.scheduledEndTime,
              end > start,
              !dispatch.crew.isEmpty else {
            return
        }
        let airports = dispatch.informativeRouteAirports ?? []
        let legUUIDs = dispatch.informativePlannedLegUUIDs ?? []
        var legs: [[String: Any]] = []
        if airports.count >= 2 {
            for index in 0..<(airports.count - 1) {
                legs.append([
                    "leg_uuid": index < legUUIDs.count ? legUUIDs[index] : UUID().uuidString.lowercased(),
                    "sequence_number": index + 1,
                    "origin_airport": airports[index],
                    "destination_airport": airports[index + 1],
                ])
            }
        }
        let crew: [[String: Any]] = dispatch.crew.map { assignment in
            var member: [String: Any] = [
                "person_name": assignment.personName,
                "role": assignment.role.rawValue,
                "pilot_function": assignment.effectivePilotFunction.rawValue,
                "is_pic": assignment.hasPICResponsibility,
                "is_primary_customer": assignment.role == .student
                    && assignment.effectivePilotFunction == .pilotFlying,
            ]
            if let personID = assignment.personID {
                member["user_id"] = personID
            }
            return member
        }
        let componentID = "schedule-duty-\(schedulerRecordID.lowercased())"
        let payload: [String: Any] = [
            "operation": "create",
            "request_id": componentID,
            "scheduler_record_id": schedulerRecordID.lowercased(),
            "reservation_uuid": reservationUUID.lowercased(),
            "aircraft_id": dispatch.aircraftID ?? 0,
            "aircraft_registration": dispatch.tailNumber,
            "reservation_type": "flight_training",
            "mission_code": dispatch.missionCode,
            "scheduled_date": scheduleLocalDateString(start),
            "scheduled_start_time": scheduleLocalTimestampString(start),
            "scheduled_end_time": scheduleLocalTimestampString(end),
            "crew": crew,
            "legs": legs,
        ]
        guard let snapshot = try? JSONSerialization.data(withJSONObject: payload, options: [.sortedKeys]) else {
            return
        }
        let component = CVRUploadComponentRecord(
            id: componentID,
            serverID: nil,
            flightRecordID: dispatch.id,
            componentType: "schedule_duty_sync",
            localFilePath: nil,
            sha256: nil,
            byteCount: Int64(snapshot.count),
            state: .queued,
            progress: 0,
            attemptCount: 0,
            lastError: "",
            lastAttemptAt: nil,
            serverVerificationAt: nil,
            serverReceiptID: nil,
            requestPayloadSnapshot: snapshot
        )
        state.uploadComponents.removeAll {
            $0.componentType == "schedule_duty_sync" && $0.flightRecordID == dispatch.id
        }
        state.uploadComponents.append(component)
    }

    private static func queueScheduledDutyWindowUpdate(
        dispatch: CVRDispatchRecord,
        state: inout CVRWorkflowState
    ) {
        guard let schedulerRecordID = dispatch.schedulerRecordID?.lowercased(),
              let reservationUUID = dispatch.reservationUUID?.lowercased(),
              schedulerRecordID == reservationUUID,
              let start = dispatch.scheduledStartTime,
              let end = dispatch.scheduledEndTime,
              end > start else {
            return
        }
        let routeAirports = dispatch.informativeRouteAirports ?? []
        let routeLegs: [[String: Any]] = routeAirports.count >= 2
            ? (0..<(routeAirports.count - 1)).map { index in
                let legUUIDs = dispatch.informativePlannedLegUUIDs ?? []
                return [
                    "sequence_number": index + 1,
                    "leg_uuid": index < legUUIDs.count
                        ? legUUIDs[index]
                        : UUID().uuidString.lowercased(),
                    "origin_airport": routeAirports[index],
                    "destination_airport": routeAirports[index + 1],
                ] as [String: Any]
            }
            : []
        let mutableIndex = state.uploadComponents.firstIndex { component in
            guard component.componentType == "schedule_duty_sync",
                  component.flightRecordID == dispatch.id,
                  component.state != .serverVerified,
                  component.state != .uploaded,
                  component.state != .uploading,
                  component.state != .superseded,
                  let data = component.requestPayloadSnapshot,
                  let payload = try? JSONSerialization.jsonObject(with: data) as? [String: Any] else {
                return false
            }
            return (payload["scheduler_record_id"] as? String)?.lowercased() == schedulerRecordID
        }
        if let mutableIndex,
           let data = state.uploadComponents[mutableIndex].requestPayloadSnapshot,
           var payload = try? JSONSerialization.jsonObject(with: data) as? [String: Any] {
            payload["scheduled_date"] = scheduleLocalDateString(start)
            payload["scheduled_start_time"] = scheduleLocalTimestampString(start)
            payload["scheduled_end_time"] = scheduleLocalTimestampString(end)
            payload["legs"] = routeLegs
            guard let snapshot = try? JSONSerialization.data(withJSONObject: payload, options: [.sortedKeys]) else {
                return
            }
            state.uploadComponents[mutableIndex].requestPayloadSnapshot = snapshot
            state.uploadComponents[mutableIndex].byteCount = Int64(snapshot.count)
            state.uploadComponents[mutableIndex].state = .queued
            state.uploadComponents[mutableIndex].progress = 0
            state.uploadComponents[mutableIndex].lastError = ""
            state.uploadComponents[mutableIndex].errorCode = nil
            state.uploadComponents[mutableIndex].retryable = nil
            state.uploadComponents[mutableIndex].userActionRequired = nil
            return
        }

        let componentID = "schedule-window-\(UUID().uuidString.lowercased())"
        let payload: [String: Any] = [
            "operation": "update_window",
            "request_id": componentID,
            "scheduler_record_id": schedulerRecordID,
            "reservation_uuid": reservationUUID,
            "aircraft_id": dispatch.aircraftID ?? 0,
            "scheduled_date": scheduleLocalDateString(start),
            "scheduled_start_time": scheduleLocalTimestampString(start),
            "scheduled_end_time": scheduleLocalTimestampString(end),
            "legs": routeLegs,
        ]
        guard let snapshot = try? JSONSerialization.data(withJSONObject: payload, options: [.sortedKeys]) else {
            return
        }
        state.uploadComponents.removeAll { component in
            guard component.componentType == "schedule_duty_sync",
                  component.flightRecordID == dispatch.id,
                  component.state != .serverVerified,
                  component.state != .uploaded,
                  let data = component.requestPayloadSnapshot,
                  let existing = try? JSONSerialization.jsonObject(with: data) as? [String: Any] else {
                return false
            }
            return (existing["operation"] as? String) == "update_window"
        }
        state.uploadComponents.append(CVRUploadComponentRecord(
            id: componentID,
            serverID: nil,
            flightRecordID: dispatch.id,
            componentType: "schedule_duty_sync",
            localFilePath: nil,
            sha256: nil,
            byteCount: Int64(snapshot.count),
            state: .queued,
            progress: 0,
            attemptCount: 0,
            lastError: "",
            lastAttemptAt: nil,
            serverVerificationAt: nil,
            serverReceiptID: nil,
            requestPayloadSnapshot: snapshot
        ))
    }

    private static func scheduleLocalDateString(_ date: Date) -> String {
        scheduleLocalFormatter("yyyy-MM-dd").string(from: date)
    }

    private static func scheduleLocalTimestampString(_ date: Date) -> String {
        scheduleLocalFormatter("yyyy-MM-dd HH:mm:ss").string(from: date)
    }

    private static func scheduleLocalFormatter(_ format: String) -> DateFormatter {
        let formatter = DateFormatter()
        formatter.calendar = Calendar(identifier: .gregorian)
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.timeZone = TimeZone(identifier: "America/Los_Angeles")
        formatter.dateFormat = format
        return formatter
    }

    /// A material edit to an online scheduled Duty Assignment creates a new local
    /// reservation immediately and queues an idempotent server supersession. The
    /// queue is deliberately independent from Dispatch confirmation.
    private static func remintAndQueueScheduledDutyReplacement(
        dispatch: inout CVRDispatchRecord,
        state: inout CVRWorkflowState
    ) {
        guard state.activeFlightRecord == nil else { return }
        let currentScheduler = dispatch.schedulerRecordID?
            .trimmingCharacters(in: .whitespacesAndNewlines)
            .lowercased() ?? ""
        guard !currentScheduler.isEmpty else { return }

        let hasUnsynchronizedLocalCreate = state.uploadComponents.contains { component in
            guard component.componentType == "schedule_duty_sync",
                  component.flightRecordID == dispatch.id,
                  component.state != .serverVerified,
                  component.state != .uploaded,
                  let data = component.requestPayloadSnapshot,
                  let payload = try? JSONSerialization.jsonObject(with: data) as? [String: Any] else {
                return false
            }
            return (payload["operation"] as? String) == "create"
                && (payload["scheduler_record_id"] as? String)?.lowercased() == currentScheduler
        }
        if hasUnsynchronizedLocalCreate {
            // The original local draft never existed online. Retire its queued
            // identity and freeze a fresh create instead of superseding a row
            // the server cannot possibly find.
            let replacementUUID = UUID().uuidString.lowercased()
            dispatch.schedulerRecordID = replacementUUID
            dispatch.reservationUUID = replacementUUID
            dispatch.supersedesSchedulerRecordID = nil
            dispatch.supersedesReservationUUID = nil
            let routeSegmentCount = max(0, (dispatch.informativeRouteAirports?.count ?? 0) - 1)
            dispatch.informativePlannedLegUUIDs = (0..<routeSegmentCount).map { _ in
                UUID().uuidString.lowercased()
            }
            if var session = state.operationalSession {
                session.reservationUUID = replacementUUID
                for index in session.plannedLegs.indices {
                    let legUUID = UUID().uuidString.lowercased()
                    session.plannedLegs[index].id = legUUID
                    session.plannedLegs[index].reservationUUID = replacementUUID
                    session.plannedLegs[index].legUUID = legUUID
                    session.plannedLegs[index].schedulerRecordID = replacementUUID
                }
                state.operationalSession = session
            }
            queueLocalScheduleCreation(dispatch: &dispatch, state: &state)
            return
        }

        let priorReplacementWasAccepted = state.uploadComponents.contains { component in
            guard component.componentType == "schedule_duty_sync",
                  component.flightRecordID == dispatch.id,
                  component.state == .serverVerified || component.state == .uploaded,
                  let data = component.requestPayloadSnapshot,
                  let payload = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
                  let replacement = payload["scheduler_record_id"] as? String else {
                return false
            }
            return replacement.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
                == currentScheduler
        }
        if priorReplacementWasAccepted {
            // A later material edit is a new Duty replacement in the chain.
            // Never reuse the UUID that the server has already accepted.
            dispatch.supersedesSchedulerRecordID = nil
            dispatch.supersedesReservationUUID = nil
        }

        let supersededScheduler = dispatch.supersedesSchedulerRecordID ?? currentScheduler
        let supersededReservation = dispatch.supersedesReservationUUID
            ?? dispatch.operationalIdentity?.reservationUUID
            ?? state.operationalSession?.reservationUUID
            ?? currentScheduler

        if dispatch.supersedesSchedulerRecordID == nil {
            let replacementUUID = UUID().uuidString.lowercased()
            dispatch.supersedesSchedulerRecordID = supersededScheduler
            dispatch.supersedesReservationUUID = supersededReservation.lowercased()
            dispatch.schedulerRecordID = replacementUUID
            dispatch.reservationUUID = replacementUUID
            let routeSegmentCount = max(0, (dispatch.informativeRouteAirports?.count ?? 0) - 1)
            dispatch.informativePlannedLegUUIDs = (0..<routeSegmentCount).map { _ in
                UUID().uuidString.lowercased()
            }

            var session = state.operationalSession ?? .empty
            session.reservationUUID = replacementUUID
            for index in session.plannedLegs.indices {
                let legUUID = UUID().uuidString.lowercased()
                session.plannedLegs[index].id = legUUID
                session.plannedLegs[index].reservationUUID = replacementUUID
                session.plannedLegs[index].legUUID = legUUID
                session.plannedLegs[index].schedulerRecordID = replacementUUID
                session.plannedLegs[index].status = "planned"
            }
            state.operationalSession = session

            let firstLeg = session.plannedLegs.sorted { $0.sequenceNumber < $1.sequenceNumber }.first
            if dispatch.operationalSessionModelVersion != CVROperationalSessionRecord.modelVersion,
               let firstLeg,
               let identity = try? CVROperationalIdentityLocal.createOfflineBundle(
                   organizationID: dispatch.organizationID,
                   dispatchUUID: dispatch.id,
                   reservationType: dispatch.operationalIdentity?.reservationType ?? "flight_training",
                   activityDomain: "flight",
                   organizationTimezoneIANA: dispatch.operationalIdentity?.organizationTimezoneIANA
                       ?? TimeZone.current.identifier,
                   originAirport: firstLeg.departureAirport,
                   destinationAirport: firstLeg.destinationAirport,
                   schedulerRecordID: replacementUUID,
                   reservationUUID: replacementUUID,
                   legUUID: firstLeg.legUUID
               ) {
                dispatch.operationalIdentity = identity
            }
        }

        guard let replacementScheduler = dispatch.schedulerRecordID,
              let replacementReservation = dispatch.reservationUUID
                ?? dispatch.operationalIdentity?.reservationUUID,
              let session = state.operationalSession else { return }
        let legs: [[String: Any]]
        if !session.plannedLegs.isEmpty {
            legs = session.plannedLegs.sorted { $0.sequenceNumber < $1.sequenceNumber }.map { leg in
                [
                    "leg_uuid": leg.legUUID,
                    "sequence_number": leg.sequenceNumber,
                    "origin_airport": leg.departureAirport,
                    "destination_airport": leg.destinationAirport,
                ] as [String: Any]
            }
        } else {
            let airports = dispatch.informativeRouteAirports ?? []
            let legUUIDs = dispatch.informativePlannedLegUUIDs ?? []
            var generatedLegs: [[String: Any]] = []
            if airports.count >= 2 {
                for index in 0..<(airports.count - 1) {
                    let legUUID = index < legUUIDs.count
                        ? legUUIDs[index]
                        : UUID().uuidString.lowercased()
                    let leg: [String: Any] = [
                    "leg_uuid": legUUID,
                    "sequence_number": index + 1,
                    "origin_airport": airports[index],
                    "destination_airport": airports[index + 1],
                    ]
                    generatedLegs.append(leg)
                }
            }
            legs = generatedLegs
        }
        let crew = dispatch.crew.map { assignment in
            var member: [String: Any] = [
                "person_name": assignment.personName,
                "role": assignment.role.rawValue,
                "pilot_function": assignment.effectivePilotFunction.rawValue,
                "is_pic": assignment.hasPICResponsibility,
                "is_primary_customer": assignment.role == .student
                    && assignment.effectivePilotFunction == .pilotFlying,
            ]
            if let personID = assignment.personID {
                member["user_id"] = personID
            }
            return member
        }
        let componentID = "schedule-duty-\(replacementScheduler)"
        let payload: [String: Any] = [
            "request_id": componentID,
            "supersedes_scheduler_record_id": supersededScheduler,
            "supersedes_reservation_uuid": supersededReservation,
            "scheduler_record_id": replacementScheduler,
            "reservation_uuid": replacementReservation,
            "aircraft_id": dispatch.aircraftID ?? 0,
            "aircraft_registration": dispatch.tailNumber,
            "reservation_type": dispatch.operationalIdentity?.reservationType ?? "flight_training",
            "mission_code": dispatch.missionCode,
            "crew": crew,
            "legs": legs,
        ]
        guard let snapshot = try? JSONSerialization.data(withJSONObject: payload, options: [.sortedKeys]) else {
            return
        }
        let component = CVRUploadComponentRecord(
            id: componentID,
            serverID: nil,
            flightRecordID: dispatch.id,
            componentType: "schedule_duty_sync",
            localFilePath: nil,
            sha256: nil,
            byteCount: Int64(snapshot.count),
            state: .queued,
            progress: 0,
            attemptCount: 0,
            lastError: "",
            lastAttemptAt: nil,
            serverVerificationAt: nil,
            serverReceiptID: nil,
            requestPayloadSnapshot: snapshot
        )
        state.uploadComponents.removeAll {
            $0.componentType == "schedule_duty_sync" && $0.flightRecordID == dispatch.id
        }
        state.uploadComponents.append(component)
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

    private static func pilotFunction(from value: String?) -> CVRPilotFunction {
        let normalized = value?.trimmingCharacters(in: .whitespacesAndNewlines).uppercased() ?? "NONE"
        return CVRPilotFunction(rawValue: normalized) ?? .none
    }
}
