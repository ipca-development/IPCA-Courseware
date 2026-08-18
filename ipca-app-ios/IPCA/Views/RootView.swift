import SwiftUI

struct RootView: View {
    @EnvironmentObject private var session: AppSession

    var body: some View {
        Group {
            if session.isAuthenticated {
                MainShellView()
            } else {
                LoginView()
            }
        }
        .preferredColorScheme(.dark)
        .animation(.easeInOut(duration: 0.2), value: session.isAuthenticated)
        .sheet(isPresented: $session.showingForgotPassword) {
            ForgotPasswordView()
                .environmentObject(session)
        }
        .sheet(isPresented: $session.showingPasswordReset) {
            PasswordResetView()
                .environmentObject(session)
        }
        .sheet(isPresented: $session.showingRemoteSessionCode) {
            RemoteSessionCodeView()
                .environmentObject(session)
        }
    }
}
