import SwiftUI
import UIKit

struct RecorderView: View {
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var store: RecordingStore
    @EnvironmentObject private var audio: AudioRecorderManager
    @EnvironmentObject private var uploadManager: UploadManager
    @EnvironmentObject private var ahrsBLE: AHRSBLEManager
    @EnvironmentObject private var gps: GPSLocationManager
    @State private var lastAudioLevelStatusSentAt = Date.distantPast
    @State private var lastAudioLevelStatusPct = -100
    @State private var knownMagneticHeadingText = ""
    @State private var compassDeviationText = ""
    @State private var magneticVariationText = ""

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
                sendThrottledAudioLevelStatus(level)
            }
            .onChange(of: audio.isUSBActive) { _, _ in
                ahrsBLE.sendStatusCommand(audio.isAcceptedExternalInputActive ? "AUDIO=1" : "AUDIO=0")
            }
            .onChange(of: audio.isAcceptedExternalInputActive) { _, isExternalActive in
                ahrsBLE.sendStatusCommand(isExternalActive ? "AUDIO=1" : "AUDIO=0")
            }
            .onChange(of: audio.recordingSignalActive) { _, _ in
                sendRecordingStatus()
            }
            .onChange(of: audio.isRecording) { _, isRecording in
                UIApplication.shared.isIdleTimerDisabled = isRecording
            }
            .onChange(of: audio.isPaused) { _, _ in
                sendRecordingStatus()
            }
            .onChange(of: latestUploadStatusKey) { _, _ in
                sendUploadStatus()
            }
            .onChange(of: latestTranscriptStatusKey) { _, _ in
                sendTranscriptStatus()
            }
            .onChange(of: settings.ahrsCalibration) { _, calibration in
                syncCalibrationTextFields(calibration)
                ahrsBLE.updateCalibration(calibration)
            }
            .task {
                syncCalibrationTextFields(settings.ahrsCalibration)
                ahrsBLE.updateCalibration(settings.ahrsCalibration)
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
                    .foregroundStyle(IPCATheme.secondaryText)
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
                Text("Warning: the current audio route is not the accepted cockpit input. Connect the USB-C audio adapter/EarPods interface, then tap Refresh Inputs before recording cockpit audio.")
                    .foregroundStyle(.red)
            }
            inputList
            if !audio.lastError.isEmpty {
                Text(audio.lastError).foregroundStyle(.red)
            }
        }
    }

    private var sourceBanner: some View {
        Label(audio.sourceSummary, systemImage: audio.isAcceptedExternalInputActive ? "checkmark.circle.fill" : "exclamationmark.triangle.fill")
            .font(.title3.weight(.semibold))
            .foregroundStyle(audio.isAcceptedExternalInputActive ? IPCATheme.success : IPCATheme.danger)
            .padding(12)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background((audio.isAcceptedExternalInputActive ? IPCATheme.success : IPCATheme.danger).opacity(0.12), in: RoundedRectangle(cornerRadius: 12))
    }

    private var inputList: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("Detected Inputs").font(.subheadline.weight(.semibold))
            if audio.availableInputs.isEmpty {
                Text("No inputs reported. Tap Refresh Inputs after connecting the USB interface.")
                    .font(.caption)
                    .foregroundStyle(IPCATheme.secondaryText)
            }
            ForEach(audio.availableInputs) { input in
                HStack {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(input.name)
                            .font(.subheadline)
                        Text(input.portType)
                            .font(.caption)
                            .foregroundStyle(IPCATheme.secondaryText)
                    }
                    Spacer()
                    if input.id == audio.selectedInputID {
                        IPCAStatusPill(text: "ACTIVE", color: IPCATheme.brightBlue)
                    }
                    if input.isUSB {
                        IPCAStatusPill(text: "USB", color: IPCATheme.success)
                    }
                    if input.isAcceptedExternalInput && !input.isUSB {
                        IPCAStatusPill(text: "EXTERNAL", color: IPCATheme.success)
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
            LabeledContent("Background audio", value: audio.backgroundRecordingStatus)
            LevelMeterView(level: audio.level, peakLevel: audio.peakLevel)
            HStack {
                Text("Average \(Formatters.decibels(audio.averagePowerDB))")
                Spacer()
                Text("Peak \(Formatters.decibels(audio.peakPowerDB))")
            }
            .font(.caption)
            .foregroundStyle(IPCATheme.secondaryText)
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
                            lastAudioLevelStatusSentAt = .distantPast
                            lastAudioLevelStatusPct = -100
                            ahrsBLE.startCapture(recordingID: recordingID, startedAt: startedAt)
                            gps.startCapture(recordingID: recordingID, startedAt: startedAt)
                            ahrsBLE.sendStatusCommand("REC=1")
                            ahrsBLE.sendStatusCommand("UPLOAD=WAIT")
                            ahrsBLE.sendStatusCommand("TX=WAIT")
                            ahrsBLE.sendStatusCommand(audio.isAcceptedExternalInputActive ? "AUDIO=1" : "AUDIO=0")
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
            if let failed = latestFailedRecordingForStatus {
                Text("Last upload failed. Use the Recordings tab to retry or review the error.")
                    .font(.caption)
                    .foregroundStyle(IPCATheme.warning)
                LabeledContent("Failed recording", value: failed.id)
                if !failed.lastError.isEmpty {
                    Text(failed.lastError).foregroundStyle(.red)
                }
            }
            if let recording = latestActiveRecordingForStatus {
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
            } else if latestFailedRecordingForStatus == nil {
                Text("No recordings yet.").foregroundStyle(IPCATheme.secondaryText)
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
            if gps.state == .permissionNeeded {
                Button("Request GPS Permission") {
                    gps.requestPermission()
                }
            }
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
                        Text(String(format: "H %.0f m / V %.0f m", sample.horizontalAccuracy, sample.verticalAccuracy)).foregroundStyle(IPCATheme.secondaryText)
                    }
                }
            } else {
                Text("GPS is optional. Samples are saved during recording when location is available.")
                    .foregroundStyle(IPCATheme.secondaryText)
            }
            if !gps.lastError.isEmpty {
                Text(gps.lastError).foregroundStyle(.red)
            }
        }
    }

    private var ahrsCard: some View {
        IPCACard(title: "BLE AHRS", systemImage: "gyroscope") {
            LabeledContent("State", value: ahrsBLE.connectionState.rawValue)
            LabeledContent("Receive rate", value: String(format: "%.1f Hz", ahrsBLE.receiveRateHz))
            Text(ahrsBLE.receiveHealthMessage)
                .font(.caption)
                .foregroundStyle(ahrsBLE.receiveRateHz > 0 && ahrsBLE.receiveRateHz < 2.0 ? IPCATheme.warning : IPCATheme.secondaryText)
            if let sample = ahrsBLE.latestSample {
                Grid(alignment: .leading, horizontalSpacing: 18, verticalSpacing: 8) {
                    GridRow {
                        Text("Raw roll")
                        Text(String(format: "%.1f", sample.roll)).foregroundStyle(IPCATheme.success)
                    }
                    GridRow {
                        Text("Raw pitch")
                        Text(String(format: "%.1f", sample.pitch)).foregroundStyle(IPCATheme.success)
                    }
                    GridRow {
                        Text("Calibrated roll")
                        Text(String(format: "%.1f", sample.calibratedRoll ?? sample.roll)).foregroundStyle(IPCATheme.brightBlue)
                    }
                    GridRow {
                        Text("Calibrated pitch")
                        Text(String(format: "%.1f", sample.calibratedPitch ?? -sample.pitch)).foregroundStyle(IPCATheme.brightBlue)
                    }
                    GridRow {
                        Text("Yaw")
                        Text(String(format: "%.1f", sample.yaw)).foregroundStyle(IPCATheme.success)
                    }
                    GridRow {
                        Text("Acceleration")
                        Text(String(format: "%.2f", sample.acceleration)).foregroundStyle(IPCATheme.success)
                    }
                    if let accX = sample.accelerationX, let accY = sample.accelerationY, let accZ = sample.accelerationZ {
                        GridRow {
                            Text("Accel vector")
                            Text(String(format: "X %.2f  Y %.2f  Z %.2f", accX, accY, accZ))
                                .foregroundStyle(IPCATheme.secondaryText)
                        }
                    }
                    GridRow {
                        Text("Compass heading")
                        Text(String(format: "%.0f", sample.magneticHeading)).foregroundStyle(ahrsDiagnosticColor(sample.headingQuality))
                    }
                    GridRow {
                        Text("Magnetic Heading")
                        Text(String(format: "%.0f", sample.correctedMagneticHeading ?? sample.magneticHeading)).foregroundStyle(ahrsDiagnosticColor(sample.headingQuality))
                    }
                    GridRow {
                        Text("True Heading")
                        Text(String(format: "%.0f", sample.trueHeading ?? sample.magneticHeading)).foregroundStyle(ahrsDiagnosticColor(sample.headingQuality))
                    }
                    GridRow {
                        Text("Heading quality")
                        Text(headingQualityLabel(sample.headingQuality)).foregroundStyle(ahrsDiagnosticColor(sample.headingQuality))
                    }
                    GridRow {
                        Text("BNO rotation acc")
                        Text(accuracyLabel(sample.rotationVectorAccuracy)).foregroundStyle(ahrsDiagnosticColor(sample.rotationVectorAccuracy))
                    }
                    GridRow {
                        Text("BNO mag acc")
                        Text(accuracyLabel(sample.magneticFieldAccuracy)).foregroundStyle(ahrsDiagnosticColor(sample.magneticFieldAccuracy))
                    }
                    if let magX = sample.magneticX, let magY = sample.magneticY, let magZ = sample.magneticZ {
                        GridRow {
                            Text("Mag vector")
                            Text(String(format: "X %.1f  Y %.1f  Z %.1f", magX, magY, magZ))
                                .foregroundStyle(IPCATheme.secondaryText)
                        }
                    }
                }
                Text(sample.rawLine)
                    .font(.caption)
                    .foregroundStyle(IPCATheme.secondaryText)
                    .textSelection(.enabled)
                calibrationControls(sample: sample)
            } else {
                Text("Waiting for IPCA-CVR BLE AHRS data.")
                    .foregroundStyle(IPCATheme.secondaryText)
            }
            if !ahrsBLE.lastError.isEmpty {
                Text(ahrsBLE.lastError).foregroundStyle(.red)
            }
        }
    }

    private func calibrationControls(sample: AHRSSample) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            Divider()
            Text("AHRS Calibration")
                .font(.subheadline.weight(.semibold))
            Text("Raw chip data is preserved. These settings define aircraft level reference, compass deviation d, and local Variation V.")
                .font(.caption)
                .foregroundStyle(IPCATheme.secondaryText)

            LazyVGrid(columns: [GridItem(.adaptive(minimum: 220), spacing: 12)], alignment: .leading, spacing: 10) {
                LabeledContent("Pitch level offset", value: String(format: "%.1f", settings.ahrsCalibration.pitchLevelOffset))
                LabeledContent("Roll level offset", value: String(format: "%.1f", settings.ahrsCalibration.rollLevelOffset))
                LabeledContent("Compass deviation d", value: String(format: "%.1f deg", settings.ahrsCalibration.compassDeviation))
                LabeledContent("Variation V", value: String(format: "%.1f deg", settings.ahrsCalibration.magneticVariation))
            }

            HStack {
                Button("Set Level Reference") {
                    settings.setLevelReference(from: sample)
                    ahrsBLE.updateCalibration(settings.ahrsCalibration)
                }
                .buttonStyle(.borderedProminent)
                .tint(IPCATheme.brightBlue)

                Button("Reset AHRS Calibration") {
                    settings.resetAHRSCalibration()
                    ahrsBLE.updateCalibration(settings.ahrsCalibration)
                }
                .buttonStyle(.bordered)
                .tint(IPCATheme.warning)
            }

            HStack {
                TextField("Known Magnetic Heading", text: $knownMagneticHeadingText)
                    .keyboardType(.decimalPad)
                    .textFieldStyle(.roundedBorder)
                Button("Set Magnetic Heading") {
                    guard let known = Double(knownMagneticHeadingText) else { return }
                    settings.setCompassDeviation(knownMagneticHeading: known, rawCompassHeading: sample.magneticHeading)
                    ahrsBLE.updateCalibration(settings.ahrsCalibration)
                }
                .buttonStyle(.bordered)
            }

            HStack {
                TextField("Compass deviation d", text: $compassDeviationText)
                    .keyboardType(.numbersAndPunctuation)
                    .textFieldStyle(.roundedBorder)
                Button("Apply d") {
                    guard let deviation = Double(compassDeviationText) else { return }
                    settings.updateCompassDeviation(deviation)
                    ahrsBLE.updateCalibration(settings.ahrsCalibration)
                }
                .buttonStyle(.bordered)
            }

            HStack {
                TextField("Local Variation V", text: $magneticVariationText)
                    .keyboardType(.numbersAndPunctuation)
                    .textFieldStyle(.roundedBorder)
                Button("Apply V") {
                    guard let variation = Double(magneticVariationText) else { return }
                    settings.updateMagneticVariation(variation)
                    ahrsBLE.updateCalibration(settings.ahrsCalibration)
                }
                .buttonStyle(.bordered)
            }

            Text("Formula: Compass heading + deviation d = Magnetic Heading. Magnetic Heading + Variation V = True Heading. East Variation positive, West Variation negative.")
                .font(.caption)
                .foregroundStyle(IPCATheme.secondaryText)
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

    private var latestActiveRecordingForStatus: Recording? {
        store.recordings.first {
            $0.uploadStatus != .failed && $0.transcriptStatus != .failed
        }
    }

    private var latestFailedRecordingForStatus: Recording? {
        guard let latest = store.recordings.first,
              latest.uploadStatus == .failed || latest.transcriptStatus == .failed
        else {
            return nil
        }
        return latest
    }

    private func stopAndUpload() {
        guard var recording = audio.stopRecording(language: settings.language) else { return }
        UIApplication.shared.isIdleTimerDisabled = false
        if let aircraft = settings.selectedAircraft {
            recording.aircraftID = aircraft.id
            recording.aircraftRegistration = aircraft.registration
            recording.aircraftDisplayName = aircraft.displayName
            recording.aircraftType = aircraft.aircraftType
            recording.aircraftADSBHex = aircraft.adsbHex
        }
        recording.altimeterSettingInHg = settings.altimeterSettingValue
        recording.airportElevationFt = settings.airportElevationValue
        recording.oatC = settings.oatValue
        recording.ahrsSamplesPath = ahrsBLE.stopCaptureAndSave(recordingID: recording.id)
        recording.gpsSamplesPath = gps.stopCaptureAndSave(recordingID: recording.id)
        recording.flightSessionID = recording.id
        recording.segmentIndex = 1
        ahrsBLE.sendStatusCommand("REC=0")
        ahrsBLE.sendStatusCommand("UPLOAD=BUSY")
        sendGPSStatus()
        store.add(recording)
        if let previous = store.previousMergeCandidate(for: recording.id) {
            store.update(recording.id) {
                $0.lastError = "Possible continuation of previous flight \(previous.id). Open Recording Detail to merge if this is the same flight."
            }
        }
        uploadManager.upload(recordingID: recording.id, store: store, settings: settings)
    }

    private func sendRecordingStatus() {
        if !audio.isRecording {
            ahrsBLE.sendStatusCommand("REC=0")
        } else if audio.recordingSignalActive {
            ahrsBLE.sendStatusCommand("REC=1")
        } else {
            ahrsBLE.sendStatusCommand("REC=PAUSE")
        }
    }

    private func sendThrottledAudioLevelStatus(_ level: Float) {
        let pct = Int(max(0, min(1, level)) * 100)
        let now = Date()
        guard now.timeIntervalSince(lastAudioLevelStatusSentAt) >= 1 else { return }
        guard abs(pct - lastAudioLevelStatusPct) >= 5 else { return }

        lastAudioLevelStatusSentAt = now
        lastAudioLevelStatusPct = pct
        ahrsBLE.sendStatusCommand("AUDIOLEVEL=\(pct)")
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

    private func syncCalibrationTextFields(_ calibration: AHRSCalibration) {
        compassDeviationText = String(format: "%.1f", calibration.compassDeviation)
        magneticVariationText = String(format: "%.1f", calibration.magneticVariation)
    }

    private func accuracyLabel(_ value: Int?) -> String {
        guard let value else { return "Unknown" }
        switch value {
        case 0: return "Unreliable (0)"
        case 1: return "Low (1)"
        case 2: return "Medium (2)"
        case 3: return "High (3)"
        default: return "Value \(value)"
        }
    }

    private func headingQualityLabel(_ value: Int?) -> String {
        guard let value else { return "Unknown" }
        switch value {
        case 0: return "Invalid / not calibrated"
        case 1: return "Low confidence"
        case 2: return "Chip calibrated"
        default: return "Value \(value)"
        }
    }

    private func ahrsDiagnosticColor(_ value: Int?) -> Color {
        guard let value else { return IPCATheme.secondaryText }
        if value >= 2 { return IPCATheme.success }
        if value == 1 { return IPCATheme.warning }
        return IPCATheme.danger
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
