import AppKit
import SwiftUI

struct ContentView: View {
    @EnvironmentObject var state: AppStateController

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 18) {
                header
                automaticSyncOverview
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
                    statusCard(title: messageTitle) {
                        Text(state.lastError)
                            .foregroundStyle(messageColor)
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

    private var automaticSyncOverview: some View {
        statusCard(title: "Automatic Sync") {
            HStack(alignment: .top, spacing: 18) {
                statusPill(
                    title: "Scheduler",
                    value: "On",
                    detail: "Checks Garmin every \(state.settings.syncIntervalMinutes) minutes."
                )
                statusPill(
                    title: "Next Check",
                    value: format(state.nextScheduledSync),
                    detail: state.nextScheduledSync == nil ? "Waiting for scheduler startup." : "No action needed."
                )
                statusPill(
                    title: "Queue",
                    value: state.pendingUploads == 0 ? "Empty" : "\(state.pendingUploads) pending",
                    detail: state.uploadProgressDetail
                )
            }
            Text(state.currentWorkDetail)
                .font(.callout)
                .foregroundStyle(.secondary)
        }
    }

    private var connectionStatus: some View {
        statusCard(title: "Connection Status") {
            grid([
                ("IPCA.training", state.ipcaStatus),
                ("Garmin Authentication", state.garminAuthenticationStatus),
                ("Garmin Cursor", state.garminCursorStatus),
                ("Garmin Sync", state.garminSyncStatus),
                ("Garmin Backfill", state.garminBackfillStatus),
                ("Browser", state.browser.browserStatus),
                ("Network", state.network.isOnline ? "Online" : "Offline"),
                ("Local Queue", state.queueStartupStatus)
            ])
        }
    }

    private var syncStatus: some View {
        statusCard(title: "Sync Status") {
            grid([
                ("Last successful sync", format(state.lastSuccessfulSync)),
                ("Next automatic check", format(state.nextScheduledSync)),
                ("New entries found", "\(state.newEntriesFound)"),
                ("Files downloaded", "\(state.filesDownloaded)"),
                ("Files uploaded", "\(state.filesUploaded)"),
                ("Pending uploads", "\(state.pendingUploads)"),
                ("Pause status", state.pauseStatus)
            ])
            VStack(alignment: .leading, spacing: 8) {
                Text("Current item")
                    .font(.caption)
                    .foregroundStyle(.secondary)
                ProgressView(value: Double(state.currentItemProgressPercent), total: 100)
                Text("Overall active run")
                    .font(.caption)
                    .foregroundStyle(.secondary)
                ProgressView(value: Double(state.overallProgressPercent), total: 100)
            }
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
                    Button("Sync Now") { state.syncNow() }
                    Button("Backfill Garmin History") { state.backfillGarminHistory() }
                        .disabled(state.isBackfillRunning)
                    Button("Pause") { state.requestPause() }
                        .disabled(state.pauseRequested || state.isPaused)
                    Button("Resume") { state.resumeTransfers() }
                        .disabled(!state.isPaused)
                }
                Text("Automatic checks run every \(state.settings.syncIntervalMinutes) minutes. You only need Sync Now for an immediate check.")
                    .font(.callout)
                    .foregroundStyle(.secondary)
                Toggle("Launch at Login", isOn: Binding(
                    get: { state.launchAtLogin.isEnabled },
                    set: { enabled in
                        do { try state.launchAtLogin.setEnabled(enabled) }
                        catch { state.setError("Could not update Launch at Login.") }
                    }
                ))
                .toggleStyle(.switch)
                DisclosureGroup("Advanced / Diagnostics") {
                    VStack(alignment: .leading, spacing: 12) {
                        HStack {
                            Button("I’m on the Garmin Logbook") { state.confirmGarminLogbook() }
                            Button("Reconnect Garmin") { state.reconnectGarmin() }
                            Button("Reload Garmin Logbook for Initial Sync") { state.reloadGarminForInitialSync() }
                            Button("Repair Connection") { state.repairConnection() }
                        }
                        HStack {
                            Button("Open Downloads") { state.artifactStore.openDownloads() }
                            Button("Open Logs") { LoggingService.shared.openLogs() }
                            Button("Open Garmin Diagnostic") { LoggingService.shared.openGarminDiagnostic() }
                            Button("Clear Logs") { LoggingService.shared.clearLogs() }
                            Button("Quit") { NSApplication.shared.terminate(nil) }
                        }
                        HStack {
                            Text("Sync interval")
                            Stepper("\(state.settings.syncIntervalMinutes) minutes", value: Binding(
                                get: { state.settings.syncIntervalMinutes },
                                set: { state.settings.syncIntervalMinutes = $0 }
                            ), in: 1...120)
                            Spacer()
                            Text("Retain uploads")
                            Stepper("\(state.settings.retainUploadedArtifactsDays) days", value: Binding(
                                get: { state.settings.retainUploadedArtifactsDays },
                                set: { state.settings.retainUploadedArtifactsDays = $0 }
                            ), in: 1...365)
                        }
                    }
                    .padding(.top, 8)
                }
            }
        }
    }

    private func statusPill(title: String, value: String, detail: String) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(title)
                .font(.caption)
                .foregroundStyle(.secondary)
            Text(value.isEmpty ? "Unknown" : value)
                .font(.title3.bold())
            Text(detail)
                .font(.caption)
                .foregroundStyle(.secondary)
                .lineLimit(2)
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(Color(NSColor.windowBackgroundColor))
        .clipShape(RoundedRectangle(cornerRadius: 12))
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

    private var messageTitle: String {
        switch state.status {
        case .syncing, .downloading, .uploading, .connecting, .verifyingGarmin:
            return "Current Activity"
        default:
            return "Last Error"
        }
    }

    private var messageColor: Color {
        switch state.status {
        case .syncing, .downloading, .uploading, .connecting, .verifyingGarmin:
            return .secondary
        case .error, .offline, .ipcaUnavailable:
            return .red
        default:
            return .primary
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
            Text("Garmin Auth: \(state.garminAuthenticationStatus)")
            Text("Garmin Cursor: \(state.garminCursorStatus)")
            Text("Garmin Sync: \(state.garminSyncStatus)")
            Text("Garmin Backfill: \(state.garminBackfillStatus)")
            Text("Last Sync: \(state.lastSuccessfulSync?.formatted(date: .omitted, time: .shortened) ?? "Not yet")")
            Text("Pending Uploads: \(state.pendingUploads)")
            Text("Pause: \(state.pauseStatus)")
            Text("Item: \(state.currentItemProgressPercent)%  Overall: \(state.overallProgressPercent)%")
            Text(state.uploadProgressDetail)
                .font(.caption)
                .foregroundStyle(.secondary)
            Text(state.currentWorkDetail)
                .font(.caption)
                .foregroundStyle(.secondary)
            Divider()
            Button("Sync Now") { state.syncNow() }
            Button("Backfill Garmin History") { state.backfillGarminHistory() }
                .disabled(state.isBackfillRunning)
            Button("Pause") { state.requestPause() }
                .disabled(state.pauseRequested || state.isPaused)
            Button("Resume") { state.resumeTransfers() }
                .disabled(!state.isPaused)
            Button("Reload Garmin Logbook for Initial Sync") { state.reloadGarminForInitialSync() }
            Button("Open Window") { openWindow(id: "main") }
            Button("Open Logs") { LoggingService.shared.openLogs() }
            Button("Clear Logs") { LoggingService.shared.clearLogs() }
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
