import SwiftUI

struct MeView: View {
    @EnvironmentObject private var session: AppSession

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: IPCATheme.Spacing.lg) {
                    Text("Me")
                        .font(.largeTitle.bold())
                        .foregroundStyle(IPCATheme.Colors.textPrimary)
                        .padding(.horizontal, IPCATheme.Spacing.screen)
                        .padding(.top, IPCATheme.Spacing.xs)

                    if let user = session.user {
                        HStack(alignment: .center, spacing: IPCATheme.Spacing.md) {
                            IPCAAvatar(
                                name: user.name,
                                photoPath: user.photoPath,
                                serverURL: session.serverURLString,
                                size: 84
                            )
                            VStack(alignment: .leading, spacing: 6) {
                                Text(user.name)
                                    .font(.title3.weight(.bold))
                                    .foregroundStyle(IPCATheme.Colors.textPrimary)
                                if !user.role.isEmpty {
                                    HStack(spacing: 6) {
                                        Image(systemName: "shield.fill")
                                            .font(.caption2)
                                        Text(IPCATheme.formattedRole(user.role))
                                            .font(.caption.weight(.semibold))
                                    }
                                    .foregroundStyle(IPCATheme.Colors.ipcaBlue)
                                    .padding(.horizontal, 8)
                                    .padding(.vertical, 4)
                                    .background(IPCATheme.Colors.navyElevated, in: Capsule())
                                }
                                if !user.email.isEmpty {
                                    HStack(spacing: 6) {
                                        Image(systemName: "envelope")
                                        Text(user.email)
                                    }
                                    .font(.footnote)
                                    .foregroundStyle(IPCATheme.Colors.textSecondary)
                                }
                            }
                            Spacer(minLength: 0)
                        }
                        .padding(.horizontal, IPCATheme.Spacing.screen)
                    }

                    settingsGroup("ACCOUNT") {
                        Button {
                            Task { await session.enablePushFromPrimer() }
                        } label: {
                            IPCASettingsRow(
                                icon: "bell.fill",
                                title: "Notifications",
                                subtitle: "Manage your alerts",
                                value: session.notificationsAuthorized ? "On" : "Off",
                                valueColor: session.notificationsAuthorized ? IPCATheme.Colors.success : IPCATheme.Colors.warning
                            )
                        }
                        .buttonStyle(.plain)
                        .disabled(session.notificationsAuthorized)
                    }

                    settingsGroup("APP") {
                        IPCASettingsRow(
                            icon: "iphone",
                            title: "Device",
                            value: DeviceIdentity.platform == "ipad" ? "iPad" : "iPhone"
                        )
                        divider
                        IPCASettingsRow(
                            icon: "info.circle.fill",
                            title: "App Version",
                            value: DeviceIdentity.appVersion
                        )
                        divider
                        IPCASettingsRow(
                            icon: "wifi",
                            title: "Connection",
                            value: session.isOnline ? "Online" : "Offline",
                            valueColor: session.isOnline ? IPCATheme.Colors.success : IPCATheme.Colors.warning
                        )
                        if let lastSyncAt = session.lastSyncAt {
                            divider
                            IPCASettingsRow(
                                icon: "arrow.triangle.2.circlepath",
                                title: "Last Sync",
                                value: lastSyncAt.formatted(date: .abbreviated, time: .shortened),
                                valueColor: IPCATheme.Colors.textTertiary
                            )
                        }
                    }

                    Button {
                        Task { await session.logout() }
                    } label: {
                        HStack(spacing: IPCATheme.Spacing.sm) {
                            Image(systemName: "rectangle.portrait.and.arrow.right")
                            Text("Sign Out")
                                .font(.body.weight(.semibold))
                        }
                        .foregroundStyle(IPCATheme.Colors.destructive)
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 14)
                        .background(IPCATheme.Colors.navySurface, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.card, style: .continuous))
                        .overlay(
                            RoundedRectangle(cornerRadius: IPCATheme.Radius.card, style: .continuous)
                                .stroke(IPCATheme.Colors.destructive.opacity(0.25), lineWidth: 1)
                        )
                    }
                    .buttonStyle(.plain)
                    .padding(.horizontal, IPCATheme.Spacing.screen)
                    .padding(.bottom, IPCATheme.Spacing.xl)
                }
            }
            .background(IPCABackground())
            .toolbar(.hidden, for: .navigationBar)
            .task { await session.preparePush() }
        }
    }

    private var divider: some View {
        Rectangle()
            .fill(IPCATheme.Colors.separator)
            .frame(height: 1)
            .padding(.leading, 64)
    }

    private func settingsGroup<Content: View>(_ title: String, @ViewBuilder content: () -> Content) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            Text(title)
                .font(.caption.weight(.semibold))
                .tracking(0.8)
                .foregroundStyle(IPCATheme.Colors.textTertiary)
                .padding(.horizontal, IPCATheme.Spacing.screen)
            VStack(spacing: 0) {
                content()
            }
            .background(IPCATheme.Colors.navySurface, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.card, style: .continuous))
            .overlay(
                RoundedRectangle(cornerRadius: IPCATheme.Radius.card, style: .continuous)
                    .stroke(IPCATheme.Colors.separator, lineWidth: 1)
            )
            .padding(.horizontal, IPCATheme.Spacing.screen)
        }
    }
}
