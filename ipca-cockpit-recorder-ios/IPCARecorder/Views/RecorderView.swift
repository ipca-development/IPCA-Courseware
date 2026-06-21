import SwiftUI

struct RecorderView: View {
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var store: RecordingStore
    @EnvironmentObject private var audio: AudioRecorderManager
    @EnvironmentObject private var uploadManager: UploadManager
    @EnvironmentObject private var ahrsBLE: AHRSBLEManager
    @EnvironmentObject private var gps: GPSLocationManager

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 20) {
                    IPCAHeader(
                        title: "Cockpit Recorder",
                        subtitle: "Audio, AHRS, GPS and training evidence capture",
                        systemImage: "airplane"
                    )
                    recordingCard
                    aircraftCard
                    ahrsCard
                    gpsCard
                    inputCard
                    lastRecordingCard
                }
                .padding()
            }
            .background(IPCATheme.pageBackground.ignoresSafeArea())
            .navigationTitle("Cockpit Recorder")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                Button("Refresh Inputs") {
                    Task { await audio.refreshInputs() }
                }
            }
            .onChange(of: gps.state.rawValue) { _, _ in
                sendGPSStatus()
            }
            .onChange(of: audio.level) { _, level in
                ahrsBLE.sendStatusCommand("AUDIOLEVEL=\(Int(max(0, min(1, level)) * 100))")
            }
            .onChange(of: audio.isUSBActive) { _, isUSBActive in
                ahrsBLE.sendStatusCommand(isUSBActive ? "AUDIO=1" : "AUDIO=0")
            }
            .onChange(of: latestUploadStatusKey) { _, _ in
                sendUploadStatus()
            }
            .onChange(of: latestTranscriptStatusKey) { _, _ in
                sendTranscriptStatus()
            }
            .task {
                if settings.isServerURLConfigured {
                    await settings.refreshAircraft()
                }
            }
        }
    }

    private var aircraftCard: some View {
        IPCACard(title: "Aircraft", systemImage: "airplane.circle") {
            if settings.aircraft.isEmpty {
                Text("No active aircraft loaded. Add aircraft in the Courseware admin page, then refresh.")
                    .foregroundStyle(.secondary)
            } else {
                Picker("Selected aircraft", selection: $settings.selectedAircraftID) {
                    Text("Not selected").tag(0)
                    ForEach(settings.aircraft) { aircraft in
                        Text(aircraft.label).tag(aircraft.id)
                    }
                }
                if let selected = settings.selectedAircraft {
                    LabeledContent("Registration", value: selected.registration)
                    LabeledContent("ADS-B hex", value: selected.adsbHex.isEmpty ? "Missing" : selected.adsbHex)
                    if !selected.homeAirport.isEmpty {
                        LabeledContent("Home airport", value: selected.homeAirport)
                    }
                } else {
                    Text("Select the aircraft before recording so the server can attach ownship ADS-B data later.")
                        .foregroundStyle(.orange)
                }
            }
            if !settings.aircraftError.isEmpty {
                Text(settings.aircraftError).foregroundStyle(.red)
            }
            Button("Refresh Aircraft") {
                Task { await settings.refreshAircraft() }
            }
            .disabled(!settings.isServerURLConfigured)
        }
    }

    private var inputCard: some View {
        IPCACard(title: "Audio Input Details", systemImage: "cable.connector") {
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
    }

    private var sourceBanner: some View {
        Label(audio.sourceSummary, systemImage: audio.isUSBActive ? "checkmark.circle.fill" : "exclamationmark.triangle.fill")
            .font(.title3.weight(.semibold))
            .foregroundStyle(audio.isUSBActive ? IPCATheme.success : IPCATheme.danger)
            .padding(12)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background((audio.isUSBActive ? IPCATheme.success : IPCATheme.danger).opacity(0.12), in: RoundedRectangle(cornerRadius: 12))
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
                        IPCAStatusPill(text: "ACTIVE", color: IPCATheme.brightBlue)
                    }
                    if input.isUSB {
                        IPCAStatusPill(text: "USB", color: IPCATheme.success)
                    }
                    if input.isBuiltInMic {
                        IPCAStatusPill(text: "IPAD MIC", color: IPCATheme.warning)
                    }
                }
                .padding(8)
                .background(input.id == audio.selectedInputID ? IPCATheme.lightBlue.opacity(0.35) : Color.clear, in: RoundedRectangle(cornerRadius: 8))
            }
        }
    }

    private var recordingCard: some View {
        IPCACard(title: "Recording", systemImage: "record.circle") {
            sourceBanner
            LabeledContent("Status", value: statusText)
            LabeledContent("Aircraft", value: settings.selectedAircraft?.label ?? "Not selected")
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
                    .foregroundStyle(IPCATheme.warning)
            }

            LazyVGrid(columns: [GridItem(.adaptive(minimum: 180), spacing: 12)], spacing: 12) {
                Button("Record") {
                    Task {
                        let started = await audio.startRecording(language: settings.language)
                        if started, let recordingID = audio.activeRecordingID, let startedAt = audio.activeRecordingStartedAt {
                            ahrsBLE.startCapture(recordingID: recordingID, startedAt: startedAt)
                            gps.startCapture(recordingID: recordingID, startedAt: startedAt)
                            ahrsBLE.sendStatusCommand("REC=1")
                            ahrsBLE.sendStatusCommand("UPLOAD=WAIT")
                            ahrsBLE.sendStatusCommand("TX=WAIT")
                            ahrsBLE.sendStatusCommand(audio.isUSBActive ? "AUDIO=1" : "AUDIO=0")
                            sendGPSStatus()
                        }
                    }
                }
                .buttonStyle(.borderedProminent)
                .tint(IPCATheme.brightBlue)
                .disabled(audio.isRecording)
                .frame(maxWidth: .infinity)

                Button("Pause Recording") {
                    audio.pauseRecording()
                    ahrsBLE.sendStatusCommand("REC=PAUSE")
                }
                .buttonStyle(.bordered)
                .tint(IPCATheme.blue)
                .disabled(!audio.isRecording || audio.isPaused)
                .frame(maxWidth: .infinity)

                Button("Resume Recording") {
                    audio.resumeRecording()
                    ahrsBLE.sendStatusCommand("REC=1")
                }
                .buttonStyle(.bordered)
                .tint(IPCATheme.blue)
                .disabled(!audio.isRecording || !audio.isPaused)
                .frame(maxWidth: .infinity)

                Button("Stop") {
                    stopAndUpload()
                }
                .buttonStyle(.borderedProminent)
                .tint(IPCATheme.danger)
                .disabled(!audio.isRecording)
                .frame(maxWidth: .infinity)
            }
        }
    }

    private var lastRecordingCard: some View {
        IPCACard(title: "Last Recording Status", systemImage: "checklist") {
            if let recording = store.recordings.first {
                LabeledContent("Recording", value: recording.id)
                LabeledContent("Input used", value: recording.inputDeviceName)
                LabeledContent("Aircraft", value: recording.aircraftLabel)
                LabeledContent("AHRS samples", value: recording.ahrsSamplesPath == nil ? "None saved" : "Saved")
                LabeledContent("GPS samples", value: recording.gpsSamplesPath == nil ? "None saved" : "Saved")
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
                    .foregroundStyle(IPCATheme.warning)
            }
        }
    }

    private var gpsCard: some View {
        IPCACard(title: "GPS", systemImage: "location.circle") {
            LabeledContent("State", value: gps.state.rawValue)
            if let sample = gps.latestSample {
                Grid(alignment: .leading, horizontalSpacing: 18, verticalSpacing: 8) {
                    GridRow {
                        Text("Latitude")
                        Text(String(format: "%.6f", sample.latitude)).foregroundStyle(IPCATheme.success)
                    }
                    GridRow {
                        Text("Longitude")
                        Text(String(format: "%.6f", sample.longitude)).foregroundStyle(IPCATheme.success)
                    }
                    GridRow {
                        Text("Altitude")
                        Text(String(format: "%.1f m", sample.altitude)).foregroundStyle(IPCATheme.success)
                    }
                    GridRow {
                        Text("Speed")
                        Text(String(format: "%.1f kt", sample.speedKnots)).foregroundStyle(IPCATheme.success)
                    }
                    GridRow {
                        Text("Course")
                        Text(sample.course >= 0 ? String(format: "%.0f", sample.course) : "Unavailable").foregroundStyle(IPCATheme.success)
                    }
                    GridRow {
                        Text("Accuracy")
                        Text(String(format: "H %.0f m / V %.0f m", sample.horizontalAccuracy, sample.verticalAccuracy)).foregroundStyle(.secondary)
                    }
                }
            } else {
                Text("GPS is optional. Samples are saved during recording when location is available.")
                    .foregroundStyle(.secondary)
            }
            if !gps.lastError.isEmpty {
                Text(gps.lastError).foregroundStyle(.red)
            }
        }
    }

    private var ahrsCard: some View {
        IPCACard(title: "BLE AHRS", systemImage: "gyroscope") {
            LabeledContent("State", value: ahrsBLE.connectionState.rawValue)
            if let sample = ahrsBLE.latestSample {
                Grid(alignment: .leading, horizontalSpacing: 18, verticalSpacing: 8) {
                    GridRow {
                        Text("Roll")
                        Text(String(format: "%.1f", sample.roll)).foregroundStyle(IPCATheme.success)
                    }
                    GridRow {
                        Text("Pitch")
                        Text(String(format: "%.1f", sample.pitch)).foregroundStyle(IPCATheme.success)
                    }
                    GridRow {
                        Text("Yaw")
                        Text(String(format: "%.1f", sample.yaw)).foregroundStyle(IPCATheme.success)
                    }
                    GridRow {
                        Text("Acceleration")
                        Text(String(format: "%.2f", sample.acceleration)).foregroundStyle(IPCATheme.success)
                    }
                    GridRow {
                        Text("Mag heading")
                        Text(String(format: "%.0f", sample.magneticHeading)).foregroundStyle(IPCATheme.success)
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
    }

    private var statusText: String {
        if audio.isRecording && audio.isPaused { return "Paused" }
        if audio.isRecording { return "Recording" }
        return "Idle"
    }

    private var latestUploadStatusKey: String {
        store.recordings.first?.uploadStatus.rawValue ?? ""
    }

    private var latestTranscriptStatusKey: String {
        store.recordings.first?.transcriptStatus.rawValue ?? ""
    }

    private func stopAndUpload() {
        guard var recording = audio.stopRecording(language: settings.language) else { return }
        if let aircraft = settings.selectedAircraft {
            recording.aircraftID = aircraft.id
            recording.aircraftRegistration = aircraft.registration
            recording.aircraftDisplayName = aircraft.displayName
            recording.aircraftType = aircraft.aircraftType
            recording.aircraftADSBHex = aircraft.adsbHex
        }
        recording.ahrsSamplesPath = ahrsBLE.stopCaptureAndSave(recordingID: recording.id)
        recording.gpsSamplesPath = gps.stopCaptureAndSave(recordingID: recording.id)
        ahrsBLE.sendStatusCommand("REC=0")
        ahrsBLE.sendStatusCommand("UPLOAD=BUSY")
        sendGPSStatus()
        store.add(recording)
        uploadManager.upload(recordingID: recording.id, store: store, settings: settings)
    }

    private func sendGPSStatus() {
        switch gps.state {
        case .ready, .recording:
            ahrsBLE.sendStatusCommand("GPS=1")
        default:
            ahrsBLE.sendStatusCommand("GPS=0")
        }
    }

    private func sendUploadStatus() {
        guard let status = store.recordings.first?.uploadStatus else { return }
        switch status {
        case .pending:
            ahrsBLE.sendStatusCommand("UPLOAD=WAIT")
        case .uploading:
            ahrsBLE.sendStatusCommand("UPLOAD=BUSY")
        case .uploaded:
            ahrsBLE.sendStatusCommand("UPLOAD=OK")
        case .failed:
            ahrsBLE.sendStatusCommand("UPLOAD=FAIL")
        }
    }

    private func sendTranscriptStatus() {
        guard let status = store.recordings.first?.transcriptStatus else { return }
        switch status {
        case .pending:
            ahrsBLE.sendStatusCommand("TX=WAIT")
        case .transcribing:
            ahrsBLE.sendStatusCommand("TX=BUSY")
        case .ready:
            ahrsBLE.sendStatusCommand("TX=OK")
        case .failed:
            ahrsBLE.sendStatusCommand("TX=FAIL")
        }
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
