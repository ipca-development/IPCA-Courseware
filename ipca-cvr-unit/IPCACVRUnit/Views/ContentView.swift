import SwiftUI

struct ContentView: View {
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var crewMessages: CrewMessagesStore
    @State private var adminPIN = ""
    @State private var adminUnlocked = false
    @State private var showAdminUnlock = false
    @State private var adminTab: AdminTab = .status

    var body: some View {
        ZStack {
            OperationalTabsView(adminUnlocked: $adminUnlocked, showAdminUnlock: $showAdminUnlock)
                .allowsHitTesting(!adminUnlocked)
                .accessibilityHidden(adminUnlocked)

            if adminUnlocked {
                adminTabs
                    .transition(.opacity)
            }

            if let message = crewMessages.oldestUnacknowledgedMessage {
                CrewSystemMessageView(message: message) {
                    crewMessages.acknowledge(message)
                }
                .transition(.move(edge: .top).combined(with: .opacity))
                .zIndex(100)
            }
        }
        .animation(.easeInOut(duration: 0.2), value: adminUnlocked)
        .animation(.easeInOut(duration: 0.2), value: crewMessages.oldestUnacknowledgedMessage?.id)
        .background(
            (adminUnlocked ? CVROperationalPalette.background : IPCATheme.pageBackground)
                .ignoresSafeArea()
        )
        .onChange(of: settings.isSimulationModeEnabled) {
            if settings.isSimulationModeEnabled {
                adminUnlocked = false
            }
        }
        .onChange(of: adminUnlocked) {
            if adminUnlocked {
                adminTab = .status
            }
        }
        .onChange(of: showAdminUnlock) {
            if showAdminUnlock {
                adminPIN = ""
            }
        }
        .sheet(isPresented: $showAdminUnlock) {
            AdminUnlockView(adminPIN: $adminPIN, adminUnlocked: $adminUnlocked)
                .preferredColorScheme(.dark)
                .onChange(of: adminUnlocked) {
                    if adminUnlocked {
                        adminPIN = ""
                        showAdminUnlock = false
                    }
                }
        }
    }

    private var adminTabs: some View {
        VStack(spacing: 0) {
            Group {
                switch adminTab {
                case .status:
                    StatusDashboardView(adminUnlocked: $adminUnlocked, showAdminUnlock: $showAdminUnlock)
                case .beacon:
                    AvionicsBeaconTestView()
                case .recordings:
                    AdminRecordingsView()
                case .logs:
                    FlightLogView(adminUnlocked: true)
                case .flightHistory:
                    AdminWorkflowArchivesView()
                case .admin:
                    AdminSettingsView()
                case .exitAdmin:
                    ExitAdminView(adminUnlocked: $adminUnlocked)
                }
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity)

            AdminBottomTabBar(selectedTab: $adminTab)
        }
        .background(CVROperationalPalette.background.ignoresSafeArea())
        // Admin shell must stay dark like the operational workflow (app root forces light).
        .preferredColorScheme(.dark)
    }
}

private struct CrewSystemMessageView: View {
    var message: CVRCrewMessage
    var acknowledge: () -> Void

    var body: some View {
        VStack {
            VStack(alignment: .leading, spacing: 14) {
                HStack {
                    Label("SYSTEM MESSAGE", systemImage: "exclamationmark.triangle.fill")
                        .font(.headline.weight(.black))
                        .foregroundStyle(Color.yellow)
                    Spacer()
                    Text(message.sentAtUTC, format: .dateTime.hour().minute().second())
                        .font(.subheadline.monospacedDigit().weight(.semibold))
                        .foregroundStyle(Color.white.opacity(0.8))
                        .accessibilityLabel("Sent \(message.sentAtUTC.formatted(date: .omitted, time: .complete))")
                }

                Text(message.body)
                    .font(.title3.weight(.semibold))
                    .foregroundStyle(Color.white)
                    .fixedSize(horizontal: false, vertical: true)

                Button(action: acknowledge) {
                    Text("ACKNOWLEDGE")
                        .font(.headline.weight(.black))
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 12)
                }
                .buttonStyle(.borderedProminent)
                .tint(Color(red: 0.12, green: 0.47, blue: 0.92))
                .accessibilityHint("Dismisses this message and queues confirmation to the server")
            }
            .padding(18)
            .background(Color.black.opacity(0.96))
            .overlay {
                RoundedRectangle(cornerRadius: 14)
                    .stroke(Color.yellow.opacity(0.85), lineWidth: 2)
            }
            .clipShape(RoundedRectangle(cornerRadius: 14))
            .shadow(color: Color.black.opacity(0.5), radius: 14, y: 6)
            .padding(.horizontal, 12)
            .padding(.top, 8)

            Spacer(minLength: 0)
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .background(Color.black.opacity(0.48).ignoresSafeArea())
        .contentShape(Rectangle())
    }
}

private enum AdminTab: String, CaseIterable, Identifiable {
    case status
    case beacon
    case recordings
    case logs
    case flightHistory
    case admin
    case exitAdmin

    var id: String { rawValue }

    var title: String {
        switch self {
        case .status: return "Status"
        case .beacon: return "Beacon"
        case .recordings: return "Recordings"
        case .logs: return "Logs"
        case .flightHistory: return "History"
        case .admin: return "Admin"
        case .exitAdmin: return "Exit"
        }
    }

    var systemImage: String {
        switch self {
        case .status: return "waveform"
        case .beacon: return "antenna.radiowaves.left.and.right"
        case .recordings: return "externaldrive"
        case .logs: return "list.bullet.clipboard"
        case .flightHistory: return "archivebox"
        case .admin: return "gearshape"
        case .exitAdmin: return "lock.fill"
        }
    }
}

/// Matches the operational bottom menu: opaque black bar with readable icons.
private struct AdminBottomTabBar: View {
    @Binding var selectedTab: AdminTab

    var body: some View {
        HStack(spacing: 0) {
            ForEach(AdminTab.allCases) { tab in
                Button {
                    selectedTab = tab
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
                        selectedTab == tab
                            ? Color(red: 0.12, green: 0.47, blue: 0.92)
                            : Color.white.opacity(0.62)
                    )
                    .frame(maxWidth: .infinity)
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                .accessibilityLabel(tab.title)
                .accessibilityAddTraits(selectedTab == tab ? .isSelected : [])
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

private struct AdminWorkflowArchivesView: View {
    @EnvironmentObject private var workflow: CVRWorkflowStore

    var body: some View {
        NavigationStack {
            List {
                if workflow.archives.isEmpty {
                    ContentUnavailableView(
                        "No Archived Flights",
                        systemImage: "archivebox",
                        description: Text("Completed workflows will be retained here before NEXT FLIGHT clears the active screen.")
                    )
                    .foregroundStyle(CVROperationalPalette.textSecondary)
                    .cvrAdminListRowStyle()
                    .listRowInsets(EdgeInsets())
                } else {
                    ForEach(workflow.archives.sorted(by: { $0.archivedAt > $1.archivedAt })) { archive in
                        NavigationLink {
                            AdminWorkflowArchiveDetailView(archive: archive)
                        } label: {
                            VStack(alignment: .leading, spacing: 4) {
                                Text("\(archive.dispatch.tailNumber) · \(archive.dispatch.missionCode)")
                                    .font(.headline)
                                    .foregroundStyle(CVROperationalPalette.textPrimary)
                                Text(archive.archivedAt.formatted(date: .abbreviated, time: .shortened))
                                    .font(.caption)
                                    .foregroundStyle(CVROperationalPalette.textSecondary)
                                Text("\(archive.flightEvents.count) events · \(archive.status == .serverVerified ? "Server verified" : "Upload pending")")
                                    .font(.caption)
                                    .foregroundStyle(
                                        archive.status == .serverVerified
                                            ? CVROperationalPalette.success
                                            : CVROperationalPalette.warning
                                    )
                            }
                        }
                        .cvrAdminListRowStyle()
                    }
                }
            }
            .cvrAdminListChrome()
            .cvrAdminScreenChrome(title: "Flight History")
        }
    }
}

private struct AdminWorkflowArchiveDetailView: View {
    @EnvironmentObject private var workflow: CVRWorkflowStore
    let archive: CVRWorkflowArchiveRecord
    @State private var exportURL: URL?
    @State private var exportError = ""

    var body: some View {
        List {
            Section("Flight") {
                LabeledContent("Aircraft", value: archive.dispatch.tailNumber)
                LabeledContent("Mission", value: archive.dispatch.missionCode)
                LabeledContent("Flight Record", value: archive.flightRecordID)
                LabeledContent("Archived", value: archive.archivedAt.formatted(date: .abbreviated, time: .standard))
                LabeledContent("Recording Session", value: archive.flightRecord.recordingSessionID ?? "Not linked")
            }
            .cvrAdminListRowStyle()

            Section("Closure") {
                LabeledContent("Ending Hobbs", value: archive.flightRecord.endingHobbs.map { String(format: "%.1f", $0) } ?? "—")
                LabeledContent("Ending Tacho", value: archive.flightRecord.endingTacho.map { String(format: "%.1f", $0) } ?? "—")
                LabeledContent("Fuel Remaining", value: archive.flightRecord.fuelRemaining ?? "—")
                LabeledContent(
                    "Oil",
                    value: archive.flightRecord.effectiveEndingOilQuantity
                        .map { "\(String(format: "%.1f", $0)) \(archive.flightRecord.effectiveEndingOilUnit)" }
                        ?? "—"
                )
            }
            .cvrAdminListRowStyle()

            Section("Event Timeline") {
                ForEach(archive.flightEvents.sorted(by: { $0.timestampUTC < $1.timestampUTC })) { event in
                    VStack(alignment: .leading, spacing: 3) {
                        Text(event.eventType.replacingOccurrences(of: "_", with: " ").uppercased())
                            .font(.caption.weight(.bold))
                            .foregroundStyle(CVROperationalPalette.textPrimary)
                        Text(event.timestampUTC.formatted(date: .omitted, time: .standard))
                            .foregroundStyle(CVROperationalPalette.textSecondary)
                        if let offset = event.audioOffset {
                            Text(String(format: "Audio +%.1f s", offset))
                                .font(.caption)
                                .foregroundStyle(CVROperationalPalette.textSecondary)
                        }
                    }
                    .cvrAdminListRowStyle()
                }
            }

            Section("Export") {
                Button("Prepare JSON Export") {
                    do {
                        exportURL = try workflow.archiveExportURL(id: archive.id)
                        exportError = ""
                    } catch {
                        exportError = error.localizedDescription
                    }
                }
                .cvrAdminListRowStyle()
                if let exportURL {
                    ShareLink(item: exportURL) {
                        Label("Share Archive", systemImage: "square.and.arrow.up")
                    }
                    .cvrAdminListRowStyle()
                }
                if !exportError.isEmpty {
                    Text(exportError)
                        .foregroundStyle(CVROperationalPalette.critical)
                        .cvrAdminListRowStyle()
                }
            }
        }
        .cvrAdminListChrome()
        .cvrAdminScreenChrome(title: "Archived Flight")
    }
}

private struct ExitAdminView: View {
    @Binding var adminUnlocked: Bool
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    var body: some View {
        VStack(spacing: 18) {
            Image(systemName: "lock.shield.fill")
                .font(.system(size: 48))
                .foregroundStyle(CVROperationalPalette.secondaryBlue)
            Text("Exit Admin Mode")
                .font(.title.weight(.bold))
                .foregroundStyle(CVROperationalPalette.textPrimary)
            Text("Lock admin tools and return to the public recorder status screen.")
                .font(.subheadline)
                .multilineTextAlignment(.center)
                .foregroundStyle(CVROperationalPalette.textSecondary)
                .padding(.horizontal)
            Button("Exit Admin Mode", role: .destructive) {
                if reduceMotion {
                    adminUnlocked = false
                } else {
                    withAnimation(.easeOut(duration: 0.2)) {
                        adminUnlocked = false
                    }
                }
            }
            .buttonStyle(.borderedProminent)
            .tint(CVROperationalPalette.critical)
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .background(CVROperationalPalette.background.ignoresSafeArea())
        .preferredColorScheme(.dark)
    }
}

private struct AdminUnlockView: View {
    @EnvironmentObject private var settings: SettingsStore
    @Binding var adminPIN: String
    @Binding var adminUnlocked: Bool
    @State private var error = ""

    var body: some View {
        VStack(spacing: 18) {
            Text("Admin Access")
                .font(.largeTitle.weight(.bold))
                .foregroundStyle(CVROperationalPalette.textPrimary)

            SecureField("Admin PIN", text: $adminPIN)
                .keyboardType(.numberPad)
                .padding(12)
                .foregroundStyle(CVROperationalPalette.textPrimary)
                .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 12))
                .overlay(RoundedRectangle(cornerRadius: 12).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
                .frame(maxWidth: 260)

            Button("Unlock") {
                if adminPIN == settings.adminPIN {
                    adminUnlocked = true
                    error = ""
                } else {
                    error = "Incorrect PIN"
                }
            }
            .buttonStyle(.borderedProminent)
            .tint(CVROperationalPalette.primaryBlue)

            if !error.isEmpty {
                Text(error)
                    .foregroundStyle(CVROperationalPalette.critical)
            }
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .background(CVROperationalPalette.background.ignoresSafeArea())
        .preferredColorScheme(.dark)
    }
}

private struct AdminSettingsView: View {
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var coordinator: CVRUnitCoordinator
    @EnvironmentObject private var beacon: AvionicsBeaconManager
    @EnvironmentObject private var audio: AudioRecorderManager
    @EnvironmentObject private var gps: GPSLocationManager
    @EnvironmentObject private var workflow: CVRWorkflowStore
    @EnvironmentObject private var uploadManager: UploadManager
    @EnvironmentObject private var garminVault: GarminCsvVaultStore
    @EnvironmentObject private var scheduledSessions: ScheduledSessionsStore
    @EnvironmentObject private var garminSDCard: GarminSDCardImportCoordinator
    @State private var isShowingGarminSDCardFolderPicker = false
    @State private var isShowingGarminSDCardClearConfirmation = false
    @State private var hapticProbeStatus = ""

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 14) {
                    CVROperationalSectionCard(title: "Server", systemImage: "server.rack") {
                        VStack(alignment: .leading, spacing: 12) {
                            adminField("Courseware server URL", text: $settings.serverURL)
                                .textInputAutocapitalization(.never)
                                .autocorrectionDisabled()
                            Toggle("Allow 5G/cellular upload", isOn: $settings.allowCellularUpload)
                                .tint(CVROperationalPalette.primaryBlue)
                        }
                    }

                    CVROperationalSectionCard(title: "CVR Device Enrollment", systemImage: "iphone.and.arrow.forward") {
                        VStack(alignment: .leading, spacing: 12) {
                            adminLabeled("Status", settings.deviceEnrollmentStatus)
                            adminField("One-time enrollment code", text: $settings.enrollmentCode)
                                .textInputAutocapitalization(.characters)
                                .autocorrectionDisabled()
                            Button("Enroll CVR Unit") {
                                Task {
                                    if await settings.enrollDevice() {
                                        scheduledSessions.clearCache()
                                        await scheduledSessions.refresh(settings: settings)
                                        uploadManager.uploadQueuedWorkflowComponents(
                                            workflow: workflow,
                                            settings: settings,
                                            trigger: .enrollmentSucceeded
                                        )
                                    }
                                }
                            }
                            .buttonStyle(.borderedProminent)
                            .tint(CVROperationalPalette.primaryBlue)
                            .disabled(settings.enrollmentCode.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
                            Text("Generate the code on IPCA.training under Master Logbook → Data Intake.")
                                .font(.caption)
                                .foregroundStyle(CVROperationalPalette.textSecondary)
                            if !settings.deviceEnrollmentError.isEmpty {
                                Text(settings.deviceEnrollmentError)
                                    .font(.caption)
                                    .foregroundStyle(CVROperationalPalette.critical)
                            }
                        }
                    }

                    CVROperationalSectionCard(title: "Dedicated Aircraft", systemImage: "airplane") {
                        VStack(alignment: .leading, spacing: 12) {
                            Picker("Aircraft", selection: $settings.selectedAircraftID) {
                                Text("Not selected").tag(0)
                                ForEach(settings.aircraft) { aircraft in
                                    Text(aircraft.label).tag(aircraft.id)
                                }
                            }
                            .tint(CVROperationalPalette.secondaryBlue)
                            adminField("CVR Unit Identifier", text: $settings.cvrUnitIdentifier)
                                .textInputAutocapitalization(.characters)
                                .autocorrectionDisabled()
                            Button("Refresh Aircraft") {
                                Task {
                                    await settings.refreshAircraft()
                                    await settings.refreshCrewUsers()
                                }
                            }
                            .buttonStyle(.bordered)
                            if !settings.aircraftError.isEmpty {
                                Text(settings.aircraftError)
                                    .foregroundStyle(CVROperationalPalette.critical)
                            }
                        }
                    }

                    CVROperationalSectionCard(title: "Crew Users", systemImage: "person.2") {
                        VStack(alignment: .leading, spacing: 12) {
                            adminLabeled("Loaded users", "\(settings.crewUsers.count)")
                            Button("Refresh Crew Users") {
                                Task { await settings.refreshCrewUsers() }
                            }
                            .buttonStyle(.bordered)
                            if !settings.crewUsersError.isEmpty {
                                Text(settings.crewUsersError)
                                    .font(.caption)
                                    .foregroundStyle(CVROperationalPalette.critical)
                            }
                        }
                    }

                    CVROperationalSectionCard(title: "Garmin Local Vault", systemImage: "externaldrive") {
                        VStack(alignment: .leading, spacing: 12) {
                            Stepper(
                                "Retain synced CSVs: \(settings.garminVaultRetentionDays) days",
                                value: $settings.garminVaultRetentionDays,
                                in: 7...180
                            )
                            Stepper(
                                "Vault limit: \(settings.garminVaultMaxMegabytes) MB",
                                value: $settings.garminVaultMaxMegabytes,
                                in: 100...2000,
                                step: 50
                            )
                            adminLabeled("Local vault files", "\(garminVault.records.count)")
                            Text("Garmin CSV files attached from Log / AirDrop are retained locally for retry and cleanup according to these limits.")
                                .font(.caption)
                                .foregroundStyle(CVROperationalPalette.textSecondary)
                        }
                    }

                    CVROperationalSectionCard(title: "Garmin SD Card", systemImage: "sdcard") {
                        VStack(alignment: .leading, spacing: 12) {
                            if let info = settings.garminSDCardFolderDisplayInfo {
                                adminLabeled("Folder", info.folderName)
                                adminLabeled("Volume", info.volumeName)
                                if let configuredAt = info.configuredAt {
                                    adminLabeled("Configured", configuredAt.formatted(date: .abbreviated, time: .shortened))
                                }
                                if settings.bookmarkIsStale {
                                    Text("Access needs restoration — re-select the folder below.")
                                        .font(.caption)
                                        .foregroundStyle(CVROperationalPalette.warning)
                                }
                            } else {
                                Text("No Garmin folder configured yet. Select it once here, or from the Log tab, to enable SD card imports.")
                                    .font(.caption)
                                    .foregroundStyle(CVROperationalPalette.textSecondary)
                            }
                            HStack(spacing: 12) {
                                Button(settings.hasGarminSDCardFolderConfigured ? "Change Folder" : "Choose Folder") {
                                    isShowingGarminSDCardFolderPicker = true
                                }
                                .buttonStyle(.borderedProminent)
                                .tint(CVROperationalPalette.primaryBlue)
                                if settings.hasGarminSDCardFolderConfigured {
                                    Button("Clear", role: .destructive) {
                                        isShowingGarminSDCardClearConfirmation = true
                                    }
                                    .buttonStyle(.bordered)
                                }
                            }
                        }
                    }

                    CVROperationalSectionCard(title: "Simulation Demo", systemImage: "play.circle") {
                        VStack(alignment: .leading, spacing: 12) {
                            Toggle("Simulation Mode", isOn: $settings.isSimulationModeEnabled)
                                .tint(CVROperationalPalette.primaryBlue)
                            Text("Walk through Dispatch → Recorder → In-Flight → Garmin without audio logging or server uploads. Enabling simulation returns you to the operational tabs; use the bottom bar for avionics and takeoff/landing controls.")
                                .font(.caption)
                                .foregroundStyle(CVROperationalPalette.textSecondary)
                            if settings.isSimulationModeEnabled {
                                Button("Simulate Avionics ON") {
                                    beacon.simulateAvionicsOn()
                                }
                                .buttonStyle(.bordered)
                                Button("Simulate Avionics OFF") {
                                    beacon.simulateAvionicsOff()
                                }
                                .buttonStyle(.bordered)
                                Button("Reset Simulation Workflow", role: .destructive) {
                                    workflow.resetSimulationWorkflow {
                                        beacon.clearSimulationOverride()
                                    }
                                }
                                .buttonStyle(.borderedProminent)
                                .tint(CVROperationalPalette.critical)
                            }
                        }
                    }

                    CVROperationalSectionCard(title: "Avionics Beacon", systemImage: "antenna.radiowaves.left.and.right") {
                        VStack(alignment: .leading, spacing: 12) {
                            Text("One-time setup for the avionics-power beacon. After connection is enabled, the iPhone listens for the assigned beacon identity and uses it to start and stop recording.")
                                .font(.caption)
                                .foregroundStyle(CVROperationalPalette.textSecondary)

                            Button(settings.isBeaconTriggerEnabled ? "Beacon Connected" : "Connect Beacon") {
                                settings.isBeaconTriggerEnabled = true
                                beacon.startScan(scanAll: false)
                            }
                            .buttonStyle(.borderedProminent)
                            .tint(CVROperationalPalette.primaryBlue)
                            .disabled(settings.isBeaconTriggerEnabled)

                            Button("Disconnect Beacon Trigger", role: .destructive) {
                                settings.isBeaconTriggerEnabled = false
                                beacon.stopScan()
                            }
                            .buttonStyle(.borderedProminent)
                            .tint(CVROperationalPalette.critical)
                            .disabled(!settings.isBeaconTriggerEnabled)

                            adminLabeled(
                                "Trigger",
                                settings.isBeaconTriggerEnabled ? "Enabled" : "Not connected",
                                valueColor: settings.isBeaconTriggerEnabled
                                    ? CVROperationalPalette.success
                                    : CVROperationalPalette.textSecondary
                            )
                            adminLabeled("Beacon state", operationalBeaconStatus.label, valueColor: operationalBeaconStatusColor)
                            adminLabeled("Advertisements", "\(beacon.advertisementCount)")
                        }
                    }

                    if CVRHapticDiagnostics.isEnabled {
                        CVROperationalSectionCard(title: "Haptic Diagnostics", systemImage: "waveform.path") {
                            VStack(alignment: .leading, spacing: 12) {
                                Text("Diagnosis only. Not shown unless launch arg \(CVRHapticDiagnostics.launchArgument) or UserDefaults flag is set.")
                                    .font(.caption)
                                    .foregroundStyle(CVROperationalPalette.textSecondary)
                                let snap = CVRHapticDiagnostics.snapshot(
                                    recordingActive: audio.isRecording,
                                    usbInputActive: audio.isUSBActive,
                                    phase: "admin_live"
                                )
                                adminLabeled("supportsHaptics", "\(snap["supportsHaptics"] ?? "?")")
                                adminLabeled("allowHapticsDuringRecording", "\(snap["allowHapticsAndSystemSoundsDuringRecording"] ?? "?")")
                                adminLabeled("category", "\(snap["category"] ?? "?")")
                                adminLabeled("mode", "\(snap["mode"] ?? "?")")
                                adminLabeled("options", "\((snap["options"] as? [String])?.joined(separator: ", ") ?? "?")")
                                adminLabeled("recordingActive", "\(audio.isRecording)")
                                adminLabeled("usbInputActive", "\(audio.isUSBActive)")
                                Button("Fire Haptic Probe") {
                                    CVRHapticDiagnostics.logSnapshot(
                                        recordingActive: audio.isRecording,
                                        usbInputActive: audio.isUSBActive,
                                        phase: "admin_manual_probe"
                                    )
                                    CVRHaptics.impact(.heavy)
                                }
                                .buttonStyle(.borderedProminent)
                                .tint(CVROperationalPalette.warning)
                                Button("Run Automated Before/During/After Probe") {
                                    Task {
                                        hapticProbeStatus = "Running…"
                                        if let url = await CVRHapticDiagnostics.runAutomatedProbe(audio: audio) {
                                            hapticProbeStatus = "Wrote \(url.lastPathComponent)"
                                        } else {
                                            hapticProbeStatus = "Probe failed or diagnostics disabled"
                                        }
                                    }
                                }
                                .buttonStyle(.bordered)
                                if !hapticProbeStatus.isEmpty {
                                    Text(hapticProbeStatus)
                                        .font(.caption)
                                        .foregroundStyle(CVROperationalPalette.textSecondary)
                                }
                            }
                        }
                    }

                    CVROperationalSectionCard(title: "Audio", systemImage: "mic") {
                        VStack(alignment: .leading, spacing: 12) {
                            Button("Reset Audio Path") {
                                Task { await coordinator.resetAudioRoute(source: "admin UI") }
                            }
                            .buttonStyle(.bordered)
                            Text(audio.sourceSummary)
                                .foregroundStyle(CVROperationalPalette.textSecondary)
                            if audio.isInputGainSettable {
                                Slider(
                                    value: Binding(
                                        get: { Double(audio.inputGain) },
                                        set: { audio.setInputGain(Float($0)) }
                                    ),
                                    in: 0...1
                                )
                                .tint(CVROperationalPalette.primaryBlue)
                                adminLabeled("Hardware input gain", "\(Int((audio.inputGain * 100).rounded()))%")
                            } else {
                                adminLabeled("Hardware input gain", "Not controllable by iOS")
                            }
                            Picker("Post recording gain", selection: $settings.postRecordingGainDB) {
                                Text("Off").tag(0.0)
                                Text("+3 dB").tag(3.0)
                                Text("+6 dB").tag(6.0)
                                Text("+9 dB").tag(9.0)
                                Text("+12 dB").tag(12.0)
                            }
                            .tint(CVROperationalPalette.secondaryBlue)
                            Text("Post recording gain makes finalized files louder after capture. It cannot recover clipped or noisy input.")
                                .font(.caption)
                                .foregroundStyle(CVROperationalPalette.textSecondary)
                        }
                    }

                    CVROperationalSectionCard(title: "GPS Time", systemImage: "location") {
                        VStack(alignment: .leading, spacing: 12) {
                            adminLabeled("Status", gpsStatusText, valueColor: gpsStatusColor)
                            adminLabeled("Last UTC", gpsTimestampText)
                            if gps.state == .permissionNeeded {
                                Button("Request GPS Permission") {
                                    gps.requestPermission()
                                }
                                .buttonStyle(.borderedProminent)
                                .tint(CVROperationalPalette.primaryBlue)
                            }
                            if !gps.lastError.isEmpty {
                                Text(gps.lastError)
                                    .font(.caption)
                                    .foregroundStyle(CVROperationalPalette.critical)
                            }
                        }
                    }

                    CVROperationalSectionCard(title: "Security", systemImage: "lock") {
                        SecureField("Admin PIN", text: $settings.adminPIN)
                            .keyboardType(.numberPad)
                            .padding(12)
                            .foregroundStyle(CVROperationalPalette.textPrimary)
                            .background(Color.black.opacity(0.28), in: RoundedRectangle(cornerRadius: 10))
                            .overlay(RoundedRectangle(cornerRadius: 10).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
                    }
                }
                .padding(16)
                .padding(.bottom, 24)
            }
            .cvrAdminScreenChrome(title: "CVR Unit Admin")
        }
        .sheet(isPresented: $isShowingGarminSDCardFolderPicker) {
            GarminSDCardFolderPicker(
                onPick: { url in
                    isShowingGarminSDCardFolderPicker = false
                    garminSDCard.selectFolder(url, settings: settings)
                },
                onCancel: { isShowingGarminSDCardFolderPicker = false }
            )
        }
        .confirmationDialog(
            "Clear the configured Garmin folder?",
            isPresented: $isShowingGarminSDCardClearConfirmation,
            titleVisibility: .visible
        ) {
            Button("Clear Folder", role: .destructive) {
                garminSDCard.clearFolder(settings: settings)
            }
            Button("Cancel", role: .cancel) {}
        } message: {
            Text("IPCA will forget this folder. You will need to select it again before importing more Garmin CSV files.")
        }
    }

    private func adminField(_ title: String, text: Binding<String>) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(title)
                .font(.caption.weight(.semibold))
                .foregroundStyle(CVROperationalPalette.textSecondary)
            TextField(title, text: text)
                .padding(12)
                .foregroundStyle(CVROperationalPalette.textPrimary)
                .background(Color.black.opacity(0.28), in: RoundedRectangle(cornerRadius: 10))
                .overlay(RoundedRectangle(cornerRadius: 10).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
        }
    }

    private func adminLabeled(_ title: String, _ value: String, valueColor: Color = CVROperationalPalette.textPrimary) -> some View {
        HStack(alignment: .firstTextBaseline) {
            Text(title)
                .font(.subheadline)
                .foregroundStyle(CVROperationalPalette.textSecondary)
            Spacer(minLength: 12)
            Text(value)
                .font(.subheadline.weight(.semibold))
                .foregroundStyle(valueColor)
                .multilineTextAlignment(.trailing)
        }
    }

    private var operationalBeaconStatus: AvionicsBeaconOperationalStatus {
        beacon.currentState.operationalStatus(secondsSinceLastAdvertisement: beacon.secondsSinceLastAdvertisement)
    }

    private var operationalBeaconStatusColor: Color {
        switch operationalBeaconStatus.severity {
        case .nominal:
            return CVROperationalPalette.success
        case .warning:
            return CVROperationalPalette.warning
        case .danger:
            return CVROperationalPalette.critical
        case .inactive:
            return CVROperationalPalette.textSecondary
        }
    }

    private var gpsStatusText: String {
        switch gps.state {
        case .permissionNeeded:
            return "Permission Needed"
        case .ready:
            return "Ready"
        case .recording:
            return "Recording"
        case .denied:
            return "Denied"
        case .unavailable:
            return "Unavailable"
        case .failed:
            return "Failed"
        }
    }

    private var gpsStatusColor: Color {
        switch gps.state {
        case .ready, .recording:
            return CVROperationalPalette.success
        case .permissionNeeded, .unavailable:
            return CVROperationalPalette.warning
        case .denied, .failed:
            return CVROperationalPalette.critical
        }
    }

    private var gpsTimestampText: String {
        guard let sample = gps.latestSample else { return "--" }
        let formatter = ISO8601DateFormatter()
        formatter.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        formatter.timeZone = TimeZone(secondsFromGMT: 0)
        return formatter.string(from: sample.timestamp)
    }
}
