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
    @EnvironmentObject private var bluetooth: GarminBluetoothMonitor
    @EnvironmentObject private var coordinator: CVRUnitCoordinator
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

                Section("Garmin Bluetooth") {
                    TextField("G3X advertised name contains", text: $settings.garminG3XName)
                    TextField("GNX375 advertised name contains", text: $settings.garminGNX375Name)
                    TextField("Optional BLE service UUIDs, comma separated", text: $settings.garminServiceUUIDs)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()
                    Button("Refresh Bluetooth Monitor") {
                        bluetooth.refresh(settings: settings)
                    }
                    if !bluetooth.lastError.isEmpty {
                        Text(bluetooth.lastError)
                            .foregroundStyle(IPCATheme.warning)
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
}
