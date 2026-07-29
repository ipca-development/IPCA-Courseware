import SwiftUI
import UIKit

struct OperationalTabsView: View {
    @EnvironmentObject private var workflow: CVRWorkflowStore
    @EnvironmentObject private var settings: SettingsStore
    @Binding var adminUnlocked: Bool
    @Binding var showAdminUnlock: Bool

    var body: some View {
        TabView(selection: Binding(
            get: { workflow.state.selectedTab },
            set: { workflow.selectTab($0) }
        )) {
            DispatchWorkflowView(showAdminUnlock: $showAdminUnlock)
                .tabItem {
                    Image(systemName: CVROperationalTab.dispatch.systemImage)
                    Text(CVROperationalTab.dispatch.title)
                }
                .tag(CVROperationalTab.dispatch)

            RecorderWorkflowView(adminUnlocked: $adminUnlocked, showAdminUnlock: $showAdminUnlock)
                .tabItem {
                    Image(systemName: CVROperationalTab.recorder.systemImage)
                    Text(CVROperationalTab.recorder.title)
                }
                .tag(CVROperationalTab.recorder)

            InFlightWorkflowView(showAdminUnlock: $showAdminUnlock)
                .tabItem {
                    Image(systemName: CVROperationalTab.inFlight.systemImage)
                    Text(CVROperationalTab.inFlight.title)
                }
                .tag(CVROperationalTab.inFlight)

            GarminWorkflowView(showAdminUnlock: $showAdminUnlock)
                .tabItem {
                    Image(systemName: CVROperationalTab.garmin.systemImage)
                    Text(CVROperationalTab.garmin.title)
                }
                .tag(CVROperationalTab.garmin)
        }
        .tint(CVROperationalPalette.primaryBlue)
        .background(CVROperationalPalette.background.ignoresSafeArea())
    }
}

struct DispatchWorkflowView: View {
    @EnvironmentObject private var workflow: CVRWorkflowStore
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var beacon: AvionicsBeaconManager
    @EnvironmentObject private var missionCatalog: MissionCatalogStore
    @EnvironmentObject private var uploadManager: UploadManager
    @Binding var showAdminUnlock: Bool
    @State private var isEditingDispatch = false

    var body: some View {
        GeometryReader { proxy in
            let metrics = CVROperationalMetrics(size: proxy.size)
            ZStack {
                CVROperationalPalette.background.ignoresSafeArea()
                VStack(spacing: metrics.spacing) {
                    header(metrics)
                    statusCard(metrics)
                    dispatchTiles(metrics)
                    warningCard
                    actionButtons
                }
                .padding(.horizontal, metrics.outerHorizontalPadding)
                .padding(.vertical, metrics.outerVerticalPadding)
                .frame(width: proxy.size.width, height: proxy.size.height, alignment: .top)
            }
        }
        .sheet(isPresented: $isEditingDispatch) {
            DispatchEditorView()
                .environmentObject(workflow)
                .environmentObject(settings)
                .environmentObject(missionCatalog)
                .presentationDetents([.large])
        }
    }

    private func header(_ metrics: CVROperationalMetrics) -> some View {
        CVROperationalHeaderView(
            aircraftRegistration: aircraftRegistration,
            unitIdentifier: settings.cvrUnitIdentifier,
            metrics: metrics,
            onLogoTap: { showAdminUnlock = true }
        )
    }

    private func statusCard(_ metrics: CVROperationalMetrics) -> some View {
        CVROperationalStatusCard(
            title: dispatchStatus.displayTitle,
            subtitle: statusSubtitle,
            iconName: dispatchStatusIcon,
            color: dispatchStatusColor,
            value: nil,
            caption: "DISPATCH RECORD",
            metrics: metrics
        )
    }

    private func dispatchTiles(_ metrics: CVROperationalMetrics) -> some View {
        HStack(spacing: metrics.spacing) {
            CVROperationalTile(title: "ACFT", iconName: "airplane", value: aircraftTile, color: aircraftTileColor, metrics: metrics)
            CVROperationalTile(title: "CREW", iconName: "person.2.fill", value: crewTile, color: crewTileColor, metrics: metrics)
            CVROperationalTile(title: "METERS", iconName: "gauge.with.dots.needle.bottom.50percent", value: meterTile, color: meterTileColor, metrics: metrics)
            CVROperationalTile(title: "FUEL/OIL", iconName: "fuelpump.fill", value: fuelTile, color: fuelTileColor, metrics: metrics)
        }
    }

    @ViewBuilder
    private var warningCard: some View {
        if let error = workflow.lastError.nilIfEmpty {
            CVROperationalWarningCard(title: "WORKFLOW STORAGE WARNING", message: error, iconName: "externaldrive.badge.exclamationmark", color: CVROperationalPalette.warning)
        } else if settings.selectedAircraft == nil {
            CVROperationalWarningCard(title: "AIRCRAFT CONFIGURATION REQUIRED", message: "Assign this CVR Unit to its aircraft before Dispatch.", iconName: "lock.trianglebadge.exclamationmark", color: CVROperationalPalette.critical)
        } else if workflow.state.activeDispatch == nil {
            CVROperationalWarningCard(title: "NO ACTIVE DISPATCH", message: "Create or recover a dispatch before recorder verification.", iconName: "exclamationmark.triangle.fill", color: CVROperationalPalette.standby)
        } else if !workflow.dispatchMissingItems.isEmpty {
            CVROperationalWarningCard(title: workflow.dispatchMissingItems.first ?? "DISPATCH INCOMPLETE", message: "\(workflow.dispatchMissingItems.count) item(s) require attention.", iconName: "checklist.unchecked", color: CVROperationalPalette.warning)
        } else {
            CVROperationalWarningCard(title: "READY FOR VERIFICATION", message: "Dispatch data and individual crew consents are stored locally.", iconName: "checkmark.seal.fill", color: CVROperationalPalette.success)
        }
    }

    private var actionButtons: some View {
        VStack(spacing: 8) {
            if workflow.state.activeDispatch == nil {
                if settings.selectedAircraft == nil {
                    CVROperationalActionButton(title: "CONFIGURE AIRCRAFT", subtitle: "Assign aircraft", color: CVROperationalPalette.critical) {
                        showAdminUnlock = true
                    }
                } else {
                    CVROperationalActionButton(title: "CREATE LOCAL DISPATCH", subtitle: "Works offline", color: CVROperationalPalette.secondaryBlue) {
                        workflow.createOrOpenLocalDispatch(
                            selectedAircraft: settings.selectedAircraft,
                            cvrUnitID: settings.cvrUnitIdentifier,
                            beaconID: beacon.expectedBeaconIdentityHex
                        )
                        isEditingDispatch = true
                    }
                }
            } else {
                if workflow.isDispatchLocked {
                    CVROperationalActionButton(title: "Dispatch Confirmed", subtitle: "Flight Record Created", color: CVROperationalPalette.success) {}
                } else {
                    CVROperationalActionButton(title: "Edit Dispatch", subtitle: "Crew / meters", color: CVROperationalPalette.secondaryBlue) {
                        isEditingDispatch = true
                    }
                    consentButtons
                    if canConfirmDispatch {
                        CVROperationalActionButton(title: "Confirm Dispatch", subtitle: "Create Flight Record", color: CVROperationalPalette.success) {
                            workflow.verifyDispatchAndCreateFlightRecord()
                            uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                        }
                    }
                }
            }
        }
    }

    @ViewBuilder
    private var consentButtons: some View {
        if let dispatch = workflow.state.activeDispatch, !dispatch.crew.isEmpty {
            HStack(spacing: 8) {
                ForEach(Array(dispatch.crew.prefix(2))) { assignment in
                    CVROperationalActionButton(title: "CONSENT", subtitle: assignment.personName.uppercased(), color: consentColor(for: assignment)) {
                        workflow.recordConsent(for: assignment, accepted: true, appVersion: appVersion, deviceID: UIDevice.current.identifierForVendor?.uuidString ?? "local-device")
                    }
                }
            }
        }
    }

    private var dispatchStatus: CVRDispatchStatus {
        workflow.state.activeDispatch?.status ?? .noDispatch
    }

    private var statusSubtitle: String {
        switch dispatchStatus {
        case .noDispatch:
            return "OPEN OR CREATE A FLIGHT ASSIGNMENT"
        case .dispatchIncomplete:
            return "REQUIRED ITEMS ARE MISSING"
        case .consentRequired:
            return "INDIVIDUAL CREW CONSENT REQUIRED"
        case .tailNumberConflict:
            return "AIRCRAFT DOES NOT MATCH CVR UNIT"
        case .readyForVerification:
            return "READY TO CREATE FLIGHT RECORD"
        case .dispatchVerified, .flightRecordLoggingEnabled:
            return "RECORDER VERIFICATION ENABLED"
        }
    }

    private var dispatchStatusIcon: String {
        switch dispatchStatus {
        case .flightRecordLoggingEnabled, .dispatchVerified, .readyForVerification:
            return "checkmark.seal.fill"
        case .tailNumberConflict:
            return "xmark.octagon.fill"
        default:
            return "circle.fill"
        }
    }

    private var dispatchStatusColor: Color {
        switch dispatchStatus {
        case .flightRecordLoggingEnabled, .dispatchVerified:
            return CVROperationalPalette.success
        case .tailNumberConflict:
            return CVROperationalPalette.critical
        case .readyForVerification:
            return CVROperationalPalette.secondaryBlue
        default:
            return CVROperationalPalette.standby
        }
    }

    private var canConfirmDispatch: Bool {
        workflow.dispatchMissingItems.isEmpty || workflow.isDispatchVerified
    }

    private var aircraftRegistration: String {
        settings.selectedAircraft?.registration ?? workflow.state.activeDispatch?.tailNumber ?? "NO AIRCRAFT"
    }

    private var aircraftTile: String {
        workflow.state.activeDispatch?.tailNumber.nilIfEmpty ?? "None"
    }

    private var aircraftTileColor: Color {
        workflow.state.activeDispatch?.tailNumber.nilIfEmpty == nil ? CVROperationalPalette.warning : CVROperationalPalette.success
    }

    private var crewTile: String {
        guard let dispatch = workflow.state.activeDispatch else { return "None" }
        return dispatch.crew.isEmpty ? "Required" : "\(dispatch.crew.count)"
    }

    private var crewTileColor: Color {
        guard let dispatch = workflow.state.activeDispatch, !dispatch.crew.isEmpty else { return CVROperationalPalette.warning }
        return CVROperationalPalette.success
    }

    private var meterTile: String {
        guard let dispatch = workflow.state.activeDispatch else { return "Required" }
        let hobbs = dispatch.startingHobbs.map { String(format: "H: %.1f", $0) } ?? "H: ?"
        let tacho = dispatch.startingTacho.map { String(format: "T: %.1f", $0) } ?? "T: ?"
        return "\(hobbs)\n\(tacho)"
    }

    private var meterTileColor: Color {
        guard let dispatch = workflow.state.activeDispatch, dispatch.startingHobbs != nil, dispatch.startingTacho != nil else { return CVROperationalPalette.warning }
        return CVROperationalPalette.success
    }

    private var fuelTile: String {
        guard let dispatch = workflow.state.activeDispatch else { return "Required" }
        let fuel = Self.fuelGallons(from: dispatch.fuelOnboard).map { "F: \(Self.gallonText($0)) USG" } ?? "F: ? USG"
        let oil = dispatch.oilPercentage.map { "O: \($0)%" } ?? "O: ?%"
        return "\(fuel)\n\(oil)"
    }

    private var fuelTileColor: Color {
        guard let dispatch = workflow.state.activeDispatch, !dispatch.fuelOnboard.isEmpty, dispatch.oilPercentage != nil else { return CVROperationalPalette.warning }
        if let gallons = Self.fuelGallons(from: dispatch.fuelOnboard), gallons <= 3 {
            return CVROperationalPalette.critical
        }
        return CVROperationalPalette.success
    }

    private var appVersion: String {
        Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String ?? "1.0"
    }

    private func consentColor(for assignment: CVRCrewAssignment) -> Color {
        guard let dispatch = workflow.state.activeDispatch else { return CVROperationalPalette.warning }
        let hasConsent = workflow.state.consents.contains {
            $0.dispatchID == dispatch.id && $0.personName == assignment.personName && $0.crewRole == assignment.role && $0.consentResult
        }
        return hasConsent ? CVROperationalPalette.success : CVROperationalPalette.standby
    }

    private static func fuelGallons(from value: String) -> Double? {
        let cleaned = value
            .replacingOccurrences(of: "USG", with: "", options: .caseInsensitive)
            .trimmingCharacters(in: .whitespacesAndNewlines)
        return Double(cleaned)
    }

    private static func gallonText(_ value: Double) -> String {
        String(format: "%.1f", value)
    }
}

struct RecorderWorkflowView: View {
    @EnvironmentObject private var workflow: CVRWorkflowStore
    @EnvironmentObject private var audio: AudioRecorderManager
    @EnvironmentObject private var beacon: AvionicsBeaconManager
    @EnvironmentObject private var gps: GPSLocationManager
    @EnvironmentObject private var system: SystemMonitor
    @Binding var adminUnlocked: Bool
    @Binding var showAdminUnlock: Bool

    var body: some View {
        if !workflow.isDispatchVerified {
            LockedOperationalView(
                title: "LOCKED",
                subtitle: "DISPATCH VERIFICATION REQUIRED",
                iconName: "lock.fill",
                color: CVROperationalPalette.standby,
                showAdminUnlock: $showAdminUnlock
            )
        } else if !workflow.isRecorderVerified {
            RecorderVerificationView(showAdminUnlock: $showAdminUnlock)
        } else {
            StatusDashboardView(adminUnlocked: $adminUnlocked, showAdminUnlock: $showAdminUnlock)
        }
    }
}

struct RecorderVerificationView: View {
    @EnvironmentObject private var workflow: CVRWorkflowStore
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var audio: AudioRecorderManager
    @EnvironmentObject private var beacon: AvionicsBeaconManager
    @EnvironmentObject private var gps: GPSLocationManager
    @EnvironmentObject private var system: SystemMonitor
    @Binding var showAdminUnlock: Bool

    var body: some View {
        GeometryReader { proxy in
            let metrics = CVROperationalMetrics(size: proxy.size)
            ZStack {
                CVROperationalPalette.background.ignoresSafeArea()
                VStack(spacing: metrics.spacing) {
                    CVROperationalHeaderView(aircraftRegistration: aircraftRegistration, unitIdentifier: settings.cvrUnitIdentifier, metrics: metrics, onLogoTap: { showAdminUnlock = true })
                    CVROperationalStatusCard(title: "READY FOR CHECK", subtitle: "RECORDER VERIFICATION REQUIRED", iconName: "waveform.badge.magnifyingglass", color: CVROperationalPalette.secondaryBlue, value: nil, caption: "RECORDER", metrics: metrics)
                    HStack(spacing: metrics.spacing) {
                        CVROperationalTile(title: "AUDIO", iconName: "mic.fill", value: audio.isInternalMicWarning ? "iPhone Mic" : audio.sourceSummary.replacingOccurrences(of: "Audio source: ", with: ""), color: audio.isInternalMicWarning ? CVROperationalPalette.warning : CVROperationalPalette.success, metrics: metrics)
                        CVROperationalTile(title: "BEACON", iconName: "dot.radiowaves.left.and.right", value: beacon.currentState.operationalStatus(secondsSinceLastAdvertisement: beacon.secondsSinceLastAdvertisement).label, color: beaconColor, metrics: metrics)
                        CVROperationalTile(title: "GPS", iconName: "location.fill", value: gpsLabel, color: gpsColor, metrics: metrics)
                        CVROperationalTile(title: "STORAGE", iconName: "externaldrive.fill", value: system.storageText, color: system.availableStorageBytes > 512_000_000 ? CVROperationalPalette.success : CVROperationalPalette.warning, metrics: metrics)
                    }
                    CVROperationalWarningCard(title: "VISUAL VERIFICATION", message: "Confirm recorder health before enabling in-flight logging.", iconName: "eye.fill", color: CVROperationalPalette.standby)
                    CVROperationalActionButton(title: "VERIFY RECORDER", subtitle: "Persist health check", color: CVROperationalPalette.success) {
                        workflow.recordRecorderVerification(
                            audioRouteStatus: audio.sourceSummary,
                            beaconStatus: beacon.currentState.rawValue,
                            gpsStatus: gps.state.rawValue,
                            storageStatus: system.storageText,
                            thermalStatus: thermalLabel,
                            batteryStatus: "\(system.batteryStateText) \(system.batteryLevelPercent)%",
                            permissionStatus: "app-level-checks-pending",
                            fileWritingTestResult: "deferred-to-recording-start",
                            warnings: audio.isInternalMicWarning ? ["IPHONE MICROPHONE ACTIVE"] : [],
                            acceptedWarnings: [],
                            appVersion: appVersion,
                            deviceID: UIDevice.current.identifierForVendor?.uuidString ?? "local-device"
                        )
                    }
                }
                .padding(.horizontal, metrics.outerHorizontalPadding)
                .padding(.vertical, metrics.outerVerticalPadding)
                .frame(width: proxy.size.width, height: proxy.size.height, alignment: .top)
            }
        }
    }

    private var aircraftRegistration: String {
        settings.selectedAircraft?.registration ?? workflow.state.activeDispatch?.tailNumber ?? "NO AIRCRAFT"
    }

    private var beaconColor: Color {
        beacon.currentState == .avionicsOn || beacon.currentState == .temporarilyMissing ? CVROperationalPalette.success : CVROperationalPalette.warning
    }

    private var gpsLabel: String {
        switch gps.state {
        case .ready, .recording: return "Ready"
        case .permissionNeeded: return "Acquiring"
        default: return "Unavailable"
        }
    }

    private var gpsColor: Color {
        switch gps.state {
        case .ready, .recording: return CVROperationalPalette.success
        case .permissionNeeded, .unavailable: return CVROperationalPalette.standby
        default: return CVROperationalPalette.critical
        }
    }

    private var thermalLabel: String {
        switch ProcessInfo.processInfo.thermalState {
        case .nominal: return "NOMINAL"
        case .fair: return "FAIR"
        case .serious: return "SERIOUS"
        case .critical: return "CRITICAL"
        @unknown default: return "UNKNOWN"
        }
    }

    private var appVersion: String {
        Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String ?? "1.0"
    }
}

struct InFlightWorkflowView: View {
    @EnvironmentObject private var workflow: CVRWorkflowStore
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var beacon: AvionicsBeaconManager
    @EnvironmentObject private var gps: GPSLocationManager
    @EnvironmentObject private var uploadManager: UploadManager
    @Binding var showAdminUnlock: Bool
    @State private var isShowingShutdownVerification = false
    @State private var exerciseConfirmed = false
    @State private var trainingRemarkConfirmed = false

    var body: some View {
        Group {
            if !workflow.isRecorderVerified {
                LockedOperationalView(title: "LOCKED", subtitle: "RECORDER VERIFICATION REQUIRED", iconName: "lock.fill", color: CVROperationalPalette.standby, showAdminUnlock: $showAdminUnlock)
            } else {
                TimelineView(.periodic(from: Date(), by: 1)) { timeline in
                    GeometryReader { proxy in
                        let metrics = CVROperationalMetrics(size: proxy.size)
                        ZStack {
                            CVROperationalPalette.background.ignoresSafeArea()
                            VStack(spacing: metrics.spacing) {
                                CVROperationalHeaderView(aircraftRegistration: settings.selectedAircraft?.registration ?? workflow.state.activeDispatch?.tailNumber ?? "NO AIRCRAFT", unitIdentifier: settings.cvrUnitIdentifier, metrics: metrics, onLogoTap: { showAdminUnlock = true })
                                CVROperationalStatusCard(title: inFlightTitle, subtitle: inFlightSubtitle, iconName: "airplane", color: inFlightColor, value: inFlightValue(now: timeline.date), caption: "IN-FLIGHT", metrics: metrics)
                                HStack(spacing: metrics.spacing) {
                                    CVROperationalTile(title: "DISPATCH", iconName: "checkmark.seal.fill", value: "Verified", color: CVROperationalPalette.success, metrics: metrics)
                                    CVROperationalTile(title: "RECORDER", iconName: "waveform", value: "Verified", color: CVROperationalPalette.success, metrics: metrics)
                                    CVROperationalTile(title: "BEACON", iconName: "dot.radiowaves.left.and.right", value: beacon.currentState.operationalStatus(secondsSinceLastAdvertisement: beacon.secondsSinceLastAdvertisement).label, color: avionicsReady ? CVROperationalPalette.success : CVROperationalPalette.standby, metrics: metrics)
                                    CVROperationalTile(title: "GPS", iconName: "location.fill", value: gps.state == .ready || gps.state == .recording ? "Ready" : "Acquiring", color: gps.state == .ready || gps.state == .recording ? CVROperationalPalette.success : CVROperationalPalette.standby, metrics: metrics)
                                }
                                inFlightControlPanel
                                HStack(spacing: metrics.spacing) {
                                    CVROperationalTile(title: "TAKE OFFS", iconName: "airplane.departure", value: "\(takeoffCount)", color: takeoffCount > 0 ? CVROperationalPalette.success : CVROperationalPalette.standby, metrics: metrics)
                                    CVROperationalTile(title: "LANDINGS", iconName: "airplane.arrival", value: "\(landingCount)", color: landingCount > 0 ? CVROperationalPalette.success : CVROperationalPalette.standby, metrics: metrics)
                                }
                            }
                            .padding(.horizontal, metrics.outerHorizontalPadding)
                            .padding(.vertical, metrics.outerVerticalPadding)
                            .frame(width: proxy.size.width, height: proxy.size.height, alignment: .top)
                        }
                    }
                }
            }
        }
        .sheet(isPresented: $isShowingShutdownVerification) {
            ShutdownVerificationView()
                .environmentObject(workflow)
                .environmentObject(gps)
                .presentationDetents([.large])
        }
    }

    private var avionicsReady: Bool {
        beacon.currentState == .avionicsOn || beacon.currentState == .temporarilyMissing
    }

    private var hasEngineStartEvent: Bool {
        offBlockEvent != nil
    }

    private var hasEngineShutdownEvent: Bool {
        onBlockEvent != nil
    }

    private var hasShutdownVerificationEvent: Bool {
        event("shutdown_verification_completed") != nil
    }

    private var offBlockEvent: CVRFlightEventRecord? {
        event("engine_start_off_block")
    }

    private var onBlockEvent: CVRFlightEventRecord? {
        event("engine_shutdown_on_block")
    }

    private func event(_ type: String) -> CVRFlightEventRecord? {
        guard let flightRecord = workflow.state.activeFlightRecord else { return nil }
        return workflow.state.flightEvents.last {
            $0.flightRecordID == flightRecord.id && $0.eventType == type
        }
    }

    private var inFlightTitle: String {
        if hasEngineShutdownEvent { return "ON BLOCK RECORDED" }
        if hasEngineStartEvent { return "OFF BLOCK ACTIVE" }
        return avionicsReady ? "READY FOR ENGINE START" : "STANDING BY"
    }

    private var inFlightSubtitle: String {
        if hasEngineShutdownEvent { return "SHUTDOWN VERIFICATION REQUIRED" }
        if hasEngineStartEvent { return "GPS AIRBORNE FLIGHT TIME" }
        return avionicsReady ? "PRESS AND HOLD ENGINE START" : "WAITING FOR AVIONICS POWER"
    }

    private var inFlightColor: Color {
        if hasEngineShutdownEvent { return CVROperationalPalette.success }
        if hasEngineStartEvent { return CVROperationalPalette.success }
        return avionicsReady ? CVROperationalPalette.secondaryBlue : CVROperationalPalette.standby
    }

    private func inFlightValue(now: Date) -> String? {
        guard hasEngineStartEvent else { return nil }
        return elapsedText(seconds: gpsAirborneSeconds(now: now))
    }

    @ViewBuilder
    private var inFlightControlPanel: some View {
        if hasEngineShutdownEvent {
            VStack(spacing: 8) {
                CVROperationalWarningCard(title: hasShutdownVerificationEvent ? "SHUTDOWN VERIFIED" : "ON BLOCK RECORDED", message: hasShutdownVerificationEvent ? "Post-flight data is stored locally. Continue with Garmin import." : "Official Engine Shutdown time is stored locally.", iconName: "checkmark.seal.fill", color: CVROperationalPalette.success)
                if !hasShutdownVerificationEvent {
                    CVROperationalActionButton(title: "Complete Shutdown Verification", subtitle: "Ending meters / fuel / oil", color: CVROperationalPalette.secondaryBlue) {
                        isShowingShutdownVerification = true
                    }
                }
            }
        } else if hasEngineStartEvent {
            VStack(spacing: 8) {
                HStack(spacing: 8) {
                    CVROperationalActionButton(title: "EXERCISE START", subtitle: "Mark training item", color: CVROperationalPalette.secondaryBlue, isConfirmed: exerciseConfirmed) {
                        workflow.recordInFlightAction(eventType: "exercise_marker", creationMethod: "tap", gpsSample: gps.latestSample)
                        uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                        flashConfirmation($exerciseConfirmed)
                    }
                    CVROperationalActionButton(title: "TRAINING REMARK", subtitle: "Mark comment", color: CVROperationalPalette.secondaryBlue, isConfirmed: trainingRemarkConfirmed) {
                        workflow.recordInFlightAction(eventType: "training_remark_marker", creationMethod: "tap", gpsSample: gps.latestSample)
                        uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                        flashConfirmation($trainingRemarkConfirmed)
                    }
                }
                CVRHoldActionButton(title: "SAFETY EVENT", subtitle: "Hold 2 seconds", color: CVROperationalPalette.warning, minimumDuration: 2) {
                    UIImpactFeedbackGenerator(style: .heavy).impactOccurred()
                    workflow.recordInFlightAction(eventType: "safety_event", creationMethod: "two_second_hold", gpsSample: gps.latestSample)
                    uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                }
                CVRHoldActionButton(title: "ENGINE SHUTDOWN", subtitle: "Hold 3 seconds for ON Block", color: CVROperationalPalette.critical) {
                    UIImpactFeedbackGenerator(style: .heavy).impactOccurred()
                    workflow.recordEngineShutdownOnBlock(gpsSample: gps.latestSample)
                    uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                }
            }
        } else if avionicsReady {
            CVRHoldActionButton(title: "ENGINE START", subtitle: "Hold 3 seconds for OFF Block", color: CVROperationalPalette.success) {
                UIImpactFeedbackGenerator(style: .heavy).impactOccurred()
                workflow.recordEngineStartOffBlock(gpsSample: gps.latestSample)
                uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
            }
        } else {
            CVROperationalWarningCard(title: "WAITING FOR AVIONICS POWER", message: "Engine Start will appear when the paired beacon reports avionics power.", iconName: "timer", color: CVROperationalPalette.standby)
        }
    }

    private var takeoffCount: Int {
        flightEvents.filter { $0.eventType == "gps_takeoff_provisional" }.count
    }

    private var landingCount: Int {
        flightEvents.filter { $0.eventType == "gps_landing_provisional" }.count
    }

    private var flightEvents: [CVRFlightEventRecord] {
        guard let flightRecord = workflow.state.activeFlightRecord else { return [] }
        return workflow.state.flightEvents
            .filter { $0.flightRecordID == flightRecord.id }
            .sorted { $0.timestampUTC < $1.timestampUTC }
    }

    private func gpsAirborneSeconds(now: Date) -> TimeInterval {
        var airborneStart: Date?
        var total: TimeInterval = 0
        for event in flightEvents {
            if event.eventType == "gps_takeoff_provisional", airborneStart == nil {
                airborneStart = event.timestampUTC
            } else if event.eventType == "gps_landing_provisional", let start = airborneStart {
                total += max(0, event.timestampUTC.timeIntervalSince(start))
                airborneStart = nil
            }
        }
        if let airborneStart, !hasEngineShutdownEvent {
            total += max(0, now.timeIntervalSince(airborneStart))
        }
        return total
    }

    private func elapsedText(seconds rawSeconds: TimeInterval) -> String {
        let seconds = max(0, Int(rawSeconds))
        let hours = seconds / 3600
        let minutes = (seconds % 3600) / 60
        let remainingSeconds = seconds % 60
        return String(format: "%02d:%02d:%02d", hours, minutes, remainingSeconds)
    }

    private func flashConfirmation(_ confirmation: Binding<Bool>) {
        confirmation.wrappedValue = true
        Task {
            try? await Task.sleep(for: .milliseconds(700))
            confirmation.wrappedValue = false
        }
    }
}

private struct CVRHoldActionButton: View {
    let title: String
    let subtitle: String
    let color: Color
    var minimumDuration: TimeInterval = 3
    let action: () -> Void
    @State private var isPressing = false
    @State private var holdProgress = 0.0
    @State private var confirmedFlash = false

    var body: some View {
        VStack(spacing: 3) {
            Text(title)
                .font(.title3.weight(.bold))
                .tracking(1.0)
            Text(subtitle)
                .font(.caption.weight(.semibold))
                .foregroundStyle(confirmedFlash ? Color.white.opacity(0.9) : CVROperationalPalette.textSecondary)
        }
        .foregroundStyle(confirmedFlash ? Color.white : color)
        .frame(maxWidth: .infinity, minHeight: 72)
        .background {
            GeometryReader { proxy in
                ZStack(alignment: .leading) {
                    CVROperationalPalette.cardBackground
                    color.opacity(confirmedFlash ? 0.95 : 0.30)
                        .frame(width: proxy.size.width * holdProgress)
                }
                .clipShape(RoundedRectangle(cornerRadius: 18))
            }
        }
        .overlay(RoundedRectangle(cornerRadius: 18).stroke(color.opacity(0.85), lineWidth: 1))
        .scaleEffect(isPressing ? 0.985 : 1.0)
        .contentShape(RoundedRectangle(cornerRadius: 18))
        .onLongPressGesture(
            minimumDuration: minimumDuration,
            maximumDistance: 45,
            pressing: { pressing in
                isPressing = pressing
                if pressing {
                    confirmedFlash = false
                    holdProgress = 0
                    withAnimation(.linear(duration: minimumDuration)) {
                        holdProgress = 1
                    }
                } else if !confirmedFlash {
                    withAnimation(.easeOut(duration: 0.15)) {
                        holdProgress = 0
                    }
                }
            },
            perform: {
                confirmedFlash = true
                holdProgress = 1
                action()
                Task {
                    try? await Task.sleep(for: .milliseconds(450))
                    confirmedFlash = false
                    withAnimation(.easeOut(duration: 0.18)) {
                        holdProgress = 0
                    }
                }
            }
        )
        .animation(.easeInOut(duration: 0.1), value: confirmedFlash)
    }
}

private struct ShutdownVerificationView: View {
    @EnvironmentObject private var workflow: CVRWorkflowStore
    @EnvironmentObject private var gps: GPSLocationManager
    @Environment(\.dismiss) private var dismiss
    @State private var endingHobbs = ""
    @State private var endingTacho = ""
    @State private var fuelRemaining = 0.0
    @State private var oilPercent = 50.0
    @State private var hasFuelSelection = false
    @State private var hasOilSelection = true
    @State private var maintenanceRemark = ""
    @FocusState private var focusedField: NumericField?

    private enum NumericField: Hashable {
        case hobbs
        case tacho
    }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 12) {
                    section("ENDING METERS") {
                        HStack(spacing: 8) {
                            numericTextField("Ending Hobbs", text: $endingHobbs, field: .hobbs)
                            numericTextField("Ending Tacho", text: $endingTacho, field: .tacho)
                        }
                    }

                    section("POST-FLIGHT FUEL / OIL") {
                        HStack(alignment: .top, spacing: 10) {
                            Spacer(minLength: 0)
                            CVRFluidCylinderPicker(
                                title: "FUEL",
                                unit: "USG",
                                value: $fuelRemaining,
                                hasSelection: $hasFuelSelection,
                                maxValue: 13,
                                warningThreshold: 3,
                                fillColor: CVROperationalPalette.success,
                                warningColor: CVROperationalPalette.critical
                            )
                            .frame(width: 132)
                            CVRFluidCylinderPicker(
                                title: "OIL",
                                unit: "%",
                                value: $oilPercent,
                                hasSelection: $hasOilSelection,
                                maxValue: 100,
                                warningThreshold: nil,
                                fillColor: CVROperationalPalette.standby,
                                warningColor: CVROperationalPalette.standby
                            )
                            .frame(width: 132)
                            Spacer(minLength: 0)
                        }
                    }

                    section("MAINTENANCE REMARK") {
                        TextField("Optional remark", text: $maintenanceRemark, axis: .vertical)
                            .lineLimit(3, reservesSpace: true)
                            .font(.subheadline.weight(.semibold))
                            .foregroundStyle(CVROperationalPalette.textPrimary)
                            .padding(10)
                            .background(Color.black.opacity(0.22), in: RoundedRectangle(cornerRadius: 10))
                            .overlay(RoundedRectangle(cornerRadius: 10).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
                    }
                }
                .padding(16)
            }
            .background(CVROperationalPalette.background.ignoresSafeArea())
            .navigationTitle("Shutdown Verification")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Close") { dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Save") {
                        save()
                        dismiss()
                    }
                    .disabled(!canSave)
                }
            }
        }
        .preferredColorScheme(.dark)
        .onAppear(perform: load)
    }

    private var canSave: Bool {
        Double(endingHobbs) != nil && Double(endingTacho) != nil && hasFuelSelection && hasOilSelection
    }

    private func section<Content: View>(_ title: String, @ViewBuilder content: () -> Content) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            Text(title)
                .font(.caption.weight(.bold))
                .tracking(1.2)
                .foregroundStyle(CVROperationalPalette.secondaryBlue)
            content()
        }
        .padding(14)
        .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 18))
        .overlay(RoundedRectangle(cornerRadius: 18).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
    }

    private func numericTextField(_ placeholder: String, text: Binding<String>, field: NumericField) -> some View {
        HStack(spacing: 6) {
            TextField(placeholder, text: text)
                .keyboardType(.decimalPad)
                .focused($focusedField, equals: field)
                .font(.subheadline.weight(.semibold))
                .foregroundStyle(CVROperationalPalette.textPrimary)
            if focusedField == field {
                Button {
                    focusedField = nil
                } label: {
                    Text("DONE")
                        .font(.caption2.weight(.bold))
                        .tracking(0.8)
                        .foregroundStyle(CVROperationalPalette.secondaryBlue)
                }
                .buttonStyle(.plain)
            }
        }
        .padding(10)
        .background(Color.black.opacity(0.22), in: RoundedRectangle(cornerRadius: 10))
        .overlay(RoundedRectangle(cornerRadius: 10).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
    }

    private func load() {
        guard let flightRecord = workflow.state.activeFlightRecord else { return }
        endingHobbs = flightRecord.endingHobbs.map { String(format: "%.1f", $0) } ?? ""
        endingTacho = flightRecord.endingTacho.map { String(format: "%.1f", $0) } ?? ""
        if let fuel = flightRecord.fuelRemaining.flatMap(Self.fuelGallons(from:)) {
            fuelRemaining = min(max(fuel, 0), 13)
            hasFuelSelection = true
        }
        oilPercent = Double(flightRecord.endingOilPercentage ?? 50)
        hasOilSelection = true
        maintenanceRemark = flightRecord.maintenanceRemark ?? ""
    }

    private func save() {
        workflow.recordShutdownVerification(
            endingHobbs: Double(endingHobbs),
            endingTacho: Double(endingTacho),
            fuelRemaining: String(format: "%.1f", fuelRemaining),
            oilPercentage: Int(oilPercent.rounded()),
            maintenanceRemark: maintenanceRemark,
            gpsSample: gps.latestSample
        )
    }

    private static func fuelGallons(from value: String) -> Double? {
        let cleaned = value
            .replacingOccurrences(of: "USG", with: "", options: .caseInsensitive)
            .trimmingCharacters(in: .whitespacesAndNewlines)
        return Double(cleaned)
    }
}

struct GarminWorkflowView: View {
    @EnvironmentObject private var workflow: CVRWorkflowStore
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var uploadManager: UploadManager
    @EnvironmentObject private var recordingStore: RecordingStore
    @EnvironmentObject private var beacon: AvionicsBeaconManager
    @Binding var showAdminUnlock: Bool
    @State private var confirmOfflineArchive = false

    var body: some View {
        GeometryReader { proxy in
            let metrics = CVROperationalMetrics(size: proxy.size)
            ZStack {
                CVROperationalPalette.background.ignoresSafeArea()
                VStack(spacing: metrics.spacing) {
                    CVROperationalHeaderView(aircraftRegistration: settings.selectedAircraft?.registration ?? workflow.state.activeDispatch?.tailNumber ?? "NO AIRCRAFT", unitIdentifier: settings.cvrUnitIdentifier, metrics: metrics, onLogoTap: { showAdminUnlock = true })
                    CVROperationalStatusCard(title: "GARMIN RECOVERY", subtitle: "IMPORT AND UPLOAD QUEUE", iconName: "doc.badge.arrow.up", color: CVROperationalPalette.secondaryBlue, value: nil, caption: "GARMIN", metrics: metrics)
                    HStack(spacing: metrics.spacing) {
                        CVROperationalTile(title: "UPLOAD", iconName: "icloud.and.arrow.up.fill", value: uploadTileValue, color: garminComponents.isEmpty ? CVROperationalPalette.secondaryBlue : CVROperationalPalette.standby, metrics: metrics)
                        CVROperationalTile(title: "TRANSCRIPT", iconName: "text.bubble.fill", value: transcriptTileValue, color: transcriptTileColor, metrics: metrics)
                        CVROperationalTile(title: "REPLAY", iconName: "play.rectangle.fill", value: replayTileValue, color: replayTileColor, metrics: metrics)
                        CVROperationalTile(title: "G3X BT", iconName: "antenna.radiowaves.left.and.right", value: "Not exposed", color: CVROperationalPalette.standby, metrics: metrics)
                    }
                    CVROperationalWarningCard(title: garminWarningTitle, message: garminWarningMessage, iconName: garminWarningIcon, color: garminWarningColor)
                    CVROperationalActionButton(title: uploadButtonTitle, subtitle: uploadButtonSubtitle, color: garminComponents.isEmpty ? CVROperationalPalette.textSecondary : CVROperationalPalette.secondaryBlue) {
                        if allWorkflowComponentsVerified {
                            workflow.resetForNextFlightIfComplete()
                        } else {
                            uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                        }
                    }
                    if canArchiveWithPendingUploads {
                        Button("ARCHIVE LOCALLY & START NEXT FLIGHT") {
                            confirmOfflineArchive = true
                        }
                        .font(.caption.weight(.bold))
                        .foregroundStyle(CVROperationalPalette.secondaryBlue)
                    }
                }
                .padding(.horizontal, metrics.outerHorizontalPadding)
                .padding(.vertical, metrics.outerVerticalPadding)
                .frame(width: proxy.size.width, height: proxy.size.height, alignment: .top)
            }
        }
        .confirmationDialog(
            "Start the next flight?",
            isPresented: $confirmOfflineArchive,
            titleVisibility: .visible
        ) {
            Button("Archive Locally & Start Next Flight") {
                workflow.resetForNextFlightIfComplete()
                uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
            }
            Button("Cancel", role: .cancel) {}
        } message: {
            Text("All workflow evidence will be retained in Admin Flight History. Pending components will continue retrying from the archive.")
        }
        .task {
            await pollRecoveryProcessingStatus()
        }
    }

    private var garminComponents: [CVRUploadComponentRecord] {
        guard let flightRecord = workflow.state.activeFlightRecord else { return [] }
        return workflow.state.uploadComponents.filter {
            $0.flightRecordID == flightRecord.id && $0.componentType == "garmin_csv"
        }
    }

    private var workflowComponents: [CVRUploadComponentRecord] {
        guard let flightRecord = workflow.state.activeFlightRecord else { return [] }
        return workflow.state.uploadComponents.filter { $0.flightRecordID == flightRecord.id }
    }

    private var recoveryRecording: Recording? {
        guard let sessionID = workflow.state.activeFlightRecord?.recordingSessionID else { return nil }
        return recordingStore.recordings.first { $0.flightSessionID == sessionID || $0.id == sessionID }
    }

    private var uploadTileValue: String {
        if let recording = recoveryRecording, recording.uploadStatus == .uploading {
            return "\(Int((recording.uploadProgress * 100).rounded()))%"
        }
        guard !garminComponents.isEmpty else { return "Recovery" }
        if let uploading = workflowComponents.first(where: { $0.state == .uploading }) {
            return "\(Int(((uploading.progress ?? 0) * 100).rounded()))%"
        }
        if workflowComponents.contains(where: { $0.state == .failed || $0.state == .needsUserAction }) {
            return "Failed"
        }
        if allWorkflowComponentsVerified || workflowComponents.contains(where: { $0.state == .uploaded }) {
            return "Uploaded"
        }
        return "Queued"
    }

    private var transcriptTileValue: String {
        guard let recording = recoveryRecording else { return "N/A" }
        switch recording.transcriptStatus {
        case .ready: return "Ready"
        case .transcribing: return "\(recording.transcriptProgress)%"
        case .failed: return "Failed"
        case .pending: return recording.uploadStatus == .uploaded ? "Queued" : "Pending"
        }
    }

    private var transcriptTileColor: Color {
        guard let recording = recoveryRecording else { return CVROperationalPalette.standby }
        if recording.transcriptStatus == .ready { return CVROperationalPalette.success }
        if recording.transcriptStatus == .failed { return CVROperationalPalette.critical }
        return CVROperationalPalette.secondaryBlue
    }

    private var replayTileValue: String {
        guard let recording = recoveryRecording else { return "N/A" }
        let status = (recording.replayStatus ?? "not_started").lowercased()
        if status == "ready" || status == "complete" || status == "completed" { return "Ready" }
        if status == "failed" { return "Failed" }
        if status == "processing" || status == "running" {
            return "\(max(0, min(100, recording.replayProgress ?? 0)))%"
        }
        return "Pending"
    }

    private var replayTileColor: Color {
        guard let recording = recoveryRecording else { return CVROperationalPalette.standby }
        let status = (recording.replayStatus ?? "not_started").lowercased()
        if status == "ready" || status == "complete" || status == "completed" { return CVROperationalPalette.success }
        if status == "failed" { return CVROperationalPalette.critical }
        return status == "processing" || status == "running" ? CVROperationalPalette.secondaryBlue : CVROperationalPalette.standby
    }

    private var uploadButtonSubtitle: String {
        guard !garminComponents.isEmpty else { return "Waiting for CSV" }
        if allWorkflowComponentsVerified {
            return "Return to Dispatch"
        }
        if let uploading = workflowComponents.first(where: { $0.state == .uploading }) {
            return "Uploading \(Int(((uploading.progress ?? 0) * 100).rounded()))%"
        }
        if failedWorkflowComponent != nil {
            return "Retry missing / failed components"
        }
        return "CSV and flight data"
    }

    private var uploadButtonTitle: String {
        if allWorkflowComponentsVerified {
            return "NEXT FLIGHT"
        }
        return failedWorkflowComponent != nil ? "RETRY FAILED ITEMS" : "UPLOAD QUEUED ITEMS"
    }

    private var allWorkflowComponentsVerified: Bool {
        !workflowComponents.isEmpty && workflowComponents.allSatisfy { $0.state == .serverVerified }
    }

    private var canArchiveWithPendingUploads: Bool {
        guard !allWorkflowComponentsVerified,
              let flightRecord = workflow.state.activeFlightRecord else { return false }
        return flightRecord.status == .awaitingGarmin
            || flightRecord.status == .awaitingUpload
            || flightRecord.status == .complete
    }

    private var failedWorkflowComponent: CVRUploadComponentRecord? {
        workflowComponents.first { $0.state == .failed || $0.state == .needsUserAction }
    }

    private var garminWarningTitle: String {
        if garminComponents.isEmpty { return "GARMIN TAB AVAILABLE" }
        if let failedWorkflowComponent {
            return failedWorkflowComponent.componentType == "dispatch_metadata" ? "DISPATCH UPLOAD FAILED" : "GARMIN UPLOAD FAILED"
        }
        if allWorkflowComponentsVerified { return "FLIGHT DATA SERVER VERIFIED" }
        return "GARMIN CSV IMPORTED"
    }

    private var garminWarningMessage: String {
        if garminComponents.isEmpty {
            return "Share a Garmin CSV to this app to attach it to the Flight Record."
        }
        if let failedWorkflowComponent {
            return failedWorkflowComponent.lastError.nilIfEmpty ?? "Retry missing / failed components."
        }
        if allWorkflowComponentsVerified {
            return "Flight data is server verified. Direct Garmin G3X Bluetooth connection state is not exposed to this iOS app; avionics power is \(avionicsPowerLabel)."
        }
        return "Shared CSV is stored locally and queued. Direct Garmin G3X Bluetooth connection state is not exposed to this iOS app; avionics power is \(avionicsPowerLabel)."
    }

    private var garminWarningIcon: String {
        failedWorkflowComponent == nil ? (garminComponents.isEmpty ? "arrow.triangle.2.circlepath" : "checkmark.seal.fill") : "exclamationmark.triangle.fill"
    }

    private var garminWarningColor: Color {
        if failedWorkflowComponent != nil { return CVROperationalPalette.critical }
        return garminComponents.isEmpty ? CVROperationalPalette.secondaryBlue : CVROperationalPalette.success
    }

    private var avionicsPowerLabel: String {
        beacon.currentState == .avionicsOn || beacon.currentState == .temporarilyMissing ? "ON" : "not detected"
    }

    private func pollRecoveryProcessingStatus() async {
        while !Task.isCancelled {
            if let recording = recoveryRecording,
               let serverID = recording.serverID,
               !serverID.isEmpty,
               let baseURL = settings.normalizedServerURL {
                do {
                    let response = try await APIClient(serverURL: baseURL).status(recordingID: serverID)
                    if let remote = response.recording {
                        recordingStore.update(recording.id) {
                            $0.transcriptProgress = remote.progress
                            $0.replayStatus = remote.reconstructionStatus
                            $0.replayProgress = remote.reconstructionProgress
                            $0.replayStage = remote.reconstructionStage
                            if remote.transcriptionStatus == "ready" {
                                $0.transcriptStatus = .ready
                            } else if remote.transcriptionStatus == "failed" {
                                $0.transcriptStatus = .failed
                            } else if remote.transcriptionStatus == "transcribing" || remote.transcriptionStatus == "queued" {
                                $0.transcriptStatus = .transcribing
                            }
                        }
                    }
                } catch {
                    // Keep the last known processing state; the next poll retries.
                }
            }
            try? await Task.sleep(for: .seconds(5))
        }
    }
}

struct LockedOperationalView: View {
    @EnvironmentObject private var settings: SettingsStore
    var title: String
    var subtitle: String
    var iconName: String
    var color: Color
    @Binding var showAdminUnlock: Bool

    var body: some View {
        GeometryReader { proxy in
            let metrics = CVROperationalMetrics(size: proxy.size)
            ZStack {
                CVROperationalPalette.background.ignoresSafeArea()
                VStack(spacing: metrics.spacing) {
                    CVROperationalHeaderView(aircraftRegistration: settings.selectedAircraft?.registration ?? "NO AIRCRAFT", unitIdentifier: settings.cvrUnitIdentifier, metrics: metrics, onLogoTap: { showAdminUnlock = true })
                    CVROperationalStatusCard(title: title, subtitle: subtitle, iconName: iconName, color: color, value: nil, caption: "WORKFLOW", metrics: metrics)
                    CVROperationalWarningCard(title: subtitle, message: "Complete the previous operational step before using this tab.", iconName: "lock.fill", color: color)
                    Spacer()
                }
                .padding(.horizontal, metrics.outerHorizontalPadding)
                .padding(.vertical, metrics.outerVerticalPadding)
                .frame(width: proxy.size.width, height: proxy.size.height, alignment: .top)
            }
        }
    }
}

struct DispatchEditorView: View {
    @EnvironmentObject private var workflow: CVRWorkflowStore
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var missionCatalog: MissionCatalogStore
    @Environment(\.dismiss) private var dismiss
    @State private var missionCode = ""
    @State private var selectedMissionCode = ""
    @State private var startingHobbs = ""
    @State private var startingTacho = ""
    @State private var fuelGallons = 0.0
    @State private var oilPercent = 0.0
    @State private var hasFuelSelection = false
    @State private var hasOilSelection = false
    @State private var selectedCrewUserID = 0
    @State private var selectedCrewRole: CVRCrewRole = .student
    @State private var showCrewUserList = false
    @State private var editingCrewAssignmentID: String?
    @FocusState private var focusedNumericField: NumericField?

    private enum CrewUserKind {
        case student
        case instructor
        case other
    }

    private enum NumericField: Hashable {
        case hobbs
        case tacho
    }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 12) {
                    editorSection("AIRCRAFT") {
                        HStack {
                            VStack(alignment: .leading, spacing: 3) {
                                Text(settings.selectedAircraft?.registration ?? "NO AIRCRAFT")
                                    .font(.title2.weight(.bold))
                                    .foregroundStyle(settings.selectedAircraft == nil ? CVROperationalPalette.critical : CVROperationalPalette.textPrimary)
                                Text("LOCKED TO THIS CVR UNIT")
                                    .font(.caption.weight(.bold))
                                    .tracking(1.0)
                                    .foregroundStyle(CVROperationalPalette.secondaryBlue)
                            }
                            Spacer()
                            Image(systemName: "lock.fill")
                                .foregroundStyle(CVROperationalPalette.secondaryBlue)
                        }
                    }

                    editorSection("DISPATCH DETAILS") {
                        missionPicker
                        HStack(spacing: 8) {
                            numericTextField("Starting Hobbs", text: $startingHobbs, field: .hobbs)
                            numericTextField("Starting Tacho", text: $startingTacho, field: .tacho)
                        }
                        HStack(alignment: .top, spacing: 10) {
                            Spacer(minLength: 0)
                            CVRFluidCylinderPicker(
                                title: "FUEL",
                                unit: "USG",
                                value: $fuelGallons,
                                hasSelection: $hasFuelSelection,
                                maxValue: 13,
                                warningThreshold: 3,
                                fillColor: CVROperationalPalette.success,
                                warningColor: CVROperationalPalette.critical
                            )
                            .frame(width: 132)
                            CVRFluidCylinderPicker(
                                title: "OIL",
                                unit: "%",
                                value: $oilPercent,
                                hasSelection: $hasOilSelection,
                                maxValue: 100,
                                warningThreshold: nil,
                                fillColor: CVROperationalPalette.standby,
                                warningColor: CVROperationalPalette.standby
                            )
                            .frame(width: 132)
                            Spacer(minLength: 0)
                        }
                    }

                    editorSection("CREW") {
                        crewUserPicker
                        roleButtons

                        CVROperationalActionButton(title: editingCrewAssignmentID == nil ? "ADD CREW MEMBER" : "UPDATE CREW MEMBER", subtitle: selectedCrewRole.label, color: selectedCrewUserID > 0 ? CVROperationalPalette.secondaryBlue : CVROperationalPalette.textSecondary) {
                            addCrew()
                        }

                        ForEach(workflow.state.activeDispatch?.crew ?? []) { assignment in
                            HStack {
                                Text(assignment.personName)
                                    .font(.subheadline.weight(.bold))
                                    .foregroundStyle(CVROperationalPalette.textPrimary)
                                Spacer()
                                Text(assignment.role.label.uppercased())
                                    .font(.caption.weight(.bold))
                                    .foregroundStyle(CVROperationalPalette.secondaryBlue)
                                Button("EDIT") {
                                    editCrew(assignment)
                                }
                                .font(.caption2.weight(.bold))
                                .foregroundStyle(CVROperationalPalette.secondaryBlue)
                                .buttonStyle(.plain)
                                Button("DELETE") {
                                    deleteCrew(assignment)
                                }
                                .font(.caption2.weight(.bold))
                                .foregroundStyle(CVROperationalPalette.critical)
                                .buttonStyle(.plain)
                            }
                            .padding(10)
                            .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 12))
                        }
                    }
                }
                .padding(16)
            }
            .background(CVROperationalPalette.background.ignoresSafeArea())
            .navigationTitle("Dispatch")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Close") { dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Save") {
                        save()
                        dismiss()
                    }
                }
                ToolbarItemGroup(placement: .keyboard) {
                    Spacer()
                    Button("Done") {
                        focusedNumericField = nil
                    }
                    .font(.headline.weight(.bold))
                }
            }
        }
        .preferredColorScheme(.dark)
        .onAppear {
            load()
            if missionCatalog.missions.isEmpty {
                missionCatalog.loadBundledFallback()
            }
        }
    }

    @ViewBuilder
    private var missionPicker: some View {
        if missionCatalog.missions.isEmpty {
            darkTextField("Mission Code", text: $missionCode)
            if !missionCatalog.lastError.isEmpty {
                Text(missionCatalog.lastError)
                    .font(.caption)
                    .foregroundStyle(CVROperationalPalette.warning)
            }
        } else {
            Picker("Mission", selection: $selectedMissionCode) {
                Text("Select Mission").tag("")
                ForEach(missionCatalog.missions) { mission in
                    Text(mission.displayTitle).tag(mission.missionCode)
                }
            }
            .pickerStyle(.menu)
            .tint(CVROperationalPalette.secondaryBlue)
            .padding(10)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(Color.black.opacity(0.22), in: RoundedRectangle(cornerRadius: 10))
            .overlay(RoundedRectangle(cornerRadius: 10).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))

            if let selected = missionCatalog.mission(code: selectedMissionCode) {
                Text(selected.missionDescription)
                    .font(.caption)
                    .foregroundStyle(CVROperationalPalette.textSecondary)
                    .lineLimit(2)
            }
        }
    }

    @ViewBuilder
    private var crewUserPicker: some View {
        if settings.crewUsers.isEmpty {
            CVROperationalWarningCard(
                title: "NO USERS LOADED",
                message: settings.crewUsersError.isEmpty ? "Refresh users when online." : settings.crewUsersError,
                iconName: "person.crop.circle.badge.exclamationmark",
                color: CVROperationalPalette.warning
            )
        } else {
            VStack(spacing: 8) {
                Button {
                    showCrewUserList.toggle()
                } label: {
                    HStack {
                        Text(selectedCrewUser?.displayName ?? "Select IPCA User")
                            .font(.subheadline.weight(.bold))
                            .foregroundStyle(selectedCrewUser.map(crewUserColor) ?? CVROperationalPalette.textPrimary)
                            .lineLimit(1)
                        Spacer()
                        Image(systemName: showCrewUserList ? "chevron.up" : "chevron.down")
                            .foregroundStyle(CVROperationalPalette.secondaryBlue)
                    }
                    .padding(10)
                    .background(Color.black.opacity(0.22), in: RoundedRectangle(cornerRadius: 10))
                    .overlay(RoundedRectangle(cornerRadius: 10).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
                }
                .buttonStyle(.plain)

                if showCrewUserList {
                    ScrollView {
                        VStack(spacing: 6) {
                            ForEach(settings.crewUsers) { user in
                                Button {
                                    selectCrewUser(user)
                                } label: {
                                    HStack {
                                        Text(user.displayName)
                                            .font(.caption.weight(.bold))
                                            .foregroundStyle(crewUserColor(user))
                                            .lineLimit(1)
                                        Spacer()
                                        if selectedCrewUserID == user.id {
                                            Image(systemName: "checkmark.circle.fill")
                                                .foregroundStyle(crewUserColor(user))
                                        }
                                    }
                                    .padding(.horizontal, 10)
                                    .padding(.vertical, 8)
                                    .background(Color.black.opacity(0.14), in: RoundedRectangle(cornerRadius: 10))
                                }
                                .buttonStyle(.plain)
                            }
                        }
                    }
                    .frame(maxHeight: 240)
                }
            }
        }
    }

    private var roleButtons: some View {
        LazyVGrid(columns: [GridItem(.adaptive(minimum: 128), spacing: 8)], spacing: 8) {
            ForEach(roleOptions) { role in
                let allowed = isRoleAllowed(role)
                Button {
                    if allowed {
                        selectedCrewRole = role
                    }
                } label: {
                    Text(role.label.uppercased())
                        .font(.caption.weight(.bold))
                        .foregroundStyle(roleTextColor(role, allowed: allowed))
                        .frame(maxWidth: .infinity, minHeight: 44)
                        .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 14))
                        .overlay(RoundedRectangle(cornerRadius: 14).stroke((selectedCrewRole == role && allowed ? CVROperationalPalette.success : CVROperationalPalette.cardBorder), lineWidth: 1))
                }
                .buttonStyle(.plain)
                .disabled(!allowed)
            }
        }
    }

    private func editorSection<Content: View>(_ title: String, @ViewBuilder content: () -> Content) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            Text(title)
                .font(.caption.weight(.bold))
                .tracking(1.2)
                .foregroundStyle(CVROperationalPalette.secondaryBlue)
            content()
        }
        .padding(14)
        .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 18))
        .overlay(RoundedRectangle(cornerRadius: 18).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
    }

    private func darkTextField(_ placeholder: String, text: Binding<String>) -> some View {
        TextField(placeholder, text: text)
            .textInputAutocapitalization(.characters)
            .autocorrectionDisabled()
            .font(.subheadline.weight(.semibold))
            .foregroundStyle(CVROperationalPalette.textPrimary)
            .padding(10)
            .background(Color.black.opacity(0.22), in: RoundedRectangle(cornerRadius: 10))
            .overlay(RoundedRectangle(cornerRadius: 10).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
    }

    private func numericTextField(_ placeholder: String, text: Binding<String>, field: NumericField) -> some View {
        HStack(spacing: 6) {
            TextField(placeholder, text: text)
                .keyboardType(.decimalPad)
                .focused($focusedNumericField, equals: field)
                .font(.subheadline.weight(.semibold))
                .foregroundStyle(CVROperationalPalette.textPrimary)
            if focusedNumericField == field {
                Button {
                    focusedNumericField = nil
                } label: {
                    Text("DONE")
                        .font(.caption2.weight(.bold))
                        .tracking(0.8)
                        .foregroundStyle(CVROperationalPalette.secondaryBlue)
                }
                .buttonStyle(.plain)
            }
        }
        .padding(10)
        .background(Color.black.opacity(0.22), in: RoundedRectangle(cornerRadius: 10))
        .overlay(RoundedRectangle(cornerRadius: 10).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
    }

    private func load() {
        guard let dispatch = workflow.state.activeDispatch else { return }
        missionCode = dispatch.missionCode
        selectedMissionCode = dispatch.missionCode
        startingHobbs = dispatch.startingHobbs.map { String(format: "%.1f", $0) } ?? ""
        startingTacho = dispatch.startingTacho.map { String(format: "%.1f", $0) } ?? ""
        if let fuel = Self.fuelGallons(from: dispatch.fuelOnboard) {
            fuelGallons = min(max(fuel, 0), 13)
            hasFuelSelection = true
        } else {
            fuelGallons = 0
            hasFuelSelection = false
        }
        if let oil = dispatch.oilPercentage {
            oilPercent = min(max(Double(oil), 0), 100)
            hasOilSelection = true
        } else {
            oilPercent = 50
            hasOilSelection = true
        }
    }

    private func save() {
        workflow.updateActiveDispatch { dispatch in
            let selectedCode = selectedMissionCode.trimmingCharacters(in: .whitespacesAndNewlines)
            dispatch.missionCode = (selectedCode.isEmpty ? missionCode : selectedCode).trimmingCharacters(in: .whitespacesAndNewlines).uppercased()
            dispatch.startingHobbs = Double(startingHobbs)
            dispatch.startingTacho = Double(startingTacho)
            dispatch.fuelOnboard = hasFuelSelection ? Self.gallonText(fuelGallons) : ""
            dispatch.oilPercentage = hasOilSelection ? Int(oilPercent.rounded()) : nil
        }
    }

    private func addCrew() {
        guard let user = selectedCrewUser, isRoleAllowed(selectedCrewRole) else { return }
        workflow.updateActiveDispatch { dispatch in
            if let editingCrewAssignmentID {
                dispatch.crew.removeAll { $0.id == editingCrewAssignmentID }
            }
            dispatch.crew.removeAll { $0.personID == user.id && $0.role == selectedCrewRole }
            dispatch.crew.append(CVRCrewAssignment(id: UUID().uuidString, personID: user.id, personName: user.displayName, role: selectedCrewRole))
        }
        selectedCrewUserID = 0
        editingCrewAssignmentID = nil
        showCrewUserList = false
    }

    private func editCrew(_ assignment: CVRCrewAssignment) {
        editingCrewAssignmentID = assignment.id
        selectedCrewUserID = assignment.personID ?? settings.crewUsers.first(where: { $0.displayName == assignment.personName })?.id ?? 0
        selectedCrewRole = assignment.role
        showCrewUserList = false
    }

    private func deleteCrew(_ assignment: CVRCrewAssignment) {
        workflow.updateActiveDispatch { dispatch in
            dispatch.crew.removeAll { $0.id == assignment.id }
        }
        if editingCrewAssignmentID == assignment.id {
            editingCrewAssignmentID = nil
            selectedCrewUserID = 0
        }
    }

    private var selectedCrewUser: CVRCrewUser? {
        settings.crewUsers.first { $0.id == selectedCrewUserID }
    }

    private var roleOptions: [CVRCrewRole] {
        [.student, .instructor, .pic, .safetyPilot, .observer]
    }

    private func selectCrewUser(_ user: CVRCrewUser) {
        selectedCrewUserID = user.id
        showCrewUserList = false
        if !isRoleAllowed(selectedCrewRole, for: user) {
            switch crewUserKind(user) {
            case .student:
                selectedCrewRole = .student
            case .instructor:
                selectedCrewRole = .instructor
            case .other:
                selectedCrewRole = .pic
            }
        }
    }

    private func isRoleAllowed(_ role: CVRCrewRole) -> Bool {
        guard let user = selectedCrewUser else { return true }
        return isRoleAllowed(role, for: user)
    }

    private func isRoleAllowed(_ role: CVRCrewRole, for user: CVRCrewUser) -> Bool {
        switch crewUserKind(user) {
        case .student:
            return role != .instructor
        case .instructor:
            return role != .student
        case .other:
            return true
        }
    }

    private func roleTextColor(_ role: CVRCrewRole, allowed: Bool) -> Color {
        guard allowed else { return CVROperationalPalette.textSecondary.opacity(0.45) }
        return selectedCrewRole == role ? CVROperationalPalette.success : CVROperationalPalette.textPrimary
    }

    private func crewUserColor(_ user: CVRCrewUser) -> Color {
        switch crewUserKind(user) {
        case .student:
            return CVROperationalPalette.success
        case .instructor:
            return CVROperationalPalette.secondaryBlue
        case .other:
            return CVROperationalPalette.textPrimary
        }
    }

    private func crewUserKind(_ user: CVRCrewUser) -> CrewUserKind {
        let role = user.role.lowercased()
        if role.contains("student") {
            return .student
        }
        if role.contains("instructor") || role.contains("supervisor") {
            return .instructor
        }
        return .other
    }

    private static func fuelGallons(from value: String) -> Double? {
        let cleaned = value
            .replacingOccurrences(of: "USG", with: "", options: .caseInsensitive)
            .trimmingCharacters(in: .whitespacesAndNewlines)
        return Double(cleaned)
    }

    private static func gallonText(_ value: Double) -> String {
        String(format: "%.1f", value)
    }
}

private struct CVRFluidCylinderPicker: View {
    let title: String
    let unit: String
    @Binding var value: Double
    @Binding var hasSelection: Bool
    let maxValue: Double
    let warningThreshold: Double?
    let fillColor: Color
    let warningColor: Color

    private var normalizedLevel: Double {
        guard maxValue > 0 else { return 0 }
        return min(max(value / maxValue, 0), 1)
    }

    private var activeColor: Color {
        if let warningThreshold, hasSelection, value <= warningThreshold {
            return warningColor
        }
        return fillColor
    }

    private var displayText: String {
        guard hasSelection else { return "SET" }
        if unit == "%" {
            return "\(Int(value.rounded()))%"
        }
        return "\(String(format: "%.1f", value)) \(unit)"
    }

    var body: some View {
        VStack(spacing: 8) {
            Text(displayText)
                .font(.system(size: displayText == "SET" ? 21 : 26, weight: .bold, design: .rounded))
                .foregroundStyle(activeColor)
                .lineLimit(1)
                .minimumScaleFactor(0.75)
                .frame(height: 32)
            ZStack(alignment: .bottom) {
                GeometryReader { proxy in
                    let cylinderHeight = proxy.size.height
                    let fillHeight = cylinderHeight * normalizedLevel
                    ZStack(alignment: .bottom) {
                        Rectangle()
                            .stroke(CVROperationalPalette.cardBorder, lineWidth: 1)
                            .background(Color.black.opacity(0.18))
                        Rectangle()
                            .fill(activeColor.opacity(0.78))
                            .frame(height: fillHeight)
                    }
                    .contentShape(Rectangle())
                    .gesture(
                        DragGesture(minimumDistance: 0)
                            .onChanged { gesture in
                                let y = min(max(gesture.location.y, 0), cylinderHeight)
                                let selected = (1 - y / cylinderHeight) * maxValue
                                let stepped = unit == "USG" ? (selected * 10).rounded() / 10 : selected.rounded()
                                value = min(max(stepped, 0), maxValue)
                                hasSelection = true
                            }
                    )
                }
            }
            .frame(height: 190)
            Text(title)
                .font(.caption.weight(.bold))
                .tracking(1.1)
                .foregroundStyle(CVROperationalPalette.textSecondary)
        }
        .frame(maxWidth: .infinity)
        .padding(12)
        .background(Color.black.opacity(0.18), in: RoundedRectangle(cornerRadius: 18))
        .overlay(RoundedRectangle(cornerRadius: 18).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
    }
}

private extension String {
    var nilIfEmpty: String? {
        let value = trimmingCharacters(in: .whitespacesAndNewlines)
        return value.isEmpty ? nil : value
    }
}
