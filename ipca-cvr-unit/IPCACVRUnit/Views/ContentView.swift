import SwiftUI

struct ContentView: View {
    @EnvironmentObject private var settings: SettingsStore
    @State private var adminPIN = ""
    @State private var adminUnlocked = false

    var body: some View {
        TabView {
            StatusDashboardView(adminUnlocked: $adminUnlocked)
                .tabItem {
                    Label("Status", systemImage: "waveform")
                }

            AvionicsBeaconTestView()
                .tabItem {
                    Label("Beacon Test", systemImage: "antenna.radiowaves.left.and.right")
                }

            if adminUnlocked {
                AdminRecordingsView()
                    .tabItem {
                        Label("Recordings", systemImage: "externaldrive")
                    }

                AdminSettingsView()
                    .tabItem {
                        Label("Admin", systemImage: "gearshape")
                    }
            } else {
                AdminUnlockView(adminPIN: $adminPIN, adminUnlocked: $adminUnlocked)
                    .tabItem {
                        Label("Admin", systemImage: "lock")
                    }
            }
        }
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

    var body: some View {
        NavigationStack {
            Form {
                Section("Server") {
                    TextField("Courseware server URL", text: $settings.serverURL)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()
                    Toggle("Allow 5G/cellular upload", isOn: $settings.allowCellularUpload)
                }

                Section("Dedicated Aircraft") {
                    Picker("Aircraft", selection: $settings.selectedAircraftID) {
                        Text("Not selected").tag(0)
                        ForEach(settings.aircraft) { aircraft in
                            Text(aircraft.label).tag(aircraft.id)
                        }
                    }
                    Button("Refresh Aircraft") {
                        Task { await settings.refreshAircraft() }
                    }
                    if !settings.aircraftError.isEmpty {
                        Text(settings.aircraftError)
                            .foregroundStyle(IPCATheme.danger)
                    }
                }

                Section("Avionics Beacon") {
                    Text("One-time setup for the ESP-32 avionics-power beacon. After connection is enabled, the iPhone listens for the beacon and uses it to start and stop recording.")
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
}
