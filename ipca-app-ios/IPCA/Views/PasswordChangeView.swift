import SwiftUI

struct PasswordChangeView: View {
    @EnvironmentObject private var session: AppSession
    @Environment(\.dismiss) private var dismiss
    @State private var current = ""
    @State private var newPassword = ""
    @State private var confirm = ""
    @State private var errorMessage: String?
    @State private var isSaving = false

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: IPCATheme.Spacing.md) {
                Text("Use at least 8 characters. Your new password must be different from your current password.")
                    .font(.footnote)
                    .foregroundStyle(IPCATheme.Colors.textSecondary)
                passwordField("Current Password", text: $current)
                passwordField("New Password", text: $newPassword)
                passwordField("Confirm New Password", text: $confirm)
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
                            Text("Save Password")
                                .font(.headline)
                        }
                    }
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 14)
                }
                .foregroundStyle(.white)
                .background(
                    canSave ? AnyShapeStyle(IPCATheme.interactiveGradient) : AnyShapeStyle(IPCATheme.Colors.navyElevated),
                    in: RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous)
                )
                .disabled(!canSave || isSaving)
            }
            .padding(.horizontal, IPCATheme.Spacing.screen)
            .padding(.top, IPCATheme.Spacing.md)
        }
        .background(IPCABackground())
        .navigationTitle("Password")
        .navigationBarTitleDisplayMode(.inline)
        .toolbarBackground(IPCATheme.Colors.navyPrimary, for: .navigationBar)
        .toolbarColorScheme(.dark, for: .navigationBar)
    }

    private var canSave: Bool {
        !current.isEmpty && newPassword.count >= 8 && newPassword == confirm
    }

    private func passwordField(_ title: String, text: Binding<String>) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(title)
                .font(.footnote.weight(.semibold))
                .foregroundStyle(IPCATheme.Colors.textSecondary)
            SecureField(title, text: text)
                .textContentType(.password)
                .padding(IPCATheme.Spacing.sm)
                .foregroundStyle(IPCATheme.Colors.textPrimary)
                .background(IPCATheme.Colors.navySurface, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous))
                .overlay(
                    RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous)
                        .stroke(IPCATheme.Colors.separator, lineWidth: 1)
                )
        }
    }

    private func save() async {
        isSaving = true
        errorMessage = nil
        do {
            try await session.changeAccountPassword(current: current, new: newPassword, confirm: confirm)
            dismiss()
        } catch let error as APIClientError {
            errorMessage = error.errorDescription
        } catch {
            errorMessage = "Couldn't change your password."
        }
        isSaving = false
    }
}
