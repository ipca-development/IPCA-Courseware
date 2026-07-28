import SwiftUI

struct ContentView: View {
    @EnvironmentObject private var settings: SettingsStore
    @State private var adminPIN = ""
    @State private var adminUnlocked = false
    @State private var showAdminUnlock = false

    var body: some View {
        Group {
            if adminUnlocked {
                adminTabs
            } else {
                OperationalTabsView(adminUnlocked: $adminUnlocked, showAdminUnlock: $showAdminUnlock)
            }
        }
        .background(IPCATheme.pageBackground.ignoresSafeArea())
        .onChange(of: showAdminUnlock) { _, presented in
            if presented {
                adminPIN = ""
            }
        }
        .sheet(isPresented: $showAdminUnlock) {
            AdminUnlockView(adminPIN: $adminPIN, adminUnlocked: $adminUnlocked)
                .onChange(of: adminUnlocked) { _, unlocked in
                    if unlocked {
                        adminPIN = ""
                        showAdminUnlock = false
                    }
                }
        }
    }

    private var adminTabs: some View {
        TabView {
            StatusDashboardView(adminUnlocked: $adminUnlocked, showAdminUnlock: $showAdminUnlock)
                .tabItem {
                    Image(systemName: "waveform")
                        .font(.system(size: 12))
                    Text("Status")
                }

            AvionicsBeaconTestView()
                .tabItem {
                    Image(systemName: "antenna.radiowaves.left.and.right")
                        .font(.system(size: 12))
                    Text("Beacon Test")
                }

            AdminRecordingsView()
                .tabItem {
                    Image(systemName: "externaldrive")
                        .font(.system(size: 12))
                    Text("Recordings")
                }

            AdminSettingsView()
                .tabItem {
                    Image(systemName: "gearshape")
                        .font(.system(size: 12))
                    Text("Admin")
                }

            ExitAdminView(adminUnlocked: $adminUnlocked)
                .tabItem {
                    Image(systemName: "lock.fill")
                        .font(.system(size: 12))
                    Text("Exit Admin")
                }
        }
    }
}

private struct ExitAdminView: View {
    @Binding var adminUnlocked: Bool

    var body: some View {
        VStack(spacing: 18) {
            Image(systemName: "lock.shield.fill")
                .font(.system(size: 48))
                .foregroundStyle(IPCATheme.brightBlue)
            Text("Exit Admin Mode")
                .font(.title.weight(.bold))
                .foregroundStyle(IPCATheme.navy)
            Text("Lock admin tools and return to the public recorder status screen.")
                .font(.subheadline)
                .multilineTextAlignment(.center)
                .foregroundStyle(IPCATheme.secondaryText)
                .padding(.horizontal)
            Button("Exit Admin Mode", role: .destructive) {
                adminUnlocked = false
            }
            .buttonStyle(.borderedProminent)
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .background(IPCATheme.pageBackground.ignoresSafeArea())
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
                .foregroundStyle(IPCATheme.navy)

            SecureField("Admin PIN", text: $adminPIN)
                .textFieldStyle(.roundedBorder)
                .keyboardType(.numberPad)
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

            if !error.isEmpty {
                Text(error)
                    .foregroundStyle(IPCATheme.danger)
            }
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .background(IPCATheme.pageBackground.ignoresSafeArea())
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

    var body: some View {
        NavigationStack {
            Form {
                Section("Server") {
                    TextField("Courseware server URL", text: $settings.serverURL)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()
                    Toggle("Allow 5G/cellular upload", isOn: $settings.allowCellularUpload)
                }

                Section("CVR Device Enrollment") {
                    LabeledContent("Status", value: settings.deviceEnrollmentStatus)
                    TextField("One-time enrollment code", text: $settings.enrollmentCode)
                        .textInputAutocapitalization(.characters)
                        .autocorrectionDisabled()
                    Button("Enroll CVR Unit") {
                        Task {
                            await settings.enrollDevice()
                            if settings.deviceCredential != nil {
                                uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                            }
                        }
                    }
                    .disabled(settings.enrollmentCode.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
                    Text("Generate the code on IPCA.training under Master Logbook → Data Intake.")
                        .font(.caption)
                        .foregroundStyle(IPCATheme.secondaryText)
                    if !settings.deviceEnrollmentError.isEmpty {
                        Text(settings.deviceEnrollmentError)
                            .font(.caption)
                            .foregroundStyle(IPCATheme.danger)
                    }
                }

                Section("Dedicated Aircraft") {
                    Picker("Aircraft", selection: $settings.selectedAircraftID) {
                        Text("Not selected").tag(0)
                        ForEach(settings.aircraft) { aircraft in
                            Text(aircraft.label).tag(aircraft.id)
                        }
                    }
                    TextField("CVR Unit Identifier", text: $settings.cvrUnitIdentifier)
                        .textInputAutocapitalization(.characters)
                        .autocorrectionDisabled()
                    Button("Refresh Aircraft") {
                        Task {
                            await settings.refreshAircraft()
                            await settings.refreshCrewUsers()
                        }
                    }
                    if !settings.aircraftError.isEmpty {
                        Text(settings.aircraftError)
                            .foregroundStyle(IPCATheme.danger)
                    }
                }

                Section("Crew Users") {
                    LabeledContent("Loaded users", value: "\(settings.crewUsers.count)")
                    Button("Refresh Crew Users") {
                        Task { await settings.refreshCrewUsers() }
                    }
                    if !settings.crewUsersError.isEmpty {
                        Text(settings.crewUsersError)
                            .font(.caption)
                            .foregroundStyle(IPCATheme.danger)
                    }
                }

                Section("Avionics Beacon") {
                    Text("One-time setup for the avionics-power beacon. After connection is enabled, the iPhone listens for the assigned beacon identity and uses it to start and stop recording.")
                        .font(.caption)
                        .foregroundStyle(IPCATheme.secondaryText)

                    Button(settings.isBeaconTriggerEnabled ? "Beacon Connected" : "Connect Beacon") {
                        settings.isBeaconTriggerEnabled = true
                        beacon.startScan(scanAll: false)
                    }
                    .buttonStyle(.borderedProminent)
                    .disabled(settings.isBeaconTriggerEnabled)

                    Button("Disconnect Beacon Trigger", role: .destructive) {
                        settings.isBeaconTriggerEnabled = false
                        beacon.stopScan()
                    }
                    .disabled(!settings.isBeaconTriggerEnabled)

                    LabeledContent("Trigger") {
                        Text(settings.isBeaconTriggerEnabled ? "Enabled" : "Not connected")
                            .foregroundStyle(settings.isBeaconTriggerEnabled ? IPCATheme.success : IPCATheme.secondaryText)
                    }
                    LabeledContent("Beacon state") {
                        Text(operationalBeaconStatus.label)
                            .foregroundStyle(operationalBeaconStatusColor)
                    }
                    LabeledContent("Advertisements") {
                        Text("\(beacon.advertisementCount)")
                    }
                }

                Section("Audio") {
                    Button("Reset Audio Path") {
                        Task { await coordinator.resetAudioRoute(source: "admin UI") }
                    }
                    Text(audio.sourceSummary)
                    if audio.isInputGainSettable {
                        Slider(
                            value: Binding(
                                get: { Double(audio.inputGain) },
                                set: { audio.setInputGain(Float($0)) }
                            ),
                            in: 0...1
                        )
                        LabeledContent("Hardware input gain", value: "\(Int((audio.inputGain * 100).rounded()))%")
                    } else {
                        LabeledContent("Hardware input gain", value: "Not controllable by iOS")
                    }
                    Picker("Post recording gain", selection: $settings.postRecordingGainDB) {
                        Text("Off").tag(0.0)
                        Text("+3 dB").tag(3.0)
                        Text("+6 dB").tag(6.0)
                        Text("+9 dB").tag(9.0)
                        Text("+12 dB").tag(12.0)
                    }
                    Text("Post recording gain makes finalized files louder after capture. It cannot recover clipped or noisy input.")
                        .font(.caption)
                        .foregroundStyle(IPCATheme.secondaryText)
                }

                Section("GPS Time") {
                    LabeledContent("Status") {
                        Text(gpsStatusText)
                            .foregroundStyle(gpsStatusColor)
                    }
                    LabeledContent("Last UTC", value: gpsTimestampText)
                    if gps.state == .permissionNeeded {
                        Button("Request GPS Permission") {
                            gps.requestPermission()
                        }
                    }
                    if !gps.lastError.isEmpty {
                        Text(gps.lastError)
                            .font(.caption)
                            .foregroundStyle(IPCATheme.danger)
                    }
                }

                Section("Security") {
                    SecureField("Admin PIN", text: $settings.adminPIN)
                        .keyboardType(.numberPad)
                }
            }
            .navigationTitle("CVR Unit Admin")
        }
    }

    private var operationalBeaconStatus: AvionicsBeaconOperationalStatus {
        beacon.currentState.operationalStatus(secondsSinceLastAdvertisement: beacon.secondsSinceLastAdvertisement)
    }

    private var operationalBeaconStatusColor: Color {
        switch operationalBeaconStatus.severity {
        case .nominal:
            return IPCATheme.success
        case .warning:
            return IPCATheme.warning
        case .danger:
            return IPCATheme.danger
        case .inactive:
            return IPCATheme.secondaryText
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
            return IPCATheme.success
        case .permissionNeeded, .unavailable:
            return IPCATheme.warning
        case .denied, .failed:
            return IPCATheme.danger
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
