import AVFoundation
import Combine
import Foundation

@MainActor
final class AudioRecorderManager: NSObject, ObservableObject, AVAudioRecorderDelegate {
    @Published private(set) var availableInputs: [AudioInputInfo] = []
    @Published private(set) var selectedInputID: String = ""
    @Published private(set) var selectedInputName: String = "Unknown"
    @Published private(set) var selectedInputPortType: String = "Unknown"
    @Published private(set) var preferredInputName: String = "None"
    @Published private(set) var isUSBActive = false
    @Published private(set) var isAcceptedExternalInputActive = false
    @Published private(set) var isInternalMicWarning = false
    @Published private(set) var isRecording = false
    @Published private(set) var elapsed: TimeInterval = 0
    @Published private(set) var fileSize: Int64 = 0
    @Published private(set) var level: Float = 0
    @Published private(set) var peakLevel: Float = 0
    @Published private(set) var averagePowerDB: Float = -160
    @Published private(set) var peakPowerDB: Float = -160
    @Published private(set) var activeRecordingID: String?
    @Published private(set) var activeRecordingStartedAt: Date?
    @Published private(set) var recordingSignalActive = false
    @Published private(set) var backgroundRecordingStatus = "Idle"
    @Published var lastError: String = ""

    var sourceSummary: String {
        if isUSBActive {
            return "Audio source: USB-C audio input"
        }
        if isAcceptedExternalInputActive {
            return "Audio source: external audio input (\(selectedInputName))"
        }
        if isInternalMicWarning {
            return "Audio source warning: iPhone internal microphone"
        }
        return "Audio source warning: no accepted external input selected"
    }

    private var recorder: AVAudioRecorder?
    private var recordingURL: URL?
    private var recordingID: String?
    private var startedAt: Date?
    private var timer: Timer?
    private var shouldResumeAfterInterruption = false

    override init() {
        super.init()
        NotificationCenter.default.addObserver(
            self,
            selector: #selector(handleRouteChange),
            name: AVAudioSession.routeChangeNotification,
            object: nil
        )
        NotificationCenter.default.addObserver(
            self,
            selector: #selector(handleAudioInterruption),
            name: AVAudioSession.interruptionNotification,
            object: nil
        )
    }

    deinit {
        NotificationCenter.default.removeObserver(self)
        timer?.invalidate()
    }

    func refreshInputs(activateSession: Bool = true) async {
        do {
            try configureAudioSession()
            let session = AVAudioSession.sharedInstance()
            if activateSession {
                try session.setActive(true)
                try preferExternalInputIfAvailable()
            }
            updateInputState()
        } catch {
            lastError = error.localizedDescription
        }
    }

    func resetAudioRoute() async {
        do {
            try configureAudioSession()
            let session = AVAudioSession.sharedInstance()
            try session.setActive(true)
            try preferExternalInputIfAvailable()
            updateInputState()
            if isRecording, !recordingSignalActive {
                recorder?.record()
                recordingSignalActive = recorder?.isRecording == true
            }
        } catch {
            lastError = "Audio route reset failed: \(error.localizedDescription)"
        }
    }

    func startRecording(language: String) async -> Bool {
        lastError = ""
        do {
            let granted = await requestMicrophonePermission()
            guard granted else {
                lastError = "Microphone permission was denied."
                return false
            }

            try configureAudioSession()
            let session = AVAudioSession.sharedInstance()
            try session.setActive(true)
            try preferExternalInputIfAvailable()
            updateInputState()

            let id = UUID().uuidString
            let dir = try RecordingStore.recordingsDirectory()
            let url = dir.appendingPathComponent("\(id).m4a")
            let settings: [String: Any] = [
                AVFormatIDKey: Int(kAudioFormatMPEG4AAC),
                AVSampleRateKey: 44_100,
                AVNumberOfChannelsKey: 1,
                AVEncoderAudioQualityKey: AVAudioQuality.high.rawValue
            ]

            let audioRecorder = try AVAudioRecorder(url: url, settings: settings)
            audioRecorder.delegate = self
            audioRecorder.isMeteringEnabled = true
            audioRecorder.prepareToRecord()
            guard audioRecorder.record() else {
                lastError = "Recorder did not start."
                return false
            }

            recorder = audioRecorder
            recordingURL = url
            recordingID = id
            activeRecordingID = id
            let startDate = Date()
            startedAt = startDate
            activeRecordingStartedAt = startDate
            elapsed = 0
            fileSize = 0
            level = 0
            peakLevel = 0
            averagePowerDB = -160
            peakPowerDB = -160
            isRecording = true
            recordingSignalActive = true
            backgroundRecordingStatus = "Recording"
            startTimer()
            return true
        } catch {
            lastError = error.localizedDescription
            return false
        }
    }

    func stopRecording(language: String) -> Recording? {
        guard let recorder, let recordingURL, let recordingID, let startedAt else { return nil }
        recorder.stop()
        self.recorder = nil
        self.recordingURL = nil
        self.recordingID = nil
        self.activeRecordingID = nil
        self.activeRecordingStartedAt = nil
        self.startedAt = nil
        isRecording = false
        recordingSignalActive = false
        backgroundRecordingStatus = "Idle"
        stopTimer()

        let finalSize = fileSizeFor(url: recordingURL)
        fileSize = finalSize

        return Recording(
            id: recordingID,
            serverID: nil,
            startedAt: startedAt,
            duration: max(elapsed, recorder.currentTime),
            filePath: recordingURL.path,
            inputDeviceName: selectedInputName,
            aircraftID: nil,
            aircraftRegistration: nil,
            aircraftDisplayName: nil,
            aircraftType: nil,
            aircraftADSBHex: nil,
            fileSize: finalSize,
            uploadStatus: .pending,
            transcriptStatus: .pending,
            uploadProgress: 0,
            transcriptProgress: 0,
            language: language,
            transcript: "",
            lastError: isAcceptedExternalInputActive ? "" : "Audio source warning: \(selectedInputName)"
        )
    }

    func appDidEnterBackground() {
        guard isRecording else {
            backgroundRecordingStatus = "Idle in background"
            return
        }

        updateMeters()
        recordingSignalActive = recorder?.isRecording == true
        backgroundRecordingStatus = recordingSignalActive ? "Recording in background" : "Recording not active in background"
    }

    func appWillEnterForeground() {
        guard isRecording else {
            backgroundRecordingStatus = "Idle"
            return
        }

        updateMeters()
        recordingSignalActive = recorder?.isRecording == true
        if recordingSignalActive {
            backgroundRecordingStatus = "Recording continued in background"
        } else {
            backgroundRecordingStatus = "Recording interrupted while app was in background"
            lastError = backgroundRecordingStatus
        }
    }

    private func requestMicrophonePermission() async -> Bool {
        await withCheckedContinuation { continuation in
            if #available(iOS 17.0, *) {
                AVAudioApplication.requestRecordPermission { granted in
                    continuation.resume(returning: granted)
                }
            } else {
                AVAudioSession.sharedInstance().requestRecordPermission { granted in
                    continuation.resume(returning: granted)
                }
            }
        }
    }

    private func configureAudioSession() throws {
        try AVAudioSession.sharedInstance().setCategory(
            .playAndRecord,
            mode: .default,
            options: [.allowBluetoothHFP, .mixWithOthers]
        )
    }

    private func preferExternalInputIfAvailable() throws {
        let session = AVAudioSession.sharedInstance()
        guard let input = session.availableInputs?.first(where: { $0.portType == .usbAudio })
                ?? session.availableInputs?.first(where: { Self.isAcceptedExternalPort($0.portType) })
        else {
            return
        }
        try session.setPreferredInput(input)
    }

    private func updateInputState() {
        let session = AVAudioSession.sharedInstance()
        let inputs = session.availableInputs ?? []
        availableInputs = inputs.map {
            AudioInputInfo(
                id: $0.uid,
                name: $0.portName,
                portType: $0.portType.rawValue,
                isUSB: $0.portType == .usbAudio,
                isAcceptedExternalInput: Self.isAcceptedExternalPort($0.portType),
                isBuiltInMic: $0.portType == .builtInMic
            )
        }

        let active = session.currentRoute.inputs.first
        let preferred = session.preferredInput
        let selected = active ?? preferred
        selectedInputID = selected?.uid ?? ""
        selectedInputName = selected?.portName ?? "Unknown"
        selectedInputPortType = selected?.portType.rawValue ?? "Unknown"
        preferredInputName = preferred?.portName ?? "None"
        isUSBActive = selected?.portType == .usbAudio
        isAcceptedExternalInputActive = selected.map { Self.isAcceptedExternalPort($0.portType) } ?? false
        isInternalMicWarning = selected?.portType == .builtInMic || !isAcceptedExternalInputActive
    }

    private func startTimer() {
        timer?.invalidate()
        timer = Timer.scheduledTimer(withTimeInterval: 0.2, repeats: true) { [weak self] _ in
            Task { @MainActor in
                self?.updateMeters()
            }
        }
    }

    private func stopTimer() {
        timer?.invalidate()
        timer = nil
    }

    private func updateMeters() {
        guard let recorder else { return }
        recorder.updateMeters()
        elapsed = recorder.currentTime
        recordingSignalActive = isRecording && recorder.isRecording
        let average = recorder.averagePower(forChannel: 0)
        let peak = recorder.peakPower(forChannel: 0)
        averagePowerDB = average
        peakPowerDB = peak
        level = Self.normalizedPowerLevel(average)
        peakLevel = Self.normalizedPowerLevel(peak)
        if let recordingURL {
            fileSize = fileSizeFor(url: recordingURL)
        }
        updateInputState()
    }

    private func fileSizeFor(url: URL) -> Int64 {
        let values = try? url.resourceValues(forKeys: [.fileSizeKey])
        return Int64(values?.fileSize ?? 0)
    }

    private static func normalizedPowerLevel(_ decibels: Float) -> Float {
        if decibels < -60 { return 0 }
        if decibels >= 0 { return 1 }
        return powf((decibels + 60) / 60, 2)
    }

    @objc private func handleRouteChange() {
        Task { @MainActor in
            try? preferExternalInputIfAvailable()
            updateInputState()
            if isRecording {
                recordingSignalActive = recorder?.isRecording == true
            }
        }
    }

    private static func isAcceptedExternalPort(_ portType: AVAudioSession.Port) -> Bool {
        switch portType {
        case .usbAudio, .headsetMic, .lineIn, .bluetoothHFP, .carAudio:
            return true
        default:
            return false
        }
    }

    @objc private func handleAudioInterruption(_ notification: Notification) {
        Task { @MainActor in
            guard let userInfo = notification.userInfo,
                  let rawType = userInfo[AVAudioSessionInterruptionTypeKey] as? UInt,
                  let type = AVAudioSession.InterruptionType(rawValue: rawType)
            else {
                return
            }

            switch type {
            case .began:
                shouldResumeAfterInterruption = isRecording
                recordingSignalActive = false
                backgroundRecordingStatus = "Audio recording interrupted"
                lastError = backgroundRecordingStatus
            case .ended:
                let rawOptions = userInfo[AVAudioSessionInterruptionOptionKey] as? UInt ?? 0
                let options = AVAudioSession.InterruptionOptions(rawValue: rawOptions)
                if shouldResumeAfterInterruption && options.contains(.shouldResume), let recorder {
                    try? configureAudioSession()
                    try? AVAudioSession.sharedInstance().setActive(true)
                    recorder.record()
                    recordingSignalActive = recorder.isRecording
                    backgroundRecordingStatus = recordingSignalActive ? "Recording resumed after interruption" : "Recorder did not resume after interruption"
                } else if shouldResumeAfterInterruption {
                    backgroundRecordingStatus = "Audio interruption ended, reset audio path may be needed"
                    lastError = backgroundRecordingStatus
                }
                shouldResumeAfterInterruption = false
            @unknown default:
                break
            }
        }
    }
}
