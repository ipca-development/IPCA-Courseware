import SwiftUI

struct RecordingsView: View {
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var store: RecordingStore
    @EnvironmentObject private var uploadManager: UploadManager

    var body: some View {
        NavigationStack {
            List {
                if store.recordings.isEmpty {
                    ContentUnavailableView("No Recordings", systemImage: "waveform", description: Text("Record cockpit audio from the Recorder tab."))
                }

                ForEach(store.recordings) { recording in
                    NavigationLink {
                        RecordingDetailView(recordingID: recording.id)
                    } label: {
                        VStack(alignment: .leading, spacing: 6) {
                            Text(recording.startedAt, style: .date)
                                .font(.headline)
                            Text(recording.startedAt, style: .time)
                                .foregroundStyle(.secondary)
                            HStack {
                                Text(Formatters.duration(recording.duration))
                                Text(Formatters.bytes(recording.fileSize))
                                Text(recording.statusLabel)
                            }
                            .font(.subheadline)
                            .foregroundStyle(.secondary)

                            if recording.uploadStatus == .uploading {
                                ProgressView(value: recording.uploadProgress)
                            }
                        }
                    }
                    .swipeActions {
                        Button("Retry Upload") {
                            uploadManager.upload(recordingID: recording.id, store: store, settings: settings)
                        }
                        .disabled(uploadManager.activeUploads.contains(recording.id))
                    }
                }
            }
            .navigationTitle("Recordings")
        }
    }
}
