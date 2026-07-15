import SwiftUI

@main
struct IPCASyncAgentApp: App {
    @StateObject private var state = AppStateController()

    var body: some Scene {
        Window("IPCA Sync Agent", id: "main") {
            ContentView()
                .environmentObject(state)
        }
        .defaultSize(width: 820, height: 760)

        MenuBarExtra {
            MenuBarContentView()
                .environmentObject(state)
        } label: {
            Image(systemName: menuIconName)
        }
        .menuBarExtraStyle(.window)

        Settings {
            ContentView()
                .environmentObject(state)
        }
    }

    private var menuIconName: String {
        switch state.status {
        case .connected, .idle:
            return "airplane.circle"
        case .syncing, .downloading, .uploading:
            return "arrow.triangle.2.circlepath.circle"
        case .error, .offline, .ipcaUnavailable, .garminAuthenticationRequired, .actionRequired:
            return "exclamationmark.triangle"
        default:
            return "airplane"
        }
    }
}
