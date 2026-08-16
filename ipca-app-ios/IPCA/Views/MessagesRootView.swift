import SwiftUI

struct MessagesCompose: Hashable {}
struct MessagesActions: Hashable {}

struct MessagesRootView: View {
    @Environment(\.horizontalSizeClass) private var sizeClass
    @EnvironmentObject private var session: AppSession
    @State private var path = NavigationPath()

    var body: some View {
        Group {
            if sizeClass == .regular {
                HStack(spacing: 0) {
                    NavigationStack {
                        ConversationListView()
                    }
                    .frame(width: 340)
                    .background(IPCATheme.Colors.navyBase)
                    Divider()
                        .background(IPCATheme.Colors.separator)
                    NavigationStack {
                        regularDetail
                    }
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
                    .id(regularDetailID)
                }
                .frame(maxWidth: .infinity, maxHeight: .infinity)
            } else {
                NavigationStack(path: $path) {
                    ConversationListView(compactPath: $path)
                        .navigationDestination(for: MessagesCompose.self) { _ in
                            NewMessageView(compactPath: $path)
                                .ipcaHidesTabBar()
                        }
                        .navigationDestination(for: MessagesActions.self) { _ in
                            ActionsView()
                                .ipcaHidesTabBar()
                        }
                        .navigationDestination(for: String.self) { uuid in
                            ConversationView(conversationUUID: uuid)
                                .id(uuid)
                                .ipcaHidesTabBar()
                        }
                }
            }
        }
        .onChange(of: session.pendingConversationUUID) { _, uuid in
            guard let uuid else { return }
            session.showingActions = false
            session.selectedConversationUUID = uuid
            if sizeClass != .regular {
                path = NavigationPath()
                path.append(uuid)
            }
            session.pendingConversationUUID = nil
        }
        .onChange(of: session.pendingActions) { _, pending in
            guard pending else { return }
            if sizeClass == .regular {
                session.showingActions = true
                session.selectedConversationUUID = nil
                session.pendingActions = false
                return
            }
            path = NavigationPath()
            path.append(MessagesActions())
            session.pendingActions = false
        }
    }

    @ViewBuilder
    private var regularDetail: some View {
        if session.showingActions {
            ActionsView()
        } else if let uuid = session.selectedConversationUUID {
            ConversationView(conversationUUID: uuid)
        } else {
            ContentUnavailableView("Messages", systemImage: "bubble.left.and.bubble.right", description: Text("Select a conversation"))
        }
    }

    private var regularDetailID: String {
        if session.showingActions {
            return "actions"
        }
        return session.selectedConversationUUID ?? "empty"
    }
}
