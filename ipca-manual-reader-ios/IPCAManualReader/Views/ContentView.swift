import SwiftUI

struct ContentView: View {
    @ObservedObject private var session = ManualReaderSessionStore.shared

    var body: some View {
        Group {
            if session.isLoggedIn {
                LibraryView()
            } else {
                LoginView()
            }
        }
        .task {
            // Delay so the UIKit login form mounts before any network callback.
            try? await Task.sleep(for: .milliseconds(300))
            await session.restoreSessionIfNeeded()
        }
    }
}
