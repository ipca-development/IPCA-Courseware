import Foundation

@MainActor
enum LiveAppValidation {
    static func runIfRequested(session: AppSession) async {
        let args = ProcessInfo.processInfo.arguments
        guard let base = value(in: args, key: "-IPCALiveBase") else { return }
        let email = value(in: args, key: "-IPCALiveEmail") ?? ""
        let password = value(in: args, key: "-IPCALivePassword") ?? ""
        let peerEmail = value(in: args, key: "-IPCALivePeerEmail") ?? ""
        let scenario = value(in: args, key: "-IPCALiveScenario") ?? "send_receive"
        let runID = value(in: args, key: "-IPCALiveRunID") ?? UUID().uuidString.lowercased()

        var report: [String: Any] = [
            "scenario": scenario,
            "email": email,
            "run_id": runID,
            "started_at": ISO8601DateFormatter().string(from: Date())
        ]

        do {
            switch scenario {
            case "queue_hold":
                await session.login(email: email, password: password, serverURL: base, startBackgroundLoops: false)
                guard session.isAuthenticated else { throw LiveError("login failed: \(session.loginError ?? "")") }
                let peer = try await findPeer(session: session, email: peerEmail)
                await session.openDirect(with: peer)
                guard let conversationUUID = session.selectedConversationUUID else { throw LiveError("no conversation") }
                let body = "queue-hold-\(runID)"
                let clientID = await session.enqueueOnly(conversationUUID: conversationUUID, body: body)
                report["pass"] = true
                report["conversation_uuid"] = conversationUUID
                report["client_id"] = clientID
                report["body"] = body
                write(report)
                try await Task.sleep(for: .seconds(120))

            case "recover":
                await session.restoreIfPossible()
                if !session.isAuthenticated {
                    await session.login(email: email, password: password, serverURL: base)
                }
                let deadline = Date().addingTimeInterval(20)
                var found = false
                var count = 0
                while Date() < deadline {
                    await session.syncNow()
                    var conversationUUID = session.selectedConversationUUID
                    if conversationUUID == nil {
                        conversationUUID = await session.store.firstConversationUUID()
                    }
                    if let conversationUUID {
                        count = await session.store.messageCount(conversationUUID: conversationUUID)
                        let bodies = await session.store.bodies(conversationUUID: conversationUUID)
                        if bodies.contains(where: { $0.contains("queue-hold-\(runID)") }) {
                            found = true
                            report["conversation_uuid"] = conversationUUID
                            break
                        }
                    }
                    try await Task.sleep(for: .milliseconds(200))
                }
                report["pass"] = found
                report["local_message_count"] = count
                write(report)

            default:
                await session.login(email: email, password: password, serverURL: base)
                guard session.isAuthenticated else { throw LiveError("login failed: \(session.loginError ?? "")") }
                let peer = try await findPeer(session: session, email: peerEmail)
                await session.openDirect(with: peer)
                guard let conversationUUID = session.selectedConversationUUID else { throw LiveError("no conversation") }
                let outbound = "from-\(email)-\(runID)"
                let sentAt = Date()
                await session.send(conversationUUID: conversationUUID, body: outbound)
                let inboundPrefix = "from-\(peerEmail)-\(runID)"
                var inboundAt: Date?
                var usedBackgroundPollOnly = true
                var localCount = 0
                let deadline = Date().addingTimeInterval(15)
                while Date() < deadline {
                    let bodies = await session.store.bodies(conversationUUID: conversationUUID)
                    localCount = bodies.count
                    if bodies.contains(where: { $0.contains(inboundPrefix) }) {
                        inboundAt = Date()
                        break
                    }
                    try await Task.sleep(for: .milliseconds(100))
                }
                let duplicateClientIDs = await session.store.duplicateClientIDs(conversationUUID: conversationUUID)
                if inboundAt == nil {
                    usedBackgroundPollOnly = false
                    await session.syncNow()
                    let bodies = await session.store.bodies(conversationUUID: conversationUUID)
                    if bodies.contains(where: { $0.contains(inboundPrefix) }) {
                        inboundAt = Date()
                    }
                    localCount = bodies.count
                }
                report["pass"] = inboundAt != nil && duplicateClientIDs.isEmpty
                report["conversation_uuid"] = conversationUUID
                report["outbound_body"] = outbound
                report["inbound_wait_ms"] = inboundAt.map { Int($0.timeIntervalSince(sentAt) * 1000) } ?? -1
                report["used_background_poll_only"] = usedBackgroundPollOnly
                report["local_message_count"] = localCount
                report["duplicate_client_ids"] = duplicateClientIDs
                write(report)
            }
        } catch {
            report["pass"] = false
            report["error"] = error.localizedDescription
            write(report)
        }
    }

    private static func findPeer(session: AppSession, email: String) async throws -> PublicUser {
        await session.searchPeople(email)
        if let match = session.people.first(where: { $0.email.lowercased() == email.lowercased() }) {
            return match
        }
        throw LiveError("peer not found: \(email)")
    }

    private static func value(in args: [String], key: String) -> String? {
        guard let index = args.firstIndex(of: key), args.indices.contains(index + 1) else { return nil }
        return args[index + 1]
    }

    private static func write(_ report: [String: Any]) {
        guard let data = try? JSONSerialization.data(withJSONObject: report, options: [.prettyPrinted, .sortedKeys]) else { return }
        let urls = FileManager.default.urls(for: .documentDirectory, in: .userDomainMask)
        if let url = urls.first {
            try? data.write(to: url.appendingPathComponent("ipca-live-report.json"), options: .atomic)
        }
    }
}

private struct LiveError: LocalizedError {
    let errorDescription: String?
    init(_ message: String) { self.errorDescription = message }
}
