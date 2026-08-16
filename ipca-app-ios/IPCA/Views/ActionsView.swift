import SwiftUI

struct ActionsView: View {
    @EnvironmentObject private var session: AppSession
    @State private var items: [ActionItemDTO] = []
    @State private var loading = true

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            VStack(alignment: .leading, spacing: IPCATheme.Spacing.xs) {
                Text("Needs Attention")
                    .font(.largeTitle.bold())
                    .foregroundStyle(IPCATheme.Colors.textPrimary)
                Text("Official items that require your response or action.")
                    .font(.subheadline)
                    .foregroundStyle(IPCATheme.Colors.textSecondary)
            }
            .padding(.horizontal, IPCATheme.Spacing.screen)
            .padding(.top, IPCATheme.Spacing.xs)
            .padding(.bottom, IPCATheme.Spacing.md)

            Group {
                if loading && items.isEmpty {
                    ProgressView()
                        .tint(IPCATheme.Colors.ipcaBlue)
                        .frame(maxWidth: .infinity, maxHeight: .infinity)
                } else if items.isEmpty {
                    ContentUnavailableView(
                        "You're all caught up",
                        systemImage: "checkmark.circle",
                        description: Text("Official messages that need a response will show up here.")
                    )
                    .foregroundStyle(IPCATheme.Colors.textSecondary)
                } else {
                    ScrollView {
                        LazyVStack(spacing: IPCATheme.Spacing.sm) {
                            ForEach(items) { item in
                                actionCard(item)
                            }
                        }
                        .padding(.horizontal, IPCATheme.Spacing.screen)
                        .padding(.bottom, IPCATheme.Spacing.xl)
                    }
                    .refreshable { await reload() }
                }
            }
        }
        .background(IPCABackground())
        .navigationBarTitleDisplayMode(.inline)
        .toolbarBackground(IPCATheme.Colors.navyBase, for: .navigationBar)
        .toolbarColorScheme(.dark, for: .navigationBar)
        .task { await reload() }
        .refreshable { await reload() }
    }

    private func actionCard(_ item: ActionItemDTO) -> some View {
        let acknowledgement = item.kind.lowercased().contains("ack")
        return VStack(alignment: .leading, spacing: IPCATheme.Spacing.sm) {
            HStack(alignment: .top, spacing: IPCATheme.Spacing.sm) {
                IPCAIconTile(
                    systemImage: acknowledgement ? "megaphone.fill" : "doc.text.fill",
                    foreground: acknowledgement ? IPCATheme.Colors.ipcaBlue : IPCATheme.Colors.warning
                )
                VStack(alignment: .leading, spacing: 4) {
                    IPCAStatusBadge(
                        text: acknowledgement ? "Acknowledgement" : "Action Required",
                        tone: acknowledgement ? .info : .attention
                    )
                    Text(item.title.isEmpty ? "IPCA" : item.title)
                        .font(.body.weight(.semibold))
                        .foregroundStyle(IPCATheme.Colors.textPrimary)
                    Text(item.body)
                        .font(.subheadline)
                        .foregroundStyle(IPCATheme.Colors.textSecondary)
                }
                Spacer(minLength: 0)
            }
            HStack {
                Button("Acknowledge") {
                    Task {
                        await session.acknowledge(messageUUID: item.messageUUID)
                        await reload()
                    }
                }
                .font(.subheadline.weight(.semibold))
                .foregroundStyle(.white)
                .padding(.horizontal, 14)
                .padding(.vertical, 8)
                .background(IPCATheme.interactiveGradient, in: Capsule())
                Button("Open") {
                    session.openConversationFromNotification(item.conversationUUID)
                }
                .font(.subheadline.weight(.semibold))
                .foregroundStyle(IPCATheme.Colors.ipcaBlue)
                .padding(.horizontal, 14)
                .padding(.vertical, 8)
                .background(IPCATheme.Colors.navyElevated, in: Capsule())
                Spacer()
            }
        }
        .padding(IPCATheme.Spacing.md)
        .background(IPCATheme.Colors.navySurface, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.card, style: .continuous))
        .overlay(alignment: .leading) {
            RoundedRectangle(cornerRadius: 2)
                .fill(acknowledgement ? IPCATheme.Colors.ipcaBlue : IPCATheme.Colors.warning)
                .frame(width: 3)
                .padding(.vertical, 12)
        }
        .overlay(
            RoundedRectangle(cornerRadius: IPCATheme.Radius.card, style: .continuous)
                .stroke(IPCATheme.Colors.separator, lineWidth: 1)
        )
    }

    private func reload() async {
        items = await session.loadActions()
        loading = false
    }
}
