import Combine
import Foundation

struct CVRUnitWarning: Identifiable, Equatable {
    let id = UUID()
    var message: String
    var createdAt: Date
}

@MainActor
final class RemoteIPadLinkManager: ObservableObject {
    @Published private(set) var instructorStudentConnectionText = "Not connected"
    @Published private(set) var connectedPeerCount = 0
    @Published private(set) var latestWarning: CVRUnitWarning?
    @Published private(set) var resetCommandRequestedAt: Date?

    func publishAudioSourceWarning(_ message: String) {
        latestWarning = CVRUnitWarning(message: message, createdAt: Date())
        // Future transport hook: forward this warning to instructor/student iPads.
    }

    func clearAudioSourceWarning() {
        latestWarning = nil
        // Future transport hook: clear this warning on instructor/student iPads.
    }

    func publishStatus(_ message: String) {
        _ = message
        // Future transport hook for live iPad status.
    }

    func receiveResetAudioPathCommand(source: String = "local admin") {
        resetCommandRequestedAt = Date()
        latestWarning = CVRUnitWarning(
            message: "Audio route reset requested by \(source).",
            createdAt: Date()
        )
    }
}
