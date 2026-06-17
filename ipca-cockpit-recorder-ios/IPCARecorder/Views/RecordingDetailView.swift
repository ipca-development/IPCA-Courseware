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
                    metadataCard(recording)
                    transcriptCard(recording)
                }
                .padding()
            } else {
                ContentUnavailableView("Recording Missing", systemImage: "questionmark.folder")
            }
        }
        .navigationTitle("Recording")
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
        VStack(alignment: .leading, spacing: 10) {
            Text("Details").font(.headline)
            LabeledContent("Recording ID", value: recording.id)
            if let serverID = recording.serverID {
                LabeledContent("Server ID", value: serverID)
            }
            LabeledContent("Started", value: recording.startedAt.formatted(date: .abbreviated, time: .standard))
            LabeledContent("Duration", value: Formatters.duration(recording.duration))
            LabeledContent("File size", value: Formatters.bytes(recording.fileSize))
            LabeledContent("Input device", value: recording.inputDeviceName)
            LabeledContent("Language", value: recording.language)
            LabeledContent("Upload", value: "\(recording.uploadStatus.label) \(Int(recording.uploadProgress * 100))%")
            LabeledContent("Transcript", value: "\(recording.transcriptStatus.label) \(recording.transcriptProgress)%")
            if !recording.lastError.isEmpty {
                Text(recording.lastError).foregroundStyle(.red)
            }
        }
        .padding()
        .background(.thinMaterial, in: RoundedRectangle(cornerRadius: 16))
    }

    private func transcriptCard(_ recording: Recording) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            Text("Transcript").font(.headline)
            if recording.transcript.isEmpty {
                Text("Transcript not ready.")
                    .foregroundStyle(.secondary)
            } else {
                Text(recording.transcript)
                    .textSelection(.enabled)
                    .frame(maxWidth: .infinity, alignment: .leading)
            }
        }
        .padding()
        .background(.thinMaterial, in: RoundedRectangle(cornerRadius: 16))
    }
}
