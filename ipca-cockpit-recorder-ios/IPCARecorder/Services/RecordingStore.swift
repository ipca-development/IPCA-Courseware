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
            if repairStaleFilePaths() {
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

    func save() {
        do {
            let url = try storeURL()
            let data = try encoder.encode(recordings)
            try data.write(to: url, options: [.atomic])
        } catch {
            print("RecordingStore save failed: \(error)")
        }
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
        }
        return changed
    }
}
