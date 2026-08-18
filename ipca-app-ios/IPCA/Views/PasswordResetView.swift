import SwiftUI

struct ForgotPasswordView: View {
    @EnvironmentObject private var session: AppSession
    @Environment(\.dismiss) private var dismiss
    @State private var email = ""
    @State private var message: String?
    @State private var errorMessage: String?
    @State private var isSending = false

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: IPCATheme.Spacing.md) {
                    Text("Enter the email for your IPCA.training account. If an account exists, we will send a reset link.")
                        .font(.footnote)
                        .foregroundStyle(IPCATheme.Colors.textSecondary)
                    VStack(alignment: .leading, spacing: 6) {
                        Text("Email")
                            .font(.footnote.weight(.semibold))
                            .foregroundStyle(IPCATheme.Colors.textSecondary)
                        TextField("Email", text: $email)
                            .textContentType(.username)
                            .keyboardType(.emailAddress)
                            .textInputAutocapitalization(.never)
                            .autocorrectionDisabled()
                            .padding(IPCATheme.Spacing.sm)
                            .foregroundStyle(IPCATheme.Colors.textPrimary)
                            .background(IPCATheme.Colors.navySurface, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous))
                            .overlay(
                                RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous)
                                    .stroke(IPCATheme.Colors.separator, lineWidth: 1)
                            )
                    }
                    if let message {
                        Text(message)
                            .font(.footnote)
                            .foregroundStyle(IPCATheme.Colors.success)
                    }
                    if let errorMessage {
                        Text(errorMessage)
                            .font(.footnote)
                            .foregroundStyle(IPCATheme.Colors.destructive)
                    }
                    Button {
                        Task { await send() }
                    } label: {
                        Group {
                            if isSending {
                                ProgressView().tint(.white)
                            } else {
                                Text("Send Reset Link")
                                    .font(.headline)
                            }
                        }
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 14)
                    }
                    .foregroundStyle(.white)
                    .background(
                        email.isEmpty || isSending
                        ? AnyShapeStyle(IPCATheme.Colors.navyElevated)
                        : AnyShapeStyle(IPCATheme.interactiveGradient),
                        in: RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous)
                    )
                    .disabled(email.isEmpty || isSending)
                    Button("I already have a reset token") {
                        dismiss()
                        session.showingPasswordReset = true
                    }
                    .font(.footnote.weight(.semibold))
                    .foregroundStyle(IPCATheme.Colors.ipcaBlue)
                    .frame(maxWidth: .infinity)
                }
                .padding(IPCATheme.Spacing.xl)
            }
            .background(IPCABackground())
            .navigationTitle("Forgot password")
            .navigationBarTitleDisplayMode(.inline)
            .toolbarBackground(IPCATheme.Colors.navyPrimary, for: .navigationBar)
            .toolbarColorScheme(.dark, for: .navigationBar)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Close") { dismiss() }
                        .foregroundStyle(IPCATheme.Colors.ipcaBlue)
                }
            }
        }
        .preferredColorScheme(.dark)
    }

    private func send() async {
        isSending = true
        errorMessage = nil
        message = nil
        do {
            message = try await session.requestPasswordReset(email: email.trimmingCharacters(in: .whitespacesAndNewlines))
        } catch let error as APIClientError {
            errorMessage = error.errorDescription
        } catch {
            errorMessage = "Couldn't send a reset link."
        }
        isSending = false
    }
}

struct PasswordResetView: View {
    @EnvironmentObject private var session: AppSession
    @Environment(\.dismiss) private var dismiss
    @State private var token = ""
    @State private var password = ""
    @State private var confirm = ""
    @State private var statusMessage: String?
    @State private var errorMessage: String?
    @State private var accountName = ""
    @State private var isSaving = false
    @State private var didSucceed = false

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: IPCATheme.Spacing.md) {
                    Text("Set a new password with the reset token from your email or by opening the reset link in the app.")
                        .font(.footnote)
                        .foregroundStyle(IPCATheme.Colors.textSecondary)
                    if accountName.isEmpty == false {
                        Text("Resetting password for \(accountName).")
                            .font(.footnote.weight(.semibold))
                            .foregroundStyle(IPCATheme.Colors.textPrimary)
                    }
                    VStack(alignment: .leading, spacing: 6) {
                        Text("Reset token")
                            .font(.footnote.weight(.semibold))
                            .foregroundStyle(IPCATheme.Colors.textSecondary)
                        TextField("Paste token", text: $token)
                            .textInputAutocapitalization(.never)
                            .autocorrectionDisabled()
                            .padding(IPCATheme.Spacing.sm)
                            .foregroundStyle(IPCATheme.Colors.textPrimary)
                            .background(IPCATheme.Colors.navySurface, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous))
                            .overlay(
                                RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous)
                                    .stroke(IPCATheme.Colors.separator, lineWidth: 1)
                            )
                    }
                    passwordField("New Password", text: $password)
                    passwordField("Confirm New Password", text: $confirm)
                    Text("Use at least 8 characters.")
                        .font(.caption)
                        .foregroundStyle(IPCATheme.Colors.textTertiary)
                    if let statusMessage {
                        Text(statusMessage)
                            .font(.footnote)
                            .foregroundStyle(IPCATheme.Colors.success)
                    }
                    if let errorMessage {
                        Text(errorMessage)
                            .font(.footnote)
                            .foregroundStyle(IPCATheme.Colors.destructive)
                    }
                    Button {
                        Task { await save() }
                    } label: {
                        Group {
                            if isSaving {
                                ProgressView().tint(.white)
                            } else {
                                Text(didSucceed ? "Sign In" : "Save New Password")
                                    .font(.headline)
                            }
                        }
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 14)
                    }
                    .foregroundStyle(.white)
                    .background(IPCATheme.interactiveGradient, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous))
                    .disabled(isSaving || (!didSucceed && (token.isEmpty || password.count < 8)))
                }
                .padding(IPCATheme.Spacing.xl)
            }
            .background(IPCABackground())
            .navigationTitle("Reset password")
            .navigationBarTitleDisplayMode(.inline)
            .toolbarBackground(IPCATheme.Colors.navyPrimary, for: .navigationBar)
            .toolbarColorScheme(.dark, for: .navigationBar)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Close") { dismiss() }
                        .foregroundStyle(IPCATheme.Colors.ipcaBlue)
                }
            }
            .task {
                if token.isEmpty {
                    token = session.pendingResetToken
                }
                await validateIfNeeded()
            }
        }
        .preferredColorScheme(.dark)
    }

    private func passwordField(_ title: String, text: Binding<String>) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(title)
                .font(.footnote.weight(.semibold))
                .foregroundStyle(IPCATheme.Colors.textSecondary)
            SecureField(title, text: text)
                .textContentType(.newPassword)
                .padding(IPCATheme.Spacing.sm)
                .foregroundStyle(IPCATheme.Colors.textPrimary)
                .background(IPCATheme.Colors.navySurface, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous))
                .overlay(
                    RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous)
                        .stroke(IPCATheme.Colors.separator, lineWidth: 1)
                )
        }
    }

    private func validateIfNeeded() async {
        let trimmed = token.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else { return }
        do {
            let result = try await session.validateResetToken(trimmed)
            accountName = result.name ?? result.email ?? ""
            errorMessage = nil
        } catch let error as APIClientError {
            errorMessage = error.errorDescription ?? "This password reset link is invalid or has expired."
        } catch {
            errorMessage = "This password reset link is invalid or has expired."
        }
    }

    private func save() async {
        if didSucceed {
            dismiss()
            return
        }
        isSaving = true
        errorMessage = nil
        do {
            statusMessage = try await session.completePasswordReset(
                token: token.trimmingCharacters(in: .whitespacesAndNewlines),
                password: password,
                confirm: confirm
            )
            didSucceed = true
        } catch let error as APIClientError {
            errorMessage = error.errorDescription
        } catch {
            errorMessage = "Couldn't reset your password."
        }
        isSaving = false
    }
}
