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
    @Published private(set) var isInputGainSettable = false
    @Published private(set) var inputGain: Float = 0
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

    var onAudioEvent: ((CVRRecordingEvent) -> Void)?
    var onAudioSegmentsChanged: (([AudioRecordingSegment], String?) -> Void)?

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
    private var monitorEngine: AVAudioEngine?
    private var recordingURL: URL?
    private var finalRecordingURL: URL?
    private var recordingID: String?
    private var startedAt: Date?
    private var currentSegmentStartedAt: Date?
    private var currentSegmentIndex = 1
    private var accumulatedDuration: TimeInterval = 0
    private var finalizedSegments: [AudioRecordingSegment] = []
    private var timer: Timer?
    private var shouldResumeAfterInterruption = false
    private let segmentDurationSeconds: TimeInterval = 60
    private let recordingMeterRefreshInterval: TimeInterval = 0.5
    private let inputStateRefreshInterval: TimeInterval = 5
    private let fileSizeRefreshInterval: TimeInterval = 2
    private static let passiveMonitorPublishInterval: TimeInterval = 0.25
    private static let passiveMonitorBufferSize: AVAudioFrameCount = 4096
    private var lastInputStateRefreshAt: Date?
    private var lastFileSizeRefreshAt: Date?

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
        monitorEngine?.inputNode.removeTap(onBus: 0)
        monitorEngine?.stop()
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
            if activateSession && !isRecording {
                try await startInputMonitorIfNeeded()
            }
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
            try await startInputMonitorIfNeeded()
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
            stopInputMonitor()

            let id = UUID().uuidString
            let dir = try RecordingStore.recordingsDirectory()
            let finalURL = dir.appendingPathComponent("\(id).m4a")
            let segmentURL = segmentURL(recordingID: id, index: 1, directory: dir)
            let audioRecorder = try makeRecorder(url: segmentURL)
            audioRecorder.delegate = self
            guard audioRecorder.record() else {
                lastError = "Recorder did not start."
                return false
            }

            recorder = audioRecorder
            recordingURL = segmentURL
            finalRecordingURL = finalURL
            recordingID = id
            activeRecordingID = id
            let startDate = Date()
            startedAt = startDate
            currentSegmentStartedAt = startDate
            currentSegmentIndex = 1
            accumulatedDuration = 0
            finalizedSegments = []
            activeRecordingStartedAt = startDate
            elapsed = 0
            fileSize = 0
            lastFileSizeRefreshAt = nil
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

    func stopRecording(language: String, postGainDB: Double = 0) async -> Recording? {
        guard let recordingID, let startedAt, let finalRecordingURL else { return nil }
        finalizeCurrentSegment()
        self.recorder = nil
        self.recordingURL = nil
        self.finalRecordingURL = nil
        self.recordingID = nil
        self.activeRecordingID = nil
        self.activeRecordingStartedAt = nil
        self.startedAt = nil
        self.currentSegmentStartedAt = nil
        isRecording = false
        recordingSignalActive = false
        backgroundRecordingStatus = "Idle"
        stopTimer()
        lastFileSizeRefreshAt = nil

        var recordedDuration = finalizedSegments.reduce(0) { $0 + max(0, $1.duration) }
        do {
            recordedDuration = try await Self.mergeSegments(finalizedSegments, outputURL: finalRecordingURL)
            if postGainDB > 0 {
                try await Self.applyGain(to: finalRecordingURL, gainDB: postGainDB)
                recordedDuration = try await Self.audioDurationSeconds(for: finalRecordingURL) ?? recordedDuration
            }
        } catch {
            lastError = "Could not merge audio segments: \(error.localizedDescription)"
            return nil
        }

        let finalSize = fileSizeFor(url: finalRecordingURL)
        fileSize = finalSize
        let inputName = selectedInputName
        currentSegmentIndex = 1
        accumulatedDuration = 0
        finalizedSegments = []
        onAudioSegmentsChanged?([], nil)
        try? await startInputMonitorIfNeeded()

        return Recording(
            id: recordingID,
            serverID: nil,
            startedAt: startedAt,
            duration: recordedDuration,
            filePath: finalRecordingURL.path,
            inputDeviceName: inputName,
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
            lastError: postGainDB > 0
                ? "Post-recording gain applied: +\(String(format: "%.0f", postGainDB)) dB"
                : (isAcceptedExternalInputActive ? "" : "Audio source warning: \(selectedInputName)")
        )
    }

    func setInputGain(_ value: Float) {
        let clamped = min(1, max(0, value))
        do {
            let session = AVAudioSession.sharedInstance()
            guard session.isInputGainSettable else {
                isInputGainSettable = false
                return
            }
            try session.setInputGain(clamped)
            inputGain = session.inputGain
            isInputGainSettable = true
        } catch {
            lastError = "Input gain update failed: \(error.localizedDescription)"
        }
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
        isInputGainSettable = session.isInputGainSettable
        inputGain = session.inputGain
    }

    private func startInputMonitorIfNeeded() async throws {
        guard !isRecording, monitorEngine == nil else { return }
        let granted = await requestMicrophonePermission()
        guard granted else { return }
        try configureAudioSession()
        let session = AVAudioSession.sharedInstance()
        try session.setActive(true)
        try preferExternalInputIfAvailable()
        updateInputState()

        let engine = AVAudioEngine()
        let input = engine.inputNode
        let format = input.outputFormat(forBus: 0)
        var lastMonitorPublishAt = Date.distantPast
        input.installTap(onBus: 0, bufferSize: Self.passiveMonitorBufferSize, format: format) { [weak self, weak engine] buffer, _ in
            let now = Date()
            guard now.timeIntervalSince(lastMonitorPublishAt) >= Self.passiveMonitorPublishInterval else {
                return
            }
            lastMonitorPublishAt = now
            let levels = Self.audioLevels(from: buffer)
            Task { @MainActor in
                guard let self, self.monitorEngine === engine, !self.isRecording else { return }
                self.updateAudioLevels(average: levels.average, peak: levels.peak)
                self.recordingSignalActive = levels.average > -55
                self.backgroundRecordingStatus = self.recordingSignalActive ? "Input signal present" : "Monitoring input"
            }
        }
        monitorEngine = engine
        do {
            try engine.start()
            backgroundRecordingStatus = "Monitoring input"
        } catch {
            input.removeTap(onBus: 0)
            monitorEngine = nil
            throw error
        }
    }

    private func stopInputMonitor() {
        guard let monitorEngine else { return }
        monitorEngine.inputNode.removeTap(onBus: 0)
        monitorEngine.stop()
        self.monitorEngine = nil
    }

    private func startTimer() {
        timer?.invalidate()
        timer = Timer.scheduledTimer(withTimeInterval: recordingMeterRefreshInterval, repeats: true) { [weak self] _ in
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
        elapsed = accumulatedDuration + recorder.currentTime
        recordingSignalActive = isRecording && recorder.isRecording
        let average = recorder.averagePower(forChannel: 0)
        let peak = recorder.peakPower(forChannel: 0)
        updateAudioLevels(average: average, peak: peak)
        if let recordingURL, shouldRefreshFileSize() {
            fileSize = finalizedSegments.reduce(Int64(0)) { $0 + $1.fileSize } + fileSizeFor(url: recordingURL)
        }
        refreshInputStateIfNeeded()
        if recorder.currentTime >= segmentDurationSeconds {
            rotateSegment()
        }
    }

    private func refreshInputStateIfNeeded() {
        let now = Date()
        if let lastInputStateRefreshAt,
           now.timeIntervalSince(lastInputStateRefreshAt) < inputStateRefreshInterval {
            return
        }
        lastInputStateRefreshAt = now
        updateInputState()
    }

    private func shouldRefreshFileSize() -> Bool {
        let now = Date()
        if let lastFileSizeRefreshAt,
           now.timeIntervalSince(lastFileSizeRefreshAt) < fileSizeRefreshInterval {
            return false
        }
        lastFileSizeRefreshAt = now
        return true
    }

    private func fileSizeFor(url: URL) -> Int64 {
        let values = try? url.resourceValues(forKeys: [.fileSizeKey])
        return Int64(values?.fileSize ?? 0)
    }

    private func updateAudioLevels(average: Float, peak: Float) {
        averagePowerDB = average
        peakPowerDB = peak
        level = Self.normalizedPowerLevel(average)
        peakLevel = Self.normalizedPowerLevel(peak)
    }

    private func makeRecorder(url: URL) throws -> AVAudioRecorder {
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
        return audioRecorder
    }

    private func rotateSegment() {
        guard isRecording,
              let recordingID,
              let dir = try? RecordingStore.recordingsDirectory()
        else { return }
        finalizeCurrentSegment()
        currentSegmentIndex += 1
        let nextURL = segmentURL(recordingID: recordingID, index: currentSegmentIndex, directory: dir)
        do {
            let nextRecorder = try makeRecorder(url: nextURL)
            guard nextRecorder.record() else {
                lastError = "Recorder did not continue after segment rotation."
                recordingSignalActive = false
                return
            }
            recorder = nextRecorder
            recordingURL = nextURL
            currentSegmentStartedAt = Date()
            recordingSignalActive = true
            onAudioEvent?(CVRRecordingEvent(severity: "info", type: "audio_segment_rotated", message: "Finalized audio segment \(currentSegmentIndex - 1)."))
        } catch {
            lastError = "Could not continue recorder segment: \(error.localizedDescription)"
            recordingSignalActive = false
        }
    }

    private func finalizeCurrentSegment() {
        guard let recorder, let recordingURL else { return }
        let duration = max(0, recorder.currentTime)
        recorder.stop()
        if duration > 0.2 {
            let segment = AudioRecordingSegment(
                index: currentSegmentIndex,
                filePath: recordingURL.path,
                startedAt: currentSegmentStartedAt ?? Date().addingTimeInterval(-duration),
                duration: duration,
                fileSize: fileSizeFor(url: recordingURL)
            )
            finalizedSegments.append(segment)
            accumulatedDuration += duration
            onAudioSegmentsChanged?(finalizedSegments, recordingURL.path)
        }
    }

    private func segmentURL(recordingID: String, index: Int, directory: URL) -> URL {
        directory.appendingPathComponent("\(recordingID).part-\(String(format: "%03d", index)).m4a")
    }

    static func mergeSegments(_ segments: [AudioRecordingSegment], outputURL: URL) async throws -> TimeInterval {
        let validSegments = segments
            .filter { $0.duration > 0 && FileManager.default.fileExists(atPath: $0.filePath) }
            .sorted { $0.index < $1.index }
        guard !validSegments.isEmpty else {
            throw CocoaError(.fileNoSuchFile, userInfo: [NSLocalizedDescriptionKey: "No finalized audio segments are available."])
        }
        try? FileManager.default.removeItem(at: outputURL)
        if validSegments.count == 1 {
            try FileManager.default.copyItem(at: URL(fileURLWithPath: validSegments[0].filePath), to: outputURL)
            return try await audioDurationSeconds(for: outputURL) ?? validSegments[0].duration
        }

        let composition = AVMutableComposition()
        guard let compositionTrack = composition.addMutableTrack(withMediaType: .audio, preferredTrackID: kCMPersistentTrackID_Invalid) else {
            throw CocoaError(.fileWriteUnknown, userInfo: [NSLocalizedDescriptionKey: "Could not create audio composition track."])
        }

        let firstStartedAt = validSegments[0].startedAt
        var finalDuration: TimeInterval = 0
        for (index, segment) in validSegments.enumerated() {
            let asset = AVURLAsset(url: URL(fileURLWithPath: segment.filePath))
            guard let track = try await asset.loadTracks(withMediaType: .audio).first else { continue }
            let duration = try await asset.load(.duration)
            let startOffsetSeconds = max(0, segment.startedAt.timeIntervalSince(firstStartedAt))
            let start = CMTime(seconds: startOffsetSeconds, preferredTimescale: 600)
            var insertDuration = duration
            if index < validSegments.index(before: validSegments.endIndex) {
                let nextOffsetSeconds = max(0, validSegments[index + 1].startedAt.timeIntervalSince(firstStartedAt))
                let wallClockWindowSeconds = max(0, nextOffsetSeconds - startOffsetSeconds)
                let encodedDurationSeconds = CMTimeGetSeconds(duration)
                if encodedDurationSeconds.isFinite,
                   wallClockWindowSeconds > 0,
                   encodedDurationSeconds > wallClockWindowSeconds {
                    insertDuration = CMTime(seconds: wallClockWindowSeconds, preferredTimescale: 600)
                }
            }
            guard CMTimeGetSeconds(insertDuration) > 0.05 else { continue }
            let timeRange = CMTimeRange(start: .zero, duration: insertDuration)
            try compositionTrack.insertTimeRange(timeRange, of: track, at: start)
            let seconds = CMTimeGetSeconds(insertDuration)
            if seconds.isFinite {
                finalDuration = max(finalDuration, startOffsetSeconds + seconds)
            }
        }

        guard finalDuration > 0 else {
            throw CocoaError(.fileWriteUnknown, userInfo: [NSLocalizedDescriptionKey: "Merged audio duration is zero."])
        }

        guard let exporter = AVAssetExportSession(asset: composition, presetName: AVAssetExportPresetAppleM4A) else {
            throw CocoaError(.fileWriteUnknown, userInfo: [NSLocalizedDescriptionKey: "Could not create audio export session."])
        }
        exporter.outputURL = outputURL
        exporter.outputFileType = .m4a
        let exporterBox = AssetExportSessionBox(exporter)
        try await withCheckedThrowingContinuation { continuation in
            exporterBox.session.exportAsynchronously {
                switch exporterBox.session.status {
                case .completed:
                    continuation.resume()
                case .failed, .cancelled:
                    continuation.resume(throwing: exporterBox.session.error ?? CocoaError(.fileWriteUnknown))
                default:
                    continuation.resume(throwing: CocoaError(.fileWriteUnknown))
                }
            }
        }
        return try await audioDurationSeconds(for: outputURL) ?? finalDuration
    }

    static func mergeAudioFiles(_ files: [(url: URL, startOffset: TimeInterval)], outputURL: URL) async throws -> TimeInterval {
        let validFiles = files
            .filter { FileManager.default.fileExists(atPath: $0.url.path) }
            .sorted { $0.startOffset < $1.startOffset }
        guard !validFiles.isEmpty else {
            throw CocoaError(.fileNoSuchFile, userInfo: [NSLocalizedDescriptionKey: "No audio files are available for merge."])
        }

        try? FileManager.default.removeItem(at: outputURL)
        let composition = AVMutableComposition()
        guard let compositionTrack = composition.addMutableTrack(withMediaType: .audio, preferredTrackID: kCMPersistentTrackID_Invalid) else {
            throw CocoaError(.fileWriteUnknown, userInfo: [NSLocalizedDescriptionKey: "Could not create audio composition track."])
        }

        var finalDuration: TimeInterval = 0
        for (index, file) in validFiles.enumerated() {
            let asset = AVURLAsset(url: file.url)
            guard let track = try await asset.loadTracks(withMediaType: .audio).first else { continue }
            let duration = try await asset.load(.duration)
            let startOffsetSeconds = max(0, file.startOffset)
            let start = CMTime(seconds: startOffsetSeconds, preferredTimescale: 600)
            var insertDuration = duration
            if index < validFiles.index(before: validFiles.endIndex) {
                let wallClockWindowSeconds = max(0, validFiles[index + 1].startOffset - file.startOffset)
                let encodedDurationSeconds = CMTimeGetSeconds(duration)
                if encodedDurationSeconds.isFinite,
                   wallClockWindowSeconds > 0,
                   encodedDurationSeconds > wallClockWindowSeconds {
                    insertDuration = CMTime(seconds: wallClockWindowSeconds, preferredTimescale: 600)
                }
            }
            guard CMTimeGetSeconds(insertDuration) > 0.05 else { continue }
            try compositionTrack.insertTimeRange(CMTimeRange(start: .zero, duration: insertDuration), of: track, at: start)
            let seconds = CMTimeGetSeconds(insertDuration)
            if seconds.isFinite {
                finalDuration = max(finalDuration, startOffsetSeconds + seconds)
            }
        }

        guard finalDuration > 0 else {
            throw CocoaError(.fileWriteUnknown, userInfo: [NSLocalizedDescriptionKey: "Merged audio duration is zero."])
        }
        guard let exporter = AVAssetExportSession(asset: composition, presetName: AVAssetExportPresetAppleM4A) else {
            throw CocoaError(.fileWriteUnknown, userInfo: [NSLocalizedDescriptionKey: "Could not create audio export session."])
        }
        exporter.outputURL = outputURL
        exporter.outputFileType = .m4a
        let exporterBox = AssetExportSessionBox(exporter)
        try await withCheckedThrowingContinuation { continuation in
            exporterBox.session.exportAsynchronously {
                switch exporterBox.session.status {
                case .completed:
                    continuation.resume()
                case .failed, .cancelled:
                    continuation.resume(throwing: exporterBox.session.error ?? CocoaError(.fileWriteUnknown))
                default:
                    continuation.resume(throwing: CocoaError(.fileWriteUnknown))
                }
            }
        }
        return finalDuration
    }

    static func audioDurationSeconds(for audioURL: URL) async throws -> TimeInterval? {
        let asset = AVURLAsset(url: audioURL)
        let duration = try await asset.load(.duration)
        let seconds = CMTimeGetSeconds(duration)
        return seconds.isFinite && seconds > 0 ? seconds : nil
    }

    static func applyGain(to audioURL: URL, gainDB: Double) async throws {
        let clampedDB = min(18, max(0, gainDB))
        guard clampedDB > 0 else { return }
        let asset = AVURLAsset(url: audioURL)
        guard let sourceTrack = try await asset.loadTracks(withMediaType: .audio).first else { return }
        let duration = try await asset.load(.duration)
        let composition = AVMutableComposition()
        guard let track = composition.addMutableTrack(withMediaType: .audio, preferredTrackID: kCMPersistentTrackID_Invalid) else {
            throw CocoaError(.fileWriteUnknown, userInfo: [NSLocalizedDescriptionKey: "Could not create gain composition track."])
        }
        try track.insertTimeRange(CMTimeRange(start: .zero, duration: duration), of: sourceTrack, at: .zero)

        let parameters = AVMutableAudioMixInputParameters(track: track)
        parameters.setVolume(Float(pow(10, clampedDB / 20)), at: .zero)
        let mix = AVMutableAudioMix()
        mix.inputParameters = [parameters]

        let outputURL = audioURL.deletingLastPathComponent().appendingPathComponent("\(audioURL.deletingPathExtension().lastPathComponent).gain.m4a")
        try? FileManager.default.removeItem(at: outputURL)
        guard let exporter = AVAssetExportSession(asset: composition, presetName: AVAssetExportPresetAppleM4A) else {
            throw CocoaError(.fileWriteUnknown, userInfo: [NSLocalizedDescriptionKey: "Could not create gain export session."])
        }
        exporter.outputURL = outputURL
        exporter.outputFileType = .m4a
        exporter.audioMix = mix
        let exporterBox = AssetExportSessionBox(exporter)
        try await withCheckedThrowingContinuation { continuation in
            exporterBox.session.exportAsynchronously {
                switch exporterBox.session.status {
                case .completed:
                    continuation.resume()
                case .failed, .cancelled:
                    continuation.resume(throwing: exporterBox.session.error ?? CocoaError(.fileWriteUnknown))
                default:
                    continuation.resume(throwing: CocoaError(.fileWriteUnknown))
                }
            }
        }
        try? FileManager.default.removeItem(at: audioURL)
        try FileManager.default.moveItem(at: outputURL, to: audioURL)
    }

    private static func normalizedPowerLevel(_ decibels: Float) -> Float {
        if decibels < -60 { return 0 }
        if decibels >= 0 { return 1 }
        return powf((decibels + 60) / 60, 2)
    }

    private static func audioLevels(from buffer: AVAudioPCMBuffer) -> (average: Float, peak: Float) {
        guard let channelData = buffer.floatChannelData else { return (-160, -160) }
        let channelCount = Int(buffer.format.channelCount)
        let frameLength = Int(buffer.frameLength)
        guard channelCount > 0, frameLength > 0 else { return (-160, -160) }

        var sumSquares: Float = 0
        var peak: Float = 0
        for channel in 0..<channelCount {
            let samples = channelData[channel]
            for frame in 0..<frameLength {
                let sample = abs(samples[frame])
                sumSquares += sample * sample
                peak = max(peak, sample)
            }
        }
        let count = Float(channelCount * frameLength)
        let rms = sqrt(sumSquares / max(1, count))
        let averageDB = 20 * log10(max(rms, 0.000_000_1))
        let peakDB = 20 * log10(max(peak, 0.000_000_1))
        return (averageDB, peakDB)
    }

    @objc private func handleRouteChange() {
        Task { @MainActor in
            try? preferExternalInputIfAvailable()
            updateInputState()
            if isRecording {
                recordingSignalActive = recorder?.isRecording == true
                onAudioEvent?(CVRRecordingEvent(
                    severity: isAcceptedExternalInputActive ? "info" : "warning",
                    type: "audio_route_changed",
                    message: "Audio route changed to \(selectedInputName)",
                    metadata: [
                        "input": selectedInputName,
                        "port_type": selectedInputPortType
                    ]
                ))
            } else {
                stopInputMonitor()
                try? await startInputMonitorIfNeeded()
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
                onAudioEvent?(CVRRecordingEvent(severity: "warning", type: "audio_interruption_began", message: backgroundRecordingStatus))
            case .ended:
                let rawOptions = userInfo[AVAudioSessionInterruptionOptionKey] as? UInt ?? 0
                let options = AVAudioSession.InterruptionOptions(rawValue: rawOptions)
                if shouldResumeAfterInterruption && options.contains(.shouldResume), let recorder {
                    try? configureAudioSession()
                    try? AVAudioSession.sharedInstance().setActive(true)
                    recorder.record()
                    recordingSignalActive = recorder.isRecording
                    backgroundRecordingStatus = recordingSignalActive ? "Recording resumed after interruption" : "Recorder did not resume after interruption"
                    onAudioEvent?(CVRRecordingEvent(severity: recordingSignalActive ? "info" : "error", type: "audio_interruption_ended", message: backgroundRecordingStatus))
                } else if shouldResumeAfterInterruption {
                    backgroundRecordingStatus = "Audio interruption ended, reset audio path may be needed"
                    lastError = backgroundRecordingStatus
                    onAudioEvent?(CVRRecordingEvent(severity: "warning", type: "audio_interruption_ended", message: backgroundRecordingStatus))
                }
                shouldResumeAfterInterruption = false
            @unknown default:
                break
            }
        }
    }
}

private final class AssetExportSessionBox: @unchecked Sendable {
    let session: AVAssetExportSession

    init(_ session: AVAssetExportSession) {
        self.session = session
    }
}
