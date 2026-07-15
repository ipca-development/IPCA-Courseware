import AppKit
import SwiftUI

struct ContentView: View {
    @EnvironmentObject var state: AppStateController

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 18) {
                header
                connectionStatus
                syncStatus
                tokenSettings
                primaryActions
                if !state.repairSummary.isEmpty {
                    statusCard(title: "Repair Result") {
                        Text(state.repairSummary)
                            .font(.body)
                    }
                }
                if !state.lastError.isEmpty {
                    statusCard(title: "Last Error") {
                        Text(state.lastError)
                            .foregroundStyle(.red)
                    }
                }
            }
            .padding(24)
        }
        .frame(minWidth: 760, minHeight: 720)
    }

    private var header: some View {
        HStack {
            VStack(alignment: .leading) {
                Text("IPCA Sync Agent")
                    .font(.largeTitle.bold())
                Text("Permanent Garmin synchronization appliance")
                    .foregroundStyle(.secondary)
            }
            Spacer()
            Text(state.status.rawValue)
                .font(.headline)
                .padding(.horizontal, 14)
                .padding(.vertical, 8)
                .background(statusColor.opacity(0.15))
                .foregroundStyle(statusColor)
                .clipShape(Capsule())
        }
    }

    private var connectionStatus: some View {
        statusCard(title: "Connection Status") {
            grid([
                ("IPCA.training", state.ipcaStatus),
                ("Garmin", state.garminStatus),
                ("Browser", state.browser.browserStatus),
                ("Network", state.network.isOnline ? "Online" : "Offline")
            ])
        }
    }

    private var syncStatus: some View {
        statusCard(title: "Sync Status") {
            grid([
                ("Last successful sync", format(state.lastSuccessfulSync)),
                ("Next scheduled sync", format(state.nextScheduledSync)),
                ("New entries found", "\(state.newEntriesFound)"),
                ("Files downloaded", "\(state.filesDownloaded)"),
                ("Files uploaded", "\(state.filesUploaded)"),
                ("Pending uploads", "\(state.pendingUploads)")
            ])
        }
    }

    private var tokenSettings: some View {
        statusCard(title: "IPCA.training Device Token") {
            VStack(alignment: .leading, spacing: 10) {
                SecureField("Paste token once", text: $state.tokenInput)
                    .textFieldStyle(.roundedBorder)
                HStack {
                    Button("Save Token") { state.saveToken() }
                    Button("Validate Token") { Task { await state.validateToken() } }
                    Text("The full token is stored in Keychain and never shown again.")
                        .foregroundStyle(.secondary)
                }
            }
        }
    }

    private var primaryActions: some View {
        statusCard(title: "Controls") {
            VStack(alignment: .leading, spacing: 12) {
                HStack {
                    Button("Connect Garmin") { state.connectGarmin() }
                    Button("I’m on the Garmin Logbook") { state.confirmGarminLogbook() }
                    Button("Sync Now") { state.syncNow() }
                    Button("Reconnect Garmin") { state.reconnectGarmin() }
                }
                HStack {
                    Button("Repair Connection") { state.repairConnection() }
                    Button("Open Downloads") { state.artifactStore.openDownloads() }
                    Button("Open Logs") { LoggingService.shared.openLogs() }
                    Button("Quit") { NSApplication.shared.terminate(nil) }
                }
                Toggle("Launch at Login", isOn: Binding(
                    get: { state.launchAtLogin.isEnabled },
                    set: { enabled in
                        do { try state.launchAtLogin.setEnabled(enabled) }
                        catch { state.setError("Could not update Launch at Login.") }
                    }
                ))
                .toggleStyle(.switch)
                HStack {
                    Text("Sync interval")
                    Stepper("\(state.settings.syncIntervalMinutes) minutes", value: $state.settings.syncIntervalMinutes, in: 1...120)
                    Spacer()
                    Text("Retain uploads")
                    Stepper("\(state.settings.retainUploadedArtifactsDays) days", value: $state.settings.retainUploadedArtifactsDays, in: 1...365)
                }
            }
        }
    }

    private func statusCard<Content: View>(title: String, @ViewBuilder content: () -> Content) -> some View {
        VStack(alignment: .leading, spacing: 12) {
            Text(title).font(.title3.bold())
            content()
        }
        .padding(16)
        .background(Color(NSColor.controlBackgroundColor))
        .clipShape(RoundedRectangle(cornerRadius: 14))
    }

    private func grid(_ values: [(String, String)]) -> some View {
        LazyVGrid(columns: [GridItem(.adaptive(minimum: 210), spacing: 12)], alignment: .leading, spacing: 12) {
            ForEach(values, id: \.0) { item in
                VStack(alignment: .leading, spacing: 4) {
                    Text(item.0)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                    Text(item.1.isEmpty ? "Unknown" : item.1)
                        .font(.headline)
                }
                .padding(12)
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(Color(NSColor.windowBackgroundColor))
                .clipShape(RoundedRectangle(cornerRadius: 10))
            }
        }
    }

    private var statusColor: Color {
        switch state.status {
        case .connected, .idle: return .green
        case .syncing, .downloading, .uploading, .connecting, .verifyingGarmin: return .blue
        case .waitingForGarminLogin, .waitingForDeviceToken, .actionRequired, .garminAuthenticationRequired: return .orange
        case .error, .offline, .ipcaUnavailable: return .red
        case .notConfigured: return .gray
        }
    }

    private func format(_ date: Date?) -> String {
        guard let date else { return "Not yet" }
        return date.formatted(date: .abbreviated, time: .shortened)
    }
}

struct MenuBarContentView: View {
    @EnvironmentObject var state: AppStateController
    @Environment(\.openWindow) private var openWindow

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            Text("IPCA Sync Agent").font(.headline)
            Text("Status: \(state.status.rawValue)")
            Text("Garmin: \(state.garminStatus)")
            Text("Last Sync: \(state.lastSuccessfulSync?.formatted(date: .omitted, time: .shortened) ?? "Not yet")")
            Text("Pending Uploads: \(state.pendingUploads)")
            Divider()
            Button("Sync Now") { state.syncNow() }
            Button("Open Window") { openWindow(id: "main") }
            Button("Open Logs") { LoggingService.shared.openLogs() }
            Toggle("Launch at Login", isOn: Binding(
                get: { state.launchAtLogin.isEnabled },
                set: { enabled in try? state.launchAtLogin.setEnabled(enabled) }
            ))
            Divider()
            Button("Quit") { NSApplication.shared.terminate(nil) }
        }
        .padding()
        .frame(width: 280)
    }
}
