import SwiftUI

struct ContentView: View {
    @ObservedObject private var session = ManualReaderSessionStore.shared

    var body: some View {
        Group {
            if !session.hasResolvedInitialSession {
                ZStack {
                    Color.white.ignoresSafeArea()
                    ProgressView("Restoring session…")
                        .tint(IPCAReaderTheme.navy)
                }
            } else if session.isLoggedIn {
                LibraryView()
            } else {
                LoginView()
            }
        }
        .task {
            await session.restoreSessionIfNeeded()
        }
    }
}
