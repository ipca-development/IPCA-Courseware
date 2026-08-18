import Foundation

enum DeviceIdentity {
    static let payload = ["device_uuid": "must-not-leak"]
    static let apnsEnvironment = "test"
}

final class SafetyURLProtocol: URLProtocol {
    static var requests: [URLRequest] = []
    static var requestBodies: [Data] = []

    static func reset() {
        requests = []
        requestBodies = []
    }

    override class func canInit(with request: URLRequest) -> Bool { true }
    override class func canonicalRequest(for request: URLRequest) -> URLRequest { request }

    override func startLoading() {
        Self.requests.append(request)
        if let body = request.httpBody {
            Self.requestBodies.append(body)
        } else if let stream = request.httpBodyStream {
            stream.open()
            defer { stream.close() }
            var data = Data()
            let buffer = UnsafeMutablePointer<UInt8>.allocate(capacity: 1024)
            defer { buffer.deallocate() }
            while stream.hasBytesAvailable {
                let count = stream.read(buffer, maxLength: 1024)
                guard count > 0 else { break }
                data.append(buffer, count: count)
            }
            Self.requestBodies.append(data)
        }
        let body = (try? JSONSerialization.jsonObject(with: Self.requestBodies.last ?? Data())) as? [String: Any]
        let action: String
        if request.httpMethod == "POST" {
            action = body?["action"] as? String ?? ""
        } else {
            action = URLComponents(url: request.url!, resolvingAgainstBaseURL: false)?
                .queryItems?.first(where: { $0.name == "action" })?.value ?? ""
        }
        let json: String
        switch (request.url?.path ?? "", request.httpMethod ?? "", action) {
        case (let path, "POST", _) where path.hasSuffix("/anonymous/submit.php"):
            json = #"{"ok":true,"submission":{"receipt_code":"receipt-1"},"receipt_id":"receipt-1","receipt_secret":"secret-1","reference":"SMS-2026-0000001","status":"submitted"}"#
        case (let path, "POST", _) where path.hasSuffix("/anonymous/status.php"):
            json = #"{"ok":true,"report":{"status":"under_review"},"receipt_id":"receipt-1","reference":"SMS-2026-0000001","status":"under_review","updated_at_utc":"2026-08-18T00:00:00Z"}"#
        case (let path, "POST", "list") where path.hasSuffix("/anonymous/mailbox.php"):
            json = #"{"ok":true,"mailbox":{"messages":[]},"messages":[]}"#
        case (let path, "POST", "post") where path.hasSuffix("/anonymous/mailbox.php"):
            json = #"{"ok":true,"update":{"update_uuid":"update-1","direction":"from_reporter","body":"More detail"}}"#
        case (let path, "POST", "create") where path.hasSuffix("/reports.php"):
            json = #"{"ok":true,"report":{"report_uuid":"report-1","title":"Ramp hazard","description":"Loose equipment","status":"draft"}}"#
        case (let path, "POST", "submit") where path.hasSuffix("/reports.php"):
            json = #"{"ok":true,"report":{"report_uuid":"report-1","reference":"SMS-2026-0000002","title":"Ramp hazard","description":"Loose equipment","status":"submitted"}}"#
        case (let path, "GET", _) where path.hasSuffix("/reports.php"):
            json = #"{"ok":true,"report":{"report_uuid":"report-1","title":"Ramp hazard","description":"Loose equipment","status":"submitted","updates":[{"update_uuid":"update-2","direction":"to_reporter","body":"Received","created_at_utc":"2026-08-18T00:00:00Z"}]}}"#
        case (let path, "POST", "presign") where path.hasSuffix("/attachments.php"):
            json = #"{"ok":true,"attachment":{"attachment_uuid":"00000000-0000-4000-8000-000000000001","put_url":"https://upload.test/object","headers":{"Content-Type":"application/pdf"},"expires_in":900,"status":"pending"}}"#
        case (let path, "POST", "complete") where path.hasSuffix("/attachments.php"):
            json = #"{"ok":true,"attachment":{"attachment_uuid":"00000000-0000-4000-8000-000000000001","filename":"evidence.pdf","mime_type":"application/pdf","byte_size":4,"status":"uploaded"}}"#
        case (_, "PUT", _):
            json = #"{}"#
        default:
            json = #"{"ok":true,"reports":[],"messages":[]}"#
        }
        let response = HTTPURLResponse(url: request.url!, statusCode: 200, httpVersion: nil, headerFields: nil)!
        client?.urlProtocol(self, didReceive: response, cacheStoragePolicy: .notAllowed)
        client?.urlProtocol(self, didLoad: Data(json.utf8))
        client?.urlProtocolDidFinishLoading(self)
    }

    override func stopLoading() {}
}

@main
struct SafetyContractCheck {
    static func main() async {
        var failures = 0
        func expect(_ name: String, _ passes: Bool) {
            print(passes ? "PASS  \(name)" : "FAIL  \(name)")
            if !passes { failures += 1 }
        }

        let legacyJSON = Data(#"{"protocol_version":1,"min_app_version":"1.0.0","min_ios_version":"17.0"}"#.utf8)
        let legacy = try! JSONDecoder().decode(ServerCapabilities.self, from: legacyJSON)
        expect("missing safety capability defaults false", !legacy.safetyReportingEnabled)
        expect("missing anonymous capability defaults false", !legacy.anonymousReportingEnabled)

        let enabledJSON = Data(#"{"safety_reporting_enabled":true,"anonymous_reporting_enabled":true}"#.utf8)
        let enabled = try! JSONDecoder().decode(ServerCapabilities.self, from: enabledJSON)
        expect("safety capabilities decode", enabled.safetyReportingEnabled && enabled.anonymousReportingEnabled)

        let configuration = URLSessionConfiguration.ephemeral
        configuration.protocolClasses = [SafetyURLProtocol.self]
        let client = APIClient(
            baseURL: URL(string: "https://example.test")!,
            token: "authenticated-token",
            urlSession: URLSession(configuration: configuration),
            anonymousURLSession: URLSession(configuration: configuration)
        )
        let input = SafetyReportInput(
            category: "hazard",
            title: "Ramp hazard",
            description: "Loose equipment",
            occurredAtUTC: "2026-08-18T00:00:00Z",
            location: "KTRM",
            aircraftRegistration: "",
            immediateAction: ""
        )
        _ = try! await client.submitAnonymousSafetyReport(input, idempotencyKey: "anonymous-stable-key")
        _ = try! await client.anonymousSafetyStatus(receiptID: "receipt-1", receiptSecret: "secret-1")
        _ = try! await client.anonymousSafetyMailbox(receiptID: "receipt-1", receiptSecret: "secret-1")
        try! await client.postAnonymousSafetyMailbox(
            receiptID: "receipt-1",
            receiptSecret: "secret-1",
            body: "More detail"
        )

        expect("anonymous calls are under api/safety", SafetyURLProtocol.requests.allSatisfy {
            $0.url?.path.hasPrefix("/api/safety/") == true
        })
        expect("anonymous calls omit authorization", SafetyURLProtocol.requests.allSatisfy {
            $0.value(forHTTPHeaderField: "Authorization") == nil
        })
        let bodyText = SafetyURLProtocol.requestBodies.compactMap { String(data: $0, encoding: .utf8) }.joined()
        expect("anonymous calls omit device identity", !bodyText.contains("device_uuid") && !bodyText.contains("must-not-leak"))
        expect("anonymous submit keeps stable idempotency key", SafetyURLProtocol.requests.first?
            .value(forHTTPHeaderField: "Idempotency-Key") == "anonymous-stable-key")
        let anonymousActions = SafetyURLProtocol.requestBodies.compactMap {
            (try? JSONSerialization.jsonObject(with: $0) as? [String: Any])?["action"] as? String
        }
        expect("anonymous mailbox uses canonical actions", anonymousActions.contains("list") && anonymousActions.contains("post"))

        SafetyURLProtocol.reset()
        let draft = try! await client.createSafetyReport(input, idempotencyKey: "identified-stable-key")
        let report = try! await client.submitSafetyReport(draft.reportUUID)
        let detail = try! await client.safetyReport(report.reportUUID)
        let presign = try! await client.safetyAttachmentPresign(
            reportUUID: report.reportUUID,
            attachmentUUID: "00000000-0000-4000-8000-000000000001",
            filename: "evidence.pdf",
            mimeType: "application/pdf",
            byteSize: 4
        )
        try! await client.uploadPresigned(
            url: URL(string: presign.putURL)!,
            data: Data("test".utf8),
            contentType: "application/pdf",
            extraHeaders: presign.headers
        )
        _ = try! await client.completeSafetyAttachment(presign.attachmentUUID)
        expect("identified calls carry bearer token", SafetyURLProtocol.requests
            .filter { $0.url?.host == "example.test" }
            .allSatisfy { $0.value(forHTTPHeaderField: "Authorization") == "Bearer authenticated-token" })
        expect("identified create keeps stable idempotency key", SafetyURLProtocol.requests.first?
            .value(forHTTPHeaderField: "Idempotency-Key") == "identified-stable-key")
        expect("backend updates decode as timeline", detail.timeline.first?.title == "Safety Team")
        expect("attachment uses private safety presign flow", SafetyURLProtocol.requests.contains {
            $0.url?.path.hasSuffix("/api/safety/attachments.php") == true
        } && SafetyURLProtocol.requests.contains {
            $0.url?.host == "upload.test" && $0.httpMethod == "PUT"
        })

        if failures > 0 {
            print("\n\(failures) failed")
            exit(1)
        }
        print("\nAll safety contract checks passed")
    }
}
