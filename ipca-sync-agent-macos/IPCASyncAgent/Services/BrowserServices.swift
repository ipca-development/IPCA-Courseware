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

struct CDPResult: Decodable {
    let result: RemoteObject?
}

struct RemoteObject: Decodable {
    let value: JSONValue?
    let description: String?
}

struct CDPError: Decodable {
    let message: String
}

struct CDPExceptionDetails: Decodable {
    let text: String?
    let exception: RemoteObject?
}

final class ChromeDevToolsClient {
    private let task: URLSessionWebSocketTask
    private var nextID = 1

    init(webSocketURL: URL) {
        task = URLSession.shared.webSocketTask(with: webSocketURL)
        task.resume()
    }

    func close() {
        task.cancel(with: .normalClosure, reason: nil)
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

    private func command(method: String, params: [String: Any]) async throws -> CDPEnvelope {
        let id = nextID
        nextID += 1
        let payload: [String: Any] = ["id": id, "method": method, "params": params]
        let data = try JSONSerialization.data(withJSONObject: payload)
        let text = String(data: data, encoding: .utf8) ?? "{}"
        try await task.send(.string(text))

        while true {
            let message = try await task.receive()
            let responseText: String
            switch message {
            case .string(let value): responseText = value
            case .data(let value): responseText = String(data: value, encoding: .utf8) ?? ""
            @unknown default: continue
            }
            guard let responseData = responseText.data(using: .utf8) else { continue }
            let envelope = try JSONDecoder().decode(CDPEnvelope.self, from: responseData)
            if envelope.id == id { return envelope }
        }
    }
}

final class BrowserSessionController: ObservableObject {
    @Published private(set) var browserStatus = "Not Open"
    private let chromeLocator = ChromeLocator()
    private var chromeProcess: Process?
    private var debugPort: Int?
    private var devToolsClient: ChromeDevToolsClient?

    let flyGarminURL = URL(string: "https://fly.garmin.com/fly-garmin/")!

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
        if debugPort == nil { debugPort = freeLoopbackPort() }
        let port = debugPort ?? 0

        if chromeProcess?.isRunning == true {
            browserStatus = "Open"
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
        guard let port = debugPort else { throw BrowserError.devToolsUnavailable }
        let target = try await waitForFlyGarminTarget(port: port)
        guard let webSocket = target.webSocketDebuggerUrl, let url = URL(string: webSocket) else {
            throw BrowserError.pageUnavailable
        }
        let client = ChromeDevToolsClient(webSocketURL: url)
        devToolsClient = client
        return client
    }

    func evaluate(_ expression: String) async throws -> JSONValue {
        let client = try await connectDevTools()
        return try await client.evaluate(expression)
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

    private func freeLoopbackPort() -> Int {
        for _ in 0..<20 {
            let port = Int.random(in: 43_000...49_000)
            if !isPortOpen(port) { return port }
        }
        return 47_919
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
