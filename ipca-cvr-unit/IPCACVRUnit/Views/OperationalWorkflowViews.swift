import SwiftUI
import UIKit
import UniformTypeIdentifiers

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

private extension View {
    func operationalBottomFade() -> some View {
        overlay(alignment: .bottom) {
            LinearGradient(
                colors: [
                    Color.black.opacity(0),
                    Color.black.opacity(0.82),
                    Color.black
                ],
                startPoint: .top,
                endPoint: .bottom
            )
            .frame(height: 108)
            .allowsHitTesting(false)
            .accessibilityHidden(true)
        }
    }
}

struct OperationalTabsView: View {
    @EnvironmentObject private var workflow: CVRWorkflowStore
    @EnvironmentObject private var settings: SettingsStore
    @Binding var adminUnlocked: Bool
    @Binding var showAdminUnlock: Bool

    var body: some View {
        GeometryReader { proxy in
            let metrics = CVROperationalMetrics(size: proxy.size)
            VStack(spacing: 0) {
                CVROperationalHeaderView(
                    aircraftRegistration: settings.selectedAircraft?.registration
                        ?? workflow.state.activeDispatch?.tailNumber
                        ?? "NO AIRCRAFT",
                    unitIdentifier: settings.cvrUnitIdentifier,
                    metrics: metrics,
                    onLogoTap: { showAdminUnlock = true }
                )
                .padding(.horizontal, metrics.outerHorizontalPadding)
                .padding(.vertical, metrics.outerVerticalPadding)
                .frame(maxWidth: .infinity)
                .background(CVROperationalPalette.background)
                .overlay(alignment: .bottom) {
                    Rectangle()
                        .fill(CVROperationalPalette.cardBorder.opacity(0.45))
                        .frame(height: 1)
                }
                .zIndex(2)

                selectedTabContent
                    .operationalBottomFade()
                    .frame(maxWidth: .infinity, maxHeight: .infinity)

                if settings.isSimulationModeEnabled {
                    SimulationModeChrome()
                }

                OperationalBottomTabBar()
            }
            .background(CVROperationalPalette.background.ignoresSafeArea())
        }
    }

    @ViewBuilder
    private var selectedTabContent: some View {
        switch workflow.state.selectedTab {
        case .scheduled:
            ScheduledFlightsView(showAdminUnlock: $showAdminUnlock)
        case .dispatch:
            DispatchWorkflowView(showAdminUnlock: $showAdminUnlock)
        case .recorder:
            RecorderWorkflowView(adminUnlocked: $adminUnlocked, showAdminUnlock: $showAdminUnlock)
        case .inFlight:
            InFlightWorkflowView(showAdminUnlock: $showAdminUnlock)
        case .garmin:
            FlightLogView()
        case .log:
            FlightLogView()
        }
    }
}

private struct OperationalBottomTabBar: View {
    @EnvironmentObject private var workflow: CVRWorkflowStore

    var body: some View {
        HStack(spacing: 0) {
            ForEach(CVROperationalTab.allCases.filter { $0 != .garmin }) { tab in
                Button {
                    workflow.selectTab(tab)
                } label: {
                    VStack(spacing: 3) {
                        Image(systemName: tab.systemImage)
                            .font(.system(size: 14, weight: .semibold))
                        Text(tab.title)
                            .font(.system(size: 7, weight: .bold))
                            .lineLimit(1)
                            .minimumScaleFactor(0.8)
                    }
                    .foregroundStyle(
                        workflow.state.selectedTab == tab
                            ? CVROperationalPalette.primaryBlue
                            : Color.white.opacity(0.62)
                    )
                    .frame(maxWidth: .infinity)
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                .accessibilityLabel(tab.title)
                .accessibilityAddTraits(workflow.state.selectedTab == tab ? .isSelected : [])
            }
        }
        .padding(.top, 6)
        .padding(.bottom, 6)
        .background(Color.black.ignoresSafeArea(edges: .bottom))
        .overlay(alignment: .top) {
            Rectangle()
                .fill(Color.white.opacity(0.1))
                .frame(height: 1)
        }
    }
}

private struct ScheduledFlightsView: View {
    @EnvironmentObject private var sessionsStore: ScheduledSessionsStore
    @EnvironmentObject private var workflow: CVRWorkflowStore
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var beacon: AvionicsBeaconManager
    @EnvironmentObject private var audio: AudioRecorderManager
    @Binding var showAdminUnlock: Bool
    @State private var pendingReplacementSession: CVRScheduledSession?

    var body: some View {
        GeometryReader { proxy in
            let metrics = CVROperationalMetrics(size: proxy.size)
            ZStack {
                CVROperationalPalette.background.ignoresSafeArea()
                ScrollView {
                    VStack(spacing: metrics.spacing) {
                        statusCard(metrics)
                        scheduleTiles(metrics)
                        scheduleWarning
                        sessionSection("TODAY", sessions: todaySessions, metrics: metrics)
                        sessionSection("UPCOMING", sessions: upcomingSessions, metrics: metrics)
                        actionButtons
                    }
                    .padding(.horizontal, metrics.outerHorizontalPadding)
                    .padding(.top, metrics.outerVerticalPadding)
                    .padding(.bottom, 132)
                    .frame(width: proxy.size.width, alignment: .top)
                }
                .refreshable {
                    await sessionsStore.refresh(settings: settings)
                }
            }
        }
        .confirmationDialog(
            "Archive the current workflow?",
            isPresented: Binding(
                get: { pendingReplacementSession != nil },
                set: { if !$0 { pendingReplacementSession = nil } }
            ),
            titleVisibility: .visible
        ) {
            if let session = pendingReplacementSession {
                Button("Archive Current and Open Scheduled Dispatch", role: .destructive) {
                    pendingReplacementSession = nil
                    openScheduledSession(session)
                }
            }
            Button("Cancel", role: .cancel) {
                pendingReplacementSession = nil
            }
        } message: {
            Text("The existing workflow will remain in Flight History with its pending upload state. The scheduled Dispatch will then open.")
        }
    }

    private func statusCard(_ metrics: CVROperationalMetrics) -> some View {
        CVROperationalStatusCard(
            title: scheduleStatusTitle,
            subtitle: scheduleStatusSubtitle,
            iconName: sessionsStore.isRefreshing ? "arrow.triangle.2.circlepath" : "calendar.badge.clock",
            color: scheduleStatusColor,
            value: aircraftSessions.isEmpty ? nil : "\(aircraftSessions.count)",
            caption: "SCHEDULED FLIGHTS",
            metrics: metrics
        )
    }

    private func scheduleTiles(_ metrics: CVROperationalMetrics) -> some View {
        HStack(spacing: metrics.spacing) {
            CVROperationalTile(
                title: "ACFT",
                iconName: "airplane",
                value: settings.selectedAircraft?.registration ?? "None",
                color: settings.selectedAircraft == nil ? CVROperationalPalette.warning : CVROperationalPalette.success,
                metrics: metrics
            )
            CVROperationalTile(
                title: "TODAY",
                iconName: "calendar",
                value: "\(todaySessions.count)",
                color: todaySessions.isEmpty ? CVROperationalPalette.standby : CVROperationalPalette.secondaryBlue,
                metrics: metrics
            )
            CVROperationalTile(
                title: "UPCOMING",
                iconName: "calendar.badge.plus",
                value: "\(upcomingSessions.count)",
                color: upcomingSessions.isEmpty ? CVROperationalPalette.standby : CVROperationalPalette.secondaryBlue,
                metrics: metrics
            )
            CVROperationalTile(
                title: "SYNC",
                iconName: sessionsStore.isRefreshing ? "arrow.triangle.2.circlepath" : "icloud.fill",
                value: sessionsStore.isRefreshing ? "Loading" : "Ready",
                color: sessionsStore.lastError.isEmpty ? CVROperationalPalette.success : CVROperationalPalette.warning,
                metrics: metrics
            )
        }
    }

    @ViewBuilder
    private var scheduleWarning: some View {
        if settings.selectedAircraft == nil {
            CVROperationalWarningCard(
                title: "AIRCRAFT CONFIGURATION REQUIRED",
                message: "Assign this CVR Unit to its aircraft before loading scheduled flights.",
                iconName: "lock.trianglebadge.exclamationmark",
                color: CVROperationalPalette.critical
            )
        } else if let error = sessionsStore.lastError.nilIfEmpty {
            CVROperationalWarningCard(
                title: "SCHEDULE SYNC WARNING",
                message: error,
                iconName: "icloud.slash.fill",
                color: CVROperationalPalette.warning
            )
        } else if aircraftSessions.isEmpty && !sessionsStore.isRefreshing {
            CVROperationalWarningCard(
                title: "NO SCHEDULED FLIGHTS",
                message: "No flights are scheduled for this aircraft. You can still create a local Dispatch.",
                iconName: "calendar.badge.exclamationmark",
                color: CVROperationalPalette.standby
            )
        }
    }

    @ViewBuilder
    private func sessionSection(
        _ title: String,
        sessions: [CVRScheduledSession],
        metrics: CVROperationalMetrics
    ) -> some View {
        VStack(alignment: .leading, spacing: 9) {
            Text(title)
                .font(.caption.weight(.bold))
                .tracking(1.1)
                .foregroundStyle(CVROperationalPalette.textSecondary)
                .frame(maxWidth: .infinity, alignment: .leading)
            if sessions.isEmpty {
                HStack(spacing: 10) {
                    Image(systemName: "calendar")
                        .foregroundStyle(CVROperationalPalette.standby)
                    Text("No \(title.lowercased()) flights")
                        .font(.subheadline.weight(.semibold))
                        .foregroundStyle(CVROperationalPalette.textSecondary)
                    Spacer()
                }
                .padding(14)
                .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 16))
                .overlay(RoundedRectangle(cornerRadius: 16).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
            } else {
                ForEach(sessions) { session in
                    scheduledSessionCard(session, metrics: metrics)
                }
            }
        }
    }

    private func scheduledSessionCard(
        _ session: CVRScheduledSession,
        metrics: CVROperationalMetrics
    ) -> some View {
        let blocked = !workflow.canOpenScheduledSession(
            session,
            selectedAircraft: aircraftForSession(session),
            isAudioRecording: audio.isRecording
        )
        return Button {
            if workflow.requiresArchivingBeforeScheduledSession(session) {
                pendingReplacementSession = session
            } else {
                openScheduledSession(session)
            }
        } label: {
            HStack(spacing: 14) {
                Image(systemName: "calendar.badge.checkmark")
                    .font(.title2.weight(.bold))
                    .foregroundStyle(CVROperationalPalette.secondaryBlue)
                    .frame(width: 34)
                VStack(alignment: .leading, spacing: 5) {
                    HStack {
                        Text(session.missionCode.nilIfEmpty ?? "SCHEDULED FLIGHT")
                            .font(.headline.weight(.bold))
                            .foregroundStyle(.white)
                        Spacer()
                        Text(timeRange(session))
                            .font(.subheadline.weight(.bold).monospacedDigit())
                            .foregroundStyle(CVROperationalPalette.secondaryBlue)
                    }
                    Text(route(session))
                        .font(.subheadline.weight(.semibold))
                        .foregroundStyle(CVROperationalPalette.textSecondary)
                    Text(crewSummary(session))
                        .font(.caption.weight(.semibold))
                        .foregroundStyle(CVROperationalPalette.textSecondary)
                }
                Image(systemName: blocked ? "lock.fill" : "chevron.right")
                    .foregroundStyle(blocked ? CVROperationalPalette.warning : CVROperationalPalette.textSecondary)
            }
            .padding(14)
            .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 16))
            .overlay(RoundedRectangle(cornerRadius: 16).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
        }
        .buttonStyle(.plain)
        .disabled(blocked)
        .opacity(blocked ? 0.55 : 1)
    }

    private func openScheduledSession(_ session: CVRScheduledSession) {
        workflow.openDispatchFromScheduledSession(
            session,
            selectedAircraft: aircraftForSession(session),
            cvrUnitID: settings.cvrUnitIdentifier,
            beaconID: beacon.expectedBeaconIdentityHex,
            isAudioRecording: audio.isRecording
        )
    }

    private func aircraftForSession(_ session: CVRScheduledSession) -> CockpitAircraft {
        if let aircraft = settings.aircraft.first(where: {
            $0.id == session.aircraftID
                || normalizedTail($0.registration) == normalizedTail(session.aircraftRegistration)
        }) {
            return aircraft
        }
        return CockpitAircraft(
            id: session.aircraftID,
            registration: session.aircraftRegistration,
            displayName: session.aircraftRegistration,
            homeAirport: session.plannedDepartureAirport,
            operationalConfig: settings.selectedAircraft?.operationalConfig ?? .safeDefaults
        )
    }

    private func normalizedTail(_ value: String) -> String {
        value.uppercased().filter { $0.isLetter || $0.isNumber }
    }

    private var actionButtons: some View {
        VStack(spacing: 8) {
            CVROperationalActionButton(
                title: sessionsStore.isRefreshing ? "REFRESHING SCHEDULE" : "REFRESH SCHEDULE",
                subtitle: "Load flights assigned to \(settings.selectedAircraft?.registration ?? "this aircraft")",
                color: CVROperationalPalette.secondaryBlue
            ) {
                Task { await sessionsStore.refresh(settings: settings) }
            }
            if settings.selectedAircraft == nil {
                CVROperationalActionButton(
                    title: "CONFIGURE AIRCRAFT",
                    subtitle: "Assign this CVR Unit",
                    color: CVROperationalPalette.critical
                ) {
                    showAdminUnlock = true
                }
            } else {
                CVROperationalActionButton(
                    title: "CREATE LOCAL DISPATCH",
                    subtitle: "Use when no flight was scheduled",
                    color: CVROperationalPalette.standby
                ) {
                    workflow.createOrOpenLocalDispatch(
                        selectedAircraft: settings.selectedAircraft,
                        cvrUnitID: settings.cvrUnitIdentifier,
                        beaconID: beacon.expectedBeaconIdentityHex
                    )
                }
            }
        }
    }

    private var scheduleStatusTitle: String {
        if settings.selectedAircraft == nil {
            return "AIRCRAFT REQUIRED"
        }
        if sessionsStore.isRefreshing {
            return "LOADING SCHEDULE"
        }
        return aircraftSessions.isEmpty ? "NO FLIGHTS SCHEDULED" : "FLIGHTS AVAILABLE"
    }

    private var scheduleStatusSubtitle: String {
        if settings.selectedAircraft == nil {
            return "CONFIGURE THE CVR UNIT AIRCRAFT"
        }
        if sessionsStore.isRefreshing {
            return "SYNCING WITH IPCA.TRAINING"
        }
        if aircraftSessions.isEmpty {
            return "LOCAL DISPATCH REMAINS AVAILABLE"
        }
        return "SELECT A SESSION TO PREPARE DISPATCH"
    }

    private var scheduleStatusColor: Color {
        if settings.selectedAircraft == nil {
            return CVROperationalPalette.critical
        }
        if sessionsStore.isRefreshing {
            return CVROperationalPalette.secondaryBlue
        }
        return aircraftSessions.isEmpty ? CVROperationalPalette.standby : CVROperationalPalette.success
    }

    private var aircraftSessions: [CVRScheduledSession] {
        guard let aircraft = settings.selectedAircraft else { return [] }
        let startOfToday = Calendar.current.startOfDay(for: Date())
        var consumedSchedulerRecordIDs = Set(
            workflow.archives.compactMap { $0.dispatch.schedulerRecordID }
        )
        if let activeSchedulerRecordID = workflow.state.activeDispatch?.schedulerRecordID {
            consumedSchedulerRecordIDs.insert(activeSchedulerRecordID)
        }
        return sessionsStore.sessions
            .filter {
                ($0.aircraftID == aircraft.id
                    || CVRWorkflowStore.normalizedTail($0.aircraftRegistration)
                        == CVRWorkflowStore.normalizedTail(aircraft.registration))
                    && ($0.dateTime(nil) ?? .distantPast) >= startOfToday
                    && !consumedSchedulerRecordIDs.contains($0.schedulerRecordID)
            }
            .sorted { ($0.dateTime($0.scheduledStartTime) ?? .distantFuture) < ($1.dateTime($1.scheduledStartTime) ?? .distantFuture) }
    }

    private var todaySessions: [CVRScheduledSession] {
        aircraftSessions.filter { session in
            guard let date = session.dateTime(nil) else { return false }
            return Calendar.current.isDateInToday(date)
        }
    }

    private var upcomingSessions: [CVRScheduledSession] {
        aircraftSessions.filter { session in
            guard let date = session.dateTime(nil) else { return false }
            return !Calendar.current.isDateInToday(date)
        }
    }

    private func timeRange(_ session: CVRScheduledSession) -> String {
        let start = session.dateTime(session.scheduledStartTime)?.formatted(date: .omitted, time: .shortened) ?? "TBD"
        let end = session.dateTime(session.scheduledEndTime)?.formatted(date: .omitted, time: .shortened) ?? "TBD"
        return "\(start)–\(end)"
    }

    private func route(_ session: CVRScheduledSession) -> String {
        let departure = session.plannedDepartureAirport.nilIfEmpty ?? "TBD"
        let destination = session.plannedDestinationAirport.nilIfEmpty ?? "TBD"
        return "\(departure) → \(destination)"
    }

    private func crewSummary(_ session: CVRScheduledSession) -> String {
        let names = session.crew.map(\.personName).filter { !$0.isEmpty }
        return names.isEmpty ? "Crew not assigned" : names.joined(separator: ", ")
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
                ScrollView {
                    VStack(spacing: metrics.spacing) {
                        statusCard(metrics)
                        dispatchTiles(metrics)
                        dispatchOilUploadSection
                        warningCard
                        continuityUploadRepairCard
                        actionButtons
                    }
                    .padding(.horizontal, metrics.outerHorizontalPadding)
                    .padding(.top, metrics.outerVerticalPadding)
                    .padding(.bottom, 132)
                    .frame(width: proxy.size.width, alignment: .top)
                }
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
        } else if workflow.dispatchUploadInProgress() {
            CVROperationalWarningCard(
                title: "DISPATCH UPLOAD IN PROGRESS",
                message: "The Dispatch is stored locally and is being verified by the server.",
                iconName: "icloud.and.arrow.up.fill",
                color: CVROperationalPalette.secondaryBlue
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
                            unit: operationalConfig.oilUnit,
                            value: $repairOilPercent,
                            hasSelection: $repairHasOilSelection,
                            maxValue: operationalConfig.oilCapacity,
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
                if workflow.isDispatchLocked && workflow.dispatchUploadVerified() {
                    CVROperationalActionButton(title: "Dispatch Confirmed", subtitle: "Flight Record Created", color: CVROperationalPalette.success) {}
                } else if workflow.isDispatchLocked && workflow.dispatchUploadInProgress() {
                    CVROperationalActionButton(title: "Uploading Dispatch", subtitle: "Waiting for server verification", color: CVROperationalPalette.secondaryBlue) {}
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
        if let oil = dispatch.effectiveStartingOilQuantity {
            repairOilPercent = min(max(oil, 0), operationalConfig.oilCapacity)
            repairHasOilSelection = true
        } else {
            repairOilPercent = operationalConfig.oilCapacity / 2
            repairHasOilSelection = false
        }
    }

    private func applyContinuityRepairAndRetryUpload() {
        guard workflow.updateActiveDispatchForUploadRepair({ dispatch in
            if repairHasOilSelection {
                dispatch.startingOilQuantity = repairOilPercent
                dispatch.startingOilUnit = operationalConfig.oilUnit
                dispatch.oilPercentage = operationalConfig.oilUnit == "%" ? Int(repairOilPercent.rounded()) : nil
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
        let fuel = Self.quantity(from: dispatch.fuelOnboard, unit: operationalConfig.fuelUnit)
            .map { "F: \(Self.quantityText($0)) \(operationalConfig.fuelUnit)" }
            ?? "F: ? \(operationalConfig.fuelUnit)"
        let oil = dispatch.effectiveStartingOilQuantity
            .map { "O: \(Self.quantityText($0)) \(dispatch.effectiveStartingOilUnit)" }
            ?? "O: ? \(operationalConfig.oilUnit)"
        return "\(fuel)\n\(oil)"
    }

    private var fuelTileColor: Color {
        guard let dispatch = workflow.state.activeDispatch, !dispatch.fuelOnboard.isEmpty, dispatch.effectiveStartingOilQuantity != nil else { return CVROperationalPalette.warning }
        if let quantity = Self.quantity(from: dispatch.fuelOnboard, unit: operationalConfig.fuelUnit),
           quantity <= operationalConfig.fuelCapacity * (3.0 / 13.0) {
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

    private var operationalConfig: AircraftOperationalConfig {
        settings.selectedAircraft?.operationalConfig ?? .safeDefaults
    }

    private static func quantity(from value: String, unit: String) -> Double? {
        let cleaned = value
            .replacingOccurrences(of: unit, with: "", options: .caseInsensitive)
            .replacingOccurrences(of: "USG", with: "", options: .caseInsensitive)
            .trimmingCharacters(in: .whitespacesAndNewlines)
        return Double(cleaned)
    }

    private static func quantityText(_ value: Double) -> String {
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
        if workflow.state.activeDispatch == nil {
            NoActiveFlightView(caption: "RECORDER")
        } else if !workflow.isDispatchVerified {
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
            StatusDashboardView(
                adminUnlocked: $adminUnlocked,
                showAdminUnlock: $showAdminUnlock,
                showsHeader: false
            )
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
                ScrollView {
                    VStack(spacing: metrics.spacing) {
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
                    .padding(.top, metrics.outerVerticalPadding)
                    .padding(.bottom, 132)
                    .frame(width: proxy.size.width, alignment: .top)
                }
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
            if workflow.state.activeDispatch == nil {
                NoActiveFlightView(caption: "IN-FLIGHT")
            } else if !workflow.isRecorderVerified {
                LockedOperationalView(title: "LOCKED", subtitle: "RECORDER VERIFICATION REQUIRED", iconName: "lock.fill", color: CVROperationalPalette.standby, showAdminUnlock: $showAdminUnlock)
            } else {
                TimelineView(.periodic(from: Date(), by: 1)) { timeline in
                    GeometryReader { proxy in
                        let metrics = CVROperationalMetrics(size: proxy.size)
                        ZStack {
                            CVROperationalPalette.background.ignoresSafeArea()
                            ScrollView {
                                VStack(spacing: metrics.spacing) {
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
                                .padding(.top, metrics.outerVerticalPadding)
                                .padding(.bottom, 132)
                                .frame(width: proxy.size.width, alignment: .top)
                            }
                        }
                    }
                }
            }
        }
        .sheet(isPresented: $isShowingShutdownVerification) {
            ShutdownVerificationView()
                .environmentObject(workflow)
                .environmentObject(settings)
                .environmentObject(uploadManager)
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
                CVROperationalWarningCard(title: hasShutdownVerificationEvent ? "FLIGHT ENDED" : "ON BLOCK RECORDED", message: hasShutdownVerificationEvent ? "Ending Hobbs and Tacho are stored with the audio-recorded flight. Garmin CSV can be attached later from Log." : "Enter Ending Hobbs and Tacho to close this flight.", iconName: "checkmark.seal.fill", color: CVROperationalPalette.success)
                if !hasShutdownVerificationEvent {
                    CVROperationalActionButton(title: "END FLIGHT", subtitle: "Enter Ending Hobbs and Tacho", color: CVROperationalPalette.secondaryBlue) {
                        isShowingShutdownVerification = true
                    }
                } else if workflow.canEditFlightClosure {
                    CVROperationalActionButton(title: "EDIT ENDING METERS", subtitle: "Fix Hobbs or Tacho before upload", color: CVROperationalPalette.warning) {
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
                CVROperationalActionButton(title: "END FLIGHT", subtitle: "Enter Ending Hobbs and Tacho", color: CVROperationalPalette.critical) {
                    UIImpactFeedbackGenerator(style: .heavy).impactOccurred()
                    workflow.recordEngineShutdownOnBlock(gpsSample: gps.latestSample)
                    isShowingShutdownVerification = true
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
    var repairExistingClosureUpload = false
    @State private var endingHobbs = ""
    @State private var endingTacho = ""
    @State private var fuelRemaining = 0.0
    @State private var oilRemaining = 0.0
    @State private var hasFuelSelection = false
    @State private var hasOilSelection = false
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

                    section("AUDIO FLIGHT CLOSURE") {
                        Text("Enter the final aircraft meters to end this dispatched audio-recorded flight. Garmin CSV data is optional now and can be attached later from the Log page.")
                            .font(.subheadline.weight(.semibold))
                            .foregroundStyle(CVROperationalPalette.textSecondary)
                    }
                }
                .padding(16)
            }
            .background(CVROperationalPalette.background.ignoresSafeArea())
            .navigationTitle(repairExistingClosureUpload ? "Fix Ending Meters" : "Shutdown Verification")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Close") { dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Save") {
                        if save() {
                            if workflow.finishEndedFlightLocally() {
                                uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                                dismiss()
                            }
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
        Double(endingHobbs) != nil && Double(endingTacho) != nil
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
        endingHobbs = flightRecord.endingHobbs.map { String(format: "%.1f", $0) } ?? workflow.state.activeDispatch?.startingHobbs.map { String(format: "%.1f", $0) } ?? ""
        endingTacho = flightRecord.endingTacho.map { String(format: "%.1f", $0) } ?? workflow.state.activeDispatch?.startingTacho.map { String(format: "%.1f", $0) } ?? ""
        if let fuel = flightRecord.fuelRemaining.flatMap({ Self.quantity(from: $0, unit: operationalConfig.fuelUnit) }) {
            fuelRemaining = min(max(fuel, 0), operationalConfig.fuelCapacity)
            hasFuelSelection = true
        } else if let startingFuel = workflow.state.activeDispatch?.fuelOnboard,
                  let fuel = Self.quantity(from: startingFuel, unit: operationalConfig.fuelUnit) {
            fuelRemaining = min(max(fuel, 0), operationalConfig.fuelCapacity)
            hasFuelSelection = true
        }
        if let oil = flightRecord.effectiveEndingOilQuantity {
            oilRemaining = min(max(oil, 0), operationalConfig.oilCapacity)
            hasOilSelection = true
        } else if let startingOil = workflow.state.activeDispatch?.effectiveStartingOilQuantity {
            oilRemaining = min(max(startingOil, 0), operationalConfig.oilCapacity)
            hasOilSelection = true
        }
        verifiedTakeoffs = flightRecord.verifiedTakeoffCount ?? workflow.operationCounts(for: flightRecord.id).displayTakeoffs
        verifiedLandings = flightRecord.verifiedLandingCount ?? workflow.operationCounts(for: flightRecord.id).displayLandings
        maintenanceRemark = flightRecord.maintenanceRemark ?? ""
    }

    private func save() -> Bool {
        let saved: Bool
        if repairExistingClosureUpload || workflow.closureUploadFailure() != nil {
            saved = workflow.saveFlightClosureValues(
                endingHobbs: Double(endingHobbs),
                endingTacho: Double(endingTacho),
                fuelRemaining: String(format: "%.1f", fuelRemaining),
                endingOilQuantity: oilRemaining,
                endingOilUnit: operationalConfig.oilUnit,
                verifiedTakeoffCount: verifiedTakeoffs,
                verifiedLandingCount: verifiedLandings,
                maintenanceRemark: maintenanceRemark,
                gpsSample: gps.latestSample,
                repairExistingClosureUpload: true
            )
        } else {
            saved = workflow.recordShutdownVerification(
                endingHobbs: Double(endingHobbs),
                endingTacho: Double(endingTacho),
                fuelRemaining: String(format: "%.1f", fuelRemaining),
                endingOilQuantity: oilRemaining,
                endingOilUnit: operationalConfig.oilUnit,
                verifiedTakeoffCount: verifiedTakeoffs,
                verifiedLandingCount: verifiedLandings,
                maintenanceRemark: maintenanceRemark,
                gpsSample: gps.latestSample
            )
        }
        return saved
    }

    private var operationalConfig: AircraftOperationalConfig {
        settings.selectedAircraft?.operationalConfig ?? .safeDefaults
    }

    private static func quantity(from value: String, unit: String) -> Double? {
        let cleaned = value
            .replacingOccurrences(of: unit, with: "", options: .caseInsensitive)
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
    @EnvironmentObject private var gps: GPSLocationManager
    @Binding var showAdminUnlock: Bool
    @State private var isShowingClosureEditor = false

    var body: some View {
        GeometryReader { proxy in
            let metrics = CVROperationalMetrics(size: proxy.size)
            ZStack {
                CVROperationalPalette.background.ignoresSafeArea()
                ScrollView {
                    VStack(spacing: metrics.spacing) {
                        CVROperationalStatusCard(title: "GARMIN RECOVERY", subtitle: "IMPORT AND UPLOAD QUEUE", iconName: "doc.badge.arrow.up", color: CVROperationalPalette.secondaryBlue, value: nil, caption: "GARMIN", metrics: metrics)
                        HStack(spacing: metrics.spacing) {
                            CVROperationalTile(title: "UPLOAD", iconName: "icloud.and.arrow.up.fill", value: uploadTileValue, color: uploadTileColor, metrics: metrics)
                            CVROperationalTile(title: "TRANSCRIPT", iconName: "text.bubble.fill", value: transcriptTileValue, color: transcriptTileColor, metrics: metrics)
                            CVROperationalTile(title: "REPLAY", iconName: "play.rectangle.fill", value: replayTileValue, color: replayTileColor, metrics: metrics)
                            CVROperationalTile(title: "SD CARD", iconName: "sdcard.fill", value: sdCardTileValue, color: sdCardTileColor, metrics: metrics)
                        }
                        if !sdRecovery.isScanning, !garminSync.isSyncing, let summary = sdRecovery.lastSummary {
                            CVROperationalWarningCard(
                                title: "SD CARD SCAN",
                                message: summary.message,
                                iconName: summary.matchedFlightRecord ? "checkmark.circle.fill" : "externaldrive.fill",
                                color: summary.matchedFlightRecord ? CVROperationalPalette.success : CVROperationalPalette.secondaryBlue
                            )
                        }
                        CVROperationalWarningCard(
                            title: garminWarningTitle,
                            message: garminWarningMessage,
                            iconName: garminWarningIcon,
                            color: garminWarningColor,
                            progress: activeRecoveryProgress
                        )
                        workflowUploadRepairActions
                        CVROperationalActionButton(title: uploadButtonTitle, subtitle: uploadButtonSubtitle, color: garminComponents.isEmpty ? CVROperationalPalette.textSecondary : CVROperationalPalette.secondaryBlue) {
                            if settings.isSimulationModeEnabled {
                                completeSimulationDemo(workflow: workflow, settings: settings, beacon: beacon)
                            } else if workflow.canEditFlightClosure {
                                isShowingClosureEditor = true
                            } else if workflow.canRepairFailedDispatchUpload {
                                workflow.selectTab(.dispatch)
                            } else if workflow.dispatchUploadFailure() != nil || !workflow.failedActiveUploadComponents().isEmpty {
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
                    .padding(.top, metrics.outerVerticalPadding)
                    .padding(.bottom, 132)
                    .frame(width: proxy.size.width, alignment: .top)
                }
            }
        }
        .sheet(isPresented: $isShowingClosureEditor) {
            ShutdownVerificationView(repairExistingClosureUpload: true)
                .environmentObject(workflow)
                .environmentObject(settings)
                .environmentObject(uploadManager)
                .environmentObject(gps)
                .presentationDetents([.large])
        }
        .task {
            if workflow.repairCompletedClosureUploadIfNeeded() {
                uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
            }
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

    private var workflowUploadRepairActions: some View {
        VStack(spacing: 8) {
            if workflow.canEditFlightClosure {
                CVROperationalActionButton(
                    title: "FIX ENDING METERS",
                    subtitle: "Enter Ending Hobbs and Tacho",
                    color: CVROperationalPalette.warning
                ) {
                    isShowingClosureEditor = true
                }
            }
            if workflow.canRepairFailedDispatchUpload {
                CVROperationalActionButton(
                    title: "FIX DISPATCH ON DISPATCH TAB",
                    subtitle: "Oil, fuel continuity, crew, and meters",
                    color: CVROperationalPalette.secondaryBlue
                ) {
                    workflow.selectTab(.dispatch)
                }
            }
            if failedWorkflowComponent?.componentType == "recorder_verification" {
                CVROperationalActionButton(
                    title: "FIX RECORDER ON RECORDER TAB",
                    subtitle: "Repeat recorder verification",
                    color: CVROperationalPalette.secondaryBlue
                ) {
                    workflow.selectTab(.recorder)
                }
            }
        }
    }

    private var sdCardTileValue: String {
        if sdRecovery.isScanning {
            return progressPercent(sdRecovery.scanProgress) ?? "Scanning"
        }
        if garminSync.isSyncing {
            return progressPercent(garminSync.syncProgress) ?? "Syncing"
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
        if garminSync.isSyncing {
            return progressPercent(garminSync.syncProgress) ?? "Syncing"
        }
        if garminComponents.isEmpty {
            if vaultFailedCount > 0 { return "Failed" }
            if vaultPendingCount > 0 { return "Queued" }
            if vaultSyncedCount > 0 { return "Synced" }
            return "Recovery"
        }
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

    private var activeRecoveryProgress: Double? {
        if sdRecovery.isScanning {
            return sdRecovery.scanProgress
        }
        if garminSync.isSyncing {
            return garminSync.syncProgress
        }
        return nil
    }

    private func progressPercent(_ progress: Double?) -> String? {
        guard let progress else { return nil }
        return "\(Int((progress * 100).rounded()))%"
    }

    private var uploadTileColor: Color {
        if vaultFailedCount > 0 {
            return CVROperationalPalette.critical
        }
        if garminSync.isSyncing || vaultPendingCount > 0 {
            return CVROperationalPalette.secondaryBlue
        }
        if vaultSyncedCount > 0 || !garminComponents.isEmpty {
            return CVROperationalPalette.success
        }
        return CVROperationalPalette.standby
    }

    private var vaultPendingCount: Int {
        garminVault.records.filter {
            $0.syncState == .pending || $0.syncState == .uploading
        }.count
    }

    private var vaultFailedCount: Int {
        garminVault.records.filter { $0.syncState == .failed }.count
    }

    private var vaultSyncedCount: Int {
        garminVault.records.filter {
            $0.syncState == .synced || $0.syncState == .duplicate
        }.count
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
            if workflow.canEditFlightClosure {
                return "Enter Ending Hobbs and Tacho"
            }
            if workflow.canRepairFailedDispatchUpload {
                return "Open Dispatch tab to fix and retry"
            }
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
        if failedWorkflowComponent != nil {
            if workflow.canEditFlightClosure {
                return "FIX ENDING METERS"
            }
            if workflow.canRepairFailedDispatchUpload {
                return "FIX DISPATCH"
            }
            return "RETRY FAILED ITEMS"
        }
        return "UPLOAD QUEUED ITEMS"
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
        if sdRecovery.isScanning {
            return "SCANNING SD CARD"
        }
        if garminSync.isSyncing {
            return "SYNCHRONIZING CARD FILES"
        }
        if garminComponents.isEmpty {
            if vaultFailedCount > 0 {
                return "CARD SYNC NEEDS RETRY"
            }
            if garminSync.isSyncing || vaultPendingCount > 0 {
                return "CARD FILES QUEUED FOR SYNC"
            }
            if vaultSyncedCount > 0 {
                return "CARD FILES SYNCHRONIZED"
            }
            return sdRecovery.cardAvailable ? "WAITING FOR MATCHING LOG" : "INSERT SD CARD READER"
        }
        if let failedWorkflowComponent {
            return switch failedWorkflowComponent.componentType {
            case "dispatch_metadata": "DISPATCH UPLOAD FAILED"
            case "flight_record_closure": "FLIGHT CLOSURE UPLOAD FAILED"
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
        if sdRecovery.isScanning {
            let total = sdRecovery.scanFilesTotal
            let processed = min(sdRecovery.scanFilesProcessed, total)
            let counts = total > 0
                ? " \(processed)/\(total) · \(sdRecovery.scanDataRichFound) data-rich · \(sdRecovery.scanGpsOnlySkipped) GPS-only."
                : ""
            return "\(sdRecovery.scanPhase).\(counts)"
        }
        if garminSync.isSyncing {
            let total = garminSync.syncFilesTotal
            let processed = min(garminSync.syncFilesProcessed, total)
            let file = garminSync.currentFileName.isEmpty ? "" : " \(garminSync.currentFileName)"
            let counts = total > 0 ? " \(processed)/\(total) complete." : ""
            return "\(garminSync.syncPhase).\(file)\(counts)"
        }
        if garminComponents.isEmpty {
            if vaultFailedCount > 0 {
                let detail = garminSync.lastError.trimmingCharacters(in: .whitespacesAndNewlines)
                return detail.isEmpty
                    ? "\(vaultFailedCount) data-rich card file(s) failed to synchronize and will retry automatically."
                    : "\(vaultFailedCount) data-rich card file(s) failed to synchronize: \(detail)"
            }
            if garminSync.isSyncing || vaultPendingCount > 0 {
                return "\(vaultPendingCount) data-rich card file(s) are queued. GPS-only files are excluded. \(vaultSyncedCount) already synchronized or confirmed on the server."
            }
            if vaultSyncedCount > 0 {
                return "\(vaultSyncedCount) data-rich card file(s) are synchronized or already existed on the server. GPS-only files were excluded."
            }
            if let summary = sdRecovery.lastSummary, !summary.cardAvailable {
                return "Insert the USB-C SD card reader. GPS-only logs are skipped automatically."
            }
            return sdRecovery.lastSummary?.message ?? "Waiting for a matching data-rich Garmin CSV on the SD card."
        }
        if let failedWorkflowComponent {
            if workflow.canEditFlightClosure {
                let detail = failedWorkflowComponent.lastError.nilIfEmpty ?? "Ending Hobbs and Ending Tacho are required."
                return "\(detail) Tap FIX ENDING METERS below."
            }
            return failedWorkflowComponent.lastError.nilIfEmpty ?? "Retry missing / failed components."
        }
        if allWorkflowComponentsVerified {
            return "Flight data is server verified. Direct Garmin G3X Bluetooth connection state is not exposed to this iOS app; avionics power is \(avionicsPowerLabel)."
        }
        return "Shared CSV is stored locally and queued. Direct Garmin G3X Bluetooth connection state is not exposed to this iOS app; avionics power is \(avionicsPowerLabel)."
    }

    private var garminWarningIcon: String {
        if sdRecovery.isScanning || garminSync.isSyncing {
            return "arrow.triangle.2.circlepath"
        }
        if failedWorkflowComponent != nil || vaultFailedCount > 0 {
            return "exclamationmark.triangle.fill"
        }
        if vaultPendingCount > 0 || garminSync.isSyncing {
            return "arrow.triangle.2.circlepath"
        }
        return garminComponents.isEmpty && vaultSyncedCount == 0 ? "externaldrive.fill" : "checkmark.seal.fill"
    }

    private var garminWarningColor: Color {
        if sdRecovery.isScanning || garminSync.isSyncing {
            return CVROperationalPalette.secondaryBlue
        }
        if failedWorkflowComponent != nil || vaultFailedCount > 0 {
            return CVROperationalPalette.critical
        }
        if vaultPendingCount > 0 || garminSync.isSyncing {
            return CVROperationalPalette.secondaryBlue
        }
        return garminComponents.isEmpty && vaultSyncedCount == 0
            ? CVROperationalPalette.secondaryBlue
            : CVROperationalPalette.success
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

private struct FlightLogView: View {
    @EnvironmentObject private var flightLogs: CVRFlightLogStore
    @EnvironmentObject private var workflow: CVRWorkflowStore
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var uploadManager: UploadManager
    @EnvironmentObject private var recordingStore: RecordingStore
    @State private var isShowingFileImporter = false
    @State private var directImportTarget: CVRFlightLogEntry?
    @State private var pinTarget: CVRFlightLogEntry?
    @State private var adjustmentTarget: CVRFlightLogEntry?
    @State private var adjustmentPIN = ""
    @State private var pinError = ""

    var body: some View {
        GeometryReader { proxy in
            let metrics = CVROperationalMetrics(size: proxy.size)
            ZStack {
                CVROperationalPalette.background.ignoresSafeArea()
                ScrollView {
                    VStack(spacing: metrics.spacing) {
                        CVROperationalStatusCard(
                            title: "AIRCRAFT FLIGHT LOG",
                            subtitle: "DISPATCHED FLIGHTS AND GARMIN STATUS",
                            iconName: "list.bullet.clipboard",
                            color: missingCount > 0 ? CVROperationalPalette.warning : CVROperationalPalette.success,
                            value: "\(displayEntries.count)",
                            caption: "LOGS",
                            metrics: metrics
                        )
                        HStack(spacing: metrics.spacing) {
                            CVROperationalTile(
                                title: "COMPLETE",
                                iconName: "checkmark.seal.fill",
                                value: "\(completeCount)",
                                color: CVROperationalPalette.success,
                                metrics: metrics
                            )
                            CVROperationalTile(
                                title: "CSV MISSING",
                                iconName: "exclamationmark.triangle.fill",
                                value: "\(missingCount)",
                                color: missingCount > 0 ? CVROperationalPalette.warning : CVROperationalPalette.standby,
                                metrics: metrics
                            )
                        }
                        Text("TIMES SHOWN IN CALIFORNIA LOCAL TIME (PT)")
                            .font(.caption2.weight(.bold))
                            .tracking(0.7)
                            .foregroundStyle(CVROperationalPalette.textSecondary)
                            .frame(maxWidth: .infinity, alignment: .leading)
                        if !flightLogs.lastError.isEmpty {
                            CVROperationalWarningCard(
                                title: "FLIGHT LOG",
                                message: flightLogs.lastError,
                                iconName: "exclamationmark.triangle.fill",
                                color: CVROperationalPalette.warning
                            )
                        }
                        if displayEntries.isEmpty && !flightLogs.isRefreshing {
                            CVROperationalWarningCard(
                                title: "NO DISPATCHED FLIGHTS",
                                message: "Completed Dispatch records for \(settings.selectedAircraft?.registration ?? "this aircraft") will appear here.",
                                iconName: "clock.arrow.circlepath",
                                color: CVROperationalPalette.secondaryBlue
                            )
                        } else {
                            VStack(spacing: 10) {
                                ForEach(displayEntries) { entry in
                                    flightLogRow(entry)
                                }
                            }
                        }
                        if activeFlightIsClosed {
                            CVROperationalActionButton(
                                title: activeWorkflowVerified ? "NEXT FLIGHT" : "UPLOAD FLIGHT RECORD",
                                subtitle: activeWorkflowVerified
                                    ? "Archive this flight; Garmin CSV may still be added later"
                                    : "Send Dispatch, audio events, and ending meters",
                                color: activeWorkflowVerified
                                    ? CVROperationalPalette.success
                                    : CVROperationalPalette.secondaryBlue
                            ) {
                                if activeWorkflowVerified {
                                    workflow.resetForNextFlightIfComplete()
                                    Task { await flightLogs.refresh(settings: settings) }
                                } else {
                                    workflow.requeueFailedUploads()
                                    uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                                }
                            }
                        }
                        CVROperationalActionButton(
                            title: "IMPORT GARMIN CSV",
                            subtitle: "Choose a CSV, then assign it to a dispatched flight",
                            color: CVROperationalPalette.secondaryBlue
                        ) {
                            directImportTarget = nil
                            isShowingFileImporter = true
                        }
                        CVROperationalActionButton(
                            title: flightLogs.isRefreshing ? "REFRESHING LOG" : "REFRESH FLIGHT LOG",
                            subtitle: settings.selectedAircraft?.registration ?? "Enrolled aircraft",
                            color: CVROperationalPalette.standby
                        ) {
                            Task { await flightLogs.refresh(settings: settings) }
                        }
                    }
                    .padding(.horizontal, metrics.outerHorizontalPadding)
                    .padding(.top, metrics.outerVerticalPadding)
                    .padding(.bottom, 132)
                    .frame(width: proxy.size.width, alignment: .top)
                }
                .refreshable {
                    await flightLogs.refresh(settings: settings)
                }
                if flightLogs.isUploading || flightLogs.isAdjusting {
                    uploadOverlay
                }
            }
        }
        .task {
            await flightLogs.refresh(settings: settings)
        }
        .fileImporter(
            isPresented: $isShowingFileImporter,
            allowedContentTypes: [.commaSeparatedText],
            allowsMultipleSelection: false
        ) { result in
            switch result {
            case .success(let urls):
                guard let url = urls.first, flightLogs.stageGarminCSV(from: url) else { return }
                if let target = directImportTarget {
                    directImportTarget = nil
                    Task {
                        await flightLogs.uploadPendingGarminCSV(
                            to: target,
                            settings: settings,
                            uploadManager: uploadManager
                        )
                    }
                }
            case .failure(let error):
                directImportTarget = nil
                _ = error
            }
        }
        .sheet(
            isPresented: Binding(
                get: { flightLogs.pendingGarminCSV != nil && directImportTarget == nil && !flightLogs.isUploading },
                set: { _ in }
            )
        ) {
            garminAssignmentSheet
                .interactiveDismissDisabled()
        }
        .sheet(item: $pinTarget) { entry in
            flightLogPINSheet(entry)
        }
        .sheet(item: $adjustmentTarget) { entry in
            FlightLogAdjustmentView(entry: entry)
                .environmentObject(flightLogs)
                .environmentObject(settings)
                .presentationDetents([.medium, .large])
        }
    }

    private func flightLogRow(_ entry: CVRFlightLogEntry) -> some View {
        let overall = overallLogStatus(entry)
        return VStack(alignment: .leading, spacing: 10) {
            HStack {
                Text(displayDate(entry.scheduledDate))
                    .font(.headline.weight(.bold))
                    .foregroundStyle(.white)
                Spacer()
                Label(
                    overall.title,
                    systemImage: overall.icon
                )
                .font(.caption2.weight(.bold))
                .foregroundStyle(overall.color)
            }
            Text((entry.crewNames ?? []).isEmpty ? "Crew not recorded" : (entry.crewNames ?? []).joined(separator: " · "))
                .font(.caption.weight(.semibold))
                .foregroundStyle(CVROperationalPalette.textSecondary)
            HStack(spacing: 8) {
                logValue("DEPARTURE", value: airport(entry.departureAirport), detail: displayTime(entry.departureTime))
                Image(systemName: "arrow.right")
                    .font(.caption.weight(.bold))
                    .foregroundStyle(CVROperationalPalette.textSecondary)
                logValue("ARRIVAL", value: airport(entry.arrivalAirport), detail: displayTime(entry.arrivalTime))
                logValue("HOBBS", value: hobbs(entry.totalHobbsTime), detail: "TOTAL")
            }
            HStack(spacing: 8) {
                logStatusValue(
                    "SERVER",
                    value: uploadStatusText(entry),
                    color: uploadStatusColor(entry)
                )
                logStatusValue(
                    "TRANSCRIPT",
                    value: transcriptStatusText(entry),
                    color: transcriptStatusColor(entry)
                )
                logStatusValue(
                    "GARMIN CSV",
                    value: entry.hasGarminCSV ? "UPLOADED" : "MISSING",
                    color: entry.hasGarminCSV ? CVROperationalPalette.success : CVROperationalPalette.warning
                )
            }
            HStack(spacing: 8) {
                logStatusValue(
                    "TAKEOFFS",
                    value: "\(entry.takeoffCount ?? 0)",
                    color: CVROperationalPalette.secondaryBlue
                )
                logStatusValue(
                    "LANDINGS",
                    value: "\(entry.landingCount ?? 0)",
                    color: CVROperationalPalette.secondaryBlue
                )
            }
            if let failure = logFailureMessage(entry) {
                Text(failure)
                    .font(.caption2.weight(.semibold))
                    .foregroundStyle(CVROperationalPalette.critical)
                    .lineLimit(3)
            }
            HStack(spacing: 8) {
                if !entry.hasGarminCSV {
                    Button {
                        directImportTarget = entry
                        isShowingFileImporter = true
                    } label: {
                        Label("ADD CSV", systemImage: "paperclip")
                    }
                    .foregroundStyle(CVROperationalPalette.warning)
                }
                if logNeedsRetry(entry) {
                    Button {
                        retryLogUpload(entry)
                    } label: {
                        Label("RE-UPLOAD", systemImage: "arrow.clockwise.icloud.fill")
                    }
                    .foregroundStyle(CVROperationalPalette.critical)
                }
                Spacer()
                Button {
                    adjustmentPIN = ""
                    pinError = ""
                    pinTarget = entry
                } label: {
                    Label("ADJUST", systemImage: "lock.fill")
                }
                .foregroundStyle(CVROperationalPalette.secondaryBlue)
            }
            .font(.caption.weight(.bold))
            .buttonStyle(.plain)
        }
        .padding(14)
        .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 16))
        .overlay(
            RoundedRectangle(cornerRadius: 16)
                .stroke(
                    entry.hasGarminCSV ? CVROperationalPalette.cardBorder : CVROperationalPalette.warning.opacity(0.55),
                    lineWidth: 1
                )
        )
    }

    private func logValue(_ title: String, value: String, detail: String) -> some View {
        VStack(alignment: .leading, spacing: 3) {
            Text(title)
                .font(.system(size: 9, weight: .bold))
                .tracking(0.7)
                .foregroundStyle(CVROperationalPalette.textSecondary)
            Text(value)
                .font(.subheadline.weight(.bold))
                .foregroundStyle(.white)
            Text(detail)
                .font(.caption2.weight(.semibold).monospacedDigit())
                .foregroundStyle(CVROperationalPalette.secondaryBlue)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }

    private func logStatusValue(_ title: String, value: String, color: Color) -> some View {
        VStack(alignment: .leading, spacing: 3) {
            Text(title)
                .font(.system(size: 9, weight: .bold))
                .tracking(0.7)
                .foregroundStyle(CVROperationalPalette.textSecondary)
            Text(value)
                .font(.caption.weight(.bold).monospacedDigit())
                .foregroundStyle(color)
                .lineLimit(1)
                .minimumScaleFactor(0.7)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }

    private func uploadStatusText(_ entry: CVRFlightLogEntry) -> String {
        switch entry.serverUploadStatus?.lowercased() {
        case "complete": return "UPLOADED"
        case "failed": return "FAILED"
        case "uploading", "partial", "pending":
            return "\(entry.serverUploadProgress ?? 0)%"
        default: return "\(entry.serverUploadProgress ?? 0)%"
        }
    }

    private func uploadStatusColor(_ entry: CVRFlightLogEntry) -> Color {
        switch entry.serverUploadStatus?.lowercased() {
        case "complete": return CVROperationalPalette.success
        case "failed": return CVROperationalPalette.critical
        default: return CVROperationalPalette.secondaryBlue
        }
    }

    private func transcriptStatusText(_ entry: CVRFlightLogEntry) -> String {
        switch entry.transcriptStatus?.lowercased() {
        case "ready": return "READY"
        case "failed": return "FAILED"
        case "transcribing", "queued": return "\(entry.transcriptProgress ?? 0)%"
        default: return "PENDING"
        }
    }

    private func transcriptStatusColor(_ entry: CVRFlightLogEntry) -> Color {
        switch entry.transcriptStatus?.lowercased() {
        case "ready": return CVROperationalPalette.success
        case "failed": return CVROperationalPalette.critical
        case "transcribing", "queued": return CVROperationalPalette.secondaryBlue
        default: return CVROperationalPalette.standby
        }
    }

    private func overallLogStatus(_ entry: CVRFlightLogEntry) -> (title: String, icon: String, color: Color) {
        if entry.serverUploadStatus?.lowercased() == "failed"
            || entry.transcriptStatus?.lowercased() == "failed" {
            return ("UPLOAD FAILED", "exclamationmark.octagon.fill", CVROperationalPalette.critical)
        }
        if entry.serverUploadStatus?.lowercased() == "complete",
           entry.transcriptStatus?.lowercased() == "ready",
           entry.hasGarminCSV {
            return ("COMPLETE", "checkmark.seal.fill", CVROperationalPalette.success)
        }
        if entry.serverUploadStatus?.lowercased() == "complete" {
            return ("SERVER UPLOADED", "checkmark.icloud.fill", CVROperationalPalette.secondaryBlue)
        }
        return ("INCOMPLETE", "exclamationmark.triangle.fill", CVROperationalPalette.warning)
    }

    private func logNeedsRetry(_ entry: CVRFlightLogEntry) -> Bool {
        entry.serverUploadStatus?.lowercased() == "failed"
            || entry.audioUploadStatus?.lowercased() == "failed"
            || entry.transcriptStatus?.lowercased() == "failed"
    }

    private func logFailureMessage(_ entry: CVRFlightLogEntry) -> String? {
        let message = (entry.transcriptError ?? entry.serverUploadError ?? "")
            .trimmingCharacters(in: .whitespacesAndNewlines)
        return message.isEmpty ? nil : message
    }

    private func retryLogUpload(_ entry: CVRFlightLogEntry) {
        workflow.requeueFailedUploads(forFlightRecordID: entry.flightRecordID)
        uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
        for recording in linkedRecordings(forFlightRecordID: entry.flightRecordID) {
            let needsFlightRelink = recording.flightSessionID != entry.flightRecordID
            recordingStore.update(recording.id) {
                $0.flightSessionID = entry.flightRecordID
                $0.nextUploadRetryAt = nil
                $0.uploadRetryCount = nil
                if $0.uploadStatus == .failed {
                    $0.lastError = ""
                }
            }
            if needsFlightRelink || recording.uploadStatus != .uploaded {
                uploadManager.upload(recordingID: recording.id, store: recordingStore, settings: settings)
            }
        }
        Task {
            try? await Task.sleep(for: .seconds(4))
            if entry.transcriptStatus?.lowercased() == "failed" {
                await flightLogs.retryServerProcessing(entry, settings: settings)
            } else {
                await flightLogs.refresh(settings: settings)
            }
            try? await Task.sleep(for: .seconds(8))
            await flightLogs.refresh(settings: settings)
        }
    }

    private func linkedRecordings(forFlightRecordID flightRecordID: String) -> [Recording] {
        let identifiers = workflow.recordingIdentifiers(forFlightRecordID: flightRecordID)
        return recordingStore.recordings.filter {
            $0.flightSessionID == flightRecordID
                || identifiers.contains($0.id)
                || identifiers.contains($0.flightSessionID)
        }
    }

    private func flightLogPINSheet(_ entry: CVRFlightLogEntry) -> some View {
        NavigationStack {
            ZStack {
                CVROperationalPalette.background.ignoresSafeArea()
                VStack(spacing: 18) {
                    Image(systemName: "lock.shield.fill")
                        .font(.system(size: 42, weight: .bold))
                        .foregroundStyle(CVROperationalPalette.secondaryBlue)
                    Text("ADMIN AUTHORIZATION")
                        .font(.headline.weight(.bold))
                        .foregroundStyle(.white)
                    Text("Enter the CVR Unit Admin PIN to adjust Hobbs, Tacho, or fuel.")
                        .font(.subheadline.weight(.semibold))
                        .foregroundStyle(CVROperationalPalette.textSecondary)
                        .multilineTextAlignment(.center)
                    SecureField("Admin PIN", text: $adjustmentPIN)
                        .keyboardType(.numberPad)
                        .textContentType(.password)
                        .font(.title3.weight(.bold))
                        .foregroundStyle(.white)
                        .padding(12)
                        .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 12))
                        .overlay(RoundedRectangle(cornerRadius: 12).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
                    if !pinError.isEmpty {
                        Text(pinError)
                            .font(.caption.weight(.bold))
                            .foregroundStyle(CVROperationalPalette.critical)
                    }
                    CVROperationalActionButton(
                        title: "UNLOCK ADJUSTMENT",
                        subtitle: displayDate(entry.scheduledDate),
                        color: CVROperationalPalette.secondaryBlue
                    ) {
                        guard adjustmentPIN == settings.adminPIN else {
                            pinError = "Incorrect Admin PIN"
                            return
                        }
                        pinTarget = nil
                        DispatchQueue.main.asyncAfter(deadline: .now() + 0.25) {
                            adjustmentTarget = entry
                        }
                    }
                }
                .padding(24)
            }
            .navigationTitle("Protected Adjustment")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { pinTarget = nil }
                }
            }
        }
        .preferredColorScheme(.dark)
    }

    private var garminAssignmentSheet: some View {
        NavigationStack {
            ZStack {
                CVROperationalPalette.background.ignoresSafeArea()
                ScrollView {
                    VStack(alignment: .leading, spacing: 12) {
                        if let file = flightLogs.pendingGarminCSV {
                            Text(file.originalFilename)
                                .font(.subheadline.weight(.bold))
                                .foregroundStyle(CVROperationalPalette.secondaryBlue)
                        }
                        Text("Select the dispatched flight that belongs to this Garmin CSV.")
                            .font(.subheadline.weight(.semibold))
                            .foregroundStyle(CVROperationalPalette.textSecondary)
                        ForEach(displayEntries.filter { !$0.hasGarminCSV }) { entry in
                            Button {
                                Task {
                                    await flightLogs.uploadPendingGarminCSV(
                                        to: entry,
                                        settings: settings,
                                        uploadManager: uploadManager
                                    )
                                }
                            } label: {
                                HStack {
                                    VStack(alignment: .leading, spacing: 4) {
                                        Text(displayDate(entry.scheduledDate))
                                            .font(.headline.weight(.bold))
                                        Text("\(airport(entry.departureAirport)) \(displayTime(entry.departureTime)) → \(airport(entry.arrivalAirport)) \(displayTime(entry.arrivalTime))")
                                            .font(.caption.weight(.semibold))
                                            .foregroundStyle(CVROperationalPalette.textSecondary)
                                    }
                                    Spacer()
                                    Image(systemName: "paperclip")
                                        .foregroundStyle(CVROperationalPalette.secondaryBlue)
                                }
                                .foregroundStyle(.white)
                                .padding(14)
                                .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 14))
                                .overlay(RoundedRectangle(cornerRadius: 14).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
                            }
                            .buttonStyle(.plain)
                        }
                    }
                    .padding(16)
                }
            }
            .navigationTitle("Assign Garmin CSV")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { flightLogs.cancelPendingGarminCSV() }
                }
            }
        }
        .preferredColorScheme(.dark)
    }

    private var uploadOverlay: some View {
        ZStack {
            Color.black.opacity(0.72).ignoresSafeArea()
            VStack(spacing: 14) {
                ProgressView(value: flightLogs.isAdjusting ? nil : flightLogs.uploadProgress)
                    .tint(CVROperationalPalette.secondaryBlue)
                    .frame(width: 220)
                Text(flightLogs.isAdjusting ? "SAVING LOG ADJUSTMENT" : "ATTACHING GARMIN CSV")
                    .font(.headline.weight(.bold))
                    .foregroundStyle(.white)
                if !flightLogs.isAdjusting {
                    Text("\(Int((flightLogs.uploadProgress * 100).rounded()))%")
                        .font(.title2.weight(.bold).monospacedDigit())
                        .foregroundStyle(CVROperationalPalette.secondaryBlue)
                }
            }
            .padding(24)
            .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 18))
            .overlay(RoundedRectangle(cornerRadius: 18).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
        }
    }

    private var displayEntries: [CVRFlightLogEntry] {
        var byIdentity: [String: CVRFlightLogEntry] = [:]
        for remote in flightLogs.entries {
            let identity = logIdentity(remote)
            if let existing = byIdentity[identity] {
                byIdentity[identity] = mergeLogEntries(existing, remote)
            } else {
                byIdentity[identity] = remote
            }
        }
        let selectedTail = normalizedTail(settings.selectedAircraft?.registration ?? "")
        for archive in workflow.archives where selectedTail.isEmpty
            || normalizedTail(archive.dispatch.tailNumber) == selectedTail {
            let local = localLogEntry(
                dispatch: archive.dispatch,
                flightRecord: archive.flightRecord,
                events: archive.flightEvents,
                components: archive.uploadComponents
            )
            let identity = logIdentity(local)
            if let existing = byIdentity[identity] {
                byIdentity[identity] = mergeLogEntries(existing, local)
            } else {
                byIdentity[identity] = local
            }
        }
        if let dispatch = workflow.state.activeDispatch,
           let flightRecord = workflow.state.activeFlightRecord {
            let local = localLogEntry(
                dispatch: dispatch,
                flightRecord: flightRecord,
                events: workflow.state.flightEvents,
                components: workflow.state.uploadComponents
            )
            let identity = logIdentity(local)
            if let existing = byIdentity[identity] {
                byIdentity[identity] = mergeLogEntries(existing, local)
            } else {
                byIdentity[identity] = local
            }
        }
        for identity in Array(byIdentity.keys) {
            guard let entry = byIdentity[identity],
                  flightLogs.hasLocallyAttachedGarminCSV(flightRecordID: entry.flightRecordID) else {
                continue
            }
            byIdentity[identity]?.hasGarminCSV = true
        }
        return byIdentity.values.sorted {
            if $0.scheduledDate == $1.scheduledDate {
                return ($0.departureTime ?? "") > ($1.departureTime ?? "")
            }
            return $0.scheduledDate > $1.scheduledDate
        }
    }

    private func logIdentity(_ entry: CVRFlightLogEntry) -> String {
        if let schedulerRecordID = entry.schedulerRecordID?.trimmingCharacters(in: .whitespacesAndNewlines),
           !schedulerRecordID.isEmpty {
            return "schedule:\(schedulerRecordID.lowercased())"
        }
        let dispatchUUID = entry.dispatchUUID.trimmingCharacters(in: .whitespacesAndNewlines)
        if !dispatchUUID.isEmpty {
            return "dispatch:\(dispatchUUID.lowercased())"
        }
        return "flight:\(entry.flightRecordID.lowercased())"
    }

    private func mergeLogEntries(
        _ existing: CVRFlightLogEntry,
        _ candidate: CVRFlightLogEntry
    ) -> CVRFlightLogEntry {
        var merged = candidate.hasGarminCSV && !existing.hasGarminCSV ? candidate : existing
        merged.hasGarminCSV = existing.hasGarminCSV || candidate.hasGarminCSV
        if merged.schedulerRecordID?.isEmpty != false {
            merged.schedulerRecordID = existing.schedulerRecordID ?? candidate.schedulerRecordID
        }
        if (merged.crewNames ?? []).isEmpty {
            merged.crewNames = (existing.crewNames ?? []).isEmpty ? candidate.crewNames : existing.crewNames
        }
        if merged.departureAirport.isEmpty {
            merged.departureAirport = existing.departureAirport.isEmpty
                ? candidate.departureAirport
                : existing.departureAirport
        }
        if merged.arrivalAirport.isEmpty {
            merged.arrivalAirport = existing.arrivalAirport.isEmpty
                ? candidate.arrivalAirport
                : existing.arrivalAirport
        }
        merged.serverUploadStatus = preferredStatus(
            existing.serverUploadStatus,
            candidate.serverUploadStatus,
            success: "complete"
        )
        merged.serverUploadProgress = max(
            existing.serverUploadProgress ?? 0,
            candidate.serverUploadProgress ?? 0
        )
        merged.serverUploadError = merged.serverUploadStatus?.lowercased() == "failed"
            ? (candidate.serverUploadError ?? existing.serverUploadError)
            : nil
        merged.audioUploadStatus = preferredStatus(
            existing.audioUploadStatus,
            candidate.audioUploadStatus,
            success: "uploaded"
        )
        merged.transcriptStatus = preferredStatus(
            existing.transcriptStatus,
            candidate.transcriptStatus,
            success: "ready"
        )
        merged.transcriptProgress = max(
            existing.transcriptProgress ?? 0,
            candidate.transcriptProgress ?? 0
        )
        merged.transcriptError = merged.transcriptStatus?.lowercased() == "failed"
            ? (candidate.transcriptError ?? existing.transcriptError)
            : nil
        merged.takeoffCount = max(existing.takeoffCount ?? 0, candidate.takeoffCount ?? 0)
        merged.landingCount = max(existing.landingCount ?? 0, candidate.landingCount ?? 0)
        merged.serverComponentCount = max(
            existing.serverComponentCount ?? 0,
            candidate.serverComponentCount ?? 0
        )
        return merged
    }

    private func preferredStatus(_ first: String?, _ second: String?, success: String) -> String? {
        let values = [first, second].compactMap { $0?.lowercased() }
        if values.contains(success) { return success }
        if values.contains("uploading") { return "uploading" }
        if values.contains("transcribing") { return "transcribing" }
        if values.contains("queued") { return "queued" }
        if values.contains("pending") { return "pending" }
        if values.contains("partial") { return "partial" }
        if values.contains("failed") { return "failed" }
        return values.first
    }

    private func localLogEntry(
        dispatch: CVRDispatchRecord,
        flightRecord: CVRIncompleteFlightRecord,
        events: [CVRFlightEventRecord],
        components: [CVRUploadComponentRecord]
    ) -> CVRFlightLogEntry {
        let flightEvents = events.filter { $0.flightRecordID == flightRecord.id }
        let departure = flightEvents
            .filter { $0.eventType == "engine_start_off_block" }
            .min { $0.timestampLocal < $1.timestampLocal }
        let arrival = flightEvents
            .filter { $0.eventType == "engine_shutdown_on_block" }
            .max { $0.timestampLocal < $1.timestampLocal }
        let totalHobbs: Double? = if let start = dispatch.startingHobbs,
                                     let end = flightRecord.endingHobbs {
            end - start
        } else {
            nil
        }
        let calculatedArrival: Date? = if let departure,
                                          let totalHobbs {
            departure.timestampLocal.addingTimeInterval(totalHobbs * 3600)
        } else {
            arrival?.timestampLocal
        }
        let relevantComponents = components.filter { $0.flightRecordID == flightRecord.id }
        let failedComponent = relevantComponents.first {
            $0.state == .failed || $0.state == .needsUserAction
        }
        let verifiedComponentCount = relevantComponents.filter { $0.state == .serverVerified }.count
        let componentProgress = relevantComponents.isEmpty
            ? 0
            : Int((relevantComponents.reduce(0.0) { partial, component in
                if component.state == .serverVerified { return partial + 1 }
                return partial + min(max(component.progress ?? 0, 0), 1)
            } / Double(relevantComponents.count) * 100).rounded())
        let workflowUploadStatus = failedComponent != nil
            ? "failed"
            : (!relevantComponents.isEmpty && verifiedComponentCount == relevantComponents.count
                ? "complete"
                : (relevantComponents.contains { $0.state == .uploading } ? "uploading" : "pending"))
        let linkedRecordings = linkedRecordings(forFlightRecordID: flightRecord.id)
        let audioUploadStatus = linkedRecordings.contains { $0.uploadStatus == .failed }
            ? "failed"
            : (!linkedRecordings.isEmpty && linkedRecordings.allSatisfy { $0.uploadStatus == .uploaded }
                ? "uploaded"
                : (linkedRecordings.contains { $0.uploadStatus == .uploading } ? "uploading" : "pending"))
        let transcriptStatus = linkedRecordings.contains { $0.transcriptStatus == .failed }
            ? "failed"
            : (!linkedRecordings.isEmpty && linkedRecordings.allSatisfy { $0.transcriptStatus == .ready }
                ? "ready"
                : (linkedRecordings.contains { $0.transcriptStatus == .transcribing } ? "transcribing" : "pending"))
        let transcriptProgress = linkedRecordings.isEmpty
            ? 0
            : linkedRecordings.map(\.transcriptProgress).min() ?? 0
        let takeoffCount = flightRecord.verifiedTakeoffCount
            ?? flightEvents.filter {
                $0.eventType == "gps_takeoff_provisional" || $0.eventType == "manual_takeoff_adjustment"
            }.count
        let landingCount = flightRecord.verifiedLandingCount
            ?? flightEvents.filter {
                $0.eventType == "gps_landing_provisional" || $0.eventType == "manual_landing_adjustment"
            }.count
        return CVRFlightLogEntry(
            flightRecordID: flightRecord.id,
            dispatchUUID: dispatch.id,
            schedulerRecordID: dispatch.schedulerRecordID,
            aircraftRegistration: dispatch.tailNumber,
            scheduledDate: Self.logDateFormatter.string(from: dispatch.scheduledDate),
            crewNames: dispatch.crew.map(\.personName),
            departureAirport: dispatch.plannedDepartureAirport,
            departureTime: departure.map { ISO8601DateFormatter().string(from: $0.timestampLocal) },
            arrivalAirport: dispatch.plannedDestinationAirport,
            arrivalTime: calculatedArrival.map { ISO8601DateFormatter().string(from: $0) },
            startingHobbs: dispatch.startingHobbs,
            startingTacho: dispatch.startingTacho,
            endingHobbs: flightRecord.endingHobbs,
            endingTacho: flightRecord.endingTacho,
            fuelRemaining: flightRecord.fuelRemaining,
            endingOilPercentage: flightRecord.endingOilPercentage,
            endingOilQuantity: flightRecord.endingOilQuantity,
            endingOilUnit: flightRecord.endingOilUnit,
            totalHobbsTime: totalHobbs,
            hasGarminCSV: components.contains {
                $0.flightRecordID == flightRecord.id
                    && $0.componentType == "garmin_csv"
                    && $0.state == .serverVerified
            },
            serverUploadStatus: workflowUploadStatus,
            serverUploadProgress: componentProgress,
            serverUploadError: failedComponent?.lastError,
            audioUploadStatus: audioUploadStatus,
            transcriptStatus: transcriptStatus,
            transcriptProgress: transcriptProgress,
            transcriptError: linkedRecordings.first(where: { $0.transcriptStatus == .failed })?.lastError,
            takeoffCount: takeoffCount,
            landingCount: landingCount,
            serverComponentCount: verifiedComponentCount
        )
    }

    private var completeCount: Int {
        displayEntries.filter(\.hasGarminCSV).count
    }

    private var activeFlightIsClosed: Bool {
        guard let flightRecord = workflow.state.activeFlightRecord else { return false }
        return workflow.flightClosureIsComplete(flightRecord)
    }

    private var activeWorkflowVerified: Bool {
        guard let flightRecordID = workflow.state.activeFlightRecord?.id else { return false }
        let components = workflow.state.uploadComponents.filter { $0.flightRecordID == flightRecordID }
        return !components.isEmpty && components.allSatisfy { $0.state == .serverVerified }
    }

    private var missingCount: Int {
        displayEntries.count - completeCount
    }

    private func displayDate(_ value: String) -> String {
        guard let date = Self.inputDateFormatter.date(from: value) else { return value }
        return Self.outputDateFormatter.string(from: date)
    }

    private func displayTime(_ value: String?) -> String {
        guard let value, !value.isEmpty else { return "—" }
        let hasExplicitTimeZone = value.hasSuffix("Z")
            || value.range(of: #"[+-]\d{2}:\d{2}$"#, options: .regularExpression) != nil
        if hasExplicitTimeZone,
           let date = Self.isoTimestampFormatter.date(from: value) {
            return Self.californiaTimeFormatter.string(from: date)
        }
        if let localDate = Self.californiaLocalTimestampFormatter.date(from: value) {
            return Self.californiaTimeFormatter.string(from: localDate)
        }
        return value
    }

    private func airport(_ value: String) -> String {
        value.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty ? "—" : value
    }

    private func normalizedTail(_ value: String) -> String {
        value.uppercased().filter { $0.isLetter || $0.isNumber }
    }

    private func hobbs(_ value: Double?) -> String {
        value.map { String(format: "%.1f", $0) } ?? "—"
    }

    private static let inputDateFormatter: DateFormatter = {
        let formatter = DateFormatter()
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.timeZone = operationalTimeZone
        formatter.dateFormat = "yyyy-MM-dd"
        return formatter
    }()

    private static let operationalTimeZone =
        TimeZone(identifier: "America/Los_Angeles") ?? .current

    private static let isoTimestampFormatter: ISO8601DateFormatter = {
        let formatter = ISO8601DateFormatter()
        formatter.formatOptions = [.withInternetDateTime]
        return formatter
    }()

    private static let californiaLocalTimestampFormatter: DateFormatter = {
        let formatter = DateFormatter()
        formatter.calendar = Calendar(identifier: .gregorian)
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.timeZone = operationalTimeZone
        formatter.dateFormat = "yyyy-MM-dd'T'HH:mm:ss"
        return formatter
    }()

    private static let californiaTimeFormatter: DateFormatter = {
        let formatter = DateFormatter()
        formatter.calendar = Calendar(identifier: .gregorian)
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.timeZone = operationalTimeZone
        formatter.dateFormat = "HH:mm"
        return formatter
    }()

    private static let logDateFormatter: DateFormatter = {
        let formatter = DateFormatter()
        formatter.calendar = Calendar(identifier: .gregorian)
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.timeZone = operationalTimeZone
        formatter.dateFormat = "yyyy-MM-dd"
        return formatter
    }()

    private static let outputDateFormatter: DateFormatter = {
        let formatter = DateFormatter()
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.timeZone = operationalTimeZone
        formatter.dateFormat = "EEE, MMM d, yyyy"
        return formatter
    }()
}

private struct FlightLogAdjustmentView: View {
    @EnvironmentObject private var flightLogs: CVRFlightLogStore
    @EnvironmentObject private var settings: SettingsStore
    @Environment(\.dismiss) private var dismiss
    let entry: CVRFlightLogEntry
    @State private var departureAirport: String
    @State private var arrivalAirport: String
    @State private var crewNames: String
    @State private var startingHobbs: String
    @State private var startingTacho: String
    @State private var endingHobbs: String
    @State private var endingTacho: String
    @State private var fuelRemaining: String

    init(entry: CVRFlightLogEntry) {
        self.entry = entry
        _departureAirport = State(initialValue: entry.departureAirport)
        _arrivalAirport = State(initialValue: entry.arrivalAirport)
        _crewNames = State(initialValue: (entry.crewNames ?? []).joined(separator: ", "))
        _startingHobbs = State(initialValue: entry.startingHobbs.map { String(format: "%.1f", $0) } ?? "")
        _startingTacho = State(initialValue: entry.startingTacho.map { String(format: "%.1f", $0) } ?? "")
        _endingHobbs = State(initialValue: entry.endingHobbs.map { String(format: "%.1f", $0) } ?? "")
        _endingTacho = State(initialValue: entry.endingTacho.map { String(format: "%.1f", $0) } ?? "")
        _fuelRemaining = State(initialValue: entry.fuelRemaining ?? "")
    }

    var body: some View {
        NavigationStack {
            ZStack {
                CVROperationalPalette.background.ignoresSafeArea()
                ScrollView {
                    VStack(alignment: .leading, spacing: 14) {
                        CVROperationalWarningCard(
                            title: "ADMINISTRATIVE ADJUSTMENT",
                            message: "Correct the Dispatch start baseline or Flight Closure values. This creates an append-only correction; the original evidence remains preserved.",
                            iconName: "lock.shield.fill",
                            color: CVROperationalPalette.warning
                        )
                        adjustmentField(
                            "DEPARTURE AIRPORT",
                            value: $departureAirport,
                            baseline: nil,
                            keyboard: .default
                        )
                        adjustmentField(
                            "ARRIVAL AIRPORT",
                            value: $arrivalAirport,
                            baseline: nil,
                            keyboard: .default
                        )
                        adjustmentField(
                            "CREW NAMES",
                            value: $crewNames,
                            baseline: nil,
                            keyboard: .default
                        )
                        adjustmentField(
                            "STARTING HOBBS",
                            value: $startingHobbs,
                            baseline: nil
                        )
                        adjustmentField(
                            "STARTING TACHO",
                            value: $startingTacho,
                            baseline: nil
                        )
                        adjustmentField(
                            "ENDING HOBBS",
                            value: $endingHobbs,
                            baseline: nil
                        )
                        adjustmentField(
                            "ENDING TACHO",
                            value: $endingTacho,
                            baseline: nil
                        )
                        adjustmentField(
                            "FUEL REMAINING",
                            value: $fuelRemaining,
                            baseline: nil
                        )
                    }
                    .padding(16)
                }
            }
            .navigationTitle("Adjust Flight Log")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Save") {
                        Task {
                            if await flightLogs.adjustFlightLog(
                                entry,
                                departureAirport: departureAirport,
                                arrivalAirport: arrivalAirport,
                                crewNames: crewNames.split(separator: ",").map(String.init),
                                startingHobbs: Double(startingHobbs),
                                startingTacho: Double(startingTacho),
                                endingHobbs: Double(endingHobbs),
                                endingTacho: Double(endingTacho),
                                fuelRemaining: fuelRemaining,
                                settings: settings
                            ) {
                                dismiss()
                            }
                        }
                    }
                    .disabled(
                        departureAirport.isEmpty
                            || arrivalAirport.isEmpty
                            || crewNames.isEmpty
                            || startingHobbs.isEmpty
                            || startingTacho.isEmpty
                            || endingHobbs.isEmpty
                            || endingTacho.isEmpty
                            || fuelRemaining.isEmpty
                    )
                }
            }
        }
        .preferredColorScheme(.dark)
    }

    private func adjustmentField(
        _ title: String,
        value: Binding<String>,
        baseline: Double?,
        keyboard: UIKeyboardType = .decimalPad
    ) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            Text(title)
                .font(.caption.weight(.bold))
                .tracking(1)
                .foregroundStyle(CVROperationalPalette.secondaryBlue)
            TextField(title, text: value)
                .keyboardType(keyboard)
                .textInputAutocapitalization(keyboard == .default ? .characters : .never)
                .font(.title3.weight(.bold).monospacedDigit())
                .foregroundStyle(.white)
                .padding(12)
                .background(Color.black.opacity(0.22), in: RoundedRectangle(cornerRadius: 10))
                .overlay(RoundedRectangle(cornerRadius: 10).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
            if let baseline {
                Text("Starting value: \(String(format: "%.1f", baseline))")
                    .font(.caption.weight(.semibold))
                    .foregroundStyle(CVROperationalPalette.textSecondary)
            }
        }
        .padding(14)
        .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 16))
        .overlay(RoundedRectangle(cornerRadius: 16).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
    }
}

struct NoActiveFlightView: View {
    @EnvironmentObject private var workflow: CVRWorkflowStore
    var caption: String

    var body: some View {
        GeometryReader { proxy in
            let metrics = CVROperationalMetrics(size: proxy.size)
            ZStack {
                CVROperationalPalette.background.ignoresSafeArea()
                ScrollView {
                    VStack(spacing: metrics.spacing) {
                        CVROperationalStatusCard(
                            title: "NO ACTIVE FLIGHT",
                            subtitle: "READY FOR THE NEXT DISPATCH",
                            iconName: "checkmark.seal.fill",
                            color: CVROperationalPalette.standby,
                            value: nil,
                            caption: caption,
                            metrics: metrics
                        )
                        CVROperationalWarningCard(
                            title: workflow.archives.isEmpty ? "READY FOR DISPATCH" : "PREVIOUS FLIGHT ENDED",
                            message: workflow.archives.isEmpty
                                ? "Select Scheduled or Dispatch to begin a flight."
                                : "Completed flights and Garmin CSV status are available in Log. Select Scheduled or Dispatch to begin another flight.",
                            iconName: "list.bullet.clipboard.fill",
                            color: CVROperationalPalette.success
                        )
                    }
                    .padding(.horizontal, metrics.outerHorizontalPadding)
                    .padding(.top, metrics.outerVerticalPadding)
                    .padding(.bottom, 132)
                    .frame(width: proxy.size.width, alignment: .top)
                }
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
                ScrollView {
                    VStack(spacing: metrics.spacing) {
                        CVROperationalStatusCard(title: title, subtitle: subtitle, iconName: iconName, color: color, value: nil, caption: "WORKFLOW", metrics: metrics)
                        CVROperationalWarningCard(title: subtitle, message: "Complete the previous operational step before using this tab.", iconName: "lock.fill", color: color)
                    }
                    .padding(.horizontal, metrics.outerHorizontalPadding)
                    .padding(.top, metrics.outerVerticalPadding)
                    .padding(.bottom, 132)
                    .frame(width: proxy.size.width, alignment: .top)
                }
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
                        } else if workflow.state.activeDispatch?.dispatchSource == "previous_locally_closed_flight_carryover" {
                            CVROperationalWarningCard(
                                title: "PREVIOUS FLIGHT VALUES SAVED ON THIS IPHONE",
                                message: "Starting Hobbs, Starting Tacho, fuel, and oil were carried forward from the previous completed flight. Its server upload may still be pending. Confirm the physical indications before dispatch.",
                                iconName: "iphone.and.arrow.forward",
                                color: CVROperationalPalette.secondaryBlue
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
                                unit: operationalConfig.fuelUnit,
                                value: $fuelGallons,
                                hasSelection: $hasFuelSelection,
                                maxValue: operationalConfig.fuelCapacity,
                                warningThreshold: operationalConfig.fuelCapacity * (3.0 / 13.0),
                                fillColor: CVROperationalPalette.success,
                                warningColor: CVROperationalPalette.critical
                            )
                            .frame(width: 132)
                            CVRFluidCylinderPicker(
                                title: "OIL",
                                unit: operationalConfig.oilUnit,
                                value: $oilPercent,
                                hasSelection: $hasOilSelection,
                                maxValue: operationalConfig.oilCapacity,
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
        if let fuel = Self.quantity(from: dispatch.fuelOnboard, unit: operationalConfig.fuelUnit) {
            fuelGallons = min(max(fuel, 0), operationalConfig.fuelCapacity)
            hasFuelSelection = true
        } else {
            fuelGallons = 0
            hasFuelSelection = false
        }
        if let oil = dispatch.effectiveStartingOilQuantity {
            oilPercent = min(max(oil, 0), operationalConfig.oilCapacity)
            hasOilSelection = true
        } else {
            oilPercent = operationalConfig.oilCapacity / 2
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
            dispatch.fuelOnboard = hasFuelSelection ? Self.quantityText(fuelGallons) : ""
            dispatch.startingOilQuantity = hasOilSelection ? oilPercent : nil
            dispatch.startingOilUnit = hasOilSelection ? operationalConfig.oilUnit : nil
            dispatch.oilPercentage = hasOilSelection && operationalConfig.oilUnit == "%" ? Int(oilPercent.rounded()) : nil
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
              let previous = dispatch.previousFuelRemaining.flatMap({ Self.quantity(from: $0, unit: operationalConfig.fuelUnit) }),
              hasFuelSelection,
              fuelGallons > previous else { return false }
        return Self.relativeDifference(fuelGallons, previous) > 0.20
    }

    private var requiresOilServiceConfirmation: Bool {
        guard let previous = workflow.state.activeDispatch?.effectivePreviousOilQuantity,
              hasOilSelection,
              oilPercent > previous else { return false }
        return Self.relativeDifference(oilPercent, previous) > 0.20
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
        if let previous = dispatch.previousFuelRemaining.flatMap({ Self.quantity(from: $0, unit: operationalConfig.fuelUnit) }),
           hasFuelSelection,
           Self.relativeDifference(fuelGallons, previous) > 0.20 {
            messages.append(fuelGallons > previous
                ? "Fuel differs by more than 20%. Confirm refueling."
                : "Fuel is more than 20% below the previous ending value; refueling cannot explain this.")
        }
        if let previous = dispatch.effectivePreviousOilQuantity,
           hasOilSelection,
           Self.relativeDifference(oilPercent, previous) > 0.20 {
            messages.append(oilPercent > previous
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

    private var operationalConfig: AircraftOperationalConfig {
        settings.selectedAircraft?.operationalConfig ?? .safeDefaults
    }

    private static func quantity(from value: String, unit: String) -> Double? {
        let cleaned = value
            .replacingOccurrences(of: unit, with: "", options: .caseInsensitive)
            .replacingOccurrences(of: "USG", with: "", options: .caseInsensitive)
            .trimmingCharacters(in: .whitespacesAndNewlines)
        return Double(cleaned)
    }

    private static func quantityText(_ value: Double) -> String {
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
                                let stepped = unit == "%" ? selected.rounded() : (selected * 10).rounded() / 10
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
