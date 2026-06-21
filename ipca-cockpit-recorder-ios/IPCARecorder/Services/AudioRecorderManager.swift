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
    @Published private(set) var isInternalMicWarning = false
    @Published private(set) var isRecording = false
    @Published private(set) var isPaused = false
    @Published private(set) var elapsed: TimeInterval = 0
    @Published private(set) var fileSize: Int64 = 0
    @Published private(set) var level: Float = 0
    @Published private(set) var peakLevel: Float = 0
    @Published private(set) var averagePowerDB: Float = -160
    @Published private(set) var peakPowerDB: Float = -160
    @Published private(set) var activeRecordingID: String?
    @Published var lastError: String = ""

    var sourceSummary: String {
        if isUSBActive {
            return "Recording source: USB audio interface"
        }
        if isInternalMicWarning {
            return "Recording source: iPad internal microphone"
        }
        return "Recording source: unknown"
    }

    private var recorder: AVAudioRecorder?
    private var recordingURL: URL?
    private var recordingID: String?
    private var startedAt: Date?
    private var timer: Timer?

    override init() {
        super.init()
        NotificationCenter.default.addObserver(
            self,
            selector: #selector(handleRouteChange),
            name: AVAudioSession.routeChangeNotification,
            object: nil
        )
    }

    deinit {
        NotificationCenter.default.removeObserver(self)
        timer?.invalidate()
    }

    func refreshInputs(activateSession: Bool = true) async {
        do {
            let session = AVAudioSession.sharedInstance()
            try session.setCategory(.playAndRecord, mode: .default, options: [.allowBluetoothHFP])
            if activateSession {
                try session.setActive(true)
                try preferUSBInputIfAvailable()
            }
            updateInputState()
        } catch {
            lastError = error.localizedDescription
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

            let session = AVAudioSession.sharedInstance()
            try session.setCategory(.playAndRecord, mode: .default, options: [.allowBluetoothHFP])
            try session.setActive(true)
            try preferUSBInputIfAvailable()
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
            startedAt = Date()
            elapsed = 0
            fileSize = 0
            level = 0
            peakLevel = 0
            averagePowerDB = -160
            peakPowerDB = -160
            isRecording = true
            isPaused = false
            startTimer()
            return true
        } catch {
            lastError = error.localizedDescription
            return false
        }
    }

    func pauseRecording() {
        guard isRecording, !isPaused else { return }
        recorder?.pause()
        isPaused = true
        updateMeters()
    }

    func resumeRecording() {
        guard isRecording, isPaused else { return }
        recorder?.record()
        isPaused = false
        updateMeters()
    }

    func stopRecording(language: String) -> Recording? {
        guard let recorder, let recordingURL, let recordingID, let startedAt else { return nil }
        recorder.stop()
        self.recorder = nil
        self.recordingURL = nil
        self.recordingID = nil
        self.activeRecordingID = nil
        self.startedAt = nil
        isRecording = false
        isPaused = false
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
            fileSize: finalSize,
            uploadStatus: .pending,
            transcriptStatus: .pending,
            uploadProgress: 0,
            transcriptProgress: 0,
            language: language,
            transcript: "",
            lastError: "",
            ahrsSamplesPath: nil
        )
    }

    private func requestMicrophonePermission() async -> Bool {
        return await withCheckedContinuation { continuation in
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

    private func preferUSBInputIfAvailable() throws {
        let session = AVAudioSession.sharedInstance()
        guard let usbInput = session.availableInputs?.first(where: { $0.portType == .usbAudio }) else {
            return
        }
        try session.setPreferredInput(usbInput)
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
                isBuiltInMic: $0.portType == .builtInMic
            )
        }

        let active = session.currentRoute.inputs.first
        let preferred = session.preferredInput
        selectedInputID = active?.uid ?? preferred?.uid ?? ""
        selectedInputName = active?.portName ?? preferred?.portName ?? "Unknown"
        selectedInputPortType = active?.portType.rawValue ?? preferred?.portType.rawValue ?? "Unknown"
        preferredInputName = preferred?.portName ?? "None"
        isUSBActive = active?.portType == .usbAudio
        isInternalMicWarning = active?.portType == .builtInMic || (!isUSBActive && active == nil)
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
            try? preferUSBInputIfAvailable()
            updateInputState()
        }
    }
}
