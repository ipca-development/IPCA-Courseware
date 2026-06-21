import SwiftUI

struct RecorderView: View {
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var store: RecordingStore
    @EnvironmentObject private var audio: AudioRecorderManager
    @EnvironmentObject private var uploadManager: UploadManager
    @EnvironmentObject private var ahrsBLE: AHRSBLEManager

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 20) {
                    recordingCard
                    ahrsCard
                    inputCard
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
            Text("Audio Input Details").font(.headline)
            LabeledContent("Active recording input", value: audio.selectedInputName)
            LabeledContent("Active input type", value: audio.selectedInputPortType)
            LabeledContent("Preferred input", value: audio.preferredInputName)
            if audio.isInternalMicWarning {
                Text("Warning: the current audio route is the iPad microphone. Connect the USB interface, then tap Refresh Inputs before recording cockpit audio.")
                    .foregroundStyle(.red)
            }
            inputList
            if !audio.lastError.isEmpty {
                Text(audio.lastError).foregroundStyle(.red)
            }
        }
        .padding()
        .background(.thinMaterial, in: RoundedRectangle(cornerRadius: 16))
    }

    private var sourceBanner: some View {
        Label(audio.sourceSummary, systemImage: audio.isUSBActive ? "checkmark.circle.fill" : "exclamationmark.triangle.fill")
            .font(.title3.weight(.semibold))
            .foregroundStyle(audio.isUSBActive ? .green : .red)
            .padding(12)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background((audio.isUSBActive ? Color.green : Color.red).opacity(0.12), in: RoundedRectangle(cornerRadius: 12))
    }

    private var inputList: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("Detected Inputs").font(.subheadline.weight(.semibold))
            if audio.availableInputs.isEmpty {
                Text("No inputs reported. Tap Refresh Inputs after connecting the USB interface.")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
            ForEach(audio.availableInputs) { input in
                HStack {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(input.name)
                            .font(.subheadline)
                        Text(input.portType)
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                    Spacer()
                    if input.id == audio.selectedInputID {
                        Text("ACTIVE")
                            .font(.caption.weight(.bold))
                            .foregroundStyle(.blue)
                    }
                    if input.isUSB {
                        Text("USB")
                            .font(.caption.weight(.bold))
                            .foregroundStyle(.green)
                    }
                    if input.isBuiltInMic {
                        Text("IPAD MIC")
                            .font(.caption.weight(.bold))
                            .foregroundStyle(.orange)
                    }
                }
                .padding(8)
                .background(input.id == audio.selectedInputID ? Color.blue.opacity(0.08) : Color.clear, in: RoundedRectangle(cornerRadius: 8))
            }
        }
    }

    private var recordingCard: some View {
        VStack(alignment: .leading, spacing: 16) {
            Text("Recording").font(.headline)
            sourceBanner
            LabeledContent("Status", value: statusText)
            LabeledContent("Recording source", value: audio.selectedInputName)
            LabeledContent("Elapsed", value: Formatters.duration(audio.elapsed))
            LabeledContent("File size", value: Formatters.bytes(audio.fileSize))
            LevelMeterView(level: audio.level, peakLevel: audio.peakLevel)
            HStack {
                Text("Average \(Formatters.decibels(audio.averagePowerDB))")
                Spacer()
                Text("Peak \(Formatters.decibels(audio.peakPowerDB))")
            }
            .font(.caption)
            .foregroundStyle(.secondary)
            if audio.isRecording && audio.level < 0.02 && audio.peakLevel < 0.02 {
                Text("No meaningful input level detected yet. Check the USB interface gain, cabling, and selected input.")
                    .font(.caption)
                    .foregroundStyle(.orange)
            }

            LazyVGrid(columns: [GridItem(.adaptive(minimum: 180), spacing: 12)], spacing: 12) {
                Button("Record") {
                    Task {
                        let started = await audio.startRecording(language: settings.language)
                        if started, let recordingID = audio.activeRecordingID {
                            ahrsBLE.startCapture(recordingID: recordingID)
                        }
                    }
                }
                .buttonStyle(.borderedProminent)
                .disabled(audio.isRecording)
                .frame(maxWidth: .infinity)

                Button("Pause Recording") {
                    audio.pauseRecording()
                }
                .buttonStyle(.bordered)
                .disabled(!audio.isRecording || audio.isPaused)
                .frame(maxWidth: .infinity)

                Button("Resume Recording") {
                    audio.resumeRecording()
                }
                .buttonStyle(.bordered)
                .disabled(!audio.isRecording || !audio.isPaused)
                .frame(maxWidth: .infinity)

                Button("Stop") {
                    stopAndUpload()
                }
                .buttonStyle(.borderedProminent)
                .tint(.red)
                .disabled(!audio.isRecording)
                .frame(maxWidth: .infinity)
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
                LabeledContent("Input used", value: recording.inputDeviceName)
                LabeledContent("AHRS samples", value: recording.ahrsSamplesPath == nil ? "None saved" : "Saved")
                LabeledContent("Upload", value: "\(recording.uploadStatus.label) \(Int(recording.uploadProgress * 100))%")
                LabeledContent("Transcript", value: "\(recording.transcriptStatus.label) \(recording.transcriptProgress)%")
                if !recording.lastError.isEmpty {
                    Text(recording.lastError).foregroundStyle(.red)
                }
            } else {
                Text("No recordings yet.").foregroundStyle(.secondary)
            }
            if !settings.isServerURLConfigured {
                Text("Set the backend server URL in Settings before recording. Use the site origin only, for example https://courseware.example.com.")
                    .foregroundStyle(.orange)
            }
        }
        .padding()
        .background(.thinMaterial, in: RoundedRectangle(cornerRadius: 16))
    }

    private var ahrsCard: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text("BLE AHRS").font(.headline)
            LabeledContent("State", value: ahrsBLE.connectionState.rawValue)
            if let sample = ahrsBLE.latestSample {
                Grid(alignment: .leading, horizontalSpacing: 18, verticalSpacing: 8) {
                    GridRow {
                        Text("Roll")
                        Text(String(format: "%.1f", sample.roll)).foregroundStyle(.green)
                    }
                    GridRow {
                        Text("Pitch")
                        Text(String(format: "%.1f", sample.pitch)).foregroundStyle(.green)
                    }
                    GridRow {
                        Text("Yaw")
                        Text(String(format: "%.1f", sample.yaw)).foregroundStyle(.green)
                    }
                    GridRow {
                        Text("Acceleration")
                        Text(String(format: "%.2f", sample.acceleration)).foregroundStyle(.green)
                    }
                    GridRow {
                        Text("Mag heading")
                        Text(String(format: "%.0f", sample.magneticHeading)).foregroundStyle(.green)
                    }
                }
                Text(sample.rawLine)
                    .font(.caption)
                    .foregroundStyle(.secondary)
                    .textSelection(.enabled)
            } else {
                Text("Waiting for IPCA-CVR BLE AHRS data.")
                    .foregroundStyle(.secondary)
            }
            if !ahrsBLE.lastError.isEmpty {
                Text(ahrsBLE.lastError).foregroundStyle(.red)
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
        guard var recording = audio.stopRecording(language: settings.language) else { return }
        recording.ahrsSamplesPath = ahrsBLE.stopCaptureAndSave(recordingID: recording.id)
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

    static func decibels(_ value: Float) -> String {
        if value <= -159 {
            return "-inf dB"
        }
        return String(format: "%.1f dB", value)
    }
}
