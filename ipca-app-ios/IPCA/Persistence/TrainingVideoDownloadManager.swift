import Foundation

struct TrainingVideoDownloadRecord: Codable, Equatable {
    var videoUUID: String
    var ownerUserUUID: String
    var fileName: String
    var availableUntil: String
    var video: TrainingVideoDTO
}

final class TrainingVideoDownloadManager: NSObject, ObservableObject, URLSessionDownloadDelegate {
    static let shared = TrainingVideoDownloadManager()

    @Published private(set) var records: [String: TrainingVideoDownloadRecord] = [:]
    @Published private(set) var progress: [String: Double] = [:]
    @Published private(set) var failures: [String: String] = [:]

    private var session: URLSession!
    private var taskVideos: [Int: (video: TrainingVideoDTO, entitlement: TrainingVideoPlaybackDTO, ownerUserUUID: String)] = [:]
    private let directory: URL
    private let manifestURL: URL
    private let io = DispatchQueue(label: "training.ipca.app.videos")

    private override init() {
        let support = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]
            .appendingPathComponent("IPCA", isDirectory: true)
        directory = support.appendingPathComponent("TrainingVideos", isDirectory: true)
        manifestURL = directory.appendingPathComponent("manifest.json")
        super.init()
        try? FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
        let config = URLSessionConfiguration.default
        config.waitsForConnectivity = true
        session = URLSession(configuration: config, delegate: self, delegateQueue: nil)
        loadManifest()
    }

    func isDownloaded(_ videoUUID: String, ownerUserUUID: String) -> Bool {
        localURL(forUUID: videoUUID, ownerUserUUID: ownerUserUUID) != nil
    }

    func localURL(for video: TrainingVideoDTO, ownerUserUUID: String) -> URL? {
        localURL(forUUID: video.videoUUID, ownerUserUUID: ownerUserUUID)
    }

    func downloadedVideos(for ownerUserUUID: String) -> [TrainingVideoDTO] {
        records.values
            .filter { $0.ownerUserUUID == ownerUserUUID && !isExpired($0.availableUntil) }
            .map(\.video)
            .sorted { $0.title < $1.title }
    }

    func start(video: TrainingVideoDTO, entitlement: TrainingVideoPlaybackDTO, ownerUserUUID: String) {
        guard !ownerUserUUID.isEmpty, video.downloadable else { return }
        failures[video.videoUUID] = nil
        let urlString = entitlement.downloadURL ?? entitlement.url
        guard let url = URL(string: urlString) else {
            failures[video.videoUUID] = "Couldn't start that download."
            return
        }
        let task = session.downloadTask(with: url)
        taskVideos[task.taskIdentifier] = (video, entitlement, ownerUserUUID)
        progress[video.videoUUID] = 0
        task.resume()
    }

    func urlSession(_ session: URLSession, downloadTask: URLSessionDownloadTask, didWriteData bytesWritten: Int64, totalBytesWritten: Int64, totalBytesExpectedToWrite: Int64) {
        guard let item = taskVideos[downloadTask.taskIdentifier], totalBytesExpectedToWrite > 0 else { return }
        let value = Double(totalBytesWritten) / Double(totalBytesExpectedToWrite)
        DispatchQueue.main.async {
            self.progress[item.video.videoUUID] = value
        }
    }

    func urlSession(_ session: URLSession, downloadTask: URLSessionDownloadTask, didFinishDownloadingTo location: URL) {
        guard let item = taskVideos.removeValue(forKey: downloadTask.taskIdentifier) else { return }
        let owner = item.ownerUserUUID
        let uuid = item.video.videoUUID
        let ext = fileExtension(entitlement: item.entitlement)
        let ownerDir = directory.appendingPathComponent(owner, isDirectory: true)
        try? FileManager.default.createDirectory(at: ownerDir, withIntermediateDirectories: true)
        let dest = ownerDir.appendingPathComponent(uuid + ext)
        try? FileManager.default.removeItem(at: dest)
        do {
            try FileManager.default.moveItem(at: location, to: dest)
            var resource = URLResourceValues()
            resource.isExcludedFromBackup = true
            var destVar = dest
            try? destVar.setResourceValues(resource)
            let record = TrainingVideoDownloadRecord(
                videoUUID: uuid,
                ownerUserUUID: owner,
                fileName: dest.lastPathComponent,
                availableUntil: item.entitlement.availableUntil ?? item.video.availableUntil,
                video: item.video
            )
            DispatchQueue.main.async {
                self.records[self.key(uuid, owner)] = record
                self.progress[uuid] = nil
                self.persistManifest()
            }
        } catch {
            DispatchQueue.main.async {
                self.progress[uuid] = nil
                self.failures[uuid] = "Couldn't save that video offline."
            }
        }
    }

    func urlSession(_ session: URLSession, task: URLSessionTask, didCompleteWithError error: Error?) {
        guard let item = taskVideos.removeValue(forKey: task.taskIdentifier) else { return }
        if error != nil {
            DispatchQueue.main.async {
                self.progress[item.video.videoUUID] = nil
                self.failures[item.video.videoUUID] = "Couldn't download that video."
            }
        }
    }

    private func localURL(forUUID videoUUID: String, ownerUserUUID: String) -> URL? {
        guard let record = records[key(videoUUID, ownerUserUUID)], record.ownerUserUUID == ownerUserUUID else { return nil }
        if isExpired(record.availableUntil) {
            return nil
        }
        let url = directory.appendingPathComponent(ownerUserUUID, isDirectory: true).appendingPathComponent(record.fileName)
        return FileManager.default.fileExists(atPath: url.path) ? url : nil
    }

    private func key(_ videoUUID: String, _ owner: String) -> String {
        owner + "/" + videoUUID
    }

    private func isExpired(_ value: String) -> Bool {
        guard !value.isEmpty, let date = TrainingVideoDate.parse(value) else { return false }
        return date < Date()
    }

    private func fileExtension(entitlement: TrainingVideoPlaybackDTO) -> String {
        let mime = (entitlement.mimeType ?? "").lowercased()
        if mime.contains("quicktime") { return ".mov" }
        return ".mp4"
    }

    private func loadManifest() {
        guard let data = try? Data(contentsOf: manifestURL),
              let decoded = try? JSONDecoder().decode([TrainingVideoDownloadRecord].self, from: data) else { return }
        var next: [String: TrainingVideoDownloadRecord] = [:]
        for record in decoded where !isExpired(record.availableUntil) {
            next[key(record.videoUUID, record.ownerUserUUID)] = record
        }
        records = next
    }

    private func persistManifest() {
        io.async {
            let values = Array(self.records.values)
            if let data = try? JSONEncoder().encode(values) {
                try? data.write(to: self.manifestURL, options: .atomic)
            }
        }
    }
}

enum TrainingVideoDate {
    static func parse(_ value: String) -> Date? {
        let trimmed = value.trimmingCharacters(in: .whitespacesAndNewlines)
        if let date = ISO8601DateFormatter().date(from: trimmed) {
            return date
        }
        let formats = [
            "yyyy-MM-dd HH:mm:ss.SSS",
            "yyyy-MM-dd HH:mm:ss",
            "yyyy-MM-dd'T'HH:mm:ss.SSSXXXXX",
            "yyyy-MM-dd'T'HH:mm:ssXXXXX"
        ]
        let formatter = DateFormatter()
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.timeZone = TimeZone(secondsFromGMT: 0)
        for format in formats {
            formatter.dateFormat = format
            if let date = formatter.date(from: trimmed) {
                return date
            }
        }
        return nil
    }
}

struct TrainingVideoWatchItem: Codable, Equatable {
    var videoUUID: String
    var ownerUserUUID: String
    var positionMs: Int
    var durationMs: Int
}

final class TrainingVideoWatchStore {
    static let shared = TrainingVideoWatchStore()

    private let defaults = UserDefaults.standard
    private let key = "training.ipca.app.watch-progress"

    private init() {}

    func pending(ownerUserUUID: String) -> [TrainingVideoWatchItem] {
        load().filter { $0.ownerUserUUID == ownerUserUUID }
    }

    func queue(videoUUID: String, positionMs: Int, durationMs: Int, ownerUserUUID: String) {
        guard !ownerUserUUID.isEmpty, !videoUUID.isEmpty else { return }
        var items = load().filter { !($0.videoUUID == videoUUID && $0.ownerUserUUID == ownerUserUUID) }
        items.append(TrainingVideoWatchItem(
            videoUUID: videoUUID,
            ownerUserUUID: ownerUserUUID,
            positionMs: max(0, positionMs),
            durationMs: max(0, durationMs)
        ))
        save(items)
    }

    func remove(videoUUID: String, ownerUserUUID: String) {
        save(load().filter { !($0.videoUUID == videoUUID && $0.ownerUserUUID == ownerUserUUID) })
    }

    private func load() -> [TrainingVideoWatchItem] {
        guard let data = defaults.data(forKey: key) else { return [] }
        return (try? JSONDecoder().decode([TrainingVideoWatchItem].self, from: data)) ?? []
    }

    private func save(_ items: [TrainingVideoWatchItem]) {
        if let data = try? JSONEncoder().encode(items) {
            defaults.set(data, forKey: key)
        }
    }
}
