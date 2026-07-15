import AppKit
import Foundation

enum BrowserError: Error, LocalizedError {
    case chromeMissing
    case profileInUse
    case devToolsUnavailable
    case pageUnavailable
    case invalidResponse(String)

    var errorDescription: String? {
        switch self {
        case .chromeMissing:
            return "Google Chrome Stable is not installed. Install Google Chrome and try again."
        case .profileInUse:
            return "Garmin browser is already running. Reuse it or close it."
        case .devToolsUnavailable:
            return "The Garmin browser is open, but the app cannot reconnect to it yet."
        case .pageUnavailable:
            return "The Garmin browser page is not available."
        case .invalidResponse(let message):
            return message
        }
    }
}

final class ChromeLocator {
    private let candidates = [
        "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome",
        "\(NSHomeDirectory())/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
    ]

    func locate() -> URL? {
        candidates
            .map { URL(fileURLWithPath: $0) }
            .first { FileManager.default.isExecutableFile(atPath: $0.path) }
    }
}

struct ChromeTarget: Decodable {
    let type: String?
    let url: String?
    let title: String?
    let webSocketDebuggerUrl: String?
}

struct CDPEnvelope: Decodable {
    let id: Int?
    let result: CDPResult?
    let error: CDPError?
    let exceptionDetails: CDPExceptionDetails?
}

struct CDPRawEnvelope: Codable {
    let id: Int?
    let result: JSONValue?
    let error: CDPError?
}

struct CDPResult: Decodable {
    let result: RemoteObject?
}

struct RemoteObject: Decodable {
    let value: JSONValue?
    let description: String?
}

struct CDPError: Codable {
    let message: String
}

struct CDPExceptionDetails: Decodable {
    let text: String?
    let exception: RemoteObject?
}

struct GarminLogbookNetworkSnapshot {
    var requestID: String
    var url: String
    var startedAt: Date
    var responseStatus: Int?
    var contentType: String?
    var loadingFinishedAt: Date?
    var encodedDataLength: Double?
    var body: String?
    var method: String?
    var postDataPrefix: String?

    var isInFlight: Bool { loadingFinishedAt == nil }
    var duration: TimeInterval? {
        loadingFinishedAt?.timeIntervalSince(startedAt)
    }

    var sanitizedPathWithQueryNames: String {
        guard let components = URLComponents(string: url) else { return "" }
        let names = (components.queryItems ?? []).map { $0.name }.sorted()
        return components.path + (names.isEmpty ? "" : "?" + names.map { "\($0)=<redacted>" }.joined(separator: "&"))
    }
}

final class ChromeDevToolsClient: @unchecked Sendable {
    private let task: URLSessionWebSocketTask
    private var nextID = 1
    private var pendingContinuations: [Int: CheckedContinuation<CDPRawEnvelope, Error>] = [:]
    private var apiRequests: [String: GarminLogbookNetworkSnapshot] = [:]
    private var disconnected = false
    private let stateQueue = DispatchQueue(label: "com.ipca.syncagent.cdp.state")

    init(webSocketURL: URL) {
        task = URLSession.shared.webSocketTask(with: webSocketURL)
        task.resume()
        LoggingService.shared.info("CDP receive loop started.")
        receiveLoop()
    }

    func close() {
        stateQueue.sync {
            disconnected = true
            let continuations = pendingContinuations
            pendingContinuations.removeAll()
            continuations.values.forEach { $0.resume(throwing: BrowserError.devToolsUnavailable) }
        }
        task.cancel(with: .normalClosure, reason: nil)
    }

    func enableNetworkTracking() async throws {
        _ = try await rawCommand(method: "Network.enable", params: [:])
        LoggingService.shared.info("CDP Network enabled.")
    }

    func enableRuntime() async throws {
        _ = try await rawCommand(method: "Runtime.enable", params: [:])
    }

    func evaluate(_ expression: String) async throws -> JSONValue {
        let response = try await command(method: "Runtime.evaluate", params: [
            "expression": expression,
            "awaitPromise": true,
            "returnByValue": true
        ])
        if let error = response.error {
            throw BrowserError.invalidResponse(error.message)
        }
        if let exception = response.exceptionDetails {
            throw BrowserError.invalidResponse(exception.exception?.description ?? exception.text ?? "The browser returned a JavaScript error.")
        }
        guard let value = response.result?.result?.value else {
            throw BrowserError.invalidResponse("The browser returned no value.")
        }
        return value
    }

    func cookieHeader(for urls: [String]) async throws -> String {
        let response = try await rawCommand(method: "Network.getCookies", params: ["urls": urls])
        if let error = response.error {
            throw BrowserError.invalidResponse(error.message)
        }
        guard let cookies = response.result?.object?["cookies"]?.array else { return "" }
        return cookies.compactMap { cookie in
            guard let object = cookie.object,
                  let name = object["name"]?.string,
                  let value = object["value"]?.string,
                  !name.isEmpty else {
                return nil
            }
            return "\(name)=\(value)"
        }.joined(separator: "; ")
    }

    private func command(method: String, params: [String: Any]) async throws -> CDPEnvelope {
        let raw = try await rawCommand(method: method, params: params)
        let data = try JSONEncoder().encode(raw)
        return try JSONDecoder().decode(CDPEnvelope.self, from: data)
    }

    private func rawCommand(method: String, params: [String: Any]) async throws -> CDPRawEnvelope {
        let id = stateQueue.sync {
            guard !disconnected else { return -1 }
            let id = nextID
            nextID += 1
            return id
        }
        guard id > 0 else { throw BrowserError.devToolsUnavailable }
        let payload: [String: Any] = ["id": id, "method": method, "params": params]
        let data = try JSONSerialization.data(withJSONObject: payload)
        let text = String(data: data, encoding: .utf8) ?? "{}"
        return try await withCheckedThrowingContinuation { continuation in
            stateQueue.async {
                self.pendingContinuations[id] = continuation
                Task {
                    do {
                        try await self.task.send(.string(text))
                    } catch {
                        self.stateQueue.async {
                            self.pendingContinuations.removeValue(forKey: id)?.resume(throwing: error)
                        }
                    }
                }
            }
        }
    }

    func completedGarminAPISnapshots() -> [GarminLogbookNetworkSnapshot] {
        stateQueue.sync {
            apiRequests.values
                .filter { !$0.isInFlight }
                .sorted { $0.startedAt < $1.startedAt }
        }
    }

    func inFlightGarminAPICount() -> Int {
        stateQueue.sync {
            apiRequests.values.filter(\.isInFlight).count
        }
    }

    func responseBody(for requestID: String) async throws -> String? {
        let response = try await rawCommand(method: "Network.getResponseBody", params: ["requestId": requestID])
        guard let body = response.result?.object?["body"]?.string else { return nil }
        if response.result?.object?["base64Encoded"]?.bool == true,
           let data = Data(base64Encoded: body) {
            return String(data: data, encoding: .utf8)
        }
        return body
    }

    func navigate(to url: URL) async throws {
        _ = try await rawCommand(method: "Page.enable", params: [:])
        _ = try await rawCommand(method: "Page.navigate", params: ["url": url.absoluteString])
    }

    private func receiveLoop() {
        Task {
            while true {
                do {
                    let message = try await task.receive()
                    let responseText: String
                    switch message {
                    case .string(let value): responseText = value
                    case .data(let value): responseText = String(data: value, encoding: .utf8) ?? ""
                    @unknown default: continue
                    }
                    handleMessage(responseText)
                } catch {
                    stateQueue.async {
                        self.disconnected = true
                        let continuations = self.pendingContinuations
                        self.pendingContinuations.removeAll()
                        continuations.values.forEach { $0.resume(throwing: error) }
                    }
                    LoggingService.shared.error("CDP disconnected/reconnecting: \(error.localizedDescription)")
                    return
                }
            }
        }
    }

    private func handleMessage(_ text: String) {
        guard let data = text.data(using: .utf8),
              let object = (try? JSONSerialization.jsonObject(with: data)) as? [String: Any] else {
            return
        }
        if let id = object["id"] as? Int {
            let envelope = (try? JSONDecoder().decode(CDPRawEnvelope.self, from: data)) ?? CDPRawEnvelope(id: id, result: nil, error: CDPError(message: "Invalid CDP response"))
            stateQueue.async {
                self.pendingContinuations.removeValue(forKey: id)?.resume(returning: envelope)
            }
            return
        }
        guard let method = object["method"] as? String,
              let params = object["params"] as? [String: Any] else {
            return
        }
        handleNetworkEvent(method: method, params: params)
    }

    private func handleNetworkEvent(method: String, params: [String: Any]) {
        switch method {
        case "Network.requestWillBeSent":
            guard let requestID = params["requestId"] as? String,
                  let request = params["request"] as? [String: Any],
                  let url = request["url"] as? String,
                  isInitialBootstrapCandidateURL(url) else { return }
            stateQueue.async {
                var snapshot = GarminLogbookNetworkSnapshot(requestID: requestID, url: url, startedAt: Date())
                snapshot.method = request["method"] as? String
                snapshot.postDataPrefix = (request["postData"] as? String).map { String($0.prefix(400)) }
                self.apiRequests[requestID] = snapshot
            }
        case "Network.responseReceived":
            guard let requestID = params["requestId"] as? String,
                  let response = params["response"] as? [String: Any] else { return }
            stateQueue.async {
                guard var snapshot = self.apiRequests[requestID] else { return }
                snapshot.responseStatus = response["status"] as? Int
                snapshot.contentType = (response["headers"] as? [String: Any])?["content-type"] as? String ??
                    (response["headers"] as? [String: Any])?["Content-Type"] as? String
                self.apiRequests[requestID] = snapshot
            }
        case "Network.loadingFinished":
            guard let requestID = params["requestId"] as? String else { return }
            stateQueue.async {
                guard var snapshot = self.apiRequests[requestID] else { return }
                snapshot.loadingFinishedAt = Date()
                snapshot.encodedDataLength = params["encodedDataLength"] as? Double
                self.apiRequests[requestID] = snapshot
            }
        case "Network.loadingFailed":
            guard let requestID = params["requestId"] as? String else { return }
            stateQueue.async {
                guard var snapshot = self.apiRequests[requestID] else { return }
                snapshot.loadingFinishedAt = Date()
                self.apiRequests[requestID] = snapshot
            }
        default:
            return
        }
    }

    private func isInitialBootstrapCandidateURL(_ value: String) -> Bool {
        guard let url = URL(string: value) else { return false }
        guard url.host == "fly.garmin.com" else { return false }
        return url.path.hasPrefix("/fly-garmin/api/") ||
            url.path.localizedCaseInsensitiveContains("graphql")
    }
}

final class BrowserSessionController: ObservableObject {
    @Published private(set) var browserStatus = "Not Open"
    private let chromeLocator = ChromeLocator()
    private let defaultDebugPort = 47_919
    private var chromeProcess: Process?
    private var debugPort: Int
    private var devToolsClient: ChromeDevToolsClient?

    let flyGarminURL = URL(string: "https://fly.garmin.com/fly-garmin/")!

    init() {
        let saved = UserDefaults.standard.integer(forKey: "garminChromeDebugPort")
        debugPort = saved > 0 ? saved : defaultDebugPort
        UserDefaults.standard.set(debugPort, forKey: "garminChromeDebugPort")
    }

    var applicationSupportDirectory: URL {
        let base = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask).first!
        let directory = base.appendingPathComponent("IPCA Sync Agent", isDirectory: true)
        try? FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
        return directory
    }

    var garminProfileDirectory: URL {
        applicationSupportDirectory.appendingPathComponent("GarminChromeProfile", isDirectory: true)
    }

    func ensureChromeAvailable() throws -> URL {
        guard let chrome = chromeLocator.locate() else { throw BrowserError.chromeMissing }
        return chrome
    }

    @MainActor
    func openGarminForLogin() async throws {
        let chrome = try ensureChromeAvailable()
        try FileManager.default.createDirectory(at: garminProfileDirectory, withIntermediateDirectories: true)
        let port = debugPort

        if chromeProcess?.isRunning == true {
            browserStatus = "Open"
            return
        }
        if isPortOpen(port) {
            browserStatus = "Open"
            LoggingService.shared.info("Reused existing local Garmin Chrome debugging session.")
            return
        }

        let process = Process()
        process.executableURL = chrome
        process.arguments = [
            "--user-data-dir=\(garminProfileDirectory.path)",
            "--remote-debugging-address=127.0.0.1",
            "--remote-debugging-port=\(port)",
            "--new-window",
            flyGarminURL.absoluteString
        ]
        try process.run()
        chromeProcess = process
        browserStatus = "Open"
        LoggingService.shared.info("Opened local Google Chrome Stable for Garmin login.")
    }

    func connectDevTools() async throws -> ChromeDevToolsClient {
        devToolsClient?.close()
        devToolsClient = nil
        let port = debugPort
        let target = try await waitForFlyGarminTarget(port: port)
        LoggingService.shared.info("CDP target rediscovered.")
        guard let webSocket = target.webSocketDebuggerUrl, let url = URL(string: webSocket) else {
            throw BrowserError.pageUnavailable
        }
        let client = ChromeDevToolsClient(webSocketURL: url)
        try await client.enableRuntime()
        try await client.enableNetworkTracking()
        LoggingService.shared.info("CDP connected.")
        devToolsClient = client
        return client
    }

    func devTools() async throws -> ChromeDevToolsClient {
        return try await connectDevTools()
    }

    func evaluate(_ expression: String) async throws -> JSONValue {
        let client = try await connectDevTools()
        return try await client.evaluate(expression)
    }

    func garminCookieHeader() async throws -> String {
        let client = try await connectDevTools()
        return try await client.cookieHeader(for: ["https://fly.garmin.com", "https://fly.garmin.com/fly-garmin/"])
    }

    func reloadLogbookForInitialSyncCapture() async throws -> ChromeDevToolsClient {
        let client = try await connectDevTools()
        let url = URL(string: "https://fly.garmin.com/fly-garmin/logbook/#/list")!
        try await client.navigate(to: url)
        LoggingService.shared.info("Reloaded Garmin Logbook after CDP Network was enabled.")
        return client
    }

    func resetConnectionWithUserConfirmation() {
        devToolsClient?.close()
        devToolsClient = nil
        chromeProcess?.terminate()
        chromeProcess = nil
        browserStatus = "Closed"
    }

    func repairSummary() async -> String {
        if chromeLocator.locate() == nil { return "Google Chrome Stable is not installed." }
        if !FileManager.default.fileExists(atPath: garminProfileDirectory.path) { return "Garmin browser profile has not been created yet." }
        return "Garmin browser setup looks ready."
    }

    private func waitForFlyGarminTarget(port: Int) async throws -> ChromeTarget {
        let url = URL(string: "http://127.0.0.1:\(port)/json/list")!
        for _ in 0..<40 {
            do {
                let (data, _) = try await URLSession.shared.data(from: url)
                let targets = try JSONDecoder().decode([ChromeTarget].self, from: data)
                if let target = targets.first(where: { ($0.url ?? "").contains("fly.garmin.com/fly-garmin") && $0.webSocketDebuggerUrl != nil }) {
                    return target
                }
                if let target = targets.first(where: { $0.type == "page" && $0.webSocketDebuggerUrl != nil }) {
                    return target
                }
            } catch {
                try? await Task.sleep(nanoseconds: 500_000_000)
            }
        }
        throw BrowserError.devToolsUnavailable
    }

    private func isPortOpen(_ port: Int) -> Bool {
        guard let url = URL(string: "http://127.0.0.1:\(port)/json/version") else { return false }
        let semaphore = DispatchSemaphore(value: 0)
        var open = false
        URLSession.shared.dataTask(with: url) { _, response, _ in
            open = (response as? HTTPURLResponse)?.statusCode == 200
            semaphore.signal()
        }.resume()
        _ = semaphore.wait(timeout: .now() + 0.2)
        return open
    }
}
