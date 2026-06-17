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

    static func recordingsDirectory() throws -> URL {
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
}
