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
            CVROperationalTile(title: "AIRCRAFT", iconName: "airplane", value: aircraftTile, color: aircraftTileColor, metrics: metrics)
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
            CVROperationalWarningCard(title: "AIRCRAFT CONFIGURATION REQUIRED", message: "Admin must assign this CVR Unit to its aircraft before Dispatch.", iconName: "lock.trianglebadge.exclamationmark", color: CVROperationalPalette.critical)
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
                    CVROperationalActionButton(title: "OPEN ADMIN SETTINGS", subtitle: "Assign aircraft", color: CVROperationalPalette.critical) {
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
                HStack(spacing: 8) {
                    CVROperationalActionButton(title: "EDIT DISPATCH", subtitle: "Crew / meters", color: CVROperationalPalette.secondaryBlue) {
                        isEditingDispatch = true
                    }
                    CVROperationalActionButton(title: "VERIFY DISPATCH", subtitle: "Create Flight Record", color: verifyButtonColor) {
                        workflow.verifyDispatchAndCreateFlightRecord()
                    }
                }
                consentButtons
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

    private var verifyButtonColor: Color {
        workflow.dispatchMissingItems.isEmpty ? CVROperationalPalette.success : CVROperationalPalette.textSecondary
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
        return dispatch.crew.isEmpty ? "Required" : "\(dispatch.crew.count) assigned"
    }

    private var crewTileColor: Color {
        guard let dispatch = workflow.state.activeDispatch, !dispatch.crew.isEmpty else { return CVROperationalPalette.warning }
        return CVROperationalPalette.success
    }

    private var meterTile: String {
        guard let dispatch = workflow.state.activeDispatch else { return "Required" }
        let hobbs = dispatch.startingHobbs.map { String(format: "%.1f", $0) } ?? "H?"
        let tacho = dispatch.startingTacho.map { String(format: "%.1f", $0) } ?? "T?"
        return "\(hobbs) / \(tacho)"
    }

    private var meterTileColor: Color {
        guard let dispatch = workflow.state.activeDispatch, dispatch.startingHobbs != nil, dispatch.startingTacho != nil else { return CVROperationalPalette.warning }
        return CVROperationalPalette.success
    }

    private var fuelTile: String {
        guard let dispatch = workflow.state.activeDispatch else { return "Required" }
        let fuel = dispatch.fuelOnboard.nilIfEmpty ?? "Fuel?"
        let oil = dispatch.oilPercentage.map { "\($0)%" } ?? "Oil?"
        return "\(fuel)\n\(oil)"
    }

    private var fuelTileColor: Color {
        guard let dispatch = workflow.state.activeDispatch, !dispatch.fuelOnboard.isEmpty, dispatch.oilPercentage != nil else { return CVROperationalPalette.warning }
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
    @EnvironmentObject private var system: SystemMonitor
    @EnvironmentObject private var gps: GPSLocationManager
    @Binding var showAdminUnlock: Bool

    var body: some View {
        if !workflow.isRecorderVerified {
            LockedOperationalView(title: "LOCKED", subtitle: "RECORDER VERIFICATION REQUIRED", iconName: "lock.fill", color: CVROperationalPalette.standby, showAdminUnlock: $showAdminUnlock)
        } else {
            GeometryReader { proxy in
                let metrics = CVROperationalMetrics(size: proxy.size)
                ZStack {
                    CVROperationalPalette.background.ignoresSafeArea()
                    VStack(spacing: metrics.spacing) {
                        CVROperationalHeaderView(aircraftRegistration: settings.selectedAircraft?.registration ?? workflow.state.activeDispatch?.tailNumber ?? "NO AIRCRAFT", unitIdentifier: settings.cvrUnitIdentifier, metrics: metrics, onLogoTap: { showAdminUnlock = true })
                        CVROperationalStatusCard(title: "STANDING BY", subtitle: "WAITING FOR AVIONICS POWER", iconName: "airplane", color: CVROperationalPalette.standby, value: nil, caption: "IN-FLIGHT", metrics: metrics)
                        HStack(spacing: metrics.spacing) {
                            CVROperationalTile(title: "DISPATCH", iconName: "checkmark.seal.fill", value: "Verified", color: CVROperationalPalette.success, metrics: metrics)
                            CVROperationalTile(title: "RECORDER", iconName: "waveform", value: "Verified", color: CVROperationalPalette.success, metrics: metrics)
                            CVROperationalTile(title: "BEACON", iconName: "dot.radiowaves.left.and.right", value: beacon.currentState.operationalStatus(secondsSinceLastAdvertisement: beacon.secondsSinceLastAdvertisement).label, color: CVROperationalPalette.standby, metrics: metrics)
                            CVROperationalTile(title: "GPS", iconName: "location.fill", value: gps.state == .ready || gps.state == .recording ? "Ready" : "Acquiring", color: gps.state == .ready || gps.state == .recording ? CVROperationalPalette.success : CVROperationalPalette.standby, metrics: metrics)
                        }
                        CVROperationalWarningCard(title: "OFF BLOCK NOT ARMED", message: "Engine Start / OFF Block controls will be added after the verified recorder foundation.", iconName: "timer", color: CVROperationalPalette.standby)
                        CVROperationalTile(title: "STORAGE", iconName: "externaldrive.fill", value: "\(system.storageText) available", color: CVROperationalPalette.success, metrics: metrics)
                    }
                    .padding(.horizontal, metrics.outerHorizontalPadding)
                    .padding(.vertical, metrics.outerVerticalPadding)
                    .frame(width: proxy.size.width, height: proxy.size.height, alignment: .top)
                }
            }
        }
    }
}

struct GarminWorkflowView: View {
    @EnvironmentObject private var workflow: CVRWorkflowStore
    @EnvironmentObject private var settings: SettingsStore
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
                        CVROperationalTile(title: "CSV", iconName: "doc.text.fill", value: "Waiting", color: CVROperationalPalette.standby, metrics: metrics)
                        CVROperationalTile(title: "MATCH", iconName: "link", value: "Pending", color: CVROperationalPalette.standby, metrics: metrics)
                        CVROperationalTile(title: "UPLOAD", iconName: "icloud.and.arrow.up.fill", value: "Recovery", color: CVROperationalPalette.secondaryBlue, metrics: metrics)
                        CVROperationalTile(title: "SERVER", iconName: "checkmark.icloud.fill", value: "Pending", color: CVROperationalPalette.standby, metrics: metrics)
                    }
                    CVROperationalWarningCard(title: "GARMIN TAB AVAILABLE", message: "This tab remains available for import and upload recovery even when Dispatch is not complete.", iconName: "arrow.triangle.2.circlepath", color: CVROperationalPalette.secondaryBlue)
                    CVROperationalActionButton(title: "RETRY FAILED ITEMS", subtitle: "Component queue foundation", color: CVROperationalPalette.secondaryBlue) {}
                }
                .padding(.horizontal, metrics.outerHorizontalPadding)
                .padding(.vertical, metrics.outerVerticalPadding)
                .frame(width: proxy.size.width, height: proxy.size.height, alignment: .top)
            }
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
    @State private var fuelOnboard = ""
    @State private var oilPercentage = ""
    @State private var selectedCrewUserID = 0
    @State private var selectedCrewRole: CVRCrewRole = .student

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
                            darkTextField("Starting Hobbs", text: $startingHobbs)
                                .keyboardType(.decimalPad)
                            darkTextField("Starting Tacho", text: $startingTacho)
                                .keyboardType(.decimalPad)
                        }
                        HStack(spacing: 8) {
                            darkTextField("Fuel Onboard", text: $fuelOnboard)
                            darkTextField("Oil %", text: $oilPercentage)
                                .keyboardType(.numberPad)
                        }
                    }

                    editorSection("CREW") {
                        crewUserPicker
                        roleButtons

                        CVROperationalActionButton(title: "ADD CREW MEMBER", subtitle: selectedCrewRole.label, color: selectedCrewUserID > 0 ? CVROperationalPalette.secondaryBlue : CVROperationalPalette.textSecondary) {
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
            }
        }
        .preferredColorScheme(.dark)
        .onAppear(perform: load)
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
                message: settings.crewUsersError.isEmpty ? "Refresh users in Admin when online." : settings.crewUsersError,
                iconName: "person.crop.circle.badge.exclamationmark",
                color: CVROperationalPalette.warning
            )
        } else {
            Picker("Crew User", selection: $selectedCrewUserID) {
                Text("Select IPCA User").tag(0)
                ForEach(settings.crewUsers) { user in
                    Text("\(user.displayName) - \(user.role.uppercased())").tag(user.id)
                }
            }
            .pickerStyle(.menu)
            .tint(CVROperationalPalette.secondaryBlue)
            .padding(10)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(Color.black.opacity(0.22), in: RoundedRectangle(cornerRadius: 10))
            .overlay(RoundedRectangle(cornerRadius: 10).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
        }
    }

    private var roleButtons: some View {
        LazyVGrid(columns: [GridItem(.adaptive(minimum: 128), spacing: 8)], spacing: 8) {
            ForEach(CVRCrewRole.allCases.filter { $0 != .unknown }) { role in
                Button {
                    selectedCrewRole = role
                } label: {
                    Text(role.label.uppercased())
                        .font(.caption.weight(.bold))
                        .foregroundStyle(selectedCrewRole == role ? CVROperationalPalette.success : CVROperationalPalette.textPrimary)
                        .frame(maxWidth: .infinity, minHeight: 44)
                        .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 14))
                        .overlay(RoundedRectangle(cornerRadius: 14).stroke((selectedCrewRole == role ? CVROperationalPalette.success : CVROperationalPalette.cardBorder), lineWidth: 1))
                }
                .buttonStyle(.plain)
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

    private func load() {
        guard let dispatch = workflow.state.activeDispatch else { return }
        missionCode = dispatch.missionCode
        selectedMissionCode = dispatch.missionCode
        startingHobbs = dispatch.startingHobbs.map { String(format: "%.1f", $0) } ?? ""
        startingTacho = dispatch.startingTacho.map { String(format: "%.1f", $0) } ?? ""
        fuelOnboard = dispatch.fuelOnboard
        oilPercentage = dispatch.oilPercentage.map(String.init) ?? ""
    }

    private func save() {
        workflow.updateActiveDispatch { dispatch in
            let selectedCode = selectedMissionCode.trimmingCharacters(in: .whitespacesAndNewlines)
            dispatch.missionCode = (selectedCode.isEmpty ? missionCode : selectedCode).trimmingCharacters(in: .whitespacesAndNewlines).uppercased()
            dispatch.startingHobbs = Double(startingHobbs)
            dispatch.startingTacho = Double(startingTacho)
            dispatch.fuelOnboard = fuelOnboard.trimmingCharacters(in: .whitespacesAndNewlines)
            dispatch.oilPercentage = Int(oilPercentage)
        }
    }

    private func addCrew() {
        guard let user = settings.crewUsers.first(where: { $0.id == selectedCrewUserID }) else { return }
        workflow.updateActiveDispatch { dispatch in
            dispatch.crew.removeAll { $0.personID == user.id && $0.role == selectedCrewRole }
            dispatch.crew.append(CVRCrewAssignment(id: UUID().uuidString, personID: user.id, personName: user.displayName, role: selectedCrewRole))
        }
        selectedCrewUserID = 0
    }
}

private extension String {
    var nilIfEmpty: String? {
        let value = trimmingCharacters(in: .whitespacesAndNewlines)
        return value.isEmpty ? nil : value
    }
}
