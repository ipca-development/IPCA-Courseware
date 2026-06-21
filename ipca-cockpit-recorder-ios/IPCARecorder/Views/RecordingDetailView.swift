import SwiftUI

struct RecordingDetailView: View {
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var store: RecordingStore
    @EnvironmentObject private var uploadManager: UploadManager

    var recordingID: String

    private var recording: Recording? {
        store.recording(id: recordingID)
    }

    var body: some View {
        ScrollView {
            if let recording {
                VStack(alignment: .leading, spacing: 18) {
                    IPCAHeader(
                        title: "Recording Detail",
                        subtitle: recording.aircraftLabel,
                        systemImage: "doc.text.magnifyingglass"
                    )
                    metadataCard(recording)
                    transcriptCard(recording)
                }
                .padding()
            } else {
                ContentUnavailableView("Recording Missing", systemImage: "questionmark.folder")
            }
        }
        .background(IPCATheme.pageBackground.ignoresSafeArea())
        .navigationTitle("Recording")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            if let recording {
                Button("Retry Upload") {
                    uploadManager.upload(recordingID: recording.id, store: store, settings: settings)
                }
                .disabled(uploadManager.activeUploads.contains(recording.id))
            }
        }
    }

    private func metadataCard(_ recording: Recording) -> some View {
        IPCACard(title: "Details", systemImage: "info.circle") {
            LabeledContent("Recording ID", value: recording.id)
            if let serverID = recording.serverID {
                LabeledContent("Server ID", value: serverID)
            }
            LabeledContent("Started", value: recording.startedAt.formatted(date: .abbreviated, time: .standard))
            LabeledContent("Duration", value: Formatters.duration(recording.duration))
            LabeledContent("File size", value: Formatters.bytes(recording.fileSize))
            LabeledContent("Input device", value: recording.inputDeviceName)
            LabeledContent("Aircraft", value: recording.aircraftLabel)
            if let adsbHex = recording.aircraftADSBHex, !adsbHex.isEmpty {
                LabeledContent("ADS-B hex", value: adsbHex)
            }
            LabeledContent("AHRS samples", value: recording.ahrsSamplesPath == nil ? "None saved" : "Saved")
            LabeledContent("GPS samples", value: recording.gpsSamplesPath == nil ? "None saved" : "Saved")
            LabeledContent("Language", value: recording.language)
            LabeledContent("Upload", value: "\(recording.uploadStatus.label) \(Int(recording.uploadProgress * 100))%")
            LabeledContent("Transcript", value: "\(recording.transcriptStatus.label) \(recording.transcriptProgress)%")
            if !recording.lastError.isEmpty {
                Text(recording.lastError).foregroundStyle(IPCATheme.danger)
            }
        }
    }

    private func transcriptCard(_ recording: Recording) -> some View {
        IPCACard(title: "Transcript", systemImage: "text.quote") {
            if recording.transcript.isEmpty {
                Text("Transcript not ready.")
                    .foregroundStyle(.secondary)
            } else {
                Text(recording.transcript)
                    .textSelection(.enabled)
                    .frame(maxWidth: .infinity, alignment: .leading)
            }
        }
    }
}
