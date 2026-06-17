import SwiftUI

struct RecorderView: View {
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var store: RecordingStore
    @EnvironmentObject private var audio: AudioRecorderManager
    @EnvironmentObject private var uploadManager: UploadManager

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 20) {
                    inputCard
                    recordingCard
                    lastRecordingCard
                }
                .padding()
            }
            .navigationTitle("Cockpit Recorder")
            .toolbar {
                Button("Refresh Inputs") {
                    Task { await audio.refreshInputs() }
                }
            }
        }
    }

    private var inputCard: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text("Audio Input").font(.headline)
            LabeledContent("Selected input", value: audio.selectedInputName)
            HStack {
                Label(audio.isUSBActive ? "USB Audio Device active" : "USB Audio Device not active",
                      systemImage: audio.isUSBActive ? "checkmark.circle.fill" : "exclamationmark.triangle.fill")
                .foregroundStyle(audio.isUSBActive ? .green : .orange)
            }
            if audio.isInternalMicWarning {
                Text("Warning: recording would use the iPad internal microphone. Connect/select the USB audio interface before recording cockpit audio.")
                    .foregroundStyle(.red)
            }
            if !audio.lastError.isEmpty {
                Text(audio.lastError).foregroundStyle(.red)
            }
        }
        .padding()
        .background(.thinMaterial, in: RoundedRectangle(cornerRadius: 16))
    }

    private var recordingCard: some View {
        VStack(alignment: .leading, spacing: 16) {
            Text("Recording").font(.headline)
            LabeledContent("Status", value: statusText)
            LabeledContent("Elapsed", value: Formatters.duration(audio.elapsed))
            LabeledContent("File size", value: Formatters.bytes(audio.fileSize))
            LevelMeterView(level: audio.level)

            HStack {
                Button("Record") {
                    Task { _ = await audio.startRecording(language: settings.language) }
                }
                .buttonStyle(.borderedProminent)
                .disabled(audio.isRecording)

                Button("Pause Recording") {
                    audio.pauseRecording()
                }
                .buttonStyle(.bordered)
                .disabled(!audio.isRecording || audio.isPaused)

                Button("Resume Recording") {
                    audio.resumeRecording()
                }
                .buttonStyle(.bordered)
                .disabled(!audio.isRecording || !audio.isPaused)

                Button("Stop") {
                    stopAndUpload()
                }
                .buttonStyle(.borderedProminent)
                .tint(.red)
                .disabled(!audio.isRecording)
            }
        }
        .padding()
        .background(.thinMaterial, in: RoundedRectangle(cornerRadius: 16))
    }

    private var lastRecordingCard: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text("Last Recording Status").font(.headline)
            if let recording = store.recordings.first {
                LabeledContent("Recording", value: recording.id)
                LabeledContent("Upload", value: "\(recording.uploadStatus.label) \(Int(recording.uploadProgress * 100))%")
                LabeledContent("Transcript", value: "\(recording.transcriptStatus.label) \(recording.transcriptProgress)%")
                if !recording.lastError.isEmpty {
                    Text(recording.lastError).foregroundStyle(.red)
                }
            } else {
                Text("No recordings yet.").foregroundStyle(.secondary)
            }
        }
        .padding()
        .background(.thinMaterial, in: RoundedRectangle(cornerRadius: 16))
    }

    private var statusText: String {
        if audio.isRecording && audio.isPaused { return "Paused" }
        if audio.isRecording { return "Recording" }
        return "Idle"
    }

    private func stopAndUpload() {
        guard let recording = audio.stopRecording(language: settings.language) else { return }
        store.add(recording)
        uploadManager.upload(recordingID: recording.id, store: store, settings: settings)
    }
}

enum Formatters {
    static func duration(_ seconds: TimeInterval) -> String {
        let total = max(0, Int(seconds.rounded()))
        let h = total / 3600
        let m = (total % 3600) / 60
        let s = total % 60
        return h > 0 ? String(format: "%d:%02d:%02d", h, m, s) : String(format: "%d:%02d", m, s)
    }

    static func bytes(_ bytes: Int64) -> String {
        let value = Double(bytes)
        if bytes >= 1_073_741_824 { return String(format: "%.2f GB", value / 1_073_741_824) }
        if bytes >= 1_048_576 { return String(format: "%.1f MB", value / 1_048_576) }
        if bytes >= 1024 { return String(format: "%.1f KB", value / 1024) }
        return "\(bytes) B"
    }
}
