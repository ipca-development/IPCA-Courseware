import CoreData
import SwiftUI

struct MainShellView: View {
    @EnvironmentObject private var session: AppSession
    @FetchRequest(
        sortDescriptors: []
    )
    private var conversations: FetchedResults<ConversationEntity>

    var body: some View {
        VStack(spacing: 0) {
            Group {
                switch session.selectedTab {
                case .messages:
                    MessagesRootView()
                case .community:
                    if session.capabilities.communityEnabled {
                        CommunityView()
                    } else {
                        MessagesRootView()
                    }
                case .training:
                    if session.capabilities.trainingEnabled {
                        TrainingView()
                    } else {
                        MessagesRootView()
                    }
                case .trainingVideos:
                    if session.capabilities.trainingVideosEnabled {
                        TrainingVideosView()
                    } else {
                        MessagesRootView()
                    }
                case .me:
                    MeView()
                }
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            if !session.hidesTabBar {
                IPCATabBar(
                    selection: $session.selectedTab,
                    communityEnabled: session.capabilities.communityEnabled,
                    trainingEnabled: session.capabilities.trainingEnabled,
                    trainingVideosEnabled: session.capabilities.trainingVideosEnabled,
                    messagesBadge: messagesBadge
                )
                .ignoresSafeArea(.keyboard)
            }
        }
        .tint(IPCATheme.Colors.ipcaBlue)
        .sheet(isPresented: $session.showingPushPrimer) {
            PushPrimerView()
                .environmentObject(session)
        }
        .onChange(of: session.capabilities.communityEnabled) { _, enabled in
            if !enabled, session.selectedTab == .community {
                session.selectedTab = .messages
            }
        }
        .onChange(of: session.capabilities.trainingVideosEnabled) { _, enabled in
            if !enabled, session.selectedTab == .trainingVideos {
                session.selectedTab = .messages
            }
        }
    }

    private var messagesBadge: Int {
        conversations.reduce(0) { $0 + Int($1.unreadCount) }
    }
}

struct PlaceholderView: View {
    let title: String
    let systemImage: String

    var body: some View {
        NavigationStack {
            ContentUnavailableView(title, systemImage: systemImage)
                .navigationTitle(title)
        }
    }
}

struct PushPrimerView: View {
    @EnvironmentObject private var session: AppSession

    var body: some View {
        NavigationStack {
            ZStack {
                IPCABackground()
                VStack(alignment: .leading, spacing: IPCATheme.Spacing.md) {
                    IPCAIconTile(systemImage: "bell.badge.fill", size: 52)
                    Text("Stay reachable")
                        .font(.largeTitle.weight(.bold))
                        .foregroundStyle(IPCATheme.Colors.textPrimary)
                    Text("IPCA notifies you when an instructor or staff member messages you, including messages that need a response.")
                        .foregroundStyle(IPCATheme.Colors.textSecondary)
                    Spacer()
                    Button {
                        Task { await session.enablePushFromPrimer() }
                    } label: {
                        Text("Turn On Notifications")
                            .font(.headline)
                            .frame(maxWidth: .infinity)
                            .padding(.vertical, 14)
                            .foregroundStyle(.white)
                            .background(IPCATheme.interactiveGradient, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous))
                    }
                    .buttonStyle(.plain)
                    Button("Not Now") {
                        Task { await session.skipPushPrimer() }
                    }
                    .frame(maxWidth: .infinity)
                    .foregroundStyle(IPCATheme.Colors.textSecondary)
                }
                .padding(IPCATheme.Spacing.xl)
            }
        }
        .presentationDetents([.medium])
        .interactiveDismissDisabled()
    }
}
