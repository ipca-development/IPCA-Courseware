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
            }
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

    @discardableResult
    func repairFlightSessionLinks(_ flightRecordIDByRecordingSessionID: [String: String]) -> Int {
        var repaired = 0
        for index in recordings.indices {
            let recording = recordings[index]
            guard let flightRecordID = flightRecordIDByRecordingSessionID[recording.flightSessionID]
                    ?? flightRecordIDByRecordingSessionID[recording.id],
                  recording.flightSessionID != flightRecordID else {
                continue
            }
            recordings[index].flightSessionID = flightRecordID
            recordings[index].uploadStatus = .pending
            recordings[index].uploadProgress = 0
            recordings[index].nextUploadRetryAt = nil
            recordings[index].uploadRetryCount = nil
            recordings[index].lastError = ""
            repaired += 1
        }
        if repaired > 0 {
            save()
        }
        return repaired
    }

    @discardableResult
    func requeueConnectivityFailedUploads() -> Int {
        var requeued = 0
        for index in recordings.indices where recordings[index].uploadStatus == .failed {
            let message = recordings[index].lastError.lowercased()
            let connectivityFailure = message.contains("offline")
                || message.contains("internet connection")
                || message.contains("network connection")
                || message.contains("not connected to the internet")
                || message.contains("could not connect")
                || message.contains("connection was lost")
                || message.contains("timed out")
            guard connectivityFailure else { continue }
            recordings[index].uploadStatus = .pending
            recordings[index].nextUploadRetryAt = nil
            recordings[index].uploadRetryCount = nil
            recordings[index].lastError = ""
            requeued += 1
        }
        if requeued > 0 {
            save()
        }
        return requeued
    }

    func recording(id: String) -> Recording? {
        recordings.first(where: { $0.id == id })
    }

    func delete(_ recording: Recording) {
        deleteLocalFiles(for: recording)
        recordings.removeAll { $0.id == recording.id }
        save()
    }

    func delete(ids: Set<String>) {
        let recordingsToDelete = recordings.filter { ids.contains($0.id) }
        for recording in recordingsToDelete {
            deleteLocalFiles(for: recording)
        }
        recordings.removeAll { ids.contains($0.id) }
        save()
    }

    func localStorageBytes(for recording: Recording) -> Int64 {
        filePathsForDeletion(recording).reduce(Int64(0)) { total, path in
            let values = try? URL(fileURLWithPath: path).resourceValues(forKeys: [.fileSizeKey])
            return total + Int64(values?.fileSize ?? 0)
        }
    }

    func localStorageBytes(ids: Set<String>) -> Int64 {
        recordings
            .filter { ids.contains($0.id) }
            .reduce(Int64(0)) { $0 + localStorageBytes(for: $1) }
    }

    func pendingUploadIDs() -> [String] {
        recordings
            .filter { $0.shouldAttemptUpload() }
            .sorted { $0.startedAt < $1.startedAt }
            .map(\.id)
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
        let dir = base.appendingPathComponent("IPCACVRUnit", isDirectory: true)
        try FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        return dir.appendingPathComponent("recordings.json")
    }

    private func releaseInterruptedUploads() -> Bool {
        var changed = false
        for index in recordings.indices where recordings[index].uploadStatus == .uploading {
            let progress = Int(recordings[index].uploadProgress * 100)
            recordings[index].uploadStatus = .pending
            recordings[index].lastError = "Upload paused at \(progress)%. Local cockpit audio remains stored on this iPhone."
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
        }
        return changed
    }

    private func deleteLocalFiles(for recording: Recording) {
        let fileManager = FileManager.default
        for path in filePathsForDeletion(recording) where !path.isEmpty {
            try? fileManager.removeItem(atPath: path)
        }
    }

    private func filePathsForDeletion(_ recording: Recording) -> Set<String> {
        let fileManager = FileManager.default
        var paths = Set<String>()
        paths.insert(recording.filePath)
        if let gpsSamplesPath = recording.gpsSamplesPath {
            paths.insert(gpsSamplesPath)
        }
        if let beaconDiagnosticsPath = recording.beaconDiagnosticsPath {
            paths.insert(beaconDiagnosticsPath)
        }
        if let recordingEventsPath = recording.recordingEventsPath {
            paths.insert(recordingEventsPath)
        }

        if let audioURL = try? Self.resolvedFileURL(
            preferredPath: recording.filePath,
            recordingID: recording.id,
            fallbackFilename: "\(recording.id).m4a"
        ) {
            paths.insert(audioURL.path)
        }

        if let directory = try? Self.recordingsDirectory(),
           let files = try? fileManager.contentsOfDirectory(at: directory, includingPropertiesForKeys: nil) {
            for url in files {
                let name = url.lastPathComponent
                if name == "\(recording.id).m4a"
                    || name == "\(recording.id).gps.json"
                    || name == "\(recording.id).beacon.json"
                    || name == "\(recording.id).events.json"
                    || name.hasPrefix("\(recording.id).part-")
                    || name.hasPrefix("\(recording.id).combined") {
                    paths.insert(url.path)
                }
            }
        }

        return paths
    }

    private func save() {
        do {
            let url = try storeURL()
            let data = try encoder.encode(recordings)
            try data.write(to: url, options: [.atomic])
        } catch {
            print("RecordingStore save failed: \(error)")
        }
    }
}
