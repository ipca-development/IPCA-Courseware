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
    @EnvironmentObject private var missionCatalog: MissionCatalogStore
    @Binding var showAdminUnlock: Bool
    @State private var pendingReplacementSession: CVRScheduledSession?
    @State private var showLocalMultiLegSheet = false

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
                        if workflow.state.engineSessionContinuityActive,
                           workflow.hasRemainingPlannedLegAfterCurrent {
                            CVROperationalWarningCard(
                                title: "ENGINE SESSION CONTINUING",
                                message: "Select the next leg. Engine Start is not required. Crew, Hobbs, Tacho, fuel, and oil carry forward.",
                                iconName: "flame.fill",
                                color: CVROperationalPalette.secondaryBlue
                            )
                        }
                        ForEach(daySections, id: \.title) { section in
                            reservationSection(section.title, groups: section.groups, metrics: metrics)
                        }
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
            "Save the current leg before opening another?",
            isPresented: Binding(
                get: { pendingReplacementSession != nil },
                set: { if !$0 { pendingReplacementSession = nil } }
            ),
            titleVisibility: .visible
        ) {
            if let session = pendingReplacementSession {
                Button("Open Scheduled Leg", role: .destructive) {
                    pendingReplacementSession = nil
                    openScheduledSession(session)
                }
            }
            Button("Cancel", role: .cancel) {
                pendingReplacementSession = nil
            }
        } message: {
            Text("Complete Check-In for the current leg when possible. Pending evidence remains available in Log.")
        }
        .sheet(isPresented: $showLocalMultiLegSheet) {
            LocalMultiLegDispatchSheet()
                .environmentObject(workflow)
                .environmentObject(settings)
                .environmentObject(beacon)
                .environmentObject(missionCatalog)
        }
    }

    private func statusCard(_ metrics: CVROperationalMetrics) -> some View {
        CVROperationalStatusCard(
            title: scheduleStatusTitle,
            subtitle: scheduleStatusSubtitle,
            iconName: sessionsStore.isRefreshing ? "arrow.triangle.2.circlepath" : "calendar.badge.clock",
            color: scheduleStatusColor,
            value: visibleGroups.isEmpty ? nil : "\(visibleGroups.count)",
            caption: "SCHEDULED RESERVATIONS",
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
                value: "\(todayGroups.count)",
                color: todayGroups.isEmpty ? CVROperationalPalette.standby : CVROperationalPalette.secondaryBlue,
                metrics: metrics
            )
            CVROperationalTile(
                title: "LEGS",
                iconName: "point.topleft.down.to.point.bottomright.curvepath",
                value: "\(visibleGroups.flatMap(\.legs).count)",
                color: CVROperationalPalette.secondaryBlue,
                metrics: metrics
            )
            CVROperationalTile(
                title: "STATUS",
                iconName: sessionsStore.isRefreshing ? "arrow.triangle.2.circlepath" : "checkmark.circle.fill",
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
                title: "SCHEDULE UPDATE WARNING",
                message: error,
                iconName: "icloud.slash.fill",
                color: CVROperationalPalette.warning
            )
        } else if visibleGroups.isEmpty && !sessionsStore.isRefreshing {
            CVROperationalWarningCard(
                title: "NO SCHEDULED FLIGHTS",
                message: "No flights are scheduled for this aircraft. You can still create a local Dispatch.",
                iconName: "calendar.badge.exclamationmark",
                color: CVROperationalPalette.standby
            )
        }
    }

    @ViewBuilder
    private func reservationSection(
        _ title: String,
        groups: [CVRScheduledReservationGroup],
        metrics: CVROperationalMetrics
    ) -> some View {
        VStack(alignment: .leading, spacing: 9) {
            Text(title)
                .font(.caption.weight(.bold))
                .tracking(1.1)
                .foregroundStyle(CVROperationalPalette.textSecondary)
                .frame(maxWidth: .infinity, alignment: .leading)
            if groups.isEmpty {
                HStack(spacing: 10) {
                    Image(systemName: "calendar")
                        .foregroundStyle(CVROperationalPalette.standby)
                    Text("No reservations")
                        .font(.subheadline.weight(.semibold))
                        .foregroundStyle(CVROperationalPalette.textSecondary)
                    Spacer()
                }
                .padding(14)
                .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 16))
                .overlay(RoundedRectangle(cornerRadius: 16).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
            } else {
                ForEach(groups) { group in
                    reservationCard(group, metrics: metrics)
                }
            }
        }
    }

    private func reservationCard(
        _ group: CVRScheduledReservationGroup,
        metrics: CVROperationalMetrics
    ) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack {
                Text(group.routeSummary)
                    .font(.headline.weight(.bold))
                    .foregroundStyle(.white)
                Spacer()
                Text("\(group.legs.count) LEG\(group.legs.count == 1 ? "" : "S")")
                    .font(.caption.weight(.bold))
                    .foregroundStyle(CVROperationalPalette.secondaryBlue)
            }
            Text(group.aircraftRegistration)
                .font(.caption.weight(.semibold))
                .foregroundStyle(CVROperationalPalette.textSecondary)
            ForEach(group.legs) { leg in
                legRow(leg, metrics: metrics)
            }
        }
        .padding(14)
        .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 16))
        .overlay(RoundedRectangle(cornerRadius: 16).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
    }

    private func legRow(_ leg: CVRScheduledLegItem, metrics: CVROperationalMetrics) -> some View {
        let blocked = isLegBlocked(leg)
        return Button {
            openLeg(leg)
        } label: {
            HStack(spacing: 12) {
                Text("LEG \(leg.sequenceNumber)")
                    .font(.caption.weight(.bold))
                    .foregroundStyle(CVROperationalPalette.secondaryBlue)
                    .frame(width: 48, alignment: .leading)
                VStack(alignment: .leading, spacing: 3) {
                    Text(leg.routeLabel)
                        .font(.subheadline.weight(.bold))
                        .foregroundStyle(.white)
                    Text(leg.missionCode.nilIfEmpty ?? timeRange(leg))
                        .font(.caption.weight(.semibold))
                        .foregroundStyle(CVROperationalPalette.textSecondary)
                }
                Spacer()
                Image(systemName: blocked ? "lock.fill" : "chevron.right")
                    .foregroundStyle(blocked ? CVROperationalPalette.warning : CVROperationalPalette.textSecondary)
            }
            .padding(.vertical, 8)
            .padding(.horizontal, 10)
            .background(Color.white.opacity(0.04), in: RoundedRectangle(cornerRadius: 12))
        }
        .buttonStyle(.plain)
        .disabled(blocked || leg.status == "checked_in")
        .opacity((blocked || leg.status == "checked_in") ? 0.55 : 1)
    }

    private func isLegBlocked(_ leg: CVRScheduledLegItem) -> Bool {
        if leg.status == "checked_in" { return true }
        if let session = leg.session {
            return !workflow.canOpenScheduledSession(
                session,
                selectedAircraft: aircraftForSession(session),
                isAudioRecording: audio.isRecording
            )
        }
        if workflow.state.engineSessionContinuityActive { return false }
        return audio.isRecording && workflow.state.activeFlightRecord != nil
    }

    private func openLeg(_ leg: CVRScheduledLegItem) {
        if let session = leg.session {
            if workflow.requiresArchivingBeforeScheduledSession(session) {
                pendingReplacementSession = session
            } else {
                openScheduledSession(session)
            }
            return
        }
        if let planned = workflow.state.plannedLegs.first(where: { $0.legUUID == leg.legUUID }) {
            workflow.openDispatchFromPlannedLeg(
                planned,
                selectedAircraft: settings.selectedAircraft,
                cvrUnitID: settings.cvrUnitIdentifier,
                beaconID: beacon.expectedBeaconIdentityHex,
                isAudioRecording: audio.isRecording,
                canonicalWriteEnabled: settings.operationalIdentityCanonicalWriteEnabled
            )
        }
    }

    private func openScheduledSession(_ session: CVRScheduledSession) {
        workflow.openDispatchFromScheduledSession(
            session,
            selectedAircraft: aircraftForSession(session),
            cvrUnitID: settings.cvrUnitIdentifier,
            beaconID: beacon.expectedBeaconIdentityHex,
            isAudioRecording: audio.isRecording,
            canonicalWriteEnabled: settings.operationalIdentityCanonicalWriteEnabled
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
                    subtitle: "Single leg or multi-leg reservation",
                    color: CVROperationalPalette.standby
                ) {
                    showLocalMultiLegSheet = true
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
        return visibleGroups.isEmpty ? "NO FLIGHTS SCHEDULED" : "RESERVATIONS AVAILABLE"
    }

    private var scheduleStatusSubtitle: String {
        if settings.selectedAircraft == nil {
            return "CONFIGURE THE CVR UNIT AIRCRAFT"
        }
        if sessionsStore.isRefreshing {
            return "UPDATING SCHEDULE"
        }
        if visibleGroups.isEmpty {
            return "LOCAL DISPATCH REMAINS AVAILABLE"
        }
        return "SELECT A LEG TO PREPARE DISPATCH"
    }

    private var scheduleStatusColor: Color {
        if settings.selectedAircraft == nil {
            return CVROperationalPalette.critical
        }
        if sessionsStore.isRefreshing {
            return CVROperationalPalette.secondaryBlue
        }
        return visibleGroups.isEmpty ? CVROperationalPalette.standby : CVROperationalPalette.success
    }

    private var aircraftSessions: [CVRScheduledSession] {
        guard let aircraft = settings.selectedAircraft else { return [] }
        let startOfToday = operationalCalendar.startOfDay(for: Date())
        var consumedSchedulerRecordIDs = Set(
            workflow.archives.compactMap { $0.dispatch.schedulerRecordID }
        )
        var consumedLegUUIDs = Set(
            workflow.archives.compactMap { $0.dispatch.operationalIdentity?.legUUID }
        )
        for planned in workflow.state.plannedLegs where planned.status == "checked_in" {
            consumedLegUUIDs.insert(planned.legUUID)
            if let scheduler = planned.schedulerRecordID {
                consumedSchedulerRecordIDs.insert(scheduler)
            }
        }
        if let activeSchedulerRecordID = workflow.state.activeDispatch?.schedulerRecordID {
            consumedSchedulerRecordIDs.insert(activeSchedulerRecordID)
        }
        if let activeLeg = workflow.state.activeDispatch?.operationalIdentity?.legUUID {
            consumedLegUUIDs.insert(activeLeg)
        }
        return sessionsStore.sessions
            .filter {
                ($0.aircraftID == aircraft.id
                    || CVRWorkflowStore.normalizedTail($0.aircraftRegistration)
                        == CVRWorkflowStore.normalizedTail(aircraft.registration))
                    && ($0.dateTime(nil) ?? .distantPast) >= startOfToday
                    && !consumedSchedulerRecordIDs.contains($0.schedulerRecordID)
                    && ($0.legUUID == nil || !consumedLegUUIDs.contains($0.legUUID!))
            }
            .sorted { ($0.dateTime($0.scheduledStartTime) ?? .distantFuture) < ($1.dateTime($1.scheduledStartTime) ?? .distantFuture) }
    }

    private var visibleGroups: [CVRScheduledReservationGroup] {
        let localLegs = workflow.state.plannedLegs.filter { planned in
            planned.status != "checked_in"
                && (settings.selectedAircraft == nil
                    || CVRWorkflowStore.normalizedTail(planned.tailNumber)
                        == CVRWorkflowStore.normalizedTail(settings.selectedAircraft?.registration ?? ""))
        }
        return CVRScheduledReservationGrouping.groups(
            from: aircraftSessions,
            localLegs: localLegs,
            calendar: operationalCalendar
        )
    }

    private var todayGroups: [CVRScheduledReservationGroup] {
        visibleGroups.filter { operationalCalendar.isDate($0.dayStart, inSameDayAs: Date()) }
    }

    private var daySections: [(title: String, groups: [CVRScheduledReservationGroup])] {
        let grouped = Dictionary(grouping: visibleGroups) { group in
            operationalCalendar.startOfDay(for: group.dayStart)
        }
        let formatter = DateFormatter()
        formatter.calendar = operationalCalendar
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.timeZone = operationalCalendar.timeZone
        formatter.dateFormat = "EEEE"
        return grouped.keys.sorted().map { day in
            let title: String
            if operationalCalendar.isDate(day, inSameDayAs: Date()) {
                title = "Today"
            } else {
                title = formatter.string(from: day)
            }
            return (title, grouped[day] ?? [])
        }
    }

    private var operationalCalendar: Calendar {
        var calendar = Calendar(identifier: .gregorian)
        calendar.timeZone = TimeZone(identifier: "America/Los_Angeles") ?? .current
        return calendar
    }

    private func timeRange(_ leg: CVRScheduledLegItem) -> String {
        let start = leg.scheduledStartTime?.formatted(date: .omitted, time: .shortened) ?? "TBD"
        let end = leg.scheduledEndTime?.formatted(date: .omitted, time: .shortened) ?? "TBD"
        return "\(start)–\(end)"
    }
}

private struct LocalMultiLegDispatchSheet: View {
    @EnvironmentObject private var workflow: CVRWorkflowStore
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var beacon: AvionicsBeaconManager
    @EnvironmentObject private var missionCatalog: MissionCatalogStore
    @Environment(\.dismiss) private var dismiss

    @State private var draft = CVRLocalDispatchDraft.fresh()
    @State private var validationHint = ""
    @State private var showMissionPicker = false
    @FocusState private var focusedField: Field?

    private enum Field: Hashable {
        case departure(Int)
        case arrival(Int)
    }

    private var flightMissions: [CVRMissionCatalogEntry] {
        missionCatalog.flightMissions
    }

    private var canCreate: Bool {
        draft.canSubmit && settings.selectedAircraft != nil
    }

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                List {
                    Section {
                        missionSection
                            .listRowInsets(EdgeInsets(top: 12, leading: 16, bottom: 12, trailing: 16))
                            .listRowBackground(Color.clear)
                            .listRowSeparator(.hidden)
                    }

                    Section {
                        ForEach(Array(draft.legs.enumerated()), id: \.element.legUUID) { index, leg in
                            legRow(index: index, leg: leg)
                                .listRowInsets(EdgeInsets(top: 8, leading: 16, bottom: 8, trailing: 16))
                                .listRowBackground(Color.clear)
                                .listRowSeparator(.hidden)
                                .swipeActions(edge: .trailing, allowsFullSwipe: true) {
                                    if draft.legs.count > 1, leg.isErasable {
                                        Button(role: .destructive) {
                                            eraseLeg(id: leg.legUUID)
                                        } label: {
                                            Label("ERASE", systemImage: "trash")
                                        }
                                        .tint(CVROperationalPalette.critical)
                                    }
                                }
                        }
                        .onDelete(perform: eraseLegs)
                    } header: {
                        Text("Route")
                            .font(.caption.weight(.bold))
                            .tracking(1.0)
                            .foregroundStyle(CVROperationalPalette.secondaryBlue)
                            .textCase(nil)
                    }

                    Section {
                        addLegButton
                            .listRowInsets(EdgeInsets(top: 4, leading: 16, bottom: 4, trailing: 16))
                            .listRowBackground(Color.clear)
                            .listRowSeparator(.hidden)

                        if !validationHint.isEmpty {
                            Text(validationHint)
                                .font(.caption.weight(.semibold))
                                .foregroundStyle(CVROperationalPalette.warning)
                                .listRowInsets(EdgeInsets(top: 4, leading: 16, bottom: 4, trailing: 16))
                                .listRowBackground(Color.clear)
                                .listRowSeparator(.hidden)
                        }

                        CVROperationalActionButton(
                            title: "CREATE DISPATCH",
                            subtitle: draft.legs.count == 1 ? "Single-leg route" : "\(draft.legs.count) legs · one reservation",
                            color: canCreate ? CVROperationalPalette.success : CVROperationalPalette.standby
                        ) {
                            create()
                        }
                        .disabled(!canCreate)
                        .opacity(canCreate ? 1 : 0.55)
                        .listRowInsets(EdgeInsets(top: 8, leading: 16, bottom: 24, trailing: 16))
                        .listRowBackground(Color.clear)
                        .listRowSeparator(.hidden)
                    }
                }
                .listStyle(.plain)
                .scrollContentBackground(.hidden)
                .scrollDismissesKeyboard(.interactively)
            }
            .background(CVROperationalPalette.background.ignoresSafeArea())
            .navigationTitle("Create Local Dispatch")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") {
                        CVRLocalDispatchDraft.clear()
                        dismiss()
                    }
                }
                ToolbarItemGroup(placement: .keyboard) {
                    Spacer()
                    Button("Done") { focusedField = nil }
                }
            }
            .onAppear(perform: loadDraft)
            .sheet(isPresented: $showMissionPicker) {
                CVRMissionPickerSheet(
                    missions: flightMissions,
                    selectedMissionCode: draft.selectedMissionCode,
                    titleProvider: { missionCatalog.flightMissionPickerTitle($0) }
                ) { code in
                    draft.selectedMissionCode = code
                }
            }
            .onChange(of: draft) { _, newValue in
                newValue.save()
                if canCreate {
                    validationHint = ""
                }
            }
        }
        .preferredColorScheme(.dark)
    }

    private var missionSection: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("CREATE LOCAL DISPATCH")
                .font(.caption.weight(.bold))
                .tracking(1.2)
                .foregroundStyle(CVROperationalPalette.textSecondary)
            Text("Mission")
                .font(.caption.weight(.bold))
                .tracking(1.0)
                .foregroundStyle(CVROperationalPalette.secondaryBlue)
            Button {
                showMissionPicker = true
            } label: {
                HStack {
                    Text(selectedMissionLabel)
                        .font(.subheadline.weight(.semibold))
                        .foregroundStyle(
                            draft.selectedMissionCode.isEmpty
                                ? CVROperationalPalette.textSecondary
                                : CVROperationalPalette.textPrimary
                        )
                        .multilineTextAlignment(.leading)
                    Spacer(minLength: 8)
                    Image(systemName: "chevron.up.chevron.down")
                        .font(.caption.weight(.bold))
                        .foregroundStyle(CVROperationalPalette.textSecondary)
                }
                .padding(14)
                .frame(minHeight: 52)
                .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 12))
                .overlay(RoundedRectangle(cornerRadius: 12).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
            }
            .buttonStyle(.plain)
            .disabled(flightMissions.isEmpty)
            if flightMissions.isEmpty {
                Text("No flight missions are available on this device.")
                    .font(.caption)
                    .foregroundStyle(CVROperationalPalette.warning)
            }
        }
    }

    private var selectedMissionLabel: String {
        if let selected = flightMissions.first(where: {
            $0.missionCode.caseInsensitiveCompare(draft.selectedMissionCode) == .orderedSame
        }) {
            return missionCatalog.flightMissionPickerTitle(selected)
        }
        return "Select Flight Mission"
    }

    private func legRow(index: Int, leg: CVRLocalDispatchDraftLeg) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("LEG \(index + 1)")
                .font(.caption.weight(.bold))
                .tracking(1.2)
                .foregroundStyle(CVROperationalPalette.textSecondary)

            HStack(spacing: 10) {
                airportField(
                    label: "DEP AD",
                    text: Binding(
                        get: { draft.legs[index].departureAirport },
                        set: { draft.setDeparture(legIndex: index, airport: $0) }
                    ),
                    editable: index == 0,
                    focus: .departure(index)
                )

                Image(systemName: "arrow.right")
                    .font(.caption.weight(.bold))
                    .foregroundStyle(CVROperationalPalette.textSecondary)

                airportField(
                    label: "ARR AD",
                    text: Binding(
                        get: { draft.legs[index].arrivalAirport },
                        set: { draft.setArrival(legIndex: index, airport: $0) }
                    ),
                    editable: true,
                    focus: .arrival(index)
                )
            }
        }
        .padding(14)
        .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 14))
        .overlay(RoundedRectangle(cornerRadius: 14).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
    }

    private func airportField(
        label: String,
        text: Binding<String>,
        editable: Bool,
        focus: Field
    ) -> some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(label)
                .font(.caption2.weight(.bold))
                .tracking(0.8)
                .foregroundStyle(CVROperationalPalette.textSecondary)
            if editable {
                TextField(label, text: text)
                    .textInputAutocapitalization(.characters)
                    .autocorrectionDisabled()
                    .font(.title3.weight(.bold).monospaced())
                    .foregroundStyle(CVROperationalPalette.textPrimary)
                    .focused($focusedField, equals: focus)
                    .frame(minHeight: 44)
                    .padding(.horizontal, 10)
                    .padding(.vertical, 8)
                    .background(Color.black.opacity(0.28), in: RoundedRectangle(cornerRadius: 10))
                    .overlay(RoundedRectangle(cornerRadius: 10).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
                    .onChange(of: text.wrappedValue) { _, newValue in
                        let sanitized = CVRLocalDispatchDraft.sanitizeAirportInput(newValue)
                        if sanitized != newValue {
                            text.wrappedValue = sanitized
                        }
                    }
            } else {
                Text(text.wrappedValue.isEmpty ? "—" : text.wrappedValue)
                    .font(.title3.weight(.bold).monospaced())
                    .foregroundStyle(CVROperationalPalette.textSecondary)
                    .frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
                    .padding(.horizontal, 10)
                    .padding(.vertical, 8)
                    .background(Color.black.opacity(0.12), in: RoundedRectangle(cornerRadius: 10))
                    .overlay(RoundedRectangle(cornerRadius: 10).stroke(CVROperationalPalette.cardBorder.opacity(0.6), lineWidth: 1))
                    .accessibilityLabel("\(label), inherited")
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }

    private var addLegButton: some View {
        Button {
            draft.addLeg()
            UIImpactFeedbackGenerator(style: .medium).impactOccurred()
            validationHint = ""
        } label: {
            HStack(spacing: 8) {
                Image(systemName: "plus.circle.fill")
                Text("ADD LEG")
                    .font(.subheadline.weight(.bold))
                    .tracking(1.0)
            }
            .foregroundStyle(CVROperationalPalette.secondaryBlue)
            .frame(maxWidth: .infinity, minHeight: 48)
            .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 12))
            .overlay(RoundedRectangle(cornerRadius: 12).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
        }
        .buttonStyle(.plain)
    }

    private func eraseLeg(id: String) {
        let erased = draft.eraseLeg(id: id)
        if erased {
            UINotificationFeedbackGenerator().notificationOccurred(.warning)
            validationHint = ""
        }
    }

    private func eraseLegs(at offsets: IndexSet) {
        let ids = offsets.compactMap { index -> String? in
            guard draft.legs.indices.contains(index) else { return nil }
            return draft.legs[index].legUUID
        }
        for id in ids {
            eraseLeg(id: id)
        }
    }

    private func loadDraft() {
        if missionCatalog.missions.isEmpty {
            missionCatalog.loadBundledFallback()
        }
        // Always start a brand-new local reservation. Do not restore a previous route draft.
        CVRLocalDispatchDraft.clear()
        draft = CVRLocalDispatchDraft.fresh(homeAirport: settings.selectedAircraft?.homeAirport ?? "")
        validationHint = ""
    }

    private func create() {
        if let message = draft.validationMessage {
            validationHint = message
            return
        }
        guard settings.selectedAircraft != nil else {
            validationHint = "Aircraft configuration is required before creating a Dispatch."
            return
        }
        workflow.createLocalMultiLegReservation(
            airports: draft.airportChain,
            selectedAircraft: settings.selectedAircraft,
            cvrUnitID: settings.cvrUnitIdentifier,
            beaconID: beacon.expectedBeaconIdentityHex,
            missionCode: draft.selectedMissionCode,
            canonicalWriteEnabled: settings.operationalIdentityCanonicalWriteEnabled,
            reservationUUID: draft.reservationUUID,
            legUUIDs: draft.legUUIDs
        )
        if workflow.lastError.isEmpty {
            CVRLocalDispatchDraft.clear()
            dismiss()
        } else {
            validationHint = workflow.lastError
        }
    }
}

/// Edit planned legs on an existing local Dispatch before the first DISPATCH FLIGHT.
private struct LocalRouteEditorSheet: View {
    @EnvironmentObject private var workflow: CVRWorkflowStore
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var missionCatalog: MissionCatalogStore
    @Environment(\.dismiss) private var dismiss

    @State private var draft = CVRLocalDispatchDraft.fresh()
    @State private var validationHint = ""
    @FocusState private var focusedField: Field?

    private enum Field: Hashable {
        case departure(Int)
        case arrival(Int)
    }

    var body: some View {
        NavigationStack {
            List {
                Section {
                    ForEach(Array(draft.legs.enumerated()), id: \.element.legUUID) { index, leg in
                        VStack(alignment: .leading, spacing: 8) {
                            Text("LEG \(index + 1)")
                                .font(.caption.weight(.bold))
                                .tracking(1.2)
                                .foregroundStyle(CVROperationalPalette.textSecondary)
                            HStack(spacing: 10) {
                                routeAirportField(
                                    label: "DEP AD",
                                    text: Binding(
                                        get: { draft.legs[index].departureAirport },
                                        set: { draft.setDeparture(legIndex: index, airport: $0) }
                                    ),
                                    editable: index == 0,
                                    focus: .departure(index)
                                )
                                Image(systemName: "arrow.right")
                                    .font(.caption.weight(.bold))
                                    .foregroundStyle(CVROperationalPalette.textSecondary)
                                routeAirportField(
                                    label: "ARR AD",
                                    text: Binding(
                                        get: { draft.legs[index].arrivalAirport },
                                        set: { draft.setArrival(legIndex: index, airport: $0) }
                                    ),
                                    editable: true,
                                    focus: .arrival(index)
                                )
                            }
                        }
                        .padding(14)
                        .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 14))
                        .overlay(RoundedRectangle(cornerRadius: 14).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
                        .listRowInsets(EdgeInsets(top: 8, leading: 16, bottom: 8, trailing: 16))
                        .listRowBackground(Color.clear)
                        .listRowSeparator(.hidden)
                        .swipeActions(edge: .trailing, allowsFullSwipe: true) {
                            if draft.legs.count > 1, leg.isErasable {
                                Button(role: .destructive) {
                                    if draft.eraseLeg(id: leg.legUUID) {
                                        UINotificationFeedbackGenerator().notificationOccurred(.warning)
                                    }
                                } label: {
                                    Label("ERASE", systemImage: "trash")
                                }
                                .tint(CVROperationalPalette.critical)
                            }
                        }
                    }
                    .onDelete(perform: eraseLegs)
                } header: {
                    Text("Route")
                        .font(.caption.weight(.bold))
                        .foregroundStyle(CVROperationalPalette.secondaryBlue)
                        .textCase(nil)
                }

                Section {
                    Button {
                        draft.addLeg()
                        UIImpactFeedbackGenerator(style: .medium).impactOccurred()
                    } label: {
                        HStack(spacing: 8) {
                            Image(systemName: "plus.circle.fill")
                            Text("ADD LEG")
                                .font(.subheadline.weight(.bold))
                                .tracking(1.0)
                        }
                        .foregroundStyle(CVROperationalPalette.secondaryBlue)
                        .frame(maxWidth: .infinity, minHeight: 48)
                    }
                    .buttonStyle(.plain)
                    .listRowInsets(EdgeInsets(top: 4, leading: 16, bottom: 4, trailing: 16))
                    .listRowBackground(Color.clear)
                    .listRowSeparator(.hidden)

                    if !validationHint.isEmpty {
                        Text(validationHint)
                            .font(.caption.weight(.semibold))
                            .foregroundStyle(CVROperationalPalette.warning)
                            .listRowBackground(Color.clear)
                            .listRowSeparator(.hidden)
                    }

                    CVROperationalActionButton(
                        title: "SAVE ROUTE",
                        subtitle: "\(draft.legs.count) leg\(draft.legs.count == 1 ? "" : "s")",
                        color: CVROperationalPalette.success
                    ) {
                        save()
                    }
                    .listRowInsets(EdgeInsets(top: 8, leading: 16, bottom: 24, trailing: 16))
                    .listRowBackground(Color.clear)
                    .listRowSeparator(.hidden)
                }
            }
            .listStyle(.plain)
            .scrollContentBackground(.hidden)
            .background(CVROperationalPalette.background.ignoresSafeArea())
            .navigationTitle("Edit Route")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
                ToolbarItemGroup(placement: .keyboard) {
                    Spacer()
                    Button("Done") { focusedField = nil }
                }
            }
            .onAppear(perform: loadFromSession)
        }
        .preferredColorScheme(.dark)
    }

    private func routeAirportField(
        label: String,
        text: Binding<String>,
        editable: Bool,
        focus: Field
    ) -> some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(label)
                .font(.caption2.weight(.bold))
                .tracking(0.8)
                .foregroundStyle(CVROperationalPalette.textSecondary)
            if editable {
                TextField(label, text: text)
                    .textInputAutocapitalization(.characters)
                    .autocorrectionDisabled()
                    .font(.title3.weight(.bold).monospaced())
                    .foregroundStyle(CVROperationalPalette.textPrimary)
                    .focused($focusedField, equals: focus)
                    .frame(minHeight: 44)
                    .padding(.horizontal, 10)
                    .padding(.vertical, 8)
                    .background(Color.black.opacity(0.28), in: RoundedRectangle(cornerRadius: 10))
                    .onChange(of: text.wrappedValue) { _, newValue in
                        let sanitized = CVRLocalDispatchDraft.sanitizeAirportInput(newValue)
                        if sanitized != newValue {
                            text.wrappedValue = sanitized
                        }
                    }
            } else {
                Text(text.wrappedValue.isEmpty ? "—" : text.wrappedValue)
                    .font(.title3.weight(.bold).monospaced())
                    .foregroundStyle(CVROperationalPalette.textSecondary)
                    .frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
                    .padding(.horizontal, 10)
                    .padding(.vertical, 8)
                    .background(Color.black.opacity(0.12), in: RoundedRectangle(cornerRadius: 10))
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }

    private func eraseLegs(at offsets: IndexSet) {
        let ids = offsets.compactMap { index -> String? in
            guard draft.legs.indices.contains(index) else { return nil }
            return draft.legs[index].legUUID
        }
        for id in ids {
            if draft.eraseLeg(id: id) {
                UINotificationFeedbackGenerator().notificationOccurred(.warning)
            }
        }
    }

    private func loadFromSession() {
        let session = workflow.state.operationalSession
        let planned = CVRDispatchRouteOverview.ordered((session?.plannedLegs ?? []).map {
            CVRDispatchRouteOverview.Leg(
                legUUID: $0.legUUID,
                sequenceNumber: $0.sequenceNumber,
                departureAirport: $0.departureAirport,
                destinationAirport: $0.destinationAirport,
                status: $0.status
            )
        })
        if planned.isEmpty {
            let dep = workflow.state.activeDispatch?.plannedDepartureAirport
                ?? settings.selectedAircraft?.homeAirport
                ?? ""
            let arr = workflow.state.activeDispatch?.plannedDestinationAirport ?? ""
            draft = CVRLocalDispatchDraft(
                reservationUUID: session?.reservationUUID ?? UUID().uuidString.lowercased(),
                selectedMissionCode: workflow.state.activeDispatch?.missionCode ?? "",
                legs: [
                    CVRLocalDispatchDraftLeg(
                        legUUID: UUID().uuidString.lowercased(),
                        departureAirport: CVROperationalIdentityLocal.normalizeAirport(dep),
                        arrivalAirport: CVROperationalIdentityLocal.normalizeAirport(arr),
                        status: "planned"
                    ),
                ]
            )
        } else {
            draft = CVRLocalDispatchDraft(
                reservationUUID: session?.reservationUUID ?? UUID().uuidString.lowercased(),
                selectedMissionCode: workflow.state.activeDispatch?.missionCode ?? "",
                legs: planned.map {
                    CVRLocalDispatchDraftLeg(
                        legUUID: $0.legUUID,
                        departureAirport: $0.departureAirport,
                        arrivalAirport: $0.destinationAirport,
                        status: $0.status
                    )
                }
            )
            draft.reapplyContinuity()
        }
        _ = missionCatalog
    }

    private func save() {
        workflow.applyLocalRouteDraft(draft)
        if workflow.lastError.isEmpty {
            dismiss()
        } else {
            validationHint = workflow.lastError
        }
    }
}

struct DispatchWorkflowView: View {
    @EnvironmentObject private var workflow: CVRWorkflowStore
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var beacon: AvionicsBeaconManager
    @EnvironmentObject private var missionCatalog: MissionCatalogStore
    @EnvironmentObject private var uploadManager: UploadManager
    @Binding var showAdminUnlock: Bool
    @State private var activeBlockEditor: DispatchBlockEditor?
    @State private var showRouteEditor = false
    @State private var showMissionPicker = false
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
                        routeOverview
                        missionSelector
                        dispatchOilUploadSection
                        exceptionalWarningCard
                        continuityUploadRepairCard
                        quickVerification
                        actionButtons
                    }
                    .padding(.horizontal, metrics.outerHorizontalPadding)
                    .padding(.top, metrics.outerVerticalPadding)
                    .padding(.bottom, 132)
                    .frame(width: proxy.size.width, alignment: .top)
                }
                .scrollDismissesKeyboard(.interactively)
            }
        }
        .onAppear {
            syncContinuityRepairState()
            workflow.sanitizeRouteStatusesIfNeeded()
            workflow.backfillDispatchCarryoverIfNeeded()
            if missionCatalog.missions.isEmpty {
                missionCatalog.loadBundledFallback()
            }
        }
        .onChange(of: workflow.state.activeDispatch?.modifiedAt) {
            syncContinuityRepairState()
        }
        .sheet(isPresented: $showMissionPicker) {
            CVRMissionPickerSheet(
                missions: flightMissions,
                selectedMissionCode: workflow.state.activeDispatch?.missionCode ?? "",
                titleProvider: { missionCatalog.flightMissionPickerTitle($0) }
            ) { code in
                workflow.updateActiveDispatch { dispatch in
                    dispatch.missionCode = code
                }
            }
        }
        .sheet(item: $activeBlockEditor) { editor in
            DispatchEditorView(focus: editor)
                .environmentObject(workflow)
                .environmentObject(settings)
                .environmentObject(missionCatalog)
                .environmentObject(uploadManager)
                .presentationDetents([.large])
        }
        .sheet(isPresented: $showRouteEditor) {
            LocalRouteEditorSheet()
                .environmentObject(workflow)
                .environmentObject(settings)
                .environmentObject(missionCatalog)
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
        LazyVGrid(
            columns: [GridItem(.flexible(), spacing: metrics.spacing), GridItem(.flexible(), spacing: metrics.spacing)],
            spacing: metrics.spacing
        ) {
            CVROperationalTile(
                title: "ACFT",
                iconName: "airplane",
                value: aircraftTile,
                color: aircraftTileColor,
                metrics: metrics,
                caption: aircraftTileCaption,
                action: aircraftTileAction
            )
            CVROperationalTile(
                title: "CREW",
                iconName: "person.2.fill",
                value: crewTile,
                color: crewTileColor,
                metrics: metrics,
                caption: crewTileCaption,
                action: workflow.state.activeDispatch == nil || workflow.isDispatchLocked ? nil : {
                    activeBlockEditor = .crew
                }
            )
            CVROperationalTile(
                title: "METERS",
                iconName: "gauge.with.dots.needle.bottom.50percent",
                value: meterTile,
                color: meterTileColor,
                metrics: metrics,
                caption: meterTileCaption,
                action: workflow.state.activeDispatch == nil || workflow.isDispatchLocked ? nil : {
                    activeBlockEditor = .meters
                }
            )
            CVROperationalTile(
                title: "FUEL/OIL",
                iconName: "fuelpump.fill",
                value: fuelTile,
                color: fuelTileColor,
                metrics: metrics,
                caption: fuelTileCaption,
                action: workflow.state.activeDispatch == nil || workflow.isDispatchLocked ? nil : {
                    activeBlockEditor = .fuelOil
                }
            )
        }
    }

    private var routeOverview: some View {
        let session = workflow.state.operationalSession
        let legs = CVRDispatchRouteOverview.ordered((session?.plannedLegs ?? []).map {
            CVRDispatchRouteOverview.Leg(
                legUUID: $0.legUUID,
                sequenceNumber: $0.sequenceNumber,
                departureAirport: $0.departureAirport,
                destinationAirport: $0.destinationAirport,
                status: $0.status
            )
        })
        let currentLegUUID = workflow.state.activeDispatch?.operationalIdentity?.legUUID
        let currentLegIndex = session?.currentLegIndex
        let canEdit = workflow.canEditLocalRoute
        return VStack(alignment: .leading, spacing: 10) {
            HStack {
                Text("ROUTE")
                    .font(.caption.weight(.bold))
                    .tracking(1.2)
                    .foregroundStyle(CVROperationalPalette.secondaryBlue)
                Spacer(minLength: 0)
                if canEdit {
                    Button {
                        showRouteEditor = true
                    } label: {
                        Text("EDIT ROUTE")
                            .font(.caption.weight(.bold))
                            .tracking(0.8)
                            .foregroundStyle(CVROperationalPalette.secondaryBlue)
                    }
                    .buttonStyle(.plain)
                }
            }
            if legs.isEmpty {
                Text(singleLegRouteFallback)
                    .font(.subheadline.weight(.semibold))
                    .foregroundStyle(CVROperationalPalette.textPrimary)
                    .padding(12)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 14))
                    .overlay(RoundedRectangle(cornerRadius: 14).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
                if canEdit {
                    Button {
                        showRouteEditor = true
                    } label: {
                        Text("Add or edit legs")
                            .font(.caption.weight(.semibold))
                            .foregroundStyle(CVROperationalPalette.secondaryBlue)
                    }
                    .buttonStyle(.plain)
                }
            } else {
                ForEach(legs, id: \.legUUID) { leg in
                    let isCurrent = CVRDispatchRouteOverview.isCurrent(
                        legUUID: leg.legUUID,
                        sequenceNumber: leg.sequenceNumber,
                        currentLegUUID: currentLegUUID,
                        currentLegIndex: currentLegIndex
                    )
                    VStack(alignment: .leading, spacing: 4) {
                        HStack(spacing: 8) {
                            Text(isCurrent ? "LEG \(leg.sequenceNumber) — CURRENT LEG" : "LEG \(leg.sequenceNumber)")
                                .font(.caption.weight(.bold))
                                .tracking(0.8)
                                .foregroundStyle(isCurrent ? CVROperationalPalette.secondaryBlue : CVROperationalPalette.textSecondary)
                            Spacer(minLength: 0)
                            let statusText = CVRDispatchRouteOverview.displayStatus(status: leg.status)
                            let isCheckedIn = CVRDispatchRouteOverview.isCheckedIn(status: leg.status)
                            if isCheckedIn {
                                Label(statusText, systemImage: CVRDispatchRouteOverview.checkedInStatusIcon)
                                    .font(.caption2.weight(.bold))
                                    .foregroundStyle(CVROperationalPalette.success)
                            } else {
                                Text(statusText)
                                    .font(.caption2.weight(.bold))
                                    .foregroundStyle(isCurrent ? CVROperationalPalette.secondaryBlue : CVROperationalPalette.textSecondary)
                            }
                        }
                        Text(CVRDispatchRouteOverview.routeLine(departure: leg.departureAirport, arrival: leg.destinationAirport))
                            .font(.subheadline.weight(.bold).monospaced())
                            .foregroundStyle(CVROperationalPalette.textPrimary)
                    }
                    .padding(12)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .background(
                        (isCurrent
                            ? CVROperationalPalette.secondaryBlue.opacity(0.14)
                            : (CVRDispatchRouteOverview.isCheckedIn(status: leg.status)
                                ? CVROperationalPalette.success.opacity(0.10)
                                : CVROperationalPalette.cardBackground)),
                        in: RoundedRectangle(cornerRadius: 14)
                    )
                    .overlay(
                        RoundedRectangle(cornerRadius: 14)
                            .stroke(
                                isCurrent
                                    ? CVROperationalPalette.secondaryBlue.opacity(0.85)
                                    : (CVRDispatchRouteOverview.isCheckedIn(status: leg.status)
                                        ? CVROperationalPalette.success.opacity(0.55)
                                        : CVROperationalPalette.cardBorder),
                                lineWidth: isCurrent ? 1.5 : 1
                            )
                    )
                }
                if canEdit {
                    Text("Tap EDIT ROUTE to change airports, add a leg, or swipe ERASE.")
                        .font(.caption2.weight(.semibold))
                        .foregroundStyle(CVROperationalPalette.textSecondary)
                }
            }
        }
        .accessibilityElement(children: .contain)
        .accessibilityLabel("Route overview")
    }

    private var singleLegRouteFallback: String {
        guard let dispatch = workflow.state.activeDispatch else {
            return "No route yet"
        }
        let dep = dispatch.plannedDepartureAirport.trimmingCharacters(in: .whitespacesAndNewlines).uppercased()
        let arr = dispatch.plannedDestinationAirport.trimmingCharacters(in: .whitespacesAndNewlines).uppercased()
        if dep.isEmpty && arr.isEmpty { return "No route yet" }
        return "LEG 1\n\((dep.isEmpty ? "—" : dep)) → \((arr.isEmpty ? "—" : arr))"
    }

    private var missionSelector: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("Mission")
                .font(.caption.weight(.bold))
                .tracking(1.0)
                .foregroundStyle(CVROperationalPalette.secondaryBlue)
            if workflow.state.activeDispatch == nil {
                Text("Open a Dispatch to select a mission.")
                    .font(.caption)
                    .foregroundStyle(CVROperationalPalette.textSecondary)
            } else if workflow.isDispatchLocked {
                Text(selectedMissionLabel)
                    .font(.subheadline.weight(.semibold))
                    .foregroundStyle(CVROperationalPalette.textPrimary)
                    .padding(14)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 12))
                    .overlay(RoundedRectangle(cornerRadius: 12).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
            } else {
                Button {
                    showMissionPicker = true
                } label: {
                    HStack {
                        Text(selectedMissionLabel)
                            .font(.subheadline.weight(.semibold))
                            .foregroundStyle(
                                (workflow.state.activeDispatch?.missionCode ?? "").isEmpty
                                    ? CVROperationalPalette.textSecondary
                                    : CVROperationalPalette.textPrimary
                            )
                            .multilineTextAlignment(.leading)
                        Spacer(minLength: 8)
                        Image(systemName: "chevron.up.chevron.down")
                            .font(.caption.weight(.bold))
                            .foregroundStyle(CVROperationalPalette.textSecondary)
                    }
                    .padding(14)
                    .frame(minHeight: 52)
                    .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 12))
                    .overlay(RoundedRectangle(cornerRadius: 12).stroke(
                        (workflow.state.activeDispatch?.missionCode ?? "").isEmpty
                            ? CVROperationalPalette.warning.opacity(0.7)
                            : CVROperationalPalette.cardBorder,
                        lineWidth: 1
                    ))
                }
                .buttonStyle(.plain)
                .disabled(flightMissions.isEmpty)
            }
        }
    }

    private var flightMissions: [CVRMissionCatalogEntry] {
        missionCatalog.flightMissions
    }

    private var selectedMissionLabel: String {
        let code = workflow.state.activeDispatch?.missionCode ?? ""
        if code.isEmpty { return "Select a flight mission" }
        if let selected = flightMissions.first(where: {
            $0.missionCode.caseInsensitiveCompare(code) == .orderedSame
        }) {
            return missionCatalog.flightMissionPickerTitle(selected)
        }
        return code
    }

    private var quickVerification: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("Quick Verification")
                .font(.caption.weight(.bold))
                .tracking(1.0)
                .foregroundStyle(CVROperationalPalette.secondaryBlue)
            VStack(alignment: .leading, spacing: 6) {
                ForEach(quickVerificationRows, id: \.self) { row in
                    Text(row)
                        .font(.caption.weight(.semibold))
                        .foregroundStyle(CVROperationalPalette.textPrimary)
                }
            }
            .padding(12)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 14))
            .overlay(RoundedRectangle(cornerRadius: 14).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
        }
    }

    private var quickVerificationRows: [String] {
        guard workflow.state.activeDispatch != nil else {
            return ["Create or open a Dispatch for this leg."]
        }
        var rows: [String] = []
        rows.append(aircraftTile == "None" ? "Aircraft — required" : "Aircraft — \(aircraftTile)")
        rows.append(crewNeedsEntry ? "Crew — tap CREW to add" : "Crew — \(crewTile)")
        rows.append(metersNeedEntry ? "Meters — tap METERS to enter" : "Meters — entered")
        rows.append(fuelOilNeedEntry ? "Fuel/Oil — tap FUEL/OIL to enter" : "Fuel/Oil — entered")
        rows.append((workflow.state.activeDispatch?.missionCode ?? "").isEmpty
            ? "Mission — select a flight mission"
            : "Mission — selected")
        if canConfirmDispatch {
            rows.append("Ready to dispatch this leg.")
        }
        return rows
    }

    @ViewBuilder
    private var exceptionalWarningCard: some View {
        if workflow.dispatchTailMismatch(enrolledRegistration: settings.selectedAircraft?.registration) {
            CVROperationalWarningCard(
                title: "AIRCRAFT MISMATCH",
                message: "Dispatch tail \(aircraftTile) does not match enrolled aircraft \(settings.selectedAircraft?.registration ?? "—"). Correct the aircraft before continuing.",
                iconName: "airplane.circle.fill",
                color: CVROperationalPalette.critical
            )
        } else if let error = workflow.lastError.nilIfEmpty,
                  !error.localizedCaseInsensitiveContains("upload"),
                  !error.localizedCaseInsensitiveContains("workflow") {
            CVROperationalWarningCard(title: "STORAGE WARNING", message: error, iconName: "externaldrive.badge.exclamationmark", color: CVROperationalPalette.warning)
        } else if settings.selectedAircraft == nil {
            CVROperationalWarningCard(title: "AIRCRAFT CONFIGURATION REQUIRED", message: "Assign this CVR Unit to its aircraft before Dispatch.", iconName: "lock.trianglebadge.exclamationmark", color: CVROperationalPalette.critical)
        } else if workflow.state.activeDispatch == nil {
            CVROperationalWarningCard(title: "NO ACTIVE DISPATCH", message: "Create or open a Dispatch for this leg.", iconName: "exclamationmark.triangle.fill", color: CVROperationalPalette.standby)
        } else if let conflict = exceptionalCrewConflict {
            CVROperationalWarningCard(
                title: "CREW FUNCTION REQUIRED",
                message: conflict,
                iconName: "person.crop.circle.badge.exclamationmark",
                color: CVROperationalPalette.warning
            )
        }
    }

    /// Only exceptional crew conflicts — not the normal empty-crew case (shown in CREW block).
    private var exceptionalCrewConflict: String? {
        guard let dispatch = workflow.state.activeDispatch,
              dispatch.crew.contains(where: { $0.role == .unknown }) else { return nil }
        return "One or more crew members still need a flight function assigned."
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
        if let warnings = workflow.state.activeDispatch?.continuityDiscrepancies,
           !warnings.isEmpty {
            CVROperationalWarningCard(
                title: "DISPATCH CONTINUITY WARNING",
                message: warnings.joined(separator: "\n"),
                iconName: "exclamationmark.triangle.fill",
                color: CVROperationalPalette.warning
            )
        }
    }

    private var actionButtons: some View {
        VStack(spacing: 8) {
            if workflow.dispatchTailMismatch(enrolledRegistration: settings.selectedAircraft?.registration) {
                CVROperationalActionButton(
                    title: "FIX AIRCRAFT ALIGNMENT",
                    subtitle: settings.selectedAircraft?.registration ?? "Select enrolled aircraft in Admin",
                    color: CVROperationalPalette.secondaryBlue
                ) {
                    _ = workflow.repairDispatchAircraftAlignment(selectedAircraft: settings.selectedAircraft)
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
                            beaconID: beacon.expectedBeaconIdentityHex,
                            canonicalWriteEnabled: settings.operationalIdentityCanonicalWriteEnabled
                        )
                    }
                }
            } else if workflow.isDispatchLocked {
                CVROperationalActionButton(title: "Dispatch Confirmed", subtitle: "Open Recorder, then In-Flight", color: CVROperationalPalette.success) {}
            } else {
                CVROperationalActionButton(
                    title: "DISPATCH FLIGHT",
                    subtitle: canConfirmDispatch
                        ? "Quick Verification complete — saves on this device"
                        : dispatchDisabledReason,
                    color: canConfirmDispatch ? CVROperationalPalette.success : CVROperationalPalette.standby
                ) {
                    guard canConfirmDispatch else { return }
                    workflow.verifyDispatchAndCreateFlightRecord()
                    uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                }
                .disabled(!canConfirmDispatch)
                .opacity(canConfirmDispatch ? 1 : 0.55)
            }
        }
    }

    private var dispatchDisabledReason: String {
        if crewNeedsEntry { return "Add crew in the CREW block" }
        if metersNeedEntry { return "Enter meters in the METERS block" }
        if fuelOilNeedEntry { return "Enter fuel and oil in the FUEL/OIL block" }
        if (workflow.state.activeDispatch?.missionCode ?? "").isEmpty { return "Select a flight mission" }
        if let first = workflow.dispatchMissingItems.first { return first }
        return "Complete required Dispatch items"
    }

    private var dispatchStatus: CVRDispatchStatus {
        workflow.state.activeDispatch?.status ?? .noDispatch
    }

    private var statusSubtitle: String {
        switch dispatchStatus {
        case .noDispatch:
            return "OPEN OR CREATE A FLIGHT ASSIGNMENT"
        case .dispatchIncomplete:
            return "COMPLETE THE HIGHLIGHTED BLOCKS"
        case .consentRequired:
            return "COMPLETE REQUIRED DISPATCH ITEMS"
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
        workflow.state.activeDispatch?.tailNumber.nilIfEmpty
            ?? settings.selectedAircraft?.registration
            ?? "None"
    }

    private var aircraftTileCaption: String? {
        if settings.selectedAircraft == nil { return "Tap to configure" }
        if workflow.dispatchTailMismatch(enrolledRegistration: settings.selectedAircraft?.registration) {
            return "Tap to fix alignment"
        }
        return "Locked to this unit"
    }

    private var aircraftTileAction: (() -> Void)? {
        if settings.selectedAircraft == nil {
            return { showAdminUnlock = true }
        }
        if workflow.dispatchTailMismatch(enrolledRegistration: settings.selectedAircraft?.registration) {
            return { _ = workflow.repairDispatchAircraftAlignment(selectedAircraft: settings.selectedAircraft) }
        }
        return nil
    }

    private var aircraftTileColor: Color {
        if workflow.dispatchTailMismatch(enrolledRegistration: settings.selectedAircraft?.registration) {
            return CVROperationalPalette.critical
        }
        return aircraftTile == "None" ? CVROperationalPalette.warning : CVROperationalPalette.success
    }

    private var crewNeedsEntry: Bool {
        guard let dispatch = workflow.state.activeDispatch else { return true }
        return dispatch.crew.isEmpty
    }

    private var crewTile: String {
        guard let dispatch = workflow.state.activeDispatch else { return "0 selected" }
        return dispatch.crew.isEmpty ? "0 selected" : "\(dispatch.crew.count) selected"
    }

    private var crewTileCaption: String? {
        guard workflow.state.activeDispatch != nil, !workflow.isDispatchLocked else { return nil }
        return crewNeedsEntry ? "Tap to add crew" : "Tap to edit crew"
    }

    private var crewTileColor: Color {
        crewNeedsEntry ? CVROperationalPalette.warning : CVROperationalPalette.success
    }

    private var metersNeedEntry: Bool {
        guard let dispatch = workflow.state.activeDispatch else { return true }
        return dispatch.startingHobbs == nil || dispatch.startingTacho == nil
    }

    private var meterTile: String {
        guard let dispatch = workflow.state.activeDispatch else { return "Starting meters required" }
        if dispatch.startingHobbs == nil || dispatch.startingTacho == nil {
            return "Starting meters required"
        }
        let hobbs = dispatch.startingHobbs.map { String(format: "H: %.1f", $0) } ?? "H: ?"
        let tacho = dispatch.startingTacho.map { String(format: "T: %.1f", $0) } ?? "T: ?"
        return "\(hobbs)\n\(tacho)"
    }

    private var meterTileCaption: String? {
        guard workflow.state.activeDispatch != nil, !workflow.isDispatchLocked else { return nil }
        return metersNeedEntry ? "Tap to enter" : "Tap to edit"
    }

    private var meterTileColor: Color {
        metersNeedEntry ? CVROperationalPalette.warning : CVROperationalPalette.success
    }

    private var fuelOilNeedEntry: Bool {
        guard let dispatch = workflow.state.activeDispatch else { return true }
        return dispatch.fuelOnboard.isEmpty || dispatch.effectiveStartingOilQuantity == nil
    }

    private var fuelTile: String {
        guard let dispatch = workflow.state.activeDispatch else { return "Fuel and oil required" }
        if dispatch.fuelOnboard.isEmpty || dispatch.effectiveStartingOilQuantity == nil {
            return "Fuel and oil required"
        }
        let fuel = Self.quantity(from: dispatch.fuelOnboard, unit: operationalConfig.fuelUnit)
            .map { "F: \(Self.quantityText($0)) \(operationalConfig.fuelUnit)" }
            ?? "F: ? \(operationalConfig.fuelUnit)"
        let oil = dispatch.effectiveStartingOilQuantity
            .map { "O: \(Self.quantityText($0)) \(dispatch.effectiveStartingOilUnit)" }
            ?? "O: ? \(operationalConfig.oilUnit)"
        return "\(fuel)\n\(oil)"
    }

    private var fuelTileCaption: String? {
        guard workflow.state.activeDispatch != nil, !workflow.isDispatchLocked else { return nil }
        return fuelOilNeedEntry ? "Tap to enter" : "Tap to edit"
    }

    private var fuelTileColor: Color {
        guard let dispatch = workflow.state.activeDispatch, !fuelOilNeedEntry else {
            return CVROperationalPalette.warning
        }
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

enum DispatchBlockEditor: String, Identifiable {
    case crew
    case meters
    case fuelOil

    var id: String { rawValue }

    var title: String {
        switch self {
        case .crew: return "Crew"
        case .meters: return "Meters"
        case .fuelOil: return "Fuel / Oil"
        }
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
    @EnvironmentObject private var coordinator: CVRUnitCoordinator
    @Binding var showAdminUnlock: Bool
    @State private var isShowingCheckIn = false
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
                                    inFlightControlPanel(metrics: metrics)
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
        .onAppear {
            if workflow.synthesizeEngineContinuityIfNeeded(gpsSample: gps.latestSample) {
                uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
            }
            Task {
                if workflow.state.operationalSession?.pendingSoftStartRecording == true
                    || workflow.state.engineSessionContinuityActive {
                    await coordinator.softStartRecordingIfAvionicsOn()
                }
            }
        }
        .sheet(isPresented: $isShowingCheckIn) {
            CheckInView()
                .environmentObject(workflow)
                .environmentObject(settings)
                .environmentObject(uploadManager)
                .environmentObject(gps)
                .environmentObject(coordinator)
                .presentationDetents([.large])
        }
    }

    private var avionicsReady: Bool {
        beacon.isSimulationOverrideActive || beacon.currentState == .avionicsOn || beacon.currentState == .temporarilyMissing
    }

    private var hasEngineStartEvent: Bool {
        offBlockEvent != nil
    }

    private var engineRunning: Bool {
        hasEngineStartEvent || workflow.state.engineSessionContinuityActive
    }

    private var hasTransientStopEvent: Bool {
        event("transient_stop_on_block") != nil
    }

    private var hasEngineShutdownEvent: Bool {
        onBlockEvent != nil
    }

    private var hasLegBoundaryEvent: Bool {
        hasEngineShutdownEvent || hasTransientStopEvent
    }

    private var hasShutdownVerificationEvent: Bool {
        event("shutdown_verification_completed") != nil
    }

    private var awaitingAvionicsOff: Bool {
        workflow.state.activeFlightRecord?.status == .awaitingAvionicsOff
            || workflow.state.operationalSession?.awaitingAvionicsOffConfirmation == true
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
        if awaitingAvionicsOff { return "AWAITING AVIONICS OFF" }
        if hasTransientStopEvent { return "TRANSIENT STOP RECORDED" }
        if hasEngineShutdownEvent { return "ENGINE SHUTDOWN RECORDED" }
        if hasEngineStartEvent { return "ENGINE RUNNING" }
        if workflow.state.engineSessionContinuityActive { return "ENGINE CONTINUITY" }
        return avionicsReady ? "READY FOR ENGINE START" : "STANDING BY"
    }

    private var inFlightSubtitle: String {
        if awaitingAvionicsOff { return "TURN AVIONICS OFF WHEN READY" }
        if hasTransientStopEvent { return "COMPLETE CHECK-IN FOR THIS LEG" }
        if hasEngineShutdownEvent { return "COMPLETE CHECK-IN, THEN AVIONICS OFF" }
        if hasEngineStartEvent { return "FLIGHT TIME" }
        if workflow.state.engineSessionContinuityActive { return "NO ENGINE START — CONTINUE THIS LEG" }
        return avionicsReady ? "HOLD ENGINE START 3 SECONDS" : "WAITING FOR AVIONICS POWER"
    }

    private var inFlightColor: Color {
        if awaitingAvionicsOff { return CVROperationalPalette.warning }
        if hasTransientStopEvent || hasEngineShutdownEvent { return CVROperationalPalette.success }
        if hasEngineStartEvent || workflow.state.engineSessionContinuityActive { return CVROperationalPalette.success }
        return avionicsReady ? CVROperationalPalette.secondaryBlue : CVROperationalPalette.standby
    }

    private func inFlightValue(now: Date) -> String? {
        guard engineRunning else { return nil }
        return elapsedText(seconds: gpsAirborneSeconds(now: now))
    }

    private var canOfferTransientStop: Bool {
        workflow.hasRemainingPlannedLegAfterCurrent
    }

    @ViewBuilder
    private func inFlightControlPanel(metrics: CVROperationalMetrics) -> some View {
        if awaitingAvionicsOff {
            CVROperationalWarningCard(
                title: "CHECK-IN SAVED",
                message: "Turn avionics OFF to finalize the recording and return to Schedule.",
                iconName: "powerplug.fill",
                color: CVROperationalPalette.warning
            )
        } else if hasTransientStopEvent {
            VStack(spacing: 8) {
                CVROperationalWarningCard(
                    title: "TRANSIENT STOP",
                    message: "Engine may keep running. Complete Check-In, then open the next leg from Schedule.",
                    iconName: "airplane.arrival",
                    color: CVROperationalPalette.secondaryBlue
                )
                CVROperationalActionButton(
                    title: hasShutdownVerificationEvent ? "EDIT CHECK-IN" : "CHECK-IN",
                    subtitle: "Tacho, Hobbs, Fuel, Destination, Takeoffs, Landings",
                    color: CVROperationalPalette.secondaryBlue
                ) {
                    workflow.beginTransientStopCheckIn()
                    isShowingCheckIn = true
                }
            }
        } else if hasEngineShutdownEvent {
            VStack(spacing: 8) {
                CVROperationalWarningCard(
                    title: hasShutdownVerificationEvent ? "CHECK-IN COMPLETE" : "ENGINE SHUTDOWN RECORDED",
                    message: hasShutdownVerificationEvent
                        ? "Turn avionics OFF when ready. Recording finalizes automatically."
                        : "Complete Check-In now, then turn avionics OFF.",
                    iconName: "checkmark.seal.fill",
                    color: CVROperationalPalette.success
                )
                if !hasShutdownVerificationEvent || workflow.canEditFlightClosure {
                    CVROperationalActionButton(
                        title: hasShutdownVerificationEvent ? "EDIT CHECK-IN" : "CHECK-IN",
                        subtitle: "Tacho, Hobbs, Fuel, Destination, Takeoffs, Landings",
                        color: CVROperationalPalette.secondaryBlue
                    ) {
                        workflow.beginEngineShutdownCheckIn()
                        isShowingCheckIn = true
                    }
                }
            }
        } else if engineRunning {
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
                    return true
                }
                takeoffLandingControls(metrics: metrics)
                if canOfferTransientStop {
                    CVRHoldActionButton(title: "TRANSIENT STOP", subtitle: "Hold 3 seconds — end leg, keep engine running", color: CVROperationalPalette.secondaryBlue) {
                        UIImpactFeedbackGenerator(style: .heavy).impactOccurred()
                        workflow.recordTransientStopOnBlock(gpsSample: gps.latestSample)
                        uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                        Task {
                            await coordinator.finalizeRecordingForLegBoundary(reason: "Transient stop leg boundary.")
                            workflow.beginTransientStopCheckIn()
                            isShowingCheckIn = true
                        }
                        return true
                    }
                }
                CVRHoldActionButton(title: "ENGINE SHUTDOWN", subtitle: "Hold 3 seconds for ON Block", color: CVROperationalPalette.critical) {
                    UIImpactFeedbackGenerator(style: .heavy).impactOccurred()
                    workflow.recordEngineShutdownOnBlock(gpsSample: gps.latestSample)
                    uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                    workflow.beginEngineShutdownCheckIn()
                    isShowingCheckIn = true
                    return true
                }
            }
        } else if avionicsReady && workflow.needsEngineStart {
            CVRHoldActionButton(title: "ENGINE START", subtitle: "Hold 3 seconds for OFF Block", color: CVROperationalPalette.success) {
                // Persist Off Block before UI confirmation flash / haptic.
                let saved = workflow.recordEngineStartOffBlock(gpsSample: gps.latestSample)
                guard saved else { return false }
                UIImpactFeedbackGenerator(style: .heavy).impactOccurred()
                uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                return true
            }
        } else if workflow.state.engineSessionContinuityActive {
            CVROperationalWarningCard(
                title: "CONTINUE THIS LEG",
                message: "Engine is already running from the previous leg. No Engine Start. Recording starts with avionics ON.",
                iconName: "flame.fill",
                color: CVROperationalPalette.secondaryBlue
            )
        } else {
            CVROperationalWarningCard(title: "WAITING FOR AVIONICS POWER", message: "Engine Start will appear when the paired beacon reports avionics power.", iconName: "timer", color: CVROperationalPalette.standby)
        }
    }

    private func takeoffLandingControls(metrics: CVROperationalMetrics) -> some View {
        HStack(spacing: metrics.spacing) {
            CVROperationalHoldTile(
                title: "TAKE OFFS",
                iconName: "airplane.departure",
                value: "\(operationCounts.displayTakeoffs)",
                subtitle: "Hold 2s to +1",
                color: operationCounts.displayTakeoffs > 0 ? CVROperationalPalette.success : CVROperationalPalette.standby,
                metrics: metrics,
                minimumDuration: 2,
                isEnabled: engineRunning && !hasLegBoundaryEvent
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
                isEnabled: engineRunning && !hasLegBoundaryEvent
            ) {
                UIImpactFeedbackGenerator(style: .medium).impactOccurred()
                workflow.recordManualLandingAdjustment(gpsSample: gps.latestSample)
                uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
            }
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
            case "engine_shutdown_on_block", "transient_stop_on_block":
                if let start = sessionStart {
                    total += max(0, event.timestampUTC.timeIntervalSince(start))
                    sessionStart = nil
                }
            default:
                break
            }
        }
        if let sessionStart, !hasLegBoundaryEvent {
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
    /// Returns true only after the operational change is persisted (or otherwise accepted).
    let action: () -> Bool
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
                // Confirm UI only after the action reports local persistence success.
                let accepted = action()
                guard accepted else {
                    withAnimation(.easeOut(duration: 0.15)) {
                        holdProgress = 0
                    }
                    return
                }
                confirmedFlash = true
                holdProgress = 1
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

private struct CheckInView: View {
    @EnvironmentObject private var workflow: CVRWorkflowStore
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var uploadManager: UploadManager
    @EnvironmentObject private var gps: GPSLocationManager
    @Environment(\.dismiss) private var dismiss
    var repairExistingClosureUpload = false
    @State private var endingHobbs = ""
    @State private var endingTacho = ""
    @State private var fuelGallons = 0.0
    @State private var hasFuelSelection = false
    @State private var destination = ""
    @State private var verifiedTakeoffs = 0
    @State private var verifiedLandings = 0
    @State private var comments = ""
    @State private var showSavedConfirmation = false
    @State private var localError = ""
    @FocusState private var focusedField: NumericField?

    private enum NumericField: Hashable {
        case hobbs
        case tacho
        case destination
    }

    private var operationalConfig: AircraftOperationalConfig {
        settings.selectedAircraft?.operationalConfig ?? .safeDefaults
    }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 12) {
                    Text(modeTitle)
                        .font(.caption.weight(.bold))
                        .foregroundStyle(CVROperationalPalette.textSecondary)
                    section("METERS") {
                        HStack(spacing: 10) {
                            largeMeterField("TACHO", text: $endingTacho, field: .tacho)
                            largeMeterField("HOBBS", text: $endingHobbs, field: .hobbs)
                        }
                        Text("Estimates until verified. Tacho ≈ start + Hobbs increment × 0.70")
                            .font(.caption2.weight(.semibold))
                            .foregroundStyle(CVROperationalPalette.textSecondary)
                    }
                    section("FUEL REMAINING") {
                        HStack {
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
                            Spacer(minLength: 0)
                        }
                    }
                    section("AIRPORT OF ARRIVAL") {
                        largeAirportField("ARR AD", text: $destination)
                    }
                    section("OPERATIONS") {
                        HStack(spacing: 12) {
                            operationStepper("TAKEOFFS", value: $verifiedTakeoffs)
                            operationStepper("LANDINGS", value: $verifiedLandings)
                        }
                    }
                    section("COMMENTS") {
                        TextField("Comments", text: $comments, axis: .vertical)
                            .lineLimit(3...6)
                            .padding(12)
                            .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 12))
                    }
                    if !localError.isEmpty || !workflow.lastError.isEmpty {
                        Text(localError.isEmpty ? workflow.lastError : localError)
                            .font(.caption.weight(.semibold))
                            .foregroundStyle(CVROperationalPalette.critical)
                    }
                    CVROperationalActionButton(
                        title: "SAVE CHECK-IN",
                        subtitle: modeSubtitle,
                        color: CVROperationalPalette.success
                    ) {
                        save()
                    }
                }
                .padding(16)
            }
            .background(CVROperationalPalette.background.ignoresSafeArea())
            .navigationTitle("Check-In")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Close") { dismiss() }
                }
                ToolbarItemGroup(placement: .keyboard) {
                    Spacer()
                    Button("Done") { focusedField = nil }
                }
            }
            .onAppear(perform: prefill)
            .alert("Leg stored safely on this device.", isPresented: $showSavedConfirmation) {
                Button("Continue") { dismiss() }
            } message: {
                Text("This leg is complete on the iPhone. You can continue the lesson.")
            }
        }
        .preferredColorScheme(.dark)
    }

    private var modeTitle: String {
        switch workflow.pendingCheckInMode {
        case .transientStop: return "TRANSIENT STOP CHECK-IN"
        case .engineShutdown: return "ENGINE SHUTDOWN CHECK-IN"
        case .none: return "CHECK-IN"
        }
    }

    private var modeSubtitle: String {
        switch workflow.pendingCheckInMode {
        case .transientStop: return "Store this leg, then return to Schedule"
        case .engineShutdown: return "Store this leg, then wait for avionics OFF"
        case .none: return "Verify meters and operations"
        }
    }

    private func prefill() {
        let flight = workflow.state.activeFlightRecord
        let dispatch = workflow.state.activeDispatch
        if let hobbs = flight?.endingHobbs {
            endingHobbs = String(format: "%.1f", hobbs)
        } else if let estimated = workflow.estimatedCheckInHobbs() {
            endingHobbs = String(format: "%.1f", estimated)
        } else if let start = dispatch?.startingHobbs {
            endingHobbs = String(format: "%.1f", start)
        }
        if let tacho = flight?.endingTacho {
            endingTacho = String(format: "%.1f", tacho)
        } else if let estimated = workflow.estimatedCheckInTacho() {
            endingTacho = String(format: "%.1f", estimated)
        } else if let start = dispatch?.startingTacho {
            endingTacho = String(format: "%.1f", start)
        }
        let fuelText = flight?.fuelRemaining ?? dispatch?.fuelOnboard ?? ""
        if let fuel = Self.quantity(from: fuelText, unit: operationalConfig.fuelUnit) {
            fuelGallons = min(max(fuel, 0), operationalConfig.fuelCapacity)
            hasFuelSelection = true
        } else {
            fuelGallons = 0
            hasFuelSelection = false
        }
        destination = (flight?.verifiedDestinationAirport
            ?? dispatch?.plannedDestinationAirport
            ?? "")
            .trimmingCharacters(in: .whitespacesAndNewlines)
            .uppercased()
        localError = ""
        if let flight {
            let counts = workflow.operationCounts(for: flight.id)
            verifiedTakeoffs = flight.verifiedTakeoffCount ?? counts.displayTakeoffs
            verifiedLandings = flight.verifiedLandingCount ?? counts.displayLandings
        }
        comments = flight?.checkInComments ?? flight?.maintenanceRemark ?? ""
    }

    private func save() {
        localError = ""
        guard hasFuelSelection else {
            localError = "Enter the fuel remaining."
            return
        }
        let hobbs = Double(endingHobbs.replacingOccurrences(of: ",", with: "."))
        let tacho = Double(endingTacho.replacingOccurrences(of: ",", with: "."))
        let saved = workflow.saveCheckInValues(
            endingHobbs: hobbs,
            endingTacho: tacho,
            fuelRemaining: Self.quantityText(fuelGallons),
            verifiedDestinationAirport: destination.trimmingCharacters(in: .whitespacesAndNewlines).uppercased(),
            verifiedTakeoffCount: verifiedTakeoffs,
            verifiedLandingCount: verifiedLandings,
            comments: comments,
            gpsSample: gps.latestSample,
            repairExistingClosureUpload: repairExistingClosureUpload
        )
        guard saved else { return }
        uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
        showSavedConfirmation = true
    }

    private func section<Content: View>(_ title: String, @ViewBuilder content: () -> Content) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            Text(title)
                .font(.caption.weight(.bold))
                .foregroundStyle(CVROperationalPalette.textSecondary)
            content()
        }
    }

    private func largeMeterField(_ title: String, text: Binding<String>, field: NumericField) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(title)
                .font(.caption.weight(.bold))
                .tracking(1.0)
                .foregroundStyle(CVROperationalPalette.secondaryBlue)
            TextField(title, text: text)
                .keyboardType(.decimalPad)
                .focused($focusedField, equals: field)
                .font(.system(size: 34, weight: .bold, design: .rounded).monospacedDigit())
                .padding(.vertical, 14)
                .padding(.horizontal, 12)
                .frame(maxWidth: .infinity, minHeight: 72, alignment: .leading)
                .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 14))
                .overlay(RoundedRectangle(cornerRadius: 14).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
        }
        .frame(maxWidth: .infinity)
    }

    private func largeAirportField(_ title: String, text: Binding<String>) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(title)
                .font(.caption.weight(.bold))
                .tracking(1.0)
                .foregroundStyle(CVROperationalPalette.secondaryBlue)
            TextField(title, text: text)
                .textInputAutocapitalization(.characters)
                .autocorrectionDisabled()
                .focused($focusedField, equals: .destination)
                .font(.system(size: 34, weight: .bold, design: .rounded).monospaced())
                .padding(.vertical, 14)
                .padding(.horizontal, 12)
                .frame(maxWidth: .infinity, minHeight: 72, alignment: .leading)
                .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 14))
                .overlay(RoundedRectangle(cornerRadius: 14).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
                .onChange(of: text.wrappedValue) { _, newValue in
                    let sanitized = CVRLocalDispatchDraft.sanitizeAirportInput(newValue)
                    if sanitized != newValue {
                        text.wrappedValue = sanitized
                    }
                }
        }
        .frame(maxWidth: .infinity)
    }

    private func operationStepper(_ title: String, value: Binding<Int>) -> some View {
        VStack(spacing: 8) {
            Text(title)
                .font(.caption2.weight(.bold))
                .foregroundStyle(CVROperationalPalette.textSecondary)
            HStack {
                Button { value.wrappedValue = max(0, value.wrappedValue - 1) } label: {
                    Image(systemName: "minus.circle.fill").font(.title2)
                }
                Text("\(value.wrappedValue)")
                    .font(.title2.weight(.bold).monospacedDigit())
                    .frame(minWidth: 36)
                Button { value.wrappedValue += 1 } label: {
                    Image(systemName: "plus.circle.fill").font(.title2)
                }
            }
            .foregroundStyle(CVROperationalPalette.secondaryBlue)
        }
        .frame(maxWidth: .infinity)
        .padding(12)
        .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 12))
    }

    private static func quantity(from value: String, unit: String) -> Double? {
        let cleaned = value
            .replacingOccurrences(of: unit, with: "", options: .caseInsensitive)
            .replacingOccurrences(of: "USG", with: "", options: .caseInsensitive)
            .trimmingCharacters(in: .whitespacesAndNewlines)
        return Double(cleaned.replacingOccurrences(of: ",", with: "."))
    }

    private static func quantityText(_ value: Double) -> String {
        String(format: "%.1f", value)
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
                                uploadManager.retryWorkflowSynchronization(workflow: workflow, settings: settings)
                            } else if allWorkflowComponentsVerified {
                                workflow.resetForNextFlightIfComplete()
                            } else {
                                uploadManager.retryWorkflowSynchronization(workflow: workflow, settings: settings)
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
            CheckInView(repairExistingClosureUpload: true)
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
    @State private var isShowingGarminAssignment = false
    @State private var isDirectGarminUpload = false
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
                            subtitle: "CHECK-IN IS LOCAL — SYNC PENDING UNTIL DISPATCH + AUDIO FINISH ONLINE",
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
                                title: "SYNC PENDING",
                                iconName: "arrow.triangle.2.circlepath",
                                value: "\(missingCount)",
                                color: missingCount > 0 ? CVROperationalPalette.secondaryBlue : CVROperationalPalette.standby,
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
                                title: activeWorkflowVerified ? "RETURN TO SCHEDULE" : "RETRY PENDING ITEMS",
                                subtitle: activeWorkflowVerified
                                    ? "This leg is complete on the device"
                                    : "Send remaining Dispatch and Check-In data when connected",
                                color: activeWorkflowVerified
                                    ? CVROperationalPalette.success
                                    : CVROperationalPalette.secondaryBlue
                            ) {
                                if activeWorkflowVerified {
                                    workflow.resetForNextFlightIfComplete()
                                    Task { await flightLogs.refresh(settings: settings) }
                                } else {
                                    syncPendingLogUploads()
                                }
                            }
                        }
                        if missingCount > 0 {
                            CVROperationalActionButton(
                                title: "SYNC NOW",
                                subtitle: "Upload queued Dispatch, Check-In, events, and cockpit audio",
                                color: CVROperationalPalette.secondaryBlue
                            ) {
                                syncPendingLogUploads()
                            }
                        }
                        CVROperationalActionButton(
                            title: flightLogs.isRefreshing ? "REFRESHING LOG" : "REFRESH FLIGHT LOG",
                            subtitle: "Reload online status only — does not start uploads",
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
                    syncPendingLogUploads()
                    await flightLogs.refresh(settings: settings)
                }
                if flightLogs.isUploading || flightLogs.isAdjusting {
                    uploadOverlay
                }
            }
        }
        .task {
            await flightLogs.refresh(settings: settings)
            if flightLogs.pendingGarminCSV != nil && directImportTarget == nil {
                isShowingGarminAssignment = true
            }
        }
        .onChange(of: flightLogs.pendingGarminCSV?.id) { _, pendingID in
            if pendingID == nil {
                isShowingGarminAssignment = false
            } else if directImportTarget == nil && !isShowingFileImporter && !isDirectGarminUpload {
                isShowingGarminAssignment = true
            }
        }
        .fileImporter(
            isPresented: $isShowingFileImporter,
            allowedContentTypes: [.commaSeparatedText],
            allowsMultipleSelection: false
        ) { result in
            switch result {
            case .success(let urls):
                guard let url = urls.first, flightLogs.stageGarminCSV(from: url) else {
                    isDirectGarminUpload = false
                    return
                }
                if let target = directImportTarget {
                    directImportTarget = nil
                    Task {
                        await flightLogs.uploadPendingGarminCSV(
                            to: target,
                            settings: settings,
                            uploadManager: uploadManager
                        )
                        isDirectGarminUpload = false
                    }
                } else {
                    isShowingGarminAssignment = true
                }
            case .failure(let error):
                isDirectGarminUpload = false
                directImportTarget = nil
                _ = error
            }
        }
        .sheet(isPresented: $isShowingGarminAssignment) {
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
                    "DISPATCH",
                    value: uploadStatusText(entry),
                    color: uploadStatusColor(entry)
                )
                logStatusValue(
                    "AUDIO",
                    value: audioStatusText(entry),
                    color: audioStatusColor(entry)
                )
                logStatusValue(
                    "TRANSCRIPT",
                    value: transcriptStatusText(entry),
                    color: transcriptStatusColor(entry)
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
                if logNeedsRetry(entry) {
                    Button {
                        retryLogUpload(entry)
                    } label: {
                        Label("RETRY", systemImage: "arrow.clockwise")
                    }
                    .foregroundStyle(CVROperationalPalette.critical)
                } else if logNeedsManualSync(entry) {
                    Button {
                        syncLogEntry(entry)
                    } label: {
                        Label("SYNC", systemImage: "arrow.triangle.2.circlepath")
                    }
                    .foregroundStyle(CVROperationalPalette.secondaryBlue)
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

    private func audioStatusText(_ entry: CVRFlightLogEntry) -> String {
        switch entry.audioUploadStatus?.lowercased() {
        case "uploaded", "complete": return "UPLOADED"
        case "failed": return "FAILED"
        case "uploading": return "UPLOADING"
        default: return "PENDING"
        }
    }

    private func audioStatusColor(_ entry: CVRFlightLogEntry) -> Color {
        switch entry.audioUploadStatus?.lowercased() {
        case "uploaded", "complete": return CVROperationalPalette.success
        case "failed": return CVROperationalPalette.critical
        case "uploading": return CVROperationalPalette.secondaryBlue
        default: return CVROperationalPalette.standby
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
            || entry.audioUploadStatus?.lowercased() == "failed"
            || entry.transcriptStatus?.lowercased() == "failed" {
            return ("FAILED", "exclamationmark.octagon.fill", CVROperationalPalette.critical)
        }
        if entry.serverUploadStatus?.lowercased() == "complete",
           (entry.audioUploadStatus?.lowercased() == "uploaded" || entry.audioUploadStatus?.lowercased() == "complete"),
           entry.transcriptStatus?.lowercased() == "ready" {
            return ("COMPLETE", "checkmark.seal.fill", CVROperationalPalette.success)
        }
        if entry.serverUploadStatus?.lowercased() == "complete" {
            return ("DISPATCH UPLOADED", "checkmark.icloud.fill", CVROperationalPalette.secondaryBlue)
        }
        // Check-In is the crew terminal state. Pending sync must not read as incomplete ops data.
        if isOperationallyCheckedIn(entry) {
            let syncing = ["uploading", "partial", "pending", "queued"].contains(
                entry.serverUploadStatus?.lowercased() ?? ""
            ) || ["uploading", "pending"].contains(entry.audioUploadStatus?.lowercased() ?? "")
                || ["transcribing", "queued", "pending"].contains(entry.transcriptStatus?.lowercased() ?? "")
            if syncing {
                return ("SYNCING", "arrow.triangle.2.circlepath", CVROperationalPalette.secondaryBlue)
            }
            return ("CHECKED IN", "checkmark.circle.fill", CVROperationalPalette.secondaryBlue)
        }
        return ("INCOMPLETE", "exclamationmark.triangle.fill", CVROperationalPalette.warning)
    }

    private func isOperationallyCheckedIn(_ entry: CVRFlightLogEntry) -> Bool {
        entry.endingHobbs != nil && entry.endingTacho != nil
    }

    private func logNeedsRetry(_ entry: CVRFlightLogEntry) -> Bool {
        entry.serverUploadStatus?.lowercased() == "failed"
            || entry.audioUploadStatus?.lowercased() == "failed"
            || entry.transcriptStatus?.lowercased() == "failed"
    }

    private func logNeedsManualSync(_ entry: CVRFlightLogEntry) -> Bool {
        guard isOperationallyCheckedIn(entry) else { return false }
        if logNeedsRetry(entry) { return false }
        let dispatchDone = entry.serverUploadStatus?.lowercased() == "complete"
        let audioDone = entry.audioUploadStatus?.lowercased() == "uploaded"
            || entry.audioUploadStatus?.lowercased() == "complete"
        let transcriptDone = entry.transcriptStatus?.lowercased() == "ready"
        return !(dispatchDone && audioDone && transcriptDone)
    }

    private func logFailureMessage(_ entry: CVRFlightLogEntry) -> String? {
        let message = (entry.transcriptError ?? entry.serverUploadError ?? "")
            .trimmingCharacters(in: .whitespacesAndNewlines)
        return message.isEmpty ? nil : message
    }

    private func syncPendingLogUploads() {
        workflow.requeueFailedUploads()
        workflow.requeueConnectivityFailedUploads()
        _ = recordingStore.requeueConnectivityFailedUploads()
        uploadManager.retryWorkflowSynchronization(workflow: workflow, settings: settings)

        let flightIDs = Set(displayEntries.map(\.flightRecordID))
        for recording in recordingStore.recordings {
            let linkedFlightID = recording.flightSessionID ?? ""
            guard flightIDs.contains(linkedFlightID) || flightIDs.contains(recording.id) else { continue }
            guard recording.uploadStatus != .uploaded else { continue }
            recordingStore.update(recording.id) {
                $0.nextUploadRetryAt = nil
                if $0.uploadStatus == .failed {
                    $0.uploadStatus = .pending
                    $0.lastError = ""
                }
            }
            uploadManager.upload(recordingID: recording.id, store: recordingStore, settings: settings)
        }

        Task {
            try? await Task.sleep(for: .seconds(4))
            await flightLogs.refresh(settings: settings)
        }
    }

    private func syncLogEntry(_ entry: CVRFlightLogEntry) {
        workflow.requeueFailedUploads(forFlightRecordID: entry.flightRecordID)
        uploadManager.retryWorkflowSynchronization(workflow: workflow, settings: settings)
        for recording in linkedRecordings(forFlightRecordID: entry.flightRecordID) {
            let needsFlightRelink = recording.flightSessionID != entry.flightRecordID
            recordingStore.update(recording.id) {
                $0.flightSessionID = entry.flightRecordID
                $0.nextUploadRetryAt = nil
                $0.uploadRetryCount = nil
                if $0.uploadStatus == .failed {
                    $0.uploadStatus = .pending
                    $0.lastError = ""
                }
            }
            if needsFlightRelink || recording.uploadStatus != .uploaded {
                uploadManager.upload(recordingID: recording.id, store: recordingStore, settings: settings)
            }
        }
        Task {
            try? await Task.sleep(for: .seconds(4))
            await flightLogs.refresh(settings: settings)
        }
    }

    private func retryLogUpload(_ entry: CVRFlightLogEntry) {
        syncLogEntry(entry)
        guard entry.transcriptStatus?.lowercased() == "failed" else { return }
        Task {
            try? await Task.sleep(for: .seconds(4))
            await flightLogs.retryServerProcessing(entry, settings: settings)
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
                                isShowingGarminAssignment = false
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
                    Button("Cancel") {
                        isShowingGarminAssignment = false
                        flightLogs.cancelPendingGarminCSV()
                    }
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
        if (merged.departureTime ?? "").isEmpty {
            merged.departureTime = existing.departureTime ?? candidate.departureTime
        }
        if (merged.arrivalTime ?? "").isEmpty {
            merged.arrivalTime = existing.arrivalTime ?? candidate.arrivalTime
        }
        merged.startingHobbs = merged.startingHobbs ?? existing.startingHobbs ?? candidate.startingHobbs
        merged.startingTacho = merged.startingTacho ?? existing.startingTacho ?? candidate.startingTacho
        merged.endingHobbs = merged.endingHobbs ?? existing.endingHobbs ?? candidate.endingHobbs
        merged.endingTacho = merged.endingTacho ?? existing.endingTacho ?? candidate.endingTacho
        merged.totalHobbsTime = merged.totalHobbsTime ?? existing.totalHobbsTime ?? candidate.totalHobbsTime
        if (merged.fuelRemaining ?? "").isEmpty {
            merged.fuelRemaining = existing.fuelRemaining ?? candidate.fuelRemaining
        }
        merged.endingOilPercentage = merged.endingOilPercentage
            ?? existing.endingOilPercentage
            ?? candidate.endingOilPercentage
        merged.endingOilQuantity = merged.endingOilQuantity
            ?? existing.endingOilQuantity
            ?? candidate.endingOilQuantity
        if (merged.endingOilUnit ?? "").isEmpty {
            merged.endingOilUnit = existing.endingOilUnit ?? candidate.endingOilUnit
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
        merged.audioUploadStatus = preferredAudioStatus(
            existing.audioUploadStatus,
            candidate.audioUploadStatus
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

    private func preferredAudioStatus(_ first: String?, _ second: String?) -> String? {
        let values = [first, second].compactMap { $0?.lowercased() }
        if values.contains("uploaded") || values.contains("complete") { return "uploaded" }
        return preferredStatus(first, second, success: "uploaded")
    }

    private func preferredStatus(_ first: String?, _ second: String?, success: String) -> String? {
        let values = [first, second].compactMap { $0?.lowercased() }
        if values.contains(success) { return success }
        if values.contains("uploading") { return "uploading" }
        if values.contains("transcribing") { return "transcribing" }
        if values.contains("queued") { return "queued" }
        if values.contains("failed") { return "failed" }
        if values.contains("pending") { return "pending" }
        if values.contains("partial") { return "partial" }
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
            .filter {
                $0.eventType == "engine_shutdown_on_block"
                    || $0.eventType == "transient_stop_on_block"
            }
            .max { $0.timestampLocal < $1.timestampLocal }
        let totalHobbs: Double? = if let start = dispatch.startingHobbs,
                                     let end = flightRecord.endingHobbs {
            end - start
        } else {
            nil
        }
        let calculatedArrival: Date? = if let stored = flightRecord.calculatedArrivalAt {
            stored
        } else if let departure,
                  let startHobbs = dispatch.startingHobbs,
                  let endHobbs = flightRecord.endingHobbs {
            departure.timestampLocal.addingTimeInterval(max(0, endHobbs - startHobbs) * 3600)
        } else {
            arrival?.timestampLocal
        }
        let crewDisplay = dispatch.crew.map { assignment in
            "\(assignment.personName) (\(assignment.role.label))"
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
            reservationUUID: dispatch.operationalIdentity?.reservationUUID,
            legUUID: dispatch.operationalIdentity?.legUUID,
            aircraftRegistration: dispatch.tailNumber,
            scheduledDate: Self.logDateFormatter.string(from: dispatch.scheduledDate),
            crewNames: crewDisplay.isEmpty ? dispatch.crew.map(\.personName) : crewDisplay,
            departureAirport: dispatch.plannedDepartureAirport,
            departureTime: departure.map { ISO8601DateFormatter().string(from: $0.timestampLocal) },
            arrivalAirport: flightRecord.verifiedDestinationAirport ?? dispatch.plannedDestinationAirport,
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
        displayEntries.filter {
            $0.serverUploadStatus?.lowercased() == "complete"
                && ($0.audioUploadStatus?.lowercased() == "uploaded" || $0.audioUploadStatus?.lowercased() == "complete")
        }.count
    }

    private var missingCount: Int {
        displayEntries.count - completeCount
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
    @State private var saveError = ""

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
                        if !saveError.isEmpty {
                            CVROperationalWarningCard(
                                title: "CANNOT SAVE ADJUSTMENT",
                                message: saveError,
                                iconName: "exclamationmark.triangle.fill",
                                color: CVROperationalPalette.critical
                            )
                        }
                        adjustmentField(
                            "DEPARTURE AIRPORT (OPTIONAL IF UNKNOWN)",
                            value: $departureAirport,
                            baseline: nil,
                            keyboard: .default
                        )
                        adjustmentField(
                            "ARRIVAL AIRPORT (OPTIONAL IF UNKNOWN)",
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
                        if let validationMessage {
                            saveError = validationMessage
                            return
                        }
                        saveError = ""
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
                            } else {
                                saveError = flightLogs.lastError.nilIfEmpty
                                    ?? "The server did not accept this adjustment."
                            }
                        }
                    }
                }
            }
        }
        .preferredColorScheme(.dark)
    }

    private var validationMessage: String? {
        let departure = departureAirport.trimmingCharacters(in: .whitespacesAndNewlines)
        let arrival = arrivalAirport.trimmingCharacters(in: .whitespacesAndNewlines)
        guard departure.isEmpty
                || departure.range(of: "^[A-Za-z0-9]{3,8}$", options: .regularExpression) != nil else {
            return "Enter a valid 3–8 character departure airport identifier."
        }
        guard arrival.isEmpty
                || arrival.range(of: "^[A-Za-z0-9]{3,8}$", options: .regularExpression) != nil else {
            return "Enter a valid 3–8 character arrival airport identifier."
        }
        guard crewNames.split(separator: ",").contains(where: {
            !$0.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
        }) else {
            return "Enter at least one crew member. Separate multiple names with commas."
        }
        guard let startHobbs = Double(startingHobbs),
              let startTacho = Double(startingTacho),
              startHobbs >= 0,
              startTacho >= 0 else {
            return "Starting Hobbs and Tacho must be valid non-negative values."
        }
        guard let endHobbs = Double(endingHobbs),
              let endTacho = Double(endingTacho),
              endHobbs >= startHobbs,
              endTacho >= startTacho else {
            return "Ending Hobbs and Tacho must be valid and cannot be lower than their starting values."
        }
        let numericFuel = fuelRemaining.components(
            separatedBy: CharacterSet(charactersIn: "0123456789.-").inverted
        ).joined()
        guard let fuel = Double(numericFuel), fuel >= 0 else {
            return "Fuel remaining must be a valid non-negative quantity."
        }
        return nil
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
    var focus: DispatchBlockEditor
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
                    switch focus {
                    case .crew:
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
                    case .meters:
                        editorSection("METERS") {
                            if workflow.state.activeDispatch?.dispatchSource == "verified_previous_flight_carryover" {
                                CVROperationalWarningCard(
                                    title: "VERIFIED PREVIOUS FLIGHT VALUES",
                                    message: "Starting Hobbs and Starting Tacho were prefilled from the latest locally archived flight for this aircraft after all server receipts were verified. Confirm the physical indications before dispatch.",
                                    iconName: "checkmark.icloud.fill",
                                    color: CVROperationalPalette.success
                                )
                            } else if workflow.state.activeDispatch?.dispatchSource == "previous_locally_closed_flight_carryover" {
                                CVROperationalWarningCard(
                                    title: "PREVIOUS FLIGHT VALUES SAVED ON THIS IPHONE",
                                    message: "Starting Hobbs and Starting Tacho were carried forward from the previous completed flight. Confirm the physical indications before dispatch.",
                                    iconName: "iphone.and.arrow.forward",
                                    color: CVROperationalPalette.secondaryBlue
                                )
                            }
                            HStack(spacing: 10) {
                                largeMeterField("TACHO", text: $startingTacho, field: .tacho)
                                largeMeterField("HOBBS", text: $startingHobbs, field: .hobbs)
                            }
                            if !meterContinuityMessages.isEmpty {
                                continuityBanner(meterContinuityMessages)
                            }
                        }
                    case .fuelOil:
                        editorSection("FUEL / OIL") {
                            if workflow.state.activeDispatch?.dispatchSource == "verified_previous_flight_carryover" {
                                CVROperationalWarningCard(
                                    title: "VERIFIED PREVIOUS FLIGHT VALUES",
                                    message: "Fuel and oil were prefilled from the latest locally archived flight for this aircraft after all server receipts were verified. Confirm the physical indications before dispatch.",
                                    iconName: "checkmark.icloud.fill",
                                    color: CVROperationalPalette.success
                                )
                            } else if workflow.state.activeDispatch?.dispatchSource == "previous_locally_closed_flight_carryover" {
                                CVROperationalWarningCard(
                                    title: "PREVIOUS FLIGHT VALUES SAVED ON THIS IPHONE",
                                    message: "Fuel and oil were carried forward from the previous completed flight. Confirm the physical indications before dispatch.",
                                    iconName: "iphone.and.arrow.forward",
                                    color: CVROperationalPalette.secondaryBlue
                                )
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
                            if !fuelOilContinuityMessages.isEmpty {
                                continuityBanner(fuelOilContinuityMessages)
                            }
                            if requiresRefuelConfirmation || workflow.dispatchContinuityUploadIssue() == .refueling {
                                operationalToggle("Aircraft was refueled before this flight", isOn: $refueledSincePreviousFlight)
                            }
                            if requiresOilServiceConfirmation || workflow.dispatchContinuityUploadIssue() == .oilServicing {
                                operationalToggle("Oil was serviced before this flight", isOn: $oilServicedSincePreviousFlight)
                            }
                        }
                    }
                }
                .padding(16)
            }
            .background(CVROperationalPalette.background.ignoresSafeArea())
            .navigationTitle(focus.title)
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
        }
    }

    private func continuityBanner(_ messages: [String]) -> some View {
        VStack(alignment: .leading, spacing: 7) {
            ForEach(messages, id: \.self) { message in
                Text(message)
                    .font(.caption.weight(.bold))
                    .foregroundStyle(CVROperationalPalette.warning)
            }
        }
        .padding(10)
        .background(CVROperationalPalette.warning.opacity(0.12), in: RoundedRectangle(cornerRadius: 10))
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

    private func largeMeterField(_ title: String, text: Binding<String>, field: NumericField) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(title)
                .font(.caption.weight(.bold))
                .tracking(1.0)
                .foregroundStyle(CVROperationalPalette.secondaryBlue)
            TextField(title, text: text)
                .keyboardType(.decimalPad)
                .focused($focusedNumericField, equals: field)
                .font(.system(size: 34, weight: .bold, design: .rounded).monospacedDigit())
                .padding(.vertical, 14)
                .padding(.horizontal, 12)
                .frame(maxWidth: .infinity, minHeight: 72, alignment: .leading)
                .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 14))
                .overlay(RoundedRectangle(cornerRadius: 14).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
        }
        .frame(maxWidth: .infinity)
    }

    private func load() {
        guard let dispatch = workflow.state.activeDispatch else { return }
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
            switch focus {
            case .crew:
                break
            case .meters:
                dispatch.startingHobbs = Double(startingHobbs)
                dispatch.startingTacho = Double(startingTacho)
            case .fuelOil:
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
        }
        if focus == .crew {
            // Crew mutations are applied immediately via add/edit/delete.
            dismiss()
            return
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

    private var meterContinuityMessages: [String] {
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
        return messages
    }

    private var fuelOilContinuityMessages: [String] {
        guard let dispatch = workflow.state.activeDispatch else { return [] }
        var messages: [String] = []
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

/// Scrollable mission list — replaces SwiftUI `Menu`, which blinks and fails to scroll
/// reliably with a large catalogue inside ScrollView/List parents.
private struct CVRMissionPickerSheet: View {
    @Environment(\.dismiss) private var dismiss

    let missions: [CVRMissionCatalogEntry]
    let selectedMissionCode: String
    let titleProvider: (CVRMissionCatalogEntry) -> String
    let onSelect: (String) -> Void

    @State private var query = ""

    private var filteredMissions: [CVRMissionCatalogEntry] {
        let trimmed = query.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else { return missions }
        return missions.filter {
            titleProvider($0).localizedCaseInsensitiveContains(trimmed)
                || $0.missionCode.localizedCaseInsensitiveContains(trimmed)
                || $0.missionDescription.localizedCaseInsensitiveContains(trimmed)
        }
    }

    var body: some View {
        NavigationStack {
            Group {
                if missions.isEmpty {
                    ContentUnavailableView(
                        "No Flight Missions",
                        systemImage: "list.bullet.rectangle",
                        description: Text("No flight missions are available on this device.")
                    )
                } else {
                    List {
                        ForEach(filteredMissions) { mission in
                            Button {
                                onSelect(mission.missionCode)
                                dismiss()
                            } label: {
                                HStack(alignment: .top, spacing: 12) {
                                    VStack(alignment: .leading, spacing: 4) {
                                        Text(titleProvider(mission))
                                            .font(.subheadline.weight(.semibold))
                                            .foregroundStyle(CVROperationalPalette.textPrimary)
                                            .multilineTextAlignment(.leading)
                                    }
                                    Spacer(minLength: 8)
                                    if mission.missionCode.caseInsensitiveCompare(selectedMissionCode) == .orderedSame {
                                        Image(systemName: "checkmark")
                                            .font(.body.weight(.bold))
                                            .foregroundStyle(CVROperationalPalette.secondaryBlue)
                                    }
                                }
                                .padding(.vertical, 4)
                            }
                            .buttonStyle(.plain)
                            .listRowBackground(CVROperationalPalette.cardBackground)
                        }
                    }
                    .listStyle(.plain)
                    .scrollContentBackground(.hidden)
                    .searchable(text: $query, prompt: "Search missions")
                }
            }
            .background(CVROperationalPalette.background.ignoresSafeArea())
            .navigationTitle("Select Flight Mission")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
            }
            .preferredColorScheme(.dark)
        }
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
