import CryptoKit
import Dispatch
import Foundation

private let appName = "IPCA Sync Agent"
private let flyGarminURL = "https://fly.garmin.com/fly-garmin/"
private let logbookAPIURL = "https://fly.garmin.com/fly-garmin/api/logbook/"
private let maxDownloadBytes = 100 * 1024 * 1024

enum ProofError: Error, CustomStringConvertible {
    case chromeNotFound
    case chromeDevToolsUnavailable
    case flyGarminPageNotFound
    case invalidCDPResponse(String)
    case garminHumanVerification
    case garminLoginRequired
    case logbookRequestFailed(String)
    case sourceUUIDMissing
    case sourceDownloadFailed(String)
    case invalidDownloadPayload

    var description: String {
        switch self {
        case .chromeNotFound:
            return "Google Chrome Stable was not found at /Applications/Google Chrome.app."
        case .chromeDevToolsUnavailable:
            return "Chrome DevTools was not reachable on localhost. If the dedicated Garmin browser is already running, close it and retry."
        case .flyGarminPageNotFound:
            return "Could not find the FlyGarmin tab through Chrome DevTools."
        case .invalidCDPResponse(let detail):
            return "Unexpected Chrome DevTools response: \(detail)"
        case .garminHumanVerification:
            return "Garmin is still showing human verification. Stop now and do not retry automatically."
        case .garminLoginRequired:
            return "Garmin is still showing login/MFA content. Leave Chrome open, finish login, then run the proof again."
        case .logbookRequestFailed(let detail):
            return "The one-shot Garmin logbook request failed: \(detail)"
        case .sourceUUIDMissing:
            return "No flightDataLogUUID was discovered in the Garmin logbook JSON."
        case .sourceDownloadFailed(let detail):
            return "The one-shot Garmin source download failed: \(detail)"
        case .invalidDownloadPayload:
            return "The Garmin source download did not return a valid base64 payload."
        }
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

enum JSONValue: Decodable {
    case string(String)
    case number(Double)
    case bool(Bool)
    case object([String: JSONValue])
    case array([JSONValue])
    case null

    init(from decoder: Decoder) throws {
        let container = try decoder.singleValueContainer()
        if container.decodeNil() {
            self = .null
        } else if let value = try? container.decode(Bool.self) {
            self = .bool(value)
        } else if let value = try? container.decode(Double.self) {
            self = .number(value)
        } else if let value = try? container.decode(String.self) {
            self = .string(value)
        } else if let value = try? container.decode([String: JSONValue].self) {
            self = .object(value)
        } else {
            self = .array(try container.decode([JSONValue].self))
        }
    }

    var string: String? {
        if case .string(let value) = self { return value }
        return nil
    }

    var int: Int? {
        if case .number(let value) = self { return Int(value) }
        return nil
    }

    var bool: Bool? {
        if case .bool(let value) = self { return value }
        return nil
    }

    var object: [String: JSONValue]? {
        if case .object(let value) = self { return value }
        return nil
    }

    var stringArray: [String]? {
        if case .array(let values) = self {
            return values.compactMap(\.string)
        }
        return nil
    }
}

final class CDPClient {
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
        let response = try await command(
            method: "Runtime.evaluate",
            params: [
                "expression": expression,
                "awaitPromise": true,
                "returnByValue": true
            ]
        )
        if let error = response.error {
            throw ProofError.invalidCDPResponse(error.message)
        }
        if let exception = response.exceptionDetails {
            throw ProofError.invalidCDPResponse(exception.exception?.description ?? exception.text ?? "JavaScript exception")
        }
        guard let value = response.result?.result?.value else {
            throw ProofError.invalidCDPResponse("Runtime.evaluate returned no value.")
        }
        return value
    }

    private func command(method: String, params: [String: Any]) async throws -> CDPEnvelope {
        let id = nextID
        nextID += 1
        let payload: [String: Any] = ["id": id, "method": method, "params": params]
        let data = try JSONSerialization.data(withJSONObject: payload, options: [])
        guard let text = String(data: data, encoding: .utf8) else {
            throw ProofError.invalidCDPResponse("Could not encode command.")
        }
        try await task.send(.string(text))

        while true {
            let message = try await task.receive()
            let responseText: String
            switch message {
            case .string(let value):
                responseText = value
            case .data(let value):
                responseText = String(data: value, encoding: .utf8) ?? ""
            @unknown default:
                continue
            }
            guard let responseData = responseText.data(using: .utf8) else {
                continue
            }
            let envelope = try JSONDecoder().decode(CDPEnvelope.self, from: responseData)
            if envelope.id == id {
                return envelope
            }
        }
    }
}

struct GarminLocalProof {
    static func run() async throws {
        let chromeURL = try chromeExecutableURL()
        let supportDir = try applicationSupportDirectory()
        let profileDir = supportDir.appendingPathComponent("GarminChromeProfile", isDirectory: true)
        let downloadsDir = supportDir.appendingPathComponent("ProofDownloads", isDirectory: true)
        try FileManager.default.createDirectory(at: profileDir, withIntermediateDirectories: true)
        try FileManager.default.createDirectory(at: downloadsDir, withIntermediateDirectories: true)

        let port = Int.random(in: 43_000...49_000)
        try launchChrome(chromeURL: chromeURL, profileDir: profileDir, port: port)

        print("IPCA Sync Agent Garmin Local Proof")
        print("Chrome: \(chromeURL.path)")
        print("Dedicated profile: \(profileDir.path)")
        print("DevTools: http://127.0.0.1:\(port)")
        print("")
        print("Complete Garmin login/MFA/human verification in the visible Chrome window.")
        print("When the Garmin Logbook is visibly loaded, press Enter here for the one-shot verification request.")
        _ = readLine()

        let target = try await waitForFlyGarminTarget(port: port)
        guard let webSocketURLString = target.webSocketDebuggerUrl, let webSocketURL = URL(string: webSocketURLString) else {
            throw ProofError.flyGarminPageNotFound
        }
        let cdp = CDPClient(webSocketURL: webSocketURL)
        defer { cdp.close() }

        let logbookValue = try await cdp.evaluate(logbookProbeExpression())
        guard let logbook = logbookValue.object else {
            throw ProofError.invalidCDPResponse("Logbook probe did not return an object.")
        }
        try validateLogbookProbe(logbook)

        let sourceUUID = logbook["firstFlightDataLogUUID"]?.string
        guard let sourceUUID, !sourceUUID.isEmpty else {
            throw ProofError.sourceUUIDMissing
        }

        print("")
        print("LOGBOOK REQUEST: OK")
        print("HTTP status: \(logbook["status"]?.int ?? 0)")
        print("Content type: \(logbook["contentType"]?.string ?? "")")
        print("Top-level JSON keys: \((logbook["topLevelKeys"]?.stringArray ?? []).joined(separator: ", "))")
        print("Entry count: \(logbook["entryCount"]?.int ?? 0)")
        print("Cursor present: \(logbook["cursorPresent"]?.bool == true ? "yes" : "no")")
        print("First flightDataLogUUID: \(sourceUUID)")

        let downloadValue = try await cdp.evaluate(sourceDownloadExpression(sourceUUID: sourceUUID))
        guard let download = downloadValue.object else {
            throw ProofError.invalidCDPResponse("Source download did not return an object.")
        }
        let saved = try saveDownloadedSource(download, sourceUUID: sourceUUID, downloadsDir: downloadsDir)

        print("")
        print("SOURCE DOWNLOAD: OK")
        print("Source UUID: \(sourceUUID)")
        print("HTTP status: \(download["status"]?.int ?? 0)")
        print("Content type: \(saved.contentType)")
        print("Byte size: \(saved.byteSize)")
        print("SHA-256: \(saved.sha256)")
        print("Saved file: \(saved.fileURL.path)")
        print("Metadata file: \(saved.metadataURL.path)")
        print("")
        print("RESULT: PASSED")
        print("Stop here. Do not build the full app until this result is manually verified on the Desktop Mac.")
    }

    private static func chromeExecutableURL() throws -> URL {
        let path = "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
        guard FileManager.default.isExecutableFile(atPath: path) else {
            throw ProofError.chromeNotFound
        }
        return URL(fileURLWithPath: path)
    }

    private static func applicationSupportDirectory() throws -> URL {
        let base = try FileManager.default.url(
            for: .applicationSupportDirectory,
            in: .userDomainMask,
            appropriateFor: nil,
            create: true
        )
        let directory = base.appendingPathComponent(appName, isDirectory: true)
        try FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
        return directory
    }

    private static func launchChrome(chromeURL: URL, profileDir: URL, port: Int) throws {
        let process = Process()
        process.executableURL = chromeURL
        process.arguments = [
            "--user-data-dir=\(profileDir.path)",
            "--remote-debugging-address=127.0.0.1",
            "--remote-debugging-port=\(port)",
            "--new-window",
            flyGarminURL
        ]
        try process.run()
    }

    private static func waitForFlyGarminTarget(port: Int) async throws -> ChromeTarget {
        let listURL = URL(string: "http://127.0.0.1:\(port)/json/list")!
        for _ in 0..<40 {
            do {
                let (data, _) = try await URLSession.shared.data(from: listURL)
                let targets = try JSONDecoder().decode([ChromeTarget].self, from: data)
                if let target = targets.first(where: { ($0.url ?? "").contains("fly.garmin.com/fly-garmin") && $0.webSocketDebuggerUrl != nil }) {
                    return target
                }
                if let target = targets.first(where: { $0.type == "page" && $0.webSocketDebuggerUrl != nil }) {
                    return target
                }
            } catch {
                try? await Task.sleep(nanoseconds: 500_000_000)
                continue
            }
            try? await Task.sleep(nanoseconds: 500_000_000)
        }
        throw ProofError.chromeDevToolsUnavailable
    }

    private static func validateLogbookProbe(_ logbook: [String: JSONValue]) throws {
        if logbook["humanVerification"]?.bool == true {
            throw ProofError.garminHumanVerification
        }
        if logbook["loginRequired"]?.bool == true {
            throw ProofError.garminLoginRequired
        }
        let status = logbook["status"]?.int ?? 0
        guard (200...299).contains(status), logbook["jsonOk"]?.bool == true else {
            throw ProofError.logbookRequestFailed("HTTP \(status), content type \(logbook["contentType"]?.string ?? "unknown")")
        }
    }

    private static func saveDownloadedSource(_ download: [String: JSONValue], sourceUUID: String, downloadsDir: URL) throws -> (fileURL: URL, metadataURL: URL, contentType: String, byteSize: Int, sha256: String) {
        if download["humanVerification"]?.bool == true {
            throw ProofError.garminHumanVerification
        }
        if download["loginRequired"]?.bool == true {
            throw ProofError.garminLoginRequired
        }
        let status = download["status"]?.int ?? 0
        guard (200...299).contains(status) else {
            throw ProofError.sourceDownloadFailed("HTTP \(status), content type \(download["contentType"]?.string ?? "unknown")")
        }
        guard let base64 = download["base64"]?.string, let bytes = Data(base64Encoded: base64) else {
            throw ProofError.invalidDownloadPayload
        }
        let contentType = download["contentType"]?.string ?? "application/octet-stream"
        let filename = safeFilename(download["filename"]?.string ?? "\(sourceUUID).bin")
        let timestamp = ISO8601DateFormatter().string(from: Date()).replacingOccurrences(of: ":", with: "-")
        let fileURL = downloadsDir.appendingPathComponent("\(sourceUUID)-\(timestamp)-\(filename)")
        try bytes.write(to: fileURL, options: .atomic)
        let sha256 = SHA256.hash(data: bytes).map { String(format: "%02x", $0) }.joined()
        let metadata: [String: Any] = [
            "source_uuid": sourceUUID,
            "byte_size": bytes.count,
            "sha256": sha256,
            "content_type": contentType,
            "original_filename": filename,
            "saved_at": ISO8601DateFormatter().string(from: Date()),
            "http_status": status
        ]
        let metadataURL = fileURL.appendingPathExtension("metadata.json")
        let metadataData = try JSONSerialization.data(withJSONObject: metadata, options: [.prettyPrinted, .sortedKeys])
        try metadataData.write(to: metadataURL, options: .atomic)
        return (fileURL, metadataURL, contentType, bytes.count, sha256)
    }

    private static func safeFilename(_ value: String) -> String {
        let allowed = CharacterSet(charactersIn: "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789._-")
        let scalars = value.unicodeScalars.map { allowed.contains($0) ? Character($0) : "-" }
        let result = String(scalars).trimmingCharacters(in: CharacterSet(charactersIn: "-."))
        return result.isEmpty ? "garmin-source.bin" : result
    }

    private static func logbookProbeExpression() -> String {
        """
        (async () => {
          const response = await fetch('\(logbookAPIURL)', {
            method: 'GET',
            credentials: 'include',
            cache: 'no-store',
            headers: { 'Accept': 'application/json, text/plain, */*' }
          });
          const contentType = response.headers.get('content-type') || '';
          const text = await response.text();
          const sample = text.slice(0, 1200).toLowerCase();
          let json = null;
          let jsonOk = false;
          try {
            json = JSON.parse(text);
            jsonOk = true;
          } catch (_) {}
          const entries = Array.isArray(json?.entries) ? json.entries :
            (Array.isArray(json?.logbookEntries) ? json.logbookEntries :
            (Array.isArray(json?.flights) ? json.flights :
            (Array.isArray(json?.items) ? json.items :
            (Array.isArray(json) ? json : []))));
          const seen = new Set();
          const uuids = [];
          const collect = (value) => {
            if (!value || uuids.length > 0) return;
            if (typeof value === 'string' && /^[a-f0-9-]{36}$/i.test(value)) {
              if (!seen.has(value.toLowerCase())) {
                seen.add(value.toLowerCase());
                uuids.push(value);
              }
              return;
            }
            if (Array.isArray(value)) {
              for (const item of value) collect(item);
              return;
            }
            if (typeof value === 'object') {
              for (const key of ['flightDataLogUUID','flightDataLogUuid','flightDataLogId','uuid','id']) {
                collect(value[key]);
              }
              for (const key of ['flightDataLogUUIDs','flightDataLogUuids','flightDataLogs','dataLogs','trackLogs']) {
                collect(value[key]);
              }
              for (const item of Object.values(value)) {
                if (uuids.length > 0) break;
                collect(item);
              }
            }
          };
          collect(json);
          return {
            status: response.status,
            ok: response.ok,
            finalUrl: response.url || '',
            contentType,
            jsonOk,
            topLevelKeys: json && typeof json === 'object' && !Array.isArray(json) ? Object.keys(json).slice(0, 30) : [],
            entryCount: entries.length,
            cursorPresent: Boolean(json?.version || json?.cursor || json?.nextCursor || json?.metadata?.version),
            firstFlightDataLogUUID: uuids[0] || null,
            loginRequired: sample.includes('password') || sample.includes('sign in') || sample.includes('multi-factor') || sample.includes('mfa') || sample.includes('verification code'),
            humanVerification: sample.includes('verify you are human') || sample.includes('human verification') || sample.includes('captcha') || sample.includes('cf-challenge')
          };
        })()
        """
    }

    private static func sourceDownloadExpression(sourceUUID: String) -> String {
        let escapedUUID = sourceUUID.replacingOccurrences(of: "'", with: "\\'")
        return """
        (async () => {
          const uuid = '\(escapedUUID)';
          const response = await fetch('https://fly.garmin.com/fly-garmin/api/logbook/flight-data/' + encodeURIComponent(uuid), {
            method: 'GET',
            credentials: 'include',
            cache: 'no-store',
            headers: { 'Accept': 'text/csv, application/octet-stream, */*' }
          });
          const contentType = response.headers.get('content-type') || '';
          const contentDisposition = response.headers.get('content-disposition') || '';
          const buffer = await response.arrayBuffer();
          const bytes = new Uint8Array(buffer);
          let sample = '';
          if (contentType.toLowerCase().includes('html')) {
            sample = new TextDecoder().decode(bytes.slice(0, 1200)).toLowerCase();
          }
          if (bytes.byteLength > \(maxDownloadBytes)) {
            return { status: response.status, ok: response.ok, contentType, byteLength: bytes.byteLength, tooLarge: true };
          }
          let binary = '';
          const chunkSize = 0x8000;
          for (let i = 0; i < bytes.length; i += chunkSize) {
            binary += String.fromCharCode(...bytes.subarray(i, i + chunkSize));
          }
          const filenameMatch = contentDisposition.match(/filename\\*?=(?:UTF-8'')?"?([^";]+)"?/i);
          return {
            status: response.status,
            ok: response.ok,
            finalUrl: response.url || '',
            contentType,
            contentDisposition,
            filename: filenameMatch ? decodeURIComponent(filenameMatch[1]) : (uuid + '.bin'),
            byteLength: bytes.byteLength,
            base64: btoa(binary),
            loginRequired: sample.includes('password') || sample.includes('sign in') || sample.includes('multi-factor') || sample.includes('mfa') || sample.includes('verification code'),
            humanVerification: sample.includes('verify you are human') || sample.includes('human verification') || sample.includes('captcha') || sample.includes('cf-challenge')
          };
        })()
        """
    }
}

Task {
    do {
        try await GarminLocalProof.run()
        exit(0)
    } catch {
        print("RESULT: FAILED")
        print("ERROR: \(error)")
        exit(1)
    }
}

dispatchMain()
