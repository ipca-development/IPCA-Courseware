import Combine
import Foundation

@MainActor
final class RecordingStore: ObservableObject {
    @Published private(set) var recordings: [Recording] = []

    private let encoder: JSONEncoder
    private let decoder: JSONDecoder

    init() {
        encoder = JSONEncoder()
        encoder.outputFormatting = [.prettyPrinted, .sortedKeys]
        encoder.dateEncodingStrategy = .iso8601

        decoder = JSONDecoder()
        decoder.dateDecodingStrategy = .iso8601
    }

    func load() async {
        do {
            let url = try storeURL()
            guard FileManager.default.fileExists(atPath: url.path) else { return }
            let data = try Data(contentsOf: url)
            recordings = try decoder.decode([Recording].self, from: data)
            var changed = repairStaleFilePaths()
            if releaseInterruptedUploads() {
                changed = true
            }
            if changed {
                save()
            } else {
                syncSharedRecordingIndex()
            }
            processPendingG3XImports()
        } catch {
            print("RecordingStore load failed: \(error)")
        }
    }

    func add(_ recording: Recording) {
        recordings.insert(recording, at: 0)
        save()
    }

    func update(_ id: String, mutate: (inout Recording) -> Void) {
        guard let index = recordings.firstIndex(where: { $0.id == id }) else { return }
        mutate(&recordings[index])
        save()
    }

    func recording(id: String) -> Recording? {
        recordings.first(where: { $0.id == id })
    }

    func previousMergeCandidate(for recordingID: String, maxGap: TimeInterval = 3600) -> Recording? {
        guard let recording = recording(id: recordingID) else { return nil }
        let sameAircraft: (Recording) -> Bool = { candidate in
            if let aircraftID = recording.aircraftID, aircraftID > 0 {
                return candidate.aircraftID == aircraftID
            }
            return candidate.aircraftRegistration == recording.aircraftRegistration
        }
        return recordings
            .filter { $0.id != recording.id && $0.startedAt <= recording.startedAt && sameAircraft($0) }
            .sorted { $0.startedAt > $1.startedAt }
            .first { candidate in
                let candidateEnd = candidate.startedAt.addingTimeInterval(candidate.duration)
                return recording.startedAt.timeIntervalSince(candidateEnd) >= 0
                    && recording.startedAt.timeIntervalSince(candidateEnd) <= maxGap
            }
    }

    func mergeWithPreviousFlight(recordingID: String) {
        guard let previous = previousMergeCandidate(for: recordingID) else { return }
        update(recordingID) { recording in
            recording.flightSessionID = previous.flightSessionID
            recording.segmentIndex = previous.segmentIndex + 1
            recording.previousSegmentID = previous.id
            recording.sourceGapSummary = Self.sourceGapSummary(previous: previous, current: recording)
            recording.uploadStatus = .pending
            recording.transcriptStatus = .pending
            recording.uploadProgress = 0
            recording.transcriptProgress = 0
            recording.lastError = "Merged locally with previous flight segment. Upload again to send session metadata."
        }
    }

    func startNewFlight(recordingID: String) {
        update(recordingID) { recording in
            recording.flightSessionID = recording.id
            recording.segmentIndex = 1
            recording.previousSegmentID = nil
            recording.sourceGapSummary = nil
        }
    }

    func createLongTestRecording(durationHours: Int, settings: SettingsStore) throws -> Recording {
        let hours = max(1, min(4, durationHours))
        let id = "TEST-" + UUID().uuidString
        let startedAt = Date()
        let duration = TimeInterval(hours * 3600)
        let directory = try Self.recordingsDirectory()
        let audioURL = directory.appendingPathComponent("\(id).wav")
        let ahrsURL = directory.appendingPathComponent("\(id).ahrs.json")
        let gpsURL = directory.appendingPathComponent("\(id).gps.json")

        try Self.writeSyntheticWAV(url: audioURL, duration: duration)
        try Self.writeSyntheticAHRS(url: ahrsURL, recordingID: id, startedAt: startedAt, duration: duration)
        try Self.writeSyntheticGPS(url: gpsURL, recordingID: id, startedAt: startedAt, duration: duration)

        let aircraft = settings.selectedAircraft
        let recording = Recording(
            id: id,
            serverID: nil,
            startedAt: startedAt,
            duration: duration,
            filePath: audioURL.path,
            inputDeviceName: "IPCA TEST synthetic \(hours)h WAV",
            aircraftID: aircraft?.id,
            aircraftRegistration: aircraft?.registration,
            aircraftDisplayName: aircraft?.displayName,
            aircraftType: aircraft?.aircraftType,
            aircraftADSBHex: aircraft?.adsbHex,
            altimeterSettingInHg: settings.altimeterSettingValue,
            airportElevationFt: settings.airportElevationValue,
            oatC: settings.oatValue,
            fileSize: Self.fileSize(audioURL),
            uploadStatus: .pending,
            transcriptStatus: .pending,
            uploadProgress: 0,
            transcriptProgress: 0,
            language: settings.language,
            transcript: "",
            lastError: "Synthetic long recording. Use only for upload/transcription/reconstruction testing.",
            ahrsSamplesPath: ahrsURL.path,
            gpsSamplesPath: gpsURL.path,
            flightSessionID: id,
            segmentIndex: 1,
            previousSegmentID: nil,
            isTestRecording: true,
            sourceGapSummary: nil
        )
        add(recording)
        return recording
    }

    func save() {
        do {
            let url = try storeURL()
            let data = try encoder.encode(recordings)
            try data.write(to: url, options: [.atomic])
            syncSharedRecordingIndex()
        } catch {
            print("RecordingStore save failed: \(error)")
        }
    }

    func attachG3X(
        recordingID: String,
        csvSourceURL: URL,
        metadata: G3XFlightStreamMetadata,
        matchMethod: String
    ) throws {
        let directory = try Self.recordingsDirectory()
        let destination = directory.appendingPathComponent("\(recordingID).g3x.csv")
        if FileManager.default.fileExists(atPath: destination.path) {
            try FileManager.default.removeItem(at: destination)
        }
        try FileManager.default.copyItem(at: csvSourceURL, to: destination)

        update(recordingID) { recording in
            recording.g3xCsvPath = destination.path
            recording.g3xImportedAt = Date()
            recording.g3xAircraftIdent = metadata.aircraftIdent
            recording.g3xMatchMethod = matchMethod
            recording.g3xRowCount = metadata.rowCount
            recording.g3xServerSynced = false
            if recording.uploadStatus == .uploaded {
                recording.lastError = "G3X CSV attached. Syncing panel data to server..."
            } else if recording.uploadStatus == .pending || recording.uploadStatus == .failed {
                recording.uploadStatus = .pending
                recording.lastError = "G3X CSV attached. Upload again to send audio and G3X data."
            }
        }
    }

    func processPendingG3XImports() {
        let pending = SharedRecordingIndexStore.readPendingImports()
        guard !pending.isEmpty else { return }

        var remaining: [PendingG3XImport] = []
        for item in pending {
            guard let importsDir = AppGroupStorage.importsDirectoryURL else {
                remaining.append(item)
                continue
            }
            let csvURL = importsDir.appendingPathComponent(item.csvRelativePath)
            guard FileManager.default.fileExists(atPath: csvURL.path) else {
                continue
            }

            if let recordingID = item.suggestedRecordingID,
               recording(id: recordingID) != nil {
                do {
                    let parsed = try G3XFlightStreamParser.parse(fileURL: csvURL)
                    try attachG3X(
                        recordingID: recordingID,
                        csvSourceURL: csvURL,
                        metadata: parsed.metadata,
                        matchMethod: item.matchMethod
                    )
                    try? FileManager.default.removeItem(at: csvURL)
                    continue
                } catch {
                    print("Pending G3X import failed for \(recordingID): \(error)")
                    remaining.append(item)
                    continue
                }
            }

            remaining.append(item)
        }

        SharedRecordingIndexStore.writePendingImports(remaining)
        syncSharedRecordingIndex()
    }

    func syncSharedRecordingIndex() {
        let entries = recordings.map { recording in
            SharedRecordingEntry(
                id: recording.id,
                startedAt: recording.startedAt,
                duration: recording.duration,
                aircraftRegistration: recording.aircraftRegistration,
                aircraftDisplayName: recording.aircraftDisplayName,
                hasG3X: recording.hasG3XData
            )
        }
        SharedRecordingIndexStore.writeIndex(entries)
    }

    nonisolated static func recordingsDirectory() throws -> URL {
        let base = try FileManager.default.url(
            for: .documentDirectory,
            in: .userDomainMask,
            appropriateFor: nil,
            create: true
        )
        let url = base.appendingPathComponent("Recordings", isDirectory: true)
        try FileManager.default.createDirectory(at: url, withIntermediateDirectories: true)
        return url
    }

    nonisolated static func resolvedFileURL(preferredPath: String, recordingID: String, fallbackFilename: String) throws -> URL {
        let preferred = URL(fileURLWithPath: preferredPath)
        if FileManager.default.fileExists(atPath: preferred.path) {
            return preferred
        }

        let directory = try recordingsDirectory()
        let fallback = directory.appendingPathComponent(fallbackFilename)
        if FileManager.default.fileExists(atPath: fallback.path) {
            return fallback
        }

        let originalName = preferred.lastPathComponent
        if !originalName.isEmpty {
            let byName = directory.appendingPathComponent(originalName)
            if FileManager.default.fileExists(atPath: byName.path) {
                return byName
            }
        }

        throw CocoaError(.fileNoSuchFile, userInfo: [
            NSFilePathErrorKey: fallback.path,
            NSLocalizedDescriptionKey: "Recording file \(fallbackFilename) is missing for \(recordingID)."
        ])
    }

    private func storeURL() throws -> URL {
        let base = try FileManager.default.url(
            for: .applicationSupportDirectory,
            in: .userDomainMask,
            appropriateFor: nil,
            create: true
        )
        let dir = base.appendingPathComponent("IPCARecorder", isDirectory: true)
        try FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        return dir.appendingPathComponent("recordings.json")
    }

    private func releaseInterruptedUploads() -> Bool {
        var changed = false
        for index in recordings.indices where recordings[index].uploadStatus == .uploading {
            let progress = Int(recordings[index].uploadProgress * 100)
            recordings[index].uploadStatus = .pending
            recordings[index].lastError = "Upload paused at \(progress)%. Tap Retry Upload to continue. Your local audio and flight data are safe on this iPad."
            changed = true
        }
        return changed
    }

    private func repairStaleFilePaths() -> Bool {
        var changed = false
        for index in recordings.indices {
            let id = recordings[index].id

            if let audioURL = try? Self.resolvedFileURL(
                preferredPath: recordings[index].filePath,
                recordingID: id,
                fallbackFilename: "\(id).m4a"
            ), audioURL.path != recordings[index].filePath {
                recordings[index].filePath = audioURL.path
                changed = true
            }

            if let path = recordings[index].ahrsSamplesPath,
               let ahrsURL = try? Self.resolvedFileURL(
                preferredPath: path,
                recordingID: id,
                fallbackFilename: "\(id).ahrs.json"
               ),
               ahrsURL.path != path {
                recordings[index].ahrsSamplesPath = ahrsURL.path
                changed = true
            }

            if let path = recordings[index].gpsSamplesPath,
               let gpsURL = try? Self.resolvedFileURL(
                preferredPath: path,
                recordingID: id,
                fallbackFilename: "\(id).gps.json"
               ),
               gpsURL.path != path {
                recordings[index].gpsSamplesPath = gpsURL.path
                changed = true
            }

            if let path = recordings[index].g3xCsvPath,
               let g3xURL = try? Self.resolvedFileURL(
                preferredPath: path,
                recordingID: id,
                fallbackFilename: "\(id).g3x.csv"
               ),
               g3xURL.path != path {
                recordings[index].g3xCsvPath = g3xURL.path
                changed = true
            }
        }
        return changed
    }

    private static func sourceGapSummary(previous: Recording, current: Recording) -> String {
        let previousEnd = previous.startedAt.addingTimeInterval(previous.duration)
        let gap = max(0, current.startedAt.timeIntervalSince(previousEnd))
        var parts = [String(format: "Segment gap %.0f seconds", gap)]
        if previous.inputDeviceName != current.inputDeviceName {
            parts.append("audio source changed: \(previous.inputDeviceName) -> \(current.inputDeviceName)")
        }
        if current.ahrsSamplesPath == nil {
            parts.append("AHRS missing in current segment")
        }
        if current.gpsSamplesPath == nil {
            parts.append("GPS missing in current segment")
        }
        return parts.joined(separator: "; ")
    }

    private static func fileSize(_ url: URL) -> Int64 {
        let values = try? url.resourceValues(forKeys: [.fileSizeKey])
        return Int64(values?.fileSize ?? 0)
    }

    private static func writeSyntheticWAV(url: URL, duration: TimeInterval) throws {
        let sampleRate = 8_000
        let channels = 1
        let bitsPerSample = 16
        let totalSamples = Int(duration) * sampleRate
        let dataSize = totalSamples * channels * (bitsPerSample / 8)
        FileManager.default.createFile(atPath: url.path, contents: nil)
        let handle = try FileHandle(forWritingTo: url)
        defer { try? handle.close() }

        var header = Data()
        appendASCII("RIFF", to: &header)
        appendUInt32LE(UInt32(36 + dataSize), to: &header)
        appendASCII("WAVEfmt ", to: &header)
        appendUInt32LE(16, to: &header)
        appendUInt16LE(1, to: &header)
        appendUInt16LE(UInt16(channels), to: &header)
        appendUInt32LE(UInt32(sampleRate), to: &header)
        appendUInt32LE(UInt32(sampleRate * channels * bitsPerSample / 8), to: &header)
        appendUInt16LE(UInt16(channels * bitsPerSample / 8), to: &header)
        appendUInt16LE(UInt16(bitsPerSample), to: &header)
        appendASCII("data", to: &header)
        appendUInt32LE(UInt32(dataSize), to: &header)
        try handle.write(contentsOf: header)

        let secondsPerChunk = 10
        let samplesPerChunk = sampleRate * secondsPerChunk
        var remaining = totalSamples
        var sampleIndex = 0
        while remaining > 0 {
            let count = min(samplesPerChunk, remaining)
            var data = Data()
            data.reserveCapacity(count * 2)
            for _ in 0..<count {
                let phase = Double(sampleIndex % sampleRate) / Double(sampleRate)
                let tone = sin(phase * 2.0 * .pi * 440.0)
                let value = Int16(tone * 1200.0)
                appendInt16LE(value, to: &data)
                sampleIndex += 1
            }
            try handle.write(contentsOf: data)
            remaining -= count
        }
    }

    private static func writeSyntheticAHRS(url: URL, recordingID: String, startedAt: Date, duration: TimeInterval) throws {
        let samples = stride(from: 0.0, through: duration, by: 1.0).map { t in
            let heading = t.truncatingRemainder(dividingBy: 360)
            return AHRSSample(
                timestamp: startedAt.addingTimeInterval(t),
                secondsSinceRecordingStart: t,
                roll: sin(t / 30.0) * 12.0,
                pitch: cos(t / 45.0) * -3.0,
                yaw: heading,
                acceleration: 1.0,
                accelerationX: 0.0,
                accelerationY: sin(t / 25.0) * 0.08,
                accelerationZ: 1.0,
                magneticHeading: heading,
                rotationVectorAccuracy: 2,
                magneticFieldAccuracy: 2,
                magneticX: nil,
                magneticY: nil,
                magneticZ: nil,
                headingQuality: 2,
                aviationPitch: cos(t / 45.0) * 3.0,
                aviationRoll: sin(t / 30.0) * 12.0,
                calibratedPitch: cos(t / 45.0) * 3.0,
                calibratedRoll: sin(t / 30.0) * 12.0,
                pitchLevelOffset: 0,
                rollLevelOffset: 0,
                compassDeviation: 0,
                magneticVariation: 0,
                correctedMagneticHeading: heading,
                trueHeading: heading,
                rawLine: "SYNTHETIC TEST \(recordingID)"
            )
        }
        try encodeJSON(samples, to: url)
    }

    private static func writeSyntheticGPS(url: URL, recordingID: String, startedAt: Date, duration: TimeInterval) throws {
        let baseLat = 33.6267
        let baseLon = -116.1597
        let samples = stride(from: 0.0, through: duration, by: 5.0).map { t in
            let nm = t / 3600.0 * 95.0
            let lat = baseLat + (nm / 60.0) * 0.65
            let lon = baseLon + (nm / 60.0) * 0.45
            let climb = min(4500.0, max(0.0, t * 6.0))
            return GPSSample(
                timestamp: startedAt.addingTimeInterval(t),
                secondsSinceRecordingStart: t,
                latitude: lat,
                longitude: lon,
                altitude: -35.0 + climb,
                speedMetersPerSecond: 48.9,
                speedKnots: 95.0,
                course: 35.0,
                horizontalAccuracy: 8.0,
                verticalAccuracy: 12.0
            )
        }
        try encodeJSON(samples, to: url)
        _ = recordingID
    }

    private static func encodeJSON<T: Encodable>(_ value: T, to url: URL) throws {
        let encoder = JSONEncoder()
        encoder.outputFormatting = [.prettyPrinted, .sortedKeys]
        encoder.dateEncodingStrategy = .custom { @Sendable date, encoder in
            let formatter = ISO8601DateFormatter()
            formatter.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
            formatter.timeZone = TimeZone(secondsFromGMT: 0)
            var container = encoder.singleValueContainer()
            try container.encode(formatter.string(from: date))
        }
        let data = try encoder.encode(value)
        try data.write(to: url, options: [.atomic])
    }

    private static func appendASCII(_ string: String, to data: inout Data) {
        data.append(string.data(using: .ascii) ?? Data())
    }

    private static func appendUInt16LE(_ value: UInt16, to data: inout Data) {
        var little = value.littleEndian
        withUnsafeBytes(of: &little) { data.append(contentsOf: $0) }
    }

    private static func appendUInt32LE(_ value: UInt32, to data: inout Data) {
        var little = value.littleEndian
        withUnsafeBytes(of: &little) { data.append(contentsOf: $0) }
    }

    private static func appendInt16LE(_ value: Int16, to data: inout Data) {
        var little = value.littleEndian
        withUnsafeBytes(of: &little) { data.append(contentsOf: $0) }
    }
}
