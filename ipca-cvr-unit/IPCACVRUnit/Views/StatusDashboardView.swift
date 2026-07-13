import SwiftUI

struct StatusDashboardView: View {
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var store: RecordingStore
    @EnvironmentObject private var audio: AudioRecorderManager
    @EnvironmentObject private var network: NetworkMonitor
    @EnvironmentObject private var system: SystemMonitor
    @EnvironmentObject private var beacon: AvionicsBeaconManager
    @EnvironmentObject private var remoteIPads: RemoteIPadLinkManager
    @EnvironmentObject private var coordinator: CVRUnitCoordinator
    @Binding var adminUnlocked: Bool

    private var activeUpload: Recording? {
        store.recordings.first { $0.uploadStatus == .uploading }
            ?? store.recordings.first { $0.needsUploadRetry }
            ?? store.recordings.first
    }

    var body: some View {
        ScrollView {
            VStack(spacing: 14) {
                header

                LazyVGrid(columns: [GridItem(.adaptive(minimum: 280), spacing: 14)], spacing: 14) {
                    IPCACard(title: "Power & Storage", systemImage: "battery.100") {
                        StatusLine(label: "Battery", value: "\(system.batteryLevelPercent)% \(system.batteryStateText)", color: batteryColor)
                        StatusLine(label: "Storage Available", value: system.storageText, color: system.availableStorageBytes > 2_000_000_000 ? IPCATheme.success : IPCATheme.warning)
                    }

                    IPCACard(title: "Instructor / Student iPads", systemImage: "ipad.landscape") {
                        StatusLine(label: "Connections", value: remoteIPads.instructorStudentConnectionText, color: IPCATheme.secondaryText)
                        Text("Future live warning/reset channel")
                            .font(.caption)
                            .foregroundStyle(IPCATheme.secondaryText)
                    }

                    IPCACard(title: "ESP-32 Beacon", systemImage: "antenna.radiowaves.left.and.right") {
                        StatusLine(label: "Trigger", value: settings.isBeaconTriggerEnabled ? "Connected" : "Not Connected", color: settings.isBeaconTriggerEnabled ? IPCATheme.success : IPCATheme.secondaryText)
                        StatusLine(label: "Scan", value: beacon.isScanning ? "Listening" : "Stopped", color: beacon.isScanning ? IPCATheme.success : IPCATheme.warning)
                        StatusLine(label: "State", value: operationalBeaconStatus.label, color: beaconStateColor)
                    }

                    IPCACard(title: "Recording", systemImage: "record.circle") {
                        StatusLine(label: "State", value: audio.isRecording ? "In Progress" : "Not Recording", color: audio.isRecording ? IPCATheme.danger : IPCATheme.secondaryText)
                        StatusLine(label: "Timer", value: format(duration: audio.elapsed), color: IPCATheme.navy)
                        ProgressView(value: Double(audio.level))
                            .tint(audio.recordingSignalActive ? IPCATheme.success : IPCATheme.warning)
                    }

                    IPCACard(title: "Audio Source", systemImage: "earbuds") {
                        StatusLine(label: "Input", value: audio.selectedInputName, color: audio.isAcceptedExternalInputActive ? IPCATheme.success : IPCATheme.danger)
                        StatusLine(label: "Route", value: audio.selectedInputPortType, color: audio.isUSBActive ? IPCATheme.success : IPCATheme.warning)
                        if audio.isInternalMicWarning {
                            Button("Reset Audio Path") {
                                remoteIPads.receiveResetAudioPathCommand(source: "local status screen")
                            }
                            .buttonStyle(.borderedProminent)
                            .tint(IPCATheme.warning)
                        }
                    }

                    IPCACard(title: "Internet & Upload", systemImage: "icloud.and.arrow.up") {
                        StatusLine(label: "Internet", value: network.statusText, color: network.canUpload(allowCellular: settings.allowCellularUpload) ? IPCATheme.success : IPCATheme.warning)
                        StatusLine(label: "Upload", value: percent(activeUpload?.uploadProgress ?? 0), color: uploadColor)
                        StatusLine(label: "Transcript", value: "\(activeUpload?.transcriptProgress ?? 0)%", color: transcriptColor)
                    }
                }

                if let warning = remoteIPads.latestWarning {
                    HStack(spacing: 10) {
                        Image(systemName: "exclamationmark.triangle.fill")
                        Text(warning.message)
                            .font(.headline)
                        Spacer()
                        Button("Reset Audio") {
                            remoteIPads.receiveResetAudioPathCommand(source: "local warning banner")
                        }
                        .buttonStyle(.bordered)
                    }
                    .padding()
                    .foregroundStyle(IPCATheme.danger)
                    .background(IPCATheme.danger.opacity(0.10), in: RoundedRectangle(cornerRadius: 16))
                }

                if adminUnlocked {
                    IPCACard(title: "Error Log", systemImage: "list.bullet.rectangle") {
                        ForEach(coordinator.eventLog.prefix(6), id: \.self) { line in
                            Text(line)
                                .font(.caption)
                                .foregroundStyle(IPCATheme.secondaryText)
                        }
                    }
                }
            }
            .padding(16)
        }
        .background(IPCATheme.pageBackground.ignoresSafeArea())
    }

    private var header: some View {
        HStack(alignment: .top) {
            VStack(alignment: .leading, spacing: 6) {
                Text("IPCA CVR Unit")
                    .font(.title.weight(.bold))
                    .foregroundStyle(.white)
                Text("Dedicated iPhone cockpit voice recorder")
                    .font(.subheadline.weight(.semibold))
                    .foregroundStyle(.white.opacity(0.82))
            }
            Spacer()
            IPCAStatusPill(text: coordinator.mode.rawValue, color: coordinator.mode == .recording ? IPCATheme.danger : IPCATheme.success)
        }
        .padding(20)
        .background(
            LinearGradient(
                colors: [IPCATheme.navy, IPCATheme.blue, IPCATheme.brightBlue.opacity(0.82)],
                startPoint: .topLeading,
                endPoint: .bottomTrailing
            ),
            in: RoundedRectangle(cornerRadius: 24)
        )
    }

    private var batteryColor: Color {
        system.batteryLevelPercent < 20 ? IPCATheme.danger : IPCATheme.success
    }

    private var uploadColor: Color {
        guard let activeUpload else { return IPCATheme.secondaryText }
        switch activeUpload.uploadStatus {
        case .uploaded: return IPCATheme.success
        case .uploading: return IPCATheme.brightBlue
        case .failed: return IPCATheme.danger
        case .pending: return IPCATheme.warning
        }
    }

    private var transcriptColor: Color {
        guard let activeUpload else { return IPCATheme.secondaryText }
        switch activeUpload.transcriptStatus {
        case .ready: return IPCATheme.success
        case .transcribing: return IPCATheme.brightBlue
        case .failed: return IPCATheme.danger
        case .pending: return IPCATheme.secondaryText
        }
    }

    private var beaconStateColor: Color {
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

    private var operationalBeaconStatus: AvionicsBeaconOperationalStatus {
        beacon.currentState.operationalStatus(secondsSinceLastAdvertisement: beacon.secondsSinceLastAdvertisement)
    }

    private func percent(_ value: Double) -> String {
        "\(Int((value * 100).rounded()))%"
    }

    private func format(duration: TimeInterval) -> String {
        let total = Int(duration.rounded())
        let hours = total / 3600
        let minutes = (total % 3600) / 60
        let seconds = total % 60
        return String(format: "%02d:%02d:%02d", hours, minutes, seconds)
    }
}

private struct StatusLine: View {
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
                .lineLimit(1)
                .minimumScaleFactor(0.75)
        }
    }
}
