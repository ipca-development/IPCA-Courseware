import SwiftUI

struct AdminRecordingsView: View {
    @EnvironmentObject private var store: RecordingStore
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var uploadManager: UploadManager
    @EnvironmentObject private var network: NetworkMonitor

    var body: some View {
        NavigationStack {
            List {
                ForEach(store.recordings) { recording in
                    VStack(alignment: .leading, spacing: 8) {
                        HStack {
                            Text(recording.startedAt, style: .date)
                                .font(.headline)
                            Text(recording.startedAt, style: .time)
                                .font(.headline)
                            Spacer()
                            IPCAStatusPill(text: recording.statusLabel, color: statusColor(recording))
                        }

                        Text(recording.aircraftLabel)
                            .foregroundStyle(IPCATheme.secondaryText)

                        HStack {
                            Text("Duration \(format(duration: recording.duration))")
                            Text(ByteCountFormatter.string(fromByteCount: recording.fileSize, countStyle: .file))
                            Text(recording.inputDeviceName)
                        }
                        .font(.caption)
                        .foregroundStyle(IPCATheme.secondaryText)

                        ProgressView(value: recording.uploadProgress)
                            .tint(statusColor(recording))

                        if !recording.lastError.isEmpty {
                            Text(recording.lastError)
                                .font(.caption)
                                .foregroundStyle(recording.uploadStatus == .failed ? IPCATheme.danger : IPCATheme.secondaryText)
                        }

                        HStack {
                            Button("Retry Upload") {
                                uploadManager.upload(recordingID: recording.id, store: store, settings: settings)
                            }
                            .disabled(!network.canUpload(allowCellular: settings.allowCellularUpload))

                            Text(recording.filePath)
                                .font(.caption2)
                                .foregroundStyle(IPCATheme.secondaryText)
                                .lineLimit(1)
                        }
                    }
                    .padding(.vertical, 6)
                }
            }
            .navigationTitle("Permanent Recordings")
            .toolbar {
                Button("Upload Pending") {
                    uploadManager.uploadPending(store: store, settings: settings, network: network)
                }
                .disabled(!network.canUpload(allowCellular: settings.allowCellularUpload))
            }
        }
    }

    private func statusColor(_ recording: Recording) -> Color {
        if recording.uploadStatus == .uploaded && recording.transcriptStatus == .ready {
            return IPCATheme.success
        }
        if recording.uploadStatus == .failed || recording.transcriptStatus == .failed {
            return IPCATheme.danger
        }
        if recording.uploadStatus == .uploading || recording.transcriptStatus == .transcribing {
            return IPCATheme.brightBlue
        }
        return IPCATheme.warning
    }

    private func format(duration: TimeInterval) -> String {
        let total = Int(duration.rounded())
        let hours = total / 3600
        let minutes = (total % 3600) / 60
        let seconds = total % 60
        return String(format: "%02d:%02d:%02d", hours, minutes, seconds)
    }
}
