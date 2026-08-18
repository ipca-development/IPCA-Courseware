import SwiftUI
import UniformTypeIdentifiers

/*
 Remote session code screen (Progress Test Code / Mock Oral Code)

 PHP follow-up, when app delivery is turned on:
 1. Implement rsa_deliver_verification_code_via_app() to persist a one-time 6-digit
    code keyed by code_id for the signed-in Courseware user. Do not put the digits
    in APNs userInfo or lock-screen / banner text.
 2. Send APNs with:
      type = remote_session_code
      kind = progress_test | mock_oral
      code_id = <uuid>
      title = "Progress Test Code" | "Mock Oral Code"
    Alert copy should be: "Your Progress Test Code is ready. Open IPCA to view it."
    (or the Mock Oral equivalent). Never include the 6-digit code in the payload.
 3. Add GET/POST /api/communication/remote_session_code.php (app session auth):
      GET  ?code_id=  → { ok, kind, title, subtitle, code, expires_at, viewed }
      POST { code_id, viewed: 1 } after the student taps "I've written it down"
    If viewed, expired, or used, omit the plaintext code so the app can show:
    "This code is no longer available. Request a new progress test on the course page."
 4. Do not flip rsa_app_code_delivery_available() / CW_REMOTE_CODE_DELIVERY_CHANNEL
    until that endpoint and APNs payload are live.
 5. Optional Training Needs Attention item: source = remote_session_code, code_id set.

 The website still owns photo + password auth and code entry. This screen only reveals
 the one-time code in-session. Digits are never written to Core Data, logs, or UserDefaults.
*/

struct RemoteSessionCodeView: View {
    @EnvironmentObject private var session: AppSession
    @Environment(\.dismiss) private var dismiss
    @Environment(\.scenePhase) private var scenePhase
    @State private var envelope: RemoteSessionCodeEnvelope?
    @State private var loadFailed = false
    @State private var copied = false
    @State private var isSaving = false

    var body: some View {
        NavigationStack {
            ZStack {
                IPCABackground()
                if envelope == nil && !loadFailed {
                    ProgressView()
                        .tint(.white)
                } else if let envelope, canReveal(envelope) {
                    codeContent(envelope)
                } else {
                    unavailableContent
                }
            }
            .navigationBarTitleDisplayMode(.inline)
            .toolbarBackground(IPCATheme.Colors.navyPrimary, for: .navigationBar)
            .toolbarColorScheme(.dark, for: .navigationBar)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Close") { dismiss() }
                        .foregroundStyle(IPCATheme.Colors.ipcaBlue)
                }
            }
            .task { await load() }
        }
        .preferredColorScheme(.dark)
        .presentationDetents([.large])
        .interactiveDismissDisabled(false)
    }

    @ViewBuilder
    private func codeContent(_ envelope: RemoteSessionCodeEnvelope) -> some View {
        VStack(spacing: IPCATheme.Spacing.lg) {
            Spacer(minLength: 12)
            Text(displayTitle(envelope))
                .font(.title2.weight(.bold))
                .foregroundStyle(IPCATheme.Colors.textPrimary)
                .multilineTextAlignment(.center)
            if !envelope.subtitle.isEmpty {
                Text(envelope.subtitle)
                    .font(.headline)
                    .foregroundStyle(IPCATheme.Colors.textSecondary)
                    .multilineTextAlignment(.center)
            }
            Text(visibleDigits(envelope.code))
                .font(.system(size: 44, weight: .bold, design: .rounded))
                .tracking(10)
                .monospacedDigit()
                .foregroundStyle(IPCATheme.Colors.textPrimary)
                .padding(.vertical, IPCATheme.Spacing.md)
                .frame(maxWidth: .infinity)
                .background(IPCATheme.Colors.navySurface, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.card, style: .continuous))
                .overlay(
                    RoundedRectangle(cornerRadius: IPCATheme.Radius.card, style: .continuous)
                        .stroke(IPCATheme.Colors.separator, lineWidth: 1)
                )
                .accessibilityLabel(scenePhase == .active ? "Code \(envelope.code)" : "Code hidden")
            Button {
                copyCode(envelope.code)
            } label: {
                Label(copied ? "Copied" : "Copy", systemImage: copied ? "checkmark" : "doc.on.doc")
                    .font(.body.weight(.semibold))
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 12)
            }
            .foregroundStyle(IPCATheme.Colors.ipcaBlue)
            .background(IPCATheme.Colors.navyElevated, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous))
            .disabled(scenePhase != .active)
            Text("Write this code down now. It will not stay visible forever. Enter it on the website to start.")
                .font(.subheadline)
                .foregroundStyle(IPCATheme.Colors.warning)
                .multilineTextAlignment(.center)
            Spacer()
            Button {
                Task { await confirmWrittenDown() }
            } label: {
                Group {
                    if isSaving {
                        ProgressView().tint(.white)
                    } else {
                        Text("I've written it down")
                            .font(.headline)
                    }
                }
                .frame(maxWidth: .infinity)
                .padding(.vertical, 14)
            }
            .foregroundStyle(.white)
            .background(IPCATheme.interactiveGradient, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous))
            .disabled(isSaving)
        }
        .padding(IPCATheme.Spacing.xl)
    }

    private var unavailableContent: some View {
        VStack(spacing: IPCATheme.Spacing.md) {
            Image(systemName: "lock.slash")
                .font(.system(size: 36, weight: .semibold))
                .foregroundStyle(IPCATheme.Colors.warning)
            Text(unavailableMessage)
                .font(.body)
                .foregroundStyle(IPCATheme.Colors.textSecondary)
                .multilineTextAlignment(.center)
            Button("Close") { dismiss() }
                .font(.headline)
                .foregroundStyle(.white)
                .frame(maxWidth: .infinity)
                .padding(.vertical, 14)
                .background(IPCATheme.interactiveGradient, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous))
        }
        .padding(IPCATheme.Spacing.xl)
    }

    private var unavailableMessage: String {
        if envelope?.kind == "mock_oral" {
            return "This code is no longer available. Request a new mock oral on the course page."
        }
        return "This code is no longer available. Request a new progress test on the course page."
    }

    private func displayTitle(_ envelope: RemoteSessionCodeEnvelope) -> String {
        if !envelope.title.isEmpty {
            return envelope.title
        }
        return envelope.kind == "mock_oral" ? "Mock Oral Code" : "Progress Test Code"
    }

    private func visibleDigits(_ code: String) -> String {
        scenePhase == .active ? code : String(repeating: "•", count: max(code.count, 6))
    }

    private func canReveal(_ envelope: RemoteSessionCodeEnvelope) -> Bool {
        guard envelope.ok, !envelope.viewed else { return false }
        let digits = envelope.code.filter(\.isNumber)
        guard digits.count == 6 else { return false }
        if let expiry = Self.parseExpiry(envelope.expiresAt), expiry < Date() {
            return false
        }
        return true
    }

    private func copyCode(_ code: String) {
        guard scenePhase == .active else { return }
        UIPasteboard.general.setItems(
            [[UTType.utf8PlainText.identifier: code]],
            options: [
                .expirationDate: Date().addingTimeInterval(90),
                .localOnly: true
            ]
        )
        copied = true
    }

    private func load() async {
        let codeID = session.pendingRemoteSessionCodeID
        guard !codeID.isEmpty else {
            loadFailed = true
            return
        }
        do {
            envelope = try await session.loadRemoteSessionCode(codeID: codeID)
            loadFailed = false
        } catch {
            envelope = nil
            loadFailed = true
        }
    }

    private func confirmWrittenDown() async {
        isSaving = true
        let codeID = session.pendingRemoteSessionCodeID
        if !codeID.isEmpty {
            try? await session.markRemoteSessionCodeViewed(codeID: codeID)
        }
        envelope = nil
        session.closeRemoteSessionCode()
        isSaving = false
        dismiss()
    }

    private static func parseExpiry(_ value: String?) -> Date? {
        guard let value, !value.isEmpty else { return nil }
        let iso = ISO8601DateFormatter()
        iso.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        if let date = iso.date(from: value) {
            return date
        }
        iso.formatOptions = [.withInternetDateTime]
        return iso.date(from: value)
    }
}
