import SwiftUI

struct RecordingsView: View {
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var store: RecordingStore
    @EnvironmentObject private var uploadManager: UploadManager

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 16) {
                    IPCAHeader(
                        title: "Recordings",
                        subtitle: "Saved cockpit audio and flight data packages",
                        systemImage: "archivebox"
                    )

                if store.recordings.isEmpty {
                    ContentUnavailableView("No Recordings", systemImage: "waveform", description: Text("Record cockpit audio from the Recorder tab."))
                }

                ForEach(store.recordings) { recording in
                    NavigationLink {
                        RecordingDetailView(recordingID: recording.id)
                    } label: {
                        recordingRow(recording)
                    }
                    .swipeActions {
                        Button("Retry Upload") {
                            uploadManager.upload(recordingID: recording.id, store: store, settings: settings)
                        }
                        .disabled(uploadManager.activeUploads.contains(recording.id))
                    }
                }
                }
                .padding()
            }
            .background(IPCATheme.pageBackground.ignoresSafeArea())
            .navigationTitle("Recordings")
            .navigationBarTitleDisplayMode(.inline)
        }
    }

    private func recordingRow(_ recording: Recording) -> some View {
        IPCACard(title: recording.startedAt.formatted(date: .abbreviated, time: .shortened), systemImage: "waveform") {
            VStack(alignment: .leading, spacing: 10) {
                Text(recording.aircraftLabel)
                    .font(.subheadline.weight(.semibold))
                    .foregroundStyle(IPCATheme.navy)

                HStack(spacing: 8) {
                    IPCAStatusPill(text: Formatters.duration(recording.duration), color: IPCATheme.blue)
                    IPCAStatusPill(text: Formatters.bytes(recording.fileSize), color: IPCATheme.blue)
                    IPCAStatusPill(text: recording.statusLabel, color: statusColor(for: recording))
                    if recording.segmentIndex > 1 {
                        IPCAStatusPill(text: "SEG \(recording.segmentIndex)", color: IPCATheme.warning)
                    }
                    if recording.isTestRecording {
                        IPCAStatusPill(text: "TEST", color: IPCATheme.warning)
                    }
                }

                if recording.uploadStatus == .uploading {
                    ProgressView(value: recording.uploadProgress)
                        .tint(IPCATheme.brightBlue)
                }
            }
        }
    }

    private func statusColor(for recording: Recording) -> Color {
        if recording.uploadStatus == .failed || recording.transcriptStatus == .failed {
            return IPCATheme.danger
        }
        if recording.transcriptStatus == .ready {
            return IPCATheme.success
        }
        return IPCATheme.brightBlue
    }
}
