import SwiftUI
import UIKit

@MainActor
private func completeSimulationDemo(
    workflow: CVRWorkflowStore,
    settings: SettingsStore,
    beacon: AvionicsBeaconManager
) {
    if workflow.finishSimulationDemo(clearAvionicsSimulation: {
        beacon.clearSimulationOverride()
    }) {
        settings.isSimulationModeEnabled = false
    }
}

@MainActor
private func operationalToggle(_ title: String, isOn: Binding<Bool>) -> some View {
    Toggle(isOn: isOn) {
        Text(title)
            .font(.caption.weight(.bold))
            .foregroundStyle(.white)
    }
    .tint(CVROperationalPalette.success)
}

struct OperationalTabsView: View {
    @EnvironmentObject private var workflow: CVRWorkflowStore
    @EnvironmentObject private var settings: SettingsStore
    @Binding var adminUnlocked: Bool
    @Binding var showAdminUnlock: Bool

    var body: some View {
        VStack(spacing: 0) {
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

            if settings.isSimulationModeEnabled {
                SimulationModeChrome()
            }
        }
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
    @State private var recoveryExportURL: URL?
    @State private var recoveryExportError = ""
    @State private var repairRefueledSincePreviousFlight = false
    @State private var repairOilServicedSincePreviousFlight = false
    @State private var repairOilPercent = 0.0
    @State private var repairHasOilSelection = false

    var body: some View {
        GeometryReader { proxy in
            let metrics = CVROperationalMetrics(size: proxy.size)
            ZStack {
                CVROperationalPalette.background.ignoresSafeArea()
                VStack(spacing: metrics.spacing) {
                    header(metrics)
                    statusCard(metrics)
                    dispatchTiles(metrics)
                    dispatchOilUploadSection
                    warningCard
                    continuityUploadRepairCard
                    actionButtons
                }
                .padding(.horizontal, metrics.outerHorizontalPadding)
                .padding(.vertical, metrics.outerVerticalPadding)
                .frame(width: proxy.size.width, height: proxy.size.height, alignment: .top)
            }
        }
        .onAppear {
            syncContinuityRepairState()
        }
        .onChange(of: workflow.state.activeDispatch?.modifiedAt) {
            syncContinuityRepairState()
        }
        .sheet(isPresented: $isEditingDispatch) {
            DispatchEditorView()
                .environmentObject(workflow)
                .environmentObject(settings)
                .environmentObject(missionCatalog)
                .environmentObject(uploadManager)
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
        if let failedDispatch = workflow.dispatchUploadFailure() {
            CVROperationalWarningCard(
                title: "DISPATCH UPLOAD FAILED",
                message: failedDispatch.lastError.nilIfEmpty ?? "The server rejected Dispatch upload. Flight data is still stored locally on this device.",
                iconName: "icloud.slash.fill",
                color: CVROperationalPalette.critical
            )
        } else if workflow.dispatchTailMismatch(enrolledRegistration: settings.selectedAircraft?.registration) {
            CVROperationalWarningCard(
                title: "AIRCRAFT MISMATCH",
                message: "Dispatch tail \(aircraftTile) does not match enrolled aircraft \(settings.selectedAircraft?.registration ?? "—"). Fix alignment before retrying upload.",
                iconName: "airplane.circle.fill",
                color: CVROperationalPalette.critical
            )
        } else if let error = workflow.lastError.nilIfEmpty {
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

    @ViewBuilder
    private var dispatchOilUploadSection: some View {
        if workflow.dispatchUploadVerified() {
            Text("Oil has been uploaded")
                .font(.caption.weight(.bold))
                .tracking(0.8)
                .foregroundStyle(.white)
                .frame(maxWidth: .infinity, alignment: .leading)
                .padding(.horizontal, 4)
        }
    }

    @ViewBuilder
    private var continuityUploadRepairCard: some View {
        if workflow.dispatchContinuityUploadIssue() != nil || workflow.canRepairFailedDispatchUpload {
            VStack(alignment: .leading, spacing: 10) {
                if workflow.dispatchContinuityUploadIssue() != nil {
                    Text("CONTINUITY CONFIRMATION REQUIRED")
                        .font(.caption.weight(.bold))
                        .tracking(1.1)
                        .foregroundStyle(CVROperationalPalette.warning)
                    Text("The server rejected Dispatch because fuel or oil changed more than 20% from the previous flight. Adjust oil if needed, confirm servicing below, then retry upload.")
                        .font(.caption)
                        .foregroundStyle(CVROperationalPalette.textSecondary)
                } else if workflow.canRepairFailedDispatchUpload {
                    Text("DISPATCH UPLOAD REPAIR")
                        .font(.caption.weight(.bold))
                        .tracking(1.1)
                        .foregroundStyle(CVROperationalPalette.warning)
                    Text("Adjust dispatch oil if the reading was wrong, then retry upload.")
                        .font(.caption)
                        .foregroundStyle(CVROperationalPalette.textSecondary)
                }

                if !workflow.dispatchUploadVerified() {
                    HStack {
                        Spacer(minLength: 0)
                        CVRFluidCylinderPicker(
                            title: "OIL",
                            unit: "%",
                            value: $repairOilPercent,
                            hasSelection: $repairHasOilSelection,
                            maxValue: 100,
                            warningThreshold: nil,
                            fillColor: CVROperationalPalette.standby,
                            warningColor: CVROperationalPalette.standby
                        )
                        .frame(width: 132)
                        Spacer(minLength: 0)
                    }
                }

                if showsRepairRefuelConfirmation {
                    operationalToggle("Aircraft was refueled before this flight", isOn: $repairRefueledSincePreviousFlight)
                }
                if showsRepairOilServiceConfirmation {
                    operationalToggle("Oil was serviced before this flight", isOn: $repairOilServicedSincePreviousFlight)
                }
                CVROperationalActionButton(
                    title: "CONFIRM & RETRY DISPATCH UPLOAD",
                    subtitle: "Apply changes and resend",
                    color: CVROperationalPalette.success
                ) {
                    applyContinuityRepairAndRetryUpload()
                }
            }
            .padding(14)
            .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 18))
            .overlay(RoundedRectangle(cornerRadius: 18).stroke(CVROperationalPalette.warning.opacity(0.55), lineWidth: 1))
        }
    }

    private var actionButtons: some View {
        VStack(spacing: 8) {
            if workflow.dispatchUploadFailure() != nil || workflow.dispatchTailMismatch(enrolledRegistration: settings.selectedAircraft?.registration) {
                if workflow.canRepairFailedDispatchUpload {
                    CVROperationalActionButton(
                        title: "EDIT DISPATCH DETAILS",
                        subtitle: "Adjust meters, fuel, oil, or crew",
                        color: CVROperationalPalette.secondaryBlue
                    ) {
                        isEditingDispatch = true
                    }
                }
                if workflow.dispatchTailMismatch(enrolledRegistration: settings.selectedAircraft?.registration) {
                    CVROperationalActionButton(
                        title: "FIX AIRCRAFT & RETRY UPLOAD",
                        subtitle: settings.selectedAircraft?.registration ?? "Select enrolled aircraft in Admin",
                        color: CVROperationalPalette.secondaryBlue
                    ) {
                        _ = workflow.repairDispatchAircraftAlignment(selectedAircraft: settings.selectedAircraft)
                        uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                    }
                } else if workflow.dispatchContinuityUploadIssue() == nil {
                    CVROperationalActionButton(title: "RETRY DISPATCH UPLOAD", subtitle: "Resend failed Dispatch metadata", color: CVROperationalPalette.warning) {
                        workflow.requeueFailedUploads(componentTypes: ["dispatch_metadata"])
                        uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                    }
                }
                if !workflow.failedActiveUploadComponents().isEmpty {
                    CVROperationalActionButton(title: "RETRY ALL FAILED UPLOADS", subtitle: "Dispatch, events, closure, Garmin", color: CVROperationalPalette.warning) {
                        workflow.requeueFailedUploads()
                        uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                    }
                }
                CVROperationalActionButton(title: "EXPORT RECOVERY JSON", subtitle: "Share local flight evidence backup", color: CVROperationalPalette.standby) {
                    do {
                        recoveryExportURL = try workflow.activeWorkflowExportURL()
                        recoveryExportError = ""
                    } catch {
                        recoveryExportURL = nil
                        recoveryExportError = error.localizedDescription
                    }
                }
                if let recoveryExportURL {
                    ShareLink(item: recoveryExportURL) {
                        Label("Share Recovery Backup", systemImage: "square.and.arrow.up")
                            .frame(maxWidth: .infinity)
                    }
                    .buttonStyle(.borderedProminent)
                    .tint(CVROperationalPalette.secondaryBlue)
                }
                if !recoveryExportError.isEmpty {
                    Text(recoveryExportError)
                        .font(.caption)
                        .foregroundStyle(CVROperationalPalette.critical)
                }
            }
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
                if workflow.isDispatchLocked && workflow.dispatchUploadFailure() == nil {
                    CVROperationalActionButton(title: "Dispatch Confirmed", subtitle: "Flight Record Created", color: CVROperationalPalette.success) {}
                } else if !workflow.isDispatchLocked {
                    CVROperationalActionButton(title: "Edit Dispatch", subtitle: "Crew / meters / oil", color: CVROperationalPalette.secondaryBlue) {
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
        workflow.dispatchMissingItems.isEmpty
    }

    private var showsRepairRefuelConfirmation: Bool {
        switch workflow.dispatchContinuityUploadIssue() {
        case .refueling:
            return true
        case .oilServicing, nil:
            return workflow.dispatchMissingItems.contains(where: { $0.contains("CONFIRM AIRCRAFT WAS REFUELED") })
        }
    }

    private var showsRepairOilServiceConfirmation: Bool {
        switch workflow.dispatchContinuityUploadIssue() {
        case .oilServicing:
            return true
        case .refueling, nil:
            return workflow.dispatchMissingItems.contains(where: { $0.contains("CONFIRM OIL WAS SERVICED") })
        }
    }

    private func syncContinuityRepairState() {
        guard let dispatch = workflow.state.activeDispatch else { return }
        repairRefueledSincePreviousFlight = dispatch.refueledSincePreviousFlight ?? false
        repairOilServicedSincePreviousFlight = dispatch.oilServicedSincePreviousFlight ?? false
        if let oil = dispatch.oilPercentage {
            repairOilPercent = min(max(Double(oil), 0), 100)
            repairHasOilSelection = true
        } else {
            repairOilPercent = 50
            repairHasOilSelection = false
        }
    }

    private func applyContinuityRepairAndRetryUpload() {
        guard workflow.updateActiveDispatchForUploadRepair({ dispatch in
            if repairHasOilSelection {
                dispatch.oilPercentage = Int(repairOilPercent.rounded())
            }
            if showsRepairRefuelConfirmation {
                dispatch.refueledSincePreviousFlight = repairRefueledSincePreviousFlight
            }
            if showsRepairOilServiceConfirmation {
                dispatch.oilServicedSincePreviousFlight = repairOilServicedSincePreviousFlight
            }
        }) else {
            return
        }
        uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
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
                                    CVROperationalHoldTile(
                                        title: "TAKE OFFS",
                                        iconName: "airplane.departure",
                                        value: "\(operationCounts.displayTakeoffs)",
                                        subtitle: "Hold 2s to +1",
                                        color: operationCounts.displayTakeoffs > 0 ? CVROperationalPalette.success : CVROperationalPalette.standby,
                                        metrics: metrics,
                                        minimumDuration: 2,
                                        isEnabled: hasEngineStartEvent && !hasEngineShutdownEvent
                                    ) {
                                        UIImpactFeedbackGenerator(style: .medium).impactOccurred()
                                        workflow.recordManualTakeoffAdjustment(gpsSample: gps.latestSample)
                                        uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                                    }
                                    CVROperationalHoldTile(
                                        title: "LANDINGS",
                                        iconName: "airplane.arrival",
                                        value: "\(operationCounts.displayLandings)",
                                        subtitle: "Hold 2s to +1",
                                        color: operationCounts.displayLandings > 0 ? CVROperationalPalette.success : CVROperationalPalette.standby,
                                        metrics: metrics,
                                        minimumDuration: 2,
                                        isEnabled: hasEngineStartEvent && !hasEngineShutdownEvent
                                    ) {
                                        UIImpactFeedbackGenerator(style: .medium).impactOccurred()
                                        workflow.recordManualLandingAdjustment(gpsSample: gps.latestSample)
                                        uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                                    }
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
        beacon.isSimulationOverrideActive || beacon.currentState == .avionicsOn || beacon.currentState == .temporarilyMissing
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
                CVROperationalWarningCard(title: hasShutdownVerificationEvent ? "SHUTDOWN VALUES SAVED" : "ON BLOCK RECORDED", message: hasShutdownVerificationEvent ? "Ending meters, fuel, and operation counts are atomically stored locally and queued for an individual server receipt. NEXT FLIGHT remains blocked until verification." : "Official Engine Shutdown time is stored locally and queued for server verification.", iconName: "checkmark.seal.fill", color: CVROperationalPalette.success)
                if !hasShutdownVerificationEvent {
                    CVROperationalActionButton(title: "Complete Shutdown Verification", subtitle: "Ending meters / fuel / operations", color: CVROperationalPalette.secondaryBlue) {
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

    private var operationCounts: (autoTakeoffs: Int, autoLandings: Int, manualTakeoffs: Int, manualLandings: Int, displayTakeoffs: Int, displayLandings: Int) {
        guard let flightRecord = workflow.state.activeFlightRecord else {
            return (0, 0, 0, 0, 0, 0)
        }
        return workflow.operationCounts(for: flightRecord.id)
    }

    private var flightEvents: [CVRFlightEventRecord] {
        guard let flightRecord = workflow.state.activeFlightRecord else { return [] }
        return workflow.state.flightEvents
            .filter { $0.flightRecordID == flightRecord.id }
            .sorted { $0.timestampUTC < $1.timestampUTC }
    }

    private func gpsAirborneSeconds(now: Date) -> TimeInterval {
        var sessionStart: Date?
        var total: TimeInterval = 0
        for event in flightEvents {
            switch event.eventType {
            case "gps_takeoff_provisional", "manual_takeoff_adjustment":
                if sessionStart == nil {
                    sessionStart = event.timestampUTC
                }
            case "gps_landing_provisional":
                let landingKind = event.metadata?["landing_kind"] ?? LandingCycleKind.fullStop.rawValue
                if landingKind == LandingCycleKind.fullStop.rawValue, let start = sessionStart {
                    total += max(0, event.timestampUTC.timeIntervalSince(start))
                    sessionStart = nil
                }
            case "engine_shutdown_on_block":
                if let start = sessionStart {
                    total += max(0, event.timestampUTC.timeIntervalSince(start))
                    sessionStart = nil
                }
            default:
                break
            }
        }
        if let sessionStart, !hasEngineShutdownEvent {
            total += max(0, now.timeIntervalSince(sessionStart))
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
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var uploadManager: UploadManager
    @EnvironmentObject private var gps: GPSLocationManager
    @Environment(\.dismiss) private var dismiss
    @State private var endingHobbs = ""
    @State private var endingTacho = ""
    @State private var fuelRemaining = 0.0
    @State private var hasFuelSelection = false
    @State private var verifiedTakeoffs = 0
    @State private var verifiedLandings = 0
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

                    section("POST-FLIGHT FUEL") {
                        HStack {
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
                            Spacer(minLength: 0)
                        }
                    }

                    section("TAKEOFFS / LANDINGS") {
                        VStack(alignment: .leading, spacing: 10) {
                            Text("GPS auto-detected \(detectedTakeoffs) takeoff(s) and \(detectedLandings) landing(s). Adjust if needed before upload.")
                                .font(.caption.weight(.semibold))
                                .foregroundStyle(CVROperationalPalette.textSecondary)
                            HStack(spacing: 12) {
                                operationStepper(title: "TAKE OFFS", value: $verifiedTakeoffs)
                                operationStepper(title: "LANDINGS", value: $verifiedLandings)
                            }
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
                        if save() {
                            uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                            dismiss()
                        }
                    }
                    .disabled(!canSave)
                }
            }
        }
        .preferredColorScheme(.dark)
        .onAppear(perform: load)
    }

    private var canSave: Bool {
        Double(endingHobbs) != nil && Double(endingTacho) != nil && hasFuelSelection && verifiedTakeoffs >= 0 && verifiedLandings >= 0
    }

    private var detectedTakeoffs: Int {
        guard let flightRecord = workflow.state.activeFlightRecord else { return 0 }
        return workflow.operationCounts(for: flightRecord.id).displayTakeoffs
    }

    private var detectedLandings: Int {
        guard let flightRecord = workflow.state.activeFlightRecord else { return 0 }
        return workflow.operationCounts(for: flightRecord.id).displayLandings
    }

    private func operationStepper(title: String, value: Binding<Int>) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            Text(title)
                .font(.caption.weight(.bold))
                .foregroundStyle(CVROperationalPalette.textSecondary)
            HStack(spacing: 10) {
                Button {
                    value.wrappedValue = max(0, value.wrappedValue - 1)
                } label: {
                    Image(systemName: "minus.circle.fill")
                        .font(.title2)
                }
                .buttonStyle(.plain)
                Text("\(value.wrappedValue)")
                    .font(.title2.weight(.bold))
                    .frame(minWidth: 36)
                Button {
                    value.wrappedValue += 1
                } label: {
                    Image(systemName: "plus.circle.fill")
                        .font(.title2)
                }
                .buttonStyle(.plain)
            }
            .foregroundStyle(CVROperationalPalette.secondaryBlue)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
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
        verifiedTakeoffs = flightRecord.verifiedTakeoffCount ?? workflow.operationCounts(for: flightRecord.id).displayTakeoffs
        verifiedLandings = flightRecord.verifiedLandingCount ?? workflow.operationCounts(for: flightRecord.id).displayLandings
        maintenanceRemark = flightRecord.maintenanceRemark ?? ""
    }

    private func save() -> Bool {
        workflow.recordShutdownVerification(
            endingHobbs: Double(endingHobbs),
            endingTacho: Double(endingTacho),
            fuelRemaining: String(format: "%.1f", fuelRemaining),
            verifiedTakeoffCount: verifiedTakeoffs,
            verifiedLandingCount: verifiedLandings,
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
    @EnvironmentObject private var sdRecovery: GarminSDCardRecoveryService
    @EnvironmentObject private var garminVault: GarminCsvVaultStore
    @EnvironmentObject private var garminSync: GarminCsvSyncManager
    @EnvironmentObject private var network: NetworkMonitor
    @Binding var showAdminUnlock: Bool

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
                        CVROperationalTile(title: "SD CARD", iconName: "sdcard.fill", value: sdCardTileValue, color: sdCardTileColor, metrics: metrics)
                    }
                    if let summary = sdRecovery.lastSummary {
                        CVROperationalWarningCard(
                            title: "SD CARD SCAN",
                            message: summary.message,
                            iconName: summary.matchedFlightRecord ? "checkmark.circle.fill" : "externaldrive.fill",
                            color: summary.matchedFlightRecord ? CVROperationalPalette.success : CVROperationalPalette.secondaryBlue
                        )
                    }
                    CVROperationalWarningCard(title: garminWarningTitle, message: garminWarningMessage, iconName: garminWarningIcon, color: garminWarningColor)
                    CVROperationalActionButton(title: uploadButtonTitle, subtitle: uploadButtonSubtitle, color: garminComponents.isEmpty ? CVROperationalPalette.textSecondary : CVROperationalPalette.secondaryBlue) {
                        if settings.isSimulationModeEnabled {
                            completeSimulationDemo(workflow: workflow, settings: settings, beacon: beacon)
                        } else if workflow.dispatchUploadFailure() != nil || !workflow.failedActiveUploadComponents().isEmpty {
                            _ = workflow.repairDispatchAircraftAlignment(selectedAircraft: settings.selectedAircraft)
                            workflow.requeueFailedUploads()
                            uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                        } else if allWorkflowComponentsVerified {
                            workflow.resetForNextFlightIfComplete()
                        } else {
                            uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                        }
                    }
                }
                .padding(.horizontal, metrics.outerHorizontalPadding)
                .padding(.vertical, metrics.outerVerticalPadding)
                .frame(width: proxy.size.width, height: proxy.size.height, alignment: .top)
            }
        }
        .task {
            await runRecoveryPipelineOnce()
            await pollRecoveryProcessingStatus()
        }
    }

    private func runRecoveryPipelineOnce() async {
        sdRecovery.refreshBookmarkState(settings: settings)
        _ = await sdRecovery.scanAndImportIfNeeded(
            settings: settings,
            vault: garminVault,
            workflow: workflow
        )
        await garminSync.syncPending(
            settings: settings,
            vault: garminVault,
            workflow: workflow,
            network: network,
            uploadManager: uploadManager
        )
    }

    private var sdCardTileValue: String {
        if sdRecovery.isScanning || garminSync.isSyncing {
            return "Busy"
        }
        if !sdRecovery.cardConfigured {
            return "Setup"
        }
        if !sdRecovery.cardAvailable {
            return "Waiting"
        }
        if let summary = sdRecovery.lastSummary, summary.matchedFlightRecord || summary.imported > 0 {
            return "Ready"
        }
        return "Scan"
    }

    private var sdCardTileColor: Color {
        if !sdRecovery.cardConfigured {
            return CVROperationalPalette.warning
        }
        if sdRecovery.cardAvailable, sdRecovery.lastSummary?.matchedFlightRecord == true {
            return CVROperationalPalette.success
        }
        return sdRecovery.cardAvailable ? CVROperationalPalette.secondaryBlue : CVROperationalPalette.standby
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
        if settings.isSimulationModeEnabled {
            return "Skip Garmin and return to Dispatch"
        }
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
        if settings.isSimulationModeEnabled {
            return workflow.state.activeFlightRecord == nil ? "DEMO READY" : "FINISH SIMULATION"
        }
        if allWorkflowComponentsVerified {
            return "NEXT FLIGHT"
        }
        return failedWorkflowComponent != nil ? "RETRY FAILED ITEMS" : "UPLOAD QUEUED ITEMS"
    }

    private var allWorkflowComponentsVerified: Bool {
        !workflowComponents.isEmpty && workflowComponents.allSatisfy { $0.state == .serverVerified }
    }

    private var failedWorkflowComponent: CVRUploadComponentRecord? {
        workflowComponents.first { $0.state == .failed || $0.state == .needsUserAction }
    }

    private var garminWarningTitle: String {
        if settings.isSimulationModeEnabled {
            return "SIMULATION MODE"
        }
        if !sdRecovery.cardConfigured {
            return "SD CARD SETUP REQUIRED"
        }
        if garminComponents.isEmpty {
            if sdRecovery.isScanning {
                return "SCANNING SD CARD"
            }
            return sdRecovery.cardAvailable ? "WAITING FOR MATCHING LOG" : "INSERT SD CARD READER"
        }
        if let failedWorkflowComponent {
            return switch failedWorkflowComponent.componentType {
            case "dispatch_metadata": "DISPATCH UPLOAD FAILED"
            case "flight_record_closure": "ENDING METERS / FUEL UPLOAD FAILED"
            case "flight_events": "FLIGHT EVENT UPLOAD FAILED"
            case "recorder_verification": "RECORDER VERIFICATION UPLOAD FAILED"
            default: "GARMIN UPLOAD FAILED"
            }
        }
        if allWorkflowComponentsVerified { return "FLIGHT DATA SERVER VERIFIED" }
        return "GARMIN CSV IMPORTED"
    }

    private var garminWarningMessage: String {
        if settings.isSimulationModeEnabled {
            return "Simulation mode skips SD card import and server uploads."
        }
        if !sdRecovery.cardConfigured {
            return "Configure the Garmin SD card folder once in Admin, then insert the USB-C reader. The app imports data-rich logs automatically."
        }
        if garminComponents.isEmpty {
            if sdRecovery.isScanning {
                return "Scanning the SD card for data-rich Garmin CSV files..."
            }
            if let summary = sdRecovery.lastSummary, !summary.cardAvailable {
                return "Insert the USB-C SD card reader. GPS-only logs are skipped automatically."
            }
            return sdRecovery.lastSummary?.message ?? "Waiting for a matching data-rich Garmin CSV on the SD card."
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
    @EnvironmentObject private var uploadManager: UploadManager
    @Environment(\.dismiss) private var dismiss
    @State private var missionCode = ""
    @State private var selectedMissionCode = ""
    @State private var startingHobbs = ""
    @State private var startingTacho = ""
    @State private var fuelGallons = 0.0
    @State private var oilPercent = 0.0
    @State private var hasFuelSelection = false
    @State private var hasOilSelection = false
    @State private var refueledSincePreviousFlight = false
    @State private var oilServicedSincePreviousFlight = false
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
                        if workflow.state.activeDispatch?.dispatchSource == "verified_previous_flight_carryover" {
                            CVROperationalWarningCard(
                                title: "VERIFIED PREVIOUS FLIGHT VALUES",
                                message: "Starting Hobbs, Starting Tacho, fuel, and oil were prefilled from the latest locally archived flight for this aircraft after all server receipts were verified. Confirm the physical indications before dispatch.",
                                iconName: "checkmark.icloud.fill",
                                color: CVROperationalPalette.success
                            )
                        }
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
                        if !continuityMessages.isEmpty {
                            VStack(alignment: .leading, spacing: 7) {
                                ForEach(continuityMessages, id: \.self) { message in
                                    Text(message)
                                        .font(.caption.weight(.bold))
                                        .foregroundStyle(CVROperationalPalette.warning)
                                }
                            }
                            .padding(10)
                            .background(CVROperationalPalette.warning.opacity(0.12), in: RoundedRectangle(cornerRadius: 10))
                        }
                        if requiresRefuelConfirmation || workflow.dispatchContinuityUploadIssue() == .refueling {
                            operationalToggle("Aircraft was refueled before this flight", isOn: $refueledSincePreviousFlight)
                        }
                        if requiresOilServiceConfirmation || workflow.dispatchContinuityUploadIssue() == .oilServicing {
                            operationalToggle("Oil was serviced before this flight", isOn: $oilServicedSincePreviousFlight)
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
                        if workflow.canRepairFailedDispatchUpload {
                            uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                        }
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
        refueledSincePreviousFlight = dispatch.refueledSincePreviousFlight ?? false
        oilServicedSincePreviousFlight = dispatch.oilServicedSincePreviousFlight ?? false
    }

    private func save() {
        let applyChanges = { (dispatch: inout CVRDispatchRecord) in
            let selectedCode = selectedMissionCode.trimmingCharacters(in: .whitespacesAndNewlines)
            dispatch.missionCode = (selectedCode.isEmpty ? missionCode : selectedCode).trimmingCharacters(in: .whitespacesAndNewlines).uppercased()
            dispatch.startingHobbs = Double(startingHobbs)
            dispatch.startingTacho = Double(startingTacho)
            dispatch.fuelOnboard = hasFuelSelection ? Self.gallonText(fuelGallons) : ""
            dispatch.oilPercentage = hasOilSelection ? Int(oilPercent.rounded()) : nil
            dispatch.refueledSincePreviousFlight = (requiresRefuelConfirmation || workflow.dispatchContinuityUploadIssue() == .refueling)
                ? refueledSincePreviousFlight
                : (dispatch.refueledSincePreviousFlight ?? false)
            dispatch.oilServicedSincePreviousFlight = (requiresOilServiceConfirmation || workflow.dispatchContinuityUploadIssue() == .oilServicing)
                ? oilServicedSincePreviousFlight
                : (dispatch.oilServicedSincePreviousFlight ?? false)
        }
        if workflow.canRepairFailedDispatchUpload {
            _ = workflow.updateActiveDispatchForUploadRepair(applyChanges)
        } else {
            workflow.updateActiveDispatch(applyChanges)
        }
    }

    private var requiresRefuelConfirmation: Bool {
        guard let dispatch = workflow.state.activeDispatch,
              let previous = dispatch.previousFuelRemaining.flatMap(Self.fuelGallons(from:)),
              hasFuelSelection,
              fuelGallons > previous else { return false }
        return Self.relativeDifference(fuelGallons, previous) > 0.20
    }

    private var requiresOilServiceConfirmation: Bool {
        guard let previous = workflow.state.activeDispatch?.previousOilPercentage,
              hasOilSelection,
              oilPercent > Double(previous) else { return false }
        return Self.relativeDifference(oilPercent, Double(previous)) > 0.20
    }

    private var continuityMessages: [String] {
        guard let dispatch = workflow.state.activeDispatch else { return [] }
        var messages: [String] = []
        if let expected = dispatch.previousEndingHobbs,
           let actual = Double(startingHobbs),
           abs(actual - expected) > 0.1 {
            messages.append(String(format: "Hobbs discrepancy: previous ending value was %.1f.", expected))
        }
        if let expected = dispatch.previousEndingTacho,
           let actual = Double(startingTacho),
           abs(actual - expected) > 0.1 {
            messages.append(String(format: "Tacho discrepancy: previous ending value was %.1f.", expected))
        }
        if let previous = dispatch.previousFuelRemaining.flatMap(Self.fuelGallons(from:)),
           hasFuelSelection,
           Self.relativeDifference(fuelGallons, previous) > 0.20 {
            messages.append(fuelGallons > previous
                ? "Fuel differs by more than 20%. Confirm refueling."
                : "Fuel is more than 20% below the previous ending value; refueling cannot explain this.")
        }
        if let previous = dispatch.previousOilPercentage,
           hasOilSelection,
           Self.relativeDifference(oilPercent, Double(previous)) > 0.20 {
            messages.append(oilPercent > Double(previous)
                ? "Oil differs by more than 20%. Confirm oil servicing."
                : "Oil is more than 20% below the previous ending value; servicing cannot explain this.")
        }
        return messages
    }

    private static func relativeDifference(_ lhs: Double, _ rhs: Double) -> Double {
        abs(lhs - rhs) / max(abs(rhs), 0.1)
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

private struct SimulationModeChrome: View {
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var workflow: CVRWorkflowStore
    @EnvironmentObject private var beacon: AvionicsBeaconManager
    @EnvironmentObject private var gps: GPSLocationManager

    var body: some View {
        VStack(spacing: 0) {
            simulationControls
            simulationBanner
        }
        .background(CVROperationalPalette.background.opacity(0.98))
    }

    private var simulationControls: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                simButton("Avionics ON", icon: "bolt.fill") {
                    beacon.simulateAvionicsOn()
                }
                simButton("Avionics OFF", icon: "bolt.slash.fill") {
                    beacon.simulateAvionicsOff()
                }
                simButton("Takeoff", icon: "airplane.departure") {
                    let kind: TakeoffCycleKind
                    if let flightRecord = workflow.state.activeFlightRecord {
                        let counts = workflow.operationCounts(for: flightRecord.id)
                        kind = counts.displayTakeoffs <= counts.displayLandings ? .initial : .cycle
                    } else {
                        kind = .initial
                    }
                    injectTransition(.takeoff(timestamp: Date(), sample: sample(), kind: kind))
                }
                simButton("T&G Land", icon: "airplane.arrival") {
                    injectTransition(.landing(timestamp: Date(), sample: sample(), kind: .touchAndGo))
                }
                simButton("Full Stop", icon: "parkingsign.circle.fill") {
                    injectTransition(.landing(timestamp: Date(), sample: sample(), kind: .fullStop))
                }
                simButton("Finish Demo", icon: "flag.checkered") {
                    completeSimulationDemo(workflow: workflow, settings: settings, beacon: beacon)
                }
                simButton("Reset", icon: "arrow.counterclockwise") {
                    workflow.resetSimulationWorkflow {
                        beacon.clearSimulationOverride()
                    }
                }
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 8)
        }
    }

    private var simulationBanner: some View {
        HStack(spacing: 8) {
            Image(systemName: "play.rectangle.on.rectangle")
                .font(.caption.weight(.bold))
            Text("SIMULATION MODE")
                .font(.caption.weight(.bold))
                .tracking(1.1)
            Spacer(minLength: 0)
            Text("No logging · No uploads")
                .font(.caption2.weight(.semibold))
        }
        .foregroundStyle(Color.black.opacity(0.92))
        .padding(.horizontal, 14)
        .padding(.vertical, 8)
        .frame(maxWidth: .infinity)
        .background(CVROperationalPalette.warning)
    }

    private func simButton(_ title: String, icon: String, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Label(title, systemImage: icon)
                .font(.caption.weight(.bold))
                .padding(.horizontal, 10)
                .padding(.vertical, 8)
                .background(CVROperationalPalette.cardBackground, in: Capsule())
                .overlay(Capsule().stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
        }
        .buttonStyle(.plain)
        .foregroundStyle(CVROperationalPalette.textPrimary)
    }

    private func injectTransition(_ transition: GPSFlightTransition) {
        guard workflow.state.activeFlightRecord != nil else { return }
        gps.injectSimulatedTransition(transition)
    }

    private func sample() -> GPSSample {
        if let latest = gps.latestSample {
            return latest
        }
        return GPSSample(
            timestamp: Date(),
            secondsSinceRecordingStart: 0,
            latitude: 33.626667,
            longitude: -116.159722,
            altitude: 120,
            speedMetersPerSecond: 25,
            speedKnots: 48.6,
            course: 0,
            horizontalAccuracy: 8,
            verticalAccuracy: 12
        )
    }
}

private extension String {
    var nilIfEmpty: String? {
        let value = trimmingCharacters(in: .whitespacesAndNewlines)
        return value.isEmpty ? nil : value
    }
}
