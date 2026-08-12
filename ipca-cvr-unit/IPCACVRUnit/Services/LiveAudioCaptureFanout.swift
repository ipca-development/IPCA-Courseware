import AVFoundation
import Foundation

struct LiveCockpitEncodedChunk {
    let chunkUUID: String
    let sequenceNumber: Int
    let broadcastUUID: String
    let operationalSessionUUID: String
    let startedAt: Date
    let duration: TimeInterval
    let fileURL: URL
}

/// One input tap with an evidence-first fanout.
///
/// Evidence buffers are copied into an unbounded serial writer and are never
/// dropped. Monitor buffers use a bounded queue and are discarded under load.
/// No monitor callback has access to engine or evidence lifecycle controls.
final class LiveAudioCaptureFanout {
    struct FinalizedEvidence {
        let duration: TimeInterval
        let fileSize: Int64
    }

    private let engine = AVAudioEngine()
    private let evidenceQueue = DispatchQueue(label: "ipca.cvr.evidence-fanout", qos: .userInitiated)
    private let monitorQueue = DispatchQueue(label: "ipca.cvr.monitor-fanout", qos: .utility)
    private let stateLock = NSLock()
    private let evidenceTargetFormat: AVAudioFormat
    private let monitorTargetFormat: AVAudioFormat
    private var evidenceWriter: PCMToAACWriter
    private var evidenceURL: URL
    private var monitorWriter: PCMToAACWriter?
    private var monitorWriterBroadcastUUID: String?
    private var monitorContext: MonitorContext?
    private var pendingMonitorBuffers = 0
    private var running = false
    private var didFail = false

    var onLevels: ((Float, Float) -> Void)?
    var onMonitorChunk: ((LiveCockpitEncodedChunk) -> Void)?
    var onFailure: ((Error) -> Void)?

    init(evidenceURL: URL) throws {
        guard let evidenceTargetFormat = AVAudioFormat(
            commonFormat: .pcmFormatFloat32,
            sampleRate: 44_100,
            channels: 1,
            interleaved: false
        ), let monitorTargetFormat = AVAudioFormat(
            commonFormat: .pcmFormatFloat32,
            sampleRate: 16_000,
            channels: 1,
            interleaved: false
        ) else {
            throw NSError(
                domain: "LiveAudioCaptureFanout",
                code: 1,
                userInfo: [NSLocalizedDescriptionKey: "Could not create capture formats."]
            )
        }
        self.evidenceTargetFormat = evidenceTargetFormat
        self.monitorTargetFormat = monitorTargetFormat
        self.evidenceURL = evidenceURL
        evidenceWriter = try PCMToAACWriter(
            url: evidenceURL,
            targetFormat: evidenceTargetFormat,
            bitRate: 96_000
        )
    }

    func start() throws {
        let input = engine.inputNode
        let format = input.outputFormat(forBus: 0)
        guard format.sampleRate > 0, format.channelCount > 0 else {
            throw NSError(
                domain: "LiveAudioCaptureFanout",
                code: 2,
                userInfo: [NSLocalizedDescriptionKey: "Audio input format is unavailable."]
            )
        }
        input.installTap(onBus: 0, bufferSize: 2_048, format: format) { [weak self] buffer, _ in
            self?.receive(buffer)
        }
        engine.prepare()
        do {
            try engine.start()
            stateLock.withLock { running = true }
        } catch {
            input.removeTap(onBus: 0)
            throw error
        }
    }

    func setMonitorLease(
        active: Bool,
        broadcastUUID: String?,
        operationalSessionUUID: String?
    ) {
        let shouldResetWriter = stateLock.withLock { () -> Bool in
            if active,
               let broadcastUUID,
               let operationalSessionUUID,
               !broadcastUUID.isEmpty,
               !operationalSessionUUID.isEmpty {
                if monitorContext?.broadcastUUID != broadcastUUID {
                    monitorContext = MonitorContext(
                        broadcastUUID: broadcastUUID,
                        operationalSessionUUID: operationalSessionUUID,
                        sequenceNumber: 1,
                        startedAt: nil,
                        capturedFrames: 0
                    )
                    pendingMonitorBuffers = 0
                    return true
                }
                return false
            } else {
                let hadContext = monitorContext != nil
                monitorContext = nil
                pendingMonitorBuffers = 0
                return hadContext
            }
        }
        if shouldResetWriter {
            monitorQueue.async { [weak self] in
                self?.monitorWriter = nil
                self?.monitorWriterBroadcastUUID = nil
            }
        }
    }

    func evidenceDuration() -> TimeInterval {
        evidenceQueue.sync { evidenceWriter.duration }
    }

    func resumeAfterInterruption() throws {
        guard stateLock.withLock({ running }) else { return }
        if !engine.isRunning {
            try engine.start()
        }
    }

    func rotateEvidence(to nextURL: URL) throws -> FinalizedEvidence {
        try evidenceQueue.sync {
            let result = finalizeEvidenceWriter()
            evidenceURL = nextURL
            evidenceWriter = try PCMToAACWriter(
                url: nextURL,
                targetFormat: evidenceTargetFormat,
                bitRate: 96_000
            )
            return result
        }
    }

    func stop() -> FinalizedEvidence {
        let wasRunning = stateLock.withLock { () -> Bool in
            let value = running
            running = false
            monitorContext = nil
            return value
        }
        if wasRunning {
            engine.inputNode.removeTap(onBus: 0)
            engine.stop()
        }
        monitorQueue.sync {
            monitorWriter = nil
            monitorWriterBroadcastUUID = nil
        }
        return evidenceQueue.sync { finalizeEvidenceWriter() }
    }

    private func receive(_ source: AVAudioPCMBuffer) {
        guard stateLock.withLock({ running && !didFail }),
              let evidenceCopy = source.deepCopy() else { return }
        let levels = Self.audioLevels(from: source)
        onLevels?(levels.average, levels.peak)

        evidenceQueue.async { [weak self] in
            guard let self else { return }
            do {
                try evidenceWriter.append(evidenceCopy)
            } catch {
                reportFailureOnce(error)
            }
        }

        let shouldQueueMonitor = stateLock.withLock { () -> Bool in
            guard monitorContext != nil, pendingMonitorBuffers < 8 else { return false }
            pendingMonitorBuffers += 1
            return true
        }
        guard shouldQueueMonitor, let monitorCopy = source.deepCopy() else { return }
        monitorQueue.async { [weak self] in
            self?.appendMonitor(monitorCopy)
        }
    }

    private func appendMonitor(_ buffer: AVAudioPCMBuffer) {
        defer {
            stateLock.withLock {
                pendingMonitorBuffers = max(0, pendingMonitorBuffers - 1)
            }
        }
        do {
            var context = stateLock.withLock { monitorContext }
            guard var context else { return }
            if monitorWriterBroadcastUUID != context.broadcastUUID {
                monitorWriter = nil
                monitorWriterBroadcastUUID = context.broadcastUUID
            }
            if monitorWriter == nil {
                let url = FileManager.default.temporaryDirectory
                    .appendingPathComponent("ipca-live-\(UUID().uuidString.lowercased()).m4a")
                monitorWriter = try PCMToAACWriter(
                    url: url,
                    targetFormat: monitorTargetFormat,
                    bitRate: 24_000
                )
                context.startedAt = Date()
                context.capturedFrames = 0
            }
            guard let monitorWriter else { return }
            try monitorWriter.append(buffer)
            context.capturedFrames = monitorWriter.frameCount
            stateLock.withLock {
                guard monitorContext?.broadcastUUID == context.broadcastUUID else { return }
                monitorContext = context
            }
            guard monitorWriter.duration >= 4 else { return }

            let completedURL = monitorWriter.url
            let completedDuration = monitorWriter.duration
            self.monitorWriter = nil
            monitorWriterBroadcastUUID = context.broadcastUUID
            let chunk = LiveCockpitEncodedChunk(
                chunkUUID: UUID().uuidString.lowercased(),
                sequenceNumber: context.sequenceNumber,
                broadcastUUID: context.broadcastUUID,
                operationalSessionUUID: context.operationalSessionUUID,
                startedAt: context.startedAt ?? Date().addingTimeInterval(-completedDuration),
                duration: completedDuration,
                fileURL: completedURL
            )
            context.sequenceNumber += 1
            context.startedAt = nil
            context.capturedFrames = 0
            stateLock.withLock {
                guard monitorContext?.broadcastUUID == context.broadcastUUID else { return }
                monitorContext = context
            }
            onMonitorChunk?(chunk)
        } catch {
            // Priority B is intentionally fail-open: discard its current chunk only.
            monitorWriter = nil
            stateLock.withLock {
                if var context = monitorContext {
                    context.startedAt = nil
                    context.capturedFrames = 0
                    monitorContext = context
                }
            }
        }
    }

    private func finalizeEvidenceWriter() -> FinalizedEvidence {
        let duration = evidenceWriter.duration
        let url = evidenceWriter.url
        evidenceWriter.close()
        let size = (try? url.resourceValues(forKeys: [.fileSizeKey]).fileSize).map(Int64.init) ?? 0
        return FinalizedEvidence(duration: duration, fileSize: size)
    }

    private func reportFailureOnce(_ error: Error) {
        let shouldReport = stateLock.withLock { () -> Bool in
            guard !didFail else { return false }
            didFail = true
            return true
        }
        if shouldReport {
            onFailure?(error)
        }
    }

    private static func audioLevels(from buffer: AVAudioPCMBuffer) -> (average: Float, peak: Float) {
        guard let channels = buffer.floatChannelData, buffer.frameLength > 0 else {
            return (-160, -160)
        }
        var sum: Float = 0
        var peak: Float = 0
        let count = Int(buffer.frameLength)
        for channel in 0..<Int(buffer.format.channelCount) {
            for index in 0..<count {
                let value = abs(channels[channel][index])
                sum += value * value
                peak = max(peak, value)
            }
        }
        let sampleCount = Float(max(1, count * Int(buffer.format.channelCount)))
        let rms = sqrt(sum / sampleCount)
        return (
            rms > 0 ? max(-160, 20 * log10(rms)) : -160,
            peak > 0 ? max(-160, 20 * log10(peak)) : -160
        )
    }

    private struct MonitorContext {
        let broadcastUUID: String
        let operationalSessionUUID: String
        var sequenceNumber: Int
        var startedAt: Date?
        var capturedFrames: AVAudioFramePosition
    }
}

private final class PCMToAACWriter {
    let url: URL
    private let targetFormat: AVAudioFormat
    private var file: AVAudioFile?
    private var converters: [String: AVAudioConverter] = [:]
    private(set) var frameCount: AVAudioFramePosition = 0

    var duration: TimeInterval {
        TimeInterval(frameCount) / targetFormat.sampleRate
    }

    init(url: URL, targetFormat: AVAudioFormat, bitRate: Int) throws {
        self.url = url
        self.targetFormat = targetFormat
        try? FileManager.default.removeItem(at: url)
        let settings: [String: Any] = [
            AVFormatIDKey: Int(kAudioFormatMPEG4AAC),
            AVSampleRateKey: targetFormat.sampleRate,
            AVNumberOfChannelsKey: Int(targetFormat.channelCount),
            AVEncoderBitRateKey: bitRate,
            AVEncoderAudioQualityKey: AVAudioQuality.high.rawValue
        ]
        file = try AVAudioFile(
            forWriting: url,
            settings: settings,
            commonFormat: .pcmFormatFloat32,
            interleaved: false
        )
    }

    func append(_ source: AVAudioPCMBuffer) throws {
        guard let file else { return }
        let key = "\(source.format.sampleRate)-\(source.format.channelCount)-\(source.format.commonFormat.rawValue)"
        let converter: AVAudioConverter
        if let existing = converters[key] {
            converter = existing
        } else {
            guard let created = AVAudioConverter(from: source.format, to: targetFormat) else {
                throw NSError(
                    domain: "LiveAudioCaptureFanout",
                    code: 3,
                    userInfo: [NSLocalizedDescriptionKey: "Could not create audio converter."]
                )
            }
            converters[key] = created
            converter = created
        }
        let ratio = targetFormat.sampleRate / source.format.sampleRate
        let capacity = AVAudioFrameCount(ceil(Double(source.frameLength) * ratio)) + 32
        guard let output = AVAudioPCMBuffer(pcmFormat: targetFormat, frameCapacity: capacity) else {
            throw NSError(
                domain: "LiveAudioCaptureFanout",
                code: 4,
                userInfo: [NSLocalizedDescriptionKey: "Could not allocate converted audio buffer."]
            )
        }
        var supplied = false
        var conversionError: NSError?
        let status = converter.convert(to: output, error: &conversionError) { _, inputStatus in
            if supplied {
                inputStatus.pointee = .noDataNow
                return nil
            }
            supplied = true
            inputStatus.pointee = .haveData
            return source
        }
        if status == .error {
            throw conversionError ?? NSError(
                domain: "LiveAudioCaptureFanout",
                code: 5,
                userInfo: [NSLocalizedDescriptionKey: "Audio conversion failed."]
            )
        }
        guard output.frameLength > 0 else { return }
        try file.write(from: output)
        frameCount += AVAudioFramePosition(output.frameLength)
    }

    func close() {
        file = nil
        converters.removeAll()
    }
}

private extension AVAudioPCMBuffer {
    func deepCopy() -> AVAudioPCMBuffer? {
        guard let copy = AVAudioPCMBuffer(pcmFormat: format, frameCapacity: frameLength) else {
            return nil
        }
        copy.frameLength = frameLength
        let audioBufferList = UnsafeMutableAudioBufferListPointer(copy.mutableAudioBufferList)
        let sourceBufferList = UnsafeMutableAudioBufferListPointer(mutableAudioBufferList)
        guard audioBufferList.count == sourceBufferList.count else { return nil }
        for index in 0..<audioBufferList.count {
            guard let source = sourceBufferList[index].mData,
                  let destination = audioBufferList[index].mData else { continue }
            memcpy(destination, source, Int(sourceBufferList[index].mDataByteSize))
            audioBufferList[index].mDataByteSize = sourceBufferList[index].mDataByteSize
        }
        return copy
    }
}

private extension NSLock {
    func withLock<T>(_ body: () throws -> T) rethrows -> T {
        lock()
        defer { unlock() }
        return try body()
    }
}
