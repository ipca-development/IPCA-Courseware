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

    func recording(id: String) -> Recording? {
        recordings.first(where: { $0.id == id })
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
