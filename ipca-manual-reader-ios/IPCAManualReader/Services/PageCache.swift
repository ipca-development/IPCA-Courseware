import Foundation

actor PageCache {
    static let shared = PageCache()

    private var storage: [String: FrozenPageResponse] = [:]
    private let maxEntries = 48

    private func key(bookKey: String, page: Int) -> String {
        "\(bookKey.uppercased())#\(page)"
    }

    func get(bookKey: String, page: Int) -> FrozenPageResponse? {
        storage[key(bookKey: bookKey, page: page)]
    }

    func set(bookKey: String, page: Int, response: FrozenPageResponse) {
        storage[key(bookKey: bookKey, page: page)] = response
        if storage.count > maxEntries {
            if let oldest = storage.keys.sorted().first {
                storage.removeValue(forKey: oldest)
            }
        }
    }

    func clear(bookKey: String? = nil) {
        if let bookKey {
            let prefix = bookKey.uppercased() + "#"
            storage = storage.filter { !$0.key.hasPrefix(prefix) }
        } else {
            storage.removeAll()
        }
    }
}
