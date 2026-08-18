import SwiftUI

struct LoginView: View {
    @EnvironmentObject private var session: AppSession
    @State private var email = ""
    @State private var password = ""
    @State private var serverURL = ""
    @FocusState private var focused: Field?

    private enum Field {
        case email, password, server
    }

    var body: some View {
        NavigationStack {
            ZStack {
                IPCABackground()
                VStack(spacing: IPCATheme.Spacing.xl) {
                    Spacer()
                    VStack(spacing: IPCATheme.Spacing.sm) {
                        IPCALogo(height: 72, lockup: true)
                        Text("Sign in with your IPCA.training account")
                            .font(.subheadline)
                            .foregroundStyle(IPCATheme.Colors.textSecondary)
                            .multilineTextAlignment(.center)
                    }
                    VStack(spacing: IPCATheme.Spacing.sm) {
                        TextField("Email", text: $email)
                            .textContentType(.username)
                            .keyboardType(.emailAddress)
                            .textInputAutocapitalization(.never)
                            .autocorrectionDisabled()
                            .focused($focused, equals: .email)
                            .padding(IPCATheme.Spacing.sm)
                            .foregroundStyle(IPCATheme.Colors.textPrimary)
                            .background(IPCATheme.Colors.navySurface, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous))
                            .overlay(
                                RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous)
                                    .stroke(IPCATheme.Colors.separator, lineWidth: 1)
                            )
                        SecureField("Password", text: $password)
                            .textContentType(.password)
                            .focused($focused, equals: .password)
                            .padding(IPCATheme.Spacing.sm)
                            .foregroundStyle(IPCATheme.Colors.textPrimary)
                            .background(IPCATheme.Colors.navySurface, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous))
                            .overlay(
                                RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous)
                                    .stroke(IPCATheme.Colors.separator, lineWidth: 1)
                            )
                        Button(action: signIn) {
                            if session.isLoggingIn {
                                ProgressView()
                                    .tint(.white)
                                    .frame(maxWidth: .infinity)
                                    .padding(.vertical, 14)
                            } else {
                                Text("Sign In")
                                    .font(.headline)
                                    .frame(maxWidth: .infinity)
                                    .padding(.vertical, 14)
                            }
                        }
                        .foregroundStyle(.white)
                        .background(
                            (session.isLoggingIn || email.isEmpty || password.isEmpty)
                            ? AnyShapeStyle(IPCATheme.Colors.navyElevated)
                            : AnyShapeStyle(IPCATheme.interactiveGradient),
                            in: RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous)
                        )
                        .disabled(session.isLoggingIn || email.isEmpty || password.isEmpty)
                        Button("Forgot password?") {
                            session.showingForgotPassword = true
                        }
                        .font(.footnote.weight(.semibold))
                        .foregroundStyle(IPCATheme.Colors.ipcaBlue)
                        .frame(maxWidth: .infinity)
                    }
                    if let error = session.loginError {
                        Text(error)
                            .font(.footnote)
                            .foregroundStyle(IPCATheme.Colors.destructive)
                            .multilineTextAlignment(.center)
                    }
                    Spacer()
#if DEBUG
                    TextField("Server", text: $serverURL)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()
                        .keyboardType(.URL)
                        .focused($focused, equals: .server)
                        .font(.footnote)
                        .padding(10)
                        .foregroundStyle(IPCATheme.Colors.textSecondary)
                        .background(IPCATheme.Colors.navySurface, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous))
#endif
                }
                .padding(IPCATheme.Spacing.xl)
            }
            .onAppear {
                serverURL = session.serverURLString
            }
        }
    }

    private func signIn() {
        Task {
            await session.login(email: email, password: password, serverURL: serverURL.isEmpty ? session.serverURLString : serverURL)
        }
    }
}
