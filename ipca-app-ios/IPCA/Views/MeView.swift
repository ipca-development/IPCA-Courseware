import SwiftUI

struct MeView: View {
    @EnvironmentObject private var session: AppSession

    var body: some View {
        NavigationStack {
            List {
                if let user = session.user {
                    Section {
                        VStack(alignment: .leading, spacing: 4) {
                            Text(user.name)
                                .font(.title2.weight(.semibold))
                            Text(user.email)
                                .foregroundStyle(.secondary)
                            Text(user.role.replacingOccurrences(of: "_", with: " ").capitalized)
                                .font(.subheadline)
                                .foregroundStyle(.secondary)
                        }
                        .padding(.vertical, 8)
                    }
                }
                Section {
                    LabeledContent("Device", value: DeviceIdentity.platform == "ipad" ? "iPad" : "iPhone")
                    LabeledContent("App", value: DeviceIdentity.appVersion)
                }
                Section {
                    Button("Sign Out", role: .destructive) {
                        Task { await session.logout() }
                    }
                }
            }
            .navigationTitle("Me")
        }
    }
}
