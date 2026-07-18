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

            PostflightWorkflowView()
                .tabItem {
                    Label("Postflight", systemImage: "checklist")
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

private struct PostflightWorkflowView: View {
    @EnvironmentObject private var store: RecordingStore
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var uploadManager: UploadManager
    @EnvironmentObject private var network: NetworkMonitor

    private var latestRecording: Recording? {
        store.recordings.max { lhs, rhs in
            lhs.startedAt < rhs.startedAt
        }
    }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 16) {
                    Text("Postflight")
                        .font(.largeTitle.weight(.bold))
                        .foregroundStyle(IPCATheme.navy)

                    Text("After avionics power is removed, the CVR Unit keeps the recording locally and uploads/transcribes automatically when internet access is available.")
                        .font(.subheadline)
                        .foregroundStyle(IPCATheme.secondaryText)

                    if let recording = latestRecording {
                        IPCACard(title: "Latest Recording", systemImage: "waveform.badge.checkmark") {
                            PostflightStatusLine(label: "Recording ID", value: shortID(recording.id), color: IPCATheme.secondaryText)
                            PostflightStatusLine(label: "Started", value: recording.startedAt.formatted(date: .abbreviated, time: .shortened), color: IPCATheme.navy)
                            PostflightStatusLine(label: "Duration", value: format(duration: recording.duration), color: IPCATheme.navy)
                            PostflightStatusLine(label: "Audio", value: recording.inputDeviceName, color: recording.inputDeviceName.localizedCaseInsensitiveContains("iPhone") ? IPCATheme.warning : IPCATheme.success)
                            PostflightStatusLine(label: "GPS", value: recording.gpsSamplesPath == nil ? "Not saved" : "Saved", color: recording.gpsSamplesPath == nil ? IPCATheme.warning : IPCATheme.success)
                            PostflightStatusLine(label: "Events", value: recording.recordingEventsPath == nil ? "Not saved" : "Saved", color: recording.recordingEventsPath == nil ? IPCATheme.secondaryText : IPCATheme.success)
                            PostflightStatusLine(label: "Upload", value: uploadLabel(for: recording), color: uploadColor(for: recording))
                            PostflightStatusLine(label: "Transcript", value: transcriptLabel(for: recording), color: transcriptColor(for: recording))
                        }

                        IPCACard(title: "Readiness", systemImage: "airplane.departure") {
                            PostflightStep(title: "Audio saved permanently", isComplete: recording.fileSize > 0, detail: ByteCountFormatter.string(fromByteCount: recording.fileSize, countStyle: .file))
                            PostflightStep(title: "GPS UTC evidence saved", isComplete: recording.gpsSamplesPath != nil, detail: recording.gpsSamplesPath == nil ? "No GPS sidecar" : "GPS sidecar ready for upload")
                            PostflightStep(title: "Operational events saved", isComplete: recording.recordingEventsPath != nil, detail: recording.recordingEventsPath == nil ? "No event sidecar" : "Event sidecar ready for upload")
                            PostflightStep(title: "Upload completed", isComplete: recording.uploadStatus == .uploaded, detail: uploadLabel(for: recording))
                            PostflightStep(title: "Transcript ready", isComplete: recording.transcriptStatus == .ready, detail: transcriptLabel(for: recording))
                            if let sourceGapSummary = recording.sourceGapSummary, !sourceGapSummary.isEmpty {
                                Text(sourceGapSummary)
                                    .font(.caption.weight(.semibold))
                                    .foregroundStyle(IPCATheme.danger)
                            }

                            Button(recording.uploadStatus == .uploaded ? "Upload Complete" : "Retry Upload Now") {
                                uploadManager.upload(recordingID: recording.id, store: store, settings: settings)
                            }
                            .buttonStyle(.borderedProminent)
                            .disabled(recording.uploadStatus == .uploaded || !network.canUpload(allowCellular: settings.allowCellularUpload))
                        }
                    } else {
                        IPCACard(title: "No Recording Yet", systemImage: "record.circle") {
                            Text("The first completed avionics-on event will appear here automatically.")
                                .foregroundStyle(IPCATheme.secondaryText)
                        }
                    }
                }
                .padding(16)
            }
            .background(IPCATheme.pageBackground.ignoresSafeArea())
            .navigationTitle("Postflight")
        }
    }

    private func uploadLabel(for recording: Recording) -> String {
        switch recording.uploadStatus {
        case .uploaded:
            return "Complete"
        case .uploading:
            return "\(Int((recording.uploadProgress * 100).rounded()))%"
        case .failed:
            if let next = recording.nextUploadRetryAt {
                let seconds = max(0, Int(next.timeIntervalSinceNow.rounded()))
                return "Failed · retrying in \(seconds)s"
            }
            return "Failed"
        case .pending:
            return "Pending"
        }
    }

    private func transcriptLabel(for recording: Recording) -> String {
        switch recording.transcriptStatus {
        case .ready:
            return "Complete"
        case .transcribing:
            return "\(recording.transcriptProgress)%"
        case .failed:
            return "Failed"
        case .pending:
            return "Pending"
        }
    }

    private func uploadColor(for recording: Recording) -> Color {
        switch recording.uploadStatus {
        case .uploaded: return IPCATheme.success
        case .uploading: return IPCATheme.brightBlue
        case .failed: return IPCATheme.danger
        case .pending: return IPCATheme.warning
        }
    }

    private func transcriptColor(for recording: Recording) -> Color {
        switch recording.transcriptStatus {
        case .ready: return IPCATheme.success
        case .transcribing: return IPCATheme.brightBlue
        case .failed: return IPCATheme.danger
        case .pending: return IPCATheme.warning
        }
    }

    private func format(duration: TimeInterval) -> String {
        let total = Int(duration.rounded())
        let hours = total / 3600
        let minutes = (total % 3600) / 60
        let seconds = total % 60
        return String(format: "%02d:%02d:%02d", hours, minutes, seconds)
    }

    private func shortID(_ value: String) -> String {
        String(value.prefix(8))
    }
}

private struct PostflightStatusLine: View {
    var label: String
    var value: String
    var color: Color

    var body: some View {
        HStack {
            Text(label)
                .font(.subheadline)
                .foregroundStyle(IPCATheme.secondaryText)
            Spacer()
            Text(value)
                .font(.subheadline.weight(.semibold))
                .foregroundStyle(color)
                .multilineTextAlignment(.trailing)
        }
    }
}

private struct PostflightStep: View {
    var title: String
    var isComplete: Bool
    var detail: String

    var body: some View {
        HStack(alignment: .top, spacing: 10) {
            Image(systemName: isComplete ? "checkmark.circle.fill" : "clock.fill")
                .foregroundStyle(isComplete ? IPCATheme.success : IPCATheme.warning)
            VStack(alignment: .leading, spacing: 3) {
                Text(title)
                    .font(.subheadline.weight(.semibold))
                    .foregroundStyle(IPCATheme.navy)
                Text(detail)
                    .font(.caption)
                    .foregroundStyle(IPCATheme.secondaryText)
            }
            Spacer()
        }
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
