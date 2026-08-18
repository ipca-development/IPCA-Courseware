import PhotosUI
import SwiftUI
import UIKit

struct MeView: View {
    @EnvironmentObject private var session: AppSession
    @State private var showingPhotoOptions = false
    @State private var pickingPhotos = false
    @State private var capturingCamera = false
    @State private var photoItem: PhotosPickerItem?
    @State private var photoError: String?

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
                            Button {
                                showingPhotoOptions = true
                            } label: {
                                ZStack(alignment: .bottomTrailing) {
                                    IPCAAvatar(
                                        name: user.name,
                                        photoPath: user.photoPath,
                                        serverURL: session.serverURLString,
                                        size: 84
                                    )
                                    Image(systemName: "camera.fill")
                                        .font(.caption.weight(.bold))
                                        .foregroundStyle(.white)
                                        .padding(6)
                                        .background(IPCATheme.interactiveGradient, in: Circle())
                                }
                            }
                            .buttonStyle(.plain)
                            .accessibilityLabel("Update profile photo")
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

                    if let photoError {
                        Text(photoError)
                            .font(.footnote)
                            .foregroundStyle(IPCATheme.Colors.destructive)
                            .padding(.horizontal, IPCATheme.Spacing.screen)
                    }

                    settingsGroup("PROFILE") {
                        NavigationLink {
                            ProfileEditView()
                        } label: {
                            IPCASettingsRow(icon: "person.fill", title: "Edit Profile", subtitle: "Personal details", showsChevron: true)
                        }
                        .buttonStyle(.plain)
                        divider
                        NavigationLink {
                            EmergencyContactsView()
                        } label: {
                            IPCASettingsRow(icon: "cross.case.fill", title: "Emergency Contacts", subtitle: "Who to call", showsChevron: true)
                        }
                        .buttonStyle(.plain)
                        divider
                        NavigationLink {
                            PasswordChangeView()
                        } label: {
                            IPCASettingsRow(icon: "lock.fill", title: "Password", subtitle: "Change your password", showsChevron: true)
                        }
                        .buttonStyle(.plain)
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
            .confirmationDialog("Update profile photo", isPresented: $showingPhotoOptions, titleVisibility: .visible) {
                Button("Camera") { capturingCamera = true }
                Button("Photo Library") { pickingPhotos = true }
                Button("Cancel", role: .cancel) {}
            }
            .photosPicker(isPresented: $pickingPhotos, selection: $photoItem, matching: .images)
            .onChange(of: photoItem) { _, item in
                guard let item else { return }
                Task { await importPhoto(item) }
            }
            .fullScreenCover(isPresented: $capturingCamera) {
                CameraCaptureView(
                    onCapture: { data, mime in
                        capturingCamera = false
                        Task { await uploadPhoto(data, mimeType: mime) }
                    },
                    onCancel: { capturingCamera = false }
                )
                .ignoresSafeArea()
            }
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

    private func importPhoto(_ item: PhotosPickerItem) async {
        photoItem = nil
        do {
            guard let data = try await item.loadTransferable(type: Data.self) else { return }
            await uploadPhoto(data, mimeType: "image/jpeg")
        } catch {
            photoError = "Couldn't read that photo."
        }
    }

    private func uploadPhoto(_ data: Data, mimeType: String) async {
        photoError = nil
        do {
            try await session.uploadProfilePhoto(data: data, mimeType: mimeType)
        } catch let error as APIClientError {
            photoError = error.errorDescription
        } catch {
            photoError = "Couldn't update your photo."
        }
    }
}
